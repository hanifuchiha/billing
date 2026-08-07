<?php
// Debug API untuk diagnosa server monitor
header('Content-Type: application/json');
require_once '../koneksibilling.php';

$username = $_GET['username'] ?? '';
$password = $_GET['password'] ?? '';

// Simple auth function
$pemilik = null;
$user_id = null;

if ($username && $password) {
    $stmt = $conn->prepare("SELECT id, USERNAME FROM user WHERE USERNAME = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        if (password_verify($password, $row['PASWORD'])) {
            $pemilik = $row['USERNAME'];
            $user_id = $row['id'];
        }
    }
}

$debug = [
    'input' => [
        'username' => $username,
        'password' => $password ? '***' : '',
    ],
    'auth' => [
        'authenticated' => ($pemilik !== null),
        'pemilik' => $pemilik,
        'user_id' => $user_id,
    ],
    'servers' => [],
    'cache_files' => []
];

if (!$pemilik) {
    $debug['error'] = 'Autentikasi gagal';
    echo json_encode($debug);
    exit;
}

// Get servers for this user
$stmt = $conn->prepare("SELECT id, IP, USERNAME, PASWORD, PEMILIK, AREA FROM server WHERE user_id = ? ORDER BY AREA");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
$servers = [];
while ($row = $res->fetch_assoc()) {
    $servers[] = $row;
}

$debug['servers']['count'] = count($servers);
$debug['servers']['list'] = [];

$cacheDir = __DIR__ . '/trafik-cache';

foreach ($servers as $srv) {
    $id = $srv['id'];
    $area = $srv['AREA'];
    $pemilik_server = $srv['PEMILIK'];
    $ip = $srv['IP'];
    
    $cacheFile = $cacheDir . "/server_traffic_data_{$id}.json";
    $cache_exists = file_exists($cacheFile);
    $cache_size = 0;
    $cache_entries = 0;
    $latest_data = null;
    
    if ($cache_exists) {
        $cache_size = filesize($cacheFile);
        $cacheData = json_decode(file_get_contents($cacheFile), true);
        if (is_array($cacheData)) {
            $cache_entries = count($cacheData);
            if (!empty($cacheData)) {
                $latest_data = end($cacheData);
            }
        }
    }
    
    $debug['servers']['list'][] = [
        'id' => $id,
        'pemilik' => $pemilik_server,
        'area' => $area,
        'ip' => $ip,
        'cache_file' => "server_traffic_data_{$id}.json",
        'cache_exists' => $cache_exists,
        'cache_size_bytes' => $cache_size,
        'cache_entries' => $cache_entries,
        'latest_data' => $latest_data
    ];
    
    $debug['cache_files'][] = [
        'file' => "server_traffic_data_{$id}.json",
        'exists' => $cache_exists,
        'size' => $cache_size
    ];
}

// List all cache files in directory
$debug['all_cache_files'] = [];
if (is_dir($cacheDir)) {
    $files = scandir($cacheDir);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..' && strpos($file, 'server_traffic_data_') === 0) {
            $debug['all_cache_files'][] = $file;
        }
    }
}

echo json_encode($debug, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
