<?php
/**
 * getdata/get_reminder_settings.php
 * ----------------------------------------------------------------------
 * Endpoint AJAX dipanggil dari modal "Sinkron dari API" (Step 1) saat
 * admin memilih Server. Mengembalikan isi pengaturan tutup buku /
 * reminder milik akun (username) pemilik server tsb, dibaca dari
 * notifbot/data/reminder-{username}.json, supaya admin bisa melihat
 * aturan yang akan dipakai untuk menghitung kolom PENGUNAAN sebelum
 * menjalankan sinkron.
 *
 * GET params:
 *   - server (atau pemilik) : nilai PEMILIK dari tabel `server`
 * ----------------------------------------------------------------------
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../cek-sesi.php';
require_once __DIR__ . '/api_hanif_periode_helper.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($AKSES) || $AKSES !== 'ADMIN') {
    echo json_encode(['success' => false, 'message' => 'Akses ditolak. Hanya ADMIN yang dapat melihat pengaturan ini.']);
    exit;
}

$pemilik = trim($_GET['server'] ?? $_GET['pemilik'] ?? '');
if ($pemilik === '') {
    echo json_encode(['success' => false, 'message' => 'Parameter server/pemilik wajib diisi.']);
    exit;
}

$username = getUsernameByPemilik($conn, $pemilik);
if ($username === null) {
    echo json_encode([
        'success'  => true,
        'pemilik'  => $pemilik,
        'username' => null,
        'found'    => false,
        'message'  => 'Tidak ditemukan akun user untuk Server/PEMILIK ini, sehingga tidak ada file reminder yang bisa dibaca. Perhitungan periode fallback akan pakai mode sederhana (mengikuti tanggal bayar).',
        'settings' => null,
    ]);
    exit;
}

$settings = loadReminderSettings($username);

if ($settings === null) {
    echo json_encode([
        'success'  => true,
        'pemilik'  => $pemilik,
        'username' => $username,
        'found'    => false,
        'message'  => "File reminder-{$username}.json tidak ditemukan / kosong. Perhitungan periode fallback akan pakai mode sederhana (mengikuti tanggal bayar).",
        'settings' => null,
    ]);
    exit;
}

echo json_encode([
    'success'  => true,
    'pemilik'  => $pemilik,
    'username' => $username,
    'found'    => true,
    'settings' => $settings,
]);