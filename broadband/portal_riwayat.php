<?php
include 'cek_sesi.php';

$complaintFlash = '';
$complaintFlashType = 'success';

if (isset($_GET['action']) && $_GET['action'] === 'cancel_laporan') {
    include '../koneksidbabsensi.php';
    $absensiConn = $conn ?? null;

    $ticketId = isset($_GET['report_id']) ? (int)$_GET['report_id'] : 0;
    $idpelCancel = isset($_GET['cari']) ? trim((string)$_GET['cari']) : '';

    if ($ticketId > 0 && $idpelCancel !== '' && $absensiConn instanceof mysqli) {
        $checkSql = "SELECT id, status FROM joblist WHERE id = ? AND tipe = 'MAINTENANCE' AND data LIKE ? LIMIT 1";
        $checkStmt = $absensiConn->prepare($checkSql);
        $likeVal = '%IDPEL:' . $idpelCancel . '%';
        if ($checkStmt) {
            $checkStmt->bind_param('is', $ticketId, $likeVal);
            $checkStmt->execute();
            $ticketRes = $checkStmt->get_result();
            $ticketRow = $ticketRes ? $ticketRes->fetch_assoc() : null;
            $checkStmt->close();

            $cancelableStatuses = ['BARU', 'PENDING', 'MENUNGGU', 'OPEN', 'PROCESS', 'PROSES'];
            $status = strtoupper(trim((string)($ticketRow['status'] ?? '')));

            if ($ticketRow && in_array($status, $cancelableStatuses, true)) {
                $deleteStmt = $absensiConn->prepare("DELETE FROM joblist WHERE id = ? LIMIT 1");
                if ($deleteStmt) {
                    $deleteStmt->bind_param('i', $ticketId);
                    $deleteStmt->execute();
                    $affected = $deleteStmt->affected_rows;
                    $deleteStmt->close();

                    if ($affected > 0) {
                        header('Location: portal_riwayat.php?cari=' . urlencode($idpelCancel) . '&notif=laporan_dibatalkan');
                        exit;
                    }
                }
            }
        }
    }

    header('Location: portal_riwayat.php?cari=' . urlencode($idpelCancel) . '&notif=laporan_gagal_dibatalkan');
    exit;
}

if (isset($_GET['notif'])) {
    if ($_GET['notif'] === 'laporan_dibatalkan') {
        $complaintFlash = 'Tiket pengaduan berhasil dibatalkan.';
        $complaintFlashType = 'success';
    } elseif ($_GET['notif'] === 'laporan_gagal_dibatalkan') {
        $complaintFlash = 'Tiket pengaduan tidak dapat dibatalkan.';
        $complaintFlashType = 'error';
    }
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Riwayat Laporan & Transaksi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
     <style>
        :root {
            --primary-green: #0d6efd;
            --dark-green: #0a58ca;
            --orange: #F7941D;
            --white: #ffffff;
            --light-gray: #f8f9fa;
            --border-color: #e9ecef;
        }
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        body {
            background-color: #f0f2f5;
            color: #333;
            padding-bottom: 80px;
        }
        .page-wrapper {
            max-width: 900px;
            margin: 0 auto;
            padding: 16px 12px;
        }
        .section-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--primary-green);
            padding: 8px 0 6px 4px;
            border-left: 4px solid var(--orange);
            padding-left: 10px;
            margin-bottom: 10px;
            margin-top: 18px;
        }
        .timeline-card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 1px 6px rgba(0,0,0,0.08);
            margin-bottom: 10px;
            overflow: hidden;
            border-left: 4px solid var(--primary-green);
        }
        .timeline-card.laporan  { border-left-color: #17a2b8; }
        .timeline-card.transaksi { border-left-color: var(--orange); }
        .card-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding: 10px 14px 6px;
            gap: 8px;
        }
        .card-badge-row {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            align-items: center;
        }
        .badge-type {
            font-size: 11px;
            font-weight: 700;
            padding: 3px 9px;
            border-radius: 20px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        .badge-laporan  { background: #d1ecf1; color: #0c5460; }
        .badge-transaksi { background: #fff3cd; color: #856404; }
        .badge-status {
            font-size: 11px;
            font-weight: 700;
            padding: 3px 9px;
            border-radius: 20px;
        }
        .status-BERHASIL { background:#d4edda; color:#155724; }
        .status-PENDING  { background:#fff3cd; color:#856404; }
        .status-GAGAL    { background:#f8d7da; color:#721c24; }
        .status-other    { background:#e2e3e5; color:#383d41; }
        .card-waktu {
            font-size: 11.5px;
            color: #888;
            white-space: nowrap;
        }
        .card-body-detail {
            padding: 6px 14px 12px;
        }
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
            gap: 6px 14px;
            margin-top: 6px;
        }
        .detail-item {
            display: flex;
            flex-direction: column;
        }
        .detail-label {
            font-size: 10.5px;
            text-transform: uppercase;
            color: #999;
            font-weight: 600;
            letter-spacing: 0.3px;
        }
        .detail-value {
            font-size: 13px;
            color: #222;
            font-weight: 500;
            word-break: break-word;
        }
        .detail-value.harga {
            color: var(--primary-green);
            font-weight: 700;
        }
        .desc-text {
            font-size: 13px;
            color: #444;
            line-height: 1.45;
            padding: 4px 0 6px;
            border-bottom: 1px solid #f0f0f0;
            margin-bottom: 4px;
            word-break: break-word;
        }
        .navbar {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background-color: var(--primary-green);
            display: flex;
            justify-content: space-around;
            padding: 10px 0;
            z-index: 100;
        }
        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            color: white;
            font-size: 12px;
            text-decoration: none;
            transition: all 0.3s ease;
            padding: 5px 8px;
            border-radius: 8px;
            min-width: 60px;
            outline: none;
        }
        .nav-item:hover,
        .nav-item:focus {
            background-color: var(--orange);
            color: white !important;
            box-shadow: 0 0 0 2px var(--orange), 0 2px 8px rgba(0,0,0,0.10);
            border: 2px solid var(--orange);
            z-index: 2;
        }
        .nav-icon {
            margin-bottom: 5px;
            font-size: 18px;
            transition: all 0.3s ease;
        }
        .nav-item.active,
        .nav-item.active:focus {
            color: white !important;
            background: linear-gradient(135deg, var(--orange) 60%, var(--primary-green) 100%);
            border: 2px solid var(--orange);
            box-shadow: 0 0 0 2px var(--orange), 0 2px 8px rgba(0,0,0,0.10);
            font-weight: bold;
            z-index: 3;
        }
        .nav-item.active .nav-icon {
            transform: scale(1.1);
        }
        /* ...existing code... */
    </style>

    <script>
    // Extract dominant color from logo (profile-[useraccount].png if exists, else logo.png) and set CSS variables (logo not displayed)
    document.addEventListener('DOMContentLoaded', function() {
        var logoUrl = '';
        <?php
        // $logo_path sudah dihitung (server-per-area aware) di cek_sesi.php
        echo "logoUrl = '" . addslashes($logo_path ?? '') . "?v=" . time() . "';\n";
        ?>
        if (!logoUrl) return;
        var img = document.createElement('img');
        img.crossOrigin = 'Anonymous';
        img.src = logoUrl;
        img.style.display = 'none';
        document.body.appendChild(img);
        img.onload = function() {
            try {
                var canvas = document.createElement('canvas');
                canvas.width = img.naturalWidth || 120;
                canvas.height = img.naturalHeight || 120;
                var ctx = canvas.getContext('2d');
                ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
                var data = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
                var colorCounts = {};
                for (var i = 0; i < data.length; i += 12) {
                    var r = data[i], g = data[i+1], b = data[i+2], a = data[i+3];
                    if (a < 128 || (r > 240 && g > 240 && b > 240) || (r < 15 && g < 15 && b < 15)) continue;
                    var color = r+','+g+','+b;
                    colorCounts[color] = (colorCounts[color]||0)+1;
                }
                var sorted = Object.entries(colorCounts).sort(function(a,b){return b[1]-a[1];});
                if (sorted.length) {
                    var primary = sorted[0][0];
                    var secondary = sorted[1] ? sorted[1][0] : primary;
                    var accent = sorted[2] ? sorted[2][0] : primary;
                    document.documentElement.style.setProperty('--primary-green', 'rgb('+primary+')');
                    document.documentElement.style.setProperty('--orange', 'rgb('+secondary+')');
                    document.documentElement.style.setProperty('--dark-green', 'rgb('+accent+')');
                }
            } catch(e) {}
            img.remove();
        };
    });

    </script>
    <style>
        .payment-status {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 15px;
            font-weight: bold;
            font-size: 14px;
        }
        .status-paid {
            background-color: #4caf50;
            color: white;
        }
        .status-unpaid {
            background-color: var(--orange);
            color: white;
        }
        .status-expired {
            background-color: #f44336;
            color: white;
        }
        .payment-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        .payment-button {
            flex: 1;
            padding: 10px;
            border: none;
            border-radius: 8px;
            font-weight: bold;
            cursor: pointer;
        }

   
        .btn-success {
            background-color: var(--dark-green);
            color: white;
        }

        .btn-danger {
            background-color: #f44336;
            color: white;
        }

        /* Bill Details Section */
        .bill-details {
            background-color: var(--light-gray);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }

        .bill-details-header {
            font-weight: bold;
            color: var(--primary-green);
            margin-bottom: 15px;
            font-size: 18px;
        }

        .bill-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }

        .bill-table th, .bill-table td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }

        .bill-table th {
            color: var(--primary-green);
            font-weight: bold;
        }

        .bill-total {
            background-color: #e8f5e9;
            font-weight: bold;
        }

        /* Payment Method Section */
        .payment-method {
            background-color: var(--light-gray);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }

        .payment-method-header {
            font-weight: bold;
            color: var(--primary-green);
            margin-bottom: 15px;
            font-size: 18px;
        }

        .method-select {
            width: 100%;
            padding: 10px;
            border-radius: 8px;
            border: 1px solid #ddd;
            margin-bottom: 15px;
            font-size: 16px;
        }

        .submit-button {
            background-color: var(--orange);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 8px;
            font-weight: bold;
            cursor: pointer;
            width: 100%;
            font-size: 16px;
        }

        /* Manual Payment Section */
        .manual-payment {
            background-color: var(--light-gray);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }

        .manual-payment-header {
            font-weight: bold;
            color: var(--primary-green);
            margin-bottom: 15px;
            font-size: 18px;
        }

        .bank-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        .bank-table th, .bank-table td {
            padding: 10px;
            text-align: left;
            border: 1px solid #ddd;
        }

        .bank-table th {
            background-color: #e3f2fd;
            color: var(--primary-green);
            font-weight: bold;
        }

        .upload-form {
            margin-top: 20px;
        }

        .file-input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 8px;
            margin-bottom: 15px;
        }

        .upload-button {
            background-color: var(--dark-green);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 8px;
            font-weight: bold;
            cursor: pointer;
            width: 100%;
        }

        /* Alert Styles */
        .alert {
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 15px;
        }

        .alert-success {
            background-color: #e8f5e9;
            color: #2e7d32;
            border: 1px solid #4caf50;
        }

        .alert-info {
            background-color: #e3f2fd;
            color: #1565c0;
            border: 1px solid #2196f3;
        }

        .alert-error {
            background-color: #ffebee;
            color: #c62828;
            border: 1px solid #f44336;
        }

        /* Ads Section - Full Width */
        .ads-section {
            margin-bottom: 20px;
        }

        .ads-title {
            font-weight: bold;
            color: var(--primary-green);
            margin-bottom: 15px;
            font-size: 18px;
        }

        .ad-card {
            background-color: var(--light-gray);
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            margin-bottom: 15px;
            width: 100%;
        }

        .ad-image {
            height: 150px;
            background: linear-gradient(135deg, var(--primary-green), var(--dark-green));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 16px;
            position: relative;
        }

        .ad-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            background-color: var(--orange);
            color: white;
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: bold;
        }

        .ad-content {
            padding: 15px;
        }

        .ad-title {
            font-weight: bold;
            color: var(--primary-green);
            margin-bottom: 8px;
            font-size: 16px;
        }

        .ad-desc {
            font-size: 14px;
            color: #666;
            margin-bottom: 12px;
            line-height: 1.4;
        }

        .ad-features {
            margin-bottom: 15px;
        }

        .ad-feature {
            display: flex;
            align-items: center;
            margin-bottom: 5px;
            font-size: 13px;
            color: #555;
        }

        .ad-feature:before {
            content: "✓";
            color: #4caf50;
            margin-right: 8px;
            font-weight: bold;
        }

        .ad-button {
            background-color: var(--orange);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 20px;
            font-weight: bold;
            cursor: pointer;
            width: 100%;
            font-size: 14px;
        }

        /* Navigation Bar */
        .navbar {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background-color: var(--primary-green);
            display: flex;
            justify-content: space-around;
            padding: 10px 0;
            z-index: 100;
        }

        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            color: white;
            font-size: 12px;
            text-decoration: none;
        }

        .nav-icon {
            margin-bottom: 5px;
            font-size: 18px;
        }

        .nav-item.active {
            color: var(--orange);
        }

        /* Iframe Container */
        .iframe-container {
            flex: 1;
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        .iframe-wrapper {
            flex: 1;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .full-iframe {
            width: 100%;
            height: 100%;
            border: none;
            display: block;
        }

        /* Loading Message */
        .loading-message {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100%;
            background-color: var(--light-gray);
            color: #666;
            font-size: 16px;
        }
    </style>
</head>
<body>
<div class="page-wrapper">

    <?php
    include '../koneksidbabsensi.php';
    $absensiConn = $conn ?? null;
    $idpel_raw = isset($_GET['cari']) ? filter_input(INPUT_GET, 'cari', FILTER_SANITIZE_STRING) : '';
    $idpel = $idpel_raw;
    $timeline = [];

    // --- LAPORAN (koneksi absensi) ---
    $stmt11 = $absensiConn instanceof mysqli ? $absensiConn->prepare("SELECT * FROM joblist WHERE data LIKE ? ORDER BY waktu DESC LIMIT 200") : false;
    if ($stmt11) {
        $likeVal = '%' . $idpel . '%';
        $stmt11->bind_param('s', $likeVal);
        $stmt11->execute();
        $resultLaporan = $stmt11->get_result();
        if ($resultLaporan) {
            while ($row = $resultLaporan->fetch_assoc()) {
                $timeline[] = ['type' => 'laporan', 'row' => $row];
            }
        }
        $stmt11->close();
    }

    // --- TRANSAKSI (koneksi billing) ---
    include '../koneksidb.php';
    require_once __DIR__ . '/../notifbot/notifphp/tagihan_status_lib.php';
    $billingConn = $conn ?? null;
    $stmtTrans = $billingConn instanceof mysqli ? $billingConn->prepare("SELECT * FROM transaksi WHERE IDPEL = ? ORDER BY waktu DESC LIMIT 200") : false;
    if ($stmtTrans) {
        $stmtTrans->bind_param('s', $idpel);
        $stmtTrans->execute();
        $resultTrans = $stmtTrans->get_result();
        if ($resultTrans) {
            while ($row = $resultTrans->fetch_assoc()) {
                $timeline[] = ['type' => 'transaksi', 'row' => $row];
            }
        }
        $stmtTrans->close();
    }

    // Jatuh tempo ditempel PER TRANSAKSI (pakai tanggal bayar transaksi itu
    // sendiri sbg acuan -- BUKAN "jatuh tempo berikutnya" global yang sama utk
    // semua baris) supaya tampil otomatis lewat detail-grid generik di
    // renderCard() -- admin/pelanggan tidak bingung kalau kolom PENGUNAAN (nama
    // bulan) tidak lagi 1:1 dgn tanggal jatuh tempo aktual pada mode ini.
    if ($billingConn instanceof mysqli && $idpel !== '') {
        $pelRiwayatQ = mysqli_query($billingConn, "SELECT TIPE_TEMPO, TANGGALPASANG, TANGGAL_MONTHVERSARY FROM pelanggan WHERE IDPEL = '" . mysqli_real_escape_string($billingConn, $idpel) . "' LIMIT 1");
        $pelRiwayat = $pelRiwayatQ ? mysqli_fetch_assoc($pelRiwayatQ) : null;
        if ($pelRiwayat) {
            $tipeTempoRiwayat = strtolower(trim((string)($pelRiwayat['TIPE_TEMPO'] ?? '')));
            if (in_array($tipeTempoRiwayat, ['monthversary', 'mengikuti_tanggal_bayar'], true)) {
                $anchorDayRiwayat = 25;
                if ($tipeTempoRiwayat === 'monthversary') {
                    $anchorDateRiwayat = (string)($pelRiwayat['TANGGAL_MONTHVERSARY'] ?? '');
                    if ($anchorDateRiwayat === '' || strtotime($anchorDateRiwayat) === false) {
                        $anchorDateRiwayat = (string)($pelRiwayat['TANGGALPASANG'] ?? '');
                    }
                    if ($anchorDateRiwayat !== '' && strtotime($anchorDateRiwayat) !== false) {
                        $anchorDayRiwayat = (int)date('j', strtotime($anchorDateRiwayat));
                    }
                }

                foreach ($timeline as &$timelineItem) {
                    if ($timelineItem['type'] !== 'transaksi') {
                        continue;
                    }
                    $rowStatus = strtoupper(trim((string)($timelineItem['row']['STATUS'] ?? '')));
                    if ($rowStatus === 'PENAGIHAN') {
                        // Kolom TANGGALBAYAR baris ini sendiri sudah direlabel jadi
                        // JATUH TEMPO di renderCard(), tidak perlu badge tambahan.
                        continue;
                    }
                    $rowWaktu = (string)($timelineItem['row']['waktu'] ?? '');
                    if (empty($rowWaktu) || strtotime($rowWaktu) === false) {
                        continue;
                    }
                    if ($tipeTempoRiwayat === 'mengikuti_tanggal_bayar') {
                        $timelineItem['row']['JATUH TEMPO BERIKUTNYA'] = date('d-m-Y', strtotime('+30 days', strtotime($rowWaktu)));
                    } else {
                        $dueRow = tagihanGetFirstDueDateFixed(date('Y-m-d', strtotime($rowWaktu)), $anchorDayRiwayat);
                        if (!empty($dueRow)) {
                            $timelineItem['row']['JATUH TEMPO BERIKUTNYA'] = date('d-m-Y', strtotime($dueRow));
                        }
                    }
                }
                unset($timelineItem);
            }
        }
    }

    // Urutkan semua berdasarkan waktu DESC
    usort($timeline, function($a, $b) {
        return strtotime($b['row']['waktu'] ?? 0) - strtotime($a['row']['waktu'] ?? 0);
    });

    // Pisahkan untuk tampilan per section
    $list_laporan   = array_values(array_filter($timeline, fn($x) => $x['type'] === 'laporan'));
    $list_transaksi = array_values(array_filter($timeline, fn($x) => $x['type'] === 'transaksi'));

    // Urutkan transaksi berdasarkan periode penggunaan (bulan-tahun) terbaru di atas.
    function parsePenggunaanSortParts($penggunaan) {
        $months = [
            'januari' => 1,
            'februari' => 2,
            'maret' => 3,
            'april' => 4,
            'mei' => 5,
            'juni' => 6,
            'juli' => 7,
            'agustus' => 8,
            'september' => 9,
            'oktober' => 10,
            'november' => 11,
            'desember' => 12
        ];

        $text = strtolower(trim((string)$penggunaan));
        if ($text !== '' && preg_match('/(januari|februari|maret|april|mei|juni|juli|agustus|september|oktober|november|desember)\s+(\d{4})/i', $text, $m)) {
            $month = $months[strtolower($m[1])] ?? 0;
            $year = (int)$m[2];
            return [$year, $month];
        }

        return [0, 0];
    }

    usort($list_transaksi, function($a, $b) {
        $rowA = $a['row'] ?? [];
        $rowB = $b['row'] ?? [];

        $penggunaanA = $rowA['PENGUNAAN'] ?? ($rowA['PENGGUNAAN'] ?? '');
        $penggunaanB = $rowB['PENGUNAAN'] ?? ($rowB['PENGGUNAAN'] ?? '');

        [$yearA, $monthA] = parsePenggunaanSortParts($penggunaanA);
        [$yearB, $monthB] = parsePenggunaanSortParts($penggunaanB);

        if ($yearA !== $yearB) {
            return $yearB <=> $yearA;
        }
        if ($monthA !== $monthB) {
            return $monthB <=> $monthA;
        }

        $timeA = strtotime((string)($rowA['waktu'] ?? '')) ?: 0;
        $timeB = strtotime((string)($rowB['waktu'] ?? '')) ?: 0;
        return $timeB <=> $timeA;
    });

    // Kolom yang tidak perlu ditampilkan
    $skipCols = ['id', 'data']; // 'data' ditampilkan khusus sebagai deskripsi

    function renderBadgeStatus($status) {
        $cls = in_array(strtoupper($status), ['BERHASIL','SUCCESS','SUKSES']) ? 'status-BERHASIL'
             : (in_array(strtoupper($status), ['PENDING','MENUNGGU']) ? 'status-PENDING'
             : (in_array(strtoupper($status), ['GAGAL','FAILED','EXPIRED']) ? 'status-GAGAL' : 'status-other'));
        return '<span class="badge-status ' . $cls . '">' . htmlspecialchars($status) . '</span>';
    }

    function isComplaintCancelableStatus($status) {
        return in_array(strtoupper(trim((string)$status)), ['BARU', 'PENDING', 'MENUNGGU', 'OPEN', 'PROCESS', 'PROSES'], true);
    }

    function renderCard($type, $row, $skipCols) {
        $waktu  = htmlspecialchars($row['waktu'] ?? '-');
        $status = htmlspecialchars($row['STATUS'] ?? $row['status'] ?? '-');
        $rawStatus = (string)($row['STATUS'] ?? $row['status'] ?? '');
        $label  = $type === 'laporan' ? 'Laporan' : 'Transaksi';
        $badgeCls = $type === 'laporan' ? 'badge-laporan' : 'badge-transaksi';
        $idpelParam = urlencode((string)($_GET['cari'] ?? ''));

        // Kolom khusus
        $desc = '';
        if ($type === 'laporan' && !empty($row['data'])) {
            $desc = $row['data'];
        }

        echo '<div class="timeline-card ' . $type . '">';
        echo   '<div class="card-top">';
        echo     '<div class="card-badge-row">';
        echo       '<span class="badge-type ' . $badgeCls . '">' . $label . '</span>';
        if (!empty($row['tipe'])) echo '<span class="badge-type" style="background:#e8eaf6;color:#3c4043;">' . htmlspecialchars($row['tipe']) . '</span>';
        echo       renderBadgeStatus($status);
        echo     '</div>';
        echo     '<span class="card-waktu">' . $waktu . '</span>';
        echo   '</div>';
        echo   '<div class="card-body-detail">';
        if ($desc) echo '<div class="desc-text">' . htmlspecialchars($desc) . '</div>';
        echo     '<div class="detail-grid">';
        foreach ($row as $col => $val) {
            $colUp = strtoupper($col);
            if (in_array($col, $skipCols)) continue;
            if (in_array($colUp, ['WAKTU','STATUS','STATUS','TIPE'])) continue;
            if ($val === null || $val === '') continue;

            $isHarga = in_array($colUp, ['HARGA','NOMINAL','TOTAL','JUMLAH']);
            $displayVal = $isHarga
                ? 'Rp ' . number_format((float)$val, 0, ',', '.')
                : htmlspecialchars((string)$val);

            // Status PENAGIHAN (belum dibayar): kolom TANGGALBAYAR sebenarnya berisi
            // tanggal jatuh tempo invoice itu, bukan tanggal pembayaran sungguhan --
            // labelnya diganti supaya tidak membingungkan.
            $colLabel = ($colUp === 'TANGGALBAYAR' && strtoupper(trim($rawStatus)) === 'PENAGIHAN')
                ? 'JATUH TEMPO'
                : $col;

            echo '<div class="detail-item">';
            echo   '<span class="detail-label">' . htmlspecialchars($colLabel) . '</span>';
            echo   '<span class="detail-value' . ($isHarga ? ' harga' : '') . '">' . $displayVal . '</span>';
            echo '</div>';
        }
        echo     '</div>';
        if ($type === 'laporan' && !empty($row['id']) && isComplaintCancelableStatus($rawStatus)) {
            echo '<div class="payment-actions">';
            echo '<a href="portal_riwayat.php?cari=' . $idpelParam . '&action=cancel_laporan&report_id=' . (int)$row['id'] . '" class="payment-button btn-danger" onclick="return confirm(\'Yakin ingin membatalkan tiket pengaduan ini?\');">Batal</a>';
            echo '</div>';
        }
        echo   '</div>';
        echo '</div>';
    }
    ?>

    <?php if ($complaintFlash !== ''): ?>
        <div class="alert alert-<?= $complaintFlashType === 'error' ? 'error' : 'success'; ?>">
            <?= htmlspecialchars($complaintFlash); ?>
        </div>
    <?php endif; ?>

    <div class="section-title">📋 Riwayat Laporan (<?= count($list_laporan) ?> data)</div>
    <?php if (count($list_laporan) > 0): ?>
        <?php foreach ($list_laporan as $item): ?>
            <?php renderCard('laporan', $item['row'], $skipCols); ?>
        <?php endforeach; ?>
    <?php else: ?>
        <div style="text-align:center;padding:20px;color:#999;">Tidak ada laporan ditemukan</div>
    <?php endif; ?>

    <div class="section-title">💳 Riwayat Transaksi (<?= count($list_transaksi) ?> data)</div>
    <?php if (count($list_transaksi) > 0): ?>
        <?php foreach ($list_transaksi as $item): ?>
            <?php renderCard('transaksi', $item['row'], $skipCols); ?>
        <?php endforeach; ?>
    <?php else: ?>
        <div style="text-align:center;padding:20px;color:#999;">Tidak ada transaksi ditemukan</div>
    <?php endif; ?>
</div><!-- /.page-wrapper -->

  <!-- Navigation Bar -->
  <div class="navbar">
    <a href="portal_baru.php?cari=<?= $idpel; ?>" class="nav-item">
      <div class="nav-icon"><i class="bi bi-house-door-fill"></i></div>
      <div>Beranda</div>
    </a>
    <a href="portal_bayar.php?cari=<?= $idpel; ?>" class="nav-item">
      <div class="nav-icon"><i class="bi bi-credit-card"></i></div>
      <div>Bayar</div>
    </a>
    <a href="portal_chat.php?cari=<?= $idpel; ?>" class="nav-item">
      <div class="nav-icon"><i class="bi bi-chat-dots"></i></div>
      <div>Chat</div>
    </a>
        <a href="portal_mywifi.php?cari=<?= $idpel; ?>" class="nav-item">
            <div class="nav-icon"><i class="bi bi-wifi"></i></div>
            <div>WiFi Saya</div>
        </a>
    <a href="portal_riwayat.php?cari=<?= $idpel; ?>" class="nav-item active">
      <div class="nav-icon"><i class="bi bi-clock-history"></i></div>
      <div>Riwayat</div>
    </a>
    <a href="portal_profile.php?cari=<?= $idpel; ?>" class="nav-item ">
      <div class="nav-icon"><i class="bi bi-person-circle"></i></div>
      <div>Profile</div>
    </a>
  </div>
</body>
</html>