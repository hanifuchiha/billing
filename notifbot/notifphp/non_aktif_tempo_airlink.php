<?php
include '../../koneksidb.php';
require('../../routeros_api.class.php');
require_once('../../radius_sync_lib.php');
require_once __DIR__ . '/../notif_template_helper.php';
include "../phpmailer/classes/class.phpmailer.php";

echo "=== MULAI PROSES NON AKTIF TEMPO ===<br>";
echo "Tanggal hari ini: " . date('Y-m-d H:i:s') . "<br>";

// Simpan konfigurasi
$config_file ='../../config.json'; // Lokasi file di folder yang sama
$config = file_exists($config_file) ? json_decode(file_get_contents($config_file), true) : [];

$domain=$config['domain'];
echo $URL=$config['domain'];

echo "Konfigurasi dimuat dari: $config_file<br>";
echo "Domain: $domain<br>";

// getFreeradiusPID()/restartFreeradius() lokal yang dulu ada di sini sudah
// dihapus (tidak dipakai lagi) -- restart FreeRADIUS sekarang ditangani
// radiusReloadIfChanged() di radius_sync_lib.php, dipanggil lewat
// radiusSyncSingleCustomerNow() di bagian isolir RADIUS di bawah.







$hariinitgl = date('d');
//$hariinitgl=25;
$currentDate = date('Y-m-d');
// Fungsi delay aman (4–6 detik acak)
function sleepAman($min = 4, $max = 6)
{
  echo "Delay aman $min-$max detik...<br>";
  $delay = rand($min * 1000, $max * 1000);
  usleep($delay * 1000);
  echo "Delay selesai<br>";
}

////////////////////////////////////////

echo "Memparsing nama file untuk mendapatkan pemilik...<br>";
$filename = basename(__FILE__); // contoh: hapus_kode_permintaan_bayar_FIBERQ.php
$nameOnly = pathinfo($filename, PATHINFO_FILENAME); // hapus_kode_permintaan_bayar_FIBERQ

$parts = explode('_', $nameOnly);
$pemilik = end($parts); // ambil bagian terakhir

echo "Bagian terakhir dari nama file: $pemilik <br>";


//////DATA USERNAME/////////////////////////////////////////////////////////////
echo "Mengambil data user dari database...<br>";
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
echo "Data user berhasil diambil: $username (ID: $iduser)<br>";
/////////////////////////////////////////////////////////////////////////////////


// Path ke file JSON
echo "Membaca file reminder JSON...<br>";
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
      $jatuh_tempo = $item['jatuh_tempo'];
      $hari_sebelum = $item['hari_sebelum'];
      $tanggal_reminder = $item['tanggal_reminder'];
      $tanggal_awal_tutup_buku = $item['tanggal_awal_tutup_buku'];
      $tanggal_akhir_tutup_buku = $item['tanggal_akhir_tutup_buku'];
    }
    echo "Data reminder berhasil dibaca: Jatuh tempo $jatuh_tempo, hari sebelum $hari_sebelum<br>";
  } else {
    echo "Error: Gagal mendecode JSON.<br>";
    // PENTING: dulu cuma echo (kebuang oleh redirect cron `> /dev/null 2>&1`),
    // jadi kegagalan total ini tidak pernah tercatat. $history_file belum
    // didefinisikan di titik ini, jadi tulis langsung ke file history-nya.
    $histFileEarly = "../data/history-$pemilik.json";
    $histEarly = file_exists($histFileEarly) ? json_decode(file_get_contents($histFileEarly), true) : [];
    if (!is_array($histEarly)) $histEarly = [];
    $histEarly[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] GAGAL TOTAL: file reminder-$pemilik.json ada tapi tidak bisa didecode (JSON rusak) - cron non_aktif_tempo dihentikan";
    file_put_contents($histFileEarly, json_encode($histEarly, JSON_PRETTY_PRINT));
    die();
  }
} else {
  echo "Error: File JSON tidak ditemukan.<br>";
  $histFileEarly = "../data/history-$pemilik.json";
  $histEarly = file_exists($histFileEarly) ? json_decode(file_get_contents($histFileEarly), true) : [];
  if (!is_array($histEarly)) $histEarly = [];
  $histEarly[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] GAGAL TOTAL: file reminder-$pemilik.json tidak ditemukan - cron non_aktif_tempo dihentikan";
  file_put_contents($histFileEarly, json_encode($histEarly, JSON_PRETTY_PRINT));
  die();
}





// Cek apakah sudah pernah dikirim
echo "Membaca file history...<br>";
 echo $history_file = "../data/history-$pemilik.json";
$history = [];

if (file_exists($history_file)) {
  $history = json_decode(file_get_contents($history_file), true);
}

// Pastikan format history adalah array
if (!is_array($history)) {
  $history = [];
}
echo "History berhasil dimuat, jumlah entry: " . count($history) . "<br>";
echo "File history dapat dibaca: " . (is_readable($history_file) ? 'Ya' : 'Tidak') . "<br>";
echo "File history dapat ditulis: " . (is_writable($history_file) ? 'Ya' : 'Tidak') . "<br>";

















/////////////////////////////////////////////////////////////////////////////////


echo "Mengambil data bot WhatsApp...<br>";
// Include helper function untuk pemilihan bot (termasuk dukungan RANDOM)
require_once('../bot_selector_helper.php');

$selectedBotSystem = '';
$botCategoryFile = "../data/bot_receiver_config-$pemilik.json";
if (file_exists($botCategoryFile)) {
  $botCategoryData = json_decode(file_get_contents($botCategoryFile), true);
  if (is_array($botCategoryData) && !empty($botCategoryData['system'])) {
    $selectedBotSystem = trim((string)$botCategoryData['system']);
  }
}

// Gunakan helper function untuk pemilihan bot (mendukung RANDOM)
$bot_result = selectBotForNotificationWithField($conn, $pemilik, $selectedBotSystem, 'penerima_system_notif');

if ($bot_result['success']) {
  $waapi = $bot_result['addressbot'];
  $botpass = $bot_result['password'];
  $botname = $bot_result['namebot'];
  $penerima_system_notif = $bot_result['penerima'];
  
  if ($bot_result['is_random']) {
    echo "[RANDOM BOT] Bot dipilih secara acak: $botname untuk menghindari spam<br>";
  }
} else {
  echo "ERROR: " . $bot_result['message'] . "<br>";
  $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] GAGAL TOTAL: tidak ada bot WA ditemukan untuk pemilik $pemilik (" . $bot_result['message'] . ") - cron non_aktif_tempo dihentikan";
  file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
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













$cektanggal = date('Y-m-d');

// Fungsi untuk format tanggal dalam Bahasa Indonesia
function tanggal_indo2($tanggal, $cetak_hari = false, $penyesuaian_bulan = 0)
{
    $hari = array(1 => 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu');
    $bulan = array(1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember');

    $split = explode('-', $tanggal);
    $tahun = (int)$split[0];
    $bulan_index = (int)$split[1];

    // Penyesuaian bulan dan tahun
    $bulan_index += $penyesuaian_bulan;
    if ($bulan_index < 1) {
        $bulan_index += 12;
        $tahun -= 1;
    } elseif ($bulan_index > 12) {
        $bulan_index -= 12;
        $tahun += 1;
    }

    if ($cetak_hari) {
        $num_hari = date('N', strtotime($tanggal));
        return $hari[$num_hari] . ', ' . $split[2] . ' ' . $bulan[$bulan_index] . ' ' . $tahun;
    } else {
        return $bulan[$bulan_index] . ' ' . $tahun;
    }
}

// Tanggal sekarang
$ptanggalalu = tanggal_indo2($cektanggal, false, -1); // Bulan lalu
echo $ptanggalalu . "<br>";

$ptanggalskg = tanggal_indo2($cektanggal, false); // Bulan ini
echo $ptanggalskg . "<br>";

$ptanggaberikut = tanggal_indo2($cektanggal, false, 1); // Bulan berikutnya
echo $ptanggaberikut . "<br>";
echo "<br>";
$ptanggalsebelum = tanggal_indo2($cektanggal, false, -1); // Bulan sebelumnya
////////////////////////////////////////////////////////////////////
$tglskg = date('d');
$ptanggalcek = tanggal_indo2($cektanggal, false);


// Logika periode
if ($jatuh_tempo <= $tanggal_awal_tutup_buku) {
  $periode_bulan_berikut = $ptanggalskg;
} elseif ($jatuh_tempo >= $tanggal_akhir_tutup_buku) {
  $periode_bulan_berikut = $ptanggaberikut;
} else {
  $periode_bulan_berikut = $ptanggaberikut;
}

if ($tglskg < $tanggal_awal_tutup_buku) {
  $periode = $ptanggalskg;
} elseif ($tglskg >= $tanggal_awal_tutup_buku && $tglskg <= $tanggal_akhir_tutup_buku) {
  $periode = $periode_bulan_berikut; // Periode bulan berikut berdasarkan jatuh_tempo
} elseif ($tglskg > $tanggal_akhir_tutup_buku) {
  if ($periode_bulan_berikut == $ptanggalskg) {
    $periode = $ptanggalskg;
  } else {
    $periode = tanggal_indo2($cektanggal, false, 2);
  }
}


echo "Hari ini: $hariinitgl, Jatuh tempo: $jatuh_tempo<br>";
echo "Jatuh tempo isolir: $jatuh_tempo<br>";
echo "Tanggal awal tutup buku: $tanggal_awal_tutup_buku<br>";
echo "Tanggal akhir tutup buku: $tanggal_akhir_tutup_buku<br>";
echo "Hari sebelum: $hari_sebelum<br>";
echo "Periode yang digunakan: $periode<br>";




$filePath = notifTemplateFilePath($pemilik);

  // Fungsi pengganti variabel
  function replaceVariables($text)
  {
    return preg_replace_callback('/\$(\w+)/', function ($matches) {
      return isset($GLOBALS[$matches[1]]) ? $GLOBALS[$matches[1]] : $matches[0];
    }, $text);
  }



















if ($hariinitgl == $jatuh_tempo) {


  echo "=== HARI INI ADALAH TANGGAL JATUH TEMPO ===\n";
  echo "Memulai proses isolir semua pelanggan...\n";


  $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Memulai notif bot isolir semua pelanggan";
  echo "Menambahkan ke history: Memulai notif bot isolir semua pelanggan<br>";
  // Simpan ke file history
  file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));



  $text = "Memulai notif bot isolir semua pelanggan di mulai jam";

  // Nomor tujuan dan pesan
  $phone = $penerima_system_notif; // Format: nomor@s.whatsapp.net
  // Data JSON sesuai dengan dokumentasi API
  // Ambil sender jika ada dari tabel botwa
  $sender = '';
  if (!empty($botname)) {
    $sqlSender = "SELECT sender FROM botwa WHERE namebot = '" . mysqli_real_escape_string($conn, $botname) . "' LIMIT 1";
    $querySender = mysqli_query($conn, $sqlSender);
    if ($querySender && $rowSender = mysqli_fetch_assoc($querySender)) {
      $sender = $rowSender['sender'];
    }
  }
  $data = [
    "phone" => $phone,
    "message" => $text,
    "sender" => $sender
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
  $url = "$currentWAAPI/send/message?session=" . urlencode($currentBotName); // Endpoint dengan parameter sesi
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
  $curlErr = curl_error($ch);
  curl_close($ch);

  // Increment target counter untuk distribusi ke bot berikutnya
  if ($isRandomBot && $botPoolCount > 0) {
      $targetIndex++;
  }

  // Sebelumnya tidak ada log sama sekali untuk ping awal ini (cuma echo, kebuang
  // oleh redirect cron `> /dev/null 2>&1`) -- sekarang dicatat ke history.
  $waOk = ($curlErr === '' && $httpCode >= 200 && $httpCode < 300);
  if ($waOk) {
    echo "Pesan notifikasi awal dikirim ke system notif<br>";
  } else {
    echo "GAGAL kirim notifikasi awal ke system notif. HTTP: $httpCode, cURL error: $curlErr<br>";
    $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] GAGAL kirim notif awal isolir tempo ke system notif | HTTP: $httpCode | cURL error: $curlErr";
    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
  }














  echo $sql = "SELECT * FROM `server` WHERE `user_id` = '$iduser'";
  $query = mysqli_query($conn, $sql);
  echo "Query server berhasil, memproses server...<br>";
  $total_servers = mysqli_num_rows($query);
  echo "Total server yang akan diproses: $total_servers<br>";
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
    echo   $PASSWORD = $data['PASSWORD'];
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
    echo "Memproses server: $PEMILIK - $AREA (IP: $IP)<br>"
    ;


    $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Proses broadcast isolir pembayaran client dimulai $PEMILIK - $AREA ( PERIODE => $periode )";
    echo "Menambahkan ke history: Proses broadcast isolir pembayaran client dimulai $PEMILIK - $AREA ( PERIODE => $periode )<br>";
    // Simpan ke file history
    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));



    $text = "Proses broadcast isolir pembayaran client dimulai $PEMILIK - $AREA ( PERIODE => $periode )";


    // Nomor tujuan dan pesan
    $phone = $penerima_system_notif; // Format: nomor@s.whatsapp.net

    // Data JSON sesuai dengan dokumentasi API
    $data = [
      "phone" => $phone,
      "message" => $text
      // "reply_message_id" => "optional" // Opsional: ID pesan yang ingin dibalas
    ];

    // Inisialisasi cURL
    $deviceId = trim((string)$sender);
    $url = "$waapi/send/message?session=" . urlencode($botname); // Endpoint dengan parameter sesi
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
    $curlErr = curl_error($ch);
    curl_close($ch);

    // Sebelumnya tidak ada log sama sekali untuk ping per-server ini.
    $waOk = ($curlErr === '' && $httpCode >= 200 && $httpCode < 300);
    if ($waOk) {
      echo "Pesan notifikasi server dikirim ke system notif<br>";
    } else {
      echo "GAGAL kirim notifikasi server ke system notif. HTTP: $httpCode, cURL error: $curlErr<br>";
      $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] GAGAL kirim notif isolir server $PEMILIK - $AREA ke system notif | HTTP: $httpCode | cURL error: $curlErr";
      file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    }

    // Jeda aman -- loop ini bisa kirim banyak pesan beruntun kalau owner
    // punya banyak server, sebelumnya tidak ada jeda sama sekali di sini.
    sleepAman();












  echo   $sql1 = "SELECT * from `pelanggan` WHERE `PEMILIK` = '$PEMILIK' and `AREA`='$AREA'";
    $query1 = mysqli_query($conn, $sql1);
    echo "Query pelanggan berhasil, memproses pelanggan...<br>";
    $total_pelanggan = mysqli_num_rows($query1);
    echo "Total pelanggan di server $PEMILIK - $AREA: $total_pelanggan<br>";
    while ($data1 = mysqli_fetch_array($query1)) {
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
      echo "<br>";
      echo $AUTHMODE = $data1['MODE']; //SEBAGAI AUTH MODE

      // Daftar mode yang valid
      $valid_modes = ['RADIUS MODE', 'API MODE', 'MULTI MODE'];

      // Cek apakah input valid, jika tidak default ke 'API MODE'
      if (!in_array($AUTHMODE, $valid_modes)) {
          $AUTHMODE = 'API MODE';
      }

      echo $TANGGALPASANG = $data1['TANGGALPASANG'];
      echo "<br>";
      echo $ALAMAT = $data1['ALAMAT'];
      echo "<br>";
      echo $TIPE_BAYAR = $data1['TIPE_BAYAR']; // prabayar atau pascabayar
      echo "<br>";
      echo $TIPE_TEMPO = $data1['TIPE_TEMPO']; // mengikuti_tanggal_bayar atau mengikuti_tanggal_tempo
      echo "<br>";
      echo "Memproses pelanggan: $IDPEL - $NAMA (Paket: $PAKET, Mode: $AUTHMODE)<br>";


                                  if($TIPE_TEMPO=="mengikuti_tanggal_tempo"){
                                  if (stripos($PAKET, 'FREE') === false) {

                                    echo "Mengecek status pembayaran pelanggan $IDPEL untuk periode $periode...<br>";
                                    echo $sql2 = "SELECT * from `transaksi` WHERE  `PENGUNAAN` LIKE '%$periode%' and `STATUS` = 'BERHASIL' and `IDPEL`='$IDPEL'  ";
                                    $query2 = mysqli_query($conn, $sql2);
                                    $cek = mysqli_num_rows($query2);
                                    $url_cari = "https://$domain/crm/billing/broadband/portal.php?cari=" . urlencode($IDPEL);

                                    if ($cek == 0) {

                                      echo "Pelanggan $IDPEL BELUM BAYAR - akan diisolir<br>";
                                      echo "Memulai proses isolir untuk pelanggan $IDPEL...<br>";



                                      echo "<br>";
                                      echo "<br>";
                                      echo "MATIKAN ";

                                      // $text = "[INI ADALAH PESAN OTOMATIS]\n*INTERNET ANDA TERISOLIR / EXPIRED*\n\nHai Bpk/Ibu $NAMA \nInternet anda Telah Terisolir.\n\nDengan detail :\n- ID Pelanggan : $IDPEL \n- Nama Pelanggan : $NAMA \n- Paket langganan : $PAKET \n- No Whatsapp : $NOWA \n- E-mail : $EMAIL\n- Alamat : $ALAMAT\n- Invoice :https://quenbytekniksejahtera.com/crm/billing/broadband/portal.php?cari=" . urlencode($IDPEL) . "\n\n\nLink pembayaran :\n$url_cari\n\n*JIKA LINK TIDAK DAPAT DIKLIK silahkan simpan kontak whatsapp ini terlebih dahulu atau copy link dan paste di browser*\n\nYOUTUBE TUTORIAL CARA BAYAR : https://youtu.be/9Gvu4C2AkW4?si=1qMH1oKTRh0lB5EM  \n\n\nDemikian yang dapat kami sampaikan terima kasih\nTerima kasih telah mempercayai kami dalam kebutuhan internet anda\nsalam $PEMILIK ";



                                      {
                                        // Baca file (auto-dibuat dgn template default kalau belum ada
                                        // -- lihat notif_template_helper.php) dan ambil bagian EXPIRED.
                                        $isi = notifTemplateGetContent($pemilik);
                                        $expired_raw = notifTemplateExtractSection($isi, 'EXPIRED');

                                        // Ganti variabel di dalam teks
                                        $expired_parsed = replaceVariables($expired_raw);

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
                                      $curlErr = curl_error($ch);
                                      curl_close($ch);


                                      // PENTING: sebelumnya baris "Bot mengirim ..." selalu ditulis apa pun hasil
                                      // curl-nya (gagal/timeout/HTTP error tetap dianggap "terkirim" di log).
                                      $waOk = ($curlErr === '' && $httpCode >= 200 && $httpCode < 300);
                                      if ($waOk) {
                                        $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Bot mengirim pesan WHATSAPP ke $NOWA dengan pesan $expired_parsed | HTTP: $httpCode";
                                      } else {
                                        $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] GAGAL kirim WHATSAPP isolir ke $NOWA | HTTP: $httpCode | cURL error: $curlErr | Response: " . substr((string)$response, 0, 200);
                                      }
                                      // Simpan ke file history
                                      file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));









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


                                                              $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Bot melakukan isolir INTERNET $IDPEL mode mikrotik";
                                                              // Simpan ke file history
                                                              file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
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
                                                  // atas (yang juga cuma ganti profile jadi "EXPIRED", bukan hapus
                                                  // secret). Ini juga menjaga jaring pengaman fallback MULTI MODE.
                                                  radiusSyncSingleCustomerNow($idpel, $pppoe_password, $paketRow, false, radiusGetGlobalSettings($conn));

                                                  echo "✅ User '$idpel' diisolir di FreeRADIUS (grup/address-list EXPIRED, entry tetap ada).";
                                              }

                                              $timer_file = "/etc/freeradius/user_timers/{$idpel}.json";
                                              if (file_exists($timer_file)) unlink($timer_file);

                                                            $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Bot melakukan isolir INTERNET $IDPEL mode radius";
                                                            // Simpan ke file history
                                                            file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
                                          }

                                    } 
                                    else 
                                    {
                                      echo "<br>";
                                      echo "<br>";
                                      echo "SUDAH BAYAR PERIODE " . $ptanggaberikut;
                                    
                                    }
                                    sleepAman();
                                  }
                               }
      }




                                    echo "Selesai memproses semua server\n";
                                    $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Selesai notif bot isolir semua pelanggan di mulai jam  $PEMILIK - $AREA ( PERIODE => $periode )";
                                    echo "Menambahkan ke history: Selesai notif bot isolir semua pelanggan di mulai jam  $PEMILIK - $AREA ( PERIODE => $periode )<br>";
                                    // Simpan ke file history
                                    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

                                    $text = "Selesai notif bot isolir semua pelanggan di mulai jam  $PEMILIK - $AREA ( PERIODE => $periode )";
                                    $session = $botname; // Nama sesi yang telah Anda buat
                                    // Nomor tujuan dan pesan
                                    $phone = $penerima_system_notif; // Format: nomor@s.whatsapp.net
                                    // Data JSON sesuai dengan dokumentasi API
                                    $data = [
                                      "phone" => $phone,
                                      "message" => $text
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
                                    $curlErr = curl_error($ch);
                                    curl_close($ch);
                                    if (!($curlErr === '' && $httpCode >= 200 && $httpCode < 300)) {
                                      $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] GAGAL kirim notif selesai isolir server $PEMILIK - $AREA | HTTP: $httpCode | cURL error: $curlErr";
                                      file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
                                    }
                                    echo "<hr>";



}



                                  $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Selesai notif bot isolir semua pelanggan";
                                  echo "Menambahkan ke history: Selesai notif bot isolir semua pelanggan<br>";
                                  // Simpan ke file history
                                  file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

                                  $text = "Selesai notif bot isolir semua pelanggan di mulai jam ";
                                  $session = $botname; // Nama sesi yang telah Anda buat

                                  // Nomor tujuan dan pesan
                                  $phone =$penerima_system_notif; // Format: nomor@s.whatsapp.net

                                  // Data JSON sesuai dengan dokumentasi API
                                  $data = [
                                    "phone" => $phone,
                                    "message" => $text
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
                                  $curlErr = curl_error($ch);
                                  curl_close($ch);
                                  if (!($curlErr === '' && $httpCode >= 200 && $httpCode < 300)) {
                                    $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] GAGAL kirim notif selesai isolir semua pelanggan | HTTP: $httpCode | cURL error: $curlErr";
                                    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
                                  }














} else {
  echo "Bukan hari jatuh tempo, proses isolir dilewati<br>";
}

// ============================================================================
//  Mode "tanggal_tetap_personal": jatuh tempo = tanggal pasang MASING-MASING
//  pelanggan (anniversary date). Berjalan setiap hari script ini dieksekusi --
//  gate-nya per pelanggan (anchor day == hari ini), bukan $jatuh_tempo global.
// ============================================================================
echo "=== CEK TANGGAL TETAP PERSONAL (ANNIVERSARY DATE) ===<br>";
$sqlServerPersonal = "SELECT * FROM `server` WHERE `user_id` = '$iduser'";
$queryServerPersonal = mysqli_query($conn, $sqlServerPersonal);
while ($dataServerPersonal = mysqli_fetch_array($queryServerPersonal)) {
    $AREA = $dataServerPersonal['AREA'];
    $PEMILIK = $dataServerPersonal['PEMILIK'];
    $IP = $dataServerPersonal['IP'];
    $PASSWORD = $dataServerPersonal['PASSWORD'];

    $sqlPelangganPersonal = "SELECT * from `pelanggan` WHERE `PEMILIK` = '$PEMILIK' and `AREA`='$AREA' and `TIPE_TEMPO`='tanggal_tetap_personal'";
    $queryPelangganPersonal = mysqli_query($conn, $sqlPelangganPersonal);
    while ($dataPersonal = mysqli_fetch_array($queryPelangganPersonal)) {
        $IDPEL = $dataPersonal['IDPEL'];
        $NAMA = $dataPersonal['NAMA'];
        $NOWA = $dataPersonal['NOWA'];
        $PAKET = $dataPersonal['PAKET'];
        $AUTHMODE = $dataPersonal['MODE'];
        $valid_modes = ['RADIUS MODE', 'API MODE', 'MULTI MODE'];
        if (!in_array($AUTHMODE, $valid_modes)) {
            $AUTHMODE = 'API MODE';
        }
        $TANGGALPASANG = $dataPersonal['TANGGALPASANG'];

        $anchorDay = (int) date('j', strtotime($TANGGALPASANG));
        if ((int)$hariinitgl !== $anchorDay) {
            continue; // bukan hari jatuh tempo personal pelanggan ini
        }
        if (stripos($PAKET, 'FREE') !== false) {
            continue;
        }

        echo "Mengecek status pembayaran pelanggan $IDPEL (anniversary day=$anchorDay) untuk periode $periode...<br>";
        $sql2 = "SELECT * from `transaksi` WHERE `PENGUNAAN` LIKE '%$periode%' and `STATUS` = 'BERHASIL' and `IDPEL`='$IDPEL'";
        $query2 = mysqli_query($conn, $sql2);
        $cek = mysqli_num_rows($query2);

        if ($cek == 0) {
            echo "Pelanggan $IDPEL BELUM BAYAR (tanggal_tetap_personal) - akan diisolir<br>";

            // Baca file (auto-dibuat dgn template default kalau belum ada -- lihat
            // notif_template_helper.php) dan ambil bagian EXPIRED.
            $isi = notifTemplateGetContent($pemilik);
            $expired_raw = notifTemplateExtractSection($isi, 'EXPIRED');
            $expired_parsed = replaceVariables($expired_raw);

            $session = $botname;
            $phone = "$NOWA@s.whatsapp.net";
            $waData = ["phone" => $phone, "message" => $expired_parsed];
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "$waapi/send/message?session=$session");
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($waData));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
            curl_setopt($ch, CURLOPT_USERPWD, "$botname:$botpass");
            // PENTING: sebelumnya curl_exec() hasilnya dibuang total (tidak ditampung
            // ke variabel), jadi TIDAK MUNGKIN tahu HTTP code/curl error-nya sama
            // sekali -- log sukses selalu ditulis walau pesan sebenarnya gagal terkirim.
            $responsePersonal = curl_exec($ch);
            $httpCodePersonal = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErrPersonal = curl_error($ch);
            curl_close($ch);

            $waOkPersonal = ($curlErrPersonal === '' && $httpCodePersonal >= 200 && $httpCodePersonal < 300);
            if ($waOkPersonal) {
                $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Bot mengirim pesan WHATSAPP ke $NOWA (tanggal_tetap_personal) dengan pesan $expired_parsed | HTTP: $httpCodePersonal";
            } else {
                $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] GAGAL kirim WHATSAPP isolir (tanggal_tetap_personal) ke $NOWA | HTTP: $httpCodePersonal | cURL error: $curlErrPersonal | Response: " . substr((string)$responsePersonal, 0, 200);
            }
            file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

            if ($AUTHMODE == 'API MODE' || $AUTHMODE == 'MULTI MODE') {
                $API = new RouterosAPI();
                $API->connect($IP, $PEMILIK, $PASSWORD);
                $cariurutan = $API->comm("/ppp/secret/getall", array(".proplist" => ".id", "?name" => $IDPEL));
                if (!empty($cariurutan[0]['.id'])) {
                    $API->comm("/ppp/secret/set", array(".id" => $cariurutan[0]['.id'], "comment" => "EXPIRED $NAMA - $NOWA - $ptanggalskg", "profile" => "EXPIRED"));
                }
                $API2 = new RouterosAPI();
                $API2->connect($IP, $PEMILIK, $PASSWORD);
                $cariurutan2 = $API2->comm("/ppp/active/getall", array(".proplist" => ".id", "?name" => $IDPEL));
                if (!empty($cariurutan2[0]['.id'])) {
                    $API2->comm("/ppp/active/remove", array(".id" => $cariurutan2[0]['.id']));
                }
                $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Bot melakukan isolir INTERNET $IDPEL mode mikrotik (tanggal_tetap_personal)";
                file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
            }

            if ($AUTHMODE == 'RADIUS MODE' || $AUTHMODE == 'MULTI MODE') {
                $idpel = $IDPEL;
                // PENTING: pakai $dataPersonal['PASSWORD'] (password PPPoE pelanggan),
                // BUKAN variabel $PASSWORD -- di scope ini $PASSWORD sudah ke-shadow
                // oleh password login API router ($dataServerPersonal['PASSWORD']).
                $pppoe_password = $dataPersonal['PASSWORD'] ?? '';

                if ($pppoe_password === '') {
                    echo "⚠️ Lewati isolir RADIUS untuk '$idpel': password PPPoE kosong di database.";
                } else {
                    $paket_q = mysqli_query($conn, "SELECT * FROM paket WHERE PAKET='" . mysqli_real_escape_string($conn, $PAKET) . "' AND PEMILIK='" . mysqli_real_escape_string($conn, $PEMILIK) . "' ORDER BY id DESC LIMIT 1");
                    $paketRow = ($paket_q && mysqli_num_rows($paket_q) > 0) ? mysqli_fetch_assoc($paket_q) : ['PAKET' => $PAKET, 'KECEPATAN' => ''];

                    // Isolir = soft-block (bukan hapus total): entry RADIUS TETAP ADA
                    // tapi grup/address-list diganti EXPIRED, konsisten dengan cron &
                    // cabang API MODE di atas.
                    radiusSyncSingleCustomerNow($idpel, $pppoe_password, $paketRow, false, radiusGetGlobalSettings($conn));

                    echo "User '$idpel' diisolir di FreeRADIUS (tanggal_tetap_personal, entry tetap ada).<br>";
                }

                $timer_file = "/etc/freeradius/user_timers/{$idpel}.json";
                if (file_exists($timer_file)) unlink($timer_file);
                $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Bot melakukan isolir INTERNET $IDPEL mode radius (tanggal_tetap_personal)";
                file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
            }
            sleepAman();
        } else {
            echo "Pelanggan $IDPEL sudah bayar periode $periode (tanggal_tetap_personal)<br>";
        }
    }
}

echo "=== SELESAI PROSES NON AKTIF TEMPO ===<br>";
echo "Script selesai dijalankan pada: " . date('Y-m-d H:i:s') . "<br>";
