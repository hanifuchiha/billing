<?php require 'header.php';
if ($AKSES == 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('Pelanggan_berhenti', $akses_menu, true)) {
        echo '<div class="container-fluid py-4"><div class="alert alert-danger">Anda tidak memiliki akses ke menu Pelanggan Berhenti.</div></div>';
        require 'footer.php';
        exit;
    }
}
 ?>


    <?php
    $filter_bulan = isset($_GET['bulan']) ? $_GET['bulan'] : date('m');
    $filter_tahun = isset($_GET['tahun']) ? $_GET['tahun'] : date('Y');

    $_SESSION['filter_bulan'] = $filter_bulan;
    $_SESSION['filter_tahun'] = $filter_tahun;

    // Filter range tanggal sargable (bisa pakai index tanggal_berhenti),
    // dibanding MONTH()/YEAR() yang memaksa MySQL scan semua baris (fungsi di
    // kolom WHERE tidak bisa pakai index).
    $where = [];
    $berhentiTahunUntukRange = ($filter_tahun !== 'all') ? intval($filter_tahun) : (int)date('Y');
    if ($filter_bulan !== 'all') {
      $berhentiBulanUntukRange = intval($filter_bulan);
      $rangeStart = sprintf('%04d-%02d-01', $berhentiTahunUntukRange, $berhentiBulanUntukRange);
      $rangeEndTs = strtotime('+1 month', strtotime($rangeStart));
      $rangeEnd = date('Y-m-d', $rangeEndTs);
      $where[] = "tanggal_berhenti >= '" . mysqli_real_escape_string($conn, $rangeStart) . "' AND tanggal_berhenti < '" . mysqli_real_escape_string($conn, $rangeEnd) . "'";
    } elseif ($filter_tahun !== 'all') {
      $rangeStart = sprintf('%04d-01-01', $berhentiTahunUntukRange);
      $rangeEnd = sprintf('%04d-01-01', $berhentiTahunUntukRange + 1);
      $where[] = "tanggal_berhenti >= '" . mysqli_real_escape_string($conn, $rangeStart) . "' AND tanggal_berhenti < '" . mysqli_real_escape_string($conn, $rangeEnd) . "'";
    }

    // Scoping kepemilikan server (pola sama dgn dashboard.php) -- $current_user_id
    // utk ASSISTANT berisi id akun OWNER (lihat cek-sesi.php), BUKAN id
    // assistant itu sendiri, jadi "WHERE user_id = $current_user_id" polos
    // SELALU balik SEMUA server milik owner (bocor lintas-area).
    $userServerIds = [];
    if ($AKSES === 'ASSISTANT') {
      if (isset($area_list) && trim((string)$area_list) !== '') {
        $queryServerId = mysqli_query($conn, "SELECT PEMILIK FROM server WHERE AREA IN ($area_list)");
        if ($queryServerId) {
          while($row = mysqli_fetch_assoc($queryServerId)) {
            $userServerIds[] = "'".$row['PEMILIK']."'";
          }
        }
      }
    } else {
      $queryServerId = mysqli_query($conn, "SELECT PEMILIK FROM server WHERE user_id = $current_user_id");
      while($row = mysqli_fetch_assoc($queryServerId)) {
        $userServerIds[] = "'".$row['PEMILIK']."'";
      }
    }
    $userServerList = count($userServerIds) > 0 ? implode(",", $userServerIds) : "''";

    $where[] = "pemilik IN ($userServerList)";
    $where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // Lazy-load: 20 baris per fetch (self-GET ke halaman ini sendiri dengan filter
    // bulan/tahun yang sama + parameter ppage) supaya daftar pelanggan berhenti yang
    // besar (terutama saat filter "Semua" bulan/tahun) tidak dirender sekaligus.
    $berhentiPageSize = 20;
    $berhentiPage = isset($_GET['ppage']) ? (int)$_GET['ppage'] : 1;
    if ($berhentiPage < 1) {
        $berhentiPage = 1;
    }
    $berhentiCountRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM pelanggan_berhenti $where_sql"));
    $berhentiTotalRows = (int)($berhentiCountRow['total'] ?? 0);
    $berhentiTotalPages = max(1, (int)ceil($berhentiTotalRows / $berhentiPageSize));
    $berhentiPage = min($berhentiPage, $berhentiTotalPages);
    $berhentiOffset = ($berhentiPage - 1) * $berhentiPageSize;

    $sql = "SELECT idpel, nama, paket, harga, alamat, nowa, alasan, tanggal_berhenti, keterangan, pemilik FROM pelanggan_berhenti $where_sql ORDER BY tanggal_berhenti DESC LIMIT $berhentiPageSize OFFSET $berhentiOffset";
    $result = mysqli_query($conn, $sql);

    // Bangun daftar bot milik user untuk checkbox group broadcast
    $bot_options = ['RANDOM'];
    if (!empty($ceknama)) {
        $bot_option_query = mysqli_query($conn, "SELECT DISTINCT namebot FROM `botwa` WHERE `pemilik` ='" . mysqli_real_escape_string($conn, $ceknama) . "'" . botAccessWhereClause($conn, $AKSES, $assigned_bot_ids ?? [], $asistant_name ?? '') . " ORDER BY namebot ASC");
        if ($bot_option_query) {
            while ($bot_option_row = mysqli_fetch_assoc($bot_option_query)) {
                $bot_name_opt = trim((string)($bot_option_row['namebot'] ?? ''));
                if ($bot_name_opt !== '') {
                    $bot_options[] = $bot_name_opt;
                }
            }
        }
    }
    ?>

  <style>
    .bot-checkbox-group {
        max-height: 220px;
        overflow-y: auto;
        background: #fff;
        border: 1px solid #cbd5e1;
        border-radius: .5rem;
        padding: .5rem;
    }
  </style>

  <div class="card shadow">
    <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
      <h2 class="mb-0">Daftar Pelanggan Berhenti Berlangganan</h2>
      <button class="btn btn-light btn-sm" onclick="location.reload()"><i class="fas fa-sync-alt me-1"></i>Refresh</button>
    </div>
    <div class="card-body">
      <!-- Filter Bulan & Tahun -->
      <form method="get" class="row g-2 align-items-end mb-4">
        <div class="col-auto">
          <label for="bulan" class="form-label mb-0">Bulan</label>
          <select name="bulan" id="bulan" class="form-select">
            <option value="all" <?php if($filter_bulan==='all') echo 'selected'; ?>>Semua</option>
            <?php
            for ($b = 1; $b <= 12; $b++) {
              $selected = ($b == $filter_bulan) ? 'selected' : '';
              printf('<option value="%02d" %s>%s</option>', $b, $selected, date('F', mktime(0,0,0,$b,1)));
            }
            ?>
          </select>
        </div>
        <div class="col-auto">
          <label for="tahun" class="form-label mb-0">Tahun</label>
          <select name="tahun" id="tahun" class="form-select">
            <option value="all" <?php if($filter_tahun==='all') echo 'selected'; ?>>Semua</option>
            <?php
            $tahun_sekarang = (int)date('Y');
            for ($t = $tahun_sekarang-5; $t <= $tahun_sekarang+1; $t++) {
              $selected = ($t == $filter_tahun) ? 'selected' : '';
              echo "<option value='$t' $selected>$t</option>";
            }
            ?>
          </select>
        </div>
        <div class="col-auto">
          <button type="submit" class="btn btn-danger">Tampilkan</button>
        </div>
        <div class="col-md-4">
          <label for="searchInput" class="form-label mb-0">Cari Pelanggan</label>
          <div class="input-group">
            <input type="text" class="form-control" id="searchInput" placeholder="Cari berdasarkan ID, nama, alamat, dll...">
            <button class="btn btn-outline-secondary" type="button" id="clearSearch" title="Clear Search">
              <i class="fas fa-times"></i>
            </button>
          </div>
          <small class="text-muted mt-1 d-block" id="searchResultCount"></small>
        </div>
      </form>
      <!-- Form Broadcast -->
      <div class="card mt-4">
        <div class="card-header bg-info text-white">
          <h5><i class="fas fa-bullhorn me-2"></i>Broadcast Pesan Iklan</h5>
        </div>
        <div class="card-body">
          <form method="post" action="proses/broadcast_berhenti.php" id="formBroadcastBerhenti">
            <input type="hidden" name="filter_bulan" value="<?php echo htmlspecialchars($filter_bulan); ?>">
            <input type="hidden" name="filter_tahun" value="<?php echo htmlspecialchars($filter_tahun); ?>">
            <div class="mb-3">
              <label for="pesan_broadcast" class="form-label">Pesan Broadcast</label>
              <textarea class="form-control" id="pesan_broadcast" name="pesan_broadcast" rows="4" required placeholder="Masukkan pesan iklan yang akan dikirim..."></textarea>
            </div>
            <div class="mb-3">
              <label class="form-label">Pilih Bot WhatsApp</label>
              <div class="bot-checkbox-group" id="grp_bot_broadcast" data-hidden-target="bot_broadcast">
                <div class="form-check">
                  <input class="form-check-input bot-random-toggle" type="checkbox" id="bot_broadcast_RANDOM" value="RANDOM" checked>
                  <label class="form-check-label fw-bold" for="bot_broadcast_RANDOM">ACAK dari SEMUA BOT</label>
                </div>
                <hr class="my-2">
                <?php foreach ($bot_options as $bot_opt):
                    if ($bot_opt === 'RANDOM') continue;
                    $bot_opt_safe = htmlspecialchars($bot_opt, ENT_QUOTES, 'UTF-8');
                ?>
                <div class="form-check">
                  <input class="form-check-input bot-specific-checkbox" type="checkbox" id="bot_broadcast_<?= $bot_opt_safe ?>" value="<?= $bot_opt_safe ?>">
                  <label class="form-check-label" for="bot_broadcast_<?= $bot_opt_safe ?>"><?= $bot_opt_safe ?></label>
                </div>
                <?php endforeach; ?>
              </div>
              <div class="form-text">Centang <b>ACAK dari SEMUA BOT</b> untuk random dari semua bot. Centang satu bot saja untuk selalu memakai bot itu. Centang beberapa bot untuk random hanya dari bot yang dicentang.</div>
              <!-- Hidden input inilah yang benar-benar dikirim sebagai field "bot_broadcast" -->
              <input type="hidden" id="bot_broadcast" name="bot_broadcast" value="RANDOM">
            </div>
            <button type="submit" class="btn btn-info" onclick="return confirm('Apakah Anda yakin ingin mengirim broadcast ke semua pelanggan berhenti?')">Kirim Broadcast</button>
          </form>
        </div>
      </div>
      <!-- End Form Broadcast -->
      <div class="table-responsive">
        <table class="table table-striped" id="pelangganTable">          <tbody id="berhentiTableBody">
            <?php
            if ($result && mysqli_num_rows($result) > 0) {
              while ($row = mysqli_fetch_assoc($result)) {
                echo '<tr>';
                echo '<td class="text-center">';
                echo '<div class="btn-group-vertical" role="group">';
                echo '<button class="btn btn-success btn-sm mb-1" onclick="registUlang(\'' . htmlspecialchars($row['idpel']) . '\')"><i class="fas fa-user-plus"></i> Regist Ulang</button>';
                echo '<button class="btn btn-danger btn-sm" onclick="hapusPermanen(\'' . htmlspecialchars($row['idpel']) . '\')"><i class="fas fa-trash"></i> Hapus Permanen</button>';
                echo '</div>';
                echo '</td>';

                echo '<td>';
                echo '<div class="row g-1">';
                echo '<div class="col-12"><strong>IDPEL:</strong> ' . htmlspecialchars($row['idpel']) . '</div>';
                echo '<div class="col-12"><strong>Nama:</strong> ' . htmlspecialchars($row['nama']) . '</div>';
                echo '<div class="col-12"><strong>Paket:</strong> ' . htmlspecialchars($row['paket']) . '</div>';
                echo '</div>';
                echo '</td>';

                echo '<td>';
                echo '<div class="row g-1">';
                echo '<div class="col-12"><strong>Server:</strong> ' . htmlspecialchars($row['pemilik']) . '</div>';
                echo '<div class="col-12"><strong>Alamat:</strong> ' . htmlspecialchars($row['alamat']) . '</div>';
                echo '<div class="col-12"><strong>No. WA:</strong> ' . htmlspecialchars($row['nowa']) . '</div>';
                echo '</div>';
                echo '</td>';

                echo '<td>';
                echo '<div class="row g-1">';
                echo '<div class="col-12"><strong>Alasan:</strong> ' . htmlspecialchars($row['alasan']) . '</div>';
                echo '<div class="col-12"><strong>Tgl Berhenti:</strong> ' . htmlspecialchars($row['tanggal_berhenti']) . '</div>';
                echo '<div class="col-12"><strong>Keterangan:</strong> ' . htmlspecialchars($row['keterangan']) . '</div>';
                echo '</div>';
                echo '</td>';

                echo '</tr>';
              }
            } else {
              echo '<tr><td colspan="4" class="text-center text-muted">Tidak ada data pelanggan berhenti untuk periode ini.</td></tr>';
            }
            ?>
            <tr id="berhentiLazySentinel" style="height:1px;"><td colspan="4" style="padding:0;border:0;"></td></tr>
          </tbody>
        </table>
        <div id="berhentiLazyLoadWrap" class="text-center py-3 <?php echo $berhentiPage >= $berhentiTotalPages ? 'd-none' : ''; ?>">
          <div id="berhentiLazyLoadIndicator" class="spinner-border spinner-border-sm text-primary d-none" role="status"><span class="visually-hidden">Loading...</span></div>
          <span id="berhentiLazyLoadStatusText" class="text-secondary text-xs"></span>
        </div>
        <div id="berhentiLazyMeta" class="d-none" data-page="<?php echo (int)$berhentiPage; ?>" data-total-pages="<?php echo (int)$berhentiTotalPages; ?>"></div>
        <?php if ($berhentiPage < $berhentiTotalPages): ?>
        <script>
        (function() {
            var currentPage = <?php echo (int)$berhentiPage; ?>;
            var totalPages  = <?php echo (int)$berhentiTotalPages; ?>;
            var isLoading   = false;

            var tableBody  = document.getElementById('berhentiTableBody');
            var sentinelRow = document.getElementById('berhentiLazySentinel');
            var lazyWrap   = document.getElementById('berhentiLazyLoadWrap');
            var lazyIndicator = document.getElementById('berhentiLazyLoadIndicator');
            var lazyStatusText = document.getElementById('berhentiLazyLoadStatusText');

            if (!tableBody || !sentinelRow) return;

            function updateStatusText() {
                if (!lazyStatusText) return;
                lazyStatusText.textContent = (currentPage >= totalPages) ? 'Semua data sudah dimuat.' : '';
            }

            function buildNextPageUrl(nextPage) {
                var url = new URL(window.location.href);
                url.searchParams.set('ppage', String(nextPage));
                return url.toString();
            }

            function appendNextPage() {
                if (isLoading || currentPage >= totalPages) return Promise.resolve();
                isLoading = true;
                if (lazyWrap) lazyWrap.classList.remove('d-none');
                if (lazyIndicator) lazyIndicator.classList.remove('d-none');

                return fetch(buildNextPageUrl(currentPage + 1), { method: 'GET', credentials: 'same-origin' })
                    .then(function(res) { return res.text(); })
                    .then(function(html) {
                        var doc = new DOMParser().parseFromString(html, 'text/html');
                        var newTableBody = doc.getElementById('berhentiTableBody');
                        var newMeta = doc.getElementById('berhentiLazyMeta');
                        if (!newTableBody) throw new Error('Gagal memuat data');

                        var newSentinel = newTableBody.querySelector('#berhentiLazySentinel');
                        if (newSentinel) newSentinel.remove();
                        tableBody.insertAdjacentHTML('beforeend', newTableBody.innerHTML);
                        tableBody.appendChild(sentinelRow);

                        var parsedPage = newMeta ? parseInt(newMeta.getAttribute('data-page'), 10) : NaN;
                        currentPage = !isNaN(parsedPage) ? parsedPage : (currentPage + 1);
                        updateStatusText();
                        if (currentPage >= totalPages && lazyWrap) lazyWrap.classList.add('d-none');
                    })
                    .catch(function(err) { console.error('Gagal lazy load pelanggan berhenti:', err); })
                    .finally(function() {
                        isLoading = false;
                        if (lazyIndicator) lazyIndicator.classList.add('d-none');
                    });
            }

            // Dipakai search box supaya pencarian mencakup semua data, bukan cuma
            // yang kebetulan sudah ke-scroll.
            window.berhentiLoadAllRemaining = function() {
                function step() {
                    if (currentPage >= totalPages) return Promise.resolve();
                    return appendNextPage().then(step);
                }
                return step();
            };

            var observer = new IntersectionObserver(function(entries) {
                entries.forEach(function(entry) {
                    if (entry.isIntersecting) appendNextPage();
                });
            }, { root: null, rootMargin: '0px 0px 300px 0px', threshold: 0 });
            observer.observe(sentinelRow);

            window.addEventListener('scroll', function() {
                if (isLoading || currentPage >= totalPages) return;
                var nearBottom = window.innerHeight + window.scrollY >= document.body.offsetHeight - 300;
                if (nearBottom) appendNextPage();
            }, { passive: true });

            updateStatusText();
        })();
        </script>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Modal Regist Ulang -->
  <div class="modal fade" id="registUlangModal" tabindex="-1" aria-labelledby="registUlangModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header bg-success text-white">
          <h5 class="modal-title" id="registUlangModalLabel"><i class="fas fa-user-plus me-2"></i>Regist Ulang Pelanggan</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <form method="post" action="proses/addcustomer.php" id="registUlangForm">
            <input type="hidden" id="fromBerhenti" name="fromBerhenti" value="1">
            
            <div class="mb-3">
              <label for="customerID" class="form-label">Customer ID (PPPOE username) <b>Boleh diganti</b></label>
              <input required type="text" class="form-control" id="customerID" name="customerID" pattern="^(?!\s)(.*\S)?$" oninput="this.value=this.value.replace(/^\s+|\s+$/g,'')">
            </div>
            
            <div class="mb-3">
              <label for="passwordPPPOE" class="form-label">Password PPPOE (PPPOE password) <b>Boleh diganti</b></label>
              <input required type="text" class="form-control" id="passwordPPPOE" name="passwordPPPOE" pattern="^(?!\s)(.*\S)?$" oninput="this.value=this.value.replace(/^\s+|\s+$/g,'')">
            </div>
            
            <div class="mb-3">
              <label for="authmode" class="form-label">Auth Mode</label>
              <select required class="form-control" id="authmode" name="authmode">
                <option value="API MODE">API MODE</option>
                <option value="RADIUS MODE">RADIUS MODE</option>
                <option value="MULTI MODE" selected>MULTI MODE</option>
              </select>
            </div>

            <div class="mb-3">
              <label for="customerName" class="form-label">Customer Name</label>
              <input required type="text" class="form-control" id="customerName" name="customerName" pattern="^(?!\s)(.*\S)?$" oninput="this.value=this.value.replace(/^\s+|\s+$/g,'')">
            </div>

            <div class="mb-3">
              <label for="address" class="form-label">Address</label>
              <input required type="text" class="form-control" id="address" name="address" pattern="^(?!\s)(.*\S)?$" oninput="this.value=this.value.replace(/^\s+|\s+$/g,'')">
            </div>

            <div class="mb-3">
              <div class="row g-2">
                <div class="col-md-6">
                  <label class="mb-1">Provinsi</label>
                  <select id="provinsi_addcust" name="provinsi" class="form-control mb-2" required>
                    <option value="">Pilih Provinsi</option>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="mb-1">Kabupaten/Kota</label>
                  <select name="kabupaten" id="kabupaten_addcust" class="form-control mb-2" required></select>
                </div>
                <div class="col-md-6">
                  <label class="mb-1">Kecamatan</label>
                  <select name="kecamatan" id="kecamatan_addcust" class="form-control mb-2" required></select>
                </div>
                <div class="col-md-6">
                  <label class="mb-1">Kelurahan</label>
                  <select name="kelurahan" id="kelurahan_addcust" class="form-control" required></select>
                </div>
                <div class="col-md-6">
                  <label class="mb-1">RW</label>
                  <input type="text" name="rw" id="rw" class="form-control mb-2" placeholder="RW" oninput="this.value=this.value.replace(/\D/g,'').replace(/^0+(?=\d)/,'')">
                </div>
                <div class="col-md-6">
                  <label class="mb-1">RT</label>
                  <input type="text" name="rt" id="rt" class="form-control mb-2" placeholder="RT" oninput="this.value=this.value.replace(/\D/g,'').replace(/^0+(?=\d)/,'')">
                </div>
              </div>
            </div>

            <div class="mb-3">
              <label for="whatsapp" class="form-label">WhatsApp Number <b>Contoh format: 62878xxxxxx</b></label>
              <input required type="number" class="form-control" id="whatsapp" name="whatsapp" pattern="^(?!\s)(.*\S)?$" oninput="this.value=this.value.replace(/^\s+|\s+$/g,'')">
            </div>

            <div class="mb-3">
              <label for="Email" class="form-label">Email</label>
              <input required type="email" class="form-control" id="Email" name="Email" pattern="^(?!\s)(.*\S)?$" oninput="this.value=this.value.replace(/^\s+|\s+$/g,'')">
            </div>

            <div class="mb-3">
              <label for="coordinates" class="form-label">Coordinates <b>Contoh format: -6.476182,106.777992</b></label>
              <input required type="text" class="form-control" id="coordinates" name="coordinates" pattern="^(?!\s)(.*\S)?$" oninput="this.value=this.value.replace(/^\s+|\s+$/g,'')">
            </div>

            <div class="mb-3">
              <label for="tanggalpasang" class="form-label">Tanggal mulai tagihan awal (invoice start Date)</label>
              <input required type="date" class="form-control" id="tanggalpasang" name="tanggalpasang" value="<?php echo date('Y-m-d'); ?>">
            </div>

            <div class="mb-3">
              <label for="sales" class="form-label">Sales (username CRM Sales)</label>
              <select required class="form-control" id="sales" name="sales">
                <option value="">-- Pilih sales --</option>
                <option value="-">TANPA SALES</option>
                <?php
                $query = "SELECT DISTINCT nama FROM mitra WHERE server='$ceknama' ";
                $result_sales = mysqli_query($conn, $query);
                while ($row = mysqli_fetch_assoc($result_sales)) {
                  $namasales = htmlspecialchars($row['nama'], ENT_QUOTES, 'UTF-8');
                  echo '<option value="' . $namasales . '">' . $namasales . '</option>';
                }
                ?>
              </select>
            </div>

            <div class="mb-3">
              <div class="d-flex justify-content-between align-items-center">
                <label for="server" class="form-label mb-0">Server Area</label>
                <small class="text-muted">
                  Jika belum ada SERVER silahkan buat dulu 
                  <a href="server.php" target="_parent" class="text-primary text-decoration-none fw-semibold">disini</a>
                </small>
              </div>
              <select required class="form-select mt-1" id="server" name="server" onchange="setAreaAddCustomer()">
                <option value="">-- Pilih Server Area --</option>
                <?php
                if ($current_user_id) {
                  if ($AKSES == 'ASSISTANT') {
                    $queryServer = mysqli_query($conn, "SELECT DISTINCT PEMILIK, BRAND, AREA FROM server WHERE AREA IN ($area_list)");
                  } else {
                    $queryServer = mysqli_query($conn, "SELECT DISTINCT PEMILIK, BRAND, AREA FROM server WHERE user_id = $current_user_id");
                  }
                  while ($rowServer = mysqli_fetch_assoc($queryServer)) {
                    $selected = (isset($data) && $rowServer['PEMILIK'] == $data['PEMILIK']) ? "selected" : "";
                    $area = htmlspecialchars($rowServer['AREA']);
                    echo '<option value="'.$rowServer['PEMILIK'].'" data-area="'.$area.'" '.$selected.'>'.$rowServer['BRAND'].'-'.$area.'</option>';
                  }
                }
                ?>
              </select>
            </div>

            <div hidden class="mb-3">
              <label for="area" class="form-label">AREA</label>
              <input type="hidden" id="area" name="area">
              <input type="text" class="form-control" id="area_display" readonly>
            </div>

            <div class="mb-3">
              <div class="d-flex justify-content-between align-items-center">
                <label for="odp" class="form-label mb-0">ODP</label>
                <small class="text-muted">
                  Jika belum ada ODP silahkan buat dulu 
                  <a href="odp.php" target='_parent' class="text-primary text-decoration-none fw-semibold">disini</a>
                </small>
              </div>
              <select required class="form-select mt-1" id="odp" name="odp" onchange="loadPackages()">
                <option disabled selected value="">-- Pilih ODP --</option>
              </select>
            </div>

            <div class="mb-3">
              <div class="d-flex justify-content-between align-items-center">
                <label for="packages" class="form-label mb-0">Packages</label>
                <small class="text-muted">
                  Jika belum ada paket silahkan buat dulu 
                  <a href="packages.php" target='_parent' class="text-primary text-decoration-none fw-semibold">disini</a>
                </small>
              </div>
              <select required class="form-select mt-1" id="packages" name="packages">
                <option disabled selected value="">-- Pilih Packages --</option>
              </select>
            </div>

            <div class="mb-3">
              <label for="tipe_bayar">Tipe Bayar:</label>
              <select class="form-control" name="tipe_bayar" id="tipe_bayar" required>
                <option disabled value="">-- Pilih Tipe Bayar --</option>
                <option value="prabayar">Prabayar (Bayar dulu baru bisa digunakan)</option>
                <option value="pascabayar">Pasca Bayar (digunakan dulu baru bayar)</option>
              </select>
            </div>

            <div class="mb-3">
              <label for="tipe_tempo">Tipe Tempo:</label>
              <select class="form-control" name="tipe_tempo" id="tipe_tempo" required>
                <option disabled value="">-- Pilih Tipe Tempo --</option>
                <?php 
                $directory = "notifbot/data";
                $files = ["$directory/reminder-$username.json"];
                foreach ($files as $file) {
                  if (file_exists($file)) {
                    $jsonFile = "notifbot/data/reminder-$username.json";
                    if (file_exists($jsonFile)) {
                      $jsonData = file_get_contents($jsonFile);
                      $data = json_decode($jsonData, true);
                      if ($data !== null) {
                        foreach ($data as $item) {
                          $jatuh_tempo = $item['jatuh_tempo'];
                        }
                      }
                    }
                    echo '<option value="mengikuti_tanggal_tempo">Fixed Due Date (Tanggal ' . $jatuh_tempo . ')</option>';
                  } else {
                    echo '<option disabled class="text-muted" value="mengikuti_tanggal_tempo">Fixed Due Date</option>';
                  }
                }
                ?>
                <option value="mengikuti_tanggal_bayar">Rolling Due Date</option>
                <option value="monthversary">Monthversary Due Date</option>
              </select>
            </div>
          </form>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="submit" form="registUlangForm" class="btn btn-success">Regist Ulang</button>
        </div>
      </div>
    </div>
  </div>

  <script>
// Wilayah Indonesia (EMSIFA) untuk Regist Ulang
let provinsiDataGlobal = [];
let kabupatenDataGlobal = [];
let kecamatanDataGlobal = [];

fetch('https://www.emsifa.com/api-wilayah-indonesia/api/provinces.json')
  .then(r=>r.json())
  .then(provinsiData => {
    provinsiDataGlobal = provinsiData;
    const provSelect = document.getElementById('provinsi_addcust');
    provSelect.innerHTML = '<option value="">Pilih Provinsi</option>' + provinsiData.map(p=>`<option value="${p.name}">${p.name}</option>`).join('');
  });

document.getElementById('provinsi_addcust').addEventListener('change', function() {
  const provName = this.value;
  const prov = provinsiDataGlobal.find(p=>p.name===provName);
  if (!prov) return;
  fetch('https://www.emsifa.com/api-wilayah-indonesia/api/regencies/'+prov.id+'.json')
    .then(r=>r.json())
    .then(data=>{
      kabupatenDataGlobal = data;
      const kabupatenSelect = document.getElementById('kabupaten_addcust');
      kabupatenSelect.innerHTML = '<option value="">Pilih Kabupaten/Kota</option>' + data.map(k=>`<option value="${k.name}">${k.name}</option>`).join('');
      document.getElementById('kecamatan_addcust').innerHTML = '<option value="">Pilih Kecamatan</option>';
      document.getElementById('kelurahan_addcust').innerHTML = '<option value="">Pilih Kelurahan</option>';
    });
});

document.getElementById('kabupaten_addcust').addEventListener('change', function() {
  const kabName = this.value;
  const kab = kabupatenDataGlobal.find(k=>k.name===kabName);
  if (!kab) return;
  fetch('https://www.emsifa.com/api-wilayah-indonesia/api/districts/' + kab.id + '.json')
    .then(r=>r.json())
    .then(data=>{
      kecamatanDataGlobal = data;
      const kecSelect = document.getElementById('kecamatan_addcust');
      kecSelect.innerHTML = '<option value="">Pilih Kecamatan</option>' + data.map(k=>`<option value="${k.name}">${k.name}</option>`).join('');
      document.getElementById('kelurahan_addcust').innerHTML = '<option value="">Pilih Kelurahan</option>';
    });
});

document.getElementById('kecamatan_addcust').addEventListener('change', function() {
  const kecName = this.value;
  const kec = kecamatanDataGlobal.find(k=>k.name===kecName);
  if (!kec) return;
  fetch('https://www.emsifa.com/api-wilayah-indonesia/api/villages/' + kec.id + '.json')
    .then(r=>r.json())
    .then(data=>{
      const kelSelect = document.getElementById('kelurahan_addcust');
      kelSelect.innerHTML = '<option value="">Pilih Kelurahan</option>' + data.map(k=>`<option value="${k.name}">${k.name}</option>`).join('');
    });
});

// Fungsi untuk Regist Ulang
function registUlang(idpel) {
  fetch('getdata/get_pelanggan_berhenti.php?idpel=' + encodeURIComponent(idpel))
    .then(response => response.json())
    .then(data => {
      if (data.error) {
        alert('Error: ' + data.error);
        return;
      }
      document.getElementById('customerID').value = data.idpel;
      document.getElementById('passwordPPPOE').value = data.password || data.idpel;
      document.getElementById('customerName').value = data.nama;
      document.getElementById('address').value = data.alamat;
      document.getElementById('whatsapp').value = data.nowa;
      document.getElementById('Email').value = data.email || '';
      document.getElementById('coordinates').value = data.tikor || '';
      document.getElementById('sales').value = data.sales || '';

      if (data.pemilik) {
        document.getElementById('server').value = data.pemilik;
        setAreaAddCustomer();
      }

      if (data.provinsi) {
        document.getElementById('provinsi_addcust').value = data.provinsi;
        document.getElementById('provinsi_addcust').dispatchEvent(new Event('change'));

        setTimeout(() => {
          if (data.kabupaten) {
            document.getElementById('kabupaten_addcust').value = data.kabupaten;
            document.getElementById('kabupaten_addcust').dispatchEvent(new Event('change'));
          }

          setTimeout(() => {
            if (data.kecamatan) {
              document.getElementById('kecamatan_addcust').value = data.kecamatan;
              document.getElementById('kecamatan_addcust').dispatchEvent(new Event('change'));
            }

            setTimeout(() => {
              if (data.kelurahan) {
                document.getElementById('kelurahan_addcust').value = data.kelurahan;
              }
              if (data.rw) document.getElementById('rw').value = data.rw;
              if (data.rt) document.getElementById('rt').value = data.rt;
            }, 500);
          }, 500);
        }, 500);
      }

      const modal = new bootstrap.Modal(document.getElementById('registUlangModal'));
      modal.show();
    })
    .catch(error => {
      console.error('Error:', error);
      alert('Terjadi kesalahan saat mengambil data pelanggan');
    });
}

// Fungsi Hapus Permanen
function hapusPermanen(idpel) {
  if (confirm('Apakah Anda yakin ingin menghapus permanen data pelanggan ' + idpel + '? Data yang dihapus tidak dapat dikembalikan.')) {
    fetch('proses/hapus_pelanggan_berhenti.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: 'idpel=' + encodeURIComponent(idpel)
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        alert('Data pelanggan berhasil dihapus permanen');
        location.reload();
      } else {
        alert('Error: ' + (data.error || 'Gagal menghapus data'));
      }
    })
    .catch(error => {
      console.error('Error:', error);
      alert('Terjadi kesalahan saat menghapus data');
    });
  }
}

// Fungsi untuk set area
function setAreaAddCustomer() {
  let serverSelect = document.getElementById('server');
  let areaInput = document.getElementById('area');
  let areaDisplay = document.getElementById('area_display');
  let selected = serverSelect.options[serverSelect.selectedIndex];
  let area = selected ? selected.getAttribute('data-area') : '';
  areaInput.value = area;
  areaDisplay.value = area;
  loadODP();
}

// Fungsi untuk load ODP
function loadODP() {
  let selectedServer = document.getElementById("server").value;
  let selectedArea = document.getElementById("area").value;
  let odpDropdown = document.getElementById("odp");
  odpDropdown.innerHTML = '<option value="">Loading...</option>';
  if (selectedServer !== "" && selectedArea !== "") {
    let xhr = new XMLHttpRequest();
    xhr.open("GET", "getdata/get_odp.php?area=" + encodeURIComponent(selectedArea) + "&server=" + encodeURIComponent(selectedServer), true);
    xhr.onreadystatechange = function() {
      if (xhr.readyState == 4 && xhr.status == 200) {
        odpDropdown.innerHTML = xhr.responseText;
      }
    };
    xhr.send();
  }
}

// Fungsi untuk load Packages
function loadPackages() {
  let selectedServer = document.getElementById("server").value;
  let selectedArea = document.getElementById("area").value;
  let packageDropdown = document.getElementById("packages");
  packageDropdown.innerHTML = '<option value="">Loading...</option>';
  if (selectedServer !== "" && selectedArea !== "") {
    let xhr = new XMLHttpRequest();
    xhr.open("GET", "getdata/get_packages.php?area=" + encodeURIComponent(selectedArea) + "&server=" + encodeURIComponent(selectedServer), true);
    xhr.onreadystatechange = function() {
      if (xhr.readyState == 4 && xhr.status == 200) {
        packageDropdown.innerHTML = xhr.responseText;
      }
    };
    xhr.send();
  }
}

document.getElementById('server').addEventListener('change', function() {
  setAreaAddCustomer();
  loadODP();
});
document.getElementById('area').addEventListener('change', function() {
  loadODP();
});

const tipeBayar = document.getElementById('tipe_bayar');
const tipeTempo = document.getElementById('tipe_tempo');

const tipeTempoAllowedPascabayar = ['mengikuti_tanggal_bayar', 'monthversary'];

tipeBayar.addEventListener('change', function() {
  const selected = this.value;
  for (let i = 0; i < tipeTempo.options.length; i++) {
    let opt = tipeTempo.options[i];
    if (selected === 'pascabayar') {
      opt.disabled = !tipeTempoAllowedPascabayar.includes(opt.value);
    } else {
      opt.disabled = false;
    }
  }
  if (selected === 'pascabayar' && !tipeTempoAllowedPascabayar.includes(tipeTempo.value)) {
    tipeTempo.value = 'mengikuti_tanggal_bayar';
  }
});

// ==== Sinkronisasi checkbox BOT -> hidden input (aturan sama dengan encodeBotSelection() PHP) ====
function updateBotSelection(groupId, hiddenInputId) {
    const group = document.getElementById(groupId);
    const hidden = document.getElementById(hiddenInputId);
    if (!group || !hidden) return;
    const randomCb = group.querySelector('.bot-random-toggle');
    const specificCbs = group.querySelectorAll('.bot-specific-checkbox');
    const selected = Array.from(specificCbs).filter(cb => cb.checked).map(cb => cb.value);

    if (!randomCb.checked && selected.length > 0) {
        hidden.value = selected.length === 1 ? selected[0] : selected.join(',');
    } else {
        hidden.value = 'RANDOM';
    }
}

function initBotCheckboxGroups() {
    document.querySelectorAll('.bot-checkbox-group').forEach(function (group) {
        const randomCb = group.querySelector('.bot-random-toggle');
        const specificCb = group.querySelectorAll('.bot-specific-checkbox');
        const hiddenId = group.getAttribute('data-hidden-target');

        function sync() { updateBotSelection(group.id, hiddenId); }

        randomCb.addEventListener('change', function () {
            if (this.checked) specificCb.forEach(cb => cb.checked = false);
            sync();
        });

        specificCb.forEach(function (cb) {
            cb.addEventListener('change', function () {
                if (this.checked) randomCb.checked = false;
                const anyChecked = Array.prototype.some.call(specificCb, c => c.checked);
                if (!anyChecked) randomCb.checked = true;
                sync();
            });
        });

        sync();
    });
}

// Prevent leading/trailing spaces
document.addEventListener('DOMContentLoaded', function() {
  initBotCheckboxGroups();

  // Sinkronkan sekali lagi sebelum form broadcast disubmit (jaga-jaga)
  const formBroadcast = document.getElementById('formBroadcastBerhenti');
  if (formBroadcast) {
    formBroadcast.addEventListener('submit', function() {
      updateBotSelection('grp_bot_broadcast', 'bot_broadcast');
    });
  }

  const form = document.getElementById('registUlangForm');
  if (form) {
    form.addEventListener('submit', function(e) {
      const inputs = form.querySelectorAll('input[type="text"], input[type="email"], input[type="number"]');
      let hasError = false;
      let errorMessage = '';
      
      inputs.forEach(function(input) {
        const value = input.value;
        const inputName = input.name;
        
        if (inputName === 'customerID' || inputName === 'passwordPPPOE') {
          if (/\s/.test(value)) {
            hasError = true;
            errorMessage = 'PPPOE Username dan Password tidak boleh mengandung spasi sama sekali!';
            input.classList.add('is-invalid');
          } else {
            input.classList.remove('is-invalid');
          }
        } else {
          if (/^\s|\s$/.test(value)) {
            hasError = true;
            errorMessage = 'Input tidak boleh ada spasi di awal atau di akhir!';
            input.classList.add('is-invalid');
          } else {
            input.classList.remove('is-invalid');
          }
          input.value = value.replace(/^\s+|\s+$/g, '');
        }
      });
      
      if (hasError) {
        alert(errorMessage);
        e.preventDefault();
      }
    });
  }

  // Search functionality
  const searchInput = document.getElementById('searchInput');
  const clearSearchBtn = document.getElementById('clearSearch');
  const searchResultCount = document.getElementById('searchResultCount');
  const table = document.getElementById('pelangganTable');
  const tbody = table.querySelector('tbody');
  let searchTimeout;

  function filterTable(searchTerm) {
    const term = searchTerm.toLowerCase().trim();

    function runFilter() {
      // Query ulang tiap kali (bukan snapshot sekali di awal) supaya baris yang
      // baru ditambahkan lazy-load ikut ke-filter, bukan cuma batch pertama.
      const rows = tbody.querySelectorAll('tr');
      let visibleCount = 0;
      let totalCount = 0;

      rows.forEach(row => {
        if (row.id === 'berhentiLazySentinel') {
          return;
        }
        if (row.cells.length === 1 && (row.textContent.includes('Tidak ada data') || row.classList.contains('no-results'))) {
          return;
        }

        totalCount++;
        const text = row.textContent.toLowerCase();
        const isVisible = term === '' || text.includes(term);

        row.style.display = isVisible ? '' : 'none';
        if (isVisible) visibleCount++;
      });

      if (term === '') {
        searchResultCount.textContent = '';
      } else {
        searchResultCount.textContent = `Menampilkan ${visibleCount} dari ${totalCount} data`;
      }

      let noResultsRow = tbody.querySelector('.no-results');
      if (term !== '' && visibleCount === 0) {
        if (!noResultsRow) {
          noResultsRow = document.createElement('tr');
          noResultsRow.className = 'no-results';
          noResultsRow.innerHTML = '<td colspan="4" class="text-center text-muted">Tidak ada data yang cocok dengan pencarian "' + searchTerm + '"</td>';
          tbody.appendChild(noResultsRow);
        }
      } else if (noResultsRow) {
        noResultsRow.remove();
      }
    }

    // Data yang belum ke-scroll (lazy-load) belum ada di DOM - muat semua dulu
    // supaya pencarian benar-benar mencakup semua data yang cocok dengan filter
    // bulan/tahun, bukan cuma yang kebetulan sudah tampil.
    if (term !== '' && typeof window.berhentiLoadAllRemaining === 'function') {
      window.berhentiLoadAllRemaining().then(runFilter);
    } else {
      runFilter();
    }
  }

  searchInput.addEventListener('input', function() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
      filterTable(this.value);
    }, 300);
  });

  clearSearchBtn.addEventListener('click', function() {
    searchInput.value = '';
    clearTimeout(searchTimeout);
    filterTable('');
    searchInput.focus();
  });

  searchInput.addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      clearTimeout(searchTimeout);
      filterTable(this.value);
    }
  });
});
  </script>

<?php require 'footer.php'; ?>