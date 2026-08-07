<?php
// api/customer_detail.php
header('Content-Type: application/json');
require_once '../koneksibilling.php';

$idpel = isset($_GET['idpel']) ? trim($_GET['idpel']) : '';
if (!$idpel) {
    echo json_encode(['success' => false, 'error' => 'Parameter kurang']);
    exit;
}

$sql = "SELECT * FROM pelanggan WHERE IDPEL = '" . $conn->real_escape_string($idpel) . "' LIMIT 1";
$res = $conn->query($sql);
if (!$res || $res->num_rows == 0) {
    echo json_encode(['success' => false, 'error' => 'Data pelanggan tidak ditemukan']);
    exit;
}
$row = $res->fetch_assoc();

// Data utama pelanggan


$data = [
    'id' => $row['id'] ?? '',
    'PASSWORD' => $row['PASSWORD'] ?? '',
    'IDPEL' => $row['IDPEL'] ?? '',
    'NAMA' => $row['NAMA'] ?? '',
    'TIPE_BAYAR' => $row['TIPE_BAYAR'] ?? '',
    'TIPE_TEMPO' => $row['TIPE_TEMPO'] ?? '',
    'TANGGAL_MONTHVERSARY' => $row['TANGGAL_MONTHVERSARY'] ?? '',
    'PAKET' => $row['PAKET'] ?? '',
    'HARGA' => $row['HARGA'] ?? '',
    'ALAMAT' => $row['ALAMAT'] ?? '',
    'NOWA' => $row['NOWA'] ?? '',
    'TANGGALPASANG' => $row['TANGGALPASANG'] ?? '',
    'EMAIL' => $row['EMAIL'] ?? '',
    'TEMPO' => $row['TEMPO'] ?? '',
    'MODE' => $row['MODE'] ?? '',
    'ODP' => $row['ODP'] ?? '',
    'AREA' => $row['AREA'] ?? '',
    'TIKOR' => $row['TIKOR'] ?? '',
    'sales' => $row['sales'] ?? '',
    'PEMILIK' => $row['PEMILIK'] ?? '',
    'BRAND' => $row['BRAND'] ?? '',
    'kecamatan' => $row['kecamatan'] ?? '',
    'kelurahan' => $row['kelurahan'] ?? '',
    'rw' => $row['rw'] ?? '',
    'rt' => $row['rt'] ?? '',
    'provinsi' => $row['provinsi'] ?? '',
    'kabupaten' => $row['kabupaten'] ?? '',
    'LATITUDE' => $row['LATITUDE'] ?? '',
    'LONGITUDE' => $row['LONGITUDE'] ?? ''
];

echo json_encode(['success' => true, 'data' => $data]);
exit;
