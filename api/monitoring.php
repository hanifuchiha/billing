<?php
// API Pemantauan Tiket (monitoring)
require_once '../koneksidb.php';
$result = $conn->query('SELECT * FROM tiket_monitoring ORDER BY updated_at DESC LIMIT 100');
$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}
echo json_encode(['data' => $data]);