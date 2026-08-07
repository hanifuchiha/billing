<?php
// API Tagihan
require_once '../koneksidb.php';
$result = $conn->query('SELECT * FROM tagihan');
$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}
echo json_encode(['data' => $data]);
