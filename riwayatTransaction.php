<?php


require 'koneksidb.php';
require_once 'struk_helper.php';
require_once __DIR__ . '/notifbot/notifphp/tagihan_status_lib.php';

?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
  <link rel="icon" type="image/png" href="../assets/img/favicon.png">
  <title>
    CRM - Billing system
  </title>
  <!--     Fonts and icons     -->
  <link href="https://fonts.googleapis.com/css?family=Inter:300,400,500,600,700,800" rel="stylesheet" />
  <!-- Nucleo Icons -->
  <link href="https://demos.creative-tim.com/soft-ui-dashboard/assets/css/nucleo-icons.css" rel="stylesheet" />
  <link href="https://demos.creative-tim.com/soft-ui-dashboard/assets/css/nucleo-svg.css" rel="stylesheet" />
  <!-- Font Awesome Icons -->
  <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
  <!-- CSS Files -->
  <link id="pagestyle" href="../assets/css/soft-ui-dashboard.css?v=1.1.0" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <!-- Nepcha Analytics (nepcha.com) -->
  <!-- Tambahkan Bootstrap CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Nepcha is a easy-to-use web analytics. No cookies and fully compliant with GDPR, CCPA and PECR. -->
  <script defer data-site="YOUR_DOMAIN_HERE" src="https://api.nepcha.com/js/nepcha-analytics.js"></script>
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <!-- Tampilkan Peta -->
  <style>
    #map {
      height: 20px;
      width: 10%;
    }
  </style>
</head>

<style>
  body {
    margin: 0;
    padding: 0;
    font-family: Arial, sans-serif;
  }

  #loading {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: white;
    display: flex;
    justify-content: center;
    align-items: center;
    z-index: 9999;
  }

  .spinner {
    width: 50px;
    height: 50px;
    border: 5px solid #ccc;
    border-top-color: #007bff;
    border-radius: 50%;
    animation: spin 1s linear infinite;
  }

  @keyframes spin {
    from {
      transform: rotate(0deg);
    }

    to {
      transform: rotate(360deg);
    }
  }
</style>







<body id="content" class="g-sidenav-show  bg-gray-100">


  <div class="container py-4">
    <div class="mb-3">
     <form method="GET" class="row g-3 align-items-end">
  <div class="col-md-3">
    <label for="start_date" class="form-label">Dari</label>
    <input type="date" class="form-control" id="start_date" name="start_date" value="<?= htmlspecialchars($_GET['start_date'] ?? '') ?>">
  </div>

  <div class="col-md-3">
    <label for="end_date" class="form-label">Sampai</label>
    <input type="date" class="form-control" id="end_date" name="end_date" value="<?= htmlspecialchars($_GET['end_date'] ?? '') ?>">
  </div>

  <div class="col-md-3">
    <label for="idpel" class="form-label">IDPEL</label>
    <input type="text" class="form-control" id="idpel" name="idpel" placeholder="Masukkan IDPEL" value="<?= htmlspecialchars($_GET['idpel'] ?? '') ?>" required>
  </div>

  <div class="col-md-3">
    <button type="submit" class="btn btn-warning w-100">Filter</button>
  </div>
</form>

      <?php
      $cekidpel = $_GET['idpel'] ?? '';
      $start = $_GET['start_date'] ?? '';
      $end = $_GET['end_date'] ?? '';

      // Jika tanggal kosong, set default ke hari ini
      if (empty($start) || empty($end)) {
        $today = date('Y-m-d');
        $start = $today;
        $end = $today;
      }

      // Info mode tempo pelanggan (Rolling/Monthversary) diambil SEKALI per IDPEL,
      // tapi tanggal jatuh tempo-nya dihitung PER TRANSAKSI di bawah (masing-masing
      // pakai tanggal bayarnya sendiri sbg acuan) -- BUKAN "jatuh tempo berikutnya"
      // yang sama utk semua baris, supaya tidak membingungkan (tiap transaksi punya
      // siklus/periode sendiri-sendiri).
      $riwayatTipeTempo = '';
      $riwayatAnchorDay = 25;
      $riwayatTanggalPasang = '';
      if ($cekidpel != '') {
        $cekidpel_safe = mysqli_real_escape_string($conn, trim($cekidpel));
        $start_safe = mysqli_real_escape_string($conn, $start);
        $end_safe = mysqli_real_escape_string($conn, $end);

        $sql = "SELECT * FROM `transaksi`
          WHERE `IDPEL` = '$cekidpel_safe'
          ORDER BY
            CAST(SUBSTRING_INDEX(TRIM(PENGUNAAN), ' ', -1) AS UNSIGNED) DESC,
            FIELD(
              TRIM(SUBSTRING_INDEX(TRIM(PENGUNAAN), ' ', 1)),
              'Januari','Februari','Maret','April','Mei','Juni',
              'Juli','Agustus','September','Oktober','November','Desember'
            ) DESC,
            id DESC";

        $query = mysqli_query($conn, $sql);

        $pelRiwayatQ = mysqli_query($conn, "SELECT TIPE_TEMPO, TANGGALPASANG, TANGGAL_MONTHVERSARY FROM pelanggan WHERE IDPEL = '$cekidpel_safe' LIMIT 1");
        $pelRiwayat = $pelRiwayatQ ? mysqli_fetch_assoc($pelRiwayatQ) : null;
        if ($pelRiwayat) {
          $riwayatTipeTempo = strtolower(trim((string)($pelRiwayat['TIPE_TEMPO'] ?? '')));
          $riwayatTanggalPasang = (string)($pelRiwayat['TANGGALPASANG'] ?? '');
          if ($riwayatTipeTempo === 'monthversary') {
            $anchorDateRiwayat = (string)($pelRiwayat['TANGGAL_MONTHVERSARY'] ?? '');
            if ($anchorDateRiwayat === '' || strtotime($anchorDateRiwayat) === false) {
              $anchorDateRiwayat = (string)($pelRiwayat['TANGGALPASANG'] ?? '');
            }
            if ($anchorDateRiwayat !== '' && strtotime($anchorDateRiwayat) !== false) {
              $riwayatAnchorDay = (int)date('j', strtotime($anchorDateRiwayat));
            }
          }
        }
      }

      // Jatuh tempo yang DIPENUHI oleh transaksi ini sendiri (BUKAN jatuh tempo
      // berikutnya setelah bayar). Fixed & Monthversary: hari (jatuh_tempo_hari/
      // anchor) dipasang ke BULAN pembayaran transaksi ini sendiri (bukan bulan
      // PENGUNAAN, yang cuma label periode dan bisa beda). Rolling: hasil simulasi
      // siklus 30 hari dari histori pembayaran SEBELUM baris ini (supaya aturan
      // bayar cepat/telat tetap dihormati -- BUKAN asal tanggal bayar transaksi ini
      // + 30 hari, itu jatuh tempo SIKLUS BERIKUTNYA). Tidak ditampilkan utk baris
      // PENAGIHAN (kolom TANGGAL BAYAR baris itu sendiri sudah direlabel jadi
      // JATUH TEMPO, lihat di bawah).
      function riwayatHitungJatuhTempoBaris($conn, $tipeTempo, $anchorDay, $jatuhTempoHariFixed, $idpelRow, $tanggalPasangRow, $waktuRow, $rowId, $statusRow) {
        if (strtoupper(trim((string)$statusRow)) === 'PENAGIHAN') {
          return '';
        }
        if (empty($waktuRow) || strtotime($waktuRow) === false) {
          return '';
        }
        $tsPay = strtotime($waktuRow);
        if ($tipeTempo === 'mengikuti_tanggal_tempo') {
          $due = tagihanBuildMonthlyDate((int)date('Y', $tsPay), (int)date('n', $tsPay), $jatuhTempoHariFixed);
          return $due ? date('d-m-Y', strtotime($due)) : '';
        }
        if ($tipeTempo === 'monthversary') {
          $due = tagihanBuildMonthlyDate((int)date('Y', $tsPay), (int)date('n', $tsPay), $anchorDay);
          return $due ? date('d-m-Y', strtotime($due)) : '';
        }
        if ($tipeTempo === 'mengikuti_tanggal_bayar') {
          $due = tagihanGetRollingDueDateForRow($conn, (string)$idpelRow, (string)$tanggalPasangRow, (int)$rowId);
          return $due ? date('d-m-Y', strtotime($due)) : '';
        }
        return '';
      }

      // Label tipe tempo pelanggan, ditampilkan apa adanya di tiap kartu riwayat
      // supaya pelanggan/admin langsung tahu masuk mode Fixed/Rolling/Monthversary.
      $riwayatTipeTempoLabelMap = [
        'mengikuti_tanggal_tempo' => 'Fixed Due Date',
        'mengikuti_tanggal_bayar' => 'Rolling',
        'monthversary' => 'Monthversary',
      ];
      $riwayatTipeTempoLabel = $riwayatTipeTempoLabelMap[$riwayatTipeTempo] ?? '-';

      // Setting jatuh_tempo (Payment Setting -> Konfigurasi Fixed Due Date) dicache
      // per PEMILIK, dipakai kalau tipe tempo pelanggan ini Fixed Due Date.
      $riwayatJatuhTempoHariCache = [];
      function riwayatGetJatuhTempoHari($pemilik, &$cache) {
        if (isset($cache[$pemilik])) {
          return $cache[$pemilik];
        }
        $val = 25;
        $file = __DIR__ . '/notifbot/data/reminder-' . $pemilik . '.json';
        if (file_exists($file)) {
          $cfg = json_decode(file_get_contents($file), true);
          if (is_array($cfg) && isset($cfg[0]['jatuh_tempo'])) {
            $val = (int)$cfg[0]['jatuh_tempo'];
          }
        }
        $cache[$pemilik] = max(1, min(31, $val));
        return $cache[$pemilik];
      }
      ?>








    </div>
    <div class="row" id="cardContainer">

      <?php
      if ($cekidpel != '') {
        if ($query && mysqli_num_rows($query) > 0) {
          $jumlah = mysqli_num_rows($query);
          echo '<div class="col-12 mb-3"><div class="d-flex justify-content-center"><span class="badge bg-primary fs-5" style="padding:10px 20px;">Jumlah data: ' . $jumlah . '</span></div></div>';

          while ($data = mysqli_fetch_array($query)) {
            $id = $data['id'];

            // Halaman ini publik (tanpa login), jadi tidak ada $ceknama/$asistant_name dari sesi.
            // Pengaturan struk disimpan per akun CRM (lihat struk_setting.php), sedangkan
            // transaksi.PEMILIK adalah username MikroTik per-server (lihat proses/addserver.php),
            // bukan username akun CRM. Resolve dulu akun pemilik server ini lewat join server->user.
            $row_struk_username = $data['PEMILIK'] ?? '';
            $rowOwnerQ = mysqli_query($conn, "SELECT u.USERNAME FROM server s INNER JOIN user u ON u.id = s.user_id WHERE s.PEMILIK = '" . mysqli_real_escape_string($conn, (string)($data['PEMILIK'] ?? '')) . "' LIMIT 1");
            if ($rowOwnerQ && mysqli_num_rows($rowOwnerQ) > 0) {
              $row_struk_username = mysqli_fetch_assoc($rowOwnerQ)['USERNAME'];
            }
            $row_struk_settings = get_struk_settings($row_struk_username);
            $metode_bayar_raw = strtolower(trim($data['METODE_BAYAR'] ?? ''));
            if ($metode_bayar_raw === 'cash') {
              $metode_bayar_label = 'Cash';
            } elseif ($metode_bayar_raw === 'transfer') {
              $metode_bayar_label = 'Transfer';
            } else {
              $metode_bayar_label = 'Payment gateway';
            }

            $bukti_value = trim($data['BUKTI'] ?? '');
            $bukti_image_url = '';
            if ($metode_bayar_raw === 'cash' || $metode_bayar_raw === 'transfer') {
              if (preg_match('/^https?:\/\//i', $bukti_value)) {
                $bukti_image_url = $bukti_value;
              } elseif (strpos($bukti_value, 'buktibon/') === 0) {
                $bukti_image_url = '/dokumen/' . $bukti_value;
              } elseif (preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $bukti_value)) {
                $bukti_image_url = 'uploads/' . $bukti_value;
              }
            }

            $status_badge_class = 'bg-warning text-dark';
            $status_badge_label = htmlspecialchars($data['STATUS']);
            if ($data['STATUS'] === 'BERHASIL') {
              $status_badge_class = 'bg-success text-white';
            } elseif ($data['STATUS'] === 'PENAGIHAN') {
              $status_badge_class = 'bg-warning text-dark';
              $status_badge_label = 'PENAGIHAN (menunggu bayar)';
            } else {
              $status_badge_class = 'bg-secondary text-white';
            }

            // Dihitung per baris (pakai tanggal bayar transaksi INI), bukan status
            // "jatuh tempo berikutnya" global -- supaya tiap kartu riwayat menampilkan
            // jatuh tempo yang relevan utk transaksi itu sendiri, bukan nilai yang
            // sama berulang di semua kartu. Berlaku utk semua status non-PENAGIHAN,
            // termasuk BERHASIL.
            // Pakai $row_struk_username (akun CRM pemilik, sudah diresolve di atas via
            // join server->user), BUKAN $data['PEMILIK'] (server/router, bisa beda
            // string) -- jatuh_tempo disimpan per AKUN CRM di reminder-{akun}.json.
            $riwayatJatuhTempoHariFixed = ($riwayatTipeTempo === 'mengikuti_tanggal_tempo')
              ? riwayatGetJatuhTempoHari($row_struk_username, $riwayatJatuhTempoHariCache)
              : 25;
            $riwayatJatuhTempoBaris = riwayatHitungJatuhTempoBaris($conn, $riwayatTipeTempo, $riwayatAnchorDay, $riwayatJatuhTempoHariFixed, $data['IDPEL'] ?? $cekidpel, $riwayatTanggalPasang, $data['waktu'] ?? '', $data['id'] ?? 0, $data['STATUS'] ?? '');
        ?>
          <div class="col-md-12 mb-4">
            <div class="card shadow-sm border-start border-4" style="border-color: #ff9800 !important;">
              <div class="card-body p-3">
                <!-- Header: Badge & Timestamp -->
                <div class="d-flex justify-content-between align-items-start mb-3">
                  <div>
                    <span class="badge bg-warning text-dark me-2">TRANSAKSI</span>
                    <span class="badge <?php echo $status_badge_class; ?>"><?php echo $status_badge_label; ?></span>
                  </div>
                  <small class="text-muted"><?php echo htmlspecialchars($data['TANGGALBAYAR']); ?></small>
                </div>

                <!-- Row 1: Tanggal Bayar/Jatuh Tempo, Pengunaan, IDPEL, Nama -->
                <div class="row mb-3">
                  <div class="col-md-3 mb-2 mb-md-0">
                    <small class="text-muted d-block"><?php echo $data['STATUS'] === 'PENAGIHAN' ? 'JATUH TEMPO' : 'TANGGAL BAYAR'; ?></small>
                    <strong><?php echo htmlspecialchars($data['TANGGALBAYAR']); ?></strong>
                    <?php if ($riwayatJatuhTempoBaris !== '') { ?>
                      <span class="badge bg-info text-dark d-block mt-1" style="width:fit-content;">Jatuh tempo: <?php echo htmlspecialchars($riwayatJatuhTempoBaris); ?></span>
                    <?php } ?>
                    <span class="badge bg-secondary d-block mt-1" style="width:fit-content;">Tipe Tempo: <?php echo htmlspecialchars($riwayatTipeTempoLabel); ?></span>
                  </div>
                  <div class="col-md-3 mb-2 mb-md-0">
                    <small class="text-muted d-block">PENGUNAAN</small>
                    <strong><?php echo htmlspecialchars($data['PENGUNAAN']); ?></strong>
                  </div>
                  <div class="col-md-3 mb-2 mb-md-0">
                    <small class="text-muted d-block">IDPEL</small>
                    <strong><?php echo htmlspecialchars($data['IDPEL']); ?></strong>
                  </div>
                  <div class="col-md-3">
                    <small class="text-muted d-block">NAMA</small>
                    <strong><?php echo htmlspecialchars($data['NAMA']); ?></strong>
                  </div>
                </div>

                <!-- Row 2: Paket, Harga, Pemilik -->
                <div class="row mb-3">
                  <div class="col-md-4 mb-2 mb-md-0">
                    <small class="text-muted d-block">PAKET</small>
                    <strong><?php echo htmlspecialchars($data['PAKET']); ?></strong>
                  </div>
                  <div class="col-md-4 mb-2 mb-md-0">
                    <small class="text-muted d-block">HARGA</small>
                    <strong class="text-primary">Rp <?php echo number_format($data['HARGA'], 0, ',', '.'); ?></strong>
                  </div>
                  <div class="col-md-4">
                    <small class="text-muted d-block">PEMILIK</small>
                    <strong><?php echo htmlspecialchars($data['PEMILIK']); ?></strong>
                  </div>
                </div>

                <!-- Row 3: Metode Bayar, Bukti, CEK -->
                <div class="row mb-3">
                  <div class="col-md-4 mb-2 mb-md-0">
                    <small class="text-muted d-block">METODE BAYAR</small>
                    <strong><?php echo htmlspecialchars($metode_bayar_label); ?></strong>
                  </div>
                  <div class="col-md-4 mb-2 mb-md-0">
                    <small class="text-muted d-block">BUKTI / REF</small>
                    <strong><?php echo htmlspecialchars($bukti_value); ?></strong>
                  </div>
                  <div class="col-md-4">
                    <small class="text-muted d-block">CEK</small>
                    <strong><?php echo htmlspecialchars($data['CEK'] ?? 'N/A'); ?></strong>
                  </div>
                </div>

                <!-- Row 4: Fee & Payment Method -->
                <?php if (!empty($data['payment_method']) || !empty($data['harga_gross'])) { ?>
                <div class="row mb-3">
                  <div class="col-md-3 mb-2 mb-md-0">
                    <small class="text-muted d-block">PAYMENT METHOD</small>
                    <strong><?php echo htmlspecialchars($data['payment_method'] ?? 'N/A'); ?></strong>
                  </div>
                  <div class="col-md-3 mb-2 mb-md-0">
                    <small class="text-muted d-block">HARGA GROSS</small>
                    <strong class="text-info">Rp <?php echo number_format($data['harga_gross'] ?? 0, 0, ',', '.'); ?></strong>
                  </div>
                  <div class="col-md-3 mb-2 mb-md-0">
                    <small class="text-muted d-block">FEE MERCHANT</small>
                    <strong class="text-danger">Rp <?php echo number_format($data['fee_merchant'] ?? 0, 0, ',', '.'); ?></strong>
                  </div>
                  <div class="col-md-3">
                    <small class="text-muted d-block">FEE CUSTOMER</small>
                    <strong class="text-danger">Rp <?php echo number_format($data['fee_customer'] ?? 0, 0, ',', '.'); ?></strong>
                  </div>
                </div>
                <?php } ?>

                <!-- Actions -->
                <div class="row">
                  <?php if (!empty($bukti_image_url)) { ?>
                    <div class="col-md-3 mb-3 mb-md-0">
                      <img src="<?php echo htmlspecialchars($bukti_image_url); ?>" alt="Bukti" style="max-width:100%;max-height:150px;border:1px solid #ddd;border-radius:6px;" onerror="this.style.display='none'">
                    </div>
                  <?php } ?>
                  <div class="col-md-9">
                    <div class="d-flex gap-2 flex-wrap">
                      <?php if (!empty($row_struk_settings['print_enabled'])) { ?>
                        <a href="print_struk.php?id=<?php echo $id; ?>" class="btn btn-sm btn-outline-primary" target="_blank">
                          <i class="bi bi-printer"></i> Print Struk
                        </a>
                      <?php } ?>
                      <?php if (!empty($row_struk_settings['pdf_enabled'])) { ?>
                        <a href="print_card.php?id=<?php echo $id; ?>" class="btn btn-sm btn-primary" target="_blank">
                          <i class="bi bi-download"></i> Download PDF
                        </a>
                      <?php } ?>
                    </div>
                  </div>
                </div>

              </div>
            </div>
          </div>
        <?php
          }
        } else {
          echo '<div class="col-12"><div class="alert alert-info">Tidak ada data transaksi untuk IDPEL ini.</div></div>';
        }
      } else {
        echo "<div class='col-12'><div class='alert alert-warning'>Silakan masukkan IDPEL untuk melihat riwayat transaksi.</div></div>";
      }
      ?>
      <script>
        // removed unused printCard and searchInput functions
      </script>

    </div>
  </div>


  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>







  </div>
  </main>

  <!--   Core JS Files   -->
  <script src="../assets/js/core/popper.min.js"></script>
  <script src="../assets/js/core/bootstrap.min.js"></script>
  <script src="../assets/js/plugins/perfect-scrollbar.min.js"></script>
  <script src="../assets/js/plugins/smooth-scrollbar.min.js"></script>
  <script>
    var win = navigator.platform.indexOf('Win') > -1;
    if (win && document.querySelector('#sidenav-scrollbar')) {
      var options = {
        damping: '0.5'
      }
      Scrollbar.init(document.querySelector('#sidenav-scrollbar'), options);
    }
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <!-- Github buttons -->
  <!-- Tambahkan Bootstrap JS (wajib untuk modal) -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script async defer src="https://buttons.github.io/buttons.js"></script>
  <!-- Control Center for Soft Dashboard: parallax effects, scripts for the example pages etc -->
  <script src="../assets/js/soft-ui-dashboard.min.js?v=1.1.0"></script>
</body>

<script>
  window.addEventListener("load", function() {
    document.getElementById("loading").style.display = "none";
    document.getElementById("content").style.display = "block";
  });
</script>

<script>
  document.addEventListener("DOMContentLoaded", function() {
    const navLinks = document.querySelectorAll(".nav-link");

    navLinks.forEach((link) => {
      // Event ketika mouse masuk (hover)
      link.addEventListener("mouseenter", function() {
        this.classList.add("active");
      });

      // Event ketika mouse keluar
      link.addEventListener("mouseleave", function() {
        this.classList.remove("active");
      });

      // Event ketika link diklik
      link.addEventListener("click", function(event) {
        // Hapus active dari semua link
        navLinks.forEach((el) => el.classList.remove("active"));

        // Tambahkan active ke yang diklik
        this.classList.add("active");

        // Jalankan navigasi ke href
        const href = this.getAttribute("href");
        if (href && href !== "#") {
          window.location.href = href;
        }
      });
    });
  });
</script>

</html>