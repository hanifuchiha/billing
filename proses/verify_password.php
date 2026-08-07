<?php
require '../cek-sesi.php';

header('Content-Type: application/json');

if (!isset($_POST['password'])) {
    echo json_encode(['success' => false, 'error' => 'Password tidak diberikan']);
    exit;
}

$inputPassword = $_POST['password'];

// Ambil password dari database berdasarkan username yang sedang login
$query = "SELECT PASWORD FROM user WHERE USERNAME = '$ceknama'";
$result = mysqli_query($conn, $query);

if (!$result) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . mysqli_error($conn)]);
    exit;
}

if (mysqli_num_rows($result) === 0) {
    echo json_encode(['success' => false, 'error' => 'User tidak ditemukan']);
    exit;
}

$userData = mysqli_fetch_assoc($result);
$storedPassword = $userData['PASWORD'];

// Verifikasi password (plain text comparison)
if ($inputPassword === $storedPassword) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Password salah']);
}
?>