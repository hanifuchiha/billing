<?php
require '../cek-sesi.php';
require_once __DIR__ . '/../notifbot/mailer_helper.php';

@set_time_limit(0);
@ini_set('max_execution_time', '0');
ignore_user_abort(true);

function reqParam($key, $default = '')
{
    if (isset($_POST[$key])) {
        return trim((string)$_POST[$key]);
    }
    if (isset($_GET[$key])) {
        return trim((string)$_GET[$key]);
    }
    return trim((string)$default);
}

function out($data, $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

function startStreamOutput()
{
    @ini_set('output_buffering', 'off');
    @ini_set('zlib.output_compression', '0');
    @ini_set('implicit_flush', '1');

    while (ob_get_level() > 0) {
        @ob_end_flush();
    }

    @ob_implicit_flush(true);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('X-Accel-Buffering: no');
}

function outStream($event, $payload = [])
{
    echo strtoupper($event) . ':' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n";
    @ob_flush();
    flush();
}

function registerStreamFatalHandler()
{
    register_shutdown_function(function () {
        $error = error_get_last();
        if (!$error) {
            return;
        }

        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!in_array((int)$error['type'], $fatalTypes, true)) {
            return;
        }

        if (http_response_code() < 400) {
            http_response_code(500);
        }

        outStream('ERROR', [
            'status' => 'error',
            'message' => 'Fatal error: ' . (isset($error['message']) ? $error['message'] : 'Unknown error'),
            'file' => basename((string)(isset($error['file']) ? $error['file'] : '')),
            'line' => (int)(isset($error['line']) ? $error['line'] : 0),
        ]);
    });
}

function sleepAman($min = 2, $max = 5)
{
    $delayMs = rand($min * 1000, $max * 1000);
    usleep($delayMs * 1000);
}

function applySafeBroadcastDelay($index)
{
    sleepAman(2, 5);
    if ($index > 0 && $index % 15 === 0) {
        usleep(rand(3000, 8000) * 1000);
    }
    if ($index > 0 && $index % 50 === 0) {
        usleep(rand(8000, 15000) * 1000);
    }
}

function buildNaturalVariant($baseMessage, $idpel, $nama)
{
    $safeNama = trim($nama) !== '' ? trim($nama) : 'Pelanggan';
    $variantSeed = crc32($idpel . '|' . $safeNama . '|' . date('Y-m-d'));

    $openers = [
        'Halo Bapak/Ibu ' . $safeNama . ',',
        'Selamat hari ini Bapak/Ibu ' . $safeNama . ',',
        'Salam hangat untuk Bapak/Ibu ' . $safeNama . ',',
        'Yth. Bapak/Ibu ' . $safeNama . ',',
    ];

    $closers = [
        'Terima kasih atas perhatian Anda.',
        'Terima kasih atas kerja sama Anda.',
        'Mohon abaikan jika sudah ditindaklanjuti.',
        'Apabila ada pertanyaan, silakan hubungi CS kami.',
    ];

    $opener = $openers[$variantSeed % count($openers)];
    $closer = $closers[$variantSeed % count($closers)];

    return $opener . "\n\n" . trim($baseMessage) . "\n\n" . $closer;
}

/**
 * Bangun daftar bot (pool) berdasarkan nilai botname yang dikirim dari form checkbox:
 * - '' / 'RANDOM' / '__RANDOM__'  -> pool = SEMUA bot milik pemilik (acak)
 * - "BotA,BotB,..."               -> pool = HANYA bot yang disebut (acak di antara itu)
 * - "BotA"                        -> pool = bot itu saja (fix, tidak random)
 */
function resolveBotPool($conn, $ceknama, $botnameRaw)
{
    $botnameRaw = trim((string)$botnameRaw);
    $upper = strtoupper($botnameRaw);
    $isRandomAll = ($botnameRaw === '' || $upper === '__RANDOM__' || $upper === 'RANDOM');

    $pool = [];

    if ($isRandomAll) {
        $stmt = $conn->prepare("SELECT namebot, addressbot, password, sender FROM botwa WHERE pemilik = ?");
        if (!$stmt) {
            return ['pool' => [], 'error' => mysqli_error($conn)];
        }
        $stmt->bind_param('s', $ceknama);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $name = trim((string)($row['namebot'] ?? ''));
            $addr = trim((string)($row['addressbot'] ?? ''));
            $pass = trim((string)($row['password'] ?? ''));
            $sender = trim((string)($row['sender'] ?? ''));
            if ($name !== '' && $addr !== '' && $pass !== '') {
                $pool[] = ['namebot' => $name, 'addressbot' => $addr, 'password' => $pass, 'sender' => $sender];
            }
        }
        $stmt->close();
    } elseif (strpos($botnameRaw, ',') !== false) {
        $names = array_values(array_unique(array_filter(array_map('trim', explode(',', $botnameRaw)), function ($v) {
            return $v !== '';
        })));

        if (empty($names)) {
            return resolveBotPool($conn, $ceknama, 'RANDOM');
        }

        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $sql = "SELECT namebot, addressbot, password, sender FROM botwa WHERE pemilik = ? AND namebot IN ($placeholders)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return ['pool' => [], 'error' => mysqli_error($conn)];
        }

        $types = str_repeat('s', count($names) + 1);
        $params = array_merge([$ceknama], $names);

        $refs = [];
        $refs[] = &$types;
        foreach ($params as $key => $value) {
            $refs[] = &$params[$key];
        }
        call_user_func_array([$stmt, 'bind_param'], $refs);

        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $name = trim((string)($row['namebot'] ?? ''));
            $addr = trim((string)($row['addressbot'] ?? ''));
            $pass = trim((string)($row['password'] ?? ''));
            $sender = trim((string)($row['sender'] ?? ''));
            if ($name !== '' && $addr !== '' && $pass !== '') {
                $pool[] = ['namebot' => $name, 'addressbot' => $addr, 'password' => $pass, 'sender' => $sender];
            }
        }
        $stmt->close();
    } else {
        $stmt = $conn->prepare("SELECT namebot, addressbot, password, sender FROM botwa WHERE namebot = ? AND pemilik = ? LIMIT 1");
        if (!$stmt) {
            return ['pool' => [], 'error' => mysqli_error($conn)];
        }
        $stmt->bind_param('ss', $botnameRaw, $ceknama);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if ($row) {
            $name = trim((string)($row['namebot'] ?? $botnameRaw));
            $addr = trim((string)($row['addressbot'] ?? ''));
            $pass = trim((string)($row['password'] ?? ''));
            $sender = trim((string)($row['sender'] ?? ''));
            if ($name !== '' && $addr !== '' && $pass !== '') {
                $pool[] = ['namebot' => $name, 'addressbot' => $addr, 'password' => $pass, 'sender' => $sender];
            }
        }
    }

    if (count($pool) > 1) {
        shuffle($pool);
    }

    return ['pool' => $pool, 'error' => null];
}

/**
 * Versi Telegram dari resolveBotPool() di atas -- pola identik, tabel bottelegram,
 * kolom bottoken menggantikan addressbot/password/sender (Telegram tidak butuh itu).
 */
function resolveTelegramBotPool($conn, $ceknama, $botnameRaw)
{
    $botnameRaw = trim((string)$botnameRaw);
    $upper = strtoupper($botnameRaw);
    $isRandomAll = ($botnameRaw === '' || $upper === '__RANDOM__' || $upper === 'RANDOM');

    $pool = [];

    if ($isRandomAll) {
        $stmt = $conn->prepare("SELECT namebot, bottoken FROM bottelegram WHERE pemilik = ?");
        if (!$stmt) {
            return ['pool' => [], 'error' => mysqli_error($conn)];
        }
        $stmt->bind_param('s', $ceknama);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $name = trim((string)($row['namebot'] ?? ''));
            $token = trim((string)($row['bottoken'] ?? ''));
            if ($name !== '' && $token !== '') {
                $pool[] = ['namebot' => $name, 'bottoken' => $token];
            }
        }
        $stmt->close();
    } elseif (strpos($botnameRaw, ',') !== false) {
        $names = array_values(array_unique(array_filter(array_map('trim', explode(',', $botnameRaw)), function ($v) {
            return $v !== '';
        })));

        if (empty($names)) {
            return resolveTelegramBotPool($conn, $ceknama, 'RANDOM');
        }

        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $sql = "SELECT namebot, bottoken FROM bottelegram WHERE pemilik = ? AND namebot IN ($placeholders)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return ['pool' => [], 'error' => mysqli_error($conn)];
        }

        $types = str_repeat('s', count($names) + 1);
        $params = array_merge([$ceknama], $names);

        $refs = [];
        $refs[] = &$types;
        foreach ($params as $key => $value) {
            $refs[] = &$params[$key];
        }
        call_user_func_array([$stmt, 'bind_param'], $refs);

        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $name = trim((string)($row['namebot'] ?? ''));
            $token = trim((string)($row['bottoken'] ?? ''));
            if ($name !== '' && $token !== '') {
                $pool[] = ['namebot' => $name, 'bottoken' => $token];
            }
        }
        $stmt->close();
    } else {
        $stmt = $conn->prepare("SELECT namebot, bottoken FROM bottelegram WHERE namebot = ? AND pemilik = ? LIMIT 1");
        if (!$stmt) {
            return ['pool' => [], 'error' => mysqli_error($conn)];
        }
        $stmt->bind_param('ss', $botnameRaw, $ceknama);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if ($row) {
            $name = trim((string)($row['namebot'] ?? $botnameRaw));
            $token = trim((string)($row['bottoken'] ?? ''));
            if ($name !== '' && $token !== '') {
                $pool[] = ['namebot' => $name, 'bottoken' => $token];
            }
        }
    }

    if (count($pool) > 1) {
        shuffle($pool);
    }

    return ['pool' => $pool, 'error' => null];
}

$botnameRaw = reqParam('botname');
$botnameUpper = strtoupper($botnameRaw);
$isRandomBot = ($botnameRaw === '' || $botnameUpper === '__RANDOM__' || $botnameUpper === 'RANDOM' || strpos($botnameRaw, ',') !== false);
// Kanal Telegram opt-in -- kosong berarti operator tidak centang bot Telegram sama sekali.
$botnameTelegramRaw = reqParam('botname_telegram');
// Kanal Email juga opt-in -- '1' kalau operator centang "Kirim juga via Email".
$sendEmail = (reqParam('send_email') === '1');
$server = reqParam('server');
$area = reqParam('area');
$odp = reqParam('odp');
$pesan = reqParam('pesan');
$streamMode = in_array(strtolower(reqParam('stream')), ['1', 'true', 'yes'], true);

if ($streamMode) {
    startStreamOutput();
    registerStreamFatalHandler();
}

$abortRequest = function ($message, $status = 400, $extra = []) use ($streamMode) {
    $payload = array_merge(['status' => 'error', 'message' => $message], $extra);
    if ($streamMode) {
        http_response_code($status);
        outStream('ERROR', $payload);
        exit;
    }
    out($payload, $status);
};

if ($server === '' || $area === '' || $odp === '' || $pesan === '') {
    $abortRequest('Parameter wajib belum lengkap (server/area/odp/pesan).', 400);
}

// Siapkan pool bot berdasarkan pilihan checkbox (RANDOM semua / multi dicentang / satu bot fix)
$botResolved = resolveBotPool($conn, $ceknama, $botnameRaw);
if ($botResolved['error'] !== null) {
    $abortRequest('Gagal prepare query botwa.', 500, ['db_error' => $botResolved['error']]);
}
$botPool = $botResolved['pool'];

if (count($botPool) === 0) {
    $abortRequest('Bot tidak ditemukan di tabel botwa.', 404, ['botname' => $botnameRaw, 'is_random' => $isRandomBot]);
}

$botPoolCount = count($botPool);
$primaryBotName = (string)$botPool[0]['namebot'];
$primaryWaapi = (string)$botPool[0]['addressbot'];

// Kanal Telegram: opsional, cuma diresolve kalau operator centang bot Telegram.
// Gagal resolve TIDAK menggagalkan broadcast WA -- Telegram-nya saja yang di-skip.
$telegramBotPool = [];
if ($botnameTelegramRaw !== '') {
    $telegramResolved = resolveTelegramBotPool($conn, $ceknama, $botnameTelegramRaw);
    if ($telegramResolved['error'] === null) {
        $telegramBotPool = $telegramResolved['pool'];
    }
}
$telegramBotPoolCount = count($telegramBotPool);

if (!function_exists('curl_init')) {
    $abortRequest('Ekstensi cURL PHP belum aktif di server.', 500);
}

if (strtoupper($odp) === 'SEMUA') {
    $stmtTarget = $conn->prepare("SELECT IDPEL, NAMA, NOWA, EMAIL, TELEGRAM_CHAT_ID FROM pelanggan WHERE PEMILIK = ? AND AREA = ?");
    if ($stmtTarget) {
        $stmtTarget->bind_param('ss', $server, $area);
    }
} else {
    $stmtTarget = $conn->prepare("SELECT IDPEL, NAMA, NOWA, EMAIL, TELEGRAM_CHAT_ID FROM pelanggan WHERE PEMILIK = ? AND AREA = ? AND ODP = ?");
    if ($stmtTarget) {
        $stmtTarget->bind_param('sss', $server, $area, $odp);
    }
}

if (!$stmtTarget) {
    $abortRequest('Gagal prepare query pelanggan.', 500, ['db_error' => mysqli_error($conn)]);
}

$stmtTarget->execute();
$resultTarget = $stmtTarget->get_result();
$targets = [];
while ($row = $resultTarget->fetch_assoc()) {
    $targets[] = $row;
}
$stmtTarget->close();

if (count($targets) === 0) {
    if ($streamMode) {
        outStream('DONE', [
            'status' => 'ok',
            'message' => 'Tidak ada target pelanggan sesuai filter.',
            'summary' => [
                'total_target' => 0,
                'success_count' => 0,
                'failed_count' => 0,
            ],
        ]);
        exit;
    }
    out([
        'status' => 'ok',
        'message' => 'Tidak ada target pelanggan sesuai filter.',
        'summary' => [
            'total_target' => 0,
            'success_count' => 0,
            'failed_count' => 0,
        ],
        'rows' => [],
    ]);
}

$rows = [];
$successCount = 0;
$failedCount = 0;
$botUsage = [];
$telegramSuccessCount = 0;
$telegramFailedCount = 0;
$telegramBotUsage = [];
$emailSuccessCount = 0;
$emailFailedCount = 0;
$totalTarget = count($targets);

if ($totalTarget > 1) {
    shuffle($targets);
}

if ($streamMode) {
    outStream('START', [
        'status' => 'ok',
        'message' => 'Proses kirim manual dimulai.',
        'botname' => $isRandomBot ? '__RANDOM__' : $primaryBotName,
        'bot_pool_count' => $botPoolCount,
        'total_target' => $totalTarget,
    ]);
}

foreach ($targets as $index => $target) {
    $botConfig = $botPool[$index % $botPoolCount];
    $currentBotName = (string)$botConfig['namebot'];
    $currentWaapi = (string)$botConfig['addressbot'];
    $currentBotPass = (string)$botConfig['password'];
    $currentSender = (string)($botConfig['sender'] ?? '');
    if (!isset($botUsage[$currentBotName])) {
        $botUsage[$currentBotName] = 0;
    }

    $idpelCur = (string)(isset($target['IDPEL']) ? $target['IDPEL'] : '');
    $namaCur = (string)(isset($target['NAMA']) ? $target['NAMA'] : '');
    $textFinal = buildNaturalVariant($pesan, $idpelCur, $namaCur);

    // Kanal Telegram: dicoba TERLEPAS dari status NOWA -- pelanggan bisa saja
    // cuma link Telegram, tanpa WA valid.
    $telegramChatId = (string)(isset($target['TELEGRAM_CHAT_ID']) ? $target['TELEGRAM_CHAT_ID'] : '');
    $telegramAttempted = false;
    $telegramOk = false;
    $telegramError = '';
    $telegramBotNameUsed = '';
    if ($telegramBotPoolCount > 0 && $telegramChatId !== '') {
        $telegramAttempted = true;
        $telegramConfig = $telegramBotPool[$index % $telegramBotPoolCount];
        $telegramBotNameUsed = (string)$telegramConfig['namebot'];
        if (!isset($telegramBotUsage[$telegramBotNameUsed])) {
            $telegramBotUsage[$telegramBotNameUsed] = 0;
        }
        $telegramResult = sendTelegramMessage((string)$telegramConfig['bottoken'], $telegramChatId, $textFinal);
        $telegramOk = !empty($telegramResult['sent']);
        $telegramError = (string)($telegramResult['error'] ?? '');
        $telegramBotUsage[$telegramBotNameUsed]++;
        if ($telegramOk) {
            $telegramSuccessCount++;
        } else {
            $telegramFailedCount++;
        }
        // Telegram jauh lebih longgar rate-limit-nya drpd WA -- jeda singkat acak saja.
        usleep(random_int(300, 800) * 1000);
    }

    // Kanal Email: sama seperti Telegram, dicoba TERLEPAS dari status NOWA.
    $emailAddr = (string)(isset($target['EMAIL']) ? $target['EMAIL'] : '');
    $emailAttempted = false;
    $emailOk = false;
    $emailError = '';
    if ($sendEmail && trim($emailAddr) !== '') {
        $emailAttempted = true;
        $emailSubject = 'Pemberitahuan - ' . $idpelCur;
        $emailHtml = nl2br(htmlspecialchars($textFinal, ENT_QUOTES, 'UTF-8'));
        $emailResult = sendEmailMessage($conn, $server, $emailAddr, $namaCur, $emailSubject, $emailHtml);
        $emailOk = !empty($emailResult['sent']);
        $emailError = (string)($emailResult['error'] ?? '');
        if ($emailOk) {
            $emailSuccessCount++;
        } else {
            $emailFailedCount++;
        }
        usleep(random_int(300, 800) * 1000);
    }

    $no = trim((string)(isset($target['NOWA']) ? $target['NOWA'] : ''));
    $digits = preg_replace('/\D+/', '', $no);
    if ($digits === '') {
        $failedCount++;
        $botUsage[$currentBotName]++;
        $processed = $index + 1;
        $rows[] = [
            'idpel' => $idpelCur,
            'nama' => $namaCur,
            'nowa' => $no,
            'botname' => $currentBotName,
            'wa_ok' => false,
            'wa_http_code' => 0,
            'wa_error' => 'Nomor WA kosong/tidak valid',
            'wa_response' => null,
            'telegram_attempted' => $telegramAttempted,
            'telegram_ok' => $telegramOk,
            'telegram_botname' => $telegramBotNameUsed,
            'telegram_error' => $telegramError,
            'email_attempted' => $emailAttempted,
            'email_ok' => $emailOk,
            'email_error' => $emailError,
        ];

        if ($streamMode) {
            outStream('PROGRESS', [
                'processed' => $processed,
                'total' => $totalTarget,
                'success_count' => $successCount,
                'failed_count' => $failedCount,
                'idpel' => $idpelCur,
                'nama' => $namaCur,
                'botname' => $currentBotName,
                'wa_ok' => false,
                'status_text' => 'Nomor WA kosong/tidak valid',
                'email_attempted' => $emailAttempted,
                'email_ok' => $emailOk,
                'telegram_attempted' => $telegramAttempted,
                'telegram_ok' => $telegramOk,
            ]);
        }
        continue;
    }

    if (strpos($digits, '0') === 0) {
        $digits = '62' . substr($digits, 1);
    } elseif (strpos($digits, '62') !== 0) {
        $digits = '62' . $digits;
    }

    $phone = $digits . '@s.whatsapp.net';

    $payload = [
        'phone' => $phone,
        'message' => $textFinal,
    ];

    // device_id gowa multi-device (build gowa terbaru wajib X-Device-Id
    // header / device_id query di /send/message) = isi kolom sender apa
    // adanya (nama device di server gowa, mis. "hanif").
    $deviceId = trim($currentSender);

    $url = rtrim($currentWaapi, '/') . '/send/message?session=' . urlencode($currentBotName);
    if ($deviceId !== '') {
        $url .= '&device_id=' . urlencode($deviceId);
    }
    $headers = ['Content-Type: application/json'];
    if ($deviceId !== '') {
        $headers[] = "X-Device-Id: $deviceId";
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_USERPWD, $currentBotName . ':' . $currentBotPass);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $waOk = ($curlError === '' && $httpCode >= 200 && $httpCode < 300);
    if ($waOk) {
        $successCount++;
    } else {
        $failedCount++;
    }
    $botUsage[$currentBotName]++;

    $rows[] = [
        'idpel' => $idpelCur,
        'nama' => $namaCur,
        'nowa' => $no,
        'phone' => $phone,
        'botname' => $currentBotName,
        'wa_ok' => $waOk,
        'wa_http_code' => $httpCode,
        'wa_error' => $curlError,
        'wa_response' => $response,
        'telegram_attempted' => $telegramAttempted,
        'telegram_ok' => $telegramOk,
        'telegram_botname' => $telegramBotNameUsed,
        'telegram_error' => $telegramError,
        'email_attempted' => $emailAttempted,
        'email_ok' => $emailOk,
        'email_error' => $emailError,
    ];

    applySafeBroadcastDelay($index + 1);

    if ($streamMode) {
        outStream('PROGRESS', [
            'processed' => $index + 1,
            'total' => $totalTarget,
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'idpel' => $idpelCur,
            'nama' => $namaCur,
            'botname' => $currentBotName,
            'wa_ok' => $waOk,
            'status_text' => $waOk ? 'Terkirim' : 'Gagal kirim',
            'telegram_attempted' => $telegramAttempted,
            'telegram_ok' => $telegramOk,
            'email_attempted' => $emailAttempted,
            'email_ok' => $emailOk,
        ]);
    }
}

if ($streamMode) {
    outStream('DONE', [
        'status' => 'ok',
        'message' => 'Proses kirim manual selesai.',
        'summary' => [
            'botname' => $isRandomBot ? '__RANDOM__' : $primaryBotName,
            'bot_pool_count' => $botPoolCount,
            'bot_usage' => $botUsage,
            'server' => $server,
            'area' => $area,
            'odp' => $odp,
            'waapi' => $isRandomBot ? 'MULTI_RANDOM' : $primaryWaapi,
            'total_target' => $totalTarget,
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'telegram_bot_pool_count' => $telegramBotPoolCount,
            'telegram_bot_usage' => $telegramBotUsage,
            'telegram_success_count' => $telegramSuccessCount,
            'telegram_failed_count' => $telegramFailedCount,
            'email_success_count' => $emailSuccessCount,
            'email_failed_count' => $emailFailedCount,
        ],
    ]);
    exit;
}

out([
    'status' => 'ok',
    'message' => 'Proses kirim manual selesai.',
    'summary' => [
        'botname' => $isRandomBot ? '__RANDOM__' : $primaryBotName,
        'bot_pool_count' => $botPoolCount,
        'bot_usage' => $botUsage,
        'server' => $server,
        'area' => $area,
        'odp' => $odp,
        'waapi' => $isRandomBot ? 'MULTI_RANDOM' : $primaryWaapi,
        'total_target' => $totalTarget,
        'success_count' => $successCount,
        'failed_count' => $failedCount,
        'telegram_bot_pool_count' => $telegramBotPoolCount,
        'telegram_bot_usage' => $telegramBotUsage,
        'telegram_success_count' => $telegramSuccessCount,
        'telegram_failed_count' => $telegramFailedCount,
    ],
    'rows' => $rows,
]);