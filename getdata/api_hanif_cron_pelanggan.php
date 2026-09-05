<?php

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
@set_time_limit(0);
@ini_set('display_errors', '0');

// Mode debug: panggil dengan ?debug=1 supaya semua PHP error/warning/notice
// ditampilkan di layar (berguna waktu troubleshooting). JANGAN dibiarkan
// aktif terus-terusan di cron produksi karena bisa merusak format JSON output.
if (!empty($_GET['debug'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
}

// Tangkap fatal error (mis. undefined function/class, error koneksi DB, dst)
// supaya tidak cuma jadi layar putih kosong tanpa keterangan.
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        @file_put_contents(
            __DIR__ . '/log_sync_pelanggan_keuangan.log',
            '[' . date('Y-m-d H:i:s') . "] FATAL PHP ERROR: " . json_encode($error, JSON_UNESCAPED_UNICODE) . "\n",
            FILE_APPEND
        );
        if (!empty($_GET['debug'])) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode(['ok' => false, 'fatal_error' => $error], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }
    }
});

// ================= CEK PRASYARAT DASAR =================
if (!function_exists('curl_init')) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'message' => 'Ekstensi PHP cURL tidak aktif di server ini (curl_init tidak ditemukan). Aktifkan ext-curl lalu coba lagi.',
    ]);
    exit;
}

// ================= KONEKSI DATABASE =================
$possibleKoneksiPaths = [
    __DIR__ . '/../koneksibilling.php',
    __DIR__ . '/../conn.php',
    __DIR__ . '/../db.php',
    __DIR__ . '/../config.php',
    __DIR__ . '/../../koneksi.php',
    __DIR__ . '/../../conn.php',
    __DIR__ . '/../../db.php',
    __DIR__ . '/../../config.php',
];
$koneksiLoaded = false;
foreach ($possibleKoneksiPaths as $p) {
    if (file_exists($p)) {
        require_once $p;
        $koneksiLoaded = true;
        break;
    }
}

header('Content-Type: application/json; charset=utf-8');

if (!$koneksiLoaded || !isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'File koneksi database tidak ditemukan / tidak menghasilkan variabel $conn (mysqli). Edit daftar $possibleKoneksiPaths di bagian atas script ini.',
    ]);
    exit;
}

// ================= KONFIGURASI =================
$requestHost = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
$requestHost = preg_replace('/:\d+$/', '', $requestHost);
$isLocalRequest = in_array($requestHost, ['localhost', '127.0.0.1', '::1'], true);
$configuredKeuanganBaseUrl = trim((string)getenv('KEUANGAN_SYNC_BASE_URL'));
if ($configuredKeuanganBaseUrl === '') {
    $configuredKeuanganBaseUrl = $isLocalRequest
        ? 'http://127.0.0.1/keuangan/sistem-manajemen-keuangan/index.php/admin/site'
        : 'https://billing.broadbandairlink.com/web/keuangan/sistem-manajemen-keuangan/index.php/admin/site';
}
define('KEU_BASE_URL', rtrim($configuredKeuanganBaseUrl, '/'));
define('KEU_API_KEY', '9fa120bc7d157225c58238c91051c7f2baf37c5338d75f8c9c260082babe2de8');
define('KEU_PELANGGAN_ENDPOINT', KEU_BASE_URL . '/pelanggan_api');
define('KEU_AREA_SITE_ENDPOINT', KEU_BASE_URL . '/area_site_api');
// Endpoint RESMI untuk mencatat pembayaran pelanggan (dari dokumentasi bagian
// "5. PAYMENT HISTORY DAN TRANSAKSI PELANGGAN"). Endpoint inilah yang benar-benar
// membuat baris di payment_history + transaksi + saldo bank, BUKAN pelanggan_api
// (itu cuma untuk data pelanggan) dan BUKAN pelanggan_modal_save (field-nya
// tidak didokumentasikan resmi dan sudah terbukti tidak membuat entri payment).
define('KEU_PELANGGAN_PAYMENT_ENDPOINT', KEU_BASE_URL . '/pelanggan_payment_api');

define('KEU_AUTO_CREATE_SITE', true);
define('KEU_PAYMENT_SYNC_ENABLED', true);

define('SYNC_LOG_FILE', __DIR__ . '/log_sync_pelanggan_keuangan.log');
define('SYNC_LOCK_FILE', __DIR__ . '/cron_sync_pelanggan_keuangan.lock');
define('SYNC_LASTRUN_FILE', __DIR__ . '/cron_sync_pelanggan_keuangan.lastrun');
define('SYNC_OVERRIDE_MAP_FILE', __DIR__ . '/keuangan_site_override.json');
// Mapping manual METODE_BAYAR (lokal) -> bank_id (keuangan), dipakai kalau
// pencarian otomatis by nama bank gagal/ambigu. Format file JSON:
// { "cash": 1, "transfer": 7, "qris": 9 }
define('SYNC_BANK_OVERRIDE_MAP_FILE', __DIR__ . '/keuangan_bank_override.json');
// Baseline transaksi saat sinkronisasi production diaktifkan. Hanya transaksi
// dengan id lebih besar dari nilai ini yang boleh dikirim ke Keuangan, sehingga
// transaksi historis tidak ikut ter-backfill pada run pertama.
define('SYNC_TRANSACTION_START_ID_FILE', __DIR__ . '/keuangan_transaction_start_id.txt');
$syncTransactionStartId = is_file(SYNC_TRANSACTION_START_ID_FILE)
    ? (int)trim((string)file_get_contents(SYNC_TRANSACTION_START_ID_FILE))
    : PHP_INT_MAX;
define('SYNC_TRANSACTION_START_ID', max(0, $syncTransactionStartId));

// ---- Lokasi file bukti pembayaran (opsional) ----
// Kolom `transaksi.BUKTI` di DB lokal biasanya berisi nama file / path relatif
// hasil upload bukti transfer di CRM. EDIT daftar di bawah ini kalau folder
// upload bukti pembayaran CRM Anda tidak ada di salah satu path berikut.
define('SYNC_BUKTI_BASE_DIRS', [
    __DIR__ . '/../uploads/bukti',
    __DIR__ . '/../uploads/transaksi',
    __DIR__ . '/../uploads',
    __DIR__ . '/../bukti',
    __DIR__ . '/uploads',
]);
define('SYNC_BUKTI_MAX_BYTES', 10 * 1024 * 1024); // 10MB sesuai batas API keuangan
define('SYNC_BUKTI_ALLOWED_EXT', ['jpg', 'jpeg', 'png', 'pdf']);

define('SYNC_SECRET', trim((string)getenv('BILLING_CRON_API_KEY')));

$onlyIdpel = trim((string)($_GET['only'] ?? $_POST['only'] ?? ''));
$dryRun    = !empty($_GET['dry_run']) || !empty($_POST['dry_run']);
$source    = (string)($_GET['src'] ?? 'manual');

// Pemanggilan HTTP dari jaringan wajib diautentikasi. Request loopback hanya
// dapat berasal dari server ini, sehingga cron lokal tidak perlu menyimpan API
// key di URL. Jika memakai URL publik, kirim key lewat header X-API-Key.
$requestRemoteAddress = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
$isLoopbackRequest = in_array($requestRemoteAddress, ['127.0.0.1', '::1'], true);
if (PHP_SAPI !== 'cli' && !$isLoopbackRequest) {
    $expectedCronKey = SYNC_SECRET !== '' ? SYNC_SECRET : KEU_API_KEY;
    $providedCronKey = trim((string)($_SERVER['HTTP_X_API_KEY'] ?? ''));
    if ($providedCronKey === '') {
        $providedCronKey = trim((string)($_GET['secret'] ?? ''));
    }
    if ($expectedCronKey === '' || !hash_equals($expectedCronKey, $providedCronKey)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'API key cron tidak valid.']);
        exit;
    }
}

// ================= LOCK, biar tidak overlap antar-run =================
$lockFp = fopen(SYNC_LOCK_FILE, 'c');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    http_response_code(423);
    echo json_encode(['ok' => false, 'message' => 'Proses sync sebelumnya masih berjalan (lock aktif).']);
    exit;
}

$summary = [
    'ok' => true,
    'started_at' => date('Y-m-d H:i:s'),
    'source' => $source,
    'dry_run' => $dryRun,
    'transaction_start_id' => SYNC_TRANSACTION_START_ID,
    'customers_checked' => 0,
    'customers_created' => 0,
    'customers_updated' => 0,
    'customers_unchanged' => 0,
    'customers_site_reassigned' => 0,
    'customers_remote_missing_repaired' => 0,
    'customers_skipped_duplicate_remote' => 0,
    'customers_skipped_unmapped_area' => 0,
    'customers_skipped_empty_idpel' => 0,
    'customers_failed' => 0,
    'sites_created' => 0,
    'sites_create_failed' => 0,
    'transaksi_synced' => 0,
    'transaksi_failed' => 0,
    'transaksi_skipped_missing_bukti' => 0,
    'transaksi_without_bukti' => 0,
    'transaksi_skipped_excluded_method' => 0,
    'transaksi_skipped_unmapped_bank' => 0,
    'transaksi_skipped_already_exists' => 0,
    'transaksi_skipped_invalid_date' => 0,
    'transaksi_reconciled_missing_in_keuangan' => 0,
    'errors' => [],
];

// ================= HELPER =================

function syncLog(string $line): void
{
    $ts = date('Y-m-d H:i:s');
    @file_put_contents(SYNC_LOG_FILE, "[$ts] $line\n", FILE_APPEND);
}

function ensureColumnExists(mysqli $conn, string $table, string $column, string $definitionSql): void
{
    $tableSafe = mysqli_real_escape_string($conn, $table);
    $columnSafe = mysqli_real_escape_string($conn, $column);
    $check = mysqli_query($conn, "SHOW COLUMNS FROM `$tableSafe` LIKE '$columnSafe'");
    if ($check && mysqli_num_rows($check) === 0) {
        mysqli_query($conn, "ALTER TABLE `$tableSafe` ADD COLUMN `$columnSafe` $definitionSql");
    }
}

function markPelangganSyncAttempt(mysqli $conn, int $localId): void
{
    mysqli_query(
        $conn,
        "UPDATE pelanggan
         SET KEUANGAN_SYNC_STATUS='processing',
             KEUANGAN_SYNC_ERROR=NULL,
             KEUANGAN_SYNC_ATTEMPTS=COALESCE(KEUANGAN_SYNC_ATTEMPTS, 0) + 1,
             KEUANGAN_SYNC_LAST_ATTEMPT=NOW()
         WHERE id=$localId"
    );
}

function markPelangganSyncFailed(mysqli $conn, int $localId, string $message): void
{
    $messageSafe = mysqli_real_escape_string($conn, mb_substr(trim($message), 0, 2000, 'UTF-8'));
    mysqli_query(
        $conn,
        "UPDATE pelanggan
         SET KEUANGAN_SYNC_STATUS='failed', KEUANGAN_SYNC_ERROR='$messageSafe'
         WHERE id=$localId"
    );
}

/**
 * Request JSON biasa (GET atau POST-JSON) ke API keuangan.
 */
function keuHttpRequest(string $method, string $url, ?array $payload = null): array
{
    $ch = curl_init();
    $headers = [
        'X-API-Key: ' . KEU_API_KEY,
        'Accept: application/json',
    ];

    if (strtoupper($method) === 'GET') {
        curl_setopt($ch, CURLOPT_URL, $url);
    } else {
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $headers[] = 'Content-Type: application/json';
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $raw = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    $json = $raw !== false && $raw !== null ? json_decode((string)$raw, true) : null;

    return [
        'http_code' => $httpCode,
        'error' => $err,
        'raw' => $raw,
        'json' => $json,
    ];
}

/**
 * Request multipart/form-data ke API keuangan - dipakai khusus untuk
 * pelanggan_payment_api karena bukti_file, jika tersedia, dikirim sebagai file
 * upload (bukan base64/JSON). $fields adalah array asosiatif field biasa,
 * $filePath (kalau ada & valid) dikirim sebagai file bukti_file.
 */
function keuHttpRequestMultipart(string $url, array $fields, ?string $filePath, string $fileMime): array
{
    $ch = curl_init();
    $headers = [
        'X-API-Key: ' . KEU_API_KEY,
        'Accept: application/json',
        // Sengaja TIDAK set Content-Type manual - biar cURL yang generate
        // boundary multipart otomatis.
    ];

    $postFields = $fields;
    if ($filePath !== null && is_file($filePath)) {
        $postFields['bukti_file'] = new CURLFile($filePath, $fileMime, basename($filePath));
    }

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $raw = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    $json = $raw !== false && $raw !== null ? json_decode((string)$raw, true) : null;

    return [
        'http_code' => $httpCode,
        'error' => $err,
        'raw' => $raw,
        'json' => $json,
    ];
}

/**
 * Normalisasi nama AREA/site utk pencocokan case/whitespace-insensitive.
 * Dipakai supaya "Home-13960.01-16_GEDUNG-B" dan "home-13960.01-16_gedung-b"
 * (mis. beda kapitalisasi krn diketik manual/edit) dikenali sbg site yang
 * SAMA, bukan dianggap belum ada lalu dibuatkan site DUPLIKAT di keuangan.
 */
function normalizeAreaKey(string $value): string
{
    return mb_strtolower(trim($value), 'UTF-8');
}

function normalizeIdpelKey(string $value): string
{
    return mb_strtolower(trim($value), 'UTF-8');
}

function resolveSite(string $area, array $remoteSiteByName, array $overrideMap, array $remoteSiteByNormalizedName = [], array $overrideMapByNormalized = []): ?array
{
    $areaTrim = trim($area);

    if ($areaTrim !== '' && isset($overrideMap[$areaTrim])) {
        return $overrideMap[$areaTrim];
    }

    if ($areaTrim !== '') {
        $overrideKey = normalizeAreaKey($areaTrim);
        if (isset($overrideMapByNormalized[$overrideKey])) {
            return $overrideMapByNormalized[$overrideKey];
        }
    }

    if ($areaTrim !== '' && isset($remoteSiteByName[$areaTrim])) {
        $s = $remoteSiteByName[$areaTrim];
        return [
            'source' => $s['source'] ?? 'site',
            'id_site' => $s['site_ref_id'] ?? $s['id_site'] ?? null,
            'site_name' => $s['site_name'] ?? $areaTrim,
        ];
    }

    // BUG (fixed): sebelumnya pencocokan cuma exact-match (case & whitespace
    // sensitif). Kalau AREA lokal beda kapitalisasi tipis dari site_name yang
    // sudah ada di keuangan (padahal site-nya SAMA), resolveSite() balikin
    // null -> pemanggil kira site belum ada -> createRemoteSite() dipanggil
    // lagi -> site DUPLIKAT (persis kasus server Mikrotik "GEDUNG-B" yang
    // sempat kebuat dobel gara-gara hal serupa). Fallback pencocokan
    // case/whitespace-insensitive di sini supaya site yang sudah ada
    // (walau beda kapitalisasi) tetap dikenali & TIDAK dibuat ulang.
    if ($areaTrim !== '') {
        $normalized = normalizeAreaKey($areaTrim);
        if (isset($remoteSiteByNormalizedName[$normalized])) {
            $s = $remoteSiteByNormalizedName[$normalized];
            return [
                'source' => $s['source'] ?? 'site',
                'id_site' => $s['site_ref_id'] ?? $s['id_site'] ?? null,
                'site_name' => $s['site_name'] ?? $areaTrim,
            ];
        }
    }

    return null;
}

function buildCustomerPayload(array $row, array $site): array
{
    return [
        'source'        => $site['source'] ?? 'site',
        'id_site'       => $site['id_site'] ?? null,
        'site_name'     => $site['site_name'] ?? null,
        'billing_area'  => trim((string)($row['AREA'] ?? '')),
        'IDPEL'         => $row['IDPEL'],
        'NAMA'          => (string)($row['NAMA'] ?? ''),
        'TIPE_BAYAR'    => (string)($row['TIPE_BAYAR'] ?? 'prabayar'),
        'TIPE_TEMPO'    => (string)($row['TIPE_TEMPO'] ?? 'mengikuti_tanggal_tempo'),
        'PAKET'         => (string)($row['PAKET'] ?? ''),
        'HARGA'         => (string)($row['HARGA_PAKET'] ?? '0'),
        'ALAMAT'        => (string)($row['ALAMAT'] ?? ''),
        'no_rumah'      => (string)($row['NO_RUMAH'] ?? '-'),
        'rt'            => (string)($row['RT'] ?? '-'),
        'rw'            => (string)($row['RW'] ?? '-'),
        'NOWA'          => (string)($row['NOWA'] ?? ''),
        'TANGGALPASANG' => (string)($row['TANGGALPASANG'] ?? ''),
        'EMAIL'         => (string)($row['EMAIL'] ?? ''),
        'ODP'           => (string)($row['ODP'] ?? ''),
        'TIKOR'         => (string)($row['TIKOR'] ?? ''),
        'sales'         => (string)($row['sales'] ?? ''),
        'PEMILIK'       => (string)($row['PEMILIK'] ?? ''),
        'BRAND'         => (string)($row['BRAND'] ?? ''),
        'kecamatan'     => (string)($row['kecamatan'] ?? ''),
        'kelurahan'     => (string)($row['kelurahan'] ?? ''),
        'provinsi'      => (string)($row['provinsi'] ?? ''),
        'kabupaten'     => (string)($row['kabupaten'] ?? ''),
    ];
}

function createRemoteSite(string $areaName, array $sampleRow, array &$summary): ?array
{
    $payload = [
        'nama_site'            => $areaName,
        'alamat'               => (string)($sampleRow['ALAMAT'] ?? ''),
        'koordinat'            => (string)($sampleRow['TIKOR'] ?? ''),
        'pic_penanggung_jawab' => (string)($sampleRow['sales'] ?? ($sampleRow['PEMILIK'] ?? '')),
        'no_kontak'            => (string)($sampleRow['NOWA'] ?? ''),
        'keterangan'           => 'Dibuat otomatis oleh cron sync CRM (AREA: ' . $areaName . ')',
    ];

    $resp = keuHttpRequest('POST', KEU_AREA_SITE_ENDPOINT, $payload);

    if (in_array($resp['http_code'], [200, 201], true) && !empty($resp['json']['ok'])) {
        $areaObj = $resp['json']['area'] ?? [];
        $idSite = (int)($areaObj['id'] ?? 0);
        if ($idSite <= 0) {
            $summary['sites_create_failed']++;
            syncLog("Site '$areaName' terbuat tapi response tidak berisi area.id yang valid: " . json_encode($resp['json']));
            return null;
        }
        $summary['sites_created']++;
        syncLog("Site baru dibuat: AREA='$areaName' -> id_site=$idSite");
        return [
            'source' => 'site',
            'id_site' => $idSite,
            'site_name' => (string)($areaObj['nama_site'] ?? $areaName),
        ];
    }

    if ($resp['http_code'] === 409) {
        // Coba pulihkan id_site yang sudah ada dari body respons 409 (kalau
        // API menyertakannya) -- supaya site yang MEMANG sudah ada tetap
        // dikenali & dipakai (bukan dianggap gagal & di-skip terus tiap run).
        // Sebelumnya 409 SELALU return null -- pelanggan di AREA itu jadi
        // TIDAK PERNAH ke-sync ke keuangan sampai ada yang menambahkan
        // mapping manual, walau site-nya sebenarnya sudah tersedia.
        $existingArea = $resp['json']['area'] ?? $resp['json']['site'] ?? $resp['json']['data'] ?? null;
        if (is_array($existingArea)) {
            $existingId = (int)($existingArea['id'] ?? $existingArea['id_site'] ?? 0);
            if ($existingId > 0) {
                syncLog("Site '$areaName' sudah ada di keuangan (409), id_site dipulihkan dari respons: $existingId");
                return [
                    'source' => 'site',
                    'id_site' => $existingId,
                    'site_name' => (string)($existingArea['nama_site'] ?? $areaName),
                ];
            }
        }

        $summary['sites_create_failed']++;
        syncLog("SKIP: site '$areaName' sudah ada di keuangan (409) tapi id_site-nya tidak diketahui dari sini (respons tidak menyertakan data site). Tambahkan manual ke keuangan_site_override.json.");
        return null;
    }

    $summary['sites_create_failed']++;
    $msg = "Gagal membuat site '$areaName': HTTP {$resp['http_code']} - " . ($resp['json']['message'] ?? $resp['error']);
    $summary['errors'][] = $msg;
    syncLog($msg);
    return null;
}

function markPelangganSynced(mysqli $conn, int $localId, int $keuanganId, array $site, string $hash): void
{
    $idSite = (int)($site['id_site'] ?? 0);
    $source = mysqli_real_escape_string($conn, (string)($site['source'] ?? 'site'));
    $hashSafe = mysqli_real_escape_string($conn, $hash);
    mysqli_query(
        $conn,
        "UPDATE pelanggan
         SET KEUANGAN_ID=$keuanganId,
             KEUANGAN_SITE_REF_ID=$idSite,
             KEUANGAN_SITE_SOURCE='$source',
             KEUANGAN_LAST_HASH='$hashSafe',
             KEUANGAN_LAST_SYNC=NOW(),
             KEUANGAN_SYNC_STATUS='synced',
             KEUANGAN_SYNC_ERROR=NULL
         WHERE id=$localId"
    );
}

/**
 * Ambil daftar bank/kas dari keuangan (dipakai untuk resolve bank_id, field
 * WAJIB di pelanggan_payment_api). Sesuai dokumentasi bagian 5:
 * GET pelanggan_payment_api?meta=1
 */
function fetchPaymentMeta(): array
{
    $resp = keuHttpRequest('GET', KEU_PELANGGAN_PAYMENT_ENDPOINT . '?meta=1');
    if ($resp['http_code'] !== 200 || !is_array($resp['json'])) {
        syncLog('WARNING: gagal ambil metadata bank (meta=1). HTTP ' . $resp['http_code'] . ' - ' . ($resp['error'] ?: $resp['raw']));
        return ['banks' => [], 'billing_bank_mappings' => []];
    }
    $banks = $resp['json']['banks'] ?? $resp['json']['data']['banks'] ?? [];
    $out = [];
    foreach ($banks as $b) {
        $id = $b['id'] ?? $b['bank_id'] ?? null;
        $nama = (string)($b['bank_nama'] ?? $b['nama'] ?? '');
        if ($id !== null && $nama !== '') {
            $out[] = ['id' => (int)$id, 'nama' => $nama];
        }
    }
    $mappings = $resp['json']['billing_bank_mappings'] ?? $resp['json']['data']['billing_bank_mappings'] ?? [];
    return [
        'banks' => $out,
        'billing_bank_mappings' => is_array($mappings) ? $mappings : [],
    ];
}

/**
 * Cari bank_id yang cocok untuk METODE_BAYAR lokal (cash/transfer/gateway dst).
 * Urutan pencarian: mapping Keuangan/override manual -> nama bank yang sama
 * persis. Return null kalau tidak ketemu; metode umum seperti cash/transfer
 * tidak boleh ditebak karena bisa salah mencatat saldo kas/bank.
 */
function resolveBankId(string $metodeBayarRaw, array $banksList, array $bankOverrideMap): ?int
{
    $metode = strtolower(trim($metodeBayarRaw));
    if ($metode === '') {
        $metode = 'lainnya';
    }

    if (isset($bankOverrideMap[$metode])) {
        return (int)$bankOverrideMap[$metode];
    }

    foreach ($banksList as $b) {
        $namaLower = strtolower(trim((string)$b['nama']));
        if ($namaLower === $metode) {
            return (int)$b['id'];
        }
    }

    return null;
}

/**
 * Cari file bukti pembayaran di disk berdasarkan nilai kolom transaksi.BUKTI.
 * Mendukung: nama file saja (dicari di SYNC_BUKTI_BASE_DIRS), path relatif,
 * path absolut, atau URL http(s) (didownload ke file temporer).
 * Return [path_lokal, mime] atau null kalau tidak ketemu / tidak valid.
 */
function resolveBuktiFile(string $buktiValue): ?array
{
    $buktiValue = trim($buktiValue);
    if ($buktiValue === '') {
        return null;
    }

    $extOk = function (string $path) {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, SYNC_BUKTI_ALLOWED_EXT, true) ? $ext : null;
    };

    $mimeFor = function (string $ext) {
        return [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png', 'pdf' => 'application/pdf',
        ][$ext] ?? 'application/octet-stream';
    };

    // URL remote -> download ke file temp.
    if (preg_match('#^https?://#i', $buktiValue)) {
        $ext = $extOk($buktiValue);
        if (!$ext) {
            return null;
        }
        $ch = curl_init($buktiValue);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $data = curl_exec($ch);
        curl_close($ch);
        if ($data === false || strlen($data) === 0 || strlen($data) > SYNC_BUKTI_MAX_BYTES) {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'bukti_') . '.' . $ext;
        file_put_contents($tmp, $data);
        return [$tmp, $mimeFor($ext)];
    }

    // Path absolut langsung.
    if (is_file($buktiValue)) {
        $ext = $extOk($buktiValue);
        if (!$ext || filesize($buktiValue) > SYNC_BUKTI_MAX_BYTES) {
            return null;
        }
        return [$buktiValue, $mimeFor($ext)];
    }

    // Coba gabungkan dengan tiap base dir yang dikonfigurasi.
    foreach (SYNC_BUKTI_BASE_DIRS as $baseDir) {
        $candidate = rtrim($baseDir, '/') . '/' . ltrim($buktiValue, '/');
        if (is_file($candidate)) {
            $ext = $extOk($candidate);
            if (!$ext || filesize($candidate) > SYNC_BUKTI_MAX_BYTES) {
                continue;
            }
            return [$candidate, $mimeFor($ext)];
        }
    }

    return null;
}

/**
 * Parse nilai TANGGALBAYAR dari tabel transaksi lokal menjadi format Y-m-d
 * yang valid untuk dikirim ke keuangan.
 *
 * PENTING: fungsi ini SENGAJA tidak pernah fallback diam-diam ke epoch
 * (1970-01-01) atau ke tanggal hari ini kalau nilainya tidak bisa diparse -
 * itu bug lama: `date('Y-m-d', strtotime($raw))` kalau strtotime() gagal
 * (return false) menghasilkan 1970-01-01 tanpa terdeteksi. Sekarang kalau
 * tanggal tidak valid, function ini return null dan CALLER WAJIB skip
 * transaksi tsb (jangan pernah kirim dengan tanggal yang salah/ditebak).
 *
 * CATATAN PENTING: kolom TANGGALBAYAR di CRM ini ternyata menyimpan teks
 * ter-format Bahasa Indonesia, misal "Minggu, 12 Juli 2026" (bukan format
 * tanggal standar seperti "2026-07-12"). strtotime() PHP cuma paham nama
 * bulan/hari Inggris, jadi SELALU gagal parse nama bulan Indonesia (Juli,
 * Agustus, dst) - ini akar bug 1970-01-01 yang sebelumnya terjadi. Karena
 * itu format Indonesia dicoba parse SECARA EKSPLISIT lebih dulu di bawah.
 */
function parseTanggalBayar(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '' || preg_match('/^0000-00-00/', $raw)) {
        return null;
    }

    // ---- Coba parse format Indonesia dulu: "[NamaHari, ]D NamaBulan YYYY" ----
    // Nama hari (Minggu, Senin, dst) di depan - kalau ada - diabaikan saja
    // karena tidak dibutuhkan untuk membentuk tanggal, cukup ambil pola
    // "angka + nama bulan + tahun" di mana pun posisinya dalam string.
    $bulanMap = [
        'januari' => '01', 'februari' => '02', 'maret' => '03', 'april' => '04',
        'mei' => '05', 'juni' => '06', 'juli' => '07', 'agustus' => '08',
        'september' => '09', 'oktober' => '10', 'november' => '11', 'desember' => '12',
    ];
    if (preg_match('/(\d{1,2})\s+([A-Za-z]+)\s+(\d{4})/u', $raw, $m)) {
        $day = (int)$m[1];
        $bulanNama = strtolower($m[2]);
        $year = (int)$m[3];
        if (isset($bulanMap[$bulanNama])) {
            $bulanNum = $bulanMap[$bulanNama];
            if (checkdate((int)$bulanNum, $day, $year)) {
                return sprintf('%04d-%s-%02d', $year, $bulanNum, $day);
            }
        }
    }

    // ---- Fallback: format tanggal standar (Inggris/ISO/dd-mm-yyyy dst) ----
    $ts = strtotime($raw);

    if ($ts === false) {
        // strtotime() gagal - coba beberapa format eksplisit umum yang sering
        // dipakai di CRM billing sebelum benar-benar menyerah.
        foreach (['d-m-Y', 'd/m/Y', 'Y-m-d', 'Y/m/d', 'd-m-Y H:i:s', 'Y-m-d H:i:s', 'd/m/Y H:i:s'] as $fmt) {
            $dt = DateTime::createFromFormat($fmt, $raw);
            if ($dt !== false) {
                $ts = $dt->getTimestamp();
                break;
            }
        }
    }

    if (!$ts || $ts <= 0) {
        return null;
    }

    // Sanity check tahun - kalau hasil parse di luar rentang wajar, anggap
    // gagal parse (bukan tanggal sungguhan) daripada dikirim apa adanya.
    $parsedYear = (int)date('Y', $ts);
    if ($parsedYear < 2000 || $parsedYear > 2100) {
        return null;
    }

    return date('Y-m-d', $ts);
}

/**
 * ANTI-DOBEL: cek ke keuangan apakah pelanggan ini SUDAH punya baris pembayaran
 * dengan tanggal_bayar + jumlah_bayar yang sama SEBELUM mengirim POST baru.
 *
 * Perlu karena flag lokal KEUANGAN_SYNCED=0 bisa saja tidak akurat - misalnya
 * POST sebelumnya sukses di sisi keuangan tapi koneksi putus / script mati
 * sebelum sempat UPDATE flag lokal. Tanpa cek ini, run berikutnya akan
 * mengirim ulang dan membuat baris payment_history GANDA di keuangan.
 *
 * Return array payment yang cocok (berisi minimal 'id') kalau ketemu, atau
 * null kalau tidak ada yang cocok (aman untuk kirim baru).
 */
function findExistingRemotePayment(int $keuanganId, string $tanggalBayar, float $jumlahBayar): ?array
{
    $resp = keuHttpRequest(
        'GET',
        KEU_PELANGGAN_PAYMENT_ENDPOINT . '?pelanggan_id=' . urlencode((string)$keuanganId)
            . '&date_from=' . urlencode($tanggalBayar) . '&date_to=' . urlencode($tanggalBayar)
    );

    if ($resp['http_code'] !== 200 || empty($resp['json']['ok'])) {
        // Gagal cek (mis. endpoint sedang error) - JANGAN anggap aman untuk
        // kirim baru, biar caller yang putuskan (default: skip demi keamanan).
        syncLog("WARNING: gagal cek duplikat pembayaran pelanggan_id=$keuanganId tanggal=$tanggalBayar. HTTP {$resp['http_code']} - " . ($resp['json']['message'] ?? $resp['error']));
        return ['id' => null, '_check_failed' => true];
    }

    $payments = $resp['json']['payments'] ?? $resp['json']['payment_history'] ?? [];
    foreach ($payments as $p) {
        $pJumlah = (float)($p['jumlah_bayar'] ?? 0);
        $pTanggal = (string)($p['tanggal_bayar'] ?? '');
        if ($pTanggal === $tanggalBayar && abs($pJumlah - $jumlahBayar) < 0.01) {
            return $p;
        }
    }

    return null;
}

/**
 * RECONCILE: cocokkan transaksi lokal yang SUDAH ditandai KEUANGAN_SYNCED=1
 * dengan payment_history pelanggan tsb di keuangan (dari hasil GET awal cron ini).
 *
 * Kenapa perlu: kolom KEUANGAN_SYNCED cuma mencatat "menurut kita, ini sudah
 * pernah dikirim & API sempat balas sukses". Itu tidak selalu berarti datanya
 * MASIH ada / benar-benar tercatat di keuangan sekarang - misalnya:
 *   - baris payment_history-nya dihapus manual di aplikasi keuangan,
 *   - data lama sebelum kolom KEUANGAN_PAYMENT_ID dipakai (payment_id kosong),
 *   - atau kasus lain yang bikin data lokal & keuangan tidak sinkron lagi.
 *
 * Kalau payment_id yang tersimpan lokal ternyata TIDAK ketemu di payment_history
 * keuangan pelanggan tsb saat ini, flag lokal direset ke 0 (KEUANGAN_SYNCED=0)
 * supaya baris itu otomatis dikirim ULANG oleh syncTransaksiForCustomer() pada
 * run cron yang sama (dipanggil tepat setelah fungsi ini). Hanya berlaku untuk
 * transaksi berstatus BERHASIL, sesuai aturan sinkronisasi utama.
 *
 * Fungsi ini TIDAK dipanggil saat dry_run (tidak boleh mengubah data saat simulasi).
 */
function reconcileTransaksiSyncFlags(mysqli $conn, string $idpel, array $existingRemote, array &$summary): void
{
    $remotePaymentIds = [];
    foreach (($existingRemote['payment_history'] ?? []) as $ph) {
        $pid = $ph['id'] ?? null;
        if ($pid !== null && $pid !== '') {
            $remotePaymentIds[(string)$pid] = true;
        }
    }

    $idpelSafe = mysqli_real_escape_string($conn, $idpel);
    $q = mysqli_query(
        $conn,
        "SELECT id, KEUANGAN_PAYMENT_ID FROM transaksi
         WHERE TRIM(IDPEL)='$idpelSafe'
           AND id > " . SYNC_TRANSACTION_START_ID . "
           AND TRIM(UPPER(COALESCE(STATUS,'')))='BERHASIL'
           AND KEUANGAN_SYNCED = 1"
    );
    if (!$q) {
        return;
    }

    while ($t = mysqli_fetch_assoc($q)) {
        $paymentId = trim((string)($t['KEUANGAN_PAYMENT_ID'] ?? ''));

        // Payment_id kosong (data lama) ATAU payment_id itu tidak ada lagi di
        // payment_history keuangan sekarang -> anggap belum/tidak benar-benar
        // tercatat di keuangan, reset supaya disync ulang.
        if ($paymentId === '' || !isset($remotePaymentIds[$paymentId])) {
            mysqli_query($conn, "UPDATE transaksi SET KEUANGAN_SYNCED=0 WHERE id=" . (int)$t['id']);
            $summary['transaksi_reconciled_missing_in_keuangan']++;
            syncLog("RECONCILE IDPEL=$idpel: transaksi id={$t['id']} (payment_id lokal='$paymentId') tidak ditemukan di payment_history keuangan saat ini, ditandai untuk sync ulang.");
        }
    }
}

/**
 * Kirim transaksi lokal berstatus BERHASIL yang belum tersinkron ke keuangan,
 * lewat endpoint RESMI pelanggan_payment_api (multipart, dengan bank_id dan
 * bukti_file). Anti-dobel: hanya ambil baris dengan KEUANGAN_SYNCED = 0, dan
 * langsung ditandai KEUANGAN_SYNCED = 1 begitu API keuangan merespons sukses.
 */
function syncTransaksiForCustomer(
    mysqli $conn,
    string $idpel,
    int $keuanganId,
    array $customerPayload,
    array $banksList,
    array $bankOverrideMap,
    array &$summary,
    bool $dryRun = false
): void {
    $idpelSafe = mysqli_real_escape_string($conn, $idpel);
    $q = mysqli_query(
        $conn,
        "SELECT * FROM transaksi
         WHERE TRIM(IDPEL)='$idpelSafe'
           AND id > " . SYNC_TRANSACTION_START_ID . "
           AND TRIM(UPPER(COALESCE(STATUS,'')))='BERHASIL'
           AND (KEUANGAN_SYNCED = 0 OR KEUANGAN_SYNCED IS NULL)
         ORDER BY id ASC"
    );
    if (!$q) {
        return;
    }

    while ($t = mysqli_fetch_assoc($q)) {
        $tanggalBayarRaw = (string)($t['TANGGALBAYAR'] ?? '');
        $tanggalBayar = parseTanggalBayar($tanggalBayarRaw);
        $tanggalSumber = 'TANGGALBAYAR';

        // ---- Fallback ke kolom `waktu` kalau TANGGALBAYAR kosong/invalid ----
        // (mis. nilainya "-" seperti pada baris hasil PENAGIHAN otomatis).
        // Kolom `waktu` biasanya diisi otomatis oleh MySQL/aplikasi dengan
        // format datetime standar ("YYYY-MM-DD HH:ii:ss"), jadi lebih aman
        // diparse dibanding mengandalkan TANGGALBAYAR yang formatnya tidak
        // konsisten (kadang teks Indonesia, kadang tanda strip, dst).
        if ($tanggalBayar === null) {
            $waktuRaw = (string)($t['waktu'] ?? '');
            $tanggalBayar = parseTanggalBayar($waktuRaw);
            if ($tanggalBayar !== null) {
                $tanggalSumber = 'waktu';
                syncLog("INFO transaksi id={$t['id']} IDPEL=$idpel: TANGGALBAYAR ('$tanggalBayarRaw') tidak valid, fallback pakai kolom waktu ('$waktuRaw') -> $tanggalBayar.");
            }
        }

        if ($tanggalBayar === null) {
            $summary['transaksi_skipped_invalid_date']++;
            $waktuRawLog = (string)($t['waktu'] ?? '');
            $msg = "SKIP transaksi id={$t['id']} IDPEL=$idpel: TANGGALBAYAR ('$tanggalBayarRaw') maupun kolom waktu ('$waktuRawLog') sama-sama tidak valid/tidak bisa diparse. "
                . "Tidak dikirim supaya tidak tercatat dengan tanggal yang salah. Perbaiki data tanggal transaksi ini di database lokal lalu jalankan sync ulang.";
            $summary['errors'][] = $msg;
            syncLog($msg);
            continue;
        }

        // API menolak tanggal_bayar di masa depan - kalau kepepet, batasi ke
        // hari ini, tapi catat sebagai warning (bukan silent fix).
        if (strtotime($tanggalBayar) > strtotime(date('Y-m-d'))) {
            syncLog("WARNING transaksi id={$t['id']} IDPEL=$idpel: sumber=$tanggalSumber terparse ke tanggal masa depan ($tanggalBayar), dibatasi ke hari ini.");
            $tanggalBayar = date('Y-m-d');
        }

        $jumlahBayar = (float)($t['HARGA'] ?? 0);

        $metodeBayarRaw = strtolower(trim((string)($t['METODE_BAYAR'] ?? '')));
        $keterangan = (string)($t['CEK'] ?? '') !== ''
            ? (string)$t['CEK']
            : ('Sync otomatis dari CRM - ' . (string)($t['BUKTI'] ?? ''));

        // Kompensasi gratis bukan penerimaan kas/bank dan tidak boleh dicatat
        // sebagai pendapatan di Keuangan, sekalipun mapping bank tersedia.
        if ($metodeBayarRaw === 'kompensasi_free') {
            $summary['transaksi_skipped_excluded_method']++;
            syncLog("SKIP transaksi id={$t['id']} IDPEL=$idpel: METODE_BAYAR='kompensasi_free' dikecualikan dari sinkronisasi pendapatan.");
            continue;
        }

        if ($dryRun) {
            syncLog("DRYRUN sync transaksi id={$t['id']} IDPEL=$idpel tanggal_bayar=$tanggalBayar jumlah_bayar=$jumlahBayar (belum benar-benar dikirim)");
            $summary['transaksi_synced']++;
            continue;
        }

        // ---- ANTI-DOBEL: cek dulu apakah pembayaran ini SUDAH ada di keuangan ----
        // (tanggal_bayar + jumlah_bayar sama) sebelum kirim POST baru. Kalau
        // ketemu, jangan kirim lagi - cukup tandai lokal sudah synced (self-heal).
        $existingPayment = findExistingRemotePayment($keuanganId, $tanggalBayar, $jumlahBayar);
        if ($existingPayment !== null) {
            if (!empty($existingPayment['_check_failed'])) {
                // Gagal cek ke server (bukan berarti sudah ada) - demi keamanan,
                // JANGAN kirim baru pada percobaan ini, coba lagi run berikutnya.
                $summary['transaksi_failed']++;
                $msg = "SKIP transaksi id={$t['id']} IDPEL=$idpel: gagal memverifikasi status duplikat ke keuangan sebelum kirim (lihat WARNING di log). Tidak dikirim demi menghindari data dobel, akan dicoba lagi run berikutnya.";
                $summary['errors'][] = $msg;
                syncLog($msg);
                continue;
            }

            $existingPaymentId = (string)($existingPayment['id'] ?? 'unknown_id');
            $paymentIdSafe = mysqli_real_escape_string($conn, $existingPaymentId);
            mysqli_query(
                $conn,
                "UPDATE transaksi
                 SET KEUANGAN_SYNCED=1, KEUANGAN_PAYMENT_ID='$paymentIdSafe', KEUANGAN_SYNC_AT=NOW()
                 WHERE id=" . (int)$t['id']
            );
            $summary['transaksi_skipped_already_exists']++;
            syncLog("SKIP transaksi id={$t['id']} IDPEL=$idpel: pembayaran tanggal_bayar=$tanggalBayar jumlah_bayar=$jumlahBayar SUDAH ada di keuangan (payment_id=$existingPaymentId). Tidak dikirim dobel, flag lokal disamakan.");
            continue;
        }

        // ---- Resolve bank_id (WAJIB) ----
        $bankId = resolveBankId($metodeBayarRaw, $banksList, $bankOverrideMap);
        if ($bankId === null) {
            $summary['transaksi_skipped_unmapped_bank']++;
            $msg = "SKIP transaksi id={$t['id']} IDPEL=$idpel: METODE_BAYAR='$metodeBayarRaw' tidak bisa dipetakan ke bank_id keuangan. "
                . "Atur mapping pada Keuangan > Data Kas/Bank > Mapping Metode Billing.";
            $summary['errors'][] = $msg;
            syncLog($msg);
            continue;
        }

        // ---- Resolve file bukti (opsional) ----
        $buktiValue = (string)($t['BUKTI'] ?? '');
        $buktiResolved = resolveBuktiFile($buktiValue);
        $buktiPath = null;
        $buktiMime = '';
        if ($buktiResolved === null) {
            $summary['transaksi_without_bukti']++;
            syncLog("INFO transaksi id={$t['id']} IDPEL=$idpel: bukti pembayaran ('$buktiValue') kosong/tidak ditemukan. Sinkron tetap dilanjutkan tanpa lampiran.");
        } else {
            [$buktiPath, $buktiMime] = $buktiResolved;
        }

        $fields = [
            'source'        => (string)($customerPayload['source'] ?? 'site'),
            'id_site'       => (string)($customerPayload['id_site'] ?? ''),
            'site_ref_id'   => (string)($customerPayload['id_site'] ?? ''),
            'pelanggan_id'  => (string)$keuanganId,
            'id'            => (string)$keuanganId,
            'bank_id'       => (string)$bankId,
            'tanggal_bayar' => $tanggalBayar,
            'jumlah_bayar'  => (string)$jumlahBayar,
            'nominal'       => (string)$jumlahBayar,
            'keterangan'    => $keterangan,
        ];

        $resp = keuHttpRequestMultipart(KEU_PELANGGAN_PAYMENT_ENDPOINT, $fields, $buktiPath, $buktiMime);

        // Hapus file temp hasil download URL remote (bukan file asli di disk CRM).
        // Pakai strpos() bukan str_starts_with() supaya tetap kompatibel PHP 7.x.
        if ($buktiPath !== null && strpos($buktiPath, sys_get_temp_dir()) === 0) {
            @unlink($buktiPath);
        }

        $logLine = "Sync transaksi id={$t['id']} IDPEL=$idpel payload=" . json_encode($fields, JSON_UNESCAPED_UNICODE)
            . " bukti_file=" . ($buktiPath ?? '(tanpa bukti)') . " | HTTP {$resp['http_code']} | raw_response=" . ($resp['raw'] ?? $resp['error']);
        syncLog($logLine);

        $success = in_array($resp['http_code'], [200, 201], true) && !empty($resp['json']['ok']);

        if ($success) {
            $paymentId = (string)(
                $resp['json']['id']
                ?? $resp['json']['payment_id']
                ?? $resp['json']['payment']['id']
                ?? ''
            );
            $paymentIdSafe = mysqli_real_escape_string($conn, $paymentId !== '' ? $paymentId : 'unknown_id');
            mysqli_query(
                $conn,
                "UPDATE transaksi
                 SET KEUANGAN_SYNCED=1, KEUANGAN_PAYMENT_ID='$paymentIdSafe', KEUANGAN_SYNC_AT=NOW()
                 WHERE id=" . (int)$t['id']
            );
            $summary['transaksi_synced']++;
        } else {
            $summary['transaksi_failed']++;
            $msg = "Sync transaksi id={$t['id']} IDPEL=$idpel gagal: HTTP {$resp['http_code']} - "
                . ($resp['json']['message'] ?? $resp['error'] ?: 'response tidak dikenali')
                . ". Tidak ditandai synced, akan dicoba lagi. Lihat log untuk detail payload & raw response.";
            $summary['errors'][] = $msg;
            syncLog($msg);
        }
    }
}

// ================= MIGRASI KOLOM OTOMATIS (sekali jalan, aman diulang) =================
ensureColumnExists($conn, 'pelanggan', 'KEUANGAN_ID', 'INT NULL DEFAULT NULL');
ensureColumnExists($conn, 'pelanggan', 'KEUANGAN_SITE_REF_ID', 'INT NULL DEFAULT NULL');
ensureColumnExists($conn, 'pelanggan', 'KEUANGAN_SITE_SOURCE', 'VARCHAR(20) NULL DEFAULT NULL');
ensureColumnExists($conn, 'pelanggan', 'KEUANGAN_LAST_HASH', 'VARCHAR(64) NULL DEFAULT NULL');
ensureColumnExists($conn, 'pelanggan', 'KEUANGAN_LAST_SYNC', 'DATETIME NULL DEFAULT NULL');
ensureColumnExists($conn, 'pelanggan', 'KEUANGAN_SYNC_STATUS', "VARCHAR(20) NOT NULL DEFAULT 'pending'");
ensureColumnExists($conn, 'pelanggan', 'KEUANGAN_SYNC_ERROR', 'TEXT NULL DEFAULT NULL');
ensureColumnExists($conn, 'pelanggan', 'KEUANGAN_SYNC_ATTEMPTS', 'INT NOT NULL DEFAULT 0');
ensureColumnExists($conn, 'pelanggan', 'KEUANGAN_SYNC_LAST_ATTEMPT', 'DATETIME NULL DEFAULT NULL');
ensureColumnExists($conn, 'transaksi', 'KEUANGAN_SYNCED', 'TINYINT(1) NOT NULL DEFAULT 0');
ensureColumnExists($conn, 'transaksi', 'KEUANGAN_PAYMENT_ID', 'VARCHAR(64) NULL DEFAULT NULL');
ensureColumnExists($conn, 'transaksi', 'KEUANGAN_SYNC_AT', 'DATETIME NULL DEFAULT NULL');

// ================= AMBIL SEMUA DATA PELANGGAN DARI KEUANGAN (sekali di awal) =================
$remoteResp = keuHttpRequest('GET', KEU_PELANGGAN_ENDPOINT);
if ($remoteResp['http_code'] !== 200 || !is_array($remoteResp['json']) || empty($remoteResp['json']['ok'])) {
    $summary['ok'] = false;
    $summary['errors'][] = 'Gagal mengambil data pelanggan dari keuangan. HTTP '
        . $remoteResp['http_code'] . ' - ' . ($remoteResp['json']['message'] ?? $remoteResp['error']);
    syncLog('FATAL: gagal fetch data keuangan awal. ' . json_encode($remoteResp['json'] ?? $remoteResp['error']));
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

$remoteCustomers = $remoteResp['json']['customers'] ?? $remoteResp['json']['rows'] ?? [];
$remoteSitesList = $remoteResp['json']['sites'] ?? [];

$remoteByIdpel = [];
$remoteDuplicateIdpels = [];
foreach ($remoteCustomers as $rc) {
    $ridpel = trim((string)($rc['IDPEL'] ?? ''));
    if ($ridpel !== '') {
        $ridpelKey = normalizeIdpelKey($ridpel);
        if (isset($remoteByIdpel[$ridpelKey])) {
            if (!isset($remoteDuplicateIdpels[$ridpelKey])) {
                $remoteDuplicateIdpels[$ridpelKey] = [(int)($remoteByIdpel[$ridpelKey]['id'] ?? 0)];
            }
            $remoteDuplicateIdpels[$ridpelKey][] = (int)($rc['id'] ?? 0);
            continue;
        }
        $remoteByIdpel[$ridpelKey] = $rc;
    }
}

$remoteSiteByName = [];
$remoteSiteByNormalizedName = [];
foreach ($remoteSitesList as $rs) {
    $sn = trim((string)($rs['site_name'] ?? ''));
    if ($sn !== '') {
        $remoteSiteByName[$sn] = $rs;
        $remoteSiteByNormalizedName[normalizeAreaKey($sn)] = $rs;
    }
}
foreach ($remoteCustomers as $rc) {
    $siteObj = $rc['site'] ?? null;
    if (is_array($siteObj) && !empty($siteObj['site_name'])) {
        $remoteSiteByName[$siteObj['site_name']] = $siteObj;
        $remoteSiteByNormalizedName[normalizeAreaKey((string)$siteObj['site_name'])] = $siteObj;
    }
}

$overrideMap = [];
if (file_exists(SYNC_OVERRIDE_MAP_FILE)) {
    $overrideMap = json_decode((string)file_get_contents(SYNC_OVERRIDE_MAP_FILE), true) ?: [];
}
$overrideMapByNormalized = [];
foreach ($overrideMap as $billingArea => $targetSite) {
    $overrideMapByNormalized[normalizeAreaKey((string)$billingArea)] = $targetSite;
}

// Mapping yang dikelola di Keuangan adalah sumber utama. Mapping ikut dibawa
// oleh pelanggan_api agar satu snapshot pelanggan + site memakai patokan sama.
$keuanganMappings = $remoteResp['json']['billing_site_mappings'] ?? null;
if (!is_array($keuanganMappings)) {
    $keuanganMappings = [];
    syncLog('WARNING: pelanggan_api Keuangan belum menyertakan mapping AREA Billing; memakai fallback lokal.');
}
if (!empty($keuanganMappings)) {
    foreach ($keuanganMappings as $mapping) {
        $billingArea = trim((string)($mapping['billing_area'] ?? ''));
        $siteId = (int)($mapping['id_site'] ?? $mapping['site_ref_id'] ?? 0);
        if ($billingArea === '' || $siteId <= 0) {
            continue;
        }
        $targetSite = [
            'source' => (string)($mapping['source'] ?? 'site'),
            'id_site' => $siteId,
            'site_name' => (string)($mapping['site_name'] ?? $billingArea),
        ];
        $overrideMap[$billingArea] = $targetSite;
        $overrideMapByNormalized[normalizeAreaKey($billingArea)] = $targetSite;
    }
}

$bankOverrideMap = [];
if (file_exists(SYNC_BANK_OVERRIDE_MAP_FILE)) {
    $decoded = json_decode((string)file_get_contents(SYNC_BANK_OVERRIDE_MAP_FILE), true) ?: [];
    foreach ($decoded as $k => $v) {
        $bankOverrideMap[strtolower(trim((string)$k))] = (int)$v;
    }
}

// Ambil daftar bank/kas dan mapping resmi dari Keuangan sekali di awal.
$paymentMeta = KEU_PAYMENT_SYNC_ENABLED
    ? fetchPaymentMeta()
    : ['banks' => [], 'billing_bank_mappings' => []];
$banksList = $paymentMeta['banks'];
foreach ((array)$paymentMeta['billing_bank_mappings'] as $mapping) {
    $method = strtolower(trim((string)($mapping['billing_method'] ?? '')));
    $bankId = (int)($mapping['bank_id'] ?? 0);
    if ($method !== '' && $bankId > 0) {
        // Mapping dari Keuangan menjadi sumber utama dan menimpa fallback JSON lokal.
        $bankOverrideMap[$method] = $bankId;
    }
}
if (KEU_PAYMENT_SYNC_ENABLED && empty($banksList) && empty($bankOverrideMap)) {
    syncLog('WARNING: daftar bank dari keuangan kosong dan tidak ada override manual. Sync transaksi kemungkinan akan gagal semua sampai ' . SYNC_BANK_OVERRIDE_MAP_FILE . ' diisi.');
}

// ================= LOOP SEMUA PELANGGAN LOKAL =================
$whereIdpel = '';
if ($onlyIdpel !== '') {
    $whereIdpel = "AND TRIM(p.IDPEL) = '" . mysqli_real_escape_string($conn, $onlyIdpel) . "'";
}

$sqlPelanggan = "SELECT p.* FROM pelanggan p WHERE TRIM(COALESCE(p.IDPEL,'')) <> '' $whereIdpel ORDER BY p.id ASC";
$resPelanggan = mysqli_query($conn, $sqlPelanggan);

if (!$resPelanggan) {
    $summary['ok'] = false;
    $summary['errors'][] = 'Query pelanggan lokal gagal: ' . mysqli_error($conn);
} else {
    while ($row = mysqli_fetch_assoc($resPelanggan)) {
        $summary['customers_checked']++;
        $idpel = trim((string)$row['IDPEL']);
        if ($idpel === '') {
            $summary['customers_skipped_empty_idpel']++;
            continue;
        }
        $idpelKey = normalizeIdpelKey($idpel);
        if (isset($remoteDuplicateIdpels[$idpelKey])) {
            $summary['customers_skipped_duplicate_remote']++;
            $summary['customers_failed']++;
            $msg = 'SKIP IDPEL=' . $idpel . ': terduplikasi di Keuangan pada ID ['
                . implode(',', array_values(array_unique($remoteDuplicateIdpels[$idpelKey])))
                . ']. Gabungkan data duplikat sebelum sinkron dilanjutkan.';
            $summary['errors'][] = $msg;
            syncLog($msg);
            if (!$dryRun) {
                markPelangganSyncFailed($conn, (int)$row['id'], $msg);
            }
            continue;
        }
        if (!$dryRun) {
            markPelangganSyncAttempt($conn, (int)$row['id']);
        }

        $site = resolveSite((string)($row['AREA'] ?? ''), $remoteSiteByName, $overrideMap, $remoteSiteByNormalizedName, $overrideMapByNormalized);

        if (!$site || empty($site['id_site'])) {
            $areaName = trim((string)($row['AREA'] ?? ''));
            if (!$dryRun && KEU_AUTO_CREATE_SITE && $areaName !== '') {
                $newSite = createRemoteSite($areaName, $row, $summary);
                if ($newSite) {
                    $remoteSiteByName[$newSite['site_name']] = [
                        'source' => $newSite['source'],
                        'site_ref_id' => $newSite['id_site'],
                        'site_name' => $newSite['site_name'],
                    ];
                    $remoteSiteByName[$areaName] = $remoteSiteByName[$newSite['site_name']];
                    // Update juga index ter-normalisasi supaya pelanggan lain di
                    // AREA yang sama (walau beda kapitalisasi) di run yang sama
                    // ikut ketemu site ini, bukan nyoba createRemoteSite() lagi.
                    $remoteSiteByNormalizedName[normalizeAreaKey($newSite['site_name'])] = $remoteSiteByName[$newSite['site_name']];
                    $remoteSiteByNormalizedName[normalizeAreaKey($areaName)] = $remoteSiteByName[$newSite['site_name']];
                    $site = $newSite;
                }
            }
        }

        if (!$site || empty($site['id_site'])) {
            $summary['customers_skipped_unmapped_area']++;
            $msg = "SKIP IDPEL=$idpel: AREA '{$row['AREA']}' (PEMILIK '{$row['PEMILIK']}') tidak ketemu site di keuangan & gagal auto-create. Tambahkan mapping manual di " . SYNC_OVERRIDE_MAP_FILE;
            syncLog($msg);
            if (!$dryRun) {
                markPelangganSyncFailed($conn, (int)$row['id'], $msg);
            }
            continue;
        }

        $hargaPaket = '0';
        $paketNama = mysqli_real_escape_string($conn, (string)($row['PAKET'] ?? ''));
        $pemilikNama = mysqli_real_escape_string($conn, (string)($row['PEMILIK'] ?? ''));
        if ($paketNama !== '') {
            $hargaQ = mysqli_query($conn, "SELECT HARGA FROM paket WHERE PAKET='$paketNama' AND PEMILIK='$pemilikNama' ORDER BY id DESC LIMIT 1");
            if ($hargaQ && ($hargaRow = mysqli_fetch_assoc($hargaQ))) {
                $hargaPaket = (string)($hargaRow['HARGA'] ?? '0');
            }
        }
        $row['HARGA_PAKET'] = $hargaPaket;

        $payload = buildCustomerPayload($row, $site);
        $payloadHash = md5(json_encode($payload, JSON_UNESCAPED_UNICODE));

        $existingRemote = $remoteByIdpel[$idpelKey] ?? null;
        $keuanganId = !empty($row['KEUANGAN_ID']) ? (int)$row['KEUANGAN_ID'] : null;

        if ($keuanganId === null && $existingRemote) {
            $keuanganId = (int)$existingRemote['id'];
        }

        // ---- RECONCILE: pelanggan sudah ada di keuangan -> pastikan transaksi
        // BERHASIL-nya benar-benar tercatat di payment_history keuangan. Kalau
        // ada yang "hilang" di sisi keuangan walau lokal sudah ditandai synced,
        // reset flag-nya supaya baris syncTransaksiForCustomer() di bawah ikut
        // mengirim ulang pada run yang sama.
        if ($keuanganId && $existingRemote && KEU_PAYMENT_SYNC_ENABLED && !$dryRun) {
            reconcileTransaksiSyncFlags($conn, $idpel, $existingRemote, $summary);
        }

        if ($keuanganId) {
            $lastHash = (string)($row['KEUANGAN_LAST_HASH'] ?? '');
            $remoteMissing = !$existingRemote;

            $siteMismatch = false;
            if ($existingRemote) {
                $remoteSiteRefId = (int)($existingRemote['site_ref_id'] ?? ($existingRemote['site']['site_ref_id'] ?? 0));
                $remoteSiteSource = (string)($existingRemote['site_source'] ?? ($existingRemote['site']['source'] ?? ''));
                $targetSiteRefId = (int)($site['id_site'] ?? 0);
                $targetSiteSource = (string)($site['source'] ?? 'site');
                if ($remoteSiteRefId !== $targetSiteRefId || strtolower($remoteSiteSource) !== strtolower($targetSiteSource)) {
                    $siteMismatch = true;
                    $areaLog = (string)($row['AREA'] ?? '');
                    syncLog("IDPEL=$idpel (AREA billing='$areaLog'): site di keuangan beda (site_ref_id=$remoteSiteRefId/$remoteSiteSource) dari seharusnya (site_ref_id=$targetSiteRefId/$targetSiteSource, site_name='{$site['site_name']}'). Dipaksa update supaya disamakan.");
                }
            }

            if ($lastHash === $payloadHash && !$siteMismatch && !$remoteMissing) {
                $summary['customers_unchanged']++;
                if (!$dryRun) {
                    markPelangganSynced($conn, (int)$row['id'], (int)$keuanganId, $site, $payloadHash);
                }
                if (KEU_PAYMENT_SYNC_ENABLED) {
                    syncTransaksiForCustomer($conn, $idpel, (int)$keuanganId, $payload, $banksList, $bankOverrideMap, $summary, $dryRun);
                }
                continue;
            }

            if ($siteMismatch) {
                $summary['customers_site_reassigned']++;
            }
            if ($remoteMissing) {
                syncLog("IDPEL=$idpel mempunyai KEUANGAN_ID=$keuanganId tetapi tidak ada pada snapshot Keuangan. Dipaksa upsert global berdasarkan IDPEL.");
            }

            $updatePayload = $payload;
            $updatePayload['id'] = $keuanganId;

            if ($dryRun) {
                syncLog("DRYRUN update IDPEL=$idpel (keuangan_id=$keuanganId)");
            } else {
                $resp = keuHttpRequest('POST', KEU_PELANGGAN_ENDPOINT, $updatePayload);
                if ($resp['http_code'] === 200 && !empty($resp['json']['ok'])) {
                    $operation = (string)($resp['json']['operation'] ?? 'updated');
                    if ($operation === 'created') {
                        $summary['customers_created']++;
                    } else {
                        $summary['customers_updated']++;
                    }
                    if ($remoteMissing) {
                        $summary['customers_remote_missing_repaired']++;
                    }
                    $keuanganId = (int)($resp['json']['id'] ?? $keuanganId);
                    markPelangganSynced($conn, (int)$row['id'], $keuanganId, $site, $payloadHash);
                } else {
                    $summary['customers_failed']++;
                    $msg = "Update gagal IDPEL=$idpel: HTTP {$resp['http_code']} - " . ($resp['json']['message'] ?? $resp['error']);
                    $summary['errors'][] = $msg;
                    syncLog($msg);
                    markPelangganSyncFailed($conn, (int)$row['id'], $msg);
                }
            }
        } else {
            if ($dryRun) {
                syncLog("DRYRUN create IDPEL=$idpel");
            } else {
                $resp = keuHttpRequest('POST', KEU_PELANGGAN_ENDPOINT, $payload);
                if ($resp['http_code'] === 200 && !empty($resp['json']['ok'])) {
                    $operation = (string)($resp['json']['operation'] ?? 'created');
                    if ($operation === 'updated') {
                        $summary['customers_updated']++;
                        $summary['customers_remote_missing_repaired']++;
                    } else {
                        $summary['customers_created']++;
                    }
                    $newId = (int)($resp['json']['id'] ?? 0);
                    markPelangganSynced($conn, (int)$row['id'], $newId, $site, $payloadHash);
                    if ($newId > 0) {
                        $keuanganId = $newId;
                    }
                } elseif ($resp['http_code'] === 409) {
                    $summary['customers_failed']++;
                    $msg = "IDPEL=$idpel konflik (409) di site {$site['site_name']} - IDPEL sudah dipakai pelanggan lain, cek manual.";
                    $summary['errors'][] = $msg;
                    syncLog($msg);
                    markPelangganSyncFailed($conn, (int)$row['id'], $msg);
                } else {
                    $summary['customers_failed']++;
                    $msg = "Create gagal IDPEL=$idpel: HTTP {$resp['http_code']} - " . ($resp['json']['message'] ?? $resp['error']);
                    $summary['errors'][] = $msg;
                    syncLog($msg);
                    markPelangganSyncFailed($conn, (int)$row['id'], $msg);
                }
            }
        }

        // ---- Sync transaksi (status BERHASIL) untuk IDPEL ini ----
        if (KEU_PAYMENT_SYNC_ENABLED && ($dryRun || $keuanganId)) {
            syncTransaksiForCustomer($conn, $idpel, (int)$keuanganId, $payload, $banksList, $bankOverrideMap, $summary, $dryRun);
        }
    }
}

@file_put_contents(SYNC_LASTRUN_FILE, (string)time());
$summary['finished_at'] = date('Y-m-d H:i:s');
syncLog('Sync selesai: ' . json_encode($summary, JSON_UNESCAPED_UNICODE));

flock($lockFp, LOCK_UN);
fclose($lockFp);

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
