<?php
/**
 * Endpoint status PPPoE per pelanggan - dipanggil dari tables.php (AJAX per baris).
 *
 * Beda dengan getonlinecustomer.php (yang connect langsung ke Mikrotik tiap kali
 * dipanggil): endpoint ini BACA DULU dari cache hasil scan batch cron
 * (cron_scan_pppoe_status.php). Kalau cache ada & masih fresh -> balikin langsung
 * (cepat, tidak connect ke Mikrotik sama sekali). Kalau cache belum ada / basi
 * (mis. cron belum dipasang / sedang gagal) -> fallback connect langsung khusus
 * pelanggan ini saja, sama seperti perilaku getonlinecustomer.php sebelumnya,
 * supaya status tetap tampil walau cron belum jalan.
 */

include '../cek-sesi.php';
require 'routeros_api.class.php';
require_once 'pppoe_status_helpers.php';

header('Content-Type: application/json');

$ip = $_POST['ip'] ?? '';
$user = $_POST['us'] ?? '';
$password = $_POST['ps'] ?? '';
$IDPEL = $_POST['idpel'] ?? '';

if (empty($IDPEL)) {
    echo json_encode(["error" => "Missing parameters"]);
    exit;
}

$cached = read_pppoe_status_cache_entry($IDPEL);
if ($cached !== null) {
    echo json_encode($cached);
    exit;
}

// --- Fallback: cache tidak tersedia, connect langsung untuk 1 pelanggan ini ---
if (empty($ip) || empty($user) || empty($password)) {
    echo json_encode(["error" => "Missing parameters"]);
    exit;
}

$API = new RouterosAPI();
if ($API->connect($ip, $user, $password)) {

    $ARRAY = $API->comm('/ppp/secret/print', ["?name" => $IDPEL]);
    $cek = !empty($ARRAY) ? 0 : false;

    $cekexpired = $cek !== false ? ($ARRAY[$cek]['profile'] ?? 'N/A') : 'N/A';
    $last_caller_secret = $cek !== false ? ($ARRAY[$cek]['last-caller-id'] ?? 'N/A') : 'N/A';
    $ceklastloggedout = $cek !== false ? ($ARRAY[$cek]['last-logged-out'] ?? 'N/A') : 'N/A';
    $ceklastdisconnect = $cek !== false ? ($ARRAY[$cek]['last-disconnect-reason'] ?? 'N/A') : 'N/A';
    $secretLimitIn = $cek !== false ? ($ARRAY[$cek]['limit-bytes-in'] ?? '') : '';
    $secretLimitOut = $cek !== false ? ($ARRAY[$cek]['limit-bytes-out'] ?? '') : '';

    $ppp_active = $API->comm('/ppp/active/print', ["?name" => $IDPEL]);

    $status = "Offline";
    $download = 0;
    $upload = 0;
    $active_caller_id = 'N/A';
    $remote_ip = 'N/A';
    $login_source = 'unknown';
    $uptime_raw = '';
    $sessionBytesIn = 0;
    $sessionBytesOut = 0;

    if (!empty($ppp_active)) {
        $status = "Online";
        $active_caller_id = $ppp_active[0]['caller-id'] ?? $ppp_active[0]['mac-address'] ?? 'N/A';
        $remote_ip = $ppp_active[0]['address'] ?? 'N/A';
        $radius_flag = $ppp_active[0]['radius'] ?? 'false';
        $login_source = ($radius_flag === 'true') ? 'radius' : 'local';
        $uptime_raw = $ppp_active[0]['uptime'] ?? '';
        if (empty($secretLimitIn)) $secretLimitIn = $ppp_active[0]['limit-bytes-in'] ?? '';
        if (empty($secretLimitOut)) $secretLimitOut = $ppp_active[0]['limit-bytes-out'] ?? '';
    }

    $kuota = format_kuota_text($secretLimitIn, $secretLimitOut);
    $uptime = ($uptime_raw !== '') ? format_uptime_readable($uptime_raw) : 'N/A';
    $lastLinkUp = ($uptime_raw !== '') ? format_last_link_up($uptime_raw) : 'N/A';

    $interface = "<pppoe-" . $IDPEL . ">";

    $interfaces = $API->comm("/interface/print", ["?name" => $interface]);
    if (!empty($interfaces)) {
        $sessionBytesIn = $interfaces[0]['rx-byte'] ?? 0;
        $sessionBytesOut = $interfaces[0]['tx-byte'] ?? 0;

        $traffic = $API->comm("/interface/monitor-traffic", [
            "interface" => $interface,
            "once" => ""
        ]);
        if (!empty($traffic[0]['tx-bits-per-second'])) {
            $download = round($traffic[0]['tx-bits-per-second'] / 1_000_000, 2);
            $upload = round($traffic[0]['rx-bits-per-second'] / 1_000_000, 2);
        }
    }

    $uptimeSecondsForUsage = ($status === 'Online') ? (ros_uptime_to_seconds($uptime_raw) ?? 0) : 0;
    list($totalBytesIn, $totalBytesOut) = track_customer_usage($conn, $IDPEL, $status === 'Online', $sessionBytesIn, $sessionBytesOut, $uptimeSecondsForUsage);

    $pemakaian = format_pemakaian_text($totalBytesIn, $totalBytesOut);
    $lastLinkDown = format_tanggal_indo($ceklastloggedout);

    echo json_encode([
        'cekexpired' => $cekexpired,
        'last_caller_secret' => $last_caller_secret,
        'active_caller_id' => $active_caller_id,
        'remote_ip' => $remote_ip,
        'ceklastloggedout' => $ceklastloggedout,
        'ceklastdisconnect' => $ceklastdisconnect,
        'status' => $status,
        'interface' => $interface,
        'download' => $download,
        'upload' => $upload,
        'user' => $IDPEL,
        'login_via' => $login_source,
        'kuota' => $kuota,
        'uptime' => $uptime,
        'last_link_up' => $lastLinkUp,
        'last_link_down' => $lastLinkDown,
        'pemakaian' => $pemakaian
    ]);

} else {
    // Mikrotik API tidak terjangkau -- wajar utk pelanggan RADIUS MODE (server
    // RADIUS-only umumnya tidak punya API Mikrotik aktif, lihat komentar
    // renderOfflineRadiusUI() di tables.php). Fallback ke data accounting RADIUS
    // (tabel radacct, koneksi $conn yang sama dgn billing -- sudah dipakai jalur
    // lain, mis. apiinterface.php) supaya pelanggan mode ini tetap dapat badge +
    // grid detail yang SAMA PERSIS dgn pelanggan API MODE, bukan dump mentah
    // command radwho (lihat cekRadwhoFallback() di tables.php, sengaja tidak
    // dipanggil lagi dari alur utama setelah perbaikan ini).
    $idpelEsc = mysqli_real_escape_string($conn, $IDPEL);
    $radacctRow = null;
    $radacctSql = "SELECT framedipaddress, acctstarttime, acctstoptime, acctsessiontime, acctterminatecause, callingstationid
        FROM radacct WHERE username = '$idpelEsc' ORDER BY radacctid DESC LIMIT 1";
    $resRad = @mysqli_query($conn, $radacctSql);
    if ($resRad === false) {
        // Kemungkinan kolom callingstationid tidak ada di skema radacct ini -- retry tanpa itu.
        $resRad = @mysqli_query($conn, "SELECT framedipaddress, acctstarttime, acctstoptime, acctsessiontime, acctterminatecause
            FROM radacct WHERE username = '$idpelEsc' ORDER BY radacctid DESC LIMIT 1");
    }
    if ($resRad) {
        $radacctRow = mysqli_fetch_assoc($resRad);
    }

    if ($radacctRow) {
        $isOnlineRadius = empty($radacctRow['acctstoptime']);
        $statusRadius = $isOnlineRadius ? 'Online' : 'Offline';
        $lastLinkUpRadius = !empty($radacctRow['acctstarttime']) ? format_tanggal_indo($radacctRow['acctstarttime']) : 'N/A';
        $lastLinkDownRadius = (!$isOnlineRadius && !empty($radacctRow['acctstoptime']))
            ? format_tanggal_indo($radacctRow['acctstoptime'])
            : 'N/A';
        $uptimeRadius = ($isOnlineRadius && !empty($radacctRow['acctstarttime']) && strtotime($radacctRow['acctstarttime']) !== false)
            ? format_uptime_from_seconds(time() - strtotime($radacctRow['acctstarttime']))
            : 'N/A';
        $macRadius = !empty($radacctRow['callingstationid']) ? $radacctRow['callingstationid'] : 'N/A';
        $remoteIpRadius = !empty($radacctRow['framedipaddress']) ? $radacctRow['framedipaddress'] : 'N/A';
        $disconnectRadius = !empty($radacctRow['acctterminatecause']) ? $radacctRow['acctterminatecause'] : 'N/A';

        echo json_encode([
            'cekexpired' => 'N/A',
            'last_caller_secret' => $macRadius,
            'active_caller_id' => $macRadius,
            'remote_ip' => $remoteIpRadius,
            'ceklastloggedout' => $lastLinkDownRadius,
            'ceklastdisconnect' => $disconnectRadius,
            'status' => $statusRadius,
            'interface' => '',
            'download' => 0,
            'upload' => 0,
            'user' => $IDPEL,
            'login_via' => 'radius',
            'kuota' => 'N/A',
            'uptime' => $uptimeRadius,
            'last_link_up' => $lastLinkUpRadius,
            'last_link_down' => $lastLinkDownRadius,
            'pemakaian' => 'N/A',
        ]);
        exit;
    }

    // Tidak ada satu pun baris radacct (belum pernah tercatat login RADIUS) --
    // tetap balikin shape JSON yang sama (semua N/A, status Offline) supaya
    // jalur render normal di tables.php (badge "Offline (LOS)" + grid graceful)
    // yang jalan, BUKAN jalur .catch()/renderOfflineRadiusUI() yang tanpa grid.
    echo json_encode([
        'cekexpired' => 'N/A',
        'last_caller_secret' => 'N/A',
        'active_caller_id' => 'N/A',
        'remote_ip' => 'N/A',
        'ceklastloggedout' => 'N/A',
        'ceklastdisconnect' => 'N/A',
        'status' => 'Offline',
        'interface' => '',
        'download' => 0,
        'upload' => 0,
        'user' => $IDPEL,
        'login_via' => 'radius',
        'kuota' => 'N/A',
        'uptime' => 'N/A',
        'last_link_up' => 'N/A',
        'last_link_down' => 'N/A',
        'pemakaian' => 'N/A',
    ]);
}
exit;
