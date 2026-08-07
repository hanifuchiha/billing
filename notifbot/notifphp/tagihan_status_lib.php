<?php
/**
 * tagihan_status_lib.php
 *
 * Logika "sudah bayar / belum bayar" yang SAMA PERSIS dengan yang dipakai
 * cek_tagihan_harian.php (skrip enforcement Mikrotik harian), diekstrak jadi
 * fungsi murni (tanpa efek samping ke Mikrotik) supaya bisa dipakai juga oleh
 * sync_freeradius_users.php. Tidak ada logika di sini yang mengubah apa pun
 * di router -- itu tetap tanggung jawab cek_tagihan_harian.php sendiri.
 *
 * cek_tagihan_harian.php TIDAK diubah/dipakai langsung dari sini supaya tidak
 * menambah risiko pada skrip enforcement Mikrotik yang sudah berjalan di
 * produksi -- fungsi-fungsi di bawah adalah salinan yang disamakan perilakunya.
 */

if (!function_exists('tagihanBulanTahunIndo')) {
    function tagihanBulanTahunIndo(string $tanggal, int $tambah = 0): string
    {
        $namaBulan = [
            1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
        ];
        $ts = strtotime($tanggal);
        $bulan = (int) date('n', $ts) + $tambah;
        $tahun = (int) date('Y', $ts);

        while ($bulan < 1) {
            $bulan += 12;
            $tahun--;
        }
        while ($bulan > 12) {
            $bulan -= 12;
            $tahun++;
        }

        return $namaBulan[$bulan] . ' ' . $tahun;
    }
}

if (!function_exists('tagihanPeriodeAktif')) {
    /**
     * Sama persis dengan periodeTagihanAktif() di cek_tagihan_harian.php (dan
     * salinannya di notif_remainder_pembayaran.php) -- skema periodeSekarang/
     * periodeBerikutnya berbasis tanggal_akhir_tutup_buku saja. $jatuhTempoHari
     * tidak dikonsultasi, sama seperti versi aslinya.
     */
    function tagihanPeriodeAktif(
        int $tglHariIni,
        int $tanggalAwalTutupBuku,
        int $tanggalAkhirTutupBuku,
        int $jatuhTempoHari,
        string $tanggalHariIni
    ): string {
        $periodeSekarang = tagihanBulanTahunIndo($tanggalHariIni, 0);
        $periodeBerikutnya = tagihanBulanTahunIndo($tanggalHariIni, 1);

        // Tutup buku lintas bulan (mis. 24-5)
        if ($tanggalAwalTutupBuku > $tanggalAkhirTutupBuku) {
            if ($tglHariIni >= $tanggalAwalTutupBuku || $tglHariIni <= $tanggalAkhirTutupBuku) {
                return $periodeSekarang;
            }
            return $periodeBerikutnya;
        }

        // Tutup buku normal (mis. 1-10)
        if ($tglHariIni <= $tanggalAkhirTutupBuku) {
            return $periodeSekarang;
        }
        return $periodeBerikutnya;
    }
}

if (!function_exists('tagihanBuildEscapedInList')) {
    function tagihanBuildEscapedInList(mysqli $conn, array $values): string
    {
        $escaped = [];
        foreach (array_unique($values) as $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            $escaped[] = "'" . $conn->real_escape_string($value) . "'";
        }
        return empty($escaped) ? "''" : implode(',', $escaped);
    }
}

if (!function_exists('tagihanBuildTrxDateExpr')) {
    function tagihanBuildTrxDateExpr(string $alias = ''): string
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
}

if (!function_exists('tagihanGetLastPaymentsBulk')) {
    function tagihanGetLastPaymentsBulk(mysqli $conn, array $idpels): array
    {
        if (empty($idpels)) {
            return [];
        }
        $inList = tagihanBuildEscapedInList($conn, $idpels);
        $trxDateExpr = tagihanBuildTrxDateExpr();
        $sql = "SELECT `IDPEL`, MAX($trxDateExpr) AS `waktu_terakhir`
                FROM `transaksi`
                WHERE `STATUS` = 'BERHASIL' AND `IDPEL` IN ($inList)
                GROUP BY `IDPEL`";
        $result = $conn->query($sql);
        $map = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $map[(string) $row['IDPEL']] = $row['waktu_terakhir'];
            }
        }
        return $map;
    }
}

if (!function_exists('tagihanGetLastPaidUsageMapBulk')) {
    function tagihanGetLastPaidUsageMapBulk(mysqli $conn, array $idpels): array
    {
        if (empty($idpels)) {
            return [];
        }
        $inList = tagihanBuildEscapedInList($conn, $idpels);
        $trxDateExprT = tagihanBuildTrxDateExpr('t');
        $trxDateExprX = tagihanBuildTrxDateExpr('x');
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
                $idpel = (string) ($row['IDPEL'] ?? '');
                if ($idpel === '' || isset($map[$idpel])) {
                    continue;
                }
                $map[$idpel] = trim((string) ($row['PENGUNAAN'] ?? ''));
            }
        }
        return $map;
    }
}

if (!function_exists('tagihanGetFirstAndCountPaymentsBulk')) {
    /**
     * Untuk mode "monthversary": tanggal transaksi BERHASIL pertama + jumlah
     * transaksi BERHASIL per pelanggan. Sama seperti getFirstAndCountPaymentsBulk()
     * di cek_tagihan_harian.php, versi murni (read-only, tanpa efek samping).
     */
    function tagihanGetFirstAndCountPaymentsBulk(mysqli $conn, array $idpels): array
    {
        if (empty($idpels)) {
            return [];
        }
        $inList = tagihanBuildEscapedInList($conn, $idpels);
        $trxDateExpr = tagihanBuildTrxDateExpr();
        $sql = "SELECT `IDPEL`, MIN($trxDateExpr) AS `waktu_pertama`, COUNT(*) AS `jumlah_transaksi`
                FROM `transaksi`
                WHERE `STATUS` = 'BERHASIL' AND `IDPEL` IN ($inList)
                GROUP BY `IDPEL`";
        $result = $conn->query($sql);
        $map = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $map[(string) $row['IDPEL']] = [
                    'waktu_pertama' => $row['waktu_pertama'],
                    'jumlah_transaksi' => (int) $row['jumlah_transaksi'],
                ];
            }
        }
        return $map;
    }
}

if (!function_exists('tagihanResolveHargaPaket')) {
    function tagihanResolveHargaPaket(array $hargaPaketMap, string $paket, string $brand, string $area)
    {
        $mapKey = $paket . '|' . $brand . '|' . $area;
        if (isset($hargaPaketMap[$mapKey])) return $hargaPaketMap[$mapKey];
        if (isset($hargaPaketMap[$paket . '||' . $area])) return $hargaPaketMap[$paket . '||' . $area];
        if (isset($hargaPaketMap[$paket . '|' . $brand . '|'])) return $hargaPaketMap[$paket . '|' . $brand . '|'];
        if (isset($hargaPaketMap[$paket . '||'])) return $hargaPaketMap[$paket . '||'];
        if (isset($hargaPaketMap[$paket])) return $hargaPaketMap[$paket];
        return null;
    }
}

if (!function_exists('tagihanIsFasumNonPromo')) {
    function tagihanIsFasumNonPromo(string $paket, array $fasumPaketList, array $promoPaketIds): bool
    {
        if ($paket === '' || !isset($fasumPaketList[$paket])) {
            return false;
        }
        $paketIdFasum = (string) $fasumPaketList[$paket];
        return !in_array($paketIdFasum, $promoPaketIds, true);
    }
}

if (!function_exists('tagihanLoadPaketMaps')) {
    /**
     * Return [hargaPaketMap, fasumPaketList, promoPaketIds] -- sama seperti
     * blok pemuatan tabel `paket`/`promo_paket` di awal cek_tagihan_harian.php.
     * Panggil SEKALI saja (bukan per-owner), lalu dipakai untuk semua pelanggan.
     */
    function tagihanLoadPaketMaps(mysqli $conn): array
    {
        $hargaPaketMap = [];
        $fasumPaketList = [];
        $promoPaketIds = [];

        $qPaketMap = $conn->query("SELECT id, PAKET, HARGA, BRAND, AREA FROM paket");
        while ($qPaketMap && ($r = $qPaketMap->fetch_assoc())) {
            $paketKey = strtolower(trim((string) ($r['PAKET'] ?? '')));
            $brandKey = strtolower(trim((string) ($r['BRAND'] ?? '')));
            $areaKey = strtolower(trim((string) ($r['AREA'] ?? '')));
            $mapKey = $paketKey . '|' . $brandKey . '|' . $areaKey;
            $hargaPaketMap[$mapKey] = $r['HARGA'];

            if ($paketKey !== '' && ($r['HARGA'] === '' || (float) $r['HARGA'] <= 0)) {
                $fasumPaketList[$paketKey] = (string) ($r['id'] ?? '');
            }
        }

        $qPromo = $conn->query("SELECT paket_id FROM promo_paket");
        while ($qPromo && ($r = $qPromo->fetch_assoc())) {
            $promoPaketIds[] = (string) ($r['paket_id'] ?? '');
        }

        return [$hargaPaketMap, $fasumPaketList, $promoPaketIds];
    }
}

if (!function_exists('tagihanLoadPromoConfigMap')) {
    /**
     * Peta nama PAKET (lowercase) -> baris config promo_paket, dimuat SEKALI
     * (bukan per-pelanggan) supaya bisa dicek per-baris tanpa query berulang.
     * Nama paket dianggap unik lintas pemilik/area (sama seperti crontab_promo.php
     * yang mencocokkan promo_paket.paket_id ke paket.PAKET tanpa filter area).
     */
    function tagihanLoadPromoConfigMap(mysqli $conn): array
    {
        $map = [];
        $res = $conn->query("SELECT pp.*, p.PAKET FROM promo_paket pp INNER JOIN paket p ON pp.paket_id = p.id");
        while ($res && ($r = $res->fetch_assoc())) {
            $key = strtolower(trim((string) ($r['PAKET'] ?? '')));
            if ($key !== '') {
                $map[$key] = $r;
            }
        }
        return $map;
    }
}

if (!function_exists('tagihanComputePromoEndDate')) {
    /**
     * Hitung tanggal berakhirnya promo utk 1 pelanggan -- logika SAMA PERSIS
     * dengan notifbot/notifphp/crontab_promo.php (yang benar-benar menjalankan
     * penggantian paket setelah promo habis), supaya tanggal yang ditampilkan
     * di tables.php tidak menyimpang dari tanggal yang dipakai cron itu.
     *
     * $promoConfig = 1 baris dari tagihanLoadPromoConfigMap() (kunci
     * promo_durasi, promo_durasi_type, promo_mulai_type).
     */
    function tagihanComputePromoEndDate(mysqli $conn, string $idpel, string $tanggalPasang, array $promoConfig): ?string
    {
        $mulaiType = (string) ($promoConfig['promo_mulai_type'] ?? '');

        if ($mulaiType === 'transaksi_akhir') {
            $idpelEsc = $conn->real_escape_string($idpel);
            $res = $conn->query("SELECT MAX(DATE(waktu)) AS last_trx FROM transaksi WHERE IDPEL = '$idpelEsc' AND STATUS = 'BERHASIL'");
            $row = $res ? $res->fetch_assoc() : null;
            $startDate = $row['last_trx'] ?? null;
        } else {
            $startDate = $tanggalPasang;
        }

        if (empty($startDate) || strtotime((string) $startDate) === false) {
            return null;
        }

        $durasi = (int) ($promoConfig['promo_durasi'] ?? 0);
        $unit = ((string) ($promoConfig['promo_durasi_type'] ?? 'bulan')) === 'hari' ? 'days' : 'month';

        return date('Y-m-d', strtotime("+{$durasi} {$unit}", strtotime((string) $startDate)));
    }
}

if (!function_exists('tagihanIsSamePeriodAsToday')) {
    function tagihanIsSamePeriodAsToday(string $dateValue, string $today): bool
    {
        if (empty($dateValue)) return false;
        $tsDate = strtotime($dateValue);
        $tsToday = strtotime($today);
        if ($tsDate === false || $tsToday === false) return false;
        return date('Y-m', $tsDate) === date('Y-m', $tsToday);
    }
}

if (!function_exists('tagihanParseIndoMonthYear')) {
    function tagihanParseIndoMonthYear(string $value): ?array
    {
        $raw = trim($value);
        if ($raw === '') {
            return null;
        }
        if (!preg_match('/^([A-Za-z]+)\s+(\d{4})$/', $raw, $m)) {
            return null;
        }
        $monthMap = [
            'januari' => 1, 'februari' => 2, 'maret' => 3, 'april' => 4,
            'mei' => 5, 'juni' => 6, 'juli' => 7, 'agustus' => 8,
            'september' => 9, 'oktober' => 10, 'november' => 11, 'desember' => 12,
        ];
        $monthName = strtolower(trim((string) $m[1]));
        $year = (int) $m[2];
        if (!isset($monthMap[$monthName]) || $year < 1970) {
            return null;
        }
        return ['month' => (int) $monthMap[$monthName], 'year' => $year];
    }
}

if (!function_exists('tagihanBuildMonthlyDate')) {
    function tagihanBuildMonthlyDate(int $year, int $month, int $day): ?string
    {
        if ($year < 1970 || $month < 1 || $month > 12) return null;
        if ($day < 1) $day = 1;
        $daysInMonth = (int) date('t', strtotime(sprintf('%04d-%02d-01', $year, $month)));
        if ($day > $daysInMonth) $day = $daysInMonth;
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}

if (!function_exists('tagihanGetFirstDueDateFixedByUsagePeriod')) {
    function tagihanGetFirstDueDateFixedByUsagePeriod(string $penggunaan, int $fixedDueDay): ?string
    {
        $parsed = tagihanParseIndoMonthYear($penggunaan);
        if (!$parsed) {
            return null;
        }
        return tagihanBuildMonthlyDate((int) $parsed['year'], (int) $parsed['month'], $fixedDueDay);
    }
}

if (!function_exists('tagihanGetFirstDueDateFixed')) {
    function tagihanGetFirstDueDateFixed(string $referenceDate, int $fixedDueDay): ?string
    {
        if (empty($referenceDate) || strtotime($referenceDate) === false) return null;
        $nextMonthTs = strtotime('+1 month', strtotime($referenceDate));
        $year = (int) date('Y', $nextMonthTs);
        $month = (int) date('m', $nextMonthTs);
        return tagihanBuildMonthlyDate($year, $month, $fixedDueDay);
    }
}

if (!function_exists('tagihanGetNextDueDateFixed')) {
    function tagihanGetNextDueDateFixed(string $currentDueDate, int $fixedDueDay): ?string
    {
        if (empty($currentDueDate) || strtotime($currentDueDate) === false) return null;
        $nextMonthTs = strtotime('+1 month', strtotime($currentDueDate));
        $year = (int) date('Y', $nextMonthTs);
        $month = (int) date('m', $nextMonthTs);
        return tagihanBuildMonthlyDate($year, $month, $fixedDueDay);
    }
}

if (!function_exists('tagihanHasSuccessfulPaymentInPeriod')) {
    function tagihanHasSuccessfulPaymentInPeriod(mysqli $conn, string $idpel, string $startDate, string $endDate): bool
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
        $trxDateExpr = tagihanBuildTrxDateExpr();
        $sql = "SELECT 1 FROM transaksi WHERE IDPEL = '$idpelEsc' AND STATUS = 'BERHASIL' AND DATE($trxDateExpr) >= '$startEsc' AND DATE($trxDateExpr) < '$endEsc' LIMIT 1";
        $query = $conn->query($sql);
        return (bool) ($query && $query->fetch_assoc());
    }
}

if (!function_exists('tagihanComputeRollingReferenceDate')) {
    /**
     * Reference date yang BENAR untuk mode Rolling (mengikuti_tanggal_bayar):
     * simulasikan maju dari TANGGALPASANG, siklus demi siklus, memakai SELURUH
     * histori pembayaran BERHASIL (bukan cuma pembayaran TERAKHIR seperti versi
     * lama, yang bikin bayar cepat ikut memajukan jatuh tempo berikutnya).
     *
     * Aturan bisnis:
     * - Bayar CEPAT (sebelum tempo yang sedang berjalan) -> jadwal TIDAK
     *   berubah, cuma maju 1 siklus (tempo berjalan + 30 hari).
     * - Bayar PAS/TELAT (pada/setelah tempo yang sedang berjalan) -> jadwal
     *   ikut mundur/reset, siklus berikutnya dihitung dari tanggal bayar itu
     *   + 30 hari.
     *
     * Siklus Rolling PERSIS 30 hari kalender (bukan +1 bulan yang bisa
     * 28-31 hari tergantung panjang bulan).
     *
     * Return value dipakai pemanggil sebagai "referenceDate" dengan pola lama
     * (firstDueDate = referenceDate + 30 hari), supaya tagihanHitungStatus()
     * dan tagihanHitungJatuhTempoBerikutnya() tidak perlu diubah strukturnya.
     */
    function tagihanComputeRollingReferenceDate(mysqli $conn, string $idpel, string $tanggalPasang): string
    {
        if (empty($tanggalPasang) || strtotime($tanggalPasang) === false) {
            return $tanggalPasang;
        }
        if ($idpel === '') {
            return $tanggalPasang;
        }

        $idpelEsc = $conn->real_escape_string($idpel);
        $trxDateExpr = tagihanBuildTrxDateExpr();
        $sql = "SELECT DATE($trxDateExpr) AS tgl FROM transaksi WHERE IDPEL = '$idpelEsc' AND STATUS = 'BERHASIL' ORDER BY $trxDateExpr ASC";
        $res = $conn->query($sql);
        $payments = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $tgl = (string) ($row['tgl'] ?? '');
                if ($tgl !== '' && strtotime($tgl) !== false) {
                    $payments[] = $tgl;
                }
            }
        }

        // "Tempo yang sedang berjalan" mulai dari 30 hari setelah pasang
        // (siklus Rolling PERSIS 30 hari kalender, bukan +1 bulan yang bisa
        // 28-31 hari tergantung panjang bulan).
        $currentDue = date('Y-m-d', strtotime('+30 days', strtotime($tanggalPasang)));

        foreach ($payments as $payDate) {
            if (strtotime($payDate) < strtotime($currentDue)) {
                // Bayar cepat -> jadwal tetap, cuma maju 1 siklus.
                $currentDue = date('Y-m-d', strtotime('+30 days', strtotime($currentDue)));
            } else {
                // Bayar pas/telat -> reset ke tanggal bayar ini + 30 hari.
                $currentDue = date('Y-m-d', strtotime('+30 days', strtotime($payDate)));
            }
        }

        // Pemanggil menghitung firstDueDate = referenceDate + 30 hari, jadi
        // kembalikan referenceDate = currentDue - 30 hari.
        return date('Y-m-d', strtotime('-30 days', strtotime($currentDue)));
    }
}

if (!function_exists('tagihanGetRollingDueDateForRow')) {
    /**
     * Tanggal jatuh tempo (Y-m-d) yang DIPENUHI oleh satu transaksi BERHASIL
     * tertentu -- yaitu jatuh tempo yang SEDANG BERJALAN pada saat transaksi itu
     * terjadi (bisa jadi transaksi ini bayar cepat/lebih awal dari jatuh tempo itu,
     * atau telat) -- BUKAN jatuh tempo siklus berikutnya setelah transaksi ini
     * diproses. Dipakai utk menampilkan "Jatuh tempo" yang benar pada kartu
     * riwayat transaksi Rolling (mengikuti_tanggal_bayar).
     *
     * Simulasi PERSIS sama dengan tagihanComputeRollingReferenceDate() (siklus 30
     * hari, aturan bayar cepat/telat), tapi cuma memakai histori pembayaran
     * BERHASIL yang terjadi SEBELUM baris transaksi $stopBeforeRowId (tidak
     * termasuk baris itu sendiri) -- currentDue pada titik berhenti itulah jatuh
     * tempo yang dipenuhi baris ini. TIDAK dipasang ulang ke bulan/tahun PENGUNAAN
     * -- hasil simulasi ini SUDAH punya bulan/tahun yang benar sendiri.
     */
    function tagihanGetRollingDueDateForRow(mysqli $conn, string $idpel, string $tanggalPasang, int $stopBeforeRowId): ?string
    {
        if (empty($tanggalPasang) || strtotime($tanggalPasang) === false || $idpel === '') {
            return null;
        }

        $idpelEsc = $conn->real_escape_string($idpel);
        $trxDateExpr = tagihanBuildTrxDateExpr();
        $sql = "SELECT id, DATE($trxDateExpr) AS tgl FROM transaksi WHERE IDPEL = '$idpelEsc' AND STATUS = 'BERHASIL' ORDER BY $trxDateExpr ASC, id ASC";
        $res = $conn->query($sql);
        $payments = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                if ((int) $row['id'] === $stopBeforeRowId) {
                    break;
                }
                $tgl = (string) ($row['tgl'] ?? '');
                if ($tgl !== '' && strtotime($tgl) !== false) {
                    $payments[] = $tgl;
                }
            }
        }

        $currentDue = date('Y-m-d', strtotime('+30 days', strtotime($tanggalPasang)));
        foreach ($payments as $payDate) {
            if (strtotime($payDate) < strtotime($currentDue)) {
                $currentDue = date('Y-m-d', strtotime('+30 days', strtotime($currentDue)));
            } else {
                $currentDue = date('Y-m-d', strtotime('+30 days', strtotime($payDate)));
            }
        }

        return $currentDue;
    }
}

if (!function_exists('tagihanGetRollingOverrideDueDate')) {
    /**
     * Override jatuh tempo berikutnya utk mode Rolling (mengikuti_tanggal_bayar),
     * diset admin lewat tombol "Ubah Jatuh Tempo" (kolom pelanggan.TANGGAL_MONTHVERSARY
     * dipakai ulang sbg override, BUKAN anchor permanen spt di mode monthversary).
     * Override cuma berlaku selama tanggalnya belum lewat -- begitu lewat, otomatis
     * diabaikan dan perhitungan balik ke tagihanComputeRollingReferenceDate() dari
     * histori pembayaran, tanpa perlu di-"clear" manual.
     */
    function tagihanGetRollingOverrideDueDate(string $tanggalOverride, string $hariIni): ?string
    {
        if (empty($tanggalOverride) || strtotime($tanggalOverride) === false) {
            return null;
        }
        $tgl = date('Y-m-d', strtotime($tanggalOverride));
        return strtotime($tgl) >= strtotime($hariIni) ? $tgl : null;
    }
}

if (!function_exists('tagihanCountConsecutiveMissedMonths')) {
    function tagihanCountConsecutiveMissedMonths(mysqli $conn, string $idpel, string $firstDueDate, string $today, bool $isFixedDay, int $fixedDueDay): int
    {
        $bulanTunggak = 0;
        $nextDueDate = $firstDueDate;
        $todayTs = strtotime($today);

        while (!empty($nextDueDate) && strtotime($nextDueDate) <= $todayTs) {
            $cycleStart = $nextDueDate;
            $cycleEnd = $isFixedDay
                ? tagihanGetNextDueDateFixed($cycleStart, $fixedDueDay)
                : date('Y-m-d', strtotime('+30 days', strtotime($cycleStart)));

            if (empty($cycleEnd) || strtotime($cycleEnd) === false) {
                break;
            }
            if (tagihanHasSuccessfulPaymentInPeriod($conn, $idpel, $cycleStart, $cycleEnd)) {
                return 0;
            }
            $bulanTunggak++;
            $nextDueDate = $cycleEnd;
            if (empty($nextDueDate) || strtotime($nextDueDate) === false) break;
        }
        return $bulanTunggak;
    }
}

if (!function_exists('tagihanHitungStatus')) {
    /**
     * Verdict murni "sudah bayar / belum bayar" -- TANPA efek samping ke
     * Mikrotik (itu urusan cek_tagihan_harian.php sendiri). Selaras 1:1 dengan
     * 3 cabang keputusan di cek_tagihan_harian.php: mengikuti_tanggal_bayar,
     * mengikuti_tanggal_tempo, dan fallback.
     *
     * $pel harus berisi: IDPEL, PAKET, TANGGALPASANG, TIPE_BAYAR, TIPE_TEMPO, TEMPO, TANGGAL_MONTHVERSARY
     * $ctx harus berisi: hari_ini, jatuh_tempo_hari, lastPaymentMap, lastPaidUsageMap
     *   (opsional untuk mode monthversary: prabayar_grace_period,
     *   monthversary_follow_last_payment)
     *
     * Return: ['sudah_bayar'=>bool, 'keterangan'=>string, 'jatuh_tempo'=>string]
     */
    function tagihanHitungStatus(mysqli $conn, array $pel, array $ctx): array
    {
        $IDPEL = (string) $pel['IDPEL'];
        $TANGGALPASANG = (string) $pel['TANGGALPASANG'];
        $TANGGAL_MONTHVERSARY = (string) ($pel['TANGGAL_MONTHVERSARY'] ?? '');
        $TIPE_BAYAR = strtolower(trim((string) $pel['TIPE_BAYAR']));
        $TIPE_TEMPO_RAW = strtolower(trim((string) ($pel['TIPE_TEMPO'] ?? '')));
        if ($TIPE_TEMPO_RAW === 'mengikuti_tanggal_bayar') {
            $TIPE_TEMPO = 'mengikuti_tanggal_bayar';
        } elseif ($TIPE_TEMPO_RAW === 'monthversary') {
            $TIPE_TEMPO = 'monthversary';
        } else {
            $TIPE_TEMPO = 'mengikuti_tanggal_tempo';
        }
        $TEMPO = (string) ($pel['TEMPO'] ?? '');

        $hari_ini = (string) $ctx['hari_ini'];
        $jatuh_tempo_hari = (int) $ctx['jatuh_tempo_hari'];
        $lastPaymentMap = $ctx['lastPaymentMap'] ?? [];
        $lastPaidUsageMap = $ctx['lastPaidUsageMap'] ?? [];
        $prabayar_grace_period = (int) ($ctx['prabayar_grace_period'] ?? 0);

        $waktu_terakhir_bayar = $lastPaymentMap[$IDPEL] ?? null;
        $penggunaan_terakhir_berhasil = trim((string) ($lastPaidUsageMap[$IDPEL] ?? ''));

        $belum_bayar = false;
        $keterangan = '';
        $jatuh_tempo_str = '';

        if ($TIPE_TEMPO === 'monthversary') {
            // Anchor per-pelanggan (read-only di sini -- penguncian/self-heal
            // anchor sesungguhnya dilakukan oleh cek_tagihan_harian.php).
            $anchorDate = $TANGGAL_MONTHVERSARY !== '' ? $TANGGAL_MONTHVERSARY : $TANGGALPASANG;
            $anchorDay = (int) date('j', strtotime($anchorDate));
            $referenceDate = $waktu_terakhir_bayar ? substr((string) $waktu_terakhir_bayar, 0, 10) : $TANGGALPASANG;

            // Toggle "Monthversary ikut tanggal bayar terakhir" (Payment Setting).
            // Kalau ON: ASIMETRIS mirip Rolling Due Date -- anchor CUMA ikut geser
            // kalau pembayaran terakhir TELAT dari anchor yang berlaku (mis. anchor
            // tgl 10, dibayar tgl 14 -> siklus berikutnya jadi tgl 14). Bayar
            // CEPAT/PAS (mis. anchor tgl 10, dibayar tgl 8) TIDAK menggeser anchor,
            // tetap tgl 10 -- supaya pelanggan tidak "dihukum" krn bayar cepat.
            if (!empty($ctx['monthversary_follow_last_payment']) && $waktu_terakhir_bayar) {
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
                // (grace period prabayar) yang sudah dikonfigurasi.
                $firstDueDate = $TANGGALPASANG;
                $jatuh_tempo_str = $firstDueDate;
                $batasIsolirBaru = ($prabayar_grace_period > 0)
                    ? date('Y-m-d', strtotime("+{$prabayar_grace_period} days", strtotime($firstDueDate)))
                    : $firstDueDate;
                if (strtotime($batasIsolirBaru) <= strtotime($hari_ini)) {
                    $belum_bayar = true;
                    $keterangan = "Belum pernah bayar sejak pasang: $TANGGALPASANG | Waktu tunggu: $prabayar_grace_period hari";
                }
            } elseif (tagihanIsSamePeriodAsToday($TANGGALPASANG, $hari_ini) || tagihanIsSamePeriodAsToday($referenceDate, $hari_ini)) {
                // baru pasang/bayar bulan ini
            } else {
                $firstDueDate = tagihanGetFirstDueDateFixed($referenceDate, $anchorDay);
                $jatuh_tempo_str = $firstDueDate ?? '';

                $batasIsolir = $firstDueDate;
                if ($TIPE_BAYAR === 'prabayar' && !empty($firstDueDate) && $prabayar_grace_period > 0) {
                    $batasIsolir = date('Y-m-d', strtotime("+{$prabayar_grace_period} days", strtotime($firstDueDate)));
                }

                if (empty($firstDueDate) || strtotime($batasIsolir) > strtotime($hari_ini)) {
                    // jatuh tempo (+ waktu tunggu untuk prabayar) belum lewat
                } else {
                    $bulanTunggak = tagihanCountConsecutiveMissedMonths($conn, $IDPEL, $firstDueDate, $hari_ini, true, $anchorDay);
                    if ($bulanTunggak >= 1) {
                        $belum_bayar = true;
                        $keterangan = "Terakhir bayar: $referenceDate | Jatuh tempo: $firstDueDate | Nunggak: $bulanTunggak bulan";
                    }
                }
            }
        } elseif ($TIPE_TEMPO === 'mengikuti_tanggal_bayar') {
            $referenceDate = tagihanComputeRollingReferenceDate($conn, $IDPEL, $TANGGALPASANG);
            $rollingOverride = tagihanGetRollingOverrideDueDate($TANGGAL_MONTHVERSARY, $hari_ini);

            if ($TIPE_BAYAR === 'prabayar' && empty($waktu_terakhir_bayar)) {
                // Prabayar yang BELUM PERNAH bayar sama sekali (baru pasang): jatuh
                // tempo pertama = tanggal pasang itu sendiri, bukan +1 bulan.
                $firstDueDate = $TANGGALPASANG;
                $jatuh_tempo_str = $firstDueDate;
                $batasIsolirBaru = ($prabayar_grace_period > 0)
                    ? date('Y-m-d', strtotime("+{$prabayar_grace_period} days", strtotime($firstDueDate)))
                    : $firstDueDate;
                if (strtotime($batasIsolirBaru) <= strtotime($hari_ini)) {
                    $belum_bayar = true;
                    $keterangan = "Belum pernah bayar sejak pasang: $TANGGALPASANG | Waktu tunggu: $prabayar_grace_period hari";
                }
            } elseif (tagihanIsSamePeriodAsToday($TANGGALPASANG, $hari_ini) || tagihanIsSamePeriodAsToday($referenceDate, $hari_ini)) {
                // baru pasang/bayar bulan ini
            } else {
                $firstDueDate = $rollingOverride ?? date('Y-m-d', strtotime('+30 days', strtotime($referenceDate)));
                $jatuh_tempo_str = $firstDueDate;

                if (strtotime($firstDueDate) > strtotime($hari_ini)) {
                    // jatuh tempo belum lewat
                } else {
                    $bulanTunggak = tagihanCountConsecutiveMissedMonths($conn, $IDPEL, $firstDueDate, $hari_ini, false, 0);
                    if ($bulanTunggak >= 1) {
                        $belum_bayar = true;
                        $keterangan = "Terakhir bayar: $referenceDate | Jatuh tempo: $firstDueDate | Nunggak: $bulanTunggak bulan";
                    }
                }
            }
        } elseif ($TIPE_TEMPO === 'mengikuti_tanggal_tempo') {
            $referenceDate = $waktu_terakhir_bayar ? substr((string) $waktu_terakhir_bayar, 0, 10) : $TANGGALPASANG;

            if ($TIPE_BAYAR === 'prabayar' && empty($waktu_terakhir_bayar)) {
                // Prabayar yang BELUM PERNAH bayar sama sekali (baru pasang): jatuh
                // tempo pertama = tanggal pasang itu sendiri, bukan menunggu hari
                // jatuh tempo global ($jatuh_tempo_hari) yang bisa saja masih jauh.
                $firstDueDate = $TANGGALPASANG;
                $jatuh_tempo_str = $firstDueDate;
                $batasIsolirBaru = ($prabayar_grace_period > 0)
                    ? date('Y-m-d', strtotime("+{$prabayar_grace_period} days", strtotime($firstDueDate)))
                    : $firstDueDate;
                if (strtotime($batasIsolirBaru) <= strtotime($hari_ini)) {
                    $belum_bayar = true;
                    $keterangan = "Belum pernah bayar sejak pasang: $TANGGALPASANG | Waktu tunggu: $prabayar_grace_period hari";
                }
            } elseif (tagihanIsSamePeriodAsToday($TANGGALPASANG, $hari_ini) || tagihanIsSamePeriodAsToday($referenceDate, $hari_ini)) {
                // baru pasang/bayar bulan ini
            } else {
                $firstDueDate = tagihanGetFirstDueDateFixed($referenceDate, $jatuh_tempo_hari);
                if ($TIPE_BAYAR === 'prabayar') {
                    $fixedDueByUsage = tagihanGetFirstDueDateFixedByUsagePeriod($penggunaan_terakhir_berhasil, $jatuh_tempo_hari);
                    if (!empty($fixedDueByUsage)) {
                        $firstDueDate = $fixedDueByUsage;
                    }
                }
                $jatuh_tempo_str = $firstDueDate ?? '';

                if (empty($firstDueDate) || strtotime($firstDueDate) > strtotime($hari_ini)) {
                    // jatuh tempo belum lewat
                } else {
                    $bulanTunggak = tagihanCountConsecutiveMissedMonths($conn, $IDPEL, $firstDueDate, $hari_ini, true, $jatuh_tempo_hari);
                    if ($bulanTunggak >= 1) {
                        $belum_bayar = true;
                        $keterangan = "Terakhir bayar: $referenceDate | Jatuh tempo: $firstDueDate | Nunggak: $bulanTunggak bulan";
                    }
                }
            }
        } else {
            // Fallback (harusnya tidak pernah ke sini karena TIPE_TEMPO sudah dinormalisasi di atas)
            if (!empty($TEMPO) && $TEMPO <= $hari_ini) {
                $sudahBayarSetelahTempo = false;
                if ($waktu_terakhir_bayar !== null) {
                    $tanggalBayarTerakhir = substr((string) $waktu_terakhir_bayar, 0, 10);
                    $sudahBayarSetelahTempo = ($tanggalBayarTerakhir >= $TEMPO);
                }
                if (!$sudahBayarSetelahTempo) {
                    $belum_bayar = true;
                    $jatuh_tempo_str = $TEMPO;
                    $keterangan = "Fallback | TEMPO habis: $TEMPO";
                }
            } elseif ($waktu_terakhir_bayar !== null) {
                $jatuh_tempo_calc = date('Y-m-d', strtotime('+30 days', strtotime((string) $waktu_terakhir_bayar)));
                $jatuh_tempo_str = $jatuh_tempo_calc;
                if ($hari_ini > $jatuh_tempo_calc) {
                    $belum_bayar = true;
                    $keterangan = "Fallback | Terakhir bayar: $waktu_terakhir_bayar | JT: $jatuh_tempo_calc";
                }
            } elseif ($TANGGALPASANG <= $hari_ini) {
                $belum_bayar = true;
                $jatuh_tempo_str = $TANGGALPASANG;
                $keterangan = "Fallback | Belum pernah bayar sejak pasang: $TANGGALPASANG";
            }
        }

        return [
            'sudah_bayar' => !$belum_bayar,
            'keterangan' => $keterangan,
            'jatuh_tempo' => $jatuh_tempo_str,
        ];
    }
}

if (!function_exists('tagihanHitungJatuhTempoBerikutnya')) {
    /**
     * Sama seperti tagihanHitungStatus(), tapi CUMA menghitung tanggal jatuh
     * tempo berikutnya utk ditampilkan (mis. tables.php) -- TANPA short-circuit
     * "baru pasang/bayar bulan ini" yang dipakai tagihanHitungStatus() (di sana
     * jatuh_tempo sengaja dikosongkan kalau periode berjalan sudah lunas, karena
     * fungsi itu cuma peduli status sudah/belum bayar utk isolir). Di sini
     * tanggalnya SELALU dihitung, terlepas dari status lunas periode berjalan.
     *
     * $pel/$ctx sama persis dgn tagihanHitungStatus().
     * Return: tanggal 'Y-m-d', atau '' kalau tidak bisa dihitung.
     */
    function tagihanHitungJatuhTempoBerikutnya(mysqli $conn, array $pel, array $ctx): string
    {
        $TANGGALPASANG = (string) $pel['TANGGALPASANG'];
        $TANGGAL_MONTHVERSARY = (string) ($pel['TANGGAL_MONTHVERSARY'] ?? '');
        $TIPE_BAYAR = strtolower(trim((string) $pel['TIPE_BAYAR']));
        $TIPE_TEMPO_RAW = strtolower(trim((string) ($pel['TIPE_TEMPO'] ?? '')));
        if ($TIPE_TEMPO_RAW === 'mengikuti_tanggal_bayar') {
            $TIPE_TEMPO = 'mengikuti_tanggal_bayar';
        } elseif ($TIPE_TEMPO_RAW === 'monthversary') {
            $TIPE_TEMPO = 'monthversary';
        } else {
            $TIPE_TEMPO = 'mengikuti_tanggal_tempo';
        }
        $TEMPO = (string) ($pel['TEMPO'] ?? '');

        $jatuh_tempo_hari = (int) ($ctx['jatuh_tempo_hari'] ?? 25);
        $lastPaymentMap = $ctx['lastPaymentMap'] ?? [];
        $lastPaidUsageMap = $ctx['lastPaidUsageMap'] ?? [];

        $IDPEL = (string) ($pel['IDPEL'] ?? '');
        $waktu_terakhir_bayar = $lastPaymentMap[$IDPEL] ?? null;
        $penggunaan_terakhir_berhasil = trim((string) ($lastPaidUsageMap[$IDPEL] ?? ''));
        $referenceDate = $waktu_terakhir_bayar ? substr((string) $waktu_terakhir_bayar, 0, 10) : $TANGGALPASANG;

        if ($TIPE_BAYAR === 'prabayar' && empty($waktu_terakhir_bayar) && !empty($TANGGALPASANG)) {
            // Prabayar yang BELUM PERNAH bayar sama sekali: jatuh tempo berikutnya
            // (utk ditampilkan) = tanggal pasang itu sendiri, SAMA di semua mode --
            // selaras dgn tagihanHitungStatus() / cek_tagihan_harian.php (bayar DI
            // MUKA, bukan menunggu +1 siklus/bulan dari tanggal pasang).
            return $TANGGALPASANG;
        }

        if ($TIPE_TEMPO === 'mengikuti_tanggal_bayar') {
            if (empty($TANGGALPASANG) || strtotime($TANGGALPASANG) === false) return '';
            $rollingOverride = tagihanGetRollingOverrideDueDate($TANGGAL_MONTHVERSARY, date('Y-m-d'));
            if ($rollingOverride !== null) {
                return $rollingOverride;
            }
            $rollingReference = tagihanComputeRollingReferenceDate($conn, $IDPEL, $TANGGALPASANG);
            return date('Y-m-d', strtotime('+30 days', strtotime($rollingReference)));
        }

        if ($TIPE_TEMPO === 'monthversary') {
            $anchorDate = $TANGGAL_MONTHVERSARY !== '' ? $TANGGAL_MONTHVERSARY : $TANGGALPASANG;
            $anchorDay = (int) date('j', strtotime($anchorDate));
            // Asimetris mirip Rolling Due Date -- lihat penjelasan di tagihanHitungStatus().
            if (!empty($ctx['monthversary_follow_last_payment']) && $waktu_terakhir_bayar) {
                $lastPaymentDay = (int) date('j', strtotime($referenceDate));
                if ($lastPaymentDay > $anchorDay) {
                    $anchorDay = $lastPaymentDay;
                }
            }
            return (string) (tagihanGetFirstDueDateFixed($referenceDate, $anchorDay) ?? '');
        }

        if ($TIPE_TEMPO === 'mengikuti_tanggal_tempo') {
            $firstDueDate = tagihanGetFirstDueDateFixed($referenceDate, $jatuh_tempo_hari);
            if ($TIPE_BAYAR === 'prabayar') {
                $fixedDueByUsage = tagihanGetFirstDueDateFixedByUsagePeriod($penggunaan_terakhir_berhasil, $jatuh_tempo_hari);
                if (!empty($fixedDueByUsage)) {
                    $firstDueDate = $fixedDueByUsage;
                }
            }
            return (string) ($firstDueDate ?? '');
        }

        // Fallback (sama seperti cabang fallback tagihanHitungStatus()).
        if (!empty($TEMPO)) {
            return $TEMPO;
        }
        if ($waktu_terakhir_bayar !== null) {
            return date('Y-m-d', strtotime('+30 days', strtotime((string) $waktu_terakhir_bayar)));
        }
        return $TANGGALPASANG;
    }
}

if (!function_exists('tagihanResolvePeriodeTercatat')) {
    /**
     * Label periode (transaksi.PENGUNAAN) utk pelanggan Fixed Due Date
     * (mengikuti_tanggal_tempo), dari bulan/tahun tanggal jatuh tempo itu sendiri.
     *
     * Setting "Periode Tercatat" (Payment Setting -> Konfigurasi Fixed Due Date):
     * - 'berjalan' (default): periode = bulan yang SAMA dengan bulan jatuh tempo
     *   (mis. jatuh tempo 25 Agustus 2026 -> periode "Agustus 2026").
     * - 'berikutnya': periode = 1 bulan SETELAH bulan jatuh tempo
     *   (mis. jatuh tempo 25 Agustus 2026 -> periode "September 2026").
     *
     * Dipakai di SEMUA titik yang menuliskan PENGUNAAN utk Fixed Due Date supaya
     * rumusnya satu tempat: invoice_generator_penagihan_*.php, manual_generate_invoice.php,
     * create_invoice_pelanggan.php, Transaction.php, dan fallback periode di portal_bayar.php.
     */
    function tagihanResolvePeriodeTercatat(int $dueMonth, int $dueYear, string $mode): string
    {
        $namaBulan = [
            1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
        ];

        $bulan = $dueMonth;
        $tahun = $dueYear;

        if ($mode === 'berikutnya') {
            $bulan++;
            if ($bulan > 12) {
                $bulan = 1;
                $tahun++;
            }
        }

        $bulan = max(1, min(12, $bulan));

        return $namaBulan[$bulan] . ' ' . $tahun;
    }
}

if (!function_exists('tagihanLoadPeriodeTercatatMode')) {
    /**
     * Baca setting "Periode Tercatat" dari reminder-{akun}.json (key 'periode_tercatat',
     * disimpan di index [0] array reminder, sama seperti 'jatuh_tempo'/'prorate_untuk_telat').
     * Default 'berjalan' kalau file/​key belum ada -- kompatibel dgn akun lama yang belum
     * pernah menyentuh setting ini (tidak mengubah perilaku billing existing).
     */
    function tagihanLoadPeriodeTercatatMode(string $reminderConfigPath): string
    {
        if (!file_exists($reminderConfigPath)) {
            return 'berjalan';
        }
        $cfg = json_decode(file_get_contents($reminderConfigPath), true);
        if (is_array($cfg) && isset($cfg[0]['periode_tercatat']) && $cfg[0]['periode_tercatat'] === 'berikutnya') {
            return 'berikutnya';
        }
        return 'berjalan';
    }
}

if (!function_exists('tagihanFallbackPeriodeLabel')) {
    /**
     * Label periode (PENGUNAAN) fallback yang TIPE_TEMPO-aware, dipakai callback
     * payment gateway (Tripay/Xendit/Midtrans/Duitku/Faspay/iPaymu/DompetX/Doku/
     * Pronpay) HANYA kalau baris pending (PERMINTAAN KODE) yang sedang dilunasi
     * TIDAK punya PENGUNAAN tersimpan (edge case -- baris itu seharusnya SELALU
     * menang duluan, lihat komentar "$pendingPengunaan" di tiap file callback).
     *
     * SEBELUMNYA tiap callback punya heuristik tutup-buku generik sendiri
     * ("$tanggal_awal_tutup_buku"/"$tanggal_akhir_tutup_buku") yang tidak sadar
     * TIPE_TEMPO/Periode Tercatat sama sekali -- bisa menghasilkan label yang
     * beda bulan dari siklus jatuh tempo pelanggan yang sebenarnya (mis. bayar
     * 2 Agustus tapi tercatat "September").
     *
     * Rumus per TIPE_TEMPO (SAMA dgn yang dipakai tables.php/notif reminder WA):
     * - mengikuti_tanggal_tempo (Fixed Due Date): due date dihitung dari
     *   jatuh_tempo_hari, LALU label-nya ikut setting Periode Tercatat
     *   (tagihanResolvePeriodeTercatat) -- satu-satunya mode yang Periode
     *   Tercatat berlaku (lihat dokumentasi settingnya sendiri).
     * - mengikuti_tanggal_bayar (Rolling) / monthversary: due date per-pelanggan
     *   (siklus 30 hari / anchor tanggal pasang), labelnya LANGSUNG bulan-tahun
     *   dari due date itu -- Periode Tercatat TIDAK berlaku utk mode ini.
     *
     * $pel/$ctx sama persis dgn tagihanHitungJatuhTempoBerikutnya(), ditambah
     * $ctx['periode_tercatat_mode'] ('berjalan'/'berikutnya').
     * Return: label "Bulan Tahun" Indonesia, atau '' kalau gagal dihitung.
     */
    function tagihanFallbackPeriodeLabel(mysqli $conn, array $pel, array $ctx): string
    {
        $TIPE_TEMPO_RAW = strtolower(trim((string) ($pel['TIPE_TEMPO'] ?? '')));
        $isFixedDueDate = ($TIPE_TEMPO_RAW !== 'mengikuti_tanggal_bayar' && $TIPE_TEMPO_RAW !== 'monthversary');

        if ($isFixedDueDate) {
            // PENTING: Fixed Due Date WAJIB forward-looking (hari ini vs jatuh_tempo_hari),
            // BUKAN lewat tagihanHitungJatuhTempoBerikutnya()/tagihanGetFirstDueDateFixedByUsagePeriod()
            // -- fungsi itu backward-looking (based on histori PEMBAYARAN TERAKHIR yang lunas)
            // utk pelanggan prabayar, jadi bisa "tertinggal" 1 bulan dari siklus kalender yang
            // sedang berjalan kalau pelanggan bayar cepat/di awal siklus (mis. dueDate histori
            // masih Juli walau hari ini sudah masuk siklus Agustus). Sama persis pelajaran &
            // rumus yang sudah dipakai notif_remainder_pembayaran*.php (lihat catatan
            // tagihanResolvePeriodeTercatat() di atas) -- "hari ini <= jatuh_tempo_hari" berarti
            // masih siklus bulan berjalan, kalau sudah lewat berarti sudah masuk siklus bulan
            // berikutnya. TIDAK butuh histori pembayaran sama sekali.
            $mode = (string) ($ctx['periode_tercatat_mode'] ?? 'berjalan');
            $jatuhTempoHari = (int) ($ctx['jatuh_tempo_hari'] ?? 25);
            if ($jatuhTempoHari < 1 || $jatuhTempoHari > 28) {
                $jatuhTempoHari = 25;
            }
            $todayTs = strtotime(date('Y-m-d'));
            $dueMonthTs = ((int) date('j', $todayTs) <= $jatuhTempoHari)
                ? $todayTs
                : strtotime('+1 month', $todayTs);
            $dueMonth = (int) date('n', $dueMonthTs);
            $dueYear = (int) date('Y', $dueMonthTs);
            return tagihanResolvePeriodeTercatat($dueMonth, $dueYear, $mode);
        }

        // Rolling/Monthversary: TIDAK ada setting Periode Tercatat, label tetap ikut
        // due date per-pelanggan (siklus 30 hari/anchor tanggal pasang) -- backward-looking
        // di sini justru BENAR krn siklusnya sendiri memang ditentukan dari histori bayar.
        $dueDate = tagihanHitungJatuhTempoBerikutnya($conn, $pel, $ctx);
        if ($dueDate === '' || strtotime($dueDate) === false) {
            return '';
        }
        return tagihanBulanTahunIndo($dueDate, 0);
    }
}

if (!function_exists('tagihanTerapkanDiskonBiayaTambahan')) {
    /**
     * Terapkan diskon & tambahan biaya pelanggan (mode 'per_pelanggan' atau
     * 'global' -- scope server/odp utk diskon, server/area/paket utk biaya --
     * dan PERIODE_TYPE 'bulanan'/'rentang'/'permanen') ke total tagihan yang
     * sudah ada. Diekstrak dari logika yang sebelumnya cuma ada di
     * cek_sesi.php (broadband/) supaya bisa dipanggil ULANG di portal_bayar.php
     * saat ada baris PENAGIHAN aktif yang menimpa $totalTagihan/$tagihanDetail
     * hasil hitungan cek_sesi.php -- SEBELUMNYA diskon/biaya tambahan hilang
     * begitu saja di kasus itu (kasus paling umum: pelanggan punya tagihan
     * berjalan), jadi efeknya tidak pernah sampai ke nominal yang dibayar.
     *
     * PENTING: fungsi ini TIDAK memastikan tabel/kolom (CREATE TABLE/ALTER)
     * -- itu tetap tanggung jawab cek_sesi.php (jalan sekali di awal request
     * lewat flag file .diskon_schema.ok/.biaya_schema.ok) supaya tidak
     * mengulang cek kolom di setiap pemanggilan fungsi ini.
     *
     * @return array{total: float, extra_detail: array<int, array{keterangan:string, harga:float}>}
     */
    function tagihanTerapkanDiskonBiayaTambahan(
        mysqli $conn,
        string $pemilik,
        string $idpel,
        string $periode,
        string $area,
        string $paket,
        string $odp,
        float $totalTagihanAwal
    ): array {
        $totalTagihan = $totalTagihanAwal;
        $extraDetail = [];

        $periodeTarget = trim($periode);
        if ($idpel === '' || $pemilik === '' || $periodeTarget === '') {
            return ['total' => $totalTagihan, 'extra_detail' => $extraDetail];
        }

        $idxTarget = tagihanParseIndoMonthYear($periodeTarget);
        $idxTargetNum = $idxTarget ? ($idxTarget['year'] * 12 + $idxTarget['month']) : null;

        $isCandidateValid = static function (array $cand, ?int $idxTargetNum): bool {
            $candType = strtolower((string) ($cand['PERIODE_TYPE'] ?? 'bulanan'));
            if ($candType !== 'rentang') {
                return true;
            }
            $mulaiParsed = tagihanParseIndoMonthYear((string) ($cand['PERIODE_MULAI'] ?? ''));
            $selesaiParsed = tagihanParseIndoMonthYear((string) ($cand['PERIODE_SELESAI'] ?? ''));
            if (!$mulaiParsed || !$selesaiParsed || $idxTargetNum === null) {
                return false;
            }
            $mulaiNum = $mulaiParsed['year'] * 12 + $mulaiParsed['month'];
            $selesaiNum = $selesaiParsed['year'] * 12 + $selesaiParsed['month'];
            return $idxTargetNum >= $mulaiNum && $idxTargetNum <= $selesaiNum;
        };

        // --- Diskon ---
        $diskonSql = "SELECT MODE, GLOBAL_SCOPE, SCOPE_VALUE, GLOBAL_AREA, GLOBAL_PAKET, IDPEL, PERIODE,
                             COALESCE(PERIODE_TYPE, 'bulanan') AS PERIODE_TYPE, PERIODE_MULAI, PERIODE_SELESAI,
                             COALESCE(NOMINAL_TYPE, 'nominal') AS NOMINAL_TYPE, NOMINAL, KETERANGAN
                      FROM diskon_pelanggan
                      WHERE ACTIVE = 1
                        AND PEMILIK = ?
                        AND (
                          (MODE = 'per_pelanggan' AND IDPEL = ?)
                          OR (
                              MODE = 'global'
                              AND (
                                  (COALESCE(GLOBAL_SCOPE, 'server') = 'server' AND COALESCE(SCOPE_VALUE, PEMILIK) = ? AND (COALESCE(GLOBAL_AREA, '') = '' OR GLOBAL_AREA = ?) AND (COALESCE(GLOBAL_PAKET, '') = '' OR GLOBAL_PAKET = ?))
                                  OR (COALESCE(GLOBAL_SCOPE, 'server') = 'odp' AND SCOPE_VALUE = ?)
                              )
                          )
                        )
                        AND (
                          COALESCE(PERIODE_TYPE, 'bulanan') = 'permanen'
                          OR COALESCE(PERIODE_TYPE, 'bulanan') = 'rentang'
                          OR (COALESCE(PERIODE_TYPE, 'bulanan') = 'bulanan' AND PERIODE = ?)
                        )
                      ORDER BY CASE
                          WHEN MODE = 'per_pelanggan' THEN 0
                          WHEN MODE = 'global' AND COALESCE(GLOBAL_SCOPE, 'server') = 'server' AND COALESCE(GLOBAL_AREA, '') <> '' AND COALESCE(GLOBAL_PAKET, '') <> '' THEN 1
                          WHEN MODE = 'global' AND COALESCE(GLOBAL_SCOPE, 'server') = 'server' AND (COALESCE(GLOBAL_AREA, '') <> '' OR COALESCE(GLOBAL_PAKET, '') <> '') THEN 2
                          ELSE 3
                      END, id DESC";
        $stmtDiskon = $conn->prepare($diskonSql);
        if ($stmtDiskon) {
            $stmtDiskon->bind_param('sssssss', $pemilik, $idpel, $pemilik, $area, $paket, $odp, $periodeTarget);
            $stmtDiskon->execute();
            $resultDiskon = $stmtDiskon->get_result();
            $rowDiskon = null;
            if ($resultDiskon) {
                while ($cand = $resultDiskon->fetch_assoc()) {
                    if ($isCandidateValid($cand, $idxTargetNum)) {
                        $rowDiskon = $cand;
                        break;
                    }
                }
            }
            $stmtDiskon->close();

            if ($rowDiskon) {
                $diskonNilai = (float) ($rowDiskon['NOMINAL'] ?? 0);
                $diskonType = strtolower((string) ($rowDiskon['NOMINAL_TYPE'] ?? 'nominal'));
                $diskonKeterangan = trim((string) ($rowDiskon['KETERANGAN'] ?? ''));
                if ($diskonNilai > 0) {
                    if ($diskonType === 'persentase') {
                        $diskonNilai = min($diskonNilai, 100);
                        $diskonNominal = ($totalTagihan * $diskonNilai) / 100;
                    } else {
                        $diskonNominal = $diskonNilai;
                    }
                    $diskonNominal = min($diskonNominal, $totalTagihan);
                    if ($diskonNominal > 0) {
                        $label = 'Diskon';
                        if ($diskonType === 'persentase') {
                            $label .= ' (' . number_format($diskonNilai, 2, ',', '.') . '%)';
                        }
                        if ($diskonKeterangan !== '') {
                            $label .= ' - ' . $diskonKeterangan;
                        }
                        $extraDetail[] = ['keterangan' => $label, 'harga' => -1 * $diskonNominal];
                        $totalTagihan -= $diskonNominal;
                    }
                }
            }
        }

        // --- Tambahan Biaya ---
        $biayaSql = "SELECT MODE, GLOBAL_AREA, GLOBAL_PAKET, IDPEL, PERIODE,
                            COALESCE(PERIODE_TYPE, 'bulanan') AS PERIODE_TYPE, PERIODE_MULAI, PERIODE_SELESAI,
                            COALESCE(NOMINAL_TYPE, 'nominal') AS NOMINAL_TYPE, NOMINAL, KETERANGAN
                     FROM biaya_tambahan_pelanggan
                     WHERE ACTIVE = 1
                       AND PEMILIK = ?
                       AND (
                         (MODE = 'per_pelanggan' AND IDPEL = ?)
                         OR (MODE = 'global' AND (COALESCE(GLOBAL_AREA, '') = '' OR GLOBAL_AREA = ?) AND (COALESCE(GLOBAL_PAKET, '') = '' OR GLOBAL_PAKET = ?))
                       )
                       AND (
                         COALESCE(PERIODE_TYPE, 'bulanan') = 'permanen'
                         OR COALESCE(PERIODE_TYPE, 'bulanan') = 'rentang'
                         OR (COALESCE(PERIODE_TYPE, 'bulanan') = 'bulanan' AND PERIODE = ?)
                       )
                     ORDER BY CASE
                       WHEN MODE = 'per_pelanggan' THEN 0
                       WHEN MODE = 'global' AND COALESCE(GLOBAL_AREA, '') <> '' AND COALESCE(GLOBAL_PAKET, '') <> '' THEN 1
                       WHEN MODE = 'global' AND (COALESCE(GLOBAL_AREA, '') <> '' OR COALESCE(GLOBAL_PAKET, '') <> '') THEN 2
                       ELSE 3
                     END, id DESC";
        $stmtBiaya = $conn->prepare($biayaSql);
        if ($stmtBiaya) {
            $stmtBiaya->bind_param('sssss', $pemilik, $idpel, $area, $paket, $periodeTarget);
            $stmtBiaya->execute();
            $resultBiaya = $stmtBiaya->get_result();
            $rowBiaya = null;
            if ($resultBiaya) {
                while ($cand = $resultBiaya->fetch_assoc()) {
                    if ($isCandidateValid($cand, $idxTargetNum)) {
                        $rowBiaya = $cand;
                        break;
                    }
                }
            }
            $stmtBiaya->close();

            if ($rowBiaya) {
                $biayaNilai = (float) ($rowBiaya['NOMINAL'] ?? 0);
                $biayaType = strtolower((string) ($rowBiaya['NOMINAL_TYPE'] ?? 'nominal'));
                $biayaKeterangan = trim((string) ($rowBiaya['KETERANGAN'] ?? ''));
                if ($biayaNilai > 0) {
                    if ($biayaType === 'persentase') {
                        $biayaNilai = min($biayaNilai, 100);
                        $biayaNominal = ($totalTagihan * $biayaNilai) / 100;
                    } else {
                        $biayaNominal = $biayaNilai;
                    }
                    if ($biayaNominal > 0) {
                        $label = 'Tambahan Biaya';
                        if ($biayaType === 'persentase') {
                            $label .= ' (' . number_format($biayaNilai, 2, ',', '.') . '%)';
                        }
                        if ($biayaKeterangan !== '') {
                            $label .= ' - ' . $biayaKeterangan;
                        }
                        $extraDetail[] = ['keterangan' => $label, 'harga' => $biayaNominal];
                        $totalTagihan += $biayaNominal;
                    }
                }
            }
        }

        return ['total' => $totalTagihan, 'extra_detail' => $extraDetail];
    }
}
