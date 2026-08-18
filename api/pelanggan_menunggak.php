<?php
// api/pelanggan_menunggak.php
// Auth diganti ke _bootstrap.php::api_authenticate() (session -> username+password -> API key
// dari tabel `apikey`) -- sebelumnya endpoint ini tidak pernah cek API key.
require_once '../koneksibilling.php';
require_once '_bootstrap.php';
session_start();
api_cors();

$method = $_SERVER['REQUEST_METHOD'];
$input = api_read_input();

function build_in_clause($conn, $values) {
    $safe = [];
    foreach ($values as $v) {
        $v = trim((string)$v);
        if ($v === '') continue;
        $safe[] = "'" . mysqli_real_escape_string($conn, $v) . "'";
    }
    if (empty($safe)) {
        return "''";
    }
    return implode(',', array_values(array_unique($safe)));
}

function build_upper_in_clause($conn, $values) {
    $safe = [];
    foreach ($values as $v) {
        $v = strtoupper(trim((string)$v));
        if ($v === '') continue;
        $safe[] = "'" . mysqli_real_escape_string($conn, $v) . "'";
    }
    if (empty($safe)) {
        return "''";
    }
    return implode(',', array_values(array_unique($safe)));
}

function first_non_empty($row, $keys, $default = '') {
    foreach ($keys as $k) {
        if (isset($row[$k]) && trim((string)$row[$k]) !== '') {
            return $row[$k];
        }
    }
    return $default;
}

function is_same_period_as_today($dateValue, $today) {
    if (empty($dateValue)) {
        return false;
    }
    $tsDate = strtotime((string)$dateValue);
    $tsToday = strtotime((string)$today);
    if ($tsDate === false || $tsToday === false) {
        return false;
    }
    return date('Y-m', $tsDate) === date('Y-m', $tsToday);
}

function should_count_as_menunggak_api($row, $today) {
    $tipeBayar = strtolower(trim((string)($row['TIPE_BAYAR'] ?? 'prabayar')));
    if ($tipeBayar !== 'pascabayar') {
        return true;
    }

    if (is_same_period_as_today($row['TANGGALPASANG'] ?? '', $today)) {
        return false;
    }

    if (is_same_period_as_today($row['last_paid'] ?? '', $today)) {
        return false;
    }

    return true;
}

function get_menunggak_reference_date_api($row) {
    $lastPaid = isset($row['last_paid']) ? trim((string)$row['last_paid']) : '';
    if ($lastPaid !== '' && strtotime($lastPaid) !== false) {
        return date('Y-m-d', strtotime($lastPaid));
    }

    $tanggalPasang = isset($row['TANGGALPASANG']) ? trim((string)$row['TANGGALPASANG']) : '';
    if ($tanggalPasang === '' || strtotime($tanggalPasang) === false) {
        return '';
    }

    return date('Y-m-d', strtotime($tanggalPasang));
}

function build_monthly_date_api($year, $month, $day) {
    $year = (int)$year;
    $month = (int)$month;
    $day = (int)$day;

    if ($year < 1970 || $month < 1 || $month > 12) {
        return null;
    }
    if ($day < 1) {
        $day = 1;
    }

    $daysInMonth = (int)date('t', strtotime(sprintf('%04d-%02d-01', $year, $month)));
    if ($day > $daysInMonth) {
        $day = $daysInMonth;
    }
    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

function get_tempo_type_value_api($row) {
    return strtolower(trim((string)($row['TIPE_TEMPO'] ?? 'mengikuti_tanggal_tempo')));
}

// Hari jatuh tempo tetap milik pelanggan itu sendiri untuk mode "monthversary".
// Disamakan dengan dashboard.php supaya total menunggak konsisten.
function get_monthversary_anchor_day_api($row) {
    $anchorDate = trim((string)($row['TANGGAL_MONTHVERSARY'] ?? ''));
    if ($anchorDate === '' || strtotime($anchorDate) === false) {
        $anchorDate = (string)($row['TANGGALPASANG'] ?? '');
    }
    if ($anchorDate === '' || strtotime($anchorDate) === false) {
        return 28;
    }
    return (int)date('j', strtotime($anchorDate));
}

function get_first_due_date_api($row, $referenceDate, $fixedDueDay) {
    if (empty($referenceDate) || strtotime($referenceDate) === false) {
        return null;
    }

    $tempoType = get_tempo_type_value_api($row);
    if ($tempoType === 'mengikuti_tanggal_bayar') {
        // Rolling: siklus PERSIS 30 hari kalender, bukan +1 bulan.
        return date('Y-m-d', strtotime('+30 days', strtotime($referenceDate)));
    }

    $refTs = strtotime($referenceDate);
    $year = (int)date('Y', $refTs);
    $month = (int)date('m', $refTs);
    $day = ($tempoType === 'monthversary') ? get_monthversary_anchor_day_api($row) : (int)$fixedDueDay;
    if ($day < 1 || $day > 31) {
        $day = 28;
    }

    $candidate = build_monthly_date_api($year, $month, $day);
    if (!$candidate) {
        return null;
    }

    if (strtotime($candidate) <= strtotime($referenceDate)) {
        $nextTs = strtotime('+1 month', strtotime($candidate));
        $year = (int)date('Y', $nextTs);
        $month = (int)date('m', $nextTs);
        $candidate = build_monthly_date_api($year, $month, $day);
    }

    return $candidate;
}

function is_due_date_passed_api($row, $today, $fixedDueDay) {
    $reference = get_menunggak_reference_date_api($row);
    $firstDueDate = get_first_due_date_api($row, $reference, $fixedDueDay);
    if (empty($firstDueDate) || strtotime($firstDueDate) === false) {
        return false;
    }
    return strtotime($firstDueDate) <= strtotime($today);
}

function get_fixed_due_date_day_api($username) {
    $defaultDay = 28;
    $safeUsername = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$username);
    $fileReminder = __DIR__ . '/../notifbot/data/reminder-' . $safeUsername . '.json';
    if (!is_file($fileReminder)) {
        return $defaultDay;
    }

    $json = @file_get_contents($fileReminder);
    if ($json === false || $json === '') {
        return $defaultDay;
    }

    $data = json_decode($json, true);
    if (!is_array($data) || empty($data)) {
        return $defaultDay;
    }

    $first = $data[0];
    $day = isset($first['jatuh_tempo']) ? (int)$first['jatuh_tempo'] : 0;
    if ($day < 1 || $day > 31) {
        return $defaultDay;
    }

    return $day;
}

function resolve_harga_paket_api($hargaMap, $paket, $brand, $area) {
    $key = $paket . '|' . $brand . '|' . $area;
    if (isset($hargaMap[$key])) return $hargaMap[$key];
    if (isset($hargaMap[$paket . '||' . $area])) return $hargaMap[$paket . '||' . $area];
    if (isset($hargaMap[$paket . '|' . $brand . '|'])) return $hargaMap[$paket . '|' . $brand . '|'];
    if (isset($hargaMap[$paket . '||'])) return $hargaMap[$paket . '||'];
    if (isset($hargaMap[$paket])) return $hargaMap[$paket];
    return null;
}

function is_fasum_non_promo_api($paket, $fasumPaketList, $promoPaketIds) {
    if ($paket === '' || !isset($fasumPaketList[$paket])) {
        return false;
    }
    $paketIdFasum = (string)$fasumPaketList[$paket];
    return !in_array($paketIdFasum, $promoPaketIds, true);
}

function get_next_due_date_api($row, $currentDueDate, $fixedDueDay) {
    if (empty($currentDueDate) || strtotime($currentDueDate) === false) {
        return null;
    }

    $tempoType = get_tempo_type_value_api($row);
    if ($tempoType === 'mengikuti_tanggal_bayar') {
        // Rolling: siklus PERSIS 30 hari kalender, bukan +1 bulan.
        return date('Y-m-d', strtotime('+30 days', strtotime($currentDueDate)));
    }

    $cfgDay = ($tempoType === 'monthversary') ? get_monthversary_anchor_day_api($row) : (int)$fixedDueDay;
    if ($cfgDay < 1 || $cfgDay > 31) {
        $cfgDay = 28;
    }

    $nextMonthTs = strtotime('+1 month', strtotime($currentDueDate));
    $year = (int)date('Y', $nextMonthTs);
    $month = (int)date('m', $nextMonthTs);
    return build_monthly_date_api($year, $month, $cfgDay);
}

function fetch_nunggak_data_api($conn, $sql, $hargaMap, $fasumPaketList, $promoPaketIds, $today, $fixedDueDay, &$filterCount, &$debugInfo, $queryTag) {
    $result = mysqli_query($conn, $sql);
    $data = [];
    if (!$result) {
        $debugInfo[] = "SQL error ($queryTag): " . mysqli_error($conn);
        return $data;
    }

    while ($row = mysqli_fetch_assoc($result)) {
        $paketKey = strtolower(trim((string)($row['PAKET'] ?? '')));
        $brandKey = strtolower(trim((string)($row['BRAND'] ?? '')));
        $areaKey = strtolower(trim((string)($row['AREA'] ?? '')));

        if (is_fasum_non_promo_api($paketKey, $fasumPaketList, $promoPaketIds)) {
            $filterCount['fasum']++;
            continue;
        }
        if (!should_count_as_menunggak_api($row, $today)) {
            $filterCount['menunggak']++;
            continue;
        }
        if (!is_due_date_passed_api($row, $today, $fixedDueDay)) {
            $filterCount['duedate']++;
            continue;
        }

        $resolvedHarga = resolve_harga_paket_api($hargaMap, $paketKey, $brandKey, $areaKey);
        if ($resolvedHarga !== null && (float)$resolvedHarga > 0) {
            $row['HARGA'] = (string)$resolvedHarga;
            $data[] = $row;
        } else {
            $filterCount['harga']++;
        }
    }

    return $data;
}

$auth = api_authenticate($conn, $input);
$pemilik = $auth['pemilik'];
if ($auth['method'] === 'apikey') {
    api_rate_limit($conn, $auth['api_key']);
}

switch ($method) {
    case 'GET':
        // Samakan scope web: ambil daftar PEMILIK & AREA dari tabel server berdasarkan user login.
        $search = trim($_GET['search'] ?? '');
        $searchEsc = mysqli_real_escape_string($conn, $search);
        $today = date('Y-m-d');
        $nofilter = isset($_GET['nofilter']) && $_GET['nofilter'] === '1';

        $userId = 0;
        $userStatus = '';
        $userGroup = '';
        $userServerJson = '';
        $ownerUsername = $pemilik;
        $stmtUser = $conn->prepare("SELECT id, STATUS, grup, server FROM user WHERE USERNAME=? LIMIT 1");
        $stmtUser->bind_param('s', $pemilik);
        $stmtUser->execute();
        $resUser = $stmtUser->get_result();
        if ($resUser && $resUser->num_rows > 0) {
            $userRow = $resUser->fetch_assoc();
            $userId = (int)($userRow['id'] ?? 0);
            $userStatus = strtoupper(trim((string)($userRow['STATUS'] ?? '')));
            $userGroup = trim((string)($userRow['grup'] ?? ''));
            $userServerJson = (string)($userRow['server'] ?? '');
        }
        // Simpan id akun yang BENAR-BENAR login (sebelum $userId ditimpa jadi id owner di bawah
        // utk ASSISTANT) -- dipakai utk baca pengaturan reseller milik akun ini sendiri.
        $loginUserId = $userId;

        if ($userStatus === 'ASSISTANT' && $userGroup !== '' && ctype_digit($userGroup)) {
            $stmtOwner = $conn->prepare("SELECT id, USERNAME FROM user WHERE id=? LIMIT 1");
            if ($stmtOwner) {
                $ownerId = (int)$userGroup;
                $stmtOwner->bind_param('i', $ownerId);
                $stmtOwner->execute();
                $resOwner = $stmtOwner->get_result();
                if ($resOwner && $resOwner->num_rows > 0) {
                    $ownerRow = $resOwner->fetch_assoc();
                    $userId = (int)($ownerRow['id'] ?? $userId);
                    $ownerUsername = (string)($ownerRow['USERNAME'] ?? $ownerUsername);
                }
            }
        }

        // Reseller/mitra ISP dengan filter harga aktif: HARGA yang dikembalikan API harus ikut
        // harga custom mereka, sama seperti web -- sebelumnya endpoint ini selalu kirim harga
        // mentah dari tabel paket. Baca pengaturan reseller dari akun yang BENAR-BENAR login
        // ($loginUserId), bukan owner-nya.
        require_once '../reseller_helper.php';
        $resellerSettingsMenunggak = reseller_get_settings($conn, $loginUserId);
        $GLOBALS['is_reseller'] = in_array($resellerSettingsMenunggak['assistant_role'], ['reseller', 'mitra_isp'], true);
        $GLOBALS['reseller_price_filter_enabled'] = (bool)$resellerSettingsMenunggak['price_filter_enabled'];
        $GLOBALS['reseller_id'] = $loginUserId;

        $pemilikValues = [];
        $areaValues = [];
        $debugInfo = [];

        if ($userStatus === 'ASSISTANT') {
            $debugInfo[] = "ASSISTANT role detected";
            $serverIds = json_decode($userServerJson, true);
            $debugInfo[] = "Raw user.server JSON: " . $userServerJson;
            $serverIds = is_array($serverIds) ? array_values(array_filter(array_map('intval', $serverIds))) : [];
            $debugInfo[] = "Parsed server IDs: " . json_encode($serverIds);
            
            if (!empty($serverIds)) {
                $idIn = implode(',', $serverIds);
                $resAssistantAreas = mysqli_query($conn, "SELECT AREA FROM server WHERE id IN ($idIn)");
                while ($resAssistantAreas && ($areaRow = mysqli_fetch_assoc($resAssistantAreas))) {
                    if (!empty($areaRow['AREA'])) {
                        $areaValues[] = $areaRow['AREA'];
                    }
                }
            }
            $debugInfo[] = "AREAs found: " . json_encode($areaValues);

            if (!empty($areaValues)) {
                $areaInAssistant = build_in_clause($conn, $areaValues);
                $resAssistantOwners = mysqli_query($conn, "SELECT DISTINCT PEMILIK FROM server WHERE AREA IN ($areaInAssistant)");
                while ($resAssistantOwners && ($ownerRow = mysqli_fetch_assoc($resAssistantOwners))) {
                    if (!empty($ownerRow['PEMILIK'])) {
                        $pemilikValues[] = $ownerRow['PEMILIK'];
                    }
                }
            }
            $debugInfo[] = "PEMILIKs found: " . json_encode($pemilikValues);
        } else {
            $debugInfo[] = "Non-ASSISTANT status: " . $userStatus;
        }

        if (empty($pemilikValues) || empty($areaValues)) {
            $debugInfo[] = "Fallback 1: Querying server by user_id=$userId OR PEMILIK=$ownerUsername";
            $stmtSrv = $conn->prepare("SELECT PEMILIK, AREA FROM server WHERE user_id=? OR PEMILIK=?");
            if ($stmtSrv) {
                $stmtSrv->bind_param('is', $userId, $ownerUsername);
                $stmtSrv->execute();
                $resSrv = $stmtSrv->get_result();
                while ($resSrv && ($sr = $resSrv->fetch_assoc())) {
                    if (!empty($sr['PEMILIK'])) $pemilikValues[] = $sr['PEMILIK'];
                    if (!empty($sr['AREA'])) $areaValues[] = $sr['AREA'];
                }
            }
            $debugInfo[] = "After Fallback 1 - PEMILIKs: " . json_encode($pemilikValues) . ", AREAs: " . json_encode($areaValues);
        }
        if (empty($pemilikValues)) {
            $debugInfo[] = "Fallback 2: Querying server by PEMILIK=$ownerUsername";
            $stmtSrvByOwner = $conn->prepare("SELECT PEMILIK, AREA FROM server WHERE PEMILIK=?");
            if ($stmtSrvByOwner) {
                $stmtSrvByOwner->bind_param('s', $ownerUsername);
                $stmtSrvByOwner->execute();
                $resSrvByOwner = $stmtSrvByOwner->get_result();
                while ($resSrvByOwner && ($sr = $resSrvByOwner->fetch_assoc())) {
                    if (!empty($sr['PEMILIK'])) $pemilikValues[] = $sr['PEMILIK'];
                    if (!empty($sr['AREA'])) $areaValues[] = $sr['AREA'];
                }
            }
            $debugInfo[] = "After Fallback 2 - PEMILIKs: " . json_encode($pemilikValues);
        }
        if (empty($areaValues)) {
            $debugInfo[] = "Fallback 3: Querying pelanggan AREA by PEMILIK=$ownerUsername";
            $stmtAreaByPelanggan = $conn->prepare("SELECT DISTINCT AREA FROM pelanggan WHERE PEMILIK=?");
            if ($stmtAreaByPelanggan) {
                $stmtAreaByPelanggan->bind_param('s', $ownerUsername);
                $stmtAreaByPelanggan->execute();
                $resAreaByPelanggan = $stmtAreaByPelanggan->get_result();
                while ($resAreaByPelanggan && ($ar = $resAreaByPelanggan->fetch_assoc())) {
                    if (!empty($ar['AREA'])) $areaValues[] = $ar['AREA'];
                }
            }
            $debugInfo[] = "After Fallback 3 - AREAs: " . json_encode($areaValues);
        }
        if (empty($pemilikValues)) {
            $debugInfo[] = "Final fallback: Using ownerUsername as default PEMILIK";
            $pemilikValues[] = $ownerUsername;
        }

        $pemilikIn = build_in_clause($conn, $pemilikValues);
        $areaIn = build_in_clause($conn, $areaValues);
        $pemilikInUpper = build_upper_in_clause($conn, $pemilikValues);
        $areaInUpper = build_upper_in_clause($conn, $areaValues);

        $where = "TRIM(UPPER(p.PEMILIK)) IN ($pemilikInUpper)";
        if (!empty($areaValues)) {
            $where .= " AND TRIM(UPPER(p.AREA)) IN ($areaInUpper)";
        }
        if ($search !== '') {
            $where .= " AND (p.IDPEL LIKE '%$searchEsc%' OR p.NAMA LIKE '%$searchEsc%' OR p.PAKET LIKE '%$searchEsc%' OR p.NOWA LIKE '%$searchEsc%' OR p.AREA LIKE '%$searchEsc%' OR p.ALAMAT LIKE '%$searchEsc%' OR p.TIPE_BAYAR LIKE '%$searchEsc%' OR p.TIPE_TEMPO LIKE '%$searchEsc%')";
        }

        $debugInfo[] = "Final WHERE clause: $where";

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
        while ($qPaketMap && ($r = mysqli_fetch_assoc($qPaketMap))) {
            $paketKey = strtolower(trim((string)$r['PAKET']));
            $brandKey = isset($r['BRAND']) ? strtolower(trim((string)$r['BRAND'])) : '';
            $areaKey = isset($r['AREA']) ? strtolower(trim((string)$r['AREA'])) : '';
            $hargaPaketMap[$paketKey . '|' . $brandKey . '|' . $areaKey] = $r['HARGA'];
            if (!isset($hargaPaketMap[$paketKey])) {
                $hargaPaketMap[$paketKey] = $r['HARGA'];
            }
            if ($r['HARGA'] === '' || (float)$r['HARGA'] <= 0) {
                $fasumPaketList[$paketKey] = $r['id'];
            }
        }

        $promoPaketIds = [];
        $qPromo = mysqli_query($conn, "SELECT paket_id FROM promo_paket");
        while ($qPromo && ($r = mysqli_fetch_assoc($qPromo))) {
            $promoPaketIds[] = (string)$r['paket_id'];
        }

        $sql = "SELECT p.IDPEL, p.NAMA, p.PAKET, p.PEMILIK, p.BRAND, p.AREA, p.NOWA, p.ALAMAT, p.EMAIL, p.TIKOR, p.ODP, p.TANGGALPASANG, p.TEMPO, p.TIPE_BAYAR, p.TIPE_TEMPO, p.TANGGAL_MONTHVERSARY, t.last_paid
                FROM pelanggan p
                LEFT JOIN (
                    SELECT IDPEL, MAX($trxTanggalExprNoAlias) AS last_paid
                    FROM transaksi
                    WHERE STATUS='BERHASIL'
                    GROUP BY IDPEL
                ) t ON p.IDPEL = t.IDPEL
                WHERE $where
                ORDER BY COALESCE(t.last_paid, p.TANGGALPASANG) ASC
                LIMIT 3000";
        $result = mysqli_query($conn, $sql);
            if (!$result) {
                $debugInfo[] = "SQL error (main): " . mysqli_error($conn);
            }

        if ((!$result || mysqli_num_rows($result) === 0) && !empty($areaValues)) {
            // Web parity fallback: assistant/user area scope bisa lebih valid dari owner scope.
            $debugInfo[] = "Empty result, trying fallback query by AREA only";
            $sqlFallbackByArea = "SELECT p.IDPEL, p.NAMA, p.PAKET, p.PEMILIK, p.BRAND, p.AREA, p.NOWA, p.ALAMAT, p.EMAIL, p.TIKOR, p.ODP, p.TANGGALPASANG, p.TEMPO, p.TIPE_BAYAR, p.TIPE_TEMPO, p.TANGGAL_MONTHVERSARY, t.last_paid
                               FROM pelanggan p
                               LEFT JOIN (
                                   SELECT IDPEL, MAX($trxTanggalExprNoAlias) AS last_paid
                                   FROM transaksi
                                   WHERE STATUS='BERHASIL'
                                   GROUP BY IDPEL
                               ) t ON p.IDPEL = t.IDPEL
                               WHERE TRIM(UPPER(p.AREA)) IN ($areaInUpper)
                               ORDER BY COALESCE(t.last_paid, p.TANGGALPASANG) ASC
                               LIMIT 3000";
            $result = mysqli_query($conn, $sqlFallbackByArea);
            if (!$result) {
                $debugInfo[] = "SQL error (fallback area): " . mysqli_error($conn);
            }
        }
        if ((!$result || mysqli_num_rows($result) === 0)) {
            // Safety fallback supaya data tetap muncul saat data server tidak lengkap.
            $debugInfo[] = "Empty result, trying fallback query by PEMILIK only";
            $sqlFallback = "SELECT p.IDPEL, p.NAMA, p.PAKET, p.PEMILIK, p.BRAND, p.AREA, p.NOWA, p.ALAMAT, p.EMAIL, p.TIKOR, p.ODP, p.TANGGALPASANG, p.TEMPO, p.TIPE_BAYAR, p.TIPE_TEMPO, p.TANGGAL_MONTHVERSARY, t.last_paid
                            FROM pelanggan p
                            LEFT JOIN (
                                SELECT IDPEL, MAX($trxTanggalExprNoAlias) AS last_paid
                                FROM transaksi
                                WHERE STATUS='BERHASIL'
                                GROUP BY IDPEL
                            ) t ON p.IDPEL = t.IDPEL
                            WHERE TRIM(UPPER(p.PEMILIK)) IN ($pemilikInUpper)
                            ORDER BY COALESCE(t.last_paid, p.TANGGALPASANG) ASC
                            LIMIT 3000";
            $result = mysqli_query($conn, $sqlFallback);
            if (!$result) {
                $debugInfo[] = "SQL error (fallback owner): " . mysqli_error($conn);
            }
        }
        if ((!$result || mysqli_num_rows($result) === 0)) {
            // Last resort fallback: direct owner username + area to avoid blank mobile page.
            $ownerEsc = mysqli_real_escape_string($conn, strtoupper(trim((string)$ownerUsername)));
            $debugInfo[] = "Empty result, trying final fallback by owner username / area";
            $sqlFinalFallback = "SELECT p.IDPEL, p.NAMA, p.PAKET, p.PEMILIK, p.BRAND, p.AREA, p.NOWA, p.ALAMAT, p.EMAIL, p.TIKOR, p.ODP, p.TANGGALPASANG, p.TEMPO, p.TIPE_BAYAR, p.TIPE_TEMPO, p.TANGGAL_MONTHVERSARY, t.last_paid
                               FROM pelanggan p
                               LEFT JOIN (
                                   SELECT IDPEL, MAX($trxTanggalExprNoAlias) AS last_paid
                                   FROM transaksi
                                   WHERE STATUS='BERHASIL'
                                   GROUP BY IDPEL
                               ) t ON p.IDPEL = t.IDPEL
                               WHERE TRIM(UPPER(p.PEMILIK)) = '$ownerEsc'";
            if (!empty($areaValues)) {
                $sqlFinalFallback .= " OR TRIM(UPPER(p.AREA)) IN ($areaInUpper)";
            }
            $sqlFinalFallback .= " ORDER BY COALESCE(t.last_paid, p.TANGGALPASANG) ASC LIMIT 3000";
            $result = mysqli_query($conn, $sqlFinalFallback);
            if (!$result) {
                $debugInfo[] = "SQL error (fallback final): " . mysqli_error($conn);
            }
        }

        $fixedDueDay = get_fixed_due_date_day_api($pemilik);

        $total = 0;
        $nunggak1 = 0;
        $nunggak2 = 0;
        $data = [];

        $rowCount = 0;
        $filterCount = ['menunggak' => 0, 'duedate' => 0, 'harga' => 0, 'fasum' => 0, 'consecutive' => 0];

        if ($nofilter) {
            // DIAGNOSTIC MODE: Return all pelanggan for this scope without filtering
            while ($row = mysqli_fetch_assoc($result)) {
                $rowCount++;
                $data[] = [
                    'IDPEL' => $row['IDPEL'] ?? ($row['idpel'] ?? ''),
                    'NAMA' => $row['NAMA'] ?? ($row['nama'] ?? ''),
                    'PAKET' => $row['PAKET'] ?? ($row['paket'] ?? ''),
                    'STATUS' => $row['STATUS'] ?? ($row['status'] ?? ''),
                    'AREA' => $row['AREA'] ?? ($row['area'] ?? ''),
                    'PEMILIK' => $row['PEMILIK'] ?? ($row['pemilik'] ?? ''),
                    'NOWA' => $row['NOWA'] ?? ($row['nowa'] ?? ''),
                    'HARGA' => $row['HARGA'] ?? ($row['harga'] ?? ''),
                    'TANGGALPASANG' => $row['TANGGALPASANG'] ?? ($row['tanggalpasang'] ?? ''),
                    'LAST_PAID' => $row['last_paid'] ?? '',
                    'bulan_nunggak' => 0,
                    'hari_nunggak' => 0,
                    'TIPE_BAYAR' => $row['TIPE_BAYAR'] ?? ($row['tipe_bayar'] ?? ''),
                    'TIPE_TEMPO' => $row['TIPE_TEMPO'] ?? ($row['tipe_tempo'] ?? ''),
                    'JATUH_TEMPO_DAY' => $fixedDueDay
                ];
            }
            $total = $rowCount;
        } else {
            // NORMAL MODE: mirror web logic (jatuh tempo + nunggak 1 bulan + nunggak 2 bulan + consecutive check)
            $dataJatuhTempo = fetch_nunggak_data_api(
                $conn,
                "SELECT p.IDPEL, p.NAMA, p.PAKET, p.PEMILIK, p.BRAND, p.AREA, p.NOWA, p.ALAMAT, p.EMAIL, p.TIKOR, p.ODP, p.TANGGALPASANG, p.TEMPO, p.TIPE_BAYAR, p.TIPE_TEMPO, p.TANGGAL_MONTHVERSARY, t.last_paid
                 FROM pelanggan p
                 LEFT JOIN (
                    SELECT IDPEL, MAX($trxTanggalExprNoAlias) AS last_paid
                    FROM transaksi
                    WHERE STATUS='BERHASIL'
                    GROUP BY IDPEL
                 ) t ON p.IDPEL=t.IDPEL
                 WHERE $where",
                $hargaPaketMap,
                $fasumPaketList,
                $promoPaketIds,
                $today,
                $fixedDueDay,
                $filterCount,
                $debugInfo,
                'jatuh_tempo'
            );

            $tgl1Bln = date('Y-m-d', strtotime('-1 month', strtotime($today)));
            $tgl2Bln = date('Y-m-d', strtotime('-2 month', strtotime($today)));

                        $sqlNunggak1Data = "SELECT p.*, t.last_paid
                              FROM pelanggan p
                              LEFT JOIN (
                                SELECT IDPEL, MAX($trxTanggalExprNoAlias) AS last_paid
                                FROM transaksi
                                WHERE STATUS='BERHASIL'
                                GROUP BY IDPEL
                              ) t ON p.IDPEL=t.IDPEL
                              WHERE $where
                                AND ((t.last_paid IS NULL AND p.TANGGALPASANG <= '$tgl1Bln' AND p.TANGGALPASANG > '$tgl2Bln')
                                     OR (t.last_paid IS NOT NULL AND t.last_paid <= '$tgl1Bln' AND t.last_paid > '$tgl2Bln'))";
                        $sqlNunggak2Data = "SELECT p.*, t.last_paid
                              FROM pelanggan p
                              LEFT JOIN (
                                SELECT IDPEL, MAX($trxTanggalExprNoAlias) AS last_paid
                                FROM transaksi
                                WHERE STATUS='BERHASIL'
                                GROUP BY IDPEL
                              ) t ON p.IDPEL=t.IDPEL
                              WHERE $where
                                AND ((t.last_paid IS NULL AND p.TANGGALPASANG <= '$tgl2Bln')
                                     OR (t.last_paid IS NOT NULL AND t.last_paid <= '$tgl2Bln'))";

                        $dataNunggak1Raw = fetch_nunggak_data_api($conn, $sqlNunggak1Data, $hargaPaketMap, $fasumPaketList, $promoPaketIds, $today, $fixedDueDay, $filterCount, $debugInfo, 'nunggak_1_bulan');
                        $dataNunggak2Raw = fetch_nunggak_data_api($conn, $sqlNunggak2Data, $hargaPaketMap, $fasumPaketList, $promoPaketIds, $today, $fixedDueDay, $filterCount, $debugInfo, 'nunggak_2_bulan_plus');

            $allMenunggak = array_merge($dataNunggak1Raw, $dataNunggak2Raw, $dataJatuhTempo);
            $rowCount = count($allMenunggak);

            $uniqueMenunggak = [];
            foreach ($allMenunggak as $row) {
                if (!empty($row['IDPEL'])) {
                    $uniqueMenunggak[(string)$row['IDPEL']] = $row;
                }
            }

            $filteredMenunggak = [];
            $todayTs = strtotime($today);
            foreach ($uniqueMenunggak as $row) {
                $idpel = (string)$row['IDPEL'];
                $qLastPaid = mysqli_query($conn, "SELECT MAX($trxTanggalExprNoAlias) AS last_paid FROM transaksi WHERE IDPEL='" . mysqli_real_escape_string($conn, $idpel) . "' AND STATUS='BERHASIL'");
                $lastPaid = null;
                if ($qLastPaid && ($r = mysqli_fetch_assoc($qLastPaid))) {
                    $lastPaid = $r['last_paid'];
                }

                $row['last_paid'] = $lastPaid;
                if (!should_count_as_menunggak_api($row, $today)) {
                    $filterCount['menunggak']++;
                    continue;
                }
                if (!is_due_date_passed_api($row, $today, $fixedDueDay)) {
                    $filterCount['duedate']++;
                    continue;
                }

                $isConsecutive = true;
                $referenceDate = get_menunggak_reference_date_api($row);
                $nextDueDate = get_first_due_date_api($row, $referenceDate, $fixedDueDay);
                $bulanTunggak = 0;
                if (!empty($nextDueDate) && strtotime($nextDueDate) !== false) {
                    while (strtotime($nextDueDate) <= $todayTs) {
                        $m = (int)date('m', strtotime($nextDueDate));
                        $y = (int)date('Y', strtotime($nextDueDate));
                        $q = mysqli_query($conn, "SELECT 1 FROM transaksi WHERE IDPEL='" . mysqli_real_escape_string($conn, $idpel) . "' AND STATUS='BERHASIL' AND MONTH($trxTanggalExprNoAlias)=$m AND YEAR($trxTanggalExprNoAlias)=$y");
                        if ($q && mysqli_fetch_assoc($q)) {
                            $isConsecutive = false;
                            break;
                        }
                        $bulanTunggak++;
                        $nextDueDate = get_next_due_date_api($row, $nextDueDate, $fixedDueDay);
                        if (empty($nextDueDate) || strtotime($nextDueDate) === false) {
                            break;
                        }
                    }
                }

                if (!$isConsecutive) {
                    $filterCount['consecutive']++;
                    continue;
                }

                if ($bulanTunggak > 0) {
                    $row['bulan_nunggak'] = $bulanTunggak;
                } else {
                    $reference = get_menunggak_reference_date_api($row);
                    $hariNunggak = 0;
                    if (!empty($reference) && strtotime($reference) !== false) {
                        $hariNunggak = (int)floor((strtotime($today) - strtotime($reference)) / (60 * 60 * 24));
                        if ($hariNunggak < 0) {
                            $hariNunggak = 0;
                        }
                    }
                    $row['bulan_nunggak'] = 0;
                    $row['hari_nunggak'] = $hariNunggak;
                }

                $filteredMenunggak[] = $row;
            }

            $data = $filteredMenunggak;
            $existingIds = array_column($data, 'IDPEL');
            foreach ($dataJatuhTempo as $jt) {
                if (!in_array($jt['IDPEL'], $existingIds, true)) {
                    $reference = get_menunggak_reference_date_api($jt);
                    $hariNunggak = 0;
                    if (!empty($reference) && strtotime($reference) !== false) {
                        $hariNunggak = (int)floor((strtotime($today) - strtotime($reference)) / (60 * 60 * 24));
                        if ($hariNunggak < 0) {
                            $hariNunggak = 0;
                        }
                    }
                    $bulanNunggak = (int)floor($hariNunggak / 30);
                    $jt['bulan_nunggak'] = $bulanNunggak;
                    if ($bulanNunggak === 0) {
                        $jt['hari_nunggak'] = $hariNunggak;
                    }
                    $data[] = $jt;
                }
            }

            foreach ($data as $row) {
                $bulanNunggak = (int)($row['bulan_nunggak'] ?? 0);
                if ($bulanNunggak >= 2) {
                    $nunggak2++;
                } elseif ($bulanNunggak >= 1) {
                    $nunggak1++;
                }
            }
            $total = count($data);

            $data = array_map(function($row) use ($fixedDueDay, $conn) {
                $paketNama = $row['PAKET'] ?? ($row['paket'] ?? '');
                $pemilikNama = $row['PEMILIK'] ?? ($row['pemilik'] ?? '');
                $hargaFinal = $row['HARGA'] ?? ($row['harga'] ?? '');
                if ($GLOBALS['is_reseller'] && $GLOBALS['reseller_price_filter_enabled'] && !empty($paketNama)) {
                    $effectiveHarga = reseller_effective_harga($conn, $paketNama, $pemilikNama);
                    if ($effectiveHarga > 0) {
                        $hargaFinal = (string)$effectiveHarga;
                    }
                }
                return [
                    'IDPEL' => $row['IDPEL'] ?? ($row['idpel'] ?? ''),
                    'NAMA' => $row['NAMA'] ?? ($row['nama'] ?? ''),
                    'PAKET' => $paketNama,
                    'STATUS' => $row['STATUS'] ?? ($row['status'] ?? 'MENUNGGAK'),
                    'AREA' => $row['AREA'] ?? ($row['area'] ?? ''),
                    'PEMILIK' => $pemilikNama,
                    'NOWA' => $row['NOWA'] ?? ($row['nowa'] ?? ''),
                    'HARGA' => $hargaFinal,
                    'TANGGALPASANG' => $row['TANGGALPASANG'] ?? ($row['tanggalpasang'] ?? ''),
                    'LAST_PAID' => $row['last_paid'] ?? '',
                    'bulan_nunggak' => (int)($row['bulan_nunggak'] ?? 0),
                    'hari_nunggak' => (int)($row['hari_nunggak'] ?? 0),
                    'TIPE_BAYAR' => $row['TIPE_BAYAR'] ?? ($row['tipe_bayar'] ?? ''),
                    'TIPE_TEMPO' => $row['TIPE_TEMPO'] ?? ($row['tipe_tempo'] ?? ''),
                    'JATUH_TEMPO_DAY' => $fixedDueDay
                ];
            }, $data);
        }

        usort($data, function ($a, $b) {
            return ((int)($b['bulan_nunggak'] ?? 0)) <=> ((int)($a['bulan_nunggak'] ?? 0));
        });

        echo json_encode([
            'success' => true,
            'summary' => [
                'total' => $total,
                'nunggak_1_bulan' => $nunggak1,
                'nunggak_2_bulan_plus' => $nunggak2,
                'target_broadcast' => $total
            ],
            'data' => $data,
            '_debug' => [
                'auth_user' => $pemilik,
                'user_status' => $userStatus,
                'user_id' => $userId,
                'owner_username' => $ownerUsername,
                'final_pemilik_values' => $pemilikValues,
                'final_area_values' => $areaValues,
                'query_where' => $where,
                'initial_query_rows' => $rowCount,
                'filter_results' => $filterCount,
                'final_count' => count($data),
                'scope_debug_steps' => $debugInfo
            ]
        ]);
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Method tidak didukung']);
}
