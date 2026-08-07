<?php
// server_monitor.php - API untuk dashboard Android dengan data dari trafik-cache
// Diganti ke _bootstrap.php::api_authenticate() (session -> username+password ->
// API key dari tabel `apikey`) -- sebelumnya WAJIB username+password plaintext, tidak
// pernah baca param `key`/`api_key`, dan tidak pernah cek sesi browser.
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

error_log("server_monitor.php auth success - pemilik: $pemilik");

// Ambil user_id dari tabel user berdasarkan username
$stmt_user = $conn->prepare("SELECT id FROM user WHERE USERNAME = ?");
$stmt_user->bind_param("s", $pemilik);
$stmt_user->execute();
$result_user = $stmt_user->get_result();
if ($result_user->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'User tidak ditemukan']);
    exit;
}
$row_user = $result_user->fetch_assoc();
$user_id = $row_user['id'];

error_log("server_monitor.php - user_id: $user_id");

// Ambil daftar server milik user berdasarkan user_id
$servers = [];
$stmt = $conn->prepare("SELECT id, IP, PEMILIK, AREA FROM server WHERE user_id = ? ORDER BY AREA");
if (!$stmt) {
    error_log("server_monitor.php prepare error: " . $conn->error);
    echo json_encode(['success' => false, 'error' => 'Query prepare error: ' . $conn->error]);
    exit;
}
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $servers[] = $row;
}

error_log("server_monitor.php - found servers: " . count($servers));

// Folder cache
$cacheDir = __DIR__ . '/trafik-cache';

$data = [];
foreach ($servers as $srv) {
    $id = $srv['id'] ?? '';
    $ip = $srv['IP'] ?? '';
    
    // Ambil hanya IP address tanpa port jika ada
    if (strpos($ip, ':') !== false) {
        $ip = explode(':', $ip)[0];
    }
    
    $area = $srv['AREA'] ?? '';
    $pemilik_server = $srv['PEMILIK'] ?? '';

    // Cek status server dengan simple ping
    $server_status = 'OFFLINE';
    if (!empty($ip)) {
        // Simple ping check (timeout 2 detik)
        $ping_result = shell_exec("ping -c 1 -W 2 " . escapeshellarg($ip) . " 2>&1");
        if (strpos($ping_result, 'bytes from') !== false || strpos($ping_result, 'time=') !== false) {
            $server_status = 'ONLINE';
        }
    }

    // Baca data dari cache file
    $cacheFile = $cacheDir . "/server_traffic_data_{$id}.json";
    $upload_mbps = 0;
    $download_mbps = 0;
    $last_update = null;
    
    if (file_exists($cacheFile)) {
        $cacheJson = file_get_contents($cacheFile);
        $cacheData = json_decode($cacheJson, true);
        if (!empty($cacheData) && is_array($cacheData)) {
            // Ambil data terakhir
            $latest = end($cacheData);
            
            // rx = upload (data masuk ke server), tx = download (data keluar dari server)
            // Convert dari bits per second ke Mbps
            $rx_bps = $latest['rx'] ?? 0;
            $tx_bps = $latest['tx'] ?? 0;
            
            $upload_mbps = round($rx_bps / 1_000_000, 2);
            $download_mbps = round($tx_bps / 1_000_000, 2);
            $last_update = $latest['timestamp'] ?? null;
        }
        error_log("server_monitor.php - server $id cache loaded: upload=$upload_mbps, download=$download_mbps, status=$server_status");
    } else {
        error_log("server_monitor.php - server $id cache file not found: $cacheFile, status=$server_status");
    }

    $data[] = [
        'id' => $id,
        'pemilik' => $pemilik_server,
        'ip' => $ip,
        'area' => $area,
        'status' => $server_status,
        'upload_mbps' => $upload_mbps,
        'download_mbps' => $download_mbps,
        'last_update' => $last_update
    ];
}

error_log("server_monitor.php - returning " . count($data) . " servers");
echo json_encode(['success' => true, 'data' => $data]);
