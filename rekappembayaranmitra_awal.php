<?php
ob_start();
include 'header.php';
require_once __DIR__ . '/komisi_helper.php';
komisi_ensure_schema($conn);
komisi_ensure_awal_claims_table($conn);

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

$cron_settings_notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_komisi_cron_settings'])) {
    $saveOk = komisi_save_cron_settings($conn, $cekserver, [
        'auto_awal_enabled' => isset($_POST['auto_awal_enabled']) ? 1 : 0,
        'awal_mode' => $_POST['awal_mode'] ?? 'persen',
        'awal_run_day' => isset($_POST['awal_run_day']) ? (int) $_POST['awal_run_day'] : 1,
        'awal_run_hour' => isset($_POST['awal_run_hour']) ? (int) $_POST['awal_run_hour'] : 1,
        'awal_run_minute' => isset($_POST['awal_run_minute']) ? (int) $_POST['awal_run_minute'] : 0
    ]);
    komisi_sync_global_cron($conn, __DIR__);
    $cron_settings_notice = $saveOk
        ? '<div class="alert alert-success">Pengaturan auto komisi bayar awal berhasil disimpan.</div>'
        : '<div class="alert alert-danger">Gagal menyimpan pengaturan auto komisi bayar awal.</div>';
}

$komisiCronSettings = komisi_get_cron_settings($conn, $cekserver);

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax_cek_ids_awal'])) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    $payload = [
        'ok' => true,
        'title' => 'Detail Rekap Komisi Bayar Awal',
        'rows' => []
    ];

    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $_GET['ajax_cek_ids_awal'])))));
    if (!empty($ids)) {
        $trxMap = [];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));

        $trxStmt = mysqli_prepare($conn, "SELECT id, nama, periode FROM transaksi_komisi WHERE id IN ($placeholders) AND server = ? AND COALESCE(komisi_kategori, 'regular') = 'awal'");
        if ($trxStmt) {
            $params = $ids;
            $typesWithOwner = $types . 's';
            $params[] = $cekserver;
            mysqli_stmt_bind_param($trxStmt, $typesWithOwner, ...$params);
            mysqli_stmt_execute($trxStmt);
            $trxRes = mysqli_stmt_get_result($trxStmt);
            while ($trx = $trxRes ? mysqli_fetch_assoc($trxRes) : null) {
                $trxMap[(int) $trx['id']] = [
                    'nama' => (string) ($trx['nama'] ?? ''),
                    'periode' => (string) ($trx['periode'] ?? '')
                ];
            }
            mysqli_stmt_close($trxStmt);
        }

        if (!empty($trxMap)) {
            $claimStmt = mysqli_prepare($conn, "SELECT transaksi_komisi_id, idpel, tanggal_bayar_awal FROM komisi_awal_claims WHERE owner = ? AND transaksi_komisi_id IN ($placeholders) ORDER BY tanggal_bayar_awal DESC, idpel ASC");
            if ($claimStmt) {
                $params = [$cekserver];
                foreach ($ids as $idVal) {
                    $params[] = $idVal;
                }
                $claimTypes = 's' . $types;
                mysqli_stmt_bind_param($claimStmt, $claimTypes, ...$params);
                mysqli_stmt_execute($claimStmt);
                $claimRes = mysqli_stmt_get_result($claimStmt);

                while ($claim = $claimRes ? mysqli_fetch_assoc($claimRes) : null) {
                    $trxId = (int) ($claim['transaksi_komisi_id'] ?? 0);
                    $idpel = trim((string) ($claim['idpel'] ?? ''));
                    $pelNama = '';
                    $pelPaket = '';
                    $pelAlamat = '';

                    if ($idpel !== '') {
                        $pelStmt = mysqli_prepare($conn, "SELECT NAMA, PAKET, ALAMAT FROM pelanggan WHERE IDPEL = ? AND PEMILIK = ? LIMIT 1");
                        if ($pelStmt) {
                            mysqli_stmt_bind_param($pelStmt, 'ss', $idpel, $cekserver);
                            mysqli_stmt_execute($pelStmt);
                            $pelRes = mysqli_stmt_get_result($pelStmt);
                            $pel = $pelRes ? mysqli_fetch_assoc($pelRes) : null;
                            if ($pel) {
                                $pelNama = (string) ($pel['NAMA'] ?? '');
                                $pelPaket = (string) ($pel['PAKET'] ?? '');
                                $pelAlamat = (string) ($pel['ALAMAT'] ?? '');
                            }
                            mysqli_stmt_close($pelStmt);
                        }
                    }

                    $payload['rows'][] = [
                        'sales' => (string) (($trxMap[$trxId]['nama'] ?? '')),
                        'periode' => (string) (($trxMap[$trxId]['periode'] ?? '')),
                        'idpel' => $idpel,
                        'nama' => $pelNama,
                        'paket' => $pelPaket,
                        'alamat' => $pelAlamat,
                        'tanggal_bayar_awal' => (string) ($claim['tanggal_bayar_awal'] ?? '')
                    ];
                }
                mysqli_stmt_close($claimStmt);
            }

            // Fallback untuk data lama yang belum punya mapping di komisi_awal_claims.
            if (empty($payload['rows'])) {
                $dedup = [];
                foreach ($trxMap as $trxInfo) {
                    $salesName = trim((string) ($trxInfo['nama'] ?? ''));
                    $periode = trim((string) ($trxInfo['periode'] ?? ''));
                    if ($salesName === '' || $periode === '') {
                        continue;
                    }
                    [$m, $y] = komisi_parse_period_label_local($periode);
                    if ($m <= 0 || $y <= 0) {
                        continue;
                    }

                    $settings = komisi_get_cron_settings($conn, $cekserver);
                    $mode = $settings['awal_mode'] ?? 'persen';
                    $previewAwal = komisi_get_awal_preview($conn, $cekserver, $salesName, $m, $y, $mode);
                    foreach ((array) ($previewAwal['awal_bayar'] ?? []) as $row) {
                        $idpel = trim((string) ($row['IDPEL'] ?? ''));
                        $key = $salesName . '|' . $periode . '|' . $idpel;
                        if (isset($dedup[$key])) {
                            continue;
                        }
                        $dedup[$key] = true;
                        $payload['rows'][] = [
                            'sales' => $salesName,
                            'periode' => $periode,
                            'idpel' => $idpel,
                            'nama' => (string) ($row['NAMA'] ?? ''),
                            'paket' => (string) ($row['PAKET'] ?? ''),
                            'alamat' => (string) ($row['ALAMAT'] ?? ''),
                            'tanggal_bayar_awal' => (string) ($row['TANGGALBAYAR'] ?? '')
                        ];
                    }
                }
            }

            $titles = [];
            foreach ($trxMap as $trxInfo) {
                $titles[] = trim(($trxInfo['nama'] ?? '') . ' - ' . ($trxInfo['periode'] ?? ''));
            }
            $titles = array_values(array_unique(array_filter($titles)));
            if (!empty($titles)) {
                $payload['title'] = implode(' | ', $titles);
            }
        }
    }

    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hapus_ids'])) {
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $_POST['hapus_ids'])))));
    if (!empty($ids)) {
        komisi_awal_claim_delete_pending_by_transaksi_ids($conn, $cekserver, $ids);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids)) . 's';
        $params = $ids;
        $params[] = $cekserver;
        $stmt = mysqli_prepare($conn, "DELETE FROM transaksi_komisi WHERE id IN ($placeholders) AND status='pending' AND server=? AND COALESCE(komisi_kategori, 'regular')='awal'");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, $types, ...$params);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }
    echo '<script>alert("Data berhasil dihapus.");window.location.href=window.location.href;</script>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acc_id'])) {
    $id = (int) $_POST['acc_id'];
    $stmt = mysqli_prepare($conn, "UPDATE transaksi_komisi SET status='berhasil' WHERE id=? AND status='pending' AND server=? AND COALESCE(komisi_kategori, 'regular')='awal'");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'is', $id, $cekserver);
        mysqli_stmt_execute($stmt);
        if (mysqli_stmt_affected_rows($stmt) > 0) {
            komisi_awal_claim_mark_berhasil_by_transaksi_ids($conn, $cekserver, [$id]);
        }
        mysqli_stmt_close($stmt);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acc_ids'])) {
    $ids = array_filter(array_map('intval', explode(',', (string) $_POST['acc_ids'])));
    $updatedIds = [];
    foreach ($ids as $id) {
        $stmt = mysqli_prepare($conn, "UPDATE transaksi_komisi SET status='berhasil' WHERE id=? AND status='pending' AND server=? AND COALESCE(komisi_kategori, 'regular')='awal'");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'is', $id, $cekserver);
            mysqli_stmt_execute($stmt);
            if (mysqli_stmt_affected_rows($stmt) > 0) {
                $updatedIds[] = $id;
            }
            mysqli_stmt_close($stmt);
        }
    }
    if (!empty($updatedIds)) {
        komisi_awal_claim_mark_berhasil_by_transaksi_ids($conn, $cekserver, $updatedIds);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hapus_selected']) && isset($_POST['selected_ids'])) {
    $allIds = [];
    foreach ((array) $_POST['selected_ids'] as $idsCsv) {
        $allIds = array_merge($allIds, array_map('intval', explode(',', (string) $idsCsv)));
    }
    $allIds = array_values(array_unique(array_filter($allIds)));
    if (!empty($allIds)) {
        komisi_awal_claim_delete_pending_by_transaksi_ids($conn, $cekserver, $allIds);
        $placeholders = implode(',', array_fill(0, count($allIds), '?'));
        $types = str_repeat('i', count($allIds));
        $stmt = mysqli_prepare($conn, "DELETE FROM transaksi_komisi WHERE id IN ($placeholders) AND status='pending' AND server='" . $conn->real_escape_string($cekserver) . "' AND COALESCE(komisi_kategori, 'regular')='awal'");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, $types, ...$allIds);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_rekap_awal'])) {
    $claimRowsRaw = (string) ($_POST['awal_claim_rows'] ?? '[]');
    $claimRowsData = json_decode($claimRowsRaw, true);
    $claimRows = [];
    if (is_array($claimRowsData)) {
        foreach ($claimRowsData as $row) {
            if (!is_array($row)) {
                continue;
            }
            $idpel = trim((string) ($row['idpel'] ?? ''));
            if ($idpel === '') {
                continue;
            }
            $claimRows[] = [
                'idpel' => $idpel,
                'tanggal_bayar_awal' => trim((string) ($row['tanggal_bayar_awal'] ?? ''))
            ];
        }
    }

    $upsert = komisi_upsert_transaksi($conn, [
        'komisi_kategori' => 'awal',
        'nama' => $_POST['nama'] ?? '',
        'tanggal' => $_POST['tanggal'] ?? date('Y-m-d'),
        'periode' => $_POST['periode'] ?? '',
        'pembayaran' => isset($_POST['jumlah']) ? (float) $_POST['jumlah'] : 0,
        'status' => 'pending',
        'server' => $cekserver,
        'total_pelanggan' => isset($_POST['total_awal_bayar']) ? (int) $_POST['total_awal_bayar'] : 0
    ]);

    if (($upsert['status'] ?? '') === 'inserted' || ($upsert['status'] ?? '') === 'updated') {
        $trxId = (int) ($upsert['id'] ?? 0);
        if ($trxId > 0) {
            komisi_awal_claim_delete_pending_by_transaksi_ids($conn, $cekserver, [$trxId]);
            komisi_awal_claim_upsert_list(
                $conn,
                $cekserver,
                (string) ($_POST['nama'] ?? ''),
                (string) ($_POST['periode'] ?? ''),
                $trxId,
                'pending',
                $claimRows
            );
        }
        echo "<script>alert('Rekap komisi bayar awal berhasil disimpan!'); window.location.href=window.location.href;</script>";
        exit;
    }
    if (($upsert['status'] ?? '') === 'locked') {
        echo "<script>alert('Rekap periode ini sudah berhasil di-ACC, tidak dapat diubah.');</script>";
    } elseif (($upsert['status'] ?? '') === 'skipped') {
        echo "<script>alert('Rekap tidak disimpan karena nominal 0 atau data belum lengkap.');</script>";
    } else {
        $msg = isset($upsert['message']) ? $upsert['message'] : $conn->error;
        echo "<script>alert('Gagal menyimpan rekap: " . addslashes($msg) . "');</script>";
    }
}

komisi_awal_claim_sync_status($conn, $cekserver);

$sales_login = isset($_GET['sales']) ? trim((string) $_GET['sales']) : '';
$bulan = isset($_GET['bulan']) ? (int) $_GET['bulan'] : (int) date('m');
$tahun = isset($_GET['tahun']) ? (int) $_GET['tahun'] : (int) date('Y');
$komisi_mode = (isset($_GET['komisi_mode']) && $_GET['komisi_mode'] === 'nominal') ? 'nominal' : 'persen';
$bulan_indonesia = komisi_month_names();
$penggunaan = komisi_build_period_label($bulan, $tahun);

$q_sales = $conn->query("SELECT nama AS sales FROM mitra WHERE server='" . $conn->real_escape_string($cekserver) . "' ORDER BY nama ASC");

$awal_bayar = [];
$awal_claim_rows = [];
$total_awal_bayar = 0;
$total_komisi = 0.0;
if ($sales_login !== '') {
    $previewAwal = komisi_get_awal_preview($conn, $cekserver, $sales_login, $bulan, $tahun, $komisi_mode);
    $awal_bayar = $previewAwal['awal_bayar'];
    $total_awal_bayar = (int) $previewAwal['total_awal_bayar'];
    $total_komisi = (float) $previewAwal['total_komisi'];
    foreach ($awal_bayar as $row) {
        $idpel = trim((string) ($row['IDPEL'] ?? ''));
        if ($idpel === '') {
            continue;
        }
        $awal_claim_rows[] = [
            'idpel' => $idpel,
            'tanggal_bayar_awal' => trim((string) ($row['TANGGALBAYAR'] ?? ''))
        ];
    }
}

$sales_trans = isset($_GET['sales_trans']) ? trim((string) $_GET['sales_trans']) : '';
$bulan_trans = isset($_GET['bulan_trans']) ? (int) $_GET['bulan_trans'] : 0;
$tahun_trans = isset($_GET['tahun_trans']) ? (int) $_GET['tahun_trans'] : 0;

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
$where_pending[] = "`status` = 'pending'";
$where_pending[] = "`nama` IN (SELECT nama FROM mitra WHERE server='" . $conn->real_escape_string($cekserver) . "')";
$where_pending[] = "COALESCE(`komisi_kategori`, 'regular') = 'awal'";

$sql_pending = "SELECT `nama`, `periode`, SUM(`pembayaran`) AS total_pembayaran, COUNT(*) AS jumlah_transaksi, GROUP_CONCAT(`id`) AS ids FROM transaksi_komisi";
if ($where_pending) {
    $sql_pending .= ' WHERE ' . implode(' AND ', $where_pending);
}
$sql_pending .= ' GROUP BY nama, periode ORDER BY nama, periode DESC LIMIT 500';
$stmt_pending = mysqli_prepare($conn, $sql_pending);
if ($stmt_pending && !empty($params_pending)) {
    $types = '';
    foreach ($params_pending as $p) {
        $types .= is_int($p) ? 'i' : 's';
    }
    mysqli_stmt_bind_param($stmt_pending, $types, ...$params_pending);
}
if ($stmt_pending) {
    mysqli_stmt_execute($stmt_pending);
    $res_pending = mysqli_stmt_get_result($stmt_pending);
}

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
$where_berhasil[] = "`status` = 'berhasil'";
$where_berhasil[] = "`nama` IN (SELECT nama FROM mitra WHERE server='" . $conn->real_escape_string($cekserver) . "')";
$where_berhasil[] = "COALESCE(`komisi_kategori`, 'regular') = 'awal'";

$sql_berhasil = "SELECT id, nama, tanggal, periode, pembayaran, status FROM transaksi_komisi";
if ($where_berhasil) {
    $sql_berhasil .= ' WHERE ' . implode(' AND ', $where_berhasil);
}
$sql_berhasil .= ' ORDER BY tanggal DESC LIMIT 500';
$stmt_berhasil = mysqli_prepare($conn, $sql_berhasil);
if ($stmt_berhasil && !empty($params_berhasil)) {
    $types = '';
    foreach ($params_berhasil as $p) {
        $types .= is_int($p) ? 'i' : 's';
    }
    mysqli_stmt_bind_param($stmt_berhasil, $types, ...$params_berhasil);
}
if ($stmt_berhasil) {
    mysqli_stmt_execute($stmt_berhasil);
    $res_berhasil = mysqli_stmt_get_result($stmt_berhasil);
}

$cekappkeuangan = '../keuangan/index.php';
$app_keuangan_exists = file_exists($cekappkeuangan);
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
.nav-tabs .nav-link {
    background: #0d6efd;
    color: #fff;
    border: 1px solid #0d6efd;
    border-bottom: none;
    font-weight: 600;
    margin-right: 2px;
}
.nav-tabs .nav-link.active {
    background: #0d6efd;
    color: #fff;
    border-color: #0d6efd #0d6efd #fff;
    font-weight: 700;
}
.nav-tabs {
    border-bottom: 2px solid #0d6efd;
}
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
.table-responsive {
    overflow-x: auto;
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
    margin-bottom: 0.75rem;
}
</style>

<div class="container-fluid py-4 komisi-layout">
    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link" href="rekappembayaranmitra.php">REGULAR KOMISI CONTINUE</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="rekappembayaranmitra_area.php">PIC AREA CONTINUE KOMISI</a>
        </li>
        <li class="nav-item">
            <a class="nav-link active" href="rekappembayaranmitra_awal.php">ONE TME KOMISI</a>
        </li>
    </ul>

    <h3 class="mb-4">Komisi Pelanggan Bayar Awal (1x)</h3>
    <?php if (!empty($cron_settings_notice)) echo $cron_settings_notice; ?>

    <div class="card shadow-sm komisi-panel mb-4">
        <div class="card-header">
            <h5 class="card-title mb-0">Auto Komisi Bayar Awal (Cron)</h5>
        </div>
        <div class="card-body">
            <form method="post" class="row g-3 align-items-end">
                <input type="hidden" name="save_komisi_cron_settings" value="1">
                <div class="col-md-3">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="auto_awal_enabled" name="auto_awal_enabled" <?php echo !empty($komisiCronSettings['auto_awal_enabled']) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="auto_awal_enabled">Auto Komisi Bayar Awal</label>
                    </div>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="awal_mode">Mode Awal</label>
                    <select class="form-select" id="awal_mode" name="awal_mode">
                        <option value="persen" <?php echo ($komisiCronSettings['awal_mode'] === 'persen') ? 'selected' : ''; ?>>Persentase</option>
                        <option value="nominal" <?php echo ($komisiCronSettings['awal_mode'] === 'nominal') ? 'selected' : ''; ?>>Nominal</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="awal_run_day">Tanggal</label>
                    <input type="number" min="1" max="28" class="form-control" id="awal_run_day" name="awal_run_day" value="<?php echo (int) $komisiCronSettings['awal_run_day']; ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="awal_run_hour">Jam</label>
                    <input type="number" min="0" max="23" class="form-control" id="awal_run_hour" name="awal_run_hour" value="<?php echo (int) $komisiCronSettings['awal_run_hour']; ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="awal_run_minute">Menit</label>
                    <input type="number" min="0" max="59" class="form-control" id="awal_run_minute" name="awal_run_minute" value="<?php echo (int) $komisiCronSettings['awal_run_minute']; ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">Simpan Cron</button>
                </div>
            </form>
            <small class="text-muted d-block mt-2">Cron komisi bayar awal berjalan terpisah dari sales dan area.</small>
        </div>
    </div>

    <div class="card shadow-sm komisi-panel mb-4">
        <div class="card-header">
            <h5 class="card-title mb-0">Buat Rekap Komisi Bayar Awal</h5>
        </div>
        <div class="card-body">
            <form method="get" class="row g-2 mb-4">
                <div class="col-md-4">
                    <select name="sales" class="form-select" required>
                        <option value="">-- Pilih Mitra --</option>
                        <?php if ($q_sales) { $q_sales->data_seek(0); while ($s = $q_sales->fetch_assoc()): ?>
                        <option value="<?= e($s['sales']) ?>" <?= ($s['sales'] === $sales_login) ? 'selected' : '' ?>><?= e($s['sales']) ?></option>
                        <?php endwhile; } ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="bulan" class="form-select" required>
                        <?php foreach ($bulan_indonesia as $k => $v): ?>
                        <option value="<?= (int) $k ?>" <?= ((int) $k === (int) $bulan) ? 'selected' : '' ?>><?= e($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="tahun" class="form-select" required>
                        <?php for ($yy = date('Y') - 2; $yy <= date('Y') + 2; $yy++): ?>
                        <option value="<?= $yy ?>" <?= ($yy == $tahun) ? 'selected' : '' ?>><?= $yy ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="komisi_mode" class="form-select" required>
                        <option value="persen" <?= $komisi_mode === 'persen' ? 'selected' : '' ?>>Persentase</option>
                        <option value="nominal" <?= $komisi_mode === 'nominal' ? 'selected' : '' ?>>Nominal</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">Tampilkan</button>
                </div>
            </form>

            <?php if ($sales_login !== ''): ?>
            <div class="mb-3 text-muted">Periode rekap: <strong><?= e($penggunaan) ?></strong></div>
            <div class="table-responsive mb-3">
                <table class="table table-bordered table-striped table-sm align-middle">
                    <thead class="table-success">
                        <tr>
                            <th>#</th>
                            <th>IDPEL</th>
                            <th>Nama</th>
                            <th>Paket</th>
                            <th>Harga</th>
                            <th>Tanggal Bayar Awal</th>
                            <th>Komisi</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($awal_bayar as $i => $row): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><?= e($row['IDPEL'] ?? '') ?></td>
                            <td><?= e($row['NAMA'] ?? '') ?></td>
                            <td><?= e($row['PAKET'] ?? '') ?></td>
                            <td>Rp <?= number_format((float) ($row['HARGA'] ?? 0), 0, ',', '.') ?></td>
                            <td><?= e($row['TANGGALBAYAR'] ?? '') ?></td>
                            <td class="fw-bold text-success">Rp <?= number_format((float) ($row['komisi'] ?? 0), 0, ',', '.') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="mb-3">
                <h5 class="section-title">Total Pelanggan Bayar Awal: <span class="text-primary"><?= (int) $total_awal_bayar ?></span></h5>
                <h5 class="section-title">Total Komisi 1x: <span class="text-success">Rp <?= number_format($total_komisi, 0, ',', '.') ?></span></h5>
            </div>
            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalRekapAwal">BUAT REKAP 1X</button>
            <?php endif; ?>
        </div>
    </div>

    <div class="modal fade" id="modalRekapAwal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form method="post">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Simpan Rekap Komisi Bayar Awal</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Nama</label>
                            <input type="text" class="form-control" name="nama" value="<?= e($sales_login) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Tanggal</label>
                            <input type="date" class="form-control" name="tanggal" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Jumlah Komisi (Rp)</label>
                            <input type="number" class="form-control" name="jumlah" min="0" value="<?= (float) $total_komisi ?>" required>
                        </div>
                        <input type="hidden" name="periode" value="<?= e($penggunaan) ?>">
                        <input type="hidden" name="total_awal_bayar" value="<?= (int) $total_awal_bayar ?>">
                        <input type="hidden" name="awal_claim_rows" value='<?= e(json_encode($awal_claim_rows)) ?>'>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" name="submit_rekap_awal" class="btn btn-primary">Simpan Rekap</button>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm komisi-panel mb-3">
        <div class="card-header">
            <h5 class="card-title mb-0">Transaksi dan Riwayat Komisi Bayar Awal</h5>
        </div>
        <div class="card-body">
            <form method="get" class="row g-2 align-items-end mb-4">
                <div class="col-md-4">
                    <label class="form-label">Nama</label>
                    <select name="sales_trans" class="form-select">
                        <option value="">-- Semua --</option>
                        <?php if ($q_sales) { $q_sales->data_seek(0); while ($s = $q_sales->fetch_assoc()): ?>
                        <option value="<?= e($s['sales']) ?>" <?= ($s['sales'] === $sales_trans) ? 'selected' : '' ?>><?= e($s['sales']) ?></option>
                        <?php endwhile; } ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Bulan</label>
                    <select name="bulan_trans" class="form-select">
                        <option value="0">-- Semua --</option>
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= ($m == $bulan_trans) ? 'selected' : '' ?>><?= $m ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Tahun</label>
                    <select name="tahun_trans" class="form-select">
                        <option value="0">-- Semua --</option>
                        <?php for ($y = date('Y'); $y >= date('Y') - 10; $y--): ?>
                        <option value="<?= $y ?>" <?= ($y == $tahun_trans) ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">Filter</button>
                </div>
            </form>

            <div class="mb-3">
                <button type="button" class="btn btn-primary me-2" id="tabPendingBtn">Pending</button>
                <button type="button" class="btn btn-outline-primary" id="tabBerhasilBtn">Berhasil</button>
            </div>

            <div id="tabPending" style="display:block;">
                <form method="post" id="deleteForm">
                    <div class="bulk-action-bar">
                        <button type="submit" name="hapus_selected" class="btn btn-danger" onclick="return confirm('Yakin ingin menghapus data yang dipilih?')">Hapus Terpilih</button>
                        <small class="text-muted">Pilih checkbox baris yang akan dihapus, lalu klik Hapus Terpilih.</small>
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
                            <?php if (isset($res_pending) && $res_pending): while ($row = $res_pending->fetch_assoc()): ?>
                                <tr>
                                    <td><input type="checkbox" name="selected_ids[]" value="<?= e($row['ids']) ?>"></td>
                                    <td><?= e($row['nama']) ?></td>
                                    <td><?= e($row['periode']) ?></td>
                                    <td>Rp<?= number_format((float) $row['total_pembayaran'], 0, ',', '.') ?></td>
                                    <td><?= e($row['jumlah_transaksi']) ?></td>
                                    <td><span class="badge bg-warning text-dark">Pending</span></td>
                                    <td class="action-cell">
                                        <div class="action-wrap">
                                        <button type="submit" name="hapus_ids" value="<?= e($row['ids']) ?>" class="btn btn-sm btn-danger" onclick="return confirm('Yakin ingin menghapus semua transaksi ini?')">Hapus</button>
                                        <?php if ($app_keuangan_exists && $AKSES == 'ADMIN') { ?>
                                            <button type="button" class="btn btn-sm btn-outline-secondary js-btn-cek-awal" data-ids="<?= e($row['ids']) ?>" data-bs-toggle="modal" data-bs-target="#modalCekAwal">Cek</button>
                                            <a href="../keuangan/accrekapsales.php" class="btn btn-sm btn-primary">ACC</a>
                                        <?php } elseif ($AKSES == 'ADMIN' || $AKSES == 'ASSISTANT' || $AKSES == 'USER') { ?>
                                            <button type="button" class="btn btn-sm btn-outline-secondary js-btn-cek-awal" data-ids="<?= e($row['ids']) ?>" data-bs-toggle="modal" data-bs-target="#modalCekAwal">Cek</button>
                                            <button type="submit" name="acc_ids" value="<?= e($row['ids']) ?>" class="btn btn-sm btn-primary" onclick="return confirm('ACC semua transaksi ini?')">ACC</button>
                                        <?php } else { echo '-'; } ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; endif; ?>
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
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (isset($res_berhasil) && $res_berhasil): while ($row = $res_berhasil->fetch_assoc()): ?>
                            <tr>
                                <td><?= e($row['id']) ?></td>
                                <td><?= e($row['nama']) ?></td>
                                <td><?= e($row['tanggal']) ?></td>
                                <td><?= e($row['periode']) ?></td>
                                <td>Rp<?= number_format((float) $row['pembayaran'], 0, ',', '.') ?></td>
                                <td><span class="badge bg-success">Berhasil</span></td>
                            </tr>
                        <?php endwhile; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var btnPending = document.getElementById('tabPendingBtn');
    var btnBerhasil = document.getElementById('tabBerhasilBtn');
    var tabPending = document.getElementById('tabPending');
    var tabBerhasil = document.getElementById('tabBerhasil');
    if (btnPending && btnBerhasil && tabPending && tabBerhasil) {
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
    }

    var selectAll = document.getElementById('selectAll');
    if (selectAll) {
        selectAll.addEventListener('change', function() {
            var checkboxes = document.querySelectorAll('input[name="selected_ids[]"]');
            checkboxes.forEach(function(checkbox) {
                checkbox.checked = selectAll.checked;
            });
        });
    }

    var awalModalEl = document.getElementById('modalCekAwal');

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    if (awalModalEl) {
        awalModalEl.addEventListener('show.bs.modal', function(event) {
            var titleEl = awalModalEl.querySelector('.modal-title');
            var bodyEl = awalModalEl.querySelector('tbody');
            var trigger = event.relatedTarget;
            var selectedAwalIds = trigger ? (trigger.getAttribute('data-ids') || '') : '';

            if (!selectedAwalIds) {
                titleEl.textContent = 'Cek Detail Rekap Awal';
                bodyEl.innerHTML = '<tr><td colspan="7" class="text-center text-muted">Data rekap tidak ditemukan.</td></tr>';
                return;
            }

            titleEl.textContent = 'Cek Detail Rekap Awal (Memuat...)';
            bodyEl.innerHTML = '<tr><td colspan="7" class="text-center text-muted">Memuat detail data...</td></tr>';

            fetch(window.location.pathname + '?ajax_cek_ids_awal=' + encodeURIComponent(selectedAwalIds), {
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
                    titleEl.textContent = 'Cek Detail Rekap Awal: ' + (data.title || '-');
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
                            '<td>' + escapeHtml(row.tanggal_bayar_awal) + '</td>' +
                        '</tr>';
                    }).join('');
                    bodyEl.innerHTML = rowsHtml;
                })
                .catch(function() {
                    titleEl.textContent = 'Cek Detail Rekap Awal';
                    bodyEl.innerHTML = '<tr><td colspan="7" class="text-center text-danger">Gagal memuat detail data.</td></tr>';
                });
        });
    }
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<div class="modal fade" id="modalCekAwal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Cek Detail Rekap Awal</h5>
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
                                <th>Alamat</th>
                                <th>Tanggal Bayar Awal</th>
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
<?php include 'footer.php'; ?>
