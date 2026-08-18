<?php
require '../cek-sesi.php';
require '../routeros_api.class.php';
require_once __DIR__ . '/../radius_sync_lib.php';
require_once __DIR__ . '/../corporate_helper.php';
corporateEnsureSchema($conn);

function redirectEditLayanan($corporateId, $status, $text = '') {
    $url = "../corporate_layanan.php?corporate_id=" . (int) $corporateId . "&edit=" . urlencode($status);
    if ($text !== '') {
        $url .= "&text=" . urlencode($text);
    }
    header("Location: " . $url);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectEditLayanan(0, '0', 'Metode tidak valid');
}

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    redirectEditLayanan(0, '0', 'ID tidak valid');
}

// Ambil baris lama + validasi kepemilikan lewat JOIN ke corporate (+ area scope).
$ceknamaEsc = mysqli_real_escape_string($conn, $ceknama);
$areaFilterCorp = corporateAreaFilterSql('c.AREA', $AKSES, $area_list ?? '');
$old = mysqli_fetch_assoc(mysqli_query($conn, "SELECT cl.*, s.IP AS old_server_ip, s.PASSWORD AS old_server_password, s.PEMILIK AS old_server_pemilik
    FROM corporate_layanan cl
    LEFT JOIN server s ON s.id = cl.server_id
    JOIN corporate c ON c.id = cl.corporate_id
    WHERE cl.id = $id AND c.PEMILIK = '$ceknamaEsc'" . $areaFilterCorp . " LIMIT 1"));
if (!$old) {
    redirectEditLayanan(0, '0', 'Layanan tidak ditemukan');
}
$corporateId = (int) $old['corporate_id'];

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
$status = ($_POST['status'] ?? 'AKTIF') === 'NONAKTIF' ? 'NONAKTIF' : 'AKTIF';
$provisioningAktif = (($_POST['provisioning_aktif'] ?? '') === '1') ? 1 : 0;
$authMode = $_POST['auth_mode'] ?? 'API MODE';
$pppoeUsername = trim((string) ($_POST['pppoe_username'] ?? ''));
$pppoePasswordInput = (string) ($_POST['pppoe_password'] ?? '');
// Password kosong saat edit = tidak diganti, pakai yang lama.
$pppoePassword = ($pppoePasswordInput !== '') ? $pppoePasswordInput : (string) $old['pppoe_password'];

if ($jenisLayanan === '') {
    redirectEditLayanan($corporateId, '0', 'Jenis Layanan wajib diisi');
}
if (!in_array($authMode, ['API MODE', 'RADIUS MODE', 'MULTI MODE'], true)) {
    $authMode = 'API MODE';
}

$serverRow = null;
$serverId = null;
if ($serverPemilik !== '' && $area !== '') {
    $serverPemilikEsc = mysqli_real_escape_string($conn, $serverPemilik);
    $areaEsc = mysqli_real_escape_string($conn, $area);
    $serverRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM server WHERE PEMILIK = '$serverPemilikEsc' AND AREA = '$areaEsc' LIMIT 1"));
    if (!$serverRow) {
        redirectEditLayanan($corporateId, '0', 'Server/Router tidak ditemukan');
    }
    $serverId = (int) $serverRow['id'];
    if (($serverRow['CONNECTION_MODE'] ?? 'API') === 'RADIUS_ONLY') {
        $authMode = 'RADIUS MODE';
    }
}

$paketRow = null;
if ($paketId > 0) {
    $paketRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM paket_corporate WHERE id = $paketId LIMIT 1"));
    if (!$paketRow) {
        redirectEditLayanan($corporateId, '0', 'Paket tidak ditemukan');
    }
}

if ($provisioningAktif === 1) {
    if (!$serverRow) {
        redirectEditLayanan($corporateId, '0', 'Server/Router wajib dipilih kalau Provisioning Otomatis diaktifkan');
    }
    if (!$paketRow) {
        redirectEditLayanan($corporateId, '0', 'Paket wajib dipilih kalau Provisioning Otomatis diaktifkan');
    }
    $errUsername = corporateLayananValidateUsername($pppoeUsername);
    if ($errUsername !== '') {
        redirectEditLayanan($corporateId, '0', $errUsername);
    }
    $errPassword = corporateLayananValidatePassword($pppoePassword);
    if ($errPassword !== '') {
        redirectEditLayanan($corporateId, '0', $errPassword);
    }
    if (corporateLayananUsernameTaken($conn, $pppoeUsername, $id)) {
        redirectEditLayanan($corporateId, '0', "Username PPPoE \"$pppoeUsername\" sudah dipakai");
    }
}

// Deteksi perlu re-provisioning: provisioning berubah aktif/nonaktif, atau
// (kalau tetap aktif) field terkait koneksi berubah.
$wasProvisioned = (int) $old['provisioning_aktif'] === 1;
$connectionFieldsChanged = (
    (int) $old['server_id'] !== (int) ($serverId ?? 0)
    || (int) $old['paket_id'] !== (int) ($paketRow['id'] ?? 0)
    || (string) $old['ip_address'] !== $ipAddress
    || (string) $old['pppoe_username'] !== $pppoeUsername
    || $pppoePasswordInput !== ''
    || (string) $old['auth_mode'] !== $authMode
);

if ($wasProvisioned && (!$provisioningAktif || $connectionFieldsChanged)) {
    // Lepas provisioning lama (server/authmode/username LAMA).
    $oldServerForDeprovision = [
        'IP' => $old['old_server_ip'] ?? '',
        'PASSWORD' => $old['old_server_password'] ?? '',
        'PEMILIK' => $old['old_server_pemilik'] ?? '',
    ];
    if (!empty($oldServerForDeprovision['IP'])) {
        corporateLayananDeprovision($oldServerForDeprovision, (string) $old['auth_mode'], (string) $old['pppoe_username']);
    }
}

if ($provisioningAktif === 1 && (!$wasProvisioned || $connectionFieldsChanged)) {
    $provisionResult = corporateLayananProvision($conn, $serverRow, $paketRow, $authMode, $pppoeUsername, $pppoePassword, $ipAddress);
    if (!$provisionResult['success']) {
        redirectEditLayanan($corporateId, '0', 'Provisioning gagal: ' . $provisionResult['message']);
    }
}

if ($provisioningAktif !== 1) {
    $pppoeUsername = '';
    $pppoePassword = '';
}

// "Status Layanan" NONAKTIF/AKTIF ikut memutus/menyambungkan koneksi router
// (reuse mekanisme SAMA PERSIS tombol Isolir manual, lihat
// corporateLayananSetIsolir()) -- supaya tidak ada koneksi yang diam-diam
// tetap hidup padahal admin sudah tandai layanan ini NONAKTIF. Cuma dipicu
// kalau field koneksi (server/paket/ip/username/password/auth_mode) TIDAK
// ikut berubah di edit yang sama (kasus itu sudah ditangani re-provisioning
// penuh di atas, supaya tidak dobel panggil API Mikrotik/RADIUS dalam 1
// request) -- kalau admin ganti status BERBARENGAN dengan field koneksi,
// status_koneksi hasil re-provisioning (AKTIF) yang menang; admin tinggal
// klik Isolir manual terpisah kalau memang maunya langsung nonaktif.
$statusJustChanged = ((string) $old['status'] !== $status);
$statusSyncApplied = false;
if ($provisioningAktif === 1 && $wasProvisioned && !$connectionFieldsChanged && $statusJustChanged) {
    $isolirTarget = ($status === 'NONAKTIF');
    $syncResult = corporateLayananSetIsolir($conn, $serverRow, $paketRow, $authMode, $pppoeUsername, $pppoePassword, $ipAddress, $isolirTarget);
    if (!$syncResult['success']) {
        redirectEditLayanan($corporateId, '0', 'Gagal sinkronkan status koneksi: ' . $syncResult['message']);
    }
    $statusSyncApplied = true;
}

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
// Status koneksi direset ke AKTIF tiap kali re-provisioning penuh terjadi
// (provisioning baru selalu dibuat dalam keadaan aktif) -- kalau provisioning
// dimatikan, status_koneksi tidak relevan lagi tapi tetap disimpan AKTIF sbg
// default netral. Kalau barusan disinkronkan dari "Status Layanan" (lihat
// blok $statusSyncApplied di atas), pakai hasil sinkronisasi itu.
if ($statusSyncApplied) {
    $statusKoneksi = ($status === 'NONAKTIF') ? 'ISOLIR' : 'AKTIF';
} else {
    $statusKoneksi = (!$wasProvisioned && $provisioningAktif === 1) || $connectionFieldsChanged ? 'AKTIF' : $old['status_koneksi'];
}

$sql = "UPDATE corporate_layanan SET
    jenis_layanan = '" . mysqli_real_escape_string($conn, $jenisLayanan) . "',
    nama_layanan = '" . mysqli_real_escape_string($conn, $namaLayanan) . "',
    server_id = $serverIdSql,
    paket_id = $paketIdSql,
    ip_address = '" . mysqli_real_escape_string($conn, $ipAddress) . "',
    vlan_id = $vlanIdSql,
    olt_id = $oltIdSql,
    provisioning_aktif = $provisioningAktif,
    auth_mode = '" . mysqli_real_escape_string($conn, $authMode) . "',
    pppoe_username = '" . mysqli_real_escape_string($conn, $pppoeUsername) . "',
    pppoe_password = '" . mysqli_real_escape_string($conn, $pppoePassword) . "',
    status_koneksi = '" . mysqli_real_escape_string($conn, $statusKoneksi) . "',
    status = '" . mysqli_real_escape_string($conn, $status) . "',
    tanggal_aktif = $tanggalAktifSql,
    catatan = '" . mysqli_real_escape_string($conn, $catatan) . "'
    WHERE id = $id";

if (!mysqli_query($conn, $sql)) {
    redirectEditLayanan($corporateId, '0', 'Gagal update database: ' . mysqli_error($conn));
}

$history_file = "../notifbot/data/history-$ceknama.json";
$history = [];
if (file_exists($history_file)) {
    $history = json_decode(file_get_contents($history_file), true);
}
if (!is_array($history)) { $history = []; }
$history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Berhasil edit Layanan id=$id corporate_id=$corporateId";
@file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

redirectEditLayanan($corporateId, '1');
