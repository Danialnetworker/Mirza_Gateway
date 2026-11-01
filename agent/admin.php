<?php
// [agent] admin settings for the AI support agent (administrators only). Entry: the
// «🤖 پشتیبان هوشمند» button in the admin keyboard, or /agent. Returns true when it handled the update.
const AGENT_ADMIN_BTN = '🤖 پشتیبان هوشمند';

function agent_cfg_save(array $patch)
{
    agent_json_update(agent_dir() . '/config.json', function (&$c) use ($patch) {
        $c = array_replace_recursive(is_array($c) ? $c : [], $patch);
    });
    agent_cfg(true);
}

function agent_admin_view()
{
    $c = agent_cfg();
    $u = agent_usage();
    $key = agent_key() !== '';
    $st = !empty($c['enabled']) ? ($key ? (agent_over_budget() ? '⏸ روشن، ولی سقف بودجه پر شده' : '✅ روشن') : '⚠️ روشن، ولی کلید API ثبت نشده') : '⛔️ خاموش';
    $t = "🤖 <b>پشتیبان هوشمند</b>\n\n"
        . "وضعیت: $st\n"
        . "مدل: <code>" . htmlspecialchars($c['model']) . "</code>\n"
        . 'کلید API: ' . ($key ? 'ثبت شده' : 'ثبت نشده') . "\n"
        . 'مصرف امروز: $' . number_format($u['day_usd'], 3) . ' از $' . $c['daily_usd'] . "\n"
        . 'مصرف این ماه: $' . number_format($u['month_usd'], 3) . ' از $' . $c['monthly_usd'] . "\n"
        . 'پشتیبان فنی (اختلال‌های حل‌نشده و دپارتمان فنی): ' . ($c['tech_id'] !== '' ? 'تنظیم شده' : 'تنظیم نشده (به ادمین اصلی می‌رسد)') . "\n"
        . 'ادمین (اختلال‌های تأییدشده و جایگزین): ' . ($c['admin_id'] !== '' ? 'تنظیم شده' : 'تنظیم نشده (همه ادمین‌ها)') . "\n"
        . 'پشتیبان مالی (دپارتمان مالی): ' . ($c['finance_id'] !== '' ? 'تنظیم شده' : 'تنظیم نشده (به ادمین اصلی می‌رسد)') . "\n"
        . 'تست اتصال از سرور ایران: ' . (agent_probe_configured() ? 'فعال' : 'تنظیم نشده') . "\n\n"
        . "وقتی روشن است، کاربر فقط دو دپارتمان «پشتیبانی مالی» و «پشتیبانی فنی» را می‌بیند؛ اگر موضوع پیام مال بخش دیگر باشد، خود ربات آن را به همان بخش می‌فرستد. هر دو آیدی می‌توانند یکی باشند.\n"
        . "پیام پشتیبانی و گزارش اختلال اول به پشتیبان هوشمند می‌رسد و هر جا لازم باشد با همهٔ پیام‌ها به همکار ارجاع می‌شود.";
    $kb = ['inline_keyboard' => [
        [['text' => !empty($c['enabled']) ? '⛔️ خاموش کن' : '✅ روشن کن', 'callback_data' => 'agentadm_toggle']],
        [['text' => '🔑 کلید API', 'callback_data' => 'agentadm_set_key'], ['text' => '🧪 تست کلید', 'callback_data' => 'agentadm_test']],
        [['text' => '💵 سقف روزانه', 'callback_data' => 'agentadm_set_daily'], ['text' => '💵 سقف ماهانه', 'callback_data' => 'agentadm_set_monthly']],
        [['text' => '🛠 آیدی پشتیبان فنی', 'callback_data' => 'agentadm_set_tech'], ['text' => '💰 آیدی پشتیبان مالی', 'callback_data' => 'agentadm_set_finance']],
        [['text' => '👤 آیدی ادمین', 'callback_data' => 'agentadm_set_admin']],
    ]];
    return [$t, json_encode($kb)];
}

function agent_admin()
{
    global $from_id, $text, $datain, $message_id, $user, $callback_query_id;
    $isAdmin = select('admin', '*', 'id_admin', $from_id, 'select');
    if (!$isAdmin || ($isAdmin['rule'] ?? '') !== 'administrator')
        return false;
    $step = (string) ($user['step'] ?? '');
    $prompts = [
        'key' => "🔑 کلید API آنتروپیک را بفرست (sk-ant-…).\nپیامت بلافاصله پاک می‌شود و کلید فقط روی سرور ذخیره می‌شود.",
        'daily' => '💵 سقف هزینهٔ روزانه به دلار را بفرست (مثلاً 1 یا 0.5). صفر یعنی بدون سقف.',
        'monthly' => '💵 سقف هزینهٔ ماهانه به دلار را بفرست (مثلاً 20). صفر یعنی بدون سقف.',
        'tech' => "🛠 آیدی عددی پشتیبان فنی را بفرست؛ گزارش اختلال‌هایی که پشتیبان هوشمند حل نکرد و پیام‌های دپارتمان فنی برای او می‌رود و به کاربر نشان داده نمی‌شود.\nبرای حذف، 0 بفرست.",
        'admin' => "👤 آیدی عددی ادمین را بفرست؛ اختلال‌های تأییدشده (سرویس یا پنل واقعاً قطع است) برای او می‌رود (و هر وقت آیدی فنی/مالی خالی باشد).\nبرای حذف (ارسال به همه ادمین‌ها)، 0 بفرست.",
        'finance' => "💰 آیدی عددی پشتیبان مالی را بفرست؛ پیام‌های دپارتمان مالی برای او می‌رود (می‌تواند همان آیدی فنی باشد).\nبرای حذف، 0 بفرست.",
    ];
    if ($text === '/agent' || $text === AGENT_ADMIN_BTN) {
        [$t, $kb] = agent_admin_view();
        sendmessage($from_id, $t, $kb, 'HTML');
        return true;
    }
    if (is_string($datain) && strpos($datain, 'agentadm_') === 0) {
        if ($callback_query_id)
            telegram('answerCallbackQuery', ['callback_query_id' => $callback_query_id]);
        $a = substr($datain, 9);
        if ($a === 'toggle') {
            agent_cfg_save(['enabled' => empty(agent_cfg()['enabled'])]);
            if (!empty(agent_cfg()['enabled']))
                agent_sync_departments();
        } elseif ($a === 'test') {
            [$ok, $m] = agent_key() === '' ? [false, 'کلید ثبت نشده'] : agent_test_key();
            sendmessage($from_id, ($ok ? '✅ کلید کار می‌کند: ' : '❌ ') . htmlspecialchars($m), null, 'HTML');
            return true;
        } elseif (strpos($a, 'set_') === 0 && isset($prompts[substr($a, 4)])) {
            step('agentadm_' . substr($a, 4), $from_id);
            sendmessage($from_id, $prompts[substr($a, 4)], null, 'HTML');
            return true;
        }
        [$t, $kb] = agent_admin_view();
        Editmessagetext($from_id, $message_id, $t, $kb);
        return true;
    }
    if (strpos($step, 'agentadm_') === 0 && is_string($text) && $text !== '') {
        $f = substr($step, 9);
        $v = trim($text);
        $err = null;
        if ($f === 'key') {
            deletemessage($from_id, $message_id);
            if (!preg_match('/^sk-ant-[A-Za-z0-9_-]{20,}$/', $v)) {
                $err = 'این شبیه کلید آنتروپیک نیست.';
            } else {
                $kf = agent_dir() . '/anthropic.key';
                file_put_contents($kf, $v);
                chmod($kf, 0600);
            }
        } elseif ($f === 'daily' || $f === 'monthly') {
            if (!is_numeric($v) || $v < 0)
                $err = 'یک عدد بفرست، مثلاً 1';
            else
                agent_cfg_save([$f . '_usd' => (float) $v]);
        } elseif ($f === 'tech' || $f === 'finance' || $f === 'admin') {
            if ($v === '0')
                agent_cfg_save([$f . '_id' => '']);
            elseif (!preg_match('/^-?\d{5,15}$/', $v))
                $err = 'فقط آیدی عددی بفرست.';
            else
                agent_cfg_save([$f . '_id' => $v]);
            if ($err === null && !empty(agent_cfg()['enabled']))
                agent_sync_departments();
        }
        if ($err !== null) {
            sendmessage($from_id, '❌ ' . $err, null, 'HTML');
            return true;
        }
        step('home', $from_id);
        [$t, $kb] = agent_admin_view();
        sendmessage($from_id, "✅ ذخیره شد.\n\n" . $t, $kb, 'HTML');
        return true;
    }
    return false;
}
