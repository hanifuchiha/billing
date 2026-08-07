<?php
require '../cek-sesi.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../user.php');
    exit;
}

$reseller_user_id = isset($_POST['reseller_user_id']) ? (int)$_POST['reseller_user_id'] : 0;

// Pastikan reseller yang diedit benar-benar milik akun utama yang sedang login
$owned = false;
if ($reseller_user_id > 0) {
    $check = mysqli_query($conn, "SELECT id FROM user WHERE id = $reseller_user_id AND STATUS = 'ASSISTANT' AND grup = '" . mysqli_real_escape_string($conn, $current_user_id) . "'");
    $owned = $check && mysqli_num_rows($check) > 0;
}

if (!$owned) {
    header('Location: ../user.php');
    exit;
}

function reseller_save_paket_rows($conn, $reseller_user_id, $type, $enabledPost, $namaPost, $hargaPost) {
    if (!is_array($namaPost)) {
        return;
    }
    foreach ($namaPost as $paket_id => $nama) {
        $paket_id = (int)$paket_id;
        if ($paket_id <= 0) {
            continue;
        }
        $enabled = (isset($enabledPost[$paket_id]) && $enabledPost[$paket_id] == '1') ? 1 : 0;
        $harga = isset($hargaPost[$paket_id]) ? (float)$hargaPost[$paket_id] : 0;
        $namaEsc = mysqli_real_escape_string($conn, (string)$nama);
        $typeEsc = mysqli_real_escape_string($conn, $type);

        $sql = "INSERT INTO reseller_paket_price (reseller_user_id, paket_type, paket_id, paket_nama, enabled, custom_harga)
                VALUES ($reseller_user_id, '$typeEsc', $paket_id, '$namaEsc', $enabled, $harga)
                ON DUPLICATE KEY UPDATE paket_nama = '$namaEsc', enabled = $enabled, custom_harga = $harga";
        mysqli_query($conn, $sql);
    }
}

reseller_save_paket_rows(
    $conn,
    $reseller_user_id,
    'broadband',
    $_POST['broadband_enabled'] ?? [],
    $_POST['broadband_nama'] ?? [],
    $_POST['broadband_harga'] ?? []
);

reseller_save_paket_rows(
    $conn,
    $reseller_user_id,
    'hotspot',
    $_POST['hotspot_enabled'] ?? [],
    $_POST['hotspot_nama'] ?? [],
    $_POST['hotspot_harga'] ?? []
);

header('Location: ../user.php?edit=' . $reseller_user_id . '&filter_harga=saved');
exit;
