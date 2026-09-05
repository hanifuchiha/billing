<?php
require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    twk_response(405, ['success' => false, 'message' => 'Method tidak diizinkan.']);
}

$conn = twk_db_connect();
$session = twk_require_auth($conn);
$idpel = (string)$session['idpel'];
$body = twk_get_json_body();

$pelanggan = twk_find_customer($conn, $idpel);
if (!$pelanggan) {
    twk_response(404, ['success' => false, 'message' => 'Data pelanggan tidak ditemukan.']);
}

$pemilik = (string)($pelanggan['PEMILIK'] ?? '');
$defaultGateway = twk_get_default_payment_method($conn, $pemilik);
$gateway = strtolower(trim((string)($body['gateway'] ?? $defaultGateway)));
$methodCode = strtoupper(trim((string)($body['method_code'] ?? '')));
$taxCfg = twk_get_payment_tax_bhp($conn, $pemilik, $defaultGateway);
$pajakRate = (float)($taxCfg['pajak'] ?? 11.0);
$defaultBhpUso = (float)($taxCfg['bhp_uso'] ?? 0.0);
$defaultHargaPaket = twk_find_package_price($conn, $pelanggan);

$validGateway = ['tripay', 'duitku', 'midtrans', 'xendit', 'manual_bank'];
if (!in_array($gateway, $validGateway, true)) {
    twk_response(422, ['success' => false, 'message' => 'Gateway pembayaran tidak valid.']);
}

$checkPending = mysqli_prepare(
    $conn,
    "SELECT BUKTI FROM transaksi WHERE IDPEL = ? AND STATUS = 'PERMINTAAN KODE' ORDER BY id DESC LIMIT 1"
);
if ($checkPending) {
    mysqli_stmt_bind_param($checkPending, 's', $idpel);
    mysqli_stmt_execute($checkPending);
    $pendingRes = mysqli_stmt_get_result($checkPending);
    $pending = $pendingRes ? mysqli_fetch_assoc($pendingRes) : null;
    mysqli_stmt_close($checkPending);

    if ($pending) {
        twk_response(409, [
            'success' => false,
            'message' => 'Masih ada kode pembayaran aktif. Batalkan terlebih dahulu.',
            'data' => [
                'reference' => (string)($pending['BUKTI'] ?? '')
            ]
        ]);
    }
}

$dateOrderExpr = "COALESCE(NULLIF(tanggal_invoice,''), NULLIF(TANGGAL_INVOICE,''), NULLIF(jatuh_tempo,''), NULLIF(JATUH_TEMPO,''), created_at, NOW())";
$sqlUnpaid = "
    SELECT *
    FROM invoice
    WHERE id_pelanggan = ?
            AND (
                TRIM(UPPER(COALESCE(status, ''))) IN ('BELUM BAYAR', 'KONFIRMASI')
                OR TRIM(COALESCE(status, '')) = ''
            )
    ORDER BY $dateOrderExpr DESC
";

$totalAmount = 0.0;
$latestPeriode = '';
$stmtUnpaid = mysqli_prepare($conn, $sqlUnpaid);
if ($stmtUnpaid) {
    mysqli_stmt_bind_param($stmtUnpaid, 's', $idpel);
    mysqli_stmt_execute($stmtUnpaid);
    $resUnpaid = mysqli_stmt_get_result($stmtUnpaid);
    while ($resUnpaid && ($row = mysqli_fetch_assoc($resUnpaid))) {
        $harga = twk_row_amount($row, ['harga', 'HARGA']);
        if ($harga <= 0) {
            $harga = $defaultHargaPaket;
        }
        $ppnRaw = twk_row_amount($row, ['ppn', 'PPN'], -1);
        $ppn = $ppnRaw > 0 ? $ppnRaw : round($harga * ($pajakRate / 100), 2);
        $bhpUso = twk_row_amount($row, ['bhp_uso', 'bhps_uso', 'BHP_USO', 'BHPS_USO']);
        if ($bhpUso <= 0) {
            $bhpUso = $defaultBhpUso;
        }
        $totalRaw = twk_row_amount($row, ['total', 'TOTAL'], -1);
        $lineTotal = $totalRaw >= 0 ? $totalRaw : ($harga + $ppn + $bhpUso);
        $totalAmount += $lineTotal;
        if ($latestPeriode === '') {
            $latestPeriode = (string)twk_row_value($row, ['periode', 'PERIODE'], '');
        }
    }
    mysqli_stmt_close($stmtUnpaid);
}

if ($totalAmount <= 0) {
    $stmtLast = mysqli_prepare($conn, "SELECT * FROM invoice WHERE id_pelanggan = ? ORDER BY $dateOrderExpr DESC LIMIT 1");
    if ($stmtLast) {
        mysqli_stmt_bind_param($stmtLast, 's', $idpel);
        mysqli_stmt_execute($stmtLast);
        $resLast = mysqli_stmt_get_result($stmtLast);
        $inv = $resLast ? mysqli_fetch_assoc($resLast) : null;
        mysqli_stmt_close($stmtLast);
        if ($inv) {
            $harga = twk_row_amount($inv, ['harga', 'HARGA']);
            if ($harga <= 0) {
                $harga = $defaultHargaPaket;
            }
            $ppnRaw = twk_row_amount($inv, ['ppn', 'PPN'], -1);
            $ppn = $ppnRaw > 0 ? $ppnRaw : round($harga * ($pajakRate / 100), 2);
            $bhpUso = twk_row_amount($inv, ['bhp_uso', 'bhps_uso', 'BHP_USO', 'BHPS_USO']);
            if ($bhpUso <= 0) {
                $bhpUso = $defaultBhpUso;
            }
            $totalRaw = twk_row_amount($inv, ['total', 'TOTAL'], -1);
            $totalAmount = $totalRaw >= 0 ? $totalRaw : ($harga + $ppn + $bhpUso);
            $latestPeriode = (string)twk_row_value($inv, ['periode', 'PERIODE'], '');
        }
    }
}

if ($totalAmount <= 0) {
    $fallbackHarga = max(0.0, $defaultHargaPaket);
    $fallbackPpn = round($fallbackHarga * ($pajakRate / 100), 2);
    $fallbackBhpUso = max(0.0, $defaultBhpUso);
    $fallbackTotal = $fallbackHarga + $fallbackPpn + $fallbackBhpUso;
    if ($fallbackTotal > 0) {
        $totalAmount = $fallbackTotal;
        if ($latestPeriode === '') {
            $latestPeriode = date('F Y');
        }
    }
}

if ($totalAmount <= 0) {
    twk_response(422, ['success' => false, 'message' => 'Tidak ada tagihan aktif untuk dibuatkan kode bayar.']);
}

$cfg = twk_load_config();
$domain = (string)($cfg['domain'] ?? 'quenbytekniksejahtera.com');
$customerName = (string)($pelanggan['NAMA'] ?? $idpel);
$customerEmail = trim((string)($pelanggan['EMAIL'] ?? ''));
if ($customerEmail === '') {
    $customerEmail = 'mobile@' . preg_replace('/^www\./', '', $domain);
}
$customerPhone = (string)($pelanggan['NOWA'] ?? '');
$paket = (string)($pelanggan['PAKET'] ?? 'Internet');

if ($gateway === 'tripay') {
    $tripayCfg = twk_get_tripay_config($conn, $pemilik);
    $apiKey = twk_pick((array)$tripayCfg, ['apikey', 'api_key']);
    $privateKey = twk_pick((array)$tripayCfg, ['privatekey', 'private_key']);
    $merchantCode = twk_pick((array)$tripayCfg, ['merchant', 'merchant_code']);
    $ownerCallbackSuffix = strtoupper((string)preg_replace('/[^A-Za-z0-9_-]/', '', $pemilik));
    if ($ownerCallbackSuffix === '') {
        $ownerCallbackSuffix = 'DEFAULT';
    }
    $ownerCallbackUrl = 'https://' . $domain . '/crm/billing/callbacktripay/callback_tripay_' . $ownerCallbackSuffix . '.php';
    $configuredCallbackUrl = trim((string)twk_pick((array)$tripayCfg, ['callback'], ''));
    if ($configuredCallbackUrl === '' || preg_match('/\/callback_tripay\.php$/i', $configuredCallbackUrl)) {
        $configuredCallbackUrl = $ownerCallbackUrl;
    }

    if ($apiKey === '' || $privateKey === '' || $merchantCode === '') {
        twk_response(422, ['success' => false, 'message' => 'Konfigurasi Tripay belum lengkap.']);
    }

    if ($methodCode === '') {
        twk_response(422, ['success' => false, 'message' => 'Pilih metode pembayaran Tripay terlebih dahulu.']);
    }

    // Kirim tagihan pokok. Tripay menambahkan fee_customer berdasarkan kanal;
    // menambahkannya di aplikasi akan membuat pelanggan dikenai biaya dua kali.
    $totalAmount = (int)round(max(0.0, $totalAmount));

    $signature = hash_hmac('sha256', $merchantCode . $idpel . (int)round($totalAmount), $privateKey);
    $payload = [
        'method' => $methodCode,
        'merchant_ref' => $idpel,
        'amount' => (int)round($totalAmount),
        'customer_name' => $customerName,
        'customer_email' => $customerEmail,
        'customer_phone' => $customerPhone,
        'order_items' => [[
            'name' => 'Pembayaran WiFi ' . $paket . ($latestPeriode !== '' ? (' ' . $latestPeriode) : ''),
            'price' => (int)round($totalAmount),
            'quantity' => 1
        ]],
        'callback_url' => $configuredCallbackUrl,
        'return_url' => twk_pick((array)$tripayCfg, ['return'], 'https://' . $domain . '/crm/billing/broadband/portal.php?cari=' . urlencode($customerPhone)),
        'signature' => $signature
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://tripay.co.id/api/transaction/create',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 25,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
    ]);
    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error) {
        twk_response(502, ['success' => false, 'message' => 'Tripay error: ' . $error]);
    }

    $json = json_decode((string)$raw, true);
    $data = is_array($json['data'] ?? null) ? $json['data'] : null;
    if ($httpCode !== 200 || !$data || empty($data['reference'])) {
        twk_response(502, [
            'success' => false,
            'message' => (string)($json['message'] ?? 'Gagal membuat transaksi Tripay.'),
            'debug' => [
                'http_code' => $httpCode,
                'raw' => $raw
            ]
        ]);
    }

    $reference = (string)$data['reference'];
    $paymentName = (string)($data['payment_name'] ?? 'Tripay Payment');
    $payCode = (string)($data['pay_code'] ?? '');
    $checkoutUrl = (string)($data['checkout_url'] ?? '');
    $payUrl = (string)($data['pay_url'] ?? '');
    $qrUrl = (string)($data['qr_url'] ?? '');
    $status = (string)($data['status'] ?? 'UNPAID');
    $amount = (float)($data['amount'] ?? $totalAmount);
    $expiredAt = !empty($data['expired_time']) ? date('Y-m-d H:i:s', (int)$data['expired_time']) : null;
    $instructions = is_array($data['instructions'] ?? null) ? $data['instructions'] : [];

    $tanggal = date('Y-m-d H:i:s');
    $nama = (string)($pelanggan['NAMA'] ?? '');
    $paketNama = (string)($pelanggan['PAKET'] ?? '');
    $insertTrx = mysqli_prepare(
        $conn,
        "INSERT INTO transaksi (TANGGALBAYAR, IDPEL, NAMA, PAKET, HARGA, STATUS, BUKTI, PEMILIK, CEK)
         VALUES (?, ?, ?, ?, ?, 'PERMINTAAN KODE', ?, ?, 'PERMINTAAN')"
    );
    if ($insertTrx) {
        mysqli_stmt_bind_param($insertTrx, 'ssssdss', $tanggal, $idpel, $nama, $paketNama, $amount, $reference, $pemilik);
        mysqli_stmt_execute($insertTrx);
        mysqli_stmt_close($insertTrx);
    }

    twk_payment_insert_local(
        $conn,
        $idpel,
        $reference,
        'tripay',
        $paymentName,
        $payCode,
        $checkoutUrl,
        $payUrl,
        $qrUrl,
        $amount,
        $status,
        $expiredAt,
        $instructions,
        is_array($json) ? $json : []
    );

    twk_response(200, [
        'success' => true,
        'message' => 'Kode pembayaran berhasil dibuat.',
        'data' => [
            'payment' => twk_payment_format_payload([
                'reference' => $reference,
                'gateway' => 'tripay',
                'payment_name' => $paymentName,
                'pay_code' => $payCode,
                'checkout_url' => $checkoutUrl,
                'pay_url' => $payUrl,
                'qr_url' => $qrUrl,
                'amount' => $amount,
                'status' => $status,
                'expired_at' => (string)$expiredAt,
                'instructions' => $instructions
            ])
        ]
    ]);
}

if ($gateway === 'duitku') {
    $duitkuCfg = twk_get_duitku_config($conn, $pemilik);
    $merchantCode = twk_pick((array)$duitkuCfg, ['merchant_code', 'merchant']);
    $apiKey = twk_pick((array)$duitkuCfg, ['api_key', 'apikey']);
    $callbackUrl = twk_pick((array)$duitkuCfg, ['callback_url', 'callback']);
    $returnUrl = twk_pick((array)$duitkuCfg, ['return_url', 'return']);

    if ($merchantCode === '' || $apiKey === '') {
        twk_response(422, ['success' => false, 'message' => 'Konfigurasi Duitku belum lengkap.']);
    }

    if ($methodCode === '') {
        twk_response(422, ['success' => false, 'message' => 'Pilih metode pembayaran Duitku terlebih dahulu.']);
    }

    $orderId = 'INV-' . time() . '-' . $idpel;
    $intAmount = (int)round($totalAmount);
    $signature = hash('sha256', $merchantCode . $idpel . $intAmount . $apiKey);

    $payload = [
        'merchantCode' => $merchantCode,
        'paymentAmount' => $intAmount,
        'paymentMethod' => $methodCode,
        'merchantOrderId' => $orderId,
        'productDetails' => 'Pembayaran WiFi ' . $paket,
        'customerName' => $customerName,
        'customerEmail' => $customerEmail,
        'customerPhone' => $customerPhone,
        'callbackUrl' => $callbackUrl,
        'returnUrl' => $returnUrl,
        'signature' => $signature
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://sandbox.duitku.com/webapi/api/merchant/v2/inquiry',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 25,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
    ]);
    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error) {
        twk_response(502, ['success' => false, 'message' => 'Duitku error: ' . $error]);
    }

    $json = json_decode((string)$raw, true);
    if ($httpCode !== 200 || !is_array($json) || (string)($json['statusCode'] ?? '') !== '00') {
        twk_response(502, [
            'success' => false,
            'message' => (string)($json['statusMessage'] ?? 'Gagal membuat transaksi Duitku.'),
            'debug' => [
                'http_code' => $httpCode,
                'raw' => $raw
            ]
        ]);
    }

    $reference = (string)($json['reference'] ?? $orderId);
    $paymentName = (string)($json['paymentName'] ?? 'Duitku Payment');
    $payCode = (string)($json['vaNumber'] ?? '');
    $checkoutUrl = (string)($json['paymentUrl'] ?? '');
    $payUrl = $checkoutUrl;
    $qrUrl = (string)($json['qrString'] ?? '');
    $status = 'UNPAID';
    $amount = (float)$intAmount;
    $expiredAt = date('Y-m-d H:i:s', time() + (24 * 60 * 60));
    $instructions = [[
        'title' => 'Instruksi Pembayaran Duitku',
        'steps' => [
            'Klik tombol Lihat Checkout untuk melanjutkan pembayaran.',
            'Pilih metode pembayaran yang telah Anda tentukan.',
            'Ikuti instruksi pembayaran yang muncul pada halaman checkout.',
            'Pembayaran akan diverifikasi otomatis oleh sistem.'
        ]
    ]];

    $tanggal = date('Y-m-d H:i:s');
    $nama = (string)($pelanggan['NAMA'] ?? '');
    $paketNama = (string)($pelanggan['PAKET'] ?? '');
    $insertTrx = mysqli_prepare(
        $conn,
        "INSERT INTO transaksi (TANGGALBAYAR, IDPEL, NAMA, PAKET, HARGA, STATUS, BUKTI, PEMILIK, CEK)
         VALUES (?, ?, ?, ?, ?, 'PERMINTAAN KODE', ?, ?, 'DUITKU')"
    );
    if ($insertTrx) {
        mysqli_stmt_bind_param($insertTrx, 'ssssdss', $tanggal, $idpel, $nama, $paketNama, $amount, $reference, $pemilik);
        mysqli_stmt_execute($insertTrx);
        mysqli_stmt_close($insertTrx);
    }

    twk_payment_insert_local(
        $conn,
        $idpel,
        $reference,
        'duitku',
        $paymentName,
        $payCode,
        $checkoutUrl,
        $payUrl,
        $qrUrl,
        $amount,
        $status,
        $expiredAt,
        $instructions,
        is_array($json) ? $json : []
    );

    twk_response(200, [
        'success' => true,
        'message' => 'Kode pembayaran berhasil dibuat.',
        'data' => [
            'payment' => twk_payment_format_payload([
                'reference' => $reference,
                'gateway' => 'duitku',
                'payment_name' => $paymentName,
                'pay_code' => $payCode,
                'checkout_url' => $checkoutUrl,
                'pay_url' => $payUrl,
                'qr_url' => $qrUrl,
                'amount' => $amount,
                'status' => $status,
                'expired_at' => $expiredAt,
                'instructions' => $instructions
            ])
        ]
    ]);
}

if ($gateway === 'manual_bank') {
    $accounts = twk_get_manual_accounts($conn, $pemilik);
    if (empty($accounts)) {
        twk_response(422, ['success' => false, 'message' => 'Rekening manual belum dikonfigurasi.']);
    }

    $acc = $accounts[0];
    $reference = 'MANUAL-' . date('YmdHis') . '-' . $idpel;
    $rekening = (string)($acc['rekening'] ?? '');
    $metode = (string)($acc['metode'] ?? 'Transfer Bank');
    $atasNama = (string)($acc['atas_nama'] ?? '');

    $instructions = [[
        'title' => 'Instruksi Transfer Manual',
        'steps' => [
            'Transfer ke rekening ' . $rekening . ' (' . $metode . ') a.n. ' . $atasNama . '.',
            'Pastikan nominal transfer sesuai total tagihan.',
            'Setelah transfer, kirim bukti pembayaran ke admin untuk verifikasi.',
            'Status pembayaran akan diproses manual oleh admin.'
        ]
    ]];

    twk_response(200, [
        'success' => true,
        'message' => 'Detail pembayaran manual berhasil disiapkan.',
        'data' => [
            'payment' => twk_payment_format_payload([
                'reference' => $reference,
                'gateway' => 'manual_bank',
                'payment_name' => 'Transfer Bank Manual',
                'pay_code' => $rekening,
                'checkout_url' => '',
                'pay_url' => '',
                'qr_url' => '',
                'amount' => $totalAmount,
                'status' => 'UNPAID',
                'expired_at' => '',
                'instructions' => $instructions
            ]),
            'manual_account' => $acc
        ]
    ]);
}

twk_response(422, [
    'success' => false,
    'message' => 'Gateway ' . $gateway . ' belum tersedia pada API mobile.'
]);
