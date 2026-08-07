<?php
/**
 * =====================================================================
 * SLA Discount Database Initialization Script
 * =====================================================================
 * Script untuk inisialisasi database dan membuat sample data SLA
 * Gunakan: http://yoursite/crm/billing/getdata/init_sla_database.php
 */

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

include '../cek_sesi.php';
require_once '../koneksibilling.php';

header('Content-Type: application/json; charset=utf-8');

$results = [];

// 1. Check if table exists
$table_check = $conn->query("
    SELECT 1 FROM information_schema.tables 
    WHERE table_schema = DATABASE() 
    AND table_name = 'customer_sla_monthly_snapshots'
");

if ($table_check && $table_check->num_rows > 0) {
    $results['table_exists'] = true;
    $results['message'] = 'Table customer_sla_monthly_snapshots already exists';
} else {
    $results['table_exists'] = false;
    
    // Create table
    $create_table = "CREATE TABLE IF NOT EXISTS `customer_sla_monthly_snapshots` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `idpel` varchar(50) NOT NULL,
        `pemilik` varchar(100) NOT NULL,
        `snapshot_month` varchar(7) NOT NULL COMMENT 'Format: YYYY-MM',
        `total_sla_percent` decimal(5,2) NOT NULL DEFAULT 100.00,
        `total_checks` int(11) NOT NULL DEFAULT 0,
        `online_checks` int(11) NOT NULL DEFAULT 0,
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_snapshot` (`idpel`,`pemilik`,`snapshot_month`),
        KEY `idx_idpel_month` (`idpel`,`snapshot_month`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    if ($conn->query($create_table)) {
        $results['table_created'] = true;
        $results['create_message'] = 'Table customer_sla_monthly_snapshots created successfully';
    } else {
        $results['table_created'] = false;
        $results['create_error'] = $conn->error;
    }
}

// 2. Get sample customers for testing
$customer_query = "SELECT DISTINCT IDPEL, PEMILIK FROM pelanggan LIMIT 5";
$customer_result = $conn->query($customer_query);
$sample_customers = [];

if ($customer_result) {
    while ($row = $customer_result->fetch_assoc()) {
        $sample_customers[] = $row;
    }
}

$results['sample_customers_found'] = count($sample_customers);
$results['sample_customers'] = $sample_customers;

// 3. Create sample SLA data for testing
if (count($sample_customers) > 0) {
    $last_month = date('Y-m', strtotime('-1 month'));
    $results['creating_sample_data_for_month'] = $last_month;
    
    $created_count = 0;
    $errors = [];
    
    foreach ($sample_customers as $customer) {
        // Generate random SLA between 85-99%
        $sla_percent = rand(85, 99) + (rand(0, 99) / 100);
        $total_checks = rand(200, 500);
        $online_checks = floor(($sla_percent / 100) * $total_checks);
        
        $insert_query = $conn->prepare("
            INSERT INTO customer_sla_monthly_snapshots 
            (idpel, pemilik, snapshot_month, total_sla_percent, total_checks, online_checks)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            total_sla_percent = VALUES(total_sla_percent),
            total_checks = VALUES(total_checks),
            online_checks = VALUES(online_checks)
        ");
        
        if (!$insert_query) {
            $errors[] = "Prepare failed: " . $conn->error;
            continue;
        }
        
        $insert_query->bind_param(
            "sssiii",
            $customer['IDPEL'],
            $customer['PEMILIK'],
            $last_month,
            $sla_percent,
            $total_checks,
            $online_checks
        );
        
        if ($insert_query->execute()) {
            $created_count++;
            $results['sample_data'][] = [
                'idpel' => $customer['IDPEL'],
                'pemilik' => $customer['PEMILIK'],
                'snapshot_month' => $last_month,
                'sla_percent' => round($sla_percent, 2),
                'discount_percent' => round(100 - $sla_percent, 2),
                'status' => 'created'
            ];
        } else {
            $errors[] = "Insert failed for {$customer['IDPEL']}: " . $insert_query->error;
        }
    }
    
    $results['sample_data_created'] = $created_count;
    if (count($errors) > 0) {
        $results['errors'] = $errors;
    }
}

// 4. Verify config file
$config_file = __DIR__ . '/../notifbot/data/sla_discount_config.json';
$results['config_file'] = $config_file;
$results['config_exists'] = file_exists($config_file);

if (file_exists($config_file)) {
    $config = json_decode(file_get_contents($config_file), true);
    $results['config_enabled'] = (bool)($config['enabled'] ?? false);
}

// 5. Count total snapshots in database
$count_query = "SELECT COUNT(*) as total FROM customer_sla_monthly_snapshots";
$count_result = $conn->query($count_query);
if ($count_result) {
    $count_row = $count_result->fetch_assoc();
    $results['total_snapshots_in_db'] = $count_row['total'];
}

$results['initialization_complete'] = true;
$results['next_steps'] = [
    'Test with: debug_sla.php?idpel=XXXXX',
    'Then reload portal_bayar.php to see SLA discount',
    'Check admin settings in paymentset.php to toggle feature on/off'
];

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
