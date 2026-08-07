<?php
// File: api/riwayat_transaksi_pelanggan.php
header('Content-Type: application/json');
require_once '../koneksibilling.php';

$idpel = $_GET['idpel'] ?? '';

if (!$idpel) {
    echo json_encode(['success' => false, 'error' => 'Parameter kurang (idpel)']);
    exit;
}

// Ambil riwayat transaksi berdasarkan IDPEL
$stmt2 = $conn->prepare("SELECT * FROM transaksi WHERE IDPEL = ? ORDER BY TANGGALBAYAR DESC");
$stmt2->bind_param("s", $idpel);
$stmt2->execute();
$res2 = $stmt2->get_result();
$items = [];
while ($d = $res2->fetch_assoc()) {
    $items[] = $d;
}
echo json_encode(['success' => true, 'data' => $items]);
