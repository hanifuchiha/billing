<?php
require 'cek-sesi.php';

header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=transaksi_export.xls");
// Nama file selalu sama (transaksi_export.xls) -- tanpa header ini, browser/
// proxy/CDN di depan bisa nyimpen cache response lama dan tetap kirim balik
// itu walau query filter (mis. status) sudah beda, hasilnya kelihatan kayak
// "export tidak ikut filter" padahal query di server sudah benar.
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

// Transaction.php mengirim start_date/end_date (bukan start/end) -- terima
// dua-duanya supaya filter tanggal dari UI benar-benar diteruskan.
$start = $_GET['start_date'] ?? ($_GET['start'] ?? '');
$end = $_GET['end_date'] ?? ($_GET['end'] ?? '');
$cekidpel = trim((string)($_GET['idpel'] ?? ''));
$jenis = $_GET['jenis'] ?? 'pt'; // pt, umkm, pkppribadi

// Toggle "btn_trx_lihat_paket" (Pengaturan User > Tombol Individual ASSISTANT,
// halaman Transaction) -- endpoint export ini tidak require header.php, jadi
// $ui_visibility_settings tidak pernah tersedia dan pengecekan diulang manual
// server-side, sama persis pola di getdata/get_daily_transaction.php.
$bisaLihatPaketExport = true;
if ($AKSES === 'ASSISTANT') {
    $exportPaketSettingsUsername = !empty($asistant_name) ? $asistant_name : $ceknama;
    $exportPaketSafeUsername = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$exportPaketSettingsUsername);
    $bisaLihatPaketExport = false; // default ASSISTANT: sembunyikan, kecuali toggle diaktifkan
    if ($exportPaketSafeUsername !== '') {
        $exportPaketSettingsFile = __DIR__ . '/settings/dashboard-cards-' . $exportPaketSafeUsername . '.json';
        if (is_file($exportPaketSettingsFile)) {
            $exportPaketDecoded = json_decode((string)@file_get_contents($exportPaketSettingsFile), true);
            if (is_array($exportPaketDecoded) && array_key_exists('btn_trx_lihat_paket', $exportPaketDecoded)) {
                $bisaLihatPaketExport = (bool)$exportPaketDecoded['btn_trx_lihat_paket'];
            } else {
                $bisaLihatPaketExport = true; // key belum pernah disimpan -> default true
            }
        } else {
            $bisaLihatPaketExport = true; // belum pernah ada file settings -> default true
        }
    }
}

require_once __DIR__ . '/export_transaksi_filter.php';
$exportFilter = exportTransaksiBuildFilter($conn, $AKSES, $area_list ?? '', $current_user_id ?? 0, $_GET);
$sql = "SELECT transaksi.*,
    p.ALAMAT AS PEL_ALAMAT, p.NOWA AS PEL_NOWA, p.rt AS PEL_RT, p.rw AS PEL_RW,
    p.kelurahan AS PEL_KELURAHAN, p.kecamatan AS PEL_KECAMATAN
    FROM transaksi
    {$exportFilter['join']}
    WHERE {$exportFilter['where_sql']}
    ORDER BY transaksi.id DESC";
$query = mysqli_query($conn, $sql);
if (!$query) {
    die('Query error: ' . mysqli_error($conn));
}

$periodeLabel = ($start !== '' && $end !== '') ? ($start . ' s/d ' . $end) : 'Semua tanggal (sesuai filter lain yang dipilih)';
echo "<table border='1'>";
echo "<tr><th colspan='15'>Data Transaksi Periode: " . htmlspecialchars($periodeLabel) . "</th></tr>";
echo "<tr>
<th>No</th><th>Tanggal Bayar</th><th>Nama</th><th>ID Pel</th><th>Paket</th><th>Penggunaan</th><th>Harga</th><th>Ref Tripay</th><th>Status</th><th>Alamat</th><th>RT</th><th>RW</th><th>Kelurahan</th><th>Kecamatan</th><th>No WA</th>
</tr>";

$no = 1;
$total = 0;
while ($data = mysqli_fetch_array($query)) {
    // Reseller/mitra ISP dengan filter harga aktif: harga yang di-export harus
    // ikut harga custom mereka, sama persis pola di Transaction.php ~734-739.
    $harga = (float)$data['HARGA'];
    if (!empty($is_reseller) && !empty($reseller_price_filter_enabled) && !empty($data['PAKET'])) {
        $effectiveHarga = reseller_effective_harga($conn, $data['PAKET'], $data['PEMILIK'] ?? '');
        if ($effectiveHarga > 0) {
            $harga = $effectiveHarga;
        }
    }
    $total += $harga;
    echo "<tr>
        <td>{$no}</td>
        <td>{$data['TANGGALBAYAR']}</td>
        <td>{$data['NAMA']}</td>
        <td>{$data['IDPEL']}</td>
        <td>" . ((!$bisaLihatPaketExport || paketVisibilityIsHidden((string)($data['PAKET'] ?? ''), $assistant_hidden_paket_broadband ?? [])) ? '-' : htmlspecialchars($data['PAKET'] ?? '')) . "</td>
        <td>{$data['PENGUNAAN']}</td>
        <td>{$harga}</td>
        <td>{$data['BUKTI']}</td>
        <td>{$data['STATUS']}</td>
        <td>{$data['PEL_ALAMAT']}</td>
        <td>{$data['PEL_RT']}</td>
        <td>{$data['PEL_RW']}</td>
        <td>{$data['PEL_KELURAHAN']}</td>
        <td>{$data['PEL_KECAMATAN']}</td>
        <td>{$data['PEL_NOWA']}</td>
    </tr>";
    $no++;
}

$ppn = ($jenis == 'pt' || $jenis == 'pkppribadi') ? $total * 0.11 : 0;
$pph25 = ($jenis == 'pt') ? $total * 0.025 : 0;
$pphFinal = ($jenis == 'umkm' && $jenis != 'pt') ? $total * 0.005 : 0;
$pphBadan = ($jenis == 'pt') ? $total * 0.22 : 0;
$totalPajak = $ppn + $pph25 + $pphFinal;
$grand = $total + $totalPajak;

echo "<tr><th colspan='12'>Total Harga</th><td colspan='3'>Rp. " . number_format($total, 0, ',', '.') . "</td></tr>";
if ($ppn > 0) echo "<tr><th colspan='12'>PPN 11%</th><td colspan='3'>Rp. " . number_format($ppn, 0, ',', '.') . "</td></tr>";
if ($pph25 > 0) echo "<tr><th colspan='12'>PPh 25 (2.5%)</th><td colspan='3'>Rp. " . number_format($pph25, 0, ',', '.') . "</td></tr>";
if ($pphFinal > 0) echo "<tr><th colspan='12'>PPh Final UMKM (0.5%)</th><td colspan='3'>Rp. " . number_format($pphFinal, 0, ',', '.') . "</td></tr>";
if ($pphBadan > 0) echo "<tr><th colspan='12'>Simulasi PPh Badan (22%)</th><td colspan='3'>Rp. " . number_format($pphBadan, 0, ',', '.') . "</td></tr>";
echo "<tr><th colspan='12'>Total Pajak Dibayar</th><td colspan='3'><strong>Rp. " . number_format($totalPajak, 0, ',', '.') . "</strong></td></tr>";
echo "<tr><th colspan='12'>Grand Total</th><td colspan='3'><strong>Rp. " . number_format($grand, 0, ',', '.') . "</strong></td></tr>";
echo "</table>";
