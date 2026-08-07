<?php // API Bukti Bayar
require_once '../koneksidb.php';
$result = $conn->query('SELECT * FROM buktibayar');
$data = [];
while ($row = $result->fetch_assoc()) {
	$data[] = $row;
}
echo json_encode(['data' => $data]);