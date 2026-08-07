
<?php require 'header.php';
if ($AKSES == 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('Dasbor', $akses_menu, true)) {
        echo '<div class="container-fluid py-4"><div class="alert alert-danger">Anda tidak memiliki akses ke menu Dasbor.</div></div>';
        require 'footer.php';
        exit;
    }
}

require_once __DIR__ . '/libs/menunggak_payment_lookup.php';


$userServers = [];
$userAreas = [];
$queryServer = mysqli_query($conn, "SELECT PEMILIK, AREA FROM server WHERE user_id = $current_user_id");
while($row = mysqli_fetch_assoc($queryServer)) {
  $userServers[] = $row['PEMILIK'];
  if (!empty($row['AREA'])) $userAreas[] = $row['AREA'];
}
$userServerList = count($userServers) > 0 ? "'" . implode("','", array_map('addslashes', $userServers)) . "'" : "''";
$userAreaList = count($userAreas) > 0 ? "'" . implode("','", array_map('addslashes', $userAreas)) . "'" : "''";

$dashboardCardDefaults = [
  'quick_actions' => true,
  'chart_pembayaran' => true,
  'transaksi_harian' => true,
  'statistik_pembayaran' => true,
  'infrastruktur_ringkasan' => true,
  'tabel_user_pppoe' => true,
  'tabel_user_hotspot' => true,
  'log_mikrotik' => true,
  'map_view' => true,
];

function loadDashboardCardSettings($username, $defaults)
{
  $safeUsername = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$username);
  if ($safeUsername === '' || !is_array($defaults)) {
    return $defaults;
  }

  $file = __DIR__ . '/settings/dashboard-cards-' . $safeUsername . '.json';
  if (!is_file($file)) {
    return $defaults;
  }

  $raw = @file_get_contents($file);
  $decoded = json_decode((string)$raw, true);
  if (!is_array($decoded)) {
    return $defaults;
  }

  $result = $defaults;
  foreach ($defaults as $key => $defaultVal) {
    if (array_key_exists($key, $decoded)) {
      $result[$key] = (bool)$decoded[$key];
    }
  }
  return $result;
}

$dashboardSettingsUsername = ($AKSES == 'ASSISTANT' && !empty($asistant_name)) ? $asistant_name : $ceknama;
$dashboardCardSettings = loadDashboardCardSettings($dashboardSettingsUsername, $dashboardCardDefaults);

function getInternetStatusRows($conn, $userServerList, $areaFilter, $filename, $type)
{
  $allowedTypes = ['total_users', 'internet_online', 'internet_los', 'expired_online', 'expired_los'];
  if (!in_array($type, $allowedTypes, true)) {
    return [];
  }

  $expired_ids = [];
  $los_ids = [];
  $offline_non_expired_ids = [];

  if (is_file($filename)) {
    $json_data = @file_get_contents($filename);
    $decoded = json_decode((string)$json_data, true);
    if (is_array($decoded)) {
      $expired_ids = array_values(array_unique(array_filter(array_map('strval', $decoded['expired_ids'] ?? []))));
      $los_ids = array_values(array_unique(array_filter(array_map('strval', $decoded['los_ids'] ?? []))));
      $offline_non_expired_ids = array_values(array_unique(array_filter(array_map('strval', $decoded['offline_non_expired_ids'] ?? []))));
    }
  }

  $expired_lookup = array_flip($expired_ids);
  $los_lookup = array_flip($los_ids);
  $offline_non_expired_lookup = array_flip($offline_non_expired_ids);

  $rows = [];
  $sql = "SELECT IDPEL, NAMA, PAKET, AREA, ODP, NOWA, ALAMAT FROM pelanggan WHERE PEMILIK IN ($userServerList) AND AREA IN ($areaFilter) ORDER BY NAMA";
  $result = mysqli_query($conn, $sql);
  while ($result && ($row = mysqli_fetch_assoc($result))) {
    $idpel = (string)($row['IDPEL'] ?? '');
    if ($idpel === '') {
      continue;
    }

    $isExpired = isset($expired_lookup[$idpel]);
    $isLos = isset($los_lookup[$idpel]);
    $isOfflineNonExpired = isset($offline_non_expired_lookup[$idpel]);

    $isMatch = false;
    if ($type === 'total_users') {
      $isMatch = true;
    } elseif ($type === 'internet_online') {
      $isMatch = (!$isExpired && !$isLos);
    } elseif ($type === 'internet_los') {
      $isMatch = $isOfflineNonExpired;
    } elseif ($type === 'expired_online') {
      $isMatch = ($isExpired && !$isLos);
    } elseif ($type === 'expired_los') {
      $isMatch = ($isExpired && $isLos);
    }

    if ($isMatch) {
      $rows[] = $row;
    }
  }

  return $rows;
}

$internetStatusCacheFile = "serverlog/" . ($AKSES == 'ASSISTANT' ? $asistant_name : $ceknama) . ".txt";
$internetStatusAreaFilter = (!empty($asistant_name)) ? $area_list : $userAreaList;

if (isset($_GET['ajax']) && $_GET['ajax'] === 'internet_status') {
  header('Content-Type: application/json; charset=utf-8');
  $statusType = isset($_GET['type']) ? strtolower(trim((string)$_GET['type'])) : '';
  $rows = getInternetStatusRows($conn, $userServerList, $internetStatusAreaFilter, $internetStatusCacheFile, $statusType);
  echo json_encode([
    'success' => true,
    'type' => $statusType,
    'data' => $rows,
  ]);
  exit;
}

// Cache control meta tags for performance optimization
?>
<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
<meta http-equiv="Pragma" content="no-cache">
<meta http-equiv="Expires" content="0">
<script>
  window.dashboardCardVisibility = <?php echo json_encode($dashboardCardSettings, JSON_UNESCAPED_UNICODE); ?>;
  document.addEventListener('DOMContentLoaded', function() {
    const visibility = (window.dashboardCardVisibility && typeof window.dashboardCardVisibility === 'object')
      ? window.dashboardCardVisibility
      : {};

    document.querySelectorAll('[data-dashboard-card]').forEach(function(el) {
      const key = el.getAttribute('data-dashboard-card');
      if (!key) return;
      if (Object.prototype.hasOwnProperty.call(visibility, key) && visibility[key] === false) {
        el.style.display = 'none';
      }
    });
  });
</script>
<?php








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
  ?>


<!-- Modal -->
<div class="modal fade" id="addCustomerModal" tabindex="-1" aria-labelledby="addCustomerModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
       <!-- Header hitam di atas -->
      <div class="bg-primary text-white text-center p-3">
        <h4 class="m-0 text-white">Add Customer PPPOE</h4>
      </div>
      <div class="modal-body p-0">
        <iframe id="customerIframe" src="addcustomerform.php" width="100%" height="500px" frameborder="0"></iframe>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-danger" data-bs-dismiss="modal">Tutup</button>
      </div>
    </div>
  </div>
</div>


<!-- Script -->
<script>
document.addEventListener("DOMContentLoaded", function() {
  const iframe = document.getElementById("customerIframe");

  iframe.addEventListener("load", function() {
    try {
      const url = iframe.contentWindow.location.href;

      // Jika iframe sudah memuat halaman tables.php
      if (url.includes("tables.php?pesan=berhasil&text=Success")) {
        // Tutup modal
        const modalEl = document.getElementById("addCustomerModal");
        const modal = bootstrap.Modal.getInstance(modalEl);
        if (modal) modal.hide();

        // Redirect parent ke tables.php
        setTimeout(() => {
          window.location.href = "tables.php?pesan=berhasil&text=Success";
        }, 300);
      }
    } catch (err) {
      console.warn("Tidak dapat membaca URL iframe:", err);
    }
  });
});
</script>
  <div class="container-fluid mt-4 px-3 px-md-4">

<?php if (!empty($asistant_name)) : ?>
  <div class="card shadow-sm border-0 mb-2">
    <div class="card-body d-flex justify-content-between align-items-start flex-wrap gap-2 py-2 px-3 small">
      <div class="me-2">
        <strong>Asisten:</strong>
        <span class="text-success ms-1\"><?= htmlspecialchars($asistant_name); ?></span>
      </div>

      <?php if (!empty($arealist)) : ?>
        <div class="text-md-end text-start">
          <strong class="d-block">Area:</strong>
          <ul class="text-primary mb-0 mt-1 ps-3 d-inline-block text-start small">
            <?php $area_items = is_array($arealist) ? $arealist : array_map('trim', explode(',', (string)$arealist)); ?>
            <?php foreach ($area_items as $area_item) : ?>
              <?php if ($area_item === '') continue; ?>
              <li class="lh-sm"><?= htmlspecialchars($area_item); ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>




<style>
  .dashboard-quick-actions {
    border: 1px solid rgba(148, 163, 184, 0.18);
    border-radius: 16px;
    overflow: hidden;
  }

  .dashboard-quick-actions .card-body {
    padding: 1rem 1.25rem;
  }

  .dashboard-quick-actions-toolbar {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.75rem;
    margin-bottom: 1rem;
  }

  .dashboard-quick-action-btn {
    width: 100%;
    height: 46px;
    padding: 0.65rem 1rem;
    border-radius: 12px;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 8px 18px rgba(37, 99, 235, 0.16);
    text-align: center;
  }

  .dashboard-search-form {
    margin-bottom: 0;
  }

  .dashboard-search-form .input-group {
    display: flex;
    align-items: stretch;
    flex-wrap: nowrap;
  }

  .dashboard-search-form .form-control {
    height: 56px;
    border-radius: 14px 0 0 14px;
    padding: 0.875rem 1rem;
    border-right: none;
  }

  .dashboard-search-form .btn {
    width: 190px;
    height: 56px;
    border-radius: 0 14px 14px 0;
    font-weight: 700;
    padding: 0.875rem 1rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 190px;
  }

  body.app-theme-dark .dashboard-quick-actions {
    background: #111827 !important;
    border-color: rgba(59, 130, 246, 0.16) !important;
  }

  body.app-theme-dark .dashboard-search-form .form-control {
    background: #0f172a !important;
    border-color: #334155 !important;
    color: #e5e7eb !important;
  }

  body.app-theme-dark .dashboard-search-form .form-control::placeholder {
    color: #94a3b8 !important;
  }

  body.app-theme-dark .dashboard-search-form .btn-warning {
    color: #0f172a !important;
  }

  @media (max-width: 768px) {
    .dashboard-quick-actions .card-body {
      padding: 0.9rem;
    }

    .dashboard-quick-actions-toolbar {
      grid-template-columns: 1fr;
      gap: 0.6rem;
      margin-bottom: 0.85rem;
    }

    .dashboard-quick-action-btn {
      min-width: 0;
    }

    .dashboard-search-form .input-group {
      flex-direction: column;
      gap: 0.65rem;
    }

    .dashboard-search-form .form-control,
    .dashboard-search-form .btn {
      width: 100%;
      border-radius: 12px;
      min-width: 0;
      flex: 1 1 auto;
    }

    .dashboard-search-form .form-control {
      border-right: 1px solid var(--bs-border-color, #dee2e6);
      height: 50px;
    }

    .dashboard-search-form .btn {
      height: 50px;
    }
  }
</style>


<div class="card dashboard-quick-actions shadow-sm" data-dashboard-card="quick_actions">
  <div class="card-body">
    <div class="dashboard-quick-actions-toolbar">
      <a type="button" class="btn btn-primary dashboard-quick-action-btn" href="vouchergenerator.php">
        Generate Voucher Hotspot
      </a>
      <button type="button" class="btn btn-primary dashboard-quick-action-btn" data-bs-toggle="modal" data-bs-target="#addCustomerModal">
        Add Customer PPPOE
      </button>
    </div>

     <form method="POST" action="tables.php" class="dashboard-search-form" id="form-dashboard-search">
                        <input type="hidden" name="action" value="cari_global">
                        <div class="input-group">
                          <input type="text" name="cariglobal" id="dashboard-search-input" class="form-control input-custom-height" placeholder="Cari ID / Nama / ODP / Paket / No WA / Alamat" required>
                          <button type="submit" class="btn btn-warning input-custom-height">Cari Pelanggan</button>
                        </div>
                     </form>
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const globalSearchInput = document.getElementById('dashboard-search-input');
      if (!globalSearchInput) return;

      globalSearchInput.addEventListener('input', function() {
        this.value = this.value.replace(/^\s+/, '');
      });

      globalSearchInput.addEventListener('blur', function() {
        this.value = this.value.replace(/\s+$/, '');
      });

      const globalSearchForm = document.getElementById('form-dashboard-search');
      if (globalSearchForm) {
        globalSearchForm.addEventListener('submit', function() {
          globalSearchInput.value = globalSearchInput.value.trim();
        });
      }
    });
  </script>
  </div>
</div>


    <br>
 

    <div class="row"> 

    

  
      <!-- Card kiri - col-8 -->
      <div class="col-md-8 mb-4" data-dashboard-card="chart_pembayaran">
        <div class="card shadow h-100">
          <div class="card-header theme-aware-header">
            <h6 class="mb-0">📊 Grafik Pembayaran Prabayar Sesuai Periode Penggunaan</h6>
          </div>
          <div class="card-body">
            
            <form id="tahunForm" class="mb-3">
              <label for="tahun">🗓️ Pilih Tahun:</label>
              <input class="form-control mb-2" type="number" id="tahun" name="tahun" min="1999" max="<?= date('Y') ?>" value="<?= $tahun ?>" placeholder="<?= date('Y') ?>">
              <div class="d-grid gap-2 d-md-block">
                <button type="submit" class="btn btn-primary btn-sm">Tampilkan Data</button>
                <!-- <a id="exportTransaksi" class="btn btn-warning btn-sm">Export PDF Transaksi</a>
                <a href="printdatapelanggan.php?pemilik=<?= $username ?>" class="btn btn-warning btn-sm">Export PDF Pelanggan</a> -->
              </div>
            </form>

         
<!-- ============ 1. GANTI BAGIAN HTML INI ============ -->
<div style="height: 250px; position: relative;">
  <canvas id="transaksiChart" style="display:none;"></canvas>
 
  <div id="transaksiChartLoading" style="display:none; text-align:center; padding-top: 80px;">
    <div class="spinner-border text-primary" role="status">
      <span class="visually-hidden">Loading...</span>
    </div>
    <div data-loading-text>Memuat data...</div>
  </div>
 
  <p id="transaksiChartError"
     class="text-danger text-center"
     style="display:none; padding-top: 100px;">
  </p>
</div>
 
 
<!-- ============ 2. GANTI BAGIAN SCRIPT INI ============ -->
<script>
  let chart;
  let isChartLoading = false;
 
  function showChartLoading(tahun) {
    document.getElementById('transaksiChart').style.display = 'none';
    document.getElementById('transaksiChartError').style.display = 'none';
 
    const loadingEl = document.getElementById('transaksiChartLoading');
    loadingEl.style.display = 'block';
    loadingEl.querySelector('[data-loading-text]').textContent = `Memuat data tahun ${tahun}...`;
  }
 
  function showChartCanvas() {
    document.getElementById('transaksiChartLoading').style.display = 'none';
    document.getElementById('transaksiChartError').style.display = 'none';
    document.getElementById('transaksiChart').style.display = 'block';
  }
 
  function showChartError(message) {
    document.getElementById('transaksiChart').style.display = 'none';
    document.getElementById('transaksiChartLoading').style.display = 'none';
 
    const errorEl = document.getElementById('transaksiChartError');
    errorEl.style.display = 'block';
    errorEl.textContent = message;
  }
 
  function updateChart(tahun) {
    if (isChartLoading) return;
    isChartLoading = true;
    showChartLoading(tahun);
 
    fetch(`getdata/get_chart_data.php?tahun=${tahun}`)
      .then(response => {
        if (!response.ok) throw new Error('HTTP ' + response.status);
        return response.json();
      })
      .then(data => {
        if (!Array.isArray(data)) {
          throw new Error('Format data tidak valid dari server');
        }
 
        const labels = data.map(item => item.bulan);
        const values = data.map(item => item.jumlah_transaksi);
        const harga = data.map(item => item.harga);
 
        // Canvas ini sudah ada sejak awal dan TIDAK PERNAH dihapus dari DOM.
        const canvasEl = document.getElementById('transaksiChart');
 
        if (chart) {
          chart.destroy();
          chart = null;
        }
 
        showChartCanvas();
 
        const ctx = canvasEl.getContext('2d');
        chart = new Chart(ctx, {
          type: 'bar',
          data: {
            labels: labels,
            datasets: [
              {
                label: 'Jumlah Transaksi',
                data: values,
                backgroundColor: 'rgba(75, 192, 192, 0.6)'
              },
              {
                label: 'Total Pemasukan (Rp)',
                data: harga,
                backgroundColor: 'rgba(54, 162, 235, 0.6)'
              }
            ]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
              x: {
                ticks: {
                  autoSkip: false,
                  maxRotation: 45,
                  minRotation: 45
                }
              },
              y: {
                beginAtZero: true
              }
            }
          }
        });
      })
      .catch(err => {
        console.error('Gagal memuat data chart:', err);
        showChartError('Gagal memuat data grafik. Silakan coba lagi.');
      })
      .finally(() => {
        isChartLoading = false;
      });
 
    // Update link export HANYA jika elemennya ada (aman terhadap null)
    const exportBtn = document.getElementById('exportTransaksi');
    if (exportBtn) {
      exportBtn.href = `printtransaksi.php?tahun=${tahun}&pemilik=<?= $username ?>`;
    }
  }
 
  document.getElementById('tahunForm').addEventListener('submit', function (e) {
    e.preventDefault();
    const tahun = document.getElementById('tahun').value;
    updateChart(tahun);
  });
 
  // Defer chart initialization untuk improve page load
  document.addEventListener('DOMContentLoaded', function () {
    setTimeout(() => updateChart(<?= json_encode($tahun) ?>), 500);
  });
</script>
         

          </div>
        </div>
      </div>




      <style>
        .daily-container {
          height: 400px;
          /* fix height */
          overflow-y: auto;
          overflow-x: hidden;
          /* opsional: untuk hindari scroll horizontal */
        }

        .theme-aware-header {
          background: #f8fafc !important;
          color: #1e293b !important;
          border-bottom: 1px solid #e2e8f0 !important;
        }

        .theme-aware-header h1,
        .theme-aware-header h2,
        .theme-aware-header h3,
        .theme-aware-header h4,
        .theme-aware-header h5,
        .theme-aware-header h6,
        .theme-aware-header p,
        .theme-aware-header span,
        .theme-aware-header small,
        .theme-aware-header strong {
          color: inherit !important;
        }

        body.app-theme-dark .theme-aware-header {
          background: #1f2937 !important;
          color: #e5e7eb !important;
          border-bottom: 1px solid rgba(148, 163, 184, 0.22) !important;
        }
      </style>
      <!-- Card kanan - col-4 -->
      <div class="col-md-4 mb-4" data-dashboard-card="transaksi_harian">
        <div class="card shadow h-100">
          <div class="card-header theme-aware-header">
            <h6 class="mb-0">📅 Transaksi Harian</h6>
            <p class="text-sm mb-0 mt-1">
              <i class="fa fa-arrow-up text-success" aria-hidden="true"></i> Aktivitas terbaru
            </p>
          </div>
          <div class="card-body daily-container" id="dailyTransactionContainer">
            <div style="text-align:center;">
              <div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div>
              <div>Memuat transaksi...</div>
            </div>
          </div>
        </div>
      </div>

      <script>
        function formatRupiah(angka) {
          return 'Rp.' + angka.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ".");
        }

       function loadDailyTransaction() {
  fetch('getdata/get_daily_transaction.php')
    .then(res => res.json())
    .then(data => {
      const container = document.getElementById('dailyTransactionContainer');
      container.innerHTML = '';

      if (data.length === 0) {
        container.innerHTML = '<p class="text-center text-muted">Tidak ada transaksi yang tercatat.</p>';
        return;
      }

      let html = '<div class="timeline timeline-one-side">';

      data.forEach(item => {
        let icon = '';
        let teks = '';
        let statusHTML = '';
        const lastBayarText = (item.last_bayar && String(item.last_bayar).trim() !== '')
          ? item.last_bayar
          : '-';
        const lastBuktiText = (item.last_bukti && String(item.last_bukti).trim() !== '')
          ? item.last_bukti
          : '-';
        const buktiImageHtml = item.bukti_image_url ? `
          <div class="mt-2">
            <img src="${item.bukti_image_url}" alt="Bukti Pembayaran" class="img-fluid rounded border" style="max-width: 220px; max-height: 220px;" onerror="this.style.display='none'">
          </div>
        ` : '';
        const buktiLastImageHtml = item.last_bukti_image_url ? `
          <div class="mt-2">
            <small class="text-muted d-block mb-1">Bukti terakhir bayar:</small>
            <img src="${item.last_bukti_image_url}" alt="Bukti Terakhir Bayar" class="img-fluid rounded border" style="max-width: 220px; max-height: 220px;" onerror="this.style.display='none'">
          </div>
        ` : '';

        if (item.status === 'berhasil') {
          icon = '<img width="32" height="32" src="https://img.icons8.com/color/48/ok--v1.png" alt="ok">';
          teks = `
            Pembayaran sebesar <strong>${formatRupiah(item.harga)}</strong> dari pelanggan 
            <strong>${item.nama}</strong> (ID: ${item.idpel}) telah 
            <span class="text-success fw-bold">BERHASIL</span>.<br>
            Referensi transaksi: <strong>${item.bukti}</strong>.
          `;
        } 
        else if (item.status === 'konfirmasi') {
          icon = '<img width="48" height="48" src="https://img.icons8.com/color/48/help--v1.png" alt="help--v1"/>';
          teks = `
            Pelanggan <strong>${item.nama}</strong> (ID: ${item.idpel}) telah mengirim bukti pembayaran 
            sebesar <strong>${formatRupiah(item.harga)}</strong>.<br>
            Mohon verifikasi transaksi dengan referensi <strong>${item.bukti}</strong>.
          `;
          statusHTML = `
            <a href="proses/konfirmasi.php?id=${encodeURIComponent(item.id)}" 
               class="btn btn-success btn-sm mt-2">
              <i class="ni ni-check-bold"></i> Terima Pembayaran
            </a>
          `;
        } 
        else {
          icon = '<img width="32" height="32" src="https://img.icons8.com/color/48/info--v1.png" alt="info">';
          teks = `
            Pelanggan <strong>${item.nama}</strong> (ID: ${item.idpel}) telah membuat tagihan 
            sebesar <strong>${formatRupiah(item.harga)}</strong>.<br>
            Status: <span class="text-warning fw-bold">Menunggu Pembayaran</span> (Ref: ${item.bukti}).
          `;
        }

        html += `
          <div class="timeline-block mb-3 p-2 rounded border-start border-3 border-${item.status === 'berhasil' ? 'success' : (item.status === 'konfirmasi' ? 'info' : 'warning')} bg-light">
            <span class="timeline-step">${icon}</span>
            <div class="timeline-content">
              <h6 class="text-dark text-sm mb-1">${teks}</h6>
              <div class="small text-dark">
                <div><strong>Terakhir bayar:</strong> ${lastBayarText}</div>
                <div><strong>Bukti terakhir:</strong> ${lastBuktiText}</div>
              </div>
              ${buktiImageHtml}
              ${buktiLastImageHtml}
              ${statusHTML}
              <p class="text-secondary text-xs mt-2 mb-0">
                <i class="ni ni-calendar-grid-58"></i> ${item.tanggal}
              </p>
            </div>
          </div>`;
      });

      html += '</div>';
      container.innerHTML = html;
    })
    .catch(err => {
      console.error('Gagal memuat transaksi:', err);
      container.innerHTML = '<p class="text-danger text-center">Terjadi kesalahan saat memuat data transaksi.</p>';
    });
}


        // Defer loading until page is fully loaded
        document.addEventListener('DOMContentLoaded', function() {
          setTimeout(loadDailyTransaction, 1000); // Load after 1 second delay

          // Auto-refresh transaksi setiap 5 menit jika tab aktif
          let transactionInterval = setInterval(() => {
            if (!document.hidden) {
              loadDailyTransaction();
            }
          }, 300000); // 5 menit

          // Clear cache browser setiap 1 jam
          setInterval(() => {
            if (!document.hidden) {
              // Clear cache AJAX
              if ('caches' in window) {
                caches.keys().then(names => {
                  names.forEach(name => {
                    caches.delete(name);
                  });
                });
              }
              // Force garbage collection jika tersedia
              if (window.gc) {
                window.gc();
              }
              console.log('Cache dibersihkan untuk performa optimal');
            }
          }, 3600000); // 1 jam

          // Pause refresh saat tab tidak aktif
          document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
              console.log('Tab tidak aktif, pause auto-refresh');
            } else {
              console.log('Tab aktif, resume auto-refresh');
              // Refresh sekali saat kembali aktif
              loadDailyTransaction();
            }
          });
        });
      </script>




<div class="container-fluid py-2 px-3 px-md-4" style="margin-top: 10px;" data-dashboard-card="statistik_pembayaran">
  <div class="card shadow">
    <div class="card-header d-flex justify-content-between align-items-center" style="padding: 10px 15px;">
      <h5 class="mb-0" style="font-size: 1em;">📊 Statistik dan Laporan Pembayaran</h5>
      <div>
        <button class="btn btn-secondary btn-sm me-2" onclick="clearBrowserCache()" style="font-weight: 600;"><i class="fas fa-broom me-1"></i>Clear Cache</button>
        <button class="btn btn-secondary btn-sm" onclick="location.reload()" style="font-weight: 600;"><i class="fas fa-sync-alt me-1"></i>Refresh</button>
      </div>
    </div>
    <div class="card-body" style="padding: 12px 15px;">
      <?php
      $filter_bulan = (isset($_GET['bulan']) && $_GET['bulan'] !== '') ? (int)$_GET['bulan'] : (int)date('m');
      $filter_tahun = (isset($_GET['tahun']) && $_GET['tahun'] !== '') ? (int)$_GET['tahun'] : (int)date('Y');
      ?>
      <!-- Filter Bulan & Tahun -->
      <form method="get" class="row g-2 align-items-end mb-2" style="font-size: 0.9em;">
        <div class="col-auto">
          <label for="bulan" class="form-label mb-0" style="font-size: 0.9em;">Bulan</label>
          <select name="bulan" id="bulan" class="form-select" style="font-size: 0.9em; padding: 0.4rem 0.5rem;">
            <?php
            $nama_bulan = array(1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember');
            for ($b = 1; $b <= 12; $b++) {
              $selected = ($b == $filter_bulan) ? 'selected' : '';
              printf('<option value="%02d" %s>%s</option>', $b, $selected, $nama_bulan[$b]);
            }
            ?>
          </select>
        </div>
        <div class="col-auto">
          <label for="tahun" class="form-label mb-0" style="font-size: 0.9em;">Tahun</label>
          <select name="tahun" id="tahun" class="form-select" style="font-size: 0.9em; padding: 0.4rem 0.5rem;">
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
          <button type="submit" class="btn btn-primary btn-sm" style="font-size: 0.9em;">Tampilkan</button>
        </div>
      </form>
    
        <?php
        setlocale(LC_TIME, 'id_ID.utf8');
       
      
    
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

      $hargaPaketMap = [];
      $fasumPaketList = [];
      $qPaketMap = mysqli_query($conn, "SELECT id, PAKET, HARGA, BRAND, AREA FROM paket");
      while ($qPaketMap && ($r = mysqli_fetch_assoc($qPaketMap))) {
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
      while ($qPromo && ($r = mysqli_fetch_assoc($qPromo))) {
        $promoPaketIds[] = (string)$r['paket_id'];
      }

      $fixedDueDateDay = 28;
      $safeUsername = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($username ?? ''));
      $reminderFile = __DIR__ . '/notifbot/data/reminder-' . $safeUsername . '.json';
      if (is_file($reminderFile)) {
        $json = @file_get_contents($reminderFile);
        $cfg = json_decode((string)$json, true);
        if (is_array($cfg) && !empty($cfg) && isset($cfg[0]['jatuh_tempo'])) {
          $d = (int)$cfg[0]['jatuh_tempo'];
          if ($d >= 1 && $d <= 31) {
            $fixedDueDateDay = $d;
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
        $tipeBayar = strtolower(trim((string)($row['TIPE_BAYAR'] ?? 'prabayar')));
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

      // Prefetch semua tanggal bayar BERHASIL untuk pelanggan akun ini SEKALI
      // (mnq_build_payment_index, lihat libs/menunggak_payment_lookup.php),
      // supaya cek "apakah sudah bayar di periode X" & "kapan last_paid" tidak
      // lagi query database per-pelanggan per-bulan-tunggakan (N+1 lama yang
      // berat untuk ribuan pelanggan). LEFT JOIN last_paid yang lama dibuang
      // dari query ini karena nilainya selalu ditimpa ulang di bawah.
      $sqlMenunggakBase = "SELECT p.IDPEL, p.NAMA, p.PAKET, p.PEMILIK, p.BRAND, p.AREA, p.NOWA, p.ALAMAT, p.EMAIL, p.TIKOR, p.ODP, p.TANGGALPASANG, p.TEMPO, p.TIPE_BAYAR, p.TIPE_TEMPO, p.TANGGAL_MONTHVERSARY FROM pelanggan p WHERE p.PEMILIK IN ($userServerList) AND p.AREA IN ($area_filter)";
      $rsMenunggak = mysqli_query($conn, $sqlMenunggakBase);
      $dashboardPelangganRows = [];
      if ($rsMenunggak) {
        while ($row = mysqli_fetch_assoc($rsMenunggak)) {
          $dashboardPelangganRows[] = $row;
        }
      }

      $menunggakPaymentIndex = mnq_build_payment_index($conn, array_column($dashboardPelangganRows, 'IDPEL'), $trxTanggalExprNoAlias);

      $hasSuccessfulPaymentInPeriod = function ($idpel, $startDate, $endDate) use ($menunggakPaymentIndex) {
        return mnq_has_payment_in_period($menunggakPaymentIndex, (string)$idpel, (string)$startDate, (string)$endDate);
      };

      $uniqueMenunggak = [];
      foreach ($dashboardPelangganRows as $row) {
        $paket = isset($row['PAKET']) ? strtolower(trim((string)$row['PAKET'])) : '';
        $brand = isset($row['BRAND']) ? strtolower(trim((string)$row['BRAND'])) : '';
        $area = isset($row['AREA']) ? strtolower(trim((string)$row['AREA'])) : '';
        if ($isFasumNonPromo($paket)) continue;
        $harga = $resolveHarga($paket, $brand, $area);
        if ($harga === null || (float)$harga <= 0) continue;

        $idpel = (string)($row['IDPEL'] ?? '');
        $lastPaidInfo = mnq_get_last_paid($menunggakPaymentIndex, $idpel);
        $row['last_paid'] = $lastPaidInfo['last_paid'];
        $row['last_pengunaan'] = $lastPaidInfo['last_pengunaan'];

        if (!$shouldCount($row, $today)) continue;
        if ($idpel !== '') {
          $uniqueMenunggak[$idpel] = $row;
        }
      }

      $dataMenunggak = [];
      foreach ($uniqueMenunggak as $row) {
        $idpel = (string)$row['IDPEL'];
        // last_paid/last_pengunaan sudah diisi di loop atas dari $menunggakPaymentIndex.

        $reference = $getReferenceDate($row);
        $nextDueDate = $resolveFirstDueForRow($row, $reference, $fixedDueDateDay);
        if (empty($nextDueDate) || strtotime($nextDueDate) === false || strtotime($nextDueDate) > strtotime($today)) continue;

        $todayTs = strtotime($today);
        $isConsecutive = true;
        $bulanTunggak = 0;
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

        if ($isConsecutive && $bulanTunggak >= 1) {
          $row['bulan_nunggak'] = $bulanTunggak;
          $dataMenunggak[] = $row;
        }
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
// Peta harga paket -- reuse $hargaPaketMap/$fasumPaketList yang sudah diambil
// di atas (baris ~942), bukan query ulang tabel `paket` dari awal (sebelumnya
// blok ini menduplikasi query yang sama persis).
// ========================
$harga_paket_map = $hargaPaketMap;
$fasum_paket_list = $fasumPaketList;

// ========================
// Ambil daftar paket promo
// ========================
$promo_paket_ids = [];
$q_promo = mysqli_query($conn, "SELECT paket_id FROM promo_paket");
while ($r = mysqli_fetch_assoc($q_promo)) {
  $promo_paket_ids[] = $r['paket_id'];
}



// ========================
// Isi data_fasum dengan pelanggan FASUM (harga 0 dan bukan promo) -- pakai
// $dashboardPelangganRows yang sudah di-buffer di atas (satu query yang sama
// dipakai juga untuk hitung menunggak & modal "Daftar Pelanggan" di bawah),
// bukan query ulang tabel pelanggan dari awal.
// ========================
$data_fasum = [];
foreach ($dashboardPelangganRows as $row) {
  $paket_pelanggan = strtolower(trim((string)($row['PAKET'] ?? '')));
  // cek harga paket
  $q_harga = mysqli_query($conn, "SELECT id, HARGA FROM paket WHERE LOWER(TRIM(PAKET)) = '" . mysqli_real_escape_string($conn, $paket_pelanggan) . "'");
  if ($q_harga && ($r_harga = mysqli_fetch_assoc($q_harga))) {
    $harga = $r_harga['HARGA'];
    $paket_id = $r_harga['id'];
    if ($harga == 0) {
      // cek bukan promo
      if (!in_array($paket_id, $promo_paket_ids)) {
        $data_fasum[] = $row;
      }
    }
  } else {
    // paket tidak ditemukan, skip
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

  // last_paid/last_pengunaan sudah diisi dari $menunggakPaymentIndex saat
  // $dataMenunggak dibangun di atas -- tidak perlu query ulang ke transaksi.

  $row['harga_paket'] = (float)$harga_paket;
  if ($filter_bulan_nunggak !== null && (int)$row['bulan_nunggak'] !== $filter_bulan_nunggak) {
    continue;
  }

  $data_menunggak[] = $row;
}

usort($data_menunggak, function ($a, $b) {
  return ((int)($b['bulan_nunggak'] ?? 0)) <=> ((int)($a['bulan_nunggak'] ?? 0));
});
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
              <a href="daftar_pelanggan_berhenti.php?bulan=<?php echo $filter_bulan; ?>&tahun=<?php echo $filter_tahun; ?>" class="btn btn-primary btn-sm mt-1" style="font-size: 0.75em; padding: 0.3rem 0.6rem;">Lihat</a>
            </div>
          </div>
        </div>
      
        <div class="col-md-3 mb-3">
          <div class="card shadow-sm h-100">
            <div class="card-body text-center" style="padding: 8px;">
              <div class="fw-bold text-primary" style="font-size: 0.75em;">Pemasukan Hari Ini</div>
              <div style="font-size: 1.2em; color: #2563eb; font-weight: bold;">Rp. <?php echo number_format($data_hari['total'] ?? 0, 0, ',', '.'); ?></div>
              <a href="Transaction.php?hari=<?php echo date('Y-m-d'); ?>" class="btn btn-primary btn-sm mt-1" style="font-size: 0.75em; padding: 0.3rem 0.6rem;">Lihat</a>
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
        <?php if (!empty($is_reseller)):
          $reseller_omset_kotor = (float)($data_bulan['total'] ?? 0);
          $reseller_laba = $reseller_omset_kotor - (float)$reseller_bandwidth_burden;
        ?>
        <div class="col-md-3 mb-3">
          <div class="card shadow-sm h-100 border-success">
            <div class="card-body text-center" style="padding: 8px;">
              <div class="fw-bold text-success" style="font-size: 0.75em;">Laba/Saldo Anda (Bulan Ini)</div>
              <div style="font-size: 0.7em; color: #6c757d;">Omset Kotor: Rp. <?php echo number_format($reseller_omset_kotor, 0, ',', '.'); ?></div>
              <div style="font-size: 0.7em; color: #6c757d;">Beban Bandwidth: Rp. <?php echo number_format((float)$reseller_bandwidth_burden, 0, ',', '.'); ?></div>
              <div style="font-size: 1.2em; color: #198754; font-weight: bold;">Rp. <?php echo number_format($reseller_laba, 0, ',', '.'); ?></div>
            </div>
          </div>
        </div>
        <?php endif; ?>
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
              <div class="card-body text-center" style="padding: 8px;">
                <div class="fw-bold text-danger" style="font-size: 0.75em;">Pengeluaran Hari Ini</div>
                <div style="font-size: 1.2em; color: #dc3545; font-weight: bold;">Rp. <?php echo number_format($total_pengeluaran_hari, 0, ',', '.'); ?></div>
                <a href="pengeluaran.php?hari=<?php echo date('Y-m-d'); ?>" class="btn btn-danger btn-sm mt-1" style="font-size: 0.75em; padding: 0.3rem 0.6rem;">Lihat</a>
              </div>
            </div>
          </div>
          <div class="col-md-3 mb-3">
            <div class="card shadow-sm h-100">
              <div class="card-body text-center" style="padding: 8px;">
                <div class="fw-bold text-danger" style="font-size: 0.75em;">Pengeluaran Minggu Ini</div>
                <div style="font-size: 1.2em; color: #dc3545; font-weight: bold;">Rp. <?php echo number_format($total_pengeluaran_minggu, 0, ',', '.'); ?></div>
              </div>
            </div>
          </div>
          <div class="col-md-3 mb-3">
            <div class="card shadow-sm h-100">
              <div class="card-body text-center" style="padding: 8px;">
                <div class="fw-bold text-danger" style="font-size: 0.75em;">Pengeluaran Bulan Ini</div>
                <div style="font-size: 1.2em; color: #dc3545; font-weight: bold;">Rp. <?php echo number_format($total_pengeluaran_bulan, 0, ',', '.'); ?></div>
              </div>
            </div>
          </div>
          <div class="col-md-3 mb-3">
            <div class="card shadow-sm h-100">
              <div class="card-body text-center" style="padding: 8px;">
                <div class="fw-bold text-danger" style="font-size: 0.75em;">Pengeluaran Tahun Ini</div>
                <div style="font-size: 1.2em; color: #dc3545; font-weight: bold;">Rp. <?php echo number_format($total_pengeluaran_tahun, 0, ',', '.'); ?></div>
              </div>
            </div>
          </div>
        </div>
         <a href="statistics.php" class="btn btn-info"><i class="fas fa-chart-bar me-1"></i> Lebih Detail</a>
      </div>
  </div>
      </div>





    </div>
  </div>
 


<!-- Modal for Customer Details -->
<div class="modal fade" id="customerModal" tabindex="-1" aria-labelledby="customerModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="customerModalLabel">Daftar Pelanggan</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <input type="text" id="searchCustomer" class="form-control mb-3" placeholder="Cari pelanggan...">
        <div class="table-responsive" style="overflow-x:scroll;overflow-y:visible;-webkit-overflow-scrolling:touch;touch-action:pan-x;overscroll-behavior-x:contain;max-width:100%;">
          <table class="table table-striped" id="customerTable" style="min-width:480px;table-layout:auto;">
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
              // Reuse $dashboardPelangganRows (sudah di-buffer di atas dari query
              // yang sama persis) alih-alih query ulang tabel pelanggan -- diurutkan
              // di PHP supaya urutan tampilan tetap ORDER BY NAMA seperti semula.
              $customerModalRows = $dashboardPelangganRows;
              usort($customerModalRows, function ($a, $b) {
                return strnatcasecmp((string)($a['NAMA'] ?? ''), (string)($b['NAMA'] ?? ''));
              });
              foreach ($customerModalRows as $row) {
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




  <?php
  // Optimized server data loading with cache check
  $filename = "serverlog/" . ($AKSES == 'ASSISTANT' ? $asistant_name : $ceknama) . ".txt";
  
  // Initialize default values
  $Total_pelanggan = $Total_online = $Total_online_expired = 0;
  $Total_online_paket = $Total_expired_offline = 0;
  $instalasi_bulan_ini_total = 0;
  $berhenti_bulan_ini_total = 0;
  $pppoe_profiles = $hotspot_profiles = [];
  $paket_harga = $paket_harga_pppoe = $paket_harga_hotspot = [];
  $total_pppoe = $total_hotspot = 0;

  $bulan_saat_ini = (int)date('m');
  $tahun_saat_ini = (int)date('Y');

  $sql_instalasi_bulan_ini = "SELECT COUNT(*) AS total
                             FROM pelanggan
                             WHERE PEMILIK IN ($userServerList)
                               AND AREA IN ($internetStatusAreaFilter)
                               AND MONTH(TANGGALPASANG) = $bulan_saat_ini
                               AND YEAR(TANGGALPASANG) = $tahun_saat_ini";
  $result_instalasi_bulan_ini = mysqli_query($conn, $sql_instalasi_bulan_ini);
  if ($result_instalasi_bulan_ini && ($row_instalasi_bulan_ini = mysqli_fetch_assoc($result_instalasi_bulan_ini))) {
    $instalasi_bulan_ini_total = (int)($row_instalasi_bulan_ini['total'] ?? 0);
  }

  $sql_berhenti_bulan_ini = "SELECT COUNT(*) AS total
                            FROM pelanggan_berhenti
                            WHERE pemilik IN ($userServerList)
                              AND MONTH(tanggal_berhenti) = $bulan_saat_ini
                              AND YEAR(tanggal_berhenti) = $tahun_saat_ini";
  $result_berhenti_bulan_ini = mysqli_query($conn, $sql_berhenti_bulan_ini);
  if ($result_berhenti_bulan_ini && ($row_berhenti_bulan_ini = mysqli_fetch_assoc($result_berhenti_bulan_ini))) {
    $berhenti_bulan_ini_total = (int)($row_berhenti_bulan_ini['total'] ?? 0);
  }

  // Check if file exists and is recent (less than 5 minutes old)
  if (file_exists($filename)) {
    $file_age = time() - filemtime($filename);
    
    if ($file_age < 300) { // File is less than 5 minutes old
      $json_data = file_get_contents($filename);
      if ($json_data && $data = json_decode($json_data, true)) {
        $Total_pelanggan = $data['Total_pelanggan'] ?? 0;
        $Total_online = $data['Total_online'] ?? 0;
        $Total_online_expired = $data['Total_online_expired'] ?? 0;
        $Total_online_paket = $data['Total_online_paket'] ?? 0;
        $Total_expired_offline = $data['Total_expired_offline'] ?? 0;
        $Total_los_internet= $data['Total_los_internet'] ?? 0;
        $pppoe_profiles = $data['pppoe_profiles'] ?? [];
        $hotspot_profiles = $data['hotspot_profiles'] ?? [];
        $paket_harga = $data['paket_harga'] ?? [];
        $paket_harga_pppoe = $data['paket_harga_pppoe'] ?? [];
        $paket_harga_hotspot = $data['paket_harga_hotspot'] ?? [];
        $total_pppoe = $data['total_pppoe'] ?? 0;
        $total_hotspot = $data['total_hotspot'] ?? 0;
      }
    } else {
      // File is old, show loading message and trigger refresh
      echo '<script>console.log("Server data cache expired, refreshing...");</script>';
    }
  }

  ?>



<!-- CDN Font Awesome Free -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.2/css/all.min.css">

  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fresh-table@1.0.0/fresh-table.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

  <style>
    .card-box,
    .card-box h1,
    .card-box h2,
    .card-box h3,
    .card-box h4,
    .card-box h5,
    .card-box h6,
    .card-box p,
    .card-box span,
    .card-box small,
    .card-box strong,
    .card-box i,
    .card-box a {
      color: #ffffff !important;
    }

    .card-box h6,
    .card-box p,
    .card-box span,
    .card-box strong {
      text-shadow: 0 1px 2px rgba(0, 0, 0, 0.38);
    }

    .card-box .dashboard-show-data-link {
      border-color: rgba(255, 255, 255, 0.92);
      background: rgba(255, 255, 255, 0.2);
      color: #ffffff !important;
    }

    .card-box .dashboard-show-data-link:hover,
    .card-box .dashboard-show-data-link:focus {
      background: rgba(255, 255, 255, 0.32);
      color: #ffffff !important;
      border-color: #ffffff;
    }

    .card-box .badge {
      background: rgba(255, 255, 255, 0.94) !important;
      color: #0f172a !important;
      font-weight: 800;
      border: 1px solid rgba(15, 23, 42, 0.15);
      text-shadow: none;
    }

    body.app-theme-dark .card-box .badge {
      background: #e2e8f0 !important;
      color: #0f172a !important;
      border-color: rgba(148, 163, 184, 0.7);
    }

    .dashboard-dark-header .badge,
    .theme-aware-header .badge {
      font-weight: 800;
      opacity: 1 !important;
    }

    #internetStatusModal .text-muted,
    #internetStatusModal .small.text-muted {
      color: var(--bs-secondary-color, #495057) !important;
      opacity: 1 !important;
    }

    body.app-theme-dark #internetStatusModal .text-muted,
    body.app-theme-dark #internetStatusModal .small.text-muted {
      color: #cbd5e1 !important;
    }

    /* Pastikan tombol silang close modal selalu kontras */
    /* Modal header terang (default) - invert icon putih jadi gelap */
    .modal .modal-header .btn-close {
      filter: invert(1) !important;
      opacity: 1 !important;
      border: 1px solid rgba(100, 116, 139, 0.4);
      border-radius: 0.4rem;
      box-shadow: 0 1px 3px rgba(15, 23, 42, 0.15);
      transition: transform 0.15s ease, box-shadow 0.15s ease, filter 0.15s ease;
    }

    /* Modal header gelap/gradient - biarkan icon tetap putih */
    .modal .modal-header .btn-close.btn-close-white {
      filter: none !important;
      opacity: 1 !important;
      border-color: rgba(255, 255, 255, 0.45);
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.25);
    }

    .modal .modal-header .btn-close:hover,
    .modal .modal-header .btn-close:focus {
      opacity: 1 !important;
      box-shadow: 0 0 0 0.2rem rgba(59, 130, 246, 0.25);
      transform: scale(1.08);
    }

    /* Dark mode: header modal jadi gelap, icon putih sudah kontras */
    body.app-theme-dark .modal .modal-header .btn-close,
    body.app-theme-dark .modal .modal-header .btn-close.btn-close-white {
      filter: none !important;
      opacity: 1 !important;
      border-color: rgba(148, 163, 184, 0.6);
      box-shadow: 0 1px 2px rgba(2, 6, 23, 0.35);
    }

    body.app-theme-dark .modal .modal-header .btn-close:hover,
    body.app-theme-dark .modal .modal-header .btn-close:focus {
      border-color: rgba(125, 211, 252, 0.8);
      box-shadow: 0 0 0 0.2rem rgba(125, 211, 252, 0.22);
      transform: scale(1.08);
    }

    .dashboard-show-data-link {
      display: inline-block;
      margin-left: 6px;
      padding: 1px 6px;
      border-radius: 999px;
      border: 1px solid rgba(255, 255, 255, 0.85);
      background: rgba(255, 255, 255, 0.16);
      color: #ffffff !important;
      font-size: 0.8em;
      font-weight: 800;
      letter-spacing: 0.01em;
      line-height: 1.3;
      text-decoration: none;
      text-shadow: 0 1px 1px rgba(0, 0, 0, 0.35);
      vertical-align: middle;
    }

    .dashboard-show-data-link:hover,
    .dashboard-show-data-link:focus {
      background: rgba(255, 255, 255, 0.28);
      color: #ffffff !important;
      border-color: #ffffff;
      text-decoration: none;
      outline: none;
    }
  </style>


  <div class="container-fluid mt-4 px-3 px-md-4" data-dashboard-card="infrastruktur_ringkasan">


    <div class="row">
      <!-- Card kiri - col-8 -->
      <div class="col-md-8 mb-4">
        <div class="card shadow-sm p-3 h-100 d-flex flex-column">

          <!-- Ringkasan PPPoE -->
          <div class="mb-4">
            <div class="row g-2">
              <!-- Total Users -->



        <?php
        // Optimized: Single query instead of multiple separate queries
    if($AKSES == 'ASSISTANT') {
      $where_clause = "`AREA` IN ($arealist)";
    } else {
      $where_clause = "`pemilik` IN ($userServerList)";
    }
    $stats_query = "
    SELECT 
      (SELECT COUNT(*) FROM server WHERE $where_clause) as server_total,
      (SELECT COUNT(DISTINCT AREA) FROM server WHERE $where_clause) as area_total,
      (SELECT COUNT(DISTINCT AREA) FROM olt WHERE $where_clause) as olt_total,
      (SELECT COUNT(*) FROM odp WHERE $where_clause) as odp_total,
      (SELECT COALESCE(SUM(
        CASE 
          WHEN splitter='1:2' THEN 2
          WHEN splitter='1:4' THEN 4
          WHEN splitter='1:8' THEN 8
          WHEN splitter='1:16' THEN 16
          WHEN splitter='1:32' THEN 32
          ELSE 0
        END
      ), 0) FROM odp WHERE $where_clause AND Hirarki='ODP') as hompas_total
    ";
        
        $stats_result = mysqli_query($conn, $stats_query);
        if ($stats_result) {
            $stats = mysqli_fetch_assoc($stats_result);
            $server_total = $stats['server_total'] ?? 0;
            $area_total = $stats['area_total'] ?? 0;
            $olt_total = $stats['olt_total'] ?? 0;
            $odp_total = $stats['odp_total'] ?? 0;
            $hompas_total = $stats['hompas_total'] ?? 0;
        } else {
            $server_total = $area_total = $olt_total = $odp_total = $hompas_total = 0;
        }


          $tampil = '<i class="fas fa-server card-icon"></i> '.$server_total.' Routers / <i class="fas fa-map-marker-alt card-icon"></i> '.$area_total.' Area / <i class="fas fa-network-wired card-icon"></i> '.$olt_total.' OLT / <i class="fas fa-project-diagram card-icon"></i> '.$odp_total.' ODP / <i class="fas fa-code-branch card-icon"></i> '.$hompas_total.' Hompas';

        // Memberikan feedback apakah ada IP yang berhasil ditambahkan
        if ($ip_added) {
          echo '<div class="alert alert-success" role="alert">IP address berhasil ditambahkan ke dalam gateway list.</div>';
        } else {
        }
        ?>


<!-- Informasi System: ping browser<->server & status layanan Radius -->
<div class="col-md-6 col-12">
  <div class="card-box d-flex align-items-center small" id="sysPingCard"
       style="background: linear-gradient(135deg, #64748b, #334155); color: white; padding: 10px; border-radius: 8px;">
    <i class="fas fa-tower-broadcast card-icon" style="font-size: 1.2em; margin-right: 8px;"></i>
    <div style="font-size: 0.85em;">
      <h6 class="mb-1 font-weight-bold" style="font-size: 0.9em; text-shadow: 1px 1px 2px rgba(0,0,0,0.3);">
        <span id="sysPingValue">Memeriksa...</span>
      </h6>
      <p class="mb-0" style="font-size: 0.8em;">Ping Browser ke Server</p>
    </div>
  </div>
</div>
<div class="col-md-6 col-12">
  <div class="card-box d-flex align-items-center small" id="sysRadiusCard"
       style="background: linear-gradient(135deg, #64748b, #334155); color: white; padding: 10px; border-radius: 8px;">
    <i class="fas fa-shield-halved card-icon" style="font-size: 1.2em; margin-right: 8px;"></i>
    <div style="font-size: 0.85em;">
      <h6 class="mb-1 font-weight-bold" style="font-size: 0.9em; text-shadow: 1px 1px 2px rgba(0,0,0,0.3);">
        <span id="sysRadiusValue">Memeriksa...</span>
      </h6>
      <p class="mb-0" style="font-size: 0.8em;">Layanan Radius</p>
    </div>
  </div>
</div>
<script>
  (function () {
    async function updateSystemStatusCards() {
      const pingValueEl = document.getElementById('sysPingValue');
      const pingCardEl = document.getElementById('sysPingCard');
      const radiusValueEl = document.getElementById('sysRadiusValue');
      const radiusCardEl = document.getElementById('sysRadiusCard');

      const t0 = performance.now();
      try {
        const res = await fetch('getdata/system_status.php?_=' + Date.now(), { credentials: 'same-origin', cache: 'no-store' });
        const data = await res.json();
        const ms = Math.round(performance.now() - t0);

        if (pingValueEl) {
          let label = 'Baik';
          if (ms > 300) label = 'Lambat';
          else if (ms > 100) label = 'Cukup';
          pingValueEl.textContent = ms + ' ms (' + label + ')';
        }
        if (pingCardEl) {
          pingCardEl.style.background = ms > 300
            ? 'linear-gradient(135deg, #ef4444, #b91c1c)'
            : (ms > 100 ? 'linear-gradient(135deg, #f59e0b, #b45309)' : 'linear-gradient(135deg, #22c55e, #15803d)');
        }

        if (data && data.success) {
          const active = !!data.radius_active;
          if (radiusValueEl) radiusValueEl.textContent = active ? 'Aktif' : 'Tidak Aktif';
          if (radiusCardEl) {
            radiusCardEl.style.background = active
              ? 'linear-gradient(135deg, #22c55e, #15803d)'
              : 'linear-gradient(135deg, #ef4444, #b91c1c)';
          }
        } else {
          if (radiusValueEl) radiusValueEl.textContent = 'Tidak diketahui';
        }
      } catch (err) {
        if (pingValueEl) pingValueEl.textContent = 'Terputus';
        if (pingCardEl) pingCardEl.style.background = 'linear-gradient(135deg, #ef4444, #b91c1c)';
        if (radiusValueEl) radiusValueEl.textContent = '-';
        if (radiusCardEl) radiusCardEl.style.background = 'linear-gradient(135deg, #64748b, #334155)';
      }
    }

    document.addEventListener('DOMContentLoaded', function () {
      updateSystemStatusCards();
      setInterval(updateSystemStatusCards, 15000);
    });
  })();
</script>

<!-- Infrastruktur -->
<div class="col-12">
  <div class="card-box d-flex align-items-center small"
       style="background: linear-gradient(135deg, var(--logo-primary), var(--logo-secondary)); color: white; padding: 12px; border-radius: 8px;">
    <div style="font-size: 0.9em;">
      <h6 class="mb-0 text-white font-weight-bold" style="text-shadow: 1px 1px 2px rgba(0,0,0,0.3); font-size: 0.95em;"><?= $tampil ?></h6>
      <p class="mb-0" style="font-size: 0.85em;">Infrastruktur</p>
    </div>
  </div>
</div>
<!-- <p class="mb-0">Data on all Mikrotik aktual </p> -->
<!-- SLA Server 30 Hari -->
<div class="col-md-4 col-sm-6 col-12">
  <div class="card-box d-flex align-items-center small"
       style="background: linear-gradient(135deg, #06b6d4, #0e7490); color: white; padding: 10px; border-radius: 8px;">
    <i class="fas fa-gauge-high card-icon" style="font-size: 1.2em; margin-right: 8px;"></i>
    <div style="font-size: 0.85em;">
      <h6 class="mb-1 font-weight-bold" style="font-size: 0.9em; text-shadow: 1px 1px 2px rgba(0,0,0,0.3);"><span id="slaTotalPercent">0.00%</span></h6>
      <p class="mb-0" style="font-size: 0.8em;">SLA Server Total 30 Hari
        <a href="javascript:void(0)" onclick="openSlaModal()" class="dashboard-show-data-link">Show Data</a>
      </p>
    </div>
  </div>
</div>

<!-- INST / DST / MT / MGS Ticket -->
<div class="col-md-4 col-sm-6 col-12">
  <div class="card-box d-flex align-items-center small" 
       style="background: linear-gradient(135deg, #f59e0b, #d97706); color: white; padding: 10px; border-radius: 8px;">
    <i class="fas fa-clipboard-list card-icon" style="font-size: 1.2em; margin-right: 8px;"></i>
    <div style="font-size: 0.85em;">
      <h6 class="mb-1 font-weight-bold" style="font-size: 0.9em; text-shadow: 1px 1px 2px rgba(0,0,0,0.3);"><span id="totalInstalasi">Loading...</span> / <span id="dismantel">Loading...</span> / <span id="maintenance">Loading...</span> / <span id="migrasi">Loading...</span></h6>
      <p class="mb-0" style="font-size: 0.8em;">INST / DST / MT / MGS
        <a href="javascript:void(0)" onclick="openTicketTypeModal('INSTALLASI')" class="dashboard-show-data-link">Show Data</a>
      </p>
    </div>
  </div>
</div>

<!-- Total Users -->
<div class="col-md-4 col-sm-6 col-12">
  <div class="card-box d-flex align-items-center small" 
       style="background: linear-gradient(135deg, #0ea5e9, #0284c7); color: white; padding: 10px; border-radius: 8px;">
    <i class="fas fa-user-plus card-icon" style="font-size: 1.2em; margin-right: 8px;"></i>
    <div style="font-size: 0.85em;">
      <h6 class="mb-1 font-weight-bold" style="text-shadow: 1px 1px 2px rgba(0,0,0,0.3); font-size: 0.95em;"><?= $instalasi_bulan_ini_total ?></h6>
      <p class="mb-0" style="font-size: 0.8em;">Pelanggan Instalasi Bulan Ini
        <a href="javascript:void(0)" onclick="openInternetStatusModal('instalasi_bulan_ini')" class="dashboard-show-data-link">Show Data</a>
      </p>
    </div>
  </div>
</div>

<div class="col-md-4 col-sm-6 col-12">
  <div class="card-box d-flex align-items-center small" 
       style="background: linear-gradient(135deg, #f97316, #ea580c); color: white; padding: 10px; border-radius: 8px;">
    <i class="fas fa-user-minus card-icon" style="font-size: 1.2em; margin-right: 8px;"></i>
    <div style="font-size: 0.85em;">
      <h6 class="mb-1 font-weight-bold" style="text-shadow: 1px 1px 2px rgba(0,0,0,0.3); font-size: 0.95em;"><?= $berhenti_bulan_ini_total ?></h6>
      <p class="mb-0" style="font-size: 0.8em;">Pelanggan Berhenti Bulan Ini
        <a href="javascript:void(0)" onclick="openInternetStatusModal('berhenti_bulan_ini')" class="dashboard-show-data-link">Show Data</a>
      </p>
    </div>
  </div>
</div>

<div class="col-md-4 col-sm-6 col-12">
  <div class="card-box d-flex align-items-center small" 
       style="background: linear-gradient(135deg, var(--logo-primary), var(--logo-secondary)); color: white; padding: 10px; border-radius: 8px;">
    <i class="fas fa-users card-icon" style="font-size: 1.2em; margin-right: 8px;"></i>
    <div style="font-size: 0.85em;">
      <h6 class="mb-1 font-weight-bold" style="text-shadow: 1px 1px 2px rgba(0,0,0,0.3); font-size: 0.95em;"><?= $Total_pelanggan ?></h6>
      <p class="mb-0" style="font-size: 0.8em;">Total Users
        <a href="javascript:void(0)" onclick="openInternetStatusModal('total_users')" class="dashboard-show-data-link">Show Data</a>
      </p>
    </div>
  </div>
</div>

<!-- Active Internet -->
<div class="col-md-4 col-sm-6 col-12">
  <div class="card-box d-flex align-items-center small" 
       style="background: linear-gradient(135deg, #10b981, #059669); color: white; padding: 10px; border-radius: 8px;">
    <i class="fas fa-user-check card-icon" style="font-size: 1.2em; margin-right: 8px;"></i>
    <div style="font-size: 0.85em;">
      <h6 class="mb-1 font-weight-bold" style="text-shadow: 1px 1px 2px rgba(0,0,0,0.3); font-size: 0.95em;"><?= $Total_online_paket ?></h6>
      <p class="mb-0" style="font-size: 0.8em;">Internet Online
        <a href="javascript:void(0)" onclick="openInternetStatusModal('internet_online')" class="dashboard-show-data-link">Show Data</a>
      </p>
    </div>
  </div>
</div>

<!-- LOS / Offline -->
<div class="col-md-4 col-sm-6 col-12">
  <div class="card-box d-flex align-items-center small" 
       style="background: linear-gradient(135deg, #ef4444, #dc2626); color: white; padding: 10px; border-radius: 8px;">
    <i class="fas fa-exclamation-triangle card-icon" style="font-size: 1.2em; margin-right: 8px;"></i>
    <div style="font-size: 0.85em;">
      <h6 class="mb-1 font-weight-bold" style="text-shadow: 1px 1px 2px rgba(0,0,0,0.3); font-size: 0.95em;"><?= $Total_los_internet ?></h6>
      <p class="mb-0" style="font-size: 0.8em;">internet Los
        <a href="javascript:void(0)" onclick="openInternetStatusModal('internet_los')" class="dashboard-show-data-link">Show Data</a>
      </p>
    </div>
  </div>
</div>

<!-- Expired online -->
<div class="col-md-4 col-sm-6 col-12">
  <div class="card-box d-flex align-items-center small" 
       style="background: linear-gradient(135deg, #f59e0b, #d97706); color: white; padding: 10px; border-radius: 8px;">
    <i class="fas fa-hourglass-half card-icon" style="font-size: 1.2em; margin-right: 8px;"></i>
    <div style="font-size: 0.85em;">
      <h6 class="mb-1 font-weight-bold" style="text-shadow: 1px 1px 2px rgba(0,0,0,0.3); font-size: 0.95em;"><?= $Total_online_expired ?></h6>
      <p class="mb-0" style="font-size: 0.8em;">Expired online
        <a href="javascript:void(0)" onclick="openInternetStatusModal('expired_online')" class="dashboard-show-data-link">Show Data</a>
      </p>
    </div>
  </div>
</div>

<!-- Expired Los -->
<div class="col-md-4 col-sm-6 col-12">
  <div class="card-box d-flex align-items-center small" 
       style="background: linear-gradient(135deg, #ef4444, #dc2626); color: white; padding: 10px; border-radius: 8px;">
    <i class="fas fa-exclamation-triangle card-icon" style="font-size: 1.2em; margin-right: 8px;"></i>
    <div style="font-size: 0.85em;">
      <h6 class="mb-1 font-weight-bold" style="text-shadow: 1px 1px 2px rgba(0,0,0,0.3); font-size: 0.95em;"><?= $Total_expired_offline ?></h6>
      <p class="mb-0" style="font-size: 0.8em;">Expired los
        <a href="javascript:void(0)" onclick="openInternetStatusModal('expired_los')" class="dashboard-show-data-link">Show Data</a>
      </p>
    </div>
  </div>
</div>

<div class="modal fade" id="internetStatusModal" tabindex="-1" aria-labelledby="internetStatusModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="internetStatusModalLabel">Detail Status Internet</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="row g-2 mb-3">
          <div class="col-md-3">
            <input type="text" id="internetStatusSearch" class="form-control" placeholder="Filter IDPEL / Nama / Paket / ODP / WA / Alamat / Tanggal Bayar / Bukti">
          </div>
          <div class="col-md-3">
            <select id="internetStatusAreaFilter" class="form-select">
              <option value="">Semua Area</option>
            </select>
          </div>
          <div class="col-md-3">
            <select id="internetStatusOdpFilter" class="form-select">
              <option value="">Semua ODP</option>
            </select>
          </div>
          <div class="col-md-3 d-flex gap-2 justify-content-md-end">
            <button type="button" class="btn btn-success btn-sm" id="btnExportInternetExcel">Export Excel</button>
            <button type="button" class="btn btn-danger btn-sm" id="btnExportInternetPdf">Export PDF</button>
          </div>
        </div>

        <div class="table-responsive">
          <table class="table table-striped table-hover" id="internetStatusTable">
            <thead>
              <tr>
                <th>Aksi</th>
                <th>IDPEL</th>
                <th>Nama</th>
                <th>Paket</th>
                <th>Area</th>
                <th>ODP</th>
                <th>No WA</th>
                <th>Tanggal Bayar Terakhir</th>
                <th>Bukti Terakhir</th>
                <th>Alamat</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
        <div class="small text-muted mt-2" id="internetStatusCounter">0 data ditampilkan</div>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="slaStatusModal" tabindex="-1" aria-labelledby="slaStatusModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="slaStatusModalLabel">Detail SLA Server (30 Hari)</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="table-responsive">
          <table class="table table-striped table-hover" id="slaStatusTable">
            <thead>
              <tr>
                <th>Server</th>
                <th>Area</th>
                <th>IP</th>
                <th>SLA</th>
                <th>Uptime 30 Hari</th>
                <th>Last Check</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
        <div class="small text-muted mt-2" id="slaStatusCounter">0 server</div>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Detail Tiket per Tipe Pekerjaan (INST/DST/MT/MGS) -->
<div class="modal fade" id="ticketTypeModal" tabindex="-1" aria-labelledby="ticketTypeModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="ticketTypeModalLabel">Detail Tiket</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="btn-group mb-3" role="group">
          <button type="button" class="btn btn-outline-primary btn-sm ticket-type-tab" data-tipe="INSTALLASI" onclick="switchTicketTypeTab('INSTALLASI')">Instalasi</button>
          <button type="button" class="btn btn-outline-primary btn-sm ticket-type-tab" data-tipe="DISMANTLE" onclick="switchTicketTypeTab('DISMANTLE')">Dismantle</button>
          <button type="button" class="btn btn-outline-primary btn-sm ticket-type-tab" data-tipe="MAINTENANCE" onclick="switchTicketTypeTab('MAINTENANCE')">Maintenance</button>
          <button type="button" class="btn btn-outline-primary btn-sm ticket-type-tab" data-tipe="MIGRASI" onclick="switchTicketTypeTab('MIGRASI')">Migrasi</button>
        </div>
        <div class="row g-2 mb-2 align-items-center">
          <div class="col-md-4">
            <input type="text" id="ticketTypeSearch" class="form-control form-control-sm" placeholder="Cari ID Pelanggan / Nama / No WA...">
          </div>
          <div class="col-md-3">
            <select id="ticketTypeStatusFilter" class="form-select form-select-sm">
              <option value="">Status: BARU + PENDING (default)</option>
              <option value="BARU">BARU</option>
              <option value="PENDING">PENDING</option>
              <option value="DONE">DONE</option>
              <option value="CANCEL">CANCEL</option>
            </select>
          </div>
          <div class="col-md-5 small text-muted text-md-end">Sumber data: <span id="ticketTypeSource">-</span></div>
        </div>
        <div class="mb-2">
          <button type="button" class="btn btn-outline-danger btn-sm" id="btnHapusTerpilih" onclick="bulkHapusTiketDashboard()" disabled>
            <i class="fas fa-trash me-1"></i>Hapus Terpilih (<span id="jumlahTerpilih">0</span>)
          </button>
        </div>
        <div class="table-responsive">
          <table class="table table-striped table-hover" id="ticketTypeTable">
            <thead>
              <tr>
                <th style="width:32px;"><input type="checkbox" id="ticketTypeSelectAll" onchange="toggleSelectAllTiket(this)"></th>
                <th>ID Pelanggan / Judul</th>
                <th>Nama</th>
                <th>No WA</th>
                <th>Keterangan</th>
                <th>Project/Area</th>
                <th>Petugas</th>
                <th>Status</th>
                <th>Tanggal</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody><tr><td colspan="10" class="text-center text-muted">Memuat data...</td></tr></tbody>
          </table>
        </div>
        <div class="small text-muted mt-2" id="ticketTypeCounter">0 tiket</div>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Edit Tiket (dipanggil dari dalam ticketTypeModal) -->
<div class="modal fade" id="editTiketModal" tabindex="-1" aria-labelledby="editTiketModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="editTiketModalLabel">Edit Tiket</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="editTiketId">
        <input type="hidden" id="editTiketSource">
        <div class="mb-2">
          <label class="form-label small">Nama Pelanggan</label>
          <input type="text" id="editTiketNama" class="form-control form-control-sm">
        </div>
        <div class="mb-2">
          <label class="form-label small">No WhatsApp</label>
          <input type="text" id="editTiketNowa" class="form-control form-control-sm">
        </div>
        <div class="mb-2">
          <label class="form-label small">Keterangan / Kendala</label>
          <textarea id="editTiketKeterangan" class="form-control form-control-sm" rows="3"></textarea>
        </div>
        <div class="mb-2">
          <label class="form-label small">Status</label>
          <select id="editTiketStatus" class="form-select form-select-sm">
            <option value="BARU">BARU</option>
            <option value="PENDING">PENDING</option>
            <option value="DONE">DONE</option>
            <option value="CANCEL">CANCEL</option>
          </select>
        </div>
        <div id="editTiketAlert"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
        <button type="button" class="btn btn-primary btn-sm" id="btnSimpanEditTiket">Simpan</button>
      </div>
    </div>
  </div>
</div>

<script>
  const internetStatusData = {};
  const internetStatusTitles = {
    total_users: 'Total Users',
    internet_online: 'Internet Online',
    internet_los: 'Internet Los',
    expired_online: 'Expired Online',
    expired_los: 'Expired Los',
    instalasi_bulan_ini: 'Pelanggan Instalasi Bulan Ini',
    berhenti_bulan_ini: 'Pelanggan Berhenti Bulan Ini'
  };
  let currentInternetStatusType = 'internet_online';
  let isInternetStatusLoading = false;

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function normalizeInternetStatusRows(type) {
    const rows = Array.isArray(internetStatusData[type]) ? internetStatusData[type] : [];
    return rows.map(item => ({
      IDPEL: item.IDPEL || '',
      NAMA: item.NAMA || '',
      PAKET: item.PAKET || '',
      AREA: item.AREA || '',
      ODP: item.ODP || '',
      NOWA: item.NOWA || '',
      ALAMAT: item.ALAMAT || '',
      TANGGAL_BAYAR_TERAKHIR: item.TANGGAL_BAYAR_TERAKHIR || '',
      HARI_LEWAT_JATUH_TEMPO: Number(item.HARI_LEWAT_JATUH_TEMPO || 0),
      BUKTI_TERAKHIR: item.BUKTI_TERAKHIR || '',
      BUKTI_TERAKHIR_IMAGE_URL: item.BUKTI_TERAKHIR_IMAGE_URL || ''
    }));
  }

  function getTanggalBayarDisplay(row) {
    const isExpiredType = currentInternetStatusType === 'expired_online' || currentInternetStatusType === 'expired_los';
    const overdueDays = Number(row.HARI_LEWAT_JATUH_TEMPO || 0);
    if (isExpiredType && overdueDays > 0) {
      return overdueDays + ' hari lewat jatuh tempo';
    }
    return row.TANGGAL_BAYAR_TERAKHIR || '-';
  }

  function renderInternetStatusLoading(message) {
    const tbody = document.querySelector('#internetStatusTable tbody');
    if (!tbody) return;
    const safeMessage = escapeHtml(message || 'Memuat data pelanggan...');
    tbody.innerHTML = `
      <tr>
        <td colspan="10" class="text-center py-4">
          <div class="d-inline-flex flex-column align-items-center gap-2">
            <div class="spinner-border text-primary" role="status" aria-hidden="true"></div>
            <div class="small text-muted">${safeMessage}</div>
          </div>
        </td>
      </tr>
    `;
    const counter = document.getElementById('internetStatusCounter');
    if (counter) {
      counter.textContent = 'Memuat data...';
    }
  }

  function openCustomerDetailByPost(idpel) {
    const idpelValue = String(idpel || '').trim();
    if (!idpelValue) {
      return;
    }

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'tables.php';

    const actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'action';
    actionInput.value = 'cari_global';

    const cariInput = document.createElement('input');
    cariInput.type = 'hidden';
    cariInput.name = 'cariglobal';
    cariInput.value = idpelValue;

    form.appendChild(actionInput);
    form.appendChild(cariInput);
    document.body.appendChild(form);
    form.submit();
  }

  async function loadInternetStatusRows(type) {
    if (isInternetStatusLoading) {
      return;
    }

    isInternetStatusLoading = true;
    renderInternetStatusLoading('Memuat data pelanggan...');

    try {
      const response = await fetch(`getdata/get_internet_status_modal.php?type=${encodeURIComponent(type)}&_=${Date.now()}`, {
        cache: 'no-store',
        credentials: 'same-origin'
      });

      const payload = await response.json();
      if (!response.ok || !payload || payload.success !== true) {
        throw new Error('Gagal mengambil data detail status internet');
      }

      internetStatusData[type] = Array.isArray(payload.data) ? payload.data : [];
    } catch (error) {
      console.error(error);
      internetStatusData[type] = [];
      const tbody = document.querySelector('#internetStatusTable tbody');
      if (tbody) {
        tbody.innerHTML = '<tr><td colspan="10" class="text-center text-danger">Gagal mengambil data detail.</td></tr>';
      }
      const counter = document.getElementById('internetStatusCounter');
      if (counter) {
        counter.textContent = '0 data ditampilkan';
      }
    } finally {
      isInternetStatusLoading = false;
    }
  }

  function populateAreaFilter(rows) {
    const areaSelect = document.getElementById('internetStatusAreaFilter');
    if (!areaSelect) return;

    const areas = [...new Set(rows.map(row => String(row.AREA || '').trim()).filter(Boolean))].sort((a, b) => a.localeCompare(b));
    const currentValue = areaSelect.value || '';

    areaSelect.innerHTML = '<option value="">Semua Area</option>';
    areas.forEach(area => {
      const option = document.createElement('option');
      option.value = area;
      option.textContent = area;
      areaSelect.appendChild(option);
    });

    if (areas.includes(currentValue)) {
      areaSelect.value = currentValue;
    }
  }

  function populateOdpFilter(rows) {
    const odpSelect = document.getElementById('internetStatusOdpFilter');
    if (!odpSelect) return;

    const odps = [...new Set(rows.map(row => String(row.ODP || '').trim()).filter(Boolean))].sort((a, b) => a.localeCompare(b));
    const currentValue = odpSelect.value || '';

    odpSelect.innerHTML = '<option value="">Semua ODP</option>';
    odps.forEach(odp => {
      const option = document.createElement('option');
      option.value = odp;
      option.textContent = odp;
      odpSelect.appendChild(option);
    });

    if (odps.includes(currentValue)) {
      odpSelect.value = currentValue;
    }
  }

  function getFilteredInternetStatusRows() {
    const keyword = (document.getElementById('internetStatusSearch')?.value || '').toLowerCase().trim();
    const areaValue = (document.getElementById('internetStatusAreaFilter')?.value || '').trim();
    const odpValue = (document.getElementById('internetStatusOdpFilter')?.value || '').trim();
    const rows = normalizeInternetStatusRows(currentInternetStatusType);

    return rows.filter(row => {
      if (areaValue && String(row.AREA || '').trim() !== areaValue) {
        return false;
      }

      if (odpValue && String(row.ODP || '').trim() !== odpValue) {
        return false;
      }

      if (!keyword) {
        return true;
      }

      const blob = [row.IDPEL, row.NAMA, row.PAKET, row.AREA, row.ODP, row.NOWA, row.ALAMAT, row.TANGGAL_BAYAR_TERAKHIR, row.BUKTI_TERAKHIR]
        .join(' ')
        .toLowerCase();
      return blob.includes(keyword);
    });
  }

  function renderInternetStatusTable() {
    const tbody = document.querySelector('#internetStatusTable tbody');
    if (!tbody) return;

    const filteredRows = getFilteredInternetStatusRows();
    tbody.innerHTML = '';

    if (!filteredRows.length) {
      tbody.innerHTML = '<tr><td colspan="10" class="text-center text-muted">Tidak ada data pelanggan pada filter ini.</td></tr>';
    } else {
      filteredRows.forEach(row => {
        const tr = document.createElement('tr');
        const buktiImage = row.BUKTI_TERAKHIR_IMAGE_URL
          ? `<div class="mt-1"><img src="${escapeHtml(row.BUKTI_TERAKHIR_IMAGE_URL)}" alt="Bukti Terakhir" class="img-fluid rounded border" style="max-width:120px;max-height:120px;" onerror="this.style.display='none'"></div>`
          : '';
        tr.innerHTML = `
          <td>
            <button type="button" class="btn btn-primary btn-sm" onclick="openCustomerDetailByPost('${escapeHtml(row.IDPEL)}')">Detail Pelanggan</button>
          </td>
          <td>${escapeHtml(row.IDPEL)}</td>
          <td>${escapeHtml(row.NAMA)}</td>
          <td>${escapeHtml(row.PAKET)}</td>
          <td>${escapeHtml(row.AREA)}</td>
          <td>${escapeHtml(row.ODP)}</td>
          <td>${escapeHtml(row.NOWA)}</td>
          <td>${escapeHtml(getTanggalBayarDisplay(row))}</td>
          <td>
            ${escapeHtml(row.BUKTI_TERAKHIR || '-')}
            ${buktiImage}
          </td>
          <td>${escapeHtml(row.ALAMAT)}</td>
        `;
        tbody.appendChild(tr);
      });
    }

    const counter = document.getElementById('internetStatusCounter');
    if (counter) {
      counter.textContent = filteredRows.length + ' data ditampilkan';
    }
  }

  function exportInternetStatusExcel() {
    const rows = getFilteredInternetStatusRows();
    if (!rows.length) {
      alert('Data kosong, tidak ada yang bisa diexport.');
      return;
    }

    const headers = ['IDPEL', 'Nama', 'Paket', 'Area', 'ODP', 'No WA', 'Tanggal Bayar Terakhir', 'Bukti Terakhir', 'Alamat'];
    const lines = [headers.join('\t')];
    rows.forEach(row => {
      lines.push([
        row.IDPEL,
        row.NAMA,
        row.PAKET,
        row.AREA,
        row.ODP,
        row.NOWA,
        getTanggalBayarDisplay(row),
        row.BUKTI_TERAKHIR,
        row.ALAMAT
      ].map(value => String(value || '').replace(/\t|\n|\r/g, ' ')).join('\t'));
    });

    const blob = new Blob(['\ufeff' + lines.join('\n')], { type: 'application/vnd.ms-excel;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    const now = new Date();
    const stamp = now.toISOString().slice(0, 19).replace(/[-:T]/g, '');
    link.href = url;
    link.download = 'detail_' + currentInternetStatusType + '_' + stamp + '.xls';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
  }

  function exportInternetStatusPdf() {
    const rows = getFilteredInternetStatusRows();
    if (!rows.length) {
      alert('Data kosong, tidak ada yang bisa diexport.');
      return;
    }

    const title = internetStatusTitles[currentInternetStatusType] || 'Detail Status Internet';
    const nowLabel = new Date().toLocaleString('id-ID');
    const tableRows = rows.map(row => `
      <tr>
        <td>${escapeHtml(row.IDPEL)}</td>
        <td>${escapeHtml(row.NAMA)}</td>
        <td>${escapeHtml(row.PAKET)}</td>
        <td>${escapeHtml(row.AREA)}</td>
        <td>${escapeHtml(row.ODP)}</td>
        <td>${escapeHtml(row.NOWA)}</td>
        <td>${escapeHtml(getTanggalBayarDisplay(row))}</td>
        <td>${escapeHtml(row.BUKTI_TERAKHIR || '-')}</td>
        <td>${escapeHtml(row.ALAMAT)}</td>
      </tr>
    `).join('');

    const html = `
      <html>
      <head>
        <title>${title}</title>
        <style>
          body { font-family: Arial, sans-serif; padding: 16px; color: #111827; }
          h3 { margin: 0 0 8px 0; }
          .meta { margin-bottom: 12px; color: #6b7280; font-size: 12px; }
          table { width: 100%; border-collapse: collapse; font-size: 12px; }
          th, td { border: 1px solid #d1d5db; padding: 6px; text-align: left; vertical-align: top; }
          th { background: #f3f4f6; }
        </style>
      </head>
      <body>
        <h3>${title}</h3>
        <div class="meta">Dicetak: ${nowLabel} | Jumlah data: ${rows.length}</div>
        <table>
          <thead>
            <tr>
              <th>IDPEL</th>
              <th>Nama</th>
              <th>Paket</th>
              <th>Area</th>
              <th>ODP</th>
              <th>No WA</th>
              <th>Tanggal Bayar Terakhir</th>
              <th>Bukti Terakhir</th>
              <th>Alamat</th>
            </tr>
          </thead>
          <tbody>${tableRows}</tbody>
        </table>
      </body>
      </html>
    `;

    const win = window.open('', '_blank');
    if (!win) {
      alert('Popup diblokir browser. Izinkan popup untuk export PDF.');
      return;
    }
    win.document.open();
    win.document.write(html);
    win.document.close();
    win.focus();
    setTimeout(() => win.print(), 300);
  }

  async function openInternetStatusModal(type) {
    currentInternetStatusType = type;
    const titleEl = document.getElementById('internetStatusModalLabel');
    if (titleEl) {
      titleEl.textContent = 'Detail Pelanggan - ' + (internetStatusTitles[type] || 'Status Internet');
    }

    const searchInput = document.getElementById('internetStatusSearch');
    const areaSelect = document.getElementById('internetStatusAreaFilter');
    const odpSelect = document.getElementById('internetStatusOdpFilter');
    if (searchInput) searchInput.value = '';
    if (areaSelect) areaSelect.value = '';
    if (odpSelect) odpSelect.value = '';

    const modalEl = document.getElementById('internetStatusModal');
    const modal = new bootstrap.Modal(modalEl);
    modal.show();

    await loadInternetStatusRows(type);
    const latestRows = normalizeInternetStatusRows(type);
    populateAreaFilter(latestRows);
    populateOdpFilter(latestRows);
    renderInternetStatusTable();
  }

  function renderSlaRows(rows) {
    const tbody = document.querySelector('#slaStatusTable tbody');
    if (!tbody) return;

    tbody.innerHTML = '';
    if (!Array.isArray(rows) || rows.length === 0) {
      tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">Belum ada data SLA 30 hari.</td></tr>';
      const counter = document.getElementById('slaStatusCounter');
      if (counter) counter.textContent = '0 server';
      return;
    }

    rows.forEach(function (row) {
      const statusText = (row.last_status || 'UNKNOWN').toUpperCase();
      const statusClass = statusText === 'ONLINE' ? 'text-success' : (statusText === 'OFFLINE' ? 'text-danger' : 'text-secondary');
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${escapeHtml(row.pemilik || '-')}</td>
        <td>${escapeHtml(row.area || '-')}</td>
        <td>${escapeHtml(row.ip || '-')}</td>
        <td>${escapeHtml((Number(row.sla_percent || 0)).toFixed(2))}%</td>
        <td>${escapeHtml(row.uptime_human || '0 menit')}</td>
        <td>${escapeHtml(row.last_check || '-')}</td>
        <td class="${statusClass}">${escapeHtml(statusText)}</td>
      `;
      tbody.appendChild(tr);
    });

    const counter = document.getElementById('slaStatusCounter');
    if (counter) {
      counter.textContent = rows.length + ' server';
    }
  }

  async function loadSlaSummary() {
    try {
      const response = await fetch('getdata/get_server_sla.php?mode=summary&_=' + Date.now(), {
        cache: 'no-store',
        credentials: 'same-origin'
      });
      const payload = await response.json();
      if (!response.ok || !payload || payload.success !== true) {
        throw new Error('Gagal mengambil SLA summary');
      }

      const target = document.getElementById('slaTotalPercent');
      if (target) {
        target.textContent = Number(payload.total_sla_percent || 0).toFixed(2) + '%';
      }
    } catch (err) {
      console.error(err);
      const target = document.getElementById('slaTotalPercent');
      if (target) {
        target.textContent = '0.00%';
      }
    }
  }

  async function openSlaModal() {
    const tbody = document.querySelector('#slaStatusTable tbody');
    if (tbody) {
      tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">Memuat data SLA...</td></tr>';
    }

    const modalEl = document.getElementById('slaStatusModal');
    const modal = new bootstrap.Modal(modalEl);
    modal.show();

    try {
      const response = await fetch('getdata/get_server_sla.php?mode=detail&_=' + Date.now(), {
        cache: 'no-store',
        credentials: 'same-origin'
      });
      const payload = await response.json();
      if (!response.ok || !payload || payload.success !== true) {
        throw new Error('Gagal mengambil detail SLA');
      }
      renderSlaRows(Array.isArray(payload.data) ? payload.data : []);
    } catch (err) {
      console.error(err);
      if (tbody) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-danger">Gagal memuat data SLA.</td></tr>';
      }
      const counter = document.getElementById('slaStatusCounter');
      if (counter) counter.textContent = '0 server';
    }
  }

  window.openSlaModal = openSlaModal;

  document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('internetStatusSearch');
    const areaFilter = document.getElementById('internetStatusAreaFilter');
    const odpFilter = document.getElementById('internetStatusOdpFilter');
    const btnExcel = document.getElementById('btnExportInternetExcel');
    const btnPdf = document.getElementById('btnExportInternetPdf');

    if (searchInput) {
      searchInput.addEventListener('input', renderInternetStatusTable);
    }
    if (areaFilter) {
      areaFilter.addEventListener('change', renderInternetStatusTable);
    }
    if (odpFilter) {
      odpFilter.addEventListener('change', renderInternetStatusTable);
    }
    if (btnExcel) {
      btnExcel.addEventListener('click', exportInternetStatusExcel);
    }
    if (btnPdf) {
      btnPdf.addEventListener('click', exportInternetStatusPdf);
    }

    loadSlaSummary();
  });
</script>









<script>
// Ambil server list dari PHP
const servers = <?php echo json_encode($userServers); ?>;
console.log("DEBUG servers:", servers);

// Pastikan servers array
if (!Array.isArray(servers)) {
  console.error("servers bukan array:", servers);
}

// Panggil endpoint
const url = "getdata/count_tiket.php?servers=" + encodeURIComponent(JSON.stringify(servers));
fetch(url)
  .then(response => response.json())
  .then(data => {
    console.log("DEBUG data dari server:", data);
    
    // Update instalasi
    document.getElementById("totalInstalasi").textContent = data?.instalasi_total ?? 0;

    

document.getElementById('dismantel').textContent = data?.dismantel_total ?? 0;
document.getElementById('maintenance').textContent = data?.maintenance_total ?? 0;
document.getElementById('migrasi').textContent = data?.migrasi_total ?? 0;

  })
  .catch(err => {
    console.error("Fetch error:", err);
    document.getElementById("totalInstalasi").textContent = "-";
    document.getElementById("dismantel").textContent = "-";
    document.getElementById("maintenance").textContent = "-";
    document.getElementById("migrasi").textContent = "-";

  });
</script>

<script>
  const ticketTypeLabels = {
    INSTALLASI: 'Instalasi',
    DISMANTLE: 'Dismantle',
    MAINTENANCE: 'Maintenance',
    MIGRASI: 'Migrasi'
  };
  let currentTicketType = 'INSTALLASI';
  let currentTicketRows = [];
  let ticketTypeSearchTimer = null;

  function renderTicketTypeRows(rows) {
    currentTicketRows = Array.isArray(rows) ? rows : [];
    const tbody = document.querySelector('#ticketTypeTable tbody');
    if (!tbody) return;

    if (currentTicketRows.length === 0) {
      tbody.innerHTML = '<tr><td colspan="10" class="text-center text-muted">Tidak ada tiket ' + ticketTypeLabels[currentTicketType] + ' yang cocok.</td></tr>';
    } else {
      tbody.innerHTML = currentTicketRows.map(function (row, idx) {
        return '<tr>' +
          '<td><input type="checkbox" class="ticket-row-check" data-idx="' + idx + '" onchange="updateJumlahTerpilih()"></td>' +
          '<td>' + escapeHtml(row.judul || '-') + '</td>' +
          '<td>' + escapeHtml(row.nama || '-') + '</td>' +
          '<td>' + escapeHtml(row.nowa || '-') + '</td>' +
          '<td>' + escapeHtml(row.keterangan || '-') + '</td>' +
          '<td>' + escapeHtml(row.project || '-') + (row.area ? ' / ' + escapeHtml(row.area) : '') + '</td>' +
          '<td>' + escapeHtml(row.petugas || '-') + '</td>' +
          '<td>' + escapeHtml(row.status || '-') + '</td>' +
          '<td>' + escapeHtml(row.tanggal || '-') + '</td>' +
          '<td class="text-nowrap">' +
            '<button type="button" class="btn btn-outline-primary btn-sm py-0 px-1" onclick="openEditTiketModal(' + idx + ')" title="Edit"><i class="fas fa-pen"></i></button> ' +
            '<button type="button" class="btn btn-outline-danger btn-sm py-0 px-1" onclick="hapusTiketDashboard(' + idx + ')" title="Hapus"><i class="fas fa-trash"></i></button>' +
          '</td>' +
          '</tr>';
      }).join('');
    }

    const counter = document.getElementById('ticketTypeCounter');
    if (counter) counter.textContent = currentTicketRows.length + ' tiket';
    updateJumlahTerpilih();
  }

  function updateJumlahTerpilih() {
    const checks = document.querySelectorAll('#ticketTypeTable tbody .ticket-row-check');
    const checked = document.querySelectorAll('#ticketTypeTable tbody .ticket-row-check:checked');
    const btn = document.getElementById('btnHapusTerpilih');
    const label = document.getElementById('jumlahTerpilih');
    if (label) label.textContent = checked.length;
    if (btn) btn.disabled = checked.length === 0;

    const selectAll = document.getElementById('ticketTypeSelectAll');
    if (selectAll) {
      selectAll.checked = checks.length > 0 && checked.length === checks.length;
      selectAll.indeterminate = checked.length > 0 && checked.length < checks.length;
    }
  }

  function toggleSelectAllTiket(checkboxEl) {
    document.querySelectorAll('#ticketTypeTable tbody .ticket-row-check').forEach(function (cb) {
      cb.checked = checkboxEl.checked;
    });
    updateJumlahTerpilih();
  }

  async function bulkHapusTiketDashboard() {
    const checked = Array.from(document.querySelectorAll('#ticketTypeTable tbody .ticket-row-check:checked'));
    if (checked.length === 0) return;

    const selectedRows = checked
      .map(function (cb) { return currentTicketRows[parseInt(cb.getAttribute('data-idx'), 10)]; })
      .filter(Boolean);

    if (!confirm('Yakin hapus ' + selectedRows.length + ' tiket terpilih? Tindakan ini tidak bisa dibatalkan.')) return;

    const btn = document.getElementById('btnHapusTerpilih');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Menghapus...'; }

    let sukses = 0;
    let gagal = 0;
    for (const row of selectedRows) {
      try {
        const fd = new FormData();
        fd.append('id', row.id);
        fd.append('source', row.source);
        const res = await fetch('getdata/delete_tiket_dashboard.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await res.json();
        if (data && data.success) { sukses++; } else { gagal++; }
      } catch (err) {
        gagal++;
      }
    }

    alert('Selesai. Berhasil dihapus: ' + sukses + (gagal > 0 ? ', Gagal: ' + gagal : ''));
    loadTicketTypeRows(currentTicketType);
  }

  async function loadTicketTypeRows(tipe) {
    const tbody = document.querySelector('#ticketTypeTable tbody');
    if (tbody) tbody.innerHTML = '<tr><td colspan="10" class="text-center text-muted">Memuat data...</td></tr>';

    const statusEl = document.getElementById('ticketTypeStatusFilter');
    const searchEl = document.getElementById('ticketTypeSearch');
    const status = statusEl ? statusEl.value : '';
    const search = searchEl ? searchEl.value.trim() : '';

    try {
      const url = 'getdata/list_tiket_by_tipe.php?tipe=' + encodeURIComponent(tipe) +
        '&servers=' + encodeURIComponent(JSON.stringify(servers)) +
        '&status=' + encodeURIComponent(status) +
        '&search=' + encodeURIComponent(search);
      const response = await fetch(url, { credentials: 'same-origin' });
      const payload = await response.json();
      if (!payload || payload.success !== true) {
        throw new Error('Gagal mengambil data tiket');
      }
      const sourceEl = document.getElementById('ticketTypeSource');
      if (sourceEl) {
        sourceEl.textContent = payload.source === 'joblist' ? 'Joblist' : 'Tiket Manager';
      }
      renderTicketTypeRows(payload.rows || []);
    } catch (err) {
      console.error(err);
      if (tbody) tbody.innerHTML = '<tr><td colspan="10" class="text-center text-danger">Gagal memuat data tiket.</td></tr>';
    }
  }

  function switchTicketTypeTab(tipe) {
    currentTicketType = tipe;
    document.querySelectorAll('.ticket-type-tab').forEach(function (btn) {
      btn.classList.toggle('active', btn.getAttribute('data-tipe') === tipe);
    });
    const titleEl = document.getElementById('ticketTypeModalLabel');
    if (titleEl) titleEl.textContent = 'Detail Tiket - ' + (ticketTypeLabels[tipe] || tipe);
    loadTicketTypeRows(tipe);
  }

  function openTicketTypeModal(tipe) {
    const modalEl = document.getElementById('ticketTypeModal');
    const modal = new bootstrap.Modal(modalEl);
    modal.show();
    switchTicketTypeTab(tipe || 'INSTALLASI');
  }

  document.addEventListener('DOMContentLoaded', function () {
    const statusEl = document.getElementById('ticketTypeStatusFilter');
    const searchEl = document.getElementById('ticketTypeSearch');
    if (statusEl) {
      statusEl.addEventListener('change', function () { loadTicketTypeRows(currentTicketType); });
    }
    if (searchEl) {
      searchEl.addEventListener('input', function () {
        clearTimeout(ticketTypeSearchTimer);
        ticketTypeSearchTimer = setTimeout(function () { loadTicketTypeRows(currentTicketType); }, 400);
      });
    }

    const btnSimpanEdit = document.getElementById('btnSimpanEditTiket');
    if (btnSimpanEdit) {
      btnSimpanEdit.addEventListener('click', simpanEditTiketDashboard);
    }
  });

  let editTiketModalInstance = null;
  function openEditTiketModal(idx) {
    const row = currentTicketRows[idx];
    if (!row) return;
    document.getElementById('editTiketId').value = row.id;
    document.getElementById('editTiketSource').value = row.source;
    document.getElementById('editTiketNama').value = row.nama || '';
    document.getElementById('editTiketNowa').value = row.nowa || '';
    document.getElementById('editTiketKeterangan').value = row.keterangan || '';
    document.getElementById('editTiketStatus').value = row.status || 'BARU';
    document.getElementById('editTiketAlert').innerHTML = '';
    document.getElementById('editTiketModalLabel').textContent = 'Edit Tiket - ' + (row.judul || '#' + row.id);

    if (!editTiketModalInstance) {
      editTiketModalInstance = new bootstrap.Modal(document.getElementById('editTiketModal'));
    }
    editTiketModalInstance.show();
  }

  function simpanEditTiketDashboard() {
    const alertBox = document.getElementById('editTiketAlert');
    const btn = document.getElementById('btnSimpanEditTiket');
    btn.disabled = true;

    const fd = new FormData();
    fd.append('id', document.getElementById('editTiketId').value);
    fd.append('source', document.getElementById('editTiketSource').value);
    fd.append('nama', document.getElementById('editTiketNama').value);
    fd.append('nowa', document.getElementById('editTiketNowa').value);
    fd.append('keterangan', document.getElementById('editTiketKeterangan').value);
    fd.append('status', document.getElementById('editTiketStatus').value);

    fetch('getdata/update_tiket_dashboard.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res.success) {
          alertBox.innerHTML = '<div class="alert alert-danger py-2 mb-0">' + (res.message || 'Gagal menyimpan.') + '</div>';
          return;
        }
        if (editTiketModalInstance) editTiketModalInstance.hide();
        loadTicketTypeRows(currentTicketType);
      })
      .catch(function (err) {
        alertBox.innerHTML = '<div class="alert alert-danger py-2 mb-0">Error: ' + err.message + '</div>';
      })
      .finally(function () { btn.disabled = false; });
  }

  function hapusTiketDashboard(idx) {
    const row = currentTicketRows[idx];
    if (!row) return;
    if (!confirm('Yakin hapus tiket "' + (row.judul || '#' + row.id) + '"? Tindakan ini tidak bisa dibatalkan.')) return;

    const fd = new FormData();
    fd.append('id', row.id);
    fd.append('source', row.source);

    fetch('getdata/delete_tiket_dashboard.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res.success) {
          alert(res.message || 'Gagal menghapus tiket.');
          return;
        }
        loadTicketTypeRows(currentTicketType);
      })
      .catch(function (err) { alert('Error: ' + err.message); });
  }
</script>



























            </div>
          </div>

          <div>
            <style>
            .card-box {
              color: white;
              padding: 10px;
              border-radius: 8px;
              font-size: 0.85rem;
            }

            .card-icon {
              font-size: 18px;
              margin-right: 8px;
            }

            .bg-purple {
              background-color: #6f42c1;
            }

            .bg-orange {
              background-color: #fd7e14;
            }

            .bg-blue {
              background-color: #007bff;
            }

            .bg-green {
              background-color: #28a745;
            }

            .bg-red {
              background-color: #dc3545;
            }
          </style>

          </div>

          <?php
          $paket_harga_pppoe = [];
          $paket_harga_hotspot = [];
          $total_pppoe = 0;
          $total_hotspot = 0;

          // Ambil harga dari paket PPPoE
          $paket_q = mysqli_query($conn, "SELECT * FROM `paket`");
          while ($p = mysqli_fetch_assoc($paket_q)) {
            $paket_harga_pppoe[$p['PAKET']] = [
              'harga' => $p['HARGA'],
              'area' => $p['AREA'],
              'pemilik' => $p['PEMILIK']
            ];
          }

          // Ambil harga dari paket Hotspot
          $hotspot_q = mysqli_query($conn, "SELECT * FROM `paket_hotspot`");
          while ($p = mysqli_fetch_assoc($hotspot_q)) {
            $paket_harga_hotspot[$p['paket']] = [
              'harga' => $p['harga'],
              'area' => $p['area'],
              'pemilik' => $p['pemilik']
            ];
          }

          ?>



          <?php
          $jumlah_paket_pppoe = 0;
          $user_aktif_pppoe = 0;
          foreach ($pppoe_profiles as $profile => $count) {
            if (strtoupper($profile) === 'EXPIRED') continue;
            $harga = $paket_harga_pppoe[$profile]['harga'] ?? 0;
            $total_pppoe += $harga * $count;
            $user_aktif_pppoe += $count;
            $jumlah_paket_pppoe++;
          }

          $jumlah_paket_hotspot = 0;
          $user_aktif_hotspot = 0;
          foreach ($hotspot_profiles as $profile => $count) {
            $harga = $paket_harga_hotspot[$profile]['harga'] ?? 0;
            $total_hotspot += $harga * $count;
            $user_aktif_hotspot += $count;
            $jumlah_paket_hotspot++;
          }
          ?>

          <div class="row g-3 mt-1">
            <div class="col-md-6 col-sm-6 col-12" data-dashboard-card="tabel_user_pppoe">
              <div class="card-box d-flex align-items-center small"
                   style="background: linear-gradient(135deg, #6f42c1, #59339d); color: white; padding: 10px; border-radius: 8px;">
                <i class="fas fa-network-wired card-icon" style="font-size: 1.2em; margin-right: 8px;"></i>
                <div style="font-size: 0.85em;">
                  <h6 class="mb-1 font-weight-bold" style="text-shadow: 1px 1px 2px rgba(0,0,0,0.3); font-size: 0.95em;"><?= $jumlah_paket_pppoe ?></h6>
                  <p class="mb-0" style="font-size: 0.8em;">📡 Total Paket PPPoE</p>
                </div>
              </div>
            </div>

            <div class="col-md-6 col-sm-6 col-12" data-dashboard-card="tabel_user_hotspot">
              <div class="card-box d-flex align-items-center small"
                   style="background: linear-gradient(135deg, #fd7e14, #d9670a); color: white; padding: 10px; border-radius: 8px;">
                <i class="fas fa-wifi card-icon" style="font-size: 1.2em; margin-right: 8px;"></i>
                <div style="font-size: 0.85em;">
                  <h6 class="mb-1 font-weight-bold" style="text-shadow: 1px 1px 2px rgba(0,0,0,0.3); font-size: 0.95em;"><?= $jumlah_paket_hotspot ?></h6>
                  <p class="mb-0" style="font-size: 0.8em;">📡 Total Paket Hotspot</p>
                </div>
              </div>
            </div>
          </div>


          <div class="card-footer bg-transparent border-0">
            <div class="mb-0" role="alert" style="background-color: #f59e0b; color: #ffffff; border: 1px solid #f59e0b; padding: .65rem 1rem; border-radius: .375rem;">
              <strong>💰 Estimate income:</strong>
              <span class="float-end fw-bold">Rp <?= number_format($total_pppoe + $total_hotspot, 0, ',', '.') ?></span>
            </div>
          </div>

          <div id="mapViewAnchor" style="display:none"></div>
          <div id="view" data-dashboard-card="map_view" class="mt-3">
            <h5 class="mb-3">🗺️ Customer and ODP Maps</h5>
            <div class="mb-3 d-flex justify-content-between flex-wrap gap-2">
              <div style="flex: 1; max-width: 300px;">
                <label for="odpFilter" class="form-label fw-bold">Filter ODP:</label>
                <div class="overlay">
                  <select id="odpFilter" class="form-select">
                    <option value="">-- Semua ODP --</option>
                  </select>
                  <span id="loading" style="display:none;"></span>
                  <span id="timer">30</span> Auto reload
                </div>
              </div>
              <button id="fullViewBtn" class="btn btn-outline-primary align-self-end">fullscreen</button>
            </div>
            <div id="map"></div>
          </div>

          <!-- Modal fullscreen untuk Customer and ODP Maps -->
          <div class="modal fade" id="mapFullscreenModal" tabindex="-1" aria-labelledby="mapFullscreenModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-fullscreen">
              <div class="modal-content">
                <div class="modal-header py-2">
                  <h5 class="modal-title" id="mapFullscreenModalLabel">🗺️ Customer and ODP Maps</h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-3" id="mapFullscreenModalBody"></div>
              </div>
            </div>
          </div>



















        </div>
      </div>

      <!-- Card kanan - col-4 -->
      <div class="col-md-4 mb-4 d-flex flex-column gap-3 dashboard-right-col" data-dashboard-card="log_mikrotik">
        <div class="card shadow-lg dashboard-right-main-card">
          <div class="card-header dashboard-dark-header dashboard-side-header bg-primary text-white d-flex justify-content-between align-items-center" style="padding: 12px 15px;">
            <span style="font-weight: 600;"><i class="fas fa-list-alt me-2"></i>System Log</span>
            <button id="clearLogBtn" class="btn btn-danger btn-sm" style="font-weight: 600;">Clear Log</button>
          </div>
         <div class="card-body log-container">
            <div class="mb-2">
              <input type="text" id="logFilterInput" class="form-control form-control-sm" placeholder="🔍 Filter..." style="font-size: 0.80em; padding: 0.25rem 0.5rem;">
            </div>
            <ul id="logList" class="list-group list-group-flush" style="font-size: 0.80em;">
              <li class="list-group-item text-muted" style="font-size: 0.80em; padding: 0.5rem; background-color: #f8f9fa; border: 1px solid #e9ecef; border-radius: 4px; margin-bottom: 0.3rem;">Memuat log...<?php echo $username ?></li>
            </ul>
          </div>
          <style>
            .log-container .list-group-item {
              background-color: #f8f9fa;
              border: 1px solid #e9ecef;
              border-radius: 4px;
              padding: 0.5rem 0.75rem !important;
              margin-bottom: 0.3rem;
              font-size: 0.80em;
              line-height: 1.4;
              transition: all 0.2s ease;
            }
            
            .log-container .list-group-item:hover {
              background-color: #e8f0fe;
              border-color: #2563eb;
              transform: translateX(2px);
            }
            
            .log-container .list-group-item.text-muted {
              color: #6c757d !important;
            }
            
            .log-container .list-group-item br {
              margin: 0.2rem 0;
            }

            /* Dark Mode Support for Log Container */
            body.app-theme-dark .log-container .list-group-item {
              background-color: #1a233a !important;
              border: 1px solid rgba(59, 130, 246, 0.2) !important;
              color: #e2e8f0 !important;
            }

            body.app-theme-dark .log-container .list-group-item:hover {
              background-color: rgba(59, 130, 246, 0.15) !important;
              border-color: #3b82f6 !important;
              color: #f1f5f9 !important;
            }

            body.app-theme-dark .log-container .list-group-item.text-muted {
              color: #cbd5e1 !important;
            }

            /* Dark mode scrollbar for log-container */
            body.app-theme-dark .dashboard-right-main-card .log-container::-webkit-scrollbar-track {
              background: #1f2937;
            }

            body.app-theme-dark .dashboard-right-main-card .log-container::-webkit-scrollbar-thumb {
              background: #475569;
            }

            body.app-theme-dark .dashboard-right-main-card .log-container::-webkit-scrollbar-thumb:hover {
              background: #64748b;
            }

            body.app-theme-dark .card > .card-header,
            body.app-theme-dark .card .card-header {
              background: #1f2937 !important;
              color: #e5e7eb !important;
              border-bottom: 1px solid rgba(148, 163, 184, 0.22) !important;
            }

            body.app-theme-dark .card .card-header h1,
            body.app-theme-dark .card .card-header h2,
            body.app-theme-dark .card .card-header h3,
            body.app-theme-dark .card .card-header h4,
            body.app-theme-dark .card .card-header h5,
            body.app-theme-dark .card .card-header h6,
            body.app-theme-dark .card .card-header span,
            body.app-theme-dark .card .card-header p,
            body.app-theme-dark .card .card-header i,
            body.app-theme-dark .card .card-header label,
            body.app-theme-dark .card .card-header strong,
            body.app-theme-dark .card .card-header small {
              color: #e5e7eb !important;
            }

            body.app-theme-dark .card .card-header .text-muted,
            body.app-theme-dark .card .card-header .text-sm,
            body.app-theme-dark .card .card-header .text-white-50 {
              color: #cbd5e1 !important;
            }

            body.app-theme-dark .card .card-header .btn-secondary {
              background-color: #334155 !important;
              border-color: #475569 !important;
              color: #f8fafc !important;
            }

            body.app-theme-dark .card .card-header .btn-secondary:hover {
              background-color: #475569 !important;
              border-color: #64748b !important;
            }

            body.app-theme-dark .dashboard-dark-header,
            body.app-theme-dark .dashboard-side-header,
            body.app-theme-dark .dashboard-dark-header.bg-primary,
            body.app-theme-dark .dashboard-dark-header.bg-info {
              background: #1f2937 !important;
              background-image: none !important;
              color: #e5e7eb !important;
              border-bottom: 1px solid rgba(148, 163, 184, 0.22) !important;
              box-shadow: none !important;
            }

            body.app-theme-dark .dashboard-dark-header h1,
            body.app-theme-dark .dashboard-dark-header h2,
            body.app-theme-dark .dashboard-dark-header h3,
            body.app-theme-dark .dashboard-dark-header h4,
            body.app-theme-dark .dashboard-dark-header h5,
            body.app-theme-dark .dashboard-dark-header h6,
            body.app-theme-dark .dashboard-dark-header span,
            body.app-theme-dark .dashboard-dark-header p,
            body.app-theme-dark .dashboard-dark-header i,
            body.app-theme-dark .dashboard-dark-header strong,
            body.app-theme-dark .dashboard-dark-header small,
            body.app-theme-dark .dashboard-side-header .text-white-50,
            body.app-theme-dark .dashboard-dark-header .text-white-50 {
              color: #cbd5e1 !important;
            }

            body.app-theme-dark .dashboard-side-header .btn-danger,
            body.app-theme-dark .dashboard-dark-header .btn-danger {
              background-color: #b91c1c !important;
              border-color: #b91c1c !important;
            }

            /* Dark Mode Support for Metric Cards */
            body.app-theme-dark .fw-bold.text-primary,
            body.app-theme-dark .fw-bold.text-success,
            body.app-theme-dark .fw-bold.text-info,
            body.app-theme-dark .fw-bold.text-warning,
            body.app-theme-dark .fw-bold.text-danger {
              color: #e2e8f0 !important;
            }

            body.app-theme-dark [style*="color: #28a745"],
            body.app-theme-dark [style*="color: #17a2b8"],
            body.app-theme-dark [style*="color: #ffc107"],
            body.app-theme-dark [style*="color: #dc3545"] {
              color: #e2e8f0 !important;
            }

            /* Dark Mode Support for Estimate Income & Alert-like elements */
            body.app-theme-dark .card-footer {
              background-color: #0f172a !important;
            }

            body.app-theme-dark .card-footer [role="alert"],
            body.app-theme-dark [role="alert"] {
              background-color: #1a3a52 !important;
              color: #f1f5f9 !important;
              border-color: #2563eb !important;
            }

            body.app-theme-dark .card-footer [role="alert"] [style*="background-color"],
            body.app-theme-dark [role="alert"][style*="background-color"] {
              background-color: #1a3a52 !important;
              color: #f1f5f9 !important;
              border-color: #2563eb !important;
            }

            body.app-theme-dark .card-footer strong,
            body.app-theme-dark [role="alert"] strong {
              color: #f1f5f9 !important;
              font-weight: 700 !important;
            }

            body.app-theme-dark .card-footer span,
            body.app-theme-dark [role="alert"] span {
              color: #e2e8f0 !important;
            }

            /* Ensure all text in cards is visible in dark mode */
            body.app-theme-dark .card-footer .fw-bold,
            body.app-theme-dark [role="alert"] .fw-bold {
              color: #f1f5f9 !important;
            }

            /* Text-related utilities in dark mode */
            body.app-theme-dark .text-muted,
            body.app-theme-dark small,
            body.app-theme-dark .small {
              color: #cbd5e1 !important;
            }

            /* Ensure all strong tags are visible */
            body.app-theme-dark strong {
              color: #f1f5f9 !important;
            }

            /* Float end elements (right-aligned) */
            body.app-theme-dark .float-end {
              color: #f1f5f9 !important;
            }

            /* Icon colors in dark mode */
            body.app-theme-dark i.fa,
            body.app-theme-dark .fas {
              color: #e2e8f0 !important;
            }

            .dashboard-right-col {
              min-height: 100%;
              display: flex;
              flex-direction: column;
              gap: 0.75rem;
            }

            .dashboard-right-main-card {
              flex: 1 1 0;
              display: flex;
              flex-direction: column;
              min-height: 520px;
              overflow: hidden;
            }

            .dashboard-right-main-card .log-container {
              flex: 1 1 auto;
              min-height: 0 !important;
              max-height: 620px !important;
              overflow-y: auto !important;
              overflow-x: hidden;
              display: block !important;
              padding: 10px 12px !important;
            }

            .dashboard-right-main-card .log-container > div:first-child {
              margin-bottom: 10px;
              flex-shrink: 0;
            }

            .dashboard-right-main-card .log-container #logList {
              overflow-y: auto !important;
              max-height: calc(100% - 50px) !important;
              display: block;
            }

            .dashboard-right-main-card .log-container::-webkit-scrollbar {
              width: 8px;
            }

            .dashboard-right-main-card .log-container::-webkit-scrollbar-track {
              background: #f1f5f9;
              border-radius: 10px;
            }

            .dashboard-right-main-card .log-container::-webkit-scrollbar-thumb {
              background: #cbd5e1;
              border-radius: 10px;
            }

            .dashboard-right-main-card .log-container::-webkit-scrollbar-thumb:hover {
              background: #94a3b8;
            }

            .dashboard-right-main-card .card-body {
              flex: 1 1 auto;
              min-height: 0;
            }

            .server-online-log-container {
              height: 100% !important;
              max-height: 620px !important;
              overflow-y: auto !important;
            }

            .server-online-log-container::-webkit-scrollbar {
              width: 8px;
            }

            .server-online-log-container::-webkit-scrollbar-track {
              background: #f1f5f9;
              border-radius: 10px;
            }

            .server-online-log-container::-webkit-scrollbar-thumb {
              background: #cbd5e1;
              border-radius: 10px;
            }

            .server-online-log-container::-webkit-scrollbar-thumb:hover {
              background: #94a3b8;
            }

            body.app-theme-dark .server-online-log-container::-webkit-scrollbar-track {
              background: #1f2937;
            }

            body.app-theme-dark .server-online-log-container::-webkit-scrollbar-thumb {
              background: #475569;
            }

            body.app-theme-dark .server-online-log-container::-webkit-scrollbar-thumb:hover {
              background: #64748b;
            }

            .server-online-log-item {
              border: 1px solid #e5e7eb;
              border-left: 4px solid #2563eb;
              border-radius: 10px;
              padding: 0.65rem 0.75rem;
              background: linear-gradient(145deg, #ffffff, #f8fbff);
              margin-bottom: 0.55rem;
              transition: transform 0.15s ease, box-shadow 0.2s ease;
            }

            .server-online-log-item:hover {
              transform: translateY(-1px);
              box-shadow: 0 8px 16px rgba(15, 23, 42, 0.08);
            }

            .server-online-log-item .server-name {
              font-size: 0.8rem;
              font-weight: 700;
              color: #1f2937;
            }

            .server-online-log-item .server-stats {
              font-size: 0.76rem;
              color: #4b5563;
            }

            .server-online-log-item .badge {
              font-size: 0.7rem;
              letter-spacing: 0.01em;
            }

            .server-online-log-empty {
              background: #f8fafc;
              border: 1px dashed #cbd5e1;
              border-radius: 10px;
              text-align: center;
              color: #64748b;
              padding: 0.9rem;
              font-size: 0.8rem;
            }

            body.app-theme-dark .server-online-log-item {
              background: linear-gradient(145deg, #111a2f, #1a2742);
              border-color: rgba(148, 163, 184, 0.3);
              border-left-color: #3b82f6;
            }

            body.app-theme-dark .server-online-log-item .server-name {
              color: #e2e8f0;
            }

            body.app-theme-dark .server-online-log-item .server-stats {
              color: #cbd5e1;
            }

            body.app-theme-dark .server-online-log-empty {
              background: #0f172a;
              border-color: rgba(59, 130, 246, 0.35);
              color: #cbd5e1;
            }
          </style>
        </div>

        <div class="card shadow-sm dashboard-right-main-card">
          <div class="card-header dashboard-dark-header dashboard-side-header bg-info text-white d-flex justify-content-between align-items-center" style="padding: 12px 15px;">
            <span style="font-weight: 600;"><i class="fas fa-satellite-dish me-2"></i>Log PPPoE / Hotspot MikroTik per Area</span>
            <small id="serverOnlineLogUpdatedAt" class="text-white-50" style="font-size: 0.72rem;">Memuat...</small>
          </div>
          <div class="card-body log-container">
            <div class="server-online-log-container" id="serverOnlineLogContainer">
              <div class="server-online-log-empty">Sedang memuat log asli MikroTik tiap server...</div>
            </div>
          </div>
        </div>
      </div>

      

        <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
   <script>
  // Kunci tinggi scroll widget log via JS (bukan cuma CSS) supaya PASTI tidak
  // bisa ketimpa aturan CSS lain manapun di halaman ini -- setProperty dgn
  // priority 'important' menang atas SEMUA aturan stylesheet, termasuk yang
  // pakai !important juga (yang menang cuma inline style !important atau yang
  // di-set lebih akhir dgn priority sama, dan ini di-set setelah semua CSS
  // di-parse browser).
  function enforceLogWidgetHeight() {
    document.querySelectorAll('.dashboard-right-main-card .log-container').forEach(function (el) {
      el.style.setProperty('max-height', '620px', 'important');
      el.style.setProperty('overflow-y', 'auto', 'important');
      el.style.setProperty('min-height', '0', 'important');
    });
    document.querySelectorAll('.server-online-log-container').forEach(function (el) {
      el.style.setProperty('max-height', '620px', 'important');
      el.style.setProperty('overflow-y', 'auto', 'important');
    });
    var logListEl = document.getElementById('logList');
    if (logListEl) {
      logListEl.style.setProperty('max-height', 'calc(100% - 50px)', 'important');
      logListEl.style.setProperty('overflow-y', 'auto', 'important');
    }
  }
  document.addEventListener('DOMContentLoaded', enforceLogWidgetHeight);
  // Log widget diisi ulang secara dinamis (fetch tiap beberapa detik) yang
  // bisa mengganti isi DOM -- jalankan ulang penguncian tinggi secara berkala
  // supaya tetap terkunci walau kontennya berubah-ubah.
  setInterval(enforceLogWidgetHeight, 5000);

  const username = "<?php echo $username ?>";
  const userOwnedAreas = <?php echo json_encode(array_values(array_unique(array_filter($userAreas)))); ?>;
  const mikrotikLogRefreshMs = 10000;


  let allLogs = [];
  let mikrotikAreaLogs = {};
  let isMikrotikLogSyncRunning = false;
  function renderLogs(filter = "") {
    let logList = $("#logList");
    logList.empty();
    let logsToShow = allLogs;
    if (filter) {
      const f = filter.toLowerCase();
      logsToShow = allLogs.filter(log => log.toLowerCase().includes(f));
    }
    if (logsToShow.length > 0) {
      logsToShow.forEach(log => {
        const safeLog = $("<div>").text(log).html();
        logList.append(`<li class='list-group-item'>${safeLog.replace(/\n/g, "<br>")}</li>`);
      });
    } else {
      logList.append("<li class='list-group-item text-muted'>Tidak ada log yang cocok.</li>");
    }
  }

  function fetchStoredLogs() {
    return $.getJSON("getdata/get_log.php?username=" + encodeURIComponent(username) + "&_=" + Date.now())
      .done(function(data) {
        allLogs = data.length > 0 ? data.reverse() : [];
        renderLogs($("#logFilterInput").val());
      })
      .fail(function() {
        // skip jika gagal, tidak update UI system log
      });
  }

  function fetchMikrotikLogsDirect() {
    return $.getJSON("getdata/get_log_mikrotik.php?mode=json&seconds=10&username=" + encodeURIComponent(username) + "&_=" + Date.now())
      .done(function(res) {
        if (res && res.success) {
          mikrotikAreaLogs = res.grouped || {};
          refreshServerMikrotikLogCard(true);
        } else {
          refreshServerMikrotikLogCard(false, 'Response API MikroTik tidak valid');
        }
      })
      .fail(function() {
        refreshServerMikrotikLogCard(false, 'Gagal mengambil log langsung dari API MikroTik');
      });
  }

  function loadLogs(forceSync = true) {
    if (isMikrotikLogSyncRunning) {
      return;
    }

    isMikrotikLogSyncRunning = true;

    const updatedAt = document.getElementById('serverOnlineLogUpdatedAt');
    if (updatedAt) {
      updatedAt.textContent = forceSync ? 'Sinkronisasi realtime...' : 'Memuat log...';
    }

    if (forceSync) {
      $.when(fetchStoredLogs(), fetchMikrotikLogsDirect())
        .always(function() {
          isMikrotikLogSyncRunning = false;
        });
    } else {
      fetchStoredLogs().always(function() {
        isMikrotikLogSyncRunning = false;
      });
    }
  }

  function parseMikrotikLogEntry(raw) {
    const lines = String(raw || '')
      .split(/\r?\n/)
      .map(line => line.trim())
      .filter(Boolean);

    const server = lines[0] || '-';
    const area = lines[1] || '-';
    const message = lines[2] || '';
    const tanggal = lines[3] || '';
    const waktu = lines[4] || '';
    const text = message.toLowerCase();
    const isError = /error|failed|failure|invalid|denied|critical|fatal|timeout|refused|unreachable|down/.test(text);

    let service = '';
    if (text.includes('pppoe')) {
      service = 'PPPoE';
    } else if (text.includes('hotspot')) {
      service = 'Hotspot';
    } else if (isError) {
      service = 'MikroTik';
    }

    let status = 'other';
    if (isError) {
      status = 'error';
    } else if (
      text.includes('logged in') ||
      text.includes('log in') ||
      text.includes('connected') ||
      text.includes('authorized') ||
      text.includes('bound')
    ) {
      status = 'online';
    } else if (
      text.includes('logged out') ||
      text.includes('log out') ||
      text.includes('disconnected') ||
      text.includes('terminated') ||
      text.includes('timeout') ||
      text.includes('lost') ||
      text.includes('down')
    ) {
      status = 'offline';
    }

    return {
      server,
      area,
      message,
      tanggal,
      waktu,
      service,
      status,
      isError
    };
  }

  function buildMikrotikAreaLogData(rawLogs) {
    const areaSet = new Set(
      (Array.isArray(userOwnedAreas) ? userOwnedAreas : [])
        .map(v => String(v || '').trim().toLowerCase())
    );
    const grouped = {};

    (Array.isArray(rawLogs) ? rawLogs : []).forEach(raw => {
      const item = parseMikrotikLogEntry(raw);
      if (!item.service) return;

      const areaKey = String(item.area || '').trim().toLowerCase();
      if (!areaSet.has(areaKey)) return;

      if (!grouped[item.area]) {
        grouped[item.area] = {
          area: item.area,
          online: 0,
          offline: 0,
          error: 0,
          other: 0,
          items: [],
          owners: new Set()
        };
      }

      if (item.status === 'error') {
        grouped[item.area].error += 1;
      } else if (item.status === 'online') {
        grouped[item.area].online += 1;
      } else if (item.status === 'offline') {
        grouped[item.area].offline += 1;
      } else {
        grouped[item.area].other += 1;
      }

      grouped[item.area].owners.add(item.server);

      if (grouped[item.area].items.length < 6) {
        grouped[item.area].items.push(item);
      }
    });

    Object.keys(grouped).forEach(areaName => {
      grouped[areaName].ownerCount = grouped[areaName].owners.size;
      delete grouped[areaName].owners;
    });

    return grouped;
  }

  function renderServerOnlineLogs(areaData) {
    const container = document.getElementById('serverOnlineLogContainer');
    if (!container) return;

    const groups = Object.values(areaData || {}).sort((a, b) => {
      return String(a.area || '').localeCompare(String(b.area || ''));
    });

    if (!groups.length) {
      container.innerHTML = '<div class="server-online-log-empty">Belum ada log PPPoE/Hotspot/error dari semua area.</div>';
      return;
    }

    const mixedItems = [];

    groups.forEach(group => {
      (Array.isArray(group.items) ? group.items : []).forEach(item => {
        mixedItems.push({
          ...item,
          area: item.area || group.area || '-',
          server: item.server || '-'
        });
      });
    });

    if (!mixedItems.length) {
      container.innerHTML = '<div class="server-online-log-empty">Belum ada event PPPoE/Hotspot/error pada riwayat log MikroTik.</div>';
      return;
    }

    const escapeHtml = (value) => String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/\"/g, '&quot;')
      .replace(/'/g, '&#39;');

    const listHtml = mixedItems.map(item => {
      const area = escapeHtml(item.area || '-');
      const server = escapeHtml(item.server || '-');
      const service = escapeHtml(item.service || 'MikroTik');
      const status = escapeHtml((item.status || 'other').toUpperCase());
      const message = escapeHtml(item.message || '-');
      const tanggal = escapeHtml(item.tanggal || '-');
      const waktu = escapeHtml(item.waktu || '');
      return `
        <li class="list-group-item" style="font-size:0.80em; padding:0.5rem; background-color:#f8f9fa; border:1px solid #e9ecef; border-radius:4px; margin-bottom:0.3rem;">
          [${area}] [${server}] [${service}] [${status}]<br>${message}<br>
          <span class="text-muted">${tanggal} ${waktu}</span>
        </li>
      `;
    }).join('');

    container.innerHTML = `
      <div class="log-container" style="height:100%; overflow-y:auto;">
        <ul class="list-group list-group-flush" style="font-size:0.80em;">
          ${listHtml}
        </ul>
      </div>
    `;
  }

  function refreshServerMikrotikLogCard(isSuccess = true, failMessage = '') {
    const updatedAt = document.getElementById('serverOnlineLogUpdatedAt');
    if (isSuccess) {
      renderServerOnlineLogs(mikrotikAreaLogs);
    }
    if (updatedAt) {
      if (isSuccess) {
        updatedAt.textContent = 'Realtime API ' + new Date().toLocaleTimeString('id-ID', {
          hour: '2-digit',
          minute: '2-digit',
          second: '2-digit'
        });
      } else {
        updatedAt.textContent = failMessage || 'Gagal sinkronisasi API MikroTik';
      }
    }
  }

  // Filter log saat user mengetik
  $(document).on('input', '#logFilterInput', function() {
    renderLogs(this.value);
  });

  // Clear log handler
  $(document).on('click', '#clearLogBtn', function() {
    if (confirm('Yakin ingin menghapus semua log?')) {
      $.post('getdata/clear_log.php', { username: username })
        .done(function(response) {
          loadLogs();
        })
        .fail(function() {
          alert('Gagal menghapus log.');
        });
    }
  });

  // Panggil saat halaman dimuat
  loadLogs(true);

  // Auto-refresh realtime setiap 10 detik hanya jika tab aktif
  let logInterval = setInterval(() => {
    if (!document.hidden) {
      loadLogs(true);
    }
  }, mikrotikLogRefreshMs);

  // Pause saat tab tidak aktif
  document.addEventListener('visibilitychange', function() {
    if (document.hidden) {
      console.log('Tab tidak aktif, pause log refresh');
    } else {
      console.log('Tab aktif, resume log refresh');
      // Refresh sekali saat kembali aktif
      loadLogs(true);
    }
  });
</script>













      </div>
    
    </div>
  
  </div>



<style>
  html, body {
    height: 100%;
    margin: 0;
  }

  /* Container card full height */
  #view {
    height: 100%;
  }
  #view .card {
    height: 100%;
  }
  #view .card-body {
    display: flex;
    flex-direction: column;
    gap: 1rem;
    height: 100%;
  }

  /* Map fleksibel sesuai card-body */
  #map {
    flex: 1 1 auto; /* tumbuh sesuai sisa tinggi card-body */
    width: 100%;
    border-radius: 0.5rem;
    min-height: 300px; /* minimal tinggi agar tidak terlalu kecil di mobile */
  }

  /* Optional: agar overlay filter tetap di atas map */
  .overlay {
    position: relative;
    z-index: 10;
  }

  /* Scroll container log */
  .log-container {
    overflow-y: auto;
    max-height: 1400px;
  }
  .logList {
    overflow-y: auto;
    max-height: 1000px;
  }

  /* Responsif untuk smartphone */
  @media (max-width: 768px) {
    #map {
      min-height: 300px;
      height: auto; /* biar tidak overflow */
    }

    .dashboard-right-col {
      max-height: none;
      gap: 1rem;
    }

    .dashboard-right-main-card {
      min-height: 460px;
      flex: 0 0 auto;
    }

    .dashboard-right-main-card .log-container {
      min-height: 380px;
      max-height: 620px;
    }

    .dashboard-right-main-card .log-container #logList,
    .dashboard-right-main-card .server-online-log-container {
      max-height: 380px;
    }
  }
</style>


  <!-- Leaflet -->
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

  <style>
    /* Modal fullscreen untuk Customer and ODP Maps - #view (judul, filter, #map)
       dipindahkan ke sini saat tombol fullscreen diklik, lalu dikembalikan ke
       posisi asal (mapViewAnchor) saat modal ditutup. */
    #mapFullscreenModal .modal-content {
      height: 100vh;
    }
    #mapFullscreenModal .modal-body {
      display: flex;
      flex-direction: column;
      overflow: auto;
    }
    #mapFullscreenModal #view {
      display: flex;
      flex-direction: column;
      flex: 1 1 auto;
      min-height: 0;
    }
    #mapFullscreenModal #map {
      flex: 1 1 auto;
      min-height: 0;
    }
    #mapFullscreenModal #fullViewBtn {
      display: none;
    }

    /* Popup map yang kontras agar tidak samar di atas tiles peta */
    #view .leaflet-popup,
    #view .leaflet-popup-pane,
    #view .leaflet-popup-content-wrapper,
    #view .leaflet-popup-content,
    #view .leaflet-popup-tip {
      opacity: 1 !important;
      filter: none !important;
    }

    #view .leaflet-popup-content-wrapper,
    #view .qts-map-popup .leaflet-popup-content-wrapper {
      background: #ffffff !important;
      color: #111827 !important;
      border: 1px solid #c6dcff !important;
      border-radius: 14px !important;
      box-shadow: 0 16px 32px rgba(15, 23, 42, 0.35) !important;
      backdrop-filter: none !important;
    }

    #view .leaflet-popup-tip,
    #view .qts-map-popup .leaflet-popup-tip {
      background: #ffffff !important;
      border: 1px solid #c6dcff !important;
      box-shadow: 0 8px 16px rgba(15, 23, 42, 0.28) !important;
    }

    #view .leaflet-popup-content,
    #view .qts-map-popup .leaflet-popup-content {
      margin: 12px 14px !important;
      line-height: 1.45 !important;
      font-weight: 600 !important;
      font-size: 13px !important;
      color: #111827 !important;
      text-shadow: none !important;
    }

    #view .leaflet-popup-content,
    #view .leaflet-popup-content * {
      color: #111827 !important;
      opacity: 1 !important;
      text-shadow: none !important;
      filter: none !important;
    }

    #view .leaflet-popup-content a,
    #view .qts-map-popup .leaflet-popup-content a {
      background: linear-gradient(135deg, #0d6efd, #0b5ed7) !important;
      color: #ffffff !important;
      border: 1px solid #0b5ed7 !important;
      border-radius: 8px !important;
      padding: 4px 8px !important;
      text-decoration: none !important;
      display: inline-block;
      font-weight: 700 !important;
    }

    #view .leaflet-popup-close-button,
    #view .qts-map-popup .leaflet-popup-close-button {
      color: #334155 !important;
      font-weight: 700 !important;
      top: 8px !important;
      right: 8px !important;
      opacity: 1 !important;
    }

    #view .leaflet-popup-content b,
    #view .leaflet-popup-content strong {
      color: #0b1220 !important;
    }
  </style>





<script>
  // Simpan semua interval animasi garis
  let animationIntervals = [];

  const map = L.map('map').setView([-2.5, 118], 5);

  const layers = {
    'OpenStreetMap': L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '© OpenStreetMap'
    }).addTo(map),

    'Satellite': L.tileLayer('https://{s}.google.com/vt/lyrs=s&x={x}&y={y}&z={z}', {
      maxZoom: 20,
      subdomains: ['mt0', 'mt1', 'mt2', 'mt3'],
      attribution: '© Google'
    }),

    'Dark': L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
      attribution: '© CartoDB'
    })
  };

  L.control.layers(layers).addTo(map);

 const iconODP = L.icon({
    iconUrl: 'odpmap2.png',
    iconSize: [80, 80],
    iconAnchor: [40, 70] // titik tengah ikon berada di koordinat marker
});

const iconOnline = L.icon({
    iconUrl: 'customer-green2.png',
    iconSize: [75, 75],
    iconAnchor: [37.5, 60] // tengah ikon
});

const iconLos = L.icon({
    iconUrl: 'customer-red2.png',
    iconSize: [75, 75],
    iconAnchor: [37.5, 60] // tengah ikon
});


  let odpMarkers = [],
    pelangganMarkers = [],
    lines = [];

  async function loadData() {
    document.getElementById('loading').style.display = 'inline';

    try {
      const res = await fetch('serverlog/<?php echo ($AKSES == 'ASSISTANT') ? $asistant_name : $username; ?>_online_client.txt');
      const data = await res.json();

      document.getElementById('loading').style.display = 'none';

      clearMap();

      odpMarkers = [];
      pelangganMarkers = [];
      lines = [];
      animationIntervals.forEach(id => clearInterval(id));
      animationIntervals = [];

      const odpFilter = document.getElementById('odpFilter');
      odpFilter.innerHTML = '<option value="">-- Semua ODP --</option>';

      // 🔹 simpan bounds semua ODP
      let bounds = [];

      // 🔹 Kelompokkan ODP berdasarkan AREA
      const areaGroups = {};
      (data.odpMarkers || []).forEach(o => {
        if (!areaGroups[o.area]) {
          areaGroups[o.area] = [];
        }
        areaGroups[o.area].push(o);

        const marker = L.marker([o.lat, o.lng], { icon: iconODP })
          .bindPopup(o.popup, { className: 'qts-map-popup', maxWidth: 360 }).addTo(map);

        odpMarkers.push({
          kode: o.kode,
          lat: o.lat,
          lng: o.lng,
          marker
        });

        bounds.push([o.lat, o.lng]);
      });

      // 🔹 Tambahkan ODP ke select filter dengan optgroup per AREA
      for (const area in areaGroups) {
        const group = document.createElement('optgroup');
        group.label = area.toUpperCase();

        areaGroups[area].forEach(o => {
          const option = document.createElement('option');
          option.value = o.kode;
          option.textContent = `${o.name} (${o.kode})`;
          group.appendChild(option);
        });

        odpFilter.appendChild(group);
      }

      // 🔹 Tambahkan marker pelanggan
      (data.pelangganMarkers || []).forEach(p => {
        const icon = p.status === 'online' ? iconOnline : iconLos;
        const marker = L.marker([p.lat, p.lng], { icon })
          .bindPopup(p.popup, { className: 'qts-map-popup', maxWidth: 360 }).addTo(map);
        marker.odp = p.odp;
        pelangganMarkers.push(marker);
      });

      // 🔹 Tambahkan garis animasi
      (data.lines || []).forEach(l => {
        const color = l.status === 'online' ? 'green' : 'red';
        const line = L.polyline([l.from, l.to], {
          color,
          dashArray: '10, 10',
          weight: 3,
        }).addTo(map);

        line.odp = l.odp;
        lines.push(line);

        let offset = 0;
        const intervalId = setInterval(() => {
          offset = (offset + 1) % 20;
          if (line._path) {
            line._path.style.strokeDashoffset = offset;
          }
        }, 50);
        animationIntervals.push(intervalId);
      });

      // 🔹 Kalau ada ODP, langsung center ke semua ODP
      if (bounds.length) {
        map.fitBounds(bounds);
      }

    } catch (e) {
      console.error("Gagal load data:", e);
      document.getElementById('loading').style.display = 'none';
    }
  }

  function clearMap() {
    odpMarkers.forEach(o => map.removeLayer(o.marker));
    pelangganMarkers.forEach(p => map.removeLayer(p));
    lines.forEach(l => map.removeLayer(l));
    odpMarkers = [];
    pelangganMarkers = [];
    lines = [];

    const select = document.getElementById('odpFilter');
    select.innerHTML = '<option value="">-- Semua ODP --</option>';

    animationIntervals.forEach(id => clearInterval(id));
    animationIntervals = [];
  }

  document.getElementById('odpFilter').addEventListener('change', function() {
    const selected = this.value;
    let bounds = [];

    odpMarkers.forEach(o => {
      if (!selected || o.kode === selected) {
        o.marker.addTo(map);
        bounds.push([o.lat, o.lng]);
      } else {
        map.removeLayer(o.marker);
      }
    });

    pelangganMarkers.forEach(p => {
      if (!selected || p.odp === selected) {
        p.addTo(map);
      } else {
        map.removeLayer(p);
      }
    });

    lines.forEach(l => {
      if (!selected || l.odp === selected) {
        l.addTo(map);
      } else {
        map.removeLayer(l);
      }
    });

    // 🔹 Jika pilih semua ODP → fitBounds semua
    if (!selected && bounds.length) {
      map.fitBounds(bounds);
    } 
    // 🔹 Jika pilih salah satu → zoom ke ODP tsb
    else if (selected) {
      const found = odpMarkers.find(o => o.kode === selected);
      if (found) map.setView([found.lat, found.lng], 16);
    }
  });

  let countdown = 120;
  let countdownInterval;

  function startCountdown() {
    const timerSpan = document.getElementById('timer');
    countdown = 120;
    countdownInterval = setInterval(() => {
      if (!document.hidden) {
        countdown--;
        timerSpan.textContent = countdown;
        if (countdown <= 0) {
          clearInterval(countdownInterval);
          loadData().then(startCountdown);
        }
      }
    }, 1000);
  }

  (function() {
    const view = document.getElementById("view");
    const anchor = document.getElementById("mapViewAnchor");
    const mapModalEl = document.getElementById("mapFullscreenModal");
    const mapModalBody = document.getElementById("mapFullscreenModalBody");

    document.getElementById("fullViewBtn").addEventListener("click", function() {
      // Pindahkan #view (judul, filter, #map) apa adanya ke dalam modal supaya
      // instance Leaflet yang sudah ada tidak perlu dibuat ulang.
      mapModalBody.appendChild(view);
      bootstrap.Modal.getOrCreateInstance(mapModalEl).show();
    });

    mapModalEl.addEventListener("shown.bs.modal", function() {
      setTimeout(() => {
        if (typeof map !== 'undefined') map.invalidateSize();
      }, 200);
    });

    mapModalEl.addEventListener("hidden.bs.modal", function() {
      anchor.parentNode.insertBefore(view, anchor.nextSibling);
      setTimeout(() => {
        if (typeof map !== 'undefined') map.invalidateSize();
      }, 200);
    });
  })();

  // Lazy load map data only when needed
  document.addEventListener('DOMContentLoaded', function() {
    // Only load map if user scrolls to map section or after 3 seconds
    let mapLoaded = false;
    
    function initMap() {
      if (!mapLoaded) {
        mapLoaded = true;
        loadData();
        startCountdown();
      }
    }
    
    // Load map after 3 seconds or when user scrolls to it
    setTimeout(initMap, 3000);
    
    const mapElement = document.getElementById('map');
    if (mapElement) {
      const observer = new IntersectionObserver((entries) => {
        if (entries[0].isIntersecting) {
          initMap();
          observer.disconnect();
        }
      });
      observer.observe(mapElement);
    }

    // Pause map refresh saat tab tidak aktif
    document.addEventListener('visibilitychange', function() {
      if (document.hidden) {
        console.log('Tab tidak aktif, pause map refresh');
      } else {
        console.log('Tab aktif, resume map refresh');
      }
    });
  });
</script>


<div class="container-fluid px-3 px-md-4">
    <div class="card shadow">
      <div class="card-header dashboard-dark-header theme-aware-header">
        <h5 class="mb-0">Server monitor</h5>
      </div>
      <div class="row">
        <?php
        if ($AKSES == 'ASSISTANT') {
            $sql = "SELECT * FROM `server` WHERE `AREA` IN ($area_list)";
        } else {
            $sql = "SELECT * FROM `server` WHERE `pemilik` IN ($userServerList)";
        }
        $query = mysqli_query($conn, $sql);

        while ($data = mysqli_fetch_array($query)) {
          $id = $data['id'];
          $ip = $data['IP'];
          $pemilik = $data['PEMILIK'];
          $area = $data['AREA'];
          $nas = $data['NAS'];
          $password = $data['PASSWORD'];

          // uid unik per baris server, dipakai untuk semua id HTML/JS
          // supaya server dengan IP/port sama tidak saling bentrok elemennya
          $uid = 'srv-' . $id;
        ?>

          <div class="col-md-4 mb-4">
            <div class="card shadow-sm p-3" style="font-size: 12px; cursor: pointer;" onclick="location.href=''">
              <div class="d-flex align-items-center mb-2">
                <img src="https://img.icons8.com/3d-fluency/94/server.png" class="avatar avatar-sm me-2" style="width: 40px;" alt="Server">
                <div>
                  <h6 class="mb-0 text-sm"><?php echo $pemilik ?> - <?php echo $area ?></h6>
                  <span class="text-xs text-muted"><?php echo $ip ?></span><br>
                  <small id="status-<?php echo $uid ?>" data-ip="<?php echo htmlspecialchars($ip, ENT_QUOTES) ?>">Status: -</small><br>
                  <small id="internet-status-<?php echo $uid ?>"></small><br>
                  <small id="cpu-name-<?php echo $uid ?>">CPU Name: -</small><br>
                  <small id="identity-<?php echo $uid ?>">Identity: -</small><br>
                  <small id="cpu-<?php echo $uid ?>">CPU Load: - %</small><br>
                  <small id="memory-<?php echo $uid ?>">Memory: - MB used / - MB total</small>
                </div>
              </div>
              <div class="text-center">
                <canvas height="150" id="trafficChart<?php echo $id; ?>"></canvas>
              </div>
            </div>
          </div>

<script>
  (function() {
    let labels = [];
    let outputData = [];
    let inputData = [];
    let chartId = "<?php echo $id; ?>";
    let uid = "<?php echo $uid; ?>";
    let ip = "<?php echo addslashes($ip); ?>";
    let pemilik = "<?php echo addslashes($pemilik); ?>";
    let password = "<?php echo addslashes($password); ?>";

    function setErrorState(message) {
      const statusEl = document.getElementById('status-' + uid);
      if (statusEl) {
        statusEl.innerText = "Status: " + message;
        statusEl.style.color = "red";
      }
      const setText = (id, text) => {
        const el = document.getElementById(id + '-' + uid);
        if (el) el.innerText = text;
      };
      setText('internet-status', "Internet Status: -");
      setText('cpu-name', "CPU Name: -");
      setText('identity', "Identity: -");
      setText('cpu', "CPU Load: - %");
      setText('memory', "Memory: - MB used / - MB total");
    }

    function fetchData() {
      const params = new URLSearchParams({
        ip: ip,
        ps: password,
        us: pemilik
      });

      fetch(`getdata/get-trafikinterface.php?${params.toString()}`)
        .then(response => {
          if (!response.ok) {
            throw new Error('HTTP ' + response.status);
          }
          return response.json();
        })
        .then(data => {
          if (data.error) {
            console.error('Mikrotik error [' + uid + ']:', data.error, data.debug || '');
            setErrorState(data.error);
            return;
          }

          let totalTx = 0;
          let totalRx = 0;

          if (data.pppoe_trafik && Array.isArray(data.pppoe_trafik)) {
            data.pppoe_trafik.forEach(iface => {
              totalTx += iface.output;
              totalRx += iface.input;
            });
          }

          if (data.hotspot_trafik && Array.isArray(data.hotspot_trafik)) {
            data.hotspot_trafik.forEach(iface => {
              totalTx += iface.output;
              totalRx += iface.input;
            });
          }

          labels.push(new Date().toLocaleTimeString());
          outputData.push(totalTx);
          inputData.push(totalRx);

          if (labels.length > 20) {
            labels.shift();
            outputData.shift();
            inputData.shift();
          }

          trafficChart.update();

          const statusEl = document.getElementById('status-' + uid);
          if (statusEl) {
            statusEl.innerText = "Status: Online";
            statusEl.style.color = "green";
          }

          document.getElementById('internet-status-' + uid).innerText = data.internet_status ?
            `Internet Status: ${data.internet_status.toUpperCase()}` :
            "Internet Status: -";
          document.getElementById('cpu-name-' + uid).innerText = data.cpu_name ? `CPU Name: ${data.cpu_name}` : "CPU Name: -";
          document.getElementById('identity-' + uid).innerText = data.identity ? `Identity: ${data.identity}` : "Identity: -";
          document.getElementById('cpu-' + uid).innerText = (data.cpu_load_percent !== undefined && data.cpu_load_percent !== null) ? `CPU Load: ${data.cpu_load_percent} %` : "CPU Load: - %";

          if (data.memory) {
            const freeMB = data.memory.free ? (data.memory.free / 1024 / 1024).toFixed(2) : '-';
            const totalMB = data.memory.total ? (data.memory.total / 1024 / 1024).toFixed(2) : '-';
            document.getElementById('memory-' + uid).innerText = `Memory: ${freeMB} MB free / ${totalMB} MB total`;
          } else {
            document.getElementById('memory-' + uid).innerText = "Memory: - MB used / - MB total";
          }
        })
        .catch(error => {
          console.error('Fetch error [' + uid + ']:', error);
          setErrorState(error.message || 'Fetch gagal');
        });
    }

    const ctx = document.getElementById('trafficChart' + chartId).getContext('2d');
    const trafficChart = new Chart(ctx, {
      type: 'line',
      data: {
        labels: labels,
        datasets: [
          { label: 'TX Mbps', data: outputData, borderColor: 'red', backgroundColor: 'rgba(255, 0, 0, 0.1)', borderWidth: 1 },
          { label: 'RX Mbps', data: inputData, borderColor: 'blue', backgroundColor: 'rgba(0, 0, 255, 0.1)', borderWidth: 1 }
        ]
      },
      options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true } }
      }
    });

    setInterval(fetchData, 10000);
    fetchData();
  })();
</script>

        <?php } ?>
      </div>
    </div>
  </div>

  <script>
    let ipList = [];

    function pingHost(ipserver, uid) {
      fetch(`getdata/ping.php?host=${ipserver}`)
        .then(response => response.json())
        .then(data => {
          const statusElem = document.getElementById("status-" + uid);
          if (statusElem) {
            statusElem.textContent = `Status: ${data.status}`;
            statusElem.style.color = data.status === "Online" ? "green" : "red";
          }
          if (data.status === "Online" && !ipList.includes(ipserver)) {
            ipList.push(ipserver);
          }
        })
        .catch(error => console.error("Error:", error));
    }

    window.onload = function() {
      const statusElements = document.querySelectorAll("[id^='status-']");
      statusElements.forEach((elem, index) => {
        let ip = elem.getAttribute('data-ip');
        let uid = elem.id.replace("status-", "");
        setTimeout(() => pingHost(ip, uid), index * 500);
      });
    };
  </script>

  <script>
  // Fungsi clear cache browser
  function clearBrowserCache() {
    // Clear cache AJAX
    if ('caches' in window) {
      caches.keys().then(names => {
        names.forEach(name => {
          caches.delete(name);
        });
      });
    }

    // Clear localStorage dan sessionStorage
    localStorage.clear();
    sessionStorage.clear();

    // Force garbage collection jika tersedia
    if (window.gc) {
      window.gc();
    }

    // Clear history state
    if (window.history.replaceState) {
      window.history.replaceState(null, null, window.location.href);
    }

    alert('Cache browser telah dibersihkan! Halaman akan refresh untuk performa optimal.');
    location.reload();
  }
  </script>













  
<?php require 'footer.php'; ?>




