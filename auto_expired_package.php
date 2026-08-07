<?php
// Script: auto_expired_package.php
// Fungsi: Pastikan setiap server memiliki profile PPPoE EXPIRED dengan local IP 15.15.15.1 dan rate-limit 0/0 (drop)
require_once 'routeros_api.class.php';
require_once 'koneksidb.php'; // Pastikan koneksi DB

function ensure_expired_pppoe_profile($server_ip, $pemilik, $password) {
    $API = new RouterosAPI();
    if ($API->connect($server_ip, $pemilik, $password)) {
        // Pastikan profile PPPoE EXPIRED ada di router
        $profiles = $API->comm("/ppp/profile/print", ["?name" => "EXPIRED"]);
        if (empty($profiles)) {
            $API->comm("/ppp/profile/add", [
                "name" => "EXPIRED",
                "local-address" => "15.15.15.1",
                "remote-address" => "",
                "rate-limit" => "0/0"
            ]);
        } else {
            // Update rate-limit jika perlu
            $API->comm("/ppp/profile/set", [
                ".id" => $profiles[0]['.id'],
                "rate-limit" => "0/0"
            ]);
        }
        $API->disconnect();
        return true;
    }
    return false;
}

// Ambil semua server dari database
$res = mysqli_query($conn, "SELECT * FROM server");
while ($srv = mysqli_fetch_assoc($res)) {
    ensure_expired_pppoe_profile($srv['IP'], $srv['PEMILIK'], $srv['PASSWORD']);
}
