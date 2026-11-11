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
    // [agent-authfix]
    $r .= "- اگر مشتری سرویس فعال دارد و می‌گوید وصل نمی‌شود یا خطای یوزر/رمز می‌گیرد، این مشکل فنی است و خودت حلش می‌کنی، حتی اگر از «پشتیبانی مالی» پیام داده یا گفته «پول دادم/پرداخت کردم» یا از پشتیبانی گله کرده. فعال بودن سرویس یعنی پرداختش انجام شده؛ این را نه به مالی بفرست و نه به پشتیبان فنی، اول check_connection را با یوزر خودش صدا بزن. فقط اگر پرداختی واقعاً ثبت نشده (سرویسی نخریده یا کیف پولش شارژ نشده) مالی است.\n";
    $r .= "- برای ارجاع به همکار ابزار handoff را صدا بزن (به‌جای نوشتن [[HANDOFF]]) و بعدش فقط یک جملهٔ کوتاه بگو که به همکار ارجاع شد.\n";
    if ($probe) {
        // [agent-acctprobe]
        $r .= "- ابزار check_connection با «اکانت خودِ مشتری» (username) و همان پروتکلی که استفاده می‌کند از یک سرور داخل ایران واقعاً وصل می‌شود و IP خروجی و باز شدن سایت‌ها را می‌سنجد. وقتی مشتری می‌گوید وصل نمی‌شود، وصل می‌شود ولی سایتی باز نمی‌شود، یا کند است (و همیشه در «گزارش اختلال»)، قبل از هر راهنمایی این ابزار را صدا بزن و بعد بر اساس نتیجه راهنمایی کن. حتی اگر مشتری متن خطا را گفته یا عکسش را فرستاده (مثل auth failed یا TLS error)، باز هم اول تست کن و از روی متن خطا حدس نزن؛ تست نشان می‌دهد یوزر و رمزِ ثبت‌شدهٔ خودِ سرویس از ایران کار می‌کند یا نه. چیزی دربارهٔ تغییر رمز توسط مشتری نگو.\n";
        $r .= "- قبل از تست بدان کدام سرویس: اگر فقط یک سرویس فعال دارد یا خودش یوزر را گفته یا [نوع درخواست] گزارش اختلال همان سرویس است، مستقیم تست کن؛ اگر چند سرویس فعال دارد، یک بار بپرس روی کدام یوزر/سرویس مشکل دارد و با کدام برنامه (V2Ray، OpenVPN، L2TP، WireGuard). یوزر را در username بده. اگر مشکلش سایت خاصی است و اسمش را نگفته، یک بار بپرس و در sites بده.\n";
        $r .= "- سرویس‌های OpenVPN و L2TP فقط یک اتصال هم‌زمان دارند: اگر نتیجه customer_online بود، از مشتری بخواه برنامه را قطع کند و بعد از تأیید او دوباره با customer_disconnected=true تست کن. اگر سرویس منقضی/حجم‌تمام/غیرفعال بود تست نکن و همان را بگو (تمدید یا حجم اضافه).\n";
        $r .= "- نتیجه با tested_with=customer_account: ok=true یعنی اکانت و سرور مشتری از ایران سالم کار می‌کند؛ مشکل سمت دستگاه/برنامه/نت مشتری است، پس قدم‌به‌قدم عیب‌یابی کن (آپدیت لینک/ساب، حذف و افزودن دوبارهٔ کانفیگ، نسخهٔ برنامه، تعویض نت یا سرور دیگر، ساعت گوشی). AUTH_FAILED با tested_with=customer_account یعنی خودِ یوزر و رمزِ ثبت‌شدهٔ مشتری (همان که در «سرویس‌های من» می‌بیند) روی سرور رد شد؛ این تقصیر تایپ مشتری نیست، پس به او نگو رمز را اشتباه زده: یک بار با use_test_account=true مقایسه کن؛ اگر اکانت تست وصل شد، مشکل مخصوص اکانت اوست (رمز/یوزر در سرور با ربات یکی نیست) و با handoff به پشتیبانی فنی بده، اگر اکانت تست هم رد شد report_outage. subscription_update=failed یعنی لینک اشتراک از ایران باز نمی‌شود. اگر اکانت مشتری با خطایی غیر از ورود شکست خورد، یک بار با use_test_account=true و همان پروتکل مقایسه کن: اکانت تست سالم = مشکل مخصوص اکانت مشتری (handoff فنی با جزئیات)، هر دو خراب = اختلال (report_outage). tested_with=our_test_account با note یعنی تست با اکانت خودمان بوده نه اکانت مشتری.\n";
        // [agent-authfix]
        $r .= "- خطای ورود (مشتری می‌گوید یوزر/رمز را درست زدم ولی auth failed، «نام کاربری یا رمز اشتباه است»، ): اسکرین‌شات نخواه و از مشتری نپرس، همان اول check_connection را با یوزر خودش صدا بزن (این تست تعیین‌کننده است و قاعدهٔ «اول اسکرین‌شات» برای خطای یوزر/رمز اجرا نمی‌شود). اگر تست با اکانت خودش ok=true شد یعنی یوزر و رمزِ ثبت‌شده همین الان از ایران کار می‌کند و مشکل فقط در تایپ آن‌هاست؛ صریح بگو «اکانتت را همین الان تست کردم و با یوزر و رمز درست وصل شد، پس یوزر یا رمز در برنامه اشتباه وارد شده» و این را عیب دستگاه، اختلال یا کار پشتیبان نکن. بعد قدم‌به‌قدم بگو: ۱) نام کاربری دقیقاً همان یوزرِ سرویس است (همان که در [اطلاعات سرویس] آمده، بدون پیشوند مثل openvpn یا اسم فایل و بدون فاصله)؛ ۲) رمز را از «سرویس‌های من» همان سرویس کپی کند نه تایپ، و فاصلهٔ اول/آخر نداشته باشد؛ ۳) اگر تایپ می‌کند کیبورد انگلیسی باشد و عدد فارسی نزند، حروف کوچک/بزرگ مهم است؛ ۴) در OpenVPN Connect پروفایل را باز کند (آیکون مداد/Edit)، Username و Password را پاک کند و دوباره وارد کند و Save password را بزند. در همین جواب یوزر را عیناً بنویس (به شکل «نام کاربری: » و بعد یوزرِ همین سرویس از [اطلاعات سرویس])، ولی رمز را هرگز در چت ننویس. اگر بعد از این باز همان خطا را داد، یک اسکرین‌شات از صفحهٔ ویرایش پروفایل (بدون رمز) بخواه.\n";
        // [agent-radlog]
        $r .= "- سرویس‌های OpenVPN و L2TP (از جمله بخش OpenVPN/L2TP سرویس‌های Magic MAX) روی سرور ورود ما ساخته می‌شوند و هر ورودِ ردشده‌شان ثبت می‌شود؛ پس برای هر شکایت اتصالِ OpenVPN یا L2TP، از همان جواب اول check_connection را بزن و قبل از آن اسکرین‌شات یا متن خطا نخواه (اسکرین‌شات فقط اگر بعد از راهنمایی باز وصل نشد). نتیجه login_log دارد: تلاش‌های ردشدهٔ همین مشتری در ۲۴ ساعت اخیر با verdict فارسی هر ردیف. verdict را دنبال کن، چون از روی لاگ واقعی و در کد حساب شده و دقیق‌ترین سند است: یوزر اشتباه تایپ شده → دقیقاً بگو چه زده و درستش چیست؛ فقط رمز اشتباه → بگو یوزرت درست است و رمز را کپی کن؛ سمت ما → handoff فنی بدون مقصر کردن مشتری. رمز را هرگز ننویس و جواب همیشه فارسی است، حتی اگر نتیجهٔ ابزار انگلیسی باشد.\n";
        $r .= "- تفسیر نتیجه: ok=true یعنی سرویس از سمت ما سالم است؛ با مشتری عیب‌یابی کن (اپ، آپدیت لینک، نت، DNS، سرور یا لوکیشن دیگر). iran_exit=true (IP خروجی ایران است)، up=false یا باز نشدن سایت‌ها یعنی سرویس واقعاً مشکل دارد: report_outage را صدا بزن و به مشتری بگو اختلال از سمت ماست و در حال پیگیری است.\n";
        $r .= "- blocked_by_exit_country یعنی آن سایت را خود کشورِ آن لوکیشن فیلتر کرده (مثل یوتیوب در روسیه)؛ این اختلال نیست، بگو برای آن سایت لوکیشن دیگری انتخاب کند.\n";
        $r .= "- اگر تست ناموفق بود چون ابزار در دسترس نبود (error)، نتیجه‌گیری نکن و از روی راهنما جلو برو.\n";
    }
    $r .= "- report_outage فقط وقتی مجاز است که نتیجهٔ تست یا «پنل: قطع» خرابی را نشان دهد.\n";
    $r .= "- کنار فراخوانی ابزار متنی ننویس؛ جواب مشتری را بعد از گرفتن نتیجهٔ ابزار بنویس.
";
    $r .= "- IP کامل را به مشتری نگو؛ فقط کشور را بگو.\n";
    $r .= "- مشتری همین الان داخل همین ربات با تو حرف می‌زند؛ آیدی ربات را نده و بگو «در همین ربات».\n";
    $r .= agent_photo_rules();    // [agent-photo]
    $r .= agent_help_rules();
    $r .= agent_guide_rules();
    return $r;
}

// ------------------------------------------------------------------ [agent-photo] customer screenshots
// A photo (or an image sent as a file) is queued as "[[IMG:<file_id>]] caption". Only the Telegram
// file_id is kept on disk; the picture is fetched for the model call and never stored.
const AGENT_IMG_RE = '/\[\[IMG:([A-Za-z0-9_-]+)\]\]/';

// Inbox entry for a photo message, '' when it is not an image the agent can read.
function agent_media_msg($photo, $document, $caption)
{
    $id = '';
    if (is_array($photo) && $photo) {
        $big = end($photo);                  // Telegram lists sizes small -> large
        $id = (string) ($big['file_id'] ?? '');
    } elseif (is_array($document) && preg_match('#^image/(jpeg|png|webp|gif)$#', (string) ($document['mime_type'] ?? ''))
        && (int) ($document['file_size'] ?? 0) <= 5000000) {
        $id = (string) ($document['file_id'] ?? '');
    }
    if ($id === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $id))
        return '';
    return trim('[[IMG:' . $id . ']] ' . trim((string) $caption));
}

// Telegram file -> Anthropic image block, null on any failure.
function agent_image_block($fileId)
{
    global $APIKEY;
    $f = telegram('getFile', ['file_id' => $fileId]);
    $path = (string) ($f['result']['file_path'] ?? '');
    if ($path === '' || (int) ($f['result']['file_size'] ?? 0) > 5000000)
        return null;
    $ch = curl_init(AGENT_TG_FILE_BASE . $APIKEY . '/' . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !is_string($raw) || $raw === '' || strlen($raw) > 5000000)
        return null;
    $info = @getimagesizefromstring($raw);
    $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true))
        return null;
    return ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode($raw)]];
}
if (!defined('AGENT_TG_FILE_BASE'))
    define('AGENT_TG_FILE_BASE', 'https://api.telegram.org/file/bot');

// User turn content: up to 4 pictures, then the text with each tag replaced by a short label.
function agent_user_content($text)
{
    if (!preg_match(AGENT_IMG_RE, $text))
        return $text;
    $blocks = [];
    $text = preg_replace_callback(AGENT_IMG_RE, function ($m) use (&$blocks) {
        $b = count($blocks) < 4 ? agent_image_block($m[1]) : null;
        if (!$b) {
            agent_log('photo fetch failed');
            return '[مشتری عکسی فرستاد که باز نشد؛ اگر لازم است بخواه دوباره بفرستد]';
        }
        $blocks[] = $b;
        return '[عکس ' . count($blocks) . ' از مشتری، پیوست]';
    }, $text);
    $blocks[] = ['type' => 'text', 'text' => $text];
    return $blocks;
}

// Pictures are sent once; the saved history keeps a label so old turns are not re-billed.
function agent_strip_images(array $msgs)
{
    foreach ($msgs as &$m) {
        if (!is_array($m['content'] ?? null))
            continue;
        foreach ($m['content'] as $i => $b) {
            if (($b['type'] ?? '') === 'image')
                $m['content'][$i] = ['type' => 'text', 'text' => '[عکس مشتری؛ در همان نوبت دیده و بررسی شد]'];
        }
    }
    unset($m);
    return $msgs;
}

function agent_img_label($s)
{
    return trim(preg_replace(AGENT_IMG_RE, '🖼 [عکس]', (string) $s));
}

function agent_conv_photos(array $c)
{
    $ids = [];
    foreach ($c['user_msgs'] ?? [] as $s) {
        if (preg_match_all(AGENT_IMG_RE, (string) $s, $m))
            $ids = array_merge($ids, $m[1]);
    }
    return array_slice(array_values(array_unique($ids)), -5);
}

// Give the human the customer's screenshots too.
function agent_send_photos($chat, array $ids)
{
    if (trim((string) $chat) === '')
        return;
    foreach ($ids as $n => $id) {
        $cap = '🖼 عکس ' . ($n + 1) . ' از مشتری';
        $r = telegram('sendPhoto', ['chat_id' => $chat, 'photo' => $id, 'caption' => $cap]);
        if (empty($r['ok']))
            telegram('sendDocument', ['chat_id' => $chat, 'document' => $id, 'caption' => $cap]);
    }
}

function agent_photo_rules()
{
    $r = "- تو عکس را می‌بینی و می‌خوانی. [agent-radlog] استثنا: اگر سرویسِ مورد شکایت OpenVPN یا L2TP است (پنل OpenVPN/L2TP، یا مشتری از OpenVPN/L2TP سرویس Magic MAX می‌گوید)، این قاعده اجرا نمی‌شود: همان اول check_connection را بزن، چون لاگ ورود سرور ما دقیق‌تر از عکس است؛ عکس را فقط اگر بعد از راهنمایی باز وصل نشد بخواه. برای بقیهٔ پروتکل‌ها: وقتی مشتری از خطا یا مشکل اتصال می‌گوید (وصل نمی‌شود، ارور می‌دهد، تایم‌اوت، رمز/یوزر را قبول نمی‌کند، لینک اضافه نمی‌شود) و نه عکسی فرستاده و نه متن دقیق خطا را نوشته، قبل از هر کار دیگر (حتی قبل از check_connection) فقط در یک پیام کوتاه بخواه از صفحهٔ برنامه، همان لحظه‌ای که خطا را نشان می‌دهد، اسکرین‌شات بگیرد و همین‌جا بفرستد؛ مثلاً «لطفاً از صفحهٔ خطای برنامه اسکرین‌شات بگیر و همین‌جا بفرست تا دقیق ببینم مشکل چیست.» اگر بلد نیست، یک خط بگو: اندروید معمولاً دکمهٔ پاور + کم‌کردن صدا، آیفون دکمهٔ کناری + زیاد کردن صدا. این را فقط یک بار بخواه؛ اگر گفت نمی‌تواند یا نمی‌خواهد، بدون عکس ادامه بده.\n";
    $r .= "- وقتی عکس رسید: نام برنامه، متن خطا (مثل AUTH_FAILED، timeout، TLS handshake، connection refused، invalid config)، وضعیت اتصال، سرور یا لوکیشن انتخاب‌شده و فیلدهای پرشده را از عکس بخوان. در یک جمله بگو چه دیدی و دقیقاً بر اساس همان راهنمایی کن (اگر یوزر/رمز یا آدرس اشتباه تایپ شده، بگو کدام فیلد اشتباه است). اگر مشکل اتصال است و هنوز تست نکرده‌ای، check_connection را هم صدا بزن. اگر عکس ناخوانا یا بی‌ربط است، بگو و یک بار دیگر بخواه.\n";
    $r .= "- رمز کامل، لینک کامل اشتراک، شماره کارت یا هر اطلاعات حساسی را که در عکس می‌بینی در جوابت تکرار نکن.\n";
    $r .= "- ویدیو، ویس و فایل غیرعکس را نمی‌بینی؛ ربات خودش به مشتری می‌گوید اسکرین‌شات بفرستد.\n";
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
    $r = "- آموزش تصویری: ابزار send_guide عکس‌های قدم‌به‌قدم را مستقیم همین‌جا برای مشتری می‌فرستد. وقتی مشتری آموزش نصب، تنظیم یا «وارد کردن/بارگذاری فیلدها» را می‌خواهد و یکی از این آموزش‌ها به سرویسش می‌خورد، اول send_guide را صدا بزن، بعد کوتاه بگو «آموزش تصویری را فرستادم» و مقدارهای مخصوص خودش را بگو (مثلاً یوزر سرویسش). آموزش را از روی پنل سرویس مشتری و گوشی‌اش انتخاب کن (اسم هر آموزش android یا iphone دارد)؛ اگر از پیامش معلوم نیست، فقط یک سؤال کوتاه بپرس «گوشی‌تان اندروید است یا آیفون؟» و بعد بفرست. سرویس L2TP فقط آیفون است. برای درخواست آموزش هرگز handoff نکن؛ فقط اگر بعد از آموزش باز وصل نشد، طبق قواعد عیب‌یابی کن. آموزش‌های موجود:\n";
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
            'description' => 'Really connect from a filtered server inside Iran WITH THE CUSTOMER\'S OWN ACCOUNT (username) and the protocol they use; returns tunnel up / auth error, exit IP country, and whether sites open. Takes 1-3 minutes. use_test_account=true tests our own test account instead (only to compare after the customer account failed).',
            'input_schema' => ['type' => 'object', 'properties' => [   // [agent-acctprobe]
                'protocol' => ['type' => 'string', 'enum' => ['v2ray', 'wireguard', 'openvpn', 'l2tp']],
                'username' => ['type' => 'string', 'description' => 'the customer service username to test (from [اطلاعات سرویس] or what the customer said)'],
                'customer_disconnected' => ['type' => 'boolean', 'description' => 'true once the customer confirmed the app is disconnected (OpenVPN/L2TP accounts allow one session)'],
                'use_test_account' => ['type' => 'boolean'],
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

function agent_probe($proto, array $sites, $location = '', array $acct = null)
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
    if ($acct) {                     // [agent-acctprobe]
        $args[] = '--cfg-stdin';
        $args[] = '1';
    }
    $ck = agent_dir('probe-cache') . '/' . md5(implode(' ', $args) . '|' . ($acct['key'] ?? '')) . '.json';
    if (is_file($ck) && time() - filemtime($ck) < ($acct ? 120 : 300)) {
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
    $out = agent_run_cmd($line, 260, $acct ? json_encode($acct['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null);
    $j = json_decode(trim($out), true);
    if (!is_array($j) || isset($j['error'])) {
        agent_log('probe failed: ' . substr($out, 0, 200));
        return ['error' => 'probe unavailable'];
    }
    $sum = agent_probe_summary($proto, $j[$map[$proto]] ?? [], $clean);
    file_put_contents($ck, json_encode($sum, JSON_UNESCAPED_UNICODE));
    return $sum;
}

function agent_run_cmd($line, $timeout, $stdin = null)
{
    $spec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    if ($stdin !== null)
        $spec[0] = ['pipe', 'r'];
    $pr = proc_open($line, $spec, $pipes);
    if (!is_resource($pr))
        return '';
    if ($stdin !== null) {           // [agent-acctprobe] the customer's account: stdin only, never argv
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
    }
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

// ------------------------------------------------------------------ [agent-acctprobe] the customer's own account
// check_connection entry: validate the username belongs to this Telegram user, build the account
// payload from the panel, run the probe with it. The model only ever sees credential-free results.
function agent_check_connection($uid, array $in)
{
    $proto = (string) ($in['protocol'] ?? '');
    $sites = (array) ($in['sites'] ?? []);
    $loc = (string) ($in['location'] ?? '');
    if (!empty($in['use_test_account'])) {
        agent_send($uid, '🔎 برای مقایسه، همین مسیر را با اکانت تست خودمان هم امتحان می‌کنم…');
        telegram('sendChatAction', ['chat_id' => $uid, 'action' => 'typing']);
        return ['tested_with' => 'our_test_account'] + agent_probe($proto, $sites, $loc);
    }
    $GLOBALS['agent_login_user'] = '';   // [agent-radlog] set by agent_customer_account for OpenVPN/L2TP logins
    $a = agent_customer_account($uid, (string) ($in['username'] ?? ''), $proto, !empty($in['customer_disconnected']));
    $ll = agent_login_errors($GLOBALS['agent_login_user'], !isset($a['error']) || strpos($a['error'], 'customer_online') === 0);
    if (isset($a['error']))
        return $a + $ll;
    if (!empty($a['fallback'])) {
        agent_send($uid, '🔎 دارم اتصال سرویست را از سمت خودمان تست می‌کنم، حدود یک دقیقه صبر کن…');
        telegram('sendChatAction', ['chat_id' => $uid, 'action' => 'typing']);
        return ['tested_with' => 'our_test_account', 'note' => $a['fallback']] + $ll + agent_probe($proto, $sites, $loc);
    }
    agent_send($uid, '🔎 دارم با همین اکانت تو (' . $a['username'] . ') و پروتکل ' . agent_proto_label($a['proto']) . ' از یک سرور داخل ایران وصل می‌شوم و تست می‌کنم، ۱ تا ۲ دقیقه صبر کن…');
    telegram('sendChatAction', ['chat_id' => $uid, 'action' => 'typing']);
    agent_log("acct probe uid=$uid user={$a['username']} proto={$a['proto']}");
    $r = agent_probe($a['proto'], $sites, $loc, ['payload' => $a['payload'], 'key' => $a['username']]);
    return ['username' => $a['username']] + $ll + $r;
}

// [agent-radlog] rejected logins of this login name (and near-miss spellings) on our Cloudius server
function agent_ago($ts)
{
    $m = max(0, intdiv(time() - (int) $ts, 60));
    return $m < 60 ? $m . ' min ago' : ($m < 2880 ? intdiv($m, 60) . ' h ago' : intdiv($m, 1440) . ' days ago');
}

// [agent-radlog-live] reason of one Cloudius log row (same rules as cronbot/agent_radlog.php); null = accepted
function agent_radlog_reason($resp)
{
    $resp = (string) $resp;
    if (stripos($resp, 'Accept') !== false && stripos($resp, 'Reject') === false)
        return null;
    if (stripos($resp, 'not found') !== false) return 'username_not_found';
    if (stripos($resp, 'Password') !== false) return 'wrong_password';
    if (stripos($resp, 'expired') !== false) return 'expired';
    if (stripos($resp, 'Traffic') !== false) return 'no_traffic';
    return 'other: ' . mb_substr(preg_replace('/^.*Authentication Failed\.?\s*/i', '', $resp), 0, 24);
}

// [agent-radlog-live] read the login server's own log right now (it only holds the newest ~30 minutes)
function agent_live_rejects(array $words)
{
    global $pdo;
    if (!$words || !isset($pdo) || !function_exists('cloudius_api'))
        return null;
    try {
        $panels = $pdo->query("SELECT name_panel, url_panel, username_panel FROM marzban_panel WHERE type = 'cloudius'")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return null;
    }
    if (!$panels)
        return null;
    $hadTimeout = array_key_exists('request_exec_timeout', $GLOBALS);
    $oldTimeout = $GLOBALS['request_exec_timeout'] ?? null;
    $GLOBALS['request_exec_timeout'] = 5000;
    $deadline = microtime(true) + 8;
    $rows = [];
    $newest = 0;
    $ok = false;
    $seen = [];
    try {
        foreach ($panels as $p) {
            $server = strtolower(cloudius_base_url($p['url_panel'])) . '|' . $p['username_panel'];
            if (isset($seen[$server]) || microtime(true) > $deadline)
                continue;                              // OpenVPN + L2TP panels on the same Cloudius
            $seen[$server] = 1;
            $top = cloudius_api($p['name_panel'], '/Log/Fetch', ['PageNo' => 1, 'RowPerPage' => 1]);
            if (empty($top['status']))
                continue;
            $ok = true;
            $newest = max($newest, cloudius_jalali_to_ts((string) ($top['data'][0]['DateTime'] ?? '')));
            foreach ($words as $w) {
                for ($page = 1; $page <= 3 && microtime(true) < $deadline; $page++) {
                    $r = cloudius_api($p['name_panel'], '/Log/Fetch', ['PageNo' => $page, 'RowPerPage' => 300, 'Query' => $w]);
                    $d = (isset($r['data']) && is_array($r['data'])) ? $r['data'] : [];
                    foreach ($d as $h) {
                        $id = $server . '#' . (int) ($h['LogID'] ?? 0);
                        $reason = agent_radlog_reason($h['Response'] ?? '');
                        $ts = cloudius_jalali_to_ts((string) ($h['DateTime'] ?? ''));
                        $user = mb_substr(trim((string) ($h['UserName'] ?? '')), 0, 100);
                        if (isset($rows[$id]) || $reason === null || !$ts || $user === '')
                            continue;
                        $rows[$id] = [$user, $reason, $ts, mb_substr((string) ($h['NASIdentifier'] ?? ''), 0, 40)];
                    }
                    if (count($d) < 300)
                        break;
                }
            }
        }
    } catch (Throwable $e) {
        // live read is best effort; the saved copy still answers
    } finally {
        if ($hadTimeout)
            $GLOBALS['request_exec_timeout'] = $oldTimeout;
        else
            unset($GLOBALS['request_exec_timeout']);
    }
    return $ok ? ['newest' => $newest, 'rows' => array_values($rows)] : null;
}

function agent_login_errors($login, $active = true)
{
    global $pdo;
    $login = trim((string) $login);
    if ($login === '' || !isset($pdo))
        return [];
    $lo = strtolower($login);
    // candidate rows: share a prefix of one of the two longest name parts (catches dropped/extra characters)
    $words = [];
    if (preg_match_all('/[a-z0-9]{4,}/', $lo, $m)) {
        usort($m[0], function ($a, $b) { return strlen($b) - strlen($a); });
        foreach (array_slice($m[0], 0, 2) as $w)
            $words[] = substr($w, 0, max(4, strlen($w) - 2));
    }
    if (!$words)
        $words[] = $lo;
    $words = array_values(array_unique($words));

    $live = agent_live_rejects($words);              // [agent-radlog-live] the login server itself, first
    $s = null;
    try {
        $s = $pdo->query('SELECT MAX(seen) seen, MAX(newest) newest FROM agent_radlog_state')->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $s = null;                                   // collector never ran (agent off / no Cloudius panel)
    }
    $saved = $s && (int) $s['seen'] >= time() - 600;
    if (!$live && !$saved)
        return $s ? ['login_log' => 'unavailable (login server log could not be read); decide from the test result'] : [];
    $savedNewest = $s ? (int) $s['newest'] : 0;
    $since = time() - 24 * 3600;                      // [agent-radlog-24h]
    $agg = [];
    $add = function ($u, $reason, $c, $l, $nas) use (&$agg) {
        $k = $u . "\0" . $reason;
        if (!isset($agg[$k]))
            $agg[$k] = ['username' => $u, 'reason' => $reason, 'c' => 0, 'l' => 0, 'nas' => ''];
        $agg[$k]['c'] += $c;
        if ($l >= $agg[$k]['l']) {
            $agg[$k]['l'] = $l;
            $agg[$k]['nas'] = $nas;
        }
    };
    if ($s) {
        $st = $pdo->prepare("SELECT username, reason, SUM(cnt) c, MAX(last_ts) l,
            SUBSTRING_INDEX(GROUP_CONCAT(nas ORDER BY last_ts DESC), ',', 1) nas
            FROM agent_radlog WHERE last_ts >= ? AND (" . implode(' OR ', array_fill(0, count($words), 'LOWER(username) LIKE ?')) . ")
            GROUP BY username, reason ORDER BY l DESC LIMIT 80");
        $st->execute(array_merge([$since], array_map(fn($w) => '%' . $w . '%', $words)));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r)
            $add((string) $r['username'], $r['reason'], (int) $r['c'], (int) $r['l'], (string) $r['nas']);
    }
    foreach ($live['rows'] ?? [] as $r)               // only rows the saved copy does not have yet
        if ($r[2] > $savedNewest && $r[2] >= $since)
            $add($r[0], $r[1], 1, $r[2], $r[3]);
    usort($agg, fn($a, $b) => $b['l'] - $a['l']);
    $out = [];
    foreach ($agg as $r) {
        $u = (string) $r['username'];
        $ul = strtolower($u);
        $same = $u === $login;
        $near = !$same && ($ul === $lo || ($lo !== '' && strpos($ul, $lo) !== false)
            || (strlen($ul) >= strlen($lo) - 3 && strpos($lo, trim($ul)) !== false) || levenshtein(trim($ul), $lo) <= 2);
        if (!$same && !($near && $r['reason'] === 'username_not_found'))
            continue;                                 // someone else's account: never shown
        $why = ['wrong_password' => 'رمز اشتباه', 'username_not_found' => 'چنین یوزری وجود ندارد', 'expired' => 'سرویس منقضی', 'no_traffic' => 'حجم تمام'][$r['reason']] ?? 'خطای دیگر';
        if (!$same)
            $v = "یوزر را اشتباه تایپ کرده: «{$u}» زده ولی یوزر درست «{$login}» است. دقیقاً فرق این دو را به مشتری بگو و یوزر درست را بده. خودت حلش کن، ارجاع نده.";
        elseif ($r['reason'] === 'wrong_password')
            $v = 'یوزر را درست زده و فقط رمز اشتباه است. بگو رمز را از «سرویس‌های من» کپی کند. خودت حلش کن، ارجاع نده.';
        elseif ($active)
            $v = "سرور ورود یوزرِ درست را رد کرده ({$why}) در حالی که سرویس در ربات فعال است: مشکل سمت ماست، تقصیر مشتری نیست. به مشتری نگو اشتباه زده؛ با handoff به پشتیبانی فنی بده و این دلیل را بنویس.";
        else
            $v = "سرور ورود یوزر را رد کرده ({$why}) و سرویس واقعاً فعال نیست: تمدید یا خرید حجم.";
        $out[] = ['typed_username' => $u, 'reason' => $r['reason'], 'times' => (int) $r['c'],
            'last' => agent_ago($r['l']), 'server' => $r['nas'], 'verdict' => $v];
        if (count($out) >= 8)
            break;
    }
    $covers = max($savedNewest, (int) ($live['newest'] ?? 0));
    return ['login_log' => ['login_username' => $login,
        'source' => 'login server log read live just now' . ($saved ? ' + our saved copy' : ' (saved copy not available)') . ', last 24 hours; only REJECTED logins are recorded (successful ones are not)',
        'log_covers_until' => ($covers ? agent_ago($covers) : 'unknown') . ' (the login server writes this log about 30 minutes late; a reject newer than this is not visible yet)',
        'rejects' => $out ?: 'none',
        'verdict' => $out ? 'جواب را بر اساس verdict جدیدترین ردیف بده (last).' : 'در ۲۴ ساعت اخیر هیچ ورود ناموفقی با این یوزر یا شبیهش به سرور ما نرسیده (به جز ۳۰ دقیقهٔ آخر که هنوز در لاگ نیامده). پس مشکل احتمالاً یوزر/رمز نیست؛ از روی نتیجهٔ تست راهنمایی کن و نگو یوزر یا رمز اشتباه است.',
        'reply_language' => 'Persian only: answer the customer in simple Persian (فارسی), never in English']];
}
function agent_proto_label($p)
{
    return ['v2ray' => 'V2Ray', 'wireguard' => 'WireGuard', 'openvpn' => 'OpenVPN', 'l2tp' => 'L2TP'][$p] ?? $p;
}

// -> ['username','proto','payload'] | ['error'=>..] | ['fallback'=>note]
function agent_customer_account($uid, $username, $proto, $disconnected)
{
    global $pdo, $ManagePanel;
    $in = implode(',', array_fill(0, count(AGENT_ACTIVE_STATUSES), '?'));
    $st = $pdo->prepare("SELECT username, Service_location FROM invoice WHERE id_user = ? AND Status IN ($in) ORDER BY time_sell DESC");
    $st->execute(array_merge([$uid], AGENT_ACTIVE_STATUSES));
    $mine = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$mine)
        return ['error' => 'no active service in this account: do not test, say technical support is only for active services of this account'];
    $username = strtolower(trim(preg_replace('/\s+/u', '', $username)));
    $row = null;
    foreach ($mine as $m) {
        if ($username !== '' && strtolower($m['username']) === $username)
            $row = $m;
    }
    if (!$row && $username === '' && count($mine) === 1)
        $row = $mine[0];
    if (!$row) {
        $list = array_map(function ($m) {
            return $m['username'] . ' (' . $m['Service_location'] . ')';
        }, array_slice($mine, 0, 8));
        return ['error' => ($username === '' ? 'ask which service/username has the problem' : 'this username is not an active service of this customer; ask again') . '. Active services: ' . implode(', ', $list)];
    }
    $user = $row['username'];
    $panel = select('marzban_panel', '*', 'name_panel', $row['Service_location'], 'select');
    if (!is_array($panel))
        return ['fallback' => 'this service has no panel connected to the bot, so it was tested with our test account (not the customer\'s)'];
    if (!isset($ManagePanel) && class_exists('ManagePanel'))
        $ManagePanel = new ManagePanel();
    $d = $ManagePanel->DataUser($row['Service_location'], $user);
    if (!is_array($d) || ($d['status'] ?? '') === 'Unsuccessful')
        return ['error' => 'could not read this service from the panel (' . substr((string) ($d['msg'] ?? 'no answer'), 0, 60) . '); do not conclude an outage from this alone'];
    $status = strtolower((string) ($d['status'] ?? ''));
    if (in_array($status, ['expired', 'limited', 'disabled', 'deactive', 'inactive', 'false', '0'], true) || $status === '')
        return ['error' => 'service is not active on the panel (status: ' . ($status ?: 'off') . '); no test needed: tell the customer (renew / contact support)'];
    if (!empty($d['expire']) && $d['expire'] < time())
        return ['error' => 'service expired; no test needed: tell the customer to renew'];
    if (!empty($d['data_limit']) && ($d['used_traffic'] ?? 0) >= $d['data_limit'])
        return ['error' => 'service volume is used up; no test needed: tell the customer to buy extra volume or renew'];

    $type = (string) $panel['type'];
    if ($type === 'cloudius')
        $GLOBALS['agent_login_user'] = (string) ($d['username'] ?? $user);   // [agent-radlog]
    if ($type === 'cloudius') {
        $def = preg_match('/l2tp/i', $row['Service_location']) ? 'l2tp' : 'openvpn';
        $proto = in_array($proto, ['openvpn', 'l2tp'], true) ? $proto : $def;
        if (($d['online_at'] ?? null) === 'online' && !$disconnected)
            return ['error' => 'customer_online: this account allows one connection and it is connected right now. Ask the customer to disconnect the app, then call again with customer_disconnected=true'];
        $pass = trim((string) ($d['subscription_url'] ?? ''));
        if ($pass === '')
            return ['fallback' => 'no password stored for this account; tested with our test account'];
        $ppp = ['username' => (string) ($d['username'] ?? $user), 'password' => $pass];
        if ($proto === 'l2tp') {
            $l = agent_l2tp_server();
            if (!$l)
                return ['fallback' => 'L2TP server/secret unknown to the bot; tested with our test account'];
            return ['username' => $user, 'proto' => 'l2tp', 'payload' => ['ppp' => $ppp + $l]];
        }
        $files = agent_ovpn_files();
        if (!$files)
            return ['fallback' => 'no OpenVPN server files in the bot help section; tested with our test account'];
        return ['username' => $user, 'proto' => 'openvpn', 'payload' => ['ppp' => $ppp, 'ovpn' => $files]];
    }
    if ($type === 'drproxy' && is_file(dirname(__DIR__) . '/panels/Api/drproxy.php')) {
        require_once dirname(__DIR__) . '/panels/Api/drproxy.php';
        $r = drproxy_call($panel, 'GET', '/users/' . rawurlencode(strtolower($user)));
        $u = $r['ok'] ? ($r['obj']['user'] ?? []) : [];
        if (!$u)
            return ['error' => 'could not read this service from the panel; do not conclude an outage from this alone'];
        $sub = (string) ($u['sub_url'] ?? '');
        $proto = in_array($proto, ['v2ray', 'wireguard', 'openvpn', 'l2tp'], true) ? $proto : 'v2ray';
        $svc = array_map('strtolower', (array) ($u['services'] ?? []));
        $need = ['v2ray' => 'v2ray', 'wireguard' => 'wg', 'openvpn' => 'openvpn', 'l2tp' => 'l2tp'][$proto];
        if ($svc && !in_array($need, $svc, true))
            return ['error' => 'this service does not include ' . agent_proto_label($proto) . ' (it has: ' . implode(', ', $svc) . '); guide the customer to a protocol the service has'];
        $ppp = (array) ($u['ppp'] ?? []);
        if (in_array($proto, ['openvpn', 'l2tp'], true) && !empty($ppp['username']))
            $GLOBALS['agent_login_user'] = (string) $ppp['username'];   // [agent-radlog] Magic MAX OpenVPN/L2TP login lives on Cloudius
        if ($proto === 'v2ray')
            return ['username' => $user, 'proto' => 'v2ray', 'payload' => ['sub_url' => $sub, 'links' => agent_fetch_links($sub)]];
        if ($proto === 'l2tp')
            return empty($ppp['username']) ? ['error' => 'no L2TP login on this service'] : ['username' => $user, 'proto' => 'l2tp', 'payload' => ['ppp' => $ppp]];
        // the DrProxy API gives no WireGuard/OpenVPN client file to the bot: compare with our test account
        return ['fallback' => 'the bot cannot fetch this service\'s ' . agent_proto_label($proto) . ' file, so it was tested with our test account (not the customer\'s)'];
    }
    // Marzban / x-ui / other V2Ray panels: the subscription link (+ any direct links the panel gave)
    $sub = (string) ($d['subscription_url'] ?? '');
    $links = array_values(array_filter((array) ($d['links'] ?? []), function ($l) {
        return is_string($l) && preg_match('#^(vless|vmess|trojan)://#', $l);
    }));
    if (!preg_match('#^https?://#', $sub) && !$links)
        return ['fallback' => 'no subscription link for this service; tested with our test account'];
    if (!$links && preg_match('#^https?://#', $sub))
        $links = agent_fetch_links($sub);
    return ['username' => $user, 'proto' => 'v2ray', 'payload' => ['sub_url' => preg_match('#^https?://#', $sub) ? $sub : '', 'links' => $links]];
}

function agent_http_get($url, $ua = 'v2rayNG/1.8.19', $timeout = 20)
{
    if (!preg_match('#^https?://#', $url))
        return '';
    for ($i = 0; $i < 2; $i++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => $timeout, CURLOPT_USERAGENT => $ua]);
        $b = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (is_string($b) && $code == 200 && $b !== '')
            return $b;
    }
    return '';
}

// V2Ray links of a subscription as the bot sees it (the probe also fetches it itself from Iran).
function agent_fetch_links($url)
{
    $b = agent_http_get($url);
    if ($b === '')
        return [];
    $t = base64_decode(preg_replace('/\s+/', '', $b), true);
    $t = ($t !== false && preg_match('#(vless|vmess|trojan)://#', $t)) ? $t : $b;
    preg_match_all('#^(?:vless|vmess|trojan)://\S+#m', $t, $m);
    return array_slice($m[0], 0, 40);
}

// OpenVPN server files the bot hands out in its help section (Telegram documents), cached for a day.
function agent_ovpn_files()
{
    global $pdo, $APIKEY;
    $out = [];
    $st = $pdo->query("SELECT name_os, Media_os FROM help WHERE type_Media_os = 'document' AND (LOWER(category) LIKE '%openvpn%' OR LOWER(name_os) LIKE '%openvpn%') ORDER BY name_os");
    foreach (($st ? $st->fetchAll(PDO::FETCH_NUM) : []) as $row) {
        [$name, $fid] = $row;
        $ck = agent_dir('ovpn-cache') . '/' . md5($fid) . '.ovpn';
        if (!is_file($ck) || time() - filemtime($ck) > 86400) {
            $g = telegram('getFile', ['file_id' => $fid]);
            $p = $g['result']['file_path'] ?? '';
            if (!$p || !preg_match('/\.ovpn$/i', $p) || ($g['result']['file_size'] ?? 0) > 200000)
                continue;
            $b = agent_http_get('https://api.telegram.org/file/bot' . $APIKEY . '/' . $p, 'Mirza', 20);
            if (strpos($b, 'remote') === false)
                continue;
            file_put_contents($ck, $b);
            @chmod($ck, 0600);
        }
        $out[] = ['name' => mb_substr(preg_replace('/^.*?(S\d+)\s*$/u', '$1', trim($name)), 0, 40), 'text' => (string) file_get_contents($ck)];
        if (count($out) >= 6)
            break;
    }
    return $out;
}

// L2TP server + pre-shared key: agent config first, else the public values in the after-purchase text.
function agent_l2tp_server()
{
    $c = agent_cfg()['l2tp'] ?? [];
    if (!empty($c['server']) && !empty($c['psk']))
        return ['server' => $c['server'], 'l2tp_psk' => $c['psk']];
    global $pdo;
    $t = (string) $pdo->query("SELECT text FROM textbot WHERE id_text = 'textafterpayibsng'")->fetchColumn();
    if (preg_match('/L2TP[^\n=]*=\s*([a-z0-9.-]+\.[a-z]{2,})/iu', $t, $s) && preg_match('/Secret[^\n=]*=\s*(\S+)/iu', $t, $k))
        return ['server' => $s[1], 'l2tp_psk' => $k[1]];
    return null;
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
    $extra = [];                     // [agent-acctprobe]
    if (!empty($r['account']))
        $extra['tested_with'] = 'customer_account';
    if (!empty($r['server']))
        $extra['server'] = $r['server'];
    if (!empty($r['servers_tried']))
        $extra['servers_tried'] = $r['servers_tried'];
    if (isset($r['sub_fetch']))
        $extra['subscription_update'] = !empty($r['sub_fetch']['ok']) ? 'ok' : 'failed (' . ($r['sub_fetch']['err'] ?? ('HTTP ' . ($r['sub_fetch']['code'] ?? 0))) . ')';
    if ($proto === 'v2ray') {
        $locs = [];
        foreach (($r['by_location'] ?? []) as $name => $x)
            $locs[$name] = $one($x);
        return ['protocol' => 'v2ray', 'ok' => (bool) ($r['ok'] ?? false), 'up' => (bool) ($r['up'] ?? false),
            'locations_ok' => $r['locations_ok'] ?? 0, 'locations' => $r['locations'] ?? 0, 'by_location' => $locs]
            + (!empty($r['err']) ? ['error' => $r['err']] : []) + (!empty($r['skip']) ? ['error' => 'not testable: ' . $r['skip']] : []) + $extra;
    }
    if (!empty($r['skip']))
        return ['protocol' => $proto, 'error' => 'not testable: ' . $r['skip']] + $extra;
    return ['protocol' => $proto] + $one($r) + $extra;
}

// ------------------------------------------------------------------ escalation
function agent_handoff($uid, array $c, $reason)
{
    global $pdo, $textbotlang;
    $ctx = $c['ctx'];
    $talk = implode("\n— ", array_map('agent_img_label', array_slice($c['user_msgs'] ?? [], -6)));   // [agent-photo]
    $photos = agent_conv_photos($c);
    $note = "\n\n🤖 پشتیبان هوشمند ارجاع داد: " . htmlspecialchars($reason) . "\nپیام‌های مشتری:\n— " . htmlspecialchars($talk);
    if (($ctx['kind'] ?? '') === 'disorder') {
        $tech = trim((string) (agent_cfg()['tech_id'] ?? ''));
        if ($tech !== '') {   // unresolved disorder -> technical support; admins only if that fails
            $r = sendmessage($tech, $ctx['handoff_text'] . $note, $ctx['handoff_kb'] ?? null, 'html');
            if (!empty($r['ok'])) {
                agent_send_photos($tech, $photos);
                return;
            }
            agent_log('tech notify failed: ' . json_encode($r));
        }
        agent_notify_admins($ctx['handoff_text'] . $note, $ctx['handoff_kb'] ?? null, 'html');
        agent_send_photos(agent_fallback_admin(), $photos);
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
    $first = agent_img_label($c['user_msgs'][0] ?? '');
    $tracking = bin2hex(random_bytes(4));
    $time = date('Y/m/d H:i:s');
    $stmt = $pdo->prepare("INSERT IGNORE INTO support_message (Tracking,idsupport,iduser,name_departman,text,time,status) VALUES (?,?,?,?,?,?,?)");
    $stmt->execute([$tracking, $dep['idsupport'], $uid, $dep['name_departman'], $first, $time, 'Unseen']);
    $txt = sprintf($textbotlang['Admin']['reportgroup']['supportMessage'], $uid, $uid, jdate('Y/m/d H:i:s'),
        $ctx['username'] ?? '', $dep['name_departman'], htmlspecialchars($first), '') . $note;
    $kb = json_encode(['inline_keyboard' => [[['text' => $textbotlang['users']['support']['answermessage'], 'callback_data' => 'Responsesupport_' . $tracking]]]]);
    sendmessage($dep['idsupport'], $txt, $kb, 'HTML');
    agent_send_photos($dep['idsupport'], $photos);
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
    $c['messages'][] = ['role' => 'user', 'content' => agent_user_content($text)];   // [agent-photo]
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
                $res = agent_check_connection($uid, $in);   // [agent-acctprobe]
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
    $c['messages'] = agent_strip_images($c['messages'] ?? []);   // [agent-photo]
    agent_json_update(agent_conv_path($uid), function (&$x) use ($c) {
        $inbox = is_array($x) ? ($x['inbox'] ?? []) : [];
        $msgs = is_array($x) ? ($x['user_msgs'] ?? []) : [];
        $x = $c;
        $x['inbox'] = $inbox;           // messages that arrived while we were working
        $x['user_msgs'] = $msgs;
    });
}
