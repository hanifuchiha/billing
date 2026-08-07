<?php
require_once __DIR__ . '/../cek-sesi.php';
require_once __DIR__ . '/../koneksibilling.php';
require_once __DIR__ . '/acs_live_cache_service.php';

header('Content-Type: application/json; charset=utf-8');

$idpel = isset($_GET['idpel']) ? trim((string)$_GET['idpel']) : '';
$pemilik = isset($_GET['pemilik']) ? trim((string)$_GET['pemilik']) : '';
$force = isset($_GET['force']) && $_GET['force'] === '1';

if ($idpel === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Parameter idpel wajib diisi',
        'rows' => [],
        'meta' => ['source' => 'bad-request'],
    ]);
    exit;
}

$result = acs_live_get_data($conn, $idpel, $pemilik, $force);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
