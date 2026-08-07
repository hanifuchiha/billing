<?php
// save_notification_multi.php
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

switch ($mode) {
    case 'registrasi':
        $pesan = trim($_POST['pesan_registrasi'] ?? '');
        $field = 'pesan_registrasi';
        $label = 'Pesan registrasi';
        break;
    case 'expired':
        $pesan = trim($_POST['pesan_expired'] ?? '');
        $field = 'pesan_expired';
        $label = 'Pesan expired';
        break;
    case 'reminder':
        $pesan = trim($_POST['pesan_reminder'] ?? '');
        $field = 'pesan_reminder';
        $label = 'Pesan reminder';
        break;
    case 'ketentuan':
        $pesan = trim($_POST['pesan_ketentuan'] ?? '');
        $field = 'pesan_ketentuan';
        $label = 'Pesan ketentuan';
        break;
    case 'disable':
        $pesan = trim($_POST['pesan_disable'] ?? '');
        $field = 'pesan_disable';
        $label = 'Pesan disable';
        break;
    case 'aktif_manual':
        $pesan = trim($_POST['pesan_aktif_manual'] ?? '');
        $field = 'pesan_aktif_manual';
        $label = 'Pesan aktif manual';
        break;
    case 'remainder_manual':
        $pesan = trim($_POST['pesan_remainder_manual'] ?? '');
        $field = 'pesan_remainder_manual';
        $label = 'Pesan remainder manual';
        break;
    case 'dismantle_manual':
        $pesan = trim($_POST['pesan_dismantle_manual'] ?? '');
        $field = 'pesan_dismantle_manual';
        $label = 'Pesan dismantle manual';
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Mode tidak valid']);
        exit;
}

if ($pesan !== '' && $pemilik !== '') {
    $stmt = $conn->prepare("SELECT id FROM notif_khusus WHERE pemilik = ?");
    $stmt->bind_param('s', $pemilik);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        $stmt2 = $conn->prepare("UPDATE notif_khusus SET $field = ? WHERE pemilik = ?");
        $stmt2->bind_param('ss', $pesan, $pemilik);
        $stmt2->execute();
        $stmt2->close();
        $response = ['success' => true, 'message' => "$label berhasil diupdate!"];
    } else {
        $stmt2 = $conn->prepare("INSERT INTO notif_khusus (pemilik, $field) VALUES (?, ?)");
        $stmt2->bind_param('ss', $pemilik, $pesan);
        $stmt2->execute();
        $stmt2->close();
        $response = ['success' => true, 'message' => "$label berhasil disimpan!"];
    }
    $stmt->close();
} else {
    $response = ['success' => false, 'message' => 'Pesan dan pemilik tidak boleh kosong!'];
}
echo json_encode($response);
