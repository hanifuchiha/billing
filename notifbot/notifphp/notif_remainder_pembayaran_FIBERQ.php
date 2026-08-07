<?php
include '../../koneksidb.php';
include "../phpmailer/classes/class.phpmailer.php";
require_once __DIR__ . '/tagihan_status_lib.php';
require_once __DIR__ . '/../notif_template_helper.php';
if ($conn->connect_error) {
    die("Koneksi gagal: " . $conn->connect_error);
}



// Fungsi delay aman (4–6 detik acak)
function sleepAman($min = 4, $max = 6)
{
    $delay = rand($min * 1000, $max * 1000);
    usleep($delay * 1000);
}
////////////////////////////////////////
// Simpan konfigurasi
$config_file ='../../config.json'; // Lokasi file di folder yang sama
$config = file_exists($config_file) ? json_decode(file_get_contents($config_file), true) : [];

$domain=$config['domain'];
$URL=$config['domain'];

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


// Cek apakah sudah pernah dikirim
// Dipindah ke atas (sebelum baca JSON reminder & pilih bot) supaya SEMUA jalur
// gagal di bawah (JSON hilang/rusak, bot tidak ketemu) bisa dicatat ke history.
// Sebelumnya kegagalan di titik ini cuma di-echo() -- padahal cron dipanggil via
// `curl ... > /dev/null 2>&1` di crontab (lihat system_setting.php), jadi echo
// itu langsung dibuang dan admin tidak pernah tahu cron gagal total di sini.
$history_file = "../data/history-$pemilik.json";
$history = [];

if (file_exists($history_file)) {
    $history = json_decode(file_get_contents($history_file), true);
}

// Pastikan format history adalah array
if (!is_array($history)) {
    $history = [];
}

// Path ke file JSON
$jsonFile = "../data/reminder-$pemilik.json";

// Default tutup buku sama dgn cek_tagihan_harian.php supaya konsisten kalau
// admin belum pernah menyentuh setting ini di Payment Setting.
$tanggal_awal_tutup_buku = 24;
$tanggal_akhir_tutup_buku = 5;

// Toggle filter (Payment Setting -> Konfigurasi Fixed Due Date): default TRUE
// (perilaku sama seperti selama ini) supaya akun yang belum pernah menyentuh
// setting baru ini tidak berubah perilaku reminder-nya. Kalau di-OFF-kan admin,
// filter terkait dilewati (reminder tetap dikirim walau sudah bayar / sudah
// dinotif hari ini) -- dipakai jg oleh blok Rolling/Monthversary di bawah.
$filterSudahBayarReminder = true;
$filterSkipNotifHariIni = true;

// Default Fixed Due Date -- SAMA seperti cek_tagihan_harian.php (jatuh_tempo_hari=25,
// hari_sebelum=3), dipakai HANYA kalau reminder-$pemilik.json belum pernah
// disimpan/rusak. $jatuh_tempo TIDAK dipakai sama sekali oleh cabang
// mengikuti_tanggal_bayar/monthversary di tagihanHitungStatus() (lihat
// tagihan_status_lib.php: jatuh_tempo_hari cuma dikonsumsi cabang
// mengikuti_tanggal_tempo), jadi aman jadi default generik utk pelanggan
// Rolling/Monthversary. $tanggal_reminder=0 sengaja dipilih supaya TIDAK PERNAH
// cocok dgn $hariinitgl ('01'-'31') -- blok Fixed Due Date di bawah otomatis
// ke-skip (bukan mati total) kalau admin belum setting Fixed Due Date, sementara
// blok Rolling/Monthversary tetap jalan seperti biasa (mereka tidak butuh
// konfigurasi Fixed Due Date).
$jatuh_tempo = 25;
$hari_sebelum = 3;
$tanggal_reminder = 0;
$botname = 'RANDOM';

// Counter ringkasan -- dicetak di paling bawah file (rekap Terkirim/Gagal/Skip)
// supaya gampang dibaca pas testing manual lewat akses URL langsung.
$cntTerkirim = 0;
$cntGagal = 0;
$cntSkipSudahBayar = 0;
$cntSkipSudahNotifHariIni = 0;
$cntSkipTemplateKosong = 0;
$cntSkipPaketFree = 0;
$cntSkipJatuhTempoTdkTerhitung = 0;

// Cek apakah file ada
if (file_exists($jsonFile)) {
    // Baca isi file JSON
    $jsonData = file_get_contents($jsonFile);

    // Decode JSON menjadi array asosiatif
    $data = json_decode($jsonData, true);

    // Periksa apakah decoding berhasil
    if ($data !== null) {
        foreach ($data as $item) {
            if (isset($item['jatuh_tempo'])) $jatuh_tempo = $item['jatuh_tempo'];
            if (isset($item['hari_sebelum'])) $hari_sebelum = $item['hari_sebelum'];
            if (isset($item['tanggal_reminder'])) $tanggal_reminder = $item['tanggal_reminder'];
            if (isset($item['botname'])) $botname = $item['botname'];
            if (isset($item['tanggal_awal_tutup_buku'])) $tanggal_awal_tutup_buku = (int)$item['tanggal_awal_tutup_buku'];
            if (isset($item['tanggal_akhir_tutup_buku'])) $tanggal_akhir_tutup_buku = (int)$item['tanggal_akhir_tutup_buku'];
            if (isset($item['filter_sudah_bayar_reminder'])) $filterSudahBayarReminder = !empty($item['filter_sudah_bayar_reminder']);
            if (isset($item['filter_skip_notif_hari_ini'])) $filterSkipNotifHariIni = !empty($item['filter_skip_notif_hari_ini']);
        }
    } else {
        // TIDAK die() lagi -- JSON Fixed Due Date rusak cuma memblokir blok Fixed
        // Due Date (lewat $tanggal_reminder default yang tak pernah cocok di atas),
        // pelanggan Rolling/Monthversary di bawah TETAP diproses pakai default.
        echo "Error: Gagal mendecode JSON reminder Fixed Due Date. Blok Fixed Due Date di-skip, pelanggan Rolling/Monthversary tetap diproses pakai default.";
        $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] PERINGATAN: file reminder-$pemilik.json ada tapi tidak bisa didecode (JSON rusak) - blok Fixed Due Date di-skip (perlu disimpan ulang di Payment Setting), Rolling/Monthversary tetap jalan pakai default (jatuh_tempo=$jatuh_tempo, hari_sebelum=$hari_sebelum, bot=$botname)";
        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    }
} else {
    // TIDAK die() lagi -- pelanggan tipe Rolling (mengikuti_tanggal_bayar) atau
    // Monthversary TIDAK butuh konfigurasi Fixed Due Date sama sekali (lihat
    // komentar default di atas), jadi mereka tidak boleh ikut mati total hanya
    // krn admin belum pernah menyentuh Payment Setting -> Konfigurasi Fixed Due
    // Date. Blok Fixed Due Date di bawah otomatis ke-skip lewat $tanggal_reminder=0.
    echo "Error: File JSON reminder Fixed Due Date tidak ditemukan. Blok Fixed Due Date di-skip, pelanggan Rolling/Monthversary tetap diproses pakai default.";
    $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] PERINGATAN: file reminder-$pemilik.json tidak ditemukan (Fixed Due Date belum disetting di Payment Setting) - blok Fixed Due Date di-skip, Rolling/Monthversary tetap jalan pakai default (jatuh_tempo=$jatuh_tempo, hari_sebelum=$hari_sebelum, bot=$botname)";
    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
}

// Setting "Periode Tercatat" (Fixed Due Date, Payment Setting -> Konfigurasi Fixed
// Due Date) -- dipakai supaya cek "sudah bayar periode ini" di bawah konsisten dgn
// label PENGUNAAN yang benar-benar ditulis invoice_generator_penagihan.php.
$periodeTercatatMode = tagihanLoadPeriodeTercatatMode($jsonFile);

// Waktu tunggu prabayar (Payment Setting -> Waktu Tunggu Prabayar), dipakai oleh
// tagihanHitungStatus() -- sama seperti cek_tagihan_harian.php.
$prabayarGracePeriodRemainder = 2;
$graceConfigFileRemainder = "../data/prabayar_grace_period-$pemilik.json";
if (file_exists($graceConfigFileRemainder)) {
    $graceDataRemainder = json_decode(file_get_contents($graceConfigFileRemainder), true);
    if (is_array($graceDataRemainder) && isset($graceDataRemainder['prabayar_grace_period'])) {
        $prabayarGracePeriodRemainder = (int)$graceDataRemainder['prabayar_grace_period'];
    }
}

// Setting "Monthversary ikut tanggal bayar terakhir" (Payment Setting -> Pengaturan
// Monthversary), dipakai supaya jatuh tempo yang dihitung di sini SAMA dgn yang
// dipakai tables.php/portal_bayar.php/cron generator.
$monthversaryFollowLastPaymentRemainder = false;
$monthversaryConfigFileRemainder = "../data/monthversary_setting-$pemilik.json";
if (file_exists($monthversaryConfigFileRemainder)) {
    $mvCfgRemainder = json_decode(file_get_contents($monthversaryConfigFileRemainder), true);
    if (is_array($mvCfgRemainder) && isset($mvCfgRemainder['follow_last_payment'])) {
        $monthversaryFollowLastPaymentRemainder = !empty($mvCfgRemainder['follow_last_payment']);
    }
}

// Include helper function untuk pemilihan bot (mendukung RANDOM)
require_once('../bot_selector_helper.php');

// Gunakan helper function untuk pemilihan bot (mendukung RANDOM)
$bot_result = selectBotForNotificationWithField($conn, $pemilik, $botname, 'penerima');

if ($bot_result['success']) {
  $waapi = $bot_result['addressbot'];
  $botpass = $bot_result['password'];
  $botname = $bot_result['namebot'];
  $sender = $bot_result['sender'] ?? '';

  if ($bot_result['is_random']) {
    echo "[RANDOM BOT] Bot dipilih secara random: $botname untuk menghindari spam (Payment Reminder)<br>";
  }
} else {
  echo "ERROR: " . $bot_result['message'] . "<br>";
  $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] GAGAL TOTAL: tidak ada bot WA ditemukan untuk pemilik $pemilik (" . $bot_result['message'] . ") - cron reminder dihentikan";
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

// Fungsi periode aktif -- SAMA PERSIS dgn cek_tagihan_harian.php (bulanTahunIndo()
// + periodeTagihanAktif()) supaya reminder WA merujuk ke bulan tagihan yang sama
// dgn yang dipakai isolir/pencocokan PENGUNAAN, bukan rumus periode terpisah yang
// tidak konsultasi tanggal_awal_tutup_buku/tanggal_akhir_tutup_buku.
if (!function_exists('bulanTahunIndo')) {
    function bulanTahunIndo(string $tanggal, int $tambah = 0): string
    {
        $namaBulan = [
            1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
        ];
        $ts    = strtotime($tanggal);
        $bulan = (int)date('n', $ts) + $tambah;
        $tahun = (int)date('Y', $ts);

        while ($bulan < 1)  { $bulan += 12; $tahun--; }
        while ($bulan > 12) { $bulan -= 12; $tahun++; }

        return $namaBulan[$bulan] . ' ' . $tahun;
    }
}

if (!function_exists('periodeTagihanAktif')) {
    function periodeTagihanAktif(
        int    $tglHariIni,
        int    $tanggal_awal_tutup_buku,
        int    $tanggal_akhir_tutup_buku,
        int    $jatuh_tempo_hari,
        string $tanggalHariIni
    ): string {
        $periodeSekarang   = bulanTahunIndo($tanggalHariIni, 0);
        $periodeBerikutnya = bulanTahunIndo($tanggalHariIni, 1);

        // Tutup buku lintas bulan (mis. 24-5)
        if ($tanggal_awal_tutup_buku > $tanggal_akhir_tutup_buku) {
            if ($tglHariIni >= $tanggal_awal_tutup_buku || $tglHariIni <= $tanggal_akhir_tutup_buku) {
                return $periodeSekarang;
            }
            return $periodeBerikutnya;
        }

        // Tutup buku normal (mis. 1-10)
        if ($tglHariIni <= $tanggal_akhir_tutup_buku) {
            return $periodeSekarang;
        }
        return $periodeBerikutnya;
    }
}

// Tanggal sekarang
$ptanggalalu = tanggal_indo($cektanggal, true, -1); // Bulan lalu
echo $ptanggalalu . "<br>";

$ptanggalskg = tanggal_indo($cektanggal, true); // Bulan ini
echo $ptanggalskg . "<br>";

$ptanggaberikut = tanggal_indo($cektanggal, true, 1); // Bulan berikutnya
echo $ptanggaberikut . "<br>";
echo "<br>";
////////////////////////////////////////////////////////////////////
// Tentukan periode tagihan Fixed Due Date (dipakai di pesan/history) --
// lihat komentar detail di atas $periode di bawah.
$tglskg = (int) date('d');
$ptanggalcek = tanggal_indo($cektanggal, true);
// $periode SEBELUMNYA dihitung via periodeTagihanAktif() (heuristik tutup-buku
// tanggal_awal_tutup_buku/tanggal_akhir_tutup_buku) -- konsep ini TIDAK ADA
// hubungannya dgn siklus jatuh tempo Fixed Due Date, jadi labelnya sering
// tidak nyambung (mis. tetap "Juli" padahal sudah masuk Agustus). Diganti pakai
// siklus jatuh_tempo_hari (kalau hari ini <= jatuh_tempo_hari, periode = bulan
// berjalan; kalau sudah lewat, periode = bulan berikutnya) + setting Periode
// Tercatat, persis rumus yang dipakai invoice_generator_penagihan.php.
$dueMonthTsPeriode = ((int) date('j', strtotime($cektanggal)) <= (int) $jatuh_tempo)
    ? strtotime($cektanggal)
    : strtotime('+1 month', strtotime($cektanggal));
$periode = tagihanResolvePeriodeTercatat(
    (int) date('n', $dueMonthTsPeriode),
    (int) date('Y', $dueMonthTsPeriode),
    $periodeTercatatMode
);
/////////////////////////////////////////////////////////////////////////////////
echo $periode;

$hariinitgl = date('d');















 $filePath = notifTemplateFilePath($pemilik);

                 
 // Ganti variabel di dalam teks
                        function replaceVariables($text)
                        {
                            return preg_replace_callback('/\$(\w+)/', function ($matches) {
                                return isset($GLOBALS[$matches[1]]) ? $GLOBALS[$matches[1]] : $matches[0];
                            }, $text);
                        }

// Cek IDPEL mana saja yang punya baris PENAGIHAN (invoice) yang MASIH MENUNGGU
// BAYAR -- dipakai supaya reminder tidak menganggap pelanggan "sudah bayar" hanya
// dari aritmetika siklus tagihanHitungStatus() (last payment + hitung mundur bulan
// tertunggak), padahal nyatanya ada invoice PENAGIHAN konkret yang belum lunas --
// pola query sama seperti portal_bayar.php ("SELECT * FROM transaksi WHERE IDPEL = ?
// AND UPPER(STATUS) = 'PENAGIHAN'"), cuma versi bulk utk banyak IDPEL sekaligus.
function tagihanCekAdaPenagihanPendingBulk(mysqli $conn, array $idpels): array
{
    $escaped = [];
    foreach (array_unique($idpels) as $v) {
        $v = trim((string) $v);
        if ($v === '') continue;
        $escaped[] = "'" . $conn->real_escape_string($v) . "'";
    }
    if (empty($escaped)) return [];
    $inList = implode(',', $escaped);
    $sql = "SELECT DISTINCT IDPEL FROM transaksi WHERE UPPER(STATUS) = 'PENAGIHAN' AND IDPEL IN ($inList)";
    $result = $conn->query($sql);
    $map = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $map[(string) $row['IDPEL']] = true;
        }
    }
    return $map;
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






























////////////////////////////////////////////////////////////////////
//info ke pusat jika mau notif pembayaran 

if ($hariinitgl == $tanggal_reminder) {

    $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Proses bot remainder pembayaran client dimulai";
    // Simpan ke file history
    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));


    $text = "Proses bot remainder pembayaran client dimulai";
    $session = $botname; // Nama sesi yang telah Anda buat
    // Nomor tujuan dan pesan
    $phone = "120363046084927501@g.us"; // Format: nomor@s.whatsapp.net
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
    curl_close($ch);
    echo "[DEBUG WA GATEWAY] HTTP: $httpCode | Response: " . htmlspecialchars((string)$response) . "<br>";




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
        $PASSWORD = $data['PASSWORD']; // tidak di-echo (kredensial perangkat)
        echo $BOTWA = $data['BOTWA'];
        echo "<br>";
        echo $OLT = $data['OLT'];
        echo "<br>";
        echo $MIK80 = $data['MIK80'];
        echo "<br>";
          echo $BRAND = $data['BRAND'];
        echo "<br>";
        echo "<br>";



        $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Proses bot remainder pembayaran client dimulai $PEMILIK - $AREA ( PERIODE => $periode )";
        // Simpan ke file history
        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

        $text = "Proses bot remainder pembayaran client  $PEMILIK - $AREA ( PERIODE => $periode ) dimulai";
        $session = $botname; // Nama sesi yang telah Anda buat
        // Nomor tujuan dan pesan
        $phone = "120363046084927501@g.us"; // Format: nomor@s.whatsapp.net
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
        curl_close($ch);
        echo "[DEBUG WA GATEWAY] Response: " . htmlspecialchars((string)$response) . "<br>";

        // Jeda aman -- loop ping per-server ini bisa kirim banyak pesan
        // beruntun kalau owner punya banyak server, sebelumnya tidak ada
        // jeda sama sekali di sini.
        sleepAman();







        $sql1 = "SELECT * from `pelanggan` WHERE `PEMILIK` = '$PEMILIK' and `AREA`='$AREA'";
        $query1 = mysqli_query($conn, $sql1);
        
        // Fetch semua pelanggan ke array untuk bisa shuffle jika multibot random
        $pelangganList = [];
        while ($data1 = mysqli_fetch_array($query1)) {
            $pelangganList[] = $data1;
        }
        
        // Shuffle pelanggan jika multibot random mode
        if ($isRandomBot && $botPoolCount > 0) {
            shuffle($pelangganList);
        }

        // Bulk fetch riwayat pembayaran sekali per server/area (bukan per pelanggan)
        // -- dipakai tagihanHitungStatus() di bawah utk cek "sudah bayar periode ini
        // atau belum" yang benar per TIPE_TEMPO, sama seperti tables.php/portal_bayar.php.
        $remainderIdpelList = array_map(static function ($row) {
            return (string) ($row['IDPEL'] ?? '');
        }, $pelangganList);
        $remainderLastPaymentMap = tagihanGetLastPaymentsBulk($conn, $remainderIdpelList);
        $remainderLastPaidUsageMap = tagihanGetLastPaidUsageMapBulk($conn, $remainderIdpelList);
        $remainderPendingPenagihanMap = tagihanCekAdaPenagihanPendingBulk($conn, $remainderIdpelList);

        // Loop melalui pelanggan (sudah ter-shuffle jika random mode)
        foreach ($pelangganList as $data1) {
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
            echo $TANGGALPASANG = $data1['TANGGALPASANG'];
            echo "<br>";
            echo $ALAMAT = $data1['ALAMAT'];
            echo "<br>";
            echo $TIPE_BAYAR = $data1['TIPE_BAYAR']; // prabayar atau pascabayar
            echo "<br>";
            echo $TIPE_TEMPO = $data1['TIPE_TEMPO']; // mengikuti_tanggal_bayar atau mengikuti_tanggal_tempo
            echo "<br>";

            // Normalisasi sama seperti cek_tagihan_harian.php: nilai selain
            // mengikuti_tanggal_bayar/monthversary diperlakukan sbg
            // mengikuti_tanggal_tempo (bukan exact-match string mentah), supaya
            // data lama dgn whitespace/casing beda tidak salah dilewati.
            $TIPE_TEMPO_NORM = strtolower(trim((string) $TIPE_TEMPO));
            if ($TIPE_TEMPO_NORM !== 'mengikuti_tanggal_bayar' && $TIPE_TEMPO_NORM !== 'monthversary') {
                $TIPE_TEMPO_NORM = 'mengikuti_tanggal_tempo';
            }

if($TIPE_TEMPO_NORM=="mengikuti_tanggal_tempo"){
            if (stripos($PAKET, 'FREE') !== false) {
                echo "[DEBUG FIXED] SKIP $IDPEL: paket FREE<br>";
                $cntSkipPaketFree++;
            }
            if (stripos($PAKET, 'FREE') === false) {

                //////////////////////BUKA BUKU TRANSAKSI CEK SUDAH BAYAR ATAU BELUM/////////////////////////////////
                // SEBELUMNYA: "SELECT ... WHERE PENGUNAAN LIKE '%$periode%'" dgn $periode
                // dihitung SEKALI di awal script pakai heuristik tutup-buku generik --
                // tidak konsultasi setting Periode Tercatat (Payment Setting -> Konfigurasi
                // Fixed Due Date), jadi gampang meleset dari label PENGUNAAN yang
                // sebenarnya ditulis invoice_generator_penagihan.php begitu admin pakai
                // mode 'berikutnya'. Sekarang pakai tagihanHitungStatus() (fungsi yang
                // SAMA dipakai cek_tagihan_harian.php/tables.php/portal_bayar.php) supaya
                // "sudah bayar periode ini" dicek dgn cara yang konsisten di seluruh sistem.
                $statusRemainderFixed = tagihanHitungStatus($conn, [
                    'IDPEL' => $IDPEL,
                    'TANGGALPASANG' => (string) $TANGGALPASANG,
                    'TIPE_BAYAR' => (string) $TIPE_BAYAR,
                    'TIPE_TEMPO' => $TIPE_TEMPO_NORM,
                    'TEMPO' => (string) ($data1['TEMPO'] ?? ''),
                    'TANGGAL_MONTHVERSARY' => (string) ($data1['TANGGAL_MONTHVERSARY'] ?? ''),
                ], [
                    'hari_ini' => $cektanggal,
                    'jatuh_tempo_hari' => (int) $jatuh_tempo,
                    'lastPaymentMap' => $remainderLastPaymentMap,
                    'lastPaidUsageMap' => $remainderLastPaidUsageMap,
                    'prabayar_grace_period' => $prabayarGracePeriodRemainder,
                ]);
                $cek = $statusRemainderFixed['sudah_bayar'] ? 1 : 0;
                if ($cek == 1 && !empty($remainderPendingPenagihanMap[$IDPEL])) {
                    // tagihanHitungStatus() bilang lunas (aritmetika siklus), TAPI ada
                    // baris PENAGIHAN yang nyata-nyata masih menunggu bayar -- jangan skip.
                    $cek = 0;
                }
                $url_cari = "https://$domain/crm/billing/broadband/portal.php?cari=" . urlencode($IDPEL);

                if ($cek == 1 && $filterSudahBayarReminder) {
                    echo "[DEBUG FIXED] SKIP $IDPEL: sudah bayar periode ini ($periode)<br>";
                    $cntSkipSudahBayar++;
                } elseif ($cek == 1) {
                    echo "[DEBUG FIXED] $IDPEL: sudah bayar periode ini ($periode), TAPI filter 'Sudah Bayar' OFF -- tetap diproses<br>";
                }

                if ($cek == 0 || !$filterSudahBayarReminder) {


                    // $text = "*[ INI ADALAH PESAN OTOMATIS ]*\n\nHai bpk/ibu $NAMA \nInternet anda dalam jatuh tempo .\nSegera lakukan pembayaran untuk menghindari ISOLIR tanggal $jatuh_tempo.\n\n- Dengan detail :\n- ID Pelanggan : $IDPEL \n- Nama Pelanggan : $NAMA \n- Paket langganan : $PAKET \n- No Whatsapp : $NOWA \n- E-mail : $EMAIL\n- Alamat : $ALAMAT\n- Invoice : https://quenbytekniksejahtera.com/crm/billing/broadband/portal.php?cari=" . urlencode($IDPEL) . "\n\n\nLink pembayaran :\nhttps://quenbytekniksejahtera.com/crm/billing/broadband/portal.php?cari=" . urlencode($IDPEL) . " \n\nYOUTUBE TUTORIAL CARA BAYAR : https://youtu.be/9Gvu4C2AkW4?si=1qMH1oKTRh0lB5EM \n\n*[ Abaikan pesan ini jika anda sudah membayar ]*\nDemikian yang dapat kami sampaikan terima kasih\nTerima kasih telah mempercayai kami dalam kebutuhan internet anda\nsalam $PEMILIK-$AREA\n";



                      // Baca file (auto-dibuat dgn template default kalau belum ada -- lihat
                      // notif_template_helper.php) dan ambil bagian REMAINDER. Guard
                      // "trim(...) === ''" di bawah dipertahankan sbg jaring pengaman
                      // (defense-in-depth) walau seharusnya sudah tidak pernah kosong lagi.
                      $isi = notifTemplateGetContent($pemilik);
                      $remainder_raw = notifTemplateExtractSection($isi, 'REMAINDER');
                      $remainder_parsed = replaceVariables($remainder_raw);

                      // Tambahkan salam pembuka dinamis untuk menghindari spam detection
                      $remainder_parsed = prependDynamicGreeting($remainder_parsed);

if (trim($remainder_parsed) === '') {
    echo "[DEBUG FIXED] SKIP $IDPEL: template REMAINDER kosong<br>";
    $cntSkipTemplateKosong++;
    $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] SKIP kirim WHATSAPP ke $NOWA: template pesan REMAINDER kosong/tidak ditemukan (cek file notifdata/$pemilik.txt & simpan ulang di menu Notifikasi -> Reminder Pembayaran)";
    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
} elseif (!$filterSkipNotifHariIni || !hasBeenNotified($history, 'Bot mengirim pesan WHATSAPP ke', $NOWA)) {
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
                    
                    $session = $currentBotName; // Nama sesi yang telah Anda buat
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
                    $url = "$currentWAAPI/send/message?session=$session"; // Endpoint dengan parameter sesi
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
                    $response_data = json_decode($response, true); // Konversi ke array
                    echo "[DEBUG WA GATEWAY] Kirim ke $NOWA | HTTP: $httpCode | cURL error: " . htmlspecialchars($curlErr) . " | Response: " . htmlspecialchars((string)$response) . "<br>";

                    // Increment target counter untuk distribusi ke bot berikutnya
                    if ($isRandomBot && $botPoolCount > 0) {
                        $targetIndex++;
                    }

                    // PENTING: sebelumnya baris "Bot mengirim pesan WHATSAPP ke ..." SELALU
                    // ditulis ke history apa pun hasil curl-nya (walau gagal/timeout/HTTP error).
                    // Akibatnya hasBeenNotified() di atas menganggap pelanggan ini SUDAH
                    // dikirimi reminder hari ini walau pesannya sebenarnya gagal terkirim,
                    // sehingga tidak pernah di-retry dan tidak ada jejak kegagalan di log.
                    $waOk = ($curlErr === '' && $httpCode >= 200 && $httpCode < 300);
                    if ($waOk) {
                        $cntTerkirim++;
                        $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Bot mengirim pesan WHATSAPP ke $NOWA dengan pesan $remainder_parsed | Bot: $currentBotName | HTTP: $httpCode";
                    } else {
                        $cntGagal++;
                        $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] GAGAL kirim WHATSAPP ke $NOWA | Bot: $currentBotName | HTTP: $httpCode | cURL error: $curlErr | Response: " . substr((string)$response, 0, 200);
                    }
                    // Simpan ke file history
                    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
} else {
    echo "[DEBUG FIXED] SKIP $IDPEL: sudah dinotif hari ini<br>";
    $cntSkipSudahNotifHariIni++;
}





//                     ///////////NOTIF BY EMAIL //////////////
//                     $mail = new PHPMailer;
//                     $mail->IsSMTP();
//                     $mail->SMTPSecure = '';
//                     $mail->Host = "mail.quenbytekniksejahtera.com"; //host masing2 provider email
//                     $mail->SMTPDebug = 2;
//                     $mail->Port = 25;
//                     $mail->SMTPAuth = false;
//                     $mail->Username = "helpdesk@quenbytekniksejahtera.com"; //user email
//                     $mail->Password = "helpdeskqts"; //password email 
//                     $mail->SetFrom("helpdesk@quenbytekniksejahtera.com", "FIBERQ"); //set email pengirim
//                     $mail->Subject = "Wifi anda dalam jatuh tempo"; //subyek email
//                     $mail->AddAddress($EMAIL, "$NAMA - $IDPEL ");  //tujuan email
//                     $mail->MsgHTML("Yth. Bapak / Ibu  $NAMA<br>
// Internet anda dalam jatuh tempo.<br>
// <br>                                                        
// Nama : $NAMA <br>
// ID pelanggan : $IDPEL <br>
// Paket Langganan : $PAKET <br>
// Harga per bulan : $harga <br>
// Alamat : $ALAMAT <br>
// No WHATSAPP : $NOWA <br>
// E Mail : $EMAIL <br>
// Tanggal aktif : $TANGGALPASANG <br>
// Tanggal jatuh tempo : $jatuh_tempo <br>
// Invoice : https://quenbytekniksejahtera.com/crm/billing/broadband/portal.php?cari=" . urlencode($IDPEL) . "<br>
// <br>                          
// -Link pembayaran :\nhttps://quenbytekniksejahtera.com/crm/billing/broadband/portal.php?cari=" . urlencode($IDPEL) . "<br>
// <br>
// - YOUTUBE TUTORIAL CARA BAYAR : https://youtu.be/9Gvu4C2AkW4?si=1qMH1oKTRh0lB5EM 
// Demikian yang dapat kami sampaikan terima kasih<br>
// Terima kasih telah mempercayai kami dalam kebutuhan internet anda<br>
// <br>
// <br>
// Informasi lanjut, hubungi Customer Service $PEMILIK WIFI di  +62 877-4031-7266 , email: 
// helpdesk@quenbytekniksejahtera.com, whatsapp +62 877-4031-7266. <br>
// <br>
// salam $PEMILIK $AREA  ");
//                     if ($mail->Send()) echo "Message has been sent";
//                     else echo "Failed to sending message";



//                     $history[] = "Bot mengirim pesan E-MAIL ke $EMAIL dengan pesan $text" . date('Y-m-d H:i:s');
//                     // Simpan ke file history
//                     file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

                    // Delay antar email agar tidak dianggap spam server
                    sleepAman();
                }
            }
        }
        }



        $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Selesai notif bot remainder semua pelanggan  $PEMILIK - $AREA ( PERIODE => $periode )";
        // Simpan ke file history
        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

        $text = "Notif bot remainder client $PEMILIK - $AREA ( PERIODE => $periode ) Selesai";
        $session = $botname; // Nama sesi yang telah Anda buat

        // Nomor tujuan dan pesan
        $phone = "120363046084927501@g.us"; // Format: nomor@s.whatsapp.net

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
        curl_close($ch);
        echo "[DEBUG WA GATEWAY] HTTP: $httpCode | Response: " . htmlspecialchars((string)$response) . "<br>";
    }


    $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Proses penagihan selesai";
    // Simpan ke file history
    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));



    $text = "Proses penagihan selesai ";
    $session = $botname; // Nama sesi yang telah Anda buat

    // Nomor tujuan dan pesan
    $phone = "120363046084927501@g.us"; // Format: nomor@s.whatsapp.net

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
    curl_close($ch);
    echo "[DEBUG WA GATEWAY] HTTP: $httpCode | Response: " . htmlspecialchars((string)$response) . "<br>";
} else {
    echo "<br> Belum waktu remainder pembayaran";
}

// ===================================================================
// REMINDER ROLLING (mengikuti_tanggal_bayar) & MONTHVERSARY
// Purpose: Blok Fixed Due Date di atas SENGAJA cuma jalan pada 1 tanggal
// kalender bersama ($tanggal_reminder, setting "Tanggal Reminder" di
// Payment Setting -> Konfigurasi Fixed Due Date) krn jatuh tempo mereka
// memang SATU tanggal yang sama utk semua pelanggan Fixed Due Date --
// itu TIDAK diubah, tetap ikut Payment Setting seperti sebelumnya.
//
// Rolling & Monthversary TIDAK begitu -- jatuh tempo tiap pelanggan
// berbeda-beda (siklus 30 hari dari tanggal bayar / anchor tanggal pasang
// masing-masing), jadi blok ini jalan TIAP HARI cron ini dipanggil (di
// LUAR gate $hariinitgl == $tanggal_reminder di atas), dan tiap pelanggan
// dicek H-berapa hari lagi jatuh tempo MASING-MASING (pakai field "hari
// sebelum" yang sama dgn setting Fixed Due Date), dgn fungsi yang SAMA
// persis dipakai tables.php/portal_bayar.php/cron generator
// (tagihanHitungJatuhTempoBerikutnya()/tagihanHitungStatus()).
// ===================================================================
$sqlServerRolling = "SELECT * FROM `server` WHERE `user_id` = '$iduser'";
$queryServerRolling = mysqli_query($conn, $sqlServerRolling);
while ($dataServerRolling = mysqli_fetch_array($queryServerRolling)) {
    $AREA_R = $dataServerRolling['AREA'];
    $PEMILIK_R = $dataServerRolling['PEMILIK'];

    $sqlPelRolling = "SELECT * FROM `pelanggan` WHERE `PEMILIK` = '$PEMILIK_R' AND `AREA` = '$AREA_R' AND LOWER(TRIM(COALESCE(TIPE_TEMPO,''))) IN ('mengikuti_tanggal_bayar','monthversary')";
    $queryPelRolling = mysqli_query($conn, $sqlPelRolling);
    $pelangganRollingList = [];
    while ($rowPelRolling = mysqli_fetch_array($queryPelRolling)) {
        $pelangganRollingList[] = $rowPelRolling;
    }

    if (empty($pelangganRollingList)) {
        continue;
    }

    if ($isRandomBot && $botPoolCount > 0) {
        shuffle($pelangganRollingList);
    }

    $idpelListRolling = array_map(static function ($row) {
        return (string) ($row['IDPEL'] ?? '');
    }, $pelangganRollingList);
    $lastPaymentMapRolling = tagihanGetLastPaymentsBulk($conn, $idpelListRolling);
    $lastPaidUsageMapRolling = tagihanGetLastPaidUsageMapBulk($conn, $idpelListRolling);
    $pendingPenagihanMapRolling = tagihanCekAdaPenagihanPendingBulk($conn, $idpelListRolling);

    foreach ($pelangganRollingList as $rowPelRolling) {
        $IDPEL = $rowPelRolling['IDPEL'];
        $NAMA = $rowPelRolling['NAMA'];
        $NOWA = $rowPelRolling['NOWA'];
        $EMAIL = $rowPelRolling['EMAIL'];
        $PAKET = $rowPelRolling['PAKET'];
        $TANGGALPASANG = $rowPelRolling['TANGGALPASANG'];
        $ALAMAT = $rowPelRolling['ALAMAT'];
        $TIPE_BAYAR = $rowPelRolling['TIPE_BAYAR'];
        $TIPE_TEMPO_NORM = strtolower(trim((string) ($rowPelRolling['TIPE_TEMPO'] ?? '')));
        echo "[DEBUG ROLLING] Proses pelanggan $IDPEL - $NAMA ($NOWA) | TIPE_TEMPO: $TIPE_TEMPO_NORM | PAKET: $PAKET<br>";
        if ($TIPE_TEMPO_NORM !== 'mengikuti_tanggal_bayar' && $TIPE_TEMPO_NORM !== 'monthversary') {
            continue;
        }

        if (stripos($PAKET, 'FREE') !== false) {
            echo "[DEBUG ROLLING] SKIP $IDPEL: paket FREE<br>";
            $cntSkipPaketFree++;
            continue;
        }

        $ctxRolling = [
            'hari_ini' => $cektanggal,
            'jatuh_tempo_hari' => (int) $jatuh_tempo,
            'lastPaymentMap' => $lastPaymentMapRolling,
            'lastPaidUsageMap' => $lastPaidUsageMapRolling,
            'prabayar_grace_period' => $prabayarGracePeriodRemainder,
            'monthversary_follow_last_payment' => $monthversaryFollowLastPaymentRemainder,
        ];

        $pelRollingArr = [
            'IDPEL' => $IDPEL,
            'TANGGALPASANG' => (string) $TANGGALPASANG,
            'TIPE_BAYAR' => (string) $TIPE_BAYAR,
            'TIPE_TEMPO' => $TIPE_TEMPO_NORM,
            'TEMPO' => (string) ($rowPelRolling['TEMPO'] ?? ''),
            'TANGGAL_MONTHVERSARY' => (string) ($rowPelRolling['TANGGAL_MONTHVERSARY'] ?? ''),
        ];

        $dueDateRolling = tagihanHitungJatuhTempoBerikutnya($conn, $pelRollingArr, $ctxRolling);
        if ($dueDateRolling === '' || strtotime($dueDateRolling) === false) {
            echo "[DEBUG ROLLING] SKIP $IDPEL: jatuh tempo tidak bisa dihitung<br>";
            $cntSkipJatuhTempoTdkTerhitung++;
            continue;
        }

        // H-berapa hari lagi jatuh tempo pelanggan ini -- pakai "hari_sebelum" yang
        // sama dgn setting Fixed Due Date (satu-satunya field itu di Payment Setting).
        $triggerDateRolling = date('Y-m-d', strtotime("-{$hari_sebelum} days", strtotime($dueDateRolling)));
        echo "[DEBUG ROLLING] $IDPEL jatuh tempo $dueDateRolling | trigger tanggal $triggerDateRolling | hari ini $cektanggal<br>";
        if ($cektanggal !== $triggerDateRolling) {
            continue;
        }

        $statusRolling = tagihanHitungStatus($conn, $pelRollingArr, $ctxRolling);
        $sudahBayarRolling = $statusRolling['sudah_bayar'];
        if ($sudahBayarRolling && !empty($pendingPenagihanMapRolling[$IDPEL])) {
            // Sama seperti blok Fixed Due Date: ada baris PENAGIHAN yang masih
            // menunggu bayar, jangan anggap lunas walau aritmetika siklus bilang lunas.
            $sudahBayarRolling = false;
        }
        if ($sudahBayarRolling && $filterSudahBayarReminder) {
            echo "[DEBUG ROLLING] SKIP $IDPEL: sudah bayar periode ini<br>";
            $cntSkipSudahBayar++;
            continue;
        } elseif ($sudahBayarRolling) {
            echo "[DEBUG ROLLING] $IDPEL: sudah bayar periode ini, TAPI filter 'Sudah Bayar' OFF -- tetap diproses<br>";
        }

        if ($filterSkipNotifHariIni && hasBeenNotified($history, 'Bot mengirim pesan WHATSAPP ke', $NOWA)) {
            echo "[DEBUG ROLLING] SKIP $IDPEL: sudah dinotif hari ini<br>";
            $cntSkipSudahNotifHariIni++;
            continue;
        }

        $url_cari = "https://$domain/crm/billing/broadband/portal.php?cari=" . urlencode($IDPEL);

        // Baca file (auto-dibuat dgn template default kalau belum ada -- lihat
        // notif_template_helper.php) dan ambil bagian REMAINDER.
        $isi = notifTemplateGetContent($pemilik);
        $remainder_raw = notifTemplateExtractSection($isi, 'REMAINDER');
        $remainder_parsed = replaceVariables($remainder_raw);
        $remainder_parsed = prependDynamicGreeting($remainder_parsed);

        if ($remainder_parsed === '') {
            echo "[DEBUG ROLLING] SKIP $IDPEL: template REMAINDER kosong<br>";
            $cntSkipTemplateKosong++;
            continue;
        }

        $currentBotName = $botname;
        $currentBotPass = $botpass;
        $currentWAAPI = $waapi;
        if ($isRandomBot && $botPoolCount > 0) {
            $currentBotConfig = $botPool[$targetIndex % $botPoolCount];
            $currentBotName = $currentBotConfig['namebot'];
            $currentBotPass = $currentBotConfig['password'];
            $currentWAAPI = $currentBotConfig['addressbot'];
        }

        $session = $currentBotName;
        $phone = "$NOWA@s.whatsapp.net";
        $dataSendRolling = [
            "phone" => $phone,
            "message" => $remainder_parsed
        ];
        $deviceId = trim((string) $sender);
        $urlSendRolling = "$currentWAAPI/send/message?session=$session";
        if ($deviceId !== '') {
            $urlSendRolling .= '&device_id=' . urlencode($deviceId);
        }
        $headersRolling = ["Content-Type: application/json"];
        if ($deviceId !== '') {
            $headersRolling[] = "X-Device-Id: $deviceId";
        }
        $chRolling = curl_init();
        curl_setopt($chRolling, CURLOPT_URL, $urlSendRolling);
        curl_setopt($chRolling, CURLOPT_POST, true);
        curl_setopt($chRolling, CURLOPT_POSTFIELDS, json_encode($dataSendRolling));
        curl_setopt($chRolling, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($chRolling, CURLOPT_HTTPHEADER, $headersRolling);
        curl_setopt($chRolling, CURLOPT_USERPWD, "$currentBotName:$currentBotPass");
        $responseRolling = curl_exec($chRolling);
        $httpCodeRolling = curl_getinfo($chRolling, CURLINFO_HTTP_CODE);
        $curlErrRolling = curl_error($chRolling);
        curl_close($chRolling);
        echo "[DEBUG WA GATEWAY] Kirim ke $NOWA (Rolling/Monthversary) | HTTP: $httpCodeRolling | cURL error: " . htmlspecialchars($curlErrRolling) . " | Response: " . htmlspecialchars((string)$responseRolling) . "<br>";

        if ($isRandomBot && $botPoolCount > 0) {
            $targetIndex++;
        }

        $waOkRolling = ($curlErrRolling === '' && $httpCodeRolling >= 200 && $httpCodeRolling < 300);
        if ($waOkRolling) {
            $cntTerkirim++;
            $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Bot mengirim pesan WHATSAPP ke $NOWA dengan pesan $remainder_parsed | Bot: $currentBotName | HTTP: $httpCodeRolling | Rolling/Monthversary, jatuh tempo $dueDateRolling";
        } else {
            $cntGagal++;
            $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] GAGAL kirim WHATSAPP ke $NOWA (Rolling/Monthversary) | Bot: $currentBotName | HTTP: $httpCodeRolling | cURL error: $curlErrRolling | Response: " . substr((string) $responseRolling, 0, 200);
        }
        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

        sleepAman();
    }
}

////////////////////////////////////////////////////////////////////
// RINGKASAN DEBUG -- rekap hasil proses reminder hari ini (Fixed Due Date +
// Rolling/Monthversary digabung), dicetak paling bawah supaya gampang dibaca
// pas testing manual lewat akses URL langsung. TIDAK menghitung pelanggan yang
// di-skip krn "belum waktunya"/tipe tempo beda (rutin & sangat banyak tiap hari,
// bukan indikasi masalah) -- cuma outcome yang actionable buat admin.
////////////////////////////////////////////////////////////////////
echo "<hr>";
echo "<h3>[RINGKASAN REMINDER]</h3>";
echo "Terkirim: $cntTerkirim<br>";
echo "Gagal kirim (error WA gateway): $cntGagal<br>";
echo "Skip - sudah bayar: $cntSkipSudahBayar<br>";
echo "Skip - sudah dinotif hari ini: $cntSkipSudahNotifHariIni<br>";
echo "Skip - template REMAINDER kosong: $cntSkipTemplateKosong<br>";
echo "Skip - paket FREE: $cntSkipPaketFree<br>";
echo "Skip - jatuh tempo tidak bisa dihitung (Rolling/Monthversary): $cntSkipJatuhTempoTdkTerhitung<br>";
$cntTotalRingkasan = $cntTerkirim + $cntGagal + $cntSkipSudahBayar + $cntSkipSudahNotifHariIni + $cntSkipTemplateKosong + $cntSkipPaketFree + $cntSkipJatuhTempoTdkTerhitung;
echo "Total pelanggan tercatat di ringkasan ini: $cntTotalRingkasan<br>";

$history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] RINGKASAN: Terkirim=$cntTerkirim | Gagal=$cntGagal | Skip sudah bayar=$cntSkipSudahBayar | Skip sudah dinotif=$cntSkipSudahNotifHariIni | Skip template kosong=$cntSkipTemplateKosong | Skip paket FREE=$cntSkipPaketFree | Skip jatuh tempo tak terhitung=$cntSkipJatuhTempoTdkTerhitung";
file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
