<?php
// inspect.php - API untuk melihat struktur data di database
header('Content-Type: application/json');
require_once '../koneksibilling.php';

$result = [
    'users' => [],
    'servers' => [],
    'cache_files' => []
];

// Get all users
$users_query = $conn->query("SELECT id, USERNAME FROM user LIMIT 10");
while ($user = $users_query->fetch_assoc()) {
    $result['users'][] = $user;
}

// Get all servers with user info
$servers_query = $conn->query("SELECT s.id, s.user_id, s.IP, s.PEMILIK, s.AREA, u.USERNAME FROM server s LEFT JOIN user u ON s.user_id = u.id LIMIT 20");
while ($server = $servers_query->fetch_assoc()) {
    $result['servers'][] = $server;
}

// Get cache files
$cacheDir = __DIR__ . '/trafik-cache';
if (is_dir($cacheDir)) {
    $files = scandir($cacheDir);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..' && strpos($file, 'server_traffic_data_') === 0) {
            $fpath = $cacheDir . '/' . $file;
            $size = filesize($fpath);
            $content = file_get_contents($fpath);
            $data = json_decode($content, true);
            $result['cache_files'][] = [
                'file' => $file,
                'size' => $size,
                'entries' => is_array($data) ? count($data) : 0,
                'latest' => is_array($data) && !empty($data) ? end($data) : null
            ];
        }
    }
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
