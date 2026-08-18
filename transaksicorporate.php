<?php require 'header.php';

if ($AKSES == 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('Corporate_Transaksi', $akses_menu, true)) {
        echo '<div class="container-fluid py-4"><div class="alert alert-danger">Anda tidak memiliki akses ke menu Transaksi Corporate.</div></div>';
        require 'footer.php';
        exit;
    }
}

require_once __DIR__ . '/corporate_helper.php';
corporateEnsureSchema($conn);

$status = $_GET['statusnotif'] ?? '';
$deleted = $_GET['deleted'] ?? '';
$filterCorporateId = (int) ($_GET['corporate_id'] ?? 0);
$filterStatus = trim((string) ($_GET['filter_status'] ?? ''));
$filterBulan = trim((string) ($_GET['filter_bulan'] ?? '')); // format YYYY-MM

$ceknamaEsc = mysqli_real_escape_string($conn, $ceknama);
$areaFilterCorp = corporateAreaFilterSql('AREA', $AKSES, $area_list ?? '');
?>

<?php if ($status === 'success'): ?>
  <div class="alert alert-success alert-dismissible fade show" role="alert">
    <strong>Berhasil!</strong> Invoice berhasil dibuat.
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php elseif ($status === 'failed'): ?>
  <div class="alert alert-danger alert-dismissible fade show" role="alert">
    <strong>Gagal!</strong> <?php echo htmlspecialchars($_GET['text'] ?? 'Terjadi kesalahan.'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php elseif ($status === 'paid'): ?>
  <div class="alert alert-success alert-dismissible fade show" role="alert">
    <strong>Berhasil!</strong> Pembayaran berhasil dicatat.
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>
<?php if ($deleted === '1'): ?>
  <div class="alert alert-success alert-dismissible fade show" role="alert">
    <strong>Berhasil!</strong> Invoice berhasil dihapus.
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php elseif ($deleted === '2'): ?>
  <div class="alert alert-danger alert-dismissible fade show" role="alert">
    <strong>Gagal!</strong> Invoice ini sudah punya riwayat pembayaran, tidak bisa dihapus.
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>

<?php
// Daftar perusahaan (dropdown filter & form Buat Invoice).
$corpList = [];
$qCorpList = mysqli_query($conn, "SELECT id, NAMA_PERUSAHAAN FROM corporate WHERE PEMILIK = '$ceknamaEsc'" . $areaFilterCorp . " ORDER BY NAMA_PERUSAHAAN ASC");
if ($qCorpList) {
    while ($r = mysqli_fetch_assoc($qCorpList)) {
        $corpList[] = $r;
    }
}
$corpIdsAllowed = array_map(static function ($r) { return (int) $r['id']; }, $corpList);

// Ringkasan Outstanding & Aging -- semua invoice milik perusahaan yg diizinkan scope ini.
$totalOutstanding = 0.0;
$aging = ['1-30' => 0.0, '31-60' => 0.0, '61-90' => 0.0, '90+' => 0.0];
if (!empty($corpIdsAllowed)) {
    $idsCsv = implode(',', $corpIdsAllowed);
    $qOutstanding = mysqli_query($conn, "SELECT id, jumlah, tanggal_jatuh_tempo FROM transaksi_corporate WHERE corporate_id IN ($idsCsv) AND status != 'LUNAS'");
    if ($qOutstanding) {
        $todayTs = strtotime(date('Y-m-d'));
        while ($t = mysqli_fetch_assoc($qOutstanding)) {
            $dibayar = corporateTotalDibayar($conn, (int) $t['id']);
            $sisa = (float) $t['jumlah'] - $dibayar;
            if ($sisa <= 0) {
                continue;
            }
            $totalOutstanding += $sisa;
            $jt = $t['tanggal_jatuh_tempo'] ? strtotime((string) $t['tanggal_jatuh_tempo']) : false;
            $daysOverdue = ($jt !== false) ? (int) floor(($todayTs - $jt) / 86400) : 0;
            if ($daysOverdue <= 0) {
                continue; // belum jatuh tempo, tidak masuk aging piutang
            } elseif ($daysOverdue <= 30) {
                $aging['1-30'] += $sisa;
            } elseif ($daysOverdue <= 60) {
                $aging['31-60'] += $sisa;
            } elseif ($daysOverdue <= 90) {
                $aging['61-90'] += $sisa;
            } else {
                $aging['90+'] += $sisa;
            }
        }
    }
}
?>

<div class="container-fluid py-4">
  <div class="row mb-3">
    <div class="col-md-3">
      <div class="card"><div class="card-body">
        <div class="text-muted small">Total Outstanding</div>
        <div class="h5 mb-0">Rp <?php echo number_format($totalOutstanding, 0, ',', '.'); ?></div>
      </div></div>
    </div>
    <div class="col-md-2">
      <div class="card"><div class="card-body">
        <div class="text-muted small">Aging 1-30 hari</div>
        <div class="h6 mb-0">Rp <?php echo number_format($aging['1-30'], 0, ',', '.'); ?></div>
      </div></div>
    </div>
    <div class="col-md-2">
      <div class="card"><div class="card-body">
        <div class="text-muted small">Aging 31-60 hari</div>
        <div class="h6 mb-0">Rp <?php echo number_format($aging['31-60'], 0, ',', '.'); ?></div>
      </div></div>
    </div>
    <div class="col-md-2">
      <div class="card"><div class="card-body">
        <div class="text-muted small">Aging 61-90 hari</div>
        <div class="h6 mb-0">Rp <?php echo number_format($aging['61-90'], 0, ',', '.'); ?></div>
      </div></div>
    </div>
    <div class="col-md-3">
      <div class="card"><div class="card-body">
        <div class="text-muted small">Aging &gt;90 hari</div>
        <div class="h6 mb-0 text-danger">Rp <?php echo number_format($aging['90+'], 0, ',', '.'); ?></div>
      </div></div>
    </div>
  </div>

  <div class="row">
    <div class="col-12">
      <div class="card mb-4">
        <div class="card-header pb-0">
          <h6>Transaksi Corporate</h6>
          <p class="text-muted small mt-2">
            Invoice/billing perusahaan Corporate -- terpisah dari menu Transaction biasa. Kelola
            data perusahaan &amp; PIC di menu <a href="corporate.php">Customer Corporate</a>.
          </p>
          <div class="btn-group-custom mb-3">
            <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addInvoiceModal">
              <i class="fas fa-plus me-1"></i> Buat Invoice
            </button>
          </div>

          <form method="get" class="row g-2 mb-3">
            <div class="col-md-4">
              <select name="corporate_id" class="form-select form-select-sm">
                <option value="0">-- Semua Perusahaan --</option>
                <?php foreach ($corpList as $c): ?>
                  <option value="<?php echo (int) $c['id']; ?>" <?php echo ($filterCorporateId === (int) $c['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['NAMA_PERUSAHAAN']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <select name="filter_status" class="form-select form-select-sm">
                <option value="">-- Semua Status --</option>
                <option value="BELUM_BAYAR" <?php echo ($filterStatus === 'BELUM_BAYAR') ? 'selected' : ''; ?>>Belum Bayar</option>
                <option value="PARTIAL" <?php echo ($filterStatus === 'PARTIAL') ? 'selected' : ''; ?>>Partial</option>
                <option value="LUNAS" <?php echo ($filterStatus === 'LUNAS') ? 'selected' : ''; ?>>Lunas</option>
              </select>
            </div>
            <div class="col-md-3">
              <input type="month" name="filter_bulan" class="form-control form-control-sm" value="<?php echo htmlspecialchars($filterBulan); ?>">
            </div>
            <div class="col-md-2">
              <button type="submit" class="btn btn-sm btn-outline-primary w-100">Filter</button>
            </div>
          </form>
        </div>

        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-bordered table-striped table-hover align-middle">
              <thead class="table-dark">
                <tr>
                  <th>No</th>
                  <th>Nomor Invoice</th>
                  <th>Perusahaan</th>
                  <th>Layanan</th>
                  <th>Deskripsi</th>
                  <th>Termin</th>
                  <th>Tgl Invoice</th>
                  <th>Jatuh Tempo</th>
                  <th>Jumlah</th>
                  <th>Dibayar</th>
                  <th>Sisa</th>
                  <th>Status</th>
                  <th>Aksi</th>
                </tr>
              </thead>
              <tbody>
                <?php
                if (empty($corpIdsAllowed)) {
                    echo "<tr><td colspan='13' class='text-center'>Belum ada Customer Corporate</td></tr>";
                } else {
                    $idsCsv = implode(',', $corpIdsAllowed);
                    $whereParts = ["tc.corporate_id IN ($idsCsv)"];
                    if ($filterCorporateId > 0) {
                        $whereParts[] = "tc.corporate_id = " . $filterCorporateId;
                    }
                    if ($filterStatus !== '') {
                        $whereParts[] = "tc.status = '" . mysqli_real_escape_string($conn, $filterStatus) . "'";
                    }
                    if ($filterBulan !== '' && preg_match('/^\d{4}-\d{2}$/', $filterBulan)) {
                        $whereParts[] = "DATE_FORMAT(tc.tanggal_invoice, '%Y-%m') = '" . mysqli_real_escape_string($conn, $filterBulan) . "'";
                    }
                    $whereSql = implode(' AND ', $whereParts);
                    $qTrx = mysqli_query($conn, "SELECT tc.*, c.NAMA_PERUSAHAAN, cl.nama_layanan, cl.jenis_layanan FROM transaksi_corporate tc JOIN corporate c ON c.id = tc.corporate_id LEFT JOIN corporate_layanan cl ON cl.id = tc.corporate_layanan_id WHERE $whereSql ORDER BY tc.id DESC");
                    if ($qTrx && mysqli_num_rows($qTrx) > 0) {
                        $no = 1;
                        $todayTs = strtotime(date('Y-m-d'));
                        while ($t = mysqli_fetch_assoc($qTrx)) {
                            $dibayar = corporateTotalDibayar($conn, (int) $t['id']);
                            $sisa = (float) $t['jumlah'] - $dibayar;
                            $isOverdue = false;
                            if ($t['status'] !== 'LUNAS' && !empty($t['tanggal_jatuh_tempo'])) {
                                $jt = strtotime((string) $t['tanggal_jatuh_tempo']);
                                if ($jt !== false && $todayTs > $jt) {
                                    $isOverdue = true;
                                }
                            }
                            $layananLabel = $t['nama_layanan'] ? $t['nama_layanan'] : ($t['jenis_layanan'] ?: '-');
                            echo "<tr>";
                            echo "<td>" . $no++ . "</td>";
                            echo "<td>" . htmlspecialchars($t['nomor_invoice']) . "</td>";
                            echo "<td>" . htmlspecialchars($t['NAMA_PERUSAHAAN']) . "</td>";
                            echo "<td>" . htmlspecialchars($layananLabel) . "</td>";
                            echo "<td>" . htmlspecialchars(mb_strimwidth((string) $t['deskripsi'], 0, 60, '...')) . "</td>";
                            echo "<td>" . htmlspecialchars($t['termin']) . "</td>";
                            echo "<td>" . htmlspecialchars($t['tanggal_invoice']) . "</td>";
                            echo "<td>" . htmlspecialchars($t['tanggal_jatuh_tempo']) . "</td>";
                            echo "<td>Rp " . number_format((float) $t['jumlah'], 0, ',', '.') . "</td>";
                            echo "<td>Rp " . number_format($dibayar, 0, ',', '.') . "</td>";
                            echo "<td>Rp " . number_format(max(0, $sisa), 0, ',', '.') . "</td>";
                            $badgeClass = ['BELUM_BAYAR' => 'bg-danger', 'PARTIAL' => 'bg-warning text-dark', 'LUNAS' => 'bg-success'][$t['status']] ?? 'bg-secondary';
                            echo "<td><span class='badge $badgeClass'>" . htmlspecialchars($t['status']) . "</span>" . ($isOverdue ? " <span class='badge bg-dark'>OVERDUE</span>" : '') . "</td>";
                            echo "<td class='text-nowrap'>";
                            if ($t['status'] !== 'LUNAS') {
                                echo "<button type='button' class='btn btn-success btn-sm mb-1' data-bs-toggle='modal' data-bs-target='#payInvoiceModal' data-perm='btn_trxcorp_bayar'"
                                    . " data-id='" . (int) $t['id'] . "' data-sisa='" . max(0, $sisa) . "' data-nomor='" . htmlspecialchars($t['nomor_invoice'], ENT_QUOTES) . "'>Catat Bayar</button><br>";
                            }
                            echo "<a href='print_invoice_corporate.php?id=" . (int) $t['id'] . "' target='_blank' class='btn btn-info btn-sm mb-1' data-perm='btn_trxcorp_cetak'>Cetak</a><br>";
                            echo "<form method='post' action='proses/deletetransaksicorporate.php' data-perm='btn_trxcorp_hapus' style='display:inline' onsubmit='return confirm(\"Yakin ingin menghapus invoice ini?\")'>"
                                . "<input type='hidden' name='id' value='" . (int) $t['id'] . "'>"
                                . "<button type='submit' class='btn btn-danger btn-sm'>Hapus</button></form>";
                            echo "</td>";
                            echo "</tr>";
                        }
                    } else {
                        echo "<tr><td colspan='12' class='text-center'>Belum ada invoice</td></tr>";
                    }
                }
                ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal Buat Invoice -->
<div class="modal fade" id="addInvoiceModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Buat Invoice Corporate</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form method="post" action="proses/addtransaksicorporate.php">
          <div class="mb-3">
            <label class="form-label">Perusahaan</label>
            <select required class="form-select" name="corporate_id" id="addInvoiceCorporate">
              <option value="">-- Pilih Perusahaan --</option>
              <?php foreach ($corpList as $c): ?>
                <option value="<?php echo (int) $c['id']; ?>" <?php echo ($filterCorporateId === (int) $c['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['NAMA_PERUSAHAAN']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Layanan Terkait <small class="text-muted">(opsional -- kosongkan utk invoice gabungan/umum)</small></label>
            <select class="form-select" name="corporate_layanan_id" id="addInvoiceLayanan">
              <option value="">-- Pilih Perusahaan dulu --</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Deskripsi Layanan / Tagihan</label>
            <textarea required class="form-control" name="deskripsi" rows="2" placeholder="Mis. Internet Dedicated 100Mbps - Kantor Pusat, Januari 2026"></textarea>
          </div>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Nomor PO <small class="text-muted">(opsional)</small></label>
              <input type="text" class="form-control" name="nomor_po">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Termin Pembayaran</label>
              <select class="form-select" name="termin">
                <option value="CASH">Cash</option>
                <option value="NET7">Net 7</option>
                <option value="NET14">Net 14</option>
                <option value="NET30" selected>Net 30</option>
                <option value="NET60">Net 60</option>
              </select>
            </div>
          </div>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Jumlah Tagihan (Rp)</label>
              <input required type="text" inputmode="numeric" class="form-control" name="jumlah" oninput="this.value=this.value.replace(/[^0-9]/g,'')">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Pajak (%) <small class="text-muted">(opsional)</small></label>
              <input type="number" step="0.01" min="0" max="100" class="form-control" name="pajak_persen" value="0">
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Tanggal Invoice</label>
            <input required type="date" class="form-control" name="tanggal_invoice" value="<?php echo date('Y-m-d'); ?>">
          </div>
          <div class="mb-3">
            <label class="form-label">Catatan</label>
            <textarea class="form-control" name="catatan" rows="2"></textarea>
          </div>
          <div class="modal-footer px-0 pb-0">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
            <button type="submit" class="btn btn-primary">Simpan Invoice</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Modal Catat Pembayaran -->
<div class="modal fade" id="payInvoiceModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Catat Pembayaran -- <span id="payInvoiceNomor"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form method="post" action="proses/catatpembayarancorporate.php">
          <input type="hidden" name="transaksi_corporate_id" id="payInvoiceId">
          <p class="text-muted">Sisa tagihan saat ini: <b>Rp <span id="payInvoiceSisa">0</span></b></p>
          <div class="mb-3">
            <label class="form-label">Jumlah Bayar (Rp)</label>
            <input required type="text" inputmode="numeric" class="form-control" name="jumlah_bayar" id="payInvoiceJumlah" oninput="this.value=this.value.replace(/[^0-9]/g,'')">
          </div>
          <div class="mb-3">
            <label class="form-label">Tanggal Bayar</label>
            <input required type="date" class="form-control" name="tanggal_bayar" value="<?php echo date('Y-m-d'); ?>">
          </div>
          <div class="mb-3">
            <label class="form-label">Metode Bayar</label>
            <input type="text" class="form-control" name="metode_bayar" placeholder="Transfer/Cash/dst">
          </div>
          <div class="mb-3">
            <label class="form-label">Keterangan <small class="text-muted">(mis. DP 30%, Pelunasan)</small></label>
            <input type="text" class="form-control" name="keterangan">
          </div>
          <div class="modal-footer px-0 pb-0">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
            <button type="submit" class="btn btn-primary">Simpan Pembayaran</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
function loadInvoiceLayananOptions(corporateId) {
    var layananSelect = document.getElementById('addInvoiceLayanan');
    if (!corporateId) {
        layananSelect.innerHTML = '<option value="">-- Pilih Perusahaan dulu --</option>';
        return;
    }
    layananSelect.innerHTML = '<option value="">Loading...</option>';
    var xhr = new XMLHttpRequest();
    xhr.open('GET', 'getdata/get_corporate_layanan.php?corporate_id=' + encodeURIComponent(corporateId), true);
    xhr.onreadystatechange = function () {
        if (xhr.readyState == 4 && xhr.status == 200) {
            layananSelect.innerHTML = xhr.responseText;
        }
    };
    xhr.send();
}
document.getElementById('addInvoiceCorporate').addEventListener('change', function () {
    loadInvoiceLayananOptions(this.value);
});
document.addEventListener('DOMContentLoaded', function () {
    var preselected = document.getElementById('addInvoiceCorporate').value;
    if (preselected) {
        loadInvoiceLayananOptions(preselected);
    }
});

document.getElementById('payInvoiceModal').addEventListener('show.bs.modal', function (event) {
    var btn = event.relatedTarget;
    var sisa = btn.getAttribute('data-sisa') || '0';
    document.getElementById('payInvoiceId').value = btn.getAttribute('data-id') || '';
    document.getElementById('payInvoiceNomor').textContent = btn.getAttribute('data-nomor') || '';
    document.getElementById('payInvoiceSisa').textContent = Number(sisa).toLocaleString('id-ID');
    document.getElementById('payInvoiceJumlah').value = Math.round(Number(sisa));
});
</script>

<?php require 'footer.php'; ?>
