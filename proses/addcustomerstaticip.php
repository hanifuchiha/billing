<?php
ob_start();
require '../cek-sesi.php';
require '../routeros_api.class.php';
require '../radius_sync_lib.php';
require_once __DIR__ . '/../staticip_helper.php';
staticipEnsureSchema($conn);

$checkConnModeCol = @mysqli_query($conn, "SHOW COLUMNS FROM server LIKE 'CONNECTION_MODE'");
if ($checkConnModeCol && mysqli_num_rows($checkConnModeCol) === 0) {
    @mysqli_query($conn, "ALTER TABLE server ADD COLUMN CONNECTION_MODE ENUM('API','RADIUS_ONLY') NOT NULL DEFAULT 'API'");
}

// Mode JSON (dipakai submit fetch() dari tablesstaticip.php, wajib supaya
// alur "buat pelanggan dulu, baru registrasi ONU ke OLT" -- lihat blok OLT
// auto-register di tablesstaticip.php -- bisa jalan sekuensial. Pola SAMA
// PERSIS dgn proses/addcustomer.php (jangan diubah tanpa menyamakan juga
// di sana).
function wantsJsonResponse() {
    return (($_POST['response_mode'] ?? '') === 'json');
}

function appendProvisionLog($message) {
    if (!isset($GLOBALS['provision_logs']) || !is_array($GLOBALS['provision_logs'])) {
        $GLOBALS['provision_logs'] = [];
    }
    $GLOBALS['provision_logs'][] = '[' . date('H:i:s') . '] ' . $message;
}

function redirectAddCustomerStatic($status, $text = '') {
    $url = "../tablesstaticip.php?pesan=" . urlencode($status);
    if ($text !== '') {
        $url .= "&text=" . urlencode($text);
    }

    if (wantsJsonResponse()) {
        $buffered = ob_get_contents();
        if ($buffered !== false) {
            $trimmedBuffered = trim((string) $buffered);
            if ($trimmedBuffered !== '') {
                appendProvisionLog('Warning output terdeteksi sebelum JSON: ' . substr($trimmedBuffered, 0, 180));
            }
            @ob_clean();
        }
        $jsonRedirect = $url;
        if (!preg_match('#^(https?:)?//#i', (string) $jsonRedirect) && strpos((string) $jsonRedirect, '/') !== 0) {
            $jsonRedirect = preg_replace('#^\.\./#', '', (string) $jsonRedirect);
            $jsonRedirect = '/crm/billing/' . ltrim((string) $jsonRedirect, '/');
        }

        $success = ($status === 'berhasil');
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success' => $success,
            'redirect' => $jsonRedirect,
            'message' => $text,
            'logs' => $GLOBALS['provision_logs'] ?? [],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    @ob_end_clean();
    header("Location: " . $url);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST)) {
    redirectAddCustomerStatic('gagal', 'Metode tidak valid');
}

$customerID    = trim((string) ($_POST['customerID'] ?? ''));
$passwordPPPOE = (string) ($_POST['passwordPPPOE'] ?? '');
$customerName  = $_POST['customerName'] ?? '';
$address       = $_POST['address'] ?? '';
$whatsapp      = $_POST['whatsapp'] ?? '';
$email         = $_POST['Email'] ?? '';
$coordinates   = $_POST['coordinates'] ?? '';
$server        = $_POST['server'] ?? '';
$area          = $_POST['area'] ?? '';
$sales         = $_POST['sales'] ?? '';
$odp           = $_POST['odp'] ?? '';
$tipe_bayar    = $_POST['tipe_bayar'] ?? '';
$tipe_tempo    = $_POST['tipe_tempo'] ?? '';
$packages      = $_POST['packages'] ?? '';
$tanggalpasang = $_POST['tanggalpasang'] ?? date('Y-m-d');
$authmode      = $_POST['authmode'] ?? 'RADIUS MODE';
$ipStatic      = trim((string) ($_POST['ip_static'] ?? ''));
$NIK           = mysqli_real_escape_string($conn, trim((string) ($_POST['NIK'] ?? '')));
$provinsi      = mysqli_real_escape_string($conn, $_POST['provinsi'] ?? '');
$kabupaten     = mysqli_real_escape_string($conn, $_POST['kabupaten'] ?? '');
$kecamatan     = mysqli_real_escape_string($conn, $_POST['kecamatan'] ?? '');
$kelurahan     = mysqli_real_escape_string($conn, $_POST['kelurahan'] ?? '');
$rw            = mysqli_real_escape_string($conn, $_POST['rw'] ?? '');
$rt            = mysqli_real_escape_string($conn, $_POST['rt'] ?? '');

// --- Validasi Customer ID / password PPPoE -- aturan sama persis addcustomer.php
// (lihat komentar di sana soal kenapa: file users FreeRADIUS gagal parse total
// kalau ada spasi/kutip-dua/backslash di username atau password). ---
if ($customerID === '') {
    redirectAddCustomerStatic('gagal', 'Customer ID / username PPPoE wajib diisi');
}
if (preg_match('/[\x00-\x20"\\\\\x7F]/', $customerID) || $customerID[0] === '#' || strlen($customerID) > 64) {
    redirectAddCustomerStatic('gagal', 'Customer ID / username PPPoE tidak boleh mengandung spasi, tanda kutip dua ("), backslash (\\), tidak boleh diawali (#), dan maksimal 64 karakter');
}
if (trim($passwordPPPOE) === '') {
    redirectAddCustomerStatic('gagal', 'Password PPPoE wajib diisi');
}
if (preg_match('/[\s"\\\\]/', $passwordPPPOE)) {
    redirectAddCustomerStatic('gagal', 'Password PPPoE tidak boleh mengandung spasi, tanda kutip (") atau backslash (\\)');
}
if ($server === '' || $area === '' || $packages === '') {
    redirectAddCustomerStatic('gagal', 'Server Area dan Paket wajib dipilih');
}

$customerID_sql = mysqli_real_escape_string($conn, $customerID);
$passwordPPPOE_sql = mysqli_real_escape_string($conn, $passwordPPPOE);

$check_result = mysqli_query($conn, "SELECT IDPEL FROM pelanggan WHERE IDPEL = '$customerID_sql'");
if ($check_result && mysqli_num_rows($check_result) > 0) {
    redirectAddCustomerStatic('gagal', "Customer ID $customerID sudah ada di database");
}

// Validasi IP Static: format IPv4 valid, wajib berada dalam salah satu range
// pool_staticip Area ini, dan belum dipakai pelanggan lain di Area yang sama.
// Validasi ulang server-side (bukan cuma percaya dropdown) karena bisa saja
// modal dikirim manual/di-tamper.
if ($ipStatic === '' || staticipIpToLong($ipStatic) === false) {
    redirectAddCustomerStatic('gagal', 'IP Static wajib diisi dengan format IPv4 yang valid');
}
$ipLong = staticipIpToLong($ipStatic);
$serverEsc = mysqli_real_escape_string($conn, $server);
$areaEsc = mysqli_real_escape_string($conn, $area);
$inRange = false;
$poolCheck = mysqli_query($conn, "SELECT ip_awal, ip_akhir FROM pool_staticip WHERE PEMILIK = '$serverEsc' AND AREA = '$areaEsc'");
if ($poolCheck) {
    while ($poolRow = mysqli_fetch_assoc($poolCheck)) {
        $s = staticipIpToLong((string) $poolRow['ip_awal']);
        $e = staticipIpToLong((string) $poolRow['ip_akhir']);
        if ($s !== false && $e !== false && $ipLong >= $s && $ipLong <= $e) {
            $inRange = true;
            break;
        }
    }
}
if (!$inRange) {
    redirectAddCustomerStatic('gagal', "IP $ipStatic bukan bagian dari IP Pool Static Area $area, tambahkan dulu rangenya di menu IP Pool Static");
}
$ipDupCheck = mysqli_query($conn, "SELECT IDPEL FROM pelanggan WHERE IP_STATIC = '" . mysqli_real_escape_string($conn, $ipStatic) . "' AND PEMILIK = '$serverEsc' AND AREA = '$areaEsc' LIMIT 1");
if ($ipDupCheck && mysqli_num_rows($ipDupCheck) > 0) {
    $dupRow = mysqli_fetch_assoc($ipDupCheck);
    redirectAddCustomerStatic('gagal', "IP $ipStatic sudah dipakai pelanggan {$dupRow['IDPEL']}");
}

// Normalisasi nomor WA -- persis logika addcustomer.php.
$whatsappedit = trim((string) $whatsapp);
if (!preg_match('/[^+0-9]/', trim($whatsapp))) {
    if (substr(trim($whatsapp), 0, 2) == '62') {
        $whatsappedit = trim($whatsapp);
    } elseif (substr(trim($whatsapp), 0, 3) == '+62') {
        $whatsappedit = '62' . substr(trim($whatsapp), 1);
    } elseif (substr(trim($whatsapp), 0, 1) == '0') {
        $whatsappedit = '62' . substr(trim($whatsapp), 1);
    }
}

$sql = "SELECT * FROM `server` WHERE `AREA` = '$areaEsc' AND `PEMILIK` = '$serverEsc'";
$query = mysqli_query($conn, $sql);
$BRAND = '';
$tempo = '';
$dataServer = $query ? mysqli_fetch_array($query) : null;

if (!$dataServer) {
    redirectAddCustomerStatic('gagal', 'Server Area tidak ditemukan');
}

$BRAND = $dataServer['BRAND'];
$tempo = $dataServer['TEMPO'];

// Server RADIUS SAJA tidak punya API Mikrotik untuk dihubungi -- auth mode WAJIB
// RADIUS MODE, abaikan pilihan API MODE/MULTI MODE dari form (persis addcustomer.php).
if (($dataServer['CONNECTION_MODE'] ?? 'API') === 'RADIUS_ONLY') {
    $authmode = 'RADIUS MODE';
}

// Paket harus benar Paket Static IP milik server+area ini (bukan bisa ditukar
// paket PPPoE biasa lewat manipulasi POST).
$paket_q = mysqli_query($conn, "SELECT * FROM paket WHERE PAKET='" . mysqli_real_escape_string($conn, $packages) . "' AND PEMILIK='$serverEsc' AND AREA='$areaEsc' AND TIPE_LAYANAN='PPPOE_STATIC' ORDER BY id DESC LIMIT 1");
$paketRow = ($paket_q && mysqli_num_rows($paket_q) > 0) ? mysqli_fetch_assoc($paket_q) : null;
if (!$paketRow) {
    redirectAddCustomerStatic('gagal', "Paket Static IP '$packages' tidak ditemukan di Server Area ini");
}

// --- Provisioning: PERSIS pola addcustomer.php, cuma tambah remote-address /
// Framed-IP-Address dari $ipStatic. ---
appendProvisionLog('Menyiapkan provisioning PPPoE (' . $authmode . ') utk ' . $customerID);
if ($authmode === 'API MODE' || $authmode === 'MULTI MODE') {
    $API = new RouterosAPI();
    $API->connect($dataServer['IP'], $dataServer['PEMILIK'], $dataServer['PASSWORD']);

    $existing = $API->comm("/ppp/secret/print", array("?name" => $customerID));
    if (!empty($existing)) {
        redirectAddCustomerStatic('gagal', "Customer ID $customerID sudah ada di server {$dataServer['PEMILIK']}");
    }

    $secretParams = array(
        "name"           => $customerID,
        "password"       => $passwordPPPOE,
        "profile"        => $packages,
        "service"        => "any",
        "remote-address" => $ipStatic,
        "comment"        => "STATIC IP BARU " . $customerName . "-" . $whatsappedit . "-" . $tanggalpasang,
    );
    $API->comm("/ppp/secret/add", $secretParams);
}

if ($authmode === 'RADIUS MODE' || $authmode === 'MULTI MODE') {
    $dup = false;
    foreach (radiusReadMergedBlocks() as $b) {
        if ($b['username'] === $customerID) {
            $dup = true;
            break;
        }
    }
    if ($dup) {
        redirectAddCustomerStatic('gagal', "Customer ID $customerID sudah ada di Radius");
    }

    radiusSyncSingleCustomerNow($customerID, $passwordPPPOE, $paketRow, true, radiusGetGlobalSettings($conn), $ipStatic);
}

// --- Simpan ke database ---
$tanggal_monthversary_awal = ($tipe_tempo === 'monthversary') ? $tanggalpasang : null;
$tanggal_monthversary_sql = $tanggal_monthversary_awal !== null
    ? "'" . mysqli_real_escape_string($conn, $tanggal_monthversary_awal) . "'"
    : 'NULL';

$sql2 = "INSERT INTO `pelanggan` (`PASSWORD`,`IDPEL`,`NIK`,`NAMA`,`TIPE_BAYAR`,`TIPE_TEMPO`,`PAKET`,`HARGA`,`TANGGALPASANG`,`NOWA`,`EMAIL`,`ALAMAT`,`TEMPO`,`PEMILIK`,`MODE`,`ODP`,`AREA`,`provinsi`,`kabupaten`,`kecamatan`,`kelurahan`,`rw`,`rt`,`TIKOR`,`sales`,`BRAND`,`TANGGAL_MONTHVERSARY`,`TIPE_LAYANAN`,`IP_STATIC`) VALUES ('$passwordPPPOE_sql','$customerID_sql','$NIK','" . mysqli_real_escape_string($conn, $customerName) . "','" . mysqli_real_escape_string($conn, $tipe_bayar) . "','" . mysqli_real_escape_string($conn, $tipe_tempo) . "','" . mysqli_real_escape_string($conn, $packages) . "','-','" . mysqli_real_escape_string($conn, $tanggalpasang) . "','$whatsappedit','" . mysqli_real_escape_string($conn, $email) . "','" . mysqli_real_escape_string($conn, $address) . "','" . mysqli_real_escape_string($conn, $tempo) . "','$serverEsc','" . mysqli_real_escape_string($conn, $authmode) . "','" . mysqli_real_escape_string($conn, $odp) . "','$areaEsc','$provinsi','$kabupaten','$kecamatan','$kelurahan','$rw','$rt','" . mysqli_real_escape_string($conn, $coordinates) . "','" . mysqli_real_escape_string($conn, $sales) . "','" . mysqli_real_escape_string($conn, $BRAND) . "',$tanggal_monthversary_sql,'PPPOE_STATIC','" . mysqli_real_escape_string($conn, $ipStatic) . "')";

if (!$conn->query($sql2)) {
    redirectAddCustomerStatic('gagal', 'Gagal menyimpan ke database: ' . $conn->error);
}
appendProvisionLog('Data pelanggan berhasil disimpan ke database.');

// Auto buat transaksi PENAGIHAN awal -- persis pola addcustomer.php.
$bulan_indonesia = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$timestamp_pasang = strtotime((string) $tanggalpasang);
if ($timestamp_pasang === false) {
    $timestamp_pasang = time();
}
$periode_penggunaan = $bulan_indonesia[(int) date('n', $timestamp_pasang)] . ' ' . date('Y', $timestamp_pasang);
$harga_penagihan = (int) reseller_effective_harga($conn, $packages, $server);

$cek_trx_q = mysqli_query($conn, "SELECT id FROM transaksi WHERE IDPEL='$customerID_sql' AND PENGUNAAN='" . mysqli_real_escape_string($conn, $periode_penggunaan) . "' AND TRIM(UPPER(COALESCE(STATUS, ''))) IN ('PENAGIHAN','PERMINTAAN KODE','KONFIRMASI','BERHASIL') LIMIT 1");
if (!$cek_trx_q || mysqli_num_rows($cek_trx_q) === 0) {
    $bukti_penagihan = 'INV-REG-' . preg_replace('/[^A-Za-z0-9_-]/', '', $customerID) . '-' . date('Ym');
    mysqli_query($conn, "INSERT INTO transaksi (TANGGALBAYAR, PENGUNAAN, STATUS, IDPEL, NAMA, PAKET, HARGA, BUKTI, CEK, PEMILIK, METODE_BAYAR) VALUES ('" . date('Y-m-d') . "', '" . mysqli_real_escape_string($conn, $periode_penggunaan) . "', 'PENAGIHAN', '$customerID_sql', '" . mysqli_real_escape_string($conn, $customerName) . "', '" . mysqli_real_escape_string($conn, $packages) . "', " . (int) $harga_penagihan . ", '" . mysqli_real_escape_string($conn, $bukti_penagihan) . "', 'AUTO PENAGIHAN DARI REGISTRASI', '$serverEsc', '')");
}

$history_file = "../notifbot/data/history-$ceknama.json";
$history = [];
if (file_exists($history_file)) {
    $history = json_decode(file_get_contents($history_file), true);
}
if (!is_array($history)) { $history = []; }
$history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Pelanggan Static IP $customerID ($customerName) berhasil didaftarkan di server $server, paket $packages, IP $ipStatic";
@file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

redirectAddCustomerStatic('berhasil', "Success Register $customerName $customerID (IP $ipStatic)");
