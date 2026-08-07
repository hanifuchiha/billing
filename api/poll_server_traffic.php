<?php
// poll_server_traffic.php - polling trafik semua server (authenticated), simpan ke JSON
require_once '../koneksibilling.php';
require_once '../routeros_api.class.php';

// polling untuk user authenticated
$username = $_GET['username'] ?? '';
$password = $_GET['password'] ?? '';

if (empty($username) || empty($password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'username dan password required']);
    exit;
}

// Authentika user
$stmt_auth = $conn->prepare("SELECT id FROM user WHERE USERNAME = ?");
$stmt_auth->bind_param("s", $username);
$stmt_auth->execute();
$result_auth = $stmt_auth->get_result();

if ($result_auth->num_rows === 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'User tidak ditemukan']);
    exit;
}

$user_row = $result_auth->fetch_assoc();
$user_id = $user_row['id'];

// Verify password
$stmt_pass = $conn->prepare("SELECT PASWORD FROM user WHERE id = ?");
$stmt_pass->bind_param("i", $user_id);
$stmt_pass->execute();
$result_pass = $stmt_pass->get_result();
$pass_row = $result_pass->fetch_assoc();

if (!password_verify($password, $pass_row['PASWORD'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Password salah']);
    exit;
}

// Query detail server milik user
$stmt = $conn->prepare("SELECT id, IP, PEMILIK, AREA, PASSWORD FROM server WHERE user_id = ? ORDER BY AREA");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();

$trafikCacheDir = __DIR__ . '/trafik-cache';
if (!is_dir($trafikCacheDir)) {
    mkdir($trafikCacheDir, 0777, true);
}

echo "\n=== POLLING START ===\n";

while ($srv = $res->fetch_assoc()) {
    $ip = $srv['IP'] ?? '';
    $apiUser = $srv['PEMILIK'] ?? '';
    $apiPass = $srv['PASSWORD'] ?? '';
    $id = $srv['id'] ?? '';
    
    echo "\n[Server {$id}] IP={$ip}, User={$apiUser}\n";
    
    $traffic = ['timestamp' => date('Y-m-d H:i:s'), 'rx' => 0, 'tx' => 0];
    
    if ($ip && $id && $apiUser && $apiPass) {
        $API = new RouterosAPI();
        if ($API->connect($ip, $apiUser, $apiPass)) {
            echo "  ✓ RouterOS Connected\n";
            
            // Ambil interface dari PPPoE Server
            $pppoe_servers = $API->comm('/interface/pppoe-server/server/print');
            $pppoe_ifaces = [];
            foreach ($pppoe_servers as $srv_pppoe) {
                if (!isset($srv_pppoe['interface'])) continue;
                $pppoe_ifaces[] = $srv_pppoe['interface'];
            }
            echo "  PPPoE Interfaces: " . (count($pppoe_ifaces) > 0 ? implode(", ", $pppoe_ifaces) : "NONE") . "\n";
            
            // Ambil interface dari Hotspot
            $hotspot_servers = $API->comm('/ip/hotspot/print');
            $hotspot_ifaces = [];
            foreach ($hotspot_servers as $srv_hotspot) {
                if (!isset($srv_hotspot['interface'])) continue;
                $hotspot_ifaces[] = $srv_hotspot['interface'];
            }
            echo "  Hotspot Interfaces: " . (count($hotspot_ifaces) > 0 ? implode(", ", $hotspot_ifaces) : "NONE") . "\n";
            
            $all_ifaces = array_unique(array_merge($pppoe_ifaces, $hotspot_ifaces));
            
            // FALLBACK: Jika dari service tidak ada interface, ambil dari semua interface
            if (count($all_ifaces) == 0) {
                echo "  → FALLBACK: Detecting ALL interfaces...\n";
                $all_interfaces = $API->comm('/interface/print');
                foreach ($all_interfaces as $iface) {
                    if (isset($iface['name'])) {
                        $all_ifaces[] = $iface['name'];
                    }
                }
                echo "  All Interfaces: " . implode(", ", $all_ifaces) . "\n";
            }
            
            $total_rx = 0;
            $total_tx = 0;
            foreach ($all_ifaces as $name) {
                $data = $API->comm('/interface/monitor-traffic', [
                    'interface' => $name,
                    'once' => ''
                ]);
                $rx = isset($data[0]['rx-bits-per-second']) ? (int)$data[0]['rx-bits-per-second'] : 0;
                $tx = isset($data[0]['tx-bits-per-second']) ? (int)$data[0]['tx-bits-per-second'] : 0;
                // rx = upload (data masuk ke server), tx = download (data keluar dari server)
                echo "    {$name}: Upload=" . round($rx/1_000_000, 2) . "Mbps (RX), Download=" . round($tx/1_000_000, 2) . "Mbps (TX)\n";
                $total_rx += $rx;
                $total_tx += $tx;
            }
            $traffic['rx'] = $total_rx;
            $traffic['tx'] = $total_tx;
            echo "  TOTAL: Upload=" . round($total_rx/1_000_000, 2) . "Mbps (RX), Download=" . round($total_tx/1_000_000, 2) . "Mbps (TX)\n";
            $API->disconnect();
        } else {
            echo "  ✗ RouterOS Connection FAILED\n";
        }
    } else {
        echo "  ✗ Missing credentials (IP={$ip}, User={$apiUser})\n";
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
    echo "  → Cache saved to: server_traffic_data_{$id}.json (Total entries: " . count($history) . ")\n";
}

echo "\n=== POLLING COMPLETE ===\n";
echo json_encode(['success' => true, 'updated' => 'all_servers']);
