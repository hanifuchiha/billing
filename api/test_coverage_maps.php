<?php
// Test coverage_maps.php API response
require_once '../koneksibilling.php';

$pemilik = 'FIBERQ';

// Get ODP data
$sql = "SELECT ID, NAME, KODE, PORT, BRAND, AREA, TIKOR FROM odp WHERE PEMILIK = ? LIMIT 10";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $pemilik);
$stmt->execute();
$result = $stmt->get_result();

echo "Total ODP records for $pemilik: " . $result->num_rows . "\n";
echo "=====================================\n";

$count = 0;
while ($row = $result->fetch_assoc()) {
    $count++;
    $tikor = str_replace(' ', '', trim($row['TIKOR'] ?? ''));
    
    echo "\nRecord $count: {$row['KODE']} - {$row['NAME']}\n";
    echo "  TIKOR (raw): " . ($row['TIKOR'] ?? 'NULL') . "\n";
    echo "  TIKOR (trimmed): $tikor\n";
    
    if (empty($tikor)) {
        echo "  ❌ TIKOR is empty\n";
        continue;
    }
    
    if (strpos($tikor, ',') === false) {
        echo "  ❌ TIKOR has no comma\n";
        continue;
    }
    
    $coords = explode(',', $tikor);
    echo "  Coords array: " . json_encode($coords) . "\n";
    
    if (count($coords) < 2) {
        echo "  ❌ Not enough coordinates\n";
        continue;
    }
    
    if (!is_numeric($coords[0]) || !is_numeric($coords[1])) {
        echo "  ❌ Coordinates are not numeric\n";
        continue;
    }
    
    $lat = floatval($coords[0]);
    $lng = floatval($coords[1]);
    
    echo "  Lat: $lat, Lng: $lng\n";
    
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        echo "  ❌ Coordinates out of range\n";
        continue;
    }
    
    echo "  ✅ VALID MARKER\n";
}

echo "\n=====================================\n";
echo "Summary:\n";
$result->data_seek(0);
$total = $result->num_rows;
$valid = 0;

while ($row = $result->fetch_assoc()) {
    $tikor = str_replace(' ', '', trim($row['TIKOR'] ?? ''));
    if (!empty($tikor) && strpos($tikor, ',') !== false) {
        $coords = explode(',', $tikor);
        if (count($coords) >= 2 && is_numeric($coords[0]) && is_numeric($coords[1])) {
            $lat = floatval($coords[0]);
            $lng = floatval($coords[1]);
            if ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) {
                $valid++;
            }
        }
    }
}

echo "Total ODP: $total\n";
echo "Valid markers: $valid\n";
echo "Invalid: " . ($total - $valid) . "\n";
?>
