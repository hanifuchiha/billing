<?php
require __DIR__ . '/cek_sesi.php';

$picList = [];
$qPic = mysqli_query($conn, "SELECT * FROM corporate_pic WHERE corporate_id = $corporateId ORDER BY id ASC");
if ($qPic) {
    while ($p = mysqli_fetch_assoc($qPic)) {
        $picList[] = $p;
    }
}

// Layanan -- SENGAJA tidak tampilkan IP/PASSWORD router atau detail VLAN/OLT
// mentah (informasi internal jaringan), cukup status & paket/bandwidth yang
// relevan buat pelanggan.
$layananList = [];
$qLayanan = mysqli_query($conn, "SELECT cl.*, p.PAKET, p.KECEPATAN FROM corporate_layanan cl LEFT JOIN paket p ON p.id = cl.paket_id WHERE cl.corporate_id = $corporateId ORDER BY cl.id DESC");
if ($qLayanan) {
    while ($l = mysqli_fetch_assoc($qLayanan)) {
        $layananList[] = $l;
    }
}

$invoiceList = [];
$totalOutstanding = 0.0;
$qInvoice = mysqli_query($conn, "SELECT tc.*, cl.nama_layanan, cl.jenis_layanan FROM transaksi_corporate tc LEFT JOIN corporate_layanan cl ON cl.id = tc.corporate_layanan_id WHERE tc.corporate_id = $corporateId ORDER BY tc.id DESC");
$todayTs = strtotime(date('Y-m-d'));
if ($qInvoice) {
    while ($t = mysqli_fetch_assoc($qInvoice)) {
        $dibayar = corporateTotalDibayar($conn, (int) $t['id']);
        $sisa = (float) $t['jumlah'] - $dibayar;
        $t['_dibayar'] = $dibayar;
        $t['_sisa'] = max(0, $sisa);
        $t['_overdue'] = false;
        if ($t['status'] !== 'LUNAS' && !empty($t['tanggal_jatuh_tempo'])) {
            $jt = strtotime((string) $t['tanggal_jatuh_tempo']);
            if ($jt !== false && $todayTs > $jt) {
                $t['_overdue'] = true;
            }
        }
        if ($t['status'] !== 'LUNAS') {
            $totalOutstanding += $t['_sisa'];
        }
        $invoiceList[] = $t;
    }
}

$logoUrl = corporateDokumenUrl((string) ($corp['LOGO'] ?? ''));
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Portal Corporate -- <?php echo htmlspecialchars($corp['NAMA_PERUSAHAAN']); ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body { background: #f1f5f9; font-family: 'Segoe UI', Arial, sans-serif; }
.navbar-brand img { max-height: 32px; margin-right: 8px; }
.card { border: none; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,.06); margin-bottom: 20px; }
.stat-card .h4 { margin-bottom: 0; }
</style>
</head>
<body>

<nav class="navbar navbar-dark bg-dark px-3 mb-4">
  <span class="navbar-brand mb-0">
    <?php if ($logoUrl !== ''): ?><img src="<?php echo htmlspecialchars($logoUrl); ?>" alt="logo"><?php endif; ?>
    Portal Corporate -- <?php echo htmlspecialchars($corp['NAMA_PERUSAHAAN']); ?>
  </span>
  <a href="logout.php" class="btn btn-outline-light btn-sm">Keluar</a>
</nav>

<div class="container pb-5">

  <div class="row">
    <div class="col-md-4">
      <div class="card stat-card">
        <div class="card-body">
          <div class="text-muted small">Total Tagihan Belum Lunas</div>
          <div class="h4 text-danger">Rp <?php echo number_format($totalOutstanding, 0, ',', '.'); ?></div>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card stat-card">
        <div class="card-body">
          <div class="text-muted small">Jumlah Layanan Aktif</div>
          <div class="h4"><?php echo count(array_filter($layananList, function ($l) { return $l['status'] === 'AKTIF'; })); ?></div>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card stat-card">
        <div class="card-body">
          <div class="text-muted small">Status Perusahaan</div>
          <div class="h4"><span class="badge bg-success">AKTIF</span></div>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-body">
      <h6 class="text-uppercase text-xs text-muted">Data Perusahaan</h6>
      <div class="row">
        <div class="col-md-6">
          <table class="table table-borderless table-sm mb-0">
            <tr><td class="text-muted" width="45%">Nama Perusahaan</td><td><b><?php echo htmlspecialchars($corp['NAMA_PERUSAHAAN']); ?></b></td></tr>
            <tr><td class="text-muted">Penanggung Jawab</td><td><?php echo htmlspecialchars($corp['PJ_NAMA']) . ($corp['PJ_JABATAN'] ? ' (' . htmlspecialchars($corp['PJ_JABATAN']) . ')' : ''); ?></td></tr>
            <tr><td class="text-muted">NPWP</td><td><?php echo htmlspecialchars($corp['NPWP'] ?: '-'); ?></td></tr>
            <tr><td class="text-muted">NIB</td><td><?php echo htmlspecialchars($corp['NIB'] ?: '-'); ?></td></tr>
            <tr><td class="text-muted">Nomor SIUP</td><td><?php echo htmlspecialchars($corp['SIUP'] ?: '-'); ?></td></tr>
          </table>
        </div>
        <div class="col-md-6">
          <table class="table table-borderless table-sm mb-0">
            <tr><td class="text-muted" width="45%">Alamat Kantor</td><td><?php echo nl2br(htmlspecialchars($corp['ALAMAT_KANTOR'])); ?></td></tr>
            <tr><td class="text-muted">Email Finance</td><td><?php echo htmlspecialchars($corp['EMAIL_FINANCE'] ?: '-'); ?></td></tr>
            <tr><td class="text-muted">Email IT</td><td><?php echo htmlspecialchars($corp['EMAIL_IT'] ?: '-'); ?></td></tr>
            <tr><td class="text-muted">Telepon</td><td><?php echo htmlspecialchars($corp['TELEPON'] ?: '-'); ?></td></tr>
            <tr><td class="text-muted">WhatsApp</td><td><?php echo htmlspecialchars($corp['WHATSAPP'] ?: '-'); ?></td></tr>
          </table>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-body">
      <h6 class="text-uppercase text-xs text-muted">PIC (Person In Charge)</h6>
      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
          <thead><tr><th>Nama</th><th>Jabatan</th><th>Email</th><th>WhatsApp</th><th>Telepon</th></tr></thead>
          <tbody>
            <?php if (empty($picList)): ?>
              <tr><td colspan="5" class="text-center text-muted">Belum ada data PIC</td></tr>
            <?php else: foreach ($picList as $p): ?>
              <tr>
                <td><?php echo htmlspecialchars($p['nama']); ?></td>
                <td><?php echo htmlspecialchars($p['jabatan']); ?></td>
                <td><?php echo htmlspecialchars($p['email']); ?></td>
                <td><?php echo htmlspecialchars($p['whatsapp']); ?></td>
                <td><?php echo htmlspecialchars($p['telepon']); ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-body">
      <h6 class="text-uppercase text-xs text-muted">Layanan</h6>
      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
          <thead><tr><th>Layanan</th><th>Jenis</th><th>Paket</th><th>IP</th><th>Status Koneksi</th><th>Status</th><th>Tanggal Aktif</th></tr></thead>
          <tbody>
            <?php if (empty($layananList)): ?>
              <tr><td colspan="7" class="text-center text-muted">Belum ada layanan</td></tr>
            <?php else: foreach ($layananList as $l): ?>
              <tr>
                <td><?php echo htmlspecialchars($l['nama_layanan'] ?: '-'); ?></td>
                <td><?php echo htmlspecialchars($l['jenis_layanan']); ?></td>
                <td><?php echo htmlspecialchars($l['PAKET'] ? $l['PAKET'] . ' (' . $l['KECEPATAN'] . ')' : '-'); ?></td>
                <td><?php echo htmlspecialchars($l['ip_address'] ?: '-'); ?></td>
                <td>
                  <?php if ((int) $l['provisioning_aktif'] === 1): ?>
                    <?php echo ($l['status_koneksi'] === 'AKTIF') ? "<span class='badge bg-success'>Aktif</span>" : "<span class='badge bg-danger'>Terputus</span>"; ?>
                  <?php else: ?>
                    <span class="text-muted">-</span>
                  <?php endif; ?>
                </td>
                <td><?php echo ($l['status'] === 'AKTIF') ? "<span class='badge bg-success'>AKTIF</span>" : "<span class='badge bg-secondary'>NONAKTIF</span>"; ?></td>
                <td><?php echo htmlspecialchars((string) $l['tanggal_aktif'] ?: '-'); ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-body">
      <h6 class="text-uppercase text-xs text-muted">Invoice / Tagihan</h6>
      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
          <thead><tr><th>Nomor Invoice</th><th>Layanan</th><th>Termin</th><th>Tgl Invoice</th><th>Jatuh Tempo</th><th>Jumlah</th><th>Sisa</th><th>Status</th><th></th></tr></thead>
          <tbody>
            <?php if (empty($invoiceList)): ?>
              <tr><td colspan="9" class="text-center text-muted">Belum ada invoice</td></tr>
            <?php else: foreach ($invoiceList as $t): ?>
              <?php
              $badgeClass = ['BELUM_BAYAR' => 'bg-danger', 'PARTIAL' => 'bg-warning text-dark', 'LUNAS' => 'bg-success'][$t['status']] ?? 'bg-secondary';
              $statusLabel = ['BELUM_BAYAR' => 'Belum Bayar', 'PARTIAL' => 'Sebagian', 'LUNAS' => 'Lunas'][$t['status']] ?? $t['status'];
              ?>
              <tr>
                <td><?php echo htmlspecialchars($t['nomor_invoice']); ?></td>
                <td><?php echo htmlspecialchars($t['nama_layanan'] ? $t['nama_layanan'] : ($t['jenis_layanan'] ?: '-')); ?></td>
                <td><?php echo htmlspecialchars($t['termin']); ?></td>
                <td><?php echo htmlspecialchars($t['tanggal_invoice']); ?></td>
                <td><?php echo htmlspecialchars($t['tanggal_jatuh_tempo']); ?></td>
                <td>Rp <?php echo number_format((float) $t['jumlah'], 0, ',', '.'); ?></td>
                <td>Rp <?php echo number_format($t['_sisa'], 0, ',', '.'); ?></td>
                <td><span class="badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($statusLabel); ?></span><?php echo $t['_overdue'] ? " <span class='badge bg-dark'>Terlambat</span>" : ''; ?></td>
                <td><a href="../print_invoice_corporate.php?id=<?php echo (int) $t['id']; ?>" target="_blank" class="btn btn-sm btn-outline-primary">Lihat/Cetak</a></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>
</body>
</html>
