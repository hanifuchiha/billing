<?php
require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    twk_response(405, ['success' => false, 'message' => 'Method tidak diizinkan.']);
}

$conn = twk_db_connect();
twk_ensure_tables($conn);

$body = twk_get_json_body();
$phoneInput = trim((string)(
    $body['phone']
    ?? $body['idselect']
    ?? $body['nomor_whatsapp']
    ?? $body['nowa']
    ?? ($_POST['phone'] ?? ($_POST['idselect'] ?? ($_POST['nomor_whatsapp'] ?? ($_POST['nowa'] ?? ''))))
));

if ($phoneInput === '') {
    twk_response(422, ['success' => false, 'message' => 'Nomor WhatsApp wajib diisi.']);
}

$normalizedPhone = twk_normalize_phone($phoneInput);
if ($normalizedPhone === '' || strlen($normalizedPhone) < 10 || strlen($normalizedPhone) > 16) {
    twk_response(422, ['success' => false, 'message' => 'Format nomor WhatsApp tidak valid.']);
}

$pelanggan = twk_find_customer($conn, $normalizedPhone);
if (!$pelanggan) {
    twk_response(401, ['success' => false, 'message' => 'Nomor WhatsApp atau ID Pelanggan tidak terdaftar sebagai pelanggan WiFi ini.']);
}

$idpel = (string)($pelanggan['IDPEL'] ?? '');
$phone = twk_normalize_phone((string)($pelanggan['NOWA'] ?? $normalizedPhone));
$cfg = twk_load_config();
$otpEnabled = false;

// Samakan dengan versi web: prioritas mode dari otp_portal_config.json
$otpPortalConfigPath = dirname(__DIR__, 2) . '/broadband/otp_portal_config.json';
if (file_exists($otpPortalConfigPath)) {
    $otpPortalCfg = json_decode(file_get_contents($otpPortalConfigPath), true);
    if (is_array($otpPortalCfg) && isset($otpPortalCfg['otp_mode'])) {
        $otpEnabled = ((string)$otpPortalCfg['otp_mode'] === 'otp');
    }
}

if (!$otpEnabled) {
    $session = twk_create_session($conn, $idpel, $phone);
    twk_response(200, [
        'success' => true,
        'message' => 'Login berhasil (mode bypass OTP aktif).',
        'data' => [
            'idpel' => $idpel,
            'phone' => $phone,
            'otp_expires_at' => null,
            'wa_status' => 'bypass',
            'otp_mode' => 'bypass',
            'debug_otp' => null,
            'auto_login' => true,
            'login_data' => [
                'token' => $session['token'],
                'token_expires_at' => $session['expires_at'],
                'customer' => twk_customer_payload($pelanggan)
            ]
        ]
    ]);
}

$otp = (string)random_int(1000, 9999);
$expiresAt = date('Y-m-d H:i:s', time() + (5 * 60));

$cleanup = mysqli_prepare($conn, "DELETE FROM twk_otp_codes WHERE (phone = ? OR idpel = ?) AND used_at IS NULL");
if ($cleanup) {
    mysqli_stmt_bind_param($cleanup, 'ss', $phone, $idpel);
    mysqli_stmt_execute($cleanup);
    mysqli_stmt_close($cleanup);
}

$stmt = mysqli_prepare(
    $conn,
    "INSERT INTO twk_otp_codes (idpel, phone, otp_code, expires_at) VALUES (?, ?, ?, ?)"
);
if (!$stmt) {
    twk_response(500, ['success' => false, 'message' => 'Gagal membuat OTP.']);
}

mysqli_stmt_bind_param($stmt, 'ssss', $idpel, $phone, $otp, $expiresAt);
$ok = mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

if (!$ok) {
    twk_response(500, ['success' => false, 'message' => 'Gagal menyimpan OTP.']);
}

$waSendStatus = 'bypass';
$waMessage = 'Mode bypass aktif.';

if ($otpEnabled) {
    $waFile = dirname(__DIR__, 2) . '/broadband/waapi.txt';
    $waapi = '';
    $namebot = '';
    $password = '';

    if (file_exists($waFile)) {
        $lines = file($waFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, 'waapi=') === 0) {
                $waapi = trim(substr($line, 6));
            } elseif (strpos($line, 'namebot=') === 0) {
                $namebot = trim(substr($line, 8));
            } elseif (strpos($line, 'password=') === 0) {
                $password = trim(substr($line, 9));
            }
        }
    }

    if ($waapi !== '' && $namebot !== '' && $password !== '') {
        $messageTpl = (string)($cfg['otp_login_template'] ?? '*[INI ADALAH PESAN OTOMATIS]*\nKode OTP anda: *{otp}*');
        $text = str_replace(['{otp}', '{nomor}', '{phone}'], [$otp, $phone, $phone], $messageTpl);

        $payload = [
            'phone' => $phone . '@s.whatsapp.net',
            'message' => $text
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, rtrim($waapi, '/') . '/send/message');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_USERPWD, $namebot . ':' . $password);

        $resp = curl_exec($ch);
        $errNo = curl_errno($ch);
        curl_close($ch);

        if ($errNo === 0) {
            $waSendStatus = 'sent';
            $waMessage = 'OTP berhasil dikirim ke WhatsApp.';
        } else {
            $waSendStatus = 'timeout';
            $waMessage = 'OTP dibuat, tetapi pengiriman WhatsApp timeout.';
        }
    } else {
        $waSendStatus = 'bypass';
        $waMessage = 'OTP dibuat, konfigurasi WhatsApp belum lengkap.';
    }
}

twk_response(200, [
    'success' => true,
    'message' => $waMessage,
    'data' => [
        'idpel' => $idpel,
        'phone' => $phone,
        'otp_expires_at' => $expiresAt,
        'wa_status' => $waSendStatus,
        'otp_mode' => 'otp',
        'debug_otp' => null,
        'auto_login' => false,
        'login_data' => null
    ]
]);
