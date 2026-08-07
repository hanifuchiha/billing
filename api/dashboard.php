<?php
// API Dashboard: statistik utama (jumlah pelanggan, tagihan, pembayaran, dsb)
// Sebelumnya cuma dukung username+password -- tidak pernah baca param
// `key`/`api_key`, PADAHAL settingsapi.php mendokumentasikan resmi akses via
// API key. Diganti ke _bootstrap.php::api_authenticate() (session ->
// username+password -> API key dari tabel `apikey`) sama seperti api/odp.php dkk.
require_once '../koneksibilling.php';
require_once '_bootstrap.php';
session_start();
api_cors();

$input = api_read_input();

$auth = api_authenticate($conn, $input);
$pemilik = $auth['pemilik'];
if ($auth['method'] === 'apikey') {
    api_rate_limit($conn, $auth['api_key']);
}

// Jumlah pelanggan
$pelanggan = $conn->query("SELECT COUNT(*) as total FROM pelanggan WHERE PEMILIK = '".$conn->real_escape_string($pemilik)."'");
$jumlah_pelanggan = $pelanggan ? ($pelanggan->fetch_assoc()['total'] ?? 0) : 0;

// Jumlah tagihan
$tagihan = $conn->query("SELECT COUNT(*) as total FROM tagihan WHERE PEMILIK = '".$conn->real_escape_string($pemilik)."'");
$jumlah_tagihan = $tagihan ? ($tagihan->fetch_assoc()['total'] ?? 0) : 0;

// Jumlah pembayaran
$pembayaran = $conn->query("SELECT COUNT(*) as total FROM transaksi WHERE STATUS = 'LUNAS' AND PEMILIK = '".$conn->real_escape_string($pemilik)."'");
$jumlah_pembayaran = $pembayaran ? ($pembayaran->fetch_assoc()['total'] ?? 0) : 0;

// Saldo total
$saldo = $conn->query("SELECT SUM(SALDO) as total FROM user WHERE USERNAME = '".$conn->real_escape_string($pemilik)."'");
$total_saldo = $saldo ? ($saldo->fetch_assoc()['total'] ?? 0) : 0;

// Paket aktif
$paket = $conn->query("SELECT COUNT(*) as total FROM paket WHERE STATUS = 'AKTIF' AND PEMILIK = '".$conn->real_escape_string($pemilik)."'");
$jumlah_paket = $paket ? ($paket->fetch_assoc()['total'] ?? 0) : 0;

$response = [
    'jumlah_pelanggan' => (int)$jumlah_pelanggan,
    'jumlah_tagihan' => (int)$jumlah_tagihan,
    'jumlah_pembayaran' => (int)$jumlah_pembayaran,
    'total_saldo' => (int)$total_saldo,
    'jumlah_paket_aktif' => (int)$jumlah_paket
];
echo json_encode($response);
exit;