<?php
// Butuh sesi login: hapus logo HANYA boleh untuk akun milik sendiri yang sedang
// login ($ceknama dari sesi), bukan nilai "ceknama" kiriman client (sebelumnya
// endpoint ini bisa dipakai siapa saja tanpa login untuk menghapus logo bisnis
// manapun cukup dengan menebak usernamenya).
require '../cek-sesi.php';

$allowed_redirects = ['../user.php', '../struk_setting.php', '../portal_setting.php'];
$redirect_to = $_POST['redirect_to'] ?? '../user.php';
if (!in_array($redirect_to, $allowed_redirects, true)) {
    $redirect_to = '../user.php';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $AKSES === 'ASSISTANT') {
    header('Location: ' . $redirect_to);
    exit;
}

$target_username = ($AKSES == 'ASSISTANT' && !empty($asistant_name)) ? $asistant_name : $ceknama;
$safe_username = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $target_username);

if ($safe_username !== '') {
    // Logo terpusat di dokumen/logo/ (sejajar crm/)
    $file = __DIR__ . "/../../../dokumen/logo/profile-$safe_username.png";

    if (file_exists($file)) {
        unlink($file);
    }

    // log history
    $history_file = "../notifbot/data/history-$safe_username.json";
    $history = [];
    if (file_exists($history_file)) {
        $history = json_decode(file_get_contents($history_file), true);
    }
    if (!is_array($history)) { $history = []; }
    $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Hapus logo profil $safe_username";
    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
}

header('Location: ' . $redirect_to);
exit();
