<?php
include '../cek-sesi.php';

header('Content-Type: application/json');

$idpel = trim($_GET['idpel'] ?? '');
$servers = trim($_GET['servers'] ?? '');
$allowedServers = array_values(array_filter(array_map('trim', explode(',', str_replace("'", '', $servers)))));

$file = __DIR__ . '/debug/optical_cache.json';
if (!file_exists($file)) {
    echo json_encode(['found' => false, 'rxDbm' => 0, 'txDbm' => 0, 'source' => null]);
    exit;
}

$payload = json_decode((string) file_get_contents($file), true);
$records = is_array($payload['records'] ?? null) ? $payload['records'] : [];

$matched = null;
foreach ($records as $record) {
    if ($idpel !== '' && strcasecmp((string) ($record['idpel'] ?? ''), $idpel) !== 0) {
        continue;
    }
    if ($allowedServers && !in_array((string) ($record['pemilik'] ?? ''), $allowedServers, true)) {
        continue;
    }
    $matched = $record;
    break;
}

if (!$matched) {
    echo json_encode(['found' => false, 'rxDbm' => 0, 'txDbm' => 0, 'source' => null]);
    exit;
}

echo json_encode([
    'found' => true,
    'rxDbm' => $matched['rx_dbm'] ?? 0,
    'txDbm' => $matched['tx_dbm'] ?? 0,
    'redaman' => $matched['rx_redaman'] ?? null,
    'source' => $matched['source'] ?? null,
    'brand' => $matched['brand'] ?? null,
    'updated_at' => $matched['updated_at'] ?? null,
]);
?>