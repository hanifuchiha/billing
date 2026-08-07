<?php
/**
 * test_payment_gateway_callback.php
 *
 * Endpoint publik PASIF -- dipakai sebagai callback_url/notifyUrl saat tombol
 * "Test Transaksi" (proses/test_payment_gateway.php) bikin transaksi test ke
 * gateway asli. SENGAJA tidak menyentuh tabel `transaksi` atau tabel apapun
 * -- cuma catat log supaya kalau ada yg iseng bayar link test (nominal
 * kecil), tidak ada efek samping ke data produksi sama sekali.
 */

$raw = file_get_contents('php://input');
$logLine = '[' . date('Y-m-d H:i:s') . '] ' . $_SERVER['REQUEST_METHOD'] . ' '
    . 'headers=' . json_encode(getallheaders() ?: []) . ' '
    . 'query=' . json_encode($_GET) . ' '
    . 'body=' . substr((string)$raw, 0, 2000) . "\n";
@file_put_contents(__DIR__ . '/test_payment_callback.log', $logLine, FILE_APPEND);

http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['status' => 'ok']);
