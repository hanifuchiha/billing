<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../cek-sesi.php';
require_once __DIR__ . '/../koneksibilling.php';
require_once __DIR__ . '/customer_sla_common.php';

date_default_timezone_set('Asia/Jakarta');

if (!customerSlaEnsureTables($conn)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Gagal menyiapkan tabel SLA pelanggan.', 'rows' => []]);
    exit;
}

$idpel = trim((string)($_GET['idpel'] ?? ''));
if ($idpel === '') {
    echo json_encode(['success' => false, 'message' => 'IDPEL wajib diisi.', 'rows' => []]);
    exit;
}

$idpelEsc = mysqli_real_escape_string($conn, $idpel);
$sql = "SELECT snapshot_month, total_sla_percent, total_checks, online_checks
        FROM customer_sla_monthly_snapshots
        WHERE idpel = '$idpelEsc'
        ORDER BY snapshot_month DESC
        LIMIT 12";

$rows = [];
$res = mysqli_query($conn, $sql);
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $rows[] = [
            'month' => (string)($row['snapshot_month'] ?? ''),
            'sla_percent' => (float)($row['total_sla_percent'] ?? 0),
            'total_checks' => (int)($row['total_checks'] ?? 0),
            'online_checks' => (int)($row['online_checks'] ?? 0),
        ];
    }
}

echo json_encode([
    'success' => true,
    'idpel' => $idpel,
    'rows' => $rows,
]);
