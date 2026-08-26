
<?php

ob_start();

require '../cek-sesi.php';
require '../routeros_api.class.php';
require '../radius_sync_lib.php';
include "../notifbot/phpmailer/classes/class.phpmailer.php";

// Bootstrap kolom server.CONNECTION_MODE (lihat server.php) -- dibutuhkan di sini
// juga karena file ini bisa dipakai sebelum admin pernah membuka server.php.
$checkConnModeCol = @mysqli_query($conn, "SHOW COLUMNS FROM server LIKE 'CONNECTION_MODE'");
if ($checkConnModeCol && mysqli_num_rows($checkConnModeCol) === 0) {
    @mysqli_query($conn, "ALTER TABLE server ADD COLUMN CONNECTION_MODE ENUM('API','RADIUS_ONLY') NOT NULL DEFAULT 'API'");
}

function wantsJsonResponse() {
    return (($_POST['response_mode'] ?? '') === 'json');
}

function appendProvisionLog($message) {
    if (!isset($GLOBALS['provision_logs']) || !is_array($GLOBALS['provision_logs'])) {
        $GLOBALS['provision_logs'] = [];
    }
    $GLOBALS['provision_logs'][] = '[' . date('H:i:s') . '] ' . $message;
}

function triggerPelangganKeuanganSync($idpel) {
    $idpel = trim((string)$idpel);
    if ($idpel === '' || !function_exists('curl_init')) {
        return ['ok' => false, 'message' => 'Sinkronisasi Keuangan tidak dapat dijalankan.'];
    }

    $scriptPath = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $billingBasePath = rtrim(dirname(dirname($scriptPath)), '/');
    if ($billingBasePath === '' || $billingBasePath === '.') {
        $billingBasePath = '/keuangan/billing';
    }
    $syncUrl = 'http://127.0.0.1' . $billingBasePath
        . '/getdata/api_hanif_cron_pelanggan.php?src=new_customer&only=' . rawurlencode($idpel);

    $ch = curl_init($syncUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    $raw = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = (string)curl_error($ch);
    curl_close($ch);

    $payload = is_string($raw) ? json_decode($raw, true) : null;
    if ($httpCode === 200 && is_array($payload) && !empty($payload['ok']) && (int)($payload['customers_failed'] ?? 0) === 0) {
        return ['ok' => true, 'message' => 'Pelanggan otomatis tersinkron ke Keuangan.'];
    }

    $message = is_array($payload) ? (string)($payload['message'] ?? '') : '';
    if ($message === '' && is_array($payload) && !empty($payload['errors'])) {
        $message = (string)reset($payload['errors']);
    }
    if ($message === '') {
        $message = $curlError !== '' ? $curlError : 'HTTP ' . $httpCode;
    }
    return ['ok' => false, 'message' => $message];
}

function redirectToTop($url) {
    if (wantsJsonResponse()) {
        $buffered = ob_get_contents();
        if ($buffered !== false) {
            $trimmedBuffered = trim((string)$buffered);
            if ($trimmedBuffered !== '') {
                appendProvisionLog('Warning output terdeteksi sebelum JSON: ' . substr($trimmedBuffered, 0, 180));
            }
            @ob_clean();
        }
        $query = [];
        $parts = parse_url($url);
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        $jsonRedirect = $url;
        if (!preg_match('#^(https?:)?//#i', (string)$jsonRedirect) && strpos((string)$jsonRedirect, '/') !== 0) {
            $jsonRedirect = preg_replace('#^\.\./#', '', (string)$jsonRedirect);
            $jsonRedirect = '/crm/billing/' . ltrim((string)$jsonRedirect, '/');
        }

        $success = (($query['pesan'] ?? '') === 'berhasil');
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success' => $success,
            'redirect' => $jsonRedirect,
            'message' => $query['text'] ?? '',
            'data' => $query,
            'logs' => $GLOBALS['provision_logs'] ?? [],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $safeUrl = json_encode($url, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    echo "<script>(function(){var u=" . $safeUrl . "; if(window.top&&window.top!==window){window.top.location.href=u;} else if(window.parent&&window.parent!==window){window.parent.location.href=u;} else {window.location.href=u;}})();</script>";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST)) {
    $back = $_SERVER['HTTP_REFERER'] ?? '../tables.php';
    redirectToTop($back);
}

appendProvisionLog('Memulai proses add customer.');




    // --- AUTO ADD COLUMN 'provinsi' & 'kabupaten' jika belum ada di tabel pelanggan ---
    $altered = false;
    
    // Auto-create NIK column if it doesn't exist
    $result = mysqli_query($conn, "SHOW COLUMNS FROM pelanggan LIKE 'NIK'");
    if (mysqli_num_rows($result) == 0) {
        mysqli_query($conn, "ALTER TABLE pelanggan ADD COLUMN NIK VARCHAR(20) DEFAULT '' AFTER IDPEL");
        $altered = true;
    }
    
    $result = mysqli_query($conn, "SHOW COLUMNS FROM pelanggan LIKE 'provinsi'");
    if (mysqli_num_rows($result) == 0) {
        mysqli_query($conn, "ALTER TABLE pelanggan ADD COLUMN provinsi VARCHAR(100) DEFAULT '' AFTER AREA");
        $altered = true;
    }
    $result = mysqli_query($conn, "SHOW COLUMNS FROM pelanggan LIKE 'kabupaten'");
    if (mysqli_num_rows($result) == 0) {
        mysqli_query($conn, "ALTER TABLE pelanggan ADD COLUMN kabupaten VARCHAR(100) DEFAULT '' AFTER provinsi");
        $altered = true;
    }
    // (Optional) Tambah kolom kecamatan, kelurahan jika ingin auto juga
    $result = mysqli_query($conn, "SHOW COLUMNS FROM pelanggan LIKE 'kecamatan'");
    if (mysqli_num_rows($result) == 0) {
        mysqli_query($conn, "ALTER TABLE pelanggan ADD COLUMN kecamatan VARCHAR(100) DEFAULT '' AFTER kabupaten");
        $altered = true;
    }
    $result = mysqli_query($conn, "SHOW COLUMNS FROM pelanggan LIKE 'kelurahan'");
    if (mysqli_num_rows($result) == 0) {
        mysqli_query($conn, "ALTER TABLE pelanggan ADD COLUMN kelurahan VARCHAR(100) DEFAULT '' AFTER kecamatan");
        $altered = true;
    }
    // (Optional) Tambah kolom rw, rt jika ingin auto juga
    $result = mysqli_query($conn, "SHOW COLUMNS FROM pelanggan LIKE 'rw'");
    if (mysqli_num_rows($result) == 0) {
        mysqli_query($conn, "ALTER TABLE pelanggan ADD COLUMN rw VARCHAR(10) DEFAULT '' AFTER kelurahan");
        $altered = true;
    }
    $result = mysqli_query($conn, "SHOW COLUMNS FROM pelanggan LIKE 'rt'");
    if (mysqli_num_rows($result) == 0) {
        mysqli_query($conn, "ALTER TABLE pelanggan ADD COLUMN rt VARCHAR(10) DEFAULT '' AFTER rw");
        $altered = true;
    }




  // ================= Fungsi =================
  // getFreeradiusPID()/restartFreeradius() lokal yang dulu ada di sini sudah
  // dihapus -- fungsi restart yang benar untuk FreeRADIUS 3 (stop+kill+jalan
  // ulang mode debug) sekarang cuma ada SATU implementasi di
  // radius_sync_lib.php (radiusReloadIfChanged), dipakai lewat
  // radiusSyncSingleCustomerNow() di bawah.



if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $customerID = $_POST['customerID'] ?? '';
    $passwordPPPOE = $_POST['passwordPPPOE'] ?? ''; // Nama input sama, perlu diperbaiki jika berbeda
    $NIK = mysqli_real_escape_string($conn, trim($_POST['NIK'] ?? ''));
    $customerName = $_POST['customerName'] ?? '';
    $address = $_POST['address'] ?? '';
    $whatsapp = $_POST['whatsapp'] ?? '';
    $email = $_POST['Email'] ?? '';
    $coordinates = $_POST['coordinates'] ?? '';
    $server = $_POST['server'] ?? '';
    $area = $_POST['area'] ?? '';
    $sales = $_POST['sales'] ?? '';
    $area = $_POST['area'] ?? '';
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
    $tanggalpasang = $_POST['tanggalpasang'] ?? date('Y-m-d');
    $register_only = ($_POST['register_only'] ?? '0') === '1';
    $URL=$config['domain'];
    $user = $server;
    $text = '';
    $whatsappedit = trim((string)$whatsapp);


    $authmode= $_POST['authmode'] ?? 'radius';
    appendProvisionLog('Validasi data customer dan mode autentikasi.');

    // --- Validasi format Customer ID (username PPPoE/Radius) & password PPPoE ---
    // FreeRADIUS 3 membaca kredensial dari file teks /etc/freeradius/3.0/users
    // dengan format "username Cleartext-Password := \"password\"" (lihat
    // radiusBuildUserBlock() di radius_sync_lib.php). Username yang mengandung
    // spasi/tab/baris-baru akan merusak parsing block (radiusParseUsersFile
    // memisah block berdasarkan baris kosong & regex "^(\S+)\s+..."), dan
    // password yang mengandung tanda kutip (") atau backslash akan merusak
    // string literalnya. Keduanya bisa membuat SELURUH file users gagal
    // di-parse sehingga FreeRADIUS gagal start/auth untuk SEMUA pelanggan,
    // bukan cuma satu. Divalidasi di server (bukan cuma JS di form) karena
    // endpoint ini bisa dipanggil langsung tanpa lewat form (mis. bypass JS).
    $customerIDTrim = trim((string) $customerID);
    if ($customerIDTrim === '') {
        appendProvisionLog('Customer ID kosong, proses dibatalkan.');
        redirectToTop("../tables.php?pesan=gagal&text=" . urlencode("Customer ID / username PPPoE wajib diisi"));
    }
    // Whitelist diganti jadi blacklist: boleh SEMUA simbol/karakter (mis. "/", "#",
    // tanda kutip satu, dll) SELAIN yang benar-benar merusak sintaks file users
    // FreeRADIUS (spasi/tab/baris-baru, tanda kutip dua, backslash) atau bikin baris
    // ini dianggap komentar (diawali '#'). Aman untuk SQL karena customerID di-escape
    // dengan mysqli_real_escape_string() sebelum dipakai di query mentah di bawah.
    if (preg_match('/[\x00-\x20"\\\\\x7F]/', $customerIDTrim) || $customerIDTrim[0] === '#' || strlen($customerIDTrim) > 64) {
        appendProvisionLog("Customer ID \"$customerIDTrim\" mengandung karakter tidak valid, proses dibatalkan.");
        redirectToTop("../tables.php?pesan=gagal&text=" . urlencode("Customer ID / username PPPoE tidak boleh mengandung spasi, tanda kutip dua (\"), backslash (\\), tidak boleh diawali (#), dan maksimal 64 karakter"));
    }
    $customerID = $customerIDTrim;
    $customerID_sql = mysqli_real_escape_string($conn, $customerID);

    if (!$register_only) {
        if (trim((string) $passwordPPPOE) === '') {
            appendProvisionLog('Password PPPoE kosong, proses dibatalkan.');
            redirectToTop("../tables.php?pesan=gagal&text=" . urlencode("Password PPPoE wajib diisi"));
        }
        if (preg_match('/[\s"\\\\]/', $passwordPPPOE)) {
            appendProvisionLog('Password PPPoE mengandung karakter tidak valid (spasi/tanda kutip/backslash), proses dibatalkan.');
            redirectToTop("../tables.php?pesan=gagal&text=" . urlencode('Password PPPoE tidak boleh mengandung spasi, tanda kutip (") atau backslash (\\)'));
        }
    }
    $passwordPPPOE_sql = mysqli_real_escape_string($conn, $passwordPPPOE);

    // Cek apakah customerID sudah ada di database
    $fromBerhenti = $_POST['fromBerhenti'] ?? '0';
    if ($fromBerhenti !== '1') { // Hanya cek duplikasi jika bukan re-registrasi
        $check_sql = "SELECT IDPEL FROM pelanggan WHERE IDPEL = '$customerID_sql'";
        $check_result = mysqli_query($conn, $check_sql);
        if (mysqli_num_rows($check_result) > 0) {
            appendProvisionLog("Customer ID $customerID sudah ada di database.");
            redirectToTop("../tables.php?pesan=gagal&text=" . urlencode("Customer ID $customerID sudah ada di database"));
        }
    }

    //  bila  no  hp  terdapat  karakter  +  dan  0-9
    if (!preg_match('/[^+0-9]/', trim($whatsapp))) {
        //  cek  karakter  1-3  apakah  +62
        if (substr(trim($whatsapp),  0,  2) == '62') {

            $whatsappedit  =  trim($whatsapp);
        }
        //  cek  karakter  1-3  apakah  +62
        elseif (substr(trim($whatsapp),  0,  3) == '+62') {

            $whatsappedit  =  '62' . substr(trim($whatsapp),  1);
        }
        //  cek  karakter  1  apakah  0
        elseif (substr(trim($whatsapp),  0,  1) == '0') {

            $whatsappedit  =  '62' . substr(trim($whatsapp),  1);
        }
        //  cek  karakter  1-13  apakah  -
        elseif (substr(trim($whatsapp),  0,  14) == '-') {

            $whatsappedit  =  '' . substr(trim($whatsapp),  1);
        }
        //  cek  karakter  1-13  apakah
        elseif (substr(trim($whatsapp),  0,  14) == ' ') {

            $whatsappedit  =  '' . substr(trim($whatsapp),  1);
        }
    }

    // Nomor WA BOLEH dipakai lebih dari satu pelanggan (mis. satu orang
    // berlangganan di beberapa lokasi/akun berbeda dengan IDPEL & password
    // masing-masing) -- portal pelanggan sudah mendukung popup "pilih akun"
    // saat login kalau nomor WA-nya sama (lihat broadband/proseslogin-portal.php).
    // Jadi di sini TIDAK memblokir penyimpanan, cuma dicatat ke log provisioning
    // sebagai info buat admin.
    if ($whatsappedit !== '') {
        $check_nowa_sql = "SELECT IDPEL FROM pelanggan WHERE NOWA = '" . mysqli_real_escape_string($conn, $whatsappedit) . "' AND PEMILIK = '" . mysqli_real_escape_string($conn, $server) . "' LIMIT 1";
        $check_nowa_result = mysqli_query($conn, $check_nowa_sql);
        if ($check_nowa_result && mysqli_num_rows($check_nowa_result) > 0) {
            $existing_nowa_row = mysqli_fetch_assoc($check_nowa_result);
            appendProvisionLog("Info: Nomor WA $whatsappedit juga dipakai ID Pelanggan {$existing_nowa_row['IDPEL']} (diizinkan, portal login akan minta pelanggan pilih akun).");
        }
    }


require_once __DIR__ . '/../notifbot/notif_template_helper.php';
$filePath = notifTemplateFilePath($ceknama);


                                // Fungsi untuk replace variabel di pesan ketentuan
                                function replace_vars($template, $vars) {
                                    return preg_replace_callback('/\\$([a-zA-Z0-9_]+)/', function($m) use ($vars) {
                                        $key = $m[1];
                                        return isset($vars[$key]) ? $vars[$key] : $m[0];
                                    }, $template);
                                }
                               









    #ADD TO SERVER===================================

    $sql = "SELECT * FROM `server` WHERE `AREA` = '$area' and `PEMILIK`= '$server' ";
    $query = mysqli_query($conn, $sql);
    appendProvisionLog($register_only
        ? 'Mode registrasi: PPPoE sudah ada di Mikrotik, hanya menambahkan ke database billing.'
        : 'Menyiapkan pembuatan akun PPPoE/Radius berdasarkan server terpilih.');

    // Initialize brand variable
    $BRAND = '';

    while ($data = mysqli_fetch_array($query)) {
        // Get brand from server data
        $BRAND = $data['BRAND'];

        // Server RADIUS SAJA tidak punya API Mikrotik untuk dihubungi -- auth mode
        // WAJIB RADIUS MODE, abaikan pilihan API MODE/MULTI MODE dari form.
        if (($data['CONNECTION_MODE'] ?? 'API') === 'RADIUS_ONLY') {
            $authmode = 'RADIUS MODE';
            appendProvisionLog('Server RADIUS SAJA -- Auth Mode dikunci ke RADIUS MODE.');
        }

        if ($register_only) {
            // PPPoE secret sudah ada di Mikrotik (hasil scan) — jangan buat ulang, cukup catat ke database.
            continue;
        }

        if($authmode=='API MODE' || $authmode=='MULTI MODE' )
        {
                    $user = $data['PEMILIK'];
                    $ip = $data['IP'];
                    $password = $data['PASSWORD'];
                    $tempo = $data['TEMPO'];
                    $API = new RouterosAPI();
                    $API->connect($ip, $user, $password);

                    // Cek apakah secret sudah ada
                    $existing = $API->comm("/ppp/secret/print", array("?name" => $customerID));
                    if (!empty($existing)) {
                        appendProvisionLog("Customer ID $customerID sudah ada di server $user.");
                        redirectToTop("../tables.php?pesan=gagal&text=" . urlencode("Customer ID $customerID sudah ada di server $user"));
                    }

                    $API->comm(
                        "/ppp/secret/add",
                        array(
                            "name"     => $customerID,
                            "password" => $passwordPPPOE,
                            "profile"  => $packages,
                            "service"  => "any",
                            "comment"  => "BARU " . $customerName . "-" . $whatsappedit . "-" . $tanggalpasang
                        )
                    );
                    appendProvisionLog("PPPoE secret berhasil dibuat di server $user.");
        }

                    if ($authmode == 'RADIUS MODE' || $authmode == 'MULTI MODE') {

                        // Cek apakah user sudah ada -- lewat radiusReadMergedBlocks()
                        // (baca KEDUA path users + mirror authorize), bukan
                        // file_get_contents() satu file saja seperti sebelumnya.
                        $dup = false;
                        foreach (radiusReadMergedBlocks() as $b) {
                            if ($b['username'] === $customerID) {
                                $dup = true;
                                break;
                            }
                        }
                        if ($dup) {
                            appendProvisionLog("Customer ID $customerID sudah ada di Radius.");
                            redirectToTop("../tables.php?pesan=gagal&text=" . urlencode("Customer ID $customerID sudah ada di Radius"));
                        }

                        // Atribut reply dibangun terpusat lewat radiusBuildPppoeReplyAttrs()
                        // (radius_sync_lib.php) supaya konsisten dengan cron & path lain --
                        // ambil dulu baris paket untuk tahu KECEPATAN/RADIUS_PROFILE_SOURCE.
                        $paket_q = mysqli_query($conn, "SELECT * FROM paket WHERE PAKET='" . mysqli_real_escape_string($conn, $packages) . "' AND PEMILIK='" . mysqli_real_escape_string($conn, $server) . "' ORDER BY id DESC LIMIT 1");
                        $paketRow = ($paket_q && mysqli_num_rows($paket_q) > 0) ? mysqli_fetch_assoc($paket_q) : ['PAKET' => $packages, 'KECEPATAN' => ''];

                        radiusSyncSingleCustomerNow($customerID, $passwordPPPOE, $paketRow, true, radiusGetGlobalSettings($conn));
                        appendProvisionLog('User Radius berhasil ditambahkan.');
                        appendProvisionLog('Freeradius berhasil direstart.');

                    }




    }







    #ADD TO database===================================


    // Mode tempo "monthversary": anchor awal dikunci ke tanggal pasang.
    // Untuk prabayar, anchor ini akan dikunci ulang otomatis ke tanggal
    // transaksi BERHASIL pertama oleh cek_tagihan_harian.php begitu
    // pelanggan melakukan pembayaran pertamanya.
    $tanggal_monthversary_awal = ($tipe_tempo === 'monthversary') ? $tanggalpasang : null;
    $tanggal_monthversary_sql = $tanggal_monthversary_awal !== null
        ? "'" . mysqli_real_escape_string($conn, $tanggal_monthversary_awal) . "'"
        : 'NULL';

    $sql2 = "INSERT INTO `pelanggan` (`PASSWORD`,`IDPEL`,`NIK`,`NAMA`, `TIPE_BAYAR`, `TIPE_TEMPO`, `PAKET`, `HARGA`, `TANGGALPASANG`,`NOWA`,`EMAIL`,`ALAMAT`,`TEMPO`,`PEMILIK`,`MODE`,`ODP`,`AREA`,`provinsi`,`kabupaten`,`kecamatan`,`kelurahan`,`rw`,`rt`,`TIKOR`,`sales`,`BRAND`,`TANGGAL_MONTHVERSARY`) VALUES ('$passwordPPPOE_sql','$customerID_sql','$NIK','$customerName','$tipe_bayar','$tipe_tempo','$packages','-','$tanggalpasang','$whatsappedit','$email','$address','$tempo','$server','$authmode','$odp','$area','$provinsi','$kabupaten','$kecamatan','$kelurahan','$rw','$rt','$coordinates','$sales','" . mysqli_real_escape_string($conn, $BRAND) . "',$tanggal_monthversary_sql)";
    if ($conn->query($sql2) === TRUE) {
        appendProvisionLog('Data pelanggan berhasil disimpan ke database.');
        // Jika ini adalah re-registrasi dari pelanggan berhenti, hapus dari tabel pelanggan_berhenti
        if ($fromBerhenti === '1') {
            $delete_sql = "DELETE FROM pelanggan_berhenti WHERE idpel = '$customerID_sql'";
            mysqli_query($conn, $delete_sql);
        }

        // Auto buat transaksi PENAGIHAN sesuai periode tanggal pasang
        $bulan_indonesia = [
            1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
        ];

        $timestamp_pasang = strtotime((string)$tanggalpasang);
        if ($timestamp_pasang === false) {
            $timestamp_pasang = time();
        }

        $periode_penggunaan = $bulan_indonesia[(int)date('n', $timestamp_pasang)] . ' ' . date('Y', $timestamp_pasang);
        $tanggal_penagihan = date('Y-m-d');

        $harga_penagihan = (int)reseller_effective_harga($conn, $packages, $server);

        $cek_trx_q = mysqli_query(
            $conn,
            "SELECT id FROM transaksi WHERE IDPEL='" . mysqli_real_escape_string($conn, $customerID) . "' AND PENGUNAAN='" . mysqli_real_escape_string($conn, $periode_penggunaan) . "' AND TRIM(UPPER(COALESCE(STATUS, ''))) IN ('PENAGIHAN','PERMINTAAN KODE','KONFIRMASI','BERHASIL') LIMIT 1"
        );

        if (!$cek_trx_q || mysqli_num_rows($cek_trx_q) === 0) {
            $bukti_penagihan = 'INV-REG-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$customerID) . '-' . date('Ym');
            $cek_penagihan = 'AUTO PENAGIHAN DARI REGISTRASI';
            $status_penagihan = 'PENAGIHAN';

            mysqli_query(
                $conn,
                "INSERT INTO transaksi (TANGGALBAYAR, PENGUNAAN, STATUS, IDPEL, NAMA, PAKET, HARGA, BUKTI, CEK, PEMILIK, METODE_BAYAR) VALUES ('" . mysqli_real_escape_string($conn, $tanggal_penagihan) . "', '" . mysqli_real_escape_string($conn, $periode_penggunaan) . "', '" . mysqli_real_escape_string($conn, $status_penagihan) . "', '" . mysqli_real_escape_string($conn, $customerID) . "', '" . mysqli_real_escape_string($conn, $customerName) . "', '" . mysqli_real_escape_string($conn, $packages) . "', " . (int)$harga_penagihan . ", '" . mysqli_real_escape_string($conn, $bukti_penagihan) . "', '" . mysqli_real_escape_string($conn, $cek_penagihan) . "', '" . mysqli_real_escape_string($conn, $server) . "', '')"
            );
            appendProvisionLog('Invoice awal berhasil dibuat.');
        }

        $keuanganSync = triggerPelangganKeuanganSync($customerID);
        if (!empty($keuanganSync['ok'])) {
            appendProvisionLog((string)$keuanganSync['message']);
        } else {
            appendProvisionLog('Sinkronisasi Keuangan masuk antrean retry: ' . (string)($keuanganSync['message'] ?? 'gagal sementara'));
        }
    }



    #BOT NOTIF===================================

    $to =  $whatsappedit;


    
    // Baca file (auto-dibuat dgn template default kalau belum ada -- lihat
    // notif_template_helper.php) dan ambil bagian REGISTRASI.
    {
        $isi = notifTemplateGetContent($ceknama);
        $GLOBALS['URL'] = $URL;

        $registrasi_raw = notifTemplateExtractSection($isi, 'REGISTRASI');

          // Ambil semua variabel terdefinisi di scope ini
          $vars = get_defined_vars();
          // Hapus variabel yang tidak perlu (opsional, agar tidak bocor variabel besar)
          unset($vars['isi'], $vars['match'], $vars['vars']);
          $text = replace_vars($registrasi_raw, $vars);

        
        if ($tipe_bayar == "pascabayar") {

            // Ganti kalimat default
            $text = str_replace(
                "Pembayaran awal ditunggu maksimal 2x24 jam sejak pesan ini dikirim",
                "Layanan ini bersifat pasca bayar, artinya Anda menggunakan layanan terlebih dahulu dan membayar sesuai pemakaian setiap bulan.",
                $text
            );

        
        }
        if ($tipe_tempo == "mengikuti_tanggal_bayar") {
            // Ketentuan tagihan untuk aktivasi sebelum tanggal 15
            $text = str_replace(
                "- Jika aktivasi dilakukan sebelum tanggal 15, maka 3 hari setelah aktif Anda akan menerima tagihan penuh untuk 1 bulan.",
                "- Pembayaran dilakuan setelah 30 hari penggunaan.",
                $text
            );

            // Ketentuan tagihan untuk aktivasi setelah tanggal 15
            $text = str_replace(
                "- Jika aktivasi dilakukan setelah tanggal 15, maka akan dikenakan tagihan prorata (hanya dihitung dari tanggal aktif hingga tanggal 28), dan bulan berikutnya akan ditagihkan penuh.",
                "",
                $text
            );
        } elseif ($tipe_tempo == "monthversary") {
            // Ketentuan tagihan untuk mode monthversary: jatuh tempo tetap
            // mengikuti tanggal aktivasi/pasang setiap bulan.
            $text = str_replace(
                "- Jika aktivasi dilakukan sebelum tanggal 15, maka 3 hari setelah aktif Anda akan menerima tagihan penuh untuk 1 bulan.",
                "- Tanggal jatuh tempo Anda akan SELALU sama dengan tanggal aktivasi ini setiap bulannya (tanggal $tanggalpasang).",
                $text
            );
            $text = str_replace(
                "- Jika aktivasi dilakukan setelah tanggal 15, maka akan dikenakan tagihan prorata (hanya dihitung dari tanggal aktif hingga tanggal 28), dan bulan berikutnya akan ditagihkan penuh.",
                "",
                $text
            );
        }


        
      

    }






    // Path ke file JSON reminder
    $jsonFile = "../notifbot/data/reminder-$ceknama.json";

    // Default
    $botname = "";

    // Ambil pilihan bot khusus kategori pendaftaran (jika ada)
    $botCategoryFile = "../notifbot/data/bot_receiver_config-$ceknama.json";
    if (file_exists($botCategoryFile)) {
        $botCategoryData = json_decode(file_get_contents($botCategoryFile), true);
        if (is_array($botCategoryData) && !empty($botCategoryData['pendaftaran'])) {
            $botname = trim((string)$botCategoryData['pendaftaran']);
        }
    }

    // Fallback ke konfigurasi reminder jika bot kategori belum dipilih
    if (file_exists($jsonFile)) {
        $jsonData = file_get_contents($jsonFile);
        $data = json_decode($jsonData, true);
        if (is_array($data)) {
            foreach ($data as $item) {
                if ($botname === "") {
                    $botname = $item['botname'];
                }
                // hanya pakai botname (jika ada lebih dari 1 entri, gunakan yg terakhir)
            }
        }
    }

    // Ambil informasi bot
    $waapi = "";
    $botpass = "";
    $sql1 = "SELECT * FROM `botwa` WHERE `namebot` = '$botname' AND `pemilik` = '$ceknama' LIMIT 1";
    $query1 = mysqli_query($conn, $sql1);
    if (mysqli_num_rows($query1) === 0) {
        $sql1 = "SELECT * FROM `botwa` WHERE `namebot` = '$botname' LIMIT 1";
        $query1 = mysqli_query($conn, $sql1);
    }
    if ($data1 = mysqli_fetch_array($query1)) {
        $waapi = $data1['addressbot'];
        $passwordbot = $data1['password'];
    }



    $waapi = htmlspecialchars($waapi);
    $phone = "$to@s.whatsapp.net";
    $message = $text;

    // Ambil sender jika ada dari tabel botwa
    $sender = '';
    $sqlSender = "SELECT sender FROM botwa WHERE namebot = '$botname' LIMIT 1";
    $querySender = mysqli_query($conn, $sqlSender);
    if ($querySender && $rowSender = mysqli_fetch_assoc($querySender)) {
        $sender = $rowSender['sender'];
    }
    $data = [
        "phone" => $phone,
        "message" => $message,
        "sender" => $sender
    ];

    $botname = htmlspecialchars($botname);
    $passwordbot = htmlspecialchars($passwordbot);

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


    #BOT NOTIF===================================





    $mail = new PHPMailer;
    $mail->IsSMTP();
    $mail->SMTPSecure = '';
    $mail->Host = "mail.quenbytekniksejahtera.com"; //host masing2 provider email
    $mail->SMTPDebug = 0;
    $mail->Port = 25;
    $mail->SMTPAuth = false;
    $mail->Username = "helpdesk@quenbytekniksejahtera.com"; //user email
    $mail->Password = "helpdeskqts"; //password email 
    $mail->SetFrom("helpdesk@quenbytekniksejahtera.com", "FIBERQ"); //set email pengirim
    $mail->Subject = "Aktifasi " . ($fromBerhenti === '1' ? 'Ulang' : '') . " wifi FIBERQ berhasil"; //subyek email
    $mail->AddAddress("$email", "$customerName - $customerID ");  //tujuan email
    $mail->MsgHTML("Yth. Bapak / Ibu  $customerName     <br>
                            <br>
                            Terima kasih atas kepercayaan anda " . ($fromBerhenti === '1' ? 'kembali ' : '') . "menggunakan WIFI FIBERQ NET   <br>
                            $customerID    <br>
                            anda telah " . ($fromBerhenti === '1' ? 'diaktifkan kembali' : 'terdaftar') . " sebagai pelanggan kami dengan detail berikut ini :   <br>
                            Nama : $customerName <br>
                            ID pelanggan : $customerID <br>
                            Paket Langganan : $packages <br>

                            Alamat : $address <br>
                            No WHATSAPP : $whatsappedit <br>
                            E Mail : $email <br>
                            Tanggal aktif : $tanggalpasang <br>
                            <br>
                            <br>
                            kami memberitahukan pembayaran awal ditunggu sampai 24 jam.
                            kami akan memberikan notif pembayaran awal via WHATSAPP INI.

                            Kami akan menjelasakan prosedure FIBERQ <br>
                            Pembayaran jatuh tempo setiap tanggal 28 setiap bulannya. <br>
                            akan ada notif tiap bulan H-3 sebelum jatuh tempo. <br>
                            jika anda aktif di awal bulan contoh aktif dibawah tanggal 15 <br>
                            maka 3 hari setelah aktif akan ada tagihan awal ( pembayaran full pemakaian sebulan ).<br>
                            Dan jika aktif di atas tanggal 15 dalam bulan maka akan ada tagihan ( tidak full / atau prarate sampai tanggal 28 ).<br>
                            demikian yang dapat kami sampaikan terima kasih <br>
                            <br><br>
                            link pembayaran di bawah ini   <br>
                            https://quenbytekniksejahtera.com/mybilling/portal.php?idselect=$customerID    <br>
                            <br>
                            <br>
                            Informasi lanjut, hubungi Customer Service FIBERQ WIFI di  +62 877-4031-7266 , email: 
                            qts@quenbytekniksejahtera.com, whatsapp +62 877-4031-7266. <br>
                            <br>
                            Hormat kami<br>
                            $server WIFI<br>");
    $mail->Send();

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


    $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Pelanggan $customerID ($customerName) berhasil di" . ($fromBerhenti === '1' ? 'registrasi ulang' : 'daftarkan') . " di server $user, paket $packages, area $area";
    // Simpan ke file history
    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));



    ////////////////////////////////////////////////////










}
// Ambil info ACS server milik user ini (untuk ditampilkan di notifikasi sukses)
$regAcsUrl  = '';
$regAcsUser = '';
$regAcsPass = '';
$checkAcsTbl = mysqli_query($conn, "SHOW TABLES LIKE 'acs_servers'");
if ($checkAcsTbl && mysqli_num_rows($checkAcsTbl) > 0) {
    $regOwnerId = isset($current_user_id) ? (int)$current_user_id : 0;
    $stmtAcs = $conn->prepare("SELECT domain, port, username_acs, password_acs FROM acs_servers WHERE owner_id = ? AND status = 'RUNNING' ORDER BY id ASC LIMIT 1");
    if (!$stmtAcs) {
        $stmtAcs = $conn->prepare("SELECT domain, port, username_acs, password_acs FROM acs_servers WHERE owner_id = ? ORDER BY id ASC LIMIT 1");
    }
    if ($stmtAcs) {
        $stmtAcs->bind_param('i', $regOwnerId);
        $stmtAcs->execute();
        $rowAcs = $stmtAcs->get_result()->fetch_assoc();
        $stmtAcs->close();
        if ($rowAcs) {
            $domainRaw  = (string)$rowAcs['domain'];
            $parsedAcs  = parse_url(strpos($domainRaw, '://') !== false ? $domainRaw : 'http://' . $domainRaw);
            $domainHost = $parsedAcs['host'] ?? $domainRaw;
            $cwmpPort   = (int)$rowAcs['port'] + 1;
            $regAcsUrl  = 'http://' . $domainHost . ':' . $cwmpPort;
            $regAcsUser = (string)$rowAcs['username_acs'];
            $regAcsPass = (string)$rowAcs['password_acs'];
        }
    }
}

redirectToTop("../tables.php?pesan=berhasil"
    . "&text="     . urlencode("Success " . ($fromBerhenti === '1' ? 'Re-register' : 'Register') . " $customerName $customerID")
    . "&idpel="    . urlencode($customerID)
    . "&nama="     . urlencode($customerName)
    . "&pppoe_p="  . urlencode($passwordPPPOE)
    . "&acs_url="  . urlencode($regAcsUrl)
    . "&acs_user=" . urlencode($regAcsUser)
    . "&acs_pass=" . urlencode($regAcsPass)
);
