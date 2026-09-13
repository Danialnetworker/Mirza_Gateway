<?php
/**
 * FrenzyEx helpers, loaded by the patched index.php.
 *
 * Kept in its own file at the bot root so the patch applied to index.php stays
 * a handful of lines. Nothing here depends on a particular Mirza version.
 */

if (!function_exists('frenzyex_setting')) {
    /**
     * One PaySetting row. Uses Mirza's own accessor when it exists so the
     * value comes from the same cache every other gateway reads.
     */
    function frenzyex_setting($name, $default = '')
    {
        if (function_exists('getPaySettingValue')) {
            return trim((string) getPaySettingValue($name, $default));
        }

        $row = select("PaySetting", "ValuePay", "NamePay", $name, "select");
        return trim((string) ($row['ValuePay'] ?? $default));
    }
}

if (!function_exists('frenzyex_status_is_on')) {
    /**
     * Canonical storage is `1`/`0`, matching Mirza's numeric gateway switches.
     * Accept `onfrenzyex` temporarily so a request cannot break while an older
     * database is being normalized by the installer.
     */
    function frenzyex_status_is_on($value)
    {
        $value = strtolower(trim((string) $value));
        return $value === '1' || $value === 'onfrenzyex';
    }
}


if (!function_exists('frenzyex_row_has_callback')) {
    /**
     * True if any button in a keyboard row carries the callback.
     */
    function frenzyex_row_has_callback($row, $callback)
    {
        if (!is_array($row)) {
            return false;
        }
        foreach ($row as $button) {
            if (is_array($button) && (($button['callback_data'] ?? null) === $callback)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('frenzyex_row_has_text')) {
    /**
     * True if any button text contains the given fragment.
     */
    function frenzyex_row_has_text($row, $needle)
    {
        if (!is_array($row)) {
            return false;
        }
        foreach ($row as $button) {
            $text = (string) ($button['text'] ?? '');
            if ($text !== '' && strpos($text, $needle) !== false) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('frenzyex_reorder_payment_keyboard')) {
    /**
     * Put FrenzyEx where the user wants it in the buyer payment list:
     * replace NowPayments in row 1, keep card-to-card beneath it, and move
     * NowPayments down to the last payment-method row before the close button.
     */
    function frenzyex_reorder_payment_keyboard($step_payment, $frenzyex_row)
    {
        if (!isset($step_payment['inline_keyboard']) || !is_array($step_payment['inline_keyboard'])) {
            return $step_payment;
        }

        $original_rows = $step_payment['inline_keyboard'];
        $rows = [];
        $nowpayment_row = null;
        $nowpayment_original_index = null;

        foreach ($original_rows as $idx => $row) {
            if (frenzyex_row_has_callback($row, 'frenzyex')) {
                continue;
            }
            if (frenzyex_row_has_callback($row, 'nowpayment')) {
                if ($nowpayment_row === null) {
                    $nowpayment_row = $row;
                    $nowpayment_original_index = $idx;
                }
                continue;
            }
            $rows[] = $row;
        }

        $insert_frenzyex_at = $nowpayment_original_index;
        if ($insert_frenzyex_at === null) {
            // Fall back to the first payment-method row, typically right after
            // the title/prompt row.
            $insert_frenzyex_at = 1;
        }
        if ($insert_frenzyex_at < 0) {
            $insert_frenzyex_at = 0;
        }
        if ($insert_frenzyex_at > count($rows)) {
            $insert_frenzyex_at = count($rows);
        }
        array_splice($rows, $insert_frenzyex_at, 0, [$frenzyex_row]);

        if ($nowpayment_row !== null) {
            $insert_nowpayment_at = count($rows);
            foreach ($rows as $idx => $row) {
                if (frenzyex_row_has_text($row, 'بستن')) {
                    $insert_nowpayment_at = $idx;
                    break;
                }
            }
            array_splice($rows, $insert_nowpayment_at, 0, [$nowpayment_row]);
        }

        $step_payment['inline_keyboard'] = $rows;
        return $step_payment;
    }
}

if (!function_exists('frenzyex_normalize_status_storage')) {
    /**
     * Normalize statusfrenzyex in the database itself before Mirza's admin.php
     * can read it.  This is intentionally done at runtime as well as during
     * installation because older FrenzyEx patches may still be present on an
     * already-modified Mirza installation and some of them index a 0/1-only
     * status-label array directly.
     *
     * Returns canonical "1" (on) or "0" (off).
     */
    function frenzyex_normalize_status_storage()
    {
        global $pdo;

        $raw = strtolower(trim((string) frenzyex_setting('statusfrenzyex', '0')));
        $normalized = ($raw === '1' || $raw === 'onfrenzyex') ? '1' : '0';

        if ($raw !== $normalized && isset($pdo) && $pdo instanceof PDO) {
            try {
                $stmt = $pdo->prepare(
                    "UPDATE PaySetting SET ValuePay = :value WHERE NamePay = 'statusfrenzyex'"
                );
                $stmt->execute([':value' => $normalized]);

                // select()/getPaySettingValue() may have cached the pre-repair row.
                if (function_exists('clearSelectCache')) {
                    clearSelectCache('PaySetting');
                }
            } catch (Throwable $e) {
                error_log('FrenzyEx: could not normalize statusfrenzyex — ' . $e->getMessage());
            }
        }

        return $normalized;
    }
}

if (!function_exists('frenzyex_enabled')) {
    /**
     * Both credentials, or the button stays hidden.
     *
     * With an API key but no signing key the bot happily creates payments and
     * then rejects every callback as unsigned — the buyer pays and nothing
     * arrives. That failure is invisible until someone loses money, so it is
     * checked here rather than left to the admin to notice.
     */
    function frenzyex_enabled()
    {
        // Normalize the stored representation first. Besides keeping this
        // helper safe, this repairs legacy admin.php code before it executes.
        if (frenzyex_normalize_status_storage() !== '1') {
            return false;
        }

        $key = frenzyex_setting('apifrenzyex', '');
        $secret = frenzyex_setting('secretfrenzyex', '');

        return $key !== '' && $key !== '0' && $secret !== '' && $secret !== '0';
    }
}

if (!function_exists('frenzyex_claim_paid')) {
    /**
     * Claim an order for delivery. True exactly once per order, ever.
     *
     * 0.4 added claimPaymentPaid() for this and it is used when present. 0.3
     * has no equivalent: its gateways read the status, deliver, and only then
     * write 'paid'. Two confirmations arriving together both read "not paid"
     * and both deliver — and FrenzyEx retries, so that race is one this
     * gateway would actually hit rather than a theoretical one.
     *
     * The fallback is the same single atomic UPDATE 0.4 settled on. The row is
     * claimed by the write itself, so only the caller whose UPDATE matched a
     * row proceeds. Column names are identical in 0.3 and 0.4.
     */
    function frenzyex_claim_paid($order_id)
    {
        if (function_exists('claimPaymentPaid')) {
            return claimPaymentPaid($order_id);
        }

        global $pdo;
        $stmt = $pdo->prepare(
            "UPDATE Payment_report SET payment_Status = 'paid'
             WHERE id_order = :id_order AND payment_Status <> 'paid'"
        );
        $stmt->bindValue(':id_order', $order_id);
        $stmt->execute();

        // Mirza caches select() results; without this DirectPayment() can read
        // the pre-claim row back and act on a stale status.
        if (function_exists('clearSelectCache')) {
            clearSelectCache('Payment_report');
        }

        return $stmt->rowCount() >= 1;
    }
}

if (!function_exists('createPayFrenzyEx')) {
    /**
     * Ask FrenzyEx for a payment link.
     *
     * Returns the decoded response. `pay_url` is the link to hand the buyer;
     * its absence means the call failed and the caller must not create an
     * invoice row.
     */
    function createPayFrenzyEx($amount_toman, $order_id)
    {
        global $domainhosts;

        $api_key = frenzyex_setting('apifrenzyex', '');
        if ($api_key === '' || $api_key === '0') {
            return ['success' => false, 'message' => 'frenzyex: api key is unset'];
        }

        $base = frenzyex_setting('endpointfrenzyex', '');
        if ($base === '' || $base === '0') {
            $base = 'https://frenzy.fastsnap.info';
        }
        $base = rtrim($base, '/');

        // `https://` written here rather than left to $domainhosts: that
        // variable is a bare host, and a callback without a scheme is either
        // refused outright or resolved against the wrong base.
        $callback = "https://$domainhosts/payment/frenzyex.php";

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $base . '/api/v1/payment-requests',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $api_key,
            ],
        ]);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode([
            'amount' => (float) $amount_toman,
            'amount_ccy' => 'TMN',
            'order_ref' => (string) $order_id,
            'description' => 'Mirza invoice ' . $order_id,
            'callback_url' => $callback,
        ], JSON_UNESCAPED_UNICODE));

        $response = curl_exec($curl);
        $error = curl_error($curl);
        curl_close($curl);

        if ($response === false) {
            error_log("FrenzyEx: create payment failed — $error");
            return ['success' => false, 'message' => $error];
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            error_log("FrenzyEx: unreadable create response — $response");
            return ['success' => false, 'message' => 'invalid response'];
        }

        return $decoded;
    }
}
