<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require '../cek-sesi.php';
require('../routeros_api.class.php');
require_once __DIR__ . '/../radius_sync_lib.php';
require_once __DIR__ . '/../reseller_helper.php';
require_once __DIR__ . '/../staticip_helper.php';
radiusEnsurePaketProfileSourceColumn($conn);
paketDiskonPermanenEnsureColumns($conn);
staticipEnsureSchema($conn);

function redirectAddPaketStatic($status, $text = '') {
    $url = "../packagesstaticip.php?statusnotif=" . urlencode($status);
    if ($text !== '') {
        $url .= "&text=" . urlencode($text);
    }
    header("Location: " . $url);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectAddPaketStatic('failed', 'Metode tidak valid');
}

$server      = trim((string) ($_POST['server'] ?? ''));
$area        = trim((string) ($_POST['area'] ?? ''));
$profileName = trim((string) ($_POST['profileName'] ?? ''));
$ratelimit   = trim((string) ($_POST['ratelimit'] ?? ''));
$harga       = trim((string) ($_POST['harga'] ?? ''));
$komisi      = trim((string) ($_POST['komisi'] ?? '0'));
$local       = trim((string) ($_POST['local'] ?? ''));
$remot       = trim((string) ($_POST['remot'] ?? ''));

if ($server === '' || $area === '' || $profileName === '' || $ratelimit === '' || $harga === '') {
    redirectAddPaketStatic('failed', 'Server Area, Nama Paket, Kecepatan, dan Harga wajib diisi');
}
if ($komisi !== '' && !is_numeric($komisi)) {
    redirectAddPaketStatic('failed', 'Komisi harus berupa angka');
}

// Server ini harus benar milik tenant yang login (bukan sekadar dipercaya dari POST).
$serverEsc = mysqli_real_escape_string($conn, $server);
$areaEsc = mysqli_real_escape_string($conn, $area);
$srvRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT IP, PASSWORD, BRAND, CONNECTION_MODE FROM server WHERE PEMILIK = '$serverEsc' AND AREA = '$areaEsc' LIMIT 1"));
if (!$srvRow) {
    redirectAddPaketStatic('failed', 'Server Area tidak ditemukan');
}

// Cegah duplikat nama paket pada server+area yang sama (pola sama dengan Packages Broadband).
$dupCheck = mysqli_query($conn, "SELECT id FROM paket WHERE PEMILIK = '$serverEsc' AND AREA = '$areaEsc' AND PAKET = '" . mysqli_real_escape_string($conn, $profileName) . "' LIMIT 1");
if ($dupCheck && mysqli_num_rows($dupCheck) > 0) {
    redirectAddPaketStatic('failed', 'Nama paket sudah digunakan di Server Area ini');
}

$isRadiusOnly = (($srvRow['CONNECTION_MODE'] ?? 'API') === 'RADIUS_ONLY');
$profileNameEsc = mysqli_real_escape_string($conn, $profileName);
$ratelimitEsc = mysqli_real_escape_string($conn, $ratelimit);
$localEsc = mysqli_real_escape_string($conn, $local);
$remotEsc = mysqli_real_escape_string($conn, $remot);
$brandEsc = mysqli_real_escape_string($conn, (string) $srvRow['BRAND']);

// PPP Profile + IP Pool Mikrotik cuma dibuat kalau Local & Remote diisi DAN server
// bukan RADIUS SAJA -- kalau dikosongkan, baris paket tetap tersimpan (admin bisa
// atur profile custom manual di router, atau paket ini murni dipakai server RADIUS_ONLY).
if ($local !== '' && $remot !== '' && !$isRadiusOnly) {
    $API = new RouterosAPI();
    if ($API->connect($srvRow['IP'], $server, $srvRow['PASSWORD'])) {
        $API->comm("/ip/pool/add", ["name" => $profileName, "ranges" => $remot]);
        $API->comm("/ppp/profile/add", [
            "name" => $profileName,
            "rate-limit" => $ratelimit,
            "local-address" => $local,
            "remote-address" => $profileName,
        ]);
        $API->disconnect();
    } else {
        redirectAddPaketStatic('failed', "Gagal konek ke MikroTik $server ($area)");
    }
}

$sql = "INSERT INTO paket (PAKET, KODE, KECEPATAN, LOCAL, REMOTE, HARGA, komisi, AREA, PEMILIK, BRAND, TIPE_LAYANAN)
        VALUES ('$profileNameEsc', '-', '$ratelimitEsc', '$localEsc', '$remotEsc', '" . (int) $harga . "', '" . (int) $komisi . "', '$areaEsc', '$serverEsc', '$brandEsc', 'PPPOE_STATIC')";

if (mysqli_query($conn, $sql)) {
    $history_file = "../notifbot/data/history-$ceknama.json";
    $history = [];
    if (file_exists($history_file)) {
        $history = json_decode(file_get_contents($history_file), true);
    }
    if (!is_array($history)) { $history = []; }
    $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Berhasil menambahkan Paket Static IP '$profileName' di $server-$area";
    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

    redirectAddPaketStatic('success');
} else {
    redirectAddPaketStatic('failed', 'Gagal menyimpan ke database: ' . mysqli_error($conn));
}
