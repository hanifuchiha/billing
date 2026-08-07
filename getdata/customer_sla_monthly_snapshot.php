<?php
require_once __DIR__ . '/../koneksibilling.php';
require_once __DIR__ . '/customer_sla_common.php';

date_default_timezone_set('Asia/Jakarta');
header('Content-Type: text/plain; charset=utf-8');

if (!customerSlaEnsureTables($conn)) {
    http_response_code(500);
    echo "Gagal menyiapkan tabel SLA pelanggan.\n";
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

$sqlCustomerSnapshot = "INSERT INTO customer_sla_monthly_snapshots
    (snapshot_month, snapshot_date, idpel, pemilik, area, odp, total_sla_percent, total_checks, online_checks, total_uptime_minutes, last_check)
    SELECT
        '$snapshotMonth',
        '$snapshotDate',
        l.idpel,
        MAX(l.pemilik) AS pemilik,
        MAX(l.area) AS area,
        MAX(p.ODP) AS odp,
        ROUND((SUM(CASE WHEN l.is_online = 1 THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) AS total_sla_percent,
        COUNT(*) AS total_checks,
        SUM(CASE WHEN l.is_online = 1 THEN 1 ELSE 0 END) AS online_checks,
        SUM(CASE WHEN l.is_online = 1 THEN 30 ELSE 0 END) AS total_uptime_minutes,
        MAX(l.checked_at) AS last_check
    FROM customer_sla_logs l
    LEFT JOIN pelanggan p ON p.IDPEL = l.idpel
    WHERE l.checked_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY l.idpel
    ON DUPLICATE KEY UPDATE
        snapshot_date = VALUES(snapshot_date),
        pemilik = VALUES(pemilik),
        area = VALUES(area),
        odp = VALUES(odp),
        total_sla_percent = VALUES(total_sla_percent),
        total_checks = VALUES(total_checks),
        online_checks = VALUES(online_checks),
        total_uptime_minutes = VALUES(total_uptime_minutes),
        last_check = VALUES(last_check)";

$sqlOdpSnapshot = "INSERT INTO odp_sla_monthly_snapshots
    (snapshot_month, snapshot_date, odp, pemilik, area, total_sla_percent, total_customers, customers_with_data, total_uptime_minutes, last_check)
    SELECT
        '$snapshotMonth',
        '$snapshotDate',
        p.ODP AS odp,
        MAX(p.PEMILIK) AS pemilik,
        MAX(p.AREA) AS area,
        ROUND((SUM(CASE WHEN l.is_online = 1 THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) AS total_sla_percent,
        COUNT(DISTINCT l.idpel) AS total_customers,
        COUNT(DISTINCT l.idpel) AS customers_with_data,
        SUM(CASE WHEN l.is_online = 1 THEN 30 ELSE 0 END) AS total_uptime_minutes,
        MAX(l.checked_at) AS last_check
    FROM customer_sla_logs l
    INNER JOIN pelanggan p ON p.IDPEL = l.idpel
    WHERE l.checked_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
      AND COALESCE(p.ODP, '') <> ''
    GROUP BY p.ODP
    ON DUPLICATE KEY UPDATE
        snapshot_date = VALUES(snapshot_date),
        pemilik = VALUES(pemilik),
        area = VALUES(area),
        total_sla_percent = VALUES(total_sla_percent),
        total_customers = VALUES(total_customers),
        customers_with_data = VALUES(customers_with_data),
        total_uptime_minutes = VALUES(total_uptime_minutes),
        last_check = VALUES(last_check)";

$okCustomer = mysqli_query($conn, $sqlCustomerSnapshot);
$okOdp = mysqli_query($conn, $sqlOdpSnapshot);

if (!$okCustomer || !$okOdp) {
    http_response_code(500);
    echo "Gagal menyimpan snapshot SLA pelanggan: " . mysqli_error($conn) . "\n";
    exit;
}

echo "Snapshot SLA pelanggan selesai. Bulan: $snapshotMonth\n";
