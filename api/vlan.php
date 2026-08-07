<?php
// api/vlan.php - VLAN management API (MikroTik RouterOS provisioning + `vlan` table CRUD).
// Full CRUD (GET/POST/PUT/DELETE) plus a `?action=sync` action, scoped to servers owned by
// (or assigned to, for ASSISTANT accounts) the authenticated user - mirrors the access model
// used throughout crm/billing/api (see api/tiket_manager.php, api/server_area.php).
//
// This is a straight port of the web flow in:
//   - vlan.php                     (list/dashboard query shape)
//   - proses/add_vlan.php          (create: validation + RouterOS push + insert)
//   - proses/edit_vlan.php         (update: keterangan/pool_id/ip_gateway + RouterOS IP diff)
//   - proses/delete_vlan.php       (delete: DB row only, router VLAN is left in place)
//   - proses/sync_vlan.php         (pull live VLAN interfaces from RouterOS, upsert into `vlan`)
//   - proses/vlan_helpers.php      (deriveVlanGatewayIp / resolveVlanRouterEndpoint - reused as-is)
require_once '../koneksibilling.php';
require_once '_bootstrap.php';
require_once '../routeros_api.class.php';
require_once '../proses/vlan_helpers.php';
session_start();
api_cors();

// --- Ensure schema (identical to vlan.php / proses/add_vlan.php / proses/sync_vlan.php) ---
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

$method = $_SERVER['REQUEST_METHOD'];
$input  = api_read_input();

$auth = api_authenticate($conn, $input);
if ($auth['method'] === 'apikey') {
    api_rate_limit($conn, $auth['api_key']);
}

$ctx = api_resolve_owner($conn, $auth['pemilik']);
if (!$ctx) {
    api_json(['success' => false, 'error' => 'User tidak ditemukan'], 401);
}
api_require_module_enabled($conn, $auth['pemilik'], 'vlan');
$allowed_server_ids = $ctx['allowed_server_ids'];

$action = strtolower(trim((string)($_GET['action'] ?? ($input['action'] ?? ''))));

// ------------------------------------------------------------------------------------------
// Helpers
// ------------------------------------------------------------------------------------------

/** Fetch a server row (id, IP, PASSWORD, PEMILIK, AREA, BRAND) if it's within allowed_server_ids. */
function vlanapi_server_row($conn, $serverId, array $allowedServerIds) {
    $serverId = (int)$serverId;
    if ($serverId <= 0 || !in_array($serverId, $allowedServerIds, true)) {
        return null;
    }
    $stmt = $conn->prepare('SELECT id, IP, PASSWORD, PEMILIK, AREA, BRAND FROM server WHERE id = ? LIMIT 1');
    api_bind($stmt, 'i', [$serverId]);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

/** Fetch a pool row (ipawal/ipakhir/iplocal), scoped to the owning server's PEMILIK - same scoping
 *  proses/add_vlan.php and proses/edit_vlan.php use ("WHERE id = $poolId AND pemilik = '$ceknama'"). */
function vlanapi_pool_row($conn, $poolId, $pemilik) {
    $poolId = (int)$poolId;
    if ($poolId <= 0) {
        return null;
    }
    $stmt = $conn->prepare('SELECT id, ipawal, ipakhir, iplocal FROM pool WHERE id = ? AND pemilik = ? LIMIT 1');
    api_bind($stmt, 'is', [$poolId, $pemilik]);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

/** Fetch one vlan row joined with pool/server, same columns vlan.php's list page shows. */
function vlanapi_fetch_row($conn, $id) {
    $stmt = $conn->prepare("SELECT v.id, v.vlan_id, v.keterangan, v.pool_id, v.server_id, v.interface_name,
                                    v.ip_gateway, v.vlan_interface_name, v.status, v.sync_source,
                                    v.last_synced_at, v.error_message, v.pemilik, v.created_at,
                                    p.pool_name, p.ipawal, p.ipakhir,
                                    s.AREA AS server_area, s.IP AS server_ip, s.BRAND AS server_brand
                             FROM vlan v
                             LEFT JOIN pool p ON p.id = v.pool_id
                             LEFT JOIN server s ON s.id = v.server_id
                             WHERE v.id = ?
                             LIMIT 1");
    api_bind($stmt, 'i', [(int)$id]);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

/** Pool-name -> pool_id lookup, same convention proses/sync_vlan.php uses (matched against the
 *  DHCP server's address-pool bound to the VLAN interface / its parent interface). */
function vlanapi_pool_id_by_name($poolName, array $poolIdByName) {
    $normalized = strtolower(trim((string)$poolName));
    if ($normalized === '') {
        return null;
    }
    return $poolIdByName[$normalized] ?? null;
}

/**
 * Pull live VLAN interfaces from one owned server's router and upsert into `vlan`
 * (sync_source='api_sync'). Straight port of proses/sync_vlan.php's per-server loop,
 * scoped to a single server_id instead of "every server this account owns".
 */
function vlanapi_sync_server($conn, array $server) {
    $serverId   = (int)$server['id'];
    $serverIp   = (string)$server['IP'];
    $serverUser = (string)$server['PEMILIK'];
    $serverPass = (string)$server['PASSWORD'];
    $ownerEsc   = $serverUser;

    [$routerHost, $routerPort] = resolveVlanRouterEndpoint(trim($serverIp));

    $poolRows = [];
    $poolStmt = $conn->prepare('SELECT id, pool_name, ipawal, ipakhir FROM pool WHERE pemilik = ?');
    api_bind($poolStmt, 's', [$ownerEsc]);
    $poolStmt->execute();
    $poolRes = $poolStmt->get_result();
    while ($p = $poolRes->fetch_assoc()) {
        $poolRows[] = $p;
    }
    $poolIdByName = [];
    foreach ($poolRows as $poolRow) {
        $poolName = trim((string)($poolRow['pool_name'] ?? ''));
        if ($poolName === '') {
            continue;
        }
        $poolIdByName[strtolower($poolName)] = (int)$poolRow['id'];
    }

    $API = new RouterosAPI();
    if ($routerPort > 0) {
        $API->port = $routerPort;
    }
    $connected = $API->connect($routerHost, $serverUser, $serverPass);
    if (!$connected) {
        return ['ok' => false, 'error' => 'Gagal terhubung ke RouterOS API. Cek endpoint ' . $routerHost . ':' . $routerPort . ', username, password server.'];
    }

    $vlanList       = $API->comm('/interface/vlan/print');
    $addrList       = $API->comm('/ip/address/print');
    $dhcpServerList = $API->comm('/ip/dhcp-server/print');
    $API->disconnect();

    if (!is_array($vlanList) || isset($vlanList['!trap']) || isset($vlanList['!fatal'])) {
        return ['ok' => false, 'error' => 'Gagal membaca daftar VLAN interface dari RouterOS.'];
    }

    // Map interface -> address (CIDR)
    $addrByInterface = [];
    if (is_array($addrList)) {
        foreach ($addrList as $addrRow) {
            if (!is_array($addrRow)) {
                continue;
            }
            $ifName  = trim((string)($addrRow['interface'] ?? ''));
            $address = trim((string)($addrRow['address'] ?? ''));
            if ($ifName === '' || $address === '') {
                continue;
            }
            if (!isset($addrByInterface[$ifName])) {
                $addrByInterface[$ifName] = $address;
            }
        }
    }

    // Map interface -> address-pool DHCP server
    $poolByInterface = [];
    if (is_array($dhcpServerList)) {
        foreach ($dhcpServerList as $dhcpRow) {
            if (!is_array($dhcpRow)) {
                continue;
            }
            $dhcpInterface = trim((string)($dhcpRow['interface'] ?? ''));
            $addressPool   = trim((string)($dhcpRow['address-pool'] ?? ($dhcpRow['address_pool'] ?? '')));
            if ($dhcpInterface === '' || $addressPool === '') {
                continue;
            }
            $poolByInterface[$dhcpInterface] = $addressPool;
        }
    }

    $totalRowsApi    = 0;
    $totalVlanUpsert = 0;
    $skippedInvalid  = 0;
    $dbErrorCount    = 0;
    $dbErrorLast     = '';

    foreach ($vlanList as $row) {
        if (!is_array($row)) {
            continue;
        }
        $totalRowsApi++;

        $vlanIdRaw = $row['vlan-id'] ?? ($row['vlan_id'] ?? ($row['vlanid'] ?? null));
        $vlanId    = (int)$vlanIdRaw;
        $parentIf  = isset($row['interface']) ? trim((string)$row['interface']) : '';
        $vlanIfName = isset($row['name']) ? trim((string)$row['name']) : '';
        $comment   = isset($row['comment']) ? trim((string)$row['comment']) : '';
        $ipGateway = ($vlanIfName !== '' && isset($addrByInterface[$vlanIfName])) ? $addrByInterface[$vlanIfName] : null;

        $poolNameDetected = '';
        if ($vlanIfName !== '' && isset($poolByInterface[$vlanIfName])) {
            $poolNameDetected = $poolByInterface[$vlanIfName];
        } elseif ($parentIf !== '' && isset($poolByInterface[$parentIf])) {
            $poolNameDetected = $poolByInterface[$parentIf];
        }
        $poolIdDetected = vlanapi_pool_id_by_name($poolNameDetected, $poolIdByName);

        if ($vlanId <= 0 || $parentIf === '') {
            $skippedInvalid++;
            continue;
        }

        if ($comment === '') {
            $comment = $vlanIfName !== '' ? $vlanIfName : ('VLAN ' . $vlanId . ' on ' . $parentIf);
        }

        $checkStmt = $conn->prepare('SELECT id FROM vlan WHERE vlan_id = ? AND pemilik = ? AND server_id = ? AND interface_name = ? LIMIT 1');
        api_bind($checkStmt, 'isis', [$vlanId, $ownerEsc, $serverId, $parentIf]);
        $checkStmt->execute();
        $existing = $checkStmt->get_result()->fetch_assoc();

        if ($existing) {
            $updStmt = $conn->prepare('UPDATE vlan
                       SET keterangan = ?, pool_id = ?, ip_gateway = ?, vlan_interface_name = ?,
                           status = ?, sync_source = ?, last_synced_at = NOW(), error_message = NULL
                       WHERE id = ?');
            $status = 'active';
            $syncSource = 'api_sync';
            api_bind($updStmt, 'sissssi', [$comment, $poolIdDetected, $ipGateway, $vlanIfName, $status, $syncSource, (int)$existing['id']]);
            if ($updStmt->execute()) {
                $totalVlanUpsert++;
            } else {
                $dbErrorCount++;
                $dbErrorLast = $updStmt->error;
            }
        } else {
            $insStmt = $conn->prepare('INSERT INTO vlan
                       (vlan_id, keterangan, pool_id, server_id, interface_name, ip_gateway, vlan_interface_name, status, sync_source, last_synced_at, error_message, pemilik)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NULL, ?)');
            $status = 'active';
            $syncSource = 'api_sync';
            api_bind($insStmt, 'isiisssss', [$vlanId, $comment, $poolIdDetected, $serverId, $parentIf, $ipGateway, $vlanIfName, $status, $syncSource, $ownerEsc]);
            if ($insStmt->execute()) {
                $totalVlanUpsert++;
            } else {
                $dbErrorCount++;
                $dbErrorLast = $insStmt->error;
            }
        }
    }

    return [
        'ok' => true,
        'total_api' => $totalRowsApi,
        'upserted' => $totalVlanUpsert,
        'skipped_invalid' => $skippedInvalid,
        'db_errors' => $dbErrorCount,
        'last_db_error' => $dbErrorLast,
    ];
}

// ------------------------------------------------------------------------------------------
// Routing
// ------------------------------------------------------------------------------------------

switch ($method) {

    // GET: list VLANs for servers in allowed_server_ids (join pool/server for display, same
    // columns vlan.php's web table shows). ?id= narrows to one row, ?server_id= filters by server.
    case 'GET':
        if (empty($allowed_server_ids)) {
            api_json(['success' => true, 'data' => [], 'total' => 0]);
        }

        $servers_in = implode(',', $allowed_server_ids);
        $where  = ["v.server_id IN ($servers_in)"];
        $types  = '';
        $params = [];

        if (!empty($_GET['id'])) {
            $where[] = 'v.id = ?';
            $types  .= 'i';
            $params[] = (int)$_GET['id'];
        }

        if (!empty($_GET['server_id'])) {
            $sid = (int)$_GET['server_id'];
            if (!in_array($sid, $allowed_server_ids, true)) {
                api_json(['success' => false, 'error' => 'Server tidak diizinkan untuk akun ini'], 403);
            }
            $where[] = 'v.server_id = ?';
            $types  .= 'i';
            $params[] = $sid;
        }

        if (!empty($_GET['status'])) {
            $where[] = 'v.status = ?';
            $types  .= 's';
            $params[] = (string)$_GET['status'];
        }

        $where_sql = implode(' AND ', $where);
        $sql = "SELECT v.id, v.vlan_id, v.keterangan, v.pool_id, v.server_id, v.interface_name,
                       v.ip_gateway, v.vlan_interface_name, v.status, v.sync_source,
                       v.last_synced_at, v.error_message, v.pemilik, v.created_at,
                       p.pool_name, p.ipawal, p.ipakhir,
                       s.AREA AS server_area, s.IP AS server_ip, s.BRAND AS server_brand
                FROM vlan v
                LEFT JOIN pool p ON p.id = v.pool_id
                LEFT JOIN server s ON s.id = v.server_id
                WHERE $where_sql
                ORDER BY v.id DESC";
        $stmt = $conn->prepare($sql);
        api_bind($stmt, $types, $params);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        api_json(['success' => true, 'data' => $data, 'total' => count($data)]);
        break;

    // POST: either ?action=sync (pull live VLANs from a server's router and upsert), or create
    // a new VLAN (push to RouterOS first, then insert - mirrors proses/add_vlan.php exactly).
    case 'POST':
        if ($action === 'sync') {
            $serverId = (int)($_GET['server_id'] ?? ($input['server_id'] ?? 0));
            $server = vlanapi_server_row($conn, $serverId, $allowed_server_ids);
            if (!$server) {
                api_json(['success' => false, 'error' => 'Server tidak ditemukan atau tidak diizinkan untuk akun ini.'], 404);
            }
            $result = vlanapi_sync_server($conn, $server);
            if (!$result['ok']) {
                api_json(['success' => false, 'error' => $result['error']], 502);
            }
            api_json(['success' => true, 'data' => [
                'server_id' => (int)$server['id'],
                'total_api' => $result['total_api'],
                'upserted' => $result['upserted'],
                'skipped_invalid' => $result['skipped_invalid'],
                'db_errors' => $result['db_errors'],
                'last_db_error' => $result['last_db_error'],
            ]]);
        }

        // --- Create (proses/add_vlan.php) ---
        $vlanId        = (int)($input['vlan_id'] ?? 0);
        $keterangan    = trim((string)($input['keterangan'] ?? ''));
        $withIp        = filter_var($input['with_ip'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $poolId        = (int)($input['pool_id'] ?? 0);
        $serverId      = (int)($input['server_id'] ?? 0);
        $interfaceName = trim((string)($input['interface_name'] ?? ''));

        if (!$withIp) {
            $poolId = 0;
        }

        // Same bounds/required-field checks as proses/add_vlan.php.
        if ($vlanId <= 0 || $vlanId > 4094 || $serverId <= 0 || $interfaceName === '' || ($withIp && $poolId <= 0)) {
            api_json(['success' => false, 'error' => 'Input VLAN tidak lengkap atau tidak valid.'], 400);
        }

        $server = vlanapi_server_row($conn, $serverId, $allowed_server_ids);
        if (!$server) {
            api_json(['success' => false, 'error' => 'Server tidak ditemukan untuk akun ini.'], 404);
        }
        $serverIp       = $server['IP'];
        $serverPassword = $server['PASSWORD'];
        $serverUser     = $server['PEMILIK']; // also the vlan.pemilik value, same convention as proses/add_vlan.php
        [$routerHost, $routerPort] = resolveVlanRouterEndpoint(trim((string)$serverIp));

        // Pool check (only when with_ip is requested).
        $ipAwal = '';
        $ipAkhir = '';
        $ipLocal = '';
        if ($withIp) {
            $poolRow = vlanapi_pool_row($conn, $poolId, $serverUser);
            if (!$poolRow) {
                api_json(['success' => false, 'error' => 'IP Pool tidak ditemukan untuk akun ini.'], 404);
            }
            $ipAwal  = $poolRow['ipawal'];
            $ipAkhir = $poolRow['ipakhir'];
            $ipLocal = trim((string)($poolRow['iplocal'] ?? ''));
        }

        // Duplicate check - same unique key as the DB (vlan_id, pemilik, server_id, interface_name).
        $dupStmt = $conn->prepare('SELECT id FROM vlan WHERE vlan_id = ? AND pemilik = ? AND server_id = ? AND interface_name = ? LIMIT 1');
        api_bind($dupStmt, 'isis', [$vlanId, $serverUser, $serverId, $interfaceName]);
        $dupStmt->execute();
        if ($dupStmt->get_result()->fetch_assoc()) {
            api_json(['success' => false, 'error' => 'VLAN sudah ada pada server dan interface tersebut.'], 409);
        }

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

        // Push to RouterOS - same command shapes as proses/add_vlan.php.
        $API = new RouterosAPI();
        try {
            if ($status !== 'failed') {
                if ($routerPort > 0) {
                    $API->port = $routerPort;
                }
                $connected = $API->connect($routerHost, $serverUser, $serverPassword);

                if ($connected) {
                    // Step 1: create VLAN interface
                    $createVlanCmd = [
                        'name' => $vlanInterfaceName,
                        'vlan-id' => (string)$vlanId,
                        'interface' => $interfaceName,
                    ];
                    $vlanResult = $API->comm('/interface/vlan/add', $createVlanCmd);
                    $vlanFailed = (is_array($vlanResult) && (isset($vlanResult['!trap']) || isset($vlanResult['!fatal'])));

                    if (!$vlanFailed) {
                        $status = 'active';

                        // Step 2: assign IP address (skipped if VLAN is activated without IP)
                        if ($withIp) {
                            $addIpCmd = [
                                'address' => $ipGateway,
                                'interface' => $vlanInterfaceName,
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

        // Insert regardless of outcome (same as proses/add_vlan.php - the row records the attempt,
        // status/error_message tell the truth; a failed push never gets status='active').
        $insertStmt = $conn->prepare('INSERT INTO vlan
            (vlan_id, keterangan, pool_id, server_id, interface_name, ip_gateway, vlan_interface_name, status, sync_source, last_synced_at, error_message, pemilik)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)');
        $poolIdParam = $poolId > 0 ? $poolId : null;
        $syncSource = 'manual_add';
        // 11 params: vlan_id(i) keterangan(s) pool_id(i) server_id(i) interface_name(s)
        //            ip_gateway(s) vlan_interface_name(s) status(s) sync_source(s) error_message(s) pemilik(s)
        $types = 'isiisssssss';
        $params = [$vlanId, $keterangan, $poolIdParam, $serverId, $interfaceName, $ipGateway, $vlanInterfaceName, $status, $syncSource, $errorMsg, $serverUser];
        api_bind($insertStmt, $types, $params);
        $insertOk = $insertStmt->execute();

        if (!$insertOk) {
            api_json(['success' => false, 'error' => 'Database error: ' . $insertStmt->error], 500);
        }

        $newId = $conn->insert_id;
        $rowData = vlanapi_fetch_row($conn, $newId);

        if ($status === 'active') {
            api_json(['success' => true, 'id' => $newId, 'data' => $rowData]);
        } elseif ($status === 'active_partial') {
            api_json(['success' => true, 'id' => $newId, 'warning' => $errorMsg, 'data' => $rowData]);
        } else {
            api_json(['success' => false, 'id' => $newId, 'error' => $errorMsg, 'data' => $rowData], 502);
        }
        break;

    // PUT/PATCH: update keterangan / ip_gateway (via pool_id) only. vlan_id, interface_name and
    // server_id are immutable once created (same rule vlan.php's edit modal enforces: "VLAN,
    // interface, dan server tidak bisa diubah di sini"). Re-pushes IP changes to RouterOS the
    // same way proses/edit_vlan.php does.
    case 'PUT':
    case 'PATCH':
        $id = (int)($input['id'] ?? 0);
        if (!$id) {
            api_json(['success' => false, 'error' => 'ID diperlukan'], 400);
        }
        foreach (['vlan_id', 'interface_name', 'server_id'] as $immutable) {
            if (array_key_exists($immutable, $input)) {
                api_json(['success' => false, 'error' => "$immutable tidak bisa diubah. Hapus lalu tambah ulang VLAN kalau perlu pindah interface/server."], 400);
            }
        }

        if (empty($allowed_server_ids)) {
            api_json(['success' => false, 'error' => 'VLAN tidak ditemukan untuk akun ini.'], 404);
        }
        $servers_in = implode(',', $allowed_server_ids);
        $oldStmt = $conn->prepare("SELECT v.id, v.vlan_id, v.pool_id, v.server_id, v.interface_name, v.keterangan,
                                           v.ip_gateway, v.vlan_interface_name,
                                           s.IP AS server_ip, s.PASSWORD AS server_password, s.PEMILIK AS server_user
                                    FROM vlan v
                                    LEFT JOIN server s ON s.id = v.server_id
                                    WHERE v.id = ? AND v.server_id IN ($servers_in)
                                    LIMIT 1");
        api_bind($oldStmt, 'i', [$id]);
        $oldStmt->execute();
        $old = $oldStmt->get_result()->fetch_assoc();
        if (!$old) {
            api_json(['success' => false, 'error' => 'VLAN tidak ditemukan untuk akun ini.'], 404);
        }

        $serverIp       = $old['server_ip'];
        $serverPassword = $old['server_password'];
        $serverUser     = $old['server_user'];
        if (empty($serverIp) || empty($serverUser)) {
            api_json(['success' => false, 'error' => 'Data server untuk VLAN ini tidak ditemukan.'], 500);
        }

        $keterangan = array_key_exists('keterangan', $input) ? trim((string)$input['keterangan']) : (string)$old['keterangan'];

        $oldPoolId    = (int)($old['pool_id'] ?? 0);
        $oldIpGateway = trim((string)($old['ip_gateway'] ?? ''));
        $hadIp        = $oldIpGateway !== '';
        $vlanInterfaceName = $old['vlan_interface_name'] ?: ('vlan' . $old['vlan_id']);

        // with_ip / pool_id default to the row's current state when omitted (partial update).
        $withIp = array_key_exists('with_ip', $input) ? filter_var($input['with_ip'], FILTER_VALIDATE_BOOLEAN) : $hadIp;
        $poolId = array_key_exists('pool_id', $input) ? (int)$input['pool_id'] : $oldPoolId;
        if (!$withIp) {
            $poolId = 0;
        }
        if ($withIp && $poolId <= 0) {
            api_json(['success' => false, 'error' => 'Input tidak lengkap atau tidak valid.'], 400);
        }

        $ipAwal = '';
        $ipAkhir = '';
        $ipLocal = '';
        if ($withIp) {
            $poolRow = vlanapi_pool_row($conn, $poolId, $serverUser);
            if (!$poolRow) {
                api_json(['success' => false, 'error' => 'IP Pool tidak ditemukan untuk akun ini.'], 404);
            }
            $ipAwal  = $poolRow['ipawal'];
            $ipAkhir = $poolRow['ipakhir'];
            $ipLocal = trim((string)($poolRow['iplocal'] ?? ''));
        }

        $poolChanged   = ($poolId !== $oldPoolId);
        $doRemoveOldIp = $hadIp && (!$withIp || $poolChanged);
        $doAddNewIp    = $withIp && (!$hadIp || $poolChanged);

        $status = 'active';
        $errorMsg = '';
        $newIpGateway = $withIp ? $oldIpGateway : '';

        if ($doAddNewIp) {
            $newIpGateway = deriveVlanGatewayIp($ipAwal, $ipAkhir, $ipLocal);
            if ($newIpGateway === '') {
                api_json(['success' => false, 'error' => 'Format IP gateway tidak valid. Periksa data pool (ipawal/ipakhir/iplocal).'], 400);
            }
        }

        if ($doRemoveOldIp || $doAddNewIp) {
            [$routerHost, $routerPort] = resolveVlanRouterEndpoint(trim((string)$serverIp));

            $API = new RouterosAPI();
            try {
                if ($routerPort > 0) {
                    $API->port = $routerPort;
                }
                $connected = $API->connect($routerHost, $serverUser, $serverPassword);

                if ($connected) {
                    if ($doRemoveOldIp) {
                        $existingIps = $API->comm('/ip/address/print', ['?interface' => $vlanInterfaceName]);
                        if (!empty($existingIps) && is_array($existingIps)) {
                            foreach ($existingIps as $ipEntry) {
                                if (isset($ipEntry['.id'])) {
                                    $API->comm('/ip/address/remove', ['.id' => $ipEntry['.id']]);
                                }
                            }
                        }
                    }

                    if ($doAddNewIp) {
                        $addIpCmd = [
                            'address' => $newIpGateway,
                            'interface' => $vlanInterfaceName,
                        ];
                        $ipResult = $API->comm('/ip/address/add', $addIpCmd);
                        $ipFailed = (is_array($ipResult) && (isset($ipResult['!trap']) || isset($ipResult['!fatal'])));
                        if ($ipFailed) {
                            $status = 'active_partial';
                            $trapMsg = '';
                            if (!empty($ipResult['!trap'][0]['message'])) {
                                $trapMsg = ' Detail: ' . $ipResult['!trap'][0]['message'];
                            }
                            $errorMsg = 'Gagal assign IP address baru.' . $trapMsg;
                            $newIpGateway = '';
                        }
                    }

                    $API->disconnect();
                } else {
                    $status = 'failed';
                    $errorMsg = 'Gagal terhubung ke RouterOS API. Cek endpoint ' . $routerHost . ':' . $routerPort . ', username, password server.';
                }
            } catch (Exception $e) {
                $status = 'failed';
                $errorMsg = 'Error RouterOS: ' . $e->getMessage();
            }
        }

        if (!$withIp) {
            $newIpGateway = '';
        }

        $poolIdParam = $poolId > 0 ? $poolId : null;
        $updStmt = $conn->prepare('UPDATE vlan
                      SET keterangan = ?, pool_id = ?, ip_gateway = ?, status = ?, error_message = ?, last_synced_at = NOW()
                      WHERE id = ?
                      LIMIT 1');
        api_bind($updStmt, 'sisssi', [$keterangan, $poolIdParam, $newIpGateway, $status, $errorMsg, $id]);
        $updOk = $updStmt->execute();
        if (!$updOk) {
            api_json(['success' => false, 'error' => 'Database error: ' . $updStmt->error], 500);
        }

        $rowData = vlanapi_fetch_row($conn, $id);
        if ($status === 'active') {
            api_json(['success' => true, 'data' => $rowData]);
        } elseif ($status === 'active_partial') {
            api_json(['success' => true, 'warning' => $errorMsg, 'data' => $rowData]);
        } else {
            api_json(['success' => false, 'error' => $errorMsg, 'data' => $rowData], 502);
        }
        break;

    // DELETE: DB row only, scoped to id + server ownership. proses/delete_vlan.php never removes
    // the VLAN interface from the router, so this doesn't either - matching that behavior exactly.
    case 'DELETE':
        $id = (int)($input['id'] ?? ($_GET['id'] ?? 0));
        if (!$id) {
            api_json(['success' => false, 'error' => 'ID diperlukan'], 400);
        }
        if (empty($allowed_server_ids)) {
            api_json(['success' => false, 'error' => 'VLAN tidak ditemukan atau tidak diizinkan'], 404);
        }
        $servers_in = implode(',', $allowed_server_ids);
        $checkRow = mysqli_query($conn, "SELECT v.id FROM vlan v WHERE v.id = " . (int)$id . " AND v.server_id IN ($servers_in) LIMIT 1");
        if (!$checkRow || mysqli_num_rows($checkRow) === 0) {
            api_json(['success' => false, 'error' => 'VLAN tidak ditemukan atau tidak diizinkan'], 404);
        }

        $delStmt = $conn->prepare('DELETE FROM vlan WHERE id = ? LIMIT 1');
        api_bind($delStmt, 'i', [$id]);
        $ok = $delStmt->execute();
        if (!$ok) {
            api_json(['success' => false, 'error' => 'Database error: ' . $delStmt->error], 500);
        }
        api_json(['success' => true]);
        break;

    default:
        api_json(['success' => false, 'error' => 'Method tidak didukung'], 405);
}
