<?php
require_once __DIR__ . '/../../koneksibilling.php';
require_once __DIR__ . '/../../getdata/acs_live_cache_service.php';

$result = acs_live_refresh_tracked_customers($conn);

$line = '[ACS CACHE CRON] ' . date('Y-m-d H:i:s') . ' | ' . json_encode($result, JSON_UNESCAPED_UNICODE);
echo $line . PHP_EOL;
