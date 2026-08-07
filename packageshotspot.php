<?php require 'header.php';
if ($AKSES == 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('Packages_Hotspot', $akses_menu, true)) {
        echo '<div class="container-fluid py-4"><div class="alert alert-danger">Anda tidak memiliki akses ke menu Packages Hotspot.</div></div>';
        require 'footer.php';
        exit;
    }
}

require 'routeros_api.class.php';
?>
<?php if (isset($_GET['statusnotif'])): ?>
  <?php if ($_GET['statusnotif'] == 'edited'): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
      ✅ Paket berhasil diperbarui!
      <?php if (!empty($_GET['installed'])): ?>
        Ditambahkan juga ke <?php echo (int)$_GET['installed']; ?> server baru.
      <?php endif; ?>
      <?php if (!empty($_GET['skipped'])): ?>
        (<?php echo (int)$_GET['skipped']; ?> server dilewati karena paket sudah ada)
      <?php endif; ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php elseif ($_GET['statusnotif'] == 'edit_failed'): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
      ❌ Gagal memperbarui paket.
      <?php if (!empty($_GET['text'])): ?>
        <div class="mt-2"><small><?php echo htmlspecialchars($_GET['text'], ENT_QUOTES, 'UTF-8'); ?></small></div>
      <?php endif; ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php elseif ($_GET['statusnotif'] == 'add_success'): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
      ✅ Paket hotspot berhasil ditambahkan.
      <?php if (!empty($_GET['text'])): ?>
        <div class="mt-2"><small><?php echo htmlspecialchars($_GET['text'], ENT_QUOTES, 'UTF-8'); ?></small></div>
      <?php endif; ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php elseif ($_GET['statusnotif'] == 'partial_success'): ?>
    <div class="alert alert-warning alert-dismissible fade show" role="alert">
      ⚠️ Paket hotspot sebagian berhasil ditambahkan.
      <?php if (!empty($_GET['text'])): ?>
        <div class="mt-2"><small><?php echo htmlspecialchars($_GET['text'], ENT_QUOTES, 'UTF-8'); ?></small></div>
      <?php endif; ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php elseif ($_GET['statusnotif'] == 'add_failed'): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
      ❌ Gagal menambahkan paket hotspot.
      <?php if (!empty($_GET['text'])): ?>
        <div class="mt-2"><small><?php echo htmlspecialchars($_GET['text'], ENT_QUOTES, 'UTF-8'); ?></small></div>
      <?php endif; ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php elseif ($_GET['statusnotif'] == 'sync_success'): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
      ✅ <?php echo isset($_GET['synced']) ? (int)$_GET['synced'] : 0; ?> profile berhasil di-sync dari MikroTik.
      <?php if (!empty($_GET['skipped'])): ?>
        (<?php echo (int)$_GET['skipped']; ?> dilewati karena sudah ada)
      <?php endif; ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php elseif ($_GET['statusnotif'] == 'sync_failed'): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
      ❌ Gagal sync profile dari MikroTik.
      <?php if (!empty($_GET['text'])): ?>
        <div class="mt-2"><small><?php echo htmlspecialchars($_GET['text'], ENT_QUOTES, 'UTF-8'); ?></small></div>
      <?php endif; ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php elseif ($_GET['msg'] == 'deleted'): ?>
    <div class="alert alert-warning alert-dismissible fade show" role="alert">
      🗑️ Paket berhasil dihapus.
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php elseif ($_GET['msg'] == 'delete_failed'): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
      ❌ Gagal menghapus paket.
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php elseif ($_GET['msg'] == 'not_found'): ?>
    <div class="alert alert-warning alert-dismissible fade show" role="alert">
      ⚠️ Paket tidak ditemukan.
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php elseif ($_GET['msg'] == 'still_in_use'): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
      ❌ Paket tidak dapat dihapus karena masih digunakan oleh user RADIUS.
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>
<?php endif; ?>


    <div class="container-fluid py-4">
        <div class="row">
            <div class="col-12">


                <div class="card">

                    <div class="card-body">

                        <label for="meta-tag" class="mt-4">Kode Redirect Otomatis (Meta Refresh):</label>
                        <textarea id="meta-tag" class="form-control" rows="3" readonly>
<meta http-equiv="refresh" content="5;url=<?php echo $domain; ?>/crm/login/">
    </textarea>




                        <button onclick="copyMetaTag()" class="btn btn-primary mt-2">Salin Kode</button>
                        <small id="copy-meta-success" class="form-text text-success mt-1" style="display:none;">Kode disalin!</small>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header pb-0">
                        <h6>Packages table</h6>



                        <!-- Menambahkan Clipboard.js untuk salin domain -->
                        <script src="https://cdnjs.cloudflare.com/ajax/libs/clipboard.js/2.0.6/clipboard.min.js"></script>
                        <script>
                            // Inisialisasi clipboard.js
                            var clipboard = new ClipboardJS('#copyButton');

                            clipboard.on('success', function(e) {
                                alert('Domain berhasil disalin: ' + e.text);
                            });

                            clipboard.on('error', function(e) {
                                alert('Gagal menyalin domain.');
                            });
                        </script>










                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#dataModal">
                            Add Paket Hotspot
                        </button>
                        <button type="button" class="btn btn-dark" data-bs-toggle="modal" data-bs-target="#syncPilihServerHotspotModal">
                            <i class="fas fa-sync me-1"></i> Sync Profile
                        </button>
                        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#importHotspotModal">
                            <i class="fas fa-file-excel me-1"></i> Import Paket Hotspot dari Excel
                        </button>
                        <a href="proses/download_template_hotspot.php" class="btn btn-info">
                            <i class="fas fa-download me-1"></i> Download Template Excel
                        </a>
                        <a href="proses/export_hotspot.php" class="btn btn-info">
                          <i class="fas fa-file-excel me-1"></i> Export Paket Hotspot ke XLSX
                        </a>

                        <!-- Modal Import Paket Hotspot Excel -->
                        <div class="modal fade" id="importHotspotModal" tabindex="-1" aria-labelledby="importHotspotModalLabel" aria-hidden="true">
                          <div class="modal-dialog">
                            <div class="modal-content">
                              <div class="modal-header">
                                <h5 class="modal-title" id="importHotspotModalLabel">Import Paket Hotspot dari Excel</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                              </div>
                              <form method="post" action="proses/import_hotspot.php" enctype="multipart/form-data">
                                <div class="modal-body">
                                  <div class="alert alert-info small mb-2">
                                    <b>Petunjuk:</b> Download template, isi sesuai urutan kolom berikut:<br>
                                    <b>Nama Paket | Rate Limit | Uptime | Harga | Komisi | Brand | Area | user_mikrotik | komisi_nominal</b><br>
                                    <span class="text-danger">* Format wajib .xlsx, baris pertama = judul kolom (boleh di-skip)</span>
                                  </div>
                                  <div class="mb-3">
                                    <label for="excel_file" class="form-label">Pilih file Excel (.xlsx)</label>
                                    <input type="file" class="form-control" id="excel_file" name="excel_file" accept=".xlsx" required>
                                  </div>
                                  <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" value="1" id="skip_first_row" name="skip_first_row" checked>
                                    <label class="form-check-label" for="skip_first_row">Lewati baris pertama (judul kolom)</label>
                                  </div>
                                </div>
                                <div class="modal-footer">
                                  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                  <button type="submit" class="btn btn-success">Import</button>
                                </div>
                              </form>
                            </div>
                          </div>
                        </div>

                        <!-- ============================================================
                             MODAL SYNC PROFILE HOTSPOT — PILIH SERVER
                             ============================================================ -->
                        <div class="modal fade" id="syncPilihServerHotspotModal" tabindex="-1" aria-labelledby="syncPilihServerHotspotModalLabel" aria-hidden="true">
                          <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content">
                              <div class="modal-header bg-gradient-primary text-white">
                                <h6 class="modal-title" id="syncPilihServerHotspotModalLabel">Sync Profile Hotspot MikroTik</h6>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                              </div>
                              <div class="modal-body">
                                <p class="text-sm mb-2">Pilih server yang ingin diambil daftar Hotspot User Profile-nya:</p>
                                <div class="list-group" id="syncServerHotspotList">
                                  <?php
                                  // Daftar server milik user (pola query sama seperti server.php)
                                  $current_user_id_sync = isset($_SESSION['id']) ? (int)$_SESSION['id'] : 0;
                                  $sqlSyncServerHotspot = ($AKSES == 'ASSISTANT')
                                      ? "SELECT * FROM server WHERE AREA IN ($area_list)"
                                      : "SELECT * FROM server WHERE user_id = $current_user_id_sync";
                                  $resSyncServerHotspot = mysqli_query($conn, $sqlSyncServerHotspot);

                                  if ($resSyncServerHotspot && mysqli_num_rows($resSyncServerHotspot) > 0) {
                                      while ($srv = mysqli_fetch_assoc($resSyncServerHotspot)) {
                                          $sIp    = htmlspecialchars($srv['IP'], ENT_QUOTES, 'UTF-8');
                                          $sUser  = htmlspecialchars($srv['PEMILIK'], ENT_QUOTES, 'UTF-8');
                                          $sPass  = htmlspecialchars($srv['PASSWORD'], ENT_QUOTES, 'UTF-8');
                                          $sArea  = htmlspecialchars($srv['AREA'], ENT_QUOTES, 'UTF-8');
                                          $sBrand = htmlspecialchars($srv['BRAND'], ENT_QUOTES, 'UTF-8');
                                          echo '<button type="button" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
                                                  onclick="startSyncProfileHotspot(this)"
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
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                              </div>
                            </div>
                          </div>
                        </div>

                        <!-- ============================================================
                             MODAL SYNC PROFILE HOTSPOT — HASIL (loading + tabel checkbox)
                             ============================================================ -->
                        <div class="modal fade" id="syncProfileHotspotResultModal" tabindex="-1" aria-labelledby="syncProfileHotspotResultModalLabel" aria-hidden="true" data-bs-backdrop="static">
                          <div class="modal-dialog modal-dialog-centered modal-xl">
                            <div class="modal-content">
                              <form id="syncProfileHotspotForm" method="POST" action="proses/sync_packageshotspot.php">
                                <input type="hidden" name="ip" id="sync_hotspot_ip">
                                <input type="hidden" name="pemilik" id="sync_hotspot_pemilik">
                                <input type="hidden" name="profiles" id="sync_hotspot_profiles_payload">

                                <div class="modal-header bg-gradient-primary text-white">
                                  <h6 class="modal-title" id="syncProfileHotspotResultModalLabel">Hotspot User Profile MikroTik — <span id="sync_hotspot_server_label"></span></h6>
                                  <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                </div>

                                <div class="modal-body" style="max-height:70vh; overflow-y:auto;">
                                  <div id="syncHotspotLoading" class="text-center py-4">
                                    <div class="spinner-border text-primary" role="status">
                                      <span class="visually-hidden">Loading...</span>
                                    </div>
                                    <div class="mt-2 text-sm">Mengambil daftar Hotspot User Profile dari MikroTik...</div>
                                  </div>

                                  <div id="syncHotspotErrorBox" class="alert alert-danger d-none"></div>

                                  <div id="syncHotspotProfileTableWrap" class="d-none">
                                    <p class="text-sm text-muted mb-2">
                                      Centang profile yang ingin di-import sebagai paket baru. Profile yang sudah tersinkron ditandai
                                      <span class="badge bg-success">Synced</span> dan tidak bisa dicentang ulang.
                                    </p>
                                    <div class="table-responsive sync-hotspot-table-wrap">
                                      <table class="table table-sm table-bordered align-middle sync-hotspot-profile-table" id="syncHotspotProfileTable">
                                        <thead class="table-light">
                                          <tr>
                                            <th style="width:36px;"><input type="checkbox" id="syncHotspotCheckAll"></th>
                                            <th style="min-width:140px;">Nama Profile</th>
                                            <th style="min-width:200px;">Rate Limit (Mikrotik)</th>
                                            <th style="width:120px;">Uptime</th>
                                            <th style="width:120px;">Harga dasar (Rp)</th>
                                            <th class="text-center" style="width:80px;">Status</th>
                                          </tr>
                                        </thead>
                                        <tbody id="syncHotspotProfileTbody">
                                          <!-- diisi via JS -->
                                        </tbody>
                                      </table>
                                    </div>
                                  </div>
                                </div>

                                <div class="modal-footer">
                                  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                                  <button type="submit" class="btn btn-primary d-none" id="syncHotspotSubmitBtn">
                                    <i class="fas fa-save me-1"></i> Import Profile Terpilih
                                  </button>
                                </div>
                              </form>
                            </div>
                          </div>
                        </div>


                        <!-- Modal -->
                        <div class="modal fade" id="dataModal" tabindex="-1" aria-labelledby="dataModalLabel" aria-hidden="true">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="dataModalLabel">Add Packages</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <form id="dataForm" action="proses/addpackageshotspot.php" method="post">



                                            <div class="mb-3">
                                                <label for="profileName" class="form-label">Packages name</label>
                                                <input type="text" class="form-control" id="profileName" name="profileName" required pattern="^(?!\s)(.*\S)?$" title="Nama paket tidak boleh diawali atau diakhiri spasi" oninput="this.value=this.value.replace(/^\s+|\s+$/g,'')">
                                            </div>

                                            <div class="mb-3">
                                                <label for="rateLimit" class="form-label">uptime</label>
                                                <input type="text" class="form-control" id="uptime" name="uptime" placeholder="">
                                                <!-- Penjelasan -->
                                                <div class="alert alert-info mt-2">
                                                    <strong>Format Uptime:</strong>
                                                    <ul class="mb-0 mt-2">
                                                        <li><b>1h</b> → 1 Jam</li>
                                                        <li><b>1d</b> → 1 Hari</li>
                                                        <li><b>1w</b> → 1 Minggu</li>
                                                        <li><b>1m</b> → 1 Bulan</li>
                                                    </ul>
                                                    <small class="text-muted">Format harus sesuai (huruf kecil) agar sistem dapat membacanya dengan benar.</small>
                                                </div>
                                            </div>

                                            <div class="mb-3">
                                                <label for="rateLimit" class="form-label">Harga dasar </label>
                                                <input type="number" class="form-control" id="harga" name="harga" placeholder="">
                                                <!-- Penjelasan -->
                                                <div class="alert alert-info mt-2">
                                                    <strong>Contoh Format Harga:</strong>
                                                    <ul class="mb-0 mt-2">
                                                        <li><b>Rp 10.000</b> → 10000</li>
                                                        <li><b>Rp 20.000</b> → 20000</li>
                                                    </ul>
                                                    <small class="text-muted">Masukkan angka tanpa titik atau koma (contoh: 10000 untuk Rp 10.000).</small>
                                                </div>
                                            </div>
                                            <div hidden class="mb-3">
                                                <label for="rateLimit" class="form-label">Komisi sales ( % )</label>
                                                <input type="number" class="form-control" id="komisi" name="komisi" value="0">
                                                <!-- Penjelasan -->
                                                <div class="alert alert-info mt-2">
                                                    <strong>Contoh Format Komisi:</strong>
                                                    <ul class="mb-0 mt-2">
                                                        <li><b>10%</b> → 10</li>
                                                        <li><b>20%</b> → 20</li>
                                                    </ul>
                                                    <small class="text-muted">Masukkan angka persen tanpa simbol % (contoh: 10 untuk 10%).</small>
                                                </div>
                                            </div>
                                            <div class="mb-3">
                                                <label for="rateLimit" class="form-label">Rate Limit speed</label>
                                                <input type="text" class="form-control" id="ratelimit" name="ratelimit" placeholder="e.g., 10M/10M">
                                            </div>


                      <div class="mb-3">
                        <label class="form-label">Server Area</label>
                        <div id="serverCheckboxesHotspot">
                          <?php
                          $queryServerAddHotspot = null;
                          if ($current_user_id) {
                              if ($AKSES == 'ASSISTANT') {
                                  $queryServerAddHotspot = mysqli_query($conn, "SELECT DISTINCT PEMILIK, BRAND, AREA FROM server WHERE AREA IN ($area_list)");
                              } else {
                                  $queryServerAddHotspot = mysqli_query($conn, "SELECT DISTINCT PEMILIK, BRAND, AREA FROM server WHERE user_id = $current_user_id");
                              }
                          }
                          if ($queryServerAddHotspot && mysqli_num_rows($queryServerAddHotspot) > 0) {
                              while ($rowServer = mysqli_fetch_assoc($queryServerAddHotspot)) {
                                  $areaCb = htmlspecialchars($rowServer['AREA']);
                                  $pemilikCb = $rowServer['PEMILIK'];
                                  $brandCb = htmlspecialchars($rowServer['BRAND']);
                                  $valueCb = json_encode(['pemilik' => $pemilikCb, 'brand' => $rowServer['BRAND'], 'area' => $rowServer['AREA']]);
                                  echo '<div class="form-check">';
                                  echo '<input class="form-check-input" type="checkbox" name="servers[]" value="' . htmlspecialchars($valueCb) . '" id="server_add_' . md5($pemilikCb . $rowServer['AREA']) . '">';
                                  echo '<label class="form-check-label">' . $brandCb . '-' . $areaCb . '</label>';
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
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                        <button type="submit" class="btn btn-primary" form="dataForm">Simpan</button>
                                    </div>
                                </div>
                            </div>
                        </div>









                    </div>
                    <div class="card-body px-0 pt-0 pb-2">
                        <div class="table-responsive p-0">


                            <style>
                                /* Menyembunyikan kolom tertentu saat tampilan mobile */
                                @media screen and (max-width: 600px) {

                                    td:nth-child(3),
                                    th:nth-child(3),
                                    td:nth-child(4),
                                    th:nth-child(4) {
                                        display: none;
                                    }
                                }

                                .small-text {
                                    font-size: 8px;
                                }

                                .alert-info ul li {
                                    font-weight: bold;
                                    color: #0c5460;
                                }

                                .alert-info small {
                                    font-style: italic;
                                    color: #6c757d;
                                }

                                /* Server list (grouping paket per area, sama seperti packages.php) */
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
                                .action-buttons { display: flex; gap: 0.25rem; flex-wrap: wrap; justify-content: center; }
                                .action-buttons .btn { margin: 0; }
                                .btn-xs { padding: 0.25rem 0.5rem; font-size: 0.75rem; }

                                /* ===== Sync Profile Hotspot Modal ===== */
                                #syncProfileHotspotResultModal .modal-content {
                                    background-color: #fff;
                                    opacity: 1;
                                }
                                .sync-hotspot-table-wrap {
                                    max-height: 55vh;
                                    overflow-y: auto;
                                }
                                .sync-hotspot-profile-table {
                                    font-size: 12px;
                                    table-layout: fixed;
                                    width: 100%;
                                }
                                .sync-hotspot-profile-table th,
                                .sync-hotspot-profile-table td {
                                    vertical-align: middle;
                                    word-break: break-word;
                                }
                                .sync-hotspot-profile-table td code {
                                    display: block;
                                    white-space: normal;
                                    word-break: break-word;
                                    line-height: 1.4;
                                }
                                .sync-hotspot-profile-table .form-control-sm {
                                    font-size: 12px;
                                    padding: 0.25rem 0.4rem;
                                }
                                .sync-hotspot-profile-table thead th {
                                    position: sticky;
                                    top: 0;
                                    z-index: 1;
                                    background-color: #f8f9fa;
                                }
                                @media (max-width: 768px) {
                                    .sync-hotspot-profile-table { font-size: 11px; }
                                }
                            </style>

                            <table id="tabel-hotspot" class="table align-items-center mb-0" style="font-size: 10px;">
                                <thead>
                                    <tr>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">Packages name</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2 text-center">Uptime</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2 text-center">Prize</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2 text-center">Server</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2 text-center">limit speed</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2 text-center">Action</th>
                                    </tr>
                                </thead>

                                <tbody id="dataTable">
                                    <?php
                                    $API = new RouterosAPI();

                                    // Ambil hanya paket hotspot yang server-nya dimiliki oleh user yang sedang login
                                    if ($AKSES != 'ASSISTANT') {
                                        $current_user_id = isset($_SESSION['id']) ? (int)$_SESSION['id'] : 0;
                                        $queryServerId = mysqli_query($conn, "SELECT PEMILIK FROM server WHERE user_id = $current_user_id");
                                        $userServerIds = [];
                                        while ($row = mysqli_fetch_assoc($queryServerId)) {
                                            $userServerIds[] = "'" . $row['PEMILIK'] . "'";
                                        }
                                        $userServerList = count($userServerIds) > 0 ? implode(",", $userServerIds) : "''";
                                        $sql = "SELECT * FROM paket_hotspot WHERE `pemilik` IN ($userServerList)";
                                    } else {
                                        // Untuk ASSISTANT, tetap gunakan $server_list dan filter area_list
                                        $sql = "SELECT * FROM paket_hotspot WHERE `area` IN ($area_list)";
                                    }

                                    $query = mysqli_query($conn, $sql);
                                    $paketHotspotRows = reseller_filter_rows($conn, reseller_collect_rows($query), 'hotspot');

                                    // ====== GROUPING: PAKET + HARGA + UPTIME + RATELIMIT ======
                                    // Baris dianggap "paket sama" hanya jika keempat nilai ini identik.
                                    // Tiap grup menyimpan daftar server (BRAND-AREA) dan daftar row asli
                                    // (untuk keperluan edit/hapus per-server), sama seperti packages.php (PPPoE).
                                    $groupsHotspot = [];
                                    foreach ($paketHotspotRows as $data) {
                                        $groupKeyHotspot = $data['paket'] . '|' . $data['harga'] . '|' . $data['uptime'] . '|' . $data['ratelimit'];

                                        if (!isset($groupsHotspot[$groupKeyHotspot])) {
                                            $groupsHotspot[$groupKeyHotspot] = [
                                                'paket'     => $data['paket'],
                                                'uptime'    => $data['uptime'],
                                                'harga'     => $data['harga'],
                                                'ratelimit' => $data['ratelimit'],
                                                'rows'      => [], // setiap entry = baris asli (1 server)
                                            ];
                                        }
                                        $groupsHotspot[$groupKeyHotspot]['rows'][] = $data;
                                    }

                                    // Lazy-load: 20 grup paket hotspot per fetch (self-POST ke halaman
                                    // ini sendiri) supaya daftar paket besar tidak dirender sekaligus.
                                    // Paging per GRUP (bukan per baris mentah) karena grouping di atas
                                    // sudah dihitung dari seluruh data, jadi tiap halaman selalu lengkap.
                                    $pktHsPageSize = 20;
                                    $pktHsPage = isset($_POST['page']) ? (int)$_POST['page'] : 1;
                                    if ($pktHsPage < 1) $pktHsPage = 1;
                                    $pktHsAllGroupKeys = array_keys($groupsHotspot);
                                    $pktHsTotalGroups  = count($pktHsAllGroupKeys);
                                    $pktHsTotalPages   = max(1, (int)ceil($pktHsTotalGroups / $pktHsPageSize));
                                    $pktHsPage         = min($pktHsPage, $pktHsTotalPages);
                                    $pktHsOffset       = ($pktHsPage - 1) * $pktHsPageSize;
                                    $pktHsPageGroupKeys = array_slice($pktHsAllGroupKeys, $pktHsOffset, $pktHsPageSize);
                                    $groupHotspotModalsHtml = [];

                                    foreach ($pktHsPageGroupKeys as $groupKeyHotspot):
                                        $groupHotspot = $groupsHotspot[$groupKeyHotspot];
                                        $groupIdHotspot = md5($groupKeyHotspot);
                                    ?>
                                    <tr>
                                      <td>
                                        <div class="d-flex px-2 py-1">
                                          <div><img src="packages.png" class="avatar avatar-sm me-3" alt="Server"></div>
                                          <div class="d-flex flex-column justify-content-center">
                                            <h6 class="mb-0 text-sm"><?php echo htmlspecialchars($groupHotspot['paket']); ?></h6>
                                          </div>
                                        </div>
                                      </td>
                                      <td class="align-middle text-center text-sm"><span class="text-xs font-weight-bold"><?php echo htmlspecialchars($groupHotspot['uptime']); ?></span></td>
                                      <td class="align-middle text-center text-sm"><span class="text-xs font-weight-bold"><?php echo htmlspecialchars($groupHotspot['harga']); ?></span></td>
                                      <td class="align-middle text-sm">
                                        <ul class="server-list">
                                          <?php foreach ($groupHotspot['rows'] as $r): ?>
                                            <li><?php echo htmlspecialchars($r['BRAND'] . '-' . $r['area']); ?></li>
                                          <?php endforeach; ?>
                                        </ul>
                                      </td>
                                      <td class="align-middle text-center text-sm"><span class="text-xs font-weight-bold"><?php echo htmlspecialchars($groupHotspot['ratelimit']); ?></span></td>
                                      <td class="align-middle text-center">
                                        <div class="action-buttons">
                                          <button class="btn btn-xs btn-warning btn-edit-paket-hotspot" data-bs-toggle="modal" data-bs-target="#pickEditModalHotspot<?php echo $groupIdHotspot; ?>" title="Edit paket"><i class="fas fa-edit"></i> Edit</button>
                                          <button class="btn btn-xs btn-danger btn-delete-paket-hotspot" data-bs-toggle="modal" data-bs-target="#pickDeleteModalHotspot<?php echo $groupIdHotspot; ?>" title="Hapus paket">Delete</button>
                                        </div>
                                      </td>
                                    </tr>

                                    <?php
                                    // Modal grup ini ditampung dulu (bukan langsung di-echo di dalam
                                    // <tbody>) supaya bisa dipindah ke luar <table> lewat
                                    // #packageshotspotModalsContainer - sama seperti packages.php.
                                    ob_start();
                                    ?>

                                    <!-- MODAL PILIH SERVER UNTUK EDIT -->
                                    <div class="modal fade" id="pickEditModalHotspot<?php echo $groupIdHotspot; ?>" tabindex="-1" aria-hidden="true">
                                      <div class="modal-dialog modal-dialog-centered">
                                        <div class="modal-content">
                                          <div class="modal-header bg-gradient-info text-white">
                                            <h6 class="modal-title">Edit Paket - <?php echo htmlspecialchars($groupHotspot['paket']); ?></h6>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                          </div>
                                          <div class="modal-body">
                                            <p class="text-sm mb-2">Paket ini dipasang di beberapa server. Pilih server yang ingin diedit:</p>
                                            <div class="list-group">
                                              <?php foreach ($groupHotspot['rows'] as $r): ?>
                                                <button type="button"
                                                        class="list-group-item list-group-item-action"
                                                        onclick='pickEditHotspot(<?php echo json_encode($r); ?>, "pickEditModalHotspot<?php echo $groupIdHotspot; ?>")'>
                                                  <?php echo htmlspecialchars($r['BRAND'] . '-' . $r['area']); ?>
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
                                    <div class="modal fade" id="pickDeleteModalHotspot<?php echo $groupIdHotspot; ?>" tabindex="-1" aria-hidden="true">
                                      <div class="modal-dialog modal-dialog-centered">
                                        <div class="modal-content">
                                          <div class="modal-header bg-gradient-danger text-white">
                                            <h6 class="modal-title">Hapus Paket - <?php echo htmlspecialchars($groupHotspot['paket']); ?></h6>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                          </div>
                                          <div class="modal-body">
                                            <p class="text-sm mb-2">Pilih server mana yang paketnya ingin dihapus:</p>
                                            <?php foreach ($groupHotspot['rows'] as $r): ?>
                                              <form action="proses/deletepackageshotspot.php" method="POST" class="d-flex align-items-center justify-content-between mb-2" onsubmit="return confirm('Yakin ingin menghapus paket ini dari server <?php echo htmlspecialchars($r['BRAND'] . '-' . $r['area']); ?>?');">
                                                <input type="hidden" name="id" value="<?php echo htmlspecialchars($r['id']); ?>">
                                                <input type="hidden" name="paket" value="<?php echo htmlspecialchars($r['paket']); ?>">
                                                <input type="hidden" name="area" value="<?php echo htmlspecialchars($r['area']); ?>">
                                                <input type="hidden" name="pemilik" value="<?php echo htmlspecialchars($r['pemilik']); ?>">
                                                <span class="text-sm"><?php echo htmlspecialchars($r['BRAND'] . '-' . $r['area']); ?></span>
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

                                    <?php $groupHotspotModalsHtml[] = ob_get_clean(); ?>
                                    <?php endforeach; // end loop groupsHotspot ?>
                                </tbody>
                            </table>
                            <div id="packageshotspotLazyMeta" class="d-none" data-page="<?php echo (int)$pktHsPage; ?>" data-total-pages="<?php echo (int)$pktHsTotalPages; ?>"></div>

                            <?php if ($pktHsPage < $pktHsTotalPages): ?>
                            <div class="text-center my-3" id="packageshotspotLazyLoadWrap">
                              <div id="packageshotspotLazyLoadIndicator" class="d-none">
                                <div class="spinner-border spinner-border-sm text-primary" role="status" aria-hidden="true"></div>
                                <span class="ms-2 text-secondary">Memuat paket hotspot berikutnya...</span>
                              </div>
                              <div id="packageshotspotLazyLoadSentinel" style="height: 1px;"></div>
                            </div>
                            <?php endif; ?>

                            <div id="packageshotspotModalsContainer"><?php echo implode('', $groupHotspotModalsHtml); ?></div>



                        </div>
                    </div>
                </div>
            </div>
        </div>




<!-- Modal Edit -->
<div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="editModalLabel">Edit Packages</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body">
        <form id="editForm" action="proses/editpackageshotspot.php" method="post">
          <input type="hidden" id="edit_id" name="id">

          <div class="mb-3">
            <label for="profileName" class="form-label">Packages name</label>
            <input type="text" class="form-control" id="edit_profileName" name="paket" required pattern="^(?!\s)(.*\S)?$" title="Nama paket tidak boleh diawali atau diakhiri spasi" oninput="this.value=this.value.replace(/^\s+|\s+$/g,'')">
          </div>

          <div class="mb-3">
            <label for="edit_uptime" class="form-label">Uptime</label>
            <input type="text" class="form-control" id="edit_uptime" name="uptime" placeholder="">
            <div class="alert alert-info mt-2">
              <strong>Format Waktu:</strong>
              <ul class="mb-0 mt-2">
                <li><b>1h</b> → 1 Jam</li>
                <li><b>1d</b> → 1 Hari</li>
                <li><b>1w</b> → 1 Minggu</li>
                <li><b>1m</b> → 1 Bulan</li>
              </ul>
              <small class="text-muted">Format harus sesuai (huruf kecil).</small>
            </div>
          </div>

          <div class="mb-3">
            <label for="edit_harga" class="form-label">Harga dasar</label>
            <input type="number" class="form-control" id="edit_harga" name="harga" placeholder="">
            <div class="alert alert-info mt-2">
              <strong>Contoh:</strong>
              <ul class="mb-0 mt-2">
                <li>Rp 10.000 → 10000</li>
                <li>Rp 20.000 → 20000</li>
              </ul>
              <small class="text-muted">Masukkan angka tanpa titik atau koma.</small>
            </div>
          </div>

          <div class="mb-3">
            <label for="edit_komisi" class="form-label">Komisi sales (%)</label>
            <input type="number" class="form-control" id="edit_komisi" name="komisi" placeholder="">
            <div class="alert alert-info mt-2">
              <strong>Contoh:</strong>
              <ul class="mb-0 mt-2">
                <li>10% → 10</li>
                <li>20% → 20</li>
              </ul>
              <small class="text-muted">Masukkan angka persen tanpa simbol %.</small>
            </div>
          </div>

          <div class="mb-3">
            <label for="edit_ratelimit" class="form-label">Rate Limit speed</label>
            <input type="text" class="form-control" id="edit_ratelimit" name="ratelimit" placeholder="e.g., 10M/10M">
          </div>

          <div class="mb-3">
            <label class="form-label">Server Area</label>
            <div class="form-text mb-1">Server asal tetap ter-update. Centang server lain untuk ikut memasang paket ini di sana (tidak menghapus server yang di-uncheck).</div>
            <div id="editServerCheckboxesHotspot">
              <?php
              $queryServerEditHotspot = null;
              if ($current_user_id) {
                  if ($AKSES == 'ASSISTANT') {
                      $queryServerEditHotspot = mysqli_query($conn, "SELECT DISTINCT PEMILIK, BRAND, AREA FROM server WHERE AREA IN ($area_list)");
                  } else {
                      $queryServerEditHotspot = mysqli_query($conn, "SELECT DISTINCT PEMILIK, BRAND, AREA FROM server WHERE user_id = $current_user_id");
                  }
              }
              if ($queryServerEditHotspot && mysqli_num_rows($queryServerEditHotspot) > 0) {
                  while ($rowServer = mysqli_fetch_assoc($queryServerEditHotspot)) {
                      $areaCb = htmlspecialchars($rowServer['AREA']);
                      $pemilikCb = $rowServer['PEMILIK'];
                      $brandCb = htmlspecialchars($rowServer['BRAND']);
                      $valueCb = json_encode(['pemilik' => $pemilikCb, 'brand' => $rowServer['BRAND'], 'area' => $rowServer['AREA']]);
                      echo '<div class="form-check">';
                      echo '<input class="form-check-input edit-server-hotspot-check" type="checkbox" name="servers[]" value="' . htmlspecialchars($valueCb) . '" data-pemilik="' . htmlspecialchars($pemilikCb, ENT_QUOTES, 'UTF-8') . '" data-area="' . $areaCb . '" id="server_edit_' . md5($pemilikCb . $rowServer['AREA']) . '">';
                      echo '<label class="form-check-label">' . $brandCb . '-' . $areaCb . '</label>';
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
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        <button type="submit" class="btn btn-primary" form="editForm">Simpan</button>
      </div>
    </div>
  </div>
</div>

<script>
// fungsi buka modal + isi data
function openEditModal(data) {
  document.getElementById("edit_id").value = data.id;
  document.getElementById("edit_profileName").value = data.paket;
  document.getElementById("edit_uptime").value = data.uptime;
  document.getElementById("edit_harga").value = data.harga;
  document.getElementById("edit_komisi").value = data.komisi;
  document.getElementById("edit_ratelimit").value = data.ratelimit;

  // Uncheck semua, lalu centang hanya server asal paket ini
  document.querySelectorAll('.edit-server-hotspot-check').forEach(function(cb) {
    cb.checked = (cb.getAttribute('data-pemilik') === data.pemilik && cb.getAttribute('data-area') === data.area);
  });

  new bootstrap.Modal(document.getElementById("editModal")).show();
}

// Dipanggil dari modal "pilih server untuk edit" (saat paket sama terpasang di beberapa server).
// Tutup dulu modal pilihan, tunggu benar-benar hilang, baru buka modal Edit supaya backdrop
// tidak menumpuk (pola yang sama dipakai di fitur Sync Profile).
function pickEditHotspot(data, pickModalId) {
  const pickModalEl = document.getElementById(pickModalId);
  const pickModal = bootstrap.Modal.getInstance(pickModalEl) || new bootstrap.Modal(pickModalEl);

  const onHidden = function () {
    pickModalEl.removeEventListener('hidden.bs.modal', onHidden);
    openEditModal(data);
  };
  pickModalEl.addEventListener('hidden.bs.modal', onHidden);
  pickModal.hide();
}
</script>

<script>
// Validasi JS fallback untuk form tambah
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
    const checkedServers = dataForm.querySelectorAll('input[name="servers[]"]:checked');
    if (checkedServers.length === 0) {
      alert('Pilih minimal satu Server Area!');
      e.preventDefault();
      return false;
    }
  });
}
// Validasi JS fallback untuk form edit
const editForm = document.getElementById('editForm');
if (editForm) {
  editForm.addEventListener('submit', function(e) {
    const nameInput = document.getElementById('edit_profileName');
    if (nameInput) {
      const val = nameInput.value;
      if (/^\s|\s$/.test(val)) {
        alert('Nama paket tidak boleh diawali atau diakhiri spasi!');
        nameInput.focus();
        e.preventDefault();
        return false;
      }
    }
    const checkedServers = editForm.querySelectorAll('input[name="servers[]"]:checked');
    if (checkedServers.length === 0) {
      alert('Pilih minimal satu Server Area!');
      e.preventDefault();
      return false;
    }
  });
}
</script>

<!-- DataTables & jQuery CDN -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/jquery.dataTables.min.css">
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>

<style>
    /* DataTables paging style (match packages.php/tables.php) */
    .dataTables_wrapper .dataTables_paginate .paginate_button {
        border-radius: 4px !important;
        border: 1px solid #ddd !important;
        background: #fff !important;
        color: #111 !important;
        font-weight: bold;
        margin: 0 2px !important;
        min-width: 32px;
        min-height: 32px;
        padding: 0 8px !important;
        box-shadow: none !important;
        transition: background 0.2s;
    }
    .dataTables_wrapper .dataTables_paginate .paginate_button.current {
        background: orange !important;
        color: #111 !important;
        border-color: orange !important;
    }
    .dataTables_wrapper .dataTables_paginate .paginate_button.disabled {
        color: #aaa !important;
        background: #f8f8f8 !important;
        border-color: #eee !important;
    }
    .dataTables_wrapper .dataTables_paginate .paginate_button:hover:not(.current) {
        background: #eee !important;
        color: #111 !important;
    }
    .dataTables_wrapper .dataTables_paginate .ellipsis {
        padding: 0 8px;
        color: #888;
    }
    /* Hide previous/next buttons, show only numbers */
    .dataTables_wrapper .dataTables_paginate .paginate_button.previous,
    .dataTables_wrapper .dataTables_paginate .paginate_button.next {
        display: none !important;
    }
</style>

<?php if ($pktHsPage < $pktHsTotalPages): ?>
<script>
// Lazy-load infinite scroll untuk tabel Paket Hotspot (self-POST ke halaman
// ini sendiri, 20 grup per fetch) - menggantikan DataTables client-side yang
// sebelumnya butuh seluruh data langsung dirender ke DOM.
(function() {
    var currentPage  = <?php echo (int)$pktHsPage; ?>;
    var totalPages   = <?php echo (int)$pktHsTotalPages; ?>;
    var isLoading    = false;

    var tableBody        = document.getElementById('dataTable');
    var modalsContainer  = document.getElementById('packageshotspotModalsContainer');
    var indicator        = document.getElementById('packageshotspotLazyLoadIndicator');
    var sentinel          = document.getElementById('packageshotspotLazyLoadSentinel');

    if (!tableBody || !indicator || !sentinel) return;

    function showIndicator(show) {
        indicator.classList.toggle('d-none', !show);
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

    function appendNextPage() {
        if (isLoading || currentPage >= totalPages) return Promise.resolve();
        isLoading = true;
        showIndicator(true);

        return fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            credentials: 'same-origin',
            body: 'page=' + (currentPage + 1)
        })
            .then(function(res) { return res.text(); })
            .then(function(html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var newTableBody = doc.getElementById('dataTable');
                var newModalsContainer = doc.getElementById('packageshotspotModalsContainer');
                var newMeta = doc.getElementById('packageshotspotLazyMeta');
                if (!newTableBody) throw new Error('Gagal memuat data paket hotspot');

                tableBody.insertAdjacentHTML('beforeend', newTableBody.innerHTML);

                if (modalsContainer && newModalsContainer) {
                    modalsContainer.insertAdjacentHTML('beforeend', newModalsContainer.innerHTML);
                    executeScripts(modalsContainer);
                }

                var parsedPage = newMeta ? parseInt(newMeta.getAttribute('data-page'), 10) : NaN;
                currentPage = !isNaN(parsedPage) ? parsedPage : (currentPage + 1);

                if (currentPage >= totalPages) {
                    var wrap = document.getElementById('packageshotspotLazyLoadWrap');
                    if (wrap) wrap.innerHTML = '<span class="text-muted small">Semua paket hotspot sudah dimuat.</span>';
                }
            })
            .catch(function(err) {
                console.error('Gagal lazy load paket hotspot:', err);
            })
            .finally(function() {
                isLoading = false;
                showIndicator(false);
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
<?php endif; ?>

<!-- ============================================================
     SCRIPT SYNC PROFILE HOTSPOT MIKROTIK
     ============================================================ -->
<script>
function startSyncProfileHotspot(btn) {
  const ip    = btn.getAttribute('data-ip');
  const user  = btn.getAttribute('data-user');
  const pass  = btn.getAttribute('data-pass');
  const area  = btn.getAttribute('data-area');
  const brand = btn.getAttribute('data-brand');

  // Reset tampilan modal hasil dulu (sebelum modal ditampilkan)
  document.getElementById('sync_hotspot_ip').value = ip;
  document.getElementById('sync_hotspot_pemilik').value = user;
  document.getElementById('sync_hotspot_server_label').textContent = `${brand} - ${area} (${ip})`;
  document.getElementById('syncHotspotLoading').classList.remove('d-none');
  document.getElementById('syncHotspotErrorBox').classList.add('d-none');
  document.getElementById('syncHotspotProfileTableWrap').classList.add('d-none');
  document.getElementById('syncHotspotSubmitBtn').classList.add('d-none');
  document.getElementById('syncHotspotProfileTbody').innerHTML = '';

  const pickModalEl   = document.getElementById('syncPilihServerHotspotModal');
  const resultModalEl = document.getElementById('syncProfileHotspotResultModal');
  const pickModal      = bootstrap.Modal.getInstance(pickModalEl) || new bootstrap.Modal(pickModalEl);
  const resultModal     = bootstrap.Modal.getInstance(resultModalEl) || new bootstrap.Modal(resultModalEl);

  // Tunggu modal pilih server BENAR-BENAR tertutup (backdrop hilang sempurna)
  // baru tampilkan modal hasil. Mencegah dua backdrop menumpuk yang membuat
  // halaman di belakang terlihat gelap seolah "datanya hilang".
  const onHidden = function () {
    pickModalEl.removeEventListener('hidden.bs.modal', onHidden);
    resultModal.show();
    fetchSyncProfileHotspotData(ip, user, pass, area, brand);
  };
  pickModalEl.addEventListener('hidden.bs.modal', onHidden);
  pickModal.hide();
}

function fetchSyncProfileHotspotData(ip, user, pass, area, brand) {
  const url = `getdata/get_mikrotik_hotspot_profiles.php?ip=${encodeURIComponent(ip)}&user=${encodeURIComponent(user)}&password=${encodeURIComponent(pass)}&area=${encodeURIComponent(area)}&brand=${encodeURIComponent(brand)}`;

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

      document.getElementById('syncHotspotLoading').classList.add('d-none');

      if (!data.success) {
        const box = document.getElementById('syncHotspotErrorBox');
        box.textContent = data.error || 'Gagal mengambil data dari MikroTik.';
        box.classList.remove('d-none');
        return;
      }

      if (!data.profiles || data.profiles.length === 0) {
        const box = document.getElementById('syncHotspotErrorBox');
        box.textContent = 'Tidak ada Hotspot User Profile yang ditemukan di server ini.';
        box.classList.remove('d-none');
        return;
      }

      renderSyncProfileHotspotTable(data.profiles);
      document.getElementById('syncHotspotProfileTableWrap').classList.remove('d-none');

      const anyUnsynced = data.profiles.some(p => !p.already_synced);
      if (anyUnsynced) {
        document.getElementById('syncHotspotSubmitBtn').classList.remove('d-none');
      }
    })
    .catch(err => {
      document.getElementById('syncHotspotLoading').classList.add('d-none');
      const box = document.getElementById('syncHotspotErrorBox');
      box.textContent = 'Gagal mengambil data: ' + err.message;
      box.classList.remove('d-none');
      console.error('[Sync Profile Hotspot Error]', err);
    });
}

function renderSyncProfileHotspotTable(profiles) {
  const tbody = document.getElementById('syncHotspotProfileTbody');
  tbody.innerHTML = '';

  profiles.forEach((p, idx) => {
    const tr = document.createElement('tr');
    if (p.already_synced) tr.classList.add('table-light', 'text-muted');

    tr.innerHTML = `
      <td class="text-center">
        <input type="checkbox" class="sync-hotspot-row-check" data-idx="${idx}" ${p.already_synced ? 'disabled' : ''}>
      </td>
      <td>${escapeHtmlSyncHotspot(p.name)}</td>
      <td><code class="text-xs">${escapeHtmlSyncHotspot(p.rate_limit || '-')}</code></td>
      <td>
        <input type="text" class="form-control form-control-sm sync-hotspot-uptime" data-idx="${idx}" value="${escapeHtmlSyncHotspot(p.uptime || '')}" placeholder="cth: 1d" ${p.already_synced ? 'disabled' : ''}>
      </td>
      <td>
        <input type="text" inputmode="numeric" class="form-control form-control-sm sync-hotspot-harga input-harga-angka" data-idx="${idx}" placeholder="0" ${p.already_synced ? 'disabled' : ''} oninput="this.value=this.value.replace(/[^0-9]/g,'')">
      </td>
      <td class="text-center">
        ${p.already_synced ? '<span class="badge bg-success">Synced</span>' : '<span class="badge bg-warning text-dark">Baru</span>'}
      </td>
    `;
    tbody.appendChild(tr);
  });

  // Simpan data mentah untuk dipakai saat submit
  tbody.dataset.profiles = JSON.stringify(profiles);

  const checkAllEl = document.getElementById('syncHotspotCheckAll');
  if (checkAllEl) {
    checkAllEl.checked = false;
    checkAllEl.onclick = function() {
      document.querySelectorAll('.sync-hotspot-row-check:not(:disabled)').forEach(cb => {
        cb.checked = this.checked;
      });
    };
  }
}

function escapeHtmlSyncHotspot(str) {
  const div = document.createElement('div');
  div.textContent = str ?? '';
  return div.innerHTML;
}

const syncProfileHotspotFormEl = document.getElementById('syncProfileHotspotForm');
if (syncProfileHotspotFormEl) {
  syncProfileHotspotFormEl.addEventListener('submit', function(e) {
    e.preventDefault();

    const tbody = document.getElementById('syncHotspotProfileTbody');
    const profiles = JSON.parse(tbody.dataset.profiles || '[]');
    const checked = document.querySelectorAll('.sync-hotspot-row-check:checked');

    if (checked.length === 0) {
      alert('Pilih minimal satu profile untuk di-import!');
      return;
    }

    const payload = [];
    checked.forEach(cb => {
      const idx = cb.getAttribute('data-idx');
      const profile = profiles[idx];
      const uptime = document.querySelector(`.sync-hotspot-uptime[data-idx="${idx}"]`).value || '';
      const harga  = document.querySelector(`.sync-hotspot-harga[data-idx="${idx}"]`).value || 0;

      payload.push({
        name: profile.name,
        rate_limit: profile.rate_limit,
        uptime: uptime,
        harga: harga,
        komisi: 0
      });
    });

    document.getElementById('sync_hotspot_profiles_payload').value = JSON.stringify(payload);
    this.submit();
  });
}
</script>

<?php require 'footer.php'; ?>