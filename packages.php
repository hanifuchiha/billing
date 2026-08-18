<?php require 'header.php';
if ($AKSES == 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('Packages_Broadband', $akses_menu, true)) {
        echo '<div class="container-fluid py-4"><div class="alert alert-danger">Anda tidak memiliki akses ke menu Packages Broadband.</div></div>';
        require 'footer.php';
        exit;
    }
}
?>
<?php
require_once __DIR__ . '/../mitra/registrasi_helper.php';
// Toggle "Input Paket Manual" di form pendaftaran publik, disimpan per akun
// owner ($ceknama), lihat registrasi_helper.php untuk detail penyimpanan.
$manualPaketEnabled = registrasi_get_manual_paket_enabled($ceknama ?? '');

// Bootstrap kolom paket.RADIUS_PROFILE_SOURCE (opt-in profil RADIUS-langsung
// untuk Panel Kontrol FreeRADIUS) -- default 'MIKROTIK', tidak mengubah
// perilaku paket existing sampai admin sengaja mengubahnya di form di bawah.
require_once __DIR__ . '/radius_sync_lib.php';
radiusEnsurePaketProfileSourceColumn($conn);

// Bootstrap kolom paket.DISKON_PERMANEN_TYPE/NILAI -- diskon permanen yang
// melekat ke paket, otomatis memotong tagihan tiap invoice dibuat.
require_once __DIR__ . '/reseller_helper.php';
paketDiskonPermanenEnsureColumns($conn);

// Bootstrap kolom paket.TIPE_LAYANAN (menu Paket Static IP terpisah) --
// dibutuhkan supaya query listing di bawah bisa mengecualikan paket Static IP.
require_once __DIR__ . '/staticip_helper.php';
staticipEnsureSchema($conn);
?>

<!-- Cache control meta tags for performance optimization -->
<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
<meta http-equiv="Pragma" content="no-cache">
<meta http-equiv="Expires" content="0">



   <?php
// Notifikasi sukses atau gagal dari addpool.php
$status = $_GET['statusnotif'] ?? '';
?>
      <?php if ($status == 'success'): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
          <strong>? Berhasil!</strong>
          <?php if (isset($_GET['synced'])): ?>
            <?php echo (int)$_GET['synced']; ?> profile berhasil di-sync dari MikroTik.
            <?php if (!empty($_GET['skipped'])): ?>
              (<?php echo (int)$_GET['skipped']; ?> dilewati karena sudah ada)
            <?php endif; ?>
          <?php else: ?>
            paket berhasil dibuat.
          <?php endif; ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php elseif ($status == 'duplicate'): ?>
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
          <strong>?? Gagal!</strong> Nama paket sudah digunakan.
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php elseif ($status == 'failed'): ?>
        <?php
          $text = isset($_GET['text']) ? htmlspecialchars($_GET['text'], ENT_QUOTES, 'UTF-8') : '';
        ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
          <strong>? Error!</strong> Terjadi kesalahan saat membuat paket.
          <?php if ($text !== ''): ?>
            <div class="mt-2"><small><?php echo $text; ?></small></div>
          <?php endif; ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>

        <?php
        // show tail of addpackagespppoe log if available
        $logPath = __DIR__ . '/logs/addpackagespppoe.log';
        if (file_exists($logPath)) {
            $log = @file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($log === false) $log = [];
            $tail = array_slice($log, -200);
            $tailText = htmlspecialchars(implode("\n", $tail), ENT_QUOTES, 'UTF-8');
            ?>
            <div class="card mt-3">
              <div class="card-header">Log (last <?php echo count($tail); ?> lines)</div>
              <div class="card-body" style="max-height:400px; overflow:auto;">
                <pre style="font-size:12px; white-space:pre-wrap; word-break:break-all"><?php echo $tailText; ?></pre>
              </div>
            </div>
            <?php
        }
        ?>
      <?php endif; ?>

   <?php
// Notifikasi sukses atau gagal dari addpool.php
$deleted = $_GET['deleted'] ?? '';
?>
      <?php if ($deleted == '1'): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
          <strong>? Berhasil!</strong> paket berhasil dihapus.
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php elseif ($deleted == '0'): ?>
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
          <strong>?? Gagal!</strong> paket gagal dihapus.
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php elseif ($deleted == '2'): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
          <strong>? Gagal!</strong> Paket masih digunakan oleh pelanggan, tidak dapat dihapus.
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>


   <?php
// Notifikasi sukses atau gagal dari addpool.php
$edit = $_GET['edit'] ?? '';
?>
      <?php if ($edit == '1'): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
          <strong>? Berhasil!</strong> paket berhasil edit.
          <?php if (!empty($_GET['installed'])): ?>
            Ditambahkan juga ke <?php echo (int)$_GET['installed']; ?> server baru.
          <?php endif; ?>
          <?php if (!empty($_GET['skipped'])): ?>
            (<?php echo (int)$_GET['skipped']; ?> server dilewati karena paket sudah ada)
          <?php endif; ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php elseif ($edit == '0'): ?>
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
          <strong>?? Gagal!</strong> paket gagal edit.
          <?php if (!empty($_GET['text'])): ?>
            <div class="mt-2"><small><?php echo htmlspecialchars($_GET['text'], ENT_QUOTES, 'UTF-8'); ?></small></div>
          <?php endif; ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>

   <?php if (($_GET['tampildaftar'] ?? '') === '1'): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
          <strong>? Berhasil!</strong> Pengaturan pendaftaran berhasil disimpan.
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
   <?php endif; ?>



  <div class="container-fluid py-4">
    <div class="row">
      <div class="col-12">
        <div class="card mb-4">
          <div class="card-header pb-0">
            <h6>Packages table</h6>
            <!-- Button Group -->
            <div class="btn-group-custom mb-3">
              <div class="btn-group-row">
                <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#dataModal">
                  <i class="fas fa-plus me-1"></i> Tambah Paket
                </button>
                <button type="button" class="btn btn-sm btn-dark" data-bs-toggle="modal" data-bs-target="#syncPilihServerModal">
                  <i class="fas fa-sync me-1"></i> Sync Profile
                </button>
                <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#importModal">
                  <i class="fas fa-file-excel me-1"></i> Import Excel
                </button>
                <a href="proses/export_packages.php" class="btn btn-sm btn-info">
                  <i class="fas fa-download me-1"></i> Export Excel
                </a>
              </div>
              <div class="btn-group-row">
                <a href="proses/download_template_paket.php" class="btn btn-sm btn-secondary" title="Download template Excel">
                  <i class="fas fa-file me-1"></i> Template
                </a>
                <a href="promo_paket.php" class="btn btn-sm btn-warning">
                  <i class="fas fa-gift me-1"></i> Promo
                </a>
                <button type="button" class="btn btn-sm" style="background-color:#0066ff;color:#fff;" data-bs-toggle="modal" data-bs-target="#pendaftaranSettingModal">
                  <i class="fas fa-globe me-1"></i> Pengaturan Pendaftaran
                </button>
              </div>
            </div>

            <div class="mb-3" style="max-width: 420px;">
              <input type="text" id="searchInput" class="form-control form-control-sm" placeholder="🔍 Cari nama paket, kecepatan, harga, atau server...">
            </div>

            <!-- Modal Import Paket Excel -->
            <div class="modal fade" id="importModal" tabindex="-1" aria-labelledby="importModalLabel" aria-hidden="true">
              <div class="modal-dialog">
                <div class="modal-content">
                  <div class="modal-header">
                    <h5 class="modal-title" id="importModalLabel">Import Paket dari Excel</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                  </div>
                  <form method="post" action="proses/import_paket.php" enctype="multipart/form-data">
                    <div class="modal-body">
                      <div class="alert alert-info small mb-2">
                        <b>Petunjuk:</b> Download template, isi sesuai urutan kolom berikut:<br>
                        <code>Nama | Kecepatan | Harga | Local | Remote | PEMILIK | Area | Komisi | Brand</code><br>
                        <span class="text-danger">* File harus .xlsx, baris pertama = judul kolom, Brand opsional (otomatis ikut data server)</span>
                      </div>
                      <div class="mb-3">
                        <label for="excel_file" class="form-label">Pilih file Excel (.xlsx)</label>
                        <input type="file" class="form-control form-control-sm" id="excel_file" name="excel_file" accept=".xlsx" required>
                      </div>
                      <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" value="1" id="skip_first_row" name="skip_first_row" checked>
                        <label class="form-check-label" for="skip_first_row">Lewati baris pertama (judul kolom)</label>
                      </div>
                    </div>
                    <div class="modal-footer">
                      <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                      <button type="submit" class="btn btn-success btn-sm">Import</button>
                    </div>
                  </form>
                </div>
              </div>
            </div>



            <!-- Modal Tambah Paket -->
            <div class="modal fade" id="dataModal" tabindex="-1" aria-labelledby="dataModalLabel" aria-hidden="true">
              <div class="modal-dialog">
                <div class="modal-content">
                  <div class="modal-header">
                    <h5 class="modal-title" id="dataModalLabel">Tambah Paket PPPOE</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                  </div>
                  <div class="modal-body">
                    <form id="dataForm" method="post" action="proses/addpackagespppoe.php">

                      <div class="mb-3">
                        <label for="profileName" class="form-label">Nama Paket</label>
                        <input required type="text" class="form-control form-control-sm" id="profileName" name="profileName" placeholder="Misal: Premium 10M" required>
                      </div>

                      <div class="row">
                        <div class="col-md-6 mb-3">
                          <label for="ratelimit" class="form-label">Kecepatan</label>
                          <input required type="text" class="form-control form-control-sm" id="ratelimit" name="ratelimit" placeholder="Misal: 10M/10M" required>
                        </div>
                        <div class="col-md-6 mb-3">
                          <label for="harga" class="form-label">Harga dasar</label>
                          <input required type="text" inputmode="numeric" class="form-control form-control-sm input-harga-angka" id="harga" name="harga" placeholder="Rp. 0" required oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                        </div>
                      </div>
                      <div hidden class="mb-3">
                        <label for="rateLimit" class="form-label">Komisi sales ( % )</label>
                        <input type="number" class="form-control form-control-sm" id="komisi" name="komisi" value="0">
                      </div>

                      <div class="row">
                        <div class="col-md-6 mb-3">
                          <label for="diskon_permanen_type" class="form-label">Diskon Permanen Paket</label>
                          <select class="form-control form-control-sm" id="diskon_permanen_type" name="diskon_permanen_type">
                            <option value="">Tidak ada diskon</option>
                            <option value="nominal">Nominal (Rp)</option>
                            <option value="persentase">Persentase (%)</option>
                          </select>
                        </div>
                        <div class="col-md-6 mb-3">
                          <label for="diskon_permanen_nilai" class="form-label">Nilai Diskon</label>
                          <input type="number" min="0" step="any" class="form-control form-control-sm" id="diskon_permanen_nilai" name="diskon_permanen_nilai" placeholder="0" value="0">
                          <div class="form-text">Otomatis memotong tagihan setiap invoice dibuat, selama pelanggan masih memakai paket ini.</div>
                        </div>
                      </div>

                      <div class="mb-3">
                        <label for="radius_profile_source" class="form-label">Sumber Profil RADIUS (untuk pelanggan mode RADIUS/MULTI)</label>
                        <select class="form-control form-control-sm" id="radius_profile_source" name="radius_profile_source">
                          <option value="MIKROTIK" selected>Profil Mikrotik (default -- bandwidth diatur di PPP Profile router)</option>
                          <option value="RADIUS_LANGSUNG">RADIUS Langsung (bandwidth dari Kecepatan di atas, dikirim lewat atribut RADIUS)</option>
                        </select>
                        <div class="form-text">Butuh toggle master aktif juga di Panel Kontrol FreeRADIUS &gt; tab Default. Default "Profil Mikrotik" -- aman, tidak mengubah perilaku existing.</div>
                      </div>

                      <?php
                      // Ambil data pool
                      $sqlPool = "SELECT iplocal, ipawal, ipakhir FROM pool WHERE pemilik='$ceknama'";
                      $resultPool = mysqli_query($conn, $sqlPool);

                      // Ambil semua paket untuk cek LOCAL & REMOTE
                      $sqlPaket = "SELECT LOCAL, REMOTE FROM paket WHERE PEMILIK='$ceknama'";
                      $resultPaket = mysqli_query($conn, $sqlPaket);

                      $usedLocal = [];
                      $usedRemot = [];
                      while($row = mysqli_fetch_array($resultPaket)){
                          if(!empty($row['LOCAL'])) $usedLocal[] = $row['LOCAL'];
                          if(!empty($row['REMOTE'])) $usedRemot[] = $row['REMOTE'];
                      }

                      // Cek apakah pool tersedia
                      $poolAvailable = false;
                      $poolMap = [];
                      while($row = mysqli_fetch_array($resultPool)){
                          $iplocal = $row['iplocal'];
                          $ipremote = $row['ipawal'].'-'.$row['ipakhir'];
                          $poolMap[$iplocal] = $ipremote;

                          if(!in_array($iplocal, $usedLocal) && !in_array($ipremote, $usedRemot)){
                              $poolAvailable = true; // ada pool yang belum dipakai
                          }
                      }
                      ?>

                      <div class="row">
                          <div class="col-md-6 mb-3">
                              <label for="local" class="form-label">Local IP</label>
                              <select class="form-control form-control-sm" id="local" name="local" <?php echo $poolAvailable ? '' : 'disabled'; ?> onchange="updateRemot()">
                                  <option value="">-- Pilih Local IP --</option>
                                  <?php
                                  foreach($poolMap as $local => $remote){
                                      $disabled = (in_array($local, $usedLocal) || in_array($remote, $usedRemot)) ? 'disabled' : '';
                                      echo "<option value='$local' $disabled>$local</option>";
                                  }
                                  ?>
                              </select>
Dari pool yang terdaftar di menu POOL
                          </div>

                          <div  class="col-md-6 mb-3">
                              <label for="remot" class="form-label">Remote IP</label>
                              <select class="form-control form-control-sm" id="remot" name="remot" disabled>
                                  <option value="">-- Pilih Remote IP --</option>
                                  <?php
                                  foreach($poolMap as $local => $remote){
                                      $disabled = (in_array($local, $usedLocal) || in_array($remote, $usedRemot)) ? 'disabled' : '';
                                      echo "<option value='$remote' $disabled>$remote</option>";
                                  }
                                  ?>
                              </select>
                              <div class="form-text">Pilih Local IP terlebih dahulu.</div>
                          </div>
                      </div>

                      <?php if(!$poolAvailable): ?>
                          <a href="pool.php" class="btn btn-warning">Buat IP Pool</a>
                      <?php endif; ?>

                      <script>
                      // Mapping Local ? Remote IP
                      var poolMap = <?php echo json_encode($poolMap); ?>;

                      function updateRemot() {
                          var localSelect = document.getElementById('local');
                          var remotSelect = document.getElementById('remot');
                          var selectedLocal = localSelect.value;

                          if(selectedLocal && poolMap[selectedLocal]){
                              remotSelect.value = poolMap[selectedLocal];
                              remotSelect.disabled = false;
                          } else {
                              remotSelect.value = "";
                              remotSelect.disabled = true;
                          }
                      }
                      </script>


                      <?php
                      $current_user_id = isset($_SESSION['id']) ? (int)$_SESSION['id'] : 0;
                      $queryServer = null;
                      if ($current_user_id) {
                          if ($AKSES == 'ASSISTANT') {
                              $queryServer = mysqli_query($conn, "SELECT DISTINCT PEMILIK, BRAND, AREA FROM server WHERE AREA IN ($area_list)");
                          } else {
                              $queryServer = mysqli_query($conn, "SELECT DISTINCT PEMILIK, BRAND, AREA FROM server WHERE user_id = $current_user_id");
                          }
                      }
                      ?>
                      <div class="mb-3">
                        <label class="form-label">Server Area</label>
                        <div id="serverCheckboxes">
                          <?php
                          if ($queryServer && mysqli_num_rows($queryServer) > 0) {
                              mysqli_data_seek($queryServer, 0);
                              while ($rowServer = mysqli_fetch_assoc($queryServer)) {
                                $area = htmlspecialchars($rowServer['AREA']);
                                $pemilik = $rowServer['PEMILIK'];
                                $brand = htmlspecialchars($rowServer['BRAND']);
                                $value = json_encode(['pemilik' => $pemilik, 'brand' => $brand, 'area' => $area]);
                                echo '<div class="form-check">';
                                echo '<input class="form-check-input" type="checkbox" name="servers[]" value="' . htmlspecialchars($value) . '" id="server_' . $pemilik . '">';
                                echo '<label class="form-check-label">' . $brand . '-' . $area . '</label>';
                                echo '</div>';
                              }
                          } else {
                              echo '<p>Tidak ada server tersedia.</p>';
                          }
                          ?>
                        </div>
                      </div>

                    </form>
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" form="dataForm" class="btn btn-primary btn-sm">Simpan</button>
                  </div>
                </div>
              </div>
            </div>

            <!-- ============================================================
                 MODAL SYNC PROFILE � PILIH SERVER
                 ============================================================ -->
            <div class="modal fade" id="syncPilihServerModal" tabindex="-1" aria-labelledby="syncPilihServerModalLabel" aria-hidden="true">
              <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                  <div class="modal-header bg-gradient-primary text-white">
                    <h6 class="modal-title" id="syncPilihServerModalLabel">Sync Profile MikroTik</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                  </div>
                  <div class="modal-body">
                    <p class="text-sm mb-2">Pilih server yang ingin diambil daftar PPP Profile-nya:</p>
                    <div class="list-group" id="syncServerList">
                      <?php
                      // Daftar server milik user (pola query sama seperti server.php)
                      $current_user_id_sync = isset($_SESSION['id']) ? (int)$_SESSION['id'] : 0;
                      $sqlSyncServer = ($AKSES == 'ASSISTANT')
                          ? "SELECT * FROM server WHERE AREA IN ($area_list)"
                          : "SELECT * FROM server WHERE user_id = $current_user_id_sync";
                      $resSyncServer = mysqli_query($conn, $sqlSyncServer);

                      if ($resSyncServer && mysqli_num_rows($resSyncServer) > 0) {
                          while ($srv = mysqli_fetch_assoc($resSyncServer)) {
                              $sIp    = htmlspecialchars($srv['IP'], ENT_QUOTES, 'UTF-8');
                              $sUser  = htmlspecialchars($srv['PEMILIK'], ENT_QUOTES, 'UTF-8');
                              $sPass  = htmlspecialchars($srv['PASSWORD'], ENT_QUOTES, 'UTF-8');
                              $sArea  = htmlspecialchars($srv['AREA'], ENT_QUOTES, 'UTF-8');
                              $sBrand = htmlspecialchars($srv['BRAND'], ENT_QUOTES, 'UTF-8');
                              echo '<button type="button" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
                                      onclick="startSyncProfile(this)"
                                      data-ip="' . $sIp . '" data-user="' . $sUser . '" data-pass="' . $sPass . '"
                                      data-area="' . $sArea . '" data-brand="' . $sBrand . '">
                                      <span>' . $sBrand . ' - ' . $sArea . '</span>
                                      <code class="text-xs">' . $sIp . '</code>
                                    </button>';
                          }
                      } else {
                          echo '<p class="text-muted text-sm mb-0">Tidak ada server tersedia.</p>';
                      }
                      ?>
                    </div>
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                  </div>
                </div>
              </div>
            </div>

            <!-- ============================================================
                 MODAL SYNC PROFILE � HASIL (loading + tabel checkbox)
                 ============================================================ -->
            <div class="modal fade" id="syncProfileResultModal" tabindex="-1" aria-labelledby="syncProfileResultModalLabel" aria-hidden="true" data-bs-backdrop="static">
              <div class="modal-dialog modal-dialog-centered modal-xl">
                <div class="modal-content">
                  <form id="syncProfileForm" method="POST" action="proses/sync_packages.php">
                    <input type="hidden" name="ip" id="sync_ip">
                    <input type="hidden" name="pemilik" id="sync_pemilik">
                    <input type="hidden" name="profiles" id="sync_profiles_payload">

                    <div class="modal-header bg-gradient-primary text-white">
                      <h6 class="modal-title" id="syncProfileResultModalLabel">Profile MikroTik � <span id="sync_server_label"></span></h6>
                      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body" style="max-height:70vh; overflow-y:auto;">
                      <div id="syncLoading" class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                          <span class="visually-hidden">Loading...</span>
                        </div>
                        <div class="mt-2 text-sm">Mengambil daftar PPP Profile dari MikroTik...</div>
                      </div>

                      <div id="syncErrorBox" class="alert alert-danger d-none"></div>

                      <div id="syncProfileTableWrap" class="d-none">
                        <p class="text-sm text-muted mb-2">
                          Centang profile yang ingin di-import sebagai paket baru. Profile yang sudah tersinkron ditandai
                          <span class="badge bg-success">Synced</span> dan tidak bisa dicentang ulang.
                        </p>
                        <div class="table-responsive sync-table-wrap">
                          <table class="table table-sm table-bordered align-middle sync-profile-table" id="syncProfileTable">
                            <thead class="table-light">
                              <tr>
                                <th style="width:36px;"><input type="checkbox" id="syncCheckAll"></th>
                                <th style="min-width:140px;">Nama Profile</th>
                                <th style="min-width:200px;">Rate Limit (Mikrotik)</th>
                                <th style="width:120px;">Harga dasar (Rp)</th>
                                <th style="min-width:140px;">Local IP</th>
                                <th style="min-width:140px;">Remote IP</th>
                                <th class="text-center" style="width:80px;">Status</th>
                              </tr>
                            </thead>
                            <tbody id="syncProfileTbody">
                              <!-- diisi via JS -->
                            </tbody>
                          </table>
                        </div>
                      </div>
                    </div>

                    <div class="modal-footer">
                      <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Tutup</button>
                      <button type="submit" class="btn btn-primary btn-sm d-none" id="syncSubmitBtn">
                        <i class="fas fa-save me-1"></i> Import Profile Terpilih
                      </button>
                    </div>
                  </form>
                </div>
              </div>
            </div>

          </div>

          <div class="card-body px-0 pt-0 pb-2">
            <div class="table-responsive p-0">

              <style>
              /* Button Group Styling */
              .btn-group-custom { display: flex; flex-wrap: wrap; gap: 1rem; }
              .btn-group-row { display: flex; gap: 0.5rem; flex-wrap: wrap; }
              /* Table Styling */
              .packages-table { font-size: 11px; }
              .packages-table thead { background-color: #f8f9fa; }
              .packages-table tbody tr:hover { background-color: rgba(0, 0, 0, 0.02); }
              .packages-table td { vertical-align: middle; padding: 0.75rem 0.5rem !important; }
              .packages-table th { padding: 0.75rem 0.5rem !important; font-weight: 600; }
              /* Action Buttons */
              .action-buttons { display: flex; gap: 0.25rem; flex-wrap: wrap; }
              .action-buttons .btn { margin: 0; }
              /* Server list */
              .server-list {
                list-style: none;
                margin: 0;
                padding: 0;
                text-align: left;
              }
              .server-list li {
                padding: 2px 0;
                border-bottom: 1px dashed #eee;
                white-space: nowrap;
              }
              .server-list li:last-child { border-bottom: none; }
              /* Mobile Responsive */
              @media screen and (max-width: 768px) {
                .btn-group-custom { flex-direction: column; gap: 0.5rem; }
                .btn-group-row { width: 100%; }
                .btn-group-row .btn { flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
                .packages-table { font-size: 10px; }
              }
              </style>

              <table class="table align-items-center mb-0 packages-table">
                <thead>
                  <tr>
                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">Name Packages</th>
                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2 text-center">Speed</th>
                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2 text-center">Prize</th>
                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2 text-center">Server</th>
                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2 text-center">Action</th>
                  </tr>
                </thead>

                <tbody id="dataTable">
                  <?php
                  // Ambil hanya paket yang server-nya dimiliki oleh user yang sedang login
                  if ($AKSES != 'ASSISTANT') {
                      $current_user_id = isset($_SESSION['id']) ? (int)$_SESSION['id'] : 0;
                      $queryServerId = mysqli_query($conn, "SELECT PEMILIK FROM server WHERE user_id = $current_user_id");
                      $userServerIds = [];
                      while ($row = mysqli_fetch_assoc($queryServerId)) {
                          $userServerIds[] = "'" . $row['PEMILIK'] . "'";
                      }
                      $userServerList = count($userServerIds) > 0 ? implode(",", $userServerIds) : "''";
                      // Paket Static IP (menu Paket Static IP terpisah) tidak boleh nyampur
                      // ke listing Packages Broadband biasa ini.
                      $sql = "SELECT * FROM paket WHERE `PEMILIK` IN ($userServerList) AND (`TIPE_LAYANAN` IS NULL OR `TIPE_LAYANAN` != 'PPPOE_STATIC')";
                  } else {
                      // Untuk ASSISTANT, tetap gunakan $area_list
                      $sql = "SELECT * FROM paket WHERE `AREA` IN ($area_list) AND (`TIPE_LAYANAN` IS NULL OR `TIPE_LAYANAN` != 'PPPOE_STATIC')";
                  }
                  $query = mysqli_query($conn, $sql);
                  $rawPaketRows = reseller_collect_rows($query);
                  $paketRows = reseller_filter_rows($conn, $rawPaketRows, 'broadband');

                  // Filter pencarian: cocokkan nama paket, kecepatan, harga, brand, atau area.
                  // Diterapkan sebelum grouping/paging supaya hasil pencarian mencakup
                  // seluruh data paket, bukan cuma baris yang sudah termuat di layar.
                  $searchTerm = trim((string)($_POST['search'] ?? $_GET['search'] ?? ''));
                  if ($searchTerm !== '') {
                      $searchNeedle = mb_strtoupper($searchTerm);
                      $paketRows = array_values(array_filter($paketRows, function ($r) use ($searchNeedle) {
                          $haystack = mb_strtoupper(
                              ($r['PAKET'] ?? '') . ' ' .
                              ($r['KECEPATAN'] ?? '') . ' ' .
                              ($r['HARGA'] ?? '') . ' ' .
                              ($r['BRAND'] ?? '') . ' ' .
                              ($r['AREA'] ?? '')
                          );
                          return mb_strpos($haystack, $searchNeedle) !== false;
                      }));
                  }

                  if (isset($_GET['reseller_debug'])) {
                      echo '<tr><td colspan="5"><pre style="white-space:pre-wrap;text-align:left;background:#fff3cd;padding:10px;border:1px solid #ffc107;">';
                      echo 'AKSES: ' . htmlspecialchars((string)$AKSES) . "\n";
                      echo 'is_reseller: ' . var_export($is_reseller ?? null, true) . "\n";
                      echo 'reseller_price_filter_enabled: ' . var_export($reseller_price_filter_enabled ?? null, true) . "\n";
                      echo 'reseller_id: ' . var_export($reseller_id ?? null, true) . "\n";
                      echo 'area_list: ' . htmlspecialchars((string)($area_list ?? '(undefined)')) . "\n";
                      echo 'SQL: ' . htmlspecialchars($sql) . "\n";
                      echo 'raw rows fetched: ' . count($rawPaketRows) . "\n";
                      echo 'raw row ids+area: ' . htmlspecialchars(implode(', ', array_map(function($r){ return ($r['id'] ?? '?') . '/' . ($r['AREA'] ?? '?'); }, $rawPaketRows))) . "\n";
                      echo 'rows after reseller filter: ' . count($paketRows) . "\n";
                      if (!empty($reseller_id)) {
                          $dbgQ = mysqli_query($conn, "SELECT paket_id, enabled, custom_harga FROM reseller_paket_price WHERE reseller_user_id=" . (int)$reseller_id . " AND paket_type='broadband'");
                          $dbgRows = reseller_collect_rows($dbgQ);
                          echo 'reseller_paket_price rows for this reseller_id: ' . htmlspecialchars(json_encode($dbgRows)) . "\n";
                      }
                      echo '</pre></td></tr>';
                  }

                  // ====== GROUPING: PAKET + HARGA + KECEPATAN ======
                  // Baris dianggap "paket sama" hanya jika ketiga nilai ini identik.
                  // Tiap grup menyimpan daftar server (BRAND-AREA) dan daftar id baris asli
                  // (untuk keperluan edit/hapus per-server).
                  $groups = [];
                  foreach ($paketRows as $data) {
                      $groupKey = $data['PAKET'] . '|' . $data['HARGA'] . '|' . $data['KECEPATAN'];

                      if (!isset($groups[$groupKey])) {
                          $groups[$groupKey] = [
                              'paket'     => $data['PAKET'],
                              'kecepatan' => $data['KECEPATAN'],
                              'harga'     => $data['HARGA'],
                              'rows'      => [], // setiap entry = baris asli (1 server)
                          ];
                      }
                      $groups[$groupKey]['rows'][] = $data;
                  }

                  $current_user_id = isset($_SESSION['id']) ? (int)$_SESSION['id'] : 0;

                  // Lazy-load: 20 grup paket per fetch supaya daftar paket yang besar
                  // tidak dirender sekaligus (mencegah lag/crash). Paging dilakukan per
                  // GRUP (bukan per baris mentah) karena grouping paket+harga+kecepatan
                  // sudah selesai dihitung di atas dari seluruh data - jadi tiap halaman
                  // yang dikirim selalu berisi grup yang lengkap & akurat.
                  $pkgPageSize   = 20;
                  $pkgPage       = isset($_POST['page']) ? (int)$_POST['page'] : 1;
                  if ($pkgPage < 1) $pkgPage = 1;
                  $pkgAllGroupKeys = array_keys($groups);
                  $pkgTotalGroups  = count($pkgAllGroupKeys);
                  $pkgTotalPages   = max(1, (int)ceil($pkgTotalGroups / $pkgPageSize));
                  $pkgPage         = min($pkgPage, $pkgTotalPages);
                  $pkgOffset       = ($pkgPage - 1) * $pkgPageSize;
                  $pkgPageGroupKeys = array_slice($pkgAllGroupKeys, $pkgOffset, $pkgPageSize);
                  $groupModalsHtml = [];

                  foreach ($pkgPageGroupKeys as $groupKey):
                      $group = $groups[$groupKey];
                      // id grup dipakai untuk id elemen HTML (modal, dsb), aman dari karakter aneh
                      $groupId = md5($groupKey);
                      $firstRow = $group['rows'][0];
                  ?>
                    <tr>
                      <td>
                        <div class="d-flex px-2 py-1">
                          <div><img src="packages.png" class="avatar avatar-sm me-3" alt="Server"></div>
                          <div class="d-flex flex-column justify-content-center">
                            <h6 class="mb-0 text-sm"><?php echo htmlspecialchars($group['paket']); ?></h6>
                          </div>
                        </div>
                      </td>
                      <td class="align-middle text-center text-sm"><?php echo htmlspecialchars($group['kecepatan']); ?></td>
                      <td class="align-middle text-center text-sm">Rp <?php echo number_format($group['harga'], 0, ',', '.'); ?></td>
                      <td class="align-middle text-sm">
                        <ul class="server-list">
                          <?php foreach ($group['rows'] as $r): ?>
                            <li><?php echo htmlspecialchars($r['BRAND'] . '-' . $r['AREA']); ?></li>
                          <?php endforeach; ?>
                        </ul>
                      </td>
                      <td class="align-middle text-center">
                        <div class="action-buttons">
                          <button class="btn btn-xs btn-warning btn-edit-paket" data-bs-toggle="modal" data-bs-target="#pickEditModal<?php echo $groupId; ?>" title="Edit paket">Edit</button>
                          <button class="btn btn-xs btn-danger btn-delete-paket" data-bs-toggle="modal" data-bs-target="#pickDeleteModal<?php echo $groupId; ?>" title="Hapus paket">Hapus</button>
                        </div>
                      </td>
                    </tr>

                    <?php
                    // Modal-modal grup ini TIDAK ditaruh di dalam <tbody> (elemen non-<tr>
                    // di dalam tbody kena "foster parenting" oleh parser HTML & juga akan
                    // hilang saat lazy-load meng-append hanya innerHTML tbody). Ditampung
                    // dulu, baru di-echo di luar <table> lewat #packagesModalsContainer.
                    ob_start();
                    ?>

                    <!-- MODAL PILIH SERVER UNTUK EDIT -->
                    <div class="modal fade" id="pickEditModal<?php echo $groupId; ?>" tabindex="-1" aria-hidden="true">
                      <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                          <div class="modal-header bg-gradient-info text-white">
                            <h6 class="modal-title">Edit Paket - <?php echo htmlspecialchars($group['paket']); ?></h6>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                          </div>
                          <div class="modal-body">
                            <p class="text-sm mb-2">Paket ini dipasang di beberapa server. Pilih server yang ingin diedit:</p>
                            <div class="list-group">
                              <?php foreach ($group['rows'] as $r): ?>
                                <button type="button"
                                        class="list-group-item list-group-item-action"
                                        data-bs-toggle="modal"
                                        data-bs-target="#editModal<?php echo $r['id']; ?>"
                                        data-bs-dismiss="modal">
                                  <?php echo htmlspecialchars($r['BRAND'] . '-' . $r['AREA']); ?>
                                </button>
                              <?php endforeach; ?>
                            </div>
                          </div>
                          <div class="modal-footer">
                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                          </div>
                        </div>
                      </div>
                    </div>

                    <!-- MODAL PILIH SERVER UNTUK HAPUS -->
                    <div class="modal fade" id="pickDeleteModal<?php echo $groupId; ?>" tabindex="-1" aria-hidden="true">
                      <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                          <div class="modal-header bg-gradient-danger text-white">
                            <h6 class="modal-title">Hapus Paket - <?php echo htmlspecialchars($group['paket']); ?></h6>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                          </div>
                          <div class="modal-body">
                            <p class="text-sm mb-2">Pilih server mana yang paketnya ingin dihapus:</p>
                            <?php foreach ($group['rows'] as $r): ?>
                              <form method="POST" action="proses/deletepackages.php" class="d-flex align-items-center justify-content-between mb-2" onsubmit="return confirm('Yakin ingin menghapus paket ini dari server <?php echo htmlspecialchars($r['BRAND'] . '-' . $r['AREA']); ?>?');">
                                <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                                <input type="hidden" name="paket" value="<?php echo htmlspecialchars($r['PAKET']); ?>">
                                <input type="hidden" name="area" value="<?php echo htmlspecialchars($r['AREA']); ?>">
                                <input type="hidden" name="pemilik" value="<?php echo htmlspecialchars($r['PEMILIK']); ?>">
                                <span class="text-sm"><?php echo htmlspecialchars($r['BRAND'] . '-' . $r['AREA']); ?></span>
                                <button type="submit" class="btn btn-xs btn-danger">Hapus</button>
                              </form>
                            <?php endforeach; ?>
                          </div>
                          <div class="modal-footer">
                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Tutup</button>
                          </div>
                        </div>
                      </div>
                    </div>

                    <!-- MODAL EDIT per server (satu per baris asli) -->
                    <?php foreach ($group['rows'] as $data):
                      $paket_id = $data['id'];
                      $ceknama = $data['PEMILIK']; // untuk pool
                    ?>
                    <div class="modal fade" id="editModal<?php echo $paket_id; ?>" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
                      <div class="modal-dialog modal-dialog-centered modal-lg">
                        <div class="modal-content">
                          <form id="editForm<?php echo $paket_id; ?>" class="pppoe-edit-form" action="proses/editpackagespppoe.php" method="POST">
                            <div class="modal-header bg-gradient-info text-white">
                              <h6 class="modal-title">Edit Paket - <?php echo htmlspecialchars($data['PAKET']); ?> (<?php echo htmlspecialchars($data['BRAND'] . '-' . $data['AREA']); ?>)</h6>
                              <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">

                              <input type="hidden" name="id" value="<?php echo $paket_id; ?>">

                              <div class="row">
                                <div class="col-md-6 mb-2">
                                  <label class="form-label">Nama Paket</label>
                                  <input type="text" name="profileName" class="form-control form-control-sm" value="<?php echo htmlspecialchars($data['PAKET']); ?>" required pattern="^(?!\s)(.*\S)?$" title="Nama paket tidak boleh diawali atau diakhiri spasi" oninput="this.value=this.value.replace(/^\s+|\s+$/g,'')">
                                </div>
                                <div class="col-md-6 mb-2">
                                  <label class="form-label">Kecepatan (rate-limit)</label>
                                  <input type="text" name="ratelimit" class="form-control form-control-sm" value="<?php echo htmlspecialchars($data['KECEPATAN']); ?>" required>
                                </div>
                              </div>

                              <div class="row">
                                <div class="col-md-6 mb-2">
                                  <label class="form-label">Harga dasar </label>
                                  <input type="text" inputmode="numeric" name="harga" class="form-control form-control-sm input-harga-angka" value="<?php echo htmlspecialchars($data['HARGA']); ?>" required oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                                </div>
                                <div class="col-md-6 mb-2">
                                  <label class="form-label">Komisi (%)</label>
                                  <input type="text" name="komisi" class="form-control form-control-sm" value="<?php echo htmlspecialchars($data['komisi']); ?>" required>
                                </div>
                              </div>

                              <div class="row">
                                <div class="col-md-6 mb-2">
                                  <label class="form-label">Diskon Permanen Paket</label>
                                  <?php $diskonTypeCur = $data['DISKON_PERMANEN_TYPE'] ?? ''; ?>
                                  <select class="form-control form-control-sm" name="diskon_permanen_type">
                                    <option value="" <?php echo $diskonTypeCur === '' ? 'selected' : ''; ?>>Tidak ada diskon</option>
                                    <option value="nominal" <?php echo $diskonTypeCur === 'nominal' ? 'selected' : ''; ?>>Nominal (Rp)</option>
                                    <option value="persentase" <?php echo $diskonTypeCur === 'persentase' ? 'selected' : ''; ?>>Persentase (%)</option>
                                  </select>
                                </div>
                                <div class="col-md-6 mb-2">
                                  <label class="form-label">Nilai Diskon</label>
                                  <input type="number" min="0" step="any" name="diskon_permanen_nilai" class="form-control form-control-sm" value="<?php echo htmlspecialchars($data['DISKON_PERMANEN_NILAI'] ?? '0'); ?>">
                                  <div class="form-text">Otomatis memotong tagihan setiap invoice dibuat, selama pelanggan masih memakai paket ini.</div>
                                </div>
                              </div>

                              <div class="row">
                                <div class="col-12 mb-2">
                                  <label class="form-label">Sumber Profil RADIUS (untuk pelanggan mode RADIUS/MULTI)</label>
                                  <?php $radiusProfileSourceCur = $data['RADIUS_PROFILE_SOURCE'] ?? 'MIKROTIK'; ?>
                                  <select class="form-control form-control-sm" name="radius_profile_source">
                                    <option value="MIKROTIK" <?php echo $radiusProfileSourceCur === 'MIKROTIK' ? 'selected' : ''; ?>>Profil Mikrotik (default -- bandwidth diatur di PPP Profile router)</option>
                                    <option value="RADIUS_LANGSUNG" <?php echo $radiusProfileSourceCur === 'RADIUS_LANGSUNG' ? 'selected' : ''; ?>>RADIUS Langsung (bandwidth dari Kecepatan, dikirim lewat atribut RADIUS)</option>
                                  </select>
                                  <div class="form-text">Butuh toggle master aktif juga di Panel Kontrol FreeRADIUS &gt; tab Default.</div>
                                </div>
                              </div>

                              <?php
                              // ==== Ambil Data Pool ====
                              $sqlPool = "SELECT iplocal, ipawal, ipakhir FROM pool WHERE pemilik='$ceknama'";
                              $resultPool = mysqli_query($conn, $sqlPool);
                              $sqlPaket2 = "SELECT LOCAL, REMOTE FROM paket WHERE PEMILIK='$ceknama'";
                              $resultPaket2 = mysqli_query($conn, $sqlPaket2);

                              $usedLocal = [];
                              $usedRemot = [];
                              while ($row = mysqli_fetch_array($resultPaket2)) {
                                if (!empty($row['LOCAL'])) $usedLocal[] = $row['LOCAL'];
                                if (!empty($row['REMOTE'])) $usedRemot[] = $row['REMOTE'];
                              }

                              $poolAvailable = false;
                              $poolMap = [];
                              while ($row = mysqli_fetch_array($resultPool)) {
                                $iplocal = $row['iplocal'];
                                $ipremote = $row['ipawal'] . '-' . $row['ipakhir'];
                                $poolMap[$iplocal] = $ipremote;
                                if (!in_array($iplocal, $usedLocal) && !in_array($ipremote, $usedRemot)) {
                                  $poolAvailable = true;
                                }
                              }
                              ?>
                              <?php if (!$poolAvailable): ?>
                                  <a href="pool.php" class="btn btn-warning">Buat IP Pool</a>
                              <?php endif; ?>
                              <div class="mb-2">
                                <label for="local<?php echo $paket_id; ?>" class="form-label">Local IP ADDRESS (Server Asal)</label>
                                <select class="form-control form-control-sm" id="local<?php echo $paket_id; ?>" name="local" onchange="updateRemot<?php echo $paket_id; ?>()">
                                  <option value="<?php echo htmlspecialchars($data['LOCAL']); ?>"><?php echo htmlspecialchars($data['LOCAL']); ?></option>
                                  <option value="">-- Pilih Local IP --</option>
                                  <?php
                                  foreach ($poolMap as $local => $remote) {
                                    $disabled = (in_array($local, $usedLocal) && $local != $data['LOCAL']) ? 'disabled' : '';
                                    $selected = ($local == $data['LOCAL']) ? 'selected' : '';
                                    echo "<option value='$local' $disabled $selected>$local</option>";
                                  }
                                  ?>
                                </select>
                              </div>

                              <div class="mb-2">
                                <label for="remot<?php echo $paket_id; ?>" class="form-label">Remote IP ADDRESS (Server Asal)</label>
                                <select class="form-control form-control-sm" id="remot<?php echo $paket_id; ?>" name="remot">
                                  <option value="<?php echo htmlspecialchars($data['REMOTE']); ?>"><?php echo htmlspecialchars($data['REMOTE']); ?></option>
                                  <option value="">-- Pilih Remot IP --</option>
                                  <?php
                                  foreach ($poolMap as $local => $remote) {
                                    $disabled = (in_array($remote, $usedRemot) && $remote != $data['REMOTE']) ? 'disabled' : '';
                                    $selected = ($remote == $data['REMOTE']) ? 'selected' : '';
                                    echo "<option value='$remote' $disabled $selected>$remote</option>";
                                  }
                                  ?>
                                </select>
                              </div>

                              <div class="mb-2">
                                <label class="form-label">Server Area</label>
                                <div class="form-text mb-1">Server asal tetap pakai Local/Remote IP di atas. Centang server lain untuk ikut memasang paket ini di sana &mdash; pilih Local IP khusus untuk server baru itu di bawah checkbox-nya (server yang di-uncheck tidak dihapus otomatis).</div>
                                <input type="hidden" name="servers_payload">
                                <div id="editServerCheckboxes<?php echo $paket_id; ?>">
                                  <?php
                                  if ($current_user_id) {
                                    if ($AKSES == 'ASSISTANT') {
                                      $queryServerEdit = mysqli_query($conn, "SELECT DISTINCT PEMILIK, BRAND, AREA FROM server WHERE AREA IN ($area_list)");
                                    } else {
                                      $queryServerEdit = mysqli_query($conn, "SELECT DISTINCT PEMILIK, BRAND, AREA FROM server WHERE user_id = $current_user_id");
                                    }
                                    while ($rowServer = mysqli_fetch_assoc($queryServerEdit)) {
                                      $areaCb = htmlspecialchars($rowServer['AREA']);
                                      $pemilikCb = $rowServer['PEMILIK'];
                                      $brandCb = htmlspecialchars($rowServer['BRAND']);
                                      $isOriginCb = ($pemilikCb == $data['PEMILIK'] && $rowServer['AREA'] == $data['AREA']);
                                      $checkedCb = $isOriginCb ? 'checked' : '';
                                      $cbId = 'srv_' . $paket_id . '_' . md5($pemilikCb . $rowServer['AREA']);

                                      echo '<div class="form-check">';
                                      echo '<input class="form-check-input edit-server-cb" type="checkbox"'
                                         . ' data-pemilik="' . htmlspecialchars($pemilikCb, ENT_QUOTES, 'UTF-8') . '"'
                                         . ' data-brand="' . htmlspecialchars($rowServer['BRAND'], ENT_QUOTES, 'UTF-8') . '"'
                                         . ' data-area="' . htmlspecialchars($rowServer['AREA'], ENT_QUOTES, 'UTF-8') . '"'
                                         . ' data-is-origin="' . ($isOriginCb ? '1' : '0') . '"'
                                         . ' data-cb-id="' . $cbId . '"'
                                         . ($isOriginCb ? '' : ' onchange="toggleNewServerPool(this)"')
                                         . ' ' . $checkedCb
                                         . ' id="' . $cbId . '">';
                                      echo '<label class="form-check-label" for="' . $cbId . '">' . $brandCb . '-' . $areaCb . '</label>';
                                      echo '</div>';

                                      if (!$isOriginCb) {
                                        // Server ini punya PEMILIK berbeda dari server asal -> IP Pool-nya juga beda,
                                        // jadi butuh pilihan Local/Remote IP sendiri (tidak bisa pakai punya server asal).
                                        $pemilikCbEsc = mysqli_real_escape_string($conn, $pemilikCb);
                                        $resultPoolNew = mysqli_query($conn, "SELECT iplocal, ipawal, ipakhir FROM pool WHERE pemilik='$pemilikCbEsc'");
                                        $resultPaketNew = mysqli_query($conn, "SELECT LOCAL, REMOTE FROM paket WHERE PEMILIK='$pemilikCbEsc'");

                                        $usedLocalNew = [];
                                        $usedRemotNew = [];
                                        while ($rowN = mysqli_fetch_array($resultPaketNew)) {
                                          if (!empty($rowN['LOCAL'])) $usedLocalNew[] = $rowN['LOCAL'];
                                          if (!empty($rowN['REMOTE'])) $usedRemotNew[] = $rowN['REMOTE'];
                                        }

                                        $poolMapNew = [];
                                        while ($rowN = mysqli_fetch_array($resultPoolNew)) {
                                          $poolMapNew[$rowN['iplocal']] = $rowN['ipawal'] . '-' . $rowN['ipakhir'];
                                        }

                                        echo '<div class="ms-4 mb-2 d-none new-server-pool-wrap" id="poolWrap_' . $cbId . '">';
                                        echo '<div class="row g-2">';
                                        echo '<div class="col-md-6">';
                                        echo '<label class="form-label small mb-0">Local IP (' . $brandCb . '-' . $areaCb . ')</label>';
                                        echo '<select class="form-control form-control-sm" name="new_local_' . $cbId . '" onchange="updateNewServerRemot(this)">';
                                        echo '<option value="">-- Pilih Local IP --</option>';
                                        foreach ($poolMapNew as $localN => $remoteN) {
                                          $disabledN = in_array($localN, $usedLocalNew) ? 'disabled' : '';
                                          echo "<option value='" . htmlspecialchars($localN) . "' data-remote='" . htmlspecialchars($remoteN) . "' $disabledN>" . htmlspecialchars($localN) . "</option>";
                                        }
                                        echo '</select>';
                                        echo '</div>';
                                        echo '<div class="col-md-6">';
                                        echo '<label class="form-label small mb-0">Remote IP</label>';
                                        echo '<input type="text" class="form-control form-control-sm" name="new_remot_' . $cbId . '" readonly placeholder="Otomatis dari Local IP">';
                                        echo '</div>';
                                        echo '</div>';
                                        if (empty($poolMapNew)) {
                                          echo '<div class="text-danger small mt-1">Server ini belum punya IP Pool. <a href="pool.php" target="_blank">Buat IP Pool</a> dulu.</div>';
                                        }
                                        echo '</div>';
                                      }
                                    }
                                  }
                                  ?>
                                </div>
                              </div>

                            </div>
                            <div class="modal-footer">
                              <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                              <button type="submit" class="btn btn-primary btn-sm">Simpan</button>
                            </div>
                          </form>
                        </div>
                      </div>
                    </div>

                    <!-- JS untuk Local ? Remote (per baris asli) -->
                    <script>
                      var poolMap<?php echo $paket_id; ?> = <?php echo json_encode($poolMap); ?>;
                      function updateRemot<?php echo $paket_id; ?>() {
                        var localSelect = document.getElementById('local<?php echo $paket_id; ?>');
                        var remotSelect = document.getElementById('remot<?php echo $paket_id; ?>');
                        var selectedLocal = localSelect.value;
                        if (selectedLocal && poolMap<?php echo $paket_id; ?>[selectedLocal]) {
                          remotSelect.value = poolMap<?php echo $paket_id; ?>[selectedLocal];
                          remotSelect.disabled = false;
                        } else {
                          remotSelect.value = "";
                          remotSelect.disabled = true;
                        }
                      }
                      // Set state awal saat modal dibuka: jika local sudah ada isinya, enable remote
                      (function() {
                        var localSelect = document.getElementById('local<?php echo $paket_id; ?>');
                        var remotSelect = document.getElementById('remot<?php echo $paket_id; ?>');
                        if (localSelect && remotSelect) {
                          remotSelect.disabled = !localSelect.value;
                        }
                      })();
                    </script>

                    <?php endforeach; // end loop rows untuk modal edit ?>

                    <?php $groupModalsHtml[] = ob_get_clean(); ?>

                  <?php endforeach; // end loop groups ?>
                </tbody>
              </table>
              <div id="packagesLazyMeta" class="d-none" data-page="<?php echo (int)$pkgPage; ?>" data-total-pages="<?php echo (int)$pkgTotalPages; ?>"></div>

              <div class="text-center my-3" id="packagesLazyLoadWrap">
                <?php if (empty($groups)): ?>
                  <span class="text-muted small">Tidak ada paket yang cocok.</span>
                <?php elseif ($pkgPage >= $pkgTotalPages): ?>
                  <span class="text-muted small">Semua paket sudah dimuat.</span>
                <?php endif; ?>
                <div id="packagesLazyLoadIndicator" class="d-none">
                  <div class="spinner-border spinner-border-sm text-primary" role="status" aria-hidden="true"></div>
                  <span class="ms-2 text-secondary">Memuat paket berikutnya...</span>
                </div>
                <div id="packagesLazyLoadSentinel" style="height: 1px;"></div>
              </div>

              <div id="packagesModalsContainer"><?php echo implode('', $groupModalsHtml); ?></div>

              <!-- Modal Pengaturan Pendaftaran: pilih paket mana yang tampil di select form pendaftaran publik (pendaftaran_bot.php) -->
              <div class="modal fade" id="pendaftaranSettingModal" tabindex="-1" aria-labelledby="pendaftaranSettingModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered modal-lg">
                  <div class="modal-content">
                    <form method="POST" action="proses/save_tampil_daftar.php">
                      <div class="modal-header bg-gradient-primary text-white">
                        <h6 class="modal-title" id="pendaftaranSettingModalLabel"><i class="fas fa-globe me-1"></i> Pengaturan Pendaftaran</h6>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                      </div>
                      <div class="modal-body" style="max-height:65vh; overflow-y:auto;">
                        <p class="text-sm text-muted mb-2">
                          Centang paket yang ingin ditampilkan pada pilihan paket di link pendaftaran pelanggan (pendaftaran_bot.php).
                          Paket yang tidak dicentang tidak akan muncul di pilihan pelanggan saat mendaftar melalui brand terkait.
                        </p>

                        <div class="form-check form-switch mb-3 p-3 border rounded bg-light">
                          <input class="form-check-input" type="checkbox" role="switch" id="manualPaketEnabled" name="manual_paket_enabled" value="1" <?php echo $manualPaketEnabled ? 'checked' : ''; ?>>
                          <label class="form-check-label fw-bold" for="manualPaketEnabled">Aktifkan Input Paket Manual</label>
                          <p class="text-sm text-muted mb-0 mt-1">
                            Kalau diaktifkan, pilihan paket di form pendaftaran publik akan menampilkan opsi tambahan
                            <b>"Input Manual"</b> yang membuka kolom teks bebas, supaya pelanggan bisa mengetik nama
                            paket sendiri kalau paket yang diinginkan belum terdaftar di daftar di atas.
                          </p>
                        </div>

                        <?php if (empty($paketRows)): ?>
                          <div class="alert alert-warning small mb-0">Belum ada paket yang bisa diatur.</div>
                        <?php else: ?>
                          <div class="table-responsive">
                            <table class="table table-sm table-bordered align-middle">
                              <thead class="table-light">
                                <tr>
                                  <th style="width:40px;" class="text-center">
                                    <input type="checkbox" id="tampilDaftarCheckAll" title="Centang/hapus centang semua">
                                  </th>
                                  <th>Nama Paket</th>
                                  <th>Kecepatan</th>
                                  <th class="text-end">Harga</th>
                                  <th>Brand - Area</th>
                                </tr>
                              </thead>
                              <tbody>
                                <?php foreach ($paketRows as $pr): ?>
                                  <tr>
                                    <td class="text-center">
                                      <input class="form-check-input tampil-daftar-checkbox" type="checkbox" name="tampil_ids[]" value="<?php echo (int)$pr['id']; ?>" <?php echo !empty($pr['TAMPIL_DAFTAR']) ? 'checked' : ''; ?>>
                                    </td>
                                    <td><?php echo htmlspecialchars($pr['PAKET']); ?></td>
                                    <td><?php echo htmlspecialchars($pr['KECEPATAN']); ?></td>
                                    <td class="text-end">Rp <?php echo number_format((float)$pr['HARGA'], 0, ',', '.'); ?></td>
                                    <td><?php echo htmlspecialchars(($pr['BRAND'] ?? '') . '-' . ($pr['AREA'] ?? '')); ?></td>
                                  </tr>
                                <?php endforeach; ?>
                              </tbody>
                            </table>
                          </div>
                        <?php endif; ?>
                      </div>
                      <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save me-1"></i> Simpan</button>
                      </div>
                    </form>
                  </div>
                </div>
              </div>

              <script>
                (function() {
                  var checkAll = document.getElementById('tampilDaftarCheckAll');
                  if (!checkAll) return;
                  var checkboxes = document.querySelectorAll('.tampil-daftar-checkbox');
                  checkAll.addEventListener('change', function() {
                    checkboxes.forEach(function(cb) { cb.checked = checkAll.checked; });
                  });
                  checkboxes.forEach(function(cb) {
                    cb.addEventListener('change', function() {
                      checkAll.checked = document.querySelectorAll('.tampil-daftar-checkbox:checked').length === checkboxes.length;
                    });
                  });
                })();
              </script>


            </div>
          </div>
        </div>
      </div>
    </div>


<!-- DataTables & jQuery CDN -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/jquery.dataTables.min.css">
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>

<style>
  .dataTables_wrapper .dataTables_paginate .paginate_button {
    border-radius: 4px !important;
    border: 1px solid #ddd !important;
    background: #fff !important;
    color: #111 !important;
    font-weight: 500;
    margin: 0 2px !important;
    min-width: 32px;
    min-height: 32px;
    padding: 0 8px !important;
    box-shadow: none !important;
    transition: all 0.2s ease;
  }
  .dataTables_wrapper .dataTables_paginate .paginate_button.current {
    background: #0d6efd !important;
    color: #fff !important;
    border-color: #0d6efd !important;
  }
  .dataTables_wrapper .dataTables_paginate .paginate_button.disabled {
    color: #aaa !important;
    background: #f8f8f8 !important;
    border-color: #eee !important;
    cursor: not-allowed !important;
  }
  .dataTables_wrapper .dataTables_paginate .paginate_button:hover:not(.current):not(.disabled) {
    background: #e9ecef !important;
    color: #111 !important;
  }
  .dataTables_wrapper .dataTables_paginate .ellipsis { padding: 0 8px; color: #888; }
  .dataTables_wrapper .dataTables_paginate .paginate_button.previous,
  .dataTables_wrapper .dataTables_paginate .paginate_button.next { display: none !important; }
  .btn-xs { padding: 0.25rem 0.5rem; font-size: 0.75rem; }

  /* ===== Sync Profile Modal ===== */
  #syncProfileResultModal .modal-content {
    background-color: #fff;
    opacity: 1;
  }
  .sync-table-wrap {
    max-height: 55vh;
    overflow-y: auto;
  }
  .sync-profile-table {
    font-size: 12px;
    table-layout: fixed;
    width: 100%;
  }
  .sync-profile-table th,
  .sync-profile-table td {
    vertical-align: middle;
    word-break: break-word;
  }
  .sync-profile-table td code {
    display: block;
    white-space: normal;
    word-break: break-word;
    line-height: 1.4;
  }
  .sync-profile-table .form-control-sm {
    font-size: 12px;
    padding: 0.25rem 0.4rem;
  }
  .sync-profile-table thead th {
    position: sticky;
    top: 0;
    z-index: 1;
    background-color: #f8f9fa;
  }
  @media (max-width: 768px) {
    .sync-profile-table { font-size: 11px; }
  }
</style>

<script>
// Lazy-load infinite scroll + pencarian untuk tabel Paket PPPoE (self-POST ke
// halaman ini sendiri, 20 grup per fetch) - menggantikan DataTables client-side
// yang sebelumnya butuh seluruh data langsung dirender ke DOM.
//
// Pencarian (#searchInput) dikirim ke server (bukan filter teks di DOM) supaya
// hasilnya mencakup SELURUH data paket, bukan cuma grup yang kebetulan sudah
// termuat lewat infinite scroll.
(function() {
    var currentPage   = <?php echo (int)$pkgPage; ?>;
    var totalPages    = <?php echo (int)$pkgTotalPages; ?>;
    var currentSearch = <?php echo json_encode($searchTerm); ?>;
    var isLoading      = false;
    var searchDebounce  = null;

    var tableBody        = document.getElementById('dataTable');
    var modalsContainer  = document.getElementById('packagesModalsContainer');
    var indicator        = document.getElementById('packagesLazyLoadIndicator');
    var sentinel          = document.getElementById('packagesLazyLoadSentinel');
    var lazyWrap          = document.getElementById('packagesLazyLoadWrap');
    var searchInput        = document.getElementById('searchInput');

    if (!tableBody || !indicator || !sentinel) return;

    function showIndicator(show) {
        indicator.classList.toggle('d-none', !show);
    }

    function setWrapMessage(html) {
        if (!lazyWrap) return;
        lazyWrap.innerHTML = html;
        lazyWrap.appendChild(indicator);
        lazyWrap.appendChild(sentinel);
    }

    function executeScripts(container) {
        container.querySelectorAll('script').forEach(function(oldScript) {
            var newScript = document.createElement('script');
            Array.from(oldScript.attributes).forEach(function(attr) {
                newScript.setAttribute(attr.name, attr.value);
            });
            newScript.textContent = oldScript.textContent;
            if (oldScript.parentNode) oldScript.parentNode.replaceChild(newScript, oldScript);
        });
    }

    function fetchPage(page) {
        return fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            credentials: 'same-origin',
            body: 'page=' + page + '&search=' + encodeURIComponent(currentSearch)
        }).then(function(res) { return res.text(); });
    }

    function appendNextPage() {
        if (isLoading || currentPage >= totalPages) return Promise.resolve();
        isLoading = true;
        showIndicator(true);

        return fetchPage(currentPage + 1)
            .then(function(html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var newTableBody = doc.getElementById('dataTable');
                var newModalsContainer = doc.getElementById('packagesModalsContainer');
                var newMeta = doc.getElementById('packagesLazyMeta');
                if (!newTableBody) throw new Error('Gagal memuat data paket');

                tableBody.insertAdjacentHTML('beforeend', newTableBody.innerHTML);

                if (modalsContainer && newModalsContainer) {
                    modalsContainer.insertAdjacentHTML('beforeend', newModalsContainer.innerHTML);
                    executeScripts(modalsContainer);
                }

                var parsedPage = newMeta ? parseInt(newMeta.getAttribute('data-page'), 10) : NaN;
                currentPage = !isNaN(parsedPage) ? parsedPage : (currentPage + 1);

                if (currentPage >= totalPages) {
                    setWrapMessage('<span class="text-muted small">Semua paket sudah dimuat.</span>');
                }
            })
            .catch(function(err) {
                console.error('Gagal lazy load paket:', err);
            })
            .finally(function() {
                isLoading = false;
                showIndicator(false);
            });
    }

    function runSearch(term) {
        if (isLoading) return;
        isLoading = true;
        showIndicator(true);
        currentSearch = term;

        fetchPage(1)
            .then(function(html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var newTableBody = doc.getElementById('dataTable');
                var newModalsContainer = doc.getElementById('packagesModalsContainer');
                var newMeta = doc.getElementById('packagesLazyMeta');
                if (!newTableBody) throw new Error('Gagal memuat data paket');

                tableBody.innerHTML = newTableBody.innerHTML;
                if (modalsContainer) {
                    modalsContainer.innerHTML = newModalsContainer ? newModalsContainer.innerHTML : '';
                    executeScripts(modalsContainer);
                }

                var parsedPage = newMeta ? parseInt(newMeta.getAttribute('data-page'), 10) : 1;
                var parsedTotal = newMeta ? parseInt(newMeta.getAttribute('data-total-pages'), 10) : 1;
                currentPage = !isNaN(parsedPage) ? parsedPage : 1;
                totalPages = !isNaN(parsedTotal) ? parsedTotal : 1;

                if (!tableBody.querySelector('tr')) {
                    setWrapMessage('<span class="text-muted small">Tidak ada paket yang cocok.</span>');
                } else if (currentPage >= totalPages) {
                    setWrapMessage('<span class="text-muted small">Semua paket sudah dimuat.</span>');
                } else {
                    setWrapMessage('');
                }
            })
            .catch(function(err) {
                console.error('Gagal mencari paket:', err);
            })
            .finally(function() {
                isLoading = false;
                showIndicator(false);
            });
    }

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            var term = searchInput.value;
            clearTimeout(searchDebounce);
            searchDebounce = setTimeout(function() { runSearch(term); }, 350);
        });
    }

    var observer = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
            if (entry.isIntersecting) appendNextPage();
        });
    }, { root: null, rootMargin: '0px 0px 300px 0px', threshold: 0 });
    observer.observe(sentinel);

    window.addEventListener('scroll', function() {
        if (isLoading || currentPage >= totalPages) return;
        var nearBottom = window.innerHeight + window.scrollY >= document.body.offsetHeight - 300;
        if (nearBottom) appendNextPage();
    }, { passive: true });
})();
</script>

<script>
// Validasi nama paket tidak boleh ada spasi di awal/akhir pada form tambah
const dataForm = document.getElementById('dataForm');
if (dataForm) {
  dataForm.addEventListener('submit', function(e) {
    const nameInput = document.getElementById('profileName');
    if (nameInput) {
      const val = nameInput.value;
      if (/^\s|\s$/.test(val)) {
        alert('Nama paket tidak boleh diawali atau diakhiri spasi!');
        nameInput.focus();
        e.preventDefault();
        return false;
      }
    }
    const checkboxes = dataForm.querySelectorAll('input[name="servers[]"]:checked');
    if (checkboxes.length === 0) {
      alert('Pilih minimal satu Server Area!');
      e.preventDefault();
      return false;
    }
  });
}

// Validasi nama paket & minimal satu Product pada SEMUA form edit paket PPPoE.
// Pakai satu listener delegated (bukan generate <script> per baris) supaya tidak
// tergantung pada nama variable JS yang di-generate PHP per baris.
//
// PENTING: modal Edit ini ditaruh di dalam <tbody> tabel. Menurut spesifikasi
// parsing HTML5, tag <form> yang muncul langsung di konteks tabel langsung
// "ditutup" oleh browser saat itu juga secara struktur DOM (field-field di
// dalamnya TETAP terhubung ke form itu untuk keperluan submit, tapi TIDAK
// tercatat sebagai anak DOM dari <form>-nya). Karena itu form.querySelectorAll(...)
// selalu menemukan 0 elemen. Solusinya: pakai form.elements (daftar resmi field
// yang terhubung ke form menurut browser), bukan penelusuran struktur DOM.
document.addEventListener('submit', function(e) {
  var form = e.target;
  if (!form.classList || !form.classList.contains('pppoe-edit-form')) return;

  var nameInput = form.elements['profileName'];
  if (nameInput && /^\s|\s$/.test(nameInput.value)) {
    alert('Nama paket tidak boleh diawali atau diakhiri spasi!');
    nameInput.focus();
    e.preventDefault();
    return;
  }

  var serverEls = Array.prototype.filter.call(form.elements, function(el) {
    return el.classList && el.classList.contains('edit-server-cb');
  });
  var checked = serverEls.filter(function(el) { return el.checked; });
  if (checked.length === 0) {
    alert('Pilih minimal satu Server Area!');
    e.preventDefault();
    return;
  }

  // Susun payload per-server: server asal pakai Local/Remote IP di field utama,
  // server baru (PEMILIK beda) pakai Local/Remote IP dari pilihan khusus di bawah
  // checkbox-nya masing-masing (karena tiap PEMILIK punya IP Pool sendiri).
  var originLocalEl = form.elements['local'];
  var originRemotEl = form.elements['remot'];
  var payload = [];
  var missingPool = [];

  checked.forEach(function(cb) {
    var isOrigin = cb.getAttribute('data-is-origin') === '1';
    var pemilik = cb.getAttribute('data-pemilik');
    var brand = cb.getAttribute('data-brand');
    var area = cb.getAttribute('data-area');
    var local, remot;

    if (isOrigin) {
      local = originLocalEl ? originLocalEl.value : '';
      remot = originRemotEl ? originRemotEl.value : '';
    } else {
      var cbId = cb.getAttribute('data-cb-id');
      var localEl = form.elements['new_local_' + cbId];
      var remotEl = form.elements['new_remot_' + cbId];
      local = localEl ? localEl.value : '';
      remot = remotEl ? remotEl.value : '';
    }

    if (!local || !remot) {
      missingPool.push(brand + '-' + area);
      return;
    }

    payload.push({ pemilik: pemilik, brand: brand, area: area, local: local, remot: remot });
  });

  if (missingPool.length > 0) {
    alert('Pilih Local IP untuk server berikut dulu: ' + missingPool.join(', '));
    e.preventDefault();
    return;
  }

  var payloadField = form.elements['servers_payload'];
  if (payloadField) {
    payloadField.value = JSON.stringify(payload);
  }
});

// Tampilkan/sembunyikan pilihan Local IP khusus saat checkbox server baru dicentang/di-uncheck.
function toggleNewServerPool(cb) {
  var wrap = document.getElementById('poolWrap_' + cb.getAttribute('data-cb-id'));
  if (wrap) wrap.classList.toggle('d-none', !cb.checked);
}

// Isi otomatis Remote IP begitu Local IP (server baru) dipilih.
// Pakai selectEl.form (asosiasi form resmi browser), bukan closest()/querySelector,
// supaya tetap benar walau form ini ada di dalam <tbody> (lihat catatan di atas).
function updateNewServerRemot(selectEl) {
  var form = selectEl.form;
  if (!form) return;
  var suffix = selectEl.name.replace('new_local_', '');
  var remotEl = form.elements['new_remot_' + suffix];
  var opt = selectEl.options[selectEl.selectedIndex];
  if (remotEl) remotEl.value = opt ? (opt.getAttribute('data-remote') || '') : '';
}
</script>

<!-- ============================================================
     SCRIPT SYNC PROFILE MIKROTIK
     ============================================================ -->
<script>
function startSyncProfile(btn) {
  const ip    = btn.getAttribute('data-ip');
  const user  = btn.getAttribute('data-user');
  const pass  = btn.getAttribute('data-pass');
  const area  = btn.getAttribute('data-area');
  const brand = btn.getAttribute('data-brand');

  // Reset tampilan modal hasil dulu (sebelum modal ditampilkan)
  document.getElementById('sync_ip').value = ip;
  document.getElementById('sync_pemilik').value = user;
  document.getElementById('sync_server_label').textContent = `${brand} - ${area} (${ip})`;
  document.getElementById('syncLoading').classList.remove('d-none');
  document.getElementById('syncErrorBox').classList.add('d-none');
  document.getElementById('syncProfileTableWrap').classList.add('d-none');
  document.getElementById('syncSubmitBtn').classList.add('d-none');
  document.getElementById('syncProfileTbody').innerHTML = '';

  const pickModalEl   = document.getElementById('syncPilihServerModal');
  const resultModalEl = document.getElementById('syncProfileResultModal');
  const pickModal      = bootstrap.Modal.getInstance(pickModalEl) || new bootstrap.Modal(pickModalEl);
  const resultModal     = bootstrap.Modal.getInstance(resultModalEl) || new bootstrap.Modal(resultModalEl);

  // Tunggu modal pilih server BENAR-BENAR tertutup (backdrop hilang sempurna)
  // baru tampilkan modal hasil. Mencegah dua backdrop menumpuk yang membuat
  // halaman di belakang terlihat gelap seolah "datanya hilang".
  const onHidden = function () {
    pickModalEl.removeEventListener('hidden.bs.modal', onHidden);
    resultModal.show();
    fetchSyncProfileData(ip, user, pass, area, brand);
  };
  pickModalEl.addEventListener('hidden.bs.modal', onHidden);
  pickModal.hide();
}

function fetchSyncProfileData(ip, user, pass, area, brand) {
  const url = `getdata/get_mikrotik_profiles.php?ip=${encodeURIComponent(ip)}&user=${encodeURIComponent(user)}&password=${encodeURIComponent(pass)}&area=${encodeURIComponent(area)}&brand=${encodeURIComponent(brand)}`;

  fetch(url)
    .then(r => {
      if (!r.ok) {
        return r.text().then(txt => {
          throw new Error(`HTTP ${r.status}: ${txt.slice(0, 300)}`);
        });
      }
      return r.text();
    })
    .then(text => {
      let data;
      try {
        data = JSON.parse(text);
      } catch (parseErr) {
        throw new Error('Response bukan JSON valid: ' + text.slice(0, 300));
      }

      document.getElementById('syncLoading').classList.add('d-none');


      if (!data.success) {
        const box = document.getElementById('syncErrorBox');
        box.textContent = data.error || 'Gagal mengambil data dari MikroTik.';
        box.classList.remove('d-none');
        return;
      }

      if (!data.profiles || data.profiles.length === 0) {
        const box = document.getElementById('syncErrorBox');
        box.textContent = 'Tidak ada PPP Profile yang ditemukan di server ini.';
        box.classList.remove('d-none');
        return;
      }

      renderSyncProfileTable(data.profiles);
      document.getElementById('syncProfileTableWrap').classList.remove('d-none');

      const anyUnsynced = data.profiles.some(p => !p.already_synced);
      if (anyUnsynced) {
        document.getElementById('syncSubmitBtn').classList.remove('d-none');
      }
    })
    .catch(err => {
      document.getElementById('syncLoading').classList.add('d-none');
      const box = document.getElementById('syncErrorBox');
      box.textContent = 'Gagal mengambil data: ' + err.message;
      box.classList.remove('d-none');
      console.error('[Sync Profile Error]', err);
    });
}

function renderSyncProfileTable(profiles) {
  const tbody = document.getElementById('syncProfileTbody');
  tbody.innerHTML = '';

  profiles.forEach((p, idx) => {
    const tr = document.createElement('tr');
    if (p.already_synced) tr.classList.add('table-light', 'text-muted');

    tr.innerHTML = `
      <td class="text-center">
        <input type="checkbox" class="sync-row-check" data-idx="${idx}" ${p.already_synced ? 'disabled' : ''}>
      </td>
      <td>${escapeHtmlSync(p.name)}</td>
      <td><code class="text-xs">${escapeHtmlSync(p.rate_limit || '-')}</code></td>
      <td>
        <input type="text" inputmode="numeric" class="form-control form-control-sm sync-harga input-harga-angka" data-idx="${idx}" placeholder="0" ${p.already_synced ? 'disabled' : ''} oninput="this.value=this.value.replace(/[^0-9]/g,'')">
      </td>
      <td>
        <input type="text" class="form-control form-control-sm sync-local" data-idx="${idx}" value="${escapeHtmlSync(p.local_address || '')}" placeholder="Local IP" ${p.already_synced ? 'disabled' : ''}>
      </td>
      <td>
        <input type="text" class="form-control form-control-sm sync-remot" data-idx="${idx}" value="${escapeHtmlSync(p.remote_address || '')}" placeholder="Remote IP" ${p.already_synced ? 'disabled' : ''}>
      </td>
      <td class="text-center">
        ${p.already_synced ? '<span class="badge bg-success">Synced</span>' : '<span class="badge bg-warning text-dark">Baru</span>'}
      </td>
    `;
    tbody.appendChild(tr);
  });

  // Simpan data mentah untuk dipakai saat submit
  tbody.dataset.profiles = JSON.stringify(profiles);

  const checkAllEl = document.getElementById('syncCheckAll');
  if (checkAllEl) {
    checkAllEl.checked = false;
    checkAllEl.onclick = function() {
      document.querySelectorAll('.sync-row-check:not(:disabled)').forEach(cb => {
        cb.checked = this.checked;
      });
    };
  }
}

function escapeHtmlSync(str) {
  const div = document.createElement('div');
  div.textContent = str ?? '';
  return div.innerHTML;
}

const syncProfileFormEl = document.getElementById('syncProfileForm');
if (syncProfileFormEl) {
  syncProfileFormEl.addEventListener('submit', function(e) {
    e.preventDefault();

    const tbody = document.getElementById('syncProfileTbody');
    const profiles = JSON.parse(tbody.dataset.profiles || '[]');
    const checked = document.querySelectorAll('.sync-row-check:checked');

    if (checked.length === 0) {
      alert('Pilih minimal satu profile untuk di-import!');
      return;
    }

    const payload = [];
    checked.forEach(cb => {
      const idx = cb.getAttribute('data-idx');
      const profile = profiles[idx];
      const harga = document.querySelector(`.sync-harga[data-idx="${idx}"]`).value || 0;
      const local = document.querySelector(`.sync-local[data-idx="${idx}"]`).value || '';
      const remot = document.querySelector(`.sync-remot[data-idx="${idx}"]`).value || '';

      payload.push({
        name: profile.name,
        rate_limit: profile.rate_limit,
        harga: harga,
        local: local,
        remot: remot,
        komisi: 0
      });
    });

    document.getElementById('sync_profiles_payload').value = JSON.stringify(payload);
    this.submit();
  });
}
</script>

<!-- ============================================================
     VALIDASI GLOBAL: SEMUA INPUT HARGA HANYA BOLEH ANGKA
     Berlaku untuk modal Tambah Paket, Edit Paket, dan Sync Profile.
     - oninput sudah membuang karakter non-digit secara realtime (ditambahkan
       langsung pada masing-masing <input>).
     - Script ini menambah dua lapis pengaman:
       1) Blokir event 'paste' yang mengandung titik/koma/huruf -> dibersihkan dulu.
       2) Validasi ulang tepat sebelum form mana pun di-submit, sebagai jaring
          pengaman terakhir kalau ada cara lain memasukkan karakter selain angka.
     ============================================================ -->
<script>
(function() {
  function bersihkanAngka(str) {
    return (str || '').toString().replace(/[^0-9]/g, '');
  }

  // 1) Blokir paste teks berformat (mis. "1.000.000" atau "1,000,000")
  document.addEventListener('paste', function(e) {
    const target = e.target;
    if (target && target.classList && target.classList.contains('input-harga-angka')) {
      e.preventDefault();
      const pasted = (e.clipboardData || window.clipboardData).getData('text');
      const bersih = bersihkanAngka(pasted);
      const start = target.selectionStart ?? target.value.length;
      const end   = target.selectionEnd ?? target.value.length;
      target.value = target.value.slice(0, start) + bersih + target.value.slice(end);
      target.dispatchEvent(new Event('input', { bubbles: true }));
    }
  });

  // 2) Validasi ulang setiap field harga tepat sebelum form mana pun disubmit
  document.addEventListener('submit', function(e) {
    const form = e.target;
    if (!form || !form.querySelectorAll) return;
    form.querySelectorAll('.input-harga-angka').forEach(function(input) {
      const bersih = bersihkanAngka(input.value);
      if (bersih !== input.value) {
        input.value = bersih;
      }
    });
  }, true); // capture: jalan duluan sebelum handler submit lain (mis. validasi sync-profile)
})();
</script>

<?php require 'footer.php'; ?>