<?php
require '../cek-sesi.php';
   
 // ambil konfigurasi
$config_file = '../config.json';
$config = file_exists($config_file) ? json_decode(file_get_contents($config_file), true) : [];
//server utama
$ippublic = $config['ippublic'];
$webiplocal = $config['webiplocal'];

   $router_user = $config['router_user'];
    $router_pass = $config['router_pass'];
    $router_ip = $config['router_ip'];
    $ippublic = $config['ippublic'];
    $webiplocal = $config['webiplocal'];






require('routeros_api.class.php'); // pastikan file ini ada
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
$id    = $_POST['id'];
$user  = $_POST['user'];
$tipe  = $_POST['tipe'];
$remot = $_POST['remot'];
$page  = $_POST['page'] ?? 'vpnadmin'; // default vpnadmin

 if ($tipe === 'delete') {
// ALAMAT sekarang bisa berisi beberapa mapping port sekaligus (4 port layanan):
// IP:pub1=>dst1,pub2=>dst2,... - ambil semua kombinasi "IP:pub_port" yang ada.
$remot_ip = explode(':', $remot)[0];
$ip_port_candidates = [$remot_ip];
if (strpos($remot, ':') !== false) {
    $mapping_part = substr($remot, strpos($remot, ':') + 1);
    foreach (explode(',', $mapping_part) as $pair) {
        $pub_port = explode('=>', trim($pair))[0];
        if ($pub_port !== '') {
            $ip_port_candidates[] = $remot_ip . ':' . trim($pub_port);
        }
    }
}
$ip_port_list = "'" . implode("','", array_map(function ($v) use ($conn) {
    return mysqli_real_escape_string($conn, $v);
}, array_unique($ip_port_candidates))) . "'";

// Cek apakah ada server yang menggunakan salah satu IP:port dari VPN ini
$cek_server = mysqli_query($conn, "SELECT COUNT(*) as jumlah FROM server WHERE IP IN ($ip_port_list)");
$row_cek = mysqli_fetch_assoc($cek_server);
if ($row_cek['jumlah'] > 0) {
    // Jika masih ada server yang menggunakan salah satu IP:port ini, jangan hapus
    header("Location: ../$page.php?statusnotif=cannot_delete");
    exit;
}

// Hapus dari database
$stmt = $conn->prepare("DELETE FROM pelanggan WHERE id = ?");
$stmt->bind_param("i", $id);
if ($stmt->execute()) {
$API2 = new RouterosAPI();

$host =  $router_ip;
$login_user = $router_user;
$login_pass =  $router_pass;

if ($API2->connect($host, $login_user, $login_pass)) {
// echo "Terhubung ke MikroTik $host\n";

// Hapus SEMUA rule NAT punya VPN ini (bisa lebih dari 1 - sampai 4 port layanan
// sekaligus), dicocokkan lewat comment yang selalu memuat username VPN-nya.
if ($user) {
$API2->write('/ip/firewall/nat/print');
$rules = $API2->read();

foreach ($rules as $rule) {
                                                if (isset($rule['comment']) && strpos($rule['comment'], $user) !== false) {
                                                    $API2->write('/ip/firewall/nat/remove', false);
                                                    $API2->write('=.id=' . $rule['.id']);
                                                    $API2->read();
                                                }
                                            }
                                        }

                                        // Tambahan: Hapus secret berdasarkan username
                                        $API2->write('/ppp/secret/print', false);
                                        $API2->write('?name=' . $user);
                                        $secrets = $API2->read();

                                        if (!empty($secrets)) {
                                            foreach ($secrets as $secret) {
                                                echo "→ Menghapus secret: {$secret['name']} (ID: {$secret['.id']})\n";
                                                $API2->write('/ppp/secret/remove', false);
                                                $API2->write('=.id=' . $secret['.id']);
                                                $API2->read();
                                            }
                                        } else {
                                            // echo "❌ Secret dengan username $user tidak ditemukan.\n";
                                        }

                                        $API2->disconnect();
                                    } else {
                                        // echo "❌ Gagal konek ke MikroTik.\n";
                                    }

                                    // echo "</pre>";

                                } else {
                                    $error = addslashes($stmt->error);
                                 header("Location: ../$page.php");
                                }

                                $stmt->close();
                            }
                        }

                        // log history
                        $history_file = "../notifbot/data/history-$ceknama.json";
                        $history = [];
                        if (file_exists($history_file)) {
                            $history = json_decode(file_get_contents($history_file), true);
                        }
                        if (!is_array($history)) { $history = []; }
                        $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Berhasil hapus VPN $user";
                        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

                           header("Location: ../$page.php?statusnotif=success_delete");
                        ?>