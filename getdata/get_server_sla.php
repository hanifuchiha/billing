<?php
ob_start();
require '../cek-sesi.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

function ensureSlaTable($conn)
{
    $sql = "CREATE TABLE IF NOT EXISTS server_sla_logs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        server_id INT NOT NULL,
        pemilik VARCHAR(150) NOT NULL,
        area VARCHAR(150) DEFAULT '',
        ip VARCHAR(100) NOT NULL,
        checked_slot DATETIME NOT NULL,
        checked_at DATETIME NOT NULL,
        is_online TINYINT(1) NOT NULL DEFAULT 0,
        response_ms INT UNSIGNED DEFAULT NULL,
        method VARCHAR(20) NOT NULL DEFAULT 'tcp',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_server_slot (server_id, checked_slot),
        KEY idx_checked_at (checked_at),
        KEY idx_owner (pemilik)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    return mysqli_query($conn, $sql) !== false;
}

function getServerScopeRows($conn)
{
    global $AKSES, $asistant_name, $area_list, $current_user_id, $ceknama;

    $sql = '';
    if (!empty($asistant_name) && strtoupper((string)$AKSES) === 'ASSISTANT' && !empty($area_list)) {
        $sql = "SELECT id, PEMILIK, AREA, IP FROM server WHERE AREA IN ($area_list)";
    } elseif (isset($current_user_id) && (int)$current_user_id > 0) {
        $uid = (int)$current_user_id;
        $sql = "SELECT id, PEMILIK, AREA, IP FROM server WHERE user_id = $uid";
    } else {
        $owner = mysqli_real_escape_string($conn, (string)$ceknama);
        $sql = "SELECT id, PEMILIK, AREA, IP FROM server WHERE PEMILIK = '$owner'";
    }

    $result = mysqli_query($conn, $sql);
    if (!$result) {
        return [false, []];
    }

    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    return [true, $rows];
}

function formatDurationMinutes($minutes)
{
    $totalMinutes = max(0, (int)$minutes);
    $days = (int)floor($totalMinutes / 1440);
    $remain = $totalMinutes % 1440;
    $hours = (int)floor($remain / 60);
    $mins = $remain % 60;

    if ($days > 0) {
        return $days . ' hari ' . $hours . ' jam';
    }
    if ($hours > 0) {
        return $hours . ' jam ' . $mins . ' menit';
    }
    return $mins . ' menit';
}

$mode = strtolower(trim((string)($_GET['mode'] ?? 'summary')));
if (!in_array($mode, ['summary', 'detail'], true)) {
    $mode = 'summary';
}

if (!ensureSlaTable($conn)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Gagal menyiapkan tabel SLA server',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

list($okScope, $serverRows) = getServerScopeRows($conn);
if (!$okScope) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Gagal membaca data server user',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($serverRows)) {
    echo json_encode([
        'success' => true,
        'mode' => $mode,
        'days' => 30,
        'total_sla_percent' => 0,
        'total_servers' => 0,
        'servers_with_data' => 0,
        'total_uptime_minutes' => 0,
        'total_uptime_human' => '0 menit',
        'last_check' => '',
        'data' => [],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$serverById = [];
$serverIds = [];
foreach ($serverRows as $srv) {
    $sid = (int)($srv['id'] ?? 0);
    if ($sid <= 0) {
        continue;
    }
    $serverIds[] = $sid;
    $serverById[$sid] = [
        'server_id' => $sid,
        'pemilik' => (string)($srv['PEMILIK'] ?? ''),
        'area' => (string)($srv['AREA'] ?? ''),
        'ip' => (string)($srv['IP'] ?? ''),
    ];
}

if (empty($serverIds)) {
    echo json_encode([
        'success' => true,
        'mode' => $mode,
        'days' => 30,
        'total_sla_percent' => 0,
        'total_servers' => 0,
        'servers_with_data' => 0,
        'total_uptime_minutes' => 0,
        'total_uptime_human' => '0 menit',
        'last_check' => '',
        'data' => [],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$idList = implode(',', array_map('intval', $serverIds));

$sqlAgg = "SELECT
            server_id,
            SUM(CASE WHEN is_online = 1 THEN 1 ELSE 0 END) AS up_count,
            COUNT(*) AS total_count,
            MAX(checked_at) AS last_check
          FROM server_sla_logs
          WHERE checked_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            AND server_id IN ($idList)
          GROUP BY server_id";
$resAgg = mysqli_query($conn, $sqlAgg);

$aggMap = [];
while ($resAgg && ($row = mysqli_fetch_assoc($resAgg))) {
    $sid = (int)$row['server_id'];
    $up = (int)($row['up_count'] ?? 0);
    $total = (int)($row['total_count'] ?? 0);
    $pct = $total > 0 ? round(($up / $total) * 100, 2) : 0;
    $aggMap[$sid] = [
        'up_count' => $up,
        'total_count' => $total,
        'sla_percent' => $pct,
        'last_check' => (string)($row['last_check'] ?? ''),
        'last_status' => 'UNKNOWN',
    ];
}

$sqlLast = "SELECT x.server_id, x.is_online
            FROM server_sla_logs x
            INNER JOIN (
                SELECT server_id, MAX(checked_at) AS max_checked
                FROM server_sla_logs
                WHERE checked_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                  AND server_id IN ($idList)
                GROUP BY server_id
            ) m ON m.server_id = x.server_id AND m.max_checked = x.checked_at";
$resLast = mysqli_query($conn, $sqlLast);
while ($resLast && ($row = mysqli_fetch_assoc($resLast))) {
    $sid = (int)$row['server_id'];
    if (!isset($aggMap[$sid])) {
        continue;
    }
    $aggMap[$sid]['last_status'] = ((int)$row['is_online'] === 1) ? 'ONLINE' : 'OFFLINE';
}

$sumPct = 0;
$serversWithData = 0;
$sumUptimeMinutes = 0;
$lastCheckGlobal = '';
$detail = [];

foreach ($serverById as $sid => $srv) {
    $base = [
        'server_id' => $sid,
        'pemilik' => $srv['pemilik'],
        'area' => $srv['area'],
        'ip' => $srv['ip'],
        'sla_percent' => 0,
        'up_count' => 0,
        'total_count' => 0,
        'uptime_minutes' => 0,
        'uptime_human' => '0 menit',
        'last_check' => '',
        'last_status' => 'UNKNOWN',
    ];

    if (isset($aggMap[$sid])) {
        $base['sla_percent'] = $aggMap[$sid]['sla_percent'];
        $base['up_count'] = $aggMap[$sid]['up_count'];
        $base['total_count'] = $aggMap[$sid]['total_count'];
        $base['uptime_minutes'] = $base['up_count'] * 30;
        $base['uptime_human'] = formatDurationMinutes($base['uptime_minutes']);
        $base['last_check'] = $aggMap[$sid]['last_check'];
        $base['last_status'] = $aggMap[$sid]['last_status'];
        $serversWithData++;
    }

    $sumPct += (float)$base['sla_percent'];
    $sumUptimeMinutes += (int)$base['uptime_minutes'];

    if (!empty($base['last_check'])) {
        if ($lastCheckGlobal === '' || strtotime($base['last_check']) > strtotime($lastCheckGlobal)) {
            $lastCheckGlobal = $base['last_check'];
        }
    }

    $detail[] = $base;
}

usort($detail, function ($a, $b) {
    return ($b['sla_percent'] <=> $a['sla_percent']);
});

$totalServers = count($serverById);
$totalSla = $totalServers > 0 ? round($sumPct / $totalServers, 2) : 0;

echo json_encode([
    'success' => true,
    'mode' => $mode,
    'days' => 30,
    'total_sla_percent' => $totalSla,
    'total_servers' => $totalServers,
    'servers_with_data' => $serversWithData,
    'total_uptime_minutes' => $sumUptimeMinutes,
    'total_uptime_human' => formatDurationMinutes($sumUptimeMinutes),
    'last_check' => $lastCheckGlobal,
    'data' => ($mode === 'detail') ? $detail : [],
], JSON_UNESCAPED_UNICODE);
exit;
