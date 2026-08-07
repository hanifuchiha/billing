<?php
include '../cek-sesi.php'; // Pastikan koneksi ke database sudah benar

if (!isset($_GET['server']) || empty($_GET['server'])) {
    echo "<option disabled value=''>Server tidak valid</option>";
    exit;
}

$server = $_GET['server'];

$query = "SELECT AREA FROM `server` WHERE `PEMILIK` = ?";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "s", $server);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($result) > 0) {
    echo '<option value="">-- Pilih AREA --</option>';
    while ($row = mysqli_fetch_assoc($result)) {
        echo '<option value="' . htmlspecialchars($row['AREA']) . '">' . htmlspecialchars($row['AREA']) . '</option>';
    }
    echo "<option  value='ALL'>ALL Area</option>";
} else {
    echo "<option disabled value=''>Tidak ada AREA $server tersedia</option>";
}

mysqli_stmt_close($stmt);
exit;
