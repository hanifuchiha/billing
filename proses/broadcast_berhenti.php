<?php
require '../cek-sesi.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die('Invalid request');
}

$pesan = trim($_POST['pesan_broadcast'] ?? '');
$bot = trim($_POST['bot_broadcast'] ?? '');

if (empty($pesan) || empty($bot)) {
    die('Pesan dan bot harus diisi');
}

// Ambil data bot dari database
// Assistant tanpa assign tak boleh pakai nama bot manipulasi punya bot lain
// (lihat notifbot/bot_access_helper.php).
$query = "SELECT addressbot, password, sender FROM botwa WHERE namebot = ? AND pemilik = ?" . botAccessWhereClause($conn, $AKSES, $assigned_bot_ids ?? [], $asistant_name ?? '');
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

// Ambil filter bulan & tahun dari POST (dari form), atau session sebagai fallback
$filter_bulan = isset($_POST['filter_bulan']) ? $_POST['filter_bulan'] : (isset($_SESSION['filter_bulan']) ? $_SESSION['filter_bulan'] : date('m'));
$filter_tahun = isset($_POST['filter_tahun']) ? $_POST['filter_tahun'] : (isset($_SESSION['filter_tahun']) ? $_SESSION['filter_tahun'] : date('Y'));
$filter_area = isset($_POST['filter_area']) ? trim((string)$_POST['filter_area']) : (isset($_SESSION['filter_area']) ? (string)$_SESSION['filter_area'] : 'all');
if ($filter_area === '') {
    $filter_area = 'all';
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <title>Proses Broadcast Pelanggan Berhenti</title>
</head>
<body>

<main>

<div class="container-fluid py-4">
  <div class="row">
    <div class="col-12">
      <div class="card shadow">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
          <h4 class="mb-0"><i class="fas fa-bullhorn me-2"></i>Proses Broadcast Pelanggan Berhenti</h4>
          <a href="../daftar_pelanggan_berhenti.php?bulan=<?php echo $filter_bulan; ?>&tahun=<?php echo $filter_tahun; ?>" class="btn btn-light btn-sm">
            <i class="fas fa-arrow-left me-1"></i>Kembali
          </a>
        </div>
        <div class="card-body">

          <!-- Info Filter -->
          <div class="alert alert-info">
            <h6><i class="fas fa-filter me-2"></i>Filter yang Digunakan:</h6>
            <p class="mb-0">Bulan: <strong><?php echo $filter_bulan; ?></strong> | Tahun: <strong><?php echo $filter_tahun; ?></strong> | Area: <strong><?php echo $filter_area === 'all' ? 'Semua Area' : htmlspecialchars($filter_area); ?></strong></p>
          </div>

          <!-- Debug Info -->
          <div class="row mb-4">
            <div class="col-md-6">
              <div class="card border-warning">
                <div class="card-header bg-warning text-dark">
                  <h6 class="mb-0"><i class="fas fa-database me-2"></i>Query Database</h6>
                </div>
                <div class="card-body">
                  <code style="font-size: 12px; word-break: break-all;">
                    <?php
                    // Ambil daftar nowa pelanggan berhenti -- scope ke pemilik server milik
                    // user yang login, dan kalau ASSISTANT dibatasi lagi ke AREA yang
                    // di-assign (pola sama seperti daftar_pelanggan_berhenti.php). Filter
                    // Area dari dropdown (kalau dipilih) diterapkan sama persis di sini,
                    // supaya penerima broadcast konsisten dengan yang tampil di daftar.
                    $areaScopeSqlBerhentiBroadcast = ($AKSES === 'ASSISTANT' && isset($area_list) && trim((string)$area_list) !== '')
                        ? "AREA IN ($area_list)"
                        : "user_id = " . (int)$current_user_id;
                    if ($filter_area !== 'all') {
                        $areaScopeSqlBerhentiBroadcast .= " AND AREA = '" . mysqli_real_escape_string($conn, $filter_area) . "'";
                    }
                    $userServerIds = [];
                    $queryServerId = mysqli_query($conn, "SELECT DISTINCT PEMILIK FROM server WHERE $areaScopeSqlBerhentiBroadcast");
                    if ($queryServerId) {
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
                    echo htmlspecialchars($sql);
                    ?>
                  </code>
                </div>
              </div>
            </div>
            <div class="col-md-6">
              <div class="card border-success">
                <div class="card-header bg-success text-white">
                  <h6 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Statistik</h6>
                </div>
                <div class="card-body">
                  <?php
                  // Debug: hitung total record yang match filter (seperti di tabel utama)
                  $sql_total = "SELECT COUNT(*) as total FROM pelanggan_berhenti $where_sql";
                  $result_total = mysqli_query($conn, $sql_total);
                  $row_total = mysqli_fetch_assoc($result_total);
                  $total_records = $row_total['total'];

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
                  ?>
                  <div class="row text-center">
                    <div class="col-6">
                      <div class="border rounded p-2">
                        <h4 class="text-primary"><?php echo $total_records; ?></h4>
                        <small class="text-muted">Total Record</small>
                      </div>
                    </div>
                    <div class="col-6">
                      <div class="border rounded p-2">
                        <h4 class="text-success"><?php echo count($numbers); ?></h4>
                        <small class="text-muted">Dengan No. WA</small>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Tabel Data Pelanggan -->
          <div class="card border-primary mb-4">
            <div class="card-header bg-primary text-white">
              <h6 class="mb-0"><i class="fas fa-users me-2"></i>Data Pelanggan yang Akan Dikirim Broadcast</h6>
            </div>
            <div class="card-body">
              <div class="table-responsive">
                <table class="table table-striped table-hover">
                  <thead class="table-dark">
                    <tr>
                      <th>No. WA</th>
                      <th>IDPEL</th>
                      <th>Nama</th>
                      <th>Paket</th>
                      <th>Server</th>
                      <th>Alamat</th>
                      <th>Alasan</th>
                      <th>Tgl Berhenti</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($numbers as $no): ?>
                    <?php $data = $customer_data[$no]; ?>
                    <tr>
                      <td><strong><?php echo htmlspecialchars($no); ?></strong></td>
                      <td><?php echo htmlspecialchars($data['idpel']); ?></td>
                      <td><?php echo htmlspecialchars($data['nama']); ?></td>
                      <td><?php echo htmlspecialchars($data['paket']); ?></td>
                      <td><?php echo htmlspecialchars($data['pemilik']); ?></td>
                      <td><?php echo htmlspecialchars($data['alamat']); ?></td>
                      <td><?php echo htmlspecialchars($data['alasan']); ?></td>
                      <td><?php echo htmlspecialchars($data['tanggal_berhenti']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <!-- Progress Broadcast -->
          <div class="card border-info">
            <div class="card-header bg-info text-white">
              <h6 class="mb-0"><i class="fas fa-play-circle me-2"></i>Proses Broadcast Sedang Berjalan...</h6>
            </div>
            <div class="card-body">
              <div class="progress mb-3">
                <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%" id="progressBar"></div>
              </div>
              <div id="broadcastResults" class="mt-3">
                <!-- Results will be displayed here -->
              </div>
            </div>
          </div>

        </div>
      </div>
    </div>
  </div>
</div>

<script>
// Progress bar animation
let progress = 0;
const progressBar = document.getElementById('progressBar');
const resultsDiv = document.getElementById('broadcastResults');

function updateProgress(current, total) {
    progress = (current / total) * 100;
    progressBar.style.width = progress + '%';
    progressBar.textContent = Math.round(progress) + '%';
}
</script>

<?php
// Kode untuk pengiriman broadcast
// Fungsi delay aman (4–6 detik acak)
function sleepAman($min = 4, $max = 6)
{
	$delay = rand($min * 1000, $max * 1000);
	usleep($delay * 1000);
}

// File log untuk broadcast
$log_file = __DIR__ . '/../logs/broadcast_berhenti.log';

// History logging
$history_file = "../notifbot/data/history-$ceknama.json";
$history = [];
if (file_exists($history_file)) {
    $history = json_decode(file_get_contents($history_file), true);
}
if (!is_array($history)) { $history = []; }

// Kirim broadcast
$success_count = 0;
$fail_count = 0;
$success_list = [];
$fail_list = [];
$total_numbers = count($numbers);

echo "<script>updateProgress(0, $total_numbers);</script>";
flush(); // Send output to browser immediately

foreach ($numbers as $index => $no) {
    $customer = $customer_data[$no];
    
    // Buat pesan personalized dengan data pelanggan
    $personalized_message = "🌟 *SELAMAT DATANG KEMBALI!* 🌟\n\n";
    $personalized_message .= "Halo {$customer['nama']},\n\n";
    $personalized_message .= "Kami merindukan Anda! 🚀\n\n";
    $personalized_message .= "*$pesan*\n\n";
    $personalized_message .= "✨ *KEUNTUNGAN BERLANGGANAN KEMBALI:*\n";
    $personalized_message .= "• Layanan internet cepat dan stabil\n";
    $personalized_message .= "• Paket khusus untuk pelanggan lama\n";
    $personalized_message .= "• Diskon hingga 20%\n";
    $personalized_message .= "• Instalasi gratis\n";
    $personalized_message .= "• Support 24/7\n\n";
    $personalized_message .= "📋 *DATA PELANGGAN ANDA:*\n";
    $personalized_message .= "IDPEL: {$customer['idpel']}\n";
    $personalized_message .= "Nama: {$customer['nama']}\n";
    $personalized_message .= "Paket Terakhir: {$customer['paket']}\n";
    $personalized_message .= "Alamat: {$customer['alamat']}\n";
    $personalized_message .= "Tanggal Berhenti: {$customer['tanggal_berhenti']}\n\n";
    $personalized_message .= "💬 *HUBUNGI KAMI SEKARANG*\n";
    $personalized_message .= "Untuk informasi lebih lanjut atau untuk berlangganan kembali:\n";
    $personalized_message .= "#BerlanggananKembali #InternetCepat #PelangganLama #DiskonSpesial";
    
    // Format nomor
    $phone = "$no@s.whatsapp.net";

    // Data JSON
    $data = [
        "phone" => $phone,
        "message" => $personalized_message
    ];

    // Inisialisasi cURL
    $url = "$waapi/send/message?session=$bot"; // Endpoint dengan parameter sesi
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

    $log_entry = date('Y-m-d H:i:s') . " - Broadcast to {$customer['nama']} ({$no})";

    // Update progress and show result
    $current_index = $index + 1;
    if ($response != "") {
      $json = json_decode($response, true);
      if ($json['code'] == 'SUCCESS') {
        $success_count++;
        $success_list[] = $customer['nama'] . " ({$no})";
        $log_entry .= " - SUCCESS";
        echo "<script>
          updateProgress($current_index, $total_numbers);
          resultsDiv.innerHTML += '<div class=\"alert alert-success py-2 mb-1\"><i class=\"fas fa-check-circle me-2\"></i>{$customer['nama']} ({$no}) - <strong>BERHASIL</strong><br><small>Response: " . htmlspecialchars($response) . "</small></div>';
        </script>";
      } else {
        $fail_count++;
        $fail_list[] = $customer['nama'] . " ({$no}) - Error: " . ($json['message'] ?? 'Unknown');
        $log_entry .= " - FAILED: " . ($json['message'] ?? 'Unknown error');
        echo "<script>
          updateProgress($current_index, $total_numbers);
          resultsDiv.innerHTML += '<div class=\"alert alert-danger py-2 mb-1\"><i class=\"fas fa-times-circle me-2\"></i>{$customer['nama']} ({$no}) - <strong>GAGAL:</strong> " . ($json['message'] ?? 'Unknown') . "<br><small>Response: " . htmlspecialchars($response) . "</small></div>';
        </script>";
      }
    } else {
      $fail_count++;
      $fail_list[] = $customer['nama'] . " ({$no}) - No response";
      $log_entry .= " - FAILED: No response from server";
      echo "<script>
        updateProgress($current_index, $total_numbers);
        resultsDiv.innerHTML += '<div class=\"alert alert-warning py-2 mb-1\"><i class=\"fas fa-exclamation-triangle me-2\"></i>{$customer['nama']} ({$no}) - <strong>TIMEOUT</strong></div>';
      </script>";
    }

    // Catat ke log file
    @file_put_contents($log_file, $log_entry . "\n", FILE_APPEND);
    error_log("[broadcast_berhenti] " . $log_entry);

    flush(); // Send output to browser immediately

    // Delay
    sleepAman(4, 6);
}

// Final results
echo "<script>
  updateProgress($total_numbers, $total_numbers);
  setTimeout(function() {
    alert('BROADCAST SELESAI\\nTotal Berhasil: $success_count\\nTotal Gagal: $fail_count');
  }, 1000);
</script>";

// Catat log summary
$summary_log = date('Y-m-d H:i:s') . " - Broadcast Summary: Total processed: " . count($numbers) . ", Success: $success_count, Failed: $fail_count, Bot: $bot, User: $ceknama";
@file_put_contents($log_file, $summary_log . "\n", FILE_APPEND);
error_log("[broadcast_berhenti] " . $summary_log);

// Catat history
$history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Proses mengajak pelanggan lama untuk berlangganan kembali (Filter: Bulan $filter_bulan Tahun $filter_tahun) - Total: " . count($numbers) . ", Berhasil: $success_count, Gagal: $fail_count, Bot: $bot";
file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

require '../footer.php';
?>