<?php
require '../cek-sesi.php';
require_once __DIR__ . '/../notifbot/notifphp/tagihan_status_lib.php';

header('Content-Type: application/json; charset=utf-8');

date_default_timezone_set('Asia/Jakarta');

function json_out($success, $message, $extra = [])
{
    echo json_encode(array_merge([
        'success' => (bool)$success,
        'message' => (string)$message,
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(false, 'Metode request tidak valid.');
}

$bulan_penggunaan = [
    'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
];

// Ambil angka tanggal (1-31) dari string TANGGALBAYAR transaksi lama, format bebas
// selama ada angka di dalamnya. Sama persis dengan proses/manual_generate_invoice.php
// supaya tanggal invoice manual per-pelanggan ini konsisten dengan hasil generate massal.
function ekstrakTanggal(string $tanggalStr): ?int
{
    if (!preg_match('/\d{1,2}/', $tanggalStr, $m)) {
        return null;
    }
    $angka = (int)$m[0];
    if ($angka < 1 || $angka > 31) {
        return null;
    }
    return $angka;
}

function bangunTanggalBayar(int $tanggal, string $namaBulan, int $tahun, array $daftarBulan): string
{
    $hari_indonesia = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    $indexBulan = array_search($namaBulan, $daftarBulan, true);
    if ($indexBulan === false) {
        return '-';
    }
    $bulanKe = $indexBulan + 1;

    $lastDay = (int)date('t', mktime(0, 0, 0, $bulanKe, 1, $tahun));
    $tanggalClamped = min($tanggal, $lastDay);

    $ts = mktime(0, 0, 0, $bulanKe, $tanggalClamped, $tahun);
    $namaHari = $hari_indonesia[(int)date('w', $ts)];

    return $namaHari . ', ' . str_pad((string)$tanggalClamped, 2, '0', STR_PAD_LEFT) . ' ' . $namaBulan . ' ' . $tahun;
}

// Bangun string TANGGALBAYAR Indonesia dari tanggal eksplisit (Y-m-d) yang dipilih
// admin lewat date-picker -- dipakai utk mode Rolling/Monthversary, yang jatuh
// temponya mengacu ke tanggal jatuh tempo pelanggan itu sendiri, bukan periode
// penggunaan (lihat bangunTanggalBayar() di atas utk mode Fixed).
function bangunTanggalBayarDariTanggal(string $tanggalYmd, array $daftarBulan): ?string
{
    $ts = strtotime($tanggalYmd);
    if ($ts === false) {
        return null;
    }
    $hari_indonesia = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    $namaHari = $hari_indonesia[(int)date('w', $ts)];
    $namaBulan = $daftarBulan[(int)date('n', $ts) - 1];
    return $namaHari . ', ' . date('d', $ts) . ' ' . $namaBulan . ' ' . date('Y', $ts);
}

$idpel = trim((string)($_POST['idpel'] ?? ''));
$pemilik = trim((string)($_POST['pemilik'] ?? ''));

if ($idpel === '' || $pemilik === '') {
    json_out(false, 'Data pelanggan tidak valid.');
}

// Pastikan pelanggan ini memang milik server user yang sedang login -- sama
// seperti validasi di save_diskon_pelanggan.php / save_biaya_tambahan_pelanggan.php.
$ownedPemilik = [];
$queryServerId = mysqli_query($conn, "SELECT PEMILIK FROM server WHERE user_id = " . (int)$current_user_id);
while ($row = mysqli_fetch_assoc($queryServerId)) {
    $ownedPemilik[] = (string)$row['PEMILIK'];
}
$ownedPemilik = array_values(array_unique(array_filter($ownedPemilik, static function ($value) {
    return $value !== '';
})));

if (!in_array($pemilik, $ownedPemilik, true) && $pemilik !== $ceknama) {
    json_out(false, 'Pelanggan tidak termasuk server Anda.');
}

$stmtPel = $conn->prepare("SELECT NAMA, PAKET, COALESCE(MODE, '') AS MODE, COALESCE(TIPE_TEMPO, '') AS TIPE_TEMPO, COALESCE(TIPE_BAYAR, '') AS TIPE_BAYAR, TANGGALPASANG, TANGGAL_MONTHVERSARY, COALESCE(TEMPO, '') AS TEMPO FROM pelanggan WHERE IDPEL = ? AND PEMILIK = ? LIMIT 1");
if (!$stmtPel) {
    json_out(false, 'Gagal menyiapkan validasi pelanggan.');
}
$stmtPel->bind_param('ss', $idpel, $pemilik);
$stmtPel->execute();
$resPel = $stmtPel->get_result();
$pelanggan = $resPel ? $resPel->fetch_assoc() : null;
$stmtPel->close();

if (!$pelanggan) {
    json_out(false, 'ID pelanggan tidak ditemukan.');
}

$nama = (string)$pelanggan['NAMA'];
$paket = (string)$pelanggan['PAKET'];
$modeBersih = strtoupper(trim((string)$pelanggan['MODE']));
$tipeTempo = strtolower(trim((string)$pelanggan['TIPE_TEMPO']));
$isRollingAtauMonthversary = in_array($tipeTempo, ['mengikuti_tanggal_bayar', 'monthversary'], true);

if (in_array($modeBersih, ['NONAKTIF', 'DISABLED', 'DISMANTLE', 'BERHENTI'], true)) {
    json_out(false, "Pelanggan berstatus $modeBersih, invoice tidak dibuat.");
}

// Fixed Due Date: periode & tanggal jatuh tempo dipilih via bulan+tahun periode,
// SAMA seperti sebelumnya. Rolling/Monthversary: jatuh tempo dihitung OTOMATIS
// oleh sistem (tagihanHitungJatuhTempoBerikutnya(), sama persis dgn yang dipakai
// tables.php utk menampilkan "Jatuh tempo berikutnya" & cron
// invoice_generator_rolling_monthversary_*.php) -- admin TIDAK perlu pilih
// tanggal manual lagi, dan periode penggunaan otomatis ikut bulan/tahun jatuh
// tempo itu sendiri (bukan "bulan berjalan" saat invoice dibuat).
if ($isRollingAtauMonthversary) {
    $monthversarySettingFile = __DIR__ . '/../notifbot/data/monthversary_setting-' . $pemilik . '.json';
    $monthversaryFollowLastPayment = false;
    if (file_exists($monthversarySettingFile)) {
        $mvCfg = json_decode(file_get_contents($monthversarySettingFile), true);
        if (is_array($mvCfg) && isset($mvCfg['follow_last_payment'])) {
            $monthversaryFollowLastPayment = !empty($mvCfg['follow_last_payment']);
        }
    }

    $lastPaymentMap = tagihanGetLastPaymentsBulk($conn, [$idpel]);
    $lastPaidUsageMap = tagihanGetLastPaidUsageMapBulk($conn, [$idpel]);
    $ctxJatuhTempo = [
        'jatuh_tempo_hari' => 25,
        'lastPaymentMap' => $lastPaymentMap,
        'lastPaidUsageMap' => $lastPaidUsageMap,
        'monthversary_follow_last_payment' => $monthversaryFollowLastPayment,
    ];
    $pelanggan['IDPEL'] = $idpel;
    $tanggalJatuhTempo = tagihanHitungJatuhTempoBerikutnya($conn, $pelanggan, $ctxJatuhTempo);
    if ($tanggalJatuhTempo === '' || strtotime($tanggalJatuhTempo) === false) {
        json_out(false, 'Tidak bisa menghitung jatuh tempo pelanggan ini (data pasang/bayar belum lengkap).');
    }
    $periodeMonth = $bulan_penggunaan[(int)date('n', strtotime($tanggalJatuhTempo)) - 1];
    $periodeYear = (int)date('Y', strtotime($tanggalJatuhTempo));
} else {
    $periodeMonth = trim((string)($_POST['periode_month'] ?? ''));
    $periodeYear = (int)($_POST['periode_year'] ?? 0);
    if (!in_array($periodeMonth, $bulan_penggunaan, true) || $periodeYear < 2000 || $periodeYear > 2100) {
        json_out(false, 'Periode invoice tidak valid.');
    }
}

// Harga paket -- sumber sama dengan proses/manual_generate_invoice.php.
$harga = reseller_effective_harga($conn, $paket, $pemilik);

if ($harga <= 0) {
    json_out(false, "Harga paket \"$paket\" tidak ditemukan atau 0, invoice tidak dibuat.");
}

$periodePenggunaan = $periodeMonth . ' ' . $periodeYear;

// Fixed Due Date: label PENGUNAAN ikut setting "Periode Tercatat" (Payment Setting
// -> Konfigurasi Fixed Due Date) -- 'berjalan' (default) = sama dgn bulan due date
// yang dipilih admin (perilaku selama ini), 'berikutnya' = +1 bulan dari situ. Due
// date ($tanggalBayar, dihitung di bawah) TETAP pakai $periodeMonth/$periodeYear apa
// adanya, tidak ikut berubah.
if (!$isRollingAtauMonthversary && $tipeTempo === 'mengikuti_tanggal_tempo') {
    $periodeTercatatReminderFile = __DIR__ . '/../notifbot/data/reminder-' . $ceknama . '.json';
    $periodeTercatatMode = tagihanLoadPeriodeTercatatMode($periodeTercatatReminderFile);
    $periodeMonthIndex = array_search($periodeMonth, $bulan_penggunaan, true);
    if ($periodeMonthIndex !== false) {
        $periodePenggunaan = tagihanResolvePeriodeTercatat($periodeMonthIndex + 1, $periodeYear, $periodeTercatatMode);
    }
}

$periodePenggunaanNorm = mb_strtoupper(trim($periodePenggunaan), 'UTF-8');

// Invoice/transaksi untuk periode ini "belum ada" hanya kalau tidak ada baris
// dengan salah satu status berikut. Kalau sudah ada (mis. sudah PENAGIHAN atau
// malah sudah BERHASIL dibayar), jangan bikin duplikat -- beda dari
// manual_generate_invoice.php (tombol massal) yang sengaja hapus+buat ulang
// entri PENAGIHAN lama; di sini tombol per-pelanggan ini harus aman diklik
// berkali-kali tanpa efek samping kalau invoice-nya memang sudah ada. Utk
// Rolling/Monthversary periode sekarang ikut bulan/tahun jatuh tempo hasil
// hitung otomatis (bukan bulan berjalan), jadi guard ini otomatis membatasi
// maksimal 1 invoice per siklus jatuh tempo utk mode tsb.
$stmtCek = $conn->prepare("SELECT TRIM(UPPER(COALESCE(STATUS, ''))) AS STATUS_NORM FROM transaksi WHERE IDPEL = ? AND PEMILIK = ? AND TRIM(UPPER(COALESCE(PENGUNAAN, ''))) = ? AND TRIM(UPPER(COALESCE(STATUS, ''))) IN ('PENAGIHAN','PERMINTAAN KODE','KONFIRMASI','BERHASIL') LIMIT 1");
if (!$stmtCek) {
    json_out(false, 'Gagal menyiapkan pengecekan invoice.');
}
$stmtCek->bind_param('sss', $idpel, $pemilik, $periodePenggunaanNorm);
$stmtCek->execute();
$resCek = $stmtCek->get_result();
$existing = $resCek ? $resCek->fetch_assoc() : null;
$stmtCek->close();

if ($existing) {
    json_out(false, "Invoice untuk periode $periodePenggunaan sudah ada (status: {$existing['STATUS_NORM']}).");
}

if ($isRollingAtauMonthversary) {
    // Tanggal jatuh tempo dibangun dari hasil hitung otomatis di atas
    // ($tanggalJatuhTempo, format Y-m-d), bukan dari input admin lagi.
    $tanggalBayar = bangunTanggalBayarDariTanggal($tanggalJatuhTempo, $bulan_penggunaan) ?? '-';
    $buktiRef = 'INV-PENAGIHAN-MANUAL-' . preg_replace('/[^A-Za-z0-9_-]/', '', $idpel) . '-' . str_replace('-', '', $tanggalJatuhTempo);
} else {
    // Fixed Due Date: angka tanggal HARUS ikut setting "jatuh_tempo" di Payment
    // Setting -> Konfigurasi Fixed Due Date (reminder-{AKUN CRM}.json, kunci
    // $ceknama -- lihat paymentset.php "$username = $ceknama"), BUKAN $pemilik
    // (PEMILIK server/router milik pelanggan ini, bisa beda string dari username
    // akun) ataupun hari dari transaksi BERHASIL terakhir -- sama seperti
    // proses/manual_generate_invoice.php, supaya tetap ada tanggal jatuh tempo
    // walau pelanggan belum pernah bayar dan tidak ikut bergeser kalau kebetulan
    // bayarnya di tanggal lain.
    $tanggalAngka = null;
    if ($tipeTempo === 'mengikuti_tanggal_tempo') {
        $jatuhTempoValue = 25;
        $reminderFile = __DIR__ . '/../notifbot/data/reminder-' . $ceknama . '.json';
        if (file_exists($reminderFile)) {
            $reminderCfg = json_decode(file_get_contents($reminderFile), true);
            if (is_array($reminderCfg) && isset($reminderCfg[0]['jatuh_tempo'])) {
                $jatuhTempoValue = (int)$reminderCfg[0]['jatuh_tempo'];
            }
        }
        $tanggalAngka = max(1, min(31, $jatuhTempoValue));
    } else {
        // Angka tanggal dari transaksi BERHASIL terakhir milik pelanggan ini, dipasang
        // ke bulan & tahun periode yang sedang dibuat. Fallback '-' kalau belum pernah
        // ada transaksi BERHASIL sama sekali.
        $stmtTgl = $conn->prepare("SELECT TANGGALBAYAR FROM transaksi WHERE IDPEL = ? AND PEMILIK = ? AND TRIM(UPPER(COALESCE(STATUS, ''))) = 'BERHASIL' ORDER BY id DESC LIMIT 1");
        if ($stmtTgl) {
            $stmtTgl->bind_param('ss', $idpel, $pemilik);
            $stmtTgl->execute();
            $resTgl = $stmtTgl->get_result();
            if ($resTgl && $rowTgl = $resTgl->fetch_assoc()) {
                $tanggalAngka = ekstrakTanggal((string)($rowTgl['TANGGALBAYAR'] ?? ''));
            }
            $stmtTgl->close();
        }
    }

    $tanggalBayar = $tanggalAngka !== null
        ? bangunTanggalBayar($tanggalAngka, $periodeMonth, $periodeYear, $bulan_penggunaan)
        : '-';
    $buktiRef = 'INV-PENAGIHAN-MANUAL-' . preg_replace('/[^A-Za-z0-9_-]/', '', $idpel) . '-' . sprintf('%04d%02d', $periodeYear, array_search($periodeMonth, $bulan_penggunaan, true) + 1);
}

$cek = 'MANUAL GENERATE INVOICE (Overview - ' . (string)($ceknama ?? 'system') . ')';

$stmtInsert = $conn->prepare("INSERT INTO transaksi (TANGGALBAYAR, PENGUNAAN, STATUS, IDPEL, NAMA, PAKET, HARGA, BUKTI, CEK, PEMILIK, METODE_BAYAR) VALUES (?, ?, 'PENAGIHAN', ?, ?, ?, ?, ?, ?, ?, '')");
if (!$stmtInsert) {
    json_out(false, 'Gagal menyiapkan query simpan invoice.');
}
$stmtInsert->bind_param('sssssdsss', $tanggalBayar, $periodePenggunaan, $idpel, $nama, $paket, $harga, $buktiRef, $cek, $pemilik);
$okInsert = $stmtInsert->execute();
$stmtInsert->close();

if (!$okInsert) {
    json_out(false, 'Gagal menyimpan invoice: ' . $conn->error);
}

json_out(true, "Invoice periode $periodePenggunaan berhasil dibuat untuk $idpel (Rp " . number_format($harga, 0, ',', '.') . ").");
