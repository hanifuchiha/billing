<?php
include '../../koneksidb.php';
require_once __DIR__ . '/../../reseller_helper.php';
require_once __DIR__ . '/tagihan_status_lib.php';

date_default_timezone_set('Asia/Jakarta');

function formatTanggalIndo(string $ymd): string
{
    $hariMap = [
        'Sunday' => 'Minggu',
        'Monday' => 'Senin',
        'Tuesday' => 'Selasa',
        'Wednesday' => 'Rabu',
        'Thursday' => 'Kamis',
        'Friday' => 'Jumat',
        'Saturday' => 'Sabtu',
    ];

    $bulanMap = [
        1 => 'Januari',
        'Februari',
        'Maret',
        'April',
        'Mei',
        'Juni',
        'Juli',
        'Agustus',
        'September',
        'Oktober',
        'November',
        'Desember',
    ];

    $dt = DateTime::createFromFormat('Y-m-d', $ymd);
    if (!$dt) {
        return $ymd;
    }

    $hariEn = $dt->format('l');
    $hari = $hariMap[$hariEn] ?? $hariEn;
    $tanggal = $dt->format('d');
    $bulan = $bulanMap[(int)$dt->format('n')] ?? $dt->format('m');
    $tahun = $dt->format('Y');

    return "{$hari}, {$tanggal} {$bulan} {$tahun}";
}

// Mengembalikan nama bulan + tahun Indonesia untuk N bulan ke depan dari bulan ini.
// $offset = 1 -> bulan depan, $offset = 2 -> 2 bulan depan, dst.
function periodeBulanKe(int $offset): string
{
    $bulan = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
    ];

    $ts = strtotime(date('Y-m-01') . " +{$offset} month");
    $m = (int)date('n', $ts);
    $y = (int)date('Y', $ts);

    return $bulan[$m] . ' ' . $y;
}

// Bangun ulang string TANGGALBAYAR Indonesia ("Senin, 15 Agustus 2026") dari angka tanggal
// digabung dengan nama bulan + tahun periode penagihan. Tanggal disesuaikan (clamp) kalau
// melebihi jumlah hari di bulan tersebut (misal tanggal 31 di bulan Februari).
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

if (!isset($conn) || !$conn || $conn->connect_error) {
    exit("Koneksi database gagal\n");
}

$filename = basename(__FILE__);
$nameOnly = pathinfo($filename, PATHINFO_FILENAME);
$parts = explode('_', $nameOnly);
$pemilik = end($parts);

if ($pemilik === 'penagihan') {
    exit("File template, bukan file user-specific\n");
}

// File ini KHUSUS pelanggan Fixed Due Date (TIPE_TEMPO = mengikuti_tanggal_tempo).
// Pelanggan Monthversary & Rolling (mengikuti_tanggal_bayar) punya cron generate
// invoice sendiri, terpisah dari file ini -- jadi kedua mode itu SENGAJA dilewati
// di loop bawah, bukan diproses di sini.
$configPath = __DIR__ . '/../data/invoice_generator-' . $pemilik . '.json';
$generatorEnabled = false;
$scheduleMode = 'monthly_range';
$startDay = 25;

if (file_exists($configPath)) {
    $cfg = json_decode(file_get_contents($configPath), true);
    if (is_array($cfg)) {
        $generatorEnabled = !empty($cfg['enabled']);
        $scheduleMode = (($cfg['schedule'] ?? 'monthly_range') === 'daily') ? 'daily' : 'monthly_range';
        $startDay = isset($cfg['start_day']) ? (int)$cfg['start_day'] : 25;
    }
}

$startDay = max(1, min(31, $startDay));

// $startDay ("Mulai Tanggal" di section Invoice Generator) HANYA menentukan jadwal
// KAPAN cron ini mulai jalan tiap bulan -- BUKAN tanggal jatuh tempo invoice. Tanggal
// jatuh tempo aktual pakai $jatuhTempoHari, dari setting "jatuh_tempo" di Payment
// Setting -> Konfigurasi Fixed Due Date (reminder-{PEMILIK}.json), supaya due date
// yang tertera di invoice selalu sesuai dengan yang dikonfigurasi admin di sana.
$reminderConfigPath = __DIR__ . '/../data/reminder-' . $pemilik . '.json';
$jatuhTempoHari = 25;
if (file_exists($reminderConfigPath)) {
    $reminderCfg = json_decode(file_get_contents($reminderConfigPath), true);
    if (is_array($reminderCfg) && isset($reminderCfg[0]['jatuh_tempo'])) {
        $jatuhTempoHari = (int)$reminderCfg[0]['jatuh_tempo'];
    }
}
$jatuhTempoHari = max(1, min(31, $jatuhTempoHari));

if (!$generatorEnabled) {
    exit("Generator nonaktif\n");
}

$todayDay = (int)date('j');
$lastDay = (int)date('t');
$effectiveStartDay = min($startDay, $lastDay);

// Gate rentang tanggal generate (start_day/scheduleMode) -- HANYA relevan utk
// pelanggan Fixed Due Date, satu-satunya mode yang diproses file ini.
$fixedDueDateInRange = true;
if ($scheduleMode !== 'daily' && ($todayDay < $effectiveStartDay || $todayDay > $lastDay)) {
    $fixedDueDateInRange = false;
}

// Daftar nama bulan Indonesia, dipakai juga oleh bangunTanggalBayar() untuk lookup index bulan.
$bulanIndo = [
    'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
];

// Fixed Due Date: cuma 1 periode ke depan (bulan depan) yang digenerate tiap
// run, BUKAN 2 bulan sekaligus -- supaya admin masih bisa manual generate
// periode lain sendiri lewat menu Transaksi bila perlu.
//
// Label PENGUNAAN-nya ikut setting "Periode Tercatat" (Payment Setting ->
// Konfigurasi Fixed Due Date, reminder-{PEMILIK}.json) via tagihanResolvePeriodeTercatat():
// 'berjalan' (default) = periode sama dgn bulan jatuh tempo, 'berikutnya' = +1 bulan
// dari bulan jatuh tempo. Tanggal jatuh tempo (TANGGALBAYAR) sendiri TIDAK berubah --
// tetap dihitung dari bulan depan ($offset 1) seperti sebelumnya.
$periodeTercatatMode = tagihanLoadPeriodeTercatatMode($reminderConfigPath);
$dueMonthTs = strtotime('+1 month', strtotime(date('Y-m-01')));
$periodeTargets = [
    1 => tagihanResolvePeriodeTercatat((int)date('n', $dueMonthTs), (int)date('Y', $dueMonthTs), $periodeTercatatMode),
];

$userStmt = $conn->prepare("SELECT id FROM user WHERE USERNAME = ? LIMIT 1");
$userStmt->bind_param('s', $pemilik);
$userStmt->execute();
$userStmt->bind_result($idUser);
if (!$userStmt->fetch()) {
    $userStmt->close();
    exit("User tidak ditemukan\n");
}
$userStmt->close();

// Cron ini jalan tanpa sesi login, jadi $is_reseller/$reseller_price_filter_enabled/$reseller_id
// (dipakai oleh reseller_effective_harga()) harus diisi manual dari akun $idUser di sini,
// sama seperti yang dilakukan cek-sesi.php untuk request interaktif.
reseller_bootstrap_schema($conn);
$is_reseller = false;
$reseller_id = null;
$reseller_price_filter_enabled = false;

$roleStmt = $conn->prepare("SELECT assistant_role FROM user WHERE id = ? LIMIT 1");
$roleStmt->bind_param('i', $idUser);
$roleStmt->execute();
$roleStmt->bind_result($assistantRole);
if ($roleStmt->fetch()) {
    $is_reseller = in_array($assistantRole, ['reseller', 'mitra_isp'], true);
}
$roleStmt->close();

if ($is_reseller) {
    $reseller_id = $idUser;
    $reseller_settings = reseller_get_settings($conn, $idUser);
    $reseller_price_filter_enabled = (bool)$reseller_settings['price_filter_enabled'];
}

$serverStmt = $conn->prepare("SELECT PEMILIK, AREA FROM server WHERE user_id = ?");
$serverStmt->bind_param('i', $idUser);
$serverStmt->execute();
$serverRes = $serverStmt->get_result();

$totalInserted = 0;
$totalSkipped = 0;
$totalRegenerated = 0;
$ringkasanPerPeriode = [];

while ($server = $serverRes->fetch_assoc()) {
    $serverPemilik = $server['PEMILIK'];
    $serverArea = $server['AREA'];

    // Bersihkan transaksi PENAGIHAN dengan harga tidak valid (<=0), sekali per server.
    $cleanupAllStmt = $conn->prepare("DELETE FROM transaksi WHERE PEMILIK = ? AND TRIM(UPPER(COALESCE(STATUS, ''))) = 'PENAGIHAN' AND CAST(COALESCE(NULLIF(HARGA, ''), '0') AS DECIMAL(18,2)) <= 0");
    $cleanupAllStmt->bind_param('s', $serverPemilik);
    $cleanupAllStmt->execute();
    $cleanupAllStmt->close();

    $pelStmt = $conn->prepare("SELECT IDPEL, NAMA, PAKET, COALESCE(MODE,'') AS MODE, TIPE_TEMPO, TIPE_BAYAR, TANGGALPASANG, TANGGAL_MONTHVERSARY FROM pelanggan WHERE PEMILIK = ? AND AREA = ? AND IDPEL <> ''");
    $pelStmt->bind_param('ss', $serverPemilik, $serverArea);
    $pelStmt->execute();
    $pelRes = $pelStmt->get_result();

    while ($pel = $pelRes->fetch_assoc()) {
        $idpel = $pel['IDPEL'];
        $nama = $pel['NAMA'];
        $paket = $pel['PAKET'];
        $mode = strtoupper(trim((string)$pel['MODE']));
        $tipeTempo = strtolower(trim((string)($pel['TIPE_TEMPO'] ?? '')));

        if (in_array($mode, ['NONAKTIF', 'DISABLED', 'DISMANTLE', 'BERHENTI'], true)) {
            $totalSkipped += count($periodeTargets);
            continue;
        }

        // Ambil harga paket sekali saja per pelanggan (dipakai untuk kedua periode).
        // reseller_effective_harga() otomatis menimpa dengan custom_harga bila akun
        // ini reseller/mitra dengan filter harga aktif (lihat reseller_helper.php).
        $harga = reseller_effective_harga($conn, $paket, $serverPemilik);

        if ($harga <= 0) {
            // Tidak valid untuk kedua periode.
            $totalSkipped += count($periodeTargets);
            continue;
        }

        // Mode "monthversary" & "mengikuti_tanggal_bayar" (Rolling) SENGAJA dilewati
        // di sini -- file ini khusus Fixed Due Date, generate invoice utk kedua mode
        // itu ditangani cron terpisah, bukan di sini.
        if ($tipeTempo === 'monthversary' || $tipeTempo === 'mengikuti_tanggal_bayar') {
            $totalSkipped += count($periodeTargets);
            continue;
        }

        // Dari sini ke bawah HANYA utk pelanggan Fixed Due Date (mengikuti_tanggal_tempo)
        // -- jadwal kalender global (start_day/scheduleMode), 1 periode (bulan depan) saja.
        if (!$fixedDueDateInRange) {
            $totalSkipped += count($periodeTargets);
            continue;
        }

        // Proses periode target (cuma 1: bulan depan).
        foreach ($periodeTargets as $offset => $periode) {
            $periodeNormalized = mb_strtoupper(trim($periode), 'UTF-8');
            $isRegenerated = false;

            $checkStmt = $conn->prepare("SELECT id, TRIM(UPPER(COALESCE(STATUS, ''))) AS STATUS_NORM FROM transaksi WHERE IDPEL = ? AND PEMILIK = ? AND TRIM(UPPER(COALESCE(PENGUNAAN, ''))) = ? AND TRIM(UPPER(COALESCE(STATUS, ''))) IN ('PENAGIHAN','PERMINTAAN KODE','KONFIRMASI','BERHASIL') LIMIT 1");
            $checkStmt->bind_param('sss', $idpel, $serverPemilik, $periodeNormalized);
            $checkStmt->execute();
            $checkStmt->bind_result($existingId, $existingStatusNorm);
            $found = $checkStmt->fetch();
            $checkStmt->close();

            if ($found) {
                if ($existingStatusNorm === 'PENAGIHAN') {
                    // Sudah ada penagihan (belum bayar) untuk periode ini: hapus dulu, buat ulang di bawah.
                    $delStmt = $conn->prepare("DELETE FROM transaksi WHERE id = ?");
                    $delStmt->bind_param('i', $existingId);
                    $delStmt->execute();
                    $delStmt->close();
                    $isRegenerated = true;
                } else {
                    // Status lain (BERHASIL/KONFIRMASI/PERMINTAAN KODE): jangan disentuh, skip.
                    $totalSkipped++;
                    $ringkasanPerPeriode[$periode]['skipped'] = ($ringkasanPerPeriode[$periode]['skipped'] ?? 0) + 1;
                    continue;
                }
            }

            // Bangun TANGGALBAYAR untuk periode ini: angka tanggal HARUS ikut $jatuhTempoHari
            // (setting "jatuh_tempo" dari Payment Setting -> "Konfigurasi Fixed Due Date"),
            // BUKAN $startDay (itu cuma jadwal kapan cron ini mulai jalan) ataupun hari dari
            // transaksi BERHASIL terakhir -- Fixed Due Date artinya SATU tanggal jatuh tempo
            // yang sama utk semua pelanggan mode ini, jadi tidak boleh ikut bergeser mengikuti
            // kapan pelanggan kebetulan bayar (itu perilaku Rolling, bukan Fixed).
            $tanggalTagih = bangunTanggalBayar($jatuhTempoHari, $bulanIndo[(int)date('n', strtotime("+{$offset} month")) - 1], (int)date('Y', strtotime("+{$offset} month")), $bulanIndo);

            $buktiRef = 'INV-PENAGIHAN-' . preg_replace('/[^A-Za-z0-9_-]/', '', $idpel) . '-' . date('Ym', strtotime("+{$offset} month"));
            $cek = 'AUTO PENAGIHAN';
            $status = 'PENAGIHAN';

            $insStmt = $conn->prepare("INSERT INTO transaksi (TANGGALBAYAR, PENGUNAAN, STATUS, IDPEL, NAMA, PAKET, HARGA, BUKTI, CEK, PEMILIK, METODE_BAYAR) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '')");
            $insStmt->bind_param('ssssssisss', $tanggalTagih, $periode, $status, $idpel, $nama, $paket, $harga, $buktiRef, $cek, $serverPemilik);

            if ($insStmt->execute()) {
                $totalInserted++;
                $ringkasanPerPeriode[$periode]['inserted'] = ($ringkasanPerPeriode[$periode]['inserted'] ?? 0) + 1;
                if ($isRegenerated) {
                    $totalRegenerated++;
                    $ringkasanPerPeriode[$periode]['regenerated'] = ($ringkasanPerPeriode[$periode]['regenerated'] ?? 0) + 1;
                }
            } else {
                $totalSkipped++;
                $ringkasanPerPeriode[$periode]['skipped'] = ($ringkasanPerPeriode[$periode]['skipped'] ?? 0) + 1;
            }
            $insStmt->close();
        }
    }

    $pelStmt->close();
}

$serverStmt->close();

echo "Selesai. Total Inserted: {$totalInserted}, Total Regenerated: {$totalRegenerated}, Total Skipped: {$totalSkipped}\n";
foreach ($ringkasanPerPeriode as $periode => $rek) {
    $ins = $rek['inserted'] ?? 0;
    $rgn = $rek['regenerated'] ?? 0;
    $skp = $rek['skipped'] ?? 0;
    echo "  - {$periode}: Inserted {$ins} (regenerated {$rgn}), Skipped {$skp}\n";
}
