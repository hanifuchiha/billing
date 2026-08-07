<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function twk_response(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function twk_get_json_body(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function twk_parse_amount($value): float
{
    if (is_int($value) || is_float($value)) {
        return (float)$value;
    }

    if (!is_string($value)) {
        return 0.0;
    }

    $raw = trim($value);
    if ($raw === '') {
        return 0.0;
    }

    $raw = preg_replace('/[^0-9,\.\-]/', '', $raw);
    if ($raw === '' || $raw === '-' || $raw === '.' || $raw === ',') {
        return 0.0;
    }

    $hasDot = strpos($raw, '.') !== false;
    $hasComma = strpos($raw, ',') !== false;

    if ($hasDot && $hasComma) {
        $lastDot = strrpos($raw, '.');
        $lastComma = strrpos($raw, ',');
        if ($lastComma > $lastDot) {
            $raw = str_replace('.', '', $raw);
            $raw = str_replace(',', '.', $raw);
        } else {
            $raw = str_replace(',', '', $raw);
        }
    } elseif ($hasComma) {
        $parts = explode(',', $raw);
        $last = end($parts);
        if (count($parts) === 2 && strlen((string)$last) <= 2) {
            $raw = str_replace(',', '.', $raw);
        } else {
            $raw = str_replace(',', '', $raw);
        }
    } elseif ($hasDot) {
        $parts = explode('.', $raw);
        $last = end($parts);
        if (count($parts) > 2 || strlen((string)$last) === 3) {
            $raw = str_replace('.', '', $raw);
        }
    }

    return (float)$raw;
}

function twk_row_value(array $row, array $keys, $default = null)
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
            return $row[$key];
        }
        $lower = strtolower($key);
        if (array_key_exists($lower, $row) && $row[$lower] !== null && $row[$lower] !== '') {
            return $row[$lower];
        }
        $upper = strtoupper($key);
        if (array_key_exists($upper, $row) && $row[$upper] !== null && $row[$upper] !== '') {
            return $row[$upper];
        }
    }
    return $default;
}

function twk_row_amount(array $row, array $keys, float $default = 0.0): float
{
    $value = twk_row_value($row, $keys, null);
    if ($value === null || $value === '') {
        return $default;
    }
    return twk_parse_amount($value);
}

function twk_normalize_phone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone);
    if ($digits === '') {
        return '';
    }

    if (strpos($digits, '62') === 0) {
        return $digits;
    }

    if (strpos($digits, '0') === 0) {
        return '62' . substr($digits, 1);
    }

    return '62' . $digits;
}

function twk_resolve_logo_owner(mysqli $conn, string $pemilik): string
{
    $pemilik = trim($pemilik);
    if ($pemilik === '') {
        return '';
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT u.USERNAME
         FROM server s
         INNER JOIN user u ON u.id = s.user_id
         WHERE s.PEMILIK = ?
         ORDER BY s.id ASC
         LIMIT 1"
    );

    if (!$stmt) {
        return '';
    }

    mysqli_stmt_bind_param($stmt, 's', $pemilik);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);

    $username = trim((string)($row['USERNAME'] ?? ''));
    return $username;
}

function twk_pick_logo_key(string $logoOwner, string $pemilik): string
{
    $uploadsDir = dirname(__DIR__, 2) . '/uploads';
    $candidates = [$logoOwner, $pemilik];

    foreach ($candidates as $candidateRaw) {
        $candidateRaw = trim((string)$candidateRaw);
        if ($candidateRaw === '') {
            continue;
        }

        $candidate = preg_replace('/[^A-Za-z0-9._-]/', '', $candidateRaw);
        if ($candidate === null || $candidate === '') {
            continue;
        }

        $profilePath = $uploadsDir . '/profile-' . $candidate . '.png';
        if (file_exists($profilePath)) {
            return $candidate;
        }
    }

    $fallbackLogo = $uploadsDir . '/logo.png';
    if (file_exists($fallbackLogo)) {
        $fallbackProfileKey = 'logo';
        $fallbackProfilePath = $uploadsDir . '/profile-' . $fallbackProfileKey . '.png';
        if (!file_exists($fallbackProfilePath)) {
            @copy($fallbackLogo, $fallbackProfilePath);
        }
        return $fallbackProfileKey;
    }

    return 'QTS';
}

function twk_attach_logo_owner(mysqli $conn, array $pelanggan): array
{
    $pemilik = trim((string)($pelanggan['PEMILIK'] ?? ''));
    $logoOwner = twk_resolve_logo_owner($conn, $pemilik);
    $pelanggan['_LOGO_OWNER'] = twk_pick_logo_key($logoOwner, $pemilik);
    return $pelanggan;
}

function twk_load_config(): array
{
    $configPath = dirname(__DIR__, 2) . '/config.json';
    if (!file_exists($configPath)) {
        return [];
    }

    $json = json_decode(file_get_contents($configPath), true);
    return is_array($json) ? $json : [];
}

function twk_db_connect(): mysqli
{
    $cfg = twk_load_config();

    $host = $cfg['db_host'] ?? 'localhost';
    $user = $cfg['db_user'] ?? 'root';
    $pass = $cfg['db_pass'] ?? '';
    $name = $cfg['db_name'] ?? '';

    if ($name === '') {
        twk_response(500, [
            'success' => false,
            'message' => 'Konfigurasi database belum lengkap (db_name kosong).'
        ]);
    }

    $conn = mysqli_init();
    if (!$conn) {
        twk_response(500, ['success' => false, 'message' => 'Gagal inisialisasi koneksi database.']);
    }

    mysqli_options($conn, MYSQLI_OPT_CONNECT_TIMEOUT, 5);
    if (defined('MYSQLI_OPT_READ_TIMEOUT')) {
        mysqli_options($conn, MYSQLI_OPT_READ_TIMEOUT, 6);
    }

    if (!@mysqli_real_connect($conn, $host, $user, $pass, $name)) {
        twk_response(500, [
            'success' => false,
            'message' => 'Koneksi database gagal.',
            'error' => mysqli_connect_error()
        ]);
    }

    mysqli_set_charset($conn, 'utf8mb4');
    @mysqli_query($conn, 'SET SESSION MAX_EXECUTION_TIME=5000');
    return $conn;
}

function twk_db_connect_absensi(): mysqli
{
    $cfg = twk_load_config();

    $host = $cfg['db_host_absensi'] ?? ($cfg['db_host'] ?? 'localhost');
    $user = $cfg['db_user_absensi'] ?? ($cfg['db_user'] ?? 'root');
    $pass = $cfg['db_pass_absensi'] ?? ($cfg['db_pass'] ?? '');
    $name = $cfg['db_name_absensi'] ?? '';

    if ($name === '') {
        twk_response(500, [
            'success' => false,
            'message' => 'Konfigurasi database absensi belum lengkap (db_name_absensi kosong).'
        ]);
    }

    $conn = mysqli_init();
    if (!$conn) {
        twk_response(500, ['success' => false, 'message' => 'Gagal inisialisasi koneksi database absensi.']);
    }

    mysqli_options($conn, MYSQLI_OPT_CONNECT_TIMEOUT, 5);
    if (defined('MYSQLI_OPT_READ_TIMEOUT')) {
        mysqli_options($conn, MYSQLI_OPT_READ_TIMEOUT, 6);
    }

    if (!@mysqli_real_connect($conn, $host, $user, $pass, $name)) {
        twk_response(500, [
            'success' => false,
            'message' => 'Koneksi database absensi gagal.',
            'error' => mysqli_connect_error()
        ]);
    }

    mysqli_set_charset($conn, 'utf8mb4');
    @mysqli_query($conn, 'SET SESSION MAX_EXECUTION_TIME=5000');
    return $conn;
}

function twk_ensure_tables(mysqli $conn): void
{
    $sqlOtp = "CREATE TABLE IF NOT EXISTS twk_otp_codes (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        idpel VARCHAR(100) NOT NULL,
        phone VARCHAR(25) NOT NULL,
        otp_code VARCHAR(10) NOT NULL,
        expires_at DATETIME NOT NULL,
        used_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_twk_otp_phone (phone),
        KEY idx_twk_otp_idpel (idpel),
        KEY idx_twk_otp_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $sqlSession = "CREATE TABLE IF NOT EXISTS twk_mobile_sessions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        token VARCHAR(128) NOT NULL,
        idpel VARCHAR(100) NOT NULL,
        phone VARCHAR(25) DEFAULT NULL,
        expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        revoked_at DATETIME DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uk_twk_token (token),
        KEY idx_twk_session_idpel (idpel),
        KEY idx_twk_session_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    if (!mysqli_query($conn, $sqlOtp)) {
        twk_response(500, ['success' => false, 'message' => 'Gagal menyiapkan tabel OTP.']);
    }
    if (!mysqli_query($conn, $sqlSession)) {
        twk_response(500, ['success' => false, 'message' => 'Gagal menyiapkan tabel sesi mobile.']);
    }
}

function twk_find_customer(mysqli $conn, string $search): ?array
{
    $search = trim($search);
    if ($search === '') {
        return null;
    }

    $stmt = mysqli_prepare($conn, "SELECT * FROM pelanggan WHERE IDPEL = ? LIMIT 1");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 's', $search);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);
        if ($row) {
            return twk_attach_logo_owner($conn, $row);
        }
    }

    $phone = twk_normalize_phone($search);
    if ($phone === '') {
        return null;
    }

    $phone0 = '0' . substr($phone, 2);
    $phonePlus = '+' . $phone;

    $stmtPhone = mysqli_prepare($conn, "SELECT * FROM pelanggan WHERE NOWA IN (?,?,?) LIMIT 1");
    if ($stmtPhone) {
        mysqli_stmt_bind_param($stmtPhone, 'sss', $phone, $phone0, $phonePlus);
        mysqli_stmt_execute($stmtPhone);
        $res = mysqli_stmt_get_result($stmtPhone);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmtPhone);
        if ($row) {
            return twk_attach_logo_owner($conn, $row);
        }
    }

    $stmtSlow = mysqli_prepare(
        $conn,
        "SELECT * FROM pelanggan
         WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(NOWA,'+',''),' ',''),'-',''),'(',''),')','') IN (?,?,?)
         LIMIT 1"
    );
    if ($stmtSlow) {
        mysqli_stmt_bind_param($stmtSlow, 'sss', $phone, $phone0, $search);
        mysqli_stmt_execute($stmtSlow);
        $res = mysqli_stmt_get_result($stmtSlow);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmtSlow);
        if ($row) {
            return twk_attach_logo_owner($conn, $row);
        }
    }

    return null;
}

function twk_create_session(mysqli $conn, string $idpel, string $phone): array
{
    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + (60 * 60 * 24 * 30));

    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO twk_mobile_sessions (token, idpel, phone, expires_at) VALUES (?, ?, ?, ?)"
    );

    if (!$stmt) {
        twk_response(500, ['success' => false, 'message' => 'Gagal membuat sesi login.']);
    }

    mysqli_stmt_bind_param($stmt, 'ssss', $token, $idpel, $phone, $expiresAt);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    if (!$ok) {
        twk_response(500, ['success' => false, 'message' => 'Gagal menyimpan sesi login.']);
    }

    return [
        'token' => $token,
        'expires_at' => $expiresAt
    ];
}

function twk_get_auth_header(): string
{
    $candidates = [
        $_SERVER['HTTP_AUTHORIZATION'] ?? null,
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null,
        $_SERVER['Authorization'] ?? null
    ];

    foreach ($candidates as $value) {
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
    }

    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $k => $v) {
                if (strtolower((string)$k) === 'authorization' && is_string($v) && trim($v) !== '') {
                    return trim($v);
                }
            }
        }
    }

    if (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        if (is_array($headers)) {
            foreach ($headers as $k => $v) {
                if (strtolower((string)$k) === 'authorization' && is_string($v) && trim($v) !== '') {
                    return trim($v);
                }
            }
        }
    }

    return '';
}

function twk_require_auth(mysqli $conn): array
{
    $auth = twk_get_auth_header();
    if (!preg_match('/Bearer\s+(.+)/i', $auth, $m)) {
        twk_response(401, ['success' => false, 'message' => 'Token tidak ditemukan.']);
    }

    $token = trim($m[1]);

    $stmt = mysqli_prepare(
        $conn,
        "SELECT idpel, phone, expires_at, revoked_at FROM twk_mobile_sessions WHERE token = ? LIMIT 1"
    );
    if (!$stmt) {
        twk_response(500, ['success' => false, 'message' => 'Gagal validasi token.']);
    }

    mysqli_stmt_bind_param($stmt, 's', $token);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $session = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);

    if (!$session) {
        twk_response(401, ['success' => false, 'message' => 'Token tidak valid.']);
    }

    if (!empty($session['revoked_at'])) {
        twk_response(401, ['success' => false, 'message' => 'Token sudah tidak aktif.']);
    }

    if (strtotime($session['expires_at']) < time()) {
        twk_response(401, ['success' => false, 'message' => 'Token sudah kedaluwarsa.']);
    }

    // Rolling session: perpanjang masa aktif token setiap request valid.
    $newExpiresAt = date('Y-m-d H:i:s', time() + (60 * 60 * 24 * 30));
    $touch = mysqli_prepare($conn, "UPDATE twk_mobile_sessions SET expires_at = ? WHERE token = ? LIMIT 1");
    if ($touch) {
        mysqli_stmt_bind_param($touch, 'ss', $newExpiresAt, $token);
        mysqli_stmt_execute($touch);
        mysqli_stmt_close($touch);
        $session['expires_at'] = $newExpiresAt;
    }

    return [
        'token' => $token,
        'idpel' => (string)$session['idpel'],
        'phone' => (string)($session['phone'] ?? '')
    ];
}

function twk_customer_payload(array $pelanggan): array
{
    $logoOwner = trim((string)($pelanggan['_LOGO_OWNER'] ?? ($pelanggan['PEMILIK'] ?? '')));
    $logoOwnerForUrl = preg_replace('/[^A-Za-z0-9._-]/', '', $logoOwner);
    if ($logoOwnerForUrl === null) {
        $logoOwnerForUrl = '';
    }
    // Logo terpusat di dokumen/logo/ (sejajar crm/), diakses via URL /dokumen/logo/...
    $logoProfilePath = $logoOwnerForUrl !== ''
        ? ('https://quenbytekniksejahtera.com/dokumen/logo/profile-' . $logoOwnerForUrl . '.png')
        : 'https://quenbytekniksejahtera.com/dokumen/logo/logo.png';

    return [
        'idpel' => (string)($pelanggan['IDPEL'] ?? ''),
        'nama' => (string)($pelanggan['NAMA'] ?? ''),
        'nowa' => (string)($pelanggan['NOWA'] ?? ''),
        'email' => (string)($pelanggan['EMAIL'] ?? ''),
        'alamat' => (string)($pelanggan['ALAMAT'] ?? ''),
        'paket' => (string)($pelanggan['PAKET'] ?? ''),
        'tipe_bayar' => (string)($pelanggan['TIPE_BAYAR'] ?? ''),
        'tempo' => (string)($pelanggan['TEMPO'] ?? ''),
        // Samakan dengan web beranda (uploads/profile-$useraccount.png).
        'pemilik' => $logoOwner,
        'logo_url' => $logoProfilePath,
        'logo_fallback_url' => 'https://quenbytekniksejahtera.com/dokumen/logo/logo.png',
        'area' => (string)($pelanggan['AREA'] ?? ''),
        'odp' => (string)($pelanggan['ODP'] ?? ''),
        'status' => (string)($pelanggan['STATUS'] ?? '')
    ];
}

function twk_customer_chat_identity(array $pelanggan): string
{
    $idpel = trim((string)($pelanggan['IDPEL'] ?? ''));
    $nowa = trim((string)($pelanggan['NOWA'] ?? ''));
    $nowa = twk_normalize_phone($nowa);

    if ($idpel === '') {
        return $nowa !== '' ? ($nowa . ' ( Whatsapp : ' . $nowa . ' )') : '';
    }

    if ($nowa === '') {
        return $idpel;
    }

    return $idpel . ' ( Whatsapp : ' . $nowa . ' )';
}

function twk_build_portal_acs_token(string $idpel): string
{
    $cfg = twk_load_config();
    $secret = hash('sha256', (string)($cfg['db_pass'] ?? '') . '|' . (string)($cfg['domain'] ?? '') . '|portal-acs');
    return hash_hmac('sha256', strtolower(trim($idpel)) . '|' . date('YmdH'), $secret);
}

function twk_find_wifi_devices_from_cache(string $idpel): array
{
    $cachePath = dirname(__DIR__, 2) . '/notifdata/acs_devices_cache.json';
    if (!file_exists($cachePath)) {
        return [];
    }

    $json = json_decode((string)file_get_contents($cachePath), true);
    if (!is_array($json) || !isset($json['devices']) || !is_array($json['devices'])) {
        return [];
    }

    $needle = strtolower(trim($idpel));
    $deviceMap = [];

    foreach ($json['devices'] as $device) {
        if (!is_array($device)) {
            continue;
        }

        $u1 = strtolower(trim((string)($device['pppoe_username'] ?? '')));
        $u2 = strtolower(trim((string)($device['pppoe_username2'] ?? '')));
        $match = false;

        foreach ([$u1, $u2] as $u) {
            if ($u === '') {
                continue;
            }
            if ($u === $needle || (strlen($u) > 3 && strpos($u, $needle) !== false) || (strlen($needle) > 3 && strpos($needle, $u) !== false)) {
                $match = true;
                break;
            }
        }

        if (!$match) {
            continue;
        }

        $params = is_array($device['params'] ?? null) ? $device['params'] : [];
        $ssidItems = [];
        foreach ($params as $k => $v) {
            $path = (string)$k;
            if (!preg_match('/(WLANConfiguration\.(\d+)\.SSID$|WiFi\.SSID\.(\d+)\.SSID$)/i', $path, $m)) {
                continue;
            }
            $idx = (int)($m[2] ?: $m[3] ?: 0);
            if ($idx <= 0 || trim((string)$v) === '') {
                continue;
            }
            $ssidItems[] = [
                'index' => $idx,
                'name' => (string)$v,
                'path' => $path
            ];
        }

        usort($ssidItems, function ($a, $b) {
            return ((int)$a['index']) <=> ((int)$b['index']);
        });

        $serialRaw = (string)($device['serial'] ?? '');
        $serverId = (int)($device['server_id'] ?? 0);
        $userKey = $u1 !== '' ? $u1 : $u2;
        $uniqueKey = strtolower(trim($serialRaw)) !== ''
            ? ('serial:' . strtolower(trim($serialRaw)))
            : ('user:' . $userKey . '|server:' . $serverId);

        $candidate = [
            'server_id' => (int)($device['server_id'] ?? 0),
            'serial_raw' => $serialRaw,
            'last_inform' => (string)($device['last_inform'] ?? ''),
            'redaman' => (string)($device['redaman'] ?? ''),
            'tx' => (string)($device['tx'] ?? ''),
            'ssid_items' => $ssidItems,
            '_score_last_inform' => strtotime((string)($device['last_inform'] ?? '')) ?: 0,
            '_score_ssid_count' => count($ssidItems)
        ];

        $existing = $deviceMap[$uniqueKey] ?? null;
        if (!is_array($existing)) {
            $deviceMap[$uniqueKey] = $candidate;
            continue;
        }

        $isBetter = false;
        if (($candidate['_score_last_inform'] ?? 0) > ($existing['_score_last_inform'] ?? 0)) {
            $isBetter = true;
        } elseif (($candidate['_score_last_inform'] ?? 0) === ($existing['_score_last_inform'] ?? 0)
            && ($candidate['_score_ssid_count'] ?? 0) > ($existing['_score_ssid_count'] ?? 0)
        ) {
            $isBetter = true;
        }

        if ($isBetter) {
            $deviceMap[$uniqueKey] = $candidate;
        }
    }

    if (!$deviceMap) {
        return [];
    }

    $devices = array_values($deviceMap);
    usort($devices, function ($a, $b) {
        $cmpLastInform = ((int)($b['_score_last_inform'] ?? 0)) <=> ((int)($a['_score_last_inform'] ?? 0));
        if ($cmpLastInform !== 0) {
            return $cmpLastInform;
        }
        $cmpSsidCount = ((int)($b['_score_ssid_count'] ?? 0)) <=> ((int)($a['_score_ssid_count'] ?? 0));
        if ($cmpSsidCount !== 0) {
            return $cmpSsidCount;
        }
        return strcmp((string)($a['serial_raw'] ?? ''), (string)($b['serial_raw'] ?? ''));
    });

    $best = $devices[0];
    unset($best['_score_last_inform'], $best['_score_ssid_count']);
    return [$best];
}

function twk_ensure_payment_table(mysqli $conn): void
{
    $sql = "CREATE TABLE IF NOT EXISTS twk_payment_requests (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        idpel VARCHAR(100) NOT NULL,
        reference VARCHAR(120) NOT NULL,
        gateway VARCHAR(30) NOT NULL,
        payment_name VARCHAR(150) DEFAULT NULL,
        pay_code VARCHAR(150) DEFAULT NULL,
        checkout_url TEXT DEFAULT NULL,
        pay_url TEXT DEFAULT NULL,
        qr_url TEXT DEFAULT NULL,
        amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        status VARCHAR(40) NOT NULL DEFAULT 'UNPAID',
        expired_at DATETIME DEFAULT NULL,
        instructions_json LONGTEXT DEFAULT NULL,
        raw_json LONGTEXT DEFAULT NULL,
        cancelled_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_twk_payment_reference (reference),
        KEY idx_twk_payment_idpel (idpel),
        KEY idx_twk_payment_gateway (gateway),
        KEY idx_twk_payment_cancelled (cancelled_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    if (!mysqli_query($conn, $sql)) {
        twk_response(500, ['success' => false, 'message' => 'Gagal menyiapkan tabel payment mobile.']);
    }
}

function twk_get_default_payment_method(mysqli $conn, string $pemilik): string
{
    $pemilik = trim($pemilik);
    if ($pemilik === '') {
        return 'manual_bank';
    }

    $stmt = mysqli_prepare($conn, "SELECT payment_default FROM user WHERE USERNAME = ? LIMIT 1");
    if (!$stmt) {
        return 'manual_bank';
    }

    mysqli_stmt_bind_param($stmt, 's', $pemilik);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);

    $raw = strtolower(trim((string)($row['payment_default'] ?? 'manual_bank')));
    $allowed = ['tripay', 'duitku', 'midtrans', 'xendit', 'manual_bank'];
    return in_array($raw, $allowed, true) ? $raw : 'manual_bank';
}

function twk_get_tripay_config(mysqli $conn, string $pemilik): ?array
{
    $pemilik = trim($pemilik);
    if ($pemilik === '') {
        return null;
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT * FROM tripay WHERE pemilik = ? ORDER BY id DESC LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }

    mysqli_stmt_bind_param($stmt, 's', $pemilik);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);

    return is_array($row) ? $row : null;
}

function twk_get_duitku_config(mysqli $conn, string $pemilik): ?array
{
    $pemilik = trim($pemilik);
    if ($pemilik === '') {
        return null;
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT * FROM duitku WHERE pemilik = ? ORDER BY id DESC LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }

    mysqli_stmt_bind_param($stmt, 's', $pemilik);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);

    return is_array($row) ? $row : null;
}

function twk_get_manual_accounts(mysqli $conn, string $pemilik): array
{
    $pemilik = trim($pemilik);
    if ($pemilik === '') {
        return [];
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT METODE, REKENING, ATAS_NAMA FROM payment_settings WHERE PEMILIK = ? ORDER BY ID DESC LIMIT 10"
    );
    if (!$stmt) {
        return [];
    }

    mysqli_stmt_bind_param($stmt, 's', $pemilik);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    $rows = [];
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $rows[] = [
            'metode' => (string)($row['METODE'] ?? ''),
            'rekening' => (string)($row['REKENING'] ?? ''),
            'atas_nama' => (string)($row['ATAS_NAMA'] ?? '')
        ];
    }
    mysqli_stmt_close($stmt);

    return $rows;
}

function twk_find_package_price(mysqli $conn, array $pelanggan): float
{
    $paket = trim((string)twk_row_value($pelanggan, ['PAKET', 'paket'], ''));
    $brand = trim((string)twk_row_value($pelanggan, ['BRAND', 'brand'], ''));
    $pemilik = trim((string)twk_row_value($pelanggan, ['PEMILIK', 'pemilik'], ''));

    if ($paket === '') {
        return 0.0;
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT HARGA FROM paket WHERE PAKET LIKE CONCAT('%', ?, '%') AND BRAND = ? ORDER BY id DESC LIMIT 1"
    );
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'ss', $paket, $brand);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);
        $harga = is_array($row) ? twk_row_amount($row, ['HARGA', 'harga']) : 0.0;
        if ($harga > 0) {
            return $harga;
        }
    }

    $stmt2 = mysqli_prepare(
        $conn,
        "SELECT HARGA FROM paket WHERE PAKET = ? AND PEMILIK = ? ORDER BY id DESC LIMIT 1"
    );
    if ($stmt2) {
        mysqli_stmt_bind_param($stmt2, 'ss', $paket, $pemilik);
        mysqli_stmt_execute($stmt2);
        $res2 = mysqli_stmt_get_result($stmt2);
        $row2 = $res2 ? mysqli_fetch_assoc($res2) : null;
        mysqli_stmt_close($stmt2);
        $harga2 = is_array($row2) ? twk_row_amount($row2, ['HARGA', 'harga']) : 0.0;
        if ($harga2 > 0) {
            return $harga2;
        }
    }

    $stmt3 = mysqli_prepare(
        $conn,
        "SELECT harga FROM paket_hotspot WHERE paket = ? AND pemilik = ? ORDER BY id DESC LIMIT 1"
    );
    if ($stmt3) {
        mysqli_stmt_bind_param($stmt3, 'ss', $paket, $pemilik);
        mysqli_stmt_execute($stmt3);
        $res3 = mysqli_stmt_get_result($stmt3);
        $row3 = $res3 ? mysqli_fetch_assoc($res3) : null;
        mysqli_stmt_close($stmt3);
        $harga3 = is_array($row3) ? twk_row_amount($row3, ['harga', 'HARGA']) : 0.0;
        if ($harga3 > 0) {
            return $harga3;
        }
    }

    return twk_row_amount($pelanggan, ['HARGA', 'harga']);
}

function twk_get_payment_tax_bhp(mysqli $conn, string $pemilik, string $forcedGateway = ''): array
{
    $gateway = $forcedGateway !== '' ? strtolower(trim($forcedGateway)) : twk_get_default_payment_method($conn, $pemilik);
    $pajak = 11.0;
    $bhp = 0.0;

    if ($gateway === 'tripay') {
        $cfg = twk_get_tripay_config($conn, $pemilik) ?: [];
        $pajak = twk_row_amount($cfg, ['pajak', 'PAJAK'], 11.0);
        $bhp = twk_row_amount($cfg, ['bhps_uso', 'bhp_uso', 'BHPS_USO', 'BHP_USO'], 0.0);
        return ['gateway' => $gateway, 'pajak' => $pajak, 'bhp_uso' => $bhp];
    }

    if ($gateway === 'duitku') {
        $cfg = twk_get_duitku_config($conn, $pemilik) ?: [];
        $pajak = twk_row_amount($cfg, ['pajak', 'PAJAK'], 11.0);
        $bhp = twk_row_amount($cfg, ['bhps_uso', 'bhp_uso', 'BHPS_USO', 'BHP_USO'], 0.0);
        return ['gateway' => $gateway, 'pajak' => $pajak, 'bhp_uso' => $bhp];
    }

    if ($gateway === 'midtrans') {
        $stmt = mysqli_prepare($conn, "SELECT pajak, bhps_uso, bhp_uso FROM midtrans WHERE pemilik = ? ORDER BY id DESC LIMIT 1");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 's', $pemilik);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $row = $res ? mysqli_fetch_assoc($res) : null;
            mysqli_stmt_close($stmt);
            if (is_array($row)) {
                $pajak = twk_row_amount($row, ['pajak', 'PAJAK'], 11.0);
                $bhp = twk_row_amount($row, ['bhps_uso', 'bhp_uso', 'BHPS_USO', 'BHP_USO'], 0.0);
            }
        }
        return ['gateway' => $gateway, 'pajak' => $pajak, 'bhp_uso' => $bhp];
    }

    if ($gateway === 'xendit') {
        $stmt = mysqli_prepare($conn, "SELECT pajak, bhps_uso, bhp_uso FROM xendit WHERE pemilik = ? ORDER BY id DESC LIMIT 1");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 's', $pemilik);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $row = $res ? mysqli_fetch_assoc($res) : null;
            mysqli_stmt_close($stmt);
            if (is_array($row)) {
                $pajak = twk_row_amount($row, ['pajak', 'PAJAK'], 11.0);
                $bhp = twk_row_amount($row, ['bhps_uso', 'bhp_uso', 'BHPS_USO', 'BHP_USO'], 0.0);
            }
        }
        return ['gateway' => $gateway, 'pajak' => $pajak, 'bhp_uso' => $bhp];
    }

    if ($gateway === 'manual_bank') {
        $stmt = mysqli_prepare($conn, "SELECT bhps_uso, bhp_uso FROM manualbank WHERE pemilik = ? ORDER BY id DESC LIMIT 1");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 's', $pemilik);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $row = $res ? mysqli_fetch_assoc($res) : null;
            mysqli_stmt_close($stmt);
            if (is_array($row)) {
                $bhp = twk_row_amount($row, ['bhps_uso', 'bhp_uso', 'BHPS_USO', 'BHP_USO'], 0.0);
            }
        }
    }

    return ['gateway' => $gateway, 'pajak' => $pajak, 'bhp_uso' => $bhp];
}

function twk_pick(array $row, array $keys, string $default = ''): string
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $row) && trim((string)$row[$key]) !== '') {
            return (string)$row[$key];
        }
    }
    return $default;
}

function twk_payment_insert_local(
    mysqli $conn,
    string $idpel,
    string $reference,
    string $gateway,
    string $paymentName,
    string $payCode,
    string $checkoutUrl,
    string $payUrl,
    string $qrUrl,
    float $amount,
    string $status,
    ?string $expiredAt,
    array $instructions,
    array $raw
): void {
    twk_ensure_payment_table($conn);
    $instructionsJson = json_encode($instructions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $rawJson = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO twk_payment_requests
        (idpel, reference, gateway, payment_name, pay_code, checkout_url, pay_url, qr_url, amount, status, expired_at, instructions_json, raw_json)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            payment_name = VALUES(payment_name),
            pay_code = VALUES(pay_code),
            checkout_url = VALUES(checkout_url),
            pay_url = VALUES(pay_url),
            qr_url = VALUES(qr_url),
            amount = VALUES(amount),
            status = VALUES(status),
            expired_at = VALUES(expired_at),
            instructions_json = VALUES(instructions_json),
            raw_json = VALUES(raw_json),
            cancelled_at = NULL"
    );

    if (!$stmt) {
        return;
    }

    mysqli_stmt_bind_param(
        $stmt,
        'ssssssssdssss',
        $idpel,
        $reference,
        $gateway,
        $paymentName,
        $payCode,
        $checkoutUrl,
        $payUrl,
        $qrUrl,
        $amount,
        $status,
        $expiredAt,
        $instructionsJson,
        $rawJson
    );
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

function twk_payment_get_local_by_reference(mysqli $conn, string $reference): ?array
{
    twk_ensure_payment_table($conn);
    $stmt = mysqli_prepare(
        $conn,
        "SELECT * FROM twk_payment_requests WHERE reference = ? AND cancelled_at IS NULL ORDER BY id DESC LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }

    mysqli_stmt_bind_param($stmt, 's', $reference);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);

    if (!$row) {
        return null;
    }

    $instructions = [];
    if (!empty($row['instructions_json'])) {
        $decoded = json_decode((string)$row['instructions_json'], true);
        if (is_array($decoded)) {
            $instructions = $decoded;
        }
    }

    return [
        'reference' => (string)($row['reference'] ?? ''),
        'gateway' => (string)($row['gateway'] ?? ''),
        'payment_name' => (string)($row['payment_name'] ?? ''),
        'pay_code' => (string)($row['pay_code'] ?? ''),
        'checkout_url' => (string)($row['checkout_url'] ?? ''),
        'pay_url' => (string)($row['pay_url'] ?? ''),
        'qr_url' => (string)($row['qr_url'] ?? ''),
        'amount' => (float)($row['amount'] ?? 0),
        'status' => (string)($row['status'] ?? 'UNPAID'),
        'expired_at' => (string)($row['expired_at'] ?? ''),
        'instructions' => $instructions
    ];
}

function twk_payment_cancel_local(mysqli $conn, string $idpel, ?string $reference = null): int
{
    twk_ensure_payment_table($conn);
    if ($reference !== null && trim($reference) !== '') {
        $stmt = mysqli_prepare(
            $conn,
            "UPDATE twk_payment_requests
             SET cancelled_at = NOW(), status = 'CANCELED'
             WHERE idpel = ? AND reference = ? AND cancelled_at IS NULL"
        );
        if (!$stmt) {
            return 0;
        }
        mysqli_stmt_bind_param($stmt, 'ss', $idpel, $reference);
        mysqli_stmt_execute($stmt);
        $affected = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);
        return (int)$affected;
    }

    $stmt = mysqli_prepare(
        $conn,
        "UPDATE twk_payment_requests
         SET cancelled_at = NOW(), status = 'CANCELED'
         WHERE idpel = ? AND cancelled_at IS NULL"
    );
    if (!$stmt) {
        return 0;
    }
    mysqli_stmt_bind_param($stmt, 's', $idpel);
    mysqli_stmt_execute($stmt);
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    return (int)$affected;
}

function twk_payment_format_payload(array $payment): array
{
    $expiredAt = (string)($payment['expired_at'] ?? '');
    $expiredTs = strtotime($expiredAt);
    $isExpired = $expiredTs ? ($expiredTs < time()) : false;

    return [
        'reference' => (string)($payment['reference'] ?? ''),
        'gateway' => (string)($payment['gateway'] ?? ''),
        'payment_name' => (string)($payment['payment_name'] ?? ''),
        'pay_code' => (string)($payment['pay_code'] ?? ''),
        'checkout_url' => (string)($payment['checkout_url'] ?? ''),
        'pay_url' => (string)($payment['pay_url'] ?? ''),
        'qr_url' => (string)($payment['qr_url'] ?? ''),
        'amount' => (float)($payment['amount'] ?? 0),
        'status' => $isExpired ? 'EXPIRED' : (string)($payment['status'] ?? 'UNPAID'),
        'expired_at' => $expiredAt,
        'is_expired' => $isExpired,
        'instructions' => is_array($payment['instructions'] ?? null) ? $payment['instructions'] : []
    ];
}
