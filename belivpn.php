<?php

require 'cek-sesi.php';

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








<body id="content" class="g-sidenav-show  bg-gray-100">
    <?php
    require 'sidebar.php';
    ?>


<div class="container-fluid py-4">
        <div class="row">
            <div class="col-12">
                <div class="card mb-4">
                    <div class="card-header pb-0">
                        <h6>Forwading server VPN</h6>
                        <a type="button" class="btn btn-success" href="user.php">
                            Saldo
                            <?php
                            echo  $saldo = "Rp " . number_format($saldo, 0, ',', '.');
                            ?>
                        </a>
                        <a type="button" class="btn btn-warning" href="tambahsaldo.php">
                            Tambah saldo

                        </a>
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#dataModal">
                            Beli VPN koneksi
                        </button>


                        <!-- Modal -->
                        <div class="modal fade" id="dataModal" tabindex="-1" aria-labelledby="dataModalLabel" aria-hidden="true">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="dataModalLabel">Tambah Data</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <form method="POST" action='proses/belivpn.php'>
                                            <div class="mb-3">
                                                <label class="form-label">Username VPN</label>
                                                <input type="text" name="username" class="form-control" required>
                                            </div>
                                            <div class="mb-3">
                                                <br>
                                                default port MikroTik<br>
                                                - 80 => webfig <br>
                                                - 8291 => winbox<br>
                                                - 8728 => api<br>
                                                - 8729 => api-ssl<br>
                                                <label class="form-label">Port ( sesuaikan port yang akan di remot )</label>

                                                <input type="text" name="port" class="form-control" required>
                                            </div>


                                            <div class="mb-3">
                                                <label class="form-label">Paket bulanan (OPEN ALL PORT)</label>
                                                <select class="form-select" id="exampleFirstName"
                                                    name="paket" required>
                                                    <option disabled>Pilih paket</option>
                                                    <?php
                                                    $sql1 = "SELECT * from `paket` WHERE `PEMILIK` = 'VPNQ'";
                                                    $query1 = mysqli_query($conn, $sql1);
                                                    while ($data1 = mysqli_fetch_array($query1)) {
                                                    ?>
                                                        <option value="<?= $data1['PAKET'] ?>|<?= $data1['HARGA'] ?>"><?= $data1['PAKET'] ?> - <?= "Rp " . number_format($data1['HARGA'], 0, ',', '.'); ?></option>
                                                    <?php
                                                    }
                                                    ?>
                                                </select>
                                            </div>


                                            <div class="mb-3">
                                                <label class="form-label">Service</label>
                                                <select name="service" class="form-select" required>
                                                    <option value="l2tp" selected>L2TP</option>

                                                    <option value="pptp">PPTP</option>

                                                </select>
                                            </div>
                                            <button type="submit" class="btn btn-success">Tambah User</button>
                                            <br>Patikan benar ini tidak dapat di ubah saat sudah di beli
                                        </form>

                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>





<body>
<?php
// Query SQL
$sql = "SELECT * FROM pelanggan WHERE NAMA LIKE '$username' AND NOWA LIKE '$nowa' AND ODP LIKE 'VPNQ'";
$result = $conn->query($sql);
if ($result->num_rows > 0): ?>
  <div class="row">
<?php while ($row = $result->fetch_assoc()):
$id     = htmlspecialchars($row['id']);
$mode   = htmlspecialchars($row['MODE']);
$IPREMOT   = htmlspecialchars($row['ALAMAT']);
$user   = htmlspecialchars($row['IDPEL']);
$pass   = htmlspecialchars($row['IDPEL']);
$paket  = htmlspecialchars($row['PAKET']);
$server_ip = "43.252.237.149";
$vpn_ip  = htmlspecialchars($row['TIKOR']);
$tanggalPasang = htmlspecialchars($row['TANGGALPASANG']);
$date = new DateTime($tanggalPasang);
$date->modify('+30 days');
$expired = $date->format('Y-m-d');
$script = "/interface {$mode}-client add name=\"{$user}\" connect-to=\"{$server_ip}\" user=\"{$user}\" password=\"{$pass}\"  keepalive-timeout=60 use-peer-dns=no add-default-route=no dial-on-demand=no allow=mschap2,mschap1,chap,pap disabled=no comment=\"{$IPREMOT}\"
/ip route add dst-address=$webiplocal gateway={$user} "
;
    ?>
      <div class="col-12 col-md-6 mb-3">
        <div class="card shadow-sm">
          <div class="card-body">
            <h6 class="card-title mb-2">ID: <?= $id ?> | <?= $mode ?></h6>
            <p class="mb-1"><strong>IP VPN:</strong> <?= $vpn_ip ?></p>
            <p class="mb-1"><strong>IP Remot:</strong> <?= $IPREMOT ?></p>
            <p class="mb-1"><strong>User:</strong> <?= $user ?></p>
            <p class="mb-1"><strong>Pass:</strong> <?= $pass ?></p>
            <p class="mb-1"><strong>Paket:</strong> <?= $paket ?></p>
            <p class="mb-1"><strong>Expired:</strong> <?= $expired ?></p>
            <button type="button" class="btn btn-primary btn-sm mt-2" data-bs-toggle="modal" data-bs-target="#modal<?= $id ?>">
              Lihat Script
            </button> 
          </div>
        </div>
      </div>

      <!-- Modal -->
      <div class="modal fade" id="modal<?= $id ?>" tabindex="-1">
        <div class="modal-dialog modal-lg">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Script VPN - ID <?= $id ?></h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <p>COPY DAN PASTE KAN DI MIKROTIK VIA TERMINAL</p>
              <textarea class="form-control" rows="12" readonly><?= $script ?></textarea>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
          </div>
        </div>
      </div>
    <?php endwhile; ?>
  </div>
<?php else: ?>
  <div class="alert alert-warning text-center">Tidak ada data ditemukan.</div>
<?php endif; ?>
 </div>
</div>
</div>
</div>
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
                        <a href="https://quenbytekniksejahtera.com/" class="font-weight-bold" target="_blank">PT QUENBY TEKNIK SEJAHTERA
                            Tim</a>

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