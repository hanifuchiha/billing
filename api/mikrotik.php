<?php
// API Proxy untuk proses ke Mikrotik (contoh: reboot, update, cek, dsb)
// Contoh: /api/mikrotik.php?action=reboot&id=123

$action = $_GET['action'] ?? '';
$allowed = [
    'reboot', 'update_ssid', 'update_wan', 'update_wan_service', 'add_wan_connection', 'delete_unregistered_pppoe',
    'insert_traffic', 'scan_unregistered_pppoe', 'ping', 'cek_radius', 'get_log_mikrotik', 'get_mikrotik_traffic'
];
if (!in_array($action, $allowed)) {
    http_response_code(403);
    echo json_encode(['error' => 'Action not allowed']);
    exit();
}

// Map action ke file PHP getdata yang sesuai
$map = [
    'reboot' => 'acs_reboot_device.php',
    'update_ssid' => 'acs_update_ssid.php',
    'update_wan' => 'acs_update_wan_parameters.php',
    'update_wan_service' => 'acs_update_wan_service.php',
    'add_wan_connection' => 'acs_add_wan_connection_device.php',
    'delete_unregistered_pppoe' => 'delete_unregistered_pppoe.php',
    'insert_traffic' => 'insert_traffic.php',
    'scan_unregistered_pppoe' => 'scan_unregistered_pppoe.php',
    'ping' => 'ping.php',
    'cek_radius' => 'cek_radius.php',
    'get_log_mikrotik' => 'get_log_mikrotik.php',
    'get_mikrotik_traffic' => 'get_mikrotik_traffic.php'
];

$file = $map[$action];
parse_str($_SERVER['QUERY_STRING'], $params);

ob_start();
include __DIR__ . '/../getdata/' . $file;
$output = ob_get_clean();

if (json_decode($output) !== null) {
    header('Content-Type: application/json');
    echo $output;
    exit();
}
header('Content-Type: application/json');
echo json_encode(['result' => $output]);
