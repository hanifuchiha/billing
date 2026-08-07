
<?php
$nominalList = [
    90000   => 7,
    150000  => 30,
    250000  => 60,
    2500000 => 365
];

// Hitung harga per hari
$packages = [];
foreach ($nominalList as $nominal => $days) {
    $price_per_day = $nominal / $days;
    $packages[] = [
        'nominal' => $nominal,
        'days' => $days,
        'price_per_day' => $price_per_day
    ];
}

// Tentukan Best Value (harga per hari termurah)
$best_value_index = 0;
$min_price_per_day = $packages[0]['price_per_day'];
foreach ($packages as $i => $pkg) {
    if ($pkg['price_per_day'] < $min_price_per_day) {
        $min_price_per_day = $pkg['price_per_day'];
        $best_value_index = $i;
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Paket Topup</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-5">
    <h2>Daftar Paket perpanjang</h2>
    <div class="row mt-4">
        <?php foreach ($packages as $i => $pkg): ?>
        <div class="col-md-3 mb-4">
            <div class="border p-3 h-100 text-center <?= $i === $best_value_index ? 'border-warning' : '' ?>">
                <div class="card-body">
                    <h5 class="card-title">Rp<?= number_format($pkg['nominal'],0,',','.') ?></h5>
                    <p class="card-text"><?= $pkg['days'] ?> Hari</p>
                    <p class="card-text"><small>Rp<?= number_format($pkg['price_per_day'],0,',','.') ?>/hari</small></p>
                    <p class="card-text">Unlimited Routers</p>
                    <p class="card-text">Unlimited users PPPOE dan VOUCHER</p>
                    <?php if($i === $best_value_index): ?>
                        <span class="badge bg-warning text-dark mb-2">Best Value</span>
                    <?php endif; ?>
                    <br>
                    
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
</body>
</html>
<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// Check if user is logged in
if (!isset($_SESSION['status']) || $_SESSION['status'] != "login") {
    header("location:index.php?pesan=belum_login");
    exit;
}



// Simpan konfigurasi
$config_file = 'config.json';
$config = file_exists($config_file) ? json_decode(file_get_contents($config_file), true) : [];

// Initialize variables
$ceknama = $_SESSION['PEMILIK'] ?? '';

$nowacek=$_SESSION['NOWA'] ;

$success = false;
$message = '';
$channels = [];
$checkout_url = '';

require 'koneksidb.php';
include 'tripay_config.php';

// Function to get payment channels
function getPaymentChannels($api_key)
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://tripay.co.id/api/merchant/payment-channel',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $api_key],
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

// Function to check transaction status
function cekTransaksi($reference, $api_key)
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://tripay.co.id/api/transaction/detail?reference=' . urlencode($reference),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $api_key],
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

// Get all payment channels
$channels = getPaymentChannels($tripay_api_key);

// Handle topup request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['topup'])) {





    $amount = intval($_POST['amount']);
    $method = $_POST['method'];
    $merchant_ref = 'TOPUP' . time();

    $data = [
        'method'        => $method,
        'merchant_ref'  => $merchant_ref,
        'amount'        => $amount,
        'customer_name' => 'User #' . $ceknama,
        'customer_email' => 'user@domain.com',
        'customer_phone' => $nowacek,
        'order_items'   => [[
            'sku'      => 'BAYAR BILLING',
            'name'     => 'Top Up Saldo',
            'price'    => $amount,
            'quantity' => 1
        ]],
        'return_url'    => $config['URL'].'/crm/billing/topup.php',
        'callback_url'  => '',
        'expired_time'  => time() + (24 * 60 * 60),
        'signature'     => hash_hmac('sha256', $tripay_merchant_code . $merchant_ref . $amount, $tripay_private_key)
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://tripay.co.id/api/transaction/create',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tripay_api_key]
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        $message = "Gagal koneksi: $err";
    } else {
        $res = json_decode($res, true);
        if ($res['success']) {
            $_SESSION['transaction_ref_sewa_billing'] = $res['data']['reference'];
            header("Location: topup.php");
            exit;
        } else {
            $message = $res['message'] ?? 'Transaksi gagal.';
        }
    }
}

// Handle delete pending request
if (isset($_POST['hapus_pending'])) {
    require 'koneksidb.php';

    $ref = mysqli_real_escape_string($conn, $_POST['hapus_pending']);

    // 1. Verifikasi transaksi milik user yang login
    $user_query = mysqli_query($conn, "SELECT id FROM user WHERE USERNAME = '" . mysqli_real_escape_string($conn, $ceknama) . "'");

    if (!$user_query || mysqli_num_rows($user_query) == 0) {
        $_SESSION['message'] = "User tidak valid";
        $_SESSION['message_type'] = "danger";
        header("Location: topup.php");
        exit;
    }

    $user = mysqli_fetch_assoc($user_query);
    $user_id = $user['id'];

    // 2. Hapus dari database dengan prepared statement
    $stmt = mysqli_prepare($conn, "DELETE FROM topup WHERE ref = ? AND user_id = ? AND type = 'pending'");
    mysqli_stmt_bind_param($stmt, "si", $ref, $user_id);

    if (mysqli_stmt_execute($stmt)) {
        $affected_rows = mysqli_stmt_affected_rows($stmt);

        if ($affected_rows > 0) {
            $_SESSION['message'] = "Transaksi berhasil dihapus";
            $_SESSION['message_type'] = "success";

            // 3. Hapus juga dari session jika ada
            if (isset($_SESSION['transaction_ref_sewa_billing']) && $_SESSION['transaction_ref_sewa_billing'] == $ref) {
                unset($_SESSION['transaction_ref_sewa_billing']);
            }

            // 4. Tambahkan flag untuk mencegah recreate
            $_SESSION['transaction_deleted'] = true;
        } else {
            $_SESSION['message'] = "Transaksi tidak ditemukan atau sudah dihapus";
            $_SESSION['message_type'] = "warning";
        }
    } else {
        $_SESSION['message'] = "Gagal menghapus: " . mysqli_error($conn);
        $_SESSION['message_type'] = "danger";
    }

    mysqli_stmt_close($stmt);
    header("Location: topup.php");
    exit;
}

// Handle continue payment request
if (isset($_POST['continue_payment'])) {
    $continue_ref = trim($_POST['continue_payment']);

    if (empty($continue_ref)) {
        $_SESSION['message'] = "Reference tidak valid";
        $_SESSION['message_type'] = "danger";
    } else {
        // Verify the transaction belongs to the current user
        $user_query = mysqli_query($conn, "SELECT id FROM user WHERE USERNAME = '" . mysqli_real_escape_string($conn, $ceknama) . "'");
        if ($user_query && mysqli_num_rows($user_query) > 0) {
            $user = mysqli_fetch_assoc($user_query);
            $user_id = $user['id'];

            $check_query = mysqli_query($conn, "SELECT 1 FROM topup WHERE ref = '" . mysqli_real_escape_string($conn, $continue_ref) . "' AND user_id = $user_id");

            if ($check_query && mysqli_num_rows($check_query) > 0) {
                $_SESSION['transaction_ref_sewa_billing'] = $continue_ref;
            } else {
                $_SESSION['message'] = "Transaksi tidak ditemukan atau tidak memiliki akses";
                $_SESSION['message_type'] = "danger";
            }
        } else {
            $_SESSION['message'] = "User tidak valid";
            $_SESSION['message_type'] = "danger";
        }
    }

    echo "<script>window.location.href='topup.php';</script>";
    exit;
}

// Check transaction status if reference exists in session
if (isset($_SESSION['transaction_ref_sewa_billing'])) {
    $reference = $_SESSION['transaction_ref_sewa_billing'];
    $status = cekTransaksi($reference, $tripay_api_key);

    if ($status['success']) {
        $data = $status['data'];
        $checkout_url = $data['checkout_url'];
        $status_transaksi = $data['status'];
        $payment_method = $data['payment_method'] ?? 'unknown';

        // Get user data
        $user_query = mysqli_query($conn, "SELECT id, saldo FROM user WHERE USERNAME = '" . mysqli_real_escape_string($conn, $ceknama) . "'");
        if (mysqli_num_rows($user_query) > 0) {
            $user = mysqli_fetch_assoc($user_query);
            $user_id = $user['id'];
            $balance_before = floatval($user['saldo']);

            if ($status_transaksi === 'PAID') {
                $amount = floatval($data['amount']);
                $days = $nominal_to_days[$amount] ?? 30;
                $balance_after = $balance_before + $amount;




                $expired_at = date('Y-m-d H:i:s', strtotime("+$days days"));
                $update = mysqli_query($conn, "UPDATE user SET expired_at = '$expired_at' WHERE id = $user_id");

                // Update balance history
                $update_history = mysqli_query($conn, "
                    UPDATE topup SET 
                        type = 'topup',
                        amount = $amount,
                        balance_before = $balance_before,
                        balance_after = $balance_after,
                        description = 'Topup via $payment_method',
                        created_at = NOW()
                    WHERE ref = '" . mysqli_real_escape_string($conn, $reference) . "' AND user_id = $user_id
                ");

                if ($update && $update_history) {
                    $success = true;
                    unset($_SESSION['transaction_ref_sewa_billing']);
                } else {
                    $message = "Gagal update saldo/history: " . mysqli_error($conn);
                }
            } else {
                // Save as pending if not exists
                $cek = mysqli_query($conn, "SELECT id FROM topup WHERE ref = '" . mysqli_real_escape_string($conn, $reference) . "' AND user_id = $user_id");
                if (mysqli_num_rows($cek) == 0) {
                    $balance_decimal = number_format($balance_before, 2, '.', '');
                    $amount = floatval($data['amount']);

                    $insert_pending = mysqli_query($conn, "
                        INSERT INTO topup 
                            (user_id, ref, amount, balance_before, balance_after, type, description, created_at) 
                        VALUES (
                            $user_id,
                            '" . mysqli_real_escape_string($conn, $reference) . "',
                            $amount,
                            $balance_decimal,
                            $balance_decimal,
                            'pending',
                            'Topup pending via $payment_method',
                            NOW()
                        )
                    ");

                    if (!$insert_pending) {
                        $message = "Gagal insert pending: " . mysqli_error($conn);
                    }
                }
            }
        }
    } else {
        $message = 'Referensi tidak ditemukan.';
    }
}

// Display pending transactions
$hasPending = false;
$user_query = mysqli_query($conn, "SELECT id FROM user WHERE USERNAME = '" . mysqli_real_escape_string($conn, $ceknama) . "'");
if ($user_query && mysqli_num_rows($user_query) > 0) {
    $user = mysqli_fetch_assoc($user_query);
    $user_id = $user['id'];

    $pending_query = mysqli_query($conn, "SELECT * FROM topup WHERE user_id = $user_id AND type = 'pending'");
    if ($pending_query && mysqli_num_rows($pending_query) > 0) {
        $hasPending = true;
    }
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Top Up - Sewa billingQ</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
        body {
            background: #f4f6fc;
            font-family: 'Poppins', sans-serif;
        }

        .header {
            background: linear-gradient(to right, #f97300, #0077b6);
            color: white;
            padding: 20px;
            border-radius: 16px;
            margin-bottom: 25px;
        }

        .form-select,
        .form-control {
            border-radius: 10px;
        }

        .btn-primary {
            background: #0077b6;
            border: none;
            border-radius: 10px;
        }

        .btn-primary:hover {
            background: #005f91;
        }

        .alert {
            border-radius: 12px;
        }

        .table-responsive {
            overflow-x: auto;
        }
    </style>
</head>

<body class="p-4">
    <div>
        <div class="header text-center">
            <h3>💳 Top Up Saldo</h3>
            <p>Pilih nominal & metode pembayaran favoritmu</p>
        </div>

        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert alert-<?= htmlspecialchars($_SESSION['message_type']) ?>">
                <?= htmlspecialchars($_SESSION['message']) ?>
            </div>
            <?php unset($_SESSION['message']);
            unset($_SESSION['message_type']); ?>
        <?php endif; ?>

        <?php if ($message): ?>
            <div class="alert alert-warning"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php
        // At the very top of your script (before any HTML output)
        if ($success) {

            header("Location: dashboard.php");
            exit();
        }
        ?>

        <?php if (!empty($checkout_url)): ?>
            <?php $instruksi = $status['data']['instructions'] ?? []; ?>
            <div class="alert alert-info p-3">
                <h5>💰 Silakan lakukan pembayaran:</h5>
                <center>
                    <h3 style="color: red;">JANGAN KELUAR DARI HALAMAN INI SAAT PROSES MEMBAYAR !!</h3>
                </center>
<?php


?>
                <?php if (!empty($status['data']['pay_code'])): ?>
                    <div class="mb-2">
                        <strong>🔢 Kode Bayar:</strong>
                        <div class="alert alert-secondary"><?= htmlspecialchars($status['data']['pay_code']) ?></div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($status['data']['qr_url'])): ?>
                    <div class="mb-3 text-center">
                        <strong>🧾 QR / Barcode:</strong><br>
                        <img src="<?= htmlspecialchars($status['data']['qr_url']) ?>" alt="QR Code" style="max-width:200px">
                    </div>
                <?php endif; ?>

                <?php if (!empty($instruksi)): ?>
                    <hr>
                    <h6>📋 Cara Pembayaran:</h6>
                    <ol>
                        <?php foreach ($instruksi as $step): ?>
                            <li><b><?= htmlspecialchars($step['title']) ?></b>
                                <ul>
                                    <?php foreach ($step['steps'] as $s): ?>
                                        <li><?= strip_tags($s) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>

                <hr>
                <div class="d-flex gap-2">
                    <form method="POST" action="">
                        <input type="hidden" name="check_status" value="1">
                        <button type="submit" class="btn btn-warning">🔄 Cek Status Pembayaran</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($hasPending): ?>
            <div class="border p-3 mt-4">
                <h5>Transaksi Pending:</h5>
                <div class="table-responsive d-none d-md-block">
                    <table class="table table-bordered">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Metode</th>
                                <th>Ref ID</th>
                                <th>Jumlah</th>
                                <th>Waktu</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $no = 1;
                            mysqli_data_seek($pending_query, 0);
                            while ($row = mysqli_fetch_assoc($pending_query)):
                            ?>
                                <tr>
                                    <td><?= $no++ ?></td>
                                    <td><?= htmlspecialchars($row['description']) ?></td>
                                   <td>
    <?= htmlspecialchars($row['ref']) ?> (Perpanjang 
    <?php
    $nominal_to_days = $nominalList ;

        $amount3 = floatval($row['amount']);

        // Cari nominal terdekat
        $closest_nominal = null;
        $min_diff = PHP_INT_MAX;
        foreach ($nominal_to_days as $nom => $hari) {
            $diff = abs($amount3 - $nom);
            if ($diff < $min_diff) {
                $min_diff = $diff;
                $closest_nominal = $nom;
            }
        }

        // Ambil jumlah hari sesuai nominal terdekat
        $days = $nominal_to_days[$closest_nominal] ?? 0;

        echo $days;
    ?> Hari kedepan)
</td>

                                    <td>Rp<?= number_format($row['amount'], 0, ',', '.') ?></td>
                                    <td><?= $row['created_at'] ?></td>
                                    <td class="text-nowrap">
                                        <form method="POST" action="" style="display:inline;">
                                            <input type="hidden" name="continue_payment" value="<?= htmlspecialchars($row['ref']) ?>">
                                            <button type="submit" class="btn btn-sm btn-success">💳 Lanjutkan</button>
                                        </form>
                                        <form method="POST" action="" style="display:inline;">
                                            <input type="hidden" name="hapus_pending" value="<?= htmlspecialchars($row['ref']) ?>">
                                            <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Hapus transaksi pending ini?')">🗑️ Hapus</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile View Cards -->
                <div class="d-md-none">
                    <?php
                    mysqli_data_seek($pending_query, 0);
                    while ($row = mysqli_fetch_assoc($pending_query)):
                    ?>
                    <div class="card mb-3">
                        <div class="card-body">
                            <h6 class="card-title">Transaksi #<?= $row['id'] ?></h6>
                            <p class="card-text"><strong>Metode:</strong> <?= htmlspecialchars($row['description']) ?></p>
                            <p class="card-text"><strong>Ref ID:</strong> <?= htmlspecialchars($row['ref']) ?> (Perpanjang 
                            <?php
                            $amount3 = floatval($row['amount']);
                            $closest_nominal = null;
                            $min_diff = PHP_INT_MAX;
                            foreach ($nominalList as $nom => $hari) {
                                $diff = abs($amount3 - $nom);
                                if ($diff < $min_diff) {
                                    $min_diff = $diff;
                                    $closest_nominal = $nom;
                                }
                            }
                            $days = $nominalList[$closest_nominal] ?? 0;
                            echo $days;
                            ?> Hari kedepan)</p>
                            <p class="card-text"><strong>Jumlah:</strong> Rp<?= number_format($row['amount'], 0, ',', '.') ?></p>
                            <p class="card-text"><strong>Waktu:</strong> <?= $row['created_at'] ?></p>
                            <div class="d-flex gap-2">
                                <form method="POST" action="" style="display:inline;">
                                    <input type="hidden" name="continue_payment" value="<?= htmlspecialchars($row['ref']) ?>">
                                    <button type="submit" class="btn btn-sm btn-success">💳 Lanjutkan</button>
                                </form>
                                <form method="POST" action="" style="display:inline;">
                                    <input type="hidden" name="hapus_pending" value="<?= htmlspecialchars($row['ref']) ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Hapus transaksi pending ini?')">🗑️ Hapus</button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!isset($_SESSION['transaction_ref_sewa_billing']) && !$success && !$hasPending): ?>
            <div class="border p-3">
                <form method="POST">
                    <div class="mb-3">
                        <label for="amount" class="form-label">💰 Pilih Nominal</label>
                        <select name="amount" id="amount" class="form-select" required>
                            <option value="">-- Pilih Nominal --</option>
                            
    <?php foreach ($nominalList as $nom => $hari): ?>
        <option value="<?= $nom ?>">
            Rp<?= number_format($nom, 0, ',', '.') ?> Perpanjang <?= $hari ?> hari kedepan
        </option>
    <?php endforeach; ?>

                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="method" class="form-label">🏦 Metode Pembayaran</label>
                        <select name="method" id="method" class="form-select" required>
                            <option value="">-- Pilih Channel Pembayaran --</option>
                            <?php foreach ($channels['data'] ?? [] as $c): ?>
                                <option value="<?= htmlspecialchars($c['code']) ?>">
                                    <?= htmlspecialchars($c['name']) ?> (<?= strtoupper($c['code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="text-end">
                        <button type="submit" name="topup" class="btn btn-primary">💸 Lanjutkan Pembayaran</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>
</body>

</html>

