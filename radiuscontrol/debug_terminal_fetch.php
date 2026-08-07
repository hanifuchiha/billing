<?php
// Guard akses: halaman ini polling tiap 15 detik dari radius.php dan
// menampilkan isi debug log FreeRADIUS mentah -- harus login sebagai ADMIN,
// sama seperti proses.php di folder ini.
require_once __DIR__ . '/../cek-sesi.php';
if (($AKSES ?? '') !== 'ADMIN') {
    http_response_code(403);
    die('Akses ditolak: pengaturan FreeRADIUS khusus untuk ADMIN.');
}

$debug_file = '/var/log/freeradius/debug-radius-web.log';

// Cek apakah file ada
if(!file_exists($debug_file)){
    echo "Log file tidak ditemukan!";
    exit;
}

// Ambil semua baris terakhir (misal 500 baris)

// Truncate log file if more than 100 lines
$lines = file($debug_file);
if (count($lines) > 100) {
    // Keep only last 100 lines
    $last100 = array_slice($lines, -100);
    // Overwrite file with last 100 lines
    file_put_contents($debug_file, implode('', $last100));
    $lines = $last100;
} else {
    $lines = array_slice($lines, -100); // hanya 100 baris terakhir
}

foreach($lines as $line){
    $line = htmlspecialchars($line); // aman
    $class = "line-normal";

    // Highlight error / warn / success
    if(preg_match('/error|fail|reject/i', $line)) $class="line-error";
    elseif(preg_match('/ok|accept|success/i', $line)) $class="line-success";

    echo "<div class='$class'>{$line}</div>\n";
}
?>
