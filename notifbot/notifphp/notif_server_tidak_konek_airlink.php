<?php
require('../../routeros_api.class.php');
include '../../koneksidb.php';
$hariinitgl = date('d');
$currentDate = date('Y-m-d');

////////////////////////////////////////

$filename = basename(__FILE__); // contoh: notif_server_tidak_konek_FIBERQ.php
$nameOnly = pathinfo($filename, PATHINFO_FILENAME); // notif_server_tidak_konek_FIBERQ

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
  }
} else {
  echo "Error: File JSON tidak ditemukan.";
}

// Override bot dari pengaturan kategori notifikasi server (jika ada)
// Include helper function untuk pemilihan bot (termasuk dukungan RANDOM)
require_once('../bot_selector_helper.php');

$selectedBotSystem = '';
$botCategoryFile = "../data/bot_receiver_config-$pemilik.json";
if (file_exists($botCategoryFile)) {
  $botCategoryData = json_decode(file_get_contents($botCategoryFile), true);
  if (is_array($botCategoryData) && !empty($botCategoryData['server'])) {
    $selectedBotSystem = trim((string)$botCategoryData['server']);
  }
}

/////////////////////////////////////////////////////////////////////////////////

// Gunakan helper function untuk pemilihan bot (mendukung RANDOM)
$bot_result = selectBotForNotificationWithField($conn, $pemilik, $selectedBotSystem, 'penerima_server');

if ($bot_result['success']) {
  $waapi = $bot_result['addressbot'];
  $botpass = $bot_result['password'];
  $botname = $bot_result['namebot'];
  $penerima = $bot_result['penerima'];
  $sender = $bot_result['sender'] ?? '';

  if ($bot_result['is_random']) {
    echo "[RANDOM BOT] Bot dipilih secara acak: $botname untuk menghindari spam (Server Notif)<br>";
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







/////////////////////////////////////////////////////////////////////////////////

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
    $tahun -= 1;
  } elseif ($bulan_index > 12) {
    $bulan_index -= 12;
    $tahun += 1;
  }

  $tgl_indo = "{$bulan[$bulan_index]} $tahun";

  if ($cetak_hari) {
    $tgl_indo = "$tgl_indo";
  }

  return $tgl_indo;
}

$ptanggalskg = tanggal_indo($cektanggal, true);
echo $ptanggalskg . "<br>";
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

$server_tidak_konek = [];

while ($data = mysqli_fetch_array($query)) {
  $AREA = $data['AREA'];
  $PEMILIK = $data['PEMILIK'];
  $IP = $data['IP'];
  $PASSWORD = $data['PASSWORD'];

  $API = new RouterosAPI();

  if (!$API->connect($IP, $PEMILIK, $PASSWORD)) {
    // Server tidak konek
    $server_tidak_konek[] = [
      'AREA' => $AREA,
      'PEMILIK' => $PEMILIK,
      'IP' => $IP
    ];
    echo "Server $AREA $PEMILIK ($IP) TIDAK KONEK<br>";
  } else {
    echo "Server $AREA $PEMILIK ($IP) KONEK<br>";
    $API->disconnect();
  }
}

if (!empty($server_tidak_konek)) {
  $jam = date('H:i:s');

  $text = "⚠️ *[ALERT SYSTEM BOT APP QTS]* ⚠️\n";
  $text .= "=============================\n";
  $text .= "🚨 SERVER LOS TERDETEKSI BILLING SERVER 🚨\n";
  $text .= "=============================\n";
  $text .= "Tanggal: " . date('Y-m-d') . " Pukul $jam\n";
  $text .= "Status: KRITIS - Perlu Tindakan Segera\n\n";
  $text .= "📋 Daftar Server Bermasalah:\n";

  foreach ($server_tidak_konek as $index => $srv) {
    $no = $index + 1;
    $text .= "$no. AREA: {$srv['AREA']}\n";
    $text .= "   SERVER: {$srv['PEMILIK']}\n";
    $text .= "   IP ADDRESS: {$srv['IP']}\n";
    $text .= "   STATUS: ❌ TIDAK TERHUBUNG\n";
    $text .= "   -----------------------------\n";
  }

  $text .= "\n🔍 Kemungkinan Penyebab:\n";
  $text .= "- Koneksi jaringan terputus\n";
  $text .= "- Server RouterOS mati atau restart\n";
  $text .= "- Firewall atau konfigurasi API salah\n";
  $text .= "- Gangguan listrik atau hardware\n\n";

  $text .= "🚀 Instruksi untuk Tim IT:\n";
  $text .= "1. Segera periksa koneksi jaringan ke IP server\n";
  $text .= "2. Restart layanan RouterOS jika diperlukan\n";
  $text .= "3. Verifikasi kredensial login (username/password)\n";
  $text .= "4. Pastikan API RouterOS aktif dan dapat diakses\n";
  $text .= "5. Laporkan hasil pengecekan ke admin sistem\n\n";

 
  $text .= "=============================\n";
  $text .= "⚡ Sistem Billing Terpengaruh - Segera Tangani! ⚡\n";

 
  // Tambahkan salam pembuka dinamis untuk menghindari spam detection
  $text = prependDynamicGreeting($text);
  
  $phone = $penerima; // Grup atau nomor tujuan

  $data = [
    "phone" => $phone,
    "message" => $text
  ];

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
  $url = "$currentWAAPI/send/message?session=" . urlencode($currentBotName);
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
  curl_setopt($ch, CURLOPT_USERPWD, "$currentBotName:$currentBotPass");

  $response = curl_exec($ch);
  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $curlErr = curl_error($ch);
  curl_close($ch);

  // Increment target counter untuk distribusi ke bot berikutnya
  if ($isRandomBot && $botPoolCount > 0) {
      $targetIndex++;
  }

  // Sebelumnya log "dikirim" selalu ditulis walau curl gagal/HTTP error, jadi
  // admin tidak pernah tahu kalau notif server-down ini sendiri gagal terkirim.
  $waOk = ($curlErr === '' && $httpCode >= 200 && $httpCode < 300);
  if ($waOk) {
    echo "Notifikasi dikirim untuk server yang tidak konek.<br>";
    $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Notif server tidak konek dikirim ke owner | Bot: $currentBotName | HTTP: $httpCode";
  } else {
    echo "GAGAL mengirim notifikasi server tidak konek. HTTP: $httpCode, cURL error: $curlErr<br>";
    $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] GAGAL kirim notif server tidak konek | Bot: $currentBotName | HTTP: $httpCode | cURL error: $curlErr | Response: " . substr((string)$response, 0, 200);
  }
  file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
} else {
  echo "Semua server konek.<br>";
  $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Semua server konek, tidak ada notif";
  file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
}
?>