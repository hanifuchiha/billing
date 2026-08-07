<?php
/**
 * =====================================================================
 * SLA Data Display - Detailed View
 * =====================================================================
 * Endpoint untuk melihat data SLA yang tersedia di database
 * dengan formatting dan detail yang lengkap
 */

include '../cek_sesi.php';
require_once '../koneksibilling.php';
require_once '../getdata/sla_discount_helper.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SLA Data Display</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f7fa;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        .header {
            background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);
            color: white;
            padding: 30px;
            border-radius: 8px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .header h1 {
            margin: 0;
            font-size: 28px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .card-title {
            font-size: 18px;
            font-weight: bold;
            color: #0d6efd;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .stat-box {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 6px;
            border-left: 4px solid #0d6efd;
        }
        .stat-label {
            font-size: 12px;
            color: #6c757d;
            margin-bottom: 5px;
        }
        .stat-value {
            font-size: 24px;
            font-weight: bold;
            color: #0d6efd;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        th {
            background-color: #f8f9fa;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #0d6efd;
            border-bottom: 2px solid #dee2e6;
        }
        td {
            padding: 12px;
            border-bottom: 1px solid #dee2e6;
        }
        tr:hover {
            background-color: #f9fafb;
        }
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-success {
            background-color: #d4edda;
            color: #155724;
        }
        .badge-warning {
            background-color: #fff3cd;
            color: #856404;
        }
        .badge-danger {
            background-color: #f8d7da;
            color: #721c24;
        }
        .badge-info {
            background-color: #d1ecf1;
            color: #0c5460;
        }
        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-info {
            background-color: #d1ecf1;
            border: 1px solid #bee5eb;
            color: #0c5460;
        }
        .alert-success {
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        .alert-warning {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
        }
        .search-box {
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
        }
        .search-box input {
            flex: 1;
            padding: 10px;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            font-size: 14px;
        }
        .search-box button {
            padding: 10px 20px;
            background-color: #0d6efd;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
        }
        .search-box button:hover {
            background-color: #0a58ca;
        }
        .sla-value {
            font-weight: bold;
            font-size: 18px;
        }
        .discount-value {
            color: #28a745;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="bi bi-graph-up"></i> SLA Discount Data Display</h1>
            <p style="margin: 5px 0 0 0; opacity: 0.9;">Lihat semua data SLA yang tersedia di database</p>
        </div>

        <?php
        // Check if feature enabled
        $feature_enabled = isSlaDiscountEnabled();
        
        if (!$feature_enabled) {
            echo '<div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> <strong>Warning:</strong> Fitur Diskon SLA belum diaktifkan! Aktifkan di Payment Settings.</div>';
        } else {
            echo '<div class="alert alert-success"><i class="bi bi-check-circle"></i> <strong>Status:</strong> Fitur Diskon SLA sudah AKTIF</div>';
        }
        ?>

        <!-- Statistics Card -->
        <div class="card">
            <div class="card-title">📊 Statistik Database</div>
            
            <?php
            // Count records
            $count_query = "SELECT COUNT(*) as total FROM customer_sla_monthly_snapshots";
            $count_result = $conn->query($count_query);
            $count_row = $count_result->fetch_assoc();
            $total_records = $count_row['total'];
            
            // Count by month
            $by_month = $conn->query("
                SELECT snapshot_month, COUNT(*) as count 
                FROM customer_sla_monthly_snapshots 
                GROUP BY snapshot_month 
                ORDER BY snapshot_month DESC 
                LIMIT 5
            ");
            $months_data = [];
            if ($by_month) {
                while ($row = $by_month->fetch_assoc()) {
                    $months_data[] = $row;
                }
            }
            
            // Current month data
            $last_month = date('Y-m', strtotime('-1 month'));
            $current_month_query = $conn->query("
                SELECT COUNT(*) as count FROM customer_sla_monthly_snapshots 
                WHERE snapshot_month = '$last_month'
            ");
            $current_month_row = $current_month_query->fetch_assoc();
            $current_month_count = $current_month_row['count'];
            ?>

            <div class="stats-grid">
                <div class="stat-box">
                    <div class="stat-label">Total Records</div>
                    <div class="stat-value"><?= $total_records ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Bulan Terakhir (<?= $last_month ?>)</div>
                    <div class="stat-value" style="color: #28a745;"><?= $current_month_count ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Rata-rata SLA</div>
                    <div class="stat-value" style="color: #ff9800;">
                        <?php
                        $avg_query = $conn->query("SELECT AVG(total_sla_percent) as avg_sla FROM customer_sla_monthly_snapshots WHERE snapshot_month = '$last_month'");
                        $avg_row = $avg_query->fetch_assoc();
                        echo number_format($avg_row['avg_sla'] ?? 0, 2) . '%';
                        ?>
                    </div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Rata-rata Diskon</div>
                    <div class="stat-value" style="color: #17a2b8;">
                        <?php
                        $avg_discount = 100 - ($avg_row['avg_sla'] ?? 100);
                        echo number_format(max(0, $avg_discount), 2) . '%';
                        ?>
                    </div>
                </div>
            </div>

            <h3 style="margin-top: 20px; color: #0d6efd;">Data per Bulan</h3>
            <table>
                <thead>
                    <tr>
                        <th>Bulan</th>
                        <th>Jumlah Customer</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($months_data as $month): ?>
                        <tr>
                            <td><strong><?= $month['snapshot_month'] ?></strong></td>
                            <td><span class="badge badge-info"><?= $month['count'] ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Search Customer Card -->
        <div class="card">
            <div class="card-title">🔍 Cari Data Customer</div>
            
            <div class="search-box">
                <input type="text" id="search_idpel" placeholder="Masukkan IDPEL customer..." value="<?= htmlspecialchars($_GET['idpel'] ?? '') ?>">
                <button onclick="searchCustomer()">Cari</button>
            </div>

            <?php
            if (isset($_GET['idpel'])) {
                $search_idpel = trim($_GET['idpel']);
                $sla_data = getSlaDicount($conn, $search_idpel);
                
                if ($sla_data) {
                    echo '<div class="alert alert-success"><i class="bi bi-check-circle"></i> <strong>Data Ditemukan!</strong> SLA data untuk customer ini tersedia.</div>';
                    ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Parameter</th>
                                <th>Nilai</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>IDPEL</strong></td>
                                <td><?= htmlspecialchars($search_idpel) ?></td>
                            </tr>
                            <tr>
                                <td><strong>SLA Bulan Lalu (%)</strong></td>
                                <td><span class="sla-value"><?= number_format($sla_data['sla_percent'], 2) ?>%</span></td>
                            </tr>
                            <tr>
                                <td><strong>Diskon yang Diberikan (%)</strong></td>
                                <td><span class="discount-value"><?= number_format($sla_data['discount_percent'], 2) ?>%</span></td>
                            </tr>
                            <tr>
                                <td><strong>Total Checks</strong></td>
                                <td><?= $sla_data['total_checks'] ?></td>
                            </tr>
                            <tr>
                                <td><strong>Online Checks</strong></td>
                                <td><?= $sla_data['online_checks'] ?></td>
                            </tr>
                            <tr>
                                <td><strong>Bulan Data</strong></td>
                                <td><?= $sla_data['last_month'] ?></td>
                            </tr>
                            <tr>
                                <td><strong>Status</strong></td>
                                <td><span class="badge badge-success"><?= $sla_data['status'] ?></span></td>
                            </tr>
                        </tbody>
                    </table>

                    <h3 style="margin-top: 20px; color: #0d6efd;">Contoh Perhitungan Diskon</h3>
                    <table>
                        <thead>
                            <tr>
                                <th>Tagihan Original</th>
                                <th>Diskon Rp (<?= $sla_data['discount_percent'] ?>%)</th>
                                <th>Total Bayar</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $test_amounts = [100000, 159000, 200000, 500000, 1000000];
                            foreach ($test_amounts as $amount) {
                                $discount = ($amount * $sla_data['discount_percent']) / 100;
                                $total = $amount - $discount;
                                ?>
                                <tr>
                                    <td>Rp<?= number_format($amount, 0, ',', '.') ?></td>
                                    <td style="color: #ff6b6b;">-Rp<?= number_format($discount, 0, ',', '.') ?></td>
                                    <td style="font-weight: bold; color: #28a745;">Rp<?= number_format($total, 0, ',', '.') ?></td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                    <?php
                } else {
                    echo '<div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> <strong>Data Tidak Ditemukan!</strong> Customer ini belum memiliki data SLA dari bulan sebelumnya.</div>';
                }
            } else {
                echo '<div class="alert alert-info"><i class="bi bi-info-circle"></i> Masukkan IDPEL di atas untuk melihat detail data SLA customer</div>';
            }
            ?>
        </div>

        <!-- Sample Data Table -->
        <div class="card">
            <div class="card-title">📋 Sample Data - Bulan Terakhir (<?= $last_month ?>)</div>
            
            <?php
            $sample_query = $conn->query("
                SELECT 
                    idpel,
                    pemilik,
                    total_sla_percent,
                    (100 - total_sla_percent) as discount_percent,
                    total_checks,
                    online_checks,
                    created_at
                FROM customer_sla_monthly_snapshots
                WHERE snapshot_month = '$last_month'
                ORDER BY idpel
                LIMIT 20
            ");

            if ($sample_query && $sample_query->num_rows > 0) {
                ?>
                <table>
                    <thead>
                        <tr>
                            <th>IDPEL</th>
                            <th>Pemilik</th>
                            <th>SLA %</th>
                            <th>Diskon %</th>
                            <th>Checks</th>
                            <th>Online</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $sample_query->fetch_assoc()): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($row['idpel']) ?></strong></td>
                                <td><?= htmlspecialchars($row['pemilik']) ?></td>
                                <td><span class="sla-value"><?= number_format($row['total_sla_percent'], 2) ?>%</span></td>
                                <td><span class="discount-value"><?= number_format($row['discount_percent'], 2) ?>%</span></td>
                                <td><?= $row['total_checks'] ?></td>
                                <td><?= $row['online_checks'] ?></td>
                                <td>
                                    <a href="?idpel=<?= urlencode($row['idpel']) ?>" style="color: #0d6efd; text-decoration: none;">
                                        <i class="bi bi-eye"></i> View
                                    </a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
                <?php
            } else {
                echo '<div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> Belum ada data SLA untuk bulan ini. Jalankan init_sla_database.php untuk membuat sample data.</div>';
            }
            ?>
        </div>

        <!-- Help Section -->
        <div class="card" style="background-color: #f0f7ff; border-left: 4px solid #0d6efd;">
            <div class="card-title"><i class="bi bi-question-circle"></i> Panduan</div>
            
            <h3>Apa itu Diskon SLA?</h3>
            <p>Diskon SLA adalah potongan harga yang diberikan kepada pelanggan berdasarkan performa SLA (Service Level Agreement) mereka di bulan sebelumnya.</p>

            <h3>Cara Kerjanya:</h3>
            <ol>
                <li>Sistem mengambil SLA% dari bulan lalu</li>
                <li>Menghitung diskon: Diskon% = 100% - SLA%</li>
                <li>Menampilkan diskon di portal pembayaran</li>
                <li>Mengurangi total bayar dengan nilai diskon</li>
            </ol>

            <h3>Contoh:</h3>
            <ul>
                <li>SLA Bulan Lalu: 95% → Diskon: 5%</li>
                <li>Tagihan: Rp100.000 → Diskon: Rp5.000 → Total: Rp95.000</li>
            </ul>

            <h3>Setup:</h3>
            <ol>
                <li>Jalankan: <code style="background-color: white; padding: 4px 8px; border-radius: 3px;">/crm/billing/getdata/init_sla_database.php</code></li>
                <li>Aktifkan di Admin: Payment Settings → SLA Discount</li>
                <li>Data akan otomatis tampil di portal pembayaran</li>
            </ol>
        </div>
    </div>

    <script>
        function searchCustomer() {
            const idpel = document.getElementById('search_idpel').value.trim();
            if (idpel) {
                window.location.href = '?idpel=' + encodeURIComponent(idpel);
            }
        }

        // Allow Enter key
        document.getElementById('search_idpel').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                searchCustomer();
            }
        });
    </script>
</body>
</html>
