<?php
header('Content-Type: application/json; charset=utf-8');
require 'header_api.php';

$type = isset($_GET['type']) ? $_GET['type'] : 'all'; // cables or assets

$cables = [];
$assets = [];

// Get cables data
if ($type == 'all' || $type == 'cables') {
    $sql = "
    SELECT 
        id,
        name,
        type,
        length,
        geom,
        attributes,
        created_at,
        updated_at
    FROM cables
    LIMIT 1000
    ";
    
    $result = mysqli_query($conn, $sql);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $cables[] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'type' => $row['type'],
                'length' => (float)$row['length'],
                'geom' => $row['geom'],
                'attributes' => json_decode($row['attributes'], true) ?? [],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at']
            ];
        }
    }
}

// Get assets data
if ($type == 'all' || $type == 'assets') {
    $sql = "
    SELECT 
        id,
        name,
        asset_type,
        location,
        geom,
        attributes,
        status,
        created_at,
        updated_at
    FROM assets
    LIMIT 1000
    ";
    
    $result = mysqli_query($conn, $sql);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $assets[] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'asset_type' => $row['asset_type'],
                'location' => $row['location'],
                'geom' => $row['geom'],
                'attributes' => json_decode($row['attributes'], true) ?? [],
                'status' => $row['status'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at']
            ];
        }
    }
}

echo json_encode([
    'success' => true,
    'cables' => $cables,
    'assets' => $assets,
    'cable_count' => count($cables),
    'asset_count' => count($assets)
]);

mysqli_close($conn);
?>
