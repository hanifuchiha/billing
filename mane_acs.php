<?php
require 'header.php';

$statusType = '';
$statusMessage = '';

function acsEsc($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function acsSetFlash(&$type, &$message, $newType, $newMessage)
{
    $type = $newType;
    $message = $newMessage;
}

function acsNormalizeMac($mac)
{
    $raw = strtoupper(trim((string)$mac));
    $raw = preg_replace('/[^A-F0-9]/', '', $raw);
    if (strlen($raw) !== 12) {
        return strtoupper(trim((string)$mac));
    }

    return implode(':', str_split($raw, 2));
}

function acsExtractSoftwareVersion($value)
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return '-';
    }

    if (preg_match('/(V\d+(?:\.\d+){1,5}[A-Za-z0-9\-]*)/i', $raw, $matches)) {
        return strtoupper((string)$matches[1]);
    }

    if (strlen($raw) > 28) {
        return substr($raw, 0, 28) . '...';
    }

    return $raw;
}

function acsIsOnlineNow($lastSeen, $secondsWindow = 300)
{
    $ts = strtotime((string)$lastSeen);
    if ($ts === false) {
        return false;
    }

    return (time() - $ts) <= (int)$secondsWindow;
}

function acsExtractParamValue($row, $keyCandidates)
{
    foreach ((array)$keyCandidates as $key) {
        if (!is_array($row) || !array_key_exists($key, $row)) {
            continue;
        }

        $value = $row[$key];
        if (is_array($value)) {
            if (array_key_exists('_value', $value) && !is_array($value['_value'])) {
                return trim((string)$value['_value']);
            }
            if (array_key_exists('value', $value) && !is_array($value['value'])) {
                return trim((string)$value['value']);
            }
            if (isset($value[0]) && !is_array($value[0])) {
                return trim((string)$value[0]);
            }
            continue;
        }

        return trim((string)$value);
    }

    return '';
}

function acsHttpGet($url, &$httpCode = 0, &$errorMessage = '')
{
    $httpCode = 0;
    $errorMessage = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json'));

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($response === false) {
            $errorMessage = 'cURL error: ' . curl_error($ch);
        }
        curl_close($ch);

        return $response === false ? '' : (string)$response;
    }

    $ctx = stream_context_create(array(
        'http' => array(
            'method' => 'GET',
            'timeout' => 10,
            'header' => "Accept: application/json\r\n"
        ),
        'ssl' => array(
            'verify_peer' => false,
            'verify_peer_name' => false
        )
    ));
    $response = @file_get_contents($url, false, $ctx);
    if ($response === false) {
        $errorMessage = 'HTTP request failed';
        return '';
    }

    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $line) {
            if (preg_match('/\s(\d{3})\s/', (string)$line, $m)) {
                $httpCode = (int)$m[1];
                break;
            }
        }
    }

    return (string)$response;
}

function acsFetchGenieDevices($serverScheme, $serverDomain, $serverPort, &$errorMessage = '')
{
    $errorMessage = '';
    $ports = array();
    $defaultPort = strtolower((string)$serverScheme) === 'https' ? 443 : 80;
    $ports[] = 7557;
    $ports[] = (int)$serverPort;
    $ports[] = $defaultPort;
    $ports = array_values(array_unique(array_filter($ports, function ($p) {
        return (int)$p > 0 && (int)$p <= 65535;
    })));

    $endpointTried = array();
    foreach ($ports as $port) {
        $baseUrl = strtolower((string)$serverScheme) . '://' . trim((string)$serverDomain);
        if (!($port === 80 && strtolower((string)$serverScheme) === 'http') && !($port === 443 && strtolower((string)$serverScheme) === 'https')) {
            $baseUrl .= ':' . (int)$port;
        }

        $url = $baseUrl . '/devices?limit=2000';
        $endpointTried[] = $url;

        $httpCode = 0;
        $httpError = '';
        $raw = acsHttpGet($url, $httpCode, $httpError);

        if ($raw === '') {
            continue;
        }
        if ($httpCode >= 400) {
            $errorMessage = 'HTTP ' . $httpCode . ' dari endpoint GenieACS';
            continue;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $errorMessage = 'Respons GenieACS bukan JSON array valid';
            continue;
        }

        return array(
            'ok' => true,
            'devices' => $decoded,
            'endpoint' => $url
        );
    }

    if ($errorMessage === '') {
        $errorMessage = 'Tidak bisa terhubung ke endpoint GenieACS devices';
    }

    return array(
        'ok' => false,
        'devices' => array(),
        'endpoint' => implode(' | ', $endpointTried)
    );
}

function acsSyncDevicesFromGenie($conn, $pemilikName, $serverScheme, $serverDomain, $serverPort)
{
    $result = array(
        'ok' => false,
        'found' => 0,
        'inserted' => 0,
        'updated' => 0,
        'error' => '',
        'endpoint' => ''
    );

    $fetch = acsFetchGenieDevices($serverScheme, $serverDomain, $serverPort, $fetchError);
    $result['endpoint'] = (string)($fetch['endpoint'] ?? '');
    if (!(bool)($fetch['ok'] ?? false)) {
        $result['error'] = $fetchError !== '' ? $fetchError : 'Gagal ambil data device dari GenieACS';
        return $result;
    }

    $scopePemilik = mysqli_real_escape_string($conn, $pemilikName);
    foreach ((array)$fetch['devices'] as $rawDevice) {
        if (!is_array($rawDevice)) {
            continue;
        }

        $serial = acsExtractParamValue($rawDevice, array('DeviceID.SerialNumber', 'InternetGatewayDevice.DeviceInfo.SerialNumber', '_id'));
        $mac = acsNormalizeMac(acsExtractParamValue($rawDevice, array('DeviceID.ID', 'DeviceID.MACAddress', 'InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig.1.MACAddress')));
        $productClass = acsExtractParamValue($rawDevice, array('DeviceID.ProductClass', 'InternetGatewayDevice.DeviceInfo.ProductClass'));
        $softwareVersion = acsExtractParamValue($rawDevice, array('InternetGatewayDevice.DeviceInfo.SoftwareVersion', 'Device.DeviceInfo.SoftwareVersion'));
        $oui = acsExtractParamValue($rawDevice, array('DeviceID.OUI'));
        $remoteIp = acsExtractParamValue($rawDevice, array('InternetGatewayDevice.ManagementServer.ConnectionRequestURL', '_remoteAddress'));
        $informTime = acsExtractParamValue($rawDevice, array('_lastInform', 'Events.Inform'));
        $eventCode = acsExtractParamValue($rawDevice, array('Events.Inform'));

        $onuNo = $serial !== '' ? $serial : ($mac !== '' ? $mac : acsExtractParamValue($rawDevice, array('_id')));
        if ($onuNo === '') {
            continue;
        }

        $statusOnt = $informTime !== '' ? 'INFORM' : 'UNKNOWN';
        $lastSeenSql = 'NOW()';
        $informTs = strtotime((string)$informTime);
        if ($informTs !== false) {
            $lastSeenSql = "'" . mysqli_real_escape_string($conn, date('Y-m-d H:i:s', $informTs)) . "'";
        }

        $result['found']++;

        $onuEsc = mysqli_real_escape_string($conn, $onuNo);
        $macEsc = mysqli_real_escape_string($conn, $mac);
        $statusEsc = mysqli_real_escape_string($conn, $statusOnt);
        $eventEsc = mysqli_real_escape_string($conn, $eventCode);
        $serialEsc = mysqli_real_escape_string($conn, $serial);
        $ouiEsc = mysqli_real_escape_string($conn, $oui);
        $productEsc = mysqli_real_escape_string($conn, $productClass);
        $remoteEsc = mysqli_real_escape_string($conn, $remoteIp);
        $methodEsc = mysqli_real_escape_string($conn, 'NBI');
        $uriEsc = mysqli_real_escape_string($conn, '/devices');
        $agentEsc = mysqli_real_escape_string($conn, $softwareVersion);
        $informEsc = mysqli_real_escape_string($conn, $informTime);

        $checkSql = "SELECT id FROM acs_ont_devices WHERE pemilik = '$scopePemilik' AND mac_address = '$macEsc' AND onu_no = '$onuEsc' LIMIT 1";
        $checkRes = mysqli_query($conn, $checkSql);
        $existing = ($checkRes && mysqli_num_rows($checkRes) > 0) ? mysqli_fetch_assoc($checkRes) : null;

        if ($existing && isset($existing['id'])) {
            $deviceId = (int)$existing['id'];
            $updateSql = "UPDATE acs_ont_devices
                          SET status_ont = '$statusEsc', event_code = '$eventEsc', serial_number = '$serialEsc', oui = '$ouiEsc',
                              product_class = '$productEsc', remote_ip = '$remoteEsc', req_method = '$methodEsc', req_uri = '$uriEsc',
                              user_agent = '$agentEsc', inform_time = '$informEsc', source_file = 'genieacs_nbi', last_seen = $lastSeenSql
                          WHERE id = $deviceId LIMIT 1";
            mysqli_query($conn, $updateSql);
            $result['updated']++;
        } else {
            $insertSql = "INSERT INTO acs_ont_devices
                          (pemilik, account_name, onu_no, mac_address, status_ont, event_code, serial_number, oui, product_class, remote_ip, req_method, req_uri, user_agent, inform_time, chip_id, portnum, distance, temperature, tx_power, rx_power, source_file, first_seen, last_seen)
                          VALUES
                          ('$scopePemilik', '', '$onuEsc', '$macEsc', '$statusEsc', '$eventEsc', '$serialEsc', '$ouiEsc', '$productEsc', '$remoteEsc', '$methodEsc', '$uriEsc', '$agentEsc', '$informEsc', '$productEsc', '$eventEsc', '-', '-', '-', '-', 'genieacs_nbi', NOW(), $lastSeenSql)";
            mysqli_query($conn, $insertSql);
            $result['inserted']++;
        }
    }

    $result['ok'] = true;
    return $result;
}

function acsDetectHostDomain()
{
    $host = '';
    if (!empty($_SERVER['HTTP_HOST'])) {
        $host = (string)$_SERVER['HTTP_HOST'];
    } elseif (!empty($_SERVER['SERVER_NAME'])) {
        $host = (string)$_SERVER['SERVER_NAME'];
    } elseif (!empty($_SERVER['SERVER_ADDR'])) {
        $host = (string)$_SERVER['SERVER_ADDR'];
    }

    $host = trim(preg_replace('/:\\d+$/', '', $host));
    return $host !== '' ? $host : 'localhost';
}

function acsCreateToken($length = 32)
{
    if (function_exists('random_bytes')) {
        return bin2hex(random_bytes((int)max(8, $length / 2)));
    }

    return md5(uniqid((string)mt_rand(), true));
}

function acsParseServerInput($rawInput, $fallbackDomain, $fallbackPort = 7547, $fallbackScheme = 'http')
{
    $input = trim((string)$rawInput);
    if ($input === '') {
        return array(
            'scheme' => $fallbackScheme,
            'domain' => $fallbackDomain,
            'port' => (int)$fallbackPort,
            'has_explicit_port' => false
        );
    }

    $hasScheme = (bool)preg_match('~^[a-zA-Z][a-zA-Z0-9+\-.]*://~', $input);
    $toParse = $hasScheme ? $input : ($fallbackScheme . '://' . $input);
    $parts = parse_url($toParse);

    $scheme = strtolower((string)($parts['scheme'] ?? $fallbackScheme));
    if ($scheme !== 'http' && $scheme !== 'https') {
        $scheme = $fallbackScheme;
    }

    $domain = trim((string)($parts['host'] ?? ''));
    if ($domain === '') {
        $domain = trim((string)$fallbackDomain);
    }

    $port = isset($parts['port']) ? (int)$parts['port'] : (int)$fallbackPort;
    if ($port < 1 || $port > 65535) {
        $port = (int)$fallbackPort;
    }

    return array(
        'scheme' => $scheme,
        'domain' => $domain,
        'port' => $port,
        'has_explicit_port' => isset($parts['port'])
    );
}

$createAccountsTableSql = "
CREATE TABLE IF NOT EXISTS acs_tr069_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pemilik VARCHAR(120) NOT NULL,
    area VARCHAR(120) DEFAULT '',
    account_name VARCHAR(120) NOT NULL,
    tr069_username VARCHAR(150) NOT NULL,
    tr069_password VARCHAR(150) NOT NULL,
    acs_url VARCHAR(255) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'AKTIF',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_pemilik_name (pemilik, account_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
";
mysqli_query($conn, $createAccountsTableSql);

$createDevicesTableSql = "
CREATE TABLE IF NOT EXISTS acs_ont_devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pemilik VARCHAR(120) NOT NULL,
    account_name VARCHAR(120) DEFAULT '',
    onu_no VARCHAR(80) DEFAULT '',
    mac_address VARCHAR(80) DEFAULT '',
    status_ont VARCHAR(60) DEFAULT '',
    event_code VARCHAR(120) DEFAULT '',
    serial_number VARCHAR(120) DEFAULT '',
    oui VARCHAR(64) DEFAULT '',
    product_class VARCHAR(120) DEFAULT '',
    remote_ip VARCHAR(80) DEFAULT '',
    req_method VARCHAR(12) DEFAULT '',
    req_uri VARCHAR(255) DEFAULT '',
    user_agent VARCHAR(255) DEFAULT '',
    inform_time VARCHAR(120) DEFAULT '',
    chip_id VARCHAR(120) DEFAULT '',
    portnum VARCHAR(60) DEFAULT '',
    distance VARCHAR(60) DEFAULT '',
    temperature VARCHAR(60) DEFAULT '',
    tx_power VARCHAR(60) DEFAULT '',
    rx_power VARCHAR(60) DEFAULT '',
    source_file VARCHAR(255) DEFAULT '',
    first_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_device (pemilik, mac_address, onu_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
";
mysqli_query($conn, $createDevicesTableSql);

$acsDeviceColumns = array(
    'event_code' => "ALTER TABLE acs_ont_devices ADD COLUMN event_code VARCHAR(120) DEFAULT '' AFTER status_ont",
    'serial_number' => "ALTER TABLE acs_ont_devices ADD COLUMN serial_number VARCHAR(120) DEFAULT '' AFTER event_code",
    'oui' => "ALTER TABLE acs_ont_devices ADD COLUMN oui VARCHAR(64) DEFAULT '' AFTER serial_number",
    'product_class' => "ALTER TABLE acs_ont_devices ADD COLUMN product_class VARCHAR(120) DEFAULT '' AFTER oui",
    'remote_ip' => "ALTER TABLE acs_ont_devices ADD COLUMN remote_ip VARCHAR(80) DEFAULT '' AFTER product_class",
    'req_method' => "ALTER TABLE acs_ont_devices ADD COLUMN req_method VARCHAR(12) DEFAULT '' AFTER remote_ip",
    'req_uri' => "ALTER TABLE acs_ont_devices ADD COLUMN req_uri VARCHAR(255) DEFAULT '' AFTER req_method",
    'user_agent' => "ALTER TABLE acs_ont_devices ADD COLUMN user_agent VARCHAR(255) DEFAULT '' AFTER req_uri",
    'inform_time' => "ALTER TABLE acs_ont_devices ADD COLUMN inform_time VARCHAR(120) DEFAULT '' AFTER user_agent"
);
foreach ($acsDeviceColumns as $columnName => $alterSql) {
    $checkCol = mysqli_query($conn, "SHOW COLUMNS FROM acs_ont_devices LIKE '" . mysqli_real_escape_string($conn, $columnName) . "'");
    if (!$checkCol || mysqli_num_rows($checkCol) === 0) {
        mysqli_query($conn, $alterSql);
    }
}

$createTr069LogTableSql = "
CREATE TABLE IF NOT EXISTS acs_tr069_requests (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    pemilik VARCHAR(120) NOT NULL DEFAULT 'GLOBAL',
    remote_ip VARCHAR(80) DEFAULT '',
    req_method VARCHAR(12) DEFAULT '',
    req_uri VARCHAR(255) DEFAULT '',
    user_agent VARCHAR(255) DEFAULT '',
    auth_username VARCHAR(150) DEFAULT '',
    serial_number VARCHAR(120) DEFAULT '',
    oui VARCHAR(64) DEFAULT '',
    product_class VARCHAR(120) DEFAULT '',
    mac_address VARCHAR(80) DEFAULT '',
    event_code VARCHAR(120) DEFAULT '',
    inform_time VARCHAR(120) DEFAULT '',
    raw_xml MEDIUMTEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pemilik_created (pemilik, created_at),
    INDEX idx_serial (serial_number),
    INDEX idx_mac (mac_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
";
mysqli_query($conn, $createTr069LogTableSql);

$checkAuthUserCol = mysqli_query($conn, "SHOW COLUMNS FROM acs_tr069_requests LIKE 'auth_username'");
if (!$checkAuthUserCol || mysqli_num_rows($checkAuthUserCol) === 0) {
    mysqli_query($conn, "ALTER TABLE acs_tr069_requests ADD COLUMN auth_username VARCHAR(150) DEFAULT '' AFTER user_agent");
}

$createServerControlTableSql = "
CREATE TABLE IF NOT EXISTS acs_server_control (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pemilik VARCHAR(120) NOT NULL,
    server_domain VARCHAR(255) NOT NULL,
    acs_scheme VARCHAR(10) NOT NULL DEFAULT 'http',
    acs_port INT NOT NULL DEFAULT 7547,
    service_name VARCHAR(120) NOT NULL DEFAULT 'genieacs-cwmp',
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    webhook_token VARCHAR(128) DEFAULT '',
    last_action VARCHAR(30) DEFAULT '',
    last_result TEXT,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_pemilik_server (pemilik)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
";
mysqli_query($conn, $createServerControlTableSql);

$checkWebhookTokenCol = mysqli_query($conn, "SHOW COLUMNS FROM acs_server_control LIKE 'webhook_token'");
if (!$checkWebhookTokenCol || mysqli_num_rows($checkWebhookTokenCol) === 0) {
    mysqli_query($conn, "ALTER TABLE acs_server_control ADD COLUMN webhook_token VARCHAR(128) DEFAULT '' AFTER is_active");
}

$checkSchemeCol = mysqli_query($conn, "SHOW COLUMNS FROM acs_server_control LIKE 'acs_scheme'");
if (!$checkSchemeCol || mysqli_num_rows($checkSchemeCol) === 0) {
    mysqli_query($conn, "ALTER TABLE acs_server_control ADD COLUMN acs_scheme VARCHAR(10) NOT NULL DEFAULT 'http' AFTER server_domain");
}

$pemilikEsc = mysqli_real_escape_string($conn, $ceknama);
$detectedServerDomain = acsDetectHostDomain();
$detectedDomainEsc = mysqli_real_escape_string($conn, $detectedServerDomain);
$generatedWebhookToken = acsCreateToken(40);
$generatedWebhookTokenEsc = mysqli_real_escape_string($conn, $generatedWebhookToken);

$checkServerControl = mysqli_query($conn, "SELECT id FROM acs_server_control WHERE pemilik = '$pemilikEsc' LIMIT 1");
if (!$checkServerControl || mysqli_num_rows($checkServerControl) === 0) {
    mysqli_query(
        $conn,
        "INSERT INTO acs_server_control (pemilik, server_domain, acs_scheme, acs_port, service_name, is_active, webhook_token, last_action, last_result)
         VALUES ('$pemilikEsc', '$detectedDomainEsc', 'http', 7547, 'genieacs-cwmp', 0, '$generatedWebhookTokenEsc', 'init', 'Inisialisasi kontrol ACS server')"
    );
}

$serverControl = array(
    'server_domain' => $detectedServerDomain,
    'acs_scheme' => 'http',
    'acs_port' => 7547,
    'service_name' => 'genieacs-cwmp',
    'is_active' => 0,
    'webhook_token' => $generatedWebhookToken,
    'last_result' => ''
);

$qServerControl = mysqli_query($conn, "SELECT * FROM acs_server_control WHERE pemilik = '$pemilikEsc' LIMIT 1");
if ($qServerControl && ($rowServerControl = mysqli_fetch_assoc($qServerControl))) {
    $serverControl = $rowServerControl;
}

if (empty($serverControl['webhook_token'])) {
    $newWebhookToken = acsCreateToken(40);
    $newWebhookTokenEsc = mysqli_real_escape_string($conn, $newWebhookToken);
    mysqli_query($conn, "UPDATE acs_server_control SET webhook_token = '$newWebhookTokenEsc' WHERE pemilik = '$pemilikEsc' LIMIT 1");
    $serverControl['webhook_token'] = $newWebhookToken;
}

$activeServerDomain = trim((string)($serverControl['server_domain'] ?? $detectedServerDomain));
if ($activeServerDomain === '') {
    $activeServerDomain = $detectedServerDomain;
}
$activeServerScheme = strtolower(trim((string)($serverControl['acs_scheme'] ?? 'http')));
if ($activeServerScheme !== 'http' && $activeServerScheme !== 'https') {
    $activeServerScheme = 'http';
}
$activeServerPort = (int)($serverControl['acs_port'] ?? 7547);
if ($activeServerPort < 1 || $activeServerPort > 65535) {
    $activeServerPort = 7547;
}
$manualGenieSyncResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';

    if ($action === 'update_server_domain') {
        $serverDomainInput = trim((string)($_POST['server_domain'] ?? ''));
        $serverPortPost = (int)($_POST['acs_port'] ?? 7547);
        $serviceName = trim((string)($_POST['service_name'] ?? 'genieacs-cwmp'));

        if ($serverPortPost < 1 || $serverPortPost > 65535) {
            $serverPortPost = 7547;
        }
        if ($serviceName === '') {
            $serviceName = 'genieacs-cwmp';
        }

        $parsedServer = acsParseServerInput($serverDomainInput, $detectedServerDomain, $serverPortPost, 'http');
        $serverDomain = (string)$parsedServer['domain'];
        $serverScheme = (string)$parsedServer['scheme'];
        $serverPort = (int)$parsedServer['port'];

        if (!(bool)$parsedServer['has_explicit_port']) {
            $serverPort = $serverPortPost;
        }

        $domainEsc = mysqli_real_escape_string($conn, $serverDomain);
        $schemeEsc = mysqli_real_escape_string($conn, $serverScheme);
        $serviceEsc = mysqli_real_escape_string($conn, $serviceName);
        $updateServerSql = "UPDATE acs_server_control
                            SET server_domain = '$domainEsc', acs_scheme = '$schemeEsc', acs_port = $serverPort, service_name = '$serviceEsc',
                                last_action = 'set_domain', last_result = 'Domain ACS server diperbarui manual', updated_at = NOW()
                            WHERE pemilik = '$pemilikEsc' LIMIT 1";
        mysqli_query($conn, $updateServerSql);
        acsSetFlash($statusType, $statusMessage, 'success', 'Domain ACS server berhasil diperbarui (support http/https/domain/ip).');
    }

    if ($action === 'toggle_server_status') {
        $targetState = strtolower(trim((string)($_POST['target_state'] ?? 'off')));
        $targetActive = ($targetState === 'on') ? 1 : 0;

        $qCurrentServer = mysqli_query($conn, "SELECT * FROM acs_server_control WHERE pemilik = '$pemilikEsc' LIMIT 1");
        $currentServer = $qCurrentServer ? mysqli_fetch_assoc($qCurrentServer) : null;

        $serviceName = isset($currentServer['service_name']) ? trim((string)$currentServer['service_name']) : 'genieacs-cwmp';
        if ($serviceName === '') {
            $serviceName = 'genieacs-cwmp';
        }

        $resultMessage = '';
        $finalActive = 0;

        if (stripos(PHP_OS, 'WIN') === 0 || !function_exists('shell_exec')) {
            $resultMessage = 'Gagal kontrol service otomatis: environment ini tidak mendukung systemctl dari PHP (Windows/no-shell).';
        } else {
            $serviceArg = escapeshellarg($serviceName);

            $actionVerb = $targetActive === 1 ? 'start' : 'stop';
            $attemptCommands = array(
                "systemctl $actionVerb $serviceArg 2>&1",
                "sudo -n systemctl $actionVerb $serviceArg 2>&1"
            );

            $attemptLogs = array();
            foreach ($attemptCommands as $cmd) {
                $out = trim((string)shell_exec($cmd));
                $checkStatus = trim((string)shell_exec("systemctl is-active $serviceArg 2>&1"));

                $attemptLogs[] = 'Exec: ' . $cmd . ' | Result: ' . ($out !== '' ? $out : '-') . ' | is-active: ' . $checkStatus;

                if ($targetActive === 1 && strpos($checkStatus, 'active') === 0) {
                    $finalActive = 1;
                    break;
                }
                if ($targetActive === 0 && (strpos($checkStatus, 'inactive') === 0 || strpos($checkStatus, 'failed') === 0 || strpos($checkStatus, 'deactivating') === 0)) {
                    $finalActive = 0;
                    break;
                }
            }

            if ($targetActive === 0 && $finalActive !== 1) {
                $finalActive = 0;
            }

            $resultMessage = implode(' || ', $attemptLogs);
            if ($targetActive === 1 && $finalActive !== 1) {
                $resultMessage .= ' || Hint: pastikan service ada dan user web server punya izin sudo tanpa password untuk systemctl.';
            }
        }

        $actionLabel = $targetActive === 1 ? 'start' : 'stop';
        $actionEsc = mysqli_real_escape_string($conn, $actionLabel);
        $resultEsc = mysqli_real_escape_string($conn, $resultMessage);
        $updateToggleSql = "UPDATE acs_server_control
                            SET is_active = $finalActive, last_action = '$actionEsc', last_result = '$resultEsc', updated_at = NOW()
                            WHERE pemilik = '$pemilikEsc' LIMIT 1";
        mysqli_query($conn, $updateToggleSql);

        if ($targetActive === 1 && $finalActive === 1) {
            acsSetFlash($statusType, $statusMessage, 'success', 'ACS server berhasil diaktifkan.');
        } elseif ($targetActive === 0 && $finalActive === 0) {
            acsSetFlash($statusType, $statusMessage, 'success', 'ACS server berhasil dinonaktifkan.');
        } else {
            acsSetFlash($statusType, $statusMessage, 'warning', 'Perintah ON/OFF gagal dieksekusi otomatis. Cek log terakhir di panel ACS server.');
        }
    }

    if ($action === 'create_account') {
        $accountName = trim((string)($_POST['account_name'] ?? ''));
        $tr069Username = trim((string)($_POST['tr069_username'] ?? ''));
        $tr069Password = trim((string)($_POST['tr069_password'] ?? ''));
        $acsUrl = trim((string)($_POST['acs_url'] ?? ''));
        $area = trim((string)($_POST['area'] ?? ''));
        $accountStatus = strtoupper(trim((string)($_POST['status'] ?? 'AKTIF')));
        if ($accountStatus !== 'AKTIF' && $accountStatus !== 'NONAKTIF') {
            $accountStatus = 'AKTIF';
        }

        if ($accountName === '' || $tr069Username === '' || $tr069Password === '' || $acsUrl === '') {
            acsSetFlash($statusType, $statusMessage, 'danger', 'Semua field akun ACS wajib diisi.');
        } else {
            $pemilikEsc = mysqli_real_escape_string($conn, $ceknama);
            $nameEsc = mysqli_real_escape_string($conn, $accountName);
            $userEsc = mysqli_real_escape_string($conn, $tr069Username);
            $passEsc = mysqli_real_escape_string($conn, $tr069Password);
            $urlEsc = mysqli_real_escape_string($conn, $acsUrl);
            $areaEsc = mysqli_real_escape_string($conn, $area);
            $statusEsc = mysqli_real_escape_string($conn, $accountStatus);

            $insertSql = "INSERT INTO acs_tr069_accounts (pemilik, area, account_name, tr069_username, tr069_password, acs_url, status)
                          VALUES ('$pemilikEsc', '$areaEsc', '$nameEsc', '$userEsc', '$passEsc', '$urlEsc', '$statusEsc')";
            $okInsert = mysqli_query($conn, $insertSql);

            if ($okInsert) {
                acsSetFlash($statusType, $statusMessage, 'success', 'Akun ACS TR-069 berhasil dibuat.');
            } else {
                acsSetFlash($statusType, $statusMessage, 'danger', 'Gagal membuat akun ACS: ' . mysqli_error($conn));
            }
        }
    }

    if ($action === 'delete_account') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pemilikEsc = mysqli_real_escape_string($conn, $ceknama);
            mysqli_query($conn, "DELETE FROM acs_tr069_accounts WHERE id = $id AND pemilik = '$pemilikEsc' LIMIT 1");
            acsSetFlash($statusType, $statusMessage, 'success', 'Akun ACS berhasil dihapus.');
        }
    }

    if ($action === 'set_device_account') {
        $deviceId = (int)($_POST['device_id'] ?? 0);
        $deviceAccountName = trim((string)($_POST['device_account_name'] ?? ''));
        if ($deviceId > 0) {
            $pemilikEsc = mysqli_real_escape_string($conn, $ceknama);
            $accountEsc = mysqli_real_escape_string($conn, $deviceAccountName);
            $updateSql = "UPDATE acs_ont_devices SET account_name = '$accountEsc' WHERE id = $deviceId AND pemilik = '$pemilikEsc' LIMIT 1";
            mysqli_query($conn, $updateSql);
            acsSetFlash($statusType, $statusMessage, 'success', 'Akun ACS untuk ONT berhasil diperbarui.');
        }
    }

    if ($action === 'sync_devices') {
        $selectedAccountName = trim((string)($_POST['sync_account_name'] ?? ''));
        $genieManual = acsSyncDevicesFromGenie($conn, $ceknama, $activeServerScheme, $activeServerDomain, $activeServerPort);
        $manualGenieSyncResult = $genieManual;

        if ($genieManual['ok']) {
            if ($selectedAccountName !== '') {
                $scopePemilik = mysqli_real_escape_string($conn, $ceknama);
                $accountEsc = mysqli_real_escape_string($conn, $selectedAccountName);
                mysqli_query(
                    $conn,
                    "UPDATE acs_ont_devices SET account_name = '$accountEsc' WHERE pemilik = '$scopePemilik' AND (account_name = '' OR account_name IS NULL) AND source_file = 'genieacs_nbi'"
                );
            }

            acsSetFlash(
                $statusType,
                $statusMessage,
                'success',
                'Sinkron dari GenieACS selesai. Data terbaca: ' . (int)$genieManual['found'] . ' | Baru: ' . (int)$genieManual['inserted'] . ' | Update: ' . (int)$genieManual['updated']
            );
        } else {
            acsSetFlash($statusType, $statusMessage, 'warning', 'Sinkron GenieACS gagal: ' . (string)$genieManual['error']);
        }
    }
}

$accounts = array();
$devices = array();

$qServerControl = mysqli_query($conn, "SELECT * FROM acs_server_control WHERE pemilik = '$pemilikEsc' LIMIT 1");
if ($qServerControl && ($rowServerControl = mysqli_fetch_assoc($qServerControl))) {
    $serverControl = $rowServerControl;
}

$serverDomain = trim((string)($serverControl['server_domain'] ?? $detectedServerDomain));
if ($serverDomain === '') {
    $serverDomain = $detectedServerDomain;
}
$serverScheme = strtolower(trim((string)($serverControl['acs_scheme'] ?? 'http')));
if ($serverScheme !== 'http' && $serverScheme !== 'https') {
    $serverScheme = 'http';
}
$serverPort = (int)($serverControl['acs_port'] ?? 7547);
if ($serverPort < 1 || $serverPort > 65535) {
    $serverPort = 7547;
}
$serverServiceName = trim((string)($serverControl['service_name'] ?? 'genieacs-cwmp'));
if ($serverServiceName === '') {
    $serverServiceName = 'genieacs-cwmp';
}
$serverIsActive = (int)($serverControl['is_active'] ?? 0) === 1;
$serverLastResult = (string)($serverControl['last_result'] ?? '');
$webhookToken = trim((string)($serverControl['webhook_token'] ?? ''));
$detectedAcsUrl = $serverScheme . '://' . $serverDomain . ':' . $serverPort . '/';
$webhookLoggerUrl = $serverScheme . '://' . $serverDomain . '/crm/billing/proses/acs_tr069_webhook.php?token=' . rawurlencode($webhookToken);

$tr069Logs = array();
$qTr069Logs = mysqli_query($conn, "SELECT id, remote_ip, req_method, auth_username, serial_number, oui, product_class, mac_address, event_code, inform_time, created_at FROM acs_tr069_requests WHERE pemilik IN ('$pemilikEsc', 'GLOBAL') ORDER BY id DESC LIMIT 200");
while ($qTr069Logs && ($logRow = mysqli_fetch_assoc($qTr069Logs))) {
    $tr069Logs[] = $logRow;
}

$qAccounts = mysqli_query($conn, "SELECT * FROM acs_tr069_accounts WHERE pemilik = '$pemilikEsc' ORDER BY id DESC");
while ($qAccounts && ($row = mysqli_fetch_assoc($qAccounts))) {
    $accounts[] = $row;
}

$keyword = trim((string)($_GET['q'] ?? ''));
$autoRefresh = isset($_GET['auto_refresh']) ? (int)$_GET['auto_refresh'] : 1;
$autoRefresh = $autoRefresh === 0 ? 0 : 1;

$genieSyncResult = is_array($manualGenieSyncResult)
    ? $manualGenieSyncResult
    : acsSyncDevicesFromGenie($conn, $ceknama, $serverScheme, $serverDomain, $serverPort);
$deviceSourceInfo = 'cache lokal (acs_ont_devices)';
if ($genieSyncResult['ok']) {
    $deviceSourceInfo = 'GenieACS NBI live (' . (int)$genieSyncResult['found'] . ' data, endpoint: ' . (string)$genieSyncResult['endpoint'] . ')';
} elseif ($statusMessage === '') {
    acsSetFlash($statusType, $statusMessage, 'warning', 'Gagal ambil langsung dari GenieACS: ' . $genieSyncResult['error'] . '. Menampilkan data cache lokal.');
}

$whereDevice = "pemilik = '$pemilikEsc'";
if ($keyword !== '') {
    $keywordEsc = mysqli_real_escape_string($conn, $keyword);
    $whereDevice .= " AND (mac_address LIKE '%$keywordEsc%' OR onu_no LIKE '%$keywordEsc%' OR status_ont LIKE '%$keywordEsc%' OR account_name LIKE '%$keywordEsc%' OR serial_number LIKE '%$keywordEsc%' OR product_class LIKE '%$keywordEsc%' OR remote_ip LIKE '%$keywordEsc%')";
}

$qDevices = mysqli_query($conn, "SELECT * FROM acs_ont_devices WHERE $whereDevice ORDER BY last_seen DESC");
while ($qDevices && ($row = mysqli_fetch_assoc($qDevices))) {
    $devices[] = $row;
}
?>

<div class="container-fluid py-4">
    <div class="card mb-4 shadow-sm">
        <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-sitemap me-2"></i>MANE ACS - Clone ACS TR-069</h5>
            <span class="badge bg-light text-dark">Pemilik: <?php echo acsEsc($ceknama); ?></span>
        </div>
        <div class="card-body">
            <p class="mb-0">Modul ini untuk membuat akun ACS TR-069, menampilkan daftar ONT yang terbaca, dan panduan seting ONT agar bisa inform ke ACS.</p>
        </div>
    </div>

    <?php if ($statusMessage !== '') { ?>
        <div class="alert alert-<?php echo acsEsc($statusType ?: 'info'); ?>">
            <?php echo acsEsc($statusMessage); ?>
        </div>
    <?php } ?>

    <div class="card mb-4 shadow-sm">
        <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="fas fa-server me-2"></i>Domain ACS Server Ubuntu Ini</h6>
            <span class="badge <?php echo $serverIsActive ? 'bg-light text-success' : 'bg-light text-danger'; ?>">
                <?php echo $serverIsActive ? 'ACS SERVER ON' : 'ACS SERVER OFF'; ?>
            </span>
        </div>
        <div class="card-body">
            <div class="alert mb-3" style="background:#0f1b38;border:1px solid #2a3f72;color:#e9f0ff;">
                Domain/URL ACS aktif: <strong><?php echo acsEsc($detectedAcsUrl); ?></strong>
            </div>

            <form method="post" class="row g-2 align-items-end mb-3">
                <input type="hidden" name="action" value="update_server_domain">
                <div class="col-md-4">
                    <label class="form-label">Domain ACS Server (URL/Domain/IP)</label>
                    <input type="text" name="server_domain" class="form-control" value="<?php echo acsEsc($serverDomain); ?>" placeholder="contoh: https://acs.domain.com atau 20.20.20.2" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Port ACS</label>
                    <input type="number" name="acs_port" class="form-control" value="<?php echo (int)$serverPort; ?>" min="1" max="65535" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Nama Service Ubuntu</label>
                    <input type="text" name="service_name" class="form-control" value="<?php echo acsEsc($serverServiceName); ?>" required>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-outline-success w-100">Update</button>
                </div>
            </form>

            <div class="d-flex gap-2 mb-2">
                <?php if ($serverIsActive) { ?>
                    <form method="post" class="mb-0">
                        <input type="hidden" name="action" value="toggle_server_status">
                        <input type="hidden" name="target_state" value="off">
                        <button type="submit" class="btn btn-danger">OFF - Matikan ACS SERVER</button>
                    </form>
                <?php } else { ?>
                    <form method="post" class="mb-0">
                        <input type="hidden" name="action" value="toggle_server_status">
                        <input type="hidden" name="target_state" value="on">
                        <button type="submit" class="btn btn-success">ON - Aktifkan ACS SERVER</button>
                    </form>
                <?php } ?>
            </div>

            <?php if ($serverLastResult !== '') { ?>
                <small class="text-muted">Log terakhir: <?php echo acsEsc($serverLastResult); ?></small>
            <?php } ?>
        </div>
    </div>

    <div class="card mb-4 shadow-sm">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="fas fa-stream me-2"></i>Log Request ACS TR-069 (Bukan Dari Onulist)</h6>
            <span class="badge bg-light text-dark"><?php echo count($tr069Logs); ?> log terbaru</span>
        </div>
        <div class="card-body">
            <div class="alert mb-3" style="background:#0f1b38;border:1px solid #2a3f72;color:#e9f0ff;">
                Endpoint logger request TR-069: <strong><?php echo acsEsc($webhookLoggerUrl); ?></strong><br>
                Arahkan mirror/log request CWMP ke endpoint ini agar semua request ACS terbaca di halaman ini.
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-striped align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Waktu</th>
                            <th>Remote IP</th>
                            <th>Method/Auth</th>
                            <th>Serial</th>
                            <th>MAC</th>
                            <th>OUI / Product</th>
                            <th>Event</th>
                            <th>Inform Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($tr069Logs) === 0) { ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted">Belum ada request TR-069 masuk ke logger.</td>
                            </tr>
                        <?php } else { ?>
                            <?php foreach ($tr069Logs as $log) { ?>
                                <tr>
                                    <td><?php echo acsEsc($log['created_at']); ?></td>
                                    <td><?php echo acsEsc($log['remote_ip']); ?></td>
                                    <td><?php echo acsEsc($log['req_method']); ?><br><small class="text-muted"><?php echo acsEsc($log['auth_username']); ?></small></td>
                                    <td><?php echo acsEsc($log['serial_number']); ?></td>
                                    <td><?php echo acsEsc($log['mac_address']); ?></td>
                                    <td><?php echo acsEsc($log['oui']); ?><br><small class="text-muted"><?php echo acsEsc($log['product_class']); ?></small></td>
                                    <td><?php echo acsEsc($log['event_code']); ?></td>
                                    <td><?php echo acsEsc($log['inform_time']); ?></td>
                                </tr>
                            <?php } ?>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-secondary text-white">
                    <h6 class="mb-0"><i class="fas fa-user-cog me-2"></i>Membuat Akun ACS TR-069</h6>
                </div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="action" value="create_account">

                        <div class="mb-2">
                            <label class="form-label">Nama Akun ACS</label>
                            <input type="text" name="account_name" class="form-control" required placeholder="contoh: ACS-AREA-BARAT">
                        </div>

                        <div class="mb-2">
                            <label class="form-label">Area</label>
                            <input type="text" name="area" class="form-control" placeholder="contoh: Area Barat">
                        </div>

                        <div class="mb-2">
                            <label class="form-label">TR069 Username</label>
                            <input type="text" name="tr069_username" class="form-control" required placeholder="contoh: tr069-user01">
                        </div>

                        <div class="mb-2">
                            <label class="form-label">TR069 Password</label>
                            <input type="text" name="tr069_password" class="form-control" required placeholder="contoh: Qts@123456">
                        </div>

                        <div class="mb-2">
                            <label class="form-label">ACS URL</label>
                            <input type="text" name="acs_url" class="form-control" required value="<?php echo acsEsc($detectedAcsUrl); ?>" placeholder="contoh: http://domain-acs:7547/">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="AKTIF" selected>AKTIF</option>
                                <option value="NONAKTIF">NONAKTIF</option>
                            </select>
                        </div>

                        <button type="submit" class="btn btn-info w-100">Simpan Akun ACS</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="fas fa-list me-2"></i>Daftar Akun ACS TR-069</h6>
                    <span class="badge bg-light text-dark"><?php echo count($accounts); ?> akun</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Nama Akun</th>
                                    <th>TR069</th>
                                    <th>ACS URL</th>
                                    <th>Area</th>
                                    <th>Status</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($accounts) === 0) { ?>
                                    <tr><td colspan="6" class="text-center text-muted">Belum ada akun ACS.</td></tr>
                                <?php } else { ?>
                                    <?php foreach ($accounts as $acc) { ?>
                                        <tr>
                                            <td><?php echo acsEsc($acc['account_name']); ?></td>
                                            <td><?php echo acsEsc($acc['tr069_username']); ?><br><small class="text-muted"><?php echo acsEsc($acc['tr069_password']); ?></small></td>
                                            <td><small><?php echo acsEsc($acc['acs_url']); ?></small></td>
                                            <td><?php echo acsEsc($acc['area']); ?></td>
                                            <td><?php echo acsEsc($acc['status']); ?></td>
                                            <td>
                                                <form method="post" onsubmit="return confirm('Hapus akun ACS ini?');" class="mb-0">
                                                    <input type="hidden" name="action" value="delete_account">
                                                    <input type="hidden" name="id" value="<?php echo (int)$acc['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">Hapus</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php } ?>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mt-4 shadow-sm" id="cardPerangkatTerbaca">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="fas fa-satellite-dish me-2"></i>Menampilkan Daftar Perangkat Terbaca</h6>
            <span class="badge bg-light text-dark"><?php echo count($devices); ?> perangkat</span>
        </div>
        <div class="card-body">
            <form method="post" class="row g-2 align-items-end mb-3">
                <input type="hidden" name="action" value="sync_devices">
                <div class="col-md-4">
                    <label class="form-label">Link ke Akun ACS (opsional)</label>
                    <select name="sync_account_name" class="form-select">
                        <option value="">Tanpa akun</option>
                        <?php foreach ($accounts as $acc) { ?>
                            <option value="<?php echo acsEsc($acc['account_name']); ?>"><?php echo acsEsc($acc['account_name']); ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Sumber data</label>
                    <input type="text" class="form-control" value="GenieACS NBI /devices (ACS asli)" readonly>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary w-100">Sinkron Dari GenieACS</button>
                </div>
            </form>

            <form method="get" class="row g-2 mb-3">
                <div class="col-md-10">
                    <input type="text" name="q" class="form-control" placeholder="Cari MAC / ONU No / status / akun" value="<?php echo acsEsc($keyword); ?>">
                    <input type="hidden" name="auto_refresh" value="<?php echo (int)$autoRefresh; ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-outline-secondary w-100">Cari</button>
                </div>
            </form>

            <div class="d-flex justify-content-between align-items-center mb-2">
                <small class="text-muted">Mode otomatis: <?php echo $autoRefresh ? 'ON (refresh 5 detik)' : 'OFF'; ?> | Sumber: <?php echo acsEsc($deviceSourceInfo); ?></small>
                <?php if ($autoRefresh) { ?>
                    <a class="btn btn-sm btn-outline-danger" href="?q=<?php echo urlencode($keyword); ?>&auto_refresh=0">Matikan Auto Refresh</a>
                <?php } else { ?>
                    <a class="btn btn-sm btn-outline-success" href="?q=<?php echo urlencode($keyword); ?>&auto_refresh=1">Aktifkan Auto Refresh 5 Detik</a>
                <?php } ?>
            </div>

            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:30px;"></th>
                            <th>Serial number</th>
                            <th>Product class</th>
                            <th>Software version</th>
                            <th>IP</th>
                            <th>SSID/Akun</th>
                            <th>Last inform</th>
                            <th>Tags</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($devices) === 0) { ?>
                            <tr>
                                <td colspan="9" class="text-center text-muted">Belum ada perangkat ONT terbaca. Klik sinkron terlebih dahulu.</td>
                            </tr>
                        <?php } else { ?>
                            <?php foreach ($devices as $dev) { ?>
                                <?php
                                $serialNo = trim((string)($dev['serial_number'] ?? ''));
                                if ($serialNo === '') {
                                    $serialNo = trim((string)($dev['onu_no'] ?? '-'));
                                }
                                $productClass = trim((string)($dev['product_class'] ?? ''));
                                $softwareVersion = acsExtractSoftwareVersion((string)($dev['user_agent'] ?? ''));
                                $remoteIp = trim((string)($dev['remote_ip'] ?? ''));
                                $ssidOrAkun = trim((string)($dev['account_name'] ?? ''));
                                $lastInform = trim((string)($dev['inform_time'] ?? ''));
                                if ($lastInform === '') {
                                    $lastInform = trim((string)($dev['last_seen'] ?? ''));
                                }
                                $isOnline = acsIsOnlineNow((string)($dev['last_seen'] ?? ''));
                                $detailId = 'devDetail' . (int)$dev['id'];
                                ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" class="form-check-input" disabled>
                                    </td>
                                    <td>
                                        <a href="#<?php echo acsEsc($detailId); ?>" data-bs-toggle="collapse" data-toggle="collapse" role="button" aria-expanded="false" aria-controls="<?php echo acsEsc($detailId); ?>">
                                            <?php echo acsEsc($serialNo !== '' ? $serialNo : '-'); ?>
                                        </a>
                                    </td>
                                    <td><?php echo acsEsc($productClass !== '' ? $productClass : '-'); ?></td>
                                    <td><?php echo acsEsc($softwareVersion); ?></td>
                                    <td><?php echo acsEsc($remoteIp !== '' ? $remoteIp : '-'); ?></td>
                                    <td><?php echo acsEsc($ssidOrAkun !== '' ? $ssidOrAkun : '-'); ?></td>
                                    <td>
                                        <?php echo acsEsc($lastInform !== '' ? $lastInform : '-'); ?><br>
                                        <span class="badge <?php echo $isOnline ? 'bg-success' : 'bg-secondary'; ?>">
                                            <?php echo $isOnline ? 'Online now' : 'Offline'; ?>
                                        </span>
                                    </td>
                                    <td style="min-width:220px;">
                                        <form method="post" class="d-flex gap-1">
                                            <input type="hidden" name="action" value="set_device_account">
                                            <input type="hidden" name="device_id" value="<?php echo (int)$dev['id']; ?>">
                                            <select name="device_account_name" class="form-select form-select-sm">
                                                <option value="">- Pilih -</option>
                                                <?php foreach ($accounts as $acc) { ?>
                                                    <option value="<?php echo acsEsc($acc['account_name']); ?>" <?php echo ((string)$dev['account_name'] === (string)$acc['account_name']) ? 'selected' : ''; ?>>
                                                        <?php echo acsEsc($acc['account_name']); ?>
                                                    </option>
                                                <?php } ?>
                                            </select>
                                            <button class="btn btn-sm btn-outline-primary" type="submit">Set</button>
                                        </form>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-link p-0" type="button" data-bs-toggle="collapse" data-toggle="collapse" data-bs-target="#<?php echo acsEsc($detailId); ?>" data-target="#<?php echo acsEsc($detailId); ?>" aria-expanded="false" aria-controls="<?php echo acsEsc($detailId); ?>">Show</button>
                                    </td>
                                </tr>
                                <tr class="collapse" id="<?php echo acsEsc($detailId); ?>">
                                    <td colspan="9" style="background:#fafbfd;">
                                        <div class="row g-2 py-2">
                                            <div class="col-md-4"><strong>MAC:</strong> <?php echo acsEsc($dev['mac_address']); ?></div>
                                            <div class="col-md-4"><strong>OUI:</strong> <?php echo acsEsc($dev['oui'] ?? ''); ?></div>
                                            <div class="col-md-4"><strong>Event:</strong> <?php echo acsEsc($dev['event_code'] ?? ''); ?></div>
                                            <div class="col-md-4"><strong>Method:</strong> <?php echo acsEsc($dev['req_method'] ?? ''); ?></div>
                                            <div class="col-md-8"><strong>URI:</strong> <?php echo acsEsc($dev['req_uri'] ?? ''); ?></div>
                                            <div class="col-md-12"><strong>User-Agent:</strong> <?php echo acsEsc($dev['user_agent'] ?? ''); ?></div>
                                            <div class="col-md-3"><strong>Distance:</strong> <?php echo acsEsc($dev['distance']); ?></div>
                                            <div class="col-md-3"><strong>Temp:</strong> <?php echo acsEsc($dev['temperature']); ?></div>
                                            <div class="col-md-3"><strong>Tx/Rx:</strong> <?php echo acsEsc($dev['tx_power']); ?> / <?php echo acsEsc($dev['rx_power']); ?></div>
                                            <div class="col-md-3"><strong>Last seen:</strong> <?php echo acsEsc($dev['last_seen']); ?></div>
                                        </div>
                                    </td>
                                </tr>
                            <?php } ?>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if ($autoRefresh) { ?>
    <script>
    (function () {
        const intervalMs = 5000;
        const cardId = 'cardPerangkatTerbaca';

        async function refreshDeviceCardOnly() {
            const currentCard = document.getElementById(cardId);
            if (!currentCard) {
                return;
            }

            try {
                const response = await fetch(window.location.href, {
                    method: 'GET',
                    cache: 'no-store',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                if (!response.ok) {
                    return;
                }

                const html = await response.text();
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                const newCard = doc.getElementById(cardId);
                if (!newCard) {
                    return;
                }

                currentCard.replaceWith(newCard);
            } catch (e) {
                // Keep current content if refresh fails.
            }
        }

        setInterval(refreshDeviceCardOnly, intervalMs);
    })();
    </script>
    <?php } ?>

    <div class="card mt-4 shadow-sm">
        <div class="card-header bg-warning text-dark">
            <h6 class="mb-0"><i class="fas fa-book-open me-2"></i>Cara Panduan Pakai & Cara Setingan Di ONT</h6>
        </div>
        <div class="card-body">
            <div class="row g-4">
                <div class="col-md-6">
                    <h6>Alur Pemakaian MANE ACS</h6>
                    <ol class="mb-0">
                        <li>Buat akun ACS TR-069 pada form sebelah atas.</li>
                        <li>Arahkan log/mirror request CWMP ke endpoint logger TR-069 di atas.</li>
                        <li>Klik tombol <strong>Sinkron Dari Log TR-069</strong> pada halaman ini.</li>
                        <li>Setiap ONT bisa dipasangkan ke akun ACS lewat kolom <strong>Akun ACS</strong>.</li>
                        <li>Gunakan data TR-069 user/pass + ACS URL saat provisioning ONT.</li>
                    </ol>
                </div>
                <div class="col-md-6">
                    <h6>Contoh Setingan ONT TR-069</h6>
                    <p class="mb-2">Masuk ke web ONT, lalu buka menu <strong>Management / TR069 / CWMP</strong> dan isi:</p>
                    <ul class="mb-0">
                        <li><strong>Enable TR-069/CWMP:</strong> ON</li>
                        <li><strong>ACS URL:</strong> `http://domain-acs:7547/`</li>
                        <li><strong>ACS Username:</strong> sesuai `TR069 Username`</li>
                        <li><strong>ACS Password:</strong> sesuai `TR069 Password`</li>
                        <li><strong>Periodic Inform:</strong> ON (contoh interval 300 detik)</li>
                        <li><strong>Connection Request Port:</strong> default vendor (7547/7548 sesuai ONT)</li>
                    </ul>
                </div>
            </div>

            <div class="alert mt-3 mb-0" style="background:#0f1b38;border:1px solid #2a3f72;color:#e9f0ff;">
                Tips: jika perangkat belum muncul, cek NAT/firewall ke ACS server, DNS resolve ACS URL, dan waktu di ONT harus benar.
            </div>
        </div>
    </div>
</div>

<?php require 'footer.php'; ?>
