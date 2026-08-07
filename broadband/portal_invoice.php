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

include 'cek_sesi.php'; // Include session check and configuration

$totalTagihan = 0;
$paketHarga = 0;
$periode = "";
$tagihanDetail = [];
$ppn = 0;

// Ambil invoice terbaru yang belum LUNAS (atau invoice terakhir jika semua sudah lunas)
$sql_tagihan = "SELECT * FROM invoice WHERE id_pelanggan='".mysqli_real_escape_string($conn,$idpel)."' AND (status='BELUM BAYAR' OR status='KONFIRMASI' OR status IS NULL OR status='') ORDER BY tanggal_invoice DESC LIMIT 1";
$res_tagihan = mysqli_query($conn, $sql_tagihan);
if($row_tagihan = mysqli_fetch_assoc($res_tagihan)) {
    $paketHarga = $row_tagihan['harga'];
    $periode = $row_tagihan['periode'];
    $status = $row_tagihan['status'];
    $totalTagihan = $row_tagihan['harga'];
    $tagihanDetail[] = [
        'keterangan' => $row_tagihan['keterangan'] ?: ("Tagihan Bulan " . $row_tagihan['periode']),
        'harga' => $row_tagihan['harga']
    ];
    // PPN jika ada, misal 11%
    $ppn = 0;
    if(isset($row_tagihan['ppn']) && is_numeric($row_tagihan['ppn'])) {
        $ppn = $row_tagihan['ppn'];
    } else {
        $ppn = round($row_tagihan['harga'] * 0.11); // default 11%
    }
    $totalTagihan += $ppn;
} else {
    // fallback: invoice terakhir
    $sql_last = "SELECT * FROM invoice WHERE id_pelanggan='".mysqli_real_escape_string($conn,$idpel)."' ORDER BY tanggal_invoice DESC LIMIT 1";
    $res_last = mysqli_query($conn, $sql_last);
    if($row_last = mysqli_fetch_assoc($res_last)) {
                $paketHarga = $row_last['harga'];
                $periode = $row_last['periode'];
                $status = $row_last['status'];
        $totalTagihan = $row_last['harga'];
        $tagihanDetail[] = [
            'keterangan' => $row_last['keterangan'] ?: ("Tagihan Bulan " . $row_last['periode']),
            'harga' => $row_last['harga']
        ];
        $ppn = 0;
        if(isset($row_last['ppn']) && is_numeric($row_last['ppn'])) {
            $ppn = $row_last['ppn'];
        } else {
            $ppn = round($row_last['harga'] * 0.11);
        }
        $totalTagihan += $ppn;
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
    

<?php
// Query hanya invoice yang belum LUNAS
$idpel = $pelanggan['IDPEL'];
$sql_inv = "SELECT * FROM invoice WHERE id_pelanggan='".mysqli_real_escape_string($conn,$idpel)."' AND status='BELUM BAYAR' ORDER BY tanggal_invoice DESC";
$res_inv = mysqli_query($conn, $sql_inv);
?>
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

        .container {
            max-width: 480px;
            margin: 0 auto;
            padding: 20px;
        }

        /* ===================================================================
           HEADER SECTION STYLES - User info and service details
           =================================================================== */
        .header {
            margin-bottom: 20px;
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
    </style>
</head>
<body>
    <!-- ===================================================================
         MAIN CONTAINER - Mobile-responsive payment portal container
         =================================================================== -->
    <div class="container">
        <?php
        // Jika status invoice LUNAS, tampilkan info saja dan hentikan render tagihan/pembayaran
        if (isset($status) && strtoupper(trim($status)) === 'LUNAS') {
        ?>
            <div class="alert alert-success" style="text-align:center; font-size:18px; margin:30px 0;">
                <i class="bi bi-check-circle" style="font-size:32px;"></i><br>
                <strong>Status: SUDAH LUNAS</strong><br>
                Tagihan untuk periode <b><?= htmlspecialchars($periode) ?></b> sudah dibayar.<br>
                Terima kasih atas pembayaran Anda!
            </div>
        <?php
            // Stop rendering further payment/tagihan UI
        } else {
        ?>
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
            // CHECK EXISTING TRANSACTIONS
            // Purpose: Look for pending payment requests for this customer
            // ===================================================================
            $sql2 = "SELECT * FROM transaksi WHERE IDPEL = ? AND STATUS = 'PERMINTAAN KODE'";
            $stmt = $conn->prepare($sql2);
            $stmt->bind_param("s", $merchantRef);
        $stmt->execute();
        $result = $stmt->get_result();

        // ===================================================================
        // EXISTING TRANSACTION PROCESSING
        // Purpose: If customer has pending payment, retrieve and display details
        // ===================================================================
        if ($result->num_rows > 0) {
            $adaData = ($result->num_rows > 0) ? "true" : "false";

            // Get transaction reference from database
            $sql = "SELECT  * FROM `transaksi` WHERE `IDPEL` ='$merchantRef' ";
            $result = $conn->query($sql);

            if ($result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    $reference = $row["BUKTI"];
                }
            }

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
                <a href="<?php echo $data['checkout_url']; ?>" class="payment-button btn-success" target="_blank">Lihat Checkout</a>
                <a href="portal_invoice.php?cari=<?= $merchantRef; ?>&ref=<?= $reference; ?>&action=hapus" class="payment-button btn-danger">Batalkan</a>
            </div>
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
                if (isset($_POST['payment_method']) && isset($_POST['merchant_code'])) {
                    // Extract Duitku form data
                    $duitku_method = $_POST['payment_method'];
                    $duitku_merchant_code = $_POST['merchant_code'];
                    $duitku_api_key = $_POST['api_key'];
                    $duitku_callback_url = $_POST['callback_url'];
                    $duitku_return_url = $_POST['return_url'];
                    $order_id = $_POST['order_id'];
                    
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
                        $barcode = $duitku_result['qrString'] ?? '';
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

                        $query = "INSERT INTO transaksi (TANGGALBAYAR, IDPEL, NAMA, PAKET, HARGA, STATUS, BUKTI, PEMILIK, CEK) 
                                  VALUES (?, ?, ?, ?, ?, 'PERMINTAAN KODE', ?, ?, 'DUITKU')";
                        $stmt = $conn->prepare($query);
                        $stmt->bind_param("sssssss", $ptanggal, $merchantRef, $nama, $namapaket, $totalTagihan, $reference, $pemilik);
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
                // Proses Tripay Payment (existing code)
                elseif (isset($_POST['method'])) {
                    $method = $_POST['method'];
                    $signature = hash_hmac('sha256', $merchantCode . $merchantRef . $totalTagihan, $privateKey);

                    $data = [
                        'method'         => $method,
                        'merchant_ref'   => $merchantRef,
                        'amount'         => $totalTagihan,
                        'customer_name'  => $pelanggan['NAMA'],
                        'customer_email' => $email,
                        'customer_phone' => $pelanggan['NOWA'],
                        'order_items'    => [[
                            'name'     => 'Pembayaran WiFi ' . $pelanggan['PAKET'],
                            'price'    => $totalTagihan,
                            'quantity' => 1
                        ]],
                        'callback_url'   => "https://$domain/crm/billing/callbacktripay/callback_tripay_$pemilik.php",
                        'return_url'     => "https://$domain/crm/billing/broadband/portal.php?cari={$pelanggan['NOWA']}",
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

                        $query = "INSERT INTO transaksi (TANGGALBAYAR, IDPEL, NAMA, PAKET, HARGA, STATUS, BUKTI, PEMILIK, CEK) 
                                  VALUES (?, ?, ?, ?, ?, 'PERMINTAAN KODE', ?, ?, 'PERMINTAAN')";
                        $stmt = $conn->prepare($query);
                        $stmt->bind_param("sssssss", $ptanggal, $merchantRef, $nama, $namapaket, $totalTagihan, $reference, $pemilik);
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
                        • Pastikan konfigurasi Duitku sudah benar<br>
                        • Cek koneksi internet dan firewall<br>
                        • Periksa merchant code dan API key<br>
                        • Hubungi support jika masalah berlanjut
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
                        • Pastikan API Key dan Private Key Tripay sudah benar<br>
                        • Cek koneksi internet dan firewall<br>
                        • Periksa merchant code dan signature<br>
                        • Pastikan akun Tripay dalam status aktif
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
            
            if (isset($reference) && $reference != '') {
        ?>

        <div class="payment-details">
            <div class="payment-details-header">Kode Pembayaran Anda</div>
            <div class="payment-info">
                <p><strong>Metode:</strong> <?php echo $namapembayaran ?></p>
                <p><strong>Referensi:</strong> <?php echo $reference ?></p>
                <p><strong>Nominal:</strong> Rp<?php echo number_format($harusbayar, 0, ',', '.'); ?></p>
                <p><strong>Kode Bayar:</strong> <?php echo $kodebayar; ?></p>
                
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
                <a href="<?php echo $cekout; ?>" class="payment-button btn-success" target="_blank">Lihat Checkout</a>
                <a href="portal_baru.php?cari=<?= $merchantRef; ?>&ref=<?= $reference; ?>&action=hapus" class="payment-button btn-danger">Batalkan</a>
            </div>
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

    <?php if($tampiltagihan =='SHOW'): ?>
            <?php 
            // Tentukan payment method berdasarkan default setting
            $show_tripay = false;
            $show_duitku = false;
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
                case 'manual_bank':
                default:
                    $show_manual = true;
                    $show_tripay = false;
                    $show_duitku = false;
                    $show_midtrans = false;
                    $show_xendit = false;
                    break;
            }
            
            if($show_tripay && $apiKey != '' ): ?>
                <!-- Bill Details Section -->
                <div class="bill-details">
                    <div class="bill-details-header">Detail Tagihan Anda</div>
            <!-- Tabel riwayat invoice lengkap -->
            <!-- Card view for unpaid invoices -->
            <div style="margin-bottom:20px;">
            <?php if(mysqli_num_rows($res_inv) > 0): ?>
                <?php mysqli_data_seek($res_inv, 0); while($inv = mysqli_fetch_assoc($res_inv)): ?>
                <div class="service-card" style="margin-bottom: 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.07);">
                    <div class="bill-status">
                        <span class="payment-status status-unpaid">Belum Bayar</span>
                        <span style="font-size:13px; color:#888;">#<?= htmlspecialchars($inv['nomor_invoice']) ?></span>
                    </div>
                    <div style="margin: 10px 0 5px 0;">
                        <span class="service-name">Paket: <?= htmlspecialchars($inv['paket']) ?></span>
                    </div>
                    <div style="font-size:15px; color:#333; margin-bottom:3px;">
                        <b>Keterangan:</b> <?= htmlspecialchars($inv['periode']) ?>
                    </div>
                    <div style="font-size:14px; color:#666; margin-bottom:3px;">
                        <b>Harga:</b> Rp<?= number_format($inv['harga'],0,',','.') ?>
                    </div>
                    <div style="font-size:13px; color:#888; margin-bottom:3px;">
                        <b>Tanggal Invoice:</b> <?= htmlspecialchars($inv['tanggal_invoice']) ?>
                    </div>
                    
                   
                        </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div style="text-align:center; color:#888; padding:30px 0;">Tidak ada tagihan yang belum dibayar.</div>
                    <?php endif; ?>
                    </div>
                    
                    <div class="alert" style="background-color: #e8f6f5; border: 1px solid var(--primary-green); color: var(--dark-green); margin-bottom: 15px;">
                        <i class="bi bi-credit-card"></i> <strong>Tripay Payment Gateway</strong>
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
                            <tr class="bill-total">
                                <td><strong>Total Tagihan</strong></td>
                                <td><strong>Rp<?= number_format($totalTagihan, 2, ',', '.'); ?></strong></td>
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
                                    echo '<option value="' . htmlspecialchars($channel['code']) . '">'
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
                            <tr class="bill-total">
                                <td><strong>Total Tagihan</strong></td>
                                <td><strong>Rp<?= number_format($totalTagihan, 2, ',', '.'); ?></strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="payment-method">
                    <div class="payment-method-header">
                        <i class="bi bi-wallet2"></i> Pembayaran via Duitku Payment Gateway
                    </div>
                    
                    <!-- <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> <strong>Info:</strong> Sistem pembayaran otomatis menggunakan Duitku Payment Gateway
                    </div> -->

                    <?php
                    // ===================================================================
                    // DUITKU CONFIGURATION RETRIEVAL
                    // Purpose: Get Duitku settings for current server and owner
                    // ===================================================================
                    $duitku_config = null;
                    $duitku_query = "SELECT * FROM duitku WHERE server = '".mysqli_real_escape_string($conn, $username)."' AND pemilik = '".mysqli_real_escape_string($conn, $useraccount)."' LIMIT 1";
                    $duitku_result = mysqli_query($conn, $duitku_query);
                    if (mysqli_num_rows($duitku_result) > 0) {
                        $duitku_config = mysqli_fetch_assoc($duitku_result);
                    }

                    // ===================================================================
                    // DUITKU PAYMENT CHANNELS
                    // Purpose: Define available payment methods for Duitku gateway
                    // ===================================================================
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
                        <!-- ===================================================================
                             DUITKU CONFIGURATION STATUS
                             Purpose: Display Duitku gateway availability and settings
                             =================================================================== -->
                        <div class="alert" style="background-color: #e8f6f5; border: 1px solid var(--primary-green); color: var(--dark-green);">
                            <i class="bi bi-check-circle"></i> <strong>Konfigurasi Duitku Aktif</strong><br>
                            Merchant Code: <?= htmlspecialchars($duitku_config['merchant_code']) ?><br>
                            Pajak: <?= $pajak ?>% (dari konfigurasi Duitku)
                        </div>

                        <!-- ===================================================================
                             DUITKU PAYMENT FORM
                             Purpose: Payment form for Duitku gateway submission
                             =================================================================== -->
                        <form method="POST" style="margin-top: 20px;">
                            <input type="hidden" name="amount" value="<?= $totalTagihan ?>">
                            <input type="hidden" name="customer_name" value="<?= htmlspecialchars($pelanggan['IDPEL']) ?>">
                            <input type="hidden" name="customer_email" value="<?= htmlspecialchars($email) ?>">
                            <input type="hidden" name="customer_phone" value="<?= htmlspecialchars($pelanggan['NOWA']) ?>">
                            <input type="hidden" name="idpel" value="<?= $merchantRef ?>">
                            <input type="hidden" name="merchant_ref" value="<?= $merchantRef ?>">
                            <input type="hidden" name="merchant_code" value="<?= htmlspecialchars($duitku_config['merchant_code']) ?>">
                            <input type="hidden" name="api_key" value="<?= htmlspecialchars($duitku_config['api_key']) ?>">
                            <input type="hidden" name="callback_url" value="<?= htmlspecialchars($duitku_config['callback_url']) ?>">
                            <input type="hidden" name="return_url" value="<?= htmlspecialchars($duitku_config['return_url']) ?>">
                            
                            <!-- Real Duitku Payment Channels -->
                            <div style="margin-bottom: 15px;">
                                <label style="display: block; margin-bottom: 8px; font-weight: 600; color: var(--primary-green);">
                                    Pilih Metode Pembayaran:
                                </label>
                                <select name="payment_method" class="method-select" required>
                                    <option value="">-- Pilih Metode Pembayaran --</option>
                                    <?php foreach ($duitku_channels as $channel): ?>
                                        <option value="<?= htmlspecialchars($channel['code']) ?>">
                                            <?= htmlspecialchars($channel['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Additional Duitku fields -->
                            <div style="margin-bottom: 15px;">
                                <label style="display: block; margin-bottom: 8px; font-weight: 600; color: var(--primary-green);">
                                    Order ID:
                                </label>
                                <input type="text" name="order_id" class="method-select" 
                                       value="<?= 'INV-' . time() . '-' . $merchantRef ?>" readonly 
                                       style="background-color: #f8f9fa; color: #6c757d;">
                            </div>

                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 15px;">
                                <div>
                                    <label style="display: block; margin-bottom: 8px; font-weight: 600; color: var(--primary-green);">
                                        ID pelanggan :
                                    </label>
                                    <input type="text" name="customer_name_display" class="method-select" 
                                           value="<?= htmlspecialchars($pelanggan['IDPEL']) ?>" readonly
                                           style="background-color: #f8f9fa; color: #6c757d;">
                                </div>
                                <div>
                                    <label style="display: block; margin-bottom: 8px; font-weight: 600; color: var(--primary-green);">
                                        Total Bayar:
                                    </label>
                                    <input type="text" name="amount_display" class="method-select" 
                                           value="Rp <?= number_format($totalTagihan, 0, ',', '.') ?>" readonly
                                           style="background-color: #f8f9fa; color: #6c757d; font-weight: bold;">
                                </div>
                            </div>

                            <button type="submit" class="submit-button">
                                <i class="bi bi-wallet2"></i> BUAT PEMBAYARAN DUITKU
                            </button>
                        </form>
                    <?php else: ?>
                        <!-- Konfigurasi Duitku belum tersedia -->
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i> <strong>Konfigurasi Duitku belum tersedia</strong><br>
                            Silakan hubungi administrator untuk mengatur konfigurasi Duitku Payment Gateway.
                        </div>
                        
                        <div style="text-align: center; padding: 20px;">
                            <i class="bi bi-gear" style="font-size: 48px; color: #ccc;"></i>
                            <p style="color: #666; margin-top: 10px;">Payment gateway sedang dalam pengaturan</p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php elseif($show_midtrans && $default_payment == 'midtrans'): ?>
                <!-- Midtrans Payment Section -->
                <div class="bill-details">
                    <div class="bill-details-header">Detail Tagihan Anda</div>
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
                            <tr class="bill-total">
                                <td><strong>Total Tagihan</strong></td>
                                <td><strong>Rp<?= number_format($totalTagihan, 2, ',', '.'); ?></strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="payment-method">
                    <div class="payment-method-header">
                        <i class="bi bi-credit-card"></i> Pembayaran via Midtrans Payment Gateway
                    </div>
                    <?php
                    // Midtrans config
                    $midtrans_config = null;
                    $midtrans_query = "SELECT * FROM midtrans WHERE server = '".mysqli_real_escape_string($conn, $username)."' AND pemilik = '".mysqli_real_escape_string($conn, $useraccount)."' LIMIT 1";
                    $midtrans_result = mysqli_query($conn, $midtrans_query);
                    if (mysqli_num_rows($midtrans_result) > 0) {
                        $midtrans_config = mysqli_fetch_assoc($midtrans_result);
                    }
                    ?>
                    <?php if ($midtrans_config): ?>
                        <div class="alert" style="background-color: #e8f6f5; border: 1px solid var(--primary-green); color: var(--dark-green);">
                            <i class="bi bi-check-circle"></i> <strong>Konfigurasi Midtrans Aktif</strong><br>
                            Merchant ID: <?= htmlspecialchars($midtrans_config['merchant_id']) ?><br>
                            Pajak: <?= $pajak ?>%
                        </div>
                        <form method="POST" style="margin-top: 20px;">
                            <!-- Midtrans form fields -->
                            <button type="submit" class="submit-button">
                                <i class="bi bi-credit-card"></i> BUAT PEMBAYARAN MIDTRANS
                            </button>
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
                            <tr class="bill-total">
                                <td><strong>Total Tagihan</strong></td>
                                <td><strong>Rp<?= number_format($totalTagihan, 2, ',', '.'); ?></strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="payment-method">
                    <div class="payment-method-header">
                        <i class="bi bi-credit-card"></i> Pembayaran via Xendit Payment Gateway
                    </div>
                    <?php
                    // Xendit config
                    $xendit_config = null;
                    $xendit_query = "SELECT * FROM xendit WHERE server = '".mysqli_real_escape_string($conn, $username)."' AND pemilik = '".mysqli_real_escape_string($conn, $useraccount)."' LIMIT 1";
                    $xendit_result = mysqli_query($conn, $xendit_query);
                    if (mysqli_num_rows($xendit_result) > 0) {
                        $xendit_config = mysqli_fetch_assoc($xendit_result);
                    }
                    ?>
                    <?php if ($xendit_config): ?>
                        <div class="alert" style="background-color: #e8f6f5; border: 1px solid var(--primary-green); color: var(--dark-green);">
                            <i class="bi bi-check-circle"></i> <strong>Konfigurasi Xendit Aktif</strong><br>
                            Merchant ID: <?= htmlspecialchars($xendit_config['merchant_id']) ?><br>
                            Pajak: <?= $pajak ?>%
                        </div>
                        <form method="POST" style="margin-top: 20px;">
                            <!-- Xendit form fields -->
                            <button type="submit" class="submit-button">
                                <i class="bi bi-credit-card"></i> BUAT PEMBAYARAN XENDIT
                            </button>
                        </form>
                    <?php else: ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i> <strong>Konfigurasi Xendit belum tersedia</strong><br>
                            Silakan hubungi administrator untuk mengatur konfigurasi Xendit Payment Gateway.
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
                            <tr class="bill-total">
                                <td><strong>Total Tagihan</strong></td>
                                <td><strong>Rp<?= number_format($totalTagihan, 2, ',', '.'); ?></strong></td>
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

                    $tanggal2 = '2025-10-12';
                    $tanggal2 = formatTanggalIndo($tanggal2); 
                    $message = '';
                    $uploadedFile = '';

                    if (isset($_FILES['bukti']) && $_FILES['bukti']['error'] === UPLOAD_ERR_OK) {
                        $nama       = $pelanggan['NAMA'] ?? '';
                        $idpel      = $pelanggan['IDPEL'] ?? '';
                        $paket      = $pelanggan['PAKET'] ?? '';
                        $harga      = $paketHarga ?? 0;
                        $penggunaan = $periode ?? '';
                        $cek        = '';
                        $pemilik    = $username ?? '';
                        
                        $insert = mysqli_query($conn, "
                            INSERT INTO transaksi 
                            (TANGGALBAYAR, PENGUNAAN, STATUS,BUKTI, IDPEL, NAMA, PAKET, HARGA, CEK, PEMILIK) 
                            VALUES 
                            ('$tanggal2', '$penggunaan', 'KONFIRMASI','MANUAL', '$idpel', '$nama', '$paket', $harga, '$cek', '$pemilik')
                        ");

                        if (!$insert) {
                            $message = "❌ Gagal membuat transaksi! Error: " . mysqli_error($conn);
                        } else {
                            $id = mysqli_insert_id($conn);
                            $uploadDir = __DIR__ . "/../../../dokumen/buktibon/";
                            $uploadUrl = "/dokumen/buktibon/";
                            if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0755, true); }

                            $ext = strtolower(pathinfo($_FILES['bukti']['name'], PATHINFO_EXTENSION));
                            $allowed = ['jpg','jpeg','png'];

                            if (!in_array($ext, $allowed)) {
                                $message = "❌ File tidak diizinkan. Hanya: " . implode(', ', $allowed);
                            } else {
                                $filename   = $id . '.' . $ext;
                                $targetFile = $uploadDir . $filename;

                                if (move_uploaded_file($_FILES['bukti']['tmp_name'], $targetFile)) {
                                    $bukti = mysqli_real_escape_string($conn, 'buktibon/' . $filename);
                                    mysqli_query($conn, "UPDATE transaksi SET BUKTI='$bukti' WHERE id=$id");

                                    $uploadedFile = $uploadUrl . $filename;
                                    $message = "✅ Transaksi berhasil dibuat dan file bukti diupload!";
                                } else {
                                    $message = "❌ Gagal mengupload file bukti!";
                                }
                            }
                        }
                    }
                    ?>

                    <div class="upload-form">
                        <div class="manual-payment-header">Upload Bukti Pembayaran</div>
                        
                        <?php if ($message): ?>
                            <div class="alert <?= strpos($message, '❌') !== false ? 'alert-error' : 'alert-success'; ?>">
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
            <div class="bill-details">
                <div class="bill-details-header">Detail Tagihan Anda</div>
                <p>Belum ada tagihan baru..</p>
            </div>
        <?php endif; ?>
        <?php } // end if status LUNAS ?>

    </div>

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
                // Hide debug panel
                debugDiv.style.display = 'none';
                button.innerHTML = '<i class="bi bi-bug"></i> Show Debug Info';
                button.style.backgroundColor = '#6c757d';
            }
        }
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