<?php
header('Content-Type: application/json; charset=utf-8');
require 'header_api.php';

// This would typically come from an external API like MikroTik RouterOS
// For now, we'll simulate the data structure

$odp_markers = [];
$customer_markers = [];
$connections = [];

// Get ODP locations from database (if stored)
$sql_odp = "
SELECT 
    id,
    NAMA as name,
    NOWA as phone,
    latitude,
    longitude
FROM odp
WHERE latitude IS NOT NULL AND longitude IS NOT NULL
LIMIT 500
";

$result_odp = mysqli_query($conn, $sql_odp);
if ($result_odp) {
    while ($row = mysqli_fetch_assoc($result_odp)) {
        $odp_markers[] = [
            'id' => 'odp_' . $row['id'],
            'type' => 'odp',
            'name' => $row['name'] ?? 'ODP',
            'latitude' => (float)$row['latitude'],
            'longitude' => (float)$row['longitude'],
            'phone' => $row['phone']
        ];
    }
}

// Get customer locations from database
$sql_customers = "
SELECT 
    IDPEL as id,
    NAMA as name,
    NOWA as phone,
    latitude,
    longitude,
    status
FROM pelanggan
WHERE latitude IS NOT NULL AND longitude IS NOT NULL
LIMIT 5000
";

$result_customers = mysqli_query($conn, $sql_customers);
if ($result_customers) {
    while ($row = mysqli_fetch_assoc($result_customers)) {
        $customer_markers[] = [
            'id' => 'customer_' . $row['id'],
            'type' => 'customer',
            'name' => $row['name'] ?? 'Customer',
            'idpel' => $row['id'],
            'latitude' => (float)$row['latitude'],
            'longitude' => (float)$row['longitude'],
            'phone' => $row['phone'],
            'status' => $row['status'],
            'online' => ($row['status'] == 'Aktif') ? true : false
        ];
    }
}

echo json_encode([
    'success' => true,
    'odp_markers' => $odp_markers,
    'customer_markers' => $customer_markers,
    'odp_count' => count($odp_markers),
    'customer_count' => count($customer_markers)
]);

mysqli_close($conn);
?>
