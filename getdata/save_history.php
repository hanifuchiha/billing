<?php
$deviceId = $_POST['deviceId'] ?? '';
$type = $_POST['type'] ?? ''; // 'trafik' or 'active'
$data = $_POST['data'] ?? '';

if (!$deviceId || !$type || !$data) {
    echo json_encode(['error' => 'Missing parameters']);
    exit;
}

$filename = "traffic/history_{$type}_{$deviceId}.json";
file_put_contents($filename, $data);

echo json_encode(['success' => true]);
?>