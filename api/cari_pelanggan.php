<?php
// API Cari Pelanggan (global search)
require_once '../koneksidb.php';
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $q = $_GET['q'] ?? '';
    $q = $conn->real_escape_string($q);
    $result = $conn->query("SELECT * FROM pelanggan WHERE id LIKE '%$q%' OR nama LIKE '%$q%' OR odp LIKE '%$q%' OR paket LIKE '%$q%' OR NOWA LIKE '%$q%' OR alamat LIKE '%$q%'");
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    echo json_encode(['data' => $data]);
    exit();
}
http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);