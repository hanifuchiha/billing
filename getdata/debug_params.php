<?php
// Debug endpoint - check if parameter are passed correctly
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['status']) || $_SESSION['status'] != 'login') {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$ip = $_GET['ip'] ?? '';
$user = $_GET['user'] ?? '';
$pass = $_GET['password'] ?? '';

echo json_encode([
    'received' => [
        'ip' => $ip,
        'user' => $user,
        'password' => '***' . substr($pass, -3),
        'ip_length' => strlen($ip),
        'user_length' => strlen($user),
        'pass_length' => strlen($pass)
    ],
    'test_connection' => @fsockopen($ip, 8728, $errno, $errstr, 2) ? 'Connected' : 'Failed',
    'timestamp' => date('Y-m-d H:i:s')
]);
