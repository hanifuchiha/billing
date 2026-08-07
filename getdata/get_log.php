<?php
include '../cek-sesi.php'; // Pastikan koneksi ke database sudah benar

$username1 = $_GET['username'];
$log_file = "../notifbot/data/history-$ceknama.json";

if (file_exists($log_file)) {
    $logs = json_decode(file_get_contents($log_file), true);
    if (!empty($logs)) {
        $logs = array_slice($logs, -5000); // Ambil 5000 data terakhir
        echo json_encode($logs);
    } else {
        echo json_encode([]);
    }
} else {
    echo json_encode([]);
}
exit;
