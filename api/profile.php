<?php
// Diganti ke _bootstrap.php::api_authenticate() (session -> username+password ->
// API key dari tabel `apikey`) -- sebelumnya tidak pernah baca param `key`/`api_key`.
require_once '../koneksibilling.php';
require_once '_bootstrap.php';
session_start();
api_cors();

$input = api_read_input();
$auth = api_authenticate($conn, $input);
$pemilik = $auth['pemilik'];
if ($auth['method'] === 'apikey') {
    api_rate_limit($conn, $auth['api_key']);
}

$stmt = $conn->prepare("SELECT * FROM user WHERE USERNAME = ? LIMIT 1");
$stmt->bind_param("s", $pemilik);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
if (!$row) {
    echo json_encode(['success' => false, 'error' => 'Autentikasi gagal']);
    exit;
}

$payload = [
    'USERNAME' => (string)($row['USERNAME'] ?? ''),
    'EMAIL' => (string)($row['EMAIL'] ?? ''),
    'NOWA' => (string)($row['NOWA'] ?? ''),
    'STATUS' => (string)($row['STATUS'] ?? ''),
    'DOMAIN' => (string)($row['domain'] ?? ($row['DOMAIN'] ?? '')),
    'SALDO' => (string)($row['SALDO'] ?? '0'),
    'SERVER' => (string)($row['server'] ?? ($row['SERVER'] ?? '')),
    'EXPIRED' => (string)($row['EXPIRED'] ?? ($row['expired'] ?? '')),
    'CREATED_AT' => (string)($row['created_at'] ?? ($row['CREATED_AT'] ?? ''))
];

echo json_encode(['success' => true, 'data' => $payload]);