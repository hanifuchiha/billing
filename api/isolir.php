<?php // API Isolir
require_once '../koneksidb.php';
$result = $conn->query('SELECT * FROM pelanggan_menunggak');
$data = [];
while ($row = $result->fetch_assoc()) {
	$data[] = $row;
}
echo json_encode(['data' => $data]);