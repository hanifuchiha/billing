<?php
/**
 * Cron perbaikan OTOMATIS PPP Profile menggantung - dipanggil BERKALA (lewat
 * cron), bukan oleh browser admin secara langsung.
 *
 * Beda dengan repair_dangling_pppoe_profile.php (alat manual, dry-run by
 * default): endpoint ini SELALU langsung memperbaiki (tidak ada mode
 * lihat-saja) -- itu memang tujuannya, supaya secret yang kolom profile-nya
 * jadi referensi menggantung ("*15"/"*0" dst, lihat proses/paket_profile_helpers.php
 * untuk latar belakang bug-nya) otomatis pulih sendiri tanpa admin harus
 * ingat menjalankan alat manual.
 *
 * Target perbaikan tetap KONSERVATIF (lihat pppoeRepairDanglingProfiles()):
 * pelanggan yang sedang isolir (comment diawali "EXPIRED") dikembalikan ke
 * profile EXPIRED, bukan dibuka. Kalau profile tujuan tidak ada di router,
 * secret dilewati dan dicatat di log -- tidak pernah ditebak.
 *
 * Cara pakai (pilih salah satu):
 *   1) CLI (disarankan, lewat crontab):
 *      php /path/ke/crm/billing/getdata/cron_repair_dangling_pppoe_profile.php
 *      Contoh crontab (jalan tiap jam):
 *      0 * * * * php /path/ke/crm/billing/getdata/cron_repair_dangling_pppoe_profile.php >> /path/ke/crm/billing/logs/cron_repair_dangling_pppoe_profile.log 2>&1
 *   2) HTTP (kalau hosting cuma bisa cron "curl/wget URL"):
 *      https://domain-anda/crm/billing/getdata/cron_repair_dangling_pppoe_profile.php?secret=XXXX
 *      (token SAMA dengan cron_scan_pppoe_status.php -- lihat config.json
 *      "pppoe_cron_secret", atau baca dari log/lihat lewat CLI)
 *   3) Lewat menu CRM "Cron Jobs" (cron-jobs.php) -- job "repair_dangling_pppoe"
 *      bisa di-enable untuk crontab, atau dipicu manual lewat tombol "Run".
 */

require_once __DIR__ . '/../koneksibilling.php';
require_once __DIR__ . '/../routeros_api.class.php';
require_once __DIR__ . '/../proses/paket_profile_helpers.php';

$isCli = (php_sapi_name() === 'cli');
$secretToken = !empty($config['pppoe_cron_secret'])
    ? (string) $config['pppoe_cron_secret']
    : hash('sha256', (string) ($config['db_pass'] ?? '') . '|pppoe-cron-2026');

if (!$isCli) {
    header('Content-Type: application/json');
    $given = isset($_GET['secret']) ? (string) $_GET['secret'] : '';
    if (!hash_equals($secretToken, $given)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Forbidden']);
        exit;
    }
}

$logFile = __DIR__ . '/../logs/cron_repair_dangling_pppoe_profile.log';
$lockFile = __DIR__ . '/../logs/cron_repair_dangling_pppoe_profile.lock';

function pppoeRepairCronLog($logFile, $message)
{
    @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND);
}

// Cegah tumpang tindih kalau perbaikan sebelumnya belum selesai (mis. banyak
// router lambat) -- pola sama dengan cron_scan_pppoe_status.php.
$lockHandle = fopen($lockFile, 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    pppoeRepairCronLog($logFile, 'Skip: proses perbaikan sebelumnya masih berjalan.');
    if (!$isCli) {
        echo json_encode(['success' => false, 'message' => 'Perbaikan sebelumnya masih berjalan, dilewati.']);
    }
    exit;
}

$startTime = microtime(true);

$summary = pppoeRepairDanglingProfiles($conn, true, function (string $line) use ($logFile) {
    pppoeRepairCronLog($logFile, '  ' . $line);
});

$durationMs = (int) round((microtime(true) - $startTime) * 1000);

pppoeRepairCronLog($logFile, sprintf(
    'Perbaikan selesai: %d server (%d gagal), %d secret dipindai, %d rusak, %d diperbaiki, %d dilewati, %d ms.',
    $summary['servers_scanned'],
    $summary['servers_failed'],
    $summary['secrets_scanned'],
    $summary['broken'],
    $summary['fixed'],
    $summary['skipped'],
    $durationMs
));

flock($lockHandle, LOCK_UN);
fclose($lockHandle);

$payload = ['duration_ms' => $durationMs] + $summary;

if (!$isCli) {
    echo json_encode(['success' => true] + $payload);
} else {
    echo "OK - {$durationMs}ms - {$summary['broken']} rusak, {$summary['fixed']} diperbaiki, {$summary['skipped']} dilewati - secret token: {$secretToken}\n";
}
