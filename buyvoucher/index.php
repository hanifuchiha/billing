<?php
require_once __DIR__ . '/../../dokumen_folder_helper.php';
require '../koneksibilling.php';

// Load config
$config = json_decode(file_get_contents('../config.json'), true);
$domain = $config['domain'] ?? 'quenbytekniksejahtera.com';

// Security: Validate and sanitize input
$username = isset($_GET['server']) ? mysqli_real_escape_string($conn, $_GET['server']) : die("Error: Server parameter missing");

////// GET USER DATA /////////////////////////////////////////////////////////
$sql99 = "SELECT * FROM `user` WHERE `USERNAME`='$username'";
$query99 = mysqli_query($conn, $sql99);

if (!$query99) die("Database error: " . mysqli_error($conn));

$data99 = mysqli_fetch_assoc($query99);
if (!$data99) die("Error: User not found");

$user_id = $data99['id'];

////// GET SERVER LIST FROM SERVER TABLE /////////////////////////////////
$sql_servers = "SELECT * FROM server WHERE user_id = '$user_id'";
$query_servers = mysqli_query($conn, $sql_servers);

if (!$query_servers) die("Database error: " . mysqli_error($conn));

$server_list = [];
while ($server_data = mysqli_fetch_assoc($query_servers)) {
    $server_list[] = $server_data['PEMILIK']; // Assuming 'IP' is the column for server IP
}

if (empty($server_list)) die("Error: No servers found for this user");

$server_list = "'" . implode("', '", array_map(function($ip) use ($conn) {
    return mysqli_real_escape_string($conn, $ip);
}, $server_list)) . "'";


// Get API Keys
$sql = "SELECT * FROM `tripay` WHERE `pemilik`='$username'";
$query = mysqli_query($conn, $sql);
if (!$query) die("Database error: " . mysqli_error($conn));

$data = mysqli_fetch_assoc($query);
if (!$data) die("Error: Tripay configuration not found");

$apiKey = $data['apikey'] ?? die("Error: API Key not found");
$privateKey = $data['privatekey'] ?? die("Error: Private Key not found");
$merchantCode = $data['merchant'] ?? die("Error: Merchant Code not found");



// Get payment methods from Tripay
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => 'https://tripay.co.id/api/merchant/payment-channel',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10, // Timeout 10 detik
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json'
    ]
]);
$response = curl_exec($ch);
if (curl_errno($ch)) die("cURL Error: " . curl_error($ch));
curl_close($ch);

$payment_channels = json_decode($response, true)['data'] ?? [];
if (empty($payment_channels)) die("Error: Could not retrieve payment methods");

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate required fields
    $required = ['voucher_amount', 'customer_name', 'customer_phone', 'payment_method'];
    foreach ($required as $field) {
        if (empty($_POST[$field])) die("Error: Missing required field - $field");
    }

    // Process voucher data
    $datavoucher_amount = $_POST['voucher_amount'];
    $rows = explode("\n", $datavoucher_amount);

    $voucherData = [];
    foreach ($rows as $row) {
        $parts = explode("|", $row);
        if (count($parts) >= 5) {
            $voucherData[] = [
                'harga' => trim($parts[0]),
                'uptime' => trim($parts[1]),
                'paket' => trim($parts[2]),
                'server' => trim($parts[3]),
                'area' => trim($parts[4])
            ];
        }
    }

    if (empty($voucherData)) die("Error: Invalid voucher data format");

    // Use the first voucher data (assuming single selection for now)
    $selectedVoucher = $voucherData[0];
    $harga = $selectedVoucher['harga'];
    $uptime = $selectedVoucher['uptime'];
    $paket = $selectedVoucher['paket'];
    $server = $selectedVoucher['server'];
    $area = $selectedVoucher['area'];

    // Generate random code
    $mode = $_POST['mode'] ?? 'mixed';
    $panjang = intval($_POST['length'] ?? 8);

    function generate_random($length = 8, $mode = 'mixed')
    {
        $chars = '';
        if ($mode === 'number') {
            $chars = '0123456789';
        } elseif ($mode === 'letter') {
            $chars = 'abcdefghijklmnopqrstuvwxyz';
        } else {
            $chars = '0123456789abcdefghijklmnopqrstuvwxyz';
        }

        $result = '';
        $max = strlen($chars) - 1;
        for ($i = 0; $i < $length; $i++) {
            $result .= $chars[random_int(0, $max)];
        }
        return $result;
    }

    $kode = generate_random($panjang, $mode);
    $datavoucher = "$kode-$paket-$uptime-{$_POST['customer_phone']}-$server-$area";
    $customer_name = mysqli_real_escape_string($conn, $_POST['customer_name']);
    $customer_email = 'quenbytechnical@gmail.com';
    $customer_phone = mysqli_real_escape_string($conn, $_POST['customer_phone']);
    $payment_method = mysqli_real_escape_string($conn, $_POST['payment_method']);


    // Create invoice
    $invoice = "INV" . time();
    $signature = hash_hmac('sha256', $merchantCode . $datavoucher . $harga, $privateKey);

    // Prepare Tripay request data
    $data = [
        'method' => $payment_method,
        'merchant_ref' => $datavoucher,
        'amount' => $harga,
        'customer_name' => $customer_name,
        'customer_email' => $customer_email,
        'customer_phone' => $customer_phone,
        'note' => 'Voucher purchase',
        'order_items' => [
            [
                'sku' => $datavoucher,
                    'name' => $datavoucher,
                    'price' => $harga,
                'quantity' => 1
            ]
        ],
        'callback_url' => "https://$domain/crm/billing/buyvoucher/callback_tripay.php",
        'return_url' => "https://$domain/crm/billing/buyvoucher/index.php?server=" . urlencode($username),
        'signature' => $signature
    ];  

    // Send request to Tripay
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://tripay.co.id/api/transaction/create',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_TIMEOUT => 15, // Timeout 15 detik untuk transaksi
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
        ]
    ]);

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        die("cURL Error: " . curl_error($ch));
    }
    curl_close($ch);

    $result = json_decode($response, true);
    if (!$result['success']) {
        die("Error creating transaction: " . ($result['message'] ?? 'Unknown error'));
    }

    $payment_link = $result['data']['checkout_url'];
    $invoiceref = $result['data']['reference'];

    // Format payment date
    function tanggal_indo2($tanggal, $cetak_hari = false)
    {
        $hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
        $bulan = [
            1 => 'Januari',
            'Februari',
            'Maret',
            'April',
            'Mei',
            'Juni',
            'Juli',
            'Agustus',
            'September',
            'Oktober',
            'November',
            'Desember'
        ];

        $split = explode('-', $tanggal);
        $tanggal_formatted = $split[2] . ' ' . $bulan[(int)$split[1]] . ' ' . $split[0];

        if ($cetak_hari) {
            $num_hari = date('w', strtotime($tanggal));
            return $hari[$num_hari] . ', ' . $tanggal_formatted;
        }
        return $tanggal_formatted;
    }

    $tanggalbayar = tanggal_indo2(date('Y-m-d'), true);

    // Save transaction to database
    $sql = "INSERT INTO `transaksi`(`TANGGALBAYAR`, `STATUS`, `IDPEL`, `NAMA`, `PAKET`, `HARGA`, `BUKTI`, `CEK`, `PEMILIK`) 
            VALUES (?, 'PERMINTAAN KODE', ?, ?, ?, ?, ?, '', ?)";

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) die("Database error: " . mysqli_error($conn));

    mysqli_stmt_bind_param(
        $stmt,
        "sssssss",
        $tanggalbayar,
        $kode,
        $customer_name,
        $paket,
        $harga,
        $invoiceref,
        $server
    );

    if (!mysqli_stmt_execute($stmt)) {
        die("Error saving transaction: " . mysqli_error($conn));
    }
    mysqli_stmt_close($stmt);

// Send WhatsApp notification
$text = "Konfirmasi Pembayaran\n\nNama: $customer_name\nWhatsApp: $customer_phone\n" .
    "Nominal: Rp $harga\nMetode: $payment_method\nInvoice: $invoice\n" .
    "Link Pembayaran: $payment_link\n\nSelesaikan pembayaran terlebih dahulu.\n" .
    "Jika pembayaran berhasil kode voucher akan terkirim";

$file = "../waapi.txt";
$waapi = $namebot = $password = "";
if (file_exists($file)) {
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (substr($line, 0, 6) === "waapi=") $waapi = substr($line, 6);
        if (substr($line, 0, 8) === "namebot=") $namebot = substr($line, 8);
        if (substr($line, 0, 9) === "password=") $password = substr($line, 9);
    }
}

// Format phone number
$nohp = preg_replace('/[^0-9]/', '', $customer_phone);
if (substr($nohp, 0, 1) === '0') {
    $hp = '62' . substr($nohp, 1);
} elseif (substr($nohp, 0, 2) !== '62') {
    $hp = '62' . $nohp;
} else {
    $hp = $nohp;
}

$phone = "$hp@s.whatsapp.net";
$message = $text;

// Data JSON sesuai dengan dokumentasi API
$data = [
    "phone" => $phone,
    "message" => $message
];

// Inisialisasi cURL
$url = "$waapi/send/message";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5); // Timeout 5 detik untuk WA
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json"
]);

// Tambahkan Basic Auth
curl_setopt($ch, CURLOPT_USERPWD, "$namebot:$password");

// Eksekusi dan tangani respons
$wa_response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Debug: Echo WhatsApp response
echo "WhatsApp Debug:<br>";
echo "Phone: " . $phone . "<br>";
echo "Message: " . nl2br($text) . "<br>";
echo "WA API URL: " . $url . "<br>";
echo "Response: " . $wa_response . "<br>";
echo "HTTP Code: " . $httpCode . "<br><br>";    // Redirect to payment page
    header("Location: " . $payment_link);
    exit;
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pembelian Kode Voucher</title>
    <link rel="stylesheet" href="css/mikhmon-ui.light.min.css">
    <link rel="icon" href="img/favicon.png">
    <style>
        table,
        th,
        td {
            border: 1px solid;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }

        .card {
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .card-body {
            padding: 20px;
        }

        .form-control {
            width: 100%;
            padding: 8px;
            margin-bottom: 15px;
        }

        .btn {
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }

        .bg-success {
            background-color: #28a745;
            color: white;
        }

        .bg-danger {
            background-color: #dc3545;
            color: white;
        }

        .text-center {
            text-align: center;
        }

        .mb-3 {
            margin-bottom: 15px;
        }

        .mb-4 {
            margin-bottom: 20px;
        }

        .w-12 {
            width: 100%;
        }

        .btn-md {
            padding: 10px;
        }

        .pd-5 {
            padding: 5px;
        }

        .loading {
            display: none;
            text-align: center;
            margin-top: 20px;
        }

        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #28a745;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>

<body>
    <div class="container">
        <center>
            <h3>PEMBELIAN KODE VOUCHER</h3>
        </center>

        <div class="card">
            <div class="card-body">
                <form action="" method="POST">
                    <div class="mb-3">
                        <label class="form-label">Nama</label>
                        <input type="text" name="customer_name" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">No WhatsApp</label>
                        <input type="text" name="customer_phone" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Nominal Voucher</label>
                        <select name="voucher_amount" class="form-control" required>
                            <?php
                            $sql = "SELECT * FROM paket_hotspot WHERE `pemilik` IN ($server_list)";
                            $query = mysqli_query($conn, $sql);

                            if (!$query) die("Database error: " . mysqli_error($conn));

                            while ($data = mysqli_fetch_assoc($query)) {
                                $value = "{$data['harga']}|{$data['uptime']}|{$data['paket']}|{$data['pemilik']}|{$data['area']}";
                                $label = "{$data['harga']} - {$data['paket']} - {$data['area']}";
                                echo "<option value='$value'>$label</option>";
                            }
                            ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Metode Pembayaran</label>
                        <select name="payment_method" class="form-control" required>
                            <?php foreach ($payment_channels as $channel): ?>
                                <option value="<?= htmlspecialchars($channel['code']) ?>">
                                    <?= htmlspecialchars($channel['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <hr>
                    <button type="submit" id="buyButton" class="w-12 btn-md bg-success pd-5">Beli Sekarang</button>
                </form>

                <div id="loading" class="loading">
                    <div class="spinner"></div>
                    <p>Memproses pembelian...</p>
                </div>

                <button class="w-12 btn-md bg-danger pd-5" onclick="window.history.back()">Kembali</button>
            </div>
        </div>
    </div>

    <script>
        document.querySelector('form').addEventListener('submit', function(e) {
            const button = document.getElementById('buyButton');
            const loading = document.getElementById('loading');
            
            // Disable button
            button.disabled = true;
            button.textContent = 'Memproses...';
            
            // Show loading
            loading.style.display = 'block';
            
            // Form will submit normally
        });
    </script>
</body>

</html>