<?php
require('../routeros_api.class.php');
require '../cek-sesi.php';


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $server = mysqli_real_escape_string($conn, $_POST['server']);
    $area = mysqli_real_escape_string($conn, $_POST['area']);
    $service_name = mysqli_real_escape_string($conn, $_POST['service_name']);
    $interface = mysqli_real_escape_string($conn, $_POST['interface']);

    // Ambil data server MikroTik dari database
    $sql = "SELECT * FROM `server` WHERE `AREA` = '$area' AND `PEMILIK` = '$server'";
    $query = mysqli_query($conn, $sql);

    if (!$query) {
        die(json_encode(["status" => "error", "message" => "Database query failed: " . mysqli_error($conn)]));
    }

    $API = new RouterosAPI();
    $API->debug = false;

    while ($data = mysqli_fetch_array($query)) {
        $username = $data['PEMILIK'];
        $ipRouterOS = $data['IP'];
        $password = $data['PASSWORD'];

        if ($API->connect($ipRouterOS, $username, $password)) {
            // Tambahkan PPPoE Server Interface secara eksplisit dengan parameter yang valid
            $response = $API->comm("/interface/pppoe-server/server/add", array(
                "interface" => $interface,
                "service-name" => $service_name,
                "default-profile" => "default",
                "authentication" => "pap,chap,mschap1,mschap2",
                "keepalive-timeout" => "10",
                "one-session-per-host" => "yes",
                "max-mtu" => "1480",
                "max-mru" => "1480",
                "disabled" => "no"
            ));


            if (isset($response['!trap'])) {
                echo json_encode(["status" => "error", "message" => "Gagal menambahkan PPPoE Server Interface", "error" => $response]);
            } else {
                echo json_encode(["status" => "success", "message" => "PPPoE Server Interface berhasil ditambahkan"]);
                // log history
                $history_file = "../notifbot/data/history-$ceknama.json";
                $history = [];
                if (file_exists($history_file)) {
                    $history = json_decode(file_get_contents($history_file), true);
                }
                if (!is_array($history)) { $history = []; }
                $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Menambahkan PPPoE server interface $service_name untuk area $area";
                file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
            }

            $API->disconnect();
        } else {
            echo json_encode(["status" => "error", "message" => "Gagal terhubung ke RouterOS"]);
        }
        header("Location: ../interface.php"); // Redirect kembali ke halaman utama

    }
    header("Location: ../interface.php"); // Redirect kembali ke halaman utama
    exit;
}
