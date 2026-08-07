<?php
// Start session for API requests (optional, just to check)
session_start();

// Set JSON header first
header('Content-Type: application/json');

// For ping endpoint, we allow both authenticated and unauthenticated requests
// Only require session if user is trying to access protected functions
$requireSession = false; // Ping is a simple connectivity check

if ($requireSession && (!isset($_SESSION['status']) || $_SESSION['status'] != 'login')) {
    http_response_code(401);
    echo json_encode(['error' => 'Session expired', 'status' => 'Offline']);
    exit;
}

if (!isset($_GET['host']) || empty($_GET['host'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Host tidak diberikan', 'status' => 'Offline']);
    exit;
}

$hostInput = $_GET['host'];

// Parse host:port format if provided
$host = $hostInput;
$port = 8728; // Default MikroTik API port

// If host contains port, extract it
if (strpos($hostInput, ':') !== false) {
    $parts = explode(':', $hostInput);
    $host = $parts[0];
    $port = (int)$parts[1];
}

// Validate IP or Domain
if (!filter_var($host, FILTER_VALIDATE_IP) && !preg_match('/^([a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/', $host)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid host format: ' . $hostInput, 'status' => 'Offline']);
    exit;
}
$timeout = 2; // Timeout dalam detik

$status = "Offline";
$message = "Unable to connect";

// Try to connect to MikroTik API port
$connection = @fsockopen($host, $port, $errno, $errstr, $timeout);

if ($connection) {
    $status = "Online";
    $message = "Connected successfully";
    fclose($connection);
} else {
    // Try fallback port 80
    $connection = @fsockopen($host, 80, $errno, $errstr, $timeout);
    if ($connection) {
        $status = "Online";
        $message = "Connected (HTTP fallback)";
        fclose($connection);
    }
}

http_response_code(200);
echo json_encode([
    'status' => $status,
    'host' => $host,
    'port' => $port,
    'message' => $message,
    'errno' => isset($errno) ? $errno : 0,
    'errstr' => isset($errstr) ? $errstr : ''
]);
exit;
