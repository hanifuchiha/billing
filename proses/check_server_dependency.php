
<?php
// proses/check_server_dependency.php
// DEBUG: Enable error reporting for troubleshooting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../koneksidb.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST)) {
    $ref = $_SERVER['HTTP_REFERER'] ?? '../index.php';
    if (!headers_sent()) {
        header('Location: ' . $ref);
    } else {
        echo "<script>window.location.href=" . json_encode($ref) . ";</script>";
    }
    exit;
}

$brand = isset($_POST['brand']) ? trim($_POST['brand']) : '';
$pemilik = isset($_POST['pemilik']) ? trim($_POST['pemilik']) : '';
$area = isset($_POST['area']) ? trim($_POST['area']) : '';

// Debug: log input
if (!$brand || !$pemilik || !$area) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Input tidak lengkap',
        'brand' => $brand,
        'pemilik' => $pemilik,
        'area' => $area
    ]);
    exit;
}

function hasData($conn, $table, $brand, $pemilik, $area, $brandField = 'BRAND', $pemilikField = 'PEMILIK', $areaField = 'AREA') {
    $sql = "SELECT COUNT(*) as cnt FROM `$table` WHERE `$brandField`=? AND `$pemilikField`=? AND `$areaField`=?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Prepare failed',
            'table' => $table,
            'sql' => $sql,
            'mysqli_error' => $conn->error
        ]);
        exit;
    }
    $stmt->bind_param('sss', $brand, $pemilik, $area);
    if (!$stmt->execute()) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Execute failed',
            'table' => $table,
            'sql' => $sql,
            'mysqli_error' => $stmt->error
        ]);
        exit;
    }
    $result = $stmt->get_result();
    if (!$result) {
        http_response_code(500);
        echo json_encode([
            'error' => 'get_result failed',
            'table' => $table,
            'sql' => $sql,
            'mysqli_error' => $stmt->error
        ]);
        exit;
    }
    $row = $result->fetch_assoc();
    return ($row['cnt'] > 0);
}

$can_delete = true;
$debug = [];

// ODP
if (hasData($conn, 'odp', $brand, $pemilik, $area)) {
    $can_delete = false;
    $debug[] = 'ODP ada';
}
// Packages (tabel: paket)
if (hasData($conn, 'paket', $brand, $pemilik, $area)) {
    $can_delete = false;
    $debug[] = 'Paket ada';
}
// Packages Hotspot (tabel: paket_hotspot)
if (hasData($conn, 'paket_hotspot', $brand, $pemilik, $area)) {
    $can_delete = false;
    $debug[] = 'Paket Hotspot ada';
}
// Pelanggan
if (hasData($conn, 'pelanggan', $brand, $pemilik, $area)) {
    $can_delete = false;
    $debug[] = 'Pelanggan ada';
}
// OLT
if (hasData($conn, 'olt', $brand, $pemilik, $area, 'brandolt', 'pemilik', 'area')) {
    $can_delete = false;
    $debug[] = 'OLT ada';
}

echo json_encode([
    'can_delete' => $can_delete,
    'debug' => $debug,
    'input' => [
        'brand' => $brand,
        'pemilik' => $pemilik,
        'area' => $area
    ]
]);
