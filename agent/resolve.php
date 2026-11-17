<?php
// [agent-resolve] The agent works a problem through to the end instead of referring it early:
//   - no cap on the number of customer messages (config max_user_msgs = 0; a positive number brings the cap back)
//   - handoff is refused until the agent has really looked (a read / test tool has returned in this conversation)
//   - when the agent thinks the problem is solved it asks whether the customer is satisfied and records the
//     answer with record_feedback; «yes» closes the conversation, «no» keeps it open for another try
//   - cost is counted per model (tokens + USD) and per conversation, feedback per month, for the admin panel

const AGENT_RS_PRICES = [   // USD per 1M tokens: input, 5-minute cache write, cache read, output
    'claude-haiku-5-5' => ['in' => 0.10, 'cache_w' => 0.125, 'cache_r' => 0.01, 'out' => 0.50],
    'claude-sonnet-5-5' => ['in' => 2.0, 'cache_w' => 2.5, 'cache_r' => 0.10, 'out' => 10.0],
    'claude-opus-5-5' => ['in' => 4.0, 'cache_w' => 5.0, 'cache_r' => 0.20, 'out' => 20.0],
];
const AGENT_RS_LOOKED = ['my_services', 'usage_report', 'my_payments', 'service_details', 'network_status', 'check_connection'];

// Sonnet 5.5 / Opus 5.5 refuse thinking «disabled»; their off switch is «between_tools» (the API says so in its
// 400 answer). The notes they write between tool calls then come back as thinking blocks, which are never sent
// to the customer.
function agent_rs_thinking()
{
    return ['type' => in_array(agent_cfg()['model'], ['claude-sonnet-5-5', 'claude-opus-5-5'], true) ? 'between_tools' : 'disabled'];
}

// Cache the conversation so far: without this every step of a turn pays full input price again for all earlier
// tool results. Marks the last block of the last message of the copy that is sent (the stored one is untouched).
function agent_rs_cache_msgs(array $msgs)
{
    $i = count($msgs) - 1;
    if ($i < 0)
        return $msgs;
    if (is_string($msgs[$i]['content']))
        $msgs[$i]['content'] = [['type' => 'text', 'text' => $msgs[$i]['content']]];
    $j = count($msgs[$i]['content']) - 1;
    if ($j >= 0 && in_array($msgs[$i]['content'][$j]['type'] ?? '', ['text', 'tool_result', 'image'], true))
        $msgs[$i]['content'][$j]['cache_control'] = ['type' => 'ephemeral'];
    return $msgs;
}

function agent_rs_price()
{
    $c = agent_cfg();
    return AGENT_RS_PRICES[$c['model']] ?? $c['price'];
}

// called for every API answer: tokens and USD per month and model, and the running cost of the current turn
function agent_rs_count(array $usage, $cost)
{
    $GLOBALS['agent_turn_cost'] = ($GLOBALS['agent_turn_cost'] ?? 0) + $cost;
    $GLOBALS['agent_turn_calls'] = ($GLOBALS['agent_turn_calls'] ?? 0) + 1;
    $model = agent_cfg()['model'];
    agent_json_update(agent_dir() . '/usage_models.json', function (&$u) use ($usage, $cost, $model) {
        $u = is_array($u) ? $u : [];
        $m = &$u[date('Y-m')][$model];
        $m = is_array($m) ? $m : ['calls' => 0, 'in' => 0, 'cache_w' => 0, 'cache_r' => 0, 'out' => 0, 'usd' => 0];
        $m['calls']++;
        $m['in'] += (int) ($usage['input_tokens'] ?? 0);
        $m['cache_w'] += (int) ($usage['cache_creation_input_tokens'] ?? 0);
        $m['cache_r'] += (int) ($usage['cache_read_input_tokens'] ?? 0);
        $m['out'] += (int) ($usage['output_tokens'] ?? 0);
        $m['usd'] += $cost;
        $u = array_slice($u, -6, null, true);
    });
}

// add what this turn cost to the conversation before it is saved
function agent_rs_stamp(array $c)
{
    $c['cost_usd'] = round(($c['cost_usd'] ?? 0) + ($GLOBALS['agent_turn_cost'] ?? 0), 6);
    $c['api_calls'] = ($c['api_calls'] ?? 0) + ($GLOBALS['agent_turn_calls'] ?? 0);
    $c['model'] = agent_cfg()['model'];
    $GLOBALS['agent_turn_cost'] = 0;
    $GLOBALS['agent_turn_calls'] = 0;
    return $c;
}

// names of the read / test tools whose RESULT the model has already seen in this conversation
function agent_rs_looked(array $c)
{
    $msgs = $c['messages'] ?? [];
    array_pop($msgs);                                 // the answer being processed now: its tools have not returned yet
    $seen = [];
    foreach ($msgs as $m)
        foreach (is_array($m['content'] ?? null) ? $m['content'] : [] as $b)
            if (($b['type'] ?? '') === 'tool_use' && in_array($b['name'] ?? '', AGENT_RS_LOOKED, true))
                $seen[$b['name']] = true;
    return array_keys($seen);
}

// null = handoff may go ahead; a string = refused, with what to do first. Refuses at most twice per conversation
// so a customer can never be trapped with the agent.
function agent_rs_handoff_check(array &$c, array $in)
{
    if (agent_rs_looked($c) || ($c['handoff_refused'] ?? 0) >= 2 || (int) ($c['ctx']['active_services'] ?? 1) === 0)
        return null;
    $c['handoff_refused'] = ($c['handoff_refused'] ?? 0) + 1;
    return 'NOT HANDED OFF. You have not looked at anything yet. First investigate with the read-only tools that fit the request '
        . '(my_services / service_details / usage_report / my_payments / network_status / check_connection), tell the customer what you found, '
        . 'and ask what you still need to know. Hand off only when the facts show that a CHANGE is needed (refund, manual payment approval, '
        . 'changing / moving / deleting a service, a fault on our side) or the customer is still not satisfied after you really tried.';
}

function agent_rs_feedback($uid, array &$c, array $in)
{
    $yes = !empty($in['satisfied']);
    $c['feedback'] = $yes ? 'yes' : 'no';
    $c['feedback_note'] = mb_substr(trim((string) ($in['note'] ?? '')), 0, 300);
    $c['feedback_at'] = time();
    if ($yes)
        $c['resolved'] = true;
    $key = 'f' . intval($c['started'] ?? 0);
    agent_json_update(agent_dir() . '/feedback.json', function (&$f) use ($uid, $key, $yes) {
        $f = is_array($f) ? $f : [];
        $f[date('Y-m')][$uid . ':' . $key] = $yes ? 1 : 0;      // one vote per conversation; a later answer replaces it
        $f = array_slice($f, -6, null, true);
    });
    return $yes
        ? 'recorded: satisfied. Now write one short, warm closing sentence; the conversation closes after it.'
        : 'recorded: NOT satisfied. Do not hand off yet: ask what exactly is still wrong or unclear, then try a different approach with the tools. '
            . 'Only if that also fails, or a change is needed that you cannot make, call handoff with a summary of what you checked and what the customer still wants.';
}

function agent_rs_tools_fix(array $t)
{
    foreach ($t as $i => $tool) {
        unset($t[$i]['cache_control']);
        if ($tool['name'] !== 'handoff')
            continue;
        $t[$i]['description'] = 'Hand the conversation to a human colleague. LAST RESORT, only (a) when a change is needed that you cannot make: refund, manual approval of a payment, '
            . 'changing / moving / deleting / recreating a service, fixing a fault on our side that the tools showed; or (b) the customer is still not satisfied after you investigated and tried. '
            . 'It is refused if you have not investigated with the read-only tools first. The number of messages is never a reason.';
        $t[$i]['input_schema']['properties']['checked'] = ['type' => 'string', 'description' => 'in Persian: what you checked and what you found (facts and numbers), so the colleague does not start from zero'];
        $t[$i]['input_schema']['properties']['reason'] = ['type' => 'string', 'description' => 'in Persian: what exactly the colleague must DO'];
        $t[$i]['input_schema']['required'] = ['reason', 'checked'];
    }
    $t[] = ['name' => 'record_feedback', 'description' => 'Record the customer\'s answer after you asked whether their problem is solved and they are satisfied with the answer. '
        . 'Call it only when the customer has actually answered that question (clearly yes or clearly no).',
        'input_schema' => ['type' => 'object', 'properties' => [
            'satisfied' => ['type' => 'boolean'],
            'note' => ['type' => 'string', 'description' => 'in Persian, one line: what was solved, or what the customer is still unhappy about'],
        ], 'required' => ['satisfied']]];
    return array_values($t);
}

function agent_rs_rules()
{
    return "## روش کار (این بخش بر هر جملهٔ خلافش در راهنما مقدم است)\n"
        . "- هدف تو این است که مشکل مشتری، فنی یا مالی، در همین گفتگو کامل حل شود. تعداد پیام سقف ندارد و «چند بار تلاش کردم» دلیل ارجاع نیست. جملهٔ «بعد از ۲ تلاش ناموفق ارجاع بده» دیگر معتبر نیست؛ هر جا راهنما نوشته HANDOFF یعنی بعد از اینکه خودت با ابزارها بررسی کردی و راه‌های دیگر هم جواب نداد.\n"
        . "- قدم‌ها: ۱) دقیق بفهم مشکل چیست و مشتری چه می‌خواهد. ۲) قبل از هر جواب، با ابزارها خودت نگاه کن (سرویس‌ها، رکورد پنل، ریز مصرف، پرداخت‌ها، وضعیت شبکه، تست اتصال)؛ حدس نزن و جواب کلی نده. ۳) چیزی را که دیدی با عدد و زمان به زبان ساده بگو و یک قدم مشخص بده. ۴) نتیجه را پیگیری کن؛ اگر جواب نداد فرضیهٔ بعدی را امتحان کن و همان راه‌حل قبلی را تکرار نکن.\n"
        . "- سؤال بپرس، مثل یک پشتیبان واقعی: اگر چیزی را از ابزارها نمی‌شود فهمید (چه دستگاه و برنامه‌ای، دقیقاً چه خطایی می‌بیند، از کِی شروع شده، با وای‌فای یا دیتای کدام اپراتور، کدام لوکیشن، همه‌چیز باز نمی‌شود یا یک سایت خاص) بپرس. هر بار یک یا دو سؤال مرتبط، نه فهرست بازجویی، و چیزی را که خودت می‌توانی ببینی نپرس.\n"
        . "- متنی که می‌نویسی عیناً برای مشتری می‌رود: از همان کلمهٔ اول با خودِ او حرف بزن و جمع‌بندی یا فکرِ خودت را قبل از جواب ننویس.\n"
        . "- لحن: گرم، محترمانه و طبیعی، مثل آدمی که واقعاً می‌خواهد کمک کند. اگر مشتری کلافه است یک جملهٔ کوتاهِ همدلانه بگو و بعد سراغ کار برو. جملهٔ کلیشه‌ای و تعارف تکراری نگو. محدودیت «حداکثر ۳ جمله» برداشته شده: به اندازهٔ لازم بنویس، ولی کوتاه و بدون حاشیه.\n"
        . "- ارجاع (handoff) آخرین راه است، فقط وقتی: الف) کاری لازم است که تغییر می‌خواهد و تو فقط می‌توانی بخوانی (بازگشت وجه، تأیید دستی پرداخت یا اصلاح موجودی، تعویض/جابجایی/حذف/ساخت دوبارهٔ سرویس، رفع خرابی سمت ما که ابزارها نشان داده‌اند)؛ ب) بعد از بررسی و تلاش واقعی، مشتری هنوز راضی نیست. حتی برای درخواستِ تغییر هم اول نگاه کن و علت را بپرس؛ خیلی وقت‌ها مشکل اصلی چیزی است که خودت حل می‌کنی (مثلاً کسی که بازگشت وجه می‌خواهد چون وصل نمی‌شود). در handoff در checked بنویس چه چیزهایی را دیدی و چه پیدا کردی.\n"
        . "- رضایت: وقتی فکر می‌کنی مشکل حل شده یا جواب کامل داده‌ای، در آخرِ همان پیام بپرس مشکلش حل شد و از جواب راضی بود یا نه (یک جملهٔ ساده، نه در هر پیام). وقتی مشتری جواب داد: اگر راضی بود record_feedback را با satisfied=true صدا بزن و با یک جملهٔ کوتاه خداحافظی کن. اگر راضی نبود record_feedback را با satisfied=false صدا بزن، بپرس دقیقاً چه چیزی مانده یا کجای جواب قانعش نکرده، و با راه دیگری دوباره تلاش کن؛ فقط اگر باز هم نشد یا تغییری لازم بود که از تو برنمی‌آید، ارجاع بده.\n"
        . "- تو به همهٔ پنل‌ها دسترسی «فقط‌خواندنی» داری (service_details و network_status علاوه بر ابزارهای قبلی). هیچ‌چیز را نمی‌توانی تغییر بدهی و هرگز قول تغییر نده (تمدید، افزایش حجم، ریست، برگرداندن پول)؛ بگو این کار را همکارت انجام می‌دهد و ارجاع بده.\n";
}

// lines for the admin panel: feedback of this month and cost per model
function agent_rs_admin_lines()
{
    $t = '';
    $f = AGENT_DIR . '/feedback.json';
    $fb = is_file($f) ? json_decode((string) file_get_contents($f), true) : [];
    $m = is_array($fb) ? ($fb[date('Y-m')] ?? []) : [];
    $yes = count(array_filter($m));
    $t .= 'رضایت مشتری‌ها (این ماه): ' . ($m ? "👍 $yes راضی، 👎 " . (count($m) - $yes) . ' ناراضی' : 'هنوز جوابی ثبت نشده') . "\n";
    $f = AGENT_DIR . '/usage_models.json';
    $um = is_file($f) ? json_decode((string) file_get_contents($f), true) : [];
    foreach (is_array($um) ? ($um[date('Y-m')] ?? []) : [] as $model => $x)
        $t .= 'هزینهٔ ' . htmlspecialchars($model) . ' (این ماه): $' . number_format($x['usd'], 3) . ' در ' . $x['calls'] . ' درخواست'
            . ' (ورودی ' . number_format(($x['in'] + $x['cache_w'] + $x['cache_r']) / 1000) . 'k، خروجی ' . number_format($x['out'] / 1000) . "k توکن)\n";
    return $t;
}
