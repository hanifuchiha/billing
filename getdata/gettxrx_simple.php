<?php
// gettxrx_simple.php
// Return dummy RX/TX for a given IP or device id (for demo, replace with real logic)
header('Content-Type: application/json');
$ip = isset($_GET['ip']) ? $_GET['ip'] : '';
$rx = rand(100, 1000) . ' kbps';
$tx = rand(100, 1000) . ' kbps';
echo json_encode(['rx' => $rx, 'tx' => $tx]);
