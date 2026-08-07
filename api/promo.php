<?php // API Promo
require_once '../koneksidb.php';
$result = $conn->query('SELECT * FROM promo_paket');
$data = [];
while ($row = $result->fetch_assoc()) {
	$data[] = $row;
}
echo json_encode(['data' => $data]);