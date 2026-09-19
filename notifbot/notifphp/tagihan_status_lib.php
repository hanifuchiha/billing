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

if (!function_exists('tagihanBuildPengunaanPeriodKeyExpr')) {
    function tagihanBuildPengunaanPeriodKeyExpr(string $alias = ''): string
    {
        $p = $alias !== '' ? $alias . '.' : '';
        return "(CAST(RIGHT({$p}PENGUNAAN, 4) AS UNSIGNED) * 100 + FIELD(LEFT({$p}PENGUNAAN, LOCATE(' ', {$p}PENGUNAAN) - 1),
            'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'))";
    }
}

if (!function_exists('tagihanGetLastPaymentDetailByUsagePeriodBulk')) {
    /**
     * Detail pembayaran BERHASIL terakhir per IDPEL (waktu, HARGA, PENGUNAAN),
     * diurutkan berdasarkan PERIODE PEMAKAIAN (kolom PENGUNAAN, format
     * "Bulan Tahun") -- BUKAN tanggal transaksi. Sebelumnya query ini
     * dijalankan PER BARIS pelanggan (N+1) di tables.php -- utk pageSize
     * besar (100) itu jadi 100 query kecil tambahan setiap kali user
     * filter+cari. Sekarang dipanggil SEKALI utk semua IDPEL yg tampil di
     * 1 halaman, pola sama seperti tagihanGetLastPaidUsageMapBulk() (self
     * join cari baris dgn "kunci periode" maksimum per IDPEL).
     */
    function tagihanGetLastPaymentDetailByUsagePeriodBulk(mysqli $conn, array $idpels): array
    {
        if (empty($idpels)) {
            return [];
        }
        $inList = tagihanBuildEscapedInList($conn, $idpels);
        $periodKeyT = tagihanBuildPengunaanPeriodKeyExpr('t');
        $periodKeyX = tagihanBuildPengunaanPeriodKeyExpr('x');
        $sql = "SELECT t.`IDPEL`, t.`waktu`, t.`HARGA`, t.`PENGUNAAN`
                FROM `transaksi` t
                WHERE t.`STATUS` = 'BERHASIL'
                    AND t.`IDPEL` IN ($inList)
                    AND $periodKeyT = (
                        SELECT MAX($periodKeyX)
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
                $map[$idpel] = [
                    'waktu' => $row['waktu'],
                    'HARGA' => $row['HARGA'],
                    'PENGUNAAN' => $row['PENGUNAAN'],
                ];
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
    function tagihanGetFirstDueDateFixedByUsagePeriod(string $penggunaan, int $fixedDueDay, string $periodeTercatatMode = 'berjalan'): ?string
    {
        $parsed = tagihanParseIndoMonthYear($penggunaan);
        if (!$parsed) {
            return null;
        }
        // FIX: selaras dengan getFirstDueDateFixedByUsagePeriod() di
        // cek_tagihan_harian_*.php -- $parsed adalah PENGUNAAN transaksi
        // BERHASIL TERAKHIR (periode yang SUDAH DIBAYAR), BUKAN periode yang
        // belum dibayar. Jarak (dlm bulan kalender) ke siklus BERIKUTNYA yang
        // belum dibayar TERGANTUNG setting Periode Tercatat, karena itu yang
        // menentukan hubungan antara label PENGUNAAN & bulan jatuh temponya:
        //  - 'berjalan' (default): due day bulan M -> PENGUNAAN "bulan M"
        //    (SAMA) -> siklus berikutnya due +1 bulan dari PENGUNAAN lunas.
        //  - 'berikutnya': due day bulan M -> PENGUNAAN "bulan M+1" -> siklus
        //    berikutnya due di bulan YANG SAMA dgn PENGUNAAN yang sudah lunas
        //    (bukan +1) -- lihat notes lengkap di getFirstDueDateFixedByUsagePeriod()
        //    versi cek_tagihan_harian_*.php.
        $monthOffset = ($periodeTercatatMode === 'berikutnya') ? 0 : 1;
        $dueMonth = (int) $parsed['month'] + $monthOffset;
        $dueYear = (int) $parsed['year'];
        if ($dueMonth > 12) {
            $dueMonth = 1;
            $dueYear++;
        } elseif ($dueMonth < 1) {
            $dueMonth = 12;
            $dueYear--;
        }
        return tagihanBuildMonthlyDate($dueYear, $dueMonth, $fixedDueDay);
    }
}

if (!function_exists('tagihanGetNextDueDateOnOrAfter')) {
    /**
     * Tanggal due-day ($fixedDueDay) TERDEKAT yang >= $referenceDate -- bisa
     * BULAN YANG SAMA kalau due day itu belum lewat relatif ke referenceDate
     * (mis. bayar 1 Agustus, due day 28 -> jatuh tempo = 28 Agustus itu
     * sendiri, BUKAN loncat ke September). BEDA dgn tagihanGetFirstDueDateFixed()
     * yang SELALU +1 bulan tanpa syarat (dirancang utk pascabayar: ditagih
     * SETELAH sebulan penuh layanan berjalan) -- utk prabayar yang bayar DI
     * MUKA, "jatuh tempo berikutnya" yang benar adalah due day terdekat
     * setelah tanggal bayar, bisa di bulan yang sama.
     */
    function tagihanGetNextDueDateOnOrAfter(string $referenceDate, int $fixedDueDay): ?string
    {
        if (empty($referenceDate) || strtotime($referenceDate) === false) return null;
        $refTs = strtotime($referenceDate);
        $year = (int) date('Y', $refTs);
        $month = (int) date('n', $refTs);
        $dueIniBulan = tagihanBuildMonthlyDate($year, $month, $fixedDueDay);
        if ($dueIniBulan !== null && strtotime($dueIniBulan) >= $refTs) {
            return $dueIniBulan;
        }
        $month++;
        if ($month > 12) { $month = 1; $year++; }
        return tagihanBuildMonthlyDate($year, $month, $fixedDueDay);
    }
}

if (!function_exists('tagihanGetFirstDueDateFixed')) {
    function tagihanGetFirstDueDateFixed(string $referenceDate, int $fixedDueDay): ?string
    {
        if (empty($referenceDate) || strtotime($referenceDate) === false) return null;
        // FIX: JANGAN strtotime('+1 month', ...) langsung -- kalau $referenceDate
        // tanggal 29/30/31 dan bulan berikutnya lebih pendek (mis. 31 Jan -> +1
        // month = 3 Maret, BUKAN Februari), PHP overflow ke bulan sesudahnya lagi.
        // Hitung nomor bulan/tahun langsung (aman dari overflow tanggal), baru +1.
        $refTs = strtotime($referenceDate);
        $year = (int) date('Y', $refTs);
        $month = (int) date('n', $refTs) + 1;
        if ($month > 12) { $month = 1; $year++; }
        return tagihanBuildMonthlyDate($year, $month, $fixedDueDay);
    }
}

if (!function_exists('tagihanGetFirstDueDateFixedWindow')) {
    /**
     * Versi FINAL utk mengikuti_tanggal_tempo (Fixed Due Date) yang benar2
     * konsisten dgn setting "Tanggal Awal/Akhir Tutup Buku" (paymentset.php)
     * -- pengganti tagihanGetFirstDueDateFixed() (SELALU +1 bulan) maupun
     * tagihanGetNextDueDateOnOrAfter() (SELALU bulan yg sama kalau due day
     * belum lewat) yang KEDUANYA terbukti salah utk salah satu dari 2 kasus
     * nyata yang dikonfirmasi:
     *   - Agus bayar 1 Agustus, due day 28 -> HARUS "28 Agustus" (bulan yg
     *     SAMA) & berstatus EXPIRED begitu tanggal itu lewat tanpa bayar lagi.
     *   - Yuda bayar 27 Agustus, due day 28 -> HARUS "28 September" (bulan
     *     BERIKUTNYA), dikonfirmasi user via AskUserQuestion.
     * Satu2nya pembeda dari kedua kasus itu adalah TANGGAL BAYAR relatif ke
     * window "Tutup Buku" (mis. FIBERQ = tgl 26-31): bayar tgl 1 (JAUH
     * sebelum window) belum "menutup" siklus bulan itu -> jatuh tempo TETAP
     * di bulan yg sama. Bayar tgl 27 (SUDAH masuk window 26-31) dianggap
     * menutup/melunasi siklus bulan itu -> jatuh tempo MAJU ke bulan
     * berikutnya. Aturan: kalau tanggal bayar >= tanggal_awal_tutup_buku
     * (atau, utk window lintas-bulan spt 24-5, >= awal ATAU <= akhir) maka
     * MAJU 1 bulan; kalau tidak, TETAP di bulan yg sama.
     * $tutupBukuAwal/$tutupBukuAkhir default 1/1 (window "tgl 1 saja") supaya
     * akun yang BELUM PERNAH eksplisit setting Tutup Buku otomatis balik ke
     * perilaku lama (SELALU maju 1 bulan, sama seperti tagihanGetFirstDueDateFixed())
     * -- 1 <= hari apa pun jadi kondisi "sudah lewat window" selalu benar.
     */
    function tagihanGetFirstDueDateFixedWindow(
        string $referenceDate,
        int $fixedDueDay,
        int $tutupBukuAwal = 1,
        int $tutupBukuAkhir = 1
    ): ?string {
        if (empty($referenceDate) || strtotime($referenceDate) === false) return null;
        $refTs = strtotime($referenceDate);
        $hariBayar = (int) date('j', $refTs);
        $year = (int) date('Y', $refTs);
        $month = (int) date('n', $refTs);

        $tutupBukuAwal = max(1, min(31, $tutupBukuAwal));
        $tutupBukuAkhir = max(1, min(31, $tutupBukuAkhir));

        if ($tutupBukuAwal <= $tutupBukuAkhir) {
            // Window normal dlm 1 bulan (mis. 26-31): sudah "menutup" siklus
            // begitu tanggal bayar >= awal window (termasuk kalau bayar
            // TELAT/setelah window berakhir -- itu pasti juga sudah menutup).
            $sudahMenutupSiklus = ($hariBayar >= $tutupBukuAwal);
        } else {
            // Window lintas bulan (mis. 24-5): tgl 24-31 ATAU tgl 1-5.
            $sudahMenutupSiklus = ($hariBayar >= $tutupBukuAwal || $hariBayar <= $tutupBukuAkhir);
        }

        $month += $sudahMenutupSiklus ? 1 : 0;
        if ($month > 12) { $month = 1; $year++; }
        return tagihanBuildMonthlyDate($year, $month, $fixedDueDay);
    }
}

if (!function_exists('tagihanGetOrAdvanceMonthversaryDueDate')) {
    /**
     * FIX #4 (2026-09-13): "Jatuh tempo berikutnya" PERMANEN utk mode
     * Monthversary -- menggantikan window formula & 2 percobaan sebelumnya
     * (tagihanComputeMonthversaryNextDueDate / ...Safe, keduanya DITARIK,
     * lihat catatan DEPRECATED di dekat definisinya) yang SELALU gagal krn
     * mencoba menebak ulang dari data mentah (histori/label) setiap kali
     * dipanggil.
     *
     * Pendekatan baru: SIMPAN checkpoint permanen di kolom
     * `pelanggan`.MV_NEXT_DUE_CACHE + MV_LAST_PROCESSED_PAYMENT, lalu setiap
     * dipanggil cukup MAJUKAN checkpoint itu TEPAT 1 bulan per pembayaran
     * BERHASIL baru yang belum diproses (bukan dihitung ulang dari nol).
     * "Maju 1 bulan per pembayaran" ini UNCONDITIONAL -- tidak ada perbandingan
     * hari-bayar-vs-anchor-day lagi sama sekali, sehingga tidak peduli
     * pelanggan bayar cepat/pas/telat, satu pembayaran BERHASIL = satu
     * kemajuan siklus. Ini valid krn checkpoint SUDAH merepresentasikan
     * "status siklus saat ini yang terkonfirmasi" -- bukan ditebak ulang dari
     * tanggal payment yang baru datang.
     *
     * Nilai awal (backfill) dihitung SEKALI dari 2 pembayaran TERAKHIR saja
     * (bukan seluruh histori -- aman utk data lama yg bolong, lihat
     * _tmp_backfill_mv_cache.php yg sudah dijalankan 2026-09-13): baseline =
     * window formula pd pembayaran KEDUA-TERAKHIR, lalu +1 bulan utk
     * pembayaran TERAKHIR. Tervalidasi ke 5 kasus nyata yg pernah bikin 2
     * percobaan sebelumnya gagal (Rizky, Nicky, Agus Setiyanto, Agus
     * Wahyudi, kasus asli lompat-2-bulan) -- SEMUA benar.
     *
     * Return null kalau pelanggan belum di-backfill (MV_NEXT_DUE_CACHE masih
     * NULL, mis. baru pindah ke mode monthversary atau belum pernah bayar) --
     * caller WAJIB fallback ke window formula lama utk kasus ini.
     */
    function tagihanGetOrAdvanceMonthversaryDueDate(mysqli $conn, string $idpel, int $anchorDay): ?string
    {
        if ($idpel === '') return null;
        $idpelEsc = $conn->real_escape_string($idpel);
        $q = $conn->query("SELECT MV_NEXT_DUE_CACHE, MV_LAST_PROCESSED_PAYMENT, TIPE_TEMPO FROM pelanggan WHERE IDPEL = '$idpelEsc' LIMIT 1");
        $row = $q ? $q->fetch_assoc() : null;
        if (!$row) {
            return null;
        }

        // SELF-HEAL (2026-09-19): pelanggan lama/import Keuangan dapat belum
        // memiliki checkpoint. Fallback lama menghitung dari tanggal bayar saja
        // sehingga pembayaran lebih awal (contoh bayar 1 Agustus, anchor 24)
        // keliru dianggap jatuh tempo 24 Agustus. Inisialisasi sekali dari
        // periode pembayaran BERHASIL terakhir; bila label historis tidak valid,
        // gunakan bulan setelah tanggal bayar. Khusus monthversary agar aturan
        // fixed-due yang memakai window tutup buku tidak berubah.
        if (empty($row['MV_NEXT_DUE_CACHE']) && strtolower(trim((string) ($row['TIPE_TEMPO'] ?? ''))) === 'monthversary') {
            $trxDateExpr = tagihanBuildTrxDateExpr();
            $payQ = $conn->query("SELECT PENGUNAAN, $trxDateExpr AS tanggal_bayar FROM transaksi WHERE IDPEL = '$idpelEsc' AND UPPER(TRIM(STATUS)) = 'BERHASIL' AND $trxDateExpr IS NOT NULL ORDER BY $trxDateExpr DESC, id DESC LIMIT 1");
            $lastPay = $payQ ? $payQ->fetch_assoc() : null;
            if ($lastPay && !empty($lastPay['tanggal_bayar'])) {
                $initialDue = tagihanGetFirstDueDateFixedByUsagePeriod((string) ($lastPay['PENGUNAAN'] ?? ''), $anchorDay, 'berjalan');
                if ($initialDue === null) {
                    $initialDue = tagihanGetFirstDueDateFixed((string) $lastPay['tanggal_bayar'], $anchorDay);
                }
                if ($initialDue !== null) {
                    $initialDueEsc = $conn->real_escape_string($initialDue);
                    $lastPayEsc = $conn->real_escape_string((string) $lastPay['tanggal_bayar']);
                    if ($conn->query("UPDATE pelanggan SET MV_NEXT_DUE_CACHE = '$initialDueEsc', MV_LAST_PROCESSED_PAYMENT = '$lastPayEsc' WHERE IDPEL = '$idpelEsc' AND MV_NEXT_DUE_CACHE IS NULL")) {
                        $row['MV_NEXT_DUE_CACHE'] = $initialDue;
                        $row['MV_LAST_PROCESSED_PAYMENT'] = (string) $lastPay['tanggal_bayar'];
                    }
                }
            }
        }

        if (empty($row['MV_NEXT_DUE_CACHE'])) {
            return null;
        }
        $cache = $row['MV_NEXT_DUE_CACHE'];
        $lastProcessed = !empty($row['MV_LAST_PROCESSED_PAYMENT']) ? $row['MV_LAST_PROCESSED_PAYMENT'] : '1970-01-01';

        $trxDateExpr = tagihanBuildTrxDateExpr();
        $lastProcessedEsc = $conn->real_escape_string($lastProcessed);
        $sql = "SELECT COUNT(*) AS jml, MAX($trxDateExpr) AS terbaru FROM transaksi WHERE IDPEL = '$idpelEsc' AND STATUS = 'BERHASIL' AND $trxDateExpr > '$lastProcessedEsc'";
        $q2 = $conn->query($sql);
        $r2 = $q2 ? $q2->fetch_assoc() : null;
        $jmlBaru = (int) ($r2['jml'] ?? 0);

        if ($jmlBaru > 0 && !empty($r2['terbaru'])) {
            $ts = strtotime($cache);
            $y = (int) date('Y', $ts);
            $m = (int) date('n', $ts) + $jmlBaru;
            while ($m > 12) { $m -= 12; $y++; }
            $newCache = tagihanBuildMonthlyDate($y, $m, $anchorDay);
            if ($newCache !== null) {
                $newCacheEsc = $conn->real_escape_string($newCache);
                $terbaruEsc = $conn->real_escape_string($r2['terbaru']);
                $conn->query("UPDATE pelanggan SET MV_NEXT_DUE_CACHE = '$newCacheEsc', MV_LAST_PROCESSED_PAYMENT = '$terbaruEsc' WHERE IDPEL = '$idpelEsc'");
                $cache = $newCache;
            }
        }
        return $cache;
    }
}

if (!function_exists('tagihanGetNextDueDateFixed')) {
    function tagihanGetNextDueDateFixed(string $currentDueDate, int $fixedDueDay): ?string
    {
        if (empty($currentDueDate) || strtotime($currentDueDate) === false) return null;
        // Fix overflow sama seperti tagihanGetFirstDueDateFixed() di atas.
        $curTs = strtotime($currentDueDate);
        $year = (int) date('Y', $curTs);
        $month = (int) date('n', $curTs) + 1;
        if ($month > 12) { $month = 1; $year++; }
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

if (!function_exists('tagihanHasSuccessfulPaymentForPengunaanMonth')) {
    /**
     * Cek apakah IDPEL punya transaksi BERHASIL dgn label PENGUNAAN persis
     * "NamaBulan Tahun" (mis. "Agustus 2026"). Dipakai
     * tagihanComputeMonthversaryNextDueDateSafe() -- lihat penjelasan lengkap
     * di sana kenapa pengecekan SATU bulan ini dipilih dibanding scan seluruh
     * histori.
     */
    function tagihanHasSuccessfulPaymentForPengunaanMonth(mysqli $conn, string $idpel, int $month, int $year): bool
    {
        $namaBulan = [
            1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
        ];
        if ($month < 1 || $month > 12 || $idpel === '') {
            return false;
        }
        $label = $namaBulan[$month] . ' ' . $year;
        $idpelEsc = $conn->real_escape_string($idpel);
        $labelEsc = $conn->real_escape_string($label);
        $sql = "SELECT 1 FROM transaksi WHERE IDPEL = '$idpelEsc' AND STATUS = 'BERHASIL' AND TRIM(UPPER(PENGUNAAN)) = TRIM(UPPER('$labelEsc')) LIMIT 1";
        $res = $conn->query($sql);
        return (bool) ($res && $res->fetch_assoc());
    }
}

if (!function_exists('tagihanComputeMonthversaryNextDueDateSafe')) {
    /**
     * ============================================================
     * DEPRECATED / JANGAN DIPAKAI (2026-09-13) -- lihat "REVERT #2" di
     * tagihanHitungStatus()/tagihanHitungJatuhTempoBerikutnya(), keduanya
     * SUDAH TIDAK memanggil fungsi ini lagi. Dibiarkan di sini cuma sbg
     * dokumentasi/riwayat percobaan, JANGAN diaktifkan ulang tanpa audit dulu.
     *
     * KENAPA DITARIK: asumsi "ADA transaksi BERHASIL dgn PENGUNAAN bulan lalu
     * = pelanggan tidak menunggak" TERNYATA SALAH -- label PENGUNAAN tidak
     * konsisten di seluruh sistem. Jalur normal (portal_bayar.php) mengisi
     * PENGUNAAN = bulan KALENDER saat bayar, tapi jalur fallback/manual (mis.
     * tagihanFallbackPeriodeLabel(), dipakai saat baris PERMINTAAN KODE tidak
     * ada -- lihat callback_xendit* RECOVERY block) bisa mengisi PENGUNAAN =
     * bulan SIKLUS YANG SEDANG DILUNASI, yang BISA LEBIH LAMA dari bulan
     * kalender pembayaran aslinya kalau pelanggan bayar telat. Kasus nyata:
     * Agus Setiyanto pasang 28 Juli 2026, transaksi PERTAMA (bayar 28 Juli,
     * manual/cash) DAN transaksi KEDUA (bayar 13 September, lewat jalur
     * RECOVERY callback Xendit) SAMA-SAMA punya PENGUNAAN "Agustus 2026" --
     * fungsi ini salah baca itu sbg "sudah ada pembayaran Agustus yang
     * SAH/tepat waktu", padahal transaksi kedua itu SENDIRI yang baru
     * melunasi siklus Agustus (telat 16 hari dari due 28 Agustus). Hasilnya
     * next due SALAH lompat ke 28 Oktober, padahal seharusnya 28 September.
     *
     * PELAJARAN: JANGAN pernah pakai label PENGUNAAN sbg sinyal "sudah lunas
     * tepat waktu atau tidak" kecuali SEMUA jalur insert transaksi di seluruh
     * codebase (termasuk future recovery/fallback manapun) dijamin memakai
     * konvensi PENGUNAAN yang SAMA PERSIS. Window formula (bandingkan hari
     * bayar vs anchor day dari SATU pembayaran terakhir) TETAP dipakai
     * sebagai satu-satunya sumber kebenaran utk sekarang, walau ada 1
     * skenario edge case yang diketahui masih salah (bayar lebih awal dari
     * anchor day, lihat awal riwayat perbaikan monthversary) -- itu risikonya
     * jauh lebih kecil & lebih predictable drpd rumus PENGUNAAN-based ini.
     * ============================================================
     *
     * FIX #3 (2026-09-13): pengganti tagihanComputeMonthversaryNextDueDate()
     * (simulasi siklus PENUH dari SELURUH histori) yang DITARIK -- terbukti
     * under-count di data produksi nyata (banyak akun riwayat transaksinya
     * bolong/tidak lengkap, mis. pelanggan 26 bulan berlangganan tapi cuma 3
     * baris transaksi BERHASIL tercatat), hasilnya jatuh tempo berikutnya
     * malah mundur jauh ke masa lalu -- lebih parah dari bug window formula
     * yang mau diperbaiki.
     *
     * Versi ini CUMA butuh 1 pengecekan RINGAN (tagihanHasSuccessfulPaymentForPengunaanMonth,
     * SATU bulan saja, bukan scan semua histori -- jauh lebih tahan thd data
     * lama yang bolong): apakah ADA pembayaran BERHASIL utk PENGUNAAN bulan
     * SEBELUM pembayaran terakhir ($referenceDate)?
     *  - ADA -> pelanggan dlm kondisi baik (tidak menunggak bulan sebelumnya),
     *    jadi pembayaran terakhir ini PASTI melunasi siklus BULAN PEMBAYARAN
     *    itu sendiri, apapun tanggal harinya dlm bulan itu (cepat/pas/telat) --
     *    next due = anchor day, 1 bulan SETELAH bulan pembayaran.
     *  - TIDAK ADA (pertama kali bayar / lagi catch-up dari menunggak) -> pakai
     *    tagihanGetFirstDueDateFixedWindow() (window formula lama, bandingkan
     *    hari bayar vs anchor day) yang sudah benar utk kasus "bayar telat
     *    lintas bulan kalender" (return null di sini, caller fallback).
     *
     * Tervalidasi thd 3 kasus nyata (2026-09-13):
     *  - Rizky Septyawan: jatuh tempo 15 Sept, bayar 13 Sept, ADA transaksi
     *    BERHASIL PENGUNAAN "Agustus 2026" -> next due 15 Oktober (benar,
     *    window formula lama salah kasih 15 September).
     *  - Nicky Surya Prastiwi: anchor 12, bayar PAS tgl 12 September, ADA
     *    PENGUNAAN "Agustus 2026" -> next due 12 Oktober -- SAMA dgn window
     *    formula lama (hari bayar 12 >= anchor 12 sudah otomatis benar), jadi
     *    tidak ada perubahan/regresi utk kasus ini.
     *  - Kasus asli "lompat 2 bulan": jatuh tempo 26 Agustus TIDAK dibayar,
     *    baru dibayar 5 September, TIDAK ADA PENGUNAAN "Agustus 2026" (memang
     *    belum lunas) -> fallback ke window formula -> next due 26 September
     *    (benar, tidak lompat ke Oktober).
     */
    function tagihanComputeMonthversaryNextDueDateSafe(mysqli $conn, string $idpel, string $referenceDate, int $anchorDay): ?string
    {
        if (empty($referenceDate) || strtotime($referenceDate) === false || $idpel === '') {
            return null;
        }
        $refTs = strtotime($referenceDate);
        $prevMonth = (int) date('n', $refTs) - 1;
        $prevYear = (int) date('Y', $refTs);
        if ($prevMonth < 1) {
            $prevMonth = 12;
            $prevYear--;
        }
        if (!tagihanHasSuccessfulPaymentForPengunaanMonth($conn, $idpel, $prevMonth, $prevYear)) {
            return null;
        }
        $curMonth = (int) date('n', $refTs) + 1;
        $curYear = (int) date('Y', $refTs);
        if ($curMonth > 12) {
            $curMonth = 1;
            $curYear++;
        }
        return tagihanBuildMonthlyDate($curYear, $curMonth, $anchorDay);
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
     * Override berlaku APA ADANYA -- baik dimajukan (grace period) maupun
     * dimundurkan (mis. admin sengaja ingin tandai pelanggan menunggak dari
     * tanggal tertentu) -- dipakai langsung sbg $firstDueDate oleh pemanggil
     * (tables.php, cek_tagihan_harian.php, dst), TERMASUK memicu isolir kalau
     * dimundurkan ke tanggal yang sudah lewat. Override otomatis "kalah" begitu
     * pelanggan bayar lagi (siklus baru dihitung dari histori pembayaran via
     * tagihanComputeRollingReferenceDate(), bukan dari kolom ini lagi) -- kalau
     * belum pernah bayar sejak override diset, nilainya tetap dipakai terus.
     */
    function tagihanGetRollingOverrideDueDate(?string $tanggalOverride, string $hariIni): ?string
    {
        // FIX: pelanggan yang belum pernah di-set "Ubah Jatuh Tempo" punya
        // TANGGAL_MONTHVERSARY = NULL di database (bukan string kosong).
        // Parameter ini sebelumnya bertipe `string` (non-nullable) -- PHP
        // TIDAK meng-coerce null jadi '' walau tanpa strict_types, jadi
        // manggil fungsi ini dengan NULL langsung Fatal error: Uncaught
        // TypeError, dan karena display_errors mati, cron/skrip berhenti
        // total tanpa pesan apa pun (kelihatan seperti "stuck"/menggantung).
        if (empty($tanggalOverride) || strtotime($tanggalOverride) === false) {
            return null;
        }
        return date('Y-m-d', strtotime($tanggalOverride));
    }
}

if (!function_exists('tagihanAdaSiklusTerlewatFixed')) {
    /**
     * Cek MENYELURUH (bukan cuma dari pembayaran terakhir) apakah ada satu
     * pun siklus jatuh tempo -- dari due date PERTAMA (TANGGALPASANG) sampai
     * SEBELUM $batasDueDate (biasanya due date hasil pembayaran TERAKHIR) --
     * yang SAMA SEKALI tidak punya transaksi BERHASIL di rentangnya.
     *
     * BEDA dgn tagihanCountConsecutiveMissedMonths(): fungsi itu berhenti &
     * return 0 begitu ketemu SATU pembayaran di siklus manapun (dirancang utk
     * menghitung "berapa bulan tunggak BERTURUT-TURUT sejak titik tertentu",
     * bukan "apakah PERNAH ada bolong di histori"). Kalau prabayar+Fixed Due
     * Date cuma dicek dari pembayaran TERAKHIR (tagihanGetFirstDueDateFixed),
     * pelanggan yang sempat bolong beberapa bulan lalu tapi kemudian bayar
     * lagi (menutup 1 siklus TERBARU) akan tetap dianggap "aman" -- padahal
     * siklus yang bolong di TENGAH histori itu tidak pernah benar-benar
     * dilunasi. Dipanggil HANYA utk kasus firstDueDate (dari pembayaran
     * terakhir) masih di masa depan -- kalau sudah lewat, jalur
     * tagihanCountConsecutiveMissedMonths() yang sudah ada tetap yang berlaku.
     *
     * @return string|null Tanggal mulai siklus BOLONG paling awal yang
     *                      ditemukan (utk dipakai sbg jatuh_tempo yang
     *                      ditampilkan/dicatat), atau null kalau tidak ada bolong.
     */
    function tagihanAdaSiklusTerlewatFixed(mysqli $conn, string $idpel, string $tanggalPasang, string $batasDueDate, int $fixedDueDay): ?string
    {
        if (empty($tanggalPasang) || strtotime($tanggalPasang) === false) return null;
        $cycleDue = tagihanGetFirstDueDateFixed($tanggalPasang, $fixedDueDay);
        $batasTs = strtotime($batasDueDate);
        $pengaman = 0;
        while ($cycleDue !== null && strtotime($cycleDue) < $batasTs && $pengaman < 120) {
            $pengaman++;
            $cycleEnd = tagihanGetNextDueDateFixed($cycleDue, $fixedDueDay);
            if ($cycleEnd === null) break;
            if (!tagihanHasSuccessfulPaymentInPeriod($conn, $idpel, $cycleDue, $cycleEnd)) {
                return $cycleDue; // siklus bolong ditemukan
            }
            $cycleDue = $cycleEnd;
        }
        return null; // tidak ada bolong, aman
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
        $periode_tercatat_mode = (string) ($ctx['periode_tercatat_mode'] ?? 'berjalan');

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
                // FIX #4 (2026-09-13): pakai checkpoint permanen (lihat
                // tagihanGetOrAdvanceMonthversaryDueDate() -- pengganti 2 percobaan
                // sebelumnya yang DITARIK krn sama-sama menebak ulang dari data mentah).
                // Fallback ke window formula lama HANYA kalau checkpoint belum ada
                // (belum di-backfill).
                $firstDueDate = tagihanGetOrAdvanceMonthversaryDueDate($conn, $IDPEL, $anchorDay)
                    ?? tagihanGetFirstDueDateFixedWindow($referenceDate, $anchorDay, $anchorDay, $anchorDay);
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
                // Patokan jatuh tempo berikutnya = TANGGAL BAYAR transaksi
                // BERHASIL terakhir, BUKAN label PENGUNAAN-nya -- selaras dgn
                // cek_tagihan_harian_FIBERQ.php. Pembeda "maju 1 bulan" vs
                // "tetap bulan yg sama" adalah window Tutup Buku (paymentset.php:
                // Tanggal Awal/Akhir Tutup Buku), BUKAN sekadar lewat/belumnya
                // due day -- tagihanGetNextDueDateOnOrAfter() (dipakai sebelum
                // ini) SELALU anggap "bulan yg sama kalau due day belum lewat",
                // yang keliru utk kasus bayar jauh SEBELUM window tutup buku tapi
                // di bulan yg due-day-nya sudah dianggap "ditutup" berikutnya --
                // lihat penjelasan lengkap & 2 contoh nyata (Agus/Yuda) di
                // tagihanGetFirstDueDateFixedWindow(). $ctx['tutup_buku_awal']/
                // ['tutup_buku_akhir'] default 1/1 (= selalu maju 1 bulan) kalau
                // caller belum mengirim nilai settingnya.
                $tutupBukuAwal = (int) ($ctx['tutup_buku_awal'] ?? 1);
                $tutupBukuAkhir = (int) ($ctx['tutup_buku_akhir'] ?? 1);
                // Override per-pelanggan lewat tombol "Ubah Jatuh Tempo" (Bulan/Tahun) --
                // HARI tetap ikut $jatuh_tempo_hari global, cuma siklus bulan/tahunnya
                // yang digeser admin. Dipakai apa adanya (maju atau mundur), sama
                // seperti mode Rolling (lihat tagihanGetRollingOverrideDueDate()).
                $fixedDueDateOverride = tagihanGetRollingOverrideDueDate($TANGGAL_MONTHVERSARY, $hari_ini);
                // FIX (2026-09-18): pakai checkpoint permanen yang SAMA dgn Monthversary
                // (tagihanGetOrAdvanceMonthversaryDueDate() -- namanya historis, tapi
                // fungsinya generik: baca MV_NEXT_DUE_CACHE, majukan SEBANYAK jumlah
                // transaksi BERHASIL baru sejak MV_LAST_PROCESSED_PAYMENT, unconditional).
                // Window formula lama (tagihanGetFirstDueDateFixedWindow) HANYA lihat
                // TANGGAL transaksi TERAKHIR, jadi kalau ada >1 transaksi BERHASIL baru
                // (mis. 2 kompensasi manual sekaligus utk 2 bulan berbeda), jatuh tempo
                // cuma maju 1 siklus padahal harusnya maju sejumlah transaksi itu --
                // kasus nyata: pelanggan Erna Sri Wulandari (airlink), 2 transaksi
                // BERHASIL tgl sama tapi PENGUNAAN beda (Agustus & September), jatuh
                // tempo salah tetap di siklus Agustus->September, seharusnya maju ke
                // Oktober. TIDAK pakai label PENGUNAAN sbg sinyal (lihat peringatan
                // DEPRECATED di tagihanComputeMonthversaryNextDueDateSafe() -- label
                // PENGUNAAN TIDAK konsisten antar jalur insert), checkpoint ini murni
                // MENGHITUNG JUMLAH transaksi baru by tanggal, bukan mencocokkan label.
                // Fallback ke window formula lama HANYA kalau checkpoint belum ada
                // (belum di-backfill utk pelanggan ini).
                $firstDueDate = $fixedDueDateOverride
                    ?? tagihanGetOrAdvanceMonthversaryDueDate($conn, $IDPEL, $jatuh_tempo_hari)
                    ?? tagihanGetFirstDueDateFixedWindow($referenceDate, $jatuh_tempo_hari, $tutupBukuAwal, $tutupBukuAkhir);
                $jatuh_tempo_str = $firstDueDate ?? '';

                if (empty($firstDueDate) || strtotime($firstDueDate) > strtotime($hari_ini)) {
                    // Jatuh tempo belum lewat.
                    //
                    // CATATAN: sempat ditambahkan cek "siklus bolong menyeluruh dari
                    // due date PERTAMA" (tagihanAdaSiklusTerlewatFixed) di sini --
                    // DITARIK BALIK krn walau niatnya benar (tangkap gap yg
                    // tertutup pembayaran belakangan), implementasinya SALAH:
                    // dia bisa nemu bolong dari BERTAHUN-TAHUN lalu (mis. siklus
                    // pertama sejak pasang) yang sebenarnya sudah "terlewati"
                    // wajar oleh puluhan pembayaran rutin sesudahnya -- pelanggan
                    // yang jelas rajin bayar jadi ditampilkan jatuh tempo di masa
                    // lampau yang absurd. Kalau mau fitur ini lagi, perlu simulasi
                    // due-date maju SATU PER SATU mengikuti tiap pembayaran secara
                    // kronologis (spt tagihanComputeRollingReferenceDate() utk
                    // Rolling), BUKAN sekadar cari cycle pertama yang kosong.
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
        $periode_tercatat_mode = (string) ($ctx['periode_tercatat_mode'] ?? 'berjalan');

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
            // FIX #4 (2026-09-13): pakai checkpoint permanen (lihat
            // tagihanGetOrAdvanceMonthversaryDueDate() -- pengganti 2 percobaan
            // sebelumnya yang DITARIK krn sama-sama menebak ulang dari data mentah).
            // Fallback ke window formula lama HANYA kalau checkpoint belum ada.
            $checkpoint = tagihanGetOrAdvanceMonthversaryDueDate($conn, $IDPEL, $anchorDay);
            return (string) ($checkpoint ?? (tagihanGetFirstDueDateFixedWindow($referenceDate, $anchorDay, $anchorDay, $anchorDay) ?? ''));
        }

        if ($TIPE_TEMPO === 'mengikuti_tanggal_tempo') {
            // Patokan = TANGGAL BAYAR transaksi BERHASIL terakhir (BUKAN label
            // PENGUNAAN). Pembeda "maju 1 bulan" vs "tetap bulan yg sama" adalah
            // window Tutup Buku (paymentset.php), BUKAN sekadar lewat/belumnya
            // due day -- lihat penjelasan lengkap & konfirmasi 2 contoh nyata
            // (Agus/Yuda) di tagihanGetFirstDueDateFixedWindow() &
            // tagihanHitungStatus(). $ctx['tutup_buku_awal']/['tutup_buku_akhir']
            // default 1/1 (= selalu maju 1 bulan) kalau caller belum mengirim
            // nilai settingnya.
            $tutupBukuAwal = (int) ($ctx['tutup_buku_awal'] ?? 1);
            $tutupBukuAkhir = (int) ($ctx['tutup_buku_akhir'] ?? 1);
            // Override per-pelanggan lewat tombol "Ubah Jatuh Tempo" (Bulan/Tahun) --
            // HARI tetap ikut $jatuh_tempo_hari global, cuma siklus bulan/tahunnya
            // yang digeser admin. Dipakai apa adanya, sama seperti mode Rolling.
            $fixedDueDateOverride = tagihanGetRollingOverrideDueDate($TANGGAL_MONTHVERSARY, date('Y-m-d'));
            if ($fixedDueDateOverride !== null) {
                return $fixedDueDateOverride;
            }
            // FIX (2026-09-18): sama dgn tagihanHitungStatus() -- pakai checkpoint
            // permanen dulu (hitung jumlah transaksi baru, bukan label PENGUNAAN),
            // fallback ke window formula HANYA kalau checkpoint belum di-backfill.
            $firstDueDate = tagihanGetOrAdvanceMonthversaryDueDate($conn, $IDPEL, $jatuh_tempo_hari)
                ?? tagihanGetFirstDueDateFixedWindow($referenceDate, $jatuh_tempo_hari, $tutupBukuAwal, $tutupBukuAkhir);
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

        // FIX (2026-09-14): Rolling/Monthversary -- PENGUNAAN WAJIB SELALU = bulan
        // KALENDER saat pelanggan bayar, TITIK, sama seperti aturan yang SUDAH
        // dipakai jalur normal portal_bayar.php ("aturan PENGUNAAN = bulan/tahun
        // SAAT pelanggan bayar, BUKAN bulan jatuh tempo invoice-nya"). Versi lama
        // di sini backward-looking (dari tagihanHitungJatuhTempoBerikutnya(), based
        // on JATUH TEMPO bukan tanggal bayar) -- SALAH dan sudah kejadian nyata
        // (pelanggan Puji Kusmiati: bayar 13 September ke-label "Februari 2026"
        // krn checkpoint jatuh tempo saat itu kebetulan salah). Fungsi ini dipanggil
        // SAAT pemrosesan pembayaran (callback gateway/manual active), jadi
        // date('Y-m-d') di titik ini SAMA dgn tanggal bayar transaksi yg sedang
        // diproses -- TIDAK perlu (dan TIDAK BOLEH) dihitung dari jatuh tempo lagi.
        return tagihanBulanTahunIndo(date('Y-m-d'), 0);
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
