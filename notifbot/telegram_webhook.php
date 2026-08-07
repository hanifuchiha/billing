<?php
/**
 * telegram_webhook.php
 *
 * Endpoint PUBLIK (tanpa login) yang dipanggil Telegram sendiri setiap ada
 * pesan masuk ke salah satu bot Telegram kita (didaftarkan per-bot via
 * telegramSetWebhook() saat bot dibuat, lihat proses/addtelegrambot.php).
 * URL per-bot unik lewat ?bot_id=<id>, supaya kita tahu bot mana yang
 * menerima pesan tanpa perlu re-lookup dari isi payload.
 *
 * Tugas SATU-SATUNYA di fase ini: tangkap command "/start <IDPEL>" (dikirim
 * pelanggan saat klik link t.me/<botusername>?start=<IDPEL>), validasi IDPEL
 * ada di tabel pelanggan, simpan chat_id pengirim ke pelanggan.TELEGRAM_CHAT_ID,
 * balas konfirmasi. Auto-respon/AI utk pesan umum lainnya SENGAJA belum
 * diimplementasikan (lihat plan -- fase susulan, setara fitur AI Provider/
 * Auto Respon di wabot.php).
 */

require_once __DIR__ . '/../koneksidb.php';
require_once __DIR__ . '/telegram_send_helper.php';
telegramEnsurePelangganColumn($conn);

header('Content-Type: application/json; charset=utf-8');

$botId = (int)($_GET['bot_id'] ?? 0);
if ($botId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false]);
    exit;
}

$raw = file_get_contents('php://input');
$update = json_decode((string)$raw, true);
if (!is_array($update)) {
    // Telegram tetap mengharapkan respons 200 supaya tidak dianggap gagal &
    // di-retry terus-menerus, walau payload-nya tidak kita mengerti.
    echo json_encode(['ok' => true]);
    exit;
}

$message = $update['message'] ?? $update['edited_message'] ?? null;
if (!is_array($message)) {
    echo json_encode(['ok' => true]);
    exit;
}

$chatId = (string)($message['chat']['id'] ?? '');
$text = trim((string)($message['text'] ?? ''));
if ($chatId === '' || $text === '') {
    echo json_encode(['ok' => true]);
    exit;
}

$stmtBot = $conn->prepare("SELECT id, namebot, bottoken, pemilik FROM bottelegram WHERE id = ? LIMIT 1");
$stmtBot->bind_param('i', $botId);
$stmtBot->execute();
$botRow = $stmtBot->get_result()->fetch_assoc();
$stmtBot->close();

if (!$botRow) {
    echo json_encode(['ok' => true]);
    exit;
}
$botToken = (string)$botRow['bottoken'];

// Format Telegram utk deep-link: "/start <payload>" (payload dari
// t.me/<username>?start=<payload>) ATAU "/start" polos tanpa payload.
if (preg_match('/^\/start(?:@\S+)?(?:\s+(\S+))?$/i', $text, $m)) {
    $idpelPayload = trim((string)($m[1] ?? ''));

    if ($idpelPayload === '') {
        sendTelegramMessage($botToken, $chatId, "Halo! Untuk menghubungkan akun Telegram Anda, buka link \"Hubungkan Telegram\" dari portal pelanggan Anda (bukan chat langsung ke bot ini).");
        echo json_encode(['ok' => true]);
        exit;
    }

    $idpelEsc = mysqli_real_escape_string($conn, $idpelPayload);
    $stmtCust = $conn->prepare("SELECT IDPEL, NAMA, PEMILIK FROM pelanggan WHERE IDPEL = ? LIMIT 1");
    $stmtCust->bind_param('s', $idpelPayload);
    $stmtCust->execute();
    $custRow = $stmtCust->get_result()->fetch_assoc();
    $stmtCust->close();

    if (!$custRow) {
        sendTelegramMessage($botToken, $chatId, "ID Pelanggan \"$idpelPayload\" tidak ditemukan. Pastikan Anda membuka link dari portal pelanggan yang benar.");
        echo json_encode(['ok' => true]);
        exit;
    }

    $stmtSave = $conn->prepare("UPDATE pelanggan SET TELEGRAM_CHAT_ID = ? WHERE IDPEL = ?");
    $stmtSave->bind_param('ss', $chatId, $idpelPayload);
    $stmtSave->execute();
    $stmtSave->close();

    $namaCustomer = (string)($custRow['NAMA'] ?? '');
    sendTelegramMessage($botToken, $chatId, "✅ Berhasil! Akun Telegram Anda sekarang terhubung dengan pelanggan *$namaCustomer* ($idpelPayload). Anda akan menerima notifikasi tagihan/informasi layanan di sini.");
    echo json_encode(['ok' => true]);
    exit;
}

// Pesan lain di luar "/start <IDPEL>" -- belum ada auto-respon di fase ini.
echo json_encode(['ok' => true]);
