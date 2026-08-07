<?php
require '../koneksidb.php';

header('Content-Type: application/json');

if (!isset($_GET['idpel'])) {
    echo json_encode(['error' => 'ID pelanggan tidak diberikan']);
    exit;
}

$idpel = mysqli_real_escape_string($conn, $_GET['idpel']);

$query = "SELECT * FROM pelanggan_berhenti WHERE idpel = '$idpel'";
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