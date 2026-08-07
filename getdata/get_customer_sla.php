<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../cek-sesi.php';
require_once __DIR__ . '/../koneksibilling.php';
require_once __DIR__ . '/customer_sla_common.php';

date_default_timezone_set('Asia/Jakarta');

if (!customerSlaEnsureTables($conn)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Gagal menyiapkan tabel SLA pelanggan.']);
    exit;
}

$scopeClause = customerSlaBuildScopeClause($conn, (string)($AKSES ?? ''), (int)($current_user_id ?? 0), (string)($area_list ?? ''), 'l');
$scopeClauseOdp = customerSlaBuildScopeClause($conn, (string)($AKSES ?? ''), (int)($current_user_id ?? 0), (string)($area_list ?? ''), 'p');

$customerMap = [];
$sqlCustomer = "SELECT
        l.idpel,
        MAX(l.pemilik) AS pemilik,
        MAX(l.area) AS area,
        SUM(CASE WHEN l.is_online = 1 THEN 1 ELSE 0 END) AS online_checks,
        COUNT(*) AS total_checks,
        MAX(l.checked_at) AS last_check
    FROM customer_sla_logs l
    WHERE l.checked_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
      AND $scopeClause
    GROUP BY l.idpel
    ORDER BY l.idpel ASC";
$resCustomer = mysqli_query($conn, $sqlCustomer);
while ($resCustomer && ($row = mysqli_fetch_assoc($resCustomer))) {
    $idpel = customerSlaNormalizeText($row['idpel'] ?? '');
    if ($idpel === '') {
        continue;
    }

    $onlineChecks = (int)($row['online_checks'] ?? 0);
    $totalChecks = (int)($row['total_checks'] ?? 0);
    $customerMap[$idpel] = [
        'idpel' => $idpel,
        'pemilik' => customerSlaNormalizeText($row['pemilik'] ?? ''),
        'area' => customerSlaNormalizeText($row['area'] ?? ''),
        'sla_percent' => customerSlaPercent($onlineChecks, $totalChecks),
        'online_checks' => $onlineChecks,
        'total_checks' => $totalChecks,
        'total_uptime_minutes' => $onlineChecks * 30,
        'last_check' => $row['last_check'] ?? null,
    ];
}

$odpMap = [];
$sqlOdp = "SELECT
        p.ODP AS odp,
        MAX(p.PEMILIK) AS pemilik,
        MAX(p.AREA) AS area,
        SUM(CASE WHEN l.is_online = 1 THEN 1 ELSE 0 END) AS online_checks,
        COUNT(*) AS total_checks,
        COUNT(DISTINCT l.idpel) AS customers_with_data,
        MAX(l.checked_at) AS last_check
    FROM customer_sla_logs l
    INNER JOIN pelanggan p ON p.IDPEL = l.idpel
    WHERE l.checked_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
      AND COALESCE(p.ODP, '') <> ''
      AND $scopeClauseOdp
    GROUP BY p.ODP
    ORDER BY p.ODP ASC";
$resOdp = mysqli_query($conn, $sqlOdp);
while ($resOdp && ($row = mysqli_fetch_assoc($resOdp))) {
    $odp = customerSlaNormalizeText($row['odp'] ?? '');
    if ($odp === '') {
        continue;
    }

    $onlineChecks = (int)($row['online_checks'] ?? 0);
    $totalChecks = (int)($row['total_checks'] ?? 0);
    $odpMap[$odp] = [
        'odp' => $odp,
        'pemilik' => customerSlaNormalizeText($row['pemilik'] ?? ''),
        'area' => customerSlaNormalizeText($row['area'] ?? ''),
        'sla_percent' => customerSlaPercent($onlineChecks, $totalChecks),
        'online_checks' => $onlineChecks,
        'total_checks' => $totalChecks,
        'customers_with_data' => (int)($row['customers_with_data'] ?? 0),
        'total_uptime_minutes' => $onlineChecks * 30,
        'last_check' => $row['last_check'] ?? null,
    ];
}

echo json_encode([
    'success' => true,
    'generated_at' => date('Y-m-d H:i:s'),
    'customers' => $customerMap,
    'odps' => $odpMap,
]);
