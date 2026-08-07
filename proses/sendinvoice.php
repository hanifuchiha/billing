<?php
require '../cek-sesi.php';
require '../routeros_api.class.php';
session_start();
ob_clean(); // Bersihkan output buffer
header("Content-Type: application/json");

$username = $_SESSION['username'] ?? 'unknown';

// Domain link pembayaran
$domain = $config['domain'];
$URL = $config['domain'];

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




// Proses request POST
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Ambil data dari POST
    $nama = $_POST["NAMA"] ?? "";
    $idpel = $_POST["IDPEL"] ?? "";
    $nowa = $_POST["NOWA"] ?? "";
    $paket = $_POST["PAKET"] ?? "";
     $email = $_POST["EMAIL"] ?? "";
    $botId = isset($_POST["bot_id"]) ? (int)$_POST["bot_id"] : 0;
    // Validasi dasar
    if (empty($idpel) || empty($nowa)) {
        echo json_encode(["message" => "ID pelanggan atau nomor WhatsApp kosong."]);
        exit;
    }

    $url_cari = $domain . "/crm/billing/broadband/portal.php?cari=" . urlencode($idpel);

  $pesan_remainder_manual = '';
                               
                               
                                    $stmt = $conn->prepare("SELECT pesan_remainder_manual FROM notif_khusus WHERE pemilik = ? LIMIT 1");
                                    $stmt->bind_param('s',$ceknama);
                                    $stmt->execute();
                                    $stmt->bind_result($pesan_remainder_manual);
                                    $stmt->fetch();
                                    $stmt->close();
                            

                                // Fungsi untuk replace variabel di pesan ketentuan
                                function replace_vars($template, $vars) {
                                    return preg_replace_callback('/\\$([a-zA-Z0-9_]+)/', function($m) use ($vars) {
                                        $key = $m[1];
                                        return isset($vars[$key]) ? $vars[$key] : $m[0];
                                    }, $template);
                                }



    // Ambil informasi bot: kalau user pilih bot lewat modal (bot_id), pakai
    // itu. Kalau tidak ada (panggilan lama/lain), fallback ke botname dari
    // file config reminder seperti sebelumnya.
    $waapi = "";
    $passwordbot = "";
    $botname = "";
    $sender = "";

    if ($botId > 0) {
        // Assistant tanpa assign tak boleh pakai bot_id manipulasi punya bot lain
        // (lihat notifbot/bot_access_helper.php).
        $stmtBot = $conn->prepare("SELECT namebot, addressbot, password, sender FROM botwa WHERE id = ? AND pemilik = ?" . botAccessWhereClause($conn, $AKSES, $assigned_bot_ids ?? [], $asistant_name ?? '') . " LIMIT 1");
        $stmtBot->bind_param('is', $botId, $ceknama);
        $stmtBot->execute();
        $botRow = $stmtBot->get_result()->fetch_assoc();
        $stmtBot->close();

        if (!$botRow) {
            echo json_encode(["message" => "❌ Bot yang dipilih tidak ditemukan atau bukan milik akun ini."]);
            exit;
        }
        $botname = $botRow['namebot'];
        $waapi = $botRow['addressbot'];
        $passwordbot = $botRow['password'];
        $sender = $botRow['sender'] ?? '';
    } else {
        $jsonFile = "../notifbot/data/reminder-$ceknama.json";
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

        if ($botname !== '') {
            $stmtBot = $conn->prepare("SELECT addressbot, password, sender FROM botwa WHERE namebot = ? LIMIT 1");
            $stmtBot->bind_param('s', $botname);
            $stmtBot->execute();
            $botRow = $stmtBot->get_result()->fetch_assoc();
            $stmtBot->close();
            if ($botRow) {
                $waapi = $botRow['addressbot'];
                $passwordbot = $botRow['password'];
                $sender = $botRow['sender'] ?? '';
            }
        }
    }

    if ($waapi === '' || $botname === '') {
        echo json_encode(["message" => "❌ Bot WhatsApp belum dipilih/dikonfigurasi. Silakan pilih bot pengirim."]);
        exit;
    }

    $phone = "$nowa@s.whatsapp.net";

    // Ambil semua variabel terdefinisi di scope ini
    $vars = get_defined_vars();
    // Hapus variabel yang tidak perlu (opsional, agar tidak bocor variabel besar)
    unset($vars['isi'], $vars['match'], $vars['vars']);
    $message = replace_vars($pesan_remainder_manual, $vars);

    $data = [
        "phone" => $phone,
        "message" => $message,
        "sender" => $sender
    ];

    // device_id gowa multi-device (build gowa terbaru wajib X-Device-Id
    // header / device_id query di /send/message) = isi kolom sender apa
    // adanya (nama device di server gowa, mis. "hanif"), sama seperti fitur
    // Tester bot internal.
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
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_USERPWD, "$botname:$passwordbot");
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $isSuccess = $response !== false && $httpCode >= 200 && $httpCode < 300;

    // Simpan log history pengiriman
    $history_file = "../notifbot/data/history-$ceknama.json";
    $history = [];

    if (file_exists($history_file)) {
        $history = json_decode(file_get_contents($history_file), true);
    }
    if (!is_array($history)) $history = [];

    $historyStatus = $isSuccess ? 'sukses' : 'GAGAL (HTTP ' . $httpCode . ')';
    $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Mengirim invoice ke '$nowa' via bot '$botname' - Periode: $periode - Status: $historyStatus";
    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

    if ($isSuccess) {
        echo json_encode([
            "message" => "✅ Invoice berhasil dikirim ke $idpel ($nowa) via bot '$botname' - Periode: $periode"
        ]);
    } else {
        $errDetail = $curlError !== '' ? $curlError : ('HTTP ' . $httpCode . ': ' . substr(strip_tags((string)$response), 0, 200));
        echo json_encode([
            "message" => "❌ Gagal mengirim invoice ke $idpel ($nowa) via bot '$botname': $errDetail"
        ]);
    }
    exit;
} else {
    echo json_encode(["message" => "Metode request tidak valid"]);
    exit;
}
