<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require '../cek-sesi.php';
require('../routeros_api.class.php');
require_once __DIR__ . '/../staticip_helper.php';
require_once __DIR__ . '/paket_profile_helpers.php';
staticipEnsureSchema($conn);

$API = new RouterosAPI();

$id      = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$paket   = trim((string) ($_POST['paket'] ?? ''));
$area    = trim((string) ($_POST['area'] ?? ''));
$pemilik = trim((string) ($_POST['pemilik'] ?? ''));

if ($id <= 0 || $paket === '' || $area === '' || $pemilik === '') {
    header("Location: ../packagesstaticip.php?deleted=0");
    exit;
}

$paket_esc   = mysqli_real_escape_string($conn, $paket);
$area_esc    = mysqli_real_escape_string($conn, $area);
$pemilik_esc = mysqli_real_escape_string($conn, $pemilik);
$ceknama_esc = mysqli_real_escape_string($conn, $ceknama);

// Pastikan baris ini benar milik tenant yang login & memang Static IP (bukan
// PPPoE biasa) sebelum diproses lebih lanjut.
$cekRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM paket WHERE id = $id AND PEMILIK = '$ceknama_esc' AND AREA = '$area_esc' AND PAKET = '$paket_esc' AND TIPE_LAYANAN = 'PPPOE_STATIC' LIMIT 1"));
if (!$cekRow) {
    header("Location: ../packagesstaticip.php?deleted=0");
    exit;
}

// Paket masih dipakai pelanggan di server+area ini -> jangan hapus.
$cek_pelanggan = mysqli_query($conn, "SELECT COUNT(*) as jumlah FROM pelanggan WHERE PAKET = '$paket_esc' AND PEMILIK = '$pemilik_esc' AND AREA = '$area_esc'");
$row_cek = mysqli_fetch_assoc($cek_pelanggan);
if ($row_cek['jumlah'] > 0) {
    header("Location: ../packagesstaticip.php?deleted=2");
    exit;
}

$srvRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT IP, PASSWORD, CONNECTION_MODE FROM server WHERE PEMILIK = '$pemilik_esc' AND AREA = '$area_esc' LIMIT 1"));
$isRadiusOnly = $srvRow ? (($srvRow['CONNECTION_MODE'] ?? 'API') === 'RADIUS_ONLY') : true;

if ($srvRow && !$isRadiusOnly) {
    if ($API->connect($srvRow['IP'], $pemilik, $srvRow['PASSWORD'])) {
        // Pagar kedua di router: secret yang tidak tercatat di tabel `pelanggan`
        // pun tetap ikut rusak (profile jadi "*15"/unknown) kalau profile-nya
        // dihapus sementara masih direferensikan.
        $stillUsed = mikrotikCountSecretsUsingProfile($API, $paket);
        if ($stillUsed > 0) {
            $API->disconnect();
            header("Location: ../packagesstaticip.php?deleted=2");
            exit;
        }

        $profiles = $API->comm("/ppp/profile/print", ["?name" => $paket]);
        if (!empty($profiles)) {
            foreach ($profiles as $p) {
                if (isset($p['.id'])) { $API->comm("/ppp/profile/remove", [".id" => $p['.id']]); }
            }
        }
        $pools = $API->comm("/ip/pool/print", ["?name" => $paket]);
        if (!empty($pools)) {
            foreach ($pools as $pp) {
                if (isset($pp['.id'])) { $API->comm("/ip/pool/remove", [".id" => $pp['.id']]); }
            }
        }
        $API->disconnect();
    }
    // Kalau koneksi Mikrotik gagal, TETAP lanjut hapus baris database -- paket
    // Static IP mungkin memang tidak pernah punya PPP Profile (Local/Remote
    // dikosongkan saat dibuat), beda dengan deletepackages.php lama yang
    // memblokir penghapusan total kalau koneksi gagal.
}

$history_file = "../notifbot/data/history-$ceknama.json";
$history = [];
if (file_exists($history_file)) {
    $history = json_decode(file_get_contents($history_file), true);
}
if (!is_array($history)) { $history = []; }
$history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Berhasil menghapus Paket Static IP '$paket'";
@file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

$del = mysqli_query($conn, "DELETE FROM paket WHERE id = $id");
if ($del) {
    header("Location: ../packagesstaticip.php?deleted=1");
} else {
    header("Location: ../packagesstaticip.php?deleted=0");
}
exit;
