<?php
// File: api_trafik_chart.php
header('Content-Type: application/json');
require_once '../routeros_api.class.php';
require_once '../koneksibilling.php'; 

$idpel = $_GET['idpel'] ?? '';
if ($idpel === '') {
    echo json_encode(['error' => 'Parameter idpel wajib diisi']);
    exit;
}

// Contoh: mapping idpel ke user PPPoE dan info mikrotik
// Anda bisa sesuaikan query sesuai struktur database Anda



// Ganti 'userpppoe' dengan nama kolom PPPoE yang benar, misal 'USERNAME', 'USER', atau 'PPPOE' sesuai database Anda
$stmt = $conn->prepare("SELECT IDPEL, USERNAME, PEMILIK, AREA FROM pelanggan WHERE IDPEL = ? LIMIT 1");
$stmt->bind_param('s', $idpel);
$stmt->execute();
$res = $stmt->get_result();
if (!($pel = $res->fetch_assoc())) {
    echo json_encode(['error' => 'ID pelanggan tidak ditemukan']);
    exit;
}
$stmt->close();

$pemilik = $pel['PEMILIK'];
$area = $pel['AREA'];


// 2. Cari data server yang cocok dengan PEMILIK dan AREA pelanggan

// Kolom server yang benar: IP (ip:port), PEMILIK (username), PASSWORD (password)
$stmt = $conn->prepare("SELECT IP, PEMILIK, PASSWORD FROM server WHERE (PEMILIK = ? AND AREA = ?) LIMIT 1");
$stmt->bind_param('ss', $pemilik, $area);
$stmt->execute();
$res = $stmt->get_result();
if (!($srv = $res->fetch_assoc())) {
    echo json_encode(['error' => 'Server untuk pelanggan tidak ditemukan']);
    exit;
}
$stmt->close();

$ipserver = $srv['IP'];
$userserver = $srv['PEMILIK'];
$passwordserver = $srv['PASSWORD'];

$API = new RouterosAPI();
if (!$API->connect($ipserver, $userserver, $passwordserver)) {
    echo json_encode(['error' => 'Gagal koneksi ke Mikrotik']);
    exit;
}

// Ambil trafik history (misal 10 data terakhir, sesuaikan dengan log/history Anda)
// Jika tidak ada log, ambil trafik saat ini saja
$data = [];
// --- Contoh ambil trafik saat ini ---
$API->write('/interface/monitor-traffic', false);
$API->write('=interface=<pppoe-' . $idpel . '>', false);
$API->write('=once=');
$traffic = $API->read();
if (!empty($traffic[0])) {
    $download = round($traffic[0]['tx-bits-per-second'] / 1_000_000, 2); // Mbps
    $upload = round($traffic[0]['rx-bits-per-second'] / 1_000_000, 2); // Mbps
    $data[] = [
        'timestamp' => date('Y-m-d H:i:s'),
        'download' => $download,
        'upload' => $upload
    ];
}
// --- Jika ada log/history, tambahkan di sini ---

$API->disconnect();

// Output
echo json_encode(['data' => $data]);
