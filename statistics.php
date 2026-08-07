<?php require 'header.php';

if ($AKSES == 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('Statistik', $akses_menu, true)) {
        echo '<div class="container-fluid py-4"><div class="alert alert-danger">Anda tidak memiliki akses ke menu Statistik dan Laporan.</div></div>';
        require 'footer.php';
        exit;
    }
}

// Ambil filter bulan & tahun dari GET, default ke bulan/tahun sekarang
$filter_bulan = isset($_GET['bulan']) ? (int)$_GET['bulan'] : (int)date('m');
$filter_tahun = isset($_GET['tahun']) ? (int)$_GET['tahun'] : (int)date('Y');






// Get all server PEMILIK and AREA for this user
$userServers = [];
$userAreas = [];
$queryServer = mysqli_query($conn, "SELECT PEMILIK, AREA FROM server WHERE user_id = $current_user_id");
while($row = mysqli_fetch_assoc($queryServer)) {
  $userServers[] = $row['PEMILIK'];
  if (!empty($row['AREA'])) $userAreas[] = $row['AREA'];
}

// For SQL IN clauses
$userServerList = count($userServers) > 0 ? "'" . implode("','", array_map('addslashes', $userServers)) . "'" : "''";
$userAreaList = count($userAreas) > 0 ? "'" . implode("','", array_map('addslashes', $userAreas)) . "'" : "''";

if($AKSES == 'ASSISTANT') {
  $userAreaList = $area_list;
  $queryPemilik = mysqli_query($conn, "SELECT DISTINCT PEMILIK FROM server WHERE AREA IN ($area_list)");
  $userServers = [];
  while($row = mysqli_fetch_assoc($queryPemilik)) {
    $userServers[] = $row['PEMILIK'];
  }
  $userServerList = count($userServers) > 0 ? "'" . implode("','", array_map('addslashes', $userServers)) . "'" : "''";
}













?>

<div class="container-fluid py-4 px-3 px-md-4">
  <!-- DataTables CSS -->
  <style>
    .table-search {
      margin-bottom: 10px;
    }
    .table-pagination {
      margin-top: 10px;
      text-align: center;
    }
    .table-pagination button {
      margin: 0 5px;
      padding: 5px 10px;
      border: 1px solid #ccc;
      background: #fff;
      cursor: pointer;
    }
    .table-pagination button.active {
      background: #007bff;
      color: white;
    }

    /* Dark Mode Support */
    body.app-theme-dark .table-pagination button {
      border-color: rgba(59, 130, 246, 0.3);
      background-color: #1a233a;
      color: #e2e8f0;
    }

    body.app-theme-dark .table-pagination button.active {
      background-color: #2563eb;
      color: #ffffff;
      border-color: #2563eb;
    }

    body.app-theme-dark .table-pagination button:hover {
      background-color: rgba(59, 130, 246, 0.15);
      border-color: #3b82f6;
    }

    body.app-theme-dark .fw-bold.text-primary,
    body.app-theme-dark .fw-bold.text-success,
    body.app-theme-dark .fw-bold.text-info,
    body.app-theme-dark .fw-bold.text-warning,
    body.app-theme-dark .fw-bold.text-danger,
    body.app-theme-dark .fw-bold.text-secondary {
      color: #e2e8f0 !important;
    }

    body.app-theme-dark [style*="color: #28a745"],
    body.app-theme-dark [style*="color: #17a2b8"],
    body.app-theme-dark [style*="color: #ffc107"],
    body.app-theme-dark [style*="color: #dc3545"],
    body.app-theme-dark [style*="color: #6c757d"] {
      color: #e2e8f0 !important;
    }
  </style>
  <div class="card shadow-sm mb-4">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
      <h6 class="mb-0" style="font-size: 1em;">Statistik dan Laporan Pembayaran</h6>
      <button class="btn btn-light btn-sm" onclick="location.reload()" style="font-weight: 600;"><i class="fas fa-sync-alt me-1"></i>Refresh</button>
    </div>
    <div class="card-body">
      <!-- Filter Bulan & Tahun -->
      <form method="get" class="row g-2 align-items-end mb-4">
        <div class="col-auto">
          <label for="bulan" class="form-label mb-0" style="font-size: 0.85em;">Bulan</label>
          <select name="bulan" id="bulan" class="form-select" style="font-size: 0.85em; padding: 0.35rem 0.5rem;">
            <?php
            for ($b = 1; $b <= 12; $b++) {
              $selected = ($b == $filter_bulan) ? 'selected' : '';
              printf('<option value="%02d" %s>%s</option>', $b, $selected, date('F', mktime(0,0,0,$b,1)));
            }
            ?>
          </select>
        </div>
        <div class="col-auto">
          <label for="tahun" class="form-label mb-0" style="font-size: 0.85em;">Tahun</label>
          <select name="tahun" id="tahun" class="form-select" style="font-size: 0.85em; padding: 0.35rem 0.5rem;">
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
          <button type="submit" class="btn btn-primary btn-sm" style="font-size: 0.85em; font-weight: 600;">Tampilkan</button>
        </div>
      </form>

      <?php
      if (!function_exists('format_duration_minutes_stats')) {
        function format_duration_minutes_stats($minutes) {
          $totalMinutes = max(0, (int)$minutes);
          $days = (int)floor($totalMinutes / 1440);
          $remain = $totalMinutes % 1440;
          $hours = (int)floor($remain / 60);
          $mins = $remain % 60;

          if ($days > 0) return $days . ' hari ' . $hours . ' jam';
          if ($hours > 0) return $hours . ' jam ' . $mins . ' menit';
          return $mins . ' menit';
        }
      }

      $filter_snapshot_month = sprintf('%04d-%02d', $filter_tahun, $filter_bulan);

      $sql_sla_monthly = "SELECT snapshot_month, pemilik, total_sla_percent, total_servers, servers_with_data, total_uptime_minutes, last_check, updated_at
                          FROM server_sla_monthly_snapshots
                          WHERE pemilik IN ($userServerList)
                            AND snapshot_month = '$filter_snapshot_month'
                          ORDER BY snapshot_month DESC, pemilik ASC";
      $res_sla_monthly = mysqli_query($conn, $sql_sla_monthly);
      ?>

      <div class="card shadow-sm mb-4">
        <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
          <h6 class="mb-0" style="font-size: 0.95em;">Riwayat Snapshot SLA tiap bulan</h6>
          <small><?= htmlspecialchars(date('F Y', mktime(0,0,0,$filter_bulan,1,$filter_tahun))); ?></small>
        </div>
        <div class="card-body p-2 p-md-3">
          <div class="table-responsive">
            <table class="table table-striped table-sm align-middle mb-0" id="tabel-sla-monthly">
              <thead>
                <tr>
                  <th>Bulan</th>
                  <th>Server</th>
                  <th>SLA Akhir</th>
                  <th>Total Server</th>
                  <th>Server Berdata</th>
                  <th>Total Uptime</th>
                  <th>Last Check</th>
                  <th>Disimpan</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($res_sla_monthly && mysqli_num_rows($res_sla_monthly) > 0) : ?>
                  <?php while ($row_sla_monthly = mysqli_fetch_assoc($res_sla_monthly)) : ?>
                    <tr>
                      <td><?= htmlspecialchars((string)($row_sla_monthly['snapshot_month'] ?? '-')); ?></td>
                      <td><?= htmlspecialchars((string)($row_sla_monthly['pemilik'] ?? '-')); ?></td>
                      <td><strong><?= number_format((float)($row_sla_monthly['total_sla_percent'] ?? 0), 2); ?>%</strong></td>
                      <td><?= (int)($row_sla_monthly['total_servers'] ?? 0); ?></td>
                      <td><?= (int)($row_sla_monthly['servers_with_data'] ?? 0); ?></td>
                      <td><?= htmlspecialchars(format_duration_minutes_stats((int)($row_sla_monthly['total_uptime_minutes'] ?? 0))); ?></td>
                      <td><?= htmlspecialchars((string)($row_sla_monthly['last_check'] ?? '-')); ?></td>
                      <td><?= htmlspecialchars((string)($row_sla_monthly['updated_at'] ?? '-')); ?></td>
                    </tr>
                  <?php endwhile; ?>
                <?php else : ?>
                  <tr>
                    <td colspan="8" class="text-center text-muted">Belum ada data snapshot SLA akhir bulan untuk periode ini.</td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <?php
      if (!function_exists('format_duration_minutes_stats')) {
        function format_duration_minutes_stats($minutes) {
          $totalMinutes = max(0, (int)$minutes);
          $days = (int)floor($totalMinutes / 1440);
          $remain = $totalMinutes % 1440;
          $hours = (int)floor($remain / 60);
          $mins = $remain % 60;

          if ($days > 0) return $days . ' hari ' . $hours . ' jam';
          if ($hours > 0) return $hours . ' jam ' . $mins . ' menit';
          return $mins . ' menit';
        }
      }

      $sql_customer_sla_monthly = "SELECT snapshot_month, idpel, pemilik, area, odp, total_sla_percent, total_checks, online_checks, total_uptime_minutes, last_check, updated_at
                                   FROM customer_sla_monthly_snapshots
                                   WHERE pemilik IN ($userServerList)
                                     AND snapshot_month = '$filter_snapshot_month'
                                   ORDER BY snapshot_month DESC, pemilik ASC, idpel ASC";
      $res_customer_sla_monthly = mysqli_query($conn, $sql_customer_sla_monthly);

      $sql_odp_sla_monthly = "SELECT snapshot_month, odp, pemilik, area, total_sla_percent, total_customers, customers_with_data, total_uptime_minutes, last_check, updated_at
                              FROM odp_sla_monthly_snapshots
                              WHERE pemilik IN ($userServerList)
                                AND snapshot_month = '$filter_snapshot_month'
                              ORDER BY snapshot_month DESC, pemilik ASC, odp ASC";
      $res_odp_sla_monthly = mysqli_query($conn, $sql_odp_sla_monthly);
      ?>

      <div class="card shadow-sm mb-4">
        <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
          <h6 class="mb-0" style="font-size: 0.95em;">Riwayat Snapshot SLA Pelanggan per bulan</h6>
          <small><?= htmlspecialchars(date('F Y', mktime(0,0,0,$filter_bulan,1,$filter_tahun))); ?></small>
        </div>
        <div class="card-body p-2 p-md-3">
          <div class="table-responsive">
            <table class="table table-striped table-sm align-middle mb-0" id="tabel-customer-sla-monthly">
              <thead>
                <tr>
                  <th>Bulan</th>
                  <th>IDPEL</th>
                  <th>AREA / ODP</th>
                  <th>SLA Akhir</th>
                  <th>Total Check</th>
                  <th>Online Check</th>
                  <th>Total Uptime</th>
                  <th>Last Check</th>
                  <th>Disimpan</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($res_customer_sla_monthly && mysqli_num_rows($res_customer_sla_monthly) > 0) : ?>
                  <?php while ($row_customer_sla_monthly = mysqli_fetch_assoc($res_customer_sla_monthly)) : ?>
                    <tr>
                      <td><?= htmlspecialchars((string)($row_customer_sla_monthly['snapshot_month'] ?? '-')); ?></td>
                      <td><?= htmlspecialchars((string)($row_customer_sla_monthly['idpel'] ?? '-')); ?></td>
                      <td><?= htmlspecialchars((string)($row_customer_sla_monthly['area'] ?? '-')); ?> / <?= htmlspecialchars((string)($row_customer_sla_monthly['odp'] ?? '-')); ?></td>
                      <td><strong><?= number_format((float)($row_customer_sla_monthly['total_sla_percent'] ?? 0), 2); ?>%</strong></td>
                      <td><?= (int)($row_customer_sla_monthly['total_checks'] ?? 0); ?></td>
                      <td><?= (int)($row_customer_sla_monthly['online_checks'] ?? 0); ?></td>
                      <td><?= htmlspecialchars(format_duration_minutes_stats((int)($row_customer_sla_monthly['total_uptime_minutes'] ?? 0))); ?></td>
                      <td><?= htmlspecialchars((string)($row_customer_sla_monthly['last_check'] ?? '-')); ?></td>
                      <td><?= htmlspecialchars((string)($row_customer_sla_monthly['updated_at'] ?? '-')); ?></td>
                    </tr>
                  <?php endwhile; ?>
                <?php else : ?>
                  <tr>
                    <td colspan="9" class="text-center text-muted">Belum ada data snapshot SLA pelanggan untuk periode ini.</td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="card shadow-sm mb-4">
        <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
          <h6 class="mb-0" style="font-size: 0.95em;">Riwayat Snapshot SLA ODP per bulan</h6>
          <small><?= htmlspecialchars(date('F Y', mktime(0,0,0,$filter_bulan,1,$filter_tahun))); ?></small>
        </div>
        <div class="card-body p-2 p-md-3">
          <div class="table-responsive">
            <table class="table table-striped table-sm align-middle mb-0" id="tabel-odp-sla-monthly">
              <thead>
                <tr>
                  <th>Bulan</th>
                  <th>ODP</th>
                  <th>Pemilik</th>
                  <th>SLA Akhir</th>
                  <th>Total Pelanggan</th>
                  <th>Pelanggan Berdata</th>
                  <th>Total Uptime</th>
                  <th>Last Check</th>
                  <th>Disimpan</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($res_odp_sla_monthly && mysqli_num_rows($res_odp_sla_monthly) > 0) : ?>
                  <?php while ($row_odp_sla_monthly = mysqli_fetch_assoc($res_odp_sla_monthly)) : ?>
                    <tr>
                      <td><?= htmlspecialchars((string)($row_odp_sla_monthly['snapshot_month'] ?? '-')); ?></td>
                      <td><?= htmlspecialchars((string)($row_odp_sla_monthly['odp'] ?? '-')); ?></td>
                      <td><?= htmlspecialchars((string)($row_odp_sla_monthly['pemilik'] ?? '-')); ?></td>
                      <td><strong><?= number_format((float)($row_odp_sla_monthly['total_sla_percent'] ?? 0), 2); ?>%</strong></td>
                      <td><?= (int)($row_odp_sla_monthly['total_customers'] ?? 0); ?></td>
                      <td><?= (int)($row_odp_sla_monthly['customers_with_data'] ?? 0); ?></td>
                      <td><?= htmlspecialchars(format_duration_minutes_stats((int)($row_odp_sla_monthly['total_uptime_minutes'] ?? 0))); ?></td>
                      <td><?= htmlspecialchars((string)($row_odp_sla_monthly['last_check'] ?? '-')); ?></td>
                      <td><?= htmlspecialchars((string)($row_odp_sla_monthly['updated_at'] ?? '-')); ?></td>
                    </tr>
                  <?php endwhile; ?>
                <?php else : ?>
                  <tr>
                    <td colspan="9" class="text-center text-muted">Belum ada data snapshot SLA ODP untuk periode ini.</td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Statistik Ringkasan -->
        <?php
        setlocale(LC_TIME, 'id_ID.utf8');
       
      


  function tanggal_indonesia2($tanggal)
  {
    $bulan = array(
      1 =>   'Januari',
      'Februari',
      'Maret',
      'April',
      'Mei',
      'Juni',
      'Juli',
      'Agustus',
      'September',
      'Oktober',
      'November',
      'Desember'
    );

    $pecahkan = explode('-', $tanggal);

    // variabel pecahkan 0 = tanggal
    // variabel pecahkan 1 = bulan
    // variabel pecahkan 2 = tahun

    return  $bulan[(int)$pecahkan[1]];
  }

  $cekbulan = tanggal_indonesia2(date('Y-m-d')); // Hasilnya menampilkan format tanggal 11 Oktober 2017

  $tahun = $_GET['tahun'];
  if ($tahun == "") {
    $tahun = date('Y');
  } else {
    $tahun = $_GET['tahun'];
  }
    
      $today = date('Y-m-d');
    $bulan_ini = str_pad($filter_bulan, 2, '0', STR_PAD_LEFT);
    $tahun_ini = $filter_tahun;
$senin_ini = date('Y-m-d', strtotime('monday this week'));
$minggu_ini = date('Y-m-d', strtotime('sunday this week'));

      // Helper function untuk query error
      function query_or_die($conn, $sql) {
        $result = mysqli_query($conn, $sql);
        if (!$result) {
          die("Query error: " . mysqli_error($conn) . "<br>SQL: $sql");
        }
        return $result;
      }

      // Filter untuk asisten
      $area_filter = (!empty($asistant_name)) ? $area_list : $userAreaList;

 

      // 1. Total Pelanggan
   $sql_aktif = "SELECT COUNT(*) as total FROM pelanggan WHERE PEMILIK IN ($userServerList) AND AREA IN ($area_filter)";
  $data_aktif = mysqli_fetch_assoc(query_or_die($conn, $sql_aktif));

// --- SETUP PERIODE ---
$periode_penggunaan_invoice = tanggal_indonesia2(sprintf('%04d-%02d-01', $filter_tahun, $filter_bulan)) . ' ' . $filter_tahun;

// 1. Definisikan ekspresi pembersihan string (Hapus nama hari dan ubah nama bulan)
// Ini akan mengubah "Jumat, 17 Februari 2023" menjadi tanggal yang dipahami MySQL
// 1. Bersihkan string: Ambil setelah koma, lalu ganti nama bulan menjadi angka
// --- 0. Persiapan Filter ---
$periode_sql = mysqli_real_escape_string($conn, $periode_penggunaan_invoice);

// --- 1. Total Invoice (Semua Tagihan di Periode Tersebut) ---
// Kita gunakan COUNT(*) agar sesuai dengan hasil 140 (tanpa DISTINCT)
$sql_invoice = "SELECT COUNT(*) as total 
                FROM transaksi t 
                INNER JOIN pelanggan p ON t.IDPEL = p.IDPEL 
                WHERE t.PENGUNAAN = '$periode_sql'  AND t.HARGA != '0'
                AND p.PEMILIK IN ($userServerList) 
                AND p.AREA IN ($area_filter)";
$data_invoice = mysqli_fetch_assoc(query_or_die($conn, $sql_invoice));

// --- 2. Sudah Bayar (Status BERHASIL di Periode Tersebut) ---
// Menghitung jumlah lembar transaksi yang sukses (Hasil akan 121)
$sql_sudah_bayar = "SELECT COUNT(*) as total 
                    FROM transaksi t 
                    INNER JOIN pelanggan p ON t.IDPEL = p.IDPEL 
                    WHERE t.STATUS = 'BERHASIL'  AND t.HARGA != '0'
                    AND t.PENGUNAAN = '$periode_sql' 
                    AND p.PEMILIK IN ($userServerList) 
                    AND p.AREA IN ($area_filter)";
$data_sudah_bayar = mysqli_fetch_assoc(query_or_die($conn, $sql_sudah_bayar));

// --- 3. Belum Bayar (Status PENAGIHAN di Periode Tersebut) ---
// Menghitung sisa tagihan yang belum lunas (Hasil akan 122 sesuai filter AREA Anda)
$sql_belum_bayar = "SELECT COUNT(*) as total 
                    FROM transaksi t 
                    INNER JOIN pelanggan p ON t.IDPEL = p.IDPEL 
                    WHERE t.STATUS = 'PENAGIHAN' AND t.HARGA != '0'
                    AND t.PENGUNAAN = '$periode_sql' 
                    AND p.PEMILIK IN ($userServerList) 
                    AND p.AREA IN ($area_filter)";
$data_belum_bayar = mysqli_fetch_assoc(query_or_die($conn, $sql_belum_bayar));

// --- 4. Catatan Tambahan (Parsing Tanggal jika dibutuhkan untuk Cashflow) ---
// Gunakan ini HANYA jika ingin membuat grafik pembayaran harian
$trx_tanggal_expr_t = "STR_TO_DATE(TRIM(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(SUBSTRING_INDEX(t.TANGGALBAYAR, ',', -1),'Januari', '01'), 'Februari', '02'), 'Maret', '03'), 'April', '04'), 'Mei', '05'), 'Juni', '06'), 'Juli', '07'), 'Agustus', '08'), 'September', '09'), 'Oktober', '10'), 'November', '11'), 'Desember', '12')), '%d %m %Y')";


      // 5-7. Menunggak (diselaraskan dengan pelanggan_menunggak.php)
      $trxTanggalExprNoAlias = "COALESCE(
        DATE(TANGGALBAYAR),
        STR_TO_DATE(TANGGALBAYAR, '%Y-%m-%d'),
        STR_TO_DATE(
          TRIM(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
            SUBSTRING_INDEX(TANGGALBAYAR, ',', -1),
            'Januari', '01'
          ), 'Februari', '02'), 'Maret', '03'), 'April', '04'), 'Mei', '05'), 'Juni', '06'), 'Juli', '07'), 'Agustus', '08'), 'September', '09'), 'Oktober', '10'), 'November', '11'), 'Desember', '12')),
          '%d %m %Y'
        )
      )";

      // Hasil query di-lewatkan reseller_filter_rows() supaya ikut filter harga
      // reseller/mitra (custom_harga per paket + paket yang tidak di-enable utk
      // reseller ini otomatis tidak masuk peta) -- transparan/no-op utk sesi
      // bukan reseller. Ini blok KEDUA yang bangun map serupa (ada lagi di
      // bawah, sengaja dipertahankan terpisah krn dipakai closure berbeda),
      // jadi harus difilter juga -- bukan cuma satu blok saja.
      $hargaPaketMap = [];
      $fasumPaketList = [];
      $qPaketMap = mysqli_query($conn, "SELECT id, PAKET, HARGA, BRAND, AREA FROM paket");
      $rowsPaketMapAwal = reseller_filter_rows($conn, reseller_collect_rows($qPaketMap), 'broadband');
      foreach ($rowsPaketMapAwal as $r) {
        $paketKey = strtolower(trim((string)$r['PAKET']));
        $brandKey = isset($r['BRAND']) ? strtolower(trim((string)$r['BRAND'])) : '';
        $areaKey = isset($r['AREA']) ? strtolower(trim((string)$r['AREA'])) : '';
        $mapKey = $paketKey . '|' . $brandKey . '|' . $areaKey;
        $hargaPaketMap[$mapKey] = $r['HARGA'];
        if ($r['HARGA'] === '' || (float)$r['HARGA'] <= 0) {
          $fasumPaketList[$paketKey] = $r['id'];
        }
      }

      $promoPaketIds = [];
      $qPromo = mysqli_query($conn, "SELECT paket_id FROM promo_paket");
      while ($r = mysqli_fetch_assoc($qPromo)) {
        $promoPaketIds[] = (string)$r['paket_id'];
      }

      // Default 25 -- SELARAS dgn default lokal cek_tagihan_harian*.php (cron
      // yang SUNGGUHAN menentukan isolir/EXPIRED di MikroTik) & reminderSettingsDefaults()
      // di DB, BUKAN 28 spt sebelumnya (beda 3 hari dari cron -> salah satu
      // sebab widget "Statistik"/"Menunggak" tidak sinkron dgn "Expired
      // Online"/"Expired Los" di dashboard -- lihat juga getFixedDueDateDay()
      // di pelanggan_menunggak.php, pola sama). Baca via reminderSettingsGetRow()
      // (gating-aware: HANYA timpa default kalau akun ini PERNAH eksplisit
      // setting Fixed Due Date).
      $fixedDueDateDay = 25;
      $safeUsername = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($ceknama ?? $username ?? ''));
      require_once __DIR__ . '/notifbot/reminder_settings_helper.php';
      $statsReminderRow = reminderSettingsGetRow($conn, (string)($ceknama ?? $username ?? ''));
      if ($statsReminderRow && !empty($statsReminderRow['fixed_due_date_configured'])) {
        $d = (int)($statsReminderRow['jatuh_tempo'] ?? 0);
        if ($d >= 1 && $d <= 31) {
          $fixedDueDateDay = $d;
        }
      }

      // Waktu tunggu (hari) prabayar sebelum dianggap menunggak sejak pasang --
      // dipakai cabang "belum pernah bayar" di bawah, selaras cek_tagihan_harian.php.
      $statsPrabayarGracePeriod = 2;
      $statsGraceFile = __DIR__ . '/notifbot/data/prabayar_grace_period-' . $safeUsername . '.json';
      if (is_file($statsGraceFile)) {
        $statsGraceData = json_decode((string)@file_get_contents($statsGraceFile), true);
        if (is_array($statsGraceData) && isset($statsGraceData['prabayar_grace_period'])) {
          $gVal = (int)$statsGraceData['prabayar_grace_period'];
          if ($gVal >= 0) {
            $statsPrabayarGracePeriod = $gVal;
          }
        }
      }

      $isSamePeriod = function ($dateValue, $todayVal) {
        if (empty($dateValue)) return false;
        $tsDate = strtotime((string)$dateValue);
        $tsToday = strtotime((string)$todayVal);
        if ($tsDate === false || $tsToday === false) return false;
        return date('Y-m', $tsDate) === date('Y-m', $tsToday);
      };

      $resolveHarga = function ($paket, $brand, $area) use ($hargaPaketMap) {
        $k = $paket . '|' . $brand . '|' . $area;
        if (isset($hargaPaketMap[$k])) return $hargaPaketMap[$k];
        if (isset($hargaPaketMap[$paket . '||' . $area])) return $hargaPaketMap[$paket . '||' . $area];
        if (isset($hargaPaketMap[$paket . '|' . $brand . '|'])) return $hargaPaketMap[$paket . '|' . $brand . '|'];
        if (isset($hargaPaketMap[$paket . '||'])) return $hargaPaketMap[$paket . '||'];
        if (isset($hargaPaketMap[$paket])) return $hargaPaketMap[$paket];
        return null;
      };

      $isFasumNonPromo = function ($paket) use ($fasumPaketList, $promoPaketIds) {
        if ($paket === '' || !isset($fasumPaketList[$paket])) return false;
        return !in_array((string)$fasumPaketList[$paket], $promoPaketIds, true);
      };

      $buildMonthlyDate = function ($year, $month, $day) {
        $year = (int)$year;
        $month = (int)$month;
        $day = (int)$day;
        if ($year < 1970 || $month < 1 || $month > 12) return null;
        if ($day < 1) $day = 1;
        $daysInMonth = (int)date('t', strtotime(sprintf('%04d-%02d-01', $year, $month)));
        if ($day > $daysInMonth) $day = $daysInMonth;
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
      };

      $parseIndoMonthYear = function ($value) {
        $raw = trim((string)$value);
        if ($raw === '') return null;
        if (!preg_match('/^([A-Za-z]+)\s+(\d{4})$/', $raw, $m)) return null;

        $monthMap = [
          'januari' => 1,
          'februari' => 2,
          'maret' => 3,
          'april' => 4,
          'mei' => 5,
          'juni' => 6,
          'juli' => 7,
          'agustus' => 8,
          'september' => 9,
          'oktober' => 10,
          'november' => 11,
          'desember' => 12,
        ];

        $monthName = strtolower(trim((string)$m[1]));
        $year = (int)$m[2];
        if (!isset($monthMap[$monthName]) || $year < 1970) return null;

        return ['month' => (int)$monthMap[$monthName], 'year' => $year];
      };

      $getFirstDueFixedByUsage = function ($usageValue, $fixedDay) use ($parseIndoMonthYear, $buildMonthlyDate) {
        $parsed = $parseIndoMonthYear((string)$usageValue);
        if (!$parsed) return null;
        return $buildMonthlyDate((int)$parsed['year'], (int)$parsed['month'], (int)$fixedDay);
      };

      $getReferenceDate = function ($row) {
        $lastPaid = isset($row['last_paid']) ? trim((string)$row['last_paid']) : '';
        if ($lastPaid !== '' && strtotime($lastPaid) !== false) return date('Y-m-d', strtotime($lastPaid));
        $pasang = isset($row['TANGGALPASANG']) ? trim((string)$row['TANGGALPASANG']) : '';
        if ($pasang !== '' && strtotime($pasang) !== false) return date('Y-m-d', strtotime($pasang));
        return '';
      };

      $getTempoType = function ($row) {
        return strtolower(trim((string)($row['TIPE_TEMPO'] ?? 'mengikuti_tanggal_tempo')));
      };

      // Hari jatuh tempo tetap milik pelanggan itu sendiri untuk mode "monthversary".
      $getMonthversaryAnchorDay = function ($row) {
        $anchorDate = trim((string)($row['TANGGAL_MONTHVERSARY'] ?? ''));
        if ($anchorDate === '' || strtotime($anchorDate) === false) {
          $anchorDate = (string)($row['TANGGALPASANG'] ?? '');
        }
        if ($anchorDate === '' || strtotime($anchorDate) === false) {
          return 28;
        }
        return (int)date('j', strtotime($anchorDate));
      };

      $shouldCount = function ($row, $todayVal) use ($isSamePeriod) {
        if ($isSamePeriod($row['TANGGALPASANG'] ?? '', $todayVal)) return false;
        if ($isSamePeriod($row['last_paid'] ?? '', $todayVal)) return false;
        return true;
      };

      $getFirstDue = function ($row, $referenceDate, $fixedDay) use ($getTempoType, $buildMonthlyDate, $getMonthversaryAnchorDay) {
        if ($referenceDate === '' || strtotime($referenceDate) === false) return null;
        $refTs = strtotime($referenceDate);
        $tempoType = $getTempoType($row);
        if ($tempoType === 'mengikuti_tanggal_bayar') {
          return date('Y-m-d', strtotime('+1 month', $refTs));
        }
        $dueDay = ($tempoType === 'monthversary') ? $getMonthversaryAnchorDay($row) : (int)$fixedDay;
        $nextMonthTs = strtotime('+1 month', $refTs);
        return $buildMonthlyDate((int)date('Y', $nextMonthTs), (int)date('m', $nextMonthTs), $dueDay);
      };

      $resolveFirstDueForRow = function ($row, $referenceDate, $fixedDay) use ($getFirstDue, $getTempoType, $getFirstDueFixedByUsage) {
        $dueDate = $getFirstDue($row, $referenceDate, $fixedDay);
        $tipeBayar = strtolower(trim((string)($row['TIPE_BAYAR'] ?? 'prabayar')));

        if ($tipeBayar === 'prabayar' && $getTempoType($row) === 'mengikuti_tanggal_tempo') {
          $dueByUsage = $getFirstDueFixedByUsage((string)($row['last_pengunaan'] ?? ''), $fixedDay);
          if (!empty($dueByUsage)) {
            $dueDate = $dueByUsage;
          }
        }

        return $dueDate;
      };

      $getNextDue = function ($row, $currentDueDate, $fixedDay) use ($getTempoType, $buildMonthlyDate, $getMonthversaryAnchorDay) {
        if (empty($currentDueDate) || strtotime($currentDueDate) === false) return null;
        $tempoType = $getTempoType($row);
        if ($tempoType === 'mengikuti_tanggal_bayar') {
          return date('Y-m-d', strtotime('+1 month', strtotime($currentDueDate)));
        }
        $dueDay = ($tempoType === 'monthversary') ? $getMonthversaryAnchorDay($row) : (int)$fixedDay;
        $n = strtotime('+1 month', strtotime($currentDueDate));
        return $buildMonthlyDate((int)date('Y', $n), (int)date('m', $n), $dueDay);
      };

      $hasSuccessfulPaymentInPeriod = function ($idpel, $startDate, $endDate) use ($conn, $trxTanggalExprNoAlias) {
        if ($idpel === '' || $startDate === '' || $endDate === '') return false;
        if (strtotime($startDate) === false || strtotime($endDate) === false) return false;

        $sql = "SELECT 1 FROM transaksi WHERE IDPEL = '" . mysqli_real_escape_string($conn, (string)$idpel) . "' AND STATUS = 'BERHASIL' AND DATE($trxTanggalExprNoAlias) >= '" . mysqli_real_escape_string($conn, $startDate) . "' AND DATE($trxTanggalExprNoAlias) < '" . mysqli_real_escape_string($conn, $endDate) . "' LIMIT 1";
        $q = mysqli_query($conn, $sql);
        return (bool)($q && mysqli_fetch_assoc($q));
      };

      // "Menunggak" di widget ini SEKARANG mengikuti status EXPIRED di router
      // (persis sama dgn "Expired Online" + "Expired Los" di dashboard), BUKAN
      // lagi murni hasil hitung siklus billing -- atas permintaan eksplisit
      // user 2026-08-05 supaya angkanya konsisten dgn dashboard. Cache
      // `expired_ids` ditulis tiap menit oleh cron getdata/serverload.php,
      // SUMBER PERSIS yang sama dipakai dashboard.php::getInternetStatusRows()
      // (union expired_online+expired_los = member expired_ids). Closure
      // $shouldCount/$resolveFirstDueForRow dkk TETAP dipakai di bawah, tapi
      // HANYA utk isi kolom bulan_nunggak tampilan -- bukan lagi penentu
      // boleh/tidaknya pelanggan masuk daftar.
      $statsCacheUsername = ($AKSES == 'ASSISTANT') ? $asistant_name : $ceknama;
      $statsCacheFile = __DIR__ . '/serverlog/' . $statsCacheUsername . '.txt';
      $statsExpiredLookup = [];
      if (is_file($statsCacheFile)) {
        $statsCacheDecoded = json_decode((string)@file_get_contents($statsCacheFile), true);
        if (is_array($statsCacheDecoded)) {
          $statsExpiredIdsCache = array_values(array_unique(array_filter(array_map('strval', $statsCacheDecoded['expired_ids'] ?? []))));
          $statsExpiredLookup = array_flip($statsExpiredIdsCache);
        }
      }

      $sqlMenunggakBase = "SELECT p.IDPEL, p.NAMA, p.PAKET, p.PEMILIK, p.BRAND, p.AREA, p.NOWA, p.ALAMAT, p.EMAIL, p.TIKOR, p.ODP, p.TANGGALPASANG, p.TEMPO, p.TIPE_BAYAR, p.TIPE_TEMPO, p.TANGGAL_MONTHVERSARY, t.last_paid FROM pelanggan p LEFT JOIN (SELECT IDPEL, MAX($trxTanggalExprNoAlias) AS last_paid FROM transaksi WHERE STATUS = 'BERHASIL' GROUP BY IDPEL) t ON p.IDPEL = t.IDPEL WHERE p.PEMILIK IN ($userServerList) AND p.AREA IN ($area_filter)";

      $uniqueMenunggak = [];
      $rsMenunggak = mysqli_query($conn, $sqlMenunggakBase);
      if ($rsMenunggak) {
        while ($row = mysqli_fetch_assoc($rsMenunggak)) {
          $idpelPrefilter = (string)($row['IDPEL'] ?? '');
          if ($idpelPrefilter === '' || !isset($statsExpiredLookup[$idpelPrefilter])) continue;
          $paket = isset($row['PAKET']) ? strtolower(trim((string)$row['PAKET'])) : '';
          $brand = isset($row['BRAND']) ? strtolower(trim((string)$row['BRAND'])) : '';
          $area = isset($row['AREA']) ? strtolower(trim((string)$row['AREA'])) : '';
          if ($isFasumNonPromo($paket)) continue;
          $harga = $resolveHarga($paket, $brand, $area);
          if ($harga === null || (float)$harga <= 0) continue;
          $uniqueMenunggak[$idpelPrefilter] = $row;
        }
      }

      $dataMenunggak = [];
      foreach ($uniqueMenunggak as $row) {
        $idpel = (string)$row['IDPEL'];
        $idpelEsc = mysqli_real_escape_string($conn, $idpel);
        $qLastPaid = mysqli_query($conn, "SELECT $trxTanggalExprNoAlias AS last_paid, PENGUNAAN AS last_pengunaan FROM transaksi WHERE IDPEL = '$idpelEsc' AND STATUS = 'BERHASIL' ORDER BY $trxTanggalExprNoAlias DESC LIMIT 1");
        $lastPaid = null;
        $lastPengunaan = null;
        if ($qLastPaid && ($r = mysqli_fetch_assoc($qLastPaid))) {
          $lastPaid = $r['last_paid'];
          $lastPengunaan = $r['last_pengunaan'];
        }
        $row['last_paid'] = $lastPaid;
        $row['last_pengunaan'] = $lastPengunaan;

        // Baris ini SUDAH PASTI masuk (cache expired_ids sudah jadi gerbang di
        // prefilter atas) -- closure di bawah cuma dipakai best-effort utk isi
        // kolom bulan_nunggak tampilan (siklus billing), floor 1 kalau hitungan
        // siklus tidak sepakat/tidak bisa dihitung (mis. baru pasang bulan ini
        // tapi belum pernah bayar & sudah lewat grace period, atau nuansa
        // siklus lain) -- selaras pola pelanggan_menunggak.php (lihat memory
        // project_menunggak_vs_expired_dashboard_mismatch).
        $bulanTunggak = 0;

        $statsTipeBayarRow = strtolower(trim((string)($row['TIPE_BAYAR'] ?? 'prabayar')));
        $statsLastPaidRaw = trim((string)($lastPaid ?? ''));
        if ($statsTipeBayarRow !== 'pascabayar' && $statsLastPaidRaw === '') {
          $statsTanggalPasangRow = trim((string)($row['TANGGALPASANG'] ?? ''));
          $statsTodayTsBaru = strtotime($today);
          $statsPasangTsBaru = strtotime($statsTanggalPasangRow);
          if ($statsTanggalPasangRow !== '' && $statsTodayTsBaru !== false && $statsPasangTsBaru !== false) {
            $bulanTunggak = 1;
          }
        } elseif ($shouldCount($row, $today)) {
          $reference = $getReferenceDate($row);
          $nextDueDate = $resolveFirstDueForRow($row, $reference, $fixedDueDateDay);
          if (!empty($nextDueDate) && strtotime($nextDueDate) !== false && strtotime($nextDueDate) <= strtotime($today)) {
            $todayTs = strtotime($today);
            $isConsecutive = true;
            while (strtotime($nextDueDate) <= $todayTs) {
              $cycleStart = $nextDueDate;
              $cycleEnd = $getNextDue($row, $cycleStart, $fixedDueDateDay);
              if (empty($cycleEnd) || strtotime($cycleEnd) === false) {
                break;
              }

              if ($hasSuccessfulPaymentInPeriod($idpel, $cycleStart, $cycleEnd)) {
                $isConsecutive = false;
                break;
              }

              $bulanTunggak++;
              $nextDueDate = $cycleEnd;
            }
            if (!$isConsecutive) {
              $bulanTunggak = 0;
            }
          }
        }

        $row['bulan_nunggak'] = max(1, $bulanTunggak);
        $dataMenunggak[] = $row;
      }

      $total_jatuh_tempo = count($dataMenunggak);
      $nunggak1 = 0;
      $nunggak2 = 0;
      foreach ($dataMenunggak as $rowNunggak) {
        $bulanNunggak = (int)($rowNunggak['bulan_nunggak'] ?? 0);
        if ($bulanNunggak === 1) {
          $nunggak1++;
        } elseif ($bulanNunggak >= 2) {
          $nunggak2++;
        }
      }
      $data_nunggak_1 = ['total' => $nunggak1];
      $data_nunggak_2 = ['total' => $nunggak2];
  // 8. Berhenti Berlangganan (ambil dari tabel pelanggan_berhenti)
  $sql_berhenti = "SELECT COUNT(*) as total FROM pelanggan_berhenti WHERE pemilik IN ($userServerList) AND MONTH(tanggal_berhenti) = '$bulan_ini' AND YEAR(tanggal_berhenti) = '$tahun_ini'";
  $data_berhenti = mysqli_fetch_assoc(query_or_die($conn, $sql_berhenti));


      // 10. Pemasukan Minggu Ini
  $tanggal_bayar_filter_sql = "COALESCE(
    DATE(t.TANGGALBAYAR),
    STR_TO_DATE(t.TANGGALBAYAR, '%Y-%m-%d'),
    STR_TO_DATE(
      CONCAT(
        TRIM(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
          SUBSTRING_INDEX(t.TANGGALBAYAR, ',', -1),
          'Januari', '01'
        ), 'Februari', '02'), 'Maret', '03'), 'April', '04'), 'Mei', '05'), 'Juni', '06'), 'Juli', '07'), 'Agustus', '08'), 'September', '09'), 'Oktober', '10'), 'November', '11'), 'Desember', '12'))
      ),
      '%d %m %Y'
    )
  )";

  $awal_bulan_ini = date('Y-m-01');
  $akhir_bulan_ini = date('Y-m-t');
  $awal_tahun_ini = date('Y-01-01');
  $akhir_tahun_ini = date('Y-12-31');

  $sql_minggu = "SELECT SUM(t.HARGA) as total FROM transaksi t INNER JOIN pelanggan p ON t.IDPEL = p.IDPEL WHERE t.STATUS = 'BERHASIL' AND $tanggal_bayar_filter_sql >= '$senin_ini' AND $tanggal_bayar_filter_sql <= '$minggu_ini' AND p.PEMILIK IN ($userServerList) AND p.AREA IN ($area_filter)";
  $data_minggu = mysqli_fetch_assoc(query_or_die($conn, $sql_minggu));

      // 11. Pemasukan Bulan Ini
  $sql_bulan = "SELECT SUM(t.HARGA) as total FROM transaksi t INNER JOIN pelanggan p ON t.IDPEL = p.IDPEL WHERE t.STATUS = 'BERHASIL' AND $tanggal_bayar_filter_sql >= '$awal_bulan_ini' AND $tanggal_bayar_filter_sql <= '$akhir_bulan_ini' AND p.PEMILIK IN ($userServerList) AND p.AREA IN ($area_filter)";
  $data_bulan = mysqli_fetch_assoc(query_or_die($conn, $sql_bulan));
   // 12. Pemasukan Hari Ini
  $sql_hari = "SELECT SUM(t.HARGA) as total FROM transaksi t INNER JOIN pelanggan p ON t.IDPEL = p.IDPEL WHERE t.STATUS = 'BERHASIL' AND $tanggal_bayar_filter_sql = '$today' AND p.PEMILIK IN ($userServerList) AND p.AREA IN ($area_filter)";
  $data_hari = mysqli_fetch_assoc(query_or_die($conn, $sql_hari));

  // 13. Pemasukan Tahun Ini
  $sql_tahun = "SELECT SUM(t.HARGA) as total FROM transaksi t INNER JOIN pelanggan p ON t.IDPEL = p.IDPEL WHERE t.STATUS = 'BERHASIL' AND $tanggal_bayar_filter_sql >= '$awal_tahun_ini' AND $tanggal_bayar_filter_sql <= '$akhir_tahun_ini' AND p.PEMILIK IN ($userServerList) AND p.AREA IN ($area_filter)";
  $data_tahun = mysqli_fetch_assoc(query_or_die($conn, $sql_tahun));
     

     
     ?>
      <div class="row mb-4">
        <div class="col-md-3 mb-3">
          <div class="card shadow-sm h-100">
            <div class="card-body text-center" style="padding: 10px;">
              <div class="fw-bold text-primary" style="font-size: 0.85em;">Total pelanggan</div>
              <div style="font-size: 1.8em; color: #28a745; font-weight: bold;"><?php echo $data_aktif['total']; ?></div>
              <button class="btn btn-primary btn-sm mt-1" data-bs-toggle="modal" data-bs-target="#customerModal" style="font-weight: 600;">Lihat Detail</button>
            </div>
          </div>
        </div>





        <div class="col-md-3 mb-3">
          <div class="card shadow-sm h-100">
            <div class="card-body text-center" style="padding: 10px;">
              <div class="fw-bold text-success" style="font-size: 0.85em;">Sudah Bayar Bulan Ini</div>
              <div style="font-size: 1.8em; color: #28a745; font-weight: bold;"><?php echo $data_sudah_bayar['total']; ?></div>
            </div>
          </div>
        </div>
        <div class="col-md-3 mb-3">
          <div class="card shadow-sm h-100">
            <div class="card-body text-center" style="padding: 10px;">
              <div class="fw-bold text-info" style="font-size: 0.85em;">Invoice Terkirim</div>
              <div style="font-size: 1.8em; color: #17a2b8; font-weight: bold;"><?php echo $data_invoice['total']; ?></div>
            </div>
          </div>
        </div>
        <div class="col-md-3 mb-3">
          <div class="card shadow-sm h-100">
            <div class="card-body text-center" style="padding: 10px;">
              <div class="fw-bold text-warning" style="font-size: 0.85em;">Belum Bayar Bulan Ini</div>
              <div style="font-size: 1.8em; color: #ffc107; font-weight: bold;"><?php echo $data_belum_bayar['total']; ?></div>
            </div>
          </div>
        </div>
        <div class="col-md-3 mb-3">
          <div class="card shadow-sm h-100">
            <div class="card-body text-center" style="padding: 10px;">
              <div class="fw-bold text-danger" style="font-size: 0.85em;">Lewat Jatuh Tempo</div>
              <div style="font-size: 1.8em; color: #dc3545; font-weight: bold;"><?php echo $total_jatuh_tempo; ?></div>
            </div>
          </div>
        </div>
        <?php

// ========================
// Ambil mapping harga paket
// ========================
// Hasil query di-lewatkan reseller_filter_rows() supaya ikut filter harga
// reseller/mitra (custom_harga per paket, plus paket yang tidak di-enable utk
// reseller ini otomatis tidak ikut masuk peta) -- transparan/no-op utk sesi
// bukan reseller (return apa adanya). Sebelum fix ini, statistics.php SELALU
// pakai HARGA asli dari tabel paket, tidak peduli sesi reseller sudah setting
// custom_harga atau belum.
$harga_paket_map = [];
$fasum_paket_list = [];
$q_paket_map = mysqli_query($conn, "SELECT id, PAKET, HARGA, BRAND, AREA FROM paket");
$rows_paket_map = reseller_filter_rows($conn, reseller_collect_rows($q_paket_map), 'broadband');
foreach ($rows_paket_map as $r) {
  $paket_key = strtolower(trim($r['PAKET']));
  $brand_key = isset($r['BRAND']) ? strtolower(trim($r['BRAND'])) : '';
  $area_key = isset($r['AREA']) ? strtolower(trim($r['AREA'])) : '';
  $map_key = $paket_key . '|' . $brand_key . '|' . $area_key;
  $harga_paket_map[$map_key] = $r['HARGA'];
  if ($r['HARGA'] === '' || $r['HARGA'] == 0) {
    $fasum_paket_list[$paket_key] = $r['id']; // simpan id untuk cek promo
  }
}

// ========================
// Ambil daftar paket promo
// ========================
$promo_paket_ids = [];
$q_promo = mysqli_query($conn, "SELECT paket_id FROM promo_paket");
while ($r = mysqli_fetch_assoc($q_promo)) {
  $promo_paket_ids[] = $r['paket_id'];
}



// ========================
// Isi data_fasum dengan pelanggan FASUM (harga 0 dan bukan promo)
// ========================
// Dulu di sini ada query SELECT ... FROM paket per-baris pelanggan (N+1 --
// bisa ratusan/ribuan query tambahan tergantung jumlah pelanggan, penyebab
// utama loading statistics.php lambat), padahal peta $fasum_paket_list yang
// PERSIS sama sudah dibangun di atas (baris ~817). Diganti pakai map itu
// langsung -- sekaligus otomatis ikut ter-filter reseller krn map-nya sudah
// difilter di atas.
$data_fasum = [];
$sql_pelanggan = "SELECT p.IDPEL, p.NAMA, p.PAKET, p.AREA FROM pelanggan p WHERE p.PEMILIK IN ($userServerList) AND p.AREA IN ($area_filter)";
$result = mysqli_query($conn, $sql_pelanggan);
while ($row = mysqli_fetch_assoc($result)) {
  $paket_pelanggan = strtolower(trim($row['PAKET']));
  if (isset($fasum_paket_list[$paket_pelanggan])) {
    $paket_id = $fasum_paket_list[$paket_pelanggan];
    if (!in_array((string)$paket_id, array_map('strval', $promo_paket_ids), true)) {
      $data_fasum[] = $row;
    }
  }
}

// ========================
// Gabungkan data menunggak utama, nunggak 1 bulan, dan nunggak 2 bulan+
// ========================
$filter_bulan_nunggak = isset($_GET['bulan_nunggak']) && $_GET['bulan_nunggak'] !== '' ? (int)$_GET['bulan_nunggak'] : null;
$data_menunggak = [];
foreach ($dataMenunggak as $row) {
  $paket_pelanggan = isset($row['PAKET']) ? strtolower(trim((string)$row['PAKET'])) : '';
  $brand_pelanggan = isset($row['BRAND']) ? strtolower(trim((string)$row['BRAND'])) : '';
  $area_pelanggan = isset($row['AREA']) ? strtolower(trim((string)$row['AREA'])) : '';
  $map_key = $paket_pelanggan . '|' . $brand_pelanggan . '|' . $area_pelanggan;

  if ($paket_pelanggan !== '' && isset($fasum_paket_list[$paket_pelanggan])) {
    $paket_id_fasum = (string)$fasum_paket_list[$paket_pelanggan];
    if (!in_array($paket_id_fasum, array_map('strval', $promo_paket_ids), true)) {
      continue;
    }
  }

  $harga_paket = null;
  if (isset($harga_paket_map[$map_key])) {
    $harga_paket = $harga_paket_map[$map_key];
  } elseif (isset($harga_paket_map[$paket_pelanggan . '||' . $area_pelanggan])) {
    $harga_paket = $harga_paket_map[$paket_pelanggan . '||' . $area_pelanggan];
  } elseif (isset($harga_paket_map[$paket_pelanggan . '|' . $brand_pelanggan . '|'])) {
    $harga_paket = $harga_paket_map[$paket_pelanggan . '|' . $brand_pelanggan . '|'];
  } elseif (isset($harga_paket_map[$paket_pelanggan . '||'])) {
    $harga_paket = $harga_paket_map[$paket_pelanggan . '||'];
  } elseif (isset($harga_paket_map[$paket_pelanggan])) {
    $harga_paket = $harga_paket_map[$paket_pelanggan];
  }

  if ($harga_paket === null || (float)$harga_paket <= 0) {
    continue;
  }

  $idpel = (string)($row['IDPEL'] ?? '');
  if ($idpel !== '') {
    $idpelEsc = mysqli_real_escape_string($conn, $idpel);
    $sqlLastPaid = "SELECT $trxTanggalExprNoAlias AS last_paid, PENGUNAAN AS last_pengunaan FROM transaksi WHERE IDPEL = '$idpelEsc' AND STATUS = 'BERHASIL' ORDER BY $trxTanggalExprNoAlias DESC LIMIT 1";
    $qLastPaid = mysqli_query($conn, $sqlLastPaid);
    if ($qLastPaid && ($rLast = mysqli_fetch_assoc($qLastPaid))) {
      $row['last_paid'] = $rLast['last_paid'] ?? ($row['last_paid'] ?? null);
      $row['last_pengunaan'] = $rLast['last_pengunaan'] ?? null;
    }
  }

  $row['harga_paket'] = (float)$harga_paket;
  if ($filter_bulan_nunggak !== null && (int)$row['bulan_nunggak'] !== $filter_bulan_nunggak) {
    continue;
  }

  $data_menunggak[] = $row;
}

usort($data_menunggak, function ($a, $b) {
  return ((int)($b['bulan_nunggak'] ?? 0)) <=> ((int)($a['bulan_nunggak'] ?? 0));
});

if (!function_exists('extractKendalaFromJoblistDataStatistics')) {
  function extractKendalaFromJoblistDataStatistics($jobData) {
    $jobData = (string)$jobData;
    if ($jobData === '') {
      return '';
    }
    if (preg_match('/KENDALA\s*:\s*(.+)$/mi', $jobData, $matches)) {
      return trim((string)$matches[1]);
    }
    return '';
  }
}

$existingTicketMap = [];
$menunggakIdList = [];
foreach ($data_menunggak as $rowMenunggak) {
  if (!empty($rowMenunggak['IDPEL'])) {
    $menunggakIdList[] = (string)$rowMenunggak['IDPEL'];
  }
}
$menunggakIdList = array_values(array_unique($menunggakIdList));

if (!empty($menunggakIdList)) {
  require_once __DIR__ . '/koneksidbabsensi2.php';
  if (isset($conn2) && $conn2) {
    $escapedIdpel = [];
    foreach ($menunggakIdList as $idpelValue) {
      $escapedIdpel[] = "'" . mysqli_real_escape_string($conn2, (string)$idpelValue) . "'";
    }

    if (!empty($escapedIdpel)) {
      $idpelIn = implode(',', $escapedIdpel);
      $idpelExpr = "TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(data, CHAR(10), 1), ':', -1))";
      $sqlTicketExisting = "SELECT id, status, report, data, $idpelExpr AS idpel_from_data FROM joblist WHERE status IN ('BARU','PENDING','CANCEL') AND data LIKE 'ID PELANGGAN :%' AND $idpelExpr IN ($idpelIn) ORDER BY id DESC";
      $resultTicketExisting = mysqli_query($conn2, $sqlTicketExisting);

      while ($resultTicketExisting && ($ticketRow = mysqli_fetch_assoc($resultTicketExisting))) {
        $idpelFromData = trim((string)($ticketRow['idpel_from_data'] ?? ''));
        if ($idpelFromData === '' || isset($existingTicketMap[$idpelFromData])) {
          continue;
        }

        $ticketNote = trim((string)($ticketRow['report'] ?? ''));
        if ($ticketNote === '') {
          $ticketNote = extractKendalaFromJoblistDataStatistics((string)($ticketRow['data'] ?? ''));
        }

        $existingTicketMap[$idpelFromData] = [
          'status' => strtoupper(trim((string)($ticketRow['status'] ?? ''))),
          'note' => $ticketNote,
          'id' => (int)($ticketRow['id'] ?? 0)
        ];
      }
    }
  }
}

// Pastikan assignment data_menunggak_1, data_menunggak_2, total_nunggak_1, total_nunggak_2 di dalam blok PHP sebelum HTML
$data_menunggak_1 = [];
$data_menunggak_2 = [];
foreach ($data_menunggak as $row) {
  if ($row['bulan_nunggak'] == 1) {
    $data_menunggak_1[] = $row;
  } elseif ($row['bulan_nunggak'] >= 2) {
    $data_menunggak_2[] = $row;
  }
}
$total_nunggak_1 = count($data_menunggak_1);
$total_nunggak_2 = count($data_menunggak_2);
?>
        <div class="col-md-3 mb-3">
          <div class="card shadow-sm h-100">
            <div class="card-body text-center" style="padding: 8px;">
              <div class="fw-bold text-danger" style="font-size: 0.75em;">Nunggak 1 Bulan</div>
              <div style="font-size: 1.4em; color: #dc3545; font-weight: bold;"><?php echo $total_nunggak_1; ?></div>
            </div>
          </div>
        </div>
        <div class="col-md-3 mb-3">
          <div class="card shadow-sm h-100">
            <div class="card-body text-center" style="padding: 8px;">
              <div class="fw-bold text-danger" style="font-size: 0.75em;">Nunggak 2 Bulan+</div>
              <div style="font-size: 1.4em; color: #dc3545; font-weight: bold;"><?php echo $total_nunggak_2; ?></div>
            </div>
          </div>
        </div>
        <div class="col-md-3 mb-3">
          <div class="card shadow-sm h-100">
            <div class="card-body text-center" style="padding: 8px;">
              <div class="fw-bold text-secondary" style="font-size: 0.75em;">Berhenti Berlangganan</div>
              <div style="font-size: 1.4em; color: #6c757d; font-weight: bold;"><?php echo $data_berhenti['total']; ?></div>
                <a href="daftar_pelanggan_berhenti.php?bulan=<?php echo $filter_bulan; ?>&tahun=<?php echo $filter_tahun; ?>" class="btn btn-primary btn-sm mt-1" style="font-weight: 600;">Lihat</a>
            </div>
          </div>
        </div>
        <?php
        // Tampilkan pemasukan jika filter default (bulan/tahun sekarang) atau tidak diatur
        $is_default_filter = (
          (!isset($_GET['bulan']) && !isset($_GET['tahun'])) ||
          ($filter_bulan == (int)date('m') && $filter_tahun == (int)date('Y'))
        );
        if ($is_default_filter) {
        ?>
        <div class="col-md-3 mb-3">
          <div class="card shadow-sm h-100">
            <div class="card-body text-center" style="padding: 8px;">
              <div class="fw-bold text-primary" style="font-size: 0.75em;">Pemasukan Hari Ini</div>
              <div style="font-size: 1.2em; color: #2563eb; font-weight: bold;">Rp. <?php echo number_format($data_hari['total'] ?? 0, 0, ',', '.'); ?></div>
              <a href="Transaction.php?hari=<?php echo date('Y-m-d'); ?>" class="btn btn-primary btn-sm mt-1" style="font-weight: 600;">Lihat</a>
            </div>
          </div>
        </div>
        <div class="col-md-3 mb-3">
          <div class="card shadow-sm h-100">
            <div class="card-body text-center" style="padding: 8px;">
              <div class="fw-bold text-primary" style="font-size: 0.75em;">Pemasukan Minggu Ini</div>
              <div style="font-size: 1.2em; color: #2563eb; font-weight: bold;">Rp. <?php echo number_format($data_minggu['total'] ?? 0, 0, ',', '.'); ?></div>
            </div>
          </div>
        </div>
        <div class="col-md-3 mb-3">
          <div class="card shadow-sm h-100">
            <div class="card-body text-center" style="padding: 8px;">
              <div class="fw-bold text-primary" style="font-size: 0.75em;">Pemasukan Bulan Ini</div>
              <div style="font-size: 1.2em; color: #2563eb; font-weight: bold;">Rp. <?php echo number_format($data_bulan['total'] ?? 0, 0, ',', '.'); ?></div>
            </div>
          </div>
        </div>
        <div class="col-md-3 mb-3">
          <div class="card shadow-sm h-100">
            <div class="card-body text-center" style="padding: 8px;">
              <div class="fw-bold text-primary" style="font-size: 0.75em;">Pemasukan Tahun Ini</div>
              <div style="font-size: 1.2em; color: #2563eb; font-weight: bold;">Rp. <?php echo number_format($data_tahun['total'] ?? 0, 0, ',', '.'); ?></div>
            </div>
          </div>
        </div>
        <!-- Card Pengeluaran Hari Ini, Bulan Ini, Tahun Ini -->
        <?php
          // Query total pengeluaran hari ini, bulan ini, tahun ini
          $where_pengeluaran_hari = "WHERE pemilik = '" . mysqli_real_escape_string($conn, $ceknama) . "' AND DATE(tanggal) = '" . date('Y-m-d') . "'";
          $sql_pengeluaran_hari = "SELECT SUM(jumlah) as total FROM pengeluaran $where_pengeluaran_hari";
          $result_pengeluaran_hari = mysqli_query($conn, $sql_pengeluaran_hari);
          $total_pengeluaran_hari = ($result_pengeluaran_hari && ($row = mysqli_fetch_assoc($result_pengeluaran_hari))) ? $row['total'] : 0;

          // Pengeluaran Minggu Ini
          $senin_ini = date('Y-m-d', strtotime('monday this week'));
          $minggu_ini = date('Y-m-d', strtotime('sunday this week'));
          $where_pengeluaran_minggu = "WHERE pemilik = '" . mysqli_real_escape_string($conn, $ceknama) . "' AND DATE(tanggal) >= '$senin_ini' AND DATE(tanggal) <= '$minggu_ini'";
          $sql_pengeluaran_minggu = "SELECT SUM(jumlah) as total FROM pengeluaran $where_pengeluaran_minggu";
          $result_pengeluaran_minggu = mysqli_query($conn, $sql_pengeluaran_minggu);
          $total_pengeluaran_minggu = ($result_pengeluaran_minggu && ($row = mysqli_fetch_assoc($result_pengeluaran_minggu))) ? $row['total'] : 0;

          $where_pengeluaran_bulan = "WHERE pemilik = '" . mysqli_real_escape_string($conn, $ceknama) . "' AND MONTH(tanggal) = '" . str_pad($filter_bulan, 2, '0', STR_PAD_LEFT) . "' AND YEAR(tanggal) = '" . $filter_tahun . "'";
          $sql_pengeluaran_bulan = "SELECT SUM(jumlah) as total FROM pengeluaran $where_pengeluaran_bulan";
          $result_pengeluaran_bulan = mysqli_query($conn, $sql_pengeluaran_bulan);
          $total_pengeluaran_bulan = ($result_pengeluaran_bulan && ($row = mysqli_fetch_assoc($result_pengeluaran_bulan))) ? $row['total'] : 0;

          $where_pengeluaran_tahun = "WHERE pemilik = '" . mysqli_real_escape_string($conn, $ceknama) . "' AND YEAR(tanggal) = '" . $filter_tahun . "'";
          $sql_pengeluaran_tahun = "SELECT SUM(jumlah) as total FROM pengeluaran $where_pengeluaran_tahun";
          $result_pengeluaran_tahun = mysqli_query($conn, $sql_pengeluaran_tahun);
          $total_pengeluaran_tahun = ($result_pengeluaran_tahun && ($row = mysqli_fetch_assoc($result_pengeluaran_tahun))) ? $row['total'] : 0;
        ?>
       
          <div class="col-md-3 mb-3">
            <div class="card shadow-sm h-100">
              <div class="card-body text-center">
                <div class="fw-bold text-danger">Pengeluaran Hari Ini</div>
                <div class="display-6 text-danger">Rp. <?php echo number_format($total_pengeluaran_hari, 0, ',', '.'); ?></div>
                <a href="pengeluaran.php?hari=<?php echo date('Y-m-d'); ?>" class="btn btn-danger btn-sm mt-2">Lihat</a>
              </div>
            </div>
          </div>
          <div class="col-md-3 mb-3">
            <div class="card shadow-sm h-100">
              <div class="card-body text-center">
                <div class="fw-bold text-danger">Pengeluaran Minggu Ini</div>
                <div class="display-6 text-danger">Rp. <?php echo number_format($total_pengeluaran_minggu, 0, ',', '.'); ?></div>
              </div>
            </div>
          </div>
          <div class="col-md-3 mb-3">
            <div class="card shadow-sm h-100">
              <div class="card-body text-center">
                <div class="fw-bold text-danger">Pengeluaran Bulan Ini</div>
                <div class="display-6 text-danger">Rp. <?php echo number_format($total_pengeluaran_bulan, 0, ',', '.'); ?></div>
              </div>
            </div>
          </div>
          <div class="col-md-3 mb-3">
            <div class="card shadow-sm h-100">
              <div class="card-body text-center">
                <div class="fw-bold text-danger">Pengeluaran Tahun Ini</div>
                <div class="display-6 text-danger">Rp. <?php echo number_format($total_pengeluaran_tahun, 0, ',', '.'); ?></div>
              </div>
            </div>
          </div>
        </div>
      <!-- Tombol Lihat Pemasukan global dihapus, sekarang hanya di kartu Hari Ini -->
        <?php 
        } else {
        // Jika difilter, tampilkan hanya pendapatan bulan yang difilter
        $nama_bulan = [1=>'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
        $bulan_label = isset($nama_bulan[(int)$filter_bulan]) ? $nama_bulan[(int)$filter_bulan] : $filter_bulan;
        ?>
        <div class="col-md-3 mb-3">
          <div class="card shadow-sm h-100">
            <div class="card-body text-center">
              <div class="fw-bold text-primary">Pendapatan Bulan <?php echo $bulan_label; ?></div>
              <div class="display-6 text-primary">Rp. <?php echo number_format($data_bulan['total'] ?? 0, 0, ',', '.'); ?></div>
            </div>
          </div>
        </div>
        <div class="col-md-3 mb-3">
          <div class="card shadow-sm h-100">
            <div class="card-body text-center">
              <div class="fw-bold text-danger">Pengeluaran Bulan <?php echo $bulan_label; ?></div>
              <div class="display-6 text-danger">Rp. <?php
                // Query pengeluaran bulan yang difilter
                $where_pengeluaran_bulan = "WHERE pemilik = '" . mysqli_real_escape_string($conn, $ceknama) . "' AND MONTH(tanggal) = '" . str_pad($filter_bulan, 2, '0', STR_PAD_LEFT) . "' AND YEAR(tanggal) = '" . $filter_tahun . "'";
                $sql_pengeluaran_bulan = "SELECT SUM(jumlah) as total FROM pengeluaran $where_pengeluaran_bulan";
                $result_pengeluaran_bulan = mysqli_query($conn, $sql_pengeluaran_bulan);
                $total_pengeluaran_bulan = ($result_pengeluaran_bulan && ($row = mysqli_fetch_assoc($result_pengeluaran_bulan))) ? $row['total'] : 0;
                echo number_format($total_pengeluaran_bulan, 0, ',', '.');
              ?></div>
            </div>
          </div>
        </div>
        <?php } ?>
   </div>
   </div>   

</div>  
              
        <div class="col-12">
          <div class="card border-info shadow-sm mb-4">
            <div class="card-header bg-info text-white">
              <h5 class="mb-0"><i class="fas fa-balance-scale me-2"></i>Neraca Keuangan (Finance Balance Sheet)</h5>
            </div>
            <div class="card-body">
              <?php
                // Query OPEX dan CAPEX bulan ini
                $where_neraca = "WHERE pemilik = '" . mysqli_real_escape_string($conn, $ceknama) . "' AND MONTH(tanggal) = '" . str_pad($filter_bulan, 2, '0', STR_PAD_LEFT) . "' AND YEAR(tanggal) = '" . $filter_tahun . "'";
                $sql_opex = "SELECT SUM(jumlah) as total FROM pengeluaran $where_neraca AND kategori = 'OPEX'";
                $sql_capex = "SELECT SUM(jumlah) as total FROM pengeluaran $where_neraca AND kategori = 'CAPEX'";
                $result_opex = mysqli_query($conn, $sql_opex);
                $result_capex = mysqli_query($conn, $sql_capex);
                $total_opex = ($result_opex && ($row = mysqli_fetch_assoc($result_opex))) ? $row['total'] : 0;
                $total_capex = ($result_capex && ($row = mysqli_fetch_assoc($result_capex))) ? $row['total'] : 0;
                // Pemasukan (omzet) dan total pengeluaran
                $sql_pemasukan = "SELECT SUM(t.HARGA) as total FROM transaksi t INNER JOIN pelanggan p ON t.IDPEL = p.IDPEL WHERE t.STATUS = 'BERHASIL' AND MONTH($trx_tanggal_expr_t) = '" . str_pad($filter_bulan, 2, '0', STR_PAD_LEFT) . "' AND YEAR($trx_tanggal_expr_t) = '" . $filter_tahun . "' AND p.PEMILIK IN ($userServerList) AND p.AREA IN ($userAreaList)";
                $result_pemasukan = mysqli_query($conn, $sql_pemasukan);
                $total_pemasukan = ($result_pemasukan && ($row = mysqli_fetch_assoc($result_pemasukan))) ? $row['total'] : 0;
                $sql_pengeluaran = "SELECT SUM(jumlah) as total FROM pengeluaran $where_neraca";
                $result_pengeluaran = mysqli_query($conn, $sql_pengeluaran);
                $total_pengeluaran = ($result_pengeluaran && ($row = mysqli_fetch_assoc($result_pengeluaran))) ? $row['total'] : 0;
                $laba_bersih = $total_pemasukan - $total_pengeluaran;
                // Saldo akhir (misal: saldo awal + pemasukan - pengeluaran), saldo awal bisa di-set manual jika perlu
                $saldo_awal = 0; // Bisa diubah jika ada fitur saldo awal
                $saldo_akhir = $saldo_awal + $total_pemasukan - $total_pengeluaran;
              ?>
              <div class="row justify-content-center mb-2">
                <div class="col-md-6 col-12 mb-3">
                  <table class="table table-bordered mb-0">
                    <tr><th class="text-start">Total Pemasukan</th><td class="text-end">Rp. <?php echo number_format($total_pemasukan, 0, ',', '.'); ?></td></tr>
                    <tr><th class="text-start">Total Pengeluaran</th><td class="text-end">Rp. <?php echo number_format($total_pengeluaran, 0, ',', '.'); ?></td></tr>
                    <tr><th class="text-start">OPEX</th><td class="text-end">Rp. <?php echo number_format($total_opex, 0, ',', '.'); ?></td></tr>
                    <tr><th class="text-start">CAPEX</th><td class="text-end">Rp. <?php echo number_format($total_capex, 0, ',', '.'); ?></td></tr>
                    <tr><th class="text-start">Laba Bersih</th><td class="text-end fw-bold text-success">Rp. <?php echo number_format($laba_bersih, 0, ',', '.'); ?></td></tr>
                    <tr><th class="text-start">Saldo Akhir</th><td class="text-end fw-bold text-primary">Rp. <?php echo number_format($saldo_akhir, 0, ',', '.'); ?></td></tr>
                  </table>
                </div>
              </div>
              <div class="text-muted small mt-2 text-center">
                *OPEX dan CAPEX diambil dari kategori pengeluaran. Pastikan kategori sudah sesuai.<br>
                *Saldo akhir = saldo awal + pemasukan - pengeluaran.<br>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Laporan Pajak UMKM/PT (PPH Final 0.5% dari omzet) -->
      <div class="row mb-4">
        <div class="col-12">
          <div class="card border-warning shadow-sm">
            <div class="card-header bg-warning text-dark">
              <h5 class="mb-0"><i class="fas fa-receipt me-2"></i>Laporan Pajak UMKM / PT dan BHP USO</h5>
            </div>
            <div class="card-body">
              <?php
                // Hitung total pemasukan bulan yang difilter
                $where_pendapatan_bulan = "WHERE t.STATUS = 'BERHASIL' AND MONTH($trx_tanggal_expr_t) = '" . str_pad($filter_bulan, 2, '0', STR_PAD_LEFT) . "' AND YEAR($trx_tanggal_expr_t) = '" . $filter_tahun . "' AND p.PEMILIK IN ($userServerList) AND p.AREA IN ($userAreaList)";
                $sql_pendapatan_bulan = "SELECT SUM(t.HARGA) as total FROM transaksi t INNER JOIN pelanggan p ON t.IDPEL = p.IDPEL $where_pendapatan_bulan";
                $result_pendapatan_bulan = mysqli_query($conn, $sql_pendapatan_bulan);
                $total_pendapatan_bulan = ($result_pendapatan_bulan && ($row = mysqli_fetch_assoc($result_pendapatan_bulan))) ? $row['total'] : 0;
                // Hitung total pengeluaran bulan yang difilter (untuk simulasi laba PT)
                $where_pengeluaran_bulan = "WHERE pemilik = '" . mysqli_real_escape_string($conn, $ceknama) . "' AND MONTH(tanggal) = '" . str_pad($filter_bulan, 2, '0', STR_PAD_LEFT) . "' AND YEAR(tanggal) = '" . $filter_tahun . "'";
                $sql_pengeluaran_bulan = "SELECT SUM(jumlah) as total FROM pengeluaran $where_pengeluaran_bulan";
                $result_pengeluaran_bulan = mysqli_query($conn, $sql_pengeluaran_bulan);
                $total_pengeluaran_bulan = ($result_pengeluaran_bulan && ($row = mysqli_fetch_assoc($result_pengeluaran_bulan))) ? $row['total'] : 0;
                $laba_bulan = $total_pendapatan_bulan - $total_pengeluaran_bulan;
                // Pajak UMKM
                $pph_umkm = $total_pendapatan_bulan * 0.005;
                // Pajak PT
                $pph_pt = ($laba_bulan > 0 ? $laba_bulan : 0) * 0.22;
                // Pajak BHP USO (misal 1.25% dari omzet)
                $bhp_uso = $total_pendapatan_bulan * 0.0125;
              ?>
              <div class="row justify-content-center mb-2">
                <div class="col-md-4 col-12 mb-3">
                  <div class="card h-100 border-success">
                    <div class="card-header bg-success text-white py-2"><b>UMKM (PP 23/2018)</b></div>
                    <div class="card-body text-center">
                      <table class="table table-bordered mb-0">
                        <tr><th class="text-start">Total Omzet Bulan Ini</th><td class="text-end">Rp. <?php echo number_format($total_pendapatan_bulan, 0, ',', '.'); ?></td></tr>
                        <tr><th class="text-start">Tarif Pajak</th><td class="text-end">0.5% (PPH Final)</td></tr>
                        <tr><th class="text-start">Pajak Terutang</th><td class="text-end text-danger fw-bold">Rp. <?php echo number_format($pph_umkm, 0, ',', '.'); ?></td></tr>
                      </table>
                    </div>
                  </div>
                </div>
                <div class="col-md-4 col-12 mb-3">
                  <div class="card h-100 border-primary">
                    <div class="card-header bg-primary text-white py-2"><b>PT (PPh Badan)</b></div>
                    <div class="card-body text-center">
                      <table class="table table-bordered mb-0">
                        <tr><th class="text-start">Total Omzet Bulan Ini</th><td class="text-end">Rp. <?php echo number_format($total_pendapatan_bulan, 0, ',', '.'); ?></td></tr>
                        <tr><th class="text-start">Total Pengeluaran Bulan Ini</th><td class="text-end">Rp. <?php echo number_format($total_pengeluaran_bulan, 0, ',', '.'); ?></td></tr>
                        <tr><th class="text-start">Laba (Omzet - Pengeluaran)</th><td class="text-end">Rp. <?php echo number_format($laba_bulan, 0, ',', '.'); ?></td></tr>
                        <tr><th class="text-start">Tarif Pajak</th><td class="text-end">22% (PPh Badan)</td></tr>
                        <tr><th class="text-start">Pajak Terutang</th><td class="text-end text-warning fw-bold">Rp. <?php echo number_format($pph_pt, 0, ',', '.'); ?></td></tr>
                      </table>
                    </div>
                  </div>
                </div>
                <div class="col-md-4 col-12 mb-3">
                  <div class="card h-100 border-warning">
                    <div class="card-header bg-warning text-dark py-2"><b>BHP USO</b></div>
                    <div class="card-body text-center">
                      <table class="table table-bordered mb-0">
                        <tr><th class="text-start">Total Omzet Bulan Ini</th><td class="text-end">Rp. <?php echo number_format($total_pendapatan_bulan, 0, ',', '.'); ?></td></tr>
                        <tr><th class="text-start">Tarif BHP USO</th><td class="text-end">1.25%</td></tr>
                        <tr><th class="text-start">BHP USO Terutang</th><td class="text-end text-warning fw-bold">Rp. <?php echo number_format($bhp_uso, 0, ',', '.'); ?></td></tr>
                      </table>
                    </div>
                  </div>
                </div>
              </div>
              <div class="text-muted small mt-2 text-center">
                *Tabel UMKM: PP 23/2018 (PPH Final 0.5% dari omzet, s.d. 2028, omzet <= 4.8M/tahun).<br>
                *Tabel PT: PPh Badan 22% dari laba (omzet - pengeluaran, simulasi, cek detail ke konsultan pajak).<br>
                <a href="https://www.pajak.go.id/id/umkm" target="_blank">Referensi Pajak UMKM</a> | <a href="https://www.pajak.go.id/id/pph-badan" target="_blank">Referensi Pajak PT</a>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Statistik Pendapatan Per Periode -->
      <div class="card shadow-sm mb-4">
        <div class="card-header bg-info text-white">
          <h5><i class="fas fa-chart-line me-2"></i>Statistik Pendapatan Per Periode</h5>
          <small>Pilih periode untuk melihat total pendapatan bulanan atau tahunan</small>
        </div>
        <div class="card-body">
          <form method="GET" class="row g-3 mb-3">
            <div class="col-md-4">
              <label for="periode" class="form-label">Pilih Periode</label>
              <select class="form-control" id="periode" name="periode">
                <option value="bulan">Bulanan</option>
                <option value="tahun">Tahunan</option>
              </select>
            </div>
            <div class="col-md-4">
              <label for="tahun" class="form-label">Tahun</label>
              <input type="number" class="form-control" id="tahun" name="tahun" value="<?= date('Y') ?>" min="2020" max="2030">
            </div>
            <div class="col-md-4">
              <button type="submit" class="btn btn-info w-100">Tampilkan</button>
            </div>
          </form>
          <?php
          $periode = $_GET['periode'] ?? 'bulan';
          $tahun = $_GET['tahun'] ?? date('Y');
          if ($periode == 'bulan') {
            $sql_pendapatan = "SELECT MONTH($trx_tanggal_expr_t) as periode, SUM(t.HARGA) as total FROM transaksi t INNER JOIN pelanggan p ON t.IDPEL = p.IDPEL WHERE YEAR($trx_tanggal_expr_t) = '$tahun' AND p.PEMILIK IN ($userServerList) AND p.AREA IN ($userAreaList) AND t.STATUS = 'BERHASIL' GROUP BY MONTH($trx_tanggal_expr_t) ORDER BY MONTH($trx_tanggal_expr_t)";
            $label = 'Bulan';
          } else {
            $sql_pendapatan = "SELECT YEAR($trx_tanggal_expr_t) as periode, SUM(t.HARGA) as total FROM transaksi t INNER JOIN pelanggan p ON t.IDPEL = p.IDPEL WHERE p.PEMILIK IN ($userServerList) AND p.AREA IN ($userAreaList) AND t.STATUS = 'BERHASIL' GROUP BY YEAR($trx_tanggal_expr_t) ORDER BY YEAR($trx_tanggal_expr_t)";
            $label = 'Tahun';
          }
          $result_pendapatan = mysqli_query($conn, $sql_pendapatan);
          ?>
          <div class="table-responsive">
            <table class="table table-striped" id="tabel-statistik-pendapatan">
            <thead>
              <tr>
                <th><?php echo $label; ?></th>
                <th>Total Pendapatan</th>
              </tr>
            </thead>
            <tbody>
              <?php 
              if (mysqli_num_rows($result_pendapatan) > 0) {
                while ($row = mysqli_fetch_assoc($result_pendapatan)) { ?>
                  <tr>
                    <td><?php echo $row['periode']; ?></td>
                    <td>Rp. <?php echo number_format($row['total'], 0, ',', '.'); ?></td>
                  </tr>
                <?php } 
              } else { ?>
                <tr>
                  <td class="text-center text-muted">-</td><td class="text-center text-muted">Tidak ada data pendapatan untuk periode ini.</td>
                </tr>
              <?php } ?>
            </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Total Transaksi Pendapatan Per Server Area -->
      <div class="card shadow-sm mb-4">
        <div class="card-header bg-success text-white">
          <h5><i class="fas fa-server me-2"></i>Total Transaksi Pendapatan Per Server Area</h5>
          <small>Total pendapatan berdasarkan server dan area untuk bulan/tahun yang dipilih</small>
        </div>
        <div class="card-body">
          <!-- Filter Bulan & Tahun untuk Tabel Ini -->
          <form method="GET" class="row g-3 mb-3">
            <div class="col-md-4">
              <label for="bulan_server" class="form-label">Bulan</label>
              <select name="bulan_server" id="bulan_server" class="form-select">
                <?php
                $bulan_server = isset($_GET['bulan_server']) ? (int)$_GET['bulan_server'] : $filter_bulan;
                for ($b = 1; $b <= 12; $b++) {
                  $selected = ($b == $bulan_server) ? 'selected' : '';
                  printf('<option value="%02d" %s>%s</option>', $b, $selected, date('F', mktime(0,0,0,$b,1)));
                }
                ?>
              </select>
            </div>
            <div class="col-md-4">
              <label for="tahun_server" class="form-label">Tahun</label>
              <select name="tahun_server" id="tahun_server" class="form-select">
                <?php
                $tahun_server = isset($_GET['tahun_server']) ? (int)$_GET['tahun_server'] : $filter_tahun;
                $tahun_sekarang = (int)date('Y');
                for ($t = $tahun_sekarang-5; $t <= $tahun_sekarang+1; $t++) {
                  $selected = ($t == $tahun_server) ? 'selected' : '';
                  echo "<option value='$t' $selected>$t</option>";
                }
                ?>
              </select>
            </div>
            <div class="col-md-4">
              <button type="submit" class="btn btn-success w-100">Tampilkan</button>
            </div>
          </form>
          <?php
          // Query total pendapatan per server area dengan filter khusus
          $sql_pendapatan_server_area = "SELECT p.PEMILIK, p.AREA, SUM(t.HARGA) as total_pendapatan, COUNT(t.IDPEL) as jumlah_transaksi FROM transaksi t INNER JOIN pelanggan p ON t.IDPEL = p.IDPEL WHERE t.STATUS = 'BERHASIL' AND MONTH($trx_tanggal_expr_t) = '$bulan_server' AND YEAR($trx_tanggal_expr_t) = '$tahun_server' AND p.PEMILIK IN ($userServerList) AND p.AREA IN ($userAreaList) GROUP BY p.PEMILIK, p.AREA ORDER BY total_pendapatan DESC";
          $result_pendapatan_server_area = mysqli_query($conn, $sql_pendapatan_server_area);
          ?>
          <div class="table-responsive">
            <table class="table table-striped" id="tabel-pendapatan-server-area">
              <thead>
                <tr>
                  <th>Server (PEMILIK)</th>
                  <th>Area</th>
                  <th>Jumlah Transaksi</th>
                  <th>Total Pendapatan</th>
                </tr>
              </thead>
              <tbody>
                <?php
                if (mysqli_num_rows($result_pendapatan_server_area) > 0) {
                  while ($row = mysqli_fetch_assoc($result_pendapatan_server_area)) { ?>
                    <tr>
                      <td><?php echo htmlspecialchars($row['PEMILIK']); ?></td>
                      <td><?php echo htmlspecialchars($row['AREA']); ?></td>
                      <td><?php echo $row['jumlah_transaksi']; ?></td>
                      <td>Rp. <?php echo number_format($row['total_pendapatan'], 0, ',', '.'); ?></td>
                    </tr>
                  <?php }
                } else { ?>
                  <tr>
                    <td class="text-center text-muted">-</td>
                    <td class="text-center text-muted">-</td>
                    <td class="text-center text-muted">-</td>
                    <td class="text-center text-muted">Tidak ada data pendapatan untuk server/area ini.</td>
                  </tr>
                <?php } ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Summary Payment Method Terbanyak (Per Bulan/Tahun) -->
      <div class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white">
          <h5><i class="fas fa-credit-card me-2"></i>Summary Payment Method Terbanyak</h5>
          <small>Ringkasan metode pembayaran terbanyak per bulan dan tahun</small>
        </div>
        <div class="card-body">
          <form method="GET" class="row g-3 mb-3">
            <div class="col-md-4">
              <label for="bulan_payment_method" class="form-label">Bulan</label>
              <select name="bulan_payment_method" id="bulan_payment_method" class="form-select">
                <?php
                $bulan_payment_method = isset($_GET['bulan_payment_method']) ? (int)$_GET['bulan_payment_method'] : $filter_bulan;
                if ($bulan_payment_method < 1 || $bulan_payment_method > 12) {
                  $bulan_payment_method = $filter_bulan;
                }
                for ($b = 1; $b <= 12; $b++) {
                  $selected = ($b == $bulan_payment_method) ? 'selected' : '';
                  printf('<option value="%02d" %s>%s</option>', $b, $selected, date('F', mktime(0,0,0,$b,1)));
                }
                ?>
              </select>
            </div>
            <div class="col-md-4">
              <label for="tahun_payment_method" class="form-label">Tahun</label>
              <select name="tahun_payment_method" id="tahun_payment_method" class="form-select">
                <?php
                $tahun_payment_method = isset($_GET['tahun_payment_method']) ? (int)$_GET['tahun_payment_method'] : $filter_tahun;
                $tahun_sekarang = (int)date('Y');
                for ($t = $tahun_sekarang-5; $t <= $tahun_sekarang+1; $t++) {
                  $selected = ($t == $tahun_payment_method) ? 'selected' : '';
                  echo "<option value='$t' $selected>$t</option>";
                }
                ?>
              </select>
            </div>
            <div class="col-md-4">
              <button type="submit" class="btn btn-primary w-100">Tampilkan</button>
            </div>
          </form>

          <?php
          $sql_payment_method_summary = "SELECT
              COALESCE(NULLIF(TRIM(t.payment_method), ''), 'UNKNOWN') AS payment_method,
              COUNT(*) AS jumlah_transaksi,
              SUM(CAST(COALESCE(NULLIF(t.HARGA, ''), '0') AS DECIMAL(18,2))) AS total_harga_dasar,
              SUM(
                CAST(COALESCE(NULLIF(t.fee_merchant, ''), '0') AS DECIMAL(18,2)) +
                CAST(COALESCE(NULLIF(t.fee_customer, ''), '0') AS DECIMAL(18,2))
              ) AS total_fee,
              SUM(CAST(COALESCE(NULLIF(t.harga_gross, ''), '0') AS DECIMAL(18,2))) AS total_harga_gross
            FROM transaksi t
            INNER JOIN pelanggan p ON t.IDPEL = p.IDPEL
            WHERE t.STATUS = 'BERHASIL'
              AND MONTH($trx_tanggal_expr_t) = '$bulan_payment_method'
              AND YEAR($trx_tanggal_expr_t) = '$tahun_payment_method'
              AND p.PEMILIK IN ($userServerList)
              AND p.AREA IN ($userAreaList)
            GROUP BY COALESCE(NULLIF(TRIM(t.payment_method), ''), 'UNKNOWN')
            ORDER BY jumlah_transaksi DESC, total_harga_gross DESC";
          $result_payment_method_summary = mysqli_query($conn, $sql_payment_method_summary);
          ?>

          <div class="table-responsive">
            <table class="table table-striped" id="tabel-payment-method-summary">
              <thead>
                <tr>
                  <th>Ranking</th>
                  <th>Payment Method</th>
                  <th>Jumlah Transaksi</th>
                  <th>Total Harga Dasar</th>
                  <th>Total Fee</th>
                  <th>Total Harga Gross</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($result_payment_method_summary && mysqli_num_rows($result_payment_method_summary) > 0) {
                  $rankPm = 1;
                  while ($row = mysqli_fetch_assoc($result_payment_method_summary)) { ?>
                    <tr>
                      <td><?php echo (int)$rankPm++; ?></td>
                      <td><?php echo htmlspecialchars((string)$row['payment_method']); ?></td>
                      <td><?php echo (int)$row['jumlah_transaksi']; ?></td>
                      <td>Rp. <?php echo number_format((float)$row['total_harga_dasar'], 0, ',', '.'); ?></td>
                      <td>Rp. <?php echo number_format((float)$row['total_fee'], 0, ',', '.'); ?></td>
                      <td>Rp. <?php echo number_format((float)$row['total_harga_gross'], 0, ',', '.'); ?></td>
                    </tr>
                  <?php }
                } else { ?>
                  <tr>
                    <td class="text-center text-muted">-</td>
                    <td class="text-center text-muted">-</td>
                    <td class="text-center text-muted">-</td>
                    <td class="text-center text-muted">-</td>
                    <td class="text-center text-muted">-</td>
                    <td class="text-center text-muted">Tidak ada data payment method pada periode ini.</td>
                  </tr>
                <?php } ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Summary Harga/Fee/Gross per Server Area (Per Bulan/Tahun) -->
      <div class="card shadow-sm mb-4">
        <div class="card-header bg-info text-white">
          <h5><i class="fas fa-coins me-2"></i>Summary Harga Dasar, Fee, dan Gross Per Server Area</h5>
          <small>Total harga dasar, fee merchant, fee customer, dan harga gross per server area per bulan/tahun</small>
        </div>
        <div class="card-body">
          <form method="GET" class="row g-3 mb-3">
            <div class="col-md-4">
              <label for="bulan_fee_summary" class="form-label">Bulan</label>
              <select name="bulan_fee_summary" id="bulan_fee_summary" class="form-select">
                <?php
                $bulan_fee_summary = isset($_GET['bulan_fee_summary']) ? (int)$_GET['bulan_fee_summary'] : $filter_bulan;
                if ($bulan_fee_summary < 1 || $bulan_fee_summary > 12) {
                  $bulan_fee_summary = $filter_bulan;
                }
                for ($b = 1; $b <= 12; $b++) {
                  $selected = ($b == $bulan_fee_summary) ? 'selected' : '';
                  printf('<option value="%02d" %s>%s</option>', $b, $selected, date('F', mktime(0,0,0,$b,1)));
                }
                ?>
              </select>
            </div>
            <div class="col-md-4">
              <label for="tahun_fee_summary" class="form-label">Tahun</label>
              <select name="tahun_fee_summary" id="tahun_fee_summary" class="form-select">
                <?php
                $tahun_fee_summary = isset($_GET['tahun_fee_summary']) ? (int)$_GET['tahun_fee_summary'] : $filter_tahun;
                $tahun_sekarang = (int)date('Y');
                for ($t = $tahun_sekarang-5; $t <= $tahun_sekarang+1; $t++) {
                  $selected = ($t == $tahun_fee_summary) ? 'selected' : '';
                  echo "<option value='$t' $selected>$t</option>";
                }
                ?>
              </select>
            </div>
            <div class="col-md-4">
              <button type="submit" class="btn btn-info text-white w-100">Tampilkan</button>
            </div>
          </form>

          <?php
          $sql_fee_summary = "SELECT
              p.PEMILIK,
              p.AREA,
              COUNT(*) AS jumlah_transaksi,
              SUM(CAST(COALESCE(NULLIF(t.HARGA, ''), '0') AS DECIMAL(18,2))) AS total_harga_dasar,
              SUM(CAST(COALESCE(NULLIF(t.fee_merchant, ''), '0') AS DECIMAL(18,2))) AS total_fee_merchant,
              SUM(CAST(COALESCE(NULLIF(t.fee_customer, ''), '0') AS DECIMAL(18,2))) AS total_fee_customer,
              SUM(
                CAST(COALESCE(NULLIF(t.HARGA, ''), '0') AS DECIMAL(18,2)) +
                CAST(COALESCE(NULLIF(t.fee_merchant, ''), '0') AS DECIMAL(18,2)) +
                CAST(COALESCE(NULLIF(t.fee_customer, ''), '0') AS DECIMAL(18,2))
              ) AS total_harga_gross
            FROM transaksi t
            INNER JOIN pelanggan p ON t.IDPEL = p.IDPEL
            WHERE t.STATUS = 'BERHASIL'
              AND MONTH($trx_tanggal_expr_t) = '$bulan_fee_summary'
              AND YEAR($trx_tanggal_expr_t) = '$tahun_fee_summary'
              AND p.PEMILIK IN ($userServerList)
              AND p.AREA IN ($userAreaList)
            GROUP BY p.PEMILIK, p.AREA
            ORDER BY total_harga_gross DESC";
          $result_fee_summary = mysqli_query($conn, $sql_fee_summary);
          ?>

          <div class="table-responsive">
            <table class="table table-striped" id="tabel-fee-summary-server-area">
              <thead>
                <tr>
                  <th>Server (PEMILIK)</th>
                  <th>Area</th>
                  <th>Jumlah Transaksi</th>
                  <th>Total Harga Dasar</th>
                  <th>Total Fee Merchant</th>
                  <th>Total Fee Customer</th>
                  <th>Total Harga Gross</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($result_fee_summary && mysqli_num_rows($result_fee_summary) > 0) {
                  while ($row = mysqli_fetch_assoc($result_fee_summary)) { ?>
                    <tr>
                      <td><?php echo htmlspecialchars((string)$row['PEMILIK']); ?></td>
                      <td><?php echo htmlspecialchars((string)$row['AREA']); ?></td>
                      <td><?php echo (int)$row['jumlah_transaksi']; ?></td>
                      <td>Rp. <?php echo number_format((float)$row['total_harga_dasar'], 0, ',', '.'); ?></td>
                      <td>Rp. <?php echo number_format((float)$row['total_fee_merchant'], 0, ',', '.'); ?></td>
                      <td>Rp. <?php echo number_format((float)$row['total_fee_customer'], 0, ',', '.'); ?></td>
                      <td>Rp. <?php echo number_format((float)$row['total_harga_gross'], 0, ',', '.'); ?></td>
                    </tr>
                  <?php }
                } else { ?>
                  <tr>
                    <td class="text-center text-muted">-</td>
                    <td class="text-center text-muted">-</td>
                    <td class="text-center text-muted">-</td>
                    <td class="text-center text-muted">-</td>
                    <td class="text-center text-muted">-</td>
                    <td class="text-center text-muted">-</td>
                    <td class="text-center text-muted">Tidak ada data fee/harga pada periode ini.</td>
                  </tr>
                <?php } ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Pendapatan Berdasarkan Kalender -->
      <div class="card shadow-sm mb-4">
        <div class="card-header bg-dark text-white">
          <h5><i class="fas fa-calendar-alt me-2"></i>Pendapatan Berdasarkan Kalender</h5>
          <small>Pendapatan harian berdasarkan tanggal bayar atau tanggal tempo, filter prabayar/pascabayar &amp; cek IDPEL per hari</small>
        </div>
        <div class="card-body">
          <form method="GET" class="row g-3 mb-3">
            <div class="col-md-3">
              <label class="form-label">Bulan</label>
              <select name="bulan_kalender" class="form-select">
                <?php
                $bulan_kalender = isset($_GET['bulan_kalender']) ? (int)$_GET['bulan_kalender'] : $filter_bulan;
                if ($bulan_kalender < 1 || $bulan_kalender > 12) $bulan_kalender = $filter_bulan;
                for ($b = 1; $b <= 12; $b++) {
                  $sel = ($b == $bulan_kalender) ? 'selected' : '';
                  printf('<option value="%d" %s>%s</option>', $b, $sel, date('F', mktime(0,0,0,$b,1)));
                }
                ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Tahun</label>
              <select name="tahun_kalender" class="form-select">
                <?php
                $tahun_kalender = isset($_GET['tahun_kalender']) ? (int)$_GET['tahun_kalender'] : $filter_tahun;
                $kal_tahun_skrg = (int)date('Y');
                for ($t = $kal_tahun_skrg-5; $t <= $kal_tahun_skrg+1; $t++) {
                  $sel = ($t == $tahun_kalender) ? 'selected' : '';
                  echo "<option value='$t' $sel>$t</option>";
                }
                ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Tipe Pelanggan</label>
              <select name="tipe_kalender" class="form-select">
                <?php
                $tipe_kalender = isset($_GET['tipe_kalender']) ? $_GET['tipe_kalender'] : 'semua';
                $opt_tipe_kal = ['semua' => 'Semua', 'prabayar' => 'Prabayar', 'pascabayar' => 'Pascabayar'];
                foreach ($opt_tipe_kal as $kv => $kl) {
                  $sel = ($tipe_kalender === $kv) ? 'selected' : '';
                  echo "<option value='" . htmlspecialchars($kv) . "' $sel>" . htmlspecialchars($kl) . "</option>";
                }
                ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Ikuti</label>
              <select name="ikuti_kalender" class="form-select">
                <?php
                $ikuti_kalender = isset($_GET['ikuti_kalender']) ? $_GET['ikuti_kalender'] : 'tanggal_bayar';
                $opt_ikuti_kal = ['tanggal_bayar' => 'Tanggal Bayar', 'tanggal_tempo' => 'Tanggal Tempo'];
                foreach ($opt_ikuti_kal as $kv => $kl) {
                  $sel = ($ikuti_kalender === $kv) ? 'selected' : '';
                  echo "<option value='" . htmlspecialchars($kv) . "' $sel>" . htmlspecialchars($kl) . "</option>";
                }
                ?>
              </select>
            </div>
            <div class="col-12">
              <button type="submit" class="btn btn-dark">Tampilkan</button>
            </div>
          </form>

          <?php
          // Sanitize filter inputs
          $bulan_kalender = (isset($bulan_kalender) && $bulan_kalender >= 1 && $bulan_kalender <= 12) ? (int)$bulan_kalender : (int)$filter_bulan;
          $tahun_kalender = (isset($tahun_kalender) && $tahun_kalender >= 2000 && $tahun_kalender <= 2100) ? (int)$tahun_kalender : (int)$filter_tahun;
          $tipe_kalender = (isset($tipe_kalender) && in_array($tipe_kalender, ['semua','prabayar','pascabayar'], true)) ? $tipe_kalender : 'semua';
          $ikuti_kalender = (isset($ikuti_kalender) && in_array($ikuti_kalender, ['tanggal_bayar','tanggal_tempo'], true)) ? $ikuti_kalender : 'tanggal_bayar';

          $kal_tipe_sql = '';
          if ($tipe_kalender === 'prabayar') {
            $kal_tipe_sql = "AND LOWER(p.TIPE_BAYAR) = 'prabayar'";
          } elseif ($tipe_kalender === 'pascabayar') {
            $kal_tipe_sql = "AND LOWER(p.TIPE_BAYAR) = 'pascabayar'";
          }

          $cal_day_data = [];
          // Cache harga efektif reseller per "paket|pemilik", dipakai ulang di
          // blok total per hari MAUPUN blok detail per-pelanggan di bawah --
          // dideklarasikan di sini (bukan di dalam if ($res_kal)) supaya selalu
          // ada walau query gagal.
          $kalHargaEfektifCache = [];

          // Total per hari SENGAJA tidak lagi di-SUM langsung di SQL --
          // reseller/mitra dgn filter harga aktif punya custom_harga PER PAKET
          // (reseller_effective_harga()), jadi harga efektif tiap baris bisa
          // beda dari HARGA mentah yang tersimpan di transaksi/pelanggan.
          // Ambil baris mentah (+ PAKET, PEMILIK), lalu agregasi per hari di
          // PHP dgn harga hasil reseller_effective_harga() (no-op/transparan
          // kalau sesi ini bukan reseller dgn filter aktif).
          if ($ikuti_kalender === 'tanggal_bayar') {
            $sql_kal = "SELECT
                DAY($trx_tanggal_expr_t) AS hari,
                t.IDPEL, t.PAKET, t.PEMILIK,
                CAST(COALESCE(NULLIF(t.HARGA,''),'0') AS DECIMAL(18,2)) AS harga_raw
              FROM transaksi t
              INNER JOIN pelanggan p ON t.IDPEL = p.IDPEL
              WHERE t.STATUS = 'BERHASIL'
                AND MONTH($trx_tanggal_expr_t) = $bulan_kalender
                AND YEAR($trx_tanggal_expr_t) = $tahun_kalender
                AND p.PEMILIK IN ($userServerList)
                AND p.AREA IN ($userAreaList)
                $kal_tipe_sql
              ORDER BY hari";
          } else {
            $sql_kal = "SELECT
                CAST(p.TEMPO AS UNSIGNED) AS hari,
                p.IDPEL, p.PAKET, p.PEMILIK,
                CAST(COALESCE(NULLIF(p.HARGA,''),'0') AS DECIMAL(18,2)) AS harga_raw
              FROM pelanggan p
              WHERE p.PEMILIK IN ($userServerList)
                AND p.AREA IN ($userAreaList)
                AND CAST(p.HARGA AS DECIMAL(18,2)) > 0
                AND CAST(p.TEMPO AS UNSIGNED) BETWEEN 1 AND 31
                $kal_tipe_sql
              ORDER BY hari";
          }

          $res_kal = mysqli_query($conn, $sql_kal);
          if ($res_kal) {
            while ($row = mysqli_fetch_assoc($res_kal)) {
              $d = (int)$row['hari'];
              if ($d < 1 || $d > 31) continue;

              $paketNama = trim((string)($row['PAKET'] ?? ''));
              $pemilikRow = trim((string)($row['PEMILIK'] ?? ''));
              $cacheKey = $paketNama . '|' . $pemilikRow;
              if (!array_key_exists($cacheKey, $kalHargaEfektifCache)) {
                $kalHargaEfektifCache[$cacheKey] = ($paketNama !== '' && $pemilikRow !== '')
                  ? reseller_effective_harga($conn, $paketNama, $pemilikRow, 'broadband')
                  : (float)$row['harga_raw'];
              }
              $hargaEfektif = $kalHargaEfektifCache[$cacheKey];

              if (!isset($cal_day_data[$d])) {
                $cal_day_data[$d] = ['total' => 0.0, 'count' => 0, 'pelanggan' => []];
              }
              $cal_day_data[$d]['total'] += $hargaEfektif;

              // tanggal_tempo: 1 baris = 1 IDPEL (PK pelanggan), aman dihitung
              // langsung. tanggal_bayar: IDPEL yg sama bisa transaksi >1x di
              // hari yg sama (jarang tapi mungkin) -- hitung tiap baris spy
              // jumlah_trx tetap sama semantiknya dgn COUNT(*) yg lama.
              $cal_day_data[$d]['count'] += 1;
              $cal_day_data[$d]['pelanggan'][] = (string)$row['IDPEL'];
            }
            foreach ($cal_day_data as $dKey => $dData) {
              $cal_day_data[$dKey]['pelanggan'] = array_values(array_unique(array_filter($dData['pelanggan'])));
            }
          }

          $grand_total_kal  = 0;
          $grand_count_kal  = 0;
          foreach ($cal_day_data as $ddata) {
            $grand_total_kal += $ddata['total'];
            $grand_count_kal += $ddata['count'];
          }

          $days_in_month_kal = (int)date('t', mktime(0,0,0,$bulan_kalender,1,$tahun_kalender));
          // ISO weekday of 1st day: 1=Mon ... 7=Sun → offset 0=Mon
          $first_dow_kal = (int)date('N', mktime(0,0,0,$bulan_kalender,1,$tahun_kalender)) - 1;

          // Build IDPEL detail map for JS
          $all_kal_idpels = [];
          foreach ($cal_day_data as $ddata) {
            foreach ($ddata['pelanggan'] as $ip) { $all_kal_idpels[] = $ip; }
          }
          $all_kal_idpels = array_unique($all_kal_idpels);
          $kal_detail_map = [];
          if (!empty($all_kal_idpels)) {
            $kal_idpel_in = implode("','", array_map(function($id) use ($conn) { return mysqli_real_escape_string($conn, (string)$id); }, $all_kal_idpels));
            // Sama seperti perhitungan total per hari di atas -- nominal per
            // pelanggan di modal detail juga WAJIB lewat reseller_effective_harga(),
            // bukan SUM(t.HARGA)/p.HARGA mentah, supaya konsisten dgn angka total
            // yang sudah difilter.
            if ($ikuti_kalender === 'tanggal_bayar') {
              $sql_kal_detail = "SELECT p.IDPEL, p.NAMA, p.PAKET, p.PEMILIK, p.AREA, COALESCE(p.TIPE_BAYAR,'') AS TIPE_BAYAR,
                  MAX($trx_tanggal_expr_t) AS tgl_bayar,
                  t.PAKET AS TRX_PAKET, t.PEMILIK AS TRX_PEMILIK,
                  SUM(CAST(COALESCE(NULLIF(t.HARGA,''),'0') AS DECIMAL(18,2))) AS harga_raw
                FROM pelanggan p
                LEFT JOIN transaksi t ON p.IDPEL = t.IDPEL AND t.STATUS='BERHASIL'
                  AND MONTH($trx_tanggal_expr_t) = $bulan_kalender AND YEAR($trx_tanggal_expr_t) = $tahun_kalender
                WHERE p.IDPEL IN ('$kal_idpel_in')
                GROUP BY p.IDPEL, p.NAMA, p.PAKET, p.PEMILIK, p.AREA, p.TIPE_BAYAR, t.PAKET, t.PEMILIK";
            } else {
              $sql_kal_detail = "SELECT IDPEL, NAMA, PAKET, PEMILIK, AREA, COALESCE(TIPE_BAYAR,'') AS TIPE_BAYAR,
                  TEMPO, COALESCE(HARGA,'0') AS harga_raw
                FROM pelanggan WHERE IDPEL IN ('$kal_idpel_in')";
            }
            $res_kal_detail = mysqli_query($conn, $sql_kal_detail);
            if ($res_kal_detail) {
              while ($row = mysqli_fetch_assoc($res_kal_detail)) {
                // Utk mode tanggal_bayar, harga efektif dihitung dari PAKET/PEMILIK
                // transaksi yg beneran terjadi (TRX_PAKET/TRX_PEMILIK, bisa beda dari
                // paket pelanggan SEKARANG kalau pelanggan sudah ganti paket) --
                // fallback ke PAKET/PEMILIK pelanggan kalau tidak ada transaksi (nominal 0).
                $paketNama = trim((string)($row['TRX_PAKET'] ?? $row['PAKET'] ?? ''));
                $pemilikRow = trim((string)($row['TRX_PEMILIK'] ?? $row['PEMILIK'] ?? ''));
                $cacheKey = $paketNama . '|' . $pemilikRow;
                if (!array_key_exists($cacheKey, $kalHargaEfektifCache)) {
                  $kalHargaEfektifCache[$cacheKey] = ($paketNama !== '' && $pemilikRow !== '')
                    ? reseller_effective_harga($conn, $paketNama, $pemilikRow, 'broadband')
                    : (float)($row['harga_raw'] ?? 0);
                }
                $row['nominal'] = $kalHargaEfektifCache[$cacheKey];
                $kal_detail_map[$row['IDPEL']] = $row;
              }
            }
          }
          ?>

          <!-- Summary strip -->
          <div class="row mb-3">
            <div class="col-md-4 mb-2">
              <div class="alert alert-info py-2 mb-0">
                <strong>Total Pendapatan:</strong> Rp. <?php echo number_format($grand_total_kal, 0, ',', '.'); ?>
              </div>
            </div>
            <div class="col-md-4 mb-2">
              <div class="alert alert-success py-2 mb-0">
                <strong><?php echo $ikuti_kalender === 'tanggal_tempo' ? 'Total Pelanggan' : 'Total Transaksi'; ?>:</strong>
                <?php echo number_format($grand_count_kal, 0, ',', '.'); ?>
              </div>
            </div>
            <div class="col-md-4 mb-2">
              <div class="alert alert-secondary py-2 mb-0">
                <strong>Bulan:</strong> <?php echo date('F Y', mktime(0,0,0,$bulan_kalender,1,$tahun_kalender)); ?> &nbsp;|&nbsp;
                <strong>Mode:</strong> <?php echo $ikuti_kalender === 'tanggal_bayar' ? 'Tgl Bayar' : 'Tgl Tempo'; ?> &nbsp;|&nbsp;
                <?php echo ucfirst($tipe_kalender); ?>
              </div>
            </div>
          </div>

          <!-- Calendar grid -->
          <div class="table-responsive">
            <table class="table table-bordered mb-0" style="min-width:700px;table-layout:fixed;">
              <thead class="table-dark">
                <tr>
                  <th class="text-center" style="width:14.28%">Sen</th>
                  <th class="text-center" style="width:14.28%">Sel</th>
                  <th class="text-center" style="width:14.28%">Rab</th>
                  <th class="text-center" style="width:14.28%">Kam</th>
                  <th class="text-center" style="width:14.28%">Jum</th>
                  <th class="text-center" style="width:14.28%;background:#455a64;">Sab</th>
                  <th class="text-center" style="width:14.28%;background:#455a64;">Min</th>
                </tr>
              </thead>
              <tbody>
                <?php
                $kal_day      = 1;
                $kal_cell     = 0;
                $kal_today_d  = (int)date('d');
                $kal_today_m  = (int)date('m');
                $kal_today_y  = (int)date('Y');
                echo '<tr>';
                for ($i = 0; $i < $first_dow_kal; $i++) {
                  echo '<td style="height:80px;background:#f8f9fa;"></td>';
                  $kal_cell++;
                }
                while ($kal_day <= $days_in_month_kal) {
                  if ($kal_cell > 0 && $kal_cell % 7 === 0) echo '</tr><tr>';
                  $col_in_row = $kal_cell % 7;
                  $is_wknd    = ($col_in_row === 5 || $col_in_row === 6);
                  $has_d      = isset($cal_day_data[$kal_day]);
                  if ($is_wknd) {
                    $bg = $has_d ? '#c8e6c9' : '#eceff1';
                  } else {
                    $bg = $has_d ? '#e8f5e9' : '#fff';
                  }
                  $border = '';
                  if ($kal_day === $kal_today_d && $bulan_kalender === $kal_today_m && $tahun_kalender === $kal_today_y) {
                    $border = 'outline:2px solid #1976d2;outline-offset:-2px;';
                  }
                  echo '<td style="height:80px;vertical-align:top;padding:4px;background:' . $bg . ';' . $border . '">';
                  echo '<div style="font-weight:700;font-size:0.85em;color:#333;">' . $kal_day . '</div>';
                  if ($has_d) {
                    $dd = $cal_day_data[$kal_day];
                    echo '<div style="font-size:0.72em;color:#2e7d32;font-weight:600;white-space:nowrap;">Rp.' . number_format($dd['total'], 0, ',', '.') . '</div>';
                    $lbl = ($ikuti_kalender === 'tanggal_bayar') ? 'trx' : 'plg';
                    echo '<div style="font-size:0.68em;color:#666;">' . $dd['count'] . ' ' . $lbl . '</div>';
                    echo '<button type="button" class="btn btn-outline-primary btn-sm mt-1" style="font-size:0.62em;padding:1px 5px;line-height:1.4;" onclick="showKalenderDetail(' . $kal_day . ')">Cek IDPEL</button>';
                  }
                  echo '</td>';
                  $kal_cell++;
                  $kal_day++;
                }
                $kal_rem = $kal_cell % 7;
                if ($kal_rem !== 0) {
                  for ($i = $kal_rem; $i < 7; $i++) {
                    echo '<td style="height:80px;background:#f8f9fa;"></td>';
                  }
                }
                echo '</tr>';
                ?>
              </tbody>
            </table>
          </div>

          <!-- Detail panel (shown on Cek click) -->
          <div id="kalender-detail-wrap" class="mt-3" style="display:none;">
            <div class="card border-primary">
              <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center py-2">
                <h6 class="mb-0" id="kalender-detail-title">Detail Pelanggan</h6>
                <button type="button" class="btn-close btn-close-white btn-sm" onclick="document.getElementById('kalender-detail-wrap').style.display='none'"></button>
              </div>
              <div class="card-body p-2">
                <input type="text" id="kalender-idpel-search" class="form-control form-control-sm mb-2" placeholder="Cari IDPEL / Nama / Paket...">
                <div class="table-responsive">
                  <table class="table table-sm table-striped mb-0" id="tabel-kalender-detail">
                    <thead>
                      <tr>
                        <th>IDPEL</th><th>Nama</th><th>Paket</th><th>Area</th><th>Tipe</th>
                        <?php if ($ikuti_kalender === 'tanggal_bayar'): ?>
                        <th>Tgl Bayar</th><th>Nominal</th>
                        <?php else: ?>
                        <th>Hari Tempo</th><th>Harga Paket</th>
                        <?php endif; ?>
                      </tr>
                    </thead>
                    <tbody id="kalender-detail-tbody"></tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>

          <script>
          var kalenderDayData   = <?php
            $kal_js = [];
            foreach ($cal_day_data as $d => $dd) { $kal_js[$d] = $dd['pelanggan']; }
            echo json_encode($kal_js, JSON_UNESCAPED_UNICODE);
          ?>;
          var kalenderDetailMap = <?php echo json_encode($kal_detail_map, JSON_UNESCAPED_UNICODE); ?>;
          var kalenderMode      = <?php echo json_encode($ikuti_kalender); ?>;
          var kalenderBulan     = <?php echo (int)$bulan_kalender; ?>;
          var kalenderTahun     = <?php echo (int)$tahun_kalender; ?>;

          function showKalenderDetail(day) {
            var idpels = kalenderDayData[day] || [];
            var wrap   = document.getElementById('kalender-detail-wrap');
            var tbody  = document.getElementById('kalender-detail-tbody');
            var title  = document.getElementById('kalender-detail-title');
            var bulanNama = new Date(kalenderTahun, kalenderBulan - 1, 1).toLocaleString('id-ID', {month:'long'});
            title.textContent = 'Detail Pelanggan — ' + (kalenderMode === 'tanggal_bayar' ? 'Bayar Tgl ' : 'Tempo Tgl ') + day + ' ' + bulanNama + ' ' + kalenderTahun;
            tbody.innerHTML = '';
            if (!idpels.length) {
              tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">Tidak ada data</td></tr>';
            } else {
              idpels.forEach(function(idpel) {
                var d  = kalenderDetailMap[idpel] || {};
                var ex1, ex2;
                if (kalenderMode === 'tanggal_bayar') {
                  ex1 = '<td>' + (d.tgl_bayar ? String(d.tgl_bayar).trim() : '-') + '</td>';
                  ex2 = '<td>Rp.' + (d.nominal ? Number(d.nominal).toLocaleString('id-ID') : '0') + '</td>';
                } else {
                  ex1 = '<td>' + (d.TEMPO || '-') + '</td>';
                  ex2 = '<td>Rp.' + (d.HARGA ? Number(d.HARGA).toLocaleString('id-ID') : '0') + '</td>';
                }
                var tipeBadge = (String(d.TIPE_BAYAR || '').toLowerCase() === 'prabayar')
                  ? '<span class="badge bg-info text-dark">Prabayar</span>'
                  : ((String(d.TIPE_BAYAR || '').toLowerCase() === 'pascabayar')
                    ? '<span class="badge bg-warning text-dark">Pascabayar</span>'
                    : '<span class="badge bg-secondary">' + (d.TIPE_BAYAR || '-') + '</span>');
                var tr = document.createElement('tr');
                tr.innerHTML = '<td>' + String(idpel || '-') + '</td>'
                  + '<td>' + String(d.NAMA || '-') + '</td>'
                  + '<td>' + String(d.PAKET || '-') + '</td>'
                  + '<td>' + String(d.AREA || '-') + '</td>'
                  + '<td>' + tipeBadge + '</td>'
                  + ex1 + ex2;
                tbody.appendChild(tr);
              });
            }
            wrap.style.display = '';
            wrap.scrollIntoView({behavior:'smooth', block:'nearest'});
            document.getElementById('kalender-idpel-search').value = '';
          }

          (function() {
            var inp = document.getElementById('kalender-idpel-search');
            if (inp) {
              inp.addEventListener('input', function() {
                var q = this.value.toLowerCase();
                document.querySelectorAll('#kalender-detail-tbody tr').forEach(function(r) {
                  r.style.display = r.textContent.toLowerCase().includes(q) ? '' : 'none';
                });
              });
            }
          })();
          </script>
        </div>
      </div>

      <!-- Laporan Paket Paling Banyak Terjual -->
      <div class="card shadow-sm mb-4">
        <div class="card-header bg-success text-white">
          <h5><i class="fas fa-box-open me-2"></i>Laporan Paket Paling Banyak Terjual</h5>
          <small>Daftar paket yang paling sering dibeli oleh pelanggan</small>
        </div>
        <div class="card-body">
          <?php
          $sql_paket = "SELECT t.PAKET, COUNT(*) as jumlah FROM transaksi t INNER JOIN pelanggan p ON t.IDPEL = p.IDPEL WHERE p.PEMILIK IN ($userServerList) AND p.AREA IN ($userAreaList) AND t.STATUS = 'BERHASIL' GROUP BY t.PAKET ORDER BY jumlah DESC LIMIT 10";
          $result_paket = mysqli_query($conn, $sql_paket);
          ?>
          <div class="table-responsive">
            <table class="table table-striped" id="tabel-paket-terjual">
            <thead>
              <tr>
                <th>Paket</th>
                <th>Jumlah Terjual</th>
              </tr>
            </thead>
            <tbody>
              <?php 
              if (mysqli_num_rows($result_paket) > 0) {
                while ($row = mysqli_fetch_assoc($result_paket)) { ?>
                  <tr>
                    <td><?php echo $row['PAKET']; ?></td>
                    <td><?php echo $row['jumlah']; ?></td>
                  </tr>
                <?php } 
              } else { ?>
                <tr>
                  <td class="text-center text-muted">Tidak ada data paket terjual.</td><td class="text-center text-muted">-</td>
                </tr>
              <?php } ?>
            </tbody>
            </table>
          </div>
        </div>
      </div>
   

      <!-- Pelanggan yang Menunggak -->
      <div class="card shadow-sm mb-4">
        <div class="card-header bg-warning text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
          <div>
            <h5 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>Pelanggan yang Menunggak</h5>
            <small>Ringkasan pelanggan yang belum membayar sesuai tempo</small>
          </div>
          <a href="pelanggan_menunggak.php" class="btn btn-light btn-sm">Lihat Detail</a>
        </div>
        <div class="card-body">
          <div class="row">
            <div class="col-md-3 mb-2">
              <div class="alert alert-danger mb-0 text-center">
                <div class="fw-bold">Total Menunggak</div>
                <div class="h4 mb-0"><?php echo count($data_menunggak); ?></div>
              </div>
            </div>
            <div class="col-md-3 mb-2">
              <div class="alert alert-warning mb-0 text-center">
                <div class="fw-bold">Nunggak 1 Siklus</div>
                <div class="h4 mb-0"><?php echo $total_nunggak_1; ?></div>
              </div>
            </div>
            <div class="col-md-3 mb-2">
              <div class="alert alert-warning mb-0 text-center">
                <div class="fw-bold">Nunggak 2 Siklus+</div>
                <div class="h4 mb-0"><?php echo $total_nunggak_2; ?></div>
              </div>
            </div>
            <div class="col-md-3 mb-2">
              <div class="alert alert-info mb-0 text-center">
                <div class="fw-bold">Target Broadcast</div>
                <div class="h4 mb-0"><?php echo count($menunggakIdList); ?></div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Card: Pelanggan FASUM / CSR -->
      <div class="card shadow-sm mb-4">
        <div class="card-header bg-secondary text-white">
          <h6 class="mb-0">Pelanggan FASUM / CSR (Harga 0)</h6>
        </div>
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-striped" id="tabel-fasum">
              <thead>
                <tr>
                  <th>IDPEL</th>
                  <th>Nama</th>
                  <th>Nama Paket</th>
                  <th>Area</th>
                </tr>
              </thead>
              <tbody id="statFasumTableBody">
                <?php
                $fasumRowsHtml = [];
                if (count($data_fasum) > 0) {
                  foreach ($data_fasum as $row) {
                    ob_start();
                    ?>
                    <tr>
                      <td><?php echo $row['IDPEL']; ?></td>
                      <td><?php echo $row['NAMA']; ?></td>
                      <td><?php echo $row['PAKET']; ?></td>
                      <td><?php echo isset($row['AREA']) ? $row['AREA'] : '-'; ?></td>
                    </tr>
                    <?php
                    $fasumRowsHtml[] = ob_get_clean();
                  }
                } else { ?>
                  <tr>
                    <td class="text-center text-muted">-</td><td class="text-center text-muted">-</td><td class="text-center text-muted">-</td><td class="text-center text-muted">Tidak ada pelanggan FASUM/CSR yang menunggak.</td>
                  </tr>
                <?php } ?>
                <tr id="statFasumLazySentinel" style="height:1px;"><td colspan="4" style="padding:0;border:0;"></td></tr>
              </tbody>
            </table>
          </div>
          <div id="statFasumLazyLoadWrap" class="text-center py-3 <?php echo count($fasumRowsHtml) <= 20 ? 'd-none' : ''; ?>">
            <div id="statFasumLazyLoadIndicator" class="spinner-border spinner-border-sm text-primary d-none" role="status"><span class="visually-hidden">Loading...</span></div>
            <span id="statFasumLazyLoadStatusText" class="text-secondary text-xs"></span>
          </div>
          <script>
            var fasumRowsHtml = <?php echo json_encode($fasumRowsHtml, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
          </script>
        </div>
      </div>

 <!-- Card: Pelanggan Dalam Promo -->
<div class="card shadow-sm mb-4">
  <div class="card-header bg-primary text-white">
    <h6 class="mb-0">Pelanggan Dalam Promo</h6>
  </div>
  <div class="card-body">
    <div class="table-responsive">
    <?php
    // Join pelanggan -> paket -> promo_paket
    // - PAKET dicocokkan exact (trim+lower)
    // - PEMILIK dicocokkan exact (trim+lower)
    // - AREA dicocokkan pakai LIKE karena format AREA di pelanggan/paket bisa gabungan
    //   (contoh: PONDOK_RAJEG_CIBINONG_JAWA_BARAT) sedangkan di promo_paket cuma "TAJURHALANG"
    // Tanggal berakhir dihitung dari TANGGALPASANG pelanggan + durasi promo
    $sql = "SELECT p.*,
                   CASE
                     WHEN pp.promo_mulai_type = 'tanggal_pasang' AND pp.promo_durasi_type = 'bulan'
                          THEN DATE_ADD(STR_TO_DATE(p.TANGGALPASANG, '%Y-%m-%d'), INTERVAL pp.promo_durasi MONTH)
                     WHEN pp.promo_mulai_type = 'tanggal_pasang' AND pp.promo_durasi_type = 'hari'
                          THEN DATE_ADD(STR_TO_DATE(p.TANGGALPASANG, '%Y-%m-%d'), INTERVAL pp.promo_durasi DAY)
                     ELSE NULL
                   END AS tanggal_berakhir_promo
            FROM pelanggan p
            JOIN paket pk
                 ON TRIM(LOWER(p.PAKET)) = TRIM(LOWER(pk.PAKET))
            JOIN promo_paket pp
                 ON pp.paket_id = pk.id
                AND TRIM(LOWER(p.PEMILIK)) = TRIM(LOWER(pp.pemilik))
                AND TRIM(LOWER(p.AREA)) LIKE CONCAT('%', TRIM(LOWER(pp.area)), '%')
            WHERE p.PEMILIK IN ($userServerList)
              AND p.AREA IN ($userAreaList)";

    $q = mysqli_query($conn, $sql);

    $pelanggan_promo = [];
    if ($q === false) {
        // Tampilkan error query saat development (hapus/comment saat production)
        echo '<div class="alert alert-danger">Query error: ' . htmlspecialchars(mysqli_error($conn)) . '</div>';
    } else {
        while ($row = mysqli_fetch_assoc($q)) {
            $pelanggan_promo[] = $row;
        }
    }

    echo '<table class="table table-striped" id="tabel-promo">';
    echo '<thead><tr><th>IDPEL</th><th>NAMA</th><th>PAKET</th><th>AREA</th><th>Promo Berakhir Pada Tanggal</th></tr></thead><tbody id="statPromoTableBody">';

    $promoRowsHtml = [];
    if (!empty($pelanggan_promo)) {
        foreach ($pelanggan_promo as $row) {
            $tanggalBerakhirDisplay = '-';
            if (!empty($row['tanggal_berakhir_promo'])) {
                $ts = strtotime((string)$row['tanggal_berakhir_promo']);
                $tanggalBerakhirDisplay = $ts !== false ? date('d-m-Y', $ts) : '-';
            }
            $rowHtml = '<tr>';
            $rowHtml .= '<td>' . htmlspecialchars($row['IDPEL']) . '</td>';
            $rowHtml .= '<td>' . htmlspecialchars($row['NAMA']) . '</td>';
            $rowHtml .= '<td>' . htmlspecialchars($row['PAKET']) . '</td>';
            $rowHtml .= '<td>' . htmlspecialchars($row['AREA']) . '</td>';
            $rowHtml .= '<td>' . $tanggalBerakhirDisplay . '</td>';
            $rowHtml .= '</tr>';
            $promoRowsHtml[] = $rowHtml;
        }
    } else {
        echo '<tr><td class="text-center text-muted">-</td><td class="text-center text-muted">-</td><td class="text-center text-muted">-</td><td class="text-center text-muted">-</td><td class="text-center text-muted">Tidak ada data sales.</td></tr>';
    }
    echo '<tr id="statPromoLazySentinel" style="height:1px;"><td colspan="5" style="padding:0;border:0;"></td></tr>';
    echo '</tbody></table>';
    echo '<div id="statPromoLazyLoadWrap" class="text-center py-3 ' . (count($promoRowsHtml) <= 20 ? 'd-none' : '') . '">';
    echo '<div id="statPromoLazyLoadIndicator" class="spinner-border spinner-border-sm text-primary d-none" role="status"><span class="visually-hidden">Loading...</span></div>';
    echo '<span id="statPromoLazyLoadStatusText" class="text-secondary text-xs"></span>';
    echo '</div>';
    echo '<script>var promoRowsHtml = ' . json_encode($promoRowsHtml, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';</script>';
    ?>
    </div>
  </div>
</div>


<!-- Tabel Sales dengan Pelanggan Paling Banyak Bayar & Menunggak -->
<div class="card shadow-sm mb-4">
  <div class="card-header bg-secondary text-white">
    <h5><i class="fas fa-user-tie me-2"></i>Statistik Sales: Pelanggan Paling Banyak Bayar & Menunggak</h5>
    <small>Daftar sales beserta jumlah pelanggan yang paling banyak bayar dan menunggak, serta nama paket yang dibayar</small>
  </div>
  <div class="card-body">
    <?php
    // Filter bulan dan tahun dari transaksi
    $filter_bulan_sales = isset($_GET['bulan_sales']) ? (int)$_GET['bulan_sales'] : (int)date('m');
    $filter_tahun_sales = isset($_GET['tahun_sales']) ? (int)$_GET['tahun_sales'] : (int)date('Y');

    // Ambil harga paket dari tabel paket dan paket_hotspot -- dilewatkan
    // reseller_filter_rows() (lihat komentar serupa di atas) supaya konsisten
    // dgn filter harga reseller/mitra (paket yang tidak di-enable utk reseller
    // ini tidak akan tampil sbg "harga > 0" walau harga aslinya positif).
    $harga_paket = [];
    $q1 = mysqli_query($conn, "SELECT id, PAKET, HARGA FROM paket");
    foreach (reseller_filter_rows($conn, reseller_collect_rows($q1), 'broadband') as $r) {
      $harga_paket[$r['PAKET']] = (int)$r['HARGA'];
    }
    $q2 = mysqli_query($conn, "SELECT id, paket, harga FROM paket_hotspot");
    foreach (reseller_filter_rows($conn, reseller_collect_rows($q2), 'hotspot') as $r) {
      $harga_paket[$r['paket']] = (int)$r['harga'];
    }

    // Query sales, hanya hitung transaksi dengan harga paket > 0 dan tidak kosong, filter bulan & tahun
    $sql_sales = "SELECT s.sales,
      SUM(CASE WHEN t.STATUS = 'BERHASIL' AND t.HARGA > 0 THEN 1 ELSE 0 END) as total_bayar,
      SUM(CASE WHEN (t.STATUS IS NULL OR t.STATUS != 'BERHASIL') AND s.HARGA > 0 THEN 1 ELSE 0 END) as total_menunggak,
      GROUP_CONCAT(DISTINCT CASE WHEN t.HARGA > 0 THEN s.PAKET END SEPARATOR ', ') as paket_terjual
      FROM pelanggan s
      LEFT JOIN (
        SELECT IDPEL, STATUS, HARGA, MONTH($trxTanggalExprNoAlias) as bulan, YEAR($trxTanggalExprNoAlias) as tahun FROM transaksi
        WHERE MONTH($trxTanggalExprNoAlias) = $filter_bulan_sales AND YEAR($trxTanggalExprNoAlias) = $filter_tahun_sales
      ) t ON s.IDPEL = t.IDPEL
      WHERE s.sales IS NOT NULL AND s.sales != '' AND s.HARGA > 0
      GROUP BY s.sales
      ORDER BY total_bayar DESC, total_menunggak DESC
      LIMIT 20";
    $result_sales = mysqli_query($conn, $sql_sales);
    ?>
    <form method="GET" class="row g-2 mb-3">
      <div class="col-auto">
        <label for="bulan_sales" class="form-label">Bulan</label>
        <select name="bulan_sales" id="bulan_sales" class="form-control">
          <?php for ($b=1; $b<=12; $b++) { $sel = ($b==$filter_bulan_sales)?'selected':''; echo "<option value='$b' $sel>$b</option>"; } ?>
        </select>
      </div>
      <div class="col-auto">
        <label for="tahun_sales" class="form-label">Tahun</label>
        <input type="number" name="tahun_sales" id="tahun_sales" class="form-control" value="<?php echo $filter_tahun_sales; ?>" min="2020" max="2030">
      </div>
      <div class="col-auto align-self-end">
        <button type="submit" class="btn btn-secondary">Tampilkan</button>
      </div>
    </form>
    <div class="table-responsive">
  <table class="table table-striped" id="tabel-sales">
      <thead>
        <tr>
          <th>Sales</th>
          <th>Pelanggan Bayar</th>
          <th>Pelanggan Menunggak</th>
          <th>Paket Terjual</th>
        </tr>
      </thead>
      <tbody>
        <?php
        if ($result_sales && mysqli_num_rows($result_sales) > 0) {
          while ($row = mysqli_fetch_assoc($result_sales)) {
            // Filter paket_terjual agar hanya tampil paket dengan harga > 0
            $paket_list = array_filter(array_map('trim', explode(',', $row['paket_terjual'])), function($p) use ($harga_paket) {
              return isset($harga_paket[$p]) && $harga_paket[$p] > 0;
            });
            echo '<tr>';
            echo '<td>' . htmlspecialchars($row['sales']) . '</td>';
            echo '<td>' . (int)$row['total_bayar'] . '</td>';
            echo '<td>' . (int)$row['total_menunggak'] . '</td>';
            echo '<td>' . htmlspecialchars(implode(', ', $paket_list)) . '</td>';
            echo '</tr>';
          }
        } else {
          echo '<tr><td class="text-center text-muted">-</td><td class="text-center text-muted">-</td><td class="text-center text-muted">-</td><td class="text-center text-muted">Tidak ada data sales.</td></tr>';
        }
        ?>
      </tbody>
      </table>
    </div>
  </div>
</div>
</div>

<!-- DataTables CSS & JS -->
<script src="assets/datatables/js/jquery.dataTables.min.js"></script>
<script src="assets/datatables/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf-autotable@3.8.2/dist/jspdf.plugin.autotable.min.js"></script>
<script>
$(document).ready(function() {
  console.log('Initializing DataTables...');
  // tabel-fasum, tabel-promo SENGAJA tidak di-DataTable() lagi -
  // keduanya sekarang lazy-load (reveal bertahap 20/batch), lihat script khusus
  // di bawah. DataTable() butuh semua baris sudah ada di DOM sejak awal, jadi
  // kalau tetap dipanggil di sini akan konflik dengan reveal bertahap tsb.
  $('#tabel-sales').DataTable();
  $('#tabel-paket-terjual').DataTable();
  $('#tabel-statistik-pendapatan').DataTable();
  $('#tabel-pendapatan-server-area').DataTable();
  $('#tabel-payment-method-summary').DataTable();
  $('#tabel-fee-summary-server-area').DataTable();
  console.log('DataTables initialized.');

  // Restore scroll position after filter submit.
  var scrollStorageKey = 'billing_statistics_scroll_pos';
  var urlParams = new URLSearchParams(window.location.search);
  var scrollPos = urlParams.get('scroll_pos');
  if (!scrollPos) {
    scrollPos = sessionStorage.getItem(scrollStorageKey);
  }
  if (scrollPos !== null && scrollPos !== '') {
    var parsedScrollPos = parseInt(scrollPos, 10);
    if (!isNaN(parsedScrollPos)) {
      window.scrollTo(0, parsedScrollPos);
      setTimeout(function() {
        window.scrollTo(0, parsedScrollPos);
      }, 150);
    }
    sessionStorage.removeItem(scrollStorageKey);
    if (urlParams.has('scroll_pos')) {
      urlParams.delete('scroll_pos');
      var cleanUrl = window.location.pathname + (urlParams.toString() ? '?' + urlParams.toString() : '') + window.location.hash;
      window.history.replaceState({}, document.title, cleanUrl);
    }
  }

  // Before submit, store current scroll position for all GET filter forms.
  $('form[method="GET"], form[method="get"]').on('submit', function() {
    var $form = $(this);
    var currentScrollPos = Math.round(window.scrollY || window.pageYOffset || 0);
    var $inp = $form.find('input[name="scroll_pos"]');
    if ($inp.length === 0) {
      $inp = $('<input type="hidden" name="scroll_pos">');
      $form.append($inp);
    }
    $inp.val(currentScrollPos);
    sessionStorage.setItem(scrollStorageKey, String(currentScrollPos));
  });
});
</script>
<style>
    .table-export-toolbar {
      display: flex;
      justify-content: flex-end;
      gap: 8px;
      margin-bottom: 10px;
      flex-wrap: wrap;
    }

    .table-export-toolbar .btn {
      padding: 4px 10px;
      font-size: 0.85rem;
    }

    .table-search {
      margin-bottom: 10px;
    }
    .table-pagination {
      margin-top: 10px;
      text-align: center;
    }
    .table-pagination button {
      margin: 0 5px;
      padding: 5px 10px;
      border: 1px solid #ccc;
      background: #fff;
      cursor: pointer;
    }
    .table-pagination button.active {
      background: #007bff;
      color: white;
    }
  </style>





<!-- Modal for Customer Details -->
<div class="modal fade" id="customerModal" tabindex="-1" aria-labelledby="customerModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="customerModalLabel">Daftar Pelanggan </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <input type="text" id="searchCustomer" class="form-control mb-3" placeholder="Cari pelanggan...">
        <div class="table-responsive">
          <table class="table table-striped" id="customerTable">
            <thead>
              <tr>
                <th>IDPEL</th>
                <th>NAMA</th>
                <th>PAKET</th>
                <th>AREA</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $sql_customers = "SELECT IDPEL, NAMA, PAKET, AREA FROM pelanggan WHERE PEMILIK IN ($userServerList) AND AREA IN ($userAreaList) ORDER BY NAMA";
              $result_customers = mysqli_query($conn, $sql_customers);
              while($row = mysqli_fetch_assoc($result_customers)) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($row['IDPEL']) . "</td>";
                echo "<td>" . htmlspecialchars($row['NAMA']) . "</td>";
                echo "<td>" . htmlspecialchars($row['PAKET']) . "</td>";
                echo "<td>" . htmlspecialchars($row['AREA']) . "</td>";
                echo "</tr>";
              }
              ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
document.getElementById('searchCustomer').addEventListener('input', function() {
  const filter = this.value.toLowerCase();
  const rows = document.querySelectorAll('#customerTable tbody tr');
  rows.forEach(row => {
    const text = row.textContent.toLowerCase();
    row.style.display = text.includes(filter) ? '' : 'none';
  });
});
</script>











<?php require 'footer.php'; ?>

<!-- Table Search and Pagination JS -->
<script>
function setupTableSearchAndPaging(tableId, rowsPerPage = 10) {
  const table = document.getElementById(tableId);
  if (!table) return;
  const tbody = table.querySelector('tbody');
  const rows = Array.from(tbody.querySelectorAll('tr'));
  const searchInput = document.createElement('input');
  searchInput.type = 'text';
  searchInput.placeholder = 'Cari...';
  searchInput.className = 'form-control table-search';
  table.parentNode.insertBefore(searchInput, table);

  const paginationDiv = document.createElement('div');
  paginationDiv.className = 'table-pagination';
  table.parentNode.insertBefore(paginationDiv, table.nextSibling);

  let currentPage = 1;
  let filteredRows = rows;

  function renderPage() {
    const start = (currentPage - 1) * rowsPerPage;
    const end = start + rowsPerPage;
    rows.forEach(row => row.style.display = 'none');
    filteredRows.slice(start, end).forEach(row => row.style.display = '');
    renderPagination();
  }

  function renderPagination() {
    paginationDiv.innerHTML = '';
    const totalPages = Math.ceil(filteredRows.length / rowsPerPage);
    if (totalPages <= 1) return;

    const prevBtn = document.createElement('button');
    prevBtn.textContent = 'Previous';
    prevBtn.disabled = currentPage === 1;
    prevBtn.onclick = () => { currentPage--; renderPage(); };
    paginationDiv.appendChild(prevBtn);

    for (let i = 1; i <= totalPages; i++) {
      const btn = document.createElement('button');
      btn.textContent = i;
      btn.className = i === currentPage ? 'active' : '';
      btn.onclick = () => { currentPage = i; renderPage(); };
      paginationDiv.appendChild(btn);
    }

    const nextBtn = document.createElement('button');
    nextBtn.textContent = 'Next';
    nextBtn.disabled = currentPage === totalPages;
    nextBtn.onclick = () => { currentPage++; renderPage(); };
    paginationDiv.appendChild(nextBtn);
  }

  searchInput.oninput = function() {
    const query = this.value.toLowerCase();
    filteredRows = rows.filter(row => {
      const cells = row.querySelectorAll('td');
      return Array.from(cells).some(cell => cell.textContent.toLowerCase().includes(query));
    });
    currentPage = 1;
    renderPage();
  };

  renderPage();
}

function getTableTitle(table) {
  const card = table.closest('.card');
  if (card) {
    const heading = card.querySelector('.card-header h5, .card-header h6, .card-header .mb-0');
    if (heading && heading.textContent) {
      return heading.textContent.trim();
    }
  }

  if (table.id) {
    return table.id.replace(/[-_]/g, ' ').trim();
  }

  return 'Tabel Statistik';
}

function sanitizeFileName(name) {
  return String(name || 'tabel_statistik')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '') || 'tabel_statistik';
}

// Tabel yang lazy-load (reveal bertahap) HARUS di-reveal penuh dulu sebelum
// diekspor, supaya export Excel/PDF tidak cuma berisi baris yang kebetulan
// sudah ke-scroll saat tombol export ditekan.
function revealLazyTableBeforeExport(table) {
  if (!table || !table.id) return;
  var revealFns = {
    'tabel-fasum': window.statFasumRevealAllRemaining,
    'tabel-promo': window.statPromoRevealAllRemaining
  };
  var fn = revealFns[table.id];
  if (typeof fn === 'function') fn();
}

function exportTableToExcel(table) {
  if (!table || typeof XLSX === 'undefined') {
    alert('Library Excel belum tersedia.');
    return;
  }

  revealLazyTableBeforeExport(table);
  const title = getTableTitle(table);
  const workbook = XLSX.utils.table_to_book(table, { sheet: 'Data' });
  const filename = sanitizeFileName(title) + '_' + new Date().toISOString().slice(0, 10) + '.xlsx';
  XLSX.writeFile(workbook, filename);
}

function exportTableToPDF(table) {
  if (!table || typeof window.jspdf === 'undefined') {
    alert('Library PDF belum tersedia.');
    return;
  }

  revealLazyTableBeforeExport(table);
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF({ orientation: 'landscape', unit: 'pt', format: 'a4' });
  const title = getTableTitle(table);
  const filename = sanitizeFileName(title) + '_' + new Date().toISOString().slice(0, 10) + '.pdf';

  const head = [];
  const headCells = table.querySelectorAll('thead th');
  if (headCells.length) {
    head.push(Array.from(headCells).map(function (cell) {
      return (cell.textContent || '').trim();
    }));
  }

  const body = [];
  const bodyRows = table.querySelectorAll('tbody tr');
  bodyRows.forEach(function (row) {
    const cells = row.querySelectorAll('td');
    if (!cells.length) {
      return;
    }
    body.push(Array.from(cells).map(function (cell) {
      return (cell.textContent || '').replace(/\s+/g, ' ').trim();
    }));
  });

  doc.setFontSize(12);
  doc.text(title, 40, 40);

  doc.autoTable({
    head: head.length ? head : [['Data']],
    body: body.length ? body : [['Tidak ada data']],
    startY: 55,
    styles: {
      fontSize: 8,
      cellPadding: 3,
      overflow: 'linebreak'
    },
    headStyles: {
      fillColor: [33, 150, 243]
    },
    theme: 'grid',
    margin: { left: 20, right: 20 }
  });

  doc.save(filename);
}

function attachExportButtonsToStatisticsTables() {
  const tables = Array.from(document.querySelectorAll('table'));

  tables.forEach(function (table, idx) {
    if (!table.id) {
      table.id = 'stats-table-auto-' + (idx + 1);
    }

    if (table.dataset.exportReady === '1') {
      return;
    }

    const wrapper = table.closest('.table-responsive') || table.parentElement;
    if (!wrapper) {
      return;
    }

    const toolbar = document.createElement('div');
    toolbar.className = 'table-export-toolbar';

    const btnExcel = document.createElement('button');
    btnExcel.type = 'button';
    btnExcel.className = 'btn btn-success btn-sm';
    btnExcel.textContent = 'Export Excel';
    btnExcel.addEventListener('click', function () {
      exportTableToExcel(table);
    });

    const btnPdf = document.createElement('button');
    btnPdf.type = 'button';
    btnPdf.className = 'btn btn-danger btn-sm';
    btnPdf.textContent = 'Export PDF';
    btnPdf.addEventListener('click', function () {
      exportTableToPDF(table);
    });

    toolbar.appendChild(btnExcel);
    toolbar.appendChild(btnPdf);

    wrapper.insertBefore(toolbar, table);
    table.dataset.exportReady = '1';
  });
}

document.addEventListener('DOMContentLoaded', function() {
  attachExportButtonsToStatisticsTables();
  // tabel-fasum, tabel-promo SENGAJA tidak dipasangi
  // setupTableSearchAndPaging lagi - keduanya lazy-load (lihat script khusus
  // di bawah), yang butuh baris ditambahkan bertahap, bukan semua sudah ada di
  // DOM sejak awal seperti yang diasumsikan fungsi ini.
  setupTableSearchAndPaging('tabel-sales');
  setupTableSearchAndPaging('tabel-paket-terjual');
  setupTableSearchAndPaging('tabel-statistik-pendapatan');
  setupTableSearchAndPaging('tabel-pendapatan-server-area');
  setupTableSearchAndPaging('tabel-payment-method-summary');
  setupTableSearchAndPaging('tabel-fee-summary-server-area');
  setupTableSearchAndPaging('customerTable');
});
</script>

<!-- ============================================================
     LAZY LOAD: tabel-fasum, tabel-promo direveal
     bertahap 20/batch saat discroll. Data sudah lengkap di memori
     (hasil hitungan/query yang sudah jalan di atas) - sama seperti
     tableshotspot.php / pelanggan_menunggak.php.
     ============================================================ -->
<script>
function createStatLazyReveal(opts) {
    var revealedCount = 0;
    var isRevealing = false;
    var allRevealed = false;

    var tableBody = document.getElementById(opts.tableBodyId);
    var sentinel = document.getElementById(opts.sentinelId);
    var lazyWrap = document.getElementById(opts.wrapId);
    var lazyIndicator = document.getElementById(opts.indicatorId);
    var lazyStatusText = document.getElementById(opts.statusTextId);

    if (!tableBody || !sentinel) return null;

    function updateStatusText() {
        if (!lazyStatusText) return;
        var total = opts.getData().length;
        if (total === 0) { lazyStatusText.textContent = ''; return; }
        lazyStatusText.textContent = allRevealed
            ? 'Semua data sudah dimuat (' + total + ').'
            : 'Menampilkan ' + revealedCount + ' dari ' + total + ' data...';
    }

    function revealChunk(count) {
        if (allRevealed || isRevealing) return;
        var data = opts.getData();
        var chunk = data.slice(revealedCount, revealedCount + count);
        if (chunk.length === 0) {
            allRevealed = true;
            updateStatusText();
            if (lazyIndicator) lazyIndicator.classList.add('d-none');
            return;
        }

        isRevealing = true;
        if (lazyWrap) lazyWrap.classList.remove('d-none');
        if (lazyIndicator) lazyIndicator.classList.remove('d-none');

        var temp = document.createElement('tbody');
        temp.innerHTML = chunk.join('');
        var newRows = Array.prototype.slice.call(temp.children);
        var newCells = [];
        newRows.forEach(function(row) {
            var cell = row.querySelector ? row.querySelector('.menunggak-profile-cell') : null;
            if (cell) newCells.push(cell);
            tableBody.insertBefore(row, sentinel);
        });

        if (opts.onChunkRevealed) opts.onChunkRevealed(newCells);

        revealedCount += chunk.length;
        allRevealed = revealedCount >= data.length;
        updateStatusText();
        if (allRevealed && lazyIndicator) lazyIndicator.classList.add('d-none');
        isRevealing = false;
    }

    function revealAllRemaining() {
        while (!allRevealed) {
            revealChunk(opts.getData().length);
        }
    }

    if ('IntersectionObserver' in window) {
        var observer = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) revealChunk(20);
            });
        }, { root: null, rootMargin: '0px 0px 300px 0px', threshold: 0 });
        observer.observe(sentinel);
    }

    window.addEventListener('scroll', function() {
        if (allRevealed || isRevealing) return;
        var nearBottom = window.innerHeight + window.scrollY >= document.body.offsetHeight - 300;
        if (nearBottom) revealChunk(20);
    }, { passive: true });

    function initialReveal() { revealChunk(20); }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialReveal);
    } else {
        initialReveal();
    }

    return revealAllRemaining;
}

window.statFasumRevealAllRemaining = createStatLazyReveal({
    tableBodyId: 'statFasumTableBody',
    sentinelId: 'statFasumLazySentinel',
    wrapId: 'statFasumLazyLoadWrap',
    indicatorId: 'statFasumLazyLoadIndicator',
    statusTextId: 'statFasumLazyLoadStatusText',
    getData: function() { return fasumRowsHtml; }
}) || function() {};

window.statPromoRevealAllRemaining = createStatLazyReveal({
    tableBodyId: 'statPromoTableBody',
    sentinelId: 'statPromoLazySentinel',
    wrapId: 'statPromoLazyLoadWrap',
    indicatorId: 'statPromoLazyLoadIndicator',
    statusTextId: 'statPromoLazyLoadStatusText',
    getData: function() { return promoRowsHtml; }
}) || function() {};
</script>

<div id="popupModal" style="display: none; position: fixed; top: 0; left: 0;
width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; justify-content: center; align-items: center;">
  <div style="background: white; padding: 20px; border-radius: 8px; width: 320px; font-size: 14px; position: relative;">
    <style>
      @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
      }
    </style>
    <div id="loadingOverlay" style="display: none; position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255,255,255,0.8); justify-content: center; align-items: center; z-index: 10000;">
      <div style="text-align: center;">
        <div style="border: 4px solid #f3f3f3; border-top: 4px solid #3498db; border-radius: 50%; width: 40px; height: 40px; animation: spin 2s linear infinite; margin: 0 auto 10px;"></div>
        <p>Loading...</p>
      </div>
    </div>
    <h5>Buat tiket</h5>
    <form id="popupForm">
      <!-- Hidden values -->
      <input type="readonly" class="form-control" name="BRAND" id="brandInput">
      <input type="readonly" class="form-control" name="IDPEL" id="idpelInput">
      <input type="readonly" class="form-control" name="NOWA" id="nowaInput">
      <input type="readonly" class="form-control" name="NAMA" id="namaInput">
      <input type="readonly" class="form-control" name="ALAMAT" id="alamatInput">
      <input type="readonly" class="form-control" name="EMAIL" id="emailInput">
      <input type="readonly" class="form-control" name="TIKOR" id="tikorInput">
      <input type="readonly" class="form-control" name="ODP" id="odpInput">

      <div class="mb-2">
        <select name="tipe" class="form-control" required>
          <option value="DISMANTLE">Dismantel</option>
        </select>
      </div>

      <div class="mb-2">
        <select name="kendala" class="form-control" required>
          <option value="">Pilih Kendala</option>
          <option value="Tidak ada pembayaran lanjutan">Tidak ada pembayaran lanjutan</option>
          <option value="Pindah rumah">Pindah rumah</option>
          <option value="Pindah ke provider lain">Pindah ke provider lain</option>
          <option value="Lainnya">Lainnya</option>
        </select>
      </div>

      <div class="text-end">
        <button type="button" onclick="closeModal()" class="btn btn-secondary btn-sm me-2">Batal</button>
        <button type="submit" class="btn btn-primary btn-sm">Kirim</button>
      </div>
    </form>
  </div>
</div>

<script>
let menunggakActiveController = null;
let menunggakIsSubmitting = false;

function menunggakSetProcessState(isProcessing, message, statusType = 'info') {
  const sendBtn = document.getElementById('menunggakSendBtn');
  const stopBtn = document.getElementById('menunggakStopBtn');
  const processStatus = document.getElementById('menunggakProcessStatus');
  const processText = document.getElementById('menunggakProcessText');
  const processSpinner = document.getElementById('menunggakProcessSpinner');

  processStatus.className = 'alert align-items-center mt-3 alert-' + statusType;
  processText.textContent = message;

  if (isProcessing) {
    processStatus.style.display = 'flex';
    sendBtn.disabled = true;
    stopBtn.style.display = '';
    processSpinner.style.display = '';
  } else {
    processStatus.style.display = 'flex';
    sendBtn.disabled = false;
    stopBtn.style.display = 'none';
    processSpinner.style.display = 'none';
  }
}

function menunggakResetProgressUI() {
  const progressWrap = document.getElementById('menunggakProgressWrap');
  const progressBar = document.getElementById('menunggakProgressBar');
  const progressMeta = document.getElementById('menunggakProgressMeta');

  progressWrap.style.display = 'none';
  progressBar.style.width = '0%';
  progressBar.textContent = '0%';
  progressBar.classList.add('progress-bar-animated');
  progressMeta.textContent = '0/0 • Berhasil: 0 • Gagal: 0';
}

function menunggakUpdateProgressUI(payload) {
  const progressWrap = document.getElementById('menunggakProgressWrap');
  const progressBar = document.getElementById('menunggakProgressBar');
  const progressMeta = document.getElementById('menunggakProgressMeta');

  const processed = Number(payload.processed || 0);
  const total = Number(payload.total || payload.total_target || 0);
  const successCount = Number(payload.success_count || 0);
  const failedCount = Number(payload.failed_count || 0);
  const percent = total > 0 ? Math.round((processed / total) * 100) : 0;

  progressWrap.style.display = 'block';
  progressBar.style.width = percent + '%';
  progressBar.textContent = percent + '%';
  progressMeta.textContent = processed + '/' + total + ' • Berhasil: ' + successCount + ' • Gagal: ' + failedCount;

  if (percent >= 100) {
    progressBar.classList.remove('progress-bar-animated');
  }
}

function menunggakParseStreamLine(line, onEvent) {
  const safeLine = String(line || '').trim();
  if (!safeLine) return;

  const separatorIndex = safeLine.indexOf(':');
  if (separatorIndex < 0) return;

  const eventName = safeLine.slice(0, separatorIndex).toUpperCase();
  const body = safeLine.slice(separatorIndex + 1);
  let payload = {};

  try {
    payload = body ? JSON.parse(body) : {};
  } catch (e) {
    payload = { raw: body };
  }

  onEvent(eventName, payload, safeLine);
}

async function menunggakConsumeStreamResponse(response, onEvent) {
  if (!response.body || !response.body.getReader) {
    const text = await response.text();
    const lines = text.split(/\r?\n/);
    lines.forEach(line => menunggakParseStreamLine(line, onEvent));
    return text;
  }

  const reader = response.body.getReader();
  const decoder = new TextDecoder();
  let buffer = '';
  let allText = '';

  while (true) {
    const readResult = await reader.read();
    if (readResult.done) break;

    const chunk = decoder.decode(readResult.value, { stream: true });
    allText += chunk;
    buffer += chunk;

    const lines = buffer.split(/\r?\n/);
    buffer = lines.pop() || '';
    lines.forEach(line => menunggakParseStreamLine(line, onEvent));
  }

  const rest = decoder.decode();
  if (rest) {
    allText += rest;
    buffer += rest;
  }

  if (buffer.trim() !== '') {
    menunggakParseStreamLine(buffer, onEvent);
  }

  return allText;
}

function stopMenunggakProcess() {
  if (menunggakActiveController) {
    menunggakActiveController.abort();
  }
}

async function submitMenunggakBroadcast() {
  if (menunggakIsSubmitting) return;

  const form = document.getElementById('menunggakBroadcastForm');
  const debugContainer = document.getElementById('menunggakDebugContainer');
  const debugOutput = document.getElementById('menunggakDebugOutput');

  if (!form) return;

  const idpelList = (form.querySelector('[name="idpel_list"]')?.value || '').trim();
  if (!idpelList) {
    alert('Tidak ada pelanggan menunggak yang bisa dibroadcast.');
    return;
  }

  if (!form.reportValidity()) {
    return;
  }

  let finalSummary = null;
  let streamErrorMessage = '';

  debugContainer.style.display = 'none';
  debugOutput.textContent = '';
  menunggakResetProgressUI();
  menunggakIsSubmitting = true;
  menunggakSetProcessState(true, 'Sedang mengirim notifikasi ke pelanggan menunggak...', 'info');

  const formData = new FormData(form);
  formData.set('stream', '1');
  formData.set('debug', '1');
  const payload = new URLSearchParams(formData);
  menunggakActiveController = new AbortController();

  try {
    const response = await fetch('proses/notif_menunggak_manual.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
      },
      body: payload.toString(),
      signal: menunggakActiveController.signal,
      cache: 'no-store'
    });

    const responseText = await menunggakConsumeStreamResponse(response, function(eventName, payload, rawLine) {
      debugContainer.style.display = 'block';
      debugOutput.textContent += (rawLine + '\n');

      if (eventName === 'START') {
        menunggakUpdateProgressUI({
          processed: 0,
          total: payload.total_target || 0,
          success_count: 0,
          failed_count: 0
        });
        menunggakSetProcessState(true, 'Proses pengiriman dimulai...', 'info');
      } else if (eventName === 'PROGRESS') {
        menunggakUpdateProgressUI(payload);
        const nama = payload.nama ? ' - ' + payload.nama : '';
        const statusText = payload.status_text ? ' (' + payload.status_text + ')' : '';
        menunggakSetProcessState(true, 'Mengirim ke ' + (payload.idpel || '-') + nama + statusText, 'info');
      } else if (eventName === 'DONE') {
        finalSummary = payload.summary || null;
        if (payload.summary) {
          menunggakUpdateProgressUI({
            processed: payload.summary.total_target || 0,
            total: payload.summary.total_target || 0,
            success_count: payload.summary.success_count || 0,
            failed_count: payload.summary.failed_count || 0
          });
        }
      } else if (eventName === 'ERROR') {
        streamErrorMessage = payload.message || 'Terjadi kesalahan saat proses broadcast menunggak.';
      }
    });

    if (!response.ok) {
      const fallbackText = responseText ? String(responseText).trim().split(/\r?\n/)[0] : '';
      const msg = streamErrorMessage || fallbackText || ('Proses selesai dengan status HTTP ' + response.status + '.');
      if (responseText && debugOutput.textContent.trim() === '') {
        debugContainer.style.display = 'block';
        debugOutput.textContent = responseText;
      }
      menunggakSetProcessState(false, msg, 'warning');
      alert(msg);
    } else if (streamErrorMessage) {
      menunggakSetProcessState(false, streamErrorMessage, 'warning');
      alert(streamErrorMessage);
    } else {
      const successCount = finalSummary ? Number(finalSummary.success_count || 0) : 0;
      const failedCount = finalSummary ? Number(finalSummary.failed_count || 0) : 0;
      const totalTarget = finalSummary ? Number(finalSummary.total_target || 0) : 0;
      menunggakSetProcessState(false, 'Broadcast menunggak selesai. Total: ' + totalTarget + ' | Berhasil: ' + successCount + ' | Gagal: ' + failedCount, 'success');
      alert('Broadcast pelanggan menunggak selesai diproses.');
    }
  } catch (error) {
    if (error.name === 'AbortError') {
      menunggakSetProcessState(false, 'Proses dihentikan dari browser.', 'warning');
      alert('Proses dihentikan.');
    } else {
      debugContainer.style.display = 'block';
      debugOutput.textContent = 'Fetch error: ' + error.message;
      menunggakSetProcessState(false, 'Gagal mengirim request ke server.', 'danger');
      alert('Gagal mengirim request. Cek debug.');
    }
  } finally {
    menunggakActiveController = null;
    menunggakIsSubmitting = false;
  }
}

let currentButton = null;

function openModal(button) {
  currentButton = button;

  // Ambil data dari tombol
  const idpel = button.getAttribute("data-idpel") || "";
  const brand = button.getAttribute("data-brand") || "";
  const nowa = button.getAttribute("data-nowa") || "";
  const nama = button.getAttribute("data-nama") || "";
  const alamat = button.getAttribute("data-alamat") || "";
  const email = button.getAttribute("data-email") || "";
  const tikor = button.getAttribute("data-tikor") || "";
  const odp = button.getAttribute("data-odp") || "";

  // Isi input hidden di form modal
  document.getElementById("idpelInput").value = idpel;
  document.getElementById("brandInput").value = brand;
  document.getElementById("nowaInput").value = nowa;
  document.getElementById("namaInput").value = nama;
  document.getElementById("alamatInput").value = alamat;
  document.getElementById("emailInput").value = email;
  document.getElementById("tikorInput").value = tikor;
  document.getElementById("odpInput").value = odp;

  // Debug log (boleh dihapus setelah selesai)
  console.log("Modal Data:", {
    idpel,
    brand,
    nowa,
    nama,
    alamat,
    email,
    tikor,
    odp
  });

  // Tampilkan modal
  document.getElementById("popupModal").style.display = "flex";
  // Pastikan loading disembunyikan
  document.getElementById("loadingOverlay").style.display = "none";
  // Enable form elements
  const formElements = document.getElementById("popupForm").querySelectorAll("input, select, button");
  formElements.forEach(el => el.disabled = false);
}

function closeModal() {
  document.getElementById("popupModal").style.display = "none";
  document.getElementById("popupForm").reset();
  // Sembunyikan loading jika masih terlihat
  document.getElementById("loadingOverlay").style.display = "none";
  // Enable form elements
  const formElements = document.getElementById("popupForm").querySelectorAll("input, select, button");
  formElements.forEach(el => el.disabled = false);
}

document.getElementById("popupForm").addEventListener("submit", function(e) {
  e.preventDefault();
  const formData = new FormData(this);

  // Tampilkan loading
  document.getElementById("loadingOverlay").style.display = "flex";
  // Disable form elements
  const formElements = this.querySelectorAll("input, select, button");
  formElements.forEach(el => el.disabled = true);

  fetch("buat_tiket.php", {
    method: "POST",
    body: formData
  })
  .then(res => res.json())
  .then(data => {
    // Sembunyikan loading
    document.getElementById("loadingOverlay").style.display = "none";
    // Enable form elements
    formElements.forEach(el => el.disabled = false);

    alert(data.message);
    if (data.success && currentButton) {
      currentButton.disabled = true;
      currentButton.innerText = "✔ Tiket Terkirim";
    }
    closeModal();
  })
  .catch(() => {
    // Sembunyikan loading
    document.getElementById("loadingOverlay").style.display = "none";
    // Enable form elements
    formElements.forEach(el => el.disabled = false);

    alert("Gagal mengirim tiket.");
  });
});
</script>