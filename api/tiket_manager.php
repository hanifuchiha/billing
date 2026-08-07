<?php
// api/tiket_manager.php - Tiket Manager API (Instalasi / Maintenance / Migrasi / Dismantle)
// Full CRUD (GET/POST/PUT/DELETE). All columns of billing_tiket_manager are exposed.
// Data is always scoped to servers owned by (or assigned to, for ASSISTANT accounts) the
// authenticated user - a request can never read/write a ticket tied to someone else's server.
// Sebelumnya file ini require_once '_bootstrap.php' TAPI tidak pernah memanggil
// api_authenticate() -- ada fungsi lokal tm_cek_login() terpisah (nama beda dari
// cek_login_api() di file lain, jadi lolos dari pencarian awal) yang cuma cek
// session ATAU username+password, tidak pernah baca API key dari tabel `apikey`.
// Diganti ke _bootstrap.php::api_authenticate() yang sebenarnya, sama seperti
// api/odp.php dkk.
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

// Bind params by reference regardless of PHP version (spread-into-by-ref is unreliable pre-8.1).
function tm_bind($stmt, $types, array $params) {
    if ($types === '') {
        return;
    }
    $refs = [$types];
    foreach ($params as $key => $value) {
        $refs[] = &$params[$key];
    }
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

// Resolve who is calling: their owner account id, whether they are an ASSISTANT, and which
// server ids they are allowed to touch. Mirrors crm/billing/tiket_manager.php's access model.
function tm_resolve_access($conn, $pemilik) {
    $stmt = $conn->prepare("SELECT id, USERNAME, STATUS, grup, server FROM user WHERE USERNAME = ? LIMIT 1");
    $stmt->bind_param('s', $pemilik);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        return null;
    }

    $session_user_id = (int)$row['id'];
    $is_assistant = strtoupper((string)$row['STATUS']) === 'ASSISTANT';
    $owner_user_id = $is_assistant ? (int)$row['grup'] : $session_user_id;

    $allowed_server_ids = [];
    if ($is_assistant) {
        $server_ids = [];
        $decoded = json_decode((string)($row['server'] ?? ''), true);
        if (is_array($decoded)) {
            foreach ($decoded as $sid) {
                $server_ids[] = (int)$sid;
            }
        }
        $server_ids = array_values(array_unique(array_filter($server_ids)));
        if (!empty($server_ids)) {
            $id_in = implode(',', $server_ids);
            $res = mysqli_query($conn, "SELECT id FROM server WHERE user_id = $owner_user_id AND id IN ($id_in)");
            while ($res && ($r = mysqli_fetch_assoc($res))) {
                $allowed_server_ids[] = (int)$r['id'];
            }
        }
    } else {
        $res = mysqli_query($conn, "SELECT id FROM server WHERE user_id = $owner_user_id");
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $allowed_server_ids[] = (int)$r['id'];
        }
    }

    return [
        'session_user_id'    => $session_user_id,
        'owner_user_id'      => $owner_user_id,
        'is_assistant'       => $is_assistant,
        'allowed_server_ids' => $allowed_server_ids,
    ];
}

$ctx = tm_resolve_access($conn, $pemilik);
if (!$ctx) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'User tidak ditemukan']);
    exit;
}
api_require_module_enabled($conn, $pemilik, 'tiket');
$allowed_server_ids = $ctx['allowed_server_ids'];

// ====================================================================
// Ticket backend routing - mirrors cek-sesi.php / cron_maintenance_ticket.php:
// each owner account can be configured (user.ticket_management_source) to keep
// its real tickets in billing_tiket_manager (default) OR in the joblist table
// (separate DB, used by the technician mobile app). We must follow the same
// flag here, otherwise an account set to "joblist" would always see empty data.
// ====================================================================
function tm_ticket_source($conn, $owner_user_id) {
    // Defensive: cek-sesi.php normally auto-adds this column, but this endpoint bypasses
    // cek-sesi.php, so make sure it exists here too before selecting it.
    $checkCol = @mysqli_query($conn, "SHOW COLUMNS FROM user LIKE 'ticket_management_source'");
    if ($checkCol && mysqli_num_rows($checkCol) === 0) {
        @mysqli_query($conn, "ALTER TABLE user ADD COLUMN ticket_management_source VARCHAR(20) NOT NULL DEFAULT 'tiket_manager'");
    }

    $stmt = $conn->prepare("SELECT ticket_management_source FROM user WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $owner_user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $src = strtolower(trim((string)($row['ticket_management_source'] ?? 'tiket_manager')));
    return in_array($src, ['tiket_manager', 'joblist'], true) ? $src : 'tiket_manager';
}

$ticket_source = tm_ticket_source($conn, $ctx['owner_user_id']);

if ($ticket_source === 'joblist') {
    // --- Joblist backend: different DB, different table/columns, no server_id FK. ---
    // Scoping assumption (matches buat_tiket.php, which inserts joblist.project = server
    // BRAND, and the cron, which scopes by PEMILIK): a joblist row is visible to this
    // account if its `project` matches either the account's own username or the BRAND of
    // one of the servers this account owns. joblist has no strict foreign key to billing
    // servers/owners, so this is a best-effort convention, not a real FK - verify it
    // matches your real data before relying on it for sensitive access control.
    function tm_joblist_connect($config) {
        $host = $config['db_host_absensi'] ?? 'localhost';
        $user = $config['db_user_absensi'] ?? 'root';
        $pass = $config['db_pass_absensi'] ?? '';
        $db   = $config['db_name_absensi'] ?? '';
        $j = @new mysqli($host, $user, $pass, $db);
        if ($j->connect_error) {
            return null;
        }
        $j->set_charset('utf8mb4');
        return $j;
    }

    function tm_joblist_team_column($connJ) {
        static $col = null;
        if ($col !== null) {
            return $col;
        }
        $col = 'team';
        $r = $connJ->query("SHOW COLUMNS FROM joblist LIKE 'team'");
        if ($r && $r->num_rows > 0) {
            return $col;
        }
        $r = $connJ->query("SHOW COLUMNS FROM joblist LIKE 'tim'");
        if ($r && $r->num_rows > 0) {
            $col = 'tim';
        }
        return $col;
    }

    function tm_joblist_columns($connJ) {
        static $cols = null;
        if ($cols !== null) {
            return $cols;
        }
        $cols = [];
        $r = $connJ->query("SHOW COLUMNS FROM joblist");
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                $f = strtolower((string)($row['Field'] ?? ''));
                if ($f !== '') {
                    $cols[$f] = true;
                }
            }
        }
        return $cols;
    }

    function tm_joblist_has_column($connJ, $name) {
        $cols = tm_joblist_columns($connJ);
        return isset($cols[strtolower($name)]);
    }

    // Normalize a joblist row into the same envelope shape as billing_tiket_manager rows,
    // while keeping the original joblist-native fields too so nothing is lost.
    function tm_joblist_normalize_row($row) {
        $data = (string)($row['data'] ?? '');
        $firstLine = trim((string)strtok($data, "\n"));
        $judul = $firstLine !== '' ? $firstLine : (strtoupper((string)($row['tipe'] ?? '')) . ' - Tiket Joblist');
        $status = (string)($row['status'] ?? '');
        return [
            'source'              => 'joblist',
            'id'                  => $row['id'] ?? null,
            'judul'               => $judul,
            'detail'              => $data,
            'server_id'           => null,
            'pemilik'             => $row['project'] ?? '',
            'brand'               => null,
            'area'                => null,
            'project_name'        => $row['project'] ?? '',
            'tipe'                => $row['tipe'] ?? '',
            'report'              => $row['report'] ?? '',
            'status'              => $status,
            'teknisi_user_id'     => null,
            'created_by_user_id'  => null,
            'done_at'             => (strtoupper($status) === 'DONE') ? ($row['waktu'] ?? null) : null,
            'created_at'          => $row['created_at'] ?: ($row['tgl'] ?? null),
            'updated_at'          => $row['waktu'] ?? null,
            // Raw joblist-native columns (kept as-is):
            'project'             => $row['project'] ?? '',
            'tim'                 => $row['tim'] ?? '',
            'nowa'                => $row['nowa'] ?? '',
            'keterangan'          => $row['keterangan'] ?? '',
            'tgl'                 => $row['tgl'] ?? '',
            'waktu'               => $row['waktu'] ?? '',
        ];
    }

    $connJ = tm_joblist_connect($config);
    if (!$connJ) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Koneksi database joblist tidak tersedia']);
        exit;
    }

    $JOBLIST_VALID_STATUS = ['BARU', 'PENDING', 'OPEN', 'DONE', 'CANCEL', 'PRIORITAS'];

    $allowed_projects = [];
    if ($pemilik !== '' && $pemilik !== null) {
        $allowed_projects[] = $pemilik;
    }
    if (!empty($allowed_server_ids)) {
        $servers_in2 = implode(',', $allowed_server_ids);
        $rb = mysqli_query($conn, "SELECT DISTINCT BRAND FROM server WHERE id IN ($servers_in2) AND BRAND <> ''");
        while ($rb && ($row = mysqli_fetch_assoc($rb))) {
            $allowed_projects[] = (string)$row['BRAND'];
        }
    }
    $allowed_projects = array_values(array_unique(array_filter($allowed_projects, function ($v) {
        return trim((string)$v) !== '';
    })));

    switch ($method) {
        case 'GET':
            if (empty($allowed_projects)) {
                echo json_encode(['success' => true, 'data' => [], 'total' => 0]);
                break;
            }
            $teamCol       = tm_joblist_team_column($connJ);
            $hasNowa       = tm_joblist_has_column($connJ, 'nowa');
            $hasReport     = tm_joblist_has_column($connJ, 'report');
            $hasKeterangan = tm_joblist_has_column($connJ, 'keterangan');
            $hasCreatedAt  = tm_joblist_has_column($connJ, 'created_at');
            $hasTgl        = tm_joblist_has_column($connJ, 'tgl');

            $proj_in = implode(',', array_map(function ($p) use ($connJ) {
                return "'" . $connJ->real_escape_string($p) . "'";
            }, $allowed_projects));
            $where = ["project IN ($proj_in)"];

            if (!empty($_GET['id'])) {
                $where[] = 'id = ' . (int)$_GET['id'];
            }

            $status_filter = strtoupper(trim((string)($_GET['status'] ?? 'ALL')));
            if ($status_filter !== '' && $status_filter !== 'ALL') {
                if (!in_array($status_filter, $JOBLIST_VALID_STATUS, true)) {
                    echo json_encode(['success' => false, 'error' => 'Status tidak valid untuk joblist. Pilihan: ALL, ' . implode(', ', $JOBLIST_VALID_STATUS)]);
                    exit;
                }
                $where[] = "status = '" . $connJ->real_escape_string($status_filter) . "'";
            }

            $tipe_filter = strtoupper(trim((string)($_GET['tipe'] ?? 'ALL')));
            if ($tipe_filter !== '' && $tipe_filter !== 'ALL') {
                $where[] = "tipe = '" . $connJ->real_escape_string($tipe_filter) . "'";
            }

            if (!empty($_GET['q'])) {
                $qEsc = $connJ->real_escape_string($_GET['q']);
                $searchCols = ['data', 'project'];
                if ($hasNowa) {
                    $searchCols[] = 'nowa';
                }
                $parts = array_map(function ($c) use ($qEsc) {
                    return "$c LIKE '%$qEsc%'";
                }, $searchCols);
                $where[] = '(' . implode(' OR ', $parts) . ')';
            }

            if (!empty($_GET['date_from'])) {
                $df = $connJ->real_escape_string($_GET['date_from']);
                $where[] = $hasTgl ? "tgl >= '$df'" : "DATE(waktu) >= '$df'";
            }
            if (!empty($_GET['date_to'])) {
                $dt = $connJ->real_escape_string($_GET['date_to']);
                $where[] = $hasTgl ? "tgl <= '$dt'" : "DATE(waktu) <= '$dt'";
            }

            $limit  = isset($_GET['limit']) ? max(1, min(500, (int)$_GET['limit'])) : 200;
            $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
            $where_sql = implode(' AND ', $where);

            $fields = ['id', 'data', 'project', "$teamCol AS tim", 'status', 'tipe', 'waktu'];
            $fields[] = $hasTgl ? 'tgl' : 'DATE(waktu) AS tgl';
            $fields[] = $hasNowa ? 'nowa' : "'' AS nowa";
            $fields[] = $hasReport ? 'report' : "'' AS report";
            $fields[] = $hasKeterangan ? 'keterangan' : "'' AS keterangan";
            $fields[] = $hasCreatedAt ? 'created_at' : "'' AS created_at";
            $selectSql = implode(', ', $fields);

            $res = $connJ->query("SELECT $selectSql FROM joblist WHERE $where_sql ORDER BY id DESC LIMIT $limit OFFSET $offset");
            $data = [];
            while ($res && ($row = $res->fetch_assoc())) {
                $data[] = tm_joblist_normalize_row($row);
            }
            $totalRes = $connJ->query("SELECT COUNT(*) AS total FROM joblist WHERE $where_sql");
            $total = $totalRes ? (int)$totalRes->fetch_assoc()['total'] : 0;

            echo json_encode(['success' => true, 'data' => $data, 'total' => $total, 'limit' => $limit, 'offset' => $offset]);
            break;

        case 'POST':
            if (empty($allowed_projects)) {
                echo json_encode(['success' => false, 'error' => 'Tidak ada project/server yang dikenali untuk akun ini']);
                exit;
            }
            $judul  = trim((string)($input['judul'] ?? ''));
            $detail = trim((string)($input['detail'] ?? ($input['data'] ?? '')));
            if ($detail === '' && $judul !== '') {
                $detail = $judul;
            }
            if ($detail === '') {
                echo json_encode(['success' => false, 'error' => 'detail (atau judul) wajib diisi']);
                exit;
            }
            if ($judul !== '' && strpos($detail, $judul) === false) {
                $detail = $judul . "\n" . $detail;
            }

            $tipe = strtoupper(trim((string)($input['tipe'] ?? 'INSTALLASI')));
            if ($tipe === '') {
                $tipe = 'INSTALLASI';
            }
            $status = strtoupper(trim((string)($input['status'] ?? 'BARU')));
            if (!in_array($status, $JOBLIST_VALID_STATUS, true)) {
                $status = 'BARU';
            }

            $project = trim((string)($input['project'] ?? ($allowed_projects[0] ?? '')));
            if (!in_array($project, $allowed_projects, true)) {
                echo json_encode(['success' => false, 'error' => 'project tidak dikenali/tidak diizinkan untuk akun ini']);
                exit;
            }
            $tim  = trim((string)($input['tim'] ?? ''));
            $nowa = trim((string)($input['nowa'] ?? ''));

            $teamCol       = tm_joblist_team_column($connJ);
            $hasNowa       = tm_joblist_has_column($connJ, 'nowa');
            $hasKeterangan = tm_joblist_has_column($connJ, 'keterangan');
            $hasTgl        = tm_joblist_has_column($connJ, 'tgl');
            $hasCreatedAt  = tm_joblist_has_column($connJ, 'created_at');
            $hasReport     = tm_joblist_has_column($connJ, 'report');

            $now   = date('Y-m-d H:i:s');
            $today = date('Y-m-d');

            $cols  = ['data', 'project', $teamCol, 'status', 'tipe', 'waktu'];
            $vals  = [$detail, $project, $tim, $status, $tipe, $now];
            $types = 'ssssss';

            if ($hasNowa) { $cols[] = 'nowa'; $vals[] = $nowa; $types .= 's'; }
            if ($hasKeterangan) { $cols[] = 'keterangan'; $vals[] = ''; $types .= 's'; }
            if ($hasTgl) { $cols[] = 'tgl'; $vals[] = $today; $types .= 's'; }
            if ($hasCreatedAt) { $cols[] = 'created_at'; $vals[] = $now; $types .= 's'; }
            if ($hasReport) { $cols[] = 'report'; $vals[] = ''; $types .= 's'; }

            $placeholders = implode(',', array_fill(0, count($cols), '?'));
            $stmt = $connJ->prepare('INSERT INTO joblist (' . implode(',', $cols) . ") VALUES ($placeholders)");
            tm_bind($stmt, $types, $vals);
            $ok = $stmt->execute();
            if (!$ok) {
                echo json_encode(['success' => false, 'error' => $stmt->error]);
                exit;
            }
            $newId = $connJ->insert_id;
            echo json_encode(['success' => true, 'id' => $newId, 'source' => 'joblist']);
            break;

        case 'PUT':
            $id = (int)($input['id'] ?? 0);
            if (!$id) {
                echo json_encode(['success' => false, 'error' => 'ID diperlukan']);
                exit;
            }
            if (empty($allowed_projects)) {
                echo json_encode(['success' => false, 'error' => 'Tiket tidak ditemukan atau tidak diizinkan']);
                exit;
            }
            $proj_in = implode(',', array_map(function ($p) use ($connJ) {
                return "'" . $connJ->real_escape_string($p) . "'";
            }, $allowed_projects));
            $existing = $connJ->query("SELECT * FROM joblist WHERE id = $id AND project IN ($proj_in) LIMIT 1")->fetch_assoc();
            if (!$existing) {
                echo json_encode(['success' => false, 'error' => 'Tiket tidak ditemukan atau tidak diizinkan']);
                exit;
            }

            $teamCol       = tm_joblist_team_column($connJ);
            $hasNowa       = tm_joblist_has_column($connJ, 'nowa');
            $hasKeterangan = tm_joblist_has_column($connJ, 'keterangan');
            $hasReport     = tm_joblist_has_column($connJ, 'report');

            $sets  = [];
            $types = '';
            $vals  = [];

            if (array_key_exists('detail', $input) || array_key_exists('data', $input)) {
                $v = (string)($input['detail'] ?? $input['data']);
                $sets[] = 'data = ?'; $types .= 's'; $vals[] = $v;
            }
            if (array_key_exists('tipe', $input)) {
                $v = strtoupper(trim((string)$input['tipe']));
                $sets[] = 'tipe = ?'; $types .= 's'; $vals[] = $v;
            }
            if (array_key_exists('status', $input)) {
                $v = strtoupper(trim((string)$input['status']));
                if (!in_array($v, $JOBLIST_VALID_STATUS, true)) {
                    echo json_encode(['success' => false, 'error' => 'Status tidak valid untuk joblist. Pilihan: ' . implode(', ', $JOBLIST_VALID_STATUS)]);
                    exit;
                }
                $sets[] = 'status = ?'; $types .= 's'; $vals[] = $v;
                if ($v === 'DONE') {
                    $sets[] = 'waktu = NOW()';
                }
            }
            if (array_key_exists('tim', $input)) {
                $sets[] = "$teamCol = ?"; $types .= 's'; $vals[] = (string)$input['tim'];
            }
            if ($hasNowa && array_key_exists('nowa', $input)) {
                $sets[] = 'nowa = ?'; $types .= 's'; $vals[] = (string)$input['nowa'];
            }
            if ($hasReport && array_key_exists('report', $input)) {
                $sets[] = 'report = ?'; $types .= 's'; $vals[] = (string)$input['report'];
            }
            if ($hasKeterangan && array_key_exists('keterangan', $input)) {
                $sets[] = 'keterangan = ?'; $types .= 's'; $vals[] = (string)$input['keterangan'];
            }
            if (array_key_exists('project', $input)) {
                $np = trim((string)$input['project']);
                if (!in_array($np, $allowed_projects, true)) {
                    echo json_encode(['success' => false, 'error' => 'project baru tidak diizinkan']);
                    exit;
                }
                $sets[] = 'project = ?'; $types .= 's'; $vals[] = $np;
            }

            if (empty($sets)) {
                echo json_encode(['success' => false, 'error' => 'Tidak ada data untuk diupdate']);
                exit;
            }

            $types .= 'i';
            $vals[] = $id;
            $stmt = $connJ->prepare('UPDATE joblist SET ' . implode(', ', $sets) . ' WHERE id = ?');
            tm_bind($stmt, $types, $vals);
            $ok = $stmt->execute();
            if (!$ok) {
                echo json_encode(['success' => false, 'error' => $stmt->error]);
                exit;
            }
            echo json_encode(['success' => true, 'source' => 'joblist']);
            break;

        case 'DELETE':
            $id = (int)($input['id'] ?? ($_GET['id'] ?? 0));
            if (!$id) {
                echo json_encode(['success' => false, 'error' => 'ID diperlukan']);
                exit;
            }
            if (empty($allowed_projects)) {
                echo json_encode(['success' => false, 'error' => 'Tiket tidak ditemukan atau tidak diizinkan']);
                exit;
            }
            $proj_in = implode(',', array_map(function ($p) use ($connJ) {
                return "'" . $connJ->real_escape_string($p) . "'";
            }, $allowed_projects));
            $stmt = $connJ->prepare("DELETE FROM joblist WHERE id = ? AND project IN ($proj_in)");
            $stmt->bind_param('i', $id);
            $ok = $stmt->execute();
            if ($ok && $stmt->affected_rows === 0) {
                echo json_encode(['success' => false, 'error' => 'Tiket tidak ditemukan atau tidak diizinkan']);
                exit;
            }
            echo json_encode(['success' => $ok]);
            break;

        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method tidak didukung']);
    }
    exit;
}

// --- Ensure schema (same full column set as crm/billing/tiket_manager.php's web UI) ---
$conn->query("CREATE TABLE IF NOT EXISTS billing_tiket_manager (
    id INT AUTO_INCREMENT PRIMARY KEY,
    judul VARCHAR(255) NOT NULL,
    detail TEXT,
    server_id INT NOT NULL,
    pemilik VARCHAR(120) NOT NULL,
    brand VARCHAR(120) DEFAULT '',
    area VARCHAR(200) DEFAULT '',
    project_name VARCHAR(191) DEFAULT '',
    tipe VARCHAR(40) DEFAULT 'INSTALLASI',
    report MEDIUMTEXT,
    status ENUM('BARU','PENDING','DONE','CANCEL') DEFAULT 'BARU',
    teknisi_user_id INT DEFAULT NULL,
    created_by_user_id INT NOT NULL,
    done_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_server (server_id),
    INDEX idx_status (status),
    INDEX idx_teknisi (teknisi_user_id),
    INDEX idx_created_by (created_by_user_id),
    INDEX idx_tipe (tipe)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function tm_ensure_column($conn, $column_name, $definition_sql) {
    $safe_col = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$column_name);
    if ($safe_col === '') {
        return;
    }
    $check = mysqli_query($conn, "SHOW COLUMNS FROM billing_tiket_manager LIKE '" . mysqli_real_escape_string($conn, $safe_col) . "'");
    if ($check && mysqli_num_rows($check) > 0) {
        return;
    }
    mysqli_query($conn, "ALTER TABLE billing_tiket_manager ADD COLUMN " . $definition_sql);
}
tm_ensure_column($conn, 'server_id', 'server_id INT NOT NULL');
tm_ensure_column($conn, 'project_name', "project_name VARCHAR(191) DEFAULT ''");
tm_ensure_column($conn, 'tipe', "tipe VARCHAR(40) DEFAULT 'INSTALLASI'");
tm_ensure_column($conn, 'report', 'report MEDIUMTEXT');
tm_ensure_column($conn, 'teknisi_user_id', 'teknisi_user_id INT DEFAULT NULL');
tm_ensure_column($conn, 'created_by_user_id', 'created_by_user_id INT NOT NULL DEFAULT 0');
tm_ensure_column($conn, 'done_at', 'done_at DATETIME DEFAULT NULL');

$VALID_STATUS = ['BARU', 'PENDING', 'DONE', 'CANCEL'];
$VALID_TIPE   = ['INSTALLASI', 'MAINTENANCE', 'MIGRASI', 'DISMANTLE'];

function tm_server_row($conn, $server_id, $allowed_server_ids) {
    $server_id = (int)$server_id;
    if ($server_id <= 0 || !in_array($server_id, $allowed_server_ids, true)) {
        return null;
    }
    $stmt = $conn->prepare("SELECT id, PEMILIK, BRAND, AREA FROM server WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $server_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

function tm_with_source($row) {
    if (!is_array($row)) {
        return $row;
    }
    return ['source' => 'tiket_manager'] + $row;
}

function tm_valid_teknisi($conn, $teknisi_user_id, $owner_user_id) {
    $teknisi_user_id = (int)$teknisi_user_id;
    $stmt = $conn->prepare("SELECT id FROM user WHERE id = ? AND STATUS = 'ASSISTANT' AND grup = ? LIMIT 1");
    $stmt->bind_param('ii', $teknisi_user_id, $owner_user_id);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

switch ($method) {

    // GET: read ticket data - any status, any tipe (instalasi/maintenance/migrasi/dismantle),
    // scoped to the servers this account owns/is-assigned-to. All columns are returned.
    case 'GET':
        if (empty($allowed_server_ids)) {
            echo json_encode(['success' => true, 'data' => [], 'total' => 0]);
            break;
        }

        $servers_in = implode(',', $allowed_server_ids);
        $where  = ["server_id IN ($servers_in)"];
        $types  = '';
        $params = [];

        if (!empty($_GET['id'])) {
            $where[] = 'id = ?';
            $types  .= 'i';
            $params[] = (int)$_GET['id'];
        }

        $status_filter = strtoupper(trim((string)($_GET['status'] ?? 'ALL')));
        if ($status_filter !== '' && $status_filter !== 'ALL') {
            if (!in_array($status_filter, $VALID_STATUS, true)) {
                echo json_encode(['success' => false, 'error' => 'Status tidak valid. Pilihan: ALL, ' . implode(', ', $VALID_STATUS)]);
                exit;
            }
            $where[] = 'status = ?';
            $types  .= 's';
            $params[] = $status_filter;
        }

        $tipe_filter = strtoupper(trim((string)($_GET['tipe'] ?? 'ALL')));
        if ($tipe_filter !== '' && $tipe_filter !== 'ALL') {
            if (!in_array($tipe_filter, $VALID_TIPE, true)) {
                echo json_encode(['success' => false, 'error' => 'Tipe tidak valid. Pilihan: ALL, ' . implode(', ', $VALID_TIPE)]);
                exit;
            }
            $where[] = 'tipe = ?';
            $types  .= 's';
            $params[] = $tipe_filter;
        }

        if (!empty($_GET['server_id'])) {
            $sid = (int)$_GET['server_id'];
            if (!in_array($sid, $allowed_server_ids, true)) {
                echo json_encode(['success' => false, 'error' => 'Server tidak diizinkan untuk akun ini']);
                exit;
            }
            $where[] = 'server_id = ?';
            $types  .= 'i';
            $params[] = $sid;
        }

        if (!empty($_GET['teknisi_user_id'])) {
            $where[] = 'teknisi_user_id = ?';
            $types  .= 'i';
            $params[] = (int)$_GET['teknisi_user_id'];
        }

        foreach (['brand', 'area', 'project_name'] as $col) {
            if (!empty($_GET[$col])) {
                $where[] = "$col = ?";
                $types  .= 's';
                $params[] = (string)$_GET[$col];
            }
        }

        if (!empty($_GET['date_from'])) {
            $where[] = 'created_at >= ?';
            $types  .= 's';
            $params[] = $_GET['date_from'] . ' 00:00:00';
        }
        if (!empty($_GET['date_to'])) {
            $where[] = 'created_at <= ?';
            $types  .= 's';
            $params[] = $_GET['date_to'] . ' 23:59:59';
        }

        if (!empty($_GET['q'])) {
            $like = '%' . $_GET['q'] . '%';
            $where[] = '(judul LIKE ? OR detail LIKE ? OR report LIKE ?)';
            $types  .= 'sss';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $limit  = isset($_GET['limit']) ? max(1, min(500, (int)$_GET['limit'])) : 200;
        $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
        $where_sql = implode(' AND ', $where);

        $stmt = $conn->prepare("SELECT * FROM billing_tiket_manager WHERE $where_sql ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
        tm_bind($stmt, $types, $params);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = tm_with_source($row);
        }

        $count_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM billing_tiket_manager WHERE $where_sql");
        tm_bind($count_stmt, $types, $params);
        $count_stmt->execute();
        $total = (int)$count_stmt->get_result()->fetch_assoc()['total'];

        echo json_encode(['success' => true, 'data' => $data, 'total' => $total, 'limit' => $limit, 'offset' => $offset]);
        break;

    // POST: create a new ticket (any tipe) tied to a server owned by this account.
    case 'POST':
        $server_id       = (int)($input['server_id'] ?? 0);
        $judul           = trim((string)($input['judul'] ?? ''));
        $detail          = trim((string)($input['detail'] ?? ''));
        $tipe            = strtoupper(trim((string)($input['tipe'] ?? 'INSTALLASI')));
        $report          = trim((string)($input['report'] ?? ''));
        $status          = strtoupper(trim((string)($input['status'] ?? 'BARU')));
        $teknisi_user_id = (isset($input['teknisi_user_id']) && $input['teknisi_user_id'] !== '' && $input['teknisi_user_id'] !== null)
            ? (int)$input['teknisi_user_id']
            : null;

        if ($judul === '') {
            echo json_encode(['success' => false, 'error' => 'Judul wajib diisi']);
            exit;
        }
        if (!in_array($tipe, $VALID_TIPE, true)) {
            echo json_encode(['success' => false, 'error' => 'Tipe tidak valid. Pilihan: ' . implode(', ', $VALID_TIPE)]);
            exit;
        }
        if (!in_array($status, $VALID_STATUS, true)) {
            echo json_encode(['success' => false, 'error' => 'Status tidak valid. Pilihan: ' . implode(', ', $VALID_STATUS)]);
            exit;
        }
        $server = tm_server_row($conn, $server_id, $allowed_server_ids);
        if (!$server) {
            echo json_encode(['success' => false, 'error' => 'server_id wajib diisi dan harus salah satu server milik akun ini']);
            exit;
        }
        if ($teknisi_user_id !== null && !tm_valid_teknisi($conn, $teknisi_user_id, $ctx['owner_user_id'])) {
            echo json_encode(['success' => false, 'error' => 'teknisi_user_id tidak valid untuk akun ini']);
            exit;
        }

        $owner_pemilik = (string)$server['PEMILIK'];
        $brand = (string)$server['BRAND'];
        $area  = (string)$server['AREA'];
        $project_name = trim($brand . ' - ' . $area);
        if ($project_name === '-' || $project_name === '') {
            $project_name = $owner_pemilik;
        }
        $created_by = $ctx['session_user_id'];

        $stmt = $conn->prepare("INSERT INTO billing_tiket_manager
            (judul, detail, server_id, pemilik, brand, area, project_name, tipe, report, status, teknisi_user_id, created_by_user_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        tm_bind($stmt, 'ssisssssssii', [
            $judul, $detail, $server_id, $owner_pemilik, $brand, $area,
            $project_name, $tipe, $report, $status, $teknisi_user_id, $created_by
        ]);
        $ok = $stmt->execute();
        if (!$ok) {
            echo json_encode(['success' => false, 'error' => $stmt->error]);
            exit;
        }

        $new_id = $conn->insert_id;
        $row = mysqli_query($conn, "SELECT * FROM billing_tiket_manager WHERE id = $new_id")->fetch_assoc();
        echo json_encode(['success' => true, 'id' => $new_id, 'data' => tm_with_source($row)]);
        break;

    // PUT: update any column of a ticket (judul, detail, report, tipe, status, teknisi_user_id,
    // server_id). Only fields present in the request body are changed.
    case 'PUT':
        $id = (int)($input['id'] ?? 0);
        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'ID diperlukan']);
            exit;
        }
        if (empty($allowed_server_ids)) {
            echo json_encode(['success' => false, 'error' => 'Tiket tidak ditemukan atau tidak diizinkan']);
            exit;
        }
        $servers_in = implode(',', $allowed_server_ids);
        $existing = mysqli_query($conn, "SELECT * FROM billing_tiket_manager WHERE id = $id AND server_id IN ($servers_in) LIMIT 1")->fetch_assoc();
        if (!$existing) {
            echo json_encode(['success' => false, 'error' => 'Tiket tidak ditemukan atau tidak diizinkan']);
            exit;
        }

        $fields = [];
        $types  = '';
        $params = [];

        if (array_key_exists('judul', $input)) {
            $v = trim((string)$input['judul']);
            if ($v === '') {
                echo json_encode(['success' => false, 'error' => 'Judul tidak boleh kosong']);
                exit;
            }
            $fields[] = 'judul = ?';
            $types   .= 's';
            $params[] = $v;
        }
        if (array_key_exists('detail', $input)) {
            $fields[] = 'detail = ?';
            $types   .= 's';
            $params[] = (string)$input['detail'];
        }
        if (array_key_exists('report', $input)) {
            $fields[] = 'report = ?';
            $types   .= 's';
            $params[] = (string)$input['report'];
        }
        if (array_key_exists('tipe', $input)) {
            $v = strtoupper(trim((string)$input['tipe']));
            if (!in_array($v, $VALID_TIPE, true)) {
                echo json_encode(['success' => false, 'error' => 'Tipe tidak valid. Pilihan: ' . implode(', ', $VALID_TIPE)]);
                exit;
            }
            $fields[] = 'tipe = ?';
            $types   .= 's';
            $params[] = $v;
        }
        if (array_key_exists('teknisi_user_id', $input)) {
            if ($input['teknisi_user_id'] === '' || $input['teknisi_user_id'] === null) {
                $fields[] = 'teknisi_user_id = NULL';
            } else {
                $tid = (int)$input['teknisi_user_id'];
                if (!tm_valid_teknisi($conn, $tid, $ctx['owner_user_id'])) {
                    echo json_encode(['success' => false, 'error' => 'teknisi_user_id tidak valid untuk akun ini']);
                    exit;
                }
                $fields[] = 'teknisi_user_id = ?';
                $types   .= 'i';
                $params[] = $tid;
            }
        }
        if (array_key_exists('server_id', $input)) {
            $newServer = tm_server_row($conn, (int)$input['server_id'], $allowed_server_ids);
            if (!$newServer) {
                echo json_encode(['success' => false, 'error' => 'server_id baru tidak valid/tidak diizinkan']);
                exit;
            }
            $fields[] = 'server_id = ?';
            $types   .= 'i';
            $params[] = (int)$newServer['id'];
            $fields[] = 'pemilik = ?';
            $types   .= 's';
            $params[] = (string)$newServer['PEMILIK'];
            $fields[] = 'brand = ?';
            $types   .= 's';
            $params[] = (string)$newServer['BRAND'];
            $fields[] = 'area = ?';
            $types   .= 's';
            $params[] = (string)$newServer['AREA'];
        }
        if (array_key_exists('status', $input)) {
            $v = strtoupper(trim((string)$input['status']));
            if (!in_array($v, $VALID_STATUS, true)) {
                echo json_encode(['success' => false, 'error' => 'Status tidak valid. Pilihan: ' . implode(', ', $VALID_STATUS)]);
                exit;
            }
            $fields[] = 'status = ?';
            $types   .= 's';
            $params[] = $v;
            $fields[] = 'done_at = ' . ($v === 'DONE' ? 'NOW()' : 'NULL');
        }

        if (empty($fields)) {
            echo json_encode(['success' => false, 'error' => 'Tidak ada data untuk diupdate']);
            exit;
        }

        $types .= 'i';
        $params[] = $id;
        $stmt = $conn->prepare("UPDATE billing_tiket_manager SET " . implode(', ', $fields) . " WHERE id = ?");
        tm_bind($stmt, $types, $params);
        $ok = $stmt->execute();
        if (!$ok) {
            echo json_encode(['success' => false, 'error' => $stmt->error]);
            exit;
        }

        $row = mysqli_query($conn, "SELECT * FROM billing_tiket_manager WHERE id = $id")->fetch_assoc();
        echo json_encode(['success' => true, 'data' => tm_with_source($row)]);
        break;

    // DELETE: remove a ticket, only if it belongs to a server owned by this account.
    case 'DELETE':
        $id = (int)($input['id'] ?? ($_GET['id'] ?? 0));
        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'ID diperlukan']);
            exit;
        }
        if (empty($allowed_server_ids)) {
            echo json_encode(['success' => false, 'error' => 'Tiket tidak ditemukan atau tidak diizinkan']);
            exit;
        }
        $servers_in = implode(',', $allowed_server_ids);
        $stmt = $conn->prepare("DELETE FROM billing_tiket_manager WHERE id = ? AND server_id IN ($servers_in)");
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        if ($ok && $stmt->affected_rows === 0) {
            echo json_encode(['success' => false, 'error' => 'Tiket tidak ditemukan atau tidak diizinkan']);
            exit;
        }
        echo json_encode(['success' => $ok]);
        break;

    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method tidak didukung']);
}
