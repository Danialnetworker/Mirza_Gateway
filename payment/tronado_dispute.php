<?php
/**
 * Tronado Dispute Callback receiver  [tronado-dispute]
 *
 * Register in the Tronado mini app (Business -> Settings -> Dispute Callback):
 *     https://<bot domain>/payment/tronado_dispute.php
 *
 * Tronado POSTs here once a cardholder dispute on an already-accepted order is
 * ACCEPTED. Signed exactly like the IPN (X-Tronado-Sig = HMAC-SHA512 of the raw
 * body with the IPN signing key).
 *
 * What the bot does (owner's rules):
 *   Outcome = Annulled
 *     - direct service purchase (getconfigafterpay)  -> the service is removed
 *       from its panel and the invoice marked removebyadmin
 *     - wallet top-up, renewal, extra volume/time     -> the order amount is
 *       taken back from the wallet; the balance may go negative
 *   Outcome = AmountAdjusted
 *     - the wallet moves by the same share of the order amount as the TRX
 *       change (less paid -> debit, more paid -> credit). A service bought with
 *       a smaller payment is kept; the shortfall goes on the wallet.
 *   anything else -> recorded only
 * Every accepted dispute, whatever happens, is posted to the report channel
 * (payment report topic).
 *
 * The order's payment_Status stays 'paid' on purpose: flipping it would let a
 * later "accepted" IPN credit the order a second time through claimPaymentPaid.
 * Each dispute is processed once (DisputeId is the key, in tronado_dispute).
 */

ini_set('error_log', 'error_log');
ignore_user_abort(true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../Marzban.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../keyboard.php';
require_once __DIR__ . '/../jdf.php';
require __DIR__ . '/../vendor/autoload.php';

$ManagePanel = new ManagePanel();
$textbotlang = languagechange();

function dispute_respond(int $code, array $body): never
{
    http_response_code($code);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

/** 200 now, keep working: Tronado stops waiting after a few seconds. */
function dispute_ack_early(array $body): void
{
    $json = json_encode($body, JSON_UNESCAPED_UNICODE);
    http_response_code(200);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Length: ' . strlen($json));
        header('Connection: close');
    }
    echo $json;
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        return;
    }
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();
}

function dispute_h($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

/** Post to the report channel, payment topic. Falls back to error_log. */
function dispute_report(string $text): void
{
    $setting = select("setting", "*");
    $channel = (string) ($setting['Channel_Report'] ?? '');
    if ($channel === '') {
        error_log('tronado dispute (no report channel): ' . strip_tags($text));
        return;
    }
    $topic = select("topicid", "idreport", "report", "paymentreport", "select")['idreport'] ?? null;
    telegram('sendmessage', [
        'chat_id' => $channel,
        'message_thread_id' => $topic,
        'text' => $text,
        'parse_mode' => 'HTML',
    ]);
}

/** Move a wallet by $delta (may go negative). Returns [before, after, found]. */
function dispute_wallet(string $userId, int $delta): array
{
    global $pdo;
    clearSelectCache('user');
    $before = select("user", "Balance", "id", $userId, "select");
    if (!$before) {
        return [null, null, false];
    }
    $stmt = $pdo->prepare("UPDATE user SET Balance = Balance + :d WHERE id = :id");
    $stmt->execute([':d' => $delta, ':id' => $userId]);
    clearSelectCache('user');
    $after = select("user", "Balance", "id", $userId, "select");
    return [(int) $before['Balance'], (int) ($after['Balance'] ?? 0), true];
}

function dispute_kind_title(string $kind): string
{
    switch ($kind) {
        case 'getconfigafterpay':
            return 'خرید مستقیم سرویس';
        case 'getextenduser':
            return 'تمدید سرویس';
        case 'getextravolumeuser':
            return 'خرید حجم اضافه';
        case 'getextratimeuser':
            return 'خرید زمان اضافه';
        default:
            return 'شارژ کیف پول';
    }
}

/** Apply one accepted dispute. Returns ['action' => code, 'lines' => [html...]]. */
function tronado_dispute_apply(array $p, string $paymentId, string $outcome): array
{
    global $pdo, $ManagePanel;
    clearSelectCache('Payment_report');
    $pr = select("Payment_report", "*", "id_order", $paymentId, "select");
    if (!$pr || ($pr['Payment_Method'] ?? '') !== 'Tronado') {
        return ['action' => 'unknown_order', 'lines' => ['⚠️ این سفارش در ربات پیدا نشد؛ اقدامی انجام نشد.']];
    }
    $userId = (string) $pr['id_user'];
    $price = (int) $pr['price'];
    $parts = explode('|', (string) $pr['id_invoice'], 2);
    $kind = $parts[0];
    $lines = [
        '👤 کاربر: <code>' . dispute_h($userId) . '</code>',
        '🧾 نوع سفارش: ' . dispute_kind_title($kind),
        '💰 مبلغ سفارش: ' . number_format($price) . ' تومان',
    ];

    if (($pr['payment_Status'] ?? '') !== 'paid') {
        $lines[] = 'ℹ️ این سفارش در ربات پرداخت‌شده نبود (وضعیت: ' . dispute_h($pr['payment_Status'] ?? '-') . ')؛ چیزی تحویل نشده بود و اقدامی لازم نیست.';
        return ['action' => 'not_paid', 'lines' => $lines];
    }

    $walletLine = function (int $delta, string $why) use ($userId, &$lines) {
        [$before, $after, $found] = dispute_wallet($userId, $delta);
        if (!$found) {
            $lines[] = '❌ کاربر در ربات پیدا نشد؛ کیف پول تغییر نکرد. لطفاً دستی بررسی کنید.';
            return 'wallet_user_missing';
        }
        $lines[] = ($delta < 0 ? '➖ ' : '➕ ') . $why . ': ' . number_format(abs($delta)) . ' تومان';
        $lines[] = '👛 موجودی: ' . number_format($before) . ' ← <b>' . number_format($after) . '</b> تومان';
        return $delta < 0 ? 'wallet_debited' : 'wallet_credited';
    };

    if ($outcome === 'Annulled') {
        if ($kind === 'getconfigafterpay') {
            $svc = $parts[1] ?? '';
            clearSelectCache('invoice');
            $inv = $svc !== '' ? select("invoice", "*", "username", $svc, "select") : null;
            if (!$inv) {
                $lines[] = '❌ سرویس <code>' . dispute_h($svc) . '</code> در ربات پیدا نشد؛ لطفاً دستی بررسی کنید.';
                return ['action' => 'service_missing', 'lines' => $lines];
            }
            $lines[] = '📦 سرویس: <code>' . dispute_h($inv['username']) . '</code> — پنل: ' . dispute_h($inv['Service_location']);
            if (in_array($inv['Status'], ['removebyadmin', 'removedbyadmin', 'removebyuser', 'removeTime'], true)) {
                $lines[] = 'ℹ️ این سرویس قبلاً حذف شده بود (' . dispute_h($inv['Status']) . ').';
                return ['action' => 'service_already_removed', 'lines' => $lines];
            }
            $rm = $ManagePanel->RemoveUser($inv['Service_location'], $inv['username']);
            if (($rm['status'] ?? '') === 'successful') {
                update('invoice', 'Status', 'removebyadmin', 'id_invoice', $inv['id_invoice']);
                $lines[] = '🗑 سرویس از پنل حذف شد.';
                return ['action' => 'service_removed', 'lines' => $lines];
            }
            $msg = is_scalar($rm['msg'] ?? null) ? (string) $rm['msg'] : json_encode($rm['msg'] ?? $rm, JSON_UNESCAPED_UNICODE);
            $lines[] = '❌ حذف سرویس از پنل ناموفق بود: ' . dispute_h(mb_substr($msg, 0, 200)) . "\n⚠️ لطفاً سرویس را دستی حذف کنید.";
            return ['action' => 'service_remove_failed', 'lines' => $lines];
        }
        $action = $walletLine(-$price, 'برگشت مبلغ سفارش لغوشده');
        return ['action' => $action, 'lines' => $lines];
    }

    if ($outcome === 'AmountAdjusted') {
        $ot = (float) ($p['OriginalTronAmount'] ?? 0);
        $td = (float) ($p['TronAmountDelta'] ?? 0);
        $ou = (int) ($p['OriginalUserMustPayToman'] ?? 0);
        $ud = (int) ($p['UserMustPayTomanDelta'] ?? 0);
        $lines[] = '🔁 ترون: ' . dispute_h($p['OriginalTronAmount'] ?? '-') . ' ← ' . dispute_h($p['TronAmount'] ?? '-')
            . ' | تومان ترونادو: ' . number_format($ou) . ' ← ' . number_format((int) ($p['UserMustPayToman'] ?? 0));
        if ($ot > 0) {
            $ratio = $td / $ot;
        } elseif ($ou > 0) {
            $ratio = $ud / $ou;
        } else {
            $lines[] = '❌ مقدار اصلاح قابل محاسبه نبود؛ لطفاً دستی بررسی کنید.';
            return ['action' => 'adjust_unknown', 'lines' => $lines];
        }
        $adj = (int) round($price * $ratio);
        if ($adj === 0) {
            $lines[] = 'ℹ️ اختلاف ناچیز است؛ کیف پول تغییر نکرد.';
            return ['action' => 'adjust_zero', 'lines' => $lines];
        }
        if ($kind === 'getconfigafterpay') {
            $lines[] = 'ℹ️ سرویس حذف نشد؛ اختلاف مبلغ روی کیف پول اعمال شد.';
        }
        $action = $walletLine($adj, $adj < 0 ? 'کسر مابه‌التفاوت (کمتر واریز شده)' : 'افزودن مابه‌التفاوت (بیشتر واریز شده)');
        return ['action' => $action, 'lines' => $lines];
    }

    $lines[] = 'ℹ️ نتیجه‌ی ناشناخته (' . dispute_h($outcome) . ')؛ فقط ثبت شد.';
    return ['action' => 'recorded', 'lines' => $lines];
}

// ---------------------------------------------------------------- request

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    dispute_respond(405, ['ok' => false, 'error' => 'POST only']);
}

$raw = (string) file_get_contents('php://input');

$signingKey = tronadoSetting('ipnkeytronado');
if ($signingKey === '') {
    error_log('tronado dispute: received but no IPN signing key is configured');
    dispute_respond(503, ['ok' => false, 'error' => 'gateway not configured']);
}
$sigHeader = strtolower(trim((string) ($_SERVER['HTTP_X_TRONADO_SIG'] ?? '')));
if ($sigHeader === '' || !hash_equals(hash_hmac('sha512', $raw, $signingKey), $sigHeader)) {
    error_log('tronado dispute: rejected, bad or missing X-Tronado-Sig from ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    dispute_respond(401, ['ok' => false, 'error' => 'bad signature']);
}

$p = json_decode($raw, true);
if (!is_array($p)) {
    dispute_respond(400, ['ok' => false, 'error' => 'invalid json']);
}
$event = (string) ($p['Event'] ?? '');
$disputeId = (int) ($p['DisputeId'] ?? 0);
$paymentId = trim((string) ($p['PaymentId'] ?? $p['PaymentID'] ?? ''));
$outcome = (string) ($p['Outcome'] ?? '');

if ($event !== 'DisputeAccepted') {
    dispute_report("⚖️ <b>ترونادو: رویداد اعتراض ناشناخته</b>\nEvent: <code>" . dispute_h($event) . "</code>\nسفارش: <code>" . dispute_h($paymentId) . "</code>\nفقط ثبت شد.");
    dispute_respond(200, ['ok' => true, 'status' => 'ignored']);
}
if ($disputeId <= 0 || $paymentId === '') {
    dispute_report("⚖️ <b>ترونادو: اعتراض ناقص دریافت شد</b>\nDisputeId یا PaymentId خالی است؛ اقدامی انجام نشد.");
    dispute_respond(400, ['ok' => false, 'error' => 'DisputeId/PaymentId missing']);
}

$pdo->exec("CREATE TABLE IF NOT EXISTS tronado_dispute (
    dispute_id BIGINT NOT NULL PRIMARY KEY,
    event_id VARCHAR(64) NULL,
    payment_id VARCHAR(191) NOT NULL,
    outcome VARCHAR(40) NULL,
    action VARCHAR(40) NULL,
    payload TEXT NULL,
    created_at INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// One pass per dispute: Tronado delivers at-least-once.
$claim = $pdo->prepare("INSERT IGNORE INTO tronado_dispute (dispute_id, event_id, payment_id, outcome, action, payload, created_at)
    VALUES (:d, :e, :p, :o, 'processing', :raw, :t)");
$claim->execute([':d' => $disputeId, ':e' => (string) ($p['EventId'] ?? ''), ':p' => $paymentId, ':o' => $outcome, ':raw' => $raw, ':t' => time()]);
if ($claim->rowCount() < 1) {
    dispute_respond(200, ['ok' => true, 'status' => 'duplicate']);
}

dispute_ack_early(['ok' => true, 'accepted' => true]);

try {
    $result = tronado_dispute_apply($p, $paymentId, $outcome);
} catch (Throwable $e) {
    error_log('tronado dispute ' . $disputeId . ' failed: ' . $e->getMessage());
    $result = ['action' => 'error', 'lines' => ['❌ خطا هنگام اجرا: ' . dispute_h($e->getMessage()), '⚠️ لطفاً دستی بررسی کنید.']];
}
$pdo->prepare("UPDATE tronado_dispute SET action = ? WHERE dispute_id = ?")->execute([$result['action'], $disputeId]);

$outcomeTitle = $outcome === 'Annulled' ? 'لغو سفارش' : ($outcome === 'AmountAdjusted' ? 'اصلاح مبلغ' : dispute_h($outcome));
dispute_report(
    "⚖️ <b>اعتراض ترونادو پذیرفته شد</b>\n"
    . '🆔 سفارش: <code>' . dispute_h($paymentId) . "</code>\n"
    . '📌 نوع اعتراض: ' . dispute_h($p['DisputeTypeTitle'] ?? $p['DisputeType'] ?? '-') . "\n"
    . '📋 نتیجه: ' . $outcomeTitle . "\n\n"
    . implode("\n", $result['lines']) . "\n\n"
    . '🔢 DisputeId: <code>' . $disputeId . '</code>'
);
exit;
