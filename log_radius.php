<?php
// log_radius.php
$log_file = '/var/log/freeradius/radius.log'; // Ubah jika path log kamu beda

if (file_exists($log_file)) {
    // Ambil 50 baris terakhir pakai tail
    $output = shell_exec("tail -n 50 " . escapeshellarg($log_file));
    echo htmlspecialchars($output);
} else {
    echo "❌ Log file tidak ditemukan.";
}
