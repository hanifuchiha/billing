<?php
include '../cek-sesi.php'; // Pastikan koneksi ke database sudah benar
if (isset($_GET['server']) && isset($_GET['area'])) {
    $server = mysqli_real_escape_string($conn, $_GET['server']);
    $area = mysqli_real_escape_string($conn, $_GET['area']);

    $query = "SELECT * FROM paket WHERE `PEMILIK` = '$server' AND `AREA` = '$area'";
    $result = mysqli_query($conn, $query);

    if (mysqli_num_rows($result) > 0) {
        echo '<option value="">-- Pilih Packages --</option>';
        while ($row = mysqli_fetch_assoc($result)) {
            echo '<option value="' . htmlspecialchars($row['PAKET']) . '">' . htmlspecialchars($row['PAKET']) . '</option>';
        }
    } else {
        echo "<option disabled value=''>Tidak ada Packages di $area  $server tersedia</option>";
    }
}
exit;
