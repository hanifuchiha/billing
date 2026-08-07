<?php
require '../cek-sesi.php';
require_once __DIR__ . '/../../mitra/registrasi_helper.php';

// Toggle "Input Paket Manual" disimpan per akun owner ($ceknama), bukan
// per-paket seperti TAMPIL_DAFTAR di bawah, karena settingnya berlaku untuk
// seluruh paket/brand milik owner tsb. Lihat registrasi_helper.php.
registrasi_save_manual_paket_enabled($ceknama ?? '', isset($_POST['manual_paket_enabled']));

// Batasi hanya ke paket milik akun yang sedang login, memakai aturan scoping
// yang sama dengan daftar paket di packages.php (PEMILIK untuk admin/owner,
// AREA untuk ASSISTANT), supaya satu akun tidak bisa mengubah pengaturan
// pendaftaran milik akun lain.
if ($AKSES != 'ASSISTANT') {
    $current_user_id = isset($_SESSION['id']) ? (int)$_SESSION['id'] : 0;
    $queryServerId = mysqli_query($conn, "SELECT PEMILIK FROM server WHERE user_id = $current_user_id");
    $ownerList = [];
    while ($row = mysqli_fetch_assoc($queryServerId)) {
        $ownerList[] = "'" . mysqli_real_escape_string($conn, $row['PEMILIK']) . "'";
    }
    $ownerListSql = count($ownerList) > 0 ? implode(',', $ownerList) : "''";
    $scopeSql = "PEMILIK IN ($ownerListSql)";
} else {
    $scopeSql = "AREA IN ($area_list)";
}

$ids = isset($_POST['tampil_ids']) && is_array($_POST['tampil_ids']) ? array_map('intval', $_POST['tampil_ids']) : [];

mysqli_query($conn, "UPDATE paket SET TAMPIL_DAFTAR = 0 WHERE $scopeSql");

if (!empty($ids)) {
    $idList = implode(',', $ids);
    mysqli_query($conn, "UPDATE paket SET TAMPIL_DAFTAR = 1 WHERE id IN ($idList) AND $scopeSql");
}

header('Location: ../packages.php?tampildaftar=1');
exit;
