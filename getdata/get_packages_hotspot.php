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

if (empty($area) || empty($server)) {
    echo "<option value=''>Parameter tidak lengkap</option>";
    exit;
}


// Gunakan Prepared Statement untuk keamanan
$sql = "SELECT `id`, `paket`, `harga` FROM `paket_hotspot` WHERE `area` = ? AND `pemilik` = ? ";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "ss", $area, $server);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$rows = reseller_filter_rows($conn, reseller_collect_rows($result), 'hotspot');
$rows = paketVisibilityFilterRows($rows, $assistant_hidden_paket_hotspot, 'paket');

// Cek apakah ada data
if (empty($rows)) {
    echo "<option value=''>EROR LOAD</option>";
    exit;
}

echo "<option disabled selected value=''>-- Pilih Packages --</option>";
foreach ($rows as $data) {
    echo "<option value='" . htmlspecialchars($data['paket']) . "'>" . htmlspecialchars($data['paket']) . "</option>";
}
exit;
