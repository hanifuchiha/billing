<?php
require '../cek-sesi.php';

function respond_and_exit($message, $redirect = '../paymentset.php') {
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $safeRedirect = htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8');
    echo "<script>alert('$safeMessage'); window.location='$safeRedirect';</script>";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../paymentset.php');
    exit;
}

$merchantId = $_POST['fp_merchant_id'] ?? '';
$userId = $_POST['fp_user_id'] ?? '';
$password = $_POST['fp_password'] ?? '';
$server = $_POST['fp_server'] ?? '';
$fp_pajak = $_POST['fp_pajak'] ?? '';
// BHPS USO dihapus dari sistem (2026-08-02) -- selalu '0', tidak lagi dari
// input admin. Kolom bhps_uso di DB dibiarkan ada (tidak dihapus).
$fp_bhps_uso = '0';
$authMode = $_POST['fp_default_auth_mode'] ?? 'API MODE';
// Pakai $ceknama (owner), bukan $_SESSION['USERNAME'], karena semua query
// tampilan/list Faspay di paymentset.php & lookup runtime di portal_bayar.php
// memfilter pemilik=$ceknama. Kalau yang mengisi form adalah akun
// ASSISTANT/sub-user, $_SESSION['USERNAME'] beda dari $ceknama sehingga data
// tersimpan tapi tidak pernah muncul/dipakai, dan nama file callback yang
// disalin tidak cocok dengan $callbackUrl (yang sudah benar pakai $ceknama).
$pemilik = $ceknama ?? '';

$domain = $config['domain'];
$callbackUrl = "https://$domain/crm/billing/callbackfaspay/callback_faspay_$ceknama.php";
$returnUrl = "https://$domain/crm/billing/broadband/portallogin.php";

if ($merchantId === '' || $userId === '' || $password === '' || $callbackUrl === '' || $returnUrl === '' || $server === '' || $pemilik === '' || $authMode === '') {
    respond_and_exit('Semua field wajib diisi');
}

if ($fp_pajak !== '' && (!is_numeric($fp_pajak) || $fp_pajak < 0)) {
    respond_and_exit('PPN harus berupa angka yang valid');
}

if (!filter_var($callbackUrl, FILTER_VALIDATE_URL) || !filter_var($returnUrl, FILTER_VALIDATE_URL)) {
    respond_and_exit('URL tidak valid');
}

$validAuthModes = ['RADIUS MODE', 'API MODE', 'MULTI MODE'];
if (!in_array($authMode, $validAuthModes)) {
    respond_and_exit('Auth mode tidak valid. Pilih salah satu: RADIUS MODE, API MODE, atau MULTI MODE');
}

// Siapkan file callback khusus per username (pola sama seperti addtripay/addduitku)
$folder = '../callbackfaspay/';
$allowFiles = ['callback_faspay.php'];
foreach ($allowFiles as $filename) {
    $file = $folder . $filename;
    if (!file_exists($file)) {
        echo "⚠️ File tidak ditemukan: $file<br>";
        continue;
    }
    $nameOnly = pathinfo($filename, PATHINFO_FILENAME);
    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    if (preg_match('/_' . preg_quote($pemilik, '/') . '$/', $nameOnly)) {
        echo "ℹ️ Lewati, sudah ada username di nama file: $filename<br>";
        continue;
    }
    $baru = $folder . $nameOnly . '_' . $pemilik . '.' . $ext;
    if (!file_exists($baru)) {
        if (copy($file, $baru)) {
            chmod($baru, 0777);
            @chown($baru, 'qts');
            @chgrp($baru, 'qts');
            $history_file = "../notifbot/data/history-$ceknama.json";
            $history = [];
            if (file_exists($history_file)) {
                $history = json_decode(file_get_contents($history_file), true);
            }
            if (!is_array($history)) { $history = []; }
            $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Berhasil mengatur akun payment gateway Faspay untuk $pemilik";
            file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
        }
    }
}

if (!isset($conn) || !$conn) {
    respond_and_exit('Koneksi database tidak tersedia');
}

// Auto-install tabel `faspay` kalau belum ada (mis. server yg belum sempat
// jalankan migrations/2026-07-17_add_ipaymu_doku_faspay.sql).
@mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `faspay` (
  `id` int NOT NULL AUTO_INCREMENT,
  `pemilik` varchar(100) NOT NULL,
  `server` varchar(100) NOT NULL,
  `merchant_id` varchar(255) NOT NULL,
  `user_id` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `pajak` varchar(10) DEFAULT '0',
  `bhps_uso` varchar(255) NOT NULL,
  `default_auth_mode` varchar(50) DEFAULT 'API MODE',
  `callback` varchar(255) NOT NULL,
  `return` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_server_pemilik` (`server`,`pemilik`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");

$merchantIdEsc = mysqli_real_escape_string($conn, $merchantId);
$userIdEsc = mysqli_real_escape_string($conn, $userId);
$passwordEsc = mysqli_real_escape_string($conn, $password);
$pajakEsc = mysqli_real_escape_string($conn, $fp_pajak !== '' ? $fp_pajak : '0');
$bhpsUsoEsc = mysqli_real_escape_string($conn, $fp_bhps_uso);
$callbackEsc = mysqli_real_escape_string($conn, $callbackUrl);
$returnEsc = mysqli_real_escape_string($conn, $returnUrl);
$serverEsc = mysqli_real_escape_string($conn, $server);
$pemilikEsc = mysqli_real_escape_string($conn, $pemilik);
$authModeEsc = mysqli_real_escape_string($conn, $authMode);

$sql = "INSERT INTO `faspay`(`server`, `pemilik`, `merchant_id`, `user_id`, `password`, `pajak`, `bhps_uso`, `default_auth_mode`, `callback`, `return`)
        VALUES ('$serverEsc','$pemilikEsc','$merchantIdEsc','$userIdEsc','$passwordEsc','$pajakEsc','$bhpsUsoEsc','$authModeEsc','$callbackEsc','$returnEsc')
        ON DUPLICATE KEY UPDATE `merchant_id`=VALUES(`merchant_id`), `user_id`=VALUES(`user_id`), `password`=VALUES(`password`), `pajak`=VALUES(`pajak`),
        `bhps_uso`=VALUES(`bhps_uso`), `default_auth_mode`=VALUES(`default_auth_mode`), `callback`=VALUES(`callback`), `return`=VALUES(`return`)";

$query = @mysqli_query($conn, $sql);
if ($query) {
    respond_and_exit('Data berhasil disimpan', '../paymentset.php');
} else {
    $error = mysqli_error($conn);
    respond_and_exit('Gagal menyimpan pengaturan: ' . $error . ' (Pastikan tabel `faspay` sudah dibuat, lihat migrations/2026-07-17_add_ipaymu_doku_faspay.sql)');
}
