<?php
require_once __DIR__ . '/../cek-sesi.php';
require_once __DIR__ . '/../routeros_api.class.php';
require_once __DIR__ . '/vlan_helpers.php';

// Buat table jika belum ada
$createTableSql = "CREATE TABLE IF NOT EXISTS vlan (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vlan_id INT UNSIGNED NOT NULL,
    keterangan VARCHAR(255) DEFAULT NULL,
    pool_id INT UNSIGNED DEFAULT NULL,
    server_id INT UNSIGNED NOT NULL,
    interface_name VARCHAR(120) NOT NULL,
    ip_gateway VARCHAR(120) DEFAULT NULL,
    vlan_interface_name VARCHAR(120) DEFAULT NULL,
    status VARCHAR(20) DEFAULT 'pending',
    sync_source VARCHAR(30) DEFAULT 'manual_add',
    last_synced_at TIMESTAMP NULL DEFAULT NULL,
    error_message TEXT DEFAULT NULL,
    pemilik VARCHAR(120) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_vlan_owner_server_interface (vlan_id, pemilik, server_id, interface_name),
    KEY idx_vlan_pemilik (pemilik),
    KEY idx_vlan_pool (pool_id),
    KEY idx_vlan_server (server_id),
    KEY idx_vlan_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
mysqli_query($conn, $createTableSql);
mysqli_query($conn, "ALTER TABLE vlan ADD COLUMN ip_gateway VARCHAR(120) DEFAULT NULL AFTER interface_name");
mysqli_query($conn, "ALTER TABLE vlan ADD COLUMN vlan_interface_name VARCHAR(120) DEFAULT NULL AFTER ip_gateway");
mysqli_query($conn, "ALTER TABLE vlan ADD COLUMN status VARCHAR(20) DEFAULT 'pending' AFTER vlan_interface_name");
mysqli_query($conn, "ALTER TABLE vlan ADD COLUMN error_message TEXT DEFAULT NULL AFTER status");
mysqli_query($conn, "ALTER TABLE vlan MODIFY pool_id INT UNSIGNED NULL");
mysqli_query($conn, "ALTER TABLE vlan ADD COLUMN sync_source VARCHAR(30) DEFAULT 'manual_add' AFTER status");
mysqli_query($conn, "ALTER TABLE vlan ADD COLUMN last_synced_at TIMESTAMP NULL DEFAULT NULL AFTER sync_source");

// Validasi input
$vlanId = isset($_POST['vlan_id']) ? (int)$_POST['vlan_id'] : 0;
$keterangan = trim($_POST['keterangan'] ?? '');
$withIp = isset($_POST['with_ip']); // centang "Aktifkan dengan IP Address" -> IP Pool wajib, kalau tidak -> VLAN aktif tanpa IP
$poolId = isset($_POST['pool_id']) ? (int)$_POST['pool_id'] : 0;
$serverId = isset($_POST['server_id']) ? (int)$_POST['server_id'] : 0;
$interfaceName = trim($_POST['interface_name'] ?? '');

if (!$withIp) {
    $poolId = 0; // pastikan tidak ikut pool walau field select masih terkirim
}

if ($vlanId <= 0 || $vlanId > 4094 || $serverId <= 0 || $interfaceName === '' || ($withIp && $poolId <= 0)) {
    header('Location: ../vlan.php?statusnotif=failed&text=' . urlencode('Input VLAN tidak lengkap atau tidak valid.'));
    exit;
}

// Cek pool milik user dan ambil IP range + IP local gateway (dilewati kalau VLAN diaktifkan tanpa IP)
$ipAwal = '';
$ipAkhir = '';
$ipLocal = '';
if ($withIp) {
    $poolCheckSql = "SELECT ipawal, ipakhir, iplocal FROM pool WHERE id = $poolId AND pemilik = '" . mysqli_real_escape_string($conn, $ceknama) . "' LIMIT 1";
    $poolCheck = mysqli_query($conn, $poolCheckSql);
    if (!$poolCheck || mysqli_num_rows($poolCheck) === 0) {
        header('Location: ../vlan.php?statusnotif=failed&text=' . urlencode('IP Pool tidak ditemukan untuk akun ini.'));
        exit;
    }
    $poolData = mysqli_fetch_assoc($poolCheck);
    $ipAwal = $poolData['ipawal'];
    $ipAkhir = $poolData['ipakhir'];
    $ipLocal = trim((string)($poolData['iplocal'] ?? ''));
}

// Cek server sesuai pola akses OLT
$currentUserId = isset($_SESSION['id']) ? (int)$_SESSION['id'] : 0;
if ($AKSES === 'ASSISTANT') {
    if (!empty($area_list)) {
        $serverCheckSql = "SELECT id, IP, PASSWORD, PEMILIK FROM server WHERE id = $serverId AND AREA IN ($area_list) LIMIT 1";
    } else {
        header('Location: ../vlan.php?statusnotif=failed&text=' . urlencode('Akses area assistant tidak tersedia.'));
        exit;
    }
} else {
    $serverCheckSql = "SELECT id, IP, PASSWORD, PEMILIK FROM server WHERE id = $serverId AND user_id = $currentUserId LIMIT 1";
}
$serverCheck = mysqli_query($conn, $serverCheckSql);
if ((!$serverCheck || mysqli_num_rows($serverCheck) === 0) && $AKSES !== 'ASSISTANT') {
    $serverFallbackSql = "SELECT id, IP, PASSWORD, PEMILIK FROM server WHERE id = $serverId AND PEMILIK = '" . mysqli_real_escape_string($conn, $ceknama) . "' LIMIT 1";
    $serverCheck = mysqli_query($conn, $serverFallbackSql);
}
if (!$serverCheck || mysqli_num_rows($serverCheck) === 0) {
    header('Location: ../vlan.php?statusnotif=failed&text=' . urlencode('Server tidak ditemukan untuk akun ini.'));
    exit;
}
$serverData = mysqli_fetch_assoc($serverCheck);
$serverIp = $serverData['IP'];
$serverPassword = $serverData['PASSWORD'];
$serverUser = $serverData['PEMILIK'];

$routerEndpoint = trim((string)$serverIp);
[$routerHost, $routerPort] = resolveVlanRouterEndpoint($routerEndpoint);

// Cek duplikat VLAN
$vlanEsc = (int)$vlanId;
$ketEsc = mysqli_real_escape_string($conn, $keterangan);
$ifEsc = mysqli_real_escape_string($conn, $interfaceName);
$ownerEsc = mysqli_real_escape_string($conn, $ceknama);

$dupSql = "SELECT id FROM vlan WHERE vlan_id = $vlanEsc AND pemilik = '$ownerEsc' AND server_id = $serverId AND interface_name = '$ifEsc' LIMIT 1";
$dupRes = mysqli_query($conn, $dupSql);
if ($dupRes && mysqli_num_rows($dupRes) > 0) {
    header('Location: ../vlan.php?statusnotif=duplicate');
    exit;
}

// Set default values
$ipGateway = '';
$vlanInterfaceName = 'vlan' . $vlanId;
$status = 'pending';
$errorMsg = '';

if ($withIp) {
    $ipGateway = deriveVlanGatewayIp($ipAwal, $ipAkhir, $ipLocal);
    if ($ipGateway === '') {
        $status = 'failed';
        $errorMsg = 'Format IP gateway tidak valid. Periksa data pool (ipawal/ipakhir/iplocal).';
    }
}

// Coba push ke RouterOS
$API = new RouterosAPI();
try {
    if ($status !== 'failed') {
        if ($routerPort > 0) {
            $API->port = $routerPort;
        }
        $connected = $API->connect($routerHost, $serverUser, $serverPassword);

        if ($connected) {
        // Step 1: Buat VLAN interface
        $createVlanCmd = [
            'name' => $vlanInterfaceName,
            'vlan-id' => (string)$vlanId,
            'interface' => $interfaceName
        ];
        
        $vlanResult = $API->comm('/interface/vlan/add', $createVlanCmd);
        $vlanFailed = (is_array($vlanResult) && (isset($vlanResult['!trap']) || isset($vlanResult['!fatal'])));

        if (!$vlanFailed) {
            $status = 'active';

            // Step 2: Assign IP address ke VLAN interface (dilewati kalau VLAN diaktifkan tanpa IP)
            if ($withIp) {
                $addIpCmd = [
                    'address' => $ipGateway,
                    'interface' => $vlanInterfaceName
                ];
                $ipResult = $API->comm('/ip/address/add', $addIpCmd);
                $ipFailed = (is_array($ipResult) && (isset($ipResult['!trap']) || isset($ipResult['!fatal'])));

                if ($ipFailed) {
                    $status = 'active_partial';
                    $trapMsg = '';
                    if (!empty($ipResult['!trap'][0]['message'])) {
                        $trapMsg = ' Detail: ' . $ipResult['!trap'][0]['message'];
                    }
                    $errorMsg = 'VLAN interface dibuat tapi gagal assign IP address.' . $trapMsg;
                }
            }
        } else {
            $status = 'failed';
            $trapMsg = '';
            if (!empty($vlanResult['!trap'][0]['message'])) {
                $trapMsg = ' Detail: ' . $vlanResult['!trap'][0]['message'];
            }
            $errorMsg = 'Gagal membuat VLAN interface di RouterOS. Pastikan interface ' . $interfaceName . ' ada di router.' . $trapMsg;
        }
        
            $API->disconnect();
        } else {
            $status = 'failed';
            $errorMsg = 'Gagal terhubung ke RouterOS API. Cek endpoint ' . $routerHost . ':' . $routerPort . ', username, password server.';
        }
    }
} catch (Exception $e) {
    $status = 'failed';
    $errorMsg = 'Error RouterOS: ' . $e->getMessage();
}

// Simpan ke database
$ipGatewayEsc = mysqli_real_escape_string($conn, $ipGateway);
$statusEsc = mysqli_real_escape_string($conn, $status);
$syncSourceEsc = mysqli_real_escape_string($conn, 'manual_add');
$errorMsgEsc = mysqli_real_escape_string($conn, $errorMsg);
$vlanInterfaceEsc = mysqli_real_escape_string($conn, $vlanInterfaceName);
$poolIdSql = $poolId > 0 ? (int)$poolId : 'NULL';

$insertSql = "INSERT INTO vlan (vlan_id, keterangan, pool_id, server_id, interface_name, ip_gateway, vlan_interface_name, status, sync_source, last_synced_at, error_message, pemilik)
            VALUES ($vlanEsc, '$ketEsc', $poolIdSql, $serverId, '$ifEsc', '$ipGatewayEsc', '$vlanInterfaceEsc', '$statusEsc', '$syncSourceEsc', NOW(), '$errorMsgEsc', '$ownerEsc')";
$insertRes = mysqli_query($conn, $insertSql);

if ($insertRes) {
    // Log ke history
    $logData = [
        'timestamp' => date('Y-m-d H:i:s'),
        'action' => 'create_vlan',
        'status' => $status,
        'details' => [
            'vlan_id' => $vlanId,
            'interface' => $interfaceName,
            'pool_id' => $poolId,
            'server_id' => $serverId,
            'ip_gateway' => $ipGateway,
            'vlan_interface' => $vlanInterfaceName,
            'error' => $errorMsg
        ]
    ];

    $historyFile = __DIR__ . '/../../notifbot/data/history-' . $ceknama . '.json';
    $dir = dirname($historyFile);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    
    if (file_exists($historyFile)) {
        $existingHistory = json_decode(file_get_contents($historyFile), true) ?? [];
    } else {
        $existingHistory = [];
    }
    $existingHistory[] = $logData;
    file_put_contents($historyFile, json_encode($existingHistory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    // Redirect dengan status notif
    if ($status === 'active') {
        header('Location: ../vlan.php?statusnotif=success');
    } elseif ($status === 'active_partial') {
        header('Location: ../vlan.php?statusnotif=warning&text=' . urlencode($errorMsg));
    } else {
        header('Location: ../vlan.php?statusnotif=failed&text=' . urlencode($errorMsg));
    }
} else {
    header('Location: ../vlan.php?statusnotif=failed&text=' . urlencode('Database error: ' . mysqli_error($conn)));
}
exit;

