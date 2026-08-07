<?php
require '../koneksibilling.php';

date_default_timezone_set('Asia/Jakarta');
header('Content-Type: text/plain; charset=utf-8');

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
        method VARCHAR(20) NOT NULL DEFAULT 'telnet',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_server_slot (server_id, checked_slot),
        KEY idx_checked_at (checked_at),
        KEY idx_owner (pemilik)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    return mysqli_query($conn, $sql) !== false;
}

function floorTo30Minutes($timestamp)
{
    $minute = (int)date('i', $timestamp);
    $flooredMinute = $minute < 30 ? 0 : 30;
    return date('Y-m-d H:', $timestamp) . sprintf('%02d:00', $flooredMinute);
}

function telnetCheckServer($host, $timeoutSeconds = 2)
{
    $start = microtime(true);
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($host, 23, $errno, $errstr, $timeoutSeconds);
    $elapsedMs = (int)round((microtime(true) - $start) * 1000);

    if ($fp) {
        fclose($fp);
        return [true, max(1, $elapsedMs)];
    }

    return [false, max(1, $elapsedMs)];
}

if (!ensureSlaTable($conn)) {
    http_response_code(500);
    echo "Gagal membuat table server_sla_logs: " . mysqli_error($conn) . "\n";
    exit;
}

$nowTs = time();
$checkedSlot = floorTo30Minutes($nowTs);
$checkedAt = date('Y-m-d H:i:s', $nowTs);

$qServers = mysqli_query($conn, "SELECT id, PEMILIK, AREA, IP FROM server WHERE COALESCE(IP,'') <> ''");
if (!$qServers) {
    http_response_code(500);
    echo "Gagal mengambil server: " . mysqli_error($conn) . "\n";
    exit;
}

$total = 0;
$online = 0;
$offline = 0;

while ($srv = mysqli_fetch_assoc($qServers)) {
    $serverId = (int)($srv['id'] ?? 0);
    $pemilik = trim((string)($srv['PEMILIK'] ?? ''));
    $area = trim((string)($srv['AREA'] ?? ''));
    $ip = trim((string)($srv['IP'] ?? ''));

    if ($serverId <= 0 || $ip === '') {
        continue;
    }

    $total++;
    list($isOnline, $responseMs) = telnetCheckServer($ip, 2);
    $method = 'telnet';
    if ($isOnline) {
        $online++;
    } else {
        $offline++;
    }

    $isOnlineInt = $isOnline ? 1 : 0;
    $serverIdEsc = (int)$serverId;
    $pemilikEsc = mysqli_real_escape_string($conn, $pemilik);
    $areaEsc = mysqli_real_escape_string($conn, $area);
    $ipEsc = mysqli_real_escape_string($conn, $ip);
    $slotEsc = mysqli_real_escape_string($conn, $checkedSlot);
    $atEsc = mysqli_real_escape_string($conn, $checkedAt);
    $respEsc = (int)$responseMs;

    $methodEsc = mysqli_real_escape_string($conn, $method);

    $sqlInsert = "INSERT INTO server_sla_logs
        (server_id, pemilik, area, ip, checked_slot, checked_at, is_online, response_ms, method)
        VALUES
        ($serverIdEsc, '$pemilikEsc', '$areaEsc', '$ipEsc', '$slotEsc', '$atEsc', $isOnlineInt, $respEsc, '$methodEsc')
        ON DUPLICATE KEY UPDATE
            checked_at = VALUES(checked_at),
            is_online = VALUES(is_online),
            response_ms = VALUES(response_ms),
            pemilik = VALUES(pemilik),
            area = VALUES(area),
            ip = VALUES(ip)";

    mysqli_query($conn, $sqlInsert);
}

mysqli_query($conn, "DELETE FROM server_sla_logs WHERE checked_at < DATE_SUB(NOW(), INTERVAL 45 DAY)");

echo "SLA logger selesai. Total: $total, Online: $online, Offline: $offline, Slot: $checkedSlot\n";
