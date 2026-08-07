<?php
require '../koneksidb.php';
require '../routeros_api.class.php';
require "../notifbot/phpmailer/classes/class.phpmailer.php";
require_once '../notifbot/bot_selector_helper.php';
require_once __DIR__ . '/../radius_sync_lib.php';
require_once __DIR__ . '/../notifbot/notifphp/tagihan_status_lib.php';

header('Content-Type: application/json; charset=utf-8');


///////////////////////////////TANGGAL INDONESIA/////////////////////////////////////
    // ================= Fungsi =================
                                function getFreeradiusPID() {
                                    $pid = trim(shell_exec("pidof freeradius"));
                                    if($pid != '') return (int)$pid;
                                    $output = shell_exec("systemctl show -p MainPID freeradius");
                                    if (preg_match('/MainPID=(\d+)/', trim($output), $m)) {
                                        return (int)$m[1];
                                    }

                                    return 0;
                                }
                                $debug_file   = '/var/log/freeradius/debug-radius-web.log';

                                function restartFreeradius() {
                                    global $debug_file;
                                    $pid = getFreeradiusPID();
                                    if($pid>0){
                                        shell_exec('sudo systemctl stop freeradius');
                                        shell_exec("sudo kill -9 $pid");
                                    }
                                    if(file_exists($debug_file)) shell_exec("sudo rm -f $debug_file");
                                    shell_exec("sudo touch $debug_file");
                                    shell_exec("sudo chmod 666 $debug_file");
                                    shell_exec("sudo freeradius -X > $debug_file 2>&1 &");
                                }

function tanggal_indo2($tanggal, $cetak_hari = false)
{
    $hari = array(1 => 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu');
    $bulan = array(1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember');

    $split = explode('-', $tanggal);
    $tanggal_formatted = $split[2] . ' ' . $bulan[(int)$split[1]] . ' ' . $split[0];

    if ($cetak_hari) {
        $num_hari = date('N', strtotime($tanggal));
        return $hari[$num_hari] . ', ' . $tanggal_formatted;
    }

    return $tanggal_formatted;
}


$ptanggalskg2 = tanggal_indo2(date('Y-m-d'), true);
$tanggalbayar = $ptanggalskg2;


////////////////////////////////////////AMBIL PEMILIK CALLBACK//////////////////////////////////


$filename = basename(__FILE__); // contoh: hapus_kode_permintaan_bayar_FIBERQ.php
$nameOnly = pathinfo($filename, PATHINFO_FILENAME); // hapus_kode_permintaan_bayar_FIBERQ

$parts = explode('_', $nameOnly);

$ownerbilling = end($parts);

///////////////////////////DATA USERNAME/////////////////////////////////////////////////////////////





// Path ke file JSON
$jsonFile = "../notifbot/data/reminder-$ownerbilling.json";

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
            $tempo = $item['jatuh_tempo'];
            $hari_sebelum = $item['hari_sebelum'];
            $tanggal_reminder = $item['tanggal_reminder'];
            $botname = $item['botname'];
            $tanggal_awal_tutup_buku = $item['tanggal_awal_tutup_buku'];
            $tanggal_akhir_tutup_buku = $item['tanggal_akhir_tutup_buku'];
        }
    } else {
        // Error handling without echo
    }
} else {
    // File not found without echo
}

// Setting "Periode Tercatat" (Payment Setting -> Konfigurasi Fixed Due Date) --
// dipakai fallback label periode di bawah (tagihanFallbackPeriodeLabel()) kalau
// baris pending yang dilunasi ternyata tidak punya PENGUNAAN tersimpan.
$periode_tercatat_mode = tagihanLoadPeriodeTercatatMode($jsonFile);



/////////////////////////////////////////////////////////////////////////////////

// Cek apakah botname adalah 'random'
if (strtoupper($botname) == 'RANDOM') {
    // Ambil semua bot milik owner billing ini
    $sql1 = "SELECT * FROM `botwa` WHERE `pemilik` = '$ownerbilling'";
    $query1 = mysqli_query($conn, $sql1);
    
    if (mysqli_num_rows($query1) > 0) {
        // Simpan semua bot ke array
        $availableBots = [];
        while ($data1 = mysqli_fetch_array($query1)) {
            $availableBots[] = [
                'namebot' => $data1['namebot'],
                'addressbot' => $data1['addressbot'],
                'password' => $data1['password']
            ];
        }
        
        // Pilih bot secara acak
        $selectedBot = $availableBots[array_rand($availableBots)];
        $botname = $selectedBot['namebot'];
        $waapi = $selectedBot['addressbot'];
        $botpass = $selectedBot['password'];
        $sender = $selectedBot['sender'] ?? '';

        // Log untuk debug
        $history[] = "[ callback tripay - " . date('Y-m-d H:i:s') . " ] Bot RANDOM dipilih | SELECTED: $botname | WAAPI: $waapi | Total bots: " . count($availableBots);
        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    } else {
        // Tidak ada bot untuk owner ini
        $waapi = '';
        $botpass = '';
        $sender = '';
        $history[] = "[ callback tripay - " . date('Y-m-d H:i:s') . " ] WARNING: Tidak ada bot untuk pemilik '$ownerbilling'";
        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    }
} else {
    // Gunakan botname spesifik
    $sql1 = "SELECT * FROM `botwa` WHERE `namebot` = '$botname'";
    $query1 = mysqli_query($conn, $sql1);
    
    // Log query untuk debugging
    $history[] = "[ callback tripay - " . date('Y-m-d H:i:s') . " ] DEBUG: Query bot | SQL: $sql1 | Rows: " . mysqli_num_rows($query1);
    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

    if (mysqli_num_rows($query1) > 0) {
        while ($data1 = mysqli_fetch_array($query1)) {
            $waapi = $data1['addressbot'];
            $botpass = $data1['password'];
            $sender = $data1['sender'] ?? '';

            // Log detail data yang diambil
            $history[] = "[ callback tripay - " . date('Y-m-d H:i:s') . " ] DEBUG: Bot data | namebot: " . $data1['namebot'] . " | addressbot: " . ($data1['addressbot'] ?? 'NULL') . " | password: " . (empty($data1['password']) ? 'EMPTY' : 'SET');
            file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
        }
        // Log untuk debug
        $history[] = "[ callback tripay - " . date('Y-m-d H:i:s') . " ] Bot config loaded | BOTNAME: $botname | WAAPI: " . ($waapi ?? 'UNDEFINED') . " | WAAPI_EMPTY: " . (empty($waapi) ? 'YES' : 'NO');
        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    } else {
        // Jika bot tidak ditemukan
        $waapi = '';
        $botpass = '';
        $sender = '';
        $history[] = "[ callback tripay - " . date('Y-m-d H:i:s') . " ] WARNING: Bot '$botname' tidak ditemukan di table botwa";
        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    }
}
/////////////////////////////////////////////////////////////////////////////////






// Cek apakah sudah pernah dikirim
$history_file = "../notifbot/data/history-$ownerbilling.json";
$history = [];

if (file_exists($history_file)) {
    $history = json_decode(file_get_contents($history_file), true);
}

// Pastikan format history adalah array
if (!is_array($history)) {
    $history = [];
}

if (!function_exists('callbackLogStep')) {
    function callbackLogStep(&$history, $history_file, $step, $extra = '')
    {
        $provider = strtoupper((string)pathinfo(__FILE__, PATHINFO_FILENAME));
        $line = "[ callback " . strtolower($provider) . " - " . date('Y-m-d H:i:s') . " ] " . $step;
        if ($extra !== '') {
            $line .= ' | ' . $extra;
        }
        $history[] = $line;
        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    }
}

$reqMethod = isset($_SERVER['REQUEST_METHOD']) ? (string)$_SERVER['REQUEST_METHOD'] : '-';
$reqUri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '-';
callbackLogStep($history, $history_file, 'SESSION_START', $reqMethod . ' ' . $reqUri);

register_shutdown_function(function () use (&$history, $history_file) {
    $err = error_get_last();
    if ($err && in_array((int)$err['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR), true)) {
        callbackLogStep($history, $history_file, 'SESSION_FATAL', (string)($err['message'] ?? 'unknown fatal error'));
        return;
    }

    callbackLogStep($history, $history_file, 'SESSION_END', 'http=' . (string)http_response_code());
});

// Flag untuk mencegah duplikasi notifikasi dan log dalam satu proses
$notification_sent = false;
$log_recorded = false;

///////////////////////////////AMBIL IPAYMU AKUN ////////////////////////////////////////




$sql = "SELECT * FROM ipaymu WHERE pemilik='$ownerbilling'";
$query = mysqli_query($conn, $sql);
$dbNameResult = mysqli_query($conn, "SELECT DATABASE() AS db");
$dbNameRow = $dbNameResult ? mysqli_fetch_assoc($dbNameResult) : null;
$dbName = is_array($dbNameRow) ? ($dbNameRow['db'] ?? null) : null;

if ($query === false) {
    exit(json_encode([
        'success' => false,
        'message' => 'Gagal mengambil data ipaymu (query error)',
        'debug' => [
            'ownerbilling' => $ownerbilling,
            'db_selected' => $dbName,
            'sql' => $sql,
            'mysql_errno' => mysqli_errno($conn),
            'mysql_error' => mysqli_error($conn),
        ],
    ]));
}

$data = mysqli_fetch_array($query);
if (!$data) {
    exit(json_encode([
        'success' => false,
        'message' => 'Gagal mengambil data ipaymu (data tidak ditemukan)',
        'debug' => [
            'ownerbilling' => $ownerbilling,
            'db_selected' => $dbName,
            'sql' => $sql,
            'row_count' => mysqli_num_rows($query),
        ],
    ]));
}
$ipaymuVa = $data['va'];
$ipaymuApiKey = $data['api_key'];
$pajak = $data['pajak'];
$apihotspot = 'NO'; // iPaymu belum mendukung integrasi hotspot voucher

//////////////////////////////BACA PAYLOAD CALLBACK IPAYMU (unnotify)//////////////////////////////
// iPaymu TIDAK menandatangani body notify_url-nya, jadi payload mentah ini hanya dipakai untuk
// menemukan trx_id/reference_id. Status pembayaran sebenarnya baru dipercaya setelah dikonfirmasi
// ulang lewat Transaction Detail API (signed) di bawah, bukan dari field status pada body ini.
$jsontest = file_get_contents('php://input');
$arr = json_decode($jsontest, true);
if (!is_array($arr) || empty($arr)) {
    // iPaymu juga bisa mengirim sebagai application/x-www-form-urlencoded
    $arr = $_POST;
}
if (!is_array($arr) || empty($arr)) {
    exit(json_encode([
        'success' => false,
        'message' => 'Invalid callback payload',
    ]));
}

$ipaymuTrxId = (string)($arr['trx_id'] ?? '');
$ipaymuReferenceId = (string)($arr['reference_id'] ?? '');

if ($ipaymuTrxId === '') {
    exit(json_encode([
        'success' => false,
        'message' => 'trx_id tidak ditemukan pada callback',
    ]));
}

// ============ KIRIM RESPONSE HTTP 200 OK LANGSUNG KE IPAYMU ============

// Bersihkan semua output buffer yang mungkin berasal dari file include
while (ob_get_level() > 0) {
    ob_end_clean();
}

// Kirim header JSON
header('Content-Type: application/json; charset=utf-8');
http_response_code(200);

// Kirim response ke iPaymu
echo json_encode([
    'success' => true
], JSON_UNESCAPED_UNICODE);

// Pastikan response langsung terkirim
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    ignore_user_abort(true);
    @ob_flush();
    flush();
}

// ============ MULAI PROSES BERAT (ASYNC) ============
set_time_limit(300);

//////////////////////////////VERIFIKASI ULANG VIA IPAYMU TRANSACTION DETAIL API//////////////////////////////
// Signature iPaymu API v2: stringToSign = METHOD:va:sha256(body)_lowercase:apiKey, lalu HMAC-SHA256 dgn apiKey.
function ipaymu_signed_request($method, $url, $va, $apiKey, $bodyArray) {
    $body = json_encode($bodyArray, JSON_UNESCAPED_SLASHES);
    $bodyHash = strtolower(hash('sha256', $body));
    $stringToSign = strtoupper($method) . ':' . $va . ':' . $bodyHash . ':' . $apiKey;
    $signature = hash_hmac('sha256', $stringToSign, $apiKey);
    $timestamp = date('YmdHis');

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'va: ' . $va,
            'signature: ' . $signature,
            'timestamp: ' . $timestamp,
        ],
    ]);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($curlError) {
        return ['Success' => false, 'CurlError' => $curlError];
    }
    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : ['Success' => false, 'RawResponse' => $response];
}

$ipaymuDetail = ipaymu_signed_request(
    'POST',
    'https://my.ipaymu.com/api/v2/transaction',
    $ipaymuVa,
    $ipaymuApiKey,
    ['transactionId' => (int)$ipaymuTrxId]
);

callbackLogStep($history, $history_file, 'IPAYMU_DETAIL_CHECK', 'trx_id=' . $ipaymuTrxId . ' | response=' . json_encode($ipaymuDetail));

$ipaymuVerifiedData = $ipaymuDetail['Data'] ?? [];
$ipaymuVerifiedStatus = (string)($ipaymuVerifiedData['StatusDesc'] ?? $ipaymuVerifiedData['Status'] ?? '');
$ipaymuVerifiedReference = (string)($ipaymuVerifiedData['ReferenceId'] ?? '');
$ipaymuIsPaid = (
    isset($ipaymuDetail['Status']) && (int)$ipaymuDetail['Status'] === 1 &&
    (
        (int)($ipaymuVerifiedData['Status'] ?? -99) === 1 ||
        strtolower($ipaymuVerifiedStatus) === 'berhasil' ||
        strtolower($ipaymuVerifiedStatus) === 'success'
    )
);

//////////////////////////////NORMALISASI VARIABEL UNTUK LOGIKA PENANDAAN LUNAS//////////////////////////////
// BUKTI transaksi disimpan sebagai reference_id (dikontrol oleh kita saat create transaction di
// portal_bayar.php), BUKAN trx_id iPaymu. Cocokkan reference_id hasil verifikasi dengan yang ada di
// callback supaya trx_id yang di-spoof tidak bisa dipakai untuk melunaskan invoice orang lain.
$invoiceref = $ipaymuReferenceId !== '' ? $ipaymuReferenceId : $ipaymuTrxId;
$merchant_ref = $ipaymuReferenceId;
$amount = (float)($ipaymuVerifiedData['Amount'] ?? ($arr['amount'] ?? 0));
$payment_method = (string)($ipaymuVerifiedData['Via'] ?? ($arr['via'] ?? 'iPaymu'));
$payment_method_code = (string)($ipaymuVerifiedData['Channel'] ?? ($arr['channel'] ?? ''));
$customer_name = (string)($ipaymuVerifiedData['BuyerName'] ?? '');
$customer_phone = (string)($ipaymuVerifiedData['BuyerPhone'] ?? '');
$payment_link = '#';
$sku = '';

$referenceMatches = ($ipaymuVerifiedReference === '' || $ipaymuVerifiedReference === $ipaymuReferenceId);
$cekstatus = ($ipaymuIsPaid && $referenceMatches) ? 'PAID' : 'UNPAID';

$history[] = "[ callback ipaymu - " . date('Y-m-d H:i:s') . " ] iPaymu cek kode ref transaksi $invoiceref";
callbackLogStep($history, $history_file, 'IPAYMU_REF_CHECK', "Reference: $invoiceref | Status: " . $cekstatus);




//////////////////////////////CEK NO REF DI DATA BASE//////////////////////////////

// Initialize all variables to prevent undefined errors
$USERNAMETRANASAKSI = '';
$PEMILIK = '';
$PAKET = '';
$NAMATRANASAKSI = '';
$ID = '';
$AREAPELANGGAN = '';
$BRANDPELANGGAN = '';
$WHATSAPPELANGGAN = '';
$PAKETPELANGGAN = '';
$ALAMATPELANGGAN = '';
$AUTHMODE = '';
$NAMAPELANGGAN = '';
$EMAILPELANGGAN = '';
$ODPPELANGGAN = '';
$MODEPELANGGAN = '';

$sql10 = "SELECT * from `transaksi` WHERE `BUKTI` = '$invoiceref'  ";
$query10 = mysqli_query($conn, $sql10);

// Check if transaksi query returns results
if (mysqli_num_rows($query10) == 0) {
    // Transaksi tidak ditemukan
    callbackLogStep($history, $history_file, 'ERROR_TRANSAKSI_NOT_FOUND', "No transaksi record found for reference: $invoiceref");
    http_response_code(200);
    exit;
}

while ($data10 = mysqli_fetch_array($query10)) {
    $USERNAMETRANASAKSI = $data10['IDPEL'];
    $PEMILIK = $data10['PEMILIK'];
    $PAKET = $data10['PAKET'];
    $NAMATRANASAKSI = $data10['NAMA'];
    
    callbackLogStep($history, $history_file, 'TRANSAKSI_FOUND', "IDPEL: $USERNAMETRANASAKSI | PAKET: $PAKET");
    
    ////cek area pelanggan 
    $sql100 =  "SELECT * FROM pelanggan WHERE IDPEL='$USERNAMETRANASAKSI'";
    $query100 = mysqli_query($conn, $sql100);
    
    if (mysqli_num_rows($query100) == 0) {
        callbackLogStep($history, $history_file, 'ERROR_PELANGGAN_NOT_FOUND', "No pelanggan record for IDPEL: $USERNAMETRANASAKSI");
        http_response_code(200);
        exit;
    }
    
    while ($data100 = mysqli_fetch_array($query100)) {
        $ID = $data100['id'];
        $AREAPELANGGAN = $data100['AREA'];
        $BRANDPELANGGAN = $data100['BRAND'];
        $WHATSAPPELANGGAN = $data100['NOWA'];
        $PAKETPELANGGAN = $data100['PAKET'];
        $ALAMATPELANGGAN = $data100['ALAMAT'];
        $PASSWORDPELANGGAN = $data100['PASSWORD']; // password PPPoE asli pelanggan (dipakai untuk RADIUS, BUKAN password server/API)
        $AUTHMODE = $data100['MODE']; // SEBAGAI AUTH MODE -- dulu salah baca kolom HARGA, jadi cabang RADIUS/MULTI MODE nyaris tidak pernah jalan

// Daftar mode yang valid
$valid_modes = ['RADIUS MODE', 'API MODE', 'MULTI MODE'];

// Cek apakah input valid, jika tidak default ke 'API MODE'
if (!in_array($AUTHMODE, $valid_modes)) {
    $AUTHMODE = 'API MODE';
}
        $NAMAPELANGGAN = $data100['NAMA'];
        $EMAILPELANGGAN = $data100['EMAIL'];
        $ODPPELANGGAN = $data100['ODP'];
        $MODEPELANGGAN = $data100['MODE'];
        // Ditambahkan supaya fallback label periode di bawah bisa TIPE_TEMPO-aware
        // (tagihanFallbackPeriodeLabel()) -- lihat komentar "$pendingPengunaan".
        $TANGGALPASANGPELANGGAN = (string) ($data100['TANGGALPASANG'] ?? '');
        $TIPEBAYARPELANGGAN = (string) ($data100['TIPE_BAYAR'] ?? '');
        $TIPETEMPOPELANGGAN = (string) ($data100['TIPE_TEMPO'] ?? '');
        $TEMPOPELANGGAN = (string) ($data100['TEMPO'] ?? '');
        $TANGGALMONTHVERSARYPELANGGAN = (string) ($data100['TANGGAL_MONTHVERSARY'] ?? '');
    }
}

// PENGUNAAN baris pending (PERMINTAAN KODE) yang sedang dilunasi -- sudah TIPE_TEMPO-aware,
// diisi portal_bayar.php saat baris ini dibuat. Dipakai di bawah supaya periode yang
// tercatat saat status jadi BERHASIL SAMA dengan periode invoice yang sedang dibayar,
// bukan dihitung ulang lewat heuristik tanggal/tutup-buku di bawah.
$pendingPengunaan = trim((string)($data10['PENGUNAAN'] ?? ''));

/////////////////////CEK TRANSAKSI TERKAHIR UNTUK PELANGGAN BULANAN  /////////////////////
function getLastTransaction($idpel)
{
    global $conn;

    $query = "SELECT * FROM transaksi 
              WHERE IDPEL = '$idpel' 
              ORDER BY 
                RIGHT(PENGUNAAN, 4) DESC, 
                FIELD(LEFT(PENGUNAAN, LOCATE(' ', PENGUNAAN) - 1), 
                    'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 
                    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember') DESC 
              LIMIT 1";

    $result = mysqli_query($conn, $query);
    return mysqli_fetch_assoc($result);
}
function getMonthName($month, $year)
{
    $months = ["Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"];
    if ($month > 12) {
        $month = 1;
        $year++;
    }
    return $months[$month - 1] . ' ' . $year;
}

function calculateProrate($harga, $tempo, $currentDate)
{
    $daysInMonth = date('t', strtotime($currentDate));
    $remainingDays = max(0, $tempo - date('d', strtotime($currentDate)));
    $prorate = ($harga / $daysInMonth) * $remainingDays;
    return round($prorate, 2);
}

$idpel = $USERNAMETRANASAKSI;
$currentDate = date('d-m-Y');
$currentDay =  date('d');
$currentMonth = date('m');
$currentYear = date('Y');
$lastTransaction = getLastTransaction($idpel);





// Cek user dari tabel `user` berdasarkan `server`
$sql_paket = "SELECT * FROM `paket` WHERE `PAKET` LIKE '%$PAKETPELANGGAN%'";


$query_paket = mysqli_query($conn, $sql_paket);
$paket_data = mysqli_fetch_array($query_paket);

// Samakan semua nominal proses callback ke total amount dari Tripay.
$paketHarga = $amount;





$HARGAPELANGGAN = $amount;








$totalTagihan = 0;
$tagihanDetail = [];
$lastTransaction['PENGUNAAN'];
list($lastUsageMonthStr, $lastUsageYear) = explode(' ', $lastTransaction['PENGUNAAN']);
$lastUsageYear = (int)$lastUsageYear;
$months = ["Januari" => 1, "Februari" => 2, "Maret" => 3, "April" => 4, "Mei" => 5, "Juni" => 6, "Juli" => 7, "Agustus" => 8, "September" => 9, "Oktober" => 10, "November" => 11, "Desember" => 12];
$lastUsageMonth = $lastTransaction['PENGUNAAN'];
$lastUsageYear = (int)$lastUsageYear;
$nextmount = getMonthName($currentMonth + 1, $currentYear);


if (empty($lastUsageMonth) || empty($lastUsageYear)) {
    // Pelanggan baru, belum ada riwayat pembayaran
    if ($currentDay >= $tanggal_awal_tutup_buku && $currentDay <= $tanggal_akhir_tutup_buku) {
        // Tanggal 16Ã¢â‚¬â€œ24: prorate bulan ini + full bulan depan
        $prorate = calculateProrate($paketHarga, $tempo, $currentDate);
        $tagihanDetail[] = [
            'keterangan' => 'Tagihan Bulan ' . getMonthName($currentMonth, $currentYear) . ' (Prorate)',
            'harga' => $prorate
        ];
        $tagihanDetail[] = [
            'keterangan' => 'Tagihan Bulan ' . getMonthName($currentMonth + 1, $currentYear),
            'harga' => $paketHarga
        ];
        $totalTagihan += ($prorate + $paketHarga);
      
    } elseif ($currentDay >= 6 && $currentDay <= 15) {
        // Tanggal 6Ã¢â‚¬â€œ15: tagih prorate bulan ini sampai tanggal 24
        $prorate = calculateProrate($paketHarga, $tempo, $currentYear . '-' . $currentMonth . '-25');
        $tagihanDetail[] = [
            'keterangan' => 'Tagihan Bulan ' . getMonthName($currentMonth, $currentYear) . ' (Prorate sampai 24)',
            'harga' => $prorate
        ];
        $totalTagihan += $prorate;
      
    } elseif ($currentDay >= $tanggal_akhir_tutup_buku) {
        // Tanggal 25Ã¢â‚¬â€œakhir bulan: tagih full bulan depan
        $tagihanDetail[] = [
            'keterangan' => 'Tagihan Bulan ' . getMonthName($currentMonth + 1, $currentYear),
            'harga' => $paketHarga
        ];
        $totalTagihan += $paketHarga;
   
    } else {
        // Tanggal 1Ã¢â‚¬â€œ5: full bulan ini
        $tagihanDetail[] = [
            'keterangan' => 'Tagihan Bulan ' . getMonthName($currentMonth, $currentYear),
            'harga' => $paketHarga
        ];
        $totalTagihan += $paketHarga;
     
    }
} elseif (
    ($lastUsageYear == $currentYear && $lastUsageMonth == $currentMonth + 1) ||
    ($currentMonth == 12 && $lastUsageMonth == 1 && $lastUsageYear == $currentYear + 1)
) {
    // Sudah bayar bulan depan (misal: sekarang Juni, sudah bayar Juli)
    $bulanBerikutnya = $currentMonth + 2;
    $tahunBerikutnya = $currentYear;
    if ($bulanBerikutnya > 12) {
        $bulanBerikutnya = 1;
        $tahunBerikutnya++;
    }
    $tagihanDetail[] = [
        'keterangan' => 'Tagihan Bulan ' . getMonthName($bulanBerikutnya, $tahunBerikutnya),
        'harga' => $paketHarga
    ];
    $totalTagihan += $paketHarga;
  
} else {
    // Sudah pernah bayar bulan lalu atau bulan ini
    if ($currentDay >= $tanggal_awal_tutup_buku && $currentDay <= $tanggal_akhir_tutup_buku) {
        // Prorate bulan ini + bulan depan
        $prorate = calculateProrate($paketHarga, $tempo, $currentDate);
        $tagihanDetail[] = [
            'keterangan' => 'Tagihan Bulan ' . getMonthName($currentMonth, $currentYear) . ' (Prorate)',
            'harga' => $prorate
        ];
        $totalTagihan += ($prorate);
       
    } elseif ($currentDay >= 16 && $currentDay <= 24) {
        // Prorate bulan ini + bulan depan
        $prorate = calculateProrate($paketHarga, $tempo, $currentDate);
        $tagihanDetail[] = [
            'keterangan' => 'Tagihan Bulan ' . getMonthName($currentMonth, $currentYear) . ' (Prorate)',
            'harga' => $prorate
        ];
        $tagihanDetail[] = [
            'keterangan' => 'Tagihan Bulan ' . getMonthName($currentMonth + 1, $currentYear),
            'harga' => $paketHarga
        ];
        $totalTagihan += ($prorate + $paketHarga);
       
    } elseif ($currentDay >= $tanggal_akhir_tutup_buku) {
        // Hanya bulan depan
        $tagihanDetail[] = [
            'keterangan' => 'Tagihan Bulan ' . getMonthName($currentMonth + 1, $currentYear),
            'harga' => $paketHarga
        ];
        $totalTagihan += $paketHarga;
      
    } else {
        // Tanggal 1Ã¢â‚¬â€œ5
        $tagihanDetail[] = [
            'keterangan' => 'Tagihan Bulan ' . getMonthName($currentMonth, $currentYear),
            'harga' => $paketHarga
        ];
        $totalTagihan += $paketHarga;
        
    }
}


////SESUAIKAN HARGA DARI TRIPAY ///
$HARGAPELANGGAN = $amount;

// Tentukan periode berdasarkan jatuh_tempo dan hari pembayaran
// --- WAJIB: Ambil waktu sekarang agar getMonthName tidak kosong ---

$tglskg = date('d'); // 26
$cektanggal = date('Y-m-d');

// --- PAKSA JADI ANGKA (Agar perbandingan tidak ngaco) ---
$jatuh_tempo = (int)$jatuh_tempo; // 28
$tanggal_awal_tutup_buku = (int)$tanggal_awal_tutup_buku; // 20
$tanggal_akhir_tutup_buku = (int)$tanggal_akhir_tutup_buku; // 28

$ptanggalskg = getMonthName($currentMonth, $currentYear); // Maret 2026
$ptanggaberikut = getMonthName($currentMonth + 1, $currentYear); // April 2026
$ptanggalsebelum = getMonthName($currentMonth - 1, $currentYear); // Bulan lalu

// ==========================================
// LOGIKA PERIODE -- fallback TIPE_TEMPO-aware, dipakai HANYA kalau baris pending
// tidak punya PENGUNAAN tersimpan (lihat "$pendingPengunaan" di bawah).
// ==========================================
$periode = tagihanFallbackPeriodeLabel($conn, [
    'IDPEL' => $idpel,
    'TANGGALPASANG' => $TANGGALPASANGPELANGGAN ?? '',
    'TIPE_BAYAR' => $TIPEBAYARPELANGGAN ?? '',
    'TIPE_TEMPO' => $TIPETEMPOPELANGGAN ?? '',
    'TEMPO' => $TEMPOPELANGGAN ?? '',
    'TANGGAL_MONTHVERSARY' => $TANGGALMONTHVERSARYPELANGGAN ?? '',
], [
    'jatuh_tempo_hari' => (int) $jatuh_tempo,
    'lastPaymentMap' => tagihanGetLastPaymentsBulk($conn, [$idpel]),
    'lastPaidUsageMap' => tagihanGetLastPaidUsageMapBulk($conn, [$idpel]),
    'periode_tercatat_mode' => $periode_tercatat_mode,
]);
if ($periode === '') {
    $periode = $ptanggalskg;
}

// PENGUNAAN baris pending (PERMINTAAN KODE/invoice PENAGIHAN yang sedang dilunasi) SELALU
// menang atas fallback di atas -- baris itu sendiri sudah TIPE_TEMPO-aware
// (diisi portal_bayar.php dari invoice yang benar). Heuristik di atas cuma fallback utk baris
// yang kebetulan belum punya PENGUNAAN (mis. bukan dari portal_bayar.php).
if ($pendingPengunaan !== '') {
    $periode = $pendingPengunaan;
}








// Set tagihan sederhana tanpa prorate
$totalTagihan = $paketHarga;
$tagihanDetail = [
  [
    'keterangan' => 'Tagihan Bulan ' . $periode,
    'harga' => $paketHarga
  ]
];




//////////////////////////////CEK PAKET DARI DATA REF//////////////////////////////

// Validate PAKET value sebelum processing
if (empty($PAKET)) {
    callbackLogStep($history, $history_file, 'ERROR_PAKET_EMPTY', "PAKET variable is empty or not set | Reference: $invoiceref | IDPEL: $USERNAMETRANASAKSI");
    
    http_response_code(200);
    exit;
}

// Trim whitespace dan convert ke uppercase untuk konsistensi
$PAKET = trim(strtoupper($PAKET));

callbackLogStep($history, $history_file, 'DEBUG_PAKET_VALUE', "PAKET value (trimmed/uppercase): '$PAKET' | PAKET type: " . gettype($PAKET) . " | PAKET length: " . strlen($PAKET));



/// => PASTIKAN BUKAN TOPUP
if (!in_array($PAKET, ["TOPUP", "TOPUP_MITRA"])) {
    $LAYANAN = ""; // Initialize LAYANAN variable

    /// => APAKAH PAKET PPPOE ?
    $sql11 = "SELECT * from `paket` WHERE UPPER(PAKET) = '$PAKET' ";
    $query11 = mysqli_query($conn, $sql11);
    
    if ($query11 === false) {
        callbackLogStep($history, $history_file, 'SQL_ERROR_PAKET_PPPOE', "SQL Error: " . mysqli_error($conn) . " | Query: $sql11");
    } else {
        if (mysqli_num_rows($query11) > 0) {
            // Data ditemukan
            $LAYANAN = "PPPOE";
            callbackLogStep($history, $history_file, 'PAKET_FOUND_PPPOE', "Paket '$PAKET' ditemukan di tabel paket");
        }
    }

    /// => APAKAH PAKET HOTSPOT ?
    if (empty($LAYANAN)) { // Only check if not already found
        $sql111 = "SELECT * from `paket_hotspot` WHERE UPPER(paket) = '$PAKET' ";
        $query111 = mysqli_query($conn, $sql111);
        
        if ($query111 === false) {
            callbackLogStep($history, $history_file, 'SQL_ERROR_PAKET_HOTSPOT', "SQL Error: " . mysqli_error($conn) . " | Query: $sql111");
        } else {
            if (mysqli_num_rows($query111) > 0) {
                // Data ditemukan
                $LAYANAN = "HOTSPOT";
                callbackLogStep($history, $history_file, 'PAKET_FOUND_HOTSPOT', "Paket '$PAKET' ditemukan di tabel paket_hotspot");
            }
        }
    }

    /// => APAKAH PAKET VPN ?
    if (empty($LAYANAN)) { // Only check if not already found
        $sql112 = "SELECT * from `paket_vpn` WHERE UPPER(paket) = '$PAKET' ";
        $query112 = mysqli_query($conn, $sql112);
        
        if ($query112 === false) {
            callbackLogStep($history, $history_file, 'SQL_ERROR_PAKET_VPN', "SQL Error: " . mysqli_error($conn) . " | Query: $sql112");
        } else {
            if (mysqli_num_rows($query112) > 0) {
                // Data ditemukan
                $LAYANAN = "VPN";
                callbackLogStep($history, $history_file, 'PAKET_FOUND_VPN', "Paket '$PAKET' ditemukan di tabel paket_vpn");
            }
        }
    }

    // Handle EXPIRED or payment status issues only untuk non-TOPUP paket
if ($cekstatus == "EXPIRED") {
    
    // Use WHATSAPPELANGGAN yang sudah di-fetch dari pelanggan table
    $sendwa = (!empty($WHATSAPPELANGGAN) ? $WHATSAPPELANGGAN : $merchant_ref) . "@s.whatsapp.net";
    
    // Kirim notifikasi error
    $hargatampil = "Rp " . number_format($amount, 0, ',', '.');
    $text = "Kode pembayaran anda untuk pelanggan $merchant_ref dengan No Ref $invoiceref dengan detail $payment_method ( $payment_method_code ) sebesar $hargatampil kadaluarsa (EXPIRED) dan tidak dapat diproses. Silakan membuat kode baru di portal pelanggan. Terima kasih atas pengertiannya.";

    if (!empty($waapi) && !empty($botname) && !empty($botpass)) {
        $data = ["phone" => $sendwa, "message" => prependDynamicGreeting($text)];
        // device_id gowa multi-device (build gowa terbaru wajib X-Device-Id
        // header / device_id query di /send/message) = isi kolom sender apa adanya
        $deviceId = trim((string)($sender ?? ''));
        $url = "$waapi/send/message?session=" . urlencode($botname);
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
        curl_setopt($ch, CURLOPT_USERPWD, "$botname:$botpass");
        curl_exec($ch);
        curl_close($ch);
    }

    // Log error ke history
    callbackLogStep($history, $history_file, 'STATUS_EXPIRED', "Transaksi $invoiceref memiliki status EXPIRED - tidak akan diproses");

    http_response_code(200);
    exit;
}
else {

    if (empty($LAYANAN)) {
         $cekstatus = $cekstatus ?? "Gagal";
        
        // Log error ke history
        callbackLogStep($history, $history_file, 'ERROR_PAKET_NOT_FOUND_NON_PAID', 
            "Paket '$PAKET' tidak ditemukan di database | Reference: $invoiceref | Status: $cekstatus");

        // Kirim notifikasi error ke customer
        if (!empty($WHATSAPPELANGGAN)) {
            $sendwa = "$WHATSAPPELANGGAN@s.whatsapp.net";
            $text = "[GAGAL] Pembayaran status $cekstatus (Ref: $invoiceref) untuk paket '$PAKET' tidak ditemukan di database. Layanan tidak dapat diaktifkan. Mohon hubungi Admin untuk informasi lebih lanjut.";

            if (!empty($waapi) && !empty($botname) && !empty($botpass)) {
                $data = ["phone" => $sendwa, "message" => prependDynamicGreeting($text)];
                // device_id gowa multi-device (build gowa terbaru wajib X-Device-Id
                // header / device_id query di /send/message) = isi kolom sender apa adanya
                $deviceId = trim((string)($sender ?? ''));
                $url = "$waapi/send/message?session=" . urlencode($botname);
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
                curl_setopt($ch, CURLOPT_USERPWD, "$botname:$botpass");
                curl_exec($ch);
                curl_close($ch);
            }
        }
        
        http_response_code(200);
        exit;
    }
    }
} // END if (!in_array($PAKET, ["TOPUP", "TOPUP_MITRA"]))
else {
    // PAKET is TOPUP or TOPUP_MITRA
    $LAYANAN = $PAKET;
}


// Skip server/area check if PAKET is TOPUP or TOPUP_MITRA
if (!in_array($PAKET, ["TOPUP", "TOPUP_MITRA"])) {
    /// => CEK SERVER TERSEDIA UNTUK AREA PELANGGAN
    $sql_server = "SELECT * FROM `server` WHERE `BRAND`='$BRANDPELANGGAN' AND `AREA` ='$AREAPELANGGAN' ";
    $query_server = mysqli_query($conn, $sql_server);

    if (mysqli_num_rows($query_server) == 0) {
        // Log error ke history
        $history[] = "[ callback tripay - " . date('Y-m-d H:i:s') . " ] ERROR: Server tidak ditemukan untuk area '$AREAPELANGGAN' - transaksi $invoiceref";
        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

        // Update status transaksi menjadi GAGAL
        $sql_error = "UPDATE `transaksi` SET `STATUS`='GAGAL', `CEK`='SERVER TIDAK DITEMUKAN' WHERE `BUKTI`='$invoiceref'";
        $conn->query($sql_error);

        exit;
    }

    while ($data1 = mysqli_fetch_array($query_server)) {
        $user100 = $data1['PEMILIK'];
        $ip100 = $data1['IP'];
        $password100 = $data1['PASSWORD'];
        
    }
    $sekali = 0;
    $sekali = $sekali + 1;
}

    //////////////////////////////AKTIFKAN JIKA HOTSPOT//////////////////////////////
if ($cekstatus == "PAID") {
if ($LAYANAN == "PPPOE") {

           

            // Kirim notifikasi hanya sekali per proses
            if (!$notification_sent) {
                $notification_sent = true;

                // $session = $botname;
                $to = $WHATSAPPELANGGAN;
                $text = "[INI ADALAH PESAN OTOMATIS]\n*PEMBAYARAN BERHASIL*\n\nHai bpk/ibu $NAMAPELANGGAN \nPembayaran anda Telah kami terima.\n\n\n\nDengan detail :\n- ID Pelanggan : $USERNAMETRANASAKSI \n- Nama Pelanggan : $NAMAPELANGGAN \n- Paket langganan : $PAKETPELANGGAN \n- No Whatsapp : $WHATSAPPELANGGAN \n- E-mail : $EMAILPELANGGAN \n- Alamat : $ALAMATPELANGGAN \n\n\nData transaksi :\n- Periode pengunaan : $periode\n- Tanggal bayar : $tanggalbayar\n- Status INTERNET : AKTIF\n- Status Pembayaran : $cekstatus \n- Nominal Bayar : $amount \n- No Ref : $invoiceref \n- Id pelanggan : $USERNAMETRANASAKSI \n- Metode pembayaran : $payment_method \n- Kode metode : $payment_method_code\n\nDownload bukti pembayaran : https://quenbytekniksejahtera.com/crm/billing/riwayatTransaction.php?idpel=$USERNAMETRANASAKSI\n\nPastikan modem Anda dalam keadaan menyala normal dan tidak ada lampu indikator merah (LOS).\n\nJika dalam waktu 1 jam setelah notifikasi ini internet belum aktif,Silakan hubungi kami, atau cabut dan pasang kembali adaptor listrik modem Anda untuk mempercepat proses aktivasi.\n\nDemikian yang dapat kami sampaikan, terima kasih \n\nTerima kasih telah mempercayai kami dalam kebutuhan internet Anda\nSalam $BRANDPELANGGAN WIFI";

                $session = $botname; // Nama sesi yang telah Anda buat

                // Nomor tujuan dan pesan
                $phone = "$to@s.whatsapp.net"; // Format: nomor@s.whatsapp.net


                // Data JSON sesuai dengan dokumentasi API
                $data = [
                    "phone" => $phone,
                    "message" => prependDynamicGreeting($text)
                    // "reply_message_id" => "optional" // Opsional: ID pesan yang ingin dibalas
                ];

                // Inisialisasi cURL
                // device_id gowa multi-device (build gowa terbaru wajib X-Device-Id
                // header / device_id query di /send/message) = isi kolom sender apa adanya
                $deviceId = trim((string)$sender);
                $url = "$waapi/send/message?session=" . urlencode($botname);
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
                curl_setopt($ch, CURLOPT_USERPWD, "$botname:$botpass");

                // Eksekusi dan tangani respons
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);



                ///////////NOTIF BY EMAIL //////////////

                $mail = new PHPMailer;
                $mail->IsSMTP();
                $mail->SMTPSecure = '';
                $mail->Host = "quenbytekniksejahtera.com"; //host masing2 provider email
                $mail->SMTPDebug = false;
                $mail->Port = 25;
                $mail->SMTPAuth = false;
                $mail->Username = "helpdesk@quenbytekniksejahtera.com"; //user email
                $mail->Password = "deltaiman92"; //password email 
                $mail->SetFrom("helpdesk@quenbytekniksejahtera.com", "FIBERQ"); //set email pengirim
                $mail->Subject = "Pembayaran anda Telah kami terima"; //subyek email
                $mail->AddAddress($EMAILPELANGGAN, "$NAMAPELANGGAN - $USERNAMETRANASAKSI ");  //tujuan email
                $mail->MsgHTML("Yth. Bapak / Ibu  $NAMAPELANGGAN<br>
                                Pembayaran anda Telah kami terima.<br>
                                <br>                                                        
                                Nama : $NAMAPELANGGAN <br>
                                ID pelanggan : $USERNAMETRANASAKSI <br>
                                Paket Langganan : $PAKETPELANGGAN <br>
                                Harga per bulan : $HARGAPELANGGAN <br>
                                Alamat : $ALAMATPELANGGAN <br>
                                No WHATSAPP : $WHATSAPPELANGGAN <br>
                                E Mail : $EMAILPELANGGAN <br>
                                Tanggal aktif : -<br>
                                <br>
                                <br>
                                Data transaksi :<br>
                                - Periode pengunaan : $periode<br>
                                Tanggal bayar : $tanggalbayar<br>
                                - Status INTERNET : AKTIF<br>
                                - Status Pembayaran : $cekstatus<br>
                                - Nominal Bayar : $HARGAPELANGGAN <br>
                                - No Ref : $invoiceref <br>
                                - Id pelanggan : $USERNAMETRANASAKSI <br>
                                - Metode pembayaran : $payment_method <br>
                                - Kode metode : $payment_method_code<br>
                                <br><br>           
                                -Download bukti pembayaran : https://quenbytekniksejahtera.com/crm/billing/riwayatTransaction.php?idpel=$USERNAMETRANASAKSI<br>
                                <br>
                                Demikian yang dapat kami sampaikan terima kasih<br>
                                Terima kasih telah mempercayai kami dalam kebutuhan internet anda<br>
                                 <br>
                     Informasi lanjut, hubungi Customer Service $BRANDPELANGGAN WIFI di  +62 877-4031-7266 , email: 
                     helpdesk@quenbytekniksejahtera.com, whatsapp +62 877-4031-7266. <br>
                     <br>
                                salam $BRANDPELANGGAN ");
                                            
            }

            // Catat log hanya sekali per proses
            if (!$log_recorded) {
                $log_recorded = true;
                $responseLog = is_string($response) ? str_replace(array("\r", "\n"), ' ', $response) : '-';
                if ($responseLog === '') {
                    $responseLog = '-';
                }
                $history[] = "[ callback tripay - " . date('Y-m-d H:i:s') . " ] Tripay berhasil notif BOT wahtsapp dan email pelanggan | WAAPI: $waapi | BOTNAME: $botname | HTTP: $httpCode | CURL_ERROR: $curlError | BOT_RESPONSE: $responseLog | MSG: $text";
                // Simpan ke file history
                file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
            }

            $sql1 = "UPDATE `pelanggan` SET `IDPEL`='$USERNAMETRANASAKSI',`NAMA`='$NAMAPELANGGAN',`PAKET`='$PAKETPELANGGAN',`NOWA`='$WHATSAPPELANGGAN',`EMAIL`='$EMAILPELANGGAN',`MODE`='$MODEPELANGGAN',`ODP`='$ODPPELANGGAN' WHERE `id`='$ID'";
            if ($conn->query($sql1) === TRUE) {

                $query300 = mysqli_query($conn, "SELECT * FROM `transaksi` WHERE `IDPEL` ='$USERNAMETRANASAKSI' AND `PENGUNAAN`='$periode' AND `STATUS`='BERHASIL'");
                if ($query300->num_rows > 0) {

                    $sql12 = "DELETE FROM `transaksi` WHERE `IDPEL` ='$USERNAMETRANASAKSI' AND `PENGUNAAN`='$periode' AND `STATUS`='BERHASIL'";
                    if ($conn->query($sql12) === TRUE) {
                    }
                }

                $sql11 = "INSERT INTO `transaksi`( `TANGGALBAYAR`,`PENGUNAAN`, `IDPEL`, `NAMA`, `PAKET`, `HARGA`, `STATUS`, `BUKTI`,`PEMILIK`,`CEK`) VALUES ('$tanggalbayar','$periode','$USERNAMETRANASAKSI','$NAMAPELANGGAN','$PAKETPELANGGAN','$HARGAPELANGGAN','BERHASIL','$invoiceref','$user100','')";
                if ($conn->query($sql11) === TRUE) {

                    $sql12 = "DELETE FROM `transaksi` WHERE BUKTI='$invoiceref' AND `STATUS`='PERMINTAAN KODE'";
                    if ($conn->query($sql12) === TRUE) {
                    }

                    $sql_hapus_penagihan = "DELETE FROM `transaksi` WHERE `IDPEL`='$USERNAMETRANASAKSI' AND `PENGUNAAN`='$periode' AND UPPER(`STATUS`)='PENAGIHAN'";
                    $conn->query($sql_hapus_penagihan);


                    $history[] = "[ callback tripay - " . date('Y-m-d H:i:s') . " ] Tripay berhasil mencatat transaksi OTOMATIS  '$tanggalbayar','$periode','$USERNAMETRANASAKSI','$NAMAPELANGGAN','$PAKETPELANGGAN','$HARGAPELANGGAN','BERHASIL','$invoiceref','$user100',''";
                    // Simpan ke file history
                    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));







                                if($AUTHMODE=='API MODE' | $AUTHMODE=='MULTI MODE'  )
                                {


                                                    ////////koneksi ke mikrotik ///////
                                                    $API = new RouterosAPI();
                                                    $API->connect($ip100, $user100, $password100);

                                                    $cariurutan = $API->comm(
                                                        "/ppp/secret/getall",
                                                        array(
                                                            ".proplist" => ".id",
                                                            "?name" => $USERNAMETRANASAKSI,
                                                        )
                                                    );

                                                    $API->comm(
                                                        "/ppp/secret/set",
                                                        array(
                                                            ".id" => $cariurutan[0][".id"],
                                                            "comment"  => "LUNAS $NAMAPELANGGAN - $WHATSAPPELANGGAN - $tanggalbayar",
                                                            "profile"  => $PAKETPELANGGAN,
                                                        )
                                                    );


                                                    $cariurutan2 = $API->comm(
                                                        "/ppp/active/getall",
                                                        array(
                                                            ".proplist" => ".id",
                                                            "?name" => $USERNAMETRANASAKSI,
                                                        )
                                                    );

                                                    $API->comm(
                                                        "/ppp/active/remove",
                                                        array(
                                                            ".id" => $cariurutan2[0][".id"],

                                                        )
                                                    );

                                                    $history[] = "[ callback tripay - " . date('Y-m-d H:i:s') . " ] Tripay berhasil aktifkan OTOMATIS $USERNAMETRANASAKSI $NAMAPELANGGAN $PAKETPELANGGAN";
                                                    // Simpan ke file history
                                                    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));


                                                    
                              

                                }


                                if($AUTHMODE=='RADIUS MODE' | $AUTHMODE=='MULTI MODE'  )
                                {
                                                                                    ////////////////////RADIUS/////////////////
                                                                                    // Ditulis lewat radius_sync_lib.php (bukan regex mentah ke satu file
                                                                                    // saja) supaya konsisten dengan mirror authorize + managed-state, dan
                                                                                    // pakai password ASLI pelanggan ($PASSWORDPELANGGAN) -- versi lama di
                                                                                    // sini salah menulis Cleartext-Password := username itu sendiri.
                                                                                    $paket_q = mysqli_query($conn, "SELECT * FROM paket WHERE PAKET='" . mysqli_real_escape_string($conn, $PAKETPELANGGAN) . "' AND PEMILIK='" . mysqli_real_escape_string($conn, $user100) . "' ORDER BY id DESC LIMIT 1");
                                                                                    $paketRow = ($paket_q && mysqli_num_rows($paket_q) > 0) ? mysqli_fetch_assoc($paket_q) : ['PAKET' => $PAKETPELANGGAN, 'KECEPATAN' => ''];

                                                                                    if ($PASSWORDPELANGGAN === '' || $PASSWORDPELANGGAN === null) {
                                                                                        $history[] = "[ callback ipaymu - " . date('Y-m-d H:i:s') . " ] PERINGATAN: password PPPoE kosong untuk $USERNAMETRANASAKSI, RADIUS TIDAK diaktifkan otomatis.";
                                                                                    } else {
                                                                                        radiusSyncSingleCustomerNow($USERNAMETRANASAKSI, $PASSWORDPELANGGAN, $paketRow, true, radiusGetGlobalSettings($conn));
                                                                                    }

                                                                                $history[] = "[ callback ipaymu - " . date('Y-m-d H:i:s') . " ] Ipaymu berhasil aktifkan OTOMATIS $USERNAMETRANASAKSI $NAMAPELANGGAN $PAKETPELANGGAN";
                                                                                // Simpan ke file history
                                                                                file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));


                                }
                }
            }
http_response_code(200);
exit;
}





        //////////////////////////////AKTIFKAN JIKA PPPOE//////////////////////////////

if ($LAYANAN == "HOTPSOT") {

            $pecah = explode("-", $merchant_ref);
            if (count($pecah) < 5) {
                exit;
            }

            $kode = $pecah[0];
            $packages = $pecah[1];
            $limitUptime = $pecah[2];
            $nomor_telp = $pecah[3];
            $server = $pecah[4];
            $area = $pecah[5];

                                if ($apihotspot == "YES") {
                                    // JIKA API
                                    $API = new RouterosAPI();
                                    $sql1 = "SELECT * FROM `server` WHERE `PEMILIK`='$server' AND `AREA`='$area'";
                                    $query1 = mysqli_query($conn, $sql1);
                                    while ($data = mysqli_fetch_array($query1)) { // Perbaiki dari $query1 ke $query
                                        $user = $data['PEMILIK'];
                                        $area = $data['AREA'];
                                        $ip = $data['IP'];
                                        $password = $data['PASSWORD'];


                                        if ($API->connect($ip, $user, $password)) {
                                            $API->debug = false;

                                            // Tambahkan user ke MikroTik
                                            $response = $API->comm("/ip/hotspot/user/add", [
                                                "name" => $kode,
                                                "password" => $kode,
                                                "profile" => $packages,
                                                "limit-uptime" => $limitUptime
                                            ]);
                                        }
                                        if (isset($response['!trap'])) {
                                            $status = "tidak bisa di gunakan di server $user - $area";
                                        } else {
                                        }
                                    }
                                }




                                $sql2 = "SELECT * FROM paket_hotspot WHERE paket='$packages'";
                                $query2 = mysqli_query($conn, $sql2);
                                while ($data2 = mysqli_fetch_array($query2)) { // Perbaiki dari $query1 ke $query

                                }


                                function convertToSeconds($limitUptime)
                                {
                                    if (is_numeric($limitUptime)) {
                                        return $limitUptime;
                                    }

                                    if (preg_match('/^(\d+)([a-zA-Z]+)$/', $limitUptime, $matches)) {
                                        $value = $matches[1];
                                        $unit = strtolower($matches[2]);

                                        switch ($unit) {
                                            case 'h':
                                                return $value * 3600;
                                            case 'd':
                                                return $value * 86400;
                                            case 'w':
                                                return $value * 604800;
                                            case 'm':
                                                return $value * 2592000;
                                            default:
                                                exit;
                                        }
                                    }

                                    exit;
                                }






                                    // Kirim notifikasi WhatsApp hanya sekali per proses
                                    if (!$notification_sent) {
                                        $notification_sent = true;

                                        // Kirim notifikasi WhatsApp
                                        $status = "Aktif";
                                        $waapi = "http://quenbytekniksejahtera.com:415";
                                        $voucher_amount = "Rp" . number_format($amount, 0, ',', '.');

                                        $text = "Ã¢Å“â€¦ Pembayaran BerhasilÃ¢Å“â€¦\n\n"
                                            . "WhatsApp: $nomor_telp\n"
                                            . "Nominal: $voucher_amount\n"
                                            . "Metode: $payment_method\n"
                                            . "Invoice: $invoiceref\n"
                                            . "*KODE VOUCHER*: *$kode*\n\n";


                                            // Nomor tujuan dan pesan
                                            $phone = "$nomor_telp@s.whatsapp.net"; // Format: nomor@s.whatsapp.net


                                            // Data JSON sesuai dengan dokumentasi API
                                            $data = [
                                                "phone" => $phone,
                                                "message" => prependDynamicGreeting($text)
                                                // "reply_message_id" => "optional" // Opsional: ID pesan yang ingin dibalas
                                            ];

                                            // Inisialisasi cURL
                                            // device_id gowa multi-device (build gowa terbaru wajib X-Device-Id
                                            // header / device_id query di /send/message) = isi kolom sender apa adanya
                                            $deviceId = trim((string)$sender);
                                            $url = "$waapi/send/message?session=" . urlencode($botname);
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
                                            curl_setopt($ch, CURLOPT_USERPWD, "$botname:$botpass");

                                            // Eksekusi dan tangani respons
                                            $response = curl_exec($ch);
                                            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                                            curl_close($ch);
                                    }








                                    // Konversi uptime ke detik
                                    $uptime_seconds = convertToSeconds($limitUptime);

                                    // ==================== FORMAT USER FREERADIUS SEDERHANA ====================
                                    $user_entry = "$kode Cleartext-Password := \"$kode\"\n";
                                    $hotspotRateLimitQ = mysqli_query($conn, "SELECT ratelimit FROM paket_hotspot WHERE paket='" . mysqli_real_escape_string($conn, $packages) . "' LIMIT 1");
                                    $hotspotRateLimitRow = $hotspotRateLimitQ ? mysqli_fetch_assoc($hotspotRateLimitQ) : null;
                                    $hotspotRateLimit = trim((string)($hotspotRateLimitRow['ratelimit'] ?? ''));
                                    if ($hotspotRateLimit !== '') {
                                        $user_entry .= "\tMikrotik-Rate-Limit := \"$hotspotRateLimit\"\n";
                                    }
                                    $user_entry .= "\tSession-Timeout := $uptime_seconds\n";
                                    $user_entry .= "\tMikrotik-Group := \"$packages\"\n\n"; // ganti $namapaket sesuai kebutuhan

                                    // Simpan ke file users (pakai sudo)
                                    $cmd = "echo " . escapeshellarg($user_entry) . " | sudo tee -a /etc/freeradius/3.0/users > /dev/null";
                                    exec($cmd, $output, $return_var);

                                    // ==================== SIMPAN TIMER USER ====================
                                    $timer_data = [
                                        'username' => $kode,
                                        'session_timeout' => $uptime_seconds,
                                        'used_seconds' => 0,
                                        'last_check' => time()
                                    ];

                                    $timer_folder = "/etc/freeradius/user_timers";
                                    if (!file_exists($timer_folder)) {
                                        mkdir($timer_folder, 0777, true);
                                        chown($timer_folder, 'www-data');
                                        chgrp($timer_folder, 'www-data');
                                    }

                                    $timer_file = "$timer_folder/{$kode}.json";

                                    // Baca data lama jika ada
                                    $old_data = file_exists($timer_file) ? json_decode(file_get_contents($timer_file), true) : [];
                                    $merged_data = array_merge($old_data, $timer_data);

                                    // Simpan kembali
                                    file_put_contents($timer_file, json_encode($merged_data, JSON_PRETTY_PRINT));

                                    // ==================== RESTART FREERADIUS MENGGUNAKAN FUNGSI ====================
                                    if ($return_var === 0) {
                                        restartFreeradius();
                                       
                                        // Masukkan data ke database transaksi
                                        $sql = "INSERT INTO `transaksi`(`TANGGALBAYAR`,`STATUS`, `IDPEL`, `NAMA`, `PAKET`, `HARGA`, `BUKTI`, `CEK`, `PEMILIK`) 
                                                VALUES ('$tanggalbayar','BERHASIL','$USERNAMETRANASAKSI','$NAMAPELANGGAN','$PAKET','$voucher_amount','$invoiceref','', '$BRANDPELANGGAN')";
                                        $conn->query($sql);

                                        // Hapus transaksi permintaan kode lama
                                        $sql12 = "DELETE FROM `transaksi` WHERE BUKTI='$invoiceref' AND `STATUS`='PERMINTAAN KODE'";
                                        $conn->query($sql12);
                                    } else {

                                    }
http_response_code(200);
exit;
}





        //////////////////////////////AKTIFKAN JIKA VPN//////////////////////////////

if ($LAYANAN == "VPNQ") {
            $sql100 =  "SELECT * FROM server WHERE PEMILIK='$BRANDPELANGGAN' AND AREA ='$AREAPELANGGAN' ";
            $query100 = mysqli_query($conn, $sql100);
            while ($data1 = mysqli_fetch_array($query100)) {

                $user100 = $data1['PEMILIK'];
                $ip100 = $data1['IP'];
                $password100 = $data1['PASSWORD'];
                
            }

            $sql33 = "SELECT * FROM `transaksi` WHERE `STATUS`='BERHASIL' AND `IDPEL`='$USERNAMETRANASAKSI' and `BUKTI`='$invoiceref' ";
            $query33 = mysqli_query($conn, $sql33);
            $CEK = mysqli_num_rows($query33);



            if ($CEK == 0) {
                // Kirim notifikasi hanya sekali per proses
                if (!$notification_sent) {
                    $notification_sent = true;

                    $to = $WHATSAPPELANGGAN;
                    $text = "[INI ADALAH PESAN OTOMATIS]\n*PEMBAYARAN BERHASIL*\n\nHai bpk/ibu $NAMAPELANGGAN \nPembayaran anda Telah kami terima.\n\n\n\nDengan detail :\n- ID Pelanggan : $USERNAMETRANASAKSI \n- Nama Pelanggan : $NAMAPELANGGAN \n- Paket langganan : $PAKETPELANGGAN \n- No Whatsapp : $WHATSAPPELANGGAN \n- E-mail : $EMAILPELANGGAN \n- Alamat : $ALAMATPELANGGAN \n\n\nData transaksi :\n- Periode pengunaan : $periode\n- Tanggal bayar : $tanggalbayar\n- Status INTERNET : AKTIF\n- Status Pembayaran : $cekstatus \n- Nominal Bayar : $amount \n- No Ref : $invoiceref \n- Id pelanggan : $USERNAMETRANASAKSI \n- Metode pembayaran : $payment_method \n- Kode metode : $payment_method_code\n\nDownload bukti pembayaran : https://quenbytekniksejahtera.com/mybilling/cetakbukti.php?invoice=$invoiceref\n\nPastikan modem Anda dalam keadaan menyala normal dan tidak ada lampu indikator merah (LOS).\n\nJika dalam waktu 1 jam setelah notifikasi ini internet belum aktif,Silakan hubungi kami, atau cabut dan pasang kembali adaptor listrik modem Anda untuk mempercepat proses aktivasi.\n\nDemikian yang dapat kami sampaikan, terima kasih \n\nTerima kasih telah mempercayai kami dalam kebutuhan internet Anda\nSalam $BRANDPELANGGAN";

                    // Nomor tujuan dan pesan
                    $phone = "$to@s.whatsapp.net"; // Format: nomor@s.whatsapp.net

                    // Data JSON sesuai dengan dokumentasi API
                    $data = [
                        "phone" => $phone,
                        "message" => prependDynamicGreeting($text)
                        // "reply_message_id" => "optional" // Opsional: ID pesan yang ingin dibalas
                    ];

                    // Inisialisasi cURL
                    // device_id gowa multi-device (build gowa terbaru wajib X-Device-Id
                    // header / device_id query di /send/message) = isi kolom sender apa adanya
                    $deviceId = trim((string)$sender);
                    $url = "$waapi/send/message?session=" . urlencode($botname);
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
                    curl_setopt($ch, CURLOPT_USERPWD, "$botname:$botpass");

                    // Eksekusi dan tangani respons
                    $response = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);


                    ///////////NOTIF BY EMAIL //////////////

                    $mail = new PHPMailer;
                    $mail->IsSMTP();
                    $mail->SMTPSecure = '';
                    $mail->Host = "quenbytekniksejahtera.com"; //host masing2 provider email
                    $mail->SMTPDebug = false;
                    $mail->Port = 25;
                    $mail->SMTPAuth = false;
                    $mail->Username = "helpdesk@quenbytekniksejahtera.com"; //user email
                    $mail->Password = "deltaiman92"; //password email
                    $mail->SetFrom("helpdesk@quenbytekniksejahtera.com", "FIBERQ"); //set email pengirim
                    $mail->Subject = "Pembayaran anda Telah kami terima"; //subyek email
                    $mail->AddAddress($EMAILPELANGGAN, "$NAMAPELANGGAN - $USERNAMETRANASAKSI ");  //tujuan email
                    $mail->MsgHTML("Yth. Bapak / Ibu  $NAMAPELANGGAN<br>
                                            Pembayaran anda Telah kami terima.<br>
                                            <br>
                                            Nama : $NAMAPELANGGAN <br>
                                            ID pelanggan : $USERNAMETRANASAKSI <br>
                                            Paket Langganan : $PAKETPELANGGAN <br>
                                            Harga per bulan : $HARGAPELANGGAN <br>
                                            Alamat : $ALAMATPELANGGAN <br>
                                            No WHATSAPP : $WHATSAPPELANGGAN <br>
                                            E Mail : $EMAILPELANGGAN <br>
                                            Tanggal aktif : -<br>
                                            <br>
                                            <br>
                                            Data transaksi :<br>
                                            - Periode pengunaan : $periode<br>
                                            Tanggal bayar : $tanggalbayar<br>
                                            - Status INTERNET : AKTIF<br>
                                            - Status Pembayaran : $cekstatus<br>
                                            - Nominal Bayar : $HARGAPELANGGAN <br>
                                            - No Ref : $invoiceref <br>
                                            - Id pelanggan : $USERNAMETRANASAKSI <br>
                                            - Metode pembayaran : $payment_method <br>
                                            - Kode metode : $payment_method_code<br>
                                            <br><br>
                                            -Download bukti pembayaran : https://quenbytekniksejahtera.com/mybilling/cetakbukti.php?invoice=$invoiceref<br>
                                            <br>
                                            Demikian yang dapat kami sampaikan terima kasih<br>
                                            Terima kasih telah mempercayai kami dalam kebutuhan internet anda<br>
                                             <br>
                                 Informasi lanjut, hubungi Customer Service FIBERQ WIFI di  +62 877-4031-7266 , email:
                                 helpdesk@quenbytekniksejahtera.com, whatsapp +62 877-4031-7266. <br>
                                 <br>
                                            salam FIBERQ ");
                }
            }


            $sql1 = "UPDATE `pelanggan` SET `IDPEL`='$USERNAMETRANASAKSI',`NAMA`='$NAMAPELANGGAN',`PAKET`='$PAKETPELANGGAN',`NOWA`='$WHATSAPPELANGGAN',`EMAIL`='$EMAILPELANGGAN',`MODE`='$MODEPELANGGAN',`ODP`='$ODPPELANGGAN' WHERE `id`='$ID'";
            if ($conn->query($sql1) === TRUE) {

                $query300 = mysqli_query($conn, "SELECT * FROM `transaksi` WHERE `IDPEL` ='$USERNAMETRANASAKSI' AND `PENGUNAAN`='$periode' AND `STATUS`='BERHASIL'");
                if ($query300->num_rows > 0) {

                    $sql12 = "DELETE FROM `transaksi` WHERE `IDPEL` ='$USERNAMETRANASAKSI' AND `PENGUNAAN`='$periode' AND `STATUS`='BERHASIL'";
                    if ($conn->query($sql12) === TRUE) {
                    }
                }

                $sql11 = "INSERT INTO `transaksi`( `TANGGALBAYAR`,`PENGUNAAN`, `IDPEL`, `NAMA`, `PAKET`, `HARGA`, `STATUS`, `BUKTI`,`PEMILIK`,`CEK`) VALUES ('$tanggalbayar','$periode','$USERNAMETRANASAKSI','$NAMAPELANGGAN','$PAKETPELANGGAN','$HARGAPELANGGAN','BERHASIL','$invoiceref','$BRANDPELANGGAN','')";
                if ($conn->query($sql11) === TRUE) {

                    $sql12 = "DELETE FROM `transaksi` WHERE `STATUS`='PERMINTAAN KODE'";
                    if ($conn->query($sql12) === TRUE) {
                    }

                    $sql_hapus_penagihan = "DELETE FROM `transaksi` WHERE `IDPEL`='$USERNAMETRANASAKSI' AND `PENGUNAAN`='$periode' AND UPPER(`STATUS`)='PENAGIHAN'";
                    $conn->query($sql_hapus_penagihan);



                    ////////koneksi ke mikrotik ///////
                    $API = new RouterosAPI();
                    $API->connect($ip100, $user100, $password100);

                    $cariurutan = $API->comm(
                        "/ppp/secret/getall",
                        array(
                            ".proplist" => ".id",
                            "?name" => $USERNAMETRANASAKSI,
                        )
                    );

                    $API->comm(
                        "/ppp/secret/set",
                        array(
                            ".id" => $cariurutan[0][".id"],
                            "comment"  => "LUNAS $NAMAPELANGGAN - $WHATSAPPELANGGAN - $tanggalbayar",
                            "profile"  => $PAKETPELANGGAN,
                            "service"  => "l2tp",
                        )
                    );


                    $cariurutan2 = $API->comm(
                        "/ppp/active/getall",
                        array(
                            ".proplist" => ".id",
                            "?name" => $USERNAMETRANASAKSI,
                        )
                    );

                    if (!empty($cariurutan2)) {
                        $API->comm(
                            "/ppp/active/remove",
                            array(
                                ".id" => $cariurutan2[0][".id"],
                            )
                        );
                    }
                }
            }
http_response_code(200);
exit;
}





        //////////////////////////////AKTIFKAN JIKA TOPUP//////////////////////////////



        // Pastikan $sekali diinisialisasi
        if (!isset($sekali)) $sekali = 1;
        if ($LAYANAN == "TOPUP") {



            $sql11 = "SELECT * FROM `user` WHERE `USERNAME`='$USERNAMETRANASAKSI' ";
            $query11 = mysqli_query($conn, $sql11);
            while ($data11 = mysqli_fetch_array($query11)) {
                $NOWAUSER = $data11['NOWA'];
                $SALDOUSER = $data11['saldo'];
            }
            $ditambahkan = $SALDOUSER + $amount;

            $sql10 = "UPDATE `user` SET `saldo`='$ditambahkan' WHERE `USERNAME`='$USERNAMETRANASAKSI'";
            if ($conn->query($sql10) === TRUE) {



                if (!$notification_sent) {
                    $notification_sent = true;
                    $sekali = $sekali + 1;

                    // $session = $botname;
                    $to = $NOWAUSER;
                    $text = "[INI ADALAH PESAN OTOMATIS]\n*TOPUP SALDO BERHASIL*\n\nHai bpk/ibu $USERNAMETRANASAKSI \nPembayaran anda Telah kami terima.\n\n*PEMBAYARAN BERHASIL*\n\nDengan detail :\n-Nama Pelanggan : $USERNAMETRANASAKSI \n-No Whatsapp : $NOWAUSER \n\n\nData transaksi : \n- Tanggal bayar : $tanggalbayar\n- SALDO : $ditambahkan\n- Status Pembayaran : $cekstatus \n- Nominal TOPUP : $amount \n- No Ref : $invoiceref\n- Id pelanggan : $USERNAMETRANASAKSI \n- Metode pembayaran : $payment_method \n- Kode metode : $payment_method_code\n\njika 1 jam dari notifikasi ini masih belum masuk saldo topup silahkan hubungi kami.\n\ndemikian yang dapat kami sampaikan terima kasih\nTerima kasih telah mempercayai kami dalam kebutuhan internet anda\nsalam VPNQ Powered PT. QUENBY TEKNIK SEJAHTERA";

                    $session = $botname; // Nama sesi yang telah Anda buat

                    // Nomor tujuan dan pesan
                    $phone = "$NOWAUSER@s.whatsapp.net"; // Format: nomor@s.whatsapp.net


                    // Data JSON sesuai dengan dokumentasi API
                    $data = [
                        "phone" => $phone,
                        "message" => prependDynamicGreeting($text)
                        // "reply_message_id" => "optional" // Opsional: ID pesan yang ingin dibalas
                    ];
                    // Inisialisasi cURL
                    // device_id gowa multi-device (build gowa terbaru wajib X-Device-Id
                    // header / device_id query di /send/message) = isi kolom sender apa adanya
                    $deviceId = trim((string)$sender);
                    $url = "$waapi/send/message?session=" . urlencode($botname);
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
                    curl_setopt($ch, CURLOPT_USERPWD, "$botname:$botpass");

                    // Eksekusi dan tangani respons
                    $response = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);



                    $sql13 = "UPDATE `transaksi` SET `STATUS`='BERHASIL' , `TANGGALBAYAR`='$tanggalbayar', `CEK`=''  WHERE `BUKTI`='$invoiceref' ";
                    if ($conn->query($sql13) === TRUE) {
                        http_response_code(200);
                        exit;
                    } else {
                        // Log error jika update gagal
                        $history[] = "[ callback tripay - " . date('Y-m-d H:i:s') . " ] ERROR: Gagal update transaksi topup $invoiceref - " . $conn->error;
                        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
                        // 3. Berikan respon error
                        exit;
                    }
                }
            }
        }









        //////////////////////////////AKTIFKAN JIKA TOPUP MITRA//////////////////////////////


        if ($LAYANAN == "TOPUP_MITRA") {



            $sql11 = "SELECT * FROM `mitra` WHERE `nama`='$USERNAMETRANASAKSI' ";
            $query11 = mysqli_query($conn, $sql11);
            while ($data11 = mysqli_fetch_array($query11)) {
                $NOWAUSER = $data11['whatsapp'];
                $SALDOUSER = $data11['saldo'];
            }
            $ditambahkan = $SALDOUSER + $amount;

            $sql10 = "UPDATE `mitra` SET `saldo`='$ditambahkan' WHERE `nama`='$USERNAMETRANASAKSI'";
            if ($conn->query($sql10) === TRUE) {



                if (!$notification_sent) {
                    $notification_sent = true;
                    $sekali = $sekali + 1;

                    // $session = $botname;
                    $to = $NOWAUSER;
                    $text = "[INI ADALAH PESAN OTOMATIS]\n*TOPUP SALDO MITRA SALES BERHASIL*\n\nHai bpk/ibu $USERNAMETRANASAKSI \nPembayaran anda Telah kami terima.\n\n*PEMBAYARAN BERHASIL*\n\nDengan detail :\n-Nama Pelanggan : $USERNAMETRANASAKSI \n-No Whatsapp : $NOWAUSER \n\n\nData transaksi : \n- Tanggal bayar : $tanggalbayar\n- SALDO : $ditambahkan\n- Status Pembayaran : $cekstatus \n- Nominal TOPUP : $amount \n- No Ref : $invoiceref\n- Id pelanggan : $USERNAMETRANASAKSI \n- Metode pembayaran : $payment_method \n- Kode metode : $payment_method_code\n\njika 1 jam dari notifikasi ini masih belum masuk saldo topup silahkan hubungi kami.\n\ndemikian yang dapat kami sampaikan terima kasih\nTerima kasih telah mempercayai kami dalam kebutuhan internet anda\nsalam  PT. QUENBY TEKNIK SEJAHTERA";

                    $session = $botname; // Nama sesi yang telah Anda buat

                    // Nomor tujuan dan pesan
                    $phone = "$NOWAUSER@s.whatsapp.net"; // Format: nomor@s.whatsapp.net


                    // Data JSON sesuai dengan dokumentasi API
                    $data = [
                        "phone" => $phone,
                        "message" => prependDynamicGreeting($text)
                        // "reply_message_id" => "optional" // Opsional: ID pesan yang ingin dibalas
                    ];

                    // Inisialisasi cURL
                    // device_id gowa multi-device (build gowa terbaru wajib X-Device-Id
                    // header / device_id query di /send/message) = isi kolom sender apa adanya
                    $deviceId = trim((string)$sender);
                    $url = "$waapi/send/message?session=" . urlencode($botname);
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
                    curl_setopt($ch, CURLOPT_USERPWD, "$botname:$botpass");

                    // Eksekusi dan tangani respons
                    $response = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);




                    $sql13 = "UPDATE `transaksi` SET `STATUS`='BERHASIL' , `TANGGALBAYAR`='$tanggalbayar', `CEK`='' WHERE `BUKTI`='$invoiceref' ";
                    if ($conn->query($sql13) === TRUE) {
                        http_response_code(200);
                        header('Content-Type: application/json');
// Response sudah dikirim di awal callback, hanya log saja di sini
            $history[] = "[ callback tripay - " . date('Y-m-d H:i:s') . " ] Background processing PPPOE selesai untuk $USERNAMETRANASAKSI";
            file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

                    }
                }
            }
        }
    }



