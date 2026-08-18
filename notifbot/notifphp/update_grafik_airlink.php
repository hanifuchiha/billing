<?php
require('../../routeros_api.class.php');
include '../../koneksidb.php';

$filename = basename(__FILE__); // contoh: update_grafik_FIBERQ.php
$nameOnly = pathinfo($filename, PATHINFO_FILENAME); // update_grafik_FIBERQ

$parts = explode('_', $nameOnly);
$pemilik = end($parts); // ambil bagian terakhir

echo "Update grafik untuk pemilik: $pemilik <br>";

// Ambil data user
$sql99 = "SELECT * FROM `user` WHERE `USERNAME`='$pemilik' ";
$query99 = mysqli_query($conn, $sql99);
if ($data99 = mysqli_fetch_array($query99)) {
    $iduser = $data99['id'];
    $username = $data99['USERNAME'];
} else {
    echo "User tidak ditemukan.<br>";
    exit;
}

// Ambil network_devices untuk user
$devices = [];
$result = mysqli_query($conn, "SELECT * FROM network_devices WHERE user_id = '" . (int)$iduser . "'");
while ($row = mysqli_fetch_assoc($result)) {
    // Get server credentials for this IP
    $ip_clean = preg_replace('/:.*/', '', $row['ip_address']); // remove port if any
    $server_query = mysqli_query($conn, "SELECT * FROM server WHERE IP LIKE '" . mysqli_real_escape_string($conn, $ip_clean) . "%' AND user_id = '" . (int)$iduser . "' LIMIT 1");
    if ($srv = mysqli_fetch_assoc($server_query)) {
        $row['pemilik'] = $srv['PEMILIK'];
        $row['password'] = $srv['PASSWORD'];
    }
    $devices[] = $row;
}

if (empty($devices)) {
    echo "Tidak ada device untuk update.<br>";
    exit;
}

foreach ($devices as $dev) {
    $deviceId = $dev['id'];
    $ip = $dev['ip_address'];
    $pemilik_dev = $dev['pemilik'] ?? '';
    $password = $dev['password'] ?? '';

    if (empty($pemilik_dev) || empty($password)) {
        echo "Device $deviceId ($ip) tidak punya credentials, skip.<br>";
        continue;
    }

    echo "Update device $deviceId ($ip)<br>";

    // Load trafik data
    $API = new RouterosAPI();
    if ($API->connect($ip, $pemilik_dev, $password)) {
        // PPPoE trafik
        $pppoe_servers = $API->comm('/interface/pppoe-server/server/print');
        $totalTx = 0;
        $totalRx = 0;
        foreach ($pppoe_servers as $srv) {
            if (!isset($srv['interface'])) continue;
            $ifname = $srv['interface'];
            $data = $API->comm('/interface/monitor-traffic', [
                'interface' => $ifname,
                'once' => ''
            ]);
            $tx = isset($data[0]['tx-bits-per-second']) ? (int)$data[0]['tx-bits-per-second'] : 0;
            $rx = isset($data[0]['rx-bits-per-second']) ? (int)$data[0]['rx-bits-per-second'] : 0;
            $totalTx += $tx;
            $totalRx += $rx;
        }

        // Hotspot trafik
        $hotspot_servers = $API->comm('/ip/hotspot/print');
        foreach ($hotspot_servers as $srv) {
            if (!isset($srv['interface'])) continue;
            $ifname = $srv['interface'];
            $data = $API->comm('/interface/monitor-traffic', [
                'interface' => $ifname,
                'once' => ''
            ]);
            $tx = isset($data[0]['tx-bits-per-second']) ? (int)$data[0]['tx-bits-per-second'] : 0;
            $rx = isset($data[0]['rx-bits-per-second']) ? (int)$data[0]['rx-bits-per-second'] : 0;
            $totalTx += $tx;
            $totalRx += $rx;
        }

        // Convert to Mbps
        $totalTxMbps = round($totalTx / 1_000_000, 2);
        $totalRxMbps = round($totalRx / 1_000_000, 2);

        // Active counts
        $pppoe_active = $API->comm('/ppp/active/print');
        $pppoe_active_count = count($pppoe_active);

        $hotspot_active = $API->comm('/ip/hotspot/active/print');
        $hotspot_active_count = count($hotspot_active);

        $API->disconnect();

        // Load existing history for trafik
        $history_file_trafik = "../../getdata/traffic/history_trafik_{$deviceId}.json";
        $history_trafik = ['labels' => [], 'rx' => [], 'tx' => []];
        if (file_exists($history_file_trafik)) {
            $history_trafik = json_decode(file_get_contents($history_file_trafik), true);
            if (!is_array($history_trafik)) $history_trafik = ['labels' => [], 'rx' => [], 'tx' => []];
        }

        // Update history
        $now = new DateTime();
        $timeStr = $now->format('H:i:s');
        $history_trafik['labels'][] = $timeStr;
        $history_trafik['rx'][] = $totalRxMbps;
        $history_trafik['tx'][] = $totalTxMbps;

        // Limit to 100 points
        if (count($history_trafik['labels']) > 100) {
            array_shift($history_trafik['labels']);
            array_shift($history_trafik['rx']);
            array_shift($history_trafik['tx']);
        }

        file_put_contents($history_file_trafik, json_encode($history_trafik));

        // Load existing history for active
        $history_file_active = "../../getdata/traffic/history_active_{$deviceId}.json";
        $history_active = ['labels' => [], 'pppoe' => [], 'hotspot' => []];
        if (file_exists($history_file_active)) {
            $history_active = json_decode(file_get_contents($history_file_active), true);
            if (!is_array($history_active)) $history_active = ['labels' => [], 'pppoe' => [], 'hotspot' => []];
        }

        // Update history
        $history_active['labels'][] = $timeStr;
        $history_active['pppoe'][] = $pppoe_active_count;
        $history_active['hotspot'][] = $hotspot_active_count;

        // Limit to 100 points
        if (count($history_active['labels']) > 100) {
            array_shift($history_active['labels']);
            array_shift($history_active['pppoe']);
            array_shift($history_active['hotspot']);
        }

        file_put_contents($history_file_active, json_encode($history_active));

        echo "Updated trafik: RX $totalRxMbps Mbps, TX $totalTxMbps Mbps<br>";
        echo "Updated active: PPPoE $pppoe_active_count, Hotspot $hotspot_active_count<br>";
    } else {
        // Sebelumnya kegagalan konek cuma di-echo (kebuang oleh redirect cron
        // `> /dev/null 2>&1`), jadi tidak pernah tercatat. Catatan: script ini
        // tidak mengirim WhatsApp sama sekali (murni update data grafik trafik
        // untuk dashboard), jadi ini bukan bug notifikasi WA -- cuma kegagalan
        // konek RouterOS yang sebelumnya tidak ter-log sama sekali.
        echo "Gagal konek ke $ip<br>";
        $historyFileGrafik = "../data/history-$pemilik.json";
        $historyGrafik = file_exists($historyFileGrafik) ? json_decode(file_get_contents($historyFileGrafik), true) : [];
        if (!is_array($historyGrafik)) $historyGrafik = [];
        $historyGrafik[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] GAGAL update grafik: tidak bisa konek ke device $deviceId ($ip)";
        file_put_contents($historyFileGrafik, json_encode($historyGrafik, JSON_PRETTY_PRINT));
    }
}

echo "Update grafik selesai.<br>";
?>