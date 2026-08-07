<?php
// File: api/traffic_idpel.php
header('Content-Type: application/json');
require_once '../koneksibilling.php';


$idpel = $_GET['idpel'] ?? '';
if (!$idpel) {
    echo json_encode(['success' => false, 'error' => 'Parameter kurang (idpel)']);
    exit;
}

// Ambil data pelanggan
$stmt = $conn->prepare("SELECT AREA, PEMILIK, BRAND FROM pelanggan WHERE IDPEL = ? LIMIT 1");
$stmt->bind_param("s", $idpel);
$stmt->execute();
$res = $stmt->get_result();
if (!($row = $res->fetch_assoc())) {
    echo json_encode(['success' => false, 'error' => 'IDPEL tidak ditemukan']);
    exit;
}
$area = $row['AREA'];
$pemilik = $row['PEMILIK'];
$brand = $row['BRAND'];

// Cari data server berdasarkan AREA dan SERVER/PEMILIK
$stmt2 = $conn->prepare("SELECT * FROM server WHERE AREA = ? AND PEMILIK = ? LIMIT 1");
$stmt2->bind_param("ss", $area, $pemilik);
$stmt2->execute();
$res2 = $stmt2->get_result();
if (!($srv = $res2->fetch_assoc())) {
    echo json_encode(['success' => false, 'error' => 'Server tidak ditemukan untuk area dan pemilik ini']);
    exit;
}

$host = $srv['IP'] ?? '192.168.88.1';
$user = $srv['PEMILIK'] ?? 'admin';
$pass = $srv['PASSWORD'] ?? 'password';



// Ambil data dari cache
$historyDir = __DIR__ . '/trafik-cache';
$historyFile = $historyDir . "/trafik_{$idpel}.json";
if (file_exists($historyFile)) {
    $history = json_decode(file_get_contents($historyFile), true);
    // Return only last 10 data points for chart display
    $history = array_slice($history, 0, 10);
    echo json_encode([
        'success' => true,
        'data' => $history
    ]);
    exit;
}
    // Jika cache tidak ada, polling langsung ke Mikrotik
    require_once __DIR__ . '/routeros_api.class.php';
    $API = new RouterosAPI();
    $rx = 0;
    $tx = 0;
    $iface = null;
    $result = [];
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
        // Ambil cache lama jika ada
        $old_history = [];
        if (file_exists($historyFile)) {
            $old_history = json_decode(file_get_contents($historyFile), true);
            if (!is_array($old_history)) $old_history = [];
        }
        // Tambahkan data terbaru ke depan
        $new_entry = [
            'rx' => $rx,
            'tx' => $tx,
            'time' => date('Y-m-d H:i:s'),
            'interface' => $iface
        ];
        array_unshift($old_history, $new_entry);
        // Batasi hanya 50 data terbaru
        $history = array_slice($old_history, 0, 50);
        // Simpan ke cache
        if (!is_dir($historyDir)) {
            mkdir($historyDir, 0777, true);
        }
        file_put_contents($historyFile, json_encode($history));
        echo json_encode([
            'success' => true,
            'data' => $history
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Gagal konek ke Mikrotik']);
    }

