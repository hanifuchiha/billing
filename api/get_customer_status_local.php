<?php
// api/get_customer_status_local.php
header('Content-Type: application/json');
require_once '../koneksibilling.php';

$idpel = isset($_POST['idpel']) ? trim($_POST['idpel']) : '';
$ipserver = isset($_POST['ip']) ? trim($_POST['ip']) : '';
$user = isset($_POST['us']) ? trim($_POST['us']) : '';
$pass = isset($_POST['ps']) ? trim($_POST['ps']) : '';

if (!$idpel || !$ipserver || !$user || !$pass) {
    echo json_encode(['success' => false, 'error' => 'Parameter kurang']);
    exit;
}

// Cek status pelanggan dari radius/mikrotik/logic lokal
// --- CONTOH LOGIC SEDERHANA ---
$status = 'Offline';
$login_via = '-';
$paket_aktif = '-';
$remote_ip = '-';
$mac = '-';

// TODO: Implementasi pengecekan status real (radius/mikrotik/ACS/dll)
// Simulasi: jika idpel genap, status Online, ganjil Offline
if (is_numeric($idpel) && $idpel % 2 == 0) {
    $status = 'Online';
    $login_via = 'RADIUS';
    $paket_aktif = 'Aktif';
    $remote_ip = '192.168.1.' . ($idpel % 255);
    $mac = strtoupper(dechex($idpel)) . ':AA:BB:CC:DD:EE';
}

$response = [
    'success' => true,
    'status' => $status,
    'login_via' => $login_via,
    'cekexpired' => $paket_aktif,
    'remote_ip' => $remote_ip,
    'active_caller_id' => $mac,
    'last_caller_secret' => $mac
];
echo json_encode($response);
