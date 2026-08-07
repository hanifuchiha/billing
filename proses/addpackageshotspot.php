<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require('../routeros_api.class.php');
require('../cek-sesi.php'); // Pastikan koneksi database sudah ada

// Bootstrap kolom server.CONNECTION_MODE (lihat server.php) -- dibutuhkan di sini
// juga karena file ini bisa dipakai sebelum admin pernah membuka server.php.
$checkConnModeCol = @mysqli_query($conn, "SHOW COLUMNS FROM server LIKE 'CONNECTION_MODE'");
if ($checkConnModeCol && mysqli_num_rows($checkConnModeCol) === 0) {
    @mysqli_query($conn, "ALTER TABLE server ADD COLUMN CONNECTION_MODE ENUM('API','RADIUS_ONLY') NOT NULL DEFAULT 'API'");
}

function redirectAddHotspot($status, $message = '') {
    $url = "../packageshotspot.php?statusnotif=" . urlencode($status);
    if ($message !== '') {
        $url .= "&text=" . urlencode($message);
    }
    header("Location: " . $url);
    exit;
}

$API = new RouterosAPI();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $profileName = trim($_POST['profileName'] ?? '');
    $ratelimit   = trim($_POST['ratelimit'] ?? '');
    $uptime      = trim($_POST['uptime'] ?? '');
    $harga       = isset($_POST['harga']) ? (int) preg_replace('/[^0-9]/', '', (string) $_POST['harga']) : 0;
    $komisi      = isset($_POST['komisi']) ? (int) $_POST['komisi'] : 0;
    $servers     = $_POST['servers'] ?? [];

    $missing = [];
    if ($profileName === '') $missing[] = 'Nama paket';
    if (!is_array($servers) || empty($servers)) $missing[] = 'Server Area (pilih minimal satu server)';

    if (!empty($missing)) {
        redirectAddHotspot('add_failed', 'Field(s) missing: ' . implode(', ', $missing));
    }

    $profileNameEsc = mysqli_real_escape_string($conn, $profileName);
    $ratelimitEsc   = mysqli_real_escape_string($conn, $ratelimit);
    $uptimeEsc      = mysqli_real_escape_string($conn, $uptime);

    $successCount = 0;
    $skipped      = 0;
    $errors       = [];
    $lastUsernameForHistory = null;

    foreach ($servers as $serverJson) {
        $serverData = json_decode($serverJson, true);
        if (!$serverData || !isset($serverData['pemilik'], $serverData['brand'], $serverData['area'])) {
            $errors[] = 'Data server tidak valid: ' . $serverJson;
            continue;
        }
        $pemilik = $serverData['pemilik'];
        $brand   = $serverData['brand'];
        $area    = $serverData['area'];

        $pemilikEsc = mysqli_real_escape_string($conn, $pemilik);
        $areaEsc    = mysqli_real_escape_string($conn, $area);
        $brandEsc   = mysqli_real_escape_string($conn, $brand);

        // Cegah duplikat: skip jika paket dengan nama sama untuk server ini sudah ada
        $dup = mysqli_query($conn, "SELECT id FROM paket_hotspot WHERE pemilik = '$pemilikEsc' AND paket = '$profileNameEsc' LIMIT 1");
        if ($dup && mysqli_num_rows($dup) > 0) {
            $skipped++;
            continue;
        }

        $srvRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT IP, PASSWORD, CONNECTION_MODE FROM `server` WHERE `PEMILIK` = '$pemilikEsc' AND `AREA` = '$areaEsc' LIMIT 1"));
        if (!$srvRow) {
            $errors[] = "Server tidak ditemukan: $pemilik-$area";
            continue;
        }

        $lastUsernameForHistory = $pemilik;

        // Server RADIUS SAJA tidak punya API Mikrotik untuk dihubungi -- profile
        // hotspot tidak relevan di sana, cukup simpan definisi paket ke database.
        $isRadiusOnly = (($srvRow['CONNECTION_MODE'] ?? 'API') === 'RADIUS_ONLY');

        if (!$isRadiusOnly) {
            if ($API->connect($srvRow['IP'], $pemilik, $srvRow['PASSWORD'])) {
                $API->comm("/ip/hotspot/user/profile/add", [
                    "name"            => $profileName,
                    "rate-limit"      => $ratelimit,
                    "shared-users"    => "1",
                    "session-timeout" => $uptime,
                ]);
                $API->disconnect();
            } else {
                $errors[] = "Gagal koneksi ke MikroTik $pemilik-$area";
                continue;
            }
        }

        $sqlInsert = "INSERT INTO `paket_hotspot` (`paket`, `uptime`, `ratelimit`, `harga`, `komisi`, `area`, `pemilik`, `BRAND`)
                      VALUES ('$profileNameEsc', '$uptimeEsc', '$ratelimitEsc', $harga, $komisi, '$areaEsc', '$pemilikEsc', '$brandEsc')";
        if (mysqli_query($conn, $sqlInsert)) {
            $successCount++;
        } else {
            $errors[] = 'Insert paket_hotspot gagal untuk ' . $pemilik . '-' . $area . ': ' . mysqli_error($conn);
        }
    }

    ///////////////////catatat di log////////////////////
    if ($lastUsernameForHistory) {
        $history_file = "../notifbot/data/history-$ceknama.json";
        $history = [];
        if (file_exists($history_file)) {
            $history = json_decode(file_get_contents($history_file), true);
        }
        if (!is_array($history)) $history = [];
        $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Berhasil menambahkan paket hotspot '$profileName' pada $successCount server";
        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    }
    ////////////////////////////////////////////////////

    if ($successCount > 0 && empty($errors)) {
        $msg = "$successCount server berhasil";
        if ($skipped > 0) $msg .= ", $skipped dilewati (sudah ada)";
        redirectAddHotspot('add_success', $msg);
    }

    if ($successCount > 0 && !empty($errors)) {
        redirectAddHotspot('partial_success', "$successCount server berhasil, tapi ada error: " . implode('; ', $errors));
    }

    redirectAddHotspot('add_failed', !empty($errors) ? implode('; ', $errors) : 'Tidak ada server yang berhasil ditambahkan');
}