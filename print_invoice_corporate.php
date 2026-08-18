<?php
// Halaman ini publik (tanpa login) -- pola sama print_struk.php, supaya link
// invoice bisa dikirim langsung ke PIC finance perusahaan tanpa perlu akun CRM.
require 'koneksidb.php';
require_once __DIR__ . '/corporate_helper.php';
corporateEnsureSchema($conn);

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    die("ID tidak ditemukan");
}

$trx = mysqli_fetch_assoc(mysqli_query($conn, "SELECT tc.*, c.NAMA_PERUSAHAAN, c.PJ_NAMA, c.PJ_JABATAN, c.ALAMAT_KANTOR, c.EMAIL_FINANCE, c.TELEPON, c.WHATSAPP, c.LOGO, cl.nama_layanan, cl.jenis_layanan FROM transaksi_corporate tc JOIN corporate c ON c.id = tc.corporate_id LEFT JOIN corporate_layanan cl ON cl.id = tc.corporate_layanan_id WHERE tc.id = $id LIMIT 1"));
if (!$trx) {
    die("Invoice tidak ditemukan");
}

$dibayar = corporateTotalDibayar($conn, $id);
$jumlah = (float) $trx['jumlah'];
$pajakNominal = $jumlah * ((float) $trx['pajak_persen'] / 100);
$totalDenganPajak = $jumlah + $pajakNominal;
$sisa = max(0, $totalDenganPajak - $dibayar);

$riwayatBayar = [];
$qBayar = mysqli_query($conn, "SELECT * FROM transaksi_corporate_pembayaran WHERE transaksi_corporate_id = $id ORDER BY tanggal_bayar ASC, id ASC");
if ($qBayar) {
    while ($b = mysqli_fetch_assoc($qBayar)) {
        $riwayatBayar[] = $b;
    }
}

$logoUrl = corporateDokumenUrl((string) ($trx['LOGO'] ?? ''));
$statusBadge = ['BELUM_BAYAR' => 'Belum Bayar', 'PARTIAL' => 'Sebagian Terbayar', 'LUNAS' => 'LUNAS'][$trx['status']] ?? $trx['status'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Invoice <?php echo htmlspecialchars($trx['nomor_invoice']); ?></title>
<style>
html, body { background: #eef1f5; font-family: Arial, sans-serif; color: #1f2937; }
.print-toolbar { max-width: 720px; margin: 16px auto 0; text-align: center; }
.print-toolbar button {
    font-family: inherit; font-size: 13px; padding: 8px 16px; border: none;
    border-radius: 6px; background: #2563eb; color: #fff; cursor: pointer;
}
.print-toolbar button:hover { background: #1d4ed8; }
.invoice-wrap { display: flex; justify-content: center; padding: 16px; }
.invoice-paper { background: #fff; box-shadow: 0 2px 10px rgba(0,0,0,.15); width: 720px; padding: 32px; }
.invoice-header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #1f2937; padding-bottom: 12px; margin-bottom: 16px; }
.invoice-header img { max-width: 80px; max-height: 80px; object-fit: contain; }
.invoice-title { font-size: 22px; font-weight: bold; text-align: right; }
table.info-table { width: 100%; margin-bottom: 16px; font-size: 13px; }
table.info-table td { padding: 2px 4px; vertical-align: top; }
table.item-table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
table.item-table th, table.item-table td { border: 1px solid #d1d5db; padding: 8px; font-size: 13px; text-align: left; }
table.item-table th { background: #f3f4f6; }
.text-right { text-align: right; }
.summary-table { width: 320px; margin-left: auto; font-size: 13px; }
.summary-table td { padding: 4px; }
.badge-status { display: inline-block; padding: 4px 10px; border-radius: 4px; font-weight: bold; font-size: 12px; }
.badge-lunas { background: #dcfce7; color: #166534; }
.badge-partial { background: #fef9c3; color: #854d0e; }
.badge-belum { background: #fee2e2; color: #991b1b; }
@media print {
    html, body { background: #fff; }
    .print-toolbar { display: none; }
    .invoice-wrap { padding: 0; }
    .invoice-paper { box-shadow: none; width: auto; }
}
</style>
</head>
<body>
<div class="print-toolbar">
    <button type="button" onclick="window.print()">🖨️ Cetak Invoice</button>
</div>
<div class="invoice-wrap">
  <div class="invoice-paper">
    <div class="invoice-header">
      <div>
        <?php if ($logoUrl !== ''): ?><img src="<?php echo htmlspecialchars($logoUrl); ?>"><?php endif; ?>
        <div><b><?php echo htmlspecialchars($trx['NAMA_PERUSAHAAN']); ?></b></div>
      </div>
      <div class="invoice-title">INVOICE<br>
        <span style="font-size:14px;font-weight:normal;"><?php echo htmlspecialchars($trx['nomor_invoice']); ?></span>
      </div>
    </div>

    <table class="info-table">
      <tr>
        <td width="50%">
          <b>Ditagihkan kepada:</b><br>
          <?php echo htmlspecialchars($trx['NAMA_PERUSAHAAN']); ?><br>
          <?php echo nl2br(htmlspecialchars((string) $trx['ALAMAT_KANTOR'])); ?><br>
          <?php if ($trx['PJ_NAMA']): ?>PIC: <?php echo htmlspecialchars($trx['PJ_NAMA']) . ' (' . htmlspecialchars($trx['PJ_JABATAN']) . ')'; ?><br><?php endif; ?>
          <?php if ($trx['EMAIL_FINANCE']): ?><?php echo htmlspecialchars($trx['EMAIL_FINANCE']); ?><br><?php endif; ?>
        </td>
        <td width="50%">
          <?php if (!empty($trx['jenis_layanan'])): ?>
          <b>Layanan:</b> <?php echo htmlspecialchars($trx['nama_layanan'] ?: $trx['jenis_layanan']) . ' (' . htmlspecialchars($trx['jenis_layanan']) . ')'; ?><br>
          <?php endif; ?>
          <b>Nomor PO:</b> <?php echo htmlspecialchars($trx['nomor_po'] ?: '-'); ?><br>
          <b>Tanggal Invoice:</b> <?php echo htmlspecialchars($trx['tanggal_invoice']); ?><br>
          <b>Jatuh Tempo:</b> <?php echo htmlspecialchars($trx['tanggal_jatuh_tempo']); ?><br>
          <b>Termin:</b> <?php echo htmlspecialchars($trx['termin']); ?><br>
          <b>Status:</b>
          <span class="badge-status <?php echo $trx['status'] === 'LUNAS' ? 'badge-lunas' : ($trx['status'] === 'PARTIAL' ? 'badge-partial' : 'badge-belum'); ?>">
            <?php echo htmlspecialchars($statusBadge); ?>
          </span>
        </td>
      </tr>
    </table>

    <table class="item-table">
      <thead>
        <tr><th>Deskripsi</th><th class="text-right" width="150">Jumlah</th></tr>
      </thead>
      <tbody>
        <tr>
          <td><?php echo nl2br(htmlspecialchars((string) $trx['deskripsi'])); ?></td>
          <td class="text-right">Rp <?php echo number_format($jumlah, 0, ',', '.'); ?></td>
        </tr>
      </tbody>
    </table>

    <table class="summary-table">
      <tr><td>Subtotal</td><td class="text-right">Rp <?php echo number_format($jumlah, 0, ',', '.'); ?></td></tr>
      <?php if ((float) $trx['pajak_persen'] > 0): ?>
      <tr><td>Pajak (<?php echo htmlspecialchars($trx['pajak_persen']); ?>%)</td><td class="text-right">Rp <?php echo number_format($pajakNominal, 0, ',', '.'); ?></td></tr>
      <?php endif; ?>
      <tr style="font-weight:bold;border-top:1px solid #d1d5db;"><td>Total Tagihan</td><td class="text-right">Rp <?php echo number_format($totalDenganPajak, 0, ',', '.'); ?></td></tr>
      <tr><td>Sudah Dibayar</td><td class="text-right">Rp <?php echo number_format($dibayar, 0, ',', '.'); ?></td></tr>
      <tr style="font-weight:bold;"><td>Sisa Tagihan</td><td class="text-right">Rp <?php echo number_format($sisa, 0, ',', '.'); ?></td></tr>
    </table>

    <?php if (!empty($riwayatBayar)): ?>
    <h4 style="margin-top:24px;">Riwayat Pembayaran</h4>
    <table class="item-table">
      <thead><tr><th>Tanggal</th><th>Metode</th><th>Keterangan</th><th class="text-right">Jumlah</th></tr></thead>
      <tbody>
        <?php foreach ($riwayatBayar as $b): ?>
        <tr>
          <td><?php echo htmlspecialchars($b['tanggal_bayar']); ?></td>
          <td><?php echo htmlspecialchars($b['metode_bayar']); ?></td>
          <td><?php echo htmlspecialchars($b['keterangan']); ?></td>
          <td class="text-right">Rp <?php echo number_format((float) $b['jumlah_bayar'], 0, ',', '.'); ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

    <?php if (!empty($trx['catatan'])): ?>
    <p style="margin-top:16px;font-size:13px;"><b>Catatan:</b> <?php echo nl2br(htmlspecialchars($trx['catatan'])); ?></p>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
