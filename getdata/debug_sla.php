<?php
// =====================================================================
// SLA Discount Debug Endpoint
// =====================================================================
// Endpoint untuk debug dan test fitur diskon SLA
// URL: /crm/billing/getdata/debug_sla.php?idpel=XXXXX

include '../cek_sesi.php';
require_once '../koneksibilling.php';
require_once './sla_discount_helper.php';

header('Content-Type: application/json; charset=utf-8');

$idpel = isset($_GET['idpel']) ? trim((string)$_GET['idpel']) : '';

if (empty($idpel)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'IDPEL parameter is required',
        'usage' => 'debug_sla.php?idpel=XXXXX'
    ]);
    exit;
}

// Debug info
$debug = [
    'idpel' => $idpel,
    'timestamp' => date('Y-m-d H:i:s'),
    'timezone' => 'Asia/Jakarta',
    'feature_enabled' => isSlaDiscountEnabled(),
    'config_file' => __DIR__ . '/../notifbot/data/sla_discount_config.json',
    'config_exists' => file_exists(__DIR__ . '/../notifbot/data/sla_discount_config.json')
];

// Test getSlaDicount
$sla_data = getSlaDicount($conn, $idpel);
$debug['sla_data'] = $sla_data;

// Test calculateInvoiceWithSlaDiscount
$test_amount = 176490;
$breakdown = calculateInvoiceWithSlaDiscount($conn, $idpel, $test_amount);
$debug['test_breakdown'] = [
    'base_amount' => $breakdown['base_amount'],
    'sla_discount_amount' => $breakdown['sla_discount_amount'],
    'total_amount' => $breakdown['total_amount'],
    'sla_discount_percent' => $breakdown['sla_discount_percent'],
    'has_discount' => $breakdown['has_discount']
];

// Check customer existence
$customer_check = $conn->prepare("
    SELECT IDPEL, PEMILIK FROM pelanggan WHERE IDPEL = ? LIMIT 1
");
$customer_check->bind_param("s", $idpel);
$customer_check->execute();
$customer_result = $customer_check->get_result();
$debug['customer_exists'] = $customer_result->num_rows > 0;
if ($customer_result->num_rows > 0) {
    $customer = $customer_result->fetch_assoc();
    $debug['customer_pemilik'] = $customer['PEMILIK'];
}

// Check SLA snapshots
$current_date = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
$current_date->modify('-1 month');
$last_month = $current_date->format('Y-m');
$debug['last_month_searched'] = $last_month;

$snapshot_check = $conn->prepare("
    SELECT COUNT(*) as total FROM customer_sla_monthly_snapshots 
    WHERE idpel = ? AND snapshot_month = ?
");
$snapshot_check->bind_param("ss", $idpel, $last_month);
$snapshot_check->execute();
$snapshot_result = $snapshot_check->get_result();
$snapshot_count = $snapshot_result->fetch_assoc();
$debug['sla_snapshot_found'] = $snapshot_count['total'] > 0;

// Show raw query result for SLA snapshot
if ($snapshot_count['total'] > 0) {
    $raw_snapshot = $conn->prepare("
        SELECT * FROM customer_sla_monthly_snapshots 
        WHERE idpel = ? AND snapshot_month = ? LIMIT 1
    ");
    $raw_snapshot->bind_param("ss", $idpel, $last_month);
    $raw_snapshot->execute();
    $raw_result = $raw_snapshot->get_result();
    if ($raw_result->num_rows > 0) {
        $debug['raw_snapshot'] = $raw_result->fetch_assoc();
    }
}

echo json_encode($debug, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
