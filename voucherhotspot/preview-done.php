<?php
require '../cek-sesi.php';
require 'vendor/autoload.php';
require '../routeros_api.class.php';

$printulang='1';




$json_file = "voucher/" . $_GET['file'];
if (!file_exists($json_file)) die("❌ File JSON tidak ditemukan");

$data = json_decode(file_get_contents($json_file), true);
if (!$data || !isset($data['vouchers'])) die("❌ Format JSON tidak valid");

// Ambil semua data dari file
$_POST = $data; // inject ke $_POST untuk reuse existing logic

$host = $data['ip'];
$user = $data['us'];
$pass = $data['ps'];
$login = $data['login'];
$jumlah = intval($data['vouchers']);
$packages = $data['packages'];
$uptime= $data['uptime'];
$ratelimit = $data['ratelimit'];
$radius = $data['radius'];
$expired_at = trim((string)($data['expired_at'] ?? ''));
$catatan = trim((string)($data['catatan'] ?? ''));

$parts = explode('|', $packages);
$harga = $parts[5] ?? '0';
$namapaket = $parts[4] ?? 'Unlimited';

// Auto-alter: kolom expired_at (masa aktif voucher) & catatan (catatan per-batch)
// ditambahkan belakangan -- self-heal sekali kalau belum ada, supaya instalasi
// lama tidak perlu migrasi manual.
$col_check = mysqli_query($conn, "SHOW COLUMNS FROM voucher LIKE 'expired_at'");
if ($col_check && mysqli_num_rows($col_check) === 0) {
    @mysqli_query($conn, "ALTER TABLE voucher ADD COLUMN expired_at DATE NULL DEFAULT NULL");
}
$col_check2 = mysqli_query($conn, "SHOW COLUMNS FROM voucher LIKE 'catatan'");
if ($col_check2 && mysqli_num_rows($col_check2) === 0) {
    @mysqli_query($conn, "ALTER TABLE voucher ADD COLUMN catatan VARCHAR(255) NULL DEFAULT NULL");
}


// ==================== FUNGSI ====================
function getFreeradiusPID() {
    $pid = trim(shell_exec("pidof freeradius"));
    if ($pid != '') return (int)$pid;
    $output = shell_exec("systemctl show -p MainPID freeradius");
    if (preg_match('/MainPID=(\d+)/', trim($output), $m)) {
        return (int)$m[1];
    }
    return 0;
}

function restartFreeradius() {
    global $debug_file;
    $pid = getFreeradiusPID();

    if ($pid > 0) {
        shell_exec('sudo systemctl stop freeradius');
        shell_exec("sudo kill -9 $pid");
    }

    if (file_exists($debug_file)) shell_exec("sudo rm -f $debug_file");
    shell_exec("sudo touch $debug_file");
    shell_exec("sudo chmod 666 $debug_file");
    shell_exec("sudo freeradius -X > $debug_file 2>&1 &");
    sleep(10);
}

function convertToSeconds($uptime)
{
    if (is_numeric($uptime)) {
        return $uptime;
    }

    if (preg_match('/^(\d+)([a-zA-Z]+)$/', $uptime, $matches)) {
        $value = $matches[1];
        $unit = strtolower($matches[2]);

        switch ($unit) {
            case 'h':
                return $value * 3600;
            case 'd':
                return $value * 86400;
            case 'w':
                return $value * 604800;
            case 'm':
                return $value * 2592000;
            default:
                die("❌ Format waktu tidak dikenal!");
        }
    }

    die("❌ Format uptime tidak valid!");
}


?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Voucher Info</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f1f5f9;
            color: #0f172a;
        }

        .voucher-ready-card .card-header {
            background: #16a34a !important;
            color: #ffffff !important;
        }

        .voucher-ready-card .card-header h5,
        .voucher-ready-card .card-body,
        .voucher-ready-card .card-body p,
        .voucher-ready-card .card-footer {
            opacity: 1 !important;
        }

        @media (prefers-color-scheme: dark) {
            body {
                background-color: #020617 !important;
                color: #e2e8f0 !important;
            }

            .voucher-ready-card {
                background-color: #0f172a !important;
                border-color: rgba(148, 163, 184, 0.25) !important;
            }

            .voucher-ready-card .card-header {
                background: #1e293b !important;
                color: #e2e8f0 !important;
            }

            .voucher-ready-card .card-header h5,
            .voucher-ready-card .card-body,
            .voucher-ready-card .card-body p,
            .voucher-ready-card .card-footer {
                color: #e2e8f0 !important;
            }

            .voucher-ready-card .card-footer.text-muted {
                color: #cbd5e1 !important;
            }
        }
    </style>
</head>
<body>

<div class="d-flex justify-content-center align-items-center vh-100">
    <div class="card text-center shadow-sm border-success voucher-ready-card" style="max-width: 400px;">
    <div class="card-header bg-success text-white">
      <h5 class="card-title mb-0">Voucher Aktif</h5>
    </div>
    <div class="card-body">
      <p class="card-text">Voucher Anda sudah aktif. Silakan buka Voucher bank untuk melakukan cetak voucher.</p>
     <a href="../voucherbank.php" target="_parent"class="btn btn-success">Buka Voucher Bank</a>
    </div>
    <div class="card-footer text-muted">
      Terima kasih telah menggunakan layanan kami.
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>


    <?php

foreach ($data['vouchers'] as $v) {
    $voucher = trim($v['user']); // nama user voucher
    if ($voucher === '') continue; // skip jika kosong

    $API = new RouterosAPI();
    $API->debug = false;

    if ($radius === 'no') {
        // ==================== TAMBAHKAN USER KE MIKROTIK ====================
        // $uptime dikirim APA ADANYA (mis. "1d"/"24h", format RouterOS native
        // dari kolom paket_hotspot.uptime) -- sebelumnya di sini malah
        // "limit-uptime" => $uptime_seconds yang UNDEFINED (cuma dihitung di
        // cabang RADIUS di bawah), jadi voucher mode API dibuat TANPA batas
        // waktu sama sekali. Pola "kirim $uptime mentah ke limit-uptime" sama
        // persis dgn yg dipakai callback_tripay.php dkk (beli voucher mode API).
        if ($API->connect($host, $user, $pass)) {
            $API->comm("/ip/hotspot/user/add", [
                "name" => $voucher,
                "password" => $voucher,
                "profile" => $namapaket,
                "limit-uptime" => $uptime
            ]);
            $API->disconnect();
        }
    } else {
        // Konversi uptime ke detik -- CUMA dibutuhkan RADIUS (Session-Timeout
        // wajib integer detik), MikroTik API terima format native di atas.
        $uptime_seconds = convertToSeconds($uptime);

        // ==================== TAMBAHKAN USER KE FREERADIUS ====================
     // Format user untuk FreeRADIUS
        $user_entry = "\n$voucher    Cleartext-Password := \"$voucher\"\n" .
            "        Mikrotik-Rate-Limit := \"$ratelimit\",\n" .
            "        Session-Timeout := $uptime_seconds,\n" .
            "        Mikrotik-Group := \"$namapaket\",\n" .
            "        Simultaneous-Use := 1\n";

        // Tambahkan ke file users (gunakan sudo agar aman)
        $cmd = "echo " . escapeshellarg($user_entry) . " | sudo tee -a /etc/freeradius/3.0/users > /dev/null";
        exec($cmd, $output, $return_var);

        // ==================== SIMPAN TIMER USER ====================
        $timer_data = [
            'username' => $voucher,
            'session_timeout' => $uptime_seconds,
            'used_seconds' => 0,
            'last_check' => time()
        ];

        $timer_folder = "/etc/freeradius/user_timers";
        if (!file_exists($timer_folder)) {
            mkdir($timer_folder, 0775, true);
            chown($timer_folder, 'www-data');
            chgrp($timer_folder, 'www-data');
        }

        $timer_file = "$timer_folder/{$voucher}.json";
        file_put_contents($timer_file, json_encode($timer_data, JSON_PRETTY_PRINT));
    }



// Query INSERT biasa
$expired_at_sql = ($expired_at !== '' && strtotime($expired_at) !== false)
    ? "'" . mysqli_real_escape_string($conn, $expired_at) . "'"
    : "NULL";
$catatan_esc = mysqli_real_escape_string($conn, $catatan);
$sql = "INSERT INTO voucher (voucher, server, paket, harga, pemilik, expired_at, catatan)
        VALUES ('$voucher', '$user', '$namapaket', '$harga', '$ceknama', $expired_at_sql, '$catatan_esc')";

// Eksekusi query
$conn->query($sql);







}

// ==================== RESTART FREERADIUS HANYA SEKALI DI AKHIR ====================
if ($radius !== 'no' && $printulang == '1') {
    restartFreeradius();
}






    ?>
