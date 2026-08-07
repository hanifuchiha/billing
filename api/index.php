<?php
// Billing API Router
// Endpoint: /crm/billing/api/index.php?route=...

header('Content-Type: application/json');
require_once '../koneksidb.php';

$route = $_GET['route'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

function response($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit();
}

switch ($route) {
    case 'login':
        require_once 'login.php';
        break;
    case 'pelanggan':
        require_once 'pelanggan.php';
        break;
    case 'tagihan':
        require_once 'tagihan.php';
        break;
    case 'pembayaran':
        require_once 'pembayaran.php';
        break;
    case 'grafik_transaksi':
        require_once 'grafik_transaksi.php';
        break;
    case 'cari_pelanggan':
        require_once 'cari_pelanggan.php';
        break;
    case 'paket':
        require_once 'paket.php';
        break;
    case 'transaksi':
        require_once 'transaksi.php';
        break;
    case 'promo':
        require_once 'promo.php';
        break;
    case 'notifikasi':
        require_once 'notifikasi.php';
        break;
    case 'log':
        require_once 'log.php';
        break;
    case 'panduan':
        require_once 'panduan.php';
        break;
    case 'isolir':
        require_once 'isolir.php';
        break;
    case 'topup':
        require_once 'topup.php';
        break;
    case 'server':
        require_once 'server.php';
        break;
    case 'buktibayar':
        require_once 'buktibayar.php';
        break;
    case 'cetak':
        require_once 'cetak.php';
        break;
    case 'upload':
        require_once 'upload.php';
        break;
    // Tambahkan endpoint lain sesuai kebutuhan
    default:
        response(['error' => 'Invalid route'], 404);
}
