<?php require 'header.php';
if ($AKSES == 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('Customer_Hotspot', $akses_menu, true)) {
        echo '<div class="container-fluid py-4"><div class="alert alert-danger">Anda tidak memiliki akses ke menu Customer Hotspot.</div></div>';
        require 'footer.php';
        exit;
    }
}
 ?>


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

    /* Modal dialog: lebar proporsional, tinggi fleksibel */
    .modal-dialog {
        max-width: 600px !important;
        /* Lebar maksimal modal */
        width: 100% !important;
        margin: auto;
    }

    /* Modal content: tinggi fleksibel, lebar proporsional */
    .modal-content {
        display: flex;
        flex-direction: column !important;
        /* Modal vertikal, bukan horizontal */
        width: 100%;
        max-height: 90vh;
        overflow: auto;
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


    .modal-body {
        overflow-y: auto;
        flex: 1;
        padding: 15px;
    }

    .modal-backdrop {
        width: 100vw;
        height: 100vh;
    }
</style>


<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="card mb-4">
                <div class="card-header pb-0">
                    <h6>Costumer Voucher</h6>
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
                    <!-- Button to trigger modal -->
                    <a type="button" class="btn btn-primary" href="vouchergenerator.php">
                        Generate Voucher
                    </a>











                    <form method="POST" class="mb-2">
                        <input type="hidden" name="action" value="filter_area">
                        <div class="mb-3">
                            <label for="server" class="form-label">Server Area</label>
                            <select required class="form-control" id="server" name="server" onchange="setAreaFilter()">
                                <option value="">-- Pilih Server Area --</option>
                                <?php
                                // WAJIB pakai $AKSES/$area_list dari cek-sesi.php (BUKAN
                                // override $current_user_id dari $_SESSION['id'] langsung) --
                                // utk ASSISTANT, $_SESSION['id'] adalah id baris assistant itu
                                // sendiri, sedangkan server terdaftar di bawah user_id akun
                                // OWNER, jadi versi lama ini salah scoping (dropdown kosong
                                // atau berpotensi salah akun).
                                if ($AKSES === 'ASSISTANT') {
                                    $queryServer = (isset($area_list) && trim((string)$area_list) !== '')
                                        ? mysqli_query($conn, "SELECT DISTINCT PEMILIK, BRAND, AREA FROM server WHERE AREA IN ($area_list)")
                                        : mysqli_query($conn, "SELECT DISTINCT PEMILIK, BRAND, AREA FROM server WHERE 1=0");
                                } else {
                                    $queryServer = mysqli_query($conn, "SELECT DISTINCT PEMILIK, BRAND, AREA FROM server WHERE user_id = $current_user_id");
                                }
                                while ($rowServer = mysqli_fetch_assoc($queryServer)) {
                                    $area = htmlspecialchars($rowServer['AREA']);
                                    echo '<option value="'.$rowServer['PEMILIK'].'" data-area="'.$area.'">'.$rowServer['BRAND'].'-'.$area.'</option>';
                                }
                                ?>
                            </select>
                        </div>
                        <input type="hidden" id="area" name="area">
                        <div hidden class="mb-3">
                            <label class="form-label">Area</label>
                            <input type="text" class="form-control" id="area_display" readonly>
                        </div>
                        <br>
                        <button type="submit" class="btn btn-warning">Show server area</button>
                    </form>
                    <script>
                    function setAreaFilter() {
                        let serverSelect = document.getElementById('server');
                        let areaInput = document.getElementById('area');
                        let areaDisplay = document.getElementById('area_display');
                        let selected = serverSelect.options[serverSelect.selectedIndex];
                        let area = selected ? selected.getAttribute('data-area') : '';
                        areaInput.value = area;
                        areaDisplay.value = area;
                    }
                    </script>

<input type="text" id="searchInput" placeholder="🔍 Sortir data..." class="form-control mb-3">

<script>
// Filter baris tabel berdasarkan input pencarian
document.addEventListener('DOMContentLoaded', function() {
    var searchInput = document.getElementById('searchInput');
    if (!searchInput) return;
    searchInput.addEventListener('keyup', function() {
        var filter = searchInput.value.toLowerCase();
        var table = document.getElementById('dataTable');
        if (!table) return;
        var rows = table.getElementsByTagName('tr');
        for (var i = 0; i < rows.length; i++) {
            var rowText = rows[i].innerText.toLowerCase();
            if (rowText.indexOf(filter) > -1) {
                rows[i].style.display = '';
            } else {
                rows[i].style.display = 'none';
            }
        }
    });
});
</script>

                            <script>
                                function loadArea() {
                                    const selectedServer = document.getElementById("server").value;
                                    const areaDropdown = document.getElementById("area");
                                    const odpDropdown = document.getElementById("odp");
                                    const packageDropdown = document.getElementById("packages");

                                    // Reset dropdown isi
                                    if (areaDropdown) areaDropdown.innerHTML = '<option value="">Loading...</option>';
                                    if (odpDropdown) odpDropdown.innerHTML = '<option value="">Loading...</option>';
                                    if (packageDropdown) packageDropdown.innerHTML = '<option value="">Loading...</option>';

                                    if (selectedServer !== "") {
                                        const xhr = new XMLHttpRequest();
                                        xhr.open("GET", "getdata/get_area.php?server=" + encodeURIComponent(selectedServer), true);
                                        xhr.onreadystatechange = function() {
                                            if (xhr.readyState === 4 && xhr.status === 200) {
                                                if (areaDropdown) areaDropdown.innerHTML = xhr.responseText;
                                            }
                                        };
                                        xhr.send();
                                    }
                                }
                            </script>














































                    <script>
                        let trafficHistory = {}; // Menyimpan riwayat data per ID pelanggan

                        function fetchData(idPel, ipServer, userServer, passwordServer) {
                            fetch('getdata/getonlinehotspot.php', {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/x-www-form-urlencoded'
                                    },
                                    body: new URLSearchParams({
                                        ip: ipServer,
                                        idpel: idPel,
                                        us: userServer,
                                        ps: passwordServer
                                    })
                                })
                                .then(response => {
                                    if (!response.ok) {
                                        throw new Error(`HTTP error! Status: ${response.status}`);
                                    }
                                    return response.json();
                                })
                                .then(data => {
                                    console.log(`Data diterima untuk ID ${idPel}:`, data);

                                    let responseElement = document.getElementById(`monitorTrafficResponse${idPel}`);
                                    if (responseElement) {
                                        responseElement.innerHTML = `<pre>${JSON.stringify(data, null, 2)}</pre>`;
                                    }

                                    if (!data || typeof data.download === "undefined" || typeof data.upload === "undefined") {
                                        throw new Error(`Data tidak valid untuk ID ${idPel}: ${JSON.stringify(data)}`);
                                    }

                                    // Konversi data download & upload menjadi angka
                                    let downloadValue = parseInt(data.download.replace(/,/g, ""), 10);
                                    let uploadValue = parseInt(data.upload.replace(/,/g, ""), 10);

                                    if (isNaN(downloadValue) || isNaN(uploadValue)) {
                                        throw new Error(`Gagal mengonversi download/upload untuk ID ${idPel}`);
                                    }

                                    const ctx = document.getElementById(`trafficChart${idPel}`);
                                    if (!ctx) {
                                        console.error(`Canvas untuk ID ${idPel} tidak ditemukan.`);
                                        return;
                                    }

                                    if (!trafficHistory[idPel]) {
                                        trafficHistory[idPel] = {
                                            labels: [],
                                            download: [],
                                            upload: []
                                        };
                                    }

                                    let history = trafficHistory[idPel];

                                    if (history.labels.length >= 10) {
                                        history.labels.shift();
                                        history.download.shift();
                                        history.upload.shift();
                                    }

                                    let timestamp = new Date().toLocaleTimeString();
                                    history.labels.push(timestamp);
                                    history.download.push(downloadValue);
                                    history.upload.push(uploadValue);

                                    if (window[`trafficChartInstance${idPel}`]) {
                                        window[`trafficChartInstance${idPel}`].destroy();
                                    }

                                    window[`trafficChartInstance${idPel}`] = new Chart(ctx.getContext('2d'), {
                                        type: 'line',
                                        data: {
                                            labels: history.labels,
                                            datasets: [{
                                                    label: 'Download (Mbps)',
                                                    data: history.download,
                                                    borderColor: 'blue',
                                                    backgroundColor: 'rgba(0, 0, 255, 0.2)',
                                                    fill: true
                                                },
                                                {
                                                    label: 'Upload (Mbps)',
                                                    data: history.upload,
                                                    borderColor: 'green',
                                                    backgroundColor: 'rgba(0, 128, 0, 0.2)',
                                                    fill: true
                                                }
                                            ]
                                        },
                                        options: {
                                            responsive: true,
                                            scales: {
                                                y: {
                                                    beginAtZero: true
                                                }
                                            }
                                        }
                                    });

                                    let statusElement = document.getElementById(`data-status-${idPel}`);
                                    let infoElement = document.getElementById(`data-info-${idPel}`);
                                    let statusElement2 = document.getElementById(`data-status2-${idPel}`);
                                    let infoElement2 = document.getElementById(`data-info2-${idPel}`);

                                    if (data.status === "Online") {
                                        if (statusElement) statusElement.innerHTML = '<span class="badge badge-sm bg-gradient-success">Connected</span>';
                                        if (infoElement) infoElement.innerHTML = `<span class="text-secondary text-xs font-weight-bold">
                  <br>Actual : ${data.profile || "N/A"} <br> Down/Up :${data.download || "N/A"} / ${data.upload || "N/A"}
                    <br>Uptime ${data.uptime || "N/A"}
                </span>`;




                                        if (statusElement2) statusElement2.innerHTML = '<span class="badge badge-sm bg-gradient-success">Connected</span>';
                                        if (infoElement2) infoElement2.innerHTML = `<span class="text-secondary text-xs font-weight-bold">
                    <br>Actual : ${data.profile || "N/A"} <br> Down/Up :${data.download || "N/A"} / ${data.upload || "N/A"}
                    <br>Uptime ${data.uptime || "N/A"}
                </span>`;




                                    } else {
                                        if (statusElement) statusElement.innerHTML = '<span class="badge badge-sm bg-gradient-danger">Disconnect</span>';
                                        if (infoElement) infoElement.innerHTML = `<span class="text-secondary text-xs font-weight-bold">
                    <br>Actual : ${data.profile || "N/A"}
                    <br>Last Logged Out: ${data.ceklastloggedout || "N/A"}
                    <br>Last Disconnect: ${data.ceklastdisconnect || "N/A"}
                </span>`;
                                        if (statusElement2) statusElement2.innerHTML = '<span class="badge badge-sm bg-gradient-danger">Disconnect</span>';
                                        if (infoElement2) infoElement2.innerHTML = `<span class="text-secondary text-xs font-weight-bold">
                    <br>Actual : ${data.profile || "N/A"}
                    <br>Last Logged Out: ${data.ceklastloggedout || "N/A"}
                    <br>Last Disconnect: ${data.ceklastdisconnect || "N/A"}
                </span>`; // Cek juga ke FreeRADIUS sebagai fallback
                                        cekRadwhoFallback(idPel);
                                    }
                                })
                                .catch(error => {
                                    console.error(`Error pada ID ${idPel}:`, error);
                                    let responseElement = document.getElementById(`monitorTrafficResponse${idPel}`);
                                    if (responseElement) {
                                        responseElement.innerHTML = `<pre style="color: red;">Error: ${error.message}</pre>`;
                                    }
                                });
                        }

                        function startFetching(idPel, ipServer, userServer, passwordServer) {
                            fetchData(idPel, ipServer, userServer, passwordServer); // Ambil data pertama kali
                            setInterval(() => {
                                fetchData(idPel, ipServer, userServer, passwordServer);
                            }, 10000); // Ambil data setiap 10 detik
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

                            <table class="table table-striped table-hover align-items-center mb-0" style="font-size: 10px;">
                                <thead>
                                    <tr>
                                        <th
                                            class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-left">
                                            Username</th>
                                        <th
                                            class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2 text-left">
                                            Packages</th>
                                        <th
                                            class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2 text-left">
                                            Status</th>


                                    <tr>


                                    </tr>
                                </thead>
                                <tbody id="dataTable">
                                    <tr id="lazyLoadSentinelRow" style="height:1px;">
                                        <td colspan="3" style="padding:0;border:0;"></td>
                                    </tr>
                                </tbody>
                            </table>

                            <div id="lazyLoadWrap" class="text-center py-3 d-none">
                                <div id="lazyLoadIndicator" class="spinner-border spinner-border-sm text-primary d-none" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <span id="lazyLoadStatusText" class="text-secondary text-xs"></span>
                            </div>

                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal overview tiap customer aktif dirender di sini (di luar <tbody>, bukan array data mentah) -->
        <div id="hotspotModalsContainer"></div>

        <?php
        require('routeros_api.class.php');

        // Kumpulkan seluruh user aktif dulu (satu kali koneksi per server, tidak bisa dihindari),
        // tapi HTML baris + modal-nya TIDAK langsung di-echo ke halaman. Disimpan sebagai data
        // supaya JS bisa menampilkannya bertahap (chunk demi chunk) saat discroll, dan baru mulai
        // polling live-traffic ("startFetching") untuk baris yang benar-benar sudah ditampilkan.
        $hotspotUsers = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = isset($_POST['action']) ? $_POST['action'] : '';
            $sql1 = "";

            if ($action === 'filter_area') {
                $selected_server = mysqli_real_escape_string($conn, $_POST['server'] ?? '');
                $selected_area   = mysqli_real_escape_string($conn, $_POST['area'] ?? '');

                // WAJIB validasi $selected_area terhadap $area_list milik ASSISTANT
                // di server-side juga -- dropdown-nya sudah difilter benar, tapi
                // tanpa cek ini, assistant tetap bisa kirim POST manual dgn area
                // lain (IDOR) dan tersambung ke MikroTik server yang bukan miliknya.
                $areaAllowedForAssistant = true;
                if ($AKSES === 'ASSISTANT') {
                    $allowedAreaNames = [];
                    if (isset($area_list) && trim((string)$area_list) !== '') {
                        foreach (explode(',', $area_list) as $areaListItem) {
                            $allowedAreaNames[] = trim($areaListItem, " '");
                        }
                    }
                    $areaAllowedForAssistant = in_array($selected_area, $allowedAreaNames, true);
                }

                if (empty($selected_server) || empty($selected_area)) {
                    echo '
                <div class="alert alert-warning alert-dismissible fade show" role="alert">
                    <strong>Peringatan!</strong> Belum tentukan mana yang ditampilkan.
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>';
                } elseif (!$areaAllowedForAssistant) {
                    echo '
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <strong>Akses ditolak.</strong> Area tersebut bukan milik Anda.
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>';
                } else {
                    if ($selected_server !== 'ALL') {
                        $sql1 = "SELECT * FROM `server`
                         WHERE `PEMILIK` = '$selected_server'
                         AND `AREA` = '$selected_area'
                         ";
                    } else {
                        $sql1 = "SELECT * FROM `server`
                         WHERE `AREA` = '$selected_area'
                         ";
                    }
                }
            }

            if (!empty($sql1)) {
                $query = mysqli_query($conn, $sql1);
            } else {
                echo '
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <strong>Peringatan!</strong> Tidak ada parameter pencarian yang valid.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>';
            }

            if (!empty($query)) {
                $API = new RouterosAPI();
                while ($data = mysqli_fetch_array($query)) {
                    $server = $data['PEMILIK'];
                    $area = $data['AREA'];
                    $ip = $data['IP'];
                    $password = $data['PASSWORD'];
                    $brand = $data['BRAND'] ?? '';

                    if ($API->connect($ip, $server, $password)) {
                        $API->write('/ip/hotspot/active/print');
                        $users = $API->read();

                        if ($users && is_array($users)) {
                            foreach ($users as $user) {
                                $username = $user['user'] ?? 'unknown';
                                $uptime = $user['uptime'] ?? 'N/A';
                                $bytes_in = number_format($user['bytes-in'] ?? 0);
                                $bytes_out = number_format($user['bytes-out'] ?? 0);
                                $modal_id = 'modal_' . md5($username . $ip); // Hindari ID duplikat

                                ob_start();
                                ?>
<tr>
    <td style="width: 100px;">
        <div class="d-flex px-2 py-1" data-bs-toggle="modal"
            data-bs-target="#<?= $modal_id ?>"
            style="cursor: pointer;"
            onclick="openMonitorModal(this, '<?= $username ?>', '<?= $ip ?>', '<?= $server ?>', '<?= $password ?>')">
            <div>
                <img src="customer.png" class="avatar avatar-sm me-3" alt="user1">
            </div>
            <div class="d-flex flex-column justify-content-center text-wrap" style="width: 100px;">
                <h6 class="mb-0 text-sm small-text" style="font-size: 8px;"><?= $username ?></h6>
                <p class="text-sm text-secondary mb-0 small-text" style="font-size: 8px;">
                    password : <?= $username ?>
                </p>
            </div>
        </div>
    </td>
    <td>
        <span id="data-status-<?= $username ?>">
            <span class="badge badge-sm bg-gradient-warning">Loading...</span>
        </span><br>
        <span id="data-info-<?= $username ?>" class="text-secondary text-xs font-weight-bold">
            Memuat...
        </span>
    </td>
    <td>
        <p class='text-xs font-weight-bold mb-0'>Owner server: <?= $server ?></p>
        <p class='text-xs text-secondary mb-0'>Area: <?= $area ?></p>
        <p class='text-xs text-secondary mb-0'>Uptime: <?= $uptime ?></p>
        <p class='text-xs text-secondary mb-0'>Bytes In: <?= $bytes_in ?></p>
        <p class='text-xs text-secondary mb-0'>Bytes Out: <?= $bytes_out ?></p>
    </td>
</tr>
                                <?php
                                $rowHtml = ob_get_clean();

                                ob_start();
                                ?>
<div class="modal fade" id="<?= $modal_id ?>" tabindex="-1"
    aria-labelledby="exampleModalLabel" aria-hidden="true"
    style="font-size:8;" data-bs-backdrop="false">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Overview Customer</h5>
                <button onclick="closeAllModals()" type="button" class="btn-close"
                    data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body small-text">
                <div class="container">
                    <div class="row">
                        <div class="col">
                            <label>Customer Traffic</label>
                            <span id="data-status2-<?= $username ?>">
                                <span class="badge badge-sm bg-gradient-warning">Loading...</span>
                            </span><br>
                            <span id="data-info2-<?= $username ?>" class="text-secondary text-xs font-weight-bold">
                                Memuat...
                            </span>
                            <canvas id="trafficChart<?= $username ?>"></canvas>
                        </div>
                        <div class="col">
                            <form class="dataForm p-3 border rounded" style="font-size: 12px;">
                                <div class="mb-1">
                                    <label class="form-label">Username:</label>
                                    <input type="text" class="form-control form-control-sm" value="<?= $username ?>" readonly>
                                </div>
                                <div class="mb-1">
                                    <label class="form-label">Password:</label>
                                    <input type="text" class="form-control form-control-sm" value="<?= $username ?>" readonly>
                                </div>
                                <div class="mb-1">
                                    <label class="form-label">Server Area:</label>
                                    <input type="text" class="form-control form-control-sm" value="<?= $brand ?>" readonly>
                                </div>
                                <div class="mb-1">
                                    <label class="form-label">Area:</label>
                                    <input type="text" class="form-control form-control-sm" value="<?= $area ?>" readonly>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeAllModals()" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>
                                <?php
                                $modalHtml = ob_get_clean();

                                $hotspotUsers[] = [
                                    'username' => $username,
                                    'ip' => $ip,
                                    'server' => $server,
                                    'password' => $password,
                                    'row_html' => $rowHtml,
                                    'modal_html' => $modalHtml,
                                ];
                            }
                        } else {
                            echo "<!-- Tidak ada user aktif di $server ($ip) -->";
                        }

                        $API->disconnect();
                    } else {
                        echo "<!-- Gagal konek ke $ip -->";
                    }
                }
            }
        }
        ?>
        <script>
            var hotspotUsers = <?php echo json_encode($hotspotUsers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
        </script>

        <!-- ============================================================
             LAZY LOAD: tampilkan baris customer hotspot bertahap saat discroll.
             Data sudah lengkap di memori (hotspotUsers, hasil satu kali fetch
             dari MikroTik) -- tidak perlu request tambahan ke server tiap
             scroll. Yang di-lazy-kan cuma: render DOM-nya + kapan polling
             live-traffic (startFetching) tiap baris mulai jalan, supaya
             tidak ratusan interval polling nyala bersamaan begitu halaman
             dibuka.
             ============================================================ -->
        <script>
        (function() {
            var CHUNK_SIZE = 20;
            var revealedCount = 0;
            var isRevealing = false;
            var allRevealed = false;

            var dataTableEl = document.getElementById('dataTable');
            var sentinelRow = document.getElementById('lazyLoadSentinelRow');
            var modalsContainerEl = document.getElementById('hotspotModalsContainer');
            var lazyWrap = document.getElementById('lazyLoadWrap');
            var lazyIndicator = document.getElementById('lazyLoadIndicator');
            var lazyStatusText = document.getElementById('lazyLoadStatusText');

            function updateStatusText() {
                if (!lazyStatusText) return;
                var total = hotspotUsers.length;
                if (total === 0) {
                    lazyStatusText.textContent = '';
                    return;
                }
                if (allRevealed) {
                    lazyStatusText.textContent = 'Semua data sudah dimuat (' + total + ' user aktif).';
                } else {
                    lazyStatusText.textContent = 'Menampilkan ' + revealedCount + ' dari ' + total + ' user aktif...';
                }
            }

            function revealChunk(count) {
                if (allRevealed || isRevealing) return;
                var chunk = hotspotUsers.slice(revealedCount, revealedCount + count);
                if (chunk.length === 0) {
                    allRevealed = true;
                    updateStatusText();
                    if (lazyIndicator) lazyIndicator.classList.add('d-none');
                    return;
                }

                isRevealing = true;
                if (lazyWrap) lazyWrap.classList.remove('d-none');
                if (lazyIndicator) lazyIndicator.classList.remove('d-none');

                var rowsHtml = '';
                var modalsHtml = '';
                for (var i = 0; i < chunk.length; i++) {
                    rowsHtml += chunk[i].row_html;
                    modalsHtml += chunk[i].modal_html;
                }

                // Sisipkan tepat sebelum sentinel row supaya sentinel tetap di posisi paling bawah
                if (sentinelRow) {
                    sentinelRow.insertAdjacentHTML('beforebegin', rowsHtml);
                } else if (dataTableEl) {
                    dataTableEl.insertAdjacentHTML('beforeend', rowsHtml);
                }
                if (modalsContainerEl) {
                    modalsContainerEl.insertAdjacentHTML('beforeend', modalsHtml);
                }

                // Mulai polling live-traffic HANYA untuk baris yang baru ditampilkan
                chunk.forEach(function(u) {
                    if (typeof startFetching === 'function') {
                        startFetching(u.username, u.ip, u.server, u.password);
                    }
                });

                revealedCount += chunk.length;
                allRevealed = revealedCount >= hotspotUsers.length;
                updateStatusText();

                if (allRevealed && lazyIndicator) {
                    lazyIndicator.classList.add('d-none');
                }
                isRevealing = false;
            }

            function revealAllRemaining() {
                while (!allRevealed) {
                    revealChunk(hotspotUsers.length); // sisa semuanya sekaligus
                }
            }

            // Observer: begitu sentinel (baris terakhir tabel) mendekati viewport, muat chunk berikutnya
            if (sentinelRow && 'IntersectionObserver' in window) {
                var observer = new IntersectionObserver(function(entries) {
                    entries.forEach(function(entry) {
                        if (entry.isIntersecting) revealChunk(CHUNK_SIZE);
                    });
                }, { root: null, rootMargin: '0px 0px 300px 0px', threshold: 0 });
                observer.observe(sentinelRow);
            }

            // Fallback scroll listener (kalau IntersectionObserver tidak tersedia / browser lama)
            window.addEventListener('scroll', function() {
                if (allRevealed || isRevealing) return;
                var nearBottom = window.innerHeight + window.scrollY >= document.body.offsetHeight - 300;
                if (nearBottom) revealChunk(CHUNK_SIZE);
            }, { passive: true });

            // Search box: reveal semua data dulu sebelum filter jalan, supaya pencarian tidak
            // melewatkan baris yang belum sempat ditampilkan.
            var searchInputEl = document.getElementById('searchInput');
            if (searchInputEl) {
                searchInputEl.addEventListener('focus', revealAllRemaining, { once: true });
                searchInputEl.addEventListener('keydown', revealAllRemaining, { once: true });
            }

            function initialReveal() {
                revealChunk(CHUNK_SIZE); // tampilkan batch pertama begitu halaman siap
            }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initialReveal);
            } else {
                initialReveal();
            }
        })();
        </script>

        <!-- JavaScript -->












                </div>
            </div>



<?php require 'footer.php'; ?>

