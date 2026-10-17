<?php
// [crypto-offline-auto] confirms USDT TRC20/BEP20 offline payments on-chain.
chdir(__DIR__);
ini_set('error_log', 'error_log');
date_default_timezone_set('Asia/Tehran');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../crypto_offline_lib.php';
require __DIR__ . '/../vendor/autoload.php';
$ManagePanel = new ManagePanel();
$setting = select("setting", "*");
$textbotlang = languagechange();

// run.php includes jobs in its own scope: never exit, only return.
$cofLock = fopen(sys_get_temp_dir() . '/mirza_cof_' . md5(__DIR__) . '.lock', 'c');
if (!$cofLock || !flock($cofLock, LOCK_EX | LOCK_NB)) {
    return;
}
try {
    cofSeedCleanup();
    $cofStats = cofCron();
    if ($cofStats['settled'] || $cofStats['rejected'] || $cofStats['expired']) {
        error_log('cryptooffline: ' . json_encode($cofStats));
    }
} catch (Throwable $e) {
    error_log('cryptooffline cron failed: ' . $e->getMessage());
} finally {
    flock($cofLock, LOCK_UN);
    fclose($cofLock);
}
