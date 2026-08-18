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
require_once '../reseller_helper.php';
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
reseller_bootstrap_schema($conn);

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

    // Pengaturan RESELLER/MITRA ISP (assistant_role, filter harga custom paket, skema biaya
    // Bandwidth vs Persentase Omset) -- mirror kolom yang sama dipakai user.php di web, plus
    // angka beban yang SUDAH dihitung (reseller_cost_burden()) supaya konsumen API tidak perlu
    // hitung ulang formulanya sendiri.
    $reseller = [
        'assistant_role' => $row['assistant_role'] ?? 'assistant',
        'price_filter_enabled' => (bool)($row['reseller_price_filter_enabled'] ?? 0),
        'cost_scheme' => in_array($row['reseller_cost_scheme'] ?? 'bandwidth', ['bandwidth', 'omset_percent'], true) ? $row['reseller_cost_scheme'] : 'bandwidth',
        'bw_cost' => (float)($row['reseller_bw_cost'] ?? 0),
        'bw_ppn_percent' => (float)($row['reseller_bw_ppn_percent'] ?? 11),
        'bw_bhp_uso' => (float)($row['reseller_bw_bhp_uso'] ?? 0),
        'omset_percent' => (float)($row['reseller_omset_percent'] ?? 0),
    ];
    $isReseller = in_array($reseller['assistant_role'], ['reseller', 'mitra_isp'], true);
    $reseller['is_reseller'] = $isReseller;
    $reseller['current_burden'] = 0.0;
    if ($isReseller) {
        $omset = ($reseller['cost_scheme'] === 'omset_percent' && !empty($row['server']))
            ? reseller_omset_bulan_ini($GLOBALS['conn'], is_array($row['server']) ? json_encode($row['server']) : (string)$row['server'])
            : 0.0;
        $reseller['current_burden'] = reseller_cost_burden([
            'bw_cost' => $reseller['bw_cost'],
            'bw_ppn_percent' => $reseller['bw_ppn_percent'],
            'bw_bhp_uso' => $reseller['bw_bhp_uso'],
            'cost_scheme' => $reseller['cost_scheme'],
            'omset_percent' => $reseller['omset_percent'],
        ], $omset);
    }
    $row['reseller'] = $reseller;

    unset(
        $row['assistant_role'], $row['reseller_price_filter_enabled'], $row['reseller_cost_scheme'],
        $row['reseller_bw_cost'], $row['reseller_bw_ppn_percent'], $row['reseller_bw_bhp_uso'], $row['reseller_omset_percent']
    );

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

/** Normalize `reseller`-block input (assistant_role + skema biaya Bandwidth/Persentase Omset +
 *  filter harga toggle) -- mirror validasi yang sama dgn user.php add_user/edit_user di web. */
function ua_validate_reseller_input(array $input) {
    $reseller = is_array($input['reseller'] ?? null) ? $input['reseller'] : $input;

    $role = trim((string)($reseller['assistant_role'] ?? 'assistant'));
    if (!in_array($role, ['assistant', 'assistant_teknisi', 'reseller', 'mitra_isp'], true)) {
        $role = 'assistant';
    }

    $scheme = trim((string)($reseller['cost_scheme'] ?? 'bandwidth'));
    if (!in_array($scheme, ['bandwidth', 'omset_percent'], true)) {
        $scheme = 'bandwidth';
    }

    return [
        'assistant_role' => $role,
        'price_filter_enabled' => !empty($reseller['price_filter_enabled']) ? 1 : 0,
        'cost_scheme' => $scheme,
        'bw_cost' => isset($reseller['bw_cost']) ? (float)$reseller['bw_cost'] : 0.0,
        'bw_ppn_percent' => isset($reseller['bw_ppn_percent']) ? (float)$reseller['bw_ppn_percent'] : 11.0,
        'bw_bhp_uso' => isset($reseller['bw_bhp_uso']) ? (float)$reseller['bw_bhp_uso'] : 0.0,
        'omset_percent' => isset($reseller['omset_percent']) ? (float)$reseller['omset_percent'] : 0.0,
    ];
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
    // Required: username, password (kirim sebagai target_username/target_password kalau caller
    // AUTH pakai username+password/API key -- lihat catatan di bawah). Optional: nowa, email
    // (-> domain), server[] (subset of the owner's own servers), akses[]/menu[] (permission keys,
    // stored as-is). inisial is inherited from the owner's own row, STATUS/grup are fixed,
    // saldo starts at 0, created_at = NOW().
    //
    // target_username/target_password vs username/password: $input yang sama dipakai
    // api_authenticate() DI ATAS untuk login caller (owner) via username+password/API key --
    // kalau dipakai lagi di sini sebagai field sub-akun baru, nilainya SELALU jadi identik dengan
    // kredensial owner sendiri (bukan sub-akun yang mau dibuat), dan `ua_username_taken()` pasti
    // menolak (username owner sudah "taken" oleh dirinya sendiri). target_username/target_password
    // jadi sumber utama; fallback ke username/password HANYA relevan kalau caller auth via sesi
    // browser aktif (method 'session'), yang mana field itu memang belum pernah dipakai utk auth.
    case 'POST':
        $targetUsernameInput = $input['target_username'] ?? ($auth['method'] === 'session' ? ($input['username'] ?? '') : '');
        $targetPasswordInput = $input['target_password'] ?? ($auth['method'] === 'session' ? ($input['password'] ?? '') : '');
        $username = trim((string)$targetUsernameInput);
        $password = (string)$targetPasswordInput;
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
        $reseller = ua_validate_reseller_input($input);

        $stmt = $conn->prepare("INSERT INTO user
            (USERNAME, PASWORD, STATUS, grup, NOWA, saldo, server, domain, inisial, akses, created_at,
             assistant_role, reseller_price_filter_enabled, reseller_cost_scheme, reseller_bw_cost, reseller_bw_ppn_percent, reseller_bw_bhp_uso, reseller_omset_percent)
            VALUES (?, ?, 'ASSISTANT', ?, ?, 0, ?, ?, ?, ?, NOW(),
             ?, ?, ?, ?, ?, ?, ?)");
        api_bind($stmt, 'ssissssssisdddd', [
            $username, $hashed, $owner_id, $nowa, $server_json, $email, $inisial, $akses_json,
            $reseller['assistant_role'], $reseller['price_filter_enabled'], $reseller['cost_scheme'],
            $reseller['bw_cost'], $reseller['bw_ppn_percent'], $reseller['bw_bhp_uso'], $reseller['omset_percent'],
        ]);
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

        // target_username/target_password, bukan username/password -- field itu sudah dipakai
        // api_authenticate() DI ATAS utk login caller (owner) via username+password/API key,
        // jadi kalau caller pakai auth method itu (bukan sesi browser), nilainya adalah kredensial
        // OWNER sendiri, bukan perubahan yang dimaksud utk sub-akun ini. Fallback ke
        // username/password hanya relevan kalau caller auth via sesi browser aktif.
        $hasTargetUsername = array_key_exists('target_username', $input) || ($auth['method'] === 'session' && array_key_exists('username', $input));
        if ($hasTargetUsername) {
            $newUsername = trim((string)($input['target_username'] ?? $input['username']));
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

        $hasTargetPassword = array_key_exists('target_password', $input) || ($auth['method'] === 'session' && array_key_exists('password', $input));
        if ($hasTargetPassword) {
            $newPassword = trim((string)($input['target_password'] ?? $input['password']));
            if ($newPassword !== '') {
                $fields[] = 'PASWORD = ?';
                $types .= 's';
                $params[] = password_hash($newPassword, PASSWORD_DEFAULT);
            }
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

        // Pengaturan RESELLER/MITRA ISP -- diupdate sekaligus (7 kolom) kalau salah satu dari
        // assistant_role/reseller/cost_scheme/bw_*/omset_percent dikirim, sama seperti form
        // Edit ASSISTANT di web yang selalu submit semua field ini bersamaan.
        $resellerKeys = ['assistant_role', 'reseller', 'price_filter_enabled', 'cost_scheme', 'bw_cost', 'bw_ppn_percent', 'bw_bhp_uso', 'omset_percent'];
        $hasResellerInput = false;
        foreach ($resellerKeys as $rk) {
            if (array_key_exists($rk, $input)) {
                $hasResellerInput = true;
                break;
            }
        }
        if ($hasResellerInput) {
            $reseller = ua_validate_reseller_input($input);
            $fields[] = 'assistant_role = ?';
            $fields[] = 'reseller_price_filter_enabled = ?';
            $fields[] = 'reseller_cost_scheme = ?';
            $fields[] = 'reseller_bw_cost = ?';
            $fields[] = 'reseller_bw_ppn_percent = ?';
            $fields[] = 'reseller_bw_bhp_uso = ?';
            $fields[] = 'reseller_omset_percent = ?';
            $types .= 'sisdddd';
            array_push(
                $params,
                $reseller['assistant_role'],
                $reseller['price_filter_enabled'],
                $reseller['cost_scheme'],
                $reseller['bw_cost'],
                $reseller['bw_ppn_percent'],
                $reseller['bw_bhp_uso'],
                $reseller['omset_percent']
            );
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
