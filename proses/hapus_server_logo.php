<?php
require '../cek-sesi.php';

$server_id = (int) ($_POST['server_id'] ?? 0);
if ($server_id <= 0) {
    header("Location: ../server.php?status=gagal&msg=" . urlencode("Server tidak valid."));
    exit;
}

$stmt = mysqli_prepare($conn, "SELECT LOGO FROM server WHERE id = ? AND user_id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, "ii", $server_id, $current_user_id);
mysqli_stmt_execute($stmt);
$row = mysqli_stmt_get_result($stmt)->fetch_assoc();
mysqli_stmt_close($stmt);

if (!$row) {
    header("Location: ../server.php?status=gagal&msg=" . urlencode("Server tidak ditemukan atau bukan milik Anda."));
    exit;
}

$logo = (string) ($row['LOGO'] ?? '');

$stmtUpd = mysqli_prepare($conn, "UPDATE server SET LOGO = NULL WHERE id = ? AND user_id = ?");
mysqli_stmt_bind_param($stmtUpd, "ii", $server_id, $current_user_id);
$ok = mysqli_stmt_execute($stmtUpd);
mysqli_stmt_close($stmtUpd);

if (!$ok) {
    header("Location: ../server.php?status=gagal&msg=" . urlencode("Gagal menghapus logo server dari database."));
    exit;
}

if ($logo !== '') {
    // Logo terpusat di dokumen/logo/ (sejajar crm/)
    $file = __DIR__ . '/../../../dokumen/logo/' . basename($logo);
    if (is_file($file)) {
        @unlink($file);
    }
}

header("Location: ../server.php?status=sukses&msg=" . urlencode("Logo server dihapus, akan kembali memakai logo akun billing."));
exit;
