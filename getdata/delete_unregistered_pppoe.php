<?php
include '../cek-sesi.php';
require 'routeros_api.class.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method tidak diizinkan.'
    ]);
    exit;
}

$username = isset($_POST['username']) ? trim((string)$_POST['username']) : '';
$serverIp = isset($_POST['server_ip']) ? trim((string)$_POST['server_ip']) : '';
$serverUser = isset($_POST['server_user']) ? trim((string)$_POST['server_user']) : '';

if ($username === '' || $serverIp === '' || $serverUser === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Parameter username/server tidak lengkap.'
    ]);
    exit;
}

$usernameEsc = mysqli_real_escape_string($conn, $username);
$serverIpEsc = mysqli_real_escape_string($conn, $serverIp);
$serverUserEsc = mysqli_real_escape_string($conn, $serverUser);
$currentUserId = isset($current_user_id) ? (int)$current_user_id : 0;

$serverQuery = "SELECT IP, PEMILIK, PASSWORD, AREA FROM server WHERE IP = '$serverIpEsc' AND PEMILIK = '$serverUserEsc'";
if ($AKSES === 'ASSISTANT') {
    if (!isset($area_list) || trim((string)$area_list) === '') {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Area assistant tidak ditemukan.'
        ]);
        exit;
    }
    $serverQuery .= " AND AREA IN ($area_list)";
} elseif ($AKSES !== 'ADMIN') {
    $serverQuery .= " AND user_id = $currentUserId";
}
$serverQuery .= " LIMIT 1";

$serverResult = mysqli_query($conn, $serverQuery);
if (!$serverResult || mysqli_num_rows($serverResult) === 0) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => 'Server tidak ditemukan atau tidak punya akses.'
    ]);
    exit;
}

$serverData = mysqli_fetch_assoc($serverResult);
$api = new RouterosAPI();
$api->timeout = 2;
$api->attempts = 1;
$api->delay = 0;

if (!$api->connect((string)$serverData['IP'], (string)$serverData['PEMILIK'], (string)$serverData['PASSWORD'])) {
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'message' => 'Gagal konek ke Mikrotik.'
    ]);
    exit;
}

$secretRows = $api->comm('/ppp/secret/print', [
    '?name' => $username
]);

if (!is_array($secretRows) || count($secretRows) === 0 || !isset($secretRows[0]['.id'])) {
    $api->disconnect();
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => 'User PPPoE tidak ditemukan di server.'
    ]);
    exit;
}

$secretId = (string)$secretRows[0]['.id'];
$api->comm('/ppp/secret/remove', [
    '.id' => $secretId
]);
$api->disconnect();

// Invalidate scan cache to avoid stale table data.
$cacheDir = __DIR__ . '/../serverlog/cache_scan_pppoe';
if (is_dir($cacheDir)) {
    $cacheFiles = glob($cacheDir . '/*.json');
    if (is_array($cacheFiles)) {
        foreach ($cacheFiles as $cacheFile) {
            @unlink($cacheFile);
        }
    }
}

echo json_encode([
    'success' => true,
    'message' => 'PPPoE berhasil dihapus dari Mikrotik.',
    'username' => $usernameEsc,
    'server_ip' => $serverIpEsc,
    'server_user' => $serverUserEsc
]);
exit;
