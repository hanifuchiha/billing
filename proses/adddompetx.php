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

$apiKey = $_POST['dx_api_key'] ?? '';
$secretKey = trim($_POST['dx_secret_key'] ?? '');
$server = $_POST['dx_server'] ?? '';
$dx_pajak = $_POST['dx_pajak'] ?? '';
// BHPS USO dihapus dari sistem (2026-08-02) -- selalu '0', tidak lagi dari
// input admin. Kolom bhps_uso di DB dibiarkan ada (tidak dihapus).
$dx_bhps_uso = '0';
$authMode = $_POST['dx_default_auth_mode'] ?? 'API MODE';
$pemilik = $_SESSION['USERNAME'] ?? '';

$domain = $config['domain'];
$callbackUrl = "https://$domain/crm/billing/callbackdompetx/callback_dompetx_$ceknama.php";
$returnUrl = "https://$domain/crm/billing/broadband/portallogin.php";

if ($apiKey === '' || $callbackUrl === '' || $returnUrl === '' || $server === '' || $pemilik === '' || $authMode === '') {
    respond_and_exit('Semua field wajib diisi (API Key wajib, Secret Key opsional)');
}

if ($dx_pajak !== '' && (!is_numeric($dx_pajak) || $dx_pajak < 0)) {
    respond_and_exit('PPN harus berupa angka yang valid');
}

if (!filter_var($callbackUrl, FILTER_VALIDATE_URL) || !filter_var($returnUrl, FILTER_VALIDATE_URL)) {
    respond_and_exit('URL tidak valid');
}

$validAuthModes = ['RADIUS MODE', 'API MODE', 'MULTI MODE'];
if (!in_array($authMode, $validAuthModes)) {
    respond_and_exit('Auth mode tidak valid. Pilih salah satu: RADIUS MODE, API MODE, atau MULTI MODE');
}

// Siapkan file callback khusus per username (pola sama seperti addtripay/addipaymu)
$folder = '../callbackdompetx/';
$allowFiles = ['callback_dompetx.php'];
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
            $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Berhasil mengatur akun payment gateway DompetX untuk $pemilik";
            file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
        }
    }
}

if (!isset($conn) || !$conn) {
    respond_and_exit('Koneksi database tidak tersedia');
}

// Auto-install tabel `dompetx` kalau belum ada (mis. server yg belum sempat
// jalankan migrations/2026-07-31_add_dompetx.sql) -- biar simpan pengaturan
// tidak gagal cuma gara-gara tabel belum dibuat manual.
@mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `dompetx` (
  `id` int NOT NULL AUTO_INCREMENT,
  `pemilik` varchar(100) NOT NULL,
  `server` varchar(100) NOT NULL,
  `api_key` varchar(255) NOT NULL,
  `secret_key` varchar(255) DEFAULT NULL,
  `pajak` varchar(10) DEFAULT '0',
  `bhps_uso` varchar(255) NOT NULL,
  `default_auth_mode` varchar(50) DEFAULT 'API MODE',
  `callback` varchar(255) NOT NULL,
  `return` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_server_pemilik` (`server`,`pemilik`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");

$apiKeyEsc = mysqli_real_escape_string($conn, $apiKey);
$secretKeyEsc = $secretKey !== '' ? mysqli_real_escape_string($conn, $secretKey) : null;
$pajakEsc = mysqli_real_escape_string($conn, $dx_pajak !== '' ? $dx_pajak : '0');
$bhpsUsoEsc = mysqli_real_escape_string($conn, $dx_bhps_uso);
$callbackEsc = mysqli_real_escape_string($conn, $callbackUrl);
$returnEsc = mysqli_real_escape_string($conn, $returnUrl);
$serverEsc = mysqli_real_escape_string($conn, $server);
$pemilikEsc = mysqli_real_escape_string($conn, $pemilik);
$authModeEsc = mysqli_real_escape_string($conn, $authMode);
$secretKeySqlValue = $secretKeyEsc === null ? 'NULL' : "'$secretKeyEsc'";

$sql = "INSERT INTO `dompetx`(`server`, `pemilik`, `api_key`, `secret_key`, `pajak`, `bhps_uso`, `default_auth_mode`, `callback`, `return`)
        VALUES ('$serverEsc','$pemilikEsc','$apiKeyEsc',$secretKeySqlValue,'$pajakEsc','$bhpsUsoEsc','$authModeEsc','$callbackEsc','$returnEsc')
        ON DUPLICATE KEY UPDATE `api_key`=VALUES(`api_key`), `secret_key`=VALUES(`secret_key`), `pajak`=VALUES(`pajak`), `bhps_uso`=VALUES(`bhps_uso`),
        `default_auth_mode`=VALUES(`default_auth_mode`), `callback`=VALUES(`callback`), `return`=VALUES(`return`)";

$query = @mysqli_query($conn, $sql);
if ($query) {
    respond_and_exit('Data berhasil disimpan', '../paymentset.php');
} else {
    $error = mysqli_error($conn);
    respond_and_exit('Gagal menyimpan pengaturan: ' . $error . ' (Pastikan tabel `dompetx` sudah dibuat, lihat migrations/2026-07-31_add_dompetx.sql)');
}
