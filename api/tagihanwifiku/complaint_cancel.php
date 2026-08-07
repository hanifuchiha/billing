<?php
require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    twk_response(405, ['success' => false, 'message' => 'Method tidak diizinkan.']);
}

$conn = twk_db_connect();
$session = twk_require_auth($conn);
$idpel = (string)$session['idpel'];

$pelanggan = twk_find_customer($conn, $idpel);
if (!$pelanggan) {
    twk_response(404, ['success' => false, 'message' => 'Data pelanggan tidak ditemukan.']);
}

$body = twk_get_json_body();
$ticketId = (int)($body['id'] ?? ($_POST['id'] ?? 0));
if ($ticketId <= 0) {
    twk_response(422, ['success' => false, 'message' => 'ID tiket tidak valid.']);
}

$absensiConn = twk_db_connect_absensi();
$checkJoblist = mysqli_query($absensiConn, "SHOW TABLES LIKE 'joblist'");
$hasJoblist = $checkJoblist && mysqli_num_rows($checkJoblist) > 0;
if (!$hasJoblist) {
    twk_response(500, ['success' => false, 'message' => 'Tabel joblist tidak ditemukan di DB absensi.']);
}

$likeIdpel = '%IDPEL:' . $idpel . '%';
$checkSql = "SELECT id, status FROM joblist WHERE id = ? AND tipe = 'MAINTENANCE' AND data LIKE ? LIMIT 1";
$checkStmt = mysqli_prepare($absensiConn, $checkSql);
if (!$checkStmt) {
    twk_response(500, ['success' => false, 'message' => 'Gagal memvalidasi tiket pengaduan.']);
}

mysqli_stmt_bind_param($checkStmt, 'is', $ticketId, $likeIdpel);
mysqli_stmt_execute($checkStmt);
$result = mysqli_stmt_get_result($checkStmt);
$row = $result ? mysqli_fetch_assoc($result) : null;
mysqli_stmt_close($checkStmt);

if (!$row) {
    twk_response(404, ['success' => false, 'message' => 'Tiket pengaduan tidak ditemukan.']);
}

$status = strtoupper(trim((string)($row['status'] ?? '')));
$cancelableStatuses = ['BARU', 'PENDING', 'MENUNGGU', 'OPEN', 'PROCESS', 'PROSES'];
if (!in_array($status, $cancelableStatuses, true)) {
    twk_response(422, ['success' => false, 'message' => 'Tiket pengaduan ini sudah tidak bisa dibatalkan.']);
}

$deleteStmt = mysqli_prepare($absensiConn, "DELETE FROM joblist WHERE id = ? LIMIT 1");
if (!$deleteStmt) {
    twk_response(500, ['success' => false, 'message' => 'Gagal menyiapkan penghapusan tiket.']);
}

mysqli_stmt_bind_param($deleteStmt, 'i', $ticketId);
$deleted = mysqli_stmt_execute($deleteStmt);
$affected = mysqli_stmt_affected_rows($deleteStmt);
mysqli_stmt_close($deleteStmt);

if (!$deleted || $affected < 1) {
    twk_response(500, ['success' => false, 'message' => 'Gagal menghapus tiket pengaduan.']);
}

twk_response(200, [
    'success' => true,
    'message' => 'Tiket pengaduan berhasil dibatalkan.',
    'data' => [
        'id' => $ticketId,
        'idpel' => $idpel,
        'deleted' => true,
        'table' => 'joblist',
        'database' => 'absensi'
    ]
]);
