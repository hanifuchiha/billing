<?php require 'header.php'; ?>

<!-- Loading Screen -->
<div id="loading">
    <div class="loading-content">
        <div class="spinner"></div>
        <div class="loading-text">Memuat Data Customer...</div>
        <div class="loading-progress">
            <div class="progress-bar"></div>
        </div>
    </div>
</div>

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
        background: linear-gradient(135deg, #ff8c00 0%, #ffaf4d 25%, #ffffff 50%, #4da1ff 75%, #0066ff 100%);
        background-size: 400% 400%;
        animation: gradientShift 8s ease infinite;
        display: flex;
        justify-content: center;
        align-items: center;
        z-index: 9999;
        opacity: 1;
        transition: opacity 0.8s ease-out, visibility 0.8s ease-out;
    }

    @keyframes gradientShift {
        0% { background-position: 0% 50%; }
        50% { background-position: 100% 50%; }
        100% { background-position: 0% 50%; }
    }

    #loading.fade-out {
        opacity: 0;
        visibility: hidden;
    }

    .loading-content {
        text-align: center;
        color: #000000;
        text-shadow: 0 2px 4px rgba(255, 255, 255, 0.8);
    }

    .spinner {
        width: 60px;
        height: 60px;
        border: 4px solid rgba(255, 140, 0, 0.3);
        border-top-color: #ff8c00;
        border-radius: 50%;
        animation: spin 1s linear infinite;
        margin: 0 auto 20px;
        box-shadow: 0 0 20px rgba(255, 140, 0, 0.3);
    }

    @keyframes spin {
        from {
            transform: rotate(0deg);
        }
        to {
            transform: rotate(360deg);
        }
    }

    .loading-text {
        font-size: 18px;
        font-weight: 600;
        margin-bottom: 20px;
        text-shadow: 0 2px 8px rgba(255, 255, 255, 0.9), 0 1px 2px rgba(0, 0, 0, 0.1);
        animation: pulse 2s ease-in-out infinite;
        letter-spacing: 0.5px;
    }

    @keyframes pulse {
        0%, 100% { 
            opacity: 1; 
            transform: scale(1);
        }
        50% { 
            opacity: 0.9; 
            transform: scale(1.02);
        }
    }

    .loading-progress {
        width: 200px;
        height: 4px;
        background: rgba(255, 140, 0, 0.2);
        border-radius: 2px;
        margin: 0 auto;
        overflow: hidden;
        box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.1);
    }

    .progress-bar {
        height: 100%;
        background: linear-gradient(90deg, #ff8c00, #ff6b00);
        border-radius: 2px;
        width: 0%;
        animation: progress 2s ease-out infinite;
        box-shadow: 0 0 10px rgba(255, 140, 0, 0.5);
    }

    @keyframes progress {
        0% { width: 0%; }
        50% { width: 70%; }
        100% { width: 100%; }
    }

    .modal-dialog {
        width: 90vw !important;
        max-width: none !important;
        margin: auto;
    }

    .modal-content {
        flex-direction: row !important;
        display: flex;
        width: 100%;
        max-height: 90vh;
        overflow: hidden;
        background: linear-gradient(to bottom, #ffffff, #e0e0e0, #ffffff);
        border-radius: 10px;
        border: 2px solid #d0d0d0;
        box-shadow: 0px 4px 10px rgba(0, 0, 0, 0.2);
        font-size: 10px;
    }

    .modal-body {
        overflow-y: auto;
        flex: 1;
        padding: 15px;
    }

    .modal-backdrop {
        width: 100vw;
        height: 100vh;
    }

    /* Card untuk mobile view tabel */
    .user-card {
        margin-bottom: 15px;
        border: 1px solid #ddd;
        border-radius: 8px;
        padding: 15px;
        background: #fff;
    }
    .user-card h6 {
        margin-bottom: 10px;
        color: #333;
    }
    .user-card p {
        margin: 5px 0;
        font-size: 14px;
    }
    .user-card .actions {
        margin-top: 10px;
        display: flex;
        gap: 5px;
        flex-wrap: wrap;
        z-index: 1100;
        position: relative;
    }
    .user-card .actions button,
    .user-card .actions a {
        pointer-events: auto;
        z-index: 1101;
        position: relative;
    }

   
</style>

<!-- Modal -->
<div class="modal fade" id="addCustomerModal" tabindex="-1" aria-labelledby="addCustomerModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-sm" style="max-width:300px; height:85vh;">
    <div class="modal-content d-flex flex-column" style="height:90vh; border:none; border-radius:12px; overflow:hidden; flex-direction: column !important;">

    <!-- Header primary -->
        <div class="bg-primary text-white text-center p-2" style="background: var(--orange) !important; color: #ffffff !important;">
            <h5 class="m-0 text-white">Tambah Customer</h5>
    </div>

      <!-- Iframe di tengah -->
      <div class="flex-grow-1">
        <iframe id="customerIframe" src="addcustomerform.php" style="width:100%; height:100%; border:none;"></iframe>
      </div>

      <!-- Modal Footer -->
      <div class="modal-footer bg-light border-top">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
          <i class="fas fa-times"></i> Tutup
        </button>
      </div>

    </div>
  </div>
</div>

<!-- Script -->
<script>
document.addEventListener("DOMContentLoaded", function() {
    const iframe = document.getElementById("customerIframe");

    function makeIframeFormsNavigateTop() {
        try {
            const doc = iframe.contentDocument || iframe.contentWindow.document;
            if (!doc) return;

            // Ensure all forms submit to top window instead of staying inside the iframe
            const forms = doc.querySelectorAll('form');
            forms.forEach(f => {
                try { f.setAttribute('target', '_top'); } catch(e){}
            });

            // Ensure links open in top as well
            const anchors = doc.querySelectorAll('a');
            anchors.forEach(a => {
                try { a.setAttribute('target', '_top'); } catch(e){}
            });
        } catch (e) {
            // Access may fail if iframe content is cross-origin; ignore silently
            // but we still keep the success detection fallback
            // console.warn('Cannot access iframe doc:', e);
        }
    }

    iframe.addEventListener('load', function() {
        // Try to adjust internal forms/links so user clicks navigate the top window
        makeIframeFormsNavigateTop();

        try {
            const url = iframe.contentWindow.location.href;
            // Jika iframe sudah memuat halaman tables.php (atau URL berisi pesan sukses)
            if (url.includes("tables.php?pesan=berhasil&text=Success") || url.includes("tables.php?pesan=berhasil")) {
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


<style>
    .tables-main-card-header {
        background: linear-gradient(135deg, var(--logo-primary, #1d4ed8), var(--logo-secondary, #2563eb)) !important;
        border-bottom: 1px solid rgba(255, 255, 255, 0.22);
    }

    .tables-main-card-header .tables-main-card-title {
        color: #ffffff !important;
        opacity: 1 !important;
        font-weight: 700;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.35);
        letter-spacing: 0.2px;
    }

    body.app-theme-dark .tables-main-card-header {
        background: linear-gradient(135deg, #0f172a, #1e293b) !important;
        border-bottom-color: rgba(148, 163, 184, 0.3);
    }

    .tables-theme-fix .text-muted,
    .tables-theme-fix .small.text-muted {
        color: var(--bs-secondary-color, #495057) !important;
        opacity: 1 !important;
    }

    body.app-theme-dark .tables-theme-fix .text-muted,
    body.app-theme-dark .tables-theme-fix .small.text-muted {
        color: #cbd5e1 !important;
    }

    .tables-theme-fix .badge {
        font-weight: 700;
        opacity: 1 !important;
    }

    .tables-theme-fix .tables-main-card-header .badge {
        background: rgba(255, 255, 255, 0.94) !important;
        color: #0f172a !important;
        border: 1px solid rgba(15, 23, 42, 0.2);
        text-shadow: none;
    }

    body.app-theme-dark .tables-theme-fix .tables-main-card-header .badge {
        background: #e2e8f0 !important;
        color: #0f172a !important;
        border-color: rgba(148, 163, 184, 0.75);
    }

    .customer-table-enhanced {
        border-collapse: separate !important;
        border-spacing: 0 12px !important;
    }

    .customer-table-enhanced thead th {
        border-bottom: 2px solid #cbd5e1 !important;
        background: #f8fafc;
        color: #0f172a !important;
    }

    .customer-table-enhanced tbody td {
        background: #ffffff !important;
        border-top: 1px solid #cbd5e1 !important;
        border-bottom: 1px solid #cbd5e1 !important;
        box-shadow: 0 6px 16px rgba(15, 23, 42, 0.12);
        vertical-align: middle;
    }

    .customer-table-enhanced tbody td:first-child {
        border-left: 1px solid #cbd5e1 !important;
        border-top-left-radius: 10px;
        border-bottom-left-radius: 10px;
    }

    .customer-table-enhanced tbody td:last-child {
        border-right: 1px solid #cbd5e1 !important;
        border-top-right-radius: 10px;
        border-bottom-right-radius: 10px;
    }

    .customer-table-enhanced tbody tr:hover td {
        box-shadow: 0 10px 22px rgba(15, 23, 42, 0.18);
    }

    body.app-theme-dark .customer-table-enhanced thead th {
        background: #0f172a;
        color: #e2e8f0 !important;
        border-bottom-color: #334155 !important;
    }

    body.app-theme-dark .customer-table-enhanced tbody td {
        background: #111827 !important;
        border-top-color: #475569 !important;
        border-bottom-color: #475569 !important;
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.45);
    }

    body.app-theme-dark .customer-table-enhanced tbody td:first-child {
        border-left-color: #475569 !important;
    }

    body.app-theme-dark .customer-table-enhanced tbody td:last-child {
        border-right-color: #475569 !important;
    }

    body.app-theme-dark .customer-table-enhanced tbody tr:hover td {
        box-shadow: 0 10px 24px rgba(2, 6, 23, 0.62);
    }
</style>



    <div class="container-fluid py-4 px-3 px-md-4 tables-theme-fix">
        <div class="row">
            <div class="col-12">
                <div class="card mb-4 shadow-sm">
                    <div class="card-header pb-0 dashboard-dark-header theme-aware-header tables-main-card-header">
                        <h6 class="mb-0 tables-main-card-title">Customer pppoe</h6>

</div>

                        <?php
                        if ($_GET['pesan'] == "berhasil") {
                        ?>
                            <div class="alert alert-success" role="alert">
                                <h4 class="alert-heading">Success Register</h4>
                                <p><?php echo $_GET['text'] ?></p>

                            </div>
                        <?php
                        }
                        ?>

                        <div class="container mt-5">
                         <!-- Tombol -->
<button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCustomerModal">
  Add Customer
</button>
<a href="importcustomerpppoe.php" class="btn btn-success ms-2">
  <i class="fas fa-file-excel"></i> Import dari Excel
</a>


                            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
                            <style>
                                .input-custom-height {
                                    height: 38px;
                                }
                            </style>
                             
                            <form method="POST" class="mb-2">
                                <label for="server" class="form-label">Pencarian</label>
                                <input type="hidden" name="action" value="cari_id">
                                <div class="input-group">
                                    <input name="cariid" class="form-control input-custom-height" placeholder="Mencari ID PELANGGAN">
                                    <button type="submit" class="btn btn-warning input-custom-height">Cari</button>
                                </div>
                            </form>
                            <form method="POST" class="mb-2">
                                <input type="hidden" name="action" value="cari_odp">
                                <div class="input-group">
                                    <input name="cariodp" class="form-control input-custom-height" placeholder="Mencari KODE ODP">
                                    <button type="submit" class="btn btn-warning input-custom-height">Cari</button>
                                </div>
                            </form>
                            <form method="POST" class="mb-2">
                                <input type="hidden" name="action" value="cari_nowa">
                                <div class="input-group">
                                    <input name="carinowa" class="form-control input-custom-height" placeholder="Mencari no Whatsap terdaftar">
                                    <button type="submit" class="btn btn-warning input-custom-height">Cari</button>
                                </div>
                            </form>
                            <form method="POST" class="mb-2">
                                <input type="hidden" name="action" value="cari_paket">
                                <div class="input-group">
                                    <select class="form-select input-custom-height" id="caripaket" name="caripaket">
                                        <?php
                                        $query = "SELECT id, PAKET FROM paket WHERE PEMILIK IN ($server_list)";
                                        $result = mysqli_query($conn, $query);
                                        $paketFilterRows = reseller_filter_rows($conn, reseller_collect_rows($result), 'broadband');
                                        $paketFilterRows = paketVisibilityFilterRows($paketFilterRows, $assistant_hidden_paket_broadband, 'PAKET');
                                        $paketFilterSeen = [];
                                        foreach ($paketFilterRows as $row) {
                                            $paketshow = htmlspecialchars($row['PAKET'], ENT_QUOTES, 'UTF-8');
                                            if (isset($paketFilterSeen[$paketshow])) {
                                                continue;
                                            }
                                            $paketFilterSeen[$paketshow] = true;
                                            echo '<option value="' . $paketshow . '">' . $paketshow . '</option>';
                                        }
                                        ?>
                                    </select>
                                    <button type="submit" class="btn btn-warning input-custom-height">Cari</button>
                                </div>
                            </form>
                            <hr>
                            <hr>
                            <form method="POST">
                                <label for="server" class="form-label">Pencarian berdasarkan server dan area</label>
                                <input type="hidden" name="action" value="filter_area">
                                <div class="mb-3">
                                    <!-- <label for="server" class="form-label">Server</label> -->
                                    <select required class="form-control" id="server" name="server" onchange="loadArea()">
                                                                                                                <option value="">-- Pilih Server --</option>
                                                                                                               <?php
                                                        // Ambil semua brand dan area, gabungkan area per brand
                                                        $query = "SELECT BRAND, AREA, PEMILIK FROM server WHERE `pemilik` IN ($server_list)";
                                                        $result = mysqli_query($conn, $query);
                                                        $brand_area_map = [];
                                                        while ($row = mysqli_fetch_assoc($result)) {
                                                            $brand = trim($row['BRAND']);
                                                            $area = trim($row['AREA']);
                                                            $pemilik = trim($row['PEMILIK']);
                                                            if (!isset($brand_area_map[$brand])) {
                                                                $brand_area_map[$brand] = [
                                                                    'areas' => [],
                                                                    'pemilik' => $pemilik
                                                                ];
                                                            }
                                                            if (!in_array($area, $brand_area_map[$brand]['areas'])) {
                                                                $brand_area_map[$brand]['areas'][] = $area;
                                                            }
                                                        }
                                                        foreach ($brand_area_map as $brand => $data) {
                                                            echo '<option value="' . htmlspecialchars($data['pemilik'], ENT_QUOTES, 'UTF-8') . '" data-areas="' . htmlspecialchars(implode(',', $data['areas']), ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($brand, ENT_QUOTES, 'UTF-8') . '</option>';
                                                        }
                                                        ?>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <!-- <label for="area" class="form-label">AREA</label> -->
                                    <select  required class="form-control" id="area" name="area" onchange="loadODP()">
                                                                            <option value="">-- Pilih AREA --</option>
                                    </select>
                                </div>



                                <!-- Optional dropdowns jika memang ada -->
                                <select hidden id="odp" name="odp"></select>
                                <select hidden id="packages" name="packages"></select>

                                <br>
                                <button type="submit" class="btn btn-warning">Show server area</button>
                            </form>
<input type="text" id="searchInput" placeholder="🔍 Sortir data..." class="form-control mb-3">

                            <script>
                                                            function loadArea() {
                                                                const serverSelect = document.getElementById("server");
                                                                const areaDropdown = document.getElementById("area");
                                                                const odpDropdown = document.getElementById("odp");
                                                                const packageDropdown = document.getElementById("packages");

                                                                // Reset dropdown isi
                                                                if (areaDropdown) areaDropdown.innerHTML = '<option value="">-- Pilih AREA --</option>';
                                                                if (odpDropdown) odpDropdown.innerHTML = '<option value="">Loading...</option>';
                                                                if (packageDropdown) packageDropdown.innerHTML = '<option value="">Loading...</option>';

                                                                const selectedOption = serverSelect.options[serverSelect.selectedIndex];
                                                                const areas = selectedOption.getAttribute('data-areas');
                                                                if (areas) {
                                                                    const areaArr = areas.split(',');
                                                                    areaArr.forEach(function(area) {
                                                                        const opt = document.createElement('option');
                                                                        opt.value = area;
                                                                        opt.textContent = area;
                                                                        areaDropdown.appendChild(opt);
                                                                    });
                                                                }
                                                            }
                            </script>



















                    <script>
                        document.getElementById("searchInput").addEventListener("keyup", function() {
                            var input, filter, table, tr, td, i, j, txtValue;
                            input = document.getElementById("searchInput");
                            filter = input.value.toUpperCase();
                            table = document.getElementById("dataTable");
                            tr = table.getElementsByTagName("tr");

                            for (i = 0; i < tr.length; i++) {
                                td = tr[i].getElementsByTagName("td");
                                let found = false;
                                for (j = 0; j < td.length; j++) {
                                    if (td[j]) {
                                        txtValue = td[j].textContent || td[j].innerText;
                                        if (txtValue.toUpperCase().indexOf(filter) > -1) {
                                            found = true;
                                            break;
                                        }
                                    }
                                }
                                tr[i].style.display = found ? "" : "none";
                            }

                            // Tunggu sebentar lalu scroll ke atas
                            setTimeout(function() {
                                window.scrollTo(0, 0);
                                document.documentElement.scrollTop = 0;
                                document.body.scrollTop = 0;
                            }, 10);
                        });
                    </script>
                 <script>
let trafficHistory = {}; // Menyimpan riwayat data per ID pelanggan
const fetchStates = {}; // Menyimpan state polling per ID pelanggan
const FETCH_INTERVAL_MS = 10000;
const FETCH_TIMEOUT_MS = 8000;
const DBM_CACHE_TTL_MS = 60000;
const dbmCache = {};

function getFetchState(idPel) {
    if (!fetchStates[idPel]) {
        fetchStates[idPel] = {
            inFlight: false,
            timerId: null
        };
    }
    return fetchStates[idPel];
}

async function fetchJsonWithTimeout(url, options = {}, timeoutMs = FETCH_TIMEOUT_MS) {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), timeoutMs);

    try {
        const response = await fetch(url, {
            ...options,
            signal: controller.signal
        });

        if (!response.ok) {
            throw new Error(`HTTP error! Status: ${response.status}`);
        }

        return await response.json();
    } finally {
        clearTimeout(timeoutId);
    }
}

// Ambil RX/Tx dBm dari semua file onulist sesuai server list.
// Fallback ke pencocokan PPPoE (IDPEL) jika MAC tidak ditemukan.
async function getDbmFromOnulist(mac, serverListStr, idPel) {
    let macPrefix = mac.split(':').slice(0,5).join(':'); // ambil 5 byte pertama
    let pppoeNeedle = String(idPel || '').trim().toLowerCase();
    let servers = serverListStr.replace(/'/g, '').split(',').map(s => s.trim());

    try {
        let fileRes = await fetch('getdata/list_onulist_files.php'); 
        let fileList = await fileRes.json();

        for (let fileName of fileList) {
            if (servers.some(s => fileName.includes(s))) {
                try {
                    let res = await fetch(`getdata/getonulist.php?file=${fileName}`);
                    let text = await res.text();
                    let lines = text.split('\n');
                    for (let line of lines) {
                        const lineLower = line.toLowerCase();
                        const matchByMac = macPrefix && lineLower.includes(macPrefix.toLowerCase());
                        const matchByPppoe = pppoeNeedle && (
                            lineLower.includes(`pppoe: ${pppoeNeedle}`) ||
                            lineLower.includes(`| nama: ${pppoeNeedle} |`) ||
                            lineLower.includes(`,${pppoeNeedle},`)
                        );

                        if (!matchByMac && !matchByPppoe) continue;

                        let dataPart = line.split('|').find(p => p.trim().startsWith('Data:'));
                        if (dataPart) {
                            let values = dataPart.replace('Data:', '').split(',');
                            let rxDbm = parseFloat(values[values.length - 2]) || 0;
                            let txDbm = parseFloat(values[values.length - 1]) || 0;
                            return { rxDbm, txDbm, file: fileName };
                        }
                    }
                } catch(e) {
                    console.error(`Error reading ${fileName}:`, e);
                }
            }
        }
    } catch(e) {
        console.error(e);
    }

    return { rxDbm: 0, txDbm: 0, file: null };
}

async function getDbmFromOnulistCached(mac, serverListStr, idPel) {
    const now = Date.now();
    const cached = dbmCache[idPel];
    if (cached && (now - cached.ts) < DBM_CACHE_TTL_MS) {
        return cached.value;
    }

    const value = await getDbmFromOnulist(mac, serverListStr, idPel);
    dbmCache[idPel] = {
        ts: now,
        value: value
    };
    return value;
}


async function fetchData(idPel, ipServer, userServer, passwordServer) {
    const state = getFetchState(idPel);
    if (state.inFlight) {
        return;
    }
    state.inFlight = true;

    try {
        const chartCanvas = document.getElementById(`trafficChart${idPel}`);
        const overviewModal = document.getElementById(`exampleoverview${idPel}`);
        const isOverviewOpen = !!(overviewModal && overviewModal.classList.contains('show'));

        const data = await fetchJsonWithTimeout('getdata/getonlinecustomer.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ ip: ipServer, idpel: idPel, us: userServer, ps: passwordServer })
        });

        if (isOverviewOpen && chartCanvas) {
            const ctx = chartCanvas.getContext('2d');
            if (ctx) {
                if (!trafficHistory[idPel]) {
                    trafficHistory[idPel] = { labels: [], download: [], upload: [] };
                }

                let history = trafficHistory[idPel];

                if (history.labels.length >= 10) {
                    history.labels.shift();
                    history.download.shift();
                    history.upload.shift();
                }

                let timestamp = new Date().toLocaleTimeString();
                history.labels.push(timestamp);
                history.download.push(data.download);
                history.upload.push(data.upload);

                if (window[`trafficChartInstance${idPel}`]) {
                    window[`trafficChartInstance${idPel}`].destroy();
                }

                window[`trafficChartInstance${idPel}`] = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: history.labels,
                        datasets: [
                            { label: 'Download (Mbps)', data: history.download, borderColor: 'blue', backgroundColor: 'rgba(0,0,255,0.2)', fill: true },
                            { label: 'Upload (Mbps)', data: history.upload, borderColor: 'green', backgroundColor: 'rgba(0,128,0,0.2)', fill: true }
                        ]
                    },
                    options: { responsive: true, scales: { y: { beginAtZero: true } } }
                });
            }
        }

        let macToCheck = data.status === "Online" ? data.active_caller_id : data.last_caller_secret;

        // Ambil RX/TX dBm dari semua file onulist
        let serverListStr = "<?php echo $server_list ?>";
        let rxTxDbm = await getDbmFromOnulistCached(macToCheck, serverListStr, idPel);

        // Tentukan badge dan teks RX dBm
let rxBadge, rxDisplay;

if (!rxTxDbm.rxDbm || rxTxDbm.rxDbm === 0) {
    rxBadge = 'bg-secondary';  // abu-abu
    rxDisplay = 'Unknown';
} else {
    rxBadge = rxTxDbm.rxDbm > -27 ? 'badge-sm bg-gradient-success' : 'badge-sm bg-gradient-danger';
    rxDisplay = rxTxDbm.rxDbm;
}

        let statusElement = document.getElementById(`data-status-${idPel}`);
let infoElement = document.getElementById(`data-info-${idPel}`);
let statusElement2 = document.getElementById(`data-status2-${idPel}`);
let infoElement2 = document.getElementById(`data-info2-${idPel}`);
let statusElementMobile = document.getElementById(`data-status2-mobile-${idPel}`);

if (data.status === "Online") {
    statusElement.innerHTML = `<span class="badge badge-sm bg-gradient-success">Connected ${data.login_via}</span>`;
    infoElement.innerHTML = `<span class="text-secondary text-xs font-weight-bold">
        <br>Actual paket aktif : ${data.cekexpired || "N/A"}
        <br>${data.remote_ip || "N/A"}
        <br>MAC : ${data.active_caller_id || "N/A"}
        <br>RX dBm: <span class="badge badge-sm ${rxBadge}">${rxDisplay}</span>
    </span>`;
    statusElement2.innerHTML = statusElement.innerHTML;
    infoElement2.innerHTML = `<span class="text-secondary text-xs font-weight-bold">
        <br>Actual paket aktif : ${data.cekexpired || "N/A"}
        <br>Down/Up : ${data.download || "N/A"} Mbps / ${data.upload || "N/A"} Mbps
        <br>${data.remote_ip || "N/A"}
        <br>MAC : ${data.active_caller_id || "N/A"}
    <br>RX dBm: <span class="badge badge-sm ${rxBadge}">${rxDisplay}</span>
    </span>`;
    if (statusElementMobile) statusElementMobile.innerHTML = statusElement.innerHTML;

    // Tambahkan tombol remote ONT
    addRemoteButton(idPel, ipServer, userServer, passwordServer, data.remote_ip);

} else {
    statusElement.innerHTML = '<span class="badge badge-sm bg-gradient-danger">Los</span>';
    infoElement.innerHTML = `<span class="text-secondary text-xs font-weight-bold">
        <br>Actual paket aktif : ${data.cekexpired || "N/A"}
        <br>Last Logged Out: ${data.ceklastloggedout || "N/A"}
        <br>Last Disconnect: ${data.ceklastdisconnect || "N/A"}
        <br>MAC : ${data.last_caller_secret || "N/A"}
        <br>RX dBm: <span class="badge badge-sm bg-gradient-danger">LOS ( last dbm : ${rxDisplay} )</span>
    </span>`;
    statusElement2.innerHTML = statusElement.innerHTML;
    infoElement2.innerHTML = infoElement.innerHTML;
    if (statusElementMobile) statusElementMobile.innerHTML = statusElement.innerHTML;

    // Hapus tombol remote jika ada
    const container = document.getElementById(`remoteContainer-${idPel}`);
    if (container) container.innerHTML = '';

    cekRadwhoFallback(idPel);
}
    } catch (error) {
        if (error && error.name === 'AbortError') {
            console.warn(`Fetch timeout for ${idPel}`);
        } else {
            console.error('Error:', error);
        }
    } finally {
        state.inFlight = false;
    }
}

function scheduleNextFetch(idPel, ipServer, userServer, passwordServer) {
    const state = getFetchState(idPel);
    if (state.timerId) {
        clearTimeout(state.timerId);
    }

    state.timerId = setTimeout(async () => {
        await fetchData(idPel, ipServer, userServer, passwordServer);
        scheduleNextFetch(idPel, ipServer, userServer, passwordServer);
    }, FETCH_INTERVAL_MS);
}

function startFetching(idPel, ipServer, userServer, passwordServer) {
    const state = getFetchState(idPel);
    if (state.inFlight || state.timerId) {
        return;
    }

    // Stagger start supaya tidak semua customer fetch bersamaan (UI jadi berat/susah diklik).
    const jitterMs = Math.floor(Math.random() * 1500);
    state.timerId = setTimeout(() => {
        state.timerId = null;
        fetchData(idPel, ipServer, userServer, passwordServer)
            .finally(() => {
                scheduleNextFetch(idPel, ipServer, userServer, passwordServer);
            });
    }, jitterMs);
}



</script>


<script>
function addRemoteButton(idPel, ipServer, userServer, passwordServer, remoteIp) {
    const remoteContainer = document.getElementById(`remoteContainer-${idPel}`);
    if (!remoteContainer) return;

    // buat tombol
    const btn = document.createElement("button");
    btn.className = "badge bg-gradient-info";
    btn.id = `remoteBtn-${idPel}`;
    btn.textContent = "Remote ONT";

    // simpan data ke atribut agar bisa dipakai di event listener
    btn.dataset.idPel = idPel;
    btn.dataset.ipServer = ipServer;
    btn.dataset.userServer = userServer;
    btn.dataset.passwordServer = passwordServer;
    btn.dataset.remoteIp = remoteIp;

    remoteContainer.innerHTML = ""; // clear isi container
    remoteContainer.appendChild(btn);

    // event listener terpisah
    btn.addEventListener("click", handleRemoteClick);
}

function handleRemoteClick(event) {
    const btn = event.currentTarget;

    // ambil data dari atribut
    const idPel = btn.dataset.idPel;
    const ipServer = btn.dataset.ipServer;
    const userServer = btn.dataset.userServer;
    const passwordServer = btn.dataset.passwordServer;
    const remoteIp = btn.dataset.remoteIp;

    // Tampilkan loading dulu - proses di ontremot.php bisa makan beberapa detik
    // (nunggu tunnel connect dll) dan responsenya baru muncul di iframe setelah
    // semuanya selesai, jadi tanpa ini iframe cuma keliatan blank/nge-freeze.
    const loadingEl = document.getElementById("remoteLoading");
    if (loadingEl) loadingEl.style.display = "flex";

    // buat form dinamis
    const form = document.createElement("form");
    form.method = "POST";
    form.action = "proses/ontremot.php";
    form.target = "remoteFrame"; // hasil ditampilkan di iframe

    form.innerHTML = `
        <input type="hidden" name="idPel" value="${idPel}">
        <input type="hidden" name="remote_ip" value="${remoteIp}">
        <input type="hidden" name="ipServer" value="${ipServer}">
        <input type="hidden" name="userServer" value="${userServer}">
        <input type="hidden" name="passwordServer" value="${passwordServer}">
    `;

    document.body.appendChild(form);
    form.submit();

    // buka modal
    const modal = new bootstrap.Modal(document.getElementById("remoteModal"));
    modal.show();
}
</script>



                    <script>
                        function cekRadwhoFallback(idPel) {
                            fetch(`getdata/cek_radius.php?idpel=${idPel}`)
                                .then(response => response.json())
                                .then(data => {
                                    let statusElement = document.getElementById(`data-status-${idPel}`);
                                    let infoElement = document.getElementById(`data-info-${idPel}`);
                                    let statusElement2 = document.getElementById(`data-status2-${idPel}`);
                                    let infoElement2 = document.getElementById(`data-info2-${idPel}`);
                                    let radwhoElement = document.getElementById(`data-radwho-${idPel}`);

                                    if (!statusElement || !infoElement || !statusElement2 || !infoElement2) return;

                                    if (data.status === "Online") {
                                        // Jika ditemukan online di RADIUS
                                        statusElement.innerHTML = `<span class="badge badge-sm bg-gradient-success">Connected via RADIUS</span>`;
                                        infoElement.innerHTML = `<span class="text-secondary text-xs font-weight-bold">
                    <br>Aktif di FreeRADIUS
                    <br>${data.remote || ''}
                </span>`;
                                        statusElement2.innerHTML = `<span class="badge badge-sm bg-gradient-success">Connected via RADIUS</span>`;
                                        infoElement2.innerHTML = `<span class="text-secondary text-xs font-weight-bold">
                    <br>Aktif di FreeRADIUS
                    <br>${data.remote || ''}
                </span>`;
                                        if (radwhoElement) {
                                            radwhoElement.innerHTML = `
                        <span class="badge bg-gradient-success">🟢 Connected RADIUS</span>
                        <br><small>${data.remote || ''}</small>`;
                                        }
                                    } else {
                                        // Tetap LOS
                                        if (radwhoElement) {
                                            radwhoElement.innerHTML = `<span class="badge bg-gradient-secondary">⚪ Tidak Aktif di RADIUS</span>`;
                                        }
                                    }
                                })
                                .catch(error => {
                                    console.error("❌ Gagal cek fallback radwho:", error);
                                });
                        }
                    </script>

                    <div class="card-body px-0 pt-0 pb-2">
                        <div class="table-responsive p-0">
                            <style>
                            

                                .small-text {
                                    font-size: 8px;
                                }
                            </style>

                            <table class="table align-items-center mb-0 customer-table-enhanced" style="font-size: 10px;"id="dataTable" >
                                <thead>
                                    <tr>
                                        <th  class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">No</th>
                                        <th
                                            class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">
                                            Name ID</th>
                                        <th
                                            class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2 text-center">
                                            Status</th>
                                        <th
                                            class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2 text-center">
                                            Server Area</th>
                                        <th
                                            class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2 text-center">
                                            Packages</th>
                                    </tr>
                                </thead>
                                <tbody >
                                    <?php

                                    require('routeros_api.class.php');

                                    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                                        $action = isset($_POST['action']) ? $_POST['action'] : '';
                                        $sql1 = "";

                                        if ($action === 'cari_id') {
                                            $selected_id = mysqli_real_escape_string($conn, $_POST['cariid'] ?? '');
                                            if (!empty($selected_id)) {
                                                $sql1 = "SELECT * FROM `pelanggan` WHERE `IDPEL` LIKE '%$selected_id%'";
                                            }
                                        } elseif ($action === 'cari_odp') {
                                            $selected_odp = mysqli_real_escape_string($conn, $_POST['cariodp'] ?? '');
                                            if (!empty($selected_odp)) {
                                                $sql1 = "SELECT * FROM `pelanggan` WHERE `ODP` LIKE '%$selected_odp%'";
                                            }
                                        } elseif ($action === 'cari_nowa') {
                                            $selected_nowa = mysqli_real_escape_string($conn, $_POST['carinowa'] ?? '');
                                            if (!empty($selected_nowa)) {
                                                $sql1 = "SELECT * FROM `pelanggan` WHERE `NOWA` LIKE '%$selected_nowa%'";
                                            }
                                        } elseif ($action === 'cari_paket') {
                                            $selected_nowa = mysqli_real_escape_string($conn, $_POST['caripaket'] ?? '');
                                            if (!empty($selected_nowa)) {
                                                $sql1 = "SELECT * FROM `pelanggan` WHERE `PAKET` LIKE '%$selected_nowa%'";
                                            }
                                        } elseif ($action === 'filter_area') {
                                            $selected_server = mysqli_real_escape_string($conn, $_POST['server'] ?? '');
                                            $selected_area   = mysqli_real_escape_string($conn, $_POST['area'] ?? '');

                                            if (empty($selected_server) || empty($selected_area)) {
                                                echo '
                <div class="alert alert-warning alert-dismissible fade show" role="alert">
                    <strong>Peringatan!</strong> Belum tentukan mana yang ditampilkan.
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>';
                                            } else {
                                                if ($selected_server !== 'ALL') {
                                                    $sql1 = "SELECT * FROM `pelanggan` 
                         WHERE `pemilik` = '$selected_server' 
                         AND `AREA` = '$selected_area' 
                         ORDER BY `IDPEL`";
                                                } else {
                                                    $sql1 = "SELECT * FROM `pelanggan` 
                         WHERE `AREA` = '$selected_area' 
                         ORDER BY `IDPEL`";
                                                }
                                            }
                                        }

                                        // Eksekusi query jika sudah diisi
                                        if (!empty($sql1)) {
                                            $result1 = mysqli_query($conn, $sql1);
                                            if (!$result1) {
                                                echo "Query gagal: " . mysqli_error($conn);
                                            }
                                        } elseif (empty($sql1)) {
                                            echo '
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <strong>Peringatan!</strong> Tidak ada parameter pencarian yang valid.xxxx
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>';
                                        }
                                    }








                                    $query1 = mysqli_query($conn, $sql1);
                                    ////////////seting penomoran urut table /////////
                                    $nomor = 0;
                                    while ($data1 = mysqli_fetch_array($query1)) {
                                        $nomor++;
                                        $idselect = $data1['id'];
                                        $pemilik = $data1['PEMILIK'];
                                      
                                        $area = $data1['AREA'];
                                        $sql = "SELECT * FROM `server` WHERE `AREA` = '$area' and `PEMILIK`= '$pemilik'  ";
                                        $query = mysqli_query($conn, $sql);
                                        while ($data = mysqli_fetch_array($query)) {

                                            $user = $data['PEMILIK'];
                                            $ip = $data['IP'];
                                            $password = $data['PASSWORD'];












                                    ?>
                                            <tr>

                                            <?php 
                                             echo "<td></td>";

                                                          // query ambil satu brand
                                                            $sql100 = "SELECT BRAND FROM server 
                                                                    WHERE AREA = '$area' AND PEMILIK = '$pemilik' 
                                                                    LIMIT 1";
                                                            $result100 = $conn->query($sql100);

                                                            if ($result100 && $result100->num_rows > 0) {
                                                                $row   = $result100->fetch_assoc();
                                                                $brand = $row['BRAND']; // hasilnya string satu saja
                                                               
                                                            }
                                            ?>
                                                <td style="width: 100px;">
                                                    <div class="d-flex px-2 py-1" data-bs-toggle="modal"
                                                        data-bs-target="#exampleoverview<?php echo $data1['IDPEL']; ?>"
                                                        style="cursor: pointer;"
                                                        onclick="openMonitorModal(this, '<?php echo $data1['IDPEL'] ?>', '<?php echo $ip; ?>', '<?php echo $user ?>', '<?php echo $password; ?>')">
                                                        <div>
                                                            <img src="https://img.icons8.com/stickers/100/add-user-male.png" class="avatar me-3" alt="Server" width="130px" height="130px"><br>

<?php if ($AKSES == "ADMIN") { ?>
                                                            <button type="button"
                                                                class="badge badge bg-gradient-warning"
                                                                data-idpel="<?= $data1['IDPEL']; ?>"
                                                                data-brand="<?= $brand; ?>"
                                                                data-nowa="<?= $data1['NOWA']; ?>"
                                                                data-nama="<?= $data1['NAMA']; ?>"
                                                                data-alamat="<?= $data1['ALAMAT'] ?? ''; ?>"
                                                                data-email="<?= $data1['EMAIL'] ?? ''; ?>"
                                                                data-tikor="<?= $data1['TIKOR'] ?? ''; ?>"
                                                                data-odp="<?= $data1['ODP'] ?? ''; ?>"
                                                                onclick="openModal(this)">
                                                                Buat Tiket
                                                            </button>
<?php } ?>         
                                                            
                                                            <br>
                                                            <button type="button" class="badge badge bg-gradient-danger" data-bs-toggle="modal" data-bs-target="#examplelivechat<?php echo $data1['IDPEL']; ?>">
                                                                                                Live Chat
                                                                                            </button>
                                                              <div id="remoteContainer-<?= $data1['IDPEL']; ?>"></div>                               
                                                        </div>

                                                        <div class="d-flex flex-column justify-content-center text-wrap"
                                                            style="width: 100px; word-wrap: break-word; overflow-wrap: break-word;">
                                                            <h6 class="mb-0 text-sm small-text" style="font-size: 8px;">
                                                                <?php echo $IDPEL = $data1['IDPEL']; ?></h6>
                                                            <p class="text-sm text-secondary mb-0 small-text"
                                                                style="font-size: 8px;"><?php echo $data1['NAMA']; ?></p>
                                                            <p class="text-sm text-secondary mb-0 small-text"
                                                                style="font-size: 8px;"><?php echo $data1['NOWA']; ?></p>

                                                        </div>

                                                    </div>

                                                </td>


                                                <td class="align-middle text-center text-sm">


                                                    <br>
                                                    <span id="data-status2-<?php echo $data1['IDPEL']; ?>">
                                                        <span class="badge badge-sm bg-gradient-warning">Loading...</span>
                                                    </span><br>
                                                    <span id="data-info2-<?php echo $data1['IDPEL']; ?>" class="text-secondary text-xs font-weight-bold">
                                                        Memuat...
                                                    </span>
                                                    <script>
                                                        startFetching(
                                                            "<?php echo $data1['IDPEL']; ?>",
                                                            "<?php echo $ip; ?>",
                                                            "<?php echo $user; ?>",
                                                            "<?php echo $password; ?>"
                                                        );
                                                    </script>


                                                <?php
                                            }
                                                ?>



                                                </td>


                                                <td>
                                                    <p class="text-xs font-weight-bold mb-0">
                                                        <?php echo $brand ?><?php $PEMILIK = $data1['PEMILIK']; ?></p>
                                                    <p class="text-xs text-secondary mb-0"><?php echo $AREA = $data1['AREA']; ?>
                                                    </p>
                                                    <p class="text-xs text-secondary mb-0"><?php echo $ODP = $data1['ODP']; ?>
                                                    </p>
                                                </td>



                                                <td>
                                                    <?php
                                                    $tanggalPasang = $data1['TANGGALPASANG'];
                                                    $datetimePasang = new DateTime($tanggalPasang);
                                                    $datetimeNow = new DateTime();

                                                    $selisih = $datetimePasang->diff($datetimeNow);

                                                    $keterangan = "";
                                                    if ($selisih->y >= 1) {
                                                        // Sudah lebih dari 1 tahun
                                                        $keterangan = " <span style='color:red;'> (lebih dari " . $selisih->y . " tahun " . $selisih->m . " bulan)</span>";
                                                    } else {
                                                        // Masih kurang dari 1 tahun → tampilkan bulan
                                                        $keterangan = " <span style='color:green;'> (baru " . $selisih->m . " bulan)</span>";
                                                    }
                                                    ?>

                                                    <span class="text-secondary text-xs font-weight-bold">
                                                        Created : <?php echo $tanggalPasang . $keterangan; ?>
                                                    </span>
                                                    <p class="text-xs font-weight mb-0">
                                                        Registed : <?php echo $data1['PAKET']; ?>
                                                    </p>
   <p class="text-xs font-weight mb-0">
    Tipe bayar : 
    <span class="badge 
        <?php 
            echo ($data1['TIPE_BAYAR'] == 'prabayar') ? 'bg-success' : 'bg-primary'; 
        ?>">
        <?php echo $data1['TIPE_BAYAR']; ?>
    </span>
</p>
<p class="text-xs font-weight mb-0">
    Tipe tempo : 
    <span class="badge 
        <?php 
            echo ($data1['TIPE_TEMPO'] == 'Mengikuti Tanggal Tempo') ? 'bg-secondary' : 'bg-info'; 
        ?>">
        <?php echo $data1['TIPE_TEMPO']; ?>
    </span>
</p>

<?php if ($AKSES == "ADMIN") { ?>
                                                    <div class="tiket total" data-id="<?= $data1['IDPEL']; ?>">Loading...<?= $data1['IDPEL']; ?></div>
<?php } ?>


                                                </td>
                                            </tr>
<!-- Modal live chat -->
<div class="modal fade" id="examplelivechat<?php echo $data1['IDPEL']; ?>" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true" data-bs-backdrop="false">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="flex-direction: column !important; display: flex !important;">
            
            <!-- Modal Header -->
            <div class="modal-header bg-primary text-white">
                <h6 class="modal-title fw-bold text-white">
                    <i class="fas fa-comments"></i> Live Chat - <?php echo $data1['NAMA']; ?>
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" onclick="closeAllModals()" aria-label="Close"></button>
            </div>
            
            <div class="modal-body p-0" style="flex: 1;">
                <!-- Iframe -->
                <iframe id="iframeChat<?php echo $data1['IDPEL']; ?>" width="100%" height="650px" style="border: none; display: none;"></iframe>
            </div>
            
            <!-- Modal Footer -->
            <div class="modal-footer bg-light border-top">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" onclick="closeAllModals()">
                    <i class="fas fa-times"></i> Tutup Chat
                </button>
            </div>
           
        </div>
    </div>
</div>
<?php

// Simpan konfigurasi
$config_file = 'config.json';
$config = file_exists($config_file) ? json_decode(file_get_contents($config_file), true) : [];
if($AKSES=='ADMIN')
{
    $adminchat='admin';
}
if($AKSES=='USER')
{
    $adminchat=$data1['PEMILIK'];
}
?>
                                            <script>
                                                document.addEventListener("DOMContentLoaded", function() {
                                                    var modal = document.getElementById("examplelivechat<?php echo $data1['IDPEL']; ?>");
                                                    var iframe = document.getElementById("iframeChat<?php echo  $data1['IDPEL']; ?>");
                                                    var iframeSrcText = document.getElementById("iframeSrcText");

                                                    modal.addEventListener("show.bs.modal", function() {
                                                        iframe.src = "<?php echo $config['URL'] ?>/crm/chat/index.php?admin=<?php echo $adminchat ?>&pelanggan=<?php echo $data1['IDPEL']; ?>&nowa=<?php echo $data1['NOWA']; ?>"
                                                        iframe.style.display = "block";

                                                        // Cek apakah elemen iframeSrcText ada sebelum mengubah textContent
                                                        if (iframeSrcText) {
                                                            iframeSrcText.textContent = "Iframe SRC: " + iframe.src;
                                                        }
                                                    });

                                                    modal.addEventListener("hidden.bs.modal", function() {
                                                        iframe.src = "";
                                                        iframe.style.display = "none";
                                                    });
                                                });
                                            </script>

                                            <!-- Modal EDIT -->
                                            <div class="modal fade" id="exampleModal<?php echo $data1['IDPEL']; ?>"
                                                tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true" data-bs-backdrop="false">
                                                <div class="modal-dialog">
                                                    <div class="modal-content">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title" id="exampleModalLabel">Edit Customer Data</h5>
                                                            <button onclick="closeAllModals()" type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <form method="POST" action="update_customer.php">
                                                                <div class="mb-3">
                                                                    <label for="editIDPEL" class="form-label">Customer ID</label>
                                                                    <input type="text" class="form-control" id="editIDPEL" name="IDPEL" value="<?php echo $data1['IDPEL']; ?>" readonly>
                                                                </div>
                                                                
                                                                <div class="mb-3">
                                                                    <label for="editName" class="form-label">Customer Name</label>
                                                                    <input type="text" class="form-control" id="editName" name="NAMA" value="<?php echo $data1['NAMA']; ?>">
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label for="editProduct" class="form-label">Server Area</label>
                                                                    <input type="text" class="form-control" id="editProduct" name="PEMILIK" value="<?php echo $server = $data1['PEMILIK']; ?>" readonly>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label for="editArea" class="form-label">Area</label>
                                                                    <input type="text" class="form-control" id="editArea" name="AREA" value="<?php echo $area = $data1['AREA']; ?>" readonly>
                                                                </div>

                                                                <div class="mb-3">
                                                                    <label for="server" class="form-label">Packages</label>
                                                                    <select required class="form-control" id="Packages" name="Packages">
                                                                        <option selected disabled value="<?php echo $data1['PAKET']; ?>"><?php echo $data1['PAKET']; ?></option>
                                                                        <?php



                                                                        $query = "SELECT * FROM paket WHERE `PEMILIK` = '$server' AND `AREA` = '$area'";
                                                                        $result = mysqli_query($conn, $query);
                                                                        $editPaketRows = reseller_filter_rows($conn, reseller_collect_rows($result), 'broadband');
                                                                        $editPaketRows = paketVisibilityFilterRows($editPaketRows, $assistant_hidden_paket_broadband, 'PAKET');

                                                                        if (count($editPaketRows) > 0) {
                                                                            echo '<option value="">-- Pilih Packages --</option>';
                                                                            foreach ($editPaketRows as $row) {
                                                                                echo '<option value="' . htmlspecialchars($row['PAKET']) . '">' . htmlspecialchars($row['PAKET']) . '</option>';
                                                                            }
                                                                        }
                                                                        ?>
                                                                    </select>
                                                                </div>



                                                                <div class="mb-3">
                                                                    <label for="editAddress" class="form-label">Address</label>
                                                                    <input type="text" class="form-control" id="editAddress" name="ALAMAT" value="<?php echo $data1['ALAMAT']; ?>">
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label for="editWhatsapp" class="form-label">WhatsApp Number</label>
                                                                    <input type="text" class="form-control" id="editWhatsapp" name="NOWA" value="<?php echo $data1['NOWA']; ?>">
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label for="editCoordinates" class="form-label">Coordinates</label>
                                                                    <input type="text" class="form-control" id="editCoordinates" name="TIKOR" value="<?php echo $data1['TIKOR']; ?>">
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label for="editODP" class="form-label">ODP</label>
                                                                    <input type="text" class="form-control" id="editODP" name="ODP" value="<?php echo $data1['ODP']; ?>">
                                                                </div>
                                                                <button type="submit" class="btn btn-primary">Save Changes</button>
                                                            </form>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" onclick="closeAllModals()" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>




                                            <!-- Modal OVERVIEW -->
                                            <div class="modal fade" id="exampleoverview<?php echo $data1['IDPEL']; ?>"
                                                tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true"
                                                style="font-size:8px; word-wrap: break-word; overflow-wrap: break-word;" data-bs-backdrop="false">

                                                <div class="modal-dialog modal-dialog-centered modal-fullscreen-custom">
                                                    <div class="modal-content">

                                                        <div class="modal-body">

                                                            <style>
                                                                .small-text {
                                                                    font-size: 10px;
                                                                }
                                                            </style>
                                                            <div class="small-text">

                                                                <div class="container">

                                                                    <div class="modal-header">
                                                                        <h5 class="modal-title" id="exampleModalLabel">Overview Customer
                                                                            data</h5>
                                                                        <button onclick="closeAllModals()" type="button" class="btn-close"
                                                                            data-bs-dismiss="modal" aria-label="Close"></button>
                                                                    </div>








                                                                    <div class="row">
                                                                        <div class="col">
                                                                            <!-- Tampilkan Peta -->
                                                                            <label>Customer Location</label>
                                                                            <div id="map<?php echo $data1['IDPEL'] ?>"
                                                                                style="height: 150px;"></div>
                                                                            <label>Customer Traffic</label>
                                                                            <pre
                                                                                id="monitorTrafficResponse<?php echo $data1['IDPEL'] ?>"></pre>
                                                                            <canvas style="height: 100px;"
                                                                                id="trafficChart<?php echo $data1['IDPEL'] ?>"></canvas>
                                                                            <label>Customer Transaction history</label>
                                                                            <iframe width="100%" src="customertransation.php?idpel=<?php echo  $data1['IDPEL']; ?>" title="W3Schools Free Online Web Tutorials"></iframe>
                                                                            <a class="btn btn-secondary" href="https://quenbytekniksejahtera.com/crm/billing/Transaction.php?idpel=<?php echo  $data1['IDPEL']; ?>">See all transation</a>

                                                                        </div>
                                                                        <div class="col">









                                                                            <div class="row">
                                                                                <div class="col">
                                                                                  
                                                                                    <form id="dataForm<?php echo $data1['IDPEL']; ?>" class="dataForm p-0" style="font-size: 8px;">
                                                                                        <div class="mb-0">
                                                                                            <label for="IDPEL" class="form-label">ID pelanggan / User PPPOE username:</label>
                                                                                            <input type="text" name="IDPEL" class="form-control form-control-sm w-100" value="<?php echo $data1['IDPEL']; ?>" readonly>
                                                                                        </div>
 <div class="mb-0">
                                                                                            <label for="IDPEL" class="form-label">User PPPOE password</label>
                                                                                            <input type="text" name="IDPEL" class="form-control form-control-sm w-100" value="<?php echo $data1['PASSWORD']; ?>" readonly>
                                                                                        </div>
                                                                                        <div class="mb-0">
                                                                                            <label for="NAMA" class="form-label">Name:</label>
                                                                                            <input type="text" name="NAMA" class="form-control form-control-sm w-100" value="<?php echo $data1['NAMA']; ?>" readonly>
                                                                                        </div>
<!-- 
                                                                                        <div class="mb-0">
                                                                                            <label for="PEMILIK" class="form-label">username server:</label>
                                                                                            <input type="text" name="PEMILIK" class="form-control form-control-sm w-100" value="<?php echo $data1['PEMILIK']; ?>" readonly>
                                                                                        </div> -->
                                                                                        <div class="mb-0">
                                                                                            <label for="PEMILIK" class="form-label">Server Area:</label>
                                                                                            <input type="text" name="PEMILIK" class="form-control form-control-sm w-100" value="<?php echo $brand; ?>" readonly>
                                                                                        </div>
                                                                                        <div class="mb-0">
                                                                                            <label for="AREA" class="form-label">Area:</label>
                                                                                            <input type="text" name="AREA" class="form-control form-control-sm w-100" value="<?php echo $data1['AREA']; ?>" readonly>
                                                                                        </div>

                                                                                        <div class="mb-0">
                                                                                            <label for="PAKET" class="form-label">Package:</label>
                                                                                            <input type="text" name="PAKET" class="form-control form-control-sm w-100" value="<?php echo $data1['PAKET']; ?>" readonly>
                                                                                        </div>

                                                                                        <div class="mb-0">
                                                                                            <label for="ALAMAT" class="form-label">Address:</label>
                                                                                            <input type="text" name="ALAMAT" class="form-control form-control-sm w-100" value="<?php echo $data1['ALAMAT']; ?>" readonly>
                                                                                        </div>

                                                                                        <div class="mb-0">
                                                                                            <label for="NOWA" class="form-label">WhatsApp:</label>
                                                                                            <input type="text" name="NOWA" class="form-control form-control-sm w-100" value="<?php echo $data1['NOWA']; ?>" readonly>
                                                                                        </div>

                                                                                        <div class="mb-0">
                                                                                            <label for="EMAIL" class="form-label">Email:</label>
                                                                                            <input type="email" name="EMAIL" class="form-control form-control-sm w-100" value="<?php echo $data1['EMAIL']; ?>" readonly>
                                                                                        </div>

                                                                                        <div class="mb-0">
                                                                                            <label for="TIKOR" class="form-label">Coordinates:</label>
                                                                                            <input type="text" name="TIKOR" class="form-control form-control-sm w-100" value="<?php echo $data1['TIKOR']; ?>" readonly>
                                                                                        </div>

                                                                                        <div class="mb-0">
                                                                                            <label for="ODP" class="form-label">ODP:</label>
                                                                                            <input type="text" name="ODP" class="form-control form-control-sm w-100" value="<?php echo $data1['ODP']; ?>" readonly>
                                                                                        </div>
                                                                                        <div class="mb-0">
                                                                                            <label for="sales" class="form-label">Sales:</label>
                                                                                            <input type="text" name="sales" class="form-control form-control-sm w-100" value="<?php echo $data1['sales']; ?>" readonly>
                                                                                        </div>
                                                                                    </form>







                                                                                </div>
                                                                                <div class="col">
<div class="tiket total" data-id="<?= $data1['IDPEL']; ?>">Loading...<?= $data1['IDPEL']; ?></div>
                                                                                    <span class="text-secondary text-xs font-weight-bold"> Created : <?php echo $tanggalPasang . $keterangan; ?>
 <p class="text-xs font-weight mb-0">
    Tipe bayar : 
    <span class="badge 
        <?php 
            echo ($data1['TIPE_BAYAR'] == 'prabayar') ? 'bg-success' : 'bg-primary'; 
        ?>">
        <?php echo $data1['TIPE_BAYAR']; ?>
    </span>
</p>
<p class="text-xs font-weight mb-0">
    Tipe tempo : 
    <span class="badge 
        <?php 
            echo ($data1['TIPE_TEMPO'] == 'Mengikuti Tanggal Tempo') ? 'bg-secondary' : 'bg-info'; 
        ?>">
        <?php echo $data1['TIPE_TEMPO']; ?>
    </span>
</p>
                                                                                        <div class="d-flex flex-column gap-2">
                                                                                            <span style="font-size:15px;" id="data-status-<?php echo $data1['IDPEL']; ?>">
                                                                                                <span class="badge badge-sm bg-gradient-warning">Loading...</span>
                                                                                            </span><br>
                                                                                            <span id="data-info-<?php echo $data1['IDPEL']; ?>" class="text-secondary text-xs font-weight-bold">
                                                                                                Memuat...
                                                                                            </span>
                                                                                            <a href="https://wa.me/ <?php

                                                                                                                    $nohp = $data1['NOWA'];
                                                                                                                    //  bila  no  hp  terdapat  karakter  +  dan  0-9
                                                                                                                    if (!preg_match('/[^+0-9]/', trim($nohp))) {

                                                                                                                        //  cek  karakter  1-3  apakah  +62

                                                                                                                        if (substr(trim($nohp),  0,  2) == '62') {

                                                                                                                            $hp  =  trim($nohp);
                                                                                                                        }

                                                                                                                        //  cek  karakter  1-3  apakah  +62
                                                                                                                        elseif (substr(trim($nohp),  0,  3) == '+62') {

                                                                                                                            $hp  =  '62' . substr(trim($nohp),  1);
                                                                                                                        }


                                                                                                                        //  cek  karakter  1  apakah  0

                                                                                                                        elseif (substr(trim($nohp),  0,  1) == '0') {

                                                                                                                            $hp  =  '62' . substr(trim($nohp),  1);
                                                                                                                        }


                                                                                                                        //  cek  karakter  1-13  apakah  -
                                                                                                                        elseif (substr(trim($nohp),  0,  14) == '-') {

                                                                                                                            $hp  =  '' . substr(trim($nohp),  1);
                                                                                                                        }
                                                                                                                        //  cek  karakter  1-13  apakah  
                                                                                                                        elseif (substr(trim($nohp),  0,  14) == ' ') {

                                                                                                                            $hp  =  '' . substr(trim($nohp),  1);
                                                                                                                        }
                                                                                                                    }
                                                                                                                    echo $hp ?>" target="_blank"
                                                                                                class="btn btn-success btn-sm"><img width="15px"
                                                                                                    height="15px" src="wa.png"><?php echo $hp ?></a>

                                                                                            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#examplelivechat<?php echo $data1['IDPEL']; ?>">
                                                                                                Live Chat
                                                                                            </button>
                                                                                            <a type="button" class="btn btn-secondary btn-sm" href="editcustomerform.php?IDPEL=<?php echo $data1['IDPEL']; ?>">
                                                                                                Edit Data
                                                                                            </a>
                                                                                            <button class="send-invoice btn btn-warning btn-sm" data-form-id="dataForm<?php echo $data1['IDPEL']; ?>">Send Invoice</button>
                                                                                            <!-- Tempat untuk menampilkan respons -->
                                                                                            <div class="response"></div>

                                                                                            <a class="disable-customer btn btn-secondary btn-sm" data-form-id="dataForm<?php echo $data1['IDPEL']; ?>">
                                                                                                Disable
                                                                                            </a>
                                                                                            <!-- Tempat untuk menampilkan respons -->
                                                                                            <div class="response"></div>
                                                                                            <a class="active-customer btn btn-success btn-sm" data-form-id="dataForm<?php echo $data1['IDPEL']; ?>">
                                                                                                Manual Active
                                                                                            </a>

<?php if ($AKSES == "ADMIN") { ?>
                                                                                            <button type="button"
                                                                                                class="btn btn-sm btn-warning"
                                                                                                data-idpel="<?= $data1['IDPEL']; ?>"
                                                                                                data-brand="<?= $data1['PEMILIK']; ?>"
                                                                                                data-nowa="<?= $data1['NOWA']; ?>"
                                                                                                data-nama="<?= $data1['NAMA']; ?>"
                                                                                                data-alamat="<?= $data1['ALAMAT'] ?? ''; ?>"
                                                                                                data-email="<?= $data1['EMAIL'] ?? ''; ?>"
                                                                                                data-tikor="<?= $data1['TIKOR'] ?? ''; ?>"
                                                                                                data-odp="<?= $data1['ODP'] ?? ''; ?>"
                                                                                                onclick="openModal(this)">
                                                                                                Buat Tiket
                                                                                            </button>
<?php } ?>

                                                                                            <!-- Tempat untuk menampilkan respons -->
                                                                                            <div class="response"></div>
                                                                                            <br><br><br><br>
                                                                                            <a class="btn btn-sm btn-danger" href="proses/deletecustomer.php?hapusid=<?php echo $data1['id'] ?>&idpel=<?php echo $data1['IDPEL'] ?>&nowa=<?php echo $data1['NOWA'] ?>&nama=<?php echo $data1['NAMA'] ?>&area=<?php echo $data1['AREA'] ?>&server=<?php echo $data1['PEMILIK'] ?>"
                                                                                                onclick="return myFunctionDeletecustomer()" style="margin:0;">
                                                                                                Dismantle customer / Berhenti langganan
                                                                                            </a>
                                                                                            <script>
                                                                                                function myFunctionDeletecustomer() {
                                                                                                    return confirm("Apakah Anda yakin ingin menghapus data ini?");
                                                                                                }

                                                                                                function myFunction() {
                                                                                                    return confirm("Apakah Anda yakin untuk lanjut ?");
                                                                                                }
                                                                                            </script>





                                                                                        </div>




                                                                                </div><br>
                                                                                <hr>

                                                                            </div>
                                                                        </div>
                                                                    </div>



                                                                </div>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" onclick="closeAllModals()"
                                                                    class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <!-- Leaflet.js Library -->
                                                <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
                                                <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

                                                <script>
                                                    document.addEventListener("DOMContentLoaded", function() {
                                                        var IDPEL = "<?php echo $data1['IDPEL']; ?>";
                                                        var tikor = "<?php echo $data1['TIKOR']; ?>";

                                                        var map = L.map('map' + IDPEL).setView([-6.200000, 106.816666],
                                                            10); // Default Jakarta

                                                        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                                                            attribution: '&copy; OpenStreetMap contributors'
                                                        }).addTo(map);

                                                        if (tikor) {
                                                            var coords = tikor.split(',');
                                                            var lat = parseFloat(coords[0].trim());
                                                            var lng = parseFloat(coords[1].trim());

                                                            if (!isNaN(lat) && !isNaN(lng)) {
                                                                L.marker([lat, lng]).addTo(map)
                                                                    .bindPopup("<b>" + IDPEL + ":</b> " + lat +
                                                                        ", " + lng).openPopup();

                                                                map.setView([lat, lng], 15);
                                                            } else {
                                                                console.error("Koordinat tidak valid untuk IDPEL " + IDPEL +
                                                                    ": " + tikor);
                                                            }
                                                        }

                                                        // Refresh peta saat modal ditampilkan
                                                        var modal = document.getElementById("exampleoverview" + IDPEL);
                                                        modal.addEventListener("shown.bs.modal", function() {
                                                            setTimeout(function() {
                                                                map.invalidateSize();
                                                            }, 300);
                                                        });
                                                    });
                                                </script>













                                                <script>
                                                    function closeAllModals() {
                                                        let modals = document.querySelectorAll('.modal.show'); // Ambil semua modal yang terbuka
                                                        modals.forEach(modal => {
                                                            let modalInstance = bootstrap.Modal.getInstance(modal);
                                                            if (modalInstance) {
                                                                modalInstance.hide(); // Tutup modal
                                                            }
                                                        });

                                                        // Hapus backdrop yang tersisa
                                                        setTimeout(() => {
                                                            document.querySelectorAll('.modal-backdrop').forEach(backdrop => backdrop.remove());
                                                        }, 300);
                                                    }

                                                    function showModal(modalId) {
                                                        let modalElement = document.getElementById(modalId);

                                                        if (!modalElement) {
                                                            console.error("Modal tidak ditemukan!");
                                                            return;
                                                        }

                                                        let modal = new bootstrap.Modal(modalElement);
                                                        modal.show();

                                                        // Tunggu beberapa saat untuk memastikan modal muncul
                                                        setTimeout(() => {
                                                            if (!modalElement.classList.contains('show')) {
                                                                console.warn("Modal gagal ditampilkan! Menghapus backdrop...");
                                                                document.querySelectorAll('.modal-backdrop').forEach(backdrop => backdrop.remove());
                                                            }
                                                        }, 100); // Tunggu 500ms untuk memastikan modal muncul

                                                        // Tambahkan event listener untuk backdrop
                                                        setTimeout(() => {
                                                            let backdrop = document.querySelector('.modal-backdrop');
                                                            if (backdrop) {
                                                                backdrop.addEventListener('click', closeAllModals);
                                                            }
                                                        }, 100);
                                                    }
                                                    // Tambahkan event listener untuk backdrop
                                                    setTimeout(() => {
                                                        let backdrop = document.querySelector('.modal-backdrop');
                                                        if (backdrop) {
                                                            backdrop.addEventListener('click', closeAllModals);
                                                        }
                                                    }, 100);
                                                </script>

                                            <?php
                                        }
                                            ?>
                                </tbody>
                            </table>


                            <div id="popupModal" style="display: none; position: fixed; top: 0; left: 0;
    width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; justify-content: center; align-items: center;">
                                <div style="background: white; padding: 20px; border-radius: 8px; width: 320px; font-size: 14px;">
                                    <h5>Buat tiket</h5>
                                    <form id="popupForm">
                                        <!-- Hidden values -->
                                        <!-- Hidden input yang diisi otomatis dari tombol -->
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
                                                <option value="">Pilih Tipe Pekerjaan</option>
                                                <option value="MAINTENANCE">Maintenance</option>
                                                <option value="MIGRASI">Migrasi</option>
                                                <option value="DISMANTLE">Dismantel</option>
                                            </select>
                                        </div>

                                        <div class="mb-2">
                                            <select name="kendala" class="form-control" required>
                                                <option value="">Pilih Kendala</option>
                                                <option value="Tidak ada pembayaran lanjutan">Tidak ada pembayaran lanjutan</option>
                                                <option value="Pindah rumah">Pindah rumah</option>
                                                <option value="Pindah ke provider lain">Pindah ke provider lain</option>
                                                <option value="Tidak Bisa Connect">Tidak Bisa Connect</option>
                                                <option value="WiFi Lemot">WiFi Lemot</option>
                                                <option value="Sinyal Lemah">Sinyal Lemah</option>
                                                <option value="Tidak Ada Internet">Tidak Ada Internet</option>
                                                <option value="Sering Putus">Sering Putus</option>
                                                <option value="Modem Mati">Modem Mati</option>
                                                <option value="Kabel Lepas / Putus">Kabel Lepas / Putus</option>
                                                <option value="Lampu LOS / Merah">Lampu LOS / Merah</option>
                                                <option value="IP Conflict / Tidak Dapat IP">IP Conflict / Tidak Dapat IP</option>
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
                                function handleFormAction(selector, url, confirmationMessageFn) {
                                    document.querySelectorAll(selector).forEach(button => {
                                        button.addEventListener("click", function() {
                                            const formId = this.getAttribute("data-form-id");
                                            const form = document.getElementById(formId);
                                            if (!form) {
                                                console.error(`Form dengan ID '${formId}' tidak ditemukan.`);
                                                return;
                                            }

                                            const formData = new FormData(form);
                                            const responseBox = this.nextElementSibling;
                                            if (!responseBox) {
                                                console.warn("Elemen untuk menampilkan respons tidak ditemukan.");
                                            }

                                            const confirmationMessage = confirmationMessageFn(formId);
                                            if (confirm(confirmationMessage)) {
                                                fetch(url, {
                                                        method: "POST",
                                                        body: formData
                                                    })
                                                    .then(response => response.json())
                                                    .then(data => {
                                                        if (responseBox) {
                                                            responseBox.innerHTML = `${data.message || "No response message."}`;
                                                        } else {
                                                            console.log(data.message || "No response message.");
                                                        }
                                                    })
                                                    .catch(error => {
                                                        console.error("Error:", error);
                                                        if (responseBox) {
                                                            responseBox.innerHTML = `[${formId}] Error: ` + error.message;
                                                        }
                                                    });
                                            }
                                        });
                                    });
                                }

                                // Panggil fungsi untuk masing-masing tombol
                                // (.send-invoice sekarang ditangani lewat modal #chooseBotSendInvoiceModal, lihat dekat #remoteModal)
                                handleFormAction(
                                    ".disable-customer",
                                    "proses/disablecustomer.php",
                                    (formId) => `Are you sure you want to disable customer [${formId}]?`
                                );

                                handleFormAction(
                                    ".active-customer",
                                    "proses/activecustomer.php",
                                    (formId) => `Are you sure you want to manually activate customer [${formId}]?`
                                );
                            </script>
                            <script>
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
                                }

                                function closeModal() {
                                    document.getElementById("popupModal").style.display = "none";
                                    document.getElementById("popupForm").reset();
                                }

                                document.getElementById("popupForm").addEventListener("submit", function(e) {
                                    e.preventDefault();
                                    const formData = new FormData(this);

                                    fetch("buat_tiket.php", {
                                            method: "POST",
                                            body: formData
                                        })
                                        .then(res => res.json())
                                        .then(data => {
                                            alert(data.message);
                                            if (data.success && currentButton) {
                                                currentButton.disabled = true;
                                                currentButton.innerText = "✔ Tiket Terkirim";
                                            }
                                            closeModal();
                                        })
                                        .catch(() => {
                                            alert("Gagal mengirim tiket.");
                                        });
                                });
                            </script>

<?php if ($AKSES == "ADMIN") { ?>
<script>
document.querySelectorAll(".tiket.total").forEach(el => {
  const dataKey = el.getAttribute("data-id");

  function loadTiket() {
    fetch("getdata/count_tiket.php?data=" + encodeURIComponent(dataKey))
      .then(res => res.json())
      .then(data => {
        let html = "";

        // Dismantel OPEN
        if (parseInt(data.dismantel) > 0) {
          html += `
            <span class="badge bg-gradient-danger fs-7 px-2 py-1">
              ${data.dismantel} tiket dismantle
            </span><br>
          `;
        }

        // Maintenance OPEN
        if (parseInt(data.maintenance) > 0) {
          html += `
            <span class="badge bg-gradient-warning fs-7 px-2 py-1">
              ${data.maintenance} tiket maintenance
            </span><br>
          `;
        }

        // Latest CANCEL team
        if (data.latest_cancel_team) {
          html += `
            <span class="badge bg-gradient-warning fs-7 px-2 py-1">
             laporan terkhir dari :<br> ${data.latest_cancel_team}
            </span>
          `;
        }

        el.innerHTML = html || "<span class='text-muted'>Tidak ada laporan</span>";
      })
      .catch(() => {
        el.innerHTML = "<span class='text-danger'>Gagal load data</span>";
      });
  }

  loadTiket();               // load awal
  setInterval(loadTiket, 10000); // refresh tiap 10 detik
});
</script>
<?php } ?>





                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- JavaScript -->

<!-- Modal -->
<div class="modal fade" id="chooseBotSendInvoiceModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="chooseBotSendInvoiceForm">
        <div class="modal-header bg-warning">
          <h5 class="modal-title">Pilih Bot Pengirim Invoice</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted" style="font-size: 12px;">Pilih bot WhatsApp yang dipakai untuk mengirim notifikasi invoice ke pelanggan ini.</p>
          <label class="form-label fw-bold">Bot WhatsApp</label>
          <select id="chooseBotSendInvoiceSelect" class="form-control" required>
            <option value="">-- Pilih Bot --</option>
            <?php
            $sendInvoiceBotStmt = $conn->prepare("SELECT id, namebot FROM botwa WHERE pemilik = ? ORDER BY namebot ASC");
            if ($sendInvoiceBotStmt) {
                $sendInvoiceBotStmt->bind_param('s', $ceknama);
                $sendInvoiceBotStmt->execute();
                $sendInvoiceBotResult = $sendInvoiceBotStmt->get_result();
                while ($sendInvoiceBotRow = $sendInvoiceBotResult->fetch_assoc()) {
                    echo '<option value="' . (int)$sendInvoiceBotRow['id'] . '">' . htmlspecialchars($sendInvoiceBotRow['namebot']) . '</option>';
                }
                $sendInvoiceBotStmt->close();
            }
            ?>
          </select>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
          <button type="submit" id="chooseBotSendInvoiceSubmitBtn" class="btn btn-warning btn-sm">Kirim Invoice</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(function () {
    let pendingSendInvoiceFormId = null;
    var chooseBotModalEl = document.getElementById('chooseBotSendInvoiceModal');
    var chooseBotModal = chooseBotModalEl && window.bootstrap ? new bootstrap.Modal(chooseBotModalEl) : null;

    document.querySelectorAll(".send-invoice").forEach(function (button) {
        button.addEventListener("click", function () {
            var formId = this.getAttribute("data-form-id");
            if (!document.getElementById(formId)) {
                console.error(`Form dengan ID '${formId}' tidak ditemukan.`);
                return;
            }
            pendingSendInvoiceFormId = formId;
            document.getElementById('chooseBotSendInvoiceForm').reset();
            if (chooseBotModal) {
                chooseBotModal.show();
            }
        });
    });

    var chooseBotForm = document.getElementById('chooseBotSendInvoiceForm');
    if (chooseBotForm) {
        chooseBotForm.addEventListener('submit', function (e) {
            e.preventDefault();

            var form = document.getElementById(pendingSendInvoiceFormId);
            var botSelect = document.getElementById('chooseBotSendInvoiceSelect');
            if (!form || !botSelect.value) {
                alert('Pilih bot pengirim terlebih dahulu.');
                return;
            }

            var formData = new FormData(form);
            formData.append('bot_id', botSelect.value);

            var submitBtn = document.getElementById('chooseBotSendInvoiceSubmitBtn');
            submitBtn.disabled = true;
            submitBtn.textContent = 'Mengirim...';

            fetch('proses/sendinvoice.php', {
                    method: 'POST',
                    body: formData
                })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    console.log('[sendinvoice response]', data);
                    alert(data.message || 'No response message.');
                    if (chooseBotModal) {
                        chooseBotModal.hide();
                    }
                })
                .catch(function (error) {
                    console.error('Error:', error);
                    alert('Gagal mengirim invoice.');
                })
                .finally(function () {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Kirim Invoice';
                });
        });
    }
})();
</script>

<div class="modal fade" id="remoteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-xl" style="max-width:90%; height:80vh;">
    <div class="modal-content d-flex flex-column" style="height:90vh; border:none;">

      <!-- Judul di atas -->
      <div class="bg-dark text-white text-center p-3">
        <h4 class="m-0">Remote ONT</h4>
      </div>

      <!-- Iframe di tengah -->
      <div class="flex-grow-1" style="position:relative;">
        <div id="remoteLoading" style="display:none; position:absolute; inset:0; z-index:2; background:#fff; flex-direction:column; align-items:center; justify-content:center;">
          <div class="spinner-border text-primary" role="status" style="width:3rem; height:3rem;"></div>
          <div class="mt-3 text-muted">Menghubungkan ke Mikrotik, mohon tunggu...</div>
        </div>
        <iframe id="remoteFrame" name="remoteFrame"
          style="width:100%; height:100%; border:none;"></iframe>
      </div>

      <!-- Tombol Tutup di bawah -->
      <div class="bg-light text-center p-3">
        <button type="button" class="btn btn-danger" data-bs-dismiss="modal">TUTUP</button>
      </div>

    </div>
  </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const remoteFrameEl = document.getElementById("remoteFrame");
    if (remoteFrameEl) {
        remoteFrameEl.addEventListener("load", function() {
            const loadingEl = document.getElementById("remoteLoading");
            if (loadingEl) loadingEl.style.display = "none";
        });
    }
});
</script>


                        </div>
                    </div>

       
<?php require 'footer.php'; ?>

<!-- Loading Animation Script -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Simulate loading progress for better UX
    let progress = 0;
    const progressBar = document.querySelector('.progress-bar');
    const loadingText = document.querySelector('.loading-text');
    
    const loadingSteps = [
        'Memuat Data Customer...',
        'Menghubungkan ke Server...',
        'Memproses Informasi...',
        'Hampir Selesai...'
    ];
    
    let stepIndex = 0;
    
    const progressInterval = setInterval(() => {
        progress += Math.random() * 15;
        if (progress >= 100) {
            progress = 100;
            clearInterval(progressInterval);
            
            // Add fade-out animation after loading is complete
            setTimeout(() => {
                const loadingScreen = document.getElementById('loading');
                loadingScreen.classList.add('fade-out');
                
                // Remove loading screen from DOM after animation
                setTimeout(() => {
                    loadingScreen.style.display = 'none';
                }, 800);
            }, 500);
        }
        
        if (progressBar) {
            progressBar.style.width = progress + '%';
        }
        
        // Update loading text based on progress
        if (progress > 25 && stepIndex < loadingSteps.length - 1) {
            stepIndex++;
            if (loadingText) {
                loadingText.textContent = loadingSteps[stepIndex];
            }
        }
    }, 200);
});

// Fallback: Hide loading screen after 5 seconds if something goes wrong
window.addEventListener('load', function() {
    setTimeout(() => {
        const loadingScreen = document.getElementById('loading');
        if (loadingScreen && !loadingScreen.classList.contains('fade-out')) {
            loadingScreen.classList.add('fade-out');
            setTimeout(() => {
                loadingScreen.style.display = 'none';
            }, 800);
        }
    }, 5000);
});
</script>


