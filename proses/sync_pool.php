<?php
ob_start();
session_start();
require '../header.php';
require('../routeros_api.class.php');

/**
 * Helper redirect: bersihkan semua output buffer (HTML dari header.php dll)
 * sebelum kirim header Location, supaya redirect tidak gagal jadi halaman putih.
 */
function redirectTo($url) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header("Location: $url");
    exit;
}

/**
 * proses/sync_pool.php
 * Menarik daftar IP Pool dari MikroTik (server yang dipilih),
 * lalu insert/update ke tabel `pool` milik user yang login ($ceknama).
 *
 * Kunci pencocokan insert vs update: pool_name + pemilik.
 */

$selected_ip = $_POST['sync_server'] ?? '';

if (empty($selected_ip)) {
    redirectTo("../pool.php?statusnotif=sync_failed&text=" . urlencode("Server tidak dipilih."));
}

// Pastikan server tersebut memang milik user yang login
$ip_esc = mysqli_real_escape_string($conn, $selected_ip);
$sqlSrv = "SELECT * FROM server WHERE IP = '$ip_esc' AND user_id = $current_user_id LIMIT 1";
$querySrv = mysqli_query($conn, $sqlSrv);

if (!$querySrv || mysqli_num_rows($querySrv) == 0) {
    redirectTo("../pool.php?statusnotif=sync_failed&text=" . urlencode("Server tidak ditemukan atau bukan milik Anda."));
}

$srv = mysqli_fetch_assoc($querySrv);
$ip       = $srv['IP'];
$pemilikMtk = $srv['PEMILIK'];   // username MikroTik (RouterOS)
$password = $srv['PASSWORD'];

// Koneksi ke MikroTik
$API = new RouterosAPI();

try {
    $connected = @$API->connect($ip, $pemilikMtk, $password);
} catch (Exception $e) {
    redirectTo("../pool.php?statusnotif=sync_failed&text=" . urlencode("Exception saat koneksi ke MikroTik ($ip): " . $e->getMessage()));
}

if (!$connected) {
    // Beberapa versi RouterosAPI menyimpan pesan error internal, coba tangkap jika ada
    $errDetail = '';
    if (isset($API->error_no) || isset($API->error_str)) {
        $errDetail = trim(($API->error_no ?? '') . ' ' . ($API->error_str ?? ''));
    } elseif (method_exists($API, 'getError')) {
        $errDetail = $API->getError();
    }
    $msg = "Gagal terhubung ke MikroTik ($ip). Periksa IP/Port, username, password, atau koneksi VPN.";
    if ($errDetail !== '') {
        $msg .= " Detail: $errDetail";
    }
    redirectTo("../pool.php?statusnotif=sync_failed&text=" . urlencode($msg));
}

// Ambil daftar IP Pool dari MikroTik
try {
    $pools = @$API->comm("/ip/pool/print");
} catch (Exception $e) {
    $API->disconnect();
    redirectTo("../pool.php?statusnotif=sync_failed&text=" . urlencode("Gagal mengambil data pool dari MikroTik ($ip): " . $e->getMessage()));
}

$API->disconnect();

if ($pools === false || $pools === null) {
    redirectTo("../pool.php?statusnotif=sync_failed&text=" . urlencode("MikroTik ($ip) tidak mengembalikan respon yang valid untuk /ip/pool/print."));
}

if (!is_array($pools) || count($pools) == 0) {
    redirectTo("../pool.php?statusnotif=sync_failed&text=" . urlencode("Tidak ada IP Pool ditemukan di MikroTik ($ip)."));
}

$inserted = 0;
$updated  = 0;
$skipped  = 0;
$errors   = []; // kumpulan pesan error per baris pool

foreach ($pools as $p) {
    $poolName = trim($p['name'] ?? '');
    $ranges   = trim($p['ranges'] ?? '');

    if ($poolName === '' || $ranges === '') {
        $skipped++;
        $errors[] = "Dilewati: pool tanpa nama atau tanpa range (raw name='" . ($p['name'] ?? '') . "', ranges='" . ($p['ranges'] ?? '') . "').";
        continue;
    }

    // ranges bisa berisi beberapa range dipisah koma, ambil yang pertama saja
    $firstRange = explode(',', $ranges)[0];
    $firstRange = trim($firstRange);

    if (strpos($firstRange, '-') !== false) {
        // Format "start-end"
        [$ipawal, $ipakhir] = array_map('trim', explode('-', $firstRange, 2));
    } else {
        // Hanya satu IP (range tunggal)
        $ipawal  = $firstRange;
        $ipakhir = $firstRange;
    }

    // Hitung local IP (IP sebelum ipawal) sebagai estimasi gateway, jika memungkinkan
    $iplocal = '';
    $partsIp = explode('.', $ipawal);
    if (count($partsIp) == 4) {
        $long = ((int)$partsIp[0] << 24) + ((int)$partsIp[1] << 16) + ((int)$partsIp[2] << 8) + (int)$partsIp[3];
        $gatewayLong = $long - 1;
        if ($gatewayLong > 0) {
            $iplocal = implode('.', [
                ($gatewayLong >> 24) & 255,
                ($gatewayLong >> 16) & 255,
                ($gatewayLong >> 8) & 255,
                $gatewayLong & 255
            ]);
        }
    }

    $poolName_esc = mysqli_real_escape_string($conn, $poolName);
    $ipawal_esc   = mysqli_real_escape_string($conn, $ipawal);
    $ipakhir_esc  = mysqli_real_escape_string($conn, $ipakhir);
    $iplocal_esc  = mysqli_real_escape_string($conn, $iplocal);
    $pemilik_esc  = mysqli_real_escape_string($conn, $ceknama);

    // Cek apakah pool dengan nama ini sudah ada untuk user ini
    $sqlCheck = "SELECT id FROM pool WHERE pool_name = '$poolName_esc' AND pemilik = '$pemilik_esc' LIMIT 1";
    $resCheck = mysqli_query($conn, $sqlCheck);

    if ($resCheck && mysqli_num_rows($resCheck) > 0) {
        // Update
        $row = mysqli_fetch_assoc($resCheck);
        $id  = (int)$row['id'];
        $sqlUpdate = "UPDATE pool SET
                        ipawal = '$ipawal_esc',
                        ipakhir = '$ipakhir_esc',
                        iplocal = '$iplocal_esc'
                      WHERE id = $id";
        if (mysqli_query($conn, $sqlUpdate)) {
            $updated++;
        } else {
            $skipped++;
            $errors[] = "Gagal update pool '$poolName': " . mysqli_error($conn);
        }
    } else {
        // Insert
        $sqlInsert = "INSERT INTO pool (pool_name, ipawal, ipakhir, iplocal, pemilik)
                      VALUES ('$poolName_esc', '$ipawal_esc', '$ipakhir_esc', '$iplocal_esc', '$pemilik_esc')";
        if (mysqli_query($conn, $sqlInsert)) {
            $inserted++;
        } else {
            $skipped++;
            $errors[] = "Gagal insert pool '$poolName': " . mysqli_error($conn);
        }
    }
}

$summaryBase = "Insert: $inserted, Update: $updated, Dilewati: $skipped (dari server $ip).";

if (count($errors) > 0) {
    // Gabungkan ringkasan + detail error (maks tampilkan beberapa baris error agar tidak terlalu panjang di URL)
    $errorDetail = implode(' | ', array_slice($errors, 0, 10));
    if (count($errors) > 10) {
        $errorDetail .= ' | ... (' . (count($errors) - 10) . ' error lainnya tidak ditampilkan)';
    }

    if ($inserted == 0 && $updated == 0) {
        // Semua baris gagal
        redirectTo("../pool.php?statusnotif=sync_failed&text=" . urlencode("$summaryBase Detail error: $errorDetail"));
    } else {
        // Berhasil sebagian
        redirectTo("../pool.php?statusnotif=sync_partial&text=" . urlencode("$summaryBase Detail error: $errorDetail"));
    }
}

redirectTo("../pool.php?statusnotif=sync_success&text=" . urlencode($summaryBase));