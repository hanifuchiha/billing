<?php
// api/olt.php - OLT (Optical Line Terminal) API, full CRUD parity with crm/billing/olt.php +
// proses/editolt.php (Add and Delete are handled inline inside crm/billing/olt.php itself;
// only Edit posts to a separate proses/editolt.php handler).
//
// `olt` table columns - confirmed from crm/billing/petunjuk_instal/Mybillingq.sql's base
// CREATE TABLE plus crm/billing/olt.php's own defensive runtime ALTER TABLE for the two SNMP
// community columns:
//   id, pemilik, area, ipolt, oltname, usernameolt, passwordolt, brandolt,
//   community_read, community_write
// There is no PORT/GPS/server_id/vlan column anywhere on this table - verified against the base
// schema dump, crm/billing/olt.php's add/edit forms, proses/editolt.php, proses/import_olt.php
// and proses/export_olt.php. All of those agree on exactly the 8 base + 2 SNMP columns above.
//
// Scoping intentionally mirrors crm/billing/olt.php's own rule (NOT the generic
// api_allowed_pemilik_list() PEMILIK-only pattern used by api/odp.php): ASSISTANT accounts are
// scoped by AREA IN (assistant's own assigned area list, from user.server), while regular
// accounts are scoped by PEMILIK IN (server.PEMILIK WHERE server.user_id = current user id).
// That's exactly what crm/billing/olt.php's own listing query does, so it's kept as-is here
// rather than swapped for _bootstrap.php's owner-scoping helpers.
//
// Secondary resource (?resource=template): olt_input_templates, the per-OLT auto-registration
// template crm/billing/olt.php manages on its "Setting Template OLT" tab. Only the fields that
// tab actually writes (ont_type, tcont_profile, vlan_id, service_name, vlan_profile, gemport,
// cos, ethuni, ont_sn/vlan_manual) are exposed here - the table has many more columns but the
// real web UI never populates them, so there is nothing to mirror for those.
require_once '../koneksibilling.php';
require_once '_bootstrap.php';
session_start();
api_cors();

$method = $_SERVER['REQUEST_METHOD'];
$input = api_read_input();

$auth = api_authenticate($conn, $input);
$pemilik = $auth['pemilik'];
if ($auth['method'] === 'apikey') {
    api_rate_limit($conn, $auth['api_key']);
}

// ---------------------------------------------------------------------------
// Defensive schema migration - same two columns crm/billing/olt.php ALTERs in at runtime, plus
// the olt_input_templates table it CREATE TABLE IF NOT EXISTS's.
// ---------------------------------------------------------------------------
function olt_ensure_column($conn, $column_name, $definition_sql) {
    $safe_col = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$column_name);
    if ($safe_col === '') {
        return;
    }
    $check = mysqli_query($conn, "SHOW COLUMNS FROM olt LIKE '" . mysqli_real_escape_string($conn, $safe_col) . "'");
    if ($check && mysqli_num_rows($check) > 0) {
        return;
    }
    mysqli_query($conn, "ALTER TABLE olt ADD COLUMN " . $definition_sql);
}
olt_ensure_column($conn, 'community_read', "community_read VARCHAR(255) DEFAULT NULL");
olt_ensure_column($conn, 'community_write', "community_write VARCHAR(255) DEFAULT NULL");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS olt_input_templates (
    olt_id INT NOT NULL PRIMARY KEY,
    auth_mode VARCHAR(50) DEFAULT NULL,
    customer_name VARCHAR(200) DEFAULT NULL,
    address TEXT DEFAULT NULL,
    provinsi VARCHAR(120) DEFAULT NULL,
    kabupaten VARCHAR(120) DEFAULT NULL,
    kecamatan VARCHAR(120) DEFAULT NULL,
    kelurahan VARCHAR(120) DEFAULT NULL,
    rw VARCHAR(10) DEFAULT NULL,
    rt VARCHAR(10) DEFAULT NULL,
    whatsapp VARCHAR(50) DEFAULT NULL,
    email VARCHAR(200) DEFAULT NULL,
    coordinates VARCHAR(120) DEFAULT NULL,
    sales VARCHAR(120) DEFAULT NULL,
    tipe_bayar VARCHAR(30) DEFAULT NULL,
    tipe_tempo VARCHAR(60) DEFAULT NULL,
    tanggal_pasang DATE DEFAULT NULL,
    package_name VARCHAR(120) DEFAULT NULL,
    odp VARCHAR(120) DEFAULT NULL,
    auto_register_enabled TINYINT(1) DEFAULT 1,
    with_wan_config TINYINT(1) DEFAULT 1,
    ont_type VARCHAR(120) DEFAULT NULL,
    ont_interface VARCHAR(80) DEFAULT NULL,
    onu_number VARCHAR(20) DEFAULT NULL,
    ont_sn VARCHAR(120) DEFAULT NULL,
    tcont_profile VARCHAR(120) DEFAULT NULL,
    vlan_id VARCHAR(20) DEFAULT NULL,
    service_name VARCHAR(60) DEFAULT NULL,
    vlan_profile VARCHAR(60) DEFAULT NULL,
    gemport VARCHAR(20) DEFAULT NULL,
    cos VARCHAR(20) DEFAULT NULL,
    ethuni VARCHAR(50) DEFAULT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by VARCHAR(120) DEFAULT NULL,
    CONSTRAINT fk_olt_input_templates_olt FOREIGN KEY (olt_id) REFERENCES olt(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ---------------------------------------------------------------------------
// Scope helpers (kept from the previous version of this file - already correct web parity,
// see the header comment for why this differs from _bootstrap.php's generic helpers).
// ---------------------------------------------------------------------------
function build_in_clause_olt($conn, $values) {
    $safe = [];
    foreach ($values as $v) {
        $v = trim((string)$v);
        if ($v === '') continue;
        $safe[] = "'" . mysqli_real_escape_string($conn, $v) . "'";
    }
    if (empty($safe)) {
        return "''";
    }
    return implode(',', array_values(array_unique($safe)));
}

function get_scope_olt($conn, $pemilik) {
    $userId = 0;
    $userStatus = '';
    $userServerJson = '';
    $stmtUser = $conn->prepare("SELECT id, STATUS, server FROM user WHERE USERNAME=? LIMIT 1");
    if ($stmtUser) {
        $stmtUser->bind_param('s', $pemilik);
        $stmtUser->execute();
        $resUser = $stmtUser->get_result();
        if ($resUser && $resUser->num_rows > 0) {
            $userRow = $resUser->fetch_assoc();
            $userId = (int)($userRow['id'] ?? 0);
            $userStatus = strtoupper(trim((string)($userRow['STATUS'] ?? '')));
            $userServerJson = (string)($userRow['server'] ?? '');
        }
    }

    $owners = [];
    $areas = [];

    if ($userStatus === 'ASSISTANT') {
        $serverIds = json_decode($userServerJson, true);
        $serverIds = is_array($serverIds) ? array_values(array_filter(array_map('intval', $serverIds))) : [];
        if (!empty($serverIds)) {
            $idIn = implode(',', $serverIds);
            $resSrv = mysqli_query($conn, "SELECT DISTINCT AREA, PEMILIK FROM server WHERE id IN ($idIn)");
            while ($resSrv && ($row = mysqli_fetch_assoc($resSrv))) {
                if (!empty($row['AREA'])) {
                    $areas[$row['AREA']] = true;
                }
                if (!empty($row['PEMILIK'])) {
                    $owners[$row['PEMILIK']] = true;
                }
            }
        }
    }

    if ($userId > 0) {
        // Web parity (non-assistant): owner scope strictly from server.user_id.
        $stmtSrv = $conn->prepare("SELECT DISTINCT PEMILIK, AREA FROM server WHERE user_id=?");
        if ($stmtSrv) {
            $stmtSrv->bind_param('i', $userId);
            $stmtSrv->execute();
            $resSrv = $stmtSrv->get_result();
            while ($resSrv && ($row = $resSrv->fetch_assoc())) {
                $owner = trim((string)($row['PEMILIK'] ?? ''));
                $area = trim((string)($row['AREA'] ?? ''));
                if ($owner !== '') {
                    $owners[$owner] = true;
                }
                if ($area !== '') {
                    $areas[$area] = true;
                }
            }
        }
    }

    if (empty($owners)) {
        // Keep last-resort compatibility if server mapping is missing.
        $owners[$pemilik] = true;
    }

    return [
        'user_status' => $userStatus,
        'owners' => array_keys($owners),
        'areas' => array_keys($areas),
        'owner_in' => build_in_clause_olt($conn, array_keys($owners)),
        'area_in' => build_in_clause_olt($conn, array_keys($areas)),
    ];
}

function can_access_owner_olt($owner, $scopeOwners) {
    $owner = trim((string)$owner);
    if ($owner === '') {
        return false;
    }
    foreach ($scopeOwners as $v) {
        if (trim((string)$v) === $owner) {
            return true;
        }
    }
    return false;
}

/** Mode + SQL fragment (no leading AND) for scoping an "olt"-aliased or bare table query. */
function olt_scope_condition($scope, $alias = '') {
    $prefix = $alias !== '' ? $alias . '.' : '';
    if ($scope['user_status'] === 'ASSISTANT' && !empty($scope['areas'])) {
        return ['mode' => 'assistant_area', 'sql' => "{$prefix}area IN ({$scope['area_in']})"];
    }
    return ['mode' => 'owner_list', 'sql' => "{$prefix}pemilik IN ({$scope['owner_in']})"];
}

/** True if OLT id $oltId is within this caller's scope. */
function olt_is_accessible($conn, $scope, $oltId) {
    $cond = olt_scope_condition($scope);
    $stmt = $conn->prepare("SELECT id FROM olt WHERE id = ? AND {$cond['sql']} LIMIT 1");
    api_bind($stmt, 'i', [(int)$oltId]);
    $stmt->execute();
    return (bool)$stmt->get_result()->fetch_assoc();
}

// Allowed brand values - matches proses/editolt.php's $allowed_brands exactly (current option
// list from crm/billing/olt.php's $oltBrandOptions plus legacy/fallback values editolt.php
// still accepts for older rows).
$ALLOWED_BRANDS = [
    'HIOSO EPON HA7302CST', 'HIOSO EPON HA7304', 'HIOSO EPON HA7308',
    'ZTE GPON C300', 'ZTE GPON C320', 'ZTE GPON C600',
    'HUAWEI MA5608T', 'HUAWEI MA5680T', 'HUAWEI MA5683T',
    'VSOL GPON V1600G0/G1', 'VSOL EPON V1600D',
    'HSGQ GPON G02/G04/G08/G16', 'HSGQ EPON E04/E08',
    'CDATA GPON FD1601S', 'CDATA EPON FD1104/FD1208',
    'HIOSO EPON', 'ZTE GPON C300/320', 'CDATA GPON', 'CDATA GPON SNMP LEGACY',
    'VSOL GPON', 'VSOL EPON', 'HSGQ GPON', 'HSGQ EPON',
    'HUAWEI GPON', 'ZTE GPON', 'FIBERHOME GPON', 'NOKIA GPON', 'CDATA', 'EPON LAIN', 'GPON LAIN',
];

/** IP(+PORT) format check, mirrors proses/editolt.php's validation. Returns error string or null. */
function olt_validate_ip_format($ipolt) {
    $parts = explode(':', $ipolt);
    $ipPart = $parts[0];
    if (!filter_var($ipPart, FILTER_VALIDATE_IP)) {
        return 'Format alamat IP tidak valid';
    }
    if (isset($parts[1]) && $parts[1] !== '') {
        $port = $parts[1];
        if (!is_numeric($port) || (int)$port < 1 || (int)$port > 65535) {
            return 'Port harus berupa angka antara 1-65535';
        }
    }
    return null;
}

/** Global ipolt duplicate check, mirrors proses/editolt.php exactly (no owner scoping there). */
function olt_ip_taken($conn, $ipolt, $excludeId = null) {
    if ($excludeId !== null) {
        $stmt = $conn->prepare("SELECT id FROM olt WHERE ipolt = ? AND id != ? LIMIT 1");
        api_bind($stmt, 'si', [$ipolt, (int)$excludeId]);
    } else {
        $stmt = $conn->prepare("SELECT id FROM olt WHERE ipolt = ? LIMIT 1");
        api_bind($stmt, 's', [$ipolt]);
    }
    $stmt->execute();
    return (bool)$stmt->get_result()->fetch_assoc();
}

$resource = strtolower(trim((string)($input['resource'] ?? ($_GET['resource'] ?? 'olt'))));

// =============================================================================================
// Secondary resource: olt_input_templates (?resource=template)
// =============================================================================================
if ($resource === 'template') {

    switch ($method) {

        // GET ?resource=template            -> list templates for every OLT in scope
        // GET ?resource=template&olt_id=N   -> single template row (data:null if unset)
        case 'GET':
            $scope = get_scope_olt($conn, $pemilik);
            $cond = olt_scope_condition($scope, 'o');
            $oltId = (int)($_GET['olt_id'] ?? 0);

            if ($oltId > 0) {
                if (!olt_is_accessible($conn, $scope, $oltId)) {
                    api_json(['success' => false, 'error' => 'OLT tidak ditemukan atau tidak diizinkan'], 404);
                }
                $stmt = $conn->prepare("SELECT olt_id, ont_type, tcont_profile, vlan_id, service_name, vlan_profile,
                    gemport, cos, ethuni, ont_sn AS vlan_manual, updated_at, updated_by
                    FROM olt_input_templates WHERE olt_id = ? LIMIT 1");
                api_bind($stmt, 'i', [$oltId]);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                api_json(['success' => true, 'data' => $row ?: null]);
            }

            $sql = "SELECT o.id AS olt_id, o.oltname, o.brandolt, o.area, o.pemilik,
                t.ont_type, t.tcont_profile, t.vlan_id, t.service_name, t.vlan_profile,
                t.gemport, t.cos, t.ethuni, t.ont_sn AS vlan_manual, t.updated_at, t.updated_by
                FROM olt o LEFT JOIN olt_input_templates t ON t.olt_id = o.id
                WHERE {$cond['sql']} ORDER BY o.area, o.oltname";
            $result = mysqli_query($conn, $sql);
            $data = [];
            while ($result && ($row = mysqli_fetch_assoc($result))) {
                $data[] = $row;
            }
            api_json(['success' => true, 'data' => $data, 'total' => count($data)]);
            break;

        // POST/PUT ?resource=template - upsert, mirrors crm/billing/olt.php's
        // save_olt_template handler (INSERT ... ON DUPLICATE KEY UPDATE) at lines ~62-130,
        // just via a bound prepared statement instead of manual string escaping.
        case 'POST':
        case 'PUT':
            $oltId = (int)($input['olt_id'] ?? ($input['template_olt_id'] ?? 0));
            if ($oltId <= 0) {
                api_json(['success' => false, 'error' => 'olt_id wajib diisi']);
            }
            $scope = get_scope_olt($conn, $pemilik);
            if (!olt_is_accessible($conn, $scope, $oltId)) {
                api_json(['success' => false, 'error' => 'OLT tidak ditemukan atau tidak diizinkan'], 404);
            }

            $ont_type = trim((string)($input['ont_type'] ?? ''));
            $tcont_profile = trim((string)($input['tcont_profile'] ?? ''));
            $vlan_manual = trim((string)($input['vlan_manual'] ?? ''));
            $vlan_id_in = trim((string)($input['vlan_id'] ?? ''));
            $service_name = trim((string)($input['service_name'] ?? ''));
            $vlan_profile = trim((string)($input['vlan_profile'] ?? ''));
            $gemport = trim((string)($input['gemport'] ?? ''));
            $cos = trim((string)($input['cos'] ?? ''));
            $ethuni = trim((string)($input['ethuni'] ?? ''));
            $vlan_id_final = $vlan_manual !== '' ? $vlan_manual : $vlan_id_in;
            $updated_by = (string)$pemilik;

            $stmt = $conn->prepare("INSERT INTO olt_input_templates
                (olt_id, ont_type, tcont_profile, vlan_id, service_name, vlan_profile, gemport, cos, ethuni, updated_by, ont_sn)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    ont_type = VALUES(ont_type),
                    tcont_profile = VALUES(tcont_profile),
                    vlan_id = VALUES(vlan_id),
                    service_name = VALUES(service_name),
                    vlan_profile = VALUES(vlan_profile),
                    gemport = VALUES(gemport),
                    cos = VALUES(cos),
                    ethuni = VALUES(ethuni),
                    ont_sn = VALUES(ont_sn),
                    updated_by = VALUES(updated_by),
                    updated_at = CURRENT_TIMESTAMP");
            api_bind($stmt, 'isssssssss', [
                $oltId, $ont_type, $tcont_profile, $vlan_id_final, $service_name, $vlan_profile,
                $gemport, $cos, $ethuni, $updated_by, $vlan_manual,
            ]);
            $ok = $stmt->execute();
            if (!$ok) {
                api_json(['success' => false, 'error' => 'Gagal menyimpan template: ' . $stmt->error]);
            }

            $row = mysqli_query($conn, "SELECT olt_id, ont_type, tcont_profile, vlan_id, service_name, vlan_profile,
                gemport, cos, ethuni, ont_sn AS vlan_manual, updated_at, updated_by
                FROM olt_input_templates WHERE olt_id = " . (int)$oltId)->fetch_assoc();
            api_json(['success' => true, 'data' => $row]);
            break;

        // DELETE ?resource=template - mirrors delete_olt_template's access check
        // (EXISTS server-ownership for normal accounts, AREA IN for ASSISTANT) then delete.
        case 'DELETE':
            $oltId = (int)($input['olt_id'] ?? ($input['template_olt_id'] ?? ($_GET['olt_id'] ?? 0)));
            if ($oltId <= 0) {
                api_json(['success' => false, 'error' => 'olt_id wajib diisi']);
            }
            $scope = get_scope_olt($conn, $pemilik);
            if (!olt_is_accessible($conn, $scope, $oltId)) {
                api_json(['success' => false, 'error' => 'OLT tidak ditemukan atau tidak diizinkan'], 404);
            }
            $stmt = $conn->prepare("DELETE FROM olt_input_templates WHERE olt_id = ?");
            api_bind($stmt, 'i', [$oltId]);
            $ok = $stmt->execute();
            api_json(['success' => (bool)$ok]);
            break;

        default:
            api_json(['success' => false, 'error' => 'Method tidak didukung'], 405);
    }
    exit;
}

// =============================================================================================
// Primary resource: olt (the OLT device rows themselves)
// =============================================================================================
switch ($method) {

    // GET: list (scoped exactly like crm/billing/olt.php's own listing query - see header
    // comment) or single-row detail via ?id=.
    case 'GET':
        $scope = get_scope_olt($conn, $pemilik);
        $cond = olt_scope_condition($scope);

        if (!empty($_GET['id'])) {
            $id = (int)$_GET['id'];
            $stmt = $conn->prepare("SELECT * FROM olt WHERE id = ? AND {$cond['sql']} LIMIT 1");
            api_bind($stmt, 'i', [$id]);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if (!$row) {
                api_json(['success' => false, 'error' => 'OLT tidak ditemukan atau tidak diizinkan'], 404);
            }
            api_json(['success' => true, 'data' => $row]);
        }

        $where = [$cond['sql']];
        $types = '';
        $params = [];

        // Optional search across the fields the web page's client-side search box covers
        // (OLT name, area, product/pemilik) - not present server-side on the real page (its
        // search is pure client-side JS filtering of the already-rendered table), added here
        // only as an API convenience so large fleets don't have to pull every row every time.
        $search = trim((string)($_GET['search'] ?? ($_GET['q'] ?? '')));
        if ($search !== '') {
            $like = '%' . $search . '%';
            $where[] = "(oltname LIKE ? OR area LIKE ? OR pemilik LIKE ? OR ipolt LIKE ?)";
            $types .= 'ssss';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $sql = "SELECT * FROM olt WHERE " . implode(' AND ', $where) . " ORDER BY area, oltname";
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

    // POST: create a new OLT. Required fields mirror crm/billing/olt.php's inline add-form
    // handler exactly: ipolt, oltname, server(pemilik), area. brandolt/usernameolt/passwordolt
    // are NOT required server-side there either (only client-side `required` attributes), so
    // they stay optional here too - but if a brandolt IS supplied it's validated against the
    // known brand list, and the ip+port format is checked (a safety improvement over the real
    // add handler, which performs no server-side IP validation at all).
    case 'POST':
        $nama = trim((string)($input['oltname'] ?? ($input['nama'] ?? '')));
        $ipolt = trim((string)($input['ipolt'] ?? ''));
        $area = trim((string)($input['area'] ?? ''));
        $targetOwner = trim((string)($input['pemilik'] ?? ($input['server'] ?? '')));
        $brandolt = trim((string)($input['brandolt'] ?? ($input['brand'] ?? '')));
        $usernameolt = trim((string)($input['usernameolt'] ?? ''));
        $passwordolt = trim((string)($input['passwordolt'] ?? ''));
        $communityRead = trim((string)($input['community_read'] ?? ''));
        $communityWrite = trim((string)($input['community_write'] ?? ''));

        if ($nama === '' || $ipolt === '' || $area === '' || $targetOwner === '') {
            api_json(['success' => false, 'error' => 'Data tidak lengkap: ipolt, oltname, server/pemilik, dan area wajib diisi']);
        }

        $ipErr = olt_validate_ip_format($ipolt);
        if ($ipErr) {
            api_json(['success' => false, 'error' => $ipErr]);
        }

        if ($brandolt !== '' && !in_array($brandolt, $ALLOWED_BRANDS, true)) {
            api_json(['success' => false, 'error' => 'Brand OLT tidak valid: ' . $brandolt]);
        }
        if ($brandolt === 'CDATA GPON' && $communityRead === '') {
            api_json(['success' => false, 'error' => 'Community Read wajib diisi untuk brand CDATA GPON']);
        }

        $scope = get_scope_olt($conn, $pemilik);
        if (!can_access_owner_olt($targetOwner, $scope['owners'])) {
            api_json(['success' => false, 'error' => 'Akses pemilik/product tidak diizinkan']);
        }

        $stmt = $conn->prepare("INSERT INTO olt (oltname, ipolt, area, pemilik, brandolt, usernameolt, passwordolt, community_read, community_write) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        api_bind($stmt, 'sssssssss', [$nama, $ipolt, $area, $targetOwner, $brandolt, $usernameolt, $passwordolt, $communityRead, $communityWrite]);
        $ok = $stmt->execute();
        if (!$ok) {
            api_json(['success' => false, 'error' => 'Gagal menyimpan data: ' . $stmt->error]);
        }

        $newId = $conn->insert_id;
        $row = mysqli_query($conn, "SELECT * FROM olt WHERE id = " . (int)$newId)->fetch_assoc();
        api_json(['success' => true, 'id' => $newId, 'data' => $row]);
        break;

    // PUT: update an OLT owned by this account. Fields omitted from the request body keep
    // their existing value (merged with the existing row first, then the FULL merged result
    // is validated exactly like proses/editolt.php validates its full form submission -
    // required fields, brand whitelist, ip/port format, CDATA community_read requirement, and
    // a global ipolt-uniqueness check across all OLTs, same as proses/editolt.php).
    case 'PUT':
        $id = (int)($input['id'] ?? 0);
        if (!$id) {
            api_json(['success' => false, 'error' => 'ID diperlukan']);
        }
        $scope = get_scope_olt($conn, $pemilik);
        $cond = olt_scope_condition($scope);
        $stmt = $conn->prepare("SELECT * FROM olt WHERE id = ? AND {$cond['sql']} LIMIT 1");
        api_bind($stmt, 'i', [$id]);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        if (!$existing) {
            api_json(['success' => false, 'error' => 'OLT tidak ditemukan atau tidak diizinkan'], 404);
        }

        $nama = array_key_exists('oltname', $input) ? trim((string)$input['oltname'])
            : (array_key_exists('nama', $input) ? trim((string)$input['nama']) : (string)$existing['oltname']);
        $ipolt = array_key_exists('ipolt', $input) ? trim((string)$input['ipolt']) : (string)$existing['ipolt'];
        $area = array_key_exists('area', $input) ? trim((string)$input['area']) : (string)$existing['area'];
        $targetOwner = array_key_exists('pemilik', $input) ? trim((string)$input['pemilik'])
            : (array_key_exists('server', $input) ? trim((string)$input['server']) : (string)$existing['pemilik']);
        $brandolt = array_key_exists('brandolt', $input) ? trim((string)$input['brandolt'])
            : (array_key_exists('brand', $input) ? trim((string)$input['brand']) : (string)$existing['brandolt']);
        $usernameolt = array_key_exists('usernameolt', $input) ? trim((string)$input['usernameolt']) : (string)$existing['usernameolt'];
        $passwordolt = array_key_exists('passwordolt', $input) ? trim((string)$input['passwordolt']) : (string)$existing['passwordolt'];
        $communityRead = array_key_exists('community_read', $input) ? trim((string)$input['community_read']) : (string)($existing['community_read'] ?? '');
        $communityWrite = array_key_exists('community_write', $input) ? trim((string)$input['community_write']) : (string)($existing['community_write'] ?? '');

        if ($nama === '' || $ipolt === '' || $area === '' || $targetOwner === '') {
            api_json(['success' => false, 'error' => 'Data tidak lengkap: ipolt, oltname, server/pemilik, dan area wajib diisi']);
        }
        if ($brandolt === '') {
            api_json(['success' => false, 'error' => 'Brand OLT wajib dipilih']);
        }
        if (!in_array($brandolt, $ALLOWED_BRANDS, true)) {
            api_json(['success' => false, 'error' => 'Brand OLT tidak valid: ' . $brandolt]);
        }
        if ($usernameolt === '') {
            api_json(['success' => false, 'error' => 'Username OLT wajib diisi']);
        }
        if ($passwordolt === '') {
            api_json(['success' => false, 'error' => 'Password OLT wajib diisi']);
        }

        $ipErr = olt_validate_ip_format($ipolt);
        if ($ipErr) {
            api_json(['success' => false, 'error' => $ipErr]);
        }
        if ($brandolt === 'CDATA GPON' && $communityRead === '') {
            api_json(['success' => false, 'error' => 'Community Read wajib diisi untuk brand CDATA GPON']);
        }
        if (olt_ip_taken($conn, $ipolt, $id)) {
            api_json(['success' => false, 'error' => 'Alamat IP sudah digunakan oleh OLT lain']);
        }

        if (!can_access_owner_olt($targetOwner, $scope['owners'])) {
            api_json(['success' => false, 'error' => 'Akses pemilik/product tidak diizinkan']);
        }

        $sql = "UPDATE olt SET oltname=?, ipolt=?, area=?, pemilik=?, brandolt=?, usernameolt=?, passwordolt=?, community_read=?, community_write=? WHERE id=? AND {$cond['sql']}";
        $stmt = $conn->prepare($sql);
        api_bind($stmt, 'sssssssssi', [$nama, $ipolt, $area, $targetOwner, $brandolt, $usernameolt, $passwordolt, $communityRead, $communityWrite, $id]);
        $ok = $stmt->execute();
        if (!$ok) {
            api_json(['success' => false, 'error' => 'Gagal update: ' . $stmt->error]);
        }

        $row = mysqli_query($conn, "SELECT * FROM olt WHERE id = " . (int)$id)->fetch_assoc();
        api_json(['success' => true, 'data' => $row]);
        break;

    // DELETE: remove an OLT. crm/billing/olt.php's own delete handler
    // (`isset($_GET['delete_id'])`, twice in that file) is a completely unguarded
    // `DELETE FROM olt WHERE id = $delete_id` - no ownership scoping, no usage/dependency
    // check of any kind, and it is not even the same pattern apiinterface.php's old delete_olt
    // used (that one at least checked `pemilik IN ($server_list)` first). There is NO
    // dependency guard anywhere in the real app for OLT deletion - nothing blocks deleting an
    // OLT that still has, e.g., an olt_input_templates row (that row cascades away via the
    // table's own `ON DELETE CASCADE` FK) or is referenced by name/IP from other tooling.
    // Per the task instructions this is NOT invented here either; what IS kept is the
    // ownership/scope check (matching apiinterface.php's old delete_olt, and simply sound
    // multi-tenant authorization - a caller should never be able to delete another tenant's
    // OLT row even though the raw web page itself has no such check).
    case 'DELETE':
        $id = (int)($input['id'] ?? ($_GET['id'] ?? 0));
        if (!$id) {
            api_json(['success' => false, 'error' => 'ID diperlukan']);
        }
        $scope = get_scope_olt($conn, $pemilik);
        $cond = olt_scope_condition($scope);
        $stmt = $conn->prepare("SELECT id FROM olt WHERE id = ? AND {$cond['sql']} LIMIT 1");
        api_bind($stmt, 'i', [$id]);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) {
            api_json(['success' => false, 'error' => 'OLT tidak ditemukan atau tidak diizinkan'], 404);
        }

        $stmt = $conn->prepare("DELETE FROM olt WHERE id = ? AND {$cond['sql']}");
        api_bind($stmt, 'i', [$id]);
        $ok = $stmt->execute();
        if (!$ok) {
            api_json(['success' => false, 'error' => 'Gagal menghapus OLT: ' . $stmt->error]);
        }

        // Defensive cleanup: the olt_input_templates FK is ON DELETE CASCADE, but on
        // installs where that table/FK was never created (e.g. crm/billing/olt.php has never
        // been opened yet), nothing would remove a leftover template row. Harmless no-op
        // otherwise since the FK already took care of it.
        $delTpl = $conn->prepare("DELETE FROM olt_input_templates WHERE olt_id = ?");
        if ($delTpl) {
            api_bind($delTpl, 'i', [$id]);
            $delTpl->execute();
        }

        api_json(['success' => true]);
        break;

    default:
        api_json(['success' => false, 'error' => 'Method tidak didukung'], 405);
}
