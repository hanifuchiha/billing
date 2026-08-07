<?php
include '../cek-sesi.php';
require '../routeros_api.class.php';
header('Content-Type: application/json');

$host = trim($_GET['ip'] ?? '');
$user = trim($_GET['us'] ?? '');
$pass = (string)($_GET['ps'] ?? '');

if ($host === '' || $user === '') {
    echo json_encode(['error' => 'Parameter ip/us tidak lengkap']);
    exit;
}

// --- Pisahkan host dan port jika ditulis "ip:port" ---
$port = 8728; // default API Mikrotik
if (strpos($host, ':') !== false) {
    [$hostOnly, $portPart] = explode(':', $host, 2);
    if ($hostOnly !== '') $host = $hostOnly;
    if (is_numeric($portPart)) $port = (int)$portPart;
}

// --- Lock per host:port:user, supaya server dgn IP+port sama tidak rebutan koneksi,
//     tapi server dgn IP+port sama namun user API berbeda tetap boleh paralel ---
$lockKey  = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $host . '_' . $port . '_' . $user);
$lockFile = sys_get_temp_dir() . '/mikrotik_lock_' . $lockKey . '.lock';
$lockHandle = @fopen($lockFile, 'c');

$gotLock = true; // default true kalau lock file gagal dibuat, supaya tidak memblokir total
if ($lockHandle) {
    $waitStart = microtime(true);
    $gotLock = flock($lockHandle, LOCK_EX | LOCK_NB);
    while (!$gotLock) {
        if (microtime(true) - $waitStart > 10) break; // tunggu maksimal 10 detik
        usleep(200000); // 200ms
        $gotLock = flock($lockHandle, LOCK_EX | LOCK_NB);
    }
}

if ($lockHandle && !$gotLock) {
    fclose($lockHandle);
    echo json_encode(['error' => 'Router sedang sibuk diakses request lain, coba lagi']);
    exit;
}

// --- Buat instance BARU setiap request (hindari koneksi/socket bekas nyangkut) ---
$API = new RouterosAPI();

if (property_exists($API, 'timeout'))  $API->timeout  = 4;
if (property_exists($API, 'attempts')) $API->attempts = 1;
if (property_exists($API, 'delay'))    $API->delay    = 1;
if (property_exists($API, 'port'))     $API->port     = $port;
if (property_exists($API, 'debug'))    $API->debug    = false;

$connected = false;
$exceptionMsg = null;
try {
    $connected = $API->connect($host, $user, $pass);
} catch (\Throwable $e) {
    $connected = false;
    $exceptionMsg = $e->getMessage();
}

if ($connected) {
    try {
        // === PPPoE Server Interface ===
        $pppoe_servers = $API->comm('/interface/pppoe-server/server/print');
        $pppoe_trafik = [];
        foreach ($pppoe_servers as $srv) {
            if (!isset($srv['interface'])) continue;
            $ifname = $srv['interface'];
            $data = $API->comm('/interface/monitor-traffic', [
                'interface' => $ifname,
                'once' => ''
            ]);
            $tx = isset($data[0]['tx-bits-per-second']) ? (int)$data[0]['tx-bits-per-second'] : 0;
            $rx = isset($data[0]['rx-bits-per-second']) ? (int)$data[0]['rx-bits-per-second'] : 0;
            if ($tx > 0 || $rx > 0) {
                $pppoe_trafik[] = [
                    'interface' => $ifname,
                    'output'    => round($tx / 1_000_000, 2),
                    'input'     => round($rx / 1_000_000, 2),
                ];
            }
        }

        // === Hotspot Server Interface ===
        $hotspot_servers = $API->comm('/ip/hotspot/print');
        $hotspot_trafik = [];
        foreach ($hotspot_servers as $srv) {
            if (!isset($srv['interface'])) continue;
            $ifname = $srv['interface'];
            $data = $API->comm('/interface/monitor-traffic', [
                'interface' => $ifname,
                'once' => ''
            ]);
            $tx = isset($data[0]['tx-bits-per-second']) ? (int)$data[0]['tx-bits-per-second'] : 0;
            $rx = isset($data[0]['rx-bits-per-second']) ? (int)$data[0]['rx-bits-per-second'] : 0;
            if ($tx > 0 || $rx > 0) {
                $hotspot_trafik[] = [
                    'interface' => $ifname,
                    'output'    => round($tx / 1_000_000, 2),
                    'input'     => round($rx / 1_000_000, 2),
                ];
            }
        }

        $pppoe_active = $API->comm('/ppp/active/print');
        $pppoe_active_count = count($pppoe_active);

        $hotspot_active = $API->comm('/ip/hotspot/active/print');
        $hotspot_active_count = count($hotspot_active);

        $resource = $API->comm('/system/resource/print');
        $identity = $API->comm('/system/identity/print');

        $API->disconnect();

        echo json_encode([
            'time' => date('H:i:s'),
            'pppoe_trafik' => $pppoe_trafik,
            'hotspot_trafik' => $hotspot_trafik,
            'pppoe_active_count' => $pppoe_active_count,
            'hotspot_active_count' => $hotspot_active_count,
            'cpu_load_percent' => $resource[0]['cpu-load'] ?? null,
            'memory' => [
                'free' => $resource[0]['free-memory'] ?? null,
                'total' => $resource[0]['total-memory'] ?? null
            ],
            'cpu_name' => $resource[0]['cpu'] ?? null,
            'identity' => $identity[0]['name'] ?? null
        ]);
    } catch (\Throwable $e) {
        try { $API->disconnect(); } catch (\Throwable $ignore) {}
        echo json_encode(['error' => 'Gagal mengambil data dari Mikrotik: ' . $e->getMessage()]);
    }
} else {
    $errStr = $API->error_str ?? ($API->getError ?? null);
    if (is_callable([$API, 'getError'])) {
        try { $errStr = $API->getError(); } catch (\Throwable $ignore) {}
    }
    $detail = $errStr ?: ($exceptionMsg ?: 'tidak diketahui (kemungkinan user/password API salah, atau service API tidak aktif di router)');

    echo json_encode([
        'error' => 'Koneksi ke Mikrotik gagal: ' . $detail,
        'debug' => [
            'host' => $host,
            'port' => $port,
            'user' => $user,
        ]
    ]);
}

if ($lockHandle) {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}
exit;