<?php
require '../cek-sesi.php';
header('Content-Type: application/json');

$conn = new mysqli($server, $username_db, $password_db, $database);
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

// Get server parameter if provided
$selectedServer = isset($_GET['server']) ? trim($_GET['server']) : '';
$failedServers = [];
$allConnections = [];
$totalChecked = 0;

// Query servers
if (!empty($selectedServer)) {
    $sql = "SELECT * FROM server WHERE PEMILIK = '" . mysqli_real_escape_string($conn, $selectedServer) . "'";
} else {
    $sql = "SELECT * FROM server";
}

$serverResult = $conn->query($sql);
if (!$serverResult) {
    echo json_encode(['success' => false, 'message' => 'Failed to query servers']);
    exit;
}

// Get all registered customers
$customersResult = $conn->query("SELECT IDPEL FROM pelanggan");
$registeredCustomers = [];
if ($customersResult) {
    while ($row = $customersResult->fetch_assoc()) {
        $registeredCustomers[strtolower(trim($row['IDPEL']))] = true;
    }
}

// Scan each server
require '../routeros_api.class.php';

while ($serverRow = $serverResult->fetch_assoc()) {
    $serverIp = $serverRow['IP'];
    $serverUser = $serverRow['USER'];
    $serverPassword = $serverRow['PASSWORD'];
    $serverPemilik = $serverRow['PEMILIK'];
    $serverArea = $serverRow['AREA'];
    $serverBrand = $serverRow['BRAND'] ?? '';

    try {
        $api = new RouterosAPI();
        $api->debug = false;

        if ($api->connect($serverIp, $serverUser, $serverPassword)) {
            // Get active PPP connections
            $api->write('/ppp/active/print', false);
            $api->write('=.tag=1');
            $api->readln();

            $activeConnections = [];
            while (true) {
                $response = $api->readln();
                if ($response === false) break;

                if (isset($response['.tag']) && $response['.tag'] == 1) {
                    break;
                }

                if (isset($response['name'])) {
                    $activeConnections[] = [
                        'id' => $response['.id'] ?? '',
                        'name' => $response['name'] ?? '',
                        'address' => $response['address'] ?? '',
                        'caller_id' => $response['caller-id'] ?? '',
                        'limit_bytes_in' => $response['limit-bytes-in'] ?? '0',
                        'limit_bytes_out' => $response['limit-bytes-out'] ?? '0',
                        'bytes_in' => $response['bytes-in'] ?? '0',
                        'bytes_out' => $response['bytes-out'] ?? '0',
                        'uptime' => $response['uptime'] ?? '',
                    ];
                }
            }

            $api->disconnect();

            // Check which ones are NOT in database
            foreach ($activeConnections as $conn_data) {
                $idpel = strtolower(trim($conn_data['name']));
                if (!isset($registeredCustomers[$idpel])) {
                    $allConnections[] = [
                        'id' => $conn_data['id'],
                        'idpel' => $conn_data['name'],
                        'address' => $conn_data['address'],
                        'caller_id' => $conn_data['caller_id'],
                        'uptime' => $conn_data['uptime'],
                        'server_ip' => $serverIp,
                        'server_user' => $serverUser,
                        'server_pemilik' => $serverPemilik,
                        'server_area' => $serverArea,
                        'server_brand' => $serverBrand,
                        'bytes_in' => $conn_data['bytes_in'],
                        'bytes_out' => $conn_data['bytes_out'],
                    ];
                }
            }

            $totalChecked += count($activeConnections);
        } else {
            $failedServers[] = $serverIp;
        }
    } catch (Exception $e) {
        $failedServers[] = $serverIp;
        error_log("Error scanning server $serverIp: " . $e->getMessage());
    }
}

$conn->close();

echo json_encode([
    'success' => true,
    'message' => 'Scan completed',
    'data' => $allConnections,
    'total_checked' => $totalChecked,
    'failed_server_count' => count($failedServers),
    'failed_servers' => $failedServers
]);
?>
