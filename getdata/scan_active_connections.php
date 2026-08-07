<?php
/**
 * Scan Active PPP Connections yang TIDAK ADA di database pelanggan
 * Method: GET
 * Params: server (opsional, filter by PEMILIK)
 * Response: JSON { success, data[], total_active_checked, not_in_db, ... }
 */
include '../cek-sesi.php';
require 'routeros_api.class.php';

header('Content-Type: application/json');

$selectedServer = isset($_GET['server']) ? trim((string)$_GET['server']) : '';
$selectedServerEsc = mysqli_real_escape_string($conn, $selectedServer);
$currentUserId = isset($current_user_id) ? (int)$current_user_id : 0;

// Build server query dengan access control
$serverQuery = "SELECT IP, PEMILIK, PASSWORD, AREA, BRAND FROM server WHERE 1=1";
if ($selectedServer !== '') {
    $serverQuery .= " AND PEMILIK = '$selectedServerEsc'";
}
if ($AKSES === 'ASSISTANT') {
    if (!isset($area_list) || trim((string)$area_list) === '') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Area assistant tidak ditemukan.']);
        exit;
    }
    $serverQuery .= " AND AREA IN ($area_list)";
} elseif ($AKSES !== 'ADMIN') {
    $serverQuery .= " AND user_id = $currentUserId";
}

$serverResult = mysqli_query($conn, $serverQuery);
if (!$serverResult) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Gagal mengambil data server.', 'error' => mysqli_error($conn)]);
    exit;
}

// Ambil semua IDPEL dari database pelanggan -- di-scope SAMA dgn $serverQuery
// di atas, konsisten dgn pola scan_unregistered_pppoe.php. Tanpa ini, username
// koneksi aktif yg kebetulan sama dgn IDPEL pelanggan di AREA/PEMILIK LAIN
// bisa keliru dianggap "sudah terdaftar" utk area ini ($area_list sudah
// dipastikan ada isinya oleh pengecekan di atas kalau $AKSES==='ASSISTANT').
$pelangganIdSet = [];
$pelangganQuery = "SELECT IDPEL FROM pelanggan WHERE 1=1";
if ($selectedServer !== '') {
    $pelangganQuery .= " AND PEMILIK = '$selectedServerEsc'";
}
if ($AKSES === 'ASSISTANT') {
    $pelangganQuery .= " AND AREA IN ($area_list)";
} elseif ($AKSES !== 'ADMIN') {
    $pelangganQuery .= " AND PEMILIK IN (SELECT PEMILIK FROM server WHERE user_id = $currentUserId)";
}
$pelangganResult = mysqli_query($conn, $pelangganQuery);
if ($pelangganResult) {
    while ($row = mysqli_fetch_assoc($pelangganResult)) {
        $pelangganIdSet[strtolower(trim((string)($row['IDPEL'] ?? '')))] = true;
    }
}

$servers = [];
$serverSeen = [];
while ($row = mysqli_fetch_assoc($serverResult)) {
    $ip       = trim((string)($row['IP'] ?? ''));
    $username = trim((string)($row['PEMILIK'] ?? ''));
    $password = (string)($row['PASSWORD'] ?? '');
    $area     = (string)($row['AREA'] ?? '');
    $brand    = (string)($row['BRAND'] ?? '');

    if ($ip === '' || $username === '') continue;
    $uniqueKey = strtolower($ip . '|' . $username);
    if (isset($serverSeen[$uniqueKey])) continue;
    $serverSeen[$uniqueKey] = true;

    $servers[] = ['ip' => $ip, 'user' => $username, 'password' => $password, 'area' => $area, 'brand' => $brand];
}

if (empty($servers)) {
    echo json_encode(['success' => true, 'data' => [], 'total_active_checked' => 0, 'not_in_db' => 0, 'message' => 'Tidak ada server ditemukan.']);
    exit;
}

$results = [];
$totalActiveChecked = 0;
$failedServers = 0;

foreach ($servers as $srv) {
    $API = new RouterosAPI();
    $API->debug = false;
    
    if (!$API->connect($srv['ip'], $srv['user'], $srv['password'])) {
        $failedServers++;
        continue;
    }

    $activeList = $API->comm("/ppp/active/print");
    $API->disconnect();

    if (!is_array($activeList)) continue;

    foreach ($activeList as $active) {
        $name = trim((string)($active['name'] ?? ''));
        if ($name === '') continue;

        $totalActiveChecked++;

        // Cek apakah ada di database pelanggan
        if (isset($pelangganIdSet[strtolower($name)])) continue;

        $results[] = [
            'active_id'   => (string)($active['.id'] ?? ''),
            'username'    => $name,
            'service'     => (string)($active['service'] ?? '-'),
            'caller_id'   => (string)($active['caller-id'] ?? '-'),
            'address'     => (string)($active['address'] ?? '-'),
            'uptime'      => (string)($active['uptime'] ?? '-'),
            'encoding'    => (string)($active['encoding'] ?? '-'),
            'server_ip'   => $srv['ip'],
            'server_user' => $srv['user'],
            'server_area' => $srv['area'],
            'server_brand'=> $srv['brand'],
        ];
    }
}

echo json_encode([
    'success'             => true,
    'data'                => $results,
    'total_active_checked'=> $totalActiveChecked,
    'not_in_db'           => count($results),
    'failed_server_count' => $failedServers,
    'servers_scanned'     => count($servers),
]);
