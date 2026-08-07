<?php
// API Signup
header('Content-Type: application/json');
require_once '../koneksidb.php';
session_start();

$data = json_decode(file_get_contents('php://input'), true);
$response = ['ok' => false, 'message' => '', 'data' => null];

$username = trim($data['nama'] ?? '');
$nowa = trim($data['nowa'] ?? '');
$email = trim($data['email'] ?? '');
$password1 = $data['password'] ?? '';
$password2 = $data['password2'] ?? '';

if ($username === '' || $nowa === '' || $email === '' || $password1 === '' || $password2 === '') {
    $response['message'] = 'Semua field wajib diisi';
    echo json_encode($response); exit;
}
if (preg_match('/\s/', $username)) {
    $response['message'] = 'Username tidak boleh ada spasi!';
    echo json_encode($response); exit;
}
if (!preg_match('/^62\d+$/', $nowa)) {
    $response['message'] = 'Nomor WhatsApp harus diawali 62 dan hanya angka';
    echo json_encode($response); exit;
}
if ($password1 !== $password2) {
    $response['message'] = 'Password tidak cocok!';
    echo json_encode($response); exit;
}
if (strlen($password1) < 6) {
    $response['message'] = 'Password minimal 6 karakter!';
    echo json_encode($response); exit;
}

$stmt = $conn->prepare("SELECT USERNAME FROM user WHERE USERNAME = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows > 0) {
    $response['message'] = 'Username sudah digunakan.';
    echo json_encode($response); exit;
}
$stmt->close();

$created_at = date('Y-m-d H:i:s');
$expired_at = date('Y-m-d H:i:s', strtotime('+7 days'));
$words = explode(' ', strtoupper($username));
$initials = '';
foreach ($words as $word) {
    $initials .= substr($word, 0, 1);
    if (strlen($initials) >= 3) break;
}
if (strlen($initials) < 3) {
    $initials .= strtoupper(substr(str_replace(' ', '', $username), strlen($initials), 3 - strlen($initials)));
}
$initials = substr($initials, 0, 3);
$password_hash = password_hash($password1, PASSWORD_DEFAULT);

$sql = "INSERT INTO user (USERNAME, PASWORD, STATUS, NOWA, saldo, server, payment_default, domain, inisial, akses, created_at, expired_at, email) VALUES (?, ?, 'USER', ?, 2000, NULL, 'manual_bank', ?, ?, '', ?, ?, ?)";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ssssssss", $username, $password_hash, $nowa, $email, $initials, $created_at, $expired_at, $email);
if ($stmt->execute()) {
    $response['ok'] = true;
    $response['message'] = 'Registrasi berhasil';
    $response['data'] = ['username' => $username, 'nowa' => $nowa, 'email' => $email, 'created_at' => $created_at, 'expired_at' => $expired_at, 'inisial' => $initials];
} else {
    $response['message'] = 'Gagal menyimpan data: ' . $stmt->error;
}
$stmt->close();
echo json_encode($response);