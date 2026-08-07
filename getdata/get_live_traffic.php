<?php
/**
 * Live traffic (Download/Upload Mbps) + IP/Uptime sesi untuk 1 pelanggan - dipanggil
 * terpisah dari status Online/Offline (yang sudah instan lewat cache di
 * get_cached_pppoe_status.php).
 *
 * Kecepatan Down/Up, IP, dan Uptime itu sifatnya sesaat (instantaneous/berubah terus),
 * tidak masuk akal diambil dari cache batch tiap 1 menit - jadi endpoint ini sengaja
 * tetap connect langsung ke Mikrotik, dipanggil hanya untuk pelanggan yang sedang
 * Online & barisnya kelihatan di layar, dan tidak menunda tampilnya status/badge.
 *
 * IP & Uptime awalnya CUMA diisi dari cache cron (pppoe_status_cache.json, refresh
 * tiap ~1 menit) atau fallback connect-langsung di get_cached_pppoe_status.php - kalau
 * cache itu basi/kosong (mis. cron belum terpasang) keduanya tampil "N/A" terus,
 * padahal Down/Up di baris yang sama sudah realtime lewat endpoint ini. Sekarang
 * IP & Uptime ikut diambil live di sini juga, sama seperti Down/Up.
 */

include '../cek-sesi.php';
require 'routeros_api.class.php';
require_once 'pppoe_status_helpers.php';

header('Content-Type: application/json');

$ip = $_POST['ip'] ?? '';
$user = $_POST['us'] ?? '';
$password = $_POST['ps'] ?? '';
$IDPEL = $_POST['idpel'] ?? '';

if (empty($ip) || empty($user) || empty($password) || empty($IDPEL)) {
    echo json_encode(['download' => 0, 'upload' => 0, 'error' => 'Missing parameters']);
    exit;
}

$download = 0;
$upload = 0;
$remoteIp = null;
$uptime = null;

$API = new RouterosAPI();
$API->timeout = 3;
$API->attempts = 1;
if ($API->connect($ip, $user, $password)) {
    $interface = "<pppoe-" . $IDPEL . ">";
    $traffic = $API->comm("/interface/monitor-traffic", [
        "interface" => $interface,
        "once" => ""
    ]);
    if (!empty($traffic[0]['tx-bits-per-second'])) {
        $download = round($traffic[0]['tx-bits-per-second'] / 1_000_000, 2);
    }
    if (!empty($traffic[0]['rx-bits-per-second'])) {
        $upload = round($traffic[0]['rx-bits-per-second'] / 1_000_000, 2);
    }

    $ppp_active = $API->comm('/ppp/active/print', [
        '?name' => $IDPEL,
        '.proplist' => 'address,uptime'
    ]);
    if (!empty($ppp_active[0]['address'])) {
        $remoteIp = $ppp_active[0]['address'];
    }
    if (!empty($ppp_active[0]['uptime'])) {
        $uptime = format_uptime_readable($ppp_active[0]['uptime']);
    }

    $API->disconnect();
}

echo json_encode([
    'download' => $download,
    'upload' => $upload,
    'remote_ip' => $remoteIp,
    'uptime' => $uptime,
]);
exit;
