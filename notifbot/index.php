<?php
/**
 * Dashboard Laporan Cek Tagihan Harian
 * Menampilkan hasil laporan tagihan dengan Bootstrap 5
 */

session_start();
include '../../koneksidb.php';

// Load konfigurasi
$config_file = '../../config.json';
$config      = file_exists($config_file) ? json_decode(file_get_contents($config_file), true) : [];
$URL         = $config['domain'] ?? '';

// Ambil daftar pemilik
$pemilikList = [];
$queryPemilik = mysqli_query($conn, "SELECT DISTINCT PEMILIK FROM server GROUP BY PEMILIK ORDER BY PEMILIK");
while($row = mysqli_fetch_assoc($queryPemilik)) {
    $pemilikList[] = $row['PEMILIK'];
}

// Pilih pemilik (default: yang pertama atau dari parameter)
$pemilikSelected = isset($_GET['pemilik']) ? $_GET['pemilik'] : (isset($pemilikList[0]) ? $pemilikList[0] : '');

// Load history data
$history = [];
$historyFile = "data/history-$pemilikSelected.json";
if (file_exists($historyFile) && $pemilikSelected !== '') {
    $history = json_decode(file_get_contents($historyFile), true) ?: [];
}

// Load reminder config
$reminderFile = "data/reminder-$pemilikSelected.json";
$reminderConfig = [];
if (file_exists($reminderFile) && $pemilikSelected !== '') {
    $reminderConfig = json_decode(file_get_contents($reminderFile), true) ?: [];
}

// Ambil data transaksi untuk periode sekarang
$periodeSekarang = date('F Y');
$sudahBayar = 0;
$belumBayar = 0;
$totalHarga = 0;
$terkumpul = 0;

if ($pemilikSelected !== '') {
    $pemilikEscaped = mysqli_real_escape_string($conn, $pemilikSelected);
    
    // Hitung transaksi berhasil
    $sql1 = "SELECT COUNT(*) as cnt, SUM(HARGA) as total 
             FROM transaksi t 
             INNER JOIN pelanggan p ON t.IDPEL = p.IDPEL 
             WHERE t.STATUS = 'BERHASIL' AND t.HARGA != '0'
             AND p.PEMILIK = '$pemilikEscaped'";
    $result1 = mysqli_fetch_assoc(mysqli_query($conn, $sql1));
    $sudahBayar = $result1['cnt'] ?? 0;
    $terkumpul = $result1['total'] ?? 0;
    
    // Hitung transaksi penagihan
    $sql2 = "SELECT COUNT(*) as cnt, SUM(HARGA) as total 
             FROM transaksi t 
             INNER JOIN pelanggan p ON t.IDPEL = p.IDPEL 
             WHERE t.STATUS = 'PENAGIHAN' AND t.HARGA != '0'
             AND p.PEMILIK = '$pemilikEscaped'";
    $result2 = mysqli_fetch_assoc(mysqli_query($conn, $sql2));
    $belumBayar = $result2['cnt'] ?? 0;
    $totalHarga = ($result2['total'] ?? 0) + ($result1['total'] ?? 0);
}

// Format rupiah
function formatRupiah($nilai) {
    return 'Rp ' . number_format($nilai, 0, ',', '.');
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Cek Tagihan Harian</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        :root {
            --primary: #0066cc;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --light-bg: #f8f9fa;
        }
        
        body {
            background-color: var(--light-bg);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .navbar {
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            background: linear-gradient(135deg, var(--primary) 0%, #0052a3 100%);
        }
        
        .card {
            border: none;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .card-header {
            background-color: var(--light-bg);
            border-bottom: 2px solid #e9ecef;
            padding: 15px;
            font-weight: 600;
        }
        
        .stat-box {
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            color: white;
            font-weight: 600;
        }
        
        .stat-box.success {
            background: linear-gradient(135deg, var(--success) 0%, #20c997 100%);
        }
        
        .stat-box.danger {
            background: linear-gradient(135deg, var(--danger) 0%, #e74c3c 100%);
        }
        
        .stat-box.info {
            background: linear-gradient(135deg, #17a2b8 0%, #0099cc 100%);
        }
        
        .stat-box.primary {
            background: linear-gradient(135deg, var(--primary) 0%, #0052a3 100%);
        }
        
        .stat-number {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 12px;
            opacity: 0.9;
        }
        
        .table {
            font-size: 13px;
            margin-bottom: 0;
        }
        
        .table thead {
            background-color: var(--light-bg);
            font-weight: 600;
            border-bottom: 2px solid #dee2e6;
        }
        
        .table tbody tr {
            border-bottom: 1px solid #e9ecef;
        }
        
        .table tbody tr:hover {
            background-color: #f8f9fa;
        }
        
        .badge-status {
            font-size: 11px;
            padding: 4px 8px;
            font-weight: 500;
        }
        
        .list-item {
            padding: 12px;
            border-left: 4px solid var(--primary);
            background: white;
            margin-bottom: 8px;
            border-radius: 4px;
            font-size: 13px;
        }
        
        .list-item.warning {
            border-left-color: var(--warning);
            background: #fffbf0;
        }
        
        .list-item.danger {
            border-left-color: var(--danger);
            background: #fff5f5;
        }
        
        .list-item.success {
            border-left-color: var(--success);
            background: #f0fdf4;
        }
        
        .section-title {
            font-size: 14px;
            font-weight: 700;
            color: #333;
            margin-top: 20px;
            margin-bottom: 12px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--primary);
        }
        
        .timeline {
            position: relative;
            padding-left: 20px;
        }
        
        .timeline::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #e9ecef;
        }
        
        .timeline-item {
            position: relative;
            padding-bottom: 12px;
            margin-bottom: 12px;
        }
        
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -24px;
            top: 2px;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--primary);
        }
        
        .select-pemilik {
            display: inline-block;
            margin-bottom: 0;
        }
        
        .form-select {
            font-size: 13px;
            padding: 6px 12px;
        }
        
        .btn {
            font-size: 13px;
            padding: 6px 14px;
        }
        
        .container-fluid {
            padding: 20px;
        }
        
        .row > div {
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-dark">
        <div class="container-fluid">
            <span class="navbar-brand mb-0 h5">
                <i class="bi bi-graph-up"></i> Dashboard Cek Tagihan Harian
            </span>
        </div>
    </nav>

    <div class="container-fluid">
        <!-- Filter Pemilik -->
        <div class="row mb-4 mt-3">
            <div class="col-12">
                <div class="d-flex gap-2 align-items-center">
                    <label class="form-label mb-0">Pilih Pemilik:</label>
                    <form method="GET" class="d-flex gap-2">
                        <select name="pemilik" class="form-select form-select-sm" style="width: 200px;" onchange="this.form.submit()">
                            <option value="">-- Pilih Pemilik --</option>
                            <?php foreach ($pemilikList as $p): ?>
                                <option value="<?= htmlspecialchars($p) ?>" <?= $pemilikSelected === $p ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($p) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
            </div>
        </div>

        <?php if ($pemilikSelected !== ''): ?>

        <!-- Statistik Utama -->
        <div class="row">
            <div class="col-md-3 col-sm-6">
                <div class="stat-box success">
                    <div class="stat-number"><?= $sudahBayar ?></div>
                    <div class="stat-label">Sudah Bayar</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-box danger">
                    <div class="stat-number"><?= $belumBayar ?></div>
                    <div class="stat-label">Belum Bayar</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-box info">
                    <div class="stat-number"><?= formatRupiah($terkumpul) ?></div>
                    <div class="stat-label">Terkumpul</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-box primary">
                    <div class="stat-number"><?= formatRupiah($totalHarga) ?></div>
                    <div class="stat-label">Total Tagihan</div>
                </div>
            </div>
        </div>

        <!-- Konfigurasi Reminder -->
        <?php if ($reminderConfig): ?>
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <i class="bi bi-gear"></i> Konfigurasi Reminder
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-6">
                                <small class="text-muted">Jatuh Tempo Hari Ke:</small>
                                <div class="fw-bold"><?= $reminderConfig[0]['jatuh_tempo'] ?? '25' ?></div>
                            </div>
                            <div class="col-6">
                                <small class="text-muted">Hari Sebelum:</small>
                                <div class="fw-bold"><?= $reminderConfig[0]['hari_sebelum'] ?? '3' ?></div>
                            </div>
                            <div class="col-6">
                                <small class="text-muted">Tutup Buku Awal:</small>
                                <div class="fw-bold"><?= $reminderConfig[0]['tanggal_awal_tutup_buku'] ?? '24' ?></div>
                            </div>
                            <div class="col-6">
                                <small class="text-muted">Tutup Buku Akhir:</small>
                                <div class="fw-bold"><?= $reminderConfig[0]['tanggal_akhir_tutup_buku'] ?? '5' ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- History Laporan -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <i class="bi bi-clock-history"></i> Riwayat Laporan
                    </div>
                    <div class="card-body">
                        <?php if (empty($history)): ?>
                            <div class="alert alert-info mb-0">
                                <i class="bi bi-info-circle"></i> Belum ada laporan. Jalankan script cek_tagihan_harian_<?= htmlspecialchars($pemilikSelected) ?>.php terlebih dahulu.
                            </div>
                        <?php else: ?>
                            <div class="timeline">
                                <?php foreach (array_reverse($history) as $item): ?>
                                    <div class="timeline-item">
                                        <div class="text-muted small"><?= htmlspecialchars($item) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- List Pelanggan Belum Bayar -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <i class="bi bi-exclamation-circle text-danger"></i> Pelanggan Belum Bayar
                    </div>
                    <div class="card-body">
                        <?php
                        $pemilikEscaped = mysqli_real_escape_string($conn, $pemilikSelected);
                        $sqlBelumBayar = "SELECT p.IDPEL, p.NAMA, p.NOWA, p.PAKET, p.TEMPO, p.TIPE_TEMPO,
                                                 MAX(t.TANGGALBAYAR) as terakhir_bayar,
                                                 (SELECT SUM(HARGA) FROM transaksi WHERE IDPEL = p.IDPEL AND STATUS = 'PENAGIHAN') as tagihan
                                          FROM pelanggan p
                                          LEFT JOIN transaksi t ON p.IDPEL = t.IDPEL AND t.STATUS = 'BERHASIL'
                                          WHERE p.PEMILIK = '$pemilikEscaped' 
                                          AND p.PAKET NOT LIKE '%FREE%'
                                          AND p.PAKET NOT LIKE '%FASUM%'
                                          GROUP BY p.IDPEL
                                          ORDER BY p.NAMA";
                        $resultBelumBayar = mysqli_query($conn, $sqlBelumBayar);
                        $countBelumBayar = 0;
                        ?>
                        <div style="max-height: 500px; overflow-y: auto;">
                            <?php while ($rb = mysqli_fetch_assoc($resultBelumBayar)): 
                                $isExpiry = !empty($rb['TEMPO']) && $rb['TEMPO'] <= date('Y-m-d');
                                if ($isExpiry) $countBelumBayar++;
                            ?>
                                <?php if ($isExpiry): ?>
                                    <div class="list-item danger">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <div class="fw-bold"><?= htmlspecialchars($rb['IDPEL']) ?> - <?= htmlspecialchars($rb['NAMA']) ?></div>
                                                <small class="text-muted">
                                                    Paket: <?= htmlspecialchars($rb['PAKET']) ?> | 
                                                    WA: <?= htmlspecialchars($rb['NOWA']) ?>
                                                </small>
                                                <br>
                                                <small class="text-muted">
                                                    <?php if(!empty($rb['terakhir_bayar'])): ?>
                                                        Terakhir bayar: <?= date('d-m-Y', strtotime($rb['terakhir_bayar'])) ?>
                                                    <?php else: ?>
                                                        Belum pernah bayar
                                                    <?php endif; ?>
                                                </small>
                                            </div>
                                            <span class="badge badge-status bg-danger"><?= formatRupiah($rb['tagihan'] ?? 0) ?></span>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endwhile; ?>
                            <?php if ($countBelumBayar === 0): ?>
                                <div class="alert alert-success mb-0">
                                    <i class="bi bi-check-circle"></i> Semua pelanggan sudah membayar!
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Perintah Eksekusi -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <i class="bi bi-terminal"></i> Eksekusi Script
                    </div>
                    <div class="card-body">
                        <p class="text-muted small mb-3">Untuk menjalankan cek tagihan otomatis, gunakan command berikut:</p>
                        <div class="bg-dark text-light p-3 rounded" style="font-family: monospace; font-size: 12px; overflow-x: auto;">
                            php /path/to/cek_tagihan_harian_<?= htmlspecialchars($pemilikSelected) ?>.php
                        </div>
                        <p class="text-muted small mt-3 mb-0">
                            <i class="bi bi-info-circle"></i> 
                            Tambahkan ke crontab untuk otomasi:
                            <br>
                            <code>0 7 * * * php /path/to/cek_tagihan_harian_<?= htmlspecialchars($pemilikSelected) ?>.php</code>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <?php else: ?>
        
        <div class="text-center py-5">
            <div class="text-muted">
                <i class="bi bi-inbox" style="font-size: 48px;"></i>
                <p class="mt-3">Pilih pemilik untuk menampilkan laporan</p>
            </div>
        </div>

        <?php endif; ?>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
