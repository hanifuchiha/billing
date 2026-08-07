<?php
require_once __DIR__ . '/../cek-sesi.php';
require_once __DIR__ . '/../routeros_api.class.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Metode request tidak diizinkan.'
    ]);
    exit;
}

$idpel = isset($_POST['idpel']) ? trim((string)$_POST['idpel']) : '';
$pemilik = isset($_POST['pemilik']) ? trim((string)$_POST['pemilik']) : '';
$area = isset($_POST['area']) ? trim((string)$_POST['area']) : '';

if ($idpel === '') {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Parameter idpel wajib diisi.'
    ]);
    exit;
}

$idpelEsc = mysqli_real_escape_string($conn, $idpel);

$sqlCustomer = "SELECT IDPEL, PEMILIK, AREA FROM pelanggan WHERE IDPEL = '$idpelEsc' LIMIT 1";
$resultCustomer = mysqli_query($conn, $sqlCustomer);
$customer = $resultCustomer ? mysqli_fetch_assoc($resultCustomer) : null;

$customerPemilik = trim((string)($customer['PEMILIK'] ?? ''));
$customerArea = trim((string)($customer['AREA'] ?? ''));

if ($customerPemilik === '' && $pemilik !== '') {
    $customerPemilik = $pemilik;
}
if ($customerArea === '' && $area !== '') {
    $customerArea = $area;
}

if ($customerPemilik === '') {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => 'Data pelanggan tidak ditemukan atau PEMILIK kosong.'
    ]);
    exit;
}

$customerPemilikEsc = mysqli_real_escape_string($conn, $customerPemilik);
$customerAreaEsc = mysqli_real_escape_string($conn, $customerArea);

$sqlServerExact = "SELECT IP, PEMILIK, PASSWORD, AREA FROM server WHERE PEMILIK = '$customerPemilikEsc'";
if ($customerArea !== '') {
    $sqlServerExact .= " AND AREA = '$customerAreaEsc'";
}
if ($AKSES !== 'ASSISTANT') {
    $sqlServerExact .= " AND user_id = " . (int)$current_user_id;
} elseif (!empty($area_list)) {
    $sqlServerExact .= " AND AREA IN ($area_list)";
}
$sqlServerExact .= " ORDER BY id DESC LIMIT 1";

$resultServerExact = mysqli_query($conn, $sqlServerExact);
$server = $resultServerExact ? mysqli_fetch_assoc($resultServerExact) : null;

if (!$server) {
    $sqlServerFallback = "SELECT IP, PEMILIK, PASSWORD, AREA FROM server WHERE PEMILIK = '$customerPemilikEsc'";
    if ($AKSES !== 'ASSISTANT') {
        $sqlServerFallback .= " AND user_id = " . (int)$current_user_id;
    } elseif (!empty($area_list)) {
        $sqlServerFallback .= " AND AREA IN ($area_list)";
    }
    $sqlServerFallback .= " ORDER BY id DESC LIMIT 1";

    $resultServerFallback = mysqli_query($conn, $sqlServerFallback);
    $server = $resultServerFallback ? mysqli_fetch_assoc($resultServerFallback) : null;
}

if (!$server) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => 'Server pelanggan tidak ditemukan.'
    ]);
    exit;
}

$ip = trim((string)($server['IP'] ?? ''));
$userServer = trim((string)($server['PEMILIK'] ?? ''));
$passwordServer = trim((string)($server['PASSWORD'] ?? ''));

if ($ip === '' || $userServer === '' || $passwordServer === '') {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Konfigurasi server belum lengkap (IP/User/Password).'
    ]);
    exit;
}

$api = new RouterosAPI();
$api->debug = false;

if (!$api->connect($ip, $userServer, $passwordServer)) {
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'message' => 'Gagal koneksi ke router.'
    ]);
    exit;
}

try {
    $api->write('/ppp/secret/print');
    $read = $api->read(false);
    $secrets = $api->parseResponse($read);

    $secretIndex = false;
    foreach ($secrets as $idx => $secretRow) {
        $secretName = isset($secretRow['name']) ? trim((string)$secretRow['name']) : '';
        if (strcasecmp($secretName, $idpel) === 0) {
            $secretIndex = $idx;
            break;
        }
    }
    $profile = $secretIndex !== false ? ((string)($secrets[$secretIndex]['profile'] ?? '-')) : '-';

    $pppActive = $api->comm('/ppp/active/print', [
        '?name' => $idpel
    ]);

    $status = 'Offline';
    $loginVia = '-';
    if (!empty($pppActive)) {
        $status = 'Online';
        $radiusFlag = (string)($pppActive[0]['radius'] ?? 'false');
        $loginVia = ($radiusFlag === 'true') ? 'radius' : 'local';
    }

    echo json_encode([
        'success' => true,
        'status' => $status,
        'profile' => $profile,
        'login_via' => $loginVia,
        'idpel' => $idpel,
        'server_ip' => $ip,
        'server_pemilik' => (string)($server['PEMILIK'] ?? ''),
        'server_area' => (string)($server['AREA'] ?? '')
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Gagal membaca status pelanggan: ' . $e->getMessage()
    ]);
} finally {
    $api->disconnect();
}
