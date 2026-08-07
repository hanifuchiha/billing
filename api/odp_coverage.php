<?php
// API: ODP Coverage Check
// Returns ODP markers and coverage status based on coordinates
// Auth diganti ke _bootstrap.php::api_authenticate() (session -> username+password -> API key
// dari tabel `apikey`) -- sebelumnya endpoint ini tidak pernah cek API key.
require_once '../koneksibilling.php';
require_once '_bootstrap.php';
session_start();
api_cors();

$input = api_read_input();
$lat = isset($input['lat']) ? floatval($input['lat']) : null;
$lng = isset($input['lng']) ? floatval($input['lng']) : null;

$auth = api_authenticate($conn, $input);
$pemilik = $auth['pemilik'];
if ($auth['method'] === 'apikey') {
    api_rate_limit($conn, $auth['api_key']);
}

// Check database connection
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

// Get all ODP data from database
$sql_odp = "SELECT ID, NAME, KODE, PORT, BRAND, AREA, TIKOR, WARNA FROM odp";
$result_odp = $conn->query($sql_odp);

$odpMarkers = [];
if ($result_odp && $result_odp->num_rows > 0) {
    while ($row = $result_odp->fetch_assoc()) {
        $tikor = str_replace(' ', '', trim($row['TIKOR'] ?? ''));
        
        // Skip if TIKOR is empty or invalid
        if (empty($tikor) || strpos($tikor, ',') === false) {
            continue;
        }
        
        // Parse coordinates
        $coords = explode(',', $tikor);
        if (count($coords) < 2 || !is_numeric($coords[0]) || !is_numeric($coords[1])) {
            continue;
        }
        
        $lat_odp = floatval($coords[0]);
        $lng_odp = floatval($coords[1]);
        
        // Validate latitude and longitude ranges
        if ($lat_odp < -90 || $lat_odp > 90 || $lng_odp < -180 || $lng_odp > 180) {
            continue;
        }
        
        $popup = "<b>ODP:</b> " . htmlspecialchars($row['NAME']) . "<br>" .
                "<b>Kode:</b> " . htmlspecialchars($row['KODE']) . "<br>" .
                "<b>Port:</b> " . htmlspecialchars($row['PORT']) . "<br>" .
                "<b>Brand:</b> " . htmlspecialchars($row['BRAND']) . "<br>" .
                "<b>Area:</b> " . htmlspecialchars($row['AREA']) . "<br>" .
                "<b>Koordinat:</b> " . $lat_odp . ", " . $lng_odp;
        
        $odpMarkers[] = [
            'id' => $row['ID'],
            'name' => $row['NAME'],
            'kode' => $row['KODE'],
            'port' => $row['PORT'],
            'brand' => $row['BRAND'],
            'area' => $row['AREA'],
            'lat' => floatval($lat_odp),
            'lng' => floatval($lng_odp),
            'color' => !empty($row['WARNA']) ? $row['WARNA'] : '#f39c12',
            'popup' => $popup
        ];
    }
}

// Coverage check if coordinates provided
$coverage_result = null;
if ($lat !== null && $lng !== null) {
    if ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) {
        $coverage_result = [
            'user_lat' => floatval($lat),
            'user_lng' => floatval($lng),
            'coverage_radius' => 200, // meters
            'nearest_odp' => null,
            'is_covered' => false,
            'distance_meters' => null
        ];
        
        // Find nearest ODP
        $min_distance = PHP_INT_MAX;
        foreach ($odpMarkers as $odp) {
            $distance = haversineDistance($lat, $lng, $odp['lat'], $odp['lng']);
            if ($distance < $min_distance) {
                $min_distance = $distance;
                $coverage_result['nearest_odp'] = [
                    'id' => $odp['id'],
                    'name' => $odp['name'],
                    'kode' => $odp['kode'],
                    'port' => $odp['port'],
                    'brand' => $odp['brand'],
                    'area' => $odp['area'],
                    'lat' => $odp['lat'],
                    'lng' => $odp['lng'],
                    'popup' => $odp['popup']
                ];
                $coverage_result['distance_meters'] = round($distance);
            }
        }
        
        // Check if covered (within 200m)
        if ($coverage_result['nearest_odp'] && $coverage_result['distance_meters'] <= 200) {
            $coverage_result['is_covered'] = true;
        }
    }
}

// Return response
echo json_encode([
    'success' => true,
    'data' => [
        'markers' => $odpMarkers,
        'total_markers' => count($odpMarkers),
        'center' => [ -6.2088, 106.8456 ], // Jakarta center
        'zoom' => 12,
        'coverage_radius' => 200
    ],
    'coverage' => $coverage_result
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
