<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require '../cek-sesi.php';
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















// Pastikan koneksi database sudah ada
$API = new RouterosAPI();

// Cek apakah form dikirim dengan metode POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // helper to redirect with a message
        function redirectWith($status, $message) {
            // write to a log file so we can inspect exact errors even if the UI doesn't show them
            $logDir = __DIR__ . '/../logs';
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0755, true);
            }
            $logFile = $logDir . '/addpackagespppoe.log';
            $entry = date('Y-m-d H:i:s') . " | " . $status . " | " . $message . PHP_EOL;
            @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);

            $url = "../packages.php?statusnotif=" . urlencode($status) . "&text=" . urlencode($message);
            header("Location: " . $url);
            exit;
        }
    // Ambil dan bersihkan input
     $profileName = mysqli_real_escape_string($conn, $_POST['profileName']);
       $ratelimit     = mysqli_real_escape_string($conn, $_POST['ratelimit']);
      $harga         = $_POST['harga'];
       $local         = mysqli_real_escape_string($conn, $_POST['local']);
      $remot         = mysqli_real_escape_string($conn, $_POST['remot']);
      $servers       = $_POST['servers']; // array of JSON
      $komisi          = mysqli_real_escape_string($conn, $_POST['komisi']);
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

    // Validasi sederhana
        // stricter validation: collect all missing fields and report them
        $missing = [];
        if (trim($profileName) === '') $missing[] = 'profileName (Nama paket)';
        if (trim($ratelimit) === '') $missing[] = 'ratelimit (Kecepatan)';
        if (trim($harga) === '') $missing[] = 'harga (Harga)';
        if (!isset($servers) || !is_array($servers) || empty($servers)) $missing[] = 'servers (Server Area)';
        if (trim($local) === '') $missing[] = 'local (Local IP)';
        if (trim($remot) === '') $missing[] = 'remot (Remote IP range)';

        if (!empty($missing)) {
            redirectWith('failed', 'Field(s) missing: ' . implode(', ', $missing) . '. Please fill all required fields and try again.');
        }

        // validate komisi if provided
        if ($komisi !== '' && !is_numeric($komisi)) {
            redirectWith('failed', 'Komisi harus berupa angka (contoh: 10 untuk 10%).');
        }

    // Loop through selected servers
    $successCount = 0;
    $errors = [];

    foreach ($servers as $serverJson) {
        $serverData = json_decode($serverJson, true);
        if (!$serverData || !isset($serverData['pemilik'], $serverData['brand'], $serverData['area'])) {
            $errors[] = 'Invalid server data: ' . $serverJson;
            continue;
        }
        $pemilik = $serverData['pemilik'];
        $brand = $serverData['brand'];
        $area2 = $serverData['area'];

        // Ambil data server MikroTik dari database
        $sql = "SELECT IP, PASSWORD, CONNECTION_MODE FROM `server` WHERE `PEMILIK` = '$pemilik'";
        $query = mysqli_query($conn, $sql);
        if (!$query || mysqli_num_rows($query) == 0) {
            $errors[] = 'Server not found: ' . $pemilik . ' - ' . mysqli_error($conn);
            continue;
        }

        $data = mysqli_fetch_array($query);
        $hostip = $data['IP'];
        $passwordip = $data['PASSWORD'];
        $usernameip = $pemilik;
        $connectionMode = $data['CONNECTION_MODE'] ?? 'API';

        // Server RADIUS SAJA tidak punya API Mikrotik untuk dihubungi -- pool/profile
        // PPP tidak relevan di sana, cukup simpan definisi paket ke database saja.
        $isRadiusOnly = ($connectionMode === 'RADIUS_ONLY');
        $mikrotikOk = $isRadiusOnly ? true : $API->connect($hostip, $usernameip, $passwordip);

        if ($mikrotikOk) {
            if (!$isRadiusOnly) {
                $API->comm("/ip/pool/add", [
                    "name"   => $profileName,
                    "ranges" => $remot
                ]);

                $API->comm("/ppp/profile/add", [
                    "name"           => $profileName,
                    "rate-limit"     => $ratelimit,
                    "local-address"  => $local,
                    "remote-address" => $profileName
                ]);
            }

            // Simpan ke database
            $query2 = "INSERT INTO `paket`( `PAKET`, `KODE`, `KECEPATAN`, `LOCAL`, `REMOTE`, `HARGA`, `komisi`, `AREA`, `PEMILIK`, `BRAND`, `RADIUS_PROFILE_SOURCE`, `DISKON_PERMANEN_TYPE`, `DISKON_PERMANEN_NILAI`) VALUES ('$profileName','-', '$ratelimit','$local','$remot','$harga','$komisi','$area2','$usernameip','" . mysqli_real_escape_string($conn, $brand) . "', '$radiusProfileSource', '$diskonType', $diskonNilai)";

            if (mysqli_query($conn, $query2)) {
                $successCount++;
                // Catat di log
                $history_file = "../notifbot/data/history-$usernameip.json";
                $history = [];
                if (file_exists($history_file)) {
                    $history = json_decode(file_get_contents($history_file), true);
                }
                if (!is_array($history)) {
                    $history = [];
                }
                $note = $isRadiusOnly ? ' (server RADIUS SAJA -- tidak dibuat di MikroTik)' : '';
                $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $usernameip) . " - " . date('Y-m-d H:i:s') . " ] Berhasil menambahkan paket pppoe '$profileName','-', '$ratelimit','$local','$remot','$harga','$komisi','$area2','$usernameip'" . $note;
                file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
            } else {
                $errors[] = 'Gagal menambahkan paket for ' . $usernameip . ': ' . mysqli_error($conn);
            }
        } else {
            $errors[] = "Gagal konek ke $hostip ($area2)";
        }
    }

    if ($successCount > 0) {
        if (empty($errors)) {
            redirectWith('success', "Paket '$profileName' berhasil ditambahkan pada $successCount server");
        } else {
            redirectWith('success', "Paket '$profileName' berhasil ditambahkan pada $successCount server, tapi ada error: " . implode('; ', $errors));
        }
    } else {
        redirectWith('failed', 'Gagal menambahkan paket: ' . implode('; ', $errors));
    }









    
}