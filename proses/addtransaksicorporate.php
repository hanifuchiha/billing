<?php
require '../cek-sesi.php';
require_once __DIR__ . '/../corporate_helper.php';
corporateEnsureSchema($conn);

function redirectAddTrxCorporate($status, $text = '') {
    $url = "../transaksicorporate.php?statusnotif=" . urlencode($status);
    if ($text !== '') {
        $url .= "&text=" . urlencode($text);
    }
    header("Location: " . $url);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectAddTrxCorporate('failed', 'Metode tidak valid');
}

$corporateId = (int) ($_POST['corporate_id'] ?? 0);
$corporateLayananId = (int) ($_POST['corporate_layanan_id'] ?? 0);
$deskripsi = trim((string) ($_POST['deskripsi'] ?? ''));
$nomorPo = trim((string) ($_POST['nomor_po'] ?? ''));
$termin = $_POST['termin'] ?? 'NET30';
$jumlahRaw = trim((string) ($_POST['jumlah'] ?? ''));
$pajakPersen = (float) ($_POST['pajak_persen'] ?? 0);
$tanggalInvoice = trim((string) ($_POST['tanggal_invoice'] ?? date('Y-m-d')));
$catatan = trim((string) ($_POST['catatan'] ?? ''));

if ($corporateId <= 0 || $deskripsi === '' || $jumlahRaw === '' || !is_numeric($jumlahRaw)) {
    redirectAddTrxCorporate('failed', 'Perusahaan, deskripsi, dan jumlah tagihan wajib diisi dengan benar');
}
if (!in_array($termin, ['CASH', 'NET7', 'NET14', 'NET30', 'NET60'], true)) {
    $termin = 'NET30';
}
if ($pajakPersen < 0 || $pajakPersen > 100) {
    $pajakPersen = 0;
}
if (strtotime($tanggalInvoice) === false) {
    $tanggalInvoice = date('Y-m-d');
}

// Wajib perusahaan ini milik tenant yang login (+ batas AREA kalau ASSISTANT).
$ceknamaEsc = mysqli_real_escape_string($conn, $ceknama);
$areaFilterCorp = corporateAreaFilterSql('AREA', $AKSES, $area_list ?? '');
$corp = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM corporate WHERE id = $corporateId AND PEMILIK = '$ceknamaEsc'" . $areaFilterCorp . " LIMIT 1"));
if (!$corp) {
    redirectAddTrxCorporate('failed', 'Customer Corporate tidak ditemukan');
}

// Layanan Terkait opsional -- kalau diisi, wajib benar milik perusahaan yang
// sama (jangan sampai invoice ke-link ke layanan perusahaan lain).
$corporateLayananIdSql = 'NULL';
if ($corporateLayananId > 0) {
    $cekLayanan = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM corporate_layanan WHERE id = $corporateLayananId AND corporate_id = $corporateId LIMIT 1"));
    if (!$cekLayanan) {
        redirectAddTrxCorporate('failed', 'Layanan Terkait tidak ditemukan untuk perusahaan ini');
    }
    $corporateLayananIdSql = $corporateLayananId;
}

$jumlah = (float) $jumlahRaw;
$hariTermin = corporateTerminToDays($termin);
$tanggalJatuhTempo = date('Y-m-d', strtotime($tanggalInvoice . " +$hariTermin days"));
$nomorInvoice = corporateGenerateInvoiceNumber($corporateId);
$dibuatOleh = !empty($asistant_name) ? $asistant_name : $ceknama;

$sql = "INSERT INTO transaksi_corporate (corporate_id, corporate_layanan_id, PEMILIK, nomor_invoice, deskripsi, nomor_po, jumlah, pajak_persen, termin, tanggal_invoice, tanggal_jatuh_tempo, status, catatan, dibuat_oleh) VALUES (
    $corporateId,
    $corporateLayananIdSql,
    '$ceknamaEsc',
    '" . mysqli_real_escape_string($conn, $nomorInvoice) . "',
    '" . mysqli_real_escape_string($conn, $deskripsi) . "',
    '" . mysqli_real_escape_string($conn, $nomorPo) . "',
    $jumlah,
    $pajakPersen,
    '" . mysqli_real_escape_string($conn, $termin) . "',
    '" . mysqli_real_escape_string($conn, $tanggalInvoice) . "',
    '" . mysqli_real_escape_string($conn, $tanggalJatuhTempo) . "',
    'BELUM_BAYAR',
    '" . mysqli_real_escape_string($conn, $catatan) . "',
    '" . mysqli_real_escape_string($conn, $dibuatOleh) . "'
)";

if (!mysqli_query($conn, $sql)) {
    redirectAddTrxCorporate('failed', 'Gagal menyimpan invoice: ' . mysqli_error($conn));
}

$history_file = "../notifbot/data/history-$ceknama.json";
$history = [];
if (file_exists($history_file)) {
    $history = json_decode(file_get_contents($history_file), true);
}
if (!is_array($history)) { $history = []; }
$history[] = "[ " . $dibuatOleh . " - " . date('Y-m-d H:i:s') . " ] Berhasil membuat invoice Corporate '$nomorInvoice' (Rp " . number_format($jumlah, 0, ',', '.') . ")";
@file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

redirectAddTrxCorporate('success');
