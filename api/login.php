<?php
// API Login
header('Content-Type: application/json');
require_once '../koneksidb.php';
session_start();

$data = json_decode(file_get_contents('php://input'), true);
$response = ['ok' => false, 'message' => '', 'data' => null];

$username = trim($data['username'] ?? '');
$password = $data['password'] ?? '';

if ($username === '' || $password === '') {
    $response['message'] = 'Username dan password wajib diisi';
    echo json_encode($response); exit;
}

$stmt = $conn->prepare("SELECT * FROM user WHERE USERNAME = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    if (password_verify($password, $row['PASWORD'])) {
        $_SESSION['USERNAME'] = $row['USERNAME'];
        $_SESSION['id'] = $row['id'];
        $_SESSION['PEMILIK'] = $row['USERNAME'];
        $_SESSION['NOWA'] = $row['NOWA'];
        $_SESSION['server'] = $row['server'];
        $_SESSION['status'] = 'login';
        $response['ok'] = true;
        $response['message'] = 'Login berhasil';
        $response['data'] = [
            'id' => $row['id'],
            'username' => $row['USERNAME'],
            'nowa' => $row['NOWA'],
            'email' => $row['domain'],
            'inisial' => $row['inisial'],
            'status' => $row['STATUS'],
            'saldo' => $row['saldo'],
            'server' => $row['server'],
            'akses' => $row['akses'],
            'expired_at' => $row['expired_at'],
            'created_at' => $row['created_at']
        ];
    } else {
        $response['message'] = 'Password salah';
    }
} else {
    $response['message'] = 'Username tidak ditemukan';
}
$stmt->close();
echo json_encode($response);