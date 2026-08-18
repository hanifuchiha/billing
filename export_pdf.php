<?php
// WAJIB cek-sesi.php (BUKAN cuma koneksidb.php) -- sebelum fix ini, file ini
// bisa diakses TANPA LOGIN SAMA SEKALI dan meng-export SEMUA transaksi dari
// SEMUA tenant/owner tanpa filter apapun (termasuk alamat/no WA/RT/RW
// pelanggan) ke siapapun yang tahu URL-nya.
require 'cek-sesi.php';
require_once 'vendor/autoload.php';

use Dompdf\Dompdf;

// Nama file berbasis tanggal filter (bisa sama persis antar percobaan kalau
// filter lain yg beda, mis. status) -- header ini cegah browser/proxy/CDN
// kirim balik response export LAMA yang di-cache walau filter sudah beda.
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

$html = '<style>
  body { font-family: sans-serif; font-size: 12px; }
  table { border-collapse: collapse; width: 100%; }
  th, td { border: 1px solid #000; padding: 6px; text-align: left; }
  th { background-color: #f2f2f2; }
</style>';

$html .= '<h3>Data Transaksi</h3>';
$periodeLabel = ($start !== '' && $end !== '') ? ($start . ' s/d ' . $end) : 'Semua tanggal (sesuai filter lain yang dipilih)';
$html .= '<p>Periode: ' . htmlspecialchars($periodeLabel) . '</p>';
$html .= '<table><thead><tr>
<th>No</th><th>Tanggal Bayar</th><th>Nama</th><th>ID Pel</th><th>Paket</th><th>Penggunaan</th><th>Harga</th><th>Ref Tripay</th><th>Status</th><th>Alamat</th><th>RT</th><th>RW</th><th>Kelurahan</th><th>Kecamatan</th><th>No WA</th>
</tr></thead><tbody>';

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
  $html .= '<tr>
    <td>' . $no++ . '</td>
    <td>' . htmlspecialchars($data['TANGGALBAYAR']) . '</td>
    <td>' . htmlspecialchars($data['NAMA']) . '</td>
    <td>' . htmlspecialchars($data['IDPEL']) . '</td>
    <td>' . ((!$bisaLihatPaketExport || paketVisibilityIsHidden((string)($data['PAKET'] ?? ''), $assistant_hidden_paket_broadband ?? [])) ? '-' : htmlspecialchars($data['PAKET'] ?? '')) . '</td>
    <td>' . htmlspecialchars($data['PENGUNAAN']) . '</td>
    <td>Rp. ' . number_format($harga, 0, ',', '.') . '</td>
    <td>' . htmlspecialchars($data['BUKTI']) . '</td>
    <td>' . htmlspecialchars($data['STATUS']) . '</td>
    <td>' . htmlspecialchars($data['PEL_ALAMAT'] ?? '') . '</td>
    <td>' . htmlspecialchars($data['PEL_RT'] ?? '') . '</td>
    <td>' . htmlspecialchars($data['PEL_RW'] ?? '') . '</td>
    <td>' . htmlspecialchars($data['PEL_KELURAHAN'] ?? '') . '</td>
    <td>' . htmlspecialchars($data['PEL_KECAMATAN'] ?? '') . '</td>
    <td>' . htmlspecialchars($data['PEL_NOWA'] ?? '') . '</td>
    </tr>';
}
$html .= '</tbody></table><br>';

// Pajak
$ppn = ($jenis == 'pt' || $jenis == 'pkppribadi') ? $total * 0.11 : 0;
$pph25 = ($jenis == 'pt') ? $total * 0.025 : 0;
$pphFinal = ($jenis == 'umkm' && $jenis != 'pt') ? $total * 0.005 : 0; // validasi tambahan untuk cegah PT
$pphBadan = ($jenis == 'pt') ? $total * 0.22 : 0;
$totalPajak = $ppn + $pph25 + $pphFinal;
$grand = $total + $totalPajak;

$html .= '<table style="width: 60%;" border="1" cellpadding="5">
<tr><th>Total Harga (sebelum pajak)</th><td>Rp. ' . number_format($total, 0, ',', '.') . '</td></tr>';
if ($ppn > 0) $html .= '<tr><th>PPN 11%</th><td>Rp. ' . number_format($ppn, 0, ',', '.') . ' <small>(harus disetor jika Anda PKP)</small></td></tr>';
if ($pph25 > 0) $html .= '<tr><th>PPh 25 (2.5%)</th><td>Rp. ' . number_format($pph25, 0, ',', '.') . ' <small>(harus dibayar bulanan sebagai angsuran pajak badan)</small></td></tr>';
if ($pphFinal > 0) $html .= '<tr><th>PPh Final UMKM (0.5%)</th><td>Rp. ' . number_format($pphFinal, 0, ',', '.') . ' <small>(harus dibayar sebagai pajak final atas omzet)</small></td></tr>';
if ($pphBadan > 0) $html .= '<tr><th>Simulasi PPh Badan (22%)</th><td>Rp. ' . number_format($pphBadan, 0, ',', '.') . ' <small>(hanya simulasi, dilaporkan dalam SPT Tahunan)</small></td></tr>';
$html .= '<tr><th><strong>Total Pajak Dibayar</strong></th><td><strong>Rp. ' . number_format($totalPajak, 0, ',', '.') . '</strong></td></tr>';
$html .= '<tr><th>Grand Total</th><td><strong>Rp. ' . number_format($grand, 0, ',', '.') . '</strong></td></tr>';
$html .= '</table><br>';

$html .= '<p><strong>Keterangan Pajak:</strong></p>
<ul>
  <li><strong>Total Harga</strong> adalah jumlah transaksi kotor dari seluruh data yang ditampilkan.</li>
  <li><strong>PPN 11%</strong>: Pajak Pertambahan Nilai atas jasa/produk yang Anda jual. Jika Anda PKP, ini <strong>wajib dipungut dan disetorkan</strong>.</li>
  <li><strong>PPh 25 (2.5%)</strong>: Angsuran bulanan pajak penghasilan bagi <strong>PT</strong>. <strong>Wajib dibayar tiap bulan</strong> ke kas negara. <strong>Pembayaran bisa dilakukan oleh direktur</strong> menggunakan dana pribadi <em>asal disetor atas nama dan NPWP PT</em>.</li>
  <li><strong>PPh Final UMKM (0.5%)</strong>: Jika Anda UMKM perorangan non-PKP dengan omzet ≤ 4.8 M, maka Anda <strong>cukup membayar 0.5%</strong> dari omzet bulanan. <strong>Tidak berlaku untuk PT (termasuk PT Perorangan)</strong>, meskipun belum PKP dan omzet masih di bawah 4,8 M.</li>
  <li><strong>PPh Badan 22%</strong>: Hanya simulasi, dihitung dari laba bersih setahun, dilaporkan saat SPT Tahunan oleh PT.</li>
  <li><strong>Total Pajak Dibayar</strong>: Merupakan jumlah seluruh komponen pajak (PPN, PPh 25, atau PPh Final UMKM) yang harus disetorkan oleh WP sesuai jenisnya.</li>
  <li><strong>Grand Total</strong>: Merupakan total seluruh komponen yang dibebankan (termasuk pajak).</li>
  <li>Dasar hukum: UU PPh No. 36 Tahun 2008, PP 55/2022, dan info resmi di <a href="https://pajak.go.id" target="_blank">pajak.go.id</a></li>
</ul>';

$dompdf = new Dompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();
$filename = 'transaksi_' . ($start !== '' ? $start : 'semua') . '_' . ($end !== '' ? $end : 'tanggal') . '.pdf';
$dompdf->stream($filename, ["Attachment" => false]);
exit;
