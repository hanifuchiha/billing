<?php
require '../cek-sesi.php';
require_once __DIR__ . '/../notifbot/telegram_bot_access_helper.php';
require_once __DIR__ . '/../notifbot/telegram_send_helper.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Metode tidak diizinkan.']);
    exit;
}

$namebot = trim((string)($_POST['namebot'] ?? ''));
$bottoken = trim((string)($_POST['bottoken'] ?? ''));

if ($namebot === '' || $bottoken === '') {
    echo json_encode(['success' => false, 'message' => 'Nama bot dan Bot Token wajib diisi.']);
    exit;
}

// Validasi token ke Telegram + ambil username bot (dipakai utk link "/start").
$identity = telegramGetMe($bottoken);
if (!$identity['success']) {
    echo json_encode(['success' => false, 'message' => $identity['message']]);
    exit;
}
$botUsername = $identity['username'];

// Bot yang dibuat oleh ASSISTANT (bukan owner) ditandai created_by_assistant --
// otomatis jadi PRIVATE, pola sama persis proses/addbot.php (bot WA).
$botCreatedByAssistant = ($AKSES === 'ASSISTANT') ? (string)($asistant_name ?? '') : '';

$stmt = $conn->prepare("INSERT INTO bottelegram (namebot, botusername, bottoken, pemilik, created_by_assistant) VALUES (?, ?, ?, ?, ?)");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Gagal menyiapkan query: ' . mysqli_error($conn)]);
    exit;
}
$stmt->bind_param('sssss', $namebot, $botUsername, $bottoken, $ceknama, $botCreatedByAssistant);
if (!$stmt->execute()) {
    $err = $stmt->error;
    $stmt->close();
    echo json_encode(['success' => false, 'message' => 'Gagal menyimpan bot: ' . $err]);
    exit;
}
$newBotId = $stmt->insert_id;
$stmt->close();

// Pasang webhook supaya pesan masuk (mis. "/start <IDPEL>") diteruskan ke
// notifbot/telegram_webhook.php -- kalau gagal, bot TETAP tersimpan (webhook
// bisa dipasang ulang manual nanti), cuma dikasih tahu di pesan sukses.
$webhookUrl = rtrim((string)($config['URL'] ?? ''), '/') . '/crm/billing/notifbot/telegram_webhook.php?bot_id=' . $newBotId;
$webhookResult = telegramSetWebhook($bottoken, $webhookUrl);

$message = 'Bot Telegram "@' . $botUsername . '" berhasil ditambahkan.';
if (!$webhookResult['success']) {
    $message .= ' Namun pemasangan webhook gagal (' . $webhookResult['message'] . ') -- pelanggan belum bisa "/start" utk hubungkan chat_id sampai ini diperbaiki.';
}

echo json_encode(['success' => true, 'message' => $message, 'bot_id' => $newBotId]);
