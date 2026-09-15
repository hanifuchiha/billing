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
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'url' => $webhookUrl,
            // sertakan callback_query supaya tombol sub-menu bot admin berfungsi
            'allowed_updates' => ['message', 'edited_message', 'callback_query'],
        ]));
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
    function sendTelegramMessage(string $botToken, string $chatId, string $message, ?string $parseMode = 'Markdown', ?array $replyMarkup = null): array
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
        ];
        // parse_mode hanya dikirim kalau diminta. Kirim '' / null utk teks polos
        // (aman dari error "can't parse entities" gara-gara _ * [ ` di data).
        if (is_string($parseMode) && $parseMode !== '') {
            $payload['parse_mode'] = $parseMode;
        }
        // reply_markup (inline keyboard sub-menu) -- dipakai bot admin billing
        // supaya semua filter lewat tombol/sub-menu, bukan parameter di perintah.
        if (is_array($replyMarkup) && !empty($replyMarkup)) {
            $payload['reply_markup'] = $replyMarkup;
        }

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

if (!function_exists('sendTelegramPhoto')) {
    /**
     * Kirim FOTO ke chat Telegram. $photo bisa:
     *   - path file lokal yang ada  -> di-upload (multipart)
     *   - selain itu (URL http/https atau file_id) -> dikirim apa adanya
     * $caption opsional (maks ~1024 char, dipotong). $replyMarkup opsional
     * (inline keyboard). Return sama bentuk dgn sendTelegramMessage().
     */
    function sendTelegramPhoto(string $botToken, string $chatId, string $photo, string $caption = '', ?array $replyMarkup = null): array
    {
        if (trim($botToken) === '' || trim($chatId) === '' || trim($photo) === '') {
            return ['sent' => false, 'error' => 'Bot token/chat_id/photo kosong.', 'http_code' => null, 'response' => null];
        }
        if (!function_exists('curl_init')) {
            return ['sent' => false, 'error' => 'Ekstensi cURL PHP belum aktif di server.', 'http_code' => null, 'response' => null];
        }

        $caption = mb_substr($caption, 0, 1000);
        $isLocalFile = (strpos($photo, 'http://') !== 0 && strpos($photo, 'https://') !== 0 && @is_file($photo));

        $ch = curl_init(telegramApiUrl($botToken, 'sendPhoto'));
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        if ($isLocalFile && class_exists('CURLFile')) {
            $fields = [
                'chat_id' => $chatId,
                'photo'   => new CURLFile($photo),
            ];
            if ($caption !== '') { $fields['caption'] = $caption; }
            if (is_array($replyMarkup) && !empty($replyMarkup)) { $fields['reply_markup'] = json_encode($replyMarkup); }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $fields); // multipart otomatis
        } else {
            $payload = ['chat_id' => $chatId, 'photo' => $photo];
            if ($caption !== '') { $payload['caption'] = $caption; }
            if (is_array($replyMarkup) && !empty($replyMarkup)) { $payload['reply_markup'] = $replyMarkup; }
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }

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

if (!function_exists('answerTelegramCallback')) {
    /** Wajib dipanggil utk setiap callback_query (tekan tombol inline) supaya
     *  spinner di tombol berhenti. $text opsional (toast kecil di klien). */
    function answerTelegramCallback(string $botToken, string $callbackId, string $text = ''): void
    {
        if (trim($botToken) === '' || trim($callbackId) === '' || !function_exists('curl_init')) return;
        $payload = ['callback_query_id' => $callbackId];
        if ($text !== '') $payload['text'] = mb_substr($text, 0, 190);
        $ch = curl_init(telegramApiUrl($botToken, 'answerCallbackQuery'));
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_exec($ch);
        curl_close($ch);
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
