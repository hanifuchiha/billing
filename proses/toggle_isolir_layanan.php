<?php
require '../cek-sesi.php';
require '../routeros_api.class.php';
require_once __DIR__ . '/../radius_sync_lib.php';
require_once __DIR__ . '/../corporate_helper.php';
corporateEnsureSchema($conn);

function redirectToggleIsolir($corporateId, $status, $text = '') {
    $url = "../corporate_layanan.php?corporate_id=" . (int) $corporateId . "&isolir=" . urlencode($status);
    if ($text !== '') {
        $url .= "&text=" . urlencode($text);
    }
    header("Location: " . $url);
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
$isolir = (($_POST['isolir'] ?? '') === '1');
if ($id <= 0) {
    redirectToggleIsolir(0, '0', 'ID tidak valid');
}

$ceknamaEsc = mysqli_real_escape_string($conn, $ceknama);
$areaFilterCorp = corporateAreaFilterSql('c.AREA', $AKSES, $area_list ?? '');
$row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT cl.*, p.PAKET, p.KECEPATAN, s.IP AS server_ip, s.PASSWORD AS server_password, s.PEMILIK AS server_pemilik
    FROM corporate_layanan cl
    LEFT JOIN paket_corporate p ON p.id = cl.paket_id
    LEFT JOIN server s ON s.id = cl.server_id
    JOIN corporate c ON c.id = cl.corporate_id
    WHERE cl.id = $id AND c.PEMILIK = '$ceknamaEsc'" . $areaFilterCorp . " LIMIT 1"));
if (!$row) {
    redirectToggleIsolir(0, '0', 'Layanan tidak ditemukan');
}
$corporateId = (int) $row['corporate_id'];

if ((int) $row['provisioning_aktif'] !== 1) {
    redirectToggleIsolir($corporateId, '0', 'Layanan ini tidak punya provisioning aktif');
}

$serverRow = !empty($row['server_ip']) ? [
    'IP' => $row['server_ip'],
    'PASSWORD' => $row['server_password'],
    'PEMILIK' => $row['server_pemilik'],
] : [];
$paketRow = ['PAKET' => $row['PAKET'] ?? '', 'KECEPATAN' => $row['KECEPATAN'] ?? ''];

$result = corporateLayananSetIsolir($conn, $serverRow, $paketRow, (string) $row['auth_mode'], (string) $row['pppoe_username'], (string) $row['pppoe_password'], (string) $row['ip_address'], $isolir);
if (!$result['success']) {
    redirectToggleIsolir($corporateId, '0', $result['message']);
}

$newStatusKoneksi = $isolir ? 'ISOLIR' : 'AKTIF';
mysqli_query($conn, "UPDATE corporate_layanan SET status_koneksi = '" . mysqli_real_escape_string($conn, $newStatusKoneksi) . "' WHERE id = $id");

$history_file = "../notifbot/data/history-$ceknama.json";
$history = [];
if (file_exists($history_file)) {
    $history = json_decode(file_get_contents($history_file), true);
}
if (!is_array($history)) { $history = []; }
$history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] " . ($isolir ? 'Isolir' : 'Aktifkan') . " Layanan id=$id corporate_id=$corporateId";
@file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

redirectToggleIsolir($corporateId, '1');
