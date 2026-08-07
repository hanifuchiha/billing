<?php
// api/server_area.php - Server Area API (PEMILIK + AREA management)
// Full CRUD (GET/POST/PUT/DELETE), scoped to servers owned by (or assigned to, for ASSISTANT
// accounts) the authenticated user - a request can never read/write another owner's server row.
// This endpoint only manages the `PEMILIK`/`AREA` columns of `server` (see api/README.md); use
// api/server.php for full server records (IP, credentials, etc).
require_once '../koneksibilling.php';
require_once '_bootstrap.php';
session_start();
api_cors();

$method = $_SERVER['REQUEST_METHOD'];

$input = api_read_input();
$auth  = api_authenticate($conn, $input);
if ($auth['method'] === 'apikey') {
    api_rate_limit($conn, $auth['api_key']);
}

$ctx = api_resolve_owner($conn, $auth['pemilik']);
if (!$ctx) {
    api_json(['success' => false, 'error' => 'User tidak ditemukan'], 401);
}
api_require_module_enabled($conn, $auth['pemilik'], 'area_server');
$allowed_server_ids = $ctx['allowed_server_ids'];

switch ($method) {

    // GET: list server PEMILIK/AREA rows, scoped to servers this account owns/is-assigned-to.
    // Optional ?id= narrows to a single row (still scoped to allowed ids).
    case 'GET':
        if (empty($allowed_server_ids)) {
            api_json(['success' => true, 'data' => []]);
        }

        $servers_in = implode(',', $allowed_server_ids);
        $where  = ["id IN ($servers_in)"];
        $types  = '';
        $params = [];

        if (!empty($_GET['id'])) {
            $where[] = 'id = ?';
            $types  .= 'i';
            $params[] = (int)$_GET['id'];
        }

        $where_sql = implode(' AND ', $where);
        $stmt = $conn->prepare("SELECT id, PEMILIK, AREA FROM server WHERE $where_sql");
        api_bind($stmt, $types, $params);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        api_json(['success' => true, 'data' => $data]);
        break;

    // POST: create a new server row (PEMILIK + AREA), tied to this account (user_id).
    case 'POST':
        $pemilik = trim((string)($input['PEMILIK'] ?? ''));
        $area    = trim((string)($input['AREA'] ?? ''));
        if ($pemilik === '' || $area === '') {
            api_json(['success' => false, 'error' => 'PEMILIK dan AREA wajib diisi'], 400);
        }

        $stmt = $conn->prepare('INSERT INTO server (PEMILIK, AREA, user_id) VALUES (?, ?, ?)');
        api_bind($stmt, 'ssi', [$pemilik, $area, $ctx['owner_user_id']]);
        $ok = $stmt->execute();
        if (!$ok) {
            api_json(['success' => false, 'error' => $stmt->error], 500);
        }

        $new_id = $conn->insert_id;
        api_json(['success' => true, 'id' => $new_id, 'data' => ['id' => $new_id, 'PEMILIK' => $pemilik, 'AREA' => $area]]);
        break;

    // PUT/PATCH: update PEMILIK/AREA on a row, only if it belongs to this account.
    case 'PUT':
    case 'PATCH':
        $id = (int)($input['id'] ?? 0);
        if (!$id) {
            api_json(['success' => false, 'error' => 'ID diperlukan'], 400);
        }
        if (empty($allowed_server_ids) || !in_array($id, $allowed_server_ids, true)) {
            api_json(['success' => false, 'error' => 'Server tidak ditemukan atau tidak diizinkan'], 404);
        }

        $fields = [];
        $types  = '';
        $params = [];

        if (array_key_exists('PEMILIK', $input)) {
            $v = trim((string)$input['PEMILIK']);
            if ($v === '') {
                api_json(['success' => false, 'error' => 'PEMILIK tidak boleh kosong'], 400);
            }
            $fields[] = 'PEMILIK = ?';
            $types   .= 's';
            $params[] = $v;
        }
        if (array_key_exists('AREA', $input)) {
            $v = trim((string)$input['AREA']);
            if ($v === '') {
                api_json(['success' => false, 'error' => 'AREA tidak boleh kosong'], 400);
            }
            $fields[] = 'AREA = ?';
            $types   .= 's';
            $params[] = $v;
        }

        if (empty($fields)) {
            api_json(['success' => false, 'error' => 'Tidak ada data untuk diupdate'], 400);
        }

        $types .= 'i';
        $params[] = $id;
        $stmt = $conn->prepare('UPDATE server SET ' . implode(', ', $fields) . ' WHERE id = ?');
        api_bind($stmt, $types, $params);
        $ok = $stmt->execute();
        if (!$ok) {
            api_json(['success' => false, 'error' => $stmt->error], 500);
        }

        $row = mysqli_query($conn, "SELECT id, PEMILIK, AREA FROM server WHERE id = $id")->fetch_assoc();
        api_json(['success' => true, 'data' => $row]);
        break;

    // DELETE: remove a row, only if it belongs to this account.
    case 'DELETE':
        $id = (int)($input['id'] ?? ($_GET['id'] ?? 0));
        if (!$id) {
            api_json(['success' => false, 'error' => 'ID diperlukan'], 400);
        }
        if (empty($allowed_server_ids) || !in_array($id, $allowed_server_ids, true)) {
            api_json(['success' => false, 'error' => 'Server tidak ditemukan atau tidak diizinkan'], 404);
        }

        $stmt = $conn->prepare('DELETE FROM server WHERE id = ?');
        api_bind($stmt, 'i', [$id]);
        $ok = $stmt->execute();
        if (!$ok) {
            api_json(['success' => false, 'error' => $stmt->error], 500);
        }
        api_json(['success' => true]);
        break;

    default:
        api_json(['success' => false, 'error' => 'Method not allowed'], 405);
}
