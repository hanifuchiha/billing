<?php
require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    twk_response(405, ['success' => false, 'message' => 'Method tidak diizinkan.']);
}

$conn = twk_db_connect();
$session = twk_require_auth($conn);
$idpel = (string)$session['idpel'];

$pelanggan = twk_find_customer($conn, $idpel);
if (!$pelanggan) {
    twk_response(404, ['success' => false, 'message' => 'Data pelanggan tidak ditemukan.']);
}

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS twk_wifi_profiles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    idpel VARCHAR(100) NOT NULL,
    ssid VARCHAR(128) NOT NULL,
    wifi_password VARCHAR(128) NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_twk_wifi_idpel (idpel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$body = twk_get_json_body();
$ssid = trim((string)($body['ssid'] ?? ($_POST['ssid'] ?? '')));
$wifiPassword = trim((string)($body['wifi_password'] ?? ($_POST['wifi_password'] ?? '')));
$serverId = (int)($body['server_id'] ?? ($_POST['server_id'] ?? 0));
$serialRaw = trim((string)($body['serial_raw'] ?? ($_POST['serial_raw'] ?? '')));
$paramPath = trim((string)($body['param_path'] ?? ($_POST['param_path'] ?? '')));
$passwordParamPath = trim((string)($body['password_param_path'] ?? ($_POST['password_param_path'] ?? '')));
$enableParamPath = trim((string)($body['enable_param_path'] ?? ($_POST['enable_param_path'] ?? '')));
$enableValue = trim((string)($body['enable_value'] ?? ($_POST['enable_value'] ?? '1')));

if ($ssid === '') {
    twk_response(422, ['success' => false, 'message' => 'SSID wajib diisi.']);
}
if (strlen($ssid) > 64) {
    twk_response(422, ['success' => false, 'message' => 'SSID maksimal 64 karakter.']);
}
if ($wifiPassword !== '' && strlen($wifiPassword) < 8) {
    twk_response(422, ['success' => false, 'message' => 'Password minimal 8 karakter.']);
}
if (strlen($wifiPassword) > 64) {
    twk_response(422, ['success' => false, 'message' => 'Password maksimal 64 karakter.']);
}

$acsTaskId = '';
$acsQueued = false;
$canAcsUpdate = ($serverId > 0 && $serialRaw !== '' && $paramPath !== '');
if ($canAcsUpdate) {
    if (!preg_match('/^(InternetGatewayDevice|Device)\./', $paramPath) || !preg_match('/\.SSID$/i', $paramPath)) {
        twk_response(422, ['success' => false, 'message' => 'Parameter SSID ACS tidak valid.']);
    }

    if ($passwordParamPath === '' && $wifiPassword !== '') {
        $passwordParamPath = preg_replace('/\.SSID$/i', '.PreSharedKey.1.PreSharedKey', $paramPath);
    }

    if ($enableParamPath === '') {
        $enableParamPath = preg_replace('/\.SSID$/i', '.Enable', $paramPath);
    }

    $cfg = twk_load_config();
    $domain = trim((string)($cfg['domain'] ?? 'quenbytekniksejahtera.com'));
    if ($domain === '') {
        $domain = 'quenbytekniksejahtera.com';
    }

    $acsPayload = [
        'server_id' => $serverId,
        'serial_raw' => $serialRaw,
        'param_path' => $paramPath,
        'ssid_value' => $ssid,
        'idpel' => $idpel,
        'acs_token' => twk_build_portal_acs_token($idpel),
        'enable_param_path' => $enableParamPath,
        'enable_value' => $enableValue,
    ];

    if ($wifiPassword !== '' && $passwordParamPath !== '') {
        $acsPayload['password_value'] = $wifiPassword;
        $acsPayload['password_param_path'] = $passwordParamPath;
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://' . $domain . '/crm/billing/getdata/acs_update_ssid.php',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($acsPayload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
    ]);
    $acsRaw = curl_exec($ch);
    $acsErr = curl_error($ch);
    $acsCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($acsErr) {
        twk_response(502, ['success' => false, 'message' => 'Gagal terhubung ke ACS: ' . $acsErr]);
    }

    $acsJson = json_decode((string)$acsRaw, true);
    if ($acsCode !== 200 || !is_array($acsJson) || !($acsJson['success'] ?? false)) {
        twk_response(502, [
            'success' => false,
            'message' => (string)($acsJson['message'] ?? 'Gagal update ACS WiFi.'),
            'data' => ['http_code' => $acsCode]
        ]);
    }

    $acsTaskId = (string)($acsJson['task_id'] ?? '');
    $acsQueued = true;
}

$stmt = mysqli_prepare($conn, "INSERT INTO twk_wifi_profiles (idpel, ssid, wifi_password) VALUES (?, ?, ?)");
if (!$stmt) {
    twk_response(500, ['success' => false, 'message' => 'Gagal menyimpan pengaturan WiFi.']);
}

mysqli_stmt_bind_param($stmt, 'sss', $idpel, $ssid, $wifiPassword);
$ok = mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

if (!$ok) {
    twk_response(500, ['success' => false, 'message' => 'Gagal menyimpan pengaturan WiFi.']);
}

twk_response(200, [
    'success' => true,
    'message' => $acsQueued
        ? 'Perubahan WiFi berhasil dikirim ke ACS. Tunggu 10 menit atau bisa lebih.'
        : 'Pengaturan WiFi berhasil disimpan.',
    'data' => [
        'idpel' => $idpel,
        'ssid' => $ssid,
        'wifi_password' => $wifiPassword,
        'updated_at' => date('Y-m-d H:i:s'),
        'acs_queued' => $acsQueued,
        'acs_task_id' => $acsTaskId
    ]
]);
