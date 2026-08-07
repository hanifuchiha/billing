<?php
require '../koneksidb.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST)) {
    $ref = $_SERVER['HTTP_REFERER'] ?? '../tables.php';
    header('Location: ' . $ref);
    exit;
}

header('Content-Type: application/json');

if (!isset($_POST['idpel'])) {
    echo json_encode(['error' => 'ID pelanggan tidak diberikan']);
    exit;
}

$idpel = mysqli_real_escape_string($conn, $_POST['idpel']);

// Check if customer exists
$query_check = "SELECT * FROM pelanggan_berhenti WHERE idpel = '$idpel'";
$result_check = mysqli_query($conn, $query_check);

if (!$result_check) {
    echo json_encode(['error' => 'Database error: ' . mysqli_error($conn)]);
    exit;
}

if (mysqli_num_rows($result_check) === 0) {
    echo json_encode(['error' => 'Pelanggan tidak ditemukan']);
    exit;
}

// Delete customer permanently
$query_delete = "DELETE FROM pelanggan_berhenti WHERE idpel = '$idpel'";
$result_delete = mysqli_query($conn, $query_delete);

if (!$result_delete) {
    echo json_encode(['error' => 'Gagal menghapus data: ' . mysqli_error($conn)]);
    exit;
}

echo json_encode(['success' => true, 'message' => 'Data pelanggan berhasil dihapus permanen']);
?>