<?php
// [agent-health] Check every service with the agent's TEST account from the Iran probe server.
// Hourly (cronbot/agent_health.php): the admin gets a message only when something breaks or recovers,
// plus one full report a day (10:00). Admins can force a full run with /check or the button in /agent.
// Problems: a protocol or V2Ray location does not connect / opens no sites / exits in Iran, or a V2Ray
// location's flag does not match the country of its exit IP. A failure is retested once before it is reported.
//
// CLI: php agent/health.php run cron|manual [chat_id]   (normally started detached by agent_health_spawn)

const AGENT_HEALTH_PROTOS = ['l2tp' => 'L2TP', 'openvpn' => 'OpenVPN', 'wg' => 'WireGuard', 'v2ray' => 'V2Ray'];
const AGENT_HEALTH_DAILY_HOUR = 10;

function agent_health_spawn($mode, $chat = '')
{
    $php = is_executable('/usr/bin/php') ? '/usr/bin/php' : PHP_BINDIR . '/php';
    $cmd = 'setsid nohup ' . escapeshellarg($php) . ' ' . escapeshellarg(__FILE__) . ' run ' . escapeshellarg($mode)
        . ' ' . escapeshellarg(preg_replace('/[^0-9-]/', '', (string) $chat)) . ' >/dev/null 2>&1 &';
    @exec($cmd);
}

// flag emoji at the start of a location name -> ISO code ('🇩🇰 دانمارک' -> 'DK'); '' when there is none
function agent_health_flag_cc($name)
{
    if (!preg_match('/^\s*([\x{1F1E6}-\x{1F1FF}])([\x{1F1E6}-\x{1F1FF}])/u', (string) $name, $m))
        return '';
    $a = mb_ord($m[1], 'UTF-8') - 0x1F1E6 + 65;
    $b = mb_ord($m[2], 'UTF-8') - 0x1F1E6 + 65;
    return chr($a) . chr($b);
}

function agent_health_flag($cc)
{
    $cc = strtoupper((string) $cc);
    if (!preg_match('/^[A-Z]{2}$/', $cc))
        return '';
    return mb_chr(0x1F1E6 + ord($cc[0]) - 65, 'UTF-8') . mb_chr(0x1F1E6 + ord($cc[1]) - 65, 'UTF-8');
}

// one probe result (a protocol, or one V2Ray location) -> [bad, Persian detail]
function agent_health_judge(array $r)
{
    if (!empty($r['skip']))
        return [true, 'تست نشد: ' . $r['skip']];
    if (!empty($r['err']) && empty($r['up']))
        return [true, 'وصل نشد (' . mb_substr((string) $r['err'], 0, 60) . ')'];
    if (empty($r['up']))
        return [true, 'وصل نشد'];
    $cc = (string) ($r['country'] ?? '');
    $kbps = (int) ($r['download']['kbps'] ?? 0);
    $speed = $kbps > 0 ? round($kbps / 1000, 1) . ' Mbps' : 'بدون دانلود';
    if (!empty($r['iran_exit']))
        return [true, 'وصل شد ولی خروجی ایران است' . ($cc ? " ($cc)" : '')];
    if (!empty($r['ok']))
        return [false, 'سالم، خروجی ' . agent_health_flag($cc) . " {$cc}، {$speed}"];
    $bad = [];
    foreach (($r['sites'] ?? []) as $h => $s)
        if (empty($s['ok']) && empty($s['blocked_by_exit_country']))
            $bad[] = preg_replace('/^www\./', '', $h);
    if ((int) ($r['download']['bytes'] ?? 0) < 400000)
        $bad[] = 'دانلود';
    return [true, 'وصل شد ولی باز نشد: ' . implode('، ', $bad ?: ['?']) . " (خروجی {$cc}، {$speed})"];
}

// raw probe outputs per protocol -> items [key => [bad, label, detail, group]]; null = probe server unreachable
function agent_health_eval(array $raw)
{
    $items = [];
    $reached = false;
    foreach (AGENT_HEALTH_PROTOS as $p => $label) {
        $d = $raw[$p] ?? null;
        if (!is_array($d) || isset($d['error'])) {
            if (is_array($d) && isset($d['error']) && $d['error'] !== 'probe unavailable')
                $reached = true;
            continue;                                  // this protocol's run failed: keep its previous state
        }
        $reached = true;
        $r = $d[$p] ?? null;
        if (!is_array($r))
            continue;
        if ($p !== 'v2ray' || empty($r['by_location'])) {
            [$bad, $det] = agent_health_judge($r);
            $items[$p] = [$bad, $label, $det, 'proto'];
            continue;
        }
        foreach ($r['by_location'] as $name => $x) {
            $x = is_array($x) ? $x : [];
            [$bad, $det] = agent_health_judge($x);
            $items['v2ray:' . $name] = [$bad, 'V2Ray ' . trim($name), $det, 'loc'];
            $want = agent_health_flag_cc($name);
            $got = array_values(array_unique(array_filter(array_map(fn($e) => strtoupper((string) ($e['cc'] ?? '')), $x['exit'] ?? []), fn($c) => preg_match('/^[A-Z]{2}$/', $c))));
            if ($want === '' || !$got)
                continue;                              // no flag in the name or exit unknown: nothing to compare
            $mis = !in_array($want, $got, true);
            $items['geo:' . $name] = [$mis, 'V2Ray ' . trim($name), $mis
                ? "اسم لوکیشن $want است ولی IP خروجی " . agent_health_flag($got[0]) . ' ' . $got[0] . ' است'
                : 'کشور خروجی با اسم یکی است', 'geo'];
        }
    }
    return $reached ? $items : null;
}

// previous state + new items -> [state, new problems, recovered, still bad, added locations, removed locations]
function agent_health_diff(array $old, array $items, $now)
{
    $state = [];
    $new = $fixed = $still = $added = [];
    foreach ($items as $k => [$bad, $label, $det, $grp]) {
        $was = $old[$k] ?? null;
        $state[$k] = ['bad' => $bad, 'label' => $label, 'detail' => $det, 'grp' => $grp,
            'since' => ($was && $was['bad'] === $bad) ? $was['since'] : $now];
        if ($bad && (!$was || !$was['bad']))
            $new[] = $k;
        elseif ($bad)
            $still[] = $k;
        elseif ($was && $was['bad'])
            $fixed[] = $k;
        if (!$was && $old && $grp === 'loc')
            $added[] = $label;
    }
    $removed = [];
    $v2rayRan = (bool) array_filter($items, fn($i) => $i[3] === 'loc');
    foreach ($old as $k => $v) {
        if (isset($state[$k]))
            continue;
        $isLoc = strpos($k, 'v2ray:') === 0;
        $isGeo = strpos($k, 'geo:') === 0;
        if ($isLoc && $v2rayRan)
            $removed[] = $v['label'];                  // V2Ray ran but this location is no longer in the subscription
        elseif ($isGeo && $v2rayRan && !isset($items['v2ray:' . substr($k, 4)]))
            continue;                                  // its location was removed
        else
            $state[$k] = $v;                           // not measured this time (run failed / exit unknown): keep last state
    }
    return [$state, $new, $fixed, $still, $added, $removed];
}

function agent_health_ago($since, $now)
{
    $m = max(0, intdiv($now - (int) $since, 60));
    return $m < 60 ? "$m دقیقه" : ($m < 1440 ? intdiv($m, 60) . ' ساعت' : intdiv($m, 1440) . ' روز');
}

function agent_health_line(array $s, $now = null, $withSince = false)
{
    return '• ' . htmlspecialchars($s['label']) . ': ' . htmlspecialchars($s['detail'])
        . ($withSince && $now ? ' <i>(از ' . agent_health_ago($s['since'], $now) . ' پیش)</i>' : '');
}

function agent_health_message($kind, array $state, array $new, array $fixed, array $still, array $added, array $removed, $now, $probeDown, $took)
{
    $when = function_exists('jdate') ? jdate('Y/m/d H:i', $now) : date('Y/m/d H:i', $now);
    $t = '🩺 <b>' . ($kind === 'alert' ? 'تغییر وضعیت سرویس‌ها' : 'گزارش کامل سرویس‌ها') . "</b>\n"
        . "<i>تست واقعی از سرور ایران با اکانت تست — $when</i>\n";
    if ($probeDown)
        return $t . "\n⚠️ سرور تست ایران در دسترس نبود؛ هیچ سرویسی تست نشد. (این خرابی سرویس مشتری‌ها نیست.)";
    if ($new && $kind === 'alert') {   // the full report lists every problem in its own section below
        $t .= "\n🔴 <b>مشکل جدید</b>\n";
        foreach ($new as $k)
            $t .= agent_health_line($state[$k]) . "\n";
    }
    if ($fixed) {
        $t .= "\n🟢 <b>برطرف شد</b>\n";
        foreach ($fixed as $k)
            $t .= '• ' . htmlspecialchars($state[$k]['label']) . ($state[$k]['grp'] === 'geo' ? ': کشور خروجی درست شد' : ': دوباره سالم') . "\n";
    }
    foreach ([[$added, '➕ لوکیشن جدید در ساب'], [$removed, '➖ لوکیشن حذف‌شده از ساب']] as [$list, $title])
        if ($list)
            $t .= "\n$title: " . htmlspecialchars(implode('، ', $list)) . "\n";
    if ($still && $kind === 'alert') {
        $t .= "\n⚠️ <b>هنوز مشکل دارد</b>\n";
        foreach ($still as $k)
            $t .= agent_health_line($state[$k], $now, true) . "\n";
    }
    if ($kind === 'alert')
        return rtrim($t);

    // full report
    $t .= "\n<b>پروتکل‌ها</b>\n";
    foreach (AGENT_HEALTH_PROTOS as $p => $label) {
        if ($p === 'v2ray')
            continue;
        $s = $state[$p] ?? null;
        $t .= ($s ? ($s['bad'] ? '🔴 ' : '🟢 ') . "$label: " . htmlspecialchars($s['detail']) : "⚪️ $label: تست نشد") . "\n";
    }
    $locs = array_filter($state, fn($s) => $s['grp'] === 'loc');
    $badLocs = array_filter($locs, fn($s) => $s['bad']);
    $geo = array_filter($state, fn($s) => $s['grp'] === 'geo' && $s['bad']);
    $t .= "\n<b>V2Ray</b>: " . (count($locs) - count($badLocs)) . ' از ' . count($locs) . " لوکیشن سالم\n";
    foreach ($badLocs as $s)
        $t .= '🔴 ' . htmlspecialchars($s['label']) . ': ' . htmlspecialchars($s['detail']) . "\n";
    if (isset($state['v2ray']) && !$locs)
        $t .= ($state['v2ray']['bad'] ? '🔴 ' : '🟢 ') . htmlspecialchars($state['v2ray']['detail']) . "\n";
    $t .= "\n<b>کشور خروجی لوکیشن‌ها</b>: " . ($geo ? count($geo) . " لوکیشن با اسمش نمی‌خواند\n" : "همه با اسمشان یکی است ✅\n");
    foreach ($geo as $s)
        $t .= '🟠 ' . htmlspecialchars($s['label']) . ': ' . htmlspecialchars($s['detail']) . "\n";
    $all = array_filter($state, fn($s) => $s['bad']);
    $t .= "\n" . ($all ? '❗️ ' . count($all) . ' مشکل' : '✅ همه‌چیز سالم است') . ($took ? " — تست $took ثانیه طول کشید" : '');
    return $t;
}

// run the forced-command probe for one protocol with the TEST account; never cached
function agent_health_probe($proto)
{
    $map = ['v2ray' => 'v2ray', 'wg' => 'wg', 'openvpn' => 'openvpn', 'l2tp' => 'l2tp'];
    $p = agent_cfg()['probe'];
    $cmd = ['ssh', '-F', '/dev/null', '-i', AGENT_DIR . '/probe_key', '-p', (string) intval($p['port']),
        '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', '-o', 'StrictHostKeyChecking=accept-new',
        '-o', 'UserKnownHostsFile=' . AGENT_DIR . '/known_hosts', $p['user'] . '@' . $p['host'], '--', $map[$proto]];
    $out = agent_run_cmd(implode(' ', array_map('escapeshellarg', $cmd)), 250);
    $j = json_decode(trim((string) $out), true);
    return is_array($j) ? $j : ['error' => 'probe unavailable'];
}

function agent_health_run_all()
{
    $raw = [];
    foreach (array_keys(AGENT_HEALTH_PROTOS) as $p)
        $raw[$p] = agent_health_probe($p);
    return $raw;
}

// retest once whatever failed; an item is bad only if it failed both times (geo mismatches are not retested)
function agent_health_confirm(array $raw, array $items, callable $probe)
{
    $redo = [];
    foreach ($items as $k => $i)
        if ($i[0] && $i[3] !== 'geo')
            $redo[$i[3] === 'loc' ? 'v2ray' : $k] = true;
    if (!$redo)
        return $items;
    $raw2 = $raw;
    foreach (array_keys($redo) as $p)
        $raw2[$p] = $probe($p);
    $again = agent_health_eval($raw2) ?? [];
    foreach ($items as $k => $i)
        if ($i[0] && $i[3] !== 'geo' && isset($again[$k]) && !$again[$k][0])
            $items[$k] = $again[$k];                  // passed on retest: a blip, not a problem
    return $items;
}

function agent_health_main($mode, $chat)
{
    $lock = fopen(agent_dir() . '/health.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        if ($mode === 'manual' && $chat !== '')
            agent_json_update(agent_dir() . '/health_waiters.json', function (&$w) use ($chat) {
                $w = is_array($w) ? $w : [];
                $w[$chat] = time();
            });
        return;
    }
    $t0 = time();
    $raw = agent_health_run_all();
    $items = agent_health_eval($raw);
    $probeDown = $items === null;
    if (!$probeDown)
        $items = agent_health_confirm($raw, $items, 'agent_health_probe');
    $now = time();
    $f = agent_dir() . '/health.json';
    $st = json_decode((string) @file_get_contents($f), true);
    $st = is_array($st) ? $st : ['items' => [], 'daily' => '', 'probe_down' => false];
    $old = $st['items'] ?? [];
    if ($probeDown)
        [$state, $new, $fixed, $still, $added, $removed] = [$old, [], [], [], [], []];
    else
        [$state, $new, $fixed, $still, $added, $removed] = agent_health_diff($old, $items, $now);
    $first = !$old && !$probeDown;
    $daily = $mode === 'cron' && (int) date('G', $now) >= AGENT_HEALTH_DAILY_HOUR && ($st['daily'] ?? '') !== date('Y-m-d', $now);
    $took = $now - $t0;
    $sent = [];
    $waiters = [];
    agent_json_update(agent_dir() . '/health_waiters.json', function (&$w) use (&$waiters) {
        $waiters = is_array($w) ? array_keys($w) : [];
        $w = [];
    });
    $full = agent_health_message('full', $state, $new, $fixed, $still, $added, $removed, $now, $probeDown, $took);
    foreach (array_unique(array_filter(array_merge($mode === 'manual' ? [$chat] : [], $waiters))) as $to) {
        sendmessage($to, mb_substr($full, 0, 4000), null, 'HTML');
        $sent[] = (string) $to;
    }
    $admin = trim((string) (agent_cfg()['admin_id'] ?? ''));
    if ($daily || $first) {
        if (!in_array($admin, $sent, true))
            agent_notify_admins(mb_substr($full, 0, 4000), null, 'HTML');
        $st['daily'] = date('Y-m-d', $now);
    } elseif ($new || $fixed || $added || $removed || ($probeDown && empty($st['probe_down']))) {
        if (!in_array($admin, $sent, true))
            agent_notify_admins(mb_substr(agent_health_message('alert', $state, $new, $fixed, $still, $added, $removed, $now, $probeDown, $took), 0, 4000), null, 'HTML');
    }
    $st['items'] = $state;
    $st['probe_down'] = $probeDown;
    $st['last_run'] = $now;
    $st['took'] = $took;
    file_put_contents($f . '.tmp', json_encode($st, JSON_UNESCAPED_UNICODE));
    rename($f . '.tmp', $f);
    agent_log('health ' . $mode . ': ' . ($probeDown ? 'probe down' : count(array_filter($state, fn($s) => $s['bad'])) . ' bad, new ' . count($new) . ', fixed ' . count($fixed)) . ", {$took}s");
    flock($lock, LOCK_UN);
}

if (PHP_SAPI === 'cli' && isset($argv[1]) && $argv[1] === 'run' && realpath($argv[0] ?? '') === __FILE__) {
    chdir(dirname(__DIR__));
    date_default_timezone_set('Asia/Tehran');
    require_once dirname(__DIR__) . '/config.php';
    require_once dirname(__DIR__) . '/botapi.php';
    require_once dirname(__DIR__) . '/function.php';
    if (is_file(dirname(__DIR__) . '/jdf.php'))
        require_once dirname(__DIR__) . '/jdf.php';
    require_once __DIR__ . '/agent.php';
    if (!agent_probe_configured())
        exit(0);
    $mode = ($argv[2] ?? 'cron') === 'manual' ? 'manual' : 'cron';
    agent_health_main($mode, preg_replace('/[^0-9-]/', '', (string) ($argv[3] ?? '')));
}
