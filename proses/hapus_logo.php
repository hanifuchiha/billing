<?php
// Butuh sesi login: hapus logo HANYA boleh untuk akun milik sendiri yang sedang
// login ($ceknama dari sesi), bukan nilai "ceknama" kiriman client (sebelumnya
// endpoint ini bisa dipakai siapa saja tanpa login untuk menghapus logo bisnis
// manapun cukup dengan menebak usernamenya).
require '../cek-sesi.php';
require_once __DIR__ . '/../logo_color_helper.php';

$allowed_redirects = ['../user.php', '../struk_setting.php', '../portal_setting.php'];
$redirect_to = $_POST['redirect_to'] ?? '../user.php';
if (!in_array($redirect_to, $allowed_redirects, true)) {
    $redirect_to = '../user.php';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $redirect_to);
    exit;
}

// ASSISTANT hanya boleh hapus logo kalau toggle "btn_logo_billing_sendiri"
// diaktifkan owner untuknya (lihat header.php/user.php). $logo_owner_key
// (dari cek-sesi.php) sudah otomatis resolve ke username assistant sendiri
// kalau dia yang login, jadi hapus di sini SELALU cuma menghapus logo milik
// akun yang sedang login sendiri -- tidak pernah logo assistant/owner lain.
if ($AKSES === 'ASSISTANT' && empty($ui_visibility_settings['btn_logo_billing_sendiri'] ?? null)) {
    header('Location: ' . $redirect_to);
    exit;
}

$target_username = $logo_owner_key;
$safe_username = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $target_username);

// Logo Usaha di struk_setting.php disimpan terpisah (struk-logo-{username}.png,
// lihat upload.php) supaya tidak ikut menghapus logo header/portal yang
// dipakai bersama akun ini -- deteksi dari redirect_to yg sama persis dgn yg
// dikirim form Hapus Logo di struk_setting.php.
$isStrukLogo = $redirect_to === '../struk_setting.php';

if ($safe_username !== '') {
    // Logo terpusat di dokumen/logo/ (sejajar crm/)
    $logoBaseName = $isStrukLogo ? 'struk-logo' : 'profile';
    $file = __DIR__ . "/../../../dokumen/logo/{$logoBaseName}-$safe_username.png";

    if (file_exists($file)) {
        unlink($file);
    }

    if (!$isStrukLogo) {
        // Hapus juga cache warna tema logo ini, supaya kembali fallback ke logo
        // owner/default (bukan warna logo yang barusan dihapus). Tidak relevan
        // utk logo struk krn logo itu tidak pernah dipakai ekstraksi warna tema.
        $colorFile = logoColorSettingsFile($safe_username);
        if ($colorFile && file_exists($colorFile)) {
            @unlink($colorFile);
        }
    }

    // log history
    $history_file = "../notifbot/data/history-$safe_username.json";
    $history = [];
    if (file_exists($history_file)) {
        $history = json_decode(file_get_contents($history_file), true);
    }
    if (!is_array($history)) { $history = []; }
    $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Hapus logo " . ($isStrukLogo ? 'struk' : 'profil') . " $safe_username";
    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
}

header('Location: ' . $redirect_to);
exit();
