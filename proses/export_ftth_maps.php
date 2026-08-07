<?php
require '../cek-sesi.php';

function getExcelColumnName($index) {
    $name = '';
    $index = (int)$index;
    do {
        $name = chr(($index % 26) + 65) . $name;
        $index = (int)floor($index / 26) - 1;
    } while ($index >= 0);
    return $name;
}

function buildWorksheetXml($rows) {
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n';
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">\n';
    $xml .= '<sheetData>';

    foreach ($rows as $rowIndex => $row) {
        $excelRow = $rowIndex + 1;
        $xml .= '<row r="' . $excelRow . '">';
        foreach ($row as $colIndex => $cellValue) {
            $col = getExcelColumnName($colIndex);
            $value = htmlspecialchars((string)$cellValue, ENT_QUOTES | ENT_XML1, 'UTF-8');
            $xml .= '<c r="' . $col . $excelRow . '" t="inlineStr"><is><t>' . $value . '</t></is></c>';
        }
        $xml .= '</row>';
    }

    $xml .= '</sheetData></worksheet>';
    return $xml;
}

$ownerEsc = mysqli_real_escape_string($conn, $ceknama);

$cablesRows = [[
    'id', 'name', 'type', 'core_count', 'fiber_type', 'status', 'color', 'length_m', 'photo', 'geom', 'owner', 'created_at'
]];
$cableQuery = mysqli_query($conn, "SELECT id, name, geom, attributes, length, owner, created_at FROM cables WHERE owner = '$ownerEsc' ORDER BY id DESC");
if ($cableQuery) {
    while ($row = mysqli_fetch_assoc($cableQuery)) {
        $attr = json_decode($row['attributes'] ?? '{}', true);
        if (!is_array($attr)) {
            $attr = [];
        }
        $cablesRows[] = [
            (string)($row['id'] ?? ''),
            (string)($row['name'] ?? ''),
            (string)($attr['type'] ?? ''),
            (string)($attr['core_count'] ?? ''),
            (string)($attr['fiber_type'] ?? ''),
            (string)($attr['status'] ?? ''),
            (string)($attr['color'] ?? ''),
            (string)($row['length'] ?? ''),
            (string)($attr['photo'] ?? ''),
            (string)($row['geom'] ?? ''),
            (string)($row['owner'] ?? ''),
            (string)($row['created_at'] ?? ''),
        ];
    }
}

$assetsRows = [[
    'id', 'id_asset', 'type', 'name', 'area', 'brand', 'pemilik', 'capacity', 'serial', 'notes', 'icon', 'color', 'photo', 'hirarki', 'geom', 'owner', 'created_at'
]];
$assetQuery = mysqli_query($conn, "SELECT id, id_asset, type, geom, attributes, owner, created_at FROM assets WHERE owner = '$ownerEsc' ORDER BY id DESC");
if ($assetQuery) {
    while ($row = mysqli_fetch_assoc($assetQuery)) {
        $attr = json_decode($row['attributes'] ?? '{}', true);
        if (!is_array($attr)) {
            $attr = [];
        }
        $assetsRows[] = [
            (string)($row['id'] ?? ''),
            (string)($row['id_asset'] ?? ''),
            (string)($row['type'] ?? ''),
            (string)($attr['name'] ?? ''),
            (string)($attr['area'] ?? ''),
            (string)($attr['brand'] ?? ''),
            (string)($attr['pemilik'] ?? ''),
            (string)($attr['capacity'] ?? ''),
            (string)($attr['serial'] ?? ''),
            (string)($attr['notes'] ?? ''),
            (string)($attr['icon'] ?? ''),
            (string)($attr['color'] ?? ''),
            (string)($attr['photo'] ?? ''),
            (string)($attr['hirarki'] ?? ''),
            (string)($row['geom'] ?? ''),
            (string)($row['owner'] ?? ''),
            (string)($row['created_at'] ?? ''),
        ];
    }
}

$tempDir = sys_get_temp_dir() . '/xlsx_export_ftth_maps_' . uniqid();
@mkdir($tempDir);
@mkdir($tempDir . '/_rels');
@mkdir($tempDir . '/docProps');
@mkdir($tempDir . '/xl');
@mkdir($tempDir . '/xl/_rels');
@mkdir($tempDir . '/xl/worksheets');

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

file_put_contents($tempDir . '/_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>
    <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>
</Relationships>');

file_put_contents($tempDir . '/docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <dc:title>Export FTTH Maps</dc:title>
    <dc:creator>QTS Export System</dc:creator>
    <dcterms:created xsi:type="dcterms:W3CDTF">' . date('c') . '</dcterms:created>
</cp:coreProperties>');

file_put_contents($tempDir . '/docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">
    <Application>QTS Export</Application>
</Properties>');

file_put_contents($tempDir . '/xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <sheets>
        <sheet name="Cables" sheetId="1" r:id="rId1"/>
        <sheet name="Assets" sheetId="2" r:id="rId2"/>
    </sheets>
</workbook>');

file_put_contents($tempDir . '/xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>
</Relationships>');

file_put_contents($tempDir . '/xl/worksheets/sheet1.xml', buildWorksheetXml($cablesRows));
file_put_contents($tempDir . '/xl/worksheets/sheet2.xml', buildWorksheetXml($assetsRows));

$zipFile = $tempDir . '.xlsx';
$zip = new ZipArchive();
if ($zip->open($zipFile, ZipArchive::CREATE) !== TRUE) {
    die('Gagal membuat file XLSX export.');
}

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);

foreach ($files as $file) {
    if (!$file->isDir()) {
        $filePath = $file->getRealPath();
        $relativePath = substr($filePath, strlen($tempDir) + 1);
        $zip->addFile($filePath, $relativePath);
    }
}
$zip->close();

$filename = 'ftth_maps_' . date('Ymd_His') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename=' . $filename);
header('Cache-Control: max-age=0');

readfile($zipFile);

@unlink($zipFile);
$cleanup = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($cleanup as $item) {
    $item->isDir() ? @rmdir($item->getRealPath()) : @unlink($item->getRealPath());
}
@rmdir($tempDir);

exit;
