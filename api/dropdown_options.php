<?php
// Sebelumnya cuma dukung username+password -- tidak pernah baca param
// `key`/`api_key`, PADAHAL settingsapi.php mendokumentasikan resmi akses via
// API key. Diganti ke _bootstrap.php::api_authenticate() (session ->
// username+password -> API key dari tabel `apikey`) sama seperti api/odp.php dkk.
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

// Sisa file di bawah ini masih memakai $user_id (integer user.id), bukan
// $pemilik (USERNAME) -- cek_login_api() lama mengembalikan id, bukan
// username. Resolve id-nya dari username hasil autentikasi supaya query
// "WHERE user_id = ?" di bawah tetap jalan seperti sebelumnya.
$stmt_uid = $conn->prepare("SELECT id FROM user WHERE USERNAME = ?");
$stmt_uid->bind_param('s', $pemilik);
$stmt_uid->execute();
$row_uid = $stmt_uid->get_result()->fetch_assoc();
$user_id = $row_uid['id'] ?? null;
if (!$user_id) {
    echo json_encode(['success' => false, 'error' => 'Autentikasi gagal']);
    exit;
}

// Get servers owned by user
$servers = [];
$stmt = $conn->prepare("SELECT PEMILIK, BRAND, AREA FROM server WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $servers[] = [
        'value' => $row['PEMILIK'],
        'label' => $row['BRAND'] . ' - ' . $row['AREA']
    ];
}


// Filter by area if provided
$area = isset($_GET['area']) ? $conn->real_escape_string($_GET['area']) : '';

// Get ODPs owned by user (filtered by area if set)
$odp = [];
$serverIds = array_map(function($s) use ($conn) { return "'" . $conn->real_escape_string($s['value']) . "'"; }, $servers);
$serverList = count($serverIds) > 0 ? implode(',', $serverIds) : "''";
$whereOdp = "p.PEMILIK IN ($serverList) AND p.ODP IS NOT NULL AND p.ODP != ''";
if ($area !== '') {
    $whereOdp .= " AND p.AREA = '$area'";
}
$q = $conn->query("SELECT DISTINCT p.ODP, p.AREA, MAX(o.NAME) AS ODP_NAME FROM pelanggan p LEFT JOIN odp o ON o.KODE = p.ODP AND o.PEMILIK = p.PEMILIK WHERE $whereOdp GROUP BY p.ODP, p.AREA ORDER BY p.ODP ASC");
while ($row = $q->fetch_assoc()) {
    $label = $row['ODP'];
    if ($row['ODP_NAME']) $label .= ' - ' . $row['ODP_NAME'];
    if ($row['AREA']) $label .= ' (' . $row['AREA'] . ')';
    $odp[] = [
        'value' => $row['ODP'],
        'label' => $label
    ];
}

// Get Pakets owned by user (filtered by area if set)
$pakets = [];
$wherePaket = "PEMILIK IN ($serverList)";
if ($area !== '') {
    $wherePaket .= " AND AREA = '$area'";
}
$q = $conn->query("SELECT DISTINCT PAKET FROM paket WHERE $wherePaket ORDER BY PAKET ASC");
while ($row = $q->fetch_assoc()) {
    $pakets[] = [
        'value' => $row['PAKET'],
        'label' => $row['PAKET']
    ];
}

echo json_encode([
    'success' => true,
    'servers' => $servers,
    'odps' => $odp,
    'pakets' => $pakets
]);
