<?php
require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    twk_response(405, ['success' => false, 'message' => 'Method tidak diizinkan.']);
}

$conn = twk_db_connect();
$session = twk_require_auth($conn);
$idpel = (string)$session['idpel'];

$pelanggan = twk_find_customer($conn, $idpel);
if (!$pelanggan) {
    twk_response(404, ['success' => false, 'message' => 'Data pelanggan tidak ditemukan.']);
}

$nowa = twk_normalize_phone((string)($pelanggan['NOWA'] ?? ''));
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 30;
if ($limit <= 0 || $limit > 100) {
    $limit = 30;
}

$items = [];
$source = 'joblist';

$absensiConn = twk_db_connect_absensi();

$checkJoblist = mysqli_query($absensiConn, "SHOW TABLES LIKE 'joblist'");
$hasJoblist = $checkJoblist && mysqli_num_rows($checkJoblist) > 0;

if ($hasJoblist) {
    $sql = "SELECT id, tgl, status, tipe, nowa, data, project
            FROM joblist
            WHERE tipe = 'MAINTENANCE' AND (nowa = ? OR data LIKE ?)
            ORDER BY id DESC
            LIMIT ?";
    $stmt = mysqli_prepare($absensiConn, $sql);
    if ($stmt) {
        $likeIdpel = '%IDPEL:' . $idpel . '%';
        mysqli_stmt_bind_param($stmt, 'ssi', $nowa, $likeIdpel, $limit);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($res && ($row = mysqli_fetch_assoc($res))) {
            $items[] = [
                'id' => (int)($row['id'] ?? 0),
                'tanggal' => (string)($row['tgl'] ?? ''),
                'status' => (string)($row['status'] ?? ''),
                'tipe' => (string)($row['tipe'] ?? ''),
                'provider' => (string)($row['project'] ?? ''),
                'keluhan' => (string)($row['data'] ?? ''),
                'source' => 'joblist'
            ];
        }
        mysqli_stmt_close($stmt);
    }
}

if (!$hasJoblist) {
    twk_response(500, ['success' => false, 'message' => 'Tabel joblist tidak ditemukan di DB absensi.']);
}

twk_response(200, [
    'success' => true,
    'message' => 'Riwayat pengaduan berhasil diambil.',
    'data' => [
        'items' => $items,
        'count' => count($items),
        'source' => $source,
        'database' => 'absensi'
    ]
]);
