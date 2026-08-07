<?php
// api/backup_restore.php
// Sebelumnya cuma dukung session ATAU username+password -- tidak pernah baca
// param `key`/`api_key`, PADAHAL settingsapi.php mendokumentasikan resmi
// akses via API key. Diganti ke _bootstrap.php::api_authenticate() (session ->
// username+password -> API key dari tabel `apikey`) sama seperti api/odp.php dkk.
require_once '../koneksibilling.php';
require_once '_bootstrap.php';
session_start();
api_cors();

$method = $_SERVER['REQUEST_METHOD'];
$input = api_read_input();

$auth = api_authenticate($conn, $input);
$pemilik = $auth['pemilik'];
if ($auth['method'] === 'apikey') {
    api_rate_limit($conn, $auth['api_key']);
}

$backup_dir = realpath(__DIR__ . '/../dailybackup/backups/');

switch ($method) {
    case 'GET':
        // List file backup milik pemilik
        $files = [];
        if ($backup_dir && is_dir($backup_dir)) {
            foreach (scandir($backup_dir) as $file) {
                if ($file === '.' || $file === '..') continue;
                if (strpos($file, $pemilik) !== false) {
                    $files[] = $file;
                }
            }
        }
        echo json_encode(['success' => true, 'data' => $files]);
        break;
    case 'POST':
        // Restore backup (dummy, hanya log permintaan, implementasi restore tergantung sistem)
        $data = $input;
        $filename = $data['filename'] ?? '';
        if (!$filename || !preg_match('/^[\w.-]+$/', $filename)) {
            echo json_encode(['success' => false, 'error' => 'Nama file tidak valid']);
            exit;
        }
        $filepath = $backup_dir . DIRECTORY_SEPARATOR . $filename;
        if (!file_exists($filepath)) {
            echo json_encode(['success' => false, 'error' => 'File backup tidak ditemukan']);
            exit;
        }
        // TODO: Implementasi restore sesuai sistem (misal: import SQL, copy file, dsb)
        echo json_encode(['success' => true, 'message' => 'Permintaan restore diterima, silakan proses manual/otomatis sesuai sistem.']);
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Method tidak didukung']);
}
