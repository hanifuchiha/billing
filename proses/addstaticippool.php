<?php
require '../cek-sesi.php';
require_once __DIR__ . '/../staticip_helper.php';
staticipEnsureSchema($conn);

function redirectPoolStatic($status, $params = []) {
    $url = "../staticippool.php?statusnotif=" . urlencode($status);
    foreach ($params as $key => $value) {
        $url .= "&$key=" . urlencode($value);
    }
    header("Location: " . $url);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectPoolStatic('failed', ['text' => 'Metode tidak valid']);
}

$server     = trim((string) ($_POST['server'] ?? ''));
$area       = trim((string) ($_POST['area'] ?? ''));
$ipAwal     = trim((string) ($_POST['ip_awal'] ?? ''));
$ipAkhir    = trim((string) ($_POST['ip_akhir'] ?? ''));
$gateway    = trim((string) ($_POST['gateway'] ?? ''));
$subnet     = trim((string) ($_POST['subnet'] ?? ''));
$keterangan = trim((string) ($_POST['keterangan'] ?? ''));

if ($server === '' || $area === '' || $ipAwal === '' || $ipAkhir === '') {
    redirectPoolStatic('failed', ['text' => 'Server Area, IP Awal, dan IP Akhir wajib diisi']);
}

$startL = staticipIpToLong($ipAwal);
$endL = staticipIpToLong($ipAkhir);
if ($startL === false || $endL === false) {
    redirectPoolStatic('failed', ['text' => 'Format IP Awal/Akhir tidak valid']);
}
if ($startL > $endL) {
    redirectPoolStatic('failed', ['text' => 'IP Awal harus lebih kecil atau sama dengan IP Akhir']);
}

// Pastikan server ini memang milik akun yang sedang login (tenant + area scoping).
$serverEsc = mysqli_real_escape_string($conn, $server);
$areaEsc = mysqli_real_escape_string($conn, $area);
$cekServer = mysqli_query($conn, "SELECT id FROM server WHERE PEMILIK = '$serverEsc' AND AREA = '$areaEsc' LIMIT 1");
if (!$cekServer || mysqli_num_rows($cekServer) === 0) {
    redirectPoolStatic('failed', ['text' => 'Server Area tidak ditemukan']);
}

$gatewayEsc = mysqli_real_escape_string($conn, $gateway);
$subnetEsc = mysqli_real_escape_string($conn, $subnet);
$keteranganEsc = mysqli_real_escape_string($conn, $keterangan);
$ipAwalEsc = mysqli_real_escape_string($conn, $ipAwal);
$ipAkhirEsc = mysqli_real_escape_string($conn, $ipAkhir);

$sql = "INSERT INTO pool_staticip (PEMILIK, AREA, ip_awal, ip_akhir, gateway, subnet, keterangan)
        VALUES ('$serverEsc', '$areaEsc', '$ipAwalEsc', '$ipAkhirEsc', '$gatewayEsc', '$subnetEsc', '$keteranganEsc')";

if (mysqli_query($conn, $sql)) {
    redirectPoolStatic('success');
} else {
    redirectPoolStatic('failed', ['text' => 'Gagal menyimpan: ' . mysqli_error($conn)]);
}
