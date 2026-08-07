<?php
// Test API file to check MikroTik connection
header('Content-Type: application/json');

// Start session
session_start();

// Test 1: Check session
if (!isset($_SESSION['status']) || $_SESSION['status'] != 'login') {
    http_response_code(401);
    echo json_encode(['test' => 'Session', 'result' => 'FAILED', 'message' => 'Not logged in']);
    exit;
}

echo json_encode(['test' => 'Session', 'result' => 'OK', 'user' => $_SESSION['PEMILIK'] ?? 'Unknown']);

// Test 2: Try to load RouterOS class
if (!file_exists('routeros_api.class.php')) {
    http_response_code(500);
    echo json_encode(['test' => 'RouterOS Class', 'result' => 'FAILED', 'message' => 'File not found']);
    exit;
}

require 'routeros_api.class.php';
echo json_encode(['test' => 'RouterOS Class', 'result' => 'OK']);

// Test 3: Get test IPs from database
require '../koneksidb.php';

$sql = "SELECT * FROM server LIMIT 1";
$query = mysqli_query($conn, $sql);
$server = mysqli_fetch_array($query);

if (!$server) {
    http_response_code(404);
    echo json_encode(['test' => 'Server List', 'result' => 'FAILED', 'message' => 'No servers found']);
    exit;
}

echo json_encode([
    'test' => 'Server List',
    'result' => 'OK',
    'ip' => $server['IP'],
    'brand' => $server['BRAND'],
    'user' => $server['PEMILIK']
]);

// Test 4: Try to connect to MikroTik
$api = new RouterosAPI();
$connected = $api->connect($server['IP'], $server['PEMILIK'], $server['PASSWORD']);

if (!$connected) {
    http_response_code(503);
    echo json_encode(['test' => 'MikroTik Connection', 'result' => 'FAILED', 'ip' => $server['IP']]);
    exit;
}

// Test 5: Try to get resource info
$resource = $api->comm("/system/resource/print");

if (empty($resource)) {
    http_response_code(500);
    echo json_encode(['test' => 'System Resource', 'result' => 'FAILED']);
    $api->disconnect();
    exit;
}

// Success!
echo json_encode([
    'test' => 'All Tests',
    'result' => 'SUCCESS',
    'ip' => $server['IP'],
    'version' => $resource[0]['version'] ?? 'Unknown',
    'uptime' => $resource[0]['uptime'] ?? 'N/A'
]);

$api->disconnect();
