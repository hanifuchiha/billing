<?php
include '../cek-sesi.php'; // Pastikan koneksi ke database sudah benar
// Ambil brand dan area dari GET
$pemilik = isset($_GET['server']) ? mysqli_real_escape_string($conn, $_GET['server']) : '';
$area = isset($_GET['area']) ? mysqli_real_escape_string($conn, $_GET['area']) : '';

if ($pemilik && $area) {
    $query = "SELECT KODE, NAME FROM odp WHERE Hirarki = 'ODC' AND PEMILIK = '$pemilik' AND AREA = '$area' ORDER BY KODE ASC";
    $result = mysqli_query($conn, $query);
    if ($result && mysqli_num_rows($result) > 0) {
        echo '<option value="">-- Pilih ODC --</option>';
        while ($row = mysqli_fetch_assoc($result)) {
            echo '<option value="' . htmlspecialchars($row['KODE']) . '">' . htmlspecialchars($row['KODE']) . ' ( ' . htmlspecialchars($row['NAME']) . ' )</option>';
        }
    } else {
        echo "<option disabled selected value=''>Tidak ada ODC $pemilik $area tersedia</option>";
    }
} else {
    echo "<option disabled selected value=''>Anda belum memiliki ODC</option>";
}
exit;
