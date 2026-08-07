<?php
// Cron polling utk NMS (mynetworkmap.php): dipanggil berkala (interval diatur
// di System Setting) via curl, SATU kali panggilan per PEMILIK (owner akun),
// sama pola dgn cron_dismantle_ticket.php/cron_maintenance_ticket.php.
//
// Tugas per panggilan:
// 1. Ping SEMUA network_devices milik owner ini, catat online/offline +
//    latency ke tabel network_device_log (fondasi grafik historis & SLA).
// 2. Kalau device yang monitor_interface-nya diisi & ada kredensial server
//    (join by ip_address ke tabel `server`), ambil jg rx/tx via RouterOS API.
// 3. Kirim alert WA sekali per episode down (bukan tiap polling) kalau
//    alert_enabled=1 utk device itu.
// 4. Retensi: hapus log lebih tua dari 90 hari supaya tabel tidak membengkak.

require('../../routeros_api.class.php');
include '../../koneksidb.php';

$pemilik = isset($_GET['pemilik']) ? trim((string)$_GET['pemilik']) : '';
if ($pemilik === '') {
    echo "Parameter pemilik wajib diisi.\n";
    exit;
}

$sqlUser = "SELECT id FROM `user` WHERE `USERNAME` = '" . mysqli_real_escape_string($conn, $pemilik) . "' LIMIT 1";
$queryUser = mysqli_query($conn, $sqlUser);
$userRow = $queryUser ? mysqli_fetch_assoc($queryUser) : null;
if (!$userRow) {
    echo "Owner $pemilik tidak ditemukan.\n";
    exit;
}
$ownerId = (int)$userRow['id'];

// ==================== AUTO-SCHEMA (self-heal, sama pola dgn mynetworkmap.php) ====================
$checkTable = mysqli_query($conn, "SHOW TABLES LIKE 'network_device_log'");
if ($checkTable && mysqli_num_rows($checkTable) == 0) {
    mysqli_query($conn, "CREATE TABLE network_device_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        device_id INT NOT NULL,
        checked_at DATETIME NOT NULL,
        online TINYINT(1) NOT NULL DEFAULT 0,
        latency_ms FLOAT DEFAULT NULL,
        rx_bps BIGINT DEFAULT NULL,
        tx_bps BIGINT DEFAULT NULL,
        INDEX idx_device_time (device_id, checked_at)
    )");
}
foreach ([
    'monitor_interface' => "VARCHAR(100) DEFAULT NULL",
    'alert_enabled' => "TINYINT(1) DEFAULT 1",
    'last_online_state' => "TINYINT(1) DEFAULT NULL",
    'down_alert_sent_at' => "DATETIME DEFAULT NULL",
] as $colName => $colDef) {
    $colCheck = mysqli_query($conn, "SHOW COLUMNS FROM network_devices LIKE '$colName'");
    if ($colCheck && mysqli_num_rows($colCheck) == 0) {
        mysqli_query($conn, "ALTER TABLE network_devices ADD COLUMN `$colName` $colDef");
    }
}

// ==================== FUNGSI PING (sama teknik dgn ping_device.php) ====================
function nmsPingCheck($ip) {
    $ip = preg_replace('/[^0-9a-zA-Z\.\-:]/', '', (string)$ip);
    if ($ip === '') return ['online' => false, 'latency' => null];
    if (strncasecmp(PHP_OS, 'WIN', 3) == 0) {
        $output = shell_exec("ping -n 1 -w 1000 " . escapeshellarg($ip));
        $isOnline = (stripos((string)$output, 'TTL=') !== false);
        $latency = null;
        if (preg_match('/time[=<]([0-9]+)ms/i', (string)$output, $m)) $latency = (float)$m[1];
    } else {
        $output = shell_exec("ping -c 1 -W 1 " . escapeshellarg($ip));
        $isOnline = (stripos((string)$output, 'ttl=') !== false);
        $latency = null;
        if (preg_match('/time=([0-9.]+) ms/', (string)$output, $m)) $latency = (float)$m[1];
    }
    return ['online' => $isOnline, 'latency' => $latency];
}

// ==================== AMBIL SEMUA DEVICE MILIK OWNER INI ====================
$sqlDev = "SELECT * FROM network_devices WHERE user_id = $ownerId";
$queryDev = mysqli_query($conn, $sqlDev);
if (!$queryDev || mysqli_num_rows($queryDev) == 0) {
    echo "Tidak ada network device utk $pemilik.\n";
    exit;
}

require_once('../bot_selector_helper.php');
$now = date('Y-m-d H:i:s');
$polled = 0;
$alertsSent = 0;

while ($dev = mysqli_fetch_assoc($queryDev)) {
    $deviceId = (int)$dev['id'];
    $ip = trim((string)$dev['ip_address']);
    if ($ip === '') continue;

    $ping = nmsPingCheck($ip);
    $rx_bps = null;
    $tx_bps = null;

    // Kalau device ini punya kredensial server (join by IP) & interface yang
    // mau dimonitor sudah dipilih admin, ambil traffic via RouterOS API.
    if (!empty($dev['monitor_interface'])) {
        $srvQuery = mysqli_query($conn, "SELECT PEMILIK, PASSWORD FROM server WHERE IP = '" . mysqli_real_escape_string($conn, $ip) . "' AND user_id = $ownerId LIMIT 1");
        $srvRow = $srvQuery ? mysqli_fetch_assoc($srvQuery) : null;
        if ($srvRow) {
            $lockFile = sys_get_temp_dir() . '/mikrotik_lock_' . md5($ip . '_' . $srvRow['PEMILIK']) . '.lock';
            $lockFp = @fopen($lockFile, 'c');
            $gotLock = $lockFp && @flock($lockFp, LOCK_EX | LOCK_NB);
            if ($gotLock) {
                $API = new RouterosAPI();
                $API->debug = false;
                if (@$API->connect($ip, $srvRow['PEMILIK'], $srvRow['PASSWORD'])) {
                    $traffic = $API->comm('/interface/monitor-traffic', [
                        'interface' => $dev['monitor_interface'],
                        'once' => '',
                    ]);
                    if (is_array($traffic) && isset($traffic[0])) {
                        $rx_bps = isset($traffic[0]['rx-bits-per-second']) ? (int)$traffic[0]['rx-bits-per-second'] : null;
                        $tx_bps = isset($traffic[0]['tx-bits-per-second']) ? (int)$traffic[0]['tx-bits-per-second'] : null;
                    }
                    $API->disconnect();
                }
                @flock($lockFp, LOCK_UN);
            }
            if ($lockFp) @fclose($lockFp);
        }
    }

    mysqli_query($conn, "INSERT INTO network_device_log (device_id, checked_at, online, latency_ms, rx_bps, tx_bps) VALUES (
        $deviceId, '$now', " . ($ping['online'] ? 1 : 0) . ", " . ($ping['latency'] !== null ? (float)$ping['latency'] : 'NULL') . ",
        " . ($rx_bps !== null ? (int)$rx_bps : 'NULL') . ", " . ($tx_bps !== null ? (int)$tx_bps : 'NULL') . ")");
    $polled++;

    // ==================== ALERT DOWN (sekali per episode, bukan tiap polling) ====================
    $lastState = $dev['last_online_state'];
    $alertEnabled = ((int)($dev['alert_enabled'] ?? 1)) === 1;
    $justWentDown = $alertEnabled && !$ping['online'] && ($lastState === null || (int)$lastState === 1);
    $cameBackUp = $ping['online'] && $lastState !== null && (int)$lastState === 0;

    if ($justWentDown) {
        $bot_result = selectBotForNotificationWithField($conn, $pemilik, '', 'penerima_server');
        if ($bot_result['success']) {
            $text = "⚠️ *[NMS ALERT]* ⚠️\n";
            $text .= "=============================\n";
            $text .= "🚨 DEVICE OFFLINE TERDETEKSI 🚨\n";
            $text .= "=============================\n";
            $text .= "Nama: {$dev['name']}\n";
            $text .= "IP: {$dev['ip_address']}\n";
            $text .= "Lokasi: {$dev['location']}\n";
            $text .= "Waktu: $now\n\n";
            $text .= "Device tidak merespons ping. Mohon segera dicek.\n";
            if (function_exists('prependDynamicGreeting')) {
                $text = prependDynamicGreeting($text);
            }

            $url = $bot_result['addressbot'] . "/send/message?session=" . urlencode($bot_result['namebot']);
            $deviceIdBot = trim((string)($bot_result['sender'] ?? ''));
            $headers = ["Content-Type: application/json"];
            if ($deviceIdBot !== '') {
                $url .= '&device_id=' . urlencode($deviceIdBot);
                $headers[] = "X-Device-Id: $deviceIdBot";
            }
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(["phone" => $bot_result['penerima'], "message" => $text]));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_USERPWD, $bot_result['namebot'] . ":" . $bot_result['password']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);
            curl_exec($ch);
            curl_close($ch);
            $alertsSent++;
        }
        mysqli_query($conn, "UPDATE network_devices SET last_online_state = 0, down_alert_sent_at = '$now' WHERE id = $deviceId");
    } elseif ($cameBackUp) {
        mysqli_query($conn, "UPDATE network_devices SET last_online_state = 1, down_alert_sent_at = NULL WHERE id = $deviceId");
    } else {
        mysqli_query($conn, "UPDATE network_devices SET last_online_state = " . ($ping['online'] ? 1 : 0) . " WHERE id = $deviceId");
    }
}

// Retensi 90 hari.
mysqli_query($conn, "DELETE FROM network_device_log WHERE checked_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");

echo "Selesai. Device dipoll: $polled, alert dikirim: $alertsSent.\n";
