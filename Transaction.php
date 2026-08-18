<?php require 'header.php';
if ($AKSES == 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('Transaction', $akses_menu, true)) {
        echo '<div class="container-fluid py-4"><div class="alert alert-danger">Anda tidak memiliki akses ke menu Transaction.</div></div>';
        require 'footer.php';
        exit;
    }
}
 ?>
<?php
// Scoping kepemilikan server (pola sama dgn dashboard.php) -- dihitung SEKALI
// di sini, dipakai ulang di SEMUA query pada halaman ini (dropdown filter,
// listing utama, generate invoice manual, delete transaksi). Sebelumnya tiap
// blok query membangun ulang server list sendiri2 dgn "SELECT PEMILIK FROM
// server WHERE user_id = $current_user_id" TANPA membedakan ASSISTANT --
// $current_user_id utk ASSISTANT berisi id akun OWNER (lihat cek-sesi.php),
// BUKAN id assistant itu sendiri, jadi hasilnya SELALU SEMUA server milik
// owner, bukan cuma area yang di-assign ke assistant tsb (bocor lintas-area).
$userServerIds = [];
if ($AKSES === 'ASSISTANT') {
    if (isset($area_list) && trim((string)$area_list) !== '') {
        $queryServerId = mysqli_query($conn, "SELECT PEMILIK FROM server WHERE AREA IN ($area_list)");
        if ($queryServerId) {
            while ($row = mysqli_fetch_assoc($queryServerId)) {
                $userServerIds[] = "'" . $row['PEMILIK'] . "'";
            }
        }
    }
} else {
    $queryServerId = mysqli_query($conn, "SELECT PEMILIK FROM server WHERE user_id = $current_user_id");
    if ($queryServerId) {
        while ($row = mysqli_fetch_assoc($queryServerId)) {
            $userServerIds[] = "'" . $row['PEMILIK'] . "'";
        }
    }
}
$userServerList = count($userServerIds) > 0 ? implode(",", $userServerIds) : "''";

require_once 'struk_helper.php';
require_once __DIR__ . '/notifbot/notifphp/tagihan_status_lib.php';
$struk_settings_username = ($AKSES == 'ASSISTANT' && !empty($asistant_name)) ? $asistant_name : $ceknama;
$struk_settings = get_struk_settings($struk_settings_username);
?>



    <!-- Tombol Copy -->

    <?php
    $bulan_penggunaan = [
      'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
      'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
    ];
    $selected_filter_month = trim($_GET['periode_bulan'] ?? '');
    $selected_filter_year = trim($_GET['periode_tahun'] ?? '');
    ?>

  <div class="container-fluid py-4 px-3 px-md-4">

    <?php if (isset($AKSES) && in_array($AKSES, ['ADMIN', 'ASSISTANT'], true)): ?>
    <div class="d-flex justify-content-end mb-3">
      <a href="adjust_tanggal_tempo_excel.php" class="btn btn-outline-primary btn-sm" data-perm="btn_trx_adjust_tanggal">
        <i class="fas fa-file-excel"></i> Penyesuaian Tanggal Bayar &amp; Jatuh Tempo (Excel)
      </a>
    </div>
    <?php endif; ?>

    <div class="card shadow p-4">
    <div class="mb-3">
    <form method="GET" class="row g-3 align-items-end" id="filterForm">
      <?php $today = date('Y-m-d'); ?>
      <div class="col-md-2">
        <label for="start_date" class="form-label">Dari</label>
        <input type="date" class="form-control" id="start_date" name="start_date" 
          value="<?= htmlspecialchars($_GET['start_date'] ?? '') ?>"
          placeholder="<?= $today ?>">
      </div>
      <div class="col-md-2">
        <label for="end_date" class="form-label">Sampai</label>
        <input type="date" class="form-control" id="end_date" name="end_date" 
          value="<?= htmlspecialchars($_GET['end_date'] ?? '') ?>"
          placeholder="<?= $today ?>">
      </div>
      <div class="col-md-2">
        <label for="periode_bulan" class="form-label">Periode Bulan</label>
        <select class="form-control" id="periode_bulan" name="periode_bulan">
          <option value="">Semua Bulan</option>
          <?php foreach ($bulan_penggunaan as $bulan_item): ?>
            <option value="<?= htmlspecialchars($bulan_item) ?>" <?= ($selected_filter_month === $bulan_item) ? 'selected' : '' ?>>
              <?= htmlspecialchars($bulan_item) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label for="periode_tahun" class="form-label">Periode Tahun</label>
        <select class="form-control" id="periode_tahun" name="periode_tahun">
          <option value="">Semua Tahun</option>
          <?php
          // $userServerList sudah dihitung sekali di atas (scoping ASSISTANT-aware).
          $tahun_options = [];
          $tahun_q = mysqli_query($conn, "SELECT DISTINCT RIGHT(TRIM(PENGUNAAN), 4) AS TAHUN FROM transaksi WHERE TRIM(COALESCE(PENGUNAAN, '')) != '' AND PEMILIK IN ($userServerList) ORDER BY CAST(RIGHT(TRIM(PENGUNAAN), 4) AS UNSIGNED) DESC");
          if ($tahun_q) {
            while ($th = mysqli_fetch_assoc($tahun_q)) {
              $tahun_item = trim($th['TAHUN'] ?? '');
              if (preg_match('/^\\d{4}$/', $tahun_item)) {
                $tahun_options[$tahun_item] = $tahun_item;
              }
            }
          }

          for ($yr = (int)date('Y') + 1; $yr >= (int)date('Y') - 5; $yr--) {
            $tahun_options[(string)$yr] = (string)$yr;
          }

          if ($selected_filter_year !== '' && preg_match('/^\\d{4}$/', $selected_filter_year)) {
            $tahun_options[$selected_filter_year] = $selected_filter_year;
          }

          $tahun_options = array_values(array_unique($tahun_options));
          rsort($tahun_options, SORT_NUMERIC);

          foreach ($tahun_options as $tahun_item) {
            $sel = ($selected_filter_year === $tahun_item) ? 'selected' : '';
            echo "<option value='" . htmlspecialchars($tahun_item) . "' $sel>" . htmlspecialchars($tahun_item) . "</option>";
          }
          ?>
        </select>
      </div>
      <div class="col-md-2">
        <label for="idpel" class="form-label">IDPEL</label>
        <input type="text" class="form-control" id="idpel" name="idpel" placeholder="Masukkan IDPEL" value="<?= htmlspecialchars($_GET['idpel'] ?? '') ?>">
      </div>
      <div class="col-md-2">
        <label for="nama" class="form-label">Nama</label>
        <input type="text" class="form-control" id="nama" name="nama" placeholder="Cari nama pelanggan..." value="<?= htmlspecialchars($_GET['nama'] ?? '') ?>">
      </div>
      <div class="col-md-2">
        <label for="area" class="form-label">AREA</label>
        <select class="form-control" id="area" name="area">
          <option value="">Semua</option>
          <?php
          // Ambil hanya area dari pelanggan yang server-nya dimiliki oleh user yang sedang login (seperti paket dan sales)
          // $userServerList sudah dihitung sekali di atas (scoping ASSISTANT-aware).
          $area_q = mysqli_query($conn, "SELECT DISTINCT AREA FROM pelanggan WHERE AREA IS NOT NULL AND AREA != '' AND PEMILIK IN ($userServerList) ORDER BY AREA");
          while ($ar = mysqli_fetch_assoc($area_q)) {
            $sel = (isset($_GET['area']) && $_GET['area'] == $ar['AREA']) ? 'selected' : '';
            echo "<option value='" . htmlspecialchars($ar['AREA']) . "' $sel>" . htmlspecialchars($ar['AREA']) . "</option>";
          }
          ?>
        </select>
      </div>
      <div class="col-md-2">
        <label for="sales" class="form-label">Sales</label>
        <select class="form-control" id="sales" name="sales">
          <option value="">Semua</option>
          <?php
          // $userServerList sudah dihitung sekali di atas (scoping ASSISTANT-aware).
          $sales_q = mysqli_query($conn, "SELECT DISTINCT sales FROM pelanggan WHERE sales IS NOT NULL AND sales != '' AND PEMILIK IN ($userServerList) ORDER BY sales");
          while ($sl = mysqli_fetch_assoc($sales_q)) {
            $sel = (isset($_GET['sales']) && $_GET['sales'] == $sl['sales']) ? 'selected' : '';
            echo "<option value='" . htmlspecialchars($sl['sales']) . "' $sel>" . htmlspecialchars($sl['sales']) . "</option>";
          }
          ?>
        </select>
      </div>
      <div class="col-md-2">
        <label for="paket" class="form-label">Paket</label>
        <select class="form-control" id="paket" name="paket">
          <option value="">Semua</option>
          <?php
          // $userServerList sudah dihitung sekali di atas (scoping ASSISTANT-aware).
          $paket_q = mysqli_query($conn, "SELECT DISTINCT PAKET FROM paket WHERE PAKET IS NOT NULL AND PAKET != '' AND PEMILIK IN ($userServerList) ORDER BY PAKET");
          while ($pk = mysqli_fetch_assoc($paket_q)) {
            if (paketVisibilityIsHidden((string)$pk['PAKET'], $assistant_hidden_paket_broadband ?? [])) {
              continue;
            }
            $sel = (isset($_GET['paket']) && $_GET['paket'] == $pk['PAKET']) ? 'selected' : '';
            echo "<option value='" . htmlspecialchars($pk['PAKET']) . "' $sel>" . htmlspecialchars($pk['PAKET']) . "</option>";
          }
          ?>
        </select>
      </div>
      <div class="col-md-2">
        <label for="metode_bayar" class="form-label">Metode Bayar</label>
        <select class="form-control" id="metode_bayar" name="metode_bayar">
          <option value="">Semua</option>
          <option value="cash" <?= (($_GET['metode_bayar'] ?? '') === 'cash') ? 'selected' : '' ?>>Cash</option>
          <option value="transfer" <?= (($_GET['metode_bayar'] ?? '') === 'transfer') ? 'selected' : '' ?>>Transfer</option>
          <option value="payment_gateway" <?= (($_GET['metode_bayar'] ?? '') === 'payment_gateway') ? 'selected' : '' ?>>Payment gateway</option>
        </select>
      </div>
      <div class="col-md-2">
        <label for="payment_method" class="form-label">Payment Method</label>
        <select class="form-control" id="payment_method" name="payment_method">
          <option value="">Semua</option>
          <?php
          $pm_q = mysqli_query($conn, "SELECT DISTINCT payment_method FROM transaksi WHERE payment_method IS NOT NULL AND payment_method != '' AND PEMILIK IN ($userServerList) ORDER BY payment_method ASC");
          while ($pm = mysqli_fetch_assoc($pm_q)) {
            $sel = (($_GET['payment_method'] ?? '') === $pm['payment_method']) ? 'selected' : '';
            echo "<option value='" . htmlspecialchars($pm['payment_method']) . "' $sel>" . htmlspecialchars($pm['payment_method']) . "</option>";
          }
          ?>
        </select>
      </div>
      <div class="col-md-2">
        <label for="status" class="form-label">Status</label>
        <select class="form-control" id="status" name="status">
          <option value="">Semua</option>
          <option value="PENAGIHAN" <?= (($_GET['status'] ?? '') === 'PENAGIHAN') ? 'selected' : '' ?>>PENAGIHAN</option>
          <option value="PERMINTAAN KODE" <?= (($_GET['status'] ?? '') === 'PERMINTAAN KODE') ? 'selected' : '' ?>>PERMINTAAN KODE</option>
          <option value="KONFIRMASI" <?= (($_GET['status'] ?? '') === 'KONFIRMASI') ? 'selected' : '' ?>>KONFIRMASI</option>
          <option value="BERHASIL" <?= (($_GET['status'] ?? '') === 'BERHASIL') ? 'selected' : '' ?>>BERHASIL</option>
        </select>
      </div>
      <div class="col-md-2">
        <label for="bukti" class="form-label">Kode Bukti / Ref / TRX</label>
        <input type="text" class="form-control" id="bukti" name="bukti" placeholder="Cari kode bukti..." value="<?= htmlspecialchars($_GET['bukti'] ?? '') ?>">
      </div>
      <div class="col-md-2 d-flex gap-2">
        <button type="submit" class="btn btn-warning w-100">Filter</button>
        <button type="button" class="btn btn-secondary w-100" id="resetFilterBtn">Reset</button>
      </div>
<script>
// Reset filter: clear all input/select and set tanggal ke hari ini
document.getElementById('resetFilterBtn').addEventListener('click', function() {
  var form = document.getElementById('filterForm');
  form.reset();
  // Remove all query params and reload
  window.location = window.location.pathname;
});
</script>
    </form>

    <?php
    $selected_generate_month = $_POST['generate_month'] ?? $bulan_penggunaan[(int)date('n') - 1];
    $selected_generate_year = (int)($_POST['generate_year'] ?? date('Y'));
    ?>

    <form method="POST" class="row g-3 align-items-end mt-1" id="manualGenerateForm">
      <div class="col-md-3">
        <label for="generate_month" class="form-label">Periode Penggunaan (Bulan)<br><small class="text-muted">Khusus pelanggan Fixed Due Date, 1 periode per generate</small></label>
        <select class="form-control" id="generate_month" name="generate_month" required>
          <?php foreach ($bulan_penggunaan as $bulan_item): ?>
            <option value="<?= htmlspecialchars($bulan_item) ?>" <?= ($selected_generate_month === $bulan_item) ? 'selected' : '' ?>>
              <?= htmlspecialchars($bulan_item) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label for="generate_year" class="form-label">Tahun</label>
        <select class="form-control" id="generate_year" name="generate_year" required>
          <?php for ($yr = date('Y') - 1; $yr <= date('Y') + 3; $yr++): ?>
            <option value="<?= $yr ?>" <?= ((int)$selected_generate_year === (int)$yr) ? 'selected' : '' ?>><?= $yr ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="col-md-3 d-grid">
        <button type="submit" class="btn btn-primary" id="manualGenerateBtn" name="manual_generate_invoice" onclick="return confirm('Generate invoice PENAGIHAN untuk periode yang dipilih?');">
          Manual Generate Invoice
        </button>
      </div>
    </form>
    <div id="manualGenerateResult" class="mt-3"></div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
      var manualForm = document.getElementById('manualGenerateForm');
      var manualBtn = document.getElementById('manualGenerateBtn');
      var manualResult = document.getElementById('manualGenerateResult');

      if (!manualForm || !manualBtn || !manualResult) return;

      manualForm.addEventListener('submit', function(e) {
        e.preventDefault();

        var formData = new FormData(manualForm);
        formData.append('manual_generate_invoice', '1');

        manualBtn.disabled = true;
        manualBtn.textContent = 'Memproses...';
        manualResult.innerHTML = '<div class="alert alert-info">Generate invoice sedang diproses di background...</div>';

        fetch('proses/manual_generate_invoice.php', {
          method: 'POST',
          body: formData,
          headers: {
            'X-Requested-With': 'XMLHttpRequest'
          }
        })
        .then(function(response) { return response.json(); })
        .then(function(result) {
          if (result && result.success) {
            // "message" pakai input mentah admin (bisa MENYESATKAN kalau Periode
            // Tercatat 'berikutnya' menggeser bulannya) -- tampilkan juga
            // rincian_periode (label PENGUNAAN yang BENAR-BENAR dipakai insert)
            // + info debug sementara supaya kelihatan kalau ada yang tidak sesuai.
            var rincianHtml = '';
            if (result.rincian_periode) {
              rincianHtml = '<br>Rincian per periode: <code>' + JSON.stringify(result.rincian_periode) + '</code>';
            }
            var debugHtml = '<br><small class="text-muted">Debug: mode=' + result.debug_periode_tercatat_mode +
              ', bulanInput=' + result.debug_indexBulanInput_plus1 +
              ', akun=' + result.debug_ceknama +
              ', file=' + result.debug_reminder_file_path + ' (' + (result.debug_reminder_file_exists ? 'ada' : 'TIDAK ADA') + ')</small>';
            manualResult.innerHTML = '<div class="alert alert-success">' +
              result.message +
              ' Inserted: <strong>' + result.inserted + '</strong>, Skipped: <strong>' + result.skipped + '</strong>.' +
              rincianHtml + debugHtml +
            '</div>';
          } else {
            manualResult.innerHTML = '<div class="alert alert-danger">' + (result.message || 'Generate gagal.') + '</div>';
          }
        })
        .catch(function() {
          manualResult.innerHTML = '<div class="alert alert-danger">Terjadi kesalahan saat memproses generate invoice.</div>';
        })
        .finally(function() {
          manualBtn.disabled = false;
          manualBtn.textContent = 'Manual Generate Invoice';
        });
      });
    });
    </script>
</div>

      <?php



      $cekidpel = $_GET['idpel'] ?? '';
      $filter_nama = trim($_GET['nama'] ?? '');
      $start = trim($_GET['start_date'] ?? '');
      $end = trim($_GET['end_date'] ?? '');
      $filter_area = $_GET['area'] ?? '';
      $filter_sales = $_GET['sales'] ?? '';
      $filter_paket = $_GET['paket'] ?? '';
      $filter_periode_bulan = trim($_GET['periode_bulan'] ?? '');
      $filter_periode_tahun = trim($_GET['periode_tahun'] ?? '');
      $filter_metode_bayar = strtolower(trim($_GET['metode_bayar'] ?? ''));
      $filter_payment_method = trim($_GET['payment_method'] ?? '');
      $filter_bukti = trim($_GET['bukti'] ?? '');
      $filter_status = strtoupper(trim($_GET['status'] ?? ''));
      $manual_generate_message = '';
      $is_idpel_search = trim($cekidpel) !== '';

      // Jika semua filter kosong, otomatis set tanggal ke hari ini saja
      if (
        $start === '' && $end === '' &&
        $filter_periode_bulan === '' && $filter_periode_tahun === '' &&
        $cekidpel === '' && $filter_nama === '' && $filter_area === '' && $filter_sales === '' && $filter_paket === '' &&
        $filter_metode_bayar === '' && $filter_payment_method === '' && $filter_bukti === '' && $filter_status === ''
      ) {
        $today = date('Y-m-d');
        $start = $today;
        $end = $today;
      }

      $allowed_statuses = ['PENAGIHAN', 'PERMINTAAN KODE', 'KONFIRMASI', 'BERHASIL'];
      if (!in_array($filter_status, $allowed_statuses, true)) {
        $filter_status = '';
      }

      // Jika filter tanggal kosong, biarkan kosong agar query tetap jalan tanpa filter tanggal








      if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['manual_generate_invoice'])) {
          $generate_month = trim($_POST['generate_month'] ?? '');
          $generate_year = (int)($_POST['generate_year'] ?? 0);

          if (!in_array($generate_month, $bulan_penggunaan, true) || $generate_year < 2000 || $generate_year > 2100) {
            $manual_generate_message = '<div class="alert alert-danger mt-3">Periode penggunaan tidak valid.</div>';
          } else {
            $periode_penggunaan = $generate_month . ' ' . $generate_year;
            $periode_penggunaan_normalized = mb_strtoupper(trim($periode_penggunaan), 'UTF-8');

            // Manual generate ini KHUSUS pelanggan Fixed Due Date (mengikuti_tanggal_tempo) --
            // Monthversary & Rolling sudah tercakup jalur H-N otomatis (invoice_generator_penagihan.php)
            // + tombol "Generate Invoice" per-pelanggan (create_invoice_pelanggan.php) yang jatuh
            // temponya mengikuti anchor/histori bayar masing-masing, bukan periode kalender.
            //
            // TANGGALBAYAR dibangun dari periode yang dipilih + jatuh_tempo_hari (setting Fixed
            // Due Date di Payment Setting), BUKAN tanggal hari ini, supaya konsisten dgn tanggal
            // jatuh tempo yang sesungguhnya dipakai cek_tagihan_harian.php/tagihan_status_lib.php.
            $reminderFileGenerate = __DIR__ . '/notifbot/data/reminder-' . preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$ceknama) . '.json';
            $jatuhTempoHariGenerate = 25;
            if (is_file($reminderFileGenerate)) {
              $reminderDataGenerate = json_decode((string)file_get_contents($reminderFileGenerate), true);
              if (is_array($reminderDataGenerate) && isset($reminderDataGenerate[0]['jatuh_tempo'])) {
                $jtCandidate = (int)$reminderDataGenerate[0]['jatuh_tempo'];
                if ($jtCandidate >= 1 && $jtCandidate <= 31) {
                  $jatuhTempoHariGenerate = $jtCandidate;
                }
              }
            }
            $bulanKeGenerate = array_search($generate_month, $bulan_penggunaan, true) + 1;
            $daysInMonthGenerate = (int)date('t', strtotime(sprintf('%04d-%02d-01', $generate_year, $bulanKeGenerate)));
            $tanggal_generate = sprintf('%04d-%02d-%02d', $generate_year, $bulanKeGenerate, min($jatuhTempoHariGenerate, $daysInMonthGenerate));

            // Label PENGUNAAN ikut setting "Periode Tercatat" (Payment Setting ->
            // Konfigurasi Fixed Due Date) -- 'berjalan' (default) = sama dgn bulan
            // due date yang dipilih admin (perilaku selama ini, $periode_penggunaan
            // di atas), 'berikutnya' = +1 bulan dari situ. $tanggal_generate TETAP
            // dari $generate_month/$generate_year apa adanya, tidak ikut berubah.
            $periodeTercatatModeGenerate = tagihanLoadPeriodeTercatatMode($reminderFileGenerate);
            $periode_penggunaan = tagihanResolvePeriodeTercatat($bulanKeGenerate, $generate_year, $periodeTercatatModeGenerate);
            $periode_penggunaan_normalized = mb_strtoupper(trim($periode_penggunaan), 'UTF-8');

            // $userServerList sudah dihitung sekali di atas (scoping ASSISTANT-aware)
            // -- WAJIB dipakai di sini, sebelumnya assistant bisa generate/hapus
            // invoice PENAGIHAN Rp0 utk pelanggan di area lain milik owner yg sama.
            $server_list_generate = $userServerList;

            mysqli_query(
              $conn,
              "DELETE FROM transaksi WHERE PEMILIK IN ($server_list_generate) AND TRIM(UPPER(COALESCE(STATUS, '')))='PENAGIHAN' AND CAST(COALESCE(NULLIF(HARGA, ''), '0') AS DECIMAL(18,2)) <= 0"
            );

            $inserted_count = 0;
            $skipped_count = 0;

            $pelanggan_q = mysqli_query($conn, "SELECT IDPEL, NAMA, PAKET, PEMILIK, COALESCE(MODE, '') AS MODE, COALESCE(TIPE_TEMPO, '') AS TIPE_TEMPO FROM pelanggan WHERE IDPEL <> '' AND PEMILIK IN ($server_list_generate)");
            while ($pel = mysqli_fetch_assoc($pelanggan_q)) {
              $idpel_generate = $pel['IDPEL'];
              $nama_generate = $pel['NAMA'];
              $paket_generate = $pel['PAKET'];
              $pemilik_generate = $pel['PEMILIK'];
              $mode_generate = strtoupper(trim((string)$pel['MODE']));
              $tipeTempoGenerate = strtolower(trim((string)$pel['TIPE_TEMPO']));

              if (in_array($mode_generate, ['NONAKTIF', 'DISABLED', 'DISMANTLE', 'BERHENTI'], true)) {
                $skipped_count++;
                continue;
              }

              if (in_array($tipeTempoGenerate, ['monthversary', 'mengikuti_tanggal_bayar'], true)) {
                // Bukan Fixed Due Date -- dilewati, sudah tercakup jalur lain (lihat komentar di atas).
                $skipped_count++;
                continue;
              }

              $cek_exist_q = mysqli_query($conn, "SELECT id FROM transaksi WHERE IDPEL='" . mysqli_real_escape_string($conn, $idpel_generate) . "' AND PEMILIK='" . mysqli_real_escape_string($conn, $pemilik_generate) . "' AND TRIM(UPPER(COALESCE(PENGUNAAN, '')))='" . mysqli_real_escape_string($conn, $periode_penggunaan_normalized) . "' AND TRIM(UPPER(COALESCE(STATUS, ''))) IN ('PENAGIHAN','PERMINTAAN KODE','KONFIRMASI','BERHASIL') LIMIT 1");
              if ($cek_exist_q && mysqli_num_rows($cek_exist_q) > 0) {
                $skipped_count++;
                continue;
              }

              $harga_generate = reseller_effective_harga($conn, $paket_generate, $pemilik_generate);

              if ($harga_generate <= 0) {
                $skipped_count++;
                continue;
              }

              $bukti_ref = 'INV-PENAGIHAN-MANUAL-' . preg_replace('/[^A-Za-z0-9_-]/', '', $idpel_generate) . '-' . date('Ym');
              $cek_generate = 'MANUAL GENERATE INVOICE';
              $status_generate = 'PENAGIHAN';

              $insert_q = mysqli_query(
                $conn,
                "INSERT INTO transaksi (TANGGALBAYAR, PENGUNAAN, STATUS, IDPEL, NAMA, PAKET, HARGA, BUKTI, CEK, PEMILIK, METODE_BAYAR) VALUES ('" . mysqli_real_escape_string($conn, $tanggal_generate) . "', '" . mysqli_real_escape_string($conn, $periode_penggunaan) . "', '" . mysqli_real_escape_string($conn, $status_generate) . "', '" . mysqli_real_escape_string($conn, $idpel_generate) . "', '" . mysqli_real_escape_string($conn, $nama_generate) . "', '" . mysqli_real_escape_string($conn, $paket_generate) . "', " . (float)$harga_generate . ", '" . mysqli_real_escape_string($conn, $bukti_ref) . "', '" . mysqli_real_escape_string($conn, $cek_generate) . "', '" . mysqli_real_escape_string($conn, $pemilik_generate) . "', '')"
              );

              if ($insert_q) {
                $inserted_count++;
              } else {
                $skipped_count++;
              }
            }

            $manual_generate_message = '<div class="alert alert-success mt-3">Manual generate periode <strong>'
              . htmlspecialchars($periode_penggunaan)
              . '</strong> selesai. Inserted: <strong>' . (int)$inserted_count
              . '</strong>, Skipped: <strong>' . (int)$skipped_count
              . '</strong>.</div>';
          }
        } elseif (isset($_POST['id'])) {
          $id = mysqli_real_escape_string($conn, $_POST['id']);

          // WAJIB cek kepemilikan sebelum DELETE -- sebelum fix ini, endpoint
          // ini TIDAK ADA pengecekan sama sekali (IDOR): siapapun yang login
          // (termasuk assistant area lain) bisa hapus transaksi id manapun
          // cukup dgn tahu/tebak id-nya, tombol Delete di UI cuma disembunyikan
          // utk ASSISTANT (proteksi tampilan doang, bukan proteksi server-side).
          // $userServerList sudah dihitung sekali di atas (scoping ASSISTANT-aware).
          $sql = "DELETE FROM transaksi WHERE id = '$id' AND PEMILIK IN ($userServerList)";
          $result = mysqli_query($conn, $sql);

          if ($result && mysqli_affected_rows($conn) > 0) {
            echo "Berhasil menghapus data: $id";
          } else {
            echo "Gagal menghapus data: transaksi tidak ditemukan atau bukan milik Anda.";
          }
        }
      }

      if (!empty($manual_generate_message)) {
        echo $manual_generate_message;
      }
















      // $userServerList sudah dihitung sekali di atas (scoping ASSISTANT-aware).
      $server_list = $userServerList;

      // Build filter join and where
      $join_pelanggan = "LEFT JOIN pelanggan p ON TRIM(transaksi.IDPEL) = TRIM(p.IDPEL)";
      $where = [];
      if ($cekidpel != '') {
        $where[] = "TRIM(transaksi.IDPEL) = '" . mysqli_real_escape_string($conn, trim($cekidpel)) . "'";
        $where[] = "transaksi.pemilik IN ($server_list)";
      } else {
        $where[] = "transaksi.pemilik IN ($server_list)";
      }
      $tanggal_bayar_filter_sql = "COALESCE(
        DATE(transaksi.TANGGALBAYAR),
        STR_TO_DATE(transaksi.TANGGALBAYAR, '%Y-%m-%d'),
        STR_TO_DATE(TRIM(SUBSTRING_INDEX(transaksi.TANGGALBAYAR, ',', -1)), '%d %M %Y'),
        STR_TO_DATE(TRIM(SUBSTRING_INDEX(transaksi.TANGGALBAYAR, ',', -1)), '%d %b %Y'),
        STR_TO_DATE(
          TRIM(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
            SUBSTRING_INDEX(transaksi.TANGGALBAYAR, ',', -1),
            'Januari', '01'
          ), 'Februari', '02'), 'Maret', '03'), 'April', '04'), 'Mei', '05'), 'Juni', '06'), 'Juli', '07'), 'Agustus', '08'), 'September', '09'), 'Oktober', '10'), 'November', '11'), 'Desember', '12')),
          '%d %m %Y'
        )
      )";
      if (!$is_idpel_search && $start !== '' && $end !== '') {
        $where[] = "$tanggal_bayar_filter_sql BETWEEN '" . mysqli_real_escape_string($conn, $start) . "' AND '" . mysqli_real_escape_string($conn, $end) . "'";
      }
      if ($filter_status !== '') {
        $statusSafe = mysqli_real_escape_string($conn, $filter_status);
        $where[] = "(TRIM(UPPER(COALESCE(transaksi.STATUS, ''))) = '" . $statusSafe . "' OR TRIM(UPPER(COALESCE(transaksi.STATUS, ''))) LIKE '" . $statusSafe . " %')";
      } elseif (!$is_idpel_search) {
        $where[] = "transaksi.STATUS NOT LIKE 'PERMINTAAN KODE'";
      }
      if ($filter_area != '') {
        $where[] = "p.AREA = '" . mysqli_real_escape_string($conn, $filter_area) . "'";
      }
      if ($filter_sales != '') {
        $where[] = "p.sales = '" . mysqli_real_escape_string($conn, $filter_sales) . "'";
      }
      if ($filter_paket != '') {
        $where[] = "p.PAKET = '" . mysqli_real_escape_string($conn, $filter_paket) . "'";
      }
      if ($filter_nama !== '') {
        $where[] = "transaksi.NAMA LIKE '%" . mysqli_real_escape_string($conn, $filter_nama) . "%'";
      }
      if ($filter_periode_bulan !== '' && in_array($filter_periode_bulan, $bulan_penggunaan, true) && $filter_periode_tahun !== '' && preg_match('/^\d{4}$/', $filter_periode_tahun)) {
        $periode_penggunaan_filter = $filter_periode_bulan . ' ' . $filter_periode_tahun;
        $where[] = "TRIM(COALESCE(transaksi.PENGUNAAN, '')) LIKE '%" . mysqli_real_escape_string($conn, $periode_penggunaan_filter) . "'";
      } elseif ($filter_periode_bulan !== '' && in_array($filter_periode_bulan, $bulan_penggunaan, true)) {
        $where[] = "TRIM(COALESCE(transaksi.PENGUNAAN, '')) LIKE '" . mysqli_real_escape_string($conn, $filter_periode_bulan) . " %'";
      } elseif ($filter_periode_tahun !== '' && preg_match('/^\d{4}$/', $filter_periode_tahun)) {
        $where[] = "TRIM(COALESCE(transaksi.PENGUNAAN, '')) LIKE '% " . mysqli_real_escape_string($conn, $filter_periode_tahun) . "'";
      }
      if ($filter_metode_bayar === 'cash') {
        $where[] = "LOWER(COALESCE(transaksi.METODE_BAYAR, '')) = 'cash'";
      } elseif ($filter_metode_bayar === 'transfer') {
        $where[] = "LOWER(COALESCE(transaksi.METODE_BAYAR, '')) = 'transfer'";
      } elseif ($filter_metode_bayar === 'payment_gateway') {
        $where[] = "LOWER(COALESCE(transaksi.METODE_BAYAR, '')) NOT IN ('cash', 'transfer')";
      }
      if ($filter_payment_method !== '') {
        $where[] = "LOWER(COALESCE(transaksi.payment_method, '')) LIKE '%" . mysqli_real_escape_string($conn, strtolower($filter_payment_method)) . "%'";
      }
      if ($filter_bukti !== '') {
        $where[] = "transaksi.BUKTI LIKE '%" . mysqli_real_escape_string($conn, $filter_bukti) . "%'";
      }
      // p.TIPE_TEMPO/TANGGALPASANG/TANGGAL_MONTHVERSARY ikut diambil supaya baris
      // non-PENAGIHAN (mis. BERHASIL) juga bisa menampilkan badge "Jatuh tempo"
      // sesuai tipe tempo pelanggan, sama seperti riwayatTransaction.php.
      $sql = "SELECT transaksi.*, p.TIPE_TEMPO AS PEL_TIPE_TEMPO, p.TANGGALPASANG AS PEL_TANGGALPASANG, p.TANGGAL_MONTHVERSARY AS PEL_TANGGAL_MONTHVERSARY FROM transaksi $join_pelanggan WHERE " . implode(' AND ', $where) . " ORDER BY transaksi.id DESC";
      ?>

      <div style="margin-top: 30px;">
      <?php

      $query = mysqli_query($conn, $sql);

      $has_data = $query && mysqli_num_rows($query) > 0;

      // Fallback: jika pencarian IDPEL tidak ketemu karena filter tambahan,
      // jalankan ulang query khusus IDPEL (tanpa filter tanggal/status/area/sales/paket/metode).
      if (!$has_data && $is_idpel_search) {
        $idpelOnlyWhere = [];
        $idpelOnlyWhere[] = "TRIM(transaksi.IDPEL) = '" . mysqli_real_escape_string($conn, trim($cekidpel)) . "'";
        $idpelOnlyWhere[] = "transaksi.pemilik IN ($server_list)";
        $sql_idpel_only = "SELECT transaksi.*, p.TIPE_TEMPO AS PEL_TIPE_TEMPO, p.TANGGALPASANG AS PEL_TANGGALPASANG, p.TANGGAL_MONTHVERSARY AS PEL_TANGGAL_MONTHVERSARY FROM transaksi $join_pelanggan WHERE " . implode(' AND ', $idpelOnlyWhere) . " ORDER BY transaksi.id DESC";
        $query_idpel_only = mysqli_query($conn, $sql_idpel_only);

        if ($query_idpel_only && mysqli_num_rows($query_idpel_only) > 0) {
          echo '<div class="alert alert-warning">';
          echo 'Data ditemukan untuk IDPEL ini, tetapi tidak masuk filter tambahan (tanggal/status/area/sales/paket/metode bayar). Menampilkan hasil berdasarkan IDPEL saja.';
          echo '</div>';
          $query = $query_idpel_only;
          $has_data = true;
        }
      }
      if ($has_data) {
        // Hitung jumlah data
        $jumlah_data = mysqli_num_rows($query);
        echo '<div class="d-flex justify-content-center mb-3"><span class="badge bg-primary fs-5" style="padding:10px 20px; font-size:1.2em;">Jumlah data: ' . $jumlah_data . '</span></div>';
      ?>
        <?php
        // Build all filter params for export links
        $export_params = [
          'start_date' => $start,
          'end_date' => $end,
          'periode_bulan' => $filter_periode_bulan,
          'periode_tahun' => $filter_periode_tahun,
          'idpel' => $cekidpel,
          'area' => $filter_area,
          'sales' => $filter_sales,
          'paket' => $filter_paket,
          'metode_bayar' => $filter_metode_bayar,
          'payment_method' => $filter_payment_method,
          'bukti' => $filter_bukti,
          'status' => $filter_status,
          'nama' => $filter_nama
        ];
        $export_query = http_build_query($export_params);
        ?>
        <a href="export_pdf.php?<?= htmlspecialchars($export_query) ?>" class="btn btn-danger" target="_blank">Export PDF</a>
        <a href="export_excel.php?<?= htmlspecialchars($export_query) ?>" class="btn btn-success" target="_blank">Export Excel</a>

        <div class="d-inline-flex align-items-center gap-2 ms-2 p-2 border rounded" style="background:#fff3e0;">
          <div class="form-check mb-0">
            <input type="checkbox" class="form-check-input" id="trxSelectAllPenagihan" onchange="trxToggleSelectAllPenagihan(this)">
            <label class="form-check-label small" for="trxSelectAllPenagihan">Pilih Semua PENAGIHAN</label>
          </div>
          <span class="small text-muted">Terpilih: <span id="trxSelectedPenagihanCount">0</span></span>
          <button type="button" class="btn btn-sm btn-danger" id="trxBulkDeleteBtn" onclick="trxBulkDeletePenagihan()"><i class="fas fa-trash me-1"></i>Hapus Massal (PENAGIHAN)</button>
        </div>
        <div id="trxBulkDeleteStatus" class="mt-2" style="display:none;"></div>
      <?php
      }

      ?>

      <!-- Card-based transaction display -->
      <div class="row mt-4">
        <?php
        // Tanggal jatuh tempo yang DIPENUHI oleh transaksi ini sendiri (BUKAN jatuh
        // tempo berikutnya setelah bayar) -- ditampilkan sbg badge tambahan di baris
        // non-PENAGIHAN (mis. BERHASIL), supaya semua status transaksi tetap
        // menunjukkan jatuh tempo sesuai tipe tempo pelanggan, sama seperti baris
        // PENAGIHAN yang jatuh temponya sudah tampil lewat kolom TANGGAL BAYAR/JATUH
        // TEMPO. Fixed & Monthversary: hari (jatuh_tempo_hari/anchor) dipasang ke
        // BULAN pembayaran transaksi ini sendiri (bukan bulan PENGUNAAN, yang bisa
        // beda -- itu cuma label periode). Rolling: hasil simulasi siklus 30 hari
        // dari histori pembayaran SEBELUM baris ini (supaya aturan bayar cepat/telat
        // tetap dihormati -- BUKAN asal tanggal bayar transaksi ini + 30 hari, itu
        // jatuh tempo SIKLUS BERIKUTNYA, bukan yang dipenuhi transaksi ini).
        function transaksiHitungJatuhTempoBaris($conn, $tipeTempo, $jatuhTempoHariFixed, $anchorDayMonthversary, $idpelRow, $tanggalPasangRow, $waktuRow, $rowId, $statusRow) {
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
            $due = tagihanBuildMonthlyDate((int)date('Y', $tsPay), (int)date('n', $tsPay), $anchorDayMonthversary);
            return $due ? date('d-m-Y', strtotime($due)) : '';
          }
          if ($tipeTempo === 'mengikuti_tanggal_bayar') {
            $due = tagihanGetRollingDueDateForRow($conn, (string)$idpelRow, (string)$tanggalPasangRow, (int)$rowId);
            return $due ? date('d-m-Y', strtotime($due)) : '';
          }
          return '';
        }
        $jatuh_tempo_hari_cache_transaksi = [];
        if ($has_data) {
          while ($data = mysqli_fetch_array($query)) {
            $id = $data['id'];
            $idpel = $data['IDPEL'];
            $pemilik = $data['PEMILIK'];
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
              } elseif (strpos($bukti_value, 'manual_active/') === 0) {
                $bukti_image_url = 'uploads/' . $bukti_value;
              } elseif (strpos($bukti_value, 'buktibon/') === 0) {
                $bukti_image_url = '/dokumen/' . $bukti_value;
              } elseif (preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $bukti_value)) {
                $bukti_image_url = 'uploads/' . $bukti_value;
              }
            }
            $manual_active_by = $data['MANUAL_ACTIVE_BY'] ?? '-';
            
            // Get brand from pelanggan table based on IDPEL
            $brand_query = "SELECT BRAND FROM pelanggan WHERE IDPEL = '" . mysqli_real_escape_string($conn, $idpel) . "' LIMIT 1";
            $brand_result = mysqli_query($conn, $brand_query);
            $brand = '';
            
            if ($brand_result && mysqli_num_rows($brand_result) > 0) {
              $brand_data = mysqli_fetch_assoc($brand_result);
              $brand = $brand_data['BRAND'] ?? '';
            }
            
            // If brand is empty, fallback to PEMILIK
            if (empty($brand)) {
              $brand = $data['PEMILIK'];
            }

            // Tampilan "harga dasar" ikut menimpa dengan custom_harga reseller/mitra
            // (kalau filter harga aktif), sama seperti yang dipakai saat generate invoice.
            // Fallback ke nilai tersimpan kalau paket tidak ketemu (mis. sudah diganti nama/dihapus).
            $displayHarga = (float)$data['HARGA'];
            if (!empty($is_reseller) && !empty($reseller_price_filter_enabled)) {
              $effectiveHarga = reseller_effective_harga($conn, $data['PAKET'], $pemilik);
              if ($effectiveHarga > 0) {
                $displayHarga = $effectiveHarga;
              }
            }

            // Determine status badge color & label yang lebih jelas (biar admin tidak
            // bingung bedanya PENAGIHAN [belum dibayar] vs BERHASIL [sudah dibayar]).
            $status_badge_class = 'bg-warning';
            $status_badge_label = htmlspecialchars($data['STATUS']);
            if ($data['STATUS'] === 'BERHASIL') {
              $status_badge_class = 'bg-success';
            } elseif ($data['STATUS'] === 'PENAGIHAN') {
              $status_badge_class = 'bg-warning';
              $status_badge_label = 'PENAGIHAN (menunggu bayar)';
            } else {
              $status_badge_class = 'bg-secondary';
            }

            // Jatuh tempo per tipe tempo pelanggan, dihitung khusus utk transaksi ini
            // sendiri (bukan status "sekarang"). Fixed Due Date pakai jatuh_tempo dari
            // Payment Setting -> Konfigurasi Fixed Due Date (reminder-{PEMILIK}.json,
            // dicache per PEMILIK); Monthversary pakai anchor TANGGAL_MONTHVERSARY
            // (fallback TANGGALPASANG); Rolling pakai digit hasil simulasi histori
            // pembayaran sebelum transaksi ini (lihat transaksiHitungJatuhTempoBaris()).
            $tipeTempoRow = strtolower(trim((string)($data['PEL_TIPE_TEMPO'] ?? '')));
            $anchorDayMonthversaryRow = 25;
            if ($tipeTempoRow === 'monthversary') {
              $anchorDateRow = (string)($data['PEL_TANGGAL_MONTHVERSARY'] ?? '');
              if ($anchorDateRow === '' || strtotime($anchorDateRow) === false) {
                $anchorDateRow = (string)($data['PEL_TANGGALPASANG'] ?? '');
              }
              if ($anchorDateRow !== '' && strtotime($anchorDateRow) !== false) {
                $anchorDayMonthversaryRow = (int)date('j', strtotime($anchorDateRow));
              }
            }
            // PENTING: setting "jatuh_tempo" (Payment Setting -> Konfigurasi Fixed
            // Due Date) disimpan per AKUN CRM (reminder-{$ceknama}.json, lihat
            // paymentset.php), BUKAN per PEMILIK server/router (transaksi.PEMILIK
            // bisa beda dari username akun -- lihat komentar $struk_settings_username
            // di atas). Salah pakai $pemilik di sini bikin file-nya tidak pernah
            // ketemu (fallback diam-diam ke default 25) utk akun yang PEMILIK
            // server-nya beda dari username login.
            $jatuhTempoHariFixedRow = 25;
            if ($tipeTempoRow === 'mengikuti_tanggal_tempo') {
              if (!isset($jatuh_tempo_hari_cache_transaksi[$ceknama])) {
                $jatuhTempoValueRow = 25;
                $reminderFileRow = __DIR__ . '/notifbot/data/reminder-' . $ceknama . '.json';
                if (file_exists($reminderFileRow)) {
                  $reminderCfgRow = json_decode(file_get_contents($reminderFileRow), true);
                  if (is_array($reminderCfgRow) && isset($reminderCfgRow[0]['jatuh_tempo'])) {
                    $jatuhTempoValueRow = (int)$reminderCfgRow[0]['jatuh_tempo'];
                  }
                }
                $jatuh_tempo_hari_cache_transaksi[$ceknama] = max(1, min(31, $jatuhTempoValueRow));
              }
              $jatuhTempoHariFixedRow = $jatuh_tempo_hari_cache_transaksi[$ceknama];
            }
            $transaksiJatuhTempoBaris = transaksiHitungJatuhTempoBaris($conn, $tipeTempoRow, $jatuhTempoHariFixedRow, $anchorDayMonthversaryRow, $idpel, (string)($data['PEL_TANGGALPASANG'] ?? ''), $data['waktu'] ?? '', $data['id'] ?? 0, $data['STATUS'] ?? '');

            // Label tipe tempo pelanggan, ditampilkan apa adanya di tiap transaksi
            // supaya admin langsung tahu pelanggan ini masuk mode Fixed/Rolling/Monthversary
            // tanpa perlu buka halaman pelanggan terpisah.
            $tipeTempoLabelMap = [
              'mengikuti_tanggal_tempo' => 'Fixed Due Date',
              'mengikuti_tanggal_bayar' => 'Rolling',
              'monthversary' => 'Monthversary',
            ];
            $tipeTempoLabelRow = $tipeTempoLabelMap[$tipeTempoRow] ?? '-';
        ?>
          <div class="col-md-12 mb-4">
            <div class="card shadow-sm border-start border-4" style="border-color: #ff9800 !important;">
              <div class="card-body p-3">
                <!-- Header Row: Badge and Timestamp -->
                <div class="d-flex justify-content-between align-items-start mb-3">
                  <div>
                    <?php if ($data['STATUS'] === 'PENAGIHAN') { ?>
                      <input type="checkbox" class="form-check-input trx-penagihan-checkbox me-2" value="<?php echo (int)$id; ?>" style="width:1.2em;height:1.2em;vertical-align:middle;">
                    <?php } ?>
                    <span class="badge bg-warning text-dark me-2">TRANSAKSI</span>
                    <span class="badge <?php echo $status_badge_class; ?> text-white"><?php echo $status_badge_label; ?></span>
                  </div>
                  <small class="text-muted"><?php echo date('Y-m-d H:i:s', strtotime($data['TANGGALBAYAR'])); ?></small>
                </div>

                <!-- Row 1: Tanggal Bayar/Jatuh Tempo, Pengunaan, IDPEL, Nama -->
                <div class="row mb-3">
                  <div class="col-md-3 mb-2 mb-md-0">
                    <small class="text-muted d-block"><?php echo $data['STATUS'] === 'PENAGIHAN' ? 'JATUH TEMPO' : 'TANGGAL BAYAR'; ?></small>
                    <strong><?php echo htmlspecialchars($data['TANGGALBAYAR']); ?></strong>
                    <?php if ($transaksiJatuhTempoBaris !== '') { ?>
                      <span class="badge bg-info text-dark d-block mt-1" style="width:fit-content;">Jatuh tempo: <?php echo htmlspecialchars($transaksiJatuhTempoBaris); ?></span>
                    <?php } ?>
                    <span class="badge bg-secondary d-block mt-1" style="width:fit-content;">Tipe Tempo: <?php echo htmlspecialchars($tipeTempoLabelRow); ?></span>
                  </div>
                  <div class="col-md-3 mb-2 mb-md-0">
                    <small class="text-muted d-block">PENGUNAAN</small>
                    <strong><?php echo htmlspecialchars($data['PENGUNAAN']); ?></strong>
                  </div>
                  <div class="col-md-3 mb-2 mb-md-0">
                    <small class="text-muted d-block">IDPEL</small>
                    <strong><?php echo htmlspecialchars($idpel); ?></strong>
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
                    <?php
                      // Toggle "btn_trx_lihat_paket" (Pengaturan User > Tombol Individual
                      // ASSISTANT): master switch terpisah dari "Hak Akses Paket (Katalog)" --
                      // kalau dimatikan, SEMUA nama paket disembunyikan di Transaksi & Export
                      // untuk assistant ini, apapun status hidden per-nama-paketnya.
                      $bisa_lihat_paket_trx = ($AKSES !== 'ASSISTANT') || !empty($ui_visibility_settings['btn_trx_lihat_paket']);
                      $paket_disembunyikan_trx = !$bisa_lihat_paket_trx || paketVisibilityIsHidden((string)$data['PAKET'], $assistant_hidden_paket_broadband ?? []);
                    ?>
                    <?php if ($paket_disembunyikan_trx): ?>
                      <strong>-</strong>
                    <?php else: ?>
                      <strong><?php echo htmlspecialchars($data['PAKET']); ?></strong>
                    <?php endif; ?>
                  </div>
                  <div class="col-md-4 mb-2 mb-md-0">
                    <small class="text-muted d-block">harga dasar ( termasuk PPN tergantung pengaturan )</small>
                    <strong class="text-primary">Rp <?php echo number_format($displayHarga, 0, ',', '.'); ?></strong>
                  </div>
                  <div class="col-md-4">
                    <small class="text-muted d-block">PEMILIK</small>
                    <strong><?php echo htmlspecialchars($brand); ?></strong>
                  </div>
                </div>

                <!-- Row 3: Metode Bayar, Bukti, CEK -->
                <div class="row mb-3">
                  <div class="col-md-4 mb-2 mb-md-0">
                    <small class="text-muted d-block">METODE BAYAR</small>
                    <strong><?php echo htmlspecialchars($metode_bayar_label); ?></strong>
                  </div>
                  <div class="col-md-4 mb-2 mb-md-0">
                    <small class="text-muted d-block">BUKTI / KODE REF / KODE TRX</small>
                    <strong><?php echo htmlspecialchars($bukti_value); ?></strong>
                  </div>
                  <div class="col-md-4">
                    <small class="text-muted d-block">CEK</small>
                    <strong><?php echo htmlspecialchars($data['CEK'] ?? 'N/A'); ?></strong>
                  </div>
                </div>

                <!-- Row 4: Additional Details (Payment Method, Fees) -->
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

                <!-- Image and Actions Row -->
                <div class="row">
                  <?php
                  // Toggle "btn_trx_lihat_bukti" (Pengaturan User > Tombol Individual ASSISTANT):
                  // owner/admin selalu bisa lihat, ASSISTANT hanya kalau toggle-nya aktif. Dicek
                  // server-side (bukan cuma sembunyikan lewat JS) karena ini foto bukti transfer,
                  // data sensitif -- kalau cuma display:none, URL & isi foto tetap ada di HTML.
                  $bisa_lihat_bukti_trx = ($AKSES !== 'ASSISTANT') || !empty($ui_visibility_settings['btn_trx_lihat_bukti']);
                  ?>
                  <?php if ($bisa_lihat_bukti_trx && ($metode_bayar_raw === 'cash' || $metode_bayar_raw === 'transfer' || $metode_bayar_raw === 'manual') && !empty($bukti_image_url)) { ?>
                    <div class="col-md-3 mb-3 mb-md-0">
                      <img src="<?php echo htmlspecialchars($bukti_image_url); ?>" alt="Bukti Pembayaran" style="max-width: 100%; max-height: 150px; border: 1px solid #ddd; border-radius: 6px;" onerror="this.style.display='none'">
                    </div>
                  <?php } ?>
                  <div class="col-md-9">
                    <div class="d-flex gap-2 flex-wrap">
                      <?php if (!empty($struk_settings['print_enabled'])) { ?>
                        <a href="print_struk.php?id=<?php echo $id; ?>" class="btn btn-sm btn-outline-primary" target="_blank">
                          <i class="bi bi-printer"></i> Print Struk
                        </a>
                      <?php } ?>
                      <?php if (!empty($struk_settings['pdf_enabled'])) { ?>
                        <a href="print_card.php?id=<?php echo $id; ?>" class="btn btn-sm btn-primary" target="_blank">
                          <i class="bi bi-download"></i> Download PDF
                        </a>
                      <?php } ?>
                      <?php if($AKSES != 'ASSISTANT') { ?>
                        <form method="post" onsubmit="return confirm('Yakin ingin menghapus data ini?');" style="display:inline;">
                          <input type="hidden" name="id" value="<?php echo $id; ?>">
                          <button type="submit" class="btn btn-sm btn-danger">
                            <i class="bi bi-trash"></i> Delete
                          </button>
                        </form>
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
          echo '<div class="col-12"><div class="alert alert-info">Data tidak ditemukan.</div></div>';
        }
        ?>
      </div>
  </div>

<script>
function trxGetPenagihanCheckboxes() {
    return Array.from(document.querySelectorAll('.trx-penagihan-checkbox'));
}

function trxUpdateSelectedCount() {
    const boxes = trxGetPenagihanCheckboxes();
    const selected = boxes.filter(function (b) { return b.checked; });
    const countEl = document.getElementById('trxSelectedPenagihanCount');
    if (countEl) {
        countEl.textContent = selected.length;
    }
    const selectAll = document.getElementById('trxSelectAllPenagihan');
    if (selectAll) {
        selectAll.checked = boxes.length > 0 && selected.length === boxes.length;
    }
}

function trxToggleSelectAllPenagihan(checkbox) {
    trxGetPenagihanCheckboxes().forEach(function (b) { b.checked = checkbox.checked; });
    trxUpdateSelectedCount();
}

document.addEventListener('change', function (e) {
    if (e.target && e.target.classList.contains('trx-penagihan-checkbox')) {
        trxUpdateSelectedCount();
    }
});
document.addEventListener('DOMContentLoaded', trxUpdateSelectedCount);

async function trxBulkDeletePenagihan() {
    const selected = trxGetPenagihanCheckboxes().filter(function (b) { return b.checked; }).map(function (b) { return b.value; });
    if (selected.length === 0) {
        alert('Pilih minimal satu transaksi PENAGIHAN untuk dihapus.');
        return;
    }
    if (!confirm('Hapus ' + selected.length + ' transaksi PENAGIHAN terpilih? Tindakan ini tidak bisa dibatalkan.')) {
        return;
    }

    const btn = document.getElementById('trxBulkDeleteBtn');
    const statusBox = document.getElementById('trxBulkDeleteStatus');
    btn.disabled = true;
    statusBox.style.display = 'block';
    statusBox.className = 'alert alert-info mt-2';
    statusBox.textContent = 'Menghapus ' + selected.length + ' transaksi...';

    try {
        const response = await fetch('proses/bulk_hapus_transaksi_penagihan.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: 'ids=' + encodeURIComponent(selected.join(',')),
            cache: 'no-store'
        });
        const text = await response.text();
        let result;
        try {
            result = JSON.parse(text);
        } catch (e) {
            statusBox.className = 'alert alert-danger mt-2';
            statusBox.textContent = 'Server mengembalikan response bukan JSON (kemungkinan error PHP).';
            return;
        }

        if (!result.success) {
            statusBox.className = 'alert alert-danger mt-2';
            statusBox.textContent = result.message || 'Gagal menghapus.';
            return;
        }

        statusBox.className = 'alert alert-success mt-2';
        statusBox.textContent = 'Selesai. Berhasil dihapus: ' + (result.total_deleted || 0) + (result.total_failed ? (' | Gagal: ' + result.total_failed) : '');

        (result.deleted || []).forEach(function (id) {
            const cb = document.querySelector('.trx-penagihan-checkbox[value="' + id + '"]');
            const card = cb ? cb.closest('.col-md-12.mb-4') : null;
            if (card) {
                card.remove();
            }
        });
        trxUpdateSelectedCount();
    } catch (err) {
        statusBox.className = 'alert alert-danger mt-2';
        statusBox.textContent = 'Gagal menghubungi server: ' + err.message;
    } finally {
        btn.disabled = false;
    }
}
</script>









<?php require 'footer.php'; ?>
