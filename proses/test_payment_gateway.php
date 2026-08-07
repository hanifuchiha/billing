<?php
/**
 * test_payment_gateway.php
 *
 * Endpoint AJAX utk tombol "Test Transaksi" di paymentset.php. Membuat
 * transaksi test NYATA ke gateway asli (nominal kecil Rp 1.000, reference
 * diprefix TEST-) supaya admin bisa verifikasi kredensial+integrasi
 * beneran jalan. TIDAK insert apapun ke tabel `transaksi` -- hasil cuma
 * dikembalikan sbg JSON utk ditampilkan di modal.
 *
 * Logika request per gateway SENGAJA disalin persis dari kode yang sudah
 * terbukti jalan di broadband/portal_bayar.php & broadband/cek_sesi.php
 * (endpoint, urutan payload, algoritma signature) -- cuma field
 * nominal/reference/data-customer yang diganti nilai test.
 */
require '../cek-sesi.php';
require_once __DIR__ . '/../dompetx_helper.php';

header('Content-Type: application/json; charset=utf-8');

// Kredensial payment gateway sensitif -- owner-only, sama pola paymentset.php.
if ($AKSES === 'ASSISTANT') {
    echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki akses ke Payment Setting.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$configFile = __DIR__ . '/../config.json';
$appConfig = file_exists($configFile) ? json_decode((string)file_get_contents($configFile), true) : [];
$domain = (string)($appConfig['domain'] ?? $_SERVER['HTTP_HOST'] ?? '');

$gateway = strtolower(trim((string)($_POST['gateway'] ?? '')));
$action = trim((string)($_POST['action'] ?? 'test'));
$testMethod = trim((string)($_POST['test_method'] ?? ''));

// --- Aksi khusus: ambil daftar channel Tripay (utk dropdown di modal) ---
if ($action === 'channels') {
    if ($gateway !== 'tripay') {
        echo json_encode(['success' => false, 'message' => 'Fetch channel cuma didukung utk Tripay.']);
        exit;
    }
    $stmt = $conn->prepare("SELECT apikey FROM tripay WHERE pemilik = ? LIMIT 1");
    $stmt->bind_param('s', $ceknama);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || empty($row['apikey'])) {
        echo json_encode(['success' => false, 'message' => 'API Key Tripay belum diisi.']);
        exit;
    }
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://tripay.co.id/api/merchant/payment-channel');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $row['apikey'], 'Content-Type: application/json']);
    $response = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);
    $decoded = json_decode((string)$response, true);
    $channels = [];
    if (is_array($decoded['data'] ?? null)) {
        foreach ($decoded['data'] as $chRow) {
            if (!empty($chRow['code']) && !empty($chRow['active'])) {
                $channels[] = ['code' => $chRow['code'], 'name' => $chRow['name'] ?? $chRow['code']];
            }
        }
    }
    if ($curlErr) {
        echo json_encode(['success' => false, 'message' => 'Gagal ambil daftar channel Tripay: ' . $curlErr]);
        exit;
    }
    echo json_encode(['success' => true, 'channels' => $channels]);
    exit;
}

// --- Aksi utama: bikin transaksi test ---
$callbackUrl = 'https://' . $domain . '/crm/billing/proses/test_payment_gateway_callback.php';
$returnUrl = 'https://' . $domain . '/crm/billing/paymentset.php';

$testRef = 'T' . time() . rand(1000, 9999);
// Nominal test bisa diatur admin dari modal (default Rp 1.000 kalau kosong/tidak valid).
$testAmount = (int)($_POST['test_amount'] ?? 1000);
if ($testAmount < 100) {
    $testAmount = 1000;
}
$testName = 'Test Transaksi';
$testEmail = 'test@' . ($domain !== '' ? $domain : 'example.com');
$testPhone = '081234567890';

$result = [
    'success' => false,
    'message' => 'Gateway tidak dikenal.',
    'checkout_url' => '',
    'pay_url' => '',
    'qr_url' => '',
    'va_number' => '',
    'raw_request' => null,
    'raw_response' => null,
    'warning' => '',
];

switch ($gateway) {
    case 'tripay':
        $stmt = $conn->prepare("SELECT apikey, privatekey, merchant FROM tripay WHERE pemilik = ? LIMIT 1");
        $stmt->bind_param('s', $ceknama);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || empty($row['apikey']) || empty($row['privatekey']) || empty($row['merchant'])) {
            $result['message'] = 'Konfigurasi Tripay belum lengkap.';
            break;
        }
        if ($testMethod === '') {
            $result['message'] = 'Pilih metode pembayaran (channel) Tripay dulu.';
            break;
        }

        $signature = hash_hmac('sha256', $row['merchant'] . $testRef . $testAmount, $row['privatekey']);
        $data = [
            'method' => $testMethod,
            'merchant_ref' => $testRef,
            'amount' => $testAmount,
            'customer_name' => $testName,
            'customer_email' => $testEmail,
            'customer_phone' => $testPhone,
            'order_items' => [[
                'name' => 'Test Transaksi Simulasi',
                'price' => $testAmount,
                'quantity' => 1,
            ]],
            'callback_url' => $callbackUrl,
            'return_url' => $returnUrl,
            'signature' => $signature,
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://tripay.co.id/api/transaction/create');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $row['apikey'], 'Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $response = curl_exec($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);
        $decoded = json_decode((string)$response, true);
        $result['raw_request'] = $data;
        $result['raw_response'] = $decoded !== null ? $decoded : $response;

        if ($curlErr) {
            $result['message'] = 'CURL Error: ' . $curlErr;
        } elseif (isset($decoded['data']['reference'])) {
            $result['success'] = true;
            $result['message'] = 'Transaksi test Tripay berhasil dibuat.';
            $result['checkout_url'] = $decoded['data']['checkout_url'] ?? '';
            $result['pay_url'] = $decoded['data']['pay_url'] ?? '';
            $result['qr_url'] = $decoded['data']['qr_url'] ?? '';
            $result['va_number'] = $decoded['data']['pay_code'] ?? '';
        } else {
            $result['message'] = 'Tripay Error: ' . ($decoded['message'] ?? 'Gagal membuat transaksi test.');
        }
        break;

    case 'duitku':
        $stmt = $conn->prepare("SELECT merchant_code, api_key FROM duitku WHERE server = ? AND pemilik = ? LIMIT 1");
        $stmt->bind_param('ss', $ceknama, $ceknama);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || empty($row['api_key']) || empty($row['merchant_code'])) {
            $result['message'] = 'Konfigurasi Duitku belum lengkap.';
            break;
        }

        $method = $testMethod !== '' ? $testMethod : 'VC';
        $orderId = 'TEST-' . time() . '-' . $testRef;
        // Signature Duitku pakai reference MENTAH ($testRef), bukan order_id
        // yang sudah diberi prefix -- sama persis pola portal_bayar.php:1222.
        $signature = hash('sha256', $row['merchant_code'] . $testRef . $testAmount . $row['api_key']);
        $data = [
            'merchantCode' => $row['merchant_code'],
            'paymentAmount' => $testAmount,
            'paymentMethod' => $method,
            'merchantOrderId' => $orderId,
            'productDetails' => 'Test Transaksi Simulasi',
            'customerName' => $testName,
            'customerEmail' => $testEmail,
            'customerPhone' => $testPhone,
            'callbackUrl' => $callbackUrl,
            'returnUrl' => $returnUrl,
            'signature' => $signature,
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://sandbox.duitku.com/webapi/api/merchant/v2/inquiry');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $response = curl_exec($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);
        $decoded = json_decode((string)$response, true);
        $result['raw_request'] = $data;
        $result['raw_response'] = $decoded !== null ? $decoded : $response;

        if ($curlErr) {
            $result['message'] = 'CURL Error: ' . $curlErr;
        } elseif (isset($decoded['statusCode']) && $decoded['statusCode'] == '00') {
            $result['success'] = true;
            $result['message'] = 'Transaksi test Duitku (sandbox) berhasil dibuat.';
            $result['checkout_url'] = $decoded['paymentUrl'] ?? '';
            $result['pay_url'] = $result['checkout_url'];
            $result['va_number'] = $decoded['vaNumber'] ?? '';
            if (!empty($decoded['qrString'])) {
                $result['qr_url'] = 'https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=' . urlencode($decoded['qrString']);
            }
        } else {
            $result['message'] = 'Duitku Error: ' . ($decoded['statusMessage'] ?? 'Gagal membuat transaksi test (cek raw_response).');
        }
        break;

    case 'midtrans':
        $stmt = $conn->prepare("SELECT server_key, server FROM midtrans WHERE server = ? AND pemilik = ? LIMIT 1");
        $stmt->bind_param('ss', $ceknama, $ceknama);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || empty($row['server_key'])) {
            $result['message'] = 'Konfigurasi Midtrans (server key) belum diatur.';
            break;
        }

        $orderId = 'TEST-' . time() . '-' . $testRef;
        $data = [
            'transaction_details' => ['order_id' => $orderId, 'gross_amount' => $testAmount],
            'customer_details' => ['first_name' => $testName, 'email' => $testEmail, 'phone' => $testPhone],
            'item_details' => [[
                'id' => $testRef,
                'price' => $testAmount,
                'quantity' => 1,
                'name' => 'Test Transaksi Simulasi',
            ]],
            'callbacks' => ['finish' => $returnUrl],
        ];
        $isProduction = strtolower(trim((string)($row['server'] ?? ''))) === 'production';
        $baseUrl = $isProduction ? 'https://app.midtrans.com' : 'https://app.sandbox.midtrans.com';
        $auth = base64_encode($row['server_key'] . ':');

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $baseUrl . '/snap/v1/transactions',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'Authorization: Basic ' . $auth],
        ]);
        $response = curl_exec($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);
        $decoded = json_decode((string)$response, true);
        $result['raw_request'] = $data;
        $result['raw_response'] = $decoded !== null ? $decoded : $response;

        if ($curlErr) {
            $result['message'] = 'CURL Error: ' . $curlErr;
        } elseif (!empty($decoded['redirect_url'])) {
            $result['success'] = true;
            $result['message'] = 'Transaksi test Midtrans (' . ($isProduction ? 'PRODUCTION -- HATI-HATI' : 'sandbox') . ') berhasil dibuat.';
            $result['checkout_url'] = $decoded['redirect_url'];
            $result['pay_url'] = $decoded['redirect_url'];
        } else {
            $result['message'] = 'Midtrans Error: ' . ($decoded['error_messages'][0] ?? 'Gagal membuat transaksi test.');
        }
        break;

    case 'xendit':
        $stmt = $conn->prepare("SELECT server_key FROM xendit WHERE server = ? AND pemilik = ? LIMIT 1");
        $stmt->bind_param('ss', $ceknama, $ceknama);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || empty($row['server_key'])) {
            $result['message'] = 'Konfigurasi Xendit (server key) belum diatur.';
            break;
        }

        $externalId = 'TEST-' . time() . '-' . $testRef;
        $data = [
            'external_id' => $externalId,
            'amount' => $testAmount,
            'description' => 'Test Transaksi Simulasi',
            'payer_email' => $testEmail,
            'customer' => ['given_names' => $testName, 'email' => $testEmail, 'mobile_number' => $testPhone],
            'success_redirect_url' => $returnUrl,
        ];
        $auth = base64_encode($row['server_key'] . ':');

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://api.xendit.co/v2/invoices',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Basic ' . $auth],
        ]);
        $response = curl_exec($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);
        $decoded = json_decode((string)$response, true);
        $result['raw_request'] = $data;
        $result['raw_response'] = $decoded !== null ? $decoded : $response;
        $result['warning'] = 'Xendit tidak punya field callback per-request -- webhook (kalau sudah diatur) tetap ke URL yang dikonfigurasi di dashboard Xendit Anda, bukan endpoint test ini.';

        if ($curlErr) {
            $result['message'] = 'CURL Error: ' . $curlErr;
        } elseif (!empty($decoded['invoice_url'])) {
            $result['success'] = true;
            $result['message'] = 'Transaksi test Xendit berhasil dibuat.';
            $result['checkout_url'] = $decoded['invoice_url'];
            $result['pay_url'] = $decoded['invoice_url'];
        } else {
            $result['message'] = 'Xendit Error: ' . ($decoded['message'] ?? 'Gagal membuat transaksi test.');
        }
        break;

    case 'ipaymu':
        $stmt = $conn->prepare("SELECT va, api_key FROM ipaymu WHERE server = ? AND pemilik = ? LIMIT 1");
        $stmt->bind_param('ss', $ceknama, $ceknama);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || empty($row['va']) || empty($row['api_key'])) {
            $result['message'] = 'Konfigurasi iPaymu belum lengkap.';
            break;
        }

        $referenceId = 'TEST-' . time() . '-' . $testRef;
        $bodyArray = [
            'product' => ['Test Transaksi Simulasi'],
            'qty' => [1],
            'price' => [$testAmount],
            'amount' => $testAmount,
            'referenceId' => $referenceId,
            'buyerName' => $testName,
            'buyerEmail' => $testEmail,
            'buyerPhone' => $testPhone,
            'notifyUrl' => $callbackUrl,
            'returnUrl' => $returnUrl,
            'cancelUrl' => $returnUrl,
        ];
        $rawBody = json_encode($bodyArray, JSON_UNESCAPED_SLASHES);
        $bodyHash = strtolower(hash('sha256', $rawBody));
        $stringToSign = 'POST:' . $row['va'] . ':' . $bodyHash . ':' . $row['api_key'];
        $signature = hash_hmac('sha256', $stringToSign, $row['api_key']);
        $timestamp = date('YmdHis');

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://my.ipaymu.com/api/v2/payment',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $rawBody,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'va: ' . $row['va'],
                'signature: ' . $signature,
                'timestamp: ' . $timestamp,
            ],
        ]);
        $response = curl_exec($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);
        $decoded = json_decode((string)$response, true);
        $result['raw_request'] = $bodyArray;
        $result['raw_response'] = $decoded !== null ? $decoded : $response;

        if ($curlErr) {
            $result['message'] = 'CURL Error: ' . $curlErr;
        } elseif (isset($decoded['Status']) && (int)$decoded['Status'] === 1) {
            $result['success'] = true;
            $result['message'] = 'Transaksi test iPaymu berhasil dibuat.';
            $result['checkout_url'] = $decoded['Data']['Url'] ?? '';
            $result['pay_url'] = $result['checkout_url'];
        } else {
            $result['message'] = 'iPaymu Error: ' . ($decoded['Message'] ?? 'Gagal membuat transaksi test.');
        }
        break;

    case 'doku':
        $stmt = $conn->prepare("SELECT client_id, secret_key FROM doku WHERE server = ? AND pemilik = ? LIMIT 1");
        $stmt->bind_param('ss', $ceknama, $ceknama);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || empty($row['client_id']) || empty($row['secret_key'])) {
            $result['message'] = 'Konfigurasi DOKU belum lengkap.';
            break;
        }

        $invoiceNumber = 'TEST-' . time() . '-' . $testRef;
        $path = '/checkout/v1/payment';
        $requestId = uniqid('', true);
        $timestamp = gmdate('Y-m-d\TH:i:s\Z');
        $bodyArray = [
            'order' => [
                'invoice_number' => $invoiceNumber,
                'amount' => $testAmount,
                'callback_url' => $callbackUrl,
                'callback_url_cancel' => $callbackUrl,
            ],
            'payment' => ['payment_due_date' => 1440],
            'customer' => ['name' => $testName, 'email' => $testEmail],
        ];
        $body = json_encode($bodyArray);
        $digest = base64_encode(hash('sha256', $body, true));
        $rawSignature = "Client-Id:{$row['client_id']}\nRequest-Id:$requestId\nRequest-Timestamp:$timestamp\nRequest-Target:$path\nDigest:$digest";
        $signature = 'HMACSHA256=' . base64_encode(hash_hmac('sha256', $rawSignature, $row['secret_key'], true));

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://api.doku.com' . $path,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Client-Id: ' . $row['client_id'],
                'Request-Id: ' . $requestId,
                'Request-Timestamp: ' . $timestamp,
                'Signature: ' . $signature,
            ],
        ]);
        $response = curl_exec($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);
        $decoded = json_decode((string)$response, true);
        $result['raw_request'] = $bodyArray;
        $result['raw_response'] = $decoded !== null ? $decoded : $response;

        if ($curlErr) {
            $result['message'] = 'CURL Error: ' . $curlErr;
        } elseif (!empty($decoded['response']['payment']['url'])) {
            $result['success'] = true;
            $result['message'] = 'Transaksi test DOKU berhasil dibuat.';
            $result['checkout_url'] = $decoded['response']['payment']['url'];
            $result['pay_url'] = $result['checkout_url'];
        } else {
            $result['message'] = 'DOKU Error: ' . ($decoded['error']['message'] ?? 'Gagal membuat transaksi test.');
        }
        break;

    case 'faspay':
        $stmt = $conn->prepare("SELECT merchant_id, user_id, password FROM faspay WHERE server = ? AND pemilik = ? LIMIT 1");
        $stmt->bind_param('ss', $ceknama, $ceknama);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || empty($row['merchant_id']) || empty($row['user_id']) || empty($row['password'])) {
            $result['message'] = 'Konfigurasi Faspay belum lengkap.';
            break;
        }

        $channel = $testMethod !== '' ? $testMethod : '201';
        $billNo = 'TEST-' . time() . '-' . $testRef;
        $signature = sha1(md5($row['user_id'] . $row['password'] . $billNo));
        $data = [
            'request' => 'Post Data',
            'merchant_id' => $row['merchant_id'],
            'merchant' => $row['merchant_id'],
            'bill_no' => $billNo,
            'bill_reff' => $billNo,
            'bill_date' => date('Y-m-d H:i:s'),
            'bill_expired' => date('Y-m-d H:i:s', strtotime('+24 hours')),
            'bill_desc' => 'Test Transaksi Simulasi',
            'bill_currency' => 'IDR',
            'bill_gross' => (string)$testAmount,
            'cust_no' => 'TEST',
            'cust_name' => $testName,
            'payment_channel' => $channel,
            'pay_type' => '1',
            'bank_userid' => '',
            'terminal' => '10',
            'signature' => $signature,
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://web.faspay.co.id/cvr/300011/10/post',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);
        $response = curl_exec($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);
        $decoded = json_decode((string)$response, true);
        $result['raw_request'] = $data;
        $result['raw_response'] = $decoded !== null ? $decoded : $response;
        $result['warning'] = 'Endpoint Faspay ini masih PLACEHOLDER di kode (path gateway_id/channel contoh, belum tentu path resmi akun Anda -- lihat komentar di portal_bayar.php). Kegagalan di sini bisa jadi krn path-nya, bukan krn kredensial salah. Faspay juga tidak punya field callback per-request.';

        if ($curlErr) {
            $result['message'] = 'CURL Error: ' . $curlErr;
        } elseif (isset($decoded['response_code']) && $decoded['response_code'] === '00') {
            $result['success'] = true;
            $result['message'] = 'Transaksi test Faspay berhasil dibuat.';
            $result['va_number'] = $decoded['virtual_account'] ?? '';
            $result['checkout_url'] = $decoded['redirect_url'] ?? '';
            $result['pay_url'] = $result['checkout_url'];
        } else {
            $result['message'] = 'Faspay Error: ' . ($decoded['response_desc'] ?? 'Gagal membuat transaksi test (kemungkinan krn endpoint placeholder).');
        }
        break;

    case 'dompetx':
        $stmt = $conn->prepare("SELECT api_key, secret_key FROM dompetx WHERE server = ? AND pemilik = ? LIMIT 1");
        $stmt->bind_param('ss', $ceknama, $ceknama);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || empty($row['api_key'])) {
            $result['message'] = 'Konfigurasi DompetX (API key) belum diatur.';
            break;
        }

        $secret = !empty($row['secret_key']) ? $row['secret_key'] : $row['api_key'];
        $method = $testMethod !== '' ? $testMethod : 'QRIS';
        $reference = 'TEST-' . time() . '-' . $testRef;
        $data = [
            'method' => $method,
            'amount' => $testAmount,
            'currency' => 'IDR',
            'reference' => $reference,
            'redirectUrl' => $returnUrl,
            'metadata' => [
                'order_name' => 'Test Transaksi Simulasi',
                'product_name' => 'Test',
                'customer_name' => $testName,
                'customer_email' => $testEmail,
                'customer_phone' => $testPhone,
            ],
        ];
        $resp = dompetx_request('POST', '/v1/payments', $row['api_key'], $secret, $data);
        $result['raw_request'] = $data;
        $result['raw_response'] = $resp['data'] !== null ? $resp['data'] : $resp['raw'];
        $result['warning'] = 'Nama field nomor VA DompetX belum terverifikasi resmi (dokumentasi API memblokir akses otomatis) -- kalau transaksi sukses tapi va_number kosong, cek raw_response manual.';

        if (!$resp['ok']) {
            $result['message'] = 'DompetX Error: ' . ($resp['data']['message'] ?? ($resp['curl_error'] !== '' ? $resp['curl_error'] : ('HTTP ' . $resp['http_code'])));
        } elseif (!empty($resp['data']['id'])) {
            $result['success'] = true;
            $result['message'] = 'Transaksi test DompetX berhasil dibuat.';
            $result['qr_url'] = $resp['data']['qrData']['qrImage'] ?? '';

            $vaCandidates = ['virtualAccount', 'vaNumber', 'va_number', 'accountNumber', 'account_number', 'payCode', 'pay_code', 'paymentCode'];
            foreach ($vaCandidates as $key) {
                if (!empty($resp['data'][$key])) {
                    $result['va_number'] = (string)$resp['data'][$key];
                    break;
                }
            }
            if ($result['va_number'] === '' && !empty($resp['data']['vaData']) && is_array($resp['data']['vaData'])) {
                foreach (['accountNumber', 'virtualAccount', 'vaNumber'] as $key) {
                    if (!empty($resp['data']['vaData'][$key])) {
                        $result['va_number'] = (string)$resp['data']['vaData'][$key];
                        break;
                    }
                }
            }
        } else {
            $result['message'] = 'DompetX Error: respons tidak dikenali (cek raw_response).';
        }
        break;
}

echo json_encode($result);
