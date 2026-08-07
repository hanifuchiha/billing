<?php
include '../cek-sesi.php';
require_once __DIR__ . '/acs_live_cache_lib.php';

header('Content-Type: application/json');

$idpel = trim((string)($_GET['idpel'] ?? ''));
$forceRefresh = isset($_GET['refresh']) && $_GET['refresh'] === '1';

if ($idpel === '') {
    echo json_encode([
        'success' => false,
        'message' => 'IDPEL wajib diisi',
        'rows' => []
    ]);
    exit;
}

$cacheDir = dirname(__DIR__) . '/cache/acs_live';
acsEnsureCacheDir($cacheDir);

$cacheKey = acsSafeIdpelFileKey($idpel);
$cacheFile = $cacheDir . '/acs_live_' . $cacheKey . '.json';

$ttlSeconds = 3600;
$refreshThrottleSeconds = 30;

$cached = acsReadCacheFile($cacheFile);
$now = time();
$cacheAge = null;
if ($cached && isset($cached['updated_at'])) {
    $cacheAge = $now - (int)$cached['updated_at'];
}

$isFresh = ($cacheAge !== null && $cacheAge >= 0 && $cacheAge < $ttlSeconds);
$canForceRefresh = ($cacheAge === null || $cacheAge >= $refreshThrottleSeconds);

if ($isFresh && (!$forceRefresh || !$canForceRefresh)) {
    echo json_encode([
        'success' => true,
        'source' => 'cache',
        'updated_at' => (int)$cached['updated_at'],
        'rows' => isset($cached['rows']) && is_array($cached['rows']) ? $cached['rows'] : []
    ]);
    exit;
}

$userId = isset($USER_ID) ? (int)$USER_ID : (isset($_SESSION['id']) ? (int)$_SESSION['id'] : 0);
$akses = isset($AKSES) ? (string)$AKSES : (isset($_SESSION['AKSES']) ? (string)$_SESSION['AKSES'] : '');

$rows = acsFetchLiveRowsByIdpel($conn, $userId, $akses, $idpel, 5);

$payload = [
    'idpel' => $idpel,
    'updated_at' => $now,
    'rows' => $rows
];
acsWriteCacheFile($cacheFile, $payload);

echo json_encode([
    'success' => true,
    'source' => 'live',
    'updated_at' => $now,
    'rows' => $rows
]);
exit;
