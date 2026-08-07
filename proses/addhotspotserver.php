<?php
require('../routeros_api.class.php');
require '../cek-sesi.php';
// Pastikan koneksi database ada

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $server_name = mysqli_real_escape_string($conn, $_POST['server_name']);
    $address_pool = mysqli_real_escape_string($conn, $_POST['address_pool']);
    $profile = mysqli_real_escape_string($conn, $_POST['profile']);
    $addresses_per_mac = (int) $_POST['addresses_per_mac'];
    $ip_dns_name = mysqli_real_escape_string($conn, $_POST['ip_dns_name']);
    $server = mysqli_real_escape_string($conn, $_POST['server']);
    $area = mysqli_real_escape_string($conn, $_POST['area']);
    $interface = mysqli_real_escape_string($conn, $_POST['interface']);
    $address = mysqli_real_escape_string($conn, $_POST['address']);
    // Ambil data server MikroTik dari database
    $sql = "SELECT * FROM `server` WHERE `AREA` = '$area' AND `PEMILIK` = '$server'";
    $query = mysqli_query($conn, $sql);

    if (!$query) {
        die(json_encode(["status" => "error", "message" => "Database query failed: " . mysqli_error($conn)]));
    }

    while ($data = mysqli_fetch_array($query)) {
        $username = $data['PEMILIK'];
        $ipRouterOS = $data['IP'];
        $password = $data['PASSWORD'];

        $API = new RouterosAPI();

        if ($API->connect($ipRouterOS, $username, $password)) {

            // Tambahkan address list secara eksplisit
            $response0 = $API->comm("/ip/address/add", array(
                "address" => $address,
                "interface" => $interface,
                "comment" => "Alamat untuk hotspot",
                "disabled" => 'no'
            ));
            if (isset($response0['!trap'])) {
                echo json_encode(["status" => "error", "message" => "Gagal menambahkan address", "error" => $response0]);
                exit;
            }






            // Tambahkan IP Pool
            $response1 = $API->comm("/ip/pool/add", array(
                "name" => $server_name,
                "ranges" => $address_pool // Pastikan input sesuai format IP range
            ));
            if (isset($response1['!trap'])) {
                echo json_encode(["status" => "error", "message" => "Gagal menambahkan IP Pool", "error" => $response1]);
                exit;
            }



            $response2 = $API->comm("/ip/hotspot/profile/add", array(
                "name" => $server_name,
                "dns-name" => $ip_dns_name,
                "hotspot-address" => "0.0.0.0",
                "html-directory" => "hotspot"
            ));

            if (isset($response2['!trap'])) {
                echo json_encode(["status" => "error", "message" => "Gagal menambahkan server profile", "error" => $response2]);
                exit;
            }

            // Tambahkan server hotspot
            $response3 =  $API->comm("/ip/hotspot/add", array(
                "name" => $server_name,
                "interface" => $interface,
                "address-pool" => $server_name, // Pastikan pool tersedia
                "profile" => $server_name,
                "addresses-per-mac" => $addresses_per_mac,
                "disabled" => 'no'

            ));
            if (isset($response3['!trap'])) {
                echo json_encode(["status" => "error", "message" => "Gagal menambahkan  server hotspot", "error" => $response3]);
                exit;
            }




            $API->disconnect();
            echo json_encode(["status" => "success", "message" => "Server Hotspot berhasil ditambahkan"]);
            // log history
            $history_file = "../notifbot/data/history-$ceknama.json";
            $history = [];
            if (file_exists($history_file)) {
                $history = json_decode(file_get_contents($history_file), true);
            }
            if (!is_array($history)) { $history = []; }
            $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Menambahkan server hotspot $server_name untuk area $area";
            file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
            header("Location: ../interface.php"); // Redirect kembali ke halaman utama
            exit;
        } else {
            echo json_encode([
                "status" => "error",
                "message" => "Gagal terhubung ke MikroTik",
                "details" => ["IP" => $ipRouterOS, "User" => $username]
            ]);
            exit;
        }
    }











    header("Location: ../interface.php"); // Redirect kembali ke halaman utama
    exit;
}
