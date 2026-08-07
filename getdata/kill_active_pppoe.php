<?php
include '../cek-sesi.php';
require 'routeros_api.class.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$idpel    = trim((string)($_POST['idpel']    ?? ''));
$ipServer = trim((string)($_POST['ip']       ?? ''));
$userSrv  = trim((string)($_POST['us']       ?? ''));
$passSrv  = trim((string)($_POST['ps']       ?? ''));

if ($idpel === '' || $ipServer === '' || $userSrv === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Parameter tidak lengkap.']);
    exit;
}

// Verifikasi server milik user yang login
$ipEsc   = mysqli_real_escape_string($conn, $ipServer);
$userEsc = mysqli_real_escape_string($conn, $userSrv);
$currentUserId = isset($current_user_id) ? (int)$current_user_id : 0;

$srvCheck = "SELECT IP FROM server WHERE IP = '$ipEsc' AND PEMILIK = '$userEsc'";
if ($AKSES === 'ASSISTANT') {
    if (!isset($area_list) || trim((string)$area_list) === '') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Akses ditolak.']);
        exit;
    }
    $srvCheck .= " AND AREA IN ($area_list)";
} elseif ($AKSES !== 'ADMIN') {
    $srvCheck .= " AND user_id = $currentUserId";
}

$srvResult = mysqli_query($conn, $srvCheck);
if (!$srvResult || mysqli_num_rows($srvResult) === 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Server tidak ditemukan atau akses ditolak.']);
    exit;
}

$api = new RouterosAPI();
$api->timeout = 5;
$api->attempts = 1;
$api->delay    = 0;

if (!$api->connect($ipServer, $userSrv, $passSrv)) {
    echo json_encode(['success' => false, 'message' => 'Gagal konek ke MikroTik.']);
    exit;
}

// Cari active connection berdasarkan nama PPPoE
$api->write('/ppp/active/print', false);
$api->write('?name=' . $idpel);
$api->write('=.proplist=.id,name');
$read    = $api->read(false);
$actives = $api->parseResponse($read);

if (!is_array($actives) || count($actives) === 0) {
    $api->disconnect();
    echo json_encode(['success' => false, 'message' => 'Tidak ada koneksi aktif ditemukan untuk ' . htmlspecialchars($idpel, ENT_QUOTES, 'UTF-8') . '.']);
    exit;
}

$killedCount = 0;
$errors      = [];

foreach ($actives as $active) {
    $activeId = trim((string)($active['.id'] ?? ''));
    if ($activeId === '') continue;

    $api->write('/ppp/active/remove', false);
    $api->write('=.id=' . $activeId);
    $removeRead = $api->read(false);
    $removeResp = $api->parseResponse($removeRead);

    // Jika tidak ada error, anggap berhasil
    $hasError = false;
    if (is_array($removeResp)) {
        foreach ($removeResp as $r) {
            if (isset($r['!trap']) || isset($r['message'])) {
                $hasError = true;
                $errors[] = (string)($r['message'] ?? 'Unknown error');
            }
        }
    }
    if (!$hasError) {
        $killedCount++;
    }
}

$api->disconnect();

if ($killedCount > 0) {
    echo json_encode([
        'success' => true,
        'message' => 'Koneksi aktif ' . htmlspecialchars($idpel, ENT_QUOTES, 'UTF-8') . ' berhasil diputus (' . $killedCount . ' sesi).',
        'killed'  => $killedCount
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Gagal memutus koneksi: ' . implode(', ', $errors ?: ['Tidak ada sesi yang dihapus.']),
        'killed'  => 0
    ]);
}
exit;
