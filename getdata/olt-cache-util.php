<?php
/**
 * OLT Cache Utility
 * Provides quick access to cached OLT data for all console types
 * Ensures instant loading without database queries
 */

header('Content-Type: application/json');

// Get action
$action = $_GET['action'] ?? $_POST['action'] ?? 'get';

// Cache directory
$cache_dir = __DIR__ . '/../notifbot/data';
if (!is_dir($cache_dir)) {
    mkdir($cache_dir, 0777, true);
}

if ($action === 'get') {
    // Return cached OLT data
    $user = $_GET['user'] ?? $_POST['user'] ?? '';
    
    if (empty($user)) {
        echo json_encode(['success' => false, 'error' => 'User required']);
        exit;
    }
    
    $cache_file = "$cache_dir/olt_data_cache-$user.json";
    
    if (file_exists($cache_file)) {
        $cache_data = json_decode(file_get_contents($cache_file), true);
        if (is_array($cache_data)) {
            echo json_encode([
                'success' => true,
                'cached' => true,
                'data' => $cache_data,
                'age' => time() - filemtime($cache_file)
            ]);
            exit;
        }
    }
    
    echo json_encode(['success' => false, 'cached' => false, 'error' => 'No cache available']);
    exit;
}

if ($action === 'get_by_id') {
    // Get specific OLT by ID from cache
    $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
    $user = $_GET['user'] ?? $_POST['user'] ?? '';
    
    if (empty($user) || !$id) {
        echo json_encode(['success' => false, 'error' => 'ID and user required']);
        exit;
    }
    
    $cache_file = "$cache_dir/olt_data_cache-$user.json";
    
    if (file_exists($cache_file)) {
        $cache_data = json_decode(file_get_contents($cache_file), true);
        if (is_array($cache_data)) {
            foreach ($cache_data as $olt) {
                if ($olt['id'] == $id) {
                    echo json_encode([
                        'success' => true,
                        'data' => $olt
                    ]);
                    exit;
                }
            }
        }
    }
    
    echo json_encode(['success' => false, 'error' => 'OLT not found in cache']);
    exit;
}

if ($action === 'get_by_ip') {
    // Get specific OLT by IP from cache
    $ip = trim($_GET['ip'] ?? $_POST['ip'] ?? '');
    $user = $_GET['user'] ?? $_POST['user'] ?? '';
    
    if (empty($user) || empty($ip)) {
        echo json_encode(['success' => false, 'error' => 'IP and user required']);
        exit;
    }
    
    $cache_file = "$cache_dir/olt_data_cache-$user.json";
    
    if (file_exists($cache_file)) {
        $cache_data = json_decode(file_get_contents($cache_file), true);
        if (is_array($cache_data)) {
            foreach ($cache_data as $olt) {
                if ($olt['ipolt'] === $ip) {
                    echo json_encode([
                        'success' => true,
                        'data' => $olt
                    ]);
                    exit;
                }
            }
        }
    }
    
    echo json_encode(['success' => false, 'error' => 'OLT not found in cache']);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action']);
?>
