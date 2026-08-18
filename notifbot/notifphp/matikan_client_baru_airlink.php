<?php
require('../../routeros_api.class.php');
require_once('../../radius_sync_lib.php');
require_once __DIR__ . '/../notif_template_helper.php';
include '../../koneksidb.php';

// Jeda aman antar pesan supaya nomor bot tidak dianggap spam oleh WhatsApp --
// pola sama persis dgn notif_remainder_pembayaran.php/non_aktif_tempo.php dkk.
function sleepAman($min = 4, $max = 6)
{
  $delayMs = rand($min * 1000, $max * 1000);
  usleep($delayMs * 1000);
}

echo "Hari ini: ";
echo $currentDate = date('Y-m-d');
echo "<br>";

// Menghitung tanggal hari kemarin
$kemarin = date('Y-m-d', strtotime('-1 day'));
echo "Cek yang kemarin pasang: ";
echo $kemarin;
echo "<br>";

// Batas "kemarin lusa" dihitung di bawah, setelah $pemilik & waktu tunggu
// prabayar (grace period) dari Payment Setting diketahui -- lihat bawah.



function getMonthName($month, $year)
{
    $months = ["Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"];
    if ($month > 12) {
        $month = 1;
        $year++;
    }
    return $months[$month - 1] . ' ' . $year;
}

                    // getFreeradiusPID()/restartFreeradius() lokal yang dulu ada di sini
                    // sudah dihapus (tidak dipakai lagi) -- restart FreeRADIUS sekarang
                    // ditangani radiusReloadIfChanged() di radius_sync_lib.php, dipanggil
                    // lewat radiusSyncSingleCustomerNow() di bagian isolir RADIUS di bawah.
////////////////////////////////////////


$filename = basename(__FILE__); // contoh: hapus_kode_permintaan_bayar_FIBERQ.php
$nameOnly = pathinfo($filename, PATHINFO_FILENAME); // hapus_kode_permintaan_bayar_FIBERQ

$parts = explode('_', $nameOnly);
$pemilik = end($parts); // ambil bagian terakhir

echo "Bagian terakhir dari nama file: $pemilik <br>";

// Waktu tunggu prabayar (grace period) dari Payment Setting -- SAMA seperti
// yang dipakai cek_tagihan_harian.php, supaya batas isolir pelanggan baru di
// sini tidak lagi hardcode 3 hari, tapi ikut setting yang admin atur.
$prabayar_grace_period = 2;
$grace_period_file = "../data/prabayar_grace_period-$pemilik.json";
if (file_exists($grace_period_file)) {
    $grace_period_data = json_decode(file_get_contents($grace_period_file), true);
    if (is_array($grace_period_data) && isset($grace_period_data['prabayar_grace_period'])) {
        $prabayar_grace_period = (int)$grace_period_data['prabayar_grace_period'];
    }
}
$kemarinlusa = date('Y-m-d', strtotime("-{$prabayar_grace_period} days"));
echo "Cek yang pasang $prabayar_grace_period hari lalu (waktu tunggu prabayar): ";
echo $kemarinlusa;
echo "<br>";


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

$sql1 = "SELECT * FROM `botwa` WHERE `namebot` = '$botname'";
$query1 = mysqli_query($conn, $sql1);

// Nomor urut
$nomor = 1;

while ($data1 = mysqli_fetch_array($query1)) {
  $waapi = $data1['addressbot'];
  $botpass = $data1['password'];
  $sender = $data1['sender'] ?? '';
}
/////////////////////////////////////////////////////////////////////////////////






 $filePath = notifTemplateFilePath($pemilik);
   // Fungsi pengganti variabel
          function replaceVariables($text)
          {
            return preg_replace_callback('/\$(\w+)/', function ($matches) {
              return isset($GLOBALS[$matches[1]]) ? $GLOBALS[$matches[1]] : $matches[0];
            }, $text);
          }
        










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


////////////////////////////////////////////////////////////////////


include "../phpmailer/classes/class.phpmailer.php";



////////////////////////////////////////////////////////////////////

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
  echo   $PASSWORD = $data['PASSWORD'];
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
  ///////////BUKA ALL DATA CLIENT///////////
  echo    $sql1 = "SELECT * from `pelanggan` WHERE `PEMILIK` = '$PEMILIK' and `AREA`='$AREA' and `TANGGALPASANG` like '%$kemarinlusa%'";
  $query1 = mysqli_query($conn, $sql1);
  while ($data1 = mysqli_fetch_array($query1)) {
    echo "<br>";
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
    echo $AUTHMODE = $data1['MODE']; //SEBAGAI AUTH MODE (dulu salah baca kolom HARGA, jadi RADIUS/MULTI MODE tidak pernah lolos cek $valid_modes dan selalu fallback ke API MODE)

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

   if (strtolower(trim((string)$TIPE_TEMPO)) === 'monthversary') {
    // Pelanggan mode "monthversary" sudah ditangani sepenuhnya (termasuk
    // waktu tunggu prabayar yang sesungguhnya) oleh cek_tagihan_harian.php,
    // jadi dilewati di sini supaya tidak diisolir dua kali dengan jeda "-3 hari" yang berbeda.
    echo "Lewati $IDPEL: mode tempo monthversary ditangani oleh cek_tagihan_harian.php<br>";
   } elseif (strtolower(trim((string)$TIPE_TEMPO)) === 'mengikuti_tanggal_bayar') {
    // Pelanggan mode Rolling: cek prabayar di bawah pakai pencocokan periode
    // tutup-buku (bukan formula 30 hari), beda dgn tagihan_status_lib.php --
    // dilewati di sini juga supaya tidak diisolir dua kali dgn hasil berbeda.
    echo "Lewati $IDPEL: mode tempo mengikuti_tanggal_bayar ditangani oleh cek_tagihan_harian.php<br>";
   } elseif($TIPE_BAYAR=="prabayar"){
    if (stripos($PAKET, 'FREE') === false) {

  $currentDate = date('d-m-Y');
$currentDay =  date('d');
$currentMonth = date('m');
$currentYear = date('Y');

 if ($currentDay >= 6 && $currentDay <= 15) {
      
        $periode = getMonthName($currentMonth, $currentYear);
    } elseif ($currentDay >= 16 && $currentDay <= 24) {
       
        $periode = getMonthName($currentMonth + 1, $currentYear);
    } elseif ($currentDay >= 25) {
      
        $periode = getMonthName($currentMonth + 1, $currentYear);
    } else {
       
        $periode = getMonthName($currentMonth, $currentYear);
    }



      
      //////////////////////BUKA BUKU TRANSAKSI CEK SUDAH BAYAR ATAU BELUM/////////////////////////////////
      echo  $sql2 = "SELECT * from `transaksi` WHERE `PEMILIK` = '$PEMILIK' and `STATUS` = 'BERHASIL' and `IDPEL`='$IDPEL' and `PENGUNAAN` LIKE '%$periode%' ";
      $query2 = mysqli_query($conn, $sql2);
      $cek = mysqli_num_rows($query2);
      if ($cek == 0) {


        $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Checked payment for new customer $IDPEL ($NAMA)";
        // Simpan ke file history
        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

        echo "<br>";
        echo "DIMATIKAN";

        // $text = "[INI ADALAH PESAN OTOMATIS]\n*INTERNET ANDA TERISOLIR / EXPIRED*\n\nHai Bpk/Ibu $NAMA \nInternet anda Telah Terisolir tagihan awal.\n\nDengan detail :\n- Periode pengunaan : $periode\n- ID Pelanggan : $IDPEL \n- Nama Pelanggan : $NAMA \n- Paket langganan : $PAKET \n- No Whatsapp : $NOWA \n- E-mail : $EMAIL  \n- Alamat : $ALAMAT\n- Invoice : https://quenbytekniksejahtera.com/crm/billing/broadband/portal.php?cari=$IDPEL\n\n\nLink pembayaran :\nhttps://quenbytekniksejahtera.com/crm/billing/broadband/portal.php?cari=$IDPEL\n*JIKA LINK TIDAK DAPAT DIKLIK silahkan simpan kontak whatsapp ini terlebih dahulu atau copy link dan paste di browser*\n\nYOUTUBE TUTORIAL CARA BAYAR : https://youtu.be/9Gvu4C2AkW4?si=1qMH1oKTRh0lB5EM  \n\n\nDemikian yang dapat kami sampaikan terima kasih\nTerima kasih telah mempercayai kami dalam kebutuhan internet anda\nsalam $PEMILIK ";


       

{
          // Baca file (auto-dibuat dgn template default kalau belum ada -- lihat
          // notif_template_helper.php) dan ambil bagian EXPIRED.
          $isi = notifTemplateGetContent($pemilik);
          $expired_raw = notifTemplateExtractSection($isi, 'EXPIRED');

          // Ganti variabel di dalam teks
          $expired_parsed = replaceVariables($expired_raw);
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

        // Jeda aman -- loop ini kirim per-pelanggan, sebelumnya tidak ada
        // jeda sama sekali di sini.
        sleepAman();

        if ($response != "") {
          $json = json_decode($response, true);
          if ($json['code'] == 'SUCCESS') {
            echo "OTP terkirim";
          } else {
            echo "OTP gagal terkirim";
          }
        }





        // Parsing response JSON
        $response_data = json_decode($response, true); // Konversi ke array

        // PENTING: sebelumnya cek "berhasil" cuma berdasar bisa/tidaknya response
        // di-decode JSON -- padahal response gagal (HTTP 4xx/5xx dari gateway WA)
        // biasanya TETAP berupa JSON valid, jadi selalu lolos sebagai "berhasil"
        // tanpa pernah cek $httpCode / curl_error() yang sebenarnya.
        $waOk = ($curlErr === '' && $httpCode >= 200 && $httpCode < 300);
        if ($waOk) {
          $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Notif isolir pelanggan baru dikirim ke $NOWA | HTTP: $httpCode";
        } else {
          $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] GAGAL kirim notif isolir pelanggan baru ke $NOWA | HTTP: $httpCode | cURL error: $curlErr | Response: " . substr((string)$response, 0, 200);
        }

        // Simpan ke file history
        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));





        ////////KIRIM NOTIF EMAIL///////////////

        $mail = new PHPMailer;
        $mail->IsSMTP();
        $mail->SMTPSecure = '';
        $mail->Host = "quenbytekniksejahtera.com"; //host masing2 provider email
        $mail->SMTPDebug = 2;
        $mail->Port = 25;
        $mail->SMTPAuth = false;
        $mail->Username = "helpdeskqts@quenbytekniksejahtera.com"; //user email
        $mail->Password = "helpdeskqts"; //password email 
        $mail->SetFrom("helpdesk@quenbytekniksejahtera.com", "FIBERQ"); //set email pengirim
        $mail->Subject = "Wifi anda terisolir tagihan awal"; //subyek email
        $mail->AddAddress($EMAIL, "$NAMA - $IDPEL ");  //tujuan email
        $mail->MsgHTML("Yth. Bapak / Ibu  $NAMA<br>
                        Internet anda Terisolir tagihan awal.<br>
                        <br>                                                        
                        Nama : $NAMA <br>
                        ID pelanggan : $IDPEL <br>
                        Paket Langganan : $PAKET <br>
                        Harga per bulan : $harga <br>
                        Alamat : $ALAMAT <br>
                        No WHATSAPP : $NOWA <br>
                        E Mail : $EMAIL <br>
                        Tanggal aktif : $TANGGALPASANG <br>
                        Invoice : https://quenbytekniksejahtera.com/crm/billing/broadband/portal.php?cari=$IDPEL<br>
                        <br>           
                        -Link pembayaran :\nhttps://quenbytekniksejahtera.com/crm/billing/broadband/portal.php?cari=$IDPEL<br>
                        <br>
                        Demikian yang dapat kami sampaikan terima kasih<br>
                        Terima kasih telah mempercayai kami dalam kebutuhan internet anda<br>
                        <br>
                         <br>
                        Informasi lanjut, hubungi Customer Service FIBERQ WIFI di  +62 877-4031-7266 , email: 
                        helpdesk@quenbytekniksejahtera.com, whatsapp +62 877-4031-7266. <br>
                        <br>
                        salam FIBERQ ");
        if ($mail->Send()) echo "Message has been sent";
        else echo "Failed to sending message";








if($AUTHMODE=='API MODE' | $AUTHMODE=='MULTI MODE'  )
{


        //  ////////koneksi ke mikrotik ///////

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
            "comment"  => "EXPIRED $NAMA - $NOWA - $TANGGALPASANG  ( CLIENT BARU )",
            "profile"  => "EXPIRED"
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

}


if($AUTHMODE=='RADIUS MODE' | $AUTHMODE=='MULTI MODE'  )
{
    $idpel = $IDPEL;
    // PENTING: pakai $data1['PASSWORD'] (password PPPoE pelanggan), BUKAN
    // variabel $PASSWORD -- di scope ini $PASSWORD sudah ke-shadow oleh
    // password login API router dari $data['PASSWORD'] (loop server di atas),
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
        // secret). Ini juga menjaga jaring pengaman fallback MULTI MODE:
        // kalau secret lokal Mikrotik hilang, RADIUS tetap ada untuk
        // fallback otomatis RouterOS.
        radiusSyncSingleCustomerNow($idpel, $pppoe_password, $paketRow, false, radiusGetGlobalSettings($conn));

        echo "✅ User '$idpel' diisolir di FreeRADIUS (grup/address-list EXPIRED, entry tetap ada).";
    }

    // Timer voucher hotspot (kalau ada) tetap dibersihkan seperti sebelumnya.
    $timer_file = "/etc/freeradius/user_timers/{$idpel}.json";
    if (file_exists($timer_file)) unlink($timer_file);
}





        $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] New customer $IDPEL ($NAMA) deactivated";
        // Simpan ke file history
        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
      } else {
        echo "<br>";
        echo "SUDAH BAYAR PERIODE " . $periode;
      }
      echo "<HR>";
    }
  }
  }
}
