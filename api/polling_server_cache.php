<?php
// polling_server_cache.php
// Jalankan via cron setiap 5 menit untuk update cache trafik semua server
// Mengambil data dari RouterOS dan menyimpan ke file JSON di folder trafik-cache

require_once '../koneksibilling.php';
require_once '../routeros_api.class.php';

// Tentukan direktori cache
$trafikCacheDir = __DIR__ . '/trafik-cache';
if (!is_dir($trafikCacheDir)) {
    mkdir($trafikCacheDir, 0777, true);
}

// Ambil semua server
$sql = "SELECT id, IP, USERNAME, PASWORD, PEMILIK, AREA, BRAND, IDENTITY FROM server";
$res = $conn->query($sql);

if (!$res) {
    error_log("Query server gagal: " . $conn->error);
    exit;
}

while ($srv = $res->fetch_assoc()) {
    $id = $srv['id'] ?? '';
    $ip = $srv['IP'] ?? '';
    $user = $srv['USERNAME'] ?? 'admin';
    $pass = $srv['PASWORD'] ?? 'password';
    $identity = $srv['IDENTITY'] ?? '';
    
    // Ambil hanya IP address tanpa port
    if (strpos($ip, ':') !== false) {
        $ip = explode(':', $ip)[0];
    }
    
    if (empty($ip) || empty($id)) {
        continue;
    }
    
    $total_rx = 0;
    $total_tx = 0;
    $timestamp = date('Y-m-d H:i:s');
    
    // Connect ke RouterOS dan ambil traffic data
    $API = new RouterosAPI();
    if (@$API->connect($ip, $user, $pass)) {
        try {
            // Ambil interface dari PPPoE Server
            $pppoe_servers = @$API->comm('/interface/pppoe-server/server/print');
            $pppoe_ifaces = [];
            if (!empty($pppoe_servers)) {
                foreach ($pppoe_servers as $srv_pppoe) {
                    if (!isset($srv_pppoe['interface'])) continue;
                    $pppoe_ifaces[] = $srv_pppoe['interface'];
                }
            }
            
            // Ambil interface dari Hotspot
            $hotspot_servers = @$API->comm('/ip/hotspot/print');
            $hotspot_ifaces = [];
            if (!empty($hotspot_servers)) {
                foreach ($hotspot_servers as $srv_hotspot) {
                    if (!isset($srv_hotspot['interface'])) continue;
                    $hotspot_ifaces[] = $srv_hotspot['interface'];
                }
            }
            
            $all_ifaces = array_unique(array_merge($pppoe_ifaces, $hotspot_ifaces));
            
            // Monitor traffic untuk setiap interface
            foreach ($all_ifaces as $iface) {
                try {
                    $data = @$API->comm('/interface/monitor-traffic', [
                        'interface' => $iface,
                        'once' => ''
                    ]);
                    
                    if (!empty($data[0])) {
                        // Data adalah dalam bits per second, konversi ke bytes
                        $rx_bps = (int)($data[0]['rx-bits-per-second'] ?? 0);
                        $tx_bps = (int)($data[0]['tx-bits-per-second'] ?? 0);
                        
                        // Simpan dalam bytes (bps / 8 = bytes per second)
                        $total_rx += intval($rx_bps / 8);
                        $total_tx += intval($tx_bps / 8);
                    }
                } catch (Exception $e) {
                    error_log("Error monitoring interface $iface: " . $e->getMessage());
                }
            }
            
            $API->disconnect();
        } catch (Exception $e) {
            error_log("Error connecting to server $id ($ip): " . $e->getMessage());
        }
    } else {
        error_log("Cannot connect to server $id ($ip)");
    }
    
    // Simpan ke cache file berdasarkan IDENTITY atau ID
    $cacheFileName = null;
    if (!empty($identity)) {
        $cacheFileName = $trafikCacheDir . '/trafik_' . $identity . '.json';
    } else {
        $cacheFileName = $trafikCacheDir . '/server_traffic_data_' . $id . '.json';
    }
    
    if (!empty($cacheFileName)) {
        $history = file_exists($cacheFileName) ? json_decode(file_get_contents($cacheFileName), true) : [];
        if (!is_array($history)) {
            $history = [];
        }
        
        $history[] = [
            'timestamp' => $timestamp,
            'rx' => $total_rx,
            'tx' => $total_tx,
            'time' => $timestamp  // Include both timestamp and time for compatibility
        ];
        
        // Batasi hanya 50 data terakhir untuk performance
        if (count($history) > 50) {
            $history = array_slice($history, -50);
        }
        
        file_put_contents($cacheFileName, json_encode($history));
    }
}

// Log sukses
error_log("Server traffic cache updated at " . date('Y-m-d H:i:s'));
echo json_encode(['success' => true, 'message' => 'Server traffic cache updated']);
