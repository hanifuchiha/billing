<?php
header('Content-Type: application/json; charset=utf-8');
require 'header_api.php'; // Session and database connection

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

$pool_name = isset($input['pool_name']) ? mysqli_real_escape_string($conn, $input['pool_name']) : '';
$ip_awal = isset($input['ip_awal']) ? mysqli_real_escape_string($conn, $input['ip_awal']) : '';
$ip_akhir = isset($input['ip_akhir']) ? mysqli_real_escape_string($conn, $input['ip_akhir']) : '';
$ip_local = isset($input['ip_local']) ? mysqli_real_escape_string($conn, $input['ip_local']) : '';
$nama = isset($input['nama']) ? mysqli_real_escape_string($conn, $input['nama']) : '';

// Validate inputs
if (empty($pool_name) || empty($ip_awal) || empty($ip_akhir) || empty($ip_local) || empty($nama)) {
    echo json_encode(['success' => false, 'message' => 'Data tidak lengkap']);
    exit;
}

// Validate IP format (simple check)
function isValidIp($ip) {
    return filter_var($ip, FILTER_VALIDATE_IP) !== false;
}

if (!isValidIp($ip_awal) || !isValidIp($ip_akhir) || !isValidIp($ip_local)) {
    echo json_encode(['success' => false, 'message' => 'Format IP tidak valid']);
    exit;
}

// Check for duplicate pool name
$sql_check = "SELECT COUNT(*) as count FROM pool WHERE pool_name = '$pool_name' AND pemilik = '$nama'";
$result_check = mysqli_query($conn, $sql_check);
$row_check = mysqli_fetch_array($result_check);

if ($row_check['count'] > 0) {
    echo json_encode(['success' => false, 'message' => 'Nama pool sudah digunakan']);
    exit;
}

// Check for IP range overlap with existing pools
$sql_overlap = "SELECT COUNT(*) as count FROM pool 
                WHERE pemilik = '$nama' AND (
                    (INET_ATON('$ip_awal') BETWEEN INET_ATON(ipawal) AND INET_ATON(ipakhir)) OR
                    (INET_ATON('$ip_akhir') BETWEEN INET_ATON(ipawal) AND INET_ATON(ipakhir)) OR
                    (INET_ATON(ipawal) BETWEEN INET_ATON('$ip_awal') AND INET_ATON('$ip_akhir')) OR
                    (INET_ATON(ipakhir) BETWEEN INET_ATON('$ip_awal') AND INET_ATON('$ip_akhir'))
                )";
$result_overlap = mysqli_query($conn, $sql_overlap);
$row_overlap = mysqli_fetch_array($result_overlap);

if ($row_overlap['count'] > 0) {
    echo json_encode(['success' => false, 'message' => 'Range IP sudah digunakan oleh pool lain']);
    exit;
}

// Insert IP Pool
$sql_insert = "INSERT INTO pool (pool_name, ipawal, ipakhir, iplocal, pemilik) 
               VALUES ('$pool_name', '$ip_awal', '$ip_akhir', '$ip_local', '$nama')";

if (mysqli_query($conn, $sql_insert)) {
    echo json_encode([
        'success' => true,
        'message' => 'IP Pool berhasil dibuat!',
        'pool_id' => mysqli_insert_id($conn)
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Gagal membuat IP Pool: ' . mysqli_error($conn)]);
}

mysqli_close($conn);
?>
