<?php
header('Content-Type: application/json');

try {
    require_once '../koneksibilling.php';
    
    if (!$conn) {
        die(json_encode(['error' => 'DB connection failed']));
    }
    
    // Test 1: Check ODP table
    $test_odp = $conn->query("SELECT COUNT(*) as cnt FROM odp");
    if (!$test_odp) {
        die(json_encode(['error' => 'ODP query failed: ' . $conn->error]));
    }
    
    $odp_count = $test_odp->fetch_assoc()['cnt'];
    
    // Test 2: Check pelanggan table  
    $test_pel = $conn->query("SELECT COUNT(*) as cnt FROM pelanggan");
    if (!$test_pel) {
        die(json_encode(['error' => 'Pelanggan query failed: ' . $conn->error]));
    }
    
    $pel_count = $test_pel->fetch_assoc()['cnt'];
    
    // Test 3: Check if TIKOR column exists in ODP
    $test_cols = $conn->query("SHOW COLUMNS FROM odp");
    $odp_cols = [];
    while ($col = $test_cols->fetch_assoc()) {
        $odp_cols[] = $col['Field'];
    }
    
    echo json_encode([
        'success' => true,
        'odp_count' => $odp_count,
        'pelanggan_count' => $pel_count,
        'odp_columns' => $odp_cols,
        'has_tikor' => in_array('TIKOR', $odp_cols)
    ]);
    
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>
