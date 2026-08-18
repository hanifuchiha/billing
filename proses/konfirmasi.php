<?php
require '../cek-sesi.php';

// Toggle "btn_trx_lihat_bukti" (Pengaturan User > Tombol Individual ASSISTANT,
// halaman Transaction) -- halaman ini tidak require header.php, jadi
// $ui_visibility_settings tidak pernah tersedia dan pengecekan harus diulang
// manual server-side, sama persis dengan pola di customertransation.php.
$kfBisaLihatBukti = true;
if ($AKSES === 'ASSISTANT') {
    $kfBuktiSettingsUsername = !empty($asistant_name) ? $asistant_name : $ceknama;
    $kfBuktiSafeUsername = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$kfBuktiSettingsUsername);
    $kfBisaLihatBukti = false; // default ASSISTANT: sembunyikan, kecuali toggle diaktifkan
    if ($kfBuktiSafeUsername !== '') {
        $kfBuktiSettingsFile = __DIR__ . '/../settings/dashboard-cards-' . $kfBuktiSafeUsername . '.json';
        if (is_file($kfBuktiSettingsFile)) {
            $kfBuktiDecoded = json_decode((string)@file_get_contents($kfBuktiSettingsFile), true);
            if (is_array($kfBuktiDecoded) && array_key_exists('btn_trx_lihat_bukti', $kfBuktiDecoded)) {
                $kfBisaLihatBukti = (bool)$kfBuktiDecoded['btn_trx_lihat_bukti'];
            } else {
                $kfBisaLihatBukti = true; // key belum pernah disimpan -> default true
            }
        } else {
            $kfBisaLihatBukti = true; // belum pernah ada file settings -> default true
        }
    }
}

// === Ambil ID dari GET ===
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) {
    echo "<script>alert('ID transaksi tidak valid!');window.location='../Transaction.php';</script>";
    exit;
}

// === Ambil data transaksi ===
$q = mysqli_query($conn, "SELECT * FROM transaksi WHERE id = $id");
if (!$q || mysqli_num_rows($q) == 0) {
    echo "<script>alert('Transaksi tidak ditemukan!');window.location='../Transaction.php';</script>";
    exit;
}
$transaksi = mysqli_fetch_assoc($q);

// Log akses halaman konfirmasi
$history_file = "../notifbot/data/history-$ceknama.json";
$history = [];
if (file_exists($history_file)) {
    $history = json_decode(file_get_contents($history_file), true);
}
if (!is_array($history)) $history = [];
$history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Mengakses halaman konfirmasi pembayaran untuk '$nama'";
file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

// Path server untuk file_exists
$uploadDir = __DIR__ . "/../uploads/";
// Path URL untuk browser
$uploadUrl = "/crm/billing/uploads/";

$extensions = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
$buktiFile = '';
$buktiUrl  = '';
$buktiExistingValue = '';

foreach ($extensions as $ext) {
    $filePath = $uploadDir . $transaksi['id'] . '.' . $ext;
    if (file_exists($filePath)) {
        $buktiFile = $filePath;
        $buktiUrl  = $uploadUrl . $transaksi['id'] . '.' . $ext;
        $buktiExistingValue = $transaksi['id'] . '.' . $ext;
        break;
    }
}
$adaBukti = !empty($buktiUrl);

// Ambil data pelanggan
$nama = $transaksi['NAMA'];
$sql = "SELECT AREA, ALAMAT, EMAIL, NOWA FROM pelanggan WHERE NAMA = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $nama);
$stmt->execute();
$result = $stmt->get_result();
$area = $alamat = $emailPelanggan = $nowa = '-';
if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $area = $row['AREA'];
    $alamat = $row['ALAMAT'];
    $emailPelanggan = $row['EMAIL'];
    $nowa = $row['NOWA'];
}

// Tentukan badge warna
$status = $transaksi['STATUS'];
$badgeClass = ($status === 'BERHASIL') ? 'bg-success' : ($status === 'KONFIRMASI' ? 'bg-warning text-dark' : 'bg-secondary');

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Konfirmasi Pembayaran</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        :root {
            --primary-green: #0E9589;
            --dark-green: #0c7a6f;
            --orange: #F7941D;
            --white: #ffffff;
            --light-gray: #f8f9fa;
            --border-color: #e9ecef;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body { 
            background-color: var(--light-gray);
            color: #333;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }

        .card {
            background-color: var(--white);
            border-radius: 15px;
            box-shadow: 0 10px 24px rgba(14,149,137,0.12);
            border: none;
        }

        .card-header {
            background: linear-gradient(135deg, var(--primary-green) 0%, var(--dark-green) 100%);
            color: var(--white);
            border-radius: 15px 15px 0 0;
            padding: 20px;
            border: none;
        }

        .card-header h4 {
            color: var(--white);
            font-weight: 600;
        }

        .btn-light {
            background-color: var(--white);
            color: var(--primary-green);
            border: none;
            font-weight: 500;
        }

        .btn-light:hover {
            background-color: var(--orange);
            color: var(--white);
        }

        .table {
            margin-bottom: 0;
        }

        .table th {
            background-color: var(--light-gray);
            color: var(--primary-green);
            font-weight: 600;
            border-color: var(--border-color);
            padding: 15px;
        }

        .table td {
            padding: 15px;
            border-color: var(--border-color);
            color: #333;
        }

        .table tbody tr:hover {
            background-color: #e8f6f5;
        }

        .badge {
            padding: 8px 12px;
            font-size: 12px;
            font-weight: 600;
        }

        .bg-success {
            background-color: var(--primary-green) !important;
        }

        .bg-warning {
            background-color: var(--orange) !important;
            color: var(--white) !important;
        }

        .bg-secondary {
            background-color: #6c757d !important;
        }

        .bukti-img {
            width: 100%;
            max-height: 350px;
            object-fit: contain;
            border-radius: 10px;
            border: 2px solid var(--primary-green);
            background-color: var(--white);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .bukti-img:hover {
            transform: scale(1.02);
            box-shadow: 0 8px 20px rgba(14,149,137,0.3);
        }

        .btn-konfirmasi {
            font-size: 1.1rem;
            padding: 15px;
            font-weight: 600;
            background: linear-gradient(135deg, var(--primary-green) 0%, var(--dark-green) 100%);
            border: none;
            border-radius: 10px;
            color: var(--white);
            box-shadow: 0 6px 18px rgba(14,149,137,0.4);
            transition: all 0.3s ease;
        }

        .btn-konfirmasi:hover {
            background: linear-gradient(135deg, var(--dark-green) 0%, var(--primary-green) 100%);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(14,149,137,0.5);
            color: var(--white);
        }

        .btn-outline-primary {
            color: var(--primary-green);
            border-color: var(--primary-green);
            background-color: var(--white);
        }

        .btn-outline-primary:hover {
            background-color: var(--primary-green);
            border-color: var(--primary-green);
            color: var(--white);
        }

        .alert-warning {
            background-color: #fff3cd;
            border-color: var(--orange);
            color: #856404;
        }

        .alert-info {
            background-color: #e8f6f5;
            border-color: var(--primary-green);
            color: var(--dark-green);
        }

        h6.fw-bold {
            color: var(--primary-green);
            font-weight: 700;
        }

        .card-body {
            padding: 25px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }
            
            .card-header {
                padding: 15px;
            }
            
            .card-body {
                padding: 20px;
            }
            
            .table th, 
            .table td {
                padding: 10px 8px;
                font-size: 14px;
            }
            
            .btn-konfirmasi {
                font-size: 1rem;
                padding: 12px;
            }
        }
    </style>
</head>
<body>

<div class="container py-5">
    <div class="card shadow-lg mx-auto" style="max-width:650px;">
        <div class="card-header bg-primary text-white d-flex align-items-center justify-content-between">
            <h4 class="mb-0">Konfirmasi Pembayaran</h4>
            <a href="../dashboard.php" class="btn btn-light btn-sm">
                <i class="bi bi-arrow-left"></i> Kembali
            </a>
        </div>

        <div class="card-body">
            <table class="table table-bordered">
                <tr><th>ID Transaksi</th><td><?= htmlspecialchars($transaksi['id']) ?></td></tr>
                <tr><th>ID Pelanggan</th><td><?= htmlspecialchars($transaksi['IDPEL'] ?? '-') ?></td></tr>
                <tr><th>Nama Pelanggan</th><td><?= htmlspecialchars($transaksi['NAMA'] ?? '-') ?></td></tr>
                <tr><th>Nominal</th><td>Rp <?= number_format($transaksi['HARGA'], 0, ',', '.') ?></td></tr>
                <tr><th>Status</th><td><span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($status) ?></span></td></tr>
                <tr><th>Tanggal</th><td><?= htmlspecialchars($transaksi['TANGGALBAYAR'] ?? '-') ?></td></tr>
                <tr><th>Paket Langganan</th><td><?= htmlspecialchars($transaksi['PAKET'] ?? '-') ?></td></tr>
                <tr><th>Email</th><td><?= htmlspecialchars($emailPelanggan) ?></td></tr>
                <tr><th>Server</th><td><?= htmlspecialchars($transaksi['PEMILIK'] ?? '-') ?></td></tr>
                <tr><th>Area</th><td><?= htmlspecialchars($area) ?></td></tr>
                <tr><th>Alamat</th><td><?= htmlspecialchars($alamat) ?></td></tr>
                <tr><th>Whatsapp</th><td><?= htmlspecialchars($nowa) ?></td></tr>
            </table>

            <h6 class="mt-4 mb-2 fw-bold">Bukti Pembayaran:</h6>
            <?php if ($adaBukti && $kfBisaLihatBukti): ?>
                <div class="text-center mb-3">
                    <?php if (pathinfo($buktiFile, PATHINFO_EXTENSION) === 'pdf'): ?>
                        <a href="<?= htmlspecialchars($buktiUrl) ?>" target="_blank" class="btn btn-outline-primary">
                            <i class="bi bi-file-earmark-pdf"></i> Lihat Bukti (PDF)
                        </a>
                    <?php else: ?>
                        <img src="<?= htmlspecialchars($buktiUrl) ?>" alt="Bukti Pembayaran" class="bukti-img" onclick="window.open(this.src)">
                    <?php endif; ?>
                </div>
            <?php elseif ($adaBukti && !$kfBisaLihatBukti): ?>
                <div class="alert alert-secondary text-center">
                    <i class="bi bi-eye-slash"></i> Anda tidak memiliki akses untuk melihat foto bukti pembayaran.
                </div>
            <?php else: ?>
                <div class="alert alert-warning text-center">
                    <i class="bi bi-exclamation-circle"></i> Bukti pembayaran belum diupload.
                </div>
            <?php endif; ?>

            <?php if ($status === 'KONFIRMASI'): 
                $idpel   = $transaksi['IDPEL'];
                $nama    = $transaksi['NAMA'];
                $paket   = $transaksi['PAKET'];
                $pemilik = $transaksi['PEMILIK'];
            ?>
            <form id="form-konfirmasi" method="post" action="../proses/activecustomer.php">
                <input type="hidden" name="IDPEL" value="<?= $idpel; ?>">
                 <input type="hidden" name="id" value="<?php echo $id; ?>">
                <input type="hidden" name="NAMA" value="<?= $nama; ?>">
                <input type="hidden" name="PAKET" value="<?= $paket; ?>">
                <input type="hidden" name="EMAIL" value="<?= $emailPelanggan; ?>">
                <input type="hidden" name="PEMILIK" value="<?= $pemilik; ?>">
                <input type="hidden" name="AREA" value="<?= $area; ?>">
                <input type="hidden" name="NOWA" value="<?= $nowa; ?>">
                <input type="hidden" name="metode_bayar" value="transfer">
                <input type="hidden" name="bukti_existing" value="<?= htmlspecialchars($buktiExistingValue) ?>">

                <button type="submit" class="btn btn-success w-100 btn-konfirmasi">
                    <i class="bi bi-check-circle"></i> TERIMA PEMBAYARAN
                </button>
            </form>
            <?php else: ?>
                <div class="alert alert-info text-center mt-3">
                    Status transaksi ini sudah <b><?= htmlspecialchars($status) ?></b>.
                </div>
            <?php endif; ?>

        </div>
        <!-- Tambahkan ini di bawah tabel / card-body -->

    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const formKonfirmasi = document.getElementById("form-konfirmasi");
    const badge = document.querySelector("table .badge");
    const debug = document.getElementById("debug-status");

    // Fungsi untuk cek status transaksi
    async function cekStatus() {
        try {
            const res = await fetch("../getdata/get_status_transaksi.php?id=<?= $id ?>");
            const data = await res.json();

            // Tampilkan hasil debug jika ada element debug
            if(debug) debug.textContent = JSON.stringify(data, null, 4);
            console.log("Debug status:", data);

            if(data.success){
                badge.textContent = data.status;

                // Update badge warna
                badge.className = "badge " + (data.status === 'BERHASIL' ? 'bg-success' : 
                                               (data.status === 'KONFIRMASI' ? 'bg-warning text-dark' : 'bg-secondary'));

                // Redirect ke Transaction.php jika status BERHASIL
                if(data.status === 'BERHASIL'){
                    if(formKonfirmasi){
                        formKonfirmasi.style.display = "none";
                    }
                    // Tampilkan pesan sukses dan redirect
                    setTimeout(function(){
                        alert("Pembayaran berhasil dikonfirmasi! Redirecting to Transaction page...");
                        window.location.href = "../Transaction.php";
                    }, 1000);
                }
            }
        } catch(err) {
            console.error("Error cek status:", err);
            if(debug) debug.textContent = "Error: " + err;
        }
    }

    // Jalankan cekStatus setiap 5 detik
    setInterval(cekStatus, 5000);

    // Jalankan sekali saat halaman load
    cekStatus();

    // Event submit tombol konfirmasi (AJAX)
    if(formKonfirmasi){
        formKonfirmasi.addEventListener("submit", async function(e) {
            e.preventDefault();
            const formData = new FormData(formKonfirmasi);

            try {
                const res = await fetch(formKonfirmasi.action, {
                    method: "POST",
                    body: formData
                });
                const data = await res.json();
                
                // Cek jika response berhasil
                if(data.success || data.message.toLowerCase().includes('berhasil') || data.message.toLowerCase().includes('success')) {
                    alert(data.message);
                    // Redirect ke Transaction.php setelah success
                    window.location.href = "../Transaction.php";
                } else {
                    alert(data.message);
                    // Update status setelah submit jika tidak redirect
                    await cekStatus();
                }
            } catch(err) {
                console.error("Error submit konfirmasi:", err);
                if(debug) debug.textContent = "Submit Error: " + err;
                alert("Terjadi kesalahan saat mengirim data.");
            }
        });
    }
});
</script>

</body>
</html>
