<?php
require 'cek-sesi.php';

$apiKey = 'p9jLAOOXmpcKIMAbIIKody7EdgmEVo9j2zy6kZj3';
$nominal = $_GET['nominal'];
$submit = $_GET['submit'];
$refcek = $_GET['ref'];


if ($refcek == "") {
    $sql11 = "SELECT * FROM `transaksi` WHERE IDPEL='$username' AND STATUS='PERMINTAAN KODE'";
    $query11 = mysqli_query($conn, $sql11);
    while ($data11 = mysqli_fetch_array($query11)) {
        $refcek = $data11['BUKTI'];
    }
}

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
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
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
<div id="loading">
    <div class="spinner"></div>
</div>







<body id="content" class="g-sidenav-show  bg-gray-100">
    <?php
    require 'sidebar.php';
    ?>


















    <div class="container-fluid py-4">

        <?php
        if ($nominal == "" && $refcek == "") {
        ?>
            <div class="container mt-4">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Pilih Nominal Saldo</h5>
                    </div>
                    <div class="card-body">
                        <form action="tambahsaldo.php" method="get">
                            <input type="hidden" name="nama" value="<?php echo $username; ?>">

                            <div class="mb-3">
                                <label for="inputGroupSelect04" class="form-label">Nominal Saldo</label>
                                <select class="form-select" name="nominal" id="inputGroupSelect04" required>
                                    <option selected disabled>-- Pilih nominal --</option>
                                    <option value="10000">Rp 10.000</option>
                                    <option value="20000">Rp 20.000</option>
                                    <option value="50000">Rp 50.000</option>
                                    <option value="100000">Rp 100.000</option>
                                </select>
                            </div>

                            <button class="btn btn-success w-100" name="submit" value="submit" type="submit">LANJUTKAN</button>
                        </form>
                    </div>
                </div>
            </div>
        <?php
        }
        ?>



        <?php
        if ($nominal != "") {

            echo "<h4 class='mb-4'>Pembelian saldo : <strong>Rp " . number_format($nominal, 0, ",", ".") . "</strong></h4>";

            $apiKey = 'p9jLAOOXmpcKIMAbIIKody7EdgmEVo9j2zy6kZj3';

            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_FRESH_CONNECT  => true,
                CURLOPT_URL            => 'https://tripay.co.id/api/merchant/payment-channel',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER         => false,
                CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey],
                CURLOPT_FAILONERROR    => false,
                CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4
            ));
            $response = curl_exec($curl);
            $error = curl_error($curl);
            curl_close($curl);

            $arr = json_decode($response, true);

            if (isset($arr['data'])) {
                echo '<div class="container"><div class="row">';
                foreach ($arr['data'] as $value) {
                    $namamet = $value['name'];
                    $codmete = $value['code'];
                    $biayaadm = $value['total_fee']['flat'];
                    $icon = $value['icon_url'];

                    if (empty($invoiceref)) {
        ?>
                        <div class="col-md-6 col-lg-4 mb-4">
                            <div class="card shadow-sm h-100">
                                <div class="card-body text-center">
                                    <img src="<?= $icon ?>" class="mb-3" style="max-height:50px;">
                                    <h5 class="card-title"><?= $namamet ?></h5>
                                    <p class="card-text">Biaya Layanan: <strong>Rp <?= number_format($biayaadm, 0, ",", ".") ?></strong></p>
                                    <a class="btn btn-success w-100" href="buatkodepembayaran.php?idselect=<?= $username ?>&totalbayar=<?= $nominal ?>&codmete=<?= $codmete ?>&nama=<?= $username ?>&nohp=<?= $nohpcek ?>&namapaket=TOPUP<?= $nominal ?>&pemilik=<?= $kodepemilik ?>&tgl=<?= $ptanggalcek ?>">Pilih</a>
                                </div>
                            </div>
                        </div>
            <?php
                    }
                }
                echo '</div></div>';
            } else {
                echo "<div class='alert alert-danger'>Gagal mengambil metode pembayaran. Silakan coba lagi nanti.</div>";
            }
        }

        if (!empty($refcek)) {
            $sql11 = "SELECT * FROM `transaksi` WHERE `BUKTI` = '$refcek'";
            $query11 = mysqli_query($conn, $sql11);
            $refdb = $idref = null;

            while ($data22 = mysqli_fetch_array($query11)) {
                $refdb = $data22['BUKTI'];
                $idref = $data22['id'];
            }

            $invoiceref = $refcek;
            echo "<h5 class='my-4'>No INVOICE : <strong>$invoiceref</strong></h5>";

            $payload = ['reference' => $invoiceref];

            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_FRESH_CONNECT  => true,
                CURLOPT_URL            => 'https://tripay.co.id/api/transaction/detail?' . http_build_query($payload),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER         => false,
                CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey],
                CURLOPT_FAILONERROR    => false,
                CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4
            ]);
            $responseref = curl_exec($curl);
            curl_close($curl);

            $arrcekbayar = json_decode($responseref, true);
            $data = $arrcekbayar['data'];

            $namapembayran = $data['payment_name'];
            $kodebayar = $data['pay_code'];
            $statusbayar = $data['status'];
            $namapembayar = $data['customer_name'];
            $idpembayar = $data['merchant_ref'];
            $refpembayar = $data['reference'];
            $exp = $data['expired_time'];
            $cekout = $data['checkout_url'];
            $payurl = $data['pay_url'];
            $barcode = $data['qr_url'];
            $harusbayar = $data['amount'];
            $cekpaidtripay = $data['status'];
            $instructions = $data['instructions'] ?? []; // antisipasi null
            ?>

            <div class="container mt-4">
                <div class="card shadow">
                    <div class="card-body text-center">
                        <h5 class="card-title">TOTAL YANG HARUS DIBAYAR</h5>
                        <h1 class="text-success mb-3">Rp <?= number_format($harusbayar, 0, ",", ".") ?></h1>
                        <p><strong><?= $namapembayran ?></strong></p>
                        <p>KODE PEMBAYARAN:</p>
                        <h4><b><?= $kodebayar ?></b></h4>

                        <?php if (trim($namapembayran) === "Indomaret") { ?>
                            <p class="text-danger">INFOKAN KASIR PEMBAYARAN VIA LINK KITA</p>
                        <?php } ?>

                        <?php if (!empty($payurl)) { ?>
                            <a class="btn btn-success w-100 my-2" href="<?= $payurl ?>">LANJUTKAN BAYAR VIA <?= $namapembayran ?></a>
                        <?php } ?>

                        <?php if (!empty($barcode)) { ?>
                            <img class="my-3" src="<?= $barcode ?>" alt="QR Code" style="max-width: 200px;">
                        <?php } ?>

                        <p class="text-muted">Expired: <?= date('d-m-Y H:i', $exp) ?></p>

                        <?php if (!empty($instructions)) { ?>
                            <!-- Tombol lihat modal -->
                            <button type="button" class="btn btn-warning w-100 text-dark mt-2" data-bs-toggle="modal" data-bs-target="#exampleModal2">
                                LIHAT CARA BAYAR
                            </button>
                        <?php } ?>

                        <a class="btn btn-primary w-100 mt-2" href="tambahsaldo.php?idselect=<?= $idpel ?>">CEK TRANSAKSI</a>
                        <a class="btn btn-danger w-100 mt-2" href="hapuskodepembayaran.php?idselect=<?= $idpel ?>&idref=<?= $invoiceref ?>">BATALKAN</a>
                    </div>
                </div>
            </div>

            <!-- MODAL -->
            <?php if (!empty($instructions)) { ?>
                <div class="modal fade" id="exampleModal2" tabindex="-1" aria-labelledby="exampleModalLabel2" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-scrollable">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">CARA BAYAR VIA <?= $namapembayran ?></h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                            </div>
                            <div class="modal-body text-start">
                                <?php
                                foreach ($instructions as $instruksi) {
                                    echo "<h6>" . $instruksi['title'] . "</h6><ul>";
                                    foreach ($instruksi['steps'] as $step) {
                                        echo "<li>" . $step . "</li>";
                                    }
                                    echo "</ul><hr>";
                                }
                                ?>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">TUTUP</button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php } ?>
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