<?php
/**
 * validasi_isolir_harian.php
 * ============================================================
 * Script validasi & eksekusi isolir harian untuk SEMUA pemilik.
 * Menjalankan kedua jenis isolir:
 *   1. non_aktif_tempo       — isolir berdasarkan tanggal jatuh tempo tetap
 *   2. non_aktif_by_tanggal  — isolir berdasarkan tanggal bayar per-pelanggan
 *
 * Cara pasang di crontab (jalankan setiap hari jam 00:05):
 *   5 0 * * * php /path/to/crm/billing/notifbot/notifphp/validasi_isolir_harian.php >> /var/log/validasi_isolir.log 2>&1
 * ============================================================
 */

// ============================================================
// INISIALISASI
// ============================================================
$startTime   = microtime(true);
$currentDate = date('Y-m-d');
$currentDay  = (int) date('d');
$scriptDir   = __DIR__;
$dataDir     = $scriptDir . '/../data';
$logFile     = $dataDir . '/validasi_isolir_' . $currentDate . '.log';

// Pastikan folder data ada
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

// Jeda aman antar pesan supaya nomor bot tidak dianggap spam oleh WhatsApp --
// pola sama persis dgn notif_remainder_pembayaran.php/non_aktif_tempo.php dkk.
// Script ini kirim 1 ringkasan per PEMILIK aktif (SEMUA tenant), jadi bisa
// beruntun banyak kalau jumlah akun aktif banyak.
function sleepAman($min = 4, $max = 6)
{
    $delayMs = rand($min * 1000, $max * 1000);
    usleep($delayMs * 1000);
}

// ============================================================
// FUNGSI LOGGING
// ============================================================
function vLog($msg)
{
    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    file_put_contents($logFile, $line . "\n", FILE_APPEND | LOCK_EX);
    echo $line . "\n";
}

// ============================================================
// KONEKSI DATABASE
// ============================================================
$config_file = $scriptDir . '/../../config.json';
$config      = file_exists($config_file) ? json_decode(file_get_contents($config_file), true) : [];
$domain      = $config['domain'] ?? '';

include $scriptDir . '/../../koneksidb.php';

if (!isset($conn) || !$conn) {
    vLog('[FATAL] Koneksi database gagal. Script berhenti.');
    exit(1);
}

// ============================================================
// FUNGSI KIRIM WHATSAPP (SUMMARY NOTIF)
// ============================================================
function kirimWhatsApp($waapi, $botname, $botpass, $phone, $pesan, $sender = '')
{
    if (empty($waapi) || empty($phone)) return false;

    $deviceId = trim((string)$sender);
    $url = "$waapi/send/message?session=" . urlencode($botname);
    if ($deviceId !== '') {
        $url .= '&device_id=' . urlencode($deviceId);
    }

    $headers = ['Content-Type: application/json'];
    if ($deviceId !== '') {
        $headers[] = "X-Device-Id: $deviceId";
    }

    $data = ['phone' => $phone, 'message' => $pesan];
    $ch   = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_USERPWD        => "$botname:$botpass",
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($httpCode >= 200 && $httpCode < 300);
}

// ============================================================
// MULAI PROSES
// ============================================================
vLog('');
vLog('================================================================');
vLog('=== MULAI VALIDASI ISOLIR HARIAN ===');
vLog("Tanggal  : $currentDate");
vLog("Hari ke  : $currentDay");
vLog('================================================================');

// ============================================================
// AMBIL SEMUA PEMILIK AKTIF DARI DATABASE
// ============================================================
$sqlUsers    = "SELECT `id`, `USERNAME`, `NOWA` FROM `user` WHERE `STATUS` = 'AKTIF' ORDER BY `USERNAME` ASC";
$queryUsers  = mysqli_query($conn, $sqlUsers);

if (!$queryUsers) {
    vLog('[FATAL] Query user gagal: ' . mysqli_error($conn));
    exit(1);
}

$totalUsers = mysqli_num_rows($queryUsers);
vLog("Total pemilik aktif ditemukan: $totalUsers");

// ============================================================
// ARRAY AKUMULASI HASIL
// ============================================================
$hasilOK   = [];   // Proses berhasil / sesuai kondisi
$hasilWarn = [];   // Peringatan (file tidak ada, reminder hilang, dll)
$hasilRun  = [];   // Script yang benar-benar dijalankan

// ============================================================
// LOOP SETIAP PEMILIK
// ============================================================
while ($user = mysqli_fetch_assoc($queryUsers)) {
    $pemilik = $user['USERNAME'];
    $iduser  = $user['id'];

    vLog('');
    vLog("------------------------------------------------------------");
    vLog("Memproses pemilik: $pemilik (ID: $iduser)");
    vLog("------------------------------------------------------------");

    // ----------------------------------------------------------
    // VALIDASI & JALANKAN: non_aktif_tempo_PEMILIK.php
    // Kondisi jalan: hari ini == jatuh_tempo di reminder JSON
    // ----------------------------------------------------------
    $tempoScript   = $scriptDir . "/non_aktif_tempo_$pemilik.php";
    $reminderFile  = $dataDir   . "/reminder-$pemilik.json";

    vLog("  [TEMPO] Memeriksa file: non_aktif_tempo_$pemilik.php");

    if (!file_exists($tempoScript)) {
        $msg = "[TEMPO] $pemilik » File script TIDAK ditemukan: non_aktif_tempo_$pemilik.php";
        vLog("  [TEMPO] WARNING: $msg");
        $hasilWarn[] = $msg;
    } else {
        vLog("  [TEMPO] File script  : ADA");

        // Cek file reminder
        if (!file_exists($reminderFile)) {
            $msg = "[TEMPO] $pemilik » File reminder TIDAK ditemukan: reminder-$pemilik.json";
            vLog("  [TEMPO] WARNING: $msg");
            $hasilWarn[] = $msg;
        } else {
            $reminderData = json_decode(file_get_contents($reminderFile), true);
            $jatuhTempo   = null;

            if (is_array($reminderData)) {
                foreach ($reminderData as $item) {
                    if (isset($item['jatuh_tempo'])) {
                        $jatuhTempo = (int) $item['jatuh_tempo'];
                        break;
                    }
                }
            }

            if ($jatuhTempo === null) {
                $msg = "[TEMPO] $pemilik » Data jatuh_tempo TIDAK ada di reminder JSON";
                vLog("  [TEMPO] WARNING: $msg");
                $hasilWarn[] = $msg;
            } else {
                vLog("  [TEMPO] Jatuh tempo : hari ke-$jatuhTempo | Hari ini: hari ke-$currentDay");

                if ($currentDay == $jatuhTempo) {
                    // ── KONDISI TERPENUHI: jalankan isolir tempo ──
                    vLog("  [TEMPO] ✓ KONDISI TERPENUHI — menjalankan isolir tempo untuk $pemilik ...");

                    $phpBin = PHP_BINARY ?: 'php';
                    $cmd    = $phpBin . ' ' . escapeshellarg($tempoScript) . ' 2>&1';
                    $output = shell_exec($cmd);

                    $outputPreview = mb_substr((string) $output, 0, 300);
                    vLog("  [TEMPO] Output (300 char pertama): " . preg_replace('/\s+/', ' ', strip_tags($outputPreview)));
                    vLog("  [TEMPO] Selesai dijalankan untuk $pemilik");

                    $hasilRun[] = "[TEMPO] $pemilik — dijalankan (jatuh tempo hari ke-$jatuhTempo)";
                    $hasilOK[]  = "[TEMPO] $pemilik — ✓ OK (dijalankan)";
                } else {
                    // Bukan hari jatuh tempo → skip, ini normal
                    $hariSisa = $jatuhTempo - $currentDay;
                    if ($hariSisa < 0) {
                        $hariSisa += cal_days_in_month(CAL_GREGORIAN, (int)date('m'), (int)date('Y'));
                    }
                    vLog("  [TEMPO] Bukan hari jatuh tempo, dilewati (sisa ±$hariSisa hari)");
                    $hasilOK[] = "[TEMPO] $pemilik — dilewati (jatuh tempo hari ke-$jatuhTempo, sisa ±$hariSisa hari)";
                }
            }
        }
    }

    // ----------------------------------------------------------
    // VALIDASI & JALANKAN: non_aktif_by_tanggal_PEMILIK.php
    // Script ini SELALU dijalankan setiap hari karena mengecek
    // jatuh tempo per-pelanggan berdasarkan tanggal bayar terakhir.
    // ----------------------------------------------------------
    $tanggalScript = $scriptDir . "/non_aktif_by_tanggal_$pemilik.php";

    vLog("  [BY_TANGGAL] Memeriksa file: non_aktif_by_tanggal_$pemilik.php");

    if (!file_exists($tanggalScript)) {
        $msg = "[BY_TANGGAL] $pemilik » File script TIDAK ditemukan: non_aktif_by_tanggal_$pemilik.php";
        vLog("  [BY_TANGGAL] WARNING: $msg");
        $hasilWarn[] = $msg;
    } else {
        vLog("  [BY_TANGGAL] File script  : ADA");
        vLog("  [BY_TANGGAL] Menjalankan isolir by-tanggal untuk $pemilik ...");

        $phpBin = PHP_BINARY ?: 'php';
        $cmd    = $phpBin . ' ' . escapeshellarg($tanggalScript) . ' 2>&1';
        $output = shell_exec($cmd);

        $outputPreview = mb_substr((string) $output, 0, 300);
        vLog("  [BY_TANGGAL] Output (300 char pertama): " . preg_replace('/\s+/', ' ', strip_tags($outputPreview)));
        vLog("  [BY_TANGGAL] Selesai dijalankan untuk $pemilik");

        $hasilRun[] = "[BY_TANGGAL] $pemilik — dijalankan";
        $hasilOK[]  = "[BY_TANGGAL] $pemilik — ✓ OK (dijalankan)";
    }

    vLog("  Selesai memproses pemilik: $pemilik");
}

// ============================================================
// RINGKASAN AKHIR
// ============================================================
$elapsed = round(microtime(true) - $startTime, 2);

vLog('');
vLog('================================================================');
vLog('=== RINGKASAN VALIDASI ISOLIR HARIAN ===');
vLog("Tanggal eksekusi : $currentDate");
vLog("Durasi           : {$elapsed} detik");
vLog("Total pemilik    : $totalUsers");
vLog("Script dijalankan: " . count($hasilRun));
vLog("Warning/Gagal    : " . count($hasilWarn));
vLog('----------------------------------------------------------------');

if (!empty($hasilRun)) {
    vLog("Script yang dijalankan:");
    foreach ($hasilRun as $r) {
        vLog("  [RUN]  $r");
    }
}

if (!empty($hasilOK)) {
    vLog("Status OK:");
    foreach ($hasilOK as $ok) {
        vLog("  [OK]   $ok");
    }
}

if (!empty($hasilWarn)) {
    vLog("Peringatan:");
    foreach ($hasilWarn as $w) {
        vLog("  [WARN] $w");
    }
}

vLog('================================================================');
vLog('=== SELESAI VALIDASI ISOLIR HARIAN ===');
vLog('');

// ============================================================
// KIRIM NOTIF WHATSAPP RINGKASAN KE SEMUA PEMILIK
// ============================================================
require_once($scriptDir . '/../bot_selector_helper.php');

mysqli_data_seek($queryUsers, 0);

// Rebuild daftar pemilik untuk notif (query ulang karena pointer sudah di ujung)
$sqlUsersNotif = "SELECT `id`, `USERNAME`, `NOWA` FROM `user` WHERE `STATUS` = 'AKTIF' ORDER BY `USERNAME` ASC";
$queryNotif    = mysqli_query($conn, $sqlUsersNotif);

while ($userNotif = mysqli_fetch_assoc($queryNotif)) {
    $pemilik = $userNotif['USERNAME'];

    // Ambil bot untuk pemilik ini
    $botResult = selectBotForNotificationWithField($conn, $pemilik, '', 'penerima_system_notif');
    if (!$botResult['success']) {
        vLog("[NOTIF] $pemilik — Gagal memilih bot: " . $botResult['message']);
        continue;
    }

    $waapi              = $botResult['addressbot'];
    $botpass            = $botResult['password'];
    $botname            = $botResult['namebot'];
    $penerimaSysNotif   = $botResult['penerima'];
    $sender             = $botResult['sender'] ?? '';

    if (empty($penerimaSysNotif)) {
        vLog("[NOTIF] $pemilik — Penerima system notif kosong, skip.");
        continue;
    }

    // Buat ringkasan per-pemilik
    $runList  = array_filter($hasilRun,  fn($r) => strpos($r, $pemilik) !== false);
    $warnList = array_filter($hasilWarn, fn($w) => strpos($w, $pemilik) !== false);

    $ringkasan  = "[VALIDASI ISOLIR HARIAN]\n";
    $ringkasan .= "Tanggal  : $currentDate\n";
    $ringkasan .= "Pemilik  : $pemilik\n";
    $ringkasan .= "Durasi   : {$elapsed} detik\n\n";

    if (!empty($runList)) {
        $ringkasan .= "*Script Dijalankan:*\n";
        foreach ($runList as $r) {
            $ringkasan .= "  ✓ $r\n";
        }
    }

    if (!empty($warnList)) {
        $ringkasan .= "\n*Peringatan:*\n";
        foreach ($warnList as $w) {
            $ringkasan .= "  ⚠ $w\n";
        }
    }

    if (empty($runList) && empty($warnList)) {
        $ringkasan .= "Tidak ada proses isolir hari ini (bukan hari jatuh tempo & tidak ada pelanggan by-tanggal).\n";
    }

    $ok = kirimWhatsApp($waapi, $botname, $botpass, $penerimaSysNotif, trim($ringkasan), $sender);
    vLog("[NOTIF] $pemilik — Notif dikirim ke $penerimaSysNotif: " . ($ok ? 'Berhasil' : 'Gagal'));

    // Jeda aman -- loop ini kirim 1 ringkasan per pemilik aktif, bisa banyak
    // kalau jumlah tenant banyak, sebelumnya tidak ada jeda sama sekali.
    sleepAman();
}

echo "\nLog tersimpan di: $logFile\n";
exit(0);
