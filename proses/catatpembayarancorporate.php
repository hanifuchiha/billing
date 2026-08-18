<?php
require '../cek-sesi.php';
require_once __DIR__ . '/../corporate_helper.php';
corporateEnsureSchema($conn);

function redirectCatatBayar($status, $text = '') {
    $url = "../transaksicorporate.php?statusnotif=" . urlencode($status);
    if ($text !== '') {
        $url .= "&text=" . urlencode($text);
    }
    header("Location: " . $url);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectCatatBayar('failed', 'Metode tidak valid');
}

$transaksiId = (int) ($_POST['transaksi_corporate_id'] ?? 0);
$jumlahBayarRaw = trim((string) ($_POST['jumlah_bayar'] ?? ''));
$tanggalBayar = trim((string) ($_POST['tanggal_bayar'] ?? date('Y-m-d')));
$metodeBayar = trim((string) ($_POST['metode_bayar'] ?? ''));
$keterangan = trim((string) ($_POST['keterangan'] ?? ''));

if ($transaksiId <= 0 || $jumlahBayarRaw === '' || !is_numeric($jumlahBayarRaw) || (float) $jumlahBayarRaw <= 0) {
    redirectCatatBayar('failed', 'Jumlah bayar tidak valid');
}
if (strtotime($tanggalBayar) === false) {
    $tanggalBayar = date('Y-m-d');
}

// Wajib invoice ini milik perusahaan tenant yang login (+ batas AREA kalau ASSISTANT).
$ceknamaEsc = mysqli_real_escape_string($conn, $ceknama);
$areaFilterCorp = corporateAreaFilterSql('c.AREA', $AKSES, $area_list ?? '');
$trx = mysqli_fetch_assoc(mysqli_query($conn, "SELECT tc.id, tc.nomor_invoice, tc.jumlah FROM transaksi_corporate tc JOIN corporate c ON c.id = tc.corporate_id WHERE tc.id = $transaksiId AND c.PEMILIK = '$ceknamaEsc'" . $areaFilterCorp . " LIMIT 1"));
if (!$trx) {
    redirectCatatBayar('failed', 'Invoice tidak ditemukan');
}

$jumlahBayar = (float) $jumlahBayarRaw;
$dicatatOleh = !empty($asistant_name) ? $asistant_name : $ceknama;

$sql = "INSERT INTO transaksi_corporate_pembayaran (transaksi_corporate_id, jumlah_bayar, tanggal_bayar, metode_bayar, keterangan, dicatat_oleh) VALUES (
    $transaksiId,
    $jumlahBayar,
    '" . mysqli_real_escape_string($conn, $tanggalBayar) . "',
    '" . mysqli_real_escape_string($conn, $metodeBayar) . "',
    '" . mysqli_real_escape_string($conn, $keterangan) . "',
    '" . mysqli_real_escape_string($conn, $dicatatOleh) . "'
)";

if (!mysqli_query($conn, $sql)) {
    redirectCatatBayar('failed', 'Gagal menyimpan pembayaran: ' . mysqli_error($conn));
}

corporateRecalcStatus($conn, $transaksiId);

$history_file = "../notifbot/data/history-$ceknama.json";
$history = [];
if (file_exists($history_file)) {
    $history = json_decode(file_get_contents($history_file), true);
}
if (!is_array($history)) { $history = []; }
$history[] = "[ " . $dicatatOleh . " - " . date('Y-m-d H:i:s') . " ] Berhasil mencatat pembayaran Rp " . number_format($jumlahBayar, 0, ',', '.') . " utk invoice '" . $trx['nomor_invoice'] . "'";
@file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

redirectCatatBayar('paid');
