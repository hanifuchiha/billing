<?php
// api/voucher_bank.php
// Sebelumnya file ini require '../cek-sesi.php' (bootstrap panel admin, redirect
// ke halaman login kalau tidak ada session browser) SEBELUM logic cek_login_api()
// di bawah sempat jalan sama sekali -- jadi API key tidak pernah benar-benar
// dicek. Diganti ke pola auth API yang benar (session ATAU username+password
// ATAU API key dari tabel `apikey`), sama seperti api/odp.php dkk.
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
        // List semua voucher detail (username, paket, status) dari file users
        $content = @file_get_contents($users_file);
        if ($content === false) {
            echo json_encode(['success' => false, 'error' => 'Gagal membaca file users']);
            exit;
        }
        $lines = explode("\n", $content);
        $vouchers = [];
        $current = [];
        foreach ($lines as $line) {
            if (preg_match('/^([a-zA-Z0-9._-]+) Cleartext-Password/', $line, $m)) {
                if (!empty($current)) $vouchers[] = $current;
                $current = ['username' => $m[1], 'paket' => '', 'status' => 'aktif'];
            } elseif (preg_match('/Mikrotik-Group := "([^"]+)"/', $line, $m)) {
                $current['paket'] = $m[1];
            }
        }
        if (!empty($current)) $vouchers[] = $current;
        echo json_encode(['success' => true, 'data' => $vouchers]);
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
