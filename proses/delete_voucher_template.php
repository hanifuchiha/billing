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

$id = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($_POST['id'] ?? ''));
if ($id === '') {
    echo json_encode(['success' => false, 'message' => 'ID template tidak valid.']);
    exit;
}

$owner = $ceknama;
$ok = delete_voucher_template($owner, $id);

echo json_encode(['success' => $ok, 'message' => $ok ? '' : 'Template tidak ditemukan.']);
