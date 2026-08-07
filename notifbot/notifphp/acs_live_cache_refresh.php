<?php
include '../../koneksidb.php';
require_once '../../getdata/acs_live_cache_lib.php';

if (!isset($conn) || !$conn || $conn->connect_error) {
    exit("Koneksi database gagal\n");
}

$filename = basename(__FILE__);
$nameOnly = pathinfo($filename, PATHINFO_FILENAME);
$parts = explode('_', $nameOnly);
$pemilik = end($parts);

if ($pemilik === 'refresh' || $pemilik === 'cache' || $pemilik === 'live' || $pemilik === 'acs') {
    $pemilik = '';
}

$userId = 0;
$akses = 'ADMIN';
if ($pemilik !== '') {
    $pemilikEsc = mysqli_real_escape_string($conn, $pemilik);
    $resUser = mysqli_query($conn, "SELECT id, STATUS FROM user WHERE USERNAME = '$pemilikEsc' LIMIT 1");
    if ($resUser && ($rowUser = mysqli_fetch_assoc($resUser))) {
        $userId = (int)($rowUser['id'] ?? 0);
        $akses = (string)($rowUser['STATUS'] ?? 'USER');
    }
}

$settingsPath = dirname(__DIR__) . '/data/acs_live_settings-' . ($pemilik !== '' ? $pemilik : 'default') . '.json';
$enabled = false;
if (file_exists($settingsPath)) {
    $settings = json_decode(file_get_contents($settingsPath), true);
    if (is_array($settings) && !empty($settings['enabled'])) {
        $enabled = true;
    }
}

if (!$enabled) {
    exit("ACS cache cron nonaktif\n");
}

$cacheDir = dirname(__DIR__, 2) . '/cache/acs_live';
acsEnsureCacheDir($cacheDir);

$files = glob($cacheDir . '/acs_live_*.json');
if (!is_array($files)) {
    $files = [];
}

$refreshed = 0;
foreach ($files as $file) {
    $cached = acsReadCacheFile($file);
    if (!$cached || empty($cached['idpel'])) {
        continue;
    }

    $idpel = trim((string)$cached['idpel']);
    if ($idpel === '') {
        continue;
    }

    $rows = acsFetchLiveRowsByIdpel($conn, $userId, $akses, $idpel, 5);
    $payload = [
        'idpel' => $idpel,
        'updated_at' => time(),
        'rows' => $rows
    ];
    acsWriteCacheFile($file, $payload);
    $refreshed++;
}

echo "ACS live cache refreshed: {$refreshed} file(s)\n";
