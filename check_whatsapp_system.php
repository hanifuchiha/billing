<?php
/**
 * WHATSAPP CALLBACK SYSTEM CHECKER
 * 
 * File ini untuk check kesehatan WhatsApp callback system
 * Jalankan: http://yourserver/crm/billing/check_whatsapp_system.php
 * 
 * GUNAKAN UNTUK: Pre-implementation check sebelum fix
 */

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>WhatsApp Callback System Checker</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            background: #f5f5f5;
        }
        .container {
            max-width: 1000px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 2px solid #007bff;
            padding-bottom: 10px;
        }
        h2 {
            color: #555;
            margin-top: 30px;
        }
        .section {
            margin: 20px 0;
            padding: 15px;
            border-left: 4px solid #ddd;
        }
        .section.pass {
            border-left-color: #28a745;
            background: #f0f8f5;
        }
        .section.fail {
            border-left-color: #dc3545;
            background: #fef5f5;
        }
        .section.warning {
            border-left-color: #ffc107;
            background: #fffaf0;
        }
        .status {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 3px;
            font-weight: bold;
            margin-right: 10px;
        }
        .status.pass {
            background: #28a745;
            color: white;
        }
        .status.fail {
            background: #dc3545;
            color: white;
        }
        .status.warning {
            background: #ffc107;
            color: #333;
        }
        .code {
            background: #f4f4f4;
            padding: 10px;
            border-radius: 3px;
            font-family: monospace;
            margin: 10px 0;
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 10px;
            text-align: left;
        }
        th {
            background: #f4f4f4;
        }
        tr.pass {
            background: #f0f8f5;
        }
        tr.fail {
            background: #fef5f5;
        }
        .button {
            background: #007bff;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            margin: 5px;
        }
        .button:hover {
            background: #0056b3;
        }
    </style>
</head>
<body>

<div class="container">
    <h1>🔍 WhatsApp Callback System Checker</h1>
    <p>Tool untuk mengecek kesehatan WhatsApp callback system sebelum implementasi fix</p>
    <p><strong>Timestamp:</strong> <?= date('Y-m-d H:i:s') ?></p>

<?php
// ===== DATABASE CONNECTION =====
require './koneksidb.php';

$checks = [
    'files' => [],
    'database' => [],
    'callbacks' => [],
    'helper' => []
];

// ===== CHECK 1: FILES =====
echo '<h2>📁 Check: Required Files</h2>';

$requiredFiles = [
    './notifbot/whatsapp_helper.php' => 'New helper function file',
    './test_callback_whatsapp.php' => 'Tester file',
    './notifbot/bot_selector_helper.php' => 'Bot selector helper',
    './koneksidb.php' => 'Database connection'
];

foreach ($requiredFiles as $file => $desc) {
    $exists = file_exists($file);
    $status = $exists ? 'PASS' : 'FAIL';
    $checks['files'][$file] = ['exists' => $exists, 'desc' => $desc];
    
    echo '<div class="section ' . strtolower($status) . '">';
    echo '<span class="status ' . strtolower($status) . '">' . $status . '</span>';
    echo '<strong>' . $file . '</strong> - ' . $desc;
    if ($exists) {
        $size = filesize($file);
        echo ' (' . formatBytes($size) . ')';
    } else {
        echo ' <span style="color: red;">❌ File tidak ditemukan!</span>';
    }
    echo '</div>';
}

// ===== CHECK 2: DATABASE =====
echo '<h2>🗄️ Check: Database Configuration</h2>';

// Check botwa table
$result = $conn->query("SHOW TABLES LIKE 'botwa'");
$botwaExists = $result && $result->num_rows > 0;
$checks['database']['botwa_table'] = $botwaExists;

echo '<div class="section ' . ($botwaExists ? 'pass' : 'fail') . '">';
echo '<span class="status ' . ($botwaExists ? 'pass' : 'fail') . '">' . ($botwaExists ? 'PASS' : 'FAIL') . '</span>';
echo '<strong>Table: botwa</strong>';
if ($botwaExists) {
    $botCount = $conn->query("SELECT COUNT(*) as cnt FROM botwa WHERE status='active' OR status IS NULL")->fetch_assoc();
    echo '<br>Active bots: ' . ($botCount['cnt'] ?? 0) . ' (Show below)';
    
    $botList = $conn->query("SELECT namebot, addressbot, status FROM botwa LIMIT 10");
    if ($botList && $botList->num_rows > 0) {
        echo '<table>';
        echo '<tr><th>Bot Name</th><th>API Address</th><th>Status</th></tr>';
        while ($bot = $botList->fetch_assoc()) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($bot['namebot']) . '</td>';
            echo '<td>' . htmlspecialchars($bot['addressbot']) . '</td>';
            echo '<td>' . ($bot['status'] ?? 'unknown') . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }
} else {
    echo '<span style="color: red;">❌ Table botwa tidak ditemukan!</span>';
}
echo '</div>';

// ===== CHECK 3: REMINDER JSON FILES =====
echo '<h2>📋 Check: Reminder JSON Files</h2>';

$reminderDir = './notifbot/data';
if (is_dir($reminderDir)) {
    $files = glob($reminderDir . '/reminder-*.json');
    echo '<div class="section pass">';
    echo '<span class="status pass">PASS</span>';
    echo '<strong>Directory exists:</strong> ' . $reminderDir . '<br>';
    echo '<strong>Found ' . count($files) . ' reminder files:</strong>';
    
    if (count($files) > 0) {
        echo '<table>';
        echo '<tr><th>File</th><th>Owner</th><th>Valid JSON</th><th>Botname</th></tr>';
        foreach ($files as $file) {
            $basename = basename($file);
            preg_match('/reminder-(.+?)\.json/', $basename, $m);
            $owner = $m[1] ?? 'unknown';
            
            $content = file_get_contents($file);
            $data = json_decode($content, true);
            $valid = ($data !== null);
            $botname = ($valid && isset($data[0]['botname'])) ? $data[0]['botname'] : '-';
            
            echo '<tr class="' . ($valid ? 'pass' : 'fail') . '">';
            echo '<td>' . htmlspecialchars($basename) . '</td>';
            echo '<td>' . htmlspecialchars($owner) . '</td>';
            echo '<td>' . ($valid ? '✓ Valid' : '✗ Invalid') . '</td>';
            echo '<td>' . htmlspecialchars($botname) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }
    echo '</div>';
} else {
    echo '<div class="section fail">';
    echo '<span class="status fail">FAIL</span>';
    echo '<strong>Directory NOT found:</strong> ' . $reminderDir;
    echo '</div>';
}

// ===== CHECK 4: HISTORY FILES =====
echo '<h2>📝 Check: History Log Files</h2>';

if (is_dir($reminderDir)) {
    $historyFiles = glob($reminderDir . '/history-*.json');
    echo '<div class="section ' . (count($historyFiles) > 0 ? 'pass' : 'warning') . '">';
    echo '<span class="status ' . (count($historyFiles) > 0 ? 'pass' : 'warning') . '">' . (count($historyFiles) > 0 ? 'EXISTS' : 'NONE') . '</span>';
    echo '<strong>Found ' . count($historyFiles) . ' history files</strong><br>';
    
    if (count($historyFiles) > 0) {
        foreach ($historyFiles as $file) {
            $basename = basename($file);
            $size = filesize($file);
            $lines = count(file($file));
            preg_match('/history-(.+?)\.json/', $basename, $m);
            $owner = $m[1] ?? 'unknown';
            
            echo '<div style="margin-left: 10px; padding: 5px; background: #f9f9f9; border-left: 2px solid #999;">';
            echo '<strong>Owner:</strong> ' . htmlspecialchars($owner) . ' ';
            echo '<strong>Size:</strong> ' . formatBytes($size) . ' ';
            echo '<strong>Entries:</strong> ~' . $lines;
            echo '</div>';
        }
    } else {
        echo '<p style="color: #666;">History files belum ada (Normal, akan created saat first transaction)</p>';
    }
    echo '</div>';
}

// ===== CHECK 5: CALLBACK FILES =====
echo '<h2>📞 Check: Callback Files Status</h2>';

$callbackFiles = [
    './callbacktripay/callback_tripay_FIBERQ.php' => 'Tripay FIBERQ',
    './callbackxendit/callback_xendit_FIBERQ.php' => 'Xendit FIBERQ',
    './callbackmidtrans/callback_midtrans_FIBERQ.php' => 'Midtrans FIBERQ',
    './callbackduitku/callback_duitku_FIBERQ.php' => 'Duitku FIBERQ',
    './callbackpronpay/callback_pronpay.php' => 'Pronpay'
];

echo '<table>';
echo '<tr><th>File</th><th>Exists</th><th>Size</th><th>Status</th></tr>';

foreach ($callbackFiles as $file => $name) {
    $exists = file_exists($file);
    $size = $exists ? filesize($file) : 0;
    
    // Check if has helper include
    $content = $exists ? file_get_contents($file) : '';
    $hasHelper = strpos($content, 'whatsapp_helper.php') !== false;
    $curleCount = substr_count($content, 'curl_exec(');
    
    echo '<tr class="' . ($exists ? 'pass' : 'fail') . '">';
    echo '<td>' . htmlspecialchars($name) . '</td>';
    echo '<td>' . ($exists ? '✓' : '✗') . '</td>';
    echo '<td>' . ($exists ? formatBytes($size) : '-') . '</td>';
    echo '<td>';
    if ($exists) {
        if ($hasHelper) {
            echo '✓ Fixed (has helper)';
        } else {
            echo '⚠ Needs fix (' . $curleCount . ' curl_exec calls)';
        }
    } else {
        echo '✗ Not found';
    }
    echo '</td>';
    echo '</tr>';
}
echo '</table>';

// ===== CHECK 6: PERMISSIONS =====
echo '<h2>🔐 Check: File Permissions</h2>';

$permFiles = [
    './notifbot/data' => 'Data directory (must be writable)',
    './notifbot/data/history-FIBERQ.json' => 'History file example'
];

foreach ($permFiles as $path => $desc) {
    if (file_exists($path)) {
        $writable = is_writable($path);
        $perms = substr(sprintf('%o', fileperms($path)), -4);
        
        echo '<div class="section ' . ($writable ? 'pass' : 'fail') . '">';
        echo '<span class="status ' . ($writable ? 'pass' : 'fail') . '">' . ($writable ? 'WRITABLE' : 'READ-ONLY') . '</span>';
        echo '<strong>' . $path . '</strong> - ' . $desc;
        echo '<br>Permissions: ' . $perms . ' (' . ($writable ? 'OK' : 'PERLU CHMOD') . ')';
        echo '</div>';
    }
}

// ===== SUMMARY =====
echo '<h2>📊 Summary & Recommendations</h2>';

$allPass = true;
foreach ($checks as $section) {
    foreach ($section as $check) {
        if (is_array($check) && isset($check['exists'])) {
            if (!$check['exists']) $allPass = false;
        } elseif (is_bool($check) && !$check) {
            $allPass = false;
        }
    }
}

echo '<div class="section ' . ($allPass ? 'pass' : 'warning') . '">';
if ($allPass) {
    echo '<span class="status pass">READY</span>';
    echo '<strong>System Status: Siap untuk implementasi!</strong><br>';
    echo '<p>Semua file dan configuration sudah ada. Silahkan lanjut dengan:</p>';
    echo '<ol>';
    echo '<li>Test dengan <code>test_callback_whatsapp.php</code></li>';
    echo '<li>Fix callback files sesuai <code>IMPLEMENTATION_GUIDE.md</code></li>';
    echo '<li>Monitor history logs setelah fix</li>';
    echo '</ol>';
} else {
    echo '<span class="status warning">ATTENTION</span>';
    echo '<strong>System Status: Ada yang perlu di-setup</strong><br>';
    echo '<p>Sebelum implementasi fix, pastikan:</p>';
    echo '<ul>';
    echo '<li>✓ Semua required files ada</li>';
    echo '<li>✓ Database botwa sudah configured</li>';
    echo '<li>✓ Reminder JSON files ada untuk setiap owner</li>';
    echo '<li>✓ Directory ./notifbot/data writable</li>';
    echo '</ul>';
}
echo '</div>';

// Footer
echo '<hr>';
echo '<p style="color: #666; font-size: 12px;">';
echo 'Generated: ' . date('Y-m-d H:i:s') . '<br>';
echo 'For more info, see: <code>README_WHATSAPP_FIX.md</code>, <code>IMPLEMENTATION_GUIDE.md</code>';
echo '</p>';

$conn->close();
?>

</div>

</body>
</html>

<?php
function formatBytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB');
    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }
    return round($bytes, $precision) . ' ' . $units[$i];
}
?>
