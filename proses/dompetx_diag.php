<?php
// Script diagnostik SEMENTARA untuk investigasi "HTTP Error: 502" saat integrasi
// DompetX (lihat portal_bayar.php blok dompetx_submit). Dijalankan LANGSUNG dari
// server produksi (bukan dari sandbox pengembangan) supaya kelihatan jaringan
// yang sebenarnya dipakai server hosting ini saat menghubungi api.dompetx.com --
// hapus file ini setelah selesai diagnosis, ini bukan bagian permanen aplikasi.
require '../cek-sesi.php';

header('Content-Type: text/plain; charset=utf-8');

function dompetxDiagTest($label, $method, $url, $headers = [], $body = null) {
    echo "=== $label ===\n";
    echo "URL: $url\n";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HEADER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_VERBOSE, 0);
    if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body ?? '');
    }
    $resp = curl_exec($ch);
    $info = curl_getinfo($ch);
    $err = curl_error($ch);
    $errno = curl_errno($ch);
    curl_close($ch);

    echo "curl_errno: $errno\n";
    echo "curl_error: " . ($err !== '' ? $err : '(kosong)') . "\n";
    echo "http_code: " . ($info['http_code'] ?? '?') . "\n";
    echo "total_time: " . ($info['total_time'] ?? '?') . "s\n";
    echo "namelookup_time (DNS): " . ($info['namelookup_time'] ?? '?') . "s\n";
    echo "connect_time: " . ($info['connect_time'] ?? '?') . "s\n";
    echo "ssl_verify_result: " . ($info['ssl_verify_result'] ?? '?') . "\n";
    echo "primary_ip: " . ($info['primary_ip'] ?? '?') . "\n";
    if ($resp === false) {
        echo "response: (curl_exec gagal total, false)\n";
    } else {
        $headerSize = $info['header_size'] ?? 0;
        $respHeaders = substr($resp, 0, $headerSize);
        $respBody = substr($resp, $headerSize);
        echo "--- response headers ---\n" . trim($respHeaders) . "\n";
        echo "--- response body (max 1000 char) ---\n" . substr($respBody, 0, 1000) . "\n";
    }
    echo "\n";
}

echo "Server public-facing PHP info:\n";
echo "PHP version: " . phpversion() . "\n";
echo "curl version: " . (function_exists('curl_version') ? (curl_version()['version'] ?? '?') : '(curl tidak ada)') . "\n";
echo "OpenSSL version (curl): " . (function_exists('curl_version') ? (curl_version()['ssl_version'] ?? '?') : '?') . "\n\n";

// Tes 1: GET biasa ke domain utama (tanpa header khusus) -- utk cek apakah
// koneksi outbound server ini ke api.dompetx.com jalan sama sekali.
dompetxDiagTest('GET https://api.dompetx.com (tanpa auth)', 'GET', 'https://api.dompetx.com');

// Tes 2: GET ke endpoint channel (tanpa auth, harusnya kena 401 kalau endpoint
// beneran ada -- bukan 502).
dompetxDiagTest('GET /v1/payments/channel (tanpa auth)', 'GET', 'https://api.dompetx.com/v1/payments/channel');

// Tes 3: pakai kredensial DompetX yg sudah disimpan admin, kalau ada.
$pemilik = $_SESSION['USERNAME'] ?? '';
$serverAkun = $username ?? ($ceknama ?? '');
if ($pemilik !== '' && $serverAkun !== '') {
    $q = "SELECT * FROM dompetx WHERE pemilik = '" . mysqli_real_escape_string($conn, $pemilik) . "' LIMIT 1";
    $r = @mysqli_query($conn, $q);
    if ($r && mysqli_num_rows($r) > 0) {
        $cfg = mysqli_fetch_assoc($r);
        $apiKey = $cfg['api_key'];
        $secret = !empty($cfg['secret_key']) ? $cfg['secret_key'] : $apiKey;
        $timestamp = (string) time();
        $sig = hash_hmac('sha256', $timestamp . '.', $secret);
        dompetxDiagTest(
            'GET /v1/payments/channel (DENGAN auth, API key tersimpan)',
            'GET',
            'https://api.dompetx.com/v1/payments/channel',
            [
                'X-DOMPAY-API-Key: ' . $apiKey,
                'X-DOMPAY-Timestamp: ' . $timestamp,
                'X-DOMPAY-Signature: ' . $sig,
            ]
        );
    } else {
        echo "(Tidak ada baris konfigurasi dompetx utk pemilik=$pemilik, tes 3 dilewati)\n";
    }
} else {
    echo "(Sesi USERNAME/server akun tidak terbaca, tes 3 dilewati)\n";
}

echo "\nSELESAI. Kirim hasil di atas (boleh sensor bagian X-DOMPAY-API-Key kalau khawatir) untuk didiagnosis.\n";
