<?php
require_once 'routeros_api.class.php';
require_once 'koneksidb.php';
session_start();
$API = new RouterosAPI();
header('Content-Type: application/json');

$current_user_id = isset($_SESSION['id']) ? (int)$_SESSION['id'] : 0;
if (!$current_user_id) {
    echo json_encode(['status'=>'error','msg'=>'Not logged in']);
    exit;
}

$servers = [];
$sql = "SELECT * FROM server WHERE user_id = $current_user_id";
$query = mysqli_query($conn, $sql);
while ($data = mysqli_fetch_assoc($query)) {
    $servers[] = $data;
}

$result = [];
foreach ($servers as $srv) {
    $ip = $srv['IP'];
    $user = $srv['PEMILIK'];
    $pass = $srv['PASSWORD'];
    $area = $srv['AREA'];
    $brand = $srv['BRAND'] ?? '';
    $pppoe = [];
    if ($API->connect($ip, $user, $pass)) {
        $pppoeActives = $API->comm("/ppp/active/print");
        foreach ($pppoeActives as $ppp) {
            $pppoe[] = [
                'user' => $ppp['name'] ?? '',
                'ip' => $ppp['address'] ?? '',
                'server_ip' => $ip,
                'area' => $area,
                'brand' => $brand
            ];
        }
        $API->disconnect();
    }
    $result = array_merge($result, $pppoe);
}
echo json_encode(['status'=>'ok','data'=>$result]);