<?php
/**
 * =====================================================================
 * SLA Data Status Checker
 * =====================================================================
 * Script untuk cek status data SLA di database
 */

include '../cek_sesi.php';
require_once '../koneksibilling.php';

header('Content-Type: application/json; charset=utf-8');

$status = [
    'timestamp' => date('Y-m-d H:i:s'),
    'timezone' => 'Asia/Jakarta'
];

// 1. Check table exists
$table_exists = $conn->query("
    SELECT 1 FROM information_schema.tables 
    WHERE table_schema = DATABASE() 
    AND table_name = 'customer_sla_monthly_snapshots'
");

$status['table_exists'] = $table_exists && $table_exists->num_rows > 0;

// 2. Count total SLA records
$count_query = "SELECT COUNT(*) as total FROM customer_sla_monthly_snapshots";
$count_result = $conn->query($count_query);
$count_row = $count_result->fetch_assoc();
$status['total_sla_records'] = (int)$count_row['total'];

// 3. Get current and last month
$current_month = date('Y-m');
$last_month = date('Y-m', strtotime('-1 month'));
$status['current_month'] = $current_month;
$status['last_month'] = $last_month;

// 4. Count records by month
$by_month = $conn->query("
    SELECT snapshot_month, COUNT(*) as count 
    FROM customer_sla_monthly_snapshots 
    GROUP BY snapshot_month 
    ORDER BY snapshot_month DESC 
    LIMIT 5
");

$status['records_by_month'] = [];
if ($by_month) {
    while ($row = $by_month->fetch_assoc()) {
        $status['records_by_month'][] = $row;
    }
}

// 5. Sample data dari bulan terakhir
$sample_query = $conn->query("
    SELECT 
        idpel,
        pemilik,
        snapshot_month,
        total_sla_percent,
        total_checks,
        online_checks,
        (100 - total_sla_percent) as discount_percent
    FROM customer_sla_monthly_snapshots
    WHERE snapshot_month = '$last_month'
    ORDER BY idpel
    LIMIT 10
");

$status['sample_data_last_month'] = [];
$status['sample_count'] = 0;
if ($sample_query) {
    while ($row = $sample_query->fetch_assoc()) {
        $status['sample_data_last_month'][] = [
            'idpel' => $row['idpel'],
            'pemilik' => $row['pemilik'],
            'sla_percent' => (float)$row['total_sla_percent'],
            'discount_percent' => (float)$row['discount_percent'],
            'total_checks' => (int)$row['total_checks'],
            'online_checks' => (int)$row['online_checks']
        ];
        $status['sample_count']++;
    }
}

// 6. Check config file
$config_file = __DIR__ . '/../notifbot/data/sla_discount_config.json';
$status['config_file_exists'] = file_exists($config_file);
if (file_exists($config_file)) {
    $config = json_decode(file_get_contents($config_file), true);
    $status['config_enabled'] = (bool)($config['enabled'] ?? false);
}

// 7. Test dengan sample customer
$test_customers = $conn->query("
    SELECT DISTINCT IDPEL, PEMILIK 
    FROM pelanggan 
    ORDER BY IDPEL 
    LIMIT 3
");

$status['test_customers'] = [];
if ($test_customers) {
    while ($row = $test_customers->fetch_assoc()) {
        $status['test_customers'][] = [
            'idpel' => $row['IDPEL'],
            'pemilik' => $row['PEMILIK'],
            'debug_url' => "debug_sla.php?idpel=" . urlencode($row['IDPEL'])
        ];
    }
}

// 8. Summary
$status['summary'] = [
    'data_ready' => $status['table_exists'] && $status['total_sla_records'] > 0,
    'feature_enabled' => $status['config_file_exists'] && ($status['config_enabled'] ?? false),
    'data_for_last_month' => count($status['sample_data_last_month']),
    'next_step' => $status['total_sla_records'] == 0 
        ? 'Run init_sla_database.php to create sample data'
        : 'Data is ready, check individual customer with debug_sla.php'
];

echo json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
