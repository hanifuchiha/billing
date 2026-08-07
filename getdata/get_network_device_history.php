<?php
// Grafik historis (harian/mingguan/bulanan) + uptime% utk 1 device NMS, dari
// data hasil polling notifbot/notifphp/network_devices_poll_cron.php.
require '../cek-sesi.php';

header('Content-Type: application/json');

$device_id = (int)($_GET['device_id'] ?? 0);
$period = in_array(($_GET['period'] ?? 'daily'), ['daily', 'weekly', 'monthly'], true) ? $_GET['period'] : 'daily';
$uptimeOnly = isset($_GET['uptime_only']) && $_GET['uptime_only'] == '1';

if ($device_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'device_id tidak valid.']);
    exit;
}

// Scoping kepemilikan: device HARUS milik user yg sedang login.
$devCheck = mysqli_query($conn, "SELECT id FROM network_devices WHERE id = $device_id AND user_id = " . (int)$current_user_id);
if (!$devCheck || mysqli_num_rows($devCheck) === 0) {
    echo json_encode(['success' => false, 'message' => 'Device tidak ditemukan atau bukan milik Anda.']);
    exit;
}

$checkTable = mysqli_query($conn, "SHOW TABLES LIKE 'network_device_log'");
if (!$checkTable || mysqli_num_rows($checkTable) === 0) {
    echo json_encode(['success' => false, 'message' => 'Belum ada data historis (cron polling belum pernah jalan). Aktifkan di System Setting.']);
    exit;
}

// Uptime% 30 hari terakhir (dipakai badge, terpisah dari periode grafik).
if ($uptimeOnly) {
    $q = mysqli_query($conn, "SELECT AVG(online) AS pct FROM network_device_log WHERE device_id = $device_id AND checked_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $row = $q ? mysqli_fetch_assoc($q) : null;
    $pct = ($row && $row['pct'] !== null) ? round((float)$row['pct'] * 100, 2) : null;
    echo json_encode(['success' => true, 'uptime_percent' => $pct]);
    exit;
}

// Rentang & granularitas grouping per periode.
if ($period === 'daily') {
    $interval = '1 DAY';
    $groupFormat = '%Y-%m-%d %H:00'; // per jam
} elseif ($period === 'weekly') {
    $interval = '7 DAY';
    $groupFormat = '%Y-%m-%d %H:00'; // per jam
} else { // monthly
    $interval = '30 DAY';
    $groupFormat = '%Y-%m-%d'; // per hari
}

$sql = "SELECT DATE_FORMAT(checked_at, '$groupFormat') AS bucket,
               AVG(rx_bps) AS avg_rx, AVG(tx_bps) AS avg_tx, AVG(online) AS avg_online
        FROM network_device_log
        WHERE device_id = $device_id AND checked_at >= DATE_SUB(NOW(), INTERVAL $interval)
        GROUP BY bucket
        ORDER BY bucket ASC";
$result = mysqli_query($conn, $sql);

$labels = [];
$rx = [];
$tx = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $labels[] = $row['bucket'];
        // Konversi bps -> Mbps supaya konsisten dgn grafik live yg sudah pakai Mbps.
        $rx[] = $row['avg_rx'] !== null ? round(((float)$row['avg_rx']) / 1000000, 3) : 0;
        $tx[] = $row['avg_tx'] !== null ? round(((float)$row['avg_tx']) / 1000000, 3) : 0;
    }
}

if (empty($labels)) {
    echo json_encode(['success' => false, 'message' => 'Belum ada data historis utk periode ini. Pastikan cron polling NMS sudah aktif & berjalan beberapa saat.']);
    exit;
}

echo json_encode(['success' => true, 'labels' => $labels, 'rx' => $rx, 'tx' => $tx]);
