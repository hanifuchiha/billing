<?php

/**
 * Jaring pengaman aktivasi pascapembayaran.
 * Callback tetap menjadi jalur aktivasi utama; cron ini menangani kegagalan API
 * dan transaksi BERHASIL yang masuk melalui jalur lain (termasuk kompensasi_free).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$lock = fopen(__DIR__ . '/cron_activate_paid_customers.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

require_once __DIR__ . '/../koneksidb.php';
require_once __DIR__ . '/../payment_activation_helper.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    paymentActivationLog('FATAL cron: koneksi database tidak tersedia');
    exit(1);
}

// Cache ini diperbarui tiap menit oleh cron_scan_pppoe_status.php. Memakainya
// sebagai pre-filter mencegah koneksi API ke ratusan router untuk pelanggan
// yang profilnya sudah normal.
$statusCacheFile = __DIR__ . '/../serverlog/pppoe_status_cache.json';
$statusCache = [];
if (is_file($statusCacheFile)) {
    $decoded = json_decode((string) file_get_contents($statusCacheFile), true);
    if (is_array($decoded['data'] ?? null)) {
        $statusCache = $decoded['data'];
    }
}
if (!$statusCache) {
    paymentActivationLog('PENDING cron: cache status PPPoE belum tersedia');
    exit(1);
}

$sql = "SELECT t.IDPEL,t.PENGUNAAN,MAX(t.id) AS transaksi_id
        FROM transaksi t
        WHERE UPPER(TRIM(t.STATUS))='BERHASIL'
          AND t.waktu >= DATE_SUB(NOW(), INTERVAL 3 DAY)
          AND TRIM(COALESCE(t.IDPEL,''))<>''
        GROUP BY t.IDPEL,t.PENGUNAAN
        ORDER BY transaksi_id ASC";
$query = $conn->query($sql);
if (!$query) {
    paymentActivationLog('FATAL cron query: ' . $conn->error);
    exit(1);
}

while ($row = $query->fetch_assoc()) {
    $idpel = (string) $row['IDPEL'];
    $cachedProfile = strtoupper(trim((string) ($statusCache[$idpel]['cekexpired'] ?? '')));
    if ($cachedProfile !== 'EXPIRED') {
        continue;
    }
    activatePaidCustomerIfExpired(
        $conn,
        $idpel,
        (string) $row['PENGUNAAN'],
        'retry-cron#' . (string) $row['transaksi_id']
    );
}

flock($lock, LOCK_UN);
fclose($lock);
