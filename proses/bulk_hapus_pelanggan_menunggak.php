<?php
// bulk_hapus_pelanggan_menunggak.php - Hapus BANYAK pelanggan sekaligus (dari
// checklist di pelanggan_menunggak.php) jadi Pelanggan Berhenti. Sengaja file
// TERPISAH dari proses/deletecustomer.php (aksi 1 pelanggan) -- deletecustomer.php
// restart FreeRADIUS PENUH tiap kali dipanggil; kalau dipakai bulk (loop N kali)
// itu akan restart FreeRADIUS N kali berturut-turut dan bisa ganggu SEMUA
// pelanggan RADIUS yang lagi konek. Di sini FreeRADIUS cuma diproses SEKALI di
// akhir lewat radiusRemoveUsers()+radiusReloadIfChanged() (helper yang sudah
// dipakai activecustomer.php/editcustomer.php dkk).
require '../cek-sesi.php';
require '../routeros_api.class.php';
require_once '../radius_sync_lib.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$idpelsRaw = (string)($_POST['idpels'] ?? '');
$idpelsList = array_values(array_unique(array_filter(array_map('trim', explode(',', $idpelsRaw)), function ($v) {
    return $v !== '';
})));

if (empty($idpelsList)) {
    echo json_encode(['success' => false, 'message' => 'Tidak ada pelanggan dipilih.']);
    exit;
}

if (count($idpelsList) > 200) {
    echo json_encode(['success' => false, 'message' => 'Maksimal 200 pelanggan per proses.']);
    exit;
}

// Scoping kepemilikan (cegah IDOR -- hapus pelanggan milik owner/area lain lewat
// tebak IDPEL) -- pola sama dengan dashboard.php/pelanggan_menunggak.php.
// $current_user_id utk ASSISTANT berisi id akun OWNER (lihat cek-sesi.php), jadi
// scoping ASSISTANT WAJIB lewat $area_list, BUKAN "PEMILIK milik $current_user_id"
// (itu akan balik SEMUA server milik owner, bocor lintas-area).
if ($AKSES === 'ASSISTANT') {
    $scopeWhere = (isset($area_list) && trim((string)$area_list) !== '')
        ? "AREA IN ($area_list)"
        : "1=0";
} else {
    $scopeWhere = "PEMILIK IN (SELECT PEMILIK FROM server WHERE user_id = " . (int)$current_user_id . ")";
}

$idpelsEscList = array_map(function ($v) use ($conn) {
    return "'" . mysqli_real_escape_string($conn, $v) . "'";
}, $idpelsList);
$idpelsInClause = implode(',', $idpelsEscList);

$rows = [];
$resPel = mysqli_query($conn, "SELECT * FROM pelanggan WHERE IDPEL IN ($idpelsInClause) AND $scopeWhere");
if ($resPel) {
    while ($r = mysqli_fetch_assoc($resPel)) {
        $rows[(string)$r['IDPEL']] = $r;
    }
}

if (empty($rows)) {
    echo json_encode(['success' => false, 'message' => 'Pelanggan tidak ditemukan atau di luar akses Anda.']);
    exit;
}

// --- Siapkan bot WA + template pesan Dismantle, SEKALI saja utk semua pelanggan ---
$botname = '';
$jsonFile = "../notifbot/data/reminder-$ceknama.json";
if (file_exists($jsonFile)) {
    $jsonData = json_decode((string)file_get_contents($jsonFile), true);
    if (is_array($jsonData)) {
        foreach ($jsonData as $item) {
            $botname = (string)($item['botname'] ?? '');
        }
    }
}

$waapi = '';
$passwordbot = '';
$sender = '';
if ($botname !== '') {
    $botEsc = mysqli_real_escape_string($conn, $botname);
    $qBot = mysqli_query($conn, "SELECT * FROM botwa WHERE namebot = '$botEsc' LIMIT 1");
    if ($qBot && ($dBot = mysqli_fetch_assoc($qBot))) {
        $waapi = (string)($dBot['addressbot'] ?? '');
        $passwordbot = (string)($dBot['password'] ?? '');
        $sender = (string)($dBot['sender'] ?? '');
    }
}
$deviceId = trim($sender);

$pesanDismantle = '';
$stmtTpl = $conn->prepare('SELECT pesan_dismantle_manual FROM notif_khusus WHERE pemilik = ? LIMIT 1');
if ($stmtTpl) {
    $stmtTpl->bind_param('s', $ceknama);
    $stmtTpl->execute();
    $stmtTpl->bind_result($pesanDismantle);
    $stmtTpl->fetch();
    $stmtTpl->close();
}
$pesanDismantle = (string)$pesanDismantle;

function bulkHapusReplaceVars(string $template, array $vars): string
{
    return preg_replace_callback('/\$([a-zA-Z0-9_]+)/', function ($m) use ($vars) {
        return isset($vars[$m[1]]) ? (string)$vars[$m[1]] : $m[0];
    }, $template);
}

// --- Kelompokkan per server (PEMILIK+AREA) supaya 1 koneksi MikroTik dipakai
// ulang utk semua pelanggan di server yang sama, bukan reconnect tiap pelanggan.
$byServer = [];
foreach ($rows as $idpel => $r) {
    $key = (string)($r['PEMILIK'] ?? '') . '|' . (string)($r['AREA'] ?? '');
    $byServer[$key][] = $idpel;
}

$history_file = "../notifbot/data/history-$ceknama.json";
$history = [];
if (file_exists($history_file)) {
    $history = json_decode((string)file_get_contents($history_file), true);
}
if (!is_array($history)) {
    $history = [];
}

$berhasil = [];
$gagal = [];
$freeradiusUsernamesToRemove = [];
$actorName = !empty($asistant_name) ? $asistant_name : $ceknama;

foreach ($byServer as $key => $idpelListForServer) {
    [$pemilikSrv, $areaSrv] = array_pad(explode('|', $key, 2), 2, '');

    $mikApi = null;
    $mikConnected = false;
    $pemilikEsc = mysqli_real_escape_string($conn, $pemilikSrv);
    $areaEsc = mysqli_real_escape_string($conn, $areaSrv);
    $srvRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT IP, PASSWORD FROM server WHERE PEMILIK='$pemilikEsc' AND AREA='$areaEsc' LIMIT 1"));
    if ($srvRow && !empty($srvRow['IP']) && !empty($srvRow['PASSWORD'])) {
        $mikApi = new RouterosAPI();
        $mikApi->timeout = 8;
        $mikConnected = $mikApi->connect($srvRow['IP'], $pemilikSrv, $srvRow['PASSWORD']);
    }

    foreach ($idpelListForServer as $idpel) {
        $row = $rows[$idpel];
        $nama = (string)($row['NAMA'] ?? '');
        $nowa = (string)($row['NOWA'] ?? '');
        $paket = (string)($row['PAKET'] ?? '');
        $tempoV = (string)($row['TEMPO'] ?? '');
        $hargaV = (string)($row['HARGA'] ?? '');
        $alamatV = (string)($row['ALAMAT'] ?? '');
        $alasanV = 'Dismantle (Bulk - Menunggak)';
        $tglV = date('Y-m-d');
        $ketV = 'Dismantle (Bulk - Menunggak)';

        // 1. Insert ke pelanggan_berhenti DULU (kalau gagal, jangan lanjut hapus).
        $insStmt = $conn->prepare("INSERT INTO pelanggan_berhenti (`idpel`,`nama`,`tempo`,`harga`,`pemilik`,`alamat`,`nowa`,`paket`,`alasan`,`tanggal_berhenti`,`keterangan`) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $insOk = false;
        if ($insStmt) {
            $insStmt->bind_param('sssssssssss', $idpel, $nama, $tempoV, $hargaV, $pemilikSrv, $alamatV, $nowa, $paket, $alasanV, $tglV, $ketV);
            $insOk = $insStmt->execute();
            $insStmt->close();
        }

        if (!$insOk) {
            $gagal[] = $idpel;
            continue;
        }

        // 2. Hapus dari pelanggan.
        $idNum = (int)($row['id'] ?? 0);
        if ($idNum > 0) {
            mysqli_query($conn, "DELETE FROM pelanggan WHERE id=$idNum");
        }

        // 3. Putus & hapus PPPoE secret di MikroTik (kalau server konek).
        if ($mikConnected && $mikApi) {
            try {
                $cariSecret = $mikApi->comm('/ppp/secret/getall', ['.proplist' => '.id', '?name' => $idpel]);
                if (!empty($cariSecret[0]['.id'])) {
                    $mikApi->comm('/ppp/secret/remove', ['.id' => $cariSecret[0]['.id']]);
                }
                $cariAktif = $mikApi->comm('/ppp/active/getall', ['.proplist' => '.id', '?name' => $idpel]);
                if (!empty($cariAktif[0]['.id'])) {
                    $mikApi->comm('/ppp/active/remove', ['.id' => $cariAktif[0]['.id']]);
                }
            } catch (Throwable $e) {
                // Tidak fatal -- data sudah pindah ke pelanggan_berhenti, lanjut saja.
            }
        }

        // 4. Kumpulkan utk hapus batch dari FreeRADIUS (di luar loop, sekali saja).
        $freeradiusUsernamesToRemove[] = $idpel;

        // 5. Hapus file timer prabayar kalau ada (punya activecustomer.php).
        $timerFile = "/etc/freeradius/user_timers/$idpel.json";
        if (file_exists($timerFile)) {
            @unlink($timerFile);
        }

        // 6. Kirim notif WA "Dismantle" (dijeda dikit tiap kirim -- jangan spam bot).
        if ($waapi !== '' && $nowa !== '' && $pesanDismantle !== '') {
            $vars = ['idpel' => $idpel, 'nama' => $nama, 'nowa' => $nowa, 'paket' => $paket, 'area' => $areaSrv, 'server' => $pemilikSrv];
            $message = bulkHapusReplaceVars($pesanDismantle, $vars);
            $phone = "$nowa@s.whatsapp.net";
            $url = rtrim($waapi, '/') . '/send/message?session=' . urlencode($botname);
            if ($deviceId !== '') {
                $url .= '&device_id=' . urlencode($deviceId);
            }
            $headers = ['Content-Type: application/json'];
            if ($deviceId !== '') {
                $headers[] = "X-Device-Id: $deviceId";
            }
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['phone' => $phone, 'message' => $message]));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_USERPWD, "$botname:$passwordbot");
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_exec($ch);
            curl_close($ch);
            usleep(400000);
        }

        $berhasil[] = $idpel;
        $history[] = "[ $actorName - " . date('Y-m-d H:i:s') . " ] Berhasil hapus (bulk dari Pelanggan Menunggak) pelanggan $idpel (Nama: $nama, Area: $areaSrv, Server: $pemilikSrv)";
    }

    if ($mikApi && $mikConnected) {
        $mikApi->disconnect();
    }
}

// --- Hapus dari FreeRADIUS SEKALI utk semua idpel, restart SEKALI (bukan per pelanggan). ---
$radiusResult = ['changed' => false, 'removed' => []];
if (!empty($freeradiusUsernamesToRemove)) {
    $radiusResult = radiusRemoveUsers($freeradiusUsernamesToRemove);
    radiusReloadIfChanged($radiusResult['changed']);
}

file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

echo json_encode([
    'success' => true,
    'berhasil' => $berhasil,
    'gagal' => $gagal,
    'total_berhasil' => count($berhasil),
    'total_gagal' => count($gagal),
    'freeradius_removed' => $radiusResult['removed'] ?? [],
]);
