<?php
require 'header.php';

if ($AKSES == 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('Informasi_Server_ACS', $akses_menu, true)) {
        echo '<div class="container-fluid py-4"><div class="alert alert-danger">Anda tidak memiliki akses ke menu Informasi Server ACS.</div></div>';
        require 'footer.php';
        exit;
    }
}

require 'acs_helper.php';

// Initialize ACS helper
$acs = new ACSHelper($conn, $USER_ID, $AKSES);

// Ensure database is ready
$acs->ensureDatabase();

// Get PEMILIK for filtering
$userlogin = isset($_SESSION['PEMILIK']) ? $_SESSION['PEMILIK'] : '';

// Get all available servers
$servers = $acs->getServers();
?>

    <style>
        .info-row {
            padding: 15px;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
        }
        .info-row:last-child {
            border-bottom: none;
        }
        .info-label {
            font-weight: bold;
            width: 200px;
            color: #666;
        }
        .info-value {
            flex: 1;
            font-size: 18px;
        }
        .status-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 8px;
        }
        .status-running {
            background: #28a745;
            box-shadow: 0 0 10px #28a745;
        }
        .status-stopped {
            background: #dc3545;
        }
        .quick-action-card {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            margin-bottom: 15px;
            transition: transform 0.2s;
        }
        .quick-action-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .quick-action-card i {
            font-size: 36px;
            margin-bottom: 10px;
        }
        
        /* Input styles */
        .input-group input[readonly] {
            background-color: #f5f5f5;
            cursor: default;
        }
        
        .card-body .form-label {
            margin-bottom: 0.5rem;
        }

        /* ACS dark theme overrides */
        body.app-theme-dark .card,
        body.app-theme-dark .card-body,
        body.app-theme-dark .quick-action-card,
        body.app-theme-dark .modal-content,
        body.app-theme-dark pre,
        body.app-theme-dark code {
            background: #0f172a !important;
            color: #e5e7eb !important;
            border-color: rgba(148, 163, 184, 0.35) !important;
        }
        body.app-theme-dark .text-muted,
        body.app-theme-dark p,
        body.app-theme-dark li,
        body.app-theme-dark span,
        body.app-theme-dark h1,
        body.app-theme-dark h2,
        body.app-theme-dark h3,
        body.app-theme-dark h4,
        body.app-theme-dark h5,
        body.app-theme-dark h6,
        body.app-theme-dark label {
            color: #e5e7eb !important;
        }
        body.app-theme-dark a:not(.btn) {
            color: #7dd3fc !important;
        }
        body.app-theme-dark .input-group input[readonly] {
            background-color: #1e293b !important;
            color: #e5e7eb !important;
            border-color: rgba(148, 163, 184, 0.35) !important;
        }
        body.app-theme-dark .border-primary {
            border-color: #667eea !important;
        }

        .acs-config-info-box {
            margin: 12px 8px 0 8px;
            width: calc(100% - 16px);
            box-sizing: border-box;
            overflow: hidden;
            word-break: break-word;
        }

        .acs-config-info-box ol {
            margin: 0;
            padding-left: 1.25rem;
            list-style-position: inside;
        }

        .acs-config-info-box li {
            padding-left: 0;
            text-indent: 0;
            white-space: normal;
            overflow-wrap: anywhere;
        }

        .acs-notes-box {
            width: 100%;
            box-sizing: border-box;
            overflow: hidden;
            word-break: break-word;
        }

        .acs-notes-box ul {
            margin: 0;
            padding-left: 1.1rem;
            list-style-position: inside;
        }

        .acs-notes-box li {
            white-space: normal;
            overflow-wrap: anywhere;
        }
    </style>

    <div class="container-fluid py-4 px-3 px-md-4">

    
    
        <?php if (empty($servers)): ?>
            <div class="card">
                <div class="card-header dashboard-dark-header theme-aware-header">
                    <h4><i class="fas fa-server"></i> Konfigurasi ACS untuk ONT Pelanggan</h4>
                </div>
                <div class="card-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i> 
                        <strong>Tidak ada server ACS</strong><br>
                        Belum ada server ACS yang tersedia pada sistem.
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="card">
                <div class="card-header dashboard-dark-header theme-aware-header">
                    <h4><i class="fas fa-server"></i> Konfigurasi ACS untuk ONT Pelanggan</h4>
                </div>
              
                    <p class="text-muted mb-4"><i class="fas fa-info-circle"></i> Berikut adalah informasi konfigurasi yang diperlukan untuk mengatur ONT pelanggan agar terhubung ke server ACS.</p>
                    
                   
                        <?php foreach ($servers as $server): 
                            // Parse domain for URL
                            $domain_value = trim($server['domain']);
                            if (stripos($domain_value, 'http://') === 0 || stripos($domain_value, 'https://') === 0) {
                                $parts = parse_url($domain_value);
                                $domain_host = isset($parts['host']) ? $parts['host'] : $domain_value;
                            } else {
                                $domain_host = $domain_value;
                            }
                            
                            // Port bundle: use stored per-server ports (external servers may not
                            // follow the sequential base/base+1/base+2/base+3 scheme)
                            $base_port = isset($server['ui_port']) && $server['ui_port'] !== null ? intval($server['ui_port']) : intval($server['port']);
                            $cwmp_port = isset($server['cwmp_port']) && $server['cwmp_port'] !== null ? intval($server['cwmp_port']) : $base_port + 1;
                            $nbi_port = isset($server['nbi_port']) && $server['nbi_port'] !== null ? intval($server['nbi_port']) : $base_port + 2;
                            $fs_port = isset($server['fs_port']) && $server['fs_port'] !== null ? intval($server['fs_port']) : $base_port + 3;
                            
                            // CWMP URL untuk ONT
                            $cwmp_url = 'http://' . $domain_host . ':' . $cwmp_port . '/';
                        ?>
                       
                            <div class="card border-primary">
                                <div class="card-header dashboard-dark-header theme-aware-header">
                                    <h5 class="mb-0">
                                        <i class="fas fa-network-wired"></i> 
                                        <?php echo htmlspecialchars($server['nama_server']); ?>
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <!-- Server Domain -->
                                    <div class="mb-3">
                                        <label class="form-label fw-bold"><i class="fas fa-globe"></i> Server Address:</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($domain_host); ?>" readonly>
                                            <button class="btn btn-outline-secondary" onclick="copyText('<?php echo htmlspecialchars($domain_host); ?>')">
                                                <i class="fas fa-copy"></i>
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <!-- CWMP Port -->
                                    <div class="mb-3">
                                        <label class="form-label fw-bold"><i class="fas fa-port"></i> CWMP Port (Provisioning):</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control" value="<?php echo $cwmp_port; ?>" readonly>
                                            <button class="btn btn-outline-secondary" onclick="copyText('<?php echo $cwmp_port; ?>')">
                                                <i class="fas fa-copy"></i>
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <!-- Full CWMP URL -->
                                    <div class="mb-3">
                                        <label class="form-label fw-bold"><i class="fas fa-link"></i> CWMP URL:</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($cwmp_url); ?>" readonly style="font-size: 0.85rem;">
                                            <button class="btn btn-outline-secondary" onclick="copyText('<?php echo htmlspecialchars($cwmp_url); ?>')">
                                                <i class="fas fa-copy"></i>
                                            </button>
                                        </div>
                                        <small class="text-muted">Masukkan URL ini di konfigurasi ONT (TR-069 ACS URL)</small>
                                    </div>
                                    
                                    <!-- Configuration Info Box -->
                                    <div class="alert alert-info mt-3 acs-config-info-box">
                                        <h6><i class="fas fa-cog"></i> Langkah Konfigurasi:</h6>
                                        <ol class="mb-0 small">
                                            <li>Login ke ONT pelanggan (biasanya 192.168.1.1)</li>
                                            <li>Cari menu <strong>TR-069 / ACS Configuration</strong></li>
                                            <li>Masukkan CWMP URL diatas</li>
                                            <li>Sistem akan auto-konfigurasi via ACS server</li>
                                        </ol>
                                    </div>
                                    
                                    <!-- Status Badge -->
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Status Server:</label><br>
                                        <span class="badge bg-<?php echo $server['status'] == 'running' ? 'success' : 'danger'; ?>">
                                            <i class="fas fa-circle"></i> <?php echo ucfirst($server['status']); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                       
                        <?php endforeach; ?>
                    
              
            </div>

            <!-- Notes Section -->
            <div class="card mt-4">
                <div class="card-header dashboard-dark-header theme-aware-header">
                    <h5><i class="fas fa-book"></i> Catatan Penting untuk Pelanggan</h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-warning acs-notes-box">
                        <h6><i class="fas fa-exclamation-triangle"></i> Persyaratan ONT</h6>
                        <ul class="mb-0 small">
                            <li>ONT harus support protokol TR-069 (CPE WAN Management Protocol)</li>
                            <li>ONT harus memiliki akses internet ke server ACS</li>
                            <li>Firewall harus mengizinkan koneksi ke port CWMP (<?php echo $cwmp_port ?? '7547'; ?>)</li>
                            <li>Konfigurasi CWMP biasanya dilakukan otomatis saat ONT pertama kali boot</li>
                        </ul>
                    </div>
                    
                    <div class="alert alert-info acs-notes-box">
                        <h6><i class="fas fa-lightbulb"></i> Troubleshooting</h6>
                        <ul class="mb-0 small">
                            <li><strong>ONT tidak terhubung?</strong> Cek koneksi internet dan pastikan CWMP URL benar</li>
                            <li><strong>Konfigurasi tidak tersimpan?</strong> ONT mungkin restart, tunggu beberapa menit</li>
                            <li><strong>Butuh bantuan?</strong> Hubungi technical support dengan nomor seri ONT</li>
                        </ul>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function copyText(text) {
            navigator.clipboard.writeText(text).then(() => {
                alert('Disalin ke clipboard!');
            }).catch(err => {
                alert('Gagal menyalin: ' + err);
            });
        }
    </script>
<?php require 'footer.php'; ?>
