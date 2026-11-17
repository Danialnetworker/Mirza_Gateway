<?php
// [agent-readonly] Read-only tools so the agent answers account questions itself instead of referring them:
//   my_services    every service of THIS customer with live volume / days / online state (which one do they mean?)
//   usage_report   one service: live totals + (Cloudius: OpenVPN/L2TP) the session history from the login server,
//                  summed per day, with the heaviest sessions and how many different networks used the account
//   my_payments    wallet balance, last payments with their state, last purchases and renewals
// Nothing here writes to a panel or the database. Only services whose invoice belongs to the asking customer are
// read; passwords, links and full IPs never leave these functions.

const AGENT_RO_DEAD = ['unpaid', 'removeTime', 'removebyadmin', 'removebyuser', 'Unsuccessful'];

function agent_ro_gb($bytes)
{
    $b = (float) $bytes;
    if ($b >= 1073741824)
        return round($b / 1073741824, 2) . ' GB';
    return round($b / 1048576, $b >= 10485760 ? 0 : 1) . ' MB';
}

function agent_ro_rows($uid, $limit = 20)
{
    global $pdo;
    $in = implode(',', array_fill(0, count(AGENT_RO_DEAD), '?'));
    $st = $pdo->prepare("SELECT id_invoice, username, Service_location, name_product, time_sell, Status FROM invoice
        WHERE id_user = ? AND Status NOT IN ($in) ORDER BY time_sell DESC LIMIT " . intval($limit));
    $st->execute(array_merge([$uid], AGENT_RO_DEAD));
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function agent_ro_protocol($panel)
{
    $type = strtolower((string) ($panel['type'] ?? ''));
    $name = (string) ($panel['name_panel'] ?? '');
    if ($type === 'cloudius' || $type === 'ibsng')
        return stripos($name, 'l2tp') !== false ? 'L2TP' : 'OpenVPN';
    if ($type === 'drproxy')
        return 'all protocols (V2Ray, WireGuard, OpenVPN, L2TP)';
    if (strpos($type, 'wg') !== false || $type === 'mikrotik')
        return 'WireGuard';
    return 'V2Ray';
}

// live numbers of one service through the bot's own panel layer
function agent_ro_live(array $row, &$raw = null)
{
    global $ManagePanel;
    if (!isset($ManagePanel) && class_exists('ManagePanel'))
        $ManagePanel = new ManagePanel();
    $panel = select('marzban_panel', '*', 'name_panel', $row['Service_location'], 'select');
    $o = ['username' => $row['username'], 'plan' => trim((string) $row['name_product']), 'panel' => $row['Service_location'],
        'protocol' => agent_ro_protocol(is_array($panel) ? $panel : []), 'bought' => agent_ago((int) $row['time_sell'])];
    $d = isset($ManagePanel) ? $ManagePanel->DataUser($row['Service_location'], $row['username']) : null;
    if (is_array($d) && preg_match('/not\s*found|یافت نشد|پیدا نشد|not exist/iu', (string) ($d['msg'] ?? '')))
        return $o + ['state' => 'not on the panel any more (deleted or expired long ago)'];
    if (!is_array($d) || ($d['status'] ?? '') === 'Unsuccessful')
        return $o + ['state' => 'panel did not answer just now'];
    $limit = (float) ($d['data_limit'] ?? 0);
    $used = (float) ($d['used_traffic'] ?? 0);
    $raw = ['limit' => $limit, 'used' => $used];
    $o['state'] = (string) ($d['status'] ?? '?');
    $o['total'] = $limit > 0 ? agent_ro_gb($limit) : 'unlimited';
    $o['used'] = agent_ro_gb($used);
    if ($limit > 0) {
        $o['remaining'] = agent_ro_gb(max(0, $limit - $used));
        $o['volume_finished'] = $limit - $used <= 1048576;
    }
    if (!empty($d['expire'])) {
        $days = floor(($d['expire'] - time()) / 86400);
        $o['days_left'] = (int) $days;
        $o['time_finished'] = $d['expire'] < time();
    }
    $on = $d['online_at'] ?? null;
    $o['connection'] = $on === 'online' ? 'online now' : ($on ? 'offline now' : 'never connected');
    return $o;
}

function agent_tool_my_services($uid)
{
    $rows = agent_ro_rows($uid, 21);
    if (!$rows)
        return ['services' => [], 'note' => 'this account has no service'];
    $more = count($rows) > 20;
    $out = [];
    $deadline = microtime(true) + 25;
    foreach (array_slice($rows, 0, 20) as $r)
        $out[] = microtime(true) < $deadline ? agent_ro_live($r) : ['username' => $r['username'], 'plan' => $r['name_product'], 'panel' => $r['Service_location'], 'state' => 'not read (too many services)'];
    $fin = array_values(array_filter($out, fn($s) => !empty($s['volume_finished']) || !empty($s['time_finished'])));
    return ['count' => count($out), 'services' => $out,
        'finished_services' => array_map(fn($s) => $s['username'], $fin),
        'note' => ($more ? 'only the 20 newest are listed. ' : '') . 'Newest first. If the customer did not say which one, match their words with these numbers (e.g. «حجمم تمام شد» = the one in finished_services); when exactly one fits, continue with it and name it; otherwise ask ONE short question listing the candidates as «یوزر — پلن — باقی‌مانده».'];
}

// duration "HH:MM:SS" or "D.HH:MM:SS" -> seconds
function agent_ro_secs($s)
{
    if (!preg_match('/^(?:(\d+)\.)?(\d+):(\d+):(\d+)/', trim((string) $s), $m))
        return 0;
    return (int) $m[1] * 86400 + (int) $m[2] * 3600 + (int) $m[3] * 60 + (int) $m[4];
}

function agent_ro_dur($sec)
{
    $sec = (int) $sec;
    return $sec >= 3600 ? intdiv($sec, 3600) . ' h ' . intdiv($sec % 3600, 60) . ' min' : max(1, intdiv($sec, 60)) . ' min';
}

// when the current volume period of this service began: purchase, or the newest renewal (renewals reset the volume)
function agent_ro_period_start(array $row)
{
    global $pdo;
    $t = (int) $row['time_sell'];
    try {
        $st = $pdo->prepare("SELECT time FROM service_other WHERE username = ? AND type IN ('extend_user', 'extend_user_by_admin') ORDER BY id DESC LIMIT 1");
        $st->execute([$row['username']]);
        $x = strtotime(str_replace('/', '-', (string) $st->fetchColumn()));
        if ($x && $x > $t)
            return [$x, 'last renewal'];
    } catch (Throwable $e) {
    }
    return [$t, 'purchase'];
}

// Cloudius session history of one user, newest first, back to $since (max 15 pages of 300, 20 s)
function agent_ro_cloudius_sessions($panelName, $username, $since)
{
    $id = cloudius_user_id($panelName, $username);
    if ($id <= 0)
        return null;
    $old = $GLOBALS['request_exec_timeout'] ?? null;
    $had = array_key_exists('request_exec_timeout', $GLOBALS);
    $GLOBALS['request_exec_timeout'] = 8000;
    $rows = [];
    $complete = false;
    try {
        $stop = microtime(true) + 20;
        for ($page = 1; $page <= 15 && microtime(true) < $stop; $page++) {
            $r = cloudius_api($panelName, '/History/Fetch', ['UserID' => (string) $id, 'PageNo' => $page, 'RowPerPage' => 300]);
            if (empty($r['status']))
                return $rows ? ['rows' => $rows, 'complete' => false] : null;
            $d = is_array($r['data'] ?? null) ? $r['data'] : [];
            foreach ($d as $h) {
                $ts = cloudius_jalali_to_ts((string) ($h['StartTime'] ?? ''));
                if (!$ts)
                    continue;
                if ($ts < $since) {
                    $complete = true;
                    break 2;
                }
                $up = cloudius_traffic_to_bytes($h['CurrentUpload'] ?? 0);
                $down = cloudius_traffic_to_bytes($h['CurrentDownload'] ?? 0);
                $rows[] = ['start' => (string) $h['StartTime'], 'ts' => $ts, 'sec' => agent_ro_secs($h['Duration'] ?? ''),
                    'total' => max(0, $up) + max(0, $down), 'sid' => (string) ($h['SessionID'] ?? '') . '|' . (string) ($h['RasTitle'] ?? ''),
                    'net' => (string) ($h['CallingStationID'] ?? ''),
                    // volume left after this row; interim rows (DC-After) do not carry it
                    'rem' => (string) ($h['LastAction'] ?? '') === 'DC-After' || !isset($h['TrafficRemained']) ? null : cloudius_traffic_to_bytes($h['TrafficRemained'])];
            }
            if (count($d) < 300) {
                $complete = true;
                break;
            }
        }
    } finally {
        if ($had)
            $GLOBALS['request_exec_timeout'] = $old;
        else
            unset($GLOBALS['request_exec_timeout']);
    }
    return ['rows' => agent_ro_segments($rows), 'complete' => $complete];
}

// Make the per-row usage exact. Rows that carry «volume left» are anchors: what was used between two anchors is the
// drop of that number, which is what the panel itself charged. That amount is shared among the rows in between by
// their estimated bytes, so the days always add up to the panel's own «used». Rows newest first in and out.
// Returns [rows, bytes used after the newest row (a connection still open now)].
function agent_ro_calibrate(array $rows, $limit, $remainNow, $complete)
{
    $rows = array_reverse($rows);
    $prev = ($limit > 0 && $complete) ? (float) $limit : null;
    $share = function (array $idx, $u) use (&$rows) {
        $w = array_sum(array_map(fn($i) => $rows[$i]['bytes'], $idx));
        $key = $w > 0 ? 'bytes' : 'sec';
        $w = $w > 0 ? $w : array_sum(array_map(fn($i) => $rows[$i]['sec'], $idx));
        foreach ($idx as $i)
            $rows[$i]['bytes'] = $w > 0 ? $u * $rows[$i][$key] / $w : $u / count($idx);
    };
    $bucket = [];
    foreach ($rows as $i => $x) {
        $bucket[] = $i;
        if ($x['rem'] === null)
            continue;
        if ($prev !== null && $x['rem'] <= $prev)
            $share($bucket, $prev - $x['rem']);      // else: first anchor, or volume was added in between: keep estimates
        $prev = (float) $x['rem'];
        $bucket = [];
    }
    $open = 0;
    if ($prev !== null && $limit > 0 && $remainNow <= $prev) {
        if ($bucket)
            $share($bucket, $prev - $remainNow);
        else
            $open = $prev - $remainNow;
    }
    return [array_reverse($rows), $open];
}

// The history holds one row per accounting segment: a long connection appears as several rows with the same
// SessionID whose download/upload counters are CUMULATIVE (each row repeats what the earlier ones counted).
// Turn rows into per-segment usage: bytes = growth of the counter since the previous row of the same connection.
// Adds 'bytes' (this segment only) and 'conn' (index of the connection it belongs to). Input and output newest first.
function agent_ro_segments(array $rows)
{
    $rows = array_reverse($rows);                    // oldest first
    $last = [];                                      // sid => [total, end ts, conn]
    $conn = 0;
    foreach ($rows as &$x) {
        $p = $last[$x['sid']] ?? null;
        if ($p && $x['total'] >= $p[0] && $x['ts'] - $p[1] <= 180) {
            $x['bytes'] = $x['total'] - $p[0];       // same connection goes on
            $x['conn'] = $p[2];
        } else {
            $x['bytes'] = $x['total'];               // a new connection
            $x['conn'] = ++$conn;
        }
        $last[$x['sid']] = [$x['total'], $x['ts'] + $x['sec'], $x['conn']];
    }
    unset($x);
    return array_reverse($rows);
}

function agent_tool_usage_report($uid, $username)
{

    $rows = agent_ro_rows($uid, 200);
    $username = strtolower(trim(preg_replace('/\s+/u', '', (string) $username)));
    $row = null;
    foreach ($rows as $r)
        if ($username !== '' && strtolower($r['username']) === $username)
            $row = $r;
    if (!$row && $username === '' && count($rows) === 1)
        $row = $rows[0];
    if (!$row)
        return ['error' => ($username === '' ? 'no username given and the customer has several services' : 'this username is not a service of this customer')
            . ': call my_services, then ask which one (or pick the one that fits what they said)'];
    $raw = null;
    $live = agent_ro_live($row, $raw);
    $others = [];
    $deadline = microtime(true) + 12;
    foreach (array_slice($rows, 0, 12) as $r) {
        if ($r['username'] === $row['username'] || microtime(true) > $deadline)
            continue;
        $o = agent_ro_live($r);
        if (isset($o['total']))
            $others[] = ['username' => $o['username'], 'plan' => $o['plan'], 'total' => $o['total'], 'remaining' => $o['remaining'] ?? 'unlimited',
                'finished' => !empty($o['volume_finished']) || !empty($o['time_finished'])];
    }
    $otherFin = array_values(array_filter($others, fn($o) => $o['finished']));
    [$since, $why] = agent_ro_period_start($row);
    $out = ['service' => $live];
    if ($others)
        $out['other_services_of_this_customer'] = $others;
    if (empty($live['volume_finished']) && empty($live['time_finished']) && $otherFin)
        $out['WARNING'] = 'This service is NOT finished, but the customer has ' . count($otherFin) . ' other service(s) that ARE finished: ' . implode(', ', array_column($otherFin, 'username'))
            . '. If the customer said their volume finished, they mean one of those, not this one: do not answer about this service. '
            . (count($otherFin) === 1 ? 'Call usage_report for that one now and answer about it.' : 'Ask which one in one short question, listing them as «یوزر — پلن — حجم کل».')
            . ' When they name it, call usage_report for it at once and give the day-by-day breakdown: they already asked why it finished, so do not offer, do it. Write only the message for the customer, speaking TO them (تو/شما).';
    // Stop here: with the day-by-day numbers of the wrong service in hand, the model answers about the wrong service.
    // The first call in a turn gets the warning only; asking again for the same username gets the numbers.
    $key = $uid . '|' . $row['username'];
    if (isset($out['WARNING']) && time() - ($GLOBALS['agent_ro_warned'][$key] ?? 0) > 120) {
        $GLOBALS['agent_ro_warned'][$key] = time();
        // names only: the numbers of the finished services come from their own usage_report
        $out['other_services_of_this_customer'] = array_map(fn($o) => ['username' => $o['username'], 'plan' => $o['plan'], 'total' => $o['total'], 'finished' => $o['finished']], $others);
        return $out + ['breakdown' => 'not included, see WARNING. Only if the customer has named THIS username themselves, call usage_report for it once more to get its breakdown.'];
    }
    unset($out['WARNING']);
    $out += ['period' => 'current volume period started ' . agent_ago($since) . ' (' . $why . ', ' . (function_exists('jdate') ? jdate('Y/m/d H:i', $since, '', 'Asia/Tehran', 'en') : date('Y-m-d H:i', $since)) . ')'];
    $panel = select('marzban_panel', '*', 'name_panel', $row['Service_location'], 'select');
    if (!is_array($panel) || strtolower((string) $panel['type']) !== 'cloudius' || !function_exists('cloudius_api')) {
        $out['breakdown'] = 'not available: this panel type keeps only the total used volume, no per-day or per-session history. Explain with the totals above (total, used, remaining, days left) and what usually uses volume; do not invent a time breakdown.';
        return $out + ['how_to_answer' => agent_ro_howto()];
    }
    $h = agent_ro_cloudius_sessions($row['Service_location'], $row['username'], $since);
    if ($h === null) {
        $out['breakdown'] = 'the login server did not return the session history just now; answer with the totals above and offer to check again in a few minutes';
        return $out + ['how_to_answer' => agent_ro_howto()];
    }
    $limitB = (float) ($raw['limit'] ?? 0);
    $remNow = $limitB - (float) ($raw['used'] ?? 0);
    [$s, $open] = agent_ro_calibrate($h['rows'], $limitB, $remNow, $h['complete']);
    $days = [];
    $nets = [];
    $sum = 0;
    foreach ($s as $x) {
        $day = substr($x['start'], 0, 10);
        if (!isset($days[$day]))
            $days[$day] = ['date' => $day, 'conns' => [], 'sec' => 0, 'bytes' => 0, 'nets' => []];
        $days[$day]['conns'][$x['conn']] = 1;
        $days[$day]['sec'] += $x['sec'];
        $days[$day]['bytes'] += $x['bytes'];
        if ($x['net'] !== '') {
            $days[$day]['nets'][$x['net']] = 1;
            $nets[$x['net']] = 1;
        }
        $sum += $x['bytes'];
    }
    krsort($days);                                       // newest day first
    $perDay = [];
    foreach ($days as $d)
        $perDay[] = ['date' => $d['date'], 'used' => agent_ro_gb($d['bytes']), 'connected' => agent_ro_dur($d['sec']),
            'connections' => count($d['conns']), 'different_networks' => count($d['nets'])];
    $conns = [];
    foreach (array_reverse($s) as $x) {              // oldest first, so 'start' is the first segment's start
        $c = $x['conn'];
        if (!isset($conns[$c]))
            $conns[$c] = ['start' => $x['start'], 'sec' => 0, 'bytes' => 0];
        $conns[$c]['sec'] += $x['sec'];
        $conns[$c]['bytes'] += $x['bytes'];
    }
    usort($conns, fn($a, $b) => $b['bytes'] <=> $a['bytes']);
    $heavy = [];
    foreach (array_slice($conns, 0, 6) as $x)
        if ($x['bytes'] >= 20 * 1048576)
            $heavy[] = ['start' => substr($x['start'], 0, 16), 'lasted' => agent_ro_dur($x['sec']), 'used' => agent_ro_gb($x['bytes'])];
    $peak = $days;
    uasort($peak, fn($a, $b) => $b['bytes'] <=> $a['bytes']);
    $peak = array_slice(array_values($peak), 0, 3);
    $out['breakdown'] = [
        'source' => 'session history of our login server (every connection of this username: start, length, download+upload)',
        'dates' => 'Jalali (Tehran time), newest first',
        'connections_in_period' => count($conns),
        'history_complete' => $h['complete'],
        'covers' => $h['complete'] ? 'the whole current volume period' : 'ONLY from ' . ($s ? substr(end($s)['start'], 0, 16) : '?') . ' until now: the account has too many connections to read them all, so the days before that are missing and the sum below is less than the total used. Say this plainly; do not present it as the whole period.',
        'used_in_these_connections' => agent_ro_gb($sum),
        'used_by_a_connection_still_open_now' => $open > 5 * 1048576 ? agent_ro_gb($open) : 'none',
        'connected_total' => agent_ro_dur(array_sum(array_column($s, 'sec'))),
        'different_networks_in_period' => count($nets),
        'top_days' => array_map(fn($d) => $d['date'] . ': ' . agent_ro_gb($d['bytes']) . ' in ' . agent_ro_dur($d['sec']), $peak),
        'per_day' => array_slice($perDay, 0, 31),
        'heaviest_connections' => $heavy,
        'notes' => 'different_networks = how many different internet connections (Wi-Fi / SIM IPs) logged in with this username; a mobile SIM changes IP often, so several a day is normal for one phone, but dozens can mean the account is used on more devices. A session still open now is not in this history until it ends (see service.connection).'
            . ($s ? '' : ' No session in this period: the account was not used since the period started.'),
    ];
    return $out + ['how_to_answer' => agent_ro_howto()];
}

function agent_ro_howto()
{
    return 'به فارسی ساده و دقیق جواب بده: اول کل/مصرف/باقی‌مانده و اینکه حجم تمام شده یا نه. بعد ریز مصرف را «روز به روز» در یک فهرست کوتاه بنویس، هر روز یک خط: «تاریخ: مقدار (مدت اتصال)»؛ همهٔ روزهای per_day را بیاور (اگر بیشتر از ۱۰ روز بود، ۱۰ روز پرمصرف‌تر). بعد ۲ یا ۳ اتصالِ سنگین را با ساعت شروع، مدت و مقدار بگو. عددها را همان‌طور که هست بگو و گرد نکن. اگر different_networks زیاد است فقط بگو «از چند اینترنت/دستگاه مختلف وصل شده» و بپرس اکانت را به کس دیگری داده یا نه؛ کسی را متهم نکن و قابلیتی که در راهنما نیست (مثل عوض‌کردن رمز) پیشنهاد نده. اگر مصرفِ واقعی با حرف مشتری نمی‌خواند، محترمانه همین را با عدد نشان بده. اسم سرور و IP را نگو. این موضوع را ارجاع نده؛ فقط اگر مشتری بعد از دیدن ریز مصرف باز هم اعتراض داشت و جبران/بازگشت وجه خواست، ارجاع بده و خلاصهٔ همین عددها را در reason بنویس.';
}

function agent_tool_my_payments($uid)
{
    global $pdo;
    $u = select('user', '*', 'id', $uid, 'select');
    $out = ['wallet_balance_toman' => number_format((int) ($u['Balance'] ?? 0))];
    $stMap = ['paid' => 'paid and credited', 'Unpaid' => 'created but not paid yet', 'expire' => 'expired without payment',
        'reject' => 'rejected', 'waiting' => 'receipt sent, waiting for admin approval'];
    $mMap = ['cart to cart' => 'کارت به کارت', 'arze digital offline' => 'ارز دیجیتال (رسید دستی)', 'usdt offline' => 'تتر (USDT)',
        'nowpayment' => 'NowPayments (ارزی)', 'Tronado' => 'ترونادو', 'frenzyex' => 'FrenzyEX', 'frenzyex nowpay' => 'FrenzyEX',
        'add balance by admin' => 'افزایش موجودی توسط ادمین', 'low balance by admin' => 'کاهش موجودی توسط ادمین'];
    $st = $pdo->prepare('SELECT id_order, time, price, Payment_Method, payment_Status FROM payment_report WHERE id_user = ? ORDER BY id DESC LIMIT 10');
    $st->execute([$uid]);
    $out['last_payments'] = array_map(fn($p) => ['tracking_code' => $p['id_order'], 'time' => $p['time'], 'amount_toman' => number_format((int) $p['price']),
        'method' => $mMap[$p['Payment_Method']] ?? (strpos((string) $p['Payment_Method'], 'Currency Rial') === 0 ? 'درگاه ریالی' : $p['Payment_Method']),
        'state' => $stMap[$p['payment_Status']] ?? $p['payment_Status']], $st->fetchAll(PDO::FETCH_ASSOC));
    $st = $pdo->prepare("SELECT username, name_product, price_product, time_sell, Status FROM invoice WHERE id_user = ? AND Status <> 'unpaid' ORDER BY time_sell DESC LIMIT 10");
    $st->execute([$uid]);
    $out['last_purchases'] = array_map(fn($i) => ['username' => $i['username'], 'plan' => trim((string) $i['name_product']), 'price_toman' => number_format((int) $i['price_product']),
        'bought' => agent_ago((int) $i['time_sell']), 'state' => $i['Status']], $st->fetchAll(PDO::FETCH_ASSOC));
    $tMap = ['extend_user' => 'renewal', 'extend_user_by_admin' => 'renewal by admin', 'extra_user' => 'extra volume', 'extra_time_user' => 'extra time',
        'transfertouser' => 'transfer to another user', 'change_location' => 'location change'];
    try {
        $st = $pdo->prepare('SELECT username, type, price, time, status FROM service_other WHERE id_user = ? ORDER BY id DESC LIMIT 10');
        $st->execute([$uid]);
        $out['last_renewals_and_extras'] = array_map(fn($x) => ['username' => $x['username'], 'what' => $tMap[$x['type']] ?? $x['type'],
            'price_toman' => number_format((int) $x['price']), 'time' => $x['time'], 'state' => $x['status']], $st->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
    }
    $out['how_to_answer'] = 'به فارسی ساده بگو چه دیدی: مبلغ، زمان، روش و وضعیت همان پرداخت یا خریدی که مشتری می‌گوید، و موجودی کیف پول. «paid and credited» یعنی پول نشسته؛ «waiting for admin approval» یعنی رسید رسیده و منتظر تأیید است؛ «expired/created but not paid» یعنی پرداختی برای آن فاکتور ثبت نشده. فقط وقتی ارجاع بده که کاری لازم است که تو نمی‌توانی انجام بدهی (تأیید دستی رسید، بازگشت وجه، اصلاح موجودی)، و در reason کد پیگیری و عددها را بنویس.';
    return $out;
}

// A tool without parameters is called with input {}. PHP decodes that to [] and would send it back as a JSON list,
// which the API rejects (HTTP 400) on the next request. Send every empty tool input as an object.
function agent_ro_fix_inputs(array $body)
{
    foreach ($body['messages'] ?? [] as $i => $m) {
        if (!is_array($m['content'] ?? null))
            continue;
        foreach ($m['content'] as $j => $b)
            if (is_array($b) && ($b['type'] ?? '') === 'tool_use' && ($b['input'] ?? null) === [])
                $body['messages'][$i]['content'][$j]['input'] = new stdClass();
    }
    return $body;
}

function agent_ro_tools()
{
    return [
        ['name' => 'my_services', 'description' => 'READ-ONLY. Every service of this customer, read live from the panels: username, plan, protocol, total / used / remaining volume, days left, finished or not, online or not. Call it whenever you are not sure which service the customer means, or their words do not match [اطلاعات سرویس]. Fast.',
            'input_schema' => ['type' => 'object', 'properties' => new stdClass()]],
        ['name' => 'usage_report', 'description' => 'READ-ONLY. Everything about the volume of ONE service of this customer: live total / used / remaining, days left, and for OpenVPN and L2TP services the full connection history summed per day (date, volume, time connected, number of connections, different networks) plus the heaviest connections with their start time. Use it for every question or complaint about volume, usage, «حجمم زود تمام شد», «چقدر مصرف کردم», «کی مصرف شده». Never use check_connection for these.',
            'input_schema' => ['type' => 'object', 'properties' => ['username' => ['type' => 'string', 'description' => 'the service username']], 'required' => ['username']]],
        ['name' => 'my_payments', 'description' => 'READ-ONLY. Wallet balance, the last 10 payments with amount / method / state, the last purchases and the last renewals of this customer. Use it for «پول دادم ولی شارژ نشد», «پرداختم چی شد», «موجودی‌ام», «تمدید کردم ولی…» before answering or referring.',
            'input_schema' => ['type' => 'object', 'properties' => new stdClass()]],
    ];
}

function agent_ro_rules()
{
    return "- [agent-readonly] این چند قانون بر هر جملهٔ راهنما که خلافش باشد مقدم است (مثلاً «فقط بگو حجم تمام شده» یا «حجم را از پنل یا ربات دیگر ببیند»). تو به اطلاعات خودِ این مشتری دسترسی فقط‌خواندنی داری (my_services، usage_report، my_payments). هر سؤالی که جوابش در این اطلاعات است را خودت جواب بده و ارجاع نده؛ «نیاز به بررسی حجم/پرداخت دارد» دلیل ارجاع نیست، چون خودت می‌توانی بررسی کنی. ارجاع فقط برای کاری است که باید انجام شود و تو نمی‌توانی (بازگشت وجه، تغییر سرویس، تأیید دستی رسید، جبران) یا وقتی ابزار خطا داد.\n"
        . "- اول بفهم سؤال چیست، بعد ابزار همان سؤال را صدا بزن: سؤال یا اعتراض دربارهٔ حجم و مصرف («حجمم تمام شد»، «چرا این‌قدر زود»، «چقدر مانده»، «کی مصرف شده») = usage_report و هرگز check_connection. check_connection فقط وقتی است که مشتری می‌گوید وصل نمی‌شود، کند است یا سایتی باز نمی‌شود. سؤال پول، پرداخت، شارژ و تمدید = my_payments.\n"
        . "- اول بفهم کدام سرویس: اگر [سرویس فعال] بیشتر از ۱ است و مشتری یوزر را نگفته، یا حرفش با [اطلاعات سرویس] نمی‌خواند (مثلاً می‌گوید حجمم تمام شده ولی آن سرویس حجم دارد)، یا می‌گوید «این اکانت نه»، my_services را صدا بزن. اگر فقط یک سرویس با حرف او جور است همان را بررسی کن و اسمش را بگو؛ وگرنه یک سؤال کوتاه بپرس و گزینه‌ها را با «یوزر — پلن — باقی‌مانده» فهرست کن. حتی در «گزارش اختلال»، سرویسِ انتخاب‌شده را قطعی فرض نکن اگر حرف مشتری چیز دیگری می‌گوید.\n"
        . "- متنِ تو مستقیم برای مشتری فرستاده می‌شود: فقط خودِ پیام را بنویس و با خودِ او حرف بزن؛ دربارهٔ او سوم‌شخص ننویس («مشتری گفته…»، «یک سؤال می‌پرسم») و فکرت را توضیح نده. از ** و علامت‌های markdown استفاده نکن. عددها را با رقم فارسی و واحدها را «گیگ» و «مگ» بنویس.\n"
        . "- پیشنهادِ بررسی نده، خودِ بررسی را انجام بده: به‌جای «اگر می‌خواهی ریز مصرف را بگیرم» همان لحظه usage_report را صدا بزن و نتیجه را بگو. وقتی مشتری در همین گفتگو پرسیده «چرا/کِی/چطور حجمم تمام شد»، تا usage_report همان یوزر را صدا نزده‌ای جواب نده؛ جوابی که ریز مصرفِ روز به روز ندارد ناقص است، حتی اگر از قبل بدانی حجمش صفر شده.\n"
        . "- جوابِ مصرف باید دقیق و با عدد باشد: کل، مصرف‌شده، باقی‌مانده؛ بعد روز به روز (تاریخ و مقدار، هر روز یک خط) و سنگین‌ترین اتصال‌ها (ساعت شروع، مدت، مقدار). محدودیت «حداکثر ۳ جمله و بدون لیست» برای جوابِ ریز مصرف و ریز پرداخت برداشته می‌شود. حتی وقتی حجم کامل تمام شده، اگر مشتری پرسیده چرا یا کِی، همین ریز مصرف را بگو و بعد بگو باید سرویس جدید بخرد. مشتری را به پنل یا ربات دیگری حواله نده که خودش نگاه کند.\n";
}

function agent_ro_call($uid, $name, array $in)
{
    try {
        if ($name === 'my_services')
            return agent_tool_my_services($uid);
        if ($name === 'usage_report')
            return agent_tool_usage_report($uid, (string) ($in['username'] ?? ''));
        if ($name === 'my_payments')
            return agent_tool_my_payments($uid);
    } catch (Throwable $e) {
        agent_log("readonly tool $name uid=$uid: " . $e->getMessage());
        return ['error' => 'could not read this just now; try once more, and if it fails again tell the customer and hand off'];
    }
    return null;
}
