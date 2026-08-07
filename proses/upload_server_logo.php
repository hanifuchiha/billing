<?php
require '../cek-sesi.php';

// === Konfigurasi (sama seperti upload.php, tapi khusus logo per-server) ===
// Ditulis langsung ke folder terpusat dokumen/logo/ (sejajar crm/, diakses via URL /dokumen/logo/...)
$target_dir = __DIR__ . '/../../../dokumen/logo/';
$maxSize = 2 * 1024 * 1024; // 2 MB
$allowedExt = ['jpg', 'jpeg', 'png'];
$allowedMime = ['image/jpeg', 'image/png', 'image/pjpeg', 'image/x-png'];

function redirect_back_with_msg($status, $msg)
{
    header("Location: ../server.php?status=" . $status . "&msg=" . urlencode($msg));
    exit;
}

$server_id = (int) ($_POST['server_id'] ?? 0);
if ($server_id <= 0) {
    redirect_back_with_msg('gagal', 'Server tidak valid.');
}

// Pastikan server ini benar-benar milik user yang sedang login
$stmt = mysqli_prepare($conn, "SELECT id, LOGO FROM server WHERE id = ? AND user_id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, "ii", $server_id, $current_user_id);
mysqli_stmt_execute($stmt);
$row = mysqli_stmt_get_result($stmt)->fetch_assoc();
mysqli_stmt_close($stmt);

if (!$row) {
    redirect_back_with_msg('gagal', 'Server tidak ditemukan atau bukan milik Anda.');
}

if (!isset($_FILES['server_logo']) || $_FILES['server_logo']['error'] === UPLOAD_ERR_NO_FILE) {
    redirect_back_with_msg('gagal', 'Tidak ada file logo yang diunggah.');
}

$file = $_FILES['server_logo'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    redirect_back_with_msg('gagal', 'Upload logo gagal (kode error: ' . $file['error'] . ').');
}

if ($file['size'] > $maxSize) {
    redirect_back_with_msg('gagal', 'File logo terlalu besar. Maksimum ' . ($maxSize / 1024 / 1024) . ' MB.');
}

if (!is_dir($target_dir)) {
    @mkdir($target_dir, 0755, true);
}

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, $allowedExt, true)) {
    redirect_back_with_msg('gagal', 'Hanya file JPG atau PNG yang diperbolehkan.');
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);
if (!in_array($mime, $allowedMime, true)) {
    redirect_back_with_msg('gagal', 'Tipe file tidak valid (bukan JPG/PNG).');
}

$imginfo = @getimagesize($file['tmp_name']);
if ($imginfo === false) {
    redirect_back_with_msg('gagal', 'Bukan file gambar yang valid.');
}

// Nama file lepas dari id server (id bisa berubah tiap kali server di-edit
// karena alur edit server pakai delete+insert) -- token unik supaya tidak
// pernah bentrok dan tidak perlu direname ulang setelah edit.
$new_filename = 'server-logo-' . bin2hex(random_bytes(8)) . '.png';
$target_file = $target_dir . $new_filename;

if (function_exists('imagecreatefromstring')) {
    $imageData = file_get_contents($file['tmp_name']);
    $img = @imagecreatefromstring($imageData);
    if ($img !== false) {
        imagesavealpha($img, true);
        imagepng($img, $target_file);
        imagedestroy($img);
    } else {
        move_uploaded_file($file['tmp_name'], $target_file);
    }
} else {
    move_uploaded_file($file['tmp_name'], $target_file);
}
@chmod($target_file, 0644);

// Simpan nama file baru ke DB, lalu hapus file logo lama (kalau ada)
$old_logo = (string) ($row['LOGO'] ?? '');

$stmtUpd = mysqli_prepare($conn, "UPDATE server SET LOGO = ? WHERE id = ? AND user_id = ?");
mysqli_stmt_bind_param($stmtUpd, "sii", $new_filename, $server_id, $current_user_id);
if (!mysqli_stmt_execute($stmtUpd)) {
    mysqli_stmt_close($stmtUpd);
    @unlink($target_file);
    redirect_back_with_msg('gagal', 'Gagal menyimpan logo ke database.');
}
mysqli_stmt_close($stmtUpd);

if ($old_logo !== '' && $old_logo !== $new_filename) {
    $old_file = $target_dir . basename($old_logo);
    if (is_file($old_file)) {
        @unlink($old_file);
    }
}

redirect_back_with_msg('sukses', 'Logo server berhasil diperbarui.');
