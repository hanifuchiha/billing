<?php
// File: getdata/clear_log.php
include '../cek-sesi.php'; // Pastikan koneksi ke database sudah benar
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$username = isset($_POST['username']) ? preg_replace('/[^a-zA-Z0-9_\-]/', '', $_POST['username']) : '';
if (!$username) {
    http_response_code(400);
    echo json_encode(['error' => 'Username required']);
    exit;
}

$log_file = __DIR__ . '/../notifbot/data/history-' . $username . '.json';
if (file_exists($log_file)) {
    // Baca history yang ada
    $history = json_decode(file_get_contents($log_file), true) ?: [];
    // Tambahkan entri log cleared
    $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Log cleared for user $username";
    // Simpan kembali
    file_put_contents($log_file, json_encode($history, JSON_PRETTY_PRINT));
    echo json_encode(['success' => true, 'message' => 'Log file cleared.']);
} else {
    // File tidak ada, buat dengan entri cleared
    $history = ["[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Log cleared for user $username"];
    if (!is_dir(dirname($log_file))) {
        mkdir(dirname($log_file), 0777, true);
    }
    file_put_contents($log_file, json_encode($history, JSON_PRETTY_PRINT));
    echo json_encode(['success' => true, 'message' => 'Log file not found, created and cleared.']);
}
