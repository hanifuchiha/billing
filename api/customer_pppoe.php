
<?php
// api/customer_pppoe.php
// Sebelumnya cuma dukung session ATAU username+password -- tidak pernah baca
// param `key`/`api_key`, PADAHAL settingsapi.php mendokumentasikan resmi
// akses via API key. Diganti ke _bootstrap.php::api_authenticate() (session ->
// username+password -> API key dari tabel `apikey`) sama seperti api/odp.php dkk.
require_once '../koneksibilling.php';
require_once '_bootstrap.php';
session_start();
api_cors();

$method = $_SERVER['REQUEST_METHOD'];
$input = api_read_input();

$auth = api_authenticate($conn, $input);
$pemilik = $auth['pemilik'];
if ($auth['method'] === 'apikey') {
    api_rate_limit($conn, $auth['api_key']);
}

switch ($method) {
    case 'GET':
        // Ambil semua server milik user (PEMILIK/brand) berdasarkan user id
        // (dipindah ke atas filter_options=1 -- sebelumnya $pemilikList dipakai
        // di blok filter_options TAPI baru didefinisikan setelah blok itu, jadi
        // request ?filter_options=1 selalu fatal error "undefined variable".)
        $stmt = $conn->prepare("SELECT PEMILIK FROM server WHERE user_id = (SELECT id FROM user WHERE USERNAME = ?)");
        $stmt->bind_param("s", $pemilik);
        $stmt->execute();
        $res = $stmt->get_result();
        $pemilikList = [];
        while ($row = $res->fetch_assoc()) {
            $pemilikList[] = $row['PEMILIK'];
        }
        if (count($pemilikList) === 0) $pemilikList = [$pemilik];

        // Endpoint khusus: filter_options=1 untuk mengambil semua pilihan filter unik
        if (isset($_GET['filter_options']) && $_GET['filter_options'] == '1') {
            $escaped = array_map(function($v) use ($conn) { return "'" . $conn->real_escape_string($v) . "'"; }, $pemilikList);
            $in = implode(",", $escaped);
            $where = "PEMILIK IN ($in)";
            $areas = [];
            $status = [];
            $paket = [];
            $q = $conn->query("SELECT DISTINCT AREA FROM pelanggan WHERE $where AND AREA != '' ORDER BY AREA ASC");
            while ($row = $q->fetch_assoc()) { $areas[] = $row['AREA']; }
            $q = $conn->query("SELECT DISTINCT STATUS FROM pelanggan WHERE $where AND STATUS != '' ORDER BY STATUS ASC");
            while ($row = $q->fetch_assoc()) { $status[] = $row['STATUS']; }
            $q = $conn->query("SELECT DISTINCT PAKET FROM pelanggan WHERE $where AND PAKET != '' ORDER BY PAKET ASC");
            while ($row = $q->fetch_assoc()) { $paket[] = $row['PAKET']; }
            echo json_encode(['success' => true, 'areas' => $areas, 'status' => $status, 'paket' => $paket]);
            exit;
        }


        // Ambil filter dari GET
        $filter_area = isset($_GET['area']) ? $conn->real_escape_string($_GET['area']) : '';
        $filter_status = isset($_GET['status']) ? $conn->real_escape_string($_GET['status']) : '';
        $filter_paket = isset($_GET['paket']) ? $conn->real_escape_string($_GET['paket']) : '';
        $filter_search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
        $filter_odp = isset($_GET['odp']) ? $conn->real_escape_string($_GET['odp']) : '';

        $escaped = array_map(function($v) use ($conn) { return "'" . $conn->real_escape_string($v) . "'"; }, $pemilikList);
        $in = implode(",", $escaped);
        $where = "PEMILIK IN ($in)";
        if ($filter_area !== '') {
            $where .= " AND AREA = '$filter_area'";
        }
        if ($filter_status !== '') {
            $where .= " AND STATUS = '$filter_status'";
        }
        if ($filter_paket !== '') {
            $where .= " AND PAKET = '$filter_paket'";
        }
        if ($filter_odp !== '') {
            $where .= " AND ODP = '$filter_odp'";
        }
        if ($filter_search !== '') {
            $where .= " AND (IDPEL LIKE '%$filter_search%' OR NAMA LIKE '%$filter_search%' OR NOWA LIKE '%$filter_search%')";
        }
        $sql = "SELECT * FROM pelanggan WHERE $where";
        $result = $conn->query($sql);
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }


        // --- Ambil statistik Mikrotik hanya dari serverlog/[PEMILIK].txt utama ---
        $stat = [
            'total_user' => 0,
            'user_online' => 0,
            'user_expired_online' => 0,
            'user_expired_offline' => 0,
            'user_los' => 0
        ];
        $logfile = dirname(__DIR__) . "/serverlog/" . $pemilik . ".txt";
        if (file_exists($logfile)) {
            $json = file_get_contents($logfile);
            $d = json_decode($json, true);
            if (is_array($d)) {
                $stat['total_user'] = isset($d['Total_pelanggan']) ? (int)$d['Total_pelanggan'] : 0;
                $stat['user_online'] = isset($d['Total_online_paket']) ? (int)$d['Total_online_paket'] : 0;
                $stat['user_expired_online'] = isset($d['Total_online_expired']) ? (int)$d['Total_online_expired'] : 0;
                $stat['user_expired_offline'] = isset($d['Total_expired_offline']) ? (int)$d['Total_expired_offline'] : 0;
                $stat['user_los'] = isset($d['Total_los_internet']) ? (int)$d['Total_los_internet'] : 0;
            }
        }

        echo json_encode(['success' => true, 'data' => $data, 'stat' => $stat]);
        break;
    case 'POST':
        // Tambah pelanggan PPPoE dengan provisioning ke Mikrotik/Radius dan auto-penagihan
        require_once '../routeros_api.class.php';
        require_once '../radius_sync_lib.php';
        $data = $input;
        $nama = $data['nama'] ?? '';
        $username_pelanggan = $data['username_pelanggan'] ?? '';
        $profile = $data['profile'] ?? '';
        $server = $data['server'] ?? '';
        $expired = $data['expired'] ?? '';
        $status = $data['status'] ?? '';
        $password_pppoe = $data['password'] ?? '';
        $authmode = $data['authmode'] ?? 'RADIUS MODE';
        if (!in_array($authmode, ['RADIUS MODE', 'API MODE', 'MULTI MODE'], true)) {
            $authmode = ($authmode === 'radius') ? 'RADIUS MODE' : 'API MODE';
        }
        $paket = $data['paket'] ?? $profile;
        $area = $data['area'] ?? '';
        $odp = $data['odp'] ?? '';
        $alamat = $data['alamat'] ?? '';
        $nowa = $data['nowa'] ?? '';
        $email = $data['email'] ?? '';
        $tanggalpasang = $data['tanggalpasang'] ?? date('Y-m-d');
        $tipe_bayar = $data['tipe_bayar'] ?? '';
        $tipe_tempo = $data['tipe_tempo'] ?? '';
        $provinsi = $data['provinsi'] ?? '';
        $kabupaten = $data['kabupaten'] ?? '';
        $kecamatan = $data['kecamatan'] ?? '';
        $kelurahan = $data['kelurahan'] ?? '';
        $rw = $data['rw'] ?? '';
        $rt = $data['rt'] ?? '';
        $tikor = $data['tikor'] ?? '';
        $sales = $data['sales'] ?? '';
        $brand = '';
        // Validasi
        if (!$nama || !$username_pelanggan || !$profile || !$server || !$expired || !$password_pppoe) {
            echo json_encode(['success' => false, 'error' => 'Data tidak lengkap']);
            exit;
        }
        // Ambil data server
        $sql = "SELECT * FROM `server` WHERE `AREA` = '" . mysqli_real_escape_string($conn, $area) . "' and `PEMILIK`= '" . mysqli_real_escape_string($conn, $server) . "' ";
        $query = mysqli_query($conn, $sql);
        if ($query && $row = mysqli_fetch_array($query)) {
            $brand = $row['BRAND'];
            // Provisioning ke Mikrotik jika perlu
            if ($authmode === 'API MODE' || $authmode === 'MULTI MODE') {
                $user = $row['PEMILIK'];
                $ip = $row['IP'];
                $password = $row['PASSWORD'];
                $API = new RouterosAPI();
                if ($API->connect($ip, $user, $password)) {
                    $existing = $API->comm("/ppp/secret/print", array("?name" => $username_pelanggan));
                    if (!empty($existing)) {
                        echo json_encode(['success' => false, 'error' => 'Customer ID sudah ada di server']);
                        exit;
                    }
                    $API->comm(
                        "/ppp/secret/add",
                        array(
                            "name"     => $username_pelanggan,
                            "password" => $password_pppoe,
                            "profile"  => $paket,
                            "service"  => "any",
                            "comment"  => "BARU $nama-$nowa-$tanggalpasang"
                        )
                    );
                }
            }
            // Provisioning ke Radius jika perlu -- lewat radius_sync_lib.php
            // (bukan file_get_contents/FILE_APPEND mentah ke satu file saja)
            // supaya entry ditulis konsisten di KEDUA path (users + mirror
            // mods-config/files/authorize) dan restart-nya pakai cara yang
            // benar untuk FreeRADIUS 3 (radiusReloadIfChanged), bukan
            // `systemctl restart` polos yang tidak selalu memuat ulang modul
            // files dengan bersih.
            if ($authmode === 'RADIUS MODE' || $authmode === 'MULTI MODE' || $authmode === 'radius') {
                $dup = false;
                foreach (radiusReadMergedBlocks() as $b) {
                    if ($b['username'] === $username_pelanggan) {
                        $dup = true;
                        break;
                    }
                }
                if ($dup) {
                    echo json_encode(['success' => false, 'error' => 'Customer ID sudah ada di Radius']);
                    exit;
                }
                $paket_q = mysqli_query($conn, "SELECT * FROM paket WHERE PAKET='" . mysqli_real_escape_string($conn, $paket) . "' AND PEMILIK='" . mysqli_real_escape_string($conn, $server) . "' ORDER BY id DESC LIMIT 1");
                $paketRow = ($paket_q && mysqli_num_rows($paket_q) > 0) ? mysqli_fetch_assoc($paket_q) : ['PAKET' => $paket, 'KECEPATAN' => ''];
                radiusSyncSingleCustomerNow($username_pelanggan, $password_pppoe, $paketRow, true, radiusGetGlobalSettings($conn));
            }
        }
        // Insert ke database pelanggan
        // PENTING: MODE harus diisi dari $authmode (RADIUS MODE/API MODE/MULTI
        // MODE) yang sebenarnya dipilih user -- sebelumnya di sini di-hardcode
        // literal 'pppoe' sehingga pelanggan yang dibuat lewat endpoint ini
        // SELALU dianggap "mode tidak valid" oleh sync_freeradius_users.php,
        // meskipun sudah diprovisioning ke RADIUS di atas (baris 197-210).
        $stmt = $conn->prepare("INSERT INTO pelanggan (PASSWORD, IDPEL, NAMA, TIPE_BAYAR, TIPE_TEMPO, PAKET, HARGA, TANGGALPASANG, NOWA, EMAIL, ALAMAT, TEMPO, PEMILIK, MODE, ODP, AREA, provinsi, kabupaten, kecamatan, kelurahan, rw, rt, TIKOR, sales, BRAND, USERNAME, PROFILE, SERVER, EXPIRED, STATUS) VALUES (?, ?, ?, ?, ?, ?, '-', ?, ?, ?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        // PENTING JUGA: sebelumnya ada $expired yang tertulis DUA KALI di sini
        // (sekali salah tempat tepat setelah $alamat, sekali lagi di posisi
        // yang benar dekat akhir). Itu menggeser SEMUA variabel setelah $alamat
        // maju satu posisi terhadap kolomnya (PEMILIK kebagian nilai EXPIRED,
        // ODP kebagian nilai PEMILIK/brand, AREA kebagian nilai ODP, dst,
        // sampai STATUS di akhir tidak kebagian nilai sama sekali). Baris ini
        // sudah dibetulkan supaya urutan variabel PERSIS sama dengan urutan
        // kolom di daftar INSERT di atas.
        $stmt->bind_param('ssssssssssssssssssssssssssss',
            $password_pppoe, $username_pelanggan, $nama, $tipe_bayar, $tipe_tempo, $paket, $tanggalpasang, $nowa, $email, $alamat, $pemilik, $authmode, $odp, $area, $provinsi, $kabupaten, $kecamatan, $kelurahan, $rw, $rt, $tikor, $sales, $brand, $username_pelanggan, $profile, $server, $expired, $status
        );
        $ok = $stmt->execute();
        // Auto-penagihan
        if ($ok) {
            $bulan_indonesia = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
            $timestamp_pasang = strtotime((string)$tanggalpasang);
            if ($timestamp_pasang === false) $timestamp_pasang = time();
            $periode_penggunaan = $bulan_indonesia[(int)date('n', $timestamp_pasang)] . ' ' . date('Y', $timestamp_pasang);
            $tanggal_penagihan = date('Y-m-d');
            $harga_penagihan = 0;
            $harga_q = mysqli_query($conn, "SELECT HARGA FROM paket WHERE PAKET='" . mysqli_real_escape_string($conn, $paket) . "' AND PEMILIK='" . mysqli_real_escape_string($conn, $server) . "' ORDER BY id DESC LIMIT 1");
            if ($harga_q && mysqli_num_rows($harga_q) > 0) {
                $harga_row = mysqli_fetch_assoc($harga_q);
                $harga_penagihan = (int)($harga_row['HARGA'] ?? 0);
            }
            $cek_trx_q = mysqli_query($conn, "SELECT id FROM transaksi WHERE IDPEL='" . mysqli_real_escape_string($conn, $username_pelanggan) . "' AND PENGUNAAN='" . mysqli_real_escape_string($conn, $periode_penggunaan) . "' AND TRIM(UPPER(COALESCE(STATUS, ''))) IN ('PENAGIHAN','PERMINTAAN KODE','KONFIRMASI','BERHASIL') LIMIT 1");
            if (!$cek_trx_q || mysqli_num_rows($cek_trx_q) === 0) {
                $bukti_penagihan = 'INV-REG-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$username_pelanggan) . '-' . date('Ym');
                $cek_penagihan = 'AUTO PENAGIHAN DARI REGISTRASI';
                $status_penagihan = 'PENAGIHAN';
                mysqli_query($conn, "INSERT INTO transaksi (TANGGALBAYAR, PENGUNAAN, STATUS, IDPEL, NAMA, PAKET, HARGA, BUKTI, CEK, PEMILIK, METODE_BAYAR) VALUES ('" . mysqli_real_escape_string($conn, $tanggal_penagihan) . "', '" . mysqli_real_escape_string($conn, $periode_penggunaan) . "', '" . mysqli_real_escape_string($conn, $status_penagihan) . "', '" . mysqli_real_escape_string($conn, $username_pelanggan) . "', '" . mysqli_real_escape_string($conn, $nama) . "', '" . mysqli_real_escape_string($conn, $paket) . "', " . (int)$harga_penagihan . ", '" . mysqli_real_escape_string($conn, $bukti_penagihan) . "', '" . mysqli_real_escape_string($conn, $cek_penagihan) . "', '" . mysqli_real_escape_string($conn, $server) . "', '')");
            }
        }
        // === NOTIFIKASI, LOG, EMAIL, ACS ===
        $notif_success = false;
        $notif_error = '';
        $log_success = false;
        $log_error = '';
        $email_success = false;
        $email_error = '';
        $acs_info = [];

        // --- WhatsApp Notification ---
        require_once '../crm/billing/notifbot/whatsapp_helper.php';
        require_once '../crm/billing/notifbot/bot_selector_helper.php';
        $ceknama = $server;
        $history_file = '../crm/billing/notifbot/data/history-' . $ceknama . '.json';
        $history = [];
        if (file_exists($history_file)) {
            $history = json_decode(file_get_contents($history_file), true);
        }
        if (!is_array($history)) $history = [];

        // Bot selection
        $botname = '';
        $waapi = '';
        $botpass = '';
        $botResult = selectBotForNotification($conn, $pemilik, 'RANDOM');
        if ($botResult['success']) {
            $botname = $botResult['namebot'];
            $waapi = $botResult['addressbot'];
            $botpass = $botResult['password'];
        }

        // WhatsApp message template
        $message = "Halo $nama, layanan PPPoE Anda telah berhasil didaftarkan. Username: $username_pelanggan, Paket: $paket, Tanggal Pasang: $tanggalpasang. Terima kasih telah bergabung.";
        $wa_result = sendWhatsappMessage($nowa, $message, $botname, $waapi, $botpass, $history, $history_file);
        $notif_success = $wa_result['success'];
        $notif_error = $wa_result['error'] ?? '';

        // --- Email Notification ---
        if (!empty($email)) {
            require_once '../crm/billing/notifbot/phpmailer/classes/class.phpmailer.php';
            $mail = new PHPMailer();
            $mail->IsSMTP();
            $mail->SMTPSecure = '';
            $mail->Host = 'mail.quenbytekniksejahtera.com';
            $mail->SMTPDebug = 0;
            $mail->Port = 25;
            $mail->SMTPAuth = false;
            $mail->Username = 'helpdesk@quenbytekniksejahtera.com';
            $mail->Password = 'helpdeskqts';
            $mail->SetFrom('helpdesk@quenbytekniksejahtera.com', 'FIBERQ');
            $mail->Subject = 'Aktivasi wifi FIBERQ berhasil';
            $mail->AddAddress($email, "$nama - $username_pelanggan");
            $mail->MsgHTML("Yth. Bapak/Ibu $nama<br><br>Terima kasih telah mendaftar layanan kami.<br>Username: $username_pelanggan<br>Paket: $paket<br>Tanggal Pasang: $tanggalpasang<br><br>Info lebih lanjut hubungi Customer Service.");
            $email_success = $mail->Send();
            if (!$email_success) $email_error = $mail->ErrorInfo;
        }

        // --- Log History ---
        $log_entry = "[API] " . date('Y-m-d H:i:s') . " | $username_pelanggan | $nama | $paket | $nowa | $email | $alamat | PPPoE customer registered via API.";
        $history[] = $log_entry;
        $log_success = (file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT)) !== false);
        if (!$log_success) $log_error = 'Failed to write log history.';

        // --- ACS Info (if available) ---
        $acs_info = [];
        $checkAcsTbl = mysqli_query($conn, "SHOW TABLES LIKE 'acs_servers'");
        if ($checkAcsTbl && mysqli_num_rows($checkAcsTbl) > 0) {
            $regOwnerId = 0;
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
                    $acs_info  = [
                        'url' => 'http://' . $domainHost . ':' . $cwmpPort,
                        'user' => (string)$rowAcs['username_acs'],
                        'pass' => (string)$rowAcs['password_acs']
                    ];
                }
            }
        }

        echo json_encode([
            'success' => $ok,
            'notif_success' => $notif_success,
            'notif_error' => $notif_error,
            'email_success' => $email_success,
            'email_error' => $email_error,
            'log_success' => $log_success,
            'log_error' => $log_error,
            'acs_info' => $acs_info
        ]);
        break;
    case 'PUT':
        // Update pelanggan PPPoE
        $data = $input;
        $id = $data['id'] ?? '';
        $nama = $data['nama'] ?? '';
        $username_pelanggan = $data['username_pelanggan'] ?? '';
        $profile = $data['profile'] ?? '';
        $server = $data['server'] ?? '';
        $expired = $data['expired'] ?? '';
        $status = $data['status'] ?? '';
        if (!$id || !$nama || !$username_pelanggan || !$profile || !$server || !$expired) {
            echo json_encode(['success' => false, 'error' => 'Data tidak lengkap']);
            exit;
        }
        $stmt = $conn->prepare("UPDATE pelanggan SET NAMA=?, USERNAME=?, PROFILE=?, SERVER=?, EXPIRED=?, STATUS=? WHERE ID=? AND MODE='pppoe' AND PEMILIK=?");
        $stmt->bind_param('ssssssss', $nama, $username_pelanggan, $profile, $server, $expired, $status, $id, $pemilik);
        $ok = $stmt->execute();
        echo json_encode(['success' => $ok]);
        break;
    case 'DELETE':
        // Hapus pelanggan PPPoE
        $data = $input;
        $id = $data['id'] ?? '';
        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'ID tidak ditemukan']);
            exit;
        }
        $stmt = $conn->prepare("DELETE FROM pelanggan WHERE ID=? AND MODE='pppoe' AND PEMILIK=?");
        $stmt->bind_param('ss', $id, $pemilik);
        $ok = $stmt->execute();
        echo json_encode(['success' => $ok]);
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Method tidak didukung']);
}
