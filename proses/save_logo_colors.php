<?php
// Dipanggil dari JS applyLogoColors() di header.php sebagai fallback: kalau
// ekstraksi server-side (GD, saat upload logo) tidak menghasilkan apapun
// (misal file lama rusak / format tak terbaca GD), hasil ekstraksi canvas di
// browser dikirim ke sini supaya ikut ke-cache untuk page-load berikutnya --
// tidak perlu ekstraksi ulang tiap kali halaman dibuka.
require '../cek-sesi.php';
require_once __DIR__ . '/../logo_color_helper.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false]);
    exit;
}

$primary = isset($_POST['primary']) ? trim((string)$_POST['primary']) : '';
$secondary = isset($_POST['secondary']) ? trim((string)$_POST['secondary']) : '';

if (!preg_match('/^#[0-9a-fA-F]{6}$/', $primary) || !preg_match('/^#[0-9a-fA-F]{6}$/', $secondary)) {
    echo json_encode(['success' => false, 'message' => 'Format warna tidak valid']);
    exit;
}

// Simpan HANYA untuk akun yang sedang login sendiri ($logo_owner_key dari
// cek-sesi.php) -- tidak bisa dipakai untuk menimpa warna akun lain.
$ok = logoColorSave($logo_owner_key ?? $ceknama, $primary, $secondary);

echo json_encode(['success' => (bool)$ok]);
