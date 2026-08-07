<?php
require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    twk_response(405, ['success' => false, 'message' => 'Method tidak diizinkan.']);
}

$conn = twk_db_connect();
$session = twk_require_auth($conn);
$idpel = (string)$session['idpel'];

$pelanggan = twk_find_customer($conn, $idpel);
if (!$pelanggan) {
    twk_response(404, ['success' => false, 'message' => 'Data pelanggan tidak ditemukan.']);
}

$body = twk_get_json_body();
$keluhan = trim((string)($body['keluhan'] ?? ($_POST['keluhan'] ?? '')));
if ($keluhan === '') {
    twk_response(422, ['success' => false, 'message' => 'Detail keluhan wajib diisi.']);
}
if (strlen($keluhan) > 4000) {
    twk_response(422, ['success' => false, 'message' => 'Detail keluhan terlalu panjang.']);
}

$nama = trim((string)($pelanggan['NAMA'] ?? ''));
$alamat = trim((string)($pelanggan['ALAMAT'] ?? ''));
$nowa = twk_normalize_phone((string)($pelanggan['NOWA'] ?? ''));
$tikor = trim((string)($pelanggan['TIKOR'] ?? ''));
$provider = trim((string)($pelanggan['BRAND'] ?? ($pelanggan['PEMILIK'] ?? 'QTS')));

$tgl = date('Y-m-d');
$status = 'BARU';
$tipe = 'MAINTENANCE';
$dataText = "[Pengaduan dari MOBILE APPS ] MAINTENANCE\n\nNAMA:$nama\nIDPEL:$idpel\nAlamat:$alamat\nKeluhan:$keluhan\nWHATSAPP:$nowa\nTIKOR:$tikor";

$inserted = false;
$insertId = 0;
$usedTable = 'joblist';

$absensiConn = twk_db_connect_absensi();

$checkJoblist = mysqli_query($absensiConn, "SHOW TABLES LIKE 'joblist'");
$hasJoblist = $checkJoblist && mysqli_num_rows($checkJoblist) > 0;

if ($hasJoblist) {
    $stmt = mysqli_prepare($absensiConn, "INSERT INTO joblist (tgl, status, tipe, nowa, data, project, report, team) VALUES (?, ?, ?, ?, ?, ?, '', '')");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'ssssss', $tgl, $status, $tipe, $nowa, $dataText, $provider);
        $inserted = mysqli_stmt_execute($stmt);
        $insertId = (int)mysqli_insert_id($absensiConn);
        mysqli_stmt_close($stmt);
    }

    if (!$inserted) {
        twk_response(500, ['success' => false, 'message' => 'Gagal menyimpan tiket ke DB absensi tabel joblist.']);
    }
}

if (!$hasJoblist) {
    twk_response(500, ['success' => false, 'message' => 'Tabel joblist tidak ditemukan di DB absensi.']);
}

if (!$inserted) {
    twk_response(500, ['success' => false, 'message' => 'Gagal menyimpan pengaduan ke DB absensi tabel joblist.']);
}

// Notifikasi WhatsApp grup tiket (best effort)
$waFile = dirname(__DIR__, 2) . '/waapi.txt';
$waapi = '';
$namebot = '';
$password = '';
$grup = '';
$grupTiket = '';
$sender = '';
if (file_exists($waFile)) {
    $lines = file($waFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, 'waapi=') === 0) $waapi = trim(substr($line, 6));
        elseif (strpos($line, 'namebot=') === 0) $namebot = trim(substr($line, 8));
        elseif (strpos($line, 'password=') === 0) $password = trim(substr($line, 9));
        elseif (strpos($line, 'grup=') === 0) $grup = trim(substr($line, 5));
        elseif (strpos($line, 'grup_tiket=') === 0) $grupTiket = trim(substr($line, 11));
        elseif (strpos($line, 'sender=') === 0) $sender = trim(substr($line, 7));
    }
}

$notifTarget = $grupTiket !== '' ? $grupTiket : $grup;
$waStatus = 'skip';
if ($waapi !== '' && $namebot !== '' && $password !== '' && $notifTarget !== '') {
    $text = "*[NOTIF SYSTEM BOT QTS]*\n===================\nTIKET BARU MASUK DI APP\n===================\n\nPROJECT : $provider \nTIPE : $tipe\nDATA :\n$dataText\n\n===================\n";
    $payload = [
        'phone' => $notifTarget,
        'message' => $text,
        'sender' => $sender
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, rtrim($waapi, '/') . '/send/message?session=QTS');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_USERPWD, $namebot . ':' . $password);

    curl_exec($ch);
    $err = curl_errno($ch);
    curl_close($ch);
    $waStatus = $err === 0 ? 'sent' : 'failed';
}

twk_response(200, [
    'success' => true,
    'message' => 'Pengaduan berhasil dikirim. Silakan tunggu teknisi datang ke lokasi.',
    'data' => [
        'id' => $insertId,
        'idpel' => $idpel,
        'tipe' => $tipe,
        'status' => $status,
        'keluhan' => $keluhan,
        'table' => $usedTable,
        'database' => 'absensi',
        'wa_status' => $waStatus,
        'created_at' => date('Y-m-d H:i:s')
    ]
]);
