<?php
// Prefetch semua tanggal bayar BERHASIL untuk sekumpulan IDPEL dalam SATU (atau
// beberapa, jika di-chunk) query, supaya pengecekan "apakah pelanggan X sudah
// bayar di periode Y" tidak perlu query database berulang per pelanggan per
// bulan tunggakan (pola N+1 lama di dashboard.php & pelanggan_menunggak.php).
//
// $trxTanggalExpr HARUS persis sama dengan ekspresi normalisasi tanggal yang
// dipakai di pemanggil (variabel $trxTanggalExprNoAlias), supaya hasil hitungan
// tunggakan tetap identik dengan versi lama -- cuma dihitung sekali per
// request, bukan per baris per bulan.

if (!function_exists('mnq_build_payment_index')) {
    function mnq_build_payment_index($conn, array $idpelList, string $trxTanggalExpr): array
    {
        $index = [];

        $idpelList = array_values(array_unique(array_filter(array_map('strval', $idpelList), static function ($v) {
            return $v !== '';
        })));
        if (empty($idpelList)) {
            return $index;
        }

        // Batasi ukuran IN() per query supaya aman untuk akun dengan puluhan
        // ribu pelanggan (hindari batas panjang query/parameter MySQL).
        foreach (array_chunk($idpelList, 2000) as $chunk) {
            $escaped = array_map(static function ($v) use ($conn) {
                return "'" . mysqli_real_escape_string($conn, $v) . "'";
            }, $chunk);

            $sql = "SELECT IDPEL, $trxTanggalExpr AS paid_date, PENGUNAAN AS pengunaan "
                 . "FROM transaksi WHERE STATUS = 'BERHASIL' AND IDPEL IN (" . implode(',', $escaped) . ")";
            $result = mysqli_query($conn, $sql);
            if (!$result) {
                continue;
            }

            while ($row = mysqli_fetch_assoc($result)) {
                $idpel = (string)($row['IDPEL'] ?? '');
                $paidDate = $row['paid_date'] ?? null;
                if ($idpel === '' || $paidDate === null || $paidDate === '') {
                    continue;
                }
                $index[$idpel][] = [
                    'date' => (string)$paidDate,
                    'pengunaan' => $row['pengunaan'] ?? null,
                ];
            }
        }

        // Urutkan tiap daftar per IDPEL dari yang TERBARU -> TERLAMA supaya
        // mnq_get_last_paid() cukup ambil elemen pertama (setara ORDER BY ...
        // DESC LIMIT 1 pada query per-baris yang lama).
        foreach ($index as $idpel => $entries) {
            usort($entries, static function ($a, $b) {
                return strcmp($b['date'], $a['date']);
            });
            $index[$idpel] = $entries;
        }

        return $index;
    }
}

if (!function_exists('mnq_has_payment_in_period')) {
    function mnq_has_payment_in_period(array $paymentIndex, string $idpel, string $startDate, string $endDate): bool
    {
        if ($idpel === '' || $startDate === '' || $endDate === '' || empty($paymentIndex[$idpel])) {
            return false;
        }

        $startTs = strtotime($startDate);
        $endTs = strtotime($endDate);
        if ($startTs === false || $endTs === false) {
            return false;
        }

        foreach ($paymentIndex[$idpel] as $entry) {
            $ts = strtotime((string)$entry['date']);
            if ($ts !== false && $ts >= $startTs && $ts < $endTs) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('mnq_get_last_paid')) {
    function mnq_get_last_paid(array $paymentIndex, string $idpel): array
    {
        if ($idpel === '' || empty($paymentIndex[$idpel])) {
            return ['last_paid' => null, 'last_pengunaan' => null];
        }

        $latest = $paymentIndex[$idpel][0];
        return [
            'last_paid' => $latest['date'],
            'last_pengunaan' => $latest['pengunaan'],
        ];
    }
}
