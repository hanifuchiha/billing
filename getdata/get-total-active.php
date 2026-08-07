<?php
require '../cek-sesi.php';
require '../routeros_api.class.php';

header('Content-Type: application/json');

$servers = [];
$sql = mysqli_query($conn, "SELECT * FROM server WHERE user_id = '" . (int)$current_user_id . "'");
while ($srv = mysqli_fetch_assoc($sql)) {
    $servers[] = $srv;
}

$total_pppoe = 0;
$total_hotspot = 0;

foreach ($servers as $srv) {
    $ip = $srv['IP'];
    $us = $srv['PEMILIK'];
    $ps = $srv['PASSWORD'];

    if (!$us || !$ps) continue;

    try {
        $API = new RouterosAPI();
        if ($API->connect($ip, $us, $ps)) {
            $pppoe_active = $API->comm('/ppp/active/print', array('count-only' => ''));
            $hotspot_active = $API->comm('/ip/hotspot/active/print', array('count-only' => ''));
            $total_pppoe += (int)$pppoe_active;
            $total_hotspot += (int)$hotspot_active;
            $API->disconnect();
        }
    } catch (Exception $e) {
        // Skip on error
    }
}

echo json_encode([
    'total_pppoe' => $total_pppoe,
    'total_hotspot' => $total_hotspot
]);
?>