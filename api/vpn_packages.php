<?php
header('Content-Type: application/json; charset=utf-8');
require 'header_api.php'; // Session and database connection

// Get VPN packages
$sql = "SELECT * FROM paket WHERE PEMILIK = 'VPNQ' ORDER BY HARGA ASC";
$result = mysqli_query($conn, $sql);

if (!$result) {
    echo json_encode(['success' => false, 'message' => 'Query error: ' . mysqli_error($conn)]);
    exit;
}

$packages = [];

// Add FREE VPN option
$packages[] = [
    'paket' => 'FREE VPN',
    'harga' => '0'
];

while ($row = mysqli_fetch_array($result)) {
    $packages[] = [
        'paket' => $row['PAKET'],
        'harga' => number_format($row['HARGA'], 0, ',', '.')
    ];
}

echo json_encode([
    'success' => true,
    'data' => $packages
]);
mysqli_close($conn);
?>
