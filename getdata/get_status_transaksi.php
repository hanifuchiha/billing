<?php
require '../cek-sesi.php';
header('Content-Type: application/json');

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID tidak valid']);
    exit;
}

$result = $conn->query("SELECT STATUS FROM transaksi WHERE id=$id LIMIT 1");

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    echo json_encode(['success' => true, 'status' => $row['STATUS']]);
} else {
    echo json_encode(['success' => false, 'message' => 'Transaksi tidak ditemukan']);
}
