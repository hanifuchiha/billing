<?php
// API Livechat: list chat terakhir
require_once '../koneksidb.php';
$result = $conn->query('SELECT * FROM livechat ORDER BY waktu DESC LIMIT 100');
$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}
echo json_encode(['data' => $data]);