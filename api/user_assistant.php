<?php
// api/user_assistant.php - ASSISTANT sub-account management API.
//
// Full CRUD (GET/POST/PUT/DELETE) over `user` rows where STATUS='ASSISTANT', mirroring the
// ASSISTANT list/add/edit/delete flows in crm/billing/user.php (list ~line 730, add ~298-360,
// edit ~363-439, delete ~804-813). Every operation is scoped to ASSISTANT rows whose `grup`
// equals the caller's own owner id - a request can never see/create/edit/delete a sub-account
// belonging to a different owner.
//
// Only OWNER accounts (STATUS <> 'ASSISTANT') may call this endpoint: an ASSISTANT managing
// other ASSISTANT accounts doesn't make sense here, same restriction the web page enforces by
// only rendering the "Daftar ASSISTANT" management card for non-ASSISTANT accounts.
require_once '../koneksibilling.php';
require_once '_bootstrap.php';
session_start();
api_cors();

$input = api_read_input();
$auth = api_authenticate($conn, $input);
$pemilik = $auth['pemilik'];
if ($auth['method'] === 'apikey') {
    api_rate_limit($conn, $auth['api_key']);
}

$ctx = api_resolve_owner($conn, $pemilik);
if (!$ctx) {
    api_json(['success' => false, 'error' => 'User tidak ditemukan'], 401);
}
api_require_module_enabled($conn, $pemilik, 'user_assistant');
if ($ctx['is_assistant']) {
    api_json(['success' => false, 'error' => 'Akun ASSISTANT tidak diizinkan mengelola sub-akun ASSISTANT lain'], 403);
}

$owner_id = (int)$ctx['owner_user_id'];
$method = $_SERVER['REQUEST_METHOD'];

/** Decode a `server`/`akses` JSON column into a plain array (empty array if invalid/empty). */
function ua_decode_json_array($raw) {
    $decoded = json_decode((string)$raw, true);
    return is_array($decoded) ? array_values($decoded) : [];
}

/** Strip PASWORD (hash) from a row before it's ever sent back over the API, decode JSON cols. */
function ua_sanitize_row($row) {
    if (!is_array($row)) {
        return $row;
    }
    unset($row['PASWORD']);
    $row['server'] = ua_decode_json_array($row['server'] ?? '');
    $row['akses'] = ua_decode_json_array($row['akses'] ?? '');
    return $row;
}

/** Normalize a `server` (ids) input array and reject anything outside the owner's own servers. */
function ua_validate_servers(array $serverInput, array $allowedServerIds) {
    $servers = [];
    foreach ($serverInput as $sid) {
        $sid = (int)$sid;
        if (!in_array($sid, $allowedServerIds, true)) {
            api_json(['success' => false, 'error' => "server id $sid tidak diizinkan untuk akun ini"], 400);
        }
        $servers[] = $sid;
    }
    return array_values(array_unique($servers));
}

/** Normalize an `akses`/`menu` (permission key) input array - accepted generically, as-is. */
function ua_validate_akses(array $aksesInput) {
    $akses = array_map('strval', $aksesInput);
    $akses = array_values(array_unique(array_filter($akses, function ($v) {
        return trim($v) !== '';
    })));
    return $akses;
}

/** USERNAME is the global login identifier (api_check_credentials looks it up unscoped), so it
 *  must stay unique across the whole `user` table, not just within this owner's sub-accounts. */
function ua_username_taken($conn, $username, $excludeId = 0) {
    if ($excludeId > 0) {
        $stmt = $conn->prepare('SELECT id FROM user WHERE USERNAME = ? AND id <> ? LIMIT 1');
        api_bind($stmt, 'si', [$username, $excludeId]);
    } else {
        $stmt = $conn->prepare('SELECT id FROM user WHERE USERNAME = ? LIMIT 1');
        api_bind($stmt, 's', [$username]);
    }
    $stmt->execute();
    return (bool)$stmt->get_result()->fetch_assoc();
}

switch ($method) {

    // GET: list all ASSISTANT rows under this owner, or a single row via ?id=. PASWORD is never
    // returned. `server`/`akses` are decoded from their JSON columns into plain arrays.
    case 'GET':
        if (!empty($_GET['id'])) {
            $id = (int)$_GET['id'];
            $stmt = $conn->prepare("SELECT * FROM user WHERE id = ? AND STATUS = 'ASSISTANT' AND grup = ? LIMIT 1");
            api_bind($stmt, 'ii', [$id, $owner_id]);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if (!$row) {
                api_json(['success' => false, 'error' => 'ASSISTANT tidak ditemukan atau tidak diizinkan'], 404);
            }
            api_json(['success' => true, 'data' => ua_sanitize_row($row)]);
        }

        $stmt = $conn->prepare("SELECT * FROM user WHERE STATUS = 'ASSISTANT' AND grup = ? ORDER BY id DESC");
        api_bind($stmt, 'i', [$owner_id]);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = ua_sanitize_row($row);
        }
        api_json(['success' => true, 'data' => $data, 'total' => count($data)]);
        break;

    // POST: create a new ASSISTANT sub-account under this owner.
    // Required: username, password. Optional: nowa, email (-> domain), server[] (subset of the
    // owner's own servers), akses[]/menu[] (permission keys, stored as-is). inisial is inherited
    // from the owner's own row, STATUS/grup are fixed, saldo starts at 0, created_at = NOW().
    case 'POST':
        $username = trim((string)($input['username'] ?? ''));
        $password = (string)($input['password'] ?? '');
        if ($username === '' || $password === '') {
            api_json(['success' => false, 'error' => 'username dan password wajib diisi'], 400);
        }
        if (ua_username_taken($conn, $username)) {
            api_json(['success' => false, 'error' => 'Username sudah digunakan'], 409);
        }

        $nowa = trim((string)($input['nowa'] ?? ''));
        $email = trim((string)($input['email'] ?? ''));

        $servers = [];
        if (isset($input['server']) && is_array($input['server'])) {
            $servers = ua_validate_servers($input['server'], $ctx['allowed_server_ids']);
        }

        $aksesInput = $input['akses'] ?? ($input['menu'] ?? []);
        $akses = is_array($aksesInput) ? ua_validate_akses($aksesInput) : [];

        // inisial is inherited from the owner's own user row, never client-supplied.
        $ownerStmt = $conn->prepare('SELECT inisial FROM user WHERE id = ? LIMIT 1');
        api_bind($ownerStmt, 'i', [$owner_id]);
        $ownerStmt->execute();
        $ownerRow = $ownerStmt->get_result()->fetch_assoc();
        $inisial = $ownerRow ? (string)$ownerRow['inisial'] : '';

        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $server_json = json_encode($servers);
        $akses_json = json_encode($akses);

        $stmt = $conn->prepare("INSERT INTO user
            (USERNAME, PASWORD, STATUS, grup, NOWA, saldo, server, domain, inisial, akses, created_at)
            VALUES (?, ?, 'ASSISTANT', ?, ?, 0, ?, ?, ?, ?, NOW())");
        api_bind($stmt, 'ssisssss', [$username, $hashed, $owner_id, $nowa, $server_json, $email, $inisial, $akses_json]);
        $ok = $stmt->execute();
        if (!$ok) {
            api_json(['success' => false, 'error' => $stmt->error], 500);
        }

        $new_id = $conn->insert_id;
        $row = mysqli_query($conn, 'SELECT * FROM user WHERE id = ' . (int)$new_id)->fetch_assoc();
        api_json(['success' => true, 'id' => $new_id, 'data' => ua_sanitize_row($row)]);
        break;

    // PUT: update an existing ASSISTANT row belonging to this owner. Only fields present in the
    // request body are changed (except STATUS/grup, which are always re-asserted as invariants,
    // matching user.php's edit_user flow). Password is only re-hashed if a new non-empty value
    // is supplied.
    case 'PUT':
        $id = (int)($input['id'] ?? 0);
        if (!$id) {
            api_json(['success' => false, 'error' => 'ID diperlukan'], 400);
        }
        $existStmt = $conn->prepare("SELECT id FROM user WHERE id = ? AND STATUS = 'ASSISTANT' AND grup = ? LIMIT 1");
        api_bind($existStmt, 'ii', [$id, $owner_id]);
        $existStmt->execute();
        if (!$existStmt->get_result()->fetch_assoc()) {
            api_json(['success' => false, 'error' => 'ASSISTANT tidak ditemukan atau tidak diizinkan'], 404);
        }

        $fields = [];
        $types = '';
        $params = [];

        if (array_key_exists('username', $input)) {
            $newUsername = trim((string)$input['username']);
            if ($newUsername === '') {
                api_json(['success' => false, 'error' => 'username tidak boleh kosong'], 400);
            }
            if (ua_username_taken($conn, $newUsername, $id)) {
                api_json(['success' => false, 'error' => 'Username sudah digunakan'], 409);
            }
            $fields[] = 'USERNAME = ?';
            $types .= 's';
            $params[] = $newUsername;
        }

        if (array_key_exists('password', $input) && trim((string)$input['password']) !== '') {
            $fields[] = 'PASWORD = ?';
            $types .= 's';
            $params[] = password_hash((string)$input['password'], PASSWORD_DEFAULT);
        }

        if (array_key_exists('nowa', $input)) {
            $fields[] = 'NOWA = ?';
            $types .= 's';
            $params[] = trim((string)$input['nowa']);
        }

        if (array_key_exists('email', $input)) {
            $fields[] = 'domain = ?';
            $types .= 's';
            $params[] = trim((string)$input['email']);
        }

        if (array_key_exists('server', $input)) {
            $serverInput = is_array($input['server']) ? $input['server'] : [];
            $servers = ua_validate_servers($serverInput, $ctx['allowed_server_ids']);
            $fields[] = 'server = ?';
            $types .= 's';
            $params[] = json_encode($servers);
        }

        if (array_key_exists('akses', $input) || array_key_exists('menu', $input)) {
            $aksesInput = $input['akses'] ?? $input['menu'];
            $akses = is_array($aksesInput) ? ua_validate_akses($aksesInput) : [];
            $fields[] = 'akses = ?';
            $types .= 's';
            $params[] = json_encode($akses);
        }

        // Invariants: an ASSISTANT row stays an ASSISTANT row owned by this same owner.
        $fields[] = "STATUS = 'ASSISTANT'";
        $fields[] = 'grup = ?';
        $types .= 'i';
        $params[] = $owner_id;

        $types .= 'i';
        $params[] = $id;
        $stmt = $conn->prepare('UPDATE user SET ' . implode(', ', $fields) . ' WHERE id = ?');
        api_bind($stmt, $types, $params);
        $ok = $stmt->execute();
        if (!$ok) {
            api_json(['success' => false, 'error' => $stmt->error], 500);
        }

        $row = mysqli_query($conn, 'SELECT * FROM user WHERE id = ' . (int)$id)->fetch_assoc();
        api_json(['success' => true, 'data' => ua_sanitize_row($row)]);
        break;

    // DELETE: remove an ASSISTANT row, only if it belongs to this owner.
    case 'DELETE':
        $id = (int)($input['id'] ?? ($_GET['id'] ?? 0));
        if (!$id) {
            api_json(['success' => false, 'error' => 'ID diperlukan'], 400);
        }
        $stmt = $conn->prepare("DELETE FROM user WHERE id = ? AND STATUS = 'ASSISTANT' AND grup = ?");
        api_bind($stmt, 'ii', [$id, $owner_id]);
        $ok = $stmt->execute();
        if ($ok && $stmt->affected_rows === 0) {
            api_json(['success' => false, 'error' => 'ASSISTANT tidak ditemukan atau tidak diizinkan'], 404);
        }
        api_json(['success' => (bool)$ok]);
        break;

    default:
        api_json(['success' => false, 'error' => 'Method tidak didukung'], 405);
}
