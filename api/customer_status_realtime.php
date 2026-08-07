<?php
// api/customer_status_realtime.php
header('Content-Type: application/json');
require_once '../koneksibilling.php';


$idpel = isset($_GET['idpel']) ? trim($_GET['idpel']) : '';
if (!$idpel) {
    echo json_encode(['success' => false, 'error' => 'Parameter kurang']);
    exit;
}


// Ambil AREA dan PEMILIK dari pelanggan
$sql_pel = "SELECT AREA, PEMILIK FROM pelanggan WHERE IDPEL = '" . $conn->real_escape_string($idpel) . "' LIMIT 1";
$res_pel = $conn->query($sql_pel);
if (!$res_pel || $res_pel->num_rows == 0) {
    file_put_contents(__DIR__ . '/debug_status_realtime.log', date('Y-m-d H:i:s') . " | IDPEL: $idpel | SQL: $sql_pel | RESULT: PELANGGAN NOT FOUND\n", FILE_APPEND);
    echo json_encode(['success' => false, 'error' => "Data pelanggan tidak ditemukan untuk $idpel"]);
    exit;
}
$row_pel = $res_pel->fetch_assoc();
$area = $row_pel['AREA'] ?? '';
$pemilik = $row_pel['PEMILIK'] ?? '';
if (!$area || !$pemilik) {
    file_put_contents(__DIR__ . '/debug_status_realtime.log', date('Y-m-d H:i:s') . " | IDPEL: $idpel | SQL: $sql_pel | RESULT: AREA/PEMILIK EMPTY\n", FILE_APPEND);
    echo json_encode(['success' => false, 'error' => "AREA/PEMILIK kosong untuk $idpel"]);
    exit;
}

// Ambil IP dan PASSWORD server berdasarkan AREA dan PEMILIK
$sql_srv = "SELECT IP, PASSWORD FROM server WHERE AREA = '" . $conn->real_escape_string($area) . "' AND PEMILIK = '" . $conn->real_escape_string($pemilik) . "' LIMIT 1";
$res_srv = $conn->query($sql_srv);
if (!$res_srv || $res_srv->num_rows == 0) {
    file_put_contents(__DIR__ . '/debug_status_realtime.log', date('Y-m-d H:i:s') . " | IDPEL: $idpel | SQL: $sql_srv | RESULT: SERVER NOT FOUND\n", FILE_APPEND);
    echo json_encode(['success' => false, 'error' => "Data server tidak ditemukan untuk $idpel"]);
    exit;
}
$row_srv = $res_srv->fetch_assoc();
$ipserver = $row_srv['IP'] ?? '';
$pass = $row_srv['PASSWORD'] ?? '';
if (!$ipserver || !$pass) {
    file_put_contents(__DIR__ . '/debug_status_realtime.log', date('Y-m-d H:i:s') . " | IDPEL: $idpel | SQL: $sql_srv | RESULT: IP/PASS EMPTY | IP: $ipserver | PASS: $pass\n", FILE_APPEND);
    echo json_encode(['success' => false, 'error' => "IP/PASSWORD server kosong untuk $idpel"]);
    exit;
}
$user = $pemilik;


// Cek status langsung ke Mikrotik via RouterOS API

$status = 'Offline';
// Siapkan data mikrotik dan cekexpired (paket aktif)
$mikrotik_data = [
    'active_caller_id' => null,
    'remote_ip' => null,
    'service' => null,
    'cekexpired' => null // paket aktif
];
if (file_exists(dirname(__DIR__) . '/routeros_api.class.php')) {
    require_once dirname(__DIR__) . '/routeros_api.class.php';
    $API = new RouterosAPI();
    if ($API->connect($ipserver, $user, $pass)) {
        $pppoe = $API->comm('/ppp/active/print', [ '?name' => $idpel ]);
        if (!empty($pppoe)) {
            // ONLINE
            $status = 'Online';
            $row = $pppoe[0];
            $mikrotik_data['active_caller_id'] = isset($row['caller-id']) ? $row['caller-id'] : null;
            $mikrotik_data['remote_ip'] = isset($row['address']) ? $row['address'] : null;
            $mikrotik_data['service'] = isset($row['service']) ? $row['service'] : null;
            // Ambil profile dari secret
            $secret = $API->comm('/ppp/secret/print', [ '?name' => $idpel ]);
            if (!empty($secret)) {
                $row_secret = $secret[0];
                $mikrotik_data['cekexpired'] = isset($row_secret['profile']) ? $row_secret['profile'] : null;
            }
        } else {
            // OFFLINE
            $status = 'Offline';
            $secret = $API->comm('/ppp/secret/print', [ '?name' => $idpel ]);
            if (!empty($secret)) {
                $row = $secret[0];
                $mikrotik_data['cekexpired'] = isset($row['profile']) ? $row['profile'] : null;
                $mikrotik_data['last_logged_out'] = isset($row['last-logged-out']) ? $row['last-logged-out'] : null;
                $mikrotik_data['last_caller'] = isset($row['last-caller']) ? $row['last-caller'] : null;
            }
        }
        $API->disconnect();
    }
}


// Ambil field utama agar mudah diakses Android


$active_caller_id = isset($mikrotik_data['active_caller_id']) ? $mikrotik_data['active_caller_id'] : null;
$remote_ip = isset($mikrotik_data['remote_ip']) ? $mikrotik_data['remote_ip'] : null;
$service = isset($mikrotik_data['service']) ? $mikrotik_data['service'] : null;
$cekexpired = isset($mikrotik_data['cekexpired']) ? $mikrotik_data['cekexpired'] : null;
$last_logged_out = isset($mikrotik_data['last_logged_out']) ? $mikrotik_data['last_logged_out'] : null;
$last_caller = isset($mikrotik_data['last_caller']) ? $mikrotik_data['last_caller'] : null;

$response = [
    'success' => true,
    'idpel' => $idpel,
    'status' => $status,
    'area' => $area,
    'pemilik' => $pemilik,
    'active_caller_id' => $active_caller_id,
    'remote_ip' => $remote_ip,
    'service' => $service,
    'cekexpired' => $cekexpired,
    'last_logged_out' => $last_logged_out,
    'last_caller' => $last_caller
];
echo json_encode($response);
exit;

