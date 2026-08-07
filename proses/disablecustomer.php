<?php

require '../cek-sesi.php';
require '../routeros_api.class.php';
require_once __DIR__ . '/../radius_sync_lib.php';

session_start(); // Gunakan session
header("Content-Type: application/json");

// Ambil domain dari konfigurasi atau sesuaikan
$domain = $config['domain'];

function getMonthName($month, $year)
{
    $months = ["Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"];
    if ($month > 12) {
        $month = 1;
        $year++;
    }
    return $months[$month - 1] . ' ' . $year;
}

$currentDate = date('d-m-Y');
$currentDay = date('d');
$currentMonth = date('m');
$currentYear = date('Y');

// Baca konfigurasi reminder
$jsonFile = "../notifbot/data/reminder-$ceknama.json";
if (file_exists($jsonFile)) {
    $jsonData = file_get_contents($jsonFile);
    $data = json_decode($jsonData, true);
    if ($data !== null) {
        foreach ($data as $item) {
            $tempo = $item['jatuh_tempo'];
            $tanggal_awal_tutup_buku = $item['tanggal_awal_tutup_buku'];
            $tanggal_akhir_tutup_buku = $item['tanggal_akhir_tutup_buku'];
        }
    }
}

// Tentukan periode berdasarkan jatuh tempo
if ($currentDay <= $tempo) {
    $periode = getMonthName($currentMonth, $currentYear);
} else {
    $periode = getMonthName($currentMonth + 1, $currentYear);
}

$tanggalbayar = date('Y-m-d');

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Ambil data dari form
    $nama = $_POST["NAMA"] ?? "";
    $idpel = $_POST["IDPEL"] ?? "";
    $nowa = $_POST["NOWA"] ?? "";
    $paket = $_POST["PAKET"] ?? "";
    $PEMILIK = $_POST["PEMILIK"] ?? "";
    $AREA = $_POST["AREA"] ?? "";

    # DISABLE USER DAN HAPUS KONEKSI AKTIF DI MIKROTIK ===========================
    $berhasil = "Proses disable dimulai\n";
    $total_servers = 0;
    $success_servers = 0;
    $profile_status = "";
    
    $sql = "SELECT * FROM `server` WHERE `AREA` = '$AREA' and `PEMILIK`= '$PEMILIK' ";
    $query = mysqli_query($conn, $sql);
    while ($data = mysqli_fetch_array($query)) {
        $total_servers++;

        $user = $data['PEMILIK'];
        $ip = $data['IP'];
        $password = $data['PASSWORD'];

        $API = new RouterosAPI();
        $berhasil .= "Server $ip: ";

        if ($API->connect($ip, $user, $password)) {
            $berhasil .= "Koneksi berhasil";
            
            // Test basic API functionality
            $systemInfo = $API->comm("/system/identity/print");
            if (!empty($systemInfo)) {
                $identity = isset($systemInfo[0]['name']) ? $systemInfo[0]['name'] : 'unknown';
                $berhasil .= " | Identity: $identity";
            }
            // CEK APAKAH USER ADA DI PPP SECRET
            $secrets = $API->comm("/ppp/secret/print", array("?name" => $idpel));
            $berhasil .= " | Checking user $idpel";
            if (empty($secrets)) {
                // User tidak ditemukan di PPP secret, lewati proses ini
                $berhasil .= " | User NOT FOUND in PPP secrets";
                $profile_status .= "User $idpel tidak ditemukan di PPP secret server $ip; ";
                $API->disconnect();
                continue; // Lanjut ke server berikutnya jika ada
            } else {
                $berhasil .= " | User FOUND";
                $currentProfile = isset($secrets[0]['profile']) ? $secrets[0]['profile'] : 'unknown';
                $berhasil .= " | Current profile: $currentProfile";
            }

            // CEK PROFIL YANG TERSEDIA TERLEBIH DAHULU
            $profiles = $API->comm("/ppp/profile/print");
            $expiredExists = false;
            $availableProfiles = [];
            foreach ($profiles as $profile) {
                if (isset($profile['name'])) {
                    $availableProfiles[] = $profile['name'];
                    if ($profile['name'] === 'EXPIRED') {
                        $expiredExists = true;
                    }
                }
            }

            $berhasil .= " | Profiles: " . implode(', ', $availableProfiles);
            $berhasil .= " | EXPIRED exists: " . ($expiredExists ? 'YES' : 'NO');

            if (!$expiredExists) {
                // Jika profil EXPIRED tidak ada, buat profil baru dengan parameter minimal
                $berhasil .= " | Creating EXPIRED profile...";
                $createProfile = $API->comm(
                    "/ppp/profile/add",
                    array(
                        "name" => "EXPIRED",
                        "comment" => "Profile untuk user yang expired"
                    )
                );
                if ($createProfile === false) {
                    $berhasil .= " FAILED";
                    $profile_status .= "Gagal buat profil EXPIRED di server $ip; ";
                } else {
                    $berhasil .= " SUCCESS";
                    $profile_status .= "Berhasil buat profil EXPIRED di server $ip; ";
                }
            }

            // UBAH PROFIL USER - Gunakan '.id' untuk identifikasi secret
            if (!isset($secrets[0]['.id'])) {
                $berhasil .= " | ERROR: Cannot find secret ID for user $idpel";
                $profile_status .= "Tidak dapat menemukan ID secret untuk user $idpel di server $ip; ";
                $API->disconnect();
                continue;
            }
            
            $berhasil .= " | Setting profile to EXPIRED";
            $result = $API->comm(
                "/ppp/secret/set",
                array(
                    ".id"     => $secrets[0]['.id'], // Menggunakan ID secret yang ditemukan
                    "profile" => "EXPIRED", // Mengubah profil ke EXPIRED
                    "comment"  => "EXPIRED $periode - $nama - $nowa - $tanggalbayar  ( BY ADMIN MANUAL )"
                )
            );

            // Debug: cek apakah command berhasil
            if ($result === false) {
                $berhasil .= " | FAILED";
                $profile_status .= "Gagal ubah profil user $idpel di server $ip; ";
            } else {
                $berhasil .= " | SUCCESS";
                $profile_status .= "Berhasil ubah profil user $idpel di server $ip; ";
                $success_servers++;
                
                // Verifikasi perubahan
                $verifySecrets = $API->comm("/ppp/secret/print", array("?name" => $idpel));
                if (!empty($verifySecrets)) {
                    $newProfile = isset($verifySecrets[0]['profile']) ? $verifySecrets[0]['profile'] : 'unknown';
                    $berhasil .= " | Verified: $newProfile";
                }
            }

            // CARI DAN HAPUS KONEKSI AKTIF
            $activeUsers = $API->comm("/ppp/active/print");
            $active_removed = 0;
            $total_active = 0;
            foreach ($activeUsers as $active) {
                if ($active['name'] == $idpel) {
                    $total_active++;
                    $berhasil .= " | Found active connection for $idpel";
                    $remove_result = $API->comm(
                        "/ppp/active/remove",
                        array(
                            ".id" => $active[".id"] // Menghapus koneksi aktif berdasarkan ID
                        )
                    );
                    if ($remove_result !== false) {
                        $active_removed++;
                        $berhasil .= " | Successfully removed active connection";
                    } else {
                        $berhasil .= " | Failed to remove active connection";
                    }
                }
            }
            $berhasil .= " | Active connections: $total_active found, $active_removed removed";
            
            $berhasil = "Server $ip: Profil diubah, $active_removed/$total_active koneksi aktif dihapus";
            $API->disconnect();
        } else {
            $berhasil = "Server $ip: Koneksi ke MikroTik gagal dengan user $user";
            http_response_code(200);
            echo json_encode(["message" => "Koneksi ke MikroTik server $ip gagal!"]);
            exit;
        }
    }

    // Ringkasan hasil operasi
    if ($total_servers > 0) {
        $berhasil = "Total server: $total_servers, Berhasil: $success_servers | $profile_status";
    } else {
        $berhasil = "Tidak ada server yang ditemukan untuk area $AREA";
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


    $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Berhasil disable pelanggan $idpel - Periode: $periode";
    // Simpan ke file history
    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));



    ////////////////////////////////////////////////////








 
    // Isolir di sisi RADIUS: pakai reconcile terpusat (radius_sync_lib.php),
    // path file dan sumber password SAMA dengan cron sync_freeradius_users.php
    // dan activecustomer.php. Group diganti "EXPIRED" tapi entrinya TETAP ADA
    // di RADIUS (tidak dihapus), supaya router masih bisa mengenali user ini
    // dan mengarahkannya ke profile isolir, bukan membuatnya "hilang" total.
    $sql_pw = "SELECT `PASSWORD` FROM `pelanggan` WHERE `IDPEL` = '" . mysqli_real_escape_string($conn, $idpel) . "' LIMIT 1";
    $result_pw = $conn->query($sql_pw);
    $radius_password = ($result_pw && $row_pw = $result_pw->fetch_assoc()) ? $row_pw['PASSWORD'] : '';

    if ($radius_password !== '') {
        $radius_result = radiusUpsertUsers([
            $idpel => [
                'password' => $radius_password,
                'reply' => ['Mikrotik-Group := "EXPIRED"'],
            ],
        ]);
        radiusReloadIfChanged($radius_result['changed']);
    }


function replaceVariables($text)
  {
      // Ganti semua variabel yang sudah didefinisikan di file ini (scope lokal)
      $vars = get_defined_vars();
      return preg_replace_callback('/\$(\w+)/', function ($matches) use ($vars) {
          $var = $matches[1];
          return isset($vars[$var]) ? $vars[$var] : $matches[0];
      }, $text);
  }


 // Ambil pesan ketentuan dari notif_khusus berdasarkan $project sebagai pemilik
                                $pesan_disable = '';
                               
                               
                                    $stmt = $conn->prepare("SELECT pesan_disable FROM notif_khusus WHERE pemilik = ? LIMIT 1");
                                    $stmt->bind_param('s',$ceknama);
                                    $stmt->execute();
                                    $stmt->bind_result($pesan_disable);
                                    $stmt->fetch();
                                    $stmt->close();
                                

                                // Fungsi untuk replace variabel di pesan ketentuan
                                function replace_vars($template, $vars) {
                                    return preg_replace_callback('/\\$([a-zA-Z0-9_]+)/', function($m) use ($vars) {
                                        $key = $m[1];
                                        return isset($vars[$key]) ? $vars[$key] : $m[0];
                                    }, $template);
                                }





    // Path ke file JSON reminder
    $jsonFile = "../notifbot/data/reminder-$ceknama.json";

    // Default
    $botname = "";
    if (file_exists($jsonFile)) {
        $jsonData = file_get_contents($jsonFile);
        $data = json_decode($jsonData, true);
        if (is_array($data)) {
            foreach ($data as $item) {
                $botname = $item['botname'];
                // hanya pakai botname (jika ada lebih dari 1 entri, gunakan yg terakhir)
            }
        }
    }
    // Ambil informasi bot
    $waapi = "";
    $botpass = "";
    $sender = "";
    $sql1 = "SELECT * FROM `botwa` WHERE `namebot` = '$botname'";
    $query1 = mysqli_query($conn, $sql1);
    if ($data1 = mysqli_fetch_array($query1)) {
        $waapi = $data1['addressbot'];
        $passwordbot = $data1['password'];
        $sender = $data1['sender'] ?? '';
    }



    $waapi = htmlspecialchars($waapi);
    $phone = "$nowa@s.whatsapp.net";
    
          // Ambil semua variabel terdefinisi di scope ini
          $vars = get_defined_vars();
          // Hapus variabel yang tidak perlu (opsional, agar tidak bocor variabel besar)
          unset($vars['isi'], $vars['match'], $vars['vars']);
    $message = replace_vars($pesan_disable, $vars);

    $data = [
        "phone" => $phone,
        "message" => $message
    ];

    $botname = htmlspecialchars($botname);
    $passwordbot = htmlspecialchars($passwordbot);

    // device_id gowa multi-device (build gowa terbaru wajib X-Device-Id
    // header / device_id query di /send/message) = isi kolom sender apa
    // adanya (nama device di server gowa, mis. "hanif").
    $deviceId = trim((string)$sender);

    $url = rtrim($waapi, '/') . '/send/message?session=' . urlencode($botname);
    if ($deviceId !== '') {
        $url .= '&device_id=' . urlencode($deviceId);
    }
    $headers = ["Content-Type: application/json"];
    if ($deviceId !== '') {
        $headers[] = "X-Device-Id: $deviceId";
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_USERPWD, "$botname:$passwordbot");
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);



    echo json_encode(["message" => "Success disable $idpel $nama - $nowa - $tanggalbayar - Periode: $periode | Status: $berhasil"]);
} else {
   
    echo json_encode(["message" => "Metode tidak diperbolehkan"]);
}
