<?php
include '../cek-sesi.php';
require_once __DIR__ . '/../corporate_helper.php';
corporateEnsureSchema($conn);

$corporateId = (int) ($_GET['corporate_id'] ?? 0);
if ($corporateId <= 0) {
    echo '<option value="">-- Pilih Perusahaan dulu --</option>';
    exit;
}

// Pastikan perusahaan ini benar milik tenant yang login.
$ceknamaEsc = mysqli_real_escape_string($conn, $ceknama);
$areaFilterCorp = corporateAreaFilterSql('AREA', $AKSES, $area_list ?? '');
$cek = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM corporate WHERE id = $corporateId AND PEMILIK = '$ceknamaEsc'" . $areaFilterCorp . " LIMIT 1"));
if (!$cek) {
    echo '<option value="">-- Perusahaan tidak ditemukan --</option>';
    exit;
}

echo '<option value="">-- Tanpa Layanan Spesifik (invoice umum/gabungan) --</option>';
$q = mysqli_query($conn, "SELECT id, jenis_layanan, nama_layanan FROM corporate_layanan WHERE corporate_id = $corporateId ORDER BY id DESC");
if ($q) {
    while ($r = mysqli_fetch_assoc($q)) {
        $label = $r['nama_layanan'] !== '' ? $r['nama_layanan'] : $r['jenis_layanan'];
        echo '<option value="' . (int) $r['id'] . '">' . htmlspecialchars($label) . ' (' . htmlspecialchars($r['jenis_layanan']) . ')</option>';
    }
}
exit;
