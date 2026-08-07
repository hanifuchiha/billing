<?php

require 'cek-sesi.php';
?>


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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/choices.js/public/assets/styles/choices.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/choices.js/public/assets/scripts/choices.min.js"></script>
    <!-- Nepcha Analytics (nepcha.com) -->
    <!-- Tambahkan Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Nepcha is a easy-to-use web analytics. No cookies and fully compliant with GDPR, CCPA and PECR. -->
    <script defer data-site="YOUR_DOMAIN_HERE" src="https://api.nepcha.com/js/nepcha-analytics.js"></script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <!-- Tampilkan Peta -->
<script>
function applyAuthModeLockEditCustomer() {
    let serverSelect = document.getElementById('server');
    let selected = serverSelect ? serverSelect.options[serverSelect.selectedIndex] : null;
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
function setAreaEditCustomer() {
    let serverSelect = document.getElementById('server');
    let areaInput = document.getElementById('area');
    let areaDisplay = document.getElementById('area_display');
    let selected = serverSelect.options[serverSelect.selectedIndex];
    let area = selected ? selected.getAttribute('data-area') : '';
    areaInput.value = area;
    areaDisplay.value = area;
    applyAuthModeLockEditCustomer();
    // Setelah area diisi, langsung load ODP dan Packages
    loadODPEdit();
}
// Set area dan load ODP/Packages on page load jika server sudah terisi
document.addEventListener('DOMContentLoaded', function() {
    setAreaEditCustomer();
});
</script>
<style>
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

    .modal-content {
        background: linear-gradient(to bottom, #ffffff, #e0e0e0, #ffffff);
        /* Gradien putih ke abu lalu kembali ke putih */
        border-radius: 10px;
        /* Membuat sudut modal lebih lembut */
        border: 2px solid #d0d0d0;
        /* Tambahkan border abu-abu */
        box-shadow: 0px 4px 10px rgba(0, 0, 0, 0.2);
        /* Tambahkan shadow */
        font-size: 10px;
        /* Mengecilkan semua font dalam modal */
    }

    .modal-dialog {
        height: 100vh;
        display: flex;
        align-items: center;
        max-width: 90vw;
        margin: auto;
    }

    .modal-content {
        max-height: 90vh;
        display: flex;
        flex-direction: column;
        width: 100%;
    }

    .modal-body {
        overflow-y: auto;
        flex: 1;
    }

    .modal-backdrop {
        width: 100vw;
        height: 100vh;
    }

    .choices {
        margin-bottom: 0;
    }

    .choices__inner {
        min-height: 38px;
    }

    #mapPickerContainer { position: relative; z-index: 1; }
    .leaflet-pane, .leaflet-top, .leaflet-bottom { z-index: 2; }
    @media (max-width: 576px) {
        #mapPickerModal .modal-dialog { margin: 0.5rem; }
        #mapPickerContainer { height: 320px !important; }
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
</style>

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




<?php
$IPDEL = $_GET['IDPEL'];
require('routeros_api.class.php');
$sql1 = "SELECT * from `pelanggan` WHERE `IDPEL`='$IPDEL' ";
$query1 = mysqli_query($conn, $sql1);

$nomor = 0;
while ($data1 = mysqli_fetch_array($query1)) {
    $nomor++;
    $idselect = $data1['id'];
    $pemilik = $data1['PEMILIK'];
    $area = $data1['AREA'];
    $sql = "SELECT * FROM `server` WHERE `AREA` = '$area' and `PEMILIK`= '$pemilik' ";
    $query = mysqli_query($conn, $sql);
    while ($data = mysqli_fetch_array($query)) {

        $user = $data['PEMILIK'];
        $ip = $data['IP'];
        $password = $data['PASSWORD'];
?>


        <div class="container mt-5">
            <div class="card shadow p-4">














                <form method="POST" action="proses/editcustomer.php" id="myForm">
                    <div class="mb-3">
                        <label for="editIDPEL" class="form-label">Customer ID / PPPOE USER</label>
                        <input type="text" class="form-control" id="editIDPEL" name="customerID"  value="<?php echo $data1['IDPEL']; ?>" >
                        <input type="hidden" name="customerID_old" value="<?php echo $data1['IDPEL']; ?>">
                    </div>
                      <div class="mb-3">
                        <label for="editIDPEL" class="form-label">PPPOE PASS</label>
                        <input type="text" class="form-control" id="editpasswordPPPOE" name="passwordPPPOE" value="<?php echo $data1['PASSWORD']; ?>" >
                    </div>
                    <div class="mb-3">
                        <label for="nik" class="form-label">NIK (Nomor Induk Kependudukan)</label>
                        <input type="text" class="form-control" id="nik" name="NIK" maxlength="20" placeholder="Masukkan NIK 16 digit" value="<?php echo htmlspecialchars(isset($data1['NIK']) ? $data1['NIK'] : ''); ?>">
                    </div>
                    <div class="mb-3">
                        <label for="editName" class="form-label">Customer Name</label>
                        <input type="text" class="form-control" id="editName" name="customerName" value="<?php echo $data1['NAMA']; ?>">
                    </div>
                <div class="mb-3">
                    <label for="authmode" class="form-label">Auth Mode</label>
                    <?php $currentAuthMode = $data1['MODE'] ?? ''; ?>
                    <select required class="form-control" id="authmode" name="authmode">
                        <option value="" disabled <?php if(!in_array($currentAuthMode, ['API MODE','RADIUS MODE','MULTI MODE'], true)) echo "selected"; ?>>Pilih Auth Mode</option>
                        <option value="API MODE" <?php if($currentAuthMode=="API MODE") echo "selected"; ?>>API MODE</option>
                        <option value="RADIUS MODE" <?php if($currentAuthMode=="RADIUS MODE") echo "selected"; ?>>RADIUS MODE</option>
                        <option value="MULTI MODE" <?php if($currentAuthMode=="MULTI MODE") echo "selected"; ?>>MULTI MODE</option>
                    </select>
                    <small id="authmode_locked_note" class="text-warning d-none">
                        Server ini dikonfigurasi <b>RADIUS SAJA</b> (tanpa API Mikrotik) -- Auth Mode dikunci ke RADIUS MODE.
                    </small>
                </div>

                    <div class="mb-3">
                        <label for="editAddress" class="form-label">Address</label>
                        <input type="text" class="form-control" id="editAddress" name="address" value="<?php echo $data1['ALAMAT']; ?>">
                    </div>
                    <div class="mb-3">
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="mb-1">Provinsi</label>
                                <select id="provinsi_editcust" name="provinsi" class="form-control mb-2" required>
                                    <?php if (!empty($data1['provinsi'])): ?>
                                        <option selected value="<?php echo htmlspecialchars($data1['provinsi']); ?>"><?php echo htmlspecialchars($data1['provinsi']); ?> (aktual)</option>
                                    <?php else: ?>
                                        <option value="">Pilih Provinsi</option>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="mb-1">Kabupaten/Kota</label>
                                <select name="kabupaten" id="kabupaten_editcust" class="form-control mb-2" required>
                                    <?php if (!empty($data1['kabupaten'])): ?>
                                        <option selected value="<?php echo htmlspecialchars($data1['kabupaten']); ?>"><?php echo htmlspecialchars($data1['kabupaten']); ?> (aktual)</option>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="mb-1">Kecamatan</label>
                                <select name="kecamatan" id="kecamatan_editcust" class="form-control mb-2" required>
                                    <?php if (!empty($data1['kecamatan'])): ?>
                                        <option selected value="<?php echo htmlspecialchars($data1['kecamatan']); ?>"><?php echo htmlspecialchars($data1['kecamatan']); ?> (aktual)</option>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="mb-1">Kelurahan</label>
                                <select name="kelurahan" id="kelurahan_editcust" class="form-control" required>
                                    <?php if (!empty($data1['kelurahan'])): ?>
                                        <option selected value="<?php echo htmlspecialchars($data1['kelurahan']); ?>"><?php echo htmlspecialchars($data1['kelurahan']); ?> (aktual)</option>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="mb-1">RW</label>
                                <input type="text" name="rw" class="form-control mb-2" value="<?php echo htmlspecialchars($data1['rw'] ?? ''); ?>" placeholder="RW" oninput="this.value=this.value.replace(/\D/g,'').replace(/^0+(?=\d)/,'')">
                            </div>
                            <div class="col-md-6">
                                <label class="mb-1">RT</label>
                                <input type="text" name="rt" class="form-control mb-2" value="<?php echo htmlspecialchars($data1['rt'] ?? ''); ?>" placeholder="RT" oninput="this.value=this.value.replace(/\D/g,'').replace(/^0+(?=\d)/,'')">
                            </div>
                        </div>
                    </div>
<script>
// Wilayah Indonesia (EMSIFA) untuk Edit Customer
const kabupatenVal_editcust = <?php echo json_encode($data1['kabupaten'] ?? ''); ?>;
const kecamatanVal_editcust = <?php echo json_encode($data1['kecamatan'] ?? ''); ?>;
const kelurahanVal_editcust = <?php echo json_encode($data1['kelurahan'] ?? ''); ?>;

let provinsiDataGlobal = [];
let kabupatenDataGlobal = [];
let kecamatanDataGlobal = [];

fetch('https://www.emsifa.com/api-wilayah-indonesia/api/provinces.json')
    .then(r=>r.json())
    .then(provinsiData => {
        provinsiDataGlobal = provinsiData;
        const provSelect = document.getElementById('provinsi_editcust');
        provSelect.innerHTML = '<option value="">Pilih Provinsi</option>' + provinsiData.map(p=>`<option value="${p.name}">${p.name}</option>`).join('');
        // Prefill provinsi jika sudah ada di database
        var provDb = <?php echo json_encode($data1['provinsi'] ?? ''); ?>;
        if (provDb) {
            provSelect.value = provDb;
            provSelect.dispatchEvent(new Event('change'));
        } else if (kabupatenVal_editcust) {
            // Prefill provinsi jika kabupaten sudah ada
            fetch('https://www.emsifa.com/api-wilayah-indonesia/api/regencies.json')
                .then(r=>r.json())
                .then(allKab => {
                    kabupatenDataGlobal = allKab;
                    const kab = allKab.find(k=>k.name===kabupatenVal_editcust);
                    if (kab) {
                        const prov = provinsiData.find(p=>p.id==kab.province_id);
                        if (prov) {
                            provSelect.value = prov.name;
                            provSelect.dispatchEvent(new Event('change'));
                        }
                    }
                });
        }
    });

document.getElementById('provinsi_editcust').addEventListener('change', function() {
    const provName = this.value;
    const prov = provinsiDataGlobal.find(p=>p.name===provName);
    if (!prov) return;
    fetch('https://www.emsifa.com/api-wilayah-indonesia/api/regencies/'+prov.id+'.json')
        .then(r=>r.json())
        .then(data=>{
            kabupatenDataGlobal = data;
            const kabupatenSelect = document.getElementById('kabupaten_editcust');
            kabupatenSelect.innerHTML = '<option value="">Pilih Kabupaten/Kota</option>' + data.map(k=>`<option value="${k.name}">${k.name}</option>`).join('');
            document.getElementById('kecamatan_editcust').innerHTML = '<option value="">Pilih Kecamatan</option>';
            document.getElementById('kelurahan_editcust').innerHTML = '<option value="">Pilih Kelurahan</option>';
            // Pre-select kabupaten jika ada
            if (kabupatenVal_editcust) {
                const opt = Array.from(kabupatenSelect.options).find(o=>o.text===kabupatenVal_editcust);
                if (opt) {
                    kabupatenSelect.value = opt.value;
                    kabupatenSelect.dispatchEvent(new Event('change'));
                }
            }
        });
});

document.getElementById('kabupaten_editcust').addEventListener('change', function() {
    const kabName = this.value;
    const kab = kabupatenDataGlobal.find(k=>k.name===kabName);
    if (!kab) return;
    fetch('https://www.emsifa.com/api-wilayah-indonesia/api/districts/' + kab.id + '.json')
        .then(r=>r.json())
        .then(data=>{
            kecamatanDataGlobal = data;
            const kecSelect = document.getElementById('kecamatan_editcust');
            kecSelect.innerHTML = '<option value="">Pilih Kecamatan</option>' + data.map(k=>`<option value="${k.name}">${k.name}</option>`).join('');
            document.getElementById('kelurahan_editcust').innerHTML = '<option value="">Pilih Kelurahan</option>';
            // Pre-select kecamatan jika ada
            if (kecamatanVal_editcust) {
                const opt = Array.from(kecSelect.options).find(o=>o.text===kecamatanVal_editcust);
                if (opt) {
                    kecSelect.value = opt.value;
                    kecSelect.dispatchEvent(new Event('change'));
                }
            }
        });
});

document.getElementById('kecamatan_editcust').addEventListener('change', function() {
    const kecName = this.value;
    const kec = kecamatanDataGlobal.find(k=>k.name===kecName);
    if (!kec) return;
    fetch('https://www.emsifa.com/api-wilayah-indonesia/api/villages/' + kec.id + '.json')
        .then(r=>r.json())
        .then(data=>{
            const kelSelect = document.getElementById('kelurahan_editcust');
            kelSelect.innerHTML = '<option value="">Pilih Kelurahan</option>' + data.map(k=>`<option value="${k.name}">${k.name}</option>`).join('');
            // Pre-select kelurahan jika ada
            if (kelurahanVal_editcust) {
                const opt = Array.from(kelSelect.options).find(o=>o.text===kelurahanVal_editcust);
                if (opt) kelSelect.value = opt.value;
            }
        });
});
</script>
                    <div class="mb-3">
                        <label for="editWhatsapp" class="form-label">WhatsApp Number</label>
                        <input type="text" class="form-control" id="editWhatsapp" name="whatsapp" value="<?php echo $data1['NOWA']; ?>">
                    </div>
                    <div class="mb-3">
                        <label for="editWhatsapp" class="form-label">Email</label>
                        <input type="text" class="form-control" id="editWhatsapp" name="Email" value="<?php echo $data1['EMAIL']; ?>">
                    </div>
                    <div class="mb-3">
                        <label for="editCoordinates" class="form-label">Coordinates</label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="editCoordinates" name="coordinates" value="<?php echo $data1['TIKOR']; ?>">
                            <button type="button" class="btn btn-outline-secondary" onclick="openMapPicker('editCoordinates')">
                                <i class="fas fa-map-marked-alt"></i> Pilih dari Maps
                            </button>
                        </div>
                    </div>


                    <div class="mb-3">
                        <label for="server" class="form-label">Sales (username CRM Sales)</label>
                        <select required class="form-control" id="sales" name="sales">
                            <option value="<?php echo $data1['sales']; ?>"><?php echo $data1['sales']; ?></option>
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
                        </select>
                    </div>
  

                    <input hidden type="text" class="form-control" id="editServerlamaHidden" name="serverlama" value="<?php echo $data1['PEMILIK']; ?>">
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <label for="server" class="form-label mb-0">Server Area</label>
                            <small class="text-muted">
                                Jika belum ada SERVER silahkan buat dulu 
                                <a href="server.php" target="_parent" class="text-primary text-decoration-none fw-semibold">disini</a>
                            </small>
                        </div>
                        <select required class="form-select mt-1" id="server" name="server" onchange="setAreaEditCustomer()">
                            <option value="">-- Pilih Server Area --</option>
                            <?php
                            $serverOptionMatched = false;
                            $savedPemilikRaw = (string)($data1['PEMILIK'] ?? '');
                            $savedAreaRaw = (string)($data1['AREA'] ?? '');
                            $savedBrandRaw = '';
                            $savedConnModeRaw = 'API';

                            if ($savedPemilikRaw !== '' && $savedAreaRaw !== '') {
                                $savedPemilikEsc = mysqli_real_escape_string($conn, $savedPemilikRaw);
                                $savedAreaEsc = mysqli_real_escape_string($conn, $savedAreaRaw);
                                $savedServerQuery = mysqli_query($conn, "SELECT BRAND, CONNECTION_MODE FROM server WHERE PEMILIK = '$savedPemilikEsc' AND AREA = '$savedAreaEsc' LIMIT 1");
                                if ($savedServerQuery && mysqli_num_rows($savedServerQuery) > 0) {
                                    $savedServerRow = mysqli_fetch_assoc($savedServerQuery);
                                    $savedBrandRaw = (string)($savedServerRow['BRAND'] ?? '');
                                    $savedConnModeRaw = (string)($savedServerRow['CONNECTION_MODE'] ?? 'API');
                                }
                            }

                                                              if ($current_user_id) {

            if ($AKSES == 'ASSISTANT') {

                                        $queryServer = mysqli_query($conn, "SELECT DISTINCT PEMILIK, BRAND, AREA, CONNECTION_MODE FROM server WHERE AREA IN ($area_list)");

            } else {

                                        $queryServer = mysqli_query($conn, "SELECT DISTINCT PEMILIK, BRAND, AREA, CONNECTION_MODE FROM server WHERE user_id = $current_user_id");

            }
          }

                            while ($rowServer = mysqli_fetch_assoc($queryServer)) {
                                $selected = (
                                    (string)$rowServer['PEMILIK'] === $savedPemilikRaw &&
                                    (string)$rowServer['AREA'] === $savedAreaRaw
                                ) ? "selected" : "";
                                if ($selected) {
                                    $serverOptionMatched = true;
                                }
                                $area = htmlspecialchars($rowServer['AREA']);
                                $connmode = htmlspecialchars($rowServer['CONNECTION_MODE'] ?? 'API');
                                echo '<option value="'.$rowServer['PEMILIK'].'" data-area="'.$area.'" data-connmode="'.$connmode.'" '.$selected.'>'.$rowServer['BRAND'].'-'.$area.'</option>';
                            }

                            if (!$serverOptionMatched && $savedPemilikRaw !== '') {
                                $savedArea = htmlspecialchars($savedAreaRaw);
                                $savedPemilik = htmlspecialchars($savedPemilikRaw);
                                $savedBrand = htmlspecialchars($savedBrandRaw);
                                $savedConnMode = htmlspecialchars($savedConnModeRaw);
                                $savedLabel = trim($savedBrand . '-' . $savedArea, '-');
                                if ($savedLabel === '') {
                                    $savedLabel = $savedPemilik;
                                }
                                echo '<option value="' . $savedPemilik . '" data-area="' . $savedArea . '" data-connmode="' . $savedConnMode . '" selected>' . $savedLabel . ' (tersimpan)</option>';
                            }
                            ?>
                        </select>
                    </div>

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

<div hidden class="mb-3">
    <label for="area" class="form-label">AREA</label>
    <input type="hidden" id="area" name="area" value="<?php echo htmlspecialchars($data1['AREA']); ?>">
    <input type="text" class="form-control" id="area_display" readonly value="<?php echo htmlspecialchars($data1['AREA']); ?>">
</div>
<script>
function setAreaEditCustomer() {
    let serverSelect = document.getElementById('server');
    let areaInput = document.getElementById('area');
    let areaDisplay = document.getElementById('area_display');
    let selected = serverSelect.options[serverSelect.selectedIndex];
    let area = selected ? selected.getAttribute('data-area') : '';
    areaInput.value = area;
    areaDisplay.value = area;
}
// Set area on page load if server is preselected
document.addEventListener('DOMContentLoaded', function() {
    setAreaEditCustomer();
});

// ODP dan Packages dinamis berdasarkan server dan area (sama seperti addcustomerform.php)
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
    setAreaEditCustomer();
    loadODP();
    loadOltRemoteButtons();
});
document.getElementById('area').addEventListener('change', function() {
    loadODP();
    loadOltRemoteButtons();
});
// Initial load jika sudah ada value (edit mode)
document.addEventListener('DOMContentLoaded', function() {
    if(document.getElementById('server').value && document.getElementById('area').value) {
        loadODP();
        loadOltRemoteButtons();
    } else {
        hideOltRemoteButtons();
    }
});
</script>

<script>
// Otomatisasi register OLT berdasarkan server/area (port dari addcustomerform.php,
// dengan penyesuaian id field customerID/passwordPPPOE milik form edit ini).
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
    const customerId = document.getElementById('editIDPEL')?.value || '';
    const customerPass = document.getElementById('editpasswordPPPOE')?.value || '';
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
            unsupported.textContent = 'Otomasi register inline saat ini tersedia untuk ZTE. Simpan data tetap bisa dijalankan untuk brand ini, tetapi register ONT masih manual.';
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

document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('oltSelect')?.addEventListener('change', loadSelectedOltContext);
    document.getElementById('auto-reg-onu-sel')?.addEventListener('change', syncAutoRegFromSelect);
    document.getElementById('auto-reg-vlan-sel')?.addEventListener('change', syncAutoRegVlanText);
    document.getElementById('auto-reg-type-sel')?.addEventListener('change', onAutoRegTypeChange);
    document.getElementById('editIDPEL')?.addEventListener('input', syncPppoeCredentialsFromCustomer);
    document.getElementById('editpasswordPPPOE')?.addEventListener('input', syncPppoeCredentialsFromCustomer);
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

   <div class="mb-3">
    <label for="tipe_bayar">Tipe Bayar:</label>
    <select class="form-control" name="tipe_bayar" id="tipe_bayar">
        <option selected value="<?php echo $data1['TIPE_BAYAR']; ?>">
            <?php echo $data1['TIPE_BAYAR']; ?> (aktual)
        </option>
        <option value="prabayar">Prabayar (Bayar dulu baru bisa digunakan)</option>
        <option value="pascabayar">Pasca Bayar (digunakan dulu baru bayar)</option>
    </select>
</div>

<div class="mb-3">
    <label for="tipe_tempo">Tipe Tempo:</label>
    <select class="form-control" name="tipe_tempo" id="tipe_tempo">
        <?php
        $tipeTempoAktual = $data1['TIPE_TEMPO'] ?? '';
        $tipeTempoAktualLabel = $tipeTempoAktual;
        if ($tipeTempoAktual === 'mengikuti_tanggal_tempo') {
            $tipeTempoAktualLabel = 'Fixed Due Date';
        } elseif ($tipeTempoAktual === 'mengikuti_tanggal_bayar') {
            $tipeTempoAktualLabel = 'Rolling Due Date';
        } elseif ($tipeTempoAktual === 'monthversary') {
            $tipeTempoAktualLabel = 'Monthversary Due Date';
        }
        ?>
        <option selected value="<?php echo $data1['TIPE_TEMPO']; ?>">
            <?php echo $tipeTempoAktualLabel; ?> (aktual)
        </option>

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
                <option disabled class="text-muted" value="mengikuti_tanggal_tempo">
                  Fixed Due Date
                </option>
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
    const tipeTempoAktual = <?php echo json_encode($data1['TIPE_TEMPO'] ?? ''); ?>;
    const tipeTempoAllowedPascabayar = ['mengikuti_tanggal_bayar', 'monthversary'];

    function lockTipeTempo() {
        const selectedBayar = tipeBayar.value;

        for (let i = 0; i < tipeTempo.options.length; i++) {
            const opt = tipeTempo.options[i];

            if (selectedBayar === 'pascabayar') {
                if (!tipeTempoAllowedPascabayar.includes(opt.value) && opt.value !== tipeTempoAktual) {
                    opt.disabled = true;
                } else {
                    opt.disabled = false;
                }
            } else {
                opt.disabled = false; // semua enable untuk prabayar
            }
        }

        if (selectedBayar === 'pascabayar') {
            const currentValue = tipeTempo.value;
            if (!tipeTempoAllowedPascabayar.includes(currentValue) && currentValue !== tipeTempoAktual) {
                tipeTempo.value = 'mengikuti_tanggal_bayar';
            }
        }
    }

    // Jalankan saat halaman load untuk menyesuaikan dengan selected aktual
    lockTipeTempo();

    // Jalankan saat user ganti tipe bayar
    tipeBayar.addEventListener('change', lockTipeTempo);
</script>

                    <script>
                        window.editCustomerSelectChoices = window.editCustomerSelectChoices || {};
                        var savedOdpValue = <?php echo json_encode(trim((string)($data1['ODP'] ?? ''))); ?>;
                        var savedPackageValue = <?php echo json_encode(trim((string)($data1['PAKET'] ?? ''))); ?>;

                        function applyEditCustomerChoiceSelect(selectId, placeholderText) {
                            var selectElement = document.getElementById(selectId);
                            if (!selectElement || !window.Choices) return;

                            if (window.editCustomerSelectChoices[selectId]) {
                                window.editCustomerSelectChoices[selectId].destroy();
                            }

                            window.editCustomerSelectChoices[selectId] = new Choices(selectElement, {
                                searchEnabled: true,
                                shouldSort: false,
                                itemSelectText: '',
                                searchPlaceholderValue: placeholderText,
                                noResultsText: 'Data tidak ditemukan',
                                noChoicesText: 'Tidak ada pilihan'
                            });
                        }

                        function loadArea(selected = null) {
                            let selectedServer = document.getElementById("server").value;
                            let areaDropdown = document.getElementById("area");
                            let odpDropdown = document.getElementById("odp");
                            let packageDropdown = document.getElementById("packages");

                            // Reset dropdown
                            areaDropdown.innerHTML = '<option value="">Loading...</option>';
                            odpDropdown.innerHTML = '<option value="">Loading...</option>';
                            packageDropdown.innerHTML = '<option value="">Loading...</option>';

                            if (selectedServer !== "") {
                                let xhr = new XMLHttpRequest();
                                xhr.open("GET", "getdata/get_area.php?server=" + encodeURIComponent(selectedServer), true);
                                xhr.onreadystatechange = function() {
                                    if (xhr.readyState == 4 && xhr.status == 200) {
                                        areaDropdown.innerHTML = xhr.responseText;

                                        // kalau ada value awal, set value-nya
                                        if (selected) {
                                            areaDropdown.value = selected;
                                            loadODP("<?php echo $data1['ODP']; ?>");
                                        }
                                    }
                                };
                                xhr.send();
                            }
                        }

                        function loadODP(selected = null) {
                            let selectedArea = document.getElementById("area").value;
                            let odpDropdown = document.getElementById("odp");
                            let selectedServer = document.getElementById("server").value;

                            odpDropdown.innerHTML = '<option value="">Loading...</option>';

                            if (selectedArea !== "" && selectedServer !== "") {
                                let xhr = new XMLHttpRequest();
                                xhr.open("GET", "getdata/get_odp.php?area=" + encodeURIComponent(selectedArea) + "&server=" + encodeURIComponent(selectedServer), true);
                                xhr.onreadystatechange = function() {
                                    if (xhr.readyState == 4 && xhr.status == 200) {
                                        odpDropdown.innerHTML = xhr.responseText;

                                        if (selected) {
                                            var hasSelectedOdp = Array.from(odpDropdown.options).some(function(opt) {
                                                return opt.value === selected;
                                            });

                                            if (hasSelectedOdp) {
                                                odpDropdown.value = selected;
                                            } else if (selected !== '') {
                                                var savedOdpOption = document.createElement('option');
                                                savedOdpOption.value = selected;
                                                savedOdpOption.textContent = selected + ' (tersimpan)';
                                                savedOdpOption.selected = true;
                                                odpDropdown.appendChild(savedOdpOption);
                                            }

                                            loadPackages(savedPackageValue);
                                        }

                                        applyEditCustomerChoiceSelect('odp', 'Cari kode ODP atau nama ODP...');
                                    }
                                };
                                xhr.send();
                            } else {
                                odpDropdown.innerHTML = '<option value="">-- Pilih ODP --</option>';
                                applyEditCustomerChoiceSelect('odp', 'Cari kode ODP atau nama ODP...');
                            }
                        }

                        function loadPackages(selected = null) {
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

                                        var selectedPackage = (selected || '').toString().trim();
                                        if (selectedPackage) {
                                            var selectedPackageLower = selectedPackage.toLowerCase();
                                            var matchedPackageOption = Array.from(packageDropdown.options).find(function(opt) {
                                                return (opt.value || '').toString().trim().toLowerCase() === selectedPackageLower;
                                            });

                                            if (matchedPackageOption) {
                                                packageDropdown.value = matchedPackageOption.value;
                                            } else {
                                                var savedPackageOption = document.createElement('option');
                                                savedPackageOption.value = selectedPackage;
                                                savedPackageOption.textContent = selectedPackage + ' (tersimpan)';
                                                savedPackageOption.selected = true;
                                                packageDropdown.appendChild(savedPackageOption);
                                            }
                                        }

                                        applyEditCustomerChoiceSelect('packages', 'Cari paket...');
                                    }
                                };
                                xhr.send();
                            }
                        }

                        // AUTO LOAD saat halaman selesai diload
                        document.addEventListener("DOMContentLoaded", function() {
                            applyEditCustomerChoiceSelect('server', 'Cari server area...');
                            applyEditCustomerChoiceSelect('odp', 'Cari kode ODP atau nama ODP...');
                            applyEditCustomerChoiceSelect('packages', 'Cari paket...');
                            loadArea("<?php echo $data1['AREA']; ?>");

                            if (savedOdpValue && !document.getElementById('odp').value) {
                                loadODP(savedOdpValue);
                            }
                        });
                    </script>

                    <div class="d-flex gap-2">
                        <button type="submit" id="saveEditBtn" class="btn btn-primary">
                            <span class="btn-label">Save Changes</span>
                            <span class="btn-loading d-none">
                                <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                                Menyimpan...
                            </span>
                        </button>
                        <a href="javascript:history.back()" class="btn btn-secondary">Back</a>
                    </div>
                <script>
                // Prevent spaces: leading/trailing for most fields, all spaces for PPPOE username/password.
                // Submit di-intercept (fetch, response_mode=json) supaya kalau ada OLT yang dipilih dan
                // panel "Register ONT Otomatis" terisi, register ONT via telnet ke OLT baru dijalankan
                // SETELAH data pelanggan berhasil disimpan - sama seperti alur di addcustomerform.php.
                document.addEventListener('DOMContentLoaded', function() {
                    const form = document.getElementById('myForm');
                    const saveBtn = document.getElementById('saveEditBtn');
                    const btnLabel = saveBtn ? saveBtn.querySelector('.btn-label') : null;
                    const btnLoading = saveBtn ? saveBtn.querySelector('.btn-loading') : null;
                    let isSubmitting = false;

                    if (!form) return;

                    form.addEventListener('submit', async function(e) {
                        e.preventDefault();
                        if (isSubmitting) return;

                        const inputs = form.querySelectorAll('input[type="text"], input[type="email"], input[type="number"]');
                        let hasError = false;
                        let errorMessage = '';

                        inputs.forEach(function(input) {
                            const value = input.value;
                            const inputName = input.name;

                            // Special handling for PPPOE username and password - no spaces at all
                            if (inputName === 'customerID' || inputName === 'passwordPPPOE') {
                                // Allow spaces for now during edit, will be validated server-side
                                input.classList.remove('is-invalid');
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

                        const selectedOlt = typeof getSelectedOlt === 'function' ? getSelectedOlt() : null;
                        const zteWrap = document.getElementById('zteAutoRegisterWrap');
                        const autoRegisterVisible = selectedOlt && typeof isZteBrand === 'function' && isZteBrand(selectedOlt.brandolt) && zteWrap && !zteWrap.classList.contains('d-none');
                        const registerCommand = autoRegisterVisible && typeof buildAutoRegisterCommand === 'function' ? buildAutoRegisterCommand() : '';
                        if (autoRegisterVisible && !registerCommand) {
                            alert('Lengkapi data register ONT terlebih dahulu, atau kosongkan pilihan OLT kalau tidak ingin register ulang.');
                            return;
                        }

                        isSubmitting = true;
                        if (saveBtn) saveBtn.disabled = true;
                        if (btnLabel) btnLabel.classList.add('d-none');
                        if (btnLoading) btnLoading.classList.remove('d-none');

                        if (typeof clearProcessLog === 'function') clearProcessLog();
                        if (typeof appendProcessLog === 'function') appendProcessLog('Menyimpan perubahan data pelanggan...');

                        try {
                            const formData = new FormData(form);
                            formData.append('response_mode', 'json');
                            const editResp = await fetch(form.action, {
                                method: 'POST',
                                body: formData,
                                credentials: 'same-origin'
                            });
                            const editRaw = await editResp.text();
                            let editData = null;
                            try {
                                editData = JSON.parse(editRaw);
                            } catch (jsonErr) {
                                if (typeof appendProcessLog === 'function') {
                                    appendProcessLog('Backend mengembalikan respons non-JSON.');
                                    appendProcessLog((editRaw || '').slice(0, 500));
                                }
                                throw new Error('Respons backend bukan JSON valid: ' + (editRaw || '').slice(0, 140));
                            }

                            if (!editResp.ok || !editData.success) {
                                throw new Error(editData.message || 'Gagal menyimpan perubahan pelanggan');
                            }

                            if (typeof appendProcessLog === 'function') appendProcessLog('Data pelanggan berhasil disimpan.');

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

                            const redirectUrl = editData.redirect || '../tables.php';
                            window.location.href = new URL(redirectUrl, form.action).toString();
                        } catch (err) {
                            if (typeof appendProcessLog === 'function') appendProcessLog('ERROR: ' + err.message);
                            alert(err.message);
                            isSubmitting = false;
                            if (saveBtn) saveBtn.disabled = false;
                            if (btnLabel) btnLabel.classList.remove('d-none');
                            if (btnLoading) btnLoading.classList.add('d-none');
                            const loadingOverlay = document.getElementById('loading');
                            if (loadingOverlay) loadingOverlay.style.display = 'none';
                        }
                    });
                });
                </script>
<input hidden type="text" class="form-control" id="serverlama" name="serverlama" value="<?php echo $data1['PEMILIK']; ?>" >
<input hidden type="text" class="form-control" id="arealama" name="arealama" value="<?php echo $data1['AREA']; ?>" >                   

                </form>
            </div>
        </div>


<?php
    }
}
?>

<script>
    document.addEventListener("DOMContentLoaded", function() {
        var firstModal = document.querySelector(".modal:not(#mapPickerModal)");
        if (firstModal) {
            var modalInstance = new bootstrap.Modal(firstModal);
            modalInstance.show();
        }
    });
</script>



<footer class="footer pt-3  ">
    <div class="container-fluid">
        <div class="row align-items-center justify-content-lg-between">
            <div class="col-lg-6 mb-lg-0 mb-4">
                <div class="copyright text-center text-sm text-muted text-lg-start">
                    © <script>
                        document.write(new Date().getFullYear())
                    </script>,
                    made with <i class="fa fa-heart"></i> by
                    <a href="https://quenbytekniksejahtera.com/" class="font-weight-bold" target="_blank">PT QUENBY TEKNIK SEJAHTERA</a>

                </div>
            </div>
            <div class="col-lg-6">
                <ul class="nav nav-footer justify-content-center justify-content-lg-end">
                    <li class="nav-item">
                        <a href="https://quenbytekniksejahtera.com/" class="nav-link text-muted"
                            target="_blank">Creative Tim</a>
                    </li>
                    <li class="nav-item">
                        <a href="https://quenbytekniksejahtera.com/" class="nav-link text-muted"
                            target="_blank">About Us</a>
                    </li>
                    <li class="nav-item">
                        <a href="https://quenbytekniksejahtera.com/" class="nav-link text-muted"
                            target="_blank">Blog</a>
                    </li>
                    <li class="nav-item">
                        <a href="https://quenbytekniksejahtera.com/" class="nav-link pe-0 text-muted"
                            target="_blank">License</a>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</footer>



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
        let links = document.querySelectorAll(".nav-link");
        let form = document.getElementById("myForm");

        // Event untuk Navbar
        links.forEach(link => {
            link.addEventListener("click", function(event) {
                event.preventDefault(); // Mencegah navigasi langsung

                let loading = document.getElementById("loading");
                loading.style.display = "flex"; // Tampilkan loading

                setTimeout(() => {
                    window.location.href = this.href; // Navigasi setelah efek loading
                }, 1000); // Delay 1 detik sebelum berpindah halaman
            });
        });

        // Event untuk Form Submit
        form.addEventListener("submit", function(event) {
            let loading = document.getElementById("loading");
            loading.style.display = "flex"; // Tampilkan loading sebelum submit
        });
    });








    document.addEventListener("DOMContentLoaded", function() {
        const navLinks = document.querySelectorAll(".nav-link");

        navLinks.forEach((link) => {
            link.addEventListener("click", function() {
                navLinks.forEach((el) => el.classList.remove("active")); // Hapus active dari semua
                this.classList.add("active"); // Tambahkan active ke yang diklik
            });
        });
    });
</script>

</html>