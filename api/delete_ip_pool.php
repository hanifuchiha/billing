<?php
header('Content-Type: application/json; charset=utf-8');
require 'header_api.php'; // Session and database connection

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

$id = isset($input['id']) ? intval($input['id']) : 0;

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID tidak valid']);
    exit;
}

// Check if pool exists
$sql_check = "SELECT * FROM pool WHERE id = $id";
$result_check = mysqli_query($conn, $sql_check);

if (mysqli_num_rows($result_check) === 0) {
    echo json_encode(['success' => false, 'message' => 'IP Pool tidak ditemukan']);
    exit;
}

// Check if pool is being used by any package
$sql_usage = "SELECT COUNT(*) as count FROM paket WHERE LOCAL LIKE CONCAT('%', (SELECT iplocal FROM pool WHERE id = $id), '%')";
$result_usage = mysqli_query($conn, $sql_usage);
$row_usage = mysqli_fetch_array($result_usage);

if ($row_usage['count'] > 0) {
    echo json_encode(['success' => false, 'message' => 'IP Pool masih digunakan oleh paket, tidak dapat dihapus']);
    exit;
}

// Delete IP Pool
$sql_delete = "DELETE FROM pool WHERE id = $id";

if (mysqli_query($conn, $sql_delete)) {
    echo json_encode([
        'success' => true,
        'message' => 'IP Pool berhasil dihapus!'
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Gagal menghapus IP Pool: ' . mysqli_error($conn)]);
}

mysqli_close($conn);
?>
