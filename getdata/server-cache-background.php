<?php
/**
 * Server Background Cache Service
 * Purpose: Periodically fetch server data and ping status
 * Usage: php server-cache-background.php (called by cron job)
 */

if (php_sapi_name() !== 'cli' && php_sapi_name() !== 'cli-server') {
    http_response_code(403);
    exit('Access Denied: CLI only');
}

require_once __DIR__ . '/../../header.php';

$log_file = __DIR__ . '/../../logs/server-cache-background.log';
$cache_file = __DIR__ . '/../../cache/server-data.json';
$cache_dir = dirname($cache_file);

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
    logMsg("=== Server Background Cache Started ===");
    
    // Fetch all server data
    $sql = "SELECT 
                id, 
                ipserver, 
                server_name, 
                PEMILIK, 
                BRAND, 
                AREA,
                usernameserver,
                passwordserver
            FROM server 
            ORDER BY AREA, server_name";
    
    $result = mysqli_query($conn, $sql);
    if (!$result) {
        throw new Exception("Database query failed: " . mysqli_error($conn));
    }
    
    $servers = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $servers[] = [
            'id' => (int)$row['id'],
            'ipserver' => $row['ipserver'],
            'server_name' => $row['server_name'],
            'pemilik' => $row['PEMILIK'],
            'brand' => $row['BRAND'],
            'area' => $row['AREA'],
            'usernameserver' => $row['usernameserver'],
            'passwordserver' => $row['passwordserver'],
            'status' => 'pending' // Will be updated by real-time checks
        ];
    }
    
    // Prepare cache data
    $cache_data = [
        'success' => true,
        'count' => count($servers),
        'data' => $servers,
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
    
    logMsg("✓ Cached " . count($servers) . " server records successfully");
    logMsg("✓ Cache file: $cache_file");
    logMsg("=== Server Background Cache Completed ===\n");
    
} catch (Exception $e) {
    logMsg("✗ Error: " . $e->getMessage());
    exit(1);
}

exit(0);
?>
