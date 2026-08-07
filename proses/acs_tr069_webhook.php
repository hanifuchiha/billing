<?php
header('Content-Type: application/json; charset=utf-8');

require_once '../koneksibilling.php';

function whExtractXmlValue($xml, $tag)
{
    $pattern = '/<(?:[a-zA-Z0-9_\-]+:)?' . preg_quote($tag, '/') . '[^>]*>(.*?)<\/(?:[a-zA-Z0-9_\-]+:)?' . preg_quote($tag, '/') . '>/is';
    if (preg_match($pattern, (string)$xml, $match)) {
        return trim(strip_tags((string)$match[1]));
    }

    return '';
}

function whExtractAuthUsername()
{
    $headers = array();
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $headers[] = (string)$_SERVER['HTTP_AUTHORIZATION'];
    }
    if (isset($_SERVER['Authorization'])) {
        $headers[] = (string)$_SERVER['Authorization'];
    }

    foreach ($headers as $header) {
        if (stripos($header, 'Basic ') === 0) {
            $encoded = trim(substr($header, 6));
            $decoded = base64_decode($encoded, true);
            if ($decoded !== false && strpos($decoded, ':') !== false) {
                $parts = explode(':', $decoded, 2);
                return trim((string)$parts[0]);
            }
        }
    }

    return '';
}

function whNormalizeMac($mac)
{
    $raw = strtoupper(trim((string)$mac));
    $raw = preg_replace('/[^A-F0-9]/', '', $raw);
    if (strlen($raw) !== 12) {
        return strtoupper(trim((string)$mac));
    }

    return implode(':', str_split($raw, 2));
}

$token = isset($_GET['token']) ? trim((string)$_GET['token']) : '';
if ($token === '') {
    http_response_code(403);
    echo json_encode(array('success' => false, 'message' => 'Token wajib diisi.'));
    exit;
}

$tokenEsc = mysqli_real_escape_string($conn, $token);
$qOwner = mysqli_query($conn, "SELECT pemilik FROM acs_server_control WHERE webhook_token = '$tokenEsc' LIMIT 1");
if (!$qOwner || mysqli_num_rows($qOwner) === 0) {
    http_response_code(403);
    echo json_encode(array('success' => false, 'message' => 'Token tidak valid.'));
    exit;
}

$rowOwner = mysqli_fetch_assoc($qOwner);
$pemilik = isset($rowOwner['pemilik']) ? (string)$rowOwner['pemilik'] : 'GLOBAL';
if ($pemilik === '') {
    $pemilik = 'GLOBAL';
}

$createLogTableSql = "
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
mysqli_query($conn, $createLogTableSql);

$checkAuthCol = mysqli_query($conn, "SHOW COLUMNS FROM acs_tr069_requests LIKE 'auth_username'");
if (!$checkAuthCol || mysqli_num_rows($checkAuthCol) === 0) {
    mysqli_query($conn, "ALTER TABLE acs_tr069_requests ADD COLUMN auth_username VARCHAR(150) DEFAULT '' AFTER user_agent");
}

$rawXml = file_get_contents('php://input');
if ($rawXml === false) {
    $rawXml = '';
}

$serialNumber = whExtractXmlValue($rawXml, 'SerialNumber');
$oui = whExtractXmlValue($rawXml, 'OUI');
$productClass = whExtractXmlValue($rawXml, 'ProductClass');
$eventCode = whExtractXmlValue($rawXml, 'EventCode');
$informTime = whExtractXmlValue($rawXml, 'CurrentTime');
$macAddress = whExtractXmlValue($rawXml, 'MACAddress');
if ($macAddress === '') {
    if (preg_match('/([A-Fa-f0-9]{2}[:\-]){5}[A-Fa-f0-9]{2}/', (string)$rawXml, $macMatch)) {
        $macAddress = $macMatch[0];
    }
}
$macAddress = whNormalizeMac($macAddress);

$remoteIp = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
$reqMethod = isset($_SERVER['REQUEST_METHOD']) ? (string)$_SERVER['REQUEST_METHOD'] : '';
$reqUri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
$userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : '';
$authUsername = whExtractAuthUsername();

$pemilikEsc = mysqli_real_escape_string($conn, $pemilik);
$remoteIpEsc = mysqli_real_escape_string($conn, $remoteIp);
$reqMethodEsc = mysqli_real_escape_string($conn, $reqMethod);
$reqUriEsc = mysqli_real_escape_string($conn, $reqUri);
$userAgentEsc = mysqli_real_escape_string($conn, $userAgent);
$authUsernameEsc = mysqli_real_escape_string($conn, $authUsername);
$serialEsc = mysqli_real_escape_string($conn, $serialNumber);
$ouiEsc = mysqli_real_escape_string($conn, $oui);
$productEsc = mysqli_real_escape_string($conn, $productClass);
$macEsc = mysqli_real_escape_string($conn, $macAddress);
$eventEsc = mysqli_real_escape_string($conn, $eventCode);
$informEsc = mysqli_real_escape_string($conn, $informTime);
$rawEsc = mysqli_real_escape_string($conn, $rawXml);

$insertSql = "INSERT INTO acs_tr069_requests
              (pemilik, remote_ip, req_method, req_uri, user_agent, auth_username, serial_number, oui, product_class, mac_address, event_code, inform_time, raw_xml)
              VALUES
              ('$pemilikEsc', '$remoteIpEsc', '$reqMethodEsc', '$reqUriEsc', '$userAgentEsc', '$authUsernameEsc', '$serialEsc', '$ouiEsc', '$productEsc', '$macEsc', '$eventEsc', '$informEsc', '$rawEsc')";

$ok = mysqli_query($conn, $insertSql);
if (!$ok) {
    http_response_code(500);
    echo json_encode(array('success' => false, 'message' => 'Gagal simpan log: ' . mysqli_error($conn)));
    exit;
}

echo json_encode(array(
    'success' => true,
    'message' => 'Log request TR-069 tersimpan.',
    'data' => array(
        'serial_number' => $serialNumber,
        'mac_address' => $macAddress,
        'event_code' => $eventCode
    )
));
