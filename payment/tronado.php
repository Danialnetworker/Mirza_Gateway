<?php
/**
 * Tronado IPN receiver — https://bot.tronado.cloud
 *
 * Tronado POSTs one JSON callback here for every status change of an order
 * this bot created with GetOrderToken (v5). The buyer never lands on this URL;
 * the whole payment UI lives in the Tronado mini app.
 *
 * Nothing in the request is believed on its own:
 *   1. the body must carry a valid X-Tronado-Sig (HMAC-SHA512 over the RAW body
 *      with the shop's IPN signing key, constant-time compared);
 *   2. only OrderStatusID 30 (PaymentAccepted) is "paid" — every other status
 *      is recorded and acknowledged, never credited;
 *   3. before crediting, the order is re-read from Tronado through
 *      GetStatusByPaymentID, which is bound to this shop's own API key, and the
 *      answer must agree with what this bot invoiced.
 *
 * Response codes matter: Tronado retries 5xx (about ten times over four hours)
 * and abandons on 4xx. So "come back later" problems answer 5xx, and
 * "never going to work" problems (bad signature, unknown order) answer 4xx.
 * Tronado also gives up waiting after 5 seconds, so a paid order is
 * acknowledged as soon as it is verified and delivered after the answer has
 * gone out. cronbot/tronado.php polls unpaid orders as a safety net for lost
 * callbacks.
 */

ini_set('error_log', 'error_log');
// Delivery continues even if Tronado closes the connection after its timeout.
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

function tronado_respond(int $code, array $body): never
{
    http_response_code($code);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Send a 200 now and keep running. Tronado stops waiting after 5 seconds and
 * panel calls in DirectPayment can take longer than that; an acknowledgement
 * that arrives late is logged on their side as a failed attempt and retried,
 * which is noise rather than harm (the retry finds the order already paid).
 */
function tronado_ack_early(array $body): void
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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    tronado_respond(405, ['ok' => false, 'error' => 'POST only']);
}

$raw = (string) file_get_contents('php://input');

$signingKey = tronadoSetting('ipnkeytronado');
if ($signingKey === '') {
    // 5xx on purpose: the admin may still be pasting the key; Tronado will retry.
    error_log('tronado: IPN received but no IPN signing key is configured');
    tronado_respond(503, ['ok' => false, 'error' => 'gateway not configured']);
}

// Signature over the raw bytes, before any parsing, compared in constant time.
$sigHeader = strtolower(trim((string) ($_SERVER['HTTP_X_TRONADO_SIG'] ?? '')));
$expectedSig = hash_hmac('sha512', $raw, $signingKey);
if ($sigHeader === '' || !hash_equals($expectedSig, $sigHeader)) {
    error_log('tronado: IPN rejected, bad or missing X-Tronado-Sig from ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    tronado_respond(401, ['ok' => false, 'error' => 'bad signature']);
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    tronado_respond(400, ['ok' => false, 'error' => 'invalid json']);
}

// v5 sends PaymentId; the status endpoint and older payloads say PaymentID.
$paymentId = trim((string) ($payload['PaymentId'] ?? $payload['PaymentID'] ?? ''));
if ($paymentId === '') {
    tronado_respond(400, ['ok' => false, 'error' => 'PaymentId missing']);
}
$statusId = (int) ($payload['OrderStatusID'] ?? 0);

$paymentReport = select("Payment_report", "*", "id_order", $paymentId, "select");
if (!$paymentReport || ($paymentReport['Payment_Method'] ?? '') !== 'Tronado') {
    error_log("tronado: IPN for unknown order {$paymentId}");
    tronado_respond(404, ['ok' => false, 'error' => 'unknown order']);
}

// Already settled by whichever path got here first (an earlier callback, or
// the poll cron). Acknowledge so Tronado stops retrying.
if (($paymentReport['payment_Status'] ?? '') === 'paid') {
    tronado_respond(200, ['ok' => true, 'status' => 'already paid']);
}

if ($statusId === TRONADO_STATUS_PAYMENT_ACCEPTED && !empty($payload['IsPaid'])) {
    // The signed body first, then an authoritative second opinion bound to this
    // shop's API key: a signed callback that Tronado itself does not confirm is
    // not credited. Both live in tronadoPaidEvidence() so the primary/confirming
    // distinction cannot drift apart between the two calls.
    $evidence = tronadoPaidEvidence($paymentReport, $payload);
    if ($evidence['stage'] === 'unavailable') {
        error_log("tronado: status check unavailable for {$paymentId}, asking for a retry");
        tronado_respond(500, ['ok' => false, 'error' => 'status check unavailable']);
    }
    if ($evidence['reason'] !== '') {
        tronadoReportProblem($paymentReport, $evidence['reason'], $evidence['payload']);
        tronado_respond(200, ['ok' => false, 'error' => $evidence['stage'] === 'payload' ? 'payload mismatch' : 'status mismatch']);
    }

    // Verified. Acknowledge first, deliver second: claimPaymentPaid inside the
    // settlement still guarantees a single credit whatever path gets there.
    tronado_ack_early(['ok' => true, 'accepted' => true]);
    tronadoSettleOrder($paymentReport, $payload, 'ipn');
    exit;
}

if (in_array($statusId, TRONADO_CLOSED_STATUSES, true)) {
    tronadoCloseOrder($paymentReport, $statusId, 'ipn');
    tronado_respond(200, ['ok' => true, 'status' => 'closed']);
}

// Progress statuses (waiting for photo, photo under review, ready to transfer):
// remember the last one for the admin panel, nothing to credit.
$meta = tronadoOrderMeta($paymentReport);
$meta['last_status'] = [
    'id' => $statusId,
    'title' => (string) ($payload['OrderStatusTitle'] ?? ''),
    'at' => gmdate('c'),
];
tronadoStoreMeta((string) $paymentReport['id_order'], $meta);
tronado_respond(200, ['ok' => true, 'status' => 'recorded']);
