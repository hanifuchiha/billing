<?php
require '../cek-sesi.php';
require_once __DIR__ . '/../notifbot/mailer_helper.php';
require_once __DIR__ . '/../notifbot/notifphp/whatsapp_notification_log_helper.php';

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

function outJson($payload, $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
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

function tanggal_indo($tanggal, $penyesuaian_bulan = 0)
{
    $bulan = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $split = explode('-', $tanggal);
    $tahun = (int)(isset($split[0]) ? $split[0] : date('Y'));
    $bulanIndex = (int)(isset($split[1]) ? $split[1] : date('m'));

    $bulanIndex += $penyesuaian_bulan;
    while ($bulanIndex < 1) {
        $bulanIndex += 12;
        $tahun -= 1;
    }
    while ($bulanIndex > 12) {
        $bulanIndex -= 12;
        $tahun += 1;
    }

    return (isset($bulan[$bulanIndex]) ? $bulan[$bulanIndex] : '-') . ' ' . $tahun;
}

function formatWaNumber($raw)
{
    $digits = preg_replace('/\D+/', '', $raw);
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

function buildNaturalVariant($baseMessage, $idpel, $nama, $mode)
{
    $safeNama = trim($nama) !== '' ? trim($nama) : 'Pelanggan';
    $variantSeed = crc32($idpel . '|' . $safeNama . '|' . date('Y-m-d') . '|' . $mode);

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

function notifGangguanReplaceVars($template, array $vars)
{
    $search = array_map(function ($k) {
        return '$' . $k;
    }, array_keys($vars));
    return str_replace($search, array_values($vars), (string)$template);
}

function notifGangguanDefaultTemplate($mode)
{
    if ($mode === 'selesai') {
        return "============================\n*INFORMASI PEMELIHARAAN JARINGAN*\n============================\nKami informasikan bahwa proses *pengerjaan/pemeliharaan jaringan* di area Anda telah *SELESAI*.\n\nApabila koneksi WiFi masih mengalami kendala, silakan lakukan *restart perangkat* (Router/ONT) terlebih dahulu.\n============================";
    }
    return "============================\n*INFORMASI PEMELIHARAAN JARINGAN*\n============================\nKami informasikan bahwa akan dilakukan *pengerjaan/pemeliharaan jaringan* di area Anda.\n\nKami memohon maaf atas ketidaknyamanan yang mungkin ditimbulkan. Tim teknis akan berupaya menyelesaikan pekerjaan tersebut secepat mungkin.\n\nKami akan memberikan pemberitahuan kembali setelah proses pemeliharaan selesai dilakukan.\n============================";
}

/**
 * Ambil template pesan gangguan/selesai gangguan dari tabel notif_khusus
 * (kolom pesan_gangguan / pesan_gangguan_selesai), jatuh ke template default
 * kalau akun belum pernah menyimpan template sendiri.
 */
function notifGangguanGetTemplate($conn, $pemilik, $mode)
{
    $column = $mode === 'selesai' ? 'pesan_gangguan_selesai' : 'pesan_gangguan';
    $template = '';

    if (!empty($pemilik)) {
        $stmt = $conn->prepare("SELECT `$column` FROM notif_khusus WHERE pemilik = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $pemilik);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            if ($row) {
                $template = trim((string)($row[$column] ?? ''));
            }
        }
    }

    return $template !== '' ? $template : notifGangguanDefaultTemplate($mode);
}

/**
 * Bangun daftar bot (pool) berdasarkan nilai botname yang dikirim dari form checkbox:
 * - '' / 'RANDOM' / '__RANDOM__'  -> pool = SEMUA bot milik pemilik (acak)
 * - "BotA,BotB,..."               -> pool = HANYA bot yang disebut (acak di antara itu)
 * - "BotA"                        -> pool = bot itu saja (fix, tidak random)
 *
 * Mengembalikan array of ['namebot'=>, 'addressbot'=>, 'password'=>]
 */
function resolveBotPool($conn, $ceknama, $botnameRaw, $AKSES = '', $assignedBotIds = [], $asistantName = '')
{
    $botnameRaw = trim((string)$botnameRaw);
    $upper = strtoupper($botnameRaw);
    $isRandomAll = ($botnameRaw === '' || $upper === '__RANDOM__' || $upper === 'RANDOM');

    $pool = [];
    // Assistant tanpa assign hanya boleh pakai bot yg di-assign owner / bot buatan sendiri
    // (lihat notifbot/bot_access_helper.php) -- dicek juga di sini (bukan cuma di form)
    // supaya request POST yang dimanipulasi tetap tidak bisa pakai bot orang lain.
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
 * Sama persis resolveBotPool() di atas, tapi ke tabel `bottelegram` --
 * kanal Telegram BENAR2 opt-in (kalau $botnameRaw kosong, function ini
 * TIDAK dipanggil sama sekali oleh pemanggil, lihat pemakaian di bawah).
 * Return ['pool'=>array of ['namebot','bottoken'], 'error'=>string|null].
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

$debugMode = in_array(strtolower(reqParam('debug')), ['1', 'true', 'yes'], true);
$streamMode = in_array(strtolower(reqParam('stream')), ['1', 'true', 'yes'], true);
$mode = strtolower(reqParam('mode'));
$botnameRaw = reqParam('botname');
$botnameUpper = strtoupper($botnameRaw);
$isRandomBot = ($botnameRaw === '' || $botnameUpper === '__RANDOM__' || $botnameUpper === 'RANDOM' || strpos($botnameRaw, ',') !== false);
// Kanal Telegram bersifat opt-in -- kosong berarti operator tidak centang bot Telegram sama sekali,
// broadcast tetap jalan WA-only seperti sebelumnya (tidak mengubah perilaku existing).
$botnameTelegramRaw = reqParam('botname_telegram');
// Kanal Email juga opt-in -- '1' kalau operator centang "Kirim juga via Email".
$sendEmail = (reqParam('send_email') === '1');
$server = reqParam('server');
$area = reqParam('area');
$odp = reqParam('odp');
$targetType = strtolower(reqParam('target_type', 'odp'));
if (!in_array($targetType, ['odp', 'odc'], true)) {
    $targetType = 'odp';
}
$odc = reqParam('odc');
$pesan = reqParam('pesan');
$notifapp = reqParam('notifapp');
$idhapus = reqParam('idhapus');
$domain = (string)(isset($config['domain']) ? $config['domain'] : '');

if ($streamMode) {
    startStreamOutput();
    registerStreamFatalHandler();
}

$abortRequest = function ($message, $status = 400, $extra = []) use ($debugMode, $streamMode) {
    $payload = array_merge(['status' => 'error', 'message' => $message], $extra);
    if ($streamMode) {
        http_response_code($status);
        outStream('ERROR', $payload);
        exit;
    }
    if ($debugMode) {
        outJson($payload, $status);
    }
    header('Location: ../../broadcast.php?statusnotif=gagal');
    exit;
};

if ($idhapus !== '') {
    $stmtDelete = $conn->prepare("DELETE FROM info WHERE id = ?");
    if ($stmtDelete) {
        $stmtDelete->bind_param('i', $idhapus);
        $stmtDelete->execute();
        $stmtDelete->close();
    }
    if ($streamMode) {
        outStream('DONE', ['status' => 'ok', 'message' => 'Data info dihapus.']);
        exit;
    }
    if ($debugMode) {
        outJson(['status' => 'ok', 'message' => 'Data info dihapus.']);
    }
    header('Location: ../../broadcast.php?statusnotif=terkirim');
    exit;
}

if ($mode === 'notifapp') {
    if ($notifapp === '' || $area === '' || $server === '') {
        $abortRequest('Parameter notifapp/area/server wajib diisi.', 400);
    }

    $statusInfo = 'info';
    $stmtInfo = $conn->prepare("INSERT INTO info (info, status, area, pemilik) VALUES (?, ?, ?, ?)");
    if ($stmtInfo) {
        $stmtInfo->bind_param('ssss', $notifapp, $statusInfo, $area, $server);
        $stmtInfo->execute();
        $stmtInfo->close();
    }

    if ($streamMode) {
        outStream('DONE', ['status' => 'ok', 'message' => 'Notif app disimpan.']);
        exit;
    }
    if ($debugMode) {
        outJson(['status' => 'ok', 'message' => 'Notif app disimpan.']);
    }
    header('Location: ../../broadcast.php?statusnotif=terkirim');
    exit;
}

if (!in_array($mode, ['info', 'selesai', 'tagihan', 'pesan'], true)) {
    $abortRequest('Mode tidak valid.', 400);
}

if ($server === '' || $area === '') {
    $abortRequest('Parameter server/area wajib diisi.', 400, ['input' => compact('botnameRaw', 'server', 'area', 'odp', 'odc', 'mode')]);
}

if ($targetType === 'odc' && $odc === '') {
    $abortRequest('Parameter ODC wajib diisi.', 400);
}

if ($targetType === 'odp' && $odp === '') {
    $abortRequest('Parameter ODP wajib diisi.', 400);
}

if ($mode === 'pesan' && $pesan === '') {
    $abortRequest('Pesan manual wajib diisi.', 400);
}

// Siapkan pool bot berdasarkan pilihan checkbox (RANDOM semua / multi dicentang / satu bot fix)
$botResolved = resolveBotPool($conn, $ceknama, $botnameRaw, $AKSES ?? '', $assigned_bot_ids ?? [], $asistant_name ?? '');
if ($botResolved['error'] !== null) {
    $abortRequest('Prepare query botwa gagal.', 500, ['db_error' => $botResolved['error']]);
}
$botPool = $botResolved['pool'];

if (count($botPool) === 0) {
    $abortRequest('Bot tidak ditemukan.', 404, ['botname' => $botnameRaw, 'is_random' => $isRandomBot]);
}

$botPoolCount = count($botPool);
$primaryBotName = (string)$botPool[0]['namebot'];
$primaryWaapi = (string)$botPool[0]['addressbot'];

// Kanal Telegram: opsional, cuma diresolve kalau operator benar-benar centang bot Telegram.
// Kalau resolve gagal/tidak ketemu, TIDAK menggagalkan broadcast WA -- Telegram-nya saja yang di-skip.
$telegramBotPool = [];
if ($botnameTelegramRaw !== '') {
    $telegramResolved = resolveTelegramBotPool($conn, $ceknama, $botnameTelegramRaw, $AKSES ?? '', $assigned_telegram_bot_ids ?? [], $asistant_name ?? '');
    if ($telegramResolved['error'] === null) {
        $telegramBotPool = $telegramResolved['pool'];
    }
}
$telegramBotPoolCount = count($telegramBotPool);

if (!function_exists('curl_init')) {
    $abortRequest('Ekstensi cURL PHP belum aktif di server.', 500);
}

$odpKodeList = [];
if ($targetType === 'odc') {
    // Cari semua ODP yang menempel ke ODC ini (kolom hirarki_parent = KODE ODC).
    $stmtOdc = $conn->prepare("SELECT KODE FROM odp WHERE Hirarki = 'ODP' AND hirarki_parent = ? AND PEMILIK = ? AND AREA = ?");
    if (!$stmtOdc) {
        $abortRequest('Prepare query ODP dari ODC gagal.', 500, ['db_error' => mysqli_error($conn)]);
    }
    $stmtOdc->bind_param('sss', $odc, $server, $area);
    $stmtOdc->execute();
    $resOdc = $stmtOdc->get_result();
    while ($resOdc && ($rowOdc = $resOdc->fetch_assoc())) {
        $odpKodeList[] = (string)$rowOdc['KODE'];
    }
    $stmtOdc->close();

    if (empty($odpKodeList)) {
        $abortRequest('Tidak ada ODP yang terhubung ke ODC tersebut.', 404, ['odc' => $odc]);
    }
}

if ($targetType === 'odc') {
    $placeholders = implode(',', array_fill(0, count($odpKodeList), '?'));
    $stmtTarget = $conn->prepare("SELECT IDPEL, NAMA, NOWA, PAKET, EMAIL, ALAMAT, PEMILIK, AREA, TELEGRAM_CHAT_ID FROM pelanggan WHERE PEMILIK = ? AND AREA = ? AND ODP IN ($placeholders)");
    if ($stmtTarget) {
        $typesTarget = 'ss' . str_repeat('s', count($odpKodeList));
        $paramsTarget = array_merge([$server, $area], $odpKodeList);
        $refsTarget = [];
        foreach ($paramsTarget as $key => $value) {
            $refsTarget[] = &$paramsTarget[$key];
        }
        array_unshift($refsTarget, $typesTarget);
        call_user_func_array([$stmtTarget, 'bind_param'], $refsTarget);
    }
} elseif (strtoupper($odp) === 'SEMUA') {
    $stmtTarget = $conn->prepare("SELECT IDPEL, NAMA, NOWA, PAKET, EMAIL, ALAMAT, PEMILIK, AREA, TELEGRAM_CHAT_ID FROM pelanggan WHERE PEMILIK = ? AND AREA = ?");
    if ($stmtTarget) {
        $stmtTarget->bind_param('ss', $server, $area);
    }
} else {
    $stmtTarget = $conn->prepare("SELECT IDPEL, NAMA, NOWA, PAKET, EMAIL, ALAMAT, PEMILIK, AREA, TELEGRAM_CHAT_ID FROM pelanggan WHERE PEMILIK = ? AND AREA = ? AND ODP = ?");
    if ($stmtTarget) {
        $stmtTarget->bind_param('sss', $server, $area, $odp);
    }
}

if (!$stmtTarget) {
    $abortRequest('Prepare query pelanggan gagal.', 500, ['db_error' => mysqli_error($conn)]);
}

$stmtTarget->execute();
$resultTarget = $stmtTarget->get_result();
$targets = [];
while ($resultTarget && ($row = $resultTarget->fetch_assoc())) {
    $targets[] = $row;
}
$stmtTarget->close();

$tanggalSekarang = date('Y-m-d');
$periodeTagihan = tanggal_indo($tanggalSekarang, 0);
$jatuhTempo = '-';

// Mode tagihan hanya menargetkan pelanggan yang BELUM mempunyai transaksi
// BERHASIL untuk periode yang sedang ditampilkan. Tanggal bayar tidak menjadi
// patokan: pembayaran Agustus untuk PENGUNAAN September tetap dihitung lunas,
// sedangkan BERHASIL PENGUNAAN Agustus tidak menutup tagihan September.
$skippedPaidCount = 0;
$skippedFreeCount = 0;
if ($mode === 'tagihan' && !empty($targets)) {
    $targetIds = [];
    foreach ($targets as $targetRow) {
        $targetId = trim((string)($targetRow['IDPEL'] ?? ''));
        if ($targetId !== '') {
            $targetIds[$targetId] = true;
        }
    }

    $paidPeriodMap = [];
    if (!empty($targetIds)) {
        $escapedIds = [];
        foreach (array_keys($targetIds) as $targetId) {
            $escapedIds[] = "'" . $conn->real_escape_string($targetId) . "'";
        }
        $periodeEscaped = $conn->real_escape_string(trim($periodeTagihan));
        $paidSql = "SELECT DISTINCT IDPEL
                    FROM transaksi
                    WHERE UPPER(TRIM(COALESCE(STATUS, ''))) = 'BERHASIL'
                      AND LOWER(TRIM(COALESCE(PENGUNAAN, ''))) = LOWER('$periodeEscaped')
                      AND IDPEL IN (" . implode(',', $escapedIds) . ")";
        $paidResult = $conn->query($paidSql);
        if (!$paidResult) {
            $abortRequest('Gagal memeriksa pembayaran periode tagihan.', 500, ['db_error' => $conn->error]);
        }
        while ($paidRow = $paidResult->fetch_assoc()) {
            $paidPeriodMap[(string)$paidRow['IDPEL']] = true;
        }
    }

    $targets = array_values(array_filter($targets, function ($targetRow) use ($paidPeriodMap, &$skippedPaidCount, &$skippedFreeCount) {
        if (stripos((string)($targetRow['PAKET'] ?? ''), 'FREE') !== false) {
            $skippedFreeCount++;
            return false;
        }
        if (!empty($paidPeriodMap[(string)($targetRow['IDPEL'] ?? '')])) {
            $skippedPaidCount++;
            return false;
        }
        return true;
    }));
}

$stmtUser = $conn->prepare("SELECT USERNAME FROM user WHERE server LIKE ? LIMIT 1");
if ($stmtUser) {
    $serverLike = '%' . $server . '%';
    $stmtUser->bind_param('s', $serverLike);
    $stmtUser->execute();
    $resUser = $stmtUser->get_result();
    $userRow = $resUser ? $resUser->fetch_assoc() : null;
    $stmtUser->close();

    if ($userRow && !empty($userRow['USERNAME'])) {
        $jsonFile = '../data/reminder-' . $userRow['USERNAME'] . '.json';
        if (file_exists($jsonFile)) {
            $jsonData = json_decode((string)file_get_contents($jsonFile), true);
            if (is_array($jsonData) && isset($jsonData[0]['jatuh_tempo'])) {
                $jatuhTempo = (string)$jsonData[0]['jatuh_tempo'];
            }
        }
    }
}

$historyFile = '../data/history-' . $ceknama . '.json';
$history = [];
if (file_exists($historyFile)) {
    $history = json_decode((string)file_get_contents($historyFile), true);
}
if (!is_array($history)) {
    $history = [];
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
$skippedAlreadySentCount = 0;
$totalTarget = count($targets);

if ($totalTarget > 1) {
    shuffle($targets);
}

$odpDisplay = $targetType === 'odc' ? $odc : $odp;
$templateGangguan = in_array($mode, ['info', 'selesai'], true) ? notifGangguanGetTemplate($conn, $ceknama, $mode) : '';
$emailSubjectByMode = [
    'info' => 'Info Gangguan Jaringan',
    'selesai' => 'Info Gangguan Selesai',
    'tagihan' => 'Pengingat Tagihan',
    'pesan' => 'Pemberitahuan',
];
$emailSubjectPrefix = (string)($emailSubjectByMode[$mode] ?? 'Pemberitahuan');

if ($streamMode) {
    outStream('START', [
        'status' => 'ok',
        'message' => 'Proses broadcast dimulai.',
        'mode' => $mode,
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

    $idpel = (string)(isset($target['IDPEL']) ? $target['IDPEL'] : '');
    $nama = (string)(isset($target['NAMA']) ? $target['NAMA'] : '');
    $nowaRaw = (string)(isset($target['NOWA']) ? $target['NOWA'] : '');
    $nowa = formatWaNumber($nowaRaw);
    $paket = (string)(isset($target['PAKET']) ? $target['PAKET'] : '');
    $alamat = (string)(isset($target['ALAMAT']) ? $target['ALAMAT'] : '');
    $pemilik = (string)(isset($target['PEMILIK']) ? $target['PEMILIK'] : $server);
    $areaPelanggan = (string)(isset($target['AREA']) ? $target['AREA'] : $area);

    if ($mode === 'info' || $mode === 'selesai') {
        $textBase = notifGangguanReplaceVars($templateGangguan, [
            'idpel' => $idpel,
            'nama' => $nama,
            'nowa' => $nowaRaw,
            'paket' => $paket,
            'alamat' => $alamat,
            'area' => $areaPelanggan,
            'odp' => $odpDisplay,
        ]);
    } elseif ($mode === 'tagihan') {
        $linkPortal = rtrim($domain, '/') . '/crm/billing/broadband/portal.php?cari=' . urlencode($idpel);
        $textBase = "*[ INI ADALAH PESAN OTOMATIS ]*\n\nInternet anda dalam jatuh tempo.\nSegera lakukan pembayaran untuk menghindari ISOLIR tanggal $jatuhTempo.\n\n- Dengan detail :\n- ID Pelanggan : $idpel\n- Nama Pelanggan : $nama\n- Paket langganan : $paket\n- No Whatsapp : $nowaRaw\n- Alamat : $alamat\n- Periode : $periodeTagihan\n\nLink pembayaran:\n$linkPortal\n\n*[ Abaikan pesan ini jika anda sudah membayar ]*\nSalam $pemilik-$areaPelanggan";
    } else {
        $textBase = $pesan;
    }

    $text = buildNaturalVariant($textBase, $idpel, $nama, $mode);

    // Gunakan kunci deduplikasi yang sama dengan cron reminder. Dengan begitu
    // broadcast tagihan tidak mengirim ulang pelanggan yang sudah sukses pada
    // periode ini, tetapi status failed tetap dapat dicoba kembali.
    $waNotifLogId = 0;
    if ($mode === 'tagihan') {
        $notifLog = waNotifQueueAndClaim($conn, [
            'pemilik' => $ceknama,
            'idpel' => $idpel,
            'nomor_wa' => $nowaRaw,
            'periode' => $periodeTagihan,
            'jenis_notifikasi' => 'payment_reminder_fixed',
            'message' => $text,
            'bot_name' => $currentBotName,
        ]);
        if (!$notifLog['claimed']) {
            $skippedAlreadySentCount++;
            if ($streamMode) {
                outStream('PROGRESS', [
                    'processed' => $index + 1,
                    'total' => $totalTarget,
                    'success_count' => $successCount,
                    'failed_count' => $failedCount,
                    'skipped_count' => $skippedAlreadySentCount,
                    'idpel' => $idpel,
                    'nama' => $nama,
                    'botname' => $currentBotName,
                    'wa_ok' => true,
                    'status_text' => 'Dilewati: reminder periode ini sudah terkirim/diproses',
                ]);
            }
            continue;
        }
        $waNotifLogId = (int)$notifLog['id'];
    }

    // Kanal Telegram: dicoba TERLEPAS dari status NOWA (pelanggan bisa saja cuma
    // link Telegram, tanpa WA valid) -- dilewati diam2 kalau pool kosong atau
    // pelanggan belum pernah "/start" (TELEGRAM_CHAT_ID kosong).
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
        $telegramResult = sendTelegramMessage((string)$telegramConfig['bottoken'], $telegramChatId, $text);
        $telegramOk = !empty($telegramResult['sent']);
        $telegramError = (string)($telegramResult['error'] ?? '');
        $telegramBotUsage[$telegramBotNameUsed]++;
        if ($telegramOk) {
            $telegramSuccessCount++;
        } else {
            $telegramFailedCount++;
        }
        // Telegram API jauh lebih longgar rate-limit-nya dibanding WA -- cukup
        // jeda singkat acak, bukan applySafeBroadcastDelay() penuh spt WA.
        usleep(random_int(300, 800) * 1000);
    }

    // Kanal Email: sama seperti Telegram, dicoba TERLEPAS dari status NOWA.
    // Tidak perlu langkah "linking" spt Telegram -- EMAIL sudah ada di data
    // pelanggan (diisi admin), bukan self-registration pelanggan.
    $emailAddr = (string)(isset($target['EMAIL']) ? $target['EMAIL'] : '');
    $emailAttempted = false;
    $emailOk = false;
    $emailError = '';
    if ($sendEmail && trim($emailAddr) !== '') {
        $emailAttempted = true;
        $emailSubject = $emailSubjectPrefix . ' - ' . $idpel;
        $emailHtml = nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
        $emailResult = sendEmailMessage($conn, $pemilik, $emailAddr, $nama, $emailSubject, $emailHtml);
        $emailOk = !empty($emailResult['sent']);
        $emailError = (string)($emailResult['error'] ?? '');
        if ($emailOk) {
            $emailSuccessCount++;
        } else {
            $emailFailedCount++;
        }
        // SMTP juga longgar rate-limit-nya dibanding WA -- jeda singkat acak saja.
        usleep(random_int(300, 800) * 1000);
    }

    if ($nowa === '') {
        if ($waNotifLogId > 0) {
            waNotifFinish($conn, $waNotifLogId, false, 0, 'Nomor WA kosong/tidak valid');
        }
        $failedCount++;
        $botUsage[$currentBotName]++;
        $processed = $index + 1;
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
                'processed' => $processed,
                'total' => $totalTarget,
                'success_count' => $successCount,
                'failed_count' => $failedCount,
                'idpel' => $idpel,
                'nama' => $nama,
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

    $phone = $nowa . '@s.whatsapp.net';
    $payload = [
        'phone' => $phone,
        'message' => $text,
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
    if ($waNotifLogId > 0) {
        waNotifFinish(
            $conn,
            $waNotifLogId,
            $waOk,
            $httpCode,
            $curlError !== '' ? ('cURL: ' . $curlError . ' | ' . (string)$response) : (string)$response
        );
    }
    if ($waOk) {
        $successCount++;
    } else {
        $failedCount++;
    }
    $botUsage[$currentBotName]++;

    $history[] = '[ ' . (!empty($asistant_name) ? $asistant_name : $ceknama) . ' - ' . date('Y-m-d H:i:s') . ' ] Bot ' . $currentBotName . ' mode ' . $mode . ' kirim WA ke ' . $phone;
    file_put_contents($historyFile, json_encode($history, JSON_PRETTY_PRINT));

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

    applySafeBroadcastDelay($index + 1);

    if ($streamMode) {
        outStream('PROGRESS', [
            'processed' => $index + 1,
            'total' => $totalTarget,
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'skipped_paid_count' => $skippedPaidCount,
            'skipped_free_count' => $skippedFreeCount,
            'skipped_already_sent_count' => $skippedAlreadySentCount,
            'idpel' => $idpel,
            'nama' => $nama,
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
        'message' => 'Proses broadcast selesai.',
        'summary' => [
            'mode' => $mode,
            'botname' => $isRandomBot ? '__RANDOM__' : $primaryBotName,
            'bot_pool_count' => $botPoolCount,
            'bot_usage' => $botUsage,
            'server' => $server,
            'area' => $area,
            'target_type' => $targetType,
            'odp' => $odp,
            'odc' => $odc,
            'waapi' => $isRandomBot ? 'MULTI_RANDOM' : $primaryWaapi,
            'total_target' => $totalTarget,
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'skipped_paid_count' => $skippedPaidCount,
            'skipped_free_count' => $skippedFreeCount,
            'skipped_already_sent_count' => $skippedAlreadySentCount,
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

if ($debugMode) {
    outJson([
        'status' => 'ok',
        'message' => 'Proses broadcast selesai.',
        'summary' => [
            'mode' => $mode,
            'botname' => $isRandomBot ? '__RANDOM__' : $primaryBotName,
            'bot_pool_count' => $botPoolCount,
            'bot_usage' => $botUsage,
            'server' => $server,
            'area' => $area,
            'target_type' => $targetType,
            'odp' => $odp,
            'odc' => $odc,
            'waapi' => $isRandomBot ? 'MULTI_RANDOM' : $primaryWaapi,
            'total_target' => $totalTarget,
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'skipped_paid_count' => $skippedPaidCount,
            'skipped_free_count' => $skippedFreeCount,
            'skipped_already_sent_count' => $skippedAlreadySentCount,
            'telegram_bot_pool_count' => $telegramBotPoolCount,
            'telegram_bot_usage' => $telegramBotUsage,
            'telegram_success_count' => $telegramSuccessCount,
            'telegram_failed_count' => $telegramFailedCount,
            'email_success_count' => $emailSuccessCount,
            'email_failed_count' => $emailFailedCount,
        ],
        'rows' => $rows,
    ]);
}

$status = $failedCount > 0 ? 'gagal' : 'terkirim';
header('Location: ../../broadcast.php?statusnotif=' . $status);
exit;
