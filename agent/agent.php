<?php
// [agent] AI support agent for Mirza. OFF by default: nothing changes until an admin
// enables it (storage/agent/config.json, written by the admin panel). Every failure path
// falls back to the normal support flow, so the agent can never lose a ticket.
// Secrets (API key, SSH key) live in storage/agent/ (web-denied, chmod 600), never in git or the DB.
if (defined('MIRZA_AGENT_LOADED'))
    return;
define('MIRZA_AGENT_LOADED', 1);
define('AGENT_DIR', dirname(__DIR__) . '/storage/agent');

function agent_dir($sub = '')
{
    $d = AGENT_DIR . ($sub !== '' ? '/' . $sub : '');
    if (!is_dir($d))
        @mkdir($d, 0700, true);
    if (!is_file(AGENT_DIR . '/.htaccess'))
        @file_put_contents(AGENT_DIR . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    return $d;
}

function agent_defaults()
{
    return [
        'enabled' => false,
        'model' => 'claude-haiku-5-5',
        'tech_id' => '',               // technical support chat id: unresolved disorder reports + «پشتیبانی فنی» (never shown to users)
        'admin_id' => '',              // admin chat id: confirmed outages + fallback when a support id is empty/unreachable
        'finance_id' => '',            // finance support chat id: «پشتیبانی مالی» (may equal tech_id; empty = first administrator)
        'dep_fin' => 0,                // departman row ids the agent created
        'dep_tech' => 0,
        'daily_usd' => 1.0,
        'monthly_usd' => 20.0,
        'price' => ['in' => 0.10, 'cache_w' => 0.125, 'cache_r' => 0.01, 'out' => 0.50], // USD per 1M tokens
        'probe' => ['host' => '', 'port' => 22, 'user' => 'root'],
        'idle_min' => 30,
        'max_user_msgs' => 8,
        'php_bin' => '',
    ];
}

function agent_cfg($reload = false)
{
    static $c = null;
    if ($c === null || $reload) {
        $f = AGENT_DIR . '/config.json';
        $j = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
        $c = array_replace_recursive(agent_defaults(), is_array($j) ? $j : []);
    }
    return $c;
}

function agent_key()
{
    $f = AGENT_DIR . '/anthropic.key';
    return is_file($f) ? trim((string) file_get_contents($f)) : '';
}

function agent_enabled()
{
    $c = agent_cfg();
    return !empty($c['enabled']) && agent_key() !== '' && !agent_over_budget();
}

function agent_log($msg)
{
    @file_put_contents(agent_dir() . '/agent.log', date('Y-m-d H:i:s ') . $msg . "\n", FILE_APPEND);
}

// ------------------------------------------------------------------ locked JSON files
function agent_json_update($path, callable $fn)
{
    $h = fopen($path, 'c+');
    if (!$h)
        return null;
    @chmod($path, 0600);
    flock($h, LOCK_EX);
    $raw = stream_get_contents($h);
    $data = $raw ? json_decode($raw, true) : null;
    $ret = $fn($data);
    if ($ret !== false) {
        ftruncate($h, 0);
        rewind($h);
        fwrite($h, json_encode($data, JSON_UNESCAPED_UNICODE));
        fflush($h);
    }
    flock($h, LOCK_UN);
    fclose($h);
    return $data;
}

// ------------------------------------------------------------------ budget
function agent_usage()
{
    $f = AGENT_DIR . '/usage.json';
    $u = is_file($f) ? json_decode((string) file_get_contents($f), true) : [];
    $u = is_array($u) ? $u : [];
    $day = date('Y-m-d');
    $mon = date('Y-m');
    return [
        'day_usd' => ($u['day'] ?? '') === $day ? (float) $u['day_usd'] : 0.0,
        'month_usd' => ($u['month'] ?? '') === $mon ? (float) $u['month_usd'] : 0.0,
        'calls' => (int) ($u['calls'] ?? 0),
    ];
}

function agent_over_budget()
{
    $c = agent_cfg();
    $u = agent_usage();
    return ($c['daily_usd'] > 0 && $u['day_usd'] >= $c['daily_usd'])
        || ($c['monthly_usd'] > 0 && $u['month_usd'] >= $c['monthly_usd']);
}

function agent_add_usage(array $usage)
{
    $p = agent_cfg()['price'];
    $cost = (($usage['input_tokens'] ?? 0) * $p['in'] + ($usage['cache_creation_input_tokens'] ?? 0) * $p['cache_w']
        + ($usage['cache_read_input_tokens'] ?? 0) * $p['cache_r'] + ($usage['output_tokens'] ?? 0) * $p['out']) / 1e6;
    agent_json_update(agent_dir() . '/usage.json', function (&$u) use ($cost) {
        $u = is_array($u) ? $u : [];
        $day = date('Y-m-d');
        $mon = date('Y-m');
        if (($u['day'] ?? '') !== $day) {
            $u['day'] = $day;
            $u['day_usd'] = 0;
        }
        if (($u['month'] ?? '') !== $mon) {
            $u['month'] = $mon;
            $u['month_usd'] = 0;
        }
        $u['day_usd'] += $cost;
        $u['month_usd'] += $cost;
        $u['calls'] = ($u['calls'] ?? 0) + 1;
    });
    return $cost;
}

// ------------------------------------------------------------------ conversations
function agent_conv_path($uid)
{
    return agent_dir('conv') . '/' . intval($uid) . '.json';
}

function agent_conv($uid)
{
    $f = agent_conv_path($uid);
    $c = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    return is_array($c) ? $c : null;
}

function agent_conv_live($c)
{
    return is_array($c) && empty($c['done']) && (time() - ($c['updated'] ?? 0)) < agent_cfg()['idle_min'] * 60;
}

// Services that count as "active" for technical support (expired / removed ones do not).
const AGENT_ACTIVE_STATUSES = ['active', 'sendedwarn', 'send_on_hold'];

function agent_active_services($uid)
{
    global $pdo;
    $in = implode(',', array_fill(0, count(AGENT_ACTIVE_STATUSES), '?'));
    $st = $pdo->prepare("SELECT COUNT(*) FROM invoice WHERE id_user = ? AND Status IN ($in)");
    $st->execute(array_merge([$uid], AGENT_ACTIVE_STATUSES));
    return (int) $st->fetchColumn();
}

const AGENT_NO_SERVICE_TEXT = "🙏 پشتیبانی فنی فقط برای کاربرانی است که حداقل یک سرویس فعال در همین حساب دارند و الان سرویس فعالی برایت ثبت نشده.\n\n"
    . "• اگر سرویست تمام شده، از «♻️ تمدید سرویس» تمدیدش کن یا از «🔐 خرید اشتراک» سرویس جدید بگیر.\n"
    . "• اگر سرویس را برای شخص دیگری گرفته‌ای، از خودش بخواه با حساب خودش در همین ربات پیام بدهد.\n"
    . "• سؤال مالی داری؟ از بخش «پشتیبانی مالی» پیام بده.";

// Start a conversation. $ctx: kind=ticket|disorder, invoice, dep_id, username, handoff_text, handoff_kb
// Returns false when the agent refused at once (technical department, no active service): the
// customer already got the reason and the caller should leave the step at home.
function agent_start($uid, $text, array $ctx)
{
    if (($ctx['kind'] ?? '') === 'ticket' && agent_dep_kind($ctx['dep_id'] ?? 0) === 'tech' && agent_active_services($uid) === 0) {
        agent_send($uid, AGENT_NO_SERVICE_TEXT);
        agent_log("refused tech ticket uid=$uid: no active service");
        return false;
    }
    $ctx['active_services'] = agent_active_services($uid);
    agent_json_update(agent_conv_path($uid), function (&$c) use ($text, $ctx) {
        $c = ['ctx' => $ctx, 'started' => time(), 'updated' => time(), 'messages' => [], 'inbox' => [$text],
            'user_msgs' => [$text], 'done' => false, 'probes' => [], 'outage_sent' => false];
    });
    agent_kick($uid);
    return true;
}

// A menu button (any label in the bot's language file, the admin button, or a /command) ends the
// agent chat: the step goes back to home and the bot handles the press as usual.
function agent_leave_on_button($uid, $text)
{
    global $textbotlang, $user;
    static $labels = null;
    $t = trim((string) $text);
    if ($labels === null) {
        $labels = [AGENT_ADMIN_BTN_LABEL => true];
        if (is_array($textbotlang))
            array_walk_recursive($textbotlang, function ($v) use (&$labels) {
                if (is_string($v) && mb_strlen($v) <= 64)
                    $labels[trim($v)] = true;
            });
    }
    if ($t === '' || ($t[0] !== '/' && !isset($labels[$t])))
        return false;
    step('home', $uid);
    $user['step'] = 'home';
    agent_json_update(agent_conv_path($uid), function (&$c) {
        if (!is_array($c))
            return false;
        $c['done'] = true;
    });
    return true;
}
const AGENT_ADMIN_BTN_LABEL = '🤖 پشتیبان هوشمند';

// ------------------------------------------------------------------ departments
const AGENT_DEPS = ['dep_fin' => ['پشتیبانی مالی', 'finance_id'], 'dep_tech' => ['پشتیبانی فنی', 'tech_id']];

function agent_fallback_admin()
{
    $id = trim((string) (agent_cfg()['admin_id'] ?? ''));
    if ($id !== '')
        return $id;
    $a = select('admin', '*', 'rule', 'administrator', 'select');
    return (string) ($a['id_admin'] ?? '');
}

// Make sure the two agent departments exist and point at the configured ids.
function agent_sync_departments()
{
    global $pdo;
    $c = agent_cfg(true);
    $fb = agent_fallback_admin();
    $ids = [];
    foreach (AGENT_DEPS as $k => [$name, $idKey]) {
        $sid = trim((string) ($c[$idKey] ?? '')) ?: $fb;
        if ($sid === '')
            continue;
        $row = !empty($c[$k]) ? select('departman', '*', 'id', (int) $c[$k], 'select') : null;
        if (!$row) {
            $st = $pdo->prepare('SELECT * FROM departman WHERE name_departman = ? LIMIT 1');
            $st->execute([$name]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if ($row) {
            if ((string) $row['idsupport'] !== $sid)
                $pdo->prepare('UPDATE departman SET idsupport = ? WHERE id = ?')->execute([$sid, $row['id']]);
            $ids[$k] = (int) $row['id'];
        } else {
            $pdo->prepare('INSERT INTO departman (idsupport, name_departman) VALUES (?, ?)')->execute([$sid, $name]);
            $ids[$k] = (int) $pdo->lastInsertId();
        }
    }
    if (function_exists('clearSelectCache'))
        clearSelectCache('departman');
    agent_json_update(AGENT_DIR . '/config.json', function (&$x) use ($ids) {
        $x = array_replace(is_array($x) ? $x : [], $ids);
    });
    agent_cfg(true);
    return $ids;
}

// Department picker while the agent is on: only finance + technical.
function agent_dep_keyboard()
{
    global $textbotlang;
    $c = agent_cfg();
    if (empty($c['dep_fin']) || empty($c['dep_tech']) || !select('departman', '*', 'id', (int) $c['dep_tech'], 'select'))
        $c = array_replace($c, agent_sync_departments());
    $rows = [];
    foreach (AGENT_DEPS as $k => [$name]) {
        if (!empty($c[$k]))
            $rows[] = [['text' => $name, 'callback_data' => 'departman_' . (int) $c[$k]]];
    }
    $rows[] = [['text' => $textbotlang['users']['backbtn'], 'callback_data' => 'backuser']];
    return json_encode(['inline_keyboard' => $rows]);
}

// 'finance' | 'tech' | '' for a departman id
function agent_dep_kind($depId)
{
    $c = agent_cfg();
    if ((int) $depId && (int) $depId === (int) $c['dep_fin'])
        return 'finance';
    if ((int) $depId && (int) $depId === (int) $c['dep_tech'])
        return 'tech';
    return '';
}

// Next customer message of a live conversation. false = no live conversation.
function agent_push($uid, $text)
{
    $ok = false;
    agent_json_update(agent_conv_path($uid), function (&$c) use ($text, &$ok) {
        if (!agent_conv_live($c))
            return false;
        $c['inbox'][] = $text;
        $c['user_msgs'][] = $text;
        $c['updated'] = time();
        $ok = true;
    });
    if ($ok)
        agent_kick($uid);
    return $ok;
}

function agent_can_exec()
{
    if (!function_exists('exec'))
        return false;
    $dis = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    return !in_array('exec', $dis, true);
}

// Run the worker in the background so the webhook returns at once (probes take ~1 min).
function agent_kick($uid)
{
    $uid = intval($uid);
    $php = agent_cfg()['php_bin'] ?: (PHP_SAPI === 'cli' && PHP_BINARY ? PHP_BINARY : PHP_BINDIR . '/php');
    if (agent_can_exec() && is_executable($php)) {
        exec('nohup ' . escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/worker.php') . ' ' . $uid . ' > /dev/null 2>&1 &');
        return;
    }
    ignore_user_abort(true);
    set_time_limit(300);
    agent_run($uid);
}

// ------------------------------------------------------------------ Claude API
function agent_api(array $body)
{
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['x-api-key: ' . agent_key(), 'anthropic-version: 2023-06-01', 'content-type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $j = is_string($raw) ? json_decode($raw, true) : null;
    if ($code !== 200 || !is_array($j)) {
        agent_log("api http=$code err=$err body=" . substr((string) $raw, 0, 300));
        return null;
    }
    if (!empty($j['usage']))
        agent_add_usage($j['usage']);
    return $j;
}

// Quick key check for the admin panel: [ok, message]
function agent_test_key()
{
    $r = agent_api(['model' => agent_cfg()['model'], 'max_tokens' => 5, 'messages' => [['role' => 'user', 'content' => 'ping']]]);
    return $r ? [true, $r['model'] ?? 'ok'] : [false, 'request failed (see storage/agent/agent.log)'];
}

function agent_prompt()
{
    $own = AGENT_DIR . '/prompt.md';
    $base = is_file($own) ? file_get_contents($own) : file_get_contents(__DIR__ . '/prompt.default.md');
    return trim($base) . "\n\n" . agent_rules();
}

// Rules that belong to the code (tools, escalation, privacy) — same for every install.
function agent_rules()
{
    $probe = agent_probe_configured();
    $r = "## ابزارها و قواعد سیستم\n";
    $r .= "- قانون اول (قبل از هر چیز، از همان پیام اول): کمک فنی (اتصال، کانفیگ، آموزش، تست سرویس) فقط برای سرویس فعالِ خودِ همین حساب است. اگر [سرویس فعال] صفر است، یا مشتری می‌گوید سرویس مال دوست/خانواده/شخص دیگری است (حتی اگر خودش برایش خریده ولی با حساب آن شخص)، سؤال نپرس، راهنمایی و تست نکن و check_connection را صدا نزن؛ فقط مؤدبانه بگو پشتیبانی فنی فقط برای کسی است که حداقل یک سرویس فعال در حساب خودش دارد، پس به خودش بگو با حساب خودش در همین ربات پیام بدهد و تو نمی‌توانی کمک کنی. اگر سرویس با همین حساب خریده شده (در اطلاعات سرویس هست) و فقط دست کس دیگری است، کمک کن. سؤال‌های مالی (پرداخت، شارژ، خرید، تمدید) این محدودیت را ندارند.\n";
    $r .= "- مشتری فقط با ربات حرف می‌زند. تو آیدی، یوزرنیم یا شمارهٔ ادمین و پشتیبان فنی را نمی‌دانی و هرگز نگو «به فلان آیدی پیام بده». ارجاع فقط با ابزار handoff یا report_outage است و جواب همکار از طریق همین ربات به مشتری می‌رسد.\n";
    $r .= "- اگر «بدون سرویس» آمده ولی مشتری خودش نام سرویس یا پروتکل را گفته، همان را مبنا بگیر و دوباره نپرس.\n";
    $r .= "- [دپارتمان] می‌گوید مشتری از کدام بخش پیام داده. موضوع مالی (پرداخت، شارژ کیف پول، بازگشت وجه، فاکتور، قیمت، تخفیف، نمایندگی) مال «پشتیبانی مالی» است و اتصال، کانفیگ، کندی و خرابی سرویس مال «پشتیبانی فنی». اگر مشتری بخش را اشتباه انتخاب کرده و کار به همکار می‌کشد، handoff را با department درست صدا بزن تا پیامش خودکار به همان بخش برود و به مشتری بگو «پیامت را به بخش مالی/فنی فرستادم، همین‌جا جواب می‌گیری». هرگز از مشتری نخواه دوباره از بخش دیگر پیام بدهد.\n";
    $r .= "- برای ارجاع به همکار ابزار handoff را صدا بزن (به‌جای نوشتن [[HANDOFF]]) و بعدش فقط یک جملهٔ کوتاه بگو که به همکار ارجاع شد.\n";
    if ($probe) {
        $r .= "- ابزار check_connection سرویس را واقعاً از یک سرور داخل ایران با اکانت تست همان پروتکل وصل می‌کند و IP خروجی و باز شدن سایت‌ها را می‌سنجد. اولین باری که مشتری می‌گوید وصل نمی‌شود، وصل می‌شود ولی سایتی باز نمی‌شود، یا کند است (و همیشه در «گزارش اختلال»)، قبل از هر راهنمایی اول همین ابزار را صدا بزن؛ بعد بر اساس نتیجه راهنمایی کن. اگر مشکلش سایت خاصی است و اسمش را نگفته، یک بار بپرس کدام سایت، و همان را در sites بده.\n";
        $r .= "- تفسیر نتیجه: ok=true یعنی سرویس از سمت ما سالم است؛ با مشتری عیب‌یابی کن (اپ، آپدیت لینک، نت، DNS، سرور یا لوکیشن دیگر). iran_exit=true (IP خروجی ایران است)، up=false یا باز نشدن سایت‌ها یعنی سرویس واقعاً مشکل دارد: report_outage را صدا بزن و به مشتری بگو اختلال از سمت ماست و در حال پیگیری است.\n";
        $r .= "- blocked_by_exit_country یعنی آن سایت را خود کشورِ آن لوکیشن فیلتر کرده (مثل یوتیوب در روسیه)؛ این اختلال نیست، بگو برای آن سایت لوکیشن دیگری انتخاب کند.\n";
        $r .= "- اگر تست ناموفق بود چون ابزار در دسترس نبود (error)، نتیجه‌گیری نکن و از روی راهنما جلو برو.\n";
    }
    $r .= "- report_outage فقط وقتی مجاز است که نتیجهٔ تست یا «پنل: قطع» خرابی را نشان دهد.\n";
    $r .= "- کنار فراخوانی ابزار متنی ننویس؛ جواب مشتری را بعد از گرفتن نتیجهٔ ابزار بنویس.
";
    $r .= "- IP کامل را به مشتری نگو؛ فقط کشور را بگو.\n";
    $r .= "- مشتری همین الان داخل همین ربات با تو حرف می‌زند؛ آیدی ربات را نده و بگو «در همین ربات».\n";
    $r .= agent_help_rules();
    $r .= agent_guide_rules();
    return $r;
}

// ------------------------------------------------------------------ [agent-guides] picture guides
// storage/agent/guides/<key>/guide.json = {"title": "...", "when": "...", "caption": "...", "images": ["step1.png", ...]}
function agent_guides()
{
    $out = [];
    foreach (glob(AGENT_DIR . '/guides/*/guide.json') ?: [] as $f) {
        $g = json_decode((string) file_get_contents($f), true);
        $key = basename(dirname($f));
        if (!is_array($g) || empty($g['images']) || !preg_match('/^[a-z0-9_]+$/', $key))
            continue;
        $imgs = [];
        foreach ((array) $g['images'] as $i) {
            $path = dirname($f) . '/' . basename((string) $i);
            if (is_file($path))
                $imgs[] = $path;
        }
        if ($imgs)
            $out[$key] = ['title' => (string) ($g['title'] ?? $key), 'when' => (string) ($g['when'] ?? ''),
                'caption' => (string) ($g['caption'] ?? ''), 'images' => array_slice($imgs, 0, 10)];
    }
    ksort($out);
    return $out;
}

function agent_guide_rules()
{
    $g = agent_guides();
    if (!$g)
        return '';
    $r = "- آموزش تصویری: ابزار send_guide عکس‌های قدم‌به‌قدم را مستقیم همین‌جا برای مشتری می‌فرستد. وقتی مشتری آموزش نصب، تنظیم یا «وارد کردن/بارگذاری فیلدها» را می‌خواهد و یکی از این آموزش‌ها به سرویسش می‌خورد، اول send_guide را صدا بزن، بعد کوتاه بگو «آموزش تصویری را فرستادم» و مقدارهای مخصوص خودش را بگو (مثلاً یوزر سرویسش). برای درخواست آموزش هرگز handoff نکن؛ فقط اگر بعد از آموزش باز وصل نشد، طبق قواعد عیب‌یابی کن. آموزش‌های موجود:\n";
    foreach ($g as $k => $x)
        $r .= "  - {$k}: «{$x['title']}»" . ($x['when'] !== '' ? " — {$x['when']}" : '') . "\n";
    return $r;
}

// Send one guide as a photo album; [ok, message for the model]
function agent_send_guide($uid, $key)
{
    $g = agent_guides()[$key] ?? null;
    if (!$g)
        return 'no such guide; use one of: ' . implode(', ', array_keys(agent_guides()));
    $media = [];
    $data = ['chat_id' => $uid];
    foreach ($g['images'] as $n => $path) {
        $m = ['type' => 'photo', 'media' => "attach://p$n"];
        if ($n === 0 && $g['caption'] !== '')
            $m['caption'] = mb_substr($g['caption'], 0, 1000);
        $media[] = $m;
        $data["p$n"] = new CURLFile($path, 'image/png', "step$n.png");
    }
    $data['media'] = json_encode($media, JSON_UNESCAPED_UNICODE);
    $r = count($media) === 1
        ? telegram('sendPhoto', ['chat_id' => $uid, 'photo' => $data['p0'], 'caption' => $media[0]['caption'] ?? ''])
        : telegram('sendMediaGroup', $data);
    if (empty($r['ok'])) {
        agent_log("guide $key failed: " . json_encode($r));
        return 'sending failed; explain the steps in text instead';
    }
    return 'sent ' . count($media) . ' pictures of «' . $g['title'] . '»; now tell the customer briefly, do not repeat every step';
}

// What the bot's «📚 آموزش» section really holds, read from the help table.
function agent_help_rules()
{
    global $pdo;
    try {
        $rows = $pdo->query('SELECT name_os, category FROM help')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return '';
    }
    if (!$rows)
        return '';
    $by = [];
    foreach ($rows as $h) {
        $cat = trim((string) ($h['category'] ?? ''));
        $by[$cat === '' || strtolower($cat) === 'null' ? '-' : $cat][] = trim((string) $h['name_os']);
    }
    $list = [];
    foreach ($by as $cat => $names)
        $list[] = ($cat === '-' ? '' : "«{$cat}»: ") . implode('، ', array_map(function ($n) {
            return "«{$n}»";
        }, $names));
    return "- بخش «📚 آموزش» در منوی اصلی همین ربات این‌ها را دارد (فایل‌های سرور، برنامه‌ها و آموزش‌ها): " . implode(' | ', $list)
        . ". وقتی مشتری فایل سرور، برنامه یا آموزش نصب ندارد یا می‌خواهد، نگو نمی‌توانی بدهی؛ بگو در منوی اصلی «📚 آموزش» را بزند و همان مورد را انتخاب کند (نام دقیق مورد را بگو). اگر چیزی که می‌خواهد در این فهرست نیست، از «سرویس‌های من» یا handoff کمک بگیر.\n";
}

function agent_tools()
{
    $t = [
        ['name' => 'handoff', 'description' => 'Hand the conversation to a human colleague (admin/support). Use for every case the guide says to refer.',
            'input_schema' => ['type' => 'object', 'properties' => [
                'reason' => ['type' => 'string', 'description' => 'short reason in Persian'],
                'department' => ['type' => 'string', 'enum' => ['finance', 'tech'], 'description' => 'which team should answer: finance = money/payments/refunds/prices, tech = connection/service problems'],
            ], 'required' => ['reason']]],
        ['name' => 'report_outage', 'description' => 'Report a confirmed outage of our service/panel to the admin. Only after check_connection failed or the panel is down.',
            'input_schema' => ['type' => 'object', 'properties' => ['summary' => ['type' => 'string', 'description' => 'what is broken, in Persian']], 'required' => ['summary']]],
    ];
    if ($guides = agent_guides()) {
        $t[] = ['name' => 'send_guide', 'description' => 'Send a step-by-step picture guide to the customer in this chat.',
            'input_schema' => ['type' => 'object', 'properties' => [
                'guide' => ['type' => 'string', 'enum' => array_keys($guides)],
            ], 'required' => ['guide']]];
    }
    if (agent_probe_configured()) {
        $t[] = ['name' => 'check_connection',
            'description' => 'Really connect the given protocol from a filtered server inside Iran with a test account; returns tunnel up, exit IP country, and whether sites open. Takes up to 1-2 minutes.',
            'input_schema' => ['type' => 'object', 'properties' => [
                'protocol' => ['type' => 'string', 'enum' => ['v2ray', 'wireguard', 'openvpn', 'l2tp']],
                'sites' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'extra domains the customer named (max 3)'],
                'location' => ['type' => 'string', 'description' => 'v2ray only: part of the location name to test'],
            ], 'required' => ['protocol']]];
    }
    $t[count($t) - 1]['cache_control'] = ['type' => 'ephemeral'];
    return $t;
}

// ------------------------------------------------------------------ service info for the model
function agent_service_info($uid, $invoiceId = null)
{
    global $pdo, $ManagePanel;
    if (!isset($ManagePanel) && class_exists('ManagePanel'))
        $ManagePanel = new ManagePanel();
    if ($invoiceId) {
        $rows = [select('invoice', '*', 'id_invoice', $invoiceId, 'select')];
    } else {
        $st = $pdo->prepare("SELECT * FROM invoice WHERE id_user = :u AND Status NOT IN ('unpaid','removeTime','removebyadmin','Unsuccessful') ORDER BY time_sell DESC LIMIT 3");
        $st->execute([':u' => $uid]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    $out = [];
    foreach ($rows as $r) {
        if (!is_array($r) || (string) ($r['id_user'] ?? '') !== (string) $uid)
            continue;
        $line = 'سرویس: ' . $r['name_product'] . ' | پنل: ' . $r['Service_location'] . ' | یوزر: ' . $r['username'];
        $d = isset($ManagePanel) ? $ManagePanel->DataUser($r['Service_location'], $r['username']) : null;
        $miss = is_array($d) && preg_match('/not\s*found|یافت نشد|پیدا نشد|not exist/iu', (string) ($d['msg'] ?? ''));
        if ($miss) {
            $line .= ' | روی پنل پیدا نشد (حذف یا منقضی شده؛ اختلال نیست، به همکار ارجاع بده)';
        } elseif (!is_array($d) || ($d['status'] ?? '') === 'Unsuccessful') {
            $line .= ' | پنل: قطع';
        } else {
            $gb = function ($b) {
                return $b ? round($b / 1073741824, 2) . 'GB' : '0';
            };
            $line .= ' | وضعیت: ' . ($d['status'] ?? '?');
            $line .= ' | حجم کل: ' . (!empty($d['data_limit']) ? $gb($d['data_limit']) : 'نامحدود');
            $line .= ' | مصرف: ' . $gb($d['used_traffic'] ?? 0);
            if (!empty($d['expire']))
                $line .= ' | روز باقی‌مانده: ' . floor(($d['expire'] - time()) / 86400);
            $on = $d['online_at'] ?? null;
            $line .= ' | آخرین اتصال: ' . ($on === 'online' ? 'آنلاین' : ($on === 'offline' ? 'آفلاین' : ($on ? 'آفلاین' : 'متصل نشده')));
            $line .= ' | پنل: وصل';
        }
        $out[] = $line;
    }
    return $out ? implode("\n", $out) : 'بدون سرویس';
}

// ------------------------------------------------------------------ connection probe (over SSH)
function agent_probe_configured()
{
    $p = agent_cfg()['probe'];
    return !empty($p['host']) && is_file(AGENT_DIR . '/probe_key');
}

function agent_probe($proto, array $sites, $location = '')
{
    $map = ['v2ray' => 'v2ray', 'wireguard' => 'wg', 'openvpn' => 'openvpn', 'l2tp' => 'l2tp'];
    if (!isset($map[$proto]))
        return ['error' => 'unknown protocol'];
    $args = [$map[$proto]];
    $clean = [];
    foreach (array_slice($sites, 0, 3) as $s) {
        $h = strtolower(trim((string) $s));
        $h = preg_replace('#^https?://#', '', $h);
        $h = preg_replace('#[/?:].*$#', '', $h);
        if (preg_match('/^[a-z0-9][a-z0-9.-]{0,252}\.[a-z]{2,}$/', $h)) {
            $clean[] = $h;
            $args[] = '--site';
            $args[] = $h;
        }
    }
    $location = trim((string) $location);
    if ($proto === 'v2ray' && $location !== '' && preg_match('/^[\p{L}\p{N} _-]{1,40}$/u', $location)) {
        $args[] = '--only';
        $args[] = $location;
    }
    $ck = agent_dir('probe-cache') . '/' . md5(implode(' ', $args)) . '.json';
    if (is_file($ck) && time() - filemtime($ck) < 300) {
        $j = json_decode((string) file_get_contents($ck), true);
        if (is_array($j))
            return $j;
    }
    $p = agent_cfg()['probe'];
    $cmd = ['ssh', '-F', '/dev/null', '-i', AGENT_DIR . '/probe_key', '-p', (string) intval($p['port']),
        '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', '-o', 'StrictHostKeyChecking=accept-new',
        '-o', 'UserKnownHostsFile=' . AGENT_DIR . '/known_hosts', $p['user'] . '@' . $p['host'], '--'];
    $remote = implode(' ', array_map(function ($a) {
        return preg_match('/^[A-Za-z0-9._-]+$/', $a) ? $a : base64_encode($a) . '#b64';
    }, $args));
    $cmd[] = $remote;
    $line = implode(' ', array_map('escapeshellarg', $cmd));
    $out = agent_run_cmd($line, 200);
    $j = json_decode(trim($out), true);
    if (!is_array($j) || isset($j['error'])) {
        agent_log('probe failed: ' . substr($out, 0, 200));
        return ['error' => 'probe unavailable'];
    }
    $sum = agent_probe_summary($proto, $j[$map[$proto]] ?? [], $clean);
    file_put_contents($ck, json_encode($sum, JSON_UNESCAPED_UNICODE));
    return $sum;
}

function agent_run_cmd($line, $timeout)
{
    $pr = proc_open($line, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($pr))
        return '';
    stream_set_blocking($pipes[1], false);
    $out = '';
    $t0 = time();
    while (true) {
        $out .= (string) stream_get_contents($pipes[1]);
        $st = proc_get_status($pr);
        if (!$st['running'])
            break;
        if (time() - $t0 > $timeout) {
            proc_terminate($pr, 9);
            break;
        }
        usleep(200000);
    }
    $out .= (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($pr);
    return $out;
}

// Small, model-friendly result: no IPs, no secrets.
function agent_probe_summary($proto, array $r, array $asked)
{
    $one = function ($x) {
        $s = ['ok' => (bool) ($x['ok'] ?? false), 'up' => (bool) ($x['up'] ?? false)];
        if (isset($x['country']))
            $s['exit_country'] = $x['country'];
        if (array_key_exists('iran_exit', $x))
            $s['iran_exit'] = (bool) $x['iran_exit'];
        if (!empty($x['err']))
            $s['error'] = $x['err'];
        if (isset($x['download']['kbps']))
            $s['download_kbps'] = $x['download']['kbps'];
        foreach (($x['sites'] ?? []) as $h => $v) {
            $s['sites'][$h] = !empty($v['ok']) ? 'open' : (!empty($v['blocked_by_exit_country']) ? 'blocked_by_exit_country' : 'not_open');
        }
        return $s;
    };
    if ($proto === 'v2ray') {
        $locs = [];
        foreach (($r['by_location'] ?? []) as $name => $x)
            $locs[$name] = $one($x);
        return ['protocol' => 'v2ray', 'ok' => (bool) ($r['ok'] ?? false), 'up' => (bool) ($r['up'] ?? false),
            'locations_ok' => $r['locations_ok'] ?? 0, 'locations' => $r['locations'] ?? 0, 'by_location' => $locs]
            + (!empty($r['err']) ? ['error' => $r['err']] : []) + (!empty($r['skip']) ? ['error' => 'not testable: ' . $r['skip']] : []);
    }
    if (!empty($r['skip']))
        return ['protocol' => $proto, 'error' => 'not testable: ' . $r['skip']];
    return ['protocol' => $proto] + $one($r);
}

// ------------------------------------------------------------------ escalation
function agent_handoff($uid, array $c, $reason)
{
    global $pdo, $textbotlang;
    $ctx = $c['ctx'];
    $talk = implode("\n— ", array_slice($c['user_msgs'] ?? [], -6));
    $note = "\n\n🤖 پشتیبان هوشمند ارجاع داد: " . htmlspecialchars($reason) . "\nپیام‌های مشتری:\n— " . htmlspecialchars($talk);
    if (($ctx['kind'] ?? '') === 'disorder') {
        $tech = trim((string) (agent_cfg()['tech_id'] ?? ''));
        if ($tech !== '') {   // unresolved disorder -> technical support; admins only if that fails
            $r = sendmessage($tech, $ctx['handoff_text'] . $note, $ctx['handoff_kb'] ?? null, 'html');
            if (!empty($r['ok']))
                return;
            agent_log('tech notify failed: ' . json_encode($r));
        }
        agent_notify_admins($ctx['handoff_text'] . $note, $ctx['handoff_kb'] ?? null, 'html');
        return;
    }
    $want = $c['handoff_dep'] ?? '';
    $cfg = agent_cfg();
    $depId = $want === 'finance' && !empty($cfg['dep_fin']) ? $cfg['dep_fin'] : ($want === 'tech' && !empty($cfg['dep_tech']) ? $cfg['dep_tech'] : ($ctx['dep_id'] ?? 0));
    $dep = select('departman', '*', 'id', $depId, 'select');
    if (!$dep)
        $dep = select('departman', '*', 'id', $ctx['dep_id'] ?? 0, 'select');
    if (!$dep) {
        $dep = select('departman', '*', null, null, 'select');
    }
    $first = $c['user_msgs'][0] ?? '';
    $tracking = bin2hex(random_bytes(4));
    $time = date('Y/m/d H:i:s');
    $stmt = $pdo->prepare("INSERT IGNORE INTO support_message (Tracking,idsupport,iduser,name_departman,text,time,status) VALUES (?,?,?,?,?,?,?)");
    $stmt->execute([$tracking, $dep['idsupport'], $uid, $dep['name_departman'], $first, $time, 'Unseen']);
    $txt = sprintf($textbotlang['Admin']['reportgroup']['supportMessage'], $uid, $uid, jdate('Y/m/d H:i:s'),
        $ctx['username'] ?? '', $dep['name_departman'], htmlspecialchars($first), '') . $note;
    $kb = json_encode(['inline_keyboard' => [[['text' => $textbotlang['users']['support']['answermessage'], 'callback_data' => 'Responsesupport_' . $tracking]]]]);
    sendmessage($dep['idsupport'], $txt, $kb, 'HTML');
}

// The configured admin alone, else every non-seller admin.
function agent_notify_admins($txt, $kb = null, $mode = 'HTML')
{
    $one = trim((string) (agent_cfg()['admin_id'] ?? ''));
    if ($one !== '') {
        $r = sendmessage($one, $txt, $kb, $mode);
        if (!empty($r['ok']))
            return true;
        agent_log('admin notify failed: ' . json_encode($r));
    }
    $ok = false;
    foreach ((select('admin', '*', null, null, 'fetchAll') ?: []) as $a) {
        if (($a['rule'] ?? '') === 'Seller')
            continue;
        $r = sendmessage($a['id_admin'], $txt, $kb, $mode);
        $ok = $ok || !empty($r['ok']);
    }
    return $ok;
}

function agent_outage_allowed(array $c)
{
    foreach (($c['probes'] ?? []) as $p) {
        if (empty($p['error']) && empty($p['ok']))
            return true;
    }
    return strpos($c['service_info'] ?? '', 'پنل: قطع') !== false;
}

function agent_report_outage($uid, array $c, $summary)
{
    $key = md5(json_encode(array_map(function ($p) {
        return $p['protocol'] ?? '';
    }, $c['probes'] ?? [])) . ($c['ctx']['invoice'] ?? ''));
    $dup = false;
    agent_json_update(agent_dir() . '/outages.json', function (&$o) use ($key, &$dup) {
        $o = is_array($o) ? array_filter($o, function ($t) {
            return time() - $t < 1800;
        }) : [];
        if (isset($o[$key])) {
            $dup = true;
            return false;
        }
        $o[$key] = time();
    });
    if ($dup)
        return 'already reported recently; tell the customer it is being worked on';
    $probe = '';
    foreach (($c['probes'] ?? []) as $p)
        $probe .= "\n<code>" . htmlspecialchars(json_encode($p, JSON_UNESCAPED_UNICODE)) . '</code>';
    $txt = "🚨 اختلال تأییدشده (پشتیبان هوشمند)\n\nکاربر: <a href=\"tg://user?id=$uid\">$uid</a> @" . htmlspecialchars($c['ctx']['username'] ?? '')
        . "\n" . htmlspecialchars($c['service_info'] ?? '') . "\n\nخلاصه: " . htmlspecialchars($summary)
        . "\nپیام مشتری: " . htmlspecialchars(implode(' / ', array_slice($c['user_msgs'] ?? [], -3))) . "\n\nنتیجهٔ تست:" . $probe;
    if (agent_notify_admins(mb_substr($txt, 0, 3900)))   // confirmed outage -> admin
        return 'reported to the admin';
    agent_log('outage notify failed for every admin');
    agent_handoff($uid, $c, 'اختلال تأییدشده: ' . $summary);
    return 'reported to support';
}

// ------------------------------------------------------------------ the worker loop
function agent_send($uid, $text)
{
    $text = trim(preg_replace('/^\s*\[\[HANDOFF:[^\]]*\]\]\s*/u', '', (string) $text));
    if ($text !== '')
        sendmessage($uid, htmlspecialchars($text), null, 'HTML');
}

function agent_run($uid)
{
    $lock = fopen(agent_dir('conv') . '/' . intval($uid) . '.run', 'c');
    flock($lock, LOCK_EX);                 // one worker per user; later ones wait, then find an empty inbox
    for ($i = 0; $i < 10; $i++) {
        $msgs = null;
        agent_json_update(agent_conv_path($uid), function (&$c) use (&$msgs) {
            if (!is_array($c) || !empty($c['done']) || empty($c['inbox']))
                return false;
            $msgs = $c['inbox'];
            $c['inbox'] = [];
        });
        if (!$msgs)
            break;
        try {
            agent_turn($uid, implode("\n", $msgs));
        } catch (Throwable $e) {
            agent_log("turn error uid=$uid: " . $e->getMessage());
            agent_finish($uid, 'خطای داخلی پشتیبان هوشمند');
        }
    }
    flock($lock, LOCK_UN);
    fclose($lock);
}

// Close the conversation and hand it to a human (used for every failure path too).
function agent_finish($uid, $reason, $tell = true)
{
    $c = agent_conv($uid);
    if (!$c || !empty($c['done']))
        return;
    agent_json_update(agent_conv_path($uid), function (&$x) {
        $x['done'] = true;
    });
    agent_handoff($uid, $c, $reason);
    step('home', $uid);
    if ($tell)
        agent_send($uid, 'پیامت را برای همکارم فرستادم، به‌زودی همین‌جا جواب می‌گیری.');
}

function agent_turn($uid, $text)
{
    $c = agent_conv($uid);
    if (!$c)
        return;
    if (agent_over_budget())
        return agent_finish($uid, 'سقف بودجهٔ پشتیبان هوشمند پر شده');
    if (count($c['user_msgs'] ?? []) > agent_cfg()['max_user_msgs'])
        return agent_finish($uid, 'گفتگو طولانی شد');
    telegram('sendChatAction', ['chat_id' => $uid, 'action' => 'typing']);
    if (empty($c['messages'])) {
        $c['service_info'] = agent_service_info($uid, $c['ctx']['invoice'] ?? null);
        $head = '[اطلاعات سرویس] ' . $c['service_info'];
        $head .= "\n[سرویس فعال] " . (int) ($c['ctx']['active_services'] ?? agent_active_services($uid));
        if (($c['ctx']['kind'] ?? '') === 'disorder')
            $head .= "\n[نوع درخواست] گزارش اختلال همین سرویس";
        elseif (!empty($c['ctx']['dep_id'])) {
            $kind = agent_dep_kind($c['ctx']['dep_id']);
            $dn = $kind === 'finance' ? 'پشتیبانی مالی' : ($kind === 'tech' ? 'پشتیبانی فنی' : (string) (select('departman', '*', 'id', $c['ctx']['dep_id'], 'select')['name_departman'] ?? ''));
            if ($dn !== '')
                $head .= "\n[دپارتمان] " . $dn;
        }
        $text = $head . "\n[پیام کاربر] " . $text;
    }
    $c['messages'][] = ['role' => 'user', 'content' => $text];
    $system = [['type' => 'text', 'text' => agent_prompt(), 'cache_control' => ['type' => 'ephemeral']]];
    $handed = null;
    $outage = false;
    for ($step = 0; $step < 5; $step++) {
        $r = agent_api(['model' => agent_cfg()['model'], 'max_tokens' => 500, 'thinking' => ['type' => 'disabled'],
            'system' => $system, 'tools' => agent_tools(), 'messages' => $c['messages']]);
        if (!$r) {
            agent_save($uid, $c);
            return agent_finish($uid, 'پشتیبان هوشمند در دسترس نبود');
        }
        $c['messages'][] = ['role' => 'assistant', 'content' => $r['content']];
        $said = '';
        $results = [];
        foreach ($r['content'] as $b) {
            if ($b['type'] === 'text')
                $said .= $b['text'];
        }
        if (preg_match('/\[\[HANDOFF:([^\]]*)\]\]/u', $said, $m))
            $handed = trim($m[1]) ?: 'ارجاع';
        foreach ($r['content'] as $b) {
            if ($b['type'] !== 'tool_use')
                continue;
            $in = $b['input'] ?? [];
            if ($b['name'] === 'handoff') {
                $handed = (string) ($in['reason'] ?? 'ارجاع');
                if (in_array($in['department'] ?? '', ['finance', 'tech'], true))
                    $c['handoff_dep'] = $in['department'];
                $res = 'handed off; now tell the customer in one short sentence that a colleague will answer here';
            } elseif ($b['name'] === 'report_outage') {
                if (agent_outage_allowed($c)) {
                    $res = agent_report_outage($uid, $c, (string) ($in['summary'] ?? ''));
                    $outage = true;
                } else {
                    $res = 'not allowed: no failed test and the panel is up. Run check_connection first or guide the customer.';
                }
            } elseif ($b['name'] === 'send_guide') {
                telegram('sendChatAction', ['chat_id' => $uid, 'action' => 'upload_photo']);
                $res = agent_send_guide($uid, (string) ($in['guide'] ?? ''));
            } elseif ($b['name'] === 'check_connection') {
                agent_send($uid, '🔎 دارم اتصال سرویست را از سمت خودمان تست می‌کنم، حدود یک دقیقه صبر کن…');
                telegram('sendChatAction', ['chat_id' => $uid, 'action' => 'typing']);
                $res = agent_probe((string) ($in['protocol'] ?? ''), (array) ($in['sites'] ?? []), (string) ($in['location'] ?? ''));
                $c['probes'][] = $res;
                $res = json_encode($res, JSON_UNESCAPED_UNICODE);
            } else {
                $res = 'unknown tool';
            }
            $results[] = ['type' => 'tool_result', 'tool_use_id' => $b['id'], 'content' => $res];
        }
        if ($results) {                      // text next to a tool call is the model thinking aloud: not for the customer
            $c['messages'][] = ['role' => 'user', 'content' => $results];
            continue;
        }
        $fallback = ($c['handoff_dep'] ?? '') === 'finance' ? 'پیامت را به بخش مالی فرستادم، به‌زودی همین‌جا جواب می‌گیری.'
            : (($c['handoff_dep'] ?? '') === 'tech' ? 'پیامت را به بخش فنی فرستادم، به‌زودی همین‌جا جواب می‌گیری.' : 'به همکارم ارجاع دادم، به‌زودی همین‌جا جواب می‌گیری.');
        agent_send($uid, $handed !== null && trim($said) === '' ? $fallback : $said);
        break;
    }
    $c['updated'] = time();
    if ($handed !== null) {
        $c['done'] = true;
        agent_save($uid, $c);
        agent_handoff($uid, $c, $handed);
        step('home', $uid);
        return;
    }
    agent_save($uid, $c);
}

function agent_save($uid, array $c)
{
    agent_json_update(agent_conv_path($uid), function (&$x) use ($c) {
        $inbox = is_array($x) ? ($x['inbox'] ?? []) : [];
        $msgs = is_array($x) ? ($x['user_msgs'] ?? []) : [];
        $x = $c;
        $x['inbox'] = $inbox;           // messages that arrived while we were working
        $x['user_msgs'] = $msgs;
    });
}
