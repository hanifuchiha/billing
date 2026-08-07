<?php
require '../cek-sesi.php';
require '../routeros_api.class.php';

// ambil konfigurasi
$config_file = '../config.json';
$config = file_exists($config_file) ? json_decode(file_get_contents($config_file), true) : [];
$router_ip = $config['router_ip'];
$ippublicportapi = $config['ippublicportapi'];
$router_user = $config['router_user'];
$router_pass = $config['router_pass'];

$page = $_POST['page'] ?? $_GET['page'] ?? 'vpnadmin';
$page = in_array($page, ['vpn', 'vpnadmin'], true) ? $page : 'vpnadmin';

// Pastikan SSTP-server di router utama aktif DAN jalan di port 4433 (konvensi yang dipakai
// semua koneksi VPN sekarang). Kalau masih di port lain atau mati, langsung dibetulkan.
function mikrotik_ensure_sstp_server($API, $port = '4433') {
    $server = $API->comm('/interface/sstp-server/server/print');
    $current_port = (is_array($server) && isset($server[0]['port'])) ? $server[0]['port'] : null;
    $current_enabled = (is_array($server) && isset($server[0]['enabled'])) ? $server[0]['enabled'] : null;

    if ($current_port === $port && in_array($current_enabled, ['true', 'yes'], true)) {
        return 'already';
    }

    $API->comm('/interface/sstp-server/server/set', [
        'port' => $port,
        'enabled' => 'yes'
    ]);
    return 'updated';
}

$API = new RouterosAPI();
if ($API->connect($router_ip . ':' . $ippublicportapi, $router_user, $router_pass)) {
    $status = mikrotik_ensure_sstp_server($API, '4433');
    $API->disconnect();
    header("Location: ../$page.php?statusnotif=" . ($status === 'updated' ? 'sstp_updated' : 'sstp_already'));
    exit;
}

header("Location: ../$page.php?statusnotif=sstp_failed");
exit;
