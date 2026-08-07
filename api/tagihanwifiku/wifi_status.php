<?php
require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    twk_response(405, ['success' => false, 'message' => 'Method tidak diizinkan.']);
}

$conn = twk_db_connect();
$session = twk_require_auth($conn);
$idpel = (string)$session['idpel'];

function twk_detect_connection_status(mysqli $conn, array $pelanggan, string $idpel): array
{
    $default = [
        'type' => 'offline',
        'label' => 'OFFLINE / LOS',
        'source' => 'fetch_data2'
    ];

    $pemilik = trim((string)($pelanggan['PEMILIK'] ?? ''));
    $area = trim((string)($pelanggan['AREA'] ?? ''));
    $odp = trim((string)($pelanggan['ODP'] ?? ''));
    if ($pemilik === '' || $area === '' || $odp === '' || trim($idpel) === '') {
        return $default;
    }

    $idserver = null;
    $stmt = mysqli_prepare($conn, "SELECT id FROM server WHERE PEMILIK = ? AND AREA = ? ORDER BY id DESC LIMIT 1");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'ss', $pemilik, $area);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($res && ($row = mysqli_fetch_assoc($res))) {
            $idserver = (int)($row['id'] ?? 0);
        }
        mysqli_stmt_close($stmt);
    }

    if (!$idserver) {
        return $default;
    }

    $body = '';
    $fetchScript = dirname(__DIR__, 2) . '/broadband/fetch_data2.php';
    if (is_file($fetchScript)) {
        $oldGet = $_GET;
        $oldCwd = getcwd();

        $_GET['idserver'] = (string)$idserver;
        $_GET['kodeodp'] = $odp;
        $_GET['idpel'] = $idpel;

        try {
            if (is_string($oldCwd) && $oldCwd !== '') {
                @chdir(dirname($fetchScript));
            }

            ob_start();
            include $fetchScript;
            $includedOutput = ob_get_clean();
            if (is_string($includedOutput)) {
                $body = $includedOutput;
            }
        } catch (Throwable $e) {
            if (ob_get_level() > 0) {
                @ob_end_clean();
            }
        }

        $_GET = $oldGet;
        if (is_string($oldCwd) && $oldCwd !== '') {
            @chdir($oldCwd);
        }
    }

    $up = strtoupper($body);
    if (strpos($up, 'ONLINE WORKING') !== false) {
        return ['type' => 'online-working', 'label' => 'ONLINE WORKING', 'source' => 'fetch_data2'];
    }
    if (strpos($up, 'ONLINE DHCP') !== false) {
        return ['type' => 'online-dhcp', 'label' => 'ONLINE DHCP', 'source' => 'fetch_data2'];
    }
    if (strpos($up, 'EXPIRED') !== false) {
        return ['type' => 'expired', 'label' => 'EXPIRED', 'source' => 'fetch_data2'];
    }

    return $default;
}

$pelanggan = twk_find_customer($conn, $idpel);
if (!$pelanggan) {
    twk_response(404, ['success' => false, 'message' => 'Data pelanggan tidak ditemukan.']);
}

$devices = twk_find_wifi_devices_from_cache($idpel);
$acsToken = twk_build_portal_acs_token($idpel);
$connectionStatus = twk_detect_connection_status($conn, $pelanggan, $idpel);

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS twk_wifi_profiles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    idpel VARCHAR(100) NOT NULL,
    ssid VARCHAR(128) NOT NULL,
    wifi_password VARCHAR(128) NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_twk_wifi_idpel (idpel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$setting = [
    'ssid' => '',
    'wifi_password' => '',
    'updated_at' => ''
];
$st = mysqli_prepare($conn, "SELECT ssid, wifi_password, updated_at FROM twk_wifi_profiles WHERE idpel = ? ORDER BY id DESC LIMIT 1");
if ($st) {
    mysqli_stmt_bind_param($st, 's', $idpel);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    if ($res && ($row = mysqli_fetch_assoc($res))) {
        $setting = [
            'ssid' => (string)($row['ssid'] ?? ''),
            'wifi_password' => (string)($row['wifi_password'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? '')
        ];
    }
    mysqli_stmt_close($st);
}

twk_response(200, [
    'success' => true,
    'message' => 'Data WiFi berhasil diambil.',
    'data' => [
        'customer' => twk_customer_payload($pelanggan),
        'acs_token' => $acsToken,
        'device_count' => count($devices),
        'devices' => $devices,
        'connection_status' => $connectionStatus,
        'saved_setting' => $setting
    ]
]);
