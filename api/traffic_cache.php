<?php
// api/traffic_cache.php - Get cached traffic data for servers
error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    require_once '../koneksibilling.php';
    require_once '_bootstrap.php';
    session_start();
    api_cors();

    // Diganti ke _bootstrap.php::api_authenticate() (session -> username+password ->
    // API key dari tabel `apikey`) -- sebelumnya tidak pernah baca param `key`/`api_key`.
    $method = $_SERVER['REQUEST_METHOD'];
    $input = api_read_input();

    $auth = api_authenticate($conn, $input);
    $pemilik = $auth['pemilik'];
    if ($auth['method'] === 'apikey') {
        api_rate_limit($conn, $auth['api_key']);
    }

    // Create table if not exists
    $create_table = "CREATE TABLE IF NOT EXISTS server_traffic_cache (
        id INT AUTO_INCREMENT PRIMARY KEY,
        server_id INT,
        pemilik VARCHAR(255),
        area VARCHAR(255),
        brand VARCHAR(255),
        identity VARCHAR(255),
        ip VARCHAR(50),
        upload_mbps DECIMAL(10, 2),
        download_mbps DECIMAL(10, 2),
        last_update TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY server_cache (server_id, pemilik)
    )";
    mysqli_query($conn, $create_table);

    if ($method === 'GET') {
        // Get cached traffic data for user's servers
        $q = mysqli_query($conn, "SELECT * FROM server_traffic_cache WHERE pemilik = '".mysqli_real_escape_string($conn, $pemilik)."' ORDER BY last_update DESC");
        
        $data = [];
        while ($row = mysqli_fetch_assoc($q)) {
            $data[] = [
                'id' => $row['server_id'],
                'area' => $row['area'],
                'brand' => $row['brand'],
                'identity' => $row['identity'],
                'ip' => $row['ip'],
                'upload_mbps' => (float)$row['upload_mbps'],
                'download_mbps' => (float)$row['download_mbps'],
                'last_update' => $row['last_update']
            ];
        }

        echo json_encode(['success' => true, 'data' => $data]);
        exit;
    }
    elseif ($method === 'POST') {
        // Update traffic cache
        $server_id = $input['server_id'] ?? 0;
        $area = $input['area'] ?? '';
        $brand = $input['brand'] ?? '';
        $identity = $input['identity'] ?? '';
        $ip = $input['ip'] ?? '';
        $upload_mbps = (float)($input['upload_mbps'] ?? 0);
        $download_mbps = (float)($input['download_mbps'] ?? 0);

        if (!$server_id) {
            echo json_encode(['success' => false, 'error' => 'server_id required']);
            exit;
        }

        $stmt = $conn->prepare("INSERT INTO server_traffic_cache (server_id, pemilik, area, brand, identity, ip, upload_mbps, download_mbps) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE upload_mbps=?, download_mbps=?, last_update=CURRENT_TIMESTAMP");
        $stmt->bind_param("isssssddd", $server_id, $pemilik, $area, $brand, $identity, $ip, $upload_mbps, $download_mbps, $upload_mbps, $download_mbps);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Traffic cache updated']);
        } else {
            echo json_encode(['success' => false, 'error' => $stmt->error]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Method tidak didukung']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
