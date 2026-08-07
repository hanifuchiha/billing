<?php
// API: Check Coverage Status
// Given user coordinates (lat, lng), returns nearest ODP and coverage status
// Sebelumnya cuma dukung username+password -- tidak pernah baca param
// `key`/`api_key`, PADAHAL settingsapi.php mendokumentasikan resmi akses via
// API key. Diganti ke _bootstrap.php::api_authenticate() (session ->
// username+password -> API key dari tabel `apikey`) sama seperti api/odp.php dkk.
require_once '../koneksibilling.php';
require_once '_bootstrap.php';
session_start();
api_cors();

$input = api_read_input();
$lat = isset($_GET['lat']) ? floatval($_GET['lat']) : null;
$lng = isset($_GET['lng']) ? floatval($_GET['lng']) : null;

$auth = api_authenticate($conn, $input);
$pemilik = $auth['pemilik'];
if ($auth['method'] === 'apikey') {
    api_rate_limit($conn, $auth['api_key']);
}

// Validate coordinates
if ($lat === null || $lng === null || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Koordinat tidak valid']);
    exit;
}

// Haversine distance calculation
function haversineDistance($lat1, $lon1, $lat2, $lon2) {
    $R = 6371e3; // Earth radius in meters
    $φ1 = $lat1 * M_PI / 180;
    $φ2 = $lat2 * M_PI / 180;
    $Δφ = ($lat2 - $lat1) * M_PI / 180;
    $Δλ = ($lon2 - $lon1) * M_PI / 180;
    $a = sin($Δφ / 2) ** 2 + cos($φ1) * cos($φ2) * sin($Δλ / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $R * $c;
}

// Get all ODP markers
$sql = "SELECT NAME, KODE, PORT, BRAND, AREA, TIKOR FROM odp WHERE TIKOR IS NOT NULL AND TIKOR != ''";
$result = $conn->query($sql);

$nearest = null;
$minDistance = PHP_INT_MAX;

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $tikor = str_replace(' ', '', trim($row['TIKOR']));
        
        if (empty($tikor) || strpos($tikor, ',') === false) {
            continue;
        }
        
        $coords = explode(',', $tikor);
        if (count($coords) < 2) {
            continue;
        }
        
        $odpLat = floatval(trim($coords[0]));
        $odpLng = floatval(trim($coords[1]));
        
        if ($odpLat < -90 || $odpLat > 90 || $odpLng < -180 || $odpLng > 180) {
            continue;
        }
        
        $distance = haversineDistance($lat, $lng, $odpLat, $odpLng);
        
        if ($distance < $minDistance) {
            $minDistance = $distance;
            $nearest = [
                'name' => $row['NAME'] ?? '',
                'kode' => $row['KODE'] ?? '',
                'port' => $row['PORT'] ?? '',
                'brand' => $row['BRAND'] ?? '',
                'area' => $row['AREA'] ?? '',
                'lat' => $odpLat,
                'lng' => $odpLng,
                'distance' => round($distance),
                'popup' => 
                    "<b>ODP:</b> " . htmlspecialchars($row['NAME']) . "<br>" .
                    "<b>Kode:</b> " . htmlspecialchars($row['KODE']) . "<br>" .
                    "<b>Port:</b> " . htmlspecialchars($row['PORT']) . "<br>" .
                    "<b>Server Area:</b> " . htmlspecialchars($row['BRAND']) . "<br>" .
                    "<b>Area:</b> " . htmlspecialchars($row['AREA']) . "<br>" .
                    "<b>Koordinat:</b> " . $odpLat . ", " . $odpLng . "<br>" .
                    "<b>Jarak:</b> " . round($distance) . " meter"
            ];
        }
    }
}

// Check coverage status (within 200 meters)
$isCovered = false;
if ($nearest && $nearest['distance'] <= 200) {
    $isCovered = true;
}

// Return response
echo json_encode([
    'success' => true,
    'data' => [
        'user_lat' => $lat,
        'user_lng' => $lng,
        'is_covered' => $isCovered,
        'nearest_odp' => $nearest,
        'coverage_radius' => 200
    ]
], JSON_UNESCAPED_SLASHES);
?>
