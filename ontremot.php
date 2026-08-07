<?php
require '../cek-sesi.php';
require '../routeros_api.class.php';
error_reporting(E_ALL);
ini_set('display_errors', 1);



// ambil konfigurasi
$config_file = '../config.json';
$config = file_exists($config_file) ? json_decode(file_get_contents($config_file), true) : [];

//server utama
$router_ip = $config['router_ip'];
$router_user = $config['router_user'];
$router_pass = $config['router_pass'];
$vpn_ip_local = $config['vpn_ip_local'];
//servertujuan 




// -- SETTING DEFAULT KONEKSI MIKROTIK (ubah bila perlu) --
$SERVER_MAIN_IP = $router_ip;
$SERVER_MAIN_API_PORT = 8728;
$SERVER_MAIN_USER = $router_user;
$SERVER_MAIN_PASS = $router_pass;

// $REMOTE_DEFAULT_USER = 'FIBERQ';
// $REMOTE_DEFAULT_PASS = 'FIBERQ@QTS';
// $vpn_ip_local ='54.54.54.1';


function rand_secret_name($prefix = 'rmt'){
    return $prefix . '-' . bin2hex(random_bytes(3));
}
function rand_secret_password(){
    return bin2hex(random_bytes(4));
}

function pick_free_vpn_ip($API) {
    $used = [];
    $secrets = $API->comm('/ppp/secret/print');
    if (is_array($secrets)) {
        foreach ($secrets as $row) {
            if (!empty($row['remote-address'])) {
                $used[$row['remote-address']] = true;
            }
        }
    }
    $active = $API->comm('/ppp/active/print');
    if (is_array($active)) {
        foreach ($active as $row) {
            if (!empty($row['address'])) {
                $used[$row['address']] = true;
            }
        }
    }
    for ($i = 2; $i <= 254; $i++) {
        $ip = "10.10.10.$i";
        if (!isset($used[$ip])) {
            return $ip;
        }
    }
    return false;
}
function pick_free_public_port($API){
    $used = [];
    $list = $API->comm('/ip/firewall/nat/print');
    if (is_array($list)){
        foreach($list as $row){
            if (isset($row['dst-port'])){
                $ports = explode(',', $row['dst-port']);
                foreach($ports as $p) $used[trim($p)] = true;
            }
        }
    }
    for($p=4001;$p<=4999;$p++) if (!isset($used[$p])) return $p;
    return false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST'){




                    $mode = $_POST['mode'] ?? 'VPN';
                    $customer = $_POST['idPel'];
                    $remote_pppoe_ip =  $_POST['remote_ip'];
                    $pppoe_user = $_POST['idPel'];
                    $pppoe_pass = $_POST['idPel'];
                    $web_port_choice ='80';
                    $remote_mk_ip = $_POST['ipServer'];
                    $remote_api_user = $_POST['userServer'];
                    $remote_api_pass = $_POST['passwordServer'];

                $ceklocal=$vpn_ip_local.":".$SERVER_MAIN_API_PORT;
                    if (empty($remote_mk_ip)){
                        echo '<div style="color:red">IP Mikrotik tujuan (remote) harus diisi.</div>';
                        exit;
                    }
                    

    if ($remote_mk_ip == $ceklocal){
                                        $API = new RouterosAPI();
                                        $API->debug = false;
                                        if ($API->connect($SERVER_MAIN_IP.":".$SERVER_MAIN_API_PORT, $SERVER_MAIN_USER, $SERVER_MAIN_PASS)) {
                                            $vpn_ip = pick_free_vpn_ip($API);
                                            if (!$vpn_ip){
                                                echo '<div style="color:red">VPN IP FULL</div>';
                                                $API->disconnect();
                                                exit;
                                            }

                                            $secret_name = rand_secret_name('secret');
                                            $secret_pass = rand_secret_password();
                                            $comment = $customer !== '' ? $customer : "auto-$secret_name";

                                       
                                            $public_port = pick_free_public_port($API);
                                            if (!$public_port){
                                                echo '<div style="color:red">VPN PORT FULL</div>';
                                                $API->disconnect();
                                                exit;
                                            }

                                            $nat_comment = "vpnmap:$comment";
                                            $API->comm('/ip/firewall/nat/add', [
                                                'chain'=>'dstnat',
                                                'dst-address'=>$SERVER_MAIN_IP,
                                                'protocol'=>'tcp',
                                                'dst-port'=>(string)$public_port,
                                                'action'=>'dst-nat',
                                                'to-addresses'=>$remote_pppoe_ip,
                                                'to-ports'=>'80',
                                                'comment'=>$nat_comment
                                            ]);

                                            $API->comm('/system/scheduler/add', [
                                                'name' => "expire-$comment",
                                                'on-event' => '/ip firewall nat remove [find dst-port="' . $public_port . '"]; /system scheduler remove [find name="expire-' . $comment . '"];',
                                                'start-time' => '00:00:00',
                                                'interval' => '00:10:00'
                                            ]);


                                                $API->disconnect();
                                              

                                            echo '
                                                <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
                                    </head>
                                    <div class="container mt-4">
                                    <div class="row justify-content-center">
                                        <div class="col-md-8">
                                        <div class="card shadow-lg border-0">
                                            <div class="card-header bg-primary text-white">
                                            <h5 class="mb-0">VPN Remote Mapping Berhasil</h5>
                                            </div>
                                            <div class="card-body">
                                            <p><strong>Customer:</strong> ' . htmlspecialchars($customer) . '</p>
                                            <p class="text-danger"><i class="bi bi-clock-history"></i> Remote ini hanya berlaku <strong>10 menit</strong> kemudian akan otomatis terhapus.</p>
                                            <div class="alert alert-info">
                                                <strong>Public Access:</strong><br>
                                                <a href="http://' . $SERVER_MAIN_IP . ':' . $public_port . '" target="_blank">
                                                http://' . $SERVER_MAIN_IP . ':' . $public_port . '
                                                </a>
                                            </div>
                                            </div>
                                            <div class="card-footer text-muted">
                                            Dibuat pada: ' . date("d-m-Y H:i:s") . '
                                            </div>
                                        </div>
                                        </div>
                                    </div>
                                    </div>
                                    ';
                                    exit;

                                            } else {
                                                echo '<div style="color:red">Gagal konek ke Mikrotik Tujuan.</div>';
                                            }
      } else {

        //////VIA VPN


                                            $API = new RouterosAPI();
                                        $API->debug = false;
                                        if ($API->connect($SERVER_MAIN_IP.":".$SERVER_MAIN_API_PORT, $SERVER_MAIN_USER, $SERVER_MAIN_PASS)) {
                                            $vpn_ip = pick_free_vpn_ip($API);
                                            if (!$vpn_ip){
                                                echo '<div style="color:red">VPN IP FULL</div>';
                                                $API->disconnect();
                                                exit;
                                            }

                                            $secret_name = rand_secret_name('secret');
                                            $secret_pass = rand_secret_password();
                                            $comment = $customer !== '' ? $customer : "auto-$secret_name";

                                            // tambah secret
                                            $API->comm('/ppp/secret/add', [
                                                'name' => $secret_name,
                                                'password' => $secret_pass,
                                                'local-address' => $vpn_ip_local,
                                                'remote-address' => $vpn_ip,
                                                'service' => 'l2tp',
                                                'comment' => $comment
                                            ]);

                                            $public_port = pick_free_public_port($API);
                                            if (!$public_port){
                                                echo '<div style="color:red">VPN PORT FULL</div>';
                                                $API->disconnect();
                                                exit;
                                            }

                                            $nat_comment = "vpnmap:$comment";
                                            $API->comm('/ip/firewall/nat/add', [
                                                'chain'=>'dstnat',
                                                'dst-address'=>$SERVER_MAIN_IP,
                                                'protocol'=>'tcp',
                                                'dst-port'=>(string)$public_port,
                                                'action'=>'dst-nat',
                                                'to-addresses'=>$vpn_ip,
                                                'to-ports'=>$public_port,
                                                'comment'=>$nat_comment
                                            ]);

                                                $API->comm('/system/scheduler/add', [
                                                    'name' => "expire-$secret_name",
                                                    'on-event' => '/ppp secret remove [find name="' . $secret_name . '"]; /ip firewall nat remove [find dst-port="' . $public_port . '"]; /system scheduler remove [find name="expire-' . $secret_name . '"];',
                                                    'start-time' => '00:00:00',
                                                    'interval' => '00:10:00'
                                                ]);


                                            // konek ke remote
                                            $API_remote = new RouterosAPI();
                                            $API_remote->debug = false;
                                            if ($API_remote->connect($remote_mk_ip, $remote_api_user, $remote_api_pass)) {
                                                $l2tp_name = 'l2tp-to-main-' . bin2hex(random_bytes(2));
                                                $API_remote->comm('/interface/l2tp-client/add', [
                                                    'name'        => $l2tp_name,
                                                    'connect-to'  => $SERVER_MAIN_IP,
                                                    'user'        => $secret_name,
                                                    'password'    => $secret_pass,
                                                    'profile'     => 'default',
                                                    'use-ipsec'   => 'no',
                                                    'add-default-route' => 'no',
                                                    'dial-on-demand'    => 'no',
                                                    'disabled'   => 'no',
                                                ]);



                                            // Hitung network address untuk /24
                                            $octets = explode('.', $remote_pppoe_ip);
                                            $network = $octets[0] . '.' . $octets[1] . '.' . $octets[2] . '.0/24';





                                                $API_remote->comm('/ip/firewall/filter/add', [
                                                    'chain'        => 'srcnat',
                                                    'in-interface' => $l2tp_name,
                                                    'dst-address'  => $network,
                                                    'action'       => 'accept',
                                                    'comment'      => $nat_comment
                                                ]);

                                                $API_remote->comm('/ip/firewall/nat/add', [
                                                    'chain'=>'dstnat',
                                                    'dst-address'=>$vpn_ip,
                                                    'protocol'=>'tcp',
                                                    'dst-port'=>(string)$public_port,
                                                    'action'=>'dst-nat',
                                                    'to-addresses'=>$remote_pppoe_ip,
                                                    'to-ports'=>$web_port_choice,
                                                    'comment'=>$nat_comment
                                                ]);

                                             $API_remote->comm('/system/scheduler/add', [
                                                'name' => "expire-$l2tp_name",
                                                'on-event' => '/interface l2tp-client remove [find name="' . $l2tp_name . '"]; /ip firewall nat remove [find dst-port="' . $public_port . '"]; /system scheduler remove [find name="expire-' . $l2tp_name . '"];',
                                                'start-time' => '00:00:00',
                                                'interval' => '00:10:00'
                                            ]);


                                              
                                                $API_remote->disconnect();

                                            echo '
                                                <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
                                    </head>
                                    <div class="container mt-4">
                                    <div class="row justify-content-center">
                                        <div class="col-md-8">
                                        <div class="card shadow-lg border-0">
                                            <div class="card-header bg-primary text-white">
                                            <h5 class="mb-0">VPN Remote Mapping Berhasil</h5>
                                            </div>
                                            <div class="card-body">
                                            <p><strong>Customer:</strong> ' . htmlspecialchars($customer) . '</p>
                                            <p class="text-danger"><i class="bi bi-clock-history"></i> Remote ini hanya berlaku <strong>10 menit</strong> kemudian akan otomatis terhapus.</p>
                                            <div class="alert alert-info">
                                                <strong>Public Access:</strong><br>
                                                <a href="http://' . $SERVER_MAIN_IP . ':' . $public_port . '" target="_blank">
                                                http://' . $SERVER_MAIN_IP . ':' . $public_port . '
                                                </a>
                                            </div>
                                            </div>
                                            <div class="card-footer text-muted">
                                            Dibuat pada: ' . date("d-m-Y H:i:s") . '
                                            </div>
                                        </div>
                                        </div>
                                    </div>
                                    </div>
                                    ';
                                    exit;

                                            } else {
                                                echo '<div style="color:red">Gagal konek ke Mikrotik Tujuan.</div>';
                                            }
        }
                                        
    }
}
   

?>