<?php
require '../cek-sesi.php';
require_once __DIR__ . '/../corporate_helper.php';
corporateEnsureSchema($conn);

function redirectAddKontrak($corporateId, $status, $text = '') {
    $url = "../corporate_kontrak.php?corporate_id=" . (int) $corporateId . "&statusnotif=" . urlencode($status);
    if ($text !== '') {
        $url .= "&text=" . urlencode($text);
    }
    header("Location: " . $url);
    exit;
}

$corporateId = (int) ($_POST['corporate_id'] ?? 0);
if ($corporateId <= 0) {
    redirectAddKontrak(0, 'failed', 'Perusahaan tidak valid');
}

// Wajib milik tenant yang login (+ batas AREA kalau ASSISTANT).
$ceknamaEsc = mysqli_real_escape_string($conn, $ceknama);
$areaFilterCorp = corporateAreaFilterSql('AREA', $AKSES, $area_list ?? '');
$corp = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM corporate WHERE id = $corporateId AND PEMILIK = '$ceknamaEsc'" . $areaFilterCorp . " LIMIT 1"));
if (!$corp) {
    redirectAddKontrak($corporateId, 'failed', 'Customer Corporate tidak ditemukan');
}

$nomorKontrak = trim((string) ($_POST['nomor_kontrak'] ?? ''));
$tanggalMulai = trim((string) ($_POST['tanggal_mulai'] ?? ''));
$tanggalBerakhir = trim((string) ($_POST['tanggal_berakhir'] ?? ''));
$autoReminder = (($_POST['auto_reminder'] ?? '') === '1') ? 1 : 0;
$hariSebelum = max(1, (int) ($_POST['hari_sebelum_reminder'] ?? 30));
$catatan = trim((string) ($_POST['catatan'] ?? ''));

$tanggalMulaiSql = ($tanggalMulai !== '') ? "'" . mysqli_real_escape_string($conn, $tanggalMulai) . "'" : 'NULL';
$tanggalBerakhirSql = ($tanggalBerakhir !== '') ? "'" . mysqli_real_escape_string($conn, $tanggalBerakhir) . "'" : 'NULL';

$filePdfPath = '';
if (isset($_FILES['file_pdf'])) {
    $uploadResult = corporateHandleFileUpload($_FILES['file_pdf'], 'kontrak', (string) $corporateId);
    if (isset($uploadResult['error'])) {
        redirectAddKontrak($corporateId, 'failed', 'Upload PDF gagal: ' . $uploadResult['error']);
    }
    if (isset($uploadResult['relative_path'])) {
        $filePdfPath = $uploadResult['relative_path'];
    }
}

$sql = "INSERT INTO corporate_kontrak (corporate_id, nomor_kontrak, tanggal_mulai, tanggal_berakhir, auto_reminder, hari_sebelum_reminder, file_pdf, status, catatan) VALUES (
    $corporateId,
    '" . mysqli_real_escape_string($conn, $nomorKontrak) . "',
    $tanggalMulaiSql,
    $tanggalBerakhirSql,
    $autoReminder,
    $hariSebelum,
    '" . mysqli_real_escape_string($conn, $filePdfPath) . "',
    'AKTIF',
    '" . mysqli_real_escape_string($conn, $catatan) . "'
)";

if (!mysqli_query($conn, $sql)) {
    if ($filePdfPath !== '') {
        corporateDeleteDokumenFile($filePdfPath);
    }
    redirectAddKontrak($corporateId, 'failed', 'Gagal menyimpan kontrak: ' . mysqli_error($conn));
}

$history_file = "../notifbot/data/history-$ceknama.json";
$history = [];
if (file_exists($history_file)) {
    $history = json_decode(file_get_contents($history_file), true);
}
if (!is_array($history)) { $history = []; }
$history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Berhasil menambahkan kontrak '$nomorKontrak' utk corporate_id=$corporateId";
@file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

redirectAddKontrak($corporateId, 'success');
