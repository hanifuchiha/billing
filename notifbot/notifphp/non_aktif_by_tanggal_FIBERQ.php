<?php
include '../../koneksidb.php';
require('../../routeros_api.class.php');
require_once('../../radius_sync_lib.php');
include "../phpmailer/classes/class.phpmailer.php";


// Simpan konfigurasi
$config_file ='../../config.json'; // Lokasi file di folder yang sama
$config = file_exists($config_file) ? json_decode(file_get_contents($config_file), true) : [];

$domain=$config['domain'];
$URL=$config['domain'];


// getFreeradiusPID()/restartFreeradius() lokal yang dulu ada di sini sudah
// dihapus (tidak dipakai lagi) -- restart FreeRADIUS sekarang ditangani
// radiusReloadIfChanged() di radius_sync_lib.php, dipanggil lewat
// radiusSyncSingleCustomerNow() di bagian isolir RADIUS di bawah.








$hari_ini = date("Y-m-d");
#$hariinitgl=25;
$currentDate = date('Y-m-d');
// Fungsi delay aman (4–6 detik acak)
function sleepAman($min = 4, $max = 6)
{
  $delay = rand($min * 1000, $max * 1000);
  usleep($delay * 1000);
}
////////////////////////////////////////
$filename = basename(__FILE__); // contoh: hapus_kode_permintaan_bayar_FIBERQ.php
$nameOnly = pathinfo($filename, PATHINFO_FILENAME); // hapus_kode_permintaan_bayar_FIBERQ

$parts = explode('_', $nameOnly);
$pemilik = end($parts); // ambil bagian terakhir

echo "Bagian terakhir dari nama file: $pemilik <br>";
//////DATA USERNAME/////////////////////////////////////////////////////////////
$sql99 = "SELECT * FROM `user` WHERE `USERNAME`='$pemilik' ";
$query99 = mysqli_query($conn, $sql99);
while ($data99 = mysqli_fetch_array($query99)) {
  $iduser = $data99['id'];
  $saldo = $data99['saldo'];
  $username = $data99['USERNAME'];
  $password = $data99['PASWORD'];
  $nowa = $data99['NOWA'];
  $AKSES = $data99['STATUS'];
  $domain2 = $data99['domain'];
  // Hapus server_list dari sini
}
/////////////////////////////////////////////////////////////////////////////////
// Path ke file JSON
$jsonFile = "../data/reminder-$pemilik.json";

// Cek apakah file ada
if (file_exists($jsonFile)) {
  // Baca isi file JSON
  $jsonData = file_get_contents($jsonFile);

  // Decode JSON menjadi array asosiatif
  $data = json_decode($jsonData, true);

  // Periksa apakah decoding berhasil
  if ($data !== null) {
    foreach ($data as $item) {
    
      $botname = $item['botname'];
    }
  } else {
    echo "Error: Gagal mendecode JSON.";
  }
} else {
  echo "Error: File JSON tidak ditemukan.";
}
// Cek apakah sudah pernah dikirim
$history_file = "../data/history-$pemilik.json";
$history = [];

if (file_exists($history_file)) {
  $history = json_decode(file_get_contents($history_file), true);
}

// Pastikan format history adalah array
if (!is_array($history)) {
  $history = [];
}
/////////////////////////////////////////////////////////////////////////////////

// Include helper function untuk pemilihan bot (mendukung RANDOM)
require_once('../bot_selector_helper.php');

// Gunakan helper function untuk pemilihan bot (mendukung RANDOM)
$bot_result = selectBotForNotificationWithField($conn, $pemilik, $botname, 'penerima_system_notif');

if ($bot_result['success']) {
  $waapi = $bot_result['addressbot'];
  $botpass = $bot_result['password'];
  $botname = $bot_result['namebot'];
  $sender = $bot_result['sender'] ?? '';

  if ($bot_result['is_random']) {
    echo "[RANDOM BOT] Bot dipilih secara random: $botname untuk menghindari spam (Deactivate by Date)<br>";
  }
} else {
  echo "ERROR: " . $bot_result['message'] . "<br>";
  die();
}

// === SETUP MULTIBOT DISTRIBUTION FOR RANDOM BOT MODE ===
$isRandomBot = $bot_result['is_random'] ?? false;
$botPool = [];
$botPoolCount = 0;
$targetIndex = 0;

if ($isRandomBot) {
    // Load semua bot yang dimiliki oleh pemilik
    $botPoolSql = "SELECT namebot, addressbot, password FROM botwa WHERE pemilik = ? ORDER BY id ASC";
    $botPoolStmt = $conn->prepare($botPoolSql);
    $botPoolStmt->bind_param("s", $pemilik);
    $botPoolStmt->execute();
    $botPoolResult = $botPoolStmt->get_result();
    
    while ($botData = $botPoolResult->fetch_assoc()) {
        if (!empty($botData['namebot']) && !empty($botData['addressbot']) && !empty($botData['password'])) {
            $botPool[] = [
                'namebot' => $botData['namebot'],
                'addressbot' => $botData['addressbot'],
                'password' => $botData['password']
            ];
        }
    }
    
    $botPoolCount = count($botPool);
    
    if ($botPoolCount > 0) {
        shuffle($botPool);
        echo "[MULTIBOT POOL] Initialized dengan " . $botPoolCount . " bot tersedia untuk distribusi acak.<br>";
    } else {
        echo "[WARNING] Tidak ada bot ditemukan untuk multibot distribution. Falling back ke single bot mode.<br>";
        $isRandomBot = false;
    }
}
/////////////////////////////////////////////////////////////////////////////////





require_once __DIR__ . '/../notif_template_helper.php';
$filePath = notifTemplateFilePath($pemilik);

  // Delegasi ke notif_template_helper.php (satu acuan tunggal utk semua cron
  // notif) -- fungsi ini dipertahankan namanya (dipanggil di banyak titik di
  // file ini) tapi implementasinya sekarang cukup 1 baris, bukan salinan
  // regex sendiri lagi.
  function getTemplateSection($content, $section)
  {
    return trim(notifTemplateExtractSection((string)$content, (string)$section));
  }

  // Fungsi pengganti variabel
  function replaceVariables($text, array $contextVars = [])
  {
    $contextLowerMap = [];
    foreach ($contextVars as $ctxKey => $ctxValue) {
      $contextLowerMap[strtolower((string)$ctxKey)] = $ctxValue;
    }

    $globalsLowerMap = [];
    foreach ($GLOBALS as $globalKey => $globalValue) {
      $globalsLowerMap[strtolower((string)$globalKey)] = $globalValue;
    }

    return preg_replace_callback('/\$\{?([A-Za-z_][A-Za-z0-9_]*)\}?/', function ($matches) use ($contextVars, $contextLowerMap, $globalsLowerMap) {
      $key = $matches[1];

      if (array_key_exists($key, $contextVars)) {
        return (string)$contextVars[$key];
      }

      $ctxUpper = strtoupper($key);
      if (array_key_exists($ctxUpper, $contextVars)) {
        return (string)$contextVars[$ctxUpper];
      }

      $ctxLower = strtolower($key);
      if (array_key_exists($ctxLower, $contextVars)) {
        return (string)$contextVars[$ctxLower];
      }

      if (array_key_exists($ctxLower, $contextLowerMap)) {
        return (string)$contextLowerMap[$ctxLower];
      }

      if (array_key_exists($key, $GLOBALS)) {
        return (string)$GLOBALS[$key];
      }

      $upper = strtoupper($key);
      if (array_key_exists($upper, $GLOBALS)) {
        return (string)$GLOBALS[$upper];
      }

      $lower = strtolower($key);
      if (array_key_exists($lower, $GLOBALS)) {
        return (string)$GLOBALS[$lower];
      }

      if (array_key_exists($lower, $globalsLowerMap)) {
        return (string)$globalsLowerMap[$lower];
      }

      if ($lower === 'url' && isset($GLOBALS['URL'])) {
        return (string)$GLOBALS['URL'];
      }

      return $matches[0];
    }, $text);
  }

  // Fungsi cek apakah sudah pernah dikirim notifikasi
  function hasBeenNotified($history, $type, $identifier, $date = null)
  {
    if ($date === null) {
      $date = date('Y-m-d');
    }
    foreach ($history as $entry) {
      if (strpos($entry, $type) !== false && strpos($entry, $identifier) !== false && strpos($entry, $date) !== false) {
        return true;
      }
    }
    return false;
  }


















$sql = "SELECT * FROM `server` WHERE `user_id` = '$iduser'";
  $query = mysqli_query($conn, $sql);
  while ($data = mysqli_fetch_array($query)) {
    echo "<hr>";
    echo "<hr>";
    echo "<hr>";

    echo  $AREA = $data['AREA'];
    echo "<br>";
    echo  $PEMILIK = $data['PEMILIK'];
    echo "<br>";
    echo  $IP = $data['IP'];
    echo "<br>";
    echo  $PASSWORD = $data['PASSWORD'];
    echo "<br>";
    echo $BOTWA = $data['BOTWA'];
    echo "<br>";
    echo $OLT = $data['OLT'];
    echo "<br>";
    echo $MIK80 = $data['MIK80'];
     echo "<br>";
    echo $BRAND = $data['BRAND'];
    echo "<br>";
    echo "<br>";




$sql1 = "SELECT * from `pelanggan` WHERE `PEMILIK` = '$PEMILIK' and `AREA`='$AREA'";
$query1 = mysqli_query($conn, $sql1);

// Fetch semua pelanggan ke array untuk bisa shuffle jika multibot random
$pelangganList = [];
while ($tempData = mysqli_fetch_array($query1)) {
    $pelangganList[] = $tempData;
}

// Shuffle pelanggan jika multibot random mode
if ($isRandomBot && $botPoolCount > 0) {
    shuffle($pelangganList);
}

// Loop melalui pelanggan (sudah ter-shuffle jika random mode)
foreach ($pelangganList as $data1) {
$TIPE_TEMPO = $data1['TIPE_TEMPO']; // mengikuti_tanggal_bayar atau mengikuti_tanggal_tempo

// Pelanggan mode Rolling (mengikuti_tanggal_bayar) sudah ditangani sepenuhnya
// (termasuk simulasi seluruh histori bayar -- bayar cepat TIDAK memajukan
// jadwal) oleh cek_tagihan_harian.php/tagihan_status_lib.php, jadi dilewati
// di sini supaya tidak diisolir dua kali dengan hasil yang bisa berbeda
// (script ini cuma pakai "tanggal bayar terakhir + 30 hari" polos, tanpa
// aturan bayar-cepat-tidak-memajukan-jadwal).
if ($TIPE_TEMPO == "mengikuti_tanggal_bayar") {
    echo "Lewati {$data1['IDPEL']}: mode tempo mengikuti_tanggal_bayar ditangani oleh cek_tagihan_harian.php<br>";
    continue;
}

// Blok di bawah ini sekarang TIDAK PERNAH jalan lagi (sudah di-continue di atas
// utk satu-satunya kondisi yang dicek: mengikuti_tanggal_bayar) -- sengaja
// dipertahankan sbg if(false) alih-alih dihapus fisik krn ukurannya besar
// (~1200 baris) dan berisiko salah potong brace penutup; aman dibiarkan mati.
if(false){

      echo "<hr>";
      echo  $IDPEL = $data1['IDPEL'];
      echo "<br>";
      echo  $NAMA = $data1['NAMA'];
      echo "<br>";
      echo  $NOWA = $data1['NOWA'];
      echo "<br>";
      echo $EMAIL = $data1['EMAIL'];
      echo "<br>";
      echo $PAKET = $data1['PAKET'];
          echo $AUTHMODE = $data1['MODE']; //SEBAGAI AUTH MODE

// Daftar mode yang valid
$valid_modes = ['RADIUS MODE', 'API MODE', 'MULTI MODE'];

// Cek apakah input valid, jika tidak default ke 'API MODE'
if (!in_array($AUTHMODE, $valid_modes)) {
    $AUTHMODE = 'API MODE';
}
      echo "<br>";
      echo $TANGGALPASANG = $data1['TANGGALPASANG'];
      echo "<br>";
      echo $ALAMAT = $data1['ALAMAT'];
      echo "<br>";
      echo $TIPE_BAYAR = $data1['TIPE_BAYAR']; // prabayar atau pascabayar
      echo "<br>";
      echo $TIPE_TEMPO = $data1['TIPE_TEMPO']; // mengikuti_tanggal_bayar atau mengikuti_tanggal_tempo
      echo "<br>";
      $cektempo = $data1['TEMPO']; // mengikuti_tanggal_bayar atau mengikuti_tanggal_tempo
      echo "<br>";


              /////JIKA PELANGGAN VPNQ
              if (stripos($IDPEL, 'VPNQ-') === true) {
                 if (stripos($PAKET, 'FREE') === false) {
                                                  ///CARI USER YANG BELI
                                                  $sql111 = "SELECT * from `user` WHERE `NOWA` = '$NOWA' ";
                                                  $query111 = mysqli_query($conn, $sql111);
                                                  while ($data111 = mysqli_fetch_array($query111)) {
                                                  
                                                             $namacek=$data111['USERNAME'];
                                                             $emailcek=$data111['domain'];
                                                             $saldocek=$data111['saldo'];
                                                             ///CARI HARGA PAKET
                                                              $sql112 = "SELECT * from `paket` WHERE `PAKET` = '$PAKET' ";
                                                              $query112 = mysqli_query($conn, $sql112);
                                                              while ($data112 = mysqli_fetch_array($query112)) {
                                                                    $paketcek=$data112['PAKET'];
                                                                    $pakethargacek=$data112['HARGA'];
                                                              }

                                                              if($cektempo=='AUTO')
                                                              {
                                                                    
                                                                     if ($saldocek >= $pakethargacek) {
                                                                              $saldocek=$saldocek-$pakethargacek;
                                                                              $sql112 = "UPDATE `user` SET `saldo` = '$saldocek' WHERE `USERNAME` = '$namacek'";
                                                                              $query112 = mysqli_query($conn, $sql112);
                                                                              while ($data112 = mysqli_fetch_array($query112)) {
                                                                                   //UPDATE SALDO
                                                                              }
                                                                     }
                                                               }
                                                               else
                                                               {



                                                                                            //////////////////////JIKA BUKAN FASUM/////////////////////////////////
                                                                                            if (stripos($PAKET, 'FREE') === false) {

                                                                                            //////////////////////BUKA BUKU TRANSAKSI CEK SUDAH BAYAR ATAU BELUM/////////////////////////////////
                                                                                                $sql2 = "SELECT * 
                                                                                                        FROM `transaksi` 
                                                                                                        WHERE `STATUS` = 'BERHASIL' 
                                                                                                        AND `IDPEL` = '$IDPEL' 
                                                                                                        ORDER BY `waktu` DESC 
                                                                                                        LIMIT 1";

                                                                                                                    $query2 = mysqli_query($conn, $sql2);
                                                                                                                    $cek = mysqli_num_rows($query2);
                                                                                                                   if ($cek > 0) {

                                                                                                                  
                                                                                                                    $data = mysqli_fetch_assoc($query2); 
                                                                                                                    $terkhirbayar = $data['waktu'];      

                                                                                                                    echo "Terakhir bayar: " . $terkhirbayar . "<br>";

                                                                                                                    // hitung jatuh tempo = 30 hari dari terakhir bayar
                                                                                                                    $jatuh_tempo  = date("Y-m-d", strtotime("+30 days", strtotime($terkhirbayar)));

                                                                                                                    // hari ini
                                                                                                                    $hari_ini = date("Y-m-d");

                                                                                                                    if (strtotime($hari_ini) > strtotime($jatuh_tempo )) {
                                                                                                                        echo "Sudah melewati tempo (" . $jatuh_tempo  . ")";
                                                                                                                        echo "<BR>MATIKAN / ISOLIR";

                                                                              // Ganti variabel di dalam teks
                                                                                                                                        $expired_parsed = "📌 INFO AKUN VPN ANDA\n"
                                                                                                                                                        . "🔗 URL Remot:  $ALAMAT\n"
                                                                                                                                                        . "🌐 IP VPN: $TIKOR\n"
                                                                                                                                                        . "👤 User: $IDPEL\n"
                                                                                                                                                        . "🔑 Pass: $IDPEL\n"
                                                                                                                                                        . "📦 Paket: $PAKET\n\n"
                                                                                                                                                        . "⚠️ Sudah melewati tempo ( $jatuh_tempo )\n\n"
                                                                                                                                                        . "Mohon segera melakukan perpanjangan agar layanan tetap bisa digunakan . 🙏";


                                                                                                                                      $session = $botname; // Nama sesi yang telah Anda buat
                                                                                                                                      // Nomor tujuan dan pesan
                                                                                                                                      $phone = "$NOWA@s.whatsapp.net"; // Format: nomor@s.whatsapp.net


                                                                                                                                      // Data JSON sesuai dengan dokumentasi API
                                                                                                                                      $data = [
                                                                                                                                        "phone" => $phone,
                                                                                                                                        "message" => $expired_parsed
                                                                                                                                        // "reply_message_id" => "optional" // Opsional: ID pesan yang ingin dibalas
                                                                                                                                      ];

                                                                                                                                      // Inisialisasi cURL
                                                                                                                                      // === SELECT BOT FROM POOL FOR MULTI-BOT DISTRIBUTION ===
                                                                                                                                      $currentBotName = $botname;
                                                                                                                                      $currentBotPass = $botpass;
                                                                                                                                      $currentWAAPI = $waapi;
                                                                                                                                      
                                                                                                                                      if ($isRandomBot && $botPoolCount > 0) {
                                                                                                                                          $currentBotConfig = $botPool[$targetIndex % $botPoolCount];
                                                                                                                                          $currentBotName = $currentBotConfig['namebot'];
                                                                                                                                          $currentBotPass = $currentBotConfig['password'];
                                                                                                                                          $currentWAAPI = $currentBotConfig['addressbot'];
                                                                                                                                      }
                                                                                                                                      
                                                                                                                                      $deviceId = trim((string)$sender);
                                                                                                                                      $url = "$currentWAAPI/send/message?session=$currentBotName"; // Endpoint dengan parameter sesi
                                                                                                                                      if ($deviceId !== '') {
                                                                                                                                        $url .= '&device_id=' . urlencode($deviceId);
                                                                                                                                      }
                                                                                                                                      $headers = [
                                                                                                                                        "Content-Type: application/json"
                                                                                                                                      ];
                                                                                                                                      if ($deviceId !== '') {
                                                                                                                                        $headers[] = "X-Device-Id: $deviceId";
                                                                                                                                      }
                                                                                                                                      $ch = curl_init();
                                                                                                                                      curl_setopt($ch, CURLOPT_URL, $url);
                                                                                                                                      curl_setopt($ch, CURLOPT_POST, true);
                                                                                                                                      curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                                                                                                                                      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                                                                                                                      curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                                                                                                                                      // Tambahkan Basic Auth
                                                                                                                                      curl_setopt($ch, CURLOPT_USERPWD, "$currentBotName:$currentBotPass");

                                                                                                                                      // Eksekusi dan tangani respons
                                                                                                                                      $response = curl_exec($ch);
                                                                                                                                      $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                                                                                                                                      curl_close($ch);
                                                                                                                                      sleepAman(); // jeda aman antar pesan, sebelumnya tidak pernah dipanggil di file ini
                                                                                                                                      
                                                                                                                                      // Increment target counter untuk distribusi ke bot berikutnya
                                                                                                                                      if ($isRandomBot && $botPoolCount > 0) {
                                                                                                                                          $targetIndex++;
                                                                                                                                      }






if (!hasBeenNotified($history, 'Sent WhatsApp message to', $NOWA)) {
$history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Sent WhatsApp message to $NOWA with message $expired_parsed";
// Simpan ke file history
file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
}





if($AUTHMODE=='API MODE' | $AUTHMODE=='MULTI MODE'  )
{

                                                                                                                              // ////////koneksi ke mikrotik ///////
                                                                                                                              $API = new RouterosAPI();
                                                                                                                              $API->connect($IP, $PEMILIK, $PASSWORD);
                                                                                                                              $cariurutan = $API->comm(
                                                                                                                                "/ppp/secret/getall",
                                                                                                                                array(
                                                                                                                                  ".proplist" => ".id",
                                                                                                                                  "?name" => $IDPEL,
                                                                                                                                )
                                                                                                                              );
                                                                                                                              $API->comm(
                                                                                                                                "/ppp/secret/set",
                                                                                                                                array(
                                                                                                                                  ".id" => $cariurutan[0][".id"],
                                                                                                                                  "comment"  => "EXPIRED $NAMA - $NOWA - $ptanggalskg",
                                                                                                                                  "profile"  => "EXPIRED",
                                                                                                                                )
                                                                                                                              );
                                                                                                                              $API2 = new RouterosAPI();
                                                                                                                              $API2->connect($IP, $PEMILIK, $PASSWORD);
                                                                                                                              $cariurutan2 = $API2->comm(
                                                                                                                                "/ppp/active/getall",
                                                                                                                                array(
                                                                                                                                  ".proplist" => ".id",
                                                                                                                                  "?name" => $IDPEL,
                                                                                                                                )
                                                                                                                              );
                                                                                                                              $API2->comm(
                                                                                                                                "/ppp/active/remove",
                                                                                                                                array(
                                                                                                                                  ".id" => $cariurutan2[0][".id"],
                                                                                                                                )
                                                                                                                              );
                                                                                                                              $blmbyr++;

if (!hasBeenNotified($history, 'Isolated internet for', $IDPEL)) {
                                                                                                                              $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Isolated internet for $IDPEL via Mikrotik mode";
                                                                                                                              // Simpan ke file history
                                                                                                                              file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
}
}




if($AUTHMODE=='RADIUS MODE' | $AUTHMODE=='MULTI MODE'  )
{
    $idpel = $IDPEL;
    // PENTING: pakai $data1['PASSWORD'] (password PPPoE pelanggan), BUKAN
    // variabel $PASSWORD -- di scope ini $PASSWORD sudah ke-shadow oleh
    // password login API router (dipakai $API->connect() di atas),
    // bukan password pelanggan.
    $pppoe_password = $data1['PASSWORD'] ?? '';

    if ($pppoe_password === '') {
        echo "⚠️ Lewati isolir RADIUS untuk '$idpel': password PPPoE kosong di database.";
    } else {
        $paket_q = mysqli_query($conn, "SELECT * FROM paket WHERE PAKET='" . mysqli_real_escape_string($conn, $PAKET) . "' AND PEMILIK='" . mysqli_real_escape_string($conn, $PEMILIK) . "' ORDER BY id DESC LIMIT 1");
        $paketRow = ($paket_q && mysqli_num_rows($paket_q) > 0) ? mysqli_fetch_assoc($paket_q) : ['PAKET' => $PAKET, 'KECEPATAN' => ''];

        // Isolir = soft-block (bukan hapus total seperti sebelumnya): entry
        // RADIUS TETAP ADA tapi grup/address-list diganti EXPIRED, konsisten
        // dengan cron sync_freeradius_users.php dan dengan cabang API MODE di
        // atas. Ini juga menjaga jaring pengaman fallback MULTI MODE.
        radiusSyncSingleCustomerNow($idpel, $pppoe_password, $paketRow, false, radiusGetGlobalSettings($conn));

        echo "✅ User '$idpel' diisolir di FreeRADIUS (grup/address-list EXPIRED, entry tetap ada).";
    }

    $timer_file = "/etc/freeradius/user_timers/{$idpel}.json";
    if (file_exists($timer_file)) unlink($timer_file);

if (!hasBeenNotified($history, 'Isolated internet for', $IDPEL)) {
                                                                                                                              $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Isolated internet for $IDPEL via Radius mode";
                                                                                                                              // Simpan ke file history
                                                                                                                              file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
}
}

                                                                                                                    } elseif (strtotime($hari_ini) == strtotime($jatuh_tempo )) {
                                                                                                                        echo "Hari ini adalah jatuh tempo (" . $jatuh_tempo  . ")";
                                                                                                                        echo "<BR>NOTIF REMAINDER";


                                          // Ganti variabel di dalam teks
                                                                                                                                        $remainder_parsed = "📌 INFO AKUN VPN ANDA\n"
                                                                                                                                                        . "🔗 URL Remot:  $ALAMAT\n"
                                                                                                                                                        . "🌐 IP VPN: $TIKOR\n"
                                                                                                                                                        . "👤 User: $IDPEL\n"
                                                                                                                                                        . "🔑 Pass: $IDPEL\n"
                                                                                                                                                        . "📦 Paket: $PAKET\n\n"
                                                                                                                                                        . "⚠️ Hari ini adalah jatuh tempo  ( $jatuh_tempo )\n\n"
                                                                                                                                                        . "Mohon segera melakukan perpanjangan agar layanan tetap bisa digunakan tanpa terputus . 🙏";
                                                                                                                                    


                                                                                                                            $session = $botname; // Nama sesi yang telah Anda buat
                                                                                                                            // Nomor tujuan dan pesan
                                                                                                                            $phone = "$NOWA@s.whatsapp.net"; // Format: nomor@s.whatsapp.net
                                                                                                                            // Data JSON sesuai dengan dokumentasi API
                                                                                                                            $data = [
                                                                                                                                "phone" => $phone,
                                                                                                                                "message" => $remainder_parsed
                                                                                                                                // "reply_message_id" => "optional" // Opsional: ID pesan yang ingin dibalas
                                                                                                                            ];
                                                                                                                            // Inisialisasi cURL
                                                                                                                            $deviceId = trim((string)$sender);
                                                                                                                            $url = "$waapi/send/message?session=$session"; // Endpoint dengan parameter sesi
                                                                                                                            if ($deviceId !== '') {
                                                                                                                                $url .= '&device_id=' . urlencode($deviceId);
                                                                                                                            }
                                                                                                                            $headers = [
                                                                                                                                "Content-Type: application/json"
                                                                                                                            ];
                                                                                                                            if ($deviceId !== '') {
                                                                                                                                $headers[] = "X-Device-Id: $deviceId";
                                                                                                                            }
                                                                                                                            $ch = curl_init();
                                                                                                                            curl_setopt($ch, CURLOPT_URL, $url);
                                                                                                                            curl_setopt($ch, CURLOPT_POST, true);
                                                                                                                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                                                                                                                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                                                                                                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                                                                                                                            // Tambahkan Basic Auth
                                                                                                                            curl_setopt($ch, CURLOPT_USERPWD, "$botname:$botpass");
                                                                                                                            // Eksekusi dan tangani respons
                                                                                                                            $response = curl_exec($ch);
                                                                                                                            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                                                                                                                            curl_close($ch);
                                                                                                                            sleepAman(); // jeda aman antar pesan, sebelumnya tidak pernah dipanggil di file ini
                                                                                                                            $response_data = json_decode($response, true); // Konversi ke array

if (!hasBeenNotified($history, 'Sent remainder message to', $NOWA)) {
                                                                                                                            $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Sent remainder message to $NOWA";
                                                                                                                            // Simpan ke file history
                                                                                                                            file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
}







                                                                                                                    } else {
                                                                                                                        // hitung sisa hari
                                                                                                                        $date1 = new DateTime($hari_ini);
                                                                                                                        $date2 = new DateTime($jatuh_tempo );
                                                                                                                        $selisih = $date1->diff($date2)->days;

                                                                                                                        echo "Belum jatuh tempo, masih berlaku sampai " . $jatuh_tempo ;
                                                                                                                        echo " (sisa $selisih hari lagi)";

                                                                                                                                    // logika khusus jika sisa 2 hari
                                                                                                                                    if ($selisih == 2) {
                                                                                                                                        echo "<BR>NOTIF REMAINDER";


                                                                                                                                            $remainder_parsed = "📌 INFO AKUN VPN ANDA\n"
                                                                                                                                                        . "🔗 URL Remot:  $ALAMAT\n"
                                                                                                                                                        . "🌐 IP VPN: $TIKOR\n"
                                                                                                                                                        . "👤 User: $IDPEL\n"
                                                                                                                                                        . "🔑 Pass: $IDPEL\n"
                                                                                                                                                        . "📦 Paket: $PAKET\n\n"
                                                                                                                                                        . "⚠️ Hari ini adalah jatuh tempo  ( $jatuh_tempo )\n\n"
                                                                                                                                                        . "Mohon segera melakukan perpanjangan agar layanan tetap bisa digunakan tanpa terputus . 🙏";
                                                                                                                                      




                                                                                                                                            $session = $botname; // Nama sesi yang telah Anda buat
                                                                                                                                            // Nomor tujuan dan pesan
                                                                                                                                            $phone = "$NOWA@s.whatsapp.net"; // Format: nomor@s.whatsapp.net
                                                                                                                                            // Data JSON sesuai dengan dokumentasi API
                                                                                                                                            $data = [
                                                                                                                                                "phone" => $phone,
                                                                                                                                                "message" => $remainder_parsed
                                                                                                                                                // "reply_message_id" => "optional" // Opsional: ID pesan yang ingin dibalas
                                                                                                                                            ];
                                                                                                                                            // Inisialisasi cURL
                                                                                                                                            $deviceId = trim((string)$sender);
                                                                                                                                            $url = "$waapi/send/message?session=$session"; // Endpoint dengan parameter sesi
                                                                                                                                            if ($deviceId !== '') {
                                                                                                                                                $url .= '&device_id=' . urlencode($deviceId);
                                                                                                                                            }
                                                                                                                                            $headers = [
                                                                                                                                                "Content-Type: application/json"
                                                                                                                                            ];
                                                                                                                                            if ($deviceId !== '') {
                                                                                                                                                $headers[] = "X-Device-Id: $deviceId";
                                                                                                                                            }
                                                                                                                                            $ch = curl_init();
                                                                                                                                            curl_setopt($ch, CURLOPT_URL, $url);
                                                                                                                                            curl_setopt($ch, CURLOPT_POST, true);
                                                                                                                                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                                                                                                                                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                                                                                                                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                                                                                                                                            // Tambahkan Basic Auth
                                                                                                                                            curl_setopt($ch, CURLOPT_USERPWD, "$botname:$botpass");
                                                                                                                                            // Eksekusi dan tangani respons
                                                                                                                                            $response = curl_exec($ch);
                                                                                                                                            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                                                                                                                                            curl_close($ch);
                                                                                                                                            sleepAman(); // jeda aman antar pesan, sebelumnya tidak pernah dipanggil di file ini
                                                                                                                                            $response_data = json_decode($response, true); // Konversi ke array



                                                                                                                                            $history[] = "Bot mengirim pesan WHATSAPP ke $NOWA dengan pesan $remainder_parsed" . date('Y-m-d H:i:s');
                                                                                                                                            // Simpan ke file history
                                                                                                                                            file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));



                                                                                                                                    }

                                                                                                                    }
                                                                                                                  

                                                                                                                  


                                                                                                } else {
                                                                                                    
                                                                 



                                                                                                    if ($TIPE_BAYAR == "pascabayar") {
                                                                                                        // logika untuk pasca bayar
                                                                                                        echo "Perhatian: pelanggan pasca bayar, perlu monitoring pembayaran pertama.";
                                                                                                        $jatuh_tempo  = date("Y-m-d", strtotime("+30 days", strtotime($TANGGALPASANG)));
                                                                                                        echo "<br>Jatuh tempo pertama: " . $jatuh_tempo ;




                                                                                                          if($jatuh_tempo <= $hari_ini)
                                                                                                          {
                                                                                                                echo "MATIKAN / ISOLIR";


                                                                                                                                              $expired_parsed = "📌 INFO AKUN VPN ANDA\n"
                                                                                                                                                        . "🔗 URL Remot:  $ALAMAT\n"
                                                                                                                                                        . "🌐 IP VPN: $TIKOR\n"
                                                                                                                                                        . "👤 User: $IDPEL\n"
                                                                                                                                                        . "🔑 Pass: $IDPEL\n"
                                                                                                                                                        . "📦 Paket: $PAKET\n\n"
                                                                                                                                                        . "⚠️  jatuh tempo  ( $jatuh_tempo )\n\n"
                                                                                                                                                        . "Mohon segera melakukan perpanjangan agar layanan tetap bisa digunakan tanpa terputus . 🙏";
                                                                                                                                      



                                                                                                                            $session = $botname; // Nama sesi yang telah Anda buat
                                                                                                                            // Nomor tujuan dan pesan
                                                                                                                            $phone = "$NOWA@s.whatsapp.net"; // Format: nomor@s.whatsapp.net


                                                                                                                            // Data JSON sesuai dengan dokumentasi API
                                                                                                                            $data = [
                                                                                                                              "phone" => $phone,
                                                                                                                              "message" => $expired_parsed
                                                                                                                              // "reply_message_id" => "optional" // Opsional: ID pesan yang ingin dibalas
                                                                                                                            ];

                                                                                                                            // Inisialisasi cURL
                                                                                                                            $deviceId = trim((string)$sender);
                                                                                                                            $url = "$waapi/send/message?session=$session"; // Endpoint dengan parameter sesi
                                                                                                                            if ($deviceId !== '') {
                                                                                                                              $url .= '&device_id=' . urlencode($deviceId);
                                                                                                                            }
                                                                                                                            $headers = [
                                                                                                                              "Content-Type: application/json"
                                                                                                                            ];
                                                                                                                            if ($deviceId !== '') {
                                                                                                                              $headers[] = "X-Device-Id: $deviceId";
                                                                                                                            }
                                                                                                                            $ch = curl_init();
                                                                                                                            curl_setopt($ch, CURLOPT_URL, $url);
                                                                                                                            curl_setopt($ch, CURLOPT_POST, true);
                                                                                                                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                                                                                                                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                                                                                                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                                                                                                                            // Tambahkan Basic Auth
                                                                                                                            curl_setopt($ch, CURLOPT_USERPWD, "$botname:$botpass");

                                                                                                                            // Eksekusi dan tangani respons
                                                                                                                            $response = curl_exec($ch);
                                                                                                                            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                                                                                                                            curl_close($ch);
                                                                                                                            sleepAman(); // jeda aman antar pesan, sebelumnya tidak pernah dipanggil di file ini







if($AUTHMODE=='API MODE' | $AUTHMODE=='MULTI MODE'  )
{

                                                                                                  // ////////koneksi ke mikrotik ///////
                                                                                                  $API = new RouterosAPI();
                                                                                                  $API->connect($IP, $PEMILIK, $PASSWORD);
                                                                                                  $cariurutan = $API->comm(
                                                                                                    "/ppp/secret/getall",
                                                                                                    array(
                                                                                                      ".proplist" => ".id",
                                                                                                      "?name" => $IDPEL,
                                                                                                    )
                                                                                                  );
                                                                                                  $API->comm(
                                                                                                    "/ppp/secret/set",
                                                                                                    array(
                                                                                                      ".id" => $cariurutan[0][".id"],
                                                                                                      "comment"  => "EXPIRED $NAMA - $NOWA - $ptanggalskg",
                                                                                                      "profile"  => "EXPIRED",
                                                                                                    )
                                                                                                  );
                                                                                                  $API2 = new RouterosAPI();
                                                                                                  $API2->connect($IP, $PEMILIK, $PASSWORD);
                                                                                                  $cariurutan2 = $API2->comm(
                                                                                                    "/ppp/active/getall",
                                                                                                    array(
                                                                                                      ".proplist" => ".id",
                                                                                                      "?name" => $IDPEL,
                                                                                                    )
                                                                                                  );
                                                                                                  $API2->comm(
                                                                                                    "/ppp/active/remove",
                                                                                                    array(
                                                                                                      ".id" => $cariurutan2[0][".id"],
                                                                                                    )
                                                                                                  );
                                                                                                  $blmbyr++;


                                                                                                  $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Bot melakukan isolir INTERNET $IDPEL mode mikrotik di jam " . date('Y-m-d H:i:s');
                                                                                                  // Simpan ke file history
                                                                                                  file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

}


if($AUTHMODE=='RADIUS MODE' | $AUTHMODE=='MULTI MODE'  )
{
    $idpel = $IDPEL;
    // PENTING: pakai $data1['PASSWORD'] (password PPPoE pelanggan), BUKAN
    // variabel $PASSWORD -- di scope ini $PASSWORD sudah ke-shadow oleh
    // password login API router, bukan password pelanggan.
    $pppoe_password = $data1['PASSWORD'] ?? '';

    if ($pppoe_password === '') {
        echo "⚠️ Lewati isolir RADIUS untuk '$idpel': password PPPoE kosong di database.";
    } else {
        $paket_q = mysqli_query($conn, "SELECT * FROM paket WHERE PAKET='" . mysqli_real_escape_string($conn, $PAKET) . "' AND PEMILIK='" . mysqli_real_escape_string($conn, $PEMILIK) . "' ORDER BY id DESC LIMIT 1");
        $paketRow = ($paket_q && mysqli_num_rows($paket_q) > 0) ? mysqli_fetch_assoc($paket_q) : ['PAKET' => $PAKET, 'KECEPATAN' => ''];

        // Isolir = soft-block (bukan hapus total seperti sebelumnya).
        radiusSyncSingleCustomerNow($idpel, $pppoe_password, $paketRow, false, radiusGetGlobalSettings($conn));

        echo "✅ User '$idpel' diisolir di FreeRADIUS (grup/address-list EXPIRED, entry tetap ada).";
    }

    $timer_file = "/etc/freeradius/user_timers/{$idpel}.json";
    if (file_exists($timer_file)) unlink($timer_file);

                                                                                                  $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Bot melakukan isolir INTERNET $IDPEL mode radius di jam " . date('Y-m-d H:i:s');
                                                                                                  // Simpan ke file history
                                                                                                  file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
}

                                                                                                }
                                                                                                    }
                                                                                                }
                                                                                    }



                                                               }




                                                  }

                      }                        

                }
                else
                {
                    


                   //////////////////////JIKA BUKAN FASUM/////////////////////////////////
                  if (stripos($PAKET, 'FREE') === false) {

                                                  //////////////////////BUKA BUKU TRANSAKSI CEK SUDAH BAYAR ATAU BELUM/////////////////////////////////
                                                      $sql2 = "SELECT * 
                                                              FROM `transaksi` 
                                                              WHERE `STATUS` = 'BERHASIL' 
                                                              AND `IDPEL` = '$IDPEL' 
                                                              ORDER BY `waktu` DESC 
                                                              LIMIT 1";
                                                                    
// hitung jatuh tempo = 30 hari dari terakhir bayar
                                                                          

                                                                          $query2 = mysqli_query($conn, $sql2);
                                                                          $cek = mysqli_num_rows($query2);
                                                                          $url_cari = "https://$domain/crm/billing/broadband/portal_baru.php?cari=" . urlencode($IDPEL);
                                                                          if ($cek > 0) {

                                                                        
                                                                          $data = mysqli_fetch_assoc($query2); 
                                                                          $terkhirbayar = $data['waktu'];      

                                                                          echo "Terakhir bayar: " . $terkhirbayar . "<br>";

                                                                          // hitung jatuh tempo = 30 hari dari terakhir bayar
                                                                          $jatuh_tempo  = date("Y-m-d", strtotime("+30 days", strtotime($terkhirbayar)));
                                                                          

                                                                          // hari ini
                                                                          $hari_ini = date("Y-m-d");

                                                                 

                                                                          if (strtotime($hari_ini) == strtotime($jatuh_tempo )) {
                                                                              echo "Hari ini adalah jatuh tempo (" . $jatuh_tempo  . ")";
                                                                              echo "<BR>NOTIF REMAINDER";



                                                                                  {
                                                                                      // Baca file (auto-dibuat dgn template default kalau belum ada
                                                                                      // -- lihat notif_template_helper.php) dan ambil bagian REMAINDER.
                                                                                      $isi = notifTemplateGetContent($pemilik);
                                                                                      $remainder_by_tanggal_raw = getTemplateSection($isi, 'REMAINDER');
                                                                                      $remainder_by_tanggal_parsed = replaceVariables($remainder_by_tanggal_raw, get_defined_vars());
                                                                                  }




                                                                                  $session = $botname; // Nama sesi yang telah Anda buat
                                                                                  // Nomor tujuan dan pesan
                                                                                  $phone = "$NOWA@s.whatsapp.net"; // Format: nomor@s.whatsapp.net
                                                                                  // Data JSON sesuai dengan dokumentasi API
                                                                                  $data = [
                                                                                      "phone" => $phone,
                                                                                      "message" => $remainder_by_tanggal_parsed
                                                                                      // "reply_message_id" => "optional" // Opsional: ID pesan yang ingin dibalas
                                                                                  ];
                                                                                  // Inisialisasi cURL
                                                                                  $deviceId = trim((string)$sender);
                                                                                  $url = "$waapi/send/message?session=$session"; // Endpoint dengan parameter sesi
                                                                                  if ($deviceId !== '') {
                                                                                      $url .= '&device_id=' . urlencode($deviceId);
                                                                                  }
                                                                                  $headers = [
                                                                                      "Content-Type: application/json"
                                                                                  ];
                                                                                  if ($deviceId !== '') {
                                                                                      $headers[] = "X-Device-Id: $deviceId";
                                                                                  }
                                                                                  $ch = curl_init();
                                                                                  curl_setopt($ch, CURLOPT_URL, $url);
                                                                                  curl_setopt($ch, CURLOPT_POST, true);
                                                                                  curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                                                                                  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                                                                  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                                                                                  // Tambahkan Basic Auth
                                                                                  curl_setopt($ch, CURLOPT_USERPWD, "$botname:$botpass");
                                                                                  // Eksekusi dan tangani respons
                                                                                  $response = curl_exec($ch);
                                                                                  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                                                                                  curl_close($ch);
                                                                                  sleepAman(); // jeda aman antar pesan, sebelumnya tidak pernah dipanggil di file ini
                                                                                  $response_data = json_decode($response, true); // Konversi ke array



                                                                                  $history[] = "Bot mengirim pesan WHATSAPP ke $NOWA dengan pesan $remainder_by_tanggal_parsed" . date('Y-m-d H:i:s');
                                                                                  // Simpan ke file history
                                                                                  file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));







                                                                          } 
                                                                          else 
                                                                          {
                                                                                if($terkhirbayar == '')
                                                                                {

                                                                                }
                                                                                else
                                                                                {
                                                                                        $date1 = new DateTime($hari_ini);
                                                                                        $date2 = new DateTime( $jatuh_tempo );
                                                                                        $selisih = $date1->diff($date2)->days;

                                                                                        echo "Belum jatuh tempo, masih berlaku sampai " . $jatuh_tempo ;
                                                                                        echo " (sisa $selisih hari lagi)";

                                                                                          // logika khusus jika sisa 2 hari
                                                                                          if ($selisih == 2) {
                                                                                              echo "<BR>NOTIF REMAINDER";


                                                                                                  {
                                                                                                      // Baca file (auto-dibuat dgn template default kalau belum ada
                                                                                                      // -- lihat notif_template_helper.php) dan ambil bagian REMAINDER.
                                                                                                      $isi = notifTemplateGetContent($pemilik);
                                                                                                      $remainder_by_tanggal_raw = getTemplateSection($isi, 'REMAINDER');
                                                                                                      $remainder_by_tanggal_parsed = replaceVariables($remainder_by_tanggal_raw, get_defined_vars());
                                                                                                  }




                                                                                                  $session = $botname; // Nama sesi yang telah Anda buat
                                                                                                  // Nomor tujuan dan pesan
                                                                                                  $phone = "$NOWA@s.whatsapp.net"; // Format: nomor@s.whatsapp.net
                                                                                                  // Data JSON sesuai dengan dokumentasi API
                                                                                                  $data = [
                                                                                                      "phone" => $phone,
                                                                                                      "message" => $remainder_by_tanggal_parsed
                                                                                                      // "reply_message_id" => "optional" // Opsional: ID pesan yang ingin dibalas
                                                                                                  ];
                                                                                                  // Inisialisasi cURL
                                                                                                  $deviceId = trim((string)$sender);
                                                                                                  $url = "$waapi/send/message?session=$session"; // Endpoint dengan parameter sesi
                                                                                                  if ($deviceId !== '') {
                                                                                                      $url .= '&device_id=' . urlencode($deviceId);
                                                                                                  }
                                                                                                  $headers = [
                                                                                                      "Content-Type: application/json"
                                                                                                  ];
                                                                                                  if ($deviceId !== '') {
                                                                                                      $headers[] = "X-Device-Id: $deviceId";
                                                                                                  }
                                                                                                  $ch = curl_init();
                                                                                                  curl_setopt($ch, CURLOPT_URL, $url);
                                                                                                  curl_setopt($ch, CURLOPT_POST, true);
                                                                                                  curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                                                                                                  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                                                                                  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                                                                                                  // Tambahkan Basic Auth
                                                                                                  curl_setopt($ch, CURLOPT_USERPWD, "$botname:$botpass");
                                                                                                  // Eksekusi dan tangani respons
                                                                                                  $response = curl_exec($ch);
                                                                                                  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                                                                                                  curl_close($ch);
                                                                                                  sleepAman(); // jeda aman antar pesan, sebelumnya tidak pernah dipanggil di file ini
                                                                                                  $response_data = json_decode($response, true); // Konversi ke array



                                                                                                  $history[] = "Bot mengirim pesan WHATSAPP ke $NOWA dengan pesan $remainder_by_tanggal_parsed" . date('Y-m-d H:i:s');
                                                                                                  // Simpan ke file history
                                                                                                  file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));


                                                                                }
                                                                                else
                                                                                {
                                                                                    echo "<BR>SKIP REMAINDER";
                                                                                }

                                                                              

                                                                           }

                                                                          }
                                                                        

                                                                        


                                                      } else {
                                                        echo "<br><br>";
                                                          echo "Belum ada transaksi berhasil.";
                                                           echo "<br><br>";
                                                              // cek tipe bayar
                                                          if ($TIPE_BAYAR == "prabayar") {
                                                              echo "Memproses pelanggan prabayar: $IDPEL<br>";
                                                              $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Memproses pelanggan prabayar: $IDPEL";
                                                              // Simpan ke file history
                                                              file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
                                                              echo "MATIKAN / ISOLIR";


                                                              {
                                                                        // Baca file (auto-dibuat dgn template default kalau belum ada
                                                                        // -- lihat notif_template_helper.php) dan ambil bagian EXPIRED.
                                                                        $isi = notifTemplateGetContent($pemilik);
                                                                        $expired_raw = getTemplateSection($isi, 'EXPIRED');

                                                                        // Ganti variabel di dalam teks
                                                                        $expired_parsed = replaceVariables($expired_raw, get_defined_vars());

                                                                        // Tambahkan salam pembuka dinamis untuk menghindari spam detection
                                                                        $expired_parsed = prependDynamicGreeting($expired_parsed);
                                                                      }


                                                                                echo "Mengirim pesan WhatsApp ke $NOWA...<br>";
                                                                                $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Mengirim pesan WhatsApp ke $NOWA";
                                                                                // Simpan ke file history
                                                                                file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
                                                                                $session = $botname; // Nama sesi yang telah Anda buat
                                                                                // Nomor tujuan dan pesan
                                                                                $phone = "$NOWA@s.whatsapp.net"; // Format: nomor@s.whatsapp.net
                                                                             

                                                                                // Data JSON sesuai dengan dokumentasi API
                                                                                $data = [
                                                                                  "phone" => $phone,
                                                                                  "message" => $expired_parsed
                                                                                  // "reply_message_id" => "optional" // Opsional: ID pesan yang ingin dibalas
                                                                                ];

                                                                                // Inisialisasi cURL
                                                                                $deviceId = trim((string)$sender);
                                                                                $url = "$waapi/send/message?session=" . urlencode($session); // Endpoint dengan parameter sesi
                                                                                if ($deviceId !== '') {
                                                                                  $url .= '&device_id=' . urlencode($deviceId);
                                                                                }
                                                                                $headers = [
                                                                                  "Content-Type: application/json"
                                                                                ];
                                                                                if ($deviceId !== '') {
                                                                                  $headers[] = "X-Device-Id: $deviceId";
                                                                                }
                                                                                $ch = curl_init();
                                                                                curl_setopt($ch, CURLOPT_URL, $url);
                                                                                curl_setopt($ch, CURLOPT_POST, true);
                                                                                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                                                                                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                                                                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                                                                                // Tambahkan Basic Auth
                                                                                curl_setopt($ch, CURLOPT_USERPWD, "$botname:$botpass");

                                                                                // Eksekusi dan tangani respons
                                                                                $response = curl_exec($ch);
                                                                                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                                                                                curl_close($ch);
                                                                                sleepAman(); // jeda aman antar pesan, sebelumnya tidak pernah dipanggil di file ini


                                                                                echo "Pesan WhatsApp terkirim  $expired_parsed   response:   $response (HTTP Code: $httpCode).<br>";
                                                                                $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Pesan WhatsApp terkirim ke $NOWA";
                                                                                // Simpan ke file history
                                                                                file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));


                                                                                    
                                                                                // // //                             ///////////NOTIF BY EMAIL //////////////			 
                                                                                // $mail = new PHPMailer;
                                                                                // $mail->IsSMTP();
                                                                                // $mail->SMTPSecure = '';
                                                                                // $mail->Host = "quenbytekniksejahtera.com"; //host masing2 provider email
                                                                                // $mail->SMTPDebug = 2;
                                                                                // $mail->Port = 25;
                                                                                // $mail->SMTPAuth = false;
                                                                                // $mail->Username = "helpdesk@quenbytekniksejahtera.com"; //user email
                                                                                // $mail->Password = "helpdeskqts"; //password email 
                                                                                // $mail->SetFrom("helpdesk@quenbytekniksejahtera.com", "FIBERQ"); //set email pengirim
                                                                                // $mail->Subject = "Wifi anda terisolir"; //subyek email
                                                                                // $mail->AddAddress($EMAIL, "$NAMA - $IDPEL ");  //tujuan email
                                                                                // $mail->MsgHTML("Yth. Bapak / Ibu  $NAMA<br>
                                                                                // Internet anda terisolir.<br>
                                                                                // <br>                                                        
                                                                                // Nama : $NAMA <br>
                                                                                // ID pelanggan : $IDPEL <br>
                                                                                // Paket Langganan : $PAKET <br>
                                                                                // Harga per bulan : $harga <br>
                                                                                // Alamat : $ALAMAT <br>
                                                                                // No WHATSAPP : $NOWA <br>
                                                                                // E Mail : $EMAIL <br>
                                                                                // Tanggal aktif : $TANGGALPASANG <br>
                                                                                // Invoice : https://quenbytekniksejahtera.com/crm/billing/broadband/portal_baru.php?cari=$IDPEL<br>
                                                                                // <br>           
                                                                                // -Link pembayaran :\nhttps://quenbytekniksejahtera.com/crm/billing/broadband/portal_baru.php?cari=$IDPEL<br>
                                                                                // <br>
                                                                                // Demikian yang dapat kami sampaikan terima kasih<br>
                                                                                // Terima kasih telah mempercayai kami dalam kebutuhan internet anda<br>
                                                                                // <br>
                                                                                // salam FIBERQ ");
                                                                                // // if ($mail->Send()) echo "Message has been sent";
                                                                                // // else echo "Failed to sending message";



                                                                                // $history[] = "Bot mengirim pesan E-MAIL ke $EMAIL dengan pesan $text" . date('Y-m-d H:i:s');
                                                                                // // Simpan ke file history
                                                                                // file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));






echo "Melakukan isolir internet untuk $IDPEL...<br>";
$history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Melakukan isolir internet untuk $IDPEL";
file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
if($AUTHMODE=='API MODE' | $AUTHMODE=='MULTI MODE'  )
{



                                                                                // ////////koneksi ke mikrotik ///////
                                                                                echo "Mode API: Mengubah profile ke EXPIRED dan menghapus active connection.<br>";
                                                                                $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Mode API: Mengubah profile ke EXPIRED dan menghapus active connection untuk $IDPEL";
                                                                                file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
                                                                                $API = new RouterosAPI();
                                                                                $API->connect($IP, $PEMILIK, $PASSWORD);
                                                                                $cariurutan = $API->comm(
                                                                                  "/ppp/secret/getall",
                                                                                  array(
                                                                                    ".proplist" => ".id",
                                                                                    "?name" => $IDPEL,
                                                                                  )
                                                                                );
                                                                                $API->comm(
                                                                                  "/ppp/secret/set",
                                                                                  array(
                                                                                    ".id" => $cariurutan[0][".id"],
                                                                                    "comment"  => "EXPIRED $NAMA - $NOWA - $ptanggalskg",
                                                                                    "profile"  => "EXPIRED",
                                                                                  )
                                                                                );
                                                                                $API2 = new RouterosAPI();
                                                                                $API2->connect($IP, $PEMILIK, $PASSWORD);
                                                                                $cariurutan2 = $API2->comm(
                                                                                  "/ppp/active/getall",
                                                                                  array(
                                                                                    ".proplist" => ".id",
                                                                                    "?name" => $IDPEL,
                                                                                  )
                                                                                );
                                                                                $API2->comm(
                                                                                  "/ppp/active/remove",
                                                                                  array(
                                                                                    ".id" => $cariurutan2[0][".id"],
                                                                                  )
                                                                                );
                                                                                $blmbyr++;


                                                                                echo "Isolir via API selesai.<br>";
                                                                                $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Isolir via API selesai untuk $IDPEL";
                                                                                file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));


                                                                                $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Bot melakukan isolir INTERNET $IDPEL mode mikrotik di jam " . date('Y-m-d H:i:s');
                                                                                // Simpan ke file history
                                                                                file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));


}

echo "Mode RADIUS: Menghapus user dari FreeRADIUS.<br>";
$history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Mode RADIUS: Menghapus user dari FreeRADIUS untuk $IDPEL";
file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
if($AUTHMODE=='RADIUS MODE' | $AUTHMODE=='MULTI MODE'  )
{
    $idpel = $IDPEL;
    // PENTING: pakai $data1['PASSWORD'] (password PPPoE pelanggan), BUKAN
    // variabel $PASSWORD -- di scope ini $PASSWORD sudah ke-shadow oleh
    // password login API router, bukan password pelanggan.
    $pppoe_password = $data1['PASSWORD'] ?? '';

    if ($pppoe_password === '') {
        echo "⚠️ Lewati isolir RADIUS untuk '$idpel': password PPPoE kosong di database.";
    } else {
        $paket_q = mysqli_query($conn, "SELECT * FROM paket WHERE PAKET='" . mysqli_real_escape_string($conn, $PAKET) . "' AND PEMILIK='" . mysqli_real_escape_string($conn, $PEMILIK) . "' ORDER BY id DESC LIMIT 1");
        $paketRow = ($paket_q && mysqli_num_rows($paket_q) > 0) ? mysqli_fetch_assoc($paket_q) : ['PAKET' => $PAKET, 'KECEPATAN' => ''];

        // Isolir = soft-block (bukan hapus total seperti sebelumnya).
        radiusSyncSingleCustomerNow($idpel, $pppoe_password, $paketRow, false, radiusGetGlobalSettings($conn));

        echo "✅ User '$idpel' diisolir di FreeRADIUS (grup/address-list EXPIRED, entry tetap ada).";
    }

    $timer_file = "/etc/freeradius/user_timers/{$idpel}.json";
    if (file_exists($timer_file)) unlink($timer_file);

                                                                                $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Bot melakukan isolir INTERNET $IDPEL mode radius di jam " . date('Y-m-d H:i:s');
                                                                                // Simpan ke file history
                                                                                file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
}

echo "Isolir via RADIUS selesai.<br>";
$history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Isolir via RADIUS selesai untuk $IDPEL";
file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
                                                        
                                                                              }




                                                          if ($TIPE_BAYAR == "pascabayar") {
                                                              // logika untuk pasca bayar
                                                              echo "Perhatian: pelanggan pasca bayar, perlu monitoring pembayaran pertama.";
                                                              $jatuh_tempo  = date("Y-m-d", strtotime("+30 days", strtotime($TANGGALPASANG)));
                                                              echo "<br>Jatuh tempo pertama: " . $jatuh_tempo ;




                                                                if ($jatuh_tempo <= $hari_ini) {
                                                                {
                                                                                          echo "MATIKAN / ISOLIR";


                                                                {
                                                                          // Baca file (auto-dibuat dgn template default kalau belum ada
                                                                          // -- lihat notif_template_helper.php) dan ambil bagian EXPIRED.
                                                                          $isi = notifTemplateGetContent($pemilik);
                                                                          $expired_raw = getTemplateSection($isi, 'EXPIRED');

                                                                          // Ganti variabel di dalam teks
                                                                          $expired_parsed = replaceVariables($expired_raw, get_defined_vars());

                                                                          // Tambahkan salam pembuka dinamis untuk menghindari spam detection
                                                                          $expired_parsed = prependDynamicGreeting($expired_parsed);
                                                                        }


                                                                                  $session = $botname; // Nama sesi yang telah Anda buat
                                                                                  // Nomor tujuan dan pesan
                                                                                  $phone = "$NOWA@s.whatsapp.net"; // Format: nomor@s.whatsapp.net


                                                                                  // Data JSON sesuai dengan dokumentasi API
                                                                                  $data = [
                                                                                    "phone" => $phone,
                                                                                    "message" => $expired_parsed
                                                                                    // "reply_message_id" => "optional" // Opsional: ID pesan yang ingin dibalas
                                                                                  ];

                                                                                  // Inisialisasi cURL
                                                                                  $deviceId = trim((string)$sender);
                                                                                  $url = "$waapi/send/message?session=$session"; // Endpoint dengan parameter sesi
                                                                                  if ($deviceId !== '') {
                                                                                    $url .= '&device_id=' . urlencode($deviceId);
                                                                                  }
                                                                                  $headers = [
                                                                                    "Content-Type: application/json"
                                                                                  ];
                                                                                  if ($deviceId !== '') {
                                                                                    $headers[] = "X-Device-Id: $deviceId";
                                                                                  }
                                                                                  $ch = curl_init();
                                                                                  curl_setopt($ch, CURLOPT_URL, $url);
                                                                                  curl_setopt($ch, CURLOPT_POST, true);
                                                                                  curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                                                                                  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                                                                  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                                                                                  // Tambahkan Basic Auth
                                                                                  curl_setopt($ch, CURLOPT_USERPWD, "$botname:$botpass");

                                                                                  // Eksekusi dan tangani respons
                                                                                  $response = curl_exec($ch);
                                                                                  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                                                                                  curl_close($ch);
                                                                                  sleepAman(); // jeda aman antar pesan, sebelumnya tidak pernah dipanggil di file ini


                                                                                  
                                                        // // //                             ///////////NOTIF BY EMAIL //////////////			 
                                                        // $mail = new PHPMailer;
                                                        // $mail->IsSMTP();
                                                        // $mail->SMTPSecure = '';
                                                        // $mail->Host = "quenbytekniksejahtera.com"; //host masing2 provider email
                                                        // $mail->SMTPDebug = 2;
                                                        // $mail->Port = 25;
                                                        // $mail->SMTPAuth = false;
                                                        // $mail->Username = "helpdesk@quenbytekniksejahtera.com"; //user email
                                                        // $mail->Password = "helpdeskqts"; //password email 
                                                        // $mail->SetFrom("helpdesk@quenbytekniksejahtera.com", "FIBERQ"); //set email pengirim
                                                        // $mail->Subject = "Wifi anda terisolir"; //subyek email
                                                        // $mail->AddAddress($EMAIL, "$NAMA - $IDPEL ");  //tujuan email
                                                        // $mail->MsgHTML("Yth. Bapak / Ibu  $NAMA<br>
                                                        // Internet anda terisolir.<br>
                                                        // <br>                                                        
                                                        // Nama : $NAMA <br>
                                                        // ID pelanggan : $IDPEL <br>
                                                        // Paket Langganan : $PAKET <br>
                                                        // Harga per bulan : $harga <br>
                                                        // Alamat : $ALAMAT <br>
                                                        // No WHATSAPP : $NOWA <br>
                                                        // E Mail : $EMAIL <br>
                                                        // Tanggal aktif : $TANGGALPASANG <br>
                                                        // Invoice : https://quenbytekniksejahtera.com/crm/billing/broadband/portal_baru.php?cari=$IDPEL<br>
                                                        // <br>           
                                                        // -Link pembayaran :\nhttps://quenbytekniksejahtera.com/crm/billing/broadband/portal_baru.php?cari=$IDPEL<br>
                                                        // <br>
                                                        // Demikian yang dapat kami sampaikan terima kasih<br>
                                                        // Terima kasih telah mempercayai kami dalam kebutuhan internet anda<br>
                                                        // <br>
                                                        // salam FIBERQ ");
                                                        // // if ($mail->Send()) echo "Message has been sent";
                                                        // // else echo "Failed to sending message";



                                                        // $history[] = "Bot mengirim pesan E-MAIL ke $EMAIL dengan pesan $text" . date('Y-m-d H:i:s');
                                                        // // Simpan ke file history
                                                        // file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));







if($AUTHMODE=='API MODE' | $AUTHMODE=='MULTI MODE'  )
{

                                                        // ////////koneksi ke mikrotik ///////
                                                        $API = new RouterosAPI();
                                                        $API->connect($IP, $PEMILIK, $PASSWORD);
                                                        $cariurutan = $API->comm(
                                                          "/ppp/secret/getall",
                                                          array(
                                                            ".proplist" => ".id",
                                                            "?name" => $IDPEL,
                                                          )
                                                        );
                                                        $API->comm(
                                                          "/ppp/secret/set",
                                                          array(
                                                            ".id" => $cariurutan[0][".id"],
                                                            "comment"  => "EXPIRED $NAMA - $NOWA - $ptanggalskg",
                                                            "profile"  => "EXPIRED",
                                                          )
                                                        );
                                                        $API2 = new RouterosAPI();
                                                        $API2->connect($IP, $PEMILIK, $PASSWORD);
                                                        $cariurutan2 = $API2->comm(
                                                          "/ppp/active/getall",
                                                          array(
                                                            ".proplist" => ".id",
                                                            "?name" => $IDPEL,
                                                          )
                                                        );
                                                        $API2->comm(
                                                          "/ppp/active/remove",
                                                          array(
                                                            ".id" => $cariurutan2[0][".id"],
                                                          )
                                                        );
                                                        $blmbyr++;


                                                        $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Bot melakukan isolir INTERNET $IDPEL mode mikrotik di jam " . date('Y-m-d H:i:s');
                                                        // Simpan ke file history
                                                        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

}


if($AUTHMODE=='RADIUS MODE' | $AUTHMODE=='MULTI MODE'  )
{
    $idpel = $IDPEL;
    // PENTING: pakai $data1['PASSWORD'] (password PPPoE pelanggan), BUKAN
    // variabel $PASSWORD -- di scope ini $PASSWORD sudah ke-shadow oleh
    // password login API router, bukan password pelanggan.
    $pppoe_password = $data1['PASSWORD'] ?? '';

    if ($pppoe_password === '') {
        echo "⚠️ Lewati isolir RADIUS untuk '$idpel': password PPPoE kosong di database.";
    } else {
        $paket_q = mysqli_query($conn, "SELECT * FROM paket WHERE PAKET='" . mysqli_real_escape_string($conn, $PAKET) . "' AND PEMILIK='" . mysqli_real_escape_string($conn, $PEMILIK) . "' ORDER BY id DESC LIMIT 1");
        $paketRow = ($paket_q && mysqli_num_rows($paket_q) > 0) ? mysqli_fetch_assoc($paket_q) : ['PAKET' => $PAKET, 'KECEPATAN' => ''];

        // Isolir = soft-block (bukan hapus total seperti sebelumnya).
        radiusSyncSingleCustomerNow($idpel, $pppoe_password, $paketRow, false, radiusGetGlobalSettings($conn));

        echo "✅ User '$idpel' diisolir di FreeRADIUS (grup/address-list EXPIRED, entry tetap ada).";
    }

    $timer_file = "/etc/freeradius/user_timers/{$idpel}.json";
    if (file_exists($timer_file)) unlink($timer_file);

                                                        $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Bot melakukan isolir INTERNET $IDPEL mode radius di jam " . date('Y-m-d H:i:s');
                                                        // Simpan ke file history
                                                        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
}


                                                                }
                                                          }
                                                      }
                                          }



                }
}
}
}
}



























































