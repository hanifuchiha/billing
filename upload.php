<?php
require 'cek-sesi.php'; // pastikan $ceknama didefinisikan

// === Konfigurasi ===
// Ditulis langsung ke folder terpusat dokumen/logo/ (sejajar crm/, diakses via URL /dokumen/logo/...)
$target_dir = __DIR__ . "/../../dokumen/logo/";
$maxSize = 2 * 1024 * 1024; // 2 MB
$allowedExt = ['jpg','jpeg','png'];
$allowedMime = ['image/jpeg','image/png','image/pjpeg','image/x-png'];

// === Cek file upload ===
if (!isset($_FILES['profile_picture'])) {
    die("❌ Tidak ada file yang diunggah.");
}

$file = $_FILES['profile_picture'];

// === Cek error upload ===
if ($file['error'] !== UPLOAD_ERR_OK) {
    $errs = [
        UPLOAD_ERR_INI_SIZE => 'File melebihi upload_max_filesize di php.ini',
        UPLOAD_ERR_FORM_SIZE => 'File melebihi MAX_FILE_SIZE pada form',
        UPLOAD_ERR_PARTIAL => 'Upload terpotong',
        UPLOAD_ERR_NO_FILE => 'Tidak ada file diunggah',
        UPLOAD_ERR_NO_TMP_DIR => 'Tidak ada direktori temporer',
        UPLOAD_ERR_CANT_WRITE => 'Gagal menulis ke disk',
        UPLOAD_ERR_EXTENSION => 'Upload dihentikan ekstensi'
    ];
    $msg = $errs[$file['error']] ?? 'Kesalahan upload';
    die("❌ Upload error: $msg");
}

// === Cek ukuran ===
if ($file['size'] > $maxSize) {
    die("❌ File terlalu besar. Maksimum " . ($maxSize/1024/1024) . " MB.");
}

// === Buat folder upload jika belum ada ===
if (!is_dir($target_dir)) {
    if (!mkdir($target_dir, 0755, true)) {
        die("❌ Gagal membuat folder upload.");
    }
}

// === Cek ekstensi ===
$originalName = $file['name'];
$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
if (!in_array($ext, $allowedExt)) {
    die("❌ Hanya file JPG atau PNG yang diperbolehkan (ekstensi).");
}

// === Cek MIME ===
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);
if (!in_array($mime, $allowedMime)) {
    die("❌ Tipe file tidak valid (bukan JPG/PNG).");
}

// === Validasi bahwa file benar-benar gambar ===
$imginfo = @getimagesize($file['tmp_name']);
if ($imginfo === false) {
    die("❌ Bukan file gambar yang valid.");
}

// === Sanitasi nama user ===
$sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '_', ($ceknama ?? 'user'));
// Selalu simpan sebagai .png (dikonversi jika perlu) supaya konsisten dengan semua
// tempat yang mencari logo di "uploads/profile-{username}.png" (struk, header, dsb).
$targetExt = 'png';
$target_file = $target_dir . "profile-{$sanitized}." . $targetExt;

// === Hapus file lama ===
if (file_exists($target_file)) {
    @unlink($target_file);
}

// === Jika GD tersedia, re-encode gambar ===
if (function_exists('imagecreatefromstring')) {
    $imageData = file_get_contents($file['tmp_name']);
    $img = @imagecreatefromstring($imageData);
    if ($img !== false) {
        if ($targetExt === 'png') {
            imagesavealpha($img, true);
            imagepng($img, $target_file);
        } else {
            $bg = imagecreatetruecolor(imagesx($img), imagesy($img));
            imagefill($bg, 0, 0, imagecolorallocate($bg, 255,255,255));
            imagecopy($bg, $img, 0, 0, 0, 0, imagesx($img), imagesy($img));
            imagejpeg($bg, $target_file, 85);
            imagedestroy($bg);
        }
        imagedestroy($img);
    } else {
        // fallback copy jika gagal decode
        move_uploaded_file($file['tmp_name'], $target_file);
    }
} else {
    // fallback: GD tidak tersedia, langsung pindahkan file
    move_uploaded_file($file['tmp_name'], $target_file);
}

// === Set permission aman ===
@chmod($target_file, 0644);

// === Redirect kembali ke halaman asal (whitelist supaya tidak jadi open redirect) ===
$allowed_redirects = ['user.php', 'struk_setting.php', 'portal_setting.php'];
$redirect_to = $_POST['redirect_to'] ?? 'user.php';
if (!in_array($redirect_to, $allowed_redirects, true)) {
    $redirect_to = 'user.php';
}
header("Location: " . $redirect_to . "?img=" . urlencode("profile-{$sanitized}.{$targetExt}") . "&v=" . time());
exit();
?>
