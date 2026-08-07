<?php
/**
 * adjust_tanggal_tempo_excel.php
 * ----------------------------------------------------------------------
 * Penyesuaian massal "tanggal bayar" (mengoreksi transaksi BERHASIL
 * terakhir) & "tanggal jatuh tempo berikutnya" (kolom
 * pelanggan.TANGGAL_MONTHVERSARY) lewat upload Excel -- KHUSUS pelanggan
 * mode tempo Monthversary Due Date & Rolling Due Date
 * (mengikuti_tanggal_bayar). Mode Fixed Due Date tidak relevan di sini
 * karena jatuh temponya 1 pengaturan global (Payment Setting), bukan per
 * pelanggan.
 *
 * Dipakai terutama untuk migrasi data transaksi dari Excel lama: template
 * KOSONG (cuma header kolom + 1 baris contoh), admin isi sendiri dari data
 * Excel/sistem lama miliknya -- baris IDPEL yang diisi di sini yang akan
 * diproses, pencocokan selalu berdasarkan IDPEL. Preview hasil upload BISA
 * DIFILTER & DIEDIT LANGSUNG di halaman sebelum diterapkan. Pelanggan yang
 * tidak ada di file/tidak dipilih TIDAK disentuh sama sekali -- data yang
 * sudah ada di database dibiarkan apa adanya.
 *
 * Backend: getdata/adjust_tanggal_tempo_excel.php (preview -> execute).
 * Logika jatuh tempo disamakan persis dengan tombol pensil "Ubah Jatuh
 * Tempo" di tables.php (proses/update_tanggal_monthversary.php). Untuk
 * tanggal bayar, yang diubah adalah TRANSAKSI BERHASIL TERAKHIR pelanggan
 * ybs (bukan selalu menambah baris baru) -- transaksi baru cuma dibuat
 * kalau pelanggan itu memang belum pernah punya transaksi BERHASIL sama
 * sekali.
 * ----------------------------------------------------------------------
 */

require_once __DIR__ . '/cek-sesi.php';

function getExcelColumnNameAdj($index)
{
    $name = '';
    $index = (int) $index;
    do {
        $name = chr(($index % 26) + 65) . $name;
        $index = (int) floor($index / 26) - 1;
    } while ($index >= 0);
    return $name;
}

function buildSimpleSheetXmlAdj($rows)
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    foreach ($rows as $rowIndex => $row) {
        $excelRow = $rowIndex + 1;
        $xml .= '<row r="' . $excelRow . '">';
        foreach ($row as $colIndex => $cell) {
            $col = getExcelColumnNameAdj($colIndex);
            $xml .= '<c r="' . $col . $excelRow . '" t="inlineStr"><is><t>' . htmlspecialchars((string) $cell, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</t></is></c>';
        }
        $xml .= '</row>';
    }
    $xml .= '</sheetData></worksheet>';
    return $xml;
}

/**
 * Bangun file .xlsx dari beberapa sheet ($sheets = [['name'=>.., 'rows'=>[[..]]], ...]).
 * Sama persis dengan buildXlsxWorkbook() di importcustomerpppoe.php.
 */
function buildXlsxWorkbookAdj(array $sheets): ?string
{
    $tempDir = sys_get_temp_dir() . '/excel_adj_' . uniqid();
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
    <dc:title>Template Penyesuaian Tanggal Bayar dan Jatuh Tempo</dc:title>
    <dc:creator>Billing System</dc:creator>
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
        file_put_contents($tempDir . '/xl/worksheets/sheet' . $i . '.xml', buildSimpleSheetXmlAdj($sheets[$i - 1]['rows']));
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

function generateAdjustTempoTemplate()
{
    // Template KOSONG (cuma header + 1 baris contoh) -- SENGAJA tidak diisi
    // otomatis dari database, supaya admin isi sendiri dari data Excel/sistem
    // lama miliknya sendiri. Baris contoh pakai IDPEL sentinel 'CONTOH-001'
    // (dilewati otomatis oleh backend, lihat adjResolveRows() di
    // getdata/adjust_tanggal_tempo_excel.php) supaya aman kalau lupa dihapus.
    $headers = ['IDPEL', 'NAMA', 'TANGGAL_BAYAR_BARU', 'NOMINAL_BAYAR_BARU', 'METODE_BAYAR_BARU', 'TANGGAL_JATUH_TEMPO_BARU', 'KETERANGAN'];
    $contoh = ['CONTOH-001', 'Nama Pelanggan (opsional, cuma buat catatan Anda)', '2026-07-01', '150000', 'Transfer BCA', '2026-08-01', 'Migrasi dari sistem lama'];
    $rows = [$headers, $contoh];

    $petunjukRows = [
        ['KOLOM', 'KETERANGAN'],
        ['IDPEL', 'WAJIB. Harus SAMA PERSIS dengan IDPEL pelanggan yang sudah terdaftar di billing ini -- pencocokan SELALU berdasarkan IDPEL.'],
        ['NAMA', 'Opsional, cuma catatan Anda sendiri supaya mudah dicek -- TIDAK dipakai/disimpan sistem.'],
        [],
        ['TANGGAL_BAYAR_BARU', 'ISI kalau ingin MENGOREKSI tanggal bayar pelanggan ini. Format YYYY-MM-DD. Ini akan MENGUBAH transaksi BERHASIL TERAKHIR pelanggan ybs (bukan menambah baris baru) -- kalau pelanggan itu memang belum pernah punya transaksi BERHASIL sama sekali, baru dibuat 1 transaksi baru. KOSONGKAN kalau tidak ingin mengubah.'],
        ['NOMINAL_BAYAR_BARU', 'Nominal transaksi (angka saja). Hanya dipakai kalau TANGGAL_BAYAR_BARU diisi. Kosongkan untuk mempertahankan nominal transaksi lama (atau harga paket kalau belum pernah ada transaksi).'],
        ['METODE_BAYAR_BARU', 'Opsional, contoh: Transfer BCA, Tunai, Migrasi Data Lama. Hanya dipakai kalau TANGGAL_BAYAR_BARU diisi. Kosongkan untuk mempertahankan metode bayar lama.'],
        ['TANGGAL_JATUH_TEMPO_BARU', 'ISI kalau ingin mengubah tanggal jatuh tempo berikutnya pelanggan. Format YYYY-MM-DD. Untuk Monthversary: jadi anchor tetap baru. Untuk Rolling: jadi override (berlaku selama tanggalnya belum lewat, setelah itu otomatis dihitung ulang dari histori pembayaran). KOSONGKAN kalau tidak ingin mengubah.'],
        ['KETERANGAN', 'Opsional, catatan bebas, ikut tersimpan di CEK transaksi & histori aktivitas.'],
        [],
        ['CATATAN PENTING', 'HAPUS baris "CONTOH-001" sebelum upload (atau biarkan saja -- baris dengan IDPEL berawalan "CONTOH" otomatis dilewati sistem, jadi aman kalau lupa dihapus).'],
        ['', 'Baris yang kedua kolom TANGGAL_BAYAR_BARU dan TANGGAL_JATUH_TEMPO_BARU-nya SAMA-SAMA KOSONG akan dilewati begitu saja (dianggap tidak ada perubahan) -- HANYA IDPEL yang Anda isi di sini yang akan diproses. Pelanggan lain yang tidak ada di file ini TIDAK disentuh sama sekali, datanya tetap seperti apa adanya.'],
        ['', 'Setelah upload, semua nilai ini BISA DIEDIT LAGI & DIFILTER langsung di tabel preview sebelum diterapkan.'],
        ['', 'Fitur ini KHUSUS pelanggan mode tempo Monthversary Due Date & Rolling Due Date. Mode Fixed Due Date tidak tersedia di sini karena jatuh temponya 1 pengaturan global (Menu Payment Setting), bukan per pelanggan. Kalau IDPEL yang diisi ternyata mode Fixed Due Date atau tidak ditemukan, baris tsb akan ditandai bermasalah di preview.'],
    ];

    $sheets = [
        ['name' => 'Penyesuaian Tanggal', 'rows' => $rows],
        ['name' => 'Petunjuk', 'rows' => $petunjukRows],
    ];

    $zipFile = buildXlsxWorkbookAdj($sheets);
    if (!$zipFile) {
        echo 'Error: Tidak dapat membuat file Excel.';
        return;
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="template_penyesuaian_tanggal_tempo.xlsx"');
    header('Cache-Control: max-age=0');
    readfile($zipFile);
    unlink($zipFile);
    exit;
}

// Handle download template SEBELUM include header.php (butuh header() mentah).
if (isset($_GET['action']) && $_GET['action'] === 'download_template') {
    generateAdjustTempoTemplate();
    exit;
}

$canAccessAdjTempo = isset($AKSES) && in_array($AKSES, ['ADMIN', 'ASSISTANT'], true);
if ($canAccessAdjTempo && $AKSES === 'ASSISTANT') {
    $canAccessAdjTempo = isset($akses_menu) && is_array($akses_menu) && in_array('Transaction', $akses_menu, true);
}

require 'header.php';
?>

<div class="container-fluid py-4">
  <div class="row">
    <div class="col-12">
      <div class="card mb-4">
        <div class="card-header pb-0">
          <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span>Penyesuaian Tanggal Bayar &amp; Jatuh Tempo (Monthversary / Rolling)</span>
            <?php if ($canAccessAdjTempo): ?>
            <button type="button" class="btn btn-success btn-sm" id="btnAdjOpenModal">
              Penyesuaian dari Excel
            </button>
            <?php endif; ?>
          </div>
          <br>
        </div>
        <div class="card-body px-3 pt-0 pb-3">

          <?php if (!$canAccessAdjTempo): ?>
            <div class="alert alert-warning">Akun Anda tidak memiliki akses untuk fitur ini.</div>
          <?php else: ?>

          <div class="alert alert-info">
            Fitur ini untuk <strong>migrasi data transaksi dari Excel/sistem lama</strong>, dicocokkan berdasarkan
            <strong>IDPEL</strong>: mengoreksi <strong>transaksi BERHASIL terakhir</strong> pelanggan (tanggal
            bayar/nominal/metode bayarnya) dan/atau <strong>tanggal jatuh tempo berikutnya</strong>, khusus mode
            tempo <strong>Monthversary Due Date</strong> dan <strong>Rolling Due Date</strong>. Mode
            <strong>Fixed Due Date</strong> tidak tersedia di sini karena jatuh temponya 1 pengaturan global (Menu
            Payment Setting), bukan per pelanggan. Pelanggan yang tidak ada di file/tidak diisi <strong>TIDAK
            disentuh sama sekali</strong> -- data yang sudah ada di database dibiarkan apa adanya.
          </div>

          <a href="?action=download_template" class="btn btn-outline-success mb-3">
            <i class="fas fa-download"></i> Download Template Excel (.xlsx)
          </a>

          <div class="small text-muted">
            Template berupa sheet <strong>Penyesuaian Tanggal</strong> KOSONG (cuma header kolom + 1 baris contoh)
            -- isi sendiri IDPEL beserta <code>TANGGAL_BAYAR_BARU</code> dan/atau
            <code>TANGGAL_JATUH_TEMPO_BARU</code> dari data Excel/sistem lama Anda, lalu upload lewat tombol
            <strong>Penyesuaian dari Excel</strong>. Lihat sheet <strong>Petunjuk</strong> untuk penjelasan lengkap
            tiap kolom.
          </div>

          <?php endif; ?>

        </div>
      </div>
    </div>
  </div>
</div>

<?php if ($canAccessAdjTempo): ?>
<!-- ============================================================================
     MODAL: Penyesuaian Tanggal Bayar & Jatuh Tempo dari Excel
     ============================================================================ -->
<div class="modal fade" id="modalAdjTempoExcel" tabindex="-1" aria-labelledby="modalAdjTempoExcelLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">

      <div class="modal-header bg-success text-white">
        <h5 class="modal-title text-white" id="modalAdjTempoExcelLabel">Penyesuaian Tanggal Bayar &amp; Jatuh Tempo dari Excel</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body">

        <!-- ====== STEP 1: Upload file ====== -->
        <div id="adjStep1">
            <div class="alert alert-info small mb-3">
                Gunakan template terbaru (tombol Download Template di halaman utama). Isi IDPEL + kolom yang mau
                diubah dari data Excel/sistem lama Anda sendiri. Hanya baris yang kolom
                <code>TANGGAL_BAYAR_BARU</code> dan/atau <code>TANGGAL_JATUH_TEMPO_BARU</code>-nya diisi yang akan
                diproses -- pelanggan lain yang tidak ada di file ini tidak disentuh sama sekali.
            </div>

            <div class="mb-3">
                <label class="form-label">File Excel <span class="text-danger">*</span></label>
                <input type="file" class="form-control" id="adjExcelFile" accept=".xlsx,.xls">
                <div class="form-text">Maks 10MB.</div>
            </div>

            <div class="text-end mt-3">
                <button type="button" class="btn btn-primary" id="btnAdjPreview">
                    <span class="btn-label">Cek Data dari File</span>
                    <span class="btn-loading d-none">
                        <span class="spinner-border spinner-border-sm me-2"></span>Memproses...
                    </span>
                </button>
            </div>
        </div>

        <!-- ====== STEP 2: Preview + edit + konfirmasi ====== -->
        <div id="adjStep2" class="d-none">
            <div id="adjSummaryBox" class="alert alert-secondary py-2 mb-3"></div>
            <div id="adjBlockedBox" class="alert alert-warning py-2 mb-3 d-none"></div>

            <div class="small text-muted mb-2">
                Nilai di kolom <b>Tanggal Bayar Baru</b>, <b>Nominal</b>, <b>Metode Bayar</b>, <b>Jatuh Tempo
                Baru</b>, dan <b>Keterangan</b> BISA DIEDIT LANGSUNG di tabel ini sebelum diterapkan (mis. kalau
                ada yang salah baca dari file, tidak perlu upload ulang). "Tanggal Bayar Baru" akan MENGUBAH
                transaksi BERHASIL TERAKHIR pelanggan ybs (bukan menambah baris transaksi baru), kecuali
                pelanggan itu memang belum pernah punya transaksi BERHASIL sama sekali.
            </div>

            <div class="row g-2 align-items-end mb-2">
                <div class="col-md-4">
                    <label class="form-label small mb-1">Cari (IDPEL / Nama / Area)</label>
                    <input type="text" class="form-control form-control-sm" id="adjFilterText" placeholder="Ketik untuk memfilter baris...">
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1">Tipe Tempo</label>
                    <select class="form-select form-select-sm" id="adjFilterTipeTempo">
                        <option value="">Semua</option>
                        <option value="monthversary">Monthversary</option>
                        <option value="mengikuti_tanggal_bayar">Rolling</option>
                    </select>
                </div>
                <div class="col-md-5 d-flex justify-content-end align-items-center gap-3">
                    <div>
                        <input type="checkbox" id="adjSelectAll" checked>
                        <label for="adjSelectAll" class="ms-1">Pilih Semua (baris tampil)</label>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnAdjBackToStep1">Upload Ulang</button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-striped align-middle" id="adjPreviewTable">
                    <thead class="table-dark">
                        <tr>
                            <th style="width:36px;"></th>
                            <th>IDPEL</th>
                            <th>Nama</th>
                            <th>Area</th>
                            <th>Tipe Tempo</th>
                            <th style="min-width:150px;">Tanggal Bayar Baru</th>
                            <th style="min-width:120px;">Nominal</th>
                            <th style="min-width:140px;">Metode Bayar</th>
                            <th style="min-width:150px;">Jatuh Tempo Baru</th>
                            <th style="min-width:160px;">Keterangan</th>
                            <th>Catatan</th>
                        </tr>
                    </thead>
                    <tbody id="adjPreviewBody">
                        <tr><td colspan="11" class="text-center text-muted">Belum ada data.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ====== STEP 3: Hasil eksekusi ====== -->
        <div id="adjResultBox" class="d-none mt-3"></div>

      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
        <button type="button" class="btn btn-success d-none" id="btnAdjExecute">
            <span class="btn-label">Terapkan Penyesuaian Terpilih</span>
            <span class="btn-loading d-none">
                <span class="spinner-border spinner-border-sm me-2"></span>Menyimpan...
            </span>
        </button>
      </div>

    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    let adjPreviewData = [];

    const modalEl = document.getElementById('modalAdjTempoExcel');
    if (!modalEl) return;
    const modalInstance = new bootstrap.Modal(modalEl);

    const step1 = document.getElementById('adjStep1');
    const step2 = document.getElementById('adjStep2');
    const resultBox = document.getElementById('adjResultBox');
    const btnExecute = document.getElementById('btnAdjExecute');

    document.getElementById('btnAdjOpenModal').addEventListener('click', function() {
        resetAdjModal();
        modalInstance.show();
    });

    function resetAdjModal() {
        step1.classList.remove('d-none');
        step2.classList.add('d-none');
        resultBox.classList.add('d-none');
        resultBox.innerHTML = '';
        btnExecute.classList.add('d-none');
        adjPreviewData = [];
        document.getElementById('adjExcelFile').value = '';
        document.getElementById('adjFilterText').value = '';
        document.getElementById('adjFilterTipeTempo').value = '';
    }

    function escAdjHtml(text) {
        return String(text == null ? '' : text)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function setAdjBtnLoading(btn, loading) {
        btn.disabled = loading;
        btn.querySelector('.btn-label').classList.toggle('d-none', loading);
        btn.querySelector('.btn-loading').classList.toggle('d-none', !loading);
    }

    // ------------------------------------------------------------------
    // STEP 1 -> STEP 2: upload & preview
    // ------------------------------------------------------------------
    document.getElementById('btnAdjPreview').addEventListener('click', function() {
        const fileInput = document.getElementById('adjExcelFile');
        if (!fileInput.files || fileInput.files.length === 0) {
            alert('Pilih file Excel terlebih dahulu.');
            return;
        }

        const btn = this;
        setAdjBtnLoading(btn, true);

        const formData = new FormData();
        formData.append('action', 'preview');
        formData.append('excel_file', fileInput.files[0]);

        fetch('getdata/adjust_tanggal_tempo_excel.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(result => {
                setAdjBtnLoading(btn, false);
                if (!result.success) {
                    alert(result.message || 'Gagal memproses file.');
                    return;
                }

                adjPreviewData = result.data || [];

                const summaryBox = document.getElementById('adjSummaryBox');
                summaryBox.innerHTML = 'Total baris siap diproses: <strong>' + result.total_rows + '</strong>' +
                    (result.blocked_rows && result.blocked_rows.length ? ', bermasalah: <strong>' + result.blocked_rows.length + '</strong>' : '');

                const blockedBox = document.getElementById('adjBlockedBox');
                if (result.blocked_rows && result.blocked_rows.length > 0) {
                    let html = '<strong>Baris bermasalah (dilewati):</strong><ul class="mb-0">';
                    result.blocked_rows.forEach(function(b) {
                        html += '<li>Baris ' + b.row + ' (' + escAdjHtml(b.idpel) + ' - ' + escAdjHtml(b.nama || '') + '): ' + escAdjHtml(b.reason) + '</li>';
                    });
                    html += '</ul>';
                    blockedBox.innerHTML = html;
                    blockedBox.classList.remove('d-none');
                } else {
                    blockedBox.classList.add('d-none');
                    blockedBox.innerHTML = '';
                }

                renderAdjPreviewTable();

                step1.classList.add('d-none');
                step2.classList.remove('d-none');
                btnExecute.classList.toggle('d-none', adjPreviewData.length === 0);
            })
            .catch(() => {
                setAdjBtnLoading(btn, false);
                alert('Terjadi kesalahan saat upload/proses file.');
            });
    });

    function renderAdjPreviewTable() {
        const tbody = document.getElementById('adjPreviewBody');
        if (adjPreviewData.length === 0) {
            tbody.innerHTML = '<tr><td colspan="11" class="text-center text-muted">Tidak ada baris yang berisi perubahan.</td></tr>';
            return;
        }

        let html = '';
        adjPreviewData.forEach(function(row, idx) {
            const warn = (row.warnings && row.warnings.length) ? row.warnings.join('; ') : '';
            html += '<tr data-idx="' + idx + '" data-idpel="' + escAdjHtml(row.IDPEL).toLowerCase() + '" data-nama="' + escAdjHtml(row.NAMA).toLowerCase() + '" data-area="' + escAdjHtml(row.AREA).toLowerCase() + '" data-tipetempo="' + escAdjHtml(row.TIPE_TEMPO) + '">' +
                '<td><input type="checkbox" class="adj-row-check" data-idpel="' + escAdjHtml(row.IDPEL) + '" checked></td>' +
                '<td>' + escAdjHtml(row.IDPEL) + '</td>' +
                '<td>' + escAdjHtml(row.NAMA) + '</td>' +
                '<td>' + escAdjHtml(row.AREA) + '</td>' +
                '<td>' + (row.TIPE_TEMPO === 'monthversary' ? 'Monthversary' : 'Rolling') + '</td>' +
                '<td><input type="date" class="form-control form-control-sm adj-field" data-field="tanggal_bayar_baru" value="' + escAdjHtml(row.tanggal_bayar_baru || '') + '"></td>' +
                '<td><input type="number" min="0" step="1" class="form-control form-control-sm adj-field" data-field="nominal_bayar_baru" value="' + (row.nominal_bayar_baru || '') + '"></td>' +
                '<td><input type="text" class="form-control form-control-sm adj-field" data-field="metode_bayar_baru" value="' + escAdjHtml(row.metode_bayar_baru || '') + '"></td>' +
                '<td><input type="date" class="form-control form-control-sm adj-field" data-field="tanggal_jatuh_tempo_baru" value="' + escAdjHtml(row.tanggal_jatuh_tempo_baru || '') + '"></td>' +
                '<td><input type="text" class="form-control form-control-sm adj-field" data-field="keterangan" value="' + escAdjHtml(row.keterangan || '') + '"></td>' +
                '<td class="text-warning small">' + escAdjHtml(warn) + '</td>' +
                '</tr>';
        });
        tbody.innerHTML = html;
    }

    document.getElementById('adjSelectAll').addEventListener('change', function() {
        const checked = this.checked;
        document.querySelectorAll('#adjPreviewBody tr').forEach(function(tr) {
            if (tr.style.display === 'none') return;
            const cb = tr.querySelector('.adj-row-check');
            if (cb) cb.checked = checked;
        });
    });

    // ------------------------------------------------------------------
    // Filter: cari teks (IDPEL/Nama/Area) + Tipe Tempo, murni di browser.
    // ------------------------------------------------------------------
    function applyAdjFilter() {
        const text = document.getElementById('adjFilterText').value.trim().toLowerCase();
        const tipeTempo = document.getElementById('adjFilterTipeTempo').value;
        document.querySelectorAll('#adjPreviewBody tr[data-idx]').forEach(function(tr) {
            const matchText = text === '' ||
                tr.getAttribute('data-idpel').indexOf(text) !== -1 ||
                tr.getAttribute('data-nama').indexOf(text) !== -1 ||
                tr.getAttribute('data-area').indexOf(text) !== -1;
            const matchTipe = tipeTempo === '' || tr.getAttribute('data-tipetempo') === tipeTempo;
            tr.style.display = (matchText && matchTipe) ? '' : 'none';
        });
    }
    document.getElementById('adjFilterText').addEventListener('input', applyAdjFilter);
    document.getElementById('adjFilterTipeTempo').addEventListener('change', applyAdjFilter);

    document.getElementById('btnAdjBackToStep1').addEventListener('click', function() {
        resetAdjModal();
    });

    // ------------------------------------------------------------------
    // STEP 2 -> STEP 3: execute (ambil nilai TERKINI dari input di tabel,
    // termasuk yang sudah diedit manual, bukan dari data hasil parse awal).
    // ------------------------------------------------------------------
    btnExecute.addEventListener('click', function() {
        const rowsToSend = [];
        document.querySelectorAll('#adjPreviewBody tr[data-idx]').forEach(function(tr) {
            const cb = tr.querySelector('.adj-row-check');
            if (!cb || !cb.checked) return;
            const payload = { idpel: cb.getAttribute('data-idpel') };
            tr.querySelectorAll('.adj-field').forEach(function(input) {
                payload[input.getAttribute('data-field')] = input.value;
            });
            rowsToSend.push(payload);
        });

        if (rowsToSend.length === 0) {
            alert('Pilih minimal 1 baris untuk diproses.');
            return;
        }
        if (!confirm('Terapkan penyesuaian untuk ' + rowsToSend.length + ' pelanggan terpilih? Tindakan ini akan langsung mengubah data (transaksi terakhir & jatuh tempo).')) {
            return;
        }

        const btn = this;
        setAdjBtnLoading(btn, true);

        const formData = new FormData();
        formData.append('action', 'execute');
        formData.append('rows', JSON.stringify(rowsToSend));

        fetch('getdata/adjust_tanggal_tempo_excel.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(result => {
                setAdjBtnLoading(btn, false);
                if (!result.success) {
                    alert(result.message || 'Gagal memproses.');
                    return;
                }

                let html = '<div class="alert alert-success">' +
                    'Selesai. Transaksi terakhir diubah: <strong>' + result.total_tx_updated + '</strong>, ' +
                    'Transaksi baru dibuat: <strong>' + result.total_tx_inserted + '</strong>, ' +
                    'Jatuh tempo diubah: <strong>' + result.total_due_date_updated + '</strong>, ' +
                    'Dilewati: <strong>' + result.total_dilewati + '</strong>, ' +
                    'Error: <strong>' + result.total_error + '</strong>' +
                    '</div>';

                if (result.errors && result.errors.length > 0) {
                    html += '<div class="alert alert-danger"><strong>Error:</strong><ul class="mb-0">';
                    result.errors.forEach(function(e) { html += '<li>' + escAdjHtml(e.idpel) + ': ' + escAdjHtml(e.reason) + '</li>'; });
                    html += '</ul></div>';
                }
                if (result.notes && result.notes.length > 0) {
                    html += '<div class="alert alert-secondary small"><strong>Detail:</strong><ul class="mb-0">';
                    result.notes.forEach(function(n) { html += '<li>' + escAdjHtml(n.idpel) + ' (' + escAdjHtml(n.nama) + '): ' + escAdjHtml(n.log.join(' | ')) + '</li>'; });
                    html += '</ul></div>';
                }

                document.getElementById('adjResultBox').innerHTML = html;
                document.getElementById('adjResultBox').classList.remove('d-none');
                step2.classList.add('d-none');
                btnExecute.classList.add('d-none');
            })
            .catch(() => {
                setAdjBtnLoading(btn, false);
                alert('Terjadi kesalahan saat memproses.');
            });
    });
});
</script>
<?php endif; ?>

<?php require 'footer.php'; ?>
