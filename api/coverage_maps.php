<?php
// Coverage Maps API - Returns ODP markers and coverage data
// Sebelumnya cuma dukung username+password -- tidak pernah baca param
// `key`/`api_key`, PADAHAL settingsapi.php mendokumentasikan resmi akses via
// API key. Diganti ke _bootstrap.php::api_authenticate() (session ->
// username+password -> API key dari tabel `apikey`) sama seperti api/odp.php dkk.
require_once '../koneksibilling.php';
require_once '_bootstrap.php';
session_start();
api_cors();

$input = api_read_input();
$debug = isset($_GET['debug']);

// Check database connection
if (!$conn) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database connection failed',
        'debug' => $debug ? ['conn_status' => 'failed'] : null
    ]);
    exit;
}

$auth = api_authenticate($conn, $input);
$pemilik = $auth['pemilik'];
if ($auth['method'] === 'apikey') {
    api_rate_limit($conn, $auth['api_key']);
}

// Initialize debug info
$debug_info = [
    'total_records' => 0,
    'with_tikor' => 0,
    'valid_coords' => 0,
    'invalid_coords_samples' => []
];

// Get ODP data from database
$sql_odp = "SELECT ID, NAME, KODE, PORT, BRAND, AREA, TIKOR, WARNA FROM odp";
$result_odp = $conn->query($sql_odp);

$odpMarkers = [];

if ($result_odp && $result_odp->num_rows > 0) {
    $debug_info['total_records'] = $result_odp->num_rows;
    
    while ($row = $result_odp->fetch_assoc()) {
        $tikor = str_replace(' ', '', trim($row['TIKOR'] ?? ''));
        
        if (!empty($tikor)) {
            $debug_info['with_tikor']++;
        }
        
        // Skip if TIKOR is empty or invalid
        if (empty($tikor) || strpos($tikor, ',') === false) {
            if (empty($debug_info['invalid_coords_samples']) && !empty($tikor)) {
                $debug_info['invalid_coords_samples'][] = 'ID:' . $row['ID'] . ' TIKOR:' . $tikor;
            }
            continue;
        }
        
        // Parse coordinates
        $coords = explode(',', $tikor);
        if (count($coords) < 2 || !is_numeric($coords[0]) || !is_numeric($coords[1])) {
            if (count($debug_info['invalid_coords_samples']) < 3) {
                $debug_info['invalid_coords_samples'][] = 'ID:' . $row['ID'] . ' TIKOR:' . $tikor . ' Coords:' . json_encode($coords);
            }
            continue;
        }
        
        $lat = floatval($coords[0]);
        $lng = floatval($coords[1]);
        
        // Validate latitude and longitude ranges
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            if (count($debug_info['invalid_coords_samples']) < 3) {
                $debug_info['invalid_coords_samples'][] = 'ID:' . $row['ID'] . ' LAT:' . $lat . ' LNG:' . $lng . ' (out of range)';
            }
            continue;
        }
        
        $debug_info['valid_coords']++;
        
        $odpMarkers[] = [
            'id' => $row['ID'],
            'name' => $row['NAME'],
            'kode' => $row['KODE'],
            'port' => $row['PORT'],
            'brand' => $row['BRAND'],
            'area' => $row['AREA'],
            'lat' => $lat,
            'lng' => $lng,
            'color' => !empty($row['WARNA']) ? $row['WARNA'] : '#f39c12',
            'popup' => 
                "<b>ODP:</b> " . htmlspecialchars($row['NAME']) . "<br>" .
                "<b>Kode:</b> " . htmlspecialchars($row['KODE']) . "<br>" .
                "<b>Port:</b> " . htmlspecialchars($row['PORT']) . "<br>" .
                "<b>Brand:</b> " . htmlspecialchars($row['BRAND']) . "<br>" .
                "<b>Area:</b> " . htmlspecialchars($row['AREA']) . "<br>" .
                "<b>Koordinat:</b> " . $lat . ", " . $lng
        ];
    }
}

// Calculate coverage distance if coordinates provided
$coverage_check = null;
if (!empty($_GET['lat']) && !empty($_GET['lng'])) {
    $user_lat = floatval($_GET['lat']);
    $user_lng = floatval($_GET['lng']);
    
    if ($user_lat >= -90 && $user_lat <= 90 && $user_lng >= -180 && $user_lng <= 180) {
        $coverage_check = [
            'lat' => $user_lat,
            'lng' => $user_lng,
            'coverage_radius' => 200, // meters
            'nearest_odp' => null,
            'is_covered' => false
        ];
        
        // Find nearest ODP
        $min_distance = PHP_INT_MAX;
        foreach ($odpMarkers as $odp) {
            $distance = haversineDistance($user_lat, $user_lng, $odp['lat'], $odp['lng']);
            if ($distance < $min_distance) {
                $min_distance = $distance;
                $coverage_check['nearest_odp'] = [
                    'id' => $odp['id'],
                    'name' => $odp['name'],
                    'distance' => round($distance),
                    'lat' => $odp['lat'],
                    'lng' => $odp['lng']
                ];
            }
        }
        
        // Check if covered (within 200m)
        if ($coverage_check['nearest_odp'] && $coverage_check['nearest_odp']['distance'] <= 200) {
            $coverage_check['is_covered'] = true;
        }
    }
}

// Return response
echo json_encode([
    'success' => true,
    'data' => [
        'markers' => $odpMarkers,
        'total_markers' => count($odpMarkers),
        'center' => [ -6.2088, 106.8456 ], // Default center: Jakarta
        'zoom' => 12
    ],
    'coverage_check' => $coverage_check,
    'debug' => $debug ? $debug_info : null
], JSON_UNESCAPED_SLASHES);

// Haversine distance calculation (meters)
function haversineDistance($lat1, $lon1, $lat2, $lon2) {
    $R = 6371000; // Earth radius in meters
    $φ1 = $lat1 * M_PI / 180;
    $φ2 = $lat2 * M_PI / 180;
    $Δφ = ($lat2 - $lat1) * M_PI / 180;
    $Δλ = ($lon2 - $lon1) * M_PI / 180;
    
    $a = sin($Δφ / 2) ** 2 + cos($φ1) * cos($φ2) * sin($Δλ / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $R * $c;
}
?>
