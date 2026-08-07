<?php
require 'header.php';
require 'acs_helper.php';
require 'acs_local_config_helper.php';

// Initialize ACS helper
$acs = new ACSHelper($conn, $USER_ID, $AKSES);
$local_config = new ACSLocalConfigHelper();

// Validate local Genie-ACS folder
$config_validation = $local_config->validateLocalConfig();

// Ensure database is ready
$acs->ensureDatabase();

// Only ADMIN can access this page
if (!$acs->isAdmin()) {
    die('Access denied. Only ADMIN can create servers.');
}

$error = '';
$success = '';
$creating = false;
$error_ext = '';
$success_ext = '';
$creation_steps = [];

$host_from_server = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
$host_from_server = preg_replace('/:\\d+$/', '', $host_from_server);
$auto_domain_http = 'http://' . $host_from_server;

// Handle: daftarkan server ACS yang sudah ada / eksternal (bukan diprovisi Docker)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_external_server'])) {
    $ext_nama_server = trim($_POST['ext_nama_server'] ?? '');
    $ext_domain = trim($_POST['ext_domain'] ?? '');
    $ext_ui_port = trim($_POST['ext_ui_port'] ?? '3000');
    $ext_cwmp_port = trim($_POST['ext_cwmp_port'] ?? '7547');
    $ext_nbi_port = trim($_POST['ext_nbi_port'] ?? '7557');
    $ext_fs_port = trim($_POST['ext_fs_port'] ?? '7567');
    $ext_username_acs = trim($_POST['ext_username_acs'] ?? '');
    $ext_password_acs = trim($_POST['ext_password_acs'] ?? '');
    $ext_allowed_ips = trim($_POST['ext_allowed_ips'] ?? '');

    if (empty($ext_nama_server) || empty($ext_domain) || empty($ext_username_acs) || empty($ext_password_acs)) {
        $error_ext = 'Nama server, domain/IP, username, dan password harus diisi';
    } else {
        $ext_result = $acs->createExternalServer(
            $ext_nama_server,
            $ext_domain,
            $ext_ui_port,
            $ext_cwmp_port,
            $ext_nbi_port,
            $ext_fs_port,
            $ext_username_acs,
            $ext_password_acs,
            $ext_allowed_ips
        );

        if ($ext_result['success']) {
            $api_msg = '';
            if (isset($ext_result['api_check'])) {
                $api_msg = $ext_result['api_check']['success']
                    ? ' | ✅ NBI merespons: ' . $ext_result['api_check']['message']
                    : ' | ⚠️ NBI belum bisa dihubungi dari server ini: ' . $ext_result['api_check']['message'] . ' (server tetap terdaftar, cek firewall/port jika perlu)';
            }
            $success_ext = 'Server ACS eksternal berhasil didaftarkan!' . $api_msg;
            header('Refresh: 3; url=acs_servers_list.php');
        } else {
            $error_ext = $ext_result['message'];
        }
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_server'])) {
    $nama_server = trim($_POST['nama_server']);
    $domain = trim($_POST['domain']);
    $username_acs = trim($_POST['username_acs']);
    $password_acs = trim($_POST['password_acs']);
    $allowed_ips = isset($_POST['allowed_ips']) ? trim($_POST['allowed_ips']) : '';
    // Validation
    if (empty($nama_server) || empty($domain) || empty($username_acs) || empty($password_acs)) {
        $error = 'Semua field harus diisi';
    } else {
        $creating = true;
        // Port dibuat otomatis oleh helper
         // Gunakan local Genie-ACS config path
         $genie_config_path = __DIR__ . '/Genie-ACS-alijayanet';
         $result = $acs->createServer($nama_server, $domain, null, $username_acs, $password_acs, $genie_config_path);
        if ($result['success']) {
            // Simpan allowed_ips ke database
            $server_id = $result['server_id'] ?? null;
            if ($server_id && $allowed_ips !== '') {
                $stmt = mysqli_prepare($conn, "UPDATE acs_servers SET allowed_ips=? WHERE id=?");
                mysqli_stmt_bind_param($stmt, 'si', $allowed_ips, $server_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
            $forwarding_ok = isset($result['forwarding_result']) && is_array($result['forwarding_result'])
                && !empty($result['forwarding_result']['success']);
            $forwarding_message = $result['forwarding_result']['message'] ?? '';

            $success = true;
            $success_info = [
                'container_name' => $result['container_name'],
                'base_port' => $result['base_port'],
                'forwarding_ok' => $forwarding_ok,
                'forwarding_message' => $forwarding_message,
                'username_acs' => $username_acs,
                'password_acs' => $password_acs,
            ];
        } else {
            $error = $result['message'];
        }
        $creation_steps = $result['steps'] ?? [];
        $creating = false;
    }
}

// Get list of used ports for info panel (Docker-provisioned only; external
// servers run on a different host and don't reserve a port here)
$used_ports = [];
$result = mysqli_query($conn, "SELECT port FROM acs_servers WHERE is_external = 0 ORDER BY port");
while ($row = mysqli_fetch_assoc($result)) {
    $used_ports[] = $row['port'];
}

$suggested_port = $acs->getNextAvailableBasePort();
?>

    <style>
        .form-label {
            font-weight: bold;
        }
        .info-box {
            background: #f8f9fa;
            border-left: 4px solid #007bff;
            padding: 15px;
            margin: 20px 0;
        }
        .port-list {
            max-height: 200px;
            overflow-y: auto;
            background: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
        }
        .creating-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.8);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
        }

        /* ACS dark theme overrides */
        body.app-theme-dark .card,
        body.app-theme-dark .card-body,
        body.app-theme-dark .info-box,
        body.app-theme-dark .port-list,
        body.app-theme-dark .alert,
        body.app-theme-dark pre,
        body.app-theme-dark code {
            background: #0f172a !important;
            color: #e5e7eb !important;
            border-color: rgba(148, 163, 184, 0.35) !important;
        }
        body.app-theme-dark .form-control,
        body.app-theme-dark .form-select {
            background: #111827 !important;
            color: #e5e7eb !important;
            border-color: rgba(148, 163, 184, 0.35) !important;
        }
        body.app-theme-dark .form-control::placeholder {
            color: #94a3b8 !important;
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
    </style>

   
        <?php if ($creating): ?>
            <div class="creating-overlay">
                <div class="text-center">
                    <i class="fas fa-spinner fa-spin" style="font-size: 48px;"></i>
                    <h3 class="mt-3">Membuat Server ACS...</h3>
                    <p>Proses ini membutuhkan waktu sekitar 30-60 detik</p>
                    <p>Mohon jangan tutup halaman ini</p>
                </div>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header dashboard-dark-header theme-aware-header">
                <h4><i class="fas fa-plus-circle"></i> Tambah Server ACS Baru</h4>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-8">
                        <?php if (!empty($creation_steps)): ?>
                            <div class="alert alert-secondary" style="font-size: 0.92em;">
                                <h6 class="mb-2"><i class="fas fa-list-check"></i> Progress Pembuatan Server</h6>
                                <ul class="mb-0" style="list-style:none; padding-left:0;">
                                    <?php foreach ($creation_steps as $step): ?>
                                        <li class="mb-1">
                                            <?php echo !empty($step['ok']) ? '✅' : '❌'; ?>
                                            <?php echo htmlspecialchars($step['label']); ?>
                                            <?php if (empty($step['ok']) && !empty($step['detail'])): ?>
                                                <div style="white-space: pre-wrap; word-break: break-word; color:#dc3545; font-size:0.9em; margin-left: 1.5em;"><?php echo htmlspecialchars($step['detail']); ?></div>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <?php if ($error): ?>
                            <div class="alert alert-danger" style="white-space: pre-wrap; word-break: break-word;">
                                <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($success && isset($success_info)): ?>
                            <div class="alert alert-success">
                                <h6 class="mb-2"><i class="fas fa-check-circle"></i> Server ACS berhasil dibuat!</h6>
                                <ul class="mb-3" style="list-style:none; padding-left:0;">
                                    <li>✅ Docker ACS sudah dibuat (<code><?php echo htmlspecialchars($success_info['container_name']); ?></code>, base port <?php echo htmlspecialchars($success_info['base_port']); ?>)</li>
                                    <li><?php echo $success_info['forwarding_ok'] ? '✅ Sudah di-forward' : '⚠️ Sudah di-forward (sebagian gagal, cek: ' . htmlspecialchars($success_info['forwarding_message']) . ')'; ?></li>
                                    <li>✅ Siap dijalankan</li>
                                </ul>
                                <hr>
                                <p class="mb-1"><strong>Catatan:</strong> Login dulu ke UI GenieACS dengan akun default:</p>
                                <ul class="mb-2">
                                    <li>Username: <code>alijayanet</code></li>
                                    <li>Password: <code>087828060111</code></li>
                                </ul>
                                <p class="mb-1">Lalu buat user baru sesuai setting yang baru saja diisi:</p>
                                <ul class="mb-0">
                                    <li>Username: <code><?php echo htmlspecialchars($success_info['username_acs']); ?></code></li>
                                    <li>Password: <code><?php echo htmlspecialchars($success_info['password_acs']); ?></code></li>
                                </ul>
                                <div class="mt-3">
                                    <a href="acs_servers_list.php" class="btn btn-sm btn-success">
                                        <i class="fas fa-arrow-right"></i> Lanjut ke Daftar Server
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="">
                            <div class="mb-3">
                                <label class="form-label">Nama Server</label>
                                <input type="text" name="nama_server" class="form-control" 
                                       placeholder="Contoh: ACS Server Fiber Q" required>
                                <small class="text-muted">Nama identifikasi server untuk memudahkan pengelolaan</small>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Domain / IP Server</label>
                                <input type="text" name="domain" class="form-control" 
                                       value="<?php echo htmlspecialchars($auto_domain_http); ?>" 
                                       placeholder="Contoh: http://domain.com atau http://192.168.1.1" required>
                                <small class="text-muted">Domain atau IP server untuk mengakses ACS (gunakan HTTP)</small>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Username ACS</label>
                                <input type="text" name="username_acs" class="form-control" 
                                       placeholder="Contoh: admin" required>
                                <small class="text-muted">Username untuk login ke GenieACS</small>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Password ACS</label>
                                <input type="password" name="password_acs" class="form-control" 
                                       placeholder="Masukkan password" required>
                                <small class="text-muted">Password untuk login ke GenieACS</small>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Allowed IPs (satu IP per baris)</label>
                                <textarea name="allowed_ips" class="form-control" rows="3" placeholder="Contoh:\n127.0.0.1\n10.0.0.10\n192.168.1.1"></textarea>
                                 <small class="text-muted">Daftar IP yang diizinkan akses ke worker ACS. Kosongkan untuk default (localhost saja).
                                 <br><strong>Virtual Parameter & Config:</strong> Menggunakan dari folder lokal Genie-ACS-alijayanet dengan autentikasi penuh.</small>
                            </div>
                            <div class="d-grid gap-2">
                                <button type="submit" name="create_server" class="btn btn-primary btn-lg">
                                    <i class="fas fa-rocket"></i> Buat Server ACS
                                </button>
                                <a href="acs_servers_list.php" class="btn btn-secondary">
                                    <i class="fas fa-arrow-left"></i> Kembali ke Daftar Server
                                </a>
                            </div>
                        </form>
                    </div>

                    <div class="col-md-4">
                                                <?php if ($config_validation['valid']): ?>
                                                    <div class="alert alert-success mb-3">
                                                        <h6><i class="fas fa-check-circle"></i> ✅ Konfigurasi Lokal Valid</h6>
                                                        <small>
                                                            <strong>Path:</strong> <?php echo basename($config_validation['path']); ?><br>
                                                            <strong>File:</strong> <?php echo count($config_validation['found_files']); ?> ditemukan
                                                        </small>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="alert alert-warning mb-3">
                                                        <h6><i class="fas fa-exclamation-triangle"></i> ⚠️ Folder Tidak Lengkap</h6>
                                                        <small><?php echo htmlspecialchars($config_validation['message']); ?></small>
                                                    </div>
                                                <?php endif; ?>
                          <div class="info-box">
                            <h5><i class="fas fa-check-circle"></i> Konfigurasi Sumber</h5>
                            <p><strong>Virtual Parameter & Config:</strong></p>
                            <ul>
                                <li>Username : alijayanet</li>
                                <li>Password : 087828060111</li>
                                <li>✅ Menggunakan folder lokal</li>
                                <li>✅ Auto-import BSON collections</li>
                                <li>✅ Full authentication enabled</li>
                                <li>✅ User creation unrestricted</li>
                                <li>Repository: <code>mike2miky/Genie-ACS</code></li>
                            </ul>
                        </div>
                        <div class="info-box">
                            <h5><i class="fas fa-key"></i> Autentikasi Penuh</h5>
                                                        <p>User dapat membuat akun dan mengelola ACS tanpa batasan dengan credential disimpan di MongoDB users collection.</p>
                                                        <ul style="font-size: 12px; margin-bottom: 0;">
                                                                <li>✅ Full access ke semua resource</li>
                                                                <li>✅ Bisa membuat user baru</li>
                                                                <li>✅ Bisa edit/delete user</li>
                                                                <li>✅ Bisa manage permissions</li>
                                                                <li>✅ Tidak ada restrictions</li>
                                                        </ul>
                        </div>
                        <div class="info-box">
                            <h5><i class="fas fa-info-circle"></i> Informasi</h5>
                            <p><strong>Port yang sudah digunakan:</strong></p>
                            <div class="port-list">
                                <?php if (empty($used_ports)): ?>
                                    <p class="text-muted">Belum ada port yang digunakan</p>
                                <?php else: ?>
                                    <ul>
                                        <?php foreach ($used_ports as $port): ?>
                                            <li>Port <?php echo $port; ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="info-box">
                            <h5><i class="fas fa-cog"></i> Proses Pembuatan</h5>
                            <ol>
                                <li>Validasi port tersedia</li>
                                <li>Clone virtualparameter dari GitHub</li>
                                <li>Buat docker-compose.yml</li>
                                <li>Start container MongoDB</li>
                                <li>Start container GenieACS</li>
                                <li>Import virtualparameter ke MongoDB</li>
                                <li>Selesai!</li>
                            </ol>
                        </div>

                        <div class="info-box">
                            <h5><i class="fas fa-docker"></i> Container yang dibuat</h5>
                            <p>Base port otomatis: <strong><?php echo $suggested_port !== null ? $suggested_port : '-'; ?></strong></p>
                            <?php if ($suggested_port !== null): ?>
                            <p>Port yang akan dipakai: <strong><?php echo $suggested_port; ?>-<?php echo $suggested_port + 3; ?></strong></p>
                            <ul>
                                <li><code>acs_<?php echo $suggested_port; ?></code> (GenieACS)</li>
                                <li><code>genieacs-mongo-<?php echo $suggested_port; ?></code> (MongoDB)</li>
                            </ul>
                            <?php else: ?>
                            <p class="text-danger">Tidak ada port kosong tersedia di range 5000-5999.</p>
                            <?php endif; ?>
                        </div>

                        <div class="alert alert-warning">
                            <strong>Persyaratan:</strong>
                            <ul class="mb-0">
                                <li>Docker harus terinstall</li>
                                <li>Git harus terinstall</li>
                                <li>Base port dipilih otomatis (bundle 4 port)</li>
                                <li>Koneksi internet untuk clone repo</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tambah Server ACS yang Sudah Ada / Eksternal -->
        <div class="card mt-4">
            <div class="card-header dashboard-dark-header theme-aware-header">
                <h4><i class="fas fa-link"></i> Tambah Server ACS yang Sudah Ada (Eksternal)</h4>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-8">
                        <?php if ($error_ext): ?>
                            <div class="alert alert-danger" style="white-space: pre-wrap; word-break: break-word;">
                                <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error_ext); ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($success_ext): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_ext); ?>
                                <br>Redirecting ke daftar server...
                            </div>
                        <?php endif; ?>

                        <div class="alert alert-secondary" style="font-size: 0.92em;">
                            <i class="fas fa-circle-info"></i>
                            Gunakan form ini untuk menghubungkan server GenieACS yang <strong>sudah berjalan</strong> —
                            baik di server lain, VPS lain, atau di-install manual (bukan lewat "Buat Server ACS Baru"
                            di atas). <strong>Tidak ada Docker container baru yang dibuat</strong>, tidak ada
                            port forwarding MikroTik otomatis, dan tidak ada import virtual parameter — aplikasi ini
                            hanya menyimpan kredensial koneksi dan langsung memakainya untuk sinkronisasi device.
                        </div>

                        <form method="POST" action="">
                            <div class="mb-3">
                                <label class="form-label">Nama Server</label>
                                <input type="text" name="ext_nama_server" class="form-control"
                                       placeholder="Contoh: ACS Server Cabang Bandung" required>
                                <small class="text-muted">Nama identifikasi server untuk memudahkan pengelolaan</small>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Domain / IP Server</label>
                                <input type="text" name="ext_domain" class="form-control"
                                       placeholder="Contoh: 203.0.113.10 atau acs.contohdomain.com" required>
                                <small class="text-muted">Domain/IP tempat server GenieACS itu bisa diakses (tanpa port, tanpa http://)</small>
                            </div>

                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">UI Port</label>
                                    <input type="number" name="ext_ui_port" class="form-control" value="3000" min="1" max="65535" required>
                                    <small class="text-muted">Default GenieACS: 3000</small>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">CWMP Port</label>
                                    <input type="number" name="ext_cwmp_port" class="form-control" value="7547" min="1" max="65535" required>
                                    <small class="text-muted">Default: 7547</small>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">NBI Port</label>
                                    <input type="number" name="ext_nbi_port" class="form-control" value="7557" min="1" max="65535" required>
                                    <small class="text-muted">Default: 7557 (dipakai untuk API sync)</small>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">File Server Port</label>
                                    <input type="number" name="ext_fs_port" class="form-control" value="7567" min="1" max="65535" required>
                                    <small class="text-muted">Default: 7567</small>
                                </div>
                            </div>
                            <div class="alert alert-secondary" style="font-size: 0.85em;">
                                <i class="fas fa-triangle-exclamation"></i>
                                Server ACS eksternal <strong>tidak selalu</strong> memakai skema port berurutan
                                (base, base+1, base+2, base+3) seperti server yang diprovisi otomatis di atas.
                                Isi ke-4 port ini sesuai konfigurasi <strong>asli</strong> server GenieACS tersebut,
                                bukan asumsi otomatis.
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Username ACS</label>
                                <input type="text" name="ext_username_acs" class="form-control"
                                       placeholder="Contoh: admin" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Password ACS</label>
                                <input type="password" name="ext_password_acs" class="form-control"
                                       placeholder="Masukkan password" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Allowed IPs (satu IP per baris, opsional)</label>
                                <textarea name="ext_allowed_ips" class="form-control" rows="3" placeholder="127.0.0.1&#10;10.0.0.10"></textarea>
                            </div>

                            <div class="d-grid gap-2">
                                <button type="submit" name="create_external_server" class="btn btn-outline-primary btn-lg">
                                    <i class="fas fa-link"></i> Hubungkan Server ACS Ini
                                </button>
                            </div>
                        </form>
                    </div>

                    <div class="col-md-4">
                        <div class="info-box">
                            <h5><i class="fas fa-list-check"></i> Cocok Untuk</h5>
                            <ul style="font-size: 13px; margin-bottom: 0;">
                                <li>GenieACS yang sudah jalan di server/VPS lain</li>
                                <li>GenieACS hasil install manual (bukan lewat aplikasi ini)</li>
                                <li>ACS milik vendor/mitra yang aksesnya dibagikan ke Anda</li>
                            </ul>
                        </div>
                        <div class="info-box">
                            <h5><i class="fas fa-ban"></i> Tidak Dilakukan</h5>
                            <ul style="font-size: 13px; margin-bottom: 0;">
                                <li>❌ Tidak membuat container Docker</li>
                                <li>❌ Tidak clone/import virtual parameter</li>
                                <li>❌ Tidak membuat port forwarding MikroTik</li>
                                <li>❌ Start/Stop/Restart tidak berlaku (kelola langsung di server aslinya)</li>
                                <li>✅ Hapus registrasi hanya melepas koneksinya, server aslinya tidak disentuh</li>
                            </ul>
                        </div>
                        <div class="alert alert-info" style="font-size: 13px;">
                            Setelah terdaftar, sistem akan mencoba sekali menghubungi NBI Port untuk memastikan
                            kredensialnya benar. Kalau server ini hanya bisa diakses dari jaringan lokal (bukan
                            dari internet), tes ini bisa gagal walau kredensialnya benar — server tetap didaftarkan,
                            cukup pastikan firewall/koneksi jaringannya benar sebelum melakukan sync device.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Technical Details -->
        <div class="card mt-4">
            <div class="card-header dashboard-dark-header theme-aware-header">
                <h5><i class="fas fa-code"></i> Detail Teknis</h5>
            </div>
            <div class="card-body">
                <h6>Struktur Container:</h6>
                <pre><code>services:
  mongo:
    image: mongo:4.4
    container_name: genieacs-mongo-[PORT]
    volumes: ./mongo:/data/db

  genieacs:
    image: deandegil/genieacs
    container_name: genieacs-[PORT]
    ports:
    - "[PORT]:3000"
    - "[PORT+1]:7547"
    - "[PORT+2]:7557"
    - "[PORT+3]:7567"
    environment:
      GENIEACS_MONGODB_CONNECTION_URL: mongodb://mongo/genieacs
      GENIEACS_UI_JWT_SECRET: quenbysecret</code></pre>

                <h6 class="mt-3">Sumber Virtual Parameter:</h6>
                <p>Repository: <a href="https://github.com/alijayanet/virtualparameter" target="_blank">https://github.com/alijayanet/virtualparameter</a></p>
                <p>Data akan diimport menggunakan: <code>mongorestore --db=genieacs --drop virtualparameter</code></p>
            </div>
        </div>
    </div>
  </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
<?php require 'footer.php'; ?>
