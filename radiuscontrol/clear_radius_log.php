<?php
// Kosongkan riwayat "FreeRADIUS Request Log" di radius.php (tombol Clear Log).
// Guard akses sama seperti fetch_radius_log.php -- halaman ini bisa memuat
// username/IP pelanggan, khusus ADMIN.
require_once __DIR__ . '/../cek-sesi.php';
header('Content-Type: application/json');
if (($AKSES ?? '') !== 'ADMIN') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Akses ditolak: pengaturan FreeRADIUS khusus untuk ADMIN.']);
    exit;
}

$log_file     = '/var/log/freeradius/debug-radius-web.log';
$history_file = '/var/log/freeradius/request-log-history.json';

/**
 * Tulis $content ke $path, fallback ke sudo cp+chmod kalau permission langsung
 * gagal -- pola sama persis dengan writeHistoryFile() di fetch_radius_log.php.
 */
function clearRadiusLogWriteFile(string $path, string $content): bool
{
    if (@file_put_contents($path, $content) !== false) {
        return true;
    }
    $tmp = tempnam(sys_get_temp_dir(), 'radlog');
    file_put_contents($tmp, $content);
    shell_exec('sudo /bin/mkdir -p ' . escapeshellarg(dirname($path)) . ' 2>/dev/null');
    shell_exec('sudo /bin/cp ' . escapeshellarg($tmp) . ' ' . escapeshellarg($path) . ' 2>&1');
    shell_exec('sudo /bin/chmod 666 ' . escapeshellarg($path) . ' 2>&1');
    @unlink($tmp);
    $check = @file_get_contents($path);
    return $check !== false && trim($check) === trim($content);
}

// Kosongkan riwayat tersimpan (request-log-history.json) DAN file debug yang
// sedang berjalan (debug-radius-web.log) sekaligus -- kalau cuma history yang
// dikosongkan, poll berikutnya (setiap 20 detik) akan mem-parse ulang baris
// lama yang masih ada di file debug dan menulisnya balik ke history (hash-nya
// belum pernah "terlihat" krn history baru kosong), jadi log lama "muncul
// lagi" seolah tombol Clear tidak berfungsi.
$historyOk = clearRadiusLogWriteFile($history_file, json_encode([]));
$debugOk   = clearRadiusLogWriteFile($log_file, '');

if ($historyOk && $debugOk) {
    echo json_encode(['success' => true, 'message' => 'FreeRADIUS Request Log berhasil dikosongkan.']);
} else {
    $failed = [];
    if (!$historyOk) $failed[] = 'request-log-history.json';
    if (!$debugOk) $failed[] = 'debug-radius-web.log';
    echo json_encode(['success' => false, 'message' => 'Gagal mengosongkan: ' . implode(', ', $failed) . ' (cek permission file/sudoers).']);
}
