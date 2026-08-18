<?php
// cron_chat_activity_digest.php - jalan tiap jam (disarankan menit ke-5, mis.
// "5 * * * *"), pre-warm ringkasan AI utk jam yang BARU SAJA selesai supaya
// tab "Catatan Kejadian" (modal Live Chat, crm/billing/sidebar.php) langsung
// tampil instan begitu dibuka -- tidak perlu tunggu panggilan AI saat itu
// juga. TANPA toggle enabled_by (beda dari cron_maintenance_ticket.php dkk)
// krn ini murni operasi baca+cache, tidak ambil aksi/kirim notif apapun ke
// siapapun -- aman dijalankan utk semua tenant sekaligus.
@set_time_limit(0);
if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

define('CRON_BASE_DIR', dirname(__DIR__, 2));
define('LOG_FILE', __DIR__ . '/log_chat_activity_digest.log');
define('LOCK_FILE', __DIR__ . '/cron_chat_activity_digest.lock');
define('MAX_LOG_SIZE', 2 * 1024 * 1024); // 2 MB

function cadLog(string $msg): void
{
    if (file_exists(LOG_FILE) && filesize(LOG_FILE) >= MAX_LOG_SIZE) {
        rename(LOG_FILE, LOG_FILE . '.bak');
    }
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
    echo $line;
}

// -- Lock: cegah eksekusi bersamaan (mis. cron jalan lama krn banyak tenant,
// lalu jam berikutnya cron baru mulai lagi sebelum yang lama selesai) -------
$lockFp = @fopen(LOCK_FILE, 'c');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    cadLog('[WARNING] Cron sudah berjalan (lock file aktif). Keluar.');
    exit(0);
}

$configFile = CRON_BASE_DIR . '/config.json';
if (!file_exists($configFile)) {
    cadLog('[ERROR] config.json tidak ditemukan: ' . $configFile);
    flock($lockFp, LOCK_UN);
    exit(1);
}
$cfg = json_decode(file_get_contents($configFile), true);
if (!$cfg) {
    cadLog('[ERROR] Gagal parse config.json.');
    flock($lockFp, LOCK_UN);
    exit(1);
}

$conn = @mysqli_connect($cfg['db_host'] ?? 'localhost', $cfg['db_user'] ?? '', $cfg['db_pass'] ?? '', $cfg['db_name'] ?? '');
if (!$conn) {
    cadLog('[ERROR] Gagal koneksi DB: ' . mysqli_connect_error());
    flock($lockFp, LOCK_UN);
    exit(1);
}
mysqli_set_charset($conn, 'utf8mb4');

require_once CRON_BASE_DIR . '/chat_activity_helper.php';
chatActivityEnsureTables($conn);

// Jam yang baru saja selesai (mis. cron jalan 08:05 -> proses jam 07:00-07:59).
$prevHourStart = date('Y-m-d H:00:00', strtotime('-1 hour'));

$res = mysqli_query($conn, "SELECT DISTINCT pemilik FROM chat_event_log WHERE created_at >= '" . mysqli_real_escape_string($conn, $prevHourStart) . "'");
$pemilikList = [];
while ($res && ($row = mysqli_fetch_assoc($res))) {
    $p = trim((string)($row['pemilik'] ?? ''));
    if ($p !== '') {
        $pemilikList[] = $p;
    }
}

if (empty($pemilikList)) {
    cadLog('[INFO] Tidak ada aktivitas chat pada jam ' . $prevHourStart . ', tidak ada yang perlu di-cache.');
    flock($lockFp, LOCK_UN);
    exit(0);
}

cadLog('[INFO] Pre-warm ringkasan jam ' . $prevHourStart . ' utk ' . count($pemilikList) . ' tenant: ' . implode(', ', $pemilikList));

foreach ($pemilikList as $pemilik) {
    // limitHours=2 cukup utk cover jam yang baru selesai + jam berjalan saat
    // ini -- reuse fungsi yang SAMA persis dgn yang dipanggil tab "Catatan
    // Kejadian" (get_hourly_digest.php), supaya tidak ada logic ganda yang
    // bisa berbeda hasil.
    $digest = chatActivityGetHourlyDigest($conn, $pemilik, 2);
    cadLog('[INFO]   - ' . $pemilik . ': ' . count($digest) . ' bucket diproses.');
}

// Superadmin ('admin', gabungan semua tenant -- lihat catatan di
// chat_activity_helper.php) juga di-pre-warm sekali per run.
$digestAdmin = chatActivityGetHourlyDigest($conn, 'admin', 2);
cadLog('[INFO]   - admin (gabungan semua tenant): ' . count($digestAdmin) . ' bucket diproses.');

cadLog('[INFO] Selesai.');
flock($lockFp, LOCK_UN);
