<?php
// log_history_data.php
// Fragment endpoint (cuma render <tr> baris log, dipakai fetch/AJAX oleh
// log_history.php utk auto-refresh) -- SENGAJA require cek-sesi.php saja,
// BUKAN header.php (yg mencetak seluruh halaman HTML/nav/sidebar), supaya
// respons fetch cuma berisi <tr> murni, bukan 1 halaman penuh ditempel di
// depannya (yang bikin innerHTML gagal menampilkan tabel dgn benar).
require_once 'cek-sesi.php';

// Pastikan variabel $ceknama tersedia (ambil dari session atau logic yang sama seperti di log_history.php)
if (!isset($ceknama)) {
    if (isset($_SESSION['username'])) {
        $ceknama = $_SESSION['username'];
    } else {
        $ceknama = 'unknown';
    }
}

$history_file = "notifbot/data/history-$ceknama.json";
if (file_exists($history_file)) {
    $history = json_decode(file_get_contents($history_file), true);
    if (is_array($history)) {
        $history = array_reverse($history); // Newest first
        foreach ($history as $log_entry) {
            // Parse the log entry: [ system - timestamp ] message
            if (preg_match('/^\[(.+)\] (.+)$/s', $log_entry, $matches)) {
                $header = trim($matches[1]);
                $message = $matches[2];
                if (strpos($header, ' - ') !== false) {
                    list($log_username, $log_timestamp) = explode(' - ', $header, 2);
                    $log_username = trim($log_username);
                    $log_timestamp = trim($log_timestamp);
                    $log_message = $message;
                } else {
                    $log_username = $ceknama;
                    $log_timestamp = '';
                    $log_message = $log_entry;
                }
            } else {
                // Fallback if format doesn't match
                $log_username = $ceknama;
                $log_timestamp = '';
                $log_message = $log_entry;
            }
            echo '<tr>';
            echo '<td>' . htmlspecialchars($log_username) . '</td>';
            echo '<td>' . htmlspecialchars($log_timestamp) . '</td>';
            echo '<td>' . htmlspecialchars($log_message) . '</td>';
            echo '</tr>';
        }
    }
}
