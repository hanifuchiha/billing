<?php
require '../cek-sesi.php';
require_once __DIR__ . '/../corporate_helper.php';
corporateEnsureSchema($conn);

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    header("Location: ../paket_corporate.php?statusnotif=failed&text=" . urlencode('ID tidak valid'));
    exit;
}

// Scoping: hanya boleh hapus paket milik tenant sendiri (PEMILIK = $ceknama),
// juga dibatasi AREA yang di-assign kalau sesi ini ASSISTANT.
$ceknamaEsc = mysqli_real_escape_string($conn, $ceknama);
$areaFilter = corporateAreaFilterSql('AREA', $AKSES, $area_list ?? '');
$sql = "DELETE FROM paket_corporate WHERE id = $id AND PEMILIK = '$ceknamaEsc'" . $areaFilter;

if (mysqli_query($conn, $sql) && mysqli_affected_rows($conn) > 0) {
    header("Location: ../paket_corporate.php?statusnotif=deleted");
} else {
    header("Location: ../paket_corporate.php?statusnotif=failed&text=" . urlencode('Paket tidak ditemukan atau gagal dihapus'));
}
exit;
