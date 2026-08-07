<?php
include '../cek-sesi.php'; // Pastikan koneksi ke database sudah benar

require('routeros_api.class.php');

header('Content-Type: application/json');

$ip = $_POST['ip'] ?? 'quenbytekniksejahtera.com:40328';
$us = $_POST['us'] ?? 'FIBERQ';
$password = $_POST['ps'] ?? 'FIBERQ@QTS';
$IDPEL = $_POST['idpel'] ?? '2696';

// Validasi input
if (!$ip || !$us || !$password || !$IDPEL) {
    echo json_encode(["error" => "Missing parameters"]);
    exit;
}

// Inisialisasi RouterOS API
$API = new RouterosAPI();

if ($API->connect($ip, $us, $password)) {
    $active_users = $API->comm('/ip/hotspot/active/print', ["?user" => $IDPEL]);

    // Inisialisasi data default jika user tidak ditemukan
    $upload = '0';
    $download = '0';
    $status = 'Offline';
    $uptime = 'N/A';
    $profile = 'Hotspot pakages';

    if (!empty($active_users) && is_array($active_users)) {
        $active_user = $active_users[0] ?? [];

        $upload = isset($active_user['rx-rate'])
            ? number_format($active_user['rx-rate'] / 1048576, 2)
            : (isset($active_user['bytes-in'])
                ? number_format($active_user['bytes-in'] / 1048576, 2)
                : '0');

        $download = isset($active_user['tx-rate'])
            ? number_format($active_user['tx-rate'] / 1048576, 2)
            : (isset($active_user['bytes-out'])
                ? number_format($active_user['bytes-out'] / 1048576, 2)
                : '0');

        $status = 'Online';
        $uptime = $active_user['uptime'] ?? $uptime;
        $profile = $active_user['profile'] ?? $profile;
    }

    // Fungsi format satuan kecepatan
    function format_speed($val) {
        $num = floatval($val);
        if ($num >= 1024) {
            return number_format($num / 1024, 2) . ' GB';
        } elseif ($num >= 1) {
            return number_format($num, 2) . ' MB';
        } elseif ($num >= 0.001) {
            return number_format($num * 1024, 2) . ' KB';
        } else {
            return number_format($num * 1024 * 1024, 2) . ' B';
        }
    }

    echo json_encode([
        'idpel' => $IDPEL,  // Menggunakan IDPEL sebagai identitas user
        'profile' => $profile,
        'uptime' => $uptime,
        'upload' => format_speed($upload),
        'download' => format_speed($download),
        'status' => $status
    ], JSON_PRETTY_PRINT);

    $API->disconnect();
} else {
    echo json_encode(["error" => "Failed to connect to MikroTik server"]);
}
exit;
