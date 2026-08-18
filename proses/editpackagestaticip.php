<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require '../cek-sesi.php';
require('../routeros_api.class.php');
require_once __DIR__ . '/../radius_sync_lib.php';
require_once __DIR__ . '/../reseller_helper.php';
require_once __DIR__ . '/../staticip_helper.php';
require_once __DIR__ . '/paket_profile_helpers.php';
radiusEnsurePaketProfileSourceColumn($conn);
paketDiskonPermanenEnsureColumns($conn);
staticipEnsureSchema($conn);

function redirectEditPaketStatic($status, $text = '') {
    $url = "../packagesstaticip.php?edit=" . urlencode($status);
    if ($text !== '') {
        $url .= "&text=" . urlencode($text);
    }
    header("Location: " . $url);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectEditPaketStatic('0', 'Metode tidak valid');
}

$id          = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$profileName = trim((string) ($_POST['profileName'] ?? ''));
$ratelimit   = trim((string) ($_POST['ratelimit'] ?? ''));
$harga       = trim((string) ($_POST['harga'] ?? ''));
$komisi      = trim((string) ($_POST['komisi'] ?? '0'));
$local       = trim((string) ($_POST['local'] ?? ''));
$remot       = trim((string) ($_POST['remot'] ?? ''));

if ($id <= 0 || $profileName === '' || $ratelimit === '' || $harga === '') {
    redirectEditPaketStatic('0', 'Nama paket, kecepatan, dan harga wajib diisi');
}

// Ambil baris lama -- juga sebagai pagar: paket ini WAJIB TIPE_LAYANAN='PPPOE_STATIC'
// milik tenant yang sedang login, supaya endpoint ini tidak bisa dipakai untuk
// mengedit paket PPPoE biasa lewat manipulasi ID.
$ceknamaEsc = mysqli_real_escape_string($conn, $ceknama);
$oldRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT PAKET, PEMILIK, AREA, LOCAL, REMOTE FROM paket WHERE id = $id AND PEMILIK = '$ceknamaEsc' AND TIPE_LAYANAN = 'PPPOE_STATIC' LIMIT 1"));
if (!$oldRow) {
    redirectEditPaketStatic('0', 'Paket Static IP tidak ditemukan');
}
$oldPaket = $oldRow['PAKET'];
$oldPemilik = $oldRow['PEMILIK'];
$oldArea = $oldRow['AREA'];

$srvRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT IP, PASSWORD, CONNECTION_MODE FROM server WHERE PEMILIK = '" . mysqli_real_escape_string($conn, $oldPemilik) . "' AND AREA = '" . mysqli_real_escape_string($conn, $oldArea) . "' LIMIT 1"));
$isRadiusOnly = $srvRow ? (($srvRow['CONNECTION_MODE'] ?? 'API') === 'RADIUS_ONLY') : true;

$profileNameEsc = mysqli_real_escape_string($conn, $profileName);
$ratelimitEsc = mysqli_real_escape_string($conn, $ratelimit);
$localEsc = mysqli_real_escape_string($conn, $local);
$remotEsc = mysqli_real_escape_string($conn, $remot);

// Local/Remote diisi (keduanya) -> buat/perbarui PPP Profile & IP Pool di Mikrotik.
// Kalau dikosongkan, TIDAK menyentuh Mikrotik sama sekali (profile lama kalau ada
// dibiarkan apa adanya -- fondasi ini sengaja tidak auto-hapus supaya tidak
// memutus koneksi pelanggan yang mungkin masih memakainya).
if ($local !== '' && $remot !== '' && !$isRadiusOnly && $srvRow) {
    $API = new RouterosAPI();
    if ($API->connect($srvRow['IP'], $oldPemilik, $srvRow['PASSWORD'])) {
        // Ganti nama paket pun dilakukan lewat `set name=` (bukan remove+add):
        // /ppp/secret menyimpan referensi ke .id profile, jadi menghapus profile
        // lama membuat secret pelanggan menunjuk .id yang sudah hilang dan
        // profile-nya tampil sebagai "*15"/unknown. Pool didahulukan karena
        // dipakai profile sebagai remote-address.
        $poolResult = mikrotikUpsertIpPool($API, $oldPaket, $profileName, $remot);
        $profileResult = mikrotikUpsertPppProfile($API, $oldPaket, $profileName, $ratelimit, $local, $profileName);
        $API->disconnect();

        if (!$profileResult['ok']) {
            redirectEditPaketStatic('0', 'Gagal update PPP Profile di MikroTik: ' . $profileResult['error']);
        }
        if (!$poolResult['ok']) {
            redirectEditPaketStatic('0', 'Gagal update IP Pool di MikroTik: ' . $poolResult['error']);
        }
    } else {
        redirectEditPaketStatic('0', 'Gagal konek ke MikroTik untuk update profile');
    }
}

$sql = "UPDATE paket SET PAKET = '$profileNameEsc', KECEPATAN = '$ratelimitEsc', HARGA = " . (int) $harga . ", komisi = " . (int) $komisi . ", LOCAL = '$localEsc', REMOTE = '$remotEsc' WHERE id = $id";

if (mysqli_query($conn, $sql)) {
    // Nama paket berubah -> ikut sinkronkan referensi di pelanggan (sama seperti
    // perilaku PPP Profile Mikrotik yang otomatis ganti nama).
    if ($oldPaket !== $profileName) {
        mysqli_query($conn, "UPDATE pelanggan SET PAKET = '$profileNameEsc' WHERE PAKET = '" . mysqli_real_escape_string($conn, $oldPaket) . "' AND PEMILIK = '" . mysqli_real_escape_string($conn, $oldPemilik) . "' AND AREA = '" . mysqli_real_escape_string($conn, $oldArea) . "'");
    }

    $history_file = "../notifbot/data/history-$ceknama.json";
    $history = [];
    if (file_exists($history_file)) {
        $history = json_decode(file_get_contents($history_file), true);
    }
    if (!is_array($history)) { $history = []; }
    $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Edit Paket Static IP '$oldPaket' -> '$profileName'";
    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

    redirectEditPaketStatic('1');
} else {
    redirectEditPaketStatic('0', 'Gagal update database: ' . mysqli_error($conn));
}
