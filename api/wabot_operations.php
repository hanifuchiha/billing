<?php
/**
 * WABot Operations API - Mobile
 * Actions: add_bot, delete_bot, edit_sender, send_test_message, get_prompt, save_prompt
 *
 * LEGACY - kept as-is and NOT deleted because the Qbilling Android app hardcodes this exact
 * filename (WABotAdminDialog.kt, WABotActionDialog.kt: ".../crm/billing/api/wabot_operations.php").
 * Its add_bot/delete_bot only do a plain INSERT/DELETE - they do not provision or tear down the
 * Docker container / MikroTik NAT rule the way crm/billing/wabot.php's web UI does.
 * New integrations (and any future Qbilling update) should use api/wabot.php (full bot CRUD with
 * real Docker/MikroTik/SSH provisioning, service control, device actions, sender, toggles,
 * operational hours, prompt template), api/wabot_integrations.php (WA Resmi/Unofficial gateway
 * CRUD), and api/wabot_settings.php (ADMIN-only global settings) instead.
 */
require_once '../config.php';
require_once '../../webhook/operational_hours_helper.php';
require_once '_bootstrap.php';
header('Content-Type: application/json');
global $conn;

function wabot_webhook_config_path(): string
{
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'webhook' . DIRECTORY_SEPARATOR . 'config.json';
}

function wabot_technical_menu_db_path(): string
{
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'webhook' . DIRECTORY_SEPARATOR . 'technical_menu_db.json';
}

function wabot_load_json_file(string $path): array
{
    if (!file_exists($path)) {
        return [];
    }
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function wabot_save_json_file(string $path, array $data): bool
{
    return file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}

$rawBody = file_get_contents('php://input');
$jsonBody = json_decode((string)$rawBody, true);
$input = is_array($jsonBody) ? array_merge($_REQUEST, $jsonBody) : $_REQUEST;

$username = trim((string)($input['username'] ?? ''));
$password = (string)($input['password'] ?? '');
$action = trim((string)($input['action'] ?? ''));

if ($username === '' || $password === '') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Username dan password diperlukan']);
    exit;
}

$stmt = $conn->prepare("SELECT USERNAME, STATUS, PASWORD FROM user WHERE USERNAME = ?");
$stmt->bind_param('s', $username);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'User tidak ditemukan']);
    exit;
}

$user = $result->fetch_assoc();
$stmt->close();

if (!password_verify($password, $user['PASWORD']) && (string)$password !== (string)$user['PASWORD']) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Password salah']);
    exit;
}

$pemilik = $user['USERNAME'];
$isAdmin = strtoupper(trim((string)$user['STATUS'])) === 'ADMIN';
api_require_module_enabled($conn, $pemilik, 'wabot');

function load_bot_for_user(mysqli $conn, int $botId, string $pemilik, bool $isAdmin): ?array {
    $stmt = $conn->prepare("SELECT id, namebot, password, addressbot, webhook, sender, pemilik FROM botwa WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $botId);
    $stmt->execute();
    $result = $stmt->get_result();
    $bot = $result->num_rows > 0 ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$bot) {
        return null;
    }

    if (!$isAdmin && (string)$bot['pemilik'] !== $pemilik) {
        return ['_forbidden' => true];
    }

    return $bot;
}

if ($action === 'add_bot') {
    $botname = trim((string)($input['botname'] ?? ''));
    $botpass = trim((string)($input['botpass'] ?? ''));
    $sender = trim((string)($input['sender'] ?? ''));
    $targetPemilik = trim((string)($input['pemilik'] ?? ''));
    if ($targetPemilik === '' || !$isAdmin) {
        $targetPemilik = $pemilik;
    }

    if ($botname === '' || $botpass === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'botname dan botpass wajib diisi']);
        exit;
    }

    if (!preg_match('/^[a-zA-Z0-9_-]{3,64}$/', $botname)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Nama bot hanya boleh huruf, angka, strip, dan underscore (3-64 karakter)']);
        exit;
    }

    $stmt = $conn->prepare("SELECT id FROM botwa WHERE namebot = ? LIMIT 1");
    $stmt->bind_param('s', $botname);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    if ($exists) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Nama bot sudah dipakai']);
        exit;
    }

    $usedPorts = [];
    $resultPorts = $conn->query("SELECT addressbot FROM botwa");
    while ($rowPort = $resultPorts->fetch_assoc()) {
        $parsed = parse_url((string)($rowPort['addressbot'] ?? ''));
        $port = isset($parsed['port']) ? (int)$parsed['port'] : 0;
        if ($port >= 3000 && $port <= 3999) {
            $usedPorts[$port] = true;
        }
    }
    $newPort = 0;
    for ($p = 3000; $p <= 3999; $p++) {
        if (!isset($usedPorts[$p])) {
            $newPort = $p;
            break;
        }
    }
    if ($newPort === 0) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Port bot sudah penuh (3000-3999)']);
        exit;
    }

    $addressbot = "http://quenbytekniksejahtera.com:" . $newPort;
    $webhook = "https://quenbytekniksejahtera.com/crm/webhook/webhook_whatsapp_" . $newPort . ".php";
    $expiredAt = date('Y-m-d H:i:s', strtotime('+30 days'));

    $stmt = $conn->prepare(
        "INSERT INTO botwa (namebot, addressbot, webhook, password, sender, pemilik, expired_at, created_at, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 1)"
    );
    $stmt->bind_param('sssssss', $botname, $addressbot, $webhook, $botpass, $sender, $targetPemilik, $expiredAt);
    if ($stmt->execute()) {
        $newId = $stmt->insert_id;
        $stmt->close();
        echo json_encode([
            'success' => true,
            'message' => 'Bot berhasil ditambahkan',
            'data' => [
                'id' => $newId,
                'namebot' => $botname,
                'addressbot' => $addressbot,
                'webhook' => $webhook,
                'sender' => $sender,
                'pemilik' => $targetPemilik,
            ],
        ]);
    } else {
        $error = $stmt->error;
        $stmt->close();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Gagal menambah bot', 'details' => $error]);
    }
    exit;
}

if ($action === 'delete_bot') {
    $botId = (int)($input['bot_id'] ?? 0);
    if ($botId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'bot_id diperlukan']);
        exit;
    }

    $bot = load_bot_for_user($conn, $botId, $pemilik, $isAdmin);
    if (!$bot) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Bot tidak ditemukan']);
        exit;
    }
    if (isset($bot['_forbidden'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Anda tidak memiliki akses ke bot ini']);
        exit;
    }

    $stmt = $conn->prepare("DELETE FROM botwa WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $botId);
    if ($stmt->execute()) {
        $stmt->close();
        echo json_encode(['success' => true, 'message' => 'Bot berhasil dihapus']);
    } else {
        $error = $stmt->error;
        $stmt->close();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Gagal menghapus bot', 'details' => $error]);
    }
    exit;
}

if ($action === 'edit_sender') {
    $botId = (int)($input['bot_id'] ?? 0);
    $sender = trim((string)($input['sender'] ?? ''));

    if ($botId <= 0 || $sender === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'bot_id dan sender diperlukan']);
        exit;
    }

    $bot = load_bot_for_user($conn, $botId, $pemilik, $isAdmin);
    if (!$bot) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Bot tidak ditemukan']);
        exit;
    }
    if (isset($bot['_forbidden'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Anda tidak memiliki akses ke bot ini']);
        exit;
    }

    $stmt = $conn->prepare("UPDATE botwa SET sender = ? WHERE id = ? LIMIT 1");
    $stmt->bind_param('si', $sender, $botId);
    if ($stmt->execute()) {
        $stmt->close();
        echo json_encode(['success' => true, 'message' => 'Sender berhasil diupdate']);
    } else {
        $error = $stmt->error;
        $stmt->close();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Gagal update sender', 'details' => $error]);
    }
    exit;
}

if ($action === 'get_prompt' || $action === 'save_prompt') {
    $botId = (int)($input['bot_id'] ?? 0);
    if ($botId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'bot_id diperlukan']);
        exit;
    }

    $bot = load_bot_for_user($conn, $botId, $pemilik, $isAdmin);
    if (!$bot) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Bot tidak ditemukan']);
        exit;
    }
    if (isset($bot['_forbidden'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Anda tidak memiliki akses ke bot ini']);
        exit;
    }

    $safeBotName = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$bot['namebot']);
    $promptPath = realpath(__DIR__ . '/../../webhook') . DIRECTORY_SEPARATOR . $safeBotName . '.txt';

    if ($action === 'get_prompt') {
        $prompt = file_exists($promptPath) ? (string)file_get_contents($promptPath) : '';
        echo json_encode(['success' => true, 'data' => ['prompt' => $prompt]]);
        exit;
    }

    $prompt = (string)($input['prompt'] ?? '');
    $result = @file_put_contents($promptPath, $prompt);
    if ($result === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Gagal menyimpan prompt']);
    } else {
        echo json_encode(['success' => true, 'message' => 'Prompt berhasil disimpan']);
    }
    exit;
}

if ($action === 'get_operational_hours' || $action === 'save_operational_hours') {
    $botName = trim((string)($input['bot_name'] ?? $input['bot_operational_name'] ?? ''));
    if ($botName === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'bot_name diperlukan']);
        exit;
    }

    if ($action === 'get_operational_hours') {
        $hours = loadOperationalHours($botName);
        echo json_encode(['success' => true, 'data' => $hours]);
        exit;
    }

    $hoursConfig = [
        'enabled' => isset($input['enabled']) ? filter_var($input['enabled'], FILTER_VALIDATE_BOOLEAN) : isset($input['operational_enabled']),
        'start_time' => trim((string)($input['start_time'] ?? $input['operational_start_time'] ?? '09:00')),
        'end_time' => trim((string)($input['end_time'] ?? $input['operational_end_time'] ?? '17:00')),
        'timezone' => trim((string)($input['timezone'] ?? $input['operational_timezone'] ?? 'Asia/Jakarta')),
        'days' => $input['days'] ?? $input['operational_days'] ?? [],
        'message_outside_hours' => trim((string)($input['message_outside_hours'] ?? $input['operational_message'] ?? '')),
        'offline_mode' => isset($input['offline_mode']) ? filter_var($input['offline_mode'], FILTER_VALIDATE_BOOLEAN) : isset($input['operational_offline_mode']),
    ];

    if (!is_array($hoursConfig['days'])) {
        $daysCsv = trim((string)($input['operational_days_csv'] ?? ''));
        $hoursConfig['days'] = $daysCsv !== '' ? array_values(array_filter(array_map('trim', explode(',', $daysCsv)))) : [];
    }

    if (saveOperationalHours($botName, $hoursConfig)) {
        echo json_encode(['success' => true, 'message' => 'Jam operasional berhasil disimpan']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $GLOBALS['lastOpHoursError'] ?: 'Gagal menyimpan jam operasional']);
    }
    exit;
}

if ($action === 'get_database_settings' || $action === 'save_database_settings') {
    $configPath = wabot_webhook_config_path();
    $config = wabot_load_json_file($configPath);

    if ($action === 'get_database_settings') {
        echo json_encode([
            'success' => true,
            'data' => [
                'db_host' => $config['db_host'] ?? 'localhost',
                'db_user' => $config['db_user'] ?? '',
                'db_pass' => $config['db_pass'] ?? '',
                'db_name' => $config['db_name'] ?? 'absensi',
                'db_billing' => $config['db_billing'] ?? 'Mybillingq',
            ],
        ]);
        exit;
    }

    $dbHost = trim((string)($input['db_host'] ?? ''));
    $dbUser = trim((string)($input['db_user'] ?? ''));
    $dbPass = (string)($input['db_pass'] ?? '');
    $dbName = trim((string)($input['db_name'] ?? ''));
    $dbBilling = trim((string)($input['db_billing'] ?? ''));

    if ($dbHost === '' || $dbUser === '' || $dbName === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Host, user, dan database name wajib diisi']);
        exit;
    }

    $testConn = @new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    if ($testConn->connect_error) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Gagal koneksi database: ' . $testConn->connect_error]);
        exit;
    }
    $testConn->close();

    $config['db_host'] = $dbHost;
    $config['db_user'] = $dbUser;
    $config['db_pass'] = $dbPass;
    $config['db_name'] = $dbName;
    $config['db_billing'] = $dbBilling;

    if (wabot_save_json_file($configPath, $config)) {
        echo json_encode(['success' => true, 'message' => 'Database settings berhasil disimpan']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Gagal menyimpan config file']);
    }
    exit;
}

if ($action === 'get_function_settings' || $action === 'save_function_settings') {
    $configPath = wabot_webhook_config_path();
    $config = wabot_load_json_file($configPath);
    $functions = isset($config['functions']) && is_array($config['functions']) ? $config['functions'] : [];

    if ($action === 'get_function_settings') {
        $list = [];
        foreach ($functions as $name => $meta) {
            $list[] = [
                'function_name' => (string)$name,
                'function_desc' => (string)($meta['description'] ?? ''),
                'function_file' => (string)($meta['file'] ?? ''),
                'function_enabled' => isset($meta['enabled']) ? (bool)$meta['enabled'] : true,
                'created_at' => (string)($meta['created_at'] ?? ''),
                'updated_at' => (string)($meta['updated_at'] ?? ''),
            ];
        }
        echo json_encode(['success' => true, 'data' => $list]);
        exit;
    }

    $functionName = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($input['function_name'] ?? ''));
    $functionDesc = trim((string)($input['function_desc'] ?? ''));
    $functionFile = trim((string)($input['function_file'] ?? ''));
    $functionEnabled = isset($input['function_enabled']) ? filter_var($input['function_enabled'], FILTER_VALIDATE_BOOLEAN) : true;

    if ($functionName === '' || $functionFile === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Nama function dan file path wajib diisi']);
        exit;
    }

    $config['functions'] = $functions;
    $config['functions'][$functionName] = [
        'description' => $functionDesc,
        'file' => $functionFile,
        'enabled' => $functionEnabled,
        'created_at' => $config['functions'][$functionName]['created_at'] ?? date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ];

    if (wabot_save_json_file($configPath, $config)) {
        echo json_encode(['success' => true, 'message' => 'Function settings berhasil disimpan']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Gagal menyimpan config file']);
    }
    exit;
}

if ($action === 'get_technical_menu_db' || $action === 'save_technical_menu_db') {
    $path = wabot_technical_menu_db_path();
    $config = wabot_load_json_file($path);

    if ($action === 'get_technical_menu_db') {
        echo json_encode([
            'success' => true,
            'data' => [
                'tech_db_host' => $config['host'] ?? 'localhost',
                'tech_db_user' => $config['user'] ?? 'qts',
                'tech_db_pass' => $config['pass'] ?? '',
                'tech_db_name' => $config['name'] ?? 'absensi',
            ],
        ]);
        exit;
    }

    $techDbHost = trim((string)($input['tech_db_host'] ?? ''));
    $techDbUser = trim((string)($input['tech_db_user'] ?? ''));
    $techDbPass = (string)($input['tech_db_pass'] ?? '');
    $techDbName = trim((string)($input['tech_db_name'] ?? ''));

    if ($techDbHost === '' || $techDbUser === '' || $techDbName === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Host, user, dan database name wajib diisi']);
        exit;
    }

    $testConn = @new mysqli($techDbHost, $techDbUser, $techDbPass, $techDbName);
    if ($testConn->connect_error) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Gagal koneksi database: ' . $testConn->connect_error]);
        exit;
    }
    $testConn->close();

    $save = [
        'host' => $techDbHost,
        'user' => $techDbUser,
        'pass' => $techDbPass,
        'name' => $techDbName,
    ];

    if (wabot_save_json_file($path, $save)) {
        echo json_encode(['success' => true, 'message' => 'Technical menu DB berhasil disimpan']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Gagal menyimpan technical menu DB']);
    }
    exit;
}

if ($action === 'get_port_range_settings' || $action === 'save_port_range_settings') {
    $configPath = wabot_webhook_config_path();
    $config = wabot_load_json_file($configPath);

    if ($action === 'get_port_range_settings') {
        echo json_encode([
            'success' => true,
            'data' => [
                'port_start_bot' => (int)($config['port_start_bot'] ?? 3000),
                'port_end_bot' => (int)($config['port_end_bot'] ?? 3999),
            ],
        ]);
        exit;
    }

    $portStart = (int)($input['port_start_bot'] ?? 3000);
    $portEnd = (int)($input['port_end_bot'] ?? 3999);

    if ($portStart < 3000 || $portStart > 3999 || $portEnd < 3000 || $portEnd > 3999 || $portStart > $portEnd) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Port range tidak valid']);
        exit;
    }

    $config['port_start_bot'] = $portStart;
    $config['port_end_bot'] = $portEnd;

    if (wabot_save_json_file($configPath, $config)) {
        echo json_encode(['success' => true, 'message' => 'Port range bot berhasil disimpan']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Gagal menyimpan config file']);
    }
    exit;
}

if ($action === 'save_technical_menu_settings') {
    $botId = (int)($input['bot_id'] ?? $input['bot_technical_menu_id'] ?? 0);
    if ($botId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'bot_id diperlukan']);
        exit;
    }

    $bot = load_bot_for_user($conn, $botId, $pemilik, $isAdmin);
    if (!$bot) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Bot tidak ditemukan']);
        exit;
    }
    if (isset($bot['_forbidden'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Anda tidak memiliki akses ke bot ini']);
        exit;
    }

    $technicalMenuEnabled = isset($input['technical_menu_enabled']) ? (int)$input['technical_menu_enabled'] : 0;
    $allowReadServer = isset($input['allow_read_server']) ? (int)$input['allow_read_server'] : 1;
    $allowReadCustomer = isset($input['allow_read_customer']) ? (int)$input['allow_read_customer'] : 1;
    $allowCreatePaymentCode = isset($input['allow_create_payment_code']) ? (int)$input['allow_create_payment_code'] : 1;

    $stmt = $conn->prepare("UPDATE botwa SET technical_menu_enabled = ?, allow_read_server = ?, allow_read_customer = ?, allow_create_payment_code = ? WHERE id = ? LIMIT 1");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Prepare gagal']);
        exit;
    }
    $stmt->bind_param('iiiii', $technicalMenuEnabled, $allowReadServer, $allowReadCustomer, $allowCreatePaymentCode, $botId);
    if ($stmt->execute()) {
        $stmt->close();
        echo json_encode(['success' => true, 'message' => 'Technical menu settings berhasil disimpan']);
    } else {
        $error = $stmt->error;
        $stmt->close();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Gagal menyimpan technical menu settings', 'details' => $error]);
    }
    exit;
}

if ($action === 'send_test_message') {
    $botId = (int)($input['bot_id'] ?? 0);
    $phone = preg_replace('/[^0-9]/', '', (string)($input['phone'] ?? ''));
    $message = trim((string)($input['message'] ?? ''));

    if ($botId <= 0 || $phone === '' || $message === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'bot_id, phone, dan message diperlukan']);
        exit;
    }

    if (!preg_match('/^62\d{8,15}$/', $phone)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Format nomor telepon tidak valid (harus 62...)']);
        exit;
    }

    $bot = load_bot_for_user($conn, $botId, $pemilik, $isAdmin);
    if (!$bot) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Bot tidak ditemukan']);
        exit;
    }
    if (isset($bot['_forbidden'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Anda tidak memiliki akses ke bot ini']);
        exit;
    }

    if (empty($bot['addressbot'])) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Address bot tidak konfigurasi']);
        exit;
    }

    if (!function_exists('curl_init')) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'cURL tidak tersedia di server']);
        exit;
    }

    $addressBot = rtrim((string)$bot['addressbot'], '/');
    // device_id gowa multi-device (build gowa terbaru wajib X-Device-Id header /
    // device_id query di /send/message) = isi kolom sender apa adanya.
    $deviceId = trim((string)($bot['sender'] ?? ''));
    $sendUrl = $addressBot . '/send/message?session=' . urlencode((string)$bot['namebot']);
    if ($deviceId !== '') {
        $sendUrl .= '&device_id=' . urlencode($deviceId);
    }
    $payload = [
        'phone' => $phone,
        'message' => $message,
        'sender' => (string)($bot['sender'] ?? ''),
    ];

    $sendHeaders = [
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    if ($deviceId !== '') {
        $sendHeaders[] = "X-Device-Id: $deviceId";
    }

    $ch = curl_init($sendUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => $sendHeaders,
        CURLOPT_USERPWD => (string)$bot['namebot'] . ':' . (string)$bot['password'],
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        http_response_code(502);
        echo json_encode([
            'success' => false,
            'error' => 'Gagal menghubungi bot server',
            'details' => $curlError,
        ]);
        exit;
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        echo json_encode([
            'success' => true,
            'message' => 'Pesan test berhasil dikirim',
            'http_code' => $httpCode,
        ]);
    } else {
        http_response_code($httpCode < 500 ? 400 : 502);
        echo json_encode([
            'success' => false,
            'error' => 'Bot menolak request',
            'http_code' => $httpCode,
            'response' => $response,
        ]);
    }
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Action tidak dikenali']);
