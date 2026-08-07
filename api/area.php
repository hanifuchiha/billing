<?php
// Endpoint data area untuk Android Qbilling V2
require_once '../config.php';
header('Content-Type: application/json');

$sql = "SELECT DISTINCT AREA FROM pelanggan ORDER BY AREA";
$result = mysqli_query($conn, $sql);
$data = [];
while ($row = mysqli_fetch_assoc($result)) $data[] = $row['AREA'];
echo json_encode(['success'=>true,'data'=>$data]);