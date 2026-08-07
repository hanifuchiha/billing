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

$pemilikEsc = mysqli_real_escape_string($conn, $ceknama);
$sql = "SELECT pool_name, ipawal, ipakhir, iplocal, pemilik FROM pool WHERE pemilik = '$pemilikEsc' ORDER BY id DESC";
$query = mysqli_query($conn, $sql);

$rows = [];
$rows[] = ['pool_name', 'ipawal', 'ipakhir', 'iplocal', 'pemilik'];
while ($data = mysqli_fetch_assoc($query)) {
    $rows[] = [
        $data['pool_name'],
        $data['ipawal'],
        $data['ipakhir'],
        $data['iplocal'],
        $data['pemilik'],
    ];
}

$tempDir = sys_get_temp_dir() . '/xlsx_export_pool_' . uniqid();
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
    <dc:title>Export IP Pool</dc:title>
    <dc:creator>QTS Export System</dc:creator>
    <dcterms:created xsi:type="dcterms:W3CDTF>' . date('c') . '</dcterms:created>
</cp:coreProperties>');

file_put_contents($tempDir . '/docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">
    <Application>QTS Export</Application>
</Properties>');

file_put_contents($tempDir . '/xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <sheets>
        <sheet name="IP Pool" sheetId="1" r:id="rId1"/>
    </sheets>
</workbook>');

file_put_contents($tempDir . '/xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
</Relationships>');

file_put_contents($tempDir . '/xl/worksheets/sheet1.xml', buildWorksheetXml($rows));

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

$filename = 'ip_pool_' . date('Ymd_His') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename=' . $filename);
header('Cache-Control: max-age=0');

$history_file = '../notifbot/data/history-' . $ceknama . '.json';
$history = [];
if (file_exists($history_file)) {
    $history = json_decode(file_get_contents($history_file), true);
}
if (!is_array($history)) {
    $history = [];
}
$history[] = '[ ' . (!empty($asistant_name) ? $asistant_name : $ceknama) . ' - ' . date('Y-m-d H:i:s') . ' ] Export IP Pool';
file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

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
