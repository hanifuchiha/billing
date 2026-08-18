<?php
session_start();
require_once __DIR__ . '/../koneksidb.php';
require_once __DIR__ . '/../corporate_helper.php';
corporateEnsureSchema($conn);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit;
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    header('Location: login.php?error=2');
    exit;
}

$username = trim((string) ($_POST['username'] ?? ''));
$password = (string) ($_POST['password'] ?? '');

if ($username === '' || $password === '') {
    header('Location: login.php?error=1');
    exit;
}

$usernameEsc = mysqli_real_escape_string($conn, $username);
$row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id, PORTAL_PASSWORD, STATUS FROM corporate WHERE PORTAL_USERNAME = '$usernameEsc' LIMIT 1"));

if (!$row || empty($row['PORTAL_PASSWORD']) || !password_verify($password, $row['PORTAL_PASSWORD'])) {
    header('Location: login.php?error=1');
    exit;
}

if ($row['STATUS'] !== 'AKTIF') {
    header('Location: login.php?suspended=1');
    exit;
}

session_regenerate_id(true);
$_SESSION['corp_portal_id'] = (int) $row['id'];
unset($_SESSION['csrf_token']);

header('Location: dashboard.php');
exit;
