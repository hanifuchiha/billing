<?php
// api/ip_pool.php - IP Pool API (Full CRUD + MikroTik sync)
//
// Canonical endpoint for the `pool` table (kept at this filename because the Qbilling Android
// app hardcodes it - see IpPoolActivity.kt's API_URL constant). Consolidates and replaces the
// old api/pool.php (GET-only), api/create_ip_pool.php, api/delete_ip_pool.php, and
// api/ip_pool_list.php - those had inconsistent/missing auth (ip_pool_list.php took `nama`
// straight from $_GET with zero authentication; delete_ip_pool.php had no `pemilik` scoping on
// its DELETE at all, so any caller could delete any tenant's pool) and overlapping logic.
//
// Mirrors crm/billing/pool.php + proses/apply_pool.php + proses/delete_pool.php/delete_pool_bulk.php
// + proses/sync_pool.php (action=sync). Excel import/export/template download
// (proses/import_pool.php, export_pool.php, download_template_pool.php) are file-format
// conveniences outside plain CRUD/JSON scope and are intentionally not ported here.
//
// Response/request shapes are unchanged from the previous api/ip_pool.php so the existing
// Android client keeps working (GET returns id/pool_name/ipawal/ipakhir/iplocal/pemilik - the
// app's Gson model already declares `alternate` names for pool_id/ip_awal/ip_akhir/ip_local).
header('Content-Type: application/json');
require_once '../koneksibilling.php';
require_once '_bootstrap.php';
session_start();

api_cors();
$method = $_SERVER['REQUEST_METHOD'];
$input  = api_read_input();

$auth = api_authenticate($conn, $input);
$pemilik = $auth['pemilik'];
if ($auth['method'] === 'apikey') {
    api_rate_limit($conn, $auth['api_key']);
}

$ctx = api_resolve_owner($conn, $pemilik);
if (!$ctx) {
    api_json(['success' => false, 'error' => 'User tidak ditemukan'], 401);
}
api_require_module_enabled($conn, $pemilik, 'pool');

$allowedPemilik = api_allowed_pemilik_list($conn, $ctx);

/** Resolve/validate which PEMILIK a POST/PUT/sync should write against - same convention as api/paket.php. */
function pool_resolve_target_pemilik($input, $authPemilik, array $allowedPemilik) {
    $requested = trim((string)($input['pemilik'] ?? ''));
    if ($requested === '') {
        if (in_array($authPemilik, $allowedPemilik, true)) {
            return $authPemilik;
        }
        return $allowedPemilik[0] ?? '';
    }
    if (!in_array($requested, $allowedPemilik, true)) {
        return null;
    }
    return $requested;
}

function pool_cidr_to_range($cidr) {
    $parts = explode('/', $cidr);
    if (count($parts) !== 2) {
        return false;
    }
    $ip = $parts[0];
    $prefix = (int)$parts[1];
    if ($prefix < 0 || $prefix > 32) {
        return false;
    }
    $ipLong = ip2long($ip);
    if ($ipLong === false) {
        return false;
    }
    $mask = -1 << (32 - $prefix);
    $network = $ipLong & $mask;
    $broadcast = $network | (~$mask & 0xFFFFFFFF);
    $first = ($prefix < 31) ? $network + 1 : $network;
    $last  = ($prefix < 31) ? $broadcast - 1 : $broadcast;
    return [long2ip($first), long2ip($last)];
}

function pool_log_history($owner, $message) {
    $safeOwner = preg_replace('/[^A-Za-z0-9_.\-]/', '', (string)$owner);
    if ($safeOwner === '') {
        return;
    }
    $historyFile = __DIR__ . '/../notifbot/data/history-' . $safeOwner . '.json';
    $history = [];
    if (is_file($historyFile)) {
        $decoded = json_decode((string)file_get_contents($historyFile), true);
        if (is_array($decoded)) {
            $history = $decoded;
        }
    }
    $history[] = '[ api - ' . date('Y-m-d H:i:s') . ' ] ' . $message;
    @file_put_contents($historyFile, json_encode($history, JSON_PRETTY_PRINT));
}

/** action=sync: pull /ip/pool/print from a chosen MikroTik server (must be owned by this
 *  account) and upsert into `pool`, matched on pool_name+pemilik. Mirrors proses/sync_pool.php. */
function pool_handle_sync($conn, $ctx, $input) {
    $serverIp = trim((string)($input['server_ip'] ?? $input['sync_server'] ?? ''));
    if ($serverIp === '') {
        api_json(['success' => false, 'error' => 'server_ip wajib diisi']);
    }
    if (empty($ctx['allowed_server_ids'])) {
        api_json(['success' => false, 'error' => 'Tidak ada server yang dapat diakses untuk sync']);
    }

    $idIn = implode(',', $ctx['allowed_server_ids']);
    $srvRes = mysqli_query($conn, "SELECT * FROM server WHERE IP = '" . mysqli_real_escape_string($conn, $serverIp) . "' AND id IN ($idIn) LIMIT 1");
    $srv = $srvRes ? mysqli_fetch_assoc($srvRes) : null;
    if (!$srv) {
        api_json(['success' => false, 'error' => 'Server tidak ditemukan atau bukan milik akun ini'], 404);
    }

    $routerFile = realpath(__DIR__ . '/../routeros_api.class.php');
    if (!$routerFile) {
        api_json(['success' => false, 'error' => 'routeros_api.class.php tidak ditemukan']);
    }
    require_once $routerFile;

    $api = new RouterosAPI();
    $connected = false;
    try {
        $connected = @$api->connect($srv['IP'], $srv['PEMILIK'], $srv['PASSWORD']);
    } catch (Throwable $e) {
        api_json(['success' => false, 'error' => 'Exception saat koneksi ke MikroTik: ' . $e->getMessage()]);
    }
    if (!$connected) {
        api_json(['success' => false, 'error' => "Gagal terhubung ke MikroTik ({$srv['IP']})"]);
    }

    try {
        $pools = @$api->comm('/ip/pool/print');
    } catch (Throwable $e) {
        $api->disconnect();
        api_json(['success' => false, 'error' => 'Gagal mengambil data pool dari MikroTik: ' . $e->getMessage()]);
    }
    $api->disconnect();

    if (!is_array($pools) || count($pools) === 0) {
        api_json(['success' => false, 'error' => "Tidak ada IP Pool ditemukan di MikroTik ({$srv['IP']})"]);
    }

    $targetPemilik = (string)$srv['PEMILIK'];
    $inserted = 0;
    $updated  = 0;
    $skipped  = 0;
    $errors   = [];

    foreach ($pools as $p) {
        $poolName = trim((string)($p['name'] ?? ''));
        $ranges   = trim((string)($p['ranges'] ?? ''));
        if ($poolName === '' || $ranges === '') {
            $skipped++;
            continue;
        }
        $firstRange = trim(explode(',', $ranges)[0]);
        if (strpos($firstRange, '-') !== false) {
            [$ipawal, $ipakhir] = array_map('trim', explode('-', $firstRange, 2));
        } else {
            $ipawal  = $firstRange;
            $ipakhir = $firstRange;
        }

        $iplocal = '';
        $partsIp = explode('.', $ipawal);
        if (count($partsIp) === 4) {
            $long = ((int)$partsIp[0] << 24) + ((int)$partsIp[1] << 16) + ((int)$partsIp[2] << 8) + (int)$partsIp[3];
            $gatewayLong = $long - 1;
            if ($gatewayLong > 0) {
                $iplocal = implode('.', [($gatewayLong >> 24) & 255, ($gatewayLong >> 16) & 255, ($gatewayLong >> 8) & 255, $gatewayLong & 255]);
            }
        }

        $checkStmt = $conn->prepare('SELECT id FROM pool WHERE pool_name = ? AND pemilik = ? LIMIT 1');
        api_bind($checkStmt, 'ss', [$poolName, $targetPemilik]);
        $checkStmt->execute();
        $existing = $checkStmt->get_result()->fetch_assoc();

        if ($existing) {
            $upd = $conn->prepare('UPDATE pool SET ipawal=?, ipakhir=?, iplocal=? WHERE id=?');
            api_bind($upd, 'sssi', [$ipawal, $ipakhir, $iplocal, (int)$existing['id']]);
            if ($upd->execute()) {
                $updated++;
            } else {
                $skipped++;
                $errors[] = "Gagal update pool '$poolName': " . $upd->error;
            }
        } else {
            $ins = $conn->prepare('INSERT INTO pool (pool_name, ipawal, ipakhir, iplocal, pemilik) VALUES (?, ?, ?, ?, ?)');
            api_bind($ins, 'sssss', [$poolName, $ipawal, $ipakhir, $iplocal, $targetPemilik]);
            if ($ins->execute()) {
                $inserted++;
            } else {
                $skipped++;
                $errors[] = "Gagal insert pool '$poolName': " . $ins->error;
            }
        }
    }

    pool_log_history($targetPemilik, "Sync IP Pool dari MikroTik {$srv['IP']}: insert=$inserted, update=$updated, skip=$skipped");

    api_json([
        'success'  => true,
        'inserted' => $inserted,
        'updated'  => $updated,
        'skipped'  => $skipped,
        'errors'   => $errors,
    ]);
}

switch ($method) {

    // GET: list pool rows scoped to the PEMILIK values this account can act as.
    case 'GET':
        if (empty($allowedPemilik)) {
            api_json(['success' => true, 'data' => []]);
        }
        $pemilikInSql = api_pemilik_in_sql($conn, $allowedPemilik);
        $where  = ["pemilik IN ($pemilikInSql)"];
        $types  = '';
        $params = [];

        if (!empty($_GET['id'])) {
            $where[] = 'id = ?';
            $types  .= 'i';
            $params[] = (int)$_GET['id'];
        }
        if (!empty($_GET['search'])) {
            $like = '%' . $_GET['search'] . '%';
            $where[] = '(pool_name LIKE ? OR iplocal LIKE ?)';
            $types  .= 'ss';
            $params[] = $like;
            $params[] = $like;
        }

        $stmt = $conn->prepare('SELECT id, pool_name, ipawal, ipakhir, iplocal, pemilik FROM pool WHERE ' . implode(' AND ', $where) . ' ORDER BY pool_name ASC');
        api_bind($stmt, $types, $params);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        api_json(['success' => true, 'data' => $data]);
        break;

    // POST: create pool, OR action=sync to pull /ip/pool/print from a MikroTik server.
    case 'POST':
        $action = strtolower(trim((string)($input['action'] ?? '')));
        if ($action === 'sync') {
            pool_handle_sync($conn, $ctx, $input);
            break;
        }

        $poolName   = trim((string)($input['pool_name'] ?? $input['nama'] ?? ''));
        $localIp    = trim((string)($input['local_ip'] ?? $input['iplocal'] ?? ''));
        $rangeStart = trim((string)($input['range_start'] ?? $input['ipawal'] ?? ''));
        $rangeEnd   = trim((string)($input['range_end'] ?? $input['ipakhir'] ?? ''));

        if (strpos($rangeStart, '/') !== false) {
            $range = pool_cidr_to_range($rangeStart);
            if (!$range) {
                api_json(['success' => false, 'error' => 'Format subnet tidak valid']);
            }
            $rangeStart = $range[0];
            $rangeEnd   = $range[1];
        }

        if ($poolName === '' || $localIp === '' || $rangeStart === '' || $rangeEnd === '') {
            api_json(['success' => false, 'error' => 'Data tidak lengkap']);
        }
        foreach (['range_start' => $rangeStart, 'range_end' => $rangeEnd, 'local_ip' => $localIp] as $label => $ipCheck) {
            if (filter_var($ipCheck, FILTER_VALIDATE_IP) === false) {
                api_json(['success' => false, 'error' => "Format IP tidak valid pada $label"]);
            }
        }

        $targetPemilik = pool_resolve_target_pemilik($input, $pemilik, $allowedPemilik);
        if ($targetPemilik === null || $targetPemilik === '') {
            api_json(['success' => false, 'error' => 'pemilik tidak valid/tidak diizinkan untuk akun ini'], 403);
        }

        $stmtDup = $conn->prepare('SELECT id FROM pool WHERE pool_name = ? AND pemilik = ? LIMIT 1');
        api_bind($stmtDup, 'ss', [$poolName, $targetPemilik]);
        $stmtDup->execute();
        if ($stmtDup->get_result()->num_rows > 0) {
            api_json(['success' => false, 'error' => 'Nama pool sudah ada']);
        }

        // Numeric IP overlap check (INET_ATON) - more correct than the old string BETWEEN check.
        $stmtOverlap = $conn->prepare('SELECT id FROM pool WHERE pemilik = ? AND (
            INET_ATON(?) BETWEEN INET_ATON(ipawal) AND INET_ATON(ipakhir) OR
            INET_ATON(?) BETWEEN INET_ATON(ipawal) AND INET_ATON(ipakhir) OR
            INET_ATON(ipawal) BETWEEN INET_ATON(?) AND INET_ATON(?) OR
            INET_ATON(ipakhir) BETWEEN INET_ATON(?) AND INET_ATON(?)
        ) LIMIT 1');
        api_bind($stmtOverlap, 'sssssss', [$targetPemilik, $rangeStart, $rangeEnd, $rangeStart, $rangeEnd, $rangeStart, $rangeEnd]);
        $stmtOverlap->execute();
        if ($stmtOverlap->get_result()->num_rows > 0) {
            api_json(['success' => false, 'error' => 'IP range atau IP lokal sudah digunakan oleh pool lain']);
        }

        $stmt = $conn->prepare('INSERT INTO pool (pool_name, ipawal, ipakhir, iplocal, pemilik) VALUES (?, ?, ?, ?, ?)');
        api_bind($stmt, 'sssss', [$poolName, $rangeStart, $rangeEnd, $localIp, $targetPemilik]);
        $ok = $stmt->execute();
        if ($ok) {
            pool_log_history($targetPemilik, "Berhasil menambahkan pool $poolName");
        }
        api_json(['success' => $ok, 'id' => $ok ? $conn->insert_id : null, 'error' => $ok ? null : $stmt->error]);
        break;

    // PUT: update pool, scoped to allowed PEMILIK.
    case 'PUT':
        $id         = (int)($input['id'] ?? 0);
        $poolName   = trim((string)($input['pool_name'] ?? $input['nama'] ?? ''));
        $localIp    = trim((string)($input['local_ip'] ?? $input['iplocal'] ?? ''));
        $rangeStart = trim((string)($input['range_start'] ?? $input['ipawal'] ?? ''));
        $rangeEnd   = trim((string)($input['range_end'] ?? $input['ipakhir'] ?? ''));

        if (!$id || $poolName === '' || $localIp === '' || $rangeStart === '' || $rangeEnd === '') {
            api_json(['success' => false, 'error' => 'Data tidak lengkap']);
        }
        if (empty($allowedPemilik)) {
            api_json(['success' => false, 'error' => 'Pool tidak ditemukan atau tidak diizinkan'], 404);
        }

        $pemilikInSql = api_pemilik_in_sql($conn, $allowedPemilik);
        $stmt = $conn->prepare("UPDATE pool SET pool_name=?, ipawal=?, ipakhir=?, iplocal=? WHERE id=? AND pemilik IN ($pemilikInSql)");
        api_bind($stmt, 'ssssi', [$poolName, $rangeStart, $rangeEnd, $localIp, $id]);
        $ok = $stmt->execute();
        if ($ok && $stmt->affected_rows === 0) {
            api_json(['success' => false, 'error' => 'Pool tidak ditemukan atau tidak diizinkan'], 404);
        }
        api_json(['success' => $ok, 'error' => $ok ? null : $stmt->error]);
        break;

    // DELETE: remove pool, blocked if still referenced by a paket's LOCAL column (matches
    // proses/delete_pool.php's guard), scoped to allowed PEMILIK - the old delete_ip_pool.php had
    // no ownership check at all, so any caller could delete any tenant's pool; fixed here.
    case 'DELETE':
        $id = (int)($input['id'] ?? ($_GET['id'] ?? 0));
        if (!$id) {
            api_json(['success' => false, 'error' => 'ID tidak ditemukan']);
        }
        if (empty($allowedPemilik)) {
            api_json(['success' => false, 'error' => 'Pool tidak ditemukan atau tidak diizinkan'], 404);
        }
        $pemilikInSql = api_pemilik_in_sql($conn, $allowedPemilik);
        $findRes = mysqli_query($conn, "SELECT iplocal FROM pool WHERE id=$id AND pemilik IN ($pemilikInSql) LIMIT 1");
        $poolRow = $findRes ? mysqli_fetch_assoc($findRes) : null;
        if (!$poolRow) {
            api_json(['success' => false, 'error' => 'Pool tidak ditemukan atau tidak diizinkan'], 404);
        }

        $stmtUsage = $conn->prepare("SELECT COUNT(*) AS cnt FROM paket WHERE LOCAL LIKE CONCAT('%', ?, '%')");
        api_bind($stmtUsage, 's', [$poolRow['iplocal']]);
        $stmtUsage->execute();
        $usageCount = (int)($stmtUsage->get_result()->fetch_assoc()['cnt'] ?? 0);
        if ($usageCount > 0) {
            api_json(['success' => false, 'error' => 'IP Pool masih digunakan oleh paket, tidak dapat dihapus']);
        }

        $stmt = $conn->prepare("DELETE FROM pool WHERE id=? AND pemilik IN ($pemilikInSql)");
        api_bind($stmt, 'i', [$id]);
        $ok = $stmt->execute();
        api_json(['success' => $ok, 'error' => $ok ? null : $stmt->error]);
        break;

    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method tidak didukung']);
}
