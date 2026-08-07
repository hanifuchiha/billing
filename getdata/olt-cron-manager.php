<?php
/**
 * OLT Cache Cron Job Manager
 * Manages automated OLT data caching via cron jobs
 */

header('Content-Type: application/json');
session_start();

// Security check
if (!isset($_SESSION['status']) || $_SESSION['status'] !== 'login') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../header.php';

$pemilik = isset($_SESSION['pemilik']) ? $_SESSION['pemilik'] : (isset($_SESSION['ceknama']) ? $_SESSION['ceknama'] : '');
if (empty($pemilik)) {
    echo json_encode(['success' => false, 'error' => 'No owner identified']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Config file for OLT cache cron settings
$config_dir = __DIR__ . '/../notifbot/data';
if (!is_dir($config_dir)) {
    mkdir($config_dir, 0777, true);
}
$config_file = "$config_dir/olt_cache_cron-$pemilik.json";

// Get current cron config
$cron_config = [
    'enabled' => false,
    'interval_minutes' => 5,
    'last_run' => null,
    'next_run' => null,
    'status' => 'inactive'
];

if (file_exists($config_file)) {
    $loaded = json_decode(file_get_contents($config_file), true);
    if (is_array($loaded)) {
        $cron_config = array_merge($cron_config, $loaded);
    }
}

// Handle actions
if ($action === 'get_status') {
    // Return current cron status
    echo json_encode([
        'success' => true,
        'config' => $cron_config,
        'server_time' => date('Y-m-d H:i:s')
    ]);
    exit;
}

if ($action === 'enable') {
    $cron_config['enabled'] = true;
    $cron_config['status'] = 'active';
    $cron_config['last_run'] = date('Y-m-d H:i:s');
    $cron_config['next_run'] = date('Y-m-d H:i:s', time() + ($cron_config['interval_minutes'] * 60));
    
    file_put_contents($config_file, json_encode($cron_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    // Log action
    $history_file = "$config_dir/history-$pemilik.json";
    $history = file_exists($history_file) ? json_decode(file_get_contents($history_file), true) : [];
    if (!is_array($history)) $history = [];
    $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $pemilik) . " - " . date('Y-m-d H:i:s') . " ] Mengaktifkan cron pengambilan data OLT otomatis (interval: setiap {$cron_config['interval_minutes']} menit)";
    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    
    echo json_encode([
        'success' => true,
        'message' => 'OLT Cache Cron Job enabled',
        'config' => $cron_config
    ]);
    exit;
}

if ($action === 'disable') {
    $cron_config['enabled'] = false;
    $cron_config['status'] = 'inactive';
    
    file_put_contents($config_file, json_encode($cron_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    // Log action
    $history_file = "$config_dir/history-$pemilik.json";
    $history = file_exists($history_file) ? json_decode(file_get_contents($history_file), true) : [];
    if (!is_array($history)) $history = [];
    $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $pemilik) . " - " . date('Y-m-d H:i:s') . " ] Menonaktifkan cron pengambilan data OLT otomatis";
    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    
    echo json_encode([
        'success' => true,
        'message' => 'OLT Cache Cron Job disabled',
        'config' => $cron_config
    ]);
    exit;
}

if ($action === 'set_interval') {
    $interval = (int)($_POST['interval'] ?? 5);
    $interval = max(1, min(60, $interval)); // 1-60 minutes
    
    $cron_config['interval_minutes'] = $interval;
    
    file_put_contents($config_file, json_encode($cron_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    echo json_encode([
        'success' => true,
        'message' => "OLT Cache interval updated to $interval minutes",
        'config' => $cron_config
    ]);
    exit;
}

if ($action === 'run_now') {
    // Trigger immediate cache update
    $cron_config['last_run'] = date('Y-m-d H:i:s');
    $cron_config['next_run'] = date('Y-m-d H:i:s', time() + ($cron_config['interval_minutes'] * 60));
    
    file_put_contents($config_file, json_encode($cron_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    echo json_encode([
        'success' => true,
        'message' => 'OLT Cache update triggered',
        'config' => $cron_config
    ]);
    exit;
}

// Default: return status
echo json_encode([
    'success' => true,
    'config' => $cron_config
]);
?>
