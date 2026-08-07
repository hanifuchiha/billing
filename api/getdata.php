<?php
// API Proxy untuk getdata/*
// Contoh: /api/getdata.php?file=get_chart_data.php&params=...

$file = $_GET['file'] ?? '';
$allowed = [
    'get_chart_data.php', 'get_customer.php', 'get_daily_transaction.php', 'get_log.php', 'get_mikrotik_traffic.php',
    'get_odp.php', 'get_packages.php', 'get_status_transaksi.php', 'getonlinecustomer.php', 'getonlinehotspot.php',
    'get-active-users.php', 'get-active-pppoe-hotspot.php', 'get-total-active.php', 'gettxrx.php', 'gettxrx_simple.php',
    'get_area.php', 'get_area_id.php', 'get_area_user.php', 'get_pelanggan_berhenti.php', 'get_interface.php',
    'get_odp_by_server.php', 'get_odp_id.php', 'get_packages_hotspot.php', 'get_packages_id.php', 'get_packages_ratelimit.php',
    'get_packages_uptime.php', 'getonulist.php', 'count_tiket.php', 'dataload.php', 'serverload.php', 'scan_unregistered_pppoe.php',
    'readontcdata.php', 'readonthioso.php', 'readontzte.php', 'zte_onu.php', 'zte_optical.php', 'cek_radius.php', 'ping.php'
];
if (!in_array($file, $allowed)) {
    http_response_code(403);
    echo json_encode(['error' => 'File not allowed']);
    exit();
}

parse_str($_SERVER['QUERY_STRING'], $params);
unset($params['file']);

ob_start();
include __DIR__ . '/../getdata/' . $file;
$output = ob_get_clean();

// Jika output sudah JSON, langsung kirim
if (json_decode($output) !== null) {
    header('Content-Type: application/json');
    echo $output;
    exit();
}
// Jika output bukan JSON, bungkus
header('Content-Type: application/json');
echo json_encode(['result' => $output]);
