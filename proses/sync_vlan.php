<?php
require_once __DIR__ . '/../cek-sesi.php';
require_once __DIR__ . '/../routeros_api.class.php';

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

$currentUserId = isset($_SESSION['id']) ? (int)$_SESSION['id'] : 0;
$ownerEsc = mysqli_real_escape_string($conn, $ceknama);

// Ambil semua pool milik user untuk mapping langsung dari address-pool DHCP server
$poolRows = [];
$poolRes = mysqli_query($conn, "SELECT id, pool_name, ipawal, ipakhir FROM pool WHERE pemilik = '$ownerEsc'");
if ($poolRes) {
    while ($p = mysqli_fetch_assoc($poolRes)) {
        $poolRows[] = $p;
    }
}

$poolIdByName = [];
foreach ($poolRows as $poolRow) {
    $poolName = trim((string)($poolRow['pool_name'] ?? ''));
    if ($poolName === '') {
        continue;
    }
    $poolIdByName[strtolower($poolName)] = (int)$poolRow['id'];
}

function cidrContainsIp($cidr, $ip)
{
    $parts = explode('/', (string)$cidr, 2);
    if (count($parts) !== 2) {
        return false;
    }
    $network = trim($parts[0]);
    $prefix = (int)$parts[1];
    if (!filter_var($network, FILTER_VALIDATE_IP) || !filter_var($ip, FILTER_VALIDATE_IP) || $prefix < 0 || $prefix > 32) {
        return false;
    }
    $networkLong = ip2long($network);
    $ipLong = ip2long($ip);
    if ($networkLong === false || $ipLong === false) {
        return false;
    }
    $mask = $prefix === 0 ? 0 : (~((1 << (32 - $prefix)) - 1));
    return (($networkLong & $mask) === ($ipLong & $mask));
}

function detectPoolIdFromGateway($gatewayWithPrefix, $poolRows)
{
    $gatewayIp = trim((string)$gatewayWithPrefix);
    if ($gatewayIp === '') {
        return null;
    }
    if (strpos($gatewayIp, '/') !== false) {
        $gatewayIp = substr($gatewayIp, 0, strpos($gatewayIp, '/'));
    }
    if (!filter_var($gatewayIp, FILTER_VALIDATE_IP)) {
        return null;
    }

    $gwLong = ip2long($gatewayIp);
    if ($gwLong === false) {
        return null;
    }

    foreach ($poolRows as $pool) {
        $ipAwal = trim((string)($pool['ipawal'] ?? ''));
        $ipAkhir = trim((string)($pool['ipakhir'] ?? ''));
        if ($ipAwal === '') {
            continue;
        }

        // Pool bentuk CIDR, contoh 10.11.0.0/22
        if (strpos($ipAwal, '/') !== false) {
            if (cidrContainsIp($ipAwal, $gatewayIp)) {
                return (int)$pool['id'];
            }
            continue;
        }

        // Pool bentuk range ipawal - ipakhir
        if (filter_var($ipAwal, FILTER_VALIDATE_IP) && filter_var($ipAkhir, FILTER_VALIDATE_IP)) {
            $start = ip2long($ipAwal);
            $end = ip2long($ipAkhir);
            if ($start !== false && $end !== false && $start <= $gwLong && $gwLong <= $end) {
                return (int)$pool['id'];
            }
            continue;
        }

        // Pool single IP
        if (filter_var($ipAwal, FILTER_VALIDATE_IP) && $ipAwal === $gatewayIp) {
            return (int)$pool['id'];
        }
    }

    return null;
}

function detectPoolIdFromPoolName($poolName, $poolIdByName)
{
    $normalized = strtolower(trim((string)$poolName));
    if ($normalized === '') {
        return null;
    }

    return $poolIdByName[$normalized] ?? null;
}

if ($AKSES === 'ASSISTANT') {
    if (empty($area_list)) {
        header('Location: ../vlan.php?statusnotif=failed_sync&text=' . urlencode('Akses area assistant tidak tersedia.'));
        exit;
    }
    $serverSql = "SELECT DISTINCT id, IP, PASSWORD, PEMILIK, AREA, BRAND FROM server WHERE AREA IN ($area_list) ORDER BY BRAND ASC, AREA ASC";
} else {
    $serverSql = "SELECT DISTINCT id, IP, PASSWORD, PEMILIK, AREA, BRAND FROM server WHERE user_id = $currentUserId ORDER BY BRAND ASC, AREA ASC";
}

$serverRes = mysqli_query($conn, $serverSql);
$servers = [];
if ($serverRes) {
    while ($r = mysqli_fetch_assoc($serverRes)) {
        $servers[] = $r;
    }
}

if (empty($servers) && $AKSES !== 'ASSISTANT') {
    $serverFallbackSql = "SELECT DISTINCT id, IP, PASSWORD, PEMILIK, AREA, BRAND FROM server WHERE PEMILIK = '$ownerEsc' ORDER BY BRAND ASC, AREA ASC";
    $serverFallbackRes = mysqli_query($conn, $serverFallbackSql);
    if ($serverFallbackRes) {
        while ($r = mysqli_fetch_assoc($serverFallbackRes)) {
            $servers[] = $r;
        }
    }
}

if (empty($servers)) {
    header('Location: ../vlan.php?statusnotif=failed_sync&text=' . urlencode('Tidak ada server yang bisa disinkronkan.'));
    exit;
}

$totalServer = count($servers);
$okServer = 0;
$failServer = 0;
$totalVlanUpsert = 0;
$totalRowsApi = 0;
$skippedInvalid = 0;
$dbErrorCount = 0;
$dbErrorLast = '';

foreach ($servers as $srv) {
    $serverId = (int)$srv['id'];
    $serverIp = (string)$srv['IP'];
    $serverUser = (string)$srv['PEMILIK'];
    $serverPass = (string)$srv['PASSWORD'];

    $routerEndpoint = trim((string)$serverIp);
    $routerHost = $routerEndpoint;
    $routerPort = 8728;

    if (preg_match('/^[a-z]+:\/\//i', $routerEndpoint)) {
        $parsed = parse_url($routerEndpoint);
        if (!empty($parsed['host'])) {
            $routerHost = $parsed['host'];
        }
        if (!empty($parsed['port'])) {
            $routerPort = (int)$parsed['port'];
        }
    } else {
        $pos = strrpos($routerEndpoint, ':');
        if ($pos !== false) {
            $hostPart = substr($routerEndpoint, 0, $pos);
            $portPart = substr($routerEndpoint, $pos + 1);
            if ($hostPart !== '' && ctype_digit($portPart)) {
                $routerHost = $hostPart;
                $routerPort = (int)$portPart;
            }
        }
    }

    $API = new RouterosAPI();
    if ($routerPort > 0) {
        $API->port = $routerPort;
    }
    $connected = $API->connect($routerHost, $serverUser, $serverPass);

    if (!$connected) {
        $failServer++;
        continue;
    }

    $vlanList = $API->comm('/interface/vlan/print');
    $addrList = $API->comm('/ip/address/print');
    $dhcpServerList = $API->comm('/ip/dhcp-server/print');
    $API->disconnect();

    if (!is_array($vlanList) || isset($vlanList['!trap']) || isset($vlanList['!fatal'])) {
        $failServer++;
        continue;
    }

    $okServer++;

    // Map interface -> address (CIDR) agar ip_gateway tidak kosong saat sync
    $addrByInterface = [];
    if (is_array($addrList)) {
        foreach ($addrList as $addrRow) {
            if (!is_array($addrRow)) {
                continue;
            }
            $ifName = trim((string)($addrRow['interface'] ?? ''));
            $address = trim((string)($addrRow['address'] ?? ''));
            if ($ifName === '' || $address === '') {
                continue;
            }
            if (!isset($addrByInterface[$ifName])) {
                $addrByInterface[$ifName] = $address;
            }
        }
    }

    // Map interface -> address-pool DHCP server agar pool_id dibaca langsung dari konfigurasi VLAN terkait
    $poolByInterface = [];
    if (is_array($dhcpServerList)) {
        foreach ($dhcpServerList as $dhcpRow) {
            if (!is_array($dhcpRow)) {
                continue;
            }
            $dhcpInterface = trim((string)($dhcpRow['interface'] ?? ''));
            $addressPool = trim((string)($dhcpRow['address-pool'] ?? ($dhcpRow['address_pool'] ?? '')));
            if ($dhcpInterface === '' || $addressPool === '') {
                continue;
            }
            $poolByInterface[$dhcpInterface] = $addressPool;
        }
    }

    foreach ($vlanList as $row) {
        if (!is_array($row)) {
            continue;
        }

        $totalRowsApi++;
        $vlanIdRaw = $row['vlan-id'] ?? ($row['vlan_id'] ?? ($row['vlanid'] ?? null));
        $vlanId = (int)$vlanIdRaw;
        $parentIf = isset($row['interface']) ? trim((string)$row['interface']) : '';
        $vlanIfName = isset($row['name']) ? trim((string)$row['name']) : '';
        $comment = isset($row['comment']) ? trim((string)$row['comment']) : '';
        $ipGateway = ($vlanIfName !== '' && isset($addrByInterface[$vlanIfName])) ? $addrByInterface[$vlanIfName] : null;
        $poolNameDetected = '';
        if ($vlanIfName !== '' && isset($poolByInterface[$vlanIfName])) {
            $poolNameDetected = $poolByInterface[$vlanIfName];
        } elseif ($parentIf !== '' && isset($poolByInterface[$parentIf])) {
            $poolNameDetected = $poolByInterface[$parentIf];
        }
        $poolIdDetected = detectPoolIdFromPoolName($poolNameDetected, $poolIdByName);

        if ($vlanId <= 0 || $parentIf === '') {
            $skippedInvalid++;
            continue;
        }

        // Fallback keterangan jika comment kosong
        if ($comment === '') {
            $comment = $vlanIfName !== '' ? $vlanIfName : ('VLAN ' . $vlanId . ' on ' . $parentIf);
        }

        $ifEsc = mysqli_real_escape_string($conn, $parentIf);
        $vlanIfEsc = mysqli_real_escape_string($conn, $vlanIfName);
        $ketEsc = mysqli_real_escape_string($conn, $comment);
        $ipGatewayEsc = $ipGateway !== null ? mysqli_real_escape_string($conn, $ipGateway) : null;
        $poolIdSqlValue = $poolIdDetected !== null ? (string)((int)$poolIdDetected) : 'NULL';
        $ipGatewaySqlValue = $ipGatewayEsc !== null ? ("'" . $ipGatewayEsc . "'") : 'NULL';

        $checkSql = "SELECT id FROM vlan WHERE vlan_id = $vlanId AND pemilik = '$ownerEsc' AND server_id = $serverId AND interface_name = '$ifEsc' LIMIT 1";
        $checkRes = mysqli_query($conn, $checkSql);

        if ($checkRes && mysqli_num_rows($checkRes) > 0) {
            $existing = mysqli_fetch_assoc($checkRes);
            $id = (int)$existing['id'];
            $updSql = "UPDATE vlan
                       SET keterangan = '$ketEsc',
                           pool_id = $poolIdSqlValue,
                           ip_gateway = $ipGatewaySqlValue,
                           vlan_interface_name = '$vlanIfEsc',
                           status = 'active',
                           sync_source = 'api_sync',
                           last_synced_at = NOW(),
                           error_message = NULL
                       WHERE id = $id";
            if (mysqli_query($conn, $updSql)) {
                $totalVlanUpsert++;
            } else {
                $dbErrorCount++;
                $dbErrorLast = mysqli_error($conn);
            }
        } else {
            $insSql = "INSERT INTO vlan (vlan_id, keterangan, pool_id, server_id, interface_name, ip_gateway, vlan_interface_name, status, sync_source, last_synced_at, error_message, pemilik)
                       VALUES ($vlanId, '$ketEsc', $poolIdSqlValue, $serverId, '$ifEsc', $ipGatewaySqlValue, '$vlanIfEsc', 'active', 'api_sync', NOW(), NULL, '$ownerEsc')";
            if (mysqli_query($conn, $insSql)) {
                $totalVlanUpsert++;
            } else {
                $dbErrorCount++;
                $dbErrorLast = mysqli_error($conn);
            }
        }
    }
}

$msg = "Server: $okServer/$totalServer tersambung, VLAN API: $totalRowsApi, tersimpan/terupdate: $totalVlanUpsert, skip invalid: $skippedInvalid, db error: $dbErrorCount";
if ($dbErrorLast !== '') {
    $msg .= " | Last DB error: $dbErrorLast";
}
if ($okServer === 0) {
    header('Location: ../vlan.php?statusnotif=failed_sync&text=' . urlencode($msg));
    exit;
}

header('Location: ../vlan.php?statusnotif=success_sync&text=' . urlencode($msg));
exit;
