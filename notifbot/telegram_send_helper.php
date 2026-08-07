<?php
/**
 * telegram_send_helper.php
 *
 * Satu-satunya tempat bicara ke Telegram Bot API (https://api.telegram.org).
 * Beda dari bot WA (gateway GoWA sendiri per-bot, butuh docker container) --
 * Telegram Bot API resmi, publik, tidak butuh infrastruktur apapun di sisi
 * kita selain 1 Bot Token dari @BotFather. Tidak ada konsep container/QR/
 * reconnect/logout sama sekali di sini.
 */

if (!function_exists('telegramApiUrl')) {
    function telegramApiUrl(string $botToken, string $method): string
    {
        return 'https://api.telegram.org/bot' . rawurlencode($botToken) . '/' . $method;
    }
}

if (!function_exists('telegramGetMe')) {
    /**
     * Validasi token + ambil identitas bot (dipanggil sekali saat bot
     * ditambahkan, sama fungsinya dgn "test connection" di wabot.php).
     * Return ['success'=>bool, 'username'=>string, 'message'=>string].
     */
    function telegramGetMe(string $botToken): array
    {
        if (trim($botToken) === '') {
            return ['success' => false, 'username' => '', 'message' => 'Bot Token kosong.'];
        }
        if (!function_exists('curl_init')) {
            return ['success' => false, 'username' => '', 'message' => 'Ekstensi cURL PHP belum aktif di server.'];
        }

        $ch = curl_init(telegramApiUrl($botToken, 'getMe'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $err !== '') {
            return ['success' => false, 'username' => '', 'message' => 'Gagal hubungi Telegram: ' . $err];
        }

        $json = json_decode((string) $raw, true);
        if ($httpCode !== 200 || !is_array($json) || empty($json['ok'])) {
            $desc = is_array($json) ? ($json['description'] ?? '') : '';
            return ['success' => false, 'username' => '', 'message' => 'Token tidak valid/ditolak Telegram. ' . $desc];
        }

        $username = (string) ($json['result']['username'] ?? '');
        return ['success' => true, 'username' => $username, 'message' => 'Token valid.'];
    }
}

if (!function_exists('telegramSetWebhook')) {
    /**
     * Daftarkan URL webhook supaya pesan masuk ke bot ini (mis. "/start
     * <IDPEL>" dari pelanggan) diteruskan Telegram ke notifbot/telegram_webhook.php.
     * Dipanggil sekali saat bot ditambahkan/token diganti.
     */
    function telegramSetWebhook(string $botToken, string $webhookUrl): array
    {
        if (!function_exists('curl_init')) {
            return ['success' => false, 'message' => 'Ekstensi cURL PHP belum aktif di server.'];
        }
        $ch = curl_init(telegramApiUrl($botToken, 'setWebhook'));
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['url' => $webhookUrl]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        $json = json_decode((string) $raw, true);
        $ok = is_array($json) && !empty($json['ok']);
        return [
            'success' => $ok,
            'message' => $ok ? 'Webhook terpasang.' : ('Gagal pasang webhook: ' . ($err !== '' ? $err : ($json['description'] ?? 'unknown'))),
        ];
    }
}

if (!function_exists('sendTelegramMessage')) {
    /**
     * Kirim 1 pesan teks. $chatId = Telegram chat_id (angka, BUKAN nomor HP --
     * lihat notifbot/telegram_webhook.php utk cara pelanggan/owner
     * menghubungkan chat_id mereka via "/start").
     * Return ['sent'=>bool, 'error'=>string|null, 'http_code'=>int|null, 'response'=>string|null]
     * -- bentuk return SENGAJA disamakan dgn kirimWA() (proses/activecustomer.php)
     * supaya pola pemakaian di pemanggil konsisten dgn kanal WA.
     */
    function sendTelegramMessage(string $botToken, string $chatId, string $message): array
    {
        if (trim($botToken) === '' || trim($chatId) === '') {
            return ['sent' => false, 'error' => 'Bot token/chat_id kosong.', 'http_code' => null, 'response' => null];
        }
        if (!function_exists('curl_init')) {
            return ['sent' => false, 'error' => 'Ekstensi cURL PHP belum aktif di server.', 'http_code' => null, 'response' => null];
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
        ];

        $ch = curl_init(telegramApiUrl($botToken, 'sendMessage'));
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlError) {
            return ['sent' => false, 'error' => 'CURL Error: ' . $curlError, 'http_code' => $httpCode, 'response' => $response];
        }

        $json = json_decode((string) $response, true);
        if ($httpCode !== 200 || !is_array($json) || empty($json['ok'])) {
            $desc = is_array($json) ? ($json['description'] ?? '') : '';
            return ['sent' => false, 'error' => "HTTP Error: $httpCode. $desc", 'http_code' => $httpCode, 'response' => $response];
        }

        return ['sent' => true, 'error' => null, 'http_code' => $httpCode, 'response' => $response];
    }
}

if (!function_exists('telegramEnsurePelangganColumn')) {
    // Kolom chat_id pelanggan (hasil link via "/start <IDPEL>") -- self-heal
    // sama pola dgn kolom2 lain di codebase ini.
    function telegramEnsurePelangganColumn($conn): void
    {
        static $ensured = false;
        if ($ensured || !$conn) {
            return;
        }
        $ensured = true;
        $col = @mysqli_query($conn, "SHOW COLUMNS FROM pelanggan LIKE 'TELEGRAM_CHAT_ID'");
        if ($col && mysqli_num_rows($col) === 0) {
            @mysqli_query($conn, "ALTER TABLE pelanggan ADD COLUMN TELEGRAM_CHAT_ID VARCHAR(50) DEFAULT NULL");
        }
    }
}
