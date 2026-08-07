<?php
header('Content-Type: application/json');

try {
    require_once '../koneksibilling.php';
    
    if (!$conn) {
        die(json_encode(['error' => 'DB connection failed']));
    }
    
    // Test ODP query with exact columns
    $sql_odp = "SELECT id, NAME, KODE, PORT, BRAND, AREA, TIKOR, WARNA FROM odp LIMIT 1";
    $result = $conn->query($sql_odp);
    if (!$result) {
        die(json_encode(['error' => 'ODP query error: ' . $conn->error, 'sql' => $sql_odp]));
    }
    
    $odp_sample = $result->fetch_assoc();
    
    // Test Pelanggan query
    $sql_pel = "SELECT IDPEL, NAMA, ALAMAT, TIKOR, PAKET, ODP, TANGGALPASANG, PEMILIK, STATUS FROM pelanggan LIMIT 1";
    $result2 = $conn->query($sql_pel);
    if (!$result2) {
        die(json_encode(['error' => 'Pelanggan query error: ' . $conn->error, 'sql' => $sql_pel]));
    }
    
    $pel_sample = $result2->fetch_assoc();
    
    echo json_encode([
        'success' => true,
        'odp_sample' => $odp_sample,
        'pelanggan_sample' => $pel_sample
    ]);
    
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>
