<?php
/**
 * Kill (hapus) Active PPP Connection di MikroTik
 * Method: POST
 * Params: active_id, server_ip, server_user
 * Response: JSON { success, message }
 */
require '../cek-sesi.php';
require '../routeros_api.class.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid method']);
    exit;
}

$activeId  = trim((string)($_POST['active_id'] ?? ''));
$serverIp  = trim((string)($_POST['server_ip'] ?? ''));
$serverUser = trim((string)($_POST['server_user'] ?? ''));

if ($activeId === '' || $serverIp === '' || $serverUser === '') {
    echo json_encode(['success' => false, 'message' => 'Parameter tidak lengkap.']);
    exit;
}

// Lookup password dari database (tidak expose password ke frontend)
$serverIpEsc   = mysqli_real_escape_string($conn, $serverIp);
$serverUserEsc = mysqli_real_escape_string($conn, $serverUser);
$currentUserId = isset($current_user_id) ? (int)$current_user_id : 0;

// WAJIB filter kepemilikan server -- tanpa ini, assistant/reseller manapun
// bisa mematikan koneksi PPPoE di server/area MILIK ORANG LAIN cukup dengan
// mengirim server_ip/server_user server lain via POST manual (IDOR), sama
// persis pola yang sudah benar di delete_unregistered_pppoe.php.
$serverQuery = "SELECT PASSWORD, AREA FROM server WHERE IP = '$serverIpEsc' AND PEMILIK = '$serverUserEsc'";
if ($AKSES === 'ASSISTANT') {
    if (!isset($area_list) || trim((string)$area_list) === '') {
        echo json_encode(['success' => false, 'message' => 'Area assistant tidak ditemukan.']);
        exit;
    }
    $serverQuery .= " AND AREA IN ($area_list)";
} elseif ($AKSES !== 'ADMIN') {
    $serverQuery .= " AND user_id = $currentUserId";
}
$serverQuery .= " LIMIT 1";
$serverResult = mysqli_query($conn, $serverQuery);

if (!$serverResult || mysqli_num_rows($serverResult) === 0) {
    echo json_encode(['success' => false, 'message' => 'Server tidak ditemukan di database.']);
    exit;
}

$serverData = mysqli_fetch_assoc($serverResult);
$serverPassword = (string)($serverData['PASSWORD'] ?? '');

// Koneksi ke MikroTik
$API = new RouterosAPI();
$API->debug = false;

if (!$API->connect($serverIp, $serverUser, $serverPassword)) {
    echo json_encode(['success' => false, 'message' => "Gagal koneksi ke MikroTik ($serverIp)."]);
    exit;
}

// Hapus active connection berdasarkan .id
$result = $API->comm("/ppp/active/remove", [".id" => $activeId]);
$API->disconnect();

if ($result !== false) {
    // Log ke history
    $history_file = "../notifbot/data/history-$ceknama.json";
    $history = [];
    if (file_exists($history_file)) {
        $history = json_decode(file_get_contents($history_file), true);
    }
    if (!is_array($history)) $history = [];
    $actorName = !empty($asistant_name) ? $asistant_name : $ceknama;
    $history[] = "[ $actorName - " . date('Y-m-d H:i:s') . " ] Memutus koneksi aktif ID $activeId di server $serverIp";
    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

    echo json_encode(['success' => true, 'message' => "Koneksi berhasil dimatikan (ID: $activeId)."]);
} else {
    echo json_encode(['success' => false, 'message' => "Gagal mematikan koneksi (ID: $activeId)."]);
}
