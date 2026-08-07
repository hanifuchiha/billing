<?php
// API: customer_traffic_chart.php
header('Content-Type: application/json');
require_once '../koneksibilling.php';
// Pastikan Anda punya library/method untuk query ke Mikrotik (RouterOS API)
require_once '../routeros_api.class.php';

$idpel = isset($_GET['idpel']) ? trim($_GET['idpel']) : '';
if (!$idpel) {
    echo json_encode(['success' => false, 'error' => 'Parameter kurang']);
    exit;
}

// Ambil info server dan interface dari database
$sql = "SELECT area, pemilik FROM pelanggan WHERE IDPEL = '" . $conn->real_escape_string($idpel) . "' LIMIT 1";
$res = $conn->query($sql);
if (!$res || $res->num_rows == 0) {
    echo json_encode(['success' => false, 'error' => 'Pelanggan tidak ditemukan']);
    exit;
}
$row = $res->fetch_assoc();
$area = $row['area'];
$pemilik = $row['pemilik'];

// Ambil IP, user, pass server
$sql_srv = "SELECT IP, PASSWORD, PEMILIK FROM server WHERE AREA = '" . $conn->real_escape_string($area) . "' AND PEMILIK = '" . $conn->real_escape_string($pemilik) . "' LIMIT 1";
$res_srv = $conn->query($sql_srv);
if (!$res_srv || $res_srv->num_rows == 0) {
    echo json_encode(['success' => false, 'error' => 'Server tidak ditemukan']);
    exit;
}
$row_srv = $res_srv->fetch_assoc();
$ipserver = $row_srv['IP'];
$user = $row_srv['PEMILIK'];
$pass = $row_srv['PASSWORD'];

// Query ke Mikrotik
$API = new RouterosAPI();
if (!$API->connect($ipserver, $user, $pass)) {
    echo json_encode(['success' => false, 'error' => 'Gagal konek Mikrotik']);
    exit;
}

// Ambil data trafik (misal 24 jam terakhir, 5 menit interval)
$interface = $idpel; // diasumsikan nama interface = idpel
$traffic = $API->comm('/interface/monitor-traffic', [
    'interface' => $interface,
    'once' => ''
]);
// Contoh dummy, sesuaikan dengan hasil query Mikrotik Anda
$data = [];
if ($traffic && is_array($traffic)) {
    foreach ($traffic as $row) {
        $data[] = [
            'time' => date('H:i'),
            'rx' => $row['rx-bits-per-second'] ?? 0,
            'tx' => $row['tx-bits-per-second'] ?? 0
        ];
    }
}
$API->disconnect();
echo json_encode(['success' => true, 'data' => $data]);
exit;
