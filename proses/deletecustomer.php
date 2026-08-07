<?php
require '../cek-sesi.php';
require '../routeros_api.class.php';

/////////target delete data dari info sebelum form

$hapusid = $_GET['hapusid'];
echo $idpel = $_GET['idpel'];
echo $nowa = $_GET['nowa'];
echo $nama = $_GET['nama'];
$area = $_GET['area'];
$server = $_GET['server'];

// ================= Catat log awal proses =================
$history_file = "../notifbot/data/history-$ceknama.json";
$history = [];
if (file_exists($history_file)) {
    $history = json_decode(file_get_contents($history_file), true);
}
if (!is_array($history)) {
    $history = [];
}

// ========================================================



// ================= Fungsi =================
function getFreeradiusPID() {
    $pid = trim(shell_exec("pidof freeradius"));
    if ($pid != '') return (int)$pid;
    $output = shell_exec("systemctl show -p MainPID freeradius");
    if (preg_match('/MainPID=(\d+)/', trim($output), $m)) {
        return (int)$m[1];
    }
    return 0;
}

function restartFreeradius() {
    global $debug_file;
    $debug_file = "/var/log/freeradius/debug-radius-web.log"; // sesuaikan path log

    $pid = getFreeradiusPID();
    if ($pid > 0) {
        shell_exec('sudo systemctl stop freeradius');
        shell_exec("sudo kill -9 $pid");
    }

    if (file_exists($debug_file)) shell_exec("sudo rm -f $debug_file");
    shell_exec("sudo touch $debug_file");
    shell_exec("sudo chmod 666 $debug_file");
    shell_exec("sudo freeradius -X > $debug_file 2>&1 &");
}










$tangalan_dismantle = date("d-m-Y");

    $pesan_dismantle_manual = '';
                               
                               
                                    $stmt = $conn->prepare("SELECT pesan_dismantle_manual FROM notif_khusus WHERE pemilik = ? LIMIT 1");
                                    $stmt->bind_param('s',$ceknama);
                                    $stmt->execute();
                                    $stmt->bind_result($pesan_dismantle_manual);
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



$waapi = $waapi;
$phone = "$nowa@s.whatsapp.net";

          // Ambil semua variabel terdefinisi di scope ini
          $vars = get_defined_vars();
          // Hapus variabel yang tidak perlu (opsional, agar tidak bocor variabel besar)
          unset($vars['isi'], $vars['match'], $vars['vars']);
$message = replace_vars($pesan_dismantle_manual, $vars);

$data = [
  "phone" => $phone,
  "message" => $message
];

$botname = $botname;
$passwordbot = $passwordbot;

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













// Ambil data pelanggan sebelum dihapus

// Ambil data pelanggan sebelum dihapus
$sql_get_pelanggan = "SELECT * FROM pelanggan WHERE id='" . mysqli_real_escape_string($conn, $hapusid) . "' LIMIT 1";
$result_pelanggan = mysqli_query($conn, $sql_get_pelanggan);
$insert_berhenti_ok = false;
if ($row_pelanggan = mysqli_fetch_assoc($result_pelanggan)) {
  // Siapkan data untuk disimpan ke tabel pelanggan_berhenti
  $idpel_berhenti = isset($row_pelanggan['IDPEL']) ? $row_pelanggan['IDPEL'] : '';
  $nama_berhenti = isset($row_pelanggan['NAMA']) ? $row_pelanggan['NAMA'] : '';
  $tempo_berhenti = isset($row_pelanggan['TEMPO']) ? $row_pelanggan['TEMPO'] : '';
  $harga_berhenti = isset($row_pelanggan['HARGA']) ? $row_pelanggan['HARGA'] : '';
  $pemilik_berhenti = isset($row_pelanggan['PEMILIK']) ? $row_pelanggan['PEMILIK'] : '';
  $alamat_berhenti = isset($row_pelanggan['ALAMAT']) ? $row_pelanggan['ALAMAT'] : '';
  $nowa_berhenti = isset($row_pelanggan['NOWA']) ? $row_pelanggan['NOWA'] : '';
  $paket_berhenti = isset($row_pelanggan['PAKET']) ? $row_pelanggan['PAKET'] : '';
  $alasan_berhenti = 'Dismantle';
  $tanggal_berhenti = date('Y-m-d');
  $keterangan_berhenti = 'Dismantle';

  // Gunakan prepared statement untuk memastikan insert benar dan mendapat error bila gagal
  $insert_sql = "INSERT INTO pelanggan_berhenti (`idpel`,`nama`,`tempo`,`harga`,`pemilik`,`alamat`,`nowa`,`paket`,`alasan`,`tanggal_berhenti`,`keterangan`) VALUES (?,?,?,?,?,?,?,?,?,?,?)";
  $stmt = mysqli_prepare($conn, $insert_sql);
  if ($stmt) {
    mysqli_stmt_bind_param($stmt, 'sssssssssss', $idpel_berhenti, $nama_berhenti, $tempo_berhenti, $harga_berhenti, $pemilik_berhenti, $alamat_berhenti, $nowa_berhenti, $paket_berhenti, $alasan_berhenti, $tanggal_berhenti, $keterangan_berhenti);
    $exec = mysqli_stmt_execute($stmt);
    if ($exec) {
      
      $insert_berhenti_ok = true;
    } else {
      $err = mysqli_stmt_error($stmt);
      error_log("[deletecustomer] Insert pelanggan_berhenti failed for idpel={$idpel_berhenti}: $err\n");
      $debug_file = __DIR__ . '/../logs/insert_berhenti.log';
      @file_put_contents($debug_file, date('Y-m-d H:i:s') . " - Insert failed for $idpel_berhenti: $err\n", FILE_APPEND);
      echo "<div style='color:red;font-weight:bold'>Gagal insert ke pelanggan_berhenti: $err</div>";
    }
    mysqli_stmt_close($stmt);
  } else {
    $err = mysqli_error($conn);
    error_log("[deletecustomer] Prepare failed for pelanggan_berhenti insert: $err\n");
    $debug_file = __DIR__ . '/../logs/insert_berhenti.log';
    @file_put_contents($debug_file, date('Y-m-d H:i:s') . " - Prepare failed: $err\n", FILE_APPEND);
    echo "<div style='color:red;font-weight:bold'>Gagal prepare statement pelanggan_berhenti: $err</div>";
  }
}

// Jika gagal insert ke pelanggan_berhenti, hentikan proses
if (!$insert_berhenti_ok) {
  // Catat log gagal
  $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Gagal menghapus pelanggan $idpel: Insert ke pelanggan_berhenti gagal";
  file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
  exit;
}

$sql2 = "DELETE FROM `pelanggan` WHERE `id`='$hapusid' ";
if ($conn->query($sql2) === TRUE) {




  $sql = "SELECT * FROM `server` WHERE `AREA` = '$area' and `PEMILIK`= '$server' ";
  $query = mysqli_query($conn, $sql);
  while ($data = mysqli_fetch_array($query)) {

    $user = $data['PEMILIK'];
    $ip = $data['IP'];
    $password = $data['PASSWORD'];

    $API2 = new RouterosAPI();
    $API2->connect($ip, $user, $password);
    $cariurutan2 = $API2->comm(
      "/ppp/secret/getall",
      array(
        ".proplist" => ".id",
        "?name" => $idpel,
      )
    );

    $API2->comm(
      "/ppp/secret/remove",
      array(
        ".id" => $cariurutan2[0][".id"],
      )
    );



    // Cari ID active connection
    $cariAktif = $API2->comm(
      "/ppp/active/getall",
      array(
        ".proplist" => ".id",
        "?name" => $idpel,
      )
    );

    // Hapus active connection
    if (!empty($cariAktif)) {
      $API2->comm(
        "/ppp/active/remove",
        array(
          ".id" => $cariAktif[0][".id"],
        )
      );
    }
  }

  ///////////////////catatat di log////////////////////

  // Log sudah dicatat di akhir proses

  ////////////////////////////////////////////////////






$users_file = "/etc/freeradius/3.0/users";
$timer_dir  = "/etc/freeradius/user_timers";
$timer_file = "$timer_dir/$idpel.json";

// ==================== BACA ISI FILE USERS ====================
$content = file_get_contents($users_file);
if ($content === false) {
    die("❌ Gagal membaca file users");
}

// ==================== HAPUS BLOK USER ====================
$pattern = "/^" . preg_quote($idpel, '/') . "\s+Cleartext-Password\s*:=.*?(?:\n[ \t]+\S.*?)*?(?=^\S|\Z)/ms";
$new_content = preg_replace($pattern, '', $content, -1, $count);

if ($count === 0) {
    // User tidak ditemukan, bisa diabaikan atau beri pesan
    // die("⚠️ User '$idpel' tidak ditemukan dalam file users.");
}

// ==================== SIMPAN PERUBAHAN ====================
$tmp = tempnam(sys_get_temp_dir(), 'usr');
file_put_contents($tmp, $new_content);
shell_exec("sudo tee " . escapeshellarg($users_file) . " < $tmp > /dev/null");
unlink($tmp);

// ==================== HAPUS FILE TIMER USER ====================
if (file_exists($timer_file)) {
    unlink($timer_file);
}

// ==================== RESTART FREERADIUS ====================
restartFreeradius();

echo "✅ User '$idpel' berhasil dihapus dan FreeRADIUS telah direstart.";

// Catat log berhasil
$history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Berhasil penghapusan pelanggan $idpel (Nama: $nama, Area: $area, Server: $server)";
file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

header("Location: ../tables.php?pesan=berhasil&text=Success Delete $idpel");







  header("Location: ../tables.php?pesan=berhasil&text=Success Delete $idpel ");
} else {
  // Catat log gagal
  $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Gagal menghapus pelanggan $idpel: " . $conn->error;
  file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
  echo "Error: " . $sql2 . "<br>" . $conn->error;
}
