<?php
// [crypto-offline-auto] USDT (TRC20 / BEP20) offline payments to the owner's
// own wallet, verified on-chain and confirmed or rejected without an admin.
//
// Every invoice gets a unique cent amount per network+address, so an incoming
// transfer identifies its invoice by value alone (auto-scan) and a TXID copied
// from the public explorer cannot be replayed against a different invoice.
// Each TXID can settle exactly one order (crypto_offline_tx primary key).

if (defined('COF_METHOD')) {
    return;
}
const COF_METHOD = 'usdt offline';
const COF_TRC20_CONTRACT = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';
const COF_TRC20_CONTRACT_HEX = 'a614f803b6fd780986a42c78ec9c7f77e6ded13c';
const COF_BEP20_CONTRACT = '0x55d398326f99059ff775485246999027b3197955';
const COF_TRANSFER_TOPIC = 'ddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
const COF_INVOICE_TTL = 7200;      // invoice is valid for 2 hours
const COF_GRACE = 900;             // accept transfers mined up to 15 min after expiry
const COF_TXID_WAIT = 1800;        // a submitted TXID may stay unconfirmed for 30 min
const COF_BSC_CONFIRMATIONS = 15;
const COF_BSC_MAX_SCAN = 24000;    // ~3 h of BSC blocks (0.45 s block time)
const COF_BSC_LOG_CHUNK = 1000;
const COF_MAX_TRIES = 3;
const COF_SEED_TTL = 600;          // the one-time recovery-phrase message is deleted after 10 min

function cofNetworks(): array
{
    return [
        'trc20' => ['label' => 'USDT • TRC20 (Tron)', 'title' => 'TRC20 (شبکه ترون)', 'setting' => 'cofwallettrc20', 'explorer' => 'https://tronscan.org/#/transaction/'],
        'bep20' => ['label' => 'USDT • BEP20 (BSC)', 'title' => 'BEP20 (شبکه BNB Smart Chain)', 'setting' => 'cofwalletbep20', 'explorer' => 'https://bscscan.com/tx/'],
    ];
}

function cofGet(string $name, string $default = '0'): string
{
    global $pdo;
    $st = $pdo->prepare("SELECT ValuePay FROM PaySetting WHERE NamePay = ?");
    $st->execute([$name]);
    $v = $st->fetchColumn();
    return $v === false || $v === null ? $default : (string) $v;
}

function cofSet(string $name, string $value): void
{
    global $pdo;
    $st = $pdo->prepare("SELECT COUNT(*) FROM PaySetting WHERE NamePay = ?");
    $st->execute([$name]);
    if ((int) $st->fetchColumn() > 0) {
        $pdo->prepare("UPDATE PaySetting SET ValuePay = ? WHERE NamePay = ?")->execute([$value, $name]);
    } else {
        $pdo->prepare("INSERT INTO PaySetting (NamePay, ValuePay) VALUES (?, ?)")->execute([$name, $value]);
    }
    if (function_exists('clearSelectCache')) {
        clearSelectCache('PaySetting');
    }
}

function cofDigits(string $s): string
{
    return strtr(trim($s), ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٫' => '.']);
}

function cofDiscount(): float
{
    $d = (float) cofGet('cofdiscount', '0');
    return max(0.0, min(90.0, $d));
}

function cofDiscounted(int $price): int
{
    return (int) round($price * (100 - cofDiscount()) / 100);
}

function cofFmtPercent(float $d): string
{
    return rtrim(rtrim(number_format($d, 2, '.', ''), '0'), '.');
}

/* ---------- addresses ---------- */

function cofBase58Decode(string $s): ?string
{
    $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
    $n = '0';
    for ($i = 0, $l = strlen($s); $i < $l; $i++) {
        $p = strpos($alphabet, $s[$i]);
        if ($p === false) {
            return null;
        }
        $n = bcadd(bcmul($n, '58'), (string) $p);
    }
    $hex = '';
    while (bccomp($n, '0') > 0) {
        $hex = sprintf('%02x', (int) bcmod($n, '256')) . $hex;
        $n = bcdiv($n, '256', 0);
    }
    for ($i = 0; $i < strlen($s) && $s[$i] === '1'; $i++) {
        $hex = '00' . $hex;
    }
    return hex2bin($hex);
}

/** 20-byte hex (no 0x41 prefix) of a checksummed Tron base58 address. */
function cofTronHex(string $addr): ?string
{
    if (!preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $addr)) {
        return null;
    }
    $raw = cofBase58Decode($addr);
    if ($raw === null || strlen($raw) !== 25 || $raw[0] !== "\x41") {
        return null;
    }
    $check = substr(hash('sha256', hash('sha256', substr($raw, 0, 21), true), true), 0, 4);
    if (!hash_equals($check, substr($raw, 21))) {
        return null;
    }
    return bin2hex(substr($raw, 1, 20));
}

function cofValidAddress(string $net, string $addr): bool
{
    if ($net === 'trc20') {
        return cofTronHex($addr) !== null;
    }
    return (bool) preg_match('/^0x[0-9a-fA-F]{40}$/', $addr);
}

function cofWallet(string $net): ?string
{
    $nets = cofNetworks();
    if (!isset($nets[$net])) {
        return null;
    }
    $v = trim(cofGet($nets[$net]['setting'], '0'));
    return cofValidAddress($net, $v) ? $v : null;
}

function cofEnabledNetworks(): array
{
    $out = [];
    foreach (cofNetworks() as $net => $info) {
        if (cofWallet($net) !== null) {
            $out[$net] = $info;
        }
    }
    return $out;
}

/* ---------- numbers ---------- */

function cofHexToDec(string $hex): string
{
    $hex = strtolower(preg_replace('/^0x/i', '', trim($hex)));
    $hex = ltrim($hex, '0');
    if ($hex === '' || !ctype_xdigit($hex)) {
        return '0';
    }
    $d = '0';
    foreach (str_split($hex) as $c) {
        $d = bcadd(bcmul($d, '16'), (string) hexdec($c));
    }
    return $d;
}

function cofUnits(string $hex, int $decimals): string
{
    return bcdiv(cofHexToDec($hex), bcpow('10', (string) $decimals), 6);
}

/** received must equal the invoice amount (to the cent; cent steps keep invoices apart). */
function cofAmountMatches(string $received, string $expected): bool
{
    return bccomp($received, bcsub($expected, '0.0005', 6), 6) >= 0
        && bccomp($received, bcadd($expected, '0.005', 6), 6) < 0;
}

function cofTrimAmount(string $a): string
{
    return strpos($a, '.') === false ? $a : rtrim(rtrim($a, '0'), '.');
}

/* ---------- chain access ---------- */

function cofHttp(string $url, ?array $json = null, int $timeout = 15): ?array
{
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    if ($json !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    $key = trim(cofGet('coftrongridkey', '0'));
    if ($key !== '0' && $key !== '' && strpos($url, 'trongrid.io') !== false) {
        $headers[] = 'TRON-PRO-API-KEY: ' . $key;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'mirza-crypto-offline',
    ]);
    if ($json !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
    }
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($code !== 429 && $code !== 403) {
            break;
        }
        usleep(1200000);
    }
    curl_close($ch);
    if ($raw === false || $code === 0 || $code === 429 || $code === 403 || $code >= 500) {
        return null;
    }
    $d = json_decode($raw, true);
    return is_array($d) ? $d : null;
}

function cofBscRpc(string $method, array $params): array
{
    $rpcs = $method === 'eth_getLogs'
        ? ['https://bsc-rpc.publicnode.com', 'https://56.rpc.thirdweb.com']
        : ['https://bsc-rpc.publicnode.com', 'https://bsc-dataseed.binance.org', 'https://bsc-dataseed1.defibit.io', 'https://56.rpc.thirdweb.com'];
    foreach ($rpcs as $rpc) {
        $r = cofHttp($rpc, ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], 20);
        if (is_array($r) && array_key_exists('result', $r) && !isset($r['error'])) {
            return ['ok' => true, 'result' => $r['result']];
        }
    }
    return ['ok' => false, 'result' => null];
}

/** Look one transaction up. state: ok | pending | invalid */
function cofTronTx(string $txid, string $wallet): array
{
    $want = cofTronHex($wallet);
    $info = cofHttp('https://api.trongrid.io/walletsolidity/gettransactioninfobyid', ['value' => $txid]);
    if ($info === null) {
        return ['state' => 'pending', 'reason' => 'network'];
    }
    if (empty($info['id'])) {
        $any = cofHttp('https://api.trongrid.io/wallet/gettransactionbyid', ['value' => $txid]);
        return ['state' => 'pending', 'reason' => (is_array($any) && !empty($any['txID'])) ? 'unconfirmed' : 'notfound'];
    }
    if (($info['receipt']['result'] ?? '') !== 'SUCCESS') {
        return ['state' => 'invalid', 'reason' => 'failed'];
    }
    $sum = '0';
    $found = false;
    foreach ($info['log'] ?? [] as $log) {
        $address = strtolower((string) ($log['address'] ?? ''));
        if (strlen($address) === 42 && substr($address, 0, 2) === '41') {
            $address = substr($address, 2);
        }
        if ($address !== COF_TRC20_CONTRACT_HEX || strtolower((string) ($log['topics'][0] ?? '')) !== COF_TRANSFER_TOPIC) {
            continue;
        }
        if (substr(strtolower((string) ($log['topics'][2] ?? '')), -40) !== $want) {
            continue;
        }
        $sum = bcadd($sum, cofUnits((string) ($log['data'] ?? '0'), 6), 6);
        $found = true;
    }
    if (!$found) {
        return ['state' => 'invalid', 'reason' => 'wrongdest'];
    }
    return ['state' => 'ok', 'amount' => $sum, 'time' => intdiv((int) ($info['blockTimeStamp'] ?? 0), 1000)];
}

function cofBscBlockTime(string $blockHex): ?int
{
    $b = cofBscRpc('eth_getBlockByNumber', [$blockHex, false]);
    if (!$b['ok'] || !is_array($b['result']) || empty($b['result']['timestamp'])) {
        return null;
    }
    return (int) hexdec($b['result']['timestamp']);
}

function cofBscTx(string $txid, string $wallet): array
{
    $r = cofBscRpc('eth_getTransactionReceipt', [$txid]);
    if (!$r['ok']) {
        return ['state' => 'pending', 'reason' => 'network'];
    }
    $rc = $r['result'];
    if (!is_array($rc)) {
        return ['state' => 'pending', 'reason' => 'notfound'];
    }
    if (($rc['status'] ?? '') !== '0x1') {
        return ['state' => 'invalid', 'reason' => 'failed'];
    }
    $want = substr(strtolower($wallet), 2);
    $sum = '0';
    $found = false;
    foreach ($rc['logs'] ?? [] as $log) {
        if (strtolower((string) ($log['address'] ?? '')) !== COF_BEP20_CONTRACT || strtolower((string) ($log['topics'][0] ?? '')) !== '0x' . COF_TRANSFER_TOPIC) {
            continue;
        }
        if (substr(strtolower((string) ($log['topics'][2] ?? '')), -40) !== $want) {
            continue;
        }
        $sum = bcadd($sum, cofUnits((string) ($log['data'] ?? '0'), 18), 6);
        $found = true;
    }
    if (!$found) {
        return ['state' => 'invalid', 'reason' => 'wrongdest'];
    }
    $bn = cofBscRpc('eth_blockNumber', []);
    if (!$bn['ok']) {
        return ['state' => 'pending', 'reason' => 'network'];
    }
    if (hexdec($bn['result']) - hexdec($rc['blockNumber']) < COF_BSC_CONFIRMATIONS) {
        return ['state' => 'pending', 'reason' => 'unconfirmed'];
    }
    $time = cofBscBlockTime($rc['blockNumber']);
    if ($time === null) {
        return ['state' => 'pending', 'reason' => 'network'];
    }
    return ['state' => 'ok', 'amount' => $sum, 'time' => $time];
}

/** Confirmed incoming USDT transfers to a Tron wallet since $sinceTs. */
function cofTronIncoming(string $wallet, int $sinceTs): ?array
{
    $url = 'https://api.trongrid.io/v1/accounts/' . $wallet . '/transactions/trc20?only_to=true&only_confirmed=true&limit=100'
        . '&contract_address=' . COF_TRC20_CONTRACT . '&min_timestamp=' . ($sinceTs * 1000);
    $r = cofHttp($url);
    if (!is_array($r) || empty($r['success'])) {
        return null;
    }
    $out = [];
    foreach ($r['data'] ?? [] as $t) {
        if (($t['to'] ?? '') !== $wallet || ($t['token_info']['address'] ?? '') !== COF_TRC20_CONTRACT || !ctype_digit((string) ($t['value'] ?? ''))) {
            continue;
        }
        $out[] = ['txid' => strtolower((string) $t['transaction_id']), 'amount' => bcdiv((string) $t['value'], '1000000', 6), 'time' => intdiv((int) $t['block_timestamp'], 1000)];
    }
    return $out;
}

/** Confirmed incoming USDT transfers to a BSC wallet since the last scanned block. */
function cofBscIncoming(string $wallet): ?array
{
    $bn = cofBscRpc('eth_blockNumber', []);
    if (!$bn['ok']) {
        return null;
    }
    $safe = (int) hexdec($bn['result']) - COF_BSC_CONFIRMATIONS;
    $stateKey = 'cofbsclast_' . substr(strtolower($wallet), 2, 10);
    $from = (int) cofGet($stateKey, '0') + 1;
    $from = max($from, $safe - COF_BSC_MAX_SCAN);
    $out = [];
    $topicTo = '0x000000000000000000000000' . substr(strtolower($wallet), 2);
    while ($from <= $safe) {
        $to = min($safe, $from + COF_BSC_LOG_CHUNK - 1);
        $r = cofBscRpc('eth_getLogs', [[
            'fromBlock' => '0x' . dechex($from),
            'toBlock' => '0x' . dechex($to),
            'address' => COF_BEP20_CONTRACT,
            'topics' => ['0x' . COF_TRANSFER_TOPIC, null, $topicTo],
        ]]);
        if (!$r['ok'] || !is_array($r['result'])) {
            break;
        }
        foreach ($r['result'] as $log) {
            if (!empty($log['removed'])) {
                continue;
            }
            $out[] = ['txid' => strtolower((string) $log['transactionHash']), 'amount' => cofUnits((string) $log['data'], 18), 'block' => (string) $log['blockNumber'], 'time' => null];
        }
        cofSet($stateKey, (string) $to);
        $from = $to + 1;
    }
    return $out;
}

/* ---------- invoices ---------- */

function cofEnsureTable(): void
{
    global $pdo;
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS crypto_offline_tx (
        txid varchar(100) PRIMARY KEY NOT NULL,
        network varchar(10) NOT NULL,
        id_order varchar(100) NOT NULL,
        amount varchar(40) NOT NULL,
        time int NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

function cofMeta(array $row): array
{
    $m = json_decode((string) ($row['dec_not_confirmed'] ?? ''), true);
    return (is_array($m) && !empty($m['cof'])) ? $m : [];
}

function cofSaveMeta(string $order, array $meta): void
{
    global $pdo;
    $json = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $st = $pdo->prepare("UPDATE Payment_report SET dec_not_confirmed = ? WHERE id_order = ?");
    $st->execute([$json, $order]);
    if (function_exists('clearSelectCache')) {
        clearSelectCache('Payment_report');
    }
}

function cofPending(): array
{
    global $pdo;
    $st = $pdo->prepare("SELECT * FROM Payment_report WHERE Payment_Method = ? AND payment_Status = 'Unpaid'");
    $st->execute([COF_METHOD]);
    $out = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $m = cofMeta($row);
        if ($m) {
            $out[] = ['row' => $row, 'meta' => $m];
        }
    }
    return $out;
}

/** Creates the invoice row; returns its meta (+order) or ['error' => code]. */
function cofCreateInvoice(string $userId, string $net, int $price, string $invoice, float $usdRate): array
{
    global $pdo;
    $wallet = cofWallet($net);
    if ($wallet === null) {
        return ['error' => 'wallet'];
    }
    if ($usdRate <= 0) {
        return ['error' => 'rate'];
    }
    $discount = cofDiscount();
    $pay = cofDiscounted($price);
    $cents = (int) ceil(round($pay * 100 / $usdRate, 4));
    if ($cents < 100) {
        return ['error' => 'small'];
    }
    $pdo->query("SELECT GET_LOCK('mirza_cof_amount', 10)");
    try {
        $used = [];
        foreach (cofPending() as $p) {
            if ($p['meta']['net'] === $net && $p['meta']['addr'] === $wallet) {
                $used[(int) round(((float) $p['meta']['usdt']) * 100)] = true;
            }
        }
        for ($k = 0; $k < 100 && isset($used[$cents + $k]); $k++) {
        }
        if ($k === 100) {
            return ['error' => 'busy'];
        }
        $c = $cents + $k;
        $amount = intdiv($c, 100) . '.' . str_pad((string) ($c % 100), 2, '0', STR_PAD_LEFT);
        $order = bin2hex(random_bytes(5));
        $meta = ['cof' => 1, 'net' => $net, 'addr' => $wallet, 'usdt' => $amount, 'rate' => $usdRate, 'discount' => $discount,
            'pay_toman' => $pay, 'created' => time(), 'tries' => 0];
        $json = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $st = $pdo->prepare("INSERT INTO Payment_report (id_user,id_order,time,price,payment_Status,Payment_Method,id_invoice,dec_not_confirmed) VALUES (?,?,?,?,?,?,?,?)");
        $st->execute([$userId, $order, date('Y/m/d H:i:s'), $price, 'Unpaid', COF_METHOD, $invoice, $json]);
        if (function_exists('clearSelectCache')) {
            clearSelectCache('Payment_report');
        }
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('mirza_cof_amount')");
    }
    return $meta + ['order' => $order];
}

function cofInvoiceText(array $inv, int $price): string
{
    $n = cofNetworks()[$inv['net']];
    $t = "✅ فاکتور پرداخت تتری ساخته شد\n\n"
        . "🛒 کد پیگیری: <code>{$inv['order']}</code>\n"
        . "🌐 شبکه: <b>USDT - {$n['title']}</b>\n"
        . "💳 آدرس کیف پول:\n<code>{$inv['addr']}</code>\n\n"
        . "💵 مقدار دقیق واریز: <code>{$inv['usdt']}</code> USDT\n\n"
        . "🧾 مبلغ فاکتور: " . number_format($price) . " تومان\n";
    if ($inv['discount'] > 0) {
        $t .= "🎁 تخفیف پرداخت تتری " . cofFmtPercent((float) $inv['discount']) . "٪: " . number_format($price - $inv['pay_toman']) . "- تومان\n"
            . "💰 پرداختی شما: " . number_format($inv['pay_toman']) . " تومان\n";
    }
    $t .= "\n⚠️ نکات مهم:\n"
        . "🔸 فقط USDT روی شبکه {$n['title']} ارسال کنید؛ شبکه یا توکن اشتباه قابل بازگشت نیست.\n"
        . "🔹 مقدار رسیده به کیف پول باید دقیقاً <code>{$inv['usdt']}</code> باشد (کارمزد صرافی را جدا در نظر بگیرید)، وگرنه خودکار تأیید نمی‌شود.\n"
        . "🔸 این فاکتور ۲ ساعت اعتبار دارد.\n\n"
        . "⏱ بعد از واریز، پرداخت ظرف چند دقیقه خودکار تأیید می‌شود. برای تأیید سریع‌تر، هش تراکنش (TXID) را با دکمه زیر بفرستید.";
    return $t;
}

function cofReasonText(string $reason, array $ctx = []): string
{
    switch ($reason) {
        case 'failed':
            return 'این تراکنش روی شبکه ناموفق بوده است.';
        case 'wrongdest':
            return 'این تراکنش، انتقال USDT به آدرس این فاکتور نیست (شبکه، توکن یا آدرس اشتباه است).';
        case 'amount':
            return 'مقدار واریزی (' . cofTrimAmount($ctx['amount'] ?? '0') . ' USDT) با مقدار فاکتور (' . ($ctx['expected'] ?? '') . ' USDT) برابر نیست.';
        case 'early':
            return 'این تراکنش قبل از ساخت این فاکتور انجام شده است.';
        case 'late':
            return 'این تراکنش بعد از انقضای فاکتور انجام شده است.';
        case 'used':
            return 'این تراکنش قبلاً برای پرداخت دیگری ثبت شده است.';
        case 'notfound':
            return 'تراکنش تا ۳۰ دقیقه روی شبکه پیدا یا تأیید نشد. شبکه و هش را بررسی کنید.';
        default:
            return 'تراکنش معتبر نیست.';
    }
}

function cofReportGroup(string $text): void
{
    $setting = select("setting", "*");
    if (!is_array($setting) || isTelegramChatIdEmpty($setting['Channel_Report'] ?? '')) {
        return;
    }
    $topic = select("topicid", "idreport", "report", "paymentreport", "select")['idreport'] ?? null;
    $p = ['chat_id' => $setting['Channel_Report'], 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true];
    if (!empty($topic)) {
        $p['message_thread_id'] = $topic;
    }
    telegram('sendmessage', $p);
}

function cofUserLine(string $userId): string
{
    $u = select("user", "*", "id", $userId, "select");
    $un = is_array($u) && !empty($u['username']) && $u['username'] !== 'NOT_USERNAME' ? ' | @' . htmlspecialchars($u['username']) : '';
    return "<code>{$userId}</code>{$un}";
}

function cofTxLink(string $net, string $txid): string
{
    return '<a href="' . cofNetworks()[$net]['explorer'] . $txid . '">' . substr($txid, 0, 10) . '…' . substr($txid, -6) . '</a>';
}

/** Settle a verified transfer against its invoice. */
function cofSettle(array $row, array $meta, string $txid, string $amount, string $via): bool
{
    global $pdo;
    cofEnsureTable();
    $st = $pdo->prepare("INSERT IGNORE INTO crypto_offline_tx (txid, network, id_order, amount, time) VALUES (?,?,?,?,?)");
    $st->execute([$txid, $meta['net'], $row['id_order'], $amount, time()]);
    if ($st->rowCount() !== 1) {
        return false;
    }
    $paid = $pdo->prepare("UPDATE Payment_report SET payment_Status = 'paid' WHERE id_order = ? AND payment_Status = 'Unpaid'");
    $paid->execute([$row['id_order']]);
    if (function_exists('clearSelectCache')) {
        clearSelectCache('Payment_report');
    }
    if ($paid->rowCount() !== 1) {
        $pdo->prepare("DELETE FROM crypto_offline_tx WHERE txid = ? AND id_order = ?")->execute([$txid, $row['id_order']]);
        return false;
    }
    unset($meta['txid_pending'], $meta['txid_at']);
    $meta['txid'] = $txid;
    $meta['paid_amount'] = $amount;
    $meta['paid_at'] = time();
    cofSaveMeta($row['id_order'], $meta);
    if (!empty($row['message_id'])) {
        deletemessage($row['id_user'], $row['message_id']);
    }
    try {
        DirectPayment($row['id_order'], is_file('images.jpg') ? 'images.jpg' : '../images.jpg');
    } catch (Throwable $e) {
        error_log("cof DirectPayment failed for {$row['id_order']}: " . $e->getMessage());
    }
    $d = (float) $meta['discount'];
    cofReportGroup("💎 پرداخت تتر آفلاین — تأیید خودکار ✅\n\n"
        . "👤 کاربر: " . cofUserLine((string) $row['id_user']) . "\n"
        . "🛒 سفارش: <code>{$row['id_order']}</code>\n"
        . "🧾 مبلغ: " . number_format((int) $row['price']) . " تومان"
        . ($d > 0 ? " (تخفیف " . cofFmtPercent($d) . "٪، پرداختی " . number_format((int) $meta['pay_toman']) . " تومان)" : '') . "\n"
        . "🪙 دریافتی: " . cofTrimAmount($amount) . " USDT — " . strtoupper($meta['net']) . "\n"
        . "🔗 TXID: " . cofTxLink($meta['net'], $txid) . "\n"
        . "⚙️ روش تشخیص: " . ($via === 'txid' ? 'هش ارسالی کاربر' : 'اسکن خودکار شبکه'));
    return true;
}

function cofReject(array $row, array $meta, string $reason, array $ctx = [], bool $final = false, ?string $txid = null): void
{
    global $pdo;
    unset($meta['txid_pending'], $meta['txid_at']);
    $meta['tries'] = (int) ($meta['tries'] ?? 0) + 1;
    $meta['last_reject'] = $reason;
    $final = $final || $meta['tries'] >= COF_MAX_TRIES;
    cofSaveMeta($row['id_order'], $meta);
    $text = "❌ تراکنش شما تأیید نشد\n\n🛒 کد پیگیری: <code>{$row['id_order']}</code>\nعلت: " . cofReasonText($reason, $ctx) . "\n\n";
    $keyboard = null;
    if ($final) {
        $st = $pdo->prepare("UPDATE Payment_report SET payment_Status = 'reject' WHERE id_order = ? AND payment_Status = 'Unpaid'");
        $st->execute([$row['id_order']]);
        if (function_exists('clearSelectCache')) {
            clearSelectCache('Payment_report');
        }
        if (!empty($row['message_id'])) {
            deletemessage($row['id_user'], $row['message_id']);
        }
        $text .= "این فاکتور بسته شد. اگر واقعاً واریز کرده‌اید، کد پیگیری و هش تراکنش را برای پشتیبانی بفرستید.";
    } else {
        $text .= "اگر هش را اشتباه فرستاده‌اید، هش درست را دوباره ارسال کنید (" . (COF_MAX_TRIES - $meta['tries']) . " فرصت دیگر).";
        $keyboard = json_encode(['inline_keyboard' => [[['text' => '📨 ارسال دوباره TXID', 'callback_data' => "cofpaid-{$row['id_order']}"]]]]);
    }
    sendmessage($row['id_user'], $text, $keyboard, 'HTML');
    if ($txid !== null) {
        cofReportGroup("💎 پرداخت تتر آفلاین — رد شد ❌\n\n"
            . "👤 کاربر: " . cofUserLine((string) $row['id_user']) . "\n"
            . "🛒 سفارش: <code>{$row['id_order']}</code> — مقدار فاکتور {$meta['usdt']} USDT " . strtoupper($meta['net']) . "\n"
            . "🔗 TXID: " . cofTxLink($meta['net'], $txid) . "\n"
            . "علت: " . cofReasonText($reason, $ctx));
    }
}

/** Verify a user-supplied TXID. Returns state ok|pending|invalid (+reason). */
function cofCheckTxid(array $row, array $meta, string $txid): array
{
    global $pdo;
    cofEnsureTable();
    $st = $pdo->prepare("SELECT id_order FROM crypto_offline_tx WHERE txid = ?");
    $st->execute([$txid]);
    $owner = $st->fetchColumn();
    if ($owner !== false) {
        return ['state' => 'invalid', 'reason' => 'used'];
    }
    $v = $meta['net'] === 'trc20' ? cofTronTx($txid, $meta['addr']) : cofBscTx($txid, $meta['addr']);
    if ($v['state'] !== 'ok') {
        return $v;
    }
    if ($v['time'] < (int) $meta['created'] - 120) {
        return ['state' => 'invalid', 'reason' => 'early'];
    }
    if ($v['time'] > (int) $meta['created'] + COF_INVOICE_TTL + COF_GRACE) {
        return ['state' => 'invalid', 'reason' => 'late'];
    }
    if (!cofAmountMatches($v['amount'], $meta['usdt'])) {
        return ['state' => 'invalid', 'reason' => 'amount', 'ctx' => ['amount' => $v['amount'], 'expected' => $meta['usdt']]];
    }
    return cofSettle($row, $meta, $txid, $v['amount'], 'txid')
        ? ['state' => 'ok']
        : ['state' => 'invalid', 'reason' => 'used'];
}

function cofParseTxid(string $net, string $text): ?string
{
    if (!preg_match('/(?:0x)?([0-9a-fA-F]{64})/', $text, $m)) {
        return null;
    }
    return $net === 'bep20' ? '0x' . strtolower($m[1]) : strtolower($m[1]);
}

/** Cron: auto-match incoming transfers, finish pending TXIDs, expire stale invoices. */
function cofCron(): array
{
    global $pdo;
    $stats = ['pending' => 0, 'scanned' => 0, 'settled' => 0, 'rejected' => 0, 'expired' => 0];
    $pending = cofPending();
    $stats['pending'] = count($pending);
    if (!$pending) {
        return $stats;
    }
    cofEnsureTable();
    $isUsed = function ($txid) use ($pdo) {
        $st = $pdo->prepare("SELECT 1 FROM crypto_offline_tx WHERE txid = ?");
        $st->execute([$txid]);
        return $st->fetchColumn() !== false;
    };

    // 1) auto-scan, grouped per wallet
    $groups = [];
    foreach ($pending as $i => $p) {
        $groups[$p['meta']['net'] . '|' . $p['meta']['addr']][] = $i;
    }
    $done = [];
    foreach ($groups as $key => $idx) {
        [$net, $addr] = explode('|', $key, 2);
        $oldest = min(array_map(fn($i) => (int) $pending[$i]['meta']['created'], $idx));
        $txs = $net === 'trc20' ? cofTronIncoming($addr, $oldest - 120) : cofBscIncoming($addr);
        if (!is_array($txs)) {
            continue;
        }
        $stats['scanned'] += count($txs);
        foreach ($txs as $tx) {
            if ($isUsed($tx['txid'])) {
                continue;
            }
            foreach ($idx as $i) {
                if (isset($done[$i]) || !cofAmountMatches($tx['amount'], $pending[$i]['meta']['usdt'])) {
                    continue;
                }
                $m = $pending[$i]['meta'];
                if ($tx['time'] === null) {
                    $tx['time'] = cofBscBlockTime($tx['block']);
                    if ($tx['time'] === null) {
                        break;
                    }
                }
                if ($tx['time'] < (int) $m['created'] - 120 || $tx['time'] > (int) $m['created'] + COF_INVOICE_TTL + COF_GRACE) {
                    continue;
                }
                if (cofSettle($pending[$i]['row'], $m, $tx['txid'], $tx['amount'], 'scan')) {
                    $done[$i] = true;
                    $stats['settled']++;
                }
                break;
            }
        }
    }

    // 2) TXIDs that were still unconfirmed when the user sent them, 3) expiry
    foreach ($pending as $i => $p) {
        if (isset($done[$i])) {
            continue;
        }
        $row = $p['row'];
        $m = $p['meta'];
        if (!empty($m['txid_pending'])) {
            $r = cofCheckTxid($row, $m, $m['txid_pending']);
            if ($r['state'] === 'ok') {
                $stats['settled']++;
            } elseif ($r['state'] === 'invalid') {
                cofReject($row, $m, $r['reason'], $r['ctx'] ?? [], false, $m['txid_pending']);
                $stats['rejected']++;
            } elseif (time() - (int) $m['txid_at'] > COF_TXID_WAIT) {
                cofReject($row, $m, 'notfound', [], false, $m['txid_pending']);
                $stats['rejected']++;
            }
            continue;
        }
        if (time() > (int) $m['created'] + COF_INVOICE_TTL + COF_GRACE) {
            $st = $pdo->prepare("UPDATE Payment_report SET payment_Status = 'expire' WHERE id_order = ? AND payment_Status = 'Unpaid'");
            $st->execute([$row['id_order']]);
            if ($st->rowCount() === 1) {
                if (!empty($row['message_id'])) {
                    deletemessage($row['id_user'], $row['message_id']);
                }
                sendmessage($row['id_user'], "⌛ فاکتور پرداخت تتری <code>{$row['id_order']}</code> منقضی شد.\nبه این فاکتور واریز نکنید؛ برای پرداخت، فاکتور جدید بسازید.", null, 'HTML');
                $stats['expired']++;
            }
        }
    }
    if (function_exists('clearSelectCache')) {
        clearSelectCache('Payment_report');
    }
    return $stats;
}


/** Delete the one-time recovery-phrase message (after COF_SEED_TTL, or immediately with $now). */
function cofSeedCleanup(bool $now = false): void
{
    $m = json_decode(cofGet('cofseedmsg'), true);
    if (!is_array($m) || empty($m['mid'])) {
        return;
    }
    if (!$now && time() - (int) $m['t'] < COF_SEED_TTL) {
        return;
    }
    telegram('deleteMessage', ['chat_id' => $m['chat'], 'message_id' => (int) $m['mid']]);
    cofSet('cofseedmsg', '0');
}
