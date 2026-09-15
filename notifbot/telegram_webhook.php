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

// ---- callback_query: tekan tombol inline / sub-menu bot admin billing ----
// callback_data berisi string perintah (mis. "/transaksi 30") yang diproses
// lewat jalur telegramAdminHandle yang sama dgn pesan teks biasa.
if (isset($update['callback_query']) && is_array($update['callback_query'])) {
    $cb = $update['callback_query'];
    $cbChat = (string)($cb['message']['chat']['id'] ?? $cb['from']['id'] ?? '');
    $cbData = trim((string)($cb['data'] ?? ''));
    $cbId   = (string)($cb['id'] ?? '');

    $stmtCb = $conn->prepare("SELECT * FROM bottelegram WHERE id = ? LIMIT 1");
    $stmtCb->bind_param('i', $botId);
    $stmtCb->execute();
    $cbBotRow = $stmtCb->get_result()->fetch_assoc();
    $stmtCb->close();

    if ($cbBotRow) {
        require_once __DIR__ . '/telegram_admin_helper.php';
        telegramAdminEnsureColumns($conn);
        if ($cbChat !== '' && $cbData !== '') {
            telegramAdminHandle($conn, $cbBotRow, $cbChat, $cbData, true);
        }
        answerTelegramCallback((string)$cbBotRow['bottoken'], $cbId);
    }
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

// Foto (bukti pembayaran utk Manual Aktif bot admin). Ambil resolusi terbesar.
// Juga terima document ber-mime image/*.
$photoFileId = '';
if (isset($message['photo']) && is_array($message['photo']) && $message['photo']) {
    $ph = end($message['photo']);
    $photoFileId = (string)($ph['file_id'] ?? '');
} elseif (isset($message['document']['file_id']) && strpos((string)($message['document']['mime_type'] ?? ''), 'image/') === 0) {
    $photoFileId = (string)$message['document']['file_id'];
}
if ($photoFileId !== '' && $text === '') {
    $text = trim((string)($message['caption'] ?? ''));
}

if ($chatId === '' || ($text === '' && $photoFileId === '')) {
    echo json_encode(['ok' => true]);
    exit;
}

$stmtBot = $conn->prepare("SELECT * FROM bottelegram WHERE id = ? LIMIT 1");
$stmtBot->bind_param('i', $botId);
$stmtBot->execute();
$botRow = $stmtBot->get_result()->fetch_assoc();
$stmtBot->close();

if (!$botRow) {
    echo json_encode(['ok' => true]);
    exit;
}
$botToken = (string)$botRow['bottoken'];

// Bot Admin Billing: kalau bot ini diaktifkan mode admin-nya & pengirim
// terdaftar sbg admin, perintah spt /pelanggan /server /tagihan dll dijawab
// dengan data billing (dibatasi per-izin). Lihat notifbot/telegram_admin_helper.php.
require_once __DIR__ . '/telegram_admin_helper.php';
telegramAdminEnsureColumns($conn);

// Format Telegram utk deep-link: "/start <payload>" (payload dari
// t.me/<username>?start=<payload>) ATAU "/start" polos tanpa payload.
if (preg_match('/^\/start(?:@\S+)?(?:\s+(\S+))?$/i', $text, $m)) {
    $idpelPayload = trim((string)($m[1] ?? ''));

    if ($idpelPayload === '') {
        // /start polos dari admin terdaftar -> tampilkan menu bot admin.
        if (telegramAdminHandle($conn, $botRow, $chatId, $text)) {
            echo json_encode(['ok' => true]);
            exit;
        }
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
    sendTelegramMessage($botToken, $chatId, "Berhasil! Akun Telegram Anda sekarang terhubung dengan pelanggan " . $namaCustomer . " ($idpelPayload). Anda akan menerima notifikasi tagihan/informasi layanan di sini.", '');
    echo json_encode(['ok' => true]);
    exit;
}

// Perintah bot admin (/id, /pelanggan, /server, /tagihan, /menunggak, ...) +
// foto bukti pembayaran utk /aktif.
if (telegramAdminHandle($conn, $botRow, $chatId, $text, false, $photoFileId)) {
    echo json_encode(['ok' => true]);
    exit;
}

// Pesan lain -- belum ada auto-respon utk pelanggan umum di fase ini.
echo json_encode(['ok' => true]);
