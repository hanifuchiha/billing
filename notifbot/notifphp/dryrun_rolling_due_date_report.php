<?php
/**
 * dryrun_rolling_due_date_report.php
 * =====================================================================
 * Laporan PERBANDINGAN (read-only, TIDAK menyentuh Mikrotik/isolir/DB tulis
 * apa pun) antara logika Rolling Due Date LAMA (bug: bayar cepat ikut
 * memajukan jatuh tempo berikutnya, DAN siklus +1 bulan kalender bukan 30
 * hari persis) vs logika BARU yang benar (bayar cepat tidak mengubah jadwal,
 * bayar telat baru menggeser jadwal, siklus PERSIS 30 hari kalender) --
 * lihat tagihanComputeRollingReferenceDate() di tagihan_status_lib.php.
 *
 * Tujuan: melihat dampak perbaikan Rolling Due Date terhadap data pelanggan
 * SUNGGUHAN sebelum logika baru dipakai oleh cek_tagihan_harian_*.php (yang
 * benar-benar mengisolir pelanggan).
 *
 * CARA PAKAI:
 *   CLI : php dryrun_rolling_due_date_report.php --pemilik=FIBERQ
 *   Web : dryrun_rolling_due_date_report.php?pemilik=FIBERQ
 *
 * Script ini TIDAK melakukan perubahan apa pun -- aman dijalankan kapan saja.
 * =====================================================================
 */

include '../../koneksidb.php';
require_once __DIR__ . '/tagihan_status_lib.php';

$isCliOutput = (PHP_SAPI === 'cli');
if (!$isCliOutput) {
    header('Content-Type: text/plain; charset=utf-8');
}

$pemilik = '';
foreach (($argv ?? []) as $arg) {
    if (strpos((string)$arg, '--pemilik=') === 0) {
        $pemilik = trim((string)substr((string)$arg, 10));
        break;
    }
}
if ($pemilik === '' && isset($_GET['pemilik'])) {
    $pemilik = trim((string)$_GET['pemilik']);
}
if ($pemilik === '') {
    echo "ERROR: Parameter --pemilik=NAMA (CLI) atau ?pemilik=NAMA (web) wajib diisi.\n";
    exit(1);
}

echo "=== DRY-RUN: Dampak Perbaikan Rolling Due Date ===\n";
echo "Pemilik : $pemilik\n";
echo "Waktu   : " . date('Y-m-d H:i:s') . "\n";
echo "Catatan : Script ini TIDAK menulis apa pun ke database atau Mikrotik.\n\n";

$hari_ini = date('Y-m-d');

// -- Paket harga & fasum (exclude paket gratis, sama seperti cron asli) -----
$hargaPaketMap = [];
$fasumPaketList = [];
$promoPaketIds = [];
$qPaketMap = mysqli_query($conn, "SELECT id, PAKET, HARGA, BRAND, AREA FROM paket");
while ($qPaketMap && ($r = mysqli_fetch_assoc($qPaketMap))) {
    $paketKey = strtolower(trim((string)($r['PAKET'] ?? '')));
    $brandKey = strtolower(trim((string)($r['BRAND'] ?? '')));
    $areaKey  = strtolower(trim((string)($r['AREA'] ?? '')));
    $hargaPaketMap[$paketKey . '|' . $brandKey . '|' . $areaKey] = $r['HARGA'];
    if ($paketKey !== '' && ($r['HARGA'] === '' || (float)$r['HARGA'] <= 0)) {
        $fasumPaketList[$paketKey] = (string)($r['id'] ?? '');
    }
}
$qPromo = mysqli_query($conn, "SELECT paket_id FROM promo_paket");
while ($qPromo && ($r = mysqli_fetch_assoc($qPromo))) {
    $promoPaketIds[] = (string)($r['paket_id'] ?? '');
}

function resolveHargaPaketDry(array $map, string $paket, string $brand, string $area) {
    foreach ([$paket . '|' . $brand . '|' . $area, $paket . '||' . $area, $paket . '|' . $brand . '|', $paket . '||', $paket] as $k) {
        if (isset($map[$k])) return $map[$k];
    }
    return null;
}
function isFasumNonPromoDry(string $paket, array $fasumList, array $promoIds): bool {
    if ($paket === '' || !isset($fasumList[$paket])) return false;
    return !in_array((string)$fasumList[$paket], $promoIds, true);
}

// -- Ambil semua pelanggan mode Rolling milik pemilik ini (semua area) ------
$pemilikEsc = mysqli_real_escape_string($conn, $pemilik);
$sql = "SELECT IDPEL, NAMA, PAKET, BRAND, AREA, TANGGALPASANG, TIPE_BAYAR
        FROM pelanggan
        WHERE PEMILIK = '$pemilikEsc' AND LOWER(TRIM(TIPE_TEMPO)) = 'mengikuti_tanggal_bayar'";
$res = mysqli_query($conn, $sql);
if (!$res) {
    echo "ERROR SQL: " . mysqli_error($conn) . "\n";
    exit(1);
}

$totalChecked = 0;
$totalDueDateDiffers = 0;
$totalStatusFlips = 0;
$rows = [];

while ($pel = mysqli_fetch_assoc($res)) {
    $idpel = (string)$pel['IDPEL'];
    $tanggalPasang = (string)($pel['TANGGALPASANG'] ?? '');
    if ($tanggalPasang === '' || strtotime($tanggalPasang) === false) {
        continue;
    }

    $paketKey = strtolower(trim((string)($pel['PAKET'] ?? '')));
    $brandKey = strtolower(trim((string)($pel['BRAND'] ?? '')));
    $areaKey  = strtolower(trim((string)($pel['AREA'] ?? '')));
    if (isFasumNonPromoDry($paketKey, $fasumPaketList, $promoPaketIds)) {
        continue;
    }
    $harga = resolveHargaPaketDry($hargaPaketMap, $paketKey, $brandKey, $areaKey);
    if ($harga === null || (float)$harga <= 0) {
        continue;
    }

    $totalChecked++;

    // Ambil tanggal bayar BERHASIL paling akhir (utk formula LAMA).
    $idpelEsc = mysqli_real_escape_string($conn, $idpel);
    $trxDateExpr = tagihanBuildTrxDateExpr();
    $qLast = mysqli_query($conn, "SELECT $trxDateExpr AS last_paid FROM transaksi WHERE IDPEL = '$idpelEsc' AND STATUS = 'BERHASIL' ORDER BY $trxDateExpr DESC LIMIT 1");
    $lastPaid = null;
    if ($qLast && ($rl = mysqli_fetch_assoc($qLast))) {
        $lastPaid = $rl['last_paid'];
    }

    // ---- Formula LAMA (bug): referenceDate = pembayaran TERAKHIR saja ----
    $referenceDateOld = $lastPaid ? substr((string)$lastPaid, 0, 10) : $tanggalPasang;
    $firstDueOld = date('Y-m-d', strtotime('+1 month', strtotime($referenceDateOld)));
    $bulanTunggakOld = (strtotime($firstDueOld) <= strtotime($hari_ini))
        ? tagihanCountConsecutiveMissedMonths($conn, $idpel, $firstDueOld, $hari_ini, false, 0)
        : 0;
    $statusOld = $bulanTunggakOld >= 1 ? 'BELUM BAYAR' : 'SUDAH BAYAR';

    // ---- Formula BARU (benar): simulasi seluruh histori bayar, siklus PERSIS
    // 30 hari kalender (bukan +1 bulan) ----
    $referenceDateNew = tagihanComputeRollingReferenceDate($conn, $idpel, $tanggalPasang);
    $firstDueNew = date('Y-m-d', strtotime('+30 days', strtotime($referenceDateNew)));
    $bulanTunggakNew = (strtotime($firstDueNew) <= strtotime($hari_ini))
        ? tagihanCountConsecutiveMissedMonths($conn, $idpel, $firstDueNew, $hari_ini, false, 0)
        : 0;
    $statusNew = $bulanTunggakNew >= 1 ? 'BELUM BAYAR' : 'SUDAH BAYAR';

    $dueDiffers = ($firstDueOld !== $firstDueNew);
    $statusFlips = ($statusOld !== $statusNew);
    if ($dueDiffers) $totalDueDateDiffers++;
    if ($statusFlips) $totalStatusFlips++;

    if ($dueDiffers || $statusFlips) {
        $deltaDays = (int)round((strtotime($firstDueNew) - strtotime($firstDueOld)) / 86400);
        $rows[] = [
            $idpel,
            (string)($pel['NAMA'] ?? ''),
            $tanggalPasang,
            $lastPaid ? substr((string)$lastPaid, 0, 10) : '-',
            $firstDueOld,
            $firstDueNew,
            $deltaDays,
            $statusOld,
            $statusNew,
            $statusFlips ? 'YA' : '-',
        ];
    }
}

echo "Total pelanggan Rolling dicek (bukan fasum/gratis): $totalChecked\n";
echo "Jatuh tempo berikutnya BERUBAH  : $totalDueDateDiffers\n";
echo "Status sudah/belum bayar BERUBAH: $totalStatusFlips\n\n";

if (empty($rows)) {
    echo "Tidak ada perbedaan sama sekali antara logika lama dan baru untuk pemilik ini.\n";
    exit(0);
}

printf("%-14s %-20s %-12s %-12s %-12s %-12s %8s %-12s %-12s %-6s\n",
    'IDPEL', 'NAMA', 'PASANG', 'BAYAR_AKHIR', 'DUE_LAMA', 'DUE_BARU', 'DELTA_HR', 'STATUS_LAMA', 'STATUS_BARU', 'FLIP');
foreach ($rows as $r) {
    printf("%-14s %-20s %-12s %-12s %-12s %-12s %8d %-12s %-12s %-6s\n", ...$r);
}
