<?php
// API: Get ODP Markers for Maps
// Returns list of all ODP markers with coordinates from TIKOR field
// Auth diganti ke _bootstrap.php::api_authenticate() (session -> username+password -> API key
// dari tabel `apikey`) -- sebelumnya endpoint ini tidak pernah cek API key.
require_once '../koneksibilling.php';
require_once '_bootstrap.php';
session_start();
api_cors();

$input = api_read_input();
$auth = api_authenticate($conn, $input);
if ($auth['method'] === 'apikey') {
    api_rate_limit($conn, $auth['api_key']);
}

// Get ODP markers from database
$sql = "SELECT NAME, KODE, PORT, BRAND, AREA, TIKOR, WARNA FROM odp WHERE TIKOR IS NOT NULL AND TIKOR != ''";
$result = $conn->query($sql);

$markers = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $tikor = str_replace(' ', '', trim($row['TIKOR']));
        
        // Skip if no comma (invalid coordinates)
        if (empty($tikor) || strpos($tikor, ',') === false) {
            continue;
        }
        
        // Parse coordinates
        $coords = explode(',', $tikor);
        if (count($coords) < 2) {
            continue;
        }
        
        $lat = floatval(trim($coords[0]));
        $lng = floatval(trim($coords[1]));
        
        // Validate coordinates
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            continue;
        }
        
        $markers[] = [
            'name' => $row['NAME'] ?? '',
            'kode' => $row['KODE'] ?? '',
            'port' => $row['PORT'] ?? '',
            'brand' => $row['BRAND'] ?? '',
            'area' => $row['AREA'] ?? '',
            'lat' => $lat,
            'lng' => $lng,
            'color' => !empty($row['WARNA']) ? $row['WARNA'] : '#f39c12',
            'popup' => 
                "<b>ODP:</b> " . htmlspecialchars($row['NAME']) . "<br>" .
                "<b>Kode:</b> " . htmlspecialchars($row['KODE']) . "<br>" .
                "<b>Port:</b> " . htmlspecialchars($row['PORT']) . "<br>" .
                "<b>Server Area:</b> " . htmlspecialchars($row['BRAND']) . "<br>" .
                "<b>Area:</b> " . htmlspecialchars($row['AREA']) . "<br>" .
                "<b>Koordinat:</b> " . $lat . ", " . $lng
        ];
    }
}

// Return response
echo json_encode([
    'success' => true,
    'data' => [
        'markers' => $markers,
        'total_markers' => count($markers),
        'center' => [-6.2, 106.8],
        'zoom' => 12
    ]
], JSON_UNESCAPED_SLASHES);
?>
