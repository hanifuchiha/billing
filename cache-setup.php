<?php
/**
 * QUICK START - AUTO-SETUP FOR CACHE SYSTEM
 * Usage: Open this file in browser: crm/billing/cache-setup.php
 * Or run: php cache-setup.php
 */

define('CACHE_DIR', __DIR__ . '/getdata/cache');
define('LOGS_DIR', __DIR__ . '/logs');

$cli = php_sapi_name() === 'cli';
$errors = [];
$success = [];

// =================== STEP 1: Create directories ===================
if (!is_dir(CACHE_DIR)) {
    if (@mkdir(CACHE_DIR, 0755, true)) {
        $success[] = 'Created cache directory: ' . CACHE_DIR;
    } else {
        $errors[] = 'Failed to create cache directory';
    }
} else {
    $success[] = 'Cache directory exists: ' . CACHE_DIR;
}

if (!is_dir(LOGS_DIR)) {
    if (@mkdir(LOGS_DIR, 0755, true)) {
        $success[] = 'Created logs directory: ' . LOGS_DIR;
    } else {
        $errors[] = 'Failed to create logs directory';
    }
} else {
    $success[] = 'Logs directory exists: ' . LOGS_DIR;
}

// =================== STEP 2: Check file permissions ===================
if (is_writable(CACHE_DIR)) {
    $success[] = 'Cache directory is writable';
} else {
    $errors[] = 'Cache directory is NOT writable (chmod 755 needed)';
}

if (is_writable(LOGS_DIR)) {
    $success[] = 'Logs directory is writable';
} else {
    $errors[] = 'Logs directory is NOT writable (chmod 755 needed)';
}

// =================== STEP 3: Check required files ===================
$required_files = [
    'cache-manager.php',
    'olt.php',
    'getdata/cache-api.php',
    'getdata/olt-cache.php'
];

foreach ($required_files as $file) {
    $path = __DIR__ . '/' . $file;
    if (file_exists($path)) {
        $success[] = 'File exists: ' . $file;
    } else {
        $errors[] = 'File missing: ' . $file;
    }
}

// =================== STEP 4: Run initial cache refresh ===================
if (empty($errors)) {
    require_once __DIR__ . '/../../header.php';
    
    require_once __DIR__ . '/cache-manager.php';
    
    try {
        $results = updateAllCache($conn);
        $success[] = 'Initial cache refresh completed:';
        $success[] = '  - OLTs: ' . $results['olt'];
        $success[] = '  - Servers: ' . $results['server'];
        $success[] = '  - Users: ' . $results['user'];
        $success[] = '  - ODPs: ' . $results['odp'];
        $success[] = '  - Areas: ' . $results['area'];
    } catch (Exception $e) {
        $errors[] = 'Cache refresh failed: ' . $e->getMessage();
    }
}

// =================== OUTPUT ===================

if ($cli) {
    // CLI Output
    echo "\n";
    echo "================================================\n";
    echo "QTS CACHE SYSTEM - SETUP STATUS\n";
    echo "================================================\n\n";
    
    if (!empty($success)) {
        echo "✓ SUCCESS:\n";
        foreach ($success as $msg) {
            echo "  • $msg\n";
        }
        echo "\n";
    }
    
    if (!empty($errors)) {
        echo "✗ ERRORS:\n";
        foreach ($errors as $msg) {
            echo "  • $msg\n";
        }
        echo "\n";
    }
    
    if (empty($errors)) {
        echo "================================================\n";
        echo "SETUP COMPLETE!\n";
        echo "================================================\n\n";
        echo "Next steps:\n";
        echo "1. Setup automatic refresh:\n";
        echo "   - Windows: Run setup-task-scheduler.bat\n";
        echo "   - Linux: Run setup-cron.sh\n\n";
        echo "2. Access admin dashboard:\n";
        echo "   - cache-admin.php (requires ADMIN account)\n\n";
        echo "3. Read setup guide:\n";
        echo "   - CACHE_SETUP_GUIDE.md\n\n";
    } else {
        echo "================================================\n";
        echo "SETUP FAILED - Please fix errors above\n";
        echo "================================================\n\n";
    }
    
} else {
    // Web Output
    session_start();
    require_once __DIR__ . '/header.php';
    ?>
    
    <div class="container my-5">
        <div class="card shadow">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="fas fa-cog me-2"></i>QTS Cache System - Setup Verification</h5>
            </div>
            <div class="card-body">
                
                <?php if (!empty($success)): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <h6 class="alert-heading"><i class="fas fa-check-circle me-2"></i>Setup Status: OK</h6>
                        <ul class="mb-0">
                            <?php foreach ($success as $msg): ?>
                                <li><?= htmlspecialchars($msg); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <h6 class="alert-heading"><i class="fas fa-exclamation-circle me-2"></i>Issues Found:</h6>
                        <ul class="mb-0">
                            <?php foreach ($errors as $msg): ?>
                                <li><?= htmlspecialchars($msg); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (empty($errors)): ?>
                    <div class="alert alert-info">
                        <h6 class="alert-heading"><i class="fas fa-info-circle me-2"></i>Next Steps:</h6>
                        <ol>
                            <li><strong>Setup Automatic Refresh</strong>
                                <ul>
                                    <li>Windows: Download and run <code>setup-task-scheduler.bat</code></li>
                                    <li>Linux: Run <code>bash setup-cron.sh</code></li>
                                </ul>
                            </li>
                            <li><strong>Monitor Cache</strong> - Visit <a href="cache-admin.php">Cache Admin Dashboard</a></li>
                            <li><strong>Read Documentation</strong> - See <code>CACHE_SETUP_GUIDE.md</code></li>
                        </ol>
                    </div>
                    
                    <div class="alert alert-success">
                        <strong>✓ Cache system is ready!</strong><br>
                        All OLT and Server data is now cached and will be served instantly.
                    </div>
                    
                    <a href="cache-admin.php" class="btn btn-primary">
                        <i class="fas fa-dashboard me-2"></i>Go to Cache Dashboard
                    </a>
                    <a href="olt.php" class="btn btn-secondary">
                        <i class="fas fa-cube me-2"></i>View OLT Page
                    </a>
                <?php else: ?>
                    <div class="alert alert-danger">
                        <strong>Setup incomplete</strong> - Please fix the errors above before continuing.
                    </div>
                <?php endif; ?>
                
            </div>
        </div>
    </div>
    
    <?php
    require_once __DIR__ . '/footer.php';
}
?>
