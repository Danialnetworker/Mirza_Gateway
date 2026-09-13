<?php
/**
 * Tronado safety net: poll unpaid Tronado orders and settle the ones Tronado
 * reports as PaymentAccepted.
 *
 * The IPN in payment/tronado.php is the primary path. This cron covers the
 * cases where it never lands — callback domain not yet registered with
 * Tronado, a 5-second timeout on the shop's server, the retry window running
 * out — so a buyer whose money was accepted is never left uncredited.
 *
 * GetStatusByPaymentID is rate limited per API key (20 calls per 10 seconds),
 * hence the pause between calls; the batch is bounded so one run always ends
 * well inside the 3-minute cron interval (100 rows x 0.6 s).
 */
chdir(__DIR__);
ini_set('error_log', 'error_log');
date_default_timezone_set('Asia/Tehran');
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

if (!tronadoConfigured()) {
    return;
}

// One run at a time: the URL is public, and overlapping runs would only burn
// the shop's Tronado rate limit.
$lock = fopen(sys_get_temp_dir() . '/mirzabot-tronado-cron.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    return;
}

$list = $pdo->prepare("SELECT * FROM Payment_report WHERE payment_Status = 'Unpaid' AND Payment_Method = 'Tronado' ORDER BY id ASC LIMIT 100");
$list->execute();
$rows = $list->fetchAll(PDO::FETCH_ASSOC) ?: [];

$statusCheck = $pdo->prepare("SELECT payment_Status FROM Payment_report WHERE id_order = ? LIMIT 1");

foreach ($rows as $paymentReport) {
    // Re-read right before acting: an IPN may have settled it while we slept.
    $statusCheck->execute([$paymentReport['id_order']]);
    if ($statusCheck->fetchColumn() !== 'Unpaid') {
        continue;
    }

    $meta = tronadoOrderMeta($paymentReport);
    if (empty($meta['token']) || !empty($meta['problem'])) {
        // No link was ever issued, or a paid answer already failed our checks
        // and is waiting for a human: nothing to poll.
        continue;
    }

    $status = tronadoGetStatusByPaymentId((string) $paymentReport['id_order']);
    usleep(600000);
    if ($status === null) {
        continue;
    }

    $statusId = (int) ($status['OrderStatusID'] ?? 0);
    if ($statusId === TRONADO_STATUS_PAYMENT_ACCEPTED && !empty($status['IsPaid'])) {
        $mismatch = tronadoPaidPayloadMismatch($paymentReport, $status, false);
        if ($mismatch !== '') {
            tronadoReportProblem($paymentReport, 'poll status ' . $mismatch, $status);
            continue;
        }
        tronadoSettleOrder($paymentReport, $status, 'cron');
    }
    // Rejected/expired/cancelled answers are left to the IPN: the status
    // endpoint may describe an older order under the same PaymentID while a
    // re-initiated one is still live, and closing the row here would stop
    // polling it. Unpaid rows age out through payment_expire.php anyway.
}
