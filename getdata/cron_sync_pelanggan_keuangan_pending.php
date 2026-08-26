<?php

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
@set_time_limit(120);
@ini_set('display_errors', '0');

require __DIR__ . '/../koneksibilling.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Koneksi database Billing tidak tersedia.']);
    exit;
}

function pendingEnsureColumn(mysqli $conn, string $column, string $definition): void
{
    $columnSafe = mysqli_real_escape_string($conn, $column);
    $exists = mysqli_query($conn, "SHOW COLUMNS FROM pelanggan LIKE '$columnSafe'");
    if ($exists && mysqli_num_rows($exists) === 0) {
        mysqli_query($conn, "ALTER TABLE pelanggan ADD COLUMN `$columnSafe` $definition");
    }
}

pendingEnsureColumn($conn, 'KEUANGAN_ID', 'INT NULL DEFAULT NULL');
pendingEnsureColumn($conn, 'KEUANGAN_SYNC_STATUS', "VARCHAR(20) NOT NULL DEFAULT 'pending'");
pendingEnsureColumn($conn, 'KEUANGAN_SYNC_ERROR', 'TEXT NULL DEFAULT NULL');
pendingEnsureColumn($conn, 'KEUANGAN_SYNC_ATTEMPTS', 'INT NOT NULL DEFAULT 0');
pendingEnsureColumn($conn, 'KEUANGAN_SYNC_LAST_ATTEMPT', 'DATETIME NULL DEFAULT NULL');
$limit = max(1, min(5, (int)($_GET['limit'] ?? 5)));
$sql = "SELECT IDPEL
        FROM pelanggan
        WHERE TRIM(COALESCE(IDPEL, '')) <> ''
          AND COALESCE(KEUANGAN_ID, 0) = 0
          AND COALESCE(KEUANGAN_SYNC_STATUS, 'pending') IN ('pending', 'failed')
          AND (
              KEUANGAN_SYNC_LAST_ATTEMPT IS NULL
              OR KEUANGAN_SYNC_LAST_ATTEMPT <= DATE_SUB(NOW(), INTERVAL
                  CASE
                      WHEN COALESCE(KEUANGAN_SYNC_ATTEMPTS, 0) <= 1 THEN 1
                      WHEN KEUANGAN_SYNC_ATTEMPTS = 2 THEN 5
                      WHEN KEUANGAN_SYNC_ATTEMPTS = 3 THEN 15
                      ELSE 30
                  END MINUTE)
          )
        ORDER BY COALESCE(KEUANGAN_SYNC_LAST_ATTEMPT, '1970-01-01') ASC, id ASC
        LIMIT " . $limit;
$result = mysqli_query($conn, $sql);
if (!$result) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => mysqli_error($conn)]);
    exit;
}

$idpels = [];
while ($row = mysqli_fetch_assoc($result)) {
    $idpel = trim((string)($row['IDPEL'] ?? ''));
    if ($idpel !== '') {
        $idpels[] = $idpel;
    }
}

$summary = ['ok' => true, 'checked' => count($idpels), 'synced' => 0, 'failed' => 0, 'locked' => 0];
foreach ($idpels as $idpel) {
    $url = 'http://127.0.0.1/keuangan/billing/getdata/api_hanif_cron_pelanggan.php'
        . '?src=pending_retry&only=' . rawurlencode($idpel);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    $raw = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $payload = is_string($raw) ? json_decode($raw, true) : null;
    if ($httpCode === 423) {
        $summary['locked']++;
        break;
    }
    if ($httpCode === 200 && is_array($payload) && !empty($payload['ok']) && (int)($payload['customers_failed'] ?? 0) === 0) {
        $summary['synced']++;
    } else {
        $summary['failed']++;
    }
}

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
