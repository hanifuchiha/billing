<?php
// api/ping.php
header('Content-Type: application/json');
require_once '../koneksibilling.php';

$ip = $_GET['ip'] ?? '';
if (!$ip) {
    echo json_encode(['success' => false, 'error' => 'IP tidak ditemukan']);
    exit;
}

// Cek ping (gunakan ping -n 1 untuk Windows, -c 1 untuk Linux)
$os = strtoupper(substr(PHP_OS, 0, 3));
if ($os === 'WIN') {
    $cmd = "ping -n 1 -w 1000 " . escapeshellarg($ip);
} else {
    $cmd = "ping -c 1 -W 1 " . escapeshellarg($ip);
}

exec($cmd, $output, $status);
$is_online = ($status === 0);

// Response
if ($is_online) {
    echo json_encode(['success' => true, 'online' => true]);
} else {
    echo json_encode(['success' => true, 'online' => false]);
}
