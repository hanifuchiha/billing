<?php
header('Content-Type: application/json; charset=utf-8');

date_default_timezone_set('Asia/Jakarta');

require '../cek-sesi.php';
require_once __DIR__ . '/../notifbot/notifphp/tagihan_status_lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Metode request tidak diizinkan.'
    ]);
    exit;
}


// Ambil angka tanggal (1-31) dari string TANGGALBAYAR, format bebas selama ada angka di dalamnya
// (mendukung format "Senin, 15 Agustus 2026", "15-08-2026", "2026-08-15", dll).
// Return null kalau tidak ditemukan angka tanggal yang valid.
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

$bulan_penggunaan = [
    'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
];

$generate_month = trim($_POST['generate_month'] ?? '');
$generate_year = (int)($_POST['generate_year'] ?? 0);

if (!in_array($generate_month, $bulan_penggunaan, true) || $generate_year < 2000 || $generate_year > 2100) {
    echo json_encode([
        'success' => false,
        'message' => 'Periode penggunaan tidak valid.'
    ]);
    exit;
}

// Satu periode saja: periode yang diinput admin. (Sebelumnya ada "periode kedua"
// otomatis +1 bulan di sini yang bikin due date meleset 2 bulan tiap klik manual
// generate -- dihapus supaya konsisten dengan UI yang sudah menjanjikan "1 periode
// per generate" dan dengan invoice_generator_penagihan.php yang juga cuma 1 periode.)
$indexBulanInput = array_search($generate_month, $bulan_penggunaan, true); // 0-based
$tsBulanInput = mktime(0, 0, 0, $indexBulanInput + 1, 1, $generate_year);

$periodeTargets = [
    ['bulan' => $generate_month, 'tahun' => $generate_year, 'offsetLabel' => date('Ym', $tsBulanInput)],
];

// Scoping kepemilikan server (pola sama dgn dashboard.php) -- $current_user_id
// utk ASSISTANT berisi id akun OWNER (lihat cek-sesi.php), BUKAN id assistant
// itu sendiri, jadi "WHERE user_id = $current_user_id" polos SELALU balik
// SEMUA server milik owner. Tanpa fix ini, assistant bisa generate/hapus
// invoice PENAGIHAN Rp0 utk pelanggan di area lain milik owner yg sama.
$userServerIdsGenerate = [];
if ($AKSES === 'ASSISTANT') {
    if (isset($area_list) && trim((string)$area_list) !== '') {
        $queryServerIdGenerate = mysqli_query($conn, "SELECT PEMILIK FROM server WHERE AREA IN ($area_list)");
        if ($queryServerIdGenerate) {
            while ($rowGenerate = mysqli_fetch_assoc($queryServerIdGenerate)) {
                $userServerIdsGenerate[] = "'" . mysqli_real_escape_string($conn, $rowGenerate['PEMILIK']) . "'";
            }
        }
    }
} else {
    $queryServerIdGenerate = mysqli_query($conn, "SELECT PEMILIK FROM server WHERE user_id = $current_user_id");
    if ($queryServerIdGenerate) {
        while ($rowGenerate = mysqli_fetch_assoc($queryServerIdGenerate)) {
            $userServerIdsGenerate[] = "'" . mysqli_real_escape_string($conn, $rowGenerate['PEMILIK']) . "'";
        }
    }
}
$server_list_generate = count($userServerIdsGenerate) > 0 ? implode(',', $userServerIdsGenerate) : "''";

mysqli_query(
    $conn,
    "DELETE FROM transaksi WHERE PEMILIK IN ($server_list_generate) AND TRIM(UPPER(COALESCE(STATUS, '')))='PENAGIHAN' AND CAST(COALESCE(NULLIF(HARGA, ''), '0') AS DECIMAL(18,2)) <= 0"
);

$inserted_count = 0;
$skipped_count = 0;
$regenerated_count = 0;
$ringkasan_per_periode = [];
$jatuh_tempo_cache_generate = [];

// Setting "Periode Tercatat" (Fixed Due Date, Payment Setting -> Konfigurasi Fixed
// Due Date) -- dibaca sekali di sini (bukan per pelanggan) karena kuncinya AKUN CRM
// yang login ($ceknama), konstan sepanjang request ini, sama seperti pola
// $jatuh_tempo_cache_generate di bawah.
$periodeTercatatReminderFile = __DIR__ . '/../notifbot/data/reminder-' . $ceknama . '.json';
$periodeTercatatModeGenerate = tagihanLoadPeriodeTercatatMode($periodeTercatatReminderFile);

$pelanggan_q = mysqli_query($conn, "SELECT IDPEL, NAMA, PAKET, PEMILIK, COALESCE(MODE, '') AS MODE, TIPE_TEMPO, TANGGALPASANG, TANGGAL_MONTHVERSARY FROM pelanggan WHERE IDPEL <> '' AND PEMILIK IN ($server_list_generate)");
while ($pel = mysqli_fetch_assoc($pelanggan_q)) {
    $idpel_generate = $pel['IDPEL'];
    $nama_generate = $pel['NAMA'];
    $paket_generate = $pel['PAKET'];
    $pemilik_generate = $pel['PEMILIK'];
    $mode_generate = strtoupper(trim((string)$pel['MODE']));
    $tipe_tempo_generate = strtolower(trim((string)($pel['TIPE_TEMPO'] ?? '')));

    if (in_array($mode_generate, ['NONAKTIF', 'DISABLED', 'DISMANTLE', 'BERHENTI'], true)) {
        $skipped_count += count($periodeTargets);
        continue;
    }

    // Ambil harga paket sekali saja, dipakai untuk kedua periode.
    $harga_generate = reseller_effective_harga($conn, $paket_generate, $pemilik_generate);

    if ($harga_generate <= 0) {
        $skipped_count += count($periodeTargets);
        continue;
    }

    // Ambil angka tanggal dari TANGGALBAYAR transaksi BERHASIL terakhir milik pelanggan ini
    // (lintas periode apapun). Angka ini nanti dipasang ke bulan & tahun tiap periode penagihan
    // yang sedang dibuat. Null kalau belum pernah ada transaksi BERHASIL.
    $tanggalAngka = null;
    $tgl_bayar_q = mysqli_query($conn, "SELECT TANGGALBAYAR FROM transaksi WHERE IDPEL='" . mysqli_real_escape_string($conn, $idpel_generate) . "' AND PEMILIK='" . mysqli_real_escape_string($conn, $pemilik_generate) . "' AND TRIM(UPPER(COALESCE(STATUS, '')))='BERHASIL' ORDER BY id DESC LIMIT 1");
    if ($tgl_bayar_q && mysqli_num_rows($tgl_bayar_q) > 0) {
        $tgl_bayar_row = mysqli_fetch_assoc($tgl_bayar_q);
        $tanggalAngka = ekstrakTanggal((string)($tgl_bayar_row['TANGGALBAYAR'] ?? ''));
    }

    // Mode "mengikuti_tanggal_tempo" (Fixed Due Date): angka tanggal HARUS ikut
    // setting "jatuh_tempo" di Payment Setting -> Konfigurasi Fixed Due Date
    // (reminder-{AKUN CRM}.json, KUNCI-nya $ceknama -- lihat paymentset.php
    // "$username = $ceknama"), BUKAN hari dari transaksi BERHASIL terakhir.
    // PENTING: kuncinya AKUN CRM yang login, BUKAN $pemilik_generate (PEMILIK
    // server/router milik pelanggan ini, bisa beda string dari username akun --
    // sama seperti kasus struk_settings_username). Salah pakai PEMILIK di sini
    // bikin file reminder-nya tidak pernah ketemu, diam-diam fallback ke default
    // 25 walau admin sudah setting angka lain di Payment Setting.
    if ($tipe_tempo_generate === 'mengikuti_tanggal_tempo') {
        if (!isset($jatuh_tempo_cache_generate[$ceknama])) {
            $jatuhTempoValueGenerate = 25;
            $reminderFileGenerate = __DIR__ . '/../notifbot/data/reminder-' . $ceknama . '.json';
            if (file_exists($reminderFileGenerate)) {
                $reminderCfgGenerate = json_decode(file_get_contents($reminderFileGenerate), true);
                if (is_array($reminderCfgGenerate) && isset($reminderCfgGenerate[0]['jatuh_tempo'])) {
                    $jatuhTempoValueGenerate = (int)$reminderCfgGenerate[0]['jatuh_tempo'];
                }
            }
            $jatuh_tempo_cache_generate[$ceknama] = max(1, min(31, $jatuhTempoValueGenerate));
        }
        $tanggalAngka = $jatuh_tempo_cache_generate[$ceknama];
    }

    // Mode "monthversary": pakai anchor terkunci (TANGGAL_MONTHVERSARY, fallback
    // TANGGALPASANG), bukan tanggal transaksi terakhir -- konsisten dgn
    // invoice_generator_penagihan.php & cek_tagihan_harian.php.
    if ($tipe_tempo_generate === 'monthversary') {
        $anchorTanggalMvGenerate = (string)($pel['TANGGAL_MONTHVERSARY'] ?? '');
        if ($anchorTanggalMvGenerate === '' || strtotime($anchorTanggalMvGenerate) === false) {
            $anchorTanggalMvGenerate = (string)($pel['TANGGALPASANG'] ?? '');
        }
        if ($anchorTanggalMvGenerate !== '' && strtotime($anchorTanggalMvGenerate) !== false) {
            $tanggalAngka = (int)date('j', strtotime($anchorTanggalMvGenerate));
        }
    }

    // Mode "mengikuti_tanggal_bayar" (Rolling): pakai hasil simulasi SELURUH
    // histori pembayaran BERHASIL (tagihanComputeRollingReferenceDate), BUKAN
    // cuma angka tanggal dari transaksi BERHASIL TERAKHIR -- supaya konsisten
    // dgn cron & tagihan_status_lib.php. Override "Ubah Jatuh Tempo" (kolom
    // TANGGAL_MONTHVERSARY dipakai ulang) menang selama belum lewat.
    if ($tipe_tempo_generate === 'mengikuti_tanggal_bayar') {
        $tanggalPasangGenerate = (string)($pel['TANGGALPASANG'] ?? '');
        if ($tanggalPasangGenerate !== '' && strtotime($tanggalPasangGenerate) !== false) {
            $rollingRefGenerate = tagihanComputeRollingReferenceDate($conn, $idpel_generate, $tanggalPasangGenerate);
            $rollingFirstDueGenerate = date('Y-m-d', strtotime('+1 month', strtotime($rollingRefGenerate)));
            $tanggalAngka = (int)date('j', strtotime($rollingFirstDueGenerate));
        }
        $rollingOverrideGenerate = tagihanGetRollingOverrideDueDate((string)($pel['TANGGAL_MONTHVERSARY'] ?? ''), date('Y-m-d'));
        if ($rollingOverrideGenerate !== null) {
            $tanggalAngka = (int)date('j', strtotime($rollingOverrideGenerate));
        }
    }

    foreach ($periodeTargets as $target) {
        $periode_penggunaan = $target['bulan'] . ' ' . $target['tahun'];

        // Fixed Due Date: label PENGUNAAN ikut setting "Periode Tercatat" -- 'berjalan'
        // (default) = sama dgn bulan due date yang diminta admin (perilaku selama ini),
        // 'berikutnya' = +1 bulan dari situ. Due date (TANGGALBAYAR di bawah) TETAP
        // dibangun dari $target['bulan']/$target['tahun'] apa adanya, tidak berubah.
        if ($tipe_tempo_generate === 'mengikuti_tanggal_tempo') {
            $periode_penggunaan = tagihanResolvePeriodeTercatat($indexBulanInput + 1, (int)$target['tahun'], $periodeTercatatModeGenerate);
        }

        $periode_penggunaan_normalized = mb_strtoupper(trim($periode_penggunaan), 'UTF-8');
        $was_regenerated = false;

        $cek_exist_q = mysqli_query($conn, "SELECT id, TRIM(UPPER(COALESCE(STATUS, ''))) AS STATUS_NORM FROM transaksi WHERE IDPEL='" . mysqli_real_escape_string($conn, $idpel_generate) . "' AND PEMILIK='" . mysqli_real_escape_string($conn, $pemilik_generate) . "' AND TRIM(UPPER(COALESCE(PENGUNAAN, '')))='" . mysqli_real_escape_string($conn, $periode_penggunaan_normalized) . "' AND TRIM(UPPER(COALESCE(STATUS, ''))) IN ('PENAGIHAN','PERMINTAAN KODE','KONFIRMASI','BERHASIL') LIMIT 1");
        if ($cek_exist_q && mysqli_num_rows($cek_exist_q) > 0) {
            $cek_exist_row = mysqli_fetch_assoc($cek_exist_q);
            if ($cek_exist_row['STATUS_NORM'] === 'PENAGIHAN') {
                // Sudah ada penagihan (belum bayar) untuk periode ini: hapus dulu, lalu buat ulang di bawah.
                mysqli_query($conn, "DELETE FROM transaksi WHERE id=" . (int)$cek_exist_row['id']);
                $was_regenerated = true;
            } else {
                // Status lain (BERHASIL/KONFIRMASI/PERMINTAAN KODE): jangan disentuh, skip.
                $skipped_count++;
                $ringkasan_per_periode[$periode_penggunaan]['skipped'] = ($ringkasan_per_periode[$periode_penggunaan]['skipped'] ?? 0) + 1;
                continue;
            }
        }

        // Bangun TANGGALBAYAR untuk periode ini: angka tanggal dari riwayat BERHASIL,
        // dipasang ke bulan & tahun periode penagihan yang sedang dibuat. Fallback '-'
        // kalau pelanggan belum pernah punya transaksi BERHASIL.
        $tanggal_generate = $tanggalAngka !== null
            ? bangunTanggalBayar($tanggalAngka, $target['bulan'], (int)$target['tahun'], $bulan_penggunaan)
            : '-';

        $bukti_ref = 'INV-PENAGIHAN-MANUAL-' . preg_replace('/[^A-Za-z0-9_-]/', '', $idpel_generate) . '-' . $target['offsetLabel'];
        $cek_generate = 'MANUAL GENERATE INVOICE';
        $status_generate = 'PENAGIHAN';

        $insert_q = mysqli_query(
            $conn,
            "INSERT INTO transaksi (TANGGALBAYAR, PENGUNAAN, STATUS, IDPEL, NAMA, PAKET, HARGA, BUKTI, CEK, PEMILIK, METODE_BAYAR) VALUES ('" . mysqli_real_escape_string($conn, $tanggal_generate) . "', '" . mysqli_real_escape_string($conn, $periode_penggunaan) . "', '" . mysqli_real_escape_string($conn, $status_generate) . "', '" . mysqli_real_escape_string($conn, $idpel_generate) . "', '" . mysqli_real_escape_string($conn, $nama_generate) . "', '" . mysqli_real_escape_string($conn, $paket_generate) . "', " . (float)$harga_generate . ", '" . mysqli_real_escape_string($conn, $bukti_ref) . "', '" . mysqli_real_escape_string($conn, $cek_generate) . "', '" . mysqli_real_escape_string($conn, $pemilik_generate) . "', '')"
        );

        if ($insert_q) {
            $inserted_count++;
            $ringkasan_per_periode[$periode_penggunaan]['inserted'] = ($ringkasan_per_periode[$periode_penggunaan]['inserted'] ?? 0) + 1;
            if ($was_regenerated) {
                $regenerated_count++;
                $ringkasan_per_periode[$periode_penggunaan]['regenerated'] = ($ringkasan_per_periode[$periode_penggunaan]['regenerated'] ?? 0) + 1;
            }
        } else {
            $skipped_count++;
            $ringkasan_per_periode[$periode_penggunaan]['skipped'] = ($ringkasan_per_periode[$periode_penggunaan]['skipped'] ?? 0) + 1;
        }
    }
}

$periode_list_label = implode(' & ', array_map(static function ($t) {
    return $t['bulan'] . ' ' . $t['tahun'];
}, $periodeTargets));

echo json_encode([
    'success' => true,
    'message' => 'Manual generate periode ' . $periode_list_label . ' selesai.',
    'periode' => $periode_list_label,
    'rincian_periode' => $ringkasan_per_periode,
    'inserted' => $inserted_count,
    'regenerated' => $regenerated_count,
    'skipped' => $skipped_count,
    // DEBUG SEMENTARA -- supaya kelihatan nilai asli yang dipakai utk hitung PENGUNAAN
    // Fixed Due Date (rincian_periode di atas sudah dikelompokkan per label PENGUNAAN
    // yang BENAR-BENAR dipakai insert; "message" di atas cuma pakai input mentah admin,
    // BUKAN hasil tagihanResolvePeriodeTercatat() -- bisa beda kalau ada bug di sini).
    'debug_ceknama' => $ceknama,
    'debug_periode_tercatat_mode' => $periodeTercatatModeGenerate,
    'debug_indexBulanInput_plus1' => $indexBulanInput + 1,
    'debug_generate_month_raw' => $generate_month,
    'debug_reminder_file_path' => $periodeTercatatReminderFile,
    'debug_reminder_file_exists' => file_exists($periodeTercatatReminderFile),
]);