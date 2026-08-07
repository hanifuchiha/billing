<?php
require "../../employee/cek-sesi.php";
require "../../employee/koneksibilling.php";
$idserver = $_GET['idserver'];
$kodeodp = $_GET['kodeodp'];
if ($idserver == "") {
    header("location:serverlist.php");
}
$sql = 'SELECT * FROM server WHERE id=
' . $idserver . ' ';
$query = mysqli_query($conn, $sql);
while ($data = mysqli_fetch_array($query)) {
    $server = $data['AREA'];
    $user = $data['PEMILIK'];
    $ip = $data['IP'];
    $password = $data['PASSWORD'];
    $map = $data['MAP'];
    $waapi = $data['BOTWA'];
    $olt = $data['OLT'];
}
require('routeros_api.class.php');
$API = new RouterosAPI();
if ($API->connect($ip, $user, $password)) {
    $data =  $API->write('/ppp/active/print');
    $READ = $API->read(false);
    $ARRAY = $API->parseResponse($READ);
    $encode = json_encode($ARRAY);
}
$API2 = new RouterosAPI();
if ($API2->connect($ip, $user, $password)) {
    $data2 =  $API2->write('/ppp/secret/print');
    $READ2 = $API2->read(false);
    $ARRAY2 = $API2->parseResponse($READ2);
    $encode2 = json_encode($ARRAY2);
}

$data1 = count($ARRAY);
$data2 = count($ARRAY2);
$awaldhcp = 0;

if ($ARRAY2 == "") {
    echo "<tr>";
    echo "<td>LOAD DATA</td>";
    echo "</tr>";
}



for ($i = 0; $i < count($ARRAY2); $i++) {

    /////////////////////////////////////cek mode DHCP ///////////////////////////
    $ceknama = $ARRAY2[$i]['name'];
    $sql3 = "SELECT * FROM `pelanggan` WHERE `IDPEL` = '$ceknama' ";
    $query3 = mysqli_query($conn, $sql3);
    while ($data3 = mysqli_fetch_array($query3)) {
        $cekmode =  $data3['MODE'];
        if ($cekmode == "DHCP") {
            $dhcp = 1;
            $awaldhcp = $awaldhcp + $dhcp;
        }
    }
}


$offl = $data2 - $data1 - $awaldhcp;


// ========== PRE-LOAD SEMUA ACS DATA SEKALI DI AWAL ==========
// (Jangan include acs_cache_data.php di dalam loop - sangat slow!)
$acsDataMap = []; // Keyed by IDPEL
$cache_file = dirname(__DIR__) . '/notifdata/acs_devices_cache.json';
if (file_exists($cache_file)) {
    try {
        $cache_raw = @file_get_contents($cache_file);
        if ($cache_raw) {
            $cache_data = @json_decode($cache_raw, true);
            if (is_array($cache_data) && isset($cache_data['devices'])) {
                // Build keyed map: IDPEL => device
                // IDPEL stored in pppoe_username field
                foreach ($cache_data['devices'] as $device) {
                    $idpel = $device['pppoe_username'] ?? null;
                    if ($idpel) {
                        $acsDataMap[strtoupper(trim($idpel))] = $device;
                    }
                }
            }
        }
    } catch (Exception $e) {
        // Fallback jika error - tetap lanjut dengan acsDataMap kosong
    }
}


$nomor = 0;
$sql1 = "SELECT * from `pelanggan` WHERE `PEMILIK` = '$user' and `AREA`='$server' and `ODP`='$kodeodp' ";
$query1 = mysqli_query($conn, $sql1);
////////////seting penomoran urut table /////////       
while ($data1 = mysqli_fetch_array($query1)) {

    $nomor++;
    ////////////////////////cek online warna kolom ////////////////////
    if ($data1['MODE'] != "DHCP") {
        $interface = "<pppoe-" . $data1['IDPEL'] . ">";
        $getinterfacetraffic = $API->comm("/interface/monitor-traffic", array(
            "interface" => "$interface",
            "once" => "",
        ));
        if ($getinterfacetraffic[0]['tx-bits-per-second'] != "") {

            echo "<tr  class='table-success'>";
             $CEKLOS='';
        } else {


            echo "<tr  class='table-danger'>";

            $CEKLOS='LOS';
        }
    } else {
        if ($data1['MODE'] == "DHCP") {

            echo "<tr  class='table-success'>";
        } elseif ($data1['MODE'] == "PPPOE") {
            $cek = array_search($data1['IDPEL'], array_column($ARRAY2, 'name'));
            $cekexpired = $ARRAY2[$cek]['profile'];
            $remote = $ARRAY2[$cek]['remote_address'];
            $tambah = 1;
            if ($cekexpired == "EXPIRED") {
                echo "<tr  class='table-warning'>";
            }
        }
    }
    //////////////////////////////////////////////////////////////////
    $idselect = $data1['id'];
    echo "<td data-label='No'>" . $nomor . "</td>";
    //////////////////////////////////////////////////////////////////
    $cek = array_search($data1['IDPEL'], array_column($ARRAY2, 'name'));
    $cekexpired = $ARRAY2[$cek]['profile'];
    $remote = $ARRAY2[$cek]['last-caller-id'];
    $tambah = 1;
    if ($cekexpired != "EXPIRED") {
        $cek = $cek + $tambah;
    }

    ////////////////////////cek data mikrotik dan database////////////////////
    $cek2 = array_search($data1['IDPEL'], array_column($ARRAY, 'name'));
    $ipaddress = $ARRAY[$cek2]['address'];

    echo "<td data-label='Customer'>" . $data1['IDPEL'] . " - " . $data1['NAMA'] . "</td>";
 
echo "<td data-label='Power Ddm'>";
    
    // Inisialisasi variabel
    $rx_olt = 'Null';
    $rx_acs = 'Null';
    
    if($remote=='')
    {
        echo "<span class='power-indicator power-unknown'><i class='fas fa-question-circle'></i> Tidak terbaca</span>";
    }
    else
    {
        // ========== AMBIL RX dBm OLT dari onulist ==========
        $remote_prefix = substr($remote, 0, strrpos($remote, ':') + 1);
        
        $files = glob("../getdata/debug/onulist_*_{$server}_{$user}.txt");
        $found = false;
        
        if (!empty($files)) {
            foreach ($files as $file) {
                if ($found) break;
                if (file_exists($file)) {
                    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                    
                    foreach ($lines as $line) {
                        if ($found) break;
                        if (preg_match('/MAC:\s*([0-9a-fA-F:]+)/', $line, $matches)) {
                            $mac = $matches[1];
                            
                            if($mac == '') {
                                continue;
                            }
                            // cek prefix
                            if (stripos($mac, $remote_prefix) === 0) {
                                if (preg_match('/Data:\s*(.*)$/', $line, $matches_data)) {
                                    $parts = explode(',', $matches_data[1]);
                                    $rx_olt = $parts[11] ?? 'Null';
                                    $found = true;
                                    break;
                                }
                            }
                        }
                    }
                }
            }
        }
        
        // ========== AMBIL RX dBm ACS ==========
        $rx_acs = 'Null';
        $idpel = $data1['IDPEL'];
        
        // Lookup dari pre-loaded map (lebih cepat daripada include)
        $idpel_upper = strtoupper($idpel);
        if (isset($acsDataMap[$idpel_upper])) {
            $device = $acsDataMap[$idpel_upper];
            if (isset($device['rx_power'])) {
                $rx_acs = $device['rx_power'];
            }
        }

        
        // ========== TAMPILKAN DENGAN WARNA ==========
        echo "<div style='font-size: 0.9em; line-height: 1.6;'>";
        
        // RX OLT Badge
        $olt_badge_class = 'power-unknown';
        if ($rx_olt != 'Null' && $rx_olt != 'LOS') {
            $olt_val = floatval($rx_olt);
            $olt_badge_class = ($olt_val < -27) ? 'power-los' : 'power-good';
        }
        echo "<span class='power-indicator $olt_badge_class'>";
        echo "<strong>Redaman dari OLT:</strong> $rx_olt dBm";
        echo "</span><br>";
        
        // RX ACS Badge
        $acs_badge_class = 'power-unknown';
        if ($rx_acs != 'Null' && $rx_acs != 'LOS') {
            $acs_val = floatval($rx_acs);
            $acs_badge_class = ($acs_val < -27) ? 'power-los' : 'power-good';
        }
        echo "<span class='power-indicator $acs_badge_class'>";
        echo "<strong>Redaman dari ACS:</strong> $rx_acs dBm";
        echo "</span>";
        
        echo "</div>";
    }
echo "</td>";
    echo "<td data-label='Package Status / ONT Status'><span class='status-indicator ";
    if ($cekexpired == "EXPIRED") {
        echo "status-expired'><i class='fas fa-clock'></i> " . $cekexpired;
    } else {
        echo "status-online'><i class='fas fa-check-circle'></i> " . $cekexpired;
    }
    echo "</span><br>";


    ////////////////////////cek online////////////////////
    $interface = "<pppoe-" . $data1['IDPEL'] . ">";
    $getinterfacetraffic = $API->comm("/interface/monitor-traffic", array(
        "interface" => "$interface",
        "once" => "",
    ));
    if ($getinterfacetraffic[0]['tx-bits-per-second'] != "") {
        echo "<span class='status-indicator status-online'><i class='fas fa-wifi'></i> ONLINE PPPOE</span>";
    } else {
        if ($data1['MODE'] == "DHCP") {
            echo "<span class='status-indicator status-online'><i class='fas fa-network-wired'></i> ONLINE DHCP</span>";
        } else {
            $cek1 = array_search($data1['IDPEL'], array_column($ARRAY2, 'name'));
            $ceklastloggedout = $ARRAY2[$cek1]['last-logged-out'];
            $ceklastdisconnect = $ARRAY2[$cek1]['last-disconnect-reason'];
            echo "<span class='status-indicator status-offline'><i class='fas fa-times-circle'></i> OFFLINE</span><br><small>$ceklastloggedout<br>$ceklastdisconnect</small>";
        }
    }
    echo "</td></tr>";
}


$conn->close();
