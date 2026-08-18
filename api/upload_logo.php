<?php
// api/upload_logo.php - Upload logo billing per-akun (API version dari crm/billing/upload.php).
// Sama seperti web: ASSISTANT hanya boleh upload logo SENDIRI (bukan logo owner) kalau owner
// sudah mengaktifkan toggle "btn_logo_billing_sendiri" untuknya (Pengaturan User > Tombol
// Individual ASSISTANT). Setelah tersimpan, warna tema (--primary-color/--secondary-color)
// otomatis dihitung & disimpan lewat logo_color_helper.php, PERSIS seperti alur web.
require_once '../koneksibilling.php';
require_once '_bootstrap.php';
require_once '../logo_color_helper.php';
session_start();
api_cors();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    api_json(['success' => false, 'error' => 'Method tidak didukung, gunakan POST'], 405);
}

$input = api_read_input();
$auth = api_authenticate($conn, $input);
$pemilik = $auth['pemilik'];
if ($auth['method'] === 'apikey') {
    api_rate_limit($conn, $auth['api_key']);
}

$ctx = api_resolve_owner($conn, $pemilik);
if (!$ctx) {
    api_json(['success' => false, 'error' => 'User tidak ditemukan'], 401);
}

// $logo_owner_key: sama persis logikanya dgn cek-sesi.php -- assistant yg toggle
// "btn_logo_billing_sendiri"-nya aktif pakai username sendiri, selain itu (assistant tanpa izin,
// atau owner) pakai username owner. Assistant yang BELUM diizinkan ditolak total (bukan diam-diam
// menimpa logo owner).
$logo_owner_key = $ctx['username'];
if ($ctx['is_assistant']) {
    $safeUsername = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$ctx['username']);
    $allowed = false;
    if ($safeUsername !== '') {
        $settingsFile = __DIR__ . '/../settings/dashboard-cards-' . $safeUsername . '.json';
        if (is_file($settingsFile)) {
            $decoded = json_decode((string)@file_get_contents($settingsFile), true);
            $allowed = is_array($decoded) && array_key_exists('btn_logo_billing_sendiri', $decoded)
                ? (bool)$decoded['btn_logo_billing_sendiri']
                : true; // key belum pernah disimpan -> default true, sama seperti web
        } else {
            $allowed = true; // belum pernah ada file settings -> default true
        }
    }
    if (!$allowed) {
        api_json(['success' => false, 'error' => 'Akun ASSISTANT ini belum diizinkan upload logo sendiri oleh owner'], 403);
    }
}

if (!isset($_FILES['profile_picture'])) {
    api_json(['success' => false, 'error' => 'Tidak ada file yang diunggah (field: profile_picture)'], 400);
}

$file = $_FILES['profile_picture'];
$maxSize = 2 * 1024 * 1024; // 2 MB
$allowedExt = ['jpg', 'jpeg', 'png'];
$allowedMime = ['image/jpeg', 'image/png', 'image/pjpeg', 'image/x-png'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    api_json(['success' => false, 'error' => 'Upload error (code ' . $file['error'] . ')'], 400);
}
if ($file['size'] > $maxSize) {
    api_json(['success' => false, 'error' => 'File terlalu besar. Maksimum ' . ($maxSize / 1024 / 1024) . ' MB'], 400);
}

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, $allowedExt, true)) {
    api_json(['success' => false, 'error' => 'Hanya file JPG atau PNG yang diperbolehkan'], 400);
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);
if (!in_array($mime, $allowedMime, true)) {
    api_json(['success' => false, 'error' => 'Tipe file tidak valid (bukan JPG/PNG)'], 400);
}

$imginfo = @getimagesize($file['tmp_name']);
if ($imginfo === false) {
    api_json(['success' => false, 'error' => 'Bukan file gambar yang valid'], 400);
}

$target_dir = __DIR__ . '/../../../dokumen/logo/';
if (!is_dir($target_dir) && !@mkdir($target_dir, 0755, true) && !is_dir($target_dir)) {
    api_json(['success' => false, 'error' => 'Gagal membuat folder upload'], 500);
}

$sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '_', $logo_owner_key);
$target_file = $target_dir . "profile-{$sanitized}.png";

if (file_exists($target_file)) {
    @unlink($target_file);
}

$saved = false;
if (function_exists('imagecreatefromstring')) {
    $imageData = file_get_contents($file['tmp_name']);
    $img = @imagecreatefromstring($imageData);
    if ($img !== false) {
        imagesavealpha($img, true);
        $saved = imagepng($img, $target_file);
        imagedestroy($img);
    } else {
        $saved = move_uploaded_file($file['tmp_name'], $target_file);
    }
} else {
    $saved = move_uploaded_file($file['tmp_name'], $target_file);
}

if (!$saved || !file_exists($target_file)) {
    api_json(['success' => false, 'error' => 'Gagal menyimpan file logo'], 500);
}
@chmod($target_file, 0644);

// Hitung & simpan warna tema dari logo baru ini, sama seperti alur upload.php di web.
logoColorExtractAndSave($logo_owner_key, $target_file);

api_json([
    'success' => true,
    'logo_url' => '/dokumen/logo/profile-' . $sanitized . '.png',
    'owner_key' => $logo_owner_key,
]);
