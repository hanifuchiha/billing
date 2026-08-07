<?php
require '../cek-sesi.php';
require_once __DIR__ . '/../notifbot/notif_template_helper.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Ambil input teks
    $pesan_registrasi = trim($_POST['pesan_registrasi']);
    $pesan_notif = trim($_POST['pesan_notif']);
    $pesan_notif_remainder = trim($_POST['pesan_notif_remainder']);

    // Simpan ke database (tabel notif_khusus)
    notifTemplateSaveSections($ceknama, $pesan_registrasi, $pesan_notif, $pesan_notif_remainder);

    $history_file = "../notifbot/data/history-$ceknama.json";
    $history = [];
    if (file_exists($history_file)) {
        $history = json_decode(file_get_contents($history_file), true);
    }
    if (!is_array($history)) $history = [];
    $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Menyimpan pengaturan notifikasi";
    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
}

header('Location: ../notification.php');
