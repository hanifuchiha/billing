<?php
require 'cek-sesi.php';

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
    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>\n";
    $xml .= "<worksheet xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\">\n";
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

// WAJIB scoping kepemilikan -- tanpa ini, assistant/reseller manapun bisa
// export SEMUA pelanggan dari SEMUA area/server (bukan cuma miliknya) hanya
// dengan klik tombol "Export Pelanggan (Excel)" tanpa parameter apapun, atau
// ganti parameter ?area= ke area orang lain. Pola sama persis dgn scoping
// resmi di tables.php (baris ~5036-5053).
$currentUserId = isset($current_user_id) ? (int)$current_user_id : 0;
$whereParts = [];

if ($AKSES === 'ASSISTANT') {
    if (!isset($area_list) || trim((string)$area_list) === '') {
        die('Area assistant tidak ditemukan.');
    }
    $whereParts[] = "AREA IN ($area_list)";
} elseif ($AKSES !== 'ADMIN') {
    $ownedPemilik = [];
    $qOwn = mysqli_query($conn, "SELECT PEMILIK FROM server WHERE user_id = " . $currentUserId);
    if ($qOwn) {
        while ($r = mysqli_fetch_assoc($qOwn)) {
            $p = trim((string)($r['PEMILIK'] ?? ''));
            if ($p !== '') {
                $ownedPemilik[] = "'" . mysqli_real_escape_string($conn, $p) . "'";
            }
        }
    }
    $ownedPemilikList = count($ownedPemilik) > 0 ? implode(',', $ownedPemilik) : "''";
    $whereParts[] = "PEMILIK IN ($ownedPemilikList)";
}
// ADMIN: tanpa batasan tambahan (whereParts dari blok di atas tetap kosong).

$selected_area = isset($_GET['area']) ? trim($_GET['area']) : '';
$bindTypes = '';
$bindValues = [];
if ($selected_area !== '') {
    // Sub-filter opsional ?area= tetap di-AND-kan dgn scoping wajib di atas,
    // jadi assistant yang coba kirim area di luar $area_list miliknya cukup
    // dapat 0 baris (bukan bocor data area lain).
    $whereParts[] = "AREA = ?";
    $bindTypes .= 's';
    $bindValues[] = $selected_area;
}

$sql = "SELECT * FROM pelanggan";
if (!empty($whereParts)) {
    $sql .= " WHERE " . implode(' AND ', $whereParts);
}
$sql .= " ORDER BY IDPEL";

if ($bindTypes !== '') {
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, $bindTypes, ...$bindValues);
    mysqli_stmt_execute($stmt);
    $query = mysqli_stmt_get_result($stmt);
} else {
    $query = mysqli_query($conn, $sql);
}

$rows = [];
$rows[] = [
    'PASSWORD', 'IDPEL', 'NIK', 'NAMA', 'TIPE_BAYAR', 'TIPE_TEMPO', 'PAKET', 'HARGA',
    'ALAMAT', 'NOWA', 'TANGGALPASANG', 'EMAIL', 'TEMPO', 'MODE', 'ODP',
    'PEMILIK', 'AREA', 'TIKOR', 'sales', 'BRAND', 'PROVINSI',
    'KABUPATEN', 'KECAMATAN', 'KELURAHAN', 'RW', 'RT'
];

while ($row = mysqli_fetch_assoc($query)) {
    // Reseller/mitra ISP dengan filter harga aktif: harga yang di-export harus
    // ikut harga custom mereka, bukan harga asli tersimpan -- pola sama persis
    // dgn Transaction.php/customertransation.php.
    $hargaExport = $row['HARGA'] ?? '';
    if (!empty($is_reseller) && !empty($reseller_price_filter_enabled) && !empty($row['PAKET']) && !empty($row['PEMILIK'])) {
        $effectiveHarga = reseller_effective_harga($conn, $row['PAKET'], $row['PEMILIK']);
        if ($effectiveHarga > 0) {
            $hargaExport = $effectiveHarga;
        }
    }
    $rows[] = [
        $row['PASSWORD'] ?? '',
        $row['IDPEL'] ?? '',
        $row['NIK'] ?? '',
        $row['NAMA'] ?? '',
        $row['TIPE_BAYAR'] ?? '',
        $row['TIPE_TEMPO'] ?? '',
        $row['PAKET'] ?? '',
        $hargaExport,
        $row['ALAMAT'] ?? '',
        $row['NOWA'] ?? '',
        $row['TANGGALPASANG'] ?? '',
        $row['EMAIL'] ?? '',
        $row['TEMPO'] ?? '',
        $row['MODE'] ?? '',
        $row['ODP'] ?? '',
        $row['PEMILIK'] ?? '',
        $row['AREA'] ?? '',
        $row['TIKOR'] ?? '',
        $row['sales'] ?? '',
        $row['BRAND'] ?? '',
        $row['provinsi'] ?? '',
        $row['kabupaten'] ?? '',
        $row['kecamatan'] ?? '',
        $row['kelurahan'] ?? '',
        $row['rw'] ?? '',
        $row['rt'] ?? ''
    ];
}

// Build XLSX package
$tempDir = sys_get_temp_dir() . '/xlsx_export_pelanggan_' . uniqid();
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
    <dc:title>Export Pelanggan</dc:title>
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
        <sheet name="Pelanggan" sheetId="1" r:id="rId1"/>
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

$filename = 'pelanggan_export_' . date('Ymd_His') . '.xlsx';
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

?>
