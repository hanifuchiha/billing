<?php
header('Content-Type: application/json; charset=utf-8');
require 'header_api.php'; // Session and database connection

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

$username = isset($input['username']) ? mysqli_real_escape_string($conn, $input['username']) : '';
$port = isset($input['port']) ? intval($input['port']) : 0;
$paket = isset($input['paket']) ? mysqli_real_escape_string($conn, $input['paket']) : '';
$tempo = isset($input['tempo']) ? mysqli_real_escape_string($conn, $input['tempo']) : 'AUTO';
$service = isset($input['service']) ? strtoupper(mysqli_real_escape_string($conn, $input['service'])) : 'L2TP';
$nama = isset($input['nama']) ? mysqli_real_escape_string($conn, $input['nama']) : '';

// Validate inputs
if (empty($username) || $port <= 0 || empty($paket) || empty($nama)) {
    echo json_encode(['success' => false, 'message' => 'Data tidak lengkap']);
    exit;
}

// Check if user has enough balance
$sql_user = "SELECT SALDO FROM user WHERE NAMA = '$nama'";
$result_user = mysqli_query($conn, $sql_user);
$row_user = mysqli_fetch_array($result_user);

if (!$row_user) {
    echo json_encode(['success' => false, 'message' => 'User tidak ditemukan']);
    exit;
}

$saldo = intval($row_user['SALDO']);

// Get package price
if ($paket === 'FREE VPN') {
    $harga = 0;
} else {
    $sql_paket = "SELECT HARGA FROM paket WHERE PAKET = '$paket' AND PEMILIK = 'VPNQ'";
    $result_paket = mysqli_query($conn, $sql_paket);
    $row_paket = mysqli_fetch_array($result_paket);
    
    if (!$row_paket) {
        echo json_encode(['success' => false, 'message' => 'Paket tidak ditemukan']);
        exit;
    }
    
    $harga = intval($row_paket['HARGA']);
}

// Check balance
if ($saldo < $harga) {
    echo json_encode(['success' => false, 'message' => 'Saldo tidak cukup']);
    exit;
}

// Load configuration
$config_file = dirname(__FILE__) . '/../config.json';
$config = [];
if (file_exists($config_file)) {
    $config = json_decode(file_get_contents($config_file), true);
}

$router_ip = isset($config['router_ip']) ? $config['router_ip'] : '';
$router_user = isset($config['router_user']) ? $config['router_user'] : '';
$router_pass = isset($config['router_pass']) ? $config['router_pass'] : '';
$ippublic = isset($config['ippublic']) ? $config['ippublic'] : '';

// In a real scenario, you would connect to MikroTik API here
// For now, we'll just create the database entry
// TODO: Implement MikroTik API connection

// Create VPN connection record
$tanggal = date('Y-m-d H:i:s');
$idpel = $username;
$mode = $service === 'PPTP' ? 'pptp' : 'l2tp';
$alamat = "$ippublic:$port=>$port"; // Simplified for now

$sql_insert = "INSERT INTO pelanggan (
    BRAND, MODE, NAMA, IDPEL, PAKET, ALAMAT, TIKOR, TANGGALPASANG, STATUS, TEMPO, KETERANGAN
) VALUES (
    'VPN', '$mode', '$nama', '$idpel', '$paket', '$alamat', '$ippublic', '$tanggal', 'AKTIF', '$tempo', 'VPN Connection from Mobile App'
)";

if (mysqli_query($conn, $sql_insert)) {
    // Deduct from user balance
    $new_saldo = $saldo - $harga;
    $sql_update = "UPDATE user SET SALDO = $new_saldo WHERE NAMA = '$nama'";
    mysqli_query($conn, $sql_update);
    
    echo json_encode([
        'success' => true,
        'message' => 'VPN berhasil dibeli!',
        'vpn_id' => mysqli_insert_id($conn),
        'saldo' => $new_saldo
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Gagal membuat VPN: ' . mysqli_error($conn)]);
}

mysqli_close($conn);
?>
