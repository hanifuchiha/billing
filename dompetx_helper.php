<?php
// Helper request ke API DompetX (https://api.dompetx.com/v1).
//
// CATATAN soal akurasi dokumentasi: docs.dompetx.com memblokir akses otomatis
// biasa (403). Detail di bawah TERKONFIRMASI ULANG 2026-08-02 lewat proxy
// reader terhadap docs.dompetx.com/api-reference/introduction -- base URL,
// nama header, dan skema signature di bawah PERSIS SAMA dengan yang sudah
// diimplementasikan di sini, jadi bukan penyebab kalau ada error 502/dsb saat
// panggil API (cek jaringan/outbound server dulu). Yang SUDAH dikonfirmasi:
//   - Base URL: https://api.dompetx.com
//   - Header: X-DOMPAY-API-Key, X-DOMPAY-Timestamp, X-DOMPAY-Signature,
//     Idempotency-Key (opsional, UUID)
//   - Signature = HMAC-SHA256(timestamp . '.' . rawBody, secret)
//   - POST /v1/payments -> buat transaksi (method, amount, currency, reference,
//     settlementSpeed?, redirectUrl?, metadata{...})
//   - GET /v1/payments/detail/{id} -> detail transaksi (id, status, amount,
//     currency, type, provider, createdAt, expiresAt, qrData{qrString,qrImage,refId})
//   - GET /v1/payments/check-status/{id} dan
//     GET /v1/payments/check-status?reference={reference} -> cek status
//   - GET /v1/payments/channel -> daftar channel aktif (id, name, code,
//     image_url, status, fee_info{...})
//   - GET /v1/qr/{paymentId} -> file image QRIS
// Yang MASIH BELUM terkonfirmasi eksplisit dari dokumentasi (halaman webhook
// di docs.dompetx.com masih selalu 404/403 lewat semua percobaan, termasuk
// via proxy reader 2026-08-02): payload & header signature untuk
// callback/webhook. Diasumsikan SAMA dengan skema signing request biasa
// (X-DOMPAY-Signature/X-DOMPAY-Timestamp, HMAC-SHA256 dari timestamp.body),
// dan field status/reference pada body callback mengikuti bentuk response
// "Get Payment Detail" di atas. WAJIB diverifikasi ulang begitu ada akun
// sandbox DompetX sungguhan -- lihat callbackdompetx/callback_dompetx.php.

if (!function_exists('dompetx_signature')) {
    function dompetx_signature($secret, $timestamp, $rawBody) {
        return hash_hmac('sha256', $timestamp . '.' . $rawBody, (string) $secret);
    }
}

if (!function_exists('dompetx_request')) {
    /**
     * @param string $method  'GET' atau 'POST'
     * @param string $path    contoh: '/v1/payments', '/v1/payments/channel'
     * @param string $apiKey  X-DOMPAY-API-Key
     * @param string $secret  secret HMAC (pakai secret_key kalau diisi admin, else api_key)
     * @param array|null $bodyArray body request (untuk POST); null untuk GET tanpa body
     * @return array{ok:bool,http_code:int,data:array|null,raw:string,curl_error:string}
     */
    function dompetx_request($method, $path, $apiKey, $secret, $bodyArray = null) {
        $timestamp = (string) time();
        $rawBody = $bodyArray !== null ? json_encode($bodyArray, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
        $signature = dompetx_signature($secret, $timestamp, $rawBody);

        $headers = [
            'X-DOMPAY-API-Key: ' . $apiKey,
            'X-DOMPAY-Timestamp: ' . $timestamp,
            'X-DOMPAY-Signature: ' . $signature,
            'Idempotency-Key: ' . bin2hex(random_bytes(16)),
            'Content-Type: application/json',
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.dompetx.com' . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $rawBody);
        }

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = null;
        if ($response !== false && $response !== '') {
            $decoded = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $decoded = null;
            }
        }

        return [
            'ok' => ($curlError === '' && $httpCode >= 200 && $httpCode < 300 && $decoded !== null),
            'http_code' => $httpCode,
            'data' => $decoded,
            'raw' => (string) $response,
            'curl_error' => $curlError,
        ];
    }
}
