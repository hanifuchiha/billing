<?php
// get_device_info.php
header('Content-Type: application/json');
if (!isset($_GET['ip'])) {
    echo json_encode(['status' => 'error', 'msg' => 'No IP provided']);
    exit;
}
$ip = preg_replace('/[^0-9a-fA-F\.:]/', '', $_GET['ip']);
if (!$ip) {
    echo json_encode(['status' => 'error', 'msg' => 'Invalid IP']);
    exit;
}
// Coba baca MAC address dan identitas perangkat via ARP (Linux)
$mac = null;
$identity = null;
$arp = shell_exec("arp -n " . escapeshellarg($ip));
if (preg_match('/([0-9a-f]{2}(:[0-9a-f]{2}){5})/i', $arp, $m)) {
    $mac = $m[1];
}
// Coba SNMP sysName jika snmpwalk tersedia
if (function_exists('shell_exec')) {
    $snmp = @shell_exec("snmpget -v2c -c public " . escapeshellarg($ip) . " SNMPv2-MIB::sysName.0");
    if ($snmp && preg_match('/STRING: (.+)/', $snmp, $m)) {
        $identity = trim($m[1]);
    }
}
echo json_encode([
    'status' => 'ok',
    'mac' => $mac,
    'identity' => $identity
]);
