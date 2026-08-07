<?php
/**
 * Troubleshooting Guide for Server Data Loading
 * File: server.php Data Loading Issues
 * Date: May 4, 2026
 */

// This page helps debug why data is not loading

session_start();
header('Content-Type: text/html; charset=utf-8');

if (!isset($_SESSION['status']) || $_SESSION['status'] != 'login') {
    die('Please login first');
}

require 'koneksidb.php';
require 'billing/routeros_api.class.php';

$result = [
    'checks' => [],
    'servers' => []
];

// Check 1: Database connection
try {
    $check = mysqli_query($conn, "SELECT COUNT(*) as total FROM server");
    $row = mysqli_fetch_array($check);
    $result['checks'][] = [
        'name' => 'Database Connection',
        'status' => 'OK',
        'data' => $row
    ];
} catch (Exception $e) {
    $result['checks'][] = [
        'name' => 'Database Connection',
        'status' => 'FAILED',
        'error' => $e->getMessage()
    ];
}

// Check 2: Get server list
try {
    $query = mysqli_query($conn, "SELECT id, IP, PEMILIK, BRAND, AREA FROM server LIMIT 5");
    if ($query) {
        while ($server = mysqli_fetch_array($query)) {
            $servers_data = [
                'id' => $server['id'],
                'brand' => $server['BRAND'],
                'ip' => $server['IP'],
                'user' => $server['PEMILIK'],
                'connection_test' => 'Not tested'
            ];
            
            // Try to connect
            $api = new RouterosAPI();
            if ($api->connect($server['IP'], $server['PEMILIK'], $server['PASSWORD'])) {
                $resource = $api->comm("/system/resource/print");
                $servers_data['connection_test'] = 'SUCCESS';
                $servers_data['version'] = $resource[0]['version'] ?? 'Unknown';
                $api->disconnect();
            } else {
                $servers_data['connection_test'] = 'FAILED';
            }
            
            $result['servers'][] = $servers_data;
        }
    }
} catch (Exception $e) {
    $result['checks'][] = [
        'name' => 'Server Query',
        'status' => 'FAILED',
        'error' => $e->getMessage()
    ];
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Server Data Loading Troubleshooting</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1000px; margin: 0 auto; }
        .card { background: white; padding: 20px; margin: 10px 0; border-radius: 5px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .check-item { padding: 10px; margin: 5px 0; border-left: 4px solid #ccc; background: #f9f9f9; }
        .check-item.ok { border-left-color: #28a745; background: #d4edda; }
        .check-item.failed { border-left-color: #dc3545; background: #f8d7da; }
        .server-item { padding: 10px; margin: 5px 0; border: 1px solid #ddd; background: #fff; }
        .server-item.ok { border-left: 4px solid #28a745; }
        .server-item.failed { border-left: 4px solid #dc3545; }
        pre { background: #f4f4f4; padding: 10px; border-radius: 3px; overflow-x: auto; }
        h2 { color: #333; }
        code { background: #f0f0f0; padding: 2px 5px; border-radius: 3px; }
    </style>
</head>
<body>
<div class="container">
    <h1>🔧 Server Data Loading Troubleshooting</h1>
    
    <div class="card">
        <h2>System Checks</h2>
        <?php foreach ($result['checks'] as $check): ?>
            <div class="check-item <?php echo strtolower($check['status']); ?>">
                <strong><?php echo $check['name']; ?>:</strong> 
                <span><?php echo $check['status']; ?></span>
                <?php if (isset($check['data'])): ?>
                    <pre><?php echo json_encode($check['data'], JSON_PRETTY_PRINT); ?></pre>
                <?php endif; ?>
                <?php if (isset($check['error'])): ?>
                    <pre style="color: red;"><?php echo $check['error']; ?></pre>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <h2>Server Connection Tests</h2>
        <?php foreach ($result['servers'] as $server): ?>
            <div class="server-item <?php echo ($server['connection_test'] === 'SUCCESS') ? 'ok' : 'failed'; ?>">
                <strong><?php echo $server['brand']; ?></strong> (<?php echo $server['ip']; ?>)<br>
                <small>User: <code><?php echo $server['user']; ?></code></small><br>
                Connection: <strong><?php echo $server['connection_test']; ?></strong>
                <?php if (isset($server['version'])): ?>
                    - Version: <?php echo $server['version']; ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <h2>💡 Debugging Steps</h2>
        <ol>
            <li>Open Browser Console (F12 → Console tab)</li>
            <li>Look for <code>[Page Load]</code> messages in console</li>
            <li>Check if <code>[Ping Init]</code> and <code>[Fetch Init]</code> messages appear</li>
            <li>If connection fails, check:
                <ul>
                    <li>MikroTik API port 8728 is accessible</li>
                    <li>Credentials (username/password) are correct</li>
                    <li>Server IP is reachable from this server</li>
                </ul>
            </li>
            <li>Visit <code>/crm/billing/getdata/debug_params.php?ip=X.X.X.X&user=admin&password=PASS</code> to test parameters</li>
        </ol>
    </div>

    <div class="card" style="background: #e7f3ff; border-left: 4px solid #0066cc;">
        <h2>📌 Quick Test Links</h2>
        <ul>
            <li><a href="/crm/billing/getdata/test_mikrotik.php" target="_blank">Test MikroTik Connection</a></li>
            <li><a href="/crm/billing/getdata/debug_params.php" target="_blank">Debug Parameters</a> (add ?ip=X&user=Y&password=Z)</li>
        </ul>
    </div>
</div>
</body>
</html>
