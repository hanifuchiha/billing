<?php
// bulk_hapus_transaksi_penagihan.php - Hapus BANYAK baris transaksi sekaligus
// dari checkbox di halaman Transaction.php. SENGAJA dibatasi HANYA baris
// STATUS='PENAGIHAN' (invoice belum bayar) -- tidak boleh menghapus transaksi
// BERHASIL/KONFIRMASI/PERMINTAAN KODE lewat endpoint ini (itu riwayat
// pembayaran sungguhan, bukan invoice yang bisa dibuang begitu saja).
require '../cek-sesi.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$idsRaw = (string)($_POST['ids'] ?? '');
$idsList = array_values(array_unique(array_filter(array_map('intval', explode(',', $idsRaw)), function ($v) {
    return $v > 0;
})));

if (empty($idsList)) {
    echo json_encode(['success' => false, 'message' => 'Tidak ada transaksi dipilih.']);
    exit;
}

if (count($idsList) > 500) {
    echo json_encode(['success' => false, 'message' => 'Maksimal 500 transaksi per proses.']);
    exit;
}

// Scoping kepemilikan (cegah IDOR -- hapus transaksi milik owner lain lewat
// tebak id) -- pola sama dgn Transaction.php sendiri: ASSISTANT dibatasi ke
// $area_list (server di areanya), owner dibatasi ke server miliknya sendiri.
// $current_user_id utk ASSISTANT berisi id akun OWNER (lihat cek-sesi.php),
// jadi TIDAK BOLEH dipakai langsung sbg "user_id = $current_user_id" utk
// ASSISTANT (itu akan balik SEMUA server milik owner, bocor lintas-area).
$userServerIds = [];
if ($AKSES === 'ASSISTANT') {
    if (isset($area_list) && trim((string)$area_list) !== '') {
        $qs = mysqli_query($conn, "SELECT PEMILIK FROM server WHERE AREA IN ($area_list)");
        if ($qs) {
            while ($r = mysqli_fetch_assoc($qs)) {
                $userServerIds[] = "'" . mysqli_real_escape_string($conn, $r['PEMILIK']) . "'";
            }
        }
    }
} else {
    $qs = mysqli_query($conn, "SELECT PEMILIK FROM server WHERE user_id = " . (int)$current_user_id);
    if ($qs) {
        while ($r = mysqli_fetch_assoc($qs)) {
            $userServerIds[] = "'" . mysqli_real_escape_string($conn, $r['PEMILIK']) . "'";
        }
    }
}
$userServerList = count($userServerIds) > 0 ? implode(',', $userServerIds) : "''";

$idsInClause = implode(',', $idsList);

// Cuma ambil id yang: STATUS='PENAGIHAN' DAN PEMILIK ada dalam scope akun ini.
$validIds = [];
$resCheck = mysqli_query($conn, "SELECT id FROM transaksi WHERE id IN ($idsInClause) AND TRIM(UPPER(COALESCE(STATUS,''))) = 'PENAGIHAN' AND PEMILIK IN ($userServerList)");
if ($resCheck) {
    while ($r = mysqli_fetch_assoc($resCheck)) {
        $validIds[] = (int)$r['id'];
    }
}

$skippedCount = count($idsList) - count($validIds);

if (empty($validIds)) {
    echo json_encode(['success' => false, 'message' => 'Tidak ada transaksi PENAGIHAN yang valid/di dalam akses Anda untuk dihapus.']);
    exit;
}

$validIdsInClause = implode(',', $validIds);
mysqli_query($conn, "DELETE FROM transaksi WHERE id IN ($validIdsInClause) AND TRIM(UPPER(COALESCE(STATUS,''))) = 'PENAGIHAN' AND PEMILIK IN ($userServerList)");
$deletedCount = mysqli_affected_rows($conn);

echo json_encode([
    'success' => true,
    'deleted' => $validIds,
    'total_deleted' => $deletedCount,
    'total_failed' => $skippedCount,
]);
