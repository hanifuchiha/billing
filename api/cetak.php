<?php // API Cetak
require_once '../koneksidb.php';
$result = $conn->query('SELECT * FROM printdatapelanggan');
$data = [];
while ($row = $result->fetch_assoc()) {
	$data[] = $row;
}
echo json_encode(['data' => $data]);