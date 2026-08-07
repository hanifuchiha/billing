<?php
// api/voucher_generator.php
// Diganti ke _bootstrap.php::api_authenticate() (session -> username+password ->
// API key dari tabel `apikey`) -- sebelumnya tidak pernah baca param `key`/`api_key`.
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
// --- END Autentikasi universal ---

$users_file = "/etc/freeradius/3.0/users";
$debug_file = '/var/log/freeradius/debug-radius-web.log';

function restartFreeradius() {
    global $debug_file;
    $pid = trim(shell_exec("pidof freeradius"));
    if($pid != '') shell_exec('sudo systemctl stop freeradius');
    if($pid != '') shell_exec("sudo kill -9 $pid");
    if(file_exists($debug_file)) shell_exec("sudo rm -f $debug_file");
    shell_exec("sudo touch $debug_file");
    shell_exec("sudo chmod 666 $debug_file");
    shell_exec("sudo freeradius -X > $debug_file 2>&1 &");
}

switch ($method) {
    case 'GET':
        // List semua voucher (user) di file users
        $content = @file_get_contents($users_file);
        if ($content === false) {
            echo json_encode(['success' => false, 'error' => 'Gagal membaca file users']);
            exit;
        }
        $lines = explode("\n", $content);
        $vouchers = [];
        foreach ($lines as $line) {
            if (preg_match('/^([a-zA-Z0-9._-]+) Cleartext-Password/', $line, $m)) {
                $vouchers[] = $m[1];
            }
        }
        echo json_encode(['success' => true, 'data' => $vouchers]);
        break;
    case 'POST':
        // Generate voucher baru ke file users
        $data = $input;
        $username = $data['username'] ?? '';
        $password = $data['password'] ?? '';
        $paket = $data['paket'] ?? '';
        if (!$username || !$password || !$paket) {
            echo json_encode(['success' => false, 'error' => 'Data tidak lengkap']);
            exit;
        }
        $entry = "$username Cleartext-Password := \"$password\"\n\tMikrotik-Group := \"$paket\"\n\n";
        $content = @file_get_contents($users_file);
        if (strpos($content, "$username Cleartext-Password") !== false) {
            echo json_encode(['success' => false, 'error' => 'Voucher sudah ada']);
            exit;
        }
        file_put_contents($users_file, $entry, FILE_APPEND);
        restartFreeradius();
        echo json_encode(['success' => true]);
        break;
    case 'DELETE':
        // Hapus voucher dari file users
        $data = $input;
        $username = $data['username'] ?? '';
        if (!$username) {
            echo json_encode(['success' => false, 'error' => 'Username tidak ditemukan']);
            exit;
        }
        $content = @file_get_contents($users_file);
        if ($content === false) {
            echo json_encode(['success' => false, 'error' => 'Gagal membaca file users']);
            exit;
        }
        $pattern = "/^$username Cleartext-Password.*?(?=^[^\s]|\z)/ms";
        $new_content = preg_replace($pattern, '', $content);
        file_put_contents($users_file, $new_content);
        restartFreeradius();
        echo json_encode(['success' => true]);
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Method tidak didukung']);
}
