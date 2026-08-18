<?php
// Script background untuk mengirim broadcast WhatsApp
require '../header.php';

// Baca data POST dari argument command line (file temporary)
if ($argc < 2) {
    die('Invalid background call');
}

$temp_file = $argv[1];
if (!file_exists($temp_file)) {
    die('Temporary file not found');
}

$post_data = unserialize(file_get_contents($temp_file));
unlink($temp_file); // Hapus file temporary setelah dibaca

// Extract POST data
$pesan = trim($post_data['pesan_broadcast'] ?? '');
$bot = trim($post_data['bot_broadcast'] ?? '');
$filter_bulan = trim($post_data['filter_bulan'] ?? '');
$filter_tahun = trim($post_data['filter_tahun'] ?? '');

if (empty($pesan) || empty($bot)) {
    die('Pesan dan bot harus diisi');
}

// Ambil data bot dari database
$query = "SELECT addressbot, password, sender FROM botwa WHERE namebot = ? AND pemilik = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param('ss', $bot, $ceknama);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die('Bot tidak ditemukan');
}

$row = $result->fetch_assoc();
$waapi = $row['addressbot'];
$botpass = $row['password'];
$sender = $row['sender'] ?? '';

$stmt->close();

// device_id gowa multi-device (build gowa terbaru wajib X-Device-Id header /
// device_id query di /send/message) = isi kolom sender apa adanya (nama
// device di server gowa, mis. "hanif").
$deviceId = trim((string)$sender);

// Ambil daftar nowa pelanggan berhenti -- scope ke pemilik server milik user
// yang login, dan kalau ASSISTANT dibatasi lagi ke AREA yang di-assign (pola
// sama seperti daftar_pelanggan_berhenti.php).
$userServerIds = [];
if ($AKSES === 'ASSISTANT') {
    if (isset($area_list) && trim((string)$area_list) !== '') {
        $queryServerId = mysqli_query($conn, "SELECT PEMILIK FROM server WHERE AREA IN ($area_list)");
        while ($row = mysqli_fetch_assoc($queryServerId)) {
            $userServerIds[] = "'".$row['PEMILIK']."'";
        }
    }
} else {
    $queryServerId = mysqli_query($conn, "SELECT PEMILIK FROM server WHERE user_id = $current_user_id");
    while ($row = mysqli_fetch_assoc($queryServerId)) {
        $userServerIds[] = "'".$row['PEMILIK']."'";
    }
}
$userServerList = count($userServerIds) > 0 ? implode(",", $userServerIds) : "''";

$where = [];
if ($filter_bulan !== 'all') {
  $where[] = "MONTH(tanggal_berhenti) = '" . intval($filter_bulan) . "'";
}
if ($filter_tahun !== 'all') {
  $where[] = "YEAR(tanggal_berhenti) = '" . intval($filter_tahun) . "'";
}
$where[] = "pemilik IN ($userServerList)";
$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "SELECT idpel, nama, paket, harga, alamat, nowa, alasan, tanggal_berhenti, keterangan, pemilik FROM pelanggan_berhenti $where_sql AND nowa IS NOT NULL AND nowa != ''";

$result = mysqli_query($conn, $sql);

$numbers = [];
$customer_data = [];
while ($row = mysqli_fetch_assoc($result)) {
    $nowa = trim($row['nowa']);
    if (!empty($nowa)) {
        $numbers[] = $nowa;
        $customer_data[$nowa] = $row;
    }
}

$numbers = array_unique($numbers);

// Fungsi delay aman (4–6 detik acak)
function sleepAman($min = 4, $max = 6)
{
	$delay = rand($min * 1000, $max * 1000);
	usleep($delay * 1000);
}

// Kirim broadcast
$success_count = 0;
$fail_count = 0;
$success_list = [];
$fail_list = [];

foreach ($numbers as $no) {
    $customer = $customer_data[$no];

    // Buat pesan personalized dengan data pelanggan
    $personalized_message = $pesan . "\n\n";
    $personalized_message .= "Data Pelanggan:\n";
    $personalized_message .= "IDPEL: {$customer['idpel']}\n";
    $personalized_message .= "Nama: {$customer['nama']}\n";
    $personalized_message .= "Paket: {$customer['paket']}\n";
    $personalized_message .= "Server: {$customer['pemilik']}\n";
    $personalized_message .= "Alamat: {$customer['alamat']}\n";
    $personalized_message .= "Alasan Berhenti: {$customer['alasan']}\n";
    $personalized_message .= "Tanggal Berhenti: {$customer['tanggal_berhenti']}\n";
    if (!empty($customer['keterangan'])) {
        $personalized_message .= "Keterangan: {$customer['keterangan']}\n";
    }

    // Format nomor
    $phone = "$no@s.whatsapp.net";

    // Data JSON
    $data = [
        "phone" => $phone,
        "message" => $personalized_message
    ];

    // Inisialisasi cURL
    $url = "$waapi/send/message?session=$bot";
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

    // Tambahkan Basic Auth
    curl_setopt($ch, CURLOPT_USERPWD, "$bot:$botpass");

    // Eksekusi dan tangani respons
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response != "") {
      $json = json_decode($response, true);
      if ($json['code'] == 'SUCCESS') {
        $success_count++;
        $success_list[] = $customer['nama'] . " ({$no})";
      } else {
        $fail_count++;
        $fail_list[] = $customer['nama'] . " ({$no}) - " . ($json['message'] ?? 'Unknown error');
      }
    } else {
      $fail_count++;
      $fail_list[] = $customer['nama'] . " ({$no}) - No response";
    }

    // Delay
    sleepAman(4, 6);
}

// Simpan hasil ke file log
$log_file = __DIR__ . '/broadcast_log_' . date('Y-m-d_H-i-s') . '.txt';
$log_content = "Broadcast selesai pada " . date('Y-m-d H:i:s') . "\n";
$log_content .= "Filter: Bulan=$filter_bulan, Tahun=$filter_tahun\n";
$log_content .= "Total nomor: " . count($numbers) . "\n";
$log_content .= "Berhasil: $success_count\n";
$log_content .= "Gagal: $fail_count\n\n";

if (!empty($success_list)) {
    $log_content .= "Berhasil dikirim ke:\n" . implode("\n", $success_list) . "\n\n";
}

if (!empty($fail_list)) {
    $log_content .= "Gagal dikirim ke:\n" . implode("\n", $fail_list) . "\n\n";
}

file_put_contents($log_file, $log_content);

// Simpan status completion
$status_file = __DIR__ . '/broadcast_status_' . session_id() . '.json';
$status_data = [
    'completed' => true,
    'timestamp' => time(),
    'total_numbers' => count($numbers),
    'success_count' => $success_count,
    'fail_count' => $fail_count,
    'filter_bulan' => $filter_bulan,
    'filter_tahun' => $filter_tahun,
    'log_file' => basename($log_file)
];
file_put_contents($status_file, json_encode($status_data));

// Kirim notifikasi ke admin via email atau webhook jika diperlukan
// (opsional - bisa ditambahkan nanti)
?>