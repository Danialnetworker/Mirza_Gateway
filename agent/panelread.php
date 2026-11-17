<?php
// [agent-panelread] Read access for the agent to EVERY panel the bot sells on, using the panel logins the bot
// already has in marzban_panel. READ ONLY, enforced here and not by the prompt: every panel request of this file
// goes through agent_pr_allowed(), an allow-list of read calls per panel type. Anything else is refused before
// a request is made, so no tool of the agent can create, edit, renew, reset, kick or delete anything.
//   service_details   everything the panel knows about ONE service of the asking customer
//   network_status    are our panels / servers / locations healthy right now (hourly monitor + live panel state)
// Passwords, keys, links and configs are removed before the result reaches the model.

const AGENT_PR_ALLOW = [
    'cloudius' => ['POST' => ['#^/?User/FetchByUserName$#', '#^/?History/Fetch$#', '#^/?Log/Fetch$#', '#^/?Self/Dashboard$#']],
    'x-ui_single' => ['GET' => ['#^/panel/api/clients/get/[A-Za-z0-9_.@-]+$#', '#^/panel/api/clients/traffic/[A-Za-z0-9_.@-]+$#',
        '#^/panel/api/server/status$#', '#^/panel/api/inbounds/list$#']],
    'drproxy' => ['GET' => ['#^/users/[A-Za-z0-9_.%@-]+$#', '#^/ping$#']],
];

function agent_pr_allowed($type, $method, $path)
{
    foreach (AGENT_PR_ALLOW[strtolower((string) $type)][strtoupper((string) $method)] ?? [] as $re)
        if (preg_match($re, (string) $path))
            return true;
    return false;
}

// the only door to a panel. Returns the decoded payload or null (refused / failed).
function agent_pr_read(array $panel, $method, $path, array $params = [])
{
    $type = strtolower((string) ($panel['type'] ?? ''));
    if (!agent_pr_allowed($type, $method, $path)) {
        agent_log("panelread REFUSED $type $method $path");
        return null;
    }
    if ($type === 'cloudius') {
        $r = cloudius_api($panel['name_panel'], $path, $params);
        return !empty($r['status']) ? ($r['data'] ?? []) : null;
    }
    if ($type === 'x-ui_single') {
        $q = new CurlRequest(rtrim((string) $panel['url_panel'], '/') . $path);
        $q->setHeaders(['Accept: application/json']);
        $q->setBearerToken($panel['password_panel']);
        $x = $q->get();
        $b = json_decode((string) ($x['body'] ?? ''), true);
        return is_array($b) && !empty($b['success']) ? ($b['obj'] ?? []) : null;
    }
    if ($type === 'drproxy') {
        $x = drproxy_call($panel, 'GET', $path);
        return !empty($x['ok']) ? $x['obj'] : null;
    }
    return null;
}

function agent_pr_ms($ms)
{
    $ms = (float) $ms;
    return $ms > 0 ? agent_ago((int) ($ms / 1000)) : 'never';
}

function agent_pr_cloudius(array $panel, $username)
{
    $d = agent_pr_read($panel, 'POST', 'User/FetchByUserName', ['UserName' => $username]);
    if ($d === null)
        return ['error' => 'the login server did not answer'];
    if (empty($d[0]))
        return ['on_panel' => false, 'meaning' => 'this username does not exist on the login server (deleted, or never created): our side, hand off to tech'];
    $u = $d[0];
    return ['on_panel' => true, 'enabled' => !empty($u['IsActive']), 'expired' => !empty($u['Expired']),
        'remaining_volume' => (string) ($u['RemainedTraffic'] ?? ''), 'days_left' => (string) ($u['RemainedTime'] ?? ''),
        'created' => (string) ($u['CreationTime'] ?? ''), 'first_login' => ($u['FirstLogin'] ?? '') !== '' ? (string) $u['FirstLogin'] : 'never logged in',
        'expires' => (string) ($u['ExpirationTime'] ?? ''), 'plan_length' => ($u['ExpireLength'] ?? '') . ' ' . ($u['ExpirePeriodDesc'] ?? ''),
        'time_counts_from' => (int) ($u['ExpireType'] ?? 0) === 1 ? 'first login' : 'creation',
        'online_sessions_now' => (int) ($u['OnlineCount'] ?? 0),
        'max_simultaneous_connections' => (int) ($u['LimitOnlineUser'] ?? 0) ?: 'no limit',
        'device_limit' => (int) ($u['LimitLoginDevices'] ?? 0) ?: 'no limit', 'devices_used' => (int) ($u['UsedLoginDevices'] ?? 0),
        'speed_limit' => in_array((string) ($u['RateLimit'] ?? ''), ['', '0', '0/0'], true) ? 'none' : (string) $u['RateLimit'],
        'dates' => 'Jalali, Tehran time'];
}

function agent_pr_xui(array $panel, $username)
{
    $c = agent_pr_read($panel, 'GET', '/panel/api/clients/get/' . $username);
    if ($c === null)
        return ['on_panel' => false, 'meaning' => 'the V2Ray panel does not have this client or did not answer; retry once, then hand off to tech'];
    $cl = $c['client'] ?? [];
    $t = agent_pr_read($panel, 'GET', '/panel/api/clients/traffic/' . $username) ?: [];
    $total = (float) ($cl['totalGB'] ?? 0);
    $used = (float) ($c['usedTraffic'] ?? ((float) ($t['up'] ?? 0) + (float) ($t['down'] ?? 0)));
    $exp = (float) ($cl['expiryTime'] ?? 0);
    $o = ['on_panel' => true, 'enabled' => !empty($cl['enable']),
        'total' => $total > 0 ? agent_ro_gb($total) : 'unlimited', 'used' => agent_ro_gb($used),
        'download' => agent_ro_gb((float) ($t['down'] ?? 0)), 'upload' => agent_ro_gb((float) ($t['up'] ?? 0)),
        'expires' => $exp > 0 ? ($exp / 1000 < time() ? 'EXPIRED ' . agent_pr_ms($exp) : 'in ' . (int) floor(($exp / 1000 - time()) / 86400) . ' days')
            : ($exp < 0 ? 'starts counting at first connection (' . (int) round(-$exp / 86400000) . ' days)' : 'no expiry'),
        'created' => agent_pr_ms($cl['createdAt'] ?? 0),
        'last_online' => agent_pr_ms($t['lastOnline'] ?? 0),
        'last_time_the_app_updated_the_subscription' => agent_pr_ms($t['lastSubFetch'] ?? 0),
        'ip_limit' => (int) ($cl['limitIp'] ?? 0) ?: 'no limit',
        'locations_in_subscription' => count($c['inboundIds'] ?? [])];
    if ($total > 0)
        $o['remaining'] = agent_ro_gb(max(0, $total - $used));
    $o['hints'] = 'last_online = never means the app never connected with this link. If last_time_the_app_updated_the_subscription is old or never, the app still has old servers: tell them to press Update subscription.';
    return $o;
}

function agent_pr_drproxy(array $panel, $username)
{
    $x = agent_pr_read($panel, 'GET', '/users/' . rawurlencode(strtolower($username)));
    $u = is_array($x) ? ($x['user'] ?? null) : null;
    if (!is_array($u))
        return ['on_panel' => false, 'meaning' => 'the Magic MAX panel does not have this user or did not answer; retry once, then hand off to tech'];
    $exp = (int) ($u['expires_at'] ?? 0);
    return ['on_panel' => true, 'state' => (string) ($u['status'] ?? ''), 'enabled' => !empty($u['enabled']),
        'total' => !empty($u['data_limit_bytes']) ? agent_ro_gb($u['data_limit_bytes']) : 'unlimited', 'used' => agent_ro_gb($u['used_bytes'] ?? 0),
        'remaining' => isset($u['remaining_bytes']) ? agent_ro_gb($u['remaining_bytes']) : 'unlimited',
        'expires' => $exp > 0 ? ($exp < time() ? 'EXPIRED ' . agent_ago($exp) : 'in ' . (int) floor(($exp - time()) / 86400) . ' days') : 'no expiry',
        'time_starts_at_first_use' => !empty($u['start_on_first_use_days']), 'ip_limit' => (int) ($u['ip_limit'] ?? 0) ?: 'no limit',
        'protocols_in_this_account' => array_values(array_filter((array) ($u['services'] ?? []), 'is_string')),
        'created' => agent_ago((int) ($u['created_at'] ?? 0))];
}

function agent_tool_service_details($uid, $username)
{
    $username = strtolower(trim(preg_replace('/\s+/u', '', (string) $username)));
    $row = null;
    foreach (agent_ro_rows($uid, 200) as $r)
        if ($username !== '' && strtolower($r['username']) === $username)
            $row = $r;
    if (!$row)
        return ['error' => 'this username is not a service of this customer: call my_services and ask which one'];
    $panel = select('marzban_panel', '*', 'name_panel', $row['Service_location'], 'select');
    if (!is_array($panel))
        return ['error' => 'the panel of this service is not defined in the bot any more: hand off to tech'];
    $type = strtolower((string) $panel['type']);
    $o = ['username' => $row['username'], 'plan' => trim((string) $row['name_product']), 'panel' => $row['Service_location'],
        'protocol' => agent_ro_protocol($panel), 'bought' => agent_ago((int) $row['time_sell']), 'state_in_bot' => (string) $row['Status'],
        'panel_selling_state' => (string) ($panel['status'] ?? '')];
    if ($type === 'cloudius')
        $o['panel_record'] = agent_pr_cloudius($panel, $row['username']);
    elseif ($type === 'x-ui_single')
        $o['panel_record'] = agent_pr_xui($panel, $row['username']);
    elseif ($type === 'drproxy')
        $o['panel_record'] = agent_pr_drproxy($panel, $row['username']);
    else {
        $raw = null;
        $o['panel_record'] = array_diff_key(agent_ro_live($row, $raw), ['username' => 1, 'plan' => 1, 'panel' => 1, 'protocol' => 1, 'bought' => 1]);
    }
    if ($type === 'cloudius' && function_exists('agent_login_errors')) {
        $ll = agent_login_errors($row['username'], true);
        if (is_array($ll))
            $o['rejected_logins_last_24h'] = $ll;
    }
    $o['how_to_use'] = 'This is what our panel really has for this service. Compare it with what the customer says (never connected? expired? disabled? device limit reached? subscription not updated?) and explain what you found in plain Persian. A record that is disabled, missing or different from the bot while the bot shows the service active is OUR side: hand off to tech with these facts.';
    return $o;
}

function agent_tool_network_status()
{
    global $pdo;
    $o = [];
    $f = AGENT_DIR . '/health.json';
    $h = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    if (is_array($h) && !empty($h['items'])) {
        $bad = [];
        $okN = 0;
        foreach ($h['items'] as $k => $it) {
            if (empty($it['bad'])) {
                $okN++;
                continue;
            }
            $bad[] = ['what' => trim((string) ($it['label'] ?? $k)), 'problem' => mb_substr((string) ($it['detail'] ?? ''), 0, 160),
                'kind' => strpos($k, 'geo:') === 0 ? 'exit country differs from the location name (still works)' : 'not working', 'since' => !empty($it['since']) ? agent_ago((int) $it['since']) : ''];
        }
        $o['hourly_monitor'] = ['checked' => agent_ago((int) ($h['last_run'] ?? 0)), 'healthy_items' => $okN, 'problems' => $bad ?: 'none',
            'covers' => 'L2TP, OpenVPN, WireGuard and every V2Ray location, tested from a server inside Iran with a test account'];
    } else {
        $o['hourly_monitor'] = 'no result yet';
    }
    $panels = [];
    foreach ($pdo->query("SELECT * FROM marzban_panel")->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $type = strtolower((string) $p['type']);
        $row = ['panel' => $p['name_panel'], 'protocol' => agent_ro_protocol($p), 'selling' => (string) ($p['status'] ?? '')];
        if ($type === 'cloudius') {
            $d = agent_pr_read($p, 'POST', 'Self/Dashboard');
            $row['reachable'] = $d !== null;
            if (!empty($d[0]))
                $row += ['users_online_now' => (string) ($d[0]['CurrentOnlineCount'] ?? ''), 'servers_up' => (string) ($d[0]['ActiveRasCount'] ?? ''), 'servers_disabled' => (string) ($d[0]['DeactiveRasCount'] ?? '')];
        } elseif ($type === 'x-ui_single') {
            $d = agent_pr_read($p, 'GET', '/panel/api/server/status');
            $row['reachable'] = $d !== null;
            if (is_array($d))
                $row += ['xray' => (string) ($d['xray']['state'] ?? ''), 'cpu_percent' => (int) round((float) ($d['cpu'] ?? 0)),
                    'memory_percent' => !empty($d['mem']['total']) ? (int) round(100 * $d['mem']['current'] / $d['mem']['total']) : null,
                    'uptime' => (int) round(($d['uptime'] ?? 0) / 86400) . ' days'];
        } elseif ($type === 'drproxy') {
            $d = agent_pr_read($p, 'GET', '/ping');
            $row['reachable'] = $d !== null;
        } else {
            continue;
        }
        $panels[] = $row;
    }
    $o['panels'] = $panels;
    $o['how_to_use'] = 'Use this to tell a general problem from a personal one. A problem listed here for the customer\'s protocol or location is ours: say so honestly, suggest another location/protocol if one is healthy, and do not make them reinstall things. Everything healthy here means the cause is on the customer side or their own account: keep investigating with service_details and check_connection. Never tell server names, IPs or these numbers to the customer.';
    return $o;
}

function agent_pr_tools()
{
    return [
        ['name' => 'service_details', 'description' => 'READ-ONLY. Everything our panel has for ONE service of this customer, read live with the panel\'s own login: enabled or disabled, exists or not, created / first login / expiry, time left, online sessions now, device and connection limits, last time it was online, last time the app updated the subscription (V2Ray), which protocols the account has (Magic MAX), and rejected logins of the last 24 hours (OpenVPN / L2TP). Call it before answering any technical question about a service and before any handoff.',
            'input_schema' => ['type' => 'object', 'properties' => ['username' => ['type' => 'string', 'description' => 'the service username']], 'required' => ['username']]],
        ['name' => 'network_status', 'description' => 'READ-ONLY. Health of our side right now: the hourly monitor (L2TP, OpenVPN, WireGuard and every V2Ray location, with what is broken and since when) and whether each panel answers. Call it when a customer reports slowness, disconnects, a site not opening or a location not working, to know if it is a general problem.',
            'input_schema' => ['type' => 'object', 'properties' => new stdClass()]],
    ];
}

function agent_pr_call($uid, $name, array $in)
{
    try {
        if ($name === 'service_details')
            return agent_tool_service_details($uid, (string) ($in['username'] ?? ''));
        if ($name === 'network_status')
            return agent_tool_network_status();
    } catch (Throwable $e) {
        agent_log("panelread tool $name uid=$uid: " . $e->getMessage());
        return ['error' => 'could not read this just now; try once more'];
    }
    return null;
}
