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

function outJson($data, $status = 200)
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

function sleepAman($min = 7, $max = 13)
{
    $delay = rand($min * 1000, $max * 1000);
    usleep($delay * 1000);
}

function applySafeBroadcastDelay($index)
{
    sleepAman(7, 13);
    if ($index > 0 && $index % 20 === 0) {
        sleepAman(25, 40);
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

function formatWaNumber($raw)
{
    $digits = preg_replace('/\D+/', '', (string)$raw);
    if ($digits === '') {
        return '';
    }
    if (strpos($digits, '0') === 0) {
        $digits = '62' . substr($digits, 1);
    } elseif (strpos($digits, '62') !== 0) {
        $digits = '62' . $digits;
    }
    return $digits;
}

function parseIdpelList($raw)
{
    // Pisahkan berdasarkan pemisah list saja, jangan berdasarkan spasi
    // agar IDPEL dengan spasi/karakter khusus tetap utuh.
    $parts = preg_split('/[,;\r\n]+/', (string)$raw);
    $ids = [];
    if (!is_array($parts)) {
        return $ids;
    }

    foreach ($parts as $id) {
        $id = trim($id);
        if ($id === '') {
            continue;
        }

        // Hapus karakter kontrol saja, biarkan karakter IDPEL lainnya tetap ada.
        $id = preg_replace('/[\x00-\x1F\x7F]/u', '', $id);
        if ($id !== '') {
            $ids[] = $id;
        }
    }

    return array_values(array_unique($ids));
}

/**
 * Bangun daftar bot (pool) berdasarkan nilai botname yang dikirim dari form checkbox:
 * - '' / 'RANDOM' / '__RANDOM__'  -> pool = SEMUA bot milik pemilik (acak)
 * - "BotA,BotB,..."               -> pool = HANYA bot yang disebut (acak di antara itu)
 * - "BotA"                        -> pool = bot itu saja (fix, tidak random)
 *
 * Mengembalikan ['pool' => array, 'error' => string|null]
 */
function resolveBotPool($conn, $ceknama, $botnameRaw, $AKSES = '', $assignedBotIds = [], $asistantName = '')
{
    $botnameRaw = trim((string)$botnameRaw);
    $upper = strtoupper($botnameRaw);
    $isRandomAll = ($botnameRaw === '' || $upper === '__RANDOM__' || $upper === 'RANDOM');

    $pool = [];
    $botAccessClause = function_exists('botAccessWhereClause')
        ? botAccessWhereClause($conn, (string)$AKSES, (array)$assignedBotIds, (string)$asistantName)
        : '';

    if ($isRandomAll) {
        $stmt = $conn->prepare("SELECT namebot, addressbot, password, sender FROM botwa WHERE pemilik = ?" . $botAccessClause);
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
        // Multi-bot yang dicentang -> random hanya dari daftar ini
        $names = array_values(array_unique(array_filter(array_map('trim', explode(',', $botnameRaw)), function ($v) {
            return $v !== '';
        })));

        if (empty($names)) {
            return resolveBotPool($conn, $ceknama, 'RANDOM', $AKSES, $assignedBotIds, $asistantName); // fallback aman
        }

        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $sql = "SELECT namebot, addressbot, password, sender FROM botwa WHERE pemilik = ? AND namebot IN ($placeholders)" . $botAccessClause;
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
        // Satu bot spesifik -> fix, tidak random
        $stmt = $conn->prepare("SELECT namebot, addressbot, password, sender FROM botwa WHERE namebot = ? AND pemilik = ? LIMIT 1" . $botAccessClause);
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
 * Versi Telegram dari resolveBotPool() di atas -- pola identik, tabel bottelegram +
 * telegramBotAccessWhereClause() (bukan botAccessWhereClause()), kolom bottoken
 * menggantikan addressbot/password/sender.
 */
function resolveTelegramBotPool($conn, $ceknama, $botnameRaw, $AKSES = '', $assignedTelegramBotIds = [], $asistantName = '')
{
    $botnameRaw = trim((string)$botnameRaw);
    $upper = strtoupper($botnameRaw);
    $isRandomAll = ($botnameRaw === '' || $upper === '__RANDOM__' || $upper === 'RANDOM');

    $pool = [];
    $accessClause = function_exists('telegramBotAccessWhereClause')
        ? telegramBotAccessWhereClause($conn, (string)$AKSES, (array)$assignedTelegramBotIds, (string)$asistantName)
        : '';

    if ($isRandomAll) {
        $stmt = $conn->prepare("SELECT namebot, bottoken FROM bottelegram WHERE pemilik = ?" . $accessClause);
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
            return resolveTelegramBotPool($conn, $ceknama, 'RANDOM', $AKSES, $assignedTelegramBotIds, $asistantName);
        }

        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $sql = "SELECT namebot, bottoken FROM bottelegram WHERE pemilik = ? AND namebot IN ($placeholders)" . $accessClause;
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
        $stmt = $conn->prepare("SELECT namebot, bottoken FROM bottelegram WHERE namebot = ? AND pemilik = ? LIMIT 1" . $accessClause);
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

$streamMode = in_array(strtolower(reqParam('stream')), ['1', 'true', 'yes'], true);
$debugMode = in_array(strtolower(reqParam('debug')), ['1', 'true', 'yes'], true);

if ($streamMode) {
    startStreamOutput();
    registerStreamFatalHandler();
}

$abortRequest = function ($message, $status = 400, $extra = []) use ($streamMode, $debugMode) {
    $payload = array_merge(['status' => 'error', 'message' => $message], $extra);
    if ($streamMode) {
        http_response_code($status);
        outStream('ERROR', $payload);
        exit;
    }
    if ($debugMode) {
        outJson($payload, $status);
    }
    outJson($payload, $status);
};

$botnameRaw = reqParam('botname');
$botnameUpper = strtoupper($botnameRaw);
$isRandomBot = ($botnameRaw === '' || $botnameUpper === '__RANDOM__' || $botnameUpper === 'RANDOM' || strpos($botnameRaw, ',') !== false);
// Kanal Telegram opt-in -- kosong berarti operator tidak centang bot Telegram sama sekali.
$botnameTelegramRaw = reqParam('botname_telegram');
// Kanal Email juga opt-in -- '1' kalau operator centang "Kirim juga via Email".
$sendEmail = (reqParam('send_email') === '1');
$pesan = reqParam('pesan');
$idpelRaw = reqParam('idpel_list');
// Mode "pakai template" -- pesan diambil dari notif_khusus.pesan_remainder_manual
// (dikonfigurasi di halaman Notifikasi -> Reminder Manual) alih-alih ketik manual,
// dgn placeholder $NAMA/$IDPEL/$PAKET/dkk diganti per-pelanggan (sama seperti
// proses/sendinvoice.php). Kalau mode ini aktif, textarea pesan manual TIDAK wajib.
$useTemplate = (reqParam('use_template') === '1');

if ($idpelRaw === '' || (!$useTemplate && $pesan === '')) {
    $abortRequest('Parameter pesan/idpel_list wajib diisi.', 400);
}

$templateRemainderManual = '';
if ($useTemplate) {
    // Self-heal tabel/kolom notif_khusus.pesan_remainder_manual -- biasanya
    // sudah dibuat lewat notification.php, tapi jangan asumsikan akun ini
    // pernah buka halaman itu sebelum pakai fitur template di sini.
    $notifKhususCheck = @mysqli_query($conn, "SHOW TABLES LIKE 'notif_khusus'");
    if ($notifKhususCheck && mysqli_num_rows($notifKhususCheck) === 0) {
        @mysqli_query($conn, "CREATE TABLE notif_khusus (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pemilik VARCHAR(100) NOT NULL,
            pesan_ketentuan TEXT,
            pesan_disable TEXT,
            pesan_aktif_manual TEXT,
            pesan_remainder_manual TEXT,
            pesan_dismantle_manual TEXT,
            pesan_gangguan TEXT,
            pesan_gangguan_selesai TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } else {
        $colCheck = @mysqli_query($conn, "SHOW COLUMNS FROM notif_khusus LIKE 'pesan_remainder_manual'");
        if ($colCheck && mysqli_num_rows($colCheck) === 0) {
            @mysqli_query($conn, "ALTER TABLE notif_khusus ADD COLUMN pesan_remainder_manual TEXT");
        }
    }

    $stmtTpl = $conn->prepare('SELECT pesan_remainder_manual FROM notif_khusus WHERE pemilik = ? LIMIT 1');
    if ($stmtTpl) {
        $stmtTpl->bind_param('s', $ceknama);
        $stmtTpl->execute();
        $stmtTpl->bind_result($templateRemainderManual);
        $stmtTpl->fetch();
        $stmtTpl->close();
    }
    $templateRemainderManual = trim((string)$templateRemainderManual);
    if ($templateRemainderManual === '') {
        $abortRequest('Template "Pesan Remainder Manual" belum diisi. Silakan atur dulu di menu Notifikasi -> Reminder Manual.', 400);
    }
}

// Substitusi placeholder $NAMA/$IDPEL/dkk di template -- pola sama persis dgn
// proses/sendinvoice.php (replace_vars + get_defined_vars per-pelanggan).
if (!function_exists('menunggakReplaceTemplateVars')) {
    function menunggakReplaceTemplateVars($template, $vars)
    {
        return preg_replace_callback('/\$([a-zA-Z0-9_]+)/', function ($m) use ($vars) {
            $key = $m[1];
            return array_key_exists($key, $vars) ? (string)$vars[$key] : $m[0];
        }, $template);
    }
}

if (!function_exists('curl_init')) {
    $abortRequest('Ekstensi cURL PHP belum aktif di server.', 500);
}

$idpelList = parseIdpelList($idpelRaw);
if (count($idpelList) === 0) {
    $abortRequest('Daftar ID pelanggan menunggak kosong.', 400);
}

// Siapkan pool bot berdasarkan pilihan checkbox (RANDOM semua / multi dicentang / satu bot fix)
$botResolved = resolveBotPool($conn, $ceknama, $botnameRaw, $AKSES ?? '', $assigned_bot_ids ?? [], $asistant_name ?? '');
if ($botResolved['error'] !== null) {
    $abortRequest('Gagal prepare query botwa.', 500, ['db_error' => $botResolved['error']]);
}
$botPool = $botResolved['pool'];

if (count($botPool) === 0) {
    $abortRequest('Bot tidak ditemukan di tabel botwa.', 404, ['botname' => $botnameRaw, 'is_random' => $isRandomBot]);
}

$botPoolCount = count($botPool);
$primaryBotName = (string)$botPool[0]['namebot'];

// Kanal Telegram: opsional, cuma diresolve kalau operator centang bot Telegram.
// Gagal resolve TIDAK menggagalkan broadcast WA -- Telegram-nya saja yang di-skip.
$telegramBotPool = [];
if ($botnameTelegramRaw !== '') {
    $telegramResolved = resolveTelegramBotPool($conn, $ceknama, $botnameTelegramRaw, $AKSES ?? '', $assigned_telegram_bot_ids ?? [], $asistant_name ?? '');
    if ($telegramResolved['error'] === null) {
        $telegramBotPool = $telegramResolved['pool'];
    }
}
$telegramBotPoolCount = count($telegramBotPool);

$allowedOwners = [];
$ownerQuerySql = "SELECT DISTINCT PEMILIK FROM server WHERE user_id = " . (int)$current_user_id;
if (isset($AKSES) && $AKSES === 'ASSISTANT' && !empty($area_list)) {
    // Samakan scope ASSISTANT dengan halaman pelanggan_menunggak: berdasarkan AREA yang diizinkan.
    $ownerQuerySql = "SELECT DISTINCT PEMILIK FROM server WHERE AREA IN ($area_list)";
}

$ownerQuery = mysqli_query($conn, $ownerQuerySql);
while ($ownerQuery && ($ownerRow = mysqli_fetch_assoc($ownerQuery))) {
    if (!empty($ownerRow['PEMILIK'])) {
        $allowedOwners[] = $ownerRow['PEMILIK'];
    }
}
if (!in_array($ceknama, $allowedOwners, true)) {
    $allowedOwners[] = $ceknama;
}
$allowedOwners = array_values(array_unique(array_filter($allowedOwners)));

$idpelSqlList = [];
foreach ($idpelList as $idpel) {
    $idpelSqlList[] = "'" . mysqli_real_escape_string($conn, $idpel) . "'";
}

$ownerSqlList = [];
foreach ($allowedOwners as $owner) {
    $ownerSqlList[] = "'" . mysqli_real_escape_string($conn, $owner) . "'";
}

if (count($idpelSqlList) === 0) {
    $abortRequest('Data target tidak valid.', 400);
}

$ownerWhere = '';
if (count($ownerSqlList) > 0) {
    $ownerWhere = " AND PEMILIK IN (" . implode(',', $ownerSqlList) . ")";
}

$sqlTarget = "SELECT IDPEL, NAMA, NOWA, PEMILIK, AREA, PAKET, EMAIL, ALAMAT, BRAND, TEMPO, TANGGALPASANG, TIPE_BAYAR, TELEGRAM_CHAT_ID FROM pelanggan WHERE TRIM(IDPEL) IN (" . implode(',', $idpelSqlList) . ")" . $ownerWhere;
$resultTarget = mysqli_query($conn, $sqlTarget);
if (!$resultTarget) {
    $abortRequest('Gagal mengambil data pelanggan menunggak.', 500, ['db_error' => mysqli_error($conn)]);
}

$targets = [];
while ($row = mysqli_fetch_assoc($resultTarget)) {
    $targets[] = $row;
}

// Fallback: jika filter scope (owner/area) terlalu ketat dan hasil 0,
// gunakan IDPEL yang dipilih user agar broadcast tetap berjalan.
$usedFallbackScope = false;
if (count($targets) === 0) {
    $fallbackSql = "SELECT IDPEL, NAMA, NOWA, PEMILIK, AREA, PAKET, EMAIL, ALAMAT, BRAND, TEMPO, TANGGALPASANG, TIPE_BAYAR, TELEGRAM_CHAT_ID FROM pelanggan WHERE TRIM(IDPEL) IN (" . implode(',', $idpelSqlList) . ")";
    $fallbackRes = mysqli_query($conn, $fallbackSql);
    if ($fallbackRes) {
        while ($row = mysqli_fetch_assoc($fallbackRes)) {
            $targets[] = $row;
        }
        if (count($targets) > 0) {
            $usedFallbackScope = true;
        }
    }
}

$totalTarget = count($targets);
if ($totalTarget === 0) {
    $diagnostic = [
        'requested_idpel' => count($idpelList),
        'allowed_owner_count' => count($allowedOwners),
        'akses' => isset($AKSES) ? (string)$AKSES : '',
        'owner_scope_sql' => $ownerQuerySql,
        'used_fallback_scope' => $usedFallbackScope,
    ];

    if ($streamMode) {
        outStream('DONE', [
            'status' => 'ok',
            'message' => 'Tidak ada pelanggan menunggak yang valid untuk dikirim (target query kosong).',
            'summary' => [
                'total_target' => 0,
                'success_count' => 0,
                'failed_count' => 0,
            ],
            'diagnostic' => $diagnostic,
        ]);
        exit;
    }
    outJson([
        'status' => 'ok',
        'message' => 'Tidak ada pelanggan menunggak yang valid untuk dikirim (target query kosong).',
        'summary' => [
            'total_target' => 0,
            'success_count' => 0,
            'failed_count' => 0,
        ],
        'diagnostic' => $diagnostic,
    ]);
}

$successCount = 0;
$failedCount = 0;
$rows = [];
$botUsage = [];
$telegramSuccessCount = 0;
$telegramFailedCount = 0;
$telegramBotUsage = [];
$emailSuccessCount = 0;
$emailFailedCount = 0;

if ($totalTarget > 1) {
    shuffle($targets);
}

if ($streamMode) {
    outStream('START', [
        'status' => 'ok',
        'message' => 'Proses kirim menunggak dimulai.',
        'botname' => $isRandomBot ? '__RANDOM__' : $primaryBotName,
        'bot_pool_count' => $botPoolCount,
        'total_target' => $totalTarget,
        'scope' => $usedFallbackScope ? 'fallback_idpel_only' : 'owner_area_scope',
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

    $idpel = isset($target['IDPEL']) ? (string)$target['IDPEL'] : '';
    $nama = isset($target['NAMA']) ? (string)$target['NAMA'] : '';
    $nowaRaw = isset($target['NOWA']) ? (string)$target['NOWA'] : '';
    $nowa = formatWaNumber($nowaRaw);

    if ($useTemplate) {
        $tplVars = [
            'IDPEL' => $idpel,
            'NAMA' => $nama,
            'NOWA' => $nowaRaw,
            'PAKET' => (string)($target['PAKET'] ?? ''),
            'EMAIL' => (string)($target['EMAIL'] ?? ''),
            'ALAMAT' => (string)($target['ALAMAT'] ?? ''),
            'BRAND' => (string)($target['BRAND'] ?? ''),
            'jatuh_tempo' => (string)($target['TEMPO'] ?? ''),
            'URL' => rtrim((string)($config['domain'] ?? ''), '/'),
        ];
        $textFinal = menunggakReplaceTemplateVars($templateRemainderManual, $tplVars);
    } else {
        $textFinal = buildNaturalVariant($pesan, $idpel, $nama);
    }

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
        $emailPemilik = (string)($target['PEMILIK'] ?? $ceknama);
        $emailSubject = 'Pemberitahuan - ' . $idpel;
        $emailHtml = nl2br(htmlspecialchars($textFinal, ENT_QUOTES, 'UTF-8'));
        $emailResult = sendEmailMessage($conn, $emailPemilik, $emailAddr, $nama, $emailSubject, $emailHtml);
        $emailOk = !empty($emailResult['sent']);
        $emailError = (string)($emailResult['error'] ?? '');
        if ($emailOk) {
            $emailSuccessCount++;
        } else {
            $emailFailedCount++;
        }
        usleep(random_int(300, 800) * 1000);
    }

    if ($nowa === '') {
        $failedCount++;
        $botUsage[$currentBotName]++;
        $rows[] = [
            'idpel' => $idpel,
            'nama' => $nama,
            'nowa' => $nowaRaw,
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
                'processed' => $index + 1,
                'total' => $totalTarget,
                'success_count' => $successCount,
                'failed_count' => $failedCount,
                'idpel' => $idpel,
                'nama' => $nama,
                'botname' => $currentBotName,
                'wa_ok' => false,
                'status_text' => 'Nomor WA kosong/tidak valid',
                'telegram_attempted' => $telegramAttempted,
                'telegram_ok' => $telegramOk,
                'email_attempted' => $emailAttempted,
                'email_ok' => $emailOk,
            ]);
        }
        continue;
    }

    $phone = $nowa . '@s.whatsapp.net';
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
        'idpel' => $idpel,
        'nama' => $nama,
        'nowa' => $nowaRaw,
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

    // Tetap beri jeda aman meskipun mode debug aktif agar tidak terdeteksi spam.
    applySafeBroadcastDelay($index + 1);

    if ($streamMode) {
        outStream('PROGRESS', [
            'processed' => $index + 1,
            'total' => $totalTarget,
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'idpel' => $idpel,
            'nama' => $nama,
            'botname' => $currentBotName,
            'wa_ok' => $waOk,
            'status_text' => $waOk ? 'Terkirim' : 'Gagal kirim',
            'wa_http_code' => $httpCode,
            'wa_error' => $curlError !== '' ? $curlError : null,
            'wa_response' => $response !== false ? mb_substr((string)$response, 0, 300) : null,
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
        'message' => 'Proses kirim menunggak selesai.',
        'summary' => [
            'botname' => $isRandomBot ? '__RANDOM__' : $primaryBotName,
            'bot_pool_count' => $botPoolCount,
            'bot_usage' => $botUsage,
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

outJson([
    'status' => 'ok',
    'message' => 'Proses kirim menunggak selesai.',
    'summary' => [
        'botname' => $isRandomBot ? '__RANDOM__' : $primaryBotName,
        'bot_pool_count' => $botPoolCount,
        'bot_usage' => $botUsage,
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