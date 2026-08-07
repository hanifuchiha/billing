<?php
header('Content-Type: application/json; charset=utf-8');
require 'header_api.php';

$tahun = isset($_GET['tahun']) ? (int)$_GET['tahun'] : date('Y');

$sql = "
SELECT 
    MONTH(tanggal_pembayaran) AS bulan,
    COUNT(*) AS jumlah_transaksi,
    SUM(nominal) AS harga
FROM transaksi
WHERE YEAR(tanggal_pembayaran) = $tahun
    AND status = 'BERHASIL'
GROUP BY MONTH(tanggal_pembayaran)
ORDER BY bulan ASC
";

$result = mysqli_query($conn, $sql);

if (!$result) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    exit;
}

$data = [];
$month_names = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

// Initialize all months with 0
for ($i = 1; $i <= 12; $i++) {
    $data[] = [
        'bulan' => $month_names[$i - 1],
        'jumlah_transaksi' => 0,
        'harga' => 0
    ];
}

// Fill in actual data
while ($row = mysqli_fetch_assoc($result)) {
    $month_index = (int)$row['bulan'] - 1;
    $data[$month_index]['jumlah_transaksi'] = (int)$row['jumlah_transaksi'];
    $data[$month_index]['harga'] = (float)$row['harga'];
}

echo json_encode([
    'success' => true,
    'tahun' => $tahun,
    'data' => $data
]);

mysqli_close($conn);
?>
