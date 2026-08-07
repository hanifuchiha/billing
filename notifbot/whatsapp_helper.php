<?php
/**
 * WHATSAPP CALLBACK HELPER FUNCTIONS
 * Helper untuk send WhatsApp dengan error handling dan logging
 * 
 * Usage di callback files:
 * $result = sendWhatsappMessage($phone, $message, $botname, $waapi, $botpass, $history, $history_file);
 * if ($result['success']) {
 *    // Handle success
 * } else {
 *    // Handle error
 * }
 */

/**
 * Send WhatsApp message dengan complete error handling
 * 
 * @param string $phone Nomor WhatsApp (10 digit atau dengan @s.whatsapp.net)
 * @param string $message Pesan yang akan dikirim
 * @param string $botname Nama bot/session
 * @param string $waapi URL WhatsApp API endpoint
 * @param string $botpass Password/token untuk authentikasi
 * @param array $history Reference ke array history (untuk logging)
 * @param string $history_file Path ke file history JSON
 * @param array $options Opsi tambahan (timeout, etc)
 * 
 * @return array [
 *     'success' => bool,
 *     'http_code' => int,
 *     'response' => string,
 *     'error' => string|null,
 *     'curl_error' => string|null,
 *     'details' => string (info log)
 * ]
 */
function sendWhatsappMessage(
    $phone,
    $message,
    $botname,
    $waapi,
    $botpass,
    &$history = [],
    $history_file = null,
    $options = []
) {
    $result = [
        'success' => false,
        'http_code' => 0,
        'response' => '',
        'error' => null,
        'curl_error' => null,
        'details' => '',
        'phone' => $phone,
        'message_length' => strlen($message)
    ];

    // ===== VALIDASI INPUT =====
    if (empty($botname)) {
        $result['error'] = 'Bot name (session) is empty';
        logWhatsappError($result, $history, $history_file, "Missing botname");
        return $result;
    }

    if (empty($waapi)) {
        $result['error'] = 'WhatsApp API endpoint is empty';
        logWhatsappError($result, $history, $history_file, "Missing waapi");
        return $result;
    }

    if (empty($botpass)) {
        $result['error'] = 'Bot password/token is empty';
        logWhatsappError($result, $history, $history_file, "Missing botpass");
        return $result;
    }

    if (empty($phone)) {
        $result['error'] = 'Phone number is empty';
        logWhatsappError($result, $history, $history_file, "Missing phone");
        return $result;
    }

    if (empty($message)) {
        $result['error'] = 'Message is empty';
        logWhatsappError($result, $history, $history_file, "Empty message");
        return $result;
    }

    // ===== FORMAT PHONE =====
    $phone = formatWhatsappPhone($phone);
    if (!$phone) {
        $result['error'] = 'Invalid phone number format';
        logWhatsappError($result, $history, $history_file, "Invalid phone format");
        return $result;
    }
    $result['phone'] = $phone;

    // ===== SIAPKAN cURL =====
    $timeout = $options['timeout'] ?? 10;
    // Ambil sender jika ada dari tabel botwa
    $sender = '';
    if (!empty($botname) && !empty($waapi) && !empty($botpass)) {
        $conn = $GLOBALS['conn'] ?? null;
        if ($conn) {
            $sqlSender = "SELECT sender FROM botwa WHERE namebot = '" . mysqli_real_escape_string($conn, $botname) . "' LIMIT 1";
            $querySender = mysqli_query($conn, $sqlSender);
            if ($querySender && $rowSender = mysqli_fetch_assoc($querySender)) {
                $sender = $rowSender['sender'];
            }
        }
    }
    $data = [
        "phone" => $phone,
        "message" => $message,
        "sender" => $sender
    ];

    // device_id gowa multi-device (build gowa terbaru wajib X-Device-Id
    // header / device_id query di /send/message) = isi kolom sender apa
    // adanya (nama device di server gowa, mis. "hanif").
    $deviceId = trim((string)$sender);

    $url = "$waapi/send/message?session=$botname";
    if ($deviceId !== '') {
        $url .= '&device_id=' . urlencode($deviceId);
    }
    $headers = [
        "Content-Type: application/json",
        "User-Agent: CallbackWhatsappSender/1.0"
    ];
    if ($deviceId !== '') {
        $headers[] = "X-Device-Id: $deviceId";
    }

    try {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_USERPWD, "$botname:$botpass");
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

        $result['response'] = curl_exec($ch);
        $result['http_code'] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $result['curl_error'] = curl_error($ch);
        curl_close($ch);

        // ===== VALIDASI RESPONSE =====
        if ($result['curl_error']) {
            $result['error'] = "cURL Error: " . $result['curl_error'];
            logWhatsappError($result, $history, $history_file, "cURL Error: " . $result['curl_error']);
            return $result;
        }

        if ($result['http_code'] !== 200) {
            $result['error'] = "HTTP Error: " . $result['http_code'];
            $result['details'] = substr($result['response'], 0, 200);
            logWhatsappError($result, $history, $history_file, 
                "HTTP " . $result['http_code'] . " - " . substr($result['response'], 0, 100));
            return $result;
        }

        // ===== SUCCESS =====
        $result['success'] = true;
        $result['details'] = "Message sent successfully to $phone";
        logWhatsappSuccess($result, $history, $history_file, "Sent to $phone");
        return $result;

    } catch (Exception $e) {
        $result['error'] = "Exception: " . $e->getMessage();
        logWhatsappError($result, $history, $history_file, "Exception: " . $e->getMessage());
        return $result;
    }
}

/**
 * Format dan validasi nomor WhatsApp
 * 
 * @param string $phone Nomor dengan format apapun
 * @return string|false Nomor dengan format @s.whatsapp.net atau false jika invalid
 */
function formatWhatsappPhone($phone)
{
    if (empty($phone)) {
        return false;
    }

    // Hapus karakter non-digit kecuali @
    if (strpos($phone, '@') !== false) {
        // Sudah format full
        if (strpos($phone, '@s.whatsapp.net') !== false) {
            return $phone;
        }
        // Extract nomor dari format @s.whatsapp.net
        $phone = explode('@', $phone)[0];
    }

    // Extract hanya digit
    $phone = preg_replace('/[^0-9]/', '', $phone);

    if (empty($phone) || strlen($phone) < 10) {
        return false;
    }

    // Jika mulai dengan 0, ganti dengan 62
    if (substr($phone, 0, 1) === '0') {
        $phone = '62' . substr($phone, 1);
    }

    // Jika belum punya prefix 62, tambahkan
    if (substr($phone, 0, 2) !== '62') {
        // Assume Indonesia
        $phone = '62' . $phone;
    }

    return $phone . '@s.whatsapp.net';
}

/**
 * Log WhatsApp success
 */
function logWhatsappSuccess(&$result, &$history = [], $history_file = null, $extraInfo = '')
{
    $log = "[ SUCCESS WhatsApp ] " . date('Y-m-d H:i:s') . " | To: " . ($result['phone'] ?? 'N/A');
    if ($extraInfo) {
        $log .= " | " . $extraInfo;
    }
    $log .= " | HTTP: " . $result['http_code'];

    if (is_array($history)) {
        $history[] = $log;
    }

    if ($history_file && is_array($history)) {
        saveHistory($history, $history_file);
    }
}

/**
 * Log WhatsApp error
 */
function logWhatsappError(&$result, &$history = [], $history_file = null, $extraInfo = '')
{
    $log = "[ ERROR WhatsApp ] " . date('Y-m-d H:i:s') . " | To: " . ($result['phone'] ?? 'UNKNOWN');
    if ($result['error']) {
        $log .= " | Error: " . $result['error'];
    }
    if ($extraInfo) {
        $log .= " | " . $extraInfo;
    }
    if ($result['http_code']) {
        $log .= " | HTTP: " . $result['http_code'];
    }

    if (is_array($history)) {
        $history[] = $log;
    }

    if ($history_file && is_array($history)) {
        saveHistory($history, $history_file);
    }
}

/**
 * Save history ke JSON file
 */
function saveHistory(&$history, $history_file)
{
    if (!is_array($history)) {
        $history = [];
    }

    try {
        $dir = dirname($history_file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    } catch (Exception $e) {
        // Silent fail untuk write history
        error_log("Failed to save history: " . $e->getMessage());
    }
}

/**
 * Validasi bot configuration dari database
 * 
 * @param mysqli $conn Database connection
 * @param string $botname Nama bot
 * 
 * @return array [
 *     'valid' => bool,
 *     'waapi' => string,
 *     'botpass' => string,
 *     'error' => string|null
 * ]
 */
function validateBotConfig($conn, $botname)
{
    $result = [
        'valid' => false,
        'waapi' => null,
        'botpass' => null,
        'error' => null
    ];

    if (empty($botname)) {
        $result['error'] = 'Bot name is empty';
        return $result;
    }

    $sql = "SELECT * FROM `botwa` WHERE `namebot` = '" . $conn->real_escape_string($botname) . "'";
    $query = $conn->query($sql);

    if (!$query) {
        $result['error'] = "Database query failed: " . $conn->error;
        return $result;
    }

    if ($query->num_rows === 0) {
        $result['error'] = "Bot '$botname' not found in database";
        return $result;
    }

    $bot = $query->fetch_array();

    if (empty($bot['addressbot']) || empty($bot['password'])) {
        $result['error'] = "Bot configuration incomplete (missing addressbot or password)";
        return $result;
    }

    $result['valid'] = true;
    $result['waapi'] = $bot['addressbot'];
    $result['botpass'] = $bot['password'];

    return $result;
}

/**
 * Wrapper complete untuk send WhatsApp dengan validation penuh
 * 
 * @param array $config Config array dengan keys: phone, message, botname, waapi, botpass, history_file
 * @param mysqli $conn Optional database connection untuk validate bot config
 * 
 * @return array Result dari sendWhatsappMessage()
 */
function sendWhatsappWithValidation($config, $conn = null)
{
    $history = [];
    $history_file = $config['history_file'] ?? null;

    // Validate required fields
    $required = ['phone', 'message', 'botname', 'waapi', 'botpass'];
    foreach ($required as $field) {
        if (empty($config[$field])) {
            $error = "Missing required config: $field";
            logWhatsappError(['phone' => $config['phone'] ?? 'UNKNOWN'], $history, $history_file, $error);
            return [
                'success' => false,
                'error' => $error,
                'http_code' => 0,
                'response' => '',
                'curl_error' => null,
                'details' => ''
            ];
        }
    }

    // Optional: Validate bot config dari database
    if ($conn && !empty($config['botname'])) {
        $validation = validateBotConfig($conn, $config['botname']);
        if (!$validation['valid']) {
            logWhatsappError(['phone' => $config['phone']], $history, $history_file, "Bot validation failed: " . $validation['error']);
            return [
                'success' => false,
                'error' => $validation['error'],
                'http_code' => 0,
                'response' => '',
                'curl_error' => null,
                'details' => ''
            ];
        }
    }

    return sendWhatsappMessage(
        $config['phone'],
        $config['message'],
        $config['botname'],
        $config['waapi'],
        $config['botpass'],
        $history,
        $history_file,
        $config['options'] ?? []
    );
}

?>
