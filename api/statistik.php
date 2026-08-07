<?php
// api/statistik.php - 16 Statistics like web dashboard
error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    require_once '../koneksibilling.php';
    require_once '_bootstrap.php';
    session_start();
    api_cors();

    // Diganti ke _bootstrap.php::api_authenticate() (session -> username+password ->
    // API key dari tabel `apikey`) -- sebelumnya tidak pernah baca param `key`/`api_key`.
    $method = $_SERVER['REQUEST_METHOD'];
    $input = api_read_input();

    $auth = api_authenticate($conn, $input);
    $pemilik = $auth['pemilik'];
    if ($auth['method'] === 'apikey') {
        api_rate_limit($conn, $auth['api_key']);
    }

    if ($method === 'GET') {
        // Get user_id from authenticated username
        $userIdQuery = mysqli_query($conn, "SELECT id FROM user WHERE USERNAME = '".mysqli_real_escape_string($conn, $pemilik)."'");
        $userId = null;
        if ($userIdQuery && ($userRow = mysqli_fetch_assoc($userIdQuery))) {
            $userId = $userRow['id'];
        }
        
        if (!$userId) {
            echo json_encode(['success' => false, 'error' => 'User ID tidak ditemukan']);
            exit;
        }
        
        // Get user servers and areas based on user_id
        $userServers = [];
        $userAreas = [];
        $queryServer = mysqli_query($conn, "SELECT PEMILIK, AREA FROM server WHERE user_id = ".intval($userId));
        while($row = mysqli_fetch_assoc($queryServer)) {
            $userServers[] = $row['PEMILIK'];
            if (!empty($row['AREA'])) $userAreas[] = $row['AREA'];
        }
        $userServerList = count($userServers) > 0 ? "'" . implode("','", array_map(function($x) use ($conn) { return mysqli_real_escape_string($conn, $x); }, $userServers)) . "'" : "'" . mysqli_real_escape_string($conn, $pemilik) . "'";
        $userAreaList = count($userAreas) > 0 ? "'" . implode("','", array_map(function($x) use ($conn) { return mysqli_real_escape_string($conn, $x); }, $userAreas)) . "'" : "''";

        $stat = [];
        $today = date('Y-m-d');
        $filter_bulan = (int)date('m');
        $filter_tahun = (int)date('Y');
        $bulan_ini = str_pad($filter_bulan, 2, '0', STR_PAD_LEFT);
        $senin_ini = date('Y-m-d', strtotime('monday this week'));
        $minggu_ini = date('Y-m-d', strtotime('sunday this week'));
        $awal_bulan_ini = date('Y-m-01');
        $akhir_bulan_ini = date('Y-m-t');
        $awal_tahun_ini = date('Y-01-01');
        $akhir_tahun_ini = date('Y-12-31');
        $periode_penggunaan_invoice = 'Januari 2026'; // Default, adjust based on filter
        if ($filter_bulan == 1) $bulan_nama = 'Januari';
        elseif ($filter_bulan == 2) $bulan_nama = 'Februari';
        elseif ($filter_bulan == 3) $bulan_nama = 'Maret';
        elseif ($filter_bulan == 4) $bulan_nama = 'April';
        elseif ($filter_bulan == 5) $bulan_nama = 'Mei';
        elseif ($filter_bulan == 6) $bulan_nama = 'Juni';
        elseif ($filter_bulan == 7) $bulan_nama = 'Juli';
        elseif ($filter_bulan == 8) $bulan_nama = 'Agustus';
        elseif ($filter_bulan == 9) $bulan_nama = 'September';
        elseif ($filter_bulan == 10) $bulan_nama = 'Oktober';
        elseif ($filter_bulan == 11) $bulan_nama = 'November';
        else $bulan_nama = 'Desember';
        $periode_penggunaan_invoice = $bulan_nama . ' ' . $filter_tahun;

        // 1. Total Pelanggan (AKTIF only from web version)
        $q = mysqli_query($conn, "SELECT COUNT(*) as total FROM pelanggan WHERE PEMILIK IN ($userServerList) AND AREA IN ($userAreaList)");
        $stat['total_pelanggan'] = ($q && ($r = mysqli_fetch_assoc($q))) ? (int)$r['total'] : 0;

        // 2. Sudah Bayar Bulan Ini (Status BERHASIL di periode ini)
        $periode_esc = mysqli_real_escape_string($conn, $periode_penggunaan_invoice);
        $q = mysqli_query($conn, "SELECT COUNT(*) as total FROM transaksi t INNER JOIN pelanggan p ON t.IDPEL = p.IDPEL WHERE t.STATUS = 'BERHASIL' AND t.HARGA != '0' AND t.PENGUNAAN = '$periode_esc' AND p.PEMILIK IN ($userServerList) AND p.AREA IN ($userAreaList)");
        $stat['sudah_bayar_bulan_ini'] = ($q && ($r = mysqli_fetch_assoc($q))) ? (int)$r['total'] : 0;

        // 3. Invoice Terkirim (Total invoices in period)
        $q = mysqli_query($conn, "SELECT COUNT(*) as total FROM transaksi t INNER JOIN pelanggan p ON t.IDPEL = p.IDPEL WHERE t.PENGUNAAN = '$periode_esc' AND t.HARGA != '0' AND p.PEMILIK IN ($userServerList) AND p.AREA IN ($userAreaList)");
        $stat['invoice_terkirim'] = ($q && ($r = mysqli_fetch_assoc($q))) ? (int)$r['total'] : 0;

        // 4. Belum Bayar Bulan Ini (Status PENAGIHAN)
        $q = mysqli_query($conn, "SELECT COUNT(*) as total FROM transaksi t INNER JOIN pelanggan p ON t.IDPEL = p.IDPEL WHERE t.STATUS = 'PENAGIHAN' AND t.HARGA != '0' AND t.PENGUNAAN = '$periode_esc' AND p.PEMILIK IN ($userServerList) AND p.AREA IN ($userAreaList)");
        $stat['belum_bayar_bulan_ini'] = ($q && ($r = mysqli_fetch_assoc($q))) ? (int)$r['total'] : 0;

        // 5-7. Menunggak (diselaraskan dengan pelanggan_menunggak.php)
        $trxTanggalExprNoAlias = "COALESCE(
            DATE(TANGGALBAYAR),
            STR_TO_DATE(TANGGALBAYAR, '%Y-%m-%d'),
            STR_TO_DATE(
              TRIM(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                SUBSTRING_INDEX(TANGGALBAYAR, ',', -1),
                'Januari', '01'
              ), 'Februari', '02'), 'Maret', '03'), 'April', '04'), 'Mei', '05'), 'Juni', '06'), 'Juli', '07'), 'Agustus', '08'), 'September', '09'), 'Oktober', '10'), 'November', '11'), 'Desember', '12')),
              '%d %m %Y'
            )
          )";

        $hargaPaketMap = [];
        $fasumPaketList = [];
        $qPaketMap = mysqli_query($conn, "SELECT id, PAKET, HARGA, BRAND, AREA FROM paket");
        while ($qPaketMap && ($rp = mysqli_fetch_assoc($qPaketMap))) {
            $paketKey = strtolower(trim((string)$rp['PAKET']));
            $brandKey = isset($rp['BRAND']) ? strtolower(trim((string)$rp['BRAND'])) : '';
            $areaKey = isset($rp['AREA']) ? strtolower(trim((string)$rp['AREA'])) : '';
            $mapKey = $paketKey . '|' . $brandKey . '|' . $areaKey;
            $hargaPaketMap[$mapKey] = $rp['HARGA'];
            if ($rp['HARGA'] === '' || (float)$rp['HARGA'] <= 0) {
                $fasumPaketList[$paketKey] = $rp['id'];
            }
        }

        $promoPaketIds = [];
        $qPromo = mysqli_query($conn, "SELECT paket_id FROM promo_paket");
        while ($qPromo && ($rp = mysqli_fetch_assoc($qPromo))) {
            $promoPaketIds[] = (string)$rp['paket_id'];
        }

        $fixedDueDateDay = 28;
        $safeUsername = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$pemilik);
        $reminderFile = dirname(__DIR__) . '/notifbot/data/reminder-' . $safeUsername . '.json';
        if (is_file($reminderFile)) {
            $json = @file_get_contents($reminderFile);
            $cfg = json_decode((string)$json, true);
            if (is_array($cfg) && !empty($cfg) && isset($cfg[0]['jatuh_tempo'])) {
                $d = (int)$cfg[0]['jatuh_tempo'];
                if ($d >= 1 && $d <= 31) {
                    $fixedDueDateDay = $d;
                }
            }
        }

        $isSamePeriod = function ($dateValue, $todayVal) {
            if (empty($dateValue)) return false;
            $tsDate = strtotime((string)$dateValue);
            $tsToday = strtotime((string)$todayVal);
            if ($tsDate === false || $tsToday === false) return false;
            return date('Y-m', $tsDate) === date('Y-m', $tsToday);
        };

        $resolveHarga = function ($paket, $brand, $area) use ($hargaPaketMap) {
            $k = $paket . '|' . $brand . '|' . $area;
            if (isset($hargaPaketMap[$k])) return $hargaPaketMap[$k];
            if (isset($hargaPaketMap[$paket . '||' . $area])) return $hargaPaketMap[$paket . '||' . $area];
            if (isset($hargaPaketMap[$paket . '|' . $brand . '|'])) return $hargaPaketMap[$paket . '|' . $brand . '|'];
            if (isset($hargaPaketMap[$paket . '||'])) return $hargaPaketMap[$paket . '||'];
            if (isset($hargaPaketMap[$paket])) return $hargaPaketMap[$paket];
            return null;
        };

        $isFasumNonPromo = function ($paket) use ($fasumPaketList, $promoPaketIds) {
            if ($paket === '' || !isset($fasumPaketList[$paket])) return false;
            return !in_array((string)$fasumPaketList[$paket], $promoPaketIds, true);
        };

        $buildMonthlyDate = function ($year, $month, $day) {
            $year = (int)$year;
            $month = (int)$month;
            $day = (int)$day;
            if ($year < 1970 || $month < 1 || $month > 12) return null;
            if ($day < 1) $day = 1;
            $daysInMonth = (int)date('t', strtotime(sprintf('%04d-%02d-01', $year, $month)));
            if ($day > $daysInMonth) $day = $daysInMonth;
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        };

        $parseIndoMonthYear = function ($value) {
            $raw = trim((string)$value);
            if ($raw === '') return null;
            if (!preg_match('/^([A-Za-z]+)\s+(\d{4})$/', $raw, $m)) return null;

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
            if (!isset($monthMap[$monthName]) || $year < 1970) return null;

            return ['month' => (int)$monthMap[$monthName], 'year' => $year];
        };

        $getFirstDueFixedByUsage = function ($usageValue, $fixedDay) use ($parseIndoMonthYear, $buildMonthlyDate) {
            $parsed = $parseIndoMonthYear((string)$usageValue);
            if (!$parsed) return null;
            return $buildMonthlyDate((int)$parsed['year'], (int)$parsed['month'], (int)$fixedDay);
        };

        $getReferenceDate = function ($row) {
            $lastPaid = isset($row['last_paid']) ? trim((string)$row['last_paid']) : '';
            if ($lastPaid !== '' && strtotime($lastPaid) !== false) return date('Y-m-d', strtotime($lastPaid));
            $pasang = isset($row['TANGGALPASANG']) ? trim((string)$row['TANGGALPASANG']) : '';
            if ($pasang !== '' && strtotime($pasang) !== false) return date('Y-m-d', strtotime($pasang));
            return '';
        };

        $getTempoType = function ($row) {
            return strtolower(trim((string)($row['TIPE_TEMPO'] ?? 'mengikuti_tanggal_tempo')));
        };

        // Hari jatuh tempo tetap milik pelanggan itu sendiri untuk mode "monthversary".
        // Disamakan dengan dashboard.php supaya total menunggak konsisten.
        $getMonthversaryAnchorDay = function ($row) {
            $anchorDate = trim((string)($row['TANGGAL_MONTHVERSARY'] ?? ''));
            if ($anchorDate === '' || strtotime($anchorDate) === false) {
                $anchorDate = (string)($row['TANGGALPASANG'] ?? '');
            }
            if ($anchorDate === '' || strtotime($anchorDate) === false) {
                return 28;
            }
            return (int)date('j', strtotime($anchorDate));
        };

        $shouldCount = function ($row, $todayVal) use ($isSamePeriod) {
            if ($isSamePeriod($row['TANGGALPASANG'] ?? '', $todayVal)) return false;
            if ($isSamePeriod($row['last_paid'] ?? '', $todayVal)) return false;
            return true;
        };

        $getFirstDue = function ($row, $referenceDate, $fixedDay) use ($getTempoType, $buildMonthlyDate, $getMonthversaryAnchorDay) {
            if ($referenceDate === '' || strtotime($referenceDate) === false) return null;
            $refTs = strtotime($referenceDate);
            $tempoType = $getTempoType($row);
            if ($tempoType === 'mengikuti_tanggal_bayar') {
                return date('Y-m-d', strtotime('+1 month', $refTs));
            }
            $dueDay = ($tempoType === 'monthversary') ? $getMonthversaryAnchorDay($row) : (int)$fixedDay;
            $nextMonthTs = strtotime('+1 month', $refTs);
            return $buildMonthlyDate((int)date('Y', $nextMonthTs), (int)date('m', $nextMonthTs), $dueDay);
        };

        $resolveFirstDueForRow = function ($row, $referenceDate, $fixedDay) use ($getFirstDue, $getTempoType, $getFirstDueFixedByUsage) {
            $dueDate = $getFirstDue($row, $referenceDate, $fixedDay);
            $tipeBayar = strtolower(trim((string)($row['TIPE_BAYAR'] ?? 'prabayar')));

            if ($tipeBayar === 'prabayar' && $getTempoType($row) === 'mengikuti_tanggal_tempo') {
                $dueByUsage = $getFirstDueFixedByUsage((string)($row['last_pengunaan'] ?? ''), $fixedDay);
                if (!empty($dueByUsage)) {
                    $dueDate = $dueByUsage;
                }
            }

            return $dueDate;
        };

        $getNextDue = function ($row, $currentDueDate, $fixedDay) use ($getTempoType, $buildMonthlyDate, $getMonthversaryAnchorDay) {
            if (empty($currentDueDate) || strtotime($currentDueDate) === false) return null;
            $tempoType = $getTempoType($row);
            if ($tempoType === 'mengikuti_tanggal_bayar') {
                return date('Y-m-d', strtotime('+1 month', strtotime($currentDueDate)));
            }
            $dueDay = ($tempoType === 'monthversary') ? $getMonthversaryAnchorDay($row) : (int)$fixedDay;
            $n = strtotime('+1 month', strtotime($currentDueDate));
            return $buildMonthlyDate((int)date('Y', $n), (int)date('m', $n), $dueDay);
        };

        $hasSuccessfulPaymentInPeriod = function ($idpel, $startDate, $endDate) use ($conn, $trxTanggalExprNoAlias) {
            if ($idpel === '' || $startDate === '' || $endDate === '') return false;
            if (strtotime($startDate) === false || strtotime($endDate) === false) return false;

            $sql = "SELECT 1 FROM transaksi WHERE IDPEL = '" . mysqli_real_escape_string($conn, (string)$idpel) . "' AND STATUS = 'BERHASIL' AND DATE($trxTanggalExprNoAlias) >= '" . mysqli_real_escape_string($conn, $startDate) . "' AND DATE($trxTanggalExprNoAlias) < '" . mysqli_real_escape_string($conn, $endDate) . "' LIMIT 1";
            $q = mysqli_query($conn, $sql);
            return (bool)($q && mysqli_fetch_assoc($q));
        };

        $sqlMenunggakBase = "SELECT p.IDPEL, p.NAMA, p.PAKET, p.PEMILIK, p.BRAND, p.AREA, p.NOWA, p.ALAMAT, p.EMAIL, p.TIKOR, p.ODP, p.TANGGALPASANG, p.TEMPO, p.TIPE_BAYAR, p.TIPE_TEMPO, p.TANGGAL_MONTHVERSARY, t.last_paid FROM pelanggan p LEFT JOIN (SELECT IDPEL, MAX($trxTanggalExprNoAlias) AS last_paid FROM transaksi WHERE STATUS = 'BERHASIL' GROUP BY IDPEL) t ON p.IDPEL = t.IDPEL WHERE p.PEMILIK IN ($userServerList) AND p.AREA IN ($userAreaList)";

        $uniqueMenunggak = [];
        $rsMenunggak = mysqli_query($conn, $sqlMenunggakBase);
        if ($rsMenunggak) {
            while ($row = mysqli_fetch_assoc($rsMenunggak)) {
                $paket = isset($row['PAKET']) ? strtolower(trim((string)$row['PAKET'])) : '';
                $brand = isset($row['BRAND']) ? strtolower(trim((string)$row['BRAND'])) : '';
                $area = isset($row['AREA']) ? strtolower(trim((string)$row['AREA'])) : '';
                if ($isFasumNonPromo($paket)) continue;
                $harga = $resolveHarga($paket, $brand, $area);
                if ($harga === null || (float)$harga <= 0) continue;
                if (!$shouldCount($row, $today)) continue;
                if (!empty($row['IDPEL'])) {
                    $uniqueMenunggak[(string)$row['IDPEL']] = $row;
                }
            }
        }

        $dataMenunggak = [];
        foreach ($uniqueMenunggak as $row) {
            $idpel = (string)$row['IDPEL'];
            $idpelEsc = mysqli_real_escape_string($conn, $idpel);
            $qLastPaid = mysqli_query($conn, "SELECT $trxTanggalExprNoAlias AS last_paid, PENGUNAAN AS last_pengunaan FROM transaksi WHERE IDPEL = '$idpelEsc' AND STATUS = 'BERHASIL' ORDER BY $trxTanggalExprNoAlias DESC LIMIT 1");
            $lastPaid = null;
            $lastPengunaan = null;
            if ($qLastPaid && ($rl = mysqli_fetch_assoc($qLastPaid))) {
                $lastPaid = $rl['last_paid'];
                $lastPengunaan = $rl['last_pengunaan'];
            }
            $row['last_paid'] = $lastPaid;
            $row['last_pengunaan'] = $lastPengunaan;

            if (!$shouldCount($row, $today)) continue;

            $reference = $getReferenceDate($row);
            $nextDueDate = $resolveFirstDueForRow($row, $reference, $fixedDueDateDay);
            if (empty($nextDueDate) || strtotime($nextDueDate) === false || strtotime($nextDueDate) > strtotime($today)) continue;

            $todayTs = strtotime($today);
            $isConsecutive = true;
            $bulanTunggak = 0;
            while (strtotime($nextDueDate) <= $todayTs) {
                $cycleStart = $nextDueDate;
                $cycleEnd = $getNextDue($row, $cycleStart, $fixedDueDateDay);
                if (empty($cycleEnd) || strtotime($cycleEnd) === false) {
                    break;
                }

                if ($hasSuccessfulPaymentInPeriod($idpel, $cycleStart, $cycleEnd)) {
                    $isConsecutive = false;
                    break;
                }

                $bulanTunggak++;
                $nextDueDate = $cycleEnd;
            }

            if ($isConsecutive && $bulanTunggak >= 1) {
                $row['bulan_nunggak'] = $bulanTunggak;
                $dataMenunggak[] = $row;
            }
        }

        $stat['lewat_jatuh_tempo'] = count($dataMenunggak);
        $nunggak1 = 0;
        $nunggak2 = 0;
        foreach ($dataMenunggak as $rowNunggak) {
            $bulanNunggak = (int)($rowNunggak['bulan_nunggak'] ?? 0);
            if ($bulanNunggak === 1) {
                $nunggak1++;
            } elseif ($bulanNunggak >= 2) {
                $nunggak2++;
            }
        }
        $stat['nunggak_1_bulan'] = $nunggak1;
        $stat['nunggak_2_bulan_plus'] = $nunggak2;

        // 8. Berhenti Berlangganan (bulan ini)
        $q = mysqli_query($conn, "SELECT COUNT(*) as total FROM pelanggan_berhenti WHERE pemilik IN ($userServerList) AND MONTH(tanggal_berhenti) = '$bulan_ini' AND YEAR(tanggal_berhenti) = '$filter_tahun'");
        $stat['berhenti_berlangganan'] = ($q && ($r = mysqli_fetch_assoc($q))) ? (int)$r['total'] : 0;

        // 9-12. Pemasukan (Revenue)
        $tanggal_bayar_filter_sql = "COALESCE(DATE(t.TANGGALBAYAR), STR_TO_DATE(t.TANGGALBAYAR, '%Y-%m-%d'))";

        // 9. Pemasukan Hari Ini
        $q = mysqli_query($conn, "SELECT SUM(t.HARGA) as total FROM transaksi t INNER JOIN pelanggan p ON t.IDPEL = p.IDPEL WHERE t.STATUS = 'BERHASIL' AND DATE($tanggal_bayar_filter_sql) = '$today' AND p.PEMILIK IN ($userServerList) AND p.AREA IN ($userAreaList)");
        $stat['pemasukan_hari_ini'] = ($q && ($r = mysqli_fetch_assoc($q)) && $r['total']) ? (int)$r['total'] : 0;

        // 10. Pemasukan Minggu Ini
        $q = mysqli_query($conn, "SELECT SUM(t.HARGA) as total FROM transaksi t INNER JOIN pelanggan p ON t.IDPEL = p.IDPEL WHERE t.STATUS = 'BERHASIL' AND DATE($tanggal_bayar_filter_sql) >= '$senin_ini' AND DATE($tanggal_bayar_filter_sql) <= '$minggu_ini' AND p.PEMILIK IN ($userServerList) AND p.AREA IN ($userAreaList)");
        $stat['pemasukan_minggu_ini'] = ($q && ($r = mysqli_fetch_assoc($q)) && $r['total']) ? (int)$r['total'] : 0;

        // 11. Pemasukan Bulan Ini
        $q = mysqli_query($conn, "SELECT SUM(t.HARGA) as total FROM transaksi t INNER JOIN pelanggan p ON t.IDPEL = p.IDPEL WHERE t.STATUS = 'BERHASIL' AND DATE($tanggal_bayar_filter_sql) >= '$awal_bulan_ini' AND DATE($tanggal_bayar_filter_sql) <= '$akhir_bulan_ini' AND p.PEMILIK IN ($userServerList) AND p.AREA IN ($userAreaList)");
        $stat['pemasukan_bulan_ini'] = ($q && ($r = mysqli_fetch_assoc($q)) && $r['total']) ? (int)$r['total'] : 0;

        // 12. Pemasukan Tahun Ini
        $q = mysqli_query($conn, "SELECT SUM(t.HARGA) as total FROM transaksi t INNER JOIN pelanggan p ON t.IDPEL = p.IDPEL WHERE t.STATUS = 'BERHASIL' AND DATE($tanggal_bayar_filter_sql) >= '$awal_tahun_ini' AND DATE($tanggal_bayar_filter_sql) <= '$akhir_tahun_ini' AND p.PEMILIK IN ($userServerList) AND p.AREA IN ($userAreaList)");
        $stat['pemasukan_tahun_ini'] = ($q && ($r = mysqli_fetch_assoc($q)) && $r['total']) ? (int)$r['total'] : 0;

        // 13-16. Pengeluaran (Expenses)
        // 13. Pengeluaran Hari Ini
        $q = mysqli_query($conn, "SELECT SUM(NOMINAL) as total FROM pengeluaran WHERE DATE(TANGGAL) = '$today' AND PEMILIK IN ($userServerList)");
        $stat['pengeluaran_hari_ini'] = ($q && ($r = mysqli_fetch_assoc($q)) && $r['total']) ? (int)$r['total'] : 0;

        // 14. Pengeluaran Minggu Ini
        $q = mysqli_query($conn, "SELECT SUM(NOMINAL) as total FROM pengeluaran WHERE DATE(TANGGAL) >= '$senin_ini' AND DATE(TANGGAL) <= '$minggu_ini' AND PEMILIK IN ($userServerList)");
        $stat['pengeluaran_minggu_ini'] = ($q && ($r = mysqli_fetch_assoc($q)) && $r['total']) ? (int)$r['total'] : 0;

        // 15. Pengeluaran Bulan Ini
        $q = mysqli_query($conn, "SELECT SUM(NOMINAL) as total FROM pengeluaran WHERE DATE(TANGGAL) >= '$awal_bulan_ini' AND DATE(TANGGAL) <= '$akhir_bulan_ini' AND PEMILIK IN ($userServerList)");
        $stat['pengeluaran_bulan_ini'] = ($q && ($r = mysqli_fetch_assoc($q)) && $r['total']) ? (int)$r['total'] : 0;

        // 16. Pengeluaran Tahun Ini
        $q = mysqli_query($conn, "SELECT SUM(NOMINAL) as total FROM pengeluaran WHERE DATE(TANGGAL) >= '$awal_tahun_ini' AND DATE(TANGGAL) <= '$akhir_tahun_ini' AND PEMILIK IN ($userServerList)");
        $stat['pengeluaran_tahun_ini'] = ($q && ($r = mysqli_fetch_assoc($q)) && $r['total']) ? (int)$r['total'] : 0;

        echo json_encode(['success' => true, 'data' => $stat]);
        exit;
    }
    echo json_encode(['success' => false, 'error' => 'Method tidak didukung']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
