<?php
require_once __DIR__ . '/../cek-sesi.php';
require_once __DIR__ . '/../routeros_api.class.php';

// Create table jika belum ada
$createTableSql = "CREATE TABLE IF NOT EXISTS vlan (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vlan_id INT UNSIGNED NOT NULL,
    keterangan VARCHAR(255) DEFAULT NULL,
    pool_id INT UNSIGNED NOT NULL,
    server_id INT UNSIGNED NOT NULL,
    interface_name VARCHAR(120) NOT NULL,
    ip_gateway VARCHAR(120) DEFAULT NULL,
    vlan_interface_name VARCHAR(120) DEFAULT NULL,
    status VARCHAR(20) DEFAULT 'pending',
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

// Validasi input
$vlanId = isset($_POST['vlan_id']) ? (int)$_POST['vlan_id'] : 0;
$keterangan = trim($_POST['keterangan'] ?? '');
$poolId = isset($_POST['pool_id']) ? (int)$_POST['pool_id'] : 0;
$serverId = isset($_POST['server_id']) ? (int)$_POST['server_id'] : 0;
$interfaceName = trim($_POST['interface_name'] ?? '');

if ($vlanId <= 0 || $vlanId > 4094 || $poolId <= 0 || $serverId <= 0 || $interfaceName === '') {
    header('Location: ../vlan.php?statusnotif=failed&text=' . urlencode('Input VLAN tidak lengkap atau tidak valid.'));
    exit;
}

// Cek pool milik user dan ambil IP range
$poolCheckSql = "SELECT ipawal, ipakhir FROM pool WHERE id = $poolId AND pemilik = '" . mysqli_real_escape_string($conn, $ceknama) . "' LIMIT 1";
$poolCheck = mysqli_query($conn, $poolCheckSql);
if (!$poolCheck || mysqli_num_rows($poolCheck) === 0) {
    header('Location: ../vlan.php?statusnotif=failed&text=' . urlencode('IP Pool tidak ditemukan untuk akun ini.'));
    exit;
}
$poolData = mysqli_fetch_assoc($poolCheck);
$ipAwal = $poolData['ipawal'];

// Cek area untuk assistant
$whereArea = '';
if ($AKSES === 'ASSISTANT') {
    if (!empty($area_list)) {
        $whereArea = " AND AREA IN ($area_list)";
    } else {
        header('Location: ../vlan.php?statusnotif=failed&text=' . urlencode('Akses area assistant tidak tersedia.'));
        exit;
    }
}

// Cek server milik user
$serverCheckSql = "SELECT id, IP, PASSWORD FROM server WHERE id = $serverId AND PEMILIK = '" . mysqli_real_escape_string($conn, $ceknama) . "'" . $whereArea . " LIMIT 1";
$serverCheck = mysqli_query($conn, $serverCheckSql);
if (!$serverCheck || mysqli_num_rows($serverCheck) === 0) {
    header('Location: ../vlan.php?statusnotif=failed&text=' . urlencode('Server tidak ditemukan untuk akun ini.'));
    exit;
}
$serverData = mysqli_fetch_assoc($serverCheck);
$serverIp = $serverData['IP'];
$serverPass = $serverData['PASSWORD'];

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

// Set default status dan IP gateway
$ipGateway = $ipAwal;
$vlanInterfaceName = 'vlan' . $vlanId;
$status = 'pending';
$errorMsg = '';

// Coba push ke RouterOS
$API = new RouterosAPI();
$connected = $API->connect($serverIp, $ceknama, $serverPass);

if ($connected) {
    try {
        // Step 1: Buat VLAN interface
        $createVlanCmd = [
            'name' => $vlanInterfaceName,
            'vlan-id' => (string)$vlanId,
            'interface' => $interfaceName
        ];
        
        $vlanResult = $API->comm('/interface/vlan/add', $createVlanCmd);
        
        if ($vlanResult) {
            $status = 'active';
            
            // Step 2: Assign IP address ke VLAN interface
            $addIpCmd = [
                'address' => $ipGateway,
                'interface' => $vlanInterfaceName
            ];
            $ipResult = $API->comm('/ip/address/add', $addIpCmd);
            
            if (!$ipResult) {
                $status = 'active_partial';
                $errorMsg = 'VLAN interface dibuat tapi gagal assign IP address.';
            }
        } else {
            $status = 'failed';
            $errorMsg = 'Gagal membuat VLAN interface di RouterOS.';
        }
    } catch (Exception $e) {
        $status = 'failed';
        $errorMsg = 'Error: ' . $e->getMessage();
    }
    
    $API->disconnect();
} else {
    $status = 'failed';
    $errorMsg = 'Gagal terhubung ke RouterOS API.';
}

// Simpan ke database
$ipGatewayEsc = mysqli_real_escape_string($conn, $ipGateway);
$statusEsc = mysqli_real_escape_string($conn, $status);
$errorMsgEsc = mysqli_real_escape_string($conn, $errorMsg);
$vlanInterfaceEsc = mysqli_real_escape_string($conn, $vlanInterfaceName);

$insertSql = "INSERT INTO vlan (vlan_id, keterangan, pool_id, server_id, interface_name, ip_gateway, vlan_interface_name, status, error_message, pemilik)
            VALUES ($vlanEsc, '$ketEsc', $poolId, $serverId, '$ifEsc', '$ipGatewayEsc', '$vlanInterfaceEsc', '$statusEsc', '$errorMsgEsc', '$ownerEsc')";
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
    header('Location: ../vlan.php?statusnotif=failed&text=' . urlencode('Gagal simpan VLAN ke database: ' . mysqli_error($conn)));
}
exit;
