<?php
// api/daily_transaksi.php

header('Content-Type: application/json');
require_once '../koneksibilling.php';
session_start();

// Check DB connection
if (!isset($conn) || $conn === false || $conn->connect_errno) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed', 'details' => isset($conn->connect_error) ? $conn->connect_error : 'No connection object']);
    exit;
}


$username = $_GET['username'] ?? null;
$password = $_GET['password'] ?? null;
$month = isset($_GET['month']) ? intval($_GET['month']) : intval(date('m'));
$year = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));

if (!$username || !$password) {
    echo json_encode(['success' => false, 'error' => 'Parameter kurang (username, password)']);
    exit;
}

// Autentikasi user
$stmt = $conn->prepare("SELECT * FROM user WHERE USERNAME = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'User tidak ditemukan']);
    exit;
}
$row = $result->fetch_assoc();
if (!password_verify($password, $row['PASWORD'])) {
    echo json_encode(['success' => false, 'error' => 'Password salah']);
    exit;
}

// Ambil semua brand/pemilik user
$pemilik_list = [$username];
$stmt2 = $conn->prepare("SELECT DISTINCT PEMILIK FROM server WHERE user_id = ?");
$stmt2->bind_param("i", $row['id']);
$stmt2->execute();
$res2 = $stmt2->get_result();
while ($r = $res2->fetch_assoc()) {
    if (!in_array($r['PEMILIK'], $pemilik_list)) {
        $pemilik_list[] = $r['PEMILIK'];
    }
}

$pemilik_sql = "'" . implode("','", array_map(function($v) use ($conn) { return mysqli_real_escape_string($conn, $v); }, $pemilik_list)) . "'";


// Query transaksi harian detail (seperti versi web)
$data = [];
foreach ($pemilik_list as $pemilik) {
    $pemilik_esc = mysqli_real_escape_string($conn, $pemilik);
    // --- LOGIC SESUAI get_daily_transaction.php ---
    $today = date('Y-m-d');
    $tanggal_bayar_expr = "COALESCE(
        DATE(`TANGGALBAYAR`),
        STR_TO_DATE(`TANGGALBAYAR`, '%Y-%m-%d'),
        STR_TO_DATE(TRIM(SUBSTRING_INDEX(`TANGGALBAYAR`, ',', -1)), '%d %M %Y'),
        STR_TO_DATE(TRIM(SUBSTRING_INDEX(`TANGGALBAYAR`, ',', -1)), '%d %b %Y'),
        STR_TO_DATE(
            TRIM(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                SUBSTRING_INDEX(`TANGGALBAYAR`, ',', -1),
                'Januari', '01'
            ), 'Februari', '02'), 'Maret', '03'), 'April', '04'), 'Mei', '05'), 'Juni', '06'), 'Juli', '07'), 'Agustus', '08'), 'September', '09'), 'Oktober', '10'), 'November', '11'), 'Desember', '12')),
            '%d %m %Y'
        )
    )";
    $sql = "SELECT * FROM transaksi WHERE pemilik='$pemilik_esc' AND (
        UPPER(TRIM(COALESCE(`STATUS`, ''))) = 'KONFIRMASI'
        OR (
            UPPER(TRIM(COALESCE(`STATUS`, ''))) IN ('BERHASIL','PERMINTAAN KODE')
            AND $tanggal_bayar_expr = '$today'
        )
    ) ORDER BY FIELD(LOWER(STATUS), 'konfirmasi', 'berhasil', 'permintaan kode'), id DESC";
    $q = mysqli_query($conn, $sql);
    if (!$q) {
        echo json_encode(['success' => false, 'error' => 'Query transaksi gagal', 'details' => $conn->error, 'sql' => $sql]);
        exit;
    }
    while ($row3 = mysqli_fetch_assoc($q)) {
        $data[] = [
            'id' => $row3['id'] ?? null,
            'status' => strtolower($row3['STATUS'] ?? ''),
            'nama' => $row3['NAMA'] ?? '',
            'harga' => $row3['HARGA'] ?? 0,
            'idpel' => $row3['IDPEL'] ?? '',
            'bukti' => $row3['BUKTI'] ?? '',
            'metode_bayar' => $row3['METODE_BAYAR'] ?? '',
            'tanggal' => $row3['TANGGALBAYAR'] ?? '',
            'pemilik' => $pemilik_esc
        ];
    }
}
echo json_encode(['success' => true, 'data' => $data]);
