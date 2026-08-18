<?php
require '../cek-sesi.php';
require_once __DIR__ . '/../corporate_helper.php';
corporateEnsureSchema($conn);

function redirectEditPaketCorporate($status, $params = []) {
    $url = "../paket_corporate.php?statusnotif=" . urlencode($status);
    foreach ($params as $key => $value) {
        $url .= "&$key=" . urlencode($value);
    }
    header("Location: " . $url);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectEditPaketCorporate('failed', ['text' => 'Metode tidak valid']);
}

$id        = (int) ($_POST['id'] ?? 0);
$paket     = trim((string) ($_POST['paket'] ?? ''));
$kecepatan = trim((string) ($_POST['kecepatan'] ?? ''));
$harga     = (float) ($_POST['harga'] ?? 0);

if ($id <= 0 || $paket === '') {
    redirectEditPaketCorporate('failed', ['text' => 'Data tidak valid']);
}

// Scoping: hanya boleh edit paket milik tenant sendiri (PEMILIK = $ceknama),
// juga dibatasi AREA yang di-assign kalau sesi ini ASSISTANT. Cek existence
// DULU (bukan cuma andalkan mysqli_affected_rows setelah UPDATE) -- UPDATE
// yang nilainya kebetulan SAMA persis dgn data lama akan balik affected_rows=0
// walau berhasil/berwenang, yg salah kalau dipakai sbg penanda "tidak ketemu".
$ceknamaEsc = mysqli_real_escape_string($conn, $ceknama);
$areaFilter = corporateAreaFilterSql('AREA', $AKSES, $area_list ?? '');
$cekPaket = mysqli_query($conn, "SELECT id FROM paket_corporate WHERE id = $id AND PEMILIK = '$ceknamaEsc'" . $areaFilter . " LIMIT 1");
if (!$cekPaket || mysqli_num_rows($cekPaket) === 0) {
    redirectEditPaketCorporate('failed', ['text' => 'Paket tidak ditemukan']);
}

$paketEsc = mysqli_real_escape_string($conn, $paket);
$kecepatanEsc = mysqli_real_escape_string($conn, $kecepatan);

$sql = "UPDATE paket_corporate SET PAKET = '$paketEsc', KECEPATAN = '$kecepatanEsc', HARGA = $harga
        WHERE id = $id AND PEMILIK = '$ceknamaEsc'" . $areaFilter;

if (mysqli_query($conn, $sql)) {
    redirectEditPaketCorporate('edited');
} else {
    redirectEditPaketCorporate('failed', ['text' => 'Gagal memperbarui: ' . mysqli_error($conn)]);
}
