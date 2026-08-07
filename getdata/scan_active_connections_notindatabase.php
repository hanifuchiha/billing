<?php
include '../cek-sesi.php';
require 'routeros_api.class.php';

header('Content-Type: application/json');

$scanStartTime = microtime(true);

$selectedServer = isset($_GET['server']) ? trim((string)$_GET['server']) : '';
$selectedServerEsc = mysqli_real_escape_string($conn, $selectedServer);
$currentUserId = isset($current_user_id) ? (int)$current_user_id : 0;

// Get list of servers
$serverQuery = "SELECT IP, PEMILIK, PASSWORD, AREA, BRAND FROM server WHERE 1=1";
if ($selectedServer !== '') {
    $serverQuery .= " AND PEMILIK = '$selectedServerEsc'";
}
if ($AKSES === 'ASSISTANT') {
    if (!isset($area_list) || trim((string)$area_list) === '') {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Area assistant tidak ditemukan.'
        ]);
        exit;
    }
    $serverQuery .= " AND AREA IN ($area_list)";
} elseif ($AKSES !== 'ADMIN') {
    $serverQuery .= " AND user_id = $currentUserId";
}

$serverResult = mysqli_query($conn, $serverQuery);
if (!$serverResult) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Gagal mengambil data server.',
        'error' => mysqli_error($conn)
    ]);
    exit;
}

$servers = [];
$serverSeen = [];
while ($row = mysqli_fetch_assoc($serverResult)) {
    $ip = trim((string)($row['IP'] ?? ''));
    $username = trim((string)($row['PEMILIK'] ?? ''));
    $password = (string)($row['PASSWORD'] ?? '');

    if ($ip === '' || $username === '') {
        continue;
    }

    $uniqueKey = strtolower($ip . '|' . $username . '|' . $password);
    if (isset($serverSeen[$uniqueKey])) {
        continue;
    }

    $serverSeen[$uniqueKey] = true;
    $servers[] = [
        'ip' => $ip,
        'user' => $username,
        'pass' => $password,
        'area' => (string)($row['AREA'] ?? ''),
        'brand' => (string)($row['BRAND'] ?? '')
    ];
}

if (count($servers) === 0) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => 'Server tidak ditemukan atau tidak punya akses.'
    ]);
    exit;
}

// Get list of registered customers (IDPEL)
if ($selectedServer !== '') {
    $pelangganQuery = "SELECT IDPEL FROM pelanggan WHERE PEMILIK = '$selectedServerEsc'";
} else {
    $pemilikSet = [];
    foreach ($servers as $srv) {
        $pemilik = trim((string)($srv['user'] ?? ''));
        if ($pemilik !== '') {
            $pemilikSet[] = "'" . mysqli_real_escape_string($conn, $pemilik) . "'";
        }
    }
    $pemilikSet = array_values(array_unique($pemilikSet));
    if (count($pemilikSet) > 0) {
        $pelangganQuery = "SELECT IDPEL FROM pelanggan WHERE PEMILIK IN (" . implode(',', $pemilikSet) . ")";
    } else {
        $pelangganQuery = "SELECT IDPEL FROM pelanggan WHERE 0";
    }
}

if ($AKSES === 'ASSISTANT' && isset($area_list) && trim((string)$area_list) !== '') {
    $pelangganQuery .= " AND AREA IN ($area_list)";
}

$pelangganResult = mysqli_query($conn, $pelangganQuery);
if (!$pelangganResult) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Gagal mengambil data pelanggan.',
        'error' => mysqli_error($conn)
    ]);
    exit;
}

$pelangganSet = [];
while ($row = mysqli_fetch_assoc($pelangganResult)) {
    $idpel = strtolower(trim((string)($row['IDPEL'] ?? '')));
    if ($idpel !== '') {
        $pelangganSet[$idpel] = true;
    }
}

$hasil = [];
$hasilSeen = [];
$gagalServers = [];
$totalConnections = 0;

foreach ($servers as $srv) {
    $api = new RouterosAPI();
    $api->timeout = 2;
    $api->attempts = 1;
    $api->delay = 0;

    if (!$api->connect($srv['ip'], $srv['user'], $srv['pass'])) {
        $gagalServers[] = [
            'ip' => $srv['ip'],
            'area' => $srv['area'],
            'brand' => $srv['brand'],
            'message' => 'Gagal konek ke Mikrotik.'
        ];
        continue;
    }

    // Get active PPP connections
    $api->write('/ppp/active/print', false);
    $api->write('=.proplist=name,address,interface,uptime');
    $read = $api->read(false);
    $connections = $api->parseResponse($read);
    $api->disconnect();

    if (!is_array($connections)) {
        continue;
    }

    foreach ($connections as $conn_info) {
        $name = trim((string)($conn_info['name'] ?? ''));
        if ($name === '') {
            continue;
        }

        $totalConnections++;
        $nameKey = strtolower($name);
        
        // Check if this connection username exists in database
        $in_database = isset($pelangganSet[$nameKey]);

        // Only include if NOT in database
        if ($in_database) {
            continue;
        }

        $resultKey = strtolower($srv['ip'] . '|' . $name);
        if (isset($hasilSeen[$resultKey])) {
            continue;
        }

        $hasilSeen[$resultKey] = true;
        
        // Generate unique connection ID
        $connection_id = $name . '@' . $srv['ip'] . ':' . time();

        $hasil[] = [
            'username' => $name,
            'address' => (string)($conn_info['address'] ?? '-'),
            'interface' => (string)($conn_info['interface'] ?? '-'),
            'uptime' => (string)($conn_info['uptime'] ?? '-'),
            'connection_id' => $connection_id,
            'server_ip' => $srv['ip'],
            'server_user' => $srv['user'],
            'server_area' => $srv['area'],
            'server_brand' => $srv['brand'],
            'in_database' => $in_database
        ];
    }
}

usort($hasil, function ($a, $b) {
    return strcmp($a['username'], $b['username']);
});

$responsePayload = [
    'success' => true,
    'message' => 'Scan selesai.',
    'selected_server' => $selectedServer !== '' ? $selectedServer : 'ALL',
    'scanned_server_count' => count($servers),
    'failed_server_count' => count($gagalServers),
    'failed_servers' => $gagalServers,
    'total_connections_checked' => $totalConnections,
    'total_not_in_database' => count($hasil),
    'elapsed_ms' => (int)round((microtime(true) - $scanStartTime) * 1000),
    'data' => $hasil
];

echo json_encode($responsePayload);
exit;
