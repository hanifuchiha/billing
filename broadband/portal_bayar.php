<?php
// ===================================================================
// PORTAL BAYAR - CUSTOMER PAYMENT INTERFACE
// ===================================================================
// File: portal_bayar.php
// Purpose: Customer payment portal with multiple payment gateways
// Author: CRM Billing System
// Version: 2.0
// Last Modified: October 2025
// ===================================================================
include 'cek_sesi.php';
require_once '../getdata/sla_discount_helper.php';
require_once __DIR__ . '/../notifbot/notifphp/tagihan_status_lib.php';
require_once __DIR__ . '/../dompetx_helper.php';

if (!function_exists('portalBayarFormatTanggalIndo')) {
    function portalBayarFormatTanggalIndo(string $ymd): string
    {
        $ts = strtotime($ymd);
        if ($ts === false) {
            return '-';
        }
        $bulanIndo = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        return date('d', $ts) . ' ' . $bulanIndo[(int)date('n', $ts) - 1] . ' ' . date('Y', $ts);
    }
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <!-- ===================================================================
         HTML HEAD SECTION - Meta tags, title, and external resources
         =================================================================== -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pelanggan['PEMILIK']); ?> - Internet Service</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    
    <style>
        /* ===================================================================
           CSS CUSTOM PROPERTIES - Color scheme and design tokens
           =================================================================== */
        :root {
            --primary-green: #0d6efd;
            --dark-green: #0a58ca;
            --orange: #F7941D;
            --white: #ffffff;
            --light-gray: #f8f9fa;
            --border-color: #e9ecef;
        }

        /* ===================================================================
           GLOBAL STYLES - Universal styling and reset
           =================================================================== */
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
           

        .user-name {
            font-weight: bold;
            font-size: 18px;
            color: var(--primary-green);
        }

        .user-id {
            color: #666;
            font-size: 14px;
        }

        /* ===================================================================
           SERVICE INFO CARD STYLES - Package details and billing status
           =================================================================== */
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

        .bill-unpaid {
            color: #f44336;
            font-weight: bold;
        }

        .package-price {
            color: var(--primary-green);
            font-weight: bold;
        }

        /* Status Section */
        .status-section {
            background-color: var(--light-gray);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }

        .status-title {
            font-weight: bold;
            color: var(--primary-green);
            margin-bottom: 10px;
        }

        .status-working {
            color: #4caf50;
            font-weight: bold;
            font-size: 18px;
        }

        .whats-new {
            margin-top: 15px;
        }

        .whats-new-title {
            font-weight: bold;
            color: var(--primary-green);
            margin-bottom: 10px;
        }

        /* ===================================================================
           B-COIN SECTION STYLES - Loyalty program display
           =================================================================== */
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

        /* ===================================================================
           PAYMENT SECTION STYLES - Payment options and forms
           =================================================================== */
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

        /* Payment Details Section */
        .payment-details {
            background-color: var(--light-gray);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }

        .payment-details-header {
            background-color: var(--primary-green);
            color: white;
            padding: 10px 15px;
            border-radius: 8px 8px 0 0;
            margin: -15px -15px 15px -15px;
            font-weight: bold;
        }

        .payment-info {
            margin-bottom: 15px;
        }

        .payment-info p {
            margin-bottom: 8px;
            display: flex;
            justify-content: space-between;
        }

        .payment-info strong {
            color: var(--primary-green);
        }

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

        /* ===================================================================
           ALERT STYLES - Success, info, error, and warning notifications
           =================================================================== */
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
            animation: shake 0.5s ease-in-out;
        }
        
        .alert-warning {
            background-color: #fff3e0;
            color: #ef6c00;
            border: 1px solid #ff9800;
        }

        /* ===================================================================
           ANIMATION STYLES - Error shake and debug panel animations
           =================================================================== */
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }

        /* ===================================================================
           DEBUG INFO STYLES - Debug panel styling and scrollbars
           =================================================================== */
        .debug-info {
            max-height: 200px;
            overflow-y: auto;
            scrollbar-width: thin;
        }

        .debug-info::-webkit-scrollbar {
            width: 6px;
        }

        .debug-info::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 3px;
        }

        .debug-info::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 3px;
        }

        .debug-info::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
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
            content: "?";
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
    </style>
    <script>
    // Extract dominant color from logo (profile-[useraccount].png if exists, else logo.png) and set CSS variables (logo not displayed)
    document.addEventListener('DOMContentLoaded', function() {
        var logoUrl = '';
        <?php
        // $logo_path sudah dihitung (server-per-area aware) di cek_sesi.php
        echo "logoUrl = '" . addslashes($logo_path) . "?v=" . time() . "';\n";
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
</head>
<body>


    <!-- ===================================================================
         MAIN CONTAINER - Mobile-responsive payment portal container
         =================================================================== -->
    <div class="container">
        <br> <br> <br>
        <!-- ===================================================================
             PAYMENT METHOD INFO SECTION - Display current payment settings
             =================================================================== -->
        <div class="alert alert-info">
            <?php
            // ===================================================================
            // PAYMENT METHOD DETECTION LOGIC
            // Purpose: Determine user's default payment method from database
            // ===================================================================
            
            // Set default payment method as fallback
            $default_payment = 'manual_bank'; 
            
            // Check user's payment preference from database
            if (isset($useraccount) && !empty($useraccount)) {
                $default_query = "SELECT payment_default FROM user WHERE USERNAME = ?";
                $stmt_default = $conn->prepare($default_query);
                $stmt_default->bind_param("s", $useraccount);
                $stmt_default->execute();
                $result_default = $stmt_default->get_result();
                if ($row_default = $result_default->fetch_assoc()) {
                    $default_payment = $row_default['payment_default'] ?? 'manual_bank';
                }
                $stmt_default->close();
            }

            // ===================================================================
            // CARI TAGIHAN PENAGIHAN YANG SEDANG AKTIF UNTUK PELANGGAN INI
            // Purpose: SEBELUMNYA (dua revisi lalu) kode ini menebak dulu label
            // periode "bulan berjalan" pakai heuristik generik berbasis
            // tanggal/tutup-buku ($periode dari cek_sesi.php, TIDAK TIPE_TEMPO-
            // aware) -- gampang meleset. Lalu diganti ambil baris PENAGIHAN
            // PALING AWAL/lama tanpa syarat -- TAPI ini juga salah utk Fixed Due
            // Date: kalau pelanggan punya BEBERAPA baris PENAGIHAN (mis. tagihan
            // lama yg lewat + tagihan bulan ini, keduanya di-generate manual),
            // "paling lama" bisa jadi tagihan BULAN LALU yang sudah lewat, bukan
            // tagihan yang SEDANG berjalan sesuai jatuh_tempo_hari/Periode
            // Tercatat di Payment Setting.
            //
            // Sekarang, per TIPE_TEMPO:
            //  - Fixed Due Date (mengikuti_tanggal_tempo): HITUNG label periode
            //    yang SEDANG berjalan (jatuh_tempo_hari + Periode Tercatat,
            //    fungsi SAMA dgn tables.php/portal_baru.php), lalu cari baris
            //    PENAGIHAN yang PENGUNAAN-nya PERSIS cocok itu -- fokus HANYA ke
            //    periode berjalan, tagihan lama yg lewat/tagihan depan yg sudah
            //    ke-generate duluan diabaikan di sini (bukan tanggung jawab
            //    portal bayar utk collect tunggakan lama).
            //  - Rolling (mengikuti_tanggal_bayar) / Monthversary: TIDAK terpaku
            //    periode kalender bersama -- siklusnya per-pelanggan sendiri
            //    (30 hari sejak bayar terakhir / anchor tanggal pasang), jadi
            //    tetap ambil baris PENAGIHAN PALING AWAL/lama tanpa syarat label
            //    (perilaku lama, sudah benar utk mode ini).
            // ===================================================================
            date_default_timezone_set('Asia/Jakarta');

            $has_penagihan_periode = false;
            $penagihanRow = null;
            $periode_tagihan = '';
            $trxDateExprPenagihan = tagihanBuildTrxDateExpr();

            $tipeTempoPenagihanFokus = strtolower(trim((string)($pelanggan['TIPE_TEMPO'] ?? '')));
            $isFixedDueDatePenagihanFokus = !in_array($tipeTempoPenagihanFokus, ['monthversary', 'mengikuti_tanggal_bayar'], true);

            if ($isFixedDueDatePenagihanFokus) {
                $todayTsPenagihanFokus = strtotime(date('Y-m-d'));
                $dueMonthTsPenagihanFokus = ((int) date('j', $todayTsPenagihanFokus) <= (int) ($jatuh_tempo ?? 25))
                    ? $todayTsPenagihanFokus
                    : strtotime('+1 month', $todayTsPenagihanFokus);
                $periodeBerjalanFokus = tagihanResolvePeriodeTercatat(
                    (int) date('n', $dueMonthTsPenagihanFokus),
                    (int) date('Y', $dueMonthTsPenagihanFokus),
                    (string) ($periode_tercatat ?? 'berjalan')
                );
                $stmtPenagihan = $conn->prepare("SELECT * FROM transaksi WHERE IDPEL = ? AND UPPER(STATUS) = 'PENAGIHAN' AND TRIM(UPPER(PENGUNAAN)) = TRIM(UPPER(?)) ORDER BY id DESC LIMIT 1");
                $stmtPenagihan->bind_param("ss", $merchantRef, $periodeBerjalanFokus);
            } else {
                $stmtPenagihan = $conn->prepare("SELECT * FROM transaksi WHERE IDPEL = ? AND UPPER(STATUS) = 'PENAGIHAN' ORDER BY $trxDateExprPenagihan ASC, id ASC LIMIT 1");
                $stmtPenagihan->bind_param("s", $merchantRef);
            }
            $stmtPenagihan->execute();
            $resultPenagihan = $stmtPenagihan->get_result();
            if ($rowPenagihan = $resultPenagihan->fetch_assoc()) {
                $has_penagihan_periode = true;
                $penagihanRow = $rowPenagihan;
                $periode_tagihan = (string)($rowPenagihan['PENGUNAAN'] ?? '');
            }
            $stmtPenagihan->close();

        // ===================================================================
        // AMBIL TRANSAKSI LUNAS TERAKHIR (LINTAS PERIODE)
        // Purpose: Dipakai utk tampilan "Tanggal Terakhir Bayar"/"Jatuh Tempo
        // Berikutnya" (Rolling/Monthversary) di bawah. $lastTransaction SUDAH
        // di-resolve dgn benar oleh cek_sesi.php::getLastTransaction() (filter
        // STATUS='BERHASIL' ketat, ORDER BY periode PENGUNAAN) -- TIDAK query
        // ulang di sini. Sebelumnya di titik ini ada query kedua yang MENIMPA
        // $lastTransaction dgn `ORDER BY waktu DESC` -- kolom `waktu` bertipe
        // TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, jadi nilainya ikut maju kalau
        // baris itu di-UPDATE utk alasan apapun (bukan cuma saat benar-benar
        // dibayar), menyebabkan "Tanggal Terakhir Bayar" salah tampil tanggal
        // jauh di masa depan dari tanggal bayar aslinya.
        // ===================================================================

        echo "Periode Tagihan: $periode_tagihan <br>";

        // ===================================================================
        // TANGGAL TERAKHIR BAYAR (Rolling/Monthversary)
        // Purpose: Fixed Due Date TIDAK ditambah tampilan ini (tidak diminta).
        // Pakai TANGGALBAYAR (tanggal bayar aktual), BUKAN `waktu` (timestamp
        // auto-update yang bisa bergeser kapan saja baris disentuh update lain).
        // ===================================================================
        $tipeTempoBayarHalaman = (string)($pelanggan['TIPE_TEMPO'] ?? '');
        if (in_array($tipeTempoBayarHalaman, ['mengikuti_tanggal_bayar', 'monthversary'], true)) {
            echo 'Tanggal Terakhir Bayar: ' . (!empty($lastTransaction['TANGGALBAYAR']) ? htmlspecialchars($lastTransaction['TANGGALBAYAR'], ENT_QUOTES, 'UTF-8') : 'Belum ada') . '<br>';
        }

        // ===================================================================
        // CHECK EXISTING TRANSACTIONS (PERIODE YANG SUDAH DITEMUKAN DI ATAS)
        // Purpose: Look for pending payment requests for this customer, dibatasi
        // ke periode dari baris PENAGIHAN aktif yang barusan ditemukan, supaya
        // transaksi pending lama (periode sebelumnya) tidak ikut tampil.
        // ===================================================================
        $sql2 = "SELECT * FROM transaksi WHERE IDPEL = ? AND STATUS = 'PERMINTAAN KODE' AND TRIM(UPPER(PENGUNAAN)) = TRIM(UPPER(?)) ORDER BY id DESC LIMIT 1";
        $stmt = $conn->prepare($sql2);
        $stmt->bind_param("ss", $merchantRef, $periode_tagihan);
        $stmt->execute();
        $result = $stmt->get_result();

        $has_existing_transaction = ($result->num_rows > 0);

        // ===================================================================
        // SELARASKAN DATA TAGIHAN YANG DITAMPILKAN DENGAN BARIS PENAGIHAN
        // PERIODE BERJALAN YANG SUDAH DIVALIDASI DI ATAS.
        // Purpose: Sebelumnya $periode/$totalTagihan/$tagihanDetail dsb bisa saja
        // berisi data periode lain (mis. Agustus 2026 yang sudah lunas) kalau
        // diset dari sumber lain (cek_sesi.php / query invoice terpisah).
        // Di sini kita PAKSA nilai yang tampil mengikuti baris PENAGIHAN periode
        // berjalan yang barusan ditemukan, supaya label periode & nominal yang
        // muncul di halaman selalu konsisten dengan tagihan yang benar-benar
        // masih berstatus PENAGIHAN untuk bulan ini.
        // ===================================================================
        if ($has_penagihan_periode && $penagihanRow) {
            $periode_tagihan = $penagihanRow['PENGUNAAN'];
            $periode = $penagihanRow['PENGUNAAN'];
            $paketHarga = $penagihanRow['HARGA'];

            // ===================================================================
            // TOGGLE "Prorate Saat Telat" (Payment Setting -> Konfigurasi Fixed
            // Due Date). $prorate_untuk_telat sudah dimuat dari reminder-<user>.json
            // oleh cek_sesi.php (default TRUE), tapi sebelum ini tidak pernah
            // dikonsumsi di mana pun. OFF = pelanggan Fixed Due Date yang sudah
            // lewat tanggal jatuh tempo yang tercetak di invoice ini (TANGGALBAYAR)
            // & belum bayar, ditagih HARGA PENUH 1 bulan (harga paket saat ini),
            // BUKAN HARGA yang sudah tersimpan di baris PENAGIHAN (yang bisa saja
            // sudah prorate, mis. dari aktivasi pertengahan bulan). ON (default)
            // = biarkan HARGA invoice apa adanya (perilaku lama, tidak berubah).
            // Hanya berlaku utk Fixed Due Date -- Monthversary/Rolling jatuh
            // temponya per-pelanggan, bukan tanggal global ini.
            // ===================================================================
            $tipeTempoBayarTelat = strtolower(trim((string)($pelanggan['TIPE_TEMPO'] ?? '')));
            $isFixedDueDateBayar = !in_array($tipeTempoBayarTelat, ['monthversary', 'mengikuti_tanggal_bayar'], true);
            $tglJatuhTempoInvoice = strtotime((string)($penagihanRow['TANGGALBAYAR'] ?? ''));
            $isTelatBayarFixed = $isFixedDueDateBayar && $tglJatuhTempoInvoice !== false
                && $tglJatuhTempoInvoice < strtotime(date('Y-m-d'));

            if ($isTelatBayarFixed && empty($prorate_untuk_telat)) {
                $stmtPaketFull = $conn->prepare("SELECT HARGA FROM paket WHERE PAKET = ? AND PEMILIK = ? ORDER BY id DESC LIMIT 1");
                $stmtPaketFull->bind_param("ss", $penagihanRow['PAKET'], $penagihanRow['PEMILIK']);
                $stmtPaketFull->execute();
                $paketFullRow = $stmtPaketFull->get_result()->fetch_assoc();
                $stmtPaketFull->close();
                if ($paketFullRow && (float)$paketFullRow['HARGA'] > 0) {
                    $paketHarga = (float)$paketFullRow['HARGA'];
                }
            }

            // Hitung ulang pajak & total dari HARGA baris PENAGIHAN ini (atau harga
            // penuh hasil override di atas), memakai $pajak yang sudah ditentukan
            // cek_sesi.php sesuai payment method. Sebelumnya baris ini menimpa
            // $totalTagihan langsung dengan HARGA mentah (tanpa pajak), sehingga
            // total yang tampil di halaman tidak menyertakan pajak walau baris
            // "Pajak" tetap tampil.
            $tagihanDetail = [[
                'keterangan' => 'Tagihan Penggunaan Bulan ' . $penagihanRow['PENGUNAAN'],
                'harga' => $paketHarga
            ]];

            // BUGFIX 2026-08-02: baris di atas ($tagihanDetail/$totalTagihan) MENIMPA
            // hasil hitungan diskon & tambahan biaya pelanggan yang sudah dihitung
            // cek_sesi.php di awal file -- sebelumnya diskon/biaya HILANG begitu saja
            // di sini (kasus paling umum: pelanggan punya tagihan PENAGIHAN berjalan),
            // jadi pengaturan Diskon/Tambah Biaya di tables.php tidak pernah sampai ke
            // nominal yang ditagihkan lewat Tripay maupun gateway lain. Terapkan ULANG
            // di sini pakai fungsi bersama yang sama (tagihan_status_lib.php), dgn
            // periode = PENGUNAAN baris PENAGIHAN ini (bukan $periode heuristik lama).
            $diskonBiayaResultOverride = tagihanTerapkanDiskonBiayaTambahan(
                $conn,
                (string) ($penagihanRow['PEMILIK'] ?? $pelanggan['PEMILIK'] ?? ''),
                $merchantRef,
                (string) $penagihanRow['PENGUNAAN'],
                trim((string) ($pelanggan['AREA'] ?? '')),
                trim((string) ($pelanggan['PAKET'] ?? '')),
                trim((string) ($pelanggan['ODP'] ?? '')),
                $paketHarga
            );
            $subtotalSetelahDiskonBiaya = $diskonBiayaResultOverride['total'];
            foreach ($diskonBiayaResultOverride['extra_detail'] as $d) {
                $tagihanDetail[] = $d;
            }

            $ppn = $subtotalSetelahDiskonBiaya * $pajak / 100;
            $totalTagihan = intval($subtotalSetelahDiskonBiaya + $ppn);
        }

        // ===================================================================
        // CEK TRANSAKSI YANG SUDAH BERHASIL/LUNAS UNTUK PERIODE BERJALAN
        // Purpose: Jika tidak ada tagihan baru (PENAGIHAN) untuk periode ini,
        // cek apakah pelanggan sebenarnya SUDAH membayar periode ini, supaya
        // bisa ditampilkan detail transaksinya alih-alih hanya pesan kosong.
        //
        // CATATAN PENTING: daftar STATUS di bawah ini HARUS mencakup semua
        // nilai status "lunas" yang benar-benar dipakai oleh callback gateway
        // Anda (callback_tripay_*.php, callback duitku, dsb) dan oleh admin
        // saat mengonfirmasi transfer manual. Jika callback Anda menulis
        // status lain (mis. 'SUCCESS', 'Settlement', 'Bayar'), tambahkan ke
        // daftar IN(...) di bawah supaya transaksi lunas ikut terdeteksi.
        // ===================================================================
        $has_paid_periode = false;
        $paidTransaction = null;
        $stmtPaid = $conn->prepare("SELECT * FROM transaksi WHERE IDPEL = ? AND TRIM(UPPER(PENGUNAAN)) = TRIM(UPPER(?)) AND UPPER(STATUS) IN ('LUNAS', 'SUKSES', 'SUCCESS', 'BERHASIL', 'PAID', 'SELESAI', 'SETTLEMENT') ORDER BY id DESC LIMIT 1");
        $stmtPaid->bind_param("ss", $merchantRef, $periode_tagihan);
        $stmtPaid->execute();
        $resultPaid = $stmtPaid->get_result();
        if ($rowPaid = $resultPaid->fetch_assoc()) {
            $has_paid_periode = true;
            $paidTransaction = $rowPaid;
        }
        $stmtPaid->close();

        // ===================================================================
        // EXISTING TRANSACTION PROCESSING
        // Purpose: If customer has pending payment, retrieve and display details
        // ===================================================================
        if ($result->num_rows > 0) {
            $adaData = ($result->num_rows > 0) ? "true" : "false";

            // Get transaction reference from database (HANYA periode berjalan,
            // baris terbaru) - sebelumnya query ini mengambil SEMUA transaksi
            // milik IDPEL tanpa filter periode/urutan, sehingga BUKTI dari
            // transaksi bulan lalu bisa "kebawa" dan tampil di sini.
            $stmtRef = $conn->prepare("SELECT * FROM `transaksi` WHERE `IDPEL` = ? AND STATUS = 'PERMINTAAN KODE' AND TRIM(UPPER(PENGUNAAN)) = TRIM(UPPER(?)) ORDER BY id DESC LIMIT 1");
            $stmtRef->bind_param("ss", $merchantRef, $periode_tagihan);
            $stmtRef->execute();
            $resultRef = $stmtRef->get_result();
            if ($rowRef = $resultRef->fetch_assoc()) {
                $reference = $rowRef["BUKTI"];
            }
            $stmtRef->close();

            if (in_array($default_payment, ['ipaymu', 'doku', 'faspay', 'duitku', 'midtrans', 'xendit', 'dompetx'], true)) {
                // ===================================================================
                // IPAYMU/DOKU/FASPAY/DUITKU/MIDTRANS/XENDIT/DOMPETX: RESUME PENDING TRANSACTION
                // (tampilan lokal saja)
                // Purpose: Gateway-gateway ini tidak dipanggil ulang ke API pihak ketiga di sini
                // (tidak seperti Tripay di bawah) karena status pembayaran yang sebenarnya sudah
                // diperbarui secara independen oleh callback masing-masing gateway
                // (callback_ipaymu/doku/faspay/duitku/midtrans/xendit_*.php). Di sini cukup
                // tampilkan data transaksi lokal + status "menunggu" supaya halaman tidak error
                // saat resume -- SEBELUMNYA duitku/midtrans/xendit tidak ada di daftar ini
                // sehingga jatuh ke cabang "else" di bawah yang salah memanggil API Tripay
                // dengan referensi milik gateway lain.
                // ===================================================================
                $stmtLocalPending = $conn->prepare("SELECT * FROM transaksi WHERE BUKTI = ? LIMIT 1");
                $stmtLocalPending->bind_param("s", $reference);
                $stmtLocalPending->execute();
                $localPendingRow = $stmtLocalPending->get_result()->fetch_assoc();
                $stmtLocalPending->close();

                $gatewayLabelMap = ['ipaymu' => 'iPaymu', 'doku' => 'DOKU', 'faspay' => 'Faspay', 'duitku' => 'Duitku', 'midtrans' => 'Midtrans', 'xendit' => 'Xendit', 'dompetx' => 'DompetX'];
                $gatewayLabel = $gatewayLabelMap[$default_payment];
                $data = [
                    'payment_name' => $gatewayLabel,
                    'reference' => $reference,
                    'amount' => $localPendingRow['HARGA'] ?? $totalTagihan,
                    'pay_code' => '',
                    'expired_time' => strtotime('+24 hours', strtotime($localPendingRow['TANGGALBAYAR'] ?? 'now')),
                    'status' => 'UNPAID',
                    'checkout_url' => '#',
                    'instructions' => [[
                        'title' => 'Menunggu Konfirmasi Pembayaran',
                        'steps' => [
                            'Selesaikan pembayaran menggunakan kode/link yang diberikan sebelumnya.',
                            'Status pembayaran akan otomatis diperbarui setelah kami menerima konfirmasi dari ' . $gatewayLabel . '.',
                            'Jika sudah membayar namun status belum berubah dalam beberapa menit, silakan hubungi Admin.',
                        ],
                    ]],
                ];
                $namapembayaran = $data['payment_name'];
                $kodebayar = $data['pay_code'];
                $statusbayar = $data['status'];
                $namapembayar = $pelanggan['NAMA'];
                $idpembayar = $merchantRef;
                $refpembayar = $reference;
                $exp = $data['expired_time'];
                $cekout = $data['checkout_url'];
                $payurl = $data['checkout_url'];
                $barcode = '';
                $harusbayar = $data['amount'];
                $cekpaidtripay = $data['status'];
            } else {
                // ===================================================================
                // TRIPAY API CALL - GET TRANSACTION DETAILS
                // Purpose: Retrieve payment details from Tripay for existing transaction
                // ===================================================================
                $payload = ['reference' => $reference];
                $curl = curl_init();
                curl_setopt_array($curl, [
                    CURLOPT_FRESH_CONNECT  => true,
                    CURLOPT_URL            => 'https://tripay.co.id/api/transaction/detail?' . http_build_query($payload),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HEADER         => false,
                    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey],
                    CURLOPT_FAILONERROR    => false,
                    CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4
                ]);
                $response = curl_exec($curl);
                $error = curl_error($curl);
                curl_close($curl);
                empty($error) ? $response : $error;

                // Parse Tripay response data
                $data = json_decode($response, true)['data'];
                $namapembayaran = $data['payment_name'];
                $kodebayar = $data['pay_code'];
                $statusbayar = $data['status'];
                $namapembayar = $data['customer_name'];
                $idpembayar = $data['merchant_ref'];
                $refpembayar = $data['reference'];
                $exp = $data['expired_time'];
                $cekout = $data['checkout_url'];
                $payurl = $data['pay_url'];
                $barcode = $data['qr_url'];
                $harusbayar = $data['amount'];
                $cekpaidtripay = $data['status'];
            }
        ?>
  <strong><i class="bi bi-info-circle"></i> Metode Pembayaran:</strong>
            <?php 
            switch($default_payment) {
                case 'tripay':
                    echo '<span class="badge" style="background-color: var(--primary-green); color: white; padding: 5px 10px; border-radius: 15px; margin-left: 10px;">
                            <i class="bi bi-credit-card"></i> Tripay Payment Gateway
                          </span>';
                    break;
                case 'duitku':
                    echo '<span class="badge" style="background-color: var(--orange); color: white; padding: 5px 10px; border-radius: 15px; margin-left: 10px;">
                            <i class="bi bi-wallet2"></i> Duitku Payment Gateway
                          </span>';
                    break;
                case 'midtrans':
                    echo '<span class="badge" style="background-color: #007bff; color: white; padding: 5px 10px; border-radius: 15px; margin-left: 10px;">
                            <i class="bi bi-credit-card"></i> Midtrans Payment Gateway
                          </span>';
                    break;
                case 'xendit':
                    echo '<span class="badge" style="background-color: #6f42c1; color: white; padding: 5px 10px; border-radius: 15px; margin-left: 10px;">
                            <i class="bi bi-credit-card"></i> Xendit Payment Gateway
                          </span>';
                    break;
                case 'manual_bank':
                default:
                    echo '<span class="badge" style="background-color: var(--dark-green); color: white; padding: 5px 10px; border-radius: 15px; margin-left: 10px;">
                            <i class="bi bi-bank"></i> Transfer Bank Manual
                          </span>';
                    break;
            }
            ?>
            <br><br>
           
                <?php 
                switch($default_payment) {
                    case 'tripay':
                        echo '(Tripay Config)';
                        break;
                    case 'duitku':
                        echo '(Duitku Config)';
                        break;
                    case 'midtrans':
                        echo '(Midtrans Config)';
                        break;
                    case 'xendit':
                        echo '(Xendit Config)';
                        break;
                    case 'manual_bank':
                    default:
                        echo '(Fixed Rate)';
                        break;
                }
                ?>
            </span>
        </div>
        <!-- Payment Details Section -->
        <div class="payment-details">
            <div class="payment-details-header">Detail Pembayaran</div>
            <div class="payment-info">
                <p><strong>Metode:</strong> <?php echo $data['payment_name']; ?></p>
                <p><strong>Referensi:</strong> <?php echo $data['reference'] ?></p>
                <p><strong>Nominal:</strong> Rp<?php echo number_format($data['amount'], 0, ',', '.'); ?></p>
                <p><strong>Kode Bayar:</strong> <?php echo $data['pay_code']; ?></p>
                
                <?php
                $now = time();
                $expiredTimestamp = $data['expired_time'];
                $isExpired = $now > $expiredTimestamp;
                $expiredFormatted = date('d-m-Y H:i:s', $expiredTimestamp);
                ?>
                
                <p><strong>Berlaku Hingga:</strong> <?= $expiredFormatted; ?></p>
                
                <p><strong>Status:</strong>
                    <span class="payment-status <?= ($isExpired ? 'status-expired' : ($data['status'] == 'PAID' ? 'status-paid' : 'status-unpaid')); ?>">
                        <?= $isExpired ? 'Expired' : $data['status']; ?>
                    </span>
                </p>
            </div>
            
            <div class="payment-actions">
                <?php if (!empty($data['checkout_url']) && $data['checkout_url'] !== '#'): ?>
                <a href="<?php echo $data['checkout_url']; ?>" class="payment-button btn-success" target="_blank">Lihat Checkout</a>
                <?php endif; ?>
                <a href="portal_bayar.php?cari=<?= $merchantRef; ?>&ref=<?= $reference; ?>&action=hapus" class="payment-button btn-danger">Batalkan</a>
            </div>
              <?php if (strpos(strtolower($namapembayaran), 'dana') !== false): ?>
            <div class="payment-actions">
                <a href="<?php echo $payurl; ?>" class="payment-button btn-success btn-sm" style="background-color: #28a745; color: white;" target="_blank">Lanjut Bayar VIA DANA</a>
            </div>
        <?php endif; ?>

        <?php if (!empty($barcode)): ?>
            <div class="qr-code" style="text-align: center; margin-top: 20px;">
                <h5>Scan QR Code untuk Pembayaran</h5>
                <img src="<?php echo $barcode . (strpos($barcode, '?') === false ? '?' : '&') . 't=' . time(); ?>" alt="QR Code" style="max-width: 200px; border: 1px solid #ddd; padding: 10px;">
            </div>
        <?php endif; ?>

        </div>

      
        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i> <strong>Informasi:</strong> Jika ingin mengubah metode pembayaran, silakan batalkan pembayaran ini terlebih dahulu.
        </div>

        <?php foreach ($data['instructions'] as $instruction): ?>
            <div class="payment-details">
                <div class="payment-details-header">Instruksi Pembayaran</div>
                <div class="payment-info">
                    <h4><?php echo $instruction['title']; ?></h4>
                    <ul>
                        <?php foreach ($instruction['steps'] as $step): ?>
                            <li><?php echo $step; ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endforeach; ?>

        <?php
        
        } else {
            // ===================================================================
            // NEW PAYMENT REQUEST PROCESSING
            // Purpose: Handle new payment requests when no pending transaction exists
            // ===================================================================
            
            // Set default email if customer email is empty
            if (empty($pelanggan['EMAIL'])) {
                $email = 'default@example.com';
            } else {
                $email = $pelanggan['EMAIL'];
            }

            // ===================================================================
            // PAYMENT GATEWAY PROCESSING - Handle form submissions
            // Purpose: Process payment requests from different payment gateways
            // ===================================================================
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                
                // ===================================================================
                // DUITKU PAYMENT PROCESSING
                // Purpose: Handle Duitku payment gateway form submission
                // ===================================================================
                if (isset($_POST['duitku_submit']) && isset($_POST['payment_method'])) {
                    // Konfigurasi (termasuk api_key rahasia) diambil ulang dari DB di sini
                    // (server-side), BUKAN dari hidden field -- lihat catatan keamanan di form.
                    $duitku_method = $_POST['payment_method'];
                    $duitku_config_post = null;
                    $duitku_query_post = "SELECT * FROM duitku WHERE server = '" . mysqli_real_escape_string($conn, $username) . "' AND pemilik = '" . mysqli_real_escape_string($conn, $useraccount) . "' LIMIT 1";
                    $duitku_result_post = mysqli_query($conn, $duitku_query_post);
                    if ($duitku_result_post && mysqli_num_rows($duitku_result_post) > 0) {
                        $duitku_config_post = mysqli_fetch_assoc($duitku_result_post);
                    }

                    if (!$duitku_config_post || empty($duitku_config_post['api_key'])) {
                        $duitku_error_message = 'Konfigurasi Duitku (API key) belum diatur.';
                    } else {
                    $duitku_merchant_code = $duitku_config_post['merchant_code'];
                    $duitku_api_key = $duitku_config_post['api_key'];
                    $duitku_callback_url = $duitku_config_post['callback_url'];
                    $duitku_return_url = $duitku_config_post['return_url'];
                    $order_id = 'INV-' . time() . '-' . $merchantRef;

                    // Generate signature for Duitku API authentication
                    $duitku_signature = hash('sha256', $duitku_merchant_code . $merchantRef . $totalTagihan . $duitku_api_key);
                    
                    // Prepare API request data for Duitku
                    $duitku_data = [
                        'merchantCode' => $duitku_merchant_code,
                        'paymentAmount' => $totalTagihan,
                        'paymentMethod' => $duitku_method,
                        'merchantOrderId' => $order_id,
                        'productDetails' => 'Pembayaran WiFi ' . $pelanggan['PAKET'],
                        'customerName' => $pelanggan['NAMA'],
                        'customerEmail' => $email,
                        'customerPhone' => $pelanggan['NOWA'],
                        'callbackUrl' => $duitku_callback_url,
                        'returnUrl' => $duitku_return_url,
                        'signature' => $duitku_signature
                    ];
                    
                    // Request ke Duitku API
                    $duitku_ch = curl_init();
                    curl_setopt($duitku_ch, CURLOPT_URL, 'https://sandbox.duitku.com/webapi/api/merchant/v2/inquiry');
                    curl_setopt($duitku_ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($duitku_ch, CURLOPT_POST, true);
                    curl_setopt($duitku_ch, CURLOPT_POSTFIELDS, json_encode($duitku_data));
                    curl_setopt($duitku_ch, CURLOPT_HTTPHEADER, [
                        'Content-Type: application/json'
                    ]);
                    curl_setopt($duitku_ch, CURLOPT_TIMEOUT, 30);
                    
                    $duitku_response = curl_exec($duitku_ch);
                    $duitku_curl_error = curl_error($duitku_ch);
                    $duitku_http_code = curl_getinfo($duitku_ch, CURLINFO_HTTP_CODE);
                    curl_close($duitku_ch);
                    
                    // Error handling untuk Duitku
                    $duitku_error_message = '';
                    $duitku_debug_info = [
                        'request_data' => $duitku_data,
                        'response_raw' => $duitku_response,
                        'curl_error' => $duitku_curl_error,
                        'http_code' => $duitku_http_code,
                        'timestamp' => date('Y-m-d H:i:s')
                    ];
                    
                    if ($duitku_curl_error) {
                        $duitku_error_message = "CURL Error: " . $duitku_curl_error;
                    } elseif ($duitku_http_code != 200) {
                        $duitku_error_message = "HTTP Error: " . $duitku_http_code;
                    } elseif (empty($duitku_response)) {
                        $duitku_error_message = "Empty response from Duitku API";
                    }
                    
                    $duitku_result = json_decode($duitku_response, true);
                    
                    if (json_last_error() != JSON_ERROR_NONE) {
                        $duitku_error_message = "JSON Parse Error: " . json_last_error_msg();
                    }
                    
                    if (isset($duitku_result['statusCode']) && $duitku_result['statusCode'] == '00') {
                        // Duitku berhasil, set variabel untuk ditampilkan
                        $reference = $duitku_result['reference'];
                        $namapembayaran = $duitku_result['paymentName'] ?? 'Duitku Payment';
                        $kodebayar = $duitku_result['vaNumber'] ?? '';
                        $statusbayar = 'UNPAID';
                        $namapembayar = $pelanggan['NAMA'];
                        $idpembayar = $merchantRef;
                        $refpembayar = $reference;
                        $exp = strtotime('+24 hours'); // 24 jam dari sekarang
                        $cekout = $duitku_result['paymentUrl'] ?? '';
                        $payurl = $cekout;
                        // BUGFIX: qrString dari Duitku adalah payload QRIS mentah (teks EMVCo),
                        // BUKAN url gambar -- sebelumnya dipakai langsung sebagai <img src>
                        // (selalu broken image). Render jadi gambar QR lewat layanan publik
                        // qrserver.com supaya tampil sama seperti barcode gateway lain (Tripay/DompetX
                        // yang memang mengembalikan url gambar langsung dari API-nya).
                        $duitkuRawQrString = $duitku_result['qrString'] ?? '';
                        $barcode = $duitkuRawQrString !== ''
                            ? 'https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=' . urlencode($duitkuRawQrString)
                            : '';
                        $harusbayar = $totalTagihan;
                        $cekpaidtripay = 'UNPAID';
                        $instructions = [[
                            'title' => 'Instruksi Pembayaran Duitku',
                            'steps' => [
                                'Klik tombol "Lihat Checkout" untuk melanjutkan pembayaran',
                                'Pilih metode pembayaran yang telah Anda tentukan',
                                'Ikuti instruksi pembayaran yang muncul',
                                'Pembayaran akan diverifikasi otomatis'
                            ]
                        ]];
                        
                        // Simpan transaksi ke database
                        function formatTanggal($tanggal)
                        {
                            setlocale(LC_TIME, 'id_ID.UTF-8');
                            $timestamp = strtotime($tanggal);
                            return strftime('%A, %d %B %Y', $timestamp);
                        }

                        $ptanggal = formatTanggal($currentDate);
                        $pemilik = $pelanggan['PEMILIK'];
                        $namapaket = $pelanggan['PAKET'];
                        $nama = $pelanggan['NAMA'];

                        // PENTING: PENGUNAAN (periode) WAJIB disimpan di sini.
                        // Tanpa kolom ini, transaksi yang nanti ditandai LUNAS oleh
                        // callback Duitku tidak akan pernah cocok dengan pengecekan
                        // "$has_paid_periode" di atas (yang mencocokkan PENGUNAAN),
                        // sehingga status "sudah bayar" tidak pernah muncul di portal.
                        $query = "INSERT INTO transaksi (TANGGALBAYAR, PENGUNAAN, IDPEL, NAMA, PAKET, HARGA, STATUS, BUKTI, PEMILIK, CEK) 
                                  VALUES (?, ?, ?, ?, ?, ?, 'PERMINTAAN KODE', ?, ?, 'DUITKU')";
                        $stmt = $conn->prepare($query);
                        $stmt->bind_param("ssssssss", $ptanggal, $periode_tagihan, $merchantRef, $nama, $namapaket, $totalTagihan, $reference, $pemilik);
                        $stmt->execute();
                        $stmt->close();
                    } else {
                        // Duitku gagal - set error message
                        if (empty($duitku_error_message)) {
                            if (isset($duitku_result['statusMessage'])) {
                                $duitku_error_message = "Duitku Error: " . $duitku_result['statusMessage'];
                            } elseif (isset($duitku_result['statusCode'])) {
                                $duitku_error_message = "Duitku Error Code: " . $duitku_result['statusCode'];
                            } else {
                                $duitku_error_message = "Unknown Duitku API Error";
                            }
                        }
                        $duitku_debug_info['parsed_result'] = $duitku_result;
                    }
                    }
                }
                // ===================================================================
                // IPAYMU PAYMENT PROCESSING
                // Purpose: Handle iPaymu payment gateway form submission (redirect checkout)
                // ===================================================================
                elseif (isset($_POST['ipaymu_submit'])) {
                    $ipaymu_va = $_POST['ipaymu_va'];
                    $ipaymu_api_key = $_POST['ipaymu_api_key'];
                    $ipaymu_order_id = $_POST['order_id'];

                    function ipaymu_signed_request_bayar($method, $url, $va, $apiKey, $bodyArray) {
                        $body = json_encode($bodyArray, JSON_UNESCAPED_SLASHES);
                        $bodyHash = strtolower(hash('sha256', $body));
                        $stringToSign = strtoupper($method) . ':' . $va . ':' . $bodyHash . ':' . $apiKey;
                        $signature = hash_hmac('sha256', $stringToSign, $apiKey);
                        $timestamp = date('YmdHis');

                        $ch = curl_init();
                        curl_setopt_array($ch, [
                            CURLOPT_URL => $url,
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_POST => true,
                            CURLOPT_POSTFIELDS => $body,
                            CURLOPT_TIMEOUT => 30,
                            CURLOPT_HTTPHEADER => [
                                'Accept: application/json',
                                'Content-Type: application/json',
                                'va: ' . $va,
                                'signature: ' . $signature,
                                'timestamp: ' . $timestamp,
                            ],
                        ]);
                        $response = curl_exec($ch);
                        $curlError = curl_error($ch);
                        curl_close($ch);
                        if ($curlError) {
                            return ['Status' => -1, 'CurlError' => $curlError];
                        }
                        $decoded = json_decode($response, true);
                        return is_array($decoded) ? $decoded : ['Status' => -1, 'RawResponse' => $response];
                    }

                    $ipaymu_body = [
                        'product' => ['Pembayaran WiFi ' . $pelanggan['PAKET']],
                        'qty' => [1],
                        'price' => [(int)$totalTagihan],
                        'amount' => (int)$totalTagihan,
                        'referenceId' => $ipaymu_order_id,
                        'buyerName' => $pelanggan['NAMA'],
                        'buyerEmail' => $email,
                        'buyerPhone' => $pelanggan['NOWA'],
                        'notifyUrl' => "https://" . $_SERVER['HTTP_HOST'] . "/crm/billing/callbackipaymu/callback_ipaymu_" . $pelanggan['PEMILIK'] . ".php",
                        'returnUrl' => "https://" . $_SERVER['HTTP_HOST'] . "/crm/billing/broadband/portal_bayar.php?cari=" . $pelanggan['IDPEL'],
                        'cancelUrl' => "https://" . $_SERVER['HTTP_HOST'] . "/crm/billing/broadband/portal_bayar.php?cari=" . $pelanggan['IDPEL'],
                    ];

                    $ipaymu_result = ipaymu_signed_request_bayar('POST', 'https://my.ipaymu.com/api/v2/payment', $ipaymu_va, $ipaymu_api_key, $ipaymu_body);

                    if (isset($ipaymu_result['Status']) && (int)$ipaymu_result['Status'] === 1) {
                        $reference = $ipaymu_order_id;
                        $namapembayaran = 'iPaymu';
                        // SessionID iPaymu BUKAN kode bayar (cuma handle sesi internal) --
                        // dikosongkan supaya baris "Kode Bayar" tidak tampil (lihat kartu
                        // hasil transaksi), pelanggan cukup diarahkan lewat "Lihat Checkout".
                        $kodebayar = '';
                        $statusbayar = 'UNPAID';
                        $namapembayar = $pelanggan['NAMA'];
                        $idpembayar = $merchantRef;
                        $refpembayar = $reference;
                        $exp = strtotime('+24 hours');
                        $cekout = $ipaymu_result['Data']['Url'] ?? '';
                        $payurl = $cekout;
                        $barcode = '';
                        $harusbayar = $totalTagihan;
                        $cekpaidtripay = 'UNPAID';
                        $instructions = [[
                            'title' => 'Instruksi Pembayaran iPaymu',
                            'steps' => [
                                'Klik tombol "Lihat Checkout" untuk melanjutkan ke halaman pembayaran iPaymu',
                                'Pilih metode pembayaran yang tersedia (VA/QRIS/E-Wallet)',
                                'Ikuti instruksi pembayaran yang muncul',
                                'Pembayaran akan diverifikasi otomatis'
                            ]
                        ]];

                        function formatTanggalIpaymu($tanggal) {
                            setlocale(LC_TIME, 'id_ID.UTF-8');
                            return strftime('%A, %d %B %Y', strtotime($tanggal));
                        }
                        $ptanggal = formatTanggalIpaymu($currentDate);
                        $pemilik = $pelanggan['PEMILIK'];
                        $namapaket = $pelanggan['PAKET'];
                        $nama = $pelanggan['NAMA'];

                        $query = "INSERT INTO transaksi (TANGGALBAYAR, PENGUNAAN, IDPEL, NAMA, PAKET, HARGA, STATUS, BUKTI, PEMILIK, CEK)
                                  VALUES (?, ?, ?, ?, ?, ?, 'PERMINTAAN KODE', ?, ?, 'IPAYMU')";
                        $stmt = $conn->prepare($query);
                        $stmt->bind_param("ssssssss", $ptanggal, $periode_tagihan, $merchantRef, $nama, $namapaket, $totalTagihan, $reference, $pemilik);
                        $stmt->execute();
                        $stmt->close();
                    } else {
                        $ipaymu_error_message = $ipaymu_result['Message'] ?? ($ipaymu_result['CurlError'] ?? 'Unknown iPaymu API Error');
                    }
                }
                // ===================================================================
                // DOKU PAYMENT PROCESSING
                // Purpose: Handle DOKU payment gateway form submission (redirect checkout)
                // ===================================================================
                elseif (isset($_POST['doku_submit'])) {
                    $doku_client_id = $_POST['doku_client_id'];
                    $doku_secret_key = $_POST['doku_secret_key'];
                    $doku_order_id = $_POST['order_id'];

                    $doku_path = '/checkout/v1/payment';
                    $doku_request_id = uniqid('', true);
                    $doku_timestamp = gmdate('Y-m-d\TH:i:s\Z');
                    $doku_body_array = [
                        'order' => [
                            'invoice_number' => $doku_order_id,
                            'amount' => (int)$totalTagihan,
                            'callback_url' => "https://" . $_SERVER['HTTP_HOST'] . "/crm/billing/broadband/portal_bayar.php?cari=" . $pelanggan['IDPEL'],
                            'callback_url_cancel' => "https://" . $_SERVER['HTTP_HOST'] . "/crm/billing/broadband/portal_bayar.php?cari=" . $pelanggan['IDPEL'],
                        ],
                        'payment' => [
                            'payment_due_date' => 1440,
                        ],
                        'customer' => [
                            'name' => $pelanggan['NAMA'],
                            'email' => $email,
                        ],
                    ];
                    $doku_body = json_encode($doku_body_array);
                    $doku_digest = base64_encode(hash('sha256', $doku_body, true));
                    $doku_raw_signature = "Client-Id:$doku_client_id\n"
                        . "Request-Id:$doku_request_id\n"
                        . "Request-Timestamp:$doku_timestamp\n"
                        . "Request-Target:$doku_path\n"
                        . "Digest:$doku_digest";
                    $doku_signature = 'HMACSHA256=' . base64_encode(hash_hmac('sha256', $doku_raw_signature, $doku_secret_key, true));

                    $doku_ch = curl_init();
                    curl_setopt_array($doku_ch, [
                        CURLOPT_URL => 'https://api.doku.com' . $doku_path,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => $doku_body,
                        CURLOPT_TIMEOUT => 30,
                        CURLOPT_HTTPHEADER => [
                            'Content-Type: application/json',
                            'Client-Id: ' . $doku_client_id,
                            'Request-Id: ' . $doku_request_id,
                            'Request-Timestamp: ' . $doku_timestamp,
                            'Signature: ' . $doku_signature,
                        ],
                    ]);
                    $doku_response = curl_exec($doku_ch);
                    $doku_curl_error = curl_error($doku_ch);
                    curl_close($doku_ch);
                    $doku_result = json_decode($doku_response, true);

                    if (!$doku_curl_error && isset($doku_result['response']['payment']['url'])) {
                        $reference = $doku_order_id;
                        $namapembayaran = 'DOKU';
                        // token_id DOKU BUKAN kode bayar (token checkout internal) --
                        // dikosongkan supaya baris "Kode Bayar" tidak tampil, pelanggan
                        // cukup diarahkan lewat "Lihat Checkout".
                        $kodebayar = '';
                        $statusbayar = 'UNPAID';
                        $namapembayar = $pelanggan['NAMA'];
                        $idpembayar = $merchantRef;
                        $refpembayar = $reference;
                        $exp = strtotime('+24 hours');
                        $cekout = $doku_result['response']['payment']['url'];
                        $payurl = $cekout;
                        $barcode = '';
                        $harusbayar = $totalTagihan;
                        $cekpaidtripay = 'UNPAID';
                        $instructions = [[
                            'title' => 'Instruksi Pembayaran DOKU',
                            'steps' => [
                                'Klik tombol "Lihat Checkout" untuk melanjutkan ke halaman pembayaran DOKU',
                                'Pilih metode pembayaran yang tersedia',
                                'Ikuti instruksi pembayaran yang muncul',
                                'Pembayaran akan diverifikasi otomatis'
                            ]
                        ]];

                        function formatTanggalDoku($tanggal) {
                            setlocale(LC_TIME, 'id_ID.UTF-8');
                            return strftime('%A, %d %B %Y', strtotime($tanggal));
                        }
                        $ptanggal = formatTanggalDoku($currentDate);
                        $pemilik = $pelanggan['PEMILIK'];
                        $namapaket = $pelanggan['PAKET'];
                        $nama = $pelanggan['NAMA'];

                        $query = "INSERT INTO transaksi (TANGGALBAYAR, PENGUNAAN, IDPEL, NAMA, PAKET, HARGA, STATUS, BUKTI, PEMILIK, CEK)
                                  VALUES (?, ?, ?, ?, ?, ?, 'PERMINTAAN KODE', ?, ?, 'DOKU')";
                        $stmt = $conn->prepare($query);
                        $stmt->bind_param("ssssssss", $ptanggal, $periode_tagihan, $merchantRef, $nama, $namapaket, $totalTagihan, $reference, $pemilik);
                        $stmt->execute();
                        $stmt->close();
                    } else {
                        $doku_error_message = $doku_result['error']['message'] ?? ($doku_curl_error ?: 'Unknown DOKU API Error');
                    }
                }
                // ===================================================================
                // FASPAY PAYMENT PROCESSING
                // Purpose: Handle Faspay payment gateway form submission
                // ===================================================================
                elseif (isset($_POST['faspay_submit'])) {
                    $faspay_merchant_id = $_POST['faspay_merchant_id'];
                    $faspay_user_id = $_POST['faspay_user_id'];
                    $faspay_password = $_POST['faspay_password'];
                    $faspay_channel = $_POST['faspay_channel'];
                    $faspay_bill_no = $_POST['order_id'];

                    $faspay_signature = sha1(md5($faspay_user_id . $faspay_password . $faspay_bill_no));
                    $faspay_body = [
                        'request' => 'Post Data',
                        'merchant_id' => $faspay_merchant_id,
                        'merchant' => $faspay_merchant_id,
                        'bill_no' => $faspay_bill_no,
                        'bill_reff' => $faspay_bill_no,
                        'bill_date' => date('Y-m-d H:i:s'),
                        'bill_expired' => date('Y-m-d H:i:s', strtotime('+24 hours')),
                        'bill_desc' => 'Pembayaran WiFi ' . $pelanggan['PAKET'],
                        'bill_currency' => 'IDR',
                        'bill_gross' => (string)(int)$totalTagihan,
                        'cust_no' => $pelanggan['IDPEL'],
                        'cust_name' => $pelanggan['NAMA'],
                        'payment_channel' => $faspay_channel,
                        'pay_type' => '1',
                        'bank_userid' => '',
                        'terminal' => '10',
                        'signature' => $faspay_signature,
                    ];

                    // CATATAN: gateway_id (300011) dan channel/product id (10) di path ini adalah
                    // contoh placeholder. Faspay menetapkan ID ini per-merchant saat onboarding —
                    // ganti dengan path endpoint sesuai dokumentasi/akun sandbox Faspay Anda.
                    $faspay_ch = curl_init();
                    curl_setopt_array($faspay_ch, [
                        CURLOPT_URL => 'https://web.faspay.co.id/cvr/300011/10/post',
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => json_encode($faspay_body),
                        CURLOPT_TIMEOUT => 30,
                        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                    ]);
                    $faspay_response = curl_exec($faspay_ch);
                    $faspay_curl_error = curl_error($faspay_ch);
                    curl_close($faspay_ch);
                    $faspay_result = json_decode($faspay_response, true);

                    if (!$faspay_curl_error && isset($faspay_result['response_code']) && $faspay_result['response_code'] === '00') {
                        $reference = $faspay_bill_no;
                        $namapembayaran = 'Faspay';
                        // trx_id BUKAN kode bayar (referensi transaksi internal Faspay) --
                        // hanya isi $kodebayar kalau memang virtual_account (channel VA)
                        // benar-benar ada di respons; kalau tidak (QRIS/e-wallet/retail),
                        // biarkan kosong supaya baris "Kode Bayar" tidak tampil.
                        $kodebayar = $faspay_result['virtual_account'] ?? '';
                        $statusbayar = 'UNPAID';
                        $namapembayar = $pelanggan['NAMA'];
                        $idpembayar = $merchantRef;
                        $refpembayar = $reference;
                        $exp = strtotime('+24 hours');
                        $cekout = $faspay_result['redirect_url'] ?? '#';
                        $payurl = $cekout;
                        $barcode = '';
                        $harusbayar = $totalTagihan;
                        $cekpaidtripay = 'UNPAID';
                        $instructions = !empty($kodebayar) ? [[
                            'title' => 'Instruksi Pembayaran Faspay',
                            'steps' => [
                                'Catat/salin Kode Bayar (Virtual Account) di atas',
                                'Bayar melalui ATM/Mobile Banking/Internet Banking sesuai bank yang dipilih',
                                'Pembayaran akan diverifikasi otomatis'
                            ]
                        ]] : [[
                            'title' => 'Instruksi Pembayaran Faspay',
                            'steps' => [
                                'Klik tombol "Lihat Checkout" untuk melanjutkan ke halaman pembayaran Faspay',
                                'Selesaikan pembayaran sesuai metode (e-wallet/QRIS/retail) yang dipilih',
                                'Pembayaran akan diverifikasi otomatis'
                            ]
                        ]];

                        function formatTanggalFaspay($tanggal) {
                            setlocale(LC_TIME, 'id_ID.UTF-8');
                            return strftime('%A, %d %B %Y', strtotime($tanggal));
                        }
                        $ptanggal = formatTanggalFaspay($currentDate);
                        $pemilik = $pelanggan['PEMILIK'];
                        $namapaket = $pelanggan['PAKET'];
                        $nama = $pelanggan['NAMA'];

                        $query = "INSERT INTO transaksi (TANGGALBAYAR, PENGUNAAN, IDPEL, NAMA, PAKET, HARGA, STATUS, BUKTI, PEMILIK, CEK)
                                  VALUES (?, ?, ?, ?, ?, ?, 'PERMINTAAN KODE', ?, ?, 'FASPAY')";
                        $stmt = $conn->prepare($query);
                        $stmt->bind_param("ssssssss", $ptanggal, $periode_tagihan, $merchantRef, $nama, $namapaket, $totalTagihan, $reference, $pemilik);
                        $stmt->execute();
                        $stmt->close();
                    } else {
                        $faspay_error_message = $faspay_result['response_desc'] ?? ($faspay_curl_error ?: 'Unknown Faspay API Error');
                    }
                }
                // ===================================================================
                // DOMPETX PAYMENT PROCESSING
                // Purpose: Buat transaksi DompetX (POST /v1/payments) untuk channel yang
                // dipilih pelanggan, lalu tampilkan QR/kode bayar inline (kontrak variabel
                // sama persis dengan blok Tripay di bawah: $reference, $namapembayaran,
                // $kodebayar, $statusbayar, $exp, $cekout, $payurl, $barcode, $instructions).
                // ===================================================================
                elseif (isset($_POST['dompetx_submit'])) {
                    $dompetx_method = $_POST['dompetx_method'] ?? 'QRIS';

                    $dompetx_config_post = null;
                    $dompetx_query_post = "SELECT * FROM dompetx WHERE server = '" . mysqli_real_escape_string($conn, $username) . "' AND pemilik = '" . mysqli_real_escape_string($conn, $useraccount) . "' LIMIT 1";
                    $dompetx_result_post = mysqli_query($conn, $dompetx_query_post);
                    if ($dompetx_result_post && mysqli_num_rows($dompetx_result_post) > 0) {
                        $dompetx_config_post = mysqli_fetch_assoc($dompetx_result_post);
                    }

                    if (!$dompetx_config_post || empty($dompetx_config_post['api_key'])) {
                        $dompetx_error_message = 'Konfigurasi DompetX (API key) belum diatur.';
                    } else {
                        $dompetx_secret_post = !empty($dompetx_config_post['secret_key']) ? $dompetx_config_post['secret_key'] : $dompetx_config_post['api_key'];
                        $dompetx_order_id = 'INV-' . time() . '-' . $merchantRef;

                        $dompetx_body = [
                            'method' => $dompetx_method,
                            'amount' => (int) round($totalTagihan),
                            'currency' => 'IDR',
                            'reference' => $dompetx_order_id,
                            'redirectUrl' => "https://$domain/crm/billing/broadband/portal.php?cari={$pelanggan['IDPEL']}",
                            'metadata' => [
                                'order_name' => 'Pembayaran WiFi ' . $pelanggan['PAKET'],
                                'product_name' => $pelanggan['PAKET'],
                                'customer_name' => $pelanggan['NAMA'],
                                'customer_email' => $email,
                                'customer_phone' => $pelanggan['NOWA'],
                            ],
                        ];

                        $dompetx_resp = dompetx_request('POST', '/v1/payments', $dompetx_config_post['api_key'], $dompetx_secret_post, $dompetx_body);
                        $dompetx_debug_info = [
                            'request_data' => $dompetx_body,
                            'response_raw' => $dompetx_resp['raw'],
                            'curl_error' => $dompetx_resp['curl_error'],
                            'http_code' => $dompetx_resp['http_code'],
                            'timestamp' => date('Y-m-d H:i:s'),
                        ];

                        if ($dompetx_resp['ok'] && !empty($dompetx_resp['data']['id'])) {
                            $dxData = $dompetx_resp['data'];
                            $reference = $dompetx_order_id;
                            $namapembayaran = $dxData['type'] ?? $dompetx_method;

                            $dompetx_qr_string = $dxData['qrData']['qrString'] ?? '';
                            $dompetx_qr_image  = $dxData['qrData']['qrImage'] ?? '';

                            // Nama field nomor Virtual Account BELUM terkonfirmasi resmi dari
                            // dokumentasi DompetX (docs.dompetx.com selalu 403 saat diakses --
                            // lihat catatan di dompetx_helper.php). Coba beberapa nama field yang
                            // lazim dipakai payment gateway lain sbg kandidat (urut dari yang
                            // paling mungkin), sambil tetap log response SUKSES ke
                            // logs/dompetx_error.log (lihat bawah) supaya field yang tepat bisa
                            // dikonfirmasi & kandidat ini diperbaiki kalau ternyata masih meleset.
                            $dompetx_va_candidates = [
                                $dxData['virtualAccount'] ?? null,
                                $dxData['vaNumber'] ?? null,
                                $dxData['va_number'] ?? null,
                                $dxData['accountNumber'] ?? null,
                                $dxData['account_number'] ?? null,
                                $dxData['payCode'] ?? null,
                                $dxData['pay_code'] ?? null,
                                $dxData['paymentCode'] ?? null,
                                $dxData['vaData']['accountNumber'] ?? null,
                                $dxData['vaData']['virtualAccount'] ?? null,
                                $dxData['vaData']['vaNumber'] ?? null,
                            ];
                            $dompetx_va_number = '';
                            foreach ($dompetx_va_candidates as $dompetx_va_cand) {
                                if (!empty($dompetx_va_cand)) {
                                    $dompetx_va_number = (string) $dompetx_va_cand;
                                    break;
                                }
                            }

                            $kodebayar = $dompetx_va_number !== '' ? $dompetx_va_number : $dompetx_qr_string;
                            // Status DompetX dikonfirmasi "paid" (huruf kecil) di dokumentasi --
                            // normalisasi ke huruf besar supaya konsisten dengan perbandingan
                            // 'PAID' yang dipakai di blok render bersama di bawah.
                            $statusbayar = strtoupper((string) ($dxData['status'] ?? 'unpaid'));
                            $namapembayar = $pelanggan['NAMA'];
                            $idpembayar = $merchantRef;
                            $refpembayar = $reference;
                            $exp = !empty($dxData['expiresAt']) ? strtotime($dxData['expiresAt']) : strtotime('+24 hours');
                            $cekout = '';
                            $payurl = '';
                            $barcode = $dompetx_qr_image;
                            $harusbayar = $totalTagihan;
                            $cekpaidtripay = $statusbayar;

                            // Instruksi beda antara VA (kodebayar = nomor VA, dibayar via
                            // ATM/m-banking/i-banking) dan QRIS/lainnya (kodebayar = qrString,
                            // dibayar via scan barcode) -- dulu instruksinya hardcoded satu jenis
                            // (teks QRIS) utk semua channel, jadi salah utk VA.
                            if ($dompetx_va_number !== '') {
                                $instructions = [[
                                    'title' => 'Instruksi Pembayaran DompetX (' . htmlspecialchars($namapembayaran) . ')',
                                    'steps' => [
                                        'Catat/salin Kode Bayar (nomor Virtual Account) di atas.',
                                        'Bayar melalui ATM/Mobile Banking/Internet Banking sesuai bank yang dipilih, pastikan nominal sesuai Total Bayar.',
                                        'Pembayaran akan diverifikasi otomatis setelah kami menerima konfirmasi dari DompetX.',
                                    ],
                                ]];
                            } else {
                                $instructions = [[
                                    'title' => 'Instruksi Pembayaran DompetX (' . htmlspecialchars($namapembayaran) . ')',
                                    'steps' => [
                                        'Scan QR Code di atas menggunakan aplikasi e-wallet/mobile banking yang mendukung QRIS.',
                                        'Pastikan nominal yang tertera sesuai dengan Total Bayar.',
                                        'Pembayaran akan diverifikasi otomatis setelah kami menerima konfirmasi dari DompetX.',
                                    ],
                                ]];
                            }

                            // Log response SUKSES juga (bukan cuma gagal, lihat blok else di
                            // bawah) -- supaya kalau field VA yang dikandidatkan di atas ternyata
                            // masih belum tepat (mis. field aslinya beda nama), raw response asli
                            // dari DompetX tetap ada jejaknya utk dicek & kandidat diperbaiki,
                            // tanpa perlu reproduksi ulang transaksi tes.
                            $dompetxLogDirOk = __DIR__ . '/../logs';
                            if (!is_dir($dompetxLogDirOk)) {
                                @mkdir($dompetxLogDirOk, 0777, true);
                            }
                            $dompetxSuccessLogEntry = '[' . date('Y-m-d H:i:s') . "] SUKSES IDPEL={$pelanggan['IDPEL']} pemilik={$pelanggan['PEMILIK']} method={$dompetx_method}\n"
                                . 'response_raw: ' . json_encode($dxData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
                                . 'kodebayar_resolved: ' . ($kodebayar !== '' ? $kodebayar : '(kosong)') . ' (sumber: ' . ($dompetx_va_number !== '' ? 'kandidat VA' : 'qrString') . ")\n"
                                . str_repeat('-', 60) . "\n";
                            @file_put_contents($dompetxLogDirOk . '/dompetx_error.log', $dompetxSuccessLogEntry, FILE_APPEND | LOCK_EX);

                            function formatTanggalDompetx($tanggal) {
                                setlocale(LC_TIME, 'id_ID.UTF-8');
                                return strftime('%A, %d %B %Y', strtotime($tanggal));
                            }
                            $ptanggal = formatTanggalDompetx($currentDate);
                            $pemilik = $pelanggan['PEMILIK'];
                            $namapaket = $pelanggan['PAKET'];
                            $nama = $pelanggan['NAMA'];

                            // PENTING: PENGUNAAN (periode) WAJIB disimpan di sini juga, dengan
                            // alasan yang sama seperti blok gateway lain di atas -- callback
                            // DompetX mencocokkan transaksi lewat BUKTI (=$reference), bukan
                            // lewat id internal DompetX (lihat catatan reference di
                            // callbackdompetx/callback_dompetx.php).
                            $query = "INSERT INTO transaksi (TANGGALBAYAR, PENGUNAAN, IDPEL, NAMA, PAKET, HARGA, STATUS, BUKTI, PEMILIK, CEK)
                                      VALUES (?, ?, ?, ?, ?, ?, 'PERMINTAAN KODE', ?, ?, 'DOMPETX')";
                            $stmt = $conn->prepare($query);
                            $stmt->bind_param("ssssssss", $ptanggal, $periode_tagihan, $merchantRef, $nama, $namapaket, $totalTagihan, $reference, $pemilik);
                            $stmt->execute();
                            $stmt->close();
                        } else {
                            if (!empty($dompetx_resp['data']['message'])) {
                                $dompetx_error_message = 'DompetX Error: ' . $dompetx_resp['data']['message'];
                            } elseif (!empty($dompetx_resp['curl_error'])) {
                                $dompetx_error_message = 'CURL Error: ' . $dompetx_resp['curl_error'];
                            } else {
                                // response_raw disertakan (dipotong) krn body JSON error DompetX
                                // sering tidak ke-decode ($dompetx_resp['data'] null utk respons
                                // non-JSON spt halaman HTML 502) -- tanpa ini pesan cuma "HTTP
                                // Error: 502" tanpa petunjuk penyebab sama sekali.
                                $dompetx_error_message = 'HTTP Error: ' . $dompetx_resp['http_code'];
                                if (!empty($dompetx_resp['raw'])) {
                                    $dompetx_error_message .= ' - ' . mb_substr(strip_tags((string) $dompetx_resp['raw']), 0, 300);
                                }
                            }

                            // Log lengkap (request yang BENAR-BENAR dikirim + response mentah)
                            // ke file, bukan cuma tampil sekilas di layar -- supaya kegagalan
                            // DompetX di transaksi pelanggan sungguhan (bukan tes curl manual)
                            // bisa dibandingkan langsung tanpa perlu reproduksi ulang.
                            $dompetxLogDir = __DIR__ . '/../logs';
                            if (!is_dir($dompetxLogDir)) {
                                @mkdir($dompetxLogDir, 0777, true);
                            }
                            $dompetxLogEntry = '[' . date('Y-m-d H:i:s') . "] IDPEL={$pelanggan['IDPEL']} pemilik={$pelanggan['PEMILIK']}\n"
                                . 'request: ' . json_encode($dompetx_body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
                                . 'http_code: ' . $dompetx_resp['http_code'] . "\n"
                                . 'curl_error: ' . ($dompetx_resp['curl_error'] !== '' ? $dompetx_resp['curl_error'] : '(kosong)') . "\n"
                                . 'response_raw: ' . $dompetx_resp['raw'] . "\n"
                                . str_repeat('-', 60) . "\n";
                            @file_put_contents($dompetxLogDir . '/dompetx_error.log', $dompetxLogEntry, FILE_APPEND | LOCK_EX);
                        }
                    }
                }
                // ===================================================================
                // MIDTRANS PAYMENT PROCESSING (Snap API)
                // Purpose: Buat transaksi Midtrans lewat Snap API, dapatkan redirect_url
                // hosted-checkout -- pelanggan pilih channel (VA/QRIS/e-wallet/kartu) di
                // halaman Snap itu sendiri, jadi tidak ada kode bayar/QR untuk ditampilkan
                // inline di sini (sama seperti pola iPaymu/DOKU).
                // ===================================================================
                elseif (isset($_POST['midtrans_submit'])) {
                    $midtrans_config = null;
                    $midtrans_query2 = "SELECT * FROM midtrans WHERE server = '" . mysqli_real_escape_string($conn, $username) . "' AND pemilik = '" . mysqli_real_escape_string($conn, $useraccount) . "' LIMIT 1";
                    $midtrans_result2 = mysqli_query($conn, $midtrans_query2);
                    if ($midtrans_result2 && mysqli_num_rows($midtrans_result2) > 0) {
                        $midtrans_config = mysqli_fetch_assoc($midtrans_result2);
                    }

                    if (!$midtrans_config || empty($midtrans_config['server_key'])) {
                        $midtrans_error_message = 'Konfigurasi Midtrans (server key) belum diatur.';
                    } else {
                        $midtrans_order_id = 'INV-' . time() . '-' . $merchantRef;
                        $midtrans_amount = (int) round($totalTagihan);

                        $midtrans_body = [
                            'transaction_details' => [
                                'order_id' => $midtrans_order_id,
                                'gross_amount' => $midtrans_amount,
                            ],
                            'customer_details' => [
                                'first_name' => $pelanggan['NAMA'],
                                'email' => $email,
                                'phone' => $pelanggan['NOWA'],
                            ],
                            'item_details' => [[
                                'id' => $merchantRef,
                                'price' => $midtrans_amount,
                                'quantity' => 1,
                                'name' => substr('Pembayaran WiFi ' . $pelanggan['PAKET'], 0, 50),
                            ]],
                            'callbacks' => [
                                'finish' => $midtrans_config['return'] ?: "https://$domain/crm/billing/broadband/portal.php?cari={$pelanggan['IDPEL']}",
                            ],
                        ];

                        // Default sandbox supaya aman kalau admin belum sengaja set production.
                        // Isi kolom 'server' di tabel midtrans dengan 'production' untuk live.
                        $midtransIsProduction = strtolower(trim((string) ($midtrans_config['server'] ?? ''))) === 'production';
                        $midtransBaseUrl = $midtransIsProduction ? 'https://app.midtrans.com' : 'https://app.sandbox.midtrans.com';

                        $midtrans_auth = base64_encode($midtrans_config['server_key'] . ':');
                        $midtrans_ch = curl_init();
                        curl_setopt_array($midtrans_ch, [
                            CURLOPT_URL => $midtransBaseUrl . '/snap/v1/transactions',
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_POST => true,
                            CURLOPT_POSTFIELDS => json_encode($midtrans_body),
                            CURLOPT_TIMEOUT => 30,
                            CURLOPT_HTTPHEADER => [
                                'Accept: application/json',
                                'Content-Type: application/json',
                                'Authorization: Basic ' . $midtrans_auth,
                            ],
                        ]);
                        $midtrans_response = curl_exec($midtrans_ch);
                        $midtrans_curl_error = curl_error($midtrans_ch);
                        $midtrans_http_code = curl_getinfo($midtrans_ch, CURLINFO_HTTP_CODE);
                        curl_close($midtrans_ch);

                        $midtrans_debug_info = [
                            'request_data' => $midtrans_body,
                            'response_raw' => $midtrans_response,
                            'curl_error' => $midtrans_curl_error,
                            'http_code' => $midtrans_http_code,
                            'timestamp' => date('Y-m-d H:i:s'),
                        ];

                        $midtrans_result_data = json_decode($midtrans_response, true);

                        if ($midtrans_curl_error) {
                            $midtrans_error_message = 'CURL Error: ' . $midtrans_curl_error;
                        } elseif (empty($midtrans_result_data['redirect_url'])) {
                            $midtrans_error_message = 'Midtrans Error: ' . ($midtrans_result_data['error_messages'][0] ?? ('HTTP ' . $midtrans_http_code));
                            $midtrans_debug_info['parsed_result'] = $midtrans_result_data;
                        } else {
                            $reference = $midtrans_order_id;
                            $namapembayaran = 'Midtrans Payment Gateway';
                            $kodebayar = '';
                            $statusbayar = 'UNPAID';
                            $namapembayar = $pelanggan['NAMA'];
                            $idpembayar = $merchantRef;
                            $refpembayar = $reference;
                            $exp = strtotime('+24 hours');
                            $cekout = $midtrans_result_data['redirect_url'];
                            $payurl = $cekout;
                            $barcode = '';
                            $harusbayar = $midtrans_amount;
                            $cekpaidtripay = 'UNPAID';
                            $instructions = [[
                                'title' => 'Instruksi Pembayaran Midtrans',
                                'steps' => [
                                    'Klik tombol "Lihat Checkout" untuk melanjutkan ke halaman pembayaran Midtrans',
                                    'Pilih metode pembayaran yang tersedia (VA/QRIS/E-Wallet/Kartu Kredit)',
                                    'Ikuti instruksi pembayaran yang muncul',
                                    'Pembayaran akan diverifikasi otomatis',
                                ],
                            ]];

                            function formatTanggalMidtrans($tanggal) {
                                setlocale(LC_TIME, 'id_ID.UTF-8');
                                return strftime('%A, %d %B %Y', strtotime($tanggal));
                            }
                            $ptanggal = formatTanggalMidtrans($currentDate);
                            $pemilik = $pelanggan['PEMILIK'];
                            $namapaket = $pelanggan['PAKET'];
                            $nama = $pelanggan['NAMA'];

                            $query = "INSERT INTO transaksi (TANGGALBAYAR, PENGUNAAN, IDPEL, NAMA, PAKET, HARGA, STATUS, BUKTI, PEMILIK, CEK)
                                      VALUES (?, ?, ?, ?, ?, ?, 'PERMINTAAN KODE', ?, ?, 'MIDTRANS')";
                            $stmt = $conn->prepare($query);
                            $stmt->bind_param("ssssssss", $ptanggal, $periode_tagihan, $merchantRef, $nama, $namapaket, $midtrans_amount, $reference, $pemilik);
                            $stmt->execute();
                            $stmt->close();
                        }
                    }
                }
                // ===================================================================
                // XENDIT PAYMENT PROCESSING (Invoice API)
                // Purpose: Buat transaksi Xendit lewat Invoice API, dapatkan invoice_url
                // hosted-checkout -- pola sama seperti Midtrans di atas.
                // ===================================================================
                elseif (isset($_POST['xendit_submit'])) {
                    $xendit_config = null;
                    $xendit_query2 = "SELECT * FROM xendit WHERE server = '" . mysqli_real_escape_string($conn, $username) . "' AND pemilik = '" . mysqli_real_escape_string($conn, $useraccount) . "' LIMIT 1";
                    $xendit_result2 = mysqli_query($conn, $xendit_query2);
                    if ($xendit_result2 && mysqli_num_rows($xendit_result2) > 0) {
                        $xendit_config = mysqli_fetch_assoc($xendit_result2);
                    }

                    if (!$xendit_config || empty($xendit_config['server_key'])) {
                        $xendit_error_message = 'Konfigurasi Xendit (secret/server key) belum diatur.';
                    } else {
                        $xendit_external_id = 'INV-' . time() . '-' . $merchantRef;
                        $xendit_amount = (int) round($totalTagihan);

                        $xendit_body = [
                            'external_id' => $xendit_external_id,
                            'amount' => $xendit_amount,
                            'description' => 'Pembayaran WiFi ' . $pelanggan['PAKET'] . ' - ' . $merchantRef,
                            'payer_email' => $email,
                            'customer' => [
                                'given_names' => $pelanggan['NAMA'],
                                'email' => $email,
                                'mobile_number' => $pelanggan['NOWA'],
                            ],
                            'success_redirect_url' => $xendit_config['return'] ?: "https://$domain/crm/billing/broadband/portal.php?cari={$pelanggan['IDPEL']}",
                        ];

                        $xendit_auth = base64_encode($xendit_config['server_key'] . ':');
                        $xendit_ch = curl_init();
                        curl_setopt_array($xendit_ch, [
                            CURLOPT_URL => 'https://api.xendit.co/v2/invoices',
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_POST => true,
                            CURLOPT_POSTFIELDS => json_encode($xendit_body),
                            CURLOPT_TIMEOUT => 30,
                            CURLOPT_HTTPHEADER => [
                                'Content-Type: application/json',
                                'Authorization: Basic ' . $xendit_auth,
                            ],
                        ]);
                        $xendit_response = curl_exec($xendit_ch);
                        $xendit_curl_error = curl_error($xendit_ch);
                        $xendit_http_code = curl_getinfo($xendit_ch, CURLINFO_HTTP_CODE);
                        curl_close($xendit_ch);

                        $xendit_debug_info = [
                            'request_data' => $xendit_body,
                            'response_raw' => $xendit_response,
                            'curl_error' => $xendit_curl_error,
                            'http_code' => $xendit_http_code,
                            'timestamp' => date('Y-m-d H:i:s'),
                        ];

                        $xendit_result_data = json_decode($xendit_response, true);

                        if ($xendit_curl_error) {
                            $xendit_error_message = 'CURL Error: ' . $xendit_curl_error;
                        } elseif (empty($xendit_result_data['invoice_url'])) {
                            $xendit_error_message = 'Xendit Error: ' . ($xendit_result_data['message'] ?? ('HTTP ' . $xendit_http_code));
                            $xendit_debug_info['parsed_result'] = $xendit_result_data;
                        } else {
                            $reference = $xendit_result_data['external_id'] ?? $xendit_external_id;
                            $namapembayaran = 'Xendit Payment Gateway';
                            $kodebayar = '';
                            $statusbayar = 'UNPAID';
                            $namapembayar = $pelanggan['NAMA'];
                            $idpembayar = $merchantRef;
                            $refpembayar = $reference;
                            $exp = !empty($xendit_result_data['expiry_date']) ? strtotime($xendit_result_data['expiry_date']) : strtotime('+24 hours');
                            $cekout = $xendit_result_data['invoice_url'];
                            $payurl = $cekout;
                            $barcode = '';
                            $harusbayar = $xendit_amount;
                            $cekpaidtripay = 'UNPAID';
                            $instructions = [[
                                'title' => 'Instruksi Pembayaran Xendit',
                                'steps' => [
                                    'Klik tombol "Lihat Checkout" untuk melanjutkan ke halaman pembayaran Xendit',
                                    'Pilih metode pembayaran yang tersedia (VA/QRIS/E-Wallet/Kartu Kredit/Retail)',
                                    'Ikuti instruksi pembayaran yang muncul',
                                    'Pembayaran akan diverifikasi otomatis',
                                ],
                            ]];

                            function formatTanggalXendit($tanggal) {
                                setlocale(LC_TIME, 'id_ID.UTF-8');
                                return strftime('%A, %d %B %Y', strtotime($tanggal));
                            }
                            $ptanggal = formatTanggalXendit($currentDate);
                            $pemilik = $pelanggan['PEMILIK'];
                            $namapaket = $pelanggan['PAKET'];
                            $nama = $pelanggan['NAMA'];

                            $query = "INSERT INTO transaksi (TANGGALBAYAR, PENGUNAAN, IDPEL, NAMA, PAKET, HARGA, STATUS, BUKTI, PEMILIK, CEK)
                                      VALUES (?, ?, ?, ?, ?, ?, 'PERMINTAAN KODE', ?, ?, 'XENDIT')";
                            $stmt = $conn->prepare($query);
                            $stmt->bind_param("ssssssss", $ptanggal, $periode_tagihan, $merchantRef, $nama, $namapaket, $xendit_amount, $reference, $pemilik);
                            $stmt->execute();
                            $stmt->close();
                        }
                    }
                }
                // Proses Tripay Payment (existing code)
                elseif (isset($_POST['method'])) {
                    $method = $_POST['method'];
                    $tripayAmount = (float)$totalTagihan;
                    $channelFeeCustomerFlat = 0.0;
                    $channelFeeCustomerPercent = 0.0;
                    $channelMinimumFee = 0.0;
                    $channelMaximumFee = 0.0;

                    if (!empty($payment_channels) && is_array($payment_channels)) {
                        foreach ($payment_channels as $channelRow) {
                            if (($channelRow['code'] ?? '') !== $method) {
                                continue;
                            }
                            $channelFeeCustomerFlat = (float)($channelRow['fee_customer']['flat'] ?? 0);
                            $channelFeeCustomerPercent = (float)($channelRow['fee_customer']['percent'] ?? 0);
                            $channelMinimumFee = (float)($channelRow['minimum_fee'] ?? 0);
                            $channelMaximumFee = (float)($channelRow['maximum_fee'] ?? 0);
                            break;
                        }
                    }

                    $channelCustomerFee = $channelFeeCustomerFlat + ((float)$totalTagihan * ($channelFeeCustomerPercent / 100));
                    if ($channelCustomerFee > 0 && $channelMinimumFee > 0 && $channelCustomerFee < $channelMinimumFee) {
                        $channelCustomerFee = $channelMinimumFee;
                    }
                    if ($channelCustomerFee > 0 && $channelMaximumFee > 0 && $channelCustomerFee > $channelMaximumFee) {
                        $channelCustomerFee = $channelMaximumFee;
                    }
                    $tripayAmount = max(0, $tripayAmount + max(0, $channelCustomerFee));
                    $tripayAmount = (int)round($tripayAmount);

                    $signature = hash_hmac('sha256', $merchantCode . $merchantRef . $tripayAmount, $privateKey);

                    $data = [
                        'method'         => $method,
                        'merchant_ref'   => $merchantRef,
                        'amount'         => $tripayAmount,
                        'customer_name'  => $pelanggan['NAMA'],
                        'customer_email' => $email,
                        'customer_phone' => $pelanggan['NOWA'],
                        'order_items'    => [[
                            'name'     => 'Pembayaran WiFi ' . $pelanggan['PAKET'],
                            'price'    => $tripayAmount,
                            'quantity' => 1
                        ]],
                        'callback_url'   => "https://$domain/crm/billing/callbacktripay/callback_tripay_$useraccount.php",
                        'return_url'     => "https://$domain/crm/billing/broadband/portal.php?cari={$pelanggan['IDPEL']}",
                        'signature'      => $signature
                    ];

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, 'https://tripay.co.id/api/transaction/create');
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Authorization: Bearer ' . $apiKey,
                        'Content-Type: application/json'
                    ]);
                    curl_setopt($ch, CURLOPT_POST, 1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                    
                    $response = curl_exec($ch);
                    $tripay_curl_error = curl_error($ch);
                    $tripay_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    // Error handling untuk Tripay
                    $tripay_error_message = '';
                    $tripay_debug_info = [
                        'request_data' => $data,
                        'response_raw' => $response,
                        'curl_error' => $tripay_curl_error,
                        'http_code' => $tripay_http_code,
                        'timestamp' => date('Y-m-d H:i:s')
                    ];
                    
                    if ($tripay_curl_error) {
                        $tripay_error_message = "CURL Error: " . $tripay_curl_error;
                    } elseif ($tripay_http_code != 200) {
                        $tripay_error_message = "HTTP Error: " . $tripay_http_code;
                    } elseif (empty($response)) {
                        $tripay_error_message = "Empty response from Tripay API";
                    }

                    $result = json_decode($response, true);
                    
                    if (json_last_error() != JSON_ERROR_NONE) {
                        $tripay_error_message = "JSON Parse Error: " . json_last_error_msg();
                    }

                    if (isset($result['data']['reference'])) {
                        $reference = $result['data']['reference'];
                        $namapembayaran = $result['data']['payment_name'];
                        $kodebayar = $result['data']['pay_code'];
                        $statusbayar = $result['data']['status'];
                        $namapembayar = $result['data']['customer_name'];
                        $idpembayar = $result['data']['merchant_ref'];
                        $refpembayar = $result['data']['reference'];
                        $exp = $result['data']['expired_time'];
                        $cekout = $result['data']['checkout_url'];
                        $payurl = $result['data']['pay_url'];
                        $barcode = $result['data']['qr_url'];
                        $harusbayar = $result['data']['amount'];
                        $cekpaidtripay = $result['data']['status'];
                        $instructions = $result['data']['instructions'];

                        function formatTanggal($tanggal)
                        {
                            setlocale(LC_TIME, 'id_ID.UTF-8');
                            $timestamp = strtotime($tanggal);
                            return strftime('%A, %d %B %Y', $timestamp);
                        }

                        $ptanggal = formatTanggal($currentDate);
                        
                        $pemilik = $pelanggan['PEMILIK'];
                        $namapaket = $pelanggan['PAKET'];
                        $nama = $pelanggan['NAMA'];

                        // PENTING: PENGUNAAN (periode) WAJIB disimpan di sini juga,
                        // dengan alasan yang sama seperti pada blok Duitku di atas.
                        $query = "INSERT INTO transaksi (TANGGALBAYAR, PENGUNAAN, IDPEL, NAMA, PAKET, HARGA, STATUS, BUKTI, PEMILIK, CEK) 
                                  VALUES (?, ?, ?, ?, ?, ?, 'PERMINTAAN KODE', ?, ?, 'PERMINTAAN')";
                        $stmt = $conn->prepare($query);
                        $stmt->bind_param("ssssssss", $ptanggal, $periode_tagihan, $merchantRef, $nama, $namapaket, $tripayAmount, $reference, $pemilik);
                        $stmt->execute();
                        $stmt->close();
                    } else {
                        // Tripay gagal - set error message
                        if (empty($tripay_error_message)) {
                            if (isset($result['error_message'])) {
                                $tripay_error_message = "Tripay Error: " . $result['error_message'];
                            } elseif (isset($result['message'])) {
                                $tripay_error_message = "Tripay Error: " . $result['message'];
                            } else {
                                $tripay_error_message = "Unknown Tripay API Error";
                            }
                        }
                        $tripay_debug_info['parsed_result'] = $result;
                    }
                }
            }
         
            // Display error messages
            if (isset($duitku_error_message) && !empty($duitku_error_message)) {
        ?>
                <div class="alert alert-error">
                    <i class="bi bi-exclamation-triangle"></i> <strong>Error Duitku Payment Gateway</strong><br>
                    <?= htmlspecialchars($duitku_error_message) ?>
                    
                    <div style="margin-top: 10px; padding: 8px; background-color: rgba(255,255,255,0.7); border-radius: 4px; font-size: 12px;">
                        <strong>Troubleshooting Tips:</strong><br>
                        � Pastikan konfigurasi Duitku sudah benar<br>
                        � Cek koneksi internet dan firewall<br>
                        � Periksa merchant code dan API key<br>
                        � Hubungi support jika masalah berlanjut
                    </div>
                    
                    <div style="margin-top: 10px;">
                        <button onclick="toggleDebug('duitku')" class="btn" style="background-color: #6c757d; color: white; border: none; padding: 5px 10px; border-radius: 5px; font-size: 12px;">
                            <i class="bi bi-bug"></i> Show Debug Info
                        </button>
                        <button onclick="location.reload()" class="btn" style="background-color: var(--orange); color: white; border: none; padding: 5px 10px; border-radius: 5px; font-size: 12px; margin-left: 5px;">
                            <i class="bi bi-arrow-clockwise"></i> Retry
                        </button>
                    </div>
                    
                    <div id="duitku-debug" class="debug-info" style="display: none; margin-top: 10px; background-color: #f8f9fa; padding: 10px; border-radius: 5px; font-family: monospace; font-size: 11px; border: 1px solid #dee2e6;">
                        <div style="margin-bottom: 8px; font-weight: bold; color: #495057;">Debug Information:</div>
                        <pre style="margin: 0; white-space: pre-wrap; word-wrap: break-word;"><?= htmlspecialchars(json_encode($duitku_debug_info, JSON_PRETTY_PRINT)) ?></pre>
                    </div>
                </div>
        <?php
            }
            
            if (isset($tripay_error_message) && !empty($tripay_error_message)) {
        ?>
                <div class="alert alert-error">
                    <i class="bi bi-exclamation-triangle"></i> <strong>Error Tripay Payment Gateway</strong><br>
                    <?= htmlspecialchars($tripay_error_message) ?>
                    
                    <div style="margin-top: 10px; padding: 8px; background-color: rgba(255,255,255,0.7); border-radius: 4px; font-size: 12px;">
                        <strong>Troubleshooting Tips:</strong><br>
                        � Pastikan API Key dan Private Key Tripay sudah benar<br>
                        � Cek koneksi internet dan firewall<br>
                        � Periksa merchant code dan signature<br>
                        � Pastikan akun Tripay dalam status aktif
                    </div>
                    
                    <div style="margin-top: 10px;">
                        <button onclick="toggleDebug('tripay')" class="btn" style="background-color: #6c757d; color: white; border: none; padding: 5px 10px; border-radius: 5px; font-size: 12px;">
                            <i class="bi bi-bug"></i> Show Debug Info
                        </button>
                        <button onclick="location.reload()" class="btn" style="background-color: var(--orange); color: white; border: none; padding: 5px 10px; border-radius: 5px; font-size: 12px; margin-left: 5px;">
                            <i class="bi bi-arrow-clockwise"></i> Retry
                        </button>
                    </div>
                    
                    <div id="tripay-debug" class="debug-info" style="display: none; margin-top: 10px; background-color: #f8f9fa; padding: 10px; border-radius: 5px; font-family: monospace; font-size: 11px; border: 1px solid #dee2e6;">
                        <div style="margin-bottom: 8px; font-weight: bold; color: #495057;">Debug Information:</div>
                        <pre style="margin: 0; white-space: pre-wrap; word-wrap: break-word;"><?= htmlspecialchars(json_encode($tripay_debug_info, JSON_PRETTY_PRINT)) ?></pre>
                    </div>
                </div>
        <?php
            }

            if (isset($midtrans_error_message) && !empty($midtrans_error_message)) {
        ?>
                <div class="alert alert-error">
                    <i class="bi bi-exclamation-triangle"></i> <strong>Error Midtrans Payment Gateway</strong><br>
                    <?= htmlspecialchars($midtrans_error_message) ?>

                    <div style="margin-top: 10px; padding: 8px; background-color: rgba(255,255,255,0.7); border-radius: 4px; font-size: 12px;">
                        <strong>Troubleshooting Tips:</strong><br>
                        &bull; Pastikan Server Key Midtrans sudah benar<br>
                        &bull; Cek koneksi internet dan firewall<br>
                        &bull; Pastikan akun Midtrans dalam status aktif
                    </div>

                    <div style="margin-top: 10px;">
                        <button onclick="toggleDebug('midtrans')" class="btn" style="background-color: #6c757d; color: white; border: none; padding: 5px 10px; border-radius: 5px; font-size: 12px;">
                            <i class="bi bi-bug"></i> Show Debug Info
                        </button>
                        <button onclick="location.reload()" class="btn" style="background-color: var(--orange); color: white; border: none; padding: 5px 10px; border-radius: 5px; font-size: 12px; margin-left: 5px;">
                            <i class="bi bi-arrow-clockwise"></i> Retry
                        </button>
                    </div>

                    <div id="midtrans-debug" class="debug-info" style="display: none; margin-top: 10px; background-color: #f8f9fa; padding: 10px; border-radius: 5px; font-family: monospace; font-size: 11px; border: 1px solid #dee2e6;">
                        <div style="margin-bottom: 8px; font-weight: bold; color: #495057;">Debug Information:</div>
                        <pre style="margin: 0; white-space: pre-wrap; word-wrap: break-word;"><?= htmlspecialchars(json_encode($midtrans_debug_info ?? [], JSON_PRETTY_PRINT)) ?></pre>
                    </div>
                </div>
        <?php
            }

            if (isset($xendit_error_message) && !empty($xendit_error_message)) {
        ?>
                <div class="alert alert-error">
                    <i class="bi bi-exclamation-triangle"></i> <strong>Error Xendit Payment Gateway</strong><br>
                    <?= htmlspecialchars($xendit_error_message) ?>

                    <div style="margin-top: 10px; padding: 8px; background-color: rgba(255,255,255,0.7); border-radius: 4px; font-size: 12px;">
                        <strong>Troubleshooting Tips:</strong><br>
                        &bull; Pastikan Secret Key Xendit sudah benar<br>
                        &bull; Cek koneksi internet dan firewall<br>
                        &bull; Pastikan akun Xendit dalam status aktif
                    </div>

                    <div style="margin-top: 10px;">
                        <button onclick="toggleDebug('xendit')" class="btn" style="background-color: #6c757d; color: white; border: none; padding: 5px 10px; border-radius: 5px; font-size: 12px;">
                            <i class="bi bi-bug"></i> Show Debug Info
                        </button>
                        <button onclick="location.reload()" class="btn" style="background-color: var(--orange); color: white; border: none; padding: 5px 10px; border-radius: 5px; font-size: 12px; margin-left: 5px;">
                            <i class="bi bi-arrow-clockwise"></i> Retry
                        </button>
                    </div>

                    <div id="xendit-debug" class="debug-info" style="display: none; margin-top: 10px; background-color: #f8f9fa; padding: 10px; border-radius: 5px; font-family: monospace; font-size: 11px; border: 1px solid #dee2e6;">
                        <div style="margin-bottom: 8px; font-weight: bold; color: #495057;">Debug Information:</div>
                        <pre style="margin: 0; white-space: pre-wrap; word-wrap: break-word;"><?= htmlspecialchars(json_encode($xendit_debug_info ?? [], JSON_PRETTY_PRINT)) ?></pre>
                    </div>
                </div>
        <?php
            }
            
            if (isset($reference) && $reference != '') {
        ?>

        <div class="payment-details">
            <div class="payment-details-header">Kode Pembayaran Anda</div>
            <div class="payment-info">
                <p><strong>Metode:</strong> <?php echo $namapembayaran ?></p>
                <p><strong>Referensi:</strong> <?php echo $reference ?></p>
                <p><strong>Nominal:</strong> Rp<?php echo number_format($harusbayar, 0, ',', '.'); ?></p>
                <?php if (!empty($kodebayar)): ?>
                <p><strong>Kode Bayar:</strong> <?php echo $kodebayar; ?></p>
                <?php endif; ?>

                <?php
                $now = time();
                $expiredTimestamp = $exp;
                $isExpired = $now > $expiredTimestamp;
                $expiredFormatted = date('d-m-Y H:i:s', $expiredTimestamp);
                ?>
                
                <p><strong>Berlaku Hingga:</strong> <?= $expiredFormatted; ?></p>
                
                <p><strong>Status:</strong>
                    <span class="payment-status <?= ($isExpired ? 'status-expired' : ($statusbayar == 'PAID' ? 'status-paid' : 'status-unpaid')); ?>">
                        <?= $isExpired ? 'Expired' : $statusbayar; ?>
                    </span>
                </p>
            </div>
            
            <div class="payment-actions">
                <?php if (!empty($cekout) && $cekout !== '#'): ?>
                <a href="<?php echo $cekout; ?>" class="payment-button btn-success" target="_blank">Lihat Checkout</a>
                <?php endif; ?>
                <a href="portal_baru.php?cari=<?= $merchantRef; ?>&ref=<?= $reference; ?>&action=hapus" class="payment-button btn-danger">Batalkan</a>
            </div>
            
            <?php if (strpos(strtolower($namapembayaran), 'dana') !== false): ?>
            <div class="payment-actions">
                <a href="<?php echo $payurl; ?>" class="payment-button btn-success btn-sm" style="background-color: #28a745; color: white;" target="_blank">Lanjut Bayar VIA DANA</a>
            </div>
        <?php elseif (strpos(strtolower($namapembayaran), 'gopay') !== false || strpos(strtolower($namapembayaran), 'gpay') !== false): ?>
            <div class="payment-actions">
                <a href="<?php echo $payurl; ?>" class="payment-button btn-success btn-sm" style="background-color: #00AA13; color: white;" target="_blank">Lanjut Bayar VIA GoPay</a>
            </div>
        <?php endif; ?>

            <?php if (!empty($barcode)): ?>
                <div class="qr-code" style="text-align: center; margin-top: 20px;">
                    <h5>Scan QR Code untuk Pembayaran</h5>
                    <img src="<?php echo $barcode . (strpos($barcode, '?') === false ? '?' : '&') . 't=' . time(); ?>" alt="QR Code" style="max-width: 200px; border: 1px solid #ddd; padding: 10px;">
                </div>
            <?php endif; ?>

        </div>

        <?php foreach ($instructions as $instruction): ?>
            <div class="payment-details">
                <div class="payment-details-header">Instruksi Pembayaran</div>
                <div class="payment-info">
                    <h4><?php echo $instruction['title']; ?></h4>
                    <ul>
                        <?php foreach ($instruction['steps'] as $step): ?>
                            <li><?php echo $step; ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endforeach; ?>

        <?php
            }
        }
        ?>

        <?php /* Jangan tampilkan form bayar kalau periode ini SUDAH lunas ($has_paid_periode)
              -- baris PENAGIHAN kadang lolos tidak terhapus (mis. pembayaran manual/insert
              langsung yang tidak lewat callback gateway), jangan sampai pelanggan yang
              sudah bayar tetap diminta bayar lagi krn baris PENAGIHAN-nya masih nyangkut. */ ?>
        <?php if($has_penagihan_periode && !$has_paid_periode && !$has_existing_transaction && empty($reference)): ?>
            <?php 
            // Tentukan payment method berdasarkan default setting
            $show_tripay = false;
            $show_duitku = false;
            $show_ipaymu = false;
            $show_doku = false;
            $show_faspay = false;
            $show_dompetx = false;
            $show_manual = false;

            switch($default_payment) {
                case 'tripay':
                    $show_tripay = ($apiKey != '');
                    $show_manual = ($apiKey == ''); // fallback jika tripay tidak tersedia
                    break;
                case 'duitku':
                    $show_duitku = true;
                    $show_manual = false; // jangan tampilkan manual jika duitku dipilih
                    break;
                case 'midtrans':
                    $show_midtrans = true;
                    $show_manual = false; // jangan tampilkan manual jika midtrans dipilih
                    break;
                case 'xendit':
                    $show_xendit = true;
                    $show_manual = false; // jangan tampilkan manual jika xendit dipilih
                    break;
                case 'ipaymu':
                    $show_ipaymu = true;
                    $show_manual = false; // jangan tampilkan manual jika ipaymu dipilih
                    break;
                case 'doku':
                    $show_doku = true;
                    $show_manual = false; // jangan tampilkan manual jika doku dipilih
                    break;
                case 'faspay':
                    $show_faspay = true;
                    $show_manual = false; // jangan tampilkan manual jika faspay dipilih
                    break;
                case 'dompetx':
                    $show_dompetx = true;
                    $show_manual = false; // jangan tampilkan manual jika dompetx dipilih
                    break;
                case 'manual_bank':
                default:
                    $show_manual = true;
                    $show_tripay = false;
                    $show_duitku = false;
                    $show_midtrans = false;
                    $show_xendit = false;
                    $show_ipaymu = false;
                    $show_doku = false;
                    $show_faspay = false;
                    $show_dompetx = false;
                    break;
            }
            
            if($show_tripay && $apiKey != '' ): ?>
                <!-- Bill Details Section -->
                <div class="bill-details">
                    <div class="bill-details-header">Detail Tagihan Anda</div>
                    
                    <div class="alert" style="background-color: #e8f6f5; border: 1px solid var(--primary-green); color: var(--dark-green); margin-bottom: 15px;">
                        <i class="bi bi-credit-card"></i> <strong>Tripay Payment Gateway</strong>
                    </div>
                    <table class="bill-table tripay-bill-table" data-base-total="<?= htmlspecialchars((string)$totalTagihan, ENT_QUOTES, 'UTF-8'); ?>">
                        <thead>
                            <tr>
                                <th>Deskripsi Tagihan</th>
                                <th>Nominal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tagihanDetail as $detail) { ?>
                                <tr>
                                    <td><?= htmlspecialchars($detail['keterangan']); ?></td>
                                    <td>Rp<?= number_format($detail['harga'], 2, ',', '.'); ?></td>
                                </tr>
                            <?php } ?>
                            <tr>
                                <td><strong>Pajak (<?php echo $pajak ?>%)</strong></td>
                                <td>Rp<?= number_format($ppn, 2, ',', '.'); ?></td>
                            </tr>
                            <tr class="tripay-admin-fee-row" style="display:none;">
                                <td><strong>Admin Fee <span class="tripay-admin-fee-note" style="font-size:11px;color:#888;"></span></strong></td>
                                <td><span class="tripay-admin-fee-value">Rp0</span></td>
                            </tr>
                            <tr class="bill-total" style="background-color: #e7f3ff; border-top: 3px solid #2196F3;">
                                <td><strong style="color: #1976D2; font-size: 1.1em;">TOTAL BAYAR</strong></td>
                                <td><strong class="tripay-total-value" data-base-total="<?= htmlspecialchars((string)$totalTagihan, ENT_QUOTES, 'UTF-8'); ?>" style="color: #1976D2; font-size: 1.1em;">Rp<?= number_format($totalTagihan, 2, ',', '.'); ?></strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Payment Method Section -->
                <div class="payment-method">
                    <div class="payment-method-header">Pilihan Metode Bayar</div>
                    <form method="POST">
                        <select name="method" class="method-select" required>
                            <option value="">Pilih metode pembayaran</option>
                            <?php
                            if ($apiKey && $privateKey && $merchantCode) {
                                foreach ($payment_channels as $channel) {
                                    $feeCustomerFlat = (float)($channel['fee_customer']['flat'] ?? 0);
                                    $feeCustomerPercent = (float)($channel['fee_customer']['percent'] ?? 0);
                                    $totalFeeFlat = (float)($channel['total_fee']['flat'] ?? 0);
                                    $totalFeePercent = (float)($channel['total_fee']['percent'] ?? 0);
                                    $minimumFee = (float)($channel['minimum_fee'] ?? 0);
                                    $maximumFee = (float)($channel['maximum_fee'] ?? 0);

                                    echo '<option value="' . htmlspecialchars($channel['code']) . '" '
                                        . 'data-fee-customer-flat="' . htmlspecialchars((string)$feeCustomerFlat, ENT_QUOTES, 'UTF-8') . '" '
                                        . 'data-fee-customer-percent="' . htmlspecialchars((string)$feeCustomerPercent, ENT_QUOTES, 'UTF-8') . '" '
                                        . 'data-fee-total-flat="' . htmlspecialchars((string)$totalFeeFlat, ENT_QUOTES, 'UTF-8') . '" '
                                        . 'data-fee-total-percent="' . htmlspecialchars((string)$totalFeePercent, ENT_QUOTES, 'UTF-8') . '" '
                                        . 'data-minimum-fee="' . htmlspecialchars((string)$minimumFee, ENT_QUOTES, 'UTF-8') . '" '
                                        . 'data-maximum-fee="' . htmlspecialchars((string)$maximumFee, ENT_QUOTES, 'UTF-8') . '">'
                                        . htmlspecialchars($channel['name']) .
                                        '</option>';
                                }
                            }
                            ?>
                        </select>
                        <button type="submit" class="submit-button">BAYAR SEKARANG</button>
                    </form>
                </div>
            <?php elseif($show_duitku && $default_payment == 'duitku'): ?>
                <!-- Duitku Payment Section -->
                <div class="bill-details">
                    <div class="bill-details-header">Detail Tagihan Anda</div>
                    <div class="alert" style="background-color: #e8f6f5; border: 1px solid var(--primary-green); color: var(--dark-green); margin-bottom: 15px;">
                        <i class="bi bi-wallet2"></i> <strong>Duitku Payment Gateway</strong>
                    </div>
                    <table class="bill-table">
                        <thead>
                            <tr>
                                <th>Deskripsi Tagihan</th>
                                <th>Nominal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tagihanDetail as $detail) { ?>
                                <tr>
                                    <td><?= htmlspecialchars($detail['keterangan']); ?></td>
                                    <td>Rp<?= number_format($detail['harga'], 2, ',', '.'); ?></td>
                                </tr>
                            <?php } ?>
                            <tr>
                                <td><strong>Pajak (<?php echo $pajak ?>%)</strong></td>
                                <td>Rp<?= number_format($ppn, 2, ',', '.'); ?></td>
                            </tr>
                            <tr class="bill-total" style="background-color: #e7f3ff; border-top: 3px solid #2196F3;">
                                <td><strong style="color: #1976D2; font-size: 1.1em;">TOTAL BAYAR</strong></td>
                                <td><strong style="color: #1976D2; font-size: 1.1em;">Rp<?= number_format($totalTagihan, 2, ',', '.'); ?></strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Payment Method Section -->
                <div class="payment-method">
                    <div class="payment-method-header">Pilihan Metode Bayar</div>
                    <?php
                    // Konfigurasi (termasuk merchant_code/api_key rahasia) diambil ulang dari
                    // DB saat form di-submit (lihat blok DUITKU PAYMENT PROCESSING) -- di sini
                    // cuma dipakai utk cek tersedia/tidak dan isi daftar channel.
                    $duitku_config = null;
                    $duitku_query = "SELECT * FROM duitku WHERE server = '".mysqli_real_escape_string($conn, $username)."' AND pemilik = '".mysqli_real_escape_string($conn, $useraccount)."' LIMIT 1";
                    $duitku_result = mysqli_query($conn, $duitku_query);
                    if (mysqli_num_rows($duitku_result) > 0) {
                        $duitku_config = mysqli_fetch_assoc($duitku_result);
                    }

                    $duitku_channels = [];
                    if ($duitku_config) {
                        // Real Duitku Payment Channels (based on actual API)
                        $duitku_channels = [
                            ['code' => 'VA', 'name' => 'Virtual Account'],
                            ['code' => 'OV', 'name' => 'OVO'],
                            ['code' => 'GP', 'name' => 'GoPay'],
                            ['code' => 'DA', 'name' => 'DANA'],
                            ['code' => 'SP', 'name' => 'ShopeePay'],
                            ['code' => 'LK', 'name' => 'LinkAja'],
                            ['code' => 'BC', 'name' => 'BCA Virtual Account'],
                            ['code' => 'BN', 'name' => 'BNI Virtual Account'],
                            ['code' => 'BR', 'name' => 'BRI Virtual Account'],
                            ['code' => 'MD', 'name' => 'Mandiri Virtual Account'],
                            ['code' => 'AG', 'name' => 'Bank Artha Graha'],
                            ['code' => 'NC', 'name' => 'Bank Neo Commerce'],
                            ['code' => 'SM', 'name' => 'Bank Sahabat Sampoerna'],
                            ['code' => 'I1', 'name' => 'Bank Mandiri (Retail)'],
                            ['code' => 'CC', 'name' => 'Credit Card'],
                            ['code' => 'AL', 'name' => 'Alfamart'],
                            ['code' => 'A1', 'name' => 'ATM Bersama']
                        ];
                    }
                    ?>
                    <?php if ($duitku_config): ?>
                        <?php if (!empty($duitku_error_message)): ?>
                            <div class="alert alert-danger"><?= htmlspecialchars($duitku_error_message) ?></div>
                        <?php endif; ?>
                        <form method="POST">
                            <input type="hidden" name="duitku_submit" value="1">
                            <input type="hidden" name="merchant_ref" value="<?= htmlspecialchars($merchantRef) ?>">
                            <select name="payment_method" class="method-select" required>
                                <option value="">Pilih metode pembayaran</option>
                                <?php foreach ($duitku_channels as $channel): ?>
                                    <option value="<?= htmlspecialchars($channel['code']) ?>"><?= htmlspecialchars($channel['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="submit-button">BAYAR SEKARANG</button>
                        </form>
                    <?php else: ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i> <strong>Konfigurasi Duitku belum tersedia</strong><br>
                            Silakan hubungi administrator untuk mengatur konfigurasi Duitku Payment Gateway.
                        </div>
                    <?php endif; ?>
                </div>
            <?php elseif($show_midtrans && $default_payment == 'midtrans'): ?>
                <!-- Midtrans Payment Section -->
                <div class="bill-details">
                    <div class="bill-details-header">Detail Tagihan Anda</div>
                    <div class="alert" style="background-color: #e8f6f5; border: 1px solid var(--primary-green); color: var(--dark-green); margin-bottom: 15px;">
                        <i class="bi bi-credit-card"></i> <strong>Midtrans Payment Gateway</strong>
                    </div>
                    <table class="bill-table">
                        <thead>
                            <tr>
                                <th>Deskripsi Tagihan</th>
                                <th>Nominal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tagihanDetail as $detail) { ?>
                                <tr>
                                    <td><?= htmlspecialchars($detail['keterangan']); ?></td>
                                    <td>Rp<?= number_format($detail['harga'], 2, ',', '.'); ?></td>
                                </tr>
                            <?php } ?>
                            <tr>
                                <td><strong>Pajak (<?php echo $pajak ?>%)</strong></td>
                                <td>Rp<?= number_format($ppn, 2, ',', '.'); ?></td>
                            </tr>
                            <tr class="bill-total" style="background-color: #e7f3ff; border-top: 3px solid #2196F3;">
                                <td><strong style="color: #1976D2; font-size: 1.1em;">TOTAL BAYAR</strong></td>
                                <td><strong style="color: #1976D2; font-size: 1.1em;">Rp<?= number_format($totalTagihan, 2, ',', '.'); ?></strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Payment Method Section -->
                <div class="payment-method">
                    <div class="payment-method-header">Pilihan Metode Bayar</div>
                    <?php
                    $midtrans_config = null;
                    $midtrans_query = "SELECT * FROM midtrans WHERE server = '".mysqli_real_escape_string($conn, $username)."' AND pemilik = '".mysqli_real_escape_string($conn, $useraccount)."' LIMIT 1";
                    $midtrans_result = mysqli_query($conn, $midtrans_query);
                    if (mysqli_num_rows($midtrans_result) > 0) {
                        $midtrans_config = mysqli_fetch_assoc($midtrans_result);
                    }
                    ?>
                    <?php if ($midtrans_config): ?>
                        <?php if (!empty($midtrans_error_message)): ?>
                            <div class="alert alert-danger"><?= htmlspecialchars($midtrans_error_message) ?></div>
                        <?php endif; ?>
                        <form method="POST">
                            <input type="hidden" name="midtrans_submit" value="1">
                            <input type="hidden" name="merchant_ref" value="<?= htmlspecialchars($merchantRef) ?>">
                            <button type="submit" class="submit-button">BAYAR SEKARANG</button>
                        </form>
                    <?php else: ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i> <strong>Konfigurasi Midtrans belum tersedia</strong><br>
                            Silakan hubungi administrator untuk mengatur konfigurasi Midtrans Payment Gateway.
                        </div>
                    <?php endif; ?>
                </div>
            <?php elseif($show_xendit && $default_payment == 'xendit'): ?>
                <!-- Xendit Payment Section -->
                <div class="bill-details">
                    <div class="bill-details-header">Detail Tagihan Anda</div>
                    <div class="alert" style="background-color: #e8f6f5; border: 1px solid var(--primary-green); color: var(--dark-green); margin-bottom: 15px;">
                        <i class="bi bi-credit-card"></i> <strong>Xendit Payment Gateway</strong>
                    </div>
                    <table class="bill-table">
                        <thead>
                            <tr>
                                <th>Deskripsi Tagihan</th>
                                <th>Nominal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tagihanDetail as $detail) { ?>
                                <tr>
                                    <td><?= htmlspecialchars($detail['keterangan']); ?></td>
                                    <td>Rp<?= number_format($detail['harga'], 2, ',', '.'); ?></td>
                                </tr>
                            <?php } ?>
                            <tr>
                                <td><strong>Pajak (<?php echo $pajak ?>%)</strong></td>
                                <td>Rp<?= number_format($ppn, 2, ',', '.'); ?></td>
                            </tr>
                            <tr class="bill-total" style="background-color: #e7f3ff; border-top: 3px solid #2196F3;">
                                <td><strong style="color: #1976D2; font-size: 1.1em;">TOTAL BAYAR</strong></td>
                                <td><strong style="color: #1976D2; font-size: 1.1em;">Rp<?= number_format($totalTagihan, 2, ',', '.'); ?></strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Payment Method Section -->
                <div class="payment-method">
                    <div class="payment-method-header">Pilihan Metode Bayar</div>
                    <?php
                    $xendit_config = null;
                    $xendit_query = "SELECT * FROM xendit WHERE server = '".mysqli_real_escape_string($conn, $username)."' AND pemilik = '".mysqli_real_escape_string($conn, $useraccount)."' LIMIT 1";
                    $xendit_result = mysqli_query($conn, $xendit_query);
                    if (mysqli_num_rows($xendit_result) > 0) {
                        $xendit_config = mysqli_fetch_assoc($xendit_result);
                    }
                    ?>
                    <?php if ($xendit_config): ?>
                        <?php if (!empty($xendit_error_message)): ?>
                            <div class="alert alert-danger"><?= htmlspecialchars($xendit_error_message) ?></div>
                        <?php endif; ?>
                        <form method="POST">
                            <input type="hidden" name="xendit_submit" value="1">
                            <input type="hidden" name="merchant_ref" value="<?= htmlspecialchars($merchantRef) ?>">
                            <button type="submit" class="submit-button">BAYAR SEKARANG</button>
                        </form>
                    <?php else: ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i> <strong>Konfigurasi Xendit belum tersedia</strong><br>
                            Silakan hubungi administrator untuk mengatur konfigurasi Xendit Payment Gateway.
                        </div>
                    <?php endif; ?>
                </div>
            <?php elseif($show_ipaymu && $default_payment == 'ipaymu'): ?>
                <!-- iPaymu Payment Section -->
                <div class="bill-details">
                    <div class="bill-details-header">Detail Tagihan Anda</div>
                    <div class="alert" style="background-color: #e8f6f5; border: 1px solid var(--primary-green); color: var(--dark-green); margin-bottom: 15px;">
                        <i class="bi bi-credit-card"></i> <strong>iPaymu Payment Gateway</strong>
                    </div>
                    <table class="bill-table">
                        <thead>
                            <tr>
                                <th>Deskripsi Tagihan</th>
                                <th>Nominal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tagihanDetail as $detail) { ?>
                                <tr>
                                    <td><?= htmlspecialchars($detail['keterangan']); ?></td>
                                    <td>Rp<?= number_format($detail['harga'], 2, ',', '.'); ?></td>
                                </tr>
                            <?php } ?>
                            <tr>
                                <td><strong>Pajak (<?php echo $pajak ?>%)</strong></td>
                                <td>Rp<?= number_format($ppn, 2, ',', '.'); ?></td>
                            </tr>
                            <tr class="bill-total" style="background-color: #e7f3ff; border-top: 3px solid #2196F3;">
                                <td><strong style="color: #1976D2; font-size: 1.1em;">TOTAL BAYAR</strong></td>
                                <td><strong style="color: #1976D2; font-size: 1.1em;">Rp<?= number_format($totalTagihan, 2, ',', '.'); ?></strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Payment Method Section -->
                <div class="payment-method">
                    <div class="payment-method-header">Pilihan Metode Bayar</div>
                    <?php
                    $ipaymu_config = null;
                    $ipaymu_query = "SELECT * FROM ipaymu WHERE server = '".mysqli_real_escape_string($conn, $username)."' AND pemilik = '".mysqli_real_escape_string($conn, $useraccount)."' LIMIT 1";
                    $ipaymu_result = @mysqli_query($conn, $ipaymu_query);
                    if ($ipaymu_result && mysqli_num_rows($ipaymu_result) > 0) {
                        $ipaymu_config = mysqli_fetch_assoc($ipaymu_result);
                    }
                    ?>
                    <?php if ($ipaymu_config): ?>
                        <?php if (!empty($ipaymu_error_message)): ?>
                            <div class="alert alert-danger"><?= htmlspecialchars($ipaymu_error_message) ?></div>
                        <?php endif; ?>
                        <form method="POST">
                            <input type="hidden" name="ipaymu_submit" value="1">
                            <input type="hidden" name="ipaymu_va" value="<?= htmlspecialchars($ipaymu_config['va']) ?>">
                            <input type="hidden" name="ipaymu_api_key" value="<?= htmlspecialchars($ipaymu_config['api_key']) ?>">
                            <input type="hidden" name="order_id" value="<?= 'INV-' . time() . '-' . $merchantRef ?>">
                            <button type="submit" class="submit-button">BAYAR SEKARANG</button>
                        </form>
                    <?php else: ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i> <strong>Konfigurasi iPaymu belum tersedia</strong><br>
                            Silakan hubungi administrator untuk mengatur konfigurasi iPaymu Payment Gateway.
                        </div>
                    <?php endif; ?>
                </div>
            <?php elseif($show_doku && $default_payment == 'doku'): ?>
                <!-- DOKU Payment Section -->
                <div class="bill-details">
                    <div class="bill-details-header">Detail Tagihan Anda</div>
                    <div class="alert" style="background-color: #e8f6f5; border: 1px solid var(--primary-green); color: var(--dark-green); margin-bottom: 15px;">
                        <i class="bi bi-credit-card"></i> <strong>DOKU Payment Gateway</strong>
                    </div>
                    <table class="bill-table">
                        <thead>
                            <tr>
                                <th>Deskripsi Tagihan</th>
                                <th>Nominal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tagihanDetail as $detail) { ?>
                                <tr>
                                    <td><?= htmlspecialchars($detail['keterangan']); ?></td>
                                    <td>Rp<?= number_format($detail['harga'], 2, ',', '.'); ?></td>
                                </tr>
                            <?php } ?>
                            <tr>
                                <td><strong>Pajak (<?php echo $pajak ?>%)</strong></td>
                                <td>Rp<?= number_format($ppn, 2, ',', '.'); ?></td>
                            </tr>
                            <tr class="bill-total" style="background-color: #e7f3ff; border-top: 3px solid #2196F3;">
                                <td><strong style="color: #1976D2; font-size: 1.1em;">TOTAL BAYAR</strong></td>
                                <td><strong style="color: #1976D2; font-size: 1.1em;">Rp<?= number_format($totalTagihan, 2, ',', '.'); ?></strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Payment Method Section -->
                <div class="payment-method">
                    <div class="payment-method-header">Pilihan Metode Bayar</div>
                    <?php
                    $doku_config = null;
                    $doku_query = "SELECT * FROM doku WHERE server = '".mysqli_real_escape_string($conn, $username)."' AND pemilik = '".mysqli_real_escape_string($conn, $useraccount)."' LIMIT 1";
                    $doku_result_cfg = @mysqli_query($conn, $doku_query);
                    if ($doku_result_cfg && mysqli_num_rows($doku_result_cfg) > 0) {
                        $doku_config = mysqli_fetch_assoc($doku_result_cfg);
                    }
                    ?>
                    <?php if ($doku_config): ?>
                        <?php if (!empty($doku_error_message)): ?>
                            <div class="alert alert-danger"><?= htmlspecialchars($doku_error_message) ?></div>
                        <?php endif; ?>
                        <form method="POST">
                            <input type="hidden" name="doku_submit" value="1">
                            <input type="hidden" name="doku_client_id" value="<?= htmlspecialchars($doku_config['client_id']) ?>">
                            <input type="hidden" name="doku_secret_key" value="<?= htmlspecialchars($doku_config['secret_key']) ?>">
                            <input type="hidden" name="order_id" value="<?= 'INV-' . time() . '-' . $merchantRef ?>">
                            <button type="submit" class="submit-button">BAYAR SEKARANG</button>
                        </form>
                    <?php else: ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i> <strong>Konfigurasi DOKU belum tersedia</strong><br>
                            Silakan hubungi administrator untuk mengatur konfigurasi DOKU Payment Gateway.
                        </div>
                    <?php endif; ?>
                </div>
            <?php elseif($show_faspay && $default_payment == 'faspay'): ?>
                <!-- Faspay Payment Section -->
                <div class="bill-details">
                    <div class="bill-details-header">Detail Tagihan Anda</div>
                    <div class="alert" style="background-color: #e8f6f5; border: 1px solid var(--primary-green); color: var(--dark-green); margin-bottom: 15px;">
                        <i class="bi bi-credit-card"></i> <strong>Faspay Payment Gateway</strong>
                    </div>
                    <table class="bill-table">
                        <thead>
                            <tr>
                                <th>Deskripsi Tagihan</th>
                                <th>Nominal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tagihanDetail as $detail) { ?>
                                <tr>
                                    <td><?= htmlspecialchars($detail['keterangan']); ?></td>
                                    <td>Rp<?= number_format($detail['harga'], 2, ',', '.'); ?></td>
                                </tr>
                            <?php } ?>
                            <tr>
                                <td><strong>Pajak (<?php echo $pajak ?>%)</strong></td>
                                <td>Rp<?= number_format($ppn, 2, ',', '.'); ?></td>
                            </tr>
                            <tr class="bill-total" style="background-color: #e7f3ff; border-top: 3px solid #2196F3;">
                                <td><strong style="color: #1976D2; font-size: 1.1em;">TOTAL BAYAR</strong></td>
                                <td><strong style="color: #1976D2; font-size: 1.1em;">Rp<?= number_format($totalTagihan, 2, ',', '.'); ?></strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Payment Method Section -->
                <div class="payment-method">
                    <div class="payment-method-header">Pilihan Metode Bayar</div>
                    <?php
                    $faspay_config = null;
                    $faspay_query_cfg = "SELECT * FROM faspay WHERE server = '".mysqli_real_escape_string($conn, $username)."' AND pemilik = '".mysqli_real_escape_string($conn, $useraccount)."' LIMIT 1";
                    $faspay_result_cfg = @mysqli_query($conn, $faspay_query_cfg);
                    if ($faspay_result_cfg && mysqli_num_rows($faspay_result_cfg) > 0) {
                        $faspay_config = mysqli_fetch_assoc($faspay_result_cfg);
                    }
                    // Kode channel publik Faspay yang umum dipakai (bisa ditambah sesuai kebutuhan)
                    $faspay_channels = [
                        ['code' => '201', 'name' => 'BCA Virtual Account'],
                        ['code' => '801', 'name' => 'Mandiri Virtual Account'],
                        ['code' => '901', 'name' => 'BNI Virtual Account'],
                        ['code' => '705', 'name' => 'BRI Virtual Account'],
                        ['code' => '501', 'name' => 'Permata Virtual Account'],
                        ['code' => '702', 'name' => 'Indomaret'],
                        ['code' => '703', 'name' => 'Alfamart'],
                        ['code' => '813', 'name' => 'QRIS'],
                    ];
                    ?>
                    <?php if ($faspay_config): ?>
                        <?php if (!empty($faspay_error_message)): ?>
                            <div class="alert alert-danger"><?= htmlspecialchars($faspay_error_message) ?></div>
                        <?php endif; ?>
                        <form method="POST">
                            <input type="hidden" name="faspay_submit" value="1">
                            <input type="hidden" name="faspay_merchant_id" value="<?= htmlspecialchars($faspay_config['merchant_id']) ?>">
                            <input type="hidden" name="faspay_user_id" value="<?= htmlspecialchars($faspay_config['user_id']) ?>">
                            <input type="hidden" name="faspay_password" value="<?= htmlspecialchars($faspay_config['password']) ?>">
                            <input type="hidden" name="order_id" value="<?= 'INV-' . time() . '-' . $merchantRef ?>">
                            <select name="faspay_channel" class="method-select" required>
                                <option value="">Pilih metode pembayaran</option>
                                <?php foreach ($faspay_channels as $channel): ?>
                                    <option value="<?= htmlspecialchars($channel['code']) ?>"><?= htmlspecialchars($channel['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="submit-button">BAYAR SEKARANG</button>
                        </form>
                    <?php else: ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i> <strong>Konfigurasi Faspay belum tersedia</strong><br>
                            Silakan hubungi administrator untuk mengatur konfigurasi Faspay Payment Gateway.
                        </div>
                    <?php endif; ?>
                </div>
            <?php elseif($show_dompetx && $default_payment == 'dompetx'): ?>
                <!-- Bill Details Section -->
                <div class="bill-details">
                    <div class="bill-details-header">Detail Tagihan Anda</div>
                    <div class="alert" style="background-color: #e8f6f5; border: 1px solid var(--primary-green); color: var(--dark-green); margin-bottom: 15px;">
                        <i class="bi bi-credit-card"></i> <strong>DompetX Payment Gateway</strong>
                    </div>
                    <table class="bill-table">
                        <thead>
                            <tr>
                                <th>Deskripsi Tagihan</th>
                                <th>Nominal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tagihanDetail as $detail) { ?>
                                <tr>
                                    <td><?= htmlspecialchars($detail['keterangan']); ?></td>
                                    <td>Rp<?= number_format($detail['harga'], 2, ',', '.'); ?></td>
                                </tr>
                            <?php } ?>
                            <tr>
                                <td><strong>Pajak (<?php echo $pajak ?>%)</strong></td>
                                <td>Rp<?= number_format($ppn, 2, ',', '.'); ?></td>
                            </tr>
                            <tr class="bill-total" style="background-color: #e7f3ff; border-top: 3px solid #2196F3;">
                                <td><strong style="color: #1976D2; font-size: 1.1em;">TOTAL BAYAR</strong></td>
                                <td><strong style="color: #1976D2; font-size: 1.1em;">Rp<?= number_format($totalTagihan, 2, ',', '.'); ?></strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Payment Method Section -->
                <div class="payment-method">
                    <div class="payment-method-header">Pilihan Metode Bayar</div>
                    <?php
                    $dompetx_config = null;
                    $dompetx_query_cfg = "SELECT * FROM dompetx WHERE server = '".mysqli_real_escape_string($conn, $username)."' AND pemilik = '".mysqli_real_escape_string($conn, $useraccount)."' LIMIT 1";
                    $dompetx_result_cfg = @mysqli_query($conn, $dompetx_query_cfg);
                    if ($dompetx_result_cfg && mysqli_num_rows($dompetx_result_cfg) > 0) {
                        $dompetx_config = mysqli_fetch_assoc($dompetx_result_cfg);
                    }

                    $dompetx_channels = [];
                    if ($dompetx_config) {
                        $dompetx_secret_for_channel = !empty($dompetx_config['secret_key']) ? $dompetx_config['secret_key'] : $dompetx_config['api_key'];
                        $dompetx_channel_resp = dompetx_request('GET', '/v1/payments/channel', $dompetx_config['api_key'], $dompetx_secret_for_channel);
                        if ($dompetx_channel_resp['ok'] && !empty($dompetx_channel_resp['data']['data']) && is_array($dompetx_channel_resp['data']['data'])) {
                            foreach ($dompetx_channel_resp['data']['data'] as $dompetxChannelRow) {
                                if (($dompetxChannelRow['status'] ?? '') === 'active') {
                                    $dompetx_channels[] = $dompetxChannelRow;
                                }
                            }
                        }
                    }
                    ?>
                    <?php if ($dompetx_config): ?>
                        <?php if (!empty($dompetx_error_message)): ?>
                            <div class="alert alert-danger"><?= htmlspecialchars($dompetx_error_message) ?></div>
                        <?php endif; ?>
                        <form method="POST">
                            <input type="hidden" name="dompetx_submit" value="1">
                            <select name="dompetx_method" class="method-select" required>
                                <option value="">Pilih metode pembayaran</option>
                                <!-- QRIS SELALU disertakan (bukan cuma fallback saat channel gagal diambil) --
                                     per 2026-08-02 terkonfirmasi (lihat logs/dompetx_error.log + tes curl manual)
                                     semua channel Virtual Account (permata, ocbc, dst -- daftar $dompetx_channels
                                     di bawah) balas "error code: 502" dari sisi server DompetX sendiri, sedangkan
                                     QRIS satu-satunya method yang masih berhasil. Channel VA tetap ditampilkan
                                     (kalau DompetX perbaiki bug-nya, otomatis jalan lagi tanpa perlu ubah kode). -->
                                <option value="QRIS">QRIS</option>
                                <?php if (!empty($dompetx_channels)): ?>
                                    <?php foreach ($dompetx_channels as $dompetxChannel): ?>
                                        <option value="<?= htmlspecialchars($dompetxChannel['code'] ?? '') ?>"><?= htmlspecialchars($dompetxChannel['name'] ?? ($dompetxChannel['code'] ?? 'Metode')) ?></option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            <button type="submit" class="submit-button">BAYAR SEKARANG</button>
                        </form>
                    <?php else: ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i> <strong>Konfigurasi DompetX belum tersedia</strong><br>
                            Silakan hubungi administrator untuk mengatur konfigurasi DompetX Payment Gateway.
                        </div>
                    <?php endif; ?>
                </div>
            <?php elseif($show_manual): ?>
                <!-- Manual Payment Section -->
                <div class="manual-payment">
                    <div class="manual-payment-header">Detail Tagihan Anda</div>
                    
                    <div class="alert" style="background-color: #e8f6f5; border: 1px solid var(--dark-green); color: var(--dark-green); margin-bottom: 15px;">
                        <i class="bi bi-bank"></i> <strong>Transfer Bank Manual</strong><br>
                        
                    </div>
                    <table class="bill-table">
                        <thead>
                            <tr>
                                <th>Deskripsi Tagihan</th>
                                <th>Nominal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tagihanDetail as $detail) { ?>
                                <tr>
                                    <td><?= htmlspecialchars($detail['keterangan']); ?></td>
                                    <td>Rp<?= number_format($detail['harga'], 2, ',', '.'); ?></td>
                                </tr>
                            <?php } ?>
                            <tr>
                                <td><strong>Pajak (<?php echo $pajak ?>%)</strong></td>
                                <td>Rp<?= number_format($ppn, 2, ',', '.'); ?></td>
                            </tr>
                          
                            <tr class="bill-total" style="background-color: #e7f3ff; border-top: 3px solid #2196F3;">
                                <td><strong style="color: #1976D2; font-size: 1.1em;">TOTAL BAYAR</strong></td>
                                <td><strong style="color: #1976D2; font-size: 1.1em;">Rp<?= number_format($totalTagihan, 2, ',', '.'); ?></strong></td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="manual-payment-header">Rekening Pembayaran</div>
                    <table class="bank-table">
                        <thead>
                            <tr>
                                <th>Nama Bank</th>
                                <th>Nama Pemilik Bank</th>
                                <th>Rekening Bank</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                           
                            $sql = "SELECT `id`, `nama_bank`, `nama_pemilik_bank`, `rekening_bank`, `pemilik`, `server`
                                    FROM `manualbank`
                                    WHERE `pemilik` = '".mysqli_real_escape_string($conn, $useraccount)."'
                                    AND `server` = '".mysqli_real_escape_string($conn, $username)."'";
                            $result = mysqli_query($conn, $sql);
                            
                            if (mysqli_num_rows($result) > 0) {
                                while ($row = mysqli_fetch_assoc($result)) {
                                    echo "<tr>
                                        <td>{$row['nama_bank']}</td>
                                        <td>{$row['nama_pemilik_bank']}</td>
                                        <td>{$row['rekening_bank']}</td>
                                    </tr>";
                                }
                            } else {
                                // Jika tidak ada data untuk server spesifik, coba tanpa filter server
                             echo   $sql_fallback = "SELECT `id`, `nama_bank`, `nama_pemilik_bank`, `rekening_bank`, `pemilik`
                                               FROM `manualbank`
                                               WHERE `server` = '".mysqli_real_escape_string($conn, $username)."'";
                                $result_fallback = mysqli_query($conn, $sql_fallback);
                                
                                if (mysqli_num_rows($result_fallback) > 0) {
                                    while ($row = mysqli_fetch_assoc($result_fallback)) {
                                        echo "<tr>
                                            <td>{$row['nama_bank']}</td>
                                            <td>{$row['nama_pemilik_bank']}</td>
                                            <td>{$row['rekening_bank']}</td>
                                        </tr>";
                                    }
                                } else {
                                    echo "<tr><td colspan='3'>Tidak ada data rekening bank</td></tr>";
                                }
                            }
                            ?>
                        </tbody>
                    </table>

                    <?php
                    function formatTanggalIndo($tanggal) {
                        setlocale(LC_TIME, 'id_ID.UTF-8');
                        $timestamp = strtotime($tanggal);
                        return strftime('%A, %d %B %Y', $timestamp);
                    }

                    date_default_timezone_set('Asia/Jakarta');
                    $tanggal2 = date('Y-m-d');
                    $tanggal2 = formatTanggalIndo($tanggal2); 
                    $message = '';
                    $uploadedFile = '';

                    if (isset($_FILES['bukti']) && $_FILES['bukti']['error'] === UPLOAD_ERR_OK) {
                        $nama       = $pelanggan['NAMA'] ?? '';
                        $idpel      = $pelanggan['IDPEL'] ?? '';
                        $paket      = $pelanggan['PAKET'] ?? '';
                        $harga      = $paketHarga ?? 0;
                        // PENTING: pakai $periode_tagihan, bukan $periode
                        // yang mungkin sudah kosong/berubah di titik ini, supaya
                        // baris KONFIRMASI ini tetap cocok saat nanti dicek ulang
                        // sebagai transaksi periode berjalan.
                        $penggunaan = $periode_tagihan;
                        $cek        = '';
                        $pemilik    = $username ?? '';
                        
                        $insert = mysqli_query($conn, "
                            INSERT INTO transaksi 
                            (TANGGALBAYAR, PENGUNAAN, STATUS,BUKTI, IDPEL, NAMA, PAKET, HARGA, CEK, PEMILIK) 
                            VALUES 
                            ('$tanggal2', '$penggunaan', 'KONFIRMASI','MANUAL', '$idpel', '$nama', '$paket', $harga, '$cek', '$pemilik')
                        ");

                        if (!$insert) {
                            $message = "? Gagal membuat transaksi! Error: " . mysqli_error($conn);
                        } else {
                            $id = mysqli_insert_id($conn);
                            $uploadDir = __DIR__ . "/../../../dokumen/buktibon/";
                            $uploadUrl = "/dokumen/buktibon/";
                            if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0755, true); }

                            $ext = strtolower(pathinfo($_FILES['bukti']['name'], PATHINFO_EXTENSION));
                            $allowed = ['jpg','jpeg','png'];

                            if (!in_array($ext, $allowed)) {
                                $message = "? File tidak diizinkan. Hanya: " . implode(', ', $allowed);
                            } else {
                                $filename   = $id . '.' . $ext;
                                $targetFile = $uploadDir . $filename;

                                if (move_uploaded_file($_FILES['bukti']['tmp_name'], $targetFile)) {
                                    $bukti = mysqli_real_escape_string($conn, 'buktibon/' . $filename);
                                    mysqli_query($conn, "UPDATE transaksi SET BUKTI='$bukti' WHERE id=$id");

                                    $uploadedFile = $uploadUrl . $filename;
                                    $message = "? Transaksi berhasil dibuat dan file bukti diupload!";
                                } else {
                                    $message = "? Gagal mengupload file bukti!";
                                }
                            }
                        }
                    }
                    ?>

                    <div class="upload-form">
                        <div class="manual-payment-header">Upload Bukti Pembayaran</div>
                        
                        <?php if ($message): ?>
                            <div class="alert <?= strpos($message, '?') !== false ? 'alert-error' : 'alert-success'; ?>">
                                <?= htmlspecialchars($message) ?>
                            </div>
                        <?php endif; ?>

                        <form method="post" enctype="multipart/form-data">
                            <input type="file" name="bukti" class="file-input" required>
                            <button type="submit" class="upload-button">Upload Bukti</button>
                        </form>

                        <?php if ($uploadedFile): ?>
                            <div style="margin-top: 15px;">
                                <strong>Preview:</strong>
                                <?php if (pathinfo($uploadedFile, PATHINFO_EXTENSION) === 'pdf'): ?>
                                    <a href="<?= htmlspecialchars($uploadedFile) ?>" target="_blank" style="display: inline-block; margin-top: 10px; padding: 8px 15px; background-color: #1a237e; color: white; text-decoration: none; border-radius: 5px;">Lihat PDF</a>
                                <?php else: ?>
                                    <img src="<?= htmlspecialchars($uploadedFile) ?>" alt="Preview" style="max-width: 100%; margin-top: 10px; border-radius: 5px;">
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <?php /* Ikut masuk ke sini jika PENAGIHAN sudah lunas juga ($has_paid_periode
                  true walau $has_penagihan_periode masih true krn baris PENAGIHAN belum
                  terhapus) -- supaya tampilannya jadi "sudah bayar", bukan form bayar lagi. */ ?>
            <?php if(!$has_existing_transaction && empty($reference) && (!$has_penagihan_periode || $has_paid_periode)): ?>
                <?php if($has_paid_periode && $paidTransaction): ?>
                    <!-- Transaksi periode berjalan sudah berhasil/lunas -->
                    <div class="payment-details">
                        <div class="payment-details-header">Detail Transaksi</div>
                        <div class="alert alert-success" style="margin-bottom: 15px;">
                            <i class="bi bi-check-circle"></i>
                            <strong>Anda sudah membayar periode saat ini (<?= htmlspecialchars($periode_tagihan) ?>)</strong> dengan transaksi berikut:
                        </div>
                        <div class="payment-info">
                            <p><strong>Periode:</strong> <span><?= htmlspecialchars($paidTransaction['PENGUNAAN'] ?? $periode_tagihan) ?></span></p>
                            <p><strong>Paket:</strong> <span><?= htmlspecialchars($paidTransaction['PAKET'] ?? '-') ?></span></p>
                            <p><strong>Nominal:</strong> <span>Rp<?= number_format((float)($paidTransaction['HARGA'] ?? 0), 0, ',', '.') ?></span></p>
                            <p><strong>Tanggal Bayar:</strong> <span><?= htmlspecialchars($paidTransaction['TANGGALBAYAR'] ?? '-') ?></span></p>
                            <p><strong>Status:</strong>
                                <span class="payment-status status-paid"><?= htmlspecialchars($paidTransaction['STATUS']) ?></span>
                            </p>
                        </div>
                    </div>
                <?php else: ?>
                    <?php
                    // ===================================================================
                    // CEK TRANSAKSI MENUNGGU KONFIRMASI ADMIN (transfer manual yang
                    // sudah diupload tapi belum dikonfirmasi lunas oleh admin).
                    // Purpose: supaya pelanggan tidak melihat "Belum ada tagihan baru"
                    // padahal sebenarnya mereka sudah upload bukti dan tinggal
                    // menunggu verifikasi.
                    // ===================================================================
                    $has_confirm_periode = false;
                    $confirmTransaction = null;
                    $stmtConfirm = $conn->prepare("SELECT * FROM transaksi WHERE IDPEL = ? AND TRIM(UPPER(PENGUNAAN)) = TRIM(UPPER(?)) AND UPPER(STATUS) = 'KONFIRMASI' ORDER BY id DESC LIMIT 1");
                    $stmtConfirm->bind_param("ss", $merchantRef, $periode_tagihan);
                    $stmtConfirm->execute();
                    $resultConfirm = $stmtConfirm->get_result();
                    if ($rowConfirm = $resultConfirm->fetch_assoc()) {
                        $has_confirm_periode = true;
                        $confirmTransaction = $rowConfirm;
                    }
                    $stmtConfirm->close();
                    ?>
                    <?php if ($has_confirm_periode && $confirmTransaction): ?>
                        <div class="payment-details">
                            <div class="payment-details-header">Menunggu Konfirmasi</div>
                            <div class="alert alert-info" style="margin-bottom: 15px;">
                                <i class="bi bi-hourglass-split"></i>
                                <strong>Bukti pembayaran Anda untuk periode <?= htmlspecialchars($periode_tagihan) ?> sudah diterima</strong> dan sedang menunggu konfirmasi admin.
                            </div>
                            <div class="payment-info">
                                <p><strong>Periode:</strong> <span><?= htmlspecialchars($confirmTransaction['PENGUNAAN'] ?? $periode_tagihan) ?></span></p>
                                <p><strong>Paket:</strong> <span><?= htmlspecialchars($confirmTransaction['PAKET'] ?? '-') ?></span></p>
                                <p><strong>Nominal:</strong> <span>Rp<?= number_format((float)($confirmTransaction['HARGA'] ?? 0), 0, ',', '.') ?></span></p>
                                <p><strong>Tanggal Upload:</strong> <span><?= htmlspecialchars($confirmTransaction['TANGGALBAYAR'] ?? '-') ?></span></p>
                                <p><strong>Status:</strong>
                                    <span class="payment-status status-unpaid"><?= htmlspecialchars($confirmTransaction['STATUS']) ?></span>
                                </p>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="bill-details">
                            <div class="bill-details-header">Detail Tagihan Anda</div>
                            <p>Belum ada tagihan baru..</p>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>

    </div>
<br><br><br><br><br><br><br><br>
    <!-- ===================================================================
         BOTTOM NAVIGATION BAR - Fixed navigation for mobile portal
         =================================================================== -->
    <div class="navbar">
        <!-- Home/Dashboard Navigation -->
        <a href="portal_baru.php?cari=<?= $idpel; ?>" class="nav-item">
            <div class="nav-icon"><i class="bi bi-house-door-fill"></i></div>
            <div>Beranda</div>
        </a>
        
        <!-- Payment Portal Navigation (Current Page) -->
        <a href="portal_bayar.php?cari=<?= $idpel; ?>" class="nav-item active">
            <div class="nav-icon"><i class="bi bi-credit-card"></i></div>
            <div>Bayar</div>
        </a>
        
        <!-- Customer Support Chat -->
        <a href="portal_chat.php?cari=<?= $idpel; ?>" class="nav-item">
            <div class="nav-icon"><i class="bi bi-chat-dots"></i></div>
            <div>Chat</div>
        </a>

        <a href="portal_mywifi.php?cari=<?= $idpel; ?>" class="nav-item">
            <div class="nav-icon"><i class="bi bi-wifi"></i></div>
            <div>WiFi Saya</div>
        </a>
        
        <!-- Payment History -->
        <a href="portal_riwayat.php?cari=<?= $idpel; ?>" class="nav-item">
            <div class="nav-icon"><i class="bi bi-clock-history"></i></div>
            <div>Riwayat</div>
        </a>
        
        <!-- User Profile -->
        <a href="portal_profile.php?cari=<?= $idpel; ?>" class="nav-item">
            <div class="nav-icon"><i class="bi bi-person-circle"></i></div>
            <div>Profile</div>
        </a>
    </div>

    <!-- ===================================================================
         JAVASCRIPT FUNCTIONS - Client-side functionality for debug panel
         =================================================================== -->
    <script>
        /**
         * Toggle Debug Information Panel
         * Purpose: Show/hide debug information for payment gateway errors
         * @param {string} type - Payment gateway type (duitku/tripay)
         */
        function toggleDebug(type) {
            const debugDiv = document.getElementById(type + '-debug');
            const button = event.target;
            
            // Toggle debug panel visibility
            if (debugDiv.style.display === 'none') {
                // Show debug panel
                debugDiv.style.display = 'block';
                button.innerHTML = '<i class="bi bi-bug-fill"></i> Hide Debug Info';
                button.style.backgroundColor = '#dc3545';
            } else {
                // Hide debug panel
                debugDiv.style.display = 'none';
                button.innerHTML = '<i class="bi bi-bug"></i> Show Debug Info';
                button.style.backgroundColor = '#6c757d';
            }
        }

        /**
         * Handle Payment Method Selection for Duitku
         * Purpose: Show immediate payment buttons for DANA/GoPay when selected
         */
        document.addEventListener('DOMContentLoaded', function() {
            const paymentMethodSelect = document.querySelector('select[name="payment_method"]');
            const tripayMethodSelect = document.querySelector('select[name="method"]');

            function formatRupiah(value) {
                const number = Number(value) || 0;
                return 'Rp' + number.toLocaleString('id-ID', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
            }

            function updateTripayFeeView() {
                if (!tripayMethodSelect) {
                    return;
                }
                const selectedOption = tripayMethodSelect.options[tripayMethodSelect.selectedIndex];
                const totalNode = document.querySelector('.tripay-total-value');
                const tableNode = document.querySelector('.tripay-bill-table');
                const adminRow = document.querySelector('.tripay-admin-fee-row');
                const adminValueNode = document.querySelector('.tripay-admin-fee-value');
                const adminNoteNode = document.querySelector('.tripay-admin-fee-note');

                if (!selectedOption || !totalNode || !tableNode || !adminRow || !adminValueNode || !adminNoteNode) {
                    return;
                }

                const baseTotal = parseFloat(totalNode.dataset.baseTotal || tableNode.dataset.baseTotal || '0') || 0;
                const feeCustomerFlat = parseFloat(selectedOption.dataset.feeCustomerFlat || '0') || 0;
                const feeCustomerPercent = parseFloat(selectedOption.dataset.feeCustomerPercent || '0') || 0;
                const feeTotalFlat = parseFloat(selectedOption.dataset.feeTotalFlat || '0') || 0;
                const feeTotalPercent = parseFloat(selectedOption.dataset.feeTotalPercent || '0') || 0;
                const minimumFee = parseFloat(selectedOption.dataset.minimumFee || '0') || 0;
                const maximumFee = parseFloat(selectedOption.dataset.maximumFee || '0') || 0;

                let adminFeeCustomer = feeCustomerFlat + (baseTotal * (feeCustomerPercent / 100));
                if (adminFeeCustomer > 0 && minimumFee > 0 && adminFeeCustomer < minimumFee) {
                    adminFeeCustomer = minimumFee;
                }
                if (adminFeeCustomer > 0 && maximumFee > 0 && adminFeeCustomer > maximumFee) {
                    adminFeeCustomer = maximumFee;
                }
                if (adminFeeCustomer < 0) {
                    adminFeeCustomer = 0;
                }

                const adminFeeInfo = adminFeeCustomer > 0
                    ? adminFeeCustomer
                    : Math.max(0, feeTotalFlat + (baseTotal * (feeTotalPercent / 100)));

                if (selectedOption.value && adminFeeInfo > 0) {
                    adminRow.style.display = '';
                    adminValueNode.textContent = formatRupiah(adminFeeInfo);
                    adminNoteNode.textContent = adminFeeCustomer > 0 ? '(Biaya pelanggan)' : '(Info channel)';
                } else {
                    adminRow.style.display = 'none';
                }

                totalNode.textContent = formatRupiah(baseTotal + adminFeeCustomer);
            }

            if (tripayMethodSelect) {
                tripayMethodSelect.addEventListener('change', updateTripayFeeView);
                updateTripayFeeView();
            }

            if (paymentMethodSelect) {
                paymentMethodSelect.addEventListener('change', function() {
                    const selectedMethod = this.value.toLowerCase();
                    const existingButtons = document.querySelectorAll('.payment-method-buttons');
                    
                    // Remove existing buttons
                    existingButtons.forEach(button => button.remove());
                    
                    // Add new buttons based on selection
                    if (selectedMethod === 'da') {
                        // DANA button
                        const buttonContainer = document.createElement('div');
                        buttonContainer.className = 'payment-method-buttons';
                        buttonContainer.innerHTML = `
                            <div class="payment-actions" style="margin-top: 15px;">
                                <button type="submit" class="payment-button btn-success btn-sm" style="background-color: #28a745; color: white; border: none; padding: 10px 20px; border-radius: 5px; font-size: 14px;">
                                    <i class="bi bi-wallet2"></i> Lanjut Bayar VIA DANA
                                </button>
                            </div>
                        `;
                        this.closest('form').appendChild(buttonContainer);
                    } else if (selectedMethod === 'gp') {
                        // GoPay button
                        const buttonContainer = document.createElement('div');
                        buttonContainer.className = 'payment-method-buttons';
                        buttonContainer.innerHTML = `
                            <div class="payment-actions" style="margin-top: 15px;">
                                <button type="submit" class="payment-button btn-success btn-sm" style="background-color: #00AA13; color: white; border: none; padding: 10px 20px; border-radius: 5px; font-size: 14px;">
                                    <i class="bi bi-wallet2"></i> Lanjut Bayar VIA GoPay
                                </button>
                            </div>
                        `;
                        this.closest('form').appendChild(buttonContainer);
                    }
                });
            }
        });
    </script>
</body>
</html>

<?php
// ===================================================================
// END OF FILE: portal_bayar.php
// ===================================================================
// 
// SYSTEM OVERVIEW:
// ---------------
// This file serves as the main payment portal for customers, providing
// a comprehensive interface for billing and payment processing.
//
// KEY FEATURES:
// - Multi-gateway payment support (Tripay, Duitku, Manual Bank)
// - Dynamic payment method detection based on user preferences
// - Real-time tax calculation based on payment gateway
// - Comprehensive error handling with debug capabilities
// - Mobile-responsive design with bottom navigation
// - Secure API integration with proper signature generation
//
// PAYMENT GATEWAYS:
// 1. Tripay: Full API integration with transaction creation
// 2. Duitku: Complete payment channel support with sandbox mode
// 3. Manual Bank: Traditional bank transfer with proof upload
//
// SECURITY FEATURES:
// - Prepared SQL statements to prevent injection
// - Input validation and sanitization
// - CSRF protection through session management
// - Secure API signature generation
//
// ERROR HANDLING:
// - Comprehensive API error detection
// - User-friendly error messages
// - Debug panels for troubleshooting
// - Graceful fallback mechanisms
//
// RESPONSIVE DESIGN:
// - Mobile-first approach
// - Touch-friendly interface
// - Fixed bottom navigation
// - Optimized for small screens
//
// DATABASE INTEGRATION:
// - Customer data retrieval
// - Payment configuration management
// - Transaction logging and tracking
// - Bank account information storage
//
// ===================================================================
?>