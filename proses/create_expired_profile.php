<?php
/**
 * proses/create_expired_profile.php
 *
 * Dipanggil dari modal "Buat Profile EXPIRED" di isolir_forwarding.php ketika PPP Profile
 * EXPIRED belum ada di router yang dipilih. Membuat IP Pool "EXPIRED" (dari Local/Remote IP
 * yang dipilih dari menu IP Pool, sama seperti alur "Tambah Paket PPPoE") + PPP Profile
 * "EXPIRED" yang memakai pool tersebut.
 *
 * Request: POST { server_id, local_ip, remote_ip }
 * Response: JSON { success, local_address, pool_range }
 */

ob_start();
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', '0');

function send_json_and_exit($data) {
    if (ob_get_length() !== false) {
        ob_clean();
    }
    echo json_encode($data);
    exit;
}

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
    require_once __DIR__ . '/../cek-sesi.php';
} catch (\Throwable $e) {
    send_json_and_exit(['success' => false, 'error' => 'Gagal load cek-sesi: ' . $e->getMessage()]);
}

if (!isset($conn) || !$conn) {
    send_json_and_exit(['success' => false, 'error' => 'Koneksi database tidak tersedia']);
}
if (empty($_SESSION['id'])) {
    send_json_and_exit(['success' => false, 'error' => 'Unauthorized']);
}
if ($AKSES !== 'ADMIN') {
    send_json_and_exit(['success' => false, 'error' => 'Hanya ADMIN yang bisa mengakses fitur ini']);
}

if (!class_exists('RouterosAPI')) {
    require_once __DIR__ . '/../routeros_api.class.php';
}
require_once __DIR__ . '/isolir_helpers.php';

$serverId = isset($_POST['server_id']) ? (int) $_POST['server_id'] : 0;
$localIp  = trim($_POST['local_ip'] ?? '');
$remoteIp = trim($_POST['remote_ip'] ?? '');

if ($serverId <= 0 || $localIp === '' || $remoteIp === '') {
    send_json_and_exit(['success' => false, 'error' => 'Local IP dan Remote IP wajib dipilih']);
}

$currentUserId = (int) $_SESSION['id'];
$srvRes = mysqli_query($conn, "SELECT * FROM server WHERE id = $serverId AND user_id = $currentUserId LIMIT 1");
if (!$srvRes || mysqli_num_rows($srvRes) === 0) {
    send_json_and_exit(['success' => false, 'error' => 'Server tidak ditemukan atau bukan milik akun ini']);
}
$server = mysqli_fetch_assoc($srvRes);

$API = new RouterosAPI();
$API->timeout = 10;

if (!$API->connect($server['IP'], $server['PEMILIK'], $server['PASSWORD'])) {
    send_json_and_exit(['success' => false, 'error' => 'Gagal terhubung ke MikroTik ' . $server['IP']]);
}

try {
    $result = createExpiredProfileOnRouter($API, $localIp, $remoteIp);
    $API->disconnect();
} catch (\Throwable $e) {
    $API->disconnect();
    send_json_and_exit(['success' => false, 'error' => 'Error RouterOS API: ' . $e->getMessage()]);
}

send_json_and_exit([
    'success'       => true,
    'local_address' => $result['local_address'],
    'pool_range'    => $result['pool_range'],
]);
