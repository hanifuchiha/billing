<?php
require '../cek-sesi.php';
require_once '../voucherhotspot/voucher_template_helper.php';

header('Content-Type: application/json');

if ($AKSES == 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('Voucher_Generator', $akses_menu, true)) {
        echo json_encode(['success' => false, 'message' => 'Tidak memiliki akses.']);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method tidak diizinkan.']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    echo json_encode(['success' => false, 'message' => 'Data template tidak valid.']);
    exit;
}

$owner = $ceknama;
$id = save_voucher_template($owner, $payload);

if ($id === false) {
    echo json_encode(['success' => false, 'message' => 'Gagal menyimpan template (folder settings/ tidak bisa ditulis, atau sudah mencapai batas 30 template).']);
    exit;
}

echo json_encode(['success' => true, 'id' => $id]);
