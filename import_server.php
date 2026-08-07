<?php require 'header.php'; ?>

<?php if (isset($_GET['status'])): ?>
  <div class="alert alert-<?php echo ($_GET['status']=='sukses')?'success':'danger'; ?> alert-dismissible fade show" role="alert">
    <?php echo htmlspecialchars($_GET['msg']); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>

<div class="container-fluid py-4">
  <div class="row">
    <div class="col-12">
      <div class="card mb-4">
        <div class="card-header pb-0">
          <div class="d-flex justify-content-between align-items-center">
            <h6 class="mb-0">Import Server dari Excel</h6>
            <a href="server.php" class="btn btn-secondary btn-sm">
              <i class="fas fa-arrow-left me-1"></i>Kembali ke Server List
            </a>
          </div>
        </div>
        <div class="card-body">
          <div class="row">
            <!-- Form Upload -->
            <div class="col-md-8">
              <div class="card">
                <div class="card-header bg-gradient-primary text-white">
                  <h6 class="mb-0"><i class="fas fa-upload me-2"></i>Upload File Excel</h6>
                </div>
                <div class="card-body">
                  <form id="importForm" action="proses/import_server.php" method="POST" enctype="multipart/form-data">
                    <div class="mb-3">
                      <label for="excel_file" class="form-label">Pilih File Excel (.xlsx)</label>
                      <input type="file" class="form-control" id="excel_file" name="excel_file" accept=".xlsx" required>
                      <div class="form-text">Format yang didukung: .xlsx (maksimal 10MB)</div>
                    </div>
                    
                    <div class="mb-3">
                      <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="skip_first_row" name="skip_first_row" checked>
                        <label class="form-check-label" for="skip_first_row">
                          Skip baris pertama (header)
                        </label>
                      </div>
                    </div>
                    
                    <div class="mb-3">
                      <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="test_connection" name="test_connection" checked>
                        <label class="form-check-label" for="test_connection">
                          Test koneksi setiap server sebelum menyimpan
                        </label>
                      </div>
                    </div>
                    
                    <div class="alert alert-info">
                      <i class="fas fa-info-circle me-2"></i>
                      <strong>Perhatian:</strong>
                      <ul class="mb-0 mt-2">
                        <li>Pastikan format Excel sesuai dengan template yang disediakan</li>
                        <li>Username harus unik untuk setiap server</li>
                        <li>IP Address dan Port harus valid</li>
                        <li>Proses import akan dihentikan jika ada error pada salah satu data</li>
                      </ul>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                      <i class="fas fa-cloud-upload-alt me-2"></i>Import Server
                    </button>
                  </form>
                </div>
              </div>
            </div>
            
            <!-- Template Download -->
            <div class="col-md-4">
              <div class="card">
                <div class="card-header bg-gradient-success text-white">
                  <h6 class="mb-0"><i class="fas fa-download me-2"></i>Download Template</h6>
                </div>
                <div class="card-body">
                  <p class="text-sm">Download template Excel untuk memudahkan proses import server.</p>
                  
                  <a href="proses/download_template_server.php" class="btn btn-success btn-sm mb-2 w-100">
                    <i class="fas fa-file-excel me-2"></i>Download Excel Template
                  </a>
                  
                  <div class="mt-3">
                    <h6 class="text-sm font-weight-bold">Cara menggunakan:</h6>
                    <small class="text-muted">
                      1. Download template Excel<br>
                      2. Buka dengan Microsoft Excel<br>
                      3. Isi data server sesuai format<br>
                      4. Save sebagai Excel (.xlsx)<br>
                      5. Upload file ke sistem
                    </small>
                  </div>
                  
                  <div class="mt-3">
                    <h6 class="text-sm font-weight-bold">Catatan:</h6>
                    <small class="text-muted">
                      Sistem hanya menerima file .xlsx.<br>
                      Jika file Anda .xls, lakukan Save As ke .xlsx terlebih dahulu.
                    </small>
                  </div>
                  
                  <div class="mt-3">
                    <h6 class="text-sm font-weight-bold">Format Data:</h6>
                    <small class="text-muted">
                      <strong>Kolom yang diperlukan:</strong><br>
                      1. Brand/Product Name<br>
                      2. Area<br>
                      3. IP Address<br>
                      4. API Port<br>
                      5. Web Port<br>
                      6. Username Admin MikroTik<br>
                      7. Password Admin MikroTik
                    </small>
                  </div>
                  
                  <div class="mt-3">
                    <h6 class="text-sm font-weight-bold">Contoh Data:</h6>
                    <div class="table-responsive">
                      <table class="table table-sm table-bordered text-xs">
                        <thead class="table-secondary">
                          <tr>
                            <th>Brand</th>
                            <th>Area</th>
                            <th>IP</th>
                            <th>Port</th>
                          </tr>
                        </thead>
                        <tbody>
                          <tr>
                            <td>Jayanet</td>
                            <td>Depok</td>
                            <td>192.168.1.1</td>
                            <td>8728</td>
                          </tr>
                        </tbody>
                      </table>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
  
  <!-- Progress Modal -->
  <div class="modal fade" id="progressModal" tabindex="-1" aria-labelledby="progressModalLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="progressModalLabel">
            <i class="fas fa-spinner fa-spin me-2"></i>Memproses Import...
          </h5>
        </div>
        <div class="modal-body">
          <div class="progress mb-3">
            <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%" id="progressBar"></div>
          </div>
          <div id="progressText">Memulai proses import...</div>
          <div id="progressDetails" class="mt-2 small text-muted"></div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
document.getElementById('importForm').addEventListener('submit', function(e) {
  // Show progress modal
  var progressModal = new bootstrap.Modal(document.getElementById('progressModal'));
  progressModal.show();
  
  // You can add AJAX progress tracking here if needed
  document.getElementById('progressText').textContent = 'Mengupload file...';
  document.getElementById('progressBar').style.width = '25%';
});

// File validation
document.getElementById('excel_file').addEventListener('change', function(e) {
  var file = e.target.files[0];
  if (file) {
    var fileSize = file.size / 1024 / 1024; // Convert to MB
    var fileName = file.name;
    var fileExt = fileName.split('.').pop().toLowerCase();
    
    if (fileSize > 10) {
      alert('File terlalu besar! Maksimal 10MB.');
      e.target.value = '';
      return false;
    }
    
    if (!['xlsx'].includes(fileExt)) {
      alert('Format file tidak didukung! Gunakan .xlsx');
      e.target.value = '';
      return false;
    }
  }
});
</script>

<?php require 'footer.php'; ?>
