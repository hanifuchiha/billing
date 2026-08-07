<?php require 'header.php';
if ($AKSES == 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('Server_Area', $akses_menu, true)) {
        echo '<div class="container-fluid py-4"><div class="alert alert-danger">Anda tidak memiliki akses ke menu Server Area.</div></div>';
        require 'footer.php';
        exit;
    }
}

require('routeros_api.class.php');
require_once __DIR__ . '/radius_sync_lib.php';
$API = new RouterosAPI();

// Bootstrap kolom server.CONNECTION_MODE ('API' = perilaku lama, koneksi
// langsung ke Mikrotik; 'RADIUS_ONLY' = tanpa koneksi API sama sekali,
// router dikonfigurasi manual lewat skrip yang di-generate halaman ini).
// Default 'API' -- tidak mengubah server existing sampai admin sengaja pilih
// RADIUS SAJA saat tambah/edit server.
$checkConnModeCol = @mysqli_query($conn, "SHOW COLUMNS FROM server LIKE 'CONNECTION_MODE'");
if ($checkConnModeCol && mysqli_num_rows($checkConnModeCol) === 0) {
    @mysqli_query($conn, "ALTER TABLE server ADD COLUMN CONNECTION_MODE ENUM('API','RADIUS_ONLY') NOT NULL DEFAULT 'API'");
}

// Bootstrap kolom server.LOGO (nama file logo khusus server ini, kosong =
// pakai logo akun billing) dan server.TIKOR (koordinat "lat,lng" lokasi
// server, dipilih lewat map picker sama seperti di form tambah pelanggan).
$checkLogoCol = @mysqli_query($conn, "SHOW COLUMNS FROM server LIKE 'LOGO'");
if ($checkLogoCol && mysqli_num_rows($checkLogoCol) === 0) {
    @mysqli_query($conn, "ALTER TABLE server ADD COLUMN LOGO VARCHAR(255) NULL DEFAULT NULL");
}
$checkTikorCol = @mysqli_query($conn, "SHOW COLUMNS FROM server LIKE 'TIKOR'");
if ($checkTikorCol && mysqli_num_rows($checkTikorCol) === 0) {
    @mysqli_query($conn, "ALTER TABLE server ADD COLUMN TIKOR VARCHAR(64) NULL DEFAULT NULL");
}

// Tampilkan skrip MikroTik setelah server RADIUS_ONLY baru disimpan (lihat
// proses/addserver.php dan proses/editserver.php -- redirect ke sini dengan
// ?radius_script_for=<id>).
$radius_script_output = null;
if (isset($_GET['radius_script_for'])) {
    $rsId = (int) $_GET['radius_script_for'];
    $rsStmt = mysqli_prepare($conn, "SELECT IP, PASSWORD, AREA, BRAND FROM server WHERE id = ? AND CONNECTION_MODE = 'RADIUS_ONLY' LIMIT 1");
    mysqli_stmt_bind_param($rsStmt, "i", $rsId);
    mysqli_stmt_execute($rsStmt);
    $rsRow = mysqli_stmt_get_result($rsStmt)->fetch_assoc();
    mysqli_stmt_close($rsStmt);
    if ($rsRow) {
        $rsConfig_file = 'config.json';
        $rsConfig = file_exists($rsConfig_file) ? json_decode(file_get_contents($rsConfig_file), true) : [];
        $rsRadiusIp = $rsConfig['webiplocal'] ?? ($rsConfig['radius_ip'] ?? '');
        $radius_script_output = radiusGenerateMikrotikScript($rsRadiusIp, $rsRow['PASSWORD'], $rsRow['AREA'], $rsRow['BRAND']);
    }
}
?>

<?php if ($radius_script_output !== null): ?>
<div class="modal fade" id="radiusScriptModal" tabindex="-1" aria-labelledby="radiusScriptModalLabel" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-success text-white">
        <h5 class="modal-title" id="radiusScriptModalLabel"><i class="fas fa-check-circle"></i> Server RADIUS SAJA berhasil disimpan</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p>Server ini <strong>tidak akan pernah dihubungi lewat API</strong> oleh billing. Copy-paste skrip di bawah ini ke <strong>New Terminal</strong> di WinBox/WebFig router tersebut supaya router-nya menerima autentikasi dari FreeRADIUS:</p>
        <textarea class="form-control" rows="18" readonly onclick="this.select()" style="font-family: monospace; font-size: 0.85em;"><?= htmlspecialchars($radius_script_output, ENT_QUOTES, 'UTF-8'); ?></textarea>
        <small class="text-muted d-block mt-2">Script ini juga bisa dilihat lagi kapan pun lewat tombol "Lihat Script RADIUS" di baris server ini pada tabel di bawah.</small>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
      </div>
    </div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    new bootstrap.Modal(document.getElementById('radiusScriptModal')).show();
});
</script>
<?php endif; ?>

<style>
#mapPickerContainer { position: relative; z-index: 1; }
.leaflet-pane, .leaflet-top, .leaflet-bottom { z-index: 2; }
@media (max-width: 576px) {
  #mapPickerModal .modal-dialog { margin: 0.5rem; }
  #mapPickerContainer { height: 320px !important; }
}

/* #dataModal & #editModal membungkus modal-body di dalam <form> (modal-header
   & modal-footer sengaja di luar form -- tombol submit pakai atribut
   form="dataForm"/"editForm"), sehingga form (bukan body) yang jadi
   flex-child langsung dari modal-content. Ini merusak perhitungan tinggi
   yang dipakai modal-dialog-scrollable untuk modal-body -- akibatnya konten
   malah ke-clip (overflow:hidden) bukan discroll. display:contents membuat
   <form> "transparan" secara layout (modal-body jadi flex-child modal-content
   langsung) tanpa mengubah fungsi submitnya. */
.modal-dialog-scrollable .modal-content > form {
  display: contents;
}
</style>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.css" />
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.js"></script>

<!-- Map Picker Modal (sama seperti di form tambah pelanggan) -->
<div class="modal fade" id="mapPickerModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header" style="background:linear-gradient(135deg,#2152ff 0%,#21d4fd 100%);color:white;">
        <h5 class="modal-title" style="color:white;"><i class="fas fa-map-marked-alt me-2"></i>Pilih Lokasi di Map</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0">
        <div class="px-3 pt-3 pb-2 d-flex gap-2 flex-wrap align-items-center" style="border-bottom:1px solid #e0e0e0;">
          <input type="text" id="mapPickerSearchInput" class="form-control form-control-sm" style="max-width:260px;" placeholder="Cari alamat / tempat...">
          <button type="button" class="btn btn-sm btn-secondary" onclick="mapPickerSearch()"><i class="fas fa-search"></i> Cari</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" onclick="mapPickerUseMyLocation()"><i class="fas fa-location-arrow"></i> Lokasi Saya</button>
          <span class="ms-auto small text-muted">Klik di peta untuk menandai titik lokasi</span>
        </div>
        <div id="mapPickerContainer" style="width:100%;height:420px;"></div>
        <div class="px-3 py-2" style="border-top:1px solid #e0e0e0;background:#f8f9fa;">
          <strong>Koordinat terpilih:</strong>
          <span id="mapPickerCoordsDisplay" class="text-muted">Belum ada titik dipilih</span>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
        <button type="button" class="btn btn-primary" id="mapPickerConfirmBtn" onclick="mapPickerConfirm()" disabled>
          <i class="fas fa-check"></i> Gunakan Koordinat Ini
        </button>
      </div>
    </div>
  </div>
</div>

<script>
// ============================================================
// Map Picker: pilih koordinat server lewat peta Leaflet
// Format output: "lat,lng" (contoh: -6.477644,106.778171)
// ============================================================
var mapPickerMap = null;
var mapPickerMarker = null;
var mapPickerTargetInputId = null;
var mapPickerDefaultCenter = [-6.200000, 106.816666]; // fallback: Jakarta

function mapPickerSetCoords(lat, lng) {
    var latF = parseFloat(lat).toFixed(6);
    var lngF = parseFloat(lng).toFixed(6);
    var disp = document.getElementById('mapPickerCoordsDisplay');
    var btn  = document.getElementById('mapPickerConfirmBtn');
    if (disp) disp.textContent = latF + ',' + lngF;
    if (btn)  btn.disabled = false;
    mapPickerMap._pickedLat = latF;
    mapPickerMap._pickedLng = lngF;
}

function mapPickerPlaceMarker(lat, lng) {
    if (mapPickerMarker) {
        mapPickerMarker.setLatLng([lat, lng]);
    } else {
        mapPickerMarker = L.marker([lat, lng], { draggable: true }).addTo(mapPickerMap);
        mapPickerMarker.on('dragend', function(e) {
            var pos = e.target.getLatLng();
            mapPickerSetCoords(pos.lat, pos.lng);
        });
    }
    mapPickerSetCoords(lat, lng);
}

function mapPickerInitMap() {
    if (mapPickerMap) return; // sudah pernah di-init, jangan diulang

    mapPickerMap = L.map('mapPickerContainer').setView(mapPickerDefaultCenter, 13);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(mapPickerMap);

    mapPickerMap.on('click', function(e) {
        mapPickerPlaceMarker(e.latlng.lat, e.latlng.lng);
    });
}

var mapPickerParentModalEl = null;

function openMapPicker(inputId) {
    mapPickerTargetInputId = inputId;

    var modalEl = document.getElementById('mapPickerModal');
    var bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);

    // Modal Add/Edit Server yang sedang terbuka WAJIB dimatikan dulu sebelum
    // map picker tampil -- kalau tidak, Bootstrap numpuk 2 modal + 2 backdrop
    // sekaligus (layar jadi gelap dobel & tampilan berantakan). Modal ini
    // ditampilkan lagi otomatis begitu map picker ditutup (lihat listener
    // 'hidden.bs.modal' di bawah).
    mapPickerParentModalEl = document.querySelector('#dataModal.show, #editModal.show');
    if (mapPickerParentModalEl) {
        bootstrap.Modal.getOrCreateInstance(mapPickerParentModalEl).hide();
    }

    // Reset state setiap kali dibuka
    var disp = document.getElementById('mapPickerCoordsDisplay');
    var btn  = document.getElementById('mapPickerConfirmBtn');
    if (disp) disp.textContent = 'Belum ada titik dipilih';
    if (btn)  btn.disabled = true;
    var searchInput = document.getElementById('mapPickerSearchInput');
    if (searchInput) searchInput.value = '';

    // Jika input target sudah ada isinya (format "lat,lng"), pakai sebagai titik awal
    var existingVal = (document.getElementById(inputId) || {}).value || '';
    var startLat = null, startLng = null;
    var parts = existingVal.split(',');
    if (parts.length === 2) {
        var pLat = parseFloat(parts[0].trim());
        var pLng = parseFloat(parts[1].trim());
        if (!isNaN(pLat) && !isNaN(pLng)) { startLat = pLat; startLng = pLng; }
    }

    modalEl.addEventListener('shown.bs.modal', function onShown() {
        modalEl.removeEventListener('shown.bs.modal', onShown);
        mapPickerInitMap();
        // Perbaiki ukuran peta setelah modal benar-benar tampil
        setTimeout(function(){ mapPickerMap.invalidateSize(); }, 150);

        if (startLat !== null && startLng !== null) {
            mapPickerMap.setView([startLat, startLng], 17);
            mapPickerPlaceMarker(startLat, startLng);
        } else if (mapPickerMarker) {
            // Modal dipakai ulang tanpa nilai awal -> hilangkan marker sebelumnya
            mapPickerMap.removeLayer(mapPickerMarker);
            mapPickerMarker = null;
        }
    });

    bsModal.show();
}

function mapPickerConfirm() {
    if (!mapPickerMap || mapPickerMap._pickedLat === undefined) return;
    var targetInput = document.getElementById(mapPickerTargetInputId);
    if (targetInput) {
        targetInput.value = mapPickerMap._pickedLat + ',' + mapPickerMap._pickedLng;
        targetInput.dispatchEvent(new Event('change', { bubbles: true }));
    }
    var modalEl = document.getElementById('mapPickerModal');
    var bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
    bsModal.hide();
}

// Begitu map picker selesai ditutup (lewat tombol Batal/X, klik backdrop,
// ATAU mapPickerConfirm() di atas -- ketiganya memicu event ini), tampilkan
// lagi modal Add/Edit Server yang tadi dimatikan di openMapPicker().
document.getElementById('mapPickerModal').addEventListener('hidden.bs.modal', function() {
    if (mapPickerParentModalEl) {
        bootstrap.Modal.getOrCreateInstance(mapPickerParentModalEl).show();
        mapPickerParentModalEl = null;
    }
});

function mapPickerUseMyLocation() {
    if (!navigator.geolocation) { alert('Browser tidak mendukung geolocation.'); return; }
    navigator.geolocation.getCurrentPosition(function(pos) {
        var lat = pos.coords.latitude, lng = pos.coords.longitude;
        mapPickerMap.setView([lat, lng], 17);
        mapPickerPlaceMarker(lat, lng);
    }, function() {
        alert('Gagal mendapatkan lokasi Anda. Pastikan izin lokasi diaktifkan.');
    });
}

function mapPickerSearch() {
    var q = (document.getElementById('mapPickerSearchInput') || {}).value || '';
    q = q.trim();
    if (!q) return;
    fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(q))
        .then(function(r){ return r.json(); })
        .then(function(results) {
            if (!results || !results.length) { alert('Lokasi tidak ditemukan.'); return; }
            var lat = parseFloat(results[0].lat), lng = parseFloat(results[0].lon);
            mapPickerMap.setView([lat, lng], 16);
            mapPickerPlaceMarker(lat, lng);
        })
        .catch(function() { alert('Gagal mencari lokasi. Periksa koneksi internet.'); });
}

document.addEventListener('DOMContentLoaded', function() {
    var searchInput = document.getElementById('mapPickerSearchInput');
    if (searchInput) {
        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); mapPickerSearch(); }
        });
    }
});
</script>

  <?php if (isset($_GET["statusnotif"]) && $_GET["statusnotif"] == "terkirim"): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
      <strong>Success!</strong> Operation completed successfully.
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php endif; ?>


<?php if (isset($_GET['status'])): ?>
  <div class="alert alert-<?php echo ($_GET['status']=='sukses')?'success':'danger'; ?> alert-dismissible fade show" role="alert">
    <?php echo htmlspecialchars($_GET['msg']); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>

<div class="container my-4">
<?php
$API = new RouterosAPI();

$servers = [];
if ($current_user_id) {
  if ($AKSES == 'ASSISTANT') {
    if (!empty($area_list_JSON)) {
      $assigned_ids = json_decode($area_list_JSON, true);
      if (is_array($assigned_ids) && !empty($assigned_ids)) {
        $id_in = implode(",", array_map('intval', $assigned_ids));
        $sql = "SELECT * FROM server WHERE id IN ($id_in)";
      } else {
        $sql = "SELECT * FROM server WHERE 1=0";
      }
    } elseif (!empty($area_list)) {
      $sql = "SELECT * FROM server WHERE AREA IN ($area_list)";
    } else {
      $sql = "SELECT * FROM server WHERE 1=0";
    }
  } else {
    $sql = "SELECT * FROM server WHERE user_id = $current_user_id";
  }
  $query = mysqli_query($conn, $sql);
  if ($query) {
    while ($data = mysqli_fetch_array($query)) {
      $servers[] = $data;
    }
  }
}

// Tangkap server yang dipilih
$selected_ip = $_POST['selected_server'] ?? '';
?>
<div class="card mb-4 shadow">
  <div class="card-header bg-primary text-white">
    <h6 class="mb-2">Tampilkan Interface</h6>
    <!-- Form pilih server -->
    <form method="POST" class="d-flex flex-column flex-md-row gap-2" id="showInterfaceForm" onsubmit="showInterfaceLoading();">
      <select name="selected_server" class="form-select">
        <option value="">-- Pilih Server --</option>
        <?php foreach($servers as $srv): ?>
          <option value="<?= $srv['IP'] ?>" <?= ($srv['IP'] == $selected_ip) ? 'selected' : '' ?>>
            <?= $srv['IP'] ?> (<?= htmlspecialchars($srv['AREA']) ?>)
          </option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-warning" type="submit">Tampilkan</button>
    </form>
    <!-- Loading spinner for interface -->
    <div id="interfaceLoadingSpinner" style="display:none; margin-top:10px;">
      <div class="spinner-border text-light" role="status">
      <span class="visually-hidden">Loading...</span>
      </div>
      <span class="ms-2">Mengambil data interface...</span>
    </div>
  </div>
</div>
<script>
// Show loading spinner when form submit (Tampilkan Interface)
function showInterfaceLoading() {
  var spinner = document.getElementById('interfaceLoadingSpinner');
  if (spinner) spinner.style.display = '';
}
// Hide spinner after page load (in case reload)
window.addEventListener('DOMContentLoaded', function() {
  var spinner = document.getElementById('interfaceLoadingSpinner');
  if (spinner) spinner.style.display = 'none';
});
</script>

<?php
if($selected_ip){
    foreach($servers as $data){
        if($data['IP'] == $selected_ip){
            $ip = $data['IP'];
            $pemilik = $data['PEMILIK'];
            $password = $data['PASSWORD'];
            $area = htmlspecialchars($data['AREA']);
            break;
        }
    }

    $start_time = microtime(true);
    $connected = $API->connect($selected_ip, $pemilik, $password);
    $end_time = microtime(true);
    $response_time = round(($end_time - $start_time) * 800, 2); // ms
    ?>

    <div class="card mb-4 shadow">
        <div class="card-header bg-primary text-white d-flex flex-column flex-md-row justify-content-between">
            <?php
            $rosVersion = 'Unknown';
            if($connected){
                $systemResource = $API->comm("/system/resource/print");
                $rosVersion = $systemResource[0]['version'] ?? 'Unknown';
            }
            ?>
            <span>Server: <?= $ip ?> (<?= $area ?>)</span>
            <span>RouterOS: <?= $rosVersion ?> | Response Time: <?= $response_time ?> ms</span>
        </div>
        <div class="card-body">
            <?php
            if($connected){
                $interfaces = $API->comm("/interface/print");
                $physicalInterfaces = array_filter($interfaces, function($intf){
                    return isset($intf['type']) && strpos($intf['type'], 'ether') !== false;
                });
                ?>

                <form method="POST" action="proses/aktifkan_server.php">
                    <input type="hidden" name="server_ip" value="<?= $ip ?>">
                    <input type="hidden" name="pemilik" value="<?= $pemilik ?>">
                    <input type="hidden" name="password" value="<?= $password ?>">
               <div class="table-responsive" style="overflow-x:auto; -webkit-overflow-scrolling: touch;">
    <table class="table table-bordered table-striped table-sm mb-0">
        <thead class="table-secondary">
            <tr>
                <th>Interface</th>
                <th>Type</th>
                <th>Status</th>
                <th class="text-center">PPPoE Server</th>
                <th class="text-center">Hotspot Server</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach($physicalInterfaces as $intf):
            $name = $intf['name'];
            $type = $intf['type'];
            $running = $intf['running'] ?? 0;

            $pppoeServers = $API->comm("/interface/pppoe-server/server/print", ["?interface"=>$name]);
            $pppoeStatus  = (!empty($pppoeServers)) ? "checked" : "";

            $hotspotServers = $API->comm("/ip/hotspot/print", ["?interface"=>$name]);
            $hotspotStatus  = (!empty($hotspotServers)) ? "checked" : "";
        ?>
            <tr>
                <td><?= $name ?></td>
                <td><?= $type ?></td>
                <td><?= $running ? "<span class='text-success'>Up</span>" : "<span class='text-danger'>Down</span>" ?></td>
                <td class="text-center">
                    <input type="checkbox" name="pppoe[]" value="<?= $name ?>" <?= $pppoeStatus ?>>
                </td>
                <td class="text-center">
                    <input type="checkbox" name="hotspot[]" value="<?= $name ?>" <?= $hotspotStatus ?>>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

                    <button type="submit" class="btn btn-success mt-3">Update Server</button>
                </form>

                <?php $API->disconnect();
            } else {
                echo "<p class='text-danger'>Gagal connect ke $ip</p>";
            }
            ?>
        </div>
    </div>

<?php } ?>
</div>


  <div class="container-fluid py-4 flex-grow-1">
    <div class="row">
      <div class="col-12">
        <div class="card shadow mb-4">
          <div class="card-header bg-primary text-white">
            <h5 class="mb-0">
              <i class="fas fa-server me-2"></i>Server Router Management
            </h5>
          </div>

          <div class="card-body">
            <!-- Button Section -->
            <div class="mb-4 pb-3 border-bottom">
              <div class="row g-2">
                <div class="col-auto">
                  <?php if ($AKSES == 'ADMIN'): ?>
                    <a href="vpnadmin.php" class="btn btn-sm btn-warning">
                      <i class="fas fa-network-wired me-1"></i>Buat VPN Koneksi
                    </a>
                  <?php else: ?>
                    <a href="vpn.php" class="btn btn-sm btn-warning">
                      <i class="fas fa-lock me-1"></i>Beli VPN Remot
                    </a>
                  <?php endif; ?>
                </div>
                <div class="col-auto">
                  <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#dataModal">
                    <i class="fas fa-plus me-1"></i>Add New Server
                  </button>
                </div>
                <div class="col-auto">
                  <a href="import_server.php" class="btn btn-sm btn-success">
                    <i class="fas fa-file-excel me-1"></i>Import dari Excel
                  </a>
                </div>

                <div class="col-auto">
                  <a href="export_server.php" class="btn btn-sm btn-info text-white">
                    <i class="fas fa-download me-1"></i>Export ke XLSX
                  </a>
                </div>
              </div>
            </div>

            <!-- Table Section -->
            <div class="table-responsive">
              <table class="table table-hover align-items-center mb-0">
                <thead>
                  <tr class="table-primary">
                    <th class="text-uppercase fw-semibold small">Area / Brand</th>
                    <th class="text-uppercase fw-semibold small text-center">Status</th>
                    <th class="text-uppercase fw-semibold small">IP Port API</th>
                    <th class="text-uppercase fw-semibold small text-center">Total User</th>
                    <th class="text-uppercase fw-semibold small text-center">Active</th>
                    <th class="text-uppercase fw-semibold small text-center">Expired</th>
                    <th class="text-uppercase fw-semibold small text-center">LOS</th>
                    <th class="text-uppercase fw-semibold small text-center">Hotspot</th>
                    <th class="text-uppercase fw-semibold small text-center">Actions</th>
                  </tr>
                </thead>
                <tbody id="dataTable">

                
                  <?php

          if ($current_user_id) {
            if ($AKSES == 'ASSISTANT') {
              if (!empty($area_list_JSON)) {
                $assigned_ids = json_decode($area_list_JSON, true);
                if (is_array($assigned_ids) && !empty($assigned_ids)) {
                  $id_in = implode(",", array_map('intval', $assigned_ids));
                  $sql = "SELECT * FROM server WHERE id IN ($id_in)";
                } else {
                  $sql = "SELECT * FROM server WHERE 1=0";
                }
              } elseif (!empty($area_list)) {
                $sql = "SELECT * FROM server WHERE AREA IN ($area_list)";
              } else {
                $sql = "SELECT * FROM server WHERE 1=0";
              }
            } else {
              $sql = "SELECT * FROM server WHERE user_id = $current_user_id";
            }
          } 
                  
                  $query = mysqli_query($conn, $sql);
                  while ($data = mysqli_fetch_array($query)) {
                    $idPel = $data['IDPEL'];
                    $id = $data['id'];
                    $ip = $data['IP'];
                    $brand = $data['BRAND'];
                    $pemilik = $data['PEMILIK'];
                    $password = $data['PASSWORD'];
                    $area = htmlspecialchars($data['AREA']);
                    $connectionMode = $data['CONNECTION_MODE'] ?? 'API';
                    $serverLogo = trim((string)($data['LOGO'] ?? ''));
                    $serverTikor = trim((string)($data['TIKOR'] ?? ''));
                    $serverLogoSrc = '';
                    if ($serverLogo !== '' && is_file(__DIR__ . '/../../dokumen/logo/' . $serverLogo)) {
                        $serverLogoSrc = '/dokumen/logo/' . rawurlencode($serverLogo);
                    } elseif (!empty($logo_path)) {
                        $serverLogoSrc = $logo_path;
                    }

                      // --- AUTO REMOTE & FIREWALL FOR EXPIRED PROFILE ---
                      // Dilewati sepenuhnya untuk server RADIUS_ONLY -- server
                      // ini SENGAJA tidak punya kredensial API yang valid,
                      // jadi percobaan connect di sini cuma buang waktu
                      // (timeout) tiap kali halaman ini dibuka, tanpa hasil.
                      $API = new RouterosAPI();
                      if ($connectionMode !== 'RADIUS_ONLY' && $API->connect($ip, $pemilik, $password)) {
                        // 1. PPPoE Profile EXPIRED
                          // 1a. Buat pool EXPIRED (15.15.15.2-15.15.15.254)
                          $pool = $API->comm('/ip/pool/print', ["?name" => "EXPIRED"]);
                          if (empty($pool)) {
                            $API->comm('/ip/pool/add', [
                              "name" => "EXPIRED",
                              "ranges" => "15.15.15.2-15.15.15.254"
                            ]);
                          }

                        $profiles = $API->comm('/ppp/profile/print', ["?name" => "EXPIRED"]);
                        if (empty($profiles)) {
                          $API->comm('/ppp/profile/add', [
                              "name" => "EXPIRED",
                              "rate-limit" => "0/0",
                              "local-address" => "15.15.15.1",
                              "remote-address" => "EXPIRED"
                          ]);
                        }

                        // 2. Firewall Address List EXPIRED 15.15.15.0/24
                        $addrList = $API->comm('/ip/firewall/address-list/print', ["?list" => "EXPIRED", "?address" => "15.15.15.0/24"]);
                        if (empty($addrList)) {
                          $API->comm('/ip/firewall/address-list/add', [
                            "list" => "EXPIRED",
                            "address" => "15.15.15.0/24"
                          ]);
                        }

                        // 3. Firewall Filter Rule src-address-list=EXPIRED action=drop
                        $filterRule = $API->comm('/ip/firewall/filter/print', ["?src-address-list" => "EXPIRED", "?action" => "drop"]);
                        if (empty($filterRule)) {
                          $API->comm('/ip/firewall/filter/add', [
                            "chain" => "forward",
                            "src-address-list" => "EXPIRED",
                            "action" => "drop",
                            "comment" => "Auto block EXPIRED 15.15.15.0/24"
                          ]);
                        }
                        $API->disconnect();
                      }

                  ?>
                    <tr class="server-card" 
                        data-server-id="<?php echo htmlspecialchars($id, ENT_QUOTES, 'UTF-8'); ?>"
                        data-server-ip="<?php echo htmlspecialchars($ip, ENT_QUOTES, 'UTF-8'); ?>"
                        data-server-user="<?php echo htmlspecialchars($pemilik, ENT_QUOTES, 'UTF-8'); ?>" 
                        data-server-pass="<?php echo htmlspecialchars($password, ENT_QUOTES, 'UTF-8'); ?>"
                        data-server-area="<?php echo htmlspecialchars($area, ENT_QUOTES, 'UTF-8'); ?>"
                        data-user="<?php echo htmlspecialchars($pemilik, ENT_QUOTES, 'UTF-8'); ?>" 
                        data-pass="<?php echo htmlspecialchars($password, ENT_QUOTES, 'UTF-8'); ?>">
                      <!-- Area / Brand Column -->
                      <td>
                        <div class="d-flex align-items-center">
                          <div class="me-3">
                            <?php if ($serverLogoSrc !== ''): ?>
                              <img src="<?php echo htmlspecialchars($serverLogoSrc, ENT_QUOTES, 'UTF-8'); ?>" class="avatar avatar-sm" alt="Logo <?php echo htmlspecialchars($brand); ?>" style="object-fit:cover;" onerror="this.onerror=null;this.src='https://img.icons8.com/3d-fluency/94/server.png';">
                            <?php else: ?>
                              <img src="https://img.icons8.com/3d-fluency/94/server.png" class="avatar avatar-sm" alt="Server">
                            <?php endif; ?>
                          </div>
                          <div>
                            <div class="fw-bold text-sm mb-1"><?php echo $brand; ?></div>
                            <div class="text-xs text-muted"><?php echo $area; ?></div>
                            <?php if ($connectionMode === 'RADIUS_ONLY'): ?>
                              <span class="badge bg-info text-dark" title="Tidak ada koneksi API ke router ini">RADIUS SAJA</span>
                            <?php endif; ?>
                          </div>
                        </div>
                      </td>
                    
                      <!-- Status Column -->
                      <td class="text-center">
                        <span id="status-<?php echo $id; ?>" class="badge bg-secondary">Checking...</span>
                      </td>

                      <!-- IP Column -->
                      <td>
                        <code class="text-xs"><?php echo $ip; ?></code>
                      </td>

                      <!-- Total User Column -->
                      <td class="text-center">
                        <span id="total_pppoe_secret-<?php echo $id; ?>" class="text-sm">
                          <i class="fas fa-database text-info"></i> <span class="badge bg-light text-dark">Loading...</span>
                        </span>
                      </td>

                      <!-- Active Column -->
                      <td class="text-center">
                        <span id="active_pppoe-<?php echo $id; ?>" class="text-sm">
                          <i class="fas fa-users text-success"></i> <span class="badge bg-light text-dark">Loading...</span>
                        </span>
                      </td>

                      <!-- Expired Column -->
                      <td class="text-center">
                        <span id="active_pppoe_expired-<?php echo $id; ?>" class="text-sm">
                          <i class="fas fa-user-clock text-warning"></i> <span class="badge bg-light text-dark">Loading...</span>
                        </span>
                      </td>

                      <!-- LOS Column -->
                      <td class="text-center">
                        <span id="inactive_pppoe_los-<?php echo $id; ?>" class="text-sm">
                          <i class="fas fa-user-slash text-danger"></i> <span class="badge bg-light text-dark">Loading...</span>
                        </span>
                      </td>

                      <!-- Hotspot Column -->
                      <td class="text-center">
                        <span id="active_hotspot-<?php echo $id; ?>" class="text-sm">
                          <i class="fas fa-wifi text-purple"></i> <span class="badge bg-light text-dark">Loading...</span>
                        </span>
                      </td>

                      <!-- Actions Column -->
                      <td class="text-center">
                        <div class="btn-group btn-group-sm" role="group">
                          <?php if ($connectionMode === 'RADIUS_ONLY'): ?>
                          <a href="?radius_script_for=<?php echo (int) $id; ?>" class="btn btn-outline-success" title="Lihat Script RADIUS untuk router ini">
                            <i class="fas fa-terminal"></i>
                          </a>
                          <?php else: ?>
                          <button
                            class="btn btn-outline-info"
                            data-bs-toggle="modal"
                            data-bs-target="#exampleoverview<?php echo $idPel; ?>"
                            title="Show Details"
                            onclick="openMonitorModal(this, '<?php echo $idPel; ?>', '<?php echo $ip; ?>', '<?php echo $pemilik; ?>', '<?php echo $password; ?>', '<?php echo htmlspecialchars($area, ENT_QUOTES, 'UTF-8'); ?>');">
                            <i class="fas fa-eye"></i>
                          </button>
                          <?php endif; ?>
                          <button
                            class="btn btn-outline-warning"
                            title="Edit Server"
                            data-ipport="<?php echo $ip; ?>"
                            data-id="<?php echo $id; ?>"
                            data-password="<?php echo $password; ?>"
                            data-brand="<?php echo htmlspecialchars(addslashes($brand)); ?>"
                            data-area="<?php echo htmlspecialchars(addslashes($area)); ?>"
                            data-pemilik="<?php echo htmlspecialchars(addslashes($pemilik)); ?>"
                            onclick="return handleEditClick(event, '<?php echo $id; ?>', '<?php echo addslashes($brand); ?>', '<?php echo addslashes($pemilik); ?>', '<?php echo addslashes($area); ?>', '<?php echo $ip; ?>', '<?php echo addslashes($password); ?>', '<?php echo htmlspecialchars($connectionMode, ENT_QUOTES, 'UTF-8'); ?>', '<?php echo addslashes($serverTikor); ?>', '<?php echo addslashes($serverLogo); ?>');">
                            <i class="fas fa-edit"></i>
                          </button>
                          <button 
                            class="btn btn-outline-danger"
                            title="Delete Server"
                            data-ipport="<?php echo $ip; ?>"
                            onclick="return handleDeleteClick(event, '<?php echo $id; ?>', '<?php echo addslashes($brand); ?>', '<?php echo addslashes($pemilik); ?>', '<?php echo addslashes($area); ?>', '<?php echo $ip; ?>', this);">
                            <i class="fas fa-trash-alt"></i>
                          </button>
                        </div>
                      </td>
                    </tr>
                    <script>
                      // Log this row data when rendered
                      (function() {
                        const row = document.currentScript.previousElementSibling;
                        if (row) {
                          const id = row.getAttribute('data-server-id');
                          const ip = row.getAttribute('data-server-ip');
                          const user = row.getAttribute('data-server-user');
                          const pass = row.getAttribute('data-server-pass');
                          console.log(`[Row Rendered] ID: ${id}, IP: ${ip}, User: ${user}, Pass: ***${pass ? pass.slice(-3) : 'none'}`);
                        }
                      })();
                    </script>

             
                  <?php } ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
       <!-- Server Details Modal -->
       <div class="modal fade" id="exampleoverview<?php echo $idPel; ?>" tabindex="-1" aria-labelledby="serverModalLabel" aria-hidden="true">
                      <div class="modal-dialog modal-lg modal-dialog-scrollable">
                        <div class="modal-content">
                          <div class="modal-header bg-gradient-primary text-white">
                            <h5 class="modal-title">Server Details - <?php echo $area; ?></h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                          </div>
                          <div class="modal-body">
                            <div class="row">
                              <div class="col-md-6">
                                <div class="card mb-3">
                                  <div class="card-header bg-gradient-info text-white">
                                    <h6 class="mb-0">Basic Information</h6>
                                  </div>
                                  <div class="card-body">
                                    <ul class="list-group list-group-flush">
                                      <li class="list-group-item d-flex justify-content-between align-items-center">
                                        <strong>IP Address:</strong>
                                        <span id="mikrotik_ip<?php echo $idPel; ?>"><?php echo $ip; ?></span>
                                      </li>
                                      <li class="list-group-item d-flex justify-content-between align-items-center">
                                        <strong>Username:</strong>
                                        <span id="mikrotik_user<?php echo $idPel; ?>"><?php echo $pemilik; ?></span>
                                      </li>
                                      <li class="list-group-item d-flex justify-content-between align-items-center">
                                        <strong>Total PPPoE Secret:</strong>
                                        <span id="total_pppoe_secret<?php echo $idPel; ?>"><i class="fas fa-database"></i> Loading...</span>
                                      </li>
                                      <li class="list-group-item d-flex justify-content-between align-items-center">
                                        <strong>User Active Non Expired:</strong>
                                        <span id="active_pppoe<?php echo $idPel; ?>"><i class="fas fa-users"></i> Loading...</span>
                                      </li>
                                      <li class="list-group-item d-flex justify-content-between align-items-center">
                                        <strong>User Active Expired:</strong>
                                        <span id="active_pppoe_expired<?php echo $idPel; ?>"><i class="fas fa-user-clock"></i> Loading...</span>
                                      </li>
                                      <li class="list-group-item d-flex justify-content-between align-items-center">
                                        <strong>User Tidak Aktif / LOS:</strong>
                                        <span id="inactive_pppoe_los<?php echo $idPel; ?>"><i class="fas fa-user-slash"></i> Loading...</span>
                                      </li>
                                      <li class="list-group-item d-flex justify-content-between align-items-center">
                                        <strong>Active Hotspot:</strong>
                                        <span id="active_hotspot<?php echo $idPel; ?>"><i class="fas fa-wifi"></i> Loading...</span>
                                      </li>
                                      <li class="list-group-item d-flex justify-content-between align-items-center">
                                        <strong>Version:</strong>
                                        <span id="version<?php echo $idPel; ?>">Loading...</span>
                                      </li>
                                      <li class="list-group-item d-flex justify-content-between align-items-center">
                                        <strong>Board Name:</strong>
                                        <span id="board_name<?php echo $idPel; ?>">Loading...</span>
                                      </li>
                                    </ul>
                                  </div>
                                </div>
                              </div>
                              <div class="col-md-6">
                                <div class="card">
                                  <div class="card-header bg-gradient-success text-white">
                                    <h6 class="mb-0">Performance Metrics</h6>
                                  </div>
                                  <div class="card-body">
                                    <ul class="list-group list-group-flush">
                                      <li class="list-group-item d-flex justify-content-between align-items-center">
                                        <strong>CPU:</strong>
                                        <span id="cpu<?php echo $idPel; ?>">Loading...</span>
                                      </li>
                                      <li class="list-group-item d-flex justify-content-between align-items-center">
                                        <strong>CPU Load:</strong>
                                        <span id="cpu_load<?php echo $idPel; ?>">Loading...</span>%
                                      </li>
                                      <li class="list-group-item d-flex justify-content-between align-items-center">
                                        <strong>Uptime:</strong>
                                        <span id="uptime<?php echo $idPel; ?>">Loading...</span>
                                      </li>
                                      <li class="list-group-item d-flex justify-content-between align-items-center">
                                        <strong>Memory:</strong>
                                        <span id="free_memory<?php echo $idPel; ?>">Loading...</span> /
                                        <span id="total_memory<?php echo $idPel; ?>">Loading...</span>
                                      </li>
                                    </ul>
                                  </div>
                                </div>
                              </div>
                            </div>
                          </div>
                          <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                          </div>
                        </div>
                      </div>
                    </div>
  <!-- Modal animasi loading universal (add/edit) -- dipakai bersama oleh
       #dataModal (Add) dan #editModal, jadi HARUS berdiri sendiri di luar
       kedua modal itu. Kalau di-nest di dalam salah satunya, Bootstrap akan
       menumpuk 2 modal + 2 backdrop sekaligus saat submit, dan tampilannya
       jadi berantakan (komponen seolah "keluar" dari card modal). -->
  <div class="modal fade" id="formLoadingModal" tabindex="-1" aria-labelledby="formLoadingModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content text-center">
        <div class="modal-header">
          <h5 class="modal-title w-100" id="formLoadingModalLabel">Menyimpan Data...</h5>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <div class="spinner-border text-primary" role="status" style="width: 4rem; height: 4rem;">
              <span class="visually-hidden">Loading...</span>
            </div>
          </div>
          <div id="formLoadingStatus">Mohon tunggu, proses sedang berjalan...</div>
        </div>
      </div>
    </div>
  </div>
  <script>
  // Show loading modal for add/edit
  function showFormLoading(mode) {
    var modal = new bootstrap.Modal(document.getElementById('formLoadingModal'));
    var status = document.getElementById('formLoadingStatus');
    var label = document.getElementById('formLoadingModalLabel');
    if (label) label.textContent = (mode === 'edit') ? 'Menyimpan Perubahan...' : 'Menyimpan Data...';
    if (status) status.textContent = (mode === 'edit') ? 'Menyimpan perubahan server, mohon tunggu...' : 'Menyimpan data server baru, mohon tunggu...';
    modal.show();
  }
  </script>
  <!-- Modal animasi koneksi -- sama seperti di atas, berdiri sendiri, dipakai
       hanya oleh form Add tapi tetap tidak boleh di-nest di dalam #dataModal. -->
  <div class="modal fade" id="connectingModal" tabindex="-1" aria-labelledby="connectingModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content text-center">
        <div class="modal-header">
          <h5 class="modal-title w-100" id="connectingModalLabel">Mencoba Koneksi ke MikroTik...</h5>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <div class="spinner-border text-primary" role="status" style="width: 4rem; height: 4rem;">
              <span class="visually-hidden">Loading...</span>
            </div>
          </div>
          <div id="connectingStatus">Percobaan koneksi...</div>
        </div>
      </div>
    </div>
  </div>
  <script>
  // Show modal and poll connection status
  function showConnectingModal() {
    var modal = new bootstrap.Modal(document.getElementById('connectingModal'));
    document.getElementById('connectingStatus').textContent = 'Percobaan koneksi...';
    modal.show();
    pollConnectionStatus(modal);
  }

  function pollConnectionStatus(modal) {
    let interval = setInterval(function() {
      fetch('proses/addserver.php?connect_status=1')
        .then(r => r.json())
        .then(data => {
          if (data && data.try && data.max) {
            document.getElementById('connectingStatus').textContent = `Percobaan koneksi ke MikroTik (${data.try} / ${data.max})...`;
          } else {
            // Selesai, tutup modal
            clearInterval(interval);
            modal.hide();
          }
        })
        .catch(() => {
          // Error polling, tetap polling
        });
    }, 800);
  }
  </script>

  <!-- Add Server Modal -->
  <div class="modal fade" id="dataModal" tabindex="-1" aria-labelledby="dataModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header bg-gradient-primary text-white">
          <h5 class="modal-title" id="dataModalLabel">Add New Server</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
  <form id="dataForm" method="post" action='proses/addserver.php' onsubmit="if (!document.getElementById('connmode_radius').checked) { showConnectingModal(); } showFormLoading('add');">
          <div class="modal-body">
            <div class="mb-3 p-3 border rounded bg-light">
              <label class="form-label fw-bold mb-2">Mode Koneksi ke Router</label>
              <div class="form-check">
                <input class="form-check-input" type="radio" name="connection_mode" id="connmode_api" value="API" checked onchange="toggleServerConnMode('')">
                <label class="form-check-label" for="connmode_api"><strong>Via API Komunikasi</strong> (default)</label>
              </div>
              <div class="form-check mb-2">
                <input class="form-check-input" type="radio" name="connection_mode" id="connmode_radius" value="RADIUS_ONLY" onchange="toggleServerConnMode('')">
                <label class="form-check-label" for="connmode_radius"><strong>RADIUS Saja</strong> (tanpa koneksi API)</label>
              </div>
              <div class="small text-muted">
                <div class="mb-2"><strong>Via API Komunikasi:</strong> billing terhubung langsung ke router lewat RouterOS API.<br>
                  <span class="text-success">+ Kontrol penuh: buat/hapus PPP secret otomatis, cek status online &amp; paket aktif real-time, push profil paket langsung ke router.</span><br>
                  <span class="text-danger">- Router harus membuka akses API ke server billing (risiko kalau billing diretas), kredensial admin router tersimpan di database billing.</span>
                </div>
                <div>
                  <strong>RADIUS Saja:</strong> billing TIDAK PERNAH terhubung ke router ini -- semua autentikasi &amp; kontrol bandwidth lewat FreeRADIUS.<br>
                  <span class="text-success">+ Lebih aman (tidak ada API/kredensial router yang perlu disimpan di billing), router cukup bisa akses port RADIUS (1812/1813).</span><br>
                  <span class="text-danger">- Billing tidak bisa cek status online/paket aktif secara real-time & tidak bisa auto-buat PPP secret lokal. Semua pelanggan router ini WAJIB pakai Mode Autentikasi "RADIUS MODE".</span>
                </div>
              </div>
            </div>
              <div class="mb-3">
              <label for="brand" class="form-label">Product / brand name / Nama bisnis internet </label>
              <input type="text" class="form-control" id="brand" name="brand" required placeholder="Jayanet or JIWANET" pattern="^(?!\s)(.*\S)?$" oninput="this.value=this.value.replace(/^\s+|\s+$/g,'')">
            </div>
            <div class="mb-3">
              <label for="area" class="form-label">Area ( Area server anda )</label>
              <input type="text" class="form-control" id="area" name="area" required placeholder="e.g., Central Office / Depok / bogor" pattern="^(?!\s)(.*\S)?$" oninput="this.value=this.value.replace(/^\s+|\s+$/g,'')">
            </div>
            <div class="mb-3">
              <label for="ipaddr" class="form-label" id="ipaddr_label">IP / Domain ( IP VPN remote menuju mikrotik anda )</label>
              <input type="text" class="form-control" id="ipaddr" name="ipaddr" required placeholder="103.10.253.22 or router.example.com / IP VPN" pattern="^(?!\s)(.*\S)?$" oninput="this.value=this.value.replace(/^\s+|\s+$/g,'')">
            </div>
            <div class="mb-3">
              <label for="add_coordinates" class="form-label">Titik Lokasi Server (Opsional) <b>Contoh format: -6.476182,106.777992</b></label>
              <div class="input-group">
                <input type="text" class="form-control" id="add_coordinates" name="coordinates" placeholder="-6.476182,106.777992">
                <button type="button" class="btn btn-outline-secondary" onclick="openMapPicker('add_coordinates')">
                  <i class="fas fa-map-marked-alt"></i> Pilih di Peta
                </button>
              </div>
            </div>
            <div id="apiModeFieldsAdd">
              <div class="row">
                <div class="col-md-6 mb-3">
                  <label for="portapi" class="form-label">API Port</label>
                  <input type="text" class="form-control" id="portapi" name="portapi" required placeholder="8728" pattern="^(?!\s)(.*\S)?$" oninput="this.value=this.value.replace(/^\s+|\s+$/g,'')">
                </div>
                <div hidden class="col-md-6 mb-3">
                  <label for="portwebfig" class="form-label">Web Port</label>
                  <input type="text" class="form-control" id="portwebfig" value="80" name="portwebfig" required placeholder="80" pattern="^(?!\s)(.*\S)?$" oninput="this.value=this.value.replace(/^\s+|\s+$/g,'')">
                </div>
              </div>
              <div class="mb-3">
                <label for="product" class="form-label">Username</label>
                <input type="text" class="form-control" id="product" name="product" required placeholder="admin" pattern="^(?!\s)(.*\S)?$" oninput="this.value=this.value.replace(/^\s+|\s+$/g,'')">
              </div>
              <div class="mb-3">
                <label for="password" class="form-label">Password</label>
                <input type="password" class="form-control" id="password" name="password" required placeholder="password admin mikrotik" pattern="^(?!\s)(.*\S)?$" oninput="this.value=this.value.replace(/^\s+|\s+$/g,'')">
              </div>
            </div>
            <div id="radiusOnlyNoticeAdd" style="display:none;" class="alert alert-info small mb-3">
              <i class="fas fa-info-circle"></i> Secret RADIUS untuk router ini akan <strong>dibuat otomatis</strong> setelah disimpan. Skrip konfigurasi untuk di-paste ke MikroTik akan ditampilkan begitu server ini tersimpan.
            </div>
            <input type="hidden" name="add" value='add'>
          </div>
        </form>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" form="dataForm" class="btn btn-primary">Save Server</button>
        </div>
      </div>
    </div>
  </div>



  <script>
    // Initialize perfect scrollbar
    var win = navigator.platform.indexOf('Win') > -1;
    if (win && document.querySelector('#sidenav-scrollbar')) {
      var options = {
        damping: '0.5'
      }
      Scrollbar.init(document.querySelector('#sidenav-scrollbar'), options);
    }

    // Hide loading screen when page is loaded
    window.addEventListener("load", function() {
      document.getElementById("loading").style.display = "none";
      document.getElementById("content").style.display = "block";
    });

    // Server monitoring functionality
    let ipList = [];
    let refreshIntervals = {};

    // Ping server and update status
    // (DIUBAH) Sekarang menerima `rowId` (id unik server di DB) agar tidak
    // terjadi collision ID elemen ketika beberapa server berbagi IP yang sama.
    function pingHost(ipserver, rowId) {
      const url = `getdata/ping.php?host=${encodeURIComponent(ipserver)}`;
      console.log(`[Ping] Starting for: ${ipserver} (rowId=${rowId}) | URL: ${url}`);
      
      fetch(url)
        .then(response => {
          console.log(`[Ping Response] IP: ${ipserver} | HTTP ${response.status} | OK: ${response.ok}`);
          if (!response.ok) {
            console.warn(`[Ping HTTP Error] Status ${response.status} for ${ipserver}`);
            throw new Error(`HTTP ${response.status}`);
          }
          return response.json();
        })
        .then(data => {
          console.log(`[Ping Data] IP: ${ipserver}`, JSON.stringify(data));
          
          if (data.error) {
            console.warn(`[Ping Error] IP: ${ipserver} | Error: ${data.error}`);
            console.warn(`[Ping Details] Errno: ${data.errno}, ErrStr: ${data.errstr}`);
            const statusElem = document.getElementById("status-" + rowId);
            if (statusElem) {
              statusElem.textContent = "Offline";
              statusElem.className = "badge bg-danger";
              console.log(`[Status Set] rowId=${rowId} -> Offline (error: ${data.error})`);
            } else {
              console.warn(`[Element Missing] ID: status-${rowId}`);
            }
            return;
          }
          
          const statusElem = document.getElementById("status-" + rowId);
          if (statusElem) {
            statusElem.textContent = data.status || "Unknown";
            statusElem.className = (data.status === "Online") ? "badge bg-success" : "badge bg-danger";
            console.log(`[Status Set] rowId=${rowId} -> ${data.status}`);

            if (data.status === "Online" && !ipList.includes(ipserver)) {
              ipList.push(ipserver);
              console.log(`[Monitor] Added to online list: ${ipserver}`);
            }
          } else {
            console.warn(`[Element Missing] ID: status-${rowId}`);
          }
        })
        .catch(error => {
          console.error(`[Ping Fetch Error] IP: ${ipserver} | Error: ${error.message}`);
          const statusElem = document.getElementById("status-" + rowId);
          if (statusElem) {
            statusElem.textContent = "Error";
            statusElem.className = "badge bg-danger";
            console.log(`[Status Set] rowId=${rowId} -> Error`);
          } else {
            console.warn(`[Element Missing] ID: status-${rowId}`);
          }
        });
    }

    // Fetch active PPPoE and Hotspot counts
    // (DIUBAH) Sekarang menerima parameter `area` (untuk hitung dari tabel
    // `pelanggan`) DAN `rowId` (id unik server di DB) agar update elemen
    // tidak salah sasaran ketika ada server dengan IP yang sama.
    function fetchActiveCounts(ipserver, user, pass, area, rowId) {
      const url = `getdata/mikrotik_info.php?ip=${encodeURIComponent(ipserver)}&user=${encodeURIComponent(user)}&password=${encodeURIComponent(pass)}&area=${encodeURIComponent(area || '')}&pemilik=${encodeURIComponent(user || '')}`;
      console.log(`[Fetch] Starting for IP: ${ipserver} (rowId=${rowId})`);
      
      fetch(url)
        .then(response => {
          console.log(`[Fetch Response] IP: ${ipserver} | HTTP ${response.status}`);
          if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
          }
          return response.json();
        })
        .then(data => {
          console.log(`[Fetch Data] IP: ${ipserver}`, data);
          
          const totalPppoeElem   = document.getElementById("total_pppoe_secret-" + rowId);
          const pppoeElem        = document.getElementById("active_pppoe-" + rowId);
          const pppoeExpiredElem = document.getElementById("active_pppoe_expired-" + rowId);
          const pppoeLosElem     = document.getElementById("inactive_pppoe_los-" + rowId);
          const hotspotElem      = document.getElementById("active_hotspot-" + rowId);

          console.log(`[Element Check] rowId=${rowId} | total=${totalPppoeElem ? 'found' : 'MISSING'}, active=${pppoeElem ? 'found' : 'MISSING'}, expired=${pppoeExpiredElem ? 'found' : 'MISSING'}, los=${pppoeLosElem ? 'found' : 'MISSING'}, hotspot=${hotspotElem ? 'found' : 'MISSING'}`);

          // Check if response has error
          if (data.error) {
            console.error("API Error for " + ipserver + ":", data.error);
            const errorMsg = '<span class="badge bg-danger">Error</span>';
            if (totalPppoeElem) totalPppoeElem.innerHTML = '<i class="fas fa-database text-info"></i> ' + errorMsg;
            if (pppoeElem) pppoeElem.innerHTML = '<i class="fas fa-users text-success"></i> ' + errorMsg;
            if (pppoeExpiredElem) pppoeExpiredElem.innerHTML = '<i class="fas fa-user-clock text-warning"></i> ' + errorMsg;
            if (pppoeLosElem) pppoeLosElem.innerHTML = '<i class="fas fa-user-slash text-danger"></i> ' + errorMsg;
            if (hotspotElem) hotspotElem.innerHTML = '<i class="fas fa-wifi text-purple"></i> ' + errorMsg;
            return;
          }
          
          if (totalPppoeElem) totalPppoeElem.innerHTML = '<i class="fas fa-database text-info"></i> <span class="badge bg-light text-dark">' + (data.total_pppoe_secret || 0) + '</span>';
          if (pppoeElem) pppoeElem.innerHTML = '<i class="fas fa-users text-success"></i> <span class="badge bg-light text-dark">' + (data.active_pppoe || 0) + '</span>';
          if (pppoeExpiredElem) pppoeExpiredElem.innerHTML = '<i class="fas fa-user-clock text-warning"></i> <span class="badge bg-light text-dark">' + (data.active_pppoe_expired || 0) + '</span>';
          if (pppoeLosElem) pppoeLosElem.innerHTML = '<i class="fas fa-user-slash text-danger"></i> <span class="badge bg-light text-dark">' + (data.inactive_pppoe_los || 0) + '</span>';
          if (hotspotElem) hotspotElem.innerHTML = '<i class="fas fa-wifi text-purple"></i> <span class="badge bg-light text-dark">' + (data.active_hotspot || 0) + '</span>';
          
          console.log(`[Data Updated] rowId=${rowId} | IP: ${ipserver} | Total: ${data.total_pppoe_secret} | Active: ${data.active_pppoe} | Expired: ${data.active_pppoe_expired} | LOS: ${data.inactive_pppoe_los} | Hotspot: ${data.active_hotspot}`);
        })
        .catch(error => {
          console.error("Fetch error for " + ipserver + ":", error);
          const errorMsg = '<span class="badge bg-danger">Error</span>';
          const totalPppoeElem   = document.getElementById("total_pppoe_secret-" + rowId);
          const pppoeElem        = document.getElementById("active_pppoe-" + rowId);
          const pppoeExpiredElem = document.getElementById("active_pppoe_expired-" + rowId);
          const pppoeLosElem     = document.getElementById("inactive_pppoe_los-" + rowId);
          const hotspotElem      = document.getElementById("active_hotspot-" + rowId);
          if (totalPppoeElem) totalPppoeElem.innerHTML = '<i class="fas fa-database text-info"></i> ' + errorMsg;
          if (pppoeElem) pppoeElem.innerHTML = '<i class="fas fa-users text-success"></i> ' + errorMsg;
          if (pppoeExpiredElem) pppoeExpiredElem.innerHTML = '<i class="fas fa-user-clock text-warning"></i> ' + errorMsg;
          if (pppoeLosElem) pppoeLosElem.innerHTML = '<i class="fas fa-user-slash text-danger"></i> ' + errorMsg;
          if (hotspotElem) hotspotElem.innerHTML = '<i class="fas fa-wifi text-purple"></i> ' + errorMsg;
        });
    }

    // Ping all servers on page load
    document.addEventListener('DOMContentLoaded', function() {
      console.log('[DOMContentLoaded] Starting data fetch...');
      
      // Initial ping + fetch for all servers, keyed by data-server-id (rowId)
      document.querySelectorAll("tr.server-card").forEach(row => {
        try {
          // Use dataset API which automatically decodes HTML entities
          const rowId = (row.dataset.serverId || row.getAttribute("data-server-id") || "").trim();
          const ip    = (row.dataset.serverIp || row.getAttribute("data-server-ip") || "").trim();
          const user  = (row.dataset.serverUser || row.getAttribute("data-server-user") || "").trim();
          const pass  = (row.dataset.serverPass || row.getAttribute("data-server-pass") || "").trim();
          const area  = (row.dataset.serverArea || row.getAttribute("data-server-area") || "").trim();

          console.log(`[Init] rowId=${rowId} | IP: ${ip}, User: ${user}, Area: ${area}, Pass length: ${pass.length}`);

          if (!rowId || !ip || !user || !pass) {
            console.warn(`[Init Skip] Incomplete data - rowId: ${rowId}, IP: ${ip}, User: ${user}, Pass: ${pass ? 'yes' : 'no'}`);
            return;
          }

          // Ping
          pingHost(ip, rowId);
          setInterval(() => pingHost(ip, rowId), 60000);

          // Active counts
          fetchActiveCounts(ip, user, pass, area, rowId);
          setInterval(() => fetchActiveCounts(ip, user, pass, area, rowId), 60000);
        } catch (error) {
          console.error('[Init Error]', error);
        }
      });
      
      console.log('[DOMContentLoaded] Data fetch initialization complete');
    });
    
    // Fallback for older browsers - retry any rows still loading
    window.addEventListener('load', function() {
      console.log('[Window Load] Checking for incomplete fetches...');
      let retryCount = 0;
      
      document.querySelectorAll("tr.server-card").forEach(row => {
        const activeElem = row.querySelector("[id^='active_pppoe-']");
        if (activeElem && activeElem.textContent.includes('Loading')) {
          const rowId = (row.dataset.serverId || row.getAttribute("data-server-id") || "").trim();
          const ip    = (row.dataset.serverIp || row.getAttribute("data-server-ip") || "").trim();
          const user  = (row.dataset.serverUser || row.getAttribute("data-server-user") || "").trim();
          const pass  = (row.dataset.serverPass || row.getAttribute("data-server-pass") || "").trim();
          const area  = (row.dataset.serverArea || row.getAttribute("data-server-area") || "").trim();
          
          if (rowId && ip && user && pass) {
            console.log(`[Retry] Re-fetching for rowId=${rowId} IP: ${ip}`);
            fetchActiveCounts(ip, user, pass, area, rowId);
            retryCount++;
          }
        }
      });
      
      if (retryCount > 0) {
        console.log(`[Retry] Retried ${retryCount} incomplete rows`);
      }
    });

    // Open server monitoring modal
    // (DIUBAH) Sekarang menerima parameter `area` agar Total/Active/Expired/LOS
    // di dalam modal detail juga dihitung dari tabel `pelanggan` di database.
    // idPel di sini sudah unik per baris (primary key pelanggan), jadi tidak
    // perlu diubah ke rowId server.
    function openMonitorModal(element, idPel, ip, user, password, area) {
      // Stop previous interval if exists
      if (refreshIntervals[idPel]) {
        clearInterval(refreshIntervals[idPel]);
      }

      // Function to fetch MikroTik info
      function fetchMikrotikInfo() {
        const elements = {
          cpu: document.getElementById('cpu' + idPel),
          cpu_load: document.getElementById('cpu_load' + idPel),
          uptime: document.getElementById('uptime' + idPel),
          free_memory: document.getElementById('free_memory' + idPel),
          total_memory: document.getElementById('total_memory' + idPel),
          version: document.getElementById('version' + idPel),
          board_name: document.getElementById('board_name' + idPel),
          ip: document.getElementById('mikrotik_ip' + idPel),
          user: document.getElementById('mikrotik_user' + idPel),
          total_pppoe_secret: document.getElementById('total_pppoe_secret' + idPel),
          active_pppoe: document.getElementById('active_pppoe' + idPel),
          active_pppoe_expired: document.getElementById('active_pppoe_expired' + idPel),
          inactive_pppoe_los: document.getElementById('inactive_pppoe_los' + idPel),
          active_hotspot: document.getElementById('active_hotspot' + idPel)
        };

        // Set loading state
        Object.values(elements).forEach(el => {
          if (el) el.textContent = "Loading...";
        });

        fetch(`getdata/mikrotik_info.php?ip=${ip}&user=${user}&password=${password}&area=${encodeURIComponent(area || '')}&pemilik=${encodeURIComponent(user || '')}`)
          .then(response => {
            if (!response.ok) {
              throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            return response.json();
          })
          .then(data => {
            if (data.error) {
              console.error("API Error:", data.error);
              const errorMsg = `<i class="fas fa-exclamation-circle"></i> ${data.error}`;
              Object.values(elements).forEach(el => {
                if (el) el.innerHTML = errorMsg;
              });
            } else {
              if (elements.cpu) elements.cpu.textContent = data.cpu;
              if (elements.cpu_load) elements.cpu_load.textContent = data.cpu_load;
              if (elements.uptime) elements.uptime.textContent = data.uptime;
              if (elements.free_memory) elements.free_memory.textContent = data.free_memory;
              if (elements.total_memory) elements.total_memory.textContent = data.total_memory;
              if (elements.version) elements.version.textContent = data.version;
              if (elements.board_name) elements.board_name.textContent = data.board_name;
              if (elements.ip) elements.ip.textContent = data.ip || ip;
              if (elements.user) elements.user.textContent = data.user || user;
              if (elements.total_pppoe_secret) elements.total_pppoe_secret.innerHTML = '<i class="fas fa-database"></i> ' + (data.total_pppoe_secret || 0);
              if (elements.active_pppoe) elements.active_pppoe.innerHTML = '<i class="fas fa-users"></i> ' + (data.active_pppoe || 0);
              if (elements.active_pppoe_expired) elements.active_pppoe_expired.innerHTML = '<i class="fas fa-user-clock"></i> ' + (data.active_pppoe_expired || 0);
              if (elements.inactive_pppoe_los) elements.inactive_pppoe_los.innerHTML = '<i class="fas fa-user-slash"></i> ' + (data.inactive_pppoe_los || 0);
              if (elements.active_hotspot) elements.active_hotspot.innerHTML = '<i class="fas fa-wifi"></i> ' + (data.active_hotspot || 0);
            }
          })
          .catch(error => {
            console.error('Error:', error);
            const errorMsg = '<i class="fas fa-exclamation-circle"></i> Connection Error';
            Object.values(elements).forEach(el => {
              if (el) el.innerHTML = errorMsg;
            });
          });
      }

      // Initial fetch
      fetchMikrotikInfo();

      // Set interval for refreshing data (every 10 seconds)
      refreshIntervals[idPel] = setInterval(fetchMikrotikInfo, 10000);

      // Clear interval when modal is closed
      document.getElementById('exampleoverview' + idPel).addEventListener('hidden.bs.modal', function() {
        clearInterval(refreshIntervals[idPel]);
      });
    }

    // Handle Edit Click - Open Edit Modal
    // Toggle field sesuai mode koneksi yang dipilih -- prefix '' untuk modal
    // Add (sembunyikan portapi/product/password sepenuhnya, semua dibuat
    // otomatis), prefix 'edit_' untuk modal Edit (cuma sembunyikan API Port,
    // field Username/Password tetap ada karena dipakai ulang sebagai
    // kunci-internal/secret-RADIUS untuk server RADIUS_ONLY).
    function toggleServerConnMode(prefix) {
      var radiusRadio = document.getElementById(prefix + 'connmode_radius');
      var isRadius = radiusRadio ? radiusRadio.checked : false;
      var isEdit = (prefix === 'edit_');
      var apiFields = document.getElementById(isEdit ? 'apiModeFieldsEdit' : 'apiModeFieldsAdd');
      var notice = document.getElementById(isEdit ? 'radiusOnlyNoticeEdit' : 'radiusOnlyNoticeAdd');
      if (apiFields) apiFields.style.display = isRadius ? 'none' : '';
      if (notice) notice.style.display = isRadius ? '' : 'none';

      var fieldsToToggle = isEdit ? ['portapi'] : ['portapi', 'product', 'password'];
      fieldsToToggle.forEach(function (name) {
        var el = document.getElementById(prefix + name);
        if (el) el.required = !isRadius;
      });

      if (isEdit) {
        var passLabel = document.getElementById('edit_password_label');
        var userLabel = document.getElementById('edit_pemilik_label');
        if (passLabel) passLabel.textContent = isRadius ? 'Secret RADIUS (untuk NAS Client di FreeRADIUS)' : 'Password Admin MikroTik';
        if (userLabel) userLabel.textContent = isRadius ? 'Kode/Kunci Internal' : 'Username Admin MikroTik';
      }
    }

    function handleEditClick(event, id, brand, pemilik, area, ip, password, connectionMode, tikor, logo) {
      event.preventDefault();

      // Pisahkan IP dan Port
      var parts = ip.split(':');
      var ipAddr = parts[0] || '';
      var port = parts[1] || '8728';

      // Set form values
      document.getElementById('edit_id').value = id;
      document.getElementById('edit_brand').value = decodeURIComponent(brand);
      document.getElementById('edit_pemilik').value = decodeURIComponent(pemilik);
      document.getElementById('edit_area').value = decodeURIComponent(area);
      document.getElementById('edit_ipaddr').value = ipAddr;
      document.getElementById('edit_portapi').value = port;
      document.getElementById('edit_password').value = password;
      document.getElementById('edit_coordinates').value = tikor ? decodeURIComponent(tikor) : '';

      // Tampilkan preview logo server saat ini (kalau ada) & tombol hapus
      var logoPreview = document.getElementById('edit_logo_preview');
      var logoEmptyNote = document.getElementById('edit_logo_empty_note');
      var logoRemoveBtn = document.getElementById('edit_logo_remove_btn');
      var logoFileInput = document.getElementById('edit_logo_file');
      if (logoFileInput) logoFileInput.value = '';
      var decodedLogo = logo ? decodeURIComponent(logo) : '';
      if (decodedLogo) {
        if (logoPreview) {
          logoPreview.src = '/dokumen/logo/' + encodeURIComponent(decodedLogo);
          logoPreview.style.display = '';
        }
        if (logoEmptyNote) logoEmptyNote.style.display = 'none';
        if (logoRemoveBtn) logoRemoveBtn.style.display = '';
      } else {
        if (logoPreview) logoPreview.style.display = 'none';
        if (logoEmptyNote) logoEmptyNote.style.display = '';
        if (logoRemoveBtn) logoRemoveBtn.style.display = 'none';
      }

      // Set mode koneksi & toggle field API sesuai data server ini
      var isRadiusOnly = connectionMode === 'RADIUS_ONLY';
      var editApiRadio = document.getElementById('edit_connmode_api');
      var editRadiusRadio = document.getElementById('edit_connmode_radius');
      if (editApiRadio) editApiRadio.checked = !isRadiusOnly;
      if (editRadiusRadio) editRadiusRadio.checked = isRadiusOnly;
      toggleServerConnMode('edit_');

      // Show edit modal
      var editModal = new bootstrap.Modal(document.getElementById('editModal'));
      editModal.show();

      return false;
    }

    // Upload / hapus logo khusus server ini -- form terpisah (fetch/AJAX)
    // dari form edit utama karena tidak boleh nested <form> di dalam <form>.
    function uploadServerLogo() {
      var id = document.getElementById('edit_id').value;
      var fileInput = document.getElementById('edit_logo_file');
      if (!id) { alert('Buka form edit server dulu.'); return; }
      if (!fileInput || !fileInput.files || !fileInput.files[0]) {
        alert('Pilih file logo (JPG/PNG) dulu.');
        return;
      }
      var fd = new FormData();
      fd.append('server_id', id);
      fd.append('server_logo', fileInput.files[0]);
      fetch('proses/upload_server_logo.php', { method: 'POST', body: fd })
        .then(function() { window.location.reload(); })
        .catch(function() { alert('Gagal upload logo server.'); });
    }

    function hapusServerLogo() {
      var id = document.getElementById('edit_id').value;
      if (!id) { alert('Buka form edit server dulu.'); return; }
      if (!confirm('Hapus logo khusus server ini? Server ini akan kembali memakai logo akun billing.')) return;
      var body = new URLSearchParams();
      body.set('server_id', id);
      fetch('proses/hapus_server_logo.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: body.toString()
      })
        .then(function() { window.location.reload(); })
        .catch(function() { alert('Gagal menghapus logo server.'); });
    }

    // Handle Delete Click - Confirm and Delete
    function handleDeleteClick(event, id, brand, pemilik, area, ip, button) {
      event.preventDefault();
      
      if (confirm(`Yakin hapus server?\n\nBrand: ${decodeURIComponent(brand)}\nArea: ${decodeURIComponent(area)}\nIP: ${ip}`)) {
        // Submit delete
        window.location.href = `proses/deleteserver.php?id=${encodeURIComponent(id)}&ip=${encodeURIComponent(ip)}&area=${encodeURIComponent(area)}&pemilik=${encodeURIComponent(pemilik)}`;
      }
      
      return false;
    }

    // Nav link hover effects
    document.addEventListener("DOMContentLoaded", function() {
      const navLinks = document.querySelectorAll(".nav-link");

      navLinks.forEach((link) => {
        link.addEventListener("mouseenter", function() {
          this.classList.add("active");
        });

        link.addEventListener("mouseleave", function() {
          if (!this.classList.contains("active")) {
            this.classList.remove("active");
          }
        });

        link.addEventListener("click", function(event) {
          navLinks.forEach((el) => el.classList.remove("active"));
          this.classList.add("active");
        });
      });
    });
  </script>
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
<div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-scrollable">
    <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="editModalLabel">Edit Server</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
  <form id="editForm" action="proses/editserver.php" method="POST" onsubmit="showFormLoading('edit');">
        <div class="modal-body">
          <!-- Hidden fields -->
          <input type="hidden" name="edit" value="edit">
          <input type="hidden" id="edit_id" name="id">

          <div class="mb-3 p-3 border rounded bg-light">
            <label class="form-label fw-bold mb-2">Mode Koneksi ke Router</label>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="connection_mode" id="edit_connmode_api" value="API" onchange="toggleServerConnMode('edit_')">
              <label class="form-check-label" for="edit_connmode_api"><strong>Via API Komunikasi</strong></label>
            </div>
            <div class="form-check mb-2">
              <input class="form-check-input" type="radio" name="connection_mode" id="edit_connmode_radius" value="RADIUS_ONLY" onchange="toggleServerConnMode('edit_')">
              <label class="form-check-label" for="edit_connmode_radius"><strong>RADIUS Saja</strong> (tanpa koneksi API)</label>
            </div>
            <div class="small text-muted">
              Ganti mode di sini kalau mau memindahkan router ini antara dikendalikan lewat API atau lewat RADIUS saja.
              Pindah ke <strong>RADIUS Saja</strong> akan menampilkan skrip MikroTik baru setelah disimpan -- pastikan semua pelanggan router ini memakai Mode Autentikasi "RADIUS MODE".
            </div>
          </div>

          <div class="mb-3">
            <label for="edit_brand" class="form-label">Product / Brand Name / Nama Bisnis Internet</label>
            <input type="text" class="form-control" id="edit_brand" name="brand" required pattern="^(?!\s)(.*\S)?$" oninput="this.value=this.value.replace(/^\s+|\s+$/g,'')">
          </div>
          <div class="mb-3">
            <label for="edit_area" class="form-label">Area</label>
            <input type="text" class="form-control" id="edit_area" name="area" required pattern="^(?!\s)(.*\S)?$" oninput="this.value=this.value.replace(/^\s+|\s+$/g,'')">
          </div>

          <div class="mb-3">
            <label for="edit_ipaddr" class="form-label">IP / Domain</label>
            <input type="text" class="form-control" id="edit_ipaddr" name="ipaddr" required pattern="^(?!\s)(.*\S)?$" oninput="this.value=this.value.replace(/^\s+|\s+$/g,'')">
          </div>

          <div id="apiModeFieldsEdit">
            <div class="row">
              <div class="col-md-6 mb-3">
                <label for="edit_portapi" class="form-label">API Port</label>
                <input type="text" class="form-control" id="edit_portapi" name="portapi" required pattern="^(?!\s)(.*\S)?$" oninput="this.value=this.value.replace(/^\s+|\s+$/g,'')">
              </div>

              <div hidden class="col-md-6 mb-3">
                <label for="edit_portwebfig" class="form-label">WebFig Port</label>
                <input type="text" class="form-control" value="80" id="edit_portwebfig" name="portwebfig" pattern="^(?!\s)(.*\S)?$" oninput="this.value=this.value.replace(/^\s+|\s+$/g,'')">
              </div>
            </div>
          </div>
          <div id="radiusOnlyNoticeEdit" style="display:none;" class="alert alert-info small mb-3">
            <i class="fas fa-info-circle"></i> Router ini tidak akan dihubungi lewat API. "Secret RADIUS" di bawah dipakai sebagai secret NAS Client di FreeRADIUS untuk router ini.
          </div>

          <div class="row">
            <div class="col-md-6 mb-3">
              <label for="edit_pemilik" class="form-label" id="edit_pemilik_label">Username</label>
              <input type="text" class="form-control" id="edit_pemilik" name="pemilik" required pattern="^(?!\s)(.*\S)?$" oninput="this.value=this.value.replace(/^\s+|\s+$/g,'')">
            </div>

            <div class="col-md-6 mb-3">
              <label for="edit_password" class="form-label" id="edit_password_label">Password</label>
              <input type="password" class="form-control" id="edit_password" name="password" required pattern="^(?!\s)(.*\S)?$" oninput="this.value=this.value.replace(/^\s+|\s+$/g,'')">
              <div id="editCredentialRotateNoticeApi" class="form-text">
                Ganti Username/Password di sini akan otomatis: kalau router <strong>online</strong>, sistem konek dulu pakai kredensial lama lalu membuat kredensial baru (auto-generate) di MikroTik; kalau <strong>offline</strong>, nilai yang diketik di sini langsung disimpan ke database tanpa menyentuh router.
              </div>
            </div>
          </div>

          <div class="mb-3">
            <label for="edit_coordinates" class="form-label">Titik Lokasi Server (Opsional) <b>Contoh format: -6.476182,106.777992</b></label>
            <div class="input-group">
              <input type="text" class="form-control" id="edit_coordinates" name="coordinates" placeholder="-6.476182,106.777992">
              <button type="button" class="btn btn-outline-secondary" onclick="openMapPicker('edit_coordinates')">
                <i class="fas fa-map-marked-alt"></i> Pilih di Peta
              </button>
            </div>
          </div>

          <div class="mb-3 p-3 border rounded">
            <label class="form-label fw-bold mb-2">Logo Server (Opsional)</label>
            <div class="d-flex align-items-center gap-2 mb-2">
              <img id="edit_logo_preview" src="" alt="Logo server" style="height:42px;width:42px;object-fit:cover;border-radius:6px;border:1px solid #dee2e6;display:none;">
              <span id="edit_logo_empty_note" class="text-muted small">Belum ada logo khusus untuk server ini -- memakai logo akun billing secara default.</span>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center">
              <input type="file" id="edit_logo_file" accept="image/png,image/jpeg" class="form-control form-control-sm" style="max-width:260px;">
              <button type="button" class="btn btn-sm btn-outline-primary" onclick="uploadServerLogo()">
                <i class="fas fa-upload"></i> Upload Logo
              </button>
              <button type="button" class="btn btn-sm btn-outline-danger" id="edit_logo_remove_btn" onclick="hapusServerLogo()" style="display:none;">
                <i class="fas fa-trash-alt"></i> Hapus Logo
              </button>
            </div>
            <div class="small text-muted mt-1">JPG/PNG, maksimum 2MB. Kalau tidak diupload, server ini otomatis memakai logo akun billing Anda.</div>
          </div>
<script>
// Prevent leading/trailing spaces on all text and password inputs before submit (add & edit server)
document.addEventListener('DOMContentLoaded', function() {
  // Add Server form
  var addForm = document.getElementById('dataForm');
  if (addForm) {
    addForm.addEventListener('submit', function(e) {
      var inputs = addForm.querySelectorAll('input[type="text"], input[type="password"]');
      let hasTrimError = false;
      inputs.forEach(function(input) {
        if (/^\s|\s$/.test(input.value)) {
          hasTrimError = true;
          input.classList.add('is-invalid');
        } else {
          input.classList.remove('is-invalid');
        }
        input.value = input.value.replace(/^\s+|\s+$/g, '');
      });
      if (hasTrimError) {
        alert('Input tidak boleh ada spasi di awal atau di akhir!');
        e.preventDefault();
      }
    });
  }
  // Edit Server form
  var editForm = document.querySelector('#editModal form[action="proses/editserver.php"]');
  if (editForm) {
    editForm.addEventListener('submit', function(e) {
      var inputs = editForm.querySelectorAll('input[type="text"], input[type="password"]');
      let hasTrimError = false;
      inputs.forEach(function(input) {
        if (/^\s|\s$/.test(input.value)) {
          hasTrimError = true;
          input.classList.add('is-invalid');
        } else {
          input.classList.remove('is-invalid');
        }
        input.value = input.value.replace(/^\s+|\s+$/g, '');
      });
      if (hasTrimError) {
        alert('Input tidak boleh ada spasi di awal atau di akhir!');
        e.preventDefault();
      }
    });
  }
});
</script>
          </div>

      </form>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="submit" form="editForm" class="btn btn-primary">Simpan</button>
        </div>
    </div>
  </div>
</div>

<script>

document.addEventListener('DOMContentLoaded', function() {
  var editModal = document.getElementById('editModal');
  editModal.addEventListener('show.bs.modal', function(event) {
    var button = event.relatedTarget;
    // Ambil data dari atribut tombol
    var id        = button.getAttribute('data-id') || '';
    var ipport    = button.getAttribute('data-ipport') || '';
    var password  = button.getAttribute('data-password') || '';
    var brand     = button.getAttribute('data-brand') || '';
    var area      = button.getAttribute('data-area') || '';
    var pemilik   = button.getAttribute('data-pemilik') || '';
    var portwebfig = button.getAttribute('data-portwebfig') || '';
    // Pisahkan IP dan Port
    var parts = ipport.split(':');
    var ip   = parts[0] || '';
    var port = parts[1] || '';
    // Isi ke form modal
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_ipaddr').value = ip;
    document.getElementById('edit_portapi').value = port;
    document.getElementById('edit_portwebfig').value = portwebfig;
    document.getElementById('edit_password').value = password;
    document.getElementById('edit_brand').value = brand;
    document.getElementById('edit_area').value = area;
    document.getElementById('edit_pemilik').value = pemilik;

    // Cek flag dari checkEditServerDependency
    var canEdit = (typeof window.canEditBrandArea !== 'undefined') ? window.canEditBrandArea : false;
    var brandInput = document.getElementById('edit_brand');
    var areaInput = document.getElementById('edit_area');
    var brandLockLabel = document.getElementById('brandLockLabel');
    var areaLockLabel = document.getElementById('areaLockLabel');
    if (canEdit) {
      brandInput.removeAttribute('readonly');
      areaInput.removeAttribute('readonly');
      brandInput.classList.remove('is-invalid');
      areaInput.classList.remove('is-invalid');
      if (brandLockLabel) brandLockLabel.style.display = 'none';
      if (areaLockLabel) areaLockLabel.style.display = 'none';
    } else {
      brandInput.setAttribute('readonly', 'readonly');
      areaInput.setAttribute('readonly', 'readonly');
      brandInput.classList.remove('is-invalid');
      areaInput.classList.remove('is-invalid');
      if (brandLockLabel) brandLockLabel.style.display = '';
      if (areaLockLabel) areaLockLabel.style.display = '';
    }
  });
});
</script>


<style>
/* Card Header Styling - Standard Bootstrap */
.card-header {
  border-bottom: 1px solid rgba(0, 0, 0, 0.1);
}

.card-header.bg-primary {
  background-color: #0d6efd !important;
}

.card-header h5 {
  font-weight: 600;
  font-size: 1.1rem;
  color: white !important;
}

.card-header h5 i {
  font-size: 1.15rem;
}

/* Button Styling */
.btn-group-sm > .btn {
  padding: 0.375rem 0.6rem;
  font-size: 0.85rem;
  border-radius: 0.25rem;
}

.btn-outline-info, .btn-outline-warning, .btn-outline-danger {
  border-width: 1.5px;
  transition: all 0.2s ease;
}

.btn-outline-info:hover {
  background-color: #0dcaf0;
  border-color: #0dcaf0;
  color: white;
  transform: translateY(-1px);
}

.btn-outline-warning:hover {
  background-color: #ffc107;
  border-color: #ffc107;
  color: #000;
  transform: translateY(-1px);
}

.btn-outline-danger:hover {
  background-color: #dc3545;
  border-color: #dc3545;
  color: white;
  transform: translateY(-1px);
}

/* Table Styling */
.table {
  color: inherit;
}

.table tbody tr {
  border-bottom: 1px solid #dee2e6;
  transition: background-color 0.15s ease;
}

.table tbody tr:hover {
  background-color: #f8f9fa;
}

.table thead th {
  font-weight: 600;
  border-bottom: 2px solid rgba(0,0,0,0.1);
  vertical-align: middle;
  padding: 0.75rem 0.5rem;
}

/* Card Header Styling - ensure text is visible */
.card-header.bg-gradient-primary {
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
  box-shadow: 0 2px 4px rgba(0,0,0,0.1);
  border-bottom: 2px solid rgba(255, 255, 255, 0.2);
}

.card-header.bg-gradient-primary h5 {
  font-weight: 700 !important;
  letter-spacing: 0.5px;
  color: #ffffff !important;
  text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
}

.card-header.bg-gradient-primary .text-white {
  color: #ffffff !important;
}

/* Status Badge */
.badge {
  padding: 0.4rem 0.6rem;
  font-weight: 500;
  font-size: 0.8rem;
  border-radius: 0.25rem;
}

/* Data Cell Styling */
.table td {
  vertical-align: middle;
  padding: 0.75rem;
}

.table-responsive {
  border-radius: 0.375rem;
  box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}

/* Button Row Styling */
.mb-4.pb-3.border-bottom {
  background-color: #f8f9fa;
  border-radius: 0.375rem;
  padding: 1.25rem !important;
  margin-bottom: 1.5rem !important;
}

.btn-group-sm {
  gap: 0.25rem;
  display: flex;
  flex-wrap: wrap;
}

/* Code/IP Styling */
code {
  background-color: #f0f0f0;
  padding: 0.25rem 0.5rem;
  border-radius: 0.25rem;
  color: #495057;
}

/* Text utilities */
.fw-bold {
  font-weight: 600;
}

.text-muted {
  color: #6c757d !important;
}

/* Avatar styling */
.avatar-sm {
  width: 2.5rem;
  height: 2.5rem;
}

/* Icon colors */
.fa-database { color: #0d6efd; }
.fa-users { color: #198754; }
.fa-user-clock { color: #ffc107; }
.fa-user-slash { color: #dc3545; }
.fa-wifi { color: #6f42c1; }

/* Loading Spinner */
.badge.bg-light {
  font-size: 0.75rem;
  padding: 0.25rem 0.5rem;
}

/* Card spacing */
.card {
  border: none;
  border-radius: 0.5rem;
  overflow: hidden;
}

.card-body {
  padding: 1.5rem;
}

/* Responsive adjustments */
@media (max-width: 768px) {
  .btn-group-sm {
    flex-direction: column;
    gap: 0.5rem;
  }

  .btn-group-sm > .btn {
    width: 100%;
  }

  .table-responsive {
    font-size: 0.875rem;
  }

  .table td {
    padding: 0.5rem;
  }
}
</style>

<?php require 'footer.php'; ?>