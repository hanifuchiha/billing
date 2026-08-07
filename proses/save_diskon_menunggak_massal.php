<?php
require '../cek-sesi.php';

header('Content-Type: application/json; charset=utf-8');

function out_json($success, $message, $extra = [])
{
    echo json_encode(array_merge([
        'success' => (bool)$success,
        'message' => (string)$message,
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function parse_idpel_list($raw)
{
    $parts = preg_split('/[,;\r\n]+/', (string)$raw);
    $list = [];
    if (!is_array($parts)) {
        return $list;
    }

    foreach ($parts as $item) {
        $item = trim((string)$item);
        if ($item === '') {
            continue;
        }
        $item = preg_replace('/[\x00-\x1F\x7F]/u', '', $item);
        if ($item !== '') {
            $list[$item] = true;
        }
    }

    return array_keys($list);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    out_json(false, 'Metode request tidak valid.');
}

$bulanPenggunaan = [
    'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
];

$rawIdpelList = isset($_POST['idpel_list']) ? trim((string)$_POST['idpel_list']) : '';
$nominalType = strtolower(trim((string)($_POST['nominal_type'] ?? 'nominal')));
$nominal = (float)($_POST['nominal'] ?? 0);
$periodeMonth = trim((string)($_POST['periode_month'] ?? ''));
$periodeYear = (int)($_POST['periode_year'] ?? 0);
$keterangan = trim((string)($_POST['keterangan'] ?? ''));

if ($rawIdpelList === '') {
    out_json(false, 'Tidak ada pelanggan terpilih.');
}
if (!in_array($nominalType, ['nominal', 'persentase'], true)) {
    out_json(false, 'Jenis nilai diskon tidak valid.');
}
if ($nominal <= 0) {
    out_json(false, 'Nilai diskon harus lebih dari 0.');
}
if ($nominalType === 'persentase' && $nominal > 100) {
    out_json(false, 'Nilai persentase maksimal 100%.');
}
if (!in_array($periodeMonth, $bulanPenggunaan, true) || $periodeYear < 2000 || $periodeYear > 2100) {
    out_json(false, 'Periode diskon tidak valid.');
}

$idpelList = parse_idpel_list($rawIdpelList);
if (count($idpelList) === 0) {
    out_json(false, 'Format ID pelanggan tidak valid.');
}

$createTableSql = "CREATE TABLE IF NOT EXISTS diskon_pelanggan (
  id INT AUTO_INCREMENT PRIMARY KEY,
  MODE ENUM('global','per_pelanggan') NOT NULL DEFAULT 'global',
  GLOBAL_SCOPE ENUM('server','odp') NULL,
  SCOPE_VALUE VARCHAR(190) NULL,
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
  INDEX idx_diskon_lookup (ACTIVE, MODE, IDPEL, PEMILIK, PERIODE),
  INDEX idx_diskon_scope (ACTIVE, MODE, GLOBAL_SCOPE, SCOPE_VALUE, GLOBAL_AREA, GLOBAL_PAKET, PERIODE),
  INDEX idx_diskon_periode (PERIODE)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
mysqli_query($conn, $createTableSql);

$existingDiskonColumns = [];
$columnQuery = mysqli_query($conn, "SHOW COLUMNS FROM diskon_pelanggan");
if ($columnQuery) {
    while ($col = mysqli_fetch_assoc($columnQuery)) {
        $existingDiskonColumns[] = (string)$col['Field'];
    }
}
if (!in_array('GLOBAL_SCOPE', $existingDiskonColumns, true)) {
    mysqli_query($conn, "ALTER TABLE diskon_pelanggan ADD COLUMN GLOBAL_SCOPE ENUM('server','odp') NULL AFTER MODE");
}
if (!in_array('SCOPE_VALUE', $existingDiskonColumns, true)) {
    mysqli_query($conn, "ALTER TABLE diskon_pelanggan ADD COLUMN SCOPE_VALUE VARCHAR(190) NULL AFTER GLOBAL_SCOPE");
}
if (!in_array('GLOBAL_AREA', $existingDiskonColumns, true)) {
    mysqli_query($conn, "ALTER TABLE diskon_pelanggan ADD COLUMN GLOBAL_AREA VARCHAR(120) NULL AFTER SCOPE_VALUE");
}
if (!in_array('GLOBAL_PAKET', $existingDiskonColumns, true)) {
    mysqli_query($conn, "ALTER TABLE diskon_pelanggan ADD COLUMN GLOBAL_PAKET VARCHAR(150) NULL AFTER GLOBAL_AREA");
}
if (!in_array('NOMINAL_TYPE', $existingDiskonColumns, true)) {
    mysqli_query($conn, "ALTER TABLE diskon_pelanggan ADD COLUMN NOMINAL_TYPE ENUM('nominal','persentase') NOT NULL DEFAULT 'nominal' AFTER GLOBAL_PAKET");
}

$allowedOwners = [];
$ownerQuerySql = "SELECT DISTINCT PEMILIK FROM server WHERE user_id = " . (int)$current_user_id;
if (isset($AKSES) && $AKSES === 'ASSISTANT' && !empty($area_list)) {
    $ownerQuerySql = "SELECT DISTINCT PEMILIK FROM server WHERE AREA IN ($area_list)";
}
$ownerQuery = mysqli_query($conn, $ownerQuerySql);
while ($ownerQuery && ($ownerRow = mysqli_fetch_assoc($ownerQuery))) {
    if (!empty($ownerRow['PEMILIK'])) {
        $allowedOwners[] = (string)$ownerRow['PEMILIK'];
    }
}
if (!in_array((string)$ceknama, $allowedOwners, true)) {
    $allowedOwners[] = (string)$ceknama;
}
$allowedOwners = array_values(array_unique(array_filter($allowedOwners, static function ($val) {
    return $val !== '';
})));

$idEscaped = [];
foreach ($idpelList as $idpel) {
    $idEscaped[] = "'" . mysqli_real_escape_string($conn, $idpel) . "'";
}

$ownerWhere = '';
if (count($allowedOwners) > 0) {
    $ownerEscaped = [];
    foreach ($allowedOwners as $owner) {
        $ownerEscaped[] = "'" . mysqli_real_escape_string($conn, $owner) . "'";
    }
    $ownerWhere = ' AND PEMILIK IN (' . implode(',', $ownerEscaped) . ')';
}

$targetSql = "SELECT IDPEL, PEMILIK FROM pelanggan WHERE TRIM(IDPEL) IN (" . implode(',', $idEscaped) . ")" . $ownerWhere;
$targetResult = mysqli_query($conn, $targetSql);
$targets = [];
while ($targetResult && ($row = mysqli_fetch_assoc($targetResult))) {
    $targets[] = [
        'IDPEL' => (string)$row['IDPEL'],
        'PEMILIK' => (string)$row['PEMILIK'],
    ];
}

if (count($targets) === 0) {
    $fallbackSql = "SELECT IDPEL, PEMILIK FROM pelanggan WHERE TRIM(IDPEL) IN (" . implode(',', $idEscaped) . ")";
    $fallbackResult = mysqli_query($conn, $fallbackSql);
    while ($fallbackResult && ($row = mysqli_fetch_assoc($fallbackResult))) {
        $targets[] = [
            'IDPEL' => (string)$row['IDPEL'],
            'PEMILIK' => (string)$row['PEMILIK'],
        ];
    }
}

if (count($targets) === 0) {
    out_json(false, 'Tidak ada pelanggan valid untuk diberi diskon.');
}

$periode = $periodeMonth . ' ' . $periodeYear;
$createdBy = (string)($ceknama ?? 'system');

$stmtDisable = $conn->prepare("UPDATE diskon_pelanggan SET ACTIVE = 0 WHERE ACTIVE = 1 AND MODE = 'per_pelanggan' AND IDPEL = ? AND PEMILIK = ? AND PERIODE = ?");
$stmtInsert = $conn->prepare("INSERT INTO diskon_pelanggan (MODE, GLOBAL_SCOPE, SCOPE_VALUE, GLOBAL_AREA, IDPEL, PEMILIK, PERIODE, NOMINAL_TYPE, NOMINAL, KETERANGAN, ACTIVE, CREATED_BY) VALUES ('per_pelanggan', NULL, NULL, NULL, ?, ?, ?, ?, ?, ?, 1, ?)");

if (!$stmtDisable || !$stmtInsert) {
    if ($stmtDisable) {
        $stmtDisable->close();
    }
    if ($stmtInsert) {
        $stmtInsert->close();
    }
    out_json(false, 'Gagal menyiapkan query diskon massal.');
}

$summary = [
    'total_selected' => count($idpelList),
    'matched' => count($targets),
    'created' => 0,
    'skipped_not_found' => max(0, count($idpelList) - count($targets)),
    'failed' => 0,
];

$details = [];

foreach ($targets as $target) {
    $idpel = (string)$target['IDPEL'];
    $pemilik = (string)$target['PEMILIK'];

    $stmtDisable->bind_param('sss', $idpel, $pemilik, $periode);
    $stmtDisable->execute();

    $stmtInsert->bind_param('ssssdss', $idpel, $pemilik, $periode, $nominalType, $nominal, $keterangan, $createdBy);
    $ok = $stmtInsert->execute();

    if ($ok) {
        $summary['created']++;
        $details[] = [
            'idpel' => $idpel,
            'status' => 'created',
        ];
    } else {
        $summary['failed']++;
        $details[] = [
            'idpel' => $idpel,
            'status' => 'failed',
            'message' => mysqli_error($conn),
        ];
    }
}

$stmtDisable->close();
$stmtInsert->close();

out_json(true, 'Pengaturan diskon massal selesai diproses.', [
    'summary' => $summary,
    'details' => $details,
]);
