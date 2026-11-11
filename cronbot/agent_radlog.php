<?php
// [agent-radlog] Keep the Cloudius RADIUS login rejects for the AI support agent.
// Cloudius /Log/Fetch holds only the newest ~3000 rejects (about 20 minutes on a busy server) and
// logs failures only (written ~30 min late), so this job copies them every minute into agent_radlog, aggregated per
// (server, typed username, reason, 10-minute bucket), and keeps 24 hours. [agent-radlog-24h] Runs only while the agent
// is on and a Cloudius panel exists. No passwords or client IPs are stored.
chdir(__DIR__);
date_default_timezone_set('Asia/Tehran');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../cloudius.php';

(function () {
    global $pdo;
    $cfg = json_decode((string) @file_get_contents(__DIR__ . '/../storage/agent/config.json'), true);
    if (empty($cfg['enabled']) || !isset($pdo))
        return;
    $panels = $pdo->query("SELECT name_panel, url_panel, username_panel FROM marzban_panel WHERE type = 'cloudius'")->fetchAll(PDO::FETCH_ASSOC);
    if (!$panels)
        return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_radlog (
        server VARCHAR(190) NOT NULL, username VARCHAR(100) NOT NULL, reason VARCHAR(32) NOT NULL, bucket INT UNSIGNED NOT NULL,
        first_ts INT UNSIGNED NOT NULL, last_ts INT UNSIGNED NOT NULL, cnt INT UNSIGNED NOT NULL, nas VARCHAR(40) NOT NULL DEFAULT '',
        PRIMARY KEY (server, username, reason, bucket), KEY k_user (username), KEY k_last (last_ts)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_radlog_state (server VARCHAR(190) NOT NULL PRIMARY KEY, last_id BIGINT UNSIGNED NOT NULL, pruned INT UNSIGNED NOT NULL DEFAULT 0, seen INT UNSIGNED NOT NULL DEFAULT 0, newest INT UNSIGNED NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $oldTimeout = $GLOBALS['request_exec_timeout'] ?? null;
    $GLOBALS['request_exec_timeout'] = 8000;          // never hold the cron dispatcher for long
    $deadline = time() + 25;
    $seen = [];
    foreach ($panels as $p) {
        $server = strtolower(cloudius_base_url($p['url_panel'])) . '|' . $p['username_panel'];
        if (isset($seen[$server]))
            continue;                                  // OpenVPN + L2TP panels on the same Cloudius
        $seen[$server] = 1;
        $st = $pdo->prepare('SELECT last_id, pruned FROM agent_radlog_state WHERE server = ?');
        $st->execute([$server]);
        $state = $st->fetch(PDO::FETCH_ASSOC) ?: ['last_id' => 0, 'pruned' => 0];
        $last = (int) $state['last_id'];
        $agg = [];
        $maxId = $last;
        $ok = false;
        $newest = 0;
        for ($page = 1; $page <= 12 && time() < $deadline; $page++) {
            $r = cloudius_api($p['name_panel'], '/Log/Fetch', ['PageNo' => $page, 'RowPerPage' => 300]);
            $rows = (isset($r['data']) && is_array($r['data'])) ? $r['data'] : [];
            if (!empty($r['status']))
                $ok = true;
            if (!$rows)
                break;
            if ($page === 1 && $last > 0) {           // [agent-radlog-reset] panel log cleared -> ids restarted below our cursor
                $top = max(array_map(fn($h) => (int) ($h['LogID'] ?? 0), $rows));
                if ($top > 0 && $top < $last) {
                    $last = 0;
                    $maxId = 0;
                }
            }
            $older = false;
            foreach ($rows as $h) {
                $id = (int) ($h['LogID'] ?? 0);
                if ($id <= $last) {
                    $older = true;
                    continue;
                }
                $maxId = max($maxId, $id);
                $ts = cloudius_jalali_to_ts((string) ($h['DateTime'] ?? ''));
                $user = mb_substr(trim((string) ($h['UserName'] ?? '')), 0, 100);
                $newest = max($newest, $ts);
                if (!$ts || $user === '')
                    continue;
                $resp = (string) ($h['Response'] ?? '');
                if (stripos($resp, 'Accept') !== false && stripos($resp, 'Reject') === false)
                    continue;                          // only rejects are of interest
                if (stripos($resp, 'not found') !== false) $reason = 'username_not_found';
                elseif (stripos($resp, 'Password') !== false) $reason = 'wrong_password';
                elseif (stripos($resp, 'expired') !== false) $reason = 'expired';
                elseif (stripos($resp, 'Traffic') !== false) $reason = 'no_traffic';
                else $reason = 'other: ' . mb_substr(preg_replace('/^.*Authentication Failed\.?\s*/i', '', $resp), 0, 24);
                $k = $user . "\0" . $reason . "\0" . intdiv($ts, 600);
                if (!isset($agg[$k]))
                    $agg[$k] = [$user, $reason, intdiv($ts, 600), $ts, $ts, 0, mb_substr((string) ($h['NASIdentifier'] ?? ''), 0, 40)];
                $agg[$k][3] = min($agg[$k][3], $ts);
                $agg[$k][4] = max($agg[$k][4], $ts);
                $agg[$k][5]++;
            }
            if ($older || $last === 0 && $page >= 12)
                break;
        }
        if ($agg) {
            $ins = $pdo->prepare('INSERT INTO agent_radlog (server, username, reason, bucket, first_ts, last_ts, cnt, nas) VALUES (?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE first_ts = LEAST(first_ts, VALUES(first_ts)), last_ts = GREATEST(last_ts, VALUES(last_ts)), cnt = cnt + VALUES(cnt), nas = VALUES(nas)');
            $pdo->beginTransaction();
            try {
                foreach ($agg as $a)
                    $ins->execute([$server, $a[0], $a[1], $a[2], $a[3], $a[4], $a[5], $a[6]]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();                      // the dispatcher shares $pdo with the other jobs
                throw $e;
            }
        }
        $pruned = (int) $state['pruned'];
        if (time() - $pruned > 3600) {
            $pdo->prepare('DELETE FROM agent_radlog WHERE last_ts < ?')->execute([time() - 86400]);   // [agent-radlog-24h]
            $pruned = time();
        }
        if ($ok)
            $pdo->prepare('INSERT INTO agent_radlog_state (server, last_id, pruned, seen, newest) VALUES (?,?,?,?,?)
                ON DUPLICATE KEY UPDATE last_id = VALUES(last_id), pruned = VALUES(pruned), seen = VALUES(seen), newest = GREATEST(newest, VALUES(newest))')
                ->execute([$server, $maxId, $pruned, time(), $newest]);
    }
    if ($oldTimeout === null)
        unset($GLOBALS['request_exec_timeout']);
    else
        $GLOBALS['request_exec_timeout'] = $oldTimeout;
})();
