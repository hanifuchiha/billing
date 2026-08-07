<?php
// api/customer_hotspot.php
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
        // Ambil semua pelanggan Hotspot milik pemilik
        $search = trim($_GET['search'] ?? '');
        $where = "MODE='hotspot' AND PEMILIK='" . mysqli_real_escape_string($conn, $pemilik) . "'";
        if ($search !== '') {
            $searchEsc = mysqli_real_escape_string($conn, $search);
            $where .= " AND (IDPEL LIKE '%$searchEsc%' OR NAMA LIKE '%$searchEsc%' OR PAKET LIKE '%$searchEsc%' OR USERNAME LIKE '%$searchEsc%')";
        }
        $result = mysqli_query($conn, "SELECT * FROM pelanggan WHERE $where ORDER BY ID DESC");
        $data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $data[] = $row;
        }
        echo json_encode(['success' => true, 'data' => $data]);
        break;
    case 'POST':
        // Tambah pelanggan Hotspot dengan provisioning dan notifikasi
        require_once '../routeros_api.class.php';
        $data = $input;
        $nama = $data['nama'] ?? '';
        $username_pelanggan = $data['username_pelanggan'] ?? '';
        $profile = $data['profile'] ?? '';
        $server = $data['server'] ?? '';
        $expired = $data['expired'] ?? '';
        $status = $data['status'] ?? '';
        $password_hotspot = $data['password'] ?? '';
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
        if (!$nama || !$username_pelanggan || !$profile || !$server || !$expired || !$password_hotspot) {
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
                    $existing = $API->comm("/ip/hotspot/user/print", array("?name" => $username_pelanggan));
                    if (!empty($existing)) {
                        echo json_encode(['success' => false, 'error' => 'Customer ID sudah ada di server']);
                        exit;
                    }
                    $API->comm(
                        "/ip/hotspot/user/add",
                        array(
                            "name"     => $username_pelanggan,
                            "password" => $password_hotspot,
                            "profile"  => $paket,
                            "comment"  => "BARU $nama-$nowa-$tanggalpasang"
                        )
                    );
                }
            }
            // Provisioning ke Radius jika perlu
            if ($authmode === 'RADIUS MODE' || $authmode === 'MULTI MODE' || $authmode === 'radius') {
                $users_file = "/etc/freeradius/3.0/users";
                $file_content = file_get_contents($users_file);
                if (strpos($file_content, "$username_pelanggan Cleartext-Password") !== false) {
                    echo json_encode(['success' => false, 'error' => 'Customer ID sudah ada di Radius']);
                    exit;
                }
                $entry = "$username_pelanggan Cleartext-Password := \"$password_hotspot\"\n";
                $entry .= "\tMikrotik-Group := \"$paket\"\n\n";
                file_put_contents($users_file, $entry, FILE_APPEND);
                shell_exec('sudo systemctl restart freeradius');
            }
        }
        // Insert ke database pelanggan
        // MODE diisi dari $authmode (bukan literal 'hotspot'), dan $expired yang
        // sebelumnya tertulis dua kali (menggeser PEMILIK..STATUS satu posisi)
        // sudah dibetulkan -- lihat penjelasan lengkap di customer_pppoe.php.
        $stmt = $conn->prepare("INSERT INTO pelanggan (PASSWORD, IDPEL, NAMA, TIPE_BAYAR, TIPE_TEMPO, PAKET, HARGA, TANGGALPASANG, NOWA, EMAIL, ALAMAT, TEMPO, PEMILIK, MODE, ODP, AREA, provinsi, kabupaten, kecamatan, kelurahan, rw, rt, TIKOR, sales, BRAND, USERNAME, PROFILE, SERVER, EXPIRED, STATUS) VALUES (?, ?, ?, ?, ?, ?, '-', ?, ?, ?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('ssssssssssssssssssssssssssss',
            $password_hotspot, $username_pelanggan, $nama, $tipe_bayar, $tipe_tempo, $paket, $tanggalpasang, $nowa, $email, $alamat, $pemilik, $authmode, $odp, $area, $provinsi, $kabupaten, $kecamatan, $kelurahan, $rw, $rt, $tikor, $sales, $brand, $username_pelanggan, $profile, $server, $expired, $status
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
            $harga_q = mysqli_query($conn, "SELECT HARGA FROM paket_hotspot WHERE NAMA='" . mysqli_real_escape_string($conn, $paket) . "' AND PEMILIK='" . mysqli_real_escape_string($conn, $server) . "' ORDER BY id DESC LIMIT 1");
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
        $message = "Halo $nama, layanan Hotspot Anda telah berhasil didaftarkan. Username: $username_pelanggan, Paket: $paket, Tanggal Pasang: $tanggalpasang. Terima kasih telah bergabung.";
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
            $mail->Subject = 'Aktivasi Hotspot FIBERQ berhasil';
            $mail->AddAddress($email, "$nama - $username_pelanggan");
            $mail->MsgHTML("Yth. Bapak/Ibu $nama<br><br>Terima kasih telah mendaftar layanan Hotspot kami.<br>Username: $username_pelanggan<br>Paket: $paket<br>Tanggal Pasang: $tanggalpasang<br><br>Info lebih lanjut hubungi Customer Service.");
            $email_success = $mail->Send();
            if (!$email_success) $email_error = $mail->ErrorInfo;
        }

        // --- Log History ---
        $log_entry = "[API] " . date('Y-m-d H:i:s') . " | $username_pelanggan | $nama | $paket | $nowa | $email | $alamat | Hotspot customer registered via API.";
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
        // Update pelanggan Hotspot
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
        $stmt = $conn->prepare("UPDATE pelanggan SET NAMA=?, USERNAME=?, PROFILE=?, SERVER=?, EXPIRED=?, STATUS=? WHERE ID=? AND MODE='hotspot' AND PEMILIK=?");
        $stmt->bind_param('ssssssss', $nama, $username_pelanggan, $profile, $server, $expired, $status, $id, $pemilik);
        $ok = $stmt->execute();
        echo json_encode(['success' => $ok]);
        break;
    case 'DELETE':
        // Hapus pelanggan Hotspot
        $data = $input;
        $id = $data['id'] ?? '';
        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'ID tidak ditemukan']);
            exit;
        }
        $stmt = $conn->prepare("DELETE FROM pelanggan WHERE ID=? AND MODE='hotspot' AND PEMILIK=?");
        $stmt->bind_param('ss', $id, $pemilik);
        $ok = $stmt->execute();
        echo json_encode(['success' => $ok]);
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Method tidak didukung']);
}
