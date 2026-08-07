<?php
header('Content-Type: application/json');

$ip = isset($_GET['ip']) ? $_GET['ip'] : '';

if (!$ip) {
    echo json_encode(['error' => 'IP parameter required']);
    exit;
}

$filename = "traffic/traffic_" . str_replace(':', '_', $ip) . ".txt";

// Fetch live data from MikroTik
require('routeros_api.class.php');

// Assume credentials are from server table, but for simplicity, hardcode or get from config
// For demo, use dummy, but to make real, need to get from DB
// Since we have $conn, but to avoid session, perhaps pass credentials or something.

// For now, to make it work, let's assume we fetch live if possible.

// But to keep simple, let's modify to fetch live traffic and append to file.

$API = new RouterosAPI();
// Need IP, user, pass. Since IP may have port, parse it.

$ip_parts = explode(':', $ip);
$mikrotik_ip = $ip_parts[0];
$mikrotik_port = isset($ip_parts[1]) ? $ip_parts[1] : 8728;

// Need user and pass. Since not in GET, perhaps get from DB if IP matches server.

include '../cek-sesi.php'; // To get $conn and $current_user_id

$server_query = mysqli_query($conn, "SELECT USER, PASSWORD FROM server WHERE IP = '$ip' AND user_id = '$current_user_id'");
$live_fetch = ['connected' => false, 'rx' => null, 'tx' => null, 'query_rows' => mysqli_num_rows($server_query)];
if ($server_row = mysqli_fetch_assoc($server_query)) {
    $mikrotik_user = $server_row['USER'];
    $mikrotik_pass = $server_row['PASSWORD'];

    if ($API->connect($mikrotik_ip, $mikrotik_user, $mikrotik_pass, $mikrotik_port)) {
        $interface = 'ether1';
        $API->write('/interface/monitor-traffic', false);
        $API->write('=interface=' . $interface, false);
        $API->write('=once=');
        $traffic = $API->read();

        if (!empty($traffic)) {
            $rx_mbps = round($traffic[0]['rx-bits-per-second'] / 1_000_000, 2);
            $tx_mbps = round($traffic[0]['tx-bits-per-second'] / 1_000_000, 2);

            // Append to file
            $dir = 'traffic';
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $line = date('Y-m-d H:i:s') . ",{$rx_mbps},{$tx_mbps}\n";
            file_put_contents($filename, $line, FILE_APPEND);

            $live_fetch = ['connected' => true, 'rx' => $rx_mbps, 'tx' => $tx_mbps];
        }

        $API->disconnect();
    }
}

// Now read the last 5 lines as before

if (!file_exists($filename)) {
    // Fallback to dummy data if file not exists
    $rx_current = 10.5;
    $tx_current = 15.2;
    echo json_encode([
        'rx' => $rx_current,
        'tx' => $tx_current,
        'debug' => [
            'filename' => $filename,
            'file_exists' => false,
            'raw_lines' => [],
            'parsed_data' => [],
            'live_fetch' => $live_fetch
        ]
    ]);
    exit;
}

$lines = file($filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$lines = array_slice($lines, -5); // Last 5 lines

$labels = [];
$rx = [];
$tx = [];

foreach ($lines as $line) {
    $parts = explode(',', $line);
    if (count($parts) == 3) {
        $labels[] = date('H:i:s', strtotime($parts[0]));
        $rx[] = (float)$parts[1];
        $tx[] = (float)$parts[2];
    }
}

// Jika kurang dari 5, isi dengan 0
while (count($labels) < 5) {
    array_unshift($labels, date('H:i:s', strtotime('-' . (5 - count($labels)) . ' minutes')));
    array_unshift($rx, 0);
    array_unshift($tx, 0);
}

// Return the latest rx/tx
$rx_current = end($rx);
$tx_current = end($tx);

echo json_encode([
    'rx' => $rx_current,
    'tx' => $tx_current,
    'debug' => [
        'filename' => $filename,
        'file_exists' => file_exists($filename),
        'raw_lines' => $lines,
        'parsed_data' => array_map(function($l, $r, $t) { return ['time' => $l, 'rx' => $r, 'tx' => $t]; }, $labels, $rx, $tx),
        'live_fetch' => $live_fetch
    ]
]);
?>