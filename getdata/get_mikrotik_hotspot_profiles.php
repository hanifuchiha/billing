<?php
/**
 * getdata/get_mikrotik_hotspot_profiles.php
 *
 * Mengambil daftar Hotspot User Profile dari MikroTik (/ip/hotspot/user/profile/print)
 * untuk satu server tertentu, lalu menandai profile mana yang SUDAH ada di tabel
 * `paket_hotspot` (berdasarkan pemilik + nama paket) dan mana yang BELUM (siap di-sync).
 *
 * Request: GET
 *   - ip       : IP/host MikroTik (kolom server.IP)
 *   - user     : username API mikrotik (pemilik)
 *   - password : password API mikrotik
 *   - area     : (opsional, utk display)
 *   - brand    : (opsional, utk display)
 *
 * Response: JSON
 *   { success: true, profiles: [ { name, rate_limit, uptime, already_synced, existing_id } ] }
 *   atau { success:false, error: "..." }
 */

// --- Pastikan respon SELALU JSON, apapun yang terjadi (fatal error, warning, exception) ---
ob_start();
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', '0'); // jangan tampilkan HTML error, supaya output tetap JSON murni

function send_json_and_exit($data) {
    // Buang semua output "liar" (warning/notice HTML) yang mungkin sudah tercetak sebelum ini
    if (ob_get_length() !== false) {
        ob_clean();
    }
    echo json_encode($data);
    exit;
}

// Tangkap fatal error (mis. file tidak ditemukan, class tidak ada, dsb) supaya tetap balas JSON
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (ob_get_length() !== false) {
            ob_clean();
        }
        echo json_encode([
            'success' => false,
            'error'   => 'Server error: ' . $err['message'] . ' (' . basename($err['file']) . ':' . $err['line'] . ')',
        ]);
    }
});

try {
    // cek-sesi.php berisi koneksi database + session + $AKSES + $area_list,
    // TANPA output HTML (berbeda dengan header.php yang me-render layout/sidebar).
    require_once __DIR__ . '/../cek-sesi.php';
} catch (\Throwable $e) {
    send_json_and_exit(['success' => false, 'error' => 'Gagal load cek-sesi: ' . $e->getMessage()]);
}

// Jaga-jaga kalau header.php TIDAK mengikutkan class RouterosAPI
if (!class_exists('RouterosAPI')) {
    $rosPath = __DIR__ . '/../routeros_api.class.php';
    if (file_exists($rosPath)) {
        require_once $rosPath;
    } else {
        send_json_and_exit(['success' => false, 'error' => 'routeros_api.class.php tidak ditemukan']);
    }
}

if (!isset($conn) || !$conn) {
    send_json_and_exit(['success' => false, 'error' => 'Koneksi database tidak tersedia']);
}

// --- Auth guard sederhana, sesuaikan dengan pola auth project ---
if (empty($_SESSION['id'])) {
    send_json_and_exit(['success' => false, 'error' => 'Unauthorized']);
}

$current_user_id = (int) $_SESSION['id'];
$AKSES            = $_SESSION['AKSES'] ?? ($AKSES ?? '');
$area_list        = $area_list ?? "''";

$ip       = $_GET['ip'] ?? '';
$user     = $_GET['user'] ?? '';
$password = $_GET['password'] ?? '';

if ($ip === '' || $user === '') {
    send_json_and_exit(['success' => false, 'error' => 'Parameter ip/user tidak lengkap']);
}

// --- Pastikan server yang diminta memang milik user yang login (anti IDOR) ---
$ipEsc   = mysqli_real_escape_string($conn, $ip);
$userEsc = mysqli_real_escape_string($conn, $user);

if ($AKSES === 'ASSISTANT') {
    // ASSISTANT divalidasi lewat AREA yang diizinkan ($area_list harus sudah di-set di koneksi/header)
    $sqlCek = "SELECT * FROM server WHERE IP = '$ipEsc' AND PEMILIK = '$userEsc' AND AREA IN ($area_list) LIMIT 1";
} else {
    $sqlCek = "SELECT * FROM server WHERE IP = '$ipEsc' AND PEMILIK = '$userEsc' AND user_id = $current_user_id LIMIT 1";
}

$resCek = mysqli_query($conn, $sqlCek);
if (!$resCek) {
    send_json_and_exit(['success' => false, 'error' => 'Query cek server gagal: ' . mysqli_error($conn)]);
}
if (mysqli_num_rows($resCek) === 0) {
    send_json_and_exit(['success' => false, 'error' => 'Server tidak ditemukan atau anda tidak memiliki akses']);
}
$serverRow = mysqli_fetch_assoc($resCek);
$pemilik   = $serverRow['PEMILIK'];

// --- Connect ke MikroTik (dibungkus try-catch, karena beberapa versi RouterosAPI
//     melempar Exception/Warning saat host unreachable / socket timeout) ---
try {
    $API = new RouterosAPI();
    $API->debug = false;
    if (property_exists($API, 'timeout')) {
        $API->timeout = 5; // detik, supaya tidak menggantung lama kalau host tidak respon
    }

    $connected = @$API->connect($ip, $user, $password);

    if (!$connected) {
        send_json_and_exit(['success' => false, 'error' => "Gagal terhubung ke MikroTik $ip. Cek IP/port, username, atau password."]);
    }

    $profiles = $API->comm('/ip/hotspot/user/profile/print');
    $API->disconnect();
} catch (\Throwable $e) {
    send_json_and_exit(['success' => false, 'error' => 'Exception saat koneksi MikroTik: ' . $e->getMessage()]);
}

if (!is_array($profiles)) {
    $profiles = [];
}

// --- Ambil paket hotspot yang sudah tercatat di DB untuk pemilik ini ---
$existingMap = []; // key: nama paket (lowercase) => id paket_hotspot
$sqlPaket = "SELECT id, paket FROM paket_hotspot WHERE pemilik = '" . mysqli_real_escape_string($conn, $pemilik) . "'";
$resPaket = mysqli_query($conn, $sqlPaket);
if ($resPaket) {
    while ($row = mysqli_fetch_assoc($resPaket)) {
        $existingMap[strtolower($row['paket'])] = $row['id'];
    }
}

// --- Susun hasil, skip profile "default" bawaan MikroTik yang biasanya tidak relevan ---
$skipNames = ['default', 'default-encryption'];

$result = [];
foreach ($profiles as $p) {
    $name = $p['name'] ?? '';
    if ($name === '' || in_array(strtolower($name), $skipNames, true)) {
        continue;
    }

    $key      = strtolower($name);
    $isSynced = isset($existingMap[$key]);

    $result[] = [
        'name'           => $name,
        'rate_limit'     => $p['rate-limit'] ?? '',
        'uptime'         => $p['session-timeout'] ?? '',
        'already_synced' => $isSynced,
        'existing_id'    => $isSynced ? $existingMap[$key] : null,
    ];
}

send_json_and_exit([
    'success'  => true,
    'pemilik'  => $pemilik,
    'area'     => $serverRow['AREA'] ?? '',
    'brand'    => $serverRow['BRAND'] ?? '',
    'profiles' => $result,
]);
