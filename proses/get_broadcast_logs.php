<?php
require '../header.php';

header('Content-Type: application/json');

// Cari file log broadcast di folder proses
$log_files = glob(__DIR__ . '/broadcast_log_*.txt');

$logs = [];
foreach ($log_files as $file) {
    $filename = basename($file);
    $file_content = file_get_contents($file);

    // Parse tanggal dari nama file
    if (preg_match('/broadcast_log_(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})\.txt/', $filename, $matches)) {
        $date_str = $matches[1];
        $date = DateTime::createFromFormat('Y-m-d_H-i-s', $date_str);

        $logs[] = [
            'filename' => $filename,
            'date' => $date ? $date->format('d/m/Y H:i:s') : 'Unknown',
            'content' => $file_content
        ];
    }
}

// Urutkan berdasarkan tanggal terbaru
usort($logs, function($a, $b) {
    return strtotime($b['date']) - strtotime($a['date']);
});

// Ambil 5 log terbaru
$logs = array_slice($logs, 0, 5);

echo json_encode([
    'success' => true,
    'logs' => $logs
]);
?>