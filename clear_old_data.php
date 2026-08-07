<?php
// clear_old_data.php - Script untuk membersihkan file cache dan log lama secara otomatis
// Jalankan script ini secara berkala untuk menjaga performa dashboard tanpa menghapus data database

// Fungsi untuk logging pembersihan
function logCleanup($message) {
    $logFile = 'logs/cleanup_log.txt';
    if (!is_dir('logs')) {
        mkdir('logs', 0755, true);
    }
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

// 1. Bersihkan file cache server log yang lebih dari 7 hari
$logDir = 'serverlog/';
if (is_dir($logDir)) {
    $files = glob($logDir . '*.txt');
    $sevenDaysAgo = time() - (7 * 24 * 60 * 60);
    $deletedFiles = 0;
    foreach ($files as $file) {
        if (filemtime($file) < $sevenDaysAgo && basename($file) !== 'index.html') {
            unlink($file);
            $deletedFiles++;
        }
    }
    logCleanup("Menghapus $deletedFiles file cache server log yang lebih dari 7 hari");
}

// 2. Bersihkan file cache AJAX di getdata/cache/ yang lebih dari 1 hari
$cacheDir = 'getdata/cache/';
if (is_dir($cacheDir)) {
    $cacheFiles = glob($cacheDir . '*.json');
    $oneDayAgo = time() - (24 * 60 * 60);
    $deletedCache = 0;
    foreach ($cacheFiles as $file) {
        if (filemtime($file) < $oneDayAgo) {
            unlink($file);
            $deletedCache++;
        }
    }
    logCleanup("Menghapus $deletedCache file cache AJAX yang lebih dari 1 hari");
}

// 3. Bersihkan file history log bot yang lebih dari 30 hari
$historyDir = '../notifbot/data/';
if (is_dir($historyDir)) {
    $historyFiles = glob($historyDir . 'history-*.json');
    $thirtyDaysAgo = time() - (30 * 24 * 60 * 60);
    $deletedHistory = 0;
    foreach ($historyFiles as $file) {
        if (filemtime($file) < $thirtyDaysAgo) {
            // Backup dulu sebelum hapus
            $backupFile = $historyDir . 'backup_' . basename($file) . '_' . date('Y-m-d');
            copy($file, $backupFile);
            unlink($file);
            $deletedHistory++;
        }
    }
    logCleanup("Menghapus $deletedHistory file history log bot yang lebih dari 30 hari (dengan backup)");
}

// 4. Bersihkan file debug yang lebih dari 3 hari
$debugDir = 'getdata/debug/';
if (is_dir($debugDir)) {
    $debugFiles = glob($debugDir . '*');
    $threeDaysAgo = time() - (3 * 24 * 60 * 60);
    $deletedDebug = 0;
    foreach ($debugFiles as $file) {
        if (is_file($file) && filemtime($file) < $threeDaysAgo) {
            unlink($file);
            $deletedDebug++;
        }
    }
    logCleanup("Menghapus $deletedDebug file debug yang lebih dari 3 hari");
}

// 5. Bersihkan file debug session yang lebih dari 1 hari
$debugSessionDir = 'getdata/debug_session/';
if (is_dir($debugSessionDir)) {
    $debugSessionFiles = glob($debugSessionDir . '*');
    $oneDayAgo = time() - (24 * 60 * 60);
    $deletedDebugSession = 0;
    foreach ($debugSessionFiles as $file) {
        if (is_file($file) && filemtime($file) < $oneDayAgo) {
            unlink($file);
            $deletedDebugSession++;
        }
    }
    logCleanup("Menghapus $deletedDebugSession file debug session yang lebih dari 1 hari");
}

// 6. Bersihkan file log cleanup sendiri yang lebih dari 30 hari
$cleanupLogFile = 'logs/cleanup_log.txt';
if (file_exists($cleanupLogFile)) {
    $thirtyDaysAgo = time() - (30 * 24 * 60 * 60);
    if (filemtime($cleanupLogFile) < $thirtyDaysAgo) {
        // Backup dulu sebelum hapus
        copy($cleanupLogFile, 'logs/cleanup_log_backup_' . date('Y-m-d') . '.txt');
        unlink($cleanupLogFile);
        logCleanup("File log cleanup lama dihapus dan dibackup");
    }
}

// 7. Bersihkan file temporary PHP yang mungkin ada
$tempDir = sys_get_temp_dir();
$tempFiles = glob($tempDir . '/php*');
$oneDayAgo = time() - (24 * 60 * 60);
$deletedTemp = 0;
foreach ($tempFiles as $file) {
    if (filemtime($file) < $oneDayAgo) {
        unlink($file);
        $deletedTemp++;
    }
}
logCleanup("Menghapus $deletedTemp file temporary PHP yang lebih dari 1 hari");

// 8. Bersihkan cache session PHP jika ada (opsional, hati-hati)
$sessionDir = session_save_path();
if ($sessionDir && is_dir($sessionDir)) {
    $sessionFiles = glob($sessionDir . '/sess_*');
    $sevenDaysAgo = time() - (7 * 24 * 60 * 60);
    $deletedSessions = 0;
    foreach ($sessionFiles as $file) {
        if (filemtime($file) < $sevenDaysAgo) {
            unlink($file);
            $deletedSessions++;
        }
    }
    logCleanup("Menghapus $deletedSessions session file yang lebih dari 7 hari");
}

// Output untuk cron job atau manual run
echo "Pembersihan cache AJAX selesai pada " . date('Y-m-d H:i:s') . "\n";
echo "File cache server log dihapus: $deletedFiles\n";
echo "File cache AJAX dihapus: $deletedCache\n";
echo "File history log bot dihapus: $deletedHistory\n";
echo "File debug dihapus: $deletedDebug\n";
echo "File debug session dihapus: $deletedDebugSession\n";
echo "File temporary dihapus: $deletedTemp\n";
echo "Session file dihapus: $deletedSessions\n";
echo "Log tersimpan di logs/cleanup_log.txt\n";
?>
