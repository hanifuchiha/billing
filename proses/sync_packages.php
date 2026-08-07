<?php
/**
 * proses/sync_packages.php
 *
 * Menerima profile-profile MikroTik yang dicentang user di modal Sync Profile,
 * lengkap dengan harga & IP local/remote yang dipilih, lalu insert ke tabel `paket`.
 *
 * Request: POST
 *   - ip          : IP server mikrotik (utk lookup data server)
 *   - pemilik     : username mikrotik (kolom PEMILIK)
 *   - profiles    : JSON array, tiap item:
 *       { name, rate_limit, harga, local, remot, komisi }
 *
 * Redirect ke pakages.php dengan ?statusnotif=success / failed
 */

// --- Pastikan TIDAK PERNAH muncul HTTP 500 blank. Apapun errornya,
//     redirect balik ke pakages.php dengan pesan error yang jelas. ---
error_reporting(E_ALL);
ini_set('display_errors', '0');

function redirect_failed(string $msg) {
    header('Location: ../packages.php?statusnotif=failed&text=' . urlencode($msg));
    exit;
}

// Tangkap fatal error (mis. file/class tidak ditemukan) supaya tetap redirect, bukan HTTP 500 blank
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            header('Location: ../packages.php?statusnotif=failed&text=' . urlencode(
                'Server error: ' . $err['message'] . ' (' . basename($err['file']) . ':' . $err['line'] . ')'
            ));
        }
    }
});

try {
    // cek-sesi.php berisi koneksi database + session + $AKSES + $area_list,
    // TANPA output HTML (berbeda dengan header.php yang me-render layout/sidebar).
    require_once __DIR__ . '/../cek-sesi.php';
} catch (\Throwable $e) {
    redirect_failed('Gagal load cek-sesi: ' . $e->getMessage());
}

if (!isset($conn) || !$conn) {
    redirect_failed('Koneksi database tidak tersedia');
}

if (empty($_SESSION['id'])) {
    header('Location: ../login.php');
    exit;
}

$current_user_id = (int) $_SESSION['id'];
$AKSES            = $_SESSION['AKSES'] ?? ($AKSES ?? '');
$area_list        = $area_list ?? "''";

$ip      = $_POST['ip'] ?? '';
$pemilik = $_POST['pemilik'] ?? '';
$rawList = $_POST['profiles'] ?? '';

$profiles = json_decode($rawList, true);

if ($ip === '' || $pemilik === '' || !is_array($profiles) || count($profiles) === 0) {
    redirect_failed('Tidak ada profile yang dipilih');
}

// --- Validasi server memang milik user (anti IDOR), ambil AREA & BRAND ---
$ipEsc      = mysqli_real_escape_string($conn, $ip);
$pemilikEsc = mysqli_real_escape_string($conn, $pemilik);

if ($AKSES === 'ASSISTANT') {
    $sqlCek = "SELECT * FROM server WHERE IP = '$ipEsc' AND PEMILIK = '$pemilikEsc' AND AREA IN ($area_list) LIMIT 1";
} else {
    $sqlCek = "SELECT * FROM server WHERE IP = '$ipEsc' AND PEMILIK = '$pemilikEsc' AND user_id = $current_user_id LIMIT 1";
}

$resCek = mysqli_query($conn, $sqlCek);
if (!$resCek) {
    redirect_failed('Query cek server gagal: ' . mysqli_error($conn));
}
if (mysqli_num_rows($resCek) === 0) {
    redirect_failed('Server tidak valid atau bukan milik anda');
}
$serverRow = mysqli_fetch_assoc($resCek);
$area      = $serverRow['AREA'];
$brand     = $serverRow['BRAND'];

$inserted = 0;
$skipped  = 0;
$errors   = [];

foreach ($profiles as $p) {
    if (!is_array($p)) {
        $skipped++;
        continue;
    }

    $name   = trim((string) ($p['name'] ?? ''));
    $rate   = trim((string) ($p['rate_limit'] ?? ''));
    $harga  = isset($p['harga']) ? (int) preg_replace('/[^0-9]/', '', (string) $p['harga']) : 0;
    $local  = trim((string) ($p['local'] ?? ''));
    $remot  = trim((string) ($p['remot'] ?? ''));
    $komisi = isset($p['komisi']) ? (int) $p['komisi'] : 0;

    if ($name === '') {
        $skipped++;
        continue;
    }

    // Cegah duplikat: skip jika paket dengan nama sama untuk PEMILIK ini sudah ada
    $nameEsc = mysqli_real_escape_string($conn, $name);
    $sqlDup  = "SELECT id FROM paket WHERE PEMILIK = '$pemilikEsc' AND PAKET = '$nameEsc' LIMIT 1";
    $resDup  = mysqli_query($conn, $sqlDup);
    if ($resDup && mysqli_num_rows($resDup) > 0) {
        $skipped++;
        continue;
    }

    // KODE diisi 0 saja (placeholder), KECEPATAN diisi dari rate_limit
    $sqlInsert = "INSERT INTO paket (PAKET, KODE, KECEPATAN, HARGA, LOCAL, REMOTE, PEMILIK, AREA, komisi, BRAND)
                  VALUES (
                    '" . $nameEsc . "',
                    0,
                    '" . mysqli_real_escape_string($conn, $rate) . "',
                    " . $harga . ",
                    '" . mysqli_real_escape_string($conn, $local) . "',
                    '" . mysqli_real_escape_string($conn, $remot) . "',
                    '" . $pemilikEsc . "',
                    '" . mysqli_real_escape_string($conn, $area) . "',
                    " . $komisi . ",
                    '" . mysqli_real_escape_string($conn, $brand) . "'
                  )";

    if (mysqli_query($conn, $sqlInsert)) {
        $inserted++;
    } else {
        $errors[] = mysqli_error($conn);
    }
}

if ($inserted > 0 && count($errors) === 0) {
    header('Location: ../packages.php?statusnotif=success&synced=' . $inserted . '&skipped=' . $skipped);
} elseif ($inserted > 0 && count($errors) > 0) {
    header('Location: ../packages.php?statusnotif=success&synced=' . $inserted . '&skipped=' . $skipped . '&partial_error=1');
} else {
    $msg = count($errors) > 0 ? implode('; ', array_slice($errors, 0, 3)) : 'Tidak ada profile baru untuk disinkronkan (mungkin semua sudah ada)';
    redirect_failed($msg);
}
exit;