<?php
ob_start();
// Tampilkan error PHP untuk debugging
// error_reporting(E_ALL);
// ini_set('display_errors', 1);
include 'header.php';

if ($AKSES == 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('Pembayaran_Komisi', $akses_menu, true)) {
        echo '<div class="container-fluid py-4"><div class="alert alert-danger">Anda tidak memiliki akses ke menu Pembayaran Komisi.</div></div>';
        require 'footer.php';
        exit;
    }
}

require_once __DIR__ . '/komisi_helper.php';
komisi_ensure_schema($conn);

// Helper e() jika belum ada
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

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax_cek_ids'])) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $_GET['ajax_cek_ids'])))));
    $payload = [
        'ok' => true,
        'title' => 'Detail Rekap Komisi Sales',
        'rows' => []
    ];

    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        $stmt = mysqli_prepare($conn, "SELECT id, nama, periode, server FROM transaksi_komisi WHERE id IN ($placeholders) AND COALESCE(komisi_kategori, 'regular') = 'regular'");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, $types, ...$ids);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $contexts = [];

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
                $mode = $settings['regular_mode'] ?? 'persen';
                $preview = komisi_get_regular_preview($conn, $owner, $salesName, $m, $y, $mode);

                foreach ($preview['sudah_bayar'] as $detail) {
                    $payload['rows'][] = [
                        'sales' => (string) $salesName,
                        'periode' => (string) $periode,
                        'idpel' => (string) ($detail['IDPEL'] ?? ''),
                        'nama' => (string) ($detail['NAMA'] ?? ''),
                        'paket' => (string) ($detail['PAKET'] ?? ''),
                        'harga' => (float) ($detail['HARGA'] ?? 0),
                        'tanggal_bayar' => (string) ($detail['TANGGALBAYAR'] ?? ''),
                        'komisi' => (float) ($detail['komisi'] ?? 0),
                        'bukti' => (string) ($detail['BUKTI'] ?? '')
                    ];
                }

                $contexts[] = $salesName . ' - ' . $periode;
            }

            mysqli_stmt_close($stmt);
            $contexts = array_values(array_unique($contexts));
            if (!empty($contexts)) {
                $payload['title'] = implode(' | ', $contexts);
            }
        }
    }

    echo json_encode($payload);
    exit;
}

// Handle ACC action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acc_id'])) {
    $id = (int)$_POST['acc_id'];
    // Fetch the komisi record
    $stmt = mysqli_prepare($conn, 'SELECT `id`,`status`,`nama` FROM `transaksi_komisi` WHERE `id` = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);
    if ($row && $row['status'] !== 'berhasil') {
        $u = mysqli_prepare($conn, 'UPDATE `transaksi_komisi` SET `status` = "berhasil" WHERE `id` = ?');
        mysqli_stmt_bind_param($u, 'i', $id);
        if (mysqli_stmt_execute($u)) {
            $acc_success = true;
        } else {
            $acc_error = mysqli_stmt_error($u);
        }
    }
}

// Handle ACC selected
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acc_ids'])) {
    $ids_str = $_POST['acc_ids'];
    $ids = explode(',', $ids_str);
    $success_count = 0;
    $error_msgs = [];
    foreach ($ids as $id) {
        $id = (int)trim($id);
        if ($id > 0) {
            $stmt = mysqli_prepare($conn, 'UPDATE `transaksi_komisi` SET `status` = "berhasil" WHERE `id` = ? AND `status` = "pending"');
            mysqli_stmt_bind_param($stmt, 'i', $id);
            if (mysqli_stmt_execute($stmt)) {
                $success_count++;
            } else {
                $error_msgs[] = mysqli_stmt_error($stmt);
            }
        }
    }
    if ($success_count > 0) {
        $acc_success = "ACC berhasil untuk $success_count transaksi!";
    }
    if (!empty($error_msgs)) {
        $acc_error = implode('; ', $error_msgs);
    }
}

// Handle delete selected
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hapus_selected']) && isset($_POST['selected_ids'])) {
    $selected_ids = $_POST['selected_ids'];
    $all_ids = [];
    foreach ($selected_ids as $ids_str) {
        $ids = explode(',', $ids_str);
        $all_ids = array_merge($all_ids, array_map('intval', array_filter($ids)));
    }
    $all_ids = array_unique($all_ids);
    if (!empty($all_ids)) {
        $placeholders = str_repeat('?,', count($all_ids) - 1) . '?';
        $types = str_repeat('i', count($all_ids)) . 's';
        $params = $all_ids;
        $params[] = $cekserver;
        $stmt = mysqli_prepare($conn, "DELETE FROM `transaksi_komisi` WHERE `id` IN ($placeholders) AND `status`='pending' AND `server`=? AND COALESCE(`komisi_kategori`, 'regular')='regular'");
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        if (mysqli_stmt_execute($stmt)) {
            $delete_success = count($all_ids) . " data berhasil dihapus!";
        } else {
            $delete_error = mysqli_stmt_error($stmt);
        }
    }
}


if($AKSES=='ASSISTANT')
{
$cekserver=$GRUP;
}
else
{
    $cekserver=$ceknama;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hapus_ids'])) {
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $_POST['hapus_ids'])))));
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids)) . 's';
        $params = $ids;
        $params[] = $cekserver;
        $stmt = mysqli_prepare($conn, "DELETE FROM transaksi_komisi WHERE id IN ($placeholders) AND status='pending' AND server=? AND COALESCE(komisi_kategori, 'regular')='regular'");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, $types, ...$params);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }
    echo '<script>alert("Data berhasil dihapus.");window.location.href=window.location.href;</script>';
    exit;
}

$cron_settings_notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_komisi_cron_settings'])) {
    $saveOk = komisi_save_cron_settings($conn, $cekserver, [
        'auto_regular_enabled' => isset($_POST['auto_regular_enabled']) ? 1 : 0,
        'regular_mode' => $_POST['regular_mode'] ?? 'persen',
        'regular_run_day' => isset($_POST['regular_run_day']) ? (int) $_POST['regular_run_day'] : 1,
        'regular_run_hour' => isset($_POST['regular_run_hour']) ? (int) $_POST['regular_run_hour'] : 1,
        'regular_run_minute' => isset($_POST['regular_run_minute']) ? (int) $_POST['regular_run_minute'] : 0
    ]);
    komisi_sync_global_cron($conn, __DIR__);
    $cron_settings_notice = $saveOk
        ? '<div class="alert alert-success">Pengaturan auto komisi bulanan berhasil disimpan.</div>'
        : '<div class="alert alert-danger">Gagal menyimpan pengaturan auto komisi bulanan.</div>';
}

$komisiCronSettings = komisi_get_cron_settings($conn, $cekserver);


// Handle add rekap form from Buatrekapsales.php
if (isset($_POST['submit_rekap'])) {
    $upsert = komisi_upsert_transaksi($conn, [
        'komisi_kategori' => 'regular',
        'nama' => $_POST['nama'] ?? '',
        'tanggal' => $_POST['tanggal'] ?? date('Y-m-d'),
        'periode' => $_POST['periode'] ?? '',
        'pembayaran' => isset($_POST['jumlah']) ? (float) $_POST['jumlah'] : 0,
        'status' => $_POST['status'] ?? 'pending',
        'server' => $cekserver,
        'total_pelanggan' => isset($_POST['total_bayar']) ? (int) $_POST['total_bayar'] : 0
    ]);

    if (($upsert['status'] ?? '') === 'inserted' || ($upsert['status'] ?? '') === 'updated') {
        echo "<script>alert('Rekap berhasil disimpan!'); window.location.href=window.location.href;</script>";
        exit;
    } elseif (($upsert['status'] ?? '') === 'locked') {
        echo "<script>alert('Rekap periode ini sudah berhasil di-ACC, tidak dapat diubah otomatis.');</script>";
    } elseif (($upsert['status'] ?? '') === 'skipped') {
        echo "<script>alert('Rekap tidak disimpan karena nominal komisi 0 atau data belum lengkap.');</script>";
    } else {
        $errMsg = isset($upsert['message']) ? $upsert['message'] : $conn->error;
        echo "<script>alert('Gagal menyimpan rekap: " . addslashes($errMsg) . "');</script>";
    }
}

// Filter for card 1 (rekap)
$sales_login = isset($_GET['sales']) ? $_GET['sales'] : '';
$bulan = isset($_GET['bulan']) ? (int)$_GET['bulan'] : (int)date('m');
$tahun = isset($_GET['tahun']) ? (int)$_GET['tahun'] : (int)date('Y');
$komisi_mode = isset($_GET['komisi_mode']) && $_GET['komisi_mode'] == 'nominal' ? 'nominal' : 'persen';

$bulan_indonesia = komisi_month_names();

$bln = str_pad($bulan, 2, '0', STR_PAD_LEFT);
$penggunaan = komisi_build_period_label($bulan, $tahun);

// Ambil daftar sales (mitra)
$q_sales = $conn->query("SELECT nama AS sales FROM mitra WHERE `server`='" . $conn->real_escape_string($cekserver) . "'");

// Data for card 1
$data = [
    'sudah_bayar' => [],
    'belum_bayar' => []
];

$total_bayar = 0;
$total_belum = 0;
$total_komisi = 0;
$total_harga_belum_bayar = 0;

if ($sales_login !== '') {
    $previewRegular = komisi_get_regular_preview($conn, $cekserver, $sales_login, $bulan, $tahun, $komisi_mode);
    $data = [
        'sudah_bayar' => $previewRegular['sudah_bayar'],
        'belum_bayar' => $previewRegular['belum_bayar']
    ];
    $total_bayar = (int) $previewRegular['total_bayar'];
    $total_belum = (int) $previewRegular['total_belum'];
    $total_komisi = (float) $previewRegular['total_komisi'];
    $total_harga_belum_bayar = (float) $previewRegular['total_harga_belum_bayar'];
}

// Filter for card 2 (transaksi)
$sales_trans = isset($_GET['sales_trans']) ? $_GET['sales_trans'] : '';
$bulan_trans = isset($_GET['bulan_trans']) ? (int)$_GET['bulan_trans'] : 0;
$tahun_trans = isset($_GET['tahun_trans']) ? (int)$_GET['tahun_trans'] : 0;

// Query pending from transaksi_komisi, filter by sales_trans if selected
$where_pending = [];
$params_pending = [];
if ($sales_trans !== '') {
    $where_pending[] = '`nama` = ?';
    $params_pending[] = $sales_trans;
}
if ($bulan_trans >= 1 && $bulan_trans <= 12) {
    $where_pending[] = 'MONTH(`tanggal`) = ?';
    $params_pending[] = $bulan_trans;
}
if ($tahun_trans > 0) {
    $where_pending[] = 'YEAR(`tanggal`) = ?';
    $params_pending[] = $tahun_trans;
}
$where_pending[] = '`status` = ?';
$params_pending[] = 'pending';
$where_pending[] = "`nama` IN (SELECT nama FROM mitra WHERE `server` ='$cekserver')";
$where_pending[] = "COALESCE(`komisi_kategori`, 'regular') = 'regular'";


$sql_pending = "SELECT `nama`, `periode`, SUM(`pembayaran`) as total_pembayaran, COUNT(*) as jumlah_transaksi, GROUP_CONCAT(`id`) as ids FROM `transaksi_komisi`";
if ($where_pending) {
    $sql_pending .= " WHERE " . implode(' AND ', $where_pending);
}
$sql_pending .= ' GROUP BY `nama`, `periode` ORDER BY `nama`, `periode` DESC LIMIT 500';
$stmt_pending = mysqli_prepare($conn, $sql_pending);
if ($params_pending) {
    $types = '';
    foreach ($params_pending as $p) {
        $types .= is_int($p) ? 'i' : 's';
    }
    mysqli_stmt_bind_param($stmt_pending, $types, ...$params_pending);
}
mysqli_stmt_execute($stmt_pending);
if (function_exists('mysqli_stmt_get_result')) {
    $res_pending = mysqli_stmt_get_result($stmt_pending);
} else {
    mysqli_stmt_store_result($stmt_pending);
    $meta = mysqli_stmt_result_metadata($stmt_pending);
    $fields = [];
    $row = [];
    if ($meta) {
        while ($field = $meta->fetch_field()) {
            $fields[] = &$row[$field->name];
        }
        call_user_func_array('mysqli_stmt_bind_result', array_merge([$stmt_pending], $fields));
    }
    $res_pending = [];
    while (mysqli_stmt_fetch($stmt_pending)) {
        $res_pending[] = array_map(function($v){return $v;}, $row);
    }
}
// Query berhasil
$where_berhasil = [];
$params_berhasil = [];
if ($sales_trans !== '') {
    $where_berhasil[] = '`nama` = ?';
    $params_berhasil[] = $sales_trans;
}
if ($bulan_trans >= 1 && $bulan_trans <= 12) {
    $where_berhasil[] = 'MONTH(`tanggal`) = ?';
    $params_berhasil[] = $bulan_trans;
}
if ($tahun_trans > 0) {
    $where_berhasil[] = 'YEAR(`tanggal`) = ?';
    $params_berhasil[] = $tahun_trans;
}
$where_berhasil[] = '`status` = ?';
$params_berhasil[] = 'berhasil';
 $where_berhasil[] = "`nama` IN (SELECT nama FROM mitra WHERE `server` ='$cekserver')";
$where_berhasil[] = "COALESCE(`komisi_kategori`, 'regular') = 'regular'";
$sql_berhasil = "SELECT `id`, `nama`, `tanggal`, `periode`, `pembayaran`, `status`, `server` FROM `transaksi_komisi`";
if ($where_berhasil) {
    $sql_berhasil .= " WHERE " . implode(' AND ', $where_berhasil);
}
$sql_berhasil .= ' ORDER BY `tanggal` DESC LIMIT 500';


$stmt_berhasil = mysqli_prepare($conn, $sql_berhasil);
if ($params_berhasil) {
    $types = '';
    foreach ($params_berhasil as $p) {
        $types .= is_int($p) ? 'i' : 's';
    }
    mysqli_stmt_bind_param($stmt_berhasil, $types, ...$params_berhasil);
}
mysqli_stmt_execute($stmt_berhasil);
if (function_exists('mysqli_stmt_get_result')) {
    $res_berhasil = mysqli_stmt_get_result($stmt_berhasil);
} else {
    mysqli_stmt_store_result($stmt_berhasil);
    $meta = mysqli_stmt_result_metadata($stmt_berhasil);
    $fields = [];
    $row = [];
    if ($meta) {
        while ($field = $meta->fetch_field()) {
            $fields[] = &$row[$field->name];
        }
        call_user_func_array('mysqli_stmt_bind_result', array_merge([$stmt_berhasil], $fields));
    }
    $res_berhasil = [];
    while (mysqli_stmt_fetch($stmt_berhasil)) {
        $res_berhasil[] = array_map(function($v){return $v;}, $row);
    }
}

// Hitung dan simpan $pending_rows dan $total_komisi_pending SEKALI SAJA (tidak digunakan lagi)

$cekappkeuangan="../keuangan/index.php";
$app_keuangan_exists = file_exists($cekappkeuangan);
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
.komisi-layout {
    max-width: 1800px;
    margin: 0 auto;
    padding-left: 1.25rem;
    padding-right: 1.25rem;
}
.komisi-panel {
    border: 0;
    border-radius: 14px;
    overflow: hidden;
}
.komisi-panel .card-header {
    border-bottom: 1px solid #e9edf3;
}
#modalRekap .modal-dialog {
    max-width: min(92vw, 760px);
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
.action-cell {
    min-width: 230px;
}
.action-wrap {
    display: flex;
    flex-wrap: wrap;
    gap: 0.35rem;
}
.section-title {
    font-size: 1.05rem;
    font-weight: 600;
}
.bulk-action-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 0.75rem;
    flex-wrap: wrap;
    margin-bottom: 0.85rem;
    padding: 0.1rem 0;
    border-bottom: 1px solid #eef2f7;
}
.bulk-action-left {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
}
.bulk-action-bar .btn {
    min-width: 140px;
}
.bulk-action-right {
    text-align: right;
}
@media (max-width: 768px) {
    .bulk-action-bar {
        align-items: flex-start;
    }
    .bulk-action-left,
    .bulk-action-right {
        width: 100%;
    }
    .bulk-action-left .btn,
    .bulk-action-right small {
        width: 100%;
    }
    .bulk-action-right {
        text-align: left;
    }
}
</style>
<div class="container-fluid py-4 komisi-layout">
    <!-- Tab Navigasi Rekap -->
    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link active" href="rekappembayaranmitra.php" id="tabRekapMitra">REGULAR KOMISI CONTINUE</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="rekappembayaranmitra_area.php" id="tabRekapArea">PIC AREA CONTINUE KOMISI</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="rekappembayaranmitra_awal.php" id="tabRekapAwal">ONE TME KOMISI</a>
        </li>
    </ul>
    <div class="row">
        <div class="col-12">
            <h3 class="mb-4">Rekap Pembayaran Mitra / Sales</h3>
            <?php if (!empty($acc_success)): ?><div class="alert alert-success">ACC berhasil!</div><?php endif; ?>
            <?php if (!empty($acc_error)): ?><div class="alert alert-danger">ACC gagal: <?php echo e($acc_error); ?></div><?php endif; ?>
            <?php if (!empty($cron_settings_notice)) echo $cron_settings_notice; ?>

            <div class="card shadow-sm komisi-panel mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Auto Komisi Sales (Cron)</h5>
                </div>
                <div class="card-body">
                    <form method="post" class="row g-3 align-items-end">
                        <input type="hidden" name="save_komisi_cron_settings" value="1">
                        <div class="col-md-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="auto_regular_enabled" name="auto_regular_enabled" <?php echo !empty($komisiCronSettings['auto_regular_enabled']) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="auto_regular_enabled">Auto Komisi Sales</label>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label" for="regular_mode">Mode Sales</label>
                            <select class="form-select" id="regular_mode" name="regular_mode">
                                <option value="persen" <?php echo ($komisiCronSettings['regular_mode'] === 'persen') ? 'selected' : ''; ?>>Persentase</option>
                                <option value="nominal" <?php echo ($komisiCronSettings['regular_mode'] === 'nominal') ? 'selected' : ''; ?>>Nominal</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label" for="regular_run_day">Tanggal</label>
                            <input type="number" min="1" max="28" class="form-control" id="regular_run_day" name="regular_run_day" value="<?php echo (int) $komisiCronSettings['regular_run_day']; ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label" for="regular_run_hour">Jam</label>
                            <input type="number" min="0" max="23" class="form-control" id="regular_run_hour" name="regular_run_hour" value="<?php echo (int) $komisiCronSettings['regular_run_hour']; ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label" for="regular_run_minute">Menit</label>
                            <input type="number" min="0" max="59" class="form-control" id="regular_run_minute" name="regular_run_minute" value="<?php echo (int) $komisiCronSettings['regular_run_minute']; ?>">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100" data-perm="btn_komisi_cron">Simpan Cron</button>
                        </div>
                    </form>
                    <small class="text-muted d-block mt-2">Cron global berjalan setiap menit, tetapi komisi hanya dibuat pada tanggal dan jam yang ditentukan untuk owner ini.</small>
                </div>
            </div>

            <!-- Card 1: Buat Rekap Sales -->
            <div class="card shadow-sm komisi-panel mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Buat Rekap Sales</h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-2 mb-4">
                        <div class="col-auto">
                            <select name="sales" class="form-select" required>
                                <option value="">-- Pilih Mitra --</option>
                                <?php
                                $q_sales->data_seek(0);
                                while ($s = $q_sales->fetch_assoc()):
                                ?>
                                    <option value="<?= htmlspecialchars($s['sales']) ?>" <?= $s['sales'] == $sales_login ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($s['sales']) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-auto">
                            <select name="bulan" class="form-select" required>
                                <?php foreach ($bulan_indonesia as $key => $val): ?>
                                    <option value="<?= $key ?>" <?= $key == $bln ? 'selected' : '' ?>><?= $val ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-auto">
                            <select name="tahun" class="form-select" required>
                                <?php for ($i = date('Y') - 2; $i <= date('Y') + 2; $i++): ?>
                                    <option value="<?= $i ?>" <?= $i == $tahun ? 'selected' : '' ?>><?= $i ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-auto">
                            <select name="komisi_mode" class="form-select" required>
                                <option value="persen" <?= $komisi_mode == 'persen' ? 'selected' : '' ?>>Persentase</option>
                                <option value="nominal" <?= $komisi_mode == 'nominal' ? 'selected' : '' ?>>Nominal</option>
                            </select>
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-primary">Tampilkan</button>
                        </div>
                    </form>

                    <?php if ($sales_login !== ''): ?>
                        <h5>Periode: <strong><?= $penggunaan ?></strong></h5>

                        <h5 class="mt-4 section-title">Sudah Bayar (<?= $total_bayar ?>)</h5>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped table-sm">
                                <thead class="table-success">
                                    <tr>
                                        <th>#</th>
                                        <th>IDPEL</th>
                                        <th>Nama</th>
                                        <th>Paket</th>
                                        <th>Harga</th>
                                        <th>Alamat</th>
                                        <th>Tanggal Bayar</th>
                                        <th>Komisi</th>
                                        <th>Bukti</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($data['sudah_bayar'] as $i => $row): ?>
                                        <tr>
                                            <td><?= $i + 1 ?></td>
                                            <td><?= htmlspecialchars($row['IDPEL']) ?></td>
                                            <td><?= htmlspecialchars($row['NAMA']) ?></td>
                                            <td><?= htmlspecialchars($row['PAKET']) ?></td>
                                            <td>Rp <?= number_format($row['HARGA']) ?></td>
                                            <td style="max-width: 250px; overflow: auto;"><?= htmlspecialchars($row['ALAMAT']) ?></td>
                                            <td><?= htmlspecialchars($row['TANGGALBAYAR']) ?></td>
                                            <td class="text-success fw-bold">Rp <?= number_format($row['komisi']) ?></td>
                                            <td><?= htmlspecialchars($row['BUKTI']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-3 mb-5">
                            <h5>Total Komisi: <span class="text-success">Rp <?= number_format($total_komisi) ?></span></h5>
                        </div>

                        <h5 class="mt-5 section-title">Belum Bayar (<?= $total_belum ?>)</h5>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped table-sm">
                                <thead class="table-danger">
                                    <tr>
                                        <th>#</th>
                                        <th>IDPEL</th>
                                        <th>Nama</th>
                                        <th>Paket</th>
                                        <th>Harga</th>
                                        <th>Alamat</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($data['belum_bayar'] as $i => $row): ?>
                                        <tr>
                                            <td><?= $i + 1 ?></td>
                                            <td><?= htmlspecialchars($row['IDPEL']) ?></td>
                                            <td><?= htmlspecialchars($row['NAMA']) ?></td>
                                            <td><?= htmlspecialchars($row['PAKET']) ?></td>
                                            <td>Rp <?= number_format($row['HARGA']) ?></td>
                                            <td><?= htmlspecialchars($row['ALAMAT']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <th colspan="4" class="text-end">Total Harga Belum Bayar:</th>
                                        <th colspan="2">Rp <?= number_format($total_harga_belum_bayar) ?></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <!-- Tombol Buat Rekap -->
                        <button type="button" class="btn btn-success mb-4" data-bs-toggle="modal" data-bs-target="#modalRekap">
                            BUAT REKAP
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Modal Buat Rekap -->
            <div class="modal fade" id="modalRekap" tabindex="-1" aria-labelledby="modalRekapLabel" aria-hidden="true">
                <div class="modal-dialog">
                    <form method="POST" action="" id="formRekap">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="modalRekapLabel">Input Rekap Pembayaran</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label for="nama" class="form-label">Nama</label>
                                    <input type="text" name="nama" id="nama" class="form-control" value="<?php echo $sales_login ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label for="tanggal" class="form-label">Tanggal</label>
                                    <input type="date" name="tanggal" id="tanggal" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label for="status" class="form-label">Status</label>
                                    <select name="status" id="status" class="form-select" required>
                                        <option value="pending">PENGAJUAN</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label for="jumlah" class="form-label">Jumlah (Rp)</label>
                                    <input type="number" name="jumlah" id="jumlah" class="form-control" min="0" value="<?php echo $total_komisi ?>" required>
                                </div>
                                <input type="hidden" name="periode" value="<?= htmlspecialchars($penggunaan) ?>">
                                <input type="hidden" name="total_bayar" value="<?= (int) $total_bayar ?>">
                            </div>
                            <div class="modal-footer">
                                <button type="submit" name="submit_rekap" class="btn btn-primary">Simpan Rekap</button>
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Card 2: Transaksi -->
            <div class="card shadow-sm komisi-panel">
                <div class="card-header">
                    <h5 class="card-title mb-0">Transaksi dan riwayat</h5>
                </div>
                <div class="card-body">
                    <form method="get" class="row g-2 align-items-end mb-4">
                        <div class="col-md-4">
                            <label class="form-label">Nama</label>
                            <select name="sales_trans" class="form-select">
                                <option value="">-- Semua --</option>
                                <?php
                                $q_sales->data_seek(0);
                                while ($s = $q_sales->fetch_assoc()): ?>
                                <option value="<?php echo htmlspecialchars($s['sales']); ?>" <?php if ($s['sales'] == $sales_trans) echo 'selected'; ?>><?php echo htmlspecialchars($s['sales']); ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Bulan</label>
                            <select name="bulan_trans" class="form-select">
                                <option value="0">-- Semua --</option>
                                <?php for ($m=1;$m<=12;$m++): ?>
                                <option value="<?php echo $m;?>" <?php if ($m==$bulan_trans) echo 'selected';?>><?php echo $m;?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Tahun</label>
                            <select name="tahun_trans" class="form-select">
                                <option value="0">-- Semua --</option>
                                <?php for ($y = date('Y'); $y >= date('Y')-10; $y--): ?>
                                <option value="<?php echo $y;?>" <?php if ($y==$tahun_trans) echo 'selected';?>><?php echo $y;?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">Filter</button>
                        </div>
                    </form>
                   
<div class="mb-3 d-flex justify-content-between align-items-center">
    <div>
        <button type="button" class="btn btn-primary me-2" id="tabPendingBtn">Pending</button>
        <button type="button" class="btn btn-outline-primary" id="tabBerhasilBtn">Berhasil</button>
    </div>
</div>






















<div id="tabPending" style="display:block;">
    <form method="post" id="deleteForm">
        <div class="bulk-action-bar">
            <div class="bulk-action-left">
                <button type="submit" name="hapus_selected" class="btn btn-danger" onclick="return confirm('Yakin ingin menghapus data yang dipilih?')">Hapus Terpilih</button>
            </div>
            <div class="bulk-action-right">
                <small class="text-muted">Pilih checkbox data lalu klik Hapus Terpilih.</small>
            </div>
        </div>
        <div class="table-responsive mb-4">
            <table class="table table-bordered table-striped align-middle">
                <thead class="table-warning">
                    <tr>
                        <th><input type="checkbox" id="selectAll"></th>
                        <th>Nama</th>
                        <th>Periode</th>
                        <th>Total Pembayaran</th>
                        <th>Jumlah Transaksi</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                if (is_object($res_pending)) {
                    while ($row = $res_pending->fetch_assoc()): ?>
                        <tr>
                            <td><input type="checkbox" name="selected_ids[]" value="<?php echo e($row['ids']); ?>"></td>
                            <td><?php echo e($row['nama']); ?></td>
                            <td><?php echo e($row['periode']); ?></td>
                            <td>Rp<?php echo number_format($row['total_pembayaran'],0,',','.'); ?></td>
                            <td><?php echo e($row['jumlah_transaksi']); ?></td>
                            <td><span class="badge bg-warning text-dark">Pending</span></td>
                            <td class="action-cell">
                                <div class="action-wrap">
                                <button type="submit" name="hapus_ids" value="<?php echo e($row['ids']); ?>" class="btn btn-sm btn-danger" onclick="return confirm('Yakin ingin menghapus semua transaksi ini?')">Hapus</button>
                                <?php if ( $app_keuangan_exists && $AKSES == 'ADMIN' ) { ?>
                                    <button type="button" class="btn btn-sm btn-outline-secondary js-btn-cek-regular" data-ids="<?php echo e($row['ids']); ?>" data-bs-toggle="modal" data-bs-target="#modalCekRegular">Cek</button>
                                    <a type="button" href="../keuangan/accrekapsales.php" class="btn btn-sm btn-primary">ACC</a>
                                <?php } elseif( $AKSES == 'ADMIN' || $AKSES == 'ASSISTANT' || $AKSES == 'USER' ) { ?>
                                    <button type="button" class="btn btn-sm btn-outline-secondary js-btn-cek-regular" data-ids="<?php echo e($row['ids']); ?>" data-bs-toggle="modal" data-bs-target="#modalCekRegular">Cek</button>
                                    <button type="submit" name="acc_ids" value="<?php echo e($row['ids']); ?>" class="btn btn-sm btn-primary" onclick="return confirm('ACC semua transaksi ini?')">ACC</button>
                                <?php } else { ?>
                                    -
                                <?php } ?>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile;
                } else if (is_array($res_pending)) {
                    foreach ($res_pending as $row): ?>
                        <tr>
                            <td><input type="checkbox" name="selected_ids[]" value="<?php echo e($row['ids']); ?>"></td>
                            <td><?php echo e($row['nama']); ?></td>
                            <td><?php echo e($row['periode']); ?></td>
                            <td>Rp<?php echo number_format($row['total_pembayaran'],0,',','.'); ?></td>
                            <td><?php echo e($row['jumlah_transaksi']); ?></td>
                            <td><span class="badge bg-warning text-dark">Pending</span></td>
                            <td class="action-cell">
                                <div class="action-wrap">
                                <button type="submit" name="hapus_ids" value="<?php echo e($row['ids']); ?>" class="btn btn-sm btn-danger" onclick="return confirm('Yakin ingin menghapus semua transaksi ini?')">Hapus</button>
                                <?php if ( $app_keuangan_exists && $AKSES == 'ADMIN' ) { ?>
                                    <button type="button" class="btn btn-sm btn-outline-secondary js-btn-cek-regular" data-ids="<?php echo e($row['ids']); ?>" data-bs-toggle="modal" data-bs-target="#modalCekRegular">Cek</button>
                                    <a type="button" href="../keuangan/accrekapsales.php" class="btn btn-sm btn-primary">ACC</a>
                                <?php } elseif( $AKSES == 'ADMIN' || $AKSES == 'ASSISTANT' || $AKSES == 'USER' ) { ?>
                                    <button type="button" class="btn btn-sm btn-outline-secondary js-btn-cek-regular" data-ids="<?php echo e($row['ids']); ?>" data-bs-toggle="modal" data-bs-target="#modalCekRegular">Cek</button>
                                    <button type="submit" name="acc_ids" value="<?php echo e($row['ids']); ?>" class="btn btn-sm btn-primary" onclick="return confirm('ACC semua transaksi ini?')">ACC</button>
                                <?php } else { ?>
                                    -
                                <?php } ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach;
                }
                ?>
                </tbody>
            </table>
        </div>
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
            <tbody>
            <?php
            if (is_object($res_berhasil)) {
                while ($row = $res_berhasil->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo e($row['id']); ?></td>
                        <td><?php echo e($row['nama']); ?></td>
                        <td><?php echo e($row['tanggal']); ?></td>
                        <td><?php echo e($row['periode']); ?></td>
                        <td>Rp<?php echo number_format($row['pembayaran'],0,',','.'); ?></td>
                        <td><span class="badge bg-success">Berhasil</span></td>
                       
                        <td>-</td>
                    </tr>
            <?php endwhile;
            } else if (is_array($res_berhasil)) {
                foreach ($res_berhasil as $row): ?>
                    <tr>
                        <td><?php echo e($row['id']); ?></td>
                        <td><?php echo e($row['nama']); ?></td>
                        <td><?php echo e($row['tanggal']); ?></td>
                        <td><?php echo e($row['periode']); ?></td>
                        <td>Rp<?php echo number_format($row['pembayaran'],0,',','.'); ?></td>
                        <td><span class="badge bg-success">Berhasil</span></td>
                       
                        <td>-</td>
                    </tr>
            <?php endforeach;
            }
            ?>
            </tbody>
        </table>
    </div>
</div>
                </div>
            </div>
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

    // Select all checkbox
    var selectAll = document.getElementById('selectAll');
    if (selectAll) {
        selectAll.addEventListener('change', function() {
            var checkboxes = document.querySelectorAll('input[name="selected_ids[]"]');
            checkboxes.forEach(function(checkbox) {
                checkbox.checked = selectAll.checked;
            });
        });
    }

    var selectedRegularIds = '';
    var regularModalEl = document.getElementById('modalCekRegular');
    var regularButtons = document.querySelectorAll('.js-btn-cek-regular');
    regularButtons.forEach(function(btn) {
        btn.addEventListener('click', function() {
            selectedRegularIds = btn.getAttribute('data-ids') || '';
        });
    });

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

    if (regularModalEl) {
        regularModalEl.addEventListener('show.bs.modal', function() {
            var titleEl = regularModalEl.querySelector('.modal-title');
            var bodyEl = regularModalEl.querySelector('tbody');
            if (!selectedRegularIds) {
                titleEl.textContent = 'Cek Detail Rekap';
                bodyEl.innerHTML = '<tr><td colspan="9" class="text-center text-muted">Data rekap tidak ditemukan.</td></tr>';
                return;
            }

            titleEl.textContent = 'Cek Detail Rekap (Memuat...)';
            bodyEl.innerHTML = '<tr><td colspan="9" class="text-center text-muted">Memuat detail data...</td></tr>';

            fetch(window.location.pathname + '?ajax_cek_ids=' + encodeURIComponent(selectedRegularIds), {
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
                    titleEl.textContent = 'Cek Detail Rekap: ' + (data.title || '-');
                    if (!data.rows || data.rows.length === 0) {
                        bodyEl.innerHTML = '<tr><td colspan="9" class="text-center text-muted">Belum ada detail sumber untuk rekap ini.</td></tr>';
                        return;
                    }

                    var rowsHtml = data.rows.map(function(row) {
                        return '<tr>' +
                            '<td>' + escapeHtml(row.sales) + '</td>' +
                            '<td>' + escapeHtml(row.periode) + '</td>' +
                            '<td>' + escapeHtml(row.idpel) + '</td>' +
                            '<td>' + escapeHtml(row.nama) + '</td>' +
                            '<td>' + escapeHtml(row.paket) + '</td>' +
                            '<td>' + formatRupiah(row.harga) + '</td>' +
                            '<td>' + escapeHtml(row.tanggal_bayar) + '</td>' +
                            '<td>' + formatRupiah(row.komisi) + '</td>' +
                            '<td>' + escapeHtml(row.bukti) + '</td>' +
                        '</tr>';
                    }).join('');
                    bodyEl.innerHTML = rowsHtml;
                })
                .catch(function() {
                    titleEl.textContent = 'Cek Detail Rekap';
                    bodyEl.innerHTML = '<tr><td colspan="9" class="text-center text-danger">Gagal memuat detail data.</td></tr>';
                });
        });
    }
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<div class="modal fade" id="modalCekRegular" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Cek Detail Rekap</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-sm align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Sales</th>
                                <th>Periode</th>
                                <th>IDPEL</th>
                                <th>Nama</th>
                                <th>Paket</th>
                                <th>Harga</th>
                                <th>Tanggal Bayar</th>
                                <th>Komisi</th>
                                <th>Bukti</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td colspan="9" class="text-center text-muted">Pilih data lalu klik Cek untuk memuat detail.</td></tr>
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
<?php include 'footer.php'; ?>
