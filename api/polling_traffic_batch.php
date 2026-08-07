<?php
// polling_traffic_batch.php
// Jalankan via cron setiap 5 menit untuk update cache trafik semua pelanggan aktif

require_once '../koneksibilling.php';
require_once '../routeros_api.class.php';

// Ambil semua pelanggan aktif
$sql = "SELECT IDPEL, AREA, PEMILIK FROM pelanggan WHERE STATUS = 'Aktif'";
$res = $conn->query($sql);
if (!$res) {
    die('Query pelanggan gagal');
}

while ($row = $res->fetch_assoc()) {
    $idpel = $row['IDPEL'];
    $area = $row['AREA'];
    $pemilik = $row['PEMILIK'];

    // Cari data server
    $stmt2 = $conn->prepare("SELECT * FROM server WHERE AREA = ? AND PEMILIK = ? LIMIT 1");
    $stmt2->bind_param("ss", $area, $pemilik);
    $stmt2->execute();
    $res2 = $stmt2->get_result();
    if (!($srv = $res2->fetch_assoc())) {
        continue;
    }
    $host = $srv['IP'] ?? '192.168.88.1';
    $user = $srv['PEMILIK'] ?? 'admin';
    $pass = $srv['PASSWORD'] ?? 'password';

    $API = new RouterosAPI();
    $rx = 0;
    $tx = 0;
    $iface = null;
    if ($API->connect($host, $user, $pass)) {
        $pppoe = $API->comm('/ppp/active/print', [ '?name' => $idpel ]);
        $found_iface = false;
        if (!empty($pppoe)) {
            foreach ($pppoe as $session) {
                if (isset($session['interface'])) {
                    $iface = $session['interface'];
                    $data = $API->comm('/interface/monitor-traffic', [
                        'interface' => $iface,
                        'once' => ''
                    ]);
                    $rx = isset($data[0]['rx-bits-per-second']) ? (int)$data[0]['rx-bits-per-second'] : 0;
                    $tx = isset($data[0]['tx-bits-per-second']) ? (int)$data[0]['tx-bits-per-second'] : 0;
                    $found_iface = true;
                    break;
                }
            }
        }
        // Fallback: jika tidak ada field interface
        if (!$found_iface) {
            if (strpos($idpel, '@') !== false) {
                $iface = '<pppoe-' . $idpel . '>';
            } else {
                $iface = 'pppoe-' . $idpel;
            }
            $data = $API->comm('/interface/monitor-traffic', [
                'interface' => $iface,
                'once' => ''
            ]);
            $rx = isset($data[0]['rx-bits-per-second']) ? (int)$data[0]['rx-bits-per-second'] : 0;
            $tx = isset($data[0]['tx-bits-per-second']) ? (int)$data[0]['tx-bits-per-second'] : 0;
        }
        $API->disconnect();
    }
    // Simpan ke cache
    $historyDir = __DIR__ . '/trafik-cache';
    if (!is_dir($historyDir)) {
        mkdir($historyDir, 0777, true);
    }
    $historyFile = $historyDir . "/trafik_{$idpel}.json";
    $history = file_exists($historyFile) ? json_decode(file_get_contents($historyFile), true) : [];
    $now = time();
    $history[] = [
        'timestamp' => $now,
        'rx' => $rx,
        'tx' => $tx,
        'interface' => $iface
    ];
    if (count($history) > 20) {
        $history = array_slice($history, -20);
    }
    file_put_contents($historyFile, json_encode($history));
}
