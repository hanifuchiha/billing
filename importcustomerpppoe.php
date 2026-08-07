<?php
/**
 * importcustomerpppoe.php
 * ----------------------------------------------------------------------
 * Import massal data pelanggan PPPoE dari file Excel, mengikuti pola
 * modal "Sinkron dari API" di getdata/api_hanif.php (Step1 config ->
 * Step2 preview & edit -> Step3 hasil), tapi sumber data adalah file
 * Excel yang diupload (sheet "Pelanggan" + "Transaksi"), bukan API
 * billing eksternal. Backend ada di getdata/import_excel_pelanggan.php.
 * ----------------------------------------------------------------------
 */



require_once __DIR__ . '/cek-sesi.php';

function getExcelColumnName($index)
{
    $name = '';
    $index = (int) $index;
    do {
        $name = chr(($index % 26) + 65) . $name;
        $index = (int) floor($index / 26) - 1;
    } while ($index >= 0);
    return $name;
}

function buildSimpleSheetXml($rows)
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    foreach ($rows as $rowIndex => $row) {
        $excelRow = $rowIndex + 1;
        $xml .= '<row r="' . $excelRow . '">';
        foreach ($row as $colIndex => $cell) {
            $col = getExcelColumnName($colIndex);
            $xml .= '<c r="' . $col . $excelRow . '" t="inlineStr"><is><t>' . htmlspecialchars((string) $cell, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</t></is></c>';
        }
        $xml .= '</row>';
    }
    $xml .= '</sheetData></worksheet>';
    return $xml;
}

/**
 * Bangun file .xlsx dari beberapa sheet sekaligus ($sheets = [['name'=>..,
 * 'rows'=>[[..]]], ...]). Mengembalikan path file .xlsx sementara (caller
 * yang bertanggung jawab readfile() + unlink()).
 */
function buildXlsxWorkbook(array $sheets): ?string
{
    $tempDir = sys_get_temp_dir() . '/excel_' . uniqid();
    mkdir($tempDir);
    mkdir($tempDir . '/_rels');
    mkdir($tempDir . '/docProps');
    mkdir($tempDir . '/xl');
    mkdir($tempDir . '/xl/_rels');
    mkdir($tempDir . '/xl/worksheets');

    $n = count($sheets);

    $overrides = '';
    for ($i = 1; $i <= $n; $i++) {
        $overrides .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }
    file_put_contents($tempDir . '/[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
    ' . $overrides . '
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
    <dc:title>Template Import Pelanggan</dc:title>
    <dc:creator>Import System</dc:creator>
    <dcterms:created xsi:type="dcterms:W3CDTF">' . date('c') . '</dcterms:created>
</cp:coreProperties>');

    file_put_contents($tempDir . '/docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">
    <Application>Template Generator</Application>
</Properties>');

    $sheetTags = '';
    for ($i = 1; $i <= $n; $i++) {
        $sheetTags .= '<sheet name="' . htmlspecialchars($sheets[$i - 1]['name'], ENT_QUOTES, 'UTF-8') . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>';
    }
    file_put_contents($tempDir . '/xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <sheets>' . $sheetTags . '</sheets>
</workbook>');

    $relTags = '';
    for ($i = 1; $i <= $n; $i++) {
        $relTags .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
    }
    file_put_contents($tempDir . '/xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $relTags . '</Relationships>');

    for ($i = 1; $i <= $n; $i++) {
        file_put_contents($tempDir . '/xl/worksheets/sheet' . $i . '.xml', buildSimpleSheetXml($sheets[$i - 1]['rows']));
    }

    $zip = new ZipArchive();
    $zipFile = $tempDir . '.xlsx';

    if ($zip->open($zipFile, ZipArchive::CREATE) !== true) {
        return null;
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

    // Bersihkan folder sumber, sisakan hanya file .xlsx hasil zip.
    $cleanupFiles = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tempDir),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($cleanupFiles as $file) {
        $file->isDir() ? @rmdir($file->getRealPath()) : @unlink($file->getRealPath());
    }
    @rmdir($tempDir);

    return $zipFile;
}

/**
 * Ambil daftar PEMILIK yang dimiliki akun saat ini (dipakai untuk scoping
 * query paket/odp seperti pola di packages.php), sudah ter-quote siap
 * pakai di WHERE IN (...).
 */
function ownedPemilikList($conn, $current_user_id)
{
    $list = [];
    $q = mysqli_query($conn, "SELECT DISTINCT PEMILIK FROM server WHERE user_id = " . (int) $current_user_id);
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $list[] = "'" . mysqli_real_escape_string($conn, $row['PEMILIK']) . "'";
        }
    }
    return empty($list) ? "''" : implode(',', $list);
}

function generateExcelTemplate($conn, $AKSES, $current_user_id, $area_list)
{
    $pelangganHeaders = [
        'IDPEL', 'PASSWORD', 'NIK', 'NAMA', 'TIPE_BAYAR', 'TIPE_TEMPO', 'PAKET', 'ALAMAT', 'NOWA',
        'TANGGALPASANG', 'EMAIL', 'ODP', 'TIKOR', 'SALES', 'PROVINSI', 'KABUPATEN', 'KECAMATAN', 'KELURAHAN', 'RW', 'RT'
    ];
    $pelangganExample = [
        'PEL001', 'password123', '3201010101980001', 'John Doe', 'prabayar', 'mengikuti_tanggal_tempo',
        'Paket Home 20Mbps', 'Jl. Merdeka No. 123, Jakarta', '628123456789', '2024-01-15', 'john@email.com',
        'ODP001', '-6.200000, 106.816666', 'none', 'Jawa Barat', 'Bogor', 'Cibinong', 'Pabuaran', '03', '01'
    ];
    $pelangganInstruction = [
        'ID unik / username PPPoE (WAJIB, tanpa spasi)',
        'Password PPPoE (opsional - kalau kosong, diisi di Step 2 saat import)',
        'NIK 16 digit (opsional)',
        'Nama lengkap (WAJIB)',
        'prabayar / pascabayar (opsional, kosongkan utk pakai default Step 1 - penjelasan lengkap di sheet "Tipe Tempo dan Tipe Bayar")',
        'mengikuti_tanggal_tempo / mengikuti_tanggal_bayar / monthversary (opsional, default Step 1 - penjelasan lengkap di sheet "Tipe Tempo dan Tipe Bayar")',
        'WAJIB - nama harus SAMA PERSIS dgn yg terdaftar di menu Paket (lihat sheet Paket PPPoE)',
        'Alamat lengkap (WAJIB)',
        'No WA format 628xxx (WAJIB)',
        'Format YYYY-MM-DD (WAJIB) - untuk mode tempo monthversary, tanggal ini juga jadi anchor tanggal jatuh tempo tetap pelanggan',
        'Email (opsional)',
        'Kode ODP (opsional, kosongkan utk pakai ODP default Step 1, lihat sheet ODP)',
        'Koordinat lat,lng (opsional)',
        'Nama sales (opsional, kosongkan utk pakai default Step 1)',
        'Opsional domisili', 'Opsional domisili', 'Opsional domisili', 'Opsional domisili', 'Opsional domisili', 'Opsional domisili'
    ];
    $pelangganRows = [$pelangganHeaders, $pelangganExample, $pelangganInstruction];

    // ---- Sheet: Transaksi Berhasil Terbaru ----
    // Setiap baris di sini akan otomatis dibuat sebagai 1 transaksi
    // BERSTATUS BERHASIL untuk IDPEL terkait saat proses import dijalankan.
    $transaksiHeaders = ['IDPEL', 'TANGGALBAYAR', 'NOMINAL', 'PERIODE', 'STATUS', 'METODE_BAYAR', 'KETERANGAN', 'BUKTI'];
    $transaksiExample = ['PEL001', '2024-02-15', '200000', 'Februari 2024', 'BERHASIL', 'Transfer BCA', 'Pembayaran Februari 2024', ''];
    // Catatan: kolom IDPEL baris instruksi/catatan SENGAJA diisi 'PEL001' (sama
    // seperti baris contoh) supaya ikut dilewati oleh proses import (baris
    // dengan IDPEL mengandung 'PEL001' otomatis diabaikan), bukan malah
    // dianggap transaksi "yatim" (IDPEL tidak ditemukan di sheet Pelanggan).
    $transaksiInstruction = [
        'PEL001',
        'Tanggal transaksi dibayar, format YYYY-MM-DD (WAJIB)',
        'Nominal yang dibayar, angka saja tanpa titik/koma/Rp (WAJIB)',
        'Periode/bulan penggunaan yang dibayar, contoh: Februari 2024 (opsional - kosongkan utk dihitung otomatis dari TANGGALBAYAR)',
        'BERHASIL / PENAGIHAN (opsional, kosongkan utk otomatis BERHASIL)',
        'Metode pembayaran, contoh: Transfer BCA, Tunai, QRIS, dst (opsional)',
        'Catatan/keterangan transaksi (opsional)',
        'Nomor bukti/referensi pembayaran (opsional, dibuat otomatis kalau kosong)'
    ];
    $transaksiNote = [
        'PEL001',
        'CATATAN: baris di sheet ini HANYA untuk transaksi yang SUDAH BERHASIL/LUNAS dibayar pelanggan (riwayat pembayaran).',
        'Dipakai sistem sebagai acuan tanggal transaksi terakhir/pertama pelanggan (mis. untuk mode tempo monthversary & tanggal aktivasi).',
        '', '', '', '', ''
    ];
    $transaksiRows = [$transaksiHeaders, $transaksiExample, $transaksiInstruction, $transaksiNote];

    // ---- Sheet referensi: Server Area ----
    $serverAreaRows = [
        ['PENJELASAN:', 'Daftar Server/Area (POP) yang SUDAH TERDAFTAR di billing Anda. Sheet ini hanya referensi, tidak diimport.'],
        ['', 'Gunakan nilai AREA di sini kalau butuh mencocokkan area pelanggan pada sheet Pelanggan/Paket PPPoE/ODP.'],
        [],
        ['PEMILIK', 'BRAND', 'AREA', 'IP'],
    ];
    if ($AKSES === 'ASSISTANT') {
        $sqlServer = "SELECT PEMILIK, BRAND, AREA, IP FROM server WHERE AREA IN ($area_list) ORDER BY BRAND, AREA";
    } elseif ($current_user_id > 0) {
        $sqlServer = "SELECT PEMILIK, BRAND, AREA, IP FROM server WHERE user_id = $current_user_id ORDER BY BRAND, AREA";
    } else {
        $sqlServer = "SELECT PEMILIK, BRAND, AREA, IP FROM server WHERE 1=0";
    }
    $resServer = mysqli_query($conn, $sqlServer);
    if ($resServer) {
        while ($srv = mysqli_fetch_assoc($resServer)) {
            $serverAreaRows[] = [(string) $srv['PEMILIK'], (string) $srv['BRAND'], (string) $srv['AREA'], (string) $srv['IP']];
        }
    }

    // ---- Sheet referensi: Paket PPPoE (dikelompokkan per AREA) ----
    $paketRows = [
        ['PENJELASAN:', 'Daftar Paket PPPoE yang Anda miliki, dikelompokkan per AREA. Sheet ini hanya referensi, tidak diimport.'],
        ['', 'Nilai kolom PAKET di sheet Pelanggan HARUS SAMA PERSIS (huruf besar/kecil tidak masalah) dengan salah satu nilai PAKET di bawah, sesuai AREA pelanggan tsb.'],
        [],
        ['AREA', 'PAKET', 'KECEPATAN', 'HARGA', 'PEMILIK'],
    ];
    if ($AKSES === 'ASSISTANT') {
        $sqlPaket = "SELECT PAKET, KECEPATAN, HARGA, AREA, PEMILIK FROM paket WHERE AREA IN ($area_list) ORDER BY AREA, PAKET";
    } else {
        $sqlPaket = "SELECT PAKET, KECEPATAN, HARGA, AREA, PEMILIK FROM paket WHERE PEMILIK IN (" . ownedPemilikList($conn, $current_user_id) . ") ORDER BY AREA, PAKET";
    }
    $resPaket = mysqli_query($conn, $sqlPaket);
    if ($resPaket) {
        while ($p = mysqli_fetch_assoc($resPaket)) {
            $paketRows[] = [(string) $p['AREA'], (string) $p['PAKET'], (string) $p['KECEPATAN'], (string) $p['HARGA'], (string) $p['PEMILIK']];
        }
    }

    // ---- Sheet referensi: ODP (dikelompokkan per AREA) ----
    $odpRows = [
        ['PENJELASAN:', 'Daftar ODP yang Anda miliki, dikelompokkan per AREA. Sheet ini hanya referensi, tidak diimport.'],
        ['', 'Kolom ODP di sheet Pelanggan (opsional) harus memakai salah satu KODE di bawah, sesuai AREA pelanggan tsb. Kosongkan kolom ODP kalau ingin pakai ODP default Step 1.'],
        [],
        ['AREA', 'KODE', 'NAME', 'PEMILIK'],
    ];
    if ($AKSES === 'ASSISTANT') {
        $sqlOdp = "SELECT KODE, NAME, AREA, PEMILIK FROM odp WHERE AREA IN ($area_list) ORDER BY AREA, KODE";
    } else {
        $sqlOdp = "SELECT KODE, NAME, AREA, PEMILIK FROM odp WHERE PEMILIK IN (" . ownedPemilikList($conn, $current_user_id) . ") ORDER BY AREA, KODE";
    }
    $resOdp = mysqli_query($conn, $sqlOdp);
    if ($resOdp) {
        while ($o = mysqli_fetch_assoc($resOdp)) {
            $odpRows[] = [(string) $o['AREA'], (string) $o['KODE'], (string) $o['NAME'], (string) $o['PEMILIK']];
        }
    }

    // ---- Sheet referensi: Tipe Tempo dan Tipe Bayar ----
    // Penjelasan lengkap semua pilihan TIPE_BAYAR & TIPE_TEMPO yang didukung
    // billing, bahasa sederhana untuk yang mengisi template (bukan bahasa teknis).
    $tipeTempoBayarRows = [
        ['KATEGORI', 'NILAI (diisi di kolom Excel)', 'PENJELASAN'],
        ['Tipe Bayar', 'prabayar', 'Pelanggan membayar DULU sebelum internetnya bisa dipakai.'],
        ['Tipe Bayar', 'pascabayar', 'Pelanggan memakai internet DULU, baru membayar di akhir/awal periode penggunaan.'],
        [],
        ['Tipe Tempo', 'mengikuti_tanggal_tempo', 'FIXED DUE DATE: SEMUA pelanggan jatuh tempo di TANGGAL YANG SAMA setiap bulan, sesuai 1 pengaturan tanggal tempo utama (Menu Notifikasi). Cocok kalau mau menagih semua pelanggan serentak.'],
        ['Tipe Tempo', 'mengikuti_tanggal_bayar', 'ROLLING DUE DATE (mengikuti tanggal aktifasi): jatuh tempo = 1 bulan setelah pelanggan TERAKHIR bayar. Kalau bayar tanggal 8, bulan depan jatuh tempo juga tanggal 8 - tanggalnya bisa ikut bergeser mengikuti kapan pelanggan bayar. WAJIB dipakai untuk pelanggan pascabayar.'],
        ['Tipe Tempo', 'monthversary', 'MONTHVERSARY DUE DATE (BARU): tiap pelanggan punya tanggal jatuh tempo SENDIRI yang TETAP, sesuai tanggal pertama kali pasang/aktif (kolom TANGGALPASANG). Contoh: pasang tanggal 10, jatuh tempo SELALU tanggal 10 tiap bulan, walau bayarnya kadang lebih cepat/telat. Berlaku untuk prabayar maupun pascabayar. Khusus prabayar ada waktu tunggu (grace period) sebelum internet diisolir kalau belum bayar, diatur di Menu Notifikasi > Waktu Tunggu Prabayar.'],
        [],
        ['Catatan', '', 'Kolom TIPE_BAYAR dan TIPE_TEMPO di sheet Pelanggan boleh DIKOSONGKAN kalau ingin memakai nilai default yang dipilih di Step 1 form import (berlaku untuk semua baris yang kosong).'],
    ];

    $sheets = [
        ['name' => 'Pelanggan', 'rows' => $pelangganRows],
        ['name' => 'Transaksi Berhasil Terbaru', 'rows' => $transaksiRows],
        ['name' => 'Server Area', 'rows' => $serverAreaRows],
        ['name' => 'Paket PPPoE', 'rows' => $paketRows],
        ['name' => 'ODP', 'rows' => $odpRows],
        ['name' => 'Tipe Tempo dan Tipe Bayar', 'rows' => $tipeTempoBayarRows],
    ];

    $zipFile = buildXlsxWorkbook($sheets);
    if (!$zipFile) {
        echo 'Error: Tidak dapat membuat file Excel.';
        return;
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="template_import_pelanggan.xlsx"');
    header('Cache-Control: max-age=0');
    readfile($zipFile);
    unlink($zipFile);
    exit;
}

// Handle download template SEBELUM include header.php (butuh header() mentah).
if (isset($_GET['action']) && $_GET['action'] === 'download_template') {
    generateExcelTemplate($conn, $AKSES, $current_user_id, $area_list ?? "''");
    exit;
}

/**
 * Pastikan kolom domisili tambahan sudah ada di tabel pelanggan (migrasi
 * ringan, sama seperti versi lama importcustomerpppoe.php).
 */
function ensurePelangganColumns($conn)
{
    $columns = [
        'provinsi' => "VARCHAR(100) DEFAULT ''",
        'kabupaten' => "VARCHAR(100) DEFAULT ''",
        'kecamatan' => "VARCHAR(100) DEFAULT ''",
        'kelurahan' => "VARCHAR(100) DEFAULT ''",
        'rw' => "VARCHAR(10) DEFAULT ''",
        'rt' => "VARCHAR(10) DEFAULT ''",
    ];
    foreach ($columns as $column => $type) {
        $check = mysqli_query($conn, "SHOW COLUMNS FROM pelanggan LIKE '$column'");
        if ($check && mysqli_num_rows($check) == 0) {
            mysqli_query($conn, "ALTER TABLE pelanggan ADD COLUMN $column $type");
        }
    }
}
ensurePelangganColumns($conn);

$canImport = isset($AKSES) && in_array($AKSES, ['ADMIN', 'ASSISTANT'], true);

// Dropdown Server Area (sama seperti api_hanif.php - PEMILIK/BRAND/AREA
// dipilih SEKALI, berlaku utk semua baris pelanggan di file yg diimport).
$importServerOptions = [];
if ($canImport && isset($current_user_id) && $current_user_id) {
    if ($AKSES === 'ASSISTANT') {
        $qServer = mysqli_query($conn, "SELECT DISTINCT PEMILIK, BRAND, AREA FROM server WHERE AREA IN ($area_list)");
    } else {
        $qServer = mysqli_query($conn, "SELECT DISTINCT PEMILIK, BRAND, AREA FROM server WHERE user_id = " . (int) $current_user_id);
    }
    if ($qServer) {
        while ($rowServer = mysqli_fetch_assoc($qServer)) {
            $importServerOptions[] = [
                'pemilik' => $rowServer['PEMILIK'] ?? '',
                'brand'   => $rowServer['BRAND'] ?? '',
                'area'    => $rowServer['AREA'] ?? '',
            ];
        }
    }
}

require 'header.php';
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="card mb-4">
                <div class="card-header pb-0">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <span>Import Data Pelanggan dari Excel</span>
                        <?php if ($canImport): ?>
                        <button type="button" class="btn btn-success btn-sm" id="btnImpOpenModal">
                            Import dari Excel
                        </button>
                        <?php endif; ?>
                    </div>
                    <br>
                </div>
                <div class="card-body px-3 pt-0 pb-3">

                    <?php if (!$canImport): ?>
                        <div class="alert alert-warning">Akun Anda tidak memiliki akses untuk melakukan import data pelanggan.</div>
                    <?php else: ?>

                    <div class="alert alert-info">
                        <strong>Sebelum import, pastikan data berikut sudah terdaftar di billing:</strong>
                        <ul class="mb-0">
                            <li><strong>Server Area</strong> (menu Server) - dipilih sekali di Step 1, berlaku untuk semua pelanggan dalam file yang sama.</li>
                            <li><strong>ODP</strong> (menu ODP) - dipakai sebagai default kalau kolom ODP di file kosong.</li>
                            <li><strong>Paket</strong> (menu Paket) - WAJIB, dipakai untuk mencocokkan kolom PAKET di file &amp; mengisi HARGA otomatis. Kalau ada PAKET di file yang belum terdaftar, sistem akan menampilkan form untuk membuatnya sebelum lanjut import.</li>
                        </ul>
                    </div>

                    <a href="?action=download_template" class="btn btn-outline-success mb-3">
                        <i class="fas fa-download"></i> Download Template Excel (.xlsx)
                    </a>

                    <div class="small text-muted">
                        Template berisi sheet <strong>Pelanggan</strong> (data pelanggan), <strong>Transaksi</strong>
                        (riwayat pembayaran per pelanggan, opsional), serta sheet referensi <strong>Server Area</strong>,
                        <strong>Paket</strong>, dan <strong>ODP</strong> supaya Anda bisa menyalin nilai yang sudah
                        benar-benar terdaftar di billing.
                    </div>

                    <?php endif; ?>

                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($canImport): ?>
<!-- ============================================================================
     MODAL: Import Data Pelanggan dari Excel
     ============================================================================ -->
<div class="modal fade" id="modalImportExcel" tabindex="-1" aria-labelledby="modalImportExcelLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">

      <div class="modal-header bg-success text-white">
        <h5 class="modal-title text-white" id="modalImportExcelLabel">Import Data Pelanggan dari Excel</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body">

        <!-- ====== STEP 1: Upload file + pengaturan default ====== -->
        <div id="impStep1">
            <div class="alert alert-info small mb-3">
                Pilih <b>Server / Area / ODP / Tipe Bayar / Tipe Tempo</b> default untuk SEMUA pelanggan
                di file yang akan diimport (ODP/Tipe Bayar/Tipe Tempo hanya dipakai kalau kolom
                terkait di file kosong). Password PPPoE &amp; Mode Auth diisi di Step 2, sama seperti
                sinkron dari API.
            </div>

            <div class="mb-3">
                <label class="form-label">File Excel <span class="text-danger">*</span></label>
                <input type="file" class="form-control" id="impExcelFile" accept=".xlsx,.xls">
                <div class="form-text">Gunakan template terbaru (tombol Download Template di halaman utama). Maks 10MB.</div>
            </div>

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Server Area <span class="text-danger">*</span></label>
                    <select required class="form-select" id="impServer">
                        <option value="">-- Pilih Server Area --</option>
                        <?php foreach ($importServerOptions as $opt): ?>
                            <option value="<?= htmlspecialchars($opt['pemilik'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-area="<?= htmlspecialchars($opt['area'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-brand="<?= htmlspecialchars($opt['brand'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($opt['brand'], ENT_QUOTES, 'UTF-8') ?>-<?= htmlspecialchars($opt['area'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Area (otomatis dari Server)</label>
                    <input type="text" class="form-control" id="impAreaDisplay" readonly>
                    <input type="hidden" id="impArea">
                    <input type="hidden" id="impBrand">
                </div>

                <div class="col-md-6">
                    <label class="form-label">ODP default <span class="text-danger">*</span></label>
                    <select required class="form-select" id="impOdp">
                        <option value="">-- Pilih Server dahulu --</option>
                    </select>
                    <small class="text-muted">Dipakai kalau kolom ODP di baris pelanggan kosong.</small>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Sales default (fallback jika kolom SALES kosong)</label>
                    <select class="form-select" id="impSales">
                        <option value="-">TANPA SALES</option>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Tipe Bayar default (fallback)</label>
                    <select class="form-select" id="impTipeBayar">
                        <option value="pascabayar" selected>Pasca Bayar</option>
                        <option value="prabayar">Prabayar</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Tipe Tempo default (fallback)</label>
                    <select class="form-select" id="impTipeTempo">
                        <option value="mengikuti_tanggal_tempo" selected>Fixed Due Date</option>
                        <option value="mengikuti_tanggal_bayar">Rolling Due Date</option>
                        <option value="monthversary">Monthversary Due Date</option>
                    </select>
                </div>
            </div>

            <div class="text-end mt-3">
                <button type="button" class="btn btn-primary" id="btnImpPreview">
                    <span class="btn-label">Cek Data dari File</span>
                    <span class="btn-loading d-none">
                        <span class="spinner-border spinner-border-sm me-2"></span>Memproses...
                    </span>
                </button>
            </div>
        </div>

        <!-- ====== STEP 2: Preview + konfirmasi ====== -->
        <div id="impStep2" class="d-none">
            <div id="impSummaryBox" class="alert alert-secondary py-2 mb-3"></div>
            <div id="impBlockedBox" class="alert alert-warning py-2 mb-3 d-none"></div>
            <div id="impRouterWarningBox" class="alert alert-warning py-2 mb-3 d-none"></div>

            <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                <div>
                    <input type="checkbox" id="impSelectAll" checked>
                    <label for="impSelectAll" class="ms-1">Pilih Semua</label>
                </div>

                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <select id="impBulkAuthMode" class="form-select form-select-sm" style="width:auto;">
                        <option value="API MODE">API MODE (MikroTik)</option>
                        <option value="RADIUS MODE">RADIUS MODE</option>
                        <option value="MULTI MODE" selected>MULTI MODE (Keduanya)</option>
                    </select>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="btnImpApplyBulkAuthMode">Terapkan Mode ke Semua</button>
                </div>

                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <input type="text" id="impBulkPassword" class="form-control form-control-sm" style="min-width:140px;font-size:12px;" placeholder="Password sama utk semua...">
                    <button type="button" class="btn btn-sm btn-outline-primary" id="btnImpApplyBulkPassword">Terapkan ke Semua</button>
                </div>

                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnImpBackToStep1">Ubah Pengaturan</button>
            </div>

            <div class="small text-muted mb-2">
                Kolom <b>Password PPPoE</b> <b class="text-danger">WAJIB diisi</b> untuk semua baris yang
                dipilih (dari kolom PASSWORD di file, atau diisi manual/massal di sini). Import akan
                <b>ditolak</b> jika masih ada baris terpilih dengan password kosong.
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-striped align-middle" id="impPreviewTable">
                    <thead class="table-dark">
                        <tr>
                            <th style="width:36px;"></th>
                            <th>IDPEL</th>
                            <th>Nama</th>
                            <th>WA</th>
                            <th>Paket</th>
                            <th>Harga</th>
                            <th>Tipe Bayar</th>
                            <th>Tipe Tempo</th>
                            <th>ODP</th>
                            <th>Sales</th>
                            <th>Tgl Pasang</th>
                            <th>Status MikroTik</th>
                            <th>Status RADIUS</th>
                            <th>Mode Auth</th>
                            <th>Password PPPoE</th>
                            <th>Jml Transaksi</th>
                            <th>Total Nominal</th>
                        </tr>
                    </thead>
                    <tbody id="impPreviewBody">
                        <tr><td colspan="17" class="text-center text-muted">Belum ada data.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ====== STEP 3: Hasil eksekusi ====== -->
        <div id="impResultBox" class="d-none mt-3"></div>

      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
        <button type="button" class="btn btn-success d-none" id="btnImpExecute">
            <span class="btn-label">Simpan Pelanggan Terpilih ke Database</span>
            <span class="btn-loading d-none">
                <span class="spinner-border spinner-border-sm me-2"></span>Menyimpan...
            </span>
        </button>
      </div>

    </div>
  </div>
</div>

<!-- ============================================================================
     MODAL: Paket belum terdaftar
     ============================================================================ -->
<div class="modal fade" id="modalImpMissingPackages" tabindex="-1" aria-labelledby="modalImpMissingPackagesLabel" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header bg-warning">
        <h5 class="modal-title" id="modalImpMissingPackagesLabel">Ada Paket yang Belum Terdaftar</h5>
      </div>
      <div class="modal-body">
        <div class="alert alert-warning py-2">
            Ada data pelanggan menggunakan paket yang tidak terdaftar di billing untuk Server Area
            terpilih. Silakan buat paketnya di bawah ini (form akan terbuka di tab baru), lalu klik
            <b>Cek Ulang Paket</b> setelah selesai. Ulangi untuk semua paket yang masih terdaftar
            sebagai belum ada.
        </div>
        <div id="impMissingPackagesBody"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="btnImpMissingCancel">Batal Import</button>
        <button type="button" class="btn btn-primary" id="btnImpRecheckPackages">
            <span class="btn-label">Cek Ulang Paket</span>
            <span class="btn-loading d-none"><span class="spinner-border spinner-border-sm me-2"></span>Mengecek...</span>
        </button>
      </div>
    </div>
  </div>
</div>

<script>
// header.php/footer.php hanya memuat bootstrap.min.js di akhir dokumen
// (lewat footer.php), jadi instansiasi bootstrap.Modal harus ditunda
// sampai DOMContentLoaded supaya "bootstrap" sudah pasti terdefinisi.
document.addEventListener('DOMContentLoaded', function() {
    let impToken = null;
    let impPreviewData = [];
    let impPasswordMap = {};
    let impAuthModeMap = {};
    let impStep1Context = null; // {pemilik, area, brand, odp, sales, tipe_bayar, tipe_tempo}

    const modalEl = document.getElementById('modalImportExcel');
    const missingModalEl = document.getElementById('modalImpMissingPackages');
    if (!modalEl || !missingModalEl) return;

    const modalInstance = new bootstrap.Modal(modalEl);
    const missingModalInstance = new bootstrap.Modal(missingModalEl);

    const step1 = document.getElementById('impStep1');
    const step2 = document.getElementById('impStep2');
    const resultBox = document.getElementById('impResultBox');
    const btnExecute = document.getElementById('btnImpExecute');
    const routerWarningBox = document.getElementById('impRouterWarningBox');

    document.getElementById('btnImpOpenModal').addEventListener('click', function() {
        resetImportModal();
        modalInstance.show();
    });

    function resetImportModal() {
        step1.classList.remove('d-none');
        step2.classList.add('d-none');
        resultBox.classList.add('d-none');
        resultBox.innerHTML = '';
        btnExecute.classList.add('d-none');
        routerWarningBox.classList.add('d-none');
        routerWarningBox.innerHTML = '';
        impToken = null;
        impPreviewData = [];
        impPasswordMap = {};
        impAuthModeMap = {};
        document.getElementById('impBulkPassword').value = '';
        document.getElementById('impBulkAuthMode').value = 'MULTI MODE';
    }

    function escImpHtml(text) {
        return String(text == null ? '' : text)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function formatRupiahImp(val) {
        const n = parseFloat(val) || 0;
        return 'Rp ' + n.toLocaleString('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    }

    // ------------------------------------------------------------------
    // Dropdown Server -> set Area/Brand, load ODP & Sales.
    // ------------------------------------------------------------------
    document.getElementById('impServer').addEventListener('change', function() {
        const opt = this.options[this.selectedIndex];
        const area = opt ? (opt.getAttribute('data-area') || '') : '';
        const brand = opt ? (opt.getAttribute('data-brand') || '') : '';

        document.getElementById('impArea').value = area;
        document.getElementById('impAreaDisplay').value = area;
        document.getElementById('impBrand').value = brand;

        loadImpOdp(this.value, area);
        loadImpSales(this.value);
    });

    function loadImpOdp(server, area) {
        const odpSelect = document.getElementById('impOdp');
        odpSelect.innerHTML = '<option value="">Loading...</option>';
        if (!server || !area) {
            odpSelect.innerHTML = '<option value="">-- Pilih Server dahulu --</option>';
            return;
        }
        fetch('getdata/get_odp.php?server=' + encodeURIComponent(server) + '&area=' + encodeURIComponent(area))
            .then(r => r.text())
            .then(html => { odpSelect.innerHTML = html; })
            .catch(() => { odpSelect.innerHTML = '<option value="">Gagal memuat ODP</option>'; });
    }

    function loadImpSales(server) {
        const salesSelect = document.getElementById('impSales');
        salesSelect.innerHTML = '<option value="-">TANPA SALES</option>';
        if (!server) return;
        fetch('getdata/get_sales.php?server=' + encodeURIComponent(server))
            .then(r => r.text())
            .then(html => {
                if (html && html.trim() !== '') {
                    salesSelect.innerHTML = '<option value="-">TANPA SALES</option>' + html;
                }
            })
            .catch(() => { /* biarkan default TANPA SALES */ });
    }

    function currentStep1Context() {
        return {
            pemilik: document.getElementById('impServer').value,
            area: document.getElementById('impArea').value,
            brand: document.getElementById('impBrand').value,
            odp: document.getElementById('impOdp').value,
            sales: document.getElementById('impSales').value || '-',
            tipe_bayar: document.getElementById('impTipeBayar').value,
            tipe_tempo: document.getElementById('impTipeTempo').value,
        };
    }

    // ------------------------------------------------------------------
    // STEP 1 -> Preview (upload file)
    // ------------------------------------------------------------------
    document.getElementById('btnImpPreview').addEventListener('click', function() {
        const fileInput = document.getElementById('impExcelFile');
        const ctx = currentStep1Context();

        if (!fileInput.files || fileInput.files.length === 0) {
            alert('Pilih file Excel terlebih dahulu.');
            return;
        }
        if (!ctx.pemilik || !ctx.area) {
            alert('Pilih Server Area terlebih dahulu.');
            return;
        }
        if (!ctx.odp) {
            alert('Pilih ODP default terlebih dahulu.');
            return;
        }

        impStep1Context = ctx;

        const btn = this;
        const label = btn.querySelector('.btn-label');
        const loading = btn.querySelector('.btn-loading');
        btn.disabled = true;
        label.classList.add('d-none');
        loading.classList.remove('d-none');

        const fd = new FormData();
        fd.append('action', 'preview');
        fd.append('excel_file', fileInput.files[0]);
        fd.append('pemilik', ctx.pemilik);
        fd.append('area', ctx.area);
        fd.append('brand', ctx.brand);
        fd.append('odp', ctx.odp);
        fd.append('sales', ctx.sales);
        fd.append('tipe_bayar', ctx.tipe_bayar);
        fd.append('tipe_tempo', ctx.tipe_tempo);

        fetch('getdata/import_excel_pelanggan.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json())
            .then(res => {
                if (!res.success) {
                    throw new Error(res.message || 'Gagal memproses file.');
                }
                impToken = res.token;
                handlePreviewResponse(res);
            })
            .catch(err => { alert(err.message); })
            .finally(() => {
                btn.disabled = false;
                label.classList.remove('d-none');
                loading.classList.add('d-none');
            });
    });

    function handlePreviewResponse(res) {
        if (res.missing_packages && res.missing_packages.length > 0) {
            renderMissingPackages(res.missing_packages);
            missingModalInstance.show();
            return;
        }

        impPreviewData = res.data || [];
        impPreviewData.forEach(function(row) {
            if (!(row.IDPEL in impPasswordMap)) {
                impPasswordMap[row.IDPEL] = row.PASSWORD_FILE || '';
            }
        });

        renderSummary(res);

        if (res.router_connect_error) {
            routerWarningBox.classList.remove('d-none');
            routerWarningBox.innerHTML = escImpHtml(res.router_connect_error) + ' Status "Secret Sudah Ada / Belum Ada" mungkin tidak akurat.';
        } else {
            routerWarningBox.classList.add('d-none');
            routerWarningBox.innerHTML = '';
        }

        renderImpPreviewTable(impPreviewData);

        step1.classList.add('d-none');
        step2.classList.remove('d-none');
        btnExecute.classList.toggle('d-none', impPreviewData.length === 0);
    }

    function renderSummary(res) {
        const summaryBox = document.getElementById('impSummaryBox');
        summaryBox.className = impPreviewData.length > 0 ? 'alert alert-success py-2 mb-3' : 'alert alert-warning py-2 mb-3';
        summaryBox.innerHTML =
            `Total baris di file: <b>${res.total_rows + (res.blocked_rows ? res.blocked_rows.length : 0) + (res.duplicate_in_db_count || 0)}</b> | ` +
            `Sudah ada di database: <b>${res.duplicate_in_db_count || 0}</b> | ` +
            `Bermasalah (dilewati): <b>${res.blocked_rows ? res.blocked_rows.length : 0}</b> | ` +
            `<span class="text-success">Siap diimport: <b>${res.total_rows}</b></span>`;

        const blockedBox = document.getElementById('impBlockedBox');
        const parts = [];
        if (res.blocked_rows && res.blocked_rows.length > 0) {
            parts.push('<b>Baris dilewati:</b><ul class="mb-1">' + res.blocked_rows.map(b =>
                `<li>Baris ${b.row} (${escImpHtml(b.idpel)} - ${escImpHtml(b.nama || '-')}): ${escImpHtml(b.reason)}</li>`
            ).join('') + '</ul>');
        }
        if (res.orphan_transaksi_idpel && res.orphan_transaksi_idpel.length > 0) {
            parts.push('<div>Sheet Transaksi berisi IDPEL yang tidak ditemukan di sheet Pelanggan (transaksi ini diabaikan): ' +
                escImpHtml(res.orphan_transaksi_idpel.join(', ')) + '</div>');
        }
        if (res.tx_skipped && res.tx_skipped.length > 0) {
            parts.push(`<div>${res.tx_skipped.length} baris di sheet Transaksi Berhasil Terbaru diabaikan karena TANGGALBAYAR/NOMINAL kosong.</div>`);
        }
        if (parts.length > 0) {
            blockedBox.classList.remove('d-none');
            blockedBox.innerHTML = parts.join('');
        } else {
            blockedBox.classList.add('d-none');
            blockedBox.innerHTML = '';
        }
    }

    function rowAuthMode(row) {
        return impAuthModeMap[row.IDPEL] || 'MULTI MODE';
    }

    function odpCellHtml(row) {
        const sourceLabel = row.odp_source === 'file'
            ? '<span class="badge bg-info text-dark" style="font-size:9px;">dari file</span>'
            : '<span class="badge bg-secondary" style="font-size:9px;">default</span>';
        return escImpHtml(row.odp_final || '-') + ' ' + sourceLabel;
    }

    function statusBadge(status, labelExists, labelNotExists) {
        if (status === 'exists') return '<span class="badge bg-success" style="font-size:10px;">' + labelExists + '</span>';
        if (status === 'not_exists') return '<span class="badge bg-warning text-dark" style="font-size:10px;">' + labelNotExists + '</span>';
        return '<span class="badge bg-secondary" style="font-size:10px;">Tidak Diketahui</span>';
    }

    function renderImpPreviewTable(data) {
        const tbody = document.getElementById('impPreviewBody');
        if (!data.length) {
            tbody.innerHTML = '<tr><td colspan="17" class="text-center text-muted">Tidak ada pelanggan yang bisa diimport.</td></tr>';
            return;
        }

        tbody.innerHTML = data.map(function(row, idx) {
            const authModeValue = rowAuthMode(row);
            const passwordValue = impPasswordMap[row.IDPEL] || '';
            const warnHtml = (row.warnings && row.warnings.length)
                ? '<br><span class="text-warning" style="font-size:10px;">' + row.warnings.map(escImpHtml).join('; ') + '</span>'
                : '';

            return '<tr>' +
                '<td><input type="checkbox" class="imp-row-cb" data-idx="' + idx + '" checked></td>' +
                '<td><strong>' + escImpHtml(row.IDPEL) + '</strong></td>' +
                '<td>' + escImpHtml(row.NAMA) + '</td>' +
                '<td>' + escImpHtml(row.NOWA) + '</td>' +
                '<td>' + escImpHtml(row.PAKET) + '</td>' +
                '<td>' + formatRupiahImp(row.HARGA) + '</td>' +
                '<td>' + escImpHtml(row.TIPE_BAYAR) + warnHtml + '</td>' +
                '<td>' + escImpHtml(row.TIPE_TEMPO) + '</td>' +
                '<td>' + odpCellHtml(row) + '</td>' +
                '<td>' + escImpHtml(row.sales_final || '-') + '</td>' +
                '<td>' + escImpHtml(row.TANGGALPASANG || '-') + '</td>' +
                '<td>' + statusBadge(row.secret_status, 'Sudah Ada', 'Belum Ada') + '</td>' +
                '<td>' + statusBadge(row.radius_status, 'Sudah Ada', 'Belum Ada') + '</td>' +
                '<td>' +
                    '<select class="form-select form-select-sm imp-row-authmode" data-idx="' + idx + '" data-idpel="' + escImpHtml(row.IDPEL) + '">' +
                        '<option value="API MODE"' + (authModeValue === 'API MODE' ? ' selected' : '') + '>API MODE</option>' +
                        '<option value="RADIUS MODE"' + (authModeValue === 'RADIUS MODE' ? ' selected' : '') + '>RADIUS MODE</option>' +
                        '<option value="MULTI MODE"' + (authModeValue === 'MULTI MODE' ? ' selected' : '') + '>MULTI MODE</option>' +
                    '</select>' +
                '</td>' +
                '<td>' +
                    '<input type="text" class="form-control form-control-sm imp-row-password" style="min-width:140px;font-size:12px;" ' +
                        'data-idpel="' + escImpHtml(row.IDPEL) + '" placeholder="Wajib diisi..." value="' + escImpHtml(passwordValue) + '">' +
                '</td>' +
                '<td class="text-center">' + (row.total_transaksi || 0) + '</td>' +
                '<td>' + formatRupiahImp(row.total_nominal || 0) + '</td>' +
                '</tr>';
        }).join('');
    }

    document.getElementById('impPreviewBody').addEventListener('input', function(e) {
        if (!e.target.classList.contains('imp-row-password')) return;
        const idpel = e.target.getAttribute('data-idpel');
        if (idpel) impPasswordMap[idpel] = e.target.value;
    });

    document.getElementById('impPreviewBody').addEventListener('change', function(e) {
        if (!e.target.classList.contains('imp-row-authmode')) return;
        const idx = parseInt(e.target.getAttribute('data-idx'), 10);
        const row = impPreviewData[idx];
        if (!row) return;
        impAuthModeMap[row.IDPEL] = e.target.value;
    });

    document.getElementById('btnImpApplyBulkAuthMode').addEventListener('click', function() {
        const bulkMode = document.getElementById('impBulkAuthMode').value;
        document.querySelectorAll('.imp-row-authmode').forEach(function(sel) {
            sel.value = bulkMode;
            const idx = parseInt(sel.getAttribute('data-idx'), 10);
            const row = impPreviewData[idx];
            if (!row) return;
            impAuthModeMap[row.IDPEL] = bulkMode;
        });
    });

    document.getElementById('btnImpApplyBulkPassword').addEventListener('click', function() {
        const bulkVal = document.getElementById('impBulkPassword').value;
        if (!bulkVal) {
            alert('Isi dulu password yang ingin diterapkan ke semua baris.');
            return;
        }
        document.querySelectorAll('.imp-row-password').forEach(function(input) {
            input.value = bulkVal;
            const idpel = input.getAttribute('data-idpel');
            if (idpel) impPasswordMap[idpel] = bulkVal;
        });
    });

    document.getElementById('impSelectAll').addEventListener('change', function() {
        const checked = this.checked;
        document.querySelectorAll('.imp-row-cb').forEach(cb => { cb.checked = checked; });
    });

    document.getElementById('btnImpBackToStep1').addEventListener('click', function() {
        step2.classList.add('d-none');
        step1.classList.remove('d-none');
        btnExecute.classList.add('d-none');
    });

    // ------------------------------------------------------------------
    // Modal: Paket belum terdaftar
    // ------------------------------------------------------------------
    function renderMissingPackages(missingPackages) {
        const container = document.getElementById('impMissingPackagesBody');
        container.innerHTML = missingPackages.map(function(mp, i) {
            const formId = 'impMpForm' + i;
            const localId = 'impMpLocal' + i;
            const remotId = 'impMpRemot' + i;
            return '<div class="card mb-3">' +
                '<div class="card-body">' +
                '<h6>' + escImpHtml(mp.paket) + ' <span class="badge bg-secondary">' + mp.count + ' pelanggan</span></h6>' +
                '<div class="small text-muted mb-2">IDPEL terdampak: ' + escImpHtml(mp.affected_idpel.slice(0, 15).join(', ')) +
                    (mp.affected_idpel.length > 15 ? ', ... (' + (mp.affected_idpel.length - 15) + ' lainnya)' : '') + '</div>' +
                '<form id="' + formId + '" target="_blank" method="post" action="proses/addpackagespppoe.php" class="row g-2">' +
                    '<input type="hidden" name="profileName" value="' + escImpHtml(mp.paket) + '">' +
                    '<input type="hidden" name="servers[]" value="">' +
                    '<div class="col-md-3"><label class="form-label small mb-0">Nama Paket</label><input class="form-control form-control-sm" value="' + escImpHtml(mp.paket) + '" disabled></div>' +
                    '<div class="col-md-3"><label class="form-label small mb-0">Kecepatan</label><input required name="ratelimit" class="form-control form-control-sm" placeholder="10M/10M"></div>' +
                    '<div class="col-md-2"><label class="form-label small mb-0">Harga</label><input required type="number" name="harga" class="form-control form-control-sm" placeholder="0"></div>' +
                    '<div class="col-md-2"><label class="form-label small mb-0">Local IP</label><select required name="local" id="' + localId + '" class="form-select form-select-sm"><option value="">Loading...</option></select></div>' +
                    '<div class="col-md-2"><label class="form-label small mb-0">Remote IP</label><select required name="remot" id="' + remotId + '" class="form-select form-select-sm" disabled><option value="">-</option></select></div>' +
                    '<div class="col-md-2"><label class="form-label small mb-0">Komisi (%)</label><input type="number" name="komisi" class="form-control form-control-sm" value="0"></div>' +
                    '<div class="col-md-10 text-end mt-2"><button type="submit" class="btn btn-sm btn-primary">Buat Paket Ini (tab baru)</button></div>' +
                '</form>' +
                '</div></div>';
        }).join('');

        if (impStep1Context) {
            const serversValue = JSON.stringify({ pemilik: impStep1Context.pemilik, brand: impStep1Context.brand, area: impStep1Context.area });
            container.querySelectorAll('input[name="servers[]"]').forEach(function(inp) { inp.value = serversValue; });

            fetch('getdata/get_pool_by_pemilik.php?pemilik=' + encodeURIComponent(impStep1Context.pemilik))
                .then(r => r.json())
                .then(res => {
                    const pools = (res && res.pools) ? res.pools : [];
                    missingPackages.forEach(function(mp, i) {
                        const localSel = document.getElementById('impMpLocal' + i);
                        const remotSel = document.getElementById('impMpRemot' + i);
                        if (!localSel || !remotSel) return;
                        localSel.innerHTML = '<option value="">-- Pilih Local IP --</option>' +
                            pools.map(p => '<option value="' + escImpHtml(p.local) + '" data-remot="' + escImpHtml(p.remote) + '"' + (p.available ? '' : ' disabled') + '>' + escImpHtml(p.local) + (p.available ? '' : ' (terpakai)') + '</option>').join('');
                        localSel.addEventListener('change', function() {
                            const opt = this.options[this.selectedIndex];
                            const remot = opt ? (opt.getAttribute('data-remot') || '') : '';
                            remotSel.innerHTML = remot ? '<option value="' + escImpHtml(remot) + '">' + escImpHtml(remot) + '</option>' : '<option value="">-</option>';
                            remotSel.disabled = !remot;
                        });
                    });
                })
                .catch(() => { /* biarkan select kosong kalau gagal */ });
        }
    }

    document.getElementById('btnImpRecheckPackages').addEventListener('click', function() {
        if (!impToken) return;
        const btn = this;
        const label = btn.querySelector('.btn-label');
        const loading = btn.querySelector('.btn-loading');
        btn.disabled = true;
        label.classList.add('d-none');
        loading.classList.remove('d-none');

        const fd = new FormData();
        fd.append('action', 'check_packages');
        fd.append('token', impToken);

        fetch('getdata/import_excel_pelanggan.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json())
            .then(res => {
                if (!res.success) throw new Error(res.message || 'Gagal cek ulang paket.');
                if (res.missing_packages && res.missing_packages.length > 0) {
                    renderMissingPackages(res.missing_packages);
                    return;
                }
                missingModalInstance.hide();
                handlePreviewResponse(res);
                modalInstance.show();
            })
            .catch(err => { alert(err.message); })
            .finally(() => {
                btn.disabled = false;
                label.classList.remove('d-none');
                loading.classList.add('d-none');
            });
    });

    document.getElementById('btnImpMissingCancel').addEventListener('click', function() {
        modalInstance.hide();
    });

    // ------------------------------------------------------------------
    // STEP 2 -> Execute
    // ------------------------------------------------------------------
    btnExecute.addEventListener('click', function() {
        const selectedIdpel = [];
        const missingPasswordIdpel = [];
        const authModePayload = {};

        document.querySelectorAll('.imp-row-cb:checked').forEach(function(cb) {
            const idx = parseInt(cb.getAttribute('data-idx'), 10);
            const row = impPreviewData[idx];
            if (!row) return;

            selectedIdpel.push(row.IDPEL);
            authModePayload[row.IDPEL] = rowAuthMode(row);

            const pwd = (impPasswordMap[row.IDPEL] || '').trim();
            if (pwd === '') missingPasswordIdpel.push(row.IDPEL);
        });

        if (selectedIdpel.length === 0) {
            alert('Pilih minimal 1 pelanggan untuk diimport.');
            return;
        }
        if (missingPasswordIdpel.length > 0) {
            alert('Password PPPoE WAJIB diisi untuk SEMUA pelanggan terpilih. Masih kosong untuk:\n\n' +
                missingPasswordIdpel.join(', '));
            return;
        }

        const confirmMsg = `Anda akan menambahkan ${selectedIdpel.length} pelanggan baru beserta riwayat transaksinya ke database.\n\nLanjutkan?`;
        if (!confirm(confirmMsg)) return;

        const btn = this;
        const label = btn.querySelector('.btn-label');
        const loading = btn.querySelector('.btn-loading');
        btn.disabled = true;
        label.classList.add('d-none');
        loading.classList.remove('d-none');

        const passwordPayload = {};
        selectedIdpel.forEach(function(idpel) {
            if (impPasswordMap[idpel]) passwordPayload[idpel] = impPasswordMap[idpel];
        });

        const fd = new FormData();
        fd.append('action', 'execute');
        fd.append('token', impToken);
        fd.append('idpel_list', JSON.stringify(selectedIdpel));
        fd.append('idpel_password', JSON.stringify(passwordPayload));
        fd.append('idpel_authmode', JSON.stringify(authModePayload));

        fetch('getdata/import_excel_pelanggan.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json())
            .then(res => {
                if (!res.success) throw new Error(res.message || 'Import gagal.');

                let html = '<div class="alert alert-success">';
                html += `Import selesai. Berhasil: <b>${res.total_berhasil}</b>, Dilewati: <b>${res.total_dilewati}</b>, Error: <b>${res.total_error}</b>.`;
                html += '</div>';

                if (res.total_berhasil > 0) {
                    html += '<div class="alert ' + (res.radius_restarted ? 'alert-success' : 'alert-secondary') + ' py-2">';
                    html += res.radius_restarted
                        ? 'FreeRADIUS sudah di-restart sekali untuk menerapkan akun baru.'
                        : 'Tidak ada akun RADIUS baru yang ditambahkan pada batch ini, FreeRADIUS tidak perlu direstart.';
                    html += '</div>';
                }

                if (res.router_connect_error) {
                    html += '<div class="alert alert-warning">' + escImpHtml(res.router_connect_error) + '</div>';
                }

                if (res.notes && res.notes.length > 0) {
                    html += '<div class="alert alert-secondary py-2"><b>Detail per pelanggan (MikroTik / RADIUS):</b>';
                    html += '<div class="table-responsive mt-1"><table class="table table-sm table-bordered mb-0"><thead><tr><th>IDPEL</th><th>MikroTik</th><th>RADIUS</th></tr></thead><tbody>';
                    res.notes.forEach(n => {
                        html += `<tr><td>${escImpHtml(n.idpel)}</td><td>${escImpHtml(n.mikrotik)}</td><td>${escImpHtml(n.radius)}</td></tr>`;
                    });
                    html += '</tbody></table></div></div>';
                }

                if (res.errors && res.errors.length > 0) {
                    html += '<div class="alert alert-danger"><b>Detail Error:</b><ul class="mb-0">';
                    res.errors.forEach(e => { html += `<li>${escImpHtml(e.idpel)}: ${escImpHtml(e.reason)}</li>`; });
                    html += '</ul></div>';
                }

                if (res.skipped && res.skipped.length > 0) {
                    html += '<div class="alert alert-warning"><b>Dilewati:</b><ul class="mb-0">';
                    res.skipped.forEach(s => { html += `<li>${escImpHtml(s.idpel)}: ${escImpHtml(s.reason)}</li>`; });
                    html += '</ul></div>';
                }

                resultBox.innerHTML = html;
                resultBox.classList.remove('d-none');
                step2.classList.add('d-none');
                btnExecute.classList.add('d-none');

                setTimeout(function() { window.location.reload(); }, 2500);
            })
            .catch(err => { alert(err.message); })
            .finally(() => {
                btn.disabled = false;
                label.classList.remove('d-none');
                loading.classList.add('d-none');
            });
    });
});
</script>
<?php endif; ?>

<?php require 'footer.php'; ?>
