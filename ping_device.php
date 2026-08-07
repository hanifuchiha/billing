<?php
// ping_device.php
header('Content-Type: application/json');
if (!isset($_GET['ip'])) {
    echo json_encode(['status' => 'error', 'msg' => 'No IP provided']);
    exit;
}

// Support IP:PORT, use fsockopen (like telnet) if port present
$ip_input = $_GET['ip'];
$ip = $ip_input;
$port = null;
if (strpos($ip_input, ':') !== false) {
    list($ip, $port) = explode(':', $ip_input, 2);
    $ip = preg_replace('/[^0-9a-fA-F\.:]/', '', $ip);
    $port = (int)preg_replace('/[^0-9]/', '', $port);
} else {
    $ip = preg_replace('/[^0-9a-fA-F\.:]/', '', $ip);
}
if (!$ip) {
    echo json_encode(['status' => 'error', 'msg' => 'Invalid IP']);
    exit;
}

if ($port) {
    // Telnet-like TCP connect
    $start = microtime(true);
    $fp = @fsockopen($ip, $port, $errno, $errstr, 1.5); // 1.5s timeout
    $latency = null;
    if ($fp) {
        $latency = round((microtime(true) - $start) * 1000, 2); // ms
        fclose($fp);
        $isOnline = true;
    } else {
        $isOnline = false;
    }
    echo json_encode([
        'status' => 'ok',
        'online' => $isOnline,
        'latency' => $latency
    ]);
    // Simpan last online jika online
    if ($isOnline) {
        $file = 'history-last-online.txt';
        $data = [];
        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true) ?: [];
        }
        $data[$ip_input] = date('Y-m-d H:i:s');
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
    }
    exit;
}

// Windows ping, 1 packet, 1s timeout
// Detect OS and use appropriate ping command
if (strncasecmp(PHP_OS, 'WIN', 3) == 0) {
    // Windows
    $output = shell_exec("ping -n 1 -w 1000 " . escapeshellarg($ip));
    $isOnline = (stripos($output, 'TTL=') !== false);
    $latency = null;
    if (preg_match('/Average = ([0-9]+)ms/i', $output, $matches)) {
        $latency = (int)$matches[1];
    } elseif (preg_match('/Minimum = ([0-9]+)ms/i', $output, $matches)) {
        $latency = (int)$matches[1];
    } elseif (preg_match('/time[=<]([0-9]+)ms/i', $output, $matches)) {
        $latency = (int)$matches[1];
    }
} else {
    // Linux/Unix
    $output = shell_exec("ping -c 1 -W 1 " . escapeshellarg($ip));
    $isOnline = (stripos($output, 'ttl=') !== false);
    $latency = null;
    if (preg_match('/time=([0-9.]+) ms/', $output, $matches)) {
        $latency = (float)$matches[1];
    }
}
echo json_encode([
    'status' => 'ok',
    'online' => $isOnline,
    'latency' => $latency
]);
// Simpan last online jika online
if ($isOnline) {
    $file = 'history-last-online.txt';
    $data = [];
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true) ?: [];
    }
    $data[$ip_input] = date('Y-m-d H:i:s');
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
}
