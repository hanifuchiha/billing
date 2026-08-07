<?php
// Test polling untuk server 219 (PONDOK RAJEG)
require_once '../koneksibilling.php';
require_once '../routeros_api.class.php';

// Setup Pondok Rajeg server details
$server_id = 219;
$server_ip = '10.0.0.1';
$server_pemilik = 'FIBERQ';
$server_pass = ''; // Will query from DB

// Get password from DB
$stmt = $conn->prepare("SELECT PASSWORD FROM server WHERE id = ?");
$stmt->bind_param("i", $server_id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$server_pass = $row['PASSWORD'] ?? '';

echo "Testing Server 219 - PONDOK RAJEG\n";
echo "IP: {$server_ip}\n";
echo "User: {$server_pemilik}\n";
echo "Pass: " . (strlen($server_pass) > 0 ? "***" : "EMPTY") . "\n\n";

// Try connect
$API = new RouterosAPI();
if ($API->connect($server_ip, $server_pemilik, $server_pass)) {
    echo "[OK] Connected to RouterOS\n\n";
    
    // Check PPPoE Services
    echo "=== PPPoE Services ===\n";
    $pppoe = $API->comm('/interface/pppoe-server/server/print');
    if (is_array($pppoe)) {
        echo "Found: " . count($pppoe) . " PPPoE services\n";
        foreach ($pppoe as $p) {
            echo "  - " . json_encode($p) . "\n";
        }
    } else {
        echo "Not array: " . gettype($pppoe) . "\n";
    }
    
    echo "\n=== Hotspot Services ===\n";
    $hotspot = $API->comm('/ip/hotspot/print');
    if (is_array($hotspot)) {
        echo "Found: " . count($hotspot) . " Hotspot services\n";
        foreach ($hotspot as $h) {
            echo "  - " . json_encode($h) . "\n";
        }
    } else {
        echo "Not array: " . gettype($hotspot) . "\n";
    }
    
    echo "\n=== All Interfaces ===\n";
    $all_ifaces = $API->comm('/interface/print');
    if (is_array($all_ifaces)) {
        echo "Found: " . count($all_ifaces) . " total interfaces\n";
        foreach ($all_ifaces as $i) {
            $name = $i['name'] ?? 'N/A';
            $disabled = $i['disabled'] ?? 'false';
            $running = $i['running'] ?? 'false';
            echo "  - {$name} (disabled={$disabled}, running={$running})\n";
            
            // Try monitor traffic for active interfaces
            if (($disabled == 'false' || !isset($i['disabled'])) && 
                ($running == 'true' || !isset($i['running']))) {
                try {
                    $traffic = $API->comm('/interface/monitor-traffic', [
                        'interface' => $name,
                        'once' => ''
                    ]);
                    if (is_array($traffic) && isset($traffic[0])) {
                        $rx = $traffic[0]['rx-bits-per-second'] ?? 0;
                        $tx = $traffic[0]['tx-bits-per-second'] ?? 0;
                        echo "    => Traffic: RX={$rx} bps (" . round($rx/1_000_000, 2) . " Mbps), TX={$tx} bps (" . round($tx/1_000_000, 2) . " Mbps)\n";
                    }
                } catch (Exception $e) {
                    echo "    => Error monitoring: " . $e->getMessage() . "\n";
                }
            }
        }
    } else {
        echo "Not array: " . gettype($all_ifaces) . "\n";
    }
    
    $API->disconnect();
    echo "\n[OK] Disconnected\n";
} else {
    echo "[ERROR] Failed to connect to RouterOS\n";
}

?>
