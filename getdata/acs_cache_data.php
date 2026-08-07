<?php
/**
 * ACS Cache Data API
 * Returns ACS device data matching a customer IDPEL from the local cache file.
 * Called via AJAX from tables.php every 30 seconds.
 */

header('Content-Type: application/json; charset=utf-8');

session_start();
function buildPortalAcsSecret(): string
{
    $config_file = dirname(__DIR__) . '/config.json';
    if (!file_exists($config_file)) {
        return '';
    }
    $config = json_decode((string)file_get_contents($config_file), true);
    if (!is_array($config)) {
        return '';
    }
    return hash('sha256', (string)($config['db_pass'] ?? '') . '|' . (string)($config['domain'] ?? '') . '|portal-acs');
}

function isValidPortalAcsToken(string $idpel, string $token): bool
{
    $idpel = strtolower(trim($idpel));
    $token = trim($token);
    if ($idpel === '' || $token === '') {
        return false;
    }

    $secret = buildPortalAcsSecret();
    if ($secret === '') {
        return false;
    }

    $current = hash_hmac('sha256', $idpel . '|' . date('YmdH'), $secret);
    $prev = hash_hmac('sha256', $idpel . '|' . date('YmdH', time() - 3600), $secret);
    return hash_equals($current, $token) || hash_equals($prev, $token);
}

function normalizePhoneDigits(string $value): string
{
    return preg_replace('/\D+/', '', $value);
}

function normalizePhoneTo62(string $value): string
{
    $digits = normalizePhoneDigits($value);
    if ($digits === '') {
        return '';
    }
    if (substr($digits, 0, 2) === '62') {
        return $digits;
    }
    if (substr($digits, 0, 1) === '0') {
        return '62' . substr($digits, 1);
    }
    return '62' . $digits;
}

function resolvePortalCookieToIdpel(string $cookieValue): string
{
    $cookieValue = trim($cookieValue);
    if ($cookieValue === '') {
        return '';
    }

    $config_file = dirname(__DIR__) . '/config.json';
    if (!file_exists($config_file)) {
        return '';
    }
    $config = json_decode((string)file_get_contents($config_file), true);
    if (!is_array($config)) {
        return '';
    }

    $conn = @mysqli_connect(
        $config['db_host'] ?? 'localhost',
        $config['db_user'] ?? 'root',
        $config['db_pass'] ?? '',
        $config['db_name'] ?? ''
    );
    if (!$conn) {
        return '';
    }
    mysqli_set_charset($conn, 'utf8mb4');

    $wa62 = normalizePhoneTo62($cookieValue);
    $wa0 = ($wa62 !== '' && substr($wa62, 0, 2) === '62') ? ('0' . substr($wa62, 2)) : '';
    $rawDigits = normalizePhoneDigits($cookieValue);

    $sql = "SELECT IDPEL FROM pelanggan
            WHERE IDPEL = ?
               OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(NOWA,'+',''),' ',''),'-',''),'(',''),')','') IN (?,?,?)
            LIMIT 1";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        mysqli_close($conn);
        return '';
    }

    mysqli_stmt_bind_param($stmt, 'ssss', $cookieValue, $wa62, $wa0, $rawDigits);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $dbIdpel);
    $resolved = mysqli_stmt_fetch($stmt) ? (string)$dbIdpel : '';
    mysqli_stmt_close($stmt);
    mysqli_close($conn);

    return trim($resolved);
}

// Validate IDPEL input
$idpel = trim((string)($_GET['idpel'] ?? ''));
if ($idpel === '' || strlen($idpel) > 100) {
    echo json_encode(['error' => 'IDPEL required (max 100 chars)', 'devices' => [], 'synced_at' => null]);
    exit;
}
// Restrict to safe characters
if (!preg_match('/^[a-zA-Z0-9_\-\.@]+$/', $idpel)) {
    echo json_encode(['error' => 'IDPEL contains invalid characters', 'devices' => [], 'synced_at' => null]);
    exit;
}

$has_admin_session = !empty($_SESSION['id']) || !empty($_SESSION['PEMILIK']);
$portal_cookie = trim((string)($_COOKIE['idselect'] ?? ''));
$portal_token = trim((string)($_GET['acs_token'] ?? ''));
$portal_token_valid = isValidPortalAcsToken($idpel, $portal_token);

if (!$has_admin_session && $portal_cookie === '' && !$portal_token_valid) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if (!$has_admin_session) {
    if ($portal_cookie !== '') {
        $resolved_idpel = resolvePortalCookieToIdpel($portal_cookie);
        if ($resolved_idpel === '' || strtolower($resolved_idpel) !== strtolower($idpel)) {
            http_response_code(403);
            echo json_encode(['error' => 'Akses data WiFi tidak diizinkan', 'devices' => [], 'synced_at' => null]);
            exit;
        }
    } elseif (!$portal_token_valid) {
        http_response_code(403);
        echo json_encode(['error' => 'Akses data WiFi tidak diizinkan', 'devices' => [], 'synced_at' => null]);
        exit;
    }
}

$cache_file = dirname(__DIR__) . '/notifdata/acs_devices_cache.json';
$cache_ttl  = 3600; // 1 hour

if (!file_exists($cache_file)) {
    echo json_encode([
        'error'      => 'Cache belum ada. Aktifkan ACS Auto-Sync di menu Daftar Server ACS.',
        'devices'    => [],
        'synced_at'  => null,
        'cache_age'  => null,
        'total_cached' => 0,
    ]);
    exit;
}

$cache_age  = time() - filemtime($cache_file);
$cache_raw  = file_get_contents($cache_file);
$cache_data = json_decode($cache_raw, true);

if (!is_array($cache_data) || !isset($cache_data['devices'])) {
    echo json_encode(['error' => 'Cache rusak', 'devices' => [], 'synced_at' => null, 'cache_age' => $cache_age]);
    exit;
}

/**
 * Extract SSID value for a specific WLANConfiguration index.
 */
function extractSsidFromParams(array $params, int $index): string
{
    $candidates = [
        "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$index}.SSID",
        "Device.LANDevice.1.WLANConfiguration.{$index}.SSID",
        "Device.WiFi.SSID.{$index}.SSID",
        "WLANConfiguration.{$index}.SSID",
    ];
    foreach ($candidates as $c) {
        if (!empty($params[$c])) {
            return $params[$c];
        }
    }
    // Suffix fallback
    $sfx = ".WLANConfiguration.{$index}.SSID";
    foreach ($params as $k => $v) {
        if ($v !== '' && substr($k, -strlen($sfx)) === $sfx) {
            return $v;
        }
    }
    return '';
}

function detectSsidParamKeyFromParams(array $params, int $index): string
{
    $candidates = [
        "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$index}.SSID",
        "Device.LANDevice.1.WLANConfiguration.{$index}.SSID",
        "Device.WiFi.SSID.{$index}.SSID",
        "WLANConfiguration.{$index}.SSID",
    ];

    foreach ($candidates as $c) {
        if (array_key_exists($c, $params)) {
            return $c;
        }
    }

    $sfx1 = ".WLANConfiguration.{$index}.SSID";
    $sfx2 = ".WiFi.SSID.{$index}.SSID";
    foreach ($params as $k => $v) {
        if (substr($k, -strlen($sfx1)) === $sfx1 || substr($k, -strlen($sfx2)) === $sfx2) {
            return $k;
        }
    }

    return "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$index}.SSID";
}

function extractWifiPasswordFromParams(array $params, int $index): string
{
    $candidates = [
        "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$index}.PreSharedKey.1.KeyPassphrase",
        "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$index}.PreSharedKey.1.Passphrase",
        "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$index}.PreSharedKey.1.PreSharedKey",
        "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$index}.KeyPassphrase",
        "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$index}.Passphrase",
        "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$index}.PreSharedKey",
        "Device.LANDevice.1.WLANConfiguration.{$index}.PreSharedKey.1.KeyPassphrase",
        "Device.LANDevice.1.WLANConfiguration.{$index}.PreSharedKey.1.Passphrase",
        "Device.LANDevice.1.WLANConfiguration.{$index}.PreSharedKey.1.PreSharedKey",
        "Device.LANDevice.1.WLANConfiguration.{$index}.KeyPassphrase",
        "Device.LANDevice.1.WLANConfiguration.{$index}.Passphrase",
        "Device.LANDevice.1.WLANConfiguration.{$index}.PreSharedKey",
        "Device.WiFi.AccessPoint.{$index}.Security.KeyPassphrase",
        "Device.WiFi.AccessPoint.{$index}.Security.Passphrase",
        "Device.WiFi.AccessPoint.{$index}.Security.PreSharedKey",
        "WLANConfiguration.{$index}.PreSharedKey.1.KeyPassphrase",
        "WLANConfiguration.{$index}.PreSharedKey.1.Passphrase",
        "WLANConfiguration.{$index}.PreSharedKey.1.PreSharedKey",
        "WLANConfiguration.{$index}.Passphrase",
    ];
    foreach ($candidates as $c) {
        if (array_key_exists($c, $params) && $params[$c] !== '') {
            return (string)$params[$c];
        }
    }
    return '';
}

function detectWifiPasswordParamKeyFromParams(array $params, int $index): string
{
    $candidates = [
        "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$index}.PreSharedKey.1.KeyPassphrase",
        "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$index}.PreSharedKey.1.Passphrase",
        "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$index}.PreSharedKey.1.PreSharedKey",
        "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$index}.X_CT-COM_KeyPassphrase",
        "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$index}.KeyPassphrase",
        "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$index}.Passphrase",
        "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$index}.PreSharedKey",
        "Device.LANDevice.1.WLANConfiguration.{$index}.PreSharedKey.1.KeyPassphrase",
        "Device.LANDevice.1.WLANConfiguration.{$index}.PreSharedKey.1.Passphrase",
        "Device.LANDevice.1.WLANConfiguration.{$index}.PreSharedKey.1.PreSharedKey",
        "Device.LANDevice.1.WLANConfiguration.{$index}.KeyPassphrase",
        "Device.LANDevice.1.WLANConfiguration.{$index}.Passphrase",
        "Device.LANDevice.1.WLANConfiguration.{$index}.PreSharedKey",
        "Device.WiFi.AccessPoint.{$index}.Security.KeyPassphrase",
        "Device.WiFi.AccessPoint.{$index}.Security.Passphrase",
        "Device.WiFi.AccessPoint.{$index}.Security.PreSharedKey",
        "WLANConfiguration.{$index}.PreSharedKey.1.KeyPassphrase",
        "WLANConfiguration.{$index}.PreSharedKey.1.Passphrase",
        "WLANConfiguration.{$index}.Passphrase",
    ];

    foreach ($candidates as $c) {
        if (array_key_exists($c, $params)) {
            return $c;
        }
    }

    $suffixes = [
        ".WLANConfiguration.{$index}.PreSharedKey.1.KeyPassphrase",
        ".WLANConfiguration.{$index}.PreSharedKey.1.Passphrase",
        ".WLANConfiguration.{$index}.PreSharedKey.1.PreSharedKey",
        ".WLANConfiguration.{$index}.KeyPassphrase",
        ".WLANConfiguration.{$index}.Passphrase",
        ".WLANConfiguration.{$index}.PreSharedKey",
        ".WiFi.AccessPoint.{$index}.Security.KeyPassphrase",
        ".WiFi.AccessPoint.{$index}.Security.Passphrase",
        ".WiFi.AccessPoint.{$index}.Security.PreSharedKey",
    ];
    foreach ($params as $k => $v) {
        foreach ($suffixes as $suffix) {
            if (substr($k, -strlen($suffix)) === $suffix) {
                return $k;
            }
        }
    }

    return '';
}

function extractWifiEnableFromParams(array $params, int $index): string
{
    $candidates = [
        "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$index}.Enable",
        "Device.LANDevice.1.WLANConfiguration.{$index}.Enable",
        "Device.WiFi.SSID.{$index}.Enable",
        "WLANConfiguration.{$index}.Enable",
    ];
    foreach ($candidates as $c) {
        if (array_key_exists($c, $params) && $params[$c] !== '') {
            return (string)$params[$c];
        }
    }
    return '';
}

function detectWifiEnableParamKeyFromParams(array $params, int $index): string
{
    $candidates = [
        "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$index}.Enable",
        "Device.LANDevice.1.WLANConfiguration.{$index}.Enable",
        "Device.WiFi.SSID.{$index}.Enable",
        "WLANConfiguration.{$index}.Enable",
    ];
    foreach ($candidates as $c) {
        if (array_key_exists($c, $params)) {
            return $c;
        }
    }

    $suffixes = [
        ".WLANConfiguration.{$index}.Enable",
        ".WiFi.SSID.{$index}.Enable",
    ];
    foreach ($params as $k => $v) {
        foreach ($suffixes as $suffix) {
            if (substr($k, -strlen($suffix)) === $suffix) {
                return $k;
            }
        }
    }

    return '';
}

$idpel_lower = strtolower($idpel);
$matchedMap  = [];

foreach ($cache_data['devices'] as $device) {
    $user1  = strtolower((string)($device['pppoe_username']  ?? ''));
    $user2  = strtolower((string)($device['pppoe_username2'] ?? ''));
    $serial = strtolower((string)($device['serial']          ?? ''));

    $is_match = false;

    // Match pppoe_username (exact, or contains)
    if ($user1 !== '') {
        $is_match = ($user1 === $idpel_lower)
            || (strlen($user1) > 3 && strpos($user1, $idpel_lower) !== false)
            || (strlen($idpel_lower) > 3 && strpos($idpel_lower, $user1) !== false);
    }
    // Match pppoe_username2
    if (!$is_match && $user2 !== '') {
        $is_match = ($user2 === $idpel_lower)
            || (strlen($user2) > 3 && strpos($user2, $idpel_lower) !== false)
            || (strlen($idpel_lower) > 3 && strpos($idpel_lower, $user2) !== false);
    }
    // Exact serial match
    if (!$is_match && $serial !== '' && $serial === $idpel_lower) {
        $is_match = true;
    }

    if (!$is_match) continue;

    $params = $device['params'] ?? [];

    $deviceItem = [
        'server_id'       => (int)($device['server_id'] ?? 0),
        'serial_raw'      => (string)($device['serial'] ?? ''),
        'serial'          => htmlspecialchars($device['serial'] ?? '-', ENT_QUOTES, 'UTF-8'),
        'server_name'     => htmlspecialchars($device['server_name'] ?? '-', ENT_QUOTES, 'UTF-8'),
        'status'          => $device['status'] ?? 'UNKNOWN',
        'last_inform'     => $device['last_inform'] ?? '-',
        'pppoe_username'  => $device['pppoe_username']  ?? '',
        'pppoe_username2' => $device['pppoe_username2'] ?? '',
        'rx_power'        => $device['rx_power'] ?? '',
        'tx_power'        => $device['tx_power'] ?? '',
        'pppoe_ip'        => $device['pppoe_ip'] ?? '',
        'manufacturer'    => $device['manufacturer'] ?? '',
        'all_params'      => $params,
    ];

    for ($ssidIdx = 1; $ssidIdx <= 12; $ssidIdx++) {
        $ssidKey = 'ssid_' . $ssidIdx;
        $ssidPassKey = 'ssid_pass_' . $ssidIdx;
        $ssidEnableKey = 'ssid_enable_' . $ssidIdx;
        $ssidParamKey = 'ssid_param_' . $ssidIdx;
        $ssidPassParamKey = 'ssid_pass_param_' . $ssidIdx;
        $ssidEnableParamKey = 'ssid_enable_param_' . $ssidIdx;

        $deviceItem[$ssidKey] = isset($device[$ssidKey]) ? (string)$device[$ssidKey] : extractSsidFromParams($params, $ssidIdx);
        $deviceItem[$ssidPassKey] = isset($device[$ssidPassKey]) ? (string)$device[$ssidPassKey] : extractWifiPasswordFromParams($params, $ssidIdx);
        $deviceItem[$ssidEnableKey] = isset($device[$ssidEnableKey]) ? (string)$device[$ssidEnableKey] : extractWifiEnableFromParams($params, $ssidIdx);
        $deviceItem[$ssidParamKey] = isset($device[$ssidParamKey]) ? (string)$device[$ssidParamKey] : detectSsidParamKeyFromParams($params, $ssidIdx);
        $deviceItem[$ssidPassParamKey] = isset($device[$ssidPassParamKey]) ? (string)$device[$ssidPassParamKey] : detectWifiPasswordParamKeyFromParams($params, $ssidIdx);
        $deviceItem[$ssidEnableParamKey] = isset($device[$ssidEnableParamKey]) ? (string)$device[$ssidEnableParamKey] : detectWifiEnableParamKeyFromParams($params, $ssidIdx);
    }

    $serialRaw = strtolower(trim((string)($device['serial'] ?? '')));
    $serverId = (int)($device['server_id'] ?? 0);
    $userKey = trim($user1 !== '' ? $user1 : $user2);
    $uniqueKey = $serialRaw !== '' ? ('serial:' . $serialRaw) : ('user:' . $userKey . '|server:' . $serverId);

    $deviceItem['_score_last_inform'] = strtotime((string)($device['last_inform'] ?? '')) ?: 0;
    $ssidCount = 0;
    for ($ssidIdx = 1; $ssidIdx <= 12; $ssidIdx++) {
        $value = trim((string)($deviceItem['ssid_' . $ssidIdx] ?? ''));
        if ($value !== '') {
            $ssidCount++;
        }
    }
    $deviceItem['_score_ssid_count'] = $ssidCount;

    $existing = $matchedMap[$uniqueKey] ?? null;
    if (!is_array($existing)) {
        $matchedMap[$uniqueKey] = $deviceItem;
        continue;
    }

    $isBetter = false;
    if (($deviceItem['_score_last_inform'] ?? 0) > ($existing['_score_last_inform'] ?? 0)) {
        $isBetter = true;
    } elseif (($deviceItem['_score_last_inform'] ?? 0) === ($existing['_score_last_inform'] ?? 0)
        && ($deviceItem['_score_ssid_count'] ?? 0) > ($existing['_score_ssid_count'] ?? 0)
    ) {
        $isBetter = true;
    }

    if ($isBetter) {
        $matchedMap[$uniqueKey] = $deviceItem;
    }
}

$matched = array_values($matchedMap);
if ($matched) {
    usort($matched, function ($a, $b) {
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

    $best = $matched[0];
    unset($best['_score_last_inform'], $best['_score_ssid_count']);
    $matched = [$best];
}

echo json_encode([
    'devices'       => $matched,
    'synced_at'     => $cache_data['synced_at']        ?? null,
    'synced_ts'     => $cache_data['synced_timestamp'] ?? null,
    'cache_age'     => $cache_age,
    'cache_expired' => $cache_age > $cache_ttl,
    'total_cached'  => (int)($cache_data['devices_count'] ?? 0),
    'idpel'         => $idpel,
]);
