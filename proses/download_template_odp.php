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

function buildServerAreaSheetXml($rows) {
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

$headers = [
    'KODE',
    'NAME',
    'TIKOR',
    'PEMILIK',
    'AREA',
    'HIRARKI',
    'SPLITTER',
    'HIRARKI_PARENT'
];

$example = [
    'ODP-001',
    'ODP JALUR UTAMA',
    '-6.200000,106.816666',
    'SERVER_A',
    'AREA01',
    'ODP',
    '1:8',
    'ODC-001'
];

$notes = [
    'Hapus baris contoh dan isi data sendiri',
    'Nama ODP/ODC',
    'Format latitude,longitude',
    'PEMILIK server',
    'Area server',
    'ODC/ODP/ODP-RASIO/ODP-JUMPER',
    'Opsional: 1:2/1:4/1:8/1:16/1:32',
    'Opsional: isi jika butuh parent'
];

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

$tempDir = sys_get_temp_dir() . '/excel_odp_' . uniqid();
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
    <dc:title>Template ODP Import</dc:title>
    <dc:creator>QTS Import System</dc:creator>
    <dcterms:created xsi:type="dcterms:W3CDTF">' . date('c') . '</dcterms:created>
</cp:coreProperties>');

file_put_contents($tempDir . '/docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">
    <Application>QTS Template Generator</Application>
</Properties>');

file_put_contents($tempDir . '/xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <sheets>
        <sheet name="Template ODP" sheetId="1" r:id="rId1"/>
        <sheet name="Server Area" sheetId="2" r:id="rId2"/>
    </sheets>
</workbook>');

file_put_contents($tempDir . '/xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>
</Relationships>');

$worksheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
    <sheetData>';

$worksheetXml .= '<row r="1">';
for ($i = 0; $i < count($headers); $i++) {
    $col = getExcelColumnName($i);
    $worksheetXml .= '<c r="' . $col . '1" t="inlineStr"><is><t>' . htmlspecialchars($headers[$i]) . '</t></is></c>';
}
$worksheetXml .= '</row>';

$worksheetXml .= '<row r="2">';
for ($i = 0; $i < count($example); $i++) {
    $col = getExcelColumnName($i);
    $worksheetXml .= '<c r="' . $col . '2" t="inlineStr"><is><t>' . htmlspecialchars($example[$i]) . '</t></is></c>';
}
$worksheetXml .= '</row>';

$worksheetXml .= '<row r="3">';
for ($i = 0; $i < count($notes); $i++) {
    $col = getExcelColumnName($i);
    $worksheetXml .= '<c r="' . $col . '3" t="inlineStr"><is><t>' . htmlspecialchars($notes[$i]) . '</t></is></c>';
}
$worksheetXml .= '</row>';

$worksheetXml .= '</sheetData></worksheet>';
file_put_contents($tempDir . '/xl/worksheets/sheet1.xml', $worksheetXml);
file_put_contents($tempDir . '/xl/worksheets/sheet2.xml', buildServerAreaSheetXml($serverAreaRows));

$zip = new ZipArchive();
$zipFile = $tempDir . '.xlsx';

if ($zip->open($zipFile, ZipArchive::CREATE) !== TRUE) {
    http_response_code(500);
    echo 'Gagal membuat file template.';
    exit;
}

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($tempDir),
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

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="template_odp_import.xlsx"');
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
