<?php
/**
 * =================== BACKGROUND CACHE MANAGER ===================
 * Handles automatic background refresh of all OLT/Server data
 * Can be triggered by:
 * - Cron jobs: php cache-manager.php
 * - Background task: php -r "include 'cache-manager.php'; updateAllCache();"
 * - HTTP trigger: cache-manager.php?action=refresh
 */

// No session requirement for cron jobs
if (php_sapi_name() !== 'cli') {
    session_start();
}

define('CACHE_DIR', __DIR__ . '/getdata/cache');
define('CACHE_VERSION', 1);

// Create cache directory if not exists
if (!is_dir(CACHE_DIR)) {
    mkdir(CACHE_DIR, 0755, true);
}

// Load config
require_once __DIR__ . '/../../header.php';

// =================== CACHE OPERATIONS ===================

/**
 * Set cache data with expiry
 */
function setCacheData($key, $data, $ttl = 300) {
    $file = CACHE_DIR . '/' . sanitizeKey($key) . '.json';
    $cacheData = [
        'key' => $key,
        'data' => $data,
        'timestamp' => time(),
        'ttl' => $ttl,
        'expires' => time() + $ttl,
        'version' => CACHE_VERSION
    ];
    
    $result = file_put_contents($file, json_encode($cacheData), LOCK_EX);
    if ($result !== false) {
        logCache("[CACHE SET] $key (TTL: {$ttl}s)");
        return true;
    }
    logCache("[CACHE ERROR] Failed to set $key");
    return false;
}

/**
 * Get cache data if not expired
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
        unlink($file);
        logCache("[CACHE EXPIRED] $key");
        return null;
    }
    
    logCache("[CACHE HIT] $key");
    return $cache['data'];
}

/**
 * Clear expired caches
 */
function clearExpiredCaches() {
    $files = glob(CACHE_DIR . '/*.json');
    $cleared = 0;
    
    foreach ($files as $file) {
        $content = file_get_contents($file);
        $cache = json_decode($content, true);
        
        if ($cache['expires'] < time()) {
            unlink($file);
            $cleared++;
        }
    }
    
    if ($cleared > 0) {
        logCache("[CACHE CLEANUP] Removed $cleared expired files");
    }
    return $cleared;
}

/**
 * Clear all caches
 */
function clearAllCaches() {
    $files = glob(CACHE_DIR . '/*.json');
    $count = 0;
    
    foreach ($files as $file) {
        if (unlink($file)) {
            $count++;
        }
    }
    
    logCache("[CACHE CLEAR ALL] Removed $count files");
    return $count;
}

function sanitizeKey($key) {
    return preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key);
}

function logCache($message) {
    $logFile = CACHE_DIR . '/cache.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

// =================== DATA REFRESH FUNCTIONS ===================

/**
 * Refresh all OLT data
 */
function refreshOltCache($conn) {
    try {
        // Get all OLTs
        $query = mysqli_query($conn, "SELECT * FROM olt ORDER BY area, oltname");
        $olts = [];
        
        while ($row = mysqli_fetch_assoc($query)) {
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
                'community_write' => $row['community_write'] ?? ''
            ];
        }
        
        setCacheData('olt_all', $olts, 600); // Cache for 10 minutes
        logCache("[OLT REFRESH] Cached " . count($olts) . " OLTs");
        
        return count($olts);
    } catch (Exception $e) {
        logCache("[OLT ERROR] " . $e->getMessage());
        return 0;
    }
}

/**
 * Refresh all Server data
 */
function refreshServerCache($conn) {
    try {
        // Get all servers
        $query = mysqli_query($conn, "SELECT * FROM server ORDER BY PEMILIK, AREA");
        $servers = [];
        
        while ($row = mysqli_fetch_assoc($query)) {
            $servers[] = [
                'id' => (int)$row['id'],
                'PEMILIK' => $row['PEMILIK'],
                'AREA' => $row['AREA'],
                'BRAND' => $row['BRAND'],
                'IPSERVER' => $row['IPSERVER'],
                'USERNAMESERVER' => $row['USERNAMESERVER'],
                'PASSWORDSERVER' => $row['PASSWORDSERVER'],
                'user_id' => (int)$row['user_id']
            ];
        }
        
        setCacheData('server_all', $servers, 600); // Cache for 10 minutes
        logCache("[SERVER REFRESH] Cached " . count($servers) . " servers");
        
        return count($servers);
    } catch (Exception $e) {
        logCache("[SERVER ERROR] " . $e->getMessage());
        return 0;
    }
}

/**
 * Refresh all User data
 */
function refreshUserCache($conn) {
    try {
        // Get all users
        $query = mysqli_query($conn, "SELECT id, nama, email, akses FROM user ORDER BY nama");
        $users = [];
        
        while ($row = mysqli_fetch_assoc($query)) {
            $users[] = [
                'id' => (int)$row['id'],
                'nama' => $row['nama'],
                'email' => $row['email'],
                'akses' => $row['akses']
            ];
        }
        
        setCacheData('user_all', $users, 1800); // Cache for 30 minutes
        logCache("[USER REFRESH] Cached " . count($users) . " users");
        
        return count($users);
    } catch (Exception $e) {
        logCache("[USER ERROR] " . $e->getMessage());
        return 0;
    }
}

/**
 * Refresh all ODP data
 */
function refreshOdpCache($conn) {
    try {
        // Get all ODPs
        $query = mysqli_query($conn, "SELECT * FROM odp ORDER BY area, namaodp");
        $odps = [];
        
        while ($row = mysqli_fetch_assoc($query)) {
            $odps[] = [
                'id' => (int)$row['id'],
                'namaodp' => $row['namaodp'],
                'area' => $row['area'],
                'olt' => $row['olt'],
                'port' => $row['port'] ?? ''
            ];
        }
        
        setCacheData('odp_all', $odps, 900); // Cache for 15 minutes
        logCache("[ODP REFRESH] Cached " . count($odps) . " ODPs");
        
        return count($odps);
    } catch (Exception $e) {
        logCache("[ODP ERROR] " . $e->getMessage());
        return 0;
    }
}

/**
 * Refresh area data
 */
function refreshAreaCache($conn) {
    try {
        // Get all areas
        $query = mysqli_query($conn, "SELECT DISTINCT area FROM server WHERE area IS NOT NULL AND area != '' ORDER BY area");
        $areas = [];
        
        while ($row = mysqli_fetch_assoc($query)) {
            $areas[] = $row['area'];
        }
        
        setCacheData('area_all', $areas, 3600); // Cache for 1 hour
        logCache("[AREA REFRESH] Cached " . count($areas) . " areas");
        
        return count($areas);
    } catch (Exception $e) {
        logCache("[AREA ERROR] " . $e->getMessage());
        return 0;
    }
}

// =================== MAIN UPDATE FUNCTION ===================

/**
 * Update all caches
 */
function updateAllCache($conn = null) {
    global $conn;
    
    if (!$conn) {
        die("Database connection not available\n");
    }
    
    logCache("[CACHE UPDATE] Started");
    
    $results = [
        'olt' => refreshOltCache($conn),
        'server' => refreshServerCache($conn),
        'user' => refreshUserCache($conn),
        'odp' => refreshOdpCache($conn),
        'area' => refreshAreaCache($conn)
    ];
    
    clearExpiredCaches();
    
    logCache("[CACHE UPDATE] Completed - OLT:" . $results['olt'] . " SERVER:" . $results['server'] . " USER:" . $results['user'] . " ODP:" . $results['odp'] . " AREA:" . $results['area']);
    
    return $results;
}

// =================== CLI INTERFACE ===================

// Handle CLI execution
if (php_sapi_name() === 'cli') {
    $action = $argv[1] ?? 'refresh';
    
    switch ($action) {
        case 'refresh':
            $results = updateAllCache($conn);
            echo "Cache refresh completed:\n";
            echo "  OLTs: " . $results['olt'] . "\n";
            echo "  Servers: " . $results['server'] . "\n";
            echo "  Users: " . $results['user'] . "\n";
            echo "  ODPs: " . $results['odp'] . "\n";
            echo "  Areas: " . $results['area'] . "\n";
            break;
            
        case 'clear':
            $count = clearAllCaches();
            echo "Cleared $count cache files\n";
            break;
            
        case 'status':
            $files = glob(CACHE_DIR . '/*.json');
            echo "Cache files: " . count($files) . "\n";
            $logFile = CACHE_DIR . '/cache.log';
            if (file_exists($logFile)) {
                echo "Last 10 log entries:\n";
                $logs = array_slice(file($logFile), -10);
                foreach ($logs as $log) {
                    echo $log;
                }
            }
            break;
            
        default:
            echo "Usage: php cache-manager.php [refresh|clear|status]\n";
    }
    exit;
}

// =================== HTTP INTERFACE ===================

// Handle HTTP requests for triggering cache refresh
if (isset($_GET['action'])) {
    // Require admin or local access
    if (php_sapi_name() !== 'cli') {
        // Allow from localhost or admin users only
        $allowed = ($_SERVER['REMOTE_ADDR'] === '127.0.0.1' || $_SERVER['REMOTE_ADDR'] === '::1');
        
        if (!$allowed && (!isset($_SESSION['akses']) || $_SESSION['akses'] !== 'ADMIN')) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            exit;
        }
    }
    
    $action = $_GET['action'];
    
    header('Content-Type: application/json');
    
    switch ($action) {
        case 'refresh':
            $results = updateAllCache($conn);
            echo json_encode([
                'success' => true,
                'message' => 'Cache refreshed',
                'results' => $results,
                'timestamp' => time()
            ]);
            break;
            
        case 'clear':
            $count = clearAllCaches();
            echo json_encode([
                'success' => true,
                'message' => "Cleared $count cache files",
                'count' => $count
            ]);
            break;
            
        case 'status':
            $files = glob(CACHE_DIR . '/*.json');
            $logFile = CACHE_DIR . '/cache.log';
            $logs = file_exists($logFile) ? array_slice(file($logFile), -20) : [];
            
            echo json_encode([
                'success' => true,
                'cache_files' => count($files),
                'log_entries' => count($logs),
                'recent_logs' => $logs
            ]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action']);
    }
    exit;
}
?>
