<?php
require '../cek-sesi.php';
require_once __DIR__ . '/../corporate_helper.php';
corporateEnsureSchema($conn);

function redirectDeleteCorporate($status, $text = '') {
    $url = "../corporate.php?deleted=" . urlencode($status);
    if ($text !== '') {
        $url .= "&text=" . urlencode($text);
    }
    header("Location: " . $url);
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    redirectDeleteCorporate('0', 'ID tidak valid');
}

$ceknamaEsc = mysqli_real_escape_string($conn, $ceknama);
$areaFilterCorp = corporateAreaFilterSql('AREA', $AKSES, $area_list ?? '');
$row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT LOGO FROM corporate WHERE id = $id AND PEMILIK = '$ceknamaEsc'" . $areaFilterCorp . " LIMIT 1"));
if (!$row) {
    redirectDeleteCorporate('0', 'Customer Corporate tidak ditemukan');
}

// Blokir kalau masih ada invoice yang belum LUNAS -- pola sama
// deletepackagestaticip.php (block if used), demi jaga integritas piutang.
$cekBelumLunas = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM transaksi_corporate WHERE corporate_id = $id AND status != 'LUNAS'"));
if ($cekBelumLunas && (int) $cekBelumLunas['c'] > 0) {
    redirectDeleteCorporate('2');
}

// Hapus file kontrak PDF milik semua baris kontrak perusahaan ini.
$qKontrak = mysqli_query($conn, "SELECT file_pdf FROM corporate_kontrak WHERE corporate_id = $id");
if ($qKontrak) {
    while ($k = mysqli_fetch_assoc($qKontrak)) {
        corporateDeleteDokumenFile((string) ($k['file_pdf'] ?? ''));
    }
}
corporateDeleteDokumenFile((string) ($row['LOGO'] ?? ''));

mysqli_query($conn, "DELETE FROM corporate_pic WHERE corporate_id = $id");
mysqli_query($conn, "DELETE FROM corporate_kontrak WHERE corporate_id = $id");
// Invoice yang sudah LUNAS (satu-satunya yang lolos guard di atas) ikut
// dihapus beserta riwayat pembayarannya -- diarsipkan lewat history log
// di bawah, bukan tabel arsip terpisah (fondasi, sama sikap dgn fitur lain).
$qTrx = mysqli_query($conn, "SELECT id FROM transaksi_corporate WHERE corporate_id = $id");
if ($qTrx) {
    while ($t = mysqli_fetch_assoc($qTrx)) {
        mysqli_query($conn, "DELETE FROM transaksi_corporate_pembayaran WHERE transaksi_corporate_id = " . (int) $t['id']);
    }
}
mysqli_query($conn, "DELETE FROM transaksi_corporate WHERE corporate_id = $id");

$del = mysqli_query($conn, "DELETE FROM corporate WHERE id = $id AND PEMILIK = '$ceknamaEsc'");

$history_file = "../notifbot/data/history-$ceknama.json";
$history = [];
if (file_exists($history_file)) {
    $history = json_decode(file_get_contents($history_file), true);
}
if (!is_array($history)) { $history = []; }

if ($del && mysqli_affected_rows($conn) > 0) {
    $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Berhasil menghapus Customer Corporate id=$id";
    @file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    redirectDeleteCorporate('1');
} else {
    $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Gagal menghapus Customer Corporate id=$id: " . mysqli_error($conn);
    @file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    redirectDeleteCorporate('0', 'Gagal menghapus dari database');
}
