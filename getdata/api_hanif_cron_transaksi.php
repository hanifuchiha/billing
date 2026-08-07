<?php
/**
 * ============================================================================
 * CRON: Sinkron Transaksi Pelanggan (HANYA yang sudah ada di database lokal)
 * ============================================================================
 *
 * Tujuan:
 *   - Ambil data dari API pelanggan_api (sama seperti di api_hanif.php).
 *   - Untuk setiap pelanggan API yang IDPEL-nya SUDAH ADA di tabel pelanggan
 *     lokal, ambil array "transactions" milik pelanggan tsb dan simpan
 *     transaksi yang BELUM ADA ke tabel `transaksi`.
 *   - Pelanggan yang belum ada di database TIDAK dibuat baru oleh script ini
 *     (pembuatan pelanggan baru tetap lewat menu "Sinkron dari API" manual).
 *
 * ============================================================================
 * PERBAIKAN (v2): CEGAH DOBEL TRANSAKSI
 * ============================================================================
 *   Ditemukan kasus: tabel `transaksi` sudah berisi baris hasil sinkron/insert
 *   LAIN (mis. CEK = "SYNC DARI API (Pembayaran pelanggan ...)") untuk
 *   IDPEL + periode (PENGUNAAN) tertentu. Karena script versi lama hanya
 *   dedup berdasarkan CEK = "API-<transaksi_id>", baris lama itu tidak
 *   terdeteksi sebagai "sudah ada", sehingga script tetap insert baris BARU
 *   untuk periode yang sama -> DOBEL.
 *
 *   Perbaikan: sebelum insert, selain cek CEK marker, TAMBAHAN cek apakah
 *   sudah ada baris `transaksi` dengan IDPEL + PENGUNAAN (periode) yang sama,
 *   TIDAK PEDULI isi kolom CEK-nya. Kalau sudah ada -> skip, jangan insert.
 * ============================================================================
 *
 * ============================================================================
 * SKEMA TABEL `transaksi` (SESUAI phpMyAdmin, Database: Mybillingg)
 * ============================================================================
 *   id                     int, AUTO_INCREMENT, PK        -> tidak diisi manual
 *   waktu                  timestamp, default CURRENT_TS  -> tidak diisi manual (auto)
 *   TANGGALBAYAR           varchar(255) NOT NULL          -> dari transaksi_tanggal (API)
 *   PENGUNAAN              varchar(255) NULL, default '-' -> periode/penggunaan (lihat bawah)
 *   STATUS                 varchar(255) NOT NULL          -> 'BERHASIL' (ketetapan delta)
 *   IDPEL                  varchar(255) NOT NULL          -> dari data pelanggan (API)
 *   NAMA                   varchar(255) NOT NULL          -> dari data pelanggan (API)
 *   PAKET                  varchar(255) NOT NULL          -> dari data pelanggan (API)
 *   HARGA                  varchar(255) NOT NULL          -> dari transaksi_nominal (API)
 *   METODE_BAYAR           varchar(20)  NOT NULL          -> dari bank_nama (API), dipotong 20 char
 *   BUKTI                  varchar(255) NOT NULL          -> dari payment_history.bukti_url (match transaksi_id)
 *   CEK                    varchar(255) NOT NULL          -> lihat catatan CEK di bawah
 *   PEMILIK                varchar(255) NOT NULL          -> dari data pelanggan (API)
 *   MANUAL_ACTIVE_BY       varchar(255) NULL              -> dibiarkan NULL (bukan input manual admin)
 *   MANUAL_ACTIVE_SESSION  varchar(255) NULL              -> dibiarkan NULL
 *
 * CATATAN KOLOM "CEK":
 *   Fungsi asli kolom ini di aplikasi tidak diketahui pasti dari skema saja.
 *   Karena tabel `transaksi` TIDAK punya kolom transaksi_id API sebagai
 *   penanda unik, kolom CEK dipakai di script ini untuk menyimpan penanda
 *   "API-<transaksi_id>" agar dedup (cek transaksi yang sudah pernah
 *   disinkron) menjadi akurat berdasarkan ID transaksi asli dari API.
 *   !! Kalau kolom CEK ternyata sudah dipakai untuk keperluan lain di
 *      bagian sistem manapun (misal untuk pengecekan verifikasi transfer),
 *      JANGAN pakai script ini apa adanya -- beri tahu saya dulu supaya
 *      saya ganti mekanisme dedup-nya (misalnya pakai kolom baru).
 *
 * ============================================================================
 * PERIODE / PENGUNAAN
 * ============================================================================
 * API mengirim payment_history[] (punya transaksi_id + periode) dan
 * transactions[] (data transaksi finansial, juga punya transaksi_id).
 * Untuk tiap baris transactions[], dicocokkan ke payment_history
 * berdasarkan transaksi_id untuk mengambil periode-nya:
 *   - payment_history.periode TIDAK null -> dipakai apa adanya.
 *   - payment_history.periode NULL / tidak ketemu -> fallback diturunkan
 *     dari transaksi_tanggal, format "Nama Bulan Tahun" (mis. "Juni 2026").
 *     Ganti fungsi derivePeriodeFallback() di bawah kalau rule fallback
 *     yang sebenarnya berbeda.
 * ============================================================================
 */

// --- KONFIGURASI TABEL LOKAL ------------------------------------------------
const TBL_PELANGGAN            = 'pelanggan';   // nama tabel pelanggan lokal (SESUAIKAN jika beda)
const COL_PELANGGAN_IDPEL      = 'IDPEL';       // kolom IDPEL di tabel pelanggan
const COL_PELANGGAN_ID_PK      = 'id';          // primary key tabel pelanggan

const TBL_TRANSAKSI            = 'transaksi';   // nama tabel transaksi (sesuai screenshot)
// -----------------------------------------------------------------------------

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(0); // sinkron bisa lama kalau data banyak, jangan sampai timeout PHP

// Koneksi DB (harus mendefinisikan $conn = mysqli_connect(...))
require '../koneksibilling.php';

$apiUrl = "https://billing.broadbandairlink.com/web/keuangan/sistem-manajemen-keuangan/index.php/admin/site/pelanggan_api";
$apiKey = "9fa120bc7d157225c58238c91051c7f2baf37c5338d75f8c9c260082babe2de8";

function log_line(string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}

log_line('=== Mulai sinkron transaksi pelanggan (existing only) ===');

// ---------------------------------------------------------------------------
// Helper: nama bulan Indonesia (tanpa bergantung pada setlocale sistem)
// ---------------------------------------------------------------------------
function namaBulanIndo(int $bulan): string {
    $bulanList = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];
    return $bulanList[$bulan] ?? (string) $bulan;
}

// ---------------------------------------------------------------------------
// Helper: fallback periode kalau payment_history.periode NULL.
// Diturunkan dari transaksi_tanggal -> "Nama Bulan Tahun".
// GANTI ISI FUNGSI INI kalau rule awal yang sebenarnya berbeda.
// ---------------------------------------------------------------------------
function derivePeriodeFallback(?string $tanggal): ?string {
    if (!$tanggal) {
        return null;
    }
    $ts = strtotime($tanggal);
    if ($ts === false) {
        return null;
    }
    return namaBulanIndo((int) date('n', $ts)) . ' ' . date('Y', $ts);
}

// ---------------------------------------------------------------------------
// Helper: bangun map transaksi_id -> ['periode' => ..., 'bukti_url' => ...]
// dari payment_history milik satu pelanggan, supaya bisa dicocokkan ke
// transactions[] pelanggan yang sama.
// ---------------------------------------------------------------------------
function buildPaymentHistoryMap(array $paymentHistory): array {
    $map = [];
    foreach ($paymentHistory as $ph) {
        if (!isset($ph['transaksi_id'])) {
            continue;
        }
        $map[(string) $ph['transaksi_id']] = [
            'periode'    => $ph['periode'] ?? null,
            'bukti_url'  => $ph['bukti_url'] ?? '',
        ];
    }
    return $map;
}

// ---------------------------------------------------------------------------
// Helper: tentukan periode final untuk satu baris transaksi.
// ---------------------------------------------------------------------------
function resolvePeriode(array $t, array $paymentHistoryMap): ?string {
    $txId = isset($t['transaksi_id']) ? (string) $t['transaksi_id'] : null;

    if ($txId !== null && isset($paymentHistoryMap[$txId]['periode'])
        && $paymentHistoryMap[$txId]['periode'] !== null
        && $paymentHistoryMap[$txId]['periode'] !== '') {
        // payment_history.periode TIDAK null -> pakai apa adanya
        return $paymentHistoryMap[$txId]['periode'];
    }

    // payment_history.periode NULL / tidak ketemu -> fallback dari tanggal transaksi
    return derivePeriodeFallback($t['transaksi_tanggal'] ?? null);
}

// ---------------------------------------------------------------------------
// Helper: ambil bukti_url yang cocok dari payment_history berdasarkan
// transaksi_id (kalau ada). Kalau tidak ketemu, kembalikan string kosong.
// ---------------------------------------------------------------------------
function resolveBuktiUrl(array $t, array $paymentHistoryMap): string {
    $txId = isset($t['transaksi_id']) ? (string) $t['transaksi_id'] : null;
    if ($txId !== null && isset($paymentHistoryMap[$txId]['bukti_url'])) {
        return (string) $paymentHistoryMap[$txId]['bukti_url'];
    }
    return '';
}

/**
 * Mengubah nilai apapun jadi float yang aman (menghindari warning tipe data).
 */
function safeNumber($val): float {
    if (is_numeric($val)) {
        return (float) $val;
    }
    if (is_string($val)) {
        $clean = preg_replace('/[^0-9.,-]/', '', $val);
        $clean = str_replace(['.', ','], ['', '.'], $clean);
        if (is_numeric($clean)) {
            return (float) $clean;
        }
    }
    return 0.0;
}

// ---------------------------------------------------------------------------
// 1) Ambil data dari API
// ---------------------------------------------------------------------------
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $apiUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 120,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_HTTPHEADER => [
        "X-API-Key: {$apiKey}",
        "Accept: application/json"
    ]
]);
$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($curlError) {
    log_line('CURL ERROR: ' . $curlError);
    exit(1);
}
if ($httpCode != 200) {
    log_line('HTTP CODE bukan 200: ' . $httpCode);
    exit(1);
}

$data = json_decode($response, true);
if (!$data) {
    log_line('JSON ERROR, gagal decode response API');
    exit(1);
}

$customers = [];
if (isset($data['customers']) && is_array($data['customers'])) {
    $customers = $data['customers'];
} elseif (isset($data['rows']) && is_array($data['rows'])) {
    $customers = $data['rows'];
}

log_line('Total pelanggan dari API: ' . count($customers));

// ---------------------------------------------------------------------------
// 2) Ambil daftar IDPEL yang SUDAH ADA di database lokal
// ---------------------------------------------------------------------------
$existingIdpelSet = []; // ['IDPEL123' => true, ...]

$q = mysqli_query($conn, "SELECT `" . COL_PELANGGAN_IDPEL . "` FROM `" . TBL_PELANGGAN . "`");
if (!$q) {
    log_line('Gagal query tabel pelanggan lokal: ' . mysqli_error($conn));
    exit(1);
}
while ($r = mysqli_fetch_assoc($q)) {
    $existingIdpelSet[$r[COL_PELANGGAN_IDPEL]] = true;
}

log_line('Total pelanggan sudah ada di database lokal: ' . count($existingIdpelSet));

// ---------------------------------------------------------------------------
// 3) Dedup
// ---------------------------------------------------------------------------
// 3a) Dedup utama: cek apakah transaksi API ini sudah pernah disimpan OLEH
//     SCRIPT INI, ditandai lewat CEK = "API-<transaksi_id>".
function transaksiSudahAda($conn, string $cekMarker): bool {
    $stmt = mysqli_prepare(
        $conn,
        "SELECT 1 FROM `" . TBL_TRANSAKSI . "` WHERE `CEK` = ? LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, 's', $cekMarker);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    $found = mysqli_stmt_num_rows($stmt) > 0;
    mysqli_stmt_close($stmt);
    return $found;
}

// 3b) Dedup TAMBAHAN (PENTING - mencegah dobel):
//     Cek apakah SUDAH ADA baris transaksi untuk IDPEL + PENGUNAAN (periode)
//     yang sama, TIDAK PEDULI format/isi kolom CEK-nya. Ini menangkap kasus
//     baris lama yang dibuat lewat mekanisme sinkron/insert LAIN (mis. CEK
//     berformat "SYNC DARI API (...)") yang tidak akan pernah cocok dengan
//     marker "API-<transaksi_id>" di atas.
//
//     Kalau ternyata satu IDPEL+periode memang boleh punya lebih dari satu
//     transaksi yang sah (mis. bayar 2x di periode sama), beri tahu saya
//     supaya cek ini diperlonggar (mis. tambahkan syarat HARGA juga).
function transaksiPeriodeSudahAda($conn, string $idpel, ?string $periode): bool {
    if ($periode === null || $periode === '') {
        return false; // tidak ada periode yang bisa dibandingkan, jangan blokir insert
    }
    $stmt = mysqli_prepare(
        $conn,
        "SELECT 1 FROM `" . TBL_TRANSAKSI . "` WHERE `IDPEL` = ? AND `PENGUNAAN` = ? LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, 'ss', $idpel, $periode);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    $found = mysqli_stmt_num_rows($stmt) > 0;
    mysqli_stmt_close($stmt);
    return $found;
}

function insertTransaksi(
    $conn,
    array $c,
    array $t,
    ?string $periode,
    string $buktiUrl,
    string $cekMarker
): bool {
    $tanggalBayar = $t['transaksi_tanggal'] ?? date('Y-m-d');
    $pengunaan    = $periode ?? '-';
    $status       = 'BERHASIL'; // ketetapan delta untuk transaksi hasil sinkron API
    $idpel        = $c['IDPEL'] ?? '';
    $nama         = $c['NAMA'] ?? '';
    $paket        = $c['PAKET'] ?? '';
    $harga        = (string) safeNumber($t['transaksi_nominal'] ?? ($c['HARGA'] ?? 0));
    $metodeBayar  = mb_substr((string) ($t['bank_nama'] ?? 'payment gateway'), 0, 20);
    $bukti        = $buktiUrl;
    $cek          = $cekMarker;
    $pemilik      = $c['PEMILIK'] ?? '';

    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO `" . TBL_TRANSAKSI . "`
            (`TANGGALBAYAR`, `PENGUNAAN`, `STATUS`, `IDPEL`, `NAMA`, `PAKET`,
             `HARGA`, `METODE_BAYAR`, `BUKTI`, `CEK`, `PEMILIK`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    mysqli_stmt_bind_param(
        $stmt,
        'sssssssssss',
        $tanggalBayar, $pengunaan, $status, $idpel, $nama, $paket,
        $harga, $metodeBayar, $bukti, $cek, $pemilik
    );
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}

// ---------------------------------------------------------------------------
// 4) Loop pelanggan API -> proses hanya yang sudah ada di DB lokal
// ---------------------------------------------------------------------------
$totalPelangganDiproses = 0;
$totalTransaksiBaru = 0;
$totalTransaksiDilewatiCek = 0;      // dilewati karena marker CEK sudah ada
$totalTransaksiDilewatiPeriode = 0;  // dilewati karena IDPEL+periode sudah ada (baris lama)
$totalError = 0;

foreach ($customers as $c) {
    $idpel = $c['IDPEL'] ?? null;
    if (!$idpel) {
        continue;
    }

    // Hanya proses kalau pelanggan ini SUDAH ADA di database lokal
    if (!isset($existingIdpelSet[$idpel])) {
        continue;
    }

    $totalPelangganDiproses++;

    $transaksiList = $c['transactions'] ?? [];
    if (!is_array($transaksiList) || empty($transaksiList)) {
        continue;
    }

    $paymentHistory = $c['payment_history'] ?? [];
    if (!is_array($paymentHistory)) {
        $paymentHistory = [];
    }
    $paymentHistoryMap = buildPaymentHistoryMap($paymentHistory);

    foreach ($transaksiList as $t) {
        $txApiId = $t['transaksi_id'] ?? null;
        if (!$txApiId) {
            continue; // tidak ada ID unik dari API, lewati demi keamanan dedup
        }

        $cekMarker = 'API-' . $txApiId;

        // Cek 1: sudah pernah disinkron oleh script ini sebelumnya?
        if (transaksiSudahAda($conn, $cekMarker)) {
            $totalTransaksiDilewatiCek++;
            continue;
        }

        $periode  = resolvePeriode($t, $paymentHistoryMap);
        $buktiUrl = resolveBuktiUrl($t, $paymentHistoryMap);

        // Cek 2 (TAMBAHAN): sudah ada baris transaksi untuk IDPEL+periode ini,
        // walau lewat mekanisme lain (CEK format berbeda)? Kalau ya, JANGAN
        // insert lagi supaya tidak dobel.
        if (transaksiPeriodeSudahAda($conn, $idpel, $periode)) {
            $totalTransaksiDilewatiPeriode++;
            log_line("Lewati (sudah ada via sistem lain) IDPEL {$idpel} periode " . ($periode ?? '-'));
            continue;
        }

        if (insertTransaksi($conn, $c, $t, $periode, $buktiUrl, $cekMarker)) {
            $totalTransaksiBaru++;
        } else {
            $totalError++;
            log_line("Gagal insert transaksi (API ID {$txApiId}) untuk IDPEL {$idpel}: " . mysqli_error($conn));
        }
    }
}

log_line("Pelanggan diproses (sudah ada di DB)          : {$totalPelangganDiproses}");
log_line("Transaksi baru ditambahkan                     : {$totalTransaksiBaru}");
log_line("Transaksi dilewati (marker CEK sudah ada)      : {$totalTransaksiDilewatiCek}");
log_line("Transaksi dilewati (IDPEL+periode sudah ada)   : {$totalTransaksiDilewatiPeriode}");
log_line("Error insert                                    : {$totalError}");
log_line('=== Selesai sinkron transaksi pelanggan ===');