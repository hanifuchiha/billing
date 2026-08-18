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

// Basic input retrieval and validation
$merchantCode = $_POST['dk_merchant_code'] ?? '';
$apiKey = $_POST['dk_api_key'] ?? '';


$server = $_POST['dk_server'] ?? '';
$dk_pajak = $_POST['dk_pajak'] ?? '';
// BHPS USO dihapus dari sistem (2026-08-02) -- selalu '0', tidak lagi dari
// input admin. Kolom bhps_uso di DB dibiarkan ada (tidak dihapus).
$dk_bhps_uso = '0';
$authMode = $_POST['dk_auth_mode'] ?? 'RADIUS MODE';
// Pakai $ceknama (owner), bukan $_SESSION['USERNAME'], karena semua query
// tampilan/list Duitku di paymentset.php & lookup runtime di portal_bayar.php
// memfilter pemilik=$ceknama. Kalau yang mengisi form adalah akun
// ASSISTANT/sub-user, $_SESSION['USERNAME'] beda dari $ceknama sehingga data
// tersimpan tapi tidak pernah muncul/dipakai, dan nama file callback yang
// disalin tidak cocok dengan $callbackUrl (yang sudah benar pakai $ceknama).
$pemilik = $ceknama ?? '';


 $domain=$config['domain'];
$callbackUrl = "https://$domain/crm/billing/callbackduitku/callback_duitku_$ceknama.php";
$returnUrl = "https://$domain/crm/billing/broadband/portallogin.php";








// Debug: tampilkan data yang diterima (hapus ini setelah selesai debug)
// error_log("Data received: " . print_r($_POST, true));

if ($merchantCode === '' || $apiKey === '' || $callbackUrl === '' || $returnUrl === '' || $server === '' || $pemilik === '' || $authMode === '') {
    respond_and_exit('Semua field wajib diisi');
}

// Validasi pajak harus berupa angka
if (!is_numeric($dk_pajak) || $dk_pajak < 0) {
    respond_and_exit('PPN harus berupa angka yang valid');
}

// URL validation
if (!filter_var($callbackUrl, FILTER_VALIDATE_URL) || !filter_var($returnUrl, FILTER_VALIDATE_URL)) {
    respond_and_exit('URL tidak valid');
}

// Auth mode validation
$validAuthModes = ['RADIUS MODE', 'API MODE', 'MULTI MODE'];
if (!in_array($authMode, $validAuthModes)) {
    respond_and_exit('Auth mode tidak valid. Pilih salah satu: RADIUS MODE, API MODE, atau MULTI MODE');
}

// Siapkan file callback khusus per username seperti addtripay
$folder = '../callbackduitku/';
$allowFiles = [
    'callback_duitku.php'
];
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
            // log history
            $history_file = "../notifbot/data/history-$ceknama.json";
            $history = [];
            if (file_exists($history_file)) {
                $history = json_decode(file_get_contents($history_file), true);
            }
            if (!is_array($history)) { $history = []; }
            $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Berhasil mengatur akun payment gateway Duitku untuk $pemilik";
            file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
        }
    }
}

// Periksa koneksi database
if (!isset($conn) || !$conn) {
    respond_and_exit('Koneksi database tidak tersedia');
}

// Simpan ke DB mengikuti pola addtripay
$merchantCodeEsc = mysqli_real_escape_string($conn, $merchantCode);
$apiKeyEsc = mysqli_real_escape_string($conn, $apiKey);
$pajakEsc = mysqli_real_escape_string($conn, $dk_pajak);
$callbackEsc = mysqli_real_escape_string($conn, $callbackUrl);
$returnEsc = mysqli_real_escape_string($conn, $returnUrl);
$serverEsc = mysqli_real_escape_string($conn, $server);
$pemilikEsc = mysqli_real_escape_string($conn, $pemilik);
$authModeEsc = mysqli_real_escape_string($conn, $authMode);


$bhpsUsoEsc = mysqli_real_escape_string($conn, $dk_bhps_uso);
$sql = "INSERT INTO `duitku`(`server`, `pemilik`,`pajak`, `merchant_code`, `api_key`, `callback_url`, `return_url`, `default_auth_mode`, `bhps_uso`) VALUES ('$serverEsc','$pemilikEsc','$pajakEsc','$merchantCodeEsc','$apiKeyEsc','$callbackEsc','$returnEsc','$authModeEsc','$bhpsUsoEsc')
ON DUPLICATE KEY UPDATE `pajak`=VALUES(`pajak`), `merchant_code`=VALUES(`merchant_code`), `api_key`=VALUES(`api_key`), `callback_url`=VALUES(`callback_url`), `return_url`=VALUES(`return_url`), `default_auth_mode`=VALUES(`default_auth_mode`), `bhps_uso`=VALUES(`bhps_uso`)";

$query = mysqli_query($conn, $sql);
if ($query) {
    respond_and_exit('Data berhasil disimpan', '../paymentset.php');
} else {
    $error = mysqli_error($conn);
    respond_and_exit('Gagal menyimpan pengaturan: ' . $error);
}

?>


