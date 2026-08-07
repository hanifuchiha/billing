<?php
require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    twk_response(405, ['success' => false, 'message' => 'Method tidak diizinkan.']);
}

$conn = twk_db_connect();
twk_ensure_tables($conn);

$body = twk_get_json_body();
$phoneInput = trim((string)($body['phone'] ?? ($_POST['phone'] ?? '')));
$otpInput = trim((string)($body['otp'] ?? ($_POST['otp'] ?? '')));

if ($phoneInput === '' || $otpInput === '') {
    twk_response(422, ['success' => false, 'message' => 'Nomor WhatsApp dan OTP wajib diisi.']);
}

$phone = twk_normalize_phone($phoneInput);
if ($phone === '') {
    twk_response(422, ['success' => false, 'message' => 'Nomor WhatsApp tidak valid.']);
}

$stmt = mysqli_prepare(
    $conn,
    "SELECT * FROM twk_otp_codes WHERE phone = ? AND otp_code = ? AND used_at IS NULL ORDER BY id DESC LIMIT 1"
);
if (!$stmt) {
    twk_response(500, ['success' => false, 'message' => 'Gagal verifikasi OTP.']);
}

mysqli_stmt_bind_param($stmt, 'ss', $phone, $otpInput);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$otpRow = $res ? mysqli_fetch_assoc($res) : null;
mysqli_stmt_close($stmt);

if (!$otpRow) {
    twk_response(401, ['success' => false, 'message' => 'OTP tidak valid.']);
}

if (strtotime((string)$otpRow['expires_at']) < time()) {
    twk_response(401, ['success' => false, 'message' => 'OTP sudah kedaluwarsa.']);
}

$idpel = (string)$otpRow['idpel'];
$pelanggan = twk_find_customer($conn, $idpel);
if (!$pelanggan) {
    twk_response(404, ['success' => false, 'message' => 'Data pelanggan tidak ditemukan.']);
}

$markUsed = mysqli_prepare($conn, "UPDATE twk_otp_codes SET used_at = NOW() WHERE id = ? LIMIT 1");
if ($markUsed) {
    $otpId = (int)$otpRow['id'];
    mysqli_stmt_bind_param($markUsed, 'i', $otpId);
    mysqli_stmt_execute($markUsed);
    mysqli_stmt_close($markUsed);
}

$session = twk_create_session($conn, $idpel, $phone);

twk_response(200, [
    'success' => true,
    'message' => 'Login OTP berhasil.',
    'data' => [
        'token' => $session['token'],
        'token_expires_at' => $session['expires_at'],
        'customer' => twk_customer_payload($pelanggan)
    ]
]);
