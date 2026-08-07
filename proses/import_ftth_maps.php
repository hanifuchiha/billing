<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../cek-sesi.php';
require_once __DIR__ . '/../libs/SimpleXLSX.php';

function redirectImport($status, $message) {
    $url = '../ftth_maps.php?statusnotif=' . urlencode($status) . '&text=' . urlencode($message);
    header('Location: ' . $url);
    exit;
}

if (
    $_SERVER['REQUEST_METHOD'] !== 'POST' ||
    !isset($_FILES['excel_file']) ||
    !isset($_FILES['excel_file']['tmp_name']) ||
    !is_uploaded_file($_FILES['excel_file']['tmp_name']) ||
    $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK
) {
    redirectImport('failed', 'Tidak ada file yang diupload atau upload gagal.');
}

$fileTmp = $_FILES['excel_file']['tmp_name'];
$fileName = $_FILES['excel_file']['name'];
$ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

if ($ext !== 'xlsx') {
    redirectImport('failed', 'File harus berformat .xlsx');
}

$xlsx = SimpleXLSX::parse($fileTmp);
if (!$xlsx) {
    redirectImport('failed', 'Gagal membaca file Excel: ' . SimpleXLSX::parseError());
}

$skipHeader = isset($_POST['skip_first_row']) && $_POST['skip_first_row'] == 'on';

$cableSuccessCount = 0;
$cableFailCount = 0;
$assetSuccessCount = 0;
$assetFailCount = 0;
$failMessages = [];

// ===== PROCESS CABLES SHEET (Sheet 0) =====
$cableRows = $xlsx->rows(0); // First sheet for cables
if ($skipHeader && count($cableRows) > 0) {
    array_shift($cableRows);
}

foreach ($cableRows as $i => $row) {
    if (empty(array_filter($row, function ($v) {
        return trim((string)$v) !== '';
    }))) {
        continue;
    }

    $lineNum = $skipHeader ? $i + 2 : $i + 1;
    
    $name = trim((string)($row[0] ?? ''));
    $type = trim((string)($row[1] ?? ''));
    $coreCount = trim((string)($row[2] ?? ''));
    $fiberType = trim((string)($row[3] ?? ''));
    $status = trim((string)($row[4] ?? ''));
    $color = trim((string)($row[5] ?? ''));
    $lengthM = trim((string)($row[6] ?? ''));
    $photo = trim((string)($row[7] ?? ''));

    // Validate required fields
    $missing = [];
    if ($name === '') {
        $missing[] = 'name';
    }
    if ($type === '') {
        $missing[] = 'type';
    }
    if ($coreCount === '') {
        $missing[] = 'core_count';
    }
    if ($fiberType === '') {
        $missing[] = 'fiber_type';
    }

    if (!empty($missing)) {
        $cableFailCount++;
        $failMessages[] = 'Cable baris ' . $lineNum . ': Field kosong: ' . implode(', ', $missing);
        continue;
    }

    // Check for duplicates
    $nameEsc = mysqli_real_escape_string($conn, $name);
    $checkDup = mysqli_query($conn, "SELECT id FROM cables WHERE name = '$nameEsc' AND owner = '" . mysqli_real_escape_string($conn, $ceknama) . "'");
    if (mysqli_num_rows($checkDup) > 0) {
        $cableFailCount++;
        $failMessages[] = 'Cable baris ' . $lineNum . ': Data dengan nama "' . $name . '" sudah ada.';
        continue;
    }

    // Prepare attributes JSON
    $attributes = [
        'type' => $type,
        'core_count' => $coreCount,
        'fiber_type' => $fiberType,
        'status' => $status,
        'color' => $color,
        'photo' => $photo
    ];

    $length = !empty($lengthM) ? floatval($lengthM) : 0;
    $attrJson = json_encode($attributes);
    $attrEsc = mysqli_real_escape_string($conn, $attrJson);
    $ceknamaEsc = mysqli_real_escape_string($conn, $ceknama);

    $insertCable = mysqli_query($conn, 
        "INSERT INTO cables (name, attributes, length, owner) VALUES ('$nameEsc', '$attrEsc', $length, '$ceknamaEsc')"
    );

    if ($insertCable) {
        $cableSuccessCount++;
    } else {
        $cableFailCount++;
        $failMessages[] = 'Cable baris ' . $lineNum . ': Insert gagal - ' . mysqli_error($conn);
    }
}

// ===== PROCESS ASSETS SHEET (Sheet 1) =====
$assetRows = $xlsx->rows(1); // Second sheet for assets
if ($skipHeader && count($assetRows) > 0) {
    array_shift($assetRows);
}

foreach ($assetRows as $i => $row) {
    if (empty(array_filter($row, function ($v) {
        return trim((string)$v) !== '';
    }))) {
        continue;
    }

    $lineNum = $skipHeader ? $i + 2 : $i + 1;

    $idAsset = trim((string)($row[0] ?? ''));
    $type = trim((string)($row[1] ?? ''));
    $name = trim((string)($row[2] ?? ''));
    $area = trim((string)($row[3] ?? ''));
    $brand = trim((string)($row[4] ?? ''));
    $pemilik = trim((string)($row[5] ?? ''));
    $capacity = trim((string)($row[6] ?? ''));
    $serial = trim((string)($row[7] ?? ''));
    $notes = trim((string)($row[8] ?? ''));
    $icon = trim((string)($row[9] ?? ''));
    $color = trim((string)($row[10] ?? ''));
    $photo = trim((string)($row[11] ?? ''));
    $hirarki = trim((string)($row[12] ?? ''));

    // Validate required fields
    $missing = [];
    if ($idAsset === '') {
        $missing[] = 'id_asset';
    }
    if ($type === '') {
        $missing[] = 'type';
    }
    if ($name === '') {
        $missing[] = 'name';
    }
    if ($area === '') {
        $missing[] = 'area';
    }

    if (!empty($missing)) {
        $assetFailCount++;
        $failMessages[] = 'Asset baris ' . $lineNum . ': Field kosong: ' . implode(', ', $missing);
        continue;
    }

    // Check for duplicates
    $idAssetEsc = mysqli_real_escape_string($conn, $idAsset);
    $checkDup = mysqli_query($conn, "SELECT id FROM assets WHERE id_asset = '$idAssetEsc' AND owner = '" . mysqli_real_escape_string($conn, $ceknama) . "'");
    if (mysqli_num_rows($checkDup) > 0) {
        $assetFailCount++;
        $failMessages[] = 'Asset baris ' . $lineNum . ': Data dengan id_asset "' . $idAsset . '" sudah ada.';
        continue;
    }

    // Prepare attributes JSON
    $attributes = [
        'name' => $name,
        'area' => $area,
        'brand' => $brand,
        'pemilik' => $pemilik,
        'capacity' => $capacity,
        'serial' => $serial,
        'notes' => $notes,
        'icon' => $icon,
        'color' => $color,
        'photo' => $photo,
        'hirarki' => $hirarki
    ];

    $attrJson = json_encode($attributes);
    $attrEsc = mysqli_real_escape_string($conn, $attrJson);
    $typeEsc = mysqli_real_escape_string($conn, $type);
    $ceknamaEsc = mysqli_real_escape_string($conn, $ceknama);

    $insertAsset = mysqli_query($conn,
        "INSERT INTO assets (id_asset, type, attributes, owner) VALUES ('$idAssetEsc', '$typeEsc', '$attrEsc', '$ceknamaEsc')"
    );

    if ($insertAsset) {
        $assetSuccessCount++;
    } else {
        $assetFailCount++;
        $failMessages[] = 'Asset baris ' . $lineNum . ': Insert gagal - ' . mysqli_error($conn);
    }
}

// Log to history
$logDir = __DIR__ . '/../../notifbot/data';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$historyFile = $logDir . '/history-' . $ceknama . '.json';
$historyData = [];
if (file_exists($historyFile)) {
    $content = file_get_contents($historyFile);
    $historyData = json_decode($content, true) ?: [];
}
$historyData[] = [
    'timestamp' => date('Y-m-d H:i:s'),
    'action' => 'import_ftth_maps',
    'cables_success' => $cableSuccessCount,
    'cables_failed' => $cableFailCount,
    'assets_success' => $assetSuccessCount,
    'assets_failed' => $assetFailCount,
    'total_fail_messages' => count($failMessages)
];
file_put_contents($historyFile, json_encode($historyData, JSON_PRETTY_PRINT));

// Determine final status
$totalSuccess = $cableSuccessCount + $assetSuccessCount;
$totalFail = $cableFailCount + $assetFailCount;

if ($totalSuccess > 0 && $totalFail === 0) {
    $status = 'success';
    $message = 'Cables: ' . $cableSuccessCount . ' berhasil. Assets: ' . $assetSuccessCount . ' berhasil.';
} elseif ($totalSuccess > 0 && $totalFail > 0) {
    $status = 'partial';
    $message = 'Cables: ' . $cableSuccessCount . ' berhasil, ' . $cableFailCount . ' gagal. Assets: ' . $assetSuccessCount . ' berhasil, ' . $assetFailCount . ' gagal.';
    if (!empty($failMessages)) {
        $message .= ' Detail: ' . implode(' | ', array_slice($failMessages, 0, 5));
    }
} else {
    $status = 'failed';
    $message = 'Semua data gagal diimport. ' . (count($failMessages) > 0 ? $failMessages[0] : 'Silakan periksa format file.');
}

redirectImport($status, $message);
?>
