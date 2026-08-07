<?php
// Endpoint laporan untuk Android Qbilling V2
require_once '../config.php';
header('Content-Type: application/json');

$type = $_GET['type'] ?? 'harian';

switch ($type) {
    case 'harian':
        $tgl = $_GET['tanggal'] ?? date('Y-m-d');
        $sql = "SELECT * FROM transaksi WHERE tanggal='".mysqli_real_escape_string($conn,$tgl)."'";
        $result = mysqli_query($conn, $sql);
        $data = [];
        while ($row = mysqli_fetch_assoc($result)) $data[] = $row;
        echo json_encode(['success'=>true,'data'=>$data]);
        break;
    case 'bulanan':
        $bln = $_GET['bulan'] ?? date('Y-m');
        $sql = "SELECT * FROM transaksi WHERE tanggal LIKE '".mysqli_real_escape_string($conn,$bln)."%'";
        $result = mysqli_query($conn, $sql);
        $data = [];
        while ($row = mysqli_fetch_assoc($result)) $data[] = $row;
        echo json_encode(['success'=>true,'data'=>$data]);
        break;
    default:
        echo json_encode(['success'=>false,'error'=>'Invalid type']);
}