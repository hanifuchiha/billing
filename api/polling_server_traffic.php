<?php
// polling_server_traffic.php - polling trafik semua server, simpan ke JSON
require_once '../koneksibilling.php';
require_once '../routeros_api.class.php';





// polling hanya untuk server_id yang dikirim lewat GET (bisa array)
if (!isset($_GET['server_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'server_id required']);
    exit;
}
$server_ids = $_GET['server_id'];
if (!is_array($server_ids)) $server_ids = [$server_ids];

$json_file = 'server_traffic_data.json';
$data = file_exists($json_file) ? json_decode(file_get_contents($json_file), true) : [];

// Query detail server hanya untuk id yang diminta
$in  = str_repeat('?,', count($server_ids) - 1) . '?';
$types = str_repeat('i', count($server_ids));
$stmt = $conn->prepare("SELECT * FROM server WHERE id IN ($in)");
$stmt->bind_param($types, ...$server_ids);
$stmt->execute();
$res = $stmt->get_result();
while ($srv = $res->fetch_assoc()) {
    $ip = $srv['IP'] ?? '';
    
    $apiUser = $srv['PEMILIK'] ?? '';
    $apiPass = $srv['PASSWORD'] ?? '';
    $id = $srv['ID'] ?? $srv['id'] ?? $srv['id_server'] ?? '';
    $traffic = ['timestamp' => date('Y-m-d H:i:s'), 'rx' => 0, 'tx' => 0];
    if ($ip && $id) {
        $API = new RouterosAPI();
        if ($API->connect($ip, $apiUser, $apiPass)) {
            // Ambil interface dari PPPoE Server
            $pppoe_servers = $API->comm('/interface/pppoe-server/server/print');
            $pppoe_ifaces = [];
            foreach ($pppoe_servers as $srv_pppoe) {
                if (!isset($srv_pppoe['interface'])) continue;
                $pppoe_ifaces[] = $srv_pppoe['interface'];
            }
            // Ambil interface dari Hotspot
            $hotspot_servers = $API->comm('/ip/hotspot/print');
            $hotspot_ifaces = [];
            foreach ($hotspot_servers as $srv_hotspot) {
                if (!isset($srv_hotspot['interface'])) continue;
                $hotspot_ifaces[] = $srv_hotspot['interface'];
            }
            $all_ifaces = array_unique(array_merge($pppoe_ifaces, $hotspot_ifaces));
            $total_rx = 0;
            $total_tx = 0;
            foreach ($all_ifaces as $name) {
                $data = $API->comm('/interface/monitor-traffic', [
                    'interface' => $name,
                    'once' => ''
                ]);
                $rx = isset($data[0]['rx-bits-per-second']) ? (int)$data[0]['rx-bits-per-second'] : 0;
                $tx = isset($data[0]['tx-bits-per-second']) ? (int)$data[0]['tx-bits-per-second'] : 0;
                $total_rx += $rx;
                $total_tx += $tx;
            }
            $traffic['rx'] = $total_rx;
            $traffic['tx'] = $total_tx;
            $API->disconnect();
        }
    }
    // Pastikan folder trafik-cache ada
    $trafikCacheDir = __DIR__ . '/trafik-cache';
    if (!is_dir($trafikCacheDir)) {
        mkdir($trafikCacheDir, 0777, true);
    }
    // Simpan file JSON per server
    $server_json_file = $trafikCacheDir . "/server_traffic_data_{$id}.json";
    $history = file_exists($server_json_file) ? json_decode(file_get_contents($server_json_file), true) : [];
    $history[] = $traffic;
    // Batasi hanya 50 data terakhir
    if (count($history) > 50) {
        $history = array_slice($history, -50);
    }
    file_put_contents($server_json_file, json_encode($history));
}
echo json_encode(['success' => true, 'updated' => $server_ids]);
