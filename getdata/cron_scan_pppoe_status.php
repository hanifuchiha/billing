<?php
/**
 * Cron scan status PPPoE - dipanggil BERKALA (lewat cron), bukan oleh browser.
 *
 * Tujuannya: connect SEKALI ke tiap Mikrotik unik, ambil SEMUA data
 * /ppp/secret/print + /ppp/active/print + /interface/print sekaligus (bukan
 * satu koneksi per pelanggan seperti getonlinecustomer.php), lalu simpan
 * hasilnya ke file cache JSON. Endpoint get_cached_pppoe_status.php tinggal
 * baca file ini supaya status tampil hampir instan berapa pun banyaknya
 * pelanggan yang ditampilkan di tables.php.
 *
 * Cara pakai (pilih salah satu):
 *   1) CLI (disarankan, lewat crontab):
 *      php /path/ke/crm/billing/getdata/cron_scan_pppoe_status.php
 *   2) HTTP (kalau hosting cuma bisa cron "curl/wget URL"):
 *      https://domain-anda/crm/billing/getdata/cron_scan_pppoe_status.php?secret=XXXX
 *      (nilai XXXX dicetak ke log pertama kali script ini jalan lewat CLI,
 *      atau lihat variabel $secretToken di bawah)
 */

require_once __DIR__ . '/../koneksibilling.php';
require_once __DIR__ . '/../routeros_api.class.php';
require_once __DIR__ . '/pppoe_status_helpers.php';

$isCli = (php_sapi_name() === 'cli');
// Kalau mau pasang cron lewat URL (curl/wget) dan mau token yang gampang diingat,
// tambahkan "pppoe_cron_secret": "terserah-anda" di config.json. Kalau tidak diisi,
// dipakai token otomatis (turunan dari db_pass, cukup buka log/lewat CLI untuk lihat).
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

$logFile = __DIR__ . '/../logs/cron_scan_pppoe_status.log';
$lockFile = __DIR__ . '/../logs/cron_scan_pppoe_status.lock';

function pppoe_cron_log($logFile, $message)
{
    @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND);
}

// Cegah tumpang tindih kalau scan sebelumnya belum selesai (mis. banyak router lambat).
$lockHandle = fopen($lockFile, 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    pppoe_cron_log($logFile, 'Skip: proses scan sebelumnya masih berjalan.');
    if (!$isCli) {
        echo json_encode(['success' => false, 'message' => 'Scan sebelumnya masih berjalan, dilewati.']);
    }
    exit;
}

$startTime = microtime(true);

// Ambil semua kombinasi unik IP + username(PEMILIK) + password dari tabel server.
// Satu kombinasi = satu Mikrotik fisik -> cukup connect sekali walau dipakai banyak AREA.
$servers = [];
$serverSeen = [];
$serverResult = mysqli_query($conn, "SELECT IP, PEMILIK, PASSWORD FROM server WHERE IP IS NOT NULL AND IP <> '' AND PEMILIK IS NOT NULL AND PEMILIK <> ''");
if ($serverResult) {
    while ($row = mysqli_fetch_assoc($serverResult)) {
        $ip = trim((string) $row['IP']);
        $user = trim((string) $row['PEMILIK']);
        $pass = (string) $row['PASSWORD'];
        if ($ip === '' || $user === '') continue;

        $uniqueKey = strtolower($ip . '|' . $user . '|' . $pass);
        if (isset($serverSeen[$uniqueKey])) continue;
        $serverSeen[$uniqueKey] = true;

        $servers[] = ['ip' => $ip, 'user' => $user, 'pass' => $pass];
    }
}

// Snapshot rx/tx-byte hasil scan sebelumnya, dipakai untuk hitung kecepatan
// download/upload (rata-rata sejak scan terakhir), bukan monitor-traffic
// per-pelanggan yang jauh lebih lambat kalau dilakukan satu-satu.
$prevRaw = [];
if (file_exists(PPPOE_STATUS_CACHE_FILE)) {
    $prevDecoded = json_decode((string) @file_get_contents(PPPOE_STATUS_CACHE_FILE), true);
    if (is_array($prevDecoded) && isset($prevDecoded['raw']) && is_array($prevDecoded['raw'])) {
        $prevRaw = $prevDecoded['raw'];
    }
}

$data = [];
$rawSnapshot = [];
$serversFailed = 0;
$usageInputs = [];

foreach ($servers as $srv) {
    $api = new RouterosAPI();
    $api->timeout = 4;
    $api->attempts = 1;
    $api->delay = 0;

    if (!$api->connect($srv['ip'], $srv['user'], $srv['pass'])) {
        $serversFailed++;
        pppoe_cron_log($logFile, "Gagal konek: {$srv['ip']} ({$srv['user']})");
        continue;
    }

    // PENTING: batasi kolom lewat .proplist. Tanpa ini, /ppp/secret/print dan
    // /ppp/active/print menarik SEMUA properti tiap baris (termasuk yang tidak
    // dipakai) - di Mikrotik dengan banyak secret/koneksi ini yang paling
    // memperlambat (bisa puluhan detik per router, jauh lebih lambat dari
    // query per-pelanggan yang di-filter seperti sebelumnya). Pola ini sama
    // seperti yang sudah dipakai scan_active_pppoe.php.
    $scanServerStart = microtime(true);
    $secrets = $api->comm('/ppp/secret/print', [
        '.proplist' => 'name,profile,last-caller-id,last-logged-out,last-disconnect-reason,limit-bytes-in,limit-bytes-out'
    ]);
    $actives = $api->comm('/ppp/active/print', [
        '.proplist' => 'name,caller-id,mac-address,address,uptime,radius,limit-bytes-in,limit-bytes-out'
    ]);
    $interfaces = $api->comm('/interface/print', [
        '?type' => 'pppoe-out',
        '.proplist' => 'name,rx-byte,tx-byte'
    ]);
    $api->disconnect();
    pppoe_cron_log($logFile, sprintf(
        '  - %s (%s): %d secret, %d active, %d interface, %d ms',
        $srv['ip'],
        $srv['user'],
        is_array($secrets) ? count($secrets) : 0,
        is_array($actives) ? count($actives) : 0,
        is_array($interfaces) ? count($interfaces) : 0,
        (int) round((microtime(true) - $scanServerStart) * 1000)
    ));

    $secretMap = [];
    if (is_array($secrets)) {
        foreach ($secrets as $s) {
            $name = trim((string) ($s['name'] ?? ''));
            if ($name === '') continue;
            $secretMap[strtolower($name)] = $s;
        }
    }

    $activeMap = [];
    if (is_array($actives)) {
        foreach ($actives as $a) {
            $name = trim((string) ($a['name'] ?? ''));
            if ($name === '') continue;
            $activeMap[strtolower($name)] = $a;
        }
    }

    $interfaceMap = [];
    if (is_array($interfaces)) {
        foreach ($interfaces as $ifc) {
            $ifName = trim((string) ($ifc['name'] ?? ''));
            if ($ifName === '') continue;
            $interfaceMap[strtolower($ifName)] = $ifc;
        }
    }

    // Gabungkan semua nama yang ada di secret ATAU active (customer terdaftar tapi
    // sedang aktif tanpa secret, atau sebaliknya, tetap ikut ter-cache).
    $allNames = array_unique(array_merge(array_keys($secretMap), array_keys($activeMap)));

    foreach ($allNames as $nameKey) {
        $secretRow = $secretMap[$nameKey] ?? null;
        $activeRow = $activeMap[$nameKey] ?? null;
        $originalName = trim((string) ($secretRow['name'] ?? ($activeRow['name'] ?? $nameKey)));
        if ($originalName === '') continue;

        $isOnline = $activeRow !== null;
        $status = $isOnline ? 'Online' : 'Offline';

        $activeCallerId = $isOnline ? ($activeRow['caller-id'] ?? $activeRow['mac-address'] ?? 'N/A') : 'N/A';
        $remoteIp = $isOnline ? ($activeRow['address'] ?? 'N/A') : 'N/A';
        // Saat offline tidak ada sesi PPP aktif untuk dicek atribut 'radius'-nya, jadi
        // statusnya 'unknown' (BUKAN 'local') -- supaya tables.php tetap mencoba ambil
        // paket dari file user FreeRADIUS (Mikrotik-Group) untuk pelanggan RADIUS yang
        // sedang offline, bukan langsung dianggap LOCAL dan berhenti di profile secret
        // Mikrotik (yang bisa saja 'N/A' kalau pelanggan itu RADIUS-only / tanpa secret).
        $loginSource = 'unknown';
        if ($isOnline) {
            $radiusFlag = $activeRow['radius'] ?? 'false';
            $loginSource = ($radiusFlag === 'true') ? 'radius' : 'local';
        }
        $uptimeRaw = $isOnline ? ($activeRow['uptime'] ?? '') : '';

        $cekexpired = $secretRow['profile'] ?? 'N/A';
        $lastCallerSecret = $secretRow['last-caller-id'] ?? 'N/A';
        $ceklastloggedout = $secretRow['last-logged-out'] ?? 'N/A';
        $ceklastdisconnect = $secretRow['last-disconnect-reason'] ?? 'N/A';

        $secretLimitIn = $secretRow['limit-bytes-in'] ?? '';
        $secretLimitOut = $secretRow['limit-bytes-out'] ?? '';
        if ($isOnline) {
            if (empty($secretLimitIn)) $secretLimitIn = $activeRow['limit-bytes-in'] ?? '';
            if (empty($secretLimitOut)) $secretLimitOut = $activeRow['limit-bytes-out'] ?? '';
        }

        $kuota = format_kuota_text($secretLimitIn, $secretLimitOut);
        $uptime = ($uptimeRaw !== '') ? format_uptime_readable($uptimeRaw) : 'N/A';
        $lastLinkUp = ($uptimeRaw !== '') ? format_last_link_up($uptimeRaw) : 'N/A';

        $interfaceName = '<pppoe-' . $originalName . '>';
        $ifRow = $interfaceMap[strtolower($interfaceName)] ?? null;
        $rxByte = $ifRow['rx-byte'] ?? 0;
        $txByte = $ifRow['tx-byte'] ?? 0;

        // Kecepatan download/upload = delta byte sejak scan sebelumnya, dibagi
        // durasi antar scan (bukan pembacaan instan monitor-traffic, tapi rata-rata
        // per interval scan - cukup representatif untuk tampilan tabel).
        $download = 0.0;
        $upload = 0.0;
        $prevSnap = $prevRaw[$nameKey] ?? null;
        if ($isOnline && $prevSnap && isset($prevSnap['ts'], $prevSnap['rx'], $prevSnap['tx'])) {
            $elapsed = time() - (int) $prevSnap['ts'];
            $deltaRx = (float) $rxByte - (float) $prevSnap['rx'];
            $deltaTx = (float) $txByte - (float) $prevSnap['tx'];
            if ($elapsed > 0 && $deltaRx >= 0 && $deltaTx >= 0) {
                $download = round(($deltaTx * 8) / $elapsed / 1_000_000, 2);
                $upload = round(($deltaRx * 8) / $elapsed / 1_000_000, 2);
            }
        }

        $uptimeSecondsForUsage = $isOnline ? (ros_uptime_to_seconds($uptimeRaw) ?? 0) : 0;
        // Jangan query DB di sini (per-pelanggan) - dikumpulkan dulu, diproses
        // sekaligus lewat batch_track_customer_usage() setelah semua router
        // selesai di-scan. Dengan ribuan pelanggan, 1 query per pelanggan di
        // sinilah yang paling memperlambat scan (bisa puluhan detik).
        $usageInputs[] = [
            'idpel' => $originalName,
            'isOnline' => $isOnline,
            'bytesIn' => $rxByte,
            'bytesOut' => $txByte,
            'uptimeSeconds' => $uptimeSecondsForUsage
        ];
        $lastLinkDown = format_tanggal_indo($ceklastloggedout);

        $data[$nameKey] = [
            'cekexpired' => $cekexpired,
            'last_caller_secret' => $lastCallerSecret,
            'active_caller_id' => $activeCallerId,
            'remote_ip' => $remoteIp,
            'ceklastloggedout' => $ceklastloggedout,
            'ceklastdisconnect' => $ceklastdisconnect,
            'status' => $status,
            'interface' => $interfaceName,
            'download' => $download,
            'upload' => $upload,
            'user' => $originalName,
            'login_via' => $loginSource,
            'kuota' => $kuota,
            'uptime' => $uptime,
            'last_link_up' => $lastLinkUp,
            'last_link_down' => $lastLinkDown,
            'pemakaian' => null // diisi belakangan lewat batch_track_customer_usage()
        ];

        $rawSnapshot[$nameKey] = ['rx' => (float) $rxByte, 'tx' => (float) $txByte, 'ts' => time()];
    }
}

// Satu batch untuk SEMUA pelanggan (1 SELECT + beberapa UPSERT ter-chunk),
// bukan 1 SELECT+UPDATE per pelanggan seperti track_customer_usage() biasa.
$usageResultStart = microtime(true);
$usageResults = batch_track_customer_usage($conn, $usageInputs);
foreach ($usageResults as $nameKey => $totals) {
    if (!isset($data[$nameKey])) continue;
    $data[$nameKey]['pemakaian'] = format_pemakaian_text($totals[0], $totals[1]);
}
foreach ($data as $nameKey => $entry) {
    if ($entry['pemakaian'] === null) {
        $data[$nameKey]['pemakaian'] = 'N/A';
    }
}
pppoe_cron_log($logFile, sprintf(
    '  - batch_track_customer_usage: %d pelanggan, %d ms',
    count($usageInputs),
    (int) round((microtime(true) - $usageResultStart) * 1000)
));

$payload = [
    'generated_at' => time(),
    'duration_ms' => (int) round((microtime(true) - $startTime) * 1000),
    'servers_scanned' => count($servers),
    'servers_failed' => $serversFailed,
    'customers_cached' => count($data),
    'data' => $data,
    'raw' => $rawSnapshot
];

$cacheDir = dirname(PPPOE_STATUS_CACHE_FILE);
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0775, true);
}
@file_put_contents(PPPOE_STATUS_CACHE_FILE, json_encode($payload));

pppoe_cron_log($logFile, sprintf(
    'Scan selesai: %d server (%d gagal), %d pelanggan ter-cache, %d ms.',
    count($servers),
    $serversFailed,
    count($data),
    $payload['duration_ms']
));

flock($lockHandle, LOCK_UN);
fclose($lockHandle);

if (!$isCli) {
    echo json_encode(['success' => true] + $payload);
} else {
    echo "OK - {$payload['duration_ms']}ms - {$payload['customers_cached']} pelanggan - secret token: {$secretToken}\n";
}
