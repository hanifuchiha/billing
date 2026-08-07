<?php require 'header.php'; ?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="card mb-4 shadow">
                <div class="card-header bg-success text-white">
                    <h5><i class="fas fa-download"></i> Export Server Data</h5>
                    <p class="mb-0">Export data server ke format Excel (.xlsx)</p>
                </div>
                <div class="card-body">
                    
                    <?php if (isset($_GET['status'])): ?>
                        <div class="alert alert-<?php echo ($_GET['status']=='sukses')?'success':'danger'; ?> alert-dismissible fade show" role="alert">
                            <?php echo htmlspecialchars($_GET['msg']); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="card border-primary">
                                <div class="card-header bg-primary text-white">
                                    <h6><i class="fas fa-file-excel"></i> Export Options</h6>
                                </div>
                                <div class="card-body">
                                    <form method="POST" action="proses/export_server.php">
                                        <div class="mb-3">
                                            <label class="form-label"><strong>Format Export:</strong></label>
                                            <div class="form-check">
                                                <input class="form-check-input" type="radio" name="format" id="format_xlsx" value="xlsx" checked>
                                                <label class="form-check-label" for="format_xlsx">
                                                    <i class="fas fa-file-excel text-success"></i> Excel (.xlsx)
                                                </label>
                                            </div>
                                            <small class="text-muted">Export saat ini hanya mendukung format .xlsx</small>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label"><strong>Data yang akan diexport:</strong></label>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="include_password" id="include_password">
                                                <label class="form-check-label" for="include_password">
                                                    <i class="fas fa-key text-warning"></i> Sertakan Password (Untuk Admin)
                                                </label>
                                                <small class="form-text text-muted">⚠️ Hanya untuk keperluan backup/migrasi</small>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="include_stats" id="include_stats" checked>
                                                <label class="form-check-label" for="include_stats">
                                                    <i class="fas fa-chart-bar text-primary"></i> Sertakan Status & Statistik
                                                </label>
                                            </div>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label"><strong>Filter Data:</strong></label>
                                            
                                            <!-- Pilihan Semua -->
                                            <div class="form-check">
                                                <input class="form-check-input" type="radio" name="filter_type" id="filter_all" value="all" checked>
                                                <label class="form-check-label" for="filter_all">
                                                    <i class="fas fa-globe text-primary"></i> Export Semua Data Server
                                                </label>
                                            </div>
                                            
                                            <!-- Pilihan Per Area -->
                                            <div class="form-check">
                                                <input class="form-check-input" type="radio" name="filter_type" id="filter_by_area" value="area">
                                                <label class="form-check-label" for="filter_by_area">
                                                    <i class="fas fa-map-marker-alt text-success"></i> Export Per Area
                                                </label>
                                            </div>
                                            <div id="area_selection" class="ms-4 mt-2" style="display: none;">
                                                <select name="filter_area" id="filter_area" class="form-select">
                                                    <option value="">-- Pilih Area --</option>
                                                       <?php
                if ($AKSES == 'ASSISTANT') {
                    $queryServer = mysqli_query($conn, "SELECT DISTINCT AREA, BRAND FROM server WHERE AREA IN ($area_list) ORDER BY AREA");
                } else {
                    $queryServer = mysqli_query($conn, "SELECT DISTINCT AREA, BRAND FROM server WHERE user_id = $current_user_id ORDER BY AREA");
                }
        while ($rowServer = mysqli_fetch_assoc($queryServer)) {
          $area = htmlspecialchars($rowServer['AREA']);
                    echo '<option value="'.$area.'">'.$rowServer['BRAND'].'-'.$area.'</option>';
        }
              ?>
                                                </select>
                                            </div>
                                            
                                            <!-- Pilihan Per Brand -->
                                            <div class="form-check">
                                                <input class="form-check-input" type="radio" name="filter_type" id="filter_by_brand" value="brand">
                                                <label class="form-check-label" for="filter_by_brand">
                                                    <i class="fas fa-tags text-warning"></i> Export Per Brand
                                                </label>
                                            </div>
                                            <div id="brand_selection" class="ms-4 mt-2" style="display: none;">
                                                <select name="filter_brand" id="filter_brand" class="form-select">
                                                    <option value="">-- Pilih Brand --</option>
                                                    <?php
                                                    // Ambil daftar brand unik
                                                    if($AKSES != 'ASSISTANT') {
                                                        $sql_brand = "SELECT DISTINCT BRAND FROM server WHERE user_id = $current_user_id ORDER BY BRAND";
                                                    } else {
                                                        $sql_brand = "SELECT DISTINCT BRAND FROM server WHERE `AREA` IN ($area_list) ORDER BY BRAND";
                                                    }
                                                    $query_brand = mysqli_query($conn, $sql_brand);
                                                    while ($brand_data = mysqli_fetch_array($query_brand)) {
                                                        if (!empty($brand_data['BRAND'])) {
                                                            echo '<option value="' . htmlspecialchars($brand_data['BRAND']) . '">' . htmlspecialchars($brand_data['BRAND']) . '</option>';
                                                        }
                                                    }
                                                    ?>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="d-grid gap-2">
                                            <button type="submit" class="btn btn-success btn-lg">
                                                <i class="fas fa-download"></i> Export Data Server
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="card border-info">
                                <div class="card-header bg-info text-white">
                                    <h6><i class="fas fa-info-circle"></i> Informasi Export</h6>
                                </div>
                                <div class="card-body">
                                    <?php
                                    // Hitung statistik server
                                    if($AKSES != 'ASSISTANT') {
                                        $sql_count = "SELECT COUNT(*) as total, 
                                                     COUNT(DISTINCT AREA) as total_area,
                                                     COUNT(DISTINCT BRAND) as total_brand
                                                     FROM server WHERE `pemilik` IN ($server_list)";
                                    } else {
                                        $sql_count = "SELECT COUNT(*) as total,
                                                     COUNT(DISTINCT AREA) as total_area,
                                                     COUNT(DISTINCT BRAND) as total_brand
                                                     FROM server WHERE `pemilik` IN ($server_list) and `AREA` IN ($area_list)";
                                    }
                                    $query_count = mysqli_query($conn, $sql_count);
                                    $stats = mysqli_fetch_array($query_count);
                                    ?>
                                    
                                    <div class="alert alert-light">
                                        <h6><i class="fas fa-server"></i> Statistik Data:</h6>
                                        <ul class="mb-0">
                                            <li><strong>Total Server:</strong> <?= $stats['total'] ?></li>
                                            <li><strong>Total Area:</strong> <?= $stats['total_area'] ?></li>
                                            <li><strong>Total Brand:</strong> <?= $stats['total_brand'] ?></li>
                                        </ul>
                                    </div>

                                    <h6><i class="fas fa-filter"></i> Pilihan Filter:</h6>
                                    <div class="alert alert-info">
                                        <ul class="mb-0">
                                            <li><strong>Semua Data:</strong> Export semua server yang dapat diakses</li>
                                            <li><strong>Per Area:</strong> Export server dari area tertentu saja</li>
                                            <li><strong>Per Brand:</strong> Export server dari brand tertentu saja</li>
                                        </ul>
                                    </div>

                                    <h6><i class="fas fa-list"></i> Format Export:</h6>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-bordered">
                                            <thead class="table-primary">
                                                <tr>
                                                    <th>Kolom</th>
                                                    <th>Keterangan</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr><td>Brand</td><td>Nama brand/bisnis</td></tr>
                                                <tr><td>Area</td><td>Wilayah/lokasi</td></tr>
                                                <tr><td>IP Address</td><td>IP MikroTik</td></tr>
                                                <tr><td>API Port</td><td>Port API</td></tr>
                                                <tr><td>Web Port</td><td>Port web interface</td></tr>
                                                <tr><td>Username</td><td>System username</td></tr>
                                                <tr><td>Password</td><td>*** atau password asli (sesuai pilihan)</td></tr>
                                            </tbody>
                                        </table>
                                    </div>

                                    <div class="alert alert-warning">
                                        <small>
                                            <i class="fas fa-exclamation-triangle"></i> 
                                            <strong>Catatan Keamanan:</strong><br>
                                            - Password akan tersembunyi (***) secara default<br>
                                            - Centang "Sertakan Password" untuk menampilkan password asli<br>
                                            - File dengan password asli harus disimpan dengan aman<br>
                                            - Jangan bagikan file ke pihak yang tidak berwenang
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 text-center">
                        <a href="server.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Kembali ke Server List
                        </a>
                        <a href="import_server.php" class="btn btn-primary ms-2">
                            <i class="fas fa-upload"></i> Import Server
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Show warning when password export is checked
    const passwordCheckbox = document.getElementById('include_password');
    
    passwordCheckbox.addEventListener('change', function() {
        if (this.checked) {
            if (!confirm('⚠️ PERINGATAN KEAMANAN!\n\nAnda akan mengexport data dengan password yang terlihat. Pastikan:\n\n1. File export disimpan di tempat yang aman\n2. Hanya digunakan untuk backup/migrasi\n3. File dihapus setelah tidak diperlukan\n\nLanjutkan export dengan password?')) {
                this.checked = false;
            }
        }
    });
    
    // Handle filter type selection
    const filterRadios = document.querySelectorAll('input[name="filter_type"]');
    const areaSelection = document.getElementById('area_selection');
    const brandSelection = document.getElementById('brand_selection');
    const areaSelect = document.getElementById('filter_area');
    const brandSelect = document.getElementById('filter_brand');
    
    filterRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            // Hide all selections first
            areaSelection.style.display = 'none';
            brandSelection.style.display = 'none';
            
            // Reset selections
            areaSelect.value = '';
            brandSelect.value = '';
            
            // Show relevant selection
            if (this.value === 'area') {
                areaSelection.style.display = 'block';
            } else if (this.value === 'brand') {
                brandSelection.style.display = 'block';
            }
        });
    });
    
    // Form validation
    const form = document.querySelector('form');
    form.addEventListener('submit', function(e) {
        const filterType = document.querySelector('input[name="filter_type"]:checked').value;
        
        if (filterType === 'area' && !areaSelect.value) {
            e.preventDefault();
            alert('Silakan pilih area untuk export!');
            areaSelect.focus();
            return false;
        }
        
        if (filterType === 'brand' && !brandSelect.value) {
            e.preventDefault();
            alert('Silakan pilih brand untuk export!');
            brandSelect.focus();
            return false;
        }
    });
});
</script>

<?php require 'footer.php'; ?>