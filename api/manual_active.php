<?php
// api/manual_active.php - Bridge ke proses/activecustomer.php (fitur "Manual Active" di web).
//
// SENGAJA tidak reimplementasi logic aktivasi (connect Mikrotik/RADIUS, insert transaksi, kirim
// notif WA) di sini -- proses/activecustomer.php sudah cukup kompleks & terus diperbaiki (mis.
// perilakunya baru saja disamakan dengan callback payment gateway), jadi kalau dibuat ulang di
// sini besar risiko drift/tidak sinkron kalau salah satunya diubah lagi nanti. Endpoint ini cuma
// menjembatani auth API (session/username-password/API key) -> sesi web yang dibaca cek-sesi.php,
// lalu meneruskan request ke proses/activecustomer.php apa adanya (termasuk upload bukti bayar).
require_once '../koneksibilling.php';
require_once '_bootstrap.php';
session_start();
api_cors();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    api_json(['success' => false, 'error' => 'Method tidak didukung, gunakan POST'], 405);
}

$input = api_read_input();
$auth = api_authenticate($conn, $input);
$pemilik = $auth['pemilik'];
if ($auth['method'] === 'apikey') {
    api_rate_limit($conn, $auth['api_key']);
}

$ctx = api_resolve_owner($conn, $pemilik);
if (!$ctx) {
    api_json(['success' => false, 'error' => 'User tidak ditemukan'], 401);
}
api_require_module_enabled($conn, $pemilik, 'transaksi');

if (empty($_POST['id']) && empty($_POST['IDPEL'])) {
    api_json(['success' => false, 'error' => 'Field id (ID transaksi/pelanggan) wajib diisi -- kirim sebagai form-data POST, sama seperti form Manual Active di web'], 400);
}

// Bridge sesi: isi $_SESSION persis seperti yang dibaca cek-sesi.php (butuh minimal PEMILIK +
// status='login'), supaya proses/activecustomer.php jalan dgn konteks owner/assistant yang benar
// -- sama-sama di-resolve dari baris `user` yang sama, jadi otomatis konsisten dgn hasil
// api_resolve_owner() di atas tanpa perlu penanganan manual owner-vs-assistant di sini.
$_SESSION['USERNAME'] = $ctx['username'];
$_SESSION['id']       = $ctx['session_user_id'];
$_SESSION['PEMILIK']  = $ctx['username'];
$_SESSION['status']   = 'login';

// $_POST (dan $_FILES['bukti_pembayaran'] kalau ada) sudah otomatis terisi dari request asli
// (wajib dikirim sebagai multipart/form-data, sama seperti submit form Manual Active di web) --
// proses/activecustomer.php baca langsung dari situ, echo JSON, lalu exit sendiri.
require __DIR__ . '/../proses/activecustomer.php';
