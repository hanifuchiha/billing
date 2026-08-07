<?php require 'header.php'; ?>

<style>
  /* Default: tinggi map 500px */
  #map {
    width: 100%;
    height: 500px;
  }

  /* Mobile: layar <= 768px → tinggi 30% viewport */
  @media (max-width: 768px) {
    #map {
      height: 30vh; /* 30% dari tinggi viewport */
    }
  }

  /* Fullscreen map */
  #view.fullscreen #map {
    height: 100% !important;
  }
</style>

<div class="container py-4" id="view">
  <div class="card shadow">
    <div class="card-header bg-primary text-white">
         <h6>GLOBAL MAPS PENYEWA</h6>
    </div>
    <div class="card-body">
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
      </div>
      <div id="map"></div>
    </div>
  </div>
</div>

<!-- Leaflet -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<style>
  #view.fullscreen {
    position: fixed !important;
    top: 0;
    left: 0;
    width: 100% !important;
    height: 100% !important;
    z-index: 9999;
    background-color: white;
  }
  
  .overlay {
    position: relative;
  }
  
  #loading {
    display: none;
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    background: rgba(255,255,255,0.8);
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 0.8rem;
  }

  /* Style untuk optgroup yang tebal */
  optgroup {
    font-weight: bold;
    font-size: 1.1em;
  }
  
  optgroup option {
    font-weight: normal;
    font-size: 0.9em;
    padding-left: 10px;
  }

  /* Style untuk animasi garis */
  .animated-dashed-line {
    animation: dash-animation 1s linear infinite;
  }

  @keyframes dash-animation {
    to {
      stroke-dashoffset: -20;
    }
  }
</style>

<script>
let animationIntervals = [];
let odpMarkers = [], pelangganMarkers = [], lines = [];
let currentFilter = '';
let isDataLoaded = false;

// Inisialisasi map dengan view default
const map = L.map('map').setView([-2.5, 118], 5);

// --- Layer ---
const layers = {
    'OpenStreetMap': L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap' }).addTo(map),
    'Satellite': L.tileLayer('https://{s}.google.com/vt/lyrs=s&x={x}&y={y}&z={z}', { maxZoom: 20, subdomains: ['mt0','mt1','mt2','mt3'], attribution: '© Google' }),
    'Dark': L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', { attribution: '© CartoDB' })
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

// --- Clear Map ---
function clearMap() {
    // Hentikan semua animasi terlebih dahulu
    animationIntervals.forEach(id => {
        clearInterval(id);
    });
    animationIntervals = [];
    
    // Hapus semua marker dari peta
    odpMarkers.forEach(o => {
        if (o.marker && map.hasLayer(o.marker)) {
            map.removeLayer(o.marker);
        }
    });
    
    pelangganMarkers.forEach(p => {
        if (p && map.hasLayer(p)) {
            map.removeLayer(p);
        }
    });
    
    lines.forEach(l => {
        if (l && map.hasLayer(l)) {
            map.removeLayer(l);
        }
    });
    
    // Reset arrays
    odpMarkers = [];
    pelangganMarkers = [];
    lines = [];
}

// --- Fungsi untuk membuat animasi garis ---
function startLineAnimation(line) {
    let offset = 0;
    const intervalId = setInterval(() => {
        if (line._path) {
            offset = (offset - 1) % 20; // Negative untuk animasi maju
            line._path.style.strokeDashoffset = offset;
        }
    }, 50);
    animationIntervals.push(intervalId);
    return intervalId;
}

// --- Load Data ---
async function loadData() {
    document.getElementById('loading').style.display = 'inline';
    try {
        const res = await fetch('odpcheker/get_all_online_clients.php');
        const data = await res.json();
        document.getElementById('loading').style.display = 'none';

        console.log('Data loaded:', data);

        // Clear map sepenuhnya
        clearMap();

        const odpFilter = document.getElementById('odpFilter');
        
        // Reset filter options
        odpFilter.innerHTML = '<option value="">-- Semua ODP --</option>';

        const areaGroups = {};
        let allLatLng = [];

        // --- Proses Data ODP ---
        if (data.odpMarkers && data.odpMarkers.length > 0) {
            data.odpMarkers.forEach(odp => {
                // Kelompokkan berdasarkan area
                if (!areaGroups[odp.area]) areaGroups[odp.area] = [];
                areaGroups[odp.area].push(odp);

                // Buat marker ODP
                const marker = L.marker([parseFloat(odp.lat), parseFloat(odp.lng)], { 
                    icon: iconODP 
                }).bindPopup(odp.popup);

                odpMarkers.push({ 
                    kode: odp.kode, 
                    lat: parseFloat(odp.lat), 
                    lng: parseFloat(odp.lng), 
                    marker 
                });

                allLatLng.push([parseFloat(odp.lat), parseFloat(odp.lng)]);
            });

            // --- Buat Filter dengan Grouping Area ---
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
                }).bindPopup(pelanggan.popup);
                
                marker.odp = pelanggan.odp;
                pelangganMarkers.push(marker);
                
                allLatLng.push([parseFloat(pelanggan.lat), parseFloat(pelanggan.lng)]);
            });
        }

        // --- Proses Garis Koneksi ODP - Pelanggan dengan ANIMASI ---
        if (data.lines && data.lines.length > 0) {
            console.log('Processing lines with animation:', data.lines.length);
            
            data.lines.forEach(lineData => {
                const color = lineData.status === 'online' ? '#00ff00' : '#ff0000'; // Hijau dan Merah lebih terang
                
                // Format koordinat
                const from = Array.isArray(lineData.from) ? lineData.from : [parseFloat(lineData.from.lat), parseFloat(lineData.from.lng)];
                const to = Array.isArray(lineData.to) ? lineData.to : [parseFloat(lineData.to.lat), parseFloat(lineData.to.lng)];
                
                console.log('Creating line from', from, 'to', to, 'color:', color);
                
                // Buat garis putus-putus dengan animasi
                const line = L.polyline([from, to], { 
                    color: color, 
                    dashArray: '10, 10', 
                    weight: 4, // Lebih tebal agar terlihat jelas
                    opacity: 0.8,
                    lineCap: 'round',
                    className: 'animated-dashed-line'
                });
                
                line.odp = lineData.odp;
                lines.push(line);

                // Mulai animasi garis
                startLineAnimation(line);
            });
        }

        // --- TAMPILKAN SEMUA DATA KE MAP ---
        console.log('Adding all data to map...');
        
        // Tambahkan semua ODP marker ke map
        odpMarkers.forEach(odp => {
            if (odp.marker) {
                odp.marker.addTo(map);
            }
        });

        // Tambahkan semua pelanggan marker ke map
        pelangganMarkers.forEach(pelanggan => {
            if (pelanggan) {
                pelanggan.addTo(map);
            }
        });

        // Tambahkan semua garis ke map DAN mulai animasi
        lines.forEach(line => {
            if (line) {
                line.addTo(map);
                // Pastikan animasi berjalan setelah ditambahkan ke map
                setTimeout(() => {
                    startLineAnimation(line);
                }, 100);
            }
        });

        // Set filter ke "Semua ODP"
        odpFilter.value = '';
        currentFilter = '';

        // --- SET CENTER MAP KE TENGAH-TENGAH DATA ---
        if (allLatLng.length > 0) {
            console.log('Setting map center to data bounds...');
            
            const bounds = L.latLngBounds(allLatLng);
            map.fitBounds(bounds, { 
                padding: [50, 50],
                maxZoom: 15
            });
            
            console.log('Map centered with bounds:', bounds);
            console.log('Total animations running:', animationIntervals.length);
            
            isDataLoaded = true;
        } else {
            console.log('No data available to center map');
        }

        console.log('Data processed - ODP:', odpMarkers.length, 'Pelanggan:', pelangganMarkers.length, 'Lines:', lines.length);

    } catch(e) {
        console.error("Gagal load data:", e);
        document.getElementById('loading').style.display = 'none';
    }
}

// --- Terapkan Filter ---
function applyCurrentFilter() {
    const selectedKode = currentFilter;
    console.log('Applying filter:', selectedKode || 'Semua ODP');
    
    let bounds = [];

    // Hapus semua marker dari peta
    clearMapMarkersOnly();

    if (!selectedKode) {
        // Tampilkan SEMUA ODP dan Pelanggan
        odpMarkers.forEach(odp => {
            if (odp.marker) {
                odp.marker.addTo(map);
                bounds.push([odp.lat, odp.lng]);
            }
        });

        pelangganMarkers.forEach(pelanggan => {
            if (pelanggan) {
                pelanggan.addTo(map);
                bounds.push([pelanggan.getLatLng().lat, pelanggan.getLatLng().lng]);
            }
        });

        // Tampilkan semua garis DAN mulai ulang animasi
        lines.forEach(line => {
            if (line) {
                line.addTo(map);
                // Mulai animasi untuk garis yang ditambahkan
                setTimeout(() => {
                    startLineAnimation(line);
                }, 100);
            }
        });

        console.log('Showing all data - maintaining current view');

    } else {
        // Tampilkan hanya ODP dan Pelanggan yang dipilih
        odpMarkers.forEach(odp => {
            if (odp.kode === selectedKode && odp.marker) {
                odp.marker.addTo(map);
                bounds.push([odp.lat, odp.lng]);
            }
        });

        pelangganMarkers.forEach(pelanggan => {
            if (pelanggan.odp === selectedKode && pelanggan) {
                pelanggan.addTo(map);
                bounds.push([pelanggan.getLatLng().lat, pelanggan.getLatLng().lng]);
            }
        });

        // Tampilkan garis yang sesuai DAN mulai animasi
        lines.forEach(line => {
            if (line.odp === selectedKode && line) {
                line.addTo(map);
                // Mulai animasi untuk garis yang ditambahkan
                setTimeout(() => {
                    startLineAnimation(line);
                }, 100);
            }
        });

        // Untuk ODP spesifik, zoom ke ODP tersebut
        if (bounds.length > 0) {
            setTimeout(() => {
                try {
                    const latLngBounds = L.latLngBounds(bounds);
                    map.fitBounds(latLngBounds, { padding: [50, 50] });
                    console.log('Zoomed to ODP:', selectedKode);
                } catch (e) {
                    console.error('Error setting filtered bounds:', e);
                }
            }, 100);
        }
    }
}

// --- Clear hanya marker dari peta (untuk filter) ---
function clearMapMarkersOnly() {
    // Hentikan animasi sementara
    animationIntervals.forEach(id => {
        clearInterval(id);
    });
    animationIntervals = [];
    
    // Hapus layer dari peta
    odpMarkers.forEach(odp => {
        if (odp.marker && map.hasLayer(odp.marker)) {
            map.removeLayer(odp.marker);
        }
    });
    
    pelangganMarkers.forEach(pelanggan => {
        if (pelanggan && map.hasLayer(pelanggan)) {
            map.removeLayer(pelanggan);
        }
    });
    
    lines.forEach(line => {
        if (line && map.hasLayer(line)) {
            map.removeLayer(line);
        }
    });
}

// --- Event Filter ODP ---
document.getElementById('odpFilter').addEventListener('change', function() {
    const selectedKode = this.value;
    console.log('ODP filter changed to:', selectedKode);
    
    currentFilter = selectedKode;
    
    // Terapkan filter tanpa reload data
    applyCurrentFilter();
});

// --- Countdown Refresh (120 detik) ---
let countdown = 120;
let countdownInterval;

function startCountdown() {
    const timerSpan = document.getElementById('timer');
    countdown = 120;
    
    if (countdownInterval) {
        clearInterval(countdownInterval);
    }
    
    timerSpan.textContent = countdown;
    
    countdownInterval = setInterval(() => {
        countdown--;
        timerSpan.textContent = countdown;
        if (countdown <= 0) {
            clearInterval(countdownInterval);
            console.log('Auto-reloading data...');
            loadData().then(startCountdown);
        }
    }, 1000);
}

// --- Fullscreen Toggle ---
document.getElementById("fullViewBtn").addEventListener("click", function() {
    const view = document.getElementById("view");
    const button = this;
    const isFullscreen = view.classList.contains("fullscreen");
    
    view.classList.toggle("fullscreen");
    button.innerText = isFullscreen ? "Fullscreen" : "Minimize";
    
    setTimeout(() => {
        if (map) {
            map.invalidateSize();
            if (isDataLoaded && !currentFilter) {
                console.log('Maintaining current view in fullscreen');
            }
        }
    }, 300);
});

// --- Load Awal ---
document.addEventListener('DOMContentLoaded', function() {
    console.log('Initializing map and loading data...');
    loadData();
    startCountdown();
});
</script>


<?php require 'footer.php'; ?>