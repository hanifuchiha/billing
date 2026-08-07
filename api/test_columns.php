<?php
header('Content-Type: application/json');

try {
    require_once '../koneksibilling.php';
    
    if (!$conn) {
        die(json_encode(['error' => 'DB connection failed']));
    }
    
    // Check pelanggan columns
    $test_cols = $conn->query("SHOW COLUMNS FROM pelanggan");
    $pel_cols = [];
    while ($col = $test_cols->fetch_assoc()) {
        $pel_cols[] = $col['Field'];
    }
    
    echo json_encode([
        'success' => true,
        'pelanggan_columns' => $pel_cols,
        'has_tanggalpasang' => in_array('TANGGALPASANG', $pel_cols),
        'has_status' => in_array('STATUS', $pel_cols)
    ]);
    
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>
