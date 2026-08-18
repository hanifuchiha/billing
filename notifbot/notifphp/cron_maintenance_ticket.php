<?php
// DIAGNOSA SEMENTARA - hapus setelah error diketahui
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);



@set_time_limit(0);
if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

defined('CRON_BASE_DIR') || define('CRON_BASE_DIR', dirname(__DIR__, 2));
defined('CRON_DIR')      || define('CRON_DIR',      __DIR__);
defined('LOG_FILE')      || define('LOG_FILE',      __DIR__ . '/log_maintenance_ticket.log');
defined('LOCK_FILE')     || define('LOCK_FILE',     __DIR__ . '/cron_maintenance_ticket.lock');
defined('MAX_LOG_SIZE')  || define('MAX_LOG_SIZE',  2 * 1024 * 1024); // 2 MB
defined('ODP_TICKET_PREFIX') || define('ODP_TICKET_PREFIX', 'ODP-'); // penanda tiket konsolidasi ODP

// -- Optional arg: --pemilik=XXX (untuk file wrapper per PEMILIK) --------------
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

// -- Toggle on/off & Get enabled PEMILIK list ----------------------------------
$cronConfigFile = CRON_DIR . '/config_cron.json';
$cronConfig = file_exists($cronConfigFile)
    ? (json_decode(file_get_contents($cronConfigFile), true) ?? [])
    : [];

$cronConfigMaint = is_array($cronConfig['cron_maintenance_ticket'] ?? null)
    ? $cronConfig['cron_maintenance_ticket']
    : ['enabled_by' => [], 'interval_hours' => 2];

$enabledByList = ($forcedPemilik !== '')
    ? [$forcedPemilik]
    : (array)($cronConfigMaint['enabled_by'] ?? []);
if (empty($enabledByList)) {
    echo '[INFO] Cron cron_maintenance_ticket DISABLED (tidak ada PEMILIK yang mengaktifkan).' . PHP_EOL;
    echo '[INFO] Aktifkan via UI: tables.php toggle maintenance cron' . PHP_EOL;
    exit(0);
}

// -- Interval check --------------------------------------------------------------
$_miIntervalHours = max(1, (int)($cronConfigMaint['interval_hours'] ?? 2));
$_miLastRunSuffix = '';
if ($forcedPemilik !== '') {
    $_miLastRunSuffix = '_' . preg_replace('/[^A-Z0-9]+/', '_', strtoupper($forcedPemilik));
    $_miLastRunSuffix = trim((string)$_miLastRunSuffix, '_');
    $_miLastRunSuffix = $_miLastRunSuffix !== '' ? '_' . $_miLastRunSuffix : '';
}
$_miLastRunFile   = CRON_DIR . '/cron_maintenance_ticket' . $_miLastRunSuffix . '.lastrun';
$_miLastRun       = file_exists($_miLastRunFile) ? (int)trim(@file_get_contents($_miLastRunFile)) : 0;
$_miNextRun       = $_miLastRun + ($_miIntervalHours * 3600);
if (!$isManualRun && !$isDryRunArg && time() < $_miNextRun) {
    $minsLeft = (int)ceil(($_miNextRun - time()) / 60);
    echo '[INFO] Belum waktunya. Interval: ' . $_miIntervalHours . ' jam. Berikutnya: ' . $minsLeft . ' menit lagi.' . PHP_EOL;
    exit(0);
}

// -- Argument: --dry-run -----------------------------------------------------------
$cronStartTime = microtime(true);
$dryRun = $isDryRunArg;

// -- Lock: cegah eksekusi bersamaan -------------------------------------------------
$lockFp = @fopen(LOCK_FILE, 'c');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    echo '[WARNING] Cron sudah berjalan (lock file aktif). Keluar.' . PHP_EOL;
    exit(0);
}

// -- Tulis waktu terakhir jalan ------------------------------------------------------
if (!$dryRun) {
    @file_put_contents($_miLastRunFile, time());
}

// -- Logging --------------------------------------------------------------------------
function mlog(string $msg): void
{
    if (file_exists(LOG_FILE) && filesize(LOG_FILE) >= MAX_LOG_SIZE) {
        rename(LOG_FILE, LOG_FILE . '.bak');
    }
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
    echo $line;
}

// -- Config & DB ------------------------------------------------------------------------
$configFile = CRON_BASE_DIR . '/config.json';
if (!file_exists($configFile)) {
    mlog('[ERROR] config.json tidak ditemukan: ' . $configFile);
    flock($lockFp, LOCK_UN);
    exit(1);
}
mlog('[DEBUG] File config.json ditemukan. Memulai parsing.');
$cfg = json_decode(file_get_contents($configFile), true);
if (!$cfg) {
    mlog('[ERROR] Gagal parse config.json.');
    flock($lockFp, LOCK_UN);
    exit(1);
}
mlog('[DEBUG] Parsing config.json berhasil. Memulai proses utama.');

$connB = @mysqli_connect($cfg['db_host'] ?? 'localhost', $cfg['db_user'] ?? '', $cfg['db_pass'] ?? '', $cfg['db_name'] ?? '');
if (!$connB) { mlog('[ERROR] Gagal koneksi Billing DB: ' . mysqli_connect_error()); flock($lockFp, LOCK_UN); exit(1); }
mysqli_set_charset($connB, 'utf8mb4');

$connJ = @mysqli_connect($cfg['db_host_absensi'] ?? 'localhost', $cfg['db_user_absensi'] ?? '', $cfg['db_pass_absensi'] ?? '', $cfg['db_name_absensi'] ?? '');
if (!$connJ) { mlog('[ERROR] Gagal koneksi Joblist DB: ' . mysqli_connect_error()); flock($lockFp, LOCK_UN); exit(1); }
mysqli_set_charset($connJ, 'utf8mb4');

// -- Perluas enabledByList secara dinamis: kolom PEMILIK di tabel server adalah
// username API MikroTik PER ROUTER, bukan per akun - satu akun (satu user_id)
// bisa punya banyak server dengan nilai PEMILIK yang berbeda-beda. enabled_by
// di config_cron.json cuma snapshot PEMILIK yang tercatat SAAT toggle terakhir
// ditekan, jadi server/area baru yang ditambahkan sesudahnya tidak pernah ikut
// diproses cron sampai admin toggle off->on manual lagi. Di sini kita resolve
// ulang tiap kali cron jalan: cari semua user_id pemilik server-server yang
// jadi seed, lalu ambil SEMUA PEMILIK milik user_id tsb - supaya server/area
// baru otomatis ikut ter-cover tanpa perlu toggle ulang.
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
mlog('[INFO] PEMILIK setelah ekspansi kepemilikan akun: ' . implode(', ', $enabledByList));

// Buat SQL IN clause untuk filter PEMILIK (dibuat di sini, SETELAH ekspansi)
$pemilkEscaped = array_map(fn($p) => "'" . addslashes($p) . "'", $enabledByList);
$pemilkInClause = implode(',', $pemilkEscaped);

// RouterOS API
$routerApiFile = CRON_BASE_DIR . '/routeros_api.class.php';
$hasRouterApi  = file_exists($routerApiFile);
if ($hasRouterApi) {
    require_once $routerApiFile;
}

// Library RADIUS (radiusReadMergedBlocks dkk) -- dipakai untuk pelanggan
// RADIUS MODE/MULTI MODE, lihat fungsi radiusExpiredFileBatch() di bawah.
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
 * dideteksi expired lewat situ (itu sebabnya cron ini dulu selalu menganggap
 * mereka "tidak expired" dan salah bikin tiket MAINTENANCE saat mereka
 * offline karena isolir RADIUS).
 *
 * SENGAJA TIDAK menyentuh database `radiusq`/tabel `radacct` sama sekali --
 * itu aplikasi lain, bukan bagian dari sistem billing ini. Status ONLINE
 * pelanggan (termasuk yg radius-only) tetap 100% dari MikroTik API
 * (/ppp/active/print, dipoll di Step 7) persis seperti pelanggan API MODE
 * biasa -- sesi PPPoE yang diautentikasi lewat RADIUS di router yang sama
 * TETAP muncul di /ppp/active/print, jadi tidak butuh sumber lain.
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

// -- SQL: tanggal bayar expression -------------------------------------------------------
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

// -- Fungsi-fungsi menunggak --------------------------------------------------------------

function mt_isSamePeriod(string $dateValue, string $today): bool
{
    if (empty($dateValue)) return false;
    $ts = strtotime($dateValue);
    $tsT = strtotime($today);
    return ($ts !== false && $tsT !== false) && date('Y-m', $ts) === date('Y-m', $tsT);
}

function mt_shouldCountAsMenunggak(array $row, string $today): bool
{
    $tipeBayar = strtolower(trim((string)($row['TIPE_BAYAR'] ?? 'prabayar')));
    if ($tipeBayar !== 'pascabayar') {
        if (mt_isSamePeriod((string)($row['TANGGALPASANG'] ?? ''), $today)) return false;
        if (mt_isSamePeriod((string)($row['last_paid'] ?? ''), $today)) return false;
        return true;
    }
    if (mt_isSamePeriod((string)($row['TANGGALPASANG'] ?? ''), $today)) return false;
    if (mt_isSamePeriod((string)($row['last_paid'] ?? ''), $today)) return false;
    return true;
}

function mt_getRefDate(array $row): string
{
    $lp = trim((string)($row['last_paid'] ?? ''));
    if ($lp !== '' && strtotime($lp) !== false) return date('Y-m-d', strtotime($lp));
    $tp = trim((string)($row['TANGGALPASANG'] ?? ''));
    return ($tp !== '' && strtotime($tp) !== false) ? date('Y-m-d', strtotime($tp)) : '';
}

function mt_getFixedDay(string $username): int
{
    $day = 28;
    if ($username === '') return $day;
    $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '', $username);
    $f = CRON_BASE_DIR . '/notifbot/data/reminder-' . $safe . '.json';
    if (!is_file($f)) return $day;
    $data = json_decode(@file_get_contents($f), true);
    if (!is_array($data) || empty($data)) return $day;
    $d = (int)($data[0]['jatuh_tempo'] ?? 0);
    return ($d >= 1 && $d <= 31) ? $d : $day;
}

function mt_buildMonthlyDate(int $y, int $m, int $d): ?string
{
    if ($y < 1970 || $m < 1 || $m > 12) return null;
    if ($d < 1) $d = 1;
    $max = (int)date('t', strtotime(sprintf('%04d-%02d-01', $y, $m)));
    if ($d > $max) $d = $max;
    return sprintf('%04d-%02d-%02d', $y, $m, $d);
}

function mt_getFirstDueDate(array $row, string $refDate, int $fixedDay): ?string
{
    if (empty($refDate) || strtotime($refDate) === false) return null;
    $refTs = strtotime($refDate);
    $tempoType = strtolower(trim((string)($row['TIPE_TEMPO'] ?? 'mengikuti_tanggal_tempo')));
    if ($tempoType === 'mengikuti_tanggal_bayar') return date('Y-m-d', strtotime('+30 days', $refTs)); // Rolling: 30 hari persis, bukan +1 bulan.
    $cfgDay = ($fixedDay >= 1 && $fixedDay <= 31) ? $fixedDay : 28;
    $candidate = mt_buildMonthlyDate((int)date('Y', $refTs), (int)date('m', $refTs), $cfgDay);
    if ($candidate === null) return null;
    if (strtotime($candidate) <= $refTs) {
        $candidate = date('Y-m-d', strtotime('+1 month', strtotime($candidate)));
    }
    return $candidate;
}

function mt_isDueDatePassed(array $row, string $today, int $fixedDay): bool
{
    $ref  = mt_getRefDate($row);
    $due  = mt_getFirstDueDate($row, $ref, $fixedDay);
    return !empty($due) && strtotime($due) !== false && strtotime($due) <= strtotime($today);
}

function mt_isFasumNonPromo(string $paket, array $fasumList, array $promoIds): bool
{
    if ($paket === '' || !isset($fasumList[$paket])) return false;
    return !in_array((string)$fasumList[$paket], $promoIds, true);
}

function mt_resolveHarga(array $map, string $paket, string $brand, string $area): ?string
{
    foreach ([$paket.'|'.$brand.'|'.$area, $paket.'||'.$area, $paket.'|'.$brand.'|', $paket.'||', $paket] as $k) {
        if (isset($map[$k])) return $map[$k];
    }
    return null;
}

/**
 * Parse kapasitas splitter, mis. "1:8" -> 8. Return null jika tidak valid.
 */
function mt_parseSplitterCapacity(?string $splitter): ?int
{
    $splitter = trim((string)$splitter);
    if ($splitter === '' || strpos($splitter, ':') === false) return null;
    $parts = explode(':', $splitter);
    $cap = (int)($parts[1] ?? 0);
    return $cap > 0 ? $cap : null;
}

// -- MULAI ------------------------------------------------------------------------------
$today = date('Y-m-d');
$now   = date('Y-m-d H:i:s');
mlog('=== Cron Maintenance Ticket Mulai: ' . $now . ($dryRun ? ' [DRY-RUN]' : '') . ' ===');
mlog('[INFO] Diproses untuk PEMILIK: ' . implode(', ', $enabledByList));

// Tambahkan log untuk debug
mlog('[DEBUG] Memulai eksekusi cron_maintenance_ticket.');

if (empty($enabledByList)) {
    mlog('[INFO] Cron dinonaktifkan karena tidak ada PEMILIK yang mengaktifkan.');
    exit(0);
}

mlog('[DEBUG] Daftar PEMILIK yang mengaktifkan: ' . implode(', ', $enabledByList));

$statCreated         = 0;
$statCancelled       = 0;
$statErrors          = 0;
$statDedupDeleted    = 0;
$statSkippedMissing  = 0;
$statOdpConsolidated = 0;

// Step 1: Paket map
$hargaMap  = [];
$fasumList = [];
$promoIds  = [];
$qPaket = mysqli_query($connB, "SELECT id, PAKET, HARGA, BRAND, AREA FROM paket");
while ($qPaket && ($r = mysqli_fetch_assoc($qPaket))) {
    $pk = strtolower(trim((string)$r['PAKET']));
    $bk = strtolower(trim((string)($r['BRAND'] ?? '')));
    $ak = strtolower(trim((string)($r['AREA']  ?? '')));
    $hargaMap[$pk.'|'.$bk.'|'.$ak] = $r['HARGA'];
    if ($r['HARGA'] === '' || (float)$r['HARGA'] <= 0) $fasumList[$pk] = (string)$r['id'];
}
$qPromo = mysqli_query($connB, "SELECT paket_id FROM promo_paket");
while ($qPromo && ($r = mysqli_fetch_assoc($qPromo))) $promoIds[] = (string)$r['paket_id'];

// Step 2: Server map (PEMILIK|AREA -> row + username user) - HANYA yang enabled
$serverMap = [];
$usernameByUid = [];
$qSrv = mysqli_query($connB, "SELECT s.id, s.IP, s.PEMILIK, s.PASSWORD, s.AREA, s.user_id, u.username FROM server s LEFT JOIN user u ON s.user_id = u.id WHERE s.PEMILIK IN ($pemilkInClause) ORDER BY s.id DESC");
while ($qSrv && ($r = mysqli_fetch_assoc($qSrv))) {
    $key = (string)$r['PEMILIK'] . '|' . (string)($r['AREA'] ?? '');
    if (!isset($serverMap[$key])) $serverMap[$key] = $r;
    $uid = (int)$r['user_id'];
    if (!isset($usernameByUid[$uid]) && !empty($r['username'])) $usernameByUid[$uid] = (string)$r['username'];
}
mlog('[INFO] Server dimuat: ' . count($serverMap));

// Step 3: fixedDueDay per PEMILIK
$fixedDayByPemilik = [];
foreach ($serverMap as $key => $srv) {
    $pem = (string)$srv['PEMILIK'];
    if (isset($fixedDayByPemilik[$pem])) continue;
    $uid = (int)($srv['user_id'] ?? 0);
    $fixedDayByPemilik[$pem] = mt_getFixedDay($usernameByUid[$uid] ?? '');
}

// Step 4: Load semua pelanggan dengan last_paid - HANYA yang enabled
$allPelanggan = [];
$qPel = mysqli_query($connB,
    "SELECT p.IDPEL, p.NAMA, p.PAKET, p.PEMILIK, p.BRAND, p.AREA,
            p.NOWA, p.ALAMAT, p.EMAIL, p.ODP, p.MODE,
            p.TANGGALPASANG, p.TEMPO, p.TIPE_BAYAR, p.TIPE_TEMPO,
            t.last_paid
     FROM pelanggan p
     LEFT JOIN (
         SELECT IDPEL, MAX($trxTanggalExpr) AS last_paid
         FROM transaksi WHERE STATUS = 'BERHASIL' GROUP BY IDPEL
     ) t ON p.IDPEL = t.IDPEL
     WHERE p.PEMILIK IN ($pemilkInClause)"
);
while ($qPel && ($r = mysqli_fetch_assoc($qPel))) $allPelanggan[(string)$r['IDPEL']] = $r;
mlog('[INFO] Pelanggan dimuat: ' . count($allPelanggan));

// Step 5: Load tiket MAINTENANCE aktif (BARU/PENDING)
$activeMainTickets = [];  // IDPEL (atau ODP-KODE) => ticket_row
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
$qTkt = mysqli_query($ticketConn, "SELECT id, $ticketDataCol AS data, status, tipe, $ticketProjectCol AS project FROM $ticketTable WHERE tipe = 'MAINTENANCE' AND status IN ('BARU','PENDING')");
while ($qTkt && ($r = mysqli_fetch_assoc($qTkt))) {
    // Tanpa ^ supaya cocok dengan tiket dari web UI (ada header sebelum ID PELANGGAN)
    if (preg_match('/ID PELANGGAN\s*:([^\n]+)/i', (string)$r['data'], $m)) {
        $idpel = trim($m[1]);
        if ($idpel !== '') $activeMainTickets[$idpel] = $r;
    }
}
mlog('[INFO] Tiket MAINTENANCE aktif: ' . count($activeMainTickets));

// Step 5b: Hapus duplikat tiket MAINTENANCE aktif
// Kriteria duplikat: project(server) + tipe + status + IDPEL(atau ODP-KODE) sama.
$seenTicketKey = [];
$duplicateTicketIds = [];
$canonicalByIdpel = [];
foreach ($activeMainTickets as $idpel => $ticket) {
    $project = strtoupper(trim((string)($ticket['project'] ?? '')));
    $status  = strtoupper(trim((string)($ticket['status'] ?? '')));
    $ticketKey = $project . '|MAINTENANCE|' . $status . '|' . strtoupper($idpel);

    if (isset($seenTicketKey[$ticketKey])) {
        $duplicateTicketIds[] = (int)($ticket['id'] ?? 0);
        continue;
    }

    $seenTicketKey[$ticketKey] = true;
    if (!isset($canonicalByIdpel[$idpel])) {
        $canonicalByIdpel[$idpel] = $ticket;
    }
}

if (!empty($duplicateTicketIds)) {
    foreach ($duplicateTicketIds as $dupId) {
        if ($dupId <= 0) {
            continue;
        }
        if ($dryRun) {
            $statDedupDeleted++;
            mlog("[DRY-RUN] HAPUS tiket duplikat MAINTENANCE #{$dupId}");
            continue;
        }
        if (mysqli_query($ticketConn, "DELETE FROM $ticketTable WHERE id={$dupId} LIMIT 1")) {
            $statDedupDeleted++;
            mlog("[DEDUP] HAPUS tiket duplikat MAINTENANCE #{$dupId}");
        } else {
            $statErrors++;
            mlog('[ERROR] Gagal hapus tiket duplikat #' . $dupId . ': ' . mysqli_error($ticketConn));
        }
    }
}

$activeMainTickets = $canonicalByIdpel;

// Step 6: SEMUA pelanggan (termasuk fasum/gratis paket HARGA 0) ikut
// dipertimbangkan untuk polling MikroTik & evaluasi ODP - pelanggan gratis yang
// offline tapi TIDAK expired tetap berhak dapat tiket MAINTENANCE, sama seperti
// pelanggan berbayar. Klasifikasi expired/tidak-expired SENGAJA dipindah ke
// SETELAH polling profile MikroTik di Step 7 (bukan dari tanggal jatuh tempo
// billing seperti sebelumnya) supaya definisi "expired" persis sama dengan
// dashboard (getdata/serverload.php) dan cron_dismantle_ticket.php, sehingga
// jumlah tiket MAINTENANCE balance dengan "Internet LOS" di dashboard.
$consideredPelanggan = $allPelanggan;
mlog('[INFO] Dipertimbangkan (termasuk fasum/gratis): ' . count($consideredPelanggan));

// Step 7: Polling MikroTik SEKALI per server: profile PPP secret (utk deteksi
// EXPIRED, sama seperti cron_dismantle_ticket.php) SEKALIGUS status online,
// untuk semua pelanggan yang dipertimbangkan.
$byServerKeyAll = [];
foreach ($consideredPelanggan as $idpel => $row) {
    $key = (string)($row['PEMILIK'] ?? '') . '|' . (string)($row['AREA'] ?? '');
    $byServerKeyAll[$key][$idpel] = $row;
}

$onlineStatus      = []; // idpel(lower) => true/false
$polledOk          = []; // idpel(lower) => true jika server berhasil di-cek
$expiredProfileSet = []; // idpel(lower) => true jika profile PPP secret == EXPIRED
$hasLocalSecret    = []; // idpel(lower) => true jika ADA entry PPP secret lokal (profile apa saja) di router yg berhasil dipoll

foreach ($byServerKeyAll as $serverKey => $customerRows) {
    $onlineSet       = [];
    $expiredSetLocal = [];
    $secretSetLocal  = [];
    $mikrotikOK      = false;

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
                    $mikrotikOK = true;

                    $secrets = $api->comm('/ppp/secret/print');
                    foreach ($secrets as $sec) {
                        $secName = strtolower(trim((string)($sec['name'] ?? '')));
                        $secProfile = trim((string)($sec['profile'] ?? ''));
                        if ($secName === '') continue;
                        $secretSetLocal[$secName] = true;
                        if (strtoupper($secProfile) === 'EXPIRED') {
                            $expiredSetLocal[$secName] = true;
                        }
                    }

                    $activeSessions = $api->comm('/ppp/active/print');
                    foreach ($activeSessions as $sess) {
                        $sessName = strtolower(trim((string)($sess['name'] ?? '')));
                        if ($sessName !== '') $onlineSet[$sessName] = true;
                    }
                    if (method_exists($api, 'disconnect')) $api->disconnect();
                    mlog('[INFO] MikroTik ' . $ip . ' (' . $serverKey . '): ' . count($onlineSet) . ' sesi aktif, ' . count($expiredSetLocal) . ' profile EXPIRED');
                } else {
                    mlog('[WARNING] Gagal konek MikroTik ' . $ip . ' (' . $serverKey . ')');
                }
            } catch (Throwable $e) {
                mlog('[WARNING] Error MikroTik ' . $serverKey . ': ' . $e->getMessage());
            }
        }
    }

    if (!$mikrotikOK) {
        mlog('[WARNING] MikroTik tidak tersedia untuk ' . $serverKey . ' - status online/offline/profile pelanggan di server ini tidak bisa dipastikan');
        continue;
    }

    foreach ($customerRows as $idpel => $row) {
        $lk = strtolower($idpel);
        $polledOk[$lk]     = true;
        $onlineStatus[$lk] = isset($onlineSet[$lk]);
        if (isset($expiredSetLocal[$lk])) $expiredProfileSet[$lk] = true;
        if (isset($secretSetLocal[$lk])) $hasLocalSecret[$lk] = true;
    }
}

// Step 7c: EXPIRED via file FreeRADIUS (Mikrotik-Group), untuk pelanggan yang
// MODE-nya RADIUS MODE/MULTI MODE, ATAU yang ternyata TIDAK ADA PPP secret
// lokalnya sama sekali (walau MODE tidak terisi/salah) -----------------------
// MikroTik API di atas HANYA melihat PPP secret LOKAL di router -- pelanggan yang
// login via RADIUS tidak akan pernah muncul di /ppp/secret/print, sehingga TIDAK
// PERNAH terdeteksi EXPIRED lewat jalur itu, walau sudah diisolir (isolir RADIUS
// mengubah atribut Mikrotik-Group di file users FreeRADIUS, bukan profile secret
// lokal MikroTik). Status ONLINE untuk pelanggan ini TIDAK disentuh di sini --
// sudah benar apa adanya dari Step 7 (/ppp/active/print, sama seperti pelanggan
// API MODE biasa; sesi RADIUS di router yang sama tetap muncul di situ). Hanya
// pelanggan yang server-nya berhasil dipoll ($polledOk sudah true dari Step 7)
// yang diproses -- kalau server tidak reachable, status tetap tidak diketahui
// (skip), sama seperti pelanggan API MODE biasa.
$radiusModeIdpel = [];
foreach ($consideredPelanggan as $idpelR => $rowR) {
    $lkR = strtolower($idpelR);
    if (!isset($polledOk[$lkR])) continue;
    $modeUpperR = strtoupper(trim((string)($rowR['MODE'] ?? '')));
    $noLocalSecretR = !isset($hasLocalSecret[$lkR]);
    if ($modeUpperR === 'RADIUS MODE' || $modeUpperR === 'MULTI MODE' || $noLocalSecretR) {
        $radiusModeIdpel[] = $idpelR;
    }
}
if (!empty($radiusModeIdpel)) {
    $radiusExpiredSet = radiusExpiredFileBatch($radiusModeIdpel);
    mlog('[INFO] Status EXPIRED via file FreeRADIUS dicek untuk ' . count($radiusModeIdpel) . ' pelanggan (' . count($radiusExpiredSet) . ' expired)');
    foreach ($radiusModeIdpel as $idpelR) {
        $lkR = strtolower($idpelR);
        $modeUpperR = strtoupper(trim((string)($consideredPelanggan[$idpelR]['MODE'] ?? '')));
        $hasLocalSecretR = isset($hasLocalSecret[$lkR]);
        $radiusExpiredR = isset($radiusExpiredSet[$lkR]);
        if ($modeUpperR === 'MULTI MODE') {
            // Punya secret lokal valid juga -- expired kalau SALAH SATU sumber bilang begitu.
            if ($radiusExpiredR || !empty($expiredProfileSet[$lkR])) {
                $expiredProfileSet[$lkR] = true;
            }
        } elseif ($modeUpperR === 'RADIUS MODE' || !$hasLocalSecretR) {
            // Murni RADIUS / tanpa secret lokal sama sekali: profile lokal tidak relevan,
            // pakai file RADIUS saja.
            if ($radiusExpiredR) {
                $expiredProfileSet[$lkR] = true;
            } else {
                unset($expiredProfileSet[$lkR]);
            }
        }
    }
}

// Step 6b: Klasifikasi expired vs tidak-expired SETELAH tahu profile MikroTik.
// Pelanggan yang server-nya tidak bisa dicek (!polledOk) sengaja TIDAK dimasukkan
// ke expiredSet maupun notExpiredRows, supaya tidak ada keputusan buat/cancel
// tiket yang diambil dari data yang belum pasti.
$expiredSet     = []; // hanya simpan key
$notExpiredRows = []; // IDPEL => row
foreach ($consideredPelanggan as $idpel => $row) {
    $lk = strtolower($idpel);
    if (!isset($polledOk[$lk])) continue;
    if (isset($expiredProfileSet[$lk])) {
        $expiredSet[$idpel] = true;
    } else {
        $notExpiredRows[$idpel] = $row;
    }
}
mlog('[INFO] Tidak expired: ' . count($notExpiredRows) . ', Expired: ' . count($expiredSet));

// Step 7b: Evaluasi per ODP - cek apakah SELURUH pelanggan (expired + tidak expired)
// pada satu ODP mati semua sesuai kapasitas splitter-nya. Jika ya, buat 1 tiket
// MAINTENANCE untuk ODP tsb (bukan per-pelanggan). Jika minimal 1 online, cancel
// tiket ODP (dan tiket individual lama) jika ada.
$pelangganByOdp = [];
foreach ($consideredPelanggan as $idpel => $row) {
    $odpKode = trim((string)($row['ODP'] ?? ''));
    if ($odpKode === '') continue;
    $pelangganByOdp[$odpKode][$idpel] = $row;
}

$odpCapacity = [];
$qOdpCap = mysqli_query($connB, "SELECT KODE, splitter FROM odp");
while ($qOdpCap && ($r = mysqli_fetch_assoc($qOdpCap))) {
    $cap = mt_parseSplitterCapacity($r['splitter'] ?? null);
    if ($cap !== null) $odpCapacity[$r['KODE']] = $cap;
}

$odpConsolidated = []; // KODE ODP => true, berarti pelanggan di ODP ini jangan dibuatkan tiket individual

foreach ($pelangganByOdp as $odpKode => $members) {
    $totalMembers = count($members);
    if ($totalMembers === 0) continue;

    $uncertain      = false;
    $onlineCount    = 0;
    $nonExpiredCount = 0;
    foreach ($members as $mIdpel => $mRow) {
        $lk = strtolower($mIdpel);
        if (!isset($polledOk[$lk])) { $uncertain = true; break; }
        if (!empty($onlineStatus[$lk])) $onlineCount++;
        if (!isset($expiredSet[$mIdpel])) $nonExpiredCount++;
    }

    // MikroTik salah satu anggota ODP ini tidak bisa dicek -> jangan ambil
    // keputusan ODP-level, biar aman (fallback ke logika per-pelanggan biasa).
    if ($uncertain) continue;

    $ticketKeyOdp = ODP_TICKET_PREFIX . $odpKode;
    $hasOdpTicket = isset($activeMainTickets[$ticketKeyOdp]);

    // Kalau SEMUA anggota ODP berstatus expired (profile EXPIRED di MikroTik),
    // offline-nya mereka wajar karena diisolir tagihan, bukan indikasi ODP/
    // splitter rusak - jangan buat tiket MAINTENANCE ODP. Minimal harus ada 1
    // pelanggan yang BUKAN expired supaya "ODP mati total" ini berarti gangguan
    // teknis sungguhan. (Pelanggan expired tetap ditangani cron DISMANTLE.)
    // Kalau tiket ODP lama sudah ada tapi seluruh anggotanya sekarang jadi
    // expired, cancel juga supaya tidak nyangkut.
    if ($nonExpiredCount === 0) {
        if ($hasOdpTicket) {
            $tid = (int)$activeMainTickets[$ticketKeyOdp]['id'];
            if ($dryRun) {
                $statCancelled++;
                mlog("[DRY-RUN] CANCEL tiket MAINTENANCE ODP #{$tid} KODE=$odpKode (seluruh anggota sudah expired)");
            } elseif (mysqli_query($ticketConn, "UPDATE $ticketTable SET status='CANCEL' WHERE id=$tid")) {
                $statCancelled++;
                mlog("[CANCEL] Tiket MAINTENANCE ODP #{$tid} KODE=$odpKode (seluruh anggota sudah expired)");
                unset($activeMainTickets[$ticketKeyOdp]);
            } else {
                $statErrors++;
                mlog('[ERROR] Gagal cancel tiket ODP KODE=' . $odpKode . ': ' . mysqli_error($ticketConn));
            }
        }
        continue;
    }

    // Kapasitas splitter HANYA dipakai untuk info di judul/laporan tiket, BUKAN
    // syarat konsolidasi - splitter jarang terisi penuh (mis. splitter 1:8 cuma
    // diisi 3 pelanggan terdaftar), jadi mensyaratkan totalMembers >= kapasitas
    // bikin "ODP mati total" nyaris tidak pernah kejadian dan tiket individual
    // per-pelanggan tetap keluar semua. Syarat konsolidasi cukup: SELURUH
    // pelanggan yang TERDAFTAR di ODP ini offline (onlineCount === 0).
    $capacity = $odpCapacity[$odpKode] ?? null;

    if ($onlineCount === 0) {
        // ===== ODP MATI TOTAL =====
        $odpConsolidated[$odpKode] = true;
        $statOdpConsolidated++;
        $capInfo = $capacity !== null ? "{$totalMembers}/{$capacity} pelanggan" : "{$totalMembers} pelanggan";

        // Cancel tiket individual yang mungkin sudah terlanjur ada untuk anggota ODP ini,
        // supaya tidak dobel dengan tiket ODP.
        foreach ($members as $mIdpel => $mRow) {
            if (!isset($activeMainTickets[$mIdpel])) continue;
            $tid2 = (int)$activeMainTickets[$mIdpel]['id'];
            if ($dryRun) {
                $statCancelled++;
                mlog("[DRY-RUN] CANCEL tiket individual #{$tid2} IDPEL=$mIdpel (digabung jadi tiket ODP $odpKode)");
                continue;
            }
            if (mysqli_query($ticketConn, "UPDATE $ticketTable SET status='CANCEL' WHERE id=$tid2")) {
                $statCancelled++;
                mlog("[CANCEL] Tiket individual #{$tid2} IDPEL=$mIdpel (digabung jadi tiket ODP $odpKode)");
                unset($activeMainTickets[$mIdpel]);
            } else {
                $statErrors++;
                mlog('[ERROR] Gagal cancel tiket individual IDPEL=' . $mIdpel . ' saat konsolidasi ODP: ' . mysqli_error($ticketConn));
            }
        }

        if ($hasOdpTicket) {
            // Tiket ODP sudah ada dan masih aktif, tidak perlu buat baru.
            continue;
        }

        // Double-check DB (hindari race / duplikat)
        $odpKodeEsc = mysqli_real_escape_string($ticketConn, $ticketKeyOdp);
        $cekOdp = mysqli_query($ticketConn,
            "SELECT id FROM $ticketTable
             WHERE $ticketDataCol LIKE '%ID PELANGGAN :$odpKodeEsc%'
               AND tipe = 'MAINTENANCE'
               AND status IN ('BARU','PENDING')
             LIMIT 1"
        );
        if ($cekOdp && mysqli_num_rows($cekOdp) > 0) continue;

        $sample  = reset($members);
        $pemilik = (string)($sample['PEMILIK'] ?? '');
        $brand   = (string)($sample['BRAND']   ?? '');
        $area    = (string)($sample['AREA']    ?? '');
        $kendala = "ODP mati total - seluruh pelanggan offline ($capInfo)";

        // SEBELUMNYA keterangan tiket cuma jumlah pelanggan (summary), teknisi
        // yang dapat tiket harus buka billing lagi utk tahu SIAPA saja yang
        // kena. Sekarang ikutkan daftar per-pelanggan (nama, IDPEL, status
        // online/offline/expired) langsung di keterangan tiket.
        $memberDetailLines = [];
        foreach ($members as $mIdpel => $mRow) {
            $mLk = strtolower($mIdpel);
            $mStatusLabel = isset($expiredSet[$mIdpel])
                ? 'EXPIRED'
                : (!empty($onlineStatus[$mLk]) ? 'ONLINE' : 'OFFLINE');
            $memberDetailLines[] = '- ' . (string)($mRow['NAMA'] ?? '-') . " ($mIdpel) : $mStatusLabel";
        }

        $data = "===============\nTiket MAINTENANCE ODP dari billing (OTOMATIS)\n===============\nID PELANGGAN :$ticketKeyOdp\nKODE ODP :$odpKode\nJUMLAH PELANGGAN :$totalMembers\nKENDALA :$kendala\n\nDAFTAR PELANGGAN:\n"
            . implode("\n", $memberDetailLines);

        if ($dryRun) {
            $statCreated++;
            mlog("[DRY-RUN] BUAT tiket MAINTENANCE ODP untuk KODE=$odpKode ($capInfo offline)");
            continue;
        }

        if ($useTicketManager) {
            $serverKeyGuess = $pemilik . '|' . $area;
            $srvRef   = $serverMap[$serverKeyGuess] ?? null;
            $serverId = (int)($srvRef['id'] ?? 0);
            if ($serverId <= 0) {
                $statErrors++;
                mlog('[ERROR] Gagal buat tiket MAINTENANCE ODP KODE=' . $odpKode . ': server_id tidak ditemukan');
                continue;
            }
            $judul       = mysqli_real_escape_string($ticketConn, 'MAINTENANCE ODP - ' . $odpKode);
            $detailEsc   = mysqli_real_escape_string($ticketConn, $data);
            $pemilikEsc  = mysqli_real_escape_string($ticketConn, $pemilik);
            $brandEsc    = mysqli_real_escape_string($ticketConn, $brand);
            $areaEsc     = mysqli_real_escape_string($ticketConn, $area);
            $projectName = trim($brand . ' - ' . $area);
            if ($projectName === '' || $projectName === '-') $projectName = $pemilik;
            $projectEsc  = mysqli_real_escape_string($ticketConn, $projectName);
            $createdBy   = (int)($srvRef['user_id'] ?? 0);
            $ins = "INSERT INTO billing_tiket_manager (judul, detail, server_id, pemilik, brand, area, project_name, tipe, report, status, teknisi_user_id, created_by_user_id) VALUES ('$judul', '$detailEsc', $serverId, '$pemilikEsc', '$brandEsc', '$areaEsc', '$projectEsc', 'MAINTENANCE', '', 'BARU', NULL, $createdBy)";
        } else {
            $dataEsc    = mysqli_real_escape_string($ticketConn, $data);
            $pemilikEsc = mysqli_real_escape_string($ticketConn, $pemilik);
            $ins = "INSERT INTO joblist (tgl, status, nowa, data, project, report, team, tipe)
                    VALUES ('$today', 'BARU', '', '$dataEsc', '$pemilikEsc', '', '', 'MAINTENANCE')";
        }

        if (mysqli_query($ticketConn, $ins)) {
            $statCreated++;
            mlog("[BUAT] Tiket MAINTENANCE ODP -> KODE=$odpKode ($capInfo offline)");
        } else {
            $statErrors++;
            mlog('[ERROR] Gagal buat tiket MAINTENANCE ODP KODE=' . $odpKode . ': ' . mysqli_error($ticketConn));
        }

    } elseif ($onlineCount >= 1 && $hasOdpTicket) {
        // Minimal 1 pelanggan sudah online -> cancel tiket ODP yang masih aktif
        $tid = (int)$activeMainTickets[$ticketKeyOdp]['id'];
        if ($dryRun) {
            $statCancelled++;
            mlog("[DRY-RUN] CANCEL tiket MAINTENANCE ODP #{$tid} KODE=$odpKode (sudah ada pelanggan online)");
            continue;
        }
        if (mysqli_query($ticketConn, "UPDATE $ticketTable SET status='CANCEL' WHERE id=$tid")) {
            $statCancelled++;
            mlog("[CANCEL] Tiket MAINTENANCE ODP #{$tid} KODE=$odpKode (sudah ada pelanggan online)");
            unset($activeMainTickets[$ticketKeyOdp]);
        } else {
            $statErrors++;
            mlog('[ERROR] Gagal cancel tiket ODP KODE=' . $odpKode . ': ' . mysqli_error($ticketConn));
        }
    }
}
mlog('[INFO] ODP dikonsolidasi (mati total): ' . $statOdpConsolidated);

// Step 8: Per pelanggan tidak expired - buat/cancel tiket individual
// (ODP yang sudah dikonsolidasi di Step 7b dilewati di sini)
$byServerKeyNotExpired = [];
foreach ($notExpiredRows as $idpel => $row) {
    $key = (string)($row['PEMILIK'] ?? '') . '|' . (string)($row['AREA'] ?? '');
    $byServerKeyNotExpired[$key][$idpel] = $row;
}

foreach ($byServerKeyNotExpired as $serverKey => $customerRows) {
    foreach ($customerRows as $idpel => $row) {
        $custOdp = trim((string)($row['ODP'] ?? ''));
        if ($custOdp !== '' && isset($odpConsolidated[$custOdp])) {
            // Sudah ditangani lewat tiket ODP konsolidasi.
            continue;
        }

        $lk = strtolower($idpel);
        $isOnline  = isset($polledOk[$lk]) ? (bool)($onlineStatus[$lk] ?? false) : null;
        $hasTicket = isset($activeMainTickets[$idpel]);

        if ($isOnline === false) {
            // OFFLINE + tidak expired -> buat tiket MAINTENANCE jika belum ada
            if ($hasTicket) continue; // sudah punya tiket

            // Double-check DB
            $idpelEsc = mysqli_real_escape_string($ticketConn, $idpel);
            $cek = mysqli_query($ticketConn,
                "SELECT id FROM $ticketTable
                 WHERE $ticketDataCol LIKE '%ID PELANGGAN :$idpelEsc%'
                   AND tipe = 'MAINTENANCE'
                   AND status IN ('BARU','PENDING')
                 LIMIT 1"
            );
            if ($cek && mysqli_num_rows($cek) > 0) continue;

            $nama    = (string)($row['NAMA']    ?? '');
            $odp     = (string)($row['ODP']     ?? '');
            $email   = (string)($row['EMAIL']   ?? '');
            $alamat  = (string)($row['ALAMAT']  ?? '');
            $nowa    = (string)($row['NOWA']    ?? '');
            $pemilik = (string)($row['PEMILIK'] ?? '');
            $kendala = 'Pelanggan offline/LOS - otomatis sistem';
            // Format data tiket - sama dengan buat_tiket.php agar konsisten
            $data    = "===============\nTiket MAINTENANCE dari billing\n===============\nID PELANGGAN :$idpel\nNAMA PELANGGAN :$nama\nODP :$odp\nEMAIL :$email\nALAMAT :$alamat\nNO WA :$nowa\nKENDALA :$kendala";

            if ($dryRun) {
                $statCreated++;
                mlog("[DRY-RUN] BUAT tiket MAINTENANCE untuk IDPEL=$idpel NAMA=$nama");
                continue;
            }

            if ($useTicketManager) {
                $srvRef = $serverMap[$serverKey] ?? null;
                $serverId = (int)($srvRef['id'] ?? 0);
                if ($serverId <= 0) {
                    $statErrors++;
                    mlog('[ERROR] Gagal buat tiket MAINTENANCE IDPEL=' . $idpel . ': server_id tidak ditemukan');
                    continue;
                }

                $judul = mysqli_real_escape_string($ticketConn, 'MAINTENANCE - ' . $idpel . ' - ' . $nama);
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
                $ins = "INSERT INTO billing_tiket_manager (judul, detail, server_id, pemilik, brand, area, project_name, tipe, report, status, teknisi_user_id, created_by_user_id) VALUES ('$judul', '$detailEsc', $serverId, '$pemilikEsc', '$brandEsc', '$areaEsc', '$projectEsc', 'MAINTENANCE', '', 'BARU', NULL, $createdBy)";
            } else {
                $nowaEsc    = mysqli_real_escape_string($ticketConn, $nowa);
                $dataEsc    = mysqli_real_escape_string($ticketConn, $data);
                $pemilikEsc = mysqli_real_escape_string($ticketConn, $pemilik);
                $ins = "INSERT INTO joblist (tgl, status, nowa, data, project, report, team, tipe)
                        VALUES ('$today', 'BARU', '$nowaEsc', '$dataEsc', '$pemilikEsc', '', '', 'MAINTENANCE')";
            }

            if (mysqli_query($ticketConn, $ins)) {
                $statCreated++;
                mlog("[BUAT] Tiket MAINTENANCE -> IDPEL=$idpel NAMA=$nama");
            } else {
                $statErrors++;
                mlog('[ERROR] Gagal buat tiket MAINTENANCE IDPEL=' . $idpel . ': ' . mysqli_error($ticketConn));
            }

        } elseif ($isOnline === true) {
            // ONLINE + tidak expired -> cancel tiket jika ada
            if (!$hasTicket) continue;
            $tid = (int)$activeMainTickets[$idpel]['id'];
            if ($dryRun) {
                $statCancelled++;
                mlog("[DRY-RUN] CANCEL tiket MAINTENANCE #{$tid} IDPEL=$idpel (online, tidak expired)");
                continue;
            }
            if (mysqli_query($ticketConn, "UPDATE $ticketTable SET status='CANCEL' WHERE id=$tid")) {
                $statCancelled++;
                mlog("[CANCEL] Tiket MAINTENANCE #{$tid} IDPEL=$idpel (online, tidak expired)");
            } else {
                $statErrors++;
                mlog('[ERROR] Gagal cancel IDPEL=' . $idpel . ': ' . mysqli_error($ticketConn));
            }
        }
        // isOnline === null: MikroTik tidak tersedia -> skip buat tiket
    }
}

// Step 8b: Cancel tiket MAINTENANCE individual yang pelanggannya SEKARANG
// terklasifikasi EXPIRED (mis. baru terdeteksi expired lewat RADIUS/isolir
// tagihan setelah pelanggan ybs sebelumnya sempat salah dianggap "tidak
// expired") -- Step 8 di atas HANYA memproses pelanggan di $notExpiredRows,
// jadi begitu seorang pelanggan pindah ke $expiredSet, tiket MAINTENANCE
// lama mereka TIDAK PERNAH lagi disentuh/dibatalkan oleh loop manapun di
// atas dan akan nyangkut selamanya. Tiket MAINTENANCE hanya valid utk
// pelanggan offline yg TIDAK expired -- kalau sudah expired, itu urusan
// cron_dismantle_ticket.php (tiket DISMANTLE), bukan cron ini.
foreach ($activeMainTickets as $ticketKey => $ticket) {
    if (strpos($ticketKey, ODP_TICKET_PREFIX) === 0) continue; // sudah ditangani Step 7b
    if (!isset($expiredSet[$ticketKey])) continue;
    $tid = (int)($ticket['id'] ?? 0);
    if ($dryRun) {
        $statCancelled++;
        mlog("[DRY-RUN] CANCEL tiket MAINTENANCE #{$tid} IDPEL=$ticketKey (sekarang expired, bukan urusan cron MAINTENANCE)");
        continue;
    }
    if (mysqli_query($ticketConn, "UPDATE $ticketTable SET status='CANCEL' WHERE id=$tid")) {
        $statCancelled++;
        mlog("[CANCEL] Tiket MAINTENANCE #{$tid} IDPEL=$ticketKey (sekarang expired, bukan urusan cron MAINTENANCE)");
        unset($activeMainTickets[$ticketKey]);
    } else {
        $statErrors++;
        mlog('[ERROR] Gagal cancel tiket MAINTENANCE IDPEL=' . $ticketKey . ' (expired): ' . mysqli_error($ticketConn));
    }
}

// Step 9: Bersihkan tiket untuk pelanggan/ODP yang sudah tidak ditemukan (deleted)
foreach ($activeMainTickets as $ticketKey => $ticket) {

    if (strpos($ticketKey, ODP_TICKET_PREFIX) === 0) {
        $kodeFromKey = substr($ticketKey, strlen(ODP_TICKET_PREFIX));
        if (isset($pelangganByOdp[$kodeFromKey]) && !empty($pelangganByOdp[$kodeFromKey])) {
            continue; // ODP masih punya pelanggan, sudah ditangani di Step 7b
        }
        $tid = (int)($ticket['id'] ?? 0);
        if ($dryRun) {
            $statCancelled++;
            mlog("[DRY-RUN] CANCEL tiket ODP #{$tid} KODE=$kodeFromKey (tidak ada lagi pelanggan pada ODP ini)");
            continue;
        }
        if (mysqli_query($ticketConn, "UPDATE $ticketTable SET status='CANCEL' WHERE id=$tid")) {
            $statCancelled++;
            mlog("[CANCEL] Tiket ODP #{$tid} KODE=$kodeFromKey (tidak ada lagi pelanggan pada ODP ini)");
        } else {
            $statErrors++;
            mlog('[ERROR] Gagal cancel tiket ODP KODE=' . $kodeFromKey . ' (pelanggan hilang): ' . mysqli_error($ticketConn));
        }
        continue;
    }

    if (isset($allPelanggan[$ticketKey])) continue; // masih ada di DB
    $tid = (int)($ticket['id'] ?? 0);
    $statSkippedMissing++;
    if ($dryRun) {
        $statCancelled++;
        mlog("[DRY-RUN] CANCEL tiket MAINTENANCE #{$tid} IDPEL=$ticketKey (pelanggan tidak ditemukan di DB)");
        continue;
    }
    if (mysqli_query($ticketConn, "UPDATE $ticketTable SET status='CANCEL' WHERE id=$tid")) {
        $statCancelled++;
        mlog("[CANCEL] Tiket MAINTENANCE #{$tid} IDPEL=$ticketKey (pelanggan tidak ditemukan di DB)");
    } else {
        $statErrors++;
        mlog('[ERROR] Gagal cancel tiket MAINTENANCE IDPEL=' . $ticketKey . ' (pelanggan hilang): ' . mysqli_error($ticketConn));
    }
}

// -- Selesai --------------------------------------------------------------------------------
$elapsed = round(microtime(true) - $cronStartTime, 2);
mlog(
    '=== SELESAI' . ($dryRun ? ' [DRY-RUN]' : '') . ' ===' .
    ' Dibuat=' . $statCreated .
    ' Dibatalkan=' . $statCancelled .
    ' ODPKonsolidasi=' . $statOdpConsolidated .
    ' DedupHapus=' . $statDedupDeleted .
    ' SkipMissing=' . $statSkippedMissing .
    ' Error=' . $statErrors .
    ' Waktu=' . $elapsed . 's ==='
);

mysqli_close($connB);
mysqli_close($connJ);

flock($lockFp, LOCK_UN);
fclose($lockFp);
@unlink(LOCK_FILE);

exit($statErrors > 0 ? 1 : 0);