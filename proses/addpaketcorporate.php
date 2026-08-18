<?php
require '../cek-sesi.php';
require_once __DIR__ . '/../corporate_helper.php';
corporateEnsureSchema($conn);

function redirectPaketCorporate($status, $params = []) {
    $url = "../paket_corporate.php?statusnotif=" . urlencode($status);
    foreach ($params as $key => $value) {
        $url .= "&$key=" . urlencode($value);
    }
    header("Location: " . $url);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectPaketCorporate('failed', ['text' => 'Metode tidak valid']);
}

$server    = trim((string) ($_POST['server'] ?? ''));
$area      = trim((string) ($_POST['area'] ?? ''));
$paket     = trim((string) ($_POST['paket'] ?? ''));
$kecepatan = trim((string) ($_POST['kecepatan'] ?? ''));
$harga     = (float) ($_POST['harga'] ?? 0);

if ($server === '' || $area === '' || $paket === '') {
    redirectPaketCorporate('failed', ['text' => 'Server Area dan Nama Paket wajib diisi']);
}

// Pastikan server ini memang milik akun yang sedang login (tenant + area scoping).
$serverEsc = mysqli_real_escape_string($conn, $server);
$areaEsc = mysqli_real_escape_string($conn, $area);
$cekServer = mysqli_query($conn, "SELECT id FROM server WHERE PEMILIK = '$serverEsc' AND AREA = '$areaEsc' LIMIT 1");
if (!$cekServer || mysqli_num_rows($cekServer) === 0) {
    redirectPaketCorporate('failed', ['text' => 'Server Area tidak ditemukan']);
}

$paketEsc = mysqli_real_escape_string($conn, $paket);
$kecepatanEsc = mysqli_real_escape_string($conn, $kecepatan);

$sql = "INSERT INTO paket_corporate (PEMILIK, AREA, PAKET, KECEPATAN, HARGA)
        VALUES ('$serverEsc', '$areaEsc', '$paketEsc', '$kecepatanEsc', $harga)";

if (mysqli_query($conn, $sql)) {
    redirectPaketCorporate('success');
} else {
    redirectPaketCorporate('failed', ['text' => 'Gagal menyimpan: ' . mysqli_error($conn)]);
}
