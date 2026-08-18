


<?php
require '../koneksidb.php';
require '../routeros_api.class.php';
require "../notifbot/phpmailer/classes/class.phpmailer.php";
require_once '../notifbot/bot_selector_helper.php';
require_once __DIR__ . '/../radius_sync_lib.php';
require_once __DIR__ . '/../notifbot/notifphp/tagihan_status_lib.php';
require_once __DIR__ . '/../notifbot/notif_template_helper.php';

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

$debug_file = '/var/log/freeradius/debug-radius-web.log';

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

function tanggal_indo2($tanggal, $cetak_hari = false) {
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
$filename = basename(__FILE__); // contoh: callback_duitku_FIBERQ.php
$nameOnly = pathinfo($filename, PATHINFO_FILENAME); // callback_duitku_FIBERQ
$parts = explode('_', $nameOnly);
$BRANDPELANGGAN = end($parts); // ambil bagian terakhir

echo "Bagian terakhir dari nama file: $BRANDPELANGGAN ";

///////////////////////////DATA USERNAME/////////////////////////////////////////////////////////////
echo $sql99 = "SELECT * FROM `user` WHERE `server` like '$BRANDPELANGGAN' ";
$query99 = mysqli_query($conn, $sql99);
while ($data99 = mysqli_fetch_array($query99)) {
    $username = $data99['USERNAME'];
}

// Path ke file JSON
$jsonFile = "../notifbot/data/reminder-$username.json";

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
        echo "Error: Gagal mendecode JSON.";
    }
} else {
    echo "Error: File JSON tidak ditemukan.dengan username $username";
}

// Setting "Periode Tercatat" (Payment Setting -> Konfigurasi Fixed Due Date) --
// dipakai fallback label periode di bawah (tagihanFallbackPeriodeLabel()) kalau
// baris pending yang dilunasi ternyata tidak punya PENGUNAAN tersimpan.
$periode_tercatat_mode = tagihanLoadPeriodeTercatatMode($jsonFile);

/////////////////////////////////////////////////////////////////////////////////
// Cek apakah botname adalah 'random'
if (strtoupper($botname) == 'RANDOM') {
    $sql1 = "SELECT * FROM `botwa` WHERE `pemilik` = '$username'";
    $query1 = mysqli_query($conn, $sql1);
    if (mysqli_num_rows($query1) > 0) {
        $availableBots = [];
        while ($data1 = mysqli_fetch_array($query1)) {
            $availableBots[] = ['namebot' => $data1['namebot'], 'addressbot' => $data1['addressbot'], 'password' => $data1['botpass']];
        }
        $selectedBot = $availableBots[array_rand($availableBots)];
        $botname = $selectedBot['namebot']; $waapi = $selectedBot['addressbot']; $botpass = $selectedBot['password'];
        $sender = $selectedBot['sender'] ?? '';
    } else { $waapi = ''; $botpass = ''; $sender = ''; }
} else {
    $sql1 = "SELECT * FROM `botwa` WHERE `namebot` = '$botname'";
    $query1 = mysqli_query($conn, $sql1);
    if (mysqli_num_rows($query1) > 0) {
        while ($data1 = mysqli_fetch_array($query1)) { $waapi = $data1['addressbot']; $botpass = $data1['botpass']; $sender = $data1['sender'] ?? ''; }
    } else { $waapi = ''; $botpass = ''; $sender = ''; }
}

// Cek apakah sudah pernah dikirim
$history_file = "../notifbot/data/history-$username.json";
$history = [];

if (file_exists($history_file)) {
    $history = json_decode(file_get_contents($history_file), true);
}

// Pastikan format history adalah array
if (!is_array($history)) {
    $history = [];
}

///////////////////////////////AMBIL DUITKU AKUN ////////////////////////////////////////
$sql = "SELECT * FROM duitku WHERE pemilik='$BRANDPELANGGAN'";
$query = mysqli_query($conn, $sql);
$data = mysqli_fetch_array($query);
if (!$data) {
    die(json_encode(["status" => "error", "message" => "Gagal mengambil data duitku"]));
}
$merchantCode = $data['merchant_code'];
$apiKey = $data['api_key'];
$pajak = $data['pajak'];
$authMode = $data['default_auth_mode'] ?? 'RADIUS MODE';

//////////////////////////////VERIFIKASI DUITKU//////////////////////////////
// 1. Baca data callback dari Duitku
$jsontest = file_get_contents('php://input');
$callbackData = json_decode($jsontest, true);

if (!is_array($callbackData)) {
    die(json_encode(["status" => "error", "message" => "Invalid JSON data"]));
}

// 2. Ambil data dari callback Duitku
echo "=> CEK DATA DARI DUITKU CALLBACK =>";
$merchant_order_id = $callbackData["merchantOrderId"] ?? '';
$amount = (float)($callbackData["amount"] ?? 0);
$status_code = $callbackData["statusCode"] ?? '';
$status_message = $callbackData["statusMessage"] ?? '';

// Status sukses di Duitku biasanya statusCode = "00"
$cekstatus = ($status_code == "00") ? "PAID" : "UNPAID";

echo "Merchant Order ID: $merchant_order_id, Amount: $amount, Status: $cekstatus";

$history[] = "[ callback duitku - " . date('Y-m-d H:i:s') . " ] Duitku cek kode ref transaksi $merchant_order_id";
// Simpan ke file history
file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

//////////////////////////////CEK NO REF DI DATA BASE//////////////////////////////
$sql10 = "SELECT * from `transaksi` WHERE `BUKTI` = '$merchant_order_id' ";
$query10 = mysqli_query($conn, $sql10);
while ($data10 = mysqli_fetch_array($query10)) {
    echo "=> CEK DATA BASE TRANSAKSI =>";
    echo $USERNAMETRANASAKSI = $data10['IDPEL'];
    echo "=>";
    echo $PEMILIK = $data10['PEMILIK'];
    echo "=>";
    echo $PAKET = $data10['PAKET'];
    echo "=>";
    echo $NAMATRANASAKSI = $data10['NAMA'];
    
    ////cek area pelanggan 
    $sql100 =  "SELECT * FROM pelanggan WHERE IDPEL='$USERNAMETRANASAKSI'";
    $query100 = mysqli_query($conn, $sql100);
    while ($data100 = mysqli_fetch_array($query100)) {
        echo "=>";
        echo  $ID = $data100['id'];
        echo "=>";
        echo  $AREAPELANGGAN = $data100['AREA'];
        echo "=>";
        echo  $WHATSAPPELANGGAN = $data100['NOWA'];
        echo "=>";
        echo  $PAKETPELANGGAN = $data100['PAKET'];
        echo "=>";
        echo  $ALAMATPELANGGAN = $data100['ALAMAT'];
        echo "=>";
        $PASSWORDPELANGGAN = $data100['PASSWORD']; // password PPPoE asli pelanggan (dipakai untuk RADIUS, BUKAN password server/API)
        echo "<br>";
        
        // Gunakan auth mode dari konfigurasi Duitku
        $AUTHMODE = $data100['MODE']; // dulu pakai duitku.default_auth_mode (per-tenant), sekarang konsisten baca MODE pelanggan langsung seperti gateway lain
        
        // Daftar mode yang valid
        $valid_modes = ['RADIUS MODE', 'API MODE', 'MULTI MODE'];
        
        // Cek apakah input valid, jika tidak default ke 'API MODE'
        if (!in_array($AUTHMODE, $valid_modes)) {
            $AUTHMODE = 'API MODE';
        }
        
        echo  $NAMAPELANGGAN = $data100['NAMA'];
        echo "=>";
        echo  $EMAILPELANGGAN = $data100['EMAIL'];
        echo "=>";
        echo  $ODPPELANGGAN = $data100['ODP'];
        echo "=>";
        echo  $MODEPELANGGAN = $data100['MODE'];
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

/////////////////////CEK TRANSAKSI TERAKHIR UNTUK PELANGGAN BULANAN  /////////////////////
function getLastTransaction($idpel) {
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

function getMonthName($month, $year) {
    $months = ["Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"];
    if ($month > 12) {
        $month = 1;
        $year++;
    }
    return $months[$month - 1] . ' ' . $year;
}

function calculateProrate($harga, $tempo, $currentDate) {
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

// Cek user dari tabel `paket` berdasarkan `PAKET`
$sql_paket = "SELECT * FROM `paket` WHERE `PAKET` LIKE '%$PAKETPELANGGAN%'";
$query_paket = mysqli_query($conn, $sql_paket);
$paket_data = mysqli_fetch_array($query_paket);

// Tampilkan harga paket
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
        // Tanggal 16â€“24: prorate bulan ini + full bulan depan
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
        // Tanggal 6â€“15: tagih prorate bulan ini sampai tanggal 24
        $prorate = calculateProrate($paketHarga, $tempo, $currentYear . '-' . $currentMonth . '-25');
        $tagihanDetail[] = [
            'keterangan' => 'Tagihan Bulan ' . getMonthName($currentMonth, $currentYear) . ' (Prorate sampai 24)',
            'harga' => $prorate
        ];
        $totalTagihan += $prorate;
      
    } elseif ($currentDay >= $tanggal_akhir_tutup_buku) {
        // Tanggal 25â€“akhir bulan: tagih full bulan depan
        $tagihanDetail[] = [
            'keterangan' => 'Tagihan Bulan ' . getMonthName($currentMonth + 1, $currentYear),
            'harga' => $paketHarga
        ];
        $totalTagihan += $paketHarga;
   
    } else {
        // Tanggal 1â€“5: full bulan ini
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
        // Tanggal 1â€“5
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
$tglskg = date('d');
$cektanggal = date('Y-m-d');
$ptanggalskg = getMonthName($currentMonth, $currentYear); // Bulan ini
$ptanggaberikut = getMonthName($currentMonth + 1, $currentYear); // Bulan depan
$ptanggalsebelum = getMonthName($currentMonth - 1, $currentYear); // Bulan lalu

// Logika periode -- fallback TIPE_TEMPO-aware, dipakai HANYA kalau baris pending
// tidak punya PENGUNAAN tersimpan (lihat "$pendingPengunaan" di bawah). SEBELUMNYA
// di sini pakai heuristik tutup-buku generik yang tidak sadar TIPE_TEMPO/Periode
// Tercatat sama sekali -- bisa salah bulan.
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
    // Jaring pengaman terakhir supaya $periode tidak pernah string kosong.
    $periode = $ptanggalskg;
}

// PENGUNAAN baris pending (PERMINTAAN KODE/invoice PENAGIHAN yang sedang dilunasi) SELALU
// menang atas fallback di atas -- baris itu sendiri sudah TIPE_TEMPO-aware (diisi
// portal_bayar.php dari invoice yang benar). Fallback di atas cuma dipakai utk baris
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

echo "=>";
echo $periode;

//////////////////////////////CEK PAKET DARI DATA REF//////////////////////////////
echo "=> CEK PAKET TERSEDIA =>";
echo $PAKET;


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

        die(json_encode(["status" => "error", "message" => "Server untuk area pelanggan tidak ditemukan"]));
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
                $linkBukti = "Download bukti pembayaran : https://quenbytekniksejahtera.com/crm/billing/riwayatTransaction.php?idpel=$USERNAMETRANASAKSI";
                $pembayaranBerhasilTemplate = notifTemplateGetPembayaranBerhasil($username);
                $text = notifTemplateReplaceVars($pembayaranBerhasilTemplate, get_defined_vars());

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
                $mail->SMTPDebug = 0;
                $mail->Port = 25;
                $mail->SMTPAuth = false;
                $mail->Username = "helpdesk@quenbytekniksejahtera.com"; //user email
                $mail->Password = "helpdeskqts"; //password email 
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
                if ($mail->Send()) {
                }
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

                $sql11 = "INSERT INTO `transaksi`( `TANGGALBAYAR`,`PENGUNAAN`, `IDPEL`, `NAMA`, `PAKET`, `HARGA`, `STATUS`, `BUKTI`,`PEMILIK`,`CEK`) VALUES ('$tanggalbayar','$periode','$USERNAMETRANASAKSI','$NAMAPELANGGAN','$PAKETPELANGGAN','$HARGAPELANGGAN','BERHASIL','$invoiceref','$BRANDPELANGGAN','')";
                if ($conn->query($sql11) === TRUE) {

                    $sql12 = "DELETE FROM `transaksi` WHERE BUKTI='$invoiceref' AND `STATUS`='PERMINTAAN KODE'";
                    if ($conn->query($sql12) === TRUE) {
                    }

                    $sql_hapus_penagihan = "DELETE FROM `transaksi` WHERE `IDPEL`='$USERNAMETRANASAKSI' AND `PENGUNAAN`='$periode' AND UPPER(`STATUS`)='PENAGIHAN'";
                    $conn->query($sql_hapus_penagihan);


                    $history[] = "[ callback tripay - " . date('Y-m-d H:i:s') . " ] Tripay berhasil mencatat transaksi OTOMATIS  '$tanggalbayar','$periode','$USERNAMETRANASAKSI','$NAMAPELANGGAN','$PAKETPELANGGAN','$HARGAPELANGGAN','BERHASIL','$invoiceref','$BRANDPELANGGAN',''";
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
                                        $history[] = "[ callback duitku - " . date('Y-m-d H:i:s') . " ] PERINGATAN: password PPPoE kosong untuk $USERNAMETRANASAKSI, RADIUS TIDAK diaktifkan otomatis.";
                                    } else {
                                        radiusSyncSingleCustomerNow($USERNAMETRANASAKSI, $PASSWORDPELANGGAN, $paketRow, true, radiusGetGlobalSettings($conn));
                                    }

  $history[] = "[ callback duitku - " . date('Y-m-d H:i:s') . " ] Duitku berhasil aktifkan OTOMATIS $USERNAMETRANASAKSI $NAMAPELANGGAN $PAKETPELANGGAN";
                    // Simpan ke file history
                    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));


http_response_code(200);
echo "OK";

}












                }
            }

        }





        //////////////////////////////AKTIFKAN JIKA PPPOE//////////////////////////////

        if ($LAYANAN == "HOTPSOT") {

            $pecah = explode("-", $merchant_ref);
            if (count($pecah) < 5) {
                die(json_encode(["status" => "error", "message" => "Format merchant_ref tidak valid"]));
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
                                                die("Ã¢ÂÅ’ Format waktu tidak dikenal!");
                                        }
                                    }

                                    die("Ã¢ÂÅ’ Format uptime tidak valid!");
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
                                        http_response_code(200);
                                        echo "activated successfully";

                                        // Masukkan data ke database transaksi
                                        $sql = "INSERT INTO `transaksi`(`TANGGALBAYAR`,`STATUS`, `IDPEL`, `NAMA`, `PAKET`, `HARGA`, `BUKTI`, `CEK`, `PEMILIK`) 
                                                VALUES ('$tanggalbayar','BERHASIL','$USERNAMETRANASAKSI','$NAMAPELANGGAN','$PAKET','$voucher_amount','$invoiceref','', '$BRANDPELANGGAN')";
                                        $conn->query($sql);

                                        // Hapus transaksi permintaan kode lama
                                        $sql12 = "DELETE FROM `transaksi` WHERE BUKTI='$invoiceref' AND `STATUS`='PERMINTAAN KODE'";
                                        $conn->query($sql12);
                                    } else {

                                    }






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
                    $linkBukti = "Download bukti pembayaran : https://quenbytekniksejahtera.com/mybilling/cetakbukti.php?invoice=$invoiceref";
                    $pembayaranBerhasilTemplate = notifTemplateGetPembayaranBerhasil($username);
                    $text = notifTemplateReplaceVars($pembayaranBerhasilTemplate, get_defined_vars());

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
                    $mail->SMTPDebug = 0;
                    $mail->Port = 25;
                    $mail->SMTPAuth = false;
                    $mail->Username = "helpdesk@quenbytekniksejahtera.com"; //user email
                    $mail->Password = "helpdeskqts"; //password email
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

                      http_response_code(200);
                    echo "activated successfully";
                }
            }
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
                        header('Content-Type: application/json');
                        echo json_encode(["status" => "success", "message" => "activated successfully"]);
                        exit;
                    } else {
                        // Log error jika update gagal
                        $history[] = "[ callback tripay - " . date('Y-m-d H:i:s') . " ] ERROR: Gagal update transaksi topup $invoiceref - " . $conn->error;
                        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
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
                        echo json_encode(["status" => "success", "message" => "activated successfully"]);
                        exit;
                    }
                }
            }
        }
    }




http_response_code(200);
echo "OK";
