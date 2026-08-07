<?php
// Disable output buffering dan error display untuk export
ob_start();
ini_set('display_errors', 0);
error_reporting(0);

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

try {
    // Build query dengan filtering berdasarkan role
    $where_conditions = [];
    
    $current_user_id = isset($_SESSION['id']) ? (int)$_SESSION['id'] : 0;
    
    if ($AKSES === 'ASSISTANT') {
        // ASSISTANT: filter by area list
        $where_conditions[] = "`area` IN ($area_list)";
    } else {
        // Non-ASSISTANT: filter by owner (pemilik dari server yang dimiliki user)
        $queryServerId = mysqli_query($conn, "SELECT PEMILIK FROM server WHERE user_id = $current_user_id");
        $userServerIds = [];
        while ($row = mysqli_fetch_assoc($queryServerId)) {
            $userServerIds[] = "'" . mysqli_real_escape_string($conn, $row['PEMILIK']) . "'";
        }
        $userServerList = count($userServerIds) > 0 ? implode(",", $userServerIds) : "''";
        $where_conditions[] = "`pemilik` IN ($userServerList)";
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    $sql = "SELECT id, ipolt, oltname, pemilik, area, usernameolt, passwordolt, brandolt, community_read, community_write FROM olt WHERE $where_clause ORDER BY area, oltname";
    
    $query = mysqli_query($conn, $sql);
    if (!$query) {
        throw new Exception('Error database: ' . mysqli_error($conn));
    }
    
    $olts = [];
    while ($data = mysqli_fetch_assoc($query)) {
        $olts[] = $data;
    }
    
    if (empty($olts)) {
        throw new Exception('Tidak ada data OLT yang ditemukan');
    }
    
    // Siapkan data untuk XLSX
    $rows = [];
    
    // Header sesuai dengan template import
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
    
    $rows[] = $headers;
    
    // Data dari database
    foreach ($olts as $olt) {
        $row = [
            $olt['ipolt'] ?? '',
            $olt['oltname'] ?? '',
            $olt['brandolt'] ?? '',
            $olt['usernameolt'] ?? '',
            $olt['passwordolt'] ?? '',
            $olt['pemilik'] ?? '',
            $olt['area'] ?? '',
            $olt['community_read'] ?? '',
            $olt['community_write'] ?? ''
        ];
        $rows[] = $row;
    }
    
    // Buat temporary directory untuk XLSX
    $tempDir = sys_get_temp_dir() . '/excel_olt_export_' . uniqid();
    @mkdir($tempDir);
    @mkdir($tempDir . '/_rels');
    @mkdir($tempDir . '/docProps');
    @mkdir($tempDir . '/xl');
    @mkdir($tempDir . '/xl/_rels');
    @mkdir($tempDir . '/xl/worksheets');
    
    // Create XLSX structure files
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
    
    file_put_contents($tempDir . '/xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
</Relationships>');
    
    file_put_contents($tempDir . '/xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <workbookPr date1904="false"/>
    <sheets>
        <sheet name="OLT Data" sheetId="1" r:id="rId1"/>
    </sheets>
</workbook>');
    
    file_put_contents($tempDir . '/xl/worksheets/sheet1.xml', buildWorksheetXml($rows));
    
    file_put_contents($tempDir . '/docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/officeDocument/2006/custom-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <dc:creator>' . htmlspecialchars($ceknama, ENT_XML1) . '</dc:creator>
    <dc:title>OLT Export</dc:title>
    <dcterms:created xsi:type="dcterms:W3CDTF">' . date('Y-m-d\TH:i:s\Z') . '</dcterms:created>
</cp:coreProperties>');
    
    file_put_contents($tempDir . '/docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties">
    <TotalTime>0</TotalTime>
    <Application>QTS</Application>
</Properties>');
    
    // Create ZIP file (XLSX)
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
    
    // Clear output buffer
    ob_end_clean();
    
    // Download file
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="olt_' . date('YmdHis') . '.xlsx"');
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

} catch (Exception $e) {
    ob_end_clean();
    header('Content-Type: text/html; charset=utf-8');
    echo '<html><body><h3>Error Export OLT</h3>';
    echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<p><a href="olt.php">Kembali ke OLT</a></p>';
    echo '</body></html>';
}

exit;
?>
