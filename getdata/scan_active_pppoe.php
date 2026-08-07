<?php
include '../cek-sesi.php';
require 'routeros_api.class.php';

header('Content-Type: application/json');

$scanStartTime = microtime(true);

$selectedServer = isset($_GET['server']) ? trim((string)$_GET['server']) : '';
$selectedServerEsc = mysqli_real_escape_string($conn, $selectedServer);
$currentUserId = isset($current_user_id) ? (int)$current_user_id : 0;

// Cache
$cacheScopeParts = [
    'active',
    (string)($AKSES ?? ''),
    (string)($ceknama ?? ''),
    (string)$selectedServer,
    (string)($area_list ?? '')
];
$cacheKey = sha1(implode('|', $cacheScopeParts));
$cacheDir = __DIR__ . '/../serverlog/cache_scan_pppoe';
$cacheFile = $cacheDir . '/active_' . $cacheKey . '.json';
$cacheTtlSeconds = 30; // Cache lebih pendek karena data aktif cepat berubah

if (file_exists($cacheFile) && (time() - (int)@filemtime($cacheFile)) <= $cacheTtlSeconds) {
    $cachedRaw = @file_get_contents($cacheFile);
    $cachedData = json_decode((string)$cachedRaw, true);
    if (is_array($cachedData) && isset($cachedData['success'])) {
        $cachedData['cache_hit'] = true;
        $cachedData['cache_ttl_seconds'] = $cacheTtlSeconds;
        echo json_encode($cachedData);
        exit;
    }
}

// Ambil server yang dapat diakses user
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

    if ($ip === '' || $username === '') continue;

    $uniqueKey = strtolower($ip . '|' . $username . '|' . $password);
    if (isset($serverSeen[$uniqueKey])) continue;

    $serverSeen[$uniqueKey] = true;
    $servers[] = [
        'ip'    => $ip,
        'user'  => $username,
        'pass'  => $password,
        'area'  => (string)($row['AREA'] ?? ''),
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

// Ambil daftar IDPEL dari database untuk pembanding
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

// Scan active connections dari setiap MikroTik
$hasil = [];
$hasilSeen = [];
$gagalServers = [];
$totalActive = 0;

foreach ($servers as $srv) {
    $api = new RouterosAPI();
    $api->timeout = 3;
    $api->attempts = 1;
    $api->delay = 0;

    if (!$api->connect($srv['ip'], $srv['user'], $srv['pass'])) {
        $gagalServers[] = [
            'ip'      => $srv['ip'],
            'area'    => $srv['area'],
            'brand'   => $srv['brand'],
            'message' => 'Gagal konek ke Mikrotik.'
        ];
        continue;
    }

    $api->write('/ppp/active/print', false);
    $api->write('=.proplist=name,service,caller-id,address,uptime,session-id');
    $read = $api->read(false);
    $actives = $api->parseResponse($read);
    $api->disconnect();

    if (!is_array($actives)) continue;

    foreach ($actives as $active) {
        $name = trim((string)($active['name'] ?? ''));
        if ($name === '') continue;

        $totalActive++;
        $nameKey = strtolower($name);
        if (isset($pelangganSet[$nameKey])) continue; // sudah terdaftar, skip

        $resultKey = strtolower($srv['ip'] . '|' . $name);
        if (isset($hasilSeen[$resultKey])) continue;

        $hasilSeen[$resultKey] = true;
        $hasil[] = [
            'username'    => $name,
            'service'     => (string)($active['service'] ?? '-'),
            'caller_id'   => (string)($active['caller-id'] ?? '-'),
            'ip_address'  => (string)($active['address'] ?? '-'),
            'uptime'      => (string)($active['uptime'] ?? '-'),
            'session_id'  => (string)($active['session-id'] ?? '-'),
            'server_ip'   => $srv['ip'],
            'server_user' => $srv['user'],
            'server_area' => $srv['area'],
            'server_brand'=> $srv['brand']
        ];
    }
}

usort($hasil, function ($a, $b) {
    return strcmp($a['username'], $b['username']);
});

$responsePayload = [
    'success'              => true,
    'message'              => 'Scan active connections selesai.',
    'selected_server'      => $selectedServer !== '' ? $selectedServer : 'ALL',
    'scanned_server_count' => count($servers),
    'failed_server_count'  => count($gagalServers),
    'failed_servers'       => $gagalServers,
    'total_active_checked' => $totalActive,
    'total_unregistered'   => count($hasil),
    'elapsed_ms'           => (int)round((microtime(true) - $scanStartTime) * 1000),
    'cache_hit'            => false,
    'cache_ttl_seconds'    => $cacheTtlSeconds,
    'data'                 => $hasil
];

if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0775, true);
}
if (is_dir($cacheDir)) {
    @file_put_contents($cacheFile, json_encode($responsePayload));
}

echo json_encode($responsePayload);
exit;
