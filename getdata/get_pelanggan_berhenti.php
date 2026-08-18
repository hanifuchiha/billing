<?php
// Butuh sesi login: sebelumnya endpoint ini cuma buka koneksi DB tanpa cek
// login/otorisasi sama sekali -- siapapun yang tahu/menebak idpel bisa GET
// data lengkap (termasuk nowa, koordinat GPS, bahkan password) pelanggan
// berhenti milik tenant/area manapun. Sekarang wajib login + idpel harus
// milik user/assistant yang sedang login (scope AREA utk assistant).
require '../cek-sesi.php';

header('Content-Type: application/json');

if (!isset($_GET['idpel'])) {
    echo json_encode(['error' => 'ID pelanggan tidak diberikan']);
    exit;
}

$idpel = mysqli_real_escape_string($conn, $_GET['idpel']);

// Sama seperti daftar_pelanggan_berhenti.php: scope ke pemilik server milik
// user yang login, dan kalau ASSISTANT dibatasi lagi ke AREA yang di-assign.
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

$query = "SELECT * FROM pelanggan_berhenti WHERE idpel = '$idpel' AND pemilik IN ($userServerList)";
$result = mysqli_query($conn, $query);

if (!$result) {
    echo json_encode(['error' => 'Database error: ' . mysqli_error($conn)]);
    exit;
}

if (mysqli_num_rows($result) === 0) {
    echo json_encode(['error' => 'Pelanggan tidak ditemukan']);
    exit;
}

$data = mysqli_fetch_assoc($result);

// Return data in JSON format
echo json_encode([
    'idpel' => $data['idpel'],
    'nama' => $data['nama'],
    'alamat' => $data['alamat'],
    'nowa' => $data['nowa'],
    'email' => $data['email'] ?? '',
    'tikor' => $data['tikor'] ?? '',
    'sales' => $data['sales'] ?? '',
    'pemilik' => $data['pemilik'] ?? '',
    'paket' => $data['paket'] ?? '',
    'harga' => $data['harga'] ?? '',
    'alasan' => $data['alasan'] ?? '',
    'tanggal_berhenti' => $data['tanggal_berhenti'] ?? '',
    'keterangan' => $data['keterangan'] ?? '',
    'provinsi' => $data['provinsi'] ?? '',
    'kabupaten' => $data['kabupaten'] ?? '',
    'kecamatan' => $data['kecamatan'] ?? '',
    'kelurahan' => $data['kelurahan'] ?? '',
    'rw' => $data['rw'] ?? '',
    'rt' => $data['rt'] ?? '',
    'password' => $data['password'] ?? ''
]);
?>