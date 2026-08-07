<?php
header('Content-Type: application/json');

$ip = $_GET['ip'] ?? '';
$ip = trim($ip);

if ($ip === '') {
    echo json_encode([
        'success' => false,
        'status' => 'UNKNOWN',
        'error' => 'IP wajib diisi'
    ]);
    exit;
}

$host = $ip;
$port = 8728;

if (strpos($ip, ':') !== false) {
    $parts = explode(':', $ip);
    $host = trim($parts[0]);
    $portCandidate = isset($parts[1]) ? (int)trim($parts[1]) : 0;
    if ($portCandidate > 0 && $portCandidate <= 65535) {
        $port = $portCandidate;
    }
}

if (!filter_var($host, FILTER_VALIDATE_IP) && !preg_match('/^[a-zA-Z0-9.-]+$/', $host)) {
    echo json_encode([
        'success' => false,
        'status' => 'UNKNOWN',
        'error' => 'Format IP/host tidak valid'
    ]);
    exit;
}

$status = 'OFFLINE';
$errno = 0;
$errstr = '';
$socket = @fsockopen($host, $port, $errno, $errstr, 2);
if ($socket) {
    $status = 'ONLINE';
    fclose($socket);
}

echo json_encode([
    'success' => true,
    'status' => $status,
    'host' => $host,
    'port' => $port
]);
