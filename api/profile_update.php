<?php
// Endpoint update profile untuk Android Qbilling
header('Content-Type: application/json');
require_once '../koneksidb.php';

$data = json_decode(file_get_contents('php://input'), true);
$response = ['success' => false, 'error' => ''];

$currentUsername = trim($data['username'] ?? '');
$currentPassword = (string)($data['password'] ?? '');
$newUsername = trim($data['username_new'] ?? '');
$domain = trim($data['email'] ?? '');
$whatsapp = trim($data['whatsapp'] ?? '');
$newPassword = (string)($data['password_new'] ?? '');
$inisial = trim($data['inisial'] ?? '');

if ($currentUsername === '' || $currentPassword === '' || $newUsername === '' || $domain === '' || $whatsapp === '') {
    $response['error'] = 'Field wajib tidak lengkap';
    echo json_encode($response);
    exit;
}

$stmt = $conn->prepare('SELECT id, USERNAME, PASWORD, STATUS FROM user WHERE USERNAME = ? LIMIT 1');
$stmt->bind_param('s', $currentUsername);
$stmt->execute();
$result = $stmt->get_result();

if (!$result || $result->num_rows === 0) {
    $response['error'] = 'User tidak ditemukan';
    echo json_encode($response);
    exit;
}

$user = $result->fetch_assoc();
$storedPassword = (string)$user['PASWORD'];
$isPasswordValid = password_verify($currentPassword, $storedPassword) || $currentPassword === $storedPassword;

if (!$isPasswordValid) {
    $response['error'] = 'Password saat ini tidak valid';
    echo json_encode($response);
    exit;
}

// Cek username baru dipakai user lain atau tidak
$stmtCheck = $conn->prepare('SELECT id FROM user WHERE USERNAME = ? AND id <> ? LIMIT 1');
$stmtCheck->bind_param('si', $newUsername, $user['id']);
$stmtCheck->execute();
$exists = $stmtCheck->get_result();
if ($exists && $exists->num_rows > 0) {
    $response['error'] = 'Username baru sudah digunakan';
    echo json_encode($response);
    exit;
}

$setParts = [
    'USERNAME = ?',
    'NOWA = ?',
    'domain = ?'
];
$params = [$newUsername, $whatsapp, $domain];
$types = 'sss';

// OWNER boleh update inisial, ASSISTANT tidak
if (strtoupper((string)$user['STATUS']) !== 'ASSISTANT' && $inisial !== '') {
    $setParts[] = 'inisial = ?';
    $params[] = $inisial;
    $types .= 's';
}

if ($newPassword !== '') {
    $hashedNewPassword = password_hash($newPassword, PASSWORD_DEFAULT);
    $setParts[] = 'PASWORD = ?';
    $params[] = $hashedNewPassword;
    $types .= 's';
}

$sql = 'UPDATE user SET ' . implode(', ', $setParts) . ' WHERE id = ?';
$params[] = (int)$user['id'];
$types .= 'i';

$stmtUpdate = $conn->prepare($sql);
$stmtUpdate->bind_param($types, ...$params);

if ($stmtUpdate->execute()) {
    $response['success'] = true;
    $response['message'] = 'Profile berhasil diupdate. Silakan login ulang.';
} else {
    $response['error'] = 'Gagal update profile: ' . $conn->error;
}

echo json_encode($response);
