<?php // API Notifikasi
require_once '../koneksidb.php';
$result = $conn->query('SELECT * FROM notification');
$data = [];
while ($row = $result->fetch_assoc()) {
	$data[] = $row;
}
echo json_encode(['data' => $data]);