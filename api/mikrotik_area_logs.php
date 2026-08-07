<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../koneksibilling.php';
require_once '../routeros_api.class.php';

function json_error($message, $code = 400)
{
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'error' => $message
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function authenticate_user($conn, $username, $password)
{
    $stmt = $conn->prepare("SELECT id, USERNAME, PASWORD, STATUS, grup FROM user WHERE USERNAME = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('s', $username);
    $stmt->execute();
    $result = $stmt->get_result();
    if (!$result || $result->num_rows === 0) {
        return null;
    }

    $row = $result->fetch_assoc();
    $stored = (string)($row['PASWORD'] ?? '');
    $ok = password_verify($password, $stored) || $password === $stored;
    if (!$ok) {
        return null;
    }

    return $row;
}

function should_store_mikrotik_log($message)
{
    $message = strtolower(trim((string)$message));
    if ($message === '') {
        return false;
    }

    $is_pppoe_or_hotspot = strpos($message, 'pppoe') !== false || strpos($message, 'hotspot') !== false;
    $is_ignored_noise = strpos($message, 'pppoe connection established from') !== false;
    $is_error = preg_match('/error|failed|failure|invalid|denied|critical|fatal|timeout|refused|unreachable|down/', $message) === 1;

    if ($is_ignored_noise) {
        return false;
    }

    return $is_pppoe_or_hotspot || $is_error;
}

function classify_mikrotik_log($message)
{
    $text = strtolower(trim((string)$message));
    $is_error = preg_match('/error|failed|failure|invalid|denied|critical|fatal|timeout|refused|unreachable|down/', $text) === 1;

    $service = '';
    if (strpos($text, 'pppoe') !== false) {
        $service = 'PPPoE';
    } elseif (strpos($text, 'hotspot') !== false) {
        $service = 'Hotspot';
    } elseif ($is_error) {
        $service = 'MikroTik';
    }

    $status = 'other';
    if ($is_error) {
        $status = 'error';
    } elseif (
        strpos($text, 'logged in') !== false ||
        strpos($text, 'log in') !== false ||
        strpos($text, 'connected') !== false ||
        strpos($text, 'authorized') !== false ||
        strpos($text, 'bound') !== false
    ) {
        $status = 'online';
    } elseif (
        strpos($text, 'logged out') !== false ||
        strpos($text, 'log out') !== false ||
        strpos($text, 'disconnected') !== false ||
        strpos($text, 'terminated') !== false ||
        strpos($text, 'timeout') !== false ||
        strpos($text, 'lost') !== false ||
        strpos($text, 'down') !== false
    ) {
        $status = 'offline';
    }

    return [
        'service' => $service,
        'status' => $status,
        'is_error' => $is_error
    ];
}

function load_server_list($conn, $user)
{
    $userId = (int)($user['id'] ?? 0);
    $username = (string)($user['USERNAME'] ?? '');
    $status = strtoupper(trim((string)($user['STATUS'] ?? '')));
    $grup = trim((string)($user['grup'] ?? ''));

    $queries = [];

    if ($status === 'ASSISTANT' && $grup !== '' && ctype_digit($grup)) {
        $ownerStmt = $conn->prepare("SELECT USERNAME FROM user WHERE id = ? LIMIT 1");
        if ($ownerStmt) {
            $ownerId = (int)$grup;
            $ownerStmt->bind_param('i', $ownerId);
            $ownerStmt->execute();
            $ownerRes = $ownerStmt->get_result();
            if ($ownerRes && $ownerRes->num_rows > 0) {
                $owner = $ownerRes->fetch_assoc()['USERNAME'];
                $queries[] = [
                    'sql' => "SELECT IP, PEMILIK, PASSWORD, AREA FROM server WHERE UPPER(PEMILIK) = UPPER(?)",
                    'types' => 's',
                    'params' => [$owner]
                ];
            }
        }
    }

    $queries[] = [
        'sql' => "SELECT IP, PEMILIK, PASSWORD, AREA FROM server WHERE user_id = ?",
        'types' => 'i',
        'params' => [$userId]
    ];
    $queries[] = [
        'sql' => "SELECT IP, PEMILIK, PASSWORD, AREA FROM server WHERE UPPER(PEMILIK) = UPPER(?)",
        'types' => 's',
        'params' => [$username]
    ];

    $dedup = [];
    $servers = [];

    foreach ($queries as $q) {
        $stmt = $conn->prepare($q['sql']);
        if (!$stmt) {
            continue;
        }

        $stmt->bind_param($q['types'], ...$q['params']);
        $stmt->execute();
        $res = $stmt->get_result();
        if (!$res) {
            continue;
        }

        while ($row = $res->fetch_assoc()) {
            $ip = trim((string)($row['IP'] ?? ''));
            $owner = trim((string)($row['PEMILIK'] ?? ''));
            if ($ip === '' || $owner === '') {
                continue;
            }

            $key = strtoupper($ip . '|' . $owner);
            if (isset($dedup[$key])) {
                continue;
            }

            $dedup[$key] = true;
            $servers[] = $row;
        }
    }

    return $servers;
}

$username = trim((string)($_GET['username'] ?? ''));
$password = (string)($_GET['password'] ?? '');

if ($username === '' || $password === '') {
    json_error('Username dan password harus diisi', 401);
}

$refreshSeconds = isset($_GET['seconds']) ? (int)$_GET['seconds'] : 10;
if ($refreshSeconds < 5) {
    $refreshSeconds = 5;
}
if ($refreshSeconds > 60) {
    $refreshSeconds = 60;
}

$user = authenticate_user($conn, $username, $password);
if (!$user) {
    json_error('Autentikasi gagal', 401);
}

$servers = load_server_list($conn, $user);
if (empty($servers)) {
    echo json_encode([
        'success' => true,
        'source' => 'mikrotik-api',
        'generated_at' => date('Y-m-d H:i:s'),
        'refresh_seconds' => $refreshSeconds,
        'grouped' => new stdClass(),
        'meta' => [
            'servers_total' => 0,
            'servers_connected' => 0,
            'items_total' => 0
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$API = new RouterosAPI();
$API->debug = false;

$grouped = [];
$serversConnected = 0;
$itemsTotal = 0;

foreach ($servers as $server) {
    $ip = trim((string)($server['IP'] ?? ''));
    $owner = trim((string)($server['PEMILIK'] ?? ''));
    $passwordServer = (string)($server['PASSWORD'] ?? '');
    $area = trim((string)($server['AREA'] ?? ''));
    if ($area === '') {
        $area = '-';
    }

    if (!$API->connect($ip, $owner, $passwordServer)) {
        continue;
    }

    $serversConnected++;
    $rawLogs = $API->comm('/log/print');
    $API->disconnect();

    if (!is_array($rawLogs) || empty($rawLogs)) {
        continue;
    }

    $rawLogs = array_slice($rawLogs, -120);

    foreach ($rawLogs as $log) {
        $message = (string)($log['message'] ?? '');
        if (!should_store_mikrotik_log($message)) {
            continue;
        }

        $classified = classify_mikrotik_log($message);
        if ($classified['service'] === '') {
            continue;
        }

        $tanggal = trim((string)($log['date'] ?? ''));
        if ($tanggal === '') {
            $tanggal = date('Y-m-d');
        }
        $waktu = trim((string)($log['time'] ?? ''));

        if (!isset($grouped[$area])) {
            $grouped[$area] = [
                'area' => $area,
                'online' => 0,
                'offline' => 0,
                'error' => 0,
                'other' => 0,
                'owners' => [],
                'items' => []
            ];
        }

        if ($classified['status'] === 'online') {
            $grouped[$area]['online']++;
        } elseif ($classified['status'] === 'offline') {
            $grouped[$area]['offline']++;
        } elseif ($classified['status'] === 'error') {
            $grouped[$area]['error']++;
        } else {
            $grouped[$area]['other']++;
        }

        $grouped[$area]['owners'][$owner] = true;

        if (count($grouped[$area]['items']) < 8) {
            $grouped[$area]['items'][] = [
                'server' => $owner,
                'area' => $area,
                'message' => $message,
                'tanggal' => $tanggal,
                'waktu' => $waktu,
                'service' => $classified['service'],
                'status' => $classified['status'],
                'isError' => $classified['is_error']
            ];
        }

        $itemsTotal++;
    }
}

foreach ($grouped as $areaName => $row) {
    $grouped[$areaName]['ownerCount'] = count($row['owners']);
    unset($grouped[$areaName]['owners']);
}

echo json_encode([
    'success' => true,
    'source' => 'mikrotik-api',
    'generated_at' => date('Y-m-d H:i:s'),
    'refresh_seconds' => $refreshSeconds,
    'grouped' => $grouped,
    'meta' => [
        'servers_total' => count($servers),
        'servers_connected' => $serversConnected,
        'items_total' => $itemsTotal
    ]
], JSON_UNESCAPED_UNICODE);
