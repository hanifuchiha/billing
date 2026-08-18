<?php
/**
 * Diagnosa KENAPA secret dengan profile rusak dilewati oleh
 * repair_dangling_pppoe_profile.php / cron_repair_dangling_pppoe_profile.php.
 *
 * pppoeRepairDanglingProfiles() (proses/paket_profile_helpers.php) melewati
 * secret rusak kalau usernamenya tidak ketemu di tabel `pelanggan` (IDPEL) --
 * SENGAJA tidak menebak paket, supaya tidak salah pasang profile. Tool ini
 * mengecek KENAPA tidak ketemu:
 *   - Ada di `pelanggan_berhenti` (pelanggan sudah berhenti, secret di router
 *     tidak pernah dihapus)?
 *   - Ada di `pelanggan` tapi IDPEL-nya beda tipis (spasi/case)?
 *   - Betul-betul tidak ada jejaknya di database sama sekali?
 *
 * Cara pakai (CLI):
 *   php crm/billing/getdata/diagnose_dangling_pppoe_profile.php
 */

require_once __DIR__ . '/../koneksibilling.php';
require_once __DIR__ . '/../routeros_api.class.php';
require_once __DIR__ . '/../proses/paket_profile_helpers.php';

$isCli = (php_sapi_name() === 'cli');
$secretToken = !empty($config['pppoe_cron_secret'])
    ? (string) $config['pppoe_cron_secret']
    : hash('sha256', (string) ($config['db_pass'] ?? '') . '|pppoe-cron-2026');

if (!$isCli) {
    header('Content-Type: text/plain; charset=UTF-8');
    $given = isset($_GET['secret']) ? (string) $_GET['secret'] : '';
    if (!hash_equals($secretToken, $given)) {
        http_response_code(403);
        echo "Forbidden\n";
        exit;
    }
}

function diagLog(string $line): void
{
    echo $line . PHP_EOL;
    if (function_exists('flush')) {
        @flush();
    }
}

$hasBerhentiTable = false;
$checkBerhenti = mysqli_query($conn, "SHOW TABLES LIKE 'pelanggan_berhenti'");
if ($checkBerhenti && mysqli_num_rows($checkBerhenti) > 0) {
    $hasBerhentiTable = true;
}

$servers = pppoeUniqueServers($conn);
diagLog('Router unik ditemukan: ' . count($servers));
diagLog('Tabel pelanggan_berhenti ada: ' . ($hasBerhentiTable ? 'ya' : 'tidak'));
diagLog('');

$totalBroken = 0;
$totalBerhenti = 0;
$totalNearMatch = 0;
$totalNoTrace = 0;

foreach ($servers as $srv) {
    $api = new RouterosAPI();
    $api->timeout = 5;
    $api->attempts = 1;
    $api->delay = 0;

    if (!$api->connect($srv['ip'], $srv['user'], $srv['pass'])) {
        diagLog("[SKIP KONEK] {$srv['ip']} ({$srv['user']})");
        continue;
    }

    $profileNames = [];
    $profiles = $api->comm('/ppp/profile/print', ['.proplist' => 'name']);
    if (is_array($profiles)) {
        foreach ($profiles as $p) {
            if (!is_array($p) || !isset($p['name'])) continue;
            $name = trim((string) $p['name']);
            if ($name === '') continue;
            $profileNames[strtolower($name)] = true;
        }
    }

    $secrets = $api->comm('/ppp/secret/print', ['.proplist' => '.id,name,profile,comment']);
    if (!is_array($secrets)) {
        $api->disconnect();
        continue;
    }

    foreach ($secrets as $s) {
        if (!is_array($s) || !isset($s['name'])) continue;

        $idpel = trim((string) $s['name']);
        $profileRaw = trim((string) ($s['profile'] ?? ''));
        $isDanglingId = (bool) preg_match('/^\*[0-9A-Fa-f]+$/', $profileRaw);
        $isMissingName = ($profileRaw !== '' && !$isDanglingId && !isset($profileNames[strtolower($profileRaw)]));
        if (!$isDanglingId && !$isMissingName) {
            continue;
        }

        $totalBroken++;
        $idpelEsc = mysqli_real_escape_string($conn, $idpel);

        // 1) Ada di pelanggan aktif (harusnya sudah tertangani repair, tapi cek juga)?
        $rowAktif = mysqli_fetch_assoc(mysqli_query($conn, "SELECT IDPEL, PAKET FROM pelanggan WHERE IDPEL = '$idpelEsc' LIMIT 1"));

        // 2) Ada di pelanggan_berhenti?
        $rowBerhenti = null;
        if ($hasBerhentiTable) {
            $rowBerhenti = mysqli_fetch_assoc(mysqli_query($conn, "SELECT idpel, nama, tanggal_berhenti, alasan FROM pelanggan_berhenti WHERE idpel = '$idpelEsc' LIMIT 1"));
        }

        // 3) Near-match (beda spasi/case) di pelanggan?
        $rowNear = mysqli_fetch_assoc(mysqli_query($conn, "SELECT IDPEL FROM pelanggan WHERE TRIM(IDPEL) LIKE '$idpelEsc' LIMIT 1"));

        if ($rowAktif) {
            diagLog("[ADA DI PELANGGAN AKTIF] $idpel -> PAKET='{$rowAktif['PAKET']}' (harusnya tidak ke-skip -- cek ulang)");
        } elseif ($rowBerhenti) {
            $totalBerhenti++;
            diagLog("[SUDAH BERHENTI] $idpel -- nama={$rowBerhenti['nama']}, berhenti={$rowBerhenti['tanggal_berhenti']}, alasan={$rowBerhenti['alasan']} -- secret masih tertinggal di router {$srv['ip']}");
        } elseif ($rowNear) {
            $totalNearMatch++;
            diagLog("[NEAR-MATCH] $idpel mirip dengan '{$rowNear['IDPEL']}' di pelanggan (cek spasi/case)");
        } else {
            $totalNoTrace++;
            diagLog("[TIDAK ADA JEJAK] $idpel -- tidak ada di pelanggan maupun pelanggan_berhenti (router {$srv['ip']})");
        }
    }

    $api->disconnect();
}

diagLog('');
diagLog("RINGKASAN: $totalBroken rusak total | $totalBerhenti sudah berhenti | $totalNearMatch near-match | $totalNoTrace tanpa jejak sama sekali");
