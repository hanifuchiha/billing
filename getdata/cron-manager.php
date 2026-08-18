<?php
/**
 * Cron Job Manager API
 * Manages background cron jobs for OLT cache and other processes
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../header.php';

// Check user authentication
if (!isset($_SESSION['status']) || $_SESSION['status'] !== 'login') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Only allow admin users
$user_akses = $_SESSION['akses'] ?? '';
if ($user_akses !== 'ADMIN' && $user_akses !== 'SUPERADMIN') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$job_name = $_GET['job'] ?? $_POST['job'] ?? '';

// Database table for cron jobs
$cron_table = 'cron_jobs';

// Ensure table exists
$create_table_sql = "CREATE TABLE IF NOT EXISTS $cron_table (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_name VARCHAR(100) UNIQUE NOT NULL,
    script_path VARCHAR(255) NOT NULL,
    interval_minutes INT DEFAULT 5,
    enabled TINYINT DEFAULT 0,
    last_run DATETIME,
    next_run DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";
mysqli_query($conn, $create_table_sql);

// Default cron jobs
$default_jobs = [
    [
        'job_name' => 'olt_cache',
        'script_path' => 'getdata/olt-cache-background.php',
        'interval_minutes' => 5,
        'description' => 'OLT Data Cache Update'
    ],
    [
        'job_name' => 'server_ping',
        'script_path' => 'getdata/server-cache-background.php',
        'interval_minutes' => 5,
        'description' => 'Server Ping Status'
    ],
    [
        'job_name' => 'repair_dangling_pppoe',
        'script_path' => 'getdata/cron_repair_dangling_pppoe_profile.php',
        'interval_minutes' => 60,
        'description' => 'Perbaiki otomatis PPP Profile pelanggan yang jadi referensi menggantung ("*15"/unknown)'
    ]
];

try {
    switch ($action) {
        case 'list':
            // Get all cron jobs
            $jobs = [];
            
            // First, ensure default jobs exist in DB
            foreach ($default_jobs as $job) {
                $check = mysqli_query($conn, "SELECT id FROM $cron_table WHERE job_name = '" . mysqli_real_escape_string($conn, $job['job_name']) . "'");
                if (mysqli_num_rows($check) == 0) {
                    mysqli_query($conn, "INSERT INTO $cron_table (job_name, script_path, interval_minutes) VALUES (
                        '" . mysqli_real_escape_string($conn, $job['job_name']) . "',
                        '" . mysqli_real_escape_string($conn, $job['script_path']) . "',
                        " . (int)$job['interval_minutes'] . "
                    )");
                }
            }
            
            // Now fetch all jobs
            $result = mysqli_query($conn, "SELECT * FROM $cron_table ORDER BY job_name");
            while ($row = mysqli_fetch_assoc($result)) {
                $jobs[] = $row;
            }
            
            echo json_encode([
                'success' => true,
                'jobs' => $jobs,
                'total' => count($jobs)
            ]);
            break;
            
        case 'toggle':
            if (empty($job_name)) {
                throw new Exception('Job name required');
            }
            
            $result = mysqli_query($conn, "SELECT enabled FROM $cron_table WHERE job_name = '" . mysqli_real_escape_string($conn, $job_name) . "'");
            $row = mysqli_fetch_assoc($result);
            
            if (!$row) {
                throw new Exception('Job not found');
            }
            
            $new_enabled = $row['enabled'] ? 0 : 1;
            $update_result = mysqli_query($conn, "UPDATE $cron_table SET enabled = $new_enabled WHERE job_name = '" . mysqli_real_escape_string($conn, $job_name) . "'");
            
            if (!$update_result) {
                throw new Exception('Failed to update job');
            }
            
            echo json_encode([
                'success' => true,
                'job' => $job_name,
                'enabled' => (bool)$new_enabled,
                'message' => $new_enabled ? 'Cron job enabled' : 'Cron job disabled'
            ]);
            break;
            
        case 'run':
            if (empty($job_name)) {
                throw new Exception('Job name required');
            }
            
            $result = mysqli_query($conn, "SELECT script_path FROM $cron_table WHERE job_name = '" . mysqli_real_escape_string($conn, $job_name) . "'");
            $row = mysqli_fetch_assoc($result);
            
            if (!$row) {
                throw new Exception('Job not found');
            }
            
            $script_path = __DIR__ . '/../../billing/' . $row['script_path'];
            
            if (!file_exists($script_path)) {
                throw new Exception('Script not found: ' . $script_path);
            }
            
            // Run the script in background
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                // Windows
                pclose(popen("start /B php.exe \"$script_path\"", "r"));
            } else {
                // Linux/Unix
                exec("php $script_path > /dev/null 2>&1 &");
            }
            
            // Update last_run
            mysqli_query($conn, "UPDATE $cron_table SET last_run = NOW() WHERE job_name = '" . mysqli_real_escape_string($conn, $job_name) . "'");
            
            echo json_encode([
                'success' => true,
                'job' => $job_name,
                'message' => 'Cron job triggered manually'
            ]);
            break;
            
        case 'setup_crontab':
            // Return crontab setup instructions
            $crontab_lines = [];
            
            foreach ($default_jobs as $job) {
                $script_path = '/var/www/html/crm/billing/' . $job['script_path'];
                $interval = $job['interval_minutes'];
                $crontab_lines[] = "*/$interval * * * * /usr/bin/php $script_path >> /var/log/qts-cron.log 2>&1";
            }
            
            echo json_encode([
                'success' => true,
                'crontab_lines' => $crontab_lines,
                'instructions' => 'Run "crontab -e" on your server and add these lines'
            ]);
            break;
            
        default:
            throw new Exception('Unknown action: ' . $action);
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
