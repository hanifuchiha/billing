<?php
// api/paket.php - Paket PPPoE API (Full CRUD)
//
// Mirrors crm/billing/packages.php + proses/{add,edit,delete}packagespppoe.php.
// Table `paket` columns: id, PAKET, KODE, KECEPATAN, LOCAL, REMOTE, HARGA, komisi, AREA, PEMILIK, BRAND.
//
// IMPORTANT: the package-name column is `PAKET`, not `NAMA` - `paket` has no `NAMA` column at all.
// The previous version of this file inserted/updated a nonexistent `NAMA` column, which made every
// POST/PUT fail outright ("Unknown column 'NAMA' in 'field list'").
//
// Data is scoped to PEMILIK values reachable via the servers owned by (or assigned to, for
// ASSISTANT accounts) the authenticated user - never to the raw session/login PEMILIK alone,
// since an ASSISTANT can be scoped across several owner accounts' servers.
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

/** Resolve/validate which PEMILIK a POST/PUT should write against. */
function pk_resolve_target_pemilik($input, $authPemilik, array $allowedPemilik) {
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

/** Best-effort BRAND auto-fill from the `server` table (PEMILIK+AREA), same convention used by
 *  proses/import_paket.php ("Brand opsional, otomatis ikut data server"). */
function pk_lookup_brand($conn, $pemilik, $area) {
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

    // GET: list paket rows scoped to the PEMILIK values this account can act as.
    // Optional filters: id, area, search (matches PAKET/KECEPATAN/KODE).
    case 'GET':
        if (empty($allowedPemilik)) {
            api_json(['success' => true, 'data' => []]);
        }

        $pemilikInSql = api_pemilik_in_sql($conn, $allowedPemilik);
        $where  = ["PEMILIK IN ($pemilikInSql)"];
        $types  = '';
        $params = [];

        if (!empty($_GET['id'])) {
            $where[] = 'id = ?';
            $types  .= 'i';
            $params[] = (int)$_GET['id'];
        }
        if (!empty($_GET['area'])) {
            $where[] = 'AREA = ?';
            $types  .= 's';
            $params[] = (string)$_GET['area'];
        }
        if (!empty($_GET['search'])) {
            $like = '%' . $_GET['search'] . '%';
            $where[] = '(PAKET LIKE ? OR KECEPATAN LIKE ? OR KODE LIKE ?)';
            $types  .= 'sss';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = implode(' AND ', $where);
        $stmt = $conn->prepare("SELECT * FROM paket WHERE $where_sql ORDER BY id DESC");
        api_bind($stmt, $types, $params);
        $stmt->execute();
        $result = $stmt->get_result();
        $paket = [];
        while ($row = $result->fetch_assoc()) {
            $paket[] = $row;
        }
        api_json(['success' => true, 'data' => $paket]);
        break;

    // POST: create a paket row (PAKET, KODE, KECEPATAN, LOCAL, REMOTE, HARGA, komisi, AREA,
    // PEMILIK, BRAND) - full column parity with proses/addpackagespppoe.php's INSERT.
    case 'POST':
        $paketName = trim((string)($input['paket'] ?? ($input['nama'] ?? '')));
        $kecepatan = trim((string)($input['kecepatan'] ?? ($input['ratelimit'] ?? '')));
        $harga     = trim((string)($input['harga'] ?? ''));
        $local     = trim((string)($input['local'] ?? ''));
        $remote    = trim((string)($input['remote'] ?? ($input['remot'] ?? '')));
        $area      = trim((string)($input['area'] ?? ''));
        $kode      = trim((string)($input['kode'] ?? '')) !== '' ? trim((string)$input['kode']) : '-';
        $komisi    = isset($input['komisi']) && $input['komisi'] !== '' ? (string)$input['komisi'] : '0';
        $brandIn   = trim((string)($input['brand'] ?? ''));

        $missing = [];
        if ($paketName === '') $missing[] = 'paket (nama paket)';
        if ($kecepatan === '') $missing[] = 'kecepatan';
        if ($harga === '') $missing[] = 'harga';
        if ($area === '') $missing[] = 'area';
        if ($local === '') $missing[] = 'local';
        if ($remote === '') $missing[] = 'remote';
        if (!empty($missing)) {
            api_json(['success' => false, 'error' => 'Data tidak lengkap: ' . implode(', ', $missing)]);
        }
        if (empty($allowedPemilik)) {
            api_json(['success' => false, 'error' => 'Tidak ada server/PEMILIK yang dikenali untuk akun ini']);
        }

        $targetPemilik = pk_resolve_target_pemilik($input, $pemilik, $allowedPemilik);
        if ($targetPemilik === null || $targetPemilik === '') {
            api_json(['success' => false, 'error' => 'pemilik tidak dikenali/tidak diizinkan untuk akun ini']);
        }

        $brand = $brandIn !== '' ? $brandIn : pk_lookup_brand($conn, $targetPemilik, $area);

        $stmt = $conn->prepare('INSERT INTO paket (PAKET, KODE, KECEPATAN, LOCAL, REMOTE, HARGA, komisi, AREA, PEMILIK, BRAND) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        api_bind($stmt, 'ssssssssss', [
            $paketName, $kode, $kecepatan, $local, $remote, $harga, $komisi, $area, $targetPemilik, $brand
        ]);
        $ok = $stmt->execute();
        if (!$ok) {
            api_json(['success' => false, 'error' => $stmt->error]);
        }

        $new_id = $conn->insert_id;
        $row = mysqli_query($conn, 'SELECT * FROM paket WHERE id = ' . (int)$new_id)->fetch_assoc();
        api_json(['success' => true, 'id' => $new_id, 'data' => $row]);
        break;

    // PUT: update any of PAKET, KODE, KECEPATAN, LOCAL, REMOTE, HARGA, komisi, AREA, BRAND for
    // a row owned by this account. Only fields present in the request body are changed (PEMILIK
    // itself is never changed via PUT - use dedicated tooling/DB access to move ownership).
    case 'PUT':
        $id = (int)($input['id'] ?? 0);
        if (!$id) {
            api_json(['success' => false, 'error' => 'ID diperlukan']);
        }
        if (empty($allowedPemilik)) {
            api_json(['success' => false, 'error' => 'Paket tidak ditemukan atau tidak diizinkan']);
        }

        $pemilikInSql = api_pemilik_in_sql($conn, $allowedPemilik);
        $existing = mysqli_query($conn, "SELECT * FROM paket WHERE id = $id AND PEMILIK IN ($pemilikInSql) LIMIT 1")->fetch_assoc();
        if (!$existing) {
            api_json(['success' => false, 'error' => 'Paket tidak ditemukan atau tidak diizinkan']);
        }

        $map = [
            'paket'     => 'PAKET',
            'kode'      => 'KODE',
            'kecepatan' => 'KECEPATAN',
            'local'     => 'LOCAL',
            'remote'    => 'REMOTE',
            'harga'     => 'HARGA',
            'komisi'    => 'komisi',
            'area'      => 'AREA',
            'brand'     => 'BRAND',
        ];
        // Accept a couple of legacy/alias keys too.
        if (array_key_exists('nama', $input) && !array_key_exists('paket', $input)) {
            $input['paket'] = $input['nama'];
        }
        if (array_key_exists('ratelimit', $input) && !array_key_exists('kecepatan', $input)) {
            $input['kecepatan'] = $input['ratelimit'];
        }
        if (array_key_exists('remot', $input) && !array_key_exists('remote', $input)) {
            $input['remote'] = $input['remot'];
        }

        $fields = [];
        $types  = '';
        $params = [];
        foreach ($map as $key => $col) {
            if (array_key_exists($key, $input)) {
                $v = trim((string)$input[$key]);
                if ($key === 'paket' && $v === '') {
                    api_json(['success' => false, 'error' => 'Nama paket tidak boleh kosong']);
                }
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
        $stmt = $conn->prepare('UPDATE paket SET ' . implode(', ', $fields) . ' WHERE id = ?');
        api_bind($stmt, $types, $params);
        $ok = $stmt->execute();
        if (!$ok) {
            api_json(['success' => false, 'error' => $stmt->error]);
        }

        $row = mysqli_query($conn, "SELECT * FROM paket WHERE id = $id")->fetch_assoc();
        api_json(['success' => true, 'data' => $row]);
        break;

    // DELETE: remove a paket row, but only if it belongs to a PEMILIK this account owns AND no
    // `pelanggan` row still references it (mirrors proses/deletepackages.php's guard, scoped by
    // PAKET+PEMILIK+AREA so deleting on one server doesn't get blocked by a same-named package
    // installed on a different server).
    case 'DELETE':
        $id = (int)($input['id'] ?? ($_GET['id'] ?? 0));
        if (!$id) {
            api_json(['success' => false, 'error' => 'ID diperlukan']);
        }
        if (empty($allowedPemilik)) {
            api_json(['success' => false, 'error' => 'Paket tidak ditemukan atau tidak diizinkan']);
        }

        $pemilikInSql = api_pemilik_in_sql($conn, $allowedPemilik);
        $existing = mysqli_query($conn, "SELECT * FROM paket WHERE id = $id AND PEMILIK IN ($pemilikInSql) LIMIT 1")->fetch_assoc();
        if (!$existing) {
            api_json(['success' => false, 'error' => 'Paket tidak ditemukan atau tidak diizinkan']);
        }

        $cek = $conn->prepare('SELECT COUNT(*) AS jumlah FROM pelanggan WHERE PAKET = ? AND PEMILIK = ? AND AREA = ?');
        api_bind($cek, 'sss', [$existing['PAKET'], $existing['PEMILIK'], $existing['AREA']]);
        $cek->execute();
        $jumlah = (int)($cek->get_result()->fetch_assoc()['jumlah'] ?? 0);
        if ($jumlah > 0) {
            api_json(['success' => false, 'error' => 'Paket masih digunakan oleh pelanggan, tidak dapat dihapus']);
        }

        $stmt = $conn->prepare('DELETE FROM paket WHERE id = ?');
        api_bind($stmt, 'i', [$id]);
        $ok = $stmt->execute();
        api_json(['success' => (bool)$ok]);
        break;

    default:
        api_json(['success' => false, 'error' => 'Method tidak didukung'], 405);
}
