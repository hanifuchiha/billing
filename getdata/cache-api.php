<?php
/**
 * =================== CACHED DATA API ===================
 * Serves pre-cached data to frontend (instant load)
 * Endpoint: getdata/cache-api.php?key=olt_all|server_all|user_all|odp_all|area_all
 */

header('Content-Type: application/json');

// Allow cors-like requests
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=60');

session_start();

define('CACHE_DIR', __DIR__ . '/cache');

/**
 * Get cached data - returns immediately from cache
 */
function getCacheData($key) {
    $file = CACHE_DIR . '/' . sanitizeKey($key) . '.json';
    
    if (!file_exists($file)) {
        return null;
    }
    
    $content = file_get_contents($file);
    $cache = json_decode($content, true);
    
    // Check if expired
    if ($cache['expires'] < time()) {
        return null;
    }
    
    return $cache['data'];
}

function sanitizeKey($key) {
    return preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key);
}

// Get requested cache key
$key = $_GET['key'] ?? '';
$validKeys = ['olt_all', 'server_all', 'user_all', 'odp_all', 'area_all'];

if (!in_array($key, $validKeys)) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Invalid cache key',
        'valid_keys' => $validKeys
    ]);
    exit;
}

$data = getCacheData($key);

if ($data === null) {
    http_response_code(503);
    echo json_encode([
        'error' => 'Cache not available',
        'message' => 'Data is being refreshed. Please try again.',
        'key' => $key
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'key' => $key,
    'count' => count($data),
    'data' => $data,
    'timestamp' => time()
]);
?>
