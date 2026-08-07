<?php
// API Pembayaran
require_once '../koneksidb.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $tagihan_id = $data['tagihan_id'] ?? null;
    $jumlah = $data['jumlah'] ?? null;
    if (!$tagihan_id || !$jumlah) {
        echo json_encode(['error' => 'tagihan_id dan jumlah wajib diisi']);
        exit();
    }
    // Contoh update status tagihan
    $stmt = $conn->prepare('UPDATE tagihan SET status = "LUNAS", jumlah_bayar = ? WHERE id = ?');
    $stmt->bind_param('di', $jumlah, $tagihan_id);
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['error' => 'Gagal update pembayaran']);
    }
    exit();
}
http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
