<?php
// API Grafik Transaksi Prabayar Sesuai Periode Penggunaan (dashboard)
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Sebelumnya cuma dukung username+password -- tidak pernah baca param
// `key`/`api_key`, PADAHAL settingsapi.php mendokumentasikan resmi akses via
// API key. Diganti ke _bootstrap.php::api_authenticate() (session ->
// username+password -> API key dari tabel `apikey`) sama seperti api/odp.php dkk.
require_once '../koneksibilling.php';
require_once '_bootstrap.php';
session_start();
api_cors();

$input = api_read_input();
$tahun = $input['tahun'] ?? date('Y');

$auth = api_authenticate($conn, $input);
$pemilik = $auth['pemilik'];
if ($auth['method'] === 'apikey') {
    api_rate_limit($conn, $auth['api_key']);
}

// Sisa file di bawah ini masih memakai $user_id (integer user.id), bukan
// $pemilik (USERNAME) -- cek_login_api() lama mengembalikan id, bukan
// username. Resolve id-nya dari username hasil autentikasi supaya query
// "WHERE user_id = ..." di bawah tetap jalan seperti sebelumnya.
$stmt_uid = $conn->prepare("SELECT id FROM user WHERE USERNAME = ?");
$stmt_uid->bind_param('s', $pemilik);
$stmt_uid->execute();
$row_uid = $stmt_uid->get_result()->fetch_assoc();
$user_id = $row_uid['id'] ?? null;
if (!$user_id) {
    echo json_encode(['success' => false, 'error' => 'Autentikasi gagal']);
    exit;
}

// Get user servers based on user_id
$userServers = [];
$queryServer = mysqli_query($conn, "SELECT DISTINCT PEMILIK FROM server WHERE user_id = ".intval($user_id));
while($row = mysqli_fetch_assoc($queryServer)) {
    $userServers[] = $row['PEMILIK'];
}

if (empty($userServers)) {
    echo json_encode(['success' => true, 'data' => []]);
    exit;
}

$userServerList = "'" . implode("','", array_map(function($x) use ($conn) { return mysqli_real_escape_string($conn, $x); }, $userServers)) . "'";

$bulan_nama = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$data = [];

// Query by PENGUNAAN field (prepaid period) like web version
foreach ($bulan_nama as $idx => $bulan) {
    $bulan_num = str_pad($idx + 1, 2, '0', STR_PAD_LEFT);
    $periode = $bulan . ' ' . $tahun;
    $periode_lower = strtolower($periode);
    
    $query = "SELECT 
        COUNT(*) as jumlah_transaksi, 
        COALESCE(SUM(HARGA), 0) as harga 
        FROM transaksi 
        WHERE LOWER(TRIM(PENGUNAAN)) = '" . mysqli_real_escape_string($conn, $periode_lower) . "' 
        AND UPPER(COALESCE(STATUS, '')) = 'BERHASIL'
        AND PEMILIK IN ($userServerList)";
    
    $result = mysqli_query($conn, $query);
    if ($result) {
        $row = mysqli_fetch_assoc($result);
        $data[] = [
            'bulan' => $periode,
            'jumlah_transaksi' => (int)($row['jumlah_transaksi'] ?? 0),
            'harga' => (int)($row['harga'] ?? 0)
        ];
    }
}

echo json_encode(['success' => true, 'data' => $data]);
exit;