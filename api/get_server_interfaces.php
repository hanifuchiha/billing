<?php
// Sebelumnya file ini require '../cek-sesi.php' (bootstrap panel admin, redirect
// ke halaman login kalau tidak ada session browser) -- API key tidak pernah
// dicek. Diganti ke pola auth API yang benar (session ATAU username+password
// ATAU API key dari tabel `apikey`), sama seperti api/odp.php dkk. Scoping
// ASSISTANT/owner sekarang pakai allowed_server_ids dari api_resolve_owner()
// (server.id langsung, bukan pencocokan nama AREA lewat string SQL).
require_once __DIR__ . '/../koneksibilling.php';
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../routeros_api.class.php';
session_start();
api_cors();

$input = api_read_input();
$auth = api_authenticate($conn, $input);
$pemilik = $auth['pemilik'];
if ($auth['method'] === 'apikey') {
    api_rate_limit($conn, $auth['api_key']);
}
$ctx = api_resolve_owner($conn, $pemilik);
if (!$ctx) {
    api_json(['success' => false, 'message' => 'User tidak ditemukan'], 401);
}

$serverId = isset($_GET['server_id']) ? (int)$_GET['server_id'] : 0;
if ($serverId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Server tidak valid.']);
    exit;
}

$allowedServerIds = $ctx['allowed_server_ids'] ?? [];
if (!in_array($serverId, $allowedServerIds, true)) {
    echo json_encode(['success' => false, 'message' => 'Server tidak ditemukan.']);
    exit;
}
$sql = "SELECT id, IP, PASSWORD, PEMILIK, AREA FROM server WHERE id = $serverId LIMIT 1";
$query = mysqli_query($conn, $sql);
if (!$query || mysqli_num_rows($query) === 0) {
    echo json_encode(['success' => false, 'message' => 'Server tidak ditemukan.']);
    exit;
}

$server = mysqli_fetch_assoc($query);
$API = new RouterosAPI();
$routerEndpoint = trim((string)($server['IP'] ?? ''));
$routerHost = $routerEndpoint;
$routerPort = 8728;

if (preg_match('/^[a-z]+:\/\//i', $routerEndpoint)) {
    $parsed = parse_url($routerEndpoint);
    if (!empty($parsed['host'])) {
        $routerHost = $parsed['host'];
    }
    if (!empty($parsed['port'])) {
        $routerPort = (int)$parsed['port'];
    }
} else {
    $pos = strrpos($routerEndpoint, ':');
    if ($pos !== false) {
        $hostPart = substr($routerEndpoint, 0, $pos);
        $portPart = substr($routerEndpoint, $pos + 1);
        if ($hostPart !== '' && ctype_digit($portPart)) {
            $routerHost = $hostPart;
            $routerPort = (int)$portPart;
        }
    }
}

if ($routerPort > 0) {
    $API->port = $routerPort;
}
$connected = $API->connect($routerHost, $server['PEMILIK'], $server['PASSWORD']);

if (!$connected) {
    echo json_encode(['success' => false, 'message' => 'Gagal konek ke server router (' . $routerHost . ':' . $routerPort . ').']);
    exit;
}

$interfaces = $API->comm('/interface/print');
$API->disconnect();

$result = [];
if (is_array($interfaces)) {
    foreach ($interfaces as $intf) {
        $name = isset($intf['name']) ? trim((string)$intf['name']) : '';
        if ($name === '') {
            continue;
        }

        // Skip dynamic interfaces
        if (!empty($intf['dynamic']) && ($intf['dynamic'] === 'true' || $intf['dynamic'] === 'yes')) {
            continue;
        }

        // Skip VLAN interfaces - hanya tampilkan physical (ether) saja
        $type = $intf['type'] ?? '';
        if (stripos($type, 'vlan') !== false || stripos($name, 'vlan') !== false) {
            continue;
        }

        // Hanya tampilkan ether/physical interfaces
        if (stripos($type, 'ether') !== false || $type === 'ether') {
            $result[] = [
                'name' => $name,
                'type' => $type,
                'status' => !empty($intf['running']) ? 'up' : 'down'
            ];
        }
    }
}

usort($result, function ($a, $b) {
    return strcasecmp($a['name'], $b['name']);
});

echo json_encode([
    'success' => true,
    'data' => $result,
    'server' => [
        'id' => (int)$server['id'],
        'ip' => $server['IP'],
        'area' => $server['AREA']
    ]
]);
