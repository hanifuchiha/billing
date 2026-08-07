<?php
header('Content-Type: application/json; charset=utf-8');

session_start();

function buildPortalAcsSecret(): string
{
    $config_file = dirname(__DIR__) . '/config.json';
    if (!file_exists($config_file)) {
        return '';
    }
    $config = json_decode((string)file_get_contents($config_file), true);
    if (!is_array($config)) {
        return '';
    }
    return hash('sha256', (string)($config['db_pass'] ?? '') . '|' . (string)($config['domain'] ?? '') . '|portal-acs');
}

function isValidPortalAcsToken(string $idpel, string $token): bool
{
    $idpel = strtolower(trim($idpel));
    $token = trim($token);
    if ($idpel === '' || $token === '') {
        return false;
    }

    $secret = buildPortalAcsSecret();
    if ($secret === '') {
        return false;
    }

    $current = hash_hmac('sha256', $idpel . '|' . date('YmdH'), $secret);
    $prev = hash_hmac('sha256', $idpel . '|' . date('YmdH', time() - 3600), $secret);
    return hash_equals($current, $token) || hash_equals($prev, $token);
}

function normalizePhoneDigits(string $value): string
{
    return preg_replace('/\D+/', '', $value);
}

function normalizePhoneTo62(string $value): string
{
    $digits = normalizePhoneDigits($value);
    if ($digits === '') {
        return '';
    }
    if (substr($digits, 0, 2) === '62') {
        return $digits;
    }
    if (substr($digits, 0, 1) === '0') {
        return '62' . substr($digits, 1);
    }
    return '62' . $digits;
}

function resolvePortalCookieToIdpel(string $cookieValue): string
{
    $cookieValue = trim($cookieValue);
    if ($cookieValue === '') {
        return '';
    }

    $config_file = dirname(__DIR__) . '/config.json';
    if (!file_exists($config_file)) {
        return '';
    }
    $config = json_decode((string)file_get_contents($config_file), true);
    if (!is_array($config)) {
        return '';
    }

    $conn = @mysqli_connect(
        $config['db_host'] ?? 'localhost',
        $config['db_user'] ?? 'root',
        $config['db_pass'] ?? '',
        $config['db_name'] ?? ''
    );
    if (!$conn) {
        return '';
    }
    mysqli_set_charset($conn, 'utf8mb4');

    $wa62 = normalizePhoneTo62($cookieValue);
    $wa0 = ($wa62 !== '' && substr($wa62, 0, 2) === '62') ? ('0' . substr($wa62, 2)) : '';
    $rawDigits = normalizePhoneDigits($cookieValue);

    $sql = "SELECT IDPEL FROM pelanggan
            WHERE IDPEL = ?
               OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(NOWA,'+',''),' ',''),'-',''),'(',''),')','') IN (?,?,?)
            LIMIT 1";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        mysqli_close($conn);
        return '';
    }

    mysqli_stmt_bind_param($stmt, 'ssss', $cookieValue, $wa62, $wa0, $rawDigits);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $dbIdpel);
    $resolved = mysqli_stmt_fetch($stmt) ? (string)$dbIdpel : '';
    mysqli_stmt_close($stmt);
    mysqli_close($conn);

    return trim($resolved);
}

function cacheDeviceBelongsToIdpel(int $serverId, string $serialRaw, string $idpel): bool
{
    $cache_file = dirname(__DIR__) . '/notifdata/acs_devices_cache.json';
    if (!file_exists($cache_file)) {
        return false;
    }
    $cache_data = json_decode((string)file_get_contents($cache_file), true);
    if (!is_array($cache_data) || !isset($cache_data['devices']) || !is_array($cache_data['devices'])) {
        return false;
    }

    $idpelLower = strtolower(trim($idpel));
    $serialLower = strtolower(trim($serialRaw));

    foreach ($cache_data['devices'] as $device) {
        if ((int)($device['server_id'] ?? 0) !== $serverId) {
            continue;
        }
        if (strtolower((string)($device['serial'] ?? '')) !== $serialLower) {
            continue;
        }

        $user1 = strtolower((string)($device['pppoe_username'] ?? ''));
        $user2 = strtolower((string)($device['pppoe_username2'] ?? ''));

        if ($user1 !== '' && (($user1 === $idpelLower)
            || (strlen($user1) > 3 && strpos($user1, $idpelLower) !== false)
            || (strlen($idpelLower) > 3 && strpos($idpelLower, $user1) !== false))) {
            return true;
        }

        if ($user2 !== '' && (($user2 === $idpelLower)
            || (strlen($user2) > 3 && strpos($user2, $idpelLower) !== false)
            || (strlen($idpelLower) > 3 && strpos($idpelLower, $user2) !== false))) {
            return true;
        }

        return false;
    }

    return false;
}

function findCachedServerForDevice(int $serverId, string $serialRaw, string $idpel = ''): array
{
    $cache_file = dirname(__DIR__) . '/notifdata/acs_devices_cache.json';
    if (!file_exists($cache_file)) {
        return [];
    }

    $cache_data = json_decode((string)file_get_contents($cache_file), true);
    if (!is_array($cache_data) || !isset($cache_data['devices']) || !is_array($cache_data['devices'])) {
        return [];
    }

    $serialLower = strtolower(trim($serialRaw));
    $idpelLower = strtolower(trim($idpel));
    $fallbackMatch = [];

    foreach ($cache_data['devices'] as $device) {
        $deviceSerial = strtolower(trim((string)($device['serial'] ?? '')));
        if ($deviceSerial === '' || $deviceSerial !== $serialLower) {
            continue;
        }

        $candidate = [
            'server_id' => (int)($device['server_id'] ?? 0),
            'server_name' => trim((string)($device['server_name'] ?? '')),
        ];

        if ($candidate['server_id'] === $serverId && $candidate['server_id'] > 0) {
            return $candidate;
        }

        if ($idpelLower !== '') {
            $user1 = strtolower((string)($device['pppoe_username'] ?? ''));
            $user2 = strtolower((string)($device['pppoe_username2'] ?? ''));
            $matchesIdpel = ($user1 !== '' && (($user1 === $idpelLower)
                || (strlen($user1) > 3 && strpos($user1, $idpelLower) !== false)
                || (strlen($idpelLower) > 3 && strpos($idpelLower, $user1) !== false)))
                || ($user2 !== '' && (($user2 === $idpelLower)
                || (strlen($user2) > 3 && strpos($user2, $idpelLower) !== false)
                || (strlen($idpelLower) > 3 && strpos($idpelLower, $user2) !== false)));

            if ($matchesIdpel) {
                return $candidate;
            }
        }

        if (empty($fallbackMatch)) {
            $fallbackMatch = $candidate;
        }
    }

    return $fallbackMatch;
}

$has_admin_session = !empty($_SESSION['id']) || !empty($_SESSION['PEMILIK']);
$portal_cookie = trim((string)($_COOKIE['idselect'] ?? ''));

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

$server_id  = (int)($data['server_id'] ?? 0);
$serial_raw = trim((string)($data['serial_raw'] ?? ''));
$idpel = trim((string)($data['idpel'] ?? ''));
$portal_token = trim((string)($data['acs_token'] ?? ''));
$portal_token_valid = isValidPortalAcsToken($idpel, $portal_token);

if (!$has_admin_session && $portal_cookie === '' && !$portal_token_valid) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($server_id <= 0 || $serial_raw === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Payload tidak lengkap']);
    exit;
}

if (!$has_admin_session) {
    if ($portal_cookie !== '') {
        if ($idpel === '' || !preg_match('/^[a-zA-Z0-9_\-\.@]+$/', $idpel)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'ID pelanggan tidak valid untuk akses portal']);
            exit;
        }

        $resolved_idpel = resolvePortalCookieToIdpel($portal_cookie);
        if ($resolved_idpel === '' || strtolower($resolved_idpel) !== strtolower($idpel)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Akses reboot WiFi tidak diizinkan']);
            exit;
        }

        if (!cacheDeviceBelongsToIdpel($server_id, $serial_raw, $idpel)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Perangkat tidak terkait dengan pelanggan ini']);
            exit;
        }
    } else {
        if ($idpel === '' || !preg_match('/^[a-zA-Z0-9_\-\.@]+$/', $idpel) || !$portal_token_valid) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Akses reboot WiFi tidak diizinkan']);
            exit;
        }

        if (!cacheDeviceBelongsToIdpel($server_id, $serial_raw, $idpel)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Perangkat tidak terkait dengan pelanggan ini']);
            exit;
        }
    }
}

$config_file = dirname(__DIR__) . '/config.json';
if (!file_exists($config_file)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'config.json not found']);
    exit;
}

$config = json_decode((string)file_get_contents($config_file), true);
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

if (!$server) {
    $cachedServer = findCachedServerForDevice($server_id, $serial_raw, $idpel);
    $resolvedServerId = (int)($cachedServer['server_id'] ?? 0);
    $resolvedServerName = trim((string)($cachedServer['server_name'] ?? ''));

    if ($resolvedServerId > 0 && $resolvedServerId !== $server_id) {
        $stmt = mysqli_prepare($conn, 'SELECT id, nama_server, domain, port, username_acs, password_acs FROM acs_servers WHERE id = ? LIMIT 1');
        mysqli_stmt_bind_param($stmt, 'i', $resolvedServerId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $server = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);
    }

    if (!$server && $resolvedServerName !== '') {
        $stmt = mysqli_prepare($conn, 'SELECT id, nama_server, domain, port, username_acs, password_acs FROM acs_servers WHERE nama_server = ? ORDER BY id DESC LIMIT 1');
        mysqli_stmt_bind_param($stmt, 's', $resolvedServerName);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $server = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);
    }
}
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
    'name' => 'reboot'
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
        'message' => 'Gagal kirim task reboot ke ACS',
        'http_code' => $http_code,
        'error' => $curl_err,
        'response' => $response,
    ]);
    exit;
}

$acs_task = @json_decode((string)$response, true);
$task_id = is_array($acs_task) ? ($acs_task['_id'] ?? ($acs_task['id'] ?? null)) : null;

try {
    $log_dir = dirname(__DIR__) . '/notifdata';
    if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0775, true);
    }
    $log_file = $log_dir . '/acs_activity_log.json';
    $log_entry = [
        'time'        => date('Y-m-d H:i:s'),
        'server_id'   => $server_id,
        'server_name' => $server['nama_server'] ?? ('Server #' . $server_id),
        'serial'      => $serial_raw,
        'params'      => ['DEVICE_REBOOT'],
        'actor'       => $has_admin_session ? (string)($_SESSION['PEMILIK'] ?? 'admin') : $idpel,
        'task_id'     => (string)($task_id ?? ''),
    ];
    $log_data = ['logs' => []];
    if (file_exists($log_file)) {
        $decoded = json_decode((string)@file_get_contents($log_file), true);
        if (is_array($decoded) && isset($decoded['logs'])) {
            $log_data['logs'] = $decoded['logs'];
        }
    }
    array_unshift($log_data['logs'], $log_entry);
    $log_data['logs'] = array_slice($log_data['logs'], 0, 500);
    @file_put_contents($log_file, json_encode($log_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
} catch (Throwable $e) {
    // Non-fatal, ignore.
}

echo json_encode([
    'success' => true,
    'message' => 'Task reboot perangkat berhasil dikirim ke ACS',
    'server' => $server['nama_server'] ?? ('ID ' . $server_id),
    'task_url' => $task_url,
    'connection_request' => true,
    'serial' => $serial_raw,
    'task_id' => $task_id,
    'acs_response' => $acs_task,
]);
