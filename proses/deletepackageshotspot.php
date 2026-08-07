<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require('../routeros_api.class.php');
require('../cek-sesi.php'); // Pastikan koneksi database sudah ada

$API = new RouterosAPI();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $id = intval($_POST['id']);
    $pemilik = $_POST['pemilik'];
    $area = $_POST['area'];
    $paket = $_POST['paket'];

    // Cek apakah data ada
    $check = mysqli_query($conn, "SELECT * FROM paket_hotspot WHERE id = $id");
    if (mysqli_num_rows($check) > 0) {
        // Cek apakah paket masih digunakan di Mikrotik
        $still_in_use = false;
        $sql = "SELECT * FROM `server` WHERE `pemilik`='$pemilik' and `area`='$area'";
        $query = mysqli_query($conn, $sql);
        while ($data = mysqli_fetch_array($query)) {
            $username1 = $data['PEMILIK'];
            $host1 = $data['IP'];
            $password1 = $data['PASSWORD'];

            if ($API->connect($host1, $username1, $password1)) {
                // Cek apakah ada user yang menggunakan profile ini
                $users = $API->comm("/ip/hotspot/user/print", ["?profile" => $paket]);
                if (!empty($users)) {
                    $still_in_use = true;
                }
                $API->disconnect();
            }
        }
        if ($still_in_use) {
            header("Location: ../packageshotspot.php?msg=still_in_use");
            exit;
        }

        $delete = mysqli_query($conn, "DELETE FROM paket_hotspot WHERE id = $id");
        if ($delete) {
            // Hapus profile dari Mikrotik setelah konfirmasi tidak digunakan
            $sql = "SELECT * FROM `server` WHERE `pemilik`='$pemilik' and `area`='$area'";
            $query = mysqli_query($conn, $sql);
            while ($data = mysqli_fetch_array($query)) {
                $username1 = $data['PEMILIK'];
                $host1 = $data['IP'];
                $password1 = $data['PASSWORD'];

                if ($API->connect($host1, $username1, $password1)) {
                    // Ambil ID profile berdasarkan nama
                    $profiles = $API->comm("/ip/hotspot/user/profile/print", [
                        "?name" => $paket
                    ]);

                    if (!empty($profiles)) {
                        $profileId = $profiles[0][".id"];

                        // Hapus profile
                        $API->comm("/ip/hotspot/user/profile/remove", [
                            ".id" => $profileId
                        ]);
                    }

                    $API->disconnect();
                }
            }

            // Logging
            $history_file = "../notifbot/data/history-$ceknama.json";
            $history = [];
            if (file_exists($history_file)) {
                $history = json_decode(file_get_contents($history_file), true);
            }
            if (!is_array($history)) $history = [];
            $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Berhasil menghapus paket hotspot '$paket', area '$area', pemilik '$pemilik'";
            file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

            header("Location: ../packageshotspot.php?msg=deleted");
            exit;
        } else {
            header("Location: ../packageshotspot.php?msg=delete_failed");
            exit;
        }
    } else {
        header("Location: ../packageshotspot.php?msg=not_found");
        exit;
    }
} else {
    header("Location: ../packageshotspot.php");
    exit;
}
?>