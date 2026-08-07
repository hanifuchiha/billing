<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
// Buffer output supaya redirectToTop() di bawah masih bisa memilih antara
// header('Location: ...') klasik atau JSON response (dipakai submit AJAX dari
// editcustomerform.php untuk alur register OLT) walau ada echo/warning di
// tengah proses (mis. baris FreeRADIUS mode) sebelum redirect dipanggil.
ob_start();
require '../cek-sesi.php';
require '../routeros_api.class.php';
require_once __DIR__ . '/../radius_sync_lib.php';

// Bootstrap kolom server.CONNECTION_MODE (lihat server.php) -- dibutuhkan di sini
// juga karena file ini bisa dipakai sebelum admin pernah membuka server.php.
$checkConnModeCol = @mysqli_query($conn, "SHOW COLUMNS FROM server LIKE 'CONNECTION_MODE'");
if ($checkConnModeCol && mysqli_num_rows($checkConnModeCol) === 0) {
    @mysqli_query($conn, "ALTER TABLE server ADD COLUMN CONNECTION_MODE ENUM('API','RADIUS_ONLY') NOT NULL DEFAULT 'API'");
}

function wantsJsonResponse() {
    return (($_POST['response_mode'] ?? '') === 'json');
}

function redirectToTop($url) {
    if (wantsJsonResponse()) {
        @ob_clean();
        $query = [];
        $parts = parse_url($url);
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        $success = (($query['pesan'] ?? '') === 'berhasil');
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success'  => $success,
            'redirect' => $url,
            'message'  => $query['text'] ?? '',
            'data'     => $query,
            'logs'     => [],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Location: ' . $url);
    exit;
}

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



// Fungsi untuk menambah kolom jika belum ada
function ensure_column_exists($conn, $table, $column, $type) {
    $check = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");
    if (!$check || mysqli_num_rows($check) == 0) {
        $sql = "ALTER TABLE `$table` ADD `$column` $type NULL";
        mysqli_query($conn, $sql);
    }
}

// Pastikan kolom NIK ada
ensure_column_exists($conn, 'pelanggan', 'NIK', 'VARCHAR(20)');

// Pastikan kolom area domisili ada
ensure_column_exists($conn, 'pelanggan', 'provinsi', 'VARCHAR(100)');
ensure_column_exists($conn, 'pelanggan', 'kabupaten', 'VARCHAR(100)');
ensure_column_exists($conn, 'pelanggan', 'kecamatan', 'VARCHAR(100)');
ensure_column_exists($conn, 'pelanggan', 'kelurahan', 'VARCHAR(100)');
ensure_column_exists($conn, 'pelanggan', 'rw', 'VARCHAR(10)');
ensure_column_exists($conn, 'pelanggan', 'rt', 'VARCHAR(10)');




if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $debug_log = [];
    $customerID = $_POST['customerID'] ?? '';
    $customerID_old = $_POST['customerID_old'] ?? '';
    $passwordPPPOE = $_POST['passwordPPPOE'] ?? ''; 
    $NIK = mysqli_real_escape_string($conn, trim($_POST['NIK'] ?? ''));
    $customerName = $_POST['customerName'] ?? '';
    $address = $_POST['address'] ?? '';
    $whatsapp = $_POST['whatsapp'] ?? '';
    $email = $_POST['Email'] ?? '';
    $coordinates = $_POST['coordinates'] ?? '';
    $server = $_POST['server'] ?? '';
    $serverlama = $_POST['serverlama'] ?? '';
    $area = $_POST['area'] ?? '';
    $arealama = $_POST['arealama'] ?? '';
    $sales = $_POST['sales'] ?? '';
    $odp = $_POST['odp'] ?? '';
    $tipe_bayar = $_POST['tipe_bayar'] ?? '';
    $tipe_tempo = $_POST['tipe_tempo'] ?? '';
    $packages = $_POST['packages'] ?? '';
    $provinsi = $_POST['provinsi'] ?? '';
    $kabupaten = $_POST['kabupaten'] ?? '';
    $kecamatan = $_POST['kecamatan'] ?? '';
    $kelurahan = $_POST['kelurahan'] ?? '';
    $rw = $_POST['rw'] ?? '';
    $rt = $_POST['rt'] ?? '';

    $debug_log[] = "Input received - customerID: $customerID, customerID_old: $customerID_old, passwordPPPOE: $passwordPPPOE";
    $debug_log[] = "Other inputs - customerName: $customerName, server: $server, serverlama: $serverlama";
    
    if (empty($customerID_old)) {
        $debug_log[] = "ERROR: customerID_old is empty! Cannot proceed with update.";
        file_put_contents(__DIR__.'/debug_editcustomer.log', print_r($debug_log, true), FILE_APPEND);
        redirectToTop("../tables.php?pesan=gagal&text=Error: Customer ID lama tidak ditemukan");
    }
    
    // Validate PPPOE credentials - no spaces allowed
    if (preg_match('/\s/', $customerID)) {
        $debug_log[] = "ERROR: customerID contains spaces: $customerID";
        file_put_contents(__DIR__.'/debug_editcustomer.log', print_r($debug_log, true), FILE_APPEND);
        redirectToTop("../tables.php?pesan=gagal&text=Error: PPPOE Username tidak boleh mengandung spasi");
    }
    
    if (preg_match('/\s/', $passwordPPPOE)) {
        $debug_log[] = "ERROR: passwordPPPOE contains spaces: $passwordPPPOE";
        file_put_contents(__DIR__.'/debug_editcustomer.log', print_r($debug_log, true), FILE_APPEND);
        redirectToTop("../tables.php?pesan=gagal&text=Error: PPPOE Password tidak boleh mengandung spasi");
    }

    // customerID juga dipakai sebagai username di file users FreeRADIUS
    // (radiusBuildUserBlock() di radius_sync_lib.php) -- boleh SEMUA simbol/
    // karakter (mis. "/", "#", kutip satu) SELAIN yang benar-benar merusak
    // sintaks file itu (spasi/tab/baris-baru, kutip dua, backslash) atau
    // bikin baris dianggap komentar (diawali '#'). SQL aman karena customerID
    // di-escape dengan mysqli_real_escape_string() sebelum dipakai di query
    // mentah di bawah.
    if (preg_match('/[\x00-\x20"\\\\\x7F]/', $customerID) || $customerID === '' || $customerID[0] === '#' || strlen($customerID) > 64) {
        $debug_log[] = "ERROR: customerID contains invalid characters: $customerID";
        file_put_contents(__DIR__.'/debug_editcustomer.log', print_r($debug_log, true), FILE_APPEND);
        redirectToTop("../tables.php?pesan=gagal&text=" . urlencode("Error: PPPOE Username tidak boleh mengandung spasi, tanda kutip dua (\"), backslash (\\), tidak boleh diawali (#), dan maksimal 64 karakter"));
    }
    $customerID_sql = mysqli_real_escape_string($conn, $customerID);
    $customerID_old_sql = mysqli_real_escape_string($conn, $customerID_old);
    $passwordPPPOE_sql = mysqli_real_escape_string($conn, $passwordPPPOE);

    // Password disisipkan ke file users FreeRADIUS dalam bentuk
    // Cleartext-Password := "password" -- tanda kutip/backslash di dalamnya
    // akan merusak string literal itu dan bikin SELURUH file gagal di-parse
    // (auth mati untuk SEMUA pelanggan, bukan cuma satu).
    if ($passwordPPPOE !== '' && preg_match('/["\\\\]/', $passwordPPPOE)) {
        $debug_log[] = "ERROR: passwordPPPOE contains quote/backslash";
        file_put_contents(__DIR__.'/debug_editcustomer.log', print_r($debug_log, true), FILE_APPEND);
        redirectToTop("../tables.php?pesan=gagal&text=" . urlencode('Error: PPPOE Password tidak boleh mengandung tanda kutip (") atau backslash (\\)'));
    }


 // Ambil input POST
$authmode = $_POST['authmode'] ?? 'API MODE';

// Daftar mode yang valid
$valid_modes = ['RADIUS MODE', 'API MODE', 'MULTI MODE'];

// Cek apakah input valid, jika tidak default ke 'API MODE'
if (!in_array($authmode, $valid_modes)) {
    $authmode = 'API MODE';
}


    //  bila  no  hp  terdapat  karakter  +  dan  0-9
    if (!preg_match('/[^+0-9]/', trim($whatsapp))) {
        //  cek  karakter  1-3  apakah  +62
        if (substr(trim($whatsapp),  0,  2) == '62') {
            $whatsappedit  =  trim($whatsapp);
        }
        elseif (substr(trim($whatsapp),  0,  3) == '+62') {
            $whatsappedit  =  '62' . substr(trim($whatsapp),  1);
        }
        elseif (substr(trim($whatsapp),  0,  1) == '0') {
            $whatsappedit  =  '62' . substr(trim($whatsapp),  1);
        }
        elseif (substr(trim($whatsapp),  0,  14) == '-') {
            $whatsappedit  =  '' . substr(trim($whatsapp),  1);
        }
        elseif (substr(trim($whatsapp),  0,  14) == ' ') {
            $whatsappedit  =  '' . substr(trim($whatsapp),  1);
        }
    }

    // Nomor WA BOLEH dipakai lebih dari satu pelanggan (mis. satu orang
    // berlangganan di beberapa lokasi/akun berbeda dengan IDPEL & password
    // masing-masing) -- portal pelanggan sudah mendukung popup "pilih akun"
    // saat login kalau nomor WA-nya sama (lihat broadband/proseslogin-portal.php).
    // Jadi di sini TIDAK memblokir penyimpanan, cuma dicatat ke log untuk info admin.
    if (!empty($whatsappedit)) {
        $check_nowa_sql = "SELECT IDPEL FROM pelanggan WHERE NOWA = '" . mysqli_real_escape_string($conn, $whatsappedit) . "' AND PEMILIK = '" . mysqli_real_escape_string($conn, $server) . "' AND IDPEL != '" . mysqli_real_escape_string($conn, $customerID_old) . "' LIMIT 1";
        $check_nowa_result = mysqli_query($conn, $check_nowa_sql);
        if ($check_nowa_result && mysqli_num_rows($check_nowa_result) > 0) {
            $existing_nowa_row = mysqli_fetch_assoc($check_nowa_result);
            $debug_log[] = "Info: Nomor WA $whatsappedit juga dipakai ID Pelanggan {$existing_nowa_row['IDPEL']} (diizinkan, portal login akan minta pelanggan pilih akun).";
            file_put_contents(__DIR__.'/debug_editcustomer.log', print_r($debug_log, true), FILE_APPEND);
        }
    }

    // ==================== CEK APAKAH HANYA PAKET YANG DIUBAH ====================
    // Kalau server/odp/tipe_bayar/tipe_tempo/username/password PPPoE tidak berubah,
    // dan yang berubah cuma paket, cukup update database saja tanpa menyentuh
    // Mikrotik/FreeRADIUS (tidak putus koneksi pelanggan).
    $oldDataRow = null;
    $oldDataQuery = mysqli_query($conn, "SELECT PEMILIK, ODP, TIPE_BAYAR, TIPE_TEMPO, PASSWORD, HARGA, TANGGALPASANG, TANGGAL_MONTHVERSARY FROM `pelanggan` WHERE `IDPEL` = '" . mysqli_real_escape_string($conn, $customerID_old) . "' LIMIT 1");
    if ($oldDataQuery) {
        $oldDataRow = mysqli_fetch_assoc($oldDataQuery);
    }

    if ($oldDataRow) {
        $serverChanged    = (string)$oldDataRow['PEMILIK']    !== (string)$server;
        $odpChanged       = (string)$oldDataRow['ODP']        !== (string)$odp;
        $tipeBayarChanged = (string)$oldDataRow['TIPE_BAYAR'] !== (string)$tipe_bayar;
        $tipeTempoChanged = (string)$oldDataRow['TIPE_TEMPO'] !== (string)$tipe_tempo;
        $usernameChanged  = trim((string)$customerID_old) !== trim((string)$customerID);
        $passwordChanged  = (string)$oldDataRow['PASSWORD'] !== (string)$passwordPPPOE;

        $needMikrotikUpdate = $serverChanged || $odpChanged || $tipeBayarChanged || $tipeTempoChanged || $usernameChanged || $passwordChanged;
    } else {
        // Data lama tidak ketemu, aman-nya tetap jalankan proses seperti biasa
        $needMikrotikUpdate = true;
    }

    if (!$needMikrotikUpdate) {
        $debug_log[] = "Hanya paket yang berubah (server/odp/tipe_bayar/tipe_tempo/username/password tetap sama). Skip proses Mikrotik/FreeRADIUS, hanya update database.";
    }
    // ==================== END CEK PERUBAHAN ====================

    // TEMPO ikut definisi server/product yang dipilih (dipakai di UPDATE database di bawah).
    // Diambil terpisah supaya tetap terisi walaupun proses Mikrotik di-skip.
    $tempo = '';
    $tempoQuery = mysqli_query($conn, "SELECT TEMPO, CONNECTION_MODE FROM `server` WHERE `AREA` = '" . mysqli_real_escape_string($conn, $area) . "' AND `PEMILIK` = '" . mysqli_real_escape_string($conn, $server) . "' LIMIT 1");
    if ($tempoQuery && ($tempoRow = mysqli_fetch_assoc($tempoQuery))) {
        $tempo = $tempoRow['TEMPO'];

        // Server RADIUS SAJA tidak punya API Mikrotik untuk dihubungi -- auth mode
        // WAJIB RADIUS MODE, abaikan pilihan API MODE/MULTI MODE dari form.
        if (($tempoRow['CONNECTION_MODE'] ?? 'API') === 'RADIUS_ONLY') {
            $authmode = 'RADIUS MODE';
            $debug_log[] = "Server RADIUS SAJA -- Auth Mode dikunci ke RADIUS MODE.";
        }
    }

if ($needMikrotikUpdate && ($authmode=='API MODE' | $authmode=='MULTI MODE'  ))
{
    $debug_log[] = "[API/MULTI MODE] Proses hapus secret di server lama ($arealama, $serverlama)";
    #remove secret di server lama ==================================

    $sql = "SELECT * FROM `server` WHERE `AREA` = '$arealama' and `PEMILIK`= '$serverlama' ";
    $query = mysqli_query($conn, $sql);
    if (!$query) {
        $debug_log[] = "Gagal query server lama: " . mysqli_error($conn);
    }
    while ($data = mysqli_fetch_array($query)) {






                        
              
                        $user = $data['PEMILIK'];
                        $ip = $data['IP'];
                        $password = $data['PASSWORD'];
                        $tempo = $data['TEMPO'];

                        $API = new RouterosAPI();

                        if ($API->connect($ip, $user, $password)) {
                            $debug_log[] = "Berhasil konek ke MikroTik lama ($ip, $user)";


                            $cariurutan2 = $API->comm(
                                "/ppp/secret/getall",
                                array(
                                    ".proplist" => ".id",
                                    "?name" => $customerID_old,
                                )
                            );

                            if (!empty($cariurutan2) && isset($cariurutan2[0][".id"])) {
                                $API->comm(
                                    "/ppp/secret/remove",
                                    array(
                                        ".id" => $cariurutan2[0][".id"],
                                    )
                                );
                                $debug_log[] = "Berhasil hapus secret lama $customerID_old";
                            } else {
                                $debug_log[] = "Secret lama $customerID_old tidak ditemukan di server lama";
                            }
                            $berhasil = "Koneksi ke MikroTik Berhasil";
                            $API->disconnect();
                        } else {
                            $debug_log[] = "Koneksi ke MikroTik lama gagal ($ip, $user)";
                            $berhasil = "Koneksi ke MikroTik gagal!";
                        }

                    }






    #remove secret di server lama ==================================

    $debug_log[] = "[API/MULTI MODE] Proses tambah secret di server baru ($area, $server)";
    $sql = "SELECT * FROM `server` WHERE `AREA` = '$area' and `PEMILIK`= '$server' ";
    $query = mysqli_query($conn, $sql);
    if (!$query) {
        $debug_log[] = "Gagal query server baru: " . mysqli_error($conn);
    }
    while ($data = mysqli_fetch_array($query)) {

        $user = $data['PEMILIK'];
        $ip = $data['IP'];
        $password = $data['PASSWORD'];
        $tempo = $data['TEMPO'];

        $API = new RouterosAPI();
        $API->connect($ip, $user, $password);

        if ($API->connect($ip, $user, $password)) {
            $debug_log[] = "Berhasil konek ke MikroTik baru ($ip, $user)";


            $API->comm(
                "/ppp/secret/add",
                array(
                    "name"     => $customerID,
                    "password" => $passwordPPPOE,
                    "profile"  => $packages,
                    "service"  => "any",
                    "comment"  => "EDITED " . $customerName . "-" . $whatsappedit
                )
            );
            $debug_log[] = "Berhasil tambah secret baru $customerID";
            $berhasil = "Koneksi ke MikroTik Berhasil";
            $API->disconnect();
        } else {
            $debug_log[] = "Koneksi ke MikroTik baru gagal ($ip, $user)";
            $berhasil = "Koneksi ke MikroTik gagal!";
        }
    }


}


if ($needMikrotikUpdate && ($authmode=='RADIUS MODE' | $authmode=='MULTI MODE'  ))
{
    $debug_log[] = "[RADIUS/MULTI MODE] Proses edit user di FreeRADIUS";

    // Pakai reconcile terpusat (radius_sync_lib.php) -- path file, cara tulis
    // atomic, dan reload (bukan kill -9 + restart paksa) SAMA dengan yang
    // dipakai cron sync_freeradius_users.php dan activecustomer.php.
    if ($customerID !== $customerID_old) {
        // Username PPPoE berubah: entri lama harus dihapus eksplisit,
        // bukan dibiarkan jadi entri "hantu" yang tidak pernah dipakai lagi.
        $removeResult = radiusRemoveUsers([$customerID_old]);
        $debug_log[] = "Hapus entri RADIUS lama ($customerID_old): " . json_encode($removeResult);
    } else {
        $removeResult = ['changed' => false];
    }

    $upsertResult = radiusUpsertUsers([
        $customerID => [
            'password' => $passwordPPPOE,
            'reply' => ['Mikrotik-Group := "' . $packages . '"'],
        ],
    ]);

    radiusReloadIfChanged(!empty($removeResult['changed']) || !empty($upsertResult['changed']));

    $debug_log[] = "Berhasil edit user di file users FreeRADIUS: " . json_encode($upsertResult);
    echo "✅ User '$customerID' berhasil diedit di FreeRADIUS.";
}





















    ///////////////////catatat di log////////////////////

    // Cek apakah sudah pernah dikirim
    $history_file = "../notifbot/data/history-$ceknama.json";
    $history = [];

    if (file_exists($history_file)) {
        $history = json_decode(file_get_contents($history_file), true);
    }

    // Pastikan format history adalah array
    if (!is_array($history)) {
        $history = [];
    }

    $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Berhasil edit data pelanggan $customerID";
    // Simpan ke file history
    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));



    ////////////////////////////////////////////////////






    #ADD TO database===================================

    // Harga ikut paket yang dipilih sekarang; kalau paket tidak ketemu di
    // tabel `paket` (mis. nama berubah/dihapus), pertahankan harga lama
    // supaya tidak tertimpa kosong. Kalau ketemu, pakai reseller_effective_harga()
    // (BUKAN ambil kolom HARGA mentah) supaya ikut filter harga reseller/mitra
    // (custom_harga per paket + diskon permanen) kalau sesi ini reseller dengan
    // filter aktif -- pola yang sama persis dipakai Transaction.php &
    // proses/addcustomer.php. Sebelum fix ini, edit paket pelanggan SELALU
    // menulis harga asli admin ke pelanggan.HARGA apapun sesi yang mengedit.
    $harga = $oldDataRow['HARGA'] ?? '';
    $hargaExistsQuery = mysqli_query($conn, "SELECT id FROM `paket` WHERE `PAKET` = '" . mysqli_real_escape_string($conn, $packages) . "' AND `PEMILIK` = '" . mysqli_real_escape_string($conn, $server) . "' LIMIT 1");
    if ($hargaExistsQuery && mysqli_num_rows($hargaExistsQuery) > 0) {
        $harga = reseller_effective_harga($conn, $packages, $server, 'broadband');
    }

    // Mode tempo "monthversary": kunci anchor sekali saja kalau belum pernah
    // diisi. Prabayar diambil dari transaksi BERHASIL terakhir (kalau sudah
    // pernah bayar), pascabayar & prabayar yang belum pernah bayar diambil
    // dari tanggal pasang. cek_tagihan_harian.php akan mengunci ulang ke
    // transaksi pertama pelanggan prabayar begitu pembayaran pertamanya masuk.
    $tanggal_monthversary_set_sql = '';
    if ($tipe_tempo === 'monthversary' && empty($oldDataRow['TANGGAL_MONTHVERSARY'] ?? null)) {
        $anchorBaru = null;
        if ($tipe_bayar === 'prabayar') {
            $lastTrxQuery = mysqli_query($conn, "SELECT MAX(TANGGALBAYAR) as waktu_terakhir FROM `transaksi` WHERE `IDPEL` = '" . mysqli_real_escape_string($conn, $customerID_old) . "' AND `STATUS` = 'BERHASIL'");
            if ($lastTrxQuery && ($lastTrxRow = mysqli_fetch_assoc($lastTrxQuery)) && !empty($lastTrxRow['waktu_terakhir'])) {
                $anchorBaru = substr((string)$lastTrxRow['waktu_terakhir'], 0, 10);
            }
        }
        if (empty($anchorBaru)) {
            $anchorBaru = $oldDataRow['TANGGALPASANG'] ?? null;
        }
        if (!empty($anchorBaru)) {
            $tanggal_monthversary_set_sql = ",\n        `TANGGAL_MONTHVERSARY` = '" . mysqli_real_escape_string($conn, $anchorBaru) . "'";
        }
    }

    $sql2 = "UPDATE `pelanggan`
    SET `IDPEL` = '$customerID_sql',
        `PASSWORD` = '$passwordPPPOE_sql',
        `NIK` = '$NIK',
        `NAMA` = '$customerName',
        `TIPE_BAYAR` = '$tipe_bayar',
        `TIPE_TEMPO` = '$tipe_tempo',
        `PAKET` = '$packages',
        `HARGA` = '$harga',
        `NOWA` = '$whatsappedit',
        `EMAIL` = '$email',
        `ALAMAT` = '$address',
        `TEMPO` = '$tempo',
        `PEMILIK` = '$server',
        `MODE` = '$authmode',
        `ODP` = '$odp',
        `AREA` = '$area',
        `provinsi` = '$provinsi',
        `kabupaten` = '$kabupaten',
        `kecamatan` = '$kecamatan',
        `kelurahan` = '$kelurahan',
        `rw` = '$rw',
        `rt` = '$rt',
        `TIKOR` = '$coordinates',
        `sales` = '$sales'$tanggal_monthversary_set_sql
    WHERE `IDPEL` = '$customerID_old_sql'";
    
    $debug_log[] = "SQL Query: $sql2";
    
    if ($conn->query($sql2) === TRUE) {
        $debug_log[] = "Berhasil update data pelanggan di database.";
        $debug_log[] = "Affected rows: " . $conn->affected_rows;
        if ($conn->affected_rows == 0) {
            $debug_log[] = "WARNING: No rows affected. Check if customerID_old exists in database.";
        }
    } else {
        $debug_log[] = "Gagal update data pelanggan: " . $conn->error;
        $debug_log[] = "Connection error: " . $conn->connect_error;
    }

    // Simpan log debug ke file
    file_put_contents(__DIR__.'/debug_editcustomer.log', print_r($debug_log, true), FILE_APPEND);
    
    if ($conn->affected_rows > 0) {
        redirectToTop("../tables.php?pesan=berhasil&text=Success edit data $customerName $customerID");
    } else {
        redirectToTop("../tables.php?pesan=gagal&text=No data updated. Check debug log.");
    }
}

