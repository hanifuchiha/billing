<?php

function customerSlaEnsureTables(mysqli $conn): bool
{
    $sqlLog = "CREATE TABLE IF NOT EXISTS customer_sla_logs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        idpel VARCHAR(64) NOT NULL,
        pemilik VARCHAR(150) NOT NULL,
        area VARCHAR(150) DEFAULT '',
        checked_slot DATETIME NOT NULL,
        checked_at DATETIME NOT NULL,
        is_online TINYINT(1) NOT NULL DEFAULT 0,
        response_ms INT UNSIGNED DEFAULT NULL,
        method VARCHAR(30) NOT NULL DEFAULT 'routeros-ppp',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_idpel_slot (idpel, checked_slot),
        KEY idx_checked_at (checked_at),
        KEY idx_idpel (idpel),
        KEY idx_owner (pemilik),
        KEY idx_area (area)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $sqlCustomerMonthly = "CREATE TABLE IF NOT EXISTS customer_sla_monthly_snapshots (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        snapshot_month CHAR(7) NOT NULL,
        snapshot_date DATE NOT NULL,
        idpel VARCHAR(64) NOT NULL,
        pemilik VARCHAR(150) NOT NULL,
        area VARCHAR(150) DEFAULT '',
        odp VARCHAR(150) DEFAULT '',
        total_sla_percent DECIMAL(6,2) NOT NULL DEFAULT 0.00,
        total_checks INT NOT NULL DEFAULT 0,
        online_checks INT NOT NULL DEFAULT 0,
        total_uptime_minutes INT NOT NULL DEFAULT 0,
        last_check DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_month_idpel (snapshot_month, idpel),
        KEY idx_snapshot_month (snapshot_month),
        KEY idx_pemilik (pemilik),
        KEY idx_area (area),
        KEY idx_odp (odp),
        KEY idx_idpel (idpel)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $sqlOdpMonthly = "CREATE TABLE IF NOT EXISTS odp_sla_monthly_snapshots (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        snapshot_month CHAR(7) NOT NULL,
        snapshot_date DATE NOT NULL,
        odp VARCHAR(150) NOT NULL,
        pemilik VARCHAR(150) NOT NULL,
        area VARCHAR(150) DEFAULT '',
        total_sla_percent DECIMAL(6,2) NOT NULL DEFAULT 0.00,
        total_customers INT NOT NULL DEFAULT 0,
        customers_with_data INT NOT NULL DEFAULT 0,
        total_uptime_minutes INT NOT NULL DEFAULT 0,
        last_check DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_month_odp (snapshot_month, odp),
        KEY idx_snapshot_month (snapshot_month),
        KEY idx_pemilik (pemilik),
        KEY idx_area (area),
        KEY idx_odp (odp)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    return mysqli_query($conn, $sqlLog) !== false
        && mysqli_query($conn, $sqlCustomerMonthly) !== false
        && mysqli_query($conn, $sqlOdpMonthly) !== false;
}

function customerSlaFloorSlot(int $timestamp): string
{
    $minute = (int)date('i', $timestamp);
    $flooredMinute = $minute < 30 ? 0 : 30;

    return date('Y-m-d H:', $timestamp) . sprintf('%02d:00', $flooredMinute);
}

function customerSlaPercent(int $onlineChecks, int $totalChecks): float
{
    if ($totalChecks <= 0) {
        return 0.00;
    }

    return round(($onlineChecks / $totalChecks) * 100, 2);
}

function customerSlaBuildScopeClause(mysqli $conn, string $akses, int $currentUserId, string $areaList, string $alias = 'l'): string
{
    $akses = strtoupper(trim($akses));
    $alias = trim($alias) !== '' ? trim($alias) : 'l';

    if ($akses === 'ASSISTANT') {
        $areaList = trim($areaList);
        return $areaList !== '' ? "{$alias}.area IN ($areaList)" : '1=0';
    }

    $owners = [];
    $sqlOwners = "SELECT DISTINCT PEMILIK FROM server WHERE user_id = " . (int)$currentUserId . " AND COALESCE(PEMILIK,'') <> ''";
    $resOwners = mysqli_query($conn, $sqlOwners);
    while ($resOwners && ($row = mysqli_fetch_assoc($resOwners))) {
        $owner = trim((string)($row['PEMILIK'] ?? ''));
        if ($owner !== '') {
            $owners[] = "'" . mysqli_real_escape_string($conn, $owner) . "'";
        }
    }

    if (empty($owners)) {
        return '1=0';
    }

    return "{$alias}.pemilik IN (" . implode(',', $owners) . ")";
}

function customerSlaNormalizeText($value): string
{
    return trim((string)$value);
}
