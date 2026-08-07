<?php
// server_traffic_history.php - API untuk traffic history dari cache files
// Diganti ke _bootstrap.php::api_authenticate() (session -> username+password ->
// API key dari tabel `apikey`) -- sebelumnya WAJIB username+password plaintext.
require_once '../koneksibilling.php';
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

$stmt_u = $conn->prepare("SELECT id FROM user WHERE USERNAME = ? LIMIT 1");
$stmt_u->bind_param("s", $pemilik);
$stmt_u->execute();
$urow = $stmt_u->get_result()->fetch_assoc();
if (!$urow) {
    echo json_encode(['success' => false, 'error' => 'Autentikasi gagal']);
    exit;
}
$user_id = $urow['id'];

if (!$server_id) {
    echo json_encode(['success' => false, 'error' => 'server_id required']);
    exit;
}

// Verify server belongs to user (using user_id)
$stmt = $conn->prepare("SELECT id FROM server WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $server_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows == 0) {
    echo json_encode(['success' => false, 'error' => 'Server not found or access denied']);
    exit;
}

// Baca cache file
$cacheDir = __DIR__ . '/trafik-cache';
$cacheFile = $cacheDir . "/server_traffic_data_{$server_id}.json";

$history = [];
if (file_exists($cacheFile)) {
    $cacheData = json_decode(file_get_contents($cacheFile), true);
    if (!empty($cacheData) && is_array($cacheData)) {
        foreach ($cacheData as $data) {
            $rx_bps = $data['rx'] ?? 0;
            $tx_bps = $data['tx'] ?? 0;
            
            $history[] = [
                'timestamp' => $data['timestamp'] ?? '',
                'upload_mbps' => round($rx_bps / 1_000_000, 2),
                'download_mbps' => round($tx_bps / 1_000_000, 2)
            ];
        }
    }
}

echo json_encode([
    'success' => true,
    'server_id' => $server_id,
    'history' => $history
]);
