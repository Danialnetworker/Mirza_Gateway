<?php
// [agent-history] Admin view of customer conversations with the AI support agent, per customer.
// /agent -> «💬 گفتگوهای مشتری‌ها» (or /chats, /chats <user id>): customers (newest first) -> that customer's
// conversations -> full transcript. Only one conversation per customer is live (storage/agent/conv/<uid>.json,
// overwritten by the next one), so agent_start() archives the previous one to storage/agent/history/<uid>/<started>.json;
// archives older than AGENT_HIST_DAYS are deleted. Tool results (they can hold account data) are never shown:
// only the test outcome.

const AGENT_HIST_DAYS = 30;
const AGENT_HIST_PAGE = 8;

function agent_history_archive($uid)
{
    $c = agent_conv($uid);
    if (!$c || empty($c['messages']) || empty($c['started']))
        return;
    $d = agent_dir('history/' . intval($uid));
    @file_put_contents($d . '/' . intval($c['started']) . '.json', json_encode($c, JSON_UNESCAPED_UNICODE));
    foreach ((array) glob($d . '/*.json') as $f)
        if ((int) basename($f, '.json') < time() - AGENT_HIST_DAYS * 86400)
            @unlink($f);
}

function agent_history_meta($uid, array $c)
{
    $kind = ($c['ctx']['kind'] ?? '') === 'disorder' ? 'گزارش اختلال'
        : (['finance' => 'پشتیبانی مالی', 'tech' => 'پشتیبانی فنی'][agent_dep_kind($c['ctx']['dep_id'] ?? 0)] ?? 'تیکت');
    if (!empty($c['outage_sent']))
        $out = ['🚨', 'قطعی به ادمین گزارش شد'];
    elseif (!empty($c['resolved']))   // [agent-resolve]
        $out = ['✅', 'مشکل حل شد؛ مشتری گفت راضی است'];
    elseif (!empty($c['done']))
        $out = ['↪️', 'ارجاع به ' . (['finance' => 'پشتیبانی مالی', 'tech' => 'پشتیبانی فنی'][$c['handoff_dep'] ?? ''] ?? 'همکار') . (($c['feedback'] ?? '') === 'no' ? ' (مشتری راضی نبود)' : '')];
    elseif (agent_conv_live($c))
        $out = ['🟢', 'در جریان'];
    else
        $out = ['✅', 'خود ایجنت جواب داد (بدون ارجاع)'];
    $u = trim((string) ($c['ctx']['username'] ?? ''));
    return ['uid' => (string) $uid, 'started' => (int) ($c['started'] ?? 0), 'updated' => (int) ($c['updated'] ?? 0),
        'username' => ($u === '' || $u === 'NOT_USERNAME') ? '' : $u, 'kind' => $kind, 'icon' => $out[0], 'outcome' => $out[1],
        'n' => count($c['user_msgs'] ?? [])];
}

// every stored conversation: [uid => [meta, ...] newest first], customers ordered by their newest conversation
function agent_history_all()
{
    $all = [];
    $files = array_merge((array) glob(agent_dir('conv') . '/*.json'), (array) glob(agent_dir('history') . '/*/*.json'));
    foreach ($files as $f) {
        $hist = basename(dirname($f)) !== 'conv';
        $uid = $hist ? basename(dirname($f)) : basename($f, '.json');
        if ($hist && (int) basename($f, '.json') < time() - AGENT_HIST_DAYS * 86400) {
            @unlink($f);
            continue;
        }
        $c = json_decode((string) @file_get_contents($f), true);
        if (!is_array($c) || empty($c['started']) || (empty($c['messages']) && empty($c['user_msgs'])))
            continue;
        $all[$uid][(int) $c['started']] = agent_history_meta($uid, $c);
    }
    foreach ($all as &$l) {
        krsort($l);
        $l = array_values($l);
    }
    unset($l);
    uasort($all, fn($a, $b) => $b[0]['updated'] <=> $a[0]['updated']);
    return $all;
}

function agent_history_load($uid, $started)
{
    $cur = agent_conv($uid);
    if ($cur && (int) ($cur['started'] ?? 0) === (int) $started)
        return $cur;
    $f = agent_dir('history/' . intval($uid)) . '/' . intval($started) . '.json';
    $c = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    return is_array($c) ? $c : null;
}

function agent_history_ago($ts)
{
    $m = max(0, intdiv(time() - (int) $ts, 60));
    return $m < 60 ? "$m دقیقه پیش" : ($m < 1440 ? intdiv($m, 60) . ' ساعت پیش' : intdiv($m, 1440) . ' روز پیش');
}

function agent_history_date($ts)
{
    return function_exists('jdate') ? jdate('m/d H:i', $ts, '', 'Asia/Tehran', 'en') : date('m/d H:i', $ts);
}

// Telegram takes a line's direction from its first letter: start every line with RLM
function agent_history_rtl($t)
{
    return implode("\n", array_map(fn($l) => trim($l) === '' ? '' : "\u{200F}" . $l, explode("\n", $t)));
}

function agent_history_name(array $m)
{
    return $m['username'] !== '' ? '@' . $m['username'] : $m['uid'];
}

function agent_history_nav($prefix, $page, $pages)
{
    if ($pages <= 1)
        return [];
    $row = [];
    if ($page > 0)
        $row[] = ['text' => '◀️ قبلی', 'callback_data' => $prefix . ($page - 1)];
    $row[] = ['text' => 'صفحه ' . ($page + 1) . ' از ' . $pages, 'callback_data' => $prefix . $page];
    if ($page < $pages - 1)
        $row[] = ['text' => 'بعدی ▶️', 'callback_data' => $prefix . ($page + 1)];
    return [$row];
}

function agent_history_customers_view($page)
{
    $all = agent_history_all();
    $pages = max(1, (int) ceil(count($all) / AGENT_HIST_PAGE));
    $page = max(0, min((int) $page, $pages - 1));
    $t = "💬 <b>گفتگوهای مشتری‌ها با پشتیبان هوشمند</b>\n\n" . count($all) . ' مشتری، ' . array_sum(array_map('count', $all))
        . ' گفتگو (' . AGENT_HIST_DAYS . " روز اخیر)\nروی هر مشتری بزن تا گفتگوهایش را ببینی. جدیدترین‌ها بالا هستند.\n\n"
        . "✅ خود ایجنت جواب داد   ↪️ ارجاع شد   🚨 قطعی   🟢 در جریان\n"
        . 'برای یک مشتری خاص: <code>/chats آیدی‌عددی</code>';
    $rows = [];
    foreach (array_slice($all, $page * AGENT_HIST_PAGE, AGENT_HIST_PAGE, true) as $uid => $l)
        $rows[] = [['text' => $l[0]['icon'] . ' ' . agent_history_name($l[0]) . ' · ' . count($l) . ' گفتگو · ' . agent_history_ago($l[0]['updated']),
            'callback_data' => "agentadm_h_u_{$uid}_0"]];
    $rows = array_merge($rows, agent_history_nav('agentadm_h_l_', $page, $pages));
    $rows[] = [['text' => '🔙 تنظیمات پشتیبان هوشمند', 'callback_data' => 'agentadm_home']];
    return [agent_history_rtl($t), json_encode(['inline_keyboard' => $rows])];
}

function agent_history_user_view($uid, $page)
{
    $l = agent_history_all()[(string) $uid] ?? [];
    $back = [['text' => '🔙 همهٔ مشتری‌ها', 'callback_data' => 'agentadm_h_l_0']];
    if (!$l)
        return [agent_history_rtl("💬 برای کاربر <code>" . htmlspecialchars((string) $uid) . '</code> گفتگویی با پشتیبان هوشمند در ' . AGENT_HIST_DAYS . ' روز اخیر ثبت نشده.'),
            json_encode(['inline_keyboard' => [$back]])];
    $pages = max(1, (int) ceil(count($l) / AGENT_HIST_PAGE));
    $page = max(0, min((int) $page, $pages - 1));
    $t = "👤 <b>مشتری</b> <a href=\"tg://user?id=$uid\">$uid</a>" . ($l[0]['username'] !== '' ? ' @' . htmlspecialchars($l[0]['username']) : '')
        . "\n" . count($l) . " گفتگو با پشتیبان هوشمند. روی هر کدام بزن تا متن کاملش را ببینی.";
    $rows = [];
    foreach (array_slice($l, $page * AGENT_HIST_PAGE, AGENT_HIST_PAGE) as $m)
        $rows[] = [['text' => $m['icon'] . ' ' . agent_history_date($m['started']) . ' · ' . $m['kind'] . ' · ' . $m['n'] . ' پیام',
            'callback_data' => "agentadm_h_c_{$uid}_{$m['started']}"]];
    $rows = array_merge($rows, agent_history_nav("agentadm_h_u_{$uid}_", $page, $pages));
    $rows[] = $back;
    return [agent_history_rtl($t), json_encode(['inline_keyboard' => $rows])];
}

// one conversation -> list of HTML turns (header first). What the customer wrote, what the agent answered, and what
// the agent did (test / guide / handoff / outage). Text next to a tool call was never sent to the customer: not shown.
function agent_history_turns($uid, array $c)
{
    $m = agent_history_meta($uid, $c);
    $e = fn($s) => htmlspecialchars(mb_substr(trim((string) $s), 0, 3000));
    $turns = ["💬 <b>گفتگو با پشتیبان هوشمند</b>\n👤 مشتری: <a href=\"tg://user?id=$uid\">$uid</a>" . ($m['username'] !== '' ? ' @' . $e($m['username']) : '')
        . "\n🕒 " . (function_exists('jdate') ? jdate('Y/m/d H:i', $m['started'], '', 'Asia/Tehran', 'en') : date('Y/m/d H:i', $m['started'])) . ' — ' . $m['kind']
        . (trim((string) ($c['service_info'] ?? '')) !== '' ? "\n📦 " . $e($c['service_info']) : '')
        . "\n📌 نتیجه: {$m['icon']} {$m['outcome']}"
        . (isset($c['cost_usd']) ? "\n💵 هزینهٔ این گفتگو: $" . number_format((float) $c['cost_usd'], 4) . ' — ' . (int) ($c['api_calls'] ?? 0) . ' درخواست — ' . $e($c['model'] ?? '') : '')];
    $results = [];
    foreach ($c['messages'] ?? [] as $msg)
        foreach (is_array($msg['content'] ?? null) ? $msg['content'] : [] as $b)
            if (($b['type'] ?? '') === 'tool_result')
                $results[$b['tool_use_id'] ?? ''] = $b['content'] ?? '';
    $first = true;
    foreach ($c['messages'] ?? [] as $msg) {
        $blocks = is_array($msg['content'] ?? null) ? $msg['content'] : [['type' => 'text', 'text' => (string) ($msg['content'] ?? '')]];
        $text = trim(implode("\n", array_map(fn($b) => ($b['type'] ?? '') === 'text' ? (string) $b['text'] : (($b['type'] ?? '') === 'image' ? '🖼 [عکس]' : ''), $blocks)));
        if (($msg['role'] ?? '') === 'user') {
            if ($text === '')
                continue;                              // tool results only
            if ($first && ($p = mb_strpos($text, '[پیام کاربر] ')) !== false)
                $text = mb_substr($text, $p + mb_strlen('[پیام کاربر] '));
            $first = false;
            $text = str_replace('[عکس مشتری؛ در همان نوبت دیده و بررسی شد]', '🖼 [عکس]', agent_img_label($text));
            $turns[] = "👤 <b>مشتری:</b>\n" . $e($text);
            continue;
        }
        $tools = array_filter($blocks, fn($b) => ($b['type'] ?? '') === 'tool_use');
        foreach ($tools as $b) {
            $in = $b['input'] ?? [];
            if ($b['name'] === 'check_connection') {
                $r = json_decode((string) ($results[$b['id'] ?? ''] ?? ''), true);
                $res = !is_array($r) ? 'نتیجه ثبت نشده' : (isset($r['error']) ? '⚠️ انجام نشد (' . $e(mb_substr((string) $r['error'], 0, 80)) . ')'
                    : (!empty($r['ok']) ? '✅ سالم' : '❌ ناموفق'));
                $who = is_array($r) && isset($r['tested_with']) ? ($r['tested_with'] === 'customer_account' ? ' با اکانت خود مشتری' : ' با اکانت تست') : '';
                $turns[] = '🔧 <i>تست اتصال ' . $e($in['protocol'] ?? ($r['protocol'] ?? '')) . $who . ': ' . $res . '</i>';
            } elseif ($b['name'] === 'send_guide') {
                $turns[] = '📘 <i>راهنمای تصویری فرستاد: ' . $e($in['guide'] ?? '') . '</i>';
            } elseif ($b['name'] === 'handoff') {
                $turns[] = '↪️ <i>ارجاع به ' . (['finance' => 'پشتیبانی مالی', 'tech' => 'پشتیبانی فنی'][$in['department'] ?? ''] ?? 'همکار') . ': ' . $e($in['reason'] ?? '') . '</i>';
            } elseif ($b['name'] === 'my_services') {   // [agent-readonly]
                $turns[] = '🔎 <i>فهرست و وضعیت سرویس‌های مشتری را خواند</i>';
            } elseif ($b['name'] === 'usage_report') {
                $turns[] = '📊 <i>ریز مصرف را خواند: ' . $e($in['username'] ?? '') . '</i>';
            } elseif ($b['name'] === 'my_payments') {
                $turns[] = '💳 <i>پرداخت‌ها و خریدهای مشتری را خواند</i>';
            } elseif ($b['name'] === 'service_details') {
                $turns[] = '🗂 <i>رکورد سرویس را از پنل خواند: ' . $e($in['username'] ?? '') . '</i>';
            } elseif ($b['name'] === 'network_status') {
                $turns[] = '🩺 <i>وضعیت پنل‌ها و لوکیشن‌ها را خواند</i>';
            } elseif ($b['name'] === 'record_feedback') {
                $turns[] = (!empty($in['satisfied']) ? '👍 <i>مشتری راضی بود' : '👎 <i>مشتری راضی نبود') . (trim((string) ($in['note'] ?? '')) !== '' ? ': ' . $e($in['note']) : '') . '</i>';
            } elseif ($b['name'] === 'report_outage') {
                $turns[] = '🚨 <i>گزارش قطعی به ادمین: ' . $e($in['summary'] ?? '') . '</i>';
            }
        }
        if ($tools)
            continue;
        if (preg_match('/\[\[HANDOFF:([^\]]*)\]\]/u', $text, $h)) {
            $turns[] = '↪️ <i>ارجاع به همکار: ' . $e($h[1]) . '</i>';
            $text = trim(preg_replace('/\s*\[\[HANDOFF:[^\]]*\]\]\s*/u', ' ', $text));
        }
        if ($text !== '')
            $turns[] = "🤖 <b>ایجنت:</b>\n" . $e($text);
    }
    $sent = count(array_filter($turns, fn($t) => strpos($t, '👤 <b>مشتری:</b>') === 0));
    foreach (array_slice($c['user_msgs'] ?? [], $sent ? PHP_INT_MAX : 0) as $u)       // conversation the agent never got to answer
        $turns[] = "👤 <b>مشتری:</b>\n" . $e(agent_img_label($u));
    return $turns;
}

function agent_history_send_conv($chat, $uid, $started)
{
    $c = agent_history_load($uid, $started);
    $kb = json_encode(['inline_keyboard' => [[['text' => '🔙 گفتگوهای این مشتری', 'callback_data' => "agentadm_h_U_{$uid}_0"]],
        [['text' => '📋 همهٔ مشتری‌ها', 'callback_data' => 'agentadm_h_L_0']]]]);
    if (!$c) {
        sendmessage($chat, agent_history_rtl('❌ این گفتگو دیگر موجود نیست (بیشتر از ' . AGENT_HIST_DAYS . ' روز گذشته).'), $kb, 'HTML');
        return;
    }
    $chunks = [];
    $cur = '';
    foreach (agent_history_turns($uid, $c) as $t) {      // whole turns per message, so no HTML tag is cut in half
        if ($cur !== '' && mb_strlen($cur) + mb_strlen($t) > 3500) {
            $chunks[] = $cur;
            $cur = '';
        }
        $cur .= ($cur === '' ? '' : "\n\n") . $t;
    }
    $chunks[] = $cur;
    foreach ($chunks as $i => $ch)
        telegram('sendmessage', ['chat_id' => $chat, 'text' => agent_history_rtl($ch), 'parse_mode' => 'HTML',
            'reply_markup' => $i === count($chunks) - 1 ? $kb : null]);
}

// callbacks agentadm_h_*: l_<page> customers, u_<uid>_<page> one customer, c_<uid>_<started> transcript.
// Upper-case L/U = same view as a NEW message (pressed under a transcript, which must stay readable).
function agent_history_handle($a)
{
    global $from_id, $message_id;
    if (preg_match('/^h_([lL])_(\d+)$/', $a, $m)) {
        [$t, $kb] = agent_history_customers_view((int) $m[2]);
        $m[1] === 'l' ? Editmessagetext($from_id, $message_id, $t, $kb) : sendmessage($from_id, $t, $kb, 'HTML');
    } elseif (preg_match('/^h_([uU])_(\d+)_(\d+)$/', $a, $m)) {
        [$t, $kb] = agent_history_user_view($m[2], (int) $m[3]);
        $m[1] === 'u' ? Editmessagetext($from_id, $message_id, $t, $kb) : sendmessage($from_id, $t, $kb, 'HTML');
    } elseif (preg_match('/^h_c_(\d+)_(\d+)$/', $a, $m)) {
        agent_history_send_conv($from_id, $m[1], (int) $m[2]);
    }
    return true;
}

// /chats  or  /chats <user id>
function agent_history_command($text)
{
    global $from_id;
    [$t, $kb] = preg_match('/^\/chats\s+(\d{1,15})$/', trim($text), $m) ? agent_history_user_view($m[1], 0) : agent_history_customers_view(0);
    sendmessage($from_id, $t, $kb, 'HTML');
    return true;
}
