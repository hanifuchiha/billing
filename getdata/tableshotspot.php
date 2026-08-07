<?php require 'header.php'; ?>


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

    .modal-dialog {
        width: 90vw !important;
        /* Lebar hampir penuh layar */
        max-width: none !important;
        /* Hindari batasan default Bootstrap */
        margin: auto;

    }

    .modal-content {
        flex-direction: row !important;
        /* Bagi dua sisi jika ingin konten horizontal */
        display: flex;
        width: 100%;
        max-height: 90vh;
        overflow: hidden;
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










                           
                            
                            <form method="POST">
                                <input type="hidden" name="action" value="filter_area">
                                <div class="mb-3">
                                    <label for="server" class="form-label">Server</label>
                                    <select required class="form-control" id="server" name="server" onchange="loadArea()">
                                                                                                                <option value="">-- Pilih Server --</option>
                                                                                                               <?php
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
                                    <label for="area" class="form-label">AREA</label>
                                    <select  required class="form-control" id="area" name="area" onchange="loadODP()">
                                        <option value="">-- Pilih AREA --</option>
                                    </select>
                                </div>

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

                                <!-- Optional dropdowns jika memang ada -->
                                <select hidden id="odp" name="odp"></select>
                                <select hidden id="packages" name="packages"></select>

                                <br>
                                <button type="submit" class="btn btn-warning">Show server area</button>
                            </form>
<input type="text" id="searchInput" placeholder="🔍 Sortir data..." class="form-control mb-3">

<script>
document.getElementById("searchInput").addEventListener("input", function() {
    var filter = this.value.toLowerCase();
    var table = document.getElementById("dataTable");
    var tr = table.getElementsByTagName("tr");
    for (var i = 0; i < tr.length; i++) {
        var tdList = tr[i].getElementsByTagName("td");
        var found = false;
        for (var j = 0; j < tdList.length; j++) {
            var txtValue = tdList[j].textContent || tdList[j].innerText;
            if (txtValue.toLowerCase().indexOf(filter) > -1) {
                found = true;
                break;
            }
        }
        tr[i].style.display = found ? "" : "none";
    }
});
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
                  <br>Actual : ${data.profile || "N/A"} <br> Down/Up :${data.download || "N/A"}  / ${data.upload || "N/A"}  
                    <br>Uptime ${data.uptime || "N/A"}
                </span>`;





                                        if (statusElement2) statusElement2.innerHTML = '<span class="badge badge-sm bg-gradient-success">Connected</span>';
                                        if (infoElement2) infoElement2.innerHTML = `<span class="text-secondary text-xs font-weight-bold">
                    <br>Actual : ${data.profile || "N/A"} <br> Down/Up :${data.download || "N/A"}  / ${data.upload || "N/A"}  
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
                                    <?php
                                    require('routeros_api.class.php');
                                

   

                                    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                                        $action = isset($_POST['action']) ? $_POST['action'] : '';
                                       $sql1 = "";

                                      if ($action === 'filter_area') {
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

                                        // Eksekusi query jika sudah diisi
                                        if (!empty($sql1)) {
                                          
                                            $query = mysqli_query($conn, $sql1);
                                            if (!$query) {
                                              
                                            }
                                        } elseif (empty($sql1)) {
                                            echo '
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <strong>Peringatan!</strong> Tidak ada parameter pencarian yang valid.xxxx
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>';
                                        }
                                    } else {
                                        // // Default query to show all servers
                                        // $sql1 = "SELECT * FROM `server` WHERE PEMILIK IN ($server_list)";
                                       
                                        // $query = mysqli_query($conn, $sql1);
                                    }


                                        $API = new RouterosAPI();
                                    while ($data = mysqli_fetch_array($query)) {
                                        $server = $data['PEMILIK'];
                                        $area = $data['AREA'];
                                        $ip = $data['IP'];
                                        $password = $data['PASSWORD'];

                                      

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
                                                                <div class="d-flex flex-column justify-content-center text-wrap"
                                                                    style="width: 100px;">
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
                                                            <p class='text-xs font-weight-bold mb-0'>Server: <?= $server ?></p>
                                                            <p class='text-xs text-secondary mb-0'>Area: <?= $area ?></p>
                                                            <p class='text-xs text-secondary mb-0'>Uptime: <?= $uptime ?></p>
                                                            <p class='text-xs text-secondary mb-0'>Bytes In: <?= $bytes_in ?></p>
                                                            <p class='text-xs text-secondary mb-0'>Bytes Out: <?= $bytes_out ?></p>
                                                        </td>
                                                    </tr>

                                                    <!-- Modal OVERVIEW -->
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
                                                                                <script>
                                                                                    startFetching("<?= $username ?>", "<?= $ip ?>", "<?= $server ?>", "<?= $password ?>");
                                                                                </script>
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
                                                                                        <label class="form-label">Server:</label>
                                                                                        <input type="text" class="form-control form-control-sm" value="<?= $server ?>" readonly>
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
                                                }
                                            } else {
                                                echo "<!-- Tidak ada user aktif di $server ($ip) -->";
                                            }

                                            $API->disconnect();
                                        } else {
                                            echo "<!-- Gagal konek ke $ip -->";
                                        }
                                    }
                                    ?>

                                </tbody>
                            </table>



                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- JavaScript -->












                        </div>
                    </div>



<?php require 'footer.php'; ?>

