<?php
require_once __DIR__ . '/common.php';

// Debug endpoint to check invoice data
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    twk_response(405, ['success' => false, 'message' => 'Method tidak diizinkan.']);
}

$conn = twk_db_connect();
$session = twk_require_auth($conn);
$idpel = (string)$session['idpel'];

// Get invoice table structure
$result = mysqli_query($conn, "SHOW COLUMNS FROM invoice");
$columns = [];
while ($row = mysqli_fetch_assoc($result)) {
    $columns[] = $row['Field'];
}

// Get all invoices for this customer
$invoices = [];
$sql = "SELECT * FROM invoice WHERE id_pelanggan = ? ORDER BY id DESC LIMIT 10";
$stmt = mysqli_prepare($conn, $sql);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, 's', $idpel);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $invoices[] = $row;
    }
    mysqli_stmt_close($stmt);
}

// Get count of unpaid invoices
$sqlCount = "SELECT COUNT(*) as cnt FROM invoice WHERE id_pelanggan = ? AND (TRIM(UPPER(COALESCE(status, ''))) IN ('BELUM BAYAR', 'KONFIRMASI') OR TRIM(COALESCE(status, '')) = '')";
$stmtCount = mysqli_prepare($conn, $sqlCount);
$unpaidCount = 0;
if ($stmtCount) {
    mysqli_stmt_bind_param($stmtCount, 's', $idpel);
    mysqli_stmt_execute($stmtCount);
    $resCount = mysqli_stmt_get_result($stmtCount);
    $rowCount = mysqli_fetch_assoc($resCount);
    $unpaidCount = $rowCount['cnt'] ?? 0;
    mysqli_stmt_close($stmtCount);
}

twk_response(200, [
    'success' => true,
    'debug' => [
        'idpel' => $idpel,
        'table_columns' => $columns,
        'total_invoices_on_file' => count($invoices),
        'unpaid_invoice_count' => $unpaidCount,
        'recent_invoices' => $invoices,
    ]
]);
