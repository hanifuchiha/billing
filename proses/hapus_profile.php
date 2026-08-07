<?php
require('../routeros_api.class.php');
require '../cek-sesi.php'; // Pastikan koneksi database sudah ada

$API = new RouterosAPI();
$profileName = $_GET['profile'];


$server_list = array_map('trim', explode(',', $server_list)); // Ubah ke array & hilangkan spasi
$server_list = "" . implode(",", $server_list) . ""; // Tambahkan kutip di setiap nilai

echo $sql = "SELECT  * FROM `server`  WHERE  `pemilik` IN ($server_list)";
$query = mysqli_query($conn, $sql);
while ($data = mysqli_fetch_array($query)) {
    echo  $usernameip = $data['PEMILIK'];
    echo   $hostip     = $data['IP'];
    echo  $passwordip = $data['PASSWORD'];


    if ($API->connect($hostip, $usernameip, $passwordip)) {
        // Cari ID profil PPP yang akan dihapus
        $profiles = $API->comm("/ppp/profile/print", [
            "?name" => $profileName
        ]);

        if (!empty($profiles)) {
            $id = $profiles[0][".id"];
            $API->comm("/ppp/profile/remove", [
                ".id" => $id
            ]);
        }







        // Cari ID profil Hotspot yang akan dihapus
        $profiles = $API->comm("/ip/hotspot/user/profile/print", [
            "?name" => $profileName
        ]);

        if (!empty($profiles)) {
            $id = $profiles[0][".id"];
            $API->comm("/ip/hotspot/user/profile/remove", [
                ".id" => $id
            ]);
        }


        $API->disconnect();
    }
}


///////////////////catatat di log////////////////////

// Cek apakah sudah pernah dikirim
$history_file = "../notifbot/data/history-$ceknama.json";
$history = [];

if (file_exists($history_file)) {
    $history = json_decode(file_get_contents($history_file), true);
}

// Pastikan format history adalah array
if (!is_array($history)) {
    $history = [];
}


$history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Berhasil hapus paket $profileName";
// Simpan ke file history
file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));



////////////////////////////////////////////////////







header("Location: ../dashboard.php?success=1");
