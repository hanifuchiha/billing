<?php
// koneksi.php
// Konfigurasi database dibaca dari config.json

$config_file ='../config.json';

// Pastikan file konfigurasi ada
if (!file_exists($config_file)) {
    die("❌ File konfigurasi tidak ditemukan: $config_file");
}

// Baca isi config.json
$config_json = file_get_contents($config_file);
$config = json_decode($config_json, true);

// Validasi isi config
if (!is_array($config) || !isset($config['db_host'], $config['db_user'], $config['db_pass'], $config['db_name'])) {
    die("❌ Format config.json tidak valid.");
}

// Ambil nilai konfigurasi
$servername = $config['db_host'];
$username_db = $config['db_user'];
$password_db = $config['db_pass'];
$database = $config['db_name'];

// Buat koneksi MySQLi
$conn = mysqli_connect($servername, $username_db, $password_db, $database);

// Cek koneksi
if (!$conn) {
    die("❌ Koneksi database gagal: " . mysqli_connect_error());
}

// Set charset agar UTF-8 (mencegah error karakter)
mysqli_set_charset($conn, "utf8mb4");

// Opsional: tampilkan pesan sukses (bisa dihapus di production)
// echo "✅ Koneksi berhasil ke database: $database";
?>
