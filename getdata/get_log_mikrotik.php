<?php
include '../cek-sesi.php';
require '../routeros_api.class.php';

function should_store_mikrotik_log($message) {
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

function classify_mikrotik_log($message) {
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

function server_query_for_current_user($conn, $current_user_id, $ceknama, $asistant_name, $AKSES) {
    $where = '';

    if (isset($current_user_id) && is_numeric($current_user_id)) {
        $where = ' WHERE user_id = ' . (int)$current_user_id;
    } elseif (!empty($asistant_name) && strtoupper((string)$AKSES) === 'ASSISTANT') {
        $safe_owner = mysqli_real_escape_string($conn, $asistant_name);
        $where = " WHERE PEMILIK = '$safe_owner'";
    } elseif (!empty($ceknama)) {
        $safe_owner = mysqli_real_escape_string($conn, $ceknama);
        $where = " WHERE PEMILIK = '$safe_owner'";
    }

    return "SELECT IP, PEMILIK, PASSWORD, AREA FROM server" . $where;
}

$mode = isset($_GET['mode']) ? strtolower(trim((string)$_GET['mode'])) : 'html';

if ($mode === 'json') {
    header('Content-Type: application/json; charset=utf-8');

    $refresh_seconds = isset($_GET['seconds']) ? (int)$_GET['seconds'] : 10;
    if ($refresh_seconds < 5) {
        $refresh_seconds = 5;
    }
    if ($refresh_seconds > 60) {
        $refresh_seconds = 60;
    }

    $sql = server_query_for_current_user(
        $conn,
        isset($current_user_id) ? $current_user_id : null,
        isset($ceknama) ? $ceknama : '',
        isset($asistant_name) ? $asistant_name : '',
        isset($AKSES) ? $AKSES : ''
    );

    $result = mysqli_query($conn, $sql);
    if (!$result) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Gagal mengambil daftar server.',
            'error' => mysqli_error($conn)
        ]);
        exit;
    }

    $servers = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $servers[] = $row;
    }

    if (empty($servers)) {
        echo json_encode([
            'success' => true,
            'source' => 'mikrotik-api',
            'generated_at' => date('Y-m-d H:i:s'),
            'refresh_seconds' => $refresh_seconds,
            'grouped' => new stdClass(),
            'meta' => [
                'servers_total' => 0,
                'servers_connected' => 0,
                'items_total' => 0
            ]
        ]);
        exit;
    }

    $API = new RouterosAPI();
    $API->debug = false;

    $grouped = [];
    $servers_connected = 0;
    $items_total = 0;

    foreach ($servers as $server) {
        $ip = isset($server['IP']) ? trim((string)$server['IP']) : '';
        $pemilik = isset($server['PEMILIK']) ? trim((string)$server['PEMILIK']) : '-';
        $password = isset($server['PASSWORD']) ? (string)$server['PASSWORD'] : '';
        $area = isset($server['AREA']) && trim((string)$server['AREA']) !== '' ? trim((string)$server['AREA']) : '-';

        if ($ip === '' || $pemilik === '') {
            continue;
        }

        if (!$API->connect($ip, $pemilik, $password)) {
            continue;
        }

        $servers_connected++;

        $raw_logs = $API->comm('/log/print');
        $API->disconnect();

        if (!is_array($raw_logs) || empty($raw_logs)) {
            continue;
        }

        // Ambil log paling baru saja agar request tetap ringan.
        $raw_logs = array_slice($raw_logs, -120);

        foreach ($raw_logs as $log) {
            $message = isset($log['message']) ? (string)$log['message'] : '';
            if (!should_store_mikrotik_log($message)) {
                continue;
            }

            $classified = classify_mikrotik_log($message);
            if ($classified['service'] === '') {
                continue;
            }

            $tanggal = isset($log['date']) && trim((string)$log['date']) !== ''
                ? trim((string)$log['date'])
                : date('Y-m-d');
            $waktu = isset($log['time']) ? trim((string)$log['time']) : '';

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

            $grouped[$area]['owners'][$pemilik] = true;

            if (count($grouped[$area]['items']) < 8) {
                $grouped[$area]['items'][] = [
                    'server' => $pemilik,
                    'area' => $area,
                    'message' => $message,
                    'tanggal' => $tanggal,
                    'waktu' => $waktu,
                    'service' => $classified['service'],
                    'status' => $classified['status'],
                    'isError' => $classified['is_error']
                ];
            }

            $items_total++;
        }
    }

    foreach ($grouped as $area_name => $row) {
        $grouped[$area_name]['ownerCount'] = count($row['owners']);
        unset($grouped[$area_name]['owners']);
    }

    echo json_encode([
        'success' => true,
        'source' => 'mikrotik-api',
        'generated_at' => date('Y-m-d H:i:s'),
        'refresh_seconds' => $refresh_seconds,
        'grouped' => $grouped,
        'meta' => [
            'servers_total' => count($servers),
            'servers_connected' => $servers_connected,
            'items_total' => $items_total
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Mode HTML (legacy) untuk debug manual
header('Content-Type: text/html; charset=utf-8');

$API = new RouterosAPI();
$sql = 'SELECT * FROM `server`';
$result = $conn->query($sql);

if (!$result || $result->num_rows === 0) {
    echo 'Tidak ada server terdaftar<br>';
    exit;
}

while ($row = $result->fetch_assoc()) {
    echo '<hr>';
    echo 'Server IP: ' . ($ip = $row['IP']) . '<br>';
    echo 'Pemilik: ' . ($pemilik = $row['PEMILIK']) . '<br>';
    echo 'Area: ' . ($area = $row['AREA']) . '<br>';
    echo 'Coba koneksi ke ' . $ip . '...<br>';

    if (!$API->connect($ip, $pemilik, $row['PASSWORD'])) {
        echo 'Gagal connect ke ' . $ip . '<br>';
        continue;
    }

    echo 'Berhasil connect ke ' . $ip . '<br>';

    $allLogs = $API->comm('/log/print');
    $API->disconnect();

    if (!is_array($allLogs)) {
        echo 'Tidak bisa ambil log dari ' . $ip . '<br>';
        continue;
    }

    echo 'Jumlah log total: ' . count($allLogs) . '<br>';
}

echo '<hr>';
echo 'Semua proses selesai';
exit;
