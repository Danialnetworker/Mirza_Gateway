<?php

/**
 * DrProxy reseller panel driver. One user = one /u/ link with every service the product asks for
 * (V2Ray, WireGuard, OpenVPN, L2TP); one volume and one end date across all of them.
 * API client: panels/Api/drproxy.php. Product "inbounds" = services ("v2ray,wg"); empty = all the reseller has.
 */
class DrproxyPanelDriver extends BasePanelDriver
{
    private static function name($u)
    {
        return rawurlencode(strtolower((string) $u));
    }

    private static function gb($bytes)
    {
        return $bytes > 0 ? (int) ceil($bytes / pow(1024, 3)) : 0;
    }

    public function create(array $vars)
    {
        $panel = $vars['Get_Data_Panel'];
        $product = is_array($vars['Get_Data_Product'] ?? null) ? $vars['Get_Data_Product'] : array();
        $Output = $vars['Output'];
        $expire = (int) $vars['expire'];
        $body = array(
            'username' => strtolower((string) $vars['usernameC']),
            'data_limit_gb' => self::gb((float) $vars['data_limit']),
            'days' => $expire > 0 ? max(1, (int) ceil(($expire - time()) / 86400)) : 0,
            'note' => mb_substr((string) ($vars['note'] ?? ''), 0, 200),
        );
        $svc = drproxy_services($product['inbounds'] ?? '');
        if (!empty($svc)) {
            $body['services'] = $svc;
        }
        $r = drproxy_call($panel, 'POST', '/users', $body);
        if (!$r['ok']) {
            $Output['status'] = 'Unsuccessful';
            $Output['msg'] = $r['msg'] !== '' ? $r['msg'] : 'DrProxy ' . $r['code'];
            return $Output;
        }
        $u = $r['obj']['user'];
        $Output['status'] = 'successful';
        $Output['username'] = $u['username'];
        $Output['subscription_url'] = $u['sub_url'];
        $Output['configs'] = [];
        return $Output;
    }

    public function read(array $vars)
    {
        $r = drproxy_call($vars['Get_Data_Panel'], 'GET', '/users/' . self::name($vars['username']));
        if (!$r['ok']) {
            return array('status' => 'Unsuccessful', 'msg' => $r['code'] == 404 ? 'User not found' : $r['msg']);
        }
        $u = $r['obj']['user'];
        $map = array('active' => 'active', 'disabled' => 'disabled', 'depleted' => 'limited', 'expired' => 'expired');
        return array(
            'status' => $map[$u['status']] ?? 'active',
            'username' => $u['username'],
            'data_limit' => (int) $u['data_limit_bytes'],
            'expire' => (int) ($u['expires_at'] ?? 0),
            'online_at' => null,
            'used_traffic' => (int) $u['used_bytes'],
            'links' => [],
            'subscription_url' => $u['sub_url'],
            'sub_updated_at' => null,
            'sub_last_user_agent' => null,
        );
    }

    public function revokeSub(array $vars)
    {
        $r = drproxy_call($vars['Get_Data_Panel'], 'POST', '/users/' . self::name($vars['username']) . '/rotate-link');
        if (!$r['ok']) {
            return array('status' => 'Unsuccessful', 'msg' => $r['msg']);
        }
        return array('status' => 'successful', 'configs' => [], 'subscription_url' => $r['obj']['user']['sub_url']);
    }

    public function remove(array $vars)
    {
        $r = drproxy_call($vars['Get_Data_Panel'], 'DELETE', '/users/' . self::name($vars['username']));
        if (!$r['ok'] && $r['code'] != 404) {   // already gone (e.g. a retried delete) is fine
            return array('status' => 'Unsuccessful', 'msg' => $r['msg']);
        }
        return array('status' => 'successful', 'username' => $vars['username']);
    }

    public function changeStatus(array $vars)
    {
        $act = $vars['DataUserOut']['status'] == 'active' ? 'disable' : 'enable';
        $r = drproxy_call($vars['Get_Data_Panel'], 'POST', '/users/' . self::name($vars['username']) . '/' . $act);
        return array('status' => $r['ok'] ? 'successful' : 'Unsuccessful', 'msg' => $r['ok'] ? null : $r['msg']);
    }

    public function resetUsage(array $vars)
    {
        $r = drproxy_call($vars['panel'], 'POST', '/users/' . self::name($vars['username']) . '/reset-usage');
        return array('status' => $r['ok'], 'msg' => $r['ok'] ? 'successful' : $r['msg']);
    }

    /** All renewal methods of Mirza, called before its generic pre-processing (panels.php [drproxy]). */
    public function extendService(array $vars)
    {
        $panel = $vars['panel'];
        $user = self::name($vars['username']);
        $du = $vars['data_user'];
        $gb = (float) $vars['new_limit'];
        $days = (int) $vars['time_day'];
        $leftGb = (int) $du['data_limit'] > 0 ? max(0, (int) $du['data_limit'] - (int) $du['used_traffic']) / pow(1024, 3) : 0;
        $leftDays = (int) $du['expire'] > 0 ? max(0, (int) ceil(((int) $du['expire'] - time()) / 86400)) : 0;
        $call = function ($action, $b = null) use ($panel, $user) {
            return drproxy_call($panel, 'POST', '/users/' . $user . '/' . $action, $b);
        };
        $m = extendMethodKey($vars['Method_extend']);
        if ($m == 'resetVolumeTime') {                       // new volume + new time, usage 0
            $r = $call('renew', array('data_limit_gb' => (int) ceil($gb), 'days' => $days));
        } elseif ($m == 'resetTimeAddVolume') {              // new window from today, leftover volume kept
            $r = $call('renew', array('data_limit_gb' => $gb > 0 ? (int) ceil($gb + $leftGb) : 0, 'days' => $days));
        } elseif ($m == 'addTimeConvertVolume') {            // leftover volume and days both carried over
            $r = $call('renew', array('data_limit_gb' => $gb > 0 ? (int) ceil($gb + $leftGb) : 0, 'days' => $days > 0 ? $days + $leftDays : 0));
        } elseif ($m == 'resetVolumeAddTime') {              // usage 0, volume = product, days added to what is left
            $r = $call('renew', array('data_limit_gb' => (int) ceil($gb), 'days' => $days > 0 ? $days + $leftDays : 0));
        } else {                                             // addTimeVolumeNextMonth: stack on top, usage kept
            $r = array('ok' => true, 'msg' => '');
            if ($gb > 0 && (int) $du['data_limit'] > 0) {
                $r = $call('add-volume', array('data_limit_gb' => (int) ceil($gb)));
            }
            if ($r['ok'] && $days > 0 && (int) $du['expire'] > 0) {
                $r = $call('add-time', array('days' => $days));
            }
        }
        if (!$r['ok']) {
            return array('status' => false, 'msg' => 'DrProxy: ' . $r['msg']);
        }
        if (($du['status'] ?? '') == 'disabled') {          // switched off by hand: a paid renewal turns it on
            $call('enable');
        }
        update("invoice", 'Status', "active", "username", $vars['username']);
        return array('status' => true, 'msg' => 'successful');
    }

    public function extraVolume(array $vars)
    {
        $r = drproxy_call($vars['panel'], 'POST', '/users/' . self::name($vars['username_account']) . '/add-volume',
                          array('data_limit_gb' => (int) ceil((float) $vars['limit_volume_new'])));
        return $r['ok'] ? array('status' => true, 'msg' => 'successful') : array('status' => false, 'msg' => 'DrProxy: ' . $r['msg']);
    }

    public function extraTime(array $vars)
    {
        $r = drproxy_call($vars['panel'], 'POST', '/users/' . self::name($vars['username_account']) . '/add-time',
                          array('days' => (int) $vars['limit_time_new']));
        return $r['ok'] ? array('status' => true, 'msg' => 'successful') : array('status' => false, 'msg' => 'DrProxy: ' . $r['msg']);
    }
}
