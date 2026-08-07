<?php
require 'header.php';
if ($AKSES == 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('Backup_Restore', $akses_menu, true)) {
        echo '<div class="container-fluid py-4"><div class="alert alert-danger">Anda tidak memiliki akses ke menu Backup & Restore.</div></div>';
        require 'footer.php';
        exit;
    }
}


$availableTables = [
    'pelanggan' => 'Data pelanggan',
    'odp' => 'Data ODP',
    'paket' => 'Data paket',
    'server' => 'Data server',
    'pool' => 'IP pool',
    'transaksi' => 'Riwayat transaksi',
    'diskon' => 'Pengaturan diskon',
    'biaya_tambahan' => 'Pengaturan biaya tambahan',
    'botwa' => 'Konfigurasi WhatsApp Bot'
];

$defaultTables = ['pelanggan', 'odp', 'paket', 'server'];
$status = isset($_GET['status']) ? $_GET['status'] : '';
$msg = isset($_GET['msg']) ? urldecode($_GET['msg']) : '';
?>

<div class="container-fluid py-4">
  <div class="row">
    <div class="col-12">
      <div class="card mb-4">
        <div class="card-header pb-0">
          <h5 class="mb-1">BACKUP & RESTORE</h5>
          <p class="text-sm mb-0">Manajemen database per pemilik akun: <strong><?php echo htmlspecialchars($ceknama); ?></strong></p>
        </div>
        <div class="card-body">
          <?php if ($status === 'success'): ?>
            <div class="alert alert-success"><?php echo nl2br(htmlspecialchars($msg)); ?></div>
          <?php elseif ($status === 'error'): ?>
            <div class="alert alert-danger"><?php echo nl2br(htmlspecialchars($msg)); ?></div>
          <?php endif; ?>

          <div class="alert alert-info text-white" style="background:#0d6efd; border:none;">
            Backup dan restore hanya memproses data milik owner yang sedang login. 
            Tabel utama yang direkomendasikan: pelanggan, odp, paket, server.
          </div>

          <div class="row g-4">
            <div class="col-lg-6">
              <div class="card h-100 border">
                <div class="card-header pb-0">
                  <h6 class="mb-0">Download Backup</h6>
                </div>
                <div class="card-body">
                  <form action="backup_restore_action.php" method="post">
                    <input type="hidden" name="action" value="backup">

                    <label class="form-label">Pilih tabel yang ingin dibackup</label>
                    <div class="row">
                      <?php foreach ($availableTables as $table => $label): ?>
                        <div class="col-12 mb-2">
                          <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="tables[]" value="<?php echo htmlspecialchars($table); ?>" id="backup_<?php echo htmlspecialchars($table); ?>" <?php echo in_array($table, $defaultTables, true) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="backup_<?php echo htmlspecialchars($table); ?>">
                              <strong><?php echo htmlspecialchars($table); ?></strong> - <?php echo htmlspecialchars($label); ?>
                            </label>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>

                    <div class="form-check mt-2">
                      <input class="form-check-input" type="checkbox" name="compress_backup" value="1" id="compress_backup" checked>
                      <label class="form-check-label" for="compress_backup">
                        Kompres backup ke .json.gz (disarankan untuk data besar)
                      </label>
                    </div>

                    <button type="submit" class="btn bg-gradient-primary mt-3 mb-0 w-100" data-perm="btn_backup_download">
                      <i class="fas fa-download me-2"></i>Download Backup
                    </button>
                  </form>

                  <hr class="my-4">

                  <form action="backup_restore_action.php" method="post">
                    <input type="hidden" name="action" value="structure">

                    <label class="form-label">Download struktur database (.sql)</label>
                    <div class="row">
                      <?php foreach ($availableTables as $table => $label): ?>
                        <div class="col-12 mb-2">
                          <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="tables[]" value="<?php echo htmlspecialchars($table); ?>" id="structure_<?php echo htmlspecialchars($table); ?>" checked>
                            <label class="form-check-label" for="structure_<?php echo htmlspecialchars($table); ?>">
                              <strong><?php echo htmlspecialchars($table); ?></strong> - <?php echo htmlspecialchars($label); ?>
                            </label>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>

                    <button type="submit" class="btn bg-gradient-dark mt-3 mb-0 w-100" data-perm="btn_backup_struktur">
                      <i class="fas fa-database me-2"></i>Download Struktur DB
                    </button>
                  </form>
                </div>
              </div>
            </div>

            <div class="col-lg-6">
              <div class="card h-100 border">
                <div class="card-header pb-0">
                  <h6 class="mb-0">Restore Data</h6>
                </div>
                <div class="card-body">
                  <form action="backup_restore_action.php" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="restore">

                    <div class="mb-3">
                      <label class="form-label">File backup (.json atau .json.gz)</label>
                      <input type="file" class="form-control" name="backup_file" accept="application/json,.json,.gz,.json.gz,application/gzip" required>
                      <small class="text-muted">Untuk data besar, gunakan file .json.gz agar lebih ringan saat upload.</small>
                    </div>

                    <label class="form-label">Restore tabel terpilih</label>
                    <div class="row">
                      <?php foreach ($availableTables as $table => $label): ?>
                        <div class="col-12 mb-2">
                          <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="tables[]" value="<?php echo htmlspecialchars($table); ?>" id="restore_<?php echo htmlspecialchars($table); ?>" <?php echo in_array($table, $defaultTables, true) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="restore_<?php echo htmlspecialchars($table); ?>">
                              <strong><?php echo htmlspecialchars($table); ?></strong> - <?php echo htmlspecialchars($label); ?>
                            </label>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>

                    <button type="submit" class="btn bg-gradient-warning mt-3 mb-0 w-100" data-perm="btn_restore_sekarang" onclick="return confirm('Restore akan mengganti data owner ini pada tabel terpilih. Lanjutkan?');">
                      <i class="fas fa-upload me-2"></i>Restore Sekarang
                    </button>
                  </form>
                </div>
              </div>
            </div>
          </div>

        </div>
      </div>
    </div>
  </div>
</div>

<?php require 'footer.php'; ?>
