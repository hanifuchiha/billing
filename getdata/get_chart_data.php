<?php

include '../cek-sesi.php';

$bulan_nama = [
    'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
];

$tahun = isset($_GET['tahun']) ? intval($_GET['tahun']) : (int)date('Y');
$data = [];

$pemilik_kandidat = [];

if (isset($current_user_id) && (int)$current_user_id > 0) {
    $q_server_owner = mysqli_query(
        $conn,
        "SELECT DISTINCT PEMILIK FROM server WHERE user_id = " . (int)$current_user_id . " AND COALESCE(PEMILIK,'') <> ''"
    );

    if ($q_server_owner) {
        while ($r_owner = mysqli_fetch_assoc($q_server_owner)) {
            $pemilik_kandidat[] = trim((string)$r_owner['PEMILIK']);
        }
    }
}

if (!empty($username)) {
    $pemilik_kandidat[] = trim((string)$username);
}
if (!empty($ceknama)) {
    $pemilik_kandidat[] = trim((string)$ceknama);
}
if (!empty($userlogin)) {
    $pemilik_kandidat[] = trim((string)$userlogin);
}

$pemilik_kandidat = array_values(array_unique(array_filter($pemilik_kandidat, function ($v) {
    return $v !== '';
})));

if (count($pemilik_kandidat) === 0) {
    header('Content-Type: application/json');
    echo json_encode([]);
    exit;
}

$pemilik_list_sql = "'" . implode("','", array_map(function ($v) use ($conn) {
    return mysqli_real_escape_string($conn, $v);
}, $pemilik_kandidat)) . "'";

$sql_chart = "
    SELECT COUNT(*) AS jumlah_transaksi, COALESCE(SUM(HARGA), 0) AS total_harga
    FROM transaksi
    WHERE LOWER(TRIM(PENGUNAAN)) = ?
      AND TRIM(UPPER(COALESCE(STATUS, ''))) = 'BERHASIL'
      AND TRIM(PEMILIK) IN ($pemilik_list_sql)
";

$stmt_chart = $conn->prepare($sql_chart);

foreach ($bulan_nama as $bulan) {
    $penggunaan_periode = strtolower(trim($bulan . ' ' . $tahun));
    $jumlah_transaksi = 0;
    $total_harga = 0;

    if ($stmt_chart) {
        $stmt_chart->bind_param('s', $penggunaan_periode);
        $stmt_chart->execute();
        $res_chart = $stmt_chart->get_result();

        if ($res_chart) {
            $row = $res_chart->fetch_assoc();
            $jumlah_transaksi = (int)($row['jumlah_transaksi'] ?? 0);
            $total_harga = (int)($row['total_harga'] ?? 0);
        }
    }

    $data[] = [
        'bulan' => $bulan . ' ' . $tahun,
        'jumlah_transaksi' => $jumlah_transaksi,
        'harga' => $total_harga
    ];
}

if ($stmt_chart) {
    $stmt_chart->close();
}

header('Content-Type: application/json');
echo json_encode($data);
