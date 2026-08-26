<?php

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
@ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../koneksibilling.php';

const KEUANGAN_PAYMENT_SYNC_API_KEY = '9fa120bc7d157225c58238c91051c7f2baf37c5338d75f8c9c260082babe2de8';

function paymentSyncJson(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function paymentSyncHeader(string $name): string
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return trim((string)($_SERVER[$serverKey] ?? ''));
}

function paymentSyncEnsureColumn(mysqli $conn, string $column, string $definition): void
{
    $columnSafe = mysqli_real_escape_string($conn, $column);
    $exists = mysqli_query($conn, "SHOW COLUMNS FROM transaksi LIKE '$columnSafe'");
    if ($exists && mysqli_num_rows($exists) === 0) {
        mysqli_query($conn, "ALTER TABLE transaksi ADD COLUMN `$columnSafe` $definition");
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    paymentSyncJson(['ok' => false, 'message' => 'Koneksi database Billing tidak tersedia.'], 500);
}
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    paymentSyncJson(['ok' => false, 'message' => 'Gunakan method POST.'], 405);
}

$providedKey = paymentSyncHeader('X-API-Key');
if ($providedKey === '' || !hash_equals(KEUANGAN_PAYMENT_SYNC_API_KEY, $providedKey)) {
    paymentSyncJson(['ok' => false, 'message' => 'API key tidak valid.'], 401);
}

$raw = (string)file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    paymentSyncJson(['ok' => false, 'message' => 'Payload JSON tidak valid.'], 400);
}

$originPaymentId = (int)($payload['origin_payment_id'] ?? 0);
$originTransactionId = (int)($payload['origin_transaction_id'] ?? 0);
$idpel = trim((string)($payload['idpel'] ?? ''));
$tanggalBayar = trim((string)($payload['tanggal_bayar'] ?? ''));
$periode = trim((string)($payload['periode'] ?? ''));
$jumlahBayar = (int)preg_replace('/[^0-9]/', '', (string)($payload['jumlah_bayar'] ?? '0'));
$billingMethod = strtolower(trim((string)($payload['billing_method'] ?? '')));
$buktiUrl = trim((string)($payload['bukti_url'] ?? ''));
$keterangan = trim((string)($payload['keterangan'] ?? ''));

if ($originPaymentId <= 0 || $idpel === '' || $jumlahBayar <= 0 || $billingMethod === '') {
    paymentSyncJson(['ok' => false, 'message' => 'origin_payment_id, idpel, jumlah_bayar, dan billing_method wajib diisi.'], 422);
}
if (!in_array($billingMethod, ['cash', 'transfer'], true)) {
    paymentSyncJson(['ok' => false, 'message' => 'billing_method hanya boleh cash atau transfer.'], 422);
}
$date = DateTime::createFromFormat('Y-m-d', $tanggalBayar);
if (!$date || $date->format('Y-m-d') !== $tanggalBayar) {
    paymentSyncJson(['ok' => false, 'message' => 'tanggal_bayar harus menggunakan format YYYY-MM-DD.'], 422);
}
if ($periode === '') {
    $bulan = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $periode = $bulan[(int)$date->format('n')] . ' ' . $date->format('Y');
}

paymentSyncEnsureColumn($conn, 'KEUANGAN_SYNCED', 'TINYINT(1) NOT NULL DEFAULT 0');
paymentSyncEnsureColumn($conn, 'KEUANGAN_PAYMENT_ID', 'VARCHAR(64) NULL DEFAULT NULL');
paymentSyncEnsureColumn($conn, 'KEUANGAN_SYNC_AT', 'DATETIME NULL DEFAULT NULL');
paymentSyncEnsureColumn($conn, 'KEUANGAN_SOURCE_PAYMENT_ID', 'INT NULL DEFAULT NULL');
paymentSyncEnsureColumn($conn, 'KEUANGAN_SOURCE_TRANSACTION_ID', 'INT NULL DEFAULT NULL');
paymentSyncEnsureColumn($conn, 'ORIGIN_SYSTEM', 'VARCHAR(30) NULL DEFAULT NULL');

$index = mysqli_query($conn, "SHOW INDEX FROM transaksi WHERE Key_name='uq_keuangan_source_payment'");
if ($index && mysqli_num_rows($index) === 0) {
    mysqli_query($conn, "ALTER TABLE transaksi ADD UNIQUE KEY uq_keuangan_source_payment (KEUANGAN_SOURCE_PAYMENT_ID)");
}

$existingStmt = mysqli_prepare($conn, 'SELECT id FROM transaksi WHERE KEUANGAN_SOURCE_PAYMENT_ID=? LIMIT 1');
mysqli_stmt_bind_param($existingStmt, 'i', $originPaymentId);
mysqli_stmt_execute($existingStmt);
$existingResult = mysqli_stmt_get_result($existingStmt);
$existing = $existingResult ? mysqli_fetch_assoc($existingResult) : null;
mysqli_stmt_close($existingStmt);
if ($existing) {
    paymentSyncJson([
        'ok' => true,
        'duplicate' => true,
        'billing_transaction_id' => (int)$existing['id'],
        'message' => 'Pembayaran Keuangan sudah tercatat di Billing.'
    ]);
}

$customerStmt = mysqli_prepare($conn, 'SELECT id, IDPEL, NAMA, PAKET, PEMILIK FROM pelanggan WHERE TRIM(IDPEL)=? ORDER BY id ASC');
mysqli_stmt_bind_param($customerStmt, 's', $idpel);
mysqli_stmt_execute($customerStmt);
$customerResult = mysqli_stmt_get_result($customerStmt);
$customers = [];
while ($customerResult && ($row = mysqli_fetch_assoc($customerResult))) {
    $customers[] = $row;
}
mysqli_stmt_close($customerStmt);
if (count($customers) === 0) {
    paymentSyncJson(['ok' => false, 'message' => 'IDPEL tidak ditemukan di Billing.'], 404);
}
if (count($customers) > 1) {
    paymentSyncJson(['ok' => false, 'message' => 'IDPEL terduplikasi di Billing; pembayaran tidak dimasukkan demi keamanan.'], 409);
}
$customer = $customers[0];

$cek = 'KEUANGAN-PAYMENT-' . $originPaymentId;
if ($keterangan !== '') {
    $cek .= ' | ' . $keterangan;
}
$buktiUrl = mb_substr($buktiUrl, 0, 255, 'UTF-8');
$cek = mb_substr($cek, 0, 255, 'UTF-8');
$billingMethod = mb_substr($billingMethod, 0, 50, 'UTF-8');
$originSystem = 'keuangan_manual';
$status = 'BERHASIL';
$nama = (string)($customer['NAMA'] ?? '');
$paket = (string)($customer['PAKET'] ?? '');
$pemilik = (string)($customer['PEMILIK'] ?? '');
$keuanganPaymentId = (string)$originPaymentId;

$stmt = mysqli_prepare($conn, "
    INSERT INTO transaksi
        (TANGGALBAYAR, PENGUNAAN, STATUS, IDPEL, NAMA, PAKET, HARGA,
         METODE_BAYAR, BUKTI, CEK, PEMILIK, KEUANGAN_SYNCED,
         KEUANGAN_PAYMENT_ID, KEUANGAN_SYNC_AT, KEUANGAN_SOURCE_PAYMENT_ID,
         KEUANGAN_SOURCE_TRANSACTION_ID, ORIGIN_SYSTEM)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW(), ?, ?, ?)
");
mysqli_stmt_bind_param(
    $stmt,
    'ssssssisssssiis',
    $tanggalBayar,
    $periode,
    $status,
    $idpel,
    $nama,
    $paket,
    $jumlahBayar,
    $billingMethod,
    $buktiUrl,
    $cek,
    $pemilik,
    $keuanganPaymentId,
    $originPaymentId,
    $originTransactionId,
    $originSystem
);
$ok = mysqli_stmt_execute($stmt);
$insertId = (int)mysqli_insert_id($conn);
$error = mysqli_stmt_error($stmt);
$errorNumber = mysqli_stmt_errno($stmt);
mysqli_stmt_close($stmt);

if (!$ok) {
    if ($errorNumber === 1062) {
        $retry = mysqli_prepare($conn, 'SELECT id FROM transaksi WHERE KEUANGAN_SOURCE_PAYMENT_ID=? LIMIT 1');
        mysqli_stmt_bind_param($retry, 'i', $originPaymentId);
        mysqli_stmt_execute($retry);
        $retryResult = mysqli_stmt_get_result($retry);
        $retryRow = $retryResult ? mysqli_fetch_assoc($retryResult) : null;
        mysqli_stmt_close($retry);
        if ($retryRow) {
            paymentSyncJson(['ok' => true, 'duplicate' => true, 'billing_transaction_id' => (int)$retryRow['id']]);
        }
    }
    paymentSyncJson(['ok' => false, 'message' => 'Transaksi Billing gagal disimpan: ' . $error], 500);
}

paymentSyncJson([
    'ok' => true,
    'duplicate' => false,
    'billing_transaction_id' => $insertId,
    'message' => 'Pembayaran Keuangan berhasil dicatat ke Billing.'
], 201);
