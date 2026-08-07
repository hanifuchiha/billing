<?php
include '../cek-sesi.php'; // Pastikan koneksi ke database sudah benar

if (isset($_GET['area'])) {
    $area = mysqli_real_escape_string($conn, $_GET['area']);

    $query = "SELECT * FROM odp WHERE AREA = '$area'";
    $result = mysqli_query($conn, $query);

    if (mysqli_num_rows($result) > 0) {
        echo '<option value="">-- Pilih ODP --</option>';
        while ($row = mysqli_fetch_assoc($result)) {
            echo '<option value="' . htmlspecialchars($row['KODE']) . '">' . htmlspecialchars($row['KODE']) . " ( " . htmlspecialchars($row['NAME']) . ')  </option>';
        }
    } else {
        echo "<option disabled value=''>Tidak ada ODP $area tersedia</option>";
    }
}
exit;
