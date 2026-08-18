<?php
require '../cek-sesi.php';
require_once __DIR__ . '/../corporate_helper.php';
corporateEnsureSchema($conn);

function redirectDeleteTrxCorporate($status, $text = '') {
    $url = "../transaksicorporate.php?deleted=" . urlencode($status);
    if ($text !== '') {
        $url .= "&text=" . urlencode($text);
    }
    header("Location: " . $url);
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    redirectDeleteTrxCorporate('0', 'ID tidak valid');
}

// Wajib invoice ini milik perusahaan tenant yang login (+ batas AREA kalau ASSISTANT).
$ceknamaEsc = mysqli_real_escape_string($conn, $ceknama);
$areaFilterCorp = corporateAreaFilterSql('c.AREA', $AKSES, $area_list ?? '');
$trx = mysqli_fetch_assoc(mysqli_query($conn, "SELECT tc.id, tc.nomor_invoice FROM transaksi_corporate tc JOIN corporate c ON c.id = tc.corporate_id WHERE tc.id = $id AND c.PEMILIK = '$ceknamaEsc'" . $areaFilterCorp . " LIMIT 1"));
if (!$trx) {
    redirectDeleteTrxCorporate('0', 'Invoice tidak ditemukan');
}

// Blokir kalau sudah ada riwayat pembayaran tercatat -- demi jaga integritas
// piutang (kalau butuh koreksi jumlah, sebaiknya buat invoice baru, bukan
// hapus invoice yang sudah ada uang masuk).
$cekBayar = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM transaksi_corporate_pembayaran WHERE transaksi_corporate_id = $id"));
if ($cekBayar && (int) $cekBayar['c'] > 0) {
    redirectDeleteTrxCorporate('2');
}

$del = mysqli_query($conn, "DELETE FROM transaksi_corporate WHERE id = $id");

$history_file = "../notifbot/data/history-$ceknama.json";
$history = [];
if (file_exists($history_file)) {
    $history = json_decode(file_get_contents($history_file), true);
}
if (!is_array($history)) { $history = []; }

if ($del && mysqli_affected_rows($conn) > 0) {
    $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Berhasil menghapus invoice Corporate '" . $trx['nomor_invoice'] . "'";
    @file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    redirectDeleteTrxCorporate('1');
} else {
    redirectDeleteTrxCorporate('0', 'Gagal menghapus dari database');
}
