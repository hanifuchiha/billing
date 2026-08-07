<?php
include '../cek-sesi.php'; // Pastikan koneksi ke database sudah benar

require('routeros_api.class.php');

header('Content-Type: application/json');

if (!isset($_GET['user'])) {
    echo json_encode(['error' => 'User parameter is required']);
    exit;
}

$API = new RouterosAPI();
$mikrotik_ip = $_GET['ipserver']; // Ganti dengan IP MikroTik Anda
$mikrotik_user = $_GET['userserver']; // Ganti dengan username MikroTik Anda
$mikrotik_pass = $_GET['passwordserver']; // Ganti dengan password MikroTik Anda
$pppoe_user = $_GET['user'];

if ($API->connect($mikrotik_ip, $mikrotik_user, $mikrotik_pass)) {
    // Ambil data trafik dari interface PPPoE
    $API->write('/interface/monitor-traffic', false);
    $API->write('=interface=<pppoe-' . $pppoe_user . ">", false);
    $API->write('=once=');
    $traffic = $API->read();


    if (!empty($traffic)) {
        $download = round($traffic[0]['tx-bits-per-second'] / 1_000_000, 2); // Convert to Mbps
        $upload = round($traffic[0]['rx-bits-per-second'] / 1_000_000, 2);   // Convert to Mbps


        echo json_encode([
            'download' => $download,
            'upload' => $upload,
            'user' => $pppoe_user,
            // 'mikrotik_ip' => $mikrotik_ip,
            // 'mikrotik_user' => $mikrotik_user,
            // 'mikrotik_pass' => $mikrotik_pass
        ]);
    } elseif ($pppoe_user == "") {
        echo json_encode(['error' => 'userid kosong']);
    } elseif ($mikrotik_ip == "") {
        echo json_encode(['error' => 'mikrotik_ip kosong']);
    } elseif ($mikrotik_user == "") {
        echo json_encode(['error' => 'mikrotik_user kosong']);
    } else {
        echo json_encode(['error' => 'No data found']);
    }

    $API->disconnect();
} else {
    echo json_encode(['error' => 'Failed to connect to MikroTik']);
}
exit;
