<?php
header('Content-Type: application/json; charset=utf-8');
require 'header_api.php'; // Session and database connection

// Get user and check session
$nama = isset($_GET['nama']) ? mysqli_real_escape_string($conn, $_GET['nama']) : '';

if (empty($nama)) {
    echo json_encode(['success' => false, 'message' => 'Nama user tidak ditemukan']);
    exit;
}

// Get user's saldo from database
$sql_saldo = "SELECT SALDO FROM user WHERE NAMA = '$nama'";
$result_saldo = mysqli_query($conn, $sql_saldo);
$row_saldo = mysqli_fetch_array($result_saldo);
$saldo = isset($row_saldo['SALDO']) ? 'Rp ' . number_format($row_saldo['SALDO'], 0, ',', '.') : 'Rp 0';

// Get VPN list for this user
$sql = "SELECT * FROM pelanggan WHERE BRAND LIKE 'VPN' AND NAMA = '$nama' ORDER BY id DESC";
$result = mysqli_query($conn, $sql);

if (!$result) {
    echo json_encode(['success' => false, 'message' => 'Query error: ' . mysqli_error($conn)]);
    exit;
}

$vpnData = [];

while ($row = mysqli_fetch_array($result)) {
    $id = $row['id'];
    $mode = $row['MODE'];
    $ipremot = $row['ALAMAT'];
    $user = $row['IDPEL'];
    $pass = $row['IDPEL'];
    $paket = $row['PAKET'];
    $tikor = $row['TIKOR'];
    $tanggalPasang = $row['TANGGALPASANG'];
    
    // Parse remote address
    $vpn_ip = '';
    $vpn_port = '';
    $remot_port_to = '';
    
    if (!empty($ipremot)) {
        $parts = explode(':', $ipremot);
        $vpn_ip = isset($parts[0]) ? $parts[0] : '';
        
        // Find port mapping
        if (count($parts) >= 3) {
            $mapping = $parts[count($parts) - 1];
        } elseif (count($parts) === 2) {
            $mapping = $parts[1];
        } else {
            $mapping = '';
        }
        
        if (!empty($mapping) && strpos($mapping, '=>') !== false) {
            list($port_in, $port_out) = explode('=>', $mapping, 2);
            $vpn_port = trim($port_in);
            $remot_port_to = trim($port_out);
        } else {
            $vpn_port = trim($mapping);
        }
    }
    
    // Calculate expiration date
    $date = new DateTime($tanggalPasang);
    $date->modify('+30 days');
    $expired = $date->format('Y-m-d');
    
    // Load config for domain
    $config_file = dirname(__FILE__) . '/../config.json';
    $config = [];
    if (file_exists($config_file)) {
        $config = json_decode(file_get_contents($config_file), true);
    }
    $domainaccept = isset($config['domain']) ? $config['domain'] : 'example.com';
    $radius_ip = isset($config['radius_ip']) ? $config['radius_ip'] : '192.168.1.1';
    $radius_source = isset($config['radius_source']) ? $config['radius_source'] : 'local';
    
    // Generate scripts
    $script = "/interface $mode-client add name=$user connect-to=$vpn_ip user=$user password=$pass keepalive-timeout=60 use-peer-dns=no add-default-route=no dial-on-demand=no allow=mschap2,mschap1,chap,pap disabled=no comment=expired_$expired\n\n";
    
    if (($radius_source ?? 'local') === 'local') {
        $script .= "/ip route add dst-address=$radius_ip gateway=$user\n\n";
    }
    
    $script .= "/ip hotspot walled-garden\n";
    $script .= "add dst-host=$domainaccept action=allow\n";
    $script .= "add dst-host=www.$domainaccept action=allow\n";
    $script .= "add dst-host=*.$domainaccept action=allow\n";
    
    // SSTP alternative script
    $scriptSstp = "/interface sstp-client add name=$user connect-to=$vpn_ip:4433 user=$user password=$pass profile=default-encryption verify-server-certificate=no authentication=mschap2 add-default-route=no disabled=no comment=expired_$expired\n\n";
    
    if (($radius_source ?? 'local') === 'local') {
        $scriptSstp .= "/ip route add dst-address=$radius_ip gateway=$user\n\n";
    }
    
    $scriptSstp .= "/ip hotspot walled-garden\n";
    $scriptSstp .= "add dst-host=$domainaccept action=allow\n";
    $scriptSstp .= "add dst-host=www.$domainaccept action=allow\n";
    $scriptSstp .= "add dst-host=*.$domainaccept action=allow\n";
    
    // Port forwarding script
    $script2 = "/ip/firewall/nat/add chain=dstnat action=dst-nat protocol=tcp dst-address=$tikor dst-port=$remot_port_to to-addresses=[GANTI IP PERANGKAT ANDA] to-ports=[PORT TUJUAN PERANGKAT ANDA] comment=REMOT TO LOKAL";
    
    $vpnData[] = [
        'id' => $id,
        'mode' => $mode,
        'vpn_ip' => $vpn_ip,
        'vpn_port' => $vpn_port,
        'remot_port_to' => $remot_port_to,
        'user' => $user,
        'pass' => $pass,
        'paket' => $paket,
        'expired' => $expired,
        'script' => $script,
        'script_sstp' => $scriptSstp,
        'script2' => $script2
    ];
}

echo json_encode([
    'success' => true,
    'data' => $vpnData,
    'saldo' => $saldo
]);
mysqli_close($conn);
?>
