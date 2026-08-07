<?php
// Diganti ke _bootstrap.php::api_authenticate() (session -> username+password ->
// API key dari tabel `apikey`) -- file debug/scaffolding, sebelumnya WAJIB
// username+password plaintext dan tidak pernah baca param `key`/`api_key`.

try {
    require_once '../koneksibilling.php';
    require_once '_bootstrap.php';
    session_start();
    api_cors();

    if (!$conn) {
        die(json_encode(['error' => 'DB connection failed']));
    }

    $input = api_read_input();
    $auth = api_authenticate($conn, $input);
    $pemilik = $auth['pemilik'];
    if ($auth['method'] === 'apikey') {
        api_rate_limit($conn, $auth['api_key']);
    }
    
    // Test ODP
    $sql_odp = "SELECT id, NAME, KODE, PORT, BRAND, AREA, TIKOR FROM odp";
    $res_odp = $conn->query($sql_odp);
    if (!$res_odp) {
        die(json_encode(['error' => 'ODP query: ' . $conn->error]));
    }
    
    $odp_count = $res_odp->num_rows;
    
    // Test Pelanggan
    $sql_pel = "SELECT IDPEL, NAMA, ALAMAT, TIKOR, PAKET, ODP, TANGGALPASANG, PEMILIK, AREA FROM pelanggan WHERE PEMILIK = ?";
    $stmt = $conn->prepare($sql_pel);
    if (!$stmt) {
        die(json_encode(['error' => 'Prep: ' . $conn->error]));
    }
    
    $stmt->bind_param("s", $pemilik);
    if (!$stmt->execute()) {
        die(json_encode(['error' => 'Exec: ' . $stmt->error]));
    }
    
    $res_pel = $stmt->get_result();
    $pel_count = $res_pel->num_rows;
    
    echo json_encode([
        'success' => true,
        'pemilik' => $pemilik,
        'odp_count' => $odp_count,
        'pelanggan_count' => $pel_count
    ]);
    
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>
