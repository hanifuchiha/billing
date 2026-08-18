<?php
include '../../koneksidb.php';
require_once __DIR__ . '/../../reseller_helper.php';
require_once __DIR__ . '/tagihan_status_lib.php';

date_default_timezone_set('Asia/Jakarta');

function formatTanggalIndoRM(string $ymd): string
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

if (!isset($conn) || !$conn || $conn->connect_error) {
    exit("Koneksi database gagal\n");
}

$filename = basename(__FILE__);
$nameOnly = pathinfo($filename, PATHINFO_FILENAME);
$parts = explode('_', $nameOnly);
$pemilik = end($parts);

if ($pemilik === 'monthversary') {
    exit("File template, bukan file user-specific\n");
}

// File ini KHUSUS pelanggan Rolling (mengikuti_tanggal_bayar) & Monthversary --
// pelanggan Fixed Due Date generate invoice-nya lewat invoice_generator_penagihan_*.php,
// terpisah dari file ini. Beda dari Fixed: TIDAK ada jadwal kalender global
// (start_day/scheduleMode) -- invoice tiap pelanggan digenerate N hari sebelum
// tanggal jatuh tempo MASING-MASING pelanggan (setting "days_before_due"), jadi
// cron ini harus jalan tiap hari, terlepas dari Mode Jadwal/Mulai Tanggal punya
// Fixed Due Date (lihat komentar di Payment Setting -> Invoice Generator).
$configPath = __DIR__ . '/../data/invoice_generator-' . $pemilik . '.json';
$generatorEnabled = false;
$daysBeforeDue = 2;

if (file_exists($configPath)) {
    $cfg = json_decode(file_get_contents($configPath), true);
    if (is_array($cfg)) {
        $generatorEnabled = !empty($cfg['enabled']);
        $daysBeforeDue = isset($cfg['days_before_due']) ? (int)$cfg['days_before_due'] : 2;
    }
}
$daysBeforeDue = max(0, min(30, $daysBeforeDue));

if (!$generatorEnabled) {
    exit("Generator nonaktif\n");
}

// Setting "Monthversary ikut tanggal bayar terakhir" (Payment Setting -> Monthversary),
// dipakai persis sama seperti tables.php supaya "jatuh tempo berikutnya" yang dipakai
// utk generate di sini SAMA dengan yang ditampilkan ke admin.
$monthversaryConfigPath = __DIR__ . '/../data/monthversary_setting-' . $pemilik . '.json';
$monthversaryFollowLastPayment = false;
if (file_exists($monthversaryConfigPath)) {
    $mvCfg = json_decode(file_get_contents($monthversaryConfigPath), true);
    if (is_array($mvCfg) && isset($mvCfg['follow_last_payment'])) {
        $monthversaryFollowLastPayment = !empty($mvCfg['follow_last_payment']);
    }
}

$bulanIndo = [
    'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
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
$totalTooEarly = 0;
$ringkasanPerPeriode = [];

while ($server = $serverRes->fetch_assoc()) {
    $serverPemilik = $server['PEMILIK'];
    $serverArea = $server['AREA'];

    // Bersihkan transaksi PENAGIHAN dengan harga tidak valid (<=0), sekali per server.
    $cleanupAllStmt = $conn->prepare("DELETE FROM transaksi WHERE PEMILIK = ? AND TRIM(UPPER(COALESCE(STATUS, ''))) = 'PENAGIHAN' AND CAST(COALESCE(NULLIF(HARGA, ''), '0') AS DECIMAL(18,2)) <= 0");
    $cleanupAllStmt->bind_param('s', $serverPemilik);
    $cleanupAllStmt->execute();
    $cleanupAllStmt->close();

    // Cuma pelanggan Rolling & Monthversary -- Fixed Due Date ditangani file terpisah.
    $pelStmt = $conn->prepare("SELECT IDPEL, NAMA, PAKET, COALESCE(MODE,'') AS MODE, TIPE_TEMPO, COALESCE(TIPE_BAYAR,'') AS TIPE_BAYAR, TANGGALPASANG, TANGGAL_MONTHVERSARY, COALESCE(TEMPO,'') AS TEMPO FROM pelanggan WHERE PEMILIK = ? AND AREA = ? AND IDPEL <> '' AND LOWER(TRIM(COALESCE(TIPE_TEMPO,''))) IN ('mengikuti_tanggal_bayar','monthversary')");
    $pelStmt->bind_param('ss', $serverPemilik, $serverArea);
    $pelStmt->execute();
    $pelRes = $pelStmt->get_result();

    $pelRows = [];
    $idpelList = [];
    while ($pel = $pelRes->fetch_assoc()) {
        $pelRows[] = $pel;
        $idpelList[] = $pel['IDPEL'];
    }
    $pelStmt->close();

    if (empty($pelRows)) {
        continue;
    }

    // Bulk, sama seperti tables.php -- supaya "jatuh tempo berikutnya" yang dihitung
    // di sini SAMA PERSIS dengan yang ditampilkan ke admin di Overview Customer.
    $lastPaymentMap = tagihanGetLastPaymentsBulk($conn, $idpelList);
    $lastPaidUsageMap = tagihanGetLastPaidUsageMapBulk($conn, $idpelList);

    $today = date('Y-m-d');

    foreach ($pelRows as $pel) {
        $idpel = $pel['IDPEL'];
        $nama = $pel['NAMA'];
        $paket = $pel['PAKET'];
        $mode = strtoupper(trim((string)$pel['MODE']));

        if (in_array($mode, ['NONAKTIF', 'DISABLED', 'DISMANTLE', 'BERHENTI'], true)) {
            $totalSkipped++;
            continue;
        }

        // Ambil harga paket. reseller_effective_harga() otomatis menimpa dengan
        // custom_harga bila akun ini reseller/mitra dengan filter harga aktif.
        $harga = reseller_effective_harga($conn, $paket, $serverPemilik);

        if ($harga <= 0) {
            $totalSkipped++;
            continue;
        }

        // Jatuh tempo berikutnya pelanggan ini, SAMA PERSIS dengan yang dipakai
        // tables.php utk menampilkan "Jatuh tempo berikutnya" -- supaya generate
        // invoice tidak pernah berbeda dari apa yang admin lihat di Overview Customer.
        $ctx = [
            'jatuh_tempo_hari' => 25,
            'lastPaymentMap' => $lastPaymentMap,
            'lastPaidUsageMap' => $lastPaidUsageMap,
            'monthversary_follow_last_payment' => $monthversaryFollowLastPayment,
        ];
        $dueDate = tagihanHitungJatuhTempoBerikutnya($conn, $pel, $ctx);

        if ($dueDate === '' || strtotime($dueDate) === false) {
            $totalSkipped++;
            continue;
        }

        // Terbit H- sebelum jatuh tempo: cuma generate kalau sisa hari ke jatuh
        // tempo <= setting days_before_due (termasuk kalau sudah lewat/negatif --
        // tetap harus ditagih meski telat, bukan dilewati selamanya).
        $daysUntilDue = (int) floor((strtotime($dueDate) - strtotime($today)) / 86400);
        if ($daysUntilDue > $daysBeforeDue) {
            $totalTooEarly++;
            continue;
        }

        // Label periode (PENGUNAAN) dari bulan/tahun tanggal jatuh tempo itu sendiri --
        // sama seperti pola Fixed Due Date & manual_generate_invoice.php (periode
        // penagihan = bulan/tahun jatuh temponya, bukan bulan hari ini).
        $periode = $bulanIndo[(int)date('n', strtotime($dueDate)) - 1] . ' ' . date('Y', strtotime($dueDate));
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

        $tanggalTagih = formatTanggalIndoRM($dueDate);
        $buktiRef = 'INV-PENAGIHAN-' . preg_replace('/[^A-Za-z0-9_-]/', '', $idpel) . '-' . date('Ymd', strtotime($dueDate));
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

$serverStmt->close();

echo "Selesai. Total Inserted: {$totalInserted}, Total Regenerated: {$totalRegenerated}, Total Skipped: {$totalSkipped}, Belum waktunya: {$totalTooEarly}\n";
foreach ($ringkasanPerPeriode as $periode => $rek) {
    $ins = $rek['inserted'] ?? 0;
    $rgn = $rek['regenerated'] ?? 0;
    $skp = $rek['skipped'] ?? 0;
    echo "  - {$periode}: Inserted {$ins} (regenerated {$rgn}), Skipped {$skp}\n";
}
