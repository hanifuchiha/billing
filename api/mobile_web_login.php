<?php
// Mobile Web Login Bridge
// Tujuan: memungkinkan Android WebView membuka halaman web asli dengan sesi login otomatis.

require_once '../koneksibilling.php';
session_start();

if (!isset($conn) || !$conn) {
    http_response_code(500);
    echo 'Koneksi database gagal';
    exit;
}

$username = isset($_GET['username']) ? trim((string)$_GET['username']) : '';
$password = isset($_GET['password']) ? (string)$_GET['password'] : '';
$target = isset($_GET['target']) ? trim((string)$_GET['target']) : 'dashboard.php';

$allowedTargets = [
    'dashboard.php',
    'user.php',
    'pelanggan_menunggak.php',
    'daftar_pelanggan_berhenti.php',
    'broadcast.php',
    'ftth_maps.php',
    'mynetworkmap.php'
];

if (!in_array($target, $allowedTargets, true)) {
    http_response_code(400);
    echo 'Target tidak diizinkan';
    exit;
}

if ($username === '' || $password === '') {
    http_response_code(400);
    echo 'Username/password wajib diisi';
    exit;
}

$stmt = $conn->prepare('SELECT * FROM user WHERE USERNAME = ? LIMIT 1');
$stmt->bind_param('s', $username);
$stmt->execute();
$result = $stmt->get_result();

if (!$result || $result->num_rows === 0) {
    http_response_code(401);
    echo 'Autentikasi gagal';
    exit;
}

$row = $result->fetch_assoc();
$valid = false;

if (isset($row['PASWORD']) && password_verify($password, (string)$row['PASWORD'])) {
    $valid = true;
} elseif (isset($row['PASWORD']) && hash_equals((string)$row['PASWORD'], $password)) {
    // Fallback untuk data lama jika ada password plain-text.
    $valid = true;
}

if (!$valid) {
    http_response_code(401);
    echo 'Autentikasi gagal';
    exit;
}

$_SESSION['USERNAME'] = $row['USERNAME'];
$_SESSION['id'] = $row['id'];
$_SESSION['PEMILIK'] = $row['USERNAME'];
$_SESSION['NOWA'] = isset($row['NOWA']) ? $row['NOWA'] : '';
$_SESSION['server'] = isset($row['server']) ? $row['server'] : '';
$_SESSION['status'] = 'login';

header('Location: ../' . $target);
exit;
