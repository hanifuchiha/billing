<?php
require '../header.php';

header('Content-Type: application/json');

$session_id = trim($_POST['session_id'] ?? '');

if (empty($session_id)) {
    echo json_encode(['success' => false, 'message' => 'Session ID tidak valid']);
    exit;
}

// Hapus file status broadcast
$status_file = __DIR__ . '/broadcast_status_' . $session_id . '.json';
if (file_exists($status_file)) {
    unlink($status_file);
}

// Hapus session broadcast status jika ada
if (isset($_SESSION['broadcast_status'])) {
    unset($_SESSION['broadcast_status']);
}

echo json_encode(['success' => true]);
?>