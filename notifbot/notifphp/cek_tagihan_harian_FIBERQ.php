<?php
/**
 * cek_tagihan_harian.php
 * =====================================================================
 * Skrip pengecekan tagihan harian secara otomatis.
 * Dijalankan setiap hari via cron job.
 *
 * Memeriksa pelanggan yang BELUM BAYAR berdasarkan 4 kombinasi:
 *   1. PRABAYAR   + MENGIKUTI TANGGAL BAYAR  (30 hari dari terakhir bayar)
 *   2. PASCABAYAR + MENGIKUTI TANGGAL BAYAR  (30 hari dari terakhir bayar / tanggal pasang)
 *   3. PRABAYAR   + MENGIKUTI TANGGAL TEMPO  (berdasarkan field TEMPO di pelanggan)
 *   4. PASCABAYAR + MENGIKUTI TANGGAL TEMPO  (berdasarkan field TEMPO di pelanggan)
 *
 * CARA PENGGUNAAN:
 *   Salin file ini menjadi cek_tagihan_harian_NAMAPEMILIK.php
 *   Contoh: cek_tagihan_harian_FIBERQ.php
 *   Tambahkan ke crontab:
 *     0 7 * * * php /path/to/cek_tagihan_harian_FIBERQ.php
 * =====================================================================
 */

include '../../koneksidb.php';
require_once '../../routeros_api.class.php';
require_once __DIR__ . '/tagihan_status_lib.php';

// Rapikan output: CLI tetap plain text, browser tampil preformatted.
$isCliOutput = (PHP_SAPI === 'cli');
if (!$isCliOutput) {
    echo "<pre style=\"font-family: Consolas, monospace; white-space: pre-wrap; line-height: 1.4;\">";
    register_shutdown_function(function () {
        echo "</pre>";
    });
}

echo "=== MULAI PROSES CEK TAGIHAN HARIAN ===\n";
echo "Waktu: " . date('Y-m-d H:i:s') . "\n";

// -----------------------------------------------------------------------
// 1. Ambil nama pemilik dari nama file
// -----------------------------------------------------------------------
$filename = basename(__FILE__); // cek_tagihan_harian_FIBERQ.php
$nameOnly = pathinfo($filename, PATHINFO_FILENAME); // cek_tagihan_harian_FIBERQ
$parts    = explode('_', $nameOnly);
$pemilik  = end($parts); // ambil bagian terakhir: FIBERQ

echo "Pemilik: $pemilik\n";

// -----------------------------------------------------------------------
// 2. Load konfigurasi
// -----------------------------------------------------------------------
$config_file = '../../config.json';
$config      = file_exists($config_file) ? json_decode(file_get_contents($config_file), true) : [];
$URL         = $config['domain'] ?? '';

// Timeout pembacaan status Mikrotik (detik)
$mikrotik_timeout = 10;
$mikrotik_attempts = 2;
$mikrotik_delay = 1;

// -----------------------------------------------------------------------
// 3. Load data user (pemilik)
// -----------------------------------------------------------------------
$iduser   = 0;
$username = '';

$stmt_user = $conn->prepare("SELECT * FROM `user` WHERE `USERNAME` = ? LIMIT 1");
$stmt_user->bind_param("s", $pemilik);
$stmt_user->execute();
$res_user = $stmt_user->get_result();
if ($row_user = $res_user->fetch_assoc()) {
    $iduser   = $row_user['id'];
    $username = $row_user['USERNAME'];
}
$stmt_user->close();

if ($iduser == 0) {
    echo "ERROR: Pemilik '$pemilik' tidak ditemukan di tabel user.\n";
    exit(1);
}
echo "ID User: $iduser | Username: $username\n";

// -----------------------------------------------------------------------
// 3a. Ambil seluruh server dan area yang dimiliki user
// -----------------------------------------------------------------------
$userServers = [];
$userAreas = [];
$queryServer = mysqli_query($conn, "SELECT PEMILIK, AREA FROM server WHERE user_id = $iduser");
while($row = mysqli_fetch_assoc($queryServer)) {
  $userServers[] = $row['PEMILIK'];
  if (!empty($row['AREA'])) $userAreas[] = $row['AREA'];
}
$userServerList = count($userServers) > 0 ? "'" . implode("','", array_map('addslashes', $userServers)) . "'" : "''";
$userAreaList = count($userAreas) > 0 ? "'" . implode("','", array_map('addslashes', $userAreas)) . "'" : "''";

// -----------------------------------------------------------------------
// 4. Load konfigurasi reminder (untuk mengikuti_tanggal_tempo)
// -----------------------------------------------------------------------
$jatuh_tempo_hari         = 25; // default: hari ke-25 setiap bulan
$tanggal_awal_tutup_buku  = 24;
$tanggal_akhir_tutup_buku = 5;
$hari_sebelum             = 3;


$prabayar_grace_period = 2;
// Load grace period dari file terpisah
$grace_period_file = "../data/prabayar_grace_period-$pemilik.json";
if (file_exists($grace_period_file)) {
    $grace_data = json_decode(file_get_contents($grace_period_file), true);
    if (is_array($grace_data) && isset($grace_data['prabayar_grace_period'])) {
        $prabayar_grace_period = (int)$grace_data['prabayar_grace_period'];
    }
}

// Toggle "Monthversary ikut tanggal bayar terakhir" (Payment Setting) --
// default OFF supaya perilaku lama (anchor terkunci permanen) tidak berubah
// untuk akun yang belum pernah menyentuh setting ini.
$monthversary_follow_last_payment = false;
$monthversary_setting_file = "../data/monthversary_setting-$pemilik.json";
if (file_exists($monthversary_setting_file)) {
    $monthversary_setting_data = json_decode(file_get_contents($monthversary_setting_file), true);
    if (is_array($monthversary_setting_data) && isset($monthversary_setting_data['follow_last_payment'])) {
        $monthversary_follow_last_payment = !empty($monthversary_setting_data['follow_last_payment']);
    }
}

// Fixed Due Date SEKARANG disimpan di tabel database `reminder_settings`
// (bukan lagi murni file JSON) -- baca LANGSUNG dari DB (bukan file mirror
// notifbot/data/reminder-<pemilik>.json) supaya cron isolir ini IMUN dari
// mirror yang sengaja menghilangkan field2 ini utk akun yang belum eksplisit
// setting Fixed Due Date (lihat reminderSettingsSyncJsonMirror()) -- kalau
// dulu dibaca dari mirror yang field-nya hilang, cron ini bisa isolir
// pelanggan di tanggal yang salah. Kalau akun MEMANG belum pernah setting,
// 4 default lokal di atas (25/24/5/3) tetap dipakai apa adanya (perilaku
// lama dipertahankan utk kasus itu).
require_once __DIR__ . '/../reminder_settings_helper.php';
$reminderSettingsRow = reminderSettingsGetRow($conn, $pemilik);
// Periode Tercatat -- SEBELUMNYA tidak pernah dibaca di sini sama sekali,
// padahal invoice generator (Transaction.php) & portal pelanggan sudah
// konsultasi setting ini. Default 'berjalan' kalau belum pernah di-set,
// sama seperti tempat lain yang baca setting yang sama.
$periode_tercatat = 'berjalan';
if ($reminderSettingsRow && !empty($reminderSettingsRow['fixed_due_date_configured'])) {
    $jatuh_tempo_hari         = (int)$reminderSettingsRow['jatuh_tempo'];
    $hari_sebelum             = (int)$reminderSettingsRow['hari_sebelum'];
    $tanggal_awal_tutup_buku  = (int)$reminderSettingsRow['tanggal_awal_tutup_buku'];
    $tanggal_akhir_tutup_buku = (int)$reminderSettingsRow['tanggal_akhir_tutup_buku'];
    $periode_tercatat         = ($reminderSettingsRow['periode_tercatat'] ?? 'berjalan') === 'berikutnya' ? 'berikutnya' : 'berjalan';
    echo "Konfigurasi reminder dimuat dari database: jatuh_tempo hari ke-$jatuh_tempo_hari, tutup buku $tanggal_awal_tutup_buku–$tanggal_akhir_tutup_buku, periode tercatat: $periode_tercatat\n";
} else {
    echo "[PERINGATAN] Akun belum pernah setting Fixed Due Date, menggunakan nilai default.\n";
}
echo "Waktu tunggu prabayar (grace period): $prabayar_grace_period hari\n";

// -----------------------------------------------------------------------
// 5. Load / init file history
// -----------------------------------------------------------------------
$history_file = "../data/history-$pemilik.json";
$history      = [];

if (file_exists($history_file)) {
    $history = json_decode(file_get_contents($history_file), true);
}
if (!is_array($history)) {
    $history = [];
}

// -----------------------------------------------------------------------
// 6. Fungsi bantu
// -----------------------------------------------------------------------

/**
 * Konversi tanggal ke nama bulan dan tahun Bahasa Indonesia.
 * @param string $tanggal   Format Y-m-d
 * @param int    $tambah    Jumlah bulan yang ditambahkan (bisa negatif)
 * @return string           Contoh: "Maret 2026"
 */
function bulanTahunIndo(string $tanggal, int $tambah = 0): string
{
    $namaBulan = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
    ];
    $ts    = strtotime($tanggal);
    $bulan = (int)date('n', $ts) + $tambah;
    $tahun = (int)date('Y', $ts);

    while ($bulan < 1)  { $bulan += 12; $tahun--; }
    while ($bulan > 12) { $bulan -= 12; $tahun++; }

    return $namaBulan[$bulan] . ' ' . $tahun;
}

/**
 * Tentukan string periode tagihan yang SEHARUSNYA sudah dibayar hari ini,
 * berdasarkan sistem tutup buku (mengikuti_tanggal_tempo).
 *
 * @param int $tglHariIni               Tanggal hari ini (1–31)
 * @param int $tanggal_awal_tutup_buku  Awal window tutup buku (mis. 24)
 * @param int $tanggal_akhir_tutup_buku Akhir window tutup buku (mis. 5)
 * @param int $jatuh_tempo_hari         Hari jatuh tempo pelanggan (mis. 25)
 * @param string $tanggalHariIni        Tanggal penuh hari ini (Y-m-d)
 * @return string                       Contoh: "Maret 2026"
 */
function periodeTagihanAktif(
    int    $tglHariIni,
    int    $tanggal_awal_tutup_buku,
    int    $tanggal_akhir_tutup_buku,   
    int    $jatuh_tempo_hari,
    string $tanggalHariIni
): string {
$periodeSekarang   = bulanTahunIndo($tanggalHariIni, 0);
$periodeBerikutnya = bulanTahunIndo($tanggalHariIni, 1);
$periodeSebelumnya = bulanTahunIndo($tanggalHariIni, -1);

// ===============================
// Tutup buku lintas bulan (24-5)
// ===============================
if ($tanggal_awal_tutup_buku > $tanggal_akhir_tutup_buku) {

    // Selama masih dalam window tutup buku
    // (24-31 dan 1-5) tetap periode sekarang
    if (
        $tglHariIni >= $tanggal_awal_tutup_buku ||
        $tglHariIni <= $tanggal_akhir_tutup_buku
    ) {
        return $periodeSekarang;
    }

    // Setelah lewat akhir tutup buku
    return $periodeBerikutnya;
}

// ===============================
// Tutup buku normal (20-28 / 1-10)
// ===============================

// Selama belum melewati akhir tutup buku
if ($tglHariIni <= $tanggal_akhir_tutup_buku) {
    return $periodeSekarang;
}

// Setelah lewat akhir tutup buku
return $periodeBerikutnya;


}

function buildEscapedInList(mysqli $conn, array $values): string
{
    $escaped = [];

    foreach (array_unique($values) as $value) {
        $value = trim((string)$value);
        if ($value === '') {
            continue;
        }

        $escaped[] = "'" . $conn->real_escape_string($value) . "'";
    }

    return empty($escaped) ? "''" : implode(',', $escaped);
}

function buildTrxDateExprCron(string $alias = ''): string
{
    $p = $alias !== '' ? $alias . '.' : '';
    return "COALESCE(
        DATE({$p}TANGGALBAYAR),
        STR_TO_DATE({$p}TANGGALBAYAR, '%Y-%m-%d'),
        STR_TO_DATE(
            TRIM(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                SUBSTRING_INDEX({$p}TANGGALBAYAR, ',', -1),
                'Januari', '01'
            ), 'Februari', '02'), 'Maret', '03'), 'April', '04'), 'Mei', '05'), 'Juni', '06'), 'Juli', '07'), 'Agustus', '08'), 'September', '09'), 'Oktober', '10'), 'November', '11'), 'Desember', '12')),
            '%d %m %Y'
        )
    )";
}

function getLastPaymentsBulk(mysqli $conn, array $idpels): array
{
    if (empty($idpels)) {
        return [];
    }

    $inList = buildEscapedInList($conn, $idpels);
    $trxDateExpr = buildTrxDateExprCron();
    $sql = "SELECT `IDPEL`, MAX($trxDateExpr) AS `waktu_terakhir`
            FROM `transaksi`
            WHERE `STATUS` = 'BERHASIL' AND `IDPEL` IN ($inList)
            GROUP BY `IDPEL`";
    $result = $conn->query($sql);
    $map = [];

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $map[(string)$row['IDPEL']] = $row['waktu_terakhir'];
        }
    }

    return $map;
}

/**
 * Untuk mode "monthversary": ambil tanggal transaksi BERHASIL pertama +
 * jumlah transaksi BERHASIL per pelanggan. Dipakai untuk mengunci anchor
 * tanggal jatuh tempo prabayar ke transaksi pertama mereka (sekali saja).
 */
function getFirstAndCountPaymentsBulk(mysqli $conn, array $idpels): array
{
    if (empty($idpels)) {
        return [];
    }

    $inList = buildEscapedInList($conn, $idpels);
    $trxDateExpr = buildTrxDateExprCron();
    $sql = "SELECT `IDPEL`, MIN($trxDateExpr) AS `waktu_pertama`, COUNT(*) AS `jumlah_transaksi`
            FROM `transaksi`
            WHERE `STATUS` = 'BERHASIL' AND `IDPEL` IN ($inList)
            GROUP BY `IDPEL`";
    $result = $conn->query($sql);
    $map = [];

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $map[(string)$row['IDPEL']] = [
                'waktu_pertama'    => $row['waktu_pertama'],
                'jumlah_transaksi' => (int)$row['jumlah_transaksi'],
            ];
        }
    }

    return $map;
}

function getLastPaidUsageMapBulk(mysqli $conn, array $idpels): array
{
    if (empty($idpels)) {
        return [];
    }

    $inList = buildEscapedInList($conn, $idpels);
        $trxDateExprT = buildTrxDateExprCron('t');
        $trxDateExprX = buildTrxDateExprCron('x');
        $sql = "SELECT t.`IDPEL`, t.`PENGUNAAN`, $trxDateExprT AS `trx_date`, t.`waktu`
            FROM `transaksi` t
                        WHERE t.`STATUS` = 'BERHASIL'
                            AND t.`IDPEL` IN ($inList)
                            AND $trxDateExprT = (
                                SELECT MAX($trxDateExprX)
                                FROM `transaksi` x
                                WHERE x.`STATUS` = 'BERHASIL' AND x.`IDPEL` = t.`IDPEL`
                            )
                        ORDER BY t.`IDPEL` ASC, t.`waktu` DESC";
    $result = $conn->query($sql);
    $map = [];

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $idpel = (string)($row['IDPEL'] ?? '');
            if ($idpel === '' || isset($map[$idpel])) {
                continue;
            }
            $map[$idpel] = trim((string)($row['PENGUNAAN'] ?? ''));
        }
    }

    return $map;
}

function getPaidPeriodMapBulk(mysqli $conn, array $idpels, string $periode): array
{
    if (empty($idpels)) {
        return [];
    }

    $inList = buildEscapedInList($conn, $idpels);
    $periodeEscaped = $conn->real_escape_string($periode);
    $sql = "SELECT DISTINCT `IDPEL`
            FROM `transaksi`
            WHERE `STATUS` = 'BERHASIL'
              AND `PENGUNAAN` = '$periodeEscaped'
              AND `IDPEL` IN ($inList)";
    $result = $conn->query($sql);
    $map = [];

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $map[(string)$row['IDPEL']] = true;
        }
    }

    return $map;
}

function getPaidPeriodDetailMapBulk(mysqli $conn, array $idpels, string $periode): array
{
    if (empty($idpels)) {
        return [];
    }

    $inList = buildEscapedInList($conn, $idpels);
    $periodeEscaped = $conn->real_escape_string($periode);
    $sql = "SELECT `IDPEL`, `PENGUNAAN`, MAX(COALESCE(`TANGGALBAYAR`, `waktu`)) AS `tanggal_bayar`
            FROM `transaksi`
            WHERE `STATUS` = 'BERHASIL'
              AND `PENGUNAAN` = '$periodeEscaped'
              AND `IDPEL` IN ($inList)
            GROUP BY `IDPEL`, `PENGUNAAN`";
    $result = $conn->query($sql);
    $map = [];

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $idpel = (string)($row['IDPEL'] ?? '');
            if ($idpel === '') {
                continue;
            }

            $map[$idpel] = [
                'penggunaan' => (string)($row['PENGUNAAN'] ?? $periode),
                'tanggal_bayar' => (string)($row['tanggal_bayar'] ?? ''),
            ];
        }
    }

    return $map;
}

function resolveHargaPaketCron(array $hargaPaketMap, string $paket, string $brand, string $area)
{
    $mapKey = $paket . '|' . $brand . '|' . $area;

    if (isset($hargaPaketMap[$mapKey])) return $hargaPaketMap[$mapKey];
    if (isset($hargaPaketMap[$paket . '||' . $area])) return $hargaPaketMap[$paket . '||' . $area];
    if (isset($hargaPaketMap[$paket . '|' . $brand . '|'])) return $hargaPaketMap[$paket . '|' . $brand . '|'];
    if (isset($hargaPaketMap[$paket . '||'])) return $hargaPaketMap[$paket . '||'];
    if (isset($hargaPaketMap[$paket])) return $hargaPaketMap[$paket];

    return null;
}

function isFasumNonPromoCron(string $paket, array $fasumPaketList, array $promoPaketIds): bool
{
    if ($paket === '' || !isset($fasumPaketList[$paket])) {
        return false;
    }

    $paketIdFasum = (string)$fasumPaketList[$paket];
    return !in_array($paketIdFasum, $promoPaketIds, true);
}

function getMikrotikCache($api, bool $isConnected): array
{
    $cache = [
        'status_by_id' => [],
        'secret_id_by_id' => [],
        'active_ids_by_id' => [],
        'profiles' => [],
    ];

    if (!$isConnected || !$api) {
        return $cache;
    }

    try {
        $secrets = $api->comm('/ppp/secret/print', [
            '.proplist' => '.id,name,profile',
        ]);

        if (is_array($secrets)) {
            foreach ($secrets as $row) {
                $name = (string)($row['name'] ?? '');
                if ($name === '') {
                    continue;
                }

                $cache['status_by_id'][$name] = [
                    'status' => 'OFFLINE',
                    'profile' => (string)($row['profile'] ?? '-'),
                    'source' => 'ppp-secret',
                ];
                $cache['secret_id_by_id'][$name] = (string)($row['.id'] ?? '');
            }
        }

        $actives = $api->comm('/ppp/active/print', [
            '.proplist' => '.id,name,profile',
        ]);

        if (is_array($actives)) {
            foreach ($actives as $row) {
                $name = (string)($row['name'] ?? '');
                if ($name === '') {
                    continue;
                }

                $cache['status_by_id'][$name] = [
                    'status' => 'ONLINE',
                    'profile' => (string)($row['profile'] ?? ($cache['status_by_id'][$name]['profile'] ?? '-')),
                    'source' => 'ppp-active',
                ];

                if (!isset($cache['active_ids_by_id'][$name])) {
                    $cache['active_ids_by_id'][$name] = [];
                }

                if (isset($row['.id'])) {
                    $cache['active_ids_by_id'][$name][] = (string)$row['.id'];
                }
            }
        }

        $profiles = $api->comm('/ppp/profile/print', [
            '.proplist' => 'name',
        ]);

        if (is_array($profiles)) {
            foreach ($profiles as $row) {
                $profileName = trim((string)($row['name'] ?? ''));
                if ($profileName === '') {
                    continue;
                }

                $cache['profiles'][mb_strtolower($profileName, 'UTF-8')] = $profileName;
            }
        }
    } catch (Throwable $e) {
        return [
            'status_by_id' => [],
            'secret_id_by_id' => [],
            'active_ids_by_id' => [],
            'profiles' => [],
        ];
    }

    return $cache;
}

function getMikrotikStatus(array $mikrotikCache, bool $isConnected, string $idpel): array
{
    if (!$isConnected) {
        return [
            'status' => 'UNKNOWN (timeout/koneksi gagal)',
            'profile' => '-',
            'source' => 'none',
        ];
    }

    return $mikrotikCache['status_by_id'][$idpel] ?? [
        'status' => 'OFFLINE',
        'profile' => '-',
        'source' => 'none',
    ];
}

/**
 * Jika pelanggan masih online dan profile belum EXPIRED,
 * ubah profile menjadi EXPIRED lalu putuskan active connection.
 *
 * @param RouterosAPI|null $api
 * @param bool $isConnected
 * @param string $idpel
 * @param string $nama
 * @param string $nowa
 * @param string $periode
 * @param string $odp
 * @return array{success:bool,message:string}
 */
function enforceExpiredProfileAndDisconnect($api, bool $isConnected, string $idpel, string $nama, string $nowa, string $periode, string $odp, array &$mikrotikCache): array
{
    if (!$isConnected || !$api) {
        return [
            'success' => false,
            'message' => 'Aksi dilewati: koneksi Mikrotik tidak tersedia',
        ];
    }

    try {
        $secretId = (string)($mikrotikCache['secret_id_by_id'][$idpel] ?? '');

        if ($secretId === '') {
            return [
                'success' => false,
                'message' => 'Aksi gagal: PPP secret tidak ditemukan di Mikrotik untuk ' . $idpel,
            ];
        }

        // Validasi bahwa profile EXPIRED tersedia di Mikrotik
        $availableProfiles = $mikrotikCache['profiles'] ?? [];
        if (!isset($availableProfiles['expired'])) {
            return [
                'success' => false,
                'message' => "Aksi gagal: profile 'EXPIRED' tidak ditemukan di Mikrotik. Buat PPP profile bernama EXPIRED terlebih dahulu di router.",
            ];
        }
        $expiredProfileName = $availableProfiles['expired'];

        $odpText = trim($odp) !== '' ? trim($odp) : '-';
        $comment = "EXPIRED $nama - $nowa - ODP:$odpText - $periode";

        $response = $api->comm('/ppp/secret/set', [
            '.id' => $secretId,
            'profile' => $expiredProfileName,
            'comment' => $comment,
        ]);

        // Cek apakah API mengembalikan error (!trap)
        if (is_array($response) && isset($response['!trap'])) {
            $trapMsg = $response['!trap'][0]['message'] ?? 'Unknown Mikrotik error';
            return [
                'success' => false,
                'message' => "Aksi gagal saat set secret: Mikrotik error – $trapMsg",
            ];
        }

        // --- VERIFIKASI: baca ulang secret untuk memastikan profile benar-benar berubah ---
        $verifyResponse = $api->comm('/ppp/secret/print', [
            '.proplist' => 'name,profile',
            '?name' => $idpel,
        ]);
        $verifiedProfile = '';
        if (is_array($verifyResponse)) {
            foreach ($verifyResponse as $vRow) {
                if (isset($vRow['name']) && strtolower(trim($vRow['name'])) === strtolower(trim($idpel))) {
                    $verifiedProfile = trim($vRow['profile'] ?? '');
                    break;
                }
            }
        }
        if ($verifiedProfile !== '' && strtoupper($verifiedProfile) !== strtoupper($expiredProfileName)) {
            // Profile TIDAK berubah setelah set — coba sekali lagi langsung via getall+set
            $retryFind = $api->comm('/ppp/secret/getall', [
                '.proplist' => '.id',
                '?name' => $idpel,
            ]);
            $retryId = $retryFind[0]['.id'] ?? '';
            if ($retryId !== '') {
                $api->comm('/ppp/secret/set', [
                    '.id' => $retryId,
                    'profile' => $expiredProfileName,
                    'comment' => $comment,
                ]);
            }
            // Verifikasi ulang
            $verify2 = $api->comm('/ppp/secret/print', [
                '.proplist' => 'name,profile',
                '?name' => $idpel,
            ]);
            $verified2 = '';
            if (is_array($verify2)) {
                foreach ($verify2 as $v2Row) {
                    if (isset($v2Row['name']) && strtolower(trim($v2Row['name'])) === strtolower(trim($idpel))) {
                        $verified2 = trim($v2Row['profile'] ?? '');
                        break;
                    }
                }
            }
            if ($verified2 !== '' && strtoupper($verified2) !== strtoupper($expiredProfileName)) {
                return [
                    'success' => false,
                    'message' => "Aksi gagal: secret ditemukan tapi profile GAGAL berubah. Profile saat ini masih: '$verified2'. Cek konfigurasi Mikrotik.",
                ];
            }
        }

        $removedCount = 0;
        $activeIds = $mikrotikCache['active_ids_by_id'][$idpel] ?? [];
        if (!empty($activeIds)) {
            foreach ($activeIds as $activeId) {
                $api->comm('/ppp/active/remove', [
                    '.id' => $activeId,
                ]);
                $removedCount++;
            }
        }

        // Juga putuskan active connection via getall jika ada yg terlewat dari cache
        $liveActives = $api->comm('/ppp/active/print', [
            '.proplist' => '.id',
            '?name' => $idpel,
        ]);
        if (is_array($liveActives)) {
            foreach ($liveActives as $la) {
                $laId = $la['.id'] ?? '';
                if ($laId !== '') {
                    $api->comm('/ppp/active/remove', ['.id' => $laId]);
                    $removedCount++;
                }
            }
        }

        $mikrotikCache['status_by_id'][$idpel] = [
            'status' => 'OFFLINE',
            'profile' => $expiredProfileName,
            'source' => 'ppp-secret',
        ];
        $mikrotikCache['secret_id_by_id'][$idpel] = $secretId;
        $mikrotikCache['active_ids_by_id'][$idpel] = [];

        $verifyMsg = ($verifiedProfile !== '' && strtoupper($verifiedProfile) === strtoupper($expiredProfileName))
            ? ' [VERIFIED: secret profile = ' . $verifiedProfile . ']'
            : ' [VERIFIED via retry]';

        return [
            'success' => true,
            'message' => "Profile secret diubah ke '$expiredProfileName', active koneksi diputus ($removedCount koneksi)" . $verifyMsg,
        ];
    } catch (Throwable $e) {
        return [
            'success' => false,
            'message' => 'Aksi gagal: ' . $e->getMessage(),
        ];
    }
}

function restorePaidCustomerProfile($api, bool $isConnected, string $idpel, string $nama, string $nowa, string $targetProfile, string $periode, string $odp, array &$mikrotikCache): array
{
    if (!$isConnected || !$api) {
        return [
            'success' => false,
            'message' => 'Pemulihan dilewati: koneksi Mikrotik tidak tersedia',
        ];
    }

    $targetProfile = trim($targetProfile);
    if ($targetProfile === '' || strtoupper($targetProfile) === 'EXPIRED') {
        return [
            'success' => false,
            'message' => 'Pemulihan gagal: profile paket pelanggan tidak valid',
        ];
    }

    $profileLookupKey = mb_strtolower($targetProfile, 'UTF-8');
    $availableProfiles = $mikrotikCache['profiles'] ?? [];
    if (!isset($availableProfiles[$profileLookupKey])) {
        return [
            'success' => false,
            'message' => "Pemulihan gagal: profile '$targetProfile' tidak ditemukan di Mikrotik",
        ];
    }

    $targetProfile = $availableProfiles[$profileLookupKey];

    try {
        $secretId = (string)($mikrotikCache['secret_id_by_id'][$idpel] ?? '');
        if ($secretId === '') {
            return [
                'success' => false,
                'message' => 'Pemulihan gagal: PPP secret tidak ditemukan',
            ];
        }

        $odpText = trim($odp) !== '' ? trim($odp) : '-';
        $comment = "AKTIF $nama - $nowa - ODP:$odpText - $periode";

        $response = $api->comm('/ppp/secret/set', [
            '.id' => $secretId,
            'profile' => $targetProfile,
            'comment' => $comment,
        ]);

        // Cek apakah API mengembalikan error (!trap)
        if (is_array($response) && isset($response['!trap'])) {
            $trapMsg = $response['!trap'][0]['message'] ?? 'Unknown Mikrotik error';
            return [
                'success' => false,
                'message' => "Pemulihan gagal: Mikrotik error – $trapMsg",
            ];
        }

        $removedCount = 0;
        $activeIds = $mikrotikCache['active_ids_by_id'][$idpel] ?? [];
        if (!empty($activeIds)) {
            foreach ($activeIds as $activeId) {
                $api->comm('/ppp/active/remove', [
                    '.id' => $activeId,
                ]);
                $removedCount++;
            }
        }

        $mikrotikCache['status_by_id'][$idpel] = [
            'status' => $removedCount > 0 ? 'OFFLINE (reconnect setelah restore)' : 'OFFLINE',
            'profile' => $targetProfile,
            'source' => 'ppp-secret',
        ];
        $mikrotikCache['active_ids_by_id'][$idpel] = [];

        return [
            'success' => true,
            'message' => "Profile dipulihkan ke '$targetProfile'" . ($removedCount > 0 ? ", active connection diputus ($removedCount koneksi)" : ''),
        ];
    } catch (Throwable $e) {
        return [
            'success' => false,
            'message' => 'Pemulihan gagal: ' . $e->getMessage(),
        ];
    }
}

// -----------------------------------------------------------------------
// 6b. Helper functions — logika menunggak (selaras pelanggan_menunggak.php)
// -----------------------------------------------------------------------

function isSamePeriodAsTodayLocal(string $dateValue, string $today): bool
{
    if (empty($dateValue)) return false;
    $tsDate = strtotime($dateValue);
    $tsToday = strtotime($today);
    if ($tsDate === false || $tsToday === false) return false;
    return date('Y-m', $tsDate) === date('Y-m', $tsToday);
}

function parseIndoMonthYearLocal(string $value): ?array
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

function getFirstDueDateFixedByUsagePeriod(string $penggunaan, int $fixedDueDay): ?string
{
    $parsed = parseIndoMonthYearLocal($penggunaan);
    if (!$parsed) {
        return null;
    }

    return buildMonthlyDateLocal((int)$parsed['year'], (int)$parsed['month'], $fixedDueDay);
}

function buildMonthlyDateLocal(int $year, int $month, int $day): ?string
{
    if ($year < 1970 || $month < 1 || $month > 12) return null;
    if ($day < 1) $day = 1;
    $daysInMonth = (int)date('t', strtotime(sprintf('%04d-%02d-01', $year, $month)));
    if ($day > $daysInMonth) $day = $daysInMonth;
    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

function getFirstDueDateFixed(string $referenceDate, int $fixedDueDay): ?string
{
    if (empty($referenceDate) || strtotime($referenceDate) === false) return null;
    $refTs = strtotime($referenceDate);
    // Untuk mode fixed tempo, due pertama selalu di bulan berikutnya.
    // FIX: JANGAN strtotime('+1 month', $refTs) langsung -- kalau $referenceDate
    // tanggal 29/30/31 dan bulan berikutnya lebih pendek (mis. 31 Jan -> +1 month
    // = 3 Maret, BUKAN Februari), PHP overflow ke bulan sesudahnya lagi, bikin
    // pelanggan dapat "bonus" sebulan sebelum ditagih/diisolir. Hitung nomor
    // bulan/tahun langsung (aman dari overflow tanggal), baru +1.
    $year = (int)date('Y', $refTs);
    $month = (int)date('n', $refTs) + 1;
    if ($month > 12) { $month = 1; $year++; }
    return buildMonthlyDateLocal($year, $month, $fixedDueDay);
}

function getNextDueDateFixed(string $currentDueDate, int $fixedDueDay): ?string
{
    if (empty($currentDueDate) || strtotime($currentDueDate) === false) return null;
    // Fix overflow sama seperti getFirstDueDateFixed() di atas.
    $curTs = strtotime($currentDueDate);
    $year = (int)date('Y', $curTs);
    $month = (int)date('n', $curTs) + 1;
    if ($month > 12) { $month = 1; $year++; }
    return buildMonthlyDateLocal($year, $month, $fixedDueDay);
}

function hasSuccessfulPaymentInPeriod(mysqli $conn, string $idpel, string $startDate, string $endDate): bool
{
    if ($idpel === '' || $startDate === '' || $endDate === '') {
        return false;
    }

    if (strtotime($startDate) === false || strtotime($endDate) === false) {
        return false;
    }

    $idpelEsc = $conn->real_escape_string($idpel);
    $startEsc = $conn->real_escape_string($startDate);
    $endEsc = $conn->real_escape_string($endDate);
    $trxDateExpr = buildTrxDateExprCron();
    $sql = "SELECT 1 FROM transaksi WHERE IDPEL = '$idpelEsc' AND STATUS = 'BERHASIL' AND DATE($trxDateExpr) >= '$startEsc' AND DATE($trxDateExpr) < '$endEsc' LIMIT 1";
    $query = $conn->query($sql);

    return (bool)($query && $query->fetch_assoc());
}

/**
 * Hitung berapa bulan berturut-turut tidak ada pembayaran mulai dari firstDueDate.
 * Selaras dengan logika filteredMenunggak di pelanggan_menunggak.php:
 * - Jika ditemukan pembayaran di salah satu bulan dalam urutan → return 0 (bukan menunggak)
 * - Jika tidak ada pembayaran sama sekali sampai hari ini → return jumlah bulan yang terlewat
 */
function countConsecutiveMissedMonths(mysqli $conn, string $idpel, string $firstDueDate, string $today, bool $isFixedDay, int $fixedDueDay): int
{
    $bulanTunggak = 0;
    $nextDueDate  = $firstDueDate;
    $todayTs      = strtotime($today);

    while (!empty($nextDueDate) && strtotime($nextDueDate) <= $todayTs) {
        $cycleStart = $nextDueDate;
        $cycleEnd = $isFixedDay
            ? getNextDueDateFixed($cycleStart, $fixedDueDay)
            : date('Y-m-d', strtotime('+30 days', strtotime($cycleStart)));

        if (empty($cycleEnd) || strtotime($cycleEnd) === false) {
            break;
        }

        if (hasSuccessfulPaymentInPeriod($conn, $idpel, $cycleStart, $cycleEnd)) {
            return 0; // Ada pembayaran → isConsecutive = false → bukan menunggak
        }
        $bulanTunggak++;
        $nextDueDate = $cycleEnd;
        if (empty($nextDueDate) || strtotime($nextDueDate) === false) break;
    }
    return $bulanTunggak;
}

// -----------------------------------------------------------------------
// 7. Mulai proses pengecekan
// -----------------------------------------------------------------------
$hari_ini = date('Y-m-d');
$tglHariIni = (int)date('d');
$periode_saat_ini = periodeTagihanAktif(
    $tglHariIni,
    $tanggal_awal_tutup_buku,
    $tanggal_akhir_tutup_buku,
    $jatuh_tempo_hari,
    $hari_ini
);

echo "Periode saat ini (sesuai setting): $periode_saat_ini\n";

// ===================================================================
// LABEL PERIODE UNTUK CEK "SUDAH BAYAR PERIODE AKTIF" (dipakai utk
// AKTIFKAN/pulihkan profile) -- HARUS SAMA PERSIS dengan rumus yang
// dipakai saat invoice benar-benar ditulis (Transaction.php / portal
// pelanggan), BUKAN $periode_saat_ini di atas (itu dari tutup-buku
// lama, cuma dipakai utk teks log/komentar Mikrotik, bukan lagi utk
// keputusan aktifkan). SEBELUMNYA satu-satunya sumber ($periode_saat_ini)
// dipakai utk SEMUA tipe tempo & bisa TIDAK PERNAH match dgn PENGUNAAN
// invoice asli (Awal/Akhir Tutup Buku vs Jatuh Tempo Hari + Periode
// Tercatat adalah 2 rumus beda) -- akibatnya pelanggan yang sudah bayar
// bisa tetap EXPIRED krn pengecekan "ada transaksi periode aktif" tidak
// pernah ketemu. Fixed Due Date & Rolling/Monthversary py rumus beda:
$dueMonthTsPeriodeAktifCron = ($tglHariIni <= $jatuh_tempo_hari)
    ? strtotime($hari_ini)
    : strtotime('+1 month', strtotime($hari_ini));
$modePeriodeAktifCron = ($tglHariIni < $tanggal_awal_tutup_buku)
    ? 'berjalan'
    : $periode_tercatat;
$periodeAktifFixedDueDate = tagihanResolvePeriodeTercatat(
    (int) date('n', $dueMonthTsPeriodeAktifCron),
    (int) date('Y', $dueMonthTsPeriodeAktifCron),
    $modePeriodeAktifCron
);
$periodeAktifRollingMonthversary = tagihanResolvePeriodeTercatat(
    (int) date('n', strtotime($hari_ini)),
    (int) date('Y', strtotime($hari_ini)),
    'berjalan'
);
echo "Periode aktif utk cek pembayaran -- Fixed Due Date: $periodeAktifFixedDueDate | Rolling/Monthversary: $periodeAktifRollingMonthversary\n";

// Rekap hasil
$statistik = [
    'total_pelanggan'              => 0,
    'sudah_bayar'                  => 0,
    'belum_bayar_total'            => 0,
    'dipulihkan_profile_total'     => 0,
    'prabayar_mengikuti_tanggal'   => [],
    'pascabayar_mengikuti_tanggal' => [],
    'prabayar_mengikuti_tempo'     => [],
    'pascabayar_mengikuti_tempo'   => [],
    'prabayar_monthversary'        => [],
    'pascabayar_monthversary'      => [],
    'pelanggan_dipulihkan'         => [],
];

$hargaPaketMap = [];
$fasumPaketList = [];
$promoPaketIds = [];

$qPaketMap = mysqli_query($conn, "SELECT id, PAKET, HARGA, BRAND, AREA FROM paket");
while ($qPaketMap && ($r = mysqli_fetch_assoc($qPaketMap))) {
    $paketKey = strtolower(trim((string)($r['PAKET'] ?? '')));
    $brandKey = strtolower(trim((string)($r['BRAND'] ?? '')));
    $areaKey = strtolower(trim((string)($r['AREA'] ?? '')));
    $mapKey = $paketKey . '|' . $brandKey . '|' . $areaKey;
    $hargaPaketMap[$mapKey] = $r['HARGA'];

    if ($paketKey !== '' && ($r['HARGA'] === '' || (float)$r['HARGA'] <= 0)) {
        $fasumPaketList[$paketKey] = (string)($r['id'] ?? '');
    }
}

$qPromo = mysqli_query($conn, "SELECT paket_id FROM promo_paket");
while ($qPromo && ($r = mysqli_fetch_assoc($qPromo))) {
    $promoPaketIds[] = (string)($r['paket_id'] ?? '');
}

// ---------- Loop semua server milik user ----------
$sql_server = "SELECT * FROM `server` WHERE `user_id` = '" . mysqli_real_escape_string($conn, $iduser) . "'";
$query_server = mysqli_query($conn, $sql_server);
$total_server = mysqli_num_rows($query_server);
echo "\nTotal server ditemukan: $total_server\n";

while ($server = mysqli_fetch_array($query_server)) {
    $AREA    = $server['AREA'];
    $PEMILIK = $server['PEMILIK'];
    $BRAND   = $server['BRAND'] ?? $PEMILIK;
    $IP      = $server['IP'] ?? '';
    $PASSWORD = $server['PASSWORD'] ?? '';

    echo "\n--- Server: $PEMILIK | Area: $AREA ---\n";

    // Koneksi Mikrotik per server dengan timeout agar proses tidak menggantung.
    $mikApi = null;
    $mikConnected = false;
    if (!empty($IP) && !empty($PEMILIK) && !empty($PASSWORD)) {
        $mikApi = new RouterosAPI();
        $mikApi->debug = false;
        $mikApi->timeout = $mikrotik_timeout;
        $mikApi->attempts = $mikrotik_attempts;
        $mikApi->delay = $mikrotik_delay;
        $mikConnected = $mikApi->connect($IP, $PEMILIK, $PASSWORD);
    }

    if ($mikConnected) {
        echo "Mikrotik: CONNECTED (timeout {$mikrotik_timeout} detik)\n";
    } else {
        echo "Mikrotik: GAGAL CONNECT / TIMEOUT {$mikrotik_timeout} detik\n";
    }

    $mikrotikCache = getMikrotikCache($mikApi, $mikConnected);

    // ---------- Loop semua pelanggan di server ini ----------
    $stmt_pel = $conn->prepare(
        "SELECT IDPEL, NAMA, NOWA, EMAIL, PAKET, ALAMAT, TANGGALPASANG,
                TIPE_BAYAR, TIPE_TEMPO, TEMPO, MODE, ODP, TANGGAL_MONTHVERSARY
         FROM `pelanggan`
         WHERE `PEMILIK` = ? AND `AREA` = ?"
    );
    $stmt_pel->bind_param("ss", $PEMILIK, $AREA);
    $stmt_pel->execute();
    $res_pel = $stmt_pel->get_result();

    $pelangganServer = [];
    $customerIds = [];

    while ($pel = $res_pel->fetch_assoc()) {
        $pelangganServer[] = $pel;
        $customerIds[] = (string)($pel['IDPEL'] ?? '');
    }

    $lastPaymentMap = getLastPaymentsBulk($conn, $customerIds);
    $lastPaidUsageMap = getLastPaidUsageMapBulk($conn, $customerIds);
    $firstPaymentMap = getFirstAndCountPaymentsBulk($conn, $customerIds);

    // Track IDPEL yang belum bayar di server ini untuk final verification
    $belumBayarIds = [];

    foreach ($pelangganServer as $pel) {
        $statistik['total_pelanggan']++;

        $IDPEL        = $pel['IDPEL'];
        $NAMA         = $pel['NAMA'];
        $NOWA         = $pel['NOWA'];
        $EMAIL        = $pel['EMAIL'];
        $PAKET        = $pel['PAKET'];
        $ALAMAT       = $pel['ALAMAT'];
        $ODP          = $pel['ODP'] ?? '';
        $TANGGALPASANG = $pel['TANGGALPASANG'];
        $TANGGAL_MONTHVERSARY = $pel['TANGGAL_MONTHVERSARY'] ?? null;
        $TIPE_BAYAR   = strtolower(trim($pel['TIPE_BAYAR'])); // prabayar / pascabayar
        $TIPE_TEMPO_RAW = strtolower(trim((string)$pel['TIPE_TEMPO']));
        // Samakan dengan pelanggan_menunggak.php: nilai selain mengikuti_tanggal_bayar/monthversary diperlakukan sebagai mengikuti_tanggal_tempo.
        if ($TIPE_TEMPO_RAW === 'mengikuti_tanggal_bayar') {
            $TIPE_TEMPO = 'mengikuti_tanggal_bayar';
        } elseif ($TIPE_TEMPO_RAW === 'monthversary') {
            $TIPE_TEMPO = 'monthversary';
        } else {
            $TIPE_TEMPO = 'mengikuti_tanggal_tempo';
        }
        $TEMPO        = $pel['TEMPO']; // date Y-m-d (expiry date untuk mengikuti_tanggal_tempo)
        $targetProfilePaket = trim((string)$PAKET);

        // Ambil pembayaran terakhir sekali per pelanggan untuk ditampilkan di laporan.
        $waktu_terakhir_bayar = $lastPaymentMap[$IDPEL] ?? null;
        $penggunaan_terakhir_berhasil = trim((string)($lastPaidUsageMap[$IDPEL] ?? ''));
        $pembayaran_terakhir_display = $waktu_terakhir_bayar ?: '-';

        // ============================================================
        //  SYARAT "LAYAK DIPULIHKAN" (utk AKTIFKAN ulang profile yang lagi
        //  EXPIRED setelah pelanggan bayar) -- $layakDipulihkan
        // ============================================================
        //  RUMUS LAMA (SUDAH DIBUANG, JANGAN DIPAKAI LAGI): cocokkan label
        //  PENGUNAAN pembayaran terakhir ke label periode hasil PROYEKSI
        //  "+1 bulan begitu hari-ini > jatuh_tempo_hari" ($periodeAktifFixedDueDate /
        //  $periodeAktifRollingMonthversary, lihat definisinya di atas). Proyeksi
        //  itu memang benar dipakai utk cari label INVOICE BERIKUTNYA yang belum
        //  tentu ada (lihat tagihanFallbackPeriodeLabel()), TAPI begitu hari ini
        //  lewat jatuh_tempo_hari, label proyeksinya ikut lompat ke bulan DEPAN --
        //  sementara pembayaran pelanggan yang BARU SAJA melunasi tunggakan
        //  labelnya masih bulan yang BARU LEWAT (bulan yang tunggakannya baru
        //  dilunasi). Dua-duanya TIDAK PERNAH match, jadi restore GAGAL TERUS
        //  untuk skenario paling umum: pelanggan telat bayar lalu melunasi
        //  (EXPIRED sendiri kan baru terjadi SETELAH jatuh tempo lewat, jadi hari
        //  ini hampir selalu sudah lewat jatuh_tempo_hari saat mereka bayar).
        //  Bukti nyata bug ini: laporan cron "Sudah bayar: 94, Profile
        //  dipulihkan: 0" -- 94 pelanggan sudah bayar tapi TIDAK SATU PUN profile
        //  MikroTik-nya ikut dipulihkan.
        //
        //  RUMUS BARU: tidak lagi cocokkan label bulan sama sekali. Cukup 2 syarat:
        //   1. $belum_bayar sudah FALSE (dihitung per cabang TIPE_TEMPO di bawah,
        //      berbasis TANGGAL bukan label -- rumus ini SUDAH diselaraskan &
        //      dipercaya sama seperti pelanggan_menunggak.php).
        //   2. Pelanggan PERNAH punya minimal 1 pembayaran BERHASIL yang tercatat
        //      ($waktu_terakhir_bayar tidak kosong) -- ini SEMATA-MATA supaya
        //      pelanggan yang BARU PASANG dan BELUM PERNAH BAYAR SAMA SEKALI
        //      (cuma masih dalam masa tenggang prabayar) tidak ikut ke-restore
        //      cuma gara-gara "belum dianggap menunggak".
        $periodeAktifUntukPelangganIni = ($TIPE_TEMPO === 'mengikuti_tanggal_tempo')
            ? $periodeAktifFixedDueDate
            : $periodeAktifRollingMonthversary;
        $layakDipulihkan = ($waktu_terakhir_bayar !== null && $waktu_terakhir_bayar !== '');
        $tanggalBayarPeriodeAktif = $layakDipulihkan ? (string)$waktu_terakhir_bayar : '';
        $penggunaanPeriodeAktif = $penggunaan_terakhir_berhasil !== '' ? $penggunaan_terakhir_berhasil : $periodeAktifUntukPelangganIni;

        // Lewati jika paket FREE atau FASUM non-promo (harga <= 0)
        if (stripos($PAKET, 'FREE') !== false) {
            continue;
        }

        $paketKeyCron = strtolower(trim((string)$PAKET));
        $brandKeyCron = strtolower(trim((string)$BRAND));
        $areaKeyCron = strtolower(trim((string)$AREA));

        if (isFasumNonPromoCron($paketKeyCron, $fasumPaketList, $promoPaketIds)) {
            continue;
        }

        $hargaPaketCron = resolveHargaPaketCron($hargaPaketMap, $paketKeyCron, $brandKeyCron, $areaKeyCron);
        // Samakan dengan pelanggan_menunggak.php: paket tanpa harga valid tidak dihitung menunggak.
        if ($hargaPaketCron === null || (float)$hargaPaketCron <= 0) {
            continue;
        }

        $belum_bayar  = false;
        $layakPulihkanProfile = false;
        $alasanPemulihan = '';
        $keterangan   = '';
        $jatuh_tempo_str = '';

        // ==============================================================
        //  LOGIKA BERDASARKAN TIPE_TEMPO
        // ==============================================================

        if ($TIPE_TEMPO === 'mengikuti_tanggal_bayar') {
            // ----------------------------------------------------------
            //  Mode: 1 bulan dari referensi siklus Rolling, dihitung dari
            //  SELURUH histori pembayaran BERHASIL (tagihanComputeRollingReferenceDate),
            //  BUKAN cuma tanggal bayar TERAKHIR -- versi lama di sini memakai
            //  $waktu_terakhir_bayar mentah yang bikin bayar cepat ikut memajukan
            //  jatuh tempo berikutnya (padahal seharusnya tidak, lihat aturan
            //  bisnis di tagihanComputeRollingReferenceDate()). Selaras dgn versi
            //  yang sudah dipakai tables.php/dashboard.php lewat tagihan_status_lib.php.
            //  - Jika baru pasang/bayar bulan ini → belum menunggak
            //  - Hitung bulan berturut-turut tanpa bayar, harus >= 1
            // ----------------------------------------------------------
            $referenceDate = tagihanComputeRollingReferenceDate($conn, $IDPEL, $TANGGALPASANG);
            $rollingOverride = tagihanGetRollingOverrideDueDate($TANGGAL_MONTHVERSARY, $hari_ini);

            if ($TIPE_BAYAR === 'prabayar' && empty($waktu_terakhir_bayar)) {
                // Prabayar yang BELUM PERNAH bayar sama sekali (baru pasang): jatuh
                // tempo pertama = tanggal pasang itu sendiri (bayar DI MUKA), BUKAN
                // tanggal pasang + 1 bulan -- dan TIDAK dapat keringanan gratis
                // sebulan penuh dari "baru pasang bulan ini", cuma waktu tunggu
                // (grace period prabayar) yang sudah dikonfigurasi di Payment Setting.
                $firstDueDate = $TANGGALPASANG;
                $jatuh_tempo_str = $firstDueDate;
                $batasIsolirBaru = ($prabayar_grace_period > 0)
                    ? date('Y-m-d', strtotime("+{$prabayar_grace_period} days", strtotime($firstDueDate)))
                    : $firstDueDate;

                if (strtotime($batasIsolirBaru) > strtotime($hari_ini)) {
                    $statistik['sudah_bayar']++;
                    if ($layakDipulihkan) {
                        $layakPulihkanProfile = true;
                        $alasanPemulihan = "Masih dalam waktu tunggu prabayar sejak pasang $TANGGALPASANG";
                    }
                } else {
                    $belum_bayar = true;
                    $keterangan = "Belum pernah bayar sejak pasang: $TANGGALPASANG | Waktu tunggu: $prabayar_grace_period hari";
                }
            } elseif (isSamePeriodAsTodayLocal($TANGGALPASANG, $hari_ini) || isSamePeriodAsTodayLocal($referenceDate, $hari_ini)) {
                // Baru pasang atau baru bayar bulan ini → belum dihitung menunggak
                $statistik['sudah_bayar']++;
                if ($layakDipulihkan) {
                    $layakPulihkanProfile = true;
                    $alasanPemulihan = "Bayar/pasang bulan ini, transaksi periode $periode_saat_ini berhasil";
                }
            } else {
                $firstDueDate    = $rollingOverride ?? date('Y-m-d', strtotime('+30 days', strtotime($referenceDate)));
                $jatuh_tempo_str = $firstDueDate;

                if (strtotime($firstDueDate) > strtotime($hari_ini)) {
                    // Jatuh tempo belum lewat
                    $statistik['sudah_bayar']++;
                    if ($layakDipulihkan) {
                        $layakPulihkanProfile = true;
                        $alasanPemulihan = "Jatuh tempo $firstDueDate belum lewat dan sudah ada transaksi periode $periode_saat_ini";
                    }
                } else {
                    $bulanTunggak = countConsecutiveMissedMonths($conn, $IDPEL, $firstDueDate, $hari_ini, false, 0);
                    if ($bulanTunggak >= 1) {
                        $belum_bayar = true;
                        $keterangan  = "Terakhir bayar: $referenceDate | Jatuh tempo: $firstDueDate | Nunggak: $bulanTunggak bulan";
                    } else {
                        $statistik['sudah_bayar']++;
                        if ($layakDipulihkan) {
                            $layakPulihkanProfile = true;
                            $alasanPemulihan = "Transaksi periode $periode_saat_ini sudah berhasil";
                        }
                    }
                }
            }

            // Simpan ke bucket yang sesuai
            if ($belum_bayar) {
                $mikStatusData = getMikrotikStatus($mikrotikCache, $mikConnected, $IDPEL);
                $mikrotik_status_online = $mikStatusData['status'];
                $mikrotik_profile_saat_ini = $mikStatusData['profile'];
                $mikrotik_aksi = 'Tidak ada aksi Mikrotik';

                $belumBayarIds[] = $IDPEL;
                echo "  [BELUM BAYAR] $IDPEL | $NAMA | tipe=mengikuti_tanggal_bayar | secret_profile='$mikrotik_profile_saat_ini' | status='$mikrotik_status_online'\n";

                if (strtoupper(trim($mikrotik_profile_saat_ini)) !== 'EXPIRED') {
                    echo "    -> Profile belum EXPIRED ('$mikrotik_profile_saat_ini'), mengubah secret ke EXPIRED...\n";
                    $aksi = enforceExpiredProfileAndDisconnect(
                        $mikApi,
                        $mikConnected,
                        $IDPEL,
                        $NAMA,
                        $NOWA,
                        $periode_saat_ini,
                        $ODP,
                        $mikrotikCache
                    );

                    $mikrotik_aksi = $aksi['message'];
                    echo "    -> Hasil: " . ($aksi['success'] ? 'BERHASIL' : 'GAGAL') . " – {$aksi['message']}\n";
                    if ($aksi['success']) {
                        $mikrotik_profile_saat_ini = 'EXPIRED';
                        if ($mikrotik_status_online === 'ONLINE') {
                            $mikrotik_status_online = 'OFFLINE (diputus)';
                        }
                    }
                } elseif ($mikrotik_status_online === 'ONLINE' && strtoupper(trim($mikrotik_profile_saat_ini)) === 'EXPIRED') {
                    $mikrotik_aksi = 'Tidak ada aksi: sudah ONLINE dengan profile EXPIRED';
                    echo "    -> Sudah EXPIRED (ONLINE)\n";
                } elseif ($mikrotik_status_online === 'OFFLINE') {
                    $mikrotik_aksi = 'Tidak ada aksi: pelanggan OFFLINE dan profile sudah EXPIRED';
                    echo "    -> Sudah EXPIRED (OFFLINE)\n";
                } else {
                    $mikrotik_aksi = 'Tidak ada aksi: status Mikrotik tidak valid untuk eksekusi';
                    echo "    -> Status tidak valid: '$mikrotik_status_online'\n";
                }

                $entry = [
                    'status_tagihan' => 'BELUM BAYAR',
                    'IDPEL'        => $IDPEL,
                    'NAMA'         => $NAMA,
                    'NOWA'         => $NOWA,
                    'EMAIL'        => $EMAIL,
                    'PAKET'        => $PAKET,
                    'TIPE_BAYAR'   => $TIPE_BAYAR,
                    'TIPE_TEMPO'   => $TIPE_TEMPO,
                    'pembayaran_terakhir' => $pembayaran_terakhir_display,
                    'jatuh_tempo'  => $jatuh_tempo_str,
                    'harus_bayar_paling_lambat' => $jatuh_tempo_str,
                    'mikrotik_status' => $mikrotik_status_online,
                    'mikrotik_profile' => $mikrotik_profile_saat_ini,
                    'mikrotik_aksi' => $mikrotik_aksi,
                    'keterangan'   => $keterangan,
                    'server'       => "$PEMILIK | $AREA | $BRAND",
                    'portal'       => "$URL/crm/billing/broadband/portal.php?cari=$IDPEL",
                ];
                if ($TIPE_BAYAR === 'prabayar') {
                    $statistik['prabayar_mengikuti_tanggal'][] = $entry;
                } else {
                    $statistik['pascabayar_mengikuti_tanggal'][] = $entry;
                }
                $statistik['belum_bayar_total']++;
            }

        } elseif ($TIPE_TEMPO === 'mengikuti_tanggal_tempo') {
            // ----------------------------------------------------------
            //  Mode: hari jatuh tempo tetap ($jatuh_tempo_hari) tiap bulan
            //  Selaras dengan logika pelanggan_menunggak.php:
            //  - Gunakan fixedDueDay dari reminder config, bukan field TEMPO DB
            //  - Jika baru pasang/bayar bulan ini → belum menunggak
            //  - Hitung bulan berturut-turut tanpa bayar, harus >= 1
            // ----------------------------------------------------------
            $referenceDate = $waktu_terakhir_bayar
                ? substr((string)$waktu_terakhir_bayar, 0, 10)
                : $TANGGALPASANG;

            if ($TIPE_BAYAR === 'prabayar' && empty($waktu_terakhir_bayar)) {
                // Prabayar yang BELUM PERNAH bayar sama sekali (baru pasang): jatuh
                // tempo pertama = tanggal pasang itu sendiri (bayar DI MUKA), BUKAN
                // menunggu hari jatuh tempo global ($jatuh_tempo_hari) yang bisa saja
                // masih jauh -- dan TIDAK dapat keringanan gratis sebulan penuh dari
                // "baru pasang bulan ini", cuma waktu tunggu (grace period prabayar).
                $firstDueDate = $TANGGALPASANG;
                $jatuh_tempo_str = $firstDueDate;
                $batasIsolirBaru = ($prabayar_grace_period > 0)
                    ? date('Y-m-d', strtotime("+{$prabayar_grace_period} days", strtotime($firstDueDate)))
                    : $firstDueDate;

                if (strtotime($batasIsolirBaru) > strtotime($hari_ini)) {
                    $statistik['sudah_bayar']++;
                    if ($layakDipulihkan) {
                        $layakPulihkanProfile = true;
                        $alasanPemulihan = "Masih dalam waktu tunggu prabayar sejak pasang $TANGGALPASANG";
                    }
                } else {
                    $belum_bayar = true;
                    $keterangan = "Belum pernah bayar sejak pasang: $TANGGALPASANG | Waktu tunggu: $prabayar_grace_period hari";
                }
            } elseif (isSamePeriodAsTodayLocal($TANGGALPASANG, $hari_ini) || isSamePeriodAsTodayLocal($referenceDate, $hari_ini)) {
                // Baru pasang atau baru bayar bulan ini → belum dihitung menunggak
                $statistik['sudah_bayar']++;
                if ($layakDipulihkan) {
                    $layakPulihkanProfile = true;
                    $alasanPemulihan = "Bayar/pasang bulan ini, transaksi periode $periode_saat_ini berhasil";
                }
            } else {
                $firstDueDate    = getFirstDueDateFixed($referenceDate, $jatuh_tempo_hari);
                if ($TIPE_BAYAR === 'prabayar') {
                    $fixedDueByUsage = getFirstDueDateFixedByUsagePeriod($penggunaan_terakhir_berhasil, $jatuh_tempo_hari);
                    if (!empty($fixedDueByUsage)) {
                        // Khusus prabayar+tempo tetap: patok due ke periode penggunaan terakhir.
                        $firstDueDate = $fixedDueByUsage;
                    }
                }
                $jatuh_tempo_str = $firstDueDate ?? '';

                if (empty($firstDueDate) || strtotime($firstDueDate) > strtotime($hari_ini)) {
                    // Jatuh tempo belum lewat
                    $statistik['sudah_bayar']++;
                    if ($layakDipulihkan) {
                        $layakPulihkanProfile = true;
                        $alasanPemulihan = "Jatuh tempo " . ($firstDueDate ?? '-') . " belum lewat dan sudah ada transaksi periode $periode_saat_ini";
                    }
                } else {
                    $bulanTunggak = countConsecutiveMissedMonths($conn, $IDPEL, $firstDueDate, $hari_ini, true, $jatuh_tempo_hari);
                    if ($bulanTunggak >= 1) {
                        $belum_bayar = true;
                        $keterangan  = "Terakhir bayar: $referenceDate | Jatuh tempo: $firstDueDate | Nunggak: $bulanTunggak bulan";
                    } else {
                        $statistik['sudah_bayar']++;
                        if ($layakDipulihkan) {
                            $layakPulihkanProfile = true;
                            $alasanPemulihan = "Transaksi periode $periode_saat_ini sudah berhasil";
                        }
                    }
                }
            }

            // Simpan ke bucket yang sesuai
            if ($belum_bayar) {
                $mikStatusData = getMikrotikStatus($mikrotikCache, $mikConnected, $IDPEL);
                $mikrotik_status_online = $mikStatusData['status'];
                $mikrotik_profile_saat_ini = $mikStatusData['profile'];
                $mikrotik_aksi = 'Tidak ada aksi Mikrotik';

                $belumBayarIds[] = $IDPEL;
                echo "  [BELUM BAYAR] $IDPEL | $NAMA | tipe=mengikuti_tanggal_tempo | secret_profile='$mikrotik_profile_saat_ini' | status='$mikrotik_status_online'\n";

                if (strtoupper(trim($mikrotik_profile_saat_ini)) !== 'EXPIRED') {
                    echo "    -> Profile belum EXPIRED ('$mikrotik_profile_saat_ini'), mengubah secret ke EXPIRED...\n";
                    $aksi = enforceExpiredProfileAndDisconnect(
                        $mikApi,
                        $mikConnected,
                        $IDPEL,
                        $NAMA,
                        $NOWA,
                        $periode_saat_ini,
                        $ODP,
                        $mikrotikCache
                    );

                    $mikrotik_aksi = $aksi['message'];
                    echo "    -> Hasil: " . ($aksi['success'] ? 'BERHASIL' : 'GAGAL') . " – {$aksi['message']}\n";
                    if ($aksi['success']) {
                        $mikrotik_profile_saat_ini = 'EXPIRED';
                        if ($mikrotik_status_online === 'ONLINE') {
                            $mikrotik_status_online = 'OFFLINE (diputus)';
                        }
                    }
                } elseif ($mikrotik_status_online === 'ONLINE' && strtoupper(trim($mikrotik_profile_saat_ini)) === 'EXPIRED') {
                    $mikrotik_aksi = 'Tidak ada aksi: sudah ONLINE dengan profile EXPIRED';
                    echo "    -> Sudah EXPIRED (ONLINE)\n";
                } elseif ($mikrotik_status_online === 'OFFLINE') {
                    $mikrotik_aksi = 'Tidak ada aksi: pelanggan OFFLINE dan profile sudah EXPIRED';
                    echo "    -> Sudah EXPIRED (OFFLINE)\n";
                } else {
                    $mikrotik_aksi = 'Tidak ada aksi: status Mikrotik tidak valid untuk eksekusi';
                    echo "    -> Status tidak valid: '$mikrotik_status_online'\n";
                }

                $entry = [
                    'status_tagihan' => 'BELUM BAYAR',
                    'IDPEL'        => $IDPEL,
                    'NAMA'         => $NAMA,
                    'NOWA'         => $NOWA,
                    'PAKET'        => $PAKET,
                    'TIPE_BAYAR'   => $TIPE_BAYAR,
                    'TIPE_TEMPO'   => $TIPE_TEMPO,
                    'pembayaran_terakhir' => $pembayaran_terakhir_display,
                    'jatuh_tempo'  => $jatuh_tempo_str,
                    'harus_bayar_paling_lambat' => $jatuh_tempo_str,
                    'mikrotik_status' => $mikrotik_status_online,
                    'mikrotik_profile' => $mikrotik_profile_saat_ini,
                    'mikrotik_aksi' => $mikrotik_aksi,
                    'keterangan'   => $keterangan,
                    'server'       => "$PEMILIK | $AREA | $BRAND",
                    'portal'       => "$URL/crm/billing/broadband/portal.php?cari=$IDPEL",
                ];
                if ($TIPE_BAYAR === 'prabayar') {
                    $statistik['prabayar_mengikuti_tempo'][] = $entry;
                } else {
                    $statistik['pascabayar_mengikuti_tempo'][] = $entry;
                }
                $statistik['belum_bayar_total']++;
            }

        } elseif ($TIPE_TEMPO === 'monthversary') {
            // ----------------------------------------------------------
            //  Mode: jatuh tempo tetap mengikuti tanggal pasang/aktifasi
            //  MASING-MASING pelanggan (anchor per-pelanggan, disimpan di
            //  TANGGAL_MONTHVERSARY, bukan hari global $jatuh_tempo_hari).
            //  - Pascabayar: anchor = TANGGALPASANG, dikunci sekali, permanen.
            //  - Prabayar  : anchor sementara = TANGGALPASANG, lalu dikunci
            //    ulang ke tanggal transaksi BERHASIL pertama begitu transaksi
            //    pertama itu tercatat (self-heal, sekali saja, lalu permanen).
            //  Prabayar juga dapat waktu tunggu (grace period) sebelum isolir,
            //  pakai setting prabayar_grace_period yang sudah ada.
            // ----------------------------------------------------------
            $firstPaymentInfo = $firstPaymentMap[$IDPEL] ?? null;

            if (empty($TANGGAL_MONTHVERSARY)) {
                // Self-heal: anchor belum pernah diisi (baru dipindah ke mode ini via edit).
                $anchorBaru = ($TIPE_BAYAR === 'prabayar' && $firstPaymentInfo)
                    ? substr((string)$firstPaymentInfo['waktu_pertama'], 0, 10)
                    : $TANGGALPASANG;
                if (!empty($anchorBaru)) {
                    $anchorEsc = $conn->real_escape_string($anchorBaru);
                    $idpelEsc = $conn->real_escape_string($IDPEL);
                    $conn->query("UPDATE `pelanggan` SET `TANGGAL_MONTHVERSARY` = '$anchorEsc' WHERE `IDPEL` = '$idpelEsc'");
                    $TANGGAL_MONTHVERSARY = $anchorBaru;
                    echo "  [MONTHVERSARY] $IDPEL – anchor awal diisi: $anchorBaru\n";
                }
            } elseif ($TIPE_BAYAR === 'prabayar' && $firstPaymentInfo && (int)$firstPaymentInfo['jumlah_transaksi'] === 1) {
                // Transaksi BERHASIL pertama baru saja tercatat -> kunci ulang anchor ke tanggal transaksi itu (sekali saja).
                $tanggalTransaksiPertama = substr((string)$firstPaymentInfo['waktu_pertama'], 0, 10);
                if ($tanggalTransaksiPertama !== '' && $tanggalTransaksiPertama !== substr((string)$TANGGAL_MONTHVERSARY, 0, 10)) {
                    $anchorEsc = $conn->real_escape_string($tanggalTransaksiPertama);
                    $idpelEsc = $conn->real_escape_string($IDPEL);
                    $conn->query("UPDATE `pelanggan` SET `TANGGAL_MONTHVERSARY` = '$anchorEsc' WHERE `IDPEL` = '$idpelEsc'");
                    $TANGGAL_MONTHVERSARY = $tanggalTransaksiPertama;
                    echo "  [MONTHVERSARY] $IDPEL – anchor dikunci ke transaksi pertama: $tanggalTransaksiPertama\n";
                }
            }

            $anchorDay = (int) date('j', strtotime($TANGGAL_MONTHVERSARY ?: $TANGGALPASANG));
            $referenceDate = $waktu_terakhir_bayar
                ? substr((string)$waktu_terakhir_bayar, 0, 10)
                : $TANGGALPASANG;

            // Toggle "Monthversary ikut tanggal bayar terakhir" (Payment Setting).
            // Kalau ON: hari jatuh tempo TIDAK dikunci permanen ke anchor awal,
            // tapi ikut hari pembayaran BERHASIL yang paling baru (mis. anchor
            // awal tgl 10, tapi kalau terakhir bayar tgl 14 maka siklus
            // berikutnya jatuh tempo jadi tgl 14, bukan tetap tgl 10). Kolom
            // TANGGAL_MONTHVERSARY yang sudah dikunci di atas TIDAK diubah --
            // ini cuma override utk perhitungan due date siklus berjalan.
            // FIX: SEBELUMNYA overwrite $anchorDay TANPA SYARAT arah, jadi
            // bayar CEPAT (mis. anchor tgl 10, dibayar tgl 8) ikut memundurkan
            // anchor ke tgl 8 -- bertentangan dgn aturan bisnis "tidak dihukum
            // krn bayar cepat" yang SUDAH benar diterapkan di tagihanHitung
            // JatuhTempoBerikutnya()/tagihanHitungStatus() (tagihan_status_lib.php,
            // dipakai tables.php/dashboard). Sekarang ASIMETRIS sama persis dgn
            // lib itu: anchor cuma MAJU kalau bayar TELAT, tidak pernah mundur.
            if ($monthversary_follow_last_payment && $waktu_terakhir_bayar) {
                $lastPaymentDay = (int) date('j', strtotime($referenceDate));
                if ($lastPaymentDay > $anchorDay) {
                    $anchorDay = $lastPaymentDay;
                }
            }

            if ($TIPE_BAYAR === 'prabayar' && empty($waktu_terakhir_bayar)) {
                // Prabayar yang BELUM PERNAH bayar sama sekali (baru pasang): jatuh
                // tempo pertama = tanggal pasang itu sendiri (bayar DI MUKA), BUKAN
                // tanggal pasang + 1 bulan -- dan TIDAK dapat keringanan gratis
                // sebulan penuh dari "baru pasang bulan ini", cuma waktu tunggu
                // (grace period prabayar) yang sudah dikonfigurasi di Payment Setting.
                $firstDueDate = $TANGGALPASANG;
                $jatuh_tempo_str = $firstDueDate;
                $batasIsolirBaru = ($prabayar_grace_period > 0)
                    ? date('Y-m-d', strtotime("+{$prabayar_grace_period} days", strtotime($firstDueDate)))
                    : $firstDueDate;

                if (strtotime($batasIsolirBaru) > strtotime($hari_ini)) {
                    $statistik['sudah_bayar']++;
                    if ($layakDipulihkan) {
                        $layakPulihkanProfile = true;
                        $alasanPemulihan = "Masih dalam waktu tunggu prabayar sejak pasang $TANGGALPASANG";
                    }
                } else {
                    $belum_bayar = true;
                    $keterangan = "Belum pernah bayar sejak pasang: $TANGGALPASANG | Waktu tunggu: $prabayar_grace_period hari";
                }
            } elseif (isSamePeriodAsTodayLocal($TANGGALPASANG, $hari_ini) || isSamePeriodAsTodayLocal($referenceDate, $hari_ini)) {
                // Baru pasang atau baru bayar bulan ini → belum dihitung menunggak
                $statistik['sudah_bayar']++;
                if ($layakDipulihkan) {
                    $layakPulihkanProfile = true;
                    $alasanPemulihan = "Bayar/pasang bulan ini, transaksi periode $periode_saat_ini berhasil";
                }
            } else {
                $firstDueDate    = getFirstDueDateFixed($referenceDate, $anchorDay);
                $jatuh_tempo_str = $firstDueDate ?? '';

                // Prabayar dapat waktu tunggu (grace period) tambahan sebelum diisolir.
                $batasIsolir = $firstDueDate;
                if ($TIPE_BAYAR === 'prabayar' && !empty($firstDueDate) && $prabayar_grace_period > 0) {
                    $batasIsolir = date('Y-m-d', strtotime("+{$prabayar_grace_period} days", strtotime($firstDueDate)));
                }

                if (empty($firstDueDate) || strtotime($batasIsolir) > strtotime($hari_ini)) {
                    // Jatuh tempo (+ waktu tunggu untuk prabayar) belum lewat
                    $statistik['sudah_bayar']++;
                    if ($layakDipulihkan) {
                        $layakPulihkanProfile = true;
                        $alasanPemulihan = "Jatuh tempo " . ($firstDueDate ?? '-') . " belum lewat (termasuk waktu tunggu) dan sudah ada transaksi periode $periode_saat_ini";
                    }
                } else {
                    $bulanTunggak = countConsecutiveMissedMonths($conn, $IDPEL, $firstDueDate, $hari_ini, true, $anchorDay);
                    if ($bulanTunggak >= 1) {
                        $belum_bayar = true;
                        $ketWaktuTunggu = ($TIPE_BAYAR === 'prabayar' && $prabayar_grace_period > 0) ? " | Waktu tunggu: $prabayar_grace_period hari" : '';
                        $keterangan  = "Terakhir bayar: $referenceDate | Jatuh tempo: $firstDueDate | Nunggak: $bulanTunggak bulan$ketWaktuTunggu";
                    } else {
                        $statistik['sudah_bayar']++;
                        if ($layakDipulihkan) {
                            $layakPulihkanProfile = true;
                            $alasanPemulihan = "Transaksi periode $periode_saat_ini sudah berhasil";
                        }
                    }
                }
            }

            // Simpan ke bucket yang sesuai
            if ($belum_bayar) {
                $mikStatusData = getMikrotikStatus($mikrotikCache, $mikConnected, $IDPEL);
                $mikrotik_status_online = $mikStatusData['status'];
                $mikrotik_profile_saat_ini = $mikStatusData['profile'];
                $mikrotik_aksi = 'Tidak ada aksi Mikrotik';

                $belumBayarIds[] = $IDPEL;
                echo "  [BELUM BAYAR] $IDPEL | $NAMA | tipe=monthversary | secret_profile='$mikrotik_profile_saat_ini' | status='$mikrotik_status_online'\n";

                if (strtoupper(trim($mikrotik_profile_saat_ini)) !== 'EXPIRED') {
                    echo "    -> Profile belum EXPIRED ('$mikrotik_profile_saat_ini'), mengubah secret ke EXPIRED...\n";
                    $aksi = enforceExpiredProfileAndDisconnect(
                        $mikApi,
                        $mikConnected,
                        $IDPEL,
                        $NAMA,
                        $NOWA,
                        $periode_saat_ini,
                        $ODP,
                        $mikrotikCache
                    );

                    $mikrotik_aksi = $aksi['message'];
                    echo "    -> Hasil: " . ($aksi['success'] ? 'BERHASIL' : 'GAGAL') . " – {$aksi['message']}\n";
                    if ($aksi['success']) {
                        $mikrotik_profile_saat_ini = 'EXPIRED';
                        if ($mikrotik_status_online === 'ONLINE') {
                            $mikrotik_status_online = 'OFFLINE (diputus)';
                        }
                    }
                } elseif ($mikrotik_status_online === 'ONLINE' && strtoupper(trim($mikrotik_profile_saat_ini)) === 'EXPIRED') {
                    $mikrotik_aksi = 'Tidak ada aksi: sudah ONLINE dengan profile EXPIRED';
                    echo "    -> Sudah EXPIRED (ONLINE)\n";
                } elseif ($mikrotik_status_online === 'OFFLINE') {
                    $mikrotik_aksi = 'Tidak ada aksi: pelanggan OFFLINE dan profile sudah EXPIRED';
                    echo "    -> Sudah EXPIRED (OFFLINE)\n";
                } else {
                    $mikrotik_aksi = 'Tidak ada aksi: status Mikrotik tidak valid untuk eksekusi';
                    echo "    -> Status tidak valid: '$mikrotik_status_online'\n";
                }

                $entry = [
                    'status_tagihan' => 'BELUM BAYAR',
                    'IDPEL'        => $IDPEL,
                    'NAMA'         => $NAMA,
                    'NOWA'         => $NOWA,
                    'PAKET'        => $PAKET,
                    'TIPE_BAYAR'   => $TIPE_BAYAR,
                    'TIPE_TEMPO'   => $TIPE_TEMPO,
                    'pembayaran_terakhir' => $pembayaran_terakhir_display,
                    'jatuh_tempo'  => $jatuh_tempo_str,
                    'harus_bayar_paling_lambat' => $jatuh_tempo_str,
                    'mikrotik_status' => $mikrotik_status_online,
                    'mikrotik_profile' => $mikrotik_profile_saat_ini,
                    'mikrotik_aksi' => $mikrotik_aksi,
                    'keterangan'   => $keterangan,
                    'server'       => "$PEMILIK | $AREA | $BRAND",
                    'portal'       => "$URL/crm/billing/broadband/portal.php?cari=$IDPEL",
                ];
                if ($TIPE_BAYAR === 'prabayar') {
                    $statistik['prabayar_monthversary'][] = $entry;
                } else {
                    $statistik['pascabayar_monthversary'][] = $entry;
                }
                $statistik['belum_bayar_total']++;
            }

        } else {
            // ----------------------------------------------------------
            //  TIPE_TEMPO tidak dikenali – fallback: cek semua kemungkinan
            //  Prioritas: TEMPO → 30 hari dari bayar terakhir → tanggal pasang
            // ----------------------------------------------------------
            echo "  [FALLBACK] $IDPEL – TIPE_TEMPO '$TIPE_TEMPO' tidak standar, menggunakan pengecekan fallback\n";

            if (!empty($TEMPO) && $TEMPO <= $hari_ini) {
                // Ada TEMPO dan sudah lewat
                $sudahBayarSetelahTempo = false;
                if ($waktu_terakhir_bayar !== null) {
                    $tanggalBayarTerakhir = substr($waktu_terakhir_bayar, 0, 10);
                    $sudahBayarSetelahTempo = ($tanggalBayarTerakhir >= $TEMPO);
                }
                if (!$sudahBayarSetelahTempo && !$layakDipulihkan) {
                    $belum_bayar     = true;
                    $jatuh_tempo_str = $TEMPO;
                    $keterangan      = "Fallback (TIPE_TEMPO='$TIPE_TEMPO') | TEMPO habis: $TEMPO | Periode aktif: $periode_saat_ini";
                } else {
                    $statistik['sudah_bayar']++;
                    if ($layakDipulihkan) {
                        $layakPulihkanProfile = true;
                        $alasanPemulihan = "Fallback: transaksi periode $periode_saat_ini sudah berhasil";
                    }
                }
            } elseif ($waktu_terakhir_bayar !== null) {
                // Gunakan 30 hari dari bayar terakhir
                $jatuh_tempo_calc = date('Y-m-d', strtotime('+30 days', strtotime($waktu_terakhir_bayar)));
                $jatuh_tempo_str  = $jatuh_tempo_calc;
                if ($hari_ini > $jatuh_tempo_calc) {
                    $belum_bayar = true;
                    $keterangan  = "Fallback (TIPE_TEMPO='$TIPE_TEMPO') | Terakhir bayar: $waktu_terakhir_bayar | JT: $jatuh_tempo_calc";
                } else {
                    $statistik['sudah_bayar']++;
                    if ($layakDipulihkan) {
                        $layakPulihkanProfile = true;
                        $alasanPemulihan = "Fallback: bayar terakhir $waktu_terakhir_bayar masih aktif sampai $jatuh_tempo_calc";
                    }
                }
            } else {
                // Belum pernah bayar sama sekali
                if ($TANGGALPASANG <= $hari_ini) {
                    $belum_bayar     = true;
                    $jatuh_tempo_str = $TANGGALPASANG;
                    $keterangan      = "Fallback (TIPE_TEMPO='$TIPE_TEMPO') | Belum pernah bayar sejak pasang: $TANGGALPASANG";
                }
            }

            // Simpan ke bucket dan enforce Mikrotik jika belum bayar
            if ($belum_bayar) {
                $mikStatusData = getMikrotikStatus($mikrotikCache, $mikConnected, $IDPEL);
                $mikrotik_status_online = $mikStatusData['status'];
                $mikrotik_profile_saat_ini = $mikStatusData['profile'];
                $mikrotik_aksi = 'Tidak ada aksi Mikrotik';

                $belumBayarIds[] = $IDPEL;
                echo "  [BELUM BAYAR] $IDPEL | $NAMA | tipe_tempo='$TIPE_TEMPO' (fallback) | secret_profile='$mikrotik_profile_saat_ini' | status='$mikrotik_status_online'\n";

                if (strtoupper(trim($mikrotik_profile_saat_ini)) !== 'EXPIRED') {
                    echo "    -> Profile belum EXPIRED ('$mikrotik_profile_saat_ini'), mengubah secret ke EXPIRED...\n";
                    $aksi = enforceExpiredProfileAndDisconnect(
                        $mikApi,
                        $mikConnected,
                        $IDPEL,
                        $NAMA,
                        $NOWA,
                        $periode_saat_ini,
                        $ODP,
                        $mikrotikCache
                    );

                    $mikrotik_aksi = $aksi['message'];
                    echo "    -> Hasil: " . ($aksi['success'] ? 'BERHASIL' : 'GAGAL') . " – {$aksi['message']}\n";
                    if ($aksi['success']) {
                        $mikrotik_profile_saat_ini = 'EXPIRED';
                        if ($mikrotik_status_online === 'ONLINE') {
                            $mikrotik_status_online = 'OFFLINE (diputus)';
                        }
                    }
                } elseif ($mikrotik_status_online === 'ONLINE' && strtoupper(trim($mikrotik_profile_saat_ini)) === 'EXPIRED') {
                    $mikrotik_aksi = 'Tidak ada aksi: sudah ONLINE dengan profile EXPIRED';
                    echo "    -> Sudah EXPIRED (ONLINE)\n";
                } elseif ($mikrotik_status_online === 'OFFLINE') {
                    $mikrotik_aksi = 'Tidak ada aksi: pelanggan OFFLINE dan profile sudah EXPIRED';
                    echo "    -> Sudah EXPIRED (OFFLINE)\n";
                } else {
                    $mikrotik_aksi = 'Tidak ada aksi: status Mikrotik tidak valid untuk eksekusi';
                    echo "    -> Status tidak valid: '$mikrotik_status_online'\n";
                }

                $entry = [
                    'status_tagihan' => 'BELUM BAYAR',
                    'IDPEL'        => $IDPEL,
                    'NAMA'         => $NAMA,
                    'NOWA'         => $NOWA,
                    'PAKET'        => $PAKET,
                    'TIPE_BAYAR'   => $TIPE_BAYAR,
                    'TIPE_TEMPO'   => $TIPE_TEMPO,
                    'pembayaran_terakhir' => $pembayaran_terakhir_display,
                    'jatuh_tempo'  => $jatuh_tempo_str,
                    'harus_bayar_paling_lambat' => $jatuh_tempo_str,
                    'mikrotik_status' => $mikrotik_status_online,
                    'mikrotik_profile' => $mikrotik_profile_saat_ini,
                    'mikrotik_aksi' => $mikrotik_aksi,
                    'keterangan'   => $keterangan,
                    'server'       => "$PEMILIK | $AREA | $BRAND",
                    'portal'       => "$URL/crm/billing/broadband/portal.php?cari=$IDPEL",
                ];
                if ($TIPE_BAYAR === 'prabayar') {
                    $statistik['prabayar_mengikuti_tempo'][] = $entry;
                } else {
                    $statistik['pascabayar_mengikuti_tempo'][] = $entry;
                }
                $statistik['belum_bayar_total']++;
            }
        }

        // ============================================================
        //  BLOK PEMULIHAN PROFILE -- utk pelanggan yang SUDAH BAYAR
        //  ($belum_bayar false) tapi profile MikroTik-nya MASIH 'EXPIRED'
        //  (sisa dari saat mereka telat sebelumnya).
        //
        //  SENGAJA cek status Mikrotik dulu utk SEMUA pelanggan sudah-bayar
        //  (bukan cuma yang $layakPulihkanProfile true) supaya kalau memang
        //  masih EXPIRED tapi TIDAK dipulihkan, log-nya menjelaskan KENAPA --
        //  sebelumnya di sini SUNYI TOTAL kalau $layakPulihkanProfile false,
        //  jadi susah dilacak (persis kasus laporan "Sudah bayar: 94, Profile
        //  dipulihkan: 0" tanpa penjelasan apa pun di log).
        // ============================================================
        if (!$belum_bayar) {
            $mikStatusData = getMikrotikStatus($mikrotikCache, $mikConnected, $IDPEL);
            $mikrotik_status_awal = $mikStatusData['status'];
            $mikrotik_profile_awal = $mikStatusData['profile'];

            if (strtoupper(trim($mikrotik_profile_awal)) === 'EXPIRED' && !$layakPulihkanProfile) {
                // Profile masih EXPIRED tapi belum layak dipulihkan -- SATU-SATUNYA
                // alasan yang mungkin sekarang (lihat syarat $layakDipulihkan di
                // atas): pelanggan ini belum pernah tercatat bayar SAMA SEKALI
                // (waktu_terakhir_bayar kosong), cuma "tidak dianggap menunggak"
                // krn masih dlm masa tenggang/baru pasang.
                echo "  [SUDAH BAYAR TAPI BELUM DIPULIHKAN] $IDPEL | $NAMA | secret_profile='$mikrotik_profile_awal' | alasan: belum pernah ada transaksi BERHASIL tercatat, masih dalam masa tenggang\n";
            }

            if (strtoupper(trim($mikrotik_profile_awal)) === 'EXPIRED' && $layakPulihkanProfile) {
                $aksiPemulihan = restorePaidCustomerProfile(
                    $mikApi,
                    $mikConnected,
                    $IDPEL,
                    $NAMA,
                    $NOWA,
                    $targetProfilePaket,
                    $periode_saat_ini,
                    $ODP,
                    $mikrotikCache
                );

                $statistik['pelanggan_dipulihkan'][] = [
                    'status_tagihan' => 'SUDAH BAYAR',
                    'IDPEL' => $IDPEL,
                    'NAMA' => $NAMA,
                    'NOWA' => $NOWA,
                    'PAKET' => $PAKET,
                    'TIPE_BAYAR' => $TIPE_BAYAR,
                    'TIPE_TEMPO' => $TIPE_TEMPO,
                    'pembayaran_terakhir' => $pembayaran_terakhir_display,
                    'tanggal_bayar_aktivasi' => $tanggalBayarPeriodeAktif !== '' ? $tanggalBayarPeriodeAktif : '-',
                    'penggunaan_aktivasi' => $penggunaanPeriodeAktif !== '' ? $penggunaanPeriodeAktif : $periode_saat_ini,
                    'mikrotik_status_sebelum' => $mikrotik_status_awal,
                    'mikrotik_profile_sebelum' => $mikrotik_profile_awal,
                    'target_profile' => $targetProfilePaket,
                    'mikrotik_aksi' => $aksiPemulihan['message'],
                    'keterangan' => $alasanPemulihan,
                    'server' => "$PEMILIK | $AREA | $BRAND",
                    'portal' => "$URL/crm/billing/broadband/portal.php?cari=$IDPEL",
                ];

                if ($aksiPemulihan['success']) {
                    $statistik['dipulihkan_profile_total']++;
                    $infoBayar = $tanggalBayarPeriodeAktif !== '' ? $tanggalBayarPeriodeAktif : '-';
                    echo "  [AKTIFKAN] $IDPEL | $NAMA | profile dipulihkan ke '$targetProfilePaket' | Bayar: $infoBayar | Penggunaan: $penggunaanPeriodeAktif\n";
                } else {
                    echo "  [GAGAL AKTIFKAN] $IDPEL | $NAMA | {$aksiPemulihan['message']}\n";
                }
            }
        }

    } // end while pelanggan
    $stmt_pel->close();

    // === FINAL VERIFICATION PASS ===
    // Baca ulang SEMUA secret langsung dari Mikrotik untuk cek apakah ada
    // pelanggan belum bayar yang profilenya masih belum EXPIRED.
    if ($mikApi && $mikConnected && !empty($belumBayarIds)) {
        echo "\n  [VERIFIKASI AKHIR] Membaca ulang semua PPP secret dari Mikrotik...\n";

        $freshSecrets = $mikApi->comm('/ppp/secret/print', [
            '.proplist' => '.id,name,profile',
        ]);

        if (is_array($freshSecrets) && !isset($freshSecrets['!trap'])) {
            // Bangun map fresh: name => [.id, profile]
            $freshMap = [];
            foreach ($freshSecrets as $fs) {
                $fsName = trim((string)($fs['name'] ?? ''));
                if ($fsName !== '') {
                    $freshMap[strtolower($fsName)] = [
                        'id' => (string)($fs['.id'] ?? ''),
                        'profile' => trim((string)($fs['profile'] ?? '')),
                        'name' => $fsName,
                    ];
                }
            }

            echo "  [VERIFIKASI] Total secret di Mikrotik: " . count($freshMap) . " | Pelanggan belum bayar: " . count($belumBayarIds) . "\n";

            // Ambil nama profile EXPIRED yang benar dari Mikrotik
            $expiredProfileForVerify = $mikrotikCache['profiles']['expired'] ?? 'EXPIRED';

            $fixedCount = 0;
            $missedInMikrotik = 0;
            foreach ($belumBayarIds as $bbId) {
                $key = strtolower(trim($bbId));
                if (!isset($freshMap[$key])) {
                    $missedInMikrotik++;
                    echo "  [VERIFIKASI] $bbId: TIDAK DITEMUKAN di Mikrotik (secret tidak ada)\n";
                    continue;
                }

                $currentProfile = $freshMap[$key]['profile'];
                $secretId = $freshMap[$key]['id'];

                if (strtoupper($currentProfile) !== strtoupper($expiredProfileForVerify)) {
                    // MASIH BELUM EXPIRED! Paksa ubah sekarang.
                    echo "  [VERIFIKASI] $bbId: profile MASIH '$currentProfile' -> PAKSA ubah ke '$expiredProfileForVerify'...\n";

                    $forceResp = $mikApi->comm('/ppp/secret/set', [
                        '.id' => $secretId,
                        'profile' => $expiredProfileForVerify,
                    ]);

                    if (is_array($forceResp) && isset($forceResp['!trap'])) {
                        $errMsg = $forceResp['!trap'][0]['message'] ?? 'Unknown error';
                        echo "  [VERIFIKASI] $bbId: GAGAL – $errMsg\n";
                    } else {
                        // Putuskan active connection juga
                        $actResp = $mikApi->comm('/ppp/active/print', [
                            '.proplist' => '.id',
                            '?name' => $bbId,
                        ]);
                        if (is_array($actResp)) {
                            foreach ($actResp as $ar) {
                                $arId = $ar['.id'] ?? '';
                                if ($arId !== '') {
                                    $mikApi->comm('/ppp/active/remove', ['.id' => $arId]);
                                }
                            }
                        }
                        $fixedCount++;
                        echo "  [VERIFIKASI] $bbId: BERHASIL dipaksa ke '$expiredProfileForVerify'\n";
                    }
                }
            }

            if ($fixedCount > 0) {
                echo "  [VERIFIKASI] Total diperbaiki di pass akhir: $fixedCount pelanggan\n";
            } else {
                echo "  [VERIFIKASI] Semua pelanggan belum bayar sudah EXPIRED. Tidak ada yang terlewat.\n";
            }
            if ($missedInMikrotik > 0) {
                echo "  [VERIFIKASI] $missedInMikrotik pelanggan tidak ditemukan di Mikrotik (mungkin secret belum dibuat).\n";
            }
        } else {
            echo "  [VERIFIKASI] GAGAL membaca ulang secret dari Mikrotik.\n";
        }
    }

    if ($mikApi && $mikConnected) {
        $mikApi->disconnect();
    }

} // end while server

// -----------------------------------------------------------------------
// 8. Tampilkan & simpan laporan
// -----------------------------------------------------------------------


$semua_belum_bayar = array_merge(
    $statistik['prabayar_mengikuti_tanggal'],
    $statistik['pascabayar_mengikuti_tanggal'],
    $statistik['prabayar_mengikuti_tempo'],
    $statistik['pascabayar_mengikuti_tempo'],
    $statistik['prabayar_monthversary'],
    $statistik['pascabayar_monthversary']
);

// --- Detail per kategori ---
$kategori_label = [
    'prabayar_mengikuti_tanggal'   => 'PRABAYAR  + MENGIKUTI TANGGAL BAYAR',
    'pascabayar_mengikuti_tanggal' => 'PASCABAYAR + MENGIKUTI TANGGAL BAYAR',
    'prabayar_mengikuti_tempo'     => 'PRABAYAR  + MENGIKUTI TANGGAL TEMPO',
    'pascabayar_mengikuti_tempo'   => 'PASCABAYAR + MENGIKUTI TANGGAL TEMPO',
    'prabayar_monthversary'        => 'PRABAYAR  + MONTHVERSARY',
    'pascabayar_monthversary'      => 'PASCABAYAR + MONTHVERSARY',
];

foreach ($kategori_label as $key => $label) {
    $list = $statistik[$key];
    echo "\n[$label] – " . count($list) . " pelanggan\n";
    foreach ($list as $idx => $p) {
        $no = $idx + 1;
        echo "  $no. [{$p['status_tagihan']}] [{$p['IDPEL']}] {$p['NAMA']} | WA: {$p['NOWA']} | Paket: {$p['PAKET']}\n";
        echo "     Server: {$p['server']}\n";
        echo "     Pembayaran Terakhir: {$p['pembayaran_terakhir']}\n";
        echo "     Jatuh Tempo: {$p['jatuh_tempo']}\n";
        echo "     Harus Bayar Paling Lambat: {$p['harus_bayar_paling_lambat']}\n";
        echo "     Mikrotik Status: {$p['mikrotik_status']}\n";
        echo "     Mikrotik PPP Profile Saat Ini: {$p['mikrotik_profile']}\n";
        echo "     Aksi Mikrotik: {$p['mikrotik_aksi']}\n";
        echo "     Ket: {$p['keterangan']}\n";
        echo "     Portal: {$p['portal']}\n";
        echo "\n";
    }
}

echo "\n[PEMULIHAN PROFILE MIKROTIK] – " . count($statistik['pelanggan_dipulihkan']) . " pelanggan\n";
foreach ($statistik['pelanggan_dipulihkan'] as $idx => $p) {
    $no = $idx + 1;
    echo "  $no. [{$p['status_tagihan']}] [{$p['IDPEL']}] {$p['NAMA']} | WA: {$p['NOWA']} | Paket: {$p['PAKET']}\n";
    echo "     Server: {$p['server']}\n";
    echo "     Pembayaran Terakhir: {$p['pembayaran_terakhir']}\n";
    echo "     Tanggal Bayar Aktivasi: {$p['tanggal_bayar_aktivasi']}\n";
    echo "     Penggunaan Aktivasi: {$p['penggunaan_aktivasi']}\n";
    echo "     Mikrotik Status Sebelum: {$p['mikrotik_status_sebelum']}\n";
    echo "     Mikrotik Profile Sebelum: {$p['mikrotik_profile_sebelum']}\n";
    echo "     Target Profile: {$p['target_profile']}\n";
    echo "     Aksi Mikrotik: {$p['mikrotik_aksi']}\n";
    echo "     Ket: {$p['keterangan']}\n";
    echo "     Portal: {$p['portal']}\n";
    echo "\n";
}

echo "\n================================================================\n";

// -----------------------------------------------------------------------
// 9a. Ambil data transaksi dari database terlebih dahulu
// -----------------------------------------------------------------------
$periode_sql = mysqli_real_escape_string($conn, $periode_saat_ini);

// Sudah bayar - Status BERHASIL sesuai periode
$sql_sudah_bayar_trans = "SELECT COUNT(*) as total 
                          FROM transaksi t 
                          INNER JOIN pelanggan p ON t.IDPEL = p.IDPEL 
                          WHERE t.STATUS = 'BERHASIL' AND t.HARGA != '0'
                          AND t.PENGUNAAN = '$periode_sql'
                          AND p.PEMILIK IN ($userServerList) 
                          AND p.AREA IN ($userAreaList)";
$data_sudah_bayar_trans = mysqli_fetch_assoc(mysqli_query($conn, $sql_sudah_bayar_trans));
$sudah_bayar_trans = $data_sudah_bayar_trans['total'] ?? 0;

// Belum bayar dihitung dari bucket hasil filter loop agar FREE/FASUM<=0 otomatis tidak ikut.
$belum_bayar_trans = count($semua_belum_bayar);

// -----------------------------------------------------------------------
// 9b. Simpan hasil ke history (format string ringkas)
// -----------------------------------------------------------------------
$laporan_data = "[ System billing - " . date('Y-m-d H:i:s') . " ] " .
    "Periode: $periode_saat_ini | " .
    "Total: " . $statistik['total_pelanggan'] . " pelanggan | " .
    "Sudah bayar: $sudah_bayar_trans | " .
    "Belum bayar: $belum_bayar_trans | " .
    "Profile dipulihkan: " . $statistik['dipulihkan_profile_total'];

$history[] = $laporan_data;

$laporan_dir = "../data";
if (!is_dir($laporan_dir)) {
    mkdir($laporan_dir, 0755, true);
}

file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "Laporan dan history disimpan ke: $history_file\n";






echo "================================================================\n";
echo "LAPORAN CEK TAGIHAN HARIAN – $pemilik\n";
echo "Tanggal: $hari_ini\n";
echo "Periode Saat Ini: $periode_saat_ini\n";
echo "================================================================\n";

echo "Total pelanggan diperiksa : " . $statistik['total_pelanggan'] . "\n";
echo "Sudah bayar               : " . $sudah_bayar_trans . "\n";
echo "BELUM BAYAR (total)       : " . $belum_bayar_trans . "\n";
echo "Profile dipulihkan        : " . $statistik['dipulihkan_profile_total'] . "\n";
echo "----------------------------------------------------------------\n";

echo "\n=== SELESAI PROSES CEK TAGIHAN HARIAN ===\n";
echo "Selesai pada: " . date('Y-m-d H:i:s') . "\n";
