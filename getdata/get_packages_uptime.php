<?php
require '../routeros_api.class.php'; // Sesuaikan dengan lokasi file Anda
require '../cek-sesi.php'; // Sesuaikan dengan koneksi database Anda

header("Content-Type: text/html; charset=UTF-8");

// Cek apakah request menggunakan POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo "<option value=''>Metode tidak diizinkan</option>";
    exit;
}

// Ambil data dari POST
$area = $_POST['area'] ?? '';
$server = $_POST['server'] ?? '';
$packages = $_POST['packages'];
if (empty($area) || empty($server)) {
    echo "<option value=''>Parameter tidak lengkap</option>";
    exit;
}


// Gunakan Prepared Statement untuk keamanan
$sql = "SELECT `uptime` FROM `paket_hotspot` WHERE `area` = ? AND `pemilik` = ? AND `paket` = ?";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "sss", $area, $server, $packages);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

// Cek apakah ada data
if (!$result || mysqli_num_rows($result) == 0) {
    echo "<option value=''>EROR LOAD</option>";
    exit;
}


while ($data = mysqli_fetch_assoc($result)) {
    echo "<option value='{$data['uptime']}'>{$data['uptime']}</option>";
}
exit;
