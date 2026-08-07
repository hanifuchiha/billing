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

function buildOLTTemplateSheetXml($rows) {
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

// Sheet 1: Import Template
$headers = [
    'IP + PORT',
    'OLT NAME',
    'Brand',
    'Username',
    'Password',
    'Product',
    'Area',
    'Community Read',
    'Community Write'
];

$example = [
    '192.168.11.1:80',
    'OLT-DEPOK-01',
    'HIOSO EPON',
    'admin',
    'admin123',
    'Jayanet',
    'Depok',
    '(opsional)',
    '(opsional)'
];

$notes = [
    'Format IP:PORT (contoh: 192.168.1.1:80)',
    'Nama unik untuk OLT',
    'HIOSO EPON, ZTE GPON C300/320, atau CDATA GPON',
    'Username untuk login OLT',
    'Password untuk login OLT',
    'Pilih dari daftar Product (Sheet 2)',
    'Area sesuai dengan Product yang dipilih',
    'Hanya untuk CDATA GPON, kosongkan untuk lainnya',
    'Hanya untuk CDATA GPON, kosongkan untuk lainnya'
];

// Sheet 2: Server-Area Reference
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

$tempDir = sys_get_temp_dir() . '/excel_olt_' . uniqid();
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

file_put_contents($tempDir . '/xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>
</Relationships>');

file_put_contents($tempDir . '/xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <workbookPr date1904="false"/>
    <sheets>
        <sheet name="OLT Template" sheetId="1" r:id="rId1"/>
        <sheet name="Server-Area Reference" sheetId="2" r:id="rId2"/>
    </sheets>
</workbook>');

// Sheet 1: Import Template dengan instruksi
$templateData = [];
// Header
$templateData[] = $headers;
// Example
$templateData[] = $example;
// Notes
$templateData[] = $notes;
// Empty row
$templateData[] = ['', '', '', '', '', '', '', '', ''];
// Instructions
$templateData[] = ['PETUNJUK PENGGUNAAN:'];
$templateData[] = ['1. Isi kolom IP + PORT dengan format: 192.168.1.1 atau 192.168.1.1:80'];
$templateData[] = ['2. OLT NAME harus unik untuk setiap device'];
$templateData[] = ['3. Brand harus salah satu dari: HIOSO EPON, ZTE GPON C300/320, CDATA GPON'];
$templateData[] = ['4. Product harus ada di sheet "Server-Area Reference"'];
$templateData[] = ['5. Area harus sesuai dengan Product yang dipilih'];
$templateData[] = ['6. Community Read & Write hanya untuk brand CDATA GPON'];

file_put_contents($tempDir . '/xl/worksheets/sheet1.xml', buildOLTTemplateSheetXml($templateData));

// Sheet 2: Server-Area Reference
file_put_contents($tempDir . '/xl/worksheets/sheet2.xml', buildOLTTemplateSheetXml($serverAreaRows));

file_put_contents($tempDir . '/docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/officeDocument/2006/custom-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <dc:creator>QTS System</dc:creator>
    <dc:title>OLT Import Template</dc:title>
    <dcterms:created xsi:type="dcterms:W3CDTF">' . date('Y-m-d\TH:i:s\Z') . '</dcterms:created>
</cp:coreProperties>');

file_put_contents($tempDir . '/docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties">
    <TotalTime>0</TotalTime>
    <Application>QTS</Application>
</Properties>');

// Create ZIP file
$zipPath = $tempDir . '.zip';
$zip = new ZipArchive();
$zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tempDir));
foreach ($files as $file) {
    if (is_file($file)) {
        $relativePath = substr($file, strlen($tempDir) + 1);
        $zip->addFile($file, $relativePath);
    }
}
$zip->close();

// Download file
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="template_olt_' . date('YmdHis') . '.xlsx"');
header('Content-Length: ' . filesize($zipPath));
readfile($zipPath);

// Cleanup
@unlink($zipPath);
@array_map('unlink', glob($tempDir . '/docProps/*'));
@rmdir($tempDir . '/docProps');
@array_map('unlink', glob($tempDir . '/xl/worksheets/*'));
@rmdir($tempDir . '/xl/worksheets');
@array_map('unlink', glob($tempDir . '/xl/_rels/*'));
@rmdir($tempDir . '/xl/_rels');
@array_map('unlink', glob($tempDir . '/xl/*'));
@rmdir($tempDir . '/xl');
@array_map('unlink', glob($tempDir . '/_rels/*'));
@rmdir($tempDir . '/_rels');
@array_map('unlink', glob($tempDir . '/*'));
@rmdir($tempDir);

exit;
?>
