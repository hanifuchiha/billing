<?php
// API: customer_transactions.php
header('Content-Type: application/json');
require_once '../koneksibilling.php';

$idpel = isset($_GET['idpel']) ? trim($_GET['idpel']) : '';
if (!$idpel) {
    echo json_encode(['success' => false, 'error' => 'Parameter kurang']);
    exit;
}

$sql = "SELECT id, tanggal, nominal, status, keterangan FROM transaksi WHERE IDPEL = '" . $conn->real_escape_string($idpel) . "' ORDER BY tanggal DESC LIMIT 30";
$res = $conn->query($sql);
if (!$res) {
    echo json_encode(['success' => false, 'error' => 'Query gagal']);
    exit;
}
$rows = [];
while ($row = $res->fetch_assoc()) {
    $rows[] = $row;
}
echo json_encode(['success' => true, 'data' => $rows]);
exit;
