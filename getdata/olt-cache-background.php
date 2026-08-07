<?php
/**
 * OLT Background Cache Service
 * Purpose: Periodically fetch OLT data and cache it for fast display
 * Usage: php olt-cache-background.php (called by cron job)
 * 
 * Cron Job Example:
 * */5 * * * * /usr/bin/php /var/www/html/crm/billing/getdata/olt-cache-background.php >> /tmp/olt-cache.log 2>&1
 */

// CLI-only execution check
if (php_sapi_name() !== 'cli' && php_sapi_name() !== 'cli-server') {
    http_response_code(403);
    exit('Access Denied: CLI only');
}

require_once __DIR__ . '/../../header.php';

$log_file = __DIR__ . '/../../logs/olt-cache-background.log';
$cache_file = __DIR__ . '/../../cache/olt-data.json';
$cache_dir = dirname($cache_file);

// Create directories if they don't exist
if (!is_dir(dirname($log_file))) {
    mkdir(dirname($log_file), 0755, true);
}
if (!is_dir($cache_dir)) {
    mkdir($cache_dir, 0755, true);
}

function logMsg($msg) {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    $msg = "[$timestamp] $msg\n";
    file_put_contents($log_file, $msg, FILE_APPEND | LOCK_EX);
    echo $msg;
}

try {
    logMsg("=== OLT Background Cache Started ===");
    
    // Fetch all OLT data
    $sql = "SELECT 
                id, 
                ipolt, 
                oltname, 
                pemilik, 
                area, 
                usernameolt, 
                passwordolt, 
                brandolt, 
                community_read, 
                community_write 
            FROM olt 
            ORDER BY area, oltname";
    
    $result = mysqli_query($conn, $sql);
    if (!$result) {
        throw new Exception("Database query failed: " . mysqli_error($conn));
    }
    
    $olts = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $olts[] = [
            'id' => (int)$row['id'],
            'ipolt' => $row['ipolt'],
            'oltname' => $row['oltname'],
            'pemilik' => $row['pemilik'],
            'area' => $row['area'],
            'usernameolt' => $row['usernameolt'],
            'passwordolt' => $row['passwordolt'],
            'brandolt' => $row['brandolt'],
            'community_read' => $row['community_read'] ?? '',
            'community_write' => $row['community_write'] ?? '',
        ];
    }
    
    // Prepare cache data
    $cache_data = [
        'success' => true,
        'count' => count($olts),
        'data' => $olts,
        'timestamp' => time(),
        'generated_at' => date('Y-m-d H:i:s')
    ];
    
    // Write cache file atomically
    $tmp_file = $cache_file . '.tmp';
    $json_data = json_encode($cache_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
    if (file_put_contents($tmp_file, $json_data, LOCK_EX) === false) {
        throw new Exception("Failed to write cache file");
    }
    
    if (!rename($tmp_file, $cache_file)) {
        throw new Exception("Failed to rename cache file");
    }
    
    logMsg("✓ Cached " . count($olts) . " OLT records successfully");
    logMsg("✓ Cache file: $cache_file");
    logMsg("=== OLT Background Cache Completed ===\n");
    
} catch (Exception $e) {
    logMsg("✗ Error: " . $e->getMessage());
    exit(1);
}

exit(0);
?>
