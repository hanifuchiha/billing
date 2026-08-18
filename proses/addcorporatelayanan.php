<?php
require '../cek-sesi.php';
require '../routeros_api.class.php';
require_once __DIR__ . '/../radius_sync_lib.php';
require_once __DIR__ . '/../corporate_helper.php';
corporateEnsureSchema($conn);

function redirectAddLayanan($corporateId, $status, $text = '') {
    $url = "../corporate_layanan.php?corporate_id=" . (int) $corporateId . "&statusnotif=" . urlencode($status);
    if ($text !== '') {
        $url .= "&text=" . urlencode($text);
    }
    header("Location: " . $url);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectAddLayanan(0, 'failed', 'Metode tidak valid');
}

$corporateId = (int) ($_POST['corporate_id'] ?? 0);
if ($corporateId <= 0) {
    redirectAddLayanan(0, 'failed', 'Perusahaan tidak valid');
}

$ceknamaEsc = mysqli_real_escape_string($conn, $ceknama);
$areaFilterCorp = corporateAreaFilterSql('AREA', $AKSES, $area_list ?? '');
$corp = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM corporate WHERE id = $corporateId AND PEMILIK = '$ceknamaEsc'" . $areaFilterCorp . " LIMIT 1"));
if (!$corp) {
    redirectAddLayanan($corporateId, 'failed', 'Customer Corporate tidak ditemukan');
}

$jenisLayanan = trim((string) ($_POST['jenis_layanan'] ?? 'Internet Dedicated'));
$namaLayanan = trim((string) ($_POST['nama_layanan'] ?? ''));
$serverPemilik = trim((string) ($_POST['server'] ?? ''));
$area = trim((string) ($_POST['area'] ?? ''));
$paketId = (int) ($_POST['paket_id'] ?? 0);
$ipAddress = trim((string) ($_POST['ip_address'] ?? ''));
$vlanId = (int) ($_POST['vlan_id'] ?? 0);
$oltId = (int) ($_POST['olt_id'] ?? 0);
$tanggalAktif = trim((string) ($_POST['tanggal_aktif'] ?? ''));
$catatan = trim((string) ($_POST['catatan'] ?? ''));
$provisioningAktif = (($_POST['provisioning_aktif'] ?? '') === '1') ? 1 : 0;
$authMode = $_POST['auth_mode'] ?? 'API MODE';
$pppoeUsername = trim((string) ($_POST['pppoe_username'] ?? ''));
$pppoePassword = (string) ($_POST['pppoe_password'] ?? '');

if ($jenisLayanan === '') {
    redirectAddLayanan($corporateId, 'failed', 'Jenis Layanan wajib diisi');
}
if (!in_array($authMode, ['API MODE', 'RADIUS MODE', 'MULTI MODE'], true)) {
    $authMode = 'API MODE';
}

// Server (opsional, tapi kalau diisi wajib benar milik tenant ini).
$serverRow = null;
$serverId = null;
if ($serverPemilik !== '' && $area !== '') {
    $serverPemilikEsc = mysqli_real_escape_string($conn, $serverPemilik);
    $areaEsc = mysqli_real_escape_string($conn, $area);
    $serverRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM server WHERE PEMILIK = '$serverPemilikEsc' AND AREA = '$areaEsc' LIMIT 1"));
    if (!$serverRow) {
        redirectAddLayanan($corporateId, 'failed', 'Server/Router tidak ditemukan');
    }
    $serverId = (int) $serverRow['id'];
    if (($serverRow['CONNECTION_MODE'] ?? 'API') === 'RADIUS_ONLY') {
        $authMode = 'RADIUS MODE';
    }
}

// Paket (opsional, WAJIB kalau provisioning aktif).
$paketRow = null;
if ($paketId > 0) {
    $paketRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM paket_corporate WHERE id = $paketId LIMIT 1"));
    if (!$paketRow) {
        redirectAddLayanan($corporateId, 'failed', 'Paket tidak ditemukan');
    }
}

if ($provisioningAktif === 1) {
    if (!$serverRow) {
        redirectAddLayanan($corporateId, 'failed', 'Server/Router wajib dipilih kalau Provisioning Otomatis diaktifkan');
    }
    if (!$paketRow) {
        redirectAddLayanan($corporateId, 'failed', 'Paket wajib dipilih kalau Provisioning Otomatis diaktifkan');
    }
    $errUsername = corporateLayananValidateUsername($pppoeUsername);
    if ($errUsername !== '') {
        redirectAddLayanan($corporateId, 'failed', $errUsername);
    }
    $errPassword = corporateLayananValidatePassword($pppoePassword);
    if ($errPassword !== '') {
        redirectAddLayanan($corporateId, 'failed', $errPassword);
    }
    if (corporateLayananUsernameTaken($conn, $pppoeUsername)) {
        redirectAddLayanan($corporateId, 'failed', "Username PPPoE \"$pppoeUsername\" sudah dipakai");
    }

    $provisionResult = corporateLayananProvision($conn, $serverRow, $paketRow, $authMode, $pppoeUsername, $pppoePassword, $ipAddress);
    if (!$provisionResult['success']) {
        redirectAddLayanan($corporateId, 'failed', 'Provisioning gagal: ' . $provisionResult['message']);
    }
} else {
    $pppoeUsername = '';
    $pppoePassword = '';
}

// VLAN/OLT (opsional) -- validasi sederhana: harus ada barisnya (tidak divalidasi
// ulang scoping ketat di sini krn dropdown sudah discoped tenant saat render).
if ($vlanId > 0 && !mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM vlan WHERE id = $vlanId LIMIT 1"))) {
    $vlanId = 0;
}
if ($oltId > 0 && !mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM olt WHERE id = $oltId LIMIT 1"))) {
    $oltId = 0;
}

$tanggalAktifSql = ($tanggalAktif !== '' && strtotime($tanggalAktif) !== false) ? "'" . mysqli_real_escape_string($conn, $tanggalAktif) . "'" : 'NULL';
$serverIdSql = $serverId !== null ? $serverId : 'NULL';
$paketIdSql = $paketRow ? (int) $paketRow['id'] : 'NULL';
$vlanIdSql = $vlanId > 0 ? $vlanId : 'NULL';
$oltIdSql = $oltId > 0 ? $oltId : 'NULL';

$sql = "INSERT INTO corporate_layanan (corporate_id, PEMILIK, jenis_layanan, nama_layanan, server_id, paket_id, ip_address, vlan_id, olt_id, provisioning_aktif, auth_mode, pppoe_username, pppoe_password, status_koneksi, status, tanggal_aktif, catatan) VALUES (
    $corporateId,
    '$ceknamaEsc',
    '" . mysqli_real_escape_string($conn, $jenisLayanan) . "',
    '" . mysqli_real_escape_string($conn, $namaLayanan) . "',
    $serverIdSql,
    $paketIdSql,
    '" . mysqli_real_escape_string($conn, $ipAddress) . "',
    $vlanIdSql,
    $oltIdSql,
    $provisioningAktif,
    '" . mysqli_real_escape_string($conn, $authMode) . "',
    '" . mysqli_real_escape_string($conn, $pppoeUsername) . "',
    '" . mysqli_real_escape_string($conn, $pppoePassword) . "',
    'AKTIF',
    'AKTIF',
    $tanggalAktifSql,
    '" . mysqli_real_escape_string($conn, $catatan) . "'
)";

if (!mysqli_query($conn, $sql)) {
    // Provisioning sudah kadung jalan (kalau aktif) -- lepas lagi supaya
    // tidak jadi entry yatim di router tanpa catatan di database.
    if ($provisioningAktif === 1 && $serverRow) {
        corporateLayananDeprovision($serverRow, $authMode, $pppoeUsername);
    }
    redirectAddLayanan($corporateId, 'failed', 'Gagal menyimpan ke database: ' . mysqli_error($conn));
}

$history_file = "../notifbot/data/history-$ceknama.json";
$history = [];
if (file_exists($history_file)) {
    $history = json_decode(file_get_contents($history_file), true);
}
if (!is_array($history)) { $history = []; }
$history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Berhasil menambahkan Layanan '$jenisLayanan' utk corporate_id=$corporateId" . ($provisioningAktif ? " (provisioning aktif, username $pppoeUsername)" : '');
@file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

redirectAddLayanan($corporateId, 'success');
