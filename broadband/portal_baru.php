<?php
include 'cek_sesi.php';
require_once __DIR__ . '/../notifbot/notifphp/tagihan_status_lib.php';

// Toggle "Monthversary ikut tanggal bayar terakhir" (Payment Setting) --
// SEBELUMNYA di-hardcode false di sini (tidak baca setting sungguhan), jadi
// kalau admin nyalakan toggle ini pelanggan Monthversary tetap lihat tanggal
// jatuh tempo yang salah di portal. Sekarang baca file yang sama dgn
// cek_tagihan_harian.php/notif_remainder_pembayaran.php.
$portalMonthversaryFollowLastPayment = false;
$portalMonthversaryConfigFile = __DIR__ . '/../notifbot/data/monthversary_setting-' . $username . '.json';
if (file_exists($portalMonthversaryConfigFile)) {
    $portalMvCfg = json_decode(file_get_contents($portalMonthversaryConfigFile), true);
    if (is_array($portalMvCfg) && isset($portalMvCfg['follow_last_payment'])) {
        $portalMonthversaryFollowLastPayment = !empty($portalMvCfg['follow_last_payment']);
    }
}

// Tanggal jatuh tempo berikutnya yang konkret, dihitung dgn fungsi yang SAMA
// dipakai tables.php/dashboard.php (bukan cuma "tanggal X tiap bulan" generik) --
// supaya pelanggan Rolling/Monthversary lihat tanggal pasti, bukan aturan umum.
$portalLastPaymentMap = [(string)($pelanggan['IDPEL'] ?? '') => $lastTransaction['waktu'] ?? null];
$portalLastPaidUsageMap = [(string)($pelanggan['IDPEL'] ?? '') => (string)($lastTransaction['PENGUNAAN'] ?? '')];
$portalJatuhTempoBerikutnya = tagihanHitungJatuhTempoBerikutnya($conn, [
    'IDPEL' => (string)($pelanggan['IDPEL'] ?? ''),
    'TANGGALPASANG' => (string)($pelanggan['TANGGALPASANG'] ?? ''),
    'TIPE_BAYAR' => (string)($pelanggan['TIPE_BAYAR'] ?? ''),
    'TIPE_TEMPO' => (string)($pelanggan['TIPE_TEMPO'] ?? ''),
    'TEMPO' => (string)($pelanggan['TEMPO'] ?? ''),
    'TANGGAL_MONTHVERSARY' => (string)($pelanggan['TANGGAL_MONTHVERSARY'] ?? ''),
], [
    'jatuh_tempo_hari' => (int)($jatuh_tempo ?? 25),
    'lastPaymentMap' => $portalLastPaymentMap,
    'lastPaidUsageMap' => $portalLastPaidUsageMap,
    'monthversary_follow_last_payment' => $portalMonthversaryFollowLastPayment,
]);

// Fixed Due Date: PAKSA selalu jatuh di jatuh_tempo_hari bulan berjalan/berikutnya
// (sama seperti tables.php) -- tanpa ini, utk prabayar hasil di atas bisa "nyangkut"
// di bulan lama krn dihitung dari PENGUNAAN terakhir yg sudah dibayar, bukan siklus
// kalender sekarang.
if (strtolower(trim((string)($pelanggan['TIPE_TEMPO'] ?? ''))) === 'mengikuti_tanggal_tempo') {
    $fixedDueDayPaksaBeranda = (int)($jatuh_tempo ?? 25);
    $todayTsPaksaBeranda = strtotime(date('Y-m-d'));
    $dueMonthTsPaksaBeranda = ((int) date('j', $todayTsPaksaBeranda) <= $fixedDueDayPaksaBeranda)
        ? $todayTsPaksaBeranda
        : strtotime('+1 month', $todayTsPaksaBeranda);
    $jatuhTempoPaksaBeranda = tagihanBuildMonthlyDate(
        (int) date('Y', $dueMonthTsPaksaBeranda),
        (int) date('n', $dueMonthTsPaksaBeranda),
        $fixedDueDayPaksaBeranda
    );
    if (!empty($jatuhTempoPaksaBeranda)) {
        $portalJatuhTempoBerikutnya = $jatuhTempoPaksaBeranda;
    }
}

// Sisa hari aktif (ditampilkan utk pelanggan Rolling) -- dihitung dari tanggal
// jatuh tempo berikutnya yang BENAR di atas (siklus penuh via
// tagihanHitungJatuhTempoBerikutnya()), BUKAN window 30 hari hardcode dari
// tanggal transaksi terakhir (logika lama, dipindah dari cek_sesi.php).
$sisaHariAktif = 0;
if ($portalJatuhTempoBerikutnya !== '') {
    $sisaHariAktifTs = strtotime($portalJatuhTempoBerikutnya) - strtotime(date('Y-m-d'));
    $sisaHariAktif = $sisaHariAktifTs > 0 ? (int) ceil($sisaHariAktifTs / 86400) : 0;
}

if (!function_exists('portalFormatTanggalIndo')) {
    function portalFormatTanggalIndo(string $ymd): string
    {
        $ts = strtotime($ymd);
        if ($ts === false) {
            return '-';
        }
        $bulanIndo = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        return date('d', $ts) . ' ' . $bulanIndo[(int)date('n', $ts) - 1] . ' ' . date('Y', $ts);
    }
}

$complaintFlash = '';
$complaintFlashType = 'success';
$idpelRef = isset($idpel) ? trim((string)$idpel) : trim((string)($_GET['cari'] ?? ''));

$billingConn = $conn ?? null;
$absensiConn = null;
@include '../koneksidbabsensi.php';
if (isset($conn) && $conn instanceof mysqli) {
    $absensiConn = $conn;
}
if ($billingConn instanceof mysqli) {
    $conn = $billingConn;
}

if (isset($_GET['action']) && $_GET['action'] === 'cancel_laporan') {
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

            $status = strtoupper(trim((string)($ticketRow['status'] ?? '')));
            if ($ticketRow && $status === 'BARU') {
                $deleteStmt = $absensiConn->prepare("DELETE FROM joblist WHERE id = ? LIMIT 1");
                if ($deleteStmt) {
                    $deleteStmt->bind_param('i', $ticketId);
                    $deleteStmt->execute();
                    $affected = $deleteStmt->affected_rows;
                    $deleteStmt->close();

                    if ($affected > 0) {
                        header('Location: portal_baru.php?cari=' . urlencode($idpelCancel) . '&notif=laporan_dibatalkan');
                        exit;
                    }
                }
            }
        }
    }

    header('Location: portal_baru.php?cari=' . urlencode($idpelCancel) . '&notif=laporan_gagal_dibatalkan');
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

$berandaComplaints = [];
if ($absensiConn instanceof mysqli && $idpelRef !== '') {
    $laporanSql = "SELECT id, tgl, status, tipe, data FROM joblist WHERE tipe = 'MAINTENANCE' AND data LIKE ? ORDER BY id DESC LIMIT 5";
    $laporanStmt = $absensiConn->prepare($laporanSql);
    if ($laporanStmt) {
        $lapLike = '%IDPEL:' . $idpelRef . '%';
        $laporanStmt->bind_param('s', $lapLike);
        $laporanStmt->execute();
        $laporanRes = $laporanStmt->get_result();
        while ($laporanRes && ($lap = $laporanRes->fetch_assoc())) {
            $berandaComplaints[] = $lap;
        }
        $laporanStmt->close();
    }
}

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $useraccount ?> - Internet Service</title>
   <style>
        :root {
            --primary-green: #0d6efd; /* primary (blue) - kept variable name for compatibility */
            --dark-green: #0a58ca; /* dark primary (darker blue) */
            --orange: #F7941D; /* secondary (orange) */
            --white: #ffffff; /* background white */
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
            background-color: var(--white);
            color: #333;
            padding-bottom: 70px; /* Space for fixed navbar */
        }

        /* Link colors - white text with orange hover to match header.php */
        a {
            color: var(--white) !important;
        }

        a:hover {
            color: var(--orange) !important;
        }
        
        .container {
            max-width: 480px;
            margin: 0 auto;
            padding: 20px;
        }
        
        /* Header Section */
        .header {
            margin-bottom: 20px;
            text-align: center;
        }
        
        .user-info {
            background-color: var(--light-gray);
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 15px;
        }
        
        .user-name {
            font-weight: bold;
            font-size: 18px;
            color: var(--primary-green);
        }
        
        .user-id {
            color: #666;
            font-size: 14px;
        }
        
        /* Service Info Card */
        .service-card {
            background-color: var(--light-gray);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .service-name {
            font-weight: bold;
            font-size: 16px;
            color: var(--primary-green);
        }
        
        .service-speed {
            font-size: 24px;
            font-weight: bold;
            color: var(--orange);
            margin: 10px 0;
        }
        
        .bill-status {
            display: flex;
            justify-content: space-between;
            margin-top: 10px;
        }
        
        .bill-paid {
            color: #4caf50;
            font-weight: bold;
        }
        
        .package-price {
            color: var(--primary-green);
            font-weight: bold;
        }
        
        /* Status Section */
        .status-section {
            background-color: var(--light-gray);
            border-radius: 14px;
            padding: 20px 18px;
            margin-bottom: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 6px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }

        .status-icon-wrap {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 4px;
        }

        .status-dot {
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background: #4caf50;
            display: block;
            position: relative;
        }

        .status-dot::after {
            content: '';
            position: absolute;
            inset: -4px;
            border-radius: 50%;
            background: rgba(76,175,80,0.25);
            animation: pulse-ring 1.6s ease-out infinite;
        }

        @keyframes pulse-ring {
            0%   { transform: scale(0.8); opacity: 0.8; }
            100% { transform: scale(1.8); opacity: 0; }
        }

        .status-body {
            width: 100%;
            text-align: center;
        }

        .status-title {
            font-size: 12px;
            font-weight: 600;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 6px;
        }

        .status-working {
            font-size: 15px;
            font-weight: 700;
            color: #333;
            line-height: 1.4;
            margin-bottom: 4px;
        }
        
        /* B-Coin Section */
        .b-coin-section {
            background-color: var(--dark-green);
            color: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .b-coin-title {
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        .b-coin-desc {
            font-size: 14px;
            margin-bottom: 15px;
        }
        
        .b-coin-button {
            background-color: var(--orange);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 20px;
            font-weight: bold;
            cursor: pointer;
        }
        
        /* Payment Section */
        .payment-section {
            background-color: var(--light-gray);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .payment-title {
            font-weight: bold;
            color: var(--primary-green);
            margin-bottom: 10px;
        }
        
        .payment-option {
            display: flex;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #eee;
        }
        
        .payment-option:last-child {
            border-bottom: none;
        }
        
        .payment-icon {
            width: 30px;
            height: 30px;
            background-color: var(--primary-green);
            border-radius: 50%;
            margin-right: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
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
        
        .ads-container {
            display: flex;
            gap: 15px;
            overflow-x: auto;
            padding-bottom: 10px;
            scrollbar-width: thin;
            scrollbar-color: var(--primary-green) #f1f1f1;
        }
        
        .ads-container::-webkit-scrollbar {
            height: 6px;
        }
        
        .ads-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 3px;
        }
        
        .ads-container::-webkit-scrollbar-thumb {
            background: var(--primary-green);
            border-radius: 3px;
        }
        
        .ads-container::-webkit-scrollbar-thumb:hover {
            background: var(--dark-green);
        }
        
        .ad-card {
            background-color: var(--light-gray);
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            min-width: 280px;
            flex-shrink: 0;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .ad-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
        }
        
        .ad-image {
            height: 80px;
            background: linear-gradient(135deg, var(--primary-green), var(--dark-green));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 14px;
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
            padding: 10px;
        }
        
        .ad-title {
            font-weight: bold;
            color: var(--primary-green);
            margin-bottom: 5px;
            font-size: 14px;
        }
        
        .ad-desc {
            font-size: 12px;
            color: #666;
            margin-bottom: 8px;
            line-height: 1.3;
        }
        
        .ad-button {
            background-color: var(--orange);
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 15px;
            font-weight: bold;
            cursor: pointer;
            width: 100%;
            font-size: 12px;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }
        
        /* Action Grid (inside status card) */
        .action-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            width: 100%;
            margin-top: 14px;
        }

        .action-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 16px 10px;
            border-radius: 14px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            font-weight: 600;
            font-size: 13px;
            text-align: center;
            color: white;
            line-height: 1.3;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .action-btn:hover {
            transform: translateY(-3px);
            color: white;
        }

        .action-btn:active {
            transform: translateY(0);
        }

        .action-icon {
            font-size: 28px;
            line-height: 1;
        }

        .action-red {
            background: linear-gradient(135deg, #e53935, #c62828);
            box-shadow: 0 4px 12px rgba(229,57,53,0.35);
        }

        .action-red:hover {
            box-shadow: 0 7px 18px rgba(229,57,53,0.45);
        }

        .action-blue {
            background: linear-gradient(135deg, var(--primary-green), var(--dark-green));
            box-shadow: 0 4px 12px rgba(13,110,253,0.3);
        }

        .action-blue:hover {
            box-shadow: 0 7px 18px rgba(13,110,253,0.4);
        }

        .complaint-bottom-section {
            background-color: var(--light-gray);
            border-radius: 12px;
            padding: 14px;
            margin-bottom: 18px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.06);
        }

        .complaint-bottom-title {
            font-weight: 700;
            color: var(--primary-green);
            margin-bottom: 10px;
            font-size: 15px;
        }

        .complaint-bottom-item {
            background: #fff;
            border: 1px solid var(--border-color);
            border-left: 4px solid #17a2b8;
            border-radius: 10px;
            padding: 10px;
            margin-bottom: 8px;
        }

        .complaint-bottom-item:last-child {
            margin-bottom: 0;
        }

        .complaint-bottom-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            margin-bottom: 6px;
            flex-wrap: wrap;
        }

        .complaint-status {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            background: #e8eaf6;
            color: #3c4043;
            text-transform: uppercase;
        }

        .complaint-status-baru {
            background: #fff3cd;
            color: #856404;
        }

        .complaint-time {
            font-size: 12px;
            color: #666;
        }

        .complaint-desc {
            font-size: 13px;
            color: #333;
            line-height: 1.4;
            white-space: pre-wrap;
            margin-bottom: 8px;
        }

        .complaint-cancel-btn {
            display: inline-block;
            background: #dc3545;
            color: #fff !important;
            text-decoration: none;
            border: none;
            border-radius: 8px;
            padding: 7px 12px;
            font-size: 12px;
            font-weight: 600;
        }

        .complaint-empty {
            font-size: 13px;
            color: #777;
            text-align: center;
            padding: 8px 0;
        }

        .complaint-flash {
            border-radius: 10px;
            padding: 10px 12px;
            margin-bottom: 10px;
            font-size: 13px;
            font-weight: 600;
        }

        .complaint-flash-success {
            background: #e7f8ee;
            color: #1d6f42;
            border: 1px solid #b6e6c9;
        }

        .complaint-flash-error {
            background: #fdecee;
            color: #8a1f2a;
            border: 1px solid #f5c2c7;
        }
        
        /* Modal Customization - Fullscreen */
        #pengaduanModal {
            display: none !important;
            visibility: hidden;
            opacity: 0;
        }
        
        #pengaduanModal.show {
            display: block !important;
            visibility: visible;
            opacity: 1;
        }
        
        .modal-fullscreen .modal-content {
            border-radius: 0;
            border: none;
            height: 100vh;
        }
        
        .modal-fullscreen .modal-header {
            background: linear-gradient(135deg, var(--primary-green), var(--dark-green));
            color: white;
            border-radius: 0;
            padding: 15px 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            position: relative;
            z-index: 1;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header-buttons {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .modal-fullscreen .modal-body {
            height: calc(100vh - 70px);
            overflow: hidden;
        }
        
        .modal-title {
            font-size: 18px;
            font-weight: 600;
            margin: 0;
        }
        
        .btn-outline-light {
            border-color: rgba(255,255,255,0.3);
            color: white;
            font-weight: 600;
            padding: 6px 12px;
            font-size: 14px;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .btn-outline-light:hover {
            background-color: rgba(255,255,255,0.2);
            border-color: rgba(255,255,255,0.5);
            color: white;
            transform: translateY(-1px);
        }
        
        .btn-close {
            filter: invert(1);
            opacity: 0.8;
            font-size: 1.2rem;
        }
        
        .btn-close:hover {
            opacity: 1;
            transform: scale(1.1);
        }
        
        /* Loading and Notifications - Fullscreen */
        .spinner-border {
            width: 4rem;
            height: 4rem;
            color: var(--primary-green) !important;
        }
        
        #loadingIndicator {
            background: var(--white);
            height: 100%;
            display: none !important; /* Force hidden by default */
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }
        
        #loadingIndicator.show {
            display: flex !important;
        }
        
        /* Ensure floating button is hidden by default */
        #floatingCloseBtn {
            display: none !important;
        }
        
        #floatingCloseBtn.show {
            display: block !important;
        }
        
        /* Hide any potential notification remnants */
        .alert.position-fixed {
            display: none !important;
        }
        
        #loadingIndicator p {
            font-size: 16px;
            color: var(--primary-green);
            margin-top: 20px;
        }
        
        .position-fixed.alert {
            animation: slideInDown 0.5s ease-out;
        }
        
        @keyframes slideInDown {
            from {
                transform: translateX(-50%) translateY(-100%);
                opacity: 0;
            }
            to {
                transform: translateX(-50%) translateY(0);
                opacity: 1;
            }
        }
        
        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        /* Fullscreen modal animations */
        .modal.fade .modal-dialog.modal-fullscreen {
            transition: transform 0.3s ease-out;
            transform: scale(0.8) translateY(-50px);
        }
        
        .modal.show .modal-dialog.modal-fullscreen {
            transform: scale(1) translateY(0);
        }
        
        /* Mobile optimizations for fullscreen modal */
        @media (max-width: 576px) {
            .modal-fullscreen .modal-header {
                padding: 12px 15px;
            }
            
            .modal-title {
                font-size: 16px;
            }
            
            .btn-close {
                font-size: 1rem;
            }
            
            .modal-fullscreen .modal-body {
                height: calc(100vh - 60px);
            }
            
            #floatingCloseBtn {
                width: 50px !important;
                height: 50px !important;
                font-size: 16px !important;
            }
        }
        
        /* Floating Close Button */
        #floatingCloseBtn {
            transition: all 0.3s ease;
            animation: bounceIn 0.6s ease-out;
        }
        
        #floatingCloseBtn:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 20px rgba(220, 53, 69, 0.6);
        }
        
        @keyframes bounceIn {
            0% {
                transform: scale(0);
                opacity: 0;
            }
            50% {
                transform: scale(1.2);
            }
            100% {
                transform: scale(1);
                opacity: 1;
            }
        }
        
        /* Navigation Bar - Enhanced Fixed Positioning */
        .navbar {
            position: fixed !important;
            bottom: 0 !important;
            left: 0 !important;
            right: 0 !important;
            background-color: var(--primary-green);
            display: flex !important;
            justify-content: space-around;
            padding: 10px 0;
            z-index: 1050 !important; /* Higher than Bootstrap modal z-index (1040) */
            box-shadow: 0 -2px 10px rgba(0,0,0,0.15);
            border-top: 2px solid var(--dark-green);
            min-height: 70px;
            align-items: center;
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

        .nav-item.active .nav-icon {
            transform: scale(1.1);
        }

        /* Ensure body has proper padding for fixed navbar */
        body {
            background-color: var(--white);
            color: #333;
            padding-bottom: 80px !important; /* Extra space for enhanced navbar */
        }

        /* Prevent navbar from being affected by modal states */
        .modal-open .navbar {
            display: flex !important;
            position: fixed !important;
            z-index: 1055 !important; /* Even higher when modal is open */
        }
    </style>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
</head>
<body>

















 <!-- Header Section -->
        <div class="header">
            <div class="user-info">
                <!-- $logo_path sudah dihitung (server-per-area aware) di cek_sesi.php -->
                <img src="<?= $logo_path ?>?v=<?= time() ?>" class="img-fluid logo-dynamic" id="logoDynamic" alt="Logo" style="max-height: 100px;">
</style>
<script>
// Extract dominant color from logo and set CSS variables
function extractLogoColors(img) {
    if (!img || !img.complete) return;
    try {
        var canvas = document.createElement('canvas');
        var ctx = canvas.getContext('2d');
        canvas.width = img.naturalWidth || 120;
        canvas.height = img.naturalHeight || 120;
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
    } catch(e) {console.log('Logo color extraction failed',e);}
}
window.addEventListener('DOMContentLoaded', function() {
    var img = document.getElementById('logoDynamic');
    if (img && img.complete) extractLogoColors(img);
    else if (img) img.onload = function(){extractLogoColors(img);};
});
</script>
                     
            </div>
        </div>

        <!-- Header Section -->
        <div class="header">
            <div class="user-info">
                <div class="user-name"><?= htmlspecialchars($pelanggan['NAMA']); ?>  (<?= $AREA ?>)</div>
                <div class="user-id">ID PELANGGAN : <?= htmlspecialchars($pelanggan['IDPEL']); ?></div>
            </div>
        </div>
        
        <!-- Service Info Card -->
        <div class="service-card">
            <div class="service-name">Paket</div>
            <div>Internet Service</div>
            <div class="service-speed"><?= htmlspecialchars($pelanggan['PAKET']); ?></div>
            <div class="bill-status">
                <div>
                    <div>Tagihan <?= $periode; ?> </strong><br> ( <?= htmlspecialchars($pelanggan['TIPE_BAYAR']); ?> )</div>

                  <br>
                    <?php
                    echo 'Tanggal transaksi terakhir: ' . ($lastTransaction['waktu'] ?? 'Belum ada') . '<br>';
                    
                    ?>
                  
                    <div class="bill-paid"><?php 
                    
                    if($tampiltagihan=="SHOW")
                    {
                        echo 'Belum bayar';
                    }
                    else
                    {
                        echo 'Sudah bayar';
                    }
                    if($pelanggan['TIPE_TEMPO']=='mengikuti_tanggal_bayar')
                    {
                    echo '<br>Sisa hari aktif: ' . ($sisaHariAktif ?? 0) . ' hari';
                    }
                        ?></div>
                        <?php 
                    if($pelanggan['TIPE_TEMPO']=='mengikuti_tanggal_bayar')
                    {
                    ?>
                    <div>Tanggal jatuh tempo berikutnya: <?= $portalJatuhTempoBerikutnya !== '' ? portalFormatTanggalIndo($portalJatuhTempoBerikutnya) : '-' ?></div>
                    <?php
                    }
                    if($pelanggan['TIPE_TEMPO']=='mengikuti_tanggal_tempo')
                    {
                    ?>
                    <div>Tagihan berikutnya setiap tanggal <?= $tempo ?> Setiap bulan nya</div>
                    <?php
                    }
                    if($pelanggan['TIPE_TEMPO']=='monthversary')
                    {
                    ?>
                    <div>Tanggal jatuh tempo berikutnya: <?= $portalJatuhTempoBerikutnya !== '' ? portalFormatTanggalIndo($portalJatuhTempoBerikutnya) : '-' ?></div>
                    <?php
                    }
                    ?>
                </div>
                <div>
                    <div>Harga Paket</div>
                    <div class="package-price">
                        Rp<?= number_format($paketHarga, 0, ',', '.'); ?>
                        <div style="font-size:12px;color:#888;font-weight:normal;">Belum termasuk PPN</div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Status Section -->
        <div class="status-section">
            <div class="status-icon-wrap">
                <span class="status-dot"></span>
            </div>
            <div class="status-body">
                <div class="status-title"><i class="bi bi-router me-1"></i>Status Koneksi</div>
                <div class="status-working" id="loading">Memeriksa koneksi...</div>
            </div>
            <!-- Tombol Aksi Cepat -->
            <div class="action-grid">
                <button type="button" class="action-btn action-red" data-bs-toggle="modal" data-bs-target="#pengaduanModal" data-url="pengaduan.php?idselect=<?= htmlspecialchars($pelanggan['IDPEL']); ?>">
                    <div class="action-icon">🚨</div>
                    <div>Pengaduan<br>Gangguan</div>
                </button>
                <a href="portal_mywifi.php?cari=<?= urlencode((string)$pelanggan['IDPEL']); ?>" class="action-btn action-blue">
                    <div class="action-icon">📶</div>
                    <div>Ganti Nama /<br>Password WiFi</div>
                </a>
            </div>
        </div>
               <script>
try {
    document.getElementById('paymentMethods').addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        const payButton = document.getElementById('payButton');
        const paymentLogoContainer = document.getElementById('paymentLogoContainer');
        const paymentLogo = document.getElementById('paymentLogo');

        if (this.value) {
            payButton.style.display = 'block';
            paymentLogo.src = selectedOption.getAttribute('data-logo');
            paymentLogoContainer.style.display = 'block';
        } else {
            payButton.style.display = 'none';
            paymentLogoContainer.style.display = 'none';
        }
    });
} catch (e) {
    console.error('paymentMethods event error:', e);
}

function fetchData() {
    try {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', 'fetch_data2.php?idserver=<?php echo $idserver ?>&kodeodp=<?php echo $pelanggan['ODP'] ?>&idpel=<?php echo $pelanggan['IDPEL'] ?>', true);
        xhr.onreadystatechange = function() {
            if (xhr.readyState == 4 && xhr.status == 200) {
                // Tampilkan hasil status di div#loading
                document.getElementById('loading').innerHTML = xhr.responseText;
            }
        };
        xhr.onerror = function() {
            console.error('fetchData AJAX error:', xhr.statusText);
        };
        xhr.send();
    } catch (e) {
        console.error('fetchData error:', e);
    }
}

setInterval(fetchData, 500);
window.onload = function() {
    try {
        fetchData();
    } catch (e) {
        console.error('window.onload fetchData error:', e);
    }
};

function downloadInvoice() {
    try {
        window.print();
    } catch (e) {
        console.error('downloadInvoice error:', e);
    }
}

try {
    var adaData = <?php echo isset($adaData) ? $adaData : 'false'; ?>;
    if (adaData) {
        var payButton = document.getElementById("payButton");
        var paymentMethods = document.getElementById("paymentMethods");
        if (payButton) payButton.style.display = "none";
        if (paymentMethods) paymentMethods.style.display = "none";
    }
} catch (e) {
    console.error('adaData logic error:', e);
}
</script>
        <div class="container">

        <!-- Modal Pengaduan Gangguan - Fullscreen -->
        <div class="modal fade" id="pengaduanModal" tabindex="-1" aria-labelledby="pengaduanModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-fullscreen">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="pengaduanModalLabel">🚨 Form Pengaduan Gangguan</h5>
                        <div class="header-buttons">
                            <button type="button" class="btn btn-danger btn-sm me-2" data-bs-dismiss="modal">
                                📱 Tutup
                            </button>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                    </div>
                    <div class="modal-body p-0">
                        <div id="loadingIndicator" class="text-center p-5" style="display: none;">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                            <p class="mt-3">Memuat form pengaduan...</p>
                        </div>
                        <iframe src="" id="pengaduanFrame" style="width:100%; height:100%; border:none; display:none;"></iframe>
                        
                        <!-- Floating Close Button -->
                        <button type="button" id="floatingCloseBtn" class="btn btn-danger position-absolute" 
                                data-bs-dismiss="modal" style="display: none; bottom: 20px; right: 20px; z-index: 10; border-radius: 50%; width: 60px; height: 60px; box-shadow: 0 4px 15px rgba(220, 53, 69, 0.4);">
                            <i class="fas fa-times" style="font-size: 20px;"></i>
                            ✕
                        </button>
                    </div>
                </div>
            </div>
        </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Ensure modal and all elements are hidden on initial load
        var pengaduanModal = document.getElementById('pengaduanModal');
        var pengaduanFrame = document.getElementById('pengaduanFrame');
        var loadingIndicator = document.getElementById('loadingIndicator');
        var floatingCloseBtn = document.getElementById('floatingCloseBtn');
        
        // Force hide modal and all components on page load
        pengaduanModal.style.display = 'none';
        pengaduanModal.classList.remove('show');
        pengaduanModal.setAttribute('aria-hidden', 'true');
        
        // Hide iframe and loading elements
        pengaduanFrame.style.display = 'none';
        pengaduanFrame.src = '';
        loadingIndicator.style.display = 'none';
        floatingCloseBtn.style.display = 'none';
        
        // Remove any existing backdrop
        const existingBackdrop = document.querySelector('.modal-backdrop');
        if (existingBackdrop) {
            existingBackdrop.remove();
        }
        
        // Ensure body scroll is enabled
        document.body.style.overflow = 'auto';
        document.body.classList.remove('modal-open');
        
        // Clear any notifications on page load
        document.querySelectorAll('.alert.position-fixed').forEach(alert => alert.remove());
        pengaduanModal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            var url = button.getAttribute('data-url');
            var loadingIndicator = document.getElementById('loadingIndicator');
            
            // Keep navbar visible when modal opens (DO NOT hide it)
            var navbar = document.querySelector('.navbar');
            navbar.style.display = 'flex';
            navbar.style.zIndex = '1055'; // Higher z-index than modal
            navbar.classList.add('hide-navbar'); // Safety class to prevent hiding
            
            // Show loading indicator
            loadingIndicator.classList.add('show');
            pengaduanFrame.style.display = 'none';
            
            // Set iframe source
            pengaduanFrame.src = url;
            
            // Hide loading when iframe loads
            pengaduanFrame.onload = function() {
                setTimeout(() => {
                    loadingIndicator.classList.remove('show');
                    pengaduanFrame.style.display = 'block';
                    
                    // Show floating close button
                    document.getElementById('floatingCloseBtn').classList.add('show');
                    
                    // Notify iframe that modal is opened
                    try {
                        pengaduanFrame.contentWindow.postMessage('modal_opened', '*');
                    } catch(e) {
                        console.log('Could not send message to iframe:', e);
                    }
                }, 500); // Small delay for smooth transition
            };
        });
        
        pengaduanModal.addEventListener('hidden.bs.modal', function () {
            // Ensure navbar stays visible when modal closes
            var navbar = document.querySelector('.navbar');
            navbar.style.display = 'flex';
            navbar.style.zIndex = '1050';
            navbar.classList.add('hide-navbar'); // Safety class
            
            // Clear iframe and loading
            pengaduanFrame.src = "";
            pengaduanFrame.style.display = 'none';
            document.getElementById('loadingIndicator').classList.remove('show');
            document.getElementById('floatingCloseBtn').classList.remove('show');
        });
        // Listen for submit or back in iframe (pengaduan)
        window.addEventListener('message', function(e) {
            console.log('Portal received message:', e.data, 'from origin:', e.origin);
            
            // Handle object messages from new pengaduan.php
            if (typeof e.data === 'object') {
                if (e.data.type === 'pengaduan_success') {
                    console.log('Pengaduan success detected, closing modal...');
                    // Close modal immediately on success
                    pengaduanModal.style.display = 'none';
                    pengaduanModal.classList.remove('show');
                    pengaduanModal.setAttribute('aria-hidden', 'true');
                    
                    // Clear iframe
                    pengaduanFrame.src = '';
                    pengaduanFrame.style.display = 'none';
                    
                    // Hide loading and floating button
                    document.getElementById('loadingIndicator').classList.remove('show');
                    document.getElementById('floatingCloseBtn').classList.remove('show');
                    
                    // Remove backdrop and reset body
                    document.querySelectorAll('.modal-backdrop').forEach(backdrop => backdrop.remove());
                    document.body.style.overflow = 'auto';
                    document.body.classList.remove('modal-open');
                    
                    // Ensure navbar stays visible
                    var navbar = document.querySelector('.navbar');
                    navbar.style.display = 'flex';
                    navbar.style.zIndex = '1050';
                    navbar.classList.add('hide-navbar');
                    
                    // Create enhanced success notification
                    const notification = document.createElement('div');
                    notification.className = 'alert alert-success position-fixed';
                    notification.style.cssText = `
                        top: 80px; 
                        left: 50%; 
                        transform: translateX(-50%); 
                        z-index: 9999; 
                        min-width: 320px;
                        max-width: 90%;
                        text-align: center;
                        border-radius: 15px;
                        box-shadow: 0 8px 30px rgba(0,0,0,0.3);
                        background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
                        border: 2px solid #28a745;
                        animation: slideInDown 0.5s ease-out;
                    `;
                    notification.innerHTML = `
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 5px;">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <div style="font-size: 24px;">🎉</div>
                                <div>
                                    <strong style="color: #155724;">Pengaduan Berhasil Dikirim!</strong><br>
                                    <small style="color: #155724; opacity: 0.8;">Teknisi akan segera menangani masalah Anda</small>
                                </div>
                            </div>
                            <button onclick="this.parentElement.parentElement.remove()" 
                                    style="background: none; border: none; color: #155724; font-size: 20px; cursor: pointer; margin-left: 15px; padding: 5px;">&times;</button>
                        </div>
                    `;
                    
                    // Add slide animation CSS
                    if (!document.querySelector('#slideAnimationCSS')) {
                        const style = document.createElement('style');
                        style.id = 'slideAnimationCSS';
                        style.textContent = `
                            @keyframes slideInDown {
                                from {
                                    transform: translate(-50%, -100%);
                                    opacity: 0;
                                }
                                to {
                                    transform: translate(-50%, 0);
                                    opacity: 1;
                                }
                            }
                        `;
                        document.head.appendChild(style);
                    }
                    
                    document.body.appendChild(notification);
                    
                    // Auto remove notification with fade out
                    setTimeout(() => {
                        if (document.body.contains(notification)) {
                            notification.style.transition = 'opacity 0.5s ease-out';
                            notification.style.opacity = '0';
                            setTimeout(() => {
                                if (document.body.contains(notification)) {
                                    notification.remove();
                                }
                            }, 500);
                        }
                    }, 4000);
                    
                    return; // Exit early for object messages
                }
                
                if (e.data.type === 'close_modal') {
                    // Handle explicit close modal request
                    pengaduanModal.style.display = 'none';
                    pengaduanModal.classList.remove('show');
                    pengaduanModal.setAttribute('aria-hidden', 'true');
                    
                    // Clear iframe
                    pengaduanFrame.src = '';
                    pengaduanFrame.style.display = 'none';
                    
                    // Hide loading and floating button
                    document.getElementById('loadingIndicator').classList.remove('show');
                    document.getElementById('floatingCloseBtn').classList.remove('show');
                    
                    // Remove backdrop and reset body
                    document.querySelectorAll('.modal-backdrop').forEach(backdrop => backdrop.remove());
                    document.body.style.overflow = 'auto';
                    document.body.classList.remove('modal-open');
                    
                    // Ensure navbar stays visible
                    var navbar = document.querySelector('.navbar');
                    navbar.style.display = 'flex';
                    navbar.style.zIndex = '1050';
                    navbar.classList.add('hide-navbar');
                    
                    return;
                }
            }
            
            // Handle legacy string messages
            if (e.data === 'closePengaduanModal' || e.data === 'backPengaduanModal' || e.data === 'pengaduan_success') {
                console.log('Legacy message detected:', e.data);
                
                // Close modal for any of these messages
                const shouldShowSuccess = (e.data === 'closePengaduanModal' || e.data === 'pengaduan_success');
                // Force close modal immediately
                pengaduanModal.style.display = 'none';
                pengaduanModal.classList.remove('show');
                pengaduanModal.setAttribute('aria-hidden', 'true');
                
                // Clear iframe
                pengaduanFrame.src = '';
                pengaduanFrame.style.display = 'none';
                
                // Hide loading and floating button
                document.getElementById('loadingIndicator').classList.remove('show');
                document.getElementById('floatingCloseBtn').classList.remove('show');
                
                // Remove backdrop and reset body
                document.querySelectorAll('.modal-backdrop').forEach(backdrop => backdrop.remove());
                document.body.style.overflow = 'auto';
                document.body.classList.remove('modal-open');
                
                // Show success notification
                if (shouldShowSuccess) {
                    // Ensure navbar stays visible
                    var navbar = document.querySelector('.navbar');
                    navbar.style.display = 'flex';
                    navbar.style.zIndex = '1050';
                    navbar.classList.add('hide-navbar');
                    
                    // Create enhanced success notification
                    const notification = document.createElement('div');
                    notification.className = 'alert alert-success position-fixed';
                    notification.style.cssText = `
                        top: 80px; 
                        left: 50%; 
                        transform: translateX(-50%); 
                        z-index: 9999; 
                        min-width: 320px;
                        max-width: 90%;
                        text-align: center;
                        border-radius: 15px;
                        box-shadow: 0 8px 30px rgba(0,0,0,0.3);
                        background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
                        border: 2px solid #28a745;
                        animation: slideInDown 0.5s ease-out;
                    `;
                    notification.innerHTML = `
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 5px;">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <div style="font-size: 24px;">🎉</div>
                                <div>
                                    <strong style="color: #155724;">Pengaduan Berhasil Dikirim!</strong><br>
                                    <small style="color: #155724; opacity: 0.8;">Teknisi akan segera menangani masalah Anda</small>
                                </div>
                            </div>
                            <button onclick="this.parentElement.parentElement.remove()" 
                                    style="background: none; border: none; color: #155724; font-size: 20px; cursor: pointer; margin-left: 15px; padding: 5px;">&times;</button>
                        </div>
                    `;
                    
                    document.body.appendChild(notification);
                    
                    // Auto remove notification with fade out
                    setTimeout(() => {
                        if (document.body.contains(notification)) {
                            notification.style.opacity = '0';
                            notification.style.transform = 'translateX(-50%) translateY(-20px)';
                            notification.style.transition = 'all 0.5s ease';
                            setTimeout(() => {
                                if (document.body.contains(notification)) {
                                    notification.remove();
                                }
                            }, 500);
                        }
                    }, 2500); // Reduced from 4000ms to 2500ms
                }
            }
        });

        // Add keyboard shortcut (ESC) to close fullscreen modal
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && pengaduanModal.classList.contains('show')) {
                var modal = bootstrap.Modal.getInstance(pengaduanModal) || new bootstrap.Modal(pengaduanModal);
                modal.hide();
            }
        });

        // Prevent body scroll when fullscreen modal is open
        pengaduanModal.addEventListener('shown.bs.modal', function () {
            document.body.style.overflow = 'hidden';
        });

        pengaduanModal.addEventListener('hidden.bs.modal', function () {
            document.body.style.overflow = 'auto';
        });

        // Add click effect to floating button
        document.getElementById('floatingCloseBtn').addEventListener('click', function() {
            this.style.transform = 'scale(0.9)';
            setTimeout(() => {
                this.style.transform = 'scale(1)';
            }, 150);
        });

        // Add confirmation for floating close button
        document.getElementById('floatingCloseBtn').addEventListener('click', function(e) {
            if (pengaduanFrame.style.display === 'block') {
                if (!confirm('Yakin ingin keluar dari form pengaduan? Data yang belum disimpan akan hilang.')) {
                    e.preventDefault();
                    e.stopPropagation();
                }
            }
        });

        // Function to completely reset modal state
        function resetModalState() {
            pengaduanModal.style.display = 'none';
            pengaduanModal.classList.remove('show');
            pengaduanModal.setAttribute('aria-hidden', 'true');
            pengaduanFrame.style.display = 'none';
            pengaduanFrame.src = '';
            loadingIndicator.classList.remove('show');
            floatingCloseBtn.classList.remove('show');
            
            // Remove backdrop if exists
            const backdrop = document.querySelector('.modal-backdrop');
            if (backdrop) backdrop.remove();
            
            // Reset body state
            document.body.style.overflow = 'auto';
            document.body.classList.remove('modal-open');
            
            // Ensure navbar is always visible and properly positioned
            var navbar = document.querySelector('.navbar');
            if (navbar) {
                navbar.style.display = 'flex';
                navbar.style.position = 'fixed';
                navbar.style.bottom = '0';
                navbar.style.zIndex = '1050';
                navbar.classList.add('hide-navbar'); // Safety class
            }
            
            // Clear any existing notifications
            document.querySelectorAll('.alert.position-fixed').forEach(alert => alert.remove());
        }

        // Call reset on page load and visibility change
        resetModalState();
        
        // Reset modal when page becomes visible (handles browser back/forward)
        document.addEventListener('visibilitychange', function() {
            if (!document.hidden) {
                resetModalState();
            }
        });

        // Reset modal on page focus (handles tab switching)
        window.addEventListener('focus', function() {
            resetModalState();
        });
    });

    // Additional reset on window load (ensures everything is clean)
    window.addEventListener('load', function() {
        const modal = document.getElementById('pengaduanModal');
        if (modal) {
            modal.style.display = 'none';
            modal.classList.remove('show');
            document.body.classList.remove('modal-open');
            
            // Remove any leftover backdrops
            document.querySelectorAll('.modal-backdrop').forEach(backdrop => backdrop.remove());
            
            // Clear notifications
            document.querySelectorAll('.alert.position-fixed').forEach(alert => alert.remove());
        }
        
        // Final safety check - ensure navbar is always visible
        const navbar = document.querySelector('.navbar');
        if (navbar) {
            navbar.style.display = 'flex';
            navbar.style.position = 'fixed';
            navbar.style.bottom = '0';
            navbar.style.zIndex = '1050';
            navbar.classList.add('hide-navbar');
        }
    });

    // Periodic check to ensure navbar stays visible (every 5 seconds)
    setInterval(function() {
        const navbar = document.querySelector('.navbar');
        if (navbar && (navbar.style.display === 'none' || !navbar.style.display)) {
            navbar.style.display = 'flex';
            navbar.style.position = 'fixed';
            navbar.style.bottom = '0';
            navbar.style.zIndex = '1050';
            navbar.classList.add('hide-navbar');
        }
    }, 5000);
</script>
      
             <!-- Payment Section -->
        <!-- <div class="payment-section">
            <div class="payment-title">Bneftit</div>
            <div class="payment-option">
                <div class="payment-icon">P</div>
                <div>Prosedur Pembayaran Tagihan</div>
            </div>
            <div class="payment-option">
                <div class="payment-icon">T</div>
                <div>Tutorial Pembayaran Tagihan</div>
            </div>
        </div> -->
        
        <!-- Ads Section - Compact Cards -->
        <div class="ads-section">
          
            
            <div class="ads-container">
                <!-- Ad Card 1 - SBL Network -->
                <!-- <div class="ad-card">
                    <div class="ad-image">
                        <div class="ad-badge">NETWORK</div>
                        SBL Network
                    </div>
                    <div class="ad-content">
                        <div class="ad-title">SBL Network</div>
                        <div class="ad-desc">Solusi jaringan terpercaya untuk kebutuhan bisnis dan personal Anda</div>
                        <a href="https://sblnetwork.co.id/" target="_blank" class="ad-button">Kunjungi Website</a>
                    </div>
                </div> -->
                
                <!-- Ad Card 2 - Harmoni Tour -->
                <!-- <div class="ad-card">
                    <div class="ad-image">
                        <div class="ad-badge">TRAVEL</div>
                        Harmoni Tour
                    </div>
                    <div class="ad-content">
                        <div class="ad-title">Harmoni Tour</div>
                        <div class="ad-desc">Paket wisata terbaik untuk liburan impian Anda bersama keluarga</div>
                        <a href="https://harmonitour.com/" target="_blank" class="ad-button">Kunjungi Website</a>
                    </div>
                </div>
                 -->
                <!-- Ad Card 3 - Aktual -->
                <!-- <div class="ad-card">
                    <div class="ad-image">
                        <div class="ad-badge">NEWS</div>
                        Aktual.com
                    </div>
                    <div class="ad-content">
                        <div class="ad-title">Aktual.com</div>
                        <div class="ad-desc">Portal berita terkini dan terpercaya untuk informasi aktual setiap hari</div>
                        <a href="https://aktual.com/" target="_blank" class="ad-button">Kunjungi Website</a>
                    </div>
                </div> -->
            </div>
        </div>

        <div class="complaint-bottom-section">
            <div class="complaint-bottom-title">Tiket Laporan Pengaduan</div>
            <?php if ($complaintFlash !== ''): ?>
                <div class="complaint-flash <?= $complaintFlashType === 'error' ? 'complaint-flash-error' : 'complaint-flash-success'; ?>">
                    <?= htmlspecialchars($complaintFlash); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($berandaComplaints)): ?>
                <?php foreach ($berandaComplaints as $lapItem): ?>
                    <?php
                    $statusLap = strtoupper(trim((string)($lapItem['status'] ?? '-')));
                    $isBaru = ($statusLap === 'BARU');
                    ?>
                    <div class="complaint-bottom-item">
                        <div class="complaint-bottom-row">
                            <span class="complaint-status <?= $isBaru ? 'complaint-status-baru' : ''; ?>"><?= htmlspecialchars($statusLap); ?></span>
                            <span class="complaint-time"><?= htmlspecialchars((string)($lapItem['tgl'] ?? '-')); ?></span>
                        </div>
                        <div class="complaint-desc"><?= htmlspecialchars((string)($lapItem['data'] ?? '')); ?></div>
                        <?php if ($isBaru && !empty($lapItem['id'])): ?>
                            <a
                                class="complaint-cancel-btn"
                                href="portal_baru.php?cari=<?= urlencode($idpelRef); ?>&action=cancel_laporan&report_id=<?= (int)$lapItem['id']; ?>"
                                onclick="return confirm('Yakin ingin membatalkan tiket pengaduan ini?');"
                            >Batal</a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="complaint-empty">Belum ada tiket pengaduan.</div>
            <?php endif; ?>
        </div>
    </div>
    
<!-- Navigation Bar -->
    <div class="navbar">
       <a href="portal_baru.php?cari=<?= $idpel; ?>" class="nav-item active">
            <div class="nav-icon"><i class="bi bi-house-door-fill"></i></div>
            <div>Beranda</div>
        </a>
        <a href="portal_bayar.php?cari=<?= $idpel; ?>" class="nav-item">
            <div class="nav-icon"><i class="bi bi-credit-card"></i></div>
            <div>Bayar</div>
        </a>
        <a href="portal_chat.php?cari=<?= $idpel; ?>" class="nav-item ">
            <div class="nav-icon"><i class="bi bi-chat-dots"></i></div>
            <div>Chat</div>
        </a>
        <a href="portal_mywifi.php?cari=<?= $idpel; ?>" class="nav-item">
            <div class="nav-icon"><i class="bi bi-wifi"></i></div>
            <div>WiFi Saya</div>
        </a>
         <a href="portal_riwayat.php?cari=<?= $idpel; ?>" class="nav-item">
      <div class="nav-icon"><i class="bi bi-clock-history"></i></div>
      <div>Riwayat</div>
    </a>
        <a href="portal_profile.php?cari=<?= $idpel; ?>" class="nav-item">
            <div class="nav-icon"><i class="bi bi-person-circle"></i></div>
            <div>Profile</div>
        </a>
    </div>
</body>
</html>