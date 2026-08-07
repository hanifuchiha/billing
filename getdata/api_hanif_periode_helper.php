<?php
/**
 * getdata/periode_helper.php
 * ----------------------------------------------------------------------
 * Helper terpusat untuk menghitung "Periode / Penggunaan" transaksi
 * pelanggan, dipakai bersama oleh:
 *   - index.php (halaman "Data Pelanggan API")
 *   - sync_pelanggan_api.php (proses sinkron ke tabel `transaksi`)
 *   - get_reminder_settings.php (endpoint AJAX utk modal sync)
 *
 * ATURAN PRIORITAS PERIODE (dipakai di semua tempat di atas):
 *   1. Jika API mengirim `payment_history[].periode` yang tidak kosong utk
 *      transaksi_id terkait -> PAKAI ITU APA ADANYA (paling akurat, sudah
 *      dihitung oleh sistem billing eksternal).
 *   2. Jika tidak ada -> hitung sendiri berdasarkan TANGGAL transaksi +
 *      pengaturan tutup buku pelanggan ybs, mengikuti rule yang SAMA
 *      seperti dipakai untuk membuat tagihan baru di portal_baru.php,
 *      tapi diterapkan pada tanggal transaksi historis (bukan "hari ini").
 *
 * Pengaturan tutup buku (jatuh_tempo, tanggal_awal_tutup_buku,
 * tanggal_akhir_tutup_buku, dst) diambil dari file
 * notifbot/data/reminder-{username}.json milik user (akun) pemilik server
 * pelanggan tsb -- BUKAN dari input manual.
 * ----------------------------------------------------------------------
 */

if (!function_exists('getMonthNameIndo')) {
    /**
     * Nama bulan Indonesia dari nomor bulan (boleh >12 atau <1, otomatis
     * di-normalisasi ke tahun berikutnya/sebelumnya).
     */
    function getMonthNameIndo(int $month, int $year): string
    {
        while ($month > 12) {
            $month -= 12;
            $year++;
        }
        while ($month < 1) {
            $month += 12;
            $year--;
        }

        $bulanList = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
        ];

        return $bulanList[$month] . ' ' . $year;
    }
}

if (!function_exists('getUsernameByPemilik')) {
    /**
     * Ambil USERNAME akun (tabel `user`) pemilik server berdasarkan PEMILIK.
     * Mengikuti pola query yang sama seperti di portal_baru.php:
     *   SELECT * FROM user WHERE id = (SELECT user_id FROM server WHERE PEMILIK = ... LIMIT 1)
     */
    function getUsernameByPemilik($conn, string $pemilik): ?string
    {
        $pemilik = trim($pemilik);
        if ($pemilik === '' || !$conn) {
            return null;
        }

        $pemilikEsc = mysqli_real_escape_string($conn, $pemilik);
        $q = mysqli_query(
            $conn,
            "SELECT USERNAME FROM `user` WHERE id = (SELECT user_id FROM `server` WHERE PEMILIK = '$pemilikEsc' LIMIT 1) LIMIT 1"
        );

        if ($q && mysqli_num_rows($q) > 0) {
            $row = mysqli_fetch_assoc($q);
            $username = trim((string)($row['USERNAME'] ?? ''));
            return $username !== '' ? $username : null;
        }

        return null;
    }
}

if (!function_exists('loadReminderSettings')) {
    /**
     * Baca notifbot/data/reminder-{username}.json dan kembalikan
     * pengaturan tutup buku / reminder-nya (item pertama dalam array,
     * sesuai pola pembacaan yang sudah dipakai di portal_baru.php).
     *
     * Return null jika file tidak ada / tidak bisa dibaca / kosong.
     */
    function loadReminderSettings(?string $username): ?array
    {
        $username = trim((string) $username);
        if ($username === '') {
            return null;
        }

        // __DIR__ di sini = folder getdata/, sehingga path yang sama
        // seperti dipakai portal_baru.php ("../notifbot/data/...") tetap
        // konsisten selama file ini berada satu level dengan
        // sync_pelanggan_api.php / index.php.
        $file = __DIR__ . '/../notifbot/data/reminder-' . $username . '.json';

        if (!file_exists($file)) {
            return null;
        }

        $jsonData = @file_get_contents($file);
        if ($jsonData === false) {
            return null;
        }

        $data = json_decode($jsonData, true);
        if (!is_array($data) || count($data) === 0) {
            return null;
        }

        // File berbentuk array of object; pola lama hanya memakai baris
        // terakhir yang ter-loop (karena for-each tanpa break), tapi itu
        // sebenarnya cuma kebetulan selalu ambil item terakhir. Di sini
        // kita eksplisit ambil ITEM PERTAMA supaya konsisten &
        // dapat diprediksi.
        $item = $data[0];

        return [
            'jatuh_tempo'               => isset($item['jatuh_tempo']) ? (int) $item['jatuh_tempo'] : null,
            'tanggal_awal_tutup_buku'   => isset($item['tanggal_awal_tutup_buku']) ? (int) $item['tanggal_awal_tutup_buku'] : null,
            'tanggal_akhir_tutup_buku'  => isset($item['tanggal_akhir_tutup_buku']) ? (int) $item['tanggal_akhir_tutup_buku'] : null,
            'hari_sebelum'              => $item['hari_sebelum'] ?? null,
            'tanggal_reminder'          => $item['tanggal_reminder'] ?? null,
            'botname'                   => $item['botname'] ?? null,
        ];
    }
}

if (!function_exists('resolvePeriodeFromTanggal')) {
    /**
     * Hitung periode/penggunaan dari SATU tanggal transaksi (bukan "hari
     * ini"), mengikuti rule yang sama seperti dipakai portal_baru.php utk
     * membuat tagihan baru:
     *
     * - TIPE_TEMPO = 'mengikuti_tanggal_tempo':
     *     dipengaruhi jendela tutup buku (tanggal_awal_tutup_buku s/d
     *     tanggal_akhir_tutup_buku) dan jatuh_tempo, dari
     *     reminder-{username}.json milik PEMILIK pelanggan.
     *
     * - TIPE_TEMPO = 'mengikuti_tanggal_bayar' (atau pengaturan tutup buku
     *   tidak ditemukan):
     *     pascabayar -> periode = bulan tanggal transaksi
     *     prabayar   -> periode = bulan tanggal transaksi + 1
     */
    function resolvePeriodeFromTanggal(
        string $tanggalTransaksi,
        ?array $settings,
        string $tipeTempo = '',
        string $tipeBayar = ''
    ): string {
        $ts = strtotime($tanggalTransaksi);
        if ($ts === false) {
            $ts = time();
        }

        $tgl   = (int) date('d', $ts);
        $bulan = (int) date('n', $ts);
        $tahun = (int) date('Y', $ts);

        $tipeTempo = $tipeTempo !== '' ? $tipeTempo : 'mengikuti_tanggal_bayar';
        $tipeBayar = $tipeBayar !== '' ? $tipeBayar : 'pascabayar';

        $adaSettingsValid = $settings
            && !empty($settings['tanggal_awal_tutup_buku'])
            && !empty($settings['tanggal_akhir_tutup_buku']);

        if ($tipeTempo === 'mengikuti_tanggal_tempo' && $adaSettingsValid) {
            $jatuhTempo = (int) $settings['jatuh_tempo'];
            $awal       = (int) $settings['tanggal_awal_tutup_buku'];
            $akhir      = (int) $settings['tanggal_akhir_tutup_buku'];

            $bulanIni   = getMonthNameIndo($bulan, $tahun);
            $bulanDepan = getMonthNameIndo($bulan + 1, $tahun);

            $periodeBulanBerikut = ($jatuhTempo <= $akhir)
                ? $bulanDepan
                : getMonthNameIndo($bulan + 2, $tahun);

            if ($tgl < $awal) {
                // Sebelum masa tutup buku -> periode bulan berjalan.
                return $bulanIni;
            } elseif ($tgl >= $awal && $tgl <= $akhir) {
                // Masa tutup buku -> geser ke periode berikutnya.
                return $periodeBulanBerikut;
            } else {
                // Setelah masa tutup buku -> sama seperti hasil geser di atas.
                return ($jatuhTempo <= $akhir) ? $bulanDepan : getMonthNameIndo($bulan + 2, $tahun);
            }
        }

        // mengikuti_tanggal_bayar, atau pengaturan tutup buku tidak
        // ditemukan (fallback paling sederhana & aman).
        if ($tipeBayar === 'prabayar') {
            return getMonthNameIndo($bulan + 1, $tahun);
        }

        return getMonthNameIndo($bulan, $tahun); // pascabayar / default
    }
}

if (!function_exists('buildPeriodeMapFromPaymentHistory')) {
    /**
     * Bangun peta [transaksi_id => periode] dari payment_history[] milik
     * SATU pelanggan. `periode` di sini adalah field resmi dari API
     * (billing eksternal), dihitung dari tanggal_bayar oleh sistem
     * billing itu sendiri -- ini SUMBER UTAMA, dipakai dulu sebelum
     * fallback ke resolvePeriodeFromTanggal().
     */
    function buildPeriodeMapFromPaymentHistory(array $paymentHistory): array
    {
        $map = [];
        foreach ($paymentHistory as $ph) {
            if (!isset($ph['transaksi_id'])) {
                continue;
            }
            $map[(string) $ph['transaksi_id']] = $ph['periode'] ?? null;
        }
        return $map;
    }
}

if (!function_exists('resolvePeriodeFinal')) {
    /**
     * Fungsi utama yang dipanggil dari luar: resolve periode untuk SATU
     * item transaksi (dari transactions[]), dengan urutan prioritas:
     *   1. payment_history.periode (via $periodeMap, hasil
     *      buildPeriodeMapFromPaymentHistory) jika ada & tidak kosong.
     *   2. resolvePeriodeFromTanggal() sebagai fallback berbasis tanggal +
     *      pengaturan tutup buku pemilik.
     *
     * $t          : satu item transactions[] (harus punya transaksi_id &
     *               transaksi_tanggal).
     * $periodeMap : hasil buildPeriodeMapFromPaymentHistory() utk
     *               pelanggan yg sama.
     * $settings   : hasil loadReminderSettings() utk PEMILIK pelanggan ini
     *               (boleh null).
     * $tipeTempo / $tipeBayar : tipe tempo & tipe bayar pelanggan ybs.
     */
    function resolvePeriodeFinal(
        array $t,
        array $periodeMap,
        ?array $settings,
        string $tipeTempo = '',
        string $tipeBayar = ''
    ): string {
        $txId = isset($t['transaksi_id']) ? (string) $t['transaksi_id'] : null;

        if (
            $txId !== null &&
            array_key_exists($txId, $periodeMap) &&
            $periodeMap[$txId] !== null &&
            $periodeMap[$txId] !== ''
        ) {
            return (string) $periodeMap[$txId];
        }

        $tanggal = (string) ($t['transaksi_tanggal'] ?? date('Y-m-d'));

        return resolvePeriodeFromTanggal($tanggal, $settings, $tipeTempo, $tipeBayar);
    }
}