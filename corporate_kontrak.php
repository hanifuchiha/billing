<?php require 'header.php';

if ($AKSES == 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('Corporate_Customer', $akses_menu, true)) {
        echo '<div class="container-fluid py-4"><div class="alert alert-danger">Anda tidak memiliki akses ke menu Customer Corporate.</div></div>';
        require 'footer.php';
        exit;
    }
}

require_once __DIR__ . '/corporate_helper.php';
corporateEnsureSchema($conn);

$corporateId = (int) ($_GET['corporate_id'] ?? 0);
$ceknamaEsc = mysqli_real_escape_string($conn, $ceknama);
$areaFilterCorp = corporateAreaFilterSql('AREA', $AKSES, $area_list ?? '');
$corp = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM corporate WHERE id = $corporateId AND PEMILIK = '$ceknamaEsc'" . $areaFilterCorp . " LIMIT 1"));
if (!$corp) {
    echo '<div class="container-fluid py-4"><div class="alert alert-danger">Customer Corporate tidak ditemukan.</div></div>';
    require 'footer.php';
    exit;
}

$status = $_GET['statusnotif'] ?? '';
$deleted = $_GET['deleted'] ?? '';
?>

<?php if ($status === 'success'): ?>
  <div class="alert alert-success alert-dismissible fade show" role="alert">
    <strong>Berhasil!</strong> Kontrak berhasil ditambahkan.
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php elseif ($status === 'failed'): ?>
  <div class="alert alert-danger alert-dismissible fade show" role="alert">
    <strong>Gagal!</strong> <?php echo htmlspecialchars($_GET['text'] ?? 'Terjadi kesalahan.'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>
<?php if ($deleted === '1'): ?>
  <div class="alert alert-success alert-dismissible fade show" role="alert">
    <strong>Berhasil!</strong> Kontrak berhasil dihapus.
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>

<div class="container-fluid py-4">
  <div class="row">
    <div class="col-12">
      <div class="card mb-4">
        <div class="card-header pb-0">
          <h6>Kontrak -- <?php echo htmlspecialchars($corp['NAMA_PERUSAHAAN']); ?></h6>
          <p class="text-muted small mt-2">
            Riwayat kontrak perusahaan ini. Boleh lebih dari satu baris (mis. saat renewal --
            tambahkan kontrak baru, tandai kontrak lama BERAKHIR).
          </p>
          <a href="corporate.php" class="btn btn-sm btn-secondary mb-3"><i class="fas fa-arrow-left me-1"></i> Kembali ke Customer Corporate</a>
        </div>

        <div class="card-body">
          <div class="table-responsive mb-4">
            <table class="table table-bordered table-striped table-hover align-middle">
              <thead class="table-dark">
                <tr>
                  <th>No</th>
                  <th>Nomor Kontrak</th>
                  <th>Tanggal Mulai</th>
                  <th>Tanggal Berakhir</th>
                  <th>Auto Reminder</th>
                  <th>File PDF</th>
                  <th>Status</th>
                  <th>Aksi</th>
                </tr>
              </thead>
              <tbody>
                <?php
                $qK = mysqli_query($conn, "SELECT * FROM corporate_kontrak WHERE corporate_id = $corporateId ORDER BY id DESC");
                if ($qK && mysqli_num_rows($qK) > 0) {
                    $no = 1;
                    while ($k = mysqli_fetch_assoc($qK)) {
                        echo "<tr>";
                        echo "<td>" . $no++ . "</td>";
                        echo "<td>" . htmlspecialchars($k['nomor_kontrak']) . "</td>";
                        echo "<td>" . htmlspecialchars($k['tanggal_mulai']) . "</td>";
                        echo "<td>" . htmlspecialchars($k['tanggal_berakhir']) . "</td>";
                        echo "<td>" . ((int) $k['auto_reminder'] === 1 ? 'H-' . (int) $k['hari_sebelum_reminder'] : '<span class="text-muted">Nonaktif</span>') . "</td>";
                        $pdfUrl = corporateDokumenUrl((string) ($k['file_pdf'] ?? ''));
                        echo "<td>" . ($pdfUrl !== '' ? "<a href='" . htmlspecialchars($pdfUrl) . "' target='_blank'>Lihat PDF</a>" : '<span class="text-muted">-</span>') . "</td>";
                        $badge = ($k['status'] === 'AKTIF') ? "<span class='badge bg-success'>AKTIF</span>" : "<span class='badge bg-secondary'>BERAKHIR</span>";
                        echo "<td>" . $badge . "</td>";
                        echo "<td>";
                        echo "<form method='post' action='proses/deletekontrakcorporate.php' data-perm='btn_corpkontrak_hapus' style='display:inline' onsubmit='return confirm(\"Yakin ingin menghapus kontrak ini?\")'>"
                            . "<input type='hidden' name='id' value='" . (int) $k['id'] . "'>"
                            . "<input type='hidden' name='corporate_id' value='$corporateId'>"
                            . "<button type='submit' class='btn btn-danger btn-sm'>Hapus</button></form>";
                        echo "</td>";
                        echo "</tr>";
                    }
                } else {
                    echo "<tr><td colspan='8' class='text-center'>Belum ada kontrak</td></tr>";
                }
                ?>
              </tbody>
            </table>
          </div>

          <h6>Tambah Kontrak Baru</h6>
          <form method="post" action="proses/addkontrakcorporate.php" enctype="multipart/form-data" class="row g-3">
            <input type="hidden" name="corporate_id" value="<?php echo $corporateId; ?>">
            <div class="col-md-4">
              <label class="form-label">Nomor Kontrak</label>
              <input type="text" class="form-control" name="nomor_kontrak">
            </div>
            <div class="col-md-4">
              <label class="form-label">Tanggal Mulai</label>
              <input type="date" class="form-control" name="tanggal_mulai">
            </div>
            <div class="col-md-4">
              <label class="form-label">Tanggal Berakhir</label>
              <input type="date" class="form-control" name="tanggal_berakhir">
            </div>
            <div class="col-md-3">
              <div class="form-check mt-4">
                <input class="form-check-input" type="checkbox" value="1" name="auto_reminder" id="autoReminderChk">
                <label class="form-check-label" for="autoReminderChk">Auto Reminder</label>
              </div>
            </div>
            <div class="col-md-3">
              <label class="form-label">Reminder H- (hari sebelum berakhir)</label>
              <input type="number" class="form-control" name="hari_sebelum_reminder" value="30" min="1">
            </div>
            <div class="col-md-6">
              <label class="form-label">Upload Kontrak (PDF)</label>
              <input type="file" class="form-control" name="file_pdf" accept=".pdf">
            </div>
            <div class="col-12">
              <label class="form-label">Catatan</label>
              <textarea class="form-control" name="catatan" rows="2"></textarea>
            </div>
            <div class="col-12">
              <button type="submit" class="btn btn-primary" data-perm="btn_corpkontrak_tambah">Simpan Kontrak</button>
            </div>
          </form>
          <div class="alert alert-secondary small mt-3 mb-0">
            Catatan: pengiriman reminder otomatis (WA/Email) H- sebelum kontrak berakhir belum
            aktif di versi ini -- toggle & H- di atas baru tersimpan sbg pengaturan, belum ada
            cron pengirimnya.
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require 'footer.php'; ?>
