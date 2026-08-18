<?php
ob_start();
require '../cek-sesi.php';
require '../routeros_api.class.php';
require_once __DIR__ . '/../radius_sync_lib.php';
require_once __DIR__ . '/../staticip_helper.php';
staticipEnsureSchema($conn);

$checkConnModeCol = @mysqli_query($conn, "SHOW COLUMNS FROM server LIKE 'CONNECTION_MODE'");
if ($checkConnModeCol && mysqli_num_rows($checkConnModeCol) === 0) {
    @mysqli_query($conn, "ALTER TABLE server ADD COLUMN CONNECTION_MODE ENUM('API','RADIUS_ONLY') NOT NULL DEFAULT 'API'");
}

// Mode JSON (dipakai submit fetch() dari tablesstaticip.php, sama pola dgn
// proses/editcustomer.php -- lihat catatan sama di addcustomerstaticip.php).
function wantsJsonResponse() {
    return (($_POST['response_mode'] ?? '') === 'json');
}

function appendProvisionLog($message) {
    if (!isset($GLOBALS['provision_logs']) || !is_array($GLOBALS['provision_logs'])) {
        $GLOBALS['provision_logs'] = [];
    }
    $GLOBALS['provision_logs'][] = '[' . date('H:i:s') . '] ' . $message;
}

function redirectEditCustomerStatic($status, $text = '') {
    $url = "../tablesstaticip.php?edit=" . urlencode($status);
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

        $success = ($status === '1');
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectEditCustomerStatic('0', 'Metode tidak valid');
}

$customerID     = trim((string) ($_POST['customerID'] ?? ''));
$customerID_old = trim((string) ($_POST['customerID_old'] ?? ''));
$passwordPPPOE  = (string) ($_POST['passwordPPPOE'] ?? '');
$customerName   = $_POST['customerName'] ?? '';
$address        = $_POST['address'] ?? '';
$whatsapp       = $_POST['whatsapp'] ?? '';
$email          = $_POST['Email'] ?? '';
$coordinates    = $_POST['coordinates'] ?? '';
$server         = $_POST['server'] ?? '';
$serverlama     = $_POST['serverlama'] ?? '';
$area           = $_POST['area'] ?? '';
$arealama       = $_POST['arealama'] ?? '';
$sales          = $_POST['sales'] ?? '';
$odp            = $_POST['odp'] ?? '';
$tipe_bayar     = $_POST['tipe_bayar'] ?? '';
$tipe_tempo     = $_POST['tipe_tempo'] ?? '';
$packages       = $_POST['packages'] ?? '';
$authmode       = $_POST['authmode'] ?? 'RADIUS MODE';
$ipStatic       = trim((string) ($_POST['ip_static'] ?? ''));
$NIK            = mysqli_real_escape_string($conn, trim((string) ($_POST['NIK'] ?? '')));
$provinsi       = mysqli_real_escape_string($conn, $_POST['provinsi'] ?? '');
$kabupaten      = mysqli_real_escape_string($conn, $_POST['kabupaten'] ?? '');
$kecamatan      = mysqli_real_escape_string($conn, $_POST['kecamatan'] ?? '');
$kelurahan      = mysqli_real_escape_string($conn, $_POST['kelurahan'] ?? '');
$rw             = mysqli_real_escape_string($conn, $_POST['rw'] ?? '');
$rt             = mysqli_real_escape_string($conn, $_POST['rt'] ?? '');

if ($customerID_old === '') {
    redirectEditCustomerStatic('0', 'Customer ID lama tidak ditemukan');
}
if ($customerID === '' || preg_match('/[\x00-\x20"\\\\\x7F]/', $customerID) || $customerID[0] === '#' || strlen($customerID) > 64) {
    redirectEditCustomerStatic('0', 'Customer ID / username PPPoE tidak valid');
}
if ($passwordPPPOE !== '' && preg_match('/[\s"\\\\]/', $passwordPPPOE)) {
    redirectEditCustomerStatic('0', 'Password PPPoE tidak boleh mengandung spasi, tanda kutip (") atau backslash (\\)');
}

$ceknamaEsc = mysqli_real_escape_string($conn, $ceknama);
$customerID_old_sql = mysqli_real_escape_string($conn, $customerID_old);

// Baris lama WAJIB milik tenant yang login & memang Static IP -- pagar supaya
// endpoint ini tidak bisa dipakai memutar-balik pelanggan PPPoE biasa.
$oldDataRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT PEMILIK, AREA, ODP, TIPE_BAYAR, TIPE_TEMPO, PASSWORD, HARGA, TANGGALPASANG, TANGGAL_MONTHVERSARY, IP_STATIC FROM `pelanggan` WHERE `IDPEL` = '$customerID_old_sql' AND `PEMILIK` = '$ceknamaEsc' AND `TIPE_LAYANAN` = 'PPPOE_STATIC' LIMIT 1"));
if (!$oldDataRow) {
    redirectEditCustomerStatic('0', 'Pelanggan Static IP tidak ditemukan');
}

$customerID_sql = mysqli_real_escape_string($conn, $customerID);
$passwordPPPOE_sql = mysqli_real_escape_string($conn, ($passwordPPPOE !== '') ? $passwordPPPOE : $oldDataRow['PASSWORD']);
$passwordForProvision = ($passwordPPPOE !== '') ? $passwordPPPOE : $oldDataRow['PASSWORD'];

$serverEsc = mysqli_real_escape_string($conn, $server);
$areaEsc = mysqli_real_escape_string($conn, $area);
$serverlamaEsc = mysqli_real_escape_string($conn, $serverlama);
$arealamaEsc = mysqli_real_escape_string($conn, $arealama);

// Validasi IP Static: format valid, masuk range pool Area baru, dan tidak
// dipakai pelanggan LAIN (boleh sama dengan IP milik dirinya sendiri).
if ($ipStatic === '' || staticipIpToLong($ipStatic) === false) {
    redirectEditCustomerStatic('0', 'IP Static wajib diisi dengan format IPv4 yang valid');
}
$ipLong = staticipIpToLong($ipStatic);
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
    redirectEditCustomerStatic('0', "IP $ipStatic bukan bagian dari IP Pool Static Area $area");
}
$ipDupCheck = mysqli_query($conn, "SELECT IDPEL FROM pelanggan WHERE IP_STATIC = '" . mysqli_real_escape_string($conn, $ipStatic) . "' AND PEMILIK = '$serverEsc' AND AREA = '$areaEsc' AND IDPEL != '$customerID_old_sql' LIMIT 1");
if ($ipDupCheck && mysqli_num_rows($ipDupCheck) > 0) {
    $dupRow = mysqli_fetch_assoc($ipDupCheck);
    redirectEditCustomerStatic('0', "IP $ipStatic sudah dipakai pelanggan {$dupRow['IDPEL']}");
}

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

// Deteksi perlu sentuh Mikrotik/RADIUS -- pola sama editcustomer.php, DITAMBAH
// perubahan IP Static (paket/IP berubah = wajib re-provisioning, beda dengan
// PPPoE biasa yang cuma ganti paket tidak perlu sentuh Mikrotik karena
// bandwidth ikut PPP Profile; di sini remote-address/Framed-IP-Address
// melekat ke SECRET/entry RADIUS pelanggan, bukan ke profile).
$serverChanged    = (string) $oldDataRow['PEMILIK'] !== (string) $server;
$odpChanged       = (string) $oldDataRow['ODP'] !== (string) $odp;
$tipeBayarChanged = (string) $oldDataRow['TIPE_BAYAR'] !== (string) $tipe_bayar;
$tipeTempoChanged = (string) $oldDataRow['TIPE_TEMPO'] !== (string) $tipe_tempo;
$usernameChanged  = trim((string) $customerID_old) !== trim((string) $customerID);
$passwordChanged  = ($passwordPPPOE !== '') && ((string) $oldDataRow['PASSWORD'] !== (string) $passwordPPPOE);
$ipChanged        = (string) ($oldDataRow['IP_STATIC'] ?? '') !== (string) $ipStatic;

$needMikrotikUpdate = $serverChanged || $odpChanged || $tipeBayarChanged || $tipeTempoChanged || $usernameChanged || $passwordChanged || $ipChanged;

$tempo = '';
$tempoQuery = mysqli_query($conn, "SELECT TEMPO, CONNECTION_MODE FROM `server` WHERE `AREA` = '$areaEsc' AND `PEMILIK` = '$serverEsc' LIMIT 1");
if ($tempoQuery && ($tempoRow = mysqli_fetch_assoc($tempoQuery))) {
    $tempo = $tempoRow['TEMPO'];
    if (($tempoRow['CONNECTION_MODE'] ?? 'API') === 'RADIUS_ONLY') {
        $authmode = 'RADIUS MODE';
    }
}

$paket_q = mysqli_query($conn, "SELECT * FROM paket WHERE PAKET='" . mysqli_real_escape_string($conn, $packages) . "' AND PEMILIK='$serverEsc' AND AREA='$areaEsc' AND TIPE_LAYANAN='PPPOE_STATIC' ORDER BY id DESC LIMIT 1");
$paketRow = ($paket_q && mysqli_num_rows($paket_q) > 0) ? mysqli_fetch_assoc($paket_q) : null;
if (!$paketRow) {
    redirectEditCustomerStatic('0', "Paket Static IP '$packages' tidak ditemukan di Server Area ini");
}

if ($needMikrotikUpdate && ($authmode === 'API MODE' || $authmode === 'MULTI MODE')) {
    // Hapus secret lama.
    $srvLamaRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM `server` WHERE `AREA` = '$arealamaEsc' AND `PEMILIK` = '$serverlamaEsc' LIMIT 1"));
    if ($srvLamaRow) {
        $APIOld = new RouterosAPI();
        if ($APIOld->connect($srvLamaRow['IP'], $srvLamaRow['PEMILIK'], $srvLamaRow['PASSWORD'])) {
            $cariurutan2 = $APIOld->comm("/ppp/secret/getall", array(".proplist" => ".id", "?name" => $customerID_old));
            if (!empty($cariurutan2) && isset($cariurutan2[0]['.id'])) {
                $APIOld->comm("/ppp/secret/remove", array(".id" => $cariurutan2[0]['.id']));
            }
            $APIOld->disconnect();
        }
    }

    // Tambah secret baru dengan remote-address IP Static terbaru.
    $srvBaruRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM `server` WHERE `AREA` = '$areaEsc' AND `PEMILIK` = '$serverEsc' LIMIT 1"));
    if ($srvBaruRow) {
        $APINew = new RouterosAPI();
        if ($APINew->connect($srvBaruRow['IP'], $srvBaruRow['PEMILIK'], $srvBaruRow['PASSWORD'])) {
            $APINew->comm("/ppp/secret/add", array(
                "name"           => $customerID,
                "password"       => $passwordForProvision,
                "profile"        => $packages,
                "service"        => "any",
                "remote-address" => $ipStatic,
                "comment"        => "EDITED STATIC IP " . $customerName . "-" . $whatsappedit,
            ));
            $APINew->disconnect();
        }
    }
}

if ($needMikrotikUpdate && ($authmode === 'RADIUS MODE' || $authmode === 'MULTI MODE')) {
    if ($customerID !== $customerID_old) {
        radiusRemoveUsers([$customerID_old]);
    }
    radiusSyncSingleCustomerNow($customerID, $passwordForProvision, $paketRow, true, radiusGetGlobalSettings($conn), $ipStatic);
}

// Harga ikut paket terbaru (reseller_effective_harga, konsisten dgn editcustomer.php).
$harga = $oldDataRow['HARGA'] ?? '';
$hargaExistsQuery = mysqli_query($conn, "SELECT id FROM `paket` WHERE `PAKET` = '" . mysqli_real_escape_string($conn, $packages) . "' AND `PEMILIK` = '$serverEsc' LIMIT 1");
if ($hargaExistsQuery && mysqli_num_rows($hargaExistsQuery) > 0) {
    $harga = reseller_effective_harga($conn, $packages, $server, 'broadband');
}

$tanggal_monthversary_set_sql = '';
if ($tipe_tempo === 'monthversary' && empty($oldDataRow['TANGGAL_MONTHVERSARY'] ?? null)) {
    $anchorBaru = null;
    if ($tipe_bayar === 'prabayar') {
        $lastTrxQuery = mysqli_query($conn, "SELECT MAX(TANGGALBAYAR) as waktu_terakhir FROM `transaksi` WHERE `IDPEL` = '$customerID_old_sql' AND `STATUS` = 'BERHASIL'");
        if ($lastTrxQuery && ($lastTrxRow = mysqli_fetch_assoc($lastTrxQuery)) && !empty($lastTrxRow['waktu_terakhir'])) {
            $anchorBaru = substr((string) $lastTrxRow['waktu_terakhir'], 0, 10);
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
        `NAMA` = '" . mysqli_real_escape_string($conn, $customerName) . "',
        `TIPE_BAYAR` = '" . mysqli_real_escape_string($conn, $tipe_bayar) . "',
        `TIPE_TEMPO` = '" . mysqli_real_escape_string($conn, $tipe_tempo) . "',
        `PAKET` = '" . mysqli_real_escape_string($conn, $packages) . "',
        `HARGA` = '" . mysqli_real_escape_string($conn, (string) $harga) . "',
        `NOWA` = '$whatsappedit',
        `EMAIL` = '" . mysqli_real_escape_string($conn, $email) . "',
        `ALAMAT` = '" . mysqli_real_escape_string($conn, $address) . "',
        `TEMPO` = '" . mysqli_real_escape_string($conn, $tempo) . "',
        `PEMILIK` = '$serverEsc',
        `MODE` = '" . mysqli_real_escape_string($conn, $authmode) . "',
        `ODP` = '" . mysqli_real_escape_string($conn, $odp) . "',
        `AREA` = '$areaEsc',
        `provinsi` = '$provinsi',
        `kabupaten` = '$kabupaten',
        `kecamatan` = '$kecamatan',
        `kelurahan` = '$kelurahan',
        `rw` = '$rw',
        `rt` = '$rt',
        `TIKOR` = '" . mysqli_real_escape_string($conn, $coordinates) . "',
        `sales` = '" . mysqli_real_escape_string($conn, $sales) . "',
        `IP_STATIC` = '" . mysqli_real_escape_string($conn, $ipStatic) . "'$tanggal_monthversary_set_sql
    WHERE `IDPEL` = '$customerID_old_sql'";

if ($conn->query($sql2) === TRUE) {
    $history_file = "../notifbot/data/history-$ceknama.json";
    $history = [];
    if (file_exists($history_file)) {
        $history = json_decode(file_get_contents($history_file), true);
    }
    if (!is_array($history)) { $history = []; }
    $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Berhasil edit pelanggan Static IP $customerID (IP $ipStatic)";
    @file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    appendProvisionLog('Data pelanggan berhasil diperbarui.');

    redirectEditCustomerStatic('1');
} else {
    redirectEditCustomerStatic('0', 'Gagal update database: ' . $conn->error);
}
