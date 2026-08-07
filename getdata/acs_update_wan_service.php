<?php
header('Content-Type: application/json; charset=utf-8');

session_start();
if (empty($_SESSION['id']) && empty($_SESSION['PEMILIK'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON payload']);
    exit;
}

$server_id = (int)($data['server_id'] ?? 0);
$serial_raw = trim((string)($data['serial_raw'] ?? ''));
$service_value = trim((string)($data['service_value'] ?? ''));
$param_path = trim((string)($data['param_path'] ?? ''));

if ($server_id <= 0 || $serial_raw === '' || $service_value === '' || $param_path === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Payload tidak lengkap']);
    exit;
}

if (strlen($service_value) > 128) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Nilai WAN ServiceList maksimal 128 karakter']);
    exit;
}

if (!preg_match('/^(InternetGatewayDevice|Device)\./', $param_path)
    || stripos($param_path, 'WANPPPConnection') === false
    || stripos($param_path, 'X_CT-COM_ServiceList') === false) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Parameter WAN ServiceList tidak valid']);
    exit;
}

$config_file = dirname(__DIR__) . '/config.json';
if (!file_exists($config_file)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'config.json not found']);
    exit;
}

$config = json_decode(file_get_contents($config_file), true);
if (!is_array($config)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'config.json invalid']);
    exit;
}

$conn = @mysqli_connect(
    $config['db_host'] ?? 'localhost',
    $config['db_user'] ?? 'root',
    $config['db_pass'] ?? '',
    $config['db_name'] ?? ''
);
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB connection failed']);
    exit;
}
mysqli_set_charset($conn, 'utf8mb4');

$stmt = mysqli_prepare($conn, 'SELECT id, nama_server, domain, port, username_acs, password_acs FROM acs_servers WHERE id = ? LIMIT 1');
mysqli_stmt_bind_param($stmt, 'i', $server_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$server = $res ? mysqli_fetch_assoc($res) : null;
mysqli_stmt_close($stmt);
mysqli_close($conn);

if (!$server) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Server ACS tidak ditemukan']);
    exit;
}

$domain = trim((string)($server['domain'] ?? ''));
$port = (int)($server['port'] ?? 0);
$nbi_port = $port + 2;
if ($domain === '' || $nbi_port <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Konfigurasi server ACS tidak valid']);
    exit;
}

$task_url = 'http://' . $domain . ':' . $nbi_port . '/devices/' . rawurlencode($serial_raw) . '/tasks?connection_request';
$payload = [
    'name' => 'setParameterValues',
    'parameterValues' => [
        [$param_path, $service_value, 'xsd:string']
    ]
];

$ch = curl_init($task_url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_MAXREDIRS => 0,
]);

$username = trim((string)($server['username_acs'] ?? ''));
$password = trim((string)($server['password_acs'] ?? ''));
if ($username !== '' || $password !== '') {
    curl_setopt($ch, CURLOPT_USERPWD, $username . ':' . $password);
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
}

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_err = curl_error($ch);
curl_close($ch);

if ($http_code < 200 || $http_code >= 300) {
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'message' => 'Gagal kirim task WAN ServiceList ke ACS',
        'http_code' => $http_code,
        'error' => $curl_err,
        'response' => $response,
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'Task update WAN ServiceList berhasil dikirim ke ACS',
    'server' => $server['nama_server'] ?? ('ID ' . $server_id),
    'serial' => $serial_raw,
    'param_path' => $param_path,
]);
