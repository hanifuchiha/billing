<?php
require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    twk_response(405, ['success' => false, 'message' => 'Method tidak diizinkan.']);
}

$conn = twk_db_connect();
$session = twk_require_auth($conn);

$pelanggan = twk_find_customer($conn, (string)$session['idpel']);
if (!$pelanggan) {
    twk_response(404, ['success' => false, 'message' => 'Data pelanggan tidak ditemukan.']);
}

twk_response(200, [
    'success' => true,
    'message' => 'Data profil berhasil diambil.',
    'data' => [
        'customer' => twk_customer_payload($pelanggan)
    ]
]);
