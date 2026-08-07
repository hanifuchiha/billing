<?php
require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    twk_response(405, ['success' => false, 'message' => 'Method tidak diizinkan.']);
}

$conn = twk_db_connect();
$session = twk_require_auth($conn);
$idpel = (string)$session['idpel'];

$pelanggan = twk_find_customer($conn, $idpel);
if (!$pelanggan) {
    twk_response(404, ['success' => false, 'message' => 'Data pelanggan tidak ditemukan.']);
}

$stmt = mysqli_prepare(
    $conn,
    "SELECT * FROM transaksi WHERE IDPEL = ? AND STATUS = 'PERMINTAAN KODE' ORDER BY id DESC LIMIT 1"
);
if (!$stmt) {
    twk_response(500, ['success' => false, 'message' => 'Gagal membaca transaksi pembayaran.']);
}

mysqli_stmt_bind_param($stmt, 's', $idpel);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$trx = $res ? mysqli_fetch_assoc($res) : null;
mysqli_stmt_close($stmt);

if (!$trx) {
    twk_response(200, [
        'success' => true,
        'message' => 'Belum ada transaksi pembayaran aktif.',
        'data' => [
            'has_active_payment' => false,
            'payment' => null,
            'cancel_note' => 'Silakan buat pembayaran baru dari menu Bayar.'
        ]
    ]);
}

$reference = (string)($trx['BUKTI'] ?? '');
$cek = strtoupper(trim((string)($trx['CEK'] ?? '')));
$gateway = ($cek === 'DUITKU') ? 'duitku' : 'tripay';

$local = $reference !== '' ? twk_payment_get_local_by_reference($conn, $reference) : null;
if ($local) {
    twk_response(200, [
        'success' => true,
        'message' => 'Transaksi pembayaran aktif ditemukan.',
        'data' => [
            'has_active_payment' => true,
            'payment' => twk_payment_format_payload($local),
            'cancel_note' => 'Jika ingin mengubah metode pembayaran, silakan batalkan pembayaran ini terlebih dahulu.'
        ]
    ]);
}

if ($gateway === 'tripay' && $reference !== '') {
    $pemilik = (string)($pelanggan['PEMILIK'] ?? '');
    $cfg = twk_get_tripay_config($conn, $pemilik);
    $apiKey = twk_pick((array)$cfg, ['apikey', 'api_key']);

    if ($apiKey !== '') {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://tripay.co.id/api/transaction/detail?' . http_build_query(['reference' => $reference]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey],
            CURLOPT_TIMEOUT => 20,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
        ]);
        $raw = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if (!$error && !empty($raw)) {
            $json = json_decode($raw, true);
            $data = is_array($json['data'] ?? null) ? $json['data'] : null;
            if ($data) {
                $instructions = is_array($data['instructions'] ?? null) ? $data['instructions'] : [];
                $expiredAt = '';
                if (!empty($data['expired_time'])) {
                    $expiredAt = date('Y-m-d H:i:s', (int)$data['expired_time']);
                }

                $mapped = [
                    'reference' => (string)($data['reference'] ?? $reference),
                    'gateway' => 'tripay',
                    'payment_name' => (string)($data['payment_name'] ?? 'Tripay Payment'),
                    'pay_code' => (string)($data['pay_code'] ?? ''),
                    'checkout_url' => (string)($data['checkout_url'] ?? ''),
                    'pay_url' => (string)($data['pay_url'] ?? ''),
                    'qr_url' => (string)($data['qr_url'] ?? ''),
                    'amount' => (float)($data['amount'] ?? 0),
                    'status' => (string)($data['status'] ?? 'UNPAID'),
                    'expired_at' => $expiredAt,
                    'instructions' => $instructions
                ];

                twk_payment_insert_local(
                    $conn,
                    $idpel,
                    $mapped['reference'],
                    'tripay',
                    $mapped['payment_name'],
                    $mapped['pay_code'],
                    $mapped['checkout_url'],
                    $mapped['pay_url'],
                    $mapped['qr_url'],
                    (float)$mapped['amount'],
                    $mapped['status'],
                    $mapped['expired_at'],
                    $mapped['instructions'],
                    $json
                );

                twk_response(200, [
                    'success' => true,
                    'message' => 'Transaksi pembayaran aktif ditemukan.',
                    'data' => [
                        'has_active_payment' => true,
                        'payment' => twk_payment_format_payload($mapped),
                        'cancel_note' => 'Jika ingin mengubah metode pembayaran, silakan batalkan pembayaran ini terlebih dahulu.'
                    ]
                ]);
            }
        }
    }
}

$fallback = [
    'reference' => $reference,
    'gateway' => $gateway,
    'payment_name' => strtoupper($gateway) . ' Payment',
    'pay_code' => '',
    'checkout_url' => '',
    'pay_url' => '',
    'qr_url' => '',
    'amount' => (float)($trx['HARGA'] ?? 0),
    'status' => 'UNPAID',
    'expired_at' => '',
    'instructions' => [[
        'title' => 'Instruksi Pembayaran',
        'steps' => [
            'Kode pembayaran sudah dibuat di sistem.',
            'Silakan hubungi admin jika detail kode bayar belum tampil di aplikasi.',
            'Anda dapat membatalkan kode ini lalu buat ulang jika diperlukan.'
        ]
    ]]
];

twk_response(200, [
    'success' => true,
    'message' => 'Transaksi pembayaran aktif ditemukan.',
    'data' => [
        'has_active_payment' => true,
        'payment' => twk_payment_format_payload($fallback),
        'cancel_note' => 'Jika ingin mengubah metode pembayaran, silakan batalkan pembayaran ini terlebih dahulu.'
    ]
]);
