<?php
require '../routeros_api.class.php';

$ip = $_GET['ip'] ?? '';
$us = $_GET['us'] ?? '';
$ps = $_GET['ps'] ?? '';

if (!$ip || !$us || !$ps) {
    echo json_encode(['error' => 'Missing parameters']);
    exit;
}

$API = new RouterosAPI();
$API->debug = false;

if ($API->connect($ip, $us, $ps)) {
    // Get active PPPoE count
    $pppoe_active = $API->comm('/ppp/active/print');
    $pppoe_active_count = count($pppoe_active);

    // Get active Hotspot count
    $hotspot_active = $API->comm('/ip/hotspot/active/print');
    $hotspot_active_count = count($hotspot_active);

    $API->disconnect();

    echo json_encode([
        'pppoe_active' => $pppoe_active_count,
        'hotspot_active' => $hotspot_active_count
    ]);
} else {
    echo json_encode(['error' => 'Connection failed']);
}
?>