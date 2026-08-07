<?php
/**
 * proses/delete_isolir_forwarding.php
 *
 * Hapus semua rule isolir-forwarding (filter, NAT, web-proxy access) dari router server
 * yang dipilih, dan hapus catatan "sudah diterapkan" dari database. PPP Profile/Pool
 * "EXPIRED" itu sendiri TIDAK dihapus (masih dipakai untuk downgrade profile pelanggan
 * menunggak, terlepas dari fitur forwarding ini).
 *
 * Request: POST { server_id }
 */

require_once __DIR__ . '/../cek-sesi.php';
require_once __DIR__ . '/../routeros_api.class.php';
require_once __DIR__ . '/isolir_helpers.php';

function redirectDeleteIsolir($status, $text = '') {
    $url = "../isolir_forwarding.php?del=" . urlencode($status);
    if ($text !== '') {
        $url .= "&text=" . urlencode($text);
    }
    header("Location: " . $url);
    exit;
}

if (empty($_SESSION['id'])) {
    header('Location: ../login.php');
    exit;
}
if ($AKSES !== 'ADMIN') {
    redirectDeleteIsolir('failed', 'Hanya ADMIN yang bisa mengakses fitur ini');
}

$serverId = isset($_POST['server_id']) ? (int) $_POST['server_id'] : 0;
if ($serverId <= 0) {
    redirectDeleteIsolir('failed', 'Server tidak valid');
}

$currentUserId = (int) $_SESSION['id'];
$srvRes = mysqli_query($conn, "SELECT * FROM server WHERE id = $serverId AND user_id = $currentUserId LIMIT 1");
if (!$srvRes || mysqli_num_rows($srvRes) === 0) {
    redirectDeleteIsolir('failed', 'Server tidak ditemukan atau bukan milik akun ini');
}
$server = mysqli_fetch_assoc($srvRes);

$API = new RouterosAPI();
$API->timeout = 10;

$log = [];
if ($API->connect($server['IP'], $server['PEMILIK'], $server['PASSWORD'])) {
    try {
        $log = removeIsolirForwardingRulesFromRouter($API);
        $API->disconnect();
    } catch (\Throwable $e) {
        $API->disconnect();
        redirectDeleteIsolir('failed', 'Error RouterOS API: ' . $e->getMessage());
    }
} else {
    // Tidak bisa konek -> tidak tahu pasti apakah rule di router masih ada atau tidak.
    // Jangan hapus catatan "applied" di database supaya statusnya tidak menyesatkan
    // (admin masih tahu server ini perlu dihapus manual / dicoba lagi nanti).
    redirectDeleteIsolir('failed', 'Gagal terhubung ke MikroTik ' . $server['IP'] . '. Rule belum dihapus, coba lagi nanti.');
}

mysqli_query($conn, "DELETE FROM isolir_forwarding_server_settings WHERE server_id = $serverId AND user_id = $currentUserId");

redirectDeleteIsolir('success', implode('; ', $log));
