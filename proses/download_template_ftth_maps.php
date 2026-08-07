<?php
require_once __DIR__ . '/../cek-sesi.php';

function getExcelColumnName($index) {
    $name = '';
    $index = (int)$index;
    do {
        $name = chr(($index % 26) + 65) . $name;
        $index = (int)floor($index / 26) - 1;
    } while ($index >= 0);
    return $name;
}

function buildTemplateSheetXml($rows) {
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n';
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    foreach ($rows as $rowIndex => $row) {
        $excelRow = $rowIndex + 1;
        $xml .= '<row r="' . $excelRow . '">';
        foreach ($row as $colIndex => $cell) {
            $col = getExcelColumnName($colIndex);
            $xml .= '<c r="' . $col . $excelRow . '" t="inlineStr"><is><t>' . htmlspecialchars((string)$cell, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</t></is></c>';
        }
        $xml .= '</row>';
    }
    $xml .= '</sheetData></worksheet>';
    return $xml;
}

// Build server-area reference sheet (Sheet 2)
$current_user_id = isset($_SESSION['id']) ? (int)$_SESSION['id'] : 0;
$serverAreaRows = [['PEMILIK', 'BRAND', 'AREA', 'IP']];

if (isset($AKSES) && $AKSES === 'ASSISTANT') {
    $sqlServer = "SELECT PEMILIK, BRAND, AREA, IP FROM server WHERE AREA IN ($area_list) ORDER BY BRAND, AREA";
} elseif ($current_user_id > 0) {
    $sqlServer = "SELECT PEMILIK, BRAND, AREA, IP FROM server WHERE user_id = $current_user_id ORDER BY BRAND, AREA";
} elseif (!empty($ceknama)) {
    $sqlServer = "SELECT PEMILIK, BRAND, AREA, IP FROM server WHERE PEMILIK = '" . mysqli_real_escape_string($conn, $ceknama) . "' ORDER BY BRAND, AREA";
} else {
    $sqlServer = "SELECT PEMILIK, BRAND, AREA, IP FROM server WHERE 1=0";
}
$serverResult = mysqli_query($conn, $sqlServer);
if ($serverResult) {
    while ($srv = mysqli_fetch_assoc($serverResult)) {
        $serverAreaRows[] = [
            (string)($srv['PEMILIK'] ?? ''),
            (string)($srv['BRAND'] ?? ''),
            (string)($srv['AREA'] ?? ''),
            (string)($srv['IP'] ?? ''),
        ];
    }
}

// Build template data (Sheet 1)
$templateRows = [];

// CABLES section
$templateRows[] = ['=== CABLES DATA ==='];
$cableHeaders = ['name', 'type', 'core_count', 'fiber_type', 'status', 'color', 'length_m', 'photo'];
$templateRows[] = $cableHeaders;

$cableExample = ['Kabel-ODP-001', 'Single Mode', '12', 'G.657A', 'Active', 'Yellow', '150', ''];
$templateRows[] = $cableExample;

$cableNotes = [
    'Nama kabel (unik per owner)',
    'Tipe kabel (Single Mode / Multi Mode / dll)',
    'Jumlah core',
    'Tipe fiber (G.652 / G.657A / dll)',
    'Status (Active / Inactive / Testing)',
    'Warna kabel',
    'Panjang dalam meter',
    'Path/URL foto'
];
$templateRows[] = $cableNotes;

// Spacing
$templateRows[] = [];

// ASSETS section
$templateRows[] = ['=== ASSETS DATA ==='];
$assetHeaders = ['id_asset', 'type', 'name', 'area', 'brand', 'pemilik', 'capacity', 'serial', 'notes', 'icon', 'color', 'photo', 'hirarki'];
$templateRows[] = $assetHeaders;

$assetExample = ['SPLITTER-ODP-001', 'Splitter', 'Splitter ODP Area 01', 'AREA01', 'COMMSCOPE', $ceknama, '1:32', 'SPLITTER-20250101-001', 'Splitter 32 way', 'splitter', 'Blue', '', 'ODP'];
$templateRows[] = $assetExample;

$assetNotes = [
    'ID aset (unik per owner)',
    'Tipe aset (Splitter / Joint Closure / Drop Splitter / dll)',
    'Nama/Deskripsi aset',
    'Area lokasi',
    'Brand manufaktur',
    'Pemilik/Server (akan diisi otomatis)',
    'Kapasitas (contoh: 1:32)',
    'Serial number',
    'Catatan tambahan',
    'Icon/type untuk peta',
    'Warna untuk visualisasi',
    'Path/URL foto',
    'Hirarki (ODP / Joint / Client)'
];
$templateRows[] = $assetNotes;

// Create temp directory
$tempDir = sys_get_temp_dir() . '/excel_template_ftth_' . uniqid();
@mkdir($tempDir);
@mkdir($tempDir . '/_rels');
@mkdir($tempDir . '/docProps');
@mkdir($tempDir . '/xl');
@mkdir($tempDir . '/xl/_rels');
@mkdir($tempDir . '/xl/worksheets');

// Create [Content_Types].xml
file_put_contents($tempDir . '/[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
    <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
    <Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
    <Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>
    <Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>
</Types>');

// Create _rels/.rels
file_put_contents($tempDir . '/_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>
    <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>
</Relationships>');

// Create docProps/core.xml
file_put_contents($tempDir . '/docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <dc:title>Template Import FTTH Maps</dc:title>
    <dc:creator>QTS Import System</dc:creator>
    <dcterms:created xsi:type="dcterms:W3CDTF">' . date('c') . '</dcterms:created>
</cp:coreProperties>');

// Create docProps/app.xml
file_put_contents($tempDir . '/docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">
    <Application>QTS Template Generator</Application>
</Properties>');

// Create xl/workbook.xml
file_put_contents($tempDir . '/xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <sheets>
        <sheet name="Template FTTH" sheetId="1" r:id="rId1"/>
        <sheet name="Server Area" sheetId="2" r:id="rId2"/>
    </sheets>
</workbook>');

// Create xl/_rels/workbook.xml.rels
file_put_contents($tempDir . '/xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>
</Relationships>');

// Create sheet1.xml (template)
file_put_contents($tempDir . '/xl/worksheets/sheet1.xml', buildTemplateSheetXml($templateRows));

// Create sheet2.xml (server-area reference)
file_put_contents($tempDir . '/xl/worksheets/sheet2.xml', buildTemplateSheetXml($serverAreaRows));

// Create XLSX file via ZipArchive
$zipFile = $tempDir . '.xlsx';
$zip = new ZipArchive();
if ($zip->open($zipFile, ZipArchive::CREATE) !== TRUE) {
    http_response_code(500);
    echo 'Gagal membuat file template.';
    exit;
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($iterator as $item) {
    $relativePath = str_replace($tempDir . DIRECTORY_SEPARATOR, '', $item);
    if (!$item->isDir()) {
        $zip->addFile($item, $relativePath);
    }
}

$zip->close();

// Send file
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="template_ftth_maps_' . date('Ymd') . '.xlsx"');
header('Content-Length: ' . filesize($zipFile));
readfile($zipFile);

// Cleanup
@unlink($zipFile);
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($files as $fileInfo) {
    if ($fileInfo->isDir()) {
        @rmdir($fileInfo->getRealPath());
    } else {
        @unlink($fileInfo->getRealPath());
    }
}
@rmdir($tempDir);
?>
