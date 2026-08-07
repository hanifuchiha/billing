<?php
// Start session for API requests
session_start();

// Set JSON header first
header('Content-Type: application/json');

// Check basic session without redirect (untuk API endpoint)
if (!isset($_SESSION['status']) || $_SESSION['status'] != 'login') {
    http_response_code(401);
    echo json_encode(['error' => 'Session expired. Please login again.', 'code' => 'AUTH_FAILED']);
    exit;
}

// Validate GET parameters
if (!isset($_GET['ip']) || empty($_GET['ip'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Parameter IP diperlukan', 'code' => 'MISSING_IP']);
    exit;
}
if (!isset($_GET['user']) || empty($_GET['user'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Parameter user diperlukan', 'code' => 'MISSING_USER']);
    exit;
}
if (!isset($_GET['password']) || empty($_GET['password'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Parameter password diperlukan', 'code' => 'MISSING_PASS']);
    exit;
}

// Load database connection
require '../koneksidb.php';
require 'routeros_api.class.php';

$mikrotik_ip    = trim($_GET['ip']       ?? '');
$mikrotik_user  = trim($_GET['user']     ?? '');
$mikrotik_pass  = trim($_GET['password'] ?? '');
$server_area    = trim($_GET['area']     ?? '');
$server_pemilik = trim($_GET['pemilik']  ?? '');

error_log("[MikroTik API Request] IP: " . substr($mikrotik_ip, 0, 20) . " | User: " . substr($mikrotik_user, 0, 20));

if (empty($mikrotik_ip) || empty($mikrotik_user) || empty($mikrotik_pass)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required parameters', 'code' => 'MISSING_PARAM']);
    exit;
}

// ---------------------------------------------------------------
// Ambil daftar IDPEL dari database sesuai AREA + PEMILIK
// IDPEL = username PPPoE yang terdaftar di sistem billing
// Hasil: set [ 'username1' => true, 'username2' => true, ... ]
// ---------------------------------------------------------------
function getRegisteredUsernamesFromDb($conn, $area, $pemilik) {
    if ($area === '' || $pemilik === '') {
        return [];
    }

    $areaEsc    = mysqli_real_escape_string($conn, $area);
    $pemilikEsc = mysqli_real_escape_string($conn, $pemilik);

    $sql = "SELECT `IDPEL` FROM `pelanggan`
            WHERE `AREA` = '$areaEsc' AND `PEMILIK` = '$pemilikEsc'";
    $res = mysqli_query($conn, $sql);

    $map = [];
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $idpel = trim($row['IDPEL'] ?? '');
            if ($idpel !== '') {
                $map[$idpel] = true;
            }
        }
    }
    return $map;
}

// ---------------------------------------------------------------
// Ambil daftar username voucher hotspot dari tabel `voucher`
// Tabel voucher tidak punya kolom AREA, hanya kolom `server`
// yang nilainya sama dengan username PEMILIK MikroTik.
// Jadi filter HANYA berdasarkan PEMILIK (server = pemilik).
// Hasil: set [ 'voucherUsername1' => true, ... ]
// ---------------------------------------------------------------
function getRegisteredHotspotVouchersFromDb($conn, $pemilik) {
    if ($pemilik === '') {
        return [];
    }

    $pemilikEsc = mysqli_real_escape_string($conn, $pemilik);

    $sql = "SELECT `voucher` FROM `voucher`
            WHERE `server` = '$pemilikEsc'";
    $res = mysqli_query($conn, $sql);

    $map = [];
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $v = trim($row['voucher'] ?? '');
            if ($v !== '') {
                $map[$v] = true;
            }
        }
    }
    return $map;
}

$api = new RouterosAPI();

try {
    if (!$api->connect($mikrotik_ip, $mikrotik_user, $mikrotik_pass)) {
        http_response_code(503);
        echo json_encode(['error' => 'Tidak bisa terhubung ke MikroTik', 'code' => 'CONNECTION_FAILED', 'ip' => $mikrotik_ip]);
        exit;
    }

    // System resource
    $resource = $api->comm("/system/resource/print");
    if (empty($resource)) {
        http_response_code(500);
        echo json_encode(['error' => 'Respons MikroTik kosong', 'code' => 'EMPTY_RESPONSE']);
        $api->disconnect();
        exit;
    }

    // ---------------------------------------------------------------
    // Step 1: Ambil username terdaftar di database (AREA + PEMILIK)
    // Ini jadi filter — hanya username yang ada di sini yang dihitung
    // ---------------------------------------------------------------
    $registered = getRegisteredUsernamesFromDb($conn, $server_area, $server_pemilik);
    $total_pppoe_secret = count($registered); // Total = jumlah di database

    // ---------------------------------------------------------------
    // Step 2: Ambil semua PPPoE SECRET dari MikroTik
    // Buat map name => profile, tapi hanya untuk yang terdaftar di DB
    // ---------------------------------------------------------------
    $pppoe_secrets = $api->comm("/ppp/secret/print");

    // map: username => profile (hanya yang ada di database)
    $secret_profile_map = [];
    foreach ((array)$pppoe_secrets as $secret) {
        $name    = trim($secret['name']    ?? '');
        $profile = strtoupper(trim($secret['profile'] ?? ''));
        if ($name !== '' && isset($registered[$name])) {
            $secret_profile_map[$name] = $profile;
        }
    }

    // ---------------------------------------------------------------
    // Step 3: Ambil PPPoE ACTIVE CONNECTION dari MikroTik
    // Profile diambil dari secret_profile_map (bukan dari active)
    // Hitung hanya yang terdaftar di database
    // ---------------------------------------------------------------
    $pppoe_active = $api->comm("/ppp/active/print");

    $active_count  = 0; // online + profile != EXPIRED + ada di DB
    $expired_count = 0; // online + profile = EXPIRED + ada di DB
    $active_names  = []; // set username yang sedang online

    foreach ((array)$pppoe_active as $conn_active) {
        $name = trim($conn_active['name'] ?? '');
        if ($name === '') continue;

        // Hanya hitung yang terdaftar di database
        if (!isset($registered[$name])) continue;

        $active_names[$name] = true;

        // Profile diambil dari secret (sumber kebenaran), bukan dari active
        $profile = $secret_profile_map[$name] ?? '';

        if ($profile === 'EXPIRED') {
            $expired_count++;
        } else {
            $active_count++;
        }
    }

    // ---------------------------------------------------------------
    // Step 4: LOS = terdaftar di DB tapi tidak online di MikroTik
    // Cek dari registered (database), bukan dari semua secret MikroTik
    // ---------------------------------------------------------------
    $los_count = 0;
    foreach ($registered as $name => $_) {
        if (!isset($active_names[$name])) {
            $los_count++;
        }
    }

    // ---------------------------------------------------------------
    // Step 5: Hotspot — filter berdasarkan database (tabel `voucher`)
    // bukan langsung hitung semua active dari MikroTik.
    //
    // Total Hotspot   = jumlah voucher terdaftar di DB untuk PEMILIK ini
    //                   (tabel voucher tidak punya AREA, jadi filter
    //                   hanya pakai PEMILIK / server = pemilik)
    // Active Hotspot  = voucher yang terdaftar di DB DAN sedang
    //                   online di /ip/hotspot/active/print MikroTik
    // ---------------------------------------------------------------
    $registered_hotspot = getRegisteredHotspotVouchersFromDb($conn, $server_pemilik);
    $total_hotspot_voucher = count($registered_hotspot);

    $hotspot_active_raw = $api->comm("/ip/hotspot/active/print");

    $hotspot_active_count = 0;
    foreach ((array)$hotspot_active_raw as $h) {
        $hname = trim($h['user'] ?? '');
        if ($hname !== '' && isset($registered_hotspot[$hname])) {
            $hotspot_active_count++;
        }
    }

    // Log untuk debugging
    error_log("[MikroTik API] IP: $mikrotik_ip | Area: $server_area | Pemilik: $server_pemilik | "
        . "Total(DB): $total_pppoe_secret | Active: $active_count | "
        . "Expired: $expired_count | LOS: $los_count | "
        . "Hotspot Total(DB): $total_hotspot_voucher | Hotspot Active: $hotspot_active_count");

    $response = [
        'success'    => true,
        'ip'         => $mikrotik_ip,
        'user'       => $mikrotik_user,
        'cpu'        => isset($resource[0]['cpu'])           ? (int)$resource[0]['cpu']           : 0,
        'cpu_load'   => isset($resource[0]['cpu-load'])      ? (int)$resource[0]['cpu-load']      : 0,
        'uptime'     => $resource[0]['uptime']               ?? 'N/A',
        'free_memory'  => isset($resource[0]['free-memory'])  ? (int)$resource[0]['free-memory']  : 0,
        'total_memory' => isset($resource[0]['total-memory']) ? (int)$resource[0]['total-memory'] : 0,
        'version'    => $resource[0]['version']              ?? 'Unknown',
        'board_name' => $resource[0]['board-name']           ?? 'Unknown',

        // Logika gabungan DB + MikroTik:
        // Total   = jumlah IDPEL di database (AREA + PEMILIK)
        // Active  = online di MikroTik + ada di DB + profile != EXPIRED
        // Expired = online di MikroTik + ada di DB + profile = EXPIRED
        // LOS     = ada di DB tapi tidak online di MikroTik
        'total_pppoe_secret'   => $total_pppoe_secret,
        'active_pppoe'         => $active_count,
        'active_pppoe_expired' => $expired_count,
        'inactive_pppoe_los'   => $los_count,

        // Hotspot dari tabel `voucher` (filter PEMILIK), dicocokkan
        // dengan user yang sedang online di MikroTik
        'total_hotspot'  => $total_hotspot_voucher,
        'active_hotspot' => $hotspot_active_count,
    ];

    http_response_code(200);
    echo json_encode($response);
    $api->disconnect();

} catch (Exception $e) {
    http_response_code(500);
    error_log("[MikroTik API Error] " . $e->getMessage());
    echo json_encode(['error' => 'Error: ' . $e->getMessage(), 'code' => 'EXCEPTION']);
} finally {
    if (isset($api)) {
        $api->disconnect();
    }
}
exit;