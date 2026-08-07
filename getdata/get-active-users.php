<?php
require '../koneksidb.php'; // Adjust path if needed

$ip = $_GET['ip'] ?? '';
$us = $_GET['us'] ?? '';
$ps = $_GET['ps'] ?? '';

if (!$ip || !$us || !$ps) {
    echo json_encode(['error' => 'Missing parameters']);
    exit;
}

use \RouterOS\Client;
use \RouterOS\Query;

try {
    $client = new Client([
        'host' => $ip,
        'user' => $us,
        'pass' => $ps,
    ]);

    // Get active PPPoE users
    $queryPppoe = new Query('/ppp/active/print');
    $pppoeActive = $client->query($queryPppoe)->read();
    $totalPppoeActive = count($pppoeActive);

    // Get active Hotspot users
    $queryHotspot = new Query('/ip/hotspot/active/print');
    $hotspotActive = $client->query($queryHotspot)->read();
    $totalHotspotActive = count($hotspotActive);

    echo json_encode([
        'pppoe_active' => $totalPppoeActive,
        'hotspot_active' => $totalHotspotActive
    ]);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>