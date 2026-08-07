<?php
require "../../employee/cek-sesi.php";
require "../../employee/koneksibilling.php";
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
  <link href="../vendor/datatables/dataTables.bootstrap4.min.css" rel="stylesheet">
  <title>SERVER LIST</title>
  
  <style>
    :root {
      --primary: #3498db;
      --secondary: #f39c12;
      --light: #f8f9fa;
      --dark: #343a40;
    }

    body {
      background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      margin: 0;
      padding: 5px 0;
      min-height: 100vh;
    }

    .map-section, .server-section {
      border: none;
      border-radius: 15px;
      box-shadow: 0 8px 30px rgba(0, 0, 0, 0.15);
      overflow: visible;
      width: 100%;
      margin: 0 0 10px 0;
      padding: 0;
      transition: transform 0.3s ease, box-shadow 0.3s ease;
      animation: fadeInUp 0.6s ease-out;
    }

    @keyframes fadeInUp {
      from {
        opacity: 0;
        transform: translateY(30px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .map-section:hover, .server-section:hover {
      transform: translateY(-5px);
      box-shadow: 0 12px 40px rgba(0, 0, 0, 0.2);
    }

    .map-header, .server-header {
      background: linear-gradient(135deg, var(--primary), #2980b9);
      color: white;
      padding: 15px 20px;
      border-bottom: none;
    }

    .map-body, .server-body {
      padding: 15px;
      background-color: white;
    }

    .btn-primary {
      background-color: var(--primary);
      border-color: var(--primary);
      color: white;
      box-shadow: 0 2px 5px rgba(0,0,0,0.2);
      transition: all 0.2s ease;
    }

    .btn-primary:hover {
      background-color: #2980b9;
      border-color: #2980b9;
      transform: translateY(-1px);
      box-shadow: 0 4px 10px rgba(0,0,0,0.3);
    }

    .btn-warning {
      background-color: var(--secondary);
      border-color: var(--secondary);
      color: white;
    }

    .btn-outline-primary {
      color: var(--primary);
      border-color: var(--primary);
    }

    .btn-outline-primary:hover {
      background-color: var(--primary);
      color: white;
    }

    .table {
      border-collapse: collapse;
      border-radius: 8px;
      overflow: hidden;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
      font-size: 0.9em;
    }

    .table thead th {
      background-color: var(--primary);
      color: white;
      border: none;
      border-right: 1px solid rgba(255,255,255,0.3);
      padding: 8px 10px;
      font-weight: bold;
      text-align: center;
    }

    .table tbody tr {
      transition: all 0.2s ease;
    }

    .table tbody tr:nth-child(even) {
      background-color: rgba(52, 152, 219, 0.05);
    }

    .table tbody tr:hover {
      background-color: rgba(52, 152, 219, 0.1);
      transform: translateX(2px);
    }

    .table tbody td {
      border-bottom: 1px solid #e0e6ed;
      border-right: 1px solid #e0e6ed;
      padding: 8px 10px;
      vertical-align: middle;
      word-wrap: break-word;
      white-space: normal;
      overflow: visible;
    }

    .table tbody td:first-child {
      text-align: left;
      font-weight: 500;
      min-width: 200px;
    }

    .table .btn {
      box-shadow: 0 1px 3px rgba(0,0,0,0.2);
      transition: all 0.2s ease;
    }

    .table .btn:hover {
      transform: translateY(-1px);
      box-shadow: 0 3px 8px rgba(0,0,0,0.3);
    }

    .overlay {
      position: relative;
    }

    #loading {
      position: absolute;
      right: 10px;
      top: 50%;
      transform: translateY(-50%);
      display: none;
      color: var(--secondary);
      font-size: 0.9em;
      background: rgba(255,255,255,0.9);
      padding: 2px 6px;
      border-radius: 4px;
      z-index: 1000;
    }

    #timer {
      color: var(--secondary);
      font-weight: bold;
    }

    .action-buttons .btn {
      margin-right: 5px;
      border-radius: 20px;
      padding: 5px 15px;
      font-weight: 500;
      min-width: 80px;
      box-shadow: 0 2px 5px rgba(0,0,0,0.2);
      transition: all 0.2s ease;
    }

    .action-buttons .btn:hover {
      transform: translateY(-1px);
      box-shadow: 0 4px 10px rgba(0,0,0,0.3);
    }

    .action-buttons .btn:last-child {
      margin-right: 0;
    }

    /* MAP STYLES - FIXED */
    #map {
      width: 100%;
      height: 350px;
      border-radius: 8px;
      z-index: 1;
    }

    /* Mobile: layar <= 768px → tinggi 20% viewport */
    @media (max-width: 768px) {
      #map {
        height: 20vh;
      }
    }

    /* Fullscreen map */
    #view.fullscreen {
      position: fixed !important;
      top: 0;
      left: 0;
      width: 100% !important;
      height: 100% !important;
      z-index: 9999;
      background-color: white;
      padding: 0;
    }

    #view.fullscreen #map {
      height: calc(100% - 100px) !important;
    }

    /* Filter dropdown styles */
    optgroup {
      font-weight: bold;
      font-size: 1.1em;
    }
    
    optgroup option {
      font-weight: normal;
      font-size: 0.9em;
      padding-left: 10px;
    }

    /* FIX: Pastikan garis Leaflet terlihat */
    .leaflet-pane svg path {
      stroke: inherit !important;
      stroke-width: inherit !important;
      stroke-dasharray: inherit !important;
    }

    .leaflet-overlay-pane svg path {
      stroke: inherit !important;
    }

    /* Animasi garis */
    @keyframes dash-animation {
      to {
        stroke-dashoffset: -20;
      }
    }

    /* Mobile optimizations */
    @media (max-width: 768px) {
      .map-section, .server-section {
        border-radius: 10px;
        margin: 0 0 15px 0;
      }
      .map-body, .server-body {
        padding: 8px;
      }
      .map-header, .server-header {
        padding: 10px 15px;
      }
      .table-responsive {
        font-size: 0.8em;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
      }
      .action-buttons .btn {
        padding: 4px 10px;
        font-size: 0.8em;
        min-width: 70px;
      }
    }
  </style>
</head>

<body>

<div id="view">
  <div class="map-section">
    <div class="map-header">
         <h6><i class="fas fa-map-marked-alt mr-2"></i>GLOBAL MAPS ODP </h6>
    </div>
    <div class="map-body">
      <div class="mb-3 d-flex justify-content-between flex-wrap gap-2">
        <div style="flex: 1; max-width: 300px;">
          <label for="odpFilter" class="form-label fw-bold">Filter ODP:</label>
          <div class="overlay">
            <select id="odpFilter" class="form-select">
              <option value="">-- Semua ODP --</option>
            </select>
            <span id="loading">🔄 Memuat data...</span>
            <span id="timer">120</span> Auto reload
          </div>
        </div>
        <button id="fullViewBtn" class="btn btn-outline-primary align-self-end">fullscreen</button>
        <button id="myLocationBtn" class="btn btn-outline-primary align-self-end">Lokasi Saya</button>
      </div>
      <div id="map"></div>
    </div>
  </div>
</div>

<!-- Leaflet -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
let animationIntervals = [];
let odpMarkers = [], pelangganMarkers = [], lines = [];
let currentFilter = '';

// Inisialisasi map dengan view default
const map = L.map('map').setView([-2.5, 118], 5);

// --- Layer ---
const layers = {
    'OpenStreetMap': L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { 
        attribution: '© OpenStreetMap' 
    }).addTo(map),
    'Satellite': L.tileLayer('https://{s}.google.com/vt/lyrs=s&x={x}&y={y}&z={z}', { 
        maxZoom: 20, 
        subdomains: ['mt0','mt1','mt2','mt3'], 
        attribution: '© Google' 
    }),
    'Dark': L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', { 
        attribution: '© CartoDB' 
    })
};
L.control.layers(layers).addTo(map);

// --- Ikon ---
const iconODP = L.icon({ 
    iconUrl: 'odpmap2.png', 
    iconSize: [80,80], 
    iconAnchor: [40,70] 
});

const iconOnline = L.icon({ 
    iconUrl: 'customer-green2.png', 
    iconSize: [75,75], 
    iconAnchor: [37.5,60] 
});

const iconLos = L.icon({ 
    iconUrl: 'customer-red2.png', 
    iconSize: [75,75], 
    iconAnchor: [37.5,60] 
});

const iconUser = L.icon({ 
    iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-blue.png', 
    iconSize: [25,41], 
    iconAnchor: [12,41], 
    popupAnchor: [1,-34] 
});

// --- Clear Map ---
function clearMap() {
    // Hentikan semua animasi
    animationIntervals.forEach(id => clearInterval(id));
    animationIntervals = [];
    
    // Hapus semua layer dari peta
    odpMarkers.forEach(o => map.removeLayer(o.marker));
    pelangganMarkers.forEach(p => map.removeLayer(p));
    lines.forEach(l => map.removeLayer(l));
    
    // Reset arrays
    odpMarkers = [];
    pelangganMarkers = [];
    lines = [];
}

// --- Fungsi untuk animasi garis - IMPROVED ---
function startLineAnimation(line) {
    let offset = 0;
    const intervalId = setInterval(() => {
        if (line._path) {
            offset = (offset - 2) % 20; // Lebih cepat
            line._path.style.strokeDashoffset = offset;
        }
    }, 100); // Lebih lambat agar smooth
    animationIntervals.push(intervalId);
}

// --- Load Data ---
async function loadData() {
    document.getElementById('loading').style.display = 'inline';
    try {
        const res = await fetch('get_all_online_clients.php');
        const data = await res.json();
        document.getElementById('loading').style.display = 'none';

        console.log('Data loaded:', data);

        // Clear map sepenuhnya
        clearMap();

        const odpFilter = document.getElementById('odpFilter');
        odpFilter.innerHTML = '<option value="">-- Semua ODP --</option>';

        const areaGroups = {};
        let allLatLng = [];

        // --- Proses Data ODP ---
        if (data.odpMarkers && data.odpMarkers.length > 0) {
            data.odpMarkers.forEach(odp => {
                if (!areaGroups[odp.area]) areaGroups[odp.area] = [];
                areaGroups[odp.area].push(odp);

                const marker = L.marker([parseFloat(odp.lat), parseFloat(odp.lng)], { 
                    icon: iconODP 
                }).bindPopup(odp.popup).addTo(map);

                odpMarkers.push({ 
                    kode: odp.kode, 
                    lat: parseFloat(odp.lat), 
                    lng: parseFloat(odp.lng), 
                    marker 
                });

                allLatLng.push([parseFloat(odp.lat), parseFloat(odp.lng)]);
            });

            // Buat Filter
            for (const area in areaGroups) {
                const group = document.createElement('optgroup');
                group.label = `📍 ${area.toUpperCase()}`;
                areaGroups[area].forEach(odp => {
                    const option = document.createElement('option');
                    option.value = odp.kode;
                    option.textContent = `${odp.name} (${odp.kode})`;
                    group.appendChild(option);
                });
                odpFilter.appendChild(group);
            }
        }

        // --- Proses Data Pelanggan ---
        if (data.pelangganMarkers && data.pelangganMarkers.length > 0) {
            data.pelangganMarkers.forEach(pelanggan => {
                const icon = pelanggan.status === 'online' ? iconOnline : iconLos;
                const marker = L.marker([parseFloat(pelanggan.lat), parseFloat(pelanggan.lng)], { 
                    icon 
                }).bindPopup(pelanggan.popup).addTo(map);
                
                marker.odp = pelanggan.odp;
                pelangganMarkers.push(marker);
                
                allLatLng.push([parseFloat(pelanggan.lat), parseFloat(pelanggan.lng)]);
            });
        }

        // --- Proses Garis Koneksi - IMPROVED ---
        if (data.lines && data.lines.length > 0) {
            console.log('Processing lines:', data.lines.length);
            
            data.lines.forEach((lineData, index) => {
                const color = lineData.status === 'online' ? '#00ff00' : '#ff0000';
                
                // Format koordinat dengan validasi
                let from, to;
                
                if (Array.isArray(lineData.from)) {
                    from = lineData.from;
                } else if (lineData.from && lineData.from.lat && lineData.from.lng) {
                    from = [parseFloat(lineData.from.lat), parseFloat(lineData.from.lng)];
                } else {
                    console.warn('Invalid from coordinates:', lineData.from);
                    return;
                }
                
                if (Array.isArray(lineData.to)) {
                    to = lineData.to;
                } else if (lineData.to && lineData.to.lat && lineData.to.lng) {
                    to = [parseFloat(lineData.to.lat), parseFloat(lineData.to.lng)];
                } else {
                    console.warn('Invalid to coordinates:', lineData.to);
                    return;
                }
                
                console.log(`Line ${index}: from ${from} to ${to}`);
                
                // Buat garis dengan style yang lebih jelas
                const line = L.polyline([from, to], { 
                    color: color, 
                    dashArray: '10, 10', 
                    weight: 5, // Lebih tebal
                    opacity: 0.9,
                    lineCap: 'round',
                    className: 'animated-line'
                }).addTo(map);
                
                line.odp = lineData.odp;
                lines.push(line);

                // Mulai animasi setelah delay kecil
                setTimeout(() => {
                    startLineAnimation(line);
                }, index * 50); // Stagger animations
            });
        } else {
            console.log('No lines data found');
        }

        // Set filter ke "Semua ODP"
        odpFilter.value = '';
        currentFilter = '';

        // Fit bounds ke semua data
        if (allLatLng.length > 0) {
            setTimeout(() => {
                const bounds = L.latLngBounds(allLatLng);
                map.fitBounds(bounds, { padding: [50, 50] });
                console.log('Initial bounds set with', allLatLng.length, 'coordinates');
            }, 100);
        }

        console.log('Data processed - ODP:', odpMarkers.length, 'Pelanggan:', pelangganMarkers.length, 'Lines:', lines.length);

    } catch(e) {
        console.error("Gagal load data:", e);
        document.getElementById('loading').style.display = 'none';
    }
}

// --- Fungsi untuk apply filter dan zoom ---
function applyFilter(selectedKode) {
    console.log('Applying filter for:', selectedKode || 'All ODP');
    
    // Sembunyikan semua marker dulu
    odpMarkers.forEach(odp => map.removeLayer(odp.marker));
    pelangganMarkers.forEach(p => map.removeLayer(p));
    lines.forEach(l => map.removeLayer(l));
    
    let bounds = [];

    if (!selectedKode) {
        // Tampilkan semua
        odpMarkers.forEach(odp => {
            map.addLayer(odp.marker);
            bounds.push([odp.lat, odp.lng]);
        });
        pelangganMarkers.forEach(p => {
            map.addLayer(p);
            bounds.push([p.getLatLng().lat, p.getLatLng().lng]);
        });
        lines.forEach(l => map.addLayer(l));
        
        console.log('Showing all ODP - maintaining view');
        
    } else {
        // Tampilkan hanya ODP yang dipilih dan pelanggannya
        const selectedOdp = odpMarkers.find(odp => odp.kode === selectedKode);
        
        if (selectedOdp) {
            console.log('Found ODP:', selectedOdp.kode);
            
            // Tampilkan ODP
            map.addLayer(selectedOdp.marker);
            bounds.push([selectedOdp.lat, selectedOdp.lng]);
            
            // Tampilkan pelanggan dari ODP ini
            pelangganMarkers.forEach(p => {
                if (p.odp === selectedKode) {
                    map.addLayer(p);
                    bounds.push([p.getLatLng().lat, p.getLatLng().lng]);
                }
            });
            
            // Tampilkan garis dari ODP ini
            lines.forEach(l => {
                if (l.odp === selectedKode) {
                    map.addLayer(l);
                }
            });
            
            // ZOOM KE ODP INI
            if (bounds.length > 0) {
                setTimeout(() => {
                    try {
                        const latLngBounds = L.latLngBounds(bounds);
                        map.fitBounds(latLngBounds, { 
                            padding: [30, 30],
                            maxZoom: 18
                        });
                        
                        console.log('Zoomed to ODP:', selectedKode, 'with', bounds.length, 'points');
                        
                        // Buka popup ODP
                        selectedOdp.marker.openPopup();
                        
                    } catch (e) {
                        console.error('Error zooming:', e);
                        // Fallback: langsung set view ke ODP
                        map.setView([selectedOdp.lat, selectedOdp.lng], 16);
                    }
                }, 200);
            }
        } else {
            console.log('ODP not found:', selectedKode);
        }
    }
}

// --- Event Filter ---
document.getElementById('odpFilter').addEventListener('change', function() {
    const selectedKode = this.value;
    currentFilter = selectedKode;
    applyFilter(selectedKode);
});

// --- Countdown ---
let countdown = 120;
let countdownInterval;

function startCountdown() {
    const timerSpan = document.getElementById('timer');
    countdown = 120;
    
    if (countdownInterval) clearInterval(countdownInterval);
    
    timerSpan.textContent = countdown;
    
    countdownInterval = setInterval(() => {
        countdown--;
        timerSpan.textContent = countdown;
        if (countdown <= 0) {
            clearInterval(countdownInterval);
            loadData().then(startCountdown);
        }
    }, 1000);
}

// --- Fullscreen ---
document.getElementById("fullViewBtn").addEventListener("click", function() {
    const view = document.getElementById("view");
    const isFullscreen = view.classList.contains("fullscreen");
    
    view.classList.toggle("fullscreen");
    this.innerText = isFullscreen ? "Fullscreen" : "Minimize";
    
    setTimeout(() => {
        map.invalidateSize();
        console.log('Map resized for fullscreen');
    }, 300);
});

// --- My Location Button ---
document.getElementById("myLocationBtn").addEventListener("click", function() {
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(function(position) {
            const lat = position.coords.latitude;
            const lng = position.coords.longitude;
            
            map.setView([lat, lng], 16);
            
            console.log('Zoomed to user location:', lat, lng);
        }, function(error) {
            alert('Tidak dapat mendapatkan lokasi: ' + error.message);
        });
    } else {
        alert('Geolokasi tidak didukung oleh browser ini.');
    }
});

// --- Load Awal ---
document.addEventListener('DOMContentLoaded', function() {
    console.log('Initializing map...');
    loadData();
    startCountdown();
    
    // Get user location
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(function(position) {
            const lat = position.coords.latitude;
            const lng = position.coords.longitude;
            
            // Add user marker
            const userMarker = L.marker([lat, lng], { icon: iconUser }).addTo(map)
                .bindPopup('Lokasi Anda').openPopup();
            
            // Optionally zoom to user location
            map.setView([lat, lng], 13);
            
            console.log('User location:', lat, lng);
        }, function(error) {
            console.error('Error getting location:', error);
        });
    } else {
        console.log('Geolocation not supported');
    }
    
    // Test function untuk debug
    window.debugMap = function() {
        console.log('ODP Markers:', odpMarkers.length);
        console.log('Customer Markers:', pelangganMarkers.length);
        console.log('Lines:', lines.length);
        console.log('Animations running:', animationIntervals.length);
    };
});
</script>



  <div class="server-section">
      <div class="server-header">
        <h4 class="mb-0"><i class="fas fa-server mr-2"></i>SERVER LIST</h4>
      </div>
      <div class="server-body">
        <div class="action-buttons mb-3">
          <a href="javascript:history.back()" class="btn btn-light">
            <i class="fas fa-arrow-left mr-2"></i>KEMBALI
          </a>
          <button type="button" onClick="document.location.reload(true)" class="btn btn-light">
            <i class="fas fa-sync-alt mr-2"></i>REFRESH
          </button>
        </div>

        <div class="table-responsive">
          <table class="table table-hover" id="dataTable" width="100%" cellspacing="0">
            <thead>
              <tr>
                <th scope="col">SERVER</th>
                <th scope="col" width="120">ACTION</th>
              </tr>
            </thead>
            <tbody>
              <?php
              require "../../employee/koneksibilling.php";
              $sql = "SELECT * FROM `server` WHERE 1";
              $query = mysqli_query($conn, $sql);
              while ($data = mysqli_fetch_array($query)) {
                $AREA = $data['AREA'];
                $BRAND = $data['BRAND'];
                $id = $data['id'];
                echo "<tr>";
              ?>
                <td><B><?php echo $BRAND ?><B> => <?php echo $AREA ?></td>
                <td>
                  <button class="btn btn-primary btn-sm" onclick="location.href='odplist.php?idserver=<?php echo $id ?>';">
                    <i class="fas fa-external-link-alt mr-1"></i> OPEN
                  </button>
                </td>
              <?php
                echo "</tr>";
              }
              ?>
            </tbody>
          </table>
        </div>
      </div>
</div>


 










  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

  <!-- jQuery and Bootstrap Bundle -->
  <script src="https://cdn.jsdelivr.net/npm/jquery@3.5.1/dist/jquery.slim.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>

  <!-- DataTables -->
  <script src="../vendor/datatables/jquery.dataTables.min.js"></script>
  <script src="../vendor/datatables/dataTables.bootstrap4.min.js"></script>
  <script src="../js/demo/datatables-demo.js"></script>

  <!-- Leaflet -->
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

</body>

</html>