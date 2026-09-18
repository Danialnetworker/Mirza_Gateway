<?php

/**
 * Cloudius RADIUS panel driver for MirzaBot.
 *
 * Verified against a live Cloudius server. Hard-won facts baked in here:
 *   - The JSON API lives on port 80 at the ROOT path (/Self/Login, /User/Fetch...).
 *     Port 8077 is the uniGUI web console and answers every API path with an
 *     HTML "Invalid URI" page, which looks like a wrong path but is a wrong port.
 *   - Auth header is `Authorization: Bearer <token>`. A StaticToken MUST keep its
 *     surrounding '#' characters or the server replies -103 LOGIN FIRST.
 *   - Success is Status === 0 in the JSON body. HTTP is always 200.
 *   - /User/Add does NOT return an id, and almost every other endpoint needs a
 *     numeric UserID, so username -> UserID goes through /User/FetchByUserName.
 *   - /User/ChangeActive DOES NOT EXIST (-101 Invalid API). Enable/disable is
 *     done with /User/Edit, where GroupID is mandatory and must be preserved.
 */

if (!defined('CLOUDIUS_TIMEOUT')) {
    define('CLOUDIUS_TIMEOUT', 15);
}

/**
 * Normalise the panel URL. Cloudius serves its API on port 80; the uniGUI
 * console on 8077 answers with HTML and would break every call, so a URL that
 * still points at the console is corrected here.
 */
function cloudius_base_url($url)
{
    $url = trim((string) $url);
    if ($url === '') {
        return '';
    }
    if (!preg_match('~^https?://~i', $url)) {
        $url = 'http://' . $url;
    }
    $url = rtrim($url, '/');
    // The uniGUI console port never speaks JSON - fall back to the API port.
    $url = preg_replace('~:8077$~', '', $url);

    return $url;
}

/**
 * Detect a Cloudius token supplied in place of the password.
 * Accepts the wrapped StaticToken (#...#) or a bare 32-hex token.
 * Anything else (a real password) returns null.
 */
function cloudius_token_from_password($password)
{
    $password = trim((string) $password);
    if ($password === '') {
        return null;
    }
    if (preg_match('~^#?[0-9A-Fa-f]{32}#?$~', $password)) {
        return cloudius_normalise_token($password);
    }

    return null;
}

/**
 * A 32 hex char StaticToken is only accepted wrapped in '#'.
 */
function cloudius_normalise_token($token)
{
    $token = trim((string) $token);
    if ($token === '') {
        return '';
    }
    if (preg_match('~^[0-9A-Fa-f]{32}$~', $token)) {
        return '#' . $token . '#';
    }

    return $token;
}

/**
 * Raw JSON call. Returns ['status'=>bool,'msg'=>string,'data'=>array,'raw'=>array].
 */
function cloudius_http($baseUrl, $endpoint, $params, $token = null)
{
    $baseUrl = cloudius_base_url($baseUrl);
    if ($baseUrl === '') {
        return array('status' => false, 'msg' => 'آدرس پنل کلودیوس خالی است', 'data' => array());
    }

    if (!isset($params['LanguageID'])) {
        $params['LanguageID'] = 1;
    }

    $headers = array('Content-Type: application/json', 'Accept: application/json');
    if ($token !== null && $token !== '') {
        $headers[] = 'Authorization: Bearer ' . cloudius_normalise_token($token);
    }

    $timeout = ($GLOBALS['request_exec_timeout'] ?? null) ? (int) ceil($GLOBALS['request_exec_timeout'] / 1000) : CLOUDIUS_TIMEOUT;
    if ($timeout < 5) {
        $timeout = CLOUDIUS_TIMEOUT;
    }

    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => $baseUrl . '/' . ltrim($endpoint, '/'),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($params, JSON_UNESCAPED_UNICODE),
    ));

    $response = curl_exec($curl);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($response === false) {
        return array('status' => false, 'msg' => 'اتصال به پنل کلودیوس برقرار نشد: ' . $curlError, 'data' => array());
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        // The uniGUI console (port 8077) answers HTML instead of JSON.
        $hint = (stripos($response, 'Invalid URI') !== false)
            ? 'پاسخ JSON نبود - احتمالا پورت پنل اشتباه است (API روی پورت 80 است نه 8077)'
            : 'پاسخ نامعتبر از پنل کلودیوس';

        return array('status' => false, 'msg' => $hint, 'data' => array());
    }

    $ok = isset($decoded['Status']) && (int) $decoded['Status'] === 0;

    return array(
        'status' => $ok,
        'code' => isset($decoded['Status']) ? (int) $decoded['Status'] : -1,
        'msg' => isset($decoded['Message']) ? $decoded['Message'] : '',
        'data' => isset($decoded['Data']) && is_array($decoded['Data']) ? $decoded['Data'] : array(),
        'total' => isset($decoded['TotalDataCount']) ? (int) $decoded['TotalDataCount'] : 0,
    );
}

/**
 * Connectivity/credentials check used when an admin registers the panel.
 */
function login_cloudius($url, $username, $password)
{
    // Token mode: the operator pastes the StaticToken instead of the password,
    // so no login round-trip is needed - validate the token with a cheap call.
    $directToken = cloudius_token_from_password($password);
    if ($directToken !== null) {
        $probe = cloudius_http($url, 'Group/Fetch', array('PageNo' => 1, 'RowPerPage' => 1), $directToken);
        if (!$probe['status']) {
            return array('status' => false, 'msg' => $probe['msg'] ?: 'توکن کلودیوس نامعتبر است');
        }

        return array(
            'status' => true,
            'msg' => 'Successful login (token)',
            'token' => $directToken,
            'data' => array('UserName' => $username),
        );
    }

    $result = cloudius_http($url, 'Self/Login', array(
        'UserName' => $username,
        'Password' => $password,
    ));

    if (!$result['status']) {
        return array('status' => false, 'msg' => $result['msg'] ?: 'ورود به پنل کلودیوس ناموفق بود');
    }

    $row = isset($result['data'][0]) ? $result['data'][0] : array();
    $token = '';
    if (!empty($row['StaticToken'])) {
        $token = $row['StaticToken'];
    } elseif (!empty($row['Token'])) {
        $token = $row['Token'];
    }

    if ($token === '') {
        return array('status' => false, 'msg' => 'توکن در پاسخ کلودیوس یافت نشد');
    }

    return array(
        'status' => true,
        'msg' => 'Successful login',
        'token' => $token,
        'data' => $row,
    );
}

/**
 * Fetch the stored panel row.
 */
function cloudius_panel($name_panel)
{
    if (is_array($name_panel)) {
        return $name_panel;
    }

    return select("marzban_panel", "*", "name_panel", $name_panel, "select");
}

/**
 * Return a usable token, logging in (and caching into `datelogin`) when needed.
 * No schema change: `datelogin` is the column other panels already use for
 * session material.
 */
function cloudius_token($panel, $force = false)
{
    if (!is_array($panel)) {
        return '';
    }

    if (!$force && !empty($panel['datelogin'])) {
        return $panel['datelogin'];
    }

    // Token mode: password_panel holds the StaticToken - use it directly.
    $directToken = cloudius_token_from_password($panel['password_panel'] ?? '');
    if ($directToken !== null) {
        if (!empty($panel['name_panel'])) {
            update("marzban_panel", "datelogin", $directToken, "name_panel", $panel['name_panel']);
        }

        return $directToken;
    }

    $login = login_cloudius($panel['url_panel'], $panel['username_panel'], $panel['password_panel']);
    if (!$login['status']) {
        return '';
    }

    if (!empty($panel['name_panel'])) {
        update("marzban_panel", "datelogin", $login['token'], "name_panel", $panel['name_panel']);
    }

    return $login['token'];
}

/**
 * Authenticated call with one automatic re-login when the cached token died.
 */
function cloudius_api($name_panel, $endpoint, $params = array())
{
    $panel = cloudius_panel($name_panel);
    if (empty($panel) || empty($panel['url_panel'])) {
        return array('status' => false, 'msg' => 'پنل کلودیوس یافت نشد', 'data' => array());
    }

    $token = cloudius_token($panel);
    if ($token === '') {
        return array('status' => false, 'msg' => 'ورود به پنل کلودیوس ناموفق بود', 'data' => array());
    }

    $result = cloudius_http($panel['url_panel'], $endpoint, $params, $token);

    // -103 LOGIN FIRST => cached token expired, refresh once.
    if (!$result['status'] && isset($result['code']) && (int) $result['code'] === -103) {
        $token = cloudius_token($panel, true);
        if ($token === '') {
            return array('status' => false, 'msg' => 'ورود مجدد به پنل کلودیوس ناموفق بود', 'data' => array());
        }
        $result = cloudius_http($panel['url_panel'], $endpoint, $params, $token);
    }

    return $result;
}

/**
 * username -> full user row. Cloudius keys everything off a numeric UserID.
 */
function cloudius_find_user($name_panel, $username)
{
    $result = cloudius_api($name_panel, 'User/FetchByUserName', array('UserName' => $username));
    if (!$result['status']) {
        return array('status' => false, 'msg' => $result['msg']);
    }
    if (empty($result['data'][0])) {
        return array('status' => false, 'msg' => 'کاربر در پنل کلودیوس یافت نشد');
    }

    return array('status' => true, 'data' => $result['data'][0]);
}

function cloudius_user_id($name_panel, $username)
{
    $user = cloudius_find_user($name_panel, $username);
    if (!$user['status']) {
        return 0;
    }

    return isset($user['data']['UserID']) ? (int) $user['data']['UserID'] : 0;
}

/**
 * Cloudius reports traffic as a human string ("49.86 GB"); Mirza needs bytes.
 */
function cloudius_traffic_to_bytes($value)
{
    if ($value === null || $value === '') {
        return 0;
    }
    if (is_numeric($value)) {
        return (float) $value;
    }

    if (!preg_match('~([0-9]*\.?[0-9]+)\s*([KMGTP]?B)?~i', (string) $value, $m)) {
        return 0;
    }

    $number = (float) $m[1];
    $unit = isset($m[2]) ? strtoupper($m[2]) : 'B';
    $factors = array('B' => 1, 'KB' => 1024, 'MB' => 1048576, 'GB' => 1073741824, 'TB' => 1099511627776, 'PB' => 1125899906842624);

    return $number * ($factors[$unit] ?? 1);
}

/**
 * Create a user. $group is the Cloudius GroupID (stored in the panel's inboundid).
 */
function addUser_cloudius($name_panel, $username, $password, $group, $traffic = 0, $days = 0)
{
    $panel = cloudius_panel($name_panel);
    if (empty($panel)) {
        return array('status' => false, 'msg' => 'پنل کلودیوس یافت نشد');
    }

    $groupId = (int) ($group !== '' && $group !== null ? $group : $panel['inboundid']);
    if ($groupId <= 0) {
        return array('status' => false, 'msg' => 'شناسه گروه کلودیوس تنظیم نشده است');
    }

    $params = array(
        'Title' => $username,
        'UserName' => $username,
        'Password' => $password,
        'GroupID' => $groupId,
        'IsActive' => true,
    );

    if ($traffic > 0) {
        $params['Traffic'] = (float) $traffic;
        $params['TrafficType'] = 'GB';
        $params['IsTrafficBase'] = true;
    }
    if ($days > 0) {
        $params['ExpireType'] = 1;   // Relative - counts from first connection
        $params['ExpirePeriod'] = 2; // Day
        $params['ExpireLength'] = (int) $days;
    }

    $result = cloudius_api($panel, 'User/Add', $params);
    if (!$result['status']) {
        return array('status' => false, 'msg' => $result['msg']);
    }

    return array('status' => true, 'msg' => 'Successful', 'data' => $result['data'][0] ?? array());
}

/**
 * Read the CURRENT password from Cloudius.
 *
 * User/FetchByUserName masks it ('XXXXX'), but User/Fetch keyed by the numeric
 * UserID returns it in clear - verified against a manually changed account.
 * Returns '' when unavailable or still masked.
 */
function cloudius_live_password($name_panel, $userId)
{
    $userId = (int) $userId;
    if ($userId <= 0) {
        return '';
    }

    $result = cloudius_api($name_panel, 'User/Fetch', array(
        'UserID' => $userId,
        'PageNo' => 1,
        'RowPerPage' => 1,
    ));
    if (empty($result['status']) || empty($result['data'][0])) {
        return '';
    }

    $pw = (string) ($result['data'][0]['Password'] ?? '');
    if ($pw === '' || preg_match('/^X+$/i', $pw)) {
        return '';
    }

    return $pw;
}

/**
 * Read a user back in a normalised shape for panels.php.
 */
function GetUser_cloudius($name_panel, $username)
{
    $user = cloudius_find_user($name_panel, $username);
    if (!$user['status']) {
        return array('status' => false, 'msg' => $user['msg']);
    }

    $row = $user['data'];
    $remainingBytes = cloudius_traffic_to_bytes($row['RemainedTraffic'] ?? 0);
    $remainingDays = isset($row['RemainedTime']) ? (int) $row['RemainedTime'] : 0;
    // Cloudius returns ExpirationTime as a JALALI string ('1405/07/24 16:39:36').
    // strtotime() would read that as Gregorian year 1405 and produce a large
    // NEGATIVE timestamp, which Mirza then renders as year 784. Never parse it -
    // RemainedTime (days) is the reliable source for both Absolute and Relative.
    $expire = 0;
    if ($remainingDays > 0) {
        $expire = time() + ($remainingDays * 86400);
    }

    return array(
        'status' => true,
        'data' => array(
            'UserID' => (int) ($row['UserID'] ?? 0),
            'username' => $row['UserName'] ?? $username,
            'enable' => !empty($row['IsActive']) ? 'active' : 'disabled',
            'is_active' => !empty($row['IsActive']),
            'expired' => !empty($row['Expired']),
            'group_id' => (int) ($row['GroupID'] ?? 0),
            'group_name' => $row['GroupName'] ?? '',
            'remained_traffic' => $remainingBytes,
            'remained_days' => $remainingDays,
            'expire' => $expire,
            'online_count' => (int) ($row['OnlineCount'] ?? 0),
            // FetchByUserName masks the password; User/Fetch by UserID does not.
            'password' => cloudius_live_password($name_panel, $row['UserID'] ?? 0),
            'raw' => $row,
        ),
    );
}

/**
 * Enable/disable. Cloudius has no ChangeActive endpoint, so User/Edit is used -
 * and GroupID is mandatory there, so the current group is read first and kept.
 */
function changeActive_cloudius($name_panel, $username, $isActive)
{
    $user = cloudius_find_user($name_panel, $username);
    if (!$user['status']) {
        return array('status' => false, 'msg' => $user['msg']);
    }

    $row = $user['data'];
    $result = cloudius_api($name_panel, 'User/Edit', array(
        'UserID' => (int) $row['UserID'],
        'GroupID' => (int) $row['GroupID'], // mandatory - dropping it corrupts the user
        'IsActive' => (bool) $isActive,
    ));

    return array('status' => $result['status'], 'msg' => $result['msg']);
}

function deleteUser_cloudius($name_panel, $username)
{
    $userId = cloudius_user_id($name_panel, $username);
    if ($userId <= 0) {
        return array('status' => false, 'msg' => 'کاربر در پنل کلودیوس یافت نشد');
    }

    $result = cloudius_api($name_panel, 'User/Remove', array(
        'UserID' => (string) $userId,
        'ForceRemove' => true,
    ));

    return array('status' => $result['status'], 'msg' => $result['msg']);
}

function addTraffic_cloudius($name_panel, $username, $trafficGb)
{
    $userId = cloudius_user_id($name_panel, $username);
    if ($userId <= 0) {
        return array('status' => false, 'msg' => 'کاربر در پنل کلودیوس یافت نشد');
    }

    $result = cloudius_api($name_panel, 'User/Traffic/Add', array(
        'UserID' => (string) $userId,
        'Traffic' => (float) $trafficGb,
        'TrafficType' => 'GB',
    ));

    return array('status' => $result['status'], 'msg' => $result['msg']);
}

function addTime_cloudius($name_panel, $username, $days)
{
    $userId = cloudius_user_id($name_panel, $username);
    if ($userId <= 0) {
        return array('status' => false, 'msg' => 'کاربر در پنل کلودیوس یافت نشد');
    }

    $result = cloudius_api($name_panel, 'User/Time/Add', array(
        'UserID' => (string) $userId,
        'ExpirePeriod' => 2, // Day
        'ExpireLength' => (int) $days,
    ));

    return array('status' => $result['status'], 'msg' => $result['msg']);
}

function resetTraffic_cloudius($name_panel, $username)
{
    $userId = cloudius_user_id($name_panel, $username);
    if ($userId <= 0) {
        return array('status' => false, 'msg' => 'کاربر در پنل کلودیوس یافت نشد');
    }

    $result = cloudius_api($name_panel, 'User/Consume/Reset', array('UserID' => (string) $userId));

    return array('status' => $result['status'], 'msg' => $result['msg']);
}

function setPassword_cloudius($name_panel, $username, $password)
{
    $userId = cloudius_user_id($name_panel, $username);
    if ($userId <= 0) {
        return array('status' => false, 'msg' => 'کاربر در پنل کلودیوس یافت نشد');
    }

    $result = cloudius_api($name_panel, 'User/SetPassword', array(
        'UserID' => $userId,
        'Password' => $password,
    ));

    return array('status' => $result['status'], 'msg' => $result['msg']);
}

/**
 * Renew: moves the user to a group and resets time/traffic/consumption.
 */
function renewUser_cloudius($name_panel, $username, $group = null, $traffic = 0, $days = 0)
{
    $panel = cloudius_panel($name_panel);
    if (empty($panel)) {
        return array('status' => false, 'msg' => 'پنل کلودیوس یافت نشد');
    }

    $user = cloudius_find_user($panel, $username);
    if (!$user['status']) {
        return array('status' => false, 'msg' => $user['msg']);
    }

    $groupId = (int) ($group !== null && $group !== '' ? $group : ($panel['inboundid'] ?: $user['data']['GroupID']));
    if ($groupId <= 0) {
        $groupId = (int) $user['data']['GroupID'];
    }

    $params = array(
        'UserID' => (string) $user['data']['UserID'],
        'NewGroupID' => $groupId,
        'ResetTime' => true,
        'ResetTraffic' => true,
        'ResetConsume' => true,
        'ResetFirstLogin' => true,
    );

    if ($traffic > 0) {
        $params['Traffic'] = (float) $traffic;
        $params['TrafficType'] = 'GB';
        $params['IsTrafficBase'] = true;
    }
    if ($days > 0) {
        $params['ExpirePeriod'] = 2;
        $params['ExpireLength'] = (int) $days;
    }

    $result = cloudius_api($panel, 'User/Renew/Add', $params);

    return array('status' => $result['status'], 'msg' => $result['msg']);
}

function onlineUsers_cloudius($name_panel)
{
    $result = cloudius_api($name_panel, 'OnlineUser/Fetch', array('PageNo' => 1, 'RowPerPage' => 500));
    if (!$result['status']) {
        return array('status' => false, 'msg' => $result['msg'], 'data' => array());
    }

    return array('status' => true, 'data' => $result['data'], 'total' => $result['total']);
}

function kickUser_cloudius($name_panel, $username)
{
    $userId = cloudius_user_id($name_panel, $username);
    if ($userId <= 0) {
        return array('status' => false, 'msg' => 'کاربر در پنل کلودیوس یافت نشد');
    }

    $result = cloudius_api($name_panel, 'OnlineUser/Kick', array('UserID' => (string) $userId));

    return array('status' => $result['status'], 'msg' => $result['msg']);
}

/**
 * Full admin dashboard aggregates - powers the "panel status" screen.
 */
function dashboard_cloudius($name_panel)
{
    $result = cloudius_api($name_panel, 'Self/Dashboard', array());
    if (!$result['status'] || empty($result['data'][0])) {
        return array('status' => false, 'msg' => $result['msg']);
    }

    $d = $result['data'][0];
    $num = function ($key) use ($d) {
        return (int) str_replace(',', '', (string) ($d[$key] ?? 0));
    };

    return array(
        'status' => true,
        'data' => array(
            'username' => $d['UserName'] ?? '',
            'title' => $d['Title'] ?? '',
            'role' => $d['Role'] ?? '',
            'license_from' => $d['ActiveFrom'] ?? '',
            'license_to' => $d['ActiveTo'] ?? '',
            'total_users' => $num('TotalUserCount'),
            'active_users' => $num('ActiveUserCount'),
            'deactive_users' => $num('DeactiveUserCount'),
            'expired_users' => $num('ActiveExpiredUserCount'),
            'live_users' => $num('ActiveLiveUserCount'),
            'online_now' => $num('CurrentOnlineCount'),
            'user_limit' => $num('LimitUserCount'),
            'online_limit' => $num('LimitOnlineUserCount'),
            'ras_total' => $num('TotalRasCount'),
            'ras_active' => $num('ActiveRasCount'),
            'log_count' => $num('LogCount'),
            'wallet' => $d['WalletRemained'] ?? '0',
            'profit' => $d['Profit'] ?? '0',
            'raw' => $d,
        ),
    );
}

/**
 * Group list - used by the admin UI so the operator can pick a GroupID.
 */
function groups_cloudius($name_panel)
{
    $result = cloudius_api($name_panel, 'Group/Fetch', array('PageNo' => 1, 'RowPerPage' => 200));
    if (!$result['status']) {
        return array('status' => false, 'msg' => $result['msg'], 'data' => array());
    }

    $groups = array();
    foreach ($result['data'] as $row) {
        $groups[] = array(
            'id' => (int) ($row['GroupID'] ?? 0),
            'title' => $row['Title'] ?? '',
            'traffic' => $row['Traffic'] ?? '',
            'days' => (int) ($row['ExpireLength'] ?? 0),
            'active' => !empty($row['IsActive']),
        );
    }

    return array('status' => true, 'data' => $groups, 'total' => $result['total']);
}
