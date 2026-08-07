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

// Check if VPN exists
$sql_check = "SELECT * FROM pelanggan WHERE id = $id AND BRAND LIKE 'VPN'";
$result_check = mysqli_query($conn, $sql_check);

if (mysqli_num_rows($result_check) === 0) {
    echo json_encode(['success' => false, 'message' => 'VPN tidak ditemukan']);
    exit;
}

// Delete VPN record
$sql_delete = "DELETE FROM pelanggan WHERE id = $id";

if (mysqli_query($conn, $sql_delete)) {
    echo json_encode([
        'success' => true,
        'message' => 'VPN berhasil dihapus!'
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Gagal menghapus VPN: ' . mysqli_error($conn)]);
}

mysqli_close($conn);
?>
