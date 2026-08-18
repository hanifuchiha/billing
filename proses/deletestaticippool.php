<?php
require '../cek-sesi.php';
require_once __DIR__ . '/../staticip_helper.php';
staticipEnsureSchema($conn);

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    header("Location: ../staticippool.php?statusnotif=failed&text=" . urlencode('ID tidak valid'));
    exit;
}

// Scoping: hanya boleh hapus range milik tenant sendiri (PEMILIK = $ceknama),
// juga dibatasi AREA yang di-assign kalau sesi ini ASSISTANT.
$ceknamaEsc = mysqli_real_escape_string($conn, $ceknama);
$areaFilter = staticipAreaFilterSql('AREA', $AKSES, $area_list ?? '');
$sql = "DELETE FROM pool_staticip WHERE id = $id AND PEMILIK = '$ceknamaEsc'" . $areaFilter;

if (mysqli_query($conn, $sql) && mysqli_affected_rows($conn) > 0) {
    header("Location: ../staticippool.php?statusnotif=deleted");
} else {
    header("Location: ../staticippool.php?statusnotif=failed&text=" . urlencode('Range tidak ditemukan atau gagal dihapus'));
}
exit;
