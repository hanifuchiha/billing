<?php
// api/server_traffic_chart.php
// Diganti ke _bootstrap.php::api_authenticate() (session -> username+password ->
// API key dari tabel `apikey`) -- sebelumnya tidak pernah baca param `key`/`api_key`.
require_once '../koneksibilling.php';
require_once '_bootstrap.php';
session_start();
api_cors();

$method = $_SERVER['REQUEST_METHOD'];
$input = api_read_input();

$auth = api_authenticate($conn, $input);
$pemilik = $auth['pemilik'];
if ($auth['method'] === 'apikey') {
    api_rate_limit($conn, $auth['api_key']);
}

// Ambil user_id dari tabel user berdasarkan username login
$stmt = $conn->prepare("SELECT id FROM user WHERE USERNAME = ?");
$stmt->bind_param("s", $pemilik);
$stmt->execute();
$result_user = $stmt->get_result();
$user_id = null;
if ($result_user && $result_user->num_rows > 0) {
    $row_user = $result_user->fetch_assoc();
    $user_id = $row_user['id'];
}
if (!$user_id) {
    echo json_encode(['success' => false, 'error' => 'User ID tidak ditemukan']);
    exit;
}

// Ambil semua server milik user
$stmt2 = $conn->prepare("SELECT * FROM server WHERE user_id = ?");
$stmt2->bind_param("i", $user_id);
$stmt2->execute();
$result = $stmt2->get_result();
$servers = [];
while ($row = $result->fetch_assoc()) {
    $row['traffic'] = [];
    // Query traffic harian bulan ini untuk server ini
    $bulan = date('m');
    $tahun = date('Y');
    $server_id = $row['ID'];
    $sql = "SELECT DATE(waktu) as tanggal, SUM(rx) as rx, SUM(tx) as tx FROM server_traffic WHERE server_id = ? AND MONTH(waktu) = ? AND YEAR(waktu) = ? GROUP BY DATE(waktu) ORDER BY tanggal ASC";
    $stmt3 = $conn->prepare($sql);
    $stmt3->bind_param("iss", $server_id, $bulan, $tahun);
    $stmt3->execute();
    $res3 = $stmt3->get_result();
    while ($traf = $res3->fetch_assoc()) {
        $row['traffic'][] = $traf;
    }
    $servers[] = $row;
}
echo json_encode(['success' => true, 'data' => $servers]);
