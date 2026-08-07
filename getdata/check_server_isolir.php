<?php
/**
 * getdata/check_server_isolir.php
 *
 * Dipanggil dari tombol "Cek Server" di isolir_forwarding.php. Konek ke MikroTik server
 * terpilih dan kumpulkan info yang dibutuhkan form: IP server (buat Landing Page), status
 * Web Proxy (port), status PPP Profile "EXPIRED", dan daftar IP Pool (buat modal "Buat
 * Profile EXPIRED" kalau belum ada).
 *
 * Request: POST { server_id }
 * Response: JSON { success, server_ip, proxy_port, expired_profile: {exists, pool_range}, pool_options: [{iplocal, pool_name, range}] }
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
require_once __DIR__ . '/../proses/isolir_helpers.php';

$serverId = isset($_POST['server_id']) ? (int) $_POST['server_id'] : 0;
if ($serverId <= 0) {
    send_json_and_exit(['success' => false, 'error' => 'Server belum dipilih']);
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
    $expiredProfile = findExpiredProfileAndPoolRange($API);

    $proxyStatus = getWebProxyStatus($API);
    $proxyPort = $proxyStatus['port'] !== '' ? $proxyStatus['port'] : '8080';

    $poolOptions = [];
    $poolRows = $API->comm('/ip/pool/print');
    if (is_array($poolRows)) {
        foreach ($poolRows as $p) {
            $poolName = trim($p['name'] ?? '');
            $ranges = trim($p['ranges'] ?? '');
            if ($poolName === '' || $ranges === '') {
                continue;
            }

            $firstRange = trim(explode(',', $ranges)[0]);
            $ipawal = strpos($firstRange, '-') !== false ? trim(explode('-', $firstRange, 2)[0]) : $firstRange;

            // Local IP = IP sebelum ipawal (estimasi gateway), sama seperti proses/sync_pool.php
            $iplocal = '';
            $partsIp = explode('.', $ipawal);
            if (count($partsIp) === 4) {
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

            if ($iplocal === '') {
                continue;
            }

            $poolOptions[] = [
                'iplocal' => $iplocal,
                'pool_name' => $poolName,
                'range' => $firstRange,
            ];
        }
    }

    $API->disconnect();
} catch (\Throwable $e) {
    $API->disconnect();
    send_json_and_exit(['success' => false, 'error' => 'Error RouterOS API: ' . $e->getMessage()]);
}

send_json_and_exit([
    'success' => true,
    'server_ip' => $server['IP'],
    'proxy_port' => $proxyPort,
    'expired_profile' => [
        'exists' => $expiredProfile['exists'],
        'pool_range' => $expiredProfile['pool_range'],
    ],
    'pool_options' => $poolOptions,
]);
