<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../koneksibilling.php';
session_start();

function out_json($success, $message, $extra = [], $code = 200)
{
    http_response_code($code);
    echo json_encode(array_merge([
        'success' => (bool)$success,
        'message' => (string)$message,
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function auth_user_mobile($conn, $username, $password)
{
    $stmt = $conn->prepare('SELECT id, USERNAME, PASWORD FROM user WHERE USERNAME=? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        if (password_verify($password, (string)$row['PASWORD']) || (string)$password === (string)$row['PASWORD']) {
            return ['id' => (int)($row['id'] ?? 0), 'username' => (string)($row['USERNAME'] ?? '')];
        }
    }
    return null;
}

function parse_idpel_list($raw)
{
    $parts = preg_split('/[,;\r\n]+/', (string)$raw);
    $ids = [];
    if (!is_array($parts)) {
        return $ids;
    }
    foreach ($parts as $item) {
        $item = trim((string)$item);
        if ($item === '') {
            continue;
        }
        $item = preg_replace('/[\x00-\x1F\x7F]/u', '', $item);
        if ($item !== '') {
            $ids[$item] = true;
        }
    }
    return array_keys($ids);
}

function get_allowed_owners($conn, $username, $userId)
{
    $owners = [];
    $stmt = $conn->prepare('SELECT DISTINCT PEMILIK FROM server WHERE user_id=? OR PEMILIK=?');
    if ($stmt) {
        $stmt->bind_param('is', $userId, $username);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            if (!empty($row['PEMILIK'])) {
                $owners[] = (string)$row['PEMILIK'];
            }
        }
        $stmt->close();
    }
    if (empty($owners)) {
        $owners[] = $username;
    }
    return array_values(array_unique(array_filter($owners, static fn($v) => trim((string)$v) !== '')));
}

try {
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }

    $username = trim((string)($input['username'] ?? ($_POST['username'] ?? $_GET['username'] ?? '')));
    $password = trim((string)($input['password'] ?? ($_POST['password'] ?? $_GET['password'] ?? '')));
    $idpelRaw = trim((string)($input['idpel_list'] ?? ($_POST['idpel_list'] ?? $_GET['idpel_list'] ?? '')));
    $nominalType = strtolower(trim((string)($input['nominal_type'] ?? ($_POST['nominal_type'] ?? $_GET['nominal_type'] ?? 'nominal'))));
    $nominal = (float)($input['nominal'] ?? ($_POST['nominal'] ?? $_GET['nominal'] ?? 0));
    $periodeMonth = trim((string)($input['periode_month'] ?? ($_POST['periode_month'] ?? $_GET['periode_month'] ?? '')));
    $periodeYear = (int)($input['periode_year'] ?? ($_POST['periode_year'] ?? $_GET['periode_year'] ?? 0));
    $keterangan = trim((string)($input['keterangan'] ?? ($_POST['keterangan'] ?? $_GET['keterangan'] ?? '')));

    if ($username === '' || $password === '') {
        out_json(false, 'Autentikasi gagal');
    }
    $auth = auth_user_mobile($conn, $username, $password);
    if (!$auth) {
        out_json(false, 'Autentikasi gagal');
    }
    if ($idpelRaw === '') {
        out_json(false, 'Tidak ada pelanggan terpilih');
    }
    if (!in_array($nominalType, ['nominal', 'persentase'], true)) {
        out_json(false, 'Jenis nilai diskon tidak valid');
    }
    if ($nominal <= 0) {
        out_json(false, 'Nilai diskon harus lebih dari 0');
    }
    if ($nominalType === 'persentase' && $nominal > 100) {
        out_json(false, 'Nilai persentase maksimal 100%');
    }

    $bulanPenggunaan = [
        'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
    ];
    if (!in_array($periodeMonth, $bulanPenggunaan, true) || $periodeYear < 2000 || $periodeYear > 2100) {
        out_json(false, 'Periode diskon tidak valid');
    }

    $idpelList = parse_idpel_list($idpelRaw);
    if (count($idpelList) === 0) {
        out_json(false, 'Format ID pelanggan tidak valid');
    }

    $allowedOwners = get_allowed_owners($conn, $auth['username'], (int)$auth['id']);
    $ownerEscaped = [];
    foreach ($allowedOwners as $owner) {
        $ownerEscaped[] = "'" . mysqli_real_escape_string($conn, $owner) . "'";
    }
    $ownerWhere = count($ownerEscaped) > 0 ? (' AND PEMILIK IN (' . implode(',', $ownerEscaped) . ')') : '';

    $idEscaped = [];
    foreach ($idpelList as $idpel) {
        $idEscaped[] = "'" . mysqli_real_escape_string($conn, $idpel) . "'";
    }

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS diskon_pelanggan (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $existingColumns = [];
    $columnQuery = mysqli_query($conn, 'SHOW COLUMNS FROM diskon_pelanggan');
    while ($columnQuery && ($col = mysqli_fetch_assoc($columnQuery))) {
        $existingColumns[] = (string)$col['Field'];
    }
    if (!in_array('GLOBAL_SCOPE', $existingColumns, true)) {
        mysqli_query($conn, "ALTER TABLE diskon_pelanggan ADD COLUMN GLOBAL_SCOPE ENUM('server','odp') NULL AFTER MODE");
    }
    if (!in_array('SCOPE_VALUE', $existingColumns, true)) {
        mysqli_query($conn, "ALTER TABLE diskon_pelanggan ADD COLUMN SCOPE_VALUE VARCHAR(190) NULL AFTER GLOBAL_SCOPE");
    }
    if (!in_array('GLOBAL_AREA', $existingColumns, true)) {
        mysqli_query($conn, "ALTER TABLE diskon_pelanggan ADD COLUMN GLOBAL_AREA VARCHAR(120) NULL AFTER SCOPE_VALUE");
    }
    if (!in_array('GLOBAL_PAKET', $existingColumns, true)) {
        mysqli_query($conn, "ALTER TABLE diskon_pelanggan ADD COLUMN GLOBAL_PAKET VARCHAR(150) NULL AFTER GLOBAL_AREA");
    }
    if (!in_array('NOMINAL_TYPE', $existingColumns, true)) {
        mysqli_query($conn, "ALTER TABLE diskon_pelanggan ADD COLUMN NOMINAL_TYPE ENUM('nominal','persentase') NOT NULL DEFAULT 'nominal' AFTER GLOBAL_PAKET");
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
        out_json(false, 'Tidak ada pelanggan valid untuk diberi diskon');
    }

    $periode = $periodeMonth . ' ' . $periodeYear;
    $createdBy = (string)($auth['username'] ?? 'system');

    $stmtDisable = $conn->prepare("UPDATE diskon_pelanggan SET ACTIVE = 0 WHERE ACTIVE = 1 AND MODE = 'per_pelanggan' AND IDPEL = ? AND PEMILIK = ? AND PERIODE = ?");
    $stmtInsert = $conn->prepare("INSERT INTO diskon_pelanggan (MODE, GLOBAL_SCOPE, SCOPE_VALUE, GLOBAL_AREA, IDPEL, PEMILIK, PERIODE, NOMINAL_TYPE, NOMINAL, KETERANGAN, ACTIVE, CREATED_BY) VALUES ('per_pelanggan', NULL, NULL, NULL, ?, ?, ?, ?, ?, ?, 1, ?)");
    if (!$stmtDisable || !$stmtInsert) {
        out_json(false, 'Gagal menyiapkan query diskon massal', [], 500);
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
            $details[] = ['idpel' => $idpel, 'status' => 'created'];
        } else {
            $summary['failed']++;
            $details[] = ['idpel' => $idpel, 'status' => 'failed', 'message' => mysqli_error($conn)];
        }
    }

    $stmtDisable->close();
    $stmtInsert->close();

    out_json(true, 'Pengaturan diskon massal selesai diproses.', [
        'summary' => $summary,
        'details' => $details,
    ]);
} catch (Throwable $e) {
    out_json(false, $e->getMessage(), [], 500);
}
