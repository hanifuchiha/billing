<?php
// --- Toggle cron on/off via AJAX: ditangani di sini, SEBELUM header.php dirender,
// supaya respons ke JS berupa JSON murni (bukan seluruh halaman HTML) dan error
// nyata (mis. exec() dimatikan, atau crontab gagal ditulis) bisa dilaporkan balik
// ke toggle, bukan selalu tampil "Sukses" walau sebenarnya gagal tersimpan. ---
if (isset($_POST['mode']) && $_POST['mode'] === 'onoff') {
    require 'cek-sesi.php';
    header('Content-Type: application/json');

    // Endpoint ini bisa manipulasi crontab server (exec) -- wajib guard akses
    // menu System_setting sama seperti halaman utamanya, supaya assistant yang
    // tidak diberi izin menu ini tidak bisa toggle cron lewat panggilan AJAX
    // langsung (bypass tampilan sidebar/halaman).
    if ($AKSES == 'ASSISTANT' && (!isset($akses_menu) || !is_array($akses_menu) || !in_array('System_setting', $akses_menu, true))) {
        echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki akses ke menu System Setting.']);
        exit;
    }

    $action = $_POST['action'] ?? '';
    $cron_job = trim($_POST['command'] ?? '');
    $response = ['success' => false, 'message' => ''];

    if ($cron_job === '') {
        $response['message'] = 'Perintah cron kosong.';
    } elseif (!in_array($action, ['add', 'delete'], true)) {
        $response['message'] = 'Aksi tidak dikenal.';
    } elseif (!function_exists('exec')) {
        $response['message'] = 'Fungsi exec() dinonaktifkan di server (disable_functions), sehingga toggle cron tidak bisa berjalan. Hubungi admin hosting untuk mengizinkan exec().';
    } else {
        // Baca crontab saat ini
        exec('crontab -l 2>&1', $list_lines, $list_ret);
        $list_text = implode("\n", $list_lines);
        $list_ok = ($list_ret === 0) || (stripos($list_text, 'no crontab') !== false);

        if (!$list_ok) {
            $response['message'] = 'Gagal membaca crontab: ' . $list_text;
        } else {
            // Buang pesan placeholder ("no crontab for ...") dan baris kosong
            // supaya tidak ikut tertulis sebagai baris cron yang tidak valid.
            $current_lines = array_values(array_filter(
                array_map('rtrim', $list_lines),
                function ($l) { return $l !== '' && stripos($l, 'no crontab') === false; }
            ));

            $exists = in_array($cron_job, $current_lines, true);

            if ($action === 'add') {
                if ($exists) {
                    $response['success'] = true;
                    $response['message'] = 'Cron job sudah aktif.';
                } else {
                    $current_lines[] = $cron_job;
                }
            } else { // delete
                if (!$exists) {
                    $response['success'] = true;
                    $response['message'] = 'Cron job sudah nonaktif.';
                } else {
                    $current_lines = array_values(array_filter(
                        $current_lines,
                        function ($l) use ($cron_job) { return $l !== $cron_job; }
                    ));
                }
            }

            // Hanya tulis ulang crontab jika ada perubahan yang perlu disimpan
            if ($response['success'] !== true) {
                $tmp_file = tempnam(sys_get_temp_dir(), 'cron_');
                file_put_contents($tmp_file, implode("\n", $current_lines) . "\n");
                exec('crontab ' . escapeshellarg($tmp_file) . ' 2>&1', $install_lines, $install_ret);
                @unlink($tmp_file);

                if ($install_ret === 0) {
                    $response['success'] = true;
                    $response['message'] = $action === 'add' ? 'Cron job berhasil diaktifkan.' : 'Cron job berhasil dinonaktifkan.';
                } else {
                    $response['message'] = 'Gagal menyimpan crontab: ' . implode("\n", $install_lines);
                }
            }
        }
    }

    echo json_encode($response);
    exit;
}

// ── Cron Dismantle: AJAX handlers SEBELUM header.php (hindari HTML output dulu) ──
// Dipindahkan dari pelanggan_menunggak.php ke sini (permintaan user) supaya semua
// pengaturan cron terkumpul di satu tempat (System Setting).
$_cronConfigFile = __DIR__ . '/notifbot/notifphp/config_cron.json';
$_cronConfig     = file_exists($_cronConfigFile)
    ? (json_decode(file_get_contents($_cronConfigFile), true) ?? [])
    : [];

// Support struktur lama dan baru
$_cronConfigData = is_array($_cronConfig['cron_dismantle_ticket'] ?? null)
    ? $_cronConfig['cron_dismantle_ticket']
    : ['enabled_by' => [], 'interval_hours' => 2];

$_cronEnabled    = !empty($_cronConfigData['enabled_by']);

$_isAjaxCron = (
    (isset($_POST['action']) && in_array($_POST['action'], ['toggle_cron_dismantle', 'set_cron_interval_dismantle', 'run_cron_dismantle_manual'], true)) ||
    (isset($_GET['action'])  && $_GET['action']  === 'get_cron_log')
);

if ($_isAjaxCron) {
    // Perlu session untuk cek $AKSES -- include cek-sesi saja (tanpa HTML)
    require_once __DIR__ . '/cek-sesi.php';
    require_once __DIR__ . '/notifbot/notifphp/crontab_sync.php';

    header('Content-Type: application/json; charset=utf-8');

    if ($AKSES !== 'ADMIN') {
        echo json_encode(['success' => false, 'message' => 'Akses ditolak.']);
        exit;
    }

    // $pemilik: WAJIB diisi untuk pemanggilan manual (lihat pemanggil di bawah) --
    // supaya run manual cuma memproses server/area milik user yang menekan tombol,
    // SAMA seperti cron otomatis (qts_sync_wwwdata_crontab() bikin satu baris
    // crontab per PEMILIK, masing-masing dengan ?pemilik=X sendiri-sendiri).
    $runCronManualHttp = static function (string $scriptName, string $pemilik): array {
        @set_time_limit(0);
        $scheme = (!empty($_SERVER['HTTPS']) && (string)$_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = isset($_SERVER['HTTP_HOST']) ? trim((string)$_SERVER['HTTP_HOST']) : '127.0.0.1';
        $scriptDir = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', dirname((string)$_SERVER['SCRIPT_NAME'])) : '/crm/billing';
        if ($scriptDir === '' || $scriptDir === '.' || $scriptDir === '\\') {
            $scriptDir = '/crm/billing';
        }
        $scriptDir = rtrim($scriptDir, '/');
        $url = $scheme . '://' . $host . $scriptDir . '/notifbot/notifphp/' . $scriptName
            . '?manual=1&pemilik=' . rawurlencode($pemilik) . '&_=' . time();

        $raw = '';
        $httpCode = 0;
        $err = '';

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, 90);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            $res = curl_exec($ch);
            if ($res !== false) {
                $raw = (string)$res;
            }
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = (string)curl_error($ch);
            curl_close($ch);
        } else {
            $oldSocketTimeout = @ini_get('default_socket_timeout');
            @ini_set('default_socket_timeout', '90');
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 90,
                    'ignore_errors' => true,
                ],
            ]);
            $res = @file_get_contents($url, false, $ctx);
            if ($res !== false) {
                $raw = (string)$res;
            } else {
                $errInfo = error_get_last();
                $err = $errInfo['message'] ?? 'file_get_contents gagal (kemungkinan timeout/koneksi ditolak).';
            }
            if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', (string)$http_response_header[0], $m)) {
                $httpCode = (int)$m[1];
            }
            if ($oldSocketTimeout !== false) {
                @ini_set('default_socket_timeout', (string)$oldSocketTimeout);
            }
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($raw));
        $tail = is_array($lines) ? array_slice(array_values(array_filter($lines, static fn($v) => trim((string)$v) !== '')), -12) : [];

        if ($httpCode >= 400 || ($raw === '' && $err !== '')) {
            return [
                'success' => false,
                'message' => 'Gagal menjalankan cron manual untuk PEMILIK ' . $pemilik . '.',
                'url' => $url,
                'http_code' => $httpCode,
                'error' => $err,
                'output' => $tail,
            ];
        }

        return [
            'success' => true,
            'message' => 'Cron manual untuk PEMILIK ' . $pemilik . ' berhasil dijalankan.',
            'url' => $url,
            'http_code' => $httpCode,
            'output' => $tail,
        ];
    };

    $resolveOwnedPemilik = static function () use ($conn, $current_user_id): array {
        $list = [];
        $q = mysqli_query($conn, "SELECT DISTINCT PEMILIK FROM server WHERE user_id = " . (int)$current_user_id);
        while ($q && ($row = mysqli_fetch_assoc($q))) {
            $pemilik = trim((string)($row['PEMILIK'] ?? ''));
            if ($pemilik !== '') $list[] = $pemilik;
        }
        return array_values(array_unique($list));
    };

    // Toggle
    if (isset($_POST['action']) && $_POST['action'] === 'toggle_cron_dismantle') {
        $newEnable = isset($_POST['enable']) ? (bool)(int)$_POST['enable'] : true;

        $userServers = [];
        $qSrv = mysqli_query($conn, "SELECT DISTINCT PEMILIK FROM server WHERE user_id = " . (int)$current_user_id);
        while ($qSrv && ($row = mysqli_fetch_assoc($qSrv))) {
            $pemilik = trim((string)($row['PEMILIK'] ?? ''));
            if ($pemilik !== '') $userServers[] = $pemilik;
        }

        if ($AKSES === 'ASSISTANT') {
            $userServers = [];
            $queryPemilik = mysqli_query($conn, "SELECT DISTINCT PEMILIK FROM server WHERE AREA IN ($area_list)");
            while ($queryPemilik && ($row = mysqli_fetch_assoc($queryPemilik))) {
                $pemilik = trim((string)($row['PEMILIK'] ?? ''));
                if ($pemilik !== '') $userServers[] = $pemilik;
            }
        }

        if (empty($userServers)) {
            echo json_encode(['success' => false, 'message' => 'Anda belum memiliki server.']);
            exit;
        }

        if (!is_array($_cronConfig['cron_dismantle_ticket'] ?? null)) {
            $_cronConfig['cron_dismantle_ticket'] = ['enabled_by' => [], 'interval_hours' => 2];
        }

        $enabledList = (array)($_cronConfig['cron_dismantle_ticket']['enabled_by'] ?? []);

        if ($newEnable) {
            foreach ($userServers as $pemilik) {
                if (!in_array($pemilik, $enabledList, true)) {
                    $enabledList[] = $pemilik;
                }
            }
        } else {
            $enabledList = array_filter($enabledList, fn($p) => !in_array($p, $userServers, true));
        }

        $_cronConfig['cron_dismantle_ticket']['enabled_by'] = array_values($enabledList);
        $jsonOut = json_encode($_cronConfig, JSON_PRETTY_PRINT);

        $cronDir = dirname($_cronConfigFile);
        if (!is_dir($cronDir)) {
            @mkdir($cronDir, 0775, true);
        }

        $written = file_put_contents($_cronConfigFile, $jsonOut);
        if ($written === false) {
            $err = error_get_last();
            echo json_encode([
                'success' => false,
                'message' => 'Gagal tulis file: ' . ($err['message'] ?? 'unknown error'),
                'path'    => $_cronConfigFile,
                'writable_dir'  => is_writable($cronDir),
                'writable_file' => file_exists($_cronConfigFile) ? is_writable($_cronConfigFile) : 'not_exists',
            ]);
        } else {
            $dismantleEnabledNow = !empty((array)($_cronConfig['cron_dismantle_ticket']['enabled_by'] ?? []));
            $sync = function_exists('qts_sync_wwwdata_crontab')
                ? qts_sync_wwwdata_crontab(
                    (array)($_cronConfig['cron_maintenance_ticket']['enabled_by'] ?? []),
                    (array)($_cronConfig['cron_dismantle_ticket']['enabled_by'] ?? [])
                )
                : ['success' => false, 'message' => 'Fungsi sinkronisasi tidak ditemukan.'];

            if (!$sync['success']) {
                echo json_encode([
                    'success' => false,
                    'message' => $sync['message'] ?? 'Terjadi kesalahan saat sinkronisasi crontab.',
                ]);
                exit;
            }

            echo json_encode([
                'success' => true,
                'enabled' => $dismantleEnabledNow,
                'pemilik' => $userServers,
                'message' => 'Cron dismantle ' . ($newEnable ? 'diaktifkan' : 'dinonaktifkan') . ' untuk: ' . implode(', ', $userServers),
                'crontab_synced' => (bool)($sync['success'] ?? false),
                'crontab_message' => (string)($sync['message'] ?? ''),
            ]);
            exit;
        }
        exit;
    }

    // Simpan interval
    if (isset($_POST['action']) && $_POST['action'] === 'set_cron_interval_dismantle') {
        $hours = max(1, min(24, (int)($_POST['hours'] ?? 2)));

        if (!is_array($_cronConfig['cron_dismantle_ticket'] ?? null)) {
            $_cronConfig['cron_dismantle_ticket'] = ['enabled_by' => [], 'interval_hours' => 2];
        }

        $_cronConfig['cron_dismantle_ticket']['interval_hours'] = $hours;

        $cronDir = dirname($_cronConfigFile);
        if (!is_dir($cronDir)) @mkdir($cronDir, 0775, true);
        $written = file_put_contents($_cronConfigFile, json_encode($_cronConfig, JSON_PRETTY_PRINT));
        if ($written === false) {
            $err = error_get_last();
            echo json_encode(['success' => false, 'message' => 'Gagal tulis: ' . ($err['message'] ?? 'unknown')]);
        } else {
            echo json_encode(['success' => true, 'hours' => $hours]);
        }
        exit;
    }

    // Jalankan manual (tanpa cron scheduler) -- di-scope ke PEMILIK milik user ini saja.
    if (isset($_POST['action']) && $_POST['action'] === 'run_cron_dismantle_manual') {
        $ownedPemilik = $resolveOwnedPemilik();
        if (empty($ownedPemilik)) {
            echo json_encode(['success' => false, 'message' => 'Anda belum memiliki server/area untuk dijalankan cron-nya.']);
            exit;
        }

        $allOutput = [];
        $allSuccess = true;
        foreach ($ownedPemilik as $pemilikRun) {
            $run = $runCronManualHttp('cron_dismantle_ticket.php', $pemilikRun);
            $allOutput[] = '=== PEMILIK: ' . $pemilikRun . ' ===';
            $allOutput = array_merge($allOutput, $run['output'] ?? []);
            if (!$run['success']) {
                $allSuccess = false;
                $allOutput[] = '[ERROR] ' . ($run['message'] ?? 'Gagal') . (!empty($run['error']) ? ' (' . $run['error'] . ')' : '');
            }
        }

        echo json_encode([
            'success' => $allSuccess,
            'message' => $allSuccess
                ? 'Cron dismantle manual berhasil dijalankan untuk ' . count($ownedPemilik) . ' server milik Anda.'
                : 'Sebagian/semua PEMILIK gagal dijalankan, lihat detail di output.',
            'output' => $allOutput,
        ]);
        exit;
    }

    // Lihat log
    if (isset($_GET['action']) && $_GET['action'] === 'get_cron_log') {
        $_logFile = __DIR__ . '/notifbot/notifphp/log_dismantle_ticket.log';
        $lines = [];
        if (file_exists($_logFile)) {
            $allLines = file($_logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $lines = array_slice($allLines, -30);
        }
        echo json_encode(['success' => true, 'lines' => $lines]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Action tidak dikenal.']);
    exit;
}

$_cronIntervalHours = max(1, (int)($_cronConfigData['interval_hours'] ?? 2));
$_diLastRunFile     = __DIR__ . '/notifbot/notifphp/cron_dismantle_ticket.lastrun';
$_cronLastRun       = file_exists($_diLastRunFile) ? (int)trim(@file_get_contents($_diLastRunFile)) : 0;
$_cronNextRun       = $_cronLastRun > 0 ? $_cronLastRun + ($_cronIntervalHours * 3600) : 0;
$_cronLastRunFmt    = $_cronLastRun > 0 ? date('d/m/Y H:i', $_cronLastRun) : 'belum pernah';
$_cronNextRunFmt    = $_cronNextRun > time() ? date('d/m/Y H:i', $_cronNextRun) : ($_cronLastRun > 0 ? 'segera' : '-');

// ── Cron Maintenance: AJAX handlers SEBELUM header.php ────────────────────────
// Dipindahkan dari tables.php ke sini (permintaan user).
$_mCronConfigFile = __DIR__ . '/notifbot/notifphp/config_cron.json';
$_mCronConfig     = file_exists($_mCronConfigFile)
    ? (json_decode(file_get_contents($_mCronConfigFile), true) ?? [])
    : [];

$_mCronConfigData = is_array($_mCronConfig['cron_maintenance_ticket'] ?? null)
    ? $_mCronConfig['cron_maintenance_ticket']
    : ['enabled_by' => [], 'interval_hours' => 2];

$_mCronEnabled    = !empty($_mCronConfigData['enabled_by']);

$_isMCronAjax = (
    (isset($_POST['action']) && in_array($_POST['action'], ['toggle_cron_maintenance', 'set_cron_interval_maintenance', 'run_cron_maintenance_manual'], true)) ||
    (isset($_GET['action'])  && $_GET['action']  === 'get_cron_maintenance_log')
);

if ($_isMCronAjax) {
    require_once __DIR__ . '/cek-sesi.php';
    require_once __DIR__ . '/notifbot/notifphp/crontab_sync.php';
    header('Content-Type: application/json; charset=utf-8');

    if ($AKSES !== 'ADMIN') {
        echo json_encode(['success' => false, 'message' => 'Akses ditolak.']);
        exit;
    }

    $runCronManualHttpM = static function (string $scriptName, string $pemilik): array {
        @set_time_limit(0);
        $scheme = (!empty($_SERVER['HTTPS']) && (string)$_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = isset($_SERVER['HTTP_HOST']) ? trim((string)$_SERVER['HTTP_HOST']) : '127.0.0.1';
        $scriptDir = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', dirname((string)$_SERVER['SCRIPT_NAME'])) : '/crm/billing';
        if ($scriptDir === '' || $scriptDir === '.' || $scriptDir === '\\') {
            $scriptDir = '/crm/billing';
        }
        $scriptDir = rtrim($scriptDir, '/');
        $url = $scheme . '://' . $host . $scriptDir . '/notifbot/notifphp/' . $scriptName
            . '?manual=1&pemilik=' . rawurlencode($pemilik) . '&_=' . time();

        $raw = '';
        $httpCode = 0;
        $err = '';

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, 90);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            $res = curl_exec($ch);
            if ($res !== false) {
                $raw = (string)$res;
            }
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = (string)curl_error($ch);
            curl_close($ch);
        } else {
            $oldSocketTimeout = @ini_get('default_socket_timeout');
            @ini_set('default_socket_timeout', '90');
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 90,
                    'ignore_errors' => true,
                ],
            ]);
            $res = @file_get_contents($url, false, $ctx);
            if ($res !== false) {
                $raw = (string)$res;
            } else {
                $errInfo = error_get_last();
                $err = $errInfo['message'] ?? 'file_get_contents gagal (kemungkinan timeout/koneksi ditolak).';
            }
            if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', (string)$http_response_header[0], $m)) {
                $httpCode = (int)$m[1];
            }
            if ($oldSocketTimeout !== false) {
                @ini_set('default_socket_timeout', (string)$oldSocketTimeout);
            }
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($raw));
        $tail = is_array($lines) ? array_slice(array_values(array_filter($lines, static fn($v) => trim((string)$v) !== '')), -12) : [];

        if ($httpCode >= 400 || ($raw === '' && $err !== '')) {
            return [
                'success' => false,
                'message' => 'Gagal menjalankan cron manual untuk PEMILIK ' . $pemilik . '.',
                'url' => $url,
                'http_code' => $httpCode,
                'error' => $err,
                'output' => $tail,
            ];
        }

        return [
            'success' => true,
            'message' => 'Cron manual untuk PEMILIK ' . $pemilik . ' berhasil dijalankan.',
            'url' => $url,
            'http_code' => $httpCode,
            'output' => $tail,
        ];
    };

    $resolveOwnedPemilikMaintenance = static function () use ($conn, $current_user_id): array {
        $list = [];
        $q = mysqli_query($conn, "SELECT DISTINCT PEMILIK FROM server WHERE user_id = " . (int)$current_user_id);
        while ($q && ($row = mysqli_fetch_assoc($q))) {
            $pemilik = trim((string)($row['PEMILIK'] ?? ''));
            if ($pemilik !== '') $list[] = $pemilik;
        }
        return array_values(array_unique($list));
    };

    // Toggle
    if (isset($_POST['action']) && $_POST['action'] === 'toggle_cron_maintenance') {
        $newEnable = isset($_POST['enable']) ? (bool)(int)$_POST['enable'] : true;

        $userServers = [];
        $qSrv = mysqli_query($conn, "SELECT DISTINCT PEMILIK FROM server WHERE user_id = " . (int)$current_user_id);
        while ($qSrv && ($row = mysqli_fetch_assoc($qSrv))) {
            $pemilik = trim((string)($row['PEMILIK'] ?? ''));
            if ($pemilik !== '') $userServers[] = $pemilik;
        }

        if (empty($userServers)) {
            echo json_encode(['success' => false, 'message' => 'Anda belum memiliki server.']);
            exit;
        }

        if (!is_array($_mCronConfig['cron_maintenance_ticket'] ?? null)) {
            $_mCronConfig['cron_maintenance_ticket'] = ['enabled_by' => [], 'interval_hours' => 2];
        }

        $enabledList = (array)($_mCronConfig['cron_maintenance_ticket']['enabled_by'] ?? []);

        if ($newEnable) {
            foreach ($userServers as $pemilik) {
                if (!in_array($pemilik, $enabledList, true)) {
                    $enabledList[] = $pemilik;
                }
            }
        } else {
            $enabledList = array_filter($enabledList, fn($p) => !in_array($p, $userServers, true));
        }

        $_mCronConfig['cron_maintenance_ticket']['enabled_by'] = array_values($enabledList);

        $cronDir = dirname($_mCronConfigFile);
        if (!is_dir($cronDir)) @mkdir($cronDir, 0775, true);

        $written = file_put_contents($_mCronConfigFile, json_encode($_mCronConfig, JSON_PRETTY_PRINT));
        if ($written === false) {
            $err = error_get_last();
            echo json_encode([
                'success'       => false,
                'message'       => 'Gagal tulis file: ' . ($err['message'] ?? 'unknown'),
                'path'          => $_mCronConfigFile,
                'writable_dir'  => is_writable($cronDir),
                'writable_file' => file_exists($_mCronConfigFile) ? is_writable($_mCronConfigFile) : 'not_exists',
            ]);
        } else {
            $sync = function_exists('qts_sync_wwwdata_crontab')
                ? qts_sync_wwwdata_crontab(
                    (array)($_mCronConfig['cron_maintenance_ticket']['enabled_by'] ?? []),
                    (array)($_cronConfig['cron_dismantle_ticket']['enabled_by'] ?? [])
                )
                : ['success' => false, 'message' => 'Helper crontab sync tidak ditemukan.'];

            echo json_encode([
                'success'   => true,
                'enabled'   => count($enabledList) > 0,
                'pemilik'   => $userServers,
                'message'   => 'Cron maintenance ' . ($newEnable ? 'diaktifkan' : 'dinonaktifkan') . ' untuk: ' . implode(', ', $userServers),
                'crontab_synced' => (bool)($sync['success'] ?? false),
                'crontab_message' => (string)($sync['message'] ?? ''),
            ]);
        }
        exit;
    }

    // Simpan interval
    if (isset($_POST['action']) && $_POST['action'] === 'set_cron_interval_maintenance') {
        $hours = max(1, min(24, (int)($_POST['hours'] ?? 2)));

        if (!is_array($_mCronConfig['cron_maintenance_ticket'] ?? null)) {
            $_mCronConfig['cron_maintenance_ticket'] = ['enabled_by' => [], 'interval_hours' => 2];
        }

        $_mCronConfig['cron_maintenance_ticket']['interval_hours'] = $hours;

        $cronDir = dirname($_mCronConfigFile);
        if (!is_dir($cronDir)) @mkdir($cronDir, 0775, true);
        $written = file_put_contents($_mCronConfigFile, json_encode($_mCronConfig, JSON_PRETTY_PRINT));
        if ($written === false) {
            $err = error_get_last();
            echo json_encode(['success' => false, 'message' => 'Gagal tulis: ' . ($err['message'] ?? 'unknown')]);
        } else {
            echo json_encode(['success' => true, 'hours' => $hours]);
        }
        exit;
    }

    // Jalankan manual -- di-scope ke PEMILIK milik user ini saja.
    if (isset($_POST['action']) && $_POST['action'] === 'run_cron_maintenance_manual') {
        $ownedPemilik = $resolveOwnedPemilikMaintenance();
        if (empty($ownedPemilik)) {
            echo json_encode(['success' => false, 'message' => 'Anda belum memiliki server/area untuk dijalankan cron-nya.']);
            exit;
        }

        $allOutput = [];
        $allSuccess = true;
        foreach ($ownedPemilik as $pemilikRun) {
            $run = $runCronManualHttpM('cron_maintenance_ticket.php', $pemilikRun);
            $allOutput[] = '=== PEMILIK: ' . $pemilikRun . ' ===';
            $allOutput = array_merge($allOutput, $run['output'] ?? []);
            if (!$run['success']) {
                $allSuccess = false;
                $allOutput[] = '[ERROR] ' . ($run['message'] ?? 'Gagal') . (!empty($run['error']) ? ' (' . $run['error'] . ')' : '');
            }
        }

        echo json_encode([
            'success' => $allSuccess,
            'message' => $allSuccess
                ? 'Cron maintenance manual berhasil dijalankan untuk ' . count($ownedPemilik) . ' server milik Anda.'
                : 'Sebagian/semua PEMILIK gagal dijalankan, lihat detail di output.',
            'output' => $allOutput,
        ]);
        exit;
    }

    // Lihat log
    if (isset($_GET['action']) && $_GET['action'] === 'get_cron_maintenance_log') {
        $_logFile = __DIR__ . '/notifbot/notifphp/log_maintenance_ticket.log';
        $lines = [];
        if (file_exists($_logFile)) {
            $allLines = file($_logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $lines = array_slice($allLines, -30);
        }
        echo json_encode(['success' => true, 'lines' => $lines]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Action tidak dikenal.']);
    exit;
}

$_mCronIntervalHours = max(1, (int)($_mCronConfigData['interval_hours'] ?? 2));
$_miLastRunFile      = __DIR__ . '/notifbot/notifphp/cron_maintenance_ticket.lastrun';
$_mCronLastRun       = file_exists($_miLastRunFile) ? (int)trim(@file_get_contents($_miLastRunFile)) : 0;
$_mCronNextRun       = $_mCronLastRun > 0 ? $_mCronLastRun + ($_mCronIntervalHours * 3600) : 0;
$_mCronLastRunFmt    = $_mCronLastRun > 0 ? date('d/m/Y H:i', $_mCronLastRun) : 'belum pernah';
$_mCronNextRunFmt    = $_mCronNextRun > time() ? date('d/m/Y H:i', $_mCronNextRun) : ($_mCronLastRun > 0 ? 'segera' : '-');

// ── Cron NMS Poll (grafik historis/uptime/alert device down): AJAX handlers
// SEBELUM header.php, pola sama persis dgn Cron Dismantle/Maintenance di atas
// tapi pakai marker crontab & fungsi sync TERPISAH (crontab_sync_nms.php)
// supaya tidak berisiko mengganggu baris crontab dismantle/maintenance yg
// sudah berjalan. Scoping kepemilikan: network_devices.user_id (bukan lewat
// tabel server/PEMILIK spt dismantle), tapi cron itu sendiri tetap dijalankan
// per-PEMILIK (konsisten dgn pola network_devices_poll_cron.php?pemilik=X).
$_nmsCronConfigFile = __DIR__ . '/notifbot/notifphp/config_cron.json';
$_nmsCronConfig     = file_exists($_nmsCronConfigFile)
    ? (json_decode(file_get_contents($_nmsCronConfigFile), true) ?? [])
    : [];
$_nmsCronConfigData = is_array($_nmsCronConfig['network_devices_poll'] ?? null)
    ? $_nmsCronConfig['network_devices_poll']
    : ['enabled_by' => [], 'interval_minutes' => 5];
$_nmsCronEnabled    = !empty($_nmsCronConfigData['enabled_by']);

$_isAjaxNmsCron = (
    (isset($_POST['action']) && in_array($_POST['action'], ['toggle_cron_nms', 'set_cron_interval_nms', 'run_cron_nms_manual'], true)) ||
    (isset($_GET['action']) && $_GET['action'] === 'get_cron_nms_log')
);

if ($_isAjaxNmsCron) {
    require_once __DIR__ . '/cek-sesi.php';
    require_once __DIR__ . '/notifbot/notifphp/crontab_sync_nms.php';

    header('Content-Type: application/json; charset=utf-8');

    if ($AKSES !== 'ADMIN') {
        echo json_encode(['success' => false, 'message' => 'Akses ditolak.']);
        exit;
    }

    $resolveOwnPemilik = static function () use ($ceknama): array {
        return $ceknama !== '' ? [$ceknama] : [];
    };

    $runNmsCronManualHttp = static function (string $pemilik): array {
        @set_time_limit(0);
        $scheme = (!empty($_SERVER['HTTPS']) && (string)$_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = isset($_SERVER['HTTP_HOST']) ? trim((string)$_SERVER['HTTP_HOST']) : '127.0.0.1';
        $scriptDir = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', dirname((string)$_SERVER['SCRIPT_NAME'])) : '/crm/billing';
        if ($scriptDir === '' || $scriptDir === '.' || $scriptDir === '\\') {
            $scriptDir = '/crm/billing';
        }
        $scriptDir = rtrim($scriptDir, '/');
        $url = $scheme . '://' . $host . $scriptDir . '/notifbot/notifphp/network_devices_poll_cron.php?pemilik=' . rawurlencode($pemilik) . '&_=' . time();

        $raw = '';
        $httpCode = 0;
        $err = '';
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, 90);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            $res = curl_exec($ch);
            if ($res !== false) $raw = (string)$res;
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = (string)curl_error($ch);
            curl_close($ch);
        }
        $lines = preg_split('/\r\n|\r|\n/', trim($raw));
        $tail = is_array($lines) ? array_values(array_filter($lines, static fn($v) => trim((string)$v) !== '')) : [];

        if ($httpCode >= 400 || ($raw === '' && $err !== '')) {
            return ['success' => false, 'message' => 'Gagal menjalankan cron NMS untuk ' . $pemilik . '.', 'output' => $tail, 'error' => $err];
        }
        return ['success' => true, 'message' => 'Cron NMS untuk ' . $pemilik . ' berhasil dijalankan.', 'output' => $tail];
    };

    if (isset($_POST['action']) && $_POST['action'] === 'toggle_cron_nms') {
        $newEnable = isset($_POST['enable']) ? (bool)(int)$_POST['enable'] : true;
        $ownPemilik = $resolveOwnPemilik();
        if (empty($ownPemilik)) {
            echo json_encode(['success' => false, 'message' => 'Akun tidak valid.']);
            exit;
        }

        if (!is_array($_nmsCronConfig['network_devices_poll'] ?? null)) {
            $_nmsCronConfig['network_devices_poll'] = ['enabled_by' => [], 'interval_minutes' => 5];
        }
        $enabledList = (array)($_nmsCronConfig['network_devices_poll']['enabled_by'] ?? []);
        if ($newEnable) {
            foreach ($ownPemilik as $p) {
                if (!in_array($p, $enabledList, true)) $enabledList[] = $p;
            }
        } else {
            $enabledList = array_filter($enabledList, fn($p) => !in_array($p, $ownPemilik, true));
        }
        $_nmsCronConfig['network_devices_poll']['enabled_by'] = array_values($enabledList);

        $cronDir = dirname($_nmsCronConfigFile);
        if (!is_dir($cronDir)) @mkdir($cronDir, 0775, true);
        $written = file_put_contents($_nmsCronConfigFile, json_encode($_nmsCronConfig, JSON_PRETTY_PRINT));
        if ($written === false) {
            echo json_encode(['success' => false, 'message' => 'Gagal tulis file config_cron.json.']);
            exit;
        }

        $intervalNow = max(1, min(60, (int)($_nmsCronConfig['network_devices_poll']['interval_minutes'] ?? 5)));
        $sync = qts_sync_nms_poll_crontab((array)$_nmsCronConfig['network_devices_poll']['enabled_by'], $intervalNow);

        echo json_encode([
            'success' => true,
            'enabled' => !empty($_nmsCronConfig['network_devices_poll']['enabled_by']),
            'message' => 'Cron NMS ' . ($newEnable ? 'diaktifkan' : 'dinonaktifkan') . '.',
            'crontab_synced' => (bool)($sync['success'] ?? false),
            'crontab_message' => (string)($sync['message'] ?? ''),
        ]);
        exit;
    }

    if (isset($_POST['action']) && $_POST['action'] === 'set_cron_interval_nms') {
        $minutes = max(1, min(60, (int)($_POST['minutes'] ?? 5)));
        if (!is_array($_nmsCronConfig['network_devices_poll'] ?? null)) {
            $_nmsCronConfig['network_devices_poll'] = ['enabled_by' => [], 'interval_minutes' => 5];
        }
        $_nmsCronConfig['network_devices_poll']['interval_minutes'] = $minutes;
        $cronDir = dirname($_nmsCronConfigFile);
        if (!is_dir($cronDir)) @mkdir($cronDir, 0775, true);
        $written = file_put_contents($_nmsCronConfigFile, json_encode($_nmsCronConfig, JSON_PRETTY_PRINT));
        if ($written === false) {
            echo json_encode(['success' => false, 'message' => 'Gagal tulis file.']);
            exit;
        }
        $sync = qts_sync_nms_poll_crontab((array)$_nmsCronConfig['network_devices_poll']['enabled_by'], $minutes);
        echo json_encode(['success' => true, 'minutes' => $minutes, 'crontab_synced' => (bool)($sync['success'] ?? false), 'crontab_message' => (string)($sync['message'] ?? '')]);
        exit;
    }

    if (isset($_POST['action']) && $_POST['action'] === 'run_cron_nms_manual') {
        $ownPemilik = $resolveOwnPemilik();
        if (empty($ownPemilik)) {
            echo json_encode(['success' => false, 'message' => 'Akun tidak valid.']);
            exit;
        }
        $allOutput = [];
        $allSuccess = true;
        foreach ($ownPemilik as $p) {
            $run = $runNmsCronManualHttp($p);
            $allOutput = array_merge($allOutput, $run['output'] ?? []);
            if (!$run['success']) {
                $allSuccess = false;
                $allOutput[] = '[ERROR] ' . ($run['message'] ?? 'Gagal');
            }
        }
        echo json_encode(['success' => $allSuccess, 'message' => $allSuccess ? 'Cron NMS manual berhasil dijalankan.' : 'Gagal, lihat output.', 'output' => $allOutput]);
        exit;
    }

    if (isset($_GET['action']) && $_GET['action'] === 'get_cron_nms_log') {
        // Belum ada file log khusus (cron NMS hanya echo output singkat), jadi
        // cukup tampilkan pesan generik -- beda dgn dismantle/maintenance yg
        // sudah punya log_*.log dari lama.
        echo json_encode(['success' => true, 'lines' => ['Cron NMS tidak menyimpan log file terpisah. Gunakan tombol "Jalankan Manual" utk lihat output langsung, atau cek tabel network_device_log di database untuk histori polling.']]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Action tidak dikenal.']);
    exit;
}

$_nmsCronIntervalMinutes = max(1, min(60, (int)($_nmsCronConfigData['interval_minutes'] ?? 5)));

require 'header.php';

if ($AKSES == 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('System_setting', $akses_menu, true)) {
        echo '<div class="container-fluid py-4"><div class="alert alert-danger">Anda tidak memiliki akses ke menu System Setting.</div></div>';
        require 'footer.php';
        exit;
    }
}

// --- Variabel read-only untuk membangun daftar perintah cron ---
// (disalin dari notification.php supaya card "Trun on" bisa berdiri sendiri di
// halaman ini - hanya bagian BACA konfigurasinya; form untuk MENGUBAH pengaturan
// jadwal reminder/invoice/ODP LOS tetap ada di menu Notifikasi seperti biasa)
$directory = "notifbot/data";

$odp_los_interval_config_file = __DIR__ . "/notifbot/data/odp_los_interval-$username.json";
$odp_los_interval_unit = 'jam';
$odp_los_interval_value = 1;
if (file_exists($odp_los_interval_config_file)) {
    $odp_los_cfg = json_decode(file_get_contents($odp_los_interval_config_file), true);
    if (is_array($odp_los_cfg)) {
        $odp_los_interval_unit = in_array(($odp_los_cfg['unit'] ?? 'jam'), ['menit', 'jam'], true) ? $odp_los_cfg['unit'] : 'jam';
        $odp_los_interval_value = (int) ($odp_los_cfg['value'] ?? 1);
    }
}

$invoice_config_file = "$directory/invoice_generator-$username.json";
$invoice_generate_schedule = 'monthly_range';
$invoice_generate_start_day = 25;
$invoice_generate_hour = 7;
$invoice_generate_minute = 0;
if (file_exists($invoice_config_file)) {
    $invoice_cfg = json_decode(file_get_contents($invoice_config_file), true);
    if (is_array($invoice_cfg)) {
        $invoice_generate_schedule = (($invoice_cfg['schedule'] ?? 'monthly_range') === 'daily') ? 'daily' : 'monthly_range';
        $invoice_generate_start_day = isset($invoice_cfg['start_day']) ? (int) $invoice_cfg['start_day'] : 25;
        $invoice_generate_hour = isset($invoice_cfg['hour']) ? (int) $invoice_cfg['hour'] : 7;
        $invoice_generate_minute = isset($invoice_cfg['minute']) ? (int) $invoice_cfg['minute'] : 0;
    }
}
$invoice_generate_start_day = max(1, min(31, $invoice_generate_start_day));
$invoice_generate_hour = max(0, min(23, $invoice_generate_hour));
$invoice_generate_minute = max(0, min(59, $invoice_generate_minute));
?>

<div class="container-fluid py-4 px-3 px-md-4">
    <div class="row">
        <div class="col-12">

<?php if ($AKSES === 'ADMIN'): ?>
    <!-- -- Card Cron Tiket Dismantle Otomatis (dipindahkan dari pelanggan_menunggak.php) -- -->
    <div class="card shadow-sm mb-4" id="cardCronDismantle">
        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
                <i class="fas fa-robot"></i>
                <span class="fw-bold">Cron Tiket Dismantle Otomatis</span>
                <span id="cronStatusBadge" class="badge <?php echo $_cronEnabled ? 'bg-success' : 'bg-secondary'; ?>">
                    <?php echo $_cronEnabled ? 'AKTIF' : 'NONAKTIF'; ?>
                </span>
            </div>
            <div class="d-flex align-items-center gap-3">
                <div class="form-check form-switch mb-0">
                    <input
                        class="form-check-input"
                        type="checkbox"
                        role="switch"
                        id="toggleCronDismantle"
                        <?php echo $_cronEnabled ? 'checked' : ''; ?>
                        style="width:3em;height:1.5em;cursor:pointer;"
                    >
                    <label class="form-check-label text-white" for="toggleCronDismantle">
                        <span id="toggleCronLabel"><?php echo $_cronEnabled ? 'ON' : 'OFF'; ?></span>
                    </label>
                </div>
                <button class="btn btn-outline-light btn-sm" id="btnShowCronLog">
                    <i class="fas fa-file-alt me-1"></i>Lihat Log
                </button>
                <button class="btn btn-warning btn-sm" id="btnRunCronDismantleNow">
                    <i class="fas fa-play me-1"></i>Jalankan Manual
                </button>
            </div>
        </div>
        <div class="card-body pb-2">
            <div class="row g-3 mb-2">
                <div class="col-md-4">
                    <div class="d-flex align-items-start gap-2">
                        <i class="fas fa-plus-circle text-success mt-1"></i>
                        <div>
                            <div class="fw-semibold small">Buat Tiket DISMANTLE</div>
                            <div class="text-muted" style="font-size:.82rem;">Otomatis buat tiket jika pelanggan menunggak &amp; belum ada tiket aktif</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="d-flex align-items-start gap-2">
                        <i class="fas fa-times-circle text-danger mt-1"></i>
                        <div>
                            <div class="fw-semibold small">Hapus Tiket (sudah bayar + online)</div>
                            <div class="text-muted" style="font-size:.82rem;">Cancel tiket jika pelanggan sudah tidak menunggak &amp; status online</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="d-flex align-items-start gap-2">
                        <i class="fas fa-tools text-warning mt-1"></i>
                        <div>
                            <div class="fw-semibold small">Ubah ke MAINTENANCE (sudah bayar + offline)</div>
                            <div class="text-muted" style="font-size:.82rem;">Jadikan tipe tiket MAINTENANCE jika sudah bayar tapi masih offline</div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Pengaturan interval -->
            <div class="d-flex flex-wrap align-items-center gap-3 pt-2 border-top mt-1 mb-2">
                <div class="d-flex align-items-center gap-2">
                    <i class="fas fa-clock text-muted small"></i>
                    <span class="small fw-semibold">Interval cek:</span>
                    <select id="cronIntervalSelect" class="form-select form-select-sm" style="width:auto;">
                        <option value="1"  <?php echo $_cronIntervalHours==1  ? 'selected' : ''; ?>>1 jam</option>
                        <option value="2"  <?php echo $_cronIntervalHours==2  ? 'selected' : ''; ?>>2 jam</option>
                        <option value="4"  <?php echo $_cronIntervalHours==4  ? 'selected' : ''; ?>>4 jam</option>
                        <option value="6"  <?php echo $_cronIntervalHours==6  ? 'selected' : ''; ?>>6 jam</option>
                        <option value="12" <?php echo $_cronIntervalHours==12 ? 'selected' : ''; ?>>12 jam</option>
                        <option value="24" <?php echo $_cronIntervalHours==24 ? 'selected' : ''; ?>>24 jam</option>
                    </select>
                    <button class="btn btn-primary btn-sm" id="btnSaveCronInterval">
                        <i class="fas fa-save me-1"></i>Simpan
                    </button>
                </div>
                <div class="small text-muted">
                    <i class="fas fa-history me-1"></i>Terakhir jalan: <strong><?php echo $_cronLastRunFmt; ?></strong>
                    &nbsp;&nbsp;<i class="fas fa-forward me-1"></i>Berikutnya: <strong id="cronNextRunDisplay"><?php echo $_cronNextRunFmt; ?></strong>
                </div>
            </div>
            <!-- Log viewer (collapsed by default) -->
            <div id="cronLogPanel" class="d-none mt-2">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="small text-muted">30 baris terakhir log cron</span>
                    <button class="btn btn-outline-secondary btn-sm" id="btnRefreshLog">
                        <i class="fas fa-sync-alt me-1"></i>Refresh Log
                    </button>
                </div>
                <pre id="cronLogContent" class="bg-dark text-light rounded p-3" style="font-size:.75rem;max-height:260px;overflow-y:auto;white-space:pre-wrap;word-break:break-all;">Memuat log...</pre>
            </div>
        </div>
    </div>

    <script>
    (function () {
        const toggle    = document.getElementById('toggleCronDismantle');
        const badge     = document.getElementById('cronStatusBadge');
        const label     = document.getElementById('toggleCronLabel');
        const btnLog    = document.getElementById('btnShowCronLog');
        const logPanel  = document.getElementById('cronLogPanel');
        const logContent= document.getElementById('cronLogContent');
        const btnRefresh= document.getElementById('btnRefreshLog');
        const btnRunNow = document.getElementById('btnRunCronDismantleNow');

        function setVisual(enabled) {
            badge.textContent  = enabled ? 'AKTIF' : 'NONAKTIF';
            badge.className    = 'badge ' + (enabled ? 'bg-success' : 'bg-secondary');
            label.textContent  = enabled ? 'ON' : 'OFF';
        }

        toggle.addEventListener('change', function () {
            const enable = this.checked ? 1 : 0;
            toggle.disabled = true;
            const fd = new FormData();
            fd.append('action',  'toggle_cron_dismantle');
            fd.append('enable',  enable);
            fetch(window.location.pathname, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        setVisual(data.enabled);
                        if (data.crontab_synced === false) {
                            alert((data.crontab_message || 'Sync crontab gagal.') + '\nToggle aplikasi tetap tersimpan.');
                        }
                    } else {
                        toggle.checked = !toggle.checked;
                        const msg = data.message || 'Gagal menyimpan pengaturan cron.';
                        const detail = data.path ? '\nPath: ' + data.path + '\nWritable dir: ' + data.writable_dir + '\nWritable file: ' + data.writable_file : '';
                        alert(msg + detail);
                    }
                })
                .catch(() => {
                    toggle.checked = !toggle.checked;
                    alert('Terjadi kesalahan koneksi.');
                })
                .finally(() => { toggle.disabled = false; });
        });

        function loadLog() {
            logContent.textContent = 'Memuat log...';
            fetch(window.location.pathname + '?action=get_cron_log')
                .then(r => r.json())
                .then(data => {
                    if (data.lines && data.lines.length > 0) {
                        logContent.textContent = data.lines.join('\n');
                    } else {
                        logContent.textContent = '(log kosong -- cron belum pernah berjalan)';
                    }
                    logContent.scrollTop = logContent.scrollHeight;
                })
                .catch(() => { logContent.textContent = 'Gagal memuat log.'; });
        }

        btnLog.addEventListener('click', function () {
            const hidden = logPanel.classList.toggle('d-none');
            if (!hidden) loadLog();
        });

        btnRefresh.addEventListener('click', loadLog);

        btnRunNow.addEventListener('click', function () {
            btnRunNow.disabled = true;
            const oldText = btnRunNow.innerHTML;
            btnRunNow.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Menjalankan...';
            const fd = new FormData();
            fd.append('action', 'run_cron_dismantle_manual');
            fetch(window.location.pathname, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    const outputText = Array.isArray(data.output) && data.output.length > 0
                        ? data.output.join('\n')
                        : '(tanpa output)';
                    logPanel.classList.remove('d-none');
                    if (!data.success) {
                        const err = data.message || 'Gagal menjalankan cron manual.';
                        const detail = data.error ? ('\nError: ' + data.error) : '';
                        logContent.textContent = '[Manual Cron][ERROR] ' + err + detail + '\n\nOutput:\n' + outputText;
                        return;
                    }
                    logContent.textContent = '[Manual Cron][OK] Cron dismantle manual berhasil dijalankan.\n\nOutput:\n' + outputText;
                    loadLog();
                })
                .catch(() => {
                    logPanel.classList.remove('d-none');
                    logContent.textContent = '[Manual Cron][ERROR] Terjadi kesalahan koneksi saat menjalankan manual.';
                })
                .finally(() => {
                    btnRunNow.disabled = false;
                    btnRunNow.innerHTML = oldText;
                });
        });

        const intervalSelect  = document.getElementById('cronIntervalSelect');
        const btnSaveInterval = document.getElementById('btnSaveCronInterval');
        const nextRunDisplay  = document.getElementById('cronNextRunDisplay');

        btnSaveInterval.addEventListener('click', function () {
            const hours = parseInt(intervalSelect.value, 10);
            btnSaveInterval.disabled = true;
            const fd = new FormData();
            fd.append('action', 'set_cron_interval_dismantle');
            fd.append('hours', hours);
            fetch(window.location.pathname, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        nextRunDisplay.textContent = '(akan diupdate saat cron berikutnya berjalan)';
                        alert('Interval berhasil disimpan: ' + data.hours + ' jam');
                    } else {
                        alert(data.message || 'Gagal simpan interval.');
                    }
                })
                .catch(() => alert('Terjadi kesalahan koneksi.'))
                .finally(() => { btnSaveInterval.disabled = false; });
        });
    })();
    </script>

    <!-- ── Card Cron Tiket Maintenance Otomatis (dipindahkan dari tables.php) ── -->
    <div class="card shadow-sm mb-4" id="cardCronMaintenance">
        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
                <i class="fas fa-tools"></i>
                <span class="fw-bold">Cron Tiket Maintenance Otomatis</span>
                <span id="mCronStatusBadge" class="badge <?php echo $_mCronEnabled ? 'bg-success' : 'bg-secondary'; ?>">
                    <?php echo $_mCronEnabled ? 'AKTIF' : 'NONAKTIF'; ?>
                </span>
            </div>
            <div class="d-flex align-items-center gap-3">
                <div class="form-check form-switch mb-0">
                    <input
                        class="form-check-input"
                        type="checkbox"
                        role="switch"
                        id="toggleCronMaintenance"
                        <?php echo $_mCronEnabled ? 'checked' : ''; ?>
                        style="width:3em;height:1.5em;cursor:pointer;"
                    >
                    <label class="form-check-label text-white" for="toggleCronMaintenance">
                        <span id="mToggleCronLabel"><?php echo $_mCronEnabled ? 'ON' : 'OFF'; ?></span>
                    </label>
                </div>
                <button class="btn btn-outline-light btn-sm" id="btnShowMCronLog">
                    <i class="fas fa-file-alt me-1"></i>Lihat Log
                </button>
                <button class="btn btn-warning btn-sm" id="btnRunMCronNow">
                    <i class="fas fa-play me-1"></i>Jalankan Sekarang
                </button>
            </div>
        </div>
        <div class="card-body pb-2">
            <div class="row g-3 mb-2">
                <div class="col-md-6">
                    <div class="d-flex align-items-start gap-2">
                        <i class="fas fa-plus-circle text-warning mt-1"></i>
                        <div>
                            <div class="fw-semibold small">Buat Tiket MAINTENANCE</div>
                            <div class="text-muted" style="font-size:.82rem;">Otomatis buat tiket jika pelanggan <strong>offline/LOS</strong> tapi <strong>tidak menunggak</strong> &amp; belum ada tiket aktif</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="d-flex align-items-start gap-2">
                        <i class="fas fa-times-circle text-danger mt-1"></i>
                        <div>
                            <div class="fw-semibold small">Hapus Tiket (sudah online + tidak expired)</div>
                            <div class="text-muted" style="font-size:.82rem;">Cancel tiket MAINTENANCE jika pelanggan sudah <strong>online kembali</strong> dan tidak menunggak</div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Pengaturan interval -->
            <div class="d-flex flex-wrap align-items-center gap-3 pt-2 border-top mt-1 mb-2">
                <div class="d-flex align-items-center gap-2">
                    <i class="fas fa-clock text-muted small"></i>
                    <span class="small fw-semibold">Interval cek:</span>
                    <select id="mCronIntervalSelect" class="form-select form-select-sm" style="width:auto;">
                        <option value="1"  <?php echo $_mCronIntervalHours==1  ? 'selected' : ''; ?>>1 jam</option>
                        <option value="2"  <?php echo $_mCronIntervalHours==2  ? 'selected' : ''; ?>>2 jam</option>
                        <option value="4"  <?php echo $_mCronIntervalHours==4  ? 'selected' : ''; ?>>4 jam</option>
                        <option value="6"  <?php echo $_mCronIntervalHours==6  ? 'selected' : ''; ?>>6 jam</option>
                        <option value="12" <?php echo $_mCronIntervalHours==12 ? 'selected' : ''; ?>>12 jam</option>
                        <option value="24" <?php echo $_mCronIntervalHours==24 ? 'selected' : ''; ?>>24 jam</option>
                    </select>
                    <button class="btn btn-primary btn-sm" id="btnSaveMCronInterval">
                        <i class="fas fa-save me-1"></i>Simpan
                    </button>
                </div>
                <div class="small text-muted">
                    <i class="fas fa-history me-1"></i>Terakhir jalan: <strong><?php echo $_mCronLastRunFmt; ?></strong>
                    &nbsp;&nbsp;<i class="fas fa-forward me-1"></i>Berikutnya: <strong id="mCronNextRunDisplay"><?php echo $_mCronNextRunFmt; ?></strong>
                </div>
            </div>
            <!-- Log viewer -->
            <div id="mCronLogPanel" class="d-none mt-2">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="small text-muted">30 baris terakhir log cron</span>
                    <button class="btn btn-outline-secondary btn-sm" id="btnRefreshMLog">
                        <i class="fas fa-sync-alt me-1"></i>Refresh Log
                    </button>
                </div>
                <pre id="mCronLogContent" class="bg-dark text-light rounded p-3" style="font-size:.75rem;max-height:240px;overflow-y:auto;white-space:pre-wrap;word-break:break-all;">Memuat log...</pre>
            </div>
        </div>
    </div>

    <script>
    (function () {
        const toggle     = document.getElementById('toggleCronMaintenance');
        const badge      = document.getElementById('mCronStatusBadge');
        const label      = document.getElementById('mToggleCronLabel');
        const btnLog     = document.getElementById('btnShowMCronLog');
        const logPanel   = document.getElementById('mCronLogPanel');
        const logContent = document.getElementById('mCronLogContent');
        const btnRefresh = document.getElementById('btnRefreshMLog');
        const btnRunNow  = document.getElementById('btnRunMCronNow');

        function setVisual(enabled) {
            badge.textContent = enabled ? 'AKTIF' : 'NONAKTIF';
            badge.className   = 'badge ' + (enabled ? 'bg-success' : 'bg-secondary');
            label.textContent = enabled ? 'ON' : 'OFF';
        }

        toggle.addEventListener('change', function () {
            const enable = this.checked ? 1 : 0;
            toggle.disabled = true;
            const fd = new FormData();
            fd.append('action', 'toggle_cron_maintenance');
            fd.append('enable', enable);
            fetch(window.location.pathname, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        setVisual(data.enabled);
                        if (data.crontab_synced === false) {
                            alert((data.crontab_message || 'Sync crontab gagal.') + '\nToggle aplikasi tetap tersimpan.');
                        }
                    } else {
                        toggle.checked = !toggle.checked;
                        const msg    = data.message || 'Gagal menyimpan pengaturan cron.';
                        const detail = data.path
                            ? '\nPath: ' + data.path + '\nWritable dir: ' + data.writable_dir + '\nWritable file: ' + data.writable_file
                            : '';
                        alert(msg + detail);
                    }
                })
                .catch(() => {
                    toggle.checked = !toggle.checked;
                    alert('Terjadi kesalahan koneksi.');
                })
                .finally(() => { toggle.disabled = false; });
        });

        function loadLog() {
            logContent.textContent = 'Memuat log...';
            fetch(window.location.pathname + '?action=get_cron_maintenance_log')
                .then(r => r.json())
                .then(data => {
                    logContent.textContent = (data.lines && data.lines.length > 0)
                        ? data.lines.join('\n')
                        : '(log kosong -- cron belum pernah berjalan)';
                    logContent.scrollTop = logContent.scrollHeight;
                })
                .catch(() => { logContent.textContent = 'Gagal memuat log.'; });
        }

        btnLog.addEventListener('click', function () {
            const hidden = logPanel.classList.toggle('d-none');
            if (!hidden) loadLog();
        });
        btnRefresh.addEventListener('click', loadLog);

        btnRunNow.addEventListener('click', function () {
            btnRunNow.disabled = true;
            const oldHtml = btnRunNow.innerHTML;
            btnRunNow.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Menjalankan...';

            const fd = new FormData();
            fd.append('action', 'run_cron_maintenance_manual');

            fetch(window.location.pathname, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    const outputText = Array.isArray(data.output) && data.output.length > 0
                        ? data.output.join('\n')
                        : '(tanpa output)';
                    logPanel.classList.remove('d-none');
                    if (data.success) {
                        logContent.textContent = '[Manual Cron][OK] ' + (data.message || 'Cron maintenance manual berhasil dijalankan.') + '\n\nOutput:\n' + outputText;
                        loadLog();
                    } else {
                        const err = data.error ? ('\nError: ' + data.error) : '';
                        logContent.textContent = '[Manual Cron][ERROR] ' + (data.message || 'Gagal menjalankan cron maintenance manual.') + err + '\n\nOutput:\n' + outputText;
                    }
                })
                .catch(() => {
                    logPanel.classList.remove('d-none');
                    logContent.textContent = '[Manual Cron][ERROR] Terjadi kesalahan koneksi saat menjalankan cron manual.';
                })
                .finally(() => {
                    btnRunNow.disabled = false;
                    btnRunNow.innerHTML = oldHtml;
                });
        });

        const mIntervalSelect  = document.getElementById('mCronIntervalSelect');
        const btnSaveMInterval = document.getElementById('btnSaveMCronInterval');
        const mNextRunDisplay  = document.getElementById('mCronNextRunDisplay');

        btnSaveMInterval.addEventListener('click', function () {
            const hours = parseInt(mIntervalSelect.value, 10);
            btnSaveMInterval.disabled = true;
            const fd = new FormData();
            fd.append('action', 'set_cron_interval_maintenance');
            fd.append('hours', hours);
            fetch(window.location.pathname, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        mNextRunDisplay.textContent = '(akan diupdate saat cron berikutnya berjalan)';
                        alert('Interval berhasil disimpan: ' + data.hours + ' jam');
                    } else {
                        alert(data.message || 'Gagal simpan interval.');
                    }
                })
                .catch(() => alert('Terjadi kesalahan koneksi.'))
                .finally(() => { btnSaveMInterval.disabled = false; });
        });
    })();
    </script>
<?php endif; ?>

    <!-- ── Card Cron NMS Poll (grafik historis, uptime/SLA, alert device down) ── -->
    <div class="card shadow-sm mb-4" id="cardCronNms">
        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
                <i class="fas fa-network-wired"></i>
                <span class="fw-bold">Cron Monitoring NMS (Grafik Historis &amp; Alert Down)</span>
                <span id="nmsCronStatusBadge" class="badge <?php echo $_nmsCronEnabled ? 'bg-success' : 'bg-secondary'; ?>">
                    <?php echo $_nmsCronEnabled ? 'AKTIF' : 'NONAKTIF'; ?>
                </span>
            </div>
            <div class="d-flex align-items-center gap-3">
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch" id="toggleCronNms"
                        <?php echo $_nmsCronEnabled ? 'checked' : ''; ?> style="width:3em;height:1.5em;cursor:pointer;">
                    <label class="form-check-label text-white" for="toggleCronNms">
                        <span id="toggleCronNmsLabel"><?php echo $_nmsCronEnabled ? 'ON' : 'OFF'; ?></span>
                    </label>
                </div>
                <button class="btn btn-warning btn-sm" id="btnRunCronNmsNow">
                    <i class="fas fa-play me-1"></i>Jalankan Manual
                </button>
            </div>
        </div>
        <div class="card-body pb-2">
            <div class="row g-3 mb-2">
                <div class="col-md-4">
                    <div class="d-flex align-items-start gap-2">
                        <i class="fas fa-chart-line text-info mt-1"></i>
                        <div>
                            <div class="fw-semibold small">Grafik Historis</div>
                            <div class="text-muted" style="font-size:.82rem;">Catat status online/offline &amp; trafik tiap device secara berkala, jadi bahan grafik harian/mingguan/bulanan di menu NMS.</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="d-flex align-items-start gap-2">
                        <i class="fas fa-percentage text-success mt-1"></i>
                        <div>
                            <div class="fw-semibold small">Uptime / SLA</div>
                            <div class="text-muted" style="font-size:.82rem;">Persentase device online dalam periode tertentu, dihitung dari histori polling ini.</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="d-flex align-items-start gap-2">
                        <i class="fas fa-bell text-danger mt-1"></i>
                        <div>
                            <div class="fw-semibold small">Alert WA Device Down</div>
                            <div class="text-muted" style="font-size:.82rem;">Kirim WA otomatis sekali per episode down (bukan spam tiap polling) ke penerima notifikasi server.</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-3 pt-2 border-top mt-1 mb-2">
                <div class="d-flex align-items-center gap-2">
                    <i class="fas fa-clock text-muted small"></i>
                    <span class="small fw-semibold">Interval polling:</span>
                    <select id="nmsCronIntervalSelect" class="form-select form-select-sm" style="width:auto;">
                        <option value="1"  <?php echo $_nmsCronIntervalMinutes==1  ? 'selected' : ''; ?>>1 menit</option>
                        <option value="5"  <?php echo $_nmsCronIntervalMinutes==5  ? 'selected' : ''; ?>>5 menit</option>
                        <option value="10" <?php echo $_nmsCronIntervalMinutes==10 ? 'selected' : ''; ?>>10 menit</option>
                        <option value="15" <?php echo $_nmsCronIntervalMinutes==15 ? 'selected' : ''; ?>>15 menit</option>
                        <option value="30" <?php echo $_nmsCronIntervalMinutes==30 ? 'selected' : ''; ?>>30 menit</option>
                        <option value="60" <?php echo $_nmsCronIntervalMinutes==60 ? 'selected' : ''; ?>>60 menit</option>
                    </select>
                    <button class="btn btn-primary btn-sm" id="btnSaveNmsCronInterval">
                        <i class="fas fa-save me-1"></i>Simpan
                    </button>
                </div>
                <div class="small text-muted">Interval lebih pendek = grafik lebih detail, tapi lebih banyak beban ke router.</div>
            </div>
            <div id="nmsCronRunOutput" class="d-none mt-2">
                <pre id="nmsCronRunOutputContent" class="bg-dark text-light rounded p-3" style="font-size:.75rem;max-height:220px;overflow-y:auto;white-space:pre-wrap;word-break:break-all;"></pre>
            </div>
        </div>
    </div>

    <script>
    (function () {
        const toggle = document.getElementById('toggleCronNms');
        const badge = document.getElementById('nmsCronStatusBadge');
        const label = document.getElementById('toggleCronNmsLabel');
        const btnRunNow = document.getElementById('btnRunCronNmsNow');
        const outputWrap = document.getElementById('nmsCronRunOutput');
        const outputContent = document.getElementById('nmsCronRunOutputContent');
        const intervalSelect = document.getElementById('nmsCronIntervalSelect');
        const btnSaveInterval = document.getElementById('btnSaveNmsCronInterval');

        function setVisual(enabled) {
            badge.textContent = enabled ? 'AKTIF' : 'NONAKTIF';
            badge.className = 'badge ' + (enabled ? 'bg-success' : 'bg-secondary');
            label.textContent = enabled ? 'ON' : 'OFF';
        }

        toggle.addEventListener('change', function () {
            const enable = this.checked ? 1 : 0;
            toggle.disabled = true;
            const fd = new FormData();
            fd.append('action', 'toggle_cron_nms');
            fd.append('enable', enable);
            fetch(window.location.pathname, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        setVisual(data.enabled);
                        if (data.crontab_synced === false) {
                            alert((data.crontab_message || 'Sync crontab gagal.') + '\nToggle aplikasi tetap tersimpan.');
                        }
                    } else {
                        toggle.checked = !toggle.checked;
                        alert(data.message || 'Gagal menyimpan pengaturan cron.');
                    }
                })
                .catch(() => {
                    toggle.checked = !toggle.checked;
                    alert('Terjadi kesalahan koneksi.');
                })
                .finally(() => { toggle.disabled = false; });
        });

        btnRunNow.addEventListener('click', function () {
            btnRunNow.disabled = true;
            btnRunNow.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Menjalankan...';
            const fd = new FormData();
            fd.append('action', 'run_cron_nms_manual');
            fetch(window.location.pathname, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    outputWrap.classList.remove('d-none');
                    outputContent.textContent = (data.output || []).join('\n') || (data.message || 'Selesai.');
                })
                .catch(() => {
                    outputWrap.classList.remove('d-none');
                    outputContent.textContent = 'Terjadi kesalahan koneksi.';
                })
                .finally(() => {
                    btnRunNow.disabled = false;
                    btnRunNow.innerHTML = '<i class="fas fa-play me-1"></i>Jalankan Manual';
                });
        });

        btnSaveInterval.addEventListener('click', function () {
            btnSaveInterval.disabled = true;
            const fd = new FormData();
            fd.append('action', 'set_cron_interval_nms');
            fd.append('minutes', intervalSelect.value);
            fetch(window.location.pathname, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        alert('Interval polling berhasil disimpan: ' + data.minutes + ' menit');
                    } else {
                        alert(data.message || 'Gagal simpan interval.');
                    }
                })
                .catch(() => alert('Terjadi kesalahan koneksi.'))
                .finally(() => { btnSaveInterval.disabled = false; });
        });
    })();
    </script>

            <!-- Card Trun On: Manajemen Cron Job -->
            <div class="card shadow-lg mt-4">
                <div class="card-header bg-dark text-white">
                    <h5 class="mb-0">Trun on</h5>
                </div>
                <div class="card-body">
                    <?php
                    // Catatan: toggle on/off (mode=onoff) sekarang ditangani lebih awal
                    // di bagian atas file (sebelum header.php) supaya AJAX menerima
                    // respons JSON asli, bukan seluruh halaman HTML.

                    // Path ke file JSON
                    $jsonFile = "notifbot/data/reminder-$username.json";

                    // Cek apakah file ada
                    if (file_exists($jsonFile)) {
                        // Baca isi file JSON
                        $jsonData = file_get_contents($jsonFile);

                        // Decode JSON menjadi array asosiatif
                        $data = json_decode($jsonData, true);

                        // Periksa apakah decoding berhasil
                        if ($data !== null) {
                            foreach ($data as $item) {
                                $jatuh_tempo = $item['jatuh_tempo'];
                                $hari_sebelum = $item['hari_sebelum'];
                                $tanggal_reminder = $item['tanggal_reminder'];
                                // ensure we have the notification time available for cron commands
                                if (isset($item['jam_reminder']) && $item['jam_reminder'] !== '') {
                                    $jam_reminder = intval($item['jam_reminder']);
                                }
                                if (isset($item['menit_reminder']) && $item['menit_reminder'] !== '') {
                                    $menit_reminder = intval($item['menit_reminder']);
                                }
                            }
                            // fallback: if still not set, ensure a default time (7:00)
                            if (empty($jam_reminder)) { $jam_reminder = 7; }
                            if (empty($menit_reminder)) { $menit_reminder = 0; }
                        } else {
                            echo "Error: Gagal mendecode JSON.";
                        }
                    } else {
                        echo "Error: File JSON tidak ditemukan.";
                    }

                    // Simpan konfigurasi
                    $config_file = 'config.json';
                    $config = file_exists($config_file) ? json_decode(file_get_contents($config_file), true) : [];

                    $domain = $config['URL']; // Contoh output: example.com

                    // Membaca daftar crontab saat ini
                    $current_crontab = shell_exec("crontab -l 2>&1");
                    $printah1 = "$menit_reminder $jam_reminder * * * curl $domain/crm/billing/notifbot/notifphp/notif_remainder_pembayaran_$username.php > /dev/null 2>&1";
                    $printah2 = "0 23 * * * curl $domain/crm/billing/notifbot/notifphp/notif_cek_servernotif_$username.php > /dev/null 2>&1";
                    $printah3 = "$menit_reminder $jam_reminder * * * curl $domain/crm/billing/notifbot/notifphp/non_aktif_tempo_$username.php > /dev/null 2>&1";
                    $printah4 = "$menit_reminder $jam_reminder * * * curl $domain/crm/billing/notifbot/notifphp/matikan_client_baru_$username.php > /dev/null 2>&1";
                    $printah5 = "0 8 * * * curl $domain/crm/billing/notifbot/notifphp/hapus_kode_permintaan_bayar_$username.php > /dev/null 2>&1";
                    $printah6 = "* * * * * curl $domain/crm/billing/getdata/get_log_mikrotik.php > /dev/null 2>&1";
                    $printah7 = "* * * * * curl $domain/crm/billing/getdata/serverload.php > /dev/null 2>&1";
                    $printah8 = "* * * * * curl $domain/crm/billing/getdata/dataload.php > /dev/null 2>&1";
                    $printah9 = "0 7 * * * curl $domain/crm/billing/notifbot/notifphp/non_aktif_by_tanggal_$username.php > /dev/null 2>&1";
                    $printah10 = "* * * * * curl $domain/crm/billing/getdata/gettxrx.php > /dev/null 2>&1";
                    $printah14 = "*/30 * * * * curl $domain/crm/billing/getdata/server_sla_logger.php > /dev/null 2>&1";
                    $printah15 = "58 23 * * * curl $domain/crm/billing/getdata/server_sla_monthly_snapshot.php > /dev/null 2>&1";
                    $printah16 = "*/30 * * * * curl $domain/crm/billing/getdata/customer_sla_logger.php > /dev/null 2>&1";
                    $printah17 = "58 23 * * * curl $domain/crm/billing/getdata/customer_sla_monthly_snapshot.php > /dev/null 2>&1";
                    // Token rahasia untuk endpoint cache status PPPoE - rumusnya HARUS sama persis
                    // dengan yang dipakai di getdata/cron_scan_pppoe_status.php.
                    $pppoe_cron_secret = !empty($config['pppoe_cron_secret'])
                        ? (string) $config['pppoe_cron_secret']
                        : hash('sha256', (string) ($config['db_pass'] ?? '') . '|pppoe-cron-2026');
                    $printah18 = "* * * * * curl \"$domain/crm/billing/getdata/cron_scan_pppoe_status.php?secret=$pppoe_cron_secret\" > /dev/null 2>&1";
                    if ($invoice_generate_schedule === 'daily') {
                        $printah11 = "$invoice_generate_minute $invoice_generate_hour * * * curl $domain/crm/billing/notifbot/notifphp/invoice_generator_penagihan_$username.php > /dev/null 2>&1";
                        $invoiceGeneratorInfo = sprintf('%02d:%02d setiap hari', $invoice_generate_hour, $invoice_generate_minute);
                    } else {
                        $printah11 = "$invoice_generate_minute $invoice_generate_hour $invoice_generate_start_day-31 * * curl $domain/crm/billing/notifbot/notifphp/invoice_generator_penagihan_$username.php > /dev/null 2>&1";
                        $invoiceGeneratorInfo = sprintf('%02d:%02d (tgl %d-akhir bulan)', $invoice_generate_hour, $invoice_generate_minute, $invoice_generate_start_day);
                    }
                    $printah13 = "*/20 * * * * curl $domain/crm/billing/notifbot/notifphp/cek_tagihan_harian_$username.php > /dev/null 2>&1";
                    $odp_los_interval_unit = in_array($odp_los_interval_unit, ['menit', 'jam'], true) ? $odp_los_interval_unit : 'jam';
                    if ($odp_los_interval_unit === 'menit') {
                        $odp_los_interval_value = max(1, min(59, (int) $odp_los_interval_value));
                        $odp_los_cron_expr = "*/{$odp_los_interval_value} * * * *";
                        $odp_los_info = "Setiap {$odp_los_interval_value} menit";
                    } else {
                        $odp_los_interval_value = max(1, min(23, (int) $odp_los_interval_value));
                        $odp_los_cron_expr = "0 */{$odp_los_interval_value} * * *";
                        $odp_los_info = "Setiap {$odp_los_interval_value} jam";
                    }
                    $printah12 = "$odp_los_cron_expr curl $domain/crm/billing/notifbot/notifphp/notif_odp_semua_los_$username.php > /dev/null 2>&1";
                    // Fungsi untuk mengecek apakah cron job sudah ada
                    if (!function_exists('isCronActive')) {
                        function isCronActive($command, $current_crontab)
                        {
                            return strpos($current_crontab, $command) !== false ? "✅ On" : "⚪ Off";
                        }
                    }
                    ?>

                    <?php
                    // Build a unified list of cron entries (main + super-admin)
                    $promo_config_file = __DIR__ . '/notifbot/notifphp/promo_config.json';
                    $promo_config = ["promo_update_enabled" => true];
                    if (file_exists($promo_config_file)) {
                        $promo_config = json_decode(file_get_contents($promo_config_file), true);
                        if (!is_array($promo_config)) $promo_config = ["promo_update_enabled" => true];
                    }
                    $php_path = PHP_BINDIR . '/php';
                    $crontab_promo_path = realpath(__DIR__ . '/notifbot/notifphp/crontab_promo.php');
                    $promo_cron = "0 2 * * * $php_path $crontab_promo_path > /dev/null 2>&1";
                    $cron_entries = [
                        ['id'=>'cron_remainder_all','name'=>'Notifikasi BOT WHATSAPP jatuh tempo ke semua pelanggan','desc'=>'Khusus pelanggan mode tempo Fixed Due Date (mengikuti tanggal tempo)','info'=>sprintf('%02d:%02d', $jam_reminder, $menit_reminder),'cmd'=>$printah1,'admin'=>false],
                        ['id'=>'cron_remainder_mode','name'=>'Notifikasi BOT WHATSAPP pembayaran remainder dan isolir pelanggan','desc'=>'Khusus pelanggan mode tempo Rolling Due Date (mengikuti tanggal aktifasi)','info'=>'','cmd'=>$printah9,'admin'=>false],
                        ['id'=>'cron_server_status','name'=>'Notifikasi BOT WHATSAPP status server','desc'=>'Status semua server setiap jam 11 malam','info'=>'Jam 23:00','cmd'=>$printah2,'admin'=>false],
                        ['id'=>'cron_tagihan_baru','name'=>'Notifikasi BOT WHATSAPP tagihan pelanggan baru','desc'=>'Tagihan pelanggan baru setelah 2 hari pasang (prabayar)','info'=>sprintf('%02d:%02d', $jam_reminder, $menit_reminder),'cmd'=>$printah4,'admin'=>false],
                        ['id'=>'cron_auto_isolir','name'=>'AUTO Isolir pelanggan jatuh tempo','desc'=>'Isolir otomatis pelanggan yang melewati jatuh tempo','info'=>sprintf('%02d:%02d', $jam_reminder, $menit_reminder),'cmd'=>$printah3,'admin'=>false],
                        ['id'=>'cron_hapus_kode','name'=>'AUTO hapus kode pembayaran expired','desc'=>'Hapus kode pembayaran yang sudah expired setelah 2 hari','info'=>'Jam 08:00','cmd'=>$printah5,'admin'=>false],
                        ['id'=>'cron_server_konek','name'=>'Notifikasi Server Tidak Konek','desc'=>'Kirim notifikasi jika ada server RouterOS yang tidak dapat dihubungi','info'=>'Setiap 1 jam','cmd'=>"0 * * * * curl $domain/crm/billing/notifbot/notifphp/notif_server_tidak_konek_$username.php > /dev/null 2>&1",'admin'=>false],
                        ['id'=>'cron_odp_semua_los','name'=>'Notifikasi ODP Semua Pelanggan LOS','desc'=>'Kirim notifikasi ketika seluruh pelanggan di satu ODP sedang LOS (data serverload)','info'=>$odp_los_info,'cmd'=>$printah12,'admin'=>false],
                        ['id'=>'cron_update_grafik','name'=>'Update Grafik Trafik dan PPPoE/Hotspot','desc'=>'Update data grafik trafik dan jumlah aktif PPPoE/Hotspot untuk monitoring jaringan','info'=>'Setiap 1 menit','cmd'=>"* * * * * curl $domain/crm/billing/notifbot/notifphp/update_grafik_$username.php > /dev/null 2>&1",'admin'=>false],
                        ['id'=>'cron_cek_tagihan_harian','name'=>'Cek Tagihan Harian + Isolir Otomatis','desc'=>'Cek pelanggan belum bayar, update profile EXPIRED, putuskan active connection, dan simpan laporan harian','info'=>'Setiap 20 menit','cmd'=>$printah13,'admin'=>false],
                        ['id'=>'cron_invoice_penagihan','name'=>'Auto Generate Invoice PENAGIHAN','desc'=>'Buat invoice status PENAGIHAN otomatis sesuai jadwal yang dipilih','info'=>$invoiceGeneratorInfo,'cmd'=>$printah11,'admin'=>false],
                        ['id'=>'cron_olt_cache','name'=>'Auto Cache Data OLT (Multi Brand)','desc'=>'Muat semua data OLT di background dan cache untuk load instant tanpa menunggu','info'=>'Setiap 5 menit','cmd'=>"*/5 * * * * curl $domain/crm/billing/getdata/olt-cache.php > /dev/null 2>&1",'admin'=>false],
                        // SUPER ADMIN entries
                        ['id'=>'cron_log_pppoe','name'=>'Mebaca log PPPOE dan HOTSPOT mikrotik','desc'=>'Kumpulkan log PPPOE/HOTSPOT secara berkala','info'=>'','cmd'=>$printah6,'admin'=>true],
                        ['id'=>'cron_read_user','name'=>'Read jumlah user aktif','desc'=>'Baca jumlah user aktif pada sistem','info'=>'','cmd'=>$printah7,'admin'=>true],
                        ['id'=>'cron_read_odp','name'=>'Read data online per ODP','desc'=>'Ambil data online per ODP','info'=>'','cmd'=>$printah8,'admin'=>true],
                        ['id'=>'cron_read_olt','name'=>'Read data RX/TX OLT (Multi Brand)','desc'=>'Ambil data RX/TX OLT via Web (HIOSO) dan Telnet (ZTE/Huawei/VSOL/HSGQ/CDATA) dengan format onulist kompatibel tables pelanggan','info'=>'Setiap 1 menit','cmd'=>$printah10,'admin'=>true],
                        ['id'=>'cron_sla_server','name'=>'Log SLA koneksi server','desc'=>'Catat status koneksi server via telnet (port 23) tiap 30 menit untuk perhitungan SLA 30 hari berjalan','info'=>'Setiap 30 menit','cmd'=>$printah14,'admin'=>true],
                        ['id'=>'cron_sla_monthly_snapshot','name'=>'Snapshot SLA akhir bulan','desc'=>'Simpan nilai SLA terakhir pada akhir bulan ke database untuk statistik historis','info'=>'23:58 setiap hari (eksekusi hanya akhir bulan)','cmd'=>$printah15,'admin'=>true],
                        ['id'=>'cron_customer_sla','name'=>'Log SLA pelanggan per IDPEL','desc'=>'Catat status online pelanggan berdasarkan IDPEL tiap 30 menit untuk SLA pelanggan dan ODP','info'=>'Setiap 30 menit','cmd'=>$printah16,'admin'=>true],
                        ['id'=>'cron_customer_sla_monthly_snapshot','name'=>'Snapshot SLA pelanggan per bulan','desc'=>'Simpan rekap SLA pelanggan dan ODP ke database pada akhir bulan','info'=>'23:58 setiap hari (eksekusi hanya akhir bulan)','cmd'=>$printah17,'admin'=>true],
                        ['id'=>'cron_pppoe_status_cache','name'=>'Cache Status PPPoE (Online/Offline) Semua Pelanggan','desc'=>'Scan batch semua Mikrotik sekaligus (bukan satu-satu per pelanggan) lalu simpan ke cache, supaya status pelanggan di menu Customer PPPoE tetap tampil cepat walau datanya banyak','info'=>'Setiap 1 menit','cmd'=>$printah18,'admin'=>true],
                    ];
                    // Tambahkan entry toggle promo update di paling bawah (khusus superadmin)
                    if (($AKSES ?? '') === 'ADMIN') {
                        $promo_update_url = $domain . '/crm/billing/notifbot/notifphp/crontab_promo.php';
                        $promo_update_cron = "0 2 * * * curl $promo_update_url > /dev/null 2>&1";
                        $cron_entries[] = [
                            'id' => 'cron_promo_update',
                            'name' => 'Update Otomatis Paket Promo Berakhir',
                            'desc' => 'Aktifkan/Nonaktifkan update otomatis paket pelanggan saat promo berakhir',
                            'info' => 'Jam 02:00',
                            'cmd' => $promo_update_cron,
                            'admin' => true,
                            'promo_toggle' => false
                        ];
                    }
                    ?>

                    <div class="table-responsive stackable-on-mobile">
                        <table class="table table-sm align-middle stackable">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:40px">No</th>
                                    <th>Fungsi &amp; Penjelasan</th>
                                    <th style="width:110px">Time</th>
                                    <th style="width:90px">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php $i = 1; $separator_added = false; foreach ($cron_entries as $entry) {
                                // Hanya superadmin yang bisa melihat/akses baris promo update
                                if (!empty($entry['id']) && $entry['id'] === 'cron_promo_update' && ($AKSES ?? '') !== 'ADMIN') continue;
                                if (!empty($entry['admin']) && $entry['admin'] && ($AKSES ?? '') !== 'ADMIN') continue; // skip admin-only

                                // Tambahkan separator sebelum entry admin pertama
                                if (!empty($entry['admin']) && $entry['admin'] && !$separator_added) {
                                    echo '<tr><td colspan="4" style="border-top: 3px solid #dc3545; text-align: center; font-weight: bold; background-color: #f8f9fa;">🔒 Super Admin Only</td></tr>';
                                    $separator_added = true;
                                }

                                $isActive = (strpos($current_crontab, $entry['cmd']) !== false);
                                echo '<tr>';
                                echo '<td data-label="No">' . $i++ . '</td>';
                                echo '<td data-label="Fungsi & Penjelasan">';
                                echo '<div><strong>' . htmlspecialchars($entry['name']) . '</strong></div>';
                                if (!empty($entry['desc'])) {
                                    echo '<div class="text-muted small">' . htmlspecialchars($entry['desc']) . '</div>';
                                }
                                echo '</td>';
                                echo '<td data-label="Info">' . htmlspecialchars($entry['info']) . '</td>';
                                echo '<td data-label="Aksi">';
                                // Semua toggle cron, termasuk promo update, pakai pola yang sama
                                echo '<form method="post" class="cron-form d-inline" id="form-' . htmlspecialchars($entry['id']) . '">';
                                echo '<input type="hidden" name="command" value="' . htmlspecialchars($entry['cmd']) . '">';
                                echo '<input type="hidden" name="mode" value="onoff">';
                                echo '<input type="hidden" name="action" class="action-field" value="' . ($isActive ? 'add' : 'delete') . '">';
                                echo '<div class="form-check form-switch">';
                                echo '<input class="form-check-input cron-toggle" type="checkbox" id="' . htmlspecialchars($entry['id']) . '" ' . ($isActive ? 'checked' : '') . ' aria-label="Toggle ' . htmlspecialchars($entry['id']) . '">';
                                echo '</div>';
                                echo '</form>';
                                echo '</td>';
                                echo '</tr>';
                            } ?>
                            </tbody>
                        </table>
                        <?php if ($AKSES == 'ADMIN') { ?>
                        <pre style="font-size:8px"><?php echo htmlspecialchars($current_crontab); ?></pre>
                        <?php } ?>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<style>
    .log-container {
        max-height: 300px;
        overflow-y: auto;
    }
</style>
<style>
    /* Mobile-friendly stacked table: convert rows into card-like blocks on small screens */
    @media (max-width: 768px) {
        .stackable-on-mobile .stackable thead { display: none; }
        .stackable-on-mobile .stackable, .stackable-on-mobile .stackable tbody, .stackable-on-mobile .stackable tr, .stackable-on-mobile .stackable td {
            display: block; width: 100%;
        }
        .stackable-on-mobile .stackable tr { margin-bottom: .75rem; border: 1px solid #e9ecef; border-radius: .375rem; padding: .5rem; background: #fff; }
        .stackable-on-mobile .stackable td { padding: .375rem .5rem; border: none; }
        /* show the label on its own line and stack content vertically */
        .stackable-on-mobile .stackable td[data-label]:before {
            content: attr(data-label) ":";
            font-weight: 700;
            display: block;
            margin-bottom: .25rem;
            color: #343a40;
        }
        /* ensure the description (penjelasan) is readable on mobile */
        .stackable-on-mobile .stackable td .text-muted {
            color: #495057 !important;
            opacity: 1 !important;
            font-size: .95rem;
        }
    }
</style>
<style>
    /* Cron toggle colors: secondary (off) and orange (on)
       Use higher specificity and !important to override Bootstrap defaults */
    input.form-check-input.cron-toggle,
    .form-check-input.cron-toggle {
        background-color: #6c757d !important; /* bootstrap secondary */
        border-color: #6c757d !important;
        background-image: none !important;
        transition: background-color .15s ease, border-color .15s ease;
    }

    input.form-check-input.cron-toggle:checked,
    .form-check-input.cron-toggle:checked {
        background-color: #fd7e14 !important; /* bootstrap warning/orange */
        border-color: #fd7e14 !important;
        background-image: none !important;
    }

    input.form-check-input.cron-toggle:focus,
    .form-check-input.cron-toggle:focus {
        box-shadow: 0 0 0 .25rem rgba(253,126,20,0.18) !important;
    }

    /* Ensure the switch knob contrasts nicely */
    input.form-check-input.cron-toggle::after,
    .form-check-input.cron-toggle::after {
        background-color: #fff !important;
    }

    /* Responsive spacing for the cron controls */
    .cron-controls .cron-form { padding: .5rem 0; }
</style>

<!-- Toast container untuk feedback AJAX toggle -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1080;">
    <div id="ajaxToast" class="toast align-items-center text-bg-dark border-0" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body" id="ajaxToastBody">Pesan</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    </div>
</div>

<script>
function showToast(title, message) {
    var toastEl = document.getElementById('ajaxToast');
    document.getElementById('ajaxToastBody').textContent = message;
    var toast = bootstrap.Toast.getOrCreateInstance(toastEl);
    toast.show();
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.cron-toggle').forEach(function(checkbox) {
        checkbox.addEventListener('change', function() {
            var form = checkbox.closest('form');
            var isChecked = checkbox.checked;
            var action = isChecked ? 'add' : 'delete';
            form.querySelector('.action-field').value = action;

            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: new URLSearchParams(new FormData(form))
            })
                .then(function(res) {
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    return res.json();
                })
                .then(function(data) {
                    if (data && data.success) {
                        showToast('Sukses', data.message || 'Perubahan disimpan.');
                    } else {
                        showToast('Gagal', (data && data.message) || 'Tidak dapat menyimpan perubahan.');
                        checkbox.checked = !isChecked;
                    }
                })
                .catch(function() {
                    showToast('Gagal', 'Tidak dapat menyimpan perubahan.');
                    checkbox.checked = !isChecked;
                });
        });
    });
});
</script>

<?php require 'footer.php'; ?>
