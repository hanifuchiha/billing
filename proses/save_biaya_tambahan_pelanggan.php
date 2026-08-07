<?php
require '../cek-sesi.php';

header('Content-Type: application/json; charset=utf-8');

function json_out($success, $message, $extra = [])
{
    echo json_encode(array_merge([
        'success' => (bool)$success,
        'message' => (string)$message,
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(false, 'Metode request tidak valid.');
}

$bulan_penggunaan = [
    'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
];

$createTableSql = "CREATE TABLE IF NOT EXISTS biaya_tambahan_pelanggan (
  id INT AUTO_INCREMENT PRIMARY KEY,
  MODE ENUM('global','per_pelanggan') NOT NULL DEFAULT 'global',
  GLOBAL_AREA VARCHAR(120) NULL,
  GLOBAL_PAKET VARCHAR(150) NULL,
  NOMINAL_TYPE ENUM('nominal','persentase') NOT NULL DEFAULT 'nominal',
  IDPEL VARCHAR(120) NULL,
  PEMILIK VARCHAR(150) NOT NULL,
  PERIODE VARCHAR(40) NOT NULL,
  NOMINAL DECIMAL(18,2) NOT NULL DEFAULT 0,
  KETERANGAN TEXT NULL,
  ACTIVE TINYINT(1) NOT NULL DEFAULT 1,
  CREATED_BY VARCHAR(120) NULL,
  CREATED_AT TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  UPDATED_AT TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_biaya_lookup (ACTIVE, MODE, IDPEL, PEMILIK, PERIODE),
  INDEX idx_biaya_scope (ACTIVE, MODE, PEMILIK, GLOBAL_AREA, PERIODE),
  INDEX idx_biaya_periode (PERIODE)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
mysqli_query($conn, $createTableSql);

$existingColumns = [];
$columnQuery = mysqli_query($conn, "SHOW COLUMNS FROM biaya_tambahan_pelanggan");
if ($columnQuery) {
    while ($col = mysqli_fetch_assoc($columnQuery)) {
        $existingColumns[] = (string)$col['Field'];
    }
}
if (!in_array('GLOBAL_AREA', $existingColumns, true)) {
    mysqli_query($conn, "ALTER TABLE biaya_tambahan_pelanggan ADD COLUMN GLOBAL_AREA VARCHAR(120) NULL AFTER MODE");
}
if (!in_array('GLOBAL_PAKET', $existingColumns, true)) {
    mysqli_query($conn, "ALTER TABLE biaya_tambahan_pelanggan ADD COLUMN GLOBAL_PAKET VARCHAR(150) NULL AFTER GLOBAL_AREA");
}
if (!in_array('NOMINAL_TYPE', $existingColumns, true)) {
    mysqli_query($conn, "ALTER TABLE biaya_tambahan_pelanggan ADD COLUMN NOMINAL_TYPE ENUM('nominal','persentase') NOT NULL DEFAULT 'nominal' AFTER GLOBAL_PAKET");
}
if (!in_array('PERIODE_TYPE', $existingColumns, true)) {
    mysqli_query($conn, "ALTER TABLE biaya_tambahan_pelanggan ADD COLUMN PERIODE_TYPE ENUM('bulanan','rentang','permanen') NOT NULL DEFAULT 'bulanan' AFTER PERIODE");
}
if (!in_array('PERIODE_MULAI', $existingColumns, true)) {
    mysqli_query($conn, "ALTER TABLE biaya_tambahan_pelanggan ADD COLUMN PERIODE_MULAI VARCHAR(40) NULL AFTER PERIODE_TYPE");
}
if (!in_array('PERIODE_SELESAI', $existingColumns, true)) {
    mysqli_query($conn, "ALTER TABLE biaya_tambahan_pelanggan ADD COLUMN PERIODE_SELESAI VARCHAR(40) NULL AFTER PERIODE_MULAI");
}

$idpel = trim((string)($_POST['idpel'] ?? ''));
$pemilik = trim((string)($_POST['pemilik'] ?? ''));
$nominalType = strtolower(trim((string)($_POST['nominal_type'] ?? 'nominal')));
$nominal = (float)($_POST['nominal'] ?? 0);
$periodeType = strtolower(trim((string)($_POST['periode_type'] ?? 'bulanan')));
$periodeMonth = trim((string)($_POST['periode_month'] ?? ''));
$periodeYear = (int)($_POST['periode_year'] ?? 0);
$periodeStartMonth = trim((string)($_POST['periode_start_month'] ?? ''));
$periodeStartYear = (int)($_POST['periode_start_year'] ?? 0);
$periodeEndMonth = trim((string)($_POST['periode_end_month'] ?? ''));
$periodeEndYear = (int)($_POST['periode_end_year'] ?? 0);
$keterangan = trim((string)($_POST['keterangan'] ?? ''));

if ($idpel === '' || $pemilik === '') {
    json_out(false, 'Data pelanggan tidak valid.');
}
if (!in_array($nominalType, ['nominal', 'persentase'], true)) {
    json_out(false, 'Jenis nilai tambahan biaya tidak valid.');
}
if (!in_array($periodeType, ['bulanan', 'rentang', 'permanen'], true)) {
    json_out(false, 'Jenis periode tambahan biaya tidak valid.');
}
if ($nominal <= 0) {
    json_out(false, 'Nilai tambahan biaya harus lebih dari 0.');
}
if ($nominalType === 'persentase' && $nominal > 100) {
    json_out(false, 'Nilai persentase maksimal 100%.');
}

$periode = '';
$periodeMulai = null;
$periodeSelesai = null;

if ($periodeType === 'bulanan') {
    if (!in_array($periodeMonth, $bulan_penggunaan, true) || $periodeYear < 2000 || $periodeYear > 2100) {
        json_out(false, 'Periode tambahan biaya tidak valid.');
    }
    $periode = $periodeMonth . ' ' . $periodeYear;
    $periodeMulai = $periode;
    $periodeSelesai = $periode;
} elseif ($periodeType === 'rentang') {
    if (!in_array($periodeStartMonth, $bulan_penggunaan, true) || $periodeStartYear < 2000 || $periodeStartYear > 2100) {
        json_out(false, 'Periode mulai tidak valid.');
    }
    if (!in_array($periodeEndMonth, $bulan_penggunaan, true) || $periodeEndYear < 2000 || $periodeEndYear > 2100) {
        json_out(false, 'Periode selesai tidak valid.');
    }
    $startIndex = $periodeStartYear * 12 + array_search($periodeStartMonth, $bulan_penggunaan, true);
    $endIndex = $periodeEndYear * 12 + array_search($periodeEndMonth, $bulan_penggunaan, true);
    if ($endIndex < $startIndex) {
        json_out(false, 'Periode selesai tidak boleh sebelum periode mulai.');
    }
    $periodeMulai = $periodeStartMonth . ' ' . $periodeStartYear;
    $periodeSelesai = $periodeEndMonth . ' ' . $periodeEndYear;
    $periode = $periodeMulai . ' s/d ' . $periodeSelesai;
} else {
    $periode = 'Permanen';
    $periodeMulai = null;
    $periodeSelesai = null;
}

$ownedPemilik = [];
$queryServerId = mysqli_query($conn, "SELECT PEMILIK FROM server WHERE user_id = " . (int)$current_user_id);
while ($row = mysqli_fetch_assoc($queryServerId)) {
    $ownedPemilik[] = (string)$row['PEMILIK'];
}
$ownedPemilik = array_values(array_unique(array_filter($ownedPemilik, static function ($value) {
    return $value !== '';
})));

if (!in_array($pemilik, $ownedPemilik, true) && $pemilik !== $ceknama) {
    json_out(false, 'Pelanggan tidak termasuk server Anda.');
}

$checkSql = "SELECT IDPEL, PEMILIK FROM pelanggan WHERE IDPEL = ? AND PEMILIK = ? LIMIT 1";
$stmtCheck = $conn->prepare($checkSql);
if (!$stmtCheck) {
    json_out(false, 'Gagal menyiapkan validasi pelanggan.');
}
$stmtCheck->bind_param('ss', $idpel, $pemilik);
$stmtCheck->execute();
$resCheck = $stmtCheck->get_result();
$pelanggan = $resCheck ? $resCheck->fetch_assoc() : null;
$stmtCheck->close();

if (!$pelanggan) {
    json_out(false, 'ID pelanggan tidak ditemukan.');
}

$createdBy = (string)($ceknama ?? 'system');

if ($periodeType === 'permanen') {
    $stmtDisable = $conn->prepare("UPDATE biaya_tambahan_pelanggan SET ACTIVE = 0 WHERE ACTIVE = 1 AND MODE = 'per_pelanggan' AND IDPEL = ? AND PEMILIK = ? AND PERIODE_TYPE = 'permanen'");
    if ($stmtDisable) {
        $stmtDisable->bind_param('ss', $idpel, $pemilik);
        $stmtDisable->execute();
        $stmtDisable->close();
    }
} else {
    $stmtDisable = $conn->prepare("UPDATE biaya_tambahan_pelanggan SET ACTIVE = 0 WHERE ACTIVE = 1 AND MODE = 'per_pelanggan' AND IDPEL = ? AND PEMILIK = ? AND PERIODE = ?");
    if ($stmtDisable) {
        $stmtDisable->bind_param('sss', $idpel, $pemilik, $periode);
        $stmtDisable->execute();
        $stmtDisable->close();
    }
}

$stmtInsert = $conn->prepare("INSERT INTO biaya_tambahan_pelanggan (MODE, GLOBAL_AREA, NOMINAL_TYPE, IDPEL, PEMILIK, PERIODE, PERIODE_TYPE, PERIODE_MULAI, PERIODE_SELESAI, NOMINAL, KETERANGAN, ACTIVE, CREATED_BY) VALUES ('per_pelanggan', NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)");
if (!$stmtInsert) {
    json_out(false, 'Gagal menyiapkan query simpan tambahan biaya.');
}

$stmtInsert->bind_param('sssssssdss', $nominalType, $idpel, $pemilik, $periode, $periodeType, $periodeMulai, $periodeSelesai, $nominal, $keterangan, $createdBy);
$okInsert = $stmtInsert->execute();
$stmtInsert->close();

if (!$okInsert) {
    json_out(false, 'Gagal menyimpan tambahan biaya pelanggan.');
}

json_out(true, 'Tambahan biaya pelanggan berhasil disimpan.');
