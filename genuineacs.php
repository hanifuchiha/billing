<?php
require 'header.php';

function gae($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function ga_create_tables_if_needed($conn)
{
    $sqlSettings = "CREATE TABLE IF NOT EXISTS genieacs_integration_settings (
        owner VARCHAR(100) NOT NULL PRIMARY KEY,
        repo_url VARCHAR(255) NOT NULL,
        repo_path VARCHAR(255) NOT NULL,
        nbi_url VARCHAR(255) NOT NULL,
        ui_url VARCHAR(255) NOT NULL,
        login_username VARCHAR(100) NOT NULL,
        login_password VARCHAR(255) NOT NULL,
        port_start INT NOT NULL DEFAULT 5000,
        port_end INT NOT NULL DEFAULT 5999,
        compose_dir VARCHAR(255) NOT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $sqlLog = "CREATE TABLE IF NOT EXISTS genieacs_sync_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        owner VARCHAR(100) NOT NULL,
        pelanggan_id INT NOT NULL,
        old_idpel VARCHAR(100) DEFAULT NULL,
        new_idpel VARCHAR(100) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    mysqli_query($conn, $sqlSettings);
    mysqli_query($conn, $sqlLog);
}

function ga_run_command($command, $cwd = null)
{
    if (!function_exists('shell_exec')) {
        return "Fungsi shell_exec() tidak tersedia di server ini.";
    }

    $prefix = '';
    if ($cwd) {
        $prefix = 'cd ' . escapeshellarg($cwd) . ' 2>&1 && ';
    }

    $full = $prefix . $command . ' 2>&1';
    $result = shell_exec($full);
    if ($result === null) {
        return "Perintah gagal dijalankan atau tidak ada output.";
    }

    return trim($result) === '' ? 'Perintah berhasil dijalankan.' : $result;
}

function ga_extract_pppoe_username($device)
{
    $candidatePaths = [
        ['InternetGatewayDevice', 'WANDevice', '1', 'WANConnectionDevice', '1', 'WANPPPConnection', '1', 'Username', '_value'],
        ['VirtualParameters', 'PPPoEUsername', '_value'],
        ['VirtualParameters', 'pppoe', '_value']
    ];

    foreach ($candidatePaths as $path) {
        $tmp = $device;
        $ok = true;
        foreach ($path as $segment) {
            if (!is_array($tmp) || !array_key_exists($segment, $tmp)) {
                $ok = false;
                break;
            }
            $tmp = $tmp[$segment];
        }
        if ($ok && is_string($tmp) && trim($tmp) !== '') {
            return trim($tmp);
        }
    }

    $stack = [$device];
    while (!empty($stack)) {
        $node = array_pop($stack);
        if (!is_array($node)) {
            continue;
        }

        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $stack[] = $value;
            }
            if (is_string($key) && is_array($value) && isset($value['_value']) && is_string($value['_value'])) {
                $k = strtolower($key);
                if (strpos($k, 'pppoe') !== false || strpos($k, 'username') !== false) {
                    $v = trim($value['_value']);
                    if ($v !== '') {
                        return $v;
                    }
                }
            }
        }
    }

    return '';
}

function ga_fetch_pppoe_usernames($nbiUrl)
{
    $nbiUrl = rtrim((string)$nbiUrl, '/');
    if ($nbiUrl === '') {
        return [[], 'NBI URL kosong.'];
    }

    $query = '/devices?limit=200&projection=_id,InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Username._value,VirtualParameters.PPPoEUsername._value,VirtualParameters.pppoe._value';
    $url = $nbiUrl . $query;

    if (!function_exists('curl_init')) {
        return [[], 'cURL tidak tersedia di server ini.'];
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

    $raw = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $err) {
        return [[], 'Gagal mengambil data GenieACS: ' . $err];
    }

    if ($http < 200 || $http >= 300) {
        return [[], 'Gagal mengambil data GenieACS. HTTP ' . $http];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [[], 'Response GenieACS bukan JSON valid.'];
    }

    $usernames = [];
    foreach ($decoded as $device) {
        $pppoe = ga_extract_pppoe_username($device);
        if ($pppoe !== '') {
            $usernames[$pppoe] = true;
        }
    }

    $final = array_keys($usernames);
    sort($final);
    return [$final, 'OK'];
}

if ($AKSES === 'ASSISTANT' && !(is_array($akses_menu) && in_array('GenuineACS_Intergrasi', $akses_menu))) {
    echo '<div class="container py-4"><div class="alert alert-danger">Akses menu GenuineACS belum diberikan untuk akun Anda.</div></div>';
    require 'footer.php';
    exit;
}

ga_create_tables_if_needed($conn);

$ownerEsc = mysqli_real_escape_string($conn, $ceknama);
$defaultComposeDir = __DIR__ . '/genuineacs-docker-' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string)$ceknama);
$defaultRepoPath = __DIR__ . '/genuineacs-source-' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string)$ceknama);

$defaultSettings = [
    'repo_url' => 'https://github.com/mike2miky/Genie-ACS-alijayanet',
    'repo_path' => $defaultRepoPath,
    'nbi_url' => 'http://127.0.0.1:5001',
    'ui_url' => 'http://127.0.0.1:5004',
    'login_username' => 'adminacs',
    'login_password' => 'admin123',
    'port_start' => 5000,
    'port_end' => 5999,
    'compose_dir' => $defaultComposeDir,
];

$settings = $defaultSettings;
$sqlGet = "SELECT * FROM genieacs_integration_settings WHERE owner='$ownerEsc' LIMIT 1";
$resGet = mysqli_query($conn, $sqlGet);
if ($resGet && $rowGet = mysqli_fetch_assoc($resGet)) {
    $settings = array_merge($settings, $rowGet);
}

$messages = [];
$dockerOutput = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'save_settings') {
        $repoUrl = trim((string)($_POST['repo_url'] ?? ''));
        $repoPath = trim((string)($_POST['repo_path'] ?? ''));
        $nbiUrl = trim((string)($_POST['nbi_url'] ?? ''));
        $uiUrl = trim((string)($_POST['ui_url'] ?? ''));
        $loginUsername = trim((string)($_POST['login_username'] ?? ''));
        $loginPassword = trim((string)($_POST['login_password'] ?? ''));
        $composeDir = trim((string)($_POST['compose_dir'] ?? ''));
        $portStart = (int)($_POST['port_start'] ?? 5000);
        $portEnd = (int)($_POST['port_end'] ?? 5999);

        if ($repoUrl === '' || $repoPath === '' || $nbiUrl === '' || $uiUrl === '' || $loginUsername === '' || $composeDir === '') {
            $messages[] = ['type' => 'danger', 'text' => 'Semua field wajib diisi.'];
        } elseif ($portStart < 5000 || $portEnd > 5999 || ($portStart + 4) > $portEnd) {
            $messages[] = ['type' => 'danger', 'text' => 'Rentang port harus 5000-5999 dan minimal cukup untuk 5 service (CWMP, NBI, FS, UI, Auth).'];
        } else {
            $repoUrlEsc = mysqli_real_escape_string($conn, $repoUrl);
            $repoPathEsc = mysqli_real_escape_string($conn, $repoPath);
            $nbiUrlEsc = mysqli_real_escape_string($conn, $nbiUrl);
            $uiUrlEsc = mysqli_real_escape_string($conn, $uiUrl);
            $loginUsernameEsc = mysqli_real_escape_string($conn, $loginUsername);
            $loginPasswordEsc = mysqli_real_escape_string($conn, $loginPassword);
            $composeDirEsc = mysqli_real_escape_string($conn, $composeDir);

            $sqlUpsert = "INSERT INTO genieacs_integration_settings
                (owner, repo_url, repo_path, nbi_url, ui_url, login_username, login_password, port_start, port_end, compose_dir)
                VALUES
                ('$ownerEsc', '$repoUrlEsc', '$repoPathEsc', '$nbiUrlEsc', '$uiUrlEsc', '$loginUsernameEsc', '$loginPasswordEsc', $portStart, $portEnd, '$composeDirEsc')
                ON DUPLICATE KEY UPDATE
                repo_url=VALUES(repo_url),
                repo_path=VALUES(repo_path),
                nbi_url=VALUES(nbi_url),
                ui_url=VALUES(ui_url),
                login_username=VALUES(login_username),
                login_password=VALUES(login_password),
                port_start=VALUES(port_start),
                port_end=VALUES(port_end),
                compose_dir=VALUES(compose_dir)";

            if (mysqli_query($conn, $sqlUpsert)) {
                $messages[] = ['type' => 'success', 'text' => 'Konfigurasi GenuineACS berhasil disimpan.'];
            } else {
                $messages[] = ['type' => 'danger', 'text' => 'Gagal menyimpan konfigurasi: ' . mysqli_error($conn)];
            }
        }
    }

    $settings = $defaultSettings;
    $resGet = mysqli_query($conn, $sqlGet);
    if ($resGet && $rowGet = mysqli_fetch_assoc($resGet)) {
        $settings = array_merge($settings, $rowGet);
    }

    if (in_array($action, ['docker_deploy', 'docker_start', 'docker_stop', 'docker_restart', 'docker_status'], true)) {
        $repoUrl = $settings['repo_url'];
        $repoPath = $settings['repo_path'];
        $composeDir = $settings['compose_dir'];
        $loginUser = $settings['login_username'];
        $loginPass = $settings['login_password'];
        $portStart = (int)$settings['port_start'];

        if (!is_dir($composeDir)) {
            @mkdir($composeDir, 0775, true);
        }

        if ($action === 'docker_deploy') {
            if (!is_dir($repoPath . '/.git')) {
                $dockerOutput .= ga_run_command('git clone ' . escapeshellarg($repoUrl) . ' ' . escapeshellarg($repoPath)) . "\n\n";
            } else {
                $dockerOutput .= ga_run_command('git -C ' . escapeshellarg($repoPath) . ' pull') . "\n\n";
            }

            $bcrypt = password_hash($loginPass, PASSWORD_BCRYPT);
            if (!is_string($bcrypt) || $bcrypt === '') {
                $bcrypt = crypt($loginPass, '$2y$10$genuineacsfixedsalt123456$');
            }

            $envContent = "GENIEACS_MONGODB_CONNECTION_URL=mongodb://mongo:27017/genieacs\n" .
                "GENIEACS_CWMP_ACCESS_LOG_FILE=/var/log/genieacs/genieacs-cwmp-access.log\n" .
                "GENIEACS_NBI_ACCESS_LOG_FILE=/var/log/genieacs/genieacs-nbi-access.log\n" .
                "GENIEACS_FS_ACCESS_LOG_FILE=/var/log/genieacs/genieacs-fs-access.log\n" .
                "GENIEACS_UI_ACCESS_LOG_FILE=/var/log/genieacs/genieacs-ui-access.log\n" .
                "GENIEACS_DEBUG_FILE=/var/log/genieacs/genieacs-debug.yaml\n" .
                "GENIEACS_EXT_DIR=/opt/genieacs/ext\n" .
                "GENIEACS_UI_JWT_SECRET=" . bin2hex(random_bytes(16)) . "\n";

            $nginxConf = "events {}\nhttp {\n  server {\n    listen 80;\n    location / {\n      auth_basic \"GenuineACS Login\";\n      auth_basic_user_file /etc/nginx/.htpasswd;\n      proxy_pass http://ui:3000;\n      proxy_set_header Host \\$host;\n      proxy_set_header X-Real-IP \\$remote_addr;\n      proxy_set_header X-Forwarded-For \\$proxy_add_x_forwarded_for;\n    }\n  }\n}\n";

            $composeYml = "version: '3.8'\nservices:\n  mongo:\n    image: mongo:4.4\n    container_name: genuineacs-mongo-" . preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$ceknama) . "\n    restart: unless-stopped\n    volumes:\n      - mongo_data:/data/db\n\n  cwmp:\n    image: genieacs/genieacs:1.2.13\n    container_name: genuineacs-cwmp-" . preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$ceknama) . "\n    restart: unless-stopped\n    env_file: .env\n    command: genieacs-cwmp\n    depends_on:\n      - mongo\n    ports:\n      - \"" . $portStart . ":7547\"\n\n  nbi:\n    image: genieacs/genieacs:1.2.13\n    container_name: genuineacs-nbi-" . preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$ceknama) . "\n    restart: unless-stopped\n    env_file: .env\n    command: genieacs-nbi\n    depends_on:\n      - mongo\n    ports:\n      - \"" . ($portStart + 1) . ":7557\"\n\n  fs:\n    image: genieacs/genieacs:1.2.13\n    container_name: genuineacs-fs-" . preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$ceknama) . "\n    restart: unless-stopped\n    env_file: .env\n    command: genieacs-fs\n    depends_on:\n      - mongo\n    ports:\n      - \"" . ($portStart + 2) . ":7567\"\n\n  ui:\n    image: genieacs/genieacs:1.2.13\n    container_name: genuineacs-ui-" . preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$ceknama) . "\n    restart: unless-stopped\n    env_file: .env\n    command: genieacs-ui\n    depends_on:\n      - mongo\n    ports:\n      - \"" . ($portStart + 3) . ":3000\"\n\n  auth:\n    image: nginx:alpine\n    container_name: genuineacs-auth-" . preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$ceknama) . "\n    restart: unless-stopped\n    depends_on:\n      - ui\n    ports:\n      - \"" . ($portStart + 4) . ":80\"\n    volumes:\n      - ./nginx.conf:/etc/nginx/nginx.conf:ro\n      - ./.htpasswd:/etc/nginx/.htpasswd:ro\n\nvolumes:\n  mongo_data:\n";

            file_put_contents($composeDir . '/.env', $envContent);
            file_put_contents($composeDir . '/nginx.conf', $nginxConf);
            file_put_contents($composeDir . '/.htpasswd', $loginUser . ':' . $bcrypt . "\n");
            file_put_contents($composeDir . '/docker-compose.yml', $composeYml);

            $dockerOutput .= ga_run_command('docker compose up -d', $composeDir) . "\n";

            $uiSec = 'http://127.0.0.1:' . ($portStart + 4);
            $nbi = 'http://127.0.0.1:' . ($portStart + 1);

            $uiSecEsc = mysqli_real_escape_string($conn, $uiSec);
            $nbiEsc = mysqli_real_escape_string($conn, $nbi);
            mysqli_query($conn, "UPDATE genieacs_integration_settings SET ui_url='$uiSecEsc', nbi_url='$nbiEsc' WHERE owner='$ownerEsc'");

            $messages[] = ['type' => 'success', 'text' => 'Deploy container GenuineACS selesai. UI login: ' . $uiSec . ' | NBI: ' . $nbi];
        }

        if ($action === 'docker_start') {
            $dockerOutput .= ga_run_command('docker compose start', $composeDir);
            $messages[] = ['type' => 'success', 'text' => 'Container GenuineACS berhasil dijalankan.'];
        }
        if ($action === 'docker_stop') {
            $dockerOutput .= ga_run_command('docker compose stop', $composeDir);
            $messages[] = ['type' => 'warning', 'text' => 'Container GenuineACS berhasil dihentikan.'];
        }
        if ($action === 'docker_restart') {
            $dockerOutput .= ga_run_command('docker compose restart', $composeDir);
            $messages[] = ['type' => 'success', 'text' => 'Container GenuineACS berhasil di-restart.'];
        }
        if ($action === 'docker_status') {
            $dockerOutput .= ga_run_command('docker compose ps', $composeDir);
            $messages[] = ['type' => 'info', 'text' => 'Status container GenuineACS ditampilkan di bawah.'];
        }
    }

    if ($action === 'sync_idpel') {
        $pppoeUsername = trim((string)($_POST['pppoe_username'] ?? ''));
        $pelangganId = (int)($_POST['pelanggan_id'] ?? 0);

        if ($pppoeUsername === '' || $pelangganId <= 0) {
            $messages[] = ['type' => 'danger', 'text' => 'PPPoE username dan pelanggan wajib dipilih.'];
        } else {
            $pppoeEsc = mysqli_real_escape_string($conn, $pppoeUsername);
            $sqlOld = "SELECT id, IDPEL FROM pelanggan WHERE id=$pelangganId AND PEMILIK='$ownerEsc' LIMIT 1";
            $resOld = mysqli_query($conn, $sqlOld);
            if ($resOld && $oldRow = mysqli_fetch_assoc($resOld)) {
                $oldIdpel = (string)$oldRow['IDPEL'];
                $sqlUpd = "UPDATE pelanggan SET IDPEL='$pppoeEsc' WHERE id=$pelangganId AND PEMILIK='$ownerEsc' LIMIT 1";
                if (mysqli_query($conn, $sqlUpd)) {
                    $oldEsc = mysqli_real_escape_string($conn, $oldIdpel);
                    mysqli_query($conn, "INSERT INTO genieacs_sync_log (owner, pelanggan_id, old_idpel, new_idpel) VALUES ('$ownerEsc', $pelangganId, '$oldEsc', '$pppoeEsc')");
                    $messages[] = ['type' => 'success', 'text' => 'Sinkronisasi berhasil. IDPEL pelanggan diperbarui menjadi: ' . $pppoeUsername];
                } else {
                    $messages[] = ['type' => 'danger', 'text' => 'Gagal update IDPEL: ' . mysqli_error($conn)];
                }
            } else {
                $messages[] = ['type' => 'danger', 'text' => 'Data pelanggan tidak ditemukan atau bukan milik akun ini.'];
            }
        }
    }
}

$settings = $defaultSettings;
$resGet = mysqli_query($conn, $sqlGet);
if ($resGet && $rowGet = mysqli_fetch_assoc($resGet)) {
    $settings = array_merge($settings, $rowGet);
}

list($pppoeList, $pppoeMsg) = ga_fetch_pppoe_usernames($settings['nbi_url']);

$customers = [];
$sqlCustomers = "SELECT id, IDPEL, NAMA, NOWA, AREA FROM pelanggan WHERE PEMILIK='$ownerEsc' ORDER BY NAMA ASC LIMIT 800";
$resCustomers = mysqli_query($conn, $sqlCustomers);
if ($resCustomers) {
    while ($c = mysqli_fetch_assoc($resCustomers)) {
        $customers[] = $c;
    }
}

$matchedMap = [];
foreach ($customers as $c) {
    $idpel = trim((string)$c['IDPEL']);
    if ($idpel !== '') {
        $matchedMap[$idpel] = $c;
    }
}
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="card mb-4">
                <div class="card-header pb-0">
                    <h6>Integrasi GenuineACS (Docker + Sinkronisasi IDPEL)</h6>
                    <p class="text-sm mb-0">Repo sumber: <strong><?php echo gae($settings['repo_url']); ?></strong></p>
                </div>
                <div class="card-body">
                    <?php foreach ($messages as $m): ?>
                        <div class="alert alert-<?php echo gae($m['type']); ?>"><?php echo gae($m['text']); ?></div>
                    <?php endforeach; ?>

                    <ul class="nav nav-tabs" id="genieTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="docker-tab" data-bs-toggle="tab" data-bs-target="#docker" type="button" role="tab">Docker GenuineACS</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="sync-tab" data-bs-toggle="tab" data-bs-target="#sync" type="button" role="tab">Sinkronisasi IDPEL - PPPoE</button>
                        </li>
                    </ul>

                    <div class="tab-content pt-3">
                        <div class="tab-pane fade show active" id="docker" role="tabpanel">
                            <form method="post" class="row g-3 mb-3">
                                <input type="hidden" name="action" value="save_settings">
                                <div class="col-md-6">
                                    <label class="form-label">Repo URL</label>
                                    <input type="text" name="repo_url" class="form-control" value="<?php echo gae($settings['repo_url']); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Repo Path</label>
                                    <input type="text" name="repo_path" class="form-control" value="<?php echo gae($settings['repo_path']); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">NBI URL (untuk baca data PPPoE)</label>
                                    <input type="text" name="nbi_url" class="form-control" value="<?php echo gae($settings['nbi_url']); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">UI URL (hasil deploy)</label>
                                    <input type="text" name="ui_url" class="form-control" value="<?php echo gae($settings['ui_url']); ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Username Login UI</label>
                                    <input type="text" name="login_username" class="form-control" value="<?php echo gae($settings['login_username']); ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Password Login UI</label>
                                    <input type="text" name="login_password" class="form-control" value="<?php echo gae($settings['login_password']); ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Compose Directory</label>
                                    <input type="text" name="compose_dir" class="form-control" value="<?php echo gae($settings['compose_dir']); ?>" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Port Mulai</label>
                                    <input type="number" name="port_start" class="form-control" value="<?php echo gae($settings['port_start']); ?>" min="5000" max="5999" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Port Akhir</label>
                                    <input type="number" name="port_end" class="form-control" value="<?php echo gae($settings['port_end']); ?>" min="5000" max="5999" required>
                                </div>
                                <div class="col-12">
                                    <button class="btn btn-primary" type="submit">Simpan Konfigurasi</button>
                                </div>
                            </form>

                            <div class="d-flex flex-wrap gap-2 mb-3">
                                <form method="post">
                                    <input type="hidden" name="action" value="docker_deploy">
                                    <button type="submit" class="btn btn-success">Deploy Container Baru</button>
                                </form>
                                <form method="post">
                                    <input type="hidden" name="action" value="docker_start">
                                    <button type="submit" class="btn btn-info">Start</button>
                                </form>
                                <form method="post">
                                    <input type="hidden" name="action" value="docker_stop">
                                    <button type="submit" class="btn btn-warning">Stop</button>
                                </form>
                                <form method="post">
                                    <input type="hidden" name="action" value="docker_restart">
                                    <button type="submit" class="btn btn-secondary">Restart</button>
                                </form>
                                <form method="post">
                                    <input type="hidden" name="action" value="docker_status">
                                    <button type="submit" class="btn btn-dark">Status</button>
                                </form>
                            </div>

                            <div class="alert alert-light border">
                                <strong>Port mapping default:</strong><br>
                                CWMP: <?php echo gae($settings['port_start']); ?>, NBI: <?php echo gae((string)((int)$settings['port_start'] + 1)); ?>,
                                FS: <?php echo gae((string)((int)$settings['port_start'] + 2)); ?>,
                                UI internal: <?php echo gae((string)((int)$settings['port_start'] + 3)); ?>,
                                UI Login (proxy auth): <?php echo gae((string)((int)$settings['port_start'] + 4)); ?>
                            </div>

                            <?php if ($dockerOutput !== ''): ?>
                                <div class="mt-3">
                                    <label class="form-label">Output Docker</label>
                                    <pre style="background:#111;color:#eaeaea;padding:12px;border-radius:8px;max-height:360px;overflow:auto;"><?php echo gae($dockerOutput); ?></pre>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="tab-pane fade" id="sync" role="tabpanel">
                            <div class="alert alert-info">
                                Data PPPoE diambil dari GenieACS NBI: <strong><?php echo gae($settings['nbi_url']); ?></strong><br>
                                Status fetch: <?php echo gae($pppoeMsg); ?>
                            </div>

                            <form method="post" class="row g-3 mb-4">
                                <input type="hidden" name="action" value="sync_idpel">
                                <div class="col-md-5">
                                    <label class="form-label">PPPoE dari GenuineACS</label>
                                    <select class="form-select" name="pppoe_username" required>
                                        <option value="">-- Pilih PPPoE --</option>
                                        <?php foreach ($pppoeList as $p): ?>
                                            <option value="<?php echo gae($p); ?>"><?php echo gae($p); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label">Pelanggan tujuan (update IDPEL)</label>
                                    <select class="form-select" name="pelanggan_id" required>
                                        <option value="">-- Pilih Pelanggan --</option>
                                        <?php foreach ($customers as $c): ?>
                                            <option value="<?php echo (int)$c['id']; ?>">
                                                <?php echo gae($c['NAMA']); ?> | IDPEL saat ini: <?php echo gae((string)$c['IDPEL']); ?> | WA: <?php echo gae((string)$c['NOWA']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2 d-flex align-items-end">
                                    <button class="btn btn-primary w-100" type="submit">Sinkronkan</button>
                                </div>
                            </form>

                            <div class="table-responsive">
                                <table class="table table-bordered table-sm align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>No</th>
                                            <th>PPPoE dari GenuineACS</th>
                                            <th>Status di Pelanggan.IDPEL</th>
                                            <th>Detail Match</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($pppoeList)): ?>
                                            <tr><td colspan="4" class="text-center">Belum ada data PPPoE terdeteksi.</td></tr>
                                        <?php else: ?>
                                            <?php $no = 1; foreach ($pppoeList as $pp): ?>
                                                <?php $isMatch = isset($matchedMap[$pp]); ?>
                                                <tr>
                                                    <td><?php echo $no++; ?></td>
                                                    <td><?php echo gae($pp); ?></td>
                                                    <td>
                                                        <?php if ($isMatch): ?>
                                                            <span class="badge bg-success">Sudah sinkron</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-warning text-dark">Belum sinkron</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($isMatch): ?>
                                                            <?php $mc = $matchedMap[$pp]; ?>
                                                            <?php echo gae($mc['NAMA']); ?> (ID <?php echo (int)$mc['id']; ?>), Area: <?php echo gae((string)$mc['AREA']); ?>
                                                        <?php else: ?>
                                                            -
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require 'footer.php'; ?>
