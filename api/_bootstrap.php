<?php
// api/_bootstrap.php - Shared auth/scoping/helper library for every endpoint in crm/billing/api/.
// Every resource file does: require_once '../koneksibilling.php'; require_once '_bootstrap.php';
// session_start(); api_cors(); then calls api_read_input()/api_authenticate()/api_resolve_owner()
// in that order, optionally api_rate_limit() for apikey callers, then api_require_module_enabled().
//
// Auth precedence in api_authenticate(): (1) existing web session ($_SESSION['status']==='login'),
// (2) username+password in the request, (3) apikey (?key=... / body "key", looked up in `apikey`
// table). Mirrors crm/billing/cek-sesi.php's OWNER/ASSISTANT resolution: `user.STATUS` 'ASSISTANT'
// means `user.grup` points at the owner's `user.id` and `user.server` is a JSON array of the
// specific server ids that assistant is scoped to; a non-assistant account is scoped to every
// `server` row where `server.user_id` = its own id. `server.PEMILIK` is a per-row brand identifier
// (one owner/user_id can have several PEMILIK values across several server rows), which is why
// tables that only have a PEMILIK column (no server_id FK) are scoped via
// api_allowed_pemilik_list()/api_pemilik_in_sql() rather than a plain WHERE PEMILIK = ?.

function api_cors() {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Content-Type: application/json');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

function api_json($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function api_read_input() {
    $data = is_array($_GET) ? $_GET : [];
    if (!empty($_POST)) {
        $data = array_merge($data, $_POST);
    }
    $raw = (string)file_get_contents('php://input');
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $data = array_merge($data, $decoded);
        }
    }
    return $data;
}

function api_bind($stmt, $types, array $params) {
    if (!$stmt || $types === '' || empty($params)) {
        return;
    }
    $refs = [];
    foreach ($params as $key => $value) {
        $refs[$key] = &$params[$key];
    }
    array_unshift($refs, $types);
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function api_authenticate($conn, array $input) {
    if (isset($_SESSION['status']) && $_SESSION['status'] === 'login' && !empty($_SESSION['PEMILIK'])) {
        return ['method' => 'session', 'pemilik' => (string)$_SESSION['PEMILIK'], 'api_key' => null];
    }

    $username = trim((string)($input['username'] ?? ''));
    $password = (string)($input['password'] ?? '');
    if ($username !== '' && $password !== '') {
        $stmt = $conn->prepare('SELECT USERNAME, PASWORD FROM user WHERE USERNAME = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row && (password_verify($password, (string)$row['PASWORD']) || $password === (string)$row['PASWORD'])) {
                return ['method' => 'userpass', 'pemilik' => (string)$row['USERNAME'], 'api_key' => null];
            }
        }
    }

    $apiKey = trim((string)($input['key'] ?? ($input['api_key'] ?? '')));
    if ($apiKey !== '') {
        $stmt = $conn->prepare('SELECT user_name FROM apikey WHERE api_key = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('s', $apiKey);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row && !empty($row['user_name'])) {
                return ['method' => 'apikey', 'pemilik' => (string)$row['user_name'], 'api_key' => $apiKey];
            }
        }
    }

    api_json(['success' => false, 'error' => 'Autentikasi gagal: sesi tidak aktif, username/password salah, atau API key tidak valid'], 401);
}

function api_ensure_rate_limit_table($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS api_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        api_key VARCHAR(191) NOT NULL,
        requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY api_key_time (api_key, requested_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function api_rate_limit($conn, $apiKey) {
    if (!$apiKey) {
        return;
    }
    api_ensure_rate_limit_table($conn);

    $stmt = $conn->prepare('SELECT COUNT(*) AS c FROM api_requests WHERE api_key = ? AND requested_at > (NOW() - INTERVAL 1 HOUR)');
    if ($stmt) {
        $stmt->bind_param('s', $apiKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $count = (int)($row['c'] ?? 0);
        if ($count >= 100) {
            api_json(['success' => false, 'error' => 'Rate limit terlampaui (maksimal 100 request/jam untuk akses via API key)'], 429);
        }
    }

    $ins = $conn->prepare('INSERT INTO api_requests (api_key) VALUES (?)');
    if ($ins) {
        $ins->bind_param('s', $apiKey);
        $ins->execute();
    }
}

// Resolves the authenticated username (whatever api_authenticate returned as 'pemilik') into an
// owner/assistant scoping context. Returns null if the user row can't be found.
function api_resolve_owner($conn, $pemilik) {
    $stmt = $conn->prepare('SELECT * FROM user WHERE USERNAME = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $pemilik);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        return null;
    }

    $status = strtoupper(trim((string)($row['STATUS'] ?? '')));
    $is_assistant = ($status === 'ASSISTANT');

    if ($is_assistant) {
        $session_user_id = (int)$row['id'];
        $owner_user_id = (int)($row['grup'] ?? 0);
        if ($owner_user_id <= 0) {
            return null;
        }

        $ownerStmt = $conn->prepare('SELECT id FROM user WHERE id = ? LIMIT 1');
        if (!$ownerStmt) {
            return null;
        }
        $ownerStmt->bind_param('i', $owner_user_id);
        $ownerStmt->execute();
        if (!$ownerStmt->get_result()->fetch_assoc()) {
            return null;
        }

        $serverIds = [];
        $decoded = json_decode((string)($row['server'] ?? ''), true);
        if (is_array($decoded)) {
            $serverIds = array_values(array_unique(array_map('intval', $decoded)));
        }

        return [
            'session_user_id'    => $session_user_id,
            'owner_user_id'      => $owner_user_id,
            'is_assistant'       => true,
            'allowed_server_ids' => $serverIds,
            'username'           => (string)$row['USERNAME'],
        ];
    }

    $owner_user_id = (int)$row['id'];
    $serverIds = [];
    $srvStmt = $conn->prepare('SELECT id FROM server WHERE user_id = ?');
    if ($srvStmt) {
        $srvStmt->bind_param('i', $owner_user_id);
        $srvStmt->execute();
        $result = $srvStmt->get_result();
        while ($srow = $result->fetch_assoc()) {
            $serverIds[] = (int)$srow['id'];
        }
    }

    return [
        'session_user_id'    => $owner_user_id,
        'owner_user_id'      => $owner_user_id,
        'is_assistant'       => false,
        'allowed_server_ids' => $serverIds,
        'username'           => (string)$row['USERNAME'],
    ];
}

// PEMILIK-based scoping for tables that only carry a PEMILIK/AREA column (no server_id FK):
// returns every distinct PEMILIK value across the servers this account is allowed to touch.
function api_allowed_pemilik_list($conn, $ctx) {
    $ids = $ctx['allowed_server_ids'] ?? [];
    if (empty($ids)) {
        return [];
    }
    $in = implode(',', array_map('intval', $ids));
    $list = [];
    $res = mysqli_query($conn, "SELECT DISTINCT PEMILIK FROM server WHERE id IN ($in)");
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            if (!empty($row['PEMILIK'])) {
                $list[] = $row['PEMILIK'];
            }
        }
    }
    return $list;
}

// Builds a ready-to-embed "'A','B','C'" SQL fragment for use inside a literal PEMILIK IN (...)
// clause (mysqli can't bind a variable-length IN list as a single placeholder).
function api_pemilik_in_sql($conn, array $pemilikList) {
    if (empty($pemilikList)) {
        return "'__none__'";
    }
    $escaped = array_map(function ($p) use ($conn) {
        return "'" . mysqli_real_escape_string($conn, (string)$p) . "'";
    }, $pemilikList);
    return implode(',', $escaped);
}

// --- Per-owner module enable/disable toggles (settingsapi.php "API Settings" tab) ---------------

const API_MODULE_COLUMNS = [
    'pelanggan'      => 'pelanggan_enabled',
    'area_server'    => 'area_server_enabled',
    'odp'            => 'odp_enabled',
    'paket'          => 'paket_enabled',
    'tiket'          => 'tiket_enabled',
    'user_assistant' => 'user_assistant_enabled',
    'wabot'          => 'wabot_enabled',
    'transaksi'      => 'transaksi_enabled',
    'pool'           => 'pool_enabled',
    'vlan'           => 'vlan_enabled',
    'log'            => 'log_enabled',
];

function api_ensure_module_settings_table($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS api_module_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        owner VARCHAR(191) NOT NULL UNIQUE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    foreach (API_MODULE_COLUMNS as $col) {
        $safeCol = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$col);
        $check = $conn->query("SHOW COLUMNS FROM api_module_settings LIKE '$safeCol'");
        if ($check && $check->num_rows === 0) {
            $conn->query("ALTER TABLE api_module_settings ADD COLUMN `$safeCol` TINYINT(1) NOT NULL DEFAULT 1");
        }
    }
}

function api_module_settings_row($conn, $pemilik) {
    api_ensure_module_settings_table($conn);

    $stmt = $conn->prepare('SELECT * FROM api_module_settings WHERE owner = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $pemilik);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row) {
        return $row;
    }

    $ins = $conn->prepare('INSERT INTO api_module_settings (owner) VALUES (?)');
    if (!$ins) {
        return null;
    }
    $ins->bind_param('s', $pemilik);
    $ins->execute();

    $stmt2 = $conn->prepare('SELECT * FROM api_module_settings WHERE owner = ? LIMIT 1');
    if (!$stmt2) {
        return null;
    }
    $stmt2->bind_param('s', $pemilik);
    $stmt2->execute();
    return $stmt2->get_result()->fetch_assoc();
}

function api_module_enabled($conn, $pemilik, $moduleKey) {
    if (!isset(API_MODULE_COLUMNS[$moduleKey])) {
        return true;
    }
    $row = api_module_settings_row($conn, $pemilik);
    if (!$row) {
        return true;
    }
    $col = API_MODULE_COLUMNS[$moduleKey];
    return !isset($row[$col]) || (int)$row[$col] === 1;
}

function api_require_module_enabled($conn, $pemilik, $moduleKey) {
    if (!api_module_enabled($conn, $pemilik, $moduleKey)) {
        api_json(['success' => false, 'error' => 'Modul API ini dinonaktifkan oleh pemilik akun. Aktifkan lagi di Settings API.'], 403);
    }
}
