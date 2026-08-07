<?php
// Disable output buffering and error display untuk export
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
    // Ambil parameter dari form
    $format = 'xlsx';
    $include_password = isset($_POST['include_password']);
    $include_stats = isset($_POST['include_stats']);
    $filter_type = $_POST['filter_type'] ?? 'all';
    $filter_area = $_POST['filter_area'] ?? '';
    $filter_brand = $_POST['filter_brand'] ?? '';
    
 // Ambil semua server yang user_id-nya sama dengan current_user_id
            $queryServerId = mysqli_query($conn, "SELECT PEMILIK FROM server WHERE user_id = $current_user_id");
            $userServerIds = [];
            while($row = mysqli_fetch_assoc($queryServerId)) {
              $userServerIds[] = "'".$row['PEMILIK']."'";
            }
            $userServerList = count($userServerIds) > 0 ? implode(",", $userServerIds) : "''";



    // Build query dengan filter berdasarkan tipe
    $where_conditions = [];
    $where_conditions[] = "`pemilik` IN ($userServerList)";
    
    if ($AKSES === 'ASSISTANT') {
        $where_conditions[] = "`AREA` IN ($area_list)";
    }
    
    // Apply filter berdasarkan pilihan user
    if ($filter_type === 'area' && !empty($filter_area)) {
        $where_conditions[] = "`AREA` = '" . mysqli_real_escape_string($conn, $filter_area) . "'";
    } elseif ($filter_type === 'brand' && !empty($filter_brand)) {
        $where_conditions[] = "`BRAND` = '" . mysqli_real_escape_string($conn, $filter_brand) . "'";
    }
    // Jika filter_type === 'all', tidak ada filter tambahan
    
    $where_clause = implode(' AND ', $where_conditions);
    $sql = "SELECT * FROM server WHERE $where_clause ORDER BY AREA, BRAND, IP";
    
    $query = mysqli_query($conn, $sql);
    if (!$query) {
        throw new Exception('Error database: ' . mysqli_error($conn));
    }
    
    $servers = [];
    while ($data = mysqli_fetch_array($query)) {
        $servers[] = $data;
    }
    
    if (empty($servers)) {
        throw new Exception('Tidak ada data server yang ditemukan dengan filter yang dipilih');
    }
    
    // Siapkan data baris untuk XLSX
    $rows = [];
    
    // Header sesuai dengan template import
    $headers = [
        'Brand',
        'Area', 
        'IP Address',
        'API Port',
        'Web Port',
        'Username',
        'Password'
    ];
    
    // Tambahkan kolom tambahan jika diminta
    if ($include_stats) {
        $headers[] = 'Status';
        $headers[] = 'Last Check';
        $headers[] = 'Export Date';
        $headers[] = 'Export By';
    }
    
    $rows[] = $headers;
    
    // Data sesuai format template import
    foreach($servers as $server) {
        // Split IP dan Port
        $ipParts = explode(':', $server['IP']);
        $ip = $ipParts[0];
        $apiPort = isset($ipParts[1]) ? $ipParts[1] : '8728';
        $webPort = $server['MIK80'] ? $server['MIK80'] : '80';
        
        // Row sesuai dengan template import
        $row = [
            $server['BRAND'] ?: '',           // Brand
            $server['AREA'] ?: '',            // Area
            $ip,                              // IP Address
            $apiPort,                         // API Port
            $webPort,                         // Web Port
            $server['PEMILIK'],               // Username
        ];
        
        // Password - terlihat jika dicentang
        if ($include_password) {
            $row[] = $server['PASSWORD'];     // Password asli jika dicentang
        } else {
            $row[] = '***';                   // Password tersembunyi jika tidak dicentang
        }
        
        // Tambahkan kolom stats jika diminta
        if ($include_stats) {
            $row[] = 'Active';                // Status
            $row[] = date('Y-m-d H:i:s');     // Last Check
            $row[] = date('Y-m-d H:i:s');     // Export Date
            $row[] = $ceknama ?: 'System';    // Export By
        }
        
        $rows[] = $row;
    }

    // Build XLSX
    $tempDir = sys_get_temp_dir() . '/xlsx_export_server_' . uniqid();
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
    <dc:title>Export Server</dc:title>
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
        <sheet name="Server Export" sheetId="1" r:id="rId1"/>
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
        throw new Exception('Gagal membuat file XLSX export.');
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

    $filename = 'server_export_' . date('Y-m-d_H-i-s') . '.xlsx';
    
    // Log aktivitas export
    $log_msg = "Export server data: " . count($servers) . " records, Format: $format";
    
    if ($filter_type === 'area' && !empty($filter_area)) {
        $log_msg .= ", Filter: Area '$filter_area'";
    } elseif ($filter_type === 'brand' && !empty($filter_brand)) {
        $log_msg .= ", Filter: Brand '$filter_brand'";
    } else {
        $log_msg .= ", Filter: All Data";
    }
    
    if ($include_password) $log_msg .= " [WITH PASSWORD]";
    if ($include_stats) $log_msg .= " [WITH STATS]";
    
    error_log("EXPORT: $log_msg by " . ($ceknama ?: 'System'));
    
    // log history
    $history_file = "../notifbot/data/history-$ceknama.json";
    $history = [];
    if (file_exists($history_file)) {
        $history = json_decode(file_get_contents($history_file), true);
    }
    if (!is_array($history)) { $history = []; }
    $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Export server data";
    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    
    // Download file
    if (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
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
    
} catch (Exception $e) {
    // Clear output buffer jika ada
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    error_log("Export Error: " . $e->getMessage());
    
    // Redirect dengan error
    $error_msg = urlencode($e->getMessage());
    header("Location: ../export_server.php?status=error&msg=$error_msg");
    exit;
}
?>