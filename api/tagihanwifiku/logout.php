<?php
require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    twk_response(405, ['success' => false, 'message' => 'Method tidak diizinkan.']);
}

$conn = twk_db_connect();
$session = twk_require_auth($conn);

$stmt = mysqli_prepare($conn, "UPDATE twk_mobile_sessions SET revoked_at = NOW() WHERE token = ? LIMIT 1");
if ($stmt) {
    mysqli_stmt_bind_param($stmt, 's', $session['token']);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

twk_response(200, [
    'success' => true,
    'message' => 'Logout berhasil.'
]);
