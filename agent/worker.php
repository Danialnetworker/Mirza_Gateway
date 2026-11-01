<?php
// [agent] background worker: php agent/worker.php <telegram_user_id>
if (PHP_SAPI !== 'cli')
    exit;
chdir(dirname(__DIR__));
ini_set('error_log', dirname(__DIR__) . '/storage/agent/error_log');
date_default_timezone_set('Asia/Tehran');
set_time_limit(600);
$uid = isset($argv[1]) ? intval($argv[1]) : 0;
if ($uid <= 0)
    exit(1);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/botapi.php';
require_once dirname(__DIR__) . '/jdf.php';
require_once dirname(__DIR__) . '/panels.php';
require_once dirname(__DIR__) . '/function.php';
require_once __DIR__ . '/agent.php';
$from_id = $uid;                       // languagechange() reads the user's language from it
$textbotlang = languagechange(dirname(__DIR__));
$ManagePanel = new ManagePanel();
agent_run($uid);
