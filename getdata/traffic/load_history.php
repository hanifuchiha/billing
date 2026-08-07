<?php
$deviceId = $_GET['deviceId'] ?? '';
$type = $_GET['type'] ?? ''; // 'trafik' or 'active'

if (!$deviceId || !$type) {
    echo json_encode(['error' => 'Missing parameters']);
    exit;
}

$filename = "history_{$type}_{$deviceId}.json";
if (file_exists($filename)) {
    $data = file_get_contents($filename);
    echo $data;
} else {
    echo json_encode([]);
}
?>