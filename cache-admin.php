<?php
/**
 * CACHE MANAGEMENT DASHBOARD
 * Access: crm/billing/cache-admin.php
 */

require 'header.php';

// Check if user is admin
if ($AKSES !== 'ADMIN') {
    die('<div class="alert alert-danger">Access Denied. Admin only.</div>');
}

define('CACHE_DIR', __DIR__ . '/getdata/cache');

// Get cache statistics
function getCacheStats() {
    $files = glob(CACHE_DIR . '/*.json');
    $stats = [];
    $totalSize = 0;
    $totalAge = 0;
    
    foreach ($files as $file) {
        if (basename($file) === 'cache.log') continue;
        
        $content = json_decode(file_get_contents($file), true);
        $key = $content['key'] ?? 'unknown';
        $size = filesize($file);
        $totalSize += $size;
        $age = time() - $content['timestamp'];
        $ttl = $content['ttl'] ?? 0;
        $expires = $content['expires'] ?? 0;
        
        $stats[] = [
            'key' => $key,
            'size' => $size,
            'age' => $age,
            'ttl' => $ttl,
            'expires_in' => max(0, $expires - time()),
            'count' => is_array($content['data']) ? count($content['data']) : 0,
            'status' => ($expires < time()) ? 'EXPIRED' : 'VALID'
        ];
    }
    
    return [
        'files' => $stats,
        'total_files' => count($stats),
        'total_size' => $totalSize,
        'cache_dir' => CACHE_DIR
    ];
}

// Get log entries
function getCacheLogs($limit = 50) {
    $logFile = CACHE_DIR . '/cache.log';
    if (!file_exists($logFile)) {
        return [];
    }
    
    $logs = file($logFile);
    return array_reverse(array_slice($logs, -$limit));
}

// Handle actions
if ($_POST['action'] ?? '' === 'refresh') {
    // Trigger cache refresh
    exec("php " . escapeshellarg(__DIR__ . '/cache-manager.php') . " refresh", $output, $status);
    $message = $status === 0 ? 'Cache refresh triggered' : 'Error triggering refresh';
}

if ($_POST['action'] ?? '' === 'clear') {
    // Clear all caches
    exec("php " . escapeshellarg(__DIR__ . '/cache-manager.php') . " clear", $output, $status);
    $message = $status === 0 ? 'Caches cleared' : 'Error clearing caches';
}

$stats = getCacheStats();
$logs = getCacheLogs();
?>

<div class="container my-4">
    
    <div class="card shadow mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="fas fa-database me-2"></i>Cache Management Dashboard</h5>
        </div>
        <div class="card-body">
            
            <?php if (isset($message)): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <i class="fas fa-info-circle me-2"></i><?= htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <!-- Stats -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h3><?= $stats['total_files']; ?></h3>
                            <p class="text-muted mb-0">Cache Files</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h3><?= number_format($stats['total_size'] / 1024, 2); ?> KB</h3>
                            <p class="text-muted mb-0">Total Size</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-body">
                            <p class="mb-0"><code><?= $stats['cache_dir']; ?></code></p>
                            <small class="text-muted">Cache Directory</small>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Action Buttons -->
            <div class="mb-3">
                <form method="POST" class="d-flex gap-2">
                    <button name="action" value="refresh" type="submit" class="btn btn-success">
                        <i class="fas fa-sync me-2"></i>Refresh All Caches
                    </button>
                    <button name="action" value="clear" type="submit" class="btn btn-warning" onclick="return confirm('Clear all caches?');">
                        <i class="fas fa-trash me-2"></i>Clear All Caches
                    </button>
                    <a href="<?= htmlspecialchars($_SERVER['REQUEST_URI']); ?>" class="btn btn-secondary">
                        <i class="fas fa-redo me-2"></i>Refresh Page
                    </a>
                </form>
            </div>
            
        </div>
    </div>
    
    <!-- Cache Files Detail -->
    <div class="card shadow mb-4">
        <div class="card-header bg-info text-white">
            <h5 class="mb-0"><i class="fas fa-list me-2"></i>Cache Files</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Key</th>
                        <th>Items</th>
                        <th>Size</th>
                        <th>Age</th>
                        <th>TTL</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stats['files'] as $file): ?>
                        <tr>
                            <td><code><?= htmlspecialchars($file['key']); ?></code></td>
                            <td><?= $file['count']; ?></td>
                            <td><?= number_format($file['size'] / 1024, 2); ?> KB</td>
                            <td><?= formatSeconds($file['age']); ?> ago</td>
                            <td><?= formatSeconds($file['ttl']); ?></td>
                            <td>
                                <?php if ($file['status'] === 'VALID'): ?>
                                    <span class="badge bg-success">VALID</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">EXPIRED</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Log Viewer -->
    <div class="card shadow">
        <div class="card-header bg-secondary text-white">
            <h5 class="mb-0"><i class="fas fa-history me-2"></i>Activity Log (Last 50)</h5>
        </div>
        <div class="card-body">
            <pre style="max-height: 400px; overflow-y: auto; background: #f8f9fa; padding: 10px; border-radius: 4px;"><?php
                foreach ($logs as $log) {
                    echo htmlspecialchars($log);
                }
            ?></pre>
        </div>
    </div>
    
</div>

<?php
function formatSeconds($seconds) {
    if ($seconds < 60) return $seconds . 's';
    if ($seconds < 3600) return round($seconds / 60) . 'm';
    if ($seconds < 86400) return round($seconds / 3600) . 'h';
    return round($seconds / 86400) . 'd';
}

require 'footer.php';
?>
