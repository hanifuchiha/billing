<?php // API Upload
require_once '../koneksidb.php';
$result = $conn->query('SELECT * FROM uploads');
$data = [];
while ($row = $result->fetch_assoc()) {
	$data[] = $row;
}
echo json_encode(['data' => $data]);