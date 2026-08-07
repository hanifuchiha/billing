



<?php
ob_start();



require 'header.php';

require_once __DIR__ . '/komisi_helper.php';
komisi_ensure_schema($conn);

if (!function_exists('e')) {
function e($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('komisi_parse_period_label_local')) {
function komisi_parse_period_label_local($periode) {
    $periode = trim((string) $periode);
    if ($periode === '') {
        return [0, 0];
    }
    $parts = preg_split('/\s+/', $periode);
    if (!$parts || count($parts) < 2) {
        return [0, 0];
    }
    $year = (int) array_pop($parts);
    $monthName = implode(' ', $parts);
    $monthMap = array_flip(komisi_month_names());
    $monthKey = $monthMap[$monthName] ?? null;
    if ($monthKey === null) {
        return [0, 0];
    }
    return [(int) $monthKey, $year];
}
}

if ($AKSES == 'ASSISTANT') {
    $cekserver = $GRUP;
} else {
    $cekserver = $ceknama;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax_cek_ids_area'])) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    $payload = [
        'ok' => true,
        'title' => 'Detail Rekap Komisi Area',
        'rows' => []
    ];

    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $_GET['ajax_cek_ids_area'])))));
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        $stmt = mysqli_prepare($conn, "SELECT id, nama, periode, server, kecamatan, kelurahan, rw, rt FROM transaksi_komisi WHERE id IN ($placeholders) AND COALESCE(komisi_kategori, 'regular')='area'");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, $types, ...$ids);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $titles = [];

            while ($trx = $res ? mysqli_fetch_assoc($res) : null) {
                $salesName = trim((string) ($trx['nama'] ?? ''));
                $periode = trim((string) ($trx['periode'] ?? ''));
                $owner = trim((string) ($trx['server'] ?? ''));
                if ($salesName === '' || $periode === '' || $owner === '') {
                    continue;
                }

                [$m, $y] = komisi_parse_period_label_local($periode);
                if ($m <= 0 || $y <= 0) {
                    continue;
                }

                $settings = komisi_get_cron_settings($conn, $owner);
                $mode = $settings['area_mode'] ?? 'persen';
                $summary = komisi_get_area_preview($conn, $owner, $m, $y, $mode, [
                    'kecamatan' => komisi_normalize_multiline_field($trx['kecamatan'] ?? ''),
                    'kelurahan' => komisi_normalize_multiline_field($trx['kelurahan'] ?? ''),
                    'rw' => komisi_normalize_multiline_field($trx['rw'] ?? ''),
                    'rt' => komisi_normalize_multiline_field($trx['rt'] ?? '')
                ]);

                foreach ((array) ($summary['rekap_mitra'] ?? []) as $mitra) {
                    if (trim((string) ($mitra['nama'] ?? '')) !== $salesName) {
                        continue;
                    }
                    foreach ((array) ($mitra['pelanggan'] ?? []) as $pel) {
                        $payload['rows'][] = [
                            'sales' => $salesName,
                            'periode' => $periode,
                            'idpel' => (string) ($pel['IDPEL'] ?? ''),
                            'nama' => (string) ($pel['NAMA'] ?? ''),
                            'paket' => (string) ($pel['PAKET'] ?? ''),
                            'alamat' => (string) ($pel['ALAMAT'] ?? ''),
                            'pembayaran' => (float) ($pel['pembayaran'] ?? 0)
                        ];
                    }
                }

                $titles[] = $salesName . ' - ' . $periode;
            }

            mysqli_stmt_close($stmt);
            $titles = array_values(array_unique(array_filter($titles)));
            if (!empty($titles)) {
                $payload['title'] = implode(' | ', $titles);
            }
        }
    }

    echo json_encode($payload);
    exit;
}

$cron_settings_notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_komisi_cron_settings'])) {
    $saveOk = komisi_save_cron_settings($conn, $cekserver, [
        'auto_area_enabled' => isset($_POST['auto_area_enabled']) ? 1 : 0,
        'area_mode' => $_POST['area_mode'] ?? 'persen',
        'area_run_day' => isset($_POST['area_run_day']) ? (int) $_POST['area_run_day'] : 1,
        'area_run_hour' => isset($_POST['area_run_hour']) ? (int) $_POST['area_run_hour'] : 1,
        'area_run_minute' => isset($_POST['area_run_minute']) ? (int) $_POST['area_run_minute'] : 0
    ]);
    komisi_sync_global_cron($conn, __DIR__);
    $cron_settings_notice = $saveOk
        ? '<div class="alert alert-success">Pengaturan auto komisi bulanan berhasil disimpan.</div>'
        : '<div class="alert alert-danger">Gagal menyimpan pengaturan auto komisi bulanan.</div>';
}
$komisiCronSettings = komisi_get_cron_settings($conn, $cekserver);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acc_id'])) {
    $id = (int) $_POST['acc_id'];
    $stmt = mysqli_prepare($conn, "UPDATE transaksi_komisi SET status='berhasil' WHERE id=? AND status='pending' AND server=? AND COALESCE(komisi_kategori, 'regular')='area'");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'is', $id, $cekserver);
        mysqli_stmt_execute($stmt);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hapus_id'])) {
    $id = (int) $_POST['hapus_id'];
    $stmt = mysqli_prepare($conn, "DELETE FROM transaksi_komisi WHERE id=? AND status='pending' AND server=? AND COALESCE(komisi_kategori, 'regular')='area'");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'is', $id, $cekserver);
        mysqli_stmt_execute($stmt);
    }
    echo '<script>alert("Data berhasil dihapus.");window.location.href=window.location.href;</script>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hapus_selected']) && isset($_POST['selected_ids'])) {
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) $_POST['selected_ids']))));
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids)) . 's';
        $params = $ids;
        $params[] = $cekserver;
        $stmt = mysqli_prepare($conn, "DELETE FROM transaksi_komisi WHERE id IN ($placeholders) AND status='pending' AND server=? AND COALESCE(komisi_kategori, 'regular')='area'");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, $types, ...$params);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }
    echo '<script>alert("Data terpilih berhasil dihapus.");window.location.href=window.location.href;</script>';
    exit;
}

$kecamatan = isset($_GET['kecamatan']) ? trim((string) $_GET['kecamatan']) : '';
$kelurahan = isset($_GET['kelurahan']) ? trim((string) $_GET['kelurahan']) : '';
$rw = isset($_GET['rw']) ? trim((string) $_GET['rw']) : '';
$rt = isset($_GET['rt']) ? trim((string) $_GET['rt']) : '';
$bulan = isset($_GET['bulan']) ? (int) $_GET['bulan'] : (int) date('m');
$tahun = isset($_GET['tahun']) ? (int) $_GET['tahun'] : (int) date('Y');
$bulan = max(1, min(12, $bulan));
$tahun = max(2020, min(2100, $tahun));
$komisi_mode = isset($_GET['komisi_mode']) && $_GET['komisi_mode'] === 'nominal' ? 'nominal' : 'persen';
$periode_komisi = komisi_build_period_label($bulan, $tahun);

$ownerEsc = $conn->real_escape_string($cekserver);
$q_kecamatan = $conn->query("SELECT DISTINCT kecamatan FROM pelanggan WHERE PEMILIK = '{$ownerEsc}' AND kecamatan != '' ORDER BY kecamatan");
if (!$q_kecamatan) { die('Query kecamatan error: ' . $conn->error); }
$q_kelurahan = $conn->query("SELECT DISTINCT kelurahan FROM pelanggan WHERE PEMILIK = '{$ownerEsc}' AND kelurahan != '' ORDER BY kelurahan");
if (!$q_kelurahan) { die('Query kelurahan error: ' . $conn->error); }
$q_rw = $conn->query("SELECT DISTINCT rw FROM pelanggan WHERE PEMILIK = '{$ownerEsc}' AND rw != '' ORDER BY rw");
if (!$q_rw) { die('Query rw error: ' . $conn->error); }
$q_rt = $conn->query("SELECT DISTINCT rt FROM pelanggan WHERE PEMILIK = '{$ownerEsc}' AND rt != '' ORDER BY rt");
if (!$q_rt) { die('Query rt error: ' . $conn->error); }

$summaryArea = komisi_get_area_preview($conn, $cekserver, $bulan, $tahun, $komisi_mode, [
    'kecamatan' => $kecamatan,
    'kelurahan' => $kelurahan,
    'rw' => $rw,
    'rt' => $rt
]);
$rekap = $summaryArea['rekap'];
$rekap_mitra = $summaryArea['rekap_mitra'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aksi_rekap'])) {
    $nama_mitra = isset($_POST['nama_mitra']) && is_array($_POST['nama_mitra']) ? $_POST['nama_mitra'] : [];
    $jabatan_rows = isset($_POST['jabatan']) && is_array($_POST['jabatan']) ? $_POST['jabatan'] : [];
    $kec_rows = isset($_POST['kecamatan']) && is_array($_POST['kecamatan']) ? $_POST['kecamatan'] : [];
    $kel_rows = isset($_POST['kelurahan']) && is_array($_POST['kelurahan']) ? $_POST['kelurahan'] : [];
    $rw_rows = isset($_POST['rw']) && is_array($_POST['rw']) ? $_POST['rw'] : [];
    $rt_rows = isset($_POST['rt']) && is_array($_POST['rt']) ? $_POST['rt'] : [];
    $total_rows = isset($_POST['total_pelanggan']) && is_array($_POST['total_pelanggan']) ? $_POST['total_pelanggan'] : [];
    $komisi_rows = isset($_POST['komisi']) && is_array($_POST['komisi']) ? $_POST['komisi'] : [];

    $inserted = 0;
    $updated = 0;
    $locked = 0;
    $skipped = 0;
    $errors = [];

    foreach ($nama_mitra as $i => $namaVal) {
        $upsert = komisi_upsert_transaksi($conn, [
            'komisi_kategori' => 'area',
            'nama' => $namaVal,
            'tanggal' => date('Y-m-d'),
            'periode' => $periode_komisi,
            'pembayaran' => isset($komisi_rows[$i]) ? (float) $komisi_rows[$i] : 0,
            'status' => 'pending',
            'server' => $cekserver,
            'jabatan' => $jabatan_rows[$i] ?? '',
            'kecamatan' => $kec_rows[$i] ?? '',
            'kelurahan' => $kel_rows[$i] ?? '',
            'rw' => $rw_rows[$i] ?? '',
            'rt' => $rt_rows[$i] ?? '',
            'total_pelanggan' => isset($total_rows[$i]) ? (int) $total_rows[$i] : 0
        ]);

        if (($upsert['status'] ?? '') === 'inserted') {
            $inserted++;
        } elseif (($upsert['status'] ?? '') === 'updated') {
            $updated++;
        } elseif (($upsert['status'] ?? '') === 'locked') {
            $locked++;
        } elseif (($upsert['status'] ?? '') === 'skipped') {
            $skipped++;
        } elseif (($upsert['status'] ?? '') === 'error') {
            $errors[] = $upsert['message'] ?? 'Unknown error';
        }
    }

    if (empty($errors)) {
        $msg = "Rekap tersimpan. Baru: {$inserted}, update: {$updated}, terkunci ACC: {$locked}, dilewati: {$skipped}.";
        echo '<script>alert(' . json_encode($msg) . ');window.location.href=window.location.pathname + window.location.search;</script>';
        exit;
    }
}

$q_pending = $conn->query("SELECT * FROM transaksi_komisi WHERE status='pending' AND server='" . $conn->real_escape_string($cekserver) . "' AND COALESCE(komisi_kategori, 'regular')='area' ORDER BY tanggal DESC");
$q_berhasil = $conn->query("SELECT * FROM transaksi_komisi WHERE status='berhasil' AND server='" . $conn->real_escape_string($cekserver) . "' AND COALESCE(komisi_kategori, 'regular')='area' ORDER BY tanggal DESC");
?>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
    .komisi-area-layout {
        max-width: 1800px;
        margin: 0 auto;
        padding-left: 1.25rem;
        padding-right: 1.25rem;
    }
    .komisi-area-panel {
        border: 0;
        border-radius: 14px;
        overflow: hidden;
    }
    .komisi-area-panel .card-header {
        border-bottom: 1px solid #e9edf3;
    }
    #rekapModal .modal-dialog {
        max-width: min(96vw, 1680px);
    }
    .table thead th {
        background: #0d6efd;
        color: #fff;
        vertical-align: middle;
        text-align: center;
        font-weight: 600;
        border-bottom: 2px solid #dee2e6;
    }
    .nav-tabs .nav-link {
        background: #0d6efd;
        color: #fff;
        border: 1px solid #0d6efd;
        border-bottom: none;
        font-weight: 600;
        margin-right: 2px;
        transition: background 0.2s, color 0.2s;
        box-shadow: 0 1px 0 rgba(13,110,253,0.08);
    }
    .nav-tabs .nav-link.active {
        background: #0d6efd;
        color: #fff;
        border-color: #0d6efd #0d6efd #fff;
        font-weight: 700;
        z-index: 2;
        box-shadow: 0 2px 6px rgba(13,110,253,0.12);
    }
    .nav-tabs .nav-link:hover,
    .nav-tabs .nav-link:focus {
        background: #1563c7 !important;
        color: #fff !important;
        text-decoration: none;
    }
    .nav-tabs {
        border-bottom: 2px solid #0d6efd;
    }
    .table-striped > tbody > tr:nth-of-type(odd) {
        background-color: #f8f9fa;
    }
    .table-bordered td, .table-bordered th {
        border: 1px solid #dee2e6 !important;
    }
    .table td, .table th {
        vertical-align: middle;
        text-align: center;
    }
    .table input[type=number] {
        max-width: 100px;
        margin: 0 auto;
    }
    .btn-info {
        background: #0dcaf0;
        border: none;
        color: #fff;
    }
    .btn-info:hover {
        background: #31d2f2;
        color: #fff;
    }
    .modal-content {
        border-radius: 1rem;
    }
    .table-responsive {
        margin-bottom: 1rem;
        overflow-x: auto;
    }
    .table-sm th, .table-sm td {
        padding: 0.45rem 0.5rem;
    }
    .table thead th, .table-info th {
        font-size: 1rem;
        letter-spacing: 0.5px;
    }
    .table tbody tr:hover {
        background: #e7f1ff;
    }
    .bulk-action-bar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 0.75rem;
        flex-wrap: wrap;
        margin-bottom: 0.75rem;
    }
    .action-wrap {
        display: flex;
        flex-wrap: wrap;
        gap: 0.35rem;
    }
</style>
<div class="container-fluid py-4 komisi-area-layout">
    <!-- Tab Navigasi Rekap -->
    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
               <a class="nav-link" href="rekappembayaranmitra.php" id="tabRekapMitra">REGULAR KOMISI CONTINUE</a>
        </li>
        <li class="nav-item">
               <a class="nav-link active" href="rekappembayaranmitra_area.php" id="tabRekapArea">PIC AREA CONTINUE KOMISI</a>
        </li>
        <li class="nav-item">
               <a class="nav-link" href="rekappembayaranmitra_awal.php" id="tabRekapAwal">ONE TME KOMISI</a>
        </li>
    </ul>
    <h3 class="mb-4">Rekap Pembayaran Mitra Berdasarkan Area</h3>
    <?php if (!empty($cron_settings_notice)) echo $cron_settings_notice; ?>

    <div class="card shadow-sm komisi-area-panel mb-4">
        <div class="card-header">
            <h5 class="card-title mb-0">Auto Komisi Area (Cron)</h5>
        </div>
        <div class="card-body">
            <form method="post" class="row g-3 align-items-end">
                        <input type="hidden" name="save_komisi_cron_settings" value="1">
                        <div class="col-md-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="auto_area_enabled" name="auto_area_enabled" <?php echo !empty($komisiCronSettings['auto_area_enabled']) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="auto_area_enabled">Auto Komisi Area</label>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label" for="area_mode">Mode Area</label>
                            <select class="form-select" id="area_mode" name="area_mode">
                                <option value="persen" <?php echo ($komisiCronSettings['area_mode'] === 'persen') ? 'selected' : ''; ?>>Persentase</option>
                                <option value="nominal" <?php echo ($komisiCronSettings['area_mode'] === 'nominal') ? 'selected' : ''; ?>>Nominal</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label" for="area_run_day">Tanggal</label>
                            <input type="number" min="1" max="28" class="form-control" id="area_run_day" name="area_run_day" value="<?php echo (int) $komisiCronSettings['area_run_day']; ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label" for="area_run_hour">Jam</label>
                            <input type="number" min="0" max="23" class="form-control" id="area_run_hour" name="area_run_hour" value="<?php echo (int) $komisiCronSettings['area_run_hour']; ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label" for="area_run_minute">Menit</label>
                            <input type="number" min="0" max="59" class="form-control" id="area_run_minute" name="area_run_minute" value="<?php echo (int) $komisiCronSettings['area_run_minute']; ?>">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">Simpan Cron</button>
                        </div>
            </form>
            <small class="text-muted d-block mt-2">Cron global berjalan setiap menit, tetapi komisi hanya dibuat pada tanggal dan jam yang ditentukan.</small>
        </div>
    </div>

    <div class="card shadow-sm komisi-area-panel mb-4">
        <div class="card-header">
            <h5 class="card-title mb-0">Filter Rekap Area</h5>
        </div>
        <div class="card-body">
            <form method="get" class="row g-2 mb-4">
        <div class="col-md-3">
            <label class="form-label">Kecamatan</label>
            <select name="kecamatan" class="form-select" onchange="this.form.submit()">
                <option value="">-- Semua --</option>
                <?php mysqli_data_seek($q_kecamatan, 0); while($k = $q_kecamatan->fetch_assoc()): ?>
                <option value="<?=e($k['kecamatan'])?>" <?=($kecamatan==$k['kecamatan'])?'selected':''?>><?=e($k['kecamatan'])?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Kelurahan</label>
            <select name="kelurahan" class="form-select" onchange="this.form.submit()">
                <option value="">-- Semua --</option>
                <?php mysqli_data_seek($q_kelurahan, 0); while($k = $q_kelurahan->fetch_assoc()): ?>
                <option value="<?=e($k['kelurahan'])?>" <?=($kelurahan==$k['kelurahan'])?'selected':''?>><?=e($k['kelurahan'])?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">RW</label>
            <select name="rw" class="form-select" onchange="this.form.submit()">
                <option value="">-- Semua --</option>
                <?php mysqli_data_seek($q_rw, 0); while($k = $q_rw->fetch_assoc()): ?>
                <option value="<?=e($k['rw'])?>" <?=($rw==$k['rw'])?'selected':''?>><?=e($k['rw'])?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">RT</label>
            <select name="rt" class="form-select" onchange="this.form.submit()">
                <option value="">-- Semua --</option>
                <?php mysqli_data_seek($q_rt, 0); while($k = $q_rt->fetch_assoc()): ?>
                <option value="<?=e($k['rt'])?>" <?=($rt==$k['rt'])?'selected':''?>><?=e($k['rt'])?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Tipe Komisi</label>
            <select name="komisi_mode" class="form-select" onchange="this.form.submit()">
                <option value="persen" <?= $komisi_mode=='persen'?'selected':'' ?>>Persentase</option>
                <option value="nominal" <?= $komisi_mode=='nominal'?'selected':'' ?>>Nominal</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Bulan</label>
            <select name="bulan" class="form-select">
                <?php foreach (komisi_month_names() as $noBulan => $namaBulan): ?>
                <option value="<?= (int) $noBulan ?>" <?= ((int) $noBulan === (int) $bulan) ? 'selected' : '' ?>><?= e($namaBulan) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Tahun</label>
            <select name="tahun" class="form-select">
                <?php for ($yy = date('Y') - 2; $yy <= date('Y') + 2; $yy++): ?>
                <option value="<?= $yy ?>" <?= ($yy == $tahun) ? 'selected' : '' ?>><?= $yy ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="col-md-2 d-flex align-items-end">
            <button type="submit" class="btn btn-primary w-100">Filter</button>
        </div>
            </form>
            <div class="mb-2 text-muted">Periode komisi: <strong><?= e($periode_komisi) ?></strong></div>
            <?php if ($kecamatan): ?>
            <div class="mb-3">
                <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#rekapModal">
                    <i class="bi bi-save"></i> Buat Rekap
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>

<!-- Modal Konfirmasi Rekap & Riwayat -->
<div class="modal fade" id="rekapModal" tabindex="-1" aria-labelledby="rekapModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="rekapModalLabel">Rekap & Riwayat Komisi Mitra</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <ul class="nav nav-tabs mb-3" id="rekapTab" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="rekap-tab" data-bs-toggle="tab" data-bs-target="#rekap" type="button" role="tab">Buat Rekap</button>
                    </li>
                </ul>
                <div class="tab-content" id="rekapTabContent">
                    <!-- Tab Rekap -->
                    <div class="tab-pane fade show active" id="rekap" role="tabpanel">
                        <form method="post">
                            <input type="hidden" name="aksi_rekap" value="1">
                          
                            <div class="table-responsive">
                                <table class="table table-bordered table-sm">
                                    <thead>
                                        <tr>
                                            <th>Nama Mitra</th>
                                            <th>Jabatan</th>
                                            <th>Kecamatan</th>
                                            <th>Kelurahan</th>
                                            <th>RW</th>
                                            <th>RT</th>
                                            <th>Total Pelanggan</th>
                                            <th>Total Pembayaran</th>
                                            <th>Komisi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php
                                    // Gunakan hasil ringkasan dari helper agar perhitungan komisi konsisten dengan periode filter.
                                    $idx_area = 0;
                                    foreach($rekap_mitra as $mitra) {
                                        // Pisahkan masing-masing field wilayah
                                        $kecamatan_list = [];
                                        $kelurahan_list = [];
                                        $rw_list = [];
                                        $rt_list = [];
                                        foreach($mitra['wilayah'] as $w) {
                                            $kecamatan_list[] = trim($w['kecamatan']);
                                            $kelurahan_list[] = trim($w['kelurahan']);
                                            $rw_list[] = trim($w['rw']);
                                            $rt_list[] = trim($w['rt']);
                                        }
                                        $kecamatan_str = implode('<br>', array_unique($kecamatan_list));
                                        $kelurahan_str = implode('<br>', array_unique($kelurahan_list));
                                        $rw_str = implode('<br>', array_unique($rw_list));
                                        $rt_str = implode('<br>', array_unique($rt_list));

                                        // Atur tampilan wilayah sesuai jabatan
                                        if (strtolower($mitra['jabatan']) == 'kepala camat') {
                                            $kelurahan_str = '-';
                                            $rw_str = '-';
                                            $rt_str = '-';
                                        } elseif (strtolower($mitra['jabatan']) == 'kepala lurah') {
                                            $rw_str = '-';
                                            $rt_str = '-';
                                        } elseif (strtolower($mitra['jabatan']) == 'kepala rw') {
                                            $rt_str = '-';
                                        }

                                        echo '<tr>';
                                        echo '<td>'.e($mitra['nama']).'</td>';
                                        echo '<td>'.e($mitra['jabatan']).'</td>';
                                        echo '<td>'.$kecamatan_str.'</td>';
                                        echo '<td>'.$kelurahan_str.'</td>';
                                        echo '<td>'.$rw_str.'</td>';
                                        echo '<td>'.$rt_str.'</td>';
                                        echo '<td>'.e($mitra['total_pelanggan']).'</td>';
                                        echo '<td>Rp '.number_format($mitra['total_pembayaran']).'</td>';
                                        // Komisi editable
                                        echo '<td><input type="number" name="komisi['.$idx_area.']" class="form-control form-control-sm" value="'.(float)$mitra['komisi'].'" min="0" step="any"></td>';
                                        // Hidden area info for POST
                                        echo '<input type="hidden" name="area_key['.$idx_area.']" value="">';
                                        echo '<input type="hidden" name="nama_mitra['.$idx_area.']" value="'.e($mitra['nama']).'">';
                                        echo '<input type="hidden" name="jabatan['.$idx_area.']" value="'.e($mitra['jabatan']).'">';
                                        echo '<input type="hidden" name="kecamatan['.$idx_area.']" value="'.e($kecamatan_str).'">';
                                        echo '<input type="hidden" name="kelurahan['.$idx_area.']" value="'.e($kelurahan_str).'">';
                                        echo '<input type="hidden" name="rw['.$idx_area.']" value="'.e($rw_str).'">';
                                        echo '<input type="hidden" name="rt['.$idx_area.']" value="'.e($rt_str).'">';
                                        echo '<input type="hidden" name="total_pelanggan['.$idx_area.']" value="'.(int)$mitra['total_pelanggan'].'">';
                                        echo '<input type="hidden" name="total_pembayaran['.$idx_area.']" value="'.(float)$mitra['total_pembayaran'].'">';
                                        $idx_area++;
                                        echo '</tr>';
                                    }
                                    ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="text-end">
                                <button type="submit" class="btn btn-success">Konfirmasi &amp; Simpan Rekap</button>
                            </div>
                        </form>
                    </div>
                    <!-- Tab Riwayat dihapus, riwayat akan dipindah ke bawah halaman -->
<!-- Card Riwayat Komisi Mitra di luar modal -->


                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>


<?php if ($kecamatan): ?>
    <div class="card shadow-sm komisi-area-panel mb-4">
        <div class="card-header">
            <h5 class="card-title mb-0">Rekap Area Berdasarkan Filter</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-striped align-middle">
                    <thead class="table-info">
                        <tr>
                            <th>Kecamatan</th>
                            <th>Kelurahan</th>
                            <th>RW</th>
                            <th>RT</th>
                            <th>Kepala Area</th>
                            <th>Jabatan</th>
                            <th>Total Pelanggan</th>
                            <th>Total Pembayaran</th>
                            <th>Komisi</th>
                            <th>Detail</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    // Tentukan level filter
                    $filter_level = '';
                    if ($rt) {
                        $filter_level = 'rt';
                    } elseif ($rw) {
                        $filter_level = 'rw';
                    } elseif ($kelurahan) {
                        $filter_level = 'kelurahan';
                    } elseif ($kecamatan) {
                        $filter_level = 'kecamatan';
                    }
                    // Gunakan ringkasan mitra dari helper (sudah sesuai periode dan mode komisi).
                    // Tampilkan tabel
                    foreach($rekap_mitra as $mitra):
                        // Pisahkan masing-masing field wilayah
                        $kecamatan_list = [];
                        $kelurahan_list = [];
                        $rw_list = [];
                        $rt_list = [];
                        foreach($mitra['wilayah'] as $w) {
                            $kecamatan_list[] = trim($w['kecamatan']);
                            $kelurahan_list[] = trim($w['kelurahan']);
                            $rw_list[] = trim($w['rw']);
                            $rt_list[] = trim($w['rt']);
                        }
                        // Unik dan gabung dengan <br>
                        $kecamatan_str = implode('<br>', array_unique($kecamatan_list));
                        $kelurahan_str = implode('<br>', array_unique($kelurahan_list));
                        $rw_str = implode('<br>', array_unique($rw_list));
                        $rt_str = implode('<br>', array_unique($rt_list));

                        // Atur tampilan wilayah sesuai jabatan
                        if (strtolower($mitra['jabatan']) == 'kepala camat') {
                            $kelurahan_str = '-';
                            $rw_str = '-';
                            $rt_str = '-';
                        } elseif (strtolower($mitra['jabatan']) == 'kepala lurah') {
                            $rw_str = '-';
                            $rt_str = '-';
                        } elseif (strtolower($mitra['jabatan']) == 'kepala rw') {
                            $rt_str = '-';
                        }
                    ?>
                        <tr>
                            <td><?=$kecamatan_str?></td>
                            <td><?=$kelurahan_str?></td>
                            <td><?=$rw_str?></td>
                            <td><?=$rt_str?></td>
                            <td><?=e($mitra['nama'])?></td>
                            <td><?=e($mitra['jabatan'])?></td>
                            <td><?=e($mitra['total_pelanggan'])?></td>
                            <td>Rp <?=number_format($mitra['total_pembayaran'])?></td>
                            <td>Rp <?=number_format($mitra['komisi'])?></td>
                            <td>
                                <button class="btn btn-sm btn-info" type="button" data-bs-toggle="collapse" data-bs-target="#detail<?=md5($mitra['nama'].$mitra['jabatan'])?>">Detail</button>
                            </td>
                        </tr>
                        <tr class="collapse" id="detail<?=md5($mitra['nama'].$mitra['jabatan'])?>">
                            <td colspan="10">
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered">
                                        <thead>
                                            <tr>
                                                <th>IDPEL</th>
                                                <th>Nama</th>
                                                <th>Paket</th>
                                                <th>Alamat</th>
                                                <th>Pembayaran Terakhir</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach($mitra['pelanggan'] as $p): ?>
                                            <tr>
                                                <td><?=e($p['IDPEL'])?></td>
                                                <td><?=e($p['NAMA'])?></td>
                                                <td><?=e($p['PAKET'])?></td>
                                                <td><?=e($p['ALAMAT'])?></td>
                                                <td>Rp <?=number_format($p['pembayaran'])?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                    
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>



<div class="card shadow-sm komisi-area-panel mt-4 mb-3">
    <!-- Filter Riwayat Komisi Mitra -->
    <form method="get" class="row g-2 align-items-end mb-4" id="filterRiwayatForm">
        <div class="col-md-4">
            <label class="form-label">Nama</label>
            <select name="nama_riwayat" class="form-select" id="namaRiwayatSelect">
                <option value="">-- Semua --</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Bulan</label>
            <select name="bulan_riwayat" class="form-select">
                <option value="0">-- Semua --</option>
                <?php for ($m=1;$m<=12;$m++): ?>
                <option value="<?php echo $m;?>" <?php if (isset($_GET['bulan_riwayat']) && $m==$_GET['bulan_riwayat']) echo 'selected';?>><?php echo $m;?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Tahun</label>
            <select name="tahun_riwayat" class="form-select">
                <option value="0">-- Semua --</option>
                <?php for ($y = date('Y'); $y >= date('Y')-10; $y--): ?>
                <option value="<?php echo $y;?>" <?php if (isset($_GET['tahun_riwayat']) && $y==$_GET['tahun_riwayat']) echo 'selected';?>><?php echo $y;?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-primary w-100">Filter</button>
        </div>
    </form>
    <div class="card-header bg-info text-white">
        <h5 class="mb-0">Riwayat Komisi Mitra</h5>
    </div>
    <div class="card-body">
        <div class="mb-3">
            <button id="tabPendingBtn" class="btn btn-primary btn-sm me-2" type="button">Pending</button>
            <button id="tabBerhasilBtn" class="btn btn-outline-primary btn-sm" type="button">Berhasil</button>
        </div>
        <div id="tabPending" style="display:block;">
            <form method="post" id="deleteFormArea">
                <div class="bulk-action-bar">
                    <button type="submit" name="hapus_selected" class="btn btn-danger btn-sm" onclick="return confirm('Yakin ingin menghapus data yang dipilih?')">Hapus Terpilih</button>
                    <small class="text-muted">Pilih checkbox baris yang akan dihapus, lalu klik Hapus Terpilih.</small>
                </div>
            <div class="table-responsive mb-4">
                <table class="table table-bordered table-striped align-middle">
                    <thead class="table-warning">
                        <tr>
                            <th><input type="checkbox" id="selectAllArea"></th>
                            <th>ID</th>
                            <th>Nama</th>
                            <th>Tanggal</th>
                            <th>Periode</th>
                            <th>Pembayaran</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="riwayatPendingTableBody">
                    <?php
                    // Filter riwayat
                    $where_riwayat = [
                        "status='pending'",
                        "server='" . $conn->real_escape_string($cekserver) . "'",
                        "COALESCE(komisi_kategori, 'regular')='area'"
                    ];
                    if (!empty($_GET['nama_riwayat'])) {
                        $nama_riwayat = $conn->real_escape_string($_GET['nama_riwayat']);
                        $where_riwayat[] = "nama='".$nama_riwayat."'";
                    }
                    if (!empty($_GET['bulan_riwayat']) && intval($_GET['bulan_riwayat'])>0) {
                        $where_riwayat[] = "MONTH(tanggal)=".intval($_GET['bulan_riwayat']);
                    }
                    if (!empty($_GET['tahun_riwayat']) && intval($_GET['tahun_riwayat'])>0) {
                        $where_riwayat[] = "YEAR(tanggal)=".intval($_GET['tahun_riwayat']);
                    }
                    $where_riwayat_sql = $where_riwayat ? ('WHERE '.implode(' AND ',$where_riwayat)) : '';

                    // Lazy-load: 20 baris per fetch (self-GET ke halaman ini sendiri
                    // dengan filter yang sama + parameter ppage_pending) supaya
                    // riwayat komisi yang besar tidak dirender sekaligus.
                    $pendingPageSize = 20;
                    $pendingPage = isset($_GET['ppage_pending']) ? (int)$_GET['ppage_pending'] : 1;
                    if ($pendingPage < 1) $pendingPage = 1;
                    $pendingCountRow = $conn->query("SELECT COUNT(*) AS total FROM transaksi_komisi $where_riwayat_sql")->fetch_assoc();
                    $pendingTotalRows = (int)($pendingCountRow['total'] ?? 0);
                    $pendingTotalPages = max(1, (int)ceil($pendingTotalRows / $pendingPageSize));
                    $pendingPage = min($pendingPage, $pendingTotalPages);
                    $pendingOffset = ($pendingPage - 1) * $pendingPageSize;

                    $sql_pending2 = "SELECT * FROM transaksi_komisi $where_riwayat_sql ORDER BY tanggal DESC LIMIT $pendingPageSize OFFSET $pendingOffset";
                    $q_pending2 = $conn->query($sql_pending2);
                    $cekappkeuangan = "../keuangan/index.php";
                    $app_keuangan_exists = file_exists($cekappkeuangan);
                    $AKSES = isset($AKSES) ? $AKSES : '';
                    if ($q_pending2) {
                        while(($row = $q_pending2->fetch_assoc())) {
                            if (!is_array($row)) continue;
                    ?>
                        <tr>
                            <td><input type="checkbox" name="selected_ids[]" value="<?=e($row['id'])?>"></td>
                            <td><?=e($row['id'])?></td>
                            <td><?=e($row['nama'])?></td>
                            <td><?=e($row['tanggal'])?></td>
                            <td><?=e($row['periode'])?></td>
                            <td>Rp<?=number_format($row['pembayaran'],0,',','.')?></td>
                            <td><span class="badge bg-warning text-dark">Pending</span></td>
                            <td>
                                <div class="action-wrap">
                                <button type="submit" name="hapus_id" value="<?=e($row['id'])?>" class="btn btn-sm btn-danger" onclick="return confirm('Yakin ingin menghapus data ini?')">Hapus</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary js-btn-cek-area" data-ids="<?=e($row['id'])?>" data-bs-toggle="modal" data-bs-target="#modalCekArea">Cek</button>
                                <?php if ( $app_keuangan_exists && $AKSES == 'ADMIN' ) { ?>
                                    <a type="button" href="../keuangan/accrekapsales.php" class="btn btn-sm btn-primary">rekap sudah bayar</a>
                                <?php } elseif( $AKSES == 'ADMIN' || $AKSES == 'ASSISTANT' || $AKSES == 'USER' ) { ?>
                                    <button type="submit" name="acc_id" value="<?=e($row['id'])?>" class="btn btn-sm btn-primary" onclick="return confirm('ACC pembayaran ini?')">rekap sudah bayar</button>
                                <?php } ?>
                                </div>
                            </td>
                        </tr>
                    <?php
                        }
                    }
                    ?>
                    <tr id="riwayatPendingLazySentinel" style="height:1px;"><td colspan="8" style="padding:0;border:0;"></td></tr>
                    </tbody>
                </table>
            </div>
            <div id="riwayatPendingLazyLoadWrap" class="text-center py-3 <?php echo $pendingPage >= $pendingTotalPages ? 'd-none' : ''; ?>">
                <div id="riwayatPendingLazyLoadIndicator" class="spinner-border spinner-border-sm text-primary d-none" role="status"><span class="visually-hidden">Loading...</span></div>
                <span id="riwayatPendingLazyLoadStatusText" class="text-secondary text-xs"></span>
            </div>
            <div id="riwayatPendingLazyMeta" class="d-none" data-page="<?php echo (int)$pendingPage; ?>" data-total-pages="<?php echo (int)$pendingTotalPages; ?>"></div>
            </form>
        </div>
        <div id="tabBerhasil" style="display:none;">
            <div class="table-responsive">
                <table class="table table-bordered table-striped align-middle">
                    <thead class="table-success">
                        <tr>
                            <th>ID</th>
                            <th>Nama</th>
                            <th>Tanggal</th>
                            <th>Periode</th>
                            <th>Pembayaran</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="riwayatBerhasilTableBody">
                    <?php
                    // Filter riwayat berhasil
                    $where_riwayat2 = [
                        "status='berhasil'",
                        "server='" . $conn->real_escape_string($cekserver) . "'",
                        "COALESCE(komisi_kategori, 'regular')='area'"
                    ];
                    if (!empty($_GET['nama_riwayat'])) {
                        $nama_riwayat = $conn->real_escape_string($_GET['nama_riwayat']);
                        $where_riwayat2[] = "nama='".$nama_riwayat."'";
                    }
                    if (!empty($_GET['bulan_riwayat']) && intval($_GET['bulan_riwayat'])>0) {
                        $where_riwayat2[] = "MONTH(tanggal)=".intval($_GET['bulan_riwayat']);
                    }
                    if (!empty($_GET['tahun_riwayat']) && intval($_GET['tahun_riwayat'])>0) {
                        $where_riwayat2[] = "YEAR(tanggal)=".intval($_GET['tahun_riwayat']);
                    }
                    $where_riwayat2_sql = $where_riwayat2 ? ('WHERE '.implode(' AND ',$where_riwayat2)) : '';

                    // Lazy-load sama seperti tabel Pending di atas.
                    $berhasilPageSize = 20;
                    $berhasilPage = isset($_GET['ppage_berhasil']) ? (int)$_GET['ppage_berhasil'] : 1;
                    if ($berhasilPage < 1) $berhasilPage = 1;
                    $berhasilCountRow = $conn->query("SELECT COUNT(*) AS total FROM transaksi_komisi $where_riwayat2_sql")->fetch_assoc();
                    $berhasilTotalRows = (int)($berhasilCountRow['total'] ?? 0);
                    $berhasilTotalPages = max(1, (int)ceil($berhasilTotalRows / $berhasilPageSize));
                    $berhasilPage = min($berhasilPage, $berhasilTotalPages);
                    $berhasilOffset = ($berhasilPage - 1) * $berhasilPageSize;

                    $sql_berhasil2 = "SELECT * FROM transaksi_komisi $where_riwayat2_sql ORDER BY tanggal DESC LIMIT $berhasilPageSize OFFSET $berhasilOffset";
                    $q_berhasil2 = $conn->query($sql_berhasil2);
                    if ($q_berhasil2) {
                        while(($row = $q_berhasil2->fetch_assoc())) {
                            if (!is_array($row)) continue;
                    ?>
                        <tr>
                            <td><?=e($row['id'])?></td>
                            <td><?=e($row['nama'])?></td>
                            <td><?=e($row['tanggal'])?></td>
                            <td><?=e($row['periode'])?></td>
                            <td>Rp<?=number_format($row['pembayaran'],0,',','.')?></td>
                            <td><span class="badge bg-success">Berhasil</span></td>
                            <td>-</td>
                        </tr>
                    <?php
                        }
                    }
                    ?>
                    <tr id="riwayatBerhasilLazySentinel" style="height:1px;"><td colspan="7" style="padding:0;border:0;"></td></tr>
                    </tbody>
                </table>
            </div>
            <div id="riwayatBerhasilLazyLoadWrap" class="text-center py-3 <?php echo $berhasilPage >= $berhasilTotalPages ? 'd-none' : ''; ?>">
                <div id="riwayatBerhasilLazyLoadIndicator" class="spinner-border spinner-border-sm text-primary d-none" role="status"><span class="visually-hidden">Loading...</span></div>
                <span id="riwayatBerhasilLazyLoadStatusText" class="text-secondary text-xs"></span>
            </div>
            <div id="riwayatBerhasilLazyMeta" class="d-none" data-page="<?php echo (int)$berhasilPage; ?>" data-total-pages="<?php echo (int)$berhasilTotalPages; ?>"></div>
        </div>
    </div>
</div>
<script>
// Tab manual tanpa Bootstrap
document.addEventListener('DOMContentLoaded', function() {
    var btnPending = document.getElementById('tabPendingBtn');
    var btnBerhasil = document.getElementById('tabBerhasilBtn');
    var tabPending = document.getElementById('tabPending');
    var tabBerhasil = document.getElementById('tabBerhasil');
    btnPending.addEventListener('click', function() {
        btnPending.classList.add('btn-primary');
        btnPending.classList.remove('btn-outline-primary');
        btnBerhasil.classList.remove('btn-primary');
        btnBerhasil.classList.add('btn-outline-primary');
        tabPending.style.display = 'block';
        tabBerhasil.style.display = 'none';
    });
    btnBerhasil.addEventListener('click', function() {
        btnBerhasil.classList.add('btn-primary');
        btnBerhasil.classList.remove('btn-outline-primary');
        btnPending.classList.remove('btn-primary');
        btnPending.classList.add('btn-outline-primary');
        tabPending.style.display = 'none';
        tabBerhasil.style.display = 'block';
    });

    var selectAllArea = document.getElementById('selectAllArea');
    if (selectAllArea) {
        selectAllArea.addEventListener('change', function() {
            var checked = selectAllArea.checked;
            // Baris yang belum ke-scroll (lazy-load) belum ada checkbox-nya di DOM -
            // muat semua dulu supaya "select all" benar-benar mencakup semua data
            // pending yang cocok dengan filter, bukan cuma yang kebetulan sudah tampil.
            var loadAll = (checked && typeof window.riwayatPendingLoadAllRemaining === 'function')
                ? window.riwayatPendingLoadAllRemaining()
                : Promise.resolve();
            loadAll.then(function() {
                var checkboxes = document.querySelectorAll('#deleteFormArea input[name="selected_ids[]"]');
                checkboxes.forEach(function(checkbox) {
                    checkbox.checked = checked;
                });
                selectAllArea.checked = checked;
            });
        });
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatRupiah(num) {
        return 'Rp ' + Number(num || 0).toLocaleString('id-ID');
    }

    var areaModalEl = document.getElementById('modalCekArea');
    if (areaModalEl) {
        areaModalEl.addEventListener('show.bs.modal', function(event) {
            var trigger = event.relatedTarget;
            var ids = trigger ? (trigger.getAttribute('data-ids') || '') : '';
            var titleEl = areaModalEl.querySelector('.modal-title');
            var bodyEl = areaModalEl.querySelector('tbody');

            if (!ids) {
                titleEl.textContent = 'Cek Detail Rekap Area';
                bodyEl.innerHTML = '<tr><td colspan="7" class="text-center text-muted">Data rekap tidak ditemukan.</td></tr>';
                return;
            }

            titleEl.textContent = 'Cek Detail Rekap Area (Memuat...)';
            bodyEl.innerHTML = '<tr><td colspan="7" class="text-center text-muted">Memuat detail data...</td></tr>';

            fetch(window.location.pathname + '?ajax_cek_ids_area=' + encodeURIComponent(ids), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(function(resp) {
                    if (!resp.ok) {
                        throw new Error('HTTP ' + resp.status);
                    }
                    return resp.text();
                })
                .then(function(text) {
                    var jsonStart = text.indexOf('{');
                    if (jsonStart > 0) {
                        text = text.slice(jsonStart);
                    }
                    return JSON.parse(text);
                })
                .then(function(data) {
                    titleEl.textContent = 'Cek Detail Rekap Area: ' + (data.title || '-');
                    if (!data.rows || data.rows.length === 0) {
                        bodyEl.innerHTML = '<tr><td colspan="7" class="text-center text-muted">Belum ada detail sumber untuk rekap ini.</td></tr>';
                        return;
                    }
                    var rowsHtml = data.rows.map(function(row) {
                        return '<tr>' +
                            '<td>' + escapeHtml(row.sales) + '</td>' +
                            '<td>' + escapeHtml(row.periode) + '</td>' +
                            '<td>' + escapeHtml(row.idpel) + '</td>' +
                            '<td>' + escapeHtml(row.nama) + '</td>' +
                            '<td>' + escapeHtml(row.paket) + '</td>' +
                            '<td>' + escapeHtml(row.alamat) + '</td>' +
                            '<td>' + formatRupiah(row.pembayaran) + '</td>' +
                        '</tr>';
                    }).join('');
                    bodyEl.innerHTML = rowsHtml;
                })
                .catch(function() {
                    titleEl.textContent = 'Cek Detail Rekap Area';
                    bodyEl.innerHTML = '<tr><td colspan="7" class="text-center text-danger">Gagal memuat detail data.</td></tr>';
                });
        });
    }
});
</script>

<!-- ============================================================
     LAZY LOAD: tabel Pending & Berhasil (Riwayat Area) direveal
     bertahap 20/halaman lewat self-GET ke halaman ini sendiri.
     ============================================================ -->
<script>
function createRiwayatAreaLazyLoad(opts) {
    var currentPage = opts.initialPage;
    var totalPages  = opts.totalPages;
    var isLoading = false;

    var tableBody = document.getElementById(opts.tableBodyId);
    var sentinelRow = document.getElementById(opts.sentinelId);
    var lazyWrap = document.getElementById(opts.wrapId);
    var lazyIndicator = document.getElementById(opts.indicatorId);
    var lazyStatusText = document.getElementById(opts.statusTextId);

    if (!tableBody || !sentinelRow) return function() { return Promise.resolve(); };

    function updateStatusText() {
        if (!lazyStatusText) return;
        lazyStatusText.textContent = (currentPage >= totalPages) ? 'Semua data sudah dimuat.' : '';
    }

    function buildNextPageUrl(nextPage) {
        var url = new URL(window.location.href);
        url.searchParams.set(opts.pageParam, String(nextPage));
        return url.toString();
    }

    function appendNextPage() {
        if (isLoading || currentPage >= totalPages) return Promise.resolve();
        isLoading = true;
        if (lazyWrap) lazyWrap.classList.remove('d-none');
        if (lazyIndicator) lazyIndicator.classList.remove('d-none');

        return fetch(buildNextPageUrl(currentPage + 1), { method: 'GET', credentials: 'same-origin' })
            .then(function(res) { return res.text(); })
            .then(function(html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var newTableBody = doc.getElementById(opts.tableBodyId);
                var newMeta = doc.getElementById(opts.metaId);
                if (!newTableBody) throw new Error('Gagal memuat data');

                var newSentinel = newTableBody.querySelector('#' + opts.sentinelId);
                if (newSentinel) newSentinel.remove();
                tableBody.insertAdjacentHTML('beforeend', newTableBody.innerHTML);
                tableBody.appendChild(sentinelRow);

                var parsedPage = newMeta ? parseInt(newMeta.getAttribute('data-page'), 10) : NaN;
                currentPage = !isNaN(parsedPage) ? parsedPage : (currentPage + 1);
                updateStatusText();
                if (currentPage >= totalPages && lazyWrap) lazyWrap.classList.add('d-none');
            })
            .catch(function(err) { console.error('Gagal lazy load:', err); })
            .finally(function() {
                isLoading = false;
                if (lazyIndicator) lazyIndicator.classList.add('d-none');
            });
    }

    function loadAllRemaining() {
        function step() {
            if (currentPage >= totalPages) return Promise.resolve();
            return appendNextPage().then(step);
        }
        return step();
    }

    if ('IntersectionObserver' in window) {
        var observer = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) appendNextPage();
            });
        }, { root: null, rootMargin: '0px 0px 300px 0px', threshold: 0 });
        observer.observe(sentinelRow);
    }

    window.addEventListener('scroll', function() {
        if (isLoading || currentPage >= totalPages) return;
        var nearBottom = window.innerHeight + window.scrollY >= document.body.offsetHeight - 300;
        if (nearBottom) appendNextPage();
    }, { passive: true });

    updateStatusText();
    return loadAllRemaining;
}

window.riwayatPendingLoadAllRemaining = createRiwayatAreaLazyLoad({
    tableBodyId: 'riwayatPendingTableBody',
    sentinelId: 'riwayatPendingLazySentinel',
    wrapId: 'riwayatPendingLazyLoadWrap',
    indicatorId: 'riwayatPendingLazyLoadIndicator',
    statusTextId: 'riwayatPendingLazyLoadStatusText',
    metaId: 'riwayatPendingLazyMeta',
    pageParam: 'ppage_pending',
    initialPage: <?php echo (int)$pendingPage; ?>,
    totalPages: <?php echo (int)$pendingTotalPages; ?>
});

window.riwayatBerhasilLoadAllRemaining = createRiwayatAreaLazyLoad({
    tableBodyId: 'riwayatBerhasilTableBody',
    sentinelId: 'riwayatBerhasilLazySentinel',
    wrapId: 'riwayatBerhasilLazyLoadWrap',
    indicatorId: 'riwayatBerhasilLazyLoadIndicator',
    statusTextId: 'riwayatBerhasilLazyLoadStatusText',
    metaId: 'riwayatBerhasilLazyMeta',
    pageParam: 'ppage_berhasil',
    initialPage: <?php echo (int)$berhasilPage; ?>,
    totalPages: <?php echo (int)$berhasilTotalPages; ?>
});
</script>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<div class="modal fade" id="modalCekArea" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Cek Detail Rekap Area</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-sm align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Mitra</th>
                                <th>Periode</th>
                                <th>IDPEL</th>
                                <th>Nama</th>
                                <th>Paket</th>
                                <th>Alamat</th>
                                <th>Pembayaran Terakhir</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td colspan="7" class="text-center text-muted">Pilih data lalu klik Cek untuk memuat detail.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>
<script>
// Isi select nama filter riwayat dengan AJAX
document.addEventListener('DOMContentLoaded', function() {
    var select = document.getElementById('namaRiwayatSelect');
    if (select) {
        fetch('mitra_list.php')
        .then(r => r.json())
        .then(data => {
            data.forEach(function(nama) {
                var opt = document.createElement('option');
                opt.value = nama;
                opt.textContent = nama;
                if (new URLSearchParams(window.location.search).get('nama_riwayat') === nama) opt.selected = true;
                select.appendChild(opt);
            });
        });
    }
});
</script>
<?php require 'footer.php'; ?>

