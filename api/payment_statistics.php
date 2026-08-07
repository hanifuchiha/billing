<?php
header('Content-Type: application/json; charset=utf-8');
require 'header_api.php';

$bulan = isset($_GET['bulan']) ? (int)$_GET['bulan'] : date('m');
$tahun = isset($_GET['tahun']) ? (int)$_GET['tahun'] : date('Y');

// Helper function to parse various date formats
function parse_date($date_str) {
    if (!$date_str) return null;
    
    $date_formats = [
        'Y-m-d',
        'd/m/Y',
        'm/d/Y',
        'Y/m/d'
    ];
    
    foreach ($date_formats as $format) {
        $parsed = DateTime::createFromFormat($format, $date_str);
        if ($parsed) return $parsed->format('Y-m-d');
    }
    
    return null;
}

$month_start = date('Y-m-01', mktime(0, 0, 0, $bulan, 1, $tahun));
$month_end = date('Y-m-t', mktime(0, 0, 0, $bulan, 1, $tahun));

// Get all statistics
$stats = [];

// Total customers
$result = mysqli_query($conn, "SELECT COUNT(*) as count FROM pelanggan WHERE status = 'Aktif'");
$stats['total_pelanggan'] = mysqli_fetch_assoc($result)['count'] ?? 0;

// Overdue customers (lebih dari 30 hari)
$result = mysqli_query($conn, "SELECT COUNT(*) as count FROM pelanggan WHERE status = 'Aktif' AND DATEDIFF(NOW(), tgl_aktivasi) > 30 AND NOT EXISTS (SELECT 1 FROM transaksi WHERE pelanggan.IDPEL = transaksi.idpel AND transaksi.tanggal_pembayaran >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND transaksi.status = 'BERHASIL')");
$stats['pelanggan_overdue'] = mysqli_fetch_assoc($result)['count'] ?? 0;

// Invoices sent this month
$result = mysqli_query($conn, "SELECT COUNT(*) as count FROM transaksi WHERE MONTH(tanggal_pembayaran) = $bulan AND YEAR(tanggal_pembayaran) = $tahun");
$stats['invoice_terkirim'] = mysqli_fetch_assoc($result)['count'] ?? 0;

// Invoices paid this month
$result = mysqli_query($conn, "SELECT COUNT(*) as count FROM transaksi WHERE MONTH(tanggal_pembayaran) = $bulan AND YEAR(tanggal_pembayaran) = $tahun AND status = 'BERHASIL'");
$stats['invoice_terbayar'] = mysqli_fetch_assoc($result)['count'] ?? 0;

// Invoices unpaid this month
$result = mysqli_query($conn, "SELECT COUNT(*) as count FROM transaksi WHERE MONTH(tanggal_pembayaran) = $bulan AND YEAR(tanggal_pembayaran) = $tahun AND status != 'BERHASIL'");
$stats['invoice_belum_bayar'] = mysqli_fetch_assoc($result)['count'] ?? 0;

// Daily revenue
$result = mysqli_query($conn, "SELECT COALESCE(SUM(nominal), 0) as total FROM transaksi WHERE DATE(tanggal_pembayaran) = CURDATE() AND status = 'BERHASIL'");
$stats['revenue_harian'] = (float)(mysqli_fetch_assoc($result)['total'] ?? 0);

// Weekly revenue
$result = mysqli_query($conn, "SELECT COALESCE(SUM(nominal), 0) as total FROM transaksi WHERE WEEK(tanggal_pembayaran) = WEEK(NOW()) AND YEAR(tanggal_pembayaran) = YEAR(NOW()) AND status = 'BERHASIL'");
$stats['revenue_mingguan'] = (float)(mysqli_fetch_assoc($result)['total'] ?? 0);

// Monthly revenue
$result = mysqli_query($conn, "SELECT COALESCE(SUM(nominal), 0) as total FROM transaksi WHERE MONTH(tanggal_pembayaran) = $bulan AND YEAR(tanggal_pembayaran) = $tahun AND status = 'BERHASIL'");
$stats['revenue_bulanan'] = (float)(mysqli_fetch_assoc($result)['total'] ?? 0);

// Yearly revenue
$result = mysqli_query($conn, "SELECT COALESCE(SUM(nominal), 0) as total FROM transaksi WHERE YEAR(tanggal_pembayaran) = $tahun AND status = 'BERHASIL'");
$stats['revenue_tahunan'] = (float)(mysqli_fetch_assoc($result)['total'] ?? 0);

// Daily expenses
$result = mysqli_query($conn, "SELECT COALESCE(SUM(nominal), 0) as total FROM pengeluaran WHERE DATE(tgl_pengeluaran) = CURDATE()");
$stats['expenses_harian'] = (float)(mysqli_fetch_assoc($result)['total'] ?? 0);

// Weekly expenses
$result = mysqli_query($conn, "SELECT COALESCE(SUM(nominal), 0) as total FROM pengeluaran WHERE WEEK(tgl_pengeluaran) = WEEK(NOW()) AND YEAR(tgl_pengeluaran) = YEAR(NOW())");
$stats['expenses_mingguan'] = (float)(mysqli_fetch_assoc($result)['total'] ?? 0);

// Monthly expenses
$result = mysqli_query($conn, "SELECT COALESCE(SUM(nominal), 0) as total FROM pengeluaran WHERE MONTH(tgl_pengeluaran) = $bulan AND YEAR(tgl_pengeluaran) = $tahun");
$stats['expenses_bulanan'] = (float)(mysqli_fetch_assoc($result)['total'] ?? 0);

// Yearly expenses
$result = mysqli_query($conn, "SELECT COALESCE(SUM(nominal), 0) as total FROM pengeluaran WHERE YEAR(tgl_pengeluaran) = $tahun");
$stats['expenses_tahunan'] = (float)(mysqli_fetch_assoc($result)['total'] ?? 0);

// Disconnected subscribers
$result = mysqli_query($conn, "SELECT COUNT(*) as count FROM pelanggan_berhenti WHERE MONTH(tgl_berhenti) = $bulan AND YEAR(tgl_berhenti) = $tahun");
$stats['pelanggan_terputus'] = mysqli_fetch_assoc($result)['count'] ?? 0;

echo json_encode([
    'success' => true,
    'bulan' => $bulan,
    'tahun' => $tahun,
    'stats' => $stats
]);

mysqli_close($conn);
?>
