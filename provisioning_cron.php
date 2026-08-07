<?php
/**
 * Provisioning Cron - Auto expire & delete MikroTik/RADIUS secrets
 * Run via crontab: see provisioning_settings.php for curl command
 */
date_default_timezone_set('Asia/Jakarta');

// Restrict access to localhost (crontab curl) or CLI
$client_ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
$allowed_ips = ['127.0.0.1', '::1', '::ffff:127.0.0.1'];
if (PHP_SAPI !== 'cli' && !in_array($client_ip, $allowed_ips, true)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$log_prefix = '[' . date('Y-m-d H:i:s') . '] ';

// Load config
$config_file = __DIR__ . '/config.json';
if (!file_exists($config_file)) {
    echo $log_prefix . "ERROR: config.json not found\n";
    exit(1);
}
$config = json_decode(file_get_contents($config_file), true);

$conn = mysqli_connect($config['db_host'], $config['db_user'], $config['db_pass'], $config['db_name']);
if (!$conn) {
    echo $log_prefix . "ERROR: DB connection failed\n";
    exit(1);
}

require_once __DIR__ . '/routeros_api.class.php';
require_once __DIR__ . '/radius_sync_lib.php';

// Check if cron settings table exists
$check_tbl = mysqli_query($conn, "SHOW TABLES LIKE 'provisioning_cron_settings'");
if (mysqli_num_rows($check_tbl) == 0) {
    echo $log_prefix . "provisioning_cron_settings table not found, skipping\n";
    mysqli_close($conn);
    exit(0);
}

// Get all owners with auto_expire enabled
$cron_owners = [];
$res_owners = mysqli_query($conn, "SELECT owner FROM provisioning_cron_settings WHERE auto_expire_enabled = 1");
if ($res_owners) {
    while ($row = mysqli_fetch_assoc($res_owners)) {
        $cron_owners[] = $row['owner'];
    }
}

if (empty($cron_owners)) {
    echo $log_prefix . "No owners with auto_expire enabled\n";
    mysqli_close($conn);
    exit(0);
}

// Find PENDING provisioning records that have expired
// Only process owners who have cron enabled
$placeholders = implode(',', array_fill(0, count($cron_owners), '?'));
$types = str_repeat('s', count($cron_owners));

$sql = "SELECT p.*, s.IP, s.PEMILIK AS srv_pemilik, s.PASSWORD AS srv_password 
        FROM provisioning p 
        LEFT JOIN server s ON p.server_pemilik = s.PEMILIK AND p.area = s.AREA
        WHERE p.status = 'PENDING' 
        AND p.expired_at < NOW()
        AND p.server_pemilik IN (
            SELECT PEMILIK FROM server WHERE user_id IN (
                SELECT id FROM user WHERE USERNAME IN ($placeholders)
            )
        )";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    // Fallback: simpler query without owner filter
    $sql_simple = "SELECT p.*, s.IP, s.PEMILIK AS srv_pemilik, s.PASSWORD AS srv_password 
                   FROM provisioning p 
                   LEFT JOIN server s ON p.server_pemilik = s.PEMILIK AND p.area = s.AREA
                   WHERE p.status = 'PENDING' AND p.expired_at < NOW()";
    $result = mysqli_query($conn, $sql_simple);
    if (!$result) {
        echo $log_prefix . "ERROR: Query failed: " . mysqli_error($conn) . "\n";
        mysqli_close($conn);
        exit(1);
    }
} else {
    $stmt->bind_param($types, ...$cron_owners);
    $stmt->execute();
    $result = $stmt->get_result();
}

$total = 0;
$success = 0;
$failed = 0;
$any_radius_changed = false;

while ($prov = mysqli_fetch_assoc($result)) {
    $total++;
    $idpel = $prov['idpel'];
    $mk_deleted = false;
    $radius_deleted = false;

    echo $log_prefix . "Processing expired: $idpel (server: {$prov['server_pemilik']}, area: {$prov['area']})\n";

    // Delete from MikroTik
    if (($prov['auth_mode'] === 'API MODE' || $prov['auth_mode'] === 'MULTI MODE') && !empty($prov['IP'])) {
        try {
            $API = new RouterosAPI();
            $API->debug = false;
            $API->timeout = 5;
            $API->attempts = 2;

            if ($API->connect($prov['IP'], $prov['srv_pemilik'], $prov['srv_password'])) {
                $existing = $API->comm("/ppp/secret/print", ["?name" => $idpel]);
                if (!empty($existing) && !isset($existing['!trap'])) {
                    $API->comm("/ppp/secret/remove", [".id" => $existing[0]['.id']]);
                    $mk_deleted = true;
                    echo $log_prefix . "  MikroTik: secret '$idpel' deleted from {$prov['IP']}\n";
                } else {
                    echo $log_prefix . "  MikroTik: secret '$idpel' not found on {$prov['IP']}\n";
                    $mk_deleted = true; // Not found = already clean
                }
                $API->disconnect();
            } else {
                echo $log_prefix . "  MikroTik: failed to connect to {$prov['IP']}\n";
            }
        } catch (Exception $e) {
            echo $log_prefix . "  MikroTik error: " . $e->getMessage() . "\n";
        }
    }

    // Delete from RADIUS -- pakai radiusRemoveUsers() (radius_sync_lib.php)
    // supaya penghapusan konsisten di KEDUA path (users + mirror
    // mods-config/files/authorize) dan managed-state ikut dibersihkan,
    // bukan preg_replace mentah langsung ke satu file saja seperti
    // sebelumnya (bisa bikin mirror drift dari file utama).
    if ($prov['auth_mode'] === 'RADIUS MODE' || $prov['auth_mode'] === 'MULTI MODE') {
        $rm = radiusRemoveUsers([$idpel]);
        if (!empty($rm['removed'])) {
            $any_radius_changed = true;
            $radius_deleted = true;
            echo $log_prefix . "  RADIUS: entry '$idpel' removed\n";
        } else {
            echo $log_prefix . "  RADIUS: entry '$idpel' not found\n";
            $radius_deleted = true;
        }
    }

    // Update status to EXPIRED
    $stmt_upd = $conn->prepare("UPDATE provisioning SET status = 'EXPIRED' WHERE id = ?");
    $stmt_upd->bind_param('i', $prov['id']);
    $stmt_upd->execute();
    $stmt_upd->close();

    $success++;
    echo $log_prefix . "  Status updated to EXPIRED (MK:" . ($mk_deleted ? 'OK' : 'SKIP') . " RAD:" . ($radius_deleted ? 'OK' : 'SKIP') . ")\n";
}

// Restart FreeRADIUS cuma kalau memang ada entry yang benar-benar dihapus
// barusan (dilacak langsung dari hasil radiusRemoveUsers() di atas, bukan
// query ulang "dalam 1 jam terakhir" yang bisa salah hitung run cron lain).
// Pakai radiusReloadIfChanged() (bukan `systemctl restart` polos) supaya
// konsisten dengan cara restart FreeRADIUS 3 yang benar (full stop+kill+
// jalan ulang mode debug -- lihat komentar di radius_sync_lib.php).
if ($any_radius_changed) {
    radiusReloadIfChanged(true);
    echo $log_prefix . "FreeRADIUS restarted\n";
}

echo $log_prefix . "Done. Total: $total, Success: $success, Failed: $failed\n";
mysqli_close($conn);
