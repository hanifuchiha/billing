<?php
/**
 * Notification API - AJAX Handler untuk semua proses simpan
 * File ini digunakan untuk handle POST requests dari AJAX di background
 */

// Clear any existing output buffers
while (ob_get_level()) {
    ob_end_clean();
}

// Start fresh output buffer untuk JSON
ob_start();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Try to suppress any output from includes
define('AJAX_REQUEST', true);

// Suppress notices/warnings BEFORE require
$old_error_reporting = error_reporting(E_ERROR | E_PARSE);

// Error handler - convert PHP errors/warnings ke JSON
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    ob_end_clean();
    $response = [
        'success' => false,
        'message' => 'PHP Error: ' . $errstr . ' (Line ' . $errline . ')'
    ];
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response);
    exit;
});

// Require dependencies - suppress output
try {
    // Define common variables that header.php might need
    $today = date('Y-m-d');
    $now = date('Y-m-d H:i:s');
    $asistant_name = isset($asistant_name) ? $asistant_name : '';
    if (!isset($akses_menu) || !is_array($akses_menu)) {
        $akses_menu = [];
    }

    // API tidak butuh render sidebar/layout dari header.php
    $_GET['embed'] = '1';
    
    ob_start(); // Start new buffer for includes
    require 'header.php';
    require_once __DIR__ . '/notifbot/bot_selector_helper.php';
    require_once __DIR__ . '/notifbot/notif_template_helper.php';
    require_once __DIR__ . '/notifbot/reminder_settings_helper.php';
    ob_end_clean(); // Clear include output
    
    // Initialize variables after header.php include
    $username = isset($username) ? $username : (isset($ceknama) ? $ceknama : '');
    
    // Verify critical variables exist
    if (empty($ceknama)) {
        ob_end_clean();
        $response = [
            'success' => false,
            'message' => 'User not authenticated (ceknama empty)'
        ];
        echo json_encode($response);
        exit;
    }
    
    if (empty($conn) || !$conn) {
        ob_end_clean();
        $response = [
            'success' => false,
            'message' => 'Database connection failed'
        ];
        echo json_encode($response);
        exit;
    }
} catch (Exception $e) {
    ob_end_clean();
    $errorResponse = [
        'success' => false,
        'message' => 'Error loading dependencies: ' . $e->getMessage()
    ];
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($errorResponse);
    exit;
}

// Restore error reporting AFTER require
error_reporting($old_error_reporting);

// --- Bot receiver config (checkbox pilihan bot per kategori notifikasi) ---
// Duplikasi minimal dari notification.php agar action di sini juga menyimpan
// pilihan bot (mis. "Semua Bot"/RANDOM), bukan cuma nomor penerima ke tabel botwa.
if (!function_exists('encodeBotSelection')) {
    function encodeBotSelection(array $selected, array $allBots)
    {
        $selected = array_values(array_unique(array_filter(array_map('trim', $selected), function ($v) {
            return $v !== '';
        })));

        if (empty($selected) || in_array('RANDOM', $selected, true)) {
            return 'RANDOM';
        }

        if (count($selected) === 1) {
            return $selected[0];
        }

        return implode(',', $selected);
    }
}

if (!function_exists('saveBotReceiverConfig')) {
    function saveBotReceiverConfig($filePath, $currentConfig, $key, $value)
    {
        $allowed = ['pendaftaran', 'server', 'livechat', 'system', 'odp_los', 'manual_active', 'provisioning'];
        if (!in_array($key, $allowed, true)) {
            return $currentConfig;
        }
        $currentConfig[$key] = trim((string)$value);
        @file_put_contents($filePath, json_encode($currentConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $currentConfig;
    }
}

$owner_key_for_bot_cfg = isset($ceknama) && $ceknama !== '' ? $ceknama : (isset($username) ? $username : 'default');
$bot_receiver_config_path = __DIR__ . "/notifbot/data/bot_receiver_config-$owner_key_for_bot_cfg.json";
$bot_receiver_config = [
    'pendaftaran' => '',
    'server' => '',
    'livechat' => '',
    'system' => '',
    'odp_los' => '',
    'manual_active' => '',
    'provisioning' => ''
];
if (file_exists($bot_receiver_config_path)) {
    $tmp_bot_receiver_config = json_decode(file_get_contents($bot_receiver_config_path), true);
    if (is_array($tmp_bot_receiver_config)) {
        $bot_receiver_config = array_merge($bot_receiver_config, $tmp_bot_receiver_config);
    }
}

// Helper untuk dipakai di tiap handler save_penerima_*: baca checkbox bot dari
// POST, encode, dan simpan ke file config di atas.
function saveBotSelectionFromPost($postField, $configKey) {
    global $bot_receiver_config_path, $bot_receiver_config;
    $arr = isset($_POST[$postField]) && is_array($_POST[$postField]) ? $_POST[$postField] : [];
    $selected = encodeBotSelection($arr, []);
    $bot_receiver_config = saveBotReceiverConfig($bot_receiver_config_path, $bot_receiver_config, $configKey, $selected);
}

// Response template
$response = [
    'success' => false,
    'message' => 'Unknown action',
    'data' => []
];

// Cek action parameter
if (!isset($_POST['action'])) {
    $response['message'] = 'Action parameter missing';
    ob_end_clean();
    echo json_encode($response);
    exit;
}

$action = $_POST['action'];

// --- HELPER FUNCTIONS ---

// Function untuk output JSON dengan proper cleanup dan logging
function outputJSON($response) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response);
    exit;
}

// Function untuk logging debug
function debugLog($action, $data) {
    $logFile = __DIR__ . '/notifbot/data/debug.log';
    $logEntry = "[" . date('Y-m-d H:i:s') . "] ACTION: $action | " . json_encode($data) . "\n";
    @file_put_contents($logFile, $logEntry, FILE_APPEND);
}

// Kolom pesan_* yang dikenal di tabel notif_khusus (lihat blok ensure-schema
// di notification.php / api/notification_settings_mobile.php). Dipakai supaya
// INSERT baris baru selalu mengisi SEMUA kolom ini sekaligus -- beberapa kolom
// (mis. pesan_ketentuan) ada di server sbg NOT NULL tanpa DEFAULT, jadi INSERT
// yang cuma mengisi 1 kolom + pemilik akan gagal ("Field 'x' doesn't have a
// default value") setiap kali baris pemilik itu belum pernah ada sama sekali.
const NOTIF_KHUSUS_KNOWN_COLUMNS = [
    'pesan_registrasi', 'pesan_expired', 'pesan_reminder', 'pesan_ketentuan',
    'pesan_disable', 'pesan_aktif_manual', 'pesan_remainder_manual', 'pesan_dismantle_manual',
    'pesan_gangguan', 'pesan_gangguan_selesai',
];

// Function untuk save/update ke database dengan error handling
function saveToDatabase($conn, $table, $column, $value, $pemilik, $ceknama, $asistant_name) {
    if (!$conn) {
        return ['success' => false, 'message' => 'Database connection tidak valid'];
    }

    // Cek apakah record sudah ada
    $stmt = $conn->prepare("SELECT id FROM `$table` WHERE pemilik = ?");
    if (!$stmt) {
        return ['success' => false, 'message' => 'DB Prepare Error: ' . $conn->error];
    }

    $stmt->bind_param('s', $pemilik);
    if (!$stmt->execute()) {
        return ['success' => false, 'message' => 'DB Execute Error: ' . $stmt->error];
    }

    $result = $stmt->get_result();
    $numRows = $result->num_rows;
    $stmt->close();

    debugLog('saveToDatabase', ['table' => $table, 'column' => $column, 'pemilik' => $pemilik, 'numRows' => $numRows]);

    // Jika ada, update. Jika tidak, insert
    if ($numRows > 0) {
        // UPDATE
        $updateSql = "UPDATE `$table` SET `$column` = ? WHERE pemilik = ?";
        $stmt = $conn->prepare($updateSql);
        if (!$stmt) {
            return ['success' => false, 'message' => 'DB Prepare Error (UPDATE): ' . $conn->error];
        }
        $stmt->bind_param('ss', $value, $pemilik);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            debugLog('saveToDatabase', ['error' => 'UPDATE failed: ' . $error]);
            return ['success' => false, 'message' => 'DB Execute Error (UPDATE): ' . $error];
        }
        $stmt->close();
    } elseif ($table === 'notif_khusus') {
        // INSERT baris baru: isi SEMUA kolom pesan_* dikenal sekaligus (bukan
        // cuma $column) supaya tidak gagal gara-gara kolom lain NOT NULL tanpa
        // default -- lihat catatan di NOTIF_KHUSUS_KNOWN_COLUMNS di atas.
        $insertCols = NOTIF_KHUSUS_KNOWN_COLUMNS;
        if (!in_array($column, $insertCols, true)) {
            $insertCols[] = $column;
        }
        $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
        $colsSql = implode(', ', array_map(function ($c) { return "`$c`"; }, $insertCols));
        $insertSql = "INSERT INTO `$table` (pemilik, $colsSql) VALUES (?, $placeholders)";
        $stmt = $conn->prepare($insertSql);
        if (!$stmt) {
            return ['success' => false, 'message' => 'DB Prepare Error (INSERT): ' . $conn->error];
        }
        $values = [];
        foreach ($insertCols as $c) {
            $values[] = ($c === $column) ? $value : '';
        }
        $types = 's' . str_repeat('s', count($insertCols));
        $stmt->bind_param($types, $pemilik, ...$values);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            debugLog('saveToDatabase', ['error' => 'INSERT failed: ' . $error]);
            return ['success' => false, 'message' => 'DB Execute Error (INSERT): ' . $error];
        }
        $stmt->close();
    } else {
        // INSERT
        $insertSql = "INSERT INTO `$table` (pemilik, `$column`) VALUES (?, ?)";
        $stmt = $conn->prepare($insertSql);
        if (!$stmt) {
            return ['success' => false, 'message' => 'DB Prepare Error (INSERT): ' . $conn->error];
        }
        $stmt->bind_param('ss', $pemilik, $value);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            debugLog('saveToDatabase', ['error' => 'INSERT failed: ' . $error]);
            return ['success' => false, 'message' => 'DB Execute Error (INSERT): ' . $error];
        }
        $stmt->close();
    }

    saveHistory($ceknama, $asistant_name, "Menyimpan data ke $table.$column");
    return ['success' => true, 'message' => 'Data berhasil disimpan'];
}

function saveHistory($ceknama, $asistant_name, $message) {
    $history_file = __DIR__ . "/notifbot/data/history-$ceknama.json";
    $history = [];
    if (file_exists($history_file)) {
        $history = json_decode(file_get_contents($history_file), true);
    }
    if (!is_array($history)) $history = [];
    $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] " . $message;
    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
}

// --- ACTION HANDLERS ---

// 1. Simpan Pesan Registrasi
// CATATAN: REGISTRASI/EXPIRED/REMAINDER disimpan ke tabel notif_khusus lewat
// notifTemplateSaveSections() (notif_template_helper.php) -- fungsi yg SAMA
// dipakai notification.php (form & modal) dan dibaca semua cron pengirim WA
// (notif_remainder_pembayaran*.php dkk, via notifTemplateGetContent()), jadi
// apa yg disimpan di sini otomatis jadi pesan yg benar2 terkirim.
if ($action === 'save_registrasi') {
    $pesan = trim($_POST['pesan_registrasi'] ?? '');
    $pemilik = $ceknama ?? '';

    debugLog('save_registrasi', ['pemilik' => $pemilik, 'pesan_length' => strlen($pesan)]);

    if (empty($pemilik)) {
        $response['message'] = 'Pemilik tidak diketahui';
        outputJSON($response);
    }

    if (notifTemplateSaveSections($pemilik, $pesan, null, null)) {
        $response['success'] = true;
        $response['message'] = 'Pesan registrasi berhasil disimpan';
        saveHistory($ceknama, $asistant_name, 'Menyimpan pesan registrasi');
    } else {
        $response['message'] = 'Gagal menyimpan pesan registrasi ke database';
    }
    outputJSON($response);
}

// 2. Simpan Pesan Expired
elseif ($action === 'save_expired') {
    $pesan = trim($_POST['pesan_expired'] ?? '');
    $pemilik = $ceknama ?? '';

    if (empty($pemilik)) {
        $response['message'] = 'Pemilik tidak diketahui';
        outputJSON($response);
    }

    if (notifTemplateSaveSections($pemilik, null, $pesan, null)) {
        $response['success'] = true;
        $response['message'] = 'Pesan expired berhasil disimpan';
        saveHistory($ceknama, $asistant_name, 'Menyimpan pesan expired');
    } else {
        $response['message'] = 'Gagal menyimpan pesan expired ke database';
    }
    outputJSON($response);
}

// 3. Simpan Pesan Reminder
elseif ($action === 'save_reminder') {
    $pesan = trim($_POST['pesan_reminder'] ?? '');
    $pemilik = $ceknama ?? '';

    if (empty($pemilik)) {
        $response['message'] = 'Pemilik tidak diketahui';
        outputJSON($response);
    }

    if (notifTemplateSaveSections($pemilik, null, null, $pesan)) {
        $response['success'] = true;
        $response['message'] = 'Pesan reminder berhasil disimpan';
        saveHistory($ceknama, $asistant_name, 'Menyimpan pesan reminder');
    } else {
        $response['message'] = 'Gagal menyimpan pesan reminder ke database';
    }
    outputJSON($response);
}

// 3b. Simpan Filter Reminder (skip pelanggan sudah bayar / skip sudah dinotif
// hari ini) -- disimpan ke tabel `reminder_settings` (kolom yang sama dgn
// Payment Setting -> Konfigurasi Fixed Due Date), via reminderSettingsSave()
// yang PARTIAL update (cuma 2 kolom filter ini) supaya key lain (jatuh_tempo,
// hari_sebelum, dst -- dikelola paymentset.php) TIDAK ikut hilang. Helper ini
// juga otomatis regenerate mirror notifbot/data/reminder-{username}.json yang
// masih dibaca notif_remainder_pembayaran*.php.
elseif ($action === 'save_filter_reminder') {
    $pemilikFilter = $ceknama ?? '';

    if (empty($pemilikFilter)) {
        $response['message'] = 'Pemilik tidak diketahui';
        outputJSON($response);
    }

    $filterSaveOk = reminderSettingsSave($conn, $pemilikFilter, [
        'filter_sudah_bayar_reminder' => isset($_POST['filter_sudah_bayar_reminder']),
        'filter_skip_notif_hari_ini' => isset($_POST['filter_skip_notif_hari_ini']),
    ]);

    if ($filterSaveOk) {
        $response['success'] = true;
        $response['message'] = 'Filter reminder berhasil disimpan';
        saveHistory($ceknama, $asistant_name, 'Menyimpan filter reminder (skip sudah bayar / skip sudah dinotif hari ini)');
    } else {
        $response['message'] = 'Gagal menyimpan filter reminder';
    }
    outputJSON($response);
}

// 4. Simpan Pesan Ketentuan
elseif ($action === 'save_ketentuan') {
    $pesan = $_POST['pesan_ketentuan'] ?? '';
    $pemilik = $ceknama ?? '';
    
    if (empty($pemilik)) {
        $response['message'] = 'Pemilik tidak diketahui';
        outputJSON($response);
    }
    
    $result = saveToDatabase($conn, 'notif_khusus', 'pesan_ketentuan', $pesan, $pemilik, $ceknama, $asistant_name);
    if ($result['success']) {
        $response['success'] = true;
        $response['message'] = 'Pesan ketentuan berhasil disimpan';
    } else {
        $response['message'] = $result['message'];
    }
    outputJSON($response);
}

// 5. Simpan Pesan Disable
elseif ($action === 'save_disable') {
    $pesan = $_POST['pesan_disable'] ?? '';
    $pemilik = $ceknama ?? '';
    
    if (empty($pemilik)) {
        $response['message'] = 'Pemilik tidak diketahui';
        outputJSON($response);
    }
    
    $result = saveToDatabase($conn, 'notif_khusus', 'pesan_disable', $pesan, $pemilik, $ceknama, $asistant_name);
    if ($result['success']) {
        $response['success'] = true;
        $response['message'] = 'Pesan disable berhasil disimpan';
    } else {
        $response['message'] = $result['message'];
    }
    outputJSON($response);
}

// 6. Simpan Pesan Aktif Manual
elseif ($action === 'save_aktif_manual') {
    $pesan = $_POST['pesan_aktif_manual'] ?? '';
    $pemilik = $ceknama ?? '';
    
    if (empty($pemilik)) {
        $response['message'] = 'Pemilik tidak diketahui';
        outputJSON($response);
    }
    
    $result = saveToDatabase($conn, 'notif_khusus', 'pesan_aktif_manual', $pesan, $pemilik, $ceknama, $asistant_name);
    if ($result['success']) {
        $response['success'] = true;
        $response['message'] = 'Pesan aktif manual berhasil disimpan';
    } else {
        $response['message'] = $result['message'];
    }
    outputJSON($response);
}

// 7. Simpan Pesan Remainder Manual
elseif ($action === 'save_remainder_manual') {
    $pesan = $_POST['pesan_remainder_manual'] ?? '';
    $pemilik = $ceknama ?? '';
    
    if (empty($pemilik)) {
        $response['message'] = 'Pemilik tidak diketahui';
        outputJSON($response);
    }
    
    $result = saveToDatabase($conn, 'notif_khusus', 'pesan_remainder_manual', $pesan, $pemilik, $ceknama, $asistant_name);
    if ($result['success']) {
        $response['success'] = true;
        $response['message'] = 'Pesan remainder manual berhasil disimpan';
    } else {
        $response['message'] = $result['message'];
    }
    outputJSON($response);
}

// 8. Simpan Pesan Dismantle Manual
elseif ($action === 'save_dismantle_manual') {
    $pesan = $_POST['pesan_dismantle_manual'] ?? '';
    $pemilik = $ceknama ?? '';
    
    if (empty($pemilik)) {
        $response['message'] = 'Pemilik tidak diketahui';
        outputJSON($response);
    }
    
    $result = saveToDatabase($conn, 'notif_khusus', 'pesan_dismantle_manual', $pesan, $pemilik, $ceknama, $asistant_name);
    if ($result['success']) {
        $response['success'] = true;
        $response['message'] = 'Pesan dismantle manual berhasil disimpan';
    } else {
        $response['message'] = $result['message'];
    }
    outputJSON($response);
}

// 8b. Simpan Pesan Gangguan (broadcast.php mode "info")
elseif ($action === 'save_gangguan') {
    $pesan = $_POST['pesan_gangguan'] ?? '';
    $pemilik = $ceknama ?? '';

    if (empty($pemilik)) {
        $response['message'] = 'Pemilik tidak diketahui';
        outputJSON($response);
    }

    $result = saveToDatabase($conn, 'notif_khusus', 'pesan_gangguan', $pesan, $pemilik, $ceknama, $asistant_name);
    if ($result['success']) {
        $response['success'] = true;
        $response['message'] = 'Pesan gangguan berhasil disimpan';
    } else {
        $response['message'] = $result['message'];
    }
    outputJSON($response);
}

// 8c. Simpan Pesan Selesai Gangguan (broadcast.php mode "selesai")
elseif ($action === 'save_gangguan_selesai') {
    $pesan = $_POST['pesan_gangguan_selesai'] ?? '';
    $pemilik = $ceknama ?? '';

    if (empty($pemilik)) {
        $response['message'] = 'Pemilik tidak diketahui';
        outputJSON($response);
    }

    $result = saveToDatabase($conn, 'notif_khusus', 'pesan_gangguan_selesai', $pesan, $pemilik, $ceknama, $asistant_name);
    if ($result['success']) {
        $response['success'] = true;
        $response['message'] = 'Pesan selesai gangguan berhasil disimpan';
    } else {
        $response['message'] = $result['message'];
    }
    outputJSON($response);
}

// 9. Simpan Nomor Penerima Pendaftaran
elseif ($action === 'save_nomor_penerima') {
    $nomor_penerima = trim($_POST['nomor_penerima'] ?? '');
    $tipe_penerima = $_POST['tipe_penerima'] ?? 'pribadi';
    $nomor_valid = true;
    $nomor_final = '';
    
    if ($tipe_penerima === 'pribadi') {
        if (preg_match('/^62[0-9]{7,15}$/', $nomor_penerima)) {
            $nomor_final = $nomor_penerima . '@s.whatsapp.net';
        } else {
            $nomor_valid = false;
            $response['message'] = 'Nomor pribadi harus diawali 62 dan hanya angka!';
        }
    } elseif ($tipe_penerima === 'grup') {
        $nomor_final = $nomor_penerima . '@g.us';
    }
    
    if ($nomor_valid) {
        $pemilik = $ceknama ?? '';
        if ($pemilik !== '') {
            saveBotSelectionFromPost('bot_penerima', 'pendaftaran');
            $stmt = $conn->prepare("UPDATE botwa SET penerima = ? WHERE pemilik = ?");
            $stmt->bind_param('ss', $nomor_final, $pemilik);
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = 'Nomor penerima berhasil disimpan: ' . htmlspecialchars($nomor_final);
                saveHistory($ceknama, $asistant_name, 'Menyimpan nomor penerima pesan');
            } else {
                $response['message'] = 'Gagal menyimpan ke database';
            }
            $stmt->close();
        } else {
            $response['message'] = 'Pemilik tidak diketahui';
        }
    }
    outputJSON($response);
    outputJSON($response);
}
elseif ($action === 'save_penerima_server') {
    $nomor_penerima = trim($_POST['nomor_penerima_server'] ?? '');
    $tipe_penerima = $_POST['tipe_penerima_server'] ?? 'pribadi';
    $nomor_valid = true;
    $nomor_final = '';
    
    if ($tipe_penerima === 'pribadi') {
        if (preg_match('/^62[0-9]{7,15}$/', $nomor_penerima)) {
            $nomor_final = $nomor_penerima . '@s.whatsapp.net';
        } else {
            $nomor_valid = false;
            $response['message'] = 'Nomor pribadi harus diawali 62 dan hanya angka!';
        }
    } elseif ($tipe_penerima === 'grup') {
        $nomor_final = $nomor_penerima . '@g.us';
    }
    
    if ($nomor_valid) {
        $pemilik = $ceknama ?? '';
        if ($pemilik !== '') {
            saveBotSelectionFromPost('bot_penerima_server', 'server');
            $stmt = $conn->prepare("UPDATE botwa SET penerima_server = ? WHERE pemilik = ?");
            $stmt->bind_param('ss', $nomor_final, $pemilik);
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = 'Nomor penerima server berhasil disimpan: ' . htmlspecialchars($nomor_final);
                saveHistory($ceknama, $asistant_name, 'Menyimpan nomor penerima server');
            } else {
                $response['message'] = 'Gagal menyimpan ke database';
            }
            $stmt->close();
        } else {
            $response['message'] = 'Pemilik tidak diketahui';
        }
    }
    outputJSON($response);
}

// 11. Simpan Nomor Penerima Livechat
elseif ($action === 'save_penerima_livechat') {
    $nomor_penerima = trim($_POST['nomor_penerima_livechat'] ?? '');
    $tipe_penerima = $_POST['tipe_penerima_livechat'] ?? 'pribadi';
    $nomor_valid = true;
    $nomor_final = '';
    
    if ($tipe_penerima === 'pribadi') {
        if (preg_match('/^62[0-9]{7,15}$/', $nomor_penerima)) {
            $nomor_final = $nomor_penerima . '@s.whatsapp.net';
        } else {
            $nomor_valid = false;
            $response['message'] = 'Nomor pribadi harus diawali 62 dan hanya angka!';
        }
    } elseif ($tipe_penerima === 'grup') {
        $nomor_final = $nomor_penerima . '@g.us';
    }
    
    if ($nomor_valid) {
        $pemilik = $ceknama ?? '';
        if ($pemilik !== '') {
            saveBotSelectionFromPost('bot_penerima_livechat', 'livechat');
            $stmt = $conn->prepare("UPDATE botwa SET penerima_livechat = ? WHERE pemilik = ?");
            $stmt->bind_param('ss', $nomor_final, $pemilik);
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = 'Nomor penerima livechat berhasil disimpan: ' . htmlspecialchars($nomor_final);
                saveHistory($ceknama, $asistant_name, 'Menyimpan nomor penerima livechat');
            } else {
                $response['message'] = 'Gagal menyimpan ke database';
            }
            $stmt->close();
        } else {
            $response['message'] = 'Pemilik tidak diketahui';
        }
    }
    outputJSON($response);
}

// 12. Simpan Nomor Penerima System Notif
elseif ($action === 'save_penerima_system_notif') {
    $nomor_penerima = trim($_POST['nomor_penerima_system_notif'] ?? '');
    $tipe_penerima = $_POST['tipe_penerima_system_notif'] ?? 'pribadi';
    $nomor_valid = true;
    $nomor_final = '';
    
    if ($tipe_penerima === 'pribadi') {
        if (preg_match('/^62[0-9]{7,15}$/', $nomor_penerima)) {
            $nomor_final = $nomor_penerima . '@s.whatsapp.net';
        } else {
            $nomor_valid = false;
            $response['message'] = 'Nomor pribadi harus diawali 62 dan hanya angka!';
        }
    } elseif ($tipe_penerima === 'grup') {
        $nomor_final = $nomor_penerima . '@g.us';
    }
    
    if ($nomor_valid) {
        $pemilik = $ceknama ?? '';
        if ($pemilik !== '') {
            saveBotSelectionFromPost('bot_penerima_system_notif', 'system');
            $stmt = $conn->prepare("UPDATE botwa SET penerima_system_notif = ? WHERE pemilik = ?");
            $stmt->bind_param('ss', $nomor_final, $pemilik);
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = 'Nomor penerima system notif berhasil disimpan: ' . htmlspecialchars($nomor_final);
                saveHistory($ceknama, $asistant_name, 'Menyimpan nomor penerima system notif');
            } else {
                $response['message'] = 'Gagal menyimpan ke database';
            }
            $stmt->close();
        } else {
            $response['message'] = 'Pemilik tidak diketahui';
        }
    }
    outputJSON($response);
}

// 13. Simpan Nomor Penerima ODP LOS
elseif ($action === 'save_penerima_odp_los') {
    $nomor_penerima = trim($_POST['nomor_penerima_odp_los'] ?? '');
    $tipe_penerima = $_POST['tipe_penerima_odp_los'] ?? 'pribadi';
    $nomor_valid = true;
    $nomor_final = '';
    
    if ($tipe_penerima === 'pribadi') {
        if (preg_match('/^62[0-9]{7,15}$/', $nomor_penerima)) {
            $nomor_final = $nomor_penerima . '@s.whatsapp.net';
        } else {
            $nomor_valid = false;
            $response['message'] = 'Nomor pribadi harus diawali 62 dan hanya angka!';
        }
    } elseif ($tipe_penerima === 'grup') {
        $nomor_final = $nomor_penerima . '@g.us';
    }
    
    if ($nomor_valid) {
        $pemilik = $ceknama ?? '';
        if ($pemilik !== '') {
            saveBotSelectionFromPost('bot_penerima_odp_los', 'odp_los');
            $stmt = $conn->prepare("UPDATE botwa SET penerima_odp_los = ? WHERE pemilik = ?");
            $stmt->bind_param('ss', $nomor_final, $pemilik);
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = 'Nomor penerima ODP LOS berhasil disimpan: ' . htmlspecialchars($nomor_final);
                saveHistory($ceknama, $asistant_name, 'Menyimpan nomor penerima ODP LOS');
            } else {
                $response['message'] = 'Gagal menyimpan ke database';
            }
            $stmt->close();
        } else {
            $response['message'] = 'Pemilik tidak diketahui';
        }
    }
    outputJSON($response);
}

// 14. Simpan Nomor Penerima Manual Active
elseif ($action === 'save_penerima_manual_active') {
    $nomor_penerima = trim($_POST['nomor_penerima_manual_active'] ?? '');
    $tipe_penerima = $_POST['tipe_penerima_manual_active'] ?? 'pribadi';
    $nomor_valid = true;
    $nomor_final = '';
    
    if ($tipe_penerima === 'pribadi') {
        if (preg_match('/^62[0-9]{7,15}$/', $nomor_penerima)) {
            $nomor_final = $nomor_penerima . '@s.whatsapp.net';
        } else {
            $nomor_valid = false;
            $response['message'] = 'Nomor pribadi harus diawali 62 dan hanya angka!';
        }
    } elseif ($tipe_penerima === 'grup') {
        $nomor_final = $nomor_penerima . '@g.us';
    }
    
    if ($nomor_valid) {
        $pemilik = $ceknama ?? '';
        if ($pemilik !== '') {
            saveBotSelectionFromPost('bot_penerima_manual_active', 'manual_active');
            $stmt = $conn->prepare("UPDATE botwa SET penerima_manual_active = ? WHERE pemilik = ?");
            $stmt->bind_param('ss', $nomor_final, $pemilik);
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = 'Nomor penerima manual active berhasil disimpan: ' . htmlspecialchars($nomor_final);
                saveHistory($ceknama, $asistant_name, 'Menyimpan nomor penerima manual active');
            } else {
                $response['message'] = 'Gagal menyimpan ke database';
            }
            $stmt->close();
        } else {
            $response['message'] = 'Pemilik tidak diketahui';
        }
    }
    outputJSON($response);
}

// 15. Simpan Nomor Penerima Provisioning
elseif ($action === 'save_penerima_provisioning') {
    $nomor_penerima = trim($_POST['nomor_penerima_provisioning'] ?? '');
    $tipe_penerima = $_POST['tipe_penerima_provisioning'] ?? 'pribadi';
    $nomor_valid = true;
    $nomor_final = '';
    
    if ($tipe_penerima === 'pribadi') {
        if (preg_match('/^62[0-9]{7,15}$/', $nomor_penerima)) {
            $nomor_final = $nomor_penerima . '@s.whatsapp.net';
        } else {
            $nomor_valid = false;
            $response['message'] = 'Nomor pribadi harus diawali 62 dan hanya angka!';
        }
    } elseif ($tipe_penerima === 'grup') {
        $nomor_final = $nomor_penerima . '@g.us';
    }
    
    if ($nomor_valid) {
        $pemilik = $ceknama ?? '';
        if ($pemilik !== '') {
            saveBotSelectionFromPost('bot_penerima_provisioning', 'provisioning');
            $stmt = $conn->prepare("UPDATE botwa SET penerima_provisioning = ? WHERE pemilik = ?");
            $stmt->bind_param('ss', $nomor_final, $pemilik);
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = 'Nomor penerima provisioning berhasil disimpan: ' . htmlspecialchars($nomor_final);
                saveHistory($ceknama, $asistant_name, 'Menyimpan nomor penerima provisioning');
            } else {
                $response['message'] = 'Gagal menyimpan ke database';
            }
            $stmt->close();
        } else {
            $response['message'] = 'Pemilik tidak diketahui';
        }
    }
    outputJSON($response);
}

// 16. Simpan Pengaturan Salam Dinamis
elseif ($action === 'save_salam_dinamis') {
    $owner_key_for_bot_cfg = isset($ceknama) && $ceknama !== '' ? $ceknama : (isset($username) ? $username : 'default');
    $owner_key_for_dynamic_greeting = preg_replace('/[^A-Za-z0-9_.-]/', '_', (string)$owner_key_for_bot_cfg);
    $dynamic_greeting_config_path = __DIR__ . "/notifbot/data/dynamic_greeting-$owner_key_for_dynamic_greeting.json";
    
    $dynamic_greeting_enabled = isset($_POST['dynamic_greeting_enabled']) ? '1' : '0';
    $dynamic_greeting_input = (string)($_POST['dynamic_greeting_list'] ?? '');
    $dynamic_greeting_lines = preg_split('/\r\n|\r|\n/', $dynamic_greeting_input);
    $dynamic_greeting_list = [];
    
    if (is_array($dynamic_greeting_lines)) {
        foreach ($dynamic_greeting_lines as $line) {
            $line = trim((string)$line);
            if ($line !== '') {
                $dynamic_greeting_list[] = $line;
            }
        }
    }
    
    $dynamic_greeting_list = array_values(array_unique($dynamic_greeting_list));
    
    $dynamic_greeting_payload = [
        'enabled' => $dynamic_greeting_enabled === '1',
        'greetings' => $dynamic_greeting_list,
        'owner' => $owner_key_for_bot_cfg,
        'updated_at' => date('Y-m-d H:i:s')
    ];
    
    if (file_put_contents($dynamic_greeting_config_path, json_encode($dynamic_greeting_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false) {
        $response['success'] = true;
        $response['message'] = 'Pengaturan salam dinamis berhasil disimpan';
        saveHistory($ceknama, $asistant_name, 'Menyimpan pengaturan salam dinamis');
    } else {
        $response['message'] = 'Gagal menyimpan pengaturan salam dinamis';
    }
    outputJSON($response);
}

// 17. Simpan Interval ODP LOS
elseif ($action === 'save_interval_odp_los') {
    $interval = trim($_POST['interval_odp_los'] ?? '');
    
    if (!is_numeric($interval) || $interval < 1) {
        $response['message'] = 'Interval harus berupa angka positif';
        outputJSON($response);
    }
    
    $pemilik = $ceknama ?? '';
    if ($pemilik !== '') {
        $stmt = $conn->prepare("UPDATE botwa SET interval_odp_los = ? WHERE pemilik = ?");
        $stmt->bind_param('is', (int)$interval, $pemilik);
        if ($stmt->execute()) {
            $response['success'] = true;
            $response['message'] = 'Interval ODP LOS berhasil disimpan: ' . $interval . ' jam';
            saveHistory($ceknama, $asistant_name, 'Menyimpan interval ODP LOS');
        } else {
            $response['message'] = 'Gagal menyimpan interval ODP LOS';
        }
        $stmt->close();
    } else {
        $response['message'] = 'Pemilik tidak diketahui';
    }
    outputJSON($response);
}

// 18. Simpan Prabayar Grace Period
elseif ($action === 'save_grace_period') {
    $grace_period = trim($_POST['prabayar_grace_period'] ?? '');
    
    if (!is_numeric($grace_period) || $grace_period < 0) {
        $response['message'] = 'Grace period harus berupa angka';
        outputJSON($response);
    }
    
    $pemilik = $ceknama ?? '';
    if ($pemilik !== '') {
        $stmt = $conn->prepare("UPDATE botwa SET prabayar_grace_period = ? WHERE pemilik = ?");
        $stmt->bind_param('is', (int)$grace_period, $pemilik);
        if ($stmt->execute()) {
            $response['success'] = true;
            $response['message'] = 'Prabayar grace period berhasil disimpan: ' . $grace_period . ' hari';
            saveHistory($ceknama, $asistant_name, 'Menyimpan prabayar grace period');
        } else {
            $response['message'] = 'Gagal menyimpan prabayar grace period';
        }
        $stmt->close();
    } else {
        $response['message'] = 'Pemilik tidak diketahui';
    }
    outputJSON($response);
}

// 19. Simpan Reminder Fixed Due Date
elseif ($action === 'save_reminder_fixed_due_date') {
    $jatuh_tempo = intval($_POST['jatuh_tempo'] ?? 0);
    $tanggal_reminder = intval($_POST['tanggal_reminder'] ?? 0);
    $waktu_reminder = trim($_POST['waktu_reminder'] ?? '');
    $botname = trim($_POST['botname'] ?? '');
    $tanggal_awal_tutup_buku = intval($_POST['tanggal_awal_tutup_buku'] ?? 0);
    $tanggal_akhir_tutup_buku = intval($_POST['tanggal_akhir_tutup_buku'] ?? 0);
    
    if ($jatuh_tempo <= 0 || $jatuh_tempo > 31) {
        $response['message'] = 'Tanggal jatuh tempo harus 1-31';
        outputJSON($response);
    }
    
    if ($tanggal_reminder <= 0 || $tanggal_reminder > 31) {
        $response['message'] = 'Tanggal reminder harus 1-31';
        outputJSON($response);
    }
    
    if (empty($waktu_reminder)) {
        $response['message'] = 'Waktu reminder tidak boleh kosong';
        outputJSON($response);
    }
    
    list($jam_reminder, $menit_reminder) = explode(':', $waktu_reminder);
    $jam_reminder = intval($jam_reminder);
    $menit_reminder = intval($menit_reminder);
    
    // Hitung hari_sebelum
    $current_year_month = date('Y-m');
    $jatuh_tempo_timestamp = strtotime($current_year_month . '-' . $jatuh_tempo);
    $reminder_timestamp = strtotime($current_year_month . '-' . $tanggal_reminder);
    $hari_sebelum = ceil(($jatuh_tempo_timestamp - $reminder_timestamp) / (60 * 60 * 24));
    
    // Simpan PARTIAL (8 kolom ini saja) via helper -- prorate_untuk_telat/
    // periode_tercatat/kedua filter reminder TIDAK disebut di sini (dikelola
    // paymentset.php / card Reminder Pembayaran), jadi reminderSettingsSave()
    // tidak akan menimpanya balik ke default (partial update, row lama
    // dipertahankan). Helper ini juga otomatis regenerate mirror
    // notifbot/data/reminder-{username}.json.
    $reminderSaveOk = reminderSettingsSave($conn, $username, [
        'jatuh_tempo' => $jatuh_tempo,
        'tanggal_reminder' => $tanggal_reminder,
        'jam_reminder' => $jam_reminder,
        'menit_reminder' => $menit_reminder,
        'hari_sebelum' => $hari_sebelum,
        'botname' => $botname,
        'tanggal_awal_tutup_buku' => $tanggal_awal_tutup_buku,
        'tanggal_akhir_tutup_buku' => $tanggal_akhir_tutup_buku,
    ]);

    if ($reminderSaveOk) {
        $response['success'] = true;
        $response['message'] = 'Pengaturan Fixed Due Date berhasil disimpan';
        saveHistory($ceknama, $asistant_name, 'Menyimpan pengaturan Fixed Due Date (Sistem Tempo Tetap)');
    } else {
        $response['message'] = 'Gagal menyimpan pengaturan Fixed Due Date';
    }
    outputJSON($response);
}

// 20. Simpan Pengaturan OTP Portal Pelanggan
elseif ($action === 'save_otp_portal') {
    $otp_mode_post = trim($_POST['otp_portal_mode'] ?? 'bypass');
    $otp_mode_post = in_array($otp_mode_post, ['otp', 'bypass'], true) ? $otp_mode_post : 'bypass';
    
    $otp_waapi_post = trim($_POST['otp_portal_waapi'] ?? '');
    $otp_namebot_post = trim($_POST['otp_portal_namebot'] ?? '');
    $otp_password_post = trim($_POST['otp_portal_password'] ?? '');
    $otp_template_post = trim($_POST['otp_portal_template'] ?? '');
    
    if ($otp_template_post === '') {
        $otp_template_post = "*[INI ADALAH PESAN OTOMATIS]*\nIni adalah kode otp anda : *{otp}*";
    }
    
    // Simpan JSON config
    $otp_portal_config_path = __DIR__ . '/broadband/otp_portal_config.json';
    $otp_payload = [
        'otp_mode' => $otp_mode_post,
        'otp_message_template' => $otp_template_post,
        'updated_at' => date('Y-m-d H:i:s')
    ];
    $json_saved = file_put_contents($otp_portal_config_path, json_encode($otp_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    // Simpan WAAPI credentials ke text file
    $otp_portal_waapi_path = __DIR__ . '/broadband/waapi.txt';
    $waapi_content = "waapi={$otp_waapi_post}\nnamebot={$otp_namebot_post}\npassword={$otp_password_post}\n";
    $waapi_saved = file_put_contents($otp_portal_waapi_path, $waapi_content);
    
    if ($json_saved !== false && $waapi_saved !== false) {
        $response['success'] = true;
        $response['message'] = 'Pengaturan OTP Portal Pelanggan berhasil disimpan';
        saveHistory($ceknama, $asistant_name, 'Menyimpan pengaturan OTP portal pelanggan');
        debugLog('save_otp_portal', ['success' => true, 'mode' => $otp_mode_post]);
    } else {
        $response['message'] = 'Gagal menyimpan pengaturan OTP Portal';
        debugLog('save_otp_portal', ['error' => 'File write failed']);
    }
    outputJSON($response);
}

// 21. Simpan Pengaturan Invoice Generator Otomatis
elseif ($action === 'save_invoice_generator') {
    $invoice_generator_enabled = isset($_POST['invoice_generator_enabled']) ? '1' : '0';
    $invoice_generate_start_day = (int)($_POST['invoice_generate_start_day'] ?? 1);
    $invoice_generate_hour = (int)($_POST['invoice_generate_hour'] ?? 0);
    $invoice_generate_minute = (int)($_POST['invoice_generate_minute'] ?? 0);
    $invoice_generate_days_before_due = (int)($_POST['invoice_generate_days_before_due'] ?? 2);

    $pemilik = $ceknama ?? '';

    if (empty($pemilik)) {
        $response['message'] = 'Pemilik tidak diketahui';
        outputJSON($response);
    }

    // Validasi input
    if ($invoice_generate_start_day < 1 || $invoice_generate_start_day > 31) {
        $response['message'] = 'Tanggal harus 1-31';
        outputJSON($response);
    }

    if ($invoice_generate_hour < 0 || $invoice_generate_hour > 23) {
        $response['message'] = 'Jam harus 0-23';
        outputJSON($response);
    }

    if ($invoice_generate_minute < 0 || $invoice_generate_minute > 59) {
        $response['message'] = 'Menit harus 0-59';
        outputJSON($response);
    }

    if ($invoice_generate_days_before_due < 0 || $invoice_generate_days_before_due > 30) {
        $response['message'] = 'Terbit H- sebelum jatuh tempo harus 0-30';
        outputJSON($response);
    }

    // Simpan ke file JSON konfigurasi (sama dengan yang diload di notification.php)
    $config_file = __DIR__ . "/notifbot/data/invoice_generator-$pemilik.json";
    $config = [
        'enabled' => $invoice_generator_enabled === '1',
        'start_day' => $invoice_generate_start_day,
        'hour' => $invoice_generate_hour,
        'minute' => $invoice_generate_minute,
        'days_before_due' => $invoice_generate_days_before_due,
        'updated_at' => date('Y-m-d H:i:s')
    ];
    
    if (file_put_contents($config_file, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false) {
        $response['success'] = true;
        $response['message'] = 'Pengaturan Invoice Generator berhasil disimpan. Waktu: ' . sprintf('%02d', $invoice_generate_start_day) . ' tanggal, jam ' . sprintf('%02d:%02d', $invoice_generate_hour, $invoice_generate_minute);
        saveHistory($ceknama, $asistant_name, 'Menyimpan pengaturan Invoice Generator Otomatis');
        debugLog('save_invoice_generator', ['success' => true, 'day' => $invoice_generate_start_day, 'time' => "$invoice_generate_hour:$invoice_generate_minute"]);
    } else {
        $response['message'] = 'Gagal menyimpan pengaturan Invoice Generator';
        debugLog('save_invoice_generator', ['error' => 'File write failed']);
    }
    outputJSON($response);
}

// Default response if no action matched
outputJSON($response);
?>
