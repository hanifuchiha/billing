<?php
/**
 * ACS Sync Worker
 * Fetches ALL ACS device data from all GenieACS servers and caches locally (JSON file).
 * No database storage for devices - saves to local file only (no row-size limits).
 * Intended to be called via crontab every minute (curl URL).
 * Cache TTL: 1 hour, continuously updated on every run.
 */

// Restrict access to localhost (crontab curl) or CLI
$client_ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
$default_allowed = ['127.0.0.1', '::1', '::ffff:127.0.0.1'];
$dynamic_allowed = [];

/**
 * Check if an IPv4 address matches a CIDR range or exact IP.
 * Supports: "1.2.3.4", "1.2.3.0/24", "0.0.0.0/0"
 */
function ipMatchesCidr(string $ip, string $range): bool
{
    // Normalize IPv6-mapped IPv4 (::ffff:1.2.3.4 -> 1.2.3.4)
    if (strpos($ip, '::ffff:') === 0) {
        $ip = substr($ip, 7);
    }

    if (strpos($range, '/') === false) {
        return $ip === $range;
    }

    [$subnet, $prefix] = explode('/', $range, 2);
    $prefix = (int)$prefix;

    if ($prefix < 0 || $prefix > 32) {
        return false;
    }

    $ipLong     = ip2long($ip);
    $subnetLong = ip2long($subnet);

    if ($ipLong === false || $subnetLong === false) {
        return false;
    }

    if ($prefix === 0) {
        return true; // 0.0.0.0/0 matches everything
    }

    $mask = ~((1 << (32 - $prefix)) - 1);
    return ($ipLong & $mask) === ($subnetLong & $mask);
}

/**
 * Check if an IP is in the allowed list (supports exact IPs and CIDR ranges).
 */
function ipInAllowedList(string $ip, array $list): bool
{
    foreach ($list as $entry) {
        $entry = trim($entry);
        if ($entry === '') continue;
        if (ipMatchesCidr($ip, $entry)) {
            return true;
        }
    }
    return false;
}
// Cek allowed_ips dari semua server (jika ada server dengan allowed_ips, gunakan itu)
$config_file = __DIR__ . '/config.json';
if (file_exists($config_file)) {
    $config = json_decode(file_get_contents($config_file), true);
    $conn = @mysqli_connect(
        $config['db_host'] ?? 'localhost',
        $config['db_user'] ?? 'root',
        $config['db_pass'] ?? '',
        $config['db_name'] ?? ''
    );
    if ($conn) {
        $tbl_check = mysqli_query($conn, "SHOW TABLES LIKE 'acs_servers'");
        if ($tbl_check && mysqli_num_rows($tbl_check) > 0) {
            $res = mysqli_query($conn, "SELECT allowed_ips FROM acs_servers");
            while ($row = mysqli_fetch_assoc($res)) {
                if (!empty($row['allowed_ips'])) {
                    $ips = preg_split('/[\r\n,]+/', $row['allowed_ips']);
                    foreach ($ips as $ip) {
                        $ip = trim($ip);
                        if ($ip !== '' && !in_array($ip, $dynamic_allowed, true)) {
                            $dynamic_allowed[] = $ip;
                        }
                    }
                }
            }
        }
        mysqli_close($conn);
    }
}
$allowed = count($dynamic_allowed) > 0 ? $dynamic_allowed : $default_allowed;
if (PHP_SAPI !== 'cli' && !ipInAllowedList($client_ip, $allowed)) {
    // Check for token in header as secondary auth
    $secret_file = __DIR__ . '/notifdata/acs_sync_secret.txt';
    $expected_token = file_exists($secret_file) ? trim(file_get_contents($secret_file)) : '';
    $request_token = $_SERVER['HTTP_X_ACS_SYNC_TOKEN'] ?? ($_GET['token'] ?? '');
    if ($expected_token === '' || !hash_equals($expected_token, $request_token)) {
        http_response_code(403);
        echo json_encode([
            'error' => 'Forbidden',
            'client_ip' => $client_ip,
            'allowed' => $allowed,
            'petunjuk' => 'IP Anda tidak diizinkan, atau token salah/tidak dikirim.'
        ]);
        exit;
    }
}

set_time_limit(120);
header('Content-Type: application/json');

// Load config for DB credentials
$config_file = __DIR__ . '/config.json';
if (!file_exists($config_file)) {
    echo json_encode(['error' => 'config.json not found']);
    exit;
}
$config = json_decode(file_get_contents($config_file), true);
if (!is_array($config)) {
    echo json_encode(['error' => 'config.json invalid']);
    exit;
}

$cache_dir  = __DIR__ . '/notifdata';
$cache_file = $cache_dir . '/acs_devices_cache.json';
$lock_file  = $cache_dir . '/acs_sync.lock';

// Prevent concurrent runs
if (file_exists($lock_file)) {
    $lock_age = time() - filemtime($lock_file);
    if ($lock_age < 90) {
        echo json_encode(['status' => 'skipped', 'reason' => "Already running (lock age: {$lock_age}s)"]);
        exit;
    }
}
file_put_contents($lock_file, date('Y-m-d H:i:s') . ' PID:' . getmypid());

// Connect to DB (only to read server list, not to store device data)
$conn = @mysqli_connect(
    $config['db_host'] ?? 'localhost',
    $config['db_user'] ?? 'root',
    $config['db_pass'] ?? '',
    $config['db_name'] ?? ''
);
if (!$conn) {
    @unlink($lock_file);
    echo json_encode(['error' => 'DB connection failed: ' . mysqli_connect_error()]);
    exit;
}
mysqli_set_charset($conn, 'utf8mb4');

// Check if acs_servers table exists
$tbl_check = mysqli_query($conn, "SHOW TABLES LIKE 'acs_servers'");
if (!$tbl_check || mysqli_num_rows($tbl_check) === 0) {
    @unlink($lock_file);
    mysqli_close($conn);
    echo json_encode(['status' => 'skipped', 'reason' => 'acs_servers table not found']);
    exit;
}

// Fetch all configured ACS servers
$servers = [];
$res = mysqli_query($conn, "SELECT * FROM acs_servers ORDER BY id ASC");
while ($row = mysqli_fetch_assoc($res)) {
    $servers[] = $row;
}
mysqli_close($conn);

if (empty($servers)) {
    @unlink($lock_file);
    echo json_encode(['status' => 'skipped', 'reason' => 'No ACS servers configured']);
    exit;
}

/**
 * Recursively extract all key=value pairs from a nested ACS device structure.
 * Returns a flat associative array: "ParentKey.ChildKey" => "value"
 * No depth limit enforced in DB schema - only memory/time constraints apply.
 */
function extractAllParamsWorker(array $data, string $prefix = '', int $depth = 0): array
{
    $result = [];
    if ($depth > 12) {
        return $result;
    }

    foreach ($data as $key => $value) {
        $fullKey = $prefix !== '' ? ($prefix . '.' . $key) : (string)$key;

        if (is_array($value)) {
            // GenieACS _value pattern
            if (array_key_exists('_value', $value) && !is_array($value['_value'])) {
                $result[$fullKey] = (string)$value['_value'];
            } else {
                // Recurse into nested structure
                $nested = extractAllParamsWorker($value, $fullKey, $depth + 1);
                $result = array_merge($result, $nested);
            }
        } elseif (!is_null($value)) {
            $result[$fullKey] = (string)$value;
        }
    }

    return $result;
}

/**
 * Pick a parameter value by trying multiple key candidates (direct + suffix fallback).
 */
function pickParamWorker(array $params, array $keys): string
{
    foreach ($keys as $k) {
        if (isset($params[$k]) && $params[$k] !== '') {
            return $params[$k];
        }
    }
    // Suffix fallback
    foreach ($params as $pk => $pv) {
        if ($pv === '') continue;
        foreach ($keys as $k) {
            if (substr($pk, -strlen($k)) === $k) {
                return $pv;
            }
        }
    }
    return '';
}

/**
 * Pick a parameter by regex pattern to support dynamic index paths.
 */
function pickParamByRegexWorker(array $params, string $pattern): string
{
    foreach ($params as $pk => $pv) {
        if ($pv === '') continue;
        if (preg_match($pattern, $pk)) {
            return $pv;
        }
    }
    return '';
}

/**
 * Find first existing param key from candidates (or suffix fallback).
 */
function findParamKeyWorker(array $params, array $candidates, array $suffixes = []): string
{
    foreach ($candidates as $candidate) {
        if (array_key_exists($candidate, $params)) {
            return $candidate;
        }
    }

    foreach ($params as $key => $val) {
        foreach ($suffixes as $suffix) {
            if (substr($key, -strlen($suffix)) === $suffix) {
                return $key;
            }
        }
    }

    return '';
}

/**
 * Build WiFi fields (SSID/password/enable + param paths) for SSID index 1..12.
 */
function buildWifiFieldsWorker(array $params, int $maxSsid = 12): array
{
    $fields = [];

    for ($idx = 1; $idx <= $maxSsid; $idx++) {
        $ssidCandidates = [
            "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$idx}.SSID",
            "Device.LANDevice.1.WLANConfiguration.{$idx}.SSID",
            "Device.WiFi.SSID.{$idx}.SSID",
            "WLANConfiguration.{$idx}.SSID",
        ];
        $ssidSuffixes = [
            ".WLANConfiguration.{$idx}.SSID",
            ".WiFi.SSID.{$idx}.SSID",
        ];
        $ssidParamKey = findParamKeyWorker($params, $ssidCandidates, $ssidSuffixes);
        $ssidValue = ($ssidParamKey !== '' && isset($params[$ssidParamKey])) ? (string)$params[$ssidParamKey] : '';

        $passCandidates = [
            "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$idx}.PreSharedKey.1.KeyPassphrase",
            "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$idx}.PreSharedKey.1.Passphrase",
            "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$idx}.PreSharedKey.1.PreSharedKey",
            "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$idx}.KeyPassphrase",
            "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$idx}.Passphrase",
            "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$idx}.PreSharedKey",
            "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$idx}.X_CT-COM_KeyPassphrase",
            "Device.LANDevice.1.WLANConfiguration.{$idx}.PreSharedKey.1.KeyPassphrase",
            "Device.LANDevice.1.WLANConfiguration.{$idx}.PreSharedKey.1.Passphrase",
            "Device.LANDevice.1.WLANConfiguration.{$idx}.PreSharedKey.1.PreSharedKey",
            "Device.LANDevice.1.WLANConfiguration.{$idx}.KeyPassphrase",
            "Device.LANDevice.1.WLANConfiguration.{$idx}.Passphrase",
            "Device.LANDevice.1.WLANConfiguration.{$idx}.PreSharedKey",
            "Device.LANDevice.1.WLANConfiguration.{$idx}.X_CT-COM_KeyPassphrase",
            "Device.WiFi.AccessPoint.{$idx}.Security.KeyPassphrase",
            "Device.WiFi.AccessPoint.{$idx}.Security.Passphrase",
            "Device.WiFi.AccessPoint.{$idx}.Security.PreSharedKey",
            "Device.WiFi.AccessPoint.{$idx}.Security.X_CT-COM_KeyPassphrase",
            "WLANConfiguration.{$idx}.PreSharedKey.1.KeyPassphrase",
            "WLANConfiguration.{$idx}.PreSharedKey.1.Passphrase",
            "WLANConfiguration.{$idx}.PreSharedKey.1.PreSharedKey",
            "WLANConfiguration.{$idx}.Passphrase",
        ];

        $ssidRef = 'Device.WiFi.SSID.' . $idx;
        foreach ($params as $paramKey => $paramVal) {
            if (!preg_match('/^Device\.WiFi\.AccessPoint\.(\d+)\.SSIDReference$/i', $paramKey, $refMatch)) {
                continue;
            }
            $normalizedRef = rtrim(trim((string)$paramVal), '.');
            if ($normalizedRef === '' || strcasecmp($normalizedRef, $ssidRef) !== 0) {
                continue;
            }
            $apIndex = $refMatch[1];
            $passCandidates[] = "Device.WiFi.AccessPoint.{$apIndex}.Security.KeyPassphrase";
            $passCandidates[] = "Device.WiFi.AccessPoint.{$apIndex}.Security.Passphrase";
            $passCandidates[] = "Device.WiFi.AccessPoint.{$apIndex}.Security.PreSharedKey";
            $passCandidates[] = "Device.WiFi.AccessPoint.{$apIndex}.Security.X_CT-COM_KeyPassphrase";
        }

        $passSuffixes = [
            ".WLANConfiguration.{$idx}.PreSharedKey.1.KeyPassphrase",
            ".WLANConfiguration.{$idx}.PreSharedKey.1.Passphrase",
            ".WLANConfiguration.{$idx}.PreSharedKey.1.PreSharedKey",
            ".WLANConfiguration.{$idx}.KeyPassphrase",
            ".WLANConfiguration.{$idx}.Passphrase",
            ".WLANConfiguration.{$idx}.PreSharedKey",
            ".WLANConfiguration.{$idx}.X_CT-COM_KeyPassphrase",
            ".WiFi.AccessPoint.{$idx}.Security.KeyPassphrase",
            ".WiFi.AccessPoint.{$idx}.Security.Passphrase",
            ".WiFi.AccessPoint.{$idx}.Security.PreSharedKey",
            ".WiFi.AccessPoint.{$idx}.Security.X_CT-COM_KeyPassphrase",
        ];
        $passParamKey = findParamKeyWorker($params, $passCandidates, $passSuffixes);
        $passValue = ($passParamKey !== '' && isset($params[$passParamKey])) ? (string)$params[$passParamKey] : '';

        $enableCandidates = [
            "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$idx}.Enable",
            "Device.LANDevice.1.WLANConfiguration.{$idx}.Enable",
            "Device.WiFi.SSID.{$idx}.Enable",
            "WLANConfiguration.{$idx}.Enable",
        ];
        $enableSuffixes = [
            ".WLANConfiguration.{$idx}.Enable",
            ".WiFi.SSID.{$idx}.Enable",
        ];
        $enableParamKey = findParamKeyWorker($params, $enableCandidates, $enableSuffixes);
        $enableValue = ($enableParamKey !== '' && isset($params[$enableParamKey])) ? (string)$params[$enableParamKey] : '';

        $fields['ssid_' . $idx] = $ssidValue;
        $fields['ssid_pass_' . $idx] = $passValue;
        $fields['ssid_enable_' . $idx] = $enableValue;
        $fields['ssid_param_' . $idx] = $ssidParamKey;
        $fields['ssid_pass_param_' . $idx] = $passParamKey;
        $fields['ssid_enable_param_' . $idx] = $enableParamKey;
    }

    return $fields;
}

$all_devices = [];
$synced_at   = date('Y-m-d H:i:s');
$errors      = [];

foreach ($servers as $server) {
    $nbi_port = isset($server['nbi_port']) && $server['nbi_port'] !== null ? intval($server['nbi_port']) : intval($server['port']) + 2;
    $domain   = trim((string)($server['domain'] ?? ''));
    if ($domain === '') {
        $errors[] = "Server ID {$server['id']}: empty domain, skipped";
        continue;
    }

    // Build the GenieACS NBI URL - fetch ALL devices with all params
    $api_url = "http://{$domain}:{$nbi_port}/devices";

    $ch = curl_init($api_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,  // No redirects - prevents SSRF escalation
        CURLOPT_MAXREDIRS      => 0,
    ]);
    if (!empty($server['username_acs']) && !empty($server['password_acs'])) {
        curl_setopt($ch, CURLOPT_USERPWD, $server['username_acs'] . ':' . $server['password_acs']);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    }

    $response   = curl_exec($ch);
    $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($http_code !== 200 || !$response) {
        $errors[] = "Server {$server['nama_server']}: HTTP {$http_code}" . ($curl_error ? " ({$curl_error})" : '');
        continue;
    }

    $devices = json_decode($response, true);
    if (!is_array($devices)) {
        $errors[] = "Server {$server['nama_server']}: Invalid JSON response";
        continue;
    }

    $server_device_count = 0;
    foreach ($devices as $device) {
        $serial = (string)($device['_id'] ?? '');
        if ($serial === '') continue;

        // Extract ALL parameters from all root keys
        $all_params = [];
        if (isset($device['VirtualParameters']) && is_array($device['VirtualParameters'])) {
            $vp = extractAllParamsWorker($device['VirtualParameters'], 'VirtualParameters');
            $all_params = array_merge($all_params, $vp);
        }
        if (isset($device['InternetGatewayDevice']) && is_array($device['InternetGatewayDevice'])) {
            $igd = extractAllParamsWorker($device['InternetGatewayDevice'], 'InternetGatewayDevice');
            $all_params = array_merge($all_params, $igd);
        }
        if (isset($device['Device']) && is_array($device['Device'])) {
            $dv = extractAllParamsWorker($device['Device'], 'Device');
            $all_params = array_merge($all_params, $dv);
        }

        // Extract key identification fields
        $pppoe_user  = pickParamWorker($all_params, [
            'VirtualParameters.pppoeUsername',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.2.WANPPPConnection.1.Username',
            'pppoeUsername'
        ]);
        if ($pppoe_user === '') {
            $pppoe_user = pickParamByRegexWorker(
                $all_params,
                '/^InternetGatewayDevice\.WANDevice\.\d+\.WANConnectionDevice\.\d+\.WANPPPConnection\.\d+\.Username$/'
            );
        }
        $pppoe_user2 = pickParamWorker($all_params, ['VirtualParameters.pppoeUsername2', 'pppoeUsername2']);
        $rx_power    = pickParamWorker($all_params, ['VirtualParameters.RXPower', 'RXPower']);
        $tx_power    = pickParamWorker($all_params, ['VirtualParameters.TXPower', 'TXPower']);
        $pppoe_ip    = pickParamWorker($all_params, ['VirtualParameters.pppoeIP', 'VirtualParameters.pppoeIP2', 'pppoeIP', 'pppoeIP2']);

        // last_inform timestamp
        $last_inform_raw = $device['_lastInform'] ?? null;
        $last_inform     = '';
        $is_online       = 'OFFLINE';
        if (is_numeric($last_inform_raw)) {
            $ts = intval($last_inform_raw);
            if ($ts > 10000000000) $ts = intval($ts / 1000);
            $last_inform = date('Y-m-d H:i:s', $ts);
            $is_online   = (time() - $ts) < 600 ? 'ONLINE' : 'OFFLINE';
        } elseif (!empty($last_inform_raw)) {
            $ts          = strtotime((string)$last_inform_raw);
            $last_inform = $ts ? date('Y-m-d H:i:s', $ts) : '';
            $is_online   = ($ts && (time() - $ts) < 600) ? 'ONLINE' : 'OFFLINE';
        }

        // Manufacturer from various paths
        $manufacturer = '';
        if (isset($device['_deviceId']['_Manufacturer'])) {
            $manufacturer = (string)$device['_deviceId']['_Manufacturer'];
        } elseif (isset($device['DeviceID']['Manufacturer']['_value'])) {
            $manufacturer = (string)$device['DeviceID']['Manufacturer']['_value'];
        } elseif (isset($device['DeviceID']['Manufacturer']) && !is_array($device['DeviceID']['Manufacturer'])) {
            $manufacturer = (string)$device['DeviceID']['Manufacturer'];
        }

        $wifiFields = buildWifiFieldsWorker($all_params, 12);

        $all_devices[] = [
            'serial'          => $serial,
            'server_id'       => (int)$server['id'],
            'server_name'     => (string)($server['nama_server'] ?? ''),
            'status'          => $is_online,
            'last_inform'     => $last_inform,
            'pppoe_username'  => $pppoe_user,
            'pppoe_username2' => $pppoe_user2,
            'rx_power'        => $rx_power,
            'tx_power'        => $tx_power,
            'pppoe_ip'        => $pppoe_ip,
            'manufacturer'    => $manufacturer,
            'params'          => $all_params,
        ] + $wifiFields;
        $server_device_count++;
    }

    error_log("[ACS Worker] Server {$server['nama_server']}: {$server_device_count} devices fetched");
}

// Write cache file (local JSON, no DB storage)
$cache_data = [
    'synced_at'        => $synced_at,
    'synced_timestamp' => time(),
    'devices_count'    => count($all_devices),
    'servers_count'    => count($servers),
    'errors'           => $errors,
    'devices'          => $all_devices,
];

$cache_json  = json_encode($cache_data, JSON_UNESCAPED_UNICODE);
$cache_saved = file_put_contents($cache_file, $cache_json);

@unlink($lock_file);

echo json_encode([
    'status'          => 'success',
    'synced_at'       => $synced_at,
    'devices_count'   => count($all_devices),
    'servers_synced'  => count($servers),
    'errors'          => $errors,
    'cache_size_kb'   => $cache_saved ? round($cache_saved / 1024, 1) : 0,
]);
