<?php

/**
 * Cloudius (RADIUS / OpenVPN / L2TP) driver for the 0.7.0 panel registry.
 * The API client lives in /cloudius.php; this class only maps ManagePanel
 * calls onto it. Logic is a 1:1 port of the 0.6.0 `cloudius` branches.
 */
class CloudiusPanelDriver extends BasePanelDriver
{
    public function create(array $vars)
    {
        $Get_Data_Panel = $vars['Get_Data_Panel'];
        $Get_Data_Product = $vars['Get_Data_Product'];
        $usernameC = $vars['usernameC'];
        $data_limit = $vars['data_limit'];
        $expire = $vars['expire'];
        $Output = $vars['Output'];
        // 6 chars, letters + digits. Long hex passwords are rejected by some
        // OpenVPN clients. Ambiguous glyphs (0/O, 1/l/I) are excluded.
        $pw_chars = '23456789abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
        $password = '';
        for ($pw_i = 0; $pw_i < 6; $pw_i++) {
            $password .= $pw_chars[random_int(0, strlen($pw_chars) - 1)];
        }
        // Cloudius keys plans off a numeric GroupID (panel default in inboundid).
        $group_id = $Get_Data_Panel['inboundid'];
        if (!empty($Get_Data_Product['inbounds'])) {
            $group_id = $Get_Data_Product['inbounds'];
        }
        $traffic_gb = $data_limit > 0 ? $data_limit / pow(1024, 3) : 0;
        $days_cloudius = $expire > 0 ? (int) ceil(($expire - time()) / 86400) : 0;
        $data_Output = addUser_cloudius($Get_Data_Panel['name_panel'], $usernameC, $password, $group_id, $traffic_gb, $days_cloudius);
        if (empty($data_Output['status'])) {
            $Output['status'] = 'Unsuccessful';
            $Output['msg'] = $data_Output['msg'];
        } else {
            $Output['status'] = 'successful';
            $Output['username'] = $usernameC;
            $Output['subscription_url'] = $password;
            $Output['configs'] = [];
        }
        return $Output;
    }

    public function read(array $vars)
    {
        $Get_Data_Panel = $vars['Get_Data_Panel'];
        $username = $vars['username'];
        $UsernameData = GetUser_cloudius($Get_Data_Panel['name_panel'], $username);
        if (empty($UsernameData['status'])) {
            return array(
                'status' => 'Unsuccessful',
                'msg' => $UsernameData['msg']
            );
        }
        $UsernameData = $UsernameData['data'];
        $invocie = select("invoice", "*", "username", $username, "select");
        // Prefer the LIVE password from the panel so manual changes show up,
        // and keep the stored copy in step with it.
        $cloudius_pw_live = trim((string) ($UsernameData['password'] ?? ''));
        if ($cloudius_pw_live !== '' && $invocie != false
            && trim((string) $invocie['user_info']) !== $cloudius_pw_live) {
            update("invoice", "user_info", $cloudius_pw_live, "username", $username);
        }
        // Cloudius reports what REMAINS; Mirza wants limit + used.
        $data_limit = $UsernameData['remained_traffic'];
        $used_traffic = 0;
        if ($invocie != false && intval($invocie['Volume']) > 0) {
            $data_limit = $invocie['Volume'] * pow(1024, 3);
            $used_traffic = max(0, $data_limit - $UsernameData['remained_traffic']);
        }
        return array(
            'status' => $UsernameData['enable'],
            'username' => $UsernameData['username'],
            'data_limit' => $data_limit,
            'expire' => $UsernameData['expire'],
            'online_at' => null,
            'used_traffic' => $used_traffic,
            'links' => [],
            'subscription_url' => $cloudius_pw_live !== ''
                ? $cloudius_pw_live
                : (($invocie != false && trim((string) $invocie['user_info']) !== '')
                    ? $invocie['user_info']
                    : $UsernameData['username']),
            'sub_updated_at' => null,
            'sub_last_user_agent' => null,
        );
    }

    public function remove(array $vars)
    {
        $Get_Data_Panel = $vars['Get_Data_Panel'];
        $username = $vars['username'];
        $deleteData = deleteUser_cloudius($Get_Data_Panel['name_panel'], $username);
        if (empty($deleteData['status'])) {
            return array(
                'status' => 'Unsuccessful',
                'msg' => $deleteData['msg']
            );
        }
        return array(
            'status' => 'successful',
            'username' => $username,
        );
    }

    public function changeStatus(array $vars) // [cloudius-renew]
    {
        $cl = changeActive_cloudius($vars['name_panel'], $vars['username'], $vars['DataUserOut']['status'] != "active");
        return array(
            'status' => !empty($cl['status']) ? 'successful' : 'Unsuccessful',
            'msg' => !empty($cl['status']) ? null : ($cl['msg'] ?? 'no response')
        );
    }

    public function resetUsage(array $vars) // [cloudius-renew]
    {
        $cl = resetTraffic_cloudius($vars['panel']['name_panel'], $vars['username']);
        return array('status' => !empty($cl['status']), 'msg' => $cl['msg'] ?? '');
    }

    /**
     * Called by ManagePanel::extend() BEFORE the generic pre-processing
     * (upstream resets traffic / clears user_info first, which would destroy
     * the leftover-traffic carry-over and the stored password).
     */
    public function extendService(array $vars) // [cloudius-renew]
    {
        $panel = $vars['panel'];
        $product = $vars['product'];
        $invoice = $vars['invoice'];
        $data_user = $vars['data_user'];
        $username = $vars['username'];
        update("invoice", 'Status', "active", "username", $username);
        $cl_group = null;
        if (is_array($product) && !empty($product['inbounds']) && is_numeric($product['inbounds'])) {
            $cl_group = (int) $product['inbounds'];
        }
        $cl_gb = (float) $vars['new_limit'];
        $cl_days = (int) $vars['time_day'];
        $cl_method = extendMethodKey($vars['Method_extend']);
        if ($cl_method == "resetVolumeTime" || $cl_method == "resetTimeAddVolume") {
            // resetTimeAddVolume: new window from today, leftover traffic carried over
            if ($cl_method == "resetTimeAddVolume") {
                $cl_live = GetUser_cloudius($panel['name_panel'], $username);
                if (!empty($cl_live['status'])) {
                    $cl_gb += max(0, $cl_live['data']['remained_traffic']) / pow(1024, 3);
                }
            }
            $cl = renewUser_cloudius($panel['name_panel'], $username, $cl_group, $cl_gb, $cl_days);
        } elseif ($cl_method == "addTimeConvertVolume") {
            $cl_live = GetUser_cloudius($panel['name_panel'], $username);
            if (!empty($cl_live['status'])) {
                $cl_gb += max(0, $cl_live['data']['remained_traffic']) / pow(1024, 3);
                $cl_days += max(0, (int) $cl_live['data']['remained_days']);
            }
            $cl = renewUser_cloudius($panel['name_panel'], $username, $cl_group, $cl_gb, $cl_days);
        } else {
            // addTimeVolumeNextMonth / resetVolumeAddTime: stack on top of what is left
            $cl = array('status' => true, 'msg' => '');
            if ($cl_method == "resetVolumeAddTime") {
                $cl = resetTraffic_cloudius($panel['name_panel'], $username);
            }
            if (!empty($cl['status']) && $cl_gb > 0) {
                $cl = addTraffic_cloudius($panel['name_panel'], $username, $cl_gb);
            }
            if (!empty($cl['status']) && $cl_days > 0) {
                $cl = addTime_cloudius($panel['name_panel'], $username, $cl_days);
            }
        }
        if (empty($cl['status'])) {
            return array('status' => false, 'msg' => 'cloudius : ' . ($cl['msg'] ?? 'no response'));
        }
        if (empty($data_user['status']) || $data_user['status'] != "active") {
            changeActive_cloudius($panel['name_panel'], $username, true);
        }
        if ($invoice != false && $cl_gb > 0) {
            update("invoice", "Volume", (string) round($cl_gb, 2), "id_invoice", $invoice['id_invoice']);
        }
        return array('status' => true, 'msg' => 'successful');
    }

    public function extraVolume(array $vars) // [cloudius-renew]
    {
        $panel = $vars['panel'];
        $invoice = $vars['invoice'];
        $limit_volume_new = $vars['limit_volume_new'];
        $cl = addTraffic_cloudius($panel['name_panel'], $vars['username_account'], (float) $limit_volume_new);
        if (empty($cl['status'])) {
            return array('status' => false, 'msg' => 'cloudius : ' . ($cl['msg'] ?? 'no response'));
        }
        if ($invoice != false && intval($invoice['Volume']) > 0) {
            update("invoice", "Volume", (string) (intval($invoice['Volume']) + (float) $limit_volume_new), "id_invoice", $invoice['id_invoice']);
        }
        return array('status' => true, 'msg' => 'successful');
    }

    public function extraTime(array $vars) // [cloudius-renew]
    {
        $cl = addTime_cloudius($vars['panel']['name_panel'], $vars['username_account'], (int) $vars['limit_time_new']);
        if (empty($cl['status'])) {
            return array('status' => false, 'msg' => 'cloudius : ' . ($cl['msg'] ?? 'no response'));
        }
        return array('status' => true, 'msg' => 'successful');
    }
}
