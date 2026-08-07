<?php
// save_notification.php
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}
require_once __DIR__ . '/header.php';

$response = ['success' => false, 'message' => 'Unknown error'];
$mode = $_POST['mode'] ?? '';
$pemilik = isset($ceknama) ? $ceknama : (isset($_POST['pemilik']) ? trim($_POST['pemilik']) : '');

if ($mode === 'registrasi') {
    $pesan_registrasi = trim($_POST['pesan_registrasi'] ?? '');
    if ($pesan_registrasi !== '' && $pemilik !== '') {
        $stmt = $conn->prepare("SELECT id FROM notif_khusus WHERE pemilik = ?");
        $stmt->bind_param('s', $pemilik);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $stmt2 = $conn->prepare("UPDATE notif_khusus SET pesan_registrasi = ? WHERE pemilik = ?");
            $stmt2->bind_param('ss', $pesan_registrasi, $pemilik);
            $stmt2->execute();
            $stmt2->close();
            $response = ['success' => true, 'message' => 'Pesan registrasi berhasil diupdate!'];
        } else {
            $stmt2 = $conn->prepare("INSERT INTO notif_khusus (pemilik, pesan_registrasi) VALUES (?, ?)");
            $stmt2->bind_param('ss', $pemilik, $pesan_registrasi);
            $stmt2->execute();
            $stmt2->close();
            $response = ['success' => true, 'message' => 'Pesan registrasi berhasil disimpan!'];
        }
        $stmt->close();
    } else {
        $response = ['success' => false, 'message' => 'Pesan dan pemilik tidak boleh kosong!'];
    }
} else {
    $response = ['success' => false, 'message' => 'Mode tidak valid'];
}
echo json_encode($response);
