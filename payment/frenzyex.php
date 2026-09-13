<?php
/**
 * FrenzyEx payment callback for Mirza Bot.
 *
 * This is the callback_url handed to FrenzyEx. FrenzyEx POSTs a signed JSON
 * body here the moment a payment settles:
 *
 *   Headers: X-Frenzy-Signature: hex HMAC-SHA256 of the RAW body
 *            X-Frenzy-Event:     payment.completed | payment.test
 *   Body:    {"order_ref":"...","request_id":"...","status":"paid",
 *             "amount_toman":250000,"tx_hash":"...", ...}
 *
 * Structure follows payment/iranpay4.php so it behaves like every other
 * gateway in the bot. Installed by install.sh; needs no lang/*.php edits.
 */

ini_set('error_log', 'error_log');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../jdf.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../Marzban.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../keyboard.php';
require_once __DIR__ . '/../frenzyex_lib.php';
require __DIR__ . '/../vendor/autoload.php';

$ManagePanel = new ManagePanel();
// Set before anything can need it. DirectPayment() reads it as a global, and
// leaving it until after the claim was the older gateways' habit, not a rule.
$textbotlang = languagechange();

function frenzyex_reply($ok, $error = null, $extra = [])
{
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    $out = ['ok' => (bool) $ok];
    if ($error !== null) {
        $out['error'] = $error;
    }
    echo json_encode(array_merge($out, $extra));
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false) {
    $rawBody = '';
}

$signature = $_SERVER['HTTP_X_FRENZY_SIGNATURE'] ?? '';
$event = $_SERVER['HTTP_X_FRENZY_EVENT'] ?? '';

$secret = trim((string) getPaySettingValue('secretfrenzyex', ''));

// Reject anything we cannot prove came from FrenzyEx. Without this an attacker
// could credit themselves by POSTing a fake "paid" body.
if ($secret === '' || $secret === '0' || $signature === ''
    || !hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature)) {
    error_log("FrenzyEx: invalid callback signature");
    http_response_code(401);
    frenzyex_reply(false, 'invalid signature');
    return;
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    frenzyex_reply(false, 'invalid json');
    return;
}

// The «test callback» button in the FrenzyEx panel. Signed, but not a payment.
if (!empty($payload['test']) || $event === 'payment.test') {
    frenzyex_reply(true, null, ['test' => true]);
    return;
}

$order_id = (string) ($payload['order_ref'] ?? $payload['order_id'] ?? '');
$request_id = (string) ($payload['request_id'] ?? $payload['payment_id'] ?? '');
$status = (string) ($payload['status'] ?? '');

if ($order_id === '' || $request_id === '') {
    http_response_code(400);
    frenzyex_reply(false, 'missing order id or request id');
    return;
}

$Payment_report = select("Payment_report", "*", "id_order", $order_id, "select");
if (!$Payment_report) {
    http_response_code(404);
    frenzyex_reply(false, 'order not found', ['order_id' => $order_id]);
    return;
}

if ($status !== 'paid') {
    frenzyex_reply(true, null, ['order_id' => $order_id, 'status' => $status]);
    return;
}

if ($Payment_report['payment_Status'] == "expire") {
    frenzyex_reply(false, 'expired', ['order_id' => $order_id]);
    return;
}

$price = $Payment_report['price'];

// Never deliver for less than the invoice. A short payment is reported, not
// credited.
$paid_toman = (float) ($payload['amount_toman'] ?? $payload['price_amount'] ?? 0);
if ($paid_toman > 0 && $paid_toman + 1 < (float) $price) {
    error_log("FrenzyEx: underpaid order {$order_id} — got {$paid_toman}, expected {$price}");
    http_response_code(409);
    frenzyex_reply(false, 'amount mismatch', ['order_id' => $order_id]);
    return;
}

// Atomic: only the first delivery of this order gets past here, so FrenzyEx
// retries can never credit the customer twice. Uses Mirza's own
// claimPaymentPaid() on 0.4+, and an equivalent single UPDATE on 0.3, which
// has no such helper.
if (!frenzyex_claim_paid($order_id)) {
    frenzyex_reply(true, null, ['order_id' => $order_id, 'duplicate' => true]);
    return;
}

frenzyex_reply(true, null, ['order_id' => $order_id, 'request_id' => $request_id, 'status' => $status]);

$setting = select("setting", "*", null, null, "select");

try {
    DirectPayment($order_id, "../images.jpg");
} catch (Throwable $directPaymentError) {
    error_log("DirectPayment failed for order {$order_id}: " . $directPaymentError->getMessage());
    return;
}

$Balance_id = select("user", "*", "id", $Payment_report['id_user'], "select");

$pricecashback = intval(getPaySettingValue('chashbackfrenzyex', '0'));
if ($pricecashback > 0) {
    $result_cashback = intval($Payment_report['price'] * $pricecashback / 100);
    $Balance_confrim = intval($Balance_id['Balance']) + $result_cashback;
    update("user", "Balance", $Balance_confrim, "id", $Balance_id['id']);
    $gift_tpl = $textbotlang['paymentGateway']['giftReport'] ?? '%s';
    $text_report = sprintf($gift_tpl, $result_cashback);
    sendmessage($Balance_id['id'], $text_report, null, 'HTML');
}

// Keep the settled payload for the admin panel's payment detail view.
$statement = $pdo->prepare("UPDATE Payment_report SET dec_not_confirmed = :dec_not_confirmed WHERE id_order = :id_order");
$statement->bindValue(':dec_not_confirmed', json_encode($payload, JSON_UNESCAPED_UNICODE));
$statement->bindValue(':id_order', $order_id);
$statement->execute();

$paymentreports = select("topicid", "idreport", "report", "paymentreport", "select")['idreport'];
$report_username = htmlspecialchars((string) ($Balance_id['username'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$report_user_id = htmlspecialchars((string) ($Balance_id['id'] ?? $Payment_report['id_user']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$report_order_id = htmlspecialchars($order_id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$report_request_id = htmlspecialchars($request_id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$report_tx_hash = htmlspecialchars((string) ($payload['tx_hash'] ?? $payload['transaction_hash'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$report_paid_crypto = $payload['actually_paid'] ?? $payload['paid_amount'] ?? $payload['amount_crypto'] ?? '';
$report_paid_currency = htmlspecialchars((string) ($payload['paid_currency'] ?? $payload['currency'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

// A translated template may be supplied by a customized Mirza language file.
// The built-in report remains complete and readable without modifying lang/*.php.
if (!empty($textbotlang['paymentGateway']['reportFrenzyEx'])) {
    $text_reportpayment = sprintf(
        $textbotlang['paymentGateway']['reportFrenzyEx'],
        $report_username,
        $report_user_id,
        number_format((float) $price),
        $report_order_id,
        $report_request_id,
        $report_tx_hash
    );
} else {
    $text_reportpayment = "💵 <b>پرداخت جدید</b>\n\n"
        . "👤 نام کاربری: @{$report_username}\n"
        . "🆔 آیدی عددی: <code>{$report_user_id}</code>\n"
        . "🧾 شماره سفارش: <code>{$report_order_id}</code>\n"
        . "💰 مبلغ: <b>" . number_format((float) $price) . " تومان</b>\n"
        . "💳 روش پرداخت: <b>FrenzyEx</b>\n"
        . "🔖 شناسه پرداخت: <code>{$report_request_id}</code>";

    if ($report_paid_crypto !== '' && $report_paid_crypto !== null) {
        $text_reportpayment .= "\n🪙 مبلغ رمزارزی: <b>"
            . htmlspecialchars((string) $report_paid_crypto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . ($report_paid_currency !== '' ? " {$report_paid_currency}" : '')
            . "</b>";
    }
    if ($report_tx_hash !== '') {
        $text_reportpayment .= "\n🔗 هش تراکنش: <code>{$report_tx_hash}</code>";
    }
    $text_reportpayment .= "\n✅ وضعیت: پرداخت‌شده";
}
if (strlen($setting['Channel_Report']) > 0) {
    telegram('sendmessage', [
        'chat_id' => $setting['Channel_Report'],
        'message_thread_id' => $paymentreports,
        'text' => $text_reportpayment,
        'parse_mode' => "HTML"
    ]);
}
