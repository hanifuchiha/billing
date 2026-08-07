<?php
require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    twk_response(405, ['success' => false, 'message' => 'Method tidak diizinkan.']);
}

$conn = twk_db_connect();
$session = twk_require_auth($conn);
$idpel = (string)$session['idpel'];
$body = twk_get_json_body();
$reference = trim((string)($body['reference'] ?? ''));

if ($reference !== '') {
    $stmt = mysqli_prepare(
        $conn,
        "UPDATE transaksi
         SET STATUS = 'DIBATALKAN KODE'
         WHERE IDPEL = ? AND STATUS = 'PERMINTAAN KODE' AND BUKTI = ?"
    );
    if (!$stmt) {
        twk_response(500, ['success' => false, 'message' => 'Gagal membatalkan pembayaran.']);
    }
    mysqli_stmt_bind_param($stmt, 'ss', $idpel, $reference);
    mysqli_stmt_execute($stmt);
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);

    twk_payment_cancel_local($conn, $idpel, $reference);

    if ($affected <= 0) {
        twk_response(404, ['success' => false, 'message' => 'Transaksi aktif tidak ditemukan atau sudah dibatalkan.']);
    }

    twk_response(200, [
        'success' => true,
        'message' => 'Pembayaran berhasil dibatalkan.',
        'data' => [
            'cancelled_reference' => $reference
        ]
    ]);
}

$stmt = mysqli_prepare(
    $conn,
    "UPDATE transaksi
     SET STATUS = 'DIBATALKAN KODE'
     WHERE IDPEL = ? AND STATUS = 'PERMINTAAN KODE'"
);
if (!$stmt) {
    twk_response(500, ['success' => false, 'message' => 'Gagal membatalkan pembayaran.']);
}
mysqli_stmt_bind_param($stmt, 's', $idpel);
mysqli_stmt_execute($stmt);
$affected = mysqli_stmt_affected_rows($stmt);
mysqli_stmt_close($stmt);

twk_payment_cancel_local($conn, $idpel, null);

if ($affected <= 0) {
    twk_response(404, ['success' => false, 'message' => 'Tidak ada pembayaran aktif untuk dibatalkan.']);
}

twk_response(200, [
    'success' => true,
    'message' => 'Semua pembayaran aktif berhasil dibatalkan.',
    'data' => [
        'cancelled_count' => (int)$affected
    ]
]);
