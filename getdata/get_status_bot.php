<?php
include '../cek-sesi.php'; // Pastikan koneksi ke database sudah benar

header('Content-Type: application/json');

$namebot = $_GET['namebot'] ?? '';

if (empty($namebot)) {
    echo json_encode(['status' => 'unknown']);
    exit;
}

$cmd_qts_1 = "sudo -u qts tail -n 100 /home/qts/.pm2/logs/index-out.log | grep '\"sessionId\":\"$namebot\"'";
$cmd_qts_2 = "sudo -u qts tail -n 100 /home/qts/.pm2/logs/index-out.log | grep 'Session connected: $namebot'";
$cmd_fiberq = "sudo -u qts tail -n 100 /home/qts/.pm2/logs/index-out.log | grep 'Session disconnected: $namebot'";

$output_qts_1 = shell_exec($cmd_qts_1);
$output_qts_2 = shell_exec($cmd_qts_2);
$output_fiberq = shell_exec($cmd_fiberq);

// Mengecek apakah bot ditemukan dalam log
$qts_found = !empty(trim($output_qts_1)) || !empty(trim($output_qts_2));
$fiberq_found = !empty(trim($output_fiberq));

$status = 'unknown';
if ($qts_found) {
    $status = 'online';
} elseif ($fiberq_found) {
    $status = 'offline';
}

echo json_encode(['status' => $status]);
exit;
