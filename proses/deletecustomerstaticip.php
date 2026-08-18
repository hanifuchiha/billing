<?php
require '../cek-sesi.php';
require '../routeros_api.class.php';
require_once __DIR__ . '/../radius_sync_lib.php';
require_once __DIR__ . '/../staticip_helper.php';
staticipEnsureSchema($conn);

function redirectDeleteCustomerStatic($status, $text = '') {
    $url = "../tablesstaticip.php?deleted=" . urlencode($status);
    if ($text !== '') {
        $url .= "&text=" . urlencode($text);
    }
    header("Location: " . $url);
    exit;
}

$idpel = trim((string) ($_POST['idpel'] ?? $_GET['idpel'] ?? ''));
if ($idpel === '') {
    redirectDeleteCustomerStatic('0', 'IDPEL tidak valid');
}

// Scoping: hanya boleh hapus pelanggan Static IP milik tenant sendiri.
$ceknamaEsc = mysqli_real_escape_string($conn, $ceknama);
$idpelEsc = mysqli_real_escape_string($conn, $idpel);
$row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM pelanggan WHERE IDPEL = '$idpelEsc' AND PEMILIK = '$ceknamaEsc' AND TIPE_LAYANAN = 'PPPOE_STATIC' LIMIT 1"));
if (!$row) {
    redirectDeleteCustomerStatic('0', 'Pelanggan Static IP tidak ditemukan');
}

// Arsipkan ke pelanggan_berhenti (pola sama seperti deletecustomer.php) sebelum
// dihapus, supaya tetap tercatat di menu Pelanggan Berhenti.
$insert_sql = "INSERT INTO pelanggan_berhenti (`idpel`,`nama`,`tempo`,`harga`,`pemilik`,`alamat`,`nowa`,`paket`,`alasan`,`tanggal_berhenti`,`keterangan`) VALUES (?,?,?,?,?,?,?,?,?,?,?)";
$stmt = mysqli_prepare($conn, $insert_sql);
if ($stmt) {
    $alasan = 'Dismantle';
    $tanggal = date('Y-m-d');
    $keterangan = 'Dismantle (Static IP)';
    mysqli_stmt_bind_param(
        $stmt,
        'sssssssssss',
        $row['IDPEL'],
        $row['NAMA'],
        $row['TEMPO'],
        $row['HARGA'],
        $row['PEMILIK'],
        $row['ALAMAT'],
        $row['NOWA'],
        $row['PAKET'],
        $alasan,
        $tanggal,
        $keterangan
    );
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

$server = $row['PEMILIK'];
$area = $row['AREA'];
$authmode = $row['MODE'];

// --- Provisioning removal: PERSIS pola deletecustomer.php (API MODE), tapi
// pakai radius_sync_lib.php (radiusRemoveUsers) untuk RADIUS -- bukan
// manipulasi regex file users langsung seperti deletecustomer.php lama. ---
if ($authmode === 'API MODE' || $authmode === 'MULTI MODE') {
    $srvRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM `server` WHERE `AREA` = '" . mysqli_real_escape_string($conn, $area) . "' AND `PEMILIK` = '" . mysqli_real_escape_string($conn, $server) . "' LIMIT 1"));
    if ($srvRow) {
        $API = new RouterosAPI();
        if ($API->connect($srvRow['IP'], $srvRow['PEMILIK'], $srvRow['PASSWORD'])) {
            $cariSecret = $API->comm("/ppp/secret/getall", array(".proplist" => ".id", "?name" => $idpel));
            if (!empty($cariSecret) && isset($cariSecret[0]['.id'])) {
                $API->comm("/ppp/secret/remove", array(".id" => $cariSecret[0]['.id']));
            }
            $cariAktif = $API->comm("/ppp/active/getall", array(".proplist" => ".id", "?name" => $idpel));
            if (!empty($cariAktif) && isset($cariAktif[0]['.id'])) {
                $API->comm("/ppp/active/remove", array(".id" => $cariAktif[0]['.id']));
            }
            $API->disconnect();
        }
    }
}

if ($authmode === 'RADIUS MODE' || $authmode === 'MULTI MODE') {
    $removeResult = radiusRemoveUsers([$idpel]);
    radiusReloadIfChanged(!empty($removeResult['changed']));
}

$del = mysqli_query($conn, "DELETE FROM pelanggan WHERE IDPEL = '$idpelEsc' AND PEMILIK = '$ceknamaEsc' AND TIPE_LAYANAN = 'PPPOE_STATIC'");

$history_file = "../notifbot/data/history-$ceknama.json";
$history = [];
if (file_exists($history_file)) {
    $history = json_decode(file_get_contents($history_file), true);
}
if (!is_array($history)) { $history = []; }

if ($del && mysqli_affected_rows($conn) > 0) {
    $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Berhasil menghapus pelanggan Static IP $idpel (Nama: {$row['NAMA']}, Area: $area, Server: $server)";
    @file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    redirectDeleteCustomerStatic('1');
} else {
    $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Gagal menghapus pelanggan Static IP $idpel: " . mysqli_error($conn);
    @file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    redirectDeleteCustomerStatic('0', 'Gagal menghapus dari database');
}
