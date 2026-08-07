<?php
/**
 * Reset akumulasi pemakaian data (customer_data_usage) untuk satu pelanggan.
 * Method: POST
 * Parameters: idpel
 */

require '../cek-sesi.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid method']);
    exit;
}

$idpel = trim((string) ($_POST['idpel'] ?? ''));

if ($idpel === '') {
    echo json_encode(['status' => 'error', 'message' => 'IDPEL tidak boleh kosong']);
    exit;
}

// Pastikan tabel ada (jika belum pernah dipantau sama sekali)
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS customer_data_usage (
    idpel VARCHAR(64) NOT NULL,
    accumulated_bytes_in BIGINT UNSIGNED NOT NULL DEFAULT 0,
    accumulated_bytes_out BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_bytes_in BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_bytes_out BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_uptime_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (idpel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$idpelEsc = mysqli_real_escape_string($conn, $idpel);
$ok = mysqli_query($conn, "DELETE FROM customer_data_usage WHERE idpel = '$idpelEsc'");

if ($ok) {
    echo json_encode(['status' => 'success', 'message' => "Counter pemakaian untuk $idpel berhasil di-reset."]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Gagal reset counter: ' . mysqli_error($conn)]);
}
exit;
