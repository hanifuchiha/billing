<?php
ob_start();
require '../cek-sesi.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

function resolve_bukti_image_url($buktiRaw, $metodeBayarRaw)
{
    $bukti = trim((string)$buktiRaw);
    $metode = strtolower(trim((string)$metodeBayarRaw));

    if ($bukti === '') {
        return '';
    }

    if (preg_match('/^https?:\/\//i', $bukti)) {
        return $bukti;
    }

    if (strpos($bukti, 'manual_active/') === 0) {
        return 'uploads/' . $bukti;
    }

    // Bukti pembayaran baru disimpan di folder dokumen/buktibon (di luar folder crm)
    if (strpos($bukti, 'buktibon/') === 0) {
        return '/dokumen/' . $bukti;
    }

    if (preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $bukti)) {
        return 'uploads/' . ltrim($bukti, '/');
    }

    if (strpos($bukti, 'uploads/') === 0) {
        return $bukti;
    }

    if ($metode === 'manual' && $bukti !== '') {
        return 'uploads/' . ltrim($bukti, '/');
    }

    return '';
}

function get_fixed_due_date_day_modal($ownerName)
{
    $defaultDay = 28;
    $owner = trim((string)$ownerName);
    if ($owner === '') {
        return $defaultDay;
    }

    $safeOwner = preg_replace('/[^a-zA-Z0-9_\-]/', '', $owner);
    if ($safeOwner === '') {
        return $defaultDay;
    }

    $fileReminder = __DIR__ . '/../notifbot/data/reminder-' . $safeOwner . '.json';
    if (!is_file($fileReminder)) {
        return $defaultDay;
    }

    $raw = @file_get_contents($fileReminder);
    if ($raw === false || $raw === '') {
        return $defaultDay;
    }

    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data) || !isset($data[0]['jatuh_tempo'])) {
        return $defaultDay;
    }

    $day = (int)$data[0]['jatuh_tempo'];
    return ($day >= 1 && $day <= 31) ? $day : $defaultDay;
}

function build_monthly_date_modal($year, $month, $day)
{
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

function get_tempo_type_value_modal(array $row)
{
    return strtolower(trim((string)($row['TIPE_TEMPO'] ?? 'mengikuti_tanggal_tempo')));
}

function parse_indo_month_year_modal($value)
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return null;
    }

    if (!preg_match('/^([A-Za-z]+)\s+(\d{4})$/', $raw, $m)) {
        return null;
    }

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
    if (!isset($monthMap[$monthName]) || $year < 1970) {
        return null;
    }

    return ['month' => (int)$monthMap[$monthName], 'year' => $year];
}

function get_first_due_date_fixed_by_usage_modal(array $row, $fixedDueDay)
{
    $tipeBayar = strtolower(trim((string)($row['TIPE_BAYAR'] ?? 'prabayar')));
    if ($tipeBayar !== 'prabayar') {
        return null;
    }

    if (get_tempo_type_value_modal($row) !== 'mengikuti_tanggal_tempo') {
        return null;
    }

    $parsed = parse_indo_month_year_modal((string)($row['LAST_PENGUNAAN'] ?? ''));
    if (!$parsed) {
        return null;
    }

    return build_monthly_date_modal((int)$parsed['year'], (int)$parsed['month'], (int)$fixedDueDay);
}

function get_reference_date_modal(array $row)
{
    $lastPaid = trim((string)($row['LAST_PAID_DATE'] ?? ''));
    if ($lastPaid !== '' && strtotime($lastPaid) !== false) {
        return date('Y-m-d', strtotime($lastPaid));
    }

    $tglPasang = trim((string)($row['TANGGALPASANG'] ?? ''));
    if ($tglPasang !== '' && strtotime($tglPasang) !== false) {
        return date('Y-m-d', strtotime($tglPasang));
    }

    return '';
}

function get_first_due_date_by_tempo_modal(array $row, $referenceDate, $fixedDueDay)
{
    if ($referenceDate === '' || strtotime($referenceDate) === false) {
        return null;
    }

    $refTs = strtotime($referenceDate);
    $tempoType = get_tempo_type_value_modal($row);
    if ($tempoType === 'mengikuti_tanggal_bayar') {
        return date('Y-m-d', strtotime('+1 month', $refTs));
    }

    $cfgDay = ((int)$fixedDueDay >= 1 && (int)$fixedDueDay <= 31) ? (int)$fixedDueDay : 28;
    $nextMonthTs = strtotime('+1 month', $refTs);
    $year = (int)date('Y', $nextMonthTs);
    $month = (int)date('m', $nextMonthTs);
    return build_monthly_date_modal($year, $month, $cfgDay);
}

function append_expired_overdue_days(array $rows, $type)
{
    if (!in_array($type, ['expired_online', 'expired_los'], true)) {
        return $rows;
    }

    $today = date('Y-m-d');
    $todayTs = strtotime($today);
    if ($todayTs === false) {
        return $rows;
    }

    foreach ($rows as $idx => $row) {
        $rows[$idx]['HARI_LEWAT_JATUH_TEMPO'] = 0;

        $fixedDueDay = get_fixed_due_date_day_modal((string)($row['PEMILIK'] ?? ''));
        $referenceDate = get_reference_date_modal($row);
        $dueDate = get_first_due_date_by_tempo_modal($row, $referenceDate, $fixedDueDay);

        $dueByUsage = get_first_due_date_fixed_by_usage_modal($row, $fixedDueDay);
        if (!empty($dueByUsage) && strtotime($dueByUsage) !== false) {
            $dueDate = $dueByUsage;
        }

        if (empty($dueDate)) {
            continue;
        }

        $dueTs = strtotime($dueDate);
        if ($dueTs === false || $dueTs > $todayTs) {
            continue;
        }

        $rows[$idx]['HARI_LEWAT_JATUH_TEMPO'] = (int)floor(($todayTs - $dueTs) / 86400);
    }

    return $rows;
}

function append_last_transaction_fields($conn, array $rows): array
{
    if (empty($rows)) {
        return $rows;
    }

    $idpelList = [];
    foreach ($rows as $idx => $row) {
        $rows[$idx]['TANGGAL_BAYAR_TERAKHIR'] = '';
        $rows[$idx]['BUKTI_TERAKHIR'] = '';
        $rows[$idx]['BUKTI_TERAKHIR_IMAGE_URL'] = '';
        $rows[$idx]['LAST_PAID_DATE'] = '';
        $rows[$idx]['LAST_PENGUNAAN'] = '';
        $rows[$idx]['HARI_LEWAT_JATUH_TEMPO'] = 0;

        $idpel = trim((string)($row['IDPEL'] ?? ''));
        if ($idpel !== '') {
            $idpelList[] = $idpel;
        }
    }

    $idpelList = array_values(array_unique($idpelList));
    if (empty($idpelList)) {
        return $rows;
    }

    $escapedIdpel = array_map(function ($idpel) use ($conn) {
        return "'" . mysqli_real_escape_string($conn, $idpel) . "'";
    }, $idpelList);

    $tanggalExpr = "COALESCE(
        DATE(waktu),
        DATE(TANGGALBAYAR),
        STR_TO_DATE(TANGGALBAYAR, '%Y-%m-%d'),
        STR_TO_DATE(TRIM(SUBSTRING_INDEX(TANGGALBAYAR, ',', -1)), '%d %M %Y'),
        STR_TO_DATE(TRIM(SUBSTRING_INDEX(TANGGALBAYAR, ',', -1)), '%d %b %Y'),
        STR_TO_DATE(
            TRIM(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                SUBSTRING_INDEX(TANGGALBAYAR, ',', -1),
                'Januari', '01'
            ), 'Februari', '02'), 'Maret', '03'), 'April', '04'), 'Mei', '05'), 'Juni', '06'), 'Juli', '07'), 'Agustus', '08'), 'September', '09'), 'Oktober', '10'), 'November', '11'), 'Desember', '12')),
            '%d %m %Y'
        )
    )";

        $sql = "SELECT IDPEL, TANGGALBAYAR, BUKTI, METODE_BAYAR, PENGUNAAN, $tanggalExpr AS parsed_tanggal
            FROM transaksi
            WHERE UPPER(TRIM(COALESCE(STATUS, ''))) = 'BERHASIL'
              AND IDPEL IN (" . implode(',', $escapedIdpel) . ")
            ORDER BY IDPEL ASC, parsed_tanggal DESC, waktu DESC";

    $q = mysqli_query($conn, $sql);
    $lastMap = [];
    while ($q && ($trx = mysqli_fetch_assoc($q))) {
        $idpel = trim((string)($trx['IDPEL'] ?? ''));
        if ($idpel === '' || isset($lastMap[$idpel])) {
            continue;
        }

        $lastMap[$idpel] = [
            'tanggal' => (string)($trx['TANGGALBAYAR'] ?? ''),
            'last_paid_date' => (string)($trx['parsed_tanggal'] ?? ''),
            'last_pengunaan' => (string)($trx['PENGUNAAN'] ?? ''),
            'bukti' => (string)($trx['BUKTI'] ?? ''),
            'bukti_image_url' => resolve_bukti_image_url($trx['BUKTI'] ?? '', $trx['METODE_BAYAR'] ?? ''),
        ];
    }

    foreach ($rows as $idx => $row) {
        $idpel = trim((string)($row['IDPEL'] ?? ''));
        if ($idpel === '' || !isset($lastMap[$idpel])) {
            continue;
        }

        $rows[$idx]['TANGGAL_BAYAR_TERAKHIR'] = $lastMap[$idpel]['tanggal'];
        $rows[$idx]['LAST_PAID_DATE'] = $lastMap[$idpel]['last_paid_date'];
        $rows[$idx]['LAST_PENGUNAAN'] = $lastMap[$idpel]['last_pengunaan'];
        $rows[$idx]['BUKTI_TERAKHIR'] = $lastMap[$idpel]['bukti'];
        $rows[$idx]['BUKTI_TERAKHIR_IMAGE_URL'] = $lastMap[$idpel]['bukti_image_url'];
    }

    return $rows;
}

function getInternetStatusRows($conn, $userServerList, $areaFilter, $filename, $type)
{
    $allowedTypes = ['total_users', 'internet_online', 'internet_los', 'expired_online', 'expired_los', 'instalasi_bulan_ini', 'berhenti_bulan_ini'];
    if (!in_array($type, $allowedTypes, true)) {
        return [];
    }

    $bulanSaatIni = (int)date('m');
    $tahunSaatIni = (int)date('Y');

    $expired_ids = [];
    $los_ids = [];
    $offline_non_expired_ids = [];

    if (is_file($filename)) {
        $json_data = @file_get_contents($filename);
        $decoded = json_decode((string)$json_data, true);
        if (is_array($decoded)) {
            $expired_ids = array_values(array_unique(array_filter(array_map('strval', $decoded['expired_ids'] ?? []))));
            $los_ids = array_values(array_unique(array_filter(array_map('strval', $decoded['los_ids'] ?? []))));
            $offline_non_expired_ids = array_values(array_unique(array_filter(array_map('strval', $decoded['offline_non_expired_ids'] ?? []))));
        }
    }

    $expired_lookup = array_flip($expired_ids);
    $los_lookup = array_flip($los_ids);
    $offline_non_expired_lookup = array_flip($offline_non_expired_ids);

    $rows = [];
    $serverFilter = trim((string)$userServerList);
    $where = "1=1";
    if ($serverFilter !== '' && $serverFilter !== "''") {
        $where = "PEMILIK IN ($serverFilter)";
    }
    $areaFilterClean = trim((string)$areaFilter);
    if ($areaFilterClean !== '' && $areaFilterClean !== "''") {
        $where .= " AND AREA IN ($areaFilterClean)";
    }

    if ($type === 'instalasi_bulan_ini') {
        $sqlInstalasi = "SELECT IDPEL, NAMA, PAKET, AREA, ODP, NOWA, ALAMAT
                        FROM pelanggan
                        WHERE $where
                          AND MONTH(TANGGALPASANG) = $bulanSaatIni
                          AND YEAR(TANGGALPASANG) = $tahunSaatIni
                        ORDER BY NAMA";
        $resultInstalasi = mysqli_query($conn, $sqlInstalasi);
        $rowsInstalasi = [];
        while ($resultInstalasi && ($row = mysqli_fetch_assoc($resultInstalasi))) {
            $rowsInstalasi[] = $row;
        }
        return append_expired_overdue_days(append_last_transaction_fields($conn, $rowsInstalasi), $type);
    }

    if ($type === 'berhenti_bulan_ini') {
        $ownerWhere = "1=1";
        $serverFilter = trim((string)$userServerList);
        if ($serverFilter !== '' && $serverFilter !== "''") {
            $ownerWhere = "pemilik IN ($serverFilter)";
        }

        $sqlBerhenti = "SELECT
                          idpel AS IDPEL,
                          nama AS NAMA,
                          paket AS PAKET,
                          '' AS AREA,
                          '' AS ODP,
                          nowa AS NOWA,
                          alamat AS ALAMAT
                        FROM pelanggan_berhenti
                        WHERE $ownerWhere
                          AND MONTH(tanggal_berhenti) = $bulanSaatIni
                          AND YEAR(tanggal_berhenti) = $tahunSaatIni
                        ORDER BY tanggal_berhenti DESC, nama ASC";
        $resultBerhenti = mysqli_query($conn, $sqlBerhenti);
        $rowsBerhenti = [];
        while ($resultBerhenti && ($row = mysqli_fetch_assoc($resultBerhenti))) {
            $rowsBerhenti[] = $row;
        }
        return append_expired_overdue_days(append_last_transaction_fields($conn, $rowsBerhenti), $type);
    }

    $sql = "SELECT IDPEL, NAMA, PAKET, AREA, ODP, NOWA, ALAMAT, PEMILIK, TANGGALPASANG, TEMPO, TIPE_BAYAR, TIPE_TEMPO FROM pelanggan WHERE $where ORDER BY NAMA";
    $result = mysqli_query($conn, $sql);
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $idpel = (string)($row['IDPEL'] ?? '');
        if ($idpel === '') {
            continue;
        }

        $isExpired = isset($expired_lookup[$idpel]);
        $isLos = isset($los_lookup[$idpel]);
        $isOfflineNonExpired = isset($offline_non_expired_lookup[$idpel]);

        $isMatch = false;
        if ($type === 'total_users') {
            $isMatch = true;
        } elseif ($type === 'internet_online') {
            $isMatch = (!$isExpired && !$isLos);
        } elseif ($type === 'internet_los') {
            $isMatch = $isOfflineNonExpired;
        } elseif ($type === 'expired_online') {
            $isMatch = ($isExpired && !$isLos);
        } elseif ($type === 'expired_los') {
            $isMatch = ($isExpired && $isLos);
        }

        if ($isMatch) {
            $rows[] = $row;
        }
    }

    return append_expired_overdue_days(append_last_transaction_fields($conn, $rows), $type);
}

$statusType = isset($_GET['type']) ? strtolower(trim((string)$_GET['type'])) : '';

$conn = $conn ?? null;
$current_user_id = isset($current_user_id) ? (int)$current_user_id : 0;
$AKSES = isset($AKSES) ? (string)$AKSES : '';
$asistant_name = isset($asistant_name) ? (string)$asistant_name : '';
$ceknama = isset($ceknama) ? (string)$ceknama : ((isset($_SESSION['PEMILIK']) ? (string)$_SESSION['PEMILIK'] : ''));
$area_list = isset($area_list) ? (string)$area_list : '';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Koneksi database tidak tersedia'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userServers = [];
$userAreas = [];
$queryServer = mysqli_query($conn, "SELECT PEMILIK, AREA FROM server WHERE user_id = $current_user_id");
while ($queryServer && ($row = mysqli_fetch_assoc($queryServer))) {
    if (!empty($row['PEMILIK'])) {
        $userServers[] = $row['PEMILIK'];
    }
    if (!empty($row['AREA'])) {
        $userAreas[] = $row['AREA'];
    }
}

if (empty($userServers) && $ceknama !== '') {
    $userServers[] = $ceknama;
}

$userServerList = count($userServers) > 0 ? "'" . implode("','", array_map('addslashes', $userServers)) . "'" : "''";
$userAreaList = count($userAreas) > 0 ? "'" . implode("','", array_map('addslashes', $userAreas)) . "'" : "''";
$areaFilter = (!empty($asistant_name) && !empty($area_list)) ? $area_list : $userAreaList;
$cacheFile = __DIR__ . '/../serverlog/' . (($AKSES == 'ASSISTANT') ? $asistant_name : $ceknama) . '.txt';

$rows = getInternetStatusRows($conn, $userServerList, $areaFilter, $cacheFile, $statusType);

echo json_encode([
    'success' => true,
    'type' => $statusType,
    'data' => $rows,
], JSON_UNESCAPED_UNICODE);
exit;
