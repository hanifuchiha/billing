<?php
/**
 * getdata/get_pool_by_pemilik.php
 * ----------------------------------------------------------------------
 * Dipakai oleh modal "Paket belum terdaftar" di importcustomerpppoe.php,
 * supaya form mini pembuatan paket bisa menampilkan pasangan Local/Remote
 * IP dari tabel `pool` milik PEMILIK terpilih, sama seperti dropdown
 * Local/Remote IP di packages.php (SELECT iplocal, ipawal, ipakhir FROM
 * pool WHERE pemilik=...), termasuk penandaan pool yang sudah dipakai
 * paket lain (usedLocal/usedRemot dari tabel `paket`).
 * ----------------------------------------------------------------------
 */

require_once __DIR__ . '/../cek-sesi.php';

header('Content-Type: application/json; charset=utf-8');

$pemilik = isset($_GET['pemilik']) ? trim($_GET['pemilik']) : '';

if ($pemilik === '') {
    echo json_encode(['success' => false, 'message' => 'PEMILIK wajib diisi.', 'pools' => []]);
    exit;
}

$pemilikEsc = mysqli_real_escape_string($conn, $pemilik);

$resultPool  = mysqli_query($conn, "SELECT iplocal, ipawal, ipakhir FROM pool WHERE pemilik = '$pemilikEsc'");
$resultPaket = mysqli_query($conn, "SELECT LOCAL, REMOTE FROM paket WHERE PEMILIK = '$pemilikEsc'");

$usedLocal = [];
$usedRemot = [];
if ($resultPaket) {
    while ($row = mysqli_fetch_assoc($resultPaket)) {
        if (!empty($row['LOCAL'])) {
            $usedLocal[] = $row['LOCAL'];
        }
        if (!empty($row['REMOTE'])) {
            $usedRemot[] = $row['REMOTE'];
        }
    }
}

$pools = [];
if ($resultPool) {
    while ($row = mysqli_fetch_assoc($resultPool)) {
        $local  = (string) $row['iplocal'];
        $remote = $row['ipawal'] . '-' . $row['ipakhir'];
        $pools[] = [
            'local'     => $local,
            'remote'    => $remote,
            'available' => !in_array($local, $usedLocal, true) && !in_array($remote, $usedRemot, true),
        ];
    }
}

echo json_encode(['success' => true, 'pools' => $pools]);
