<?php
// api/customer_pppoe_enriched.php
// API khusus untuk Mobile Android - mengembalikan data lengkap dengan status realtime
// Sebelumnya cuma dukung session ATAU username+password -- tidak pernah baca
// param `key`/`api_key`, PADAHAL settingsapi.php mendokumentasikan resmi
// akses via API key. Diganti ke _bootstrap.php::api_authenticate() (session ->
// username+password -> API key dari tabel `apikey`) sama seperti api/odp.php dkk.
require_once '../koneksibilling.php';
require_once '../routeros_api.class.php';
require_once '_bootstrap.php';
session_start();
api_cors();

$input = api_read_input();

$auth = api_authenticate($conn, $input);
$pemilik = $auth['pemilik'];
if ($auth['method'] === 'apikey') {
    api_rate_limit($conn, $auth['api_key']);
}

// Ambil filter dari GET
$filter_area = isset($_GET['area']) ? $conn->real_escape_string($_GET['area']) : '';
$filter_status = isset($_GET['status']) ? $conn->real_escape_string($_GET['status']) : '';
$filter_paket = isset($_GET['paket']) ? $conn->real_escape_string($_GET['paket']) : '';
$filter_search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$filter_odp = isset($_GET['odp']) ? $conn->real_escape_string($_GET['odp']) : '';
$filter_type = isset($_GET['type']) ? $conn->real_escape_string($_GET['type']) : 'PPPOE';

// Ambil semua server milik user
$stmt = $conn->prepare("SELECT PEMILIK FROM server WHERE user_id = (SELECT id FROM user WHERE USERNAME = ?)");
$stmt->bind_param("s", $pemilik);
$stmt->execute();
$res = $stmt->get_result();
$pemilikList = [];
while ($row = $res->fetch_assoc()) {
    $pemilikList[] = $row['PEMILIK'];
}
if (count($pemilikList) === 0) $pemilikList = [$pemilik];

$escaped = array_map(function($v) use ($conn) { return "'" . $conn->real_escape_string($v) . "'"; }, $pemilikList);
$in = implode(",", $escaped);
$where = "PEMILIK IN ($in)";

// Add filter type (PPPOE, LOS, HOTSPOT)
if ($filter_type === 'PPPOE') {
    $where .= " AND TIPE_KONEKSI IN ('PPPOE', 'PPPoE', 'pppoe')";
} elseif ($filter_type === 'LOS') {
    $where .= " AND TIPE_KONEKSI IN ('LOS', 'Los', 'los')";
} elseif ($filter_type === 'HOTSPOT') {
    $where .= " AND TIPE_KONEKSI IN ('HOTSPOT', 'Hotspot', 'hotspot')";
}

if ($filter_area !== '') {
    $where .= " AND AREA = '$filter_area'";
}
if ($filter_status !== '') {
    $where .= " AND STATUS = '$filter_status'";
}
if ($filter_paket !== '') {
    $where .= " AND PAKET = '$filter_paket'";
}
if ($filter_odp !== '') {
    $where .= " AND ODP = '$filter_odp'";
}
if ($filter_search !== '') {
    $where .= " AND (IDPEL LIKE '%$filter_search%' OR NAMA LIKE '%$filter_search%' OR NOWA LIKE '%$filter_search%')";
}

$sql = "SELECT IDPEL, NAMA, STATUS, PAKET, AREA, ODP, ALAMAT, NOWA, EMAIL, TANGGALPASANG, PEMILIK FROM pelanggan WHERE $where LIMIT 100";
$result = $conn->query($sql);
$data = [];

while ($row = $result->fetch_assoc()) {
    $idpel = $row['IDPEL'];
    $area = $row['AREA'];
    $pemilik_pel = $row['PEMILIK'];
    
    // Get server info for status fetch
    $sql_srv = "SELECT IP, PASSWORD FROM server WHERE AREA = '" . $conn->real_escape_string($area) . "' AND PEMILIK = '" . $conn->real_escape_string($pemilik_pel) . "' LIMIT 1";
    $res_srv = $conn->query($sql_srv);
    
    $enrich = [
        'PAKET_HARGA' => 'N/A',
        'DOWN_SPEED' => 'N/A',
        'UP_SPEED' => 'N/A',
        'REMOTE_IP' => 'N/A',
        'RX_DBM' => 'Unknown',
        'RX_REDAMAN' => 'N/A',
        'STATUS_REALTIME' => 'UNKNOWN'
    ];
    
    // Fetch real-time status from Mikrotik
    if ($res_srv && $res_srv->num_rows > 0) {
        $row_srv = $res_srv->fetch_assoc();
        $ipserver = $row_srv['IP'];
        $pass = $row_srv['PASSWORD'];
        
        // Default to offline
        $status_realtime = 'Offline';
        $remote_ip = 'N/A';
        $active_caller = 'N/A';
        $profile = 'N/A';
        
        // Connect to Mikrotik
        if (file_exists(dirname(__DIR__) . '/routeros_api.class.php')) {
            $API = new RouterosAPI();
            if ($API->connect($ipserver, $pemilik_pel, $pass)) {
                // Check if active
                $pppoe = $API->comm('/ppp/active/print', [ '?name' => $idpel ]);
                if (!empty($pppoe)) {
                    $status_realtime = 'Online';
                    $row_active = $pppoe[0];
                    $remote_ip = isset($row_active['address']) ? $row_active['address'] : 'N/A';
                    $active_caller = isset($row_active['caller-id']) ? $row_active['caller-id'] : 'N/A';
                    
                    // Get download/upload from profile
                    $secret = $API->comm('/ppp/secret/print', [ '?name' => $idpel ]);
                    if (!empty($secret)) {
                        $profile = isset($secret[0]['profile']) ? $secret[0]['profile'] : 'N/A';
                    }
                } else {
                    // Check secret for profile and last disconnect
                    $secret = $API->comm('/ppp/secret/print', [ '?name' => $idpel ]);
                    if (!empty($secret)) {
                        $profile = isset($secret[0]['profile']) ? $secret[0]['profile'] : 'N/A';
                    }
                }
                $API->disconnect();
            }
        }
        
        $enrich['STATUS_REALTIME'] = $status_realtime;
        $enrich['REMOTE_IP'] = $remote_ip;
        $enrich['MAC'] = $active_caller;
        
        // Try to get paket speeds from database
        $sql_paket = "SELECT HARGA, SPEED_DOWN, SPEED_UP FROM paket WHERE NAMA = '" . $conn->real_escape_string($row['PAKET']) . "' LIMIT 1";
        $res_paket = $conn->query($sql_paket);
        if ($res_paket && $res_paket->num_rows > 0) {
            $row_paket = $res_paket->fetch_assoc();
            $harga = isset($row_paket['HARGA']) ? $row_paket['HARGA'] : 'N/A';
            $enrich['PAKET_HARGA'] = $harga > 0 ? 'Rp ' . number_format($harga, 0, ',', '.') : 'N/A';
            $enrich['DOWN_SPEED'] = isset($row_paket['SPEED_DOWN']) ? $row_paket['SPEED_DOWN'] : 'N/A';
            $enrich['UP_SPEED'] = isset($row_paket['SPEED_UP']) ? $row_paket['SPEED_UP'] : 'N/A';
        }
    }
    
    // Merge enriched data with original
    $data[] = array_merge($row, $enrich);
}

echo json_encode(['success' => true, 'data' => $data]);
exit;
?>
