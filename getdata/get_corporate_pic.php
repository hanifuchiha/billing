<?php
include '../cek-sesi.php';
require_once __DIR__ . '/../corporate_helper.php';
corporateEnsureSchema($conn);

header('Content-Type: application/json; charset=UTF-8');

$corporateId = (int) ($_GET['corporate_id'] ?? 0);
if ($corporateId <= 0) {
    echo json_encode([]);
    exit;
}

// Pastikan corporate ini benar milik tenant yang login (jangan bocorkan PIC
// perusahaan tenant lain lewat tebak-tebak ID), + batas AREA kalau ASSISTANT.
$ceknamaEsc = mysqli_real_escape_string($conn, $ceknama);
$areaFilterCorp = corporateAreaFilterSql('AREA', $AKSES, $area_list ?? '');
$cek = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM corporate WHERE id = $corporateId AND PEMILIK = '$ceknamaEsc'" . $areaFilterCorp . " LIMIT 1"));
if (!$cek) {
    echo json_encode([]);
    exit;
}

$rows = [];
$q = mysqli_query($conn, "SELECT nama, jabatan, email, whatsapp, telepon FROM corporate_pic WHERE corporate_id = $corporateId ORDER BY id ASC");
if ($q) {
    while ($r = mysqli_fetch_assoc($q)) {
        $rows[] = $r;
    }
}

echo json_encode($rows);
exit;
