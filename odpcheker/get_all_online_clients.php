<?php
$files = glob('../serverlog/*_online_client.txt');
$result = [
    'odpMarkers' => [],
    'pelangganMarkers' => [],
    'lines' => []
];

foreach ($files as $file) {
    $data = json_decode(file_get_contents($file), true);
    if (!$data) continue;

    $result['odpMarkers'] = array_merge($result['odpMarkers'], $data['odpMarkers'] ?? []);
    $result['pelangganMarkers'] = array_merge($result['pelangganMarkers'], $data['pelangganMarkers'] ?? []);
    $result['lines'] = array_merge($result['lines'], $data['lines'] ?? []);
}

header('Content-Type: application/json');
echo json_encode($result);
