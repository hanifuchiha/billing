<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require '../cek-sesi.php';
require('../routeros_api.class.php');

// Bootstrap kolom server.CONNECTION_MODE (lihat server.php) -- dibutuhkan di sini
// juga karena file ini bisa dipakai sebelum admin pernah membuka server.php.
$checkConnModeCol = @mysqli_query($conn, "SHOW COLUMNS FROM server LIKE 'CONNECTION_MODE'");
if ($checkConnModeCol && mysqli_num_rows($checkConnModeCol) === 0) {
    @mysqli_query($conn, "ALTER TABLE server ADD COLUMN CONNECTION_MODE ENUM('API','RADIUS_ONLY') NOT NULL DEFAULT 'API'");
}

function redirectEditHotspot($status, $params = []) {
    $url = "../packageshotspot.php?statusnotif=" . urlencode($status);
    foreach ($params as $key => $value) {
        $url .= "&$key=" . urlencode($value);
    }
    header("Location: " . $url);
    exit;
}

$id        = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$paket     = trim($_POST['paket'] ?? '');
$uptime    = trim($_POST['uptime'] ?? '');
$harga     = isset($_POST['harga']) ? (int) preg_replace('/[^0-9]/', '', (string) $_POST['harga']) : 0;
$komisi    = isset($_POST['komisi']) ? (int) $_POST['komisi'] : 0;
$ratelimit = trim($_POST['ratelimit'] ?? '');
$serversRaw = $_POST['servers'] ?? [];

if ($id <= 0 || $paket === '' || !is_array($serversRaw) || count($serversRaw) === 0) {
    redirectEditHotspot('edit_failed', ['text' => 'Nama paket kosong atau tidak ada Server Area yang dipilih']);
}

// Ambil data lama (nama profile lama & server asal) sebelum di-update
$oldRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT paket, pemilik, area FROM paket_hotspot WHERE id = " . (int) $id));
if (!$oldRow) {
    redirectEditHotspot('edit_failed', ['text' => 'Paket tidak ditemukan']);
}
$oldPaket   = $oldRow['paket'];
$oldPemilik = $oldRow['pemilik'];
$oldArea    = $oldRow['area'];

$paketEsc     = mysqli_real_escape_string($conn, $paket);
$uptimeEsc    = mysqli_real_escape_string($conn, $uptime);
$ratelimitEsc = mysqli_real_escape_string($conn, $ratelimit);

$API = new RouterosAPI();

$updatedOrigin = false;
$insertedCount = 0;
$skippedCount  = 0;
$errors        = [];

foreach ($serversRaw as $serverJson) {
    $serverData = json_decode($serverJson, true);
    if (!$serverData || !isset($serverData['pemilik'], $serverData['brand'], $serverData['area'])) {
        $errors[] = 'Data server tidak valid';
        continue;
    }
    $pemilik = $serverData['pemilik'];
    $brand   = $serverData['brand'];
    $area    = $serverData['area'];

    $pemilikEsc = mysqli_real_escape_string($conn, $pemilik);
    $areaEsc    = mysqli_real_escape_string($conn, $area);
    $brandEsc   = mysqli_real_escape_string($conn, $brand);

    $isOrigin = ($pemilik === $oldPemilik && $area === $oldArea);

    if (!$isOrigin) {
        // Cegah duplikat nama paket pada server baru ini
        $dup = mysqli_query($conn, "SELECT id FROM paket_hotspot WHERE pemilik = '$pemilikEsc' AND paket = '$paketEsc' LIMIT 1");
        if ($dup && mysqli_num_rows($dup) > 0) {
            $skippedCount++;
            continue;
        }
    }

    $srvRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT IP, PASSWORD, CONNECTION_MODE FROM `server` WHERE `PEMILIK` = '$pemilikEsc' AND `AREA` = '$areaEsc' LIMIT 1"));
    if (!$srvRow) {
        $errors[] = "Server tidak ditemukan: $pemilik-$area";
        continue;
    }

    // Server RADIUS SAJA tidak punya API Mikrotik untuk dihubungi -- lewati semua
    // langkah profile hotspot, cukup update/insert definisi paket ke database.
    $isRadiusOnly = (($srvRow['CONNECTION_MODE'] ?? 'API') === 'RADIUS_ONLY');

    if (!$isRadiusOnly) {
        if (!$API->connect($srvRow['IP'], $pemilik, $srvRow['PASSWORD'])) {
            $errors[] = "Gagal koneksi ke MikroTik $pemilik-$area";
            continue;
        }

        if ($isOrigin) {
            // Hapus profile lama di server asal (nama mungkin berubah)
            $oldProfile = $API->comm("/ip/hotspot/user/profile/print", ["?name" => $oldPaket]);
            if (!empty($oldProfile)) {
                $API->comm("/ip/hotspot/user/profile/remove", [".id" => $oldProfile[0][".id"]]);
            }
        }

        $API->comm("/ip/hotspot/user/profile/add", [
            "name"            => $paket,
            "rate-limit"      => $ratelimit,
            "shared-users"    => "1",
            "on-login"        => "",
            "session-timeout" => $uptime,
        ]);
        $API->disconnect();
    }

    if ($isOrigin) {
        $sqlUpdate = "UPDATE paket_hotspot
                      SET paket = '$paketEsc', uptime = '$uptimeEsc', harga = $harga, komisi = $komisi, ratelimit = '$ratelimitEsc'
                      WHERE id = " . (int) $id;
        if (mysqli_query($conn, $sqlUpdate)) {
            $updatedOrigin = true;
        } else {
            $errors[] = 'Update database gagal: ' . mysqli_error($conn);
        }
    } else {
        $sqlInsert = "INSERT INTO paket_hotspot (paket, uptime, ratelimit, harga, komisi, area, pemilik, BRAND)
                      VALUES ('$paketEsc', '$uptimeEsc', '$ratelimitEsc', $harga, $komisi, '$areaEsc', '$pemilikEsc', '$brandEsc')";
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
$history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Edit paket hotspot '$oldPaket' -> '$paket' (server asal ter-update: " . ($updatedOrigin ? 'ya' : 'tidak') . ", server baru ditambahkan: $insertedCount, dilewati: $skippedCount)";
file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

if ($updatedOrigin || $insertedCount > 0) {
    redirectEditHotspot('edited', ['installed' => $insertedCount, 'skipped' => $skippedCount]);
} else {
    redirectEditHotspot('edit_failed', ['text' => implode('; ', array_slice($errors, 0, 3))]);
}