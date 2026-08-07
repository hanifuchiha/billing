<?php
header('Content-Type: application/json; charset=utf-8');
require 'header_api.php'; // Session and database connection

// Get user and check session
$nama = isset($_GET['nama']) ? mysqli_real_escape_string($conn, $_GET['nama']) : '';

if (empty($nama)) {
    echo json_encode(['success' => false, 'message' => 'Nama user tidak ditemukan']);
    exit;
}

// Get IP Pool list for this user
$sql = "SELECT `id`, `pool_name`, `ipawal`, `ipakhir`, `iplocal` FROM `pool` WHERE `pemilik` = '$nama' ORDER BY `id` DESC";
$result = mysqli_query($conn, $sql);

if (!$result) {
    echo json_encode(['success' => false, 'message' => 'Query error: ' . mysqli_error($conn)]);
    exit;
}

$poolData = [];

while ($row = mysqli_fetch_array($result)) {
    $poolData[] = [
        'id' => $row['id'],
        'pool_name' => $row['pool_name'],
        'ip_awal' => $row['ipawal'],
        'ip_akhir' => $row['ipakhir'],
        'ip_local' => $row['iplocal']
    ];
}

echo json_encode([
    'success' => true,
    'data' => $poolData
]);
mysqli_close($conn);
?>
