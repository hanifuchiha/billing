<?php require 'header.php'; ?>

<style>
.sidebar,
.navbar,
.left-sidebar,
.main-sidebar,
.side-menu {
    display: none !important;
}

.content-page,
.main-content,
.page-content {
    margin-left: 0 !important;
    padding-left: 0 !important;
}

.olt-preview-box,
.olt-process-log {
    background: #111827;
    color: #d1fae5;
    border: 1px solid #374151;
    border-radius: 0.5rem;
    padding: 0.85rem 1rem;
    font-family: Consolas, Monaco, monospace;
    font-size: 0.82rem;
    line-height: 1.55;
    white-space: pre-wrap;
}

.olt-preview-box {
    min-height: 120px;
}

.olt-process-log {
    min-height: 180px;
    max-height: 360px;
    overflow-y: auto;
}

#mapPickerContainer { position: relative; z-index: 1; }
.leaflet-pane, .leaflet-top, .leaflet-bottom { z-index: 2; }
@media (max-width: 576px) {
  #mapPickerModal .modal-dialog { margin: 0.5rem; }
  #mapPickerContainer { height: 320px !important; }
}
</style>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.css" />
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.js"></script>

<!-- Map Picker Modal -->
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
// Map Picker: pilih koordinat customer lewat peta Leaflet
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

function openMapPicker(inputId) {
    mapPickerTargetInputId = inputId;

    var modalEl = document.getElementById('mapPickerModal');
    var bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);

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

<div class="container mt-5">
    <form method="post" action="proses/addcustomer.php" target="_top">
        <?php $registerOnly = (isset($_GET['register_only']) && $_GET['register_only'] === '1') ? '1' : '0'; ?>
        <input type="hidden" name="register_only" value="<?php echo htmlspecialchars($registerOnly, ENT_QUOTES, 'UTF-8'); ?>">
        <?php if ($registerOnly === '1'): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i>
            PPPoE ini <strong>sudah terdaftar di Mikrotik</strong> (hasil scan). Mengirim form ini hanya akan
            <strong>menambahkan data pelanggan ke database billing</strong> — secret PPPoE di Mikrotik tidak akan dibuat ulang.
        </div>
        <?php endif; ?>

            <?php

$kode_terkecil = null;

if($inisial=='')
{
$words = explode(" ", strtoupper($username));
$initials = "";
foreach ($words as $word) {
    $initials .= substr($word, 0, 1);
    if (strlen($initials) >= 3) break;
}
// Jika kurang dari 3 huruf, tambahkan dari huruf berikutnya
if (strlen($initials) < 3) {
    $initials .= strtoupper(substr(str_replace(" ", "", $username), strlen($initials), 3 - strlen($initials)));
}
$inisial = substr($initials, 0, 3);
}

            $used_numbers = [];
            $prefix_like = $inisial . '-%';
            $stmtKode = $conn->prepare("SELECT IDPEL FROM `pelanggan` WHERE `IDPEL` LIKE ?");
            if ($stmtKode) {
                $stmtKode->bind_param("s", $prefix_like);
                $stmtKode->execute();
                $resultKode = $stmtKode->get_result();

                while ($rowKode = $resultKode->fetch_assoc()) {
                    $idpel = $rowKode['IDPEL'];
                    if (preg_match('/^' . preg_quote($inisial, '/') . '-(\d{3})(?:@|$)/', $idpel, $matches)) {
                        $nomor = (int)$matches[1];
                        if ($nomor >= 1 && $nomor <= 999) {
                            $used_numbers[$nomor] = true;
                        }
                    }
                }

                $stmtKode->close();
            }

            // Juga cek tabel provisioning agar tidak bentrok dengan ID yang sedang PENDING
            $check_prov_tbl = mysqli_query($conn, "SHOW TABLES LIKE 'provisioning'");
            if ($check_prov_tbl && mysqli_num_rows($check_prov_tbl) > 0) {
                $stmtProvKode = $conn->prepare("SELECT idpel FROM provisioning WHERE idpel LIKE ? AND status='PENDING'");
                if ($stmtProvKode) {
                    $stmtProvKode->bind_param("s", $prefix_like);
                    $stmtProvKode->execute();
                    $resultProvKode = $stmtProvKode->get_result();
                    while ($rowProv = $resultProvKode->fetch_assoc()) {
                        if (preg_match('/^' . preg_quote($inisial, '/') . '-(\d{3})(?:@|$)/', $rowProv['idpel'], $matches)) {
                            $nomor = (int)$matches[1];
                            if ($nomor >= 1 && $nomor <= 999) {
                                $used_numbers[$nomor] = true;
                            }
                        }
                    }
                    $stmtProvKode->close();
                }
            }

            for ($i = 1; $i <= 999; $i++) {
                if (!isset($used_numbers[$i])) {
                    $kode_terkecil = $inisial . "-" . str_pad($i, 3, '0', STR_PAD_LEFT);
                    break;
                }
            }

            // Tampilkan kode terkecil yang ditemukan
            if ($kode_terkecil) {
                echo "ID Pelanggan ini belum di gunakan <br> silahkan gunakan ID pelanggan berikut : <b>" . $kode_terkecil . "@" . date('dmy') . "</b>";
            } else {
                echo "Semua kode dari FQ-001 hingga FQ-200 sudah ada di database.";
            }


            ?>





<br><hr>
            <div class="mb-3">
                <label for="customerID" class="form-label">Customer ID ( PPPOE username )
                    <?php echo $registerOnly === '1' ? '<b>Harus sama dengan username PPPoE di Mikrotik</b>' : '<b>Boleh diganti </b>'; ?>
                </label>
                <input required type="text" class="form-control" id="customerID" name="customerID" <?php echo $registerOnly === '1' ? 'readonly' : ''; ?> value="<?php echo isset($_GET['pppoe_username']) && $_GET['pppoe_username'] !== '' ? htmlspecialchars(trim($_GET['pppoe_username']), ENT_QUOTES, 'UTF-8') : $kode_terkecil . "@" . date('dmy') ?>">
            </div>
            <div class="mb-3">
                <label for="customerID" class="form-label">password PPPOE ( PPPOE password )
                    <?php echo $registerOnly === '1' ? '<b>Isi sesuai password PPPoE yang sudah ada di Mikrotik (untuk dicatat di billing)</b>' : '<b>Boleh diganti </b>'; ?>
                </label>
                <input required type="text" class="form-control" id="passwordPPPOE" name="passwordPPPOE" value="<?php echo $kode_terkecil . "@" . date('dmy') ?>">
            </div>
            <div class="mb-3">
                <label for="nik" class="form-label">NIK (Nomor Induk Kependudukan)</label>
                <input type="text" class="form-control" id="nik" name="NIK" maxlength="20" placeholder="Masukkan NIK 16 digit">
            </div>
                <div class="mb-3">
                    <label for="authmode" class="form-label">Auth Mode</label>
                    <select required class="form-control" id="authmode" name="authmode">
                        <option value="" disabled selected>Pilih Auth Mode</option>
                        <option value="API MODE" <?php if(isset($authmode) && $authmode=="API MODE") echo "selected"; ?>>API MODE</option>
                        <option value="RADIUS MODE" <?php if(isset($authmode) && $authmode=="RADIUS MODE") echo "selected"; ?>>RADIUS MODE</option>
                        <option selected value="MULTI MODE" <?php if(isset($authmode) && $authmode=="MULTI MODE") echo "selected"; ?>>MULTI MODE</option>

                    </select>
                    <small id="authmode_locked_note" class="text-warning d-none">
                        Server ini dikonfigurasi <b>RADIUS SAJA</b> (tanpa API Mikrotik) -- Auth Mode dikunci ke RADIUS MODE.
                    </small>
                </div>

            <div class="mb-3">
                <label for="customerName" class="form-label">Customer Name</label>
                <input required type="text" class="form-control" id="customerName" name="customerName" value="<?php echo htmlspecialchars($data1['NAMA']); ?>">
            </div>


            <div class="mb-3">
                <label for="address" class="form-label">Address</label>
                <input required type="text" class="form-control" id="address" name="address" value="<?php echo htmlspecialchars($data1['ALAMAT']); ?>">
            </div>
            <div class="mb-3">
                <div class="row g-2">
                    <div class="col-md-6">
                        <label class="mb-1">Provinsi</label>
                        <select id="provinsi_addcust" name="provinsi"  class="form-control mb-2" required><option value="">Pilih Provinsi</option></select>
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
                        <input type="text" name="rw" class="form-control mb-2" placeholder="RW" required oninput="this.value=this.value.replace(/\D/g,'').replace(/^0+(?=\d)/,'')">
                    </div>
                    <div class="col-md-6">
                        <label class="mb-1">RT</label>
                        <input type="text" name="rt" class="form-control mb-2" placeholder="RT" required oninput="this.value=this.value.replace(/\D/g,'').replace(/^0+(?=\d)/,'')">
                    </div>
                </div>
            </div>
<script>
// Wilayah Indonesia (EMSIFA) untuk Add Customer
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
</script>

            <div class="mb-3">
                <label for="whatsapp" class="form-label">WhatsApp Number   <b>Contoh format : 62878xxxxxx</b></label>
                <input required type="text" class="form-control" id="whatsapp" name="whatsapp" value="<?php echo htmlspecialchars($data1['NOWA']); ?>">
            </div>

            <div class="mb-3">
                <label for="whatsapp" class="form-label">Email</label>
                <input required type="email" class="form-control" id="Email" name="Email" value="<?php echo htmlspecialchars($data1['EMAIL']); ?>">
            </div>

            <div class="mb-3">
                <label for="coordinates" class="form-label">Coordinates    <b>Contoh format : -6.476182,106.777992</b> </label>
                <div class="input-group">
                    <input required type="text" class="form-control" id="coordinates" name="coordinates" value="<?php echo htmlspecialchars($data1['TIKOR']); ?>">
                    <button type="button" class="btn btn-outline-secondary" onclick="openMapPicker('coordinates')">
                        <i class="fas fa-map-marked-alt"></i> Pilih dari Maps
                    </button>
                </div>
            </div>

            <div class="mb-3">
                <label for="tanggalpasang" class="form-label">Tanggal mulai tagihan awal (invoice start Date)</label>
                <input required type="date" class="form-control" id="tanggalpasang" name="tanggalpasang" value="<?php echo date('Y-m-d'); ?>">
            </div>


            <div class="mb-3">
                <label for="server" class="form-label">Sales (username CRM Sales)</label>
                <select required class="form-control" id="sales" name="sales">
                    <option value="">-- Pilih sales --</option>
                    <option value="-">TANPA SALES</option>
                    <?php


                    $query = "SELECT DISTINCT nama FROM mitra  WHERE server='$ceknama' ";
                    $result = mysqli_query($conn, $query);
                    while ($row = mysqli_fetch_assoc($result)) {
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
    <select required class="form-select mt-1" id="server" name="server">
        <option value="">-- Pilih Server Area --</option>
        <?php
                                          if ($current_user_id) {
                                                  
            if ($AKSES == 'ASSISTANT') {

                                        $queryServer = mysqli_query($conn, "SELECT DISTINCT PEMILIK, BRAND, AREA, CONNECTION_MODE FROM server WHERE AREA IN ($area_list)");

            } else {

                                        $queryServer = mysqli_query($conn, "SELECT DISTINCT PEMILIK, BRAND, AREA, CONNECTION_MODE FROM server WHERE user_id = $current_user_id");

            }
          }


        while ($rowServer = mysqli_fetch_assoc($queryServer)) {
            $selected = (isset($data) && $rowServer['PEMILIK'] == $data['PEMILIK']) ? "selected" : "";
            $area = htmlspecialchars($rowServer['AREA']);
            $connmode = htmlspecialchars($rowServer['CONNECTION_MODE'] ?? 'API');
            echo '<option value="'.$rowServer['PEMILIK'].'" data-area="'.$area.'" data-connmode="'.$connmode.'" '.$selected.'>'.$rowServer['BRAND'].'-'.$area.'</option>';
        }
        ?>
    </select>
</div>

<div hidden class="mb-3">
    <label for="area" class="form-label">AREA</label>
    <input type="hidden" id="area" name="area">
    <input type="text" class="form-control" id="area_display" readonly>
</div>
<script>
function applyAuthModeLockAddCustomer() {
    let serverSelect = document.getElementById('server');
    let selected = serverSelect.options[serverSelect.selectedIndex];
    let connmode = selected ? selected.getAttribute('data-connmode') : '';
    let authmodeSelect = document.getElementById('authmode');
    let note = document.getElementById('authmode_locked_note');
    if (!authmodeSelect) return;
    let apiOpt = authmodeSelect.querySelector('option[value="API MODE"]');
    let multiOpt = authmodeSelect.querySelector('option[value="MULTI MODE"]');
    if (connmode === 'RADIUS_ONLY') {
        if (apiOpt) apiOpt.disabled = true;
        if (multiOpt) multiOpt.disabled = true;
        authmodeSelect.value = 'RADIUS MODE';
        note?.classList.remove('d-none');
    } else {
        if (apiOpt) apiOpt.disabled = false;
        if (multiOpt) multiOpt.disabled = false;
        note?.classList.add('d-none');
    }
}
function setAreaAddCustomer() {
    let serverSelect = document.getElementById('server');
    let areaInput = document.getElementById('area');
    let areaDisplay = document.getElementById('area_display');
    let selected = serverSelect.options[serverSelect.selectedIndex];
    const prevArea = areaInput.value;
    let area = selected ? selected.getAttribute('data-area') : '';
    areaInput.value = area;
    areaDisplay.value = area;
    applyAuthModeLockAddCustomer();

    // Trigger sinkronisasi ulang data turunan saat area berubah.
    if (prevArea !== area) {
        areaInput.dispatchEvent(new Event('change'));
    } else {
        loadODP();
        loadOltRemoteButtons();
    }
}
// Set area on page load if server is preselected
document.addEventListener('DOMContentLoaded', function() {
    setAreaAddCustomer();
});
</script>


<div class="mb-3">
    <div class="d-flex justify-content-between align-items-center">
        <label for="odp" class="form-label mb-0">ODP</label>
        <small class="text-muted">
            Jika belum ada ODP silahkan buat dulu 
            <a href="odp.php"  target='_parent' class="text-primary text-decoration-none fw-semibold">disini</a>
        </small>
    </div>
    <small class="text-muted d-block mb-1">Kategori ODP: per ODC, ODP-JUMPER, ODP-RASIO, LAIN NYA</small>
    <select required class="form-select mt-1" id="odp" name="odp" onchange="loadPackages()">
        <option disabled selected value="">-- Pilih ODP --</option>
    </select>
</div>

<div class="mb-3">
    <div class="d-flex justify-content-between align-items-center">
        <label for="packages" class="form-label mb-0">Packages</label>
        <small class="text-muted">
            Jika belum ada paket silahkan buat dulu 
            <a href="packages.php"  target='_parent' class="text-primary text-decoration-none fw-semibold">disini</a>
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
        ?>
                <option value="mengikuti_tanggal_tempo">Fixed Due Date (Tanggal <?php echo $jatuh_tempo ?>)</option>
        <?php
            } else {
        ?>
                <option disabled class="text-muted" value="mengikuti_tanggal_tempo">Fixed Due Date</option>
        <?php
            }
        }
        ?>
        <option value="mengikuti_tanggal_bayar">Rolling Due Date</option>
        <option value="monthversary">Monthversary Due Date</option>
    </select>
    <div class="alert alert-info mt-2 mb-0" style="font-size: 0.9rem;">
        <i class="fas fa-info-circle"></i>
        <strong>Apa itu Tipe Tempo?</strong> Ini menentukan kapan tagihan pelanggan jatuh tempo (harus dibayar) setiap bulannya.
        <ul class="mb-0 mt-1">
            <li><strong>Fixed Due Date</strong> (mengikuti tanggal tempo) — Semua pelanggan jatuh tempo di tanggal yang SAMA setiap bulan (sesuai pengaturan tanggal tempo utama). Cocok kalau mau menagih semua pelanggan serentak.</li>
            <li><strong>Rolling Due Date</strong> (mengikuti tanggal aktifasi) — Jatuh tempo = 1 bulan setelah pelanggan TERAKHIR bayar. Kalau bulan ini bayar tanggal 8, bulan depan jatuh tempo juga tanggal 8. Tanggalnya bisa ikut bergeser mengikuti kapan pelanggan membayar.</li>
            <li><strong>Monthversary Due Date</strong> (Baru) — Tiap pelanggan punya tanggal jatuh tempo sendiri yang TETAP, sesuai tanggal pertama kali dia pasang/aktif. Pasang tanggal 10 → jatuh tempo SELALU tanggal 10 tiap bulan, walau kadang bayarnya lebih cepat/telat. Berlaku untuk prabayar maupun pascabayar.
                <ul class="mt-1">
                    <li>Pascabayar: tanggalnya diambil dari tanggal pasang.</li>
                    <li>Prabayar: karena baru aktif setelah bayar, tanggalnya diambil dari tanggal pembayaran pertama pelanggan. Ada waktu tunggu tambahan (atur di menu Notifikasi &gt; Waktu Tunggu Prabayar) sebelum internet diisolir kalau belum bayar, jadi pelanggan tidak langsung diputus tepat di hari-H.</li>
                </ul>
            </li>
        </ul>
    </div>
</div>

<script>
    const tipeBayar = document.getElementById('tipe_bayar');
    const tipeTempo = document.getElementById('tipe_tempo');
    const tipeTempoAllowedPascabayar = ['mengikuti_tanggal_bayar', 'monthversary'];

    tipeBayar.addEventListener('change', function() {
        const selected = this.value;

        for (let i = 0; i < tipeTempo.options.length; i++) {
            let opt = tipeTempo.options[i];

            if (selected === 'pascabayar') {
                // Jika pascabayar, disable semua kecuali yang diizinkan untuk pascabayar
                opt.disabled = !tipeTempoAllowedPascabayar.includes(opt.value);
            } else {
                // Jika prabayar, enable semua
                opt.disabled = false;
            }
        }

        if (selected === 'pascabayar' && !tipeTempoAllowedPascabayar.includes(tipeTempo.value)) {
            tipeTempo.value = 'mengikuti_tanggal_bayar'; // otomatis pilih default
        }
    });
</script>


<script>
// ODP, Packages, dan otomatisasi register OLT berdasarkan server/area
const ZTE_CONSOLE_PATH = '../olt/zte/index.php';
const ZTE_ONU_TYPE_FALLBACK = [
    'HUAWEI-HG8245A','HUAWEI-HG8245H','HUAWEI-HG8245U','OPEN_FIBERHOME','OPEN_HUAWEI','OPEN_NOKIA','OPEN_ZTE',
    'ZTE-F609','ZTE-F660','ZTEG-9806H','ZTEG-F600','ZTEG-F609','ZTEG-F620','ZTEG-F625','ZTEG-F627',
    'ZTEG-F660','ZTEG-F670','ZTEG-F820','ZTEG-MSAG','ZXA10-F660'
];
let zteOnuTypeList = [...ZTE_ONU_TYPE_FALLBACK];
let currentOltList = [];
let oltFetchController = null;
let oltFetchSeq = 0;
let zteRegisterContextCache = {};
let processLogVisible = false;
const AUTO_REG_FIELD_IDS = [
    'auto-reg-onu-sel','auto-reg-sn','auto-reg-intf','auto-reg-onu-no','auto-reg-type-sel','auto-reg-type-manual',
    'auto-reg-tcont-profile','auto-reg-vlan-sel','auto-reg-vlan','auto-reg-svc','auto-reg-vlan-profile',
    'auto-reg-gemport','auto-reg-cos','auto-reg-user','auto-reg-pass','auto-reg-ethuni','auto-reg-cfg-btn'
];
const OLT_TEMPLATE_ENDPOINT = 'getdata/get_olt_template.php';
let pendingOltOntTemplate = null;

function setSelectValueEnsure(selectEl, value) {
    if (!selectEl) return;
    const val = String(value || '').trim();
    if (!val) return;
    let exists = Array.from(selectEl.options).some(opt => String(opt.value).trim() === val);
    if (!exists) {
        const opt = document.createElement('option');
        opt.value = val;
        opt.textContent = val;
        selectEl.appendChild(opt);
    }
    selectEl.value = val;
    selectEl.dispatchEvent(new Event('change'));
}

function setInputValueBySelector(selector, value) {
    const el = document.querySelector(selector);
    if (!el) return;
    el.value = value == null ? '' : String(value);
    el.dispatchEvent(new Event('input'));
}

function setSelectValueIfExists(selectEl, value) {
    if (!selectEl) return false;
    const val = String(value || '').trim();
    if (!val) return false;
    const exists = Array.from(selectEl.options).some(opt => String(opt.value).trim() === val);
    if (!exists) return false;
    selectEl.value = val;
    selectEl.dispatchEvent(new Event('change'));
    return true;
}

function applyOltTemplateToAddCustomer(template) {
    if (!template) return;
    pendingOltOntTemplate = template;

    if (!setSelectValueIfExists(document.getElementById('auto-reg-tcont-profile'), template.tcont_profile)) {
        setInputValueBySelector('#auto-reg-tcont-profile', '');
    }

    if (setSelectValueIfExists(document.getElementById('auto-reg-vlan-sel'), template.vlan_id)) {
        syncAutoRegVlanText();
    }

    setInputValueBySelector('#auto-reg-svc', template.service_name || 'HSI');
    setInputValueBySelector('#auto-reg-vlan-profile', template.vlan_profile || 'PPPoE');
    setInputValueBySelector('#auto-reg-gemport', template.gemport || '1');
    setInputValueBySelector('#auto-reg-cos', template.cos || '0');
    setInputValueBySelector('#auto-reg-ethuni', template.ethuni || '1,2,3');
    setInputValueBySelector('#auto-reg-vlan', template.vlan_manual || template.vlan_id || '');

    if (typeof setAutoRegType === 'function' && template.ont_type) {
        setAutoRegType(template.ont_type);
    }
    if (typeof updateAutoRegPreview === 'function') {
        updateAutoRegPreview();
    }
}

function loadOltTemplateForAddCustomer(oltId) {
    if (!oltId) return Promise.resolve();
    return fetch(OLT_TEMPLATE_ENDPOINT + '?olt_id=' + encodeURIComponent(oltId), { credentials: 'same-origin' })
        .then(r => r.json())
        .then(resp => {
            if (resp && resp.success && resp.data) {
                applyOltTemplateToAddCustomer(resp.data);
            } else {
                pendingOltOntTemplate = null;
            }
        })
        .catch(() => {
            pendingOltOntTemplate = null;
        });
}

function escapeHtml(text) {
    return String(text || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function appendProcessLog(message) {
    const wrap = document.getElementById('oltProcessWrap');
    const box = document.getElementById('oltProcessLog');
    if (!wrap || !box) return;
    const time = new Date().toLocaleTimeString('id-ID', { hour12: false });
    box.textContent += `[${time}] ${message}\n`;
    box.scrollTop = box.scrollHeight;
}

function setProcessLogVisible(visible) {
    processLogVisible = !!visible;
    const panel = document.getElementById('oltProcessLogPanel');
    const btn = document.getElementById('oltProcessToggleBtn');
    if (panel) panel.classList.toggle('d-none', !processLogVisible);
    if (btn) btn.textContent = processLogVisible ? 'Sembunyikan Log Proses' : 'Tampilkan Log Proses';
}

function toggleProcessLog() {
    setProcessLogVisible(!processLogVisible);
}

function clearProcessLog() {
    const box = document.getElementById('oltProcessLog');
    if (box) box.textContent = '';
    setProcessLogVisible(false);
}

function setAutoRegLoading(isLoading, message) {
    const loadingEl = document.getElementById('zteAutoRegisterLoading');
    const loadingTextEl = document.getElementById('zteAutoRegisterLoadingText');
    const refreshBtn = document.getElementById('zteRefreshDataBtn');

    if (loadingEl) loadingEl.classList.toggle('d-none', !isLoading);
    if (loadingTextEl && message) loadingTextEl.textContent = message;
    if (refreshBtn) {
        refreshBtn.disabled = !!isLoading;
        refreshBtn.textContent = isLoading ? 'Memuat data...' : 'Refresh Data OLT';
    }

    AUTO_REG_FIELD_IDS.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.disabled = !!isLoading;
    });

    if (isLoading) {
        const preview = document.getElementById('auto-reg-preview');
        if (preview) preview.textContent = '(Sedang memuat data OLT...)';
    }
}

function parseIpPort(ipData, defaultPort) {
    let ip = ipData || '';
    let port = defaultPort;
    if (ip.includes(':')) {
        const parts = ip.split(':');
        ip = parts[0];
        port = parseInt(parts[1], 10) || defaultPort;
    }
    return { ip, port };
}

function isHiosoTwoPort(brand) {
    const value = String(brand || '').toUpperCase();
    return value === 'HIOSO EPON' || value === 'HIOSO EPON HA7302CST';
}

function isZteBrand(brand) {
    return /^ZTE GPON C/i.test(String(brand || '').toUpperCase());
}

function getSelectedOlt() {
    const index = document.getElementById('oltSelect')?.value || '';
    if (index === '') return null;
    return currentOltList[parseInt(index, 10)] || null;
}

function syncPppoeCredentialsFromCustomer() {
    const userEl = document.getElementById('auto-reg-user');
    const passEl = document.getElementById('auto-reg-pass');
    const customerId = document.getElementById('customerID')?.value || '';
    const customerPass = document.getElementById('passwordPPPOE')?.value || '';
    if (userEl) userEl.value = customerId.trim();
    if (passEl) passEl.value = customerPass.trim();
    updateAutoRegPreview();
}

function populateOltSelect(list) {
    const wrap = document.getElementById('oltAutomationWrap');
    const select = document.getElementById('oltSelect');
    const empty = document.getElementById('oltAutomationEmpty');
    if (!wrap || !select || !empty) return;

    wrap.classList.remove('d-none');
    select.innerHTML = '<option value="">-- Pilih OLT --</option>';

    if (!Array.isArray(list) || !list.length) {
        empty.classList.remove('d-none');
        select.disabled = true;
        resetOltAutomationUi();
        return;
    }

    empty.classList.add('d-none');
    select.disabled = false;
    list.forEach((olt, idx) => {
        const option = document.createElement('option');
        option.value = String(idx);
        option.textContent = `${olt.oltname} | ${olt.brandolt} | ${olt.ipolt}`;
        select.appendChild(option);
    });
}

function resetOltAutomationUi() {
    const info = document.getElementById('oltSelectedInfo');
    const unsupported = document.getElementById('oltUnsupportedNote');
    const zteWrap = document.getElementById('zteAutoRegisterWrap');
    if (info) { info.classList.add('d-none'); info.innerHTML = ''; }
    if (unsupported) unsupported.classList.add('d-none');
    if (zteWrap) zteWrap.classList.add('d-none');
}

function hideOltRemoteButtons() {
    const wrap = document.getElementById('oltAutomationWrap');
    const select = document.getElementById('oltSelect');
    if (wrap) wrap.classList.add('d-none');
    if (select) {
        select.innerHTML = '<option value="">-- Pilih OLT --</option>';
        select.value = '';
    }
    resetOltAutomationUi();
    clearProcessLog();
}

function populateAutoRegTypeOptions() {
    const sel = document.getElementById('auto-reg-type-sel');
    if (!sel) return;
    const prev = sel.value;
    const manualValue = document.getElementById('auto-reg-type-manual')?.value || '';
    sel.innerHTML = '<option value="">— Pilih Type ONT —</option>';
    zteOnuTypeList.forEach(type => {
        const opt = document.createElement('option');
        opt.value = type;
        opt.textContent = type;
        sel.appendChild(opt);
    });
    const manualOpt = document.createElement('option');
    manualOpt.value = '__manual';
    manualOpt.textContent = 'Manual Input…';
    sel.appendChild(manualOpt);
    if (prev && Array.from(sel.options).some(o => o.value === prev)) sel.value = prev;
    if (manualValue) document.getElementById('auto-reg-type-manual').value = manualValue;
    onAutoRegTypeChange();
}

function onAutoRegTypeChange() {
    const manual = document.getElementById('auto-reg-type-manual');
    const sel = document.getElementById('auto-reg-type-sel');
    if (!manual || !sel) return;
    manual.style.display = sel.value === '__manual' ? '' : 'none';
    updateAutoRegPreview();
}

function setAutoRegType(typeValue) {
    const sel = document.getElementById('auto-reg-type-sel');
    const manual = document.getElementById('auto-reg-type-manual');
    const value = String(typeValue || '').trim();
    if (!sel || !manual) return;
    if (value && zteOnuTypeList.includes(value)) {
        sel.value = value;
        manual.value = '';
    } else if (value) {
        sel.value = '__manual';
        manual.value = value;
    } else {
        sel.value = '';
        manual.value = '';
    }
    onAutoRegTypeChange();
}

function getAutoRegType() {
    const value = document.getElementById('auto-reg-type-sel')?.value || '';
    if (value === '__manual') {
        return (document.getElementById('auto-reg-type-manual')?.value || '').trim();
    }
    return value.trim();
}

function parseZteUncfg(raw) {
    const rows = [];
    let currentIntf = '';
    String(raw || '').split('\n').forEach(line => {
        const intfMatch = line.match(/interface\s+(gpon-olt_\S+)/i);
        if (intfMatch) {
            currentIntf = intfMatch[1];
            return;
        }
        const onuMatch = line.match(/onu\s+(\d+)\s+type\s+(\S+)\s+sn\s+(\S+)/i);
        if (onuMatch) {
            rows.push({ intf: currentIntf, onu: onuMatch[1], type: onuMatch[2], sn: onuMatch[3] });
        }
    });
    return rows;
}

function parseZteVlanSum(raw) {
    const match = String(raw || '').match(/Details are following:\s*([\d ,]+)/s);
    if (!match) return [];
    return match[1].replace(/\s+/g, '').split(',').filter(Boolean);
}

function parseZteTcont(raw) {
    const profiles = [];
    String(raw || '').split('\n').forEach(line => {
        const match = line.match(/Profile\s+name\s+:(\S+)/i);
        if (match && match[1]) profiles.push(match[1].trim());
    });
    return profiles;
}

function parseZteOnuTypeNames(raw) {
    const set = new Set();
    String(raw || '').split('\n').forEach(line => {
        const clean = line.replace(/\s*--More--\s*/gi, '').trim();
        if (!clean) return;
        const match = clean.match(/^ONU\s+type\s+name\s*:\s*(.+)$/i);
        if (!match || !match[1]) return;
        set.add(match[1].trim());
    });
    return Array.from(set).filter(Boolean).sort((a, b) => a.localeCompare(b));
}

function populateAutoRegUncfg(rows) {
    const sel = document.getElementById('auto-reg-onu-sel');
    if (!sel) return;
    const prev = sel.value;
    sel.innerHTML = '<option value="">— Pilih ONU Unconfig —</option>';
    if (!rows.length) {
        sel.disabled = true;
        return;
    }
    sel.disabled = false;
    rows.forEach((row) => {
        const opt = document.createElement('option');
        opt.value = `${row.intf}|${row.onu}`;
        opt.dataset.intf = row.intf || '';
        opt.dataset.onu = row.onu || '';
        opt.dataset.type = row.type || '';
        opt.dataset.sn = row.sn || '';
        opt.textContent = `${row.intf} | onu ${row.onu} | type ${row.type} | SN:${row.sn}`;
        sel.appendChild(opt);
    });
    if (prev && Array.from(sel.options).some(o => o.value === prev)) {
        sel.value = prev;
    } else if (sel.options.length > 1) {
        sel.selectedIndex = 1;
    }
    syncAutoRegFromSelect();
}

function populateAutoRegTcont(profiles) {
    const sel = document.getElementById('auto-reg-tcont-profile');
    if (!sel) return;
    const prev = sel.value;
    sel.innerHTML = '<option value="">— Pilih Profile TCONT —</option>';
    sel.disabled = !profiles.length;
    profiles.forEach(profile => {
        const opt = document.createElement('option');
        opt.value = profile;
        opt.textContent = profile;
        sel.appendChild(opt);
    });
    if (prev && Array.from(sel.options).some(o => o.value === prev)) sel.value = prev;
}

function populateAutoRegVlans(vlans) {
    const sel = document.getElementById('auto-reg-vlan-sel');
    if (!sel) return;
    const prev = sel.value;
    sel.innerHTML = '<option value="">— Pilih VLAN —</option>';
    sel.disabled = !vlans.length;
    vlans.forEach(vlan => {
        const opt = document.createElement('option');
        opt.value = vlan;
        opt.textContent = `VLAN ${vlan}`;
        sel.appendChild(opt);
    });
    if (prev && Array.from(sel.options).some(o => o.value === prev)) sel.value = prev;
}

function syncAutoRegFromSelect() {
    const sel = document.getElementById('auto-reg-onu-sel');
    if (!sel || !sel.value) return;
    const opt = sel.options[sel.selectedIndex];
    document.getElementById('auto-reg-intf').value = opt?.dataset.intf || '';
    document.getElementById('auto-reg-onu-no').value = opt?.dataset.onu || '';
    document.getElementById('auto-reg-sn').value = String(opt?.dataset.sn || '').toUpperCase();
    setAutoRegType(opt?.dataset.type || '');
    updateAutoRegPreview();
}

function syncAutoRegVlanText() {
    const vlanSel = document.getElementById('auto-reg-vlan-sel');
    const vlanInput = document.getElementById('auto-reg-vlan');
    if (vlanSel && vlanInput && vlanSel.value) vlanInput.value = vlanSel.value;
    updateAutoRegPreview();
}

function toggleAutoRegConfig() {
    const chk = document.getElementById('auto-reg-with-cfg');
    if (!chk) return;
    chk.checked = !chk.checked;
    const wrap = document.getElementById('auto-reg-cfg-wrap');
    const btn = document.getElementById('auto-reg-cfg-btn');
    if (wrap) wrap.style.display = chk.checked ? 'block' : 'none';
    if (btn) btn.textContent = chk.checked ? '✓ Config WAN Aktif' : '+ Aktifkan Config WAN Sekaligus';
    updateAutoRegPreview();
}

function getAutoRegOnuId() {
    const intf = (document.getElementById('auto-reg-intf')?.value || '').trim();
    const onuNo = (document.getElementById('auto-reg-onu-no')?.value || '').trim();
    if (!intf || !onuNo) return '';
    return `gpon-onu_${intf.replace(/^gpon-olt_/i, '')}:${onuNo}`;
}

function generateAutoRegServiceConfig(onuId) {
    const vlan = (document.getElementById('auto-reg-vlan')?.value || '').trim();
    const user = (document.getElementById('auto-reg-user')?.value || '').trim();
    const pass = (document.getElementById('auto-reg-pass')?.value || '').trim();
    const svc = (document.getElementById('auto-reg-svc')?.value || 'HSI').trim();
    const gemport = (document.getElementById('auto-reg-gemport')?.value || '1').trim();
    const cos = (document.getElementById('auto-reg-cos')?.value || '0').trim();
    const profile = (document.getElementById('auto-reg-vlan-profile')?.value || 'PPPoE').trim();
    const ethuni = (document.getElementById('auto-reg-ethuni')?.value || '1,2,3').trim();
    if (!onuId || !vlan || !user || !pass) return '';
    return `pon-onu-mng ${onuId}\n  service ${svc} type internet gemport ${gemport} cos ${cos} vlan ${vlan}\n  wan-ip 1 mode pppoe username ${user} password ${pass} vlan-profile ${profile} host 1\n  wan-ip 1 ping-response enable traceroute-response enable\n  wan 1 ethuni ${ethuni} ssid 1 service internet host 1\nexit`;
}

function buildAutoRegisterCommand() {
    const intf = (document.getElementById('auto-reg-intf')?.value || '').trim();
    const onuNo = (document.getElementById('auto-reg-onu-no')?.value || '').trim();
    const type = getAutoRegType();
    const sn = (document.getElementById('auto-reg-sn')?.value || '').trim().toUpperCase();
    const tcont = (document.getElementById('auto-reg-tcont-profile')?.value || '').trim();
    if (!intf || !onuNo || !type || !sn) return '';
    let onuCmd = `onu ${onuNo} type ${type} sn ${sn}`;
    if (tcont) onuCmd += ` tcont-profile ${tcont}`;
    const base = `config t\ninterface ${intf}\n  ${onuCmd}\nexit`;
    if (!document.getElementById('auto-reg-with-cfg')?.checked) return base;
    const onuId = getAutoRegOnuId();
    const serviceCfg = generateAutoRegServiceConfig(onuId);
    if (!serviceCfg) return '';
    return `${base}\n${serviceCfg}`;
}

function updateAutoRegPreview() {
    const preview = document.getElementById('auto-reg-preview');
    if (!preview) return;
    const cmd = buildAutoRegisterCommand();
    const withCfg = document.getElementById('auto-reg-with-cfg')?.checked;
    preview.textContent = cmd || (withCfg ? '(Lengkapi ONU, Type, SN, VLAN, PPPoE user dan password)' : '(Lengkapi ONU, Type, dan SN)');
}

async function zteLogin(olt) {
    const parsed = parseIpPort(olt.ipolt || '', 23);
    const fd = new FormData();
    fd.append('action', 'login');
    fd.append('ip', parsed.ip);
    fd.append('port', String(parsed.port));
    fd.append('username', olt.usernameolt || '');
    fd.append('password', olt.passwordolt || '');
    fd.append('devname', olt.oltname || parsed.ip);
    const response = await fetch(ZTE_CONSOLE_PATH, { method: 'POST', body: fd, credentials: 'same-origin' });
    const data = await response.json();
    if (!response.ok || data.error) {
        throw new Error(data.error || 'Login OLT gagal');
    }
    return data;
}

async function zteRunAndPoll(command, onProgress) {
    const runBody = new URLSearchParams();
    runBody.append('action', 'run');
    runBody.append('command', command);
    const runResp = await fetch(ZTE_CONSOLE_PATH, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: runBody.toString(),
        credentials: 'same-origin'
    });
    const runData = await runResp.json();
    if (!runResp.ok || runData.error || !runData.pid) {
        throw new Error(runData.error || 'Gagal memulai proses OLT');
    }

    let lastOutput = '';
    let lastMessage = '';
    for (let attempt = 0; attempt < 180; attempt++) {
        const statusResp = await fetch(`${ZTE_CONSOLE_PATH}?action=status&pid=${encodeURIComponent(runData.pid)}`, { credentials: 'same-origin' });
        const statusData = await statusResp.json();
        if (typeof onProgress === 'function') {
            const diff = (statusData.output || '').slice(lastOutput.length);
            lastOutput = statusData.output || '';
            const nextStatus = { ...statusData };
            if (nextStatus.message === lastMessage) {
                nextStatus.message = '';
            } else {
                lastMessage = nextStatus.message || '';
            }
            onProgress(nextStatus, diff);
        }
        if (statusData.status === 'done') {
            return statusData.output || '';
        }
        if (statusData.status === 'error') {
            throw new Error(statusData.message || 'Proses OLT gagal');
        }
        await new Promise(resolve => setTimeout(resolve, 1200));
    }
    throw new Error('Timeout menunggu respons OLT');
}

async function loadSelectedOltContext() {
    const olt = getSelectedOlt();
    const info = document.getElementById('oltSelectedInfo');
    const unsupported = document.getElementById('oltUnsupportedNote');
    const zteWrap = document.getElementById('zteAutoRegisterWrap');
    resetOltAutomationUi();
    clearProcessLog();

    if (!olt) return;

    await loadOltTemplateForAddCustomer(olt.id);

    if (info) {
        info.classList.remove('d-none');
        info.innerHTML = `<strong>OLT:</strong> ${escapeHtml(olt.oltname)} | <strong>Brand:</strong> ${escapeHtml(olt.brandolt)} | <strong>IP:</strong> ${escapeHtml(olt.ipolt)}`;
    }

    if (isHiosoTwoPort(olt.brandolt)) {
        if (unsupported) {
            unsupported.classList.remove('d-none');
            unsupported.textContent = 'Brand HIOSO EPON 2 PON tidak memakai form register otomatis di halaman ini.';
        }
        return;
    }

    if (!isZteBrand(olt.brandolt)) {
        if (unsupported) {
            unsupported.classList.remove('d-none');
            unsupported.textContent = 'Otomasi register inline saat ini tersedia untuk ZTE. Add customer tetap bisa dijalankan untuk brand ini, tetapi register ONT masih manual.';
        }
        return;
    }

    if (zteWrap) zteWrap.classList.remove('d-none');
    setAutoRegLoading(true, `Mengambil data OLT ${olt.oltname}...`);
    zteOnuTypeList = [...ZTE_ONU_TYPE_FALLBACK];
    populateAutoRegTypeOptions();
    syncPppoeCredentialsFromCustomer();
    appendProcessLog(`Mengambil data register dari OLT ${olt.oltname}...`);

    try {
        const cacheKey = String(olt.id || olt.ipolt || olt.oltname);
        let context = zteRegisterContextCache[cacheKey];
        if (!context) {
            await zteLogin(olt);
            const raw = await zteRunAndPoll('show gpon onu uncfg\nshow vlan sum\nshow gpon profile tcont\nshow onu-type gpon', (status) => {
                if (status.message) appendProcessLog(status.message);
            });
            context = {
                uncfg: parseZteUncfg(raw),
                vlans: parseZteVlanSum(raw),
                tcontProfiles: parseZteTcont(raw),
                onuTypes: parseZteOnuTypeNames(raw)
            };
            zteRegisterContextCache[cacheKey] = context;
        }
        zteOnuTypeList = (context.onuTypes && context.onuTypes.length)
            ? context.onuTypes
            : [...ZTE_ONU_TYPE_FALLBACK];
        populateAutoRegTypeOptions();
        populateAutoRegUncfg(context.uncfg || []);
        populateAutoRegVlans(context.vlans || []);
        populateAutoRegTcont(context.tcontProfiles || []);
        if (pendingOltOntTemplate) {
            applyOltTemplateToAddCustomer(pendingOltOntTemplate);
        }
        updateAutoRegPreview();
        appendProcessLog('Data ONU unconfig, VLAN, dan profile TCONT berhasil dimuat.');
    } catch (error) {
        appendProcessLog(`Gagal memuat data OLT: ${error.message}`);
    } finally {
        setAutoRegLoading(false);
    }
}

function loadOltRemoteButtons() {
    const server = document.getElementById('server').value;
    const area = document.getElementById('area').value;
    const reqSeq = ++oltFetchSeq;

    if (!server || !area) {
        if (oltFetchController) {
            oltFetchController.abort();
            oltFetchController = null;
        }
        currentOltList = [];
        hideOltRemoteButtons();
        return;
    }

    if (oltFetchController) {
        oltFetchController.abort();
    }
    oltFetchController = new AbortController();

    fetch('getdata/get_olt_by_server_area.php?server=' + encodeURIComponent(server) + '&area=' + encodeURIComponent(area), {
        signal: oltFetchController.signal
    })
        .then(r => r.json())
        .then(res => {
            if (reqSeq !== oltFetchSeq) return;
            currentOltList = (res && res.success && Array.isArray(res.data)) ? res.data : [];
            populateOltSelect(currentOltList);
        })
        .catch((err) => {
            if (err && err.name === 'AbortError') return;
            if (reqSeq !== oltFetchSeq) return;
            currentOltList = [];
            hideOltRemoteButtons();
        });
}

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
    } else {
        odpDropdown.innerHTML = '<option value="">-- Pilih ODP --</option>';
    }
}
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
// Trigger load ODP setiap kali server/area berubah
document.getElementById('server').addEventListener('change', function() {
    setAreaAddCustomer();
});
document.getElementById('area').addEventListener('change', function() {
    loadODP();
    loadOltRemoteButtons();
});
// Initial load jika sudah ada value
document.addEventListener('DOMContentLoaded', function() {
    // Pre-select Product/Server dari hasil scan PPPoE (?pppoe_server=PEMILIK)
    const prefillServer = new URLSearchParams(window.location.search).get('pppoe_server');
    if (prefillServer) {
        const serverSelect = document.getElementById('server');
        const hasOption = Array.from(serverSelect.options).some(opt => opt.value === prefillServer);
        if (hasOption) {
            serverSelect.value = prefillServer;
            serverSelect.dispatchEvent(new Event('change'));
        }
    }
    if(document.getElementById('server').value && document.getElementById('area').value) {
        loadODP();
        loadOltRemoteButtons();
    } else {
        hideOltRemoteButtons();
    }
    document.getElementById('oltSelect')?.addEventListener('change', loadSelectedOltContext);
    document.getElementById('auto-reg-onu-sel')?.addEventListener('change', syncAutoRegFromSelect);
    document.getElementById('auto-reg-vlan-sel')?.addEventListener('change', syncAutoRegVlanText);
    document.getElementById('auto-reg-type-sel')?.addEventListener('change', onAutoRegTypeChange);
    document.getElementById('customerID')?.addEventListener('input', syncPppoeCredentialsFromCustomer);
    document.getElementById('passwordPPPOE')?.addEventListener('input', syncPppoeCredentialsFromCustomer);
    [
        'auto-reg-sn','auto-reg-intf','auto-reg-onu-no','auto-reg-type-manual','auto-reg-tcont-profile','auto-reg-vlan','auto-reg-svc',
        'auto-reg-vlan-profile','auto-reg-gemport','auto-reg-cos','auto-reg-ethuni','auto-reg-user','auto-reg-pass'
    ].forEach(id => document.getElementById(id)?.addEventListener('input', updateAutoRegPreview));
    setAutoRegLoading(false);
});
</script>

<div id="oltAutomationWrap" class="d-none mt-3">
    <div>
        <label for="oltSelect" class="form-label">Pilih OLT di area ini</label>
        <select id="oltSelect" class="form-select">
            <option value="">-- Pilih OLT --</option>
        </select>
    </div>
    <div id="oltAutomationEmpty" class="small text-muted mt-2 d-none">Tidak ada data OLT untuk server/area ini.</div>
    <div id="oltSelectedInfo" class="small mt-2 d-none text-muted"></div>
    <div id="oltUnsupportedNote" class="small text-danger mt-2 d-none"></div>

    <div id="zteAutoRegisterWrap" class="d-none mt-3">
        <div class="border rounded p-3">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <strong>Register ONT Otomatis</strong>
                <button type="button" class="btn btn-sm btn-outline-primary" id="zteRefreshDataBtn" onclick="loadSelectedOltContext()">Refresh Data OLT</button>
            </div>
            <div id="zteAutoRegisterLoading" class="alert alert-info d-none d-flex align-items-center gap-2 py-2" role="status" aria-live="polite">
                <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                <span id="zteAutoRegisterLoadingText">Sedang memuat data OLT...</span>
            </div>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">ONU Unconfig</label>
                    <select id="auto-reg-onu-sel" class="form-select">
                        <option value="">— Pilih ONU Unconfig —</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Serial Number (SN)</label>
                    <input id="auto-reg-sn" type="text" class="form-control" placeholder="ZTEGXXXXXXXX">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Interface GPON OLT</label>
                    <input id="auto-reg-intf" type="text" class="form-control" placeholder="gpon-olt_1/2/1">
                </div>
                <div class="col-md-4">
                    <label class="form-label">ONU Number</label>
                    <input id="auto-reg-onu-no" type="number" class="form-control" placeholder="Auto dari ONU Unconfig" readonly>
                    <small class="text-muted">Terisi otomatis dari pilihan ONU Unconfig.</small>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Type ONT</label>
                    <select id="auto-reg-type-sel" class="form-select"></select>
                    <input id="auto-reg-type-manual" type="text" class="form-control mt-2" placeholder="Manual type" style="display:none;">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Profile TCONT</label>
                    <select id="auto-reg-tcont-profile" class="form-select">
                        <option value="">— Pilih Profile TCONT —</option>
                    </select>
                </div>
            </div>

            <div class="mt-3 d-flex align-items-center gap-2 flex-wrap">
                <input type="checkbox" id="auto-reg-with-cfg" hidden checked>
                <button type="button" class="btn btn-sm btn-outline-primary" id="auto-reg-cfg-btn" onclick="toggleAutoRegConfig()">✓ Config WAN Aktif</button>
                <small class="text-muted">Setelah register, WAN internet juga langsung dibuat.</small>
            </div>

            <div id="auto-reg-cfg-wrap" class="mt-3 border rounded p-3">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">VLAN</label>
                        <select id="auto-reg-vlan-sel" class="form-select">
                            <option value="">— Pilih VLAN —</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">VLAN ID Manual</label>
                        <input id="auto-reg-vlan" type="number" class="form-control" placeholder="200">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Service Name</label>
                        <input id="auto-reg-svc" type="text" class="form-control" value="HSI">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">VLAN Profile</label>
                        <input id="auto-reg-vlan-profile" type="text" class="form-control" value="PPPoE">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Gemport</label>
                        <input id="auto-reg-gemport" type="number" class="form-control" value="1">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">COS</label>
                        <input id="auto-reg-cos" type="number" class="form-control" value="0">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">PPPoE Username</label>
                        <input id="auto-reg-user" type="text" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">PPPoE Password</label>
                        <input id="auto-reg-pass" type="text" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Ethuni Ports</label>
                        <input id="auto-reg-ethuni" type="text" class="form-control" value="1,2,3">
                    </div>
                </div>
            </div>

            <div class="mt-3">
                <div class="fw-semibold mb-2">Preview Register</div>
                <div id="auto-reg-preview" class="olt-preview-box">(Pilih OLT dan ONU unconfig untuk melihat preview)</div>
            </div>
        </div>
    </div>

    <div id="oltProcessWrap" class="mt-3">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="fw-semibold mb-0">Log Proses</div>
            <button type="button" id="oltProcessToggleBtn" class="btn btn-sm btn-outline-secondary" onclick="toggleProcessLog()">Tampilkan Log Proses</button>
        </div>
        <div id="oltProcessLogPanel" class="d-none mt-2">
            <div id="oltProcessLog" class="olt-process-log"></div>
        </div>
    </div>
</div>

            <div class="pt-3 mt-2">
                <button type="submit" id="saveAddBtn" class="btn btn-primary">
                    <span class="btn-label">Save and ADD</span>
                    <span class="btn-loading d-none">
                        <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                        Menyimpan...
                    </span>
                </button>
            </div>
        <script>
        // Prevent spaces: leading/trailing for most fields, all spaces for PPPOE username/password
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('form');
            const saveAddBtn = document.getElementById('saveAddBtn');
            const btnLabel = saveAddBtn ? saveAddBtn.querySelector('.btn-label') : null;
            const btnLoading = saveAddBtn ? saveAddBtn.querySelector('.btn-loading') : null;
            let isSubmitting = false;

            if (form) {
                form.addEventListener('submit', async function(e) {
                    e.preventDefault();
                    if (isSubmitting) {
                        return;
                    }

                    const inputs = form.querySelectorAll('input[type="text"], input[type="email"], input[type="number"]');
                    let hasError = false;
                    let errorMessage = '';
                    
                    inputs.forEach(function(input) {
                        const value = input.value;
                        const inputName = input.name;
                        
                        // Special handling for PPPOE username and password - no spaces at all
                        if (inputName === 'customerID' || inputName === 'passwordPPPOE') {
                            if (/\s/.test(value)) {
                                hasError = true;
                                errorMessage = 'PPPOE Username dan Password tidak boleh mengandung spasi sama sekali!';
                                input.classList.add('is-invalid');
                            } else {
                                input.classList.remove('is-invalid');
                            }
                        } else {
                            // For other fields, only prevent leading/trailing spaces
                            if (/^\s|\s$/.test(value)) {
                                hasError = true;
                                errorMessage = 'Input tidak boleh ada spasi di awal atau di akhir!';
                                input.classList.add('is-invalid');
                            } else {
                                input.classList.remove('is-invalid');
                            }
                            // Trim leading/trailing spaces
                            input.value = value.replace(/^\s+|\s+$/g, '');
                        }
                    });
                    
                    if (hasError) {
                        alert(errorMessage);
                        return;
                    }

                    const selectedOlt = getSelectedOlt();
                    const autoRegisterVisible = selectedOlt && isZteBrand(selectedOlt.brandolt) && !document.getElementById('zteAutoRegisterWrap')?.classList.contains('d-none');
                    const registerCommand = autoRegisterVisible ? buildAutoRegisterCommand() : '';
                    if (autoRegisterVisible && !registerCommand) {
                        alert('Lengkapi data register ONT terlebih dahulu.');
                        return;
                    }

                    isSubmitting = true;
                    if (saveAddBtn) {
                        saveAddBtn.disabled = true;
                    }
                    if (btnLabel) {
                        btnLabel.classList.add('d-none');
                    }
                    if (btnLoading) {
                        btnLoading.classList.remove('d-none');
                    }

                    clearProcessLog();
                    appendProcessLog('Memulai proses add customer...');

                    try {
                        const formData = new FormData(form);
                        formData.append('response_mode', 'json');
                        const addResp = await fetch(form.action, {
                            method: 'POST',
                            body: formData,
                            credentials: 'same-origin'
                        });
                        const addRaw = await addResp.text();
                        let addData = null;
                        try {
                            addData = JSON.parse(addRaw);
                        } catch (jsonErr) {
                            appendProcessLog('Backend mengembalikan respons non-JSON.');
                            appendProcessLog((addRaw || '').slice(0, 500));
                            throw new Error('Respons backend bukan JSON valid: ' + (addRaw || '').slice(0, 140));
                        }
                        (addData.logs || []).forEach(line => appendProcessLog(line));

                        if (!addResp.ok || !addData.success) {
                            throw new Error(addData.message || 'Gagal menambahkan customer');
                        }

                        appendProcessLog('Add customer berhasil.');

                        if (registerCommand) {
                            appendProcessLog(`Login ke OLT ${selectedOlt.oltname}...`);
                            await zteLogin(selectedOlt);
                            appendProcessLog('Login OLT berhasil. Menjalankan register ONT...');
                            await zteRunAndPoll(registerCommand, (status, diff) => {
                                if (status.message) appendProcessLog(status.message);
                                if (diff && diff.trim()) appendProcessLog(diff.trim());
                            });
                            appendProcessLog('Register ONT selesai. Mengalihkan ke tabel pelanggan...');
                        }

                        const redirectUrl = addData.redirect || '../tables.php';
                        const resolvedRedirectUrl = new URL(redirectUrl, form.action).toString();
                        if (window.top && window.top !== window) {
                            window.top.location.href = resolvedRedirectUrl;
                        } else {
                            window.location.href = resolvedRedirectUrl;
                        }
                    } catch (err) {
                        appendProcessLog('ERROR: ' + err.message);
                        alert(err.message);
                        isSubmitting = false;
                        if (saveAddBtn) {
                            saveAddBtn.disabled = false;
                        }
                        if (btnLabel) {
                            btnLabel.classList.remove('d-none');
                        }
                        if (btnLoading) {
                            btnLoading.classList.add('d-none');
                        }
                    }
                });
            }
        });
        </script>
        </form>

    </div>

<?php require 'footer.php'; ?>
