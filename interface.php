<?php
require 'cek-sesi.php';
require('routeros_api.class.php');
?>


<!DOCTYPE html>
<html lang="en">

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
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- Nepcha Analytics (nepcha.com) -->
    <!-- Tambahkan Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Nepcha is a easy-to-use web analytics. No cookies and fully compliant with GDPR, CCPA and PECR. -->
    <script defer data-site="YOUR_DOMAIN_HERE" src="https://api.nepcha.com/js/nepcha-analytics.js"></script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <!-- Tampilkan Peta -->
    <style>
        #map {
            height: 20px;
            width: 10%;
        }
    </style>
</head>

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
</style>



<?php
require 'sidebar.php';
?>

<body>



<div class="container my-4">
<?php
$API = new RouterosAPI();

// Ambil daftar server dulu
$sql = "SELECT * FROM server WHERE `pemilik` IN ($server_list)";
$query = mysqli_query($conn, $sql);
$servers = [];
while ($data = mysqli_fetch_array($query)) {
    $servers[] = $data;
}

// Tangkap server yang dipilih
$selected_ip = $_POST['selected_server'] ?? '';
?>

<!-- Form pilih server -->
<form method="POST" class="mb-4">
    <div class="input-group">
        <select name="selected_server" class="form-select">
            <option value="">-- Pilih Server --</option>
            <?php foreach($servers as $srv): ?>
                <option value="<?= $srv['IP'] ?>" <?= ($srv['IP'] == $selected_ip) ? 'selected' : '' ?>>
                    <?= $srv['IP'] ?> (<?= htmlspecialchars($srv['AREA']) ?>)
                </option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-primary" type="submit">Tampilkan</button>
    </div>
</form>

<?php
if($selected_ip){
    // Ambil data server yang dipilih
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
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
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

                <form method="POST" action="aktifkan_server.php">
                    <input type="hidden" name="server_ip" value="<?= $ip ?>">
                    <table class="table table-bordered table-striped mb-0">
                        <thead class="table-secondary">
                            <tr>
                                <th>Interface</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>PPPoE Server</th>
                                <th>Hotspot Server</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach($physicalInterfaces as $intf):
                            $name = $intf['name'];
                            $type = $intf['type'];
                            $running = $intf['running'] ?? 0;

                            $pppoeServers = $API->comm("/interface/pppoe-server/print", ["?interface"=>$name]);
                            $pppoeStatus = (!empty($pppoeServers)) ? "checked" : "";

                            $hotspotServers = $API->comm("/ip/hotspot/print", ["?interface"=>$name]);
                            $hotspotStatus = (!empty($hotspotServers)) ? "checked" : "";
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
    </div>
    </main>

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

</html>