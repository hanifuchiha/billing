<?php
// server_traffic.php - API untuk ambil trafik server per ID
// Diganti ke _bootstrap.php::api_authenticate() (session -> username+password ->
// API key dari tabel `apikey`) -- sebelumnya WAJIB username+password plaintext.
require_once '../koneksibilling.php';
require_once '../routeros_api.class.php';
require_once '_bootstrap.php';
session_start();
api_cors();

$input = api_read_input();
$server_id = $input['server_id'] ?? ($_GET['server_id'] ?? '');

$auth = api_authenticate($conn, $input);
$pemilik = $auth['pemilik'];
if ($auth['method'] === 'apikey') {
    api_rate_limit($conn, $auth['api_key']);
}

// Ambil data server berdasarkan ID dan user
$stmt = $conn->prepare("SELECT s.id FROM server s JOIN user u ON s.user_id = u.id WHERE s.id = ? AND u.USERNAME = ?");
$stmt->bind_param("ss", $server_id, $pemilik);
$stmt->execute();
$srv = $stmt->get_result()->fetch_assoc();

if (!$srv) {
    echo json_encode(['success' => false, 'error' => 'Server tidak ditemukan']);
    exit;
}

$traffic_history = [];
$json_file = "trafik-cache/server_traffic_data_{$server_id}.json";
if (file_exists($json_file)) {
    $traffic_history = json_decode(file_get_contents($json_file), true) ?: [];
    // Ambil hanya 50 data terakhir
    if (count($traffic_history) > 50) {
        $traffic_history = array_slice($traffic_history, -50);
    }
}
echo json_encode([
    'success' => true,
    'traffic_history' => $traffic_history
]);
