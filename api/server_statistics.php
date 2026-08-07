<?php
header('Content-Type: application/json');
require_once '../koneksibilling.php';

$username = $_GET['username'] ?? '';
$password = $_GET['password'] ?? '';
$ip = trim($_GET['ip'] ?? '');

if ($username === '' || $password === '' || $ip === '') {
    echo json_encode([
        'success' => false,
        'error' => 'Parameter username, password, dan ip wajib diisi'
    ]);
    exit;
}

function auth_user($conn, $username, $password)
{
    $stmt = $conn->prepare('SELECT USERNAME, PASWORD FROM user WHERE USERNAME = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $result = $stmt->get_result();
    if (!$result || $result->num_rows === 0) {
        return false;
    }
    $row = $result->fetch_assoc();
    return (password_verify($password, $row['PASWORD']) || $password === $row['PASWORD']) ? $row['USERNAME'] : false;
}

function safe_count($conn, $sql)
{
    $res = @mysqli_query($conn, $sql);
    if (!$res) {
        return 0;
    }
    $row = mysqli_fetch_assoc($res);
    return (int)($row['total'] ?? 0);
}

$authenticatedUser = auth_user($conn, $username, $password);
if (!$authenticatedUser) {
    echo json_encode([
        'success' => false,
        'error' => 'Autentikasi gagal'
    ]);
    exit;
}

$ipEscaped = mysqli_real_escape_string($conn, $ip);
$serverQuery = mysqli_query(
    $conn,
    "SELECT id, PEMILIK, AREA, IP FROM server WHERE IP = '$ipEscaped' OR IP LIKE '$ipEscaped:%' LIMIT 1"
);

if (!$serverQuery || mysqli_num_rows($serverQuery) === 0) {
    echo json_encode([
        'success' => true,
        'total_pppoe_secret' => 0,
        'active_pppoe' => 0,
        'active_pppoe_expired' => 0,
        'inactive_pppoe_los' => 0,
        'active_hotspot' => 0,
        'note' => 'Server tidak ditemukan'
    ]);
    exit;
}

$server = mysqli_fetch_assoc($serverQuery);
$pemilik = mysqli_real_escape_string($conn, $server['PEMILIK'] ?? '');
$area = mysqli_real_escape_string($conn, $server['AREA'] ?? '');

$baseWhere = "PEMILIK = '$pemilik'";
if ($area !== '') {
    $baseWhere .= " AND AREA = '$area'";
}

$totalPppoe = safe_count($conn, "SELECT COUNT(*) AS total FROM pelanggan WHERE $baseWhere");
$activePppoe = safe_count(
    $conn,
    "SELECT COUNT(*) AS total FROM pelanggan WHERE $baseWhere AND (UPPER(STATUS) LIKE '%ONLINE%' OR UPPER(STATUS) LIKE '%AKTIF%' OR UPPER(STATUS) LIKE '%ACTIVE%' OR UPPER(STATUS) LIKE '%CONNECTED%')"
);
$expiredPppoe = safe_count($conn, "SELECT COUNT(*) AS total FROM pelanggan WHERE $baseWhere AND TEMPO IS NOT NULL AND DATE(TEMPO) < CURDATE()");
$losPppoe = safe_count(
    $conn,
    "SELECT COUNT(*) AS total FROM pelanggan WHERE $baseWhere AND (UPPER(STATUS) LIKE '%LOS%' OR UPPER(STATUS) LIKE '%OFFLINE%' OR UPPER(STATUS) LIKE '%PUTUS%')"
);

$activeHotspot = safe_count(
    $conn,
    "SELECT COUNT(*) AS total FROM hotspot WHERE PEMILIK = '$pemilik'"
);
if ($activeHotspot === 0) {
    $activeHotspot = safe_count(
        $conn,
        "SELECT COUNT(*) AS total FROM pelanggan_hotspot WHERE PEMILIK = '$pemilik'"
    );
}

echo json_encode([
    'success' => true,
    'total_pppoe_secret' => $totalPppoe,
    'active_pppoe' => $activePppoe,
    'active_pppoe_expired' => $expiredPppoe,
    'inactive_pppoe_los' => $losPppoe,
    'active_hotspot' => $activeHotspot
]);
