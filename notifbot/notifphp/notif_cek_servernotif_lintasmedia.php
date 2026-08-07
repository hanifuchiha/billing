<?php
require('../../routeros_api.class.php');
include '../../koneksidb.php';
$hariinitgl = date('d');
$currentDate = date('Y-m-d');

// Jeda aman antar pesan supaya nomor bot tidak dianggap spam oleh WhatsApp --
// pola sama persis dgn notif_remainder_pembayaran.php/non_aktif_tempo.php dkk.
function sleepAman($min = 4, $max = 6)
{
  $delayMs = rand($min * 1000, $max * 1000);
  usleep($delayMs * 1000);
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
      $jatuh_tempo = $item['jatuh_tempo'];
      $hari_sebelum = $item['hari_sebelum'];
      $tanggal_reminder = $item['tanggal_reminder'];
      $botname = $item['botname'];
    }
  } else {
    echo "Error: Gagal mendecode JSON.";
    // PENTING: dulu cuma echo (kebuang oleh redirect cron `> /dev/null 2>&1`),
    // jadi kegagalan total ini tidak pernah tercatat. $history_file belum
    // didefinisikan di titik ini, jadi tulis langsung ke file history-nya.
    $histFileEarly = "../data/history-$pemilik.json";
    $histEarly = file_exists($histFileEarly) ? json_decode(file_get_contents($histFileEarly), true) : [];
    if (!is_array($histEarly)) $histEarly = [];
    $histEarly[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] GAGAL TOTAL: file reminder-$pemilik.json ada tapi tidak bisa didecode (JSON rusak) - cron notif_cek_servernotif dihentikan";
    file_put_contents($histFileEarly, json_encode($histEarly, JSON_PRETTY_PRINT));
    die();
  }
} else {
  echo "Error: File JSON tidak ditemukan.";
  $histFileEarly = "../data/history-$pemilik.json";
  $histEarly = file_exists($histFileEarly) ? json_decode(file_get_contents($histFileEarly), true) : [];
  if (!is_array($histEarly)) $histEarly = [];
  $histEarly[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] GAGAL TOTAL: file reminder-$pemilik.json tidak ditemukan - cron notif_cek_servernotif dihentikan";
  file_put_contents($histFileEarly, json_encode($histEarly, JSON_PRETTY_PRINT));
  die();
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

$sql1 = "SELECT * FROM `botwa` WHERE `namebot` = '$botname'";
$query1 = mysqli_query($conn, $sql1);

// Nomor urut
$nomor = 1;



$waapi = null;
while ($data1 = mysqli_fetch_array($query1)) {
  $waapi = $data1['addressbot'];
  $botpass = $data1['password'];
   $penerima =$data1['penerima_server'];
  $sender = $data1['sender'] ?? '';
}
// PENTING: sebelumnya tidak ada pengecekan sama sekali kalau tidak ada baris
// botwa untuk pemilik ini -- variabel $waapi/$botpass akan undefined dan curl
// di bawah gagal total tanpa jejak log apa pun.
if (empty($waapi)) {
  echo "ERROR: Tidak ada bot WA ($botname) ditemukan untuk pemilik $pemilik<br>";
  $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] GAGAL TOTAL: bot WA '$botname' tidak ditemukan untuk pemilik $pemilik - cron notif_cek_servernotif dihentikan";
  file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
  die();
}

/////////////////////////////////////////////////////////////////////////////////


















$cektanggal = date('Y-m-d');

// Fungsi untuk format tanggal dalam Bahasa Indonesia
function tanggal_indo($tanggal, $cetak_hari = false, $penyesuaian_bulan = 0)
{
  $bulan = array(1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember');

  $split = explode('-', $tanggal);
  $tahun = (int)$split[0];
  $bulan_index = (int)$split[1];

  // Penyesuaian bulan dan tahun
  $bulan_index += $penyesuaian_bulan;
  if ($bulan_index < 1) {
    $bulan_index += 12;
    $tahun -= 1; // Kurangi tahun jika bulan < 1
  } elseif ($bulan_index > 12) {
    $bulan_index -= 12;
    $tahun += 1; // Tambah tahun jika bulan > 12
  }

  $tgl_indo = "{$bulan[$bulan_index]} $tahun";

  if ($cetak_hari) {
    $tgl_indo = "$tgl_indo";
  }

  return $tgl_indo;
}

// Tanggal sekarang
$ptanggalalu = tanggal_indo($cektanggal, true, -1); // Bulan lalu
echo $ptanggalalu . "<br>";

$ptanggalskg = tanggal_indo($cektanggal, true); // Bulan ini
echo $ptanggalskg . "<br>";

$ptanggaberikut = tanggal_indo($cektanggal, true, 1); // Bulan berikutnya
echo $ptanggaberikut . "<br>";
echo "<br>";


/////////////////////////////////////////////////////////////////////////////////


$sql = "SELECT * FROM `server` WHERE `user_id` = '$iduser'";
$query = mysqli_query($conn, $sql);
$server_count = mysqli_num_rows($query);
echo "Query server: $sql<br>";
echo "Jumlah server ditemukan: $server_count<br>";

if ($server_count == 0) {
    echo "Tidak ada server untuk pemilik $pemilik. Lewati.<br><br>";
    exit;
}

while ($data = mysqli_fetch_array($query)) {
  $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Notif status all server ke owner dimulai";
  // Simpan ke file history
  file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));


  $client = 0;
  $cekbayar = 0;
  $cekbelumbayar = 0;
  $online = 0;
  $offline = 0;
  echo "<hr>";
  echo "<hr>";
  echo "<hr>";
  echo "<hr>";
  echo "<hr>";
  echo "<hr>";
  echo "<hr>";
  echo "<hr>";
  echo "<hr>";
  echo "<br>";
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
  echo $TEMPO = $data['TEMPO'];
  echo "<br>";
  echo "<hr>";



  $API = new RouterosAPI();

  $sql1 = "SELECT * from `pelanggan` WHERE `PEMILIK` = '$PEMILIK' and `AREA`='$AREA'";
  $query1 = mysqli_query($conn, $sql1);
  while ($data1 = mysqli_fetch_array($query1)) {



    $client++;
    echo  $IDPEL = $data1['IDPEL'];
    echo "<br>";
    echo  $NAMA = $data1['NAMA'];
    echo "<br>";
    echo  $NOWA = $data1['NOWA'];
    echo "<br>";
    echo $EMAIL = $data1['EMAIL'];
    echo "<br>";
    echo  $PAKET = $data1['PAKET'];
    echo "<br>";
    echo $TANGGALPASANG = $data1['TANGGALPASANG'];
    echo "<br>";
    echo  $ALAMAT = $data1['ALAMAT'];
    echo "<br>";


    echo $sql2 = "SELECT * from `transaksi` WHERE `PEMILIK` = '$PEMILIK' and `PENGUNAAN` LIKE '%$ptanggalskg%' and `STATUS` = 'BERHASIL' and `IDPEL`='$IDPEL'  ";
    $query2 = mysqli_query($conn, $sql2);
    $cek = mysqli_num_rows($query2);
    if ($cek == 1) {
      $cekbayar = $cekbayar + 1;
      echo "<br>";
      echo "SUDAH BAYAR";
      echo "<br>";
      echo "<hr>";
    } else {
      $cekbelumbayar = $cekbelumbayar + 1;
      echo "<br>";
      echo "BELUM BAYAR";
      echo "<br>";
      echo "<hr>";
    }



    if ($API->connect($IP, $PEMILIK, $PASSWORD)) {

      $interface = "<pppoe-" . $IDPEL . ">";
      $getinterfacetraffic = $API->comm("/interface/monitor-traffic", array(
        "interface" => "$interface",
        "once" => "",
      ));
      if ($getinterfacetraffic[0]['tx-bits-per-second'] != "") {
        echo    $offline = $offline + 1;
      } else {

        echo    $online = $online + 1;
      }
    }
  }


  $jam = date('H:i:s');



  $text = "*[ALERT SYSTEM BOT APP QTS]*\n=============================\nInfo Server $AREA $PEMILIK $ptanggalskg $jam\n=============================\nAREA = $AREA\nSERVER = $PEMILIK\nCLIENT = $client\nOFFLINE = $online\nONLINE =  $offline\nPENGUNAAN : $ptanggalskg\nBELUM BAYAR = $cekbelumbayar\nSUDAH BAYAR = $cekbayar\n============================\n";

  // Tambahkan salam pembuka dinamis untuk menghindari spam detection
  $text = prependDynamicGreeting($text);
  
  // Nomor tujuan dan pesan
  $phone = $penerima; // Format: nomor@s.whatsapp.net


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

  // Jeda aman -- loop ini bisa kirim banyak pesan beruntun kalau owner
  // punya banyak server, sebelumnya tidak ada jeda sama sekali di sini.
  sleepAman();








  echo "<hr>";
  echo "<hr>";
  echo "<hr>";
  echo "<hr>";
  echo "<hr>";
  echo "<hr>";
  echo "<hr>";
  echo "<hr>";
  echo "<hr>";
  // Sebelumnya log "selesai" ini selalu ditulis apa pun hasil curl-nya.
  if ($curlErr === '' && $httpCode >= 200 && $httpCode < 300) {
    $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Notif status all server ke owner selesai | HTTP: $httpCode";
  } else {
    $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] GAGAL kirim notif status server ke owner | HTTP: $httpCode | cURL error: $curlErr";
  }
  // Simpan ke file history
  file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
}
