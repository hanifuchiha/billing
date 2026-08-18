<?php
// Butuh sesi login: sebelumnya endpoint hapus permanen ini cuma buka koneksi
// DB tanpa cek login/otorisasi sama sekali -- siapapun yang tahu/menebak idpel
// bisa hapus permanen record pelanggan berhenti milik tenant/area manapun.
// Sekarang wajib login + idpel harus milik user/assistant yang sedang login
// (scope AREA utk assistant), sama seperti getdata/get_pelanggan_berhenti.php.
require '../cek-sesi.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST)) {
    $ref = $_SERVER['HTTP_REFERER'] ?? '../tables.php';
    header('Location: ' . $ref);
    exit;
}

header('Content-Type: application/json');

if (!isset($_POST['idpel'])) {
    echo json_encode(['error' => 'ID pelanggan tidak diberikan']);
    exit;
}

$idpel = mysqli_real_escape_string($conn, $_POST['idpel']);

$userServerIds = [];
if ($AKSES === 'ASSISTANT') {
    if (isset($area_list) && trim((string)$area_list) !== '') {
        $queryServerId = mysqli_query($conn, "SELECT PEMILIK FROM server WHERE AREA IN ($area_list)");
        while ($rowServer = mysqli_fetch_assoc($queryServerId)) {
            $userServerIds[] = "'" . $rowServer['PEMILIK'] . "'";
        }
    }
} else {
    $queryServerId = mysqli_query($conn, "SELECT PEMILIK FROM server WHERE user_id = $current_user_id");
    while ($rowServer = mysqli_fetch_assoc($queryServerId)) {
        $userServerIds[] = "'" . $rowServer['PEMILIK'] . "'";
    }
}
$userServerList = count($userServerIds) > 0 ? implode(",", $userServerIds) : "''";

// Check if customer exists AND belongs to current user/assistant scope
$query_check = "SELECT * FROM pelanggan_berhenti WHERE idpel = '$idpel' AND pemilik IN ($userServerList)";
$result_check = mysqli_query($conn, $query_check);

if (!$result_check) {
    echo json_encode(['error' => 'Database error: ' . mysqli_error($conn)]);
    exit;
}

if (mysqli_num_rows($result_check) === 0) {
    echo json_encode(['error' => 'Pelanggan tidak ditemukan']);
    exit;
}

// Delete customer permanently (tetap dibatasi pemilik IN (...) sebagai lapisan kedua)
$query_delete = "DELETE FROM pelanggan_berhenti WHERE idpel = '$idpel' AND pemilik IN ($userServerList)";
$result_delete = mysqli_query($conn, $query_delete);

if (!$result_delete) {
    echo json_encode(['error' => 'Gagal menghapus data: ' . mysqli_error($conn)]);
    exit;
}

echo json_encode(['success' => true, 'message' => 'Data pelanggan berhasil dihapus permanen']);
?>