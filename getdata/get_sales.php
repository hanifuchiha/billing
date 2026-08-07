<?php
include '../cek-sesi.php'; // Pastikan koneksi ke database sudah benar

// Ambil PEMILIK dari GET (sama seperti pola get_odp.php)
$pemilik = isset($_GET['server']) ? mysqli_real_escape_string($conn, $_GET['server']) : '';

if ($pemilik !== '') {
    // Sama seperti query sales di addcustomerform.php (mitra.server = PEMILIK)
    $query = "SELECT DISTINCT nama FROM mitra WHERE server = '$pemilik' AND nama IS NOT NULL AND nama != '' ORDER BY nama ASC";
    $result = mysqli_query($conn, $query);

    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $namasales = htmlspecialchars($row['nama'], ENT_QUOTES, 'UTF-8');
            echo '<option value="' . $namasales . '">' . $namasales . '</option>';
        }
    }
}
exit;
