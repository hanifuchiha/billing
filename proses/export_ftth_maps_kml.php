<?php
require '../cek-sesi.php';

$ceknama = isset($ceknama) ? (string)$ceknama : 'unknown';
$asistant_name = isset($asistant_name) ? (string)$asistant_name : '';

$format = strtolower(trim($_GET['format'] ?? 'kml'));
$filter = strtolower(trim($_GET['filter'] ?? 'all'));

if (!in_array($format, ['kml', 'kmz'], true)) {
    $format = 'kml';
}

if (!in_array($filter, ['all', 'odp', 'odc', 'cable'], true)) {
    $filter = 'all';
}

$ownerEsc = mysqli_real_escape_string($conn, $ceknama);

function xml_text($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function normalize_text_utf8($value)
{
    if (!is_string($value)) {
        return $value;
    }

    if (preg_match('//u', $value)) {
        return $value;
    }

    if (function_exists('mb_convert_encoding')) {
        return mb_convert_encoding($value, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252, ASCII');
    }

    if (function_exists('iconv')) {
        $converted = @iconv('Windows-1252', 'UTF-8//IGNORE', $value);
        if ($converted !== false) {
            return $converted;
        }
    }

    return utf8_encode($value);
}

function asset_matches_filter(array $attributes, $filter)
{
    if ($filter === 'all') {
        return true;
    }

    if ($filter === 'cable') {
        return false;
    }

    $hirarki = strtoupper(trim((string)($attributes['hirarki'] ?? '')));
    $icon = strtolower(trim((string)($attributes['icon'] ?? '')));

    if ($filter === 'odp') {
        return $hirarki === 'ODP' || $icon === 'odp';
    }

    if ($filter === 'odc') {
        return $hirarki === 'ODC' || $icon === 'odc';
    }

    return true;
}

function build_point_placemark(array $attributes, array $coordinates)
{
    if (count($coordinates) < 2) {
        return '';
    }

    $lng = $coordinates[0];
    $lat = $coordinates[1];
    if (!is_numeric($lng) || !is_numeric($lat)) {
        return '';
    }

    $name = normalize_text_utf8((string)($attributes['id_asset'] ?? $attributes['name'] ?? 'Asset'));
    $descriptionLines = [];
    foreach (['type' => 'Type', 'name' => 'Name', 'area' => 'Area', 'brand' => 'Brand', 'pemilik' => 'Pemilik', 'hirarki' => 'Hirarki'] as $key => $label) {
        $value = trim((string)($attributes[$key] ?? ''));
        if ($value !== '') {
            $descriptionLines[] = $label . ': ' . normalize_text_utf8($value);
        }
    }

    $placemark = [];
    $placemark[] = '<Placemark>';
    $placemark[] = '<name>' . xml_text($name) . '</name>';
    if ($descriptionLines) {
        $placemark[] = '<description><![CDATA[' . implode('<br>', array_map('xml_text', $descriptionLines)) . ']]></description>';
    }
    $placemark[] = '<Point>';
    $placemark[] = '<coordinates>' . $lng . ',' . $lat . ',0</coordinates>';
    $placemark[] = '</Point>';
    $placemark[] = '</Placemark>';

    return implode("\n", $placemark);
}

function build_line_placemark($name, array $attributes, array $coordinates)
{
    if (!$coordinates) {
        return '';
    }

    $validCoordinates = [];
    foreach ($coordinates as $coordinate) {
        if (!is_array($coordinate) || count($coordinate) < 2) {
            continue;
        }
        $lng = $coordinate[0];
        $lat = $coordinate[1];
        if (!is_numeric($lng) || !is_numeric($lat)) {
            continue;
        }
        $validCoordinates[] = $lng . ',' . $lat . ',0';
    }

    if (count($validCoordinates) < 2) {
        return '';
    }

    $safeName = normalize_text_utf8((string)$name);
    $descriptionLines = [];
    foreach (['type' => 'Type', 'core_count' => 'Core Count', 'fiber_type' => 'Fiber Type', 'status' => 'Status'] as $key => $label) {
        $value = trim((string)($attributes[$key] ?? ''));
        if ($value !== '') {
            $descriptionLines[] = $label . ': ' . normalize_text_utf8($value);
        }
    }

    $placemark = [];
    $placemark[] = '<Placemark>';
    $placemark[] = '<name>' . xml_text($safeName) . '</name>';
    if ($descriptionLines) {
        $placemark[] = '<description><![CDATA[' . implode('<br>', array_map('xml_text', $descriptionLines)) . ']]></description>';
    }
    $placemark[] = '<LineString>';
    $placemark[] = '<tessellate>1</tessellate>';
    $placemark[] = '<coordinates>' . implode(' ', $validCoordinates) . '</coordinates>';
    $placemark[] = '</LineString>';
    $placemark[] = '</Placemark>';

    return implode("\n", $placemark);
}

$placemarks = [];

if ($filter !== 'cable') {
    $assetQuery = mysqli_query($conn, "SELECT id_asset, geom, attributes FROM assets WHERE owner = '$ownerEsc' ORDER BY id DESC");
    if (!$assetQuery) {
        die('DB error assets: ' . mysqli_error($conn));
    }

    while ($row = mysqli_fetch_assoc($assetQuery)) {
        $attributes = json_decode($row['attributes'] ?? '{}', true);
        $geom = json_decode($row['geom'] ?? '{}', true);
        if (!is_array($attributes) || !is_array($geom)) {
            continue;
        }
        if (!asset_matches_filter($attributes, $filter)) {
            continue;
        }
        if (($geom['type'] ?? '') !== 'Point' || !isset($geom['coordinates']) || !is_array($geom['coordinates'])) {
            continue;
        }

        $placemark = build_point_placemark($attributes, $geom['coordinates']);
        if ($placemark !== '') {
            $placemarks[] = $placemark;
        }
    }
}

if ($filter === 'all' || $filter === 'cable') {
    $cableQuery = mysqli_query($conn, "SELECT name, geom, attributes FROM cables WHERE owner = '$ownerEsc' ORDER BY id DESC");
    if (!$cableQuery) {
        die('DB error cables: ' . mysqli_error($conn));
    }

    while ($row = mysqli_fetch_assoc($cableQuery)) {
        $attributes = json_decode($row['attributes'] ?? '{}', true);
        $geom = json_decode($row['geom'] ?? '{}', true);
        if (!is_array($attributes) || !is_array($geom)) {
            continue;
        }
        if (($geom['type'] ?? '') !== 'LineString' || !isset($geom['coordinates']) || !is_array($geom['coordinates'])) {
            continue;
        }

        $placemark = build_line_placemark((string)($row['name'] ?? 'Cable'), $attributes, $geom['coordinates']);
        if ($placemark !== '') {
            $placemarks[] = $placemark;
        }
    }
}

$titleMap = [
    'all' => 'FTTH Maps - Semua',
    'odp' => 'FTTH Maps - ODP',
    'odc' => 'FTTH Maps - ODC',
    'cable' => 'FTTH Maps - Kabel',
];
$documentTitle = $titleMap[$filter] ?? 'FTTH Maps Export';

$kmlParts = [];
$kmlParts[] = '<?xml version="1.0" encoding="UTF-8"?>';
$kmlParts[] = '<kml xmlns="http://www.opengis.net/kml/2.2">';
$kmlParts[] = '<Document>';
$kmlParts[] = '<name>' . xml_text($documentTitle) . '</name>';
foreach ($placemarks as $placemark) {
    $kmlParts[] = $placemark;
}
$kmlParts[] = '</Document>';
$kmlParts[] = '</kml>';

$kmlText = implode("\n", $kmlParts);
$baseName = 'ftth_maps_' . $filter . '_' . date('Ymd_His');

$historyFile = '../notifbot/data/history-' . $ceknama . '.json';
$history = [];
if (file_exists($historyFile)) {
    $history = json_decode(file_get_contents($historyFile), true);
}
if (!is_array($history)) {
    $history = [];
}
$history[] = '[ ' . (!empty($asistant_name) ? $asistant_name : $ceknama) . ' - ' . date('Y-m-d H:i:s') . ' ] Export FTTH Maps ' . strtoupper($format) . ' (' . $filter . ')';
@file_put_contents($historyFile, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

if ($format === 'kmz') {
    if (!class_exists('ZipArchive')) {
        die('ZipArchive tidak tersedia. Gunakan format KML.');
    }

    $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ftth_maps_kmz_' . uniqid();
    @mkdir($tempDir, 0777, true);
    $kmlPath = $tempDir . DIRECTORY_SEPARATOR . 'doc.kml';
    file_put_contents($kmlPath, $kmlText);

    $kmzPath = $tempDir . DIRECTORY_SEPARATOR . $baseName . '.kmz';
    $zip = new ZipArchive();
    if ($zip->open($kmzPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        die('Gagal membuat file KMZ.');
    }
    $zip->addFile($kmlPath, 'doc.kml');
    $zip->close();

    header('Content-Type: application/vnd.google-earth.kmz');
    header('Content-Disposition: attachment; filename=' . $baseName . '.kmz');
    header('Cache-Control: max-age=0');
    readfile($kmzPath);

    @unlink($kmlPath);
    @unlink($kmzPath);
    @rmdir($tempDir);
    exit;
}

header('Content-Type: application/vnd.google-earth.kml+xml; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $baseName . '.kml');
header('Cache-Control: max-age=0');

echo $kmlText;
exit;