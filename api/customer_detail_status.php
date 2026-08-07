<?php
// customer_detail_status.php
header('Content-Type: application/json');
require_once '../koneksibilling.php';

$idpel = $_GET['idpel'] ?? '';
if (!$idpel) {
    echo json_encode(['success' => false, 'error' => 'ID pelanggan tidak ditemukan']);
    exit;
}

// Ambil data pelanggan dasar
$q = $conn->query("SELECT * FROM pelanggan WHERE IDPEL = '" . $conn->real_escape_string($idpel) . "' LIMIT 1");
if (!$q || $q->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Pelanggan tidak ditemukan']);
    exit;
}
$row = $q->fetch_assoc();

// Default values
$status = 'Disconnected';
$paket_aktif = $row['PAKET'] ?? '-';
$down = 'N/A Mbps';
$up = 'N/A Mbps';
$ip = $row['IP'] ?? '-';
$mac = $row['MAC'] ?? '-';
$rx_dbm = 'Unknown';
$rx_redaman = 'N/A';

// Cek status online/offline dari log server jika ada
$logfile = dirname(__DIR__) . "/serverlog/" . $row['PEMILIK'] . ".txt";
if (file_exists($logfile)) {
    $json = file_get_contents($logfile);
    $d = json_decode($json, true);
    if (isset($d['pelanggan']) && isset($d['pelanggan'][$idpel])) {
        $p = $d['pelanggan'][$idpel];
        $status = $p['status'] ?? 'Connected local';
        $paket_aktif = $p['paket'] ?? $paket_aktif;
        $down = $p['down'] ?? $down;
        $up = $p['up'] ?? $up;
        $ip = $p['ip'] ?? $ip;
        $mac = $p['mac'] ?? $mac;
        $rx_dbm = $p['rx_dbm'] ?? $rx_dbm;
        $rx_redaman = $p['rx_redaman'] ?? $rx_redaman;
    }
}

// Output JSON
$data = [
    'status' => $status,
    'paket_aktif' => $paket_aktif,
    'down' => $down,
    'up' => $up,
    'ip' => $ip,
    'mac' => $mac,
    'rx_dbm' => $rx_dbm,
    'rx_redaman' => $rx_redaman
];
echo json_encode(['success' => true, 'data' => $data]);
