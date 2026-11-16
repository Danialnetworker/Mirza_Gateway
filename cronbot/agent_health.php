<?php
// [agent-health] hourly check of every service with the agent's test account (see agent/health.php).
// The check takes ~2-4 minutes, so it runs detached and the cron dispatcher is not held.
if (is_file(__DIR__ . '/../storage/agent/config.json') && is_file(__DIR__ . '/../storage/agent/probe_key')) {
    require_once __DIR__ . '/../agent/health.php';
    agent_health_spawn('cron');
}
