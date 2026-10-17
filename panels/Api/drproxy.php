<?php

/**
 * DrProxy reseller panel (V2Ray + WireGuard + OpenVPN/L2TP behind one link) — bot API client.
 *
 *   url_panel      = https://sub2.nimbaha.org   (the panel address shown in the reseller dashboard)
 *   password_panel = drpb_...                   (the reseller's bot token: dashboard > settings > Mirza bot)
 *
 * Every call is pinned server-side to that reseller. Users are addressed by username.
 * Answers: {success, msg, obj, code}. Only calls that are safe to repeat are retried
 * (GET, DELETE, create: the panel returns the same user for a repeat within 15 min).
 */

function drproxy_call(array $panel, $method, $path, $body = null)
{
    $url = rtrim((string) ($panel['url_panel'] ?? ''), '/') . '/rapi/bot' . $path;
    $safe = $method === 'GET' || $method === 'DELETE' || ($method === 'POST' && $path === '/users');
    $last = array('ok' => false, 'code' => 0, 'msg' => 'DrProxy: no response', 'obj' => null, 'err' => 'network');
    for ($try = 1; $try <= 3; $try++) {
        $headers = array('Accept: application/json', 'Authorization: Bearer ' . trim((string) ($panel['password_panel'] ?? '')));
        $opt = array(CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
                     CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 120);   // a create touches 3 servers
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $opt[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        }
        $opt[CURLOPT_HTTPHEADER] = $headers;
        $ch = curl_init($url);
        curl_setopt_array($ch, $opt);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);
        $j = is_string($raw) ? json_decode($raw, true) : null;
        if ($raw === false || $code === 0 || $code >= 500 || !is_array($j)) {
            $last = array('ok' => false, 'code' => $code, 'obj' => null, 'err' => 'network',
                          'msg' => $raw === false ? 'DrProxy: ' . $cerr : 'DrProxy: HTTP ' . $code);
            if ($safe && $try < 3) {
                sleep(2 * $try);
                continue;
            }
            return $last;
        }
        return array('ok' => !empty($j['success']), 'code' => $code, 'msg' => (string) ($j['msg'] ?? ''),
                      'obj' => $j['obj'] ?? null, 'err' => (string) ($j['code'] ?? ''));
    }
    return $last;
}

/** Product services text ("v2ray, wg" / JSON list / "all") -> list. null = something unknown in it. */
function drproxy_services($raw)
{
    $raw = trim((string) $raw);
    if ($raw === '' || in_array(strtolower($raw), array('all', 'همه', 'null', '-'), true)) {
        return array();
    }
    $j = json_decode($raw, true);
    $list = is_array($j) ? $j : preg_split('/[\s,،]+/u', $raw);
    $alias = array('v2ray' => 'v2ray', 'v2' => 'v2ray', 'vless' => 'v2ray', 'wg' => 'wg', 'wireguard' => 'wg',
                   'openvpn' => 'openvpn', 'ovpn' => 'openvpn', 'l2tp' => 'l2tp');
    $out = array();
    foreach ($list as $s) {
        $s = strtolower(trim((string) $s));
        if ($s === '') {
            continue;
        }
        if (!isset($alias[$s])) {
            return null;
        }
        $out[] = $alias[$s];
    }
    return array_values(array_unique($out));
}

/** Short status for the admin (panel added / panel status). */
/** How to choose the services of a product (shown in panel status and in «set inbound»). */
function drproxy_services_guide($svc = '')
{
    global $textbotlang;
    $t = $textbotlang['Admin']['managepanel']['drproxyServicesGuide'] ?? "💡 <b>سرویس هر محصول</b>\nدر «مدیریت محصول» ← «تنظیم اینباند» سرویس‌ها را با کاما بفرستید:\n• <code>v2ray,wg</code> یا <code>openvpn,l2tp</code> یا فقط <code>v2ray</code>\n• خالی یا <code>all</code> = همه‌ی سرویس‌هایی که نماینده دارد\nهر خرید یک لینک /u/ می‌گیرد که همه‌ی سرویس‌های آن محصول داخلش است.";
    return $svc !== '' ? str_replace('{services}', $svc, $t) : str_replace(' ({services})', '', $t);
}

function drproxy_status_text($o, $url = '')
{
    $gib = 1073741824;
    $q = is_array($o['quota'] ?? null) ? $o['quota'] : array();
    $left = array_key_exists('remaining_bytes', $q) && $q['remaining_bytes'] !== null ? round($q['remaining_bytes'] / $gib, 1) . ' GB' : '∞';
    $used = round(((int) ($q['used_bytes'] ?? 0)) / $gib, 1) . ' GB';
    $days = !empty($o['expires_at']) ? max(0, (int) ceil(($o['expires_at'] - time()) / 86400)) : null;
    $name = htmlspecialchars((string) ($o['reseller']['name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $code = htmlspecialchars((string) ($o['reseller']['code'] ?? ''), ENT_QUOTES, 'UTF-8');
    $svc = htmlspecialchars(implode(', ', (array) ($o['services'] ?? array())), ENT_QUOTES, 'UTF-8');
    $u = $url !== '' ? "🌐 <code>" . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . "</code>\n" : '';
    return "✅ <b>DrProxy</b>\n{$u}👤 {$name} ({$code})\n🧩 {$svc}\n📦 {$used} / {$left}\n⏳ " . ($days === null ? '∞' : $days)
        . "\n\n" . drproxy_services_guide($svc);
}
