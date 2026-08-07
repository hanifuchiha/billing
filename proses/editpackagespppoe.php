<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require '../cek-sesi.php'; // pastikan file ini menyetel $conn
require('../routeros_api.class.php');
require_once __DIR__ . '/../radius_sync_lib.php';
require_once __DIR__ . '/../reseller_helper.php';
radiusEnsurePaketProfileSourceColumn($conn);
paketDiskonPermanenEnsureColumns($conn);

// Bootstrap kolom server.CONNECTION_MODE (lihat server.php) -- dibutuhkan di sini
// juga karena file ini bisa dipakai sebelum admin pernah membuka server.php.
$checkConnModeCol = @mysqli_query($conn, "SHOW COLUMNS FROM server LIKE 'CONNECTION_MODE'");
if ($checkConnModeCol && mysqli_num_rows($checkConnModeCol) === 0) {
    @mysqli_query($conn, "ALTER TABLE server ADD COLUMN CONNECTION_MODE ENUM('API','RADIUS_ONLY') NOT NULL DEFAULT 'API'");
}

function redirectEditPppoe($status, $params = []) {
    $url = "../packages.php?edit=" . urlencode($status);
    foreach ($params as $key => $value) {
        $url .= "&$key=" . urlencode($value);
    }
    header("Location: " . $url);
    exit;
}

$id          = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$profileName = trim($_POST['profileName'] ?? '');
$ratelimit   = trim($_POST['ratelimit'] ?? '');
$harga       = isset($_POST['harga']) ? (int) preg_replace('/[^0-9]/', '', (string) $_POST['harga']) : 0;
$komisi      = isset($_POST['komisi']) ? (int) $_POST['komisi'] : 0;
$radiusProfileSource = ($_POST['radius_profile_source'] ?? 'MIKROTIK') === 'RADIUS_LANGSUNG' ? 'RADIUS_LANGSUNG' : 'MIKROTIK';
$diskonType = in_array($_POST['diskon_permanen_type'] ?? '', ['nominal', 'persentase'], true) ? $_POST['diskon_permanen_type'] : '';
$diskonNilaiRaw = trim((string)($_POST['diskon_permanen_nilai'] ?? ''));
$diskonNilai = ($diskonNilaiRaw !== '' && is_numeric($diskonNilaiRaw)) ? (float)$diskonNilaiRaw : 0;
if ($diskonType === '' || $diskonNilai <= 0) {
    $diskonType = '';
    $diskonNilai = 0;
}
if ($diskonType === 'persentase' && $diskonNilai > 100) {
    $diskonNilai = 100;
}
$diskonTypeEsc = mysqli_real_escape_string($conn, $diskonType);
$serversRaw  = json_decode($_POST['servers_payload'] ?? '', true);

if ($id <= 0 || $profileName === '' || $ratelimit === '' || !is_array($serversRaw) || count($serversRaw) === 0) {
    redirectEditPppoe('0', ['text' => 'Data tidak lengkap: nama paket, rate-limit, dan minimal satu Server Area (dengan Local/Remote IP) wajib diisi']);
}

// Ambil data lama (nama profile lama & server asal) sebelum di-update
$oldRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT PAKET, PEMILIK, AREA FROM paket WHERE id = " . (int) $id));
if (!$oldRow) {
    redirectEditPppoe('0', ['text' => 'Paket tidak ditemukan']);
}
$oldPaket   = $oldRow['PAKET'];
$oldPemilik = $oldRow['PEMILIK'];
$oldArea    = $oldRow['AREA'];

$profileNameEsc = mysqli_real_escape_string($conn, $profileName);
$ratelimitEsc   = mysqli_real_escape_string($conn, $ratelimit);

$API = new RouterosAPI();

$updatedOrigin = false;
$insertedCount = 0;
$skippedCount  = 0;
$errors        = [];

// Tiap entry payload sekarang bawa Local/Remote IP sendiri-sendiri (bukan satu
// pasang global untuk semua server), karena tiap PEMILIK punya IP Pool masing-masing.
foreach ($serversRaw as $entry) {
    if (!is_array($entry) || !isset($entry['pemilik'], $entry['brand'], $entry['area'], $entry['local'], $entry['remot'])) {
        $errors[] = 'Data server tidak valid';
        continue;
    }
    $pemilik = $entry['pemilik'];
    $brand   = $entry['brand'];
    $area    = $entry['area'];
    $local   = trim((string) $entry['local']);
    $remot   = trim((string) $entry['remot']);

    if ($local === '' || $remot === '') {
        $errors[] = "Local/Remote IP kosong untuk $pemilik-$area";
        continue;
    }

    $pemilikEsc = mysqli_real_escape_string($conn, $pemilik);
    $areaEsc    = mysqli_real_escape_string($conn, $area);
    $brandEsc   = mysqli_real_escape_string($conn, $brand);
    $localEsc   = mysqli_real_escape_string($conn, $local);
    $remotEsc   = mysqli_real_escape_string($conn, $remot);

    $isOrigin = ($pemilik === $oldPemilik && $area === $oldArea);

    if (!$isOrigin) {
        // Cegah duplikat nama paket pada server baru ini
        $dup = mysqli_query($conn, "SELECT id FROM paket WHERE PEMILIK = '$pemilikEsc' AND PAKET = '$profileNameEsc' LIMIT 1");
        if ($dup && mysqli_num_rows($dup) > 0) {
            $skippedCount++;
            continue;
        }
    }

    $srvRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT IP, PASSWORD, CONNECTION_MODE FROM server WHERE PEMILIK = '$pemilikEsc' AND AREA = '$areaEsc' LIMIT 1"));
    if (!$srvRow) {
        $errors[] = "Server tidak ditemukan: $pemilik-$area";
        continue;
    }

    // Server RADIUS SAJA tidak punya API Mikrotik untuk dihubungi -- lewati semua
    // langkah pool/profile PPP, cukup update/insert definisi paket ke database.
    $isRadiusOnly = (($srvRow['CONNECTION_MODE'] ?? 'API') === 'RADIUS_ONLY');

    if (!$isRadiusOnly) {
        if (!$API->connect($srvRow['IP'], $pemilik, $srvRow['PASSWORD'])) {
            $errors[] = "Gagal koneksi ke MikroTik $pemilik-$area";
            continue;
        }

        if ($isOrigin) {
            // Hapus profile & pool lama (nama mungkin berubah)
            $pl = $API->comm("/ppp/profile/print", ["?name" => $oldPaket]);
            if (!empty($pl)) {
                foreach ($pl as $p) {
                    if (isset($p['.id'])) {
                        $API->comm("/ppp/profile/remove", [".id" => $p['.id']]);
                    }
                }
            }
            $pools = $API->comm("/ip/pool/print", ["?name" => $oldPaket]);
            if (!empty($pools)) {
                foreach ($pools as $pp) {
                    if (isset($pp['.id'])) {
                        $API->comm("/ip/pool/remove", [".id" => $pp['.id']]);
                    }
                }
            }
        }

        $API->comm("/ip/pool/add", [
            "name"   => $profileName,
            "ranges" => $remot,
        ]);
        $API->comm("/ppp/profile/add", [
            "name"           => $profileName,
            "rate-limit"     => $ratelimit,
            "local-address"  => $local,
            "remote-address" => $profileName,
        ]);
        $API->disconnect();
    }

    if ($isOrigin) {
        $sqlUpdate = "UPDATE paket
                      SET PAKET = '$profileNameEsc', KECEPATAN = '$ratelimitEsc', HARGA = $harga, komisi = $komisi, LOCAL = '$localEsc', REMOTE = '$remotEsc', RADIUS_PROFILE_SOURCE = '$radiusProfileSource', DISKON_PERMANEN_TYPE = '$diskonTypeEsc', DISKON_PERMANEN_NILAI = $diskonNilai
                      WHERE id = " . (int) $id;
        if (mysqli_query($conn, $sqlUpdate)) {
            $updatedOrigin = true;
        } else {
            $errors[] = 'Update database gagal: ' . mysqli_error($conn);
        }
    } else {
        $sqlInsert = "INSERT INTO paket (PAKET, KODE, KECEPATAN, LOCAL, REMOTE, HARGA, komisi, AREA, PEMILIK, BRAND, RADIUS_PROFILE_SOURCE, DISKON_PERMANEN_TYPE, DISKON_PERMANEN_NILAI)
                      VALUES ('$profileNameEsc', '-', '$ratelimitEsc', '$localEsc', '$remotEsc', $harga, $komisi, '$areaEsc', '$pemilikEsc', '$brandEsc', '$radiusProfileSource', '$diskonTypeEsc', $diskonNilai)";
        if (mysqli_query($conn, $sqlInsert)) {
            $insertedCount++;
        } else {
            $errors[] = 'Insert database gagal untuk ' . $pemilik . '-' . $area . ': ' . mysqli_error($conn);
        }
    }
}

// log history
$history_file = "../notifbot/data/history-$ceknama.json";
$history = [];
if (file_exists($history_file)) {
    $history = json_decode(file_get_contents($history_file), true);
}
if (!is_array($history)) { $history = []; }
$history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Edit paket PPPoE '$oldPaket' -> '$profileName' (server asal ter-update: " . ($updatedOrigin ? 'ya' : 'tidak') . ", server baru ditambahkan: $insertedCount, dilewati: $skippedCount)";
file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

if ($updatedOrigin || $insertedCount > 0) {
    redirectEditPppoe('1', ['installed' => $insertedCount, 'skipped' => $skippedCount]);
} else {
    redirectEditPppoe('0', ['text' => implode('; ', array_slice($errors, 0, 3))]);
}
