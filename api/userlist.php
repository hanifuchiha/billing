<?php
// Endpoint daftar user untuk Android Qbilling V2 (admin only)
require_once '../config.php';
header('Content-Type: application/json');

$sql = "SELECT USERNAME, NAMA, EMAIL, ROLE, server FROM user ORDER BY USERNAME";
$result = mysqli_query($conn, $sql);
$data = [];
while ($row = mysqli_fetch_assoc($result)) $data[] = $row;
echo json_encode(['success'=>true,'data'=>$data]);