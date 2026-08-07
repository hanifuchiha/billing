<?php
require '../koneksibilling.php';

date_default_timezone_set('Asia/Jakarta');
header('Content-Type: text/plain; charset=utf-8');

function ensureSlaTables($conn)
{
    $sqlLog = "CREATE TABLE IF NOT EXISTS server_sla_logs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        server_id INT NOT NULL,
        pemilik VARCHAR(150) NOT NULL,
        area VARCHAR(150) DEFAULT '',
        ip VARCHAR(100) NOT NULL,
        checked_slot DATETIME NOT NULL,
        checked_at DATETIME NOT NULL,
        is_online TINYINT(1) NOT NULL DEFAULT 0,
        response_ms INT UNSIGNED DEFAULT NULL,
        method VARCHAR(20) NOT NULL DEFAULT 'telnet',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_server_slot (server_id, checked_slot),
        KEY idx_checked_at (checked_at),
        KEY idx_owner (pemilik)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $sqlMonthly = "CREATE TABLE IF NOT EXISTS server_sla_monthly_snapshots (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        snapshot_month CHAR(7) NOT NULL,
        snapshot_date DATE NOT NULL,
        pemilik VARCHAR(150) NOT NULL,
        total_sla_percent DECIMAL(6,2) NOT NULL DEFAULT 0.00,
        total_servers INT NOT NULL DEFAULT 0,
        servers_with_data INT NOT NULL DEFAULT 0,
        total_uptime_minutes INT NOT NULL DEFAULT 0,
        last_check DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_month_owner (snapshot_month, pemilik),
        KEY idx_snapshot_month (snapshot_month),
        KEY idx_pemilik (pemilik)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    return mysqli_query($conn, $sqlLog) !== false && mysqli_query($conn, $sqlMonthly) !== false;
}

if (!ensureSlaTables($conn)) {
    http_response_code(500);
    echo "Gagal menyiapkan tabel SLA: " . mysqli_error($conn) . "\n";
    exit;
}

$force = isset($_GET['force']) && $_GET['force'] === '1';
$today = date('Y-m-d');
$lastDay = date('Y-m-t');

if (!$force && $today !== $lastDay) {
    echo "Bukan hari terakhir bulan ini, snapshot dilewati. Hari ini: $today, akhir bulan: $lastDay\n";
    exit;
}

$snapshotMonth = date('Y-m');
$snapshotDate = date('Y-m-d');

$qOwners = mysqli_query($conn, "SELECT DISTINCT PEMILIK FROM server WHERE COALESCE(PEMILIK,'') <> '' ORDER BY PEMILIK ASC");
if (!$qOwners) {
    http_response_code(500);
    echo "Gagal mengambil owner server: " . mysqli_error($conn) . "\n";
    exit;
}

$totalOwners = 0;
$storedOwners = 0;

while ($ownerRow = mysqli_fetch_assoc($qOwners)) {
    $pemilik = trim((string)($ownerRow['PEMILIK'] ?? ''));
    if ($pemilik === '') {
        continue;
    }

    $totalOwners++;
    $pemilikEsc = mysqli_real_escape_string($conn, $pemilik);

    $qServers = mysqli_query($conn, "SELECT id FROM server WHERE PEMILIK = '$pemilikEsc'");
    $serverIds = [];
    while ($qServers && ($srv = mysqli_fetch_assoc($qServers))) {
        $sid = (int)($srv['id'] ?? 0);
        if ($sid > 0) {
            $serverIds[] = $sid;
        }
    }

    $totalServers = count($serverIds);
    $serversWithData = 0;
    $sumPct = 0.0;
    $sumUptimeMinutes = 0;
    $lastCheckGlobal = null;

    if ($totalServers > 0) {
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

        while ($resAgg && ($r = mysqli_fetch_assoc($resAgg))) {
            $sid = (int)$r['server_id'];
            $upCount = (int)($r['up_count'] ?? 0);
            $totalCount = (int)($r['total_count'] ?? 0);
            $slaPct = $totalCount > 0 ? round(($upCount / $totalCount) * 100, 2) : 0;
            $aggMap[$sid] = [
                'sla_percent' => $slaPct,
                'uptime_minutes' => $upCount * 30,
                'last_check' => $r['last_check'] ?? null,
            ];
        }

        foreach ($serverIds as $sid) {
            if (isset($aggMap[$sid])) {
                $serversWithData++;
                $sumPct += (float)$aggMap[$sid]['sla_percent'];
                $sumUptimeMinutes += (int)$aggMap[$sid]['uptime_minutes'];

                $lc = $aggMap[$sid]['last_check'];
                if (!empty($lc)) {
                    if ($lastCheckGlobal === null || strtotime($lc) > strtotime((string)$lastCheckGlobal)) {
                        $lastCheckGlobal = $lc;
                    }
                }
            }
        }
    }

    $totalSlaPercent = $totalServers > 0 ? round($sumPct / $totalServers, 2) : 0;

    $monthEsc = mysqli_real_escape_string($conn, $snapshotMonth);
    $dateEsc = mysqli_real_escape_string($conn, $snapshotDate);
    $lastCheckSql = $lastCheckGlobal ? "'" . mysqli_real_escape_string($conn, (string)$lastCheckGlobal) . "'" : "NULL";

    $sqlUpsert = "INSERT INTO server_sla_monthly_snapshots
        (snapshot_month, snapshot_date, pemilik, total_sla_percent, total_servers, servers_with_data, total_uptime_minutes, last_check)
        VALUES
        ('$monthEsc', '$dateEsc', '$pemilikEsc', $totalSlaPercent, $totalServers, $serversWithData, $sumUptimeMinutes, $lastCheckSql)
        ON DUPLICATE KEY UPDATE
            snapshot_date = VALUES(snapshot_date),
            total_sla_percent = VALUES(total_sla_percent),
            total_servers = VALUES(total_servers),
            servers_with_data = VALUES(servers_with_data),
            total_uptime_minutes = VALUES(total_uptime_minutes),
            last_check = VALUES(last_check)";

    if (mysqli_query($conn, $sqlUpsert)) {
        $storedOwners++;
    }
}

echo "Snapshot SLA bulanan selesai. Bulan: $snapshotMonth, total owner: $totalOwners, tersimpan: $storedOwners\n";
