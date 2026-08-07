<?php
// Mulai session
session_start();

// Hapus semua data session
$_SESSION = array();

// Hapus session cookie jika ada
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Hancurkan session
session_destroy();

// Hapus semua cookie yang ada
if (isset($_COOKIE)) {
    foreach ($_COOKIE as $name => $value) {
        setcookie($name, '', time() - 3600, '/');
        setcookie($name, '', time() - 3600);
    }
}

// Tampilkan alert Akses Ditolak
echo "<script>alert('Akses Ditolak!');</script>";

// Opsional: Redirect ke halaman lain
// header('Location: login.php');
// exit;
?>