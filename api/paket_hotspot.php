<?php
// api/paket_hotspot.php - Paket Hotspot API (Full CRUD)
//
// Mirrors crm/billing/packageshotspot.php + proses/{add,edit,delete}packageshotspot.php.
// Table `paket_hotspot` columns: id, paket, uptime, ratelimit, harga, komisi, area, pemilik, BRAND.
// Unlike `paket` (PPPoE), this table's own column names are lowercase (paket/area/pemilik) -
// only `BRAND` is uppercase, matching proses/addpackageshotspot.php's INSERT.
//
// IMPORTANT: the package-name column is `paket`, not `NAMA` - `paket_hotspot` has no `NAMA`
// column at all. The previous version of this file searched/inserted/updated a nonexistent
// `NAMA` column, which made every POST/PUT fail outright and made GET's search silently no-op.
//
// Data is scoped to PEMILIK values reachable via the servers owned by (or assigned to, for
// ASSISTANT accounts) the authenticated user.
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
api_require_module_enabled($conn, $pemilik, 'paket');

$allowedPemilik = api_allowed_pemilik_list($conn, $ctx);

/** Resolve/validate which pemilik a POST/PUT should write against. */
function ph_resolve_target_pemilik($input, $authPemilik, array $allowedPemilik) {
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

/** Best-effort BRAND auto-fill from the `server` table (PEMILIK+AREA). */
function ph_lookup_brand($conn, $pemilik, $area) {
    $stmt = $conn->prepare('SELECT BRAND FROM server WHERE PEMILIK = ? AND AREA = ? LIMIT 1');
    if (!$stmt) {
        return '';
    }
    api_bind($stmt, 'ss', [$pemilik, $area]);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ? (string)$row['BRAND'] : '';
}

switch ($method) {

    // GET: list paket_hotspot rows scoped to the pemilik values this account can act as.
    // Preserves the original search filter (now fixed to search `paket`, not `NAMA`), plus
    // optional id/area filters.
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
        if (!empty($_GET['area'])) {
            $where[] = 'area = ?';
            $types  .= 's';
            $params[] = (string)$_GET['area'];
        }

        $search = trim($_GET['search'] ?? '');
        if ($search !== '') {
            $like = '%' . $search . '%';
            $where[] = '(paket LIKE ? OR area LIKE ? OR harga LIKE ?)';
            $types  .= 'sss';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = implode(' AND ', $where);
        $stmt = $conn->prepare("SELECT * FROM paket_hotspot WHERE $where_sql ORDER BY id DESC");
        api_bind($stmt, $types, $params);
        $stmt->execute();
        $result = $stmt->get_result();
        $paket = [];
        while ($row = $result->fetch_assoc()) {
            $paket[] = $row;
        }
        api_json(['success' => true, 'data' => $paket]);
        break;

    // POST: create a paket_hotspot row (paket, uptime, ratelimit, harga, komisi, area, pemilik,
    // BRAND) - full column parity with proses/addpackageshotspot.php's INSERT. Also replicates
    // its duplicate-name guard (same pemilik + same paket name is rejected instead of silently
    // creating a second row for the same package).
    case 'POST':
        $paketName = trim((string)($input['paket'] ?? ($input['nama'] ?? '')));
        $uptime    = trim((string)($input['uptime'] ?? ''));
        $ratelimit = trim((string)($input['ratelimit'] ?? ''));
        $harga     = trim((string)($input['harga'] ?? ''));
        $area      = trim((string)($input['area'] ?? ''));
        $komisi    = isset($input['komisi']) && $input['komisi'] !== '' ? (string)$input['komisi'] : '0';
        $brandIn   = trim((string)($input['brand'] ?? ''));

        $missing = [];
        if ($paketName === '') $missing[] = 'paket (nama paket)';
        if ($harga === '') $missing[] = 'harga';
        if ($area === '') $missing[] = 'area';
        if (!empty($missing)) {
            api_json(['success' => false, 'error' => 'Data tidak lengkap: ' . implode(', ', $missing)]);
        }
        if (empty($allowedPemilik)) {
            api_json(['success' => false, 'error' => 'Tidak ada server/pemilik yang dikenali untuk akun ini']);
        }

        $targetPemilik = ph_resolve_target_pemilik($input, $pemilik, $allowedPemilik);
        if ($targetPemilik === null || $targetPemilik === '') {
            api_json(['success' => false, 'error' => 'pemilik tidak dikenali/tidak diizinkan untuk akun ini']);
        }

        // Duplicate guard: same paket name already exists for this pemilik (proses/addpackageshotspot.php skips
        // silently per-server; here we surface it as an explicit error since the API has no "skip" concept).
        $dup = $conn->prepare('SELECT id FROM paket_hotspot WHERE pemilik = ? AND paket = ? LIMIT 1');
        api_bind($dup, 'ss', [$targetPemilik, $paketName]);
        $dup->execute();
        if ($dup->get_result()->fetch_assoc()) {
            api_json(['success' => false, 'error' => 'Nama paket sudah digunakan untuk pemilik ini']);
        }

        $brand = $brandIn !== '' ? $brandIn : ph_lookup_brand($conn, $targetPemilik, $area);

        $stmt = $conn->prepare('INSERT INTO paket_hotspot (paket, uptime, ratelimit, harga, komisi, area, pemilik, BRAND) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        api_bind($stmt, 'ssssssss', [
            $paketName, $uptime, $ratelimit, $harga, $komisi, $area, $targetPemilik, $brand
        ]);
        $ok = $stmt->execute();
        if (!$ok) {
            api_json(['success' => false, 'error' => $stmt->error]);
        }

        $new_id = $conn->insert_id;
        $row = mysqli_query($conn, 'SELECT * FROM paket_hotspot WHERE id = ' . (int)$new_id)->fetch_assoc();
        api_json(['success' => true, 'id' => $new_id, 'data' => $row]);
        break;

    // PUT: update any of paket, uptime, ratelimit, harga, komisi, area, BRAND for a row owned
    // by this account. Only fields present in the request body are changed (pemilik itself is
    // never changed via PUT).
    case 'PUT':
        $id = (int)($input['id'] ?? 0);
        if (!$id) {
            api_json(['success' => false, 'error' => 'ID diperlukan']);
        }
        if (empty($allowedPemilik)) {
            api_json(['success' => false, 'error' => 'Paket tidak ditemukan atau tidak diizinkan']);
        }

        $pemilikInSql = api_pemilik_in_sql($conn, $allowedPemilik);
        $existing = mysqli_query($conn, "SELECT * FROM paket_hotspot WHERE id = $id AND pemilik IN ($pemilikInSql) LIMIT 1")->fetch_assoc();
        if (!$existing) {
            api_json(['success' => false, 'error' => 'Paket tidak ditemukan atau tidak diizinkan']);
        }

        if (array_key_exists('nama', $input) && !array_key_exists('paket', $input)) {
            $input['paket'] = $input['nama'];
        }

        $map = [
            'paket'     => 'paket',
            'uptime'    => 'uptime',
            'ratelimit' => 'ratelimit',
            'harga'     => 'harga',
            'komisi'    => 'komisi',
            'area'      => 'area',
            'brand'     => 'BRAND',
        ];

        // Duplicate guard on rename: if `paket` is changing, make sure no other row for the
        // same pemilik already uses the new name (mirrors editpackageshotspot.php's skip logic).
        if (array_key_exists('paket', $input)) {
            $newName = trim((string)$input['paket']);
            if ($newName === '') {
                api_json(['success' => false, 'error' => 'Nama paket tidak boleh kosong']);
            }
            if ($newName !== $existing['paket']) {
                $dup = $conn->prepare('SELECT id FROM paket_hotspot WHERE pemilik = ? AND paket = ? AND id <> ? LIMIT 1');
                api_bind($dup, 'ssi', [$existing['pemilik'], $newName, $id]);
                $dup->execute();
                if ($dup->get_result()->fetch_assoc()) {
                    api_json(['success' => false, 'error' => 'Nama paket sudah digunakan untuk pemilik ini']);
                }
            }
        }

        $fields = [];
        $types  = '';
        $params = [];
        foreach ($map as $key => $col) {
            if (array_key_exists($key, $input)) {
                $v = trim((string)$input[$key]);
                $fields[] = "$col = ?";
                $types   .= 's';
                $params[] = $v;
            }
        }

        if (empty($fields)) {
            api_json(['success' => false, 'error' => 'Tidak ada data untuk diupdate']);
        }

        $types .= 'i';
        $params[] = $id;
        $stmt = $conn->prepare('UPDATE paket_hotspot SET ' . implode(', ', $fields) . ' WHERE id = ?');
        api_bind($stmt, $types, $params);
        $ok = $stmt->execute();
        if (!$ok) {
            api_json(['success' => false, 'error' => $stmt->error]);
        }

        $row = mysqli_query($conn, "SELECT * FROM paket_hotspot WHERE id = $id")->fetch_assoc();
        api_json(['success' => true, 'data' => $row]);
        break;

    // DELETE: remove a paket_hotspot row, but only if it belongs to a pemilik this account owns.
    // proses/deletepackageshotspot.php's live guard checks the MikroTik hotspot profile for
    // active users; that live check isn't practical from here, so instead we do the equivalent
    // DB-level check used by proses/check_server_dependency.php: block delete if a `pelanggan`
    // (MODE='hotspot') row still references this exact paket+pemilik+area.
    case 'DELETE':
        $id = (int)($input['id'] ?? ($_GET['id'] ?? 0));
        if (!$id) {
            api_json(['success' => false, 'error' => 'ID diperlukan']);
        }
        if (empty($allowedPemilik)) {
            api_json(['success' => false, 'error' => 'Paket tidak ditemukan atau tidak diizinkan']);
        }

        $pemilikInSql = api_pemilik_in_sql($conn, $allowedPemilik);
        $existing = mysqli_query($conn, "SELECT * FROM paket_hotspot WHERE id = $id AND pemilik IN ($pemilikInSql) LIMIT 1")->fetch_assoc();
        if (!$existing) {
            api_json(['success' => false, 'error' => 'Paket tidak ditemukan atau tidak diizinkan']);
        }

        $cek = $conn->prepare("SELECT COUNT(*) AS jumlah FROM pelanggan WHERE PAKET = ? AND PEMILIK = ? AND AREA = ? AND MODE = 'hotspot'");
        api_bind($cek, 'sss', [$existing['paket'], $existing['pemilik'], $existing['area']]);
        $cek->execute();
        $jumlah = (int)($cek->get_result()->fetch_assoc()['jumlah'] ?? 0);
        if ($jumlah > 0) {
            api_json(['success' => false, 'error' => 'Paket masih digunakan oleh pelanggan hotspot, tidak dapat dihapus']);
        }

        $stmt = $conn->prepare('DELETE FROM paket_hotspot WHERE id = ?');
        api_bind($stmt, 'i', [$id]);
        $ok = $stmt->execute();
        api_json(['success' => (bool)$ok]);
        break;

    default:
        api_json(['success' => false, 'error' => 'Method tidak didukung'], 405);
}
