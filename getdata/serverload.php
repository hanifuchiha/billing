<?php
// include '../cek-sesi.php'; // Hapus untuk crontab, karena tidak ada session di CLI

require('../koneksibilling.php');
require('../routeros_api.class.php');
require_once('../radius_sync_lib.php');

// error_reporting(E_ALL);
// ini_set('display_errors', 1);

// Pastikan path absolut untuk file
$base_dir = realpath(__DIR__ . '/../');
$serverlog_dir = $base_dir . '/serverlog';
$notifbot_data_dir = $base_dir . '/notifbot/data';
$gateway_file = realpath($base_dir . '/../login/gateway.txt'); // ../../login/gateway.txt dari getdata/

echo "Base dir: $base_dir<br>";
echo "Serverlog dir: $serverlog_dir<br>";
echo "Notifbot data dir: $notifbot_data_dir<br>";
echo "Gateway file: $gateway_file<br><br>";

$API = new RouterosAPI();

// Peta username -> Mikrotik-Group dari file FreeRADIUS (satu kali baca untuk
// seluruh proses, dipakai untuk menentukan status EXPIRED pelanggan MODE
// RADIUS/API yang tidak (selalu) punya secret lokal di router -- lihat
// getpackagefromradius.php untuk pola yang sama.
$radiusGroupByUsername = [];
if (function_exists('radiusReadMergedBlocks')) {
    foreach (radiusReadMergedBlocks() as $rb) {
        if ($rb['username'] === null) continue;
        if (preg_match('/Mikrotik-Group\s*:=\s*"([^"]+)"/', $rb['raw'], $gm)) {
            $radiusGroupByUsername[$rb['username']] = $gm[1];
        }
    }
}

// Hapus file gateway.txt lama sebelum menulis ulang
if (file_exists($gateway_file)) {
    unlink($gateway_file);
}

// Membuka file untuk menambahkan data (append)
$file = fopen($gateway_file, 'a');
if (!$file) {
    // Handle error if needed
}

$ip_added = false; // Flag untuk melacak apakah IP valid telah ditambahkan

// Query untuk mengambil semua server
$result = mysqli_query($conn, "SELECT * FROM server");

while ($data = mysqli_fetch_array($result)) {
    $usernameip = $data['PEMILIK'];
    $hostip     = $data['IP'];
    $passwordip = $data['PASSWORD'];

    echo "🔄 Memproses server: $hostip (pemilik: $usernameip)<br>";

    if ($API->connect($hostip, $usernameip, $passwordip)) {
        echo "✅ Terhubung ke $hostip<br>";
        // Mengambil daftar Hotspot Profile
        $API->write('/ip/hotspot/profile/print');
        $profiles = $API->read();


        // Menambahkan IP address yang terkait dengan Hotspot Profile ke dalam file
        foreach ($profiles as $profile) {
            // Periksa apakah profile memiliki parameter 'hotspot-address'
            if (isset($profile['hotspot-address'])) {
                $hotspot_address = $profile['hotspot-address'];

                // Memeriksa apakah alamat IP bukan 0.0.0.0
                if ($hotspot_address != "0.0.0.0") {
                    // Menulis hotspot-address ke file, tanpa menghapus isi lama
                    fwrite($file, $hotspot_address . "\n");
                    echo "📝 Menambahkan IP hotspot: $hotspot_address<br>";
                    $ip_added = true; // Set flag jika IP valid ditambahkan
                }
            }

            // Jika 'hotspot-address' tidak ditemukan, coba periksa parameter lain yang mungkin relevan
            if (isset($profile['address'])) {
                $address = $profile['address'];

                // Memeriksa apakah alamat IP bukan 0.0.0.0
                if ($address != "0.0.0.0") {
                    fwrite($file, $address . "\n");
                    echo "📝 Menambahkan IP address: $address<br>";
                    $ip_added = true; // Set flag jika IP valid ditambahkan
                }
            }
        }
        $API->disconnect();
        echo "🔌 Terputus dari $hostip<br>";
    } else {
        echo "❌ Gagal terhubung ke $hostip<br>";
    }
}



fclose($file);

// Set permissions to 777 for gateway file
chmod($gateway_file, 0777);



if (!is_dir($serverlog_dir)) {
    mkdir($serverlog_dir, 0777, true);
}

// --- Ambil semua user atau user spesifik ---
$target_username = isset($_GET['username']) ? trim($_GET['username']) : null;

if ($target_username) {
    // Proses hanya user tertentu
    $sqlUsers = "SELECT id, USERNAME, STATUS, grup, server FROM `user` WHERE USERNAME = '" . mysqli_real_escape_string($conn, $target_username) . "' LIMIT 1";
} else {
    // Proses semua user (fallback)
    $sqlUsers = "SELECT id, USERNAME, STATUS, grup, server FROM `user` WHERE 1";
}

$resUsers = mysqli_query($conn, $sqlUsers);
if (!$resUsers) {
    die("❌ Query users gagal: " . mysqli_error($conn));
}

while ($userRow = mysqli_fetch_assoc($resUsers)) {
    $user_id = $userRow['id'];
    $username = $userRow['USERNAME'];
    $user_status = $userRow['STATUS'];

    if ($user_status == 'ASSISTANT') {
        $asistant_name = $username;
    } else {
        $asistant_name = $username;
    }

    // Ambil semua server milik user ini (berdasarkan user_id di tabel server)
    if ($user_status == 'ASSISTANT') {
        // Untuk asisten, ambil server berdasarkan area dari server yang di-assign
        $server_json = isset($userRow['server']) ? $userRow['server'] : '';
        if (!empty($server_json)) {
            $server_ids = json_decode($server_json, true);
            if (is_array($server_ids)) {
                $id_in = implode(",", array_map('intval', $server_ids));
                $sql_area = "SELECT DISTINCT AREA FROM server WHERE id IN ($id_in)";
                $res_area = mysqli_query($conn, $sql_area);
                $areas = [];
                while ($row = mysqli_fetch_assoc($res_area)) {
                    $areas[] = $row['AREA'];
                }
                if (!empty($areas)) {
                    $area_in = "'" . implode("','", array_map('addslashes', $areas)) . "'";
                    $sqlServers = "SELECT * FROM `server` WHERE `AREA` IN ($area_in)";
                } else {
                    $sqlServers = false;
                }
            } else {
                $sqlServers = false;
            }
        } else {
            $sqlServers = false;
        }
    } else {
        $sqlServers = "SELECT * FROM `server` WHERE `user_id` = '" . mysqli_real_escape_string($conn, $user_id) . "'";
    }
    if (!$sqlServers) {
        echo "⚠️ User $username tidak punya server (ASSISTANT tanpa area)<br>";
        continue;
    }
    $resServers = mysqli_query($conn, $sqlServers);
    if (!$resServers) {
        echo "⚠️ Query server gagal untuk user $username: " . mysqli_error($conn) . "<br>";
        continue;
    }
    if (mysqli_num_rows($resServers) == 0) {
        echo "⚠️ User $username tidak punya server<br>";
        continue;
    }

    // --- Inisialisasi ---
    $total_users = 0;
    $active_users = 0;
    $expired_online_count = 0;
    $total_expired = 0;
    $no_secret = 0;
    $LOS = 0;
    $offline_non_expired_ids = [];
    $expired_ids = [];
    $los_ids = [];
    $odp_stats = [];
    $pppoe_profiles = [];
    $hotspot_profiles = [];

    while ($data = mysqli_fetch_assoc($resServers)) {
        $usernameip = $data['PEMILIK'];
        $hostip     = $data['IP'];
        $passwordip = $data['PASSWORD'];
        $area = $data['AREA'];

        echo "🔹 [$username] proses server $hostip (pemilik $usernameip, area $area)<br>";

        // Get pelanggan for this area
        $sql_pelanggan = "SELECT * FROM pelanggan WHERE area = '" . mysqli_real_escape_string($conn, $area) . "'";
        $res_pelanggan = mysqli_query($conn, $sql_pelanggan);
        $pelanggan_list = [];
        while ($p = mysqli_fetch_assoc($res_pelanggan)) {
            $pelanggan_list[] = $p;
        }
        $total_users += count($pelanggan_list);

        if ($API->connect($hostip, $usernameip, $passwordip)) {
            // PPPoE
            $API->write('/ppp/secret/print'); $secrets = $API->read();
            $API->write('/ppp/active/print'); $actives = $API->read();

            // Check for each pelanggan
            foreach ($pelanggan_list as $p) {
                $username = $p['IDPEL'] ?? '';
                if (empty($username)) {
                    echo "⚠️ Username not found for pelanggan id " . ($p['id'] ?? 'unknown') . "<br>";
                    continue;
                }

                $odp_name = trim((string)($p['ODP'] ?? ''));
                $odp_key = '';
                if ($odp_name !== '') {
                    $odp_key = strtoupper($usernameip . '||' . $area . '||' . $odp_name);
                    if (!isset($odp_stats[$odp_key])) {
                        $odp_stats[$odp_key] = [
                            'ODP' => $odp_name,
                            'AREA' => $area,
                            'PEMILIK' => $usernameip,
                            'TOTAL_PELANGGAN' => 0,
                            'TOTAL_ONLINE' => 0,
                            'TOTAL_LOS' => 0,
                            'IDPELS' => []
                        ];
                    }
                    $odp_stats[$odp_key]['TOTAL_PELANGGAN']++;
                    if (!in_array($username, $odp_stats[$odp_key]['IDPELS'], true)) {
                        $odp_stats[$odp_key]['IDPELS'][] = $username;
                    }
                }

                // Check profile for EXPIRED
                $profile = null;
                foreach ($secrets as $s) {
                    if (isset($s['name']) && $s['name'] == $username) {
                        $profile = $s['profile'];
                        break;
                    }
                }

                // Pelanggan MODE RADIUS (atau tanpa secret lokal sama sekali,
                // termasuk API MODE) -- ambil status EXPIRED dari FreeRADIUS
                // (Mikrotik-Group), bukan dari secret lokal yang memang tidak
                // pernah ada untuk mereka. MULTI MODE tetap dipercaya dari
                // secret lokal (mereka punya secret valid juga), kecuali
                // secret lokalnya kosong -- sama seperti pola di tables.php.
                $modeVal = strtoupper((string)($p['MODE'] ?? ''));
                $isRadiusMode = (strpos($modeVal, 'RADIUS') !== false);
                if (($isRadiusMode || $profile === null) && isset($radiusGroupByUsername[$username])) {
                    $profile = $radiusGroupByUsername[$username];
                }

                if ($profile === null) {
                    $no_secret++;
                }
                if ($profile === 'EXPIRED') {
                    $total_expired++;
                    if (!in_array($username, $expired_ids, true)) {
                        $expired_ids[] = $username;
                    }
                }

                // Check if online
                $is_online = false;
                foreach ($actives as $a) {
                    if (isset($a['name']) && $a['name'] == $username) {
                        $is_online = true;
                        $active_users++;
                        break;
                    }
                }
                if ($profile === 'EXPIRED' && $is_online) {
                    $expired_online_count++;
                }

                if ($odp_key !== '') {
                    if ($is_online) {
                        $odp_stats[$odp_key]['TOTAL_ONLINE']++;
                    } else {
                        $odp_stats[$odp_key]['TOTAL_LOS']++;
                    }
                }

                if (!$is_online) {
                    $LOS++;
                    if (!in_array($username, $los_ids, true)) {
                        $los_ids[] = $username;
                    }
                    if ($profile !== 'EXPIRED') {
                        $offline_non_expired_ids[] = $username;
                    }
                }
            }

            // Profiles count - hitung pengguna per profile
            $API->write('/ppp/profile/print'); $profiles = $API->read();
            foreach ($profiles as $p) {
                if ($p['name'] != 'EXPIRED') {
                    if (!isset($pppoe_profiles[$p['name']])) {
                        $pppoe_profiles[$p['name']] = 0;
                    }
                }
            }

            // Hitung pengguna per PPPoE profile
            foreach ($secrets as $secret) {
                if (isset($secret['profile']) && $secret['profile'] != 'EXPIRED') {
                    if (isset($pppoe_profiles[$secret['profile']])) {
                        $pppoe_profiles[$secret['profile']]++;
                    }
                }
            }

            // Hotspot profiles (keep as is)
            $API->write('/ip/hotspot/user/profile/print'); $profiles = $API->read();
            foreach ($profiles as $p) {
                if (!isset($hotspot_profiles[$p['name']])) {
                    $hotspot_profiles[$p['name']] = 0;
                }
            }

            $API->disconnect();
        } else {
            echo "⚠️ Gagal konek ke $hostip ($usernameip)<br>";
        }

        usleep(500000);
    }









echo "<br>";
echo "<br>Total Data Pelanggan = ".$total_users;
echo "<br>Expired = ".$total_expired;
echo "<br>Expired Online = ".$expired_online_count;
echo "<br>Expired Offline = ".($total_expired - $expired_online_count);
echo "<br>Active = ".$active_users;
echo "<br>Active Non-Expired = ".($active_users - $expired_online_count);
echo "<br>Offline = ".($total_users - $active_users);
echo "<br>Offline Non-Expired = ".(($total_users - $active_users) - ($total_expired - $expired_online_count));
echo "<br>No Secret = ".$no_secret;
echo "<br>";
echo "<br>";




    // Harga paket
    $paket_harga = [];
    $sql_paket = "SELECT `PAKET`, `HARGA` FROM `paket`";
    $result_paket = mysqli_query($conn, $sql_paket);
    while ($row = mysqli_fetch_assoc($result_paket)) {
        $paket_harga[$row['PAKET']] = $row['HARGA'];
    }

    // --- simpan ke file (per user) ---
    $filename = $serverlog_dir . "/$asistant_name.txt";
    if (file_exists($filename)) unlink($filename);

    $data_json = [
        'odp_summary' => array_values($odp_stats),
        'odp_all_los' => array_values(array_filter(array_values($odp_stats), function ($item) {
            return (int)($item['TOTAL_PELANGGAN'] ?? 0) > 0 && (int)($item['TOTAL_ONLINE'] ?? 0) === 0;
        })),
        'Total_pelanggan' => $total_users,
        'Total_online' => $active_users,
        'Total_online_expired' => $expired_online_count,
        'Total_online_paket' => $active_users - $expired_online_count,
        'Total_expired_offline' => ($total_expired - $expired_online_count),
        'Total_los_internet' => (($total_users - $active_users) - ($total_expired - $expired_online_count)),
        'Total_expired' => $total_expired,
        'expired_ids' => array_values(array_unique($expired_ids)),
        'los_ids' => array_values(array_unique($los_ids)),
        'offline_non_expired_ids' => array_values(array_unique($offline_non_expired_ids)),
        'pppoe_profiles' => $pppoe_profiles,
        'hotspot_profiles' => $hotspot_profiles,
        'paket_harga' => $paket_harga,
        'updated_at' => date('Y-m-d H:i:s')
    ];
    file_put_contents($filename, json_encode($data_json, JSON_PRETTY_PRINT));

    // Set permissions to 777
    chmod($filename, 0777);

    // --- Catat log ke history ---
    $history_file = $notifbot_data_dir . "/history-$asistant_name.json";
    if (!is_dir($notifbot_data_dir)) {
        mkdir($notifbot_data_dir, 0777, true);
    }
    $history = [];
    if (file_exists($history_file)) {
        $history = json_decode(file_get_contents($history_file), true) ?: [];
    }
    // $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Loading server data for user $asistant_name";
    // file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    // Set permissions to 777
    chmod($history_file, 0777);

    echo "✅ Data user $username disimpan ke $filename<br>";
}