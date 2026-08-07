<?php
// DIAGNOSA SEMENTARA � hapus setelah error diketahui
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


@set_time_limit(0);
if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

defined('CRON_BASE_DIR') || define('CRON_BASE_DIR', dirname(__DIR__, 2));
defined('CRON_DIR')      || define('CRON_DIR',      __DIR__);
defined('LOG_FILE')      || define('LOG_FILE',      __DIR__ . '/log_dismantle_ticket.log');
defined('LOCK_FILE')     || define('LOCK_FILE',     __DIR__ . '/cron_dismantle_ticket.lock');
defined('MAX_LOG_SIZE')  || define('MAX_LOG_SIZE',  2 * 1024 * 1024); // 2 MB � rotate log jika sudah besar

// -- Optional arg: --pemilik=XXX (untuk file wrapper per PEMILIK) ----------
$forcedPemilik = '';
foreach (($argv ?? []) as $arg) {
    if (strpos((string)$arg, '--pemilik=') === 0) {
        $forcedPemilik = trim((string)substr((string)$arg, 10));
        break;
    }
}
if ($forcedPemilik === '' && isset($_GET['pemilik'])) {
    $forcedPemilik = trim((string)$_GET['pemilik']);
}

$isManualRun = (
    (isset($_GET['manual']) && (string)$_GET['manual'] === '1') ||
    in_array('--manual', $argv ?? [], true)
);
$isDryRunArg = in_array('--dry-run', $argv ?? [], true);

// -- Toggle on/off & Get enabled PEMILIK list ------------------------------
$cronConfigFile = CRON_DIR . '/config_cron.json';
$cronConfig = file_exists($cronConfigFile)
    ? (json_decode(file_get_contents($cronConfigFile), true) ?? [])
    : [];

$cronConfigDismantle = is_array($cronConfig['cron_dismantle_ticket'] ?? null)
    ? $cronConfig['cron_dismantle_ticket']
    : ['enabled_by' => [], 'interval_hours' => 2];

$enabledByList = ($forcedPemilik !== '')
    ? [$forcedPemilik]
    : (array)($cronConfigDismantle['enabled_by'] ?? []);
if (empty($enabledByList)) {
    echo '[INFO] Cron cron_dismantle_ticket DISABLED (tidak ada PEMILIK yang mengaktifkan).' . PHP_EOL;
    echo '[INFO] Aktifkan via UI: pelanggan_menunggak.php toggle dismantle cron' . PHP_EOL;
    exit(0);
}

// -- Interval check ---------------------------------------------------------
$_diIntervalHours = max(1, (int)($cronConfigDismantle['interval_hours'] ?? 2));
$_diLastRunSuffix = '';
if ($forcedPemilik !== '') {
    $_diLastRunSuffix = '_' . preg_replace('/[^A-Z0-9]+/', '_', strtoupper($forcedPemilik));
    $_diLastRunSuffix = trim((string)$_diLastRunSuffix, '_');
    $_diLastRunSuffix = $_diLastRunSuffix !== '' ? '_' . $_diLastRunSuffix : '';
}
$_diLastRunFile   = CRON_DIR . '/cron_dismantle_ticket' . $_diLastRunSuffix . '.lastrun';
$_diLastRun       = file_exists($_diLastRunFile) ? (int)trim(@file_get_contents($_diLastRunFile)) : 0;
$_diNextRun       = $_diLastRun + ($_diIntervalHours * 3600);
if (!$isManualRun && !$isDryRunArg && time() < $_diNextRun) {
    $minsLeft = (int)ceil(($_diNextRun - time()) / 60);
    echo '[INFO] Belum waktunya. Interval: ' . $_diIntervalHours . ' jam. Berikutnya: ' . $minsLeft . ' menit lagi.' . PHP_EOL;
    exit(0);
}

// -- Argument: --dry-run ----------------------------------------------------
$cronStartTime = microtime(true);
$dryRun = $isDryRunArg;

// -- Lock: cegah eksekusi bersamaan -----------------------------------------
$lockFp = @fopen(LOCK_FILE, 'c');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    echo '[WARNING] Cron sudah berjalan (lock file aktif). Keluar.' . PHP_EOL;
    exit(0);
}

// -- Tulis waktu terakhir jalan ----------------------------------------------
if (!$dryRun) {
    @file_put_contents($_diLastRunFile, time());
}

// -- Logging ------------------------------------------------------------------
function log_msg(string $msg): void
{
    // Rotasi log jika sudah terlalu besar
    if (file_exists(LOG_FILE) && filesize(LOG_FILE) >= MAX_LOG_SIZE) {
        rename(LOG_FILE, LOG_FILE . '.bak');
    }
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
    echo $line;
}

// -- Config & DB ------------------------------------------------------------
$configFile = CRON_BASE_DIR . '/config.json';
if (!file_exists($configFile)) {
    log_msg('[ERROR] config.json tidak ditemukan: ' . $configFile);
    flock($lockFp, LOCK_UN);
    exit(1);
}

$cfg = json_decode(file_get_contents($configFile), true);
if (!$cfg) {
    log_msg('[ERROR] Gagal parse config.json');
    flock($lockFp, LOCK_UN);
    exit(1);
}

// Koneksi billing DB
$connB = @mysqli_connect(
    $cfg['db_host']  ?? 'localhost',
    $cfg['db_user']  ?? '',
    $cfg['db_pass']  ?? '',
    $cfg['db_name']  ?? ''
);
if (!$connB) {
    log_msg('[ERROR] Gagal koneksi Billing DB: ' . mysqli_connect_error());
    flock($lockFp, LOCK_UN);
    exit(1);
}
mysqli_set_charset($connB, 'utf8mb4');

// Koneksi joblist/absensi DB
$connJ = @mysqli_connect(
    $cfg['db_host_absensi']  ?? 'localhost',
    $cfg['db_user_absensi']  ?? '',
    $cfg['db_pass_absensi']  ?? '',
    $cfg['db_name_absensi']  ?? ''
);
if (!$connJ) {
    log_msg('[ERROR] Gagal koneksi Joblist DB: ' . mysqli_connect_error());
    flock($lockFp, LOCK_UN);
    exit(1);
}
mysqli_set_charset($connJ, 'utf8mb4');

// -- Perluas enabledByList secara dinamis: kolom PEMILIK di tabel server adalah
// username API MikroTik PER ROUTER, bukan per akun - satu akun (satu user_id)
// bisa punya banyak server dengan nilai PEMILIK yang berbeda-beda. enabled_by
// di config_cron.json cuma snapshot PEMILIK yang tercatat SAAT toggle terakhir
// ditekan, jadi server/area baru yang ditambahkan sesudahnya tidak pernah ikut
// diproses cron sampai admin toggle off->on manual lagi (inilah sebab tiket
// DISMANTLE cuma keluar utk 1 area, tidak sebanyak "Expired LOS" di dashboard).
// Di sini kita resolve ulang tiap kali cron jalan: cari semua user_id pemilik
// server-server yang jadi seed, lalu ambil SEMUA PEMILIK milik user_id tsb -
// supaya server/area baru otomatis ikut ter-cover tanpa perlu toggle ulang.
function expandEnabledByOwners_cron($connB, array $seedPemilik): array
{
    $seedPemilik = array_values(array_unique(array_filter(array_map('trim', $seedPemilik), fn($p) => $p !== '')));
    if (empty($seedPemilik)) return $seedPemilik;

    $escaped = array_map(fn($p) => "'" . mysqli_real_escape_string($connB, $p) . "'", $seedPemilik);
    $userIds = [];
    $qOwners = mysqli_query($connB, 'SELECT DISTINCT user_id FROM server WHERE PEMILIK IN (' . implode(',', $escaped) . ')');
    while ($qOwners && ($r = mysqli_fetch_assoc($qOwners))) {
        $uid = (int)($r['user_id'] ?? 0);
        if ($uid > 0) $userIds[] = $uid;
    }
    if (empty($userIds)) return $seedPemilik;

    $result = $seedPemilik;
    $qAll = mysqli_query($connB, 'SELECT DISTINCT PEMILIK FROM server WHERE user_id IN (' . implode(',', $userIds) . ')');
    while ($qAll && ($r = mysqli_fetch_assoc($qAll))) {
        $p = trim((string)($r['PEMILIK'] ?? ''));
        if ($p !== '' && !in_array($p, $result, true)) $result[] = $p;
    }
    return $result;
}

$enabledByList = expandEnabledByOwners_cron($connB, $enabledByList);
log_msg('[INFO] PEMILIK setelah ekspansi kepemilikan akun: ' . implode(', ', $enabledByList));

// Buat SQL IN clause untuk filter PEMILIK (dibuat di sini, SETELAH ekspansi)
$pemilkEscaped = array_map(fn($p) => "'" . addslashes($p) . "'", $enabledByList);
$pemilkInClause = implode(',', $pemilkEscaped);

// RouterOS API (opsional, untuk cek online status)
$routerApiFile = CRON_BASE_DIR . '/routeros_api.class.php';
$hasRouterApi  = file_exists($routerApiFile);
if ($hasRouterApi) {
    require_once $routerApiFile;
}

// Library RADIUS (radiusReadMergedBlocks dkk) -- dipakai untuk pelanggan
// RADIUS MODE/MULTI MODE, lihat fungsi radiusExpiredFileBatch() & resolveCustomerStatusMode() di bawah.
$radiusSyncLibFile = CRON_BASE_DIR . '/radius_sync_lib.php';
if (file_exists($radiusSyncLibFile)) {
    require_once $radiusSyncLibFile;
}

/**
 * Cek status EXPIRED lewat atribut Mikrotik-Group di file users FreeRADIUS
 * (baca file LOKAL, `radiusReadMergedBlocks()` dari radius_sync_lib.php --
 * SAMA PERSIS sumber yg dipakai getdata/serverload.php utk widget "Expired
 * LOS" dashboard) untuk sekumpulan IDPEL yang tidak punya PPP secret lokal di
 * MikroTik (radius-only) atau MODE-nya RADIUS MODE/MULTI MODE. Pelanggan
 * begini tidak akan pernah muncul di /ppp/secret/print, jadi tidak bisa
 * dideteksi expired lewat situ.
 *
 * SENGAJA TIDAK menyentuh database `radiusq`/tabel `radacct` sama sekali --
 * itu aplikasi lain, bukan bagian dari sistem billing ini. Status ONLINE
 * pelanggan (termasuk yg radius-only) tetap 100% dari MikroTik API
 * (/ppp/active/print, dipoll di mt_getStatusCron()) persis seperti pelanggan
 * API MODE biasa -- sesi PPPoE yang diautentikasi lewat RADIUS di router yang
 * sama TETAP muncul di /ppp/active/print, jadi tidak butuh sumber lain.
 */
function radiusExpiredFileBatch(array $idpelList): array
{
    $expired = [];
    $idpelList = array_values(array_unique(array_filter(array_map('strval', $idpelList), static fn($v) => $v !== '')));
    if (empty($idpelList) || !function_exists('radiusReadMergedBlocks')) {
        return $expired;
    }

    $wanted = [];
    foreach ($idpelList as $id) {
        $wanted[strtolower($id)] = true;
    }
    foreach (radiusReadMergedBlocks() as $block) {
        $uname = $block['username'] ?? null;
        if ($uname === null) continue;
        $lk = strtolower($uname);
        if (!isset($wanted[$lk])) continue;
        if (preg_match('/Mikrotik-Group\s*:=\s*"([^"]+)"/', (string)$block['raw'], $m)) {
            if (strtoupper(trim($m[1])) === 'EXPIRED') {
                $expired[$lk] = true;
            }
        }
    }

    return $expired;
}

/**
 * Tentukan status ONLINE & EXPIRED final per pelanggan, apapun jenis
 * koneksinya (API MODE/RADIUS MODE/MULTI MODE/kosong) -- ONLINE selalu dari
 * MikroTik API (sama seperti pelanggan API MODE biasa), EXPIRED dari profile
 * PPP secret lokal KALAU ada secretnya utk username ini, atau dari atribut
 * Mikrotik-Group (file FreeRADIUS, $radiusExpiredSet) kalau tidak ada secret
 * lokal sama sekali/MODE eksplisit RADIUS-terkait -- supaya jumlah tiket
 * DISMANTLE tetap balance dgn "Expired LOS" & tiket MAINTENANCE balance dgn
 * "Internet LOS" di dashboard, APAPUN cara pelanggan konek.
 */
function resolveCustomerStatusMode(string $idpel, array $row, bool $mtOk, array $mtOnlineSet, array $mtExpiredSet, array $mtSecretSet, array $radiusExpiredSet): array
{
    $lk = strtolower($idpel);
    $modeUpper = strtoupper(trim((string)($row['MODE'] ?? '')));
    $hasLocalSecret = $mtOk && isset($mtSecretSet[$lk]);

    $online = $mtOk && isset($mtOnlineSet[$lk]);
    $apiExpired = $hasLocalSecret && isset($mtExpiredSet[$lk]);
    $radExpired = isset($radiusExpiredSet[$lk]);

    if ($modeUpper === 'MULTI MODE') {
        $expired = $apiExpired || $radExpired;
    } elseif ($modeUpper === 'RADIUS MODE' || !$hasLocalSecret) {
        $expired = $radExpired;
    } else {
        $expired = $apiExpired;
    }

    return ['ok' => $mtOk, 'online' => $online, 'expired' => $expired];
}

// -- Helper: tanggal bayar expression (sama dengan pelanggan_menunggak.php) ----
$trxTanggalExpr = "COALESCE(
    DATE(TANGGALBAYAR),
    STR_TO_DATE(TANGGALBAYAR, '%Y-%m-%d'),
    STR_TO_DATE(
      TRIM(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
        SUBSTRING_INDEX(TANGGALBAYAR, ',', -1),
        'Januari', '01'
      ), 'Februari', '02'), 'Maret', '03'), 'April', '04'), 'Mei', '05'), 'Juni', '06'), 'Juli', '07'), 'Agustus', '08'), 'September', '09'), 'Oktober', '10'), 'November', '11'), 'Desember', '12')),
      '%d %m %Y'
    )
  )";

// -- Fungsi-fungsi menunggak (disalin dari pelanggan_menunggak.php) -------------

function isSamePeriodAsToday_cron(string $dateValue, string $today): bool
{
    if (empty($dateValue)) return false;
    $tsDate  = strtotime($dateValue);
    $tsToday = strtotime($today);
    if ($tsDate === false || $tsToday === false) return false;
    return date('Y-m', $tsDate) === date('Y-m', $tsToday);
}

function shouldCountAsMenunggak_cron(array $row, string $today): bool
{
    $tipeBayar = strtolower(trim((string)($row['TIPE_BAYAR'] ?? 'prabayar')));
    if ($tipeBayar !== 'pascabayar') {
        if (isSamePeriodAsToday_cron((string)($row['TANGGALPASANG'] ?? ''), $today)) return false;
        if (isSamePeriodAsToday_cron((string)($row['last_paid'] ?? ''), $today)) return false;
        return true;
    }
    if (isSamePeriodAsToday_cron((string)($row['TANGGALPASANG'] ?? ''), $today)) return false;
    if (isSamePeriodAsToday_cron((string)($row['last_paid'] ?? ''), $today)) return false;
    return true;
}

function getMenunggakReferenceDate_cron(array $row): string
{
    $lastPaid = trim((string)($row['last_paid'] ?? ''));
    if ($lastPaid !== '' && strtotime($lastPaid) !== false) {
        return date('Y-m-d', strtotime($lastPaid));
    }
    $tanggalPasang = trim((string)($row['TANGGALPASANG'] ?? ''));
    if ($tanggalPasang === '' || strtotime($tanggalPasang) === false) return '';
    return date('Y-m-d', strtotime($tanggalPasang));
}

function getFixedDueDateDay_cron(string $username): int
{
    $defaultDay = 28;
    if ($username === '') return $defaultDay;
    $safeUsername = preg_replace('/[^a-zA-Z0-9_\-]/', '', $username);
    $fileReminder = CRON_BASE_DIR . '/notifbot/data/reminder-' . $safeUsername . '.json';
    if (!is_file($fileReminder)) return $defaultDay;
    $json = @file_get_contents($fileReminder);
    if ($json === false || $json === '') return $defaultDay;
    $data = json_decode($json, true);
    if (!is_array($data) || empty($data)) return $defaultDay;
    $day = isset($data[0]['jatuh_tempo']) ? (int)$data[0]['jatuh_tempo'] : 0;
    return ($day >= 1 && $day <= 31) ? $day : $defaultDay;
}

function buildMonthlyDate_cron(int $year, int $month, int $day): ?string
{
    if ($year < 1970 || $month < 1 || $month > 12) return null;
    if ($day < 1) $day = 1;
    $daysInMonth = (int)date('t', strtotime(sprintf('%04d-%02d-01', $year, $month)));
    if ($day > $daysInMonth) $day = $daysInMonth;
    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

function getTempoTypeValue_cron(array $row): string
{
    return strtolower(trim((string)($row['TIPE_TEMPO'] ?? 'mengikuti_tanggal_tempo')));
}

function parseIndoMonthYear_cron(string $value): ?array
{
    $raw = trim($value);
    if ($raw === '') {
        return null;
    }

    if (!preg_match('/^([A-Za-z]+)\s+(\d{4})$/', $raw, $m)) {
        return null;
    }

    $monthMap = [
        'januari' => 1,
        'februari' => 2,
        'maret' => 3,
        'april' => 4,
        'mei' => 5,
        'juni' => 6,
        'juli' => 7,
        'agustus' => 8,
        'september' => 9,
        'oktober' => 10,
        'november' => 11,
        'desember' => 12,
    ];

    $monthName = strtolower(trim((string)$m[1]));
    $year = (int)$m[2];
    if (!isset($monthMap[$monthName]) || $year < 1970) {
        return null;
    }

    return [
        'month' => (int)$monthMap[$monthName],
        'year' => $year,
    ];
}

function getFirstDueDateFixedByUsagePeriod_cron(array $row, int $fixedDueDay): ?string
{
    $tipeBayar = strtolower(trim((string)($row['TIPE_BAYAR'] ?? 'prabayar')));
    if ($tipeBayar !== 'prabayar') {
        return null;
    }

    if (getTempoTypeValue_cron($row) !== 'mengikuti_tanggal_tempo') {
        return null;
    }

    $parsed = parseIndoMonthYear_cron((string)($row['last_pengunaan'] ?? ''));
    if (!$parsed) {
        return null;
    }

    return buildMonthlyDate_cron((int)$parsed['year'], (int)$parsed['month'], $fixedDueDay);
}

function getFirstDueDateByTempoType_cron(array $row, string $referenceDate, int $fixedDueDay): ?string
{
    if (empty($referenceDate) || strtotime($referenceDate) === false) return null;
    $refTs     = strtotime($referenceDate);
    $tempoType = getTempoTypeValue_cron($row);
    if ($tempoType === 'mengikuti_tanggal_bayar') {
        // Rolling: siklus PERSIS 30 hari kalender, bukan +1 bulan.
        return date('Y-m-d', strtotime('+30 days', $refTs));
    }
    $cfgDay = ($fixedDueDay >= 1 && $fixedDueDay <= 31) ? $fixedDueDay : 28;
    // Untuk mode fixed tempo, due pertama selalu di bulan berikutnya.
    $nextMonthTs = strtotime('+1 month', $refTs);
    $year  = (int)date('Y', $nextMonthTs);
    $month = (int)date('m', $nextMonthTs);
    return buildMonthlyDate_cron($year, $month, $cfgDay);
}

function getNextDueDateByTempoType_cron(array $row, string $currentDueDate, int $fixedDueDay): ?string
{
    if (empty($currentDueDate) || strtotime($currentDueDate) === false) return null;

    $currentTs  = strtotime($currentDueDate);
    $tempoType  = getTempoTypeValue_cron($row);
    if ($tempoType === 'mengikuti_tanggal_bayar') {
        // Rolling: siklus PERSIS 30 hari kalender, bukan +1 bulan.
        return date('Y-m-d', strtotime('+30 days', $currentTs));
    }

    $cfgDay = ($fixedDueDay >= 1 && $fixedDueDay <= 31) ? $fixedDueDay : 28;
    $nextMonthTs = strtotime('+1 month', $currentTs);
    $year  = (int)date('Y', $nextMonthTs);
    $month = (int)date('m', $nextMonthTs);
    return buildMonthlyDate_cron($year, $month, $cfgDay);
}

function isDueDatePassedForRow_cron(array $row, string $today, int $fixedDueDay): bool
{
    $reference    = getMenunggakReferenceDate_cron($row);
    $firstDueDate = getFirstDueDateByTempoType_cron($row, $reference, $fixedDueDay);
    $dueByUsage = getFirstDueDateFixedByUsagePeriod_cron($row, $fixedDueDay);
    if (!empty($dueByUsage) && strtotime($dueByUsage) !== false) {
        $firstDueDate = $dueByUsage;
    }
    if (empty($firstDueDate) || strtotime($firstDueDate) === false) return false;
    return strtotime($firstDueDate) <= strtotime($today);
}

function isFasumNonPromo_cron(string $paketPelanggan, array $fasumPaketList, array $promoPaketIds): bool
{
    if ($paketPelanggan === '' || !isset($fasumPaketList[$paketPelanggan])) return false;
    $paketIdFasum = (string)$fasumPaketList[$paketPelanggan];
    return !in_array($paketIdFasum, $promoPaketIds, true);
}

function resolveHargaPaket_cron(array $hargaPaketMap, string $paket, string $brand, string $area): ?string
{
    foreach ([
        $paket . '|' . $brand . '|' . $area,
        $paket . '||' . $area,
        $paket . '|' . $brand . '|',
        $paket . '||',
        $paket,
    ] as $key) {
        if (isset($hargaPaketMap[$key])) return $hargaPaketMap[$key];
    }
    return null;
}

function hasSuccessfulPaymentInPeriod_cron(mysqli $connB, string $idpel, string $startDate, string $endDate, string $trxTanggalExpr): bool
{
    if ($idpel === '' || $startDate === '' || $endDate === '') {
        return false;
    }

    if (strtotime($startDate) === false || strtotime($endDate) === false) {
        return false;
    }

    $idpelEsc = mysqli_real_escape_string($connB, $idpel);
    $startEsc = mysqli_real_escape_string($connB, $startDate);
    $endEsc = mysqli_real_escape_string($connB, $endDate);

    $sql = 'SELECT 1 FROM transaksi WHERE IDPEL = \'' . $idpelEsc . '\' AND STATUS = \'BERHASIL\' AND DATE(' . $trxTanggalExpr . ") >= '" . $startEsc . "' AND DATE(" . $trxTanggalExpr . ") < '" . $endEsc . "' LIMIT 1";
    $q = mysqli_query($connB, $sql);

    return (bool)($q && mysqli_fetch_assoc($q));
}

function getBulanNunggakConsecutive_cron(mysqli $connB, array $row, string $today, int $fixedDueDay, string $trxTanggalExpr): int
{
    $idpel = trim((string)($row['IDPEL'] ?? ''));
    if ($idpel === '') return 0;

    $todayTs       = strtotime($today);
    $referenceDate = getMenunggakReferenceDate_cron($row);
    $nextDueDate   = getFirstDueDateByTempoType_cron($row, $referenceDate, $fixedDueDay);
    $dueByUsage = getFirstDueDateFixedByUsagePeriod_cron($row, $fixedDueDay);
    if (!empty($dueByUsage) && strtotime($dueByUsage) !== false) {
        $nextDueDate = $dueByUsage;
    }
    if (empty($nextDueDate) || strtotime($nextDueDate) === false || $todayTs === false) return 0;

    $bulanTunggak = 0;

    while (strtotime($nextDueDate) !== false && strtotime($nextDueDate) <= $todayTs) {
        $cycleStart = $nextDueDate;
        $cycleEnd   = getNextDueDateByTempoType_cron($row, $cycleStart, $fixedDueDay);

        if (empty($cycleEnd) || strtotime($cycleEnd) === false) {
            break;
        }

        if (hasSuccessfulPaymentInPeriod_cron($connB, $idpel, $cycleStart, $cycleEnd, $trxTanggalExpr)) {
            // Selaras pelanggan_menunggak: ada pembayaran dalam salah satu siklus => bukan menunggak beruntun.
            return 0;
        }

        $bulanTunggak++;
        $nextDueDate = $cycleEnd;
    }

    return $bulanTunggak;
}

// -- MULAI EKSEKUSI ------------------------------------------------------------
$today = date('Y-m-d');
$now   = date('Y-m-d H:i:s');
log_msg('=== Cron Dismantle Ticket Mulai: ' . $now . ($dryRun ? ' [DRY-RUN]' : '') . ' ===');
log_msg('[INFO] Diproses untuk PEMILIK: ' . implode(', ', $enabledByList));

// -- Step 1: Load paket map (untuk cek fasum/promo) -----------------------------
$hargaPaketMap  = [];
$fasumPaketList = [];
$promoPaketIds  = [];

$qPaket = mysqli_query($connB, "SELECT id, PAKET, HARGA, BRAND, AREA FROM paket");
while ($qPaket && ($r = mysqli_fetch_assoc($qPaket))) {
    $pk  = strtolower(trim((string)$r['PAKET']));
    $bk  = strtolower(trim((string)($r['BRAND'] ?? '')));
    $ak  = strtolower(trim((string)($r['AREA']  ?? '')));
    $hargaPaketMap[$pk . '|' . $bk . '|' . $ak] = $r['HARGA'];
    if ($r['HARGA'] === '' || (float)$r['HARGA'] <= 0) {
        $fasumPaketList[$pk] = (string)$r['id'];
    }
}
$qPromo = mysqli_query($connB, "SELECT paket_id FROM promo_paket");
while ($qPromo && ($r = mysqli_fetch_assoc($qPromo))) {
    $promoPaketIds[] = (string)$r['paket_id'];
}

// -- Step 2: Load semua server (IP, PEMILIK, PASSWORD, AREA, username pemilik) -
// Diindeks sebagai [PEMILIK|AREA => row] � HANYA yang enabled
$serverMap = [];  // key: "PEMILIK|AREA"
$usernamByUserId = [];  // user_id => username (untuk getFixedDueDateDay)
$qServer = mysqli_query($connB,
    "SELECT s.id, s.IP, s.PEMILIK, s.PASSWORD, s.AREA, s.user_id, u.username
     FROM server s LEFT JOIN user u ON s.user_id = u.id
     WHERE s.PEMILIK IN ($pemilkInClause)
     ORDER BY s.id DESC"
);
while ($qServer && ($r = mysqli_fetch_assoc($qServer))) {
    $key = (string)$r['PEMILIK'] . '|' . (string)($r['AREA'] ?? '');
    if (!isset($serverMap[$key])) {
        $serverMap[$key] = $r;
    }
    $uid = (int)$r['user_id'];
    if (!isset($usernamByUserId[$uid]) && !empty($r['username'])) {
        $usernamByUserId[$uid] = (string)$r['username'];
    }
}
log_msg('[INFO] Server dimuat: ' . count($serverMap) . ' entri');

// -- Step 3: Hitung fixedDueDateDay per PEMILIK ----------------------------------
// Prioritas: baca dari reminder-USERNAME.json milik user pemilik server
$fixedDayByPemilik = [];  // PEMILIK => int
foreach ($serverMap as $key => $srv) {
    $pemilik = (string)$srv['PEMILIK'];
    if (isset($fixedDayByPemilik[$pemilik])) continue;
    $uid      = (int)($srv['user_id'] ?? 0);
    $username = $usernamByUserId[$uid] ?? '';
    $fixedDayByPemilik[$pemilik] = getFixedDueDateDay_cron($username);
}

// -- Step 4: Load semua pelanggan dengan last_paid -------------------------------
$allPelanggan = [];  // IDPEL => row
$qPel = mysqli_query($connB,
    "SELECT p.IDPEL, p.NAMA, p.PAKET, p.PEMILIK, p.BRAND, p.AREA,
            p.NOWA, p.ALAMAT, p.EMAIL, p.ODP, p.MODE,
            p.TANGGALPASANG, p.TEMPO, p.TIPE_BAYAR, p.TIPE_TEMPO,
            (
                SELECT $trxTanggalExpr
                FROM transaksi tx
                WHERE tx.IDPEL = p.IDPEL AND tx.STATUS = 'BERHASIL'
                ORDER BY $trxTanggalExpr DESC
                LIMIT 1
            ) AS last_paid,
            (
                SELECT tx.PENGUNAAN
                FROM transaksi tx
                WHERE tx.IDPEL = p.IDPEL AND tx.STATUS = 'BERHASIL'
                ORDER BY $trxTanggalExpr DESC
                LIMIT 1
            ) AS last_pengunaan
     FROM pelanggan p
     WHERE p.PEMILIK IN ($pemilkInClause)"
);
while ($qPel && ($r = mysqli_fetch_assoc($qPel))) {
    $allPelanggan[(string)$r['IDPEL']] = $r;
}
log_msg('[INFO] Pelanggan dimuat: ' . count($allPelanggan));

// -- Step 4b: Status EXPIRED via file FreeRADIUS untuk SEMUA pelanggan ----------
// Dihitung sekali di sini (bukan per-server seperti MikroTik) karena file users
// FreeRADIUS sifatnya terpusat (satu file untuk semua server), lalu dipakai
// lewat resolveCustomerStatusMode() di Step 7 & 9. SENGAJA dicek untuk SEMUA
// pelanggan (bukan cuma yang MODE-nya sudah ditandai RADIUS MODE/MULTI MODE)
// -- resolveCustomerStatusMode() akan otomatis memakai hasil ini sebagai
// fallback kalau ternyata username ybs tidak terdaftar sebagai PPP secret
// lokal di router manapun, terlepas dari isi kolom MODE. TIDAK ada koneksi ke
// database/aplikasi lain di sini -- murni baca file lokal.
$radiusExpiredSet = !empty($allPelanggan) ? radiusExpiredFileBatch(array_keys($allPelanggan)) : [];
log_msg('[INFO] Status EXPIRED via file FreeRADIUS dicek untuk ' . count($allPelanggan) . ' pelanggan (' . count($radiusExpiredSet) . ' expired)');

// -- Step 5: Load semua tiket DISMANTLE aktif (BARU/PENDING) --------------------
$activeTicketRows = []; // list tiket mentah untuk deduplikasi
$useTicketManager = true;
$enabledUserEscaped = array_map(static fn($u) => "'" . mysqli_real_escape_string($connB, (string)$u) . "'", $enabledByList);
if (!empty($enabledUserEscaped)) {
    $qMode = mysqli_query($connB, "SELECT STATUS, ticket_management_source FROM user WHERE USERNAME IN (" . implode(',', $enabledUserEscaped) . ")");
    while ($qMode && ($modeRow = mysqli_fetch_assoc($qMode))) {
        $statusRow = strtoupper(trim((string)($modeRow['STATUS'] ?? '')));
        $srcRow = strtolower(trim((string)($modeRow['ticket_management_source'] ?? 'tiket_manager')));
        if ($statusRow === 'ADMIN' && $srcRow === 'joblist') {
            $useTicketManager = false;
            break;
        }
    }
}
$ticketConn = $useTicketManager ? $connB : $connJ;
$ticketTable = $useTicketManager ? 'billing_tiket_manager' : 'joblist';
$ticketDataCol = $useTicketManager ? 'detail' : 'data';
$ticketProjectCol = $useTicketManager ? 'pemilik' : 'project';
$qTickets = mysqli_query($ticketConn,
    "SELECT id, $ticketDataCol AS data, status, tipe, $ticketProjectCol AS project FROM $ticketTable
     WHERE tipe = 'DISMANTLE' AND status IN ('BARU','PENDING')"
);
while ($qTickets && ($r = mysqli_fetch_assoc($qTickets))) {
    // Tanpa ^ supaya cocok dengan tiket dari web UI (ada header sebelum ID PELANGGAN)
    if (preg_match('/ID PELANGGAN\s*:([^\n]+)/i', (string)$r['data'], $m)) {
        $idpel = trim($m[1]);
        if ($idpel !== '') {
            $r['__idpel'] = $idpel;
            $activeTicketRows[] = $r;
        }
    }
}
log_msg('[INFO] Tiket DISMANTLE aktif ditemukan: ' . count($activeTicketRows));

// -- Step 6: Klasifikasi pelanggan: expired vs tidak expired --------------------
$expiredRows    = [];  // IDPEL => row
$notExpiredRows = [];  // IDPEL => row

foreach ($allPelanggan as $idpel => $row) {
    $pemilik    = (string)($row['PEMILIK'] ?? '');
    $fixedDay   = $fixedDayByPemilik[$pemilik] ?? 28;
    $paket      = strtolower(trim((string)($row['PAKET']  ?? '')));
    $brand      = strtolower(trim((string)($row['BRAND']  ?? '')));
    $area       = strtolower(trim((string)($row['AREA']   ?? '')));

    // Skip paket gratis (fasum non-promo)
    if (isFasumNonPromo_cron($paket, $fasumPaketList, $promoPaketIds)) continue;

    // Tidak menunggak = belum expired
    if (!shouldCountAsMenunggak_cron($row, $today)) {
        $notExpiredRows[$idpel] = $row;
        continue;
    }

    // Jatuh tempo belum lewat = belum expired
    if (!isDueDatePassedForRow_cron($row, $today, $fixedDay)) {
        $notExpiredRows[$idpel] = $row;
        continue;
    }

    // Harus punya harga paket > 0
    $harga = resolveHargaPaket_cron($hargaPaketMap, $paket, $brand, $area);
    if ($harga === null || (float)$harga <= 0) {
        $notExpiredRows[$idpel] = $row;
        continue;
    }

    // Samakan dengan tabel Pelanggan Menunggak: harus nunggak berurutan minimal 1 bulan
    $bulanNunggak = getBulanNunggakConsecutive_cron($connB, $row, $today, $fixedDay, $trxTanggalExpr);
    if ($bulanNunggak < 1) {
        $notExpiredRows[$idpel] = $row;
        continue;
    }

    $expiredRows[$idpel] = $row;
}

log_msg('[INFO] Expired (menunggak): ' . count($expiredRows) . ', Tidak expired: ' . count($notExpiredRows));

// -- Step 7: AKSI 1 � Buat tiket DISMANTLE untuk pelanggan expired yang OFFLINE -
$statCreated       = 0;
$statSkipped       = 0;
$statSkippedOnline = 0;
$statCancelled     = 0;
$statErrors        = 0;
$statDedupDeleted  = 0;
$statSkipMissing   = 0;

// -- Step 6a: Deduplikasi tiket DISMANTLE aktif
// Kriteria duplikat: project(server) + tipe + status + IDPEL sama. Sisakan 1.
$seenTicketKey = [];
$activeTickets = []; // IDPEL => ticket canonical
foreach ($activeTicketRows as $ticket) {
    $idpel = (string)($ticket['__idpel'] ?? '');
    if ($idpel === '') {
        continue;
    }

    $project = strtoupper(trim((string)($ticket['project'] ?? '')));
    $status  = strtoupper(trim((string)($ticket['status'] ?? '')));
    $ticketKey = $project . '|DISMANTLE|' . $status . '|' . strtoupper($idpel);

    if (isset($seenTicketKey[$ticketKey])) {
        $dupId = (int)($ticket['id'] ?? 0);
        if ($dupId > 0) {
            if ($dryRun) {
                $statDedupDeleted++;
                log_msg("[DRY-RUN] HAPUS tiket duplikat DISMANTLE #{$dupId}");
            } else {
                if (mysqli_query($ticketConn, "DELETE FROM $ticketTable WHERE id={$dupId} LIMIT 1")) {
                    $statDedupDeleted++;
                    log_msg("[DEDUP] HAPUS tiket duplikat DISMANTLE #{$dupId}");
                } else {
                    $statErrors++;
                    log_msg('[ERROR] Gagal hapus tiket duplikat #' . $dupId . ': ' . mysqli_error($ticketConn));
                }
            }
        }
        continue;
    }

    $seenTicketKey[$ticketKey] = true;
    if (!isset($activeTickets[$idpel])) {
        $activeTickets[$idpel] = $ticket;
    }
}

log_msg('[INFO] Tiket DISMANTLE aktif setelah dedup: ' . count($activeTickets));

// -- Cache koneksi MikroTik per server, dipakai bersama Step 7 & Step 9 ---------
// $mikrotikCache[$serverKey] = ['ok' => bool, 'set' => [lowercase idpel => true (ONLINE)],
//                                'expiredSet' => [lowercase idpel => true (profile == EXPIRED)]]
//
// PENTING: "expiredSet" di sini dibaca LANGSUNG dari profile PPP secret router
// (/ppp/secret/print, profile === 'EXPIRED'), BUKAN dari hasil hitung "menunggak"
// tabel billing ($expiredRows di atas). Ini SENGAJA disamakan dengan definisi
// "Expired" yang dipakai widget dashboard (getdata/serverload.php) supaya jumlah
// tiket DISMANTLE selalu sama dengan jumlah "Expired LOS" di dashboard -- kalau
// dulu pakai status menunggak billing, pelanggan yg SUDAH bayar tapi profile
// router belum sempat dipulihkan (atau sebaliknya, menunggak tapi belum sempat
// diisolir cron tagihan) bisa bikin jumlahnya beda dari dashboard.
$mikrotikCache = [];

function mt_getStatusCron(string $serverKey, array $serverMap, bool $hasRouterApi, array &$mikrotikCache): array
{
    if (isset($mikrotikCache[$serverKey])) {
        return $mikrotikCache[$serverKey];
    }

    $result = ['ok' => false, 'set' => [], 'expiredSet' => [], 'secretSet' => []];

    if ($hasRouterApi && isset($serverMap[$serverKey])) {
        $srv = $serverMap[$serverKey];
        $ip  = trim((string)($srv['IP']       ?? ''));
        $usr = trim((string)($srv['PEMILIK']  ?? ''));
        $pwd = trim((string)($srv['PASSWORD'] ?? ''));

        if ($ip !== '' && $usr !== '' && $pwd !== '') {
            try {
                $api = new RouterosAPI();
                $api->debug = false;

                if ($api->connect($ip, $usr, $pwd)) {
                    $result['ok'] = true;

                    $secrets = $api->comm('/ppp/secret/print');
                    foreach ($secrets as $sec) {
                        $secName = strtolower(trim((string)($sec['name'] ?? '')));
                        $secProfile = trim((string)($sec['profile'] ?? ''));
                        if ($secName === '') continue;
                        $result['secretSet'][$secName] = true;
                        if (strtoupper($secProfile) === 'EXPIRED') {
                            $result['expiredSet'][$secName] = true;
                        }
                    }

                    $activeSessions = $api->comm('/ppp/active/print');
                    foreach ($activeSessions as $sess) {
                        $sessName = strtolower(trim((string)($sess['name'] ?? '')));
                        if ($sessName !== '') {
                            $result['set'][$sessName] = true;
                        }
                    }
                    if (method_exists($api, 'disconnect')) {
                        $api->disconnect();
                    }
                    log_msg('[INFO] MikroTik ' . $ip . ' (' . $serverKey . '): ' . count($result['set']) . ' sesi aktif, ' . count($result['expiredSet']) . ' profile EXPIRED');
                } else {
                    log_msg('[WARNING] Gagal konek MikroTik ' . $ip . ' (' . $serverKey . ')');
                }
            } catch (Throwable $e) {
                log_msg('[WARNING] Error MikroTik ' . $serverKey . ': ' . $e->getMessage());
            }
        }
    }

    $mikrotikCache[$serverKey] = $result;
    return $result;
}

// Kelompokkan SEMUA pelanggan per server (bukan cuma yg "menunggak" versi billing)
// -- kelayakan tiket DISMANTLE sekarang murni ditentukan dari profile MikroTik
// live (lihat mt_getStatusCron), jadi semua pelanggan perlu dicek statusnya.
$expiredByServerKey = [];
foreach ($allPelanggan as $idpel => $row) {
    $key = (string)($row['PEMILIK'] ?? '') . '|' . (string)($row['AREA'] ?? '');
    $expiredByServerKey[$key][$idpel] = $row;
}

foreach ($expiredByServerKey as $serverKey => $rowsInServer) {
    $mtInfo     = mt_getStatusCron($serverKey, $serverMap, $hasRouterApi, $mikrotikCache);
    $onlineOk   = $mtInfo['ok'];
    $onlineSet  = $mtInfo['set'];
    $expiredSet = $mtInfo['expiredSet'];
    $secretSet  = $mtInfo['secretSet'];

    foreach ($rowsInServer as $idpel => $row) {
        // Tiket DISMANTLE HANYA dibuat utk pelanggan yg profile MikroTik-nya
        // SUDAH "EXPIRED" (live-check /ppp/secret/print, SAMA dgn definisi
        // "Expired" di dashboard) DAN terkonfirmasi OFFLINE/LOS. Belum
        // ke-isolir (profile bukan EXPIRED) -> lewati, itu urusan cron
        // tagihan, bukan cron ini.
        $statusC = resolveCustomerStatusMode($idpel, $row, $onlineOk, $onlineSet, $expiredSet, $secretSet, $radiusExpiredSet);
        if (!$statusC['ok']) {
            $statSkippedOnline++;
            log_msg("[SKIP] IDPEL=$idpel tidak diproses karena status profile/online tidak diketahui (MikroTik/RADIUS tidak bisa diakses)");
            continue;
        }
        $isExpiredProfile = $statusC['expired'];
        if (!$isExpiredProfile) {
            continue; // belum di-isolir (profile bukan EXPIRED) -> bukan urusan cron ini
        }
        $isOnline = $statusC['online'];
        if ($isOnline === true) {
            $statSkippedOnline++;
            log_msg("[SKIP] IDPEL=$idpel expired tapi masih ONLINE � tiket DISMANTLE tidak dibuat");
            continue;
        }
        // profile EXPIRED + terkonfirmasi OFFLINE/LOS -> lanjut proses buat tiket

        // Sudah punya tiket aktif ? skip
        if (isset($activeTickets[$idpel])) {
            $statSkipped++;
            continue;
        }

        // Double-check ke DB (hindari race condition)
        $idpelEsc = mysqli_real_escape_string($ticketConn, $idpel);
        $cekRes = mysqli_query($ticketConn,
            "SELECT id FROM $ticketTable
             WHERE $ticketDataCol LIKE '%ID PELANGGAN :$idpelEsc%'
               AND tipe = 'DISMANTLE'
               AND status IN ('BARU','PENDING')
             LIMIT 1"
        );
        if ($cekRes && mysqli_num_rows($cekRes) > 0) {
            $statSkipped++;
            continue;
        }

        $nama    = (string)($row['NAMA']    ?? '');
        $odp     = (string)($row['ODP']     ?? '');
        $email   = (string)($row['EMAIL']   ?? '');
        $alamat  = (string)($row['ALAMAT']  ?? '');
        $nowa    = (string)($row['NOWA']    ?? '');
        $pemilik = (string)($row['PEMILIK'] ?? '');
        $kendala = 'Pelanggan menunggak - otomatis sistem';

        // Format data tiket � sama dengan buat_tiket.php agar konsisten
        $data = "===============\nTiket DISMANTLE dari billing\n===============\nID PELANGGAN :$idpel\nNAMA PELANGGAN :$nama\nODP :$odp\nEMAIL :$email\nALAMAT :$alamat\nNO WA :$nowa\nKENDALA :$kendala";

        if ($dryRun) {
            $statCreated++;
            log_msg("[DRY-RUN] BUAT tiket DISMANTLE untuk IDPEL=$idpel NAMA=$nama (offline)");
            continue;
        }

        if ($useTicketManager) {
            $srvRef = $serverMap[$serverKey] ?? null;
            $serverId = (int)($srvRef['id'] ?? 0);
            if ($serverId <= 0) {
                $statErrors++;
                log_msg('[ERROR] Gagal buat tiket untuk IDPEL=' . $idpel . ': server_id tidak ditemukan');
                continue;
            }

            $judul = mysqli_real_escape_string($ticketConn, 'DISMANTLE - ' . $idpel . ' - ' . $nama);
            $detailEsc = mysqli_real_escape_string($ticketConn, $data);
            $pemilikEsc = mysqli_real_escape_string($ticketConn, $pemilik);
            $brandEsc = mysqli_real_escape_string($ticketConn, (string)($row['BRAND'] ?? ''));
            $areaEsc = mysqli_real_escape_string($ticketConn, (string)($row['AREA'] ?? ''));
            $projectName = trim((string)($row['BRAND'] ?? '') . ' - ' . (string)($row['AREA'] ?? ''));
            if ($projectName === '' || $projectName === '-') {
                $projectName = $pemilik;
            }
            $projectEsc = mysqli_real_escape_string($ticketConn, $projectName);
            $createdBy = (int)($srvRef['user_id'] ?? 0);
            $ins = "INSERT INTO billing_tiket_manager (judul, detail, server_id, pemilik, brand, area, project_name, tipe, report, status, teknisi_user_id, created_by_user_id) VALUES ('$judul', '$detailEsc', $serverId, '$pemilikEsc', '$brandEsc', '$areaEsc', '$projectEsc', 'DISMANTLE', '', 'BARU', NULL, $createdBy)";
        } else {
            $dataEsc    = mysqli_real_escape_string($ticketConn, $data);
            $nowaEsc    = mysqli_real_escape_string($ticketConn, $nowa);
            $pemilikEsc = mysqli_real_escape_string($ticketConn, $pemilik);

            $ins = "INSERT INTO joblist (tgl, status, nowa, data, project, report, team, tipe)
                    VALUES ('$today', 'BARU', '$nowaEsc', '$dataEsc', '$pemilikEsc', '', '', 'DISMANTLE')";
        }

        if (mysqli_query($ticketConn, $ins)) {
            $statCreated++;
            log_msg("[BUAT] Tiket DISMANTLE dibuat ? IDPEL=$idpel NAMA=$nama (offline)");
        } else {
            $statErrors++;
            log_msg('[ERROR] Gagal buat tiket untuk IDPEL=' . $idpel . ': ' . mysqli_error($ticketConn));
        }
    }
}

// -- Step 8: AKSI 2/3 -- Pastikan tiket DISMANTLE terbuka SELALU SAMA dgn
// pelanggan expired+LOS (invarian yg diminta: jumlah tiket DST harus persis
// sama dgn "Expired LOS" di dashboard). Satu-satunya kondisi yg BOLEH tetap
// punya tiket terbuka: masih menunggak DAN terkonfirmasi OFFLINE/LOS.
// Semua kondisi lain (sudah bayar, atau online, atau status tidak diketahui)
// -> tiketnya DIBATALKAN, supaya tidak ada tiket "nyangkut" yg bikin jumlah
// DST beda dari jumlah Expired LOS yg sebenarnya.
// Kumpulkan SEMUA tiket aktif (bukan cuma yg sudah tidak expired) utk dicek ulang.
$ticketsToProcess = [];  // IDPEL => ['ticket' => ..., 'row' => ...]
foreach ($activeTickets as $idpel => $ticket) {
    $row = $allPelanggan[$idpel] ?? null;
    if ($row === null) {
        $tid = (int)($ticket['id'] ?? 0);
        $statSkipMissing++;
        log_msg("[SKIP] Tiket #{$tid} IDPEL=$idpel (pelanggan tidak ditemukan)");
        continue;
    }

    $ticketsToProcess[$idpel] = ['ticket' => $ticket, 'row' => $row];
}

// -- Step 9: Cek status profile+online via MikroTik, dikelompokkan per server --
// Group by "PEMILIK|AREA" agar koneksi ke MikroTik hanya sekali per router
$byServerKey = [];
foreach ($ticketsToProcess as $idpel => $item) {
    $pemilik = (string)($item['row']['PEMILIK'] ?? '');
    $area    = (string)($item['row']['AREA']    ?? '');
    $key     = $pemilik . '|' . $area;
    $byServerKey[$key][$idpel] = $item;
}

foreach ($byServerKey as $serverKey => $items) {
    $mtInfo     = mt_getStatusCron($serverKey, $serverMap, $hasRouterApi, $mikrotikCache);
    $mikrotikOK = $mtInfo['ok'];
    $onlineSet  = $mtInfo['set'];
    $expiredSet = $mtInfo['expiredSet'];
    $secretSet  = $mtInfo['secretSet'];

    // Proses setiap tiket dalam grup ini
    foreach ($items as $idpel => $item) {
        $ticket   = $item['ticket'];
        $ticketId = (int)$ticket['id'];

        $statusC = resolveCustomerStatusMode($idpel, $item['row'], $mikrotikOK, $onlineSet, $expiredSet, $secretSet, $radiusExpiredSet);
        if (!$statusC['ok']) {
            // Status profile/online tidak diketahui (MikroTik/RADIUS tidak bisa
            // diakses) -> jangan ambil aksi (aman: tiket dibiarkan apa adanya
            // sampai bisa dicek ulang).
            log_msg("[SKIP] Tiket #{$ticketId} IDPEL=$idpel tidak diproses karena status tidak diketahui (MikroTik/RADIUS tidak bisa diakses)");
            continue;
        }

        // "Masih expired" sekarang = profile MikroTik/RADIUS live MASIH 'EXPIRED'
        // (sama dgn definisi dashboard), BUKAN status menunggak billing.
        $masihExpired = $statusC['expired'];
        $isOnline     = $statusC['online'];

        // Satu-satunya kondisi valid utk TETAP punya tiket: profile masih EXPIRED DAN terkonfirmasi offline.
        $shouldKeepTicket = $masihExpired && !$isOnline;
        if ($shouldKeepTicket) {
            continue; // sesuai (expired + LOS), tidak perlu tindakan apa pun
        }

        if ($masihExpired) {
            $reason = 'profile masih EXPIRED tapi sudah ONLINE';
        } else {
            $reason = $isOnline ? 'profile sudah tidak EXPIRED & online' : 'profile sudah tidak EXPIRED (sudah dipulihkan)';
        }

        if ($dryRun) {
            $statCancelled++;
            log_msg("[DRY-RUN] CANCEL tiket #{$ticketId} IDPEL=$idpel ($reason)");
        } else {
            if (mysqli_query($ticketConn, "UPDATE $ticketTable SET status='CANCEL' WHERE id=$ticketId")) {
                $statCancelled++;
                log_msg("[CANCEL] Tiket #{$ticketId} IDPEL=$idpel dibatalkan ($reason)");
            } else {
                $statErrors++;
                log_msg('[ERROR] Gagal cancel tiket #' . $ticketId . ': ' . mysqli_error($ticketConn));
            }
        }
    }
}

// -- Selesai --------------------------------------------------------------------
$elapsed = round(microtime(true) - $cronStartTime, 2);
log_msg(
    "=== SELESAI" . ($dryRun ? ' [DRY-RUN]' : '') . " ===" .
    " Dibuat=$statCreated" .
    " SkipOnline=$statSkippedOnline" .
    " Skip(sudah_ada)=$statSkipped" .
    " DedupHapus=$statDedupDeleted" .
    " SkipMissing=$statSkipMissing" .
    " Dibatalkan=$statCancelled" .
    " Error=$statErrors" .
    " Waktu={$elapsed}s ==="
);

mysqli_close($connB);
mysqli_close($connJ);

flock($lockFp, LOCK_UN);
fclose($lockFp);
@unlink(LOCK_FILE);

exit($statErrors > 0 ? 1 : 0);