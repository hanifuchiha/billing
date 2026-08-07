<?php
/**
 * CACHE SYSTEM - QUICK REFERENCE
 * Bookmark this page for easy access to all cache management tools
 */

session_start();
require_once 'header.php';

// Admin check
if ($AKSES !== 'ADMIN') {
    die('<div class="alert alert-danger" style="margin:20px;">Access Denied - Admin Only</div>');
}
?>

<div class="container my-5">
    <div class="card shadow-lg">
        <div class="card-header bg-primary text-white">
            <h4 class="mb-0"><i class="fas fa-tools me-2"></i>QTS Cache System - Admin Quick Reference</h4>
        </div>
        <div class="card-body">
            
            <!-- Quick Actions -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <h5 class="mb-3"><i class="fas fa-bolt me-2"></i>Quick Actions</h5>
                    
                    <div class="list-group">
                        <a href="cache-admin.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-chart-pie me-2"></i>Cache Dashboard
                            <br><small class="text-muted">Monitor cache status, stats, and logs</small>
                        </a>
                        <a href="cache-admin.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-sync me-2"></i>Refresh All Caches
                            <br><small class="text-muted">Manually trigger immediate refresh</small>
                        </a>
                        <a href="cache-setup.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-check-circle me-2"></i>Verify Setup
                            <br><small class="text-muted">Check installation status</small>
                        </a>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <h5 class="mb-3"><i class="fas fa-gears me-2"></i>System Status</h5>
                    
                    <div class="alert alert-info">
                        <p class="mb-1"><strong>Cache Directory:</strong></p>
                        <code style="font-size: 0.85em;">crm/billing/getdata/cache/</code>
                    </div>
                    
                    <div class="alert alert-info">
                        <p class="mb-1"><strong>Logs Directory:</strong></p>
                        <code style="font-size: 0.85em;">crm/billing/logs/</code>
                    </div>
                </div>
            </div>
            
            <hr>
            
            <!-- CLI Commands -->
            <div class="mb-4">
                <h5><i class="fas fa-terminal me-2"></i>Command Line Reference</h5>
                
                <table class="table table-sm table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>Command</th>
                            <th>Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>php cache-manager.php refresh</code></td>
                            <td>Refresh all cache data</td>
                        </tr>
                        <tr>
                            <td><code>php cache-manager.php clear</code></td>
                            <td>Clear all cache files</td>
                        </tr>
                        <tr>
                            <td><code>php cache-manager.php status</code></td>
                            <td>Show cache status and logs</td>
                        </tr>
                        <tr>
                            <td><code>bash setup-cron.sh</code></td>
                            <td>Setup Linux cron jobs (Linux only)</td>
                        </tr>
                        <tr>
                            <td><code>setup-task-scheduler.bat</code></td>
                            <td>Setup Windows Task Scheduler (Windows only, run as Admin)</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <hr>
            
            <!-- API Endpoints -->
            <div class="mb-4">
                <h5><i class="fas fa-cloud-download-alt me-2"></i>API Endpoints (for developers)</h5>
                
                <table class="table table-sm table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>Endpoint</th>
                            <th>Returns</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>getdata/cache-api.php?key=olt_all</code></td>
                            <td>All OLT systems (< 50ms)</td>
                        </tr>
                        <tr>
                            <td><code>getdata/cache-api.php?key=server_all</code></td>
                            <td>All servers (< 50ms)</td>
                        </tr>
                        <tr>
                            <td><code>getdata/cache-api.php?key=user_all</code></td>
                            <td>All users (< 50ms)</td>
                        </tr>
                        <tr>
                            <td><code>getdata/cache-api.php?key=odp_all</code></td>
                            <td>All ODPs (< 50ms)</td>
                        </tr>
                        <tr>
                            <td><code>getdata/cache-api.php?key=area_all</code></td>
                            <td>All areas (< 50ms)</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <hr>
            
            <!-- Cache Schedule -->
            <div class="mb-4">
                <h5><i class="fas fa-calendar-alt me-2"></i>Automatic Refresh Schedule</h5>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="card bg-light">
                            <div class="card-body">
                                <h6 class="card-title"><i class="fas fa-clock me-2"></i>Default Schedule</h6>
                                <ul class="mb-0">
                                    <li>Every 5 min: Quick refresh</li>
                                    <li>Every 30 min: Full refresh</li>
                                    <li>Daily 2 AM: Cleanup</li>
                                    <li>Weekly 3 AM: Full sync</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card bg-light">
                            <div class="card-body">
                                <h6 class="card-title"><i class="fas fa-hourglass-half me-2"></i>Cache TTL</h6>
                                <ul class="mb-0">
                                    <li>OLTs: 10 minutes</li>
                                    <li>Servers: 10 minutes</li>
                                    <li>Users: 30 minutes</li>
                                    <li>ODPs: 15 minutes</li>
                                    <li>Areas: 1 hour</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <hr>
            
            <!-- Troubleshooting -->
            <div class="mb-4">
                <h5><i class="fas fa-exclamation-triangle me-2"></i>Troubleshooting Quick Tips</h5>
                
                <div class="accordion" id="troubleAccordion">
                    
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#trouble1">
                                Pages still loading slow?
                            </button>
                        </h2>
                        <div id="trouble1" class="accordion-collapse collapse" data-bs-parent="#troubleAccordion">
                            <div class="accordion-body">
                                <ol>
                                    <li>Visit <a href="cache-admin.php">cache-admin.php</a> and click "Refresh All Caches"</li>
                                    <li>Check if cache files exist: <code>crm/billing/getdata/cache/</code></li>
                                    <li>Run: <code>php cache-manager.php refresh</code></li>
                                    <li>Check browser console (F12) for error messages</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                    
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#trouble2">
                                "Cache not available" error?
                            </button>
                        </h2>
                        <div id="trouble2" class="accordion-collapse collapse" data-bs-parent="#troubleAccordion">
                            <div class="accordion-body">
                                This is normal when cache is being refreshed. Usually resolves in 5-10 seconds.
                                <ol>
                                    <li>Wait a moment and refresh the page</li>
                                    <li>Check if refresh is stuck: <code>php cache-manager.php status</code></li>
                                    <li>If stuck, clear: <code>php cache-manager.php clear</code></li>
                                    <li>Then refresh: <code>php cache-manager.php refresh</code></li>
                                </ol>
                            </div>
                        </div>
                    </div>
                    
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#trouble3">
                                Cache not auto-refreshing?
                            </button>
                        </h2>
                        <div id="trouble3" class="accordion-collapse collapse" data-bs-parent="#troubleAccordion">
                            <div class="accordion-body">
                                Check if Task Scheduler or Cron is running:
                                <ul>
                                    <li><strong>Windows:</strong> Open Task Scheduler, search "QTS", verify tasks exist</li>
                                    <li><strong>Linux:</strong> Run <code>crontab -l</code> to see scheduled jobs</li>
                                    <li><strong>Check logs:</strong> <code>tail -f crm/billing/logs/cache-refresh.log</code></li>
                                    <li><strong>Re-setup:</strong> Run setup script again</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#trouble4">
                                How to completely reset cache?
                            </button>
                        </h2>
                        <div id="trouble4" class="accordion-collapse collapse" data-bs-parent="#troubleAccordion">
                            <div class="accordion-body">
                                <code>php cache-manager.php clear</code>
                                <br>Then:
                                <code>php cache-manager.php refresh</code>
                            </div>
                        </div>
                    </div>
                    
                </div>
            </div>
            
            <hr>
            
            <!-- Documentation Links -->
            <div class="mb-4">
                <h5><i class="fas fa-book me-2"></i>Documentation</h5>
                
                <div class="row">
                    <div class="col-md-6">
                        <a href="#" class="btn btn-outline-primary btn-sm w-100 mb-2" onclick="alert('See CACHE_SETUP_GUIDE.md in crm/billing/'); return false;">
                            <i class="fas fa-file-alt me-2"></i>Detailed Setup Guide
                        </a>
                    </div>
                    <div class="col-md-6">
                        <a href="#" class="btn btn-outline-primary btn-sm w-100 mb-2" onclick="alert('See README_CACHE_SYSTEM.md in crm/billing/'); return false;">
                            <i class="fas fa-readme me-2"></i>System README
                        </a>
                    </div>
                </div>
            </div>
            
            <hr>
            
            <!-- Footer -->
            <div class="alert alert-success mb-0">
                <strong>✓ Cache System Active</strong>
                <br>All pages using background cache for instant load times.
                <a href="cache-admin.php" class="alert-link">View Dashboard →</a>
            </div>
            
        </div>
    </div>
</div>

<?php require 'footer.php'; ?>
