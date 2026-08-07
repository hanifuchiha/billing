<?php
ob_start();
// Otomatis tambahkan kolom allowed_ips jika belum ada
$col_check = mysqli_query($conn, "SHOW COLUMNS FROM acs_servers LIKE 'allowed_ips'");
if (!$col_check || mysqli_num_rows($col_check) === 0) {
    mysqli_query($conn, "ALTER TABLE acs_servers ADD COLUMN allowed_ips TEXT NULL AFTER password_acs");
}
require 'header.php';
require 'acs_helper.php';

// Initialize ACS helper
$acs = new ACSHelper($conn, $USER_ID, $AKSES);

// Ensure database is ready
$acs->ensureDatabase();

// Get PEMILIK for filtering
$userlogin = isset($_SESSION['PEMILIK']) ? $_SESSION['PEMILIK'] : '';

function normalizeAcsBaseUrl($rawUrl)
{
    $domain = trim((string)$rawUrl);
    if ($domain === '') {
        return 'http://127.0.0.1';
    }
    if (stripos($domain, 'http://') !== 0 && stripos($domain, 'https://') !== 0) {
        $domain = 'https://' . ltrim($domain, '/');
    }
    return rtrim($domain, '/');
}

$acs_sync_cache_file = __DIR__ . '/notifdata/acs_devices_cache.json';
$acs_sync_last = null;
$acs_sync_count = 0;
$acs_sync_enabled = false;
$acs_sync_cron_job = '';
$acs_sync_alert = '';

if ($AKSES === 'ADMIN') {
    $cfg_acs = file_exists('config.json') ? json_decode(file_get_contents('config.json'), true) : [];
    $acs_base_url = normalizeAcsBaseUrl($cfg_acs['URL'] ?? '');
    $acs_sync_cron_job = "* * * * * curl -s {$acs_base_url}/crm/billing/acs_sync_worker.php > /dev/null 2>&1";

    $current_cron = shell_exec("crontab -u www-data -l 2>&1");
    if (!is_string($current_cron) || stripos($current_cron, 'no crontab for') !== false) {
        $current_cron = '';
    }
    $acs_sync_enabled = strpos($current_cron, 'acs_sync_worker.php') !== false;

    if (file_exists($acs_sync_cache_file)) {
        $acs_cache_raw = file_get_contents($acs_sync_cache_file);
        $acs_cache_json = json_decode($acs_cache_raw, true);
        if (is_array($acs_cache_json)) {
            $acs_sync_last = $acs_cache_json['synced_at'] ?? null;
            $acs_sync_count = (int)($acs_cache_json['devices_count'] ?? 0);
        }
    }

    if (isset($_POST['toggle_acs_sync'])) {
        $enable = isset($_POST['acs_sync_enabled']) && $_POST['acs_sync_enabled'] === '1';

        if ($enable) {
            if (strpos($current_cron, 'acs_sync_worker.php') === false) {
                $new_cron = trim($current_cron);
                $new_cron .= ($new_cron !== '' ? "\n" : '') . $acs_sync_cron_job . "\n";
                file_put_contents('/tmp/acs_sync_cron.txt', $new_cron);
                shell_exec('crontab -u www-data /tmp/acs_sync_cron.txt');
                @unlink('/tmp/acs_sync_cron.txt');
            }
            $acs_sync_enabled = true;
            $acs_sync_alert = "<div class='alert alert-success mb-3'>ACS Auto-Sync aktif. Crontab berjalan tiap 1 menit dan cache lokal diperbarui otomatis.</div>";
        } else {
            $lines = preg_split('/\r\n|\r|\n/', $current_cron);
            $filtered = array_filter($lines, function ($line) {
                return trim($line) !== '' && strpos($line, 'acs_sync_worker.php') === false;
            });
            $new_cron = implode("\n", $filtered) . (count($filtered) ? "\n" : '');
            file_put_contents('/tmp/acs_sync_cron.txt', $new_cron);
            shell_exec('crontab -u www-data /tmp/acs_sync_cron.txt');
            @unlink('/tmp/acs_sync_cron.txt');
            $acs_sync_enabled = false;
            $acs_sync_alert = "<div class='alert alert-info mb-3'>ACS Auto-Sync dinonaktifkan.</div>";
        }
    }

    if (isset($_POST['manual_acs_sync'])) {
        $ch = curl_init($acs_base_url . '/crm/billing/acs_sync_worker.php');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $response_data = json_decode($response, true);
        if ($http_code === 200 && is_array($response_data) && ($response_data['status'] ?? '') === 'success') {
            $acs_sync_last = $response_data['synced_at'] ?? null;
            $acs_sync_count = (int)($response_data['devices_count'] ?? 0);
            $acs_sync_alert = "<div class='alert alert-success mb-3'>Sync manual berhasil: <strong>{$acs_sync_count}</strong> device, waktu: <strong>{$acs_sync_last}</strong></div>";
        } else {
            $error_message = is_array($response_data) ? ($response_data['error'] ?? $response) : $response;
            $acs_sync_alert = "<div class='alert alert-danger mb-3'>Sync gagal (HTTP {$http_code}): " . htmlspecialchars((string)$error_message) . "</div>";
        }
    }
}

// Handle AJAX requests
if (isset($_POST['action'])) {
    if (ob_get_length()) {
        ob_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    
    $action = $_POST['action'];
    $server_id = isset($_POST['server_id']) ? intval($_POST['server_id']) : 0;
    
    switch ($action) {
        case 'start':
        case 'stop':
        case 'restart':
        case 'delete':
            $result = $acs->dockerOperation($server_id, $action);
            echo json_encode($result);
            exit;
            
        case 'sync':
            $result = $acs->syncDevices($server_id);
            echo json_encode($result);
            exit;

        case 'repair_forwarding':
            $result = $acs->repairPortForwarding($server_id);
            echo json_encode($result);
            exit;
            
        case 'refresh_status':
            $server = $acs->getServer($server_id);
            if ($server) {
                $status = $acs->getContainerStatus($server['container_name']);
                mysqli_query($conn, "UPDATE acs_servers SET status = '$status' WHERE id = $server_id");
                echo json_encode(['success' => true, 'status' => $status]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Server not found']);
            }
            exit;

        case 'get_tasks':
            $server = $acs->getServer($server_id);
            if (!$server) {
                echo json_encode(['success' => false, 'message' => 'Server not found']);
                exit;
            }
            $d = trim((string)($server['domain'] ?? ''));
            if (stripos($d, 'http://') === 0 || stripos($d, 'https://') === 0) {
                $parts = parse_url($d);
                $d = $parts['host'] ?? $d;
            }
            $nbi_port = isset($server['nbi_port']) && $server['nbi_port'] !== null ? intval($server['nbi_port']) : intval($server['port']) + 2;
            $tasks_url = 'http://' . $d . ':' . $nbi_port . '/tasks';
            $ch_t = curl_init($tasks_url);
            curl_setopt_array($ch_t, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_CONNECTTIMEOUT => 5,
            ]);
            $u_t = trim((string)($server['username_acs'] ?? ''));
            $p_t = trim((string)($server['password_acs'] ?? ''));
            if ($u_t !== '') {
                curl_setopt($ch_t, CURLOPT_USERPWD, $u_t . ':' . $p_t);
                curl_setopt($ch_t, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            }
            $resp_t = curl_exec($ch_t);
            $code_t = curl_getinfo($ch_t, CURLINFO_HTTP_CODE);
            $cerr_t = curl_error($ch_t);
            curl_close($ch_t);
            $tasks_arr = ($code_t >= 200 && $code_t < 300) ? @json_decode((string)$resp_t, true) : [];
            echo json_encode([
                'success'    => true,
                'tasks'      => is_array($tasks_arr) ? $tasks_arr : [],
                'http_code'  => $code_t,
                'curl_error' => $cerr_t,
                'url'        => $tasks_url,
            ]);
            exit;

        case 'get_activity_log':
            $log_file_read = __DIR__ . '/notifdata/acs_activity_log.json';
            $logs_out = [];
            if (file_exists($log_file_read)) {
                $raw_l = json_decode((string)file_get_contents($log_file_read), true);
                if (is_array($raw_l) && isset($raw_l['logs'])) {
                    $logs_out = $raw_l['logs'];
                }
            }
            echo json_encode(['success' => true, 'logs' => array_slice($logs_out, 0, 100)]);
            exit;

        case 'update_allowed_ips':
            $allowed_ips_raw = isset($_POST['allowed_ips']) ? trim((string)$_POST['allowed_ips']) : '';
            $normalized_ips = '';

            if ($allowed_ips_raw !== '') {
                $parts = preg_split('/[\r\n,]+/', $allowed_ips_raw);
                $clean = [];
                foreach ($parts as $part) {
                    $ip = trim((string)$part);
                    if ($ip !== '') {
                        $clean[] = $ip;
                    }
                }
                $normalized_ips = implode("\n", array_values(array_unique($clean)));
            }

            $stmt = mysqli_prepare($conn, "UPDATE acs_servers SET allowed_ips = ? WHERE id = ?");
            if (!$stmt) {
                echo json_encode(['success' => false, 'message' => 'Prepare statement gagal']);
                exit;
            }

            mysqli_stmt_bind_param($stmt, 'si', $normalized_ips, $server_id);
            $ok = mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            if ($ok) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Allowed IPs berhasil diperbarui',
                    'allowed_ips' => $normalized_ips
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
            }
            exit;
    }
}

// Get all servers
$servers = $acs->getServers();

?>

    <style>
        .status-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 12px;
            font-weight: bold;
            line-height: 1.25;
            opacity: 1 !important;
            border: 1px solid rgba(255, 255, 255, 0.28);
            text-shadow: 0 1px 1px rgba(0, 0, 0, 0.28);
            color: #ffffff !important;
        }
        .status-running { background: #28a745; color: #ffffff !important; }
        .status-stopped { background: #dc3545; color: #ffffff !important; }
        .status-creating {
            background: #ffc107;
            color: #111827 !important;
            border-color: rgba(17, 24, 39, 0.28);
            text-shadow: none;
        }
        .status-error { background: #6c757d; color: #ffffff !important; }
        .status-not_found { background: #343a40; color: #ffffff !important; }
        .status-unknown { background: #6c757d; color: #ffffff !important; }
        .btn-action {
            padding: 5px 10px;
            font-size: 12px;
            margin: 2px;
        }
        /* ACS dark theme overrides */
        body.app-theme-dark .card,
        body.app-theme-dark .card-body,
        body.app-theme-dark .modal-content,
        body.app-theme-dark pre,
        body.app-theme-dark code {
            background: #0f172a !important;
            color: #e5e7eb !important;
            border-color: rgba(148, 163, 184, 0.35) !important;
        }
        body.app-theme-dark .table,
        body.app-theme-dark .table td,
        body.app-theme-dark .table th,
        body.app-theme-dark .table-responsive,
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
        body.app-theme-dark .table td,
        body.app-theme-dark .table th {
            border-color: rgba(148, 163, 184, 0.25) !important;
        }
        body.app-theme-dark .table-hover tbody tr:hover {
            background: rgba(59, 130, 246, 0.12) !important;
        }
        body.app-theme-dark .table-dark th {
            color: #f8fafc !important;
        }
        body.app-theme-dark .status-badge {
            color: #ffffff !important;
            border-color: rgba(148, 163, 184, 0.45);
        }
        body.app-theme-dark .status-badge.status-creating {
            color: #111827 !important;
            border-color: rgba(17, 24, 39, 0.32);
        }
        body.app-theme-dark a:not(.btn) {
            color: #7dd3fc !important;
        }
    </style>

    <div class="container-fluid py-4 px-3 px-md-4">

    
   
            <?php if ($AKSES === 'ADMIN'): ?>
            <div class="card mb-4 shadow-sm border-info">
                <div class="card-header dashboard-dark-header theme-aware-header d-flex align-items-center justify-content-between">
                    <h5 class="mb-0"><i class="fas fa-sync-alt me-2"></i>ACS Auto-Sync (Cache Lokal)</h5>
                    <span class="badge <?= $acs_sync_enabled ? 'bg-success' : 'bg-secondary' ?>">
                        <?= $acs_sync_enabled ? 'AKTIF' : 'NONAKTIF' ?>
                    </span>
                </div>
                <div class="card-body">
                    <?= $acs_sync_alert ?>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <div class="p-3 bg-light rounded h-100">
                                <div class="small text-muted mb-1">Status Cache</div>
                                <?php if ($acs_sync_last): ?>
                                    <strong class="text-success">Data tersedia</strong><br>
                                    <span class="small">Sync terakhir: <b><?= htmlspecialchars($acs_sync_last) ?></b></span><br>
                                    <span class="small">Total device: <b><?= $acs_sync_count ?></b></span>
                                <?php else: ?>
                                    <strong class="text-warning">Belum ada cache</strong><br>
                                    <span class="small">Aktifkan crontab atau jalankan sync manual.</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-3 bg-light rounded h-100">
                                <div class="small text-muted mb-1">Informasi</div>
                                <ul class="mb-0 small ps-3">
                                    <li>Data ACS disimpan di file lokal, bukan database.</li>
                                    <li>Cache berlaku 1 jam dan diperbarui otomatis tiap menit.</li>
                                    <li>Halaman tables.php membaca cache lokal saat polling data device.</li>
                                    <li>Semua parameter ACS disimpan tanpa pembatasan kolom database.</li>
                                </ul>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-3 bg-light rounded h-100">
                                <div class="small text-muted mb-1">Crontab www-data</div>
                                <code class="d-block small text-break"><?= htmlspecialchars($acs_sync_cron_job) ?></code>
                                <div class="small text-muted mt-1">Berjalan setiap 1 menit via crontab.</div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 flex-wrap">
                        <form method="post" action="" class="d-inline">
                            <input type="hidden" name="toggle_acs_sync" value="1">
                            <?php if ($acs_sync_enabled): ?>
                                <input type="hidden" name="acs_sync_enabled" value="0">
                                <button type="submit" class="btn btn-warning" onclick="return confirm('Nonaktifkan ACS Auto-Sync?')">
                                    <i class="fas fa-stop-circle me-1"></i> Nonaktifkan Crontab
                                </button>
                            <?php else: ?>
                                <input type="hidden" name="acs_sync_enabled" value="1">
                                <button type="submit" class="btn btn-success" onclick="return confirm('Aktifkan ACS Auto-Sync setiap menit?')">
                                    <i class="fas fa-play-circle me-1"></i> Aktifkan Crontab
                                </button>
                            <?php endif; ?>
                        </form>

                        <form method="post" action="" class="d-inline">
                            <input type="hidden" name="manual_acs_sync" value="1">
                            <button type="submit" class="btn btn-info" onclick="return confirm('Jalankan sync ACS sekarang?')">
                                <i class="fas fa-sync me-1"></i> Sync Manual Sekarang
                            </button>
                        </form>
                    </div>

                    <div class="form-text mt-2">
                        Data ACS diambil dari semua server GenieACS lalu disimpan ke file lokal <code>notifdata/acs_devices_cache.json</code> untuk dipakai ulang oleh halaman monitoring device.
                    </div>
                </div>
            </div>
            <?php endif; ?>

        <div class="card">
            <div class="card-header dashboard-dark-header theme-aware-header">
                <h4><i class="fas fa-server"></i> Daftar Server ACS</h4>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <a href="acs_add_server.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Tambah Server Baru
                    </a>
                    <a href="acs_install_db.php" class="btn btn-secondary">
                        <i class="fas fa-database"></i> Check Database
                    </a>
                </div>

                <?php if (empty($servers)): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> Belum ada server ACS. Silakan tambah server baru.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover">
                            <thead class="table-dark">
                                <tr>
                                    <th>ID</th>
                                    <th>Nama Server</th>
                                    <th>Domain / IP</th>
                                    <th>Port (Bundle)</th>
                                    <th>URL ACS</th>
                                    <th>Container</th>
                                    <th>Owner</th>
                                    <th>Status</th>
                                    <th>Username ACS</th>
                                    <th>Allowed IPs</th>
                                    <th>Link Login</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($servers as $server): ?>
                                    <?php
                                        $domain_value = trim((string)$server['domain']);
                                        if (stripos($domain_value, 'http://') === 0 || stripos($domain_value, 'https://') === 0) {
                                            $parts = parse_url($domain_value);
                                            $domain_host = isset($parts['host']) ? $parts['host'] : $domain_value;
                                        } else {
                                            $domain_host = $domain_value;
                                        }
                                        $is_external = !empty($server['is_external']);
                                        $ui_port = isset($server['ui_port']) && $server['ui_port'] !== null ? intval($server['ui_port']) : intval($server['port']);
                                        $cwmp_port = isset($server['cwmp_port']) && $server['cwmp_port'] !== null ? intval($server['cwmp_port']) : $ui_port + 1;
                                        $nbi_port = isset($server['nbi_port']) && $server['nbi_port'] !== null ? intval($server['nbi_port']) : $ui_port + 2;
                                        $fs_port = isset($server['fs_port']) && $server['fs_port'] !== null ? intval($server['fs_port']) : $ui_port + 3;
                                        $base_port = $ui_port;
                                        $acs_url = 'http://' . $domain_host . ':' . $ui_port;
                                    ?>
                                    <tr id="server-<?php echo $server['id']; ?>">
                                        <td><?php echo $server['id']; ?></td>
                                        <td>
                                            <?php echo htmlspecialchars($server['nama_server']); ?>
                                            <?php if ($is_external): ?>
                                                <span class="badge bg-secondary" title="Server eksternal, tidak dikelola Docker di sini">Eksternal</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($domain_host); ?></td>
                                        <td>
                                            <strong><?php echo $ui_port; ?></strong><br>
                                            <small class="text-muted">
                                                UI:<?php echo $ui_port; ?> |
                                                CWMP:<?php echo $cwmp_port; ?><br>
                                                <strong>NBI:<?php echo $nbi_port; ?></strong> |
                                                FS:<?php echo $fs_port; ?>
                                            </small>
                                        </td>
                                        <td><code><?php echo htmlspecialchars($acs_url); ?></code></td>
                                        <td><code><?php echo $server['container_name']; ?></code></td>
                                        <td><?php echo htmlspecialchars($server['owner_username']); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo strtolower($server['status']); ?>" id="status-<?php echo $server['id']; ?>">
                                                <?php echo $server['status']; ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($server['username_acs']); ?></td>
                                        <td id="allowedips-<?php echo (int)$server['id']; ?>" style="max-width:180px; white-space:pre-line; font-size:12px;">
                                            <?php echo nl2br(htmlspecialchars(trim((string)($server['allowed_ips'] ?? '')))); ?>
                                        </td>
                                        <td>
                                            <a href="<?php echo htmlspecialchars($acs_url); ?>" 
                                               target="_blank" class="btn btn-sm btn-info">
                                                <i class="fas fa-external-link-alt"></i> Login
                                            </a>
                                        </td>
                                        <td>
                                            <?php if (!$is_external): ?>
                                            <button class="btn btn-success btn-action" onclick="dockerAction(<?php echo $server['id']; ?>, 'start')" title="Start">
                                                <i class="fas fa-play"></i>
                                            </button>
                                            <button class="btn btn-warning btn-action" onclick="dockerAction(<?php echo $server['id']; ?>, 'stop')" title="Stop">
                                                <i class="fas fa-stop"></i>
                                            </button>
                                            <button class="btn btn-info btn-action" onclick="dockerAction(<?php echo $server['id']; ?>, 'restart')" title="Restart">
                                                <i class="fas fa-sync"></i>
                                            </button>
                                            <?php endif; ?>
                                            <button class="btn btn-primary btn-action" onclick="syncDevices(<?php echo $server['id']; ?>)" title="Sync Devices">
                                                <i class="fas fa-download"></i>
                                            </button>
                                            <button class="btn btn-danger btn-action" onclick="dockerAction(<?php echo $server['id']; ?>, 'delete')" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                            <button class="btn btn-outline-dark btn-action" onclick="dockerAction(<?php echo $server['id']; ?>, 'repair_forwarding')" title="Perbaiki Port Forwarding MikroTik">
                                                <i class="fas fa-route"></i>
                                            </button>
                                            <button class="btn btn-secondary btn-action" onclick="refreshStatus(<?php echo $server['id']; ?>)" title="Refresh Status">
                                                <i class="fas fa-refresh"></i>
                                            </button>
                                            <button
                                                type="button"
                                                class="btn btn-dark btn-action"
                                                data-server-id="<?php echo (int)$server['id']; ?>"
                                                data-allowed-ips="<?php echo htmlspecialchars((string)($server['allowed_ips'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                                onclick="return openAllowedIpsModalFromButton(this)"
                                                title="Edit Allowed IPs"
                                            >
                                                <i class="fas fa-network-wired"></i> IP
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Server Details -->
        <div class="card mt-4">
            <div class="card-header dashboard-dark-header theme-aware-header">
                <h5><i class="fas fa-info-circle"></i> Informasi</h5>
            </div>
            <div class="card-body">
                <ul>
                    <li><strong>ADMIN</strong> dapat melihat dan mengelola semua server ACS</li>
                    <li><strong>ADMIN</strong> dapat membuat container baru dengan port 5000-5999</li>
                    <li><strong>ADMIN</strong> dapat start/stop/restart/delete container milik siapa saja</li>
                    <li>Setiap server menggunakan GenieACS dengan MongoDB</li>
                    <li>Port ACS harus unik dan tidak bentrok</li>
                    <li>Container naming format: <code>acs_[port]</code></li>
                    <li><strong>Port Bundle per Server:</strong>
                        <ul>
                            <li><strong>UI Port:</strong> base_port (untuk Web Interface)</li>
                            <li><strong>CWMP Port:</strong> base_port+1 (untuk TR-069 CPE WAN Management Protocol)</li>
                            <li><strong>NBI Port:</strong> base_port+2 (untuk North Bound Interface API - <em>digunakan untuk API calls</em>)</li>
                            <li><strong>FS Port:</strong> base_port+3 (untuk File Server)</li>
                        </ul>
                    </li>
                </ul>
            </div>
            
        </div>
    </div>

    <!-- Activity Log Card -->
    <div class="card mt-4" id="acsActivityCard">
        <div class="card-header dashboard-dark-header theme-aware-header d-flex align-items-center justify-content-between flex-wrap gap-2">
            <h5 class="mb-0"><i class="fas fa-list-alt me-2"></i>Log Aktivitas ACS</h5>
            <div class="d-flex gap-2 align-items-center flex-wrap">
                <select id="taskServerSelect" class="form-select form-select-sm" style="width:auto;min-width:180px;">
                    <option value="">-- Pilih Server --</option>
                    <?php foreach ($servers as $s): ?>
                        <option value="<?= (int)$s['id'] ?>">
                            <?= htmlspecialchars($s['nama_server'], ENT_QUOTES, 'UTF-8') ?> (NBI:<?= isset($s['nbi_port']) && $s['nbi_port'] !== null ? intval($s['nbi_port']) : intval($s['port'])+2 ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-sm btn-outline-light" onclick="loadLiveTasks()">
                    <i class="fas fa-broadcast-tower"></i> Live Tasks GenieACS
                </button>
                <button class="btn btn-sm btn-outline-light" onclick="loadActivityLog()">
                    <i class="fas fa-sync"></i> Refresh Log Lokal
                </button>
            </div>
        </div>
        <div class="card-body p-2">
            <ul class="nav nav-tabs mb-3" id="acsLogTabs">
                <li class="nav-item">
                    <button class="nav-link active" id="tab-activity" onclick="switchAcsTab('activity')">📋 Log Update WiFi</button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" id="tab-tasks" onclick="switchAcsTab('tasks')">📡 Task GenieACS (Live)</button>
                </li>
            </ul>
            <div id="panel-activity">
                <div id="activityLogContainer"><div class="text-muted text-center py-3">Klik "Refresh Log Lokal" untuk memuat.</div></div>
            </div>
            <div id="panel-tasks" style="display:none;">
                <div id="liveTasksContainer"><div class="text-muted text-center py-3">Pilih server lalu klik "Live Tasks GenieACS".</div></div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="allowedIpsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-network-wired"></i> Edit Allowed IPs</h5>
                    <button type="button" class="btn-close" onclick="closeAllowedIpsModal()" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="allowedIpsServerId" value="">
                    <label for="allowedIpsInput" class="form-label">Allowed IPs</label>
                    <textarea id="allowedIpsInput" class="form-control" rows="8" placeholder="Contoh:
103.21.4.5
192.168.1.0/24
0.0.0.0/0"></textarea>
                    <div class="mt-2 small">
                        <strong>Format yang didukung:</strong><br>
                        <code>103.21.4.5</code> — IP tunggal<br>
                        <code>192.168.1.0/24</code> — Subnet CIDR<br>
                        <code>0.0.0.0/0</code> — Izinkan semua IP<br>
                        <span class="text-muted">Kosongkan untuk kembali ke default (localhost saja).</span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeAllowedIpsModal()">Batal</button>
                    <button type="button" class="btn btn-primary" id="saveAllowedIpsBtn" onclick="saveAllowedIps()">
                        <i class="fas fa-save"></i> Simpan
                    </button>
                </div>
            </div>
        </div>
    </div>
    <!-- Backdrop untuk modal Allowed IPs -->
    <div id="allowedIpsBackdrop" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:1040;" onclick="closeAllowedIpsModal()"></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function escH(v) {
            return String(v == null ? '' : v)
                .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

        function switchAcsTab(tab) {
            document.getElementById('panel-activity').style.display = tab === 'activity' ? '' : 'none';
            document.getElementById('panel-tasks').style.display   = tab === 'tasks'    ? '' : 'none';
            document.getElementById('tab-activity').classList.toggle('active', tab === 'activity');
            document.getElementById('tab-tasks').classList.toggle('active',    tab === 'tasks');
        }

        function openAllowedIpsModal(serverId, allowedIps) {
            document.getElementById('allowedIpsServerId').value = String(serverId || '');
            document.getElementById('allowedIpsInput').value = String(allowedIps || '');
            var modalEl = document.getElementById('allowedIpsModal');
            var backdrop = document.getElementById('allowedIpsBackdrop');
            if (!modalEl || !backdrop) return;
            modalEl.style.display = 'block';
            modalEl.style.position = 'fixed';
            modalEl.style.zIndex = '1050';
            modalEl.classList.add('show');
            backdrop.style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        function openAllowedIpsModalFromButton(btn) {
            if (!btn) return false;
            var serverId = btn.getAttribute('data-server-id') || '';
            var allowedIps = btn.getAttribute('data-allowed-ips') || '';
            openAllowedIpsModal(serverId, allowedIps);
            return false;
        }

        function closeAllowedIpsModal() {
            var modalEl = document.getElementById('allowedIpsModal');
            var backdrop = document.getElementById('allowedIpsBackdrop');
            if (modalEl) {
                modalEl.style.display = 'none';
                modalEl.classList.remove('show');
            }
            if (backdrop) backdrop.style.display = 'none';
            document.body.style.overflow = '';
        }

        document.addEventListener('keydown', function(evt) {
            if (evt.key === 'Escape') {
                closeAllowedIpsModal();
            }
        });

        function saveAllowedIps() {
            var serverId = document.getElementById('allowedIpsServerId').value;
            if (!serverId) {
                alert('Server tidak valid');
                return;
            }

            var input = document.getElementById('allowedIpsInput');
            var btn = document.getElementById('saveAllowedIpsBtn');
            var originalHtml = btn.innerHTML;

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menyimpan...';

            var payload = new URLSearchParams();
            payload.append('action', 'update_allowed_ips');
            payload.append('server_id', serverId);
            payload.append('allowed_ips', input ? input.value : '');

            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: payload.toString()
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (!data.success) {
                    alert(data.message || 'Gagal update Allowed IPs');
                    return;
                }

                var preview = document.getElementById('allowedips-' + serverId);
                if (preview) {
                    var val = String(data.allowed_ips || '').trim();
                    preview.innerHTML = val ? escH(val).replace(/\n/g, '<br>') : '';
                }

                alert(data.message || 'Allowed IPs berhasil disimpan');
                closeAllowedIpsModal();
            })
            .catch(function(err) {
                alert('Error: ' + err);
            })
            .finally(function() {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            });
        }

        function getTaskReason(task) {
            var status = String(task && task.status ? task.status : 'pending').toLowerCase();
            if (status === 'done') {
                return 'Task sudah dieksekusi oleh CPE.';
            }

            if (status === 'fault') {
                var faultText = task && task.fault ? JSON.stringify(task.fault) : '';
                return faultText ? ('Task fault: ' + faultText) : 'Task fault di GenieACS (cek detail fault).';
            }

            var retries = parseInt((task && (task.retries || task.retryCount || task.retry_count)) || '0', 10);
            if (!isNaN(retries) && retries > 0) {
                return 'Masih pending, connection request/retry sudah dicoba ' + retries + ' kali.';
            }

            var tsRaw = task && (task.timestamp || task.createdAt || task._created);
            if (tsRaw) {
                var createdMs = Date.parse(tsRaw);
                if (!isNaN(createdMs)) {
                    var ageMin = Math.floor((Date.now() - createdMs) / 60000);
                    if (ageMin >= 10) {
                        return 'Pending > ' + ageMin + ' menit: ONT kemungkinan belum inform/online atau CWMP tidak terjangkau.';
                    }
                }
            }

            return 'Menunggu ONT/CPE inform ke ACS agar task dieksekusi.';
        }
        

        function loadActivityLog() {
            var el = document.getElementById('activityLogContainer');
            el.innerHTML = '<div class="text-center py-3"><i class="fas fa-spinner fa-spin"></i> Memuat log...</div>';
            fetch('', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'action=get_activity_log' })
                .then(function(r) { return r.text(); })
                .then(function(raw) {
                    var data;
                    try {
                        data = JSON.parse(raw);
                    } catch (e) {
                        el.innerHTML = '<div class="alert alert-danger">Response bukan JSON valid: <code>' + escH(String(raw || '').slice(0, 300)) + '</code></div>';
                        return;
                    }
                    if (!data.success || !data.logs || !data.logs.length) {
                        el.innerHTML = '<div class="text-muted text-center py-3">Belum ada log aktivitas. Coba update WiFi melalui halaman pelanggan.</div>';
                        return;
                    }
                    var html = '<div class="table-responsive"><table class="table table-sm table-bordered mb-0"><thead class="table-dark"><tr><th>Waktu</th><th>Server</th><th>Serial</th><th>Parameter yang Diubah</th><th>Actor</th><th>Task ID</th></tr></thead><tbody>';
                    data.logs.forEach(function(log) {
                        var params = Array.isArray(log.params)
                            ? log.params.map(function(p) { return '<code>' + escH(p) + '</code>'; }).join('<br>')
                            : '-';
                        var taskShort = log.task_id ? log.task_id.slice(-10) : '-';
                        html += '<tr>';
                        html += '<td class="text-nowrap">' + escH(log.time) + '</td>';
                        html += '<td>' + escH(log.server_name) + '</td>';
                        html += '<td><code style="font-size:11px;">' + escH(log.serial) + '</code></td>';
                        html += '<td style="font-size:12px;">' + params + '</td>';
                        html += '<td>' + escH(log.actor) + '</td>';
                        html += '<td><small title="' + escH(log.task_id) + '">' + escH(taskShort) + '</small></td>';
                        html += '</tr>';
                    });
                    html += '</tbody></table></div>';
                    el.innerHTML = html;
                })
                .catch(function(err) { el.innerHTML = '<div class="alert alert-danger">Error: ' + err + '</div>'; });
        }

        function loadLiveTasks() {
            var serverId = document.getElementById('taskServerSelect').value;
            if (!serverId) { alert('Pilih server terlebih dahulu.'); return; }
            switchAcsTab('tasks');
            var el = document.getElementById('liveTasksContainer');
            el.innerHTML = '<div class="text-center py-3"><i class="fas fa-spinner fa-spin"></i> Memuat task dari GenieACS NBI...</div>';
            fetch('', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'action=get_tasks&server_id=' + serverId })
                .then(function(r) { return r.text(); })
                .then(function(raw) {
                    var data;
                    try {
                        data = JSON.parse(raw);
                    } catch (e) {
                        el.innerHTML = '<div class="alert alert-danger">Response bukan JSON valid: <code>' + escH(String(raw || '').slice(0, 300)) + '</code></div>';
                        return;
                    }
                    if (!data.success) {
                        el.innerHTML = '<div class="alert alert-danger">' + escH(data.message || 'Gagal mengambil task') + '</div>';
                        return;
                    }
                    if (!data.tasks || !data.tasks.length) {
                        el.innerHTML = '<div class="text-muted text-center py-3">Tidak ada task pending di GenieACS. <small>(HTTP ' + data.http_code + ' | ' + escH(data.url) + ')</small></div>';
                        return;
                    }
                    var html = '<div class="text-muted small mb-2">URL: <code>' + escH(data.url) + '</code> &mdash; HTTP ' + data.http_code + ' &mdash; ' + data.tasks.length + ' task</div>';
                    html += '<div class="table-responsive"><table class="table table-sm table-bordered mb-0"><thead class="table-dark"><tr><th>Task ID</th><th>Device</th><th>Nama Task</th><th>Parameter / Value</th><th>Status</th><th>Alasan</th></tr></thead><tbody>';
                    data.tasks.forEach(function(task) {
                        var params = '-';
                        if (Array.isArray(task.parameterValues)) {
                            params = task.parameterValues.map(function(pv) {
                                return '<code>' + escH(pv[0]) + '</code> = <em>' + escH(pv[1]) + '</em>';
                            }).join('<br>');
                        } else if (task.parameterNames) {
                            params = Array.isArray(task.parameterNames)
                                ? task.parameterNames.map(function(n) { return '<code>' + escH(n) + '</code>'; }).join('<br>')
                                : escH(String(task.parameterNames));
                        }
                        var taskId = String(task._id || '-');
                        var reason = getTaskReason(task);
                        html += '<tr>';
                        html += '<td><small title="' + escH(taskId) + '">' + escH(taskId.slice(-10)) + '</small></td>';
                        html += '<td><small>' + escH(task.device || '-') + '</small></td>';
                        html += '<td><strong>' + escH(task.name || '-') + '</strong></td>';
                        html += '<td style="font-size:12px;">' + params + '</td>';
                        html += '<td><span class="badge bg-' + (task.status === 'fault' ? 'danger' : (task.status === 'done' ? 'success' : 'warning text-dark')) + '">' + escH(task.status || 'pending') + '</span></td>';
                        html += '<td style="font-size:12px;">' + escH(reason) + '</td>';
                        html += '</tr>';
                    });
                    html += '</tbody></table></div>';
                    el.innerHTML = html;
                })
                .catch(function(err) { el.innerHTML = '<div class="alert alert-danger">Error: ' + err + '</div>'; });
        }

        // Auto-load activity log on page load
        document.addEventListener('DOMContentLoaded', function() { loadActivityLog(); });
        function dockerAction(serverId, action) {
            let confirmMsg = '';
            switch(action) {
                case 'start': confirmMsg = 'Mulai container ini?'; break;
                case 'stop': confirmMsg = 'Hentikan container ini?'; break;
                case 'restart': confirmMsg = 'Restart container ini?'; break;
                case 'delete': confirmMsg = 'HAPUS container ini secara PERMANEN? Data akan hilang!'; break;
                case 'repair_forwarding': confirmMsg = 'Buat ulang NAT rule MikroTik untuk semua port ACS server ini?'; break;
            }
            
            if (!confirm(confirmMsg)) return;
            
            const btn = event.target.closest('button');
            const originalHTML = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            btn.disabled = true;
            
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=' + action + '&server_id=' + serverId
            })
            .then(res => res.json())
            .then(data => {
                if (data.success && action === 'delete') {
                    // Langsung refresh halaman setelah delete berhasil
                    location.reload();
                    return;
                }
                
                btn.innerHTML = originalHTML;
                btn.disabled = false;
                
                alert(data.message);
                
                if (data.success) {
                    refreshStatus(serverId);
                }
            })
            .catch(err => {
                btn.innerHTML = originalHTML;
                btn.disabled = false;
                alert('Error: ' + err);
            });
        }
        
        function syncDevices(serverId) {
            if (!confirm('Sync devices dari GenieACS?')) return;
            
            const btn = event.target.closest('button');
            const originalHTML = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            btn.disabled = true;
            
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=sync&server_id=' + serverId
            })
            .then(res => res.json())
            .then(data => {
                btn.innerHTML = originalHTML;
                btn.disabled = false;
                alert(data.message);
            })
            .catch(err => {
                btn.innerHTML = originalHTML;
                btn.disabled = false;
                alert('Error: ' + err);
            });
        }
        
        function refreshStatus(serverId) {
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=refresh_status&server_id=' + serverId
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    const badge = document.getElementById('status-' + serverId);
                    badge.className = 'status-badge status-' + data.status.toLowerCase();
                    badge.textContent = data.status;
                }
            });
        }
        
        // Auto refresh status every 30 seconds
        setInterval(() => {
            document.querySelectorAll('[id^="status-"]').forEach(el => {
                const serverId = el.id.replace('status-', '');
                refreshStatus(serverId);
            });
        }, 30000);
    </script>
<?php require 'footer.php'; ?>
