<?php
/**
 * Alat MANUAL untuk deteksi & perbaiki /ppp/secret yang kolom profile-nya
 * jadi referensi menggantung ("*15", "*0", dst) atau menunjuk profile yang
 * tidak ada -- dry-run by default, aman dipakai kapan saja untuk sekadar
 * mengecek. Untuk perbaikan OTOMATIS terjadwal, pakai
 * cron_repair_dangling_pppoe_profile.php (didaftarkan di menu Cron Jobs).
 *
 * PENYEBABNYA sudah ditambal di proses/editpackagespppoe.php,
 * proses/editpackagestaticip.php, proses/deletepackages.php,
 * proses/deletepackagestaticip.php, dan apiinterface.php (hapus paket) --
 * semuanya dulu me-remove PPP Profile yang masih dipakai secret. Script ini
 * membereskan secret yang TERLANJUR rusak sebelum tambalan itu terpasang.
 *
 * Cara pakai (CLI):
 *   php crm/billing/getdata/repair_dangling_pppoe_profile.php            # dry-run (lihat saja)
 *   php crm/billing/getdata/repair_dangling_pppoe_profile.php --apply    # perbaiki beneran
 *
 * Cara pakai (URL / lewat browser atau curl):
 *   1) Cari token: jalankan sekali lewat CLI (baris terakhir cetak "secret
 *      token: ..."), ATAU buka menu CRM "Cron Jobs" (cron-jobs.php) yang
 *      juga mencetak URL siap-pakai untuk job "repair_dangling_pppoe" ini,
 *      ATAU set token sendiri lewat config.json -> "pppoe_cron_secret".
 *   2) Dry-run (lihat saja, TIDAK mengubah apa pun):
 *      https://domain-anda/crm/billing/getdata/repair_dangling_pppoe_profile.php?secret=TOKEN
 *   3) Perbaiki beneran:
 *      https://domain-anda/crm/billing/getdata/repair_dangling_pppoe_profile.php?secret=TOKEN&apply=1
 */

require_once __DIR__ . '/../koneksibilling.php';
require_once __DIR__ . '/../routeros_api.class.php';
require_once __DIR__ . '/../proses/paket_profile_helpers.php';

$isCli = (php_sapi_name() === 'cli');
// Token SAMA dengan cron_scan_pppoe_status.php & cron_repair_dangling_pppoe_profile.php
// (satu sumber: config.json -> "pppoe_cron_secret", atau turunan otomatis dari db_pass).
$secretToken = !empty($config['pppoe_cron_secret'])
    ? (string) $config['pppoe_cron_secret']
    : hash('sha256', (string) ($config['db_pass'] ?? '') . '|pppoe-cron-2026');

if (!$isCli) {
    header('Content-Type: text/plain; charset=UTF-8');
    $given = isset($_GET['secret']) ? (string) $_GET['secret'] : '';
    if (!hash_equals($secretToken, $given)) {
        http_response_code(403);
        echo "Forbidden\n";
        if (!isset($_GET['secret'])) {
            echo "Kirim ?secret=TOKEN (lihat menu Cron Jobs, atau jalankan sekali lewat CLI untuk mencetak token-nya).\n";
        }
        exit;
    }
}

$apply = $isCli
    ? in_array('--apply', $argv ?? [], true)
    : (isset($_GET['apply']) && $_GET['apply'] === '1');

function repairToolLog(string $line): void
{
    echo $line . PHP_EOL;
    if (function_exists('flush')) {
        @flush();
    }
}

repairToolLog($apply
    ? '=== MODE PERBAIKAN (--apply / apply=1): perubahan DITULIS ke router ==='
    : '=== MODE LIHAT SAJA (dry-run): tidak ada yang diubah. Tambah --apply (CLI) atau &apply=1 (URL) untuk memperbaiki. ===');

$summary = pppoeRepairDanglingProfiles($conn, $apply, 'repairToolLog');

repairToolLog('');
repairToolLog(sprintf(
    'SELESAI. Router dipindai: %d (gagal konek: %d) | Secret dipindai: %d | Rusak: %d | Diperbaiki: %d | Dilewati: %d',
    $summary['servers_scanned'],
    $summary['servers_failed'],
    $summary['secrets_scanned'],
    $summary['broken'],
    $summary['fixed'],
    $summary['skipped']
));

if (!$apply && $summary['broken'] > 0) {
    repairToolLog('Jalankan ulang dengan --apply (CLI) atau &apply=1 (URL) untuk benar-benar memperbaiki.');
}

if ($isCli && !$apply) {
    repairToolLog('');
    repairToolLog("Token untuk mode URL: secret={$secretToken}");
}
