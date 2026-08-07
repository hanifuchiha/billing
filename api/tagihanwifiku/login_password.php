<?php
require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    twk_response(405, ['success' => false, 'message' => 'Method tidak diizinkan.']);
}

$conn = twk_db_connect();
twk_ensure_tables($conn);

$body = twk_get_json_body();
$customerId = trim((string)($body['customer_id'] ?? ($_POST['customer_id'] ?? '')));
$password = trim((string)($body['password'] ?? ($_POST['password'] ?? '')));

if ($customerId === '' || $password === '') {
    twk_response(422, ['success' => false, 'message' => 'ID Pelanggan dan password wajib diisi.']);
}

$pelanggan = twk_find_customer($conn, $customerId);
if (!$pelanggan) {
    twk_response(401, ['success' => false, 'message' => 'Nomor WhatsApp atau ID Pelanggan tidak terdaftar sebagai pelanggan WiFi ini.']);
}

if ((string)$pelanggan['IDPEL'] !== $customerId) {
    twk_response(401, ['success' => false, 'message' => 'Login password harus menggunakan ID Pelanggan yang benar.']);
}

$dbNowa = (string)($pelanggan['NOWA'] ?? '');
$dbPassword = (string)($pelanggan['PASSWORD'] ?? '');
$submittedDigits = preg_replace('/\D+/', '', $password);

$valid = false;
if ($dbNowa !== '') {
    $dbNowaDigits = preg_replace('/\D+/', '', $dbNowa);
    if ($dbNowaDigits !== '' && $dbNowaDigits === $submittedDigits) {
        $valid = true;
    }
}

if (!$valid && $dbPassword !== '') {
    if ($password === $dbPassword) {
        $valid = true;
    } elseif (preg_replace('/\D+/', '', $dbPassword) === $submittedDigits && $submittedDigits !== '') {
        $valid = true;
    } elseif (password_verify($password, $dbPassword)) {
        $valid = true;
    }
}

if (!$valid) {
    twk_response(401, ['success' => false, 'message' => 'ID Pelanggan atau password salah.']);
}

$phone = twk_normalize_phone($dbNowa !== '' ? $dbNowa : $password);
$session = twk_create_session($conn, (string)$pelanggan['IDPEL'], $phone);

twk_response(200, [
    'success' => true,
    'message' => 'Login password berhasil.',
    'data' => [
        'token' => $session['token'],
        'token_expires_at' => $session['expires_at'],
        'customer' => twk_customer_payload($pelanggan)
    ]
]);
