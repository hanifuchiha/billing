<?php
// Card "Cron Tiket Dismantle Otomatis" (+ AJAX handler & vars-nya) DIPINDAHKAN
// ke system_setting.php atas permintaan user -- lihat memory
// project_cron_ticket_cards_moved_to_system_setting.
require 'header.php';
if ($AKSES == 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('Pelanggan_menunggak', $akses_menu, true)) {
        echo '<div class="container-fluid py-4"><div class="alert alert-danger">Anda tidak memiliki akses ke menu Pelanggan Menunggak.</div></div>';
        require 'footer.php';
        exit;
    }
}

require_once __DIR__ . '/libs/menunggak_payment_lookup.php';

$today = date('Y-m-d');

// Get all server PEMILIK and AREA for this user.
$userServers = [];
$userAreas = [];
$queryServer = mysqli_query($conn, "SELECT PEMILIK, AREA FROM server WHERE user_id = " . (int)$current_user_id);
while ($queryServer && ($row = mysqli_fetch_assoc($queryServer))) {
    $userServers[] = $row['PEMILIK'];
    if (!empty($row['AREA'])) {
        $userAreas[] = $row['AREA'];
    }
}

$userServerList = count($userServers) > 0 ? "'" . implode("','", array_map('addslashes', array_unique($userServers))) . "'" : "''";
$userAreaList = count($userAreas) > 0 ? "'" . implode("','", array_map('addslashes', array_unique($userAreas))) . "'" : "''";

if ($AKSES === 'ASSISTANT') {
    $userAreaList = $area_list;
    $queryPemilik = mysqli_query($conn, "SELECT DISTINCT PEMILIK FROM server WHERE AREA IN ($area_list)");
    $userServers = [];
    while ($queryPemilik && ($row = mysqli_fetch_assoc($queryPemilik))) {
        $userServers[] = $row['PEMILIK'];
    }
    $userServerList = count($userServers) > 0 ? "'" . implode("','", array_map('addslashes', array_unique($userServers))) . "'" : "''";
}

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

// Hasil query di-lewatkan reseller_filter_rows() supaya ikut filter harga
// reseller/mitra (custom_harga per paket + paket yang tidak di-enable utk
// reseller ini otomatis tidak masuk peta) -- transparan/no-op utk sesi bukan
// reseller. Sebelum fix ini, halaman ini SELALU pakai HARGA asli dari tabel
// paket, sama seperti bug yang sama di statistics.php.
$hargaPaketMap = [];
$fasumPaketList = [];
$qPaketMap = mysqli_query($conn, "SELECT id, PAKET, HARGA, BRAND, AREA FROM paket");
$rowsPaketMap = reseller_filter_rows($conn, reseller_collect_rows($qPaketMap), 'broadband');
foreach ($rowsPaketMap as $r) {
    $paketKey = strtolower(trim((string)$r['PAKET']));
    $brandKey = isset($r['BRAND']) ? strtolower(trim((string)$r['BRAND'])) : '';
    $areaKey = isset($r['AREA']) ? strtolower(trim((string)$r['AREA'])) : '';
    $mapKey = $paketKey . '|' . $brandKey . '|' . $areaKey;
    $hargaPaketMap[$mapKey] = $r['HARGA'];

    if ($r['HARGA'] === '' || (float)$r['HARGA'] <= 0) {
        $fasumPaketList[$paketKey] = $r['id'];
    }
}

$promoPaketIds = [];
$qPromo = mysqli_query($conn, "SELECT paket_id FROM promo_paket");
while ($qPromo && ($r = mysqli_fetch_assoc($qPromo))) {
    $promoPaketIds[] = (string)$r['paket_id'];
}

function resolveHargaPaket($hargaPaketMap, $paketPelanggan, $brandPelanggan, $areaPelanggan)
{
    $mapKey = $paketPelanggan . '|' . $brandPelanggan . '|' . $areaPelanggan;

    if (isset($hargaPaketMap[$mapKey])) {
        return $hargaPaketMap[$mapKey];
    }
    if (isset($hargaPaketMap[$paketPelanggan . '||' . $areaPelanggan])) {
        return $hargaPaketMap[$paketPelanggan . '||' . $areaPelanggan];
    }
    if (isset($hargaPaketMap[$paketPelanggan . '|' . $brandPelanggan . '|'])) {
        return $hargaPaketMap[$paketPelanggan . '|' . $brandPelanggan . '|'];
    }
    if (isset($hargaPaketMap[$paketPelanggan . '||'])) {
        return $hargaPaketMap[$paketPelanggan . '||'];
    }
    if (isset($hargaPaketMap[$paketPelanggan])) {
        return $hargaPaketMap[$paketPelanggan];
    }

    return null;
}

function isFasumNonPromo($paketPelanggan, $fasumPaketList, $promoPaketIds)
{
    if ($paketPelanggan === '' || !isset($fasumPaketList[$paketPelanggan])) {
        return false;
    }

    $paketIdFasum = (string)$fasumPaketList[$paketPelanggan];
    return !in_array($paketIdFasum, $promoPaketIds, true);
}

function isSamePeriodAsToday($dateValue, $today)
{
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

function shouldCountAsMenunggak($row, $today)
{
    $tipeBayar = strtolower(trim((string)($row['TIPE_BAYAR'] ?? 'prabayar')));

    // Prabayar: jika baru pasang di periode ini atau sudah bayar periode ini, belum menunggak.
    if ($tipeBayar !== 'pascabayar') {
        if (isSamePeriodAsToday($row['TANGGALPASANG'] ?? '', $today)) {
            return false;
        }
        if (isSamePeriodAsToday($row['last_paid'] ?? '', $today)) {
            return false;
        }
        return true;
    }

    // Pascabayar: jika baru pasang di periode ini, jangan dihitung menunggak.
    if (isSamePeriodAsToday($row['TANGGALPASANG'] ?? '', $today)) {
        return false;
    }

    // Pascabayar: jika transaksi berhasil terakhir masih di periode ini, jangan dihitung menunggak.
    if (isSamePeriodAsToday($row['last_paid'] ?? '', $today)) {
        return false;
    }

    return true;
}

function getMenunggakReferenceDate($row)
{
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

function getFixedDueDateDay($username)
{
    global $conn;

    // Default 25 -- SELARAS dengan default lokal cek_tagihan_harian*.php (cron
    // yang SUNGGUHAN menentukan isolir/EXPIRED di MikroTik) & reminderSettingsDefaults()
    // di DB, BUKAN 28 seperti sebelumnya (beda 3 hari dari cron -> salah satu
    // sebab widget "Menunggak"/"Statistik" tidak sinkron dengan "Expired
    // Online"/"Expired Los" di dashboard). Baca via reminderSettingsGetRow()
    // (notifbot/reminder_settings_helper.php) dengan pola gating-aware yang
    // sama persis dgn cek_tagihan_harian.php: HANYA timpa default kalau akun
    // ini PERNAH eksplisit setting Fixed Due Date (fixed_due_date_configured
    // true) -- supaya akun yang belum pernah setting tetap konsisten dgn cron.
    $defaultDay = 25;
    if (!empty($conn)) {
        require_once __DIR__ . '/notifbot/reminder_settings_helper.php';
        $row = reminderSettingsGetRow($conn, (string)$username);
        if ($row && !empty($row['fixed_due_date_configured'])) {
            $day = (int)($row['jatuh_tempo'] ?? 0);
            if ($day >= 1 && $day <= 31) {
                return $day;
            }
        }
    }

    return $defaultDay;
}

function getMenunggakPrabayarGracePeriod($username)
{
    // Waktu tunggu (hari) sebelum pelanggan PRABAYAR yang belum pernah bayar
    // sejak pasang dianggap menunggak -- selaras dgn $prabayar_grace_period
    // di cek_tagihan_harian.php & Payment Setting (file JSON yang sama, belum
    // dimigrasikan ke DB, cukup baca apa adanya spt tables.php/paymentset.php).
    $default = 2;
    $safeUsername = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$username);
    if ($safeUsername === '') {
        return $default;
    }
    $file = __DIR__ . '/notifbot/data/prabayar_grace_period-' . $safeUsername . '.json';
    if (!is_file($file)) {
        return $default;
    }
    $data = json_decode((string)@file_get_contents($file), true);
    if (!is_array($data) || !isset($data['prabayar_grace_period'])) {
        return $default;
    }
    $val = (int)$data['prabayar_grace_period'];
    return $val >= 0 ? $val : $default;
}

function buildMonthlyDate($year, $month, $day)
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

function getTempoTypeValue($row)
{
    return strtolower(trim((string)($row['TIPE_TEMPO'] ?? 'mengikuti_tanggal_tempo')));
}

// Hari jatuh tempo tetap milik pelanggan itu sendiri untuk mode "monthversary"
// (anchor dikunci di TANGGAL_MONTHVERSARY, fallback ke TANGGALPASANG kalau kosong).
function getMonthversaryAnchorDay($row)
{
    $anchorDate = trim((string)($row['TANGGAL_MONTHVERSARY'] ?? ''));
    if ($anchorDate === '' || strtotime($anchorDate) === false) {
        $anchorDate = (string)($row['TANGGALPASANG'] ?? '');
    }
    if ($anchorDate === '' || strtotime($anchorDate) === false) {
        return 28;
    }
    return (int)date('j', strtotime($anchorDate));
}

function parseIndoMonthYear($value)
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

    return [
        'month' => (int)$monthMap[$monthName],
        'year' => $year,
    ];
}

function getFirstDueDateForPrabayarFixedByLastUsage($row, $fixedDueDay)
{
    $tipeBayar = strtolower(trim((string)($row['TIPE_BAYAR'] ?? 'prabayar')));
    if ($tipeBayar !== 'prabayar') {
        return null;
    }

    if (getTempoTypeValue($row) !== 'mengikuti_tanggal_tempo') {
        return null;
    }

    $parsed = parseIndoMonthYear((string)($row['last_pengunaan'] ?? ''));
    if (!$parsed) {
        return null;
    }

    $cfgDay = (int)$fixedDueDay;
    if ($cfgDay < 1 || $cfgDay > 31) {
        $cfgDay = 28;
    }

    return buildMonthlyDate((int)$parsed['year'], (int)$parsed['month'], $cfgDay);
}

function extractKendalaFromJoblistData($jobData)
{
    $jobData = (string)$jobData;
    if ($jobData === '') {
        return '';
    }

    if (preg_match('/KENDALA\s*:\s*(.+)$/mi', $jobData, $matches)) {
        return trim((string)$matches[1]);
    }

    return '';
}

function getTicketStatusBadgeClass($status)
{
    $status = strtoupper(trim((string)$status));
    if ($status === 'BARU') {
        return 'warning text-dark';
    }
    if ($status === 'PENDING') {
        return 'info';
    }
    if ($status === 'CANCEL') {
        return 'danger';
    }

    return 'secondary';
}


function getFirstDueDateByTempoType($row, $referenceDate, $fixedDueDay)
{
    if (empty($referenceDate) || strtotime($referenceDate) === false) {
        return null;
    }

    $refTs = strtotime($referenceDate);
    $tempoType = getTempoTypeValue($row);

    if ($tempoType === 'mengikuti_tanggal_bayar') {
        return date('Y-m-d', strtotime('+1 month', $refTs));
    }

    $cfgDay = ($tempoType === 'monthversary') ? getMonthversaryAnchorDay($row) : (int)$fixedDueDay;
    if ($cfgDay < 1 || $cfgDay > 31) {
        $cfgDay = 28;
    }

    // Untuk mode fixed tempo / monthversary, due pertama selalu di bulan berikutnya.
    $nextMonthTs = strtotime('+1 month', $refTs);
    $year = (int)date('Y', $nextMonthTs);
    $month = (int)date('m', $nextMonthTs);
    return buildMonthlyDate($year, $month, $cfgDay);
}

function getNextDueDateByTempoType($row, $currentDueDate, $fixedDueDay)
{
    if (empty($currentDueDate) || strtotime($currentDueDate) === false) {
        return null;
    }

    $currentTs = strtotime($currentDueDate);
    $tempoType = getTempoTypeValue($row);

    if ($tempoType === 'mengikuti_tanggal_bayar') {
        return date('Y-m-d', strtotime('+1 month', $currentTs));
    }

    $cfgDay = ($tempoType === 'monthversary') ? getMonthversaryAnchorDay($row) : (int)$fixedDueDay;
    if ($cfgDay < 1 || $cfgDay > 31) {
        $cfgDay = 28;
    }

    $nextMonthTs = strtotime('+1 month', $currentTs);
    $year = (int)date('Y', $nextMonthTs);
    $month = (int)date('m', $nextMonthTs);
    return buildMonthlyDate($year, $month, $cfgDay);
}

function hasSuccessfulPaymentInPeriod($paymentIndex, $idpel, $startDate, $endDate)
{
    return mnq_has_payment_in_period($paymentIndex, (string)$idpel, (string)$startDate, (string)$endDate);
}

function isDueDatePassedForRow($row, $today, $fixedDueDay)
{
    $reference = getMenunggakReferenceDate($row);
    $firstDueDate = getFirstDueDateByTempoType($row, $reference, $fixedDueDay);
    if (empty($firstDueDate) || strtotime($firstDueDate) === false) {
        return false;
    }

    return strtotime($firstDueDate) <= strtotime($today);
}

function countConsecutiveMissedMonthsForRow($paymentIndex, $row, $today, $fixedDueDay)
{
    $idpel = (string)($row['IDPEL'] ?? '');
    if ($idpel === '') {
        return 0;
    }

    $referenceDate = getMenunggakReferenceDate($row);
    $nextDueDate = getFirstDueDateByTempoType($row, $referenceDate, $fixedDueDay);
    if (empty($nextDueDate) || strtotime($nextDueDate) === false) {
        return 0;
    }

    $todayTs = strtotime((string)$today);
    if ($todayTs === false) {
        return 0;
    }

    $bulanTunggak = 0;
    while (strtotime($nextDueDate) <= $todayTs) {
        $cycleStart = $nextDueDate;
        $cycleEnd = getNextDueDateByTempoType($row, $cycleStart, $fixedDueDay);

        if (empty($cycleEnd) || strtotime($cycleEnd) === false) {
            break;
        }

        if (hasSuccessfulPaymentInPeriod($paymentIndex, $idpel, $cycleStart, $cycleEnd)) {
            return 0;
        }

        $bulanTunggak++;
        $nextDueDate = $cycleEnd;
    }

    return $bulanTunggak;
}

function evaluateMenunggakStatusForRow($paymentIndex, $row, $today, $fixedDueDay, $prabayarGracePeriod = 2)
{
    $status = [
        'show' => false,
        'reference_date' => '',
        'first_due_date' => '',
        'active_due_date' => '',
        'bulan_nunggak' => 0,
        'hari_nunggak' => 0,
        'total_hari_nunggak' => 0,
    ];

    // Prabayar yang BELUM PERNAH bayar sejak pasang: cabang KHUSUS, selaras
    // persis dgn tagihanHitungStatus() (notifbot/notifphp/tagihan_status_lib.php,
    // fungsi yang dipakai cek_tagihan_harian.php utk keputusan isolir SUNGGUHAN).
    // Jatuh tempo pertama = TANGGALPASANG itu sendiri (bayar di muka, tidak
    // dapat gratis sebulan penuh), isolir setelah TANGGALPASANG + waktu tunggu
    // -- BUKAN nunggu siklus "+1 bulan" spt cabang di bawah (yang cuma berlaku
    // utk pelanggan yang SUDAH PERNAH bayar minimal sekali). Tanpa cabang ini,
    // pelanggan baru prabayar yang belum pernah bayar baru terhitung menunggak
    // SEBULAN kemudian di halaman ini -- padahal cron isolir sungguhan sudah
    // menonaktifkannya jauh lebih cepat (default waktu tunggu 2 hari) -- itulah
    // sebab utama widget ini tidak sinkron dgn "Expired Online"/"Expired Los"
    // di dashboard.
    $tipeBayarRow = strtolower(trim((string)($row['TIPE_BAYAR'] ?? 'prabayar')));
    $lastPaidRaw = trim((string)($row['last_paid'] ?? ''));
    if ($tipeBayarRow !== 'pascabayar' && $lastPaidRaw === '') {
        $tanggalPasangRow = trim((string)($row['TANGGALPASANG'] ?? ''));
        $todayTsBaru = strtotime((string)$today);
        $pasangTsBaru = strtotime($tanggalPasangRow);
        if ($tanggalPasangRow === '' || $todayTsBaru === false || $pasangTsBaru === false) {
            return $status;
        }

        $graceP = max(0, (int)$prabayarGracePeriod);
        $batasIsolirBaru = $graceP > 0
            ? date('Y-m-d', strtotime("+{$graceP} days", $pasangTsBaru))
            : $tanggalPasangRow;
        if (strtotime($batasIsolirBaru) > $todayTsBaru) {
            return $status; // masih dalam waktu tunggu (grace period)
        }

        $status['show'] = true;
        $status['reference_date'] = $tanggalPasangRow;
        $status['first_due_date'] = $tanggalPasangRow;
        $status['active_due_date'] = $tanggalPasangRow;
        $status['bulan_nunggak'] = 1;
        $status['hari_nunggak'] = max(1, (int)floor(($todayTsBaru - $pasangTsBaru) / 86400) + 1);
        $status['total_hari_nunggak'] = $status['hari_nunggak'];
        return $status;
    }

    if (!shouldCountAsMenunggak($row, $today)) {
        return $status;
    }

    $referenceDate = getMenunggakReferenceDate($row);
    if ($referenceDate === '' || strtotime($referenceDate) === false) {
        return $status;
    }

    $firstDueDate = getFirstDueDateByTempoType($row, $referenceDate, $fixedDueDay);
    $prabayarFixedDueByUsage = getFirstDueDateForPrabayarFixedByLastUsage($row, $fixedDueDay);
    if (!empty($prabayarFixedDueByUsage) && strtotime($prabayarFixedDueByUsage) !== false) {
        // Khusus prabayar+mengikuti_tanggal_tempo: patok due ke periode penggunaan terakhir.
        $firstDueDate = $prabayarFixedDueByUsage;
    }

    if (empty($firstDueDate) || strtotime($firstDueDate) === false) {
        return $status;
    }

    $todayTs = strtotime((string)$today);
    $firstDueTs = strtotime($firstDueDate);
    if ($todayTs === false || $firstDueTs === false || $firstDueTs > $todayTs) {
        return $status;
    }

    $idpel = (string)($row['IDPEL'] ?? '');
    if ($idpel === '') {
        return $status;
    }

    $bulanTunggak = 0;
    $nextDueDate = $firstDueDate;
    $activeDueDate = $firstDueDate;

    while (strtotime($nextDueDate) !== false && strtotime($nextDueDate) <= $todayTs) {
        $cycleStart = $nextDueDate;
        $cycleEnd = getNextDueDateByTempoType($row, $cycleStart, $fixedDueDay);
        if (empty($cycleEnd) || strtotime($cycleEnd) === false) {
            break;
        }

        if (hasSuccessfulPaymentInPeriod($paymentIndex, $idpel, $cycleStart, $cycleEnd)) {
            return $status;
        }

        $bulanTunggak++;
        $activeDueDate = $cycleStart;
        $nextDueDate = $cycleEnd;
    }

    if ($bulanTunggak < 1) {
        return $status;
    }

    $activeDueTs = strtotime($activeDueDate);
    $hariNunggak = $activeDueTs === false ? 0 : (int)floor(($todayTs - $activeDueTs) / 86400) + 1;
    $totalHariNunggak = (int)floor(($todayTs - $firstDueTs) / 86400) + 1;

    $status['show'] = true;
    $status['reference_date'] = $referenceDate;
    $status['first_due_date'] = $firstDueDate;
    $status['active_due_date'] = $activeDueDate;
    $status['bulan_nunggak'] = $bulanTunggak;
    $status['hari_nunggak'] = max(1, $hariNunggak);
    $status['total_hari_nunggak'] = max(1, $totalHariNunggak);

    return $status;
}

function fetchNunggakData($conn, $sql, $hargaPaketMap, $fasumPaketList, $promoPaketIds, $today, $fixedDueDay)
{
    $result = mysqli_query($conn, $sql);
    $data = [];
    if (!$result) {
        return $data;
    }

    while ($row = mysqli_fetch_assoc($result)) {
        $paketPelanggan = isset($row['PAKET']) ? strtolower(trim((string)$row['PAKET'])) : '';
        $brandPelanggan = isset($row['BRAND']) ? strtolower(trim((string)$row['BRAND'])) : '';
        $areaPelanggan = isset($row['AREA']) ? strtolower(trim((string)$row['AREA'])) : '';

        if (isFasumNonPromo($paketPelanggan, $fasumPaketList, $promoPaketIds)) {
            continue;
        }

        $statusMenunggak = evaluateMenunggakStatusForRow($conn, $row, $today, $fixedDueDay, $GLOBALS['trxTanggalExprNoAlias']);
        if (empty($statusMenunggak['show'])) {
            continue;
        }

        $hargaPaket = resolveHargaPaket($hargaPaketMap, $paketPelanggan, $brandPelanggan, $areaPelanggan);
        if ($hargaPaket !== null && (float)$hargaPaket > 0) {
            $row['harga_paket'] = (float)$hargaPaket;
            $row['bulan_nunggak'] = (int)$statusMenunggak['bulan_nunggak'];
            $row['hari_nunggak'] = (int)$statusMenunggak['hari_nunggak'];
            $row['total_hari_nunggak'] = (int)$statusMenunggak['total_hari_nunggak'];
            $row['tanggal_jatuh_tempo_pertama'] = $statusMenunggak['first_due_date'];
            $row['tanggal_jatuh_tempo_aktif'] = $statusMenunggak['active_due_date'];
            $data[] = $row;
        }
    }

    return $data;
}

// reminder_settings/grace period SELALU dikunci per username LOGIN OWNER
// ($ceknama) -- Payment Setting sekarang owner-only, tidak pernah tersimpan
// per nama assistant, jadi TIDAK BOLEH pakai $asistant_name di sini (beda
// dgn dashboardSettingsUsername/akses_menu yg memang per-assistant). $ceknama
// utk sesi ASSISTANT sudah otomatis = username owner (lihat cek-sesi.php).
$menunggakReminderUsername = (string)($ceknama ?? '');
$fixedDueDateDay = getFixedDueDateDay($menunggakReminderUsername);
$menunggakPrabayarGracePeriod = getMenunggakPrabayarGracePeriod($menunggakReminderUsername);

// Ambil isi template "Pesan Remainder Manual" (notif_khusus.pesan_remainder_manual,
// dikonfigurasi di notification.php) buat DIPERLIHATKAN apa adanya (placeholder
// $NAMA/$IDPEL/dkk masih literal) di textarea saat mode "Template Reminder
// Pembayaran" dipilih -- supaya admin bisa lihat/verifikasi isinya dulu sebelum
// kirim, bukan cuma placeholder kosong. Substitusi per-pelanggan sungguhan
// tetap terjadi di backend (proses/notif_menunggak_manual.php) saat kirim.
$menunggakTemplateRemainderPreview = '';
if ($menunggakReminderUsername !== '') {
    $stmtTplPreview = $conn->prepare('SELECT pesan_remainder_manual FROM notif_khusus WHERE pemilik = ? LIMIT 1');
    if ($stmtTplPreview) {
        $stmtTplPreview->bind_param('s', $menunggakReminderUsername);
        $stmtTplPreview->execute();
        $stmtTplPreview->bind_result($menunggakTemplateRemainderPreview);
        $stmtTplPreview->fetch();
        $stmtTplPreview->close();
    }
    $menunggakTemplateRemainderPreview = trim((string)$menunggakTemplateRemainderPreview);
}

// "Menunggak" di halaman ini SEKARANG mengikuti status EXPIRED di router
// (persis sama dgn "Expired Online" + "Expired Los" di dashboard), BUKAN lagi
// murni hasil hitung siklus billing -- atas permintaan eksplisit user 2026-08-05
// supaya angkanya konsisten dgn dashboard. Cache `expired_ids` ini ditulis
// tiap menit oleh cron getdata/serverload.php, SUMBER PERSIS yang sama dipakai
// dashboard.php::getInternetStatusRows() (union expired_online+expired_los =
// member expired_ids). Perhitungan siklus billing (evaluateMenunggakStatusForRow())
// TETAP dipakai di bawah, tapi HANYA utk isi kolom tanggal/bulan-nunggak
// tampilan -- bukan lagi penentu boleh/tidaknya pelanggan masuk daftar.
$menunggakCacheUsername = ($AKSES == 'ASSISTANT') ? $asistant_name : $ceknama;
$menunggakCacheFile = __DIR__ . '/serverlog/' . $menunggakCacheUsername . '.txt';
$menunggakExpiredLookup = [];
if (is_file($menunggakCacheFile)) {
    $menunggakCacheDecoded = json_decode((string)@file_get_contents($menunggakCacheFile), true);
    if (is_array($menunggakCacheDecoded)) {
        $menunggakExpiredIdsCache = array_values(array_unique(array_filter(array_map('strval', $menunggakCacheDecoded['expired_ids'] ?? []))));
        $menunggakExpiredLookup = array_flip($menunggakExpiredIdsCache);
    }
}

$dataMenunggak = [];
$sqlSemuaPelangganMenunggak = "SELECT p.IDPEL, p.NAMA, p.PAKET, p.PEMILIK, p.BRAND, p.AREA, p.NOWA, p.ALAMAT, p.EMAIL, p.TIKOR, p.ODP, p.TANGGALPASANG, p.TEMPO, p.TIPE_BAYAR, p.TIPE_TEMPO, p.TANGGAL_MONTHVERSARY, t.last_paid FROM pelanggan p LEFT JOIN (SELECT IDPEL, MAX($trxTanggalExprNoAlias) AS last_paid FROM transaksi WHERE STATUS = 'BERHASIL' GROUP BY IDPEL) t ON p.IDPEL = t.IDPEL WHERE p.PEMILIK IN ($userServerList) AND p.AREA IN ($userAreaList)";
$resultSemuaPelangganMenunggak = mysqli_query($conn, $sqlSemuaPelangganMenunggak);

// Kumpulkan dulu kandidat (yang lolos filter fasum) + daftar IDPEL-nya, supaya
// riwayat pembayaran BERHASIL bisa di-prefetch SEKALI lewat mnq_build_payment_index()
// alih-alih query "last_paid"/"sudah bayar di periode ini" per pelanggan per
// bulan tunggakan (pola N+1 lama yang berat untuk ribuan pelanggan).
$menunggakCandidateRows = [];
$menunggakCandidateIdpel = [];
if ($resultSemuaPelangganMenunggak) {
    while ($row = mysqli_fetch_assoc($resultSemuaPelangganMenunggak)) {
        $idpel = (string)($row['IDPEL'] ?? '');
        if ($idpel === '') {
            continue;
        }

        $paketPelanggan = isset($row['PAKET']) ? strtolower(trim((string)$row['PAKET'])) : '';
        $brandPelanggan = isset($row['BRAND']) ? strtolower(trim((string)$row['BRAND'])) : '';
        $areaPelanggan = isset($row['AREA']) ? strtolower(trim((string)$row['AREA'])) : '';

        if (isFasumNonPromo($paketPelanggan, $fasumPaketList, $promoPaketIds)) {
            continue;
        }

        $row['_paket_lc'] = $paketPelanggan;
        $row['_brand_lc'] = $brandPelanggan;
        $row['_area_lc'] = $areaPelanggan;
        $menunggakCandidateRows[] = $row;
        $menunggakCandidateIdpel[] = $idpel;
    }
}

$menunggakPaymentIndex = mnq_build_payment_index($conn, $menunggakCandidateIdpel, $trxTanggalExprNoAlias);

foreach ($menunggakCandidateRows as $row) {
    $idpel = (string)$row['IDPEL'];
    $paketPelanggan = $row['_paket_lc'];
    $brandPelanggan = $row['_brand_lc'];
    $areaPelanggan = $row['_area_lc'];

    $lastPaidInfo = mnq_get_last_paid($menunggakPaymentIndex, $idpel);
    $row['last_paid'] = $lastPaidInfo['last_paid'] ?? ($row['last_paid'] ?? null);
    $row['last_pengunaan'] = $lastPaidInfo['last_pengunaan'];

    // Boleh/tidaknya masuk daftar SEKARANG ditentukan status EXPIRED di router
    // (lihat komentar di atas), bukan lagi $statusMenunggak['show'] dari hitung
    // siklus billing -- itu cuma dipakai buat isi kolom tanggal/bulan-nunggak.
    if (!isset($menunggakExpiredLookup[$idpel])) {
        continue;
    }

    $statusMenunggak = evaluateMenunggakStatusForRow($menunggakPaymentIndex, $row, $today, $fixedDueDateDay, $menunggakPrabayarGracePeriod);

    $hargaPaket = resolveHargaPaket($hargaPaketMap, $paketPelanggan, $brandPelanggan, $areaPelanggan);
    if ($hargaPaket === null || (float)$hargaPaket <= 0) {
        continue;
    }

    // Kalau router bilang EXPIRED tapi hitung siklus billing tidak (blm) setuju
    // (mis. baru bayar & belum direstore, atau nuansa siklus lain) -- tetap
    // masukkan dgn nilai floor 1 siklus/1 hari, supaya konsisten dgn total
    // (card "Nunggak 1 Siklus"+"Nunggak 2 Siklus+" tetap = "Total Menunggak").
    $bulanNunggakRow = (int)$statusMenunggak['bulan_nunggak'];
    $hariNunggakRow = (int)$statusMenunggak['hari_nunggak'];
    $totalHariNunggakRow = (int)$statusMenunggak['total_hari_nunggak'];
    if (empty($statusMenunggak['show'])) {
        $bulanNunggakRow = max(1, $bulanNunggakRow);
        $hariNunggakRow = max(1, $hariNunggakRow);
        $totalHariNunggakRow = max(1, $totalHariNunggakRow);
    }

    $row['harga_paket'] = (float)$hargaPaket;
    $row['bulan_nunggak'] = $bulanNunggakRow;
    $row['hari_nunggak'] = $hariNunggakRow;
    $row['total_hari_nunggak'] = $totalHariNunggakRow;
    $row['tanggal_jatuh_tempo_pertama'] = $statusMenunggak['first_due_date'];
    $row['tanggal_jatuh_tempo_aktif'] = $statusMenunggak['active_due_date'];
    unset($row['_paket_lc'], $row['_brand_lc'], $row['_area_lc']);
    $dataMenunggak[] = $row;
}

usort($dataMenunggak, function ($a, $b) {
    $bulanCompare = ((int)$b['bulan_nunggak']) <=> ((int)$a['bulan_nunggak']);
    if ($bulanCompare !== 0) {
        return $bulanCompare;
    }

    return ((int)($b['hari_nunggak'] ?? 0)) <=> ((int)($a['hari_nunggak'] ?? 0));
});

$dataMenunggak1 = [];
$dataMenunggak2 = [];
foreach ($dataMenunggak as $row) {
    if ((int)$row['bulan_nunggak'] === 1) {
        $dataMenunggak1[] = $row;
    } elseif ((int)$row['bulan_nunggak'] >= 2) {
        $dataMenunggak2[] = $row;
    }
}

$totalNunggak1 = count($dataMenunggak1);
$totalNunggak2 = count($dataMenunggak2);

$menunggakIdList = [];
foreach ($dataMenunggak as $itemMenunggak) {
    if (!empty($itemMenunggak['IDPEL'])) {
        $menunggakIdList[] = (string)$itemMenunggak['IDPEL'];
    }
}
$menunggakIdList = array_values(array_unique($menunggakIdList));
$menunggakIdCsv = implode(',', $menunggakIdList);
$menunggakTotalTarget = count($menunggakIdList);

$existingTicketMap = [];
if (!empty($menunggakIdList)) {
    $ticketSource = isset($ticket_management_source) ? strtolower(trim((string)$ticket_management_source)) : 'tiket_manager';
    if (!in_array($ticketSource, ['tiket_manager', 'joblist'], true)) {
        $ticketSource = 'tiket_manager';
    }

    if ($ticketSource === 'joblist') {
        require_once __DIR__ . '/koneksidbabsensi2.php';
        if (isset($conn2) && $conn2) {
            $escapedIdpel = [];
            foreach ($menunggakIdList as $idpelValue) {
                $escapedIdpel[] = "'" . mysqli_real_escape_string($conn2, (string)$idpelValue) . "'";
            }

            if (!empty($escapedIdpel)) {
                $idpelIn = implode(',', $escapedIdpel);
                $normalizedDataExpr = "REPLACE(REPLACE(data, CONCAT(CHAR(13), CHAR(10)), CHAR(10)), CHAR(13), CHAR(10))";
                $dataWithLeadingLf = "CONCAT(CHAR(10), $normalizedDataExpr)";
                $idpelExpr1 = "CASE WHEN LOCATE(CONCAT(CHAR(10), 'ID PELANGGAN :'), $dataWithLeadingLf) > 0 THEN TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX($dataWithLeadingLf, CONCAT(CHAR(10), 'ID PELANGGAN :'), -1), CHAR(10), 1)) ELSE '' END";
                $idpelExpr2 = "CASE WHEN LOCATE(CONCAT(CHAR(10), 'ID PELANGGAN:'), $dataWithLeadingLf) > 0 THEN TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX($dataWithLeadingLf, CONCAT(CHAR(10), 'ID PELANGGAN:'), -1), CHAR(10), 1)) ELSE '' END";
                $idpelExpr3 = "CASE WHEN LOCATE(CONCAT(CHAR(10), 'IDPEL :'), $dataWithLeadingLf) > 0 THEN TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX($dataWithLeadingLf, CONCAT(CHAR(10), 'IDPEL :'), -1), CHAR(10), 1)) ELSE '' END";
                $idpelExpr = "COALESCE(NULLIF($idpelExpr1, ''), NULLIF($idpelExpr2, ''), NULLIF($idpelExpr3, ''))";
                $sqlTicketExisting = "SELECT id, status, report, data, $idpelExpr AS idpel_from_data FROM joblist WHERE status IN ('BARU','PENDING','CANCEL') AND (data LIKE '%ID PELANGGAN :%' OR data LIKE '%ID PELANGGAN:%' OR data LIKE '%IDPEL :%') AND $idpelExpr IN ($idpelIn) ORDER BY id DESC";
                $resultTicketExisting = mysqli_query($conn2, $sqlTicketExisting);

                while ($resultTicketExisting && ($ticketRow = mysqli_fetch_assoc($resultTicketExisting))) {
                    $idpelFromData = trim((string)($ticketRow['idpel_from_data'] ?? ''));
                    if ($idpelFromData === '' || isset($existingTicketMap[$idpelFromData])) {
                        continue;
                    }

                    $ticketNote = trim((string)($ticketRow['report'] ?? ''));
                    if ($ticketNote === '') {
                        $ticketNote = extractKendalaFromJoblistData((string)($ticketRow['data'] ?? ''));
                    }

                    $existingTicketMap[$idpelFromData] = [
                        'status' => strtoupper(trim((string)($ticketRow['status'] ?? ''))),
                        'note' => $ticketNote,
                        'id' => (int)($ticketRow['id'] ?? 0)
                    ];
                }
            }
        }
    } else {
        $escapedIdpel = [];
        foreach ($menunggakIdList as $idpelValue) {
            $escapedIdpel[] = "'" . mysqli_real_escape_string($conn, (string)$idpelValue) . "'";
        }

        if (!empty($escapedIdpel)) {
            $idpelIn = implode(',', $escapedIdpel);

            $ownedServerIds = [];
            $sqlOwned = "SELECT id FROM server WHERE user_id = " . (int)$current_user_id;
            $resOwned = mysqli_query($conn, $sqlOwned);
            while ($resOwned && ($rowOwned = mysqli_fetch_assoc($resOwned))) {
                $ownedServerIds[] = (int)($rowOwned['id'] ?? 0);
            }

            if (!empty($ownedServerIds)) {
                $serverIn = implode(',', array_map('intval', $ownedServerIds));
                $normalizedDataExpr = "REPLACE(REPLACE(detail, CONCAT(CHAR(13), CHAR(10)), CHAR(10)), CHAR(13), CHAR(10))";
                $dataWithLeadingLf = "CONCAT(CHAR(10), $normalizedDataExpr)";
                $idpelExpr1 = "CASE WHEN LOCATE(CONCAT(CHAR(10), 'ID PELANGGAN :'), $dataWithLeadingLf) > 0 THEN TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX($dataWithLeadingLf, CONCAT(CHAR(10), 'ID PELANGGAN :'), -1), CHAR(10), 1)) ELSE '' END";
                $idpelExpr2 = "CASE WHEN LOCATE(CONCAT(CHAR(10), 'ID PELANGGAN:'), $dataWithLeadingLf) > 0 THEN TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX($dataWithLeadingLf, CONCAT(CHAR(10), 'ID PELANGGAN:'), -1), CHAR(10), 1)) ELSE '' END";
                $idpelExpr3 = "CASE WHEN LOCATE(CONCAT(CHAR(10), 'IDPEL :'), $dataWithLeadingLf) > 0 THEN TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX($dataWithLeadingLf, CONCAT(CHAR(10), 'IDPEL :'), -1), CHAR(10), 1)) ELSE '' END";
                $idpelExpr = "COALESCE(NULLIF($idpelExpr1, ''), NULLIF($idpelExpr2, ''), NULLIF($idpelExpr3, ''))";
                $sqlTicketExisting = "SELECT id, status, report, detail, $idpelExpr AS idpel_from_data FROM billing_tiket_manager WHERE status IN ('BARU','PENDING','CANCEL') AND server_id IN ($serverIn) AND (detail LIKE '%ID PELANGGAN :%' OR detail LIKE '%ID PELANGGAN:%' OR detail LIKE '%IDPEL :%') AND $idpelExpr IN ($idpelIn) ORDER BY id DESC";
                $resultTicketExisting = mysqli_query($conn, $sqlTicketExisting);

                while ($resultTicketExisting && ($ticketRow = mysqli_fetch_assoc($resultTicketExisting))) {
                    $idpelFromData = trim((string)($ticketRow['idpel_from_data'] ?? ''));
                    if ($idpelFromData === '' || isset($existingTicketMap[$idpelFromData])) {
                        continue;
                    }

                    $ticketNote = trim((string)($ticketRow['report'] ?? ''));
                    if ($ticketNote === '') {
                        $ticketNote = extractKendalaFromJoblistData((string)($ticketRow['detail'] ?? ''));
                    }

                    $existingTicketMap[$idpelFromData] = [
                        'status' => strtoupper(trim((string)($ticketRow['status'] ?? ''))),
                        'note' => $ticketNote,
                        'id' => (int)($ticketRow['id'] ?? 0)
                    ];
                }
            }
        }
    }
}
?>

<div class="container-fluid py-4 px-3 px-md-4">

<!-- Card Cron Tiket Dismantle Otomatis dipindahkan ke system_setting.php -->

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-warning text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>Pelanggan Menunggak</h5>
            <div class="d-flex gap-2 flex-wrap">
                <button class="btn btn-success btn-sm" type="button" onclick="exportMenunggakExcel()">
                    <i class="fas fa-file-excel me-1"></i>Export Excel
                </button>
                <button class="btn btn-danger btn-sm" type="button" onclick="exportMenunggakPdf()">
                    <i class="fas fa-file-pdf me-1"></i>Export PDF
                </button>
                <button class="btn btn-light btn-sm" onclick="location.reload()"><i class="fas fa-sync-alt me-1"></i>Refresh</button>
            </div>
        </div>
        <div class="card-body">
            <div class="row mb-3">
                <div class="col-md-3 mb-2">
                    <div class="alert alert-danger mb-0 text-center">
                        <div class="fw-bold">Total Menunggak</div>
                        <div class="h4 mb-0"><?php echo count($dataMenunggak); ?></div>
                    </div>
                </div>
                <div class="col-md-3 mb-2">
                    <div class="alert alert-warning mb-0 text-center">
                        <div class="fw-bold">Nunggak 1 Siklus</div>
                        <div class="h4 mb-0"><?php echo $totalNunggak1; ?></div>
                    </div>
                </div>
                <div class="col-md-3 mb-2">
                    <div class="alert alert-warning mb-0 text-center">
                        <div class="fw-bold">Nunggak 2 Siklus+</div>
                        <div class="h4 mb-0"><?php echo $totalNunggak2; ?></div>
                    </div>
                </div>
                <div class="col-md-3 mb-2">
                    <div class="alert alert-info mb-0 text-center">
                        <div class="fw-bold">Target Broadcast</div>
                        <div class="h4 mb-0" id="selectedRecipientCount"><?php echo $menunggakTotalTarget; ?></div>
                    </div>
                </div>
            </div>

            <div class="row mb-3">
                <div class="col-md-5">
                    <label for="filterMenunggakTable" class="form-label">Cari Data Pelanggan Menunggak</label>
                    <input
                        type="text"
                        id="filterMenunggakTable"
                        class="form-control"
                        placeholder="Cari IDPEL, nama, paket, server, area, tipe bayar..."
                    >
                </div>
            </div>

            <div class="table-responsive" id="menunggakTableScrollWrap" style="max-height: 65vh; overflow-y: auto;">
                <table class="table table-striped" id="tabel-menunggak-khusus">
                    <thead style="position: sticky; top: 0; z-index: 2; background: #fff;">
                        <tr>
                            <th style="width:32px;"><input type="checkbox" id="checkAllRecipients"></th>
                            <th>IDPEL / Nama</th>
                            <th>Bayar / Tempo / Tgl Tempo</th>
                            <th>Paket / Server / Area</th>
                            <th>Harga</th>
                            <th>Bulan/Hari Tunggakan</th>
                            <th>Tiket Existing</th>
                            <th>Status Profile Saat Ini</th>
                        </tr>
                    </thead>
                    <tbody id="menunggakTableBody">
                        <?php
                        // Lazy-load: seluruh data menunggak TETAP dihitung sekali di server (status
                        // menunggak per pelanggan tidak bisa dijadikan WHERE SQL sederhana - lihat
                        // fetchNunggakData/evaluateMenunggakStatusForRow di atas), tapi HTML per baris
                        // ditampung dulu (bukan langsung di-echo) supaya JS bisa menampilkannya
                        // bertahap 20 baris per batch saat discroll - sama seperti tableshotspot.php.
                        $menunggakRowsHtml = [];
                        if (count($dataMenunggak) > 0) {
                            foreach ($dataMenunggak as $row) {
                                $idpelRow = (string)($row['IDPEL'] ?? '');
                                $pemilikRow = (string)($row['PEMILIK'] ?? '');
                                $areaRow = (string)($row['AREA'] ?? '');
                                $ticketInfo = isset($existingTicketMap[$idpelRow]) ? $existingTicketMap[$idpelRow] : null;
                                $hasServerConnection = $idpelRow !== '';
                                ob_start();
                                ?>
                                <tr>
                                    <td>
                                        <input
                                            type="checkbox"
                                            class="recipient-checkbox"
                                            value="<?php echo htmlspecialchars((string)$row['IDPEL'], ENT_QUOTES, 'UTF-8'); ?>"
                                            checked
                                        >
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars((string)$row['IDPEL'], ENT_QUOTES, 'UTF-8'); ?><br >
                                        <?php echo htmlspecialchars((string)$row['NAMA'], ENT_QUOTES, 'UTF-8'); ?>
                                    </td>
                                    <td>
                                        <?php $tipeTempoRow = (string)($row['TIPE_TEMPO'] ?? 'mengikuti_tanggal_tempo'); ?>
                                        <?php $tanggalReferensiBayar = getMenunggakReferenceDate($row); ?>
                                        <?php $jatuhTempoAktif = (string)($row['tanggal_jatuh_tempo_aktif'] ?? ''); ?>
                                        <?php
                                        $tipeTempoLabelRow = 'Rolling Due Date';
                                        if ($tipeTempoRow === 'mengikuti_tanggal_tempo') {
                                            $tipeTempoLabelRow = 'Fixed Due Date';
                                        } elseif ($tipeTempoRow === 'monthversary') {
                                            $tipeTempoLabelRow = 'Monthversary Due Date';
                                        }
                                        ?>
                                        <?php echo htmlspecialchars((string)($row['TIPE_BAYAR'] ?? 'prabayar'), ENT_QUOTES, 'UTF-8'); ?><br >
                                        <?php echo htmlspecialchars($tipeTempoLabelRow, ENT_QUOTES, 'UTF-8'); ?><br >
                                        <?php if ($tipeTempoRow === 'mengikuti_tanggal_tempo') { ?>
                                            Setiap tanggal : <?php echo htmlspecialchars((string)$fixedDueDateDay, ENT_QUOTES, 'UTF-8'); ?>
                                        <?php } elseif ($tipeTempoRow === 'monthversary') { ?>
                                            Setiap tanggal : <?php echo htmlspecialchars((string)getMonthversaryAnchorDay($row), ENT_QUOTES, 'UTF-8'); ?>
                                        <?php } else { ?>
                                            <?php echo htmlspecialchars($tanggalReferensiBayar !== '' ? $tanggalReferensiBayar : '-', ENT_QUOTES, 'UTF-8'); ?>
                                        <?php } ?>
                                        <?php
                                        $lastPaidDisplay = isset($row['last_paid']) && $row['last_paid'] !== '' && $row['last_paid'] !== null ? $row['last_paid'] : null;
                                        $lastPengunaanDisplay = isset($row['last_pengunaan']) && $row['last_pengunaan'] !== '' && $row['last_pengunaan'] !== null ? $row['last_pengunaan'] : null;
                                        ?>
                                        <hr class="my-1">
                                        <div class="small text-muted">
                                            <span class="fw-semibold">Terakhir Bayar:</span> <?php echo htmlspecialchars($lastPaidDisplay ?? '-', ENT_QUOTES, 'UTF-8'); ?><br>
                                            <span class="fw-semibold">Penggunaan:</span> <?php echo htmlspecialchars($lastPengunaanDisplay ?? '-', ENT_QUOTES, 'UTF-8'); ?><br>
                                            <span class="fw-semibold">Jatuh Tempo Aktif:</span> <?php echo htmlspecialchars($jatuhTempoAktif !== '' ? $jatuhTempoAktif : '-', ENT_QUOTES, 'UTF-8'); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars((string)$row['PAKET'], ENT_QUOTES, 'UTF-8'); ?><br >
                                        <?php echo htmlspecialchars(isset($row['PEMILIK']) ? (string)$row['PEMILIK'] : '-', ENT_QUOTES, 'UTF-8'); ?><br >
                                        <?php echo htmlspecialchars(isset($row['AREA']) ? (string)$row['AREA'] : '-', ENT_QUOTES, 'UTF-8'); ?>
                                    </td>
                                    <td>Rp. <?php echo number_format((float)(isset($row['harga_paket']) ? $row['harga_paket'] : 0), 0, ',', '.'); ?></td>
                                    <td>
                                        <?php
                                        $bulanNunggakRow = (int)($row['bulan_nunggak'] ?? 0);
                                        $hariNunggakRow = (int)($row['hari_nunggak'] ?? 0);
                                        $totalHariNunggakRow = (int)($row['total_hari_nunggak'] ?? 0);

                                        if ($bulanNunggakRow <= 1) {
                                            echo $hariNunggakRow . ' hari';
                                            echo '<div class="small text-muted">1 siklus cron</div>';
                                        } else {
                                            echo $bulanNunggakRow . ' bulan';
                                            echo '<div class="small text-muted">Total ' . $totalHariNunggakRow . ' hari</div>';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php if ($ticketInfo !== null) { ?>
                                            <?php $ticketStatus = (string)($ticketInfo['status'] ?? '-'); ?>
                                            <span class="badge bg-<?php echo htmlspecialchars(getTicketStatusBadgeClass($ticketStatus), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($ticketStatus, ENT_QUOTES, 'UTF-8'); ?></span>
                                            <div class="small text-muted mt-1" style="white-space:normal;word-break:break-word;overflow-wrap:anywhere;"><?php echo htmlspecialchars((string)($ticketInfo['note'] !== '' ? $ticketInfo['note'] : '-'), ENT_QUOTES, 'UTF-8'); ?></div>
                                        <?php } else { ?>
                                            <span class="badge bg-success">Belum ada tiket</span>
                                        <?php } ?>
                                    </td>
                                    <td class="menunggak-profile-cell"
                                        data-idpel="<?php echo htmlspecialchars($idpelRow, ENT_QUOTES, 'UTF-8'); ?>"
                                        data-pemilik="<?php echo htmlspecialchars($pemilikRow, ENT_QUOTES, 'UTF-8'); ?>"
                                        data-area="<?php echo htmlspecialchars($areaRow, ENT_QUOTES, 'UTF-8'); ?>"
                                        data-can-check="<?php echo $hasServerConnection ? '1' : '0'; ?>">
                                        <span class="badge bg-secondary menunggak-profile-badge">Memuat...</span>
                                        <div class="small text-muted mt-1 menunggak-profile-text">Menunggu cek</div>
                                    </td>
                                </tr>
                                <?php
                                $menunggakRowsHtml[] = ob_get_clean();
                            }
                        } else {
                            ?>
                            <tr>
                                <td class="text-center text-muted" colspan="8">Tidak ada data pelanggan menunggak.</td>
                            </tr>
                            <?php
                        }
                        ?>
                        <tr id="menunggakLazySentinel" style="height:1px;">
                            <td colspan="8" style="padding:0;border:0;"></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div id="menunggakLazyLoadWrap" class="text-center py-3 d-none">
                <div id="menunggakLazyLoadIndicator" class="spinner-border spinner-border-sm text-primary d-none" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <span id="menunggakLazyLoadStatusText" class="text-secondary text-xs"></span>
            </div>
            <script>
                var menunggakRowsData = <?php echo json_encode($menunggakRowsHtml, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
            </script>

            <!-- ============================================================
                 LAZY LOAD: tampilkan baris pelanggan menunggak bertahap saat
                 discroll. Data sudah lengkap di memori (menunggakRowsData, hasil
                 satu kali fetchNunggakData yang tidak bisa dijadikan WHERE SQL
                 sederhana) -- yang di-lazy-kan cuma render DOM-nya + kapan cek
                 status profile (loadMenunggakProfileStatus) tiap baris mulai
                 jalan, sama seperti tableshotspot.php.
                 ============================================================ -->
            <script>
            (function() {
                var CHUNK_SIZE = 20;
                var revealedCount = 0;
                var isRevealing = false;
                var allRevealed = false;

                var tableBody = document.getElementById('menunggakTableBody');
                var sentinelRow = document.getElementById('menunggakLazySentinel');
                var lazyWrap = document.getElementById('menunggakLazyLoadWrap');
                var lazyIndicator = document.getElementById('menunggakLazyLoadIndicator');
                var lazyStatusText = document.getElementById('menunggakLazyLoadStatusText');

                function updateStatusText() {
                    if (!lazyStatusText) return;
                    var total = menunggakRowsData.length;
                    if (total === 0) { lazyStatusText.textContent = ''; return; }
                    lazyStatusText.textContent = allRevealed
                        ? 'Semua data sudah dimuat (' + total + ' pelanggan menunggak).'
                        : 'Menampilkan ' + revealedCount + ' dari ' + total + ' pelanggan menunggak...';
                }

                function revealChunk(count) {
                    if (allRevealed || isRevealing) return;
                    var chunk = menunggakRowsData.slice(revealedCount, revealedCount + count);
                    if (chunk.length === 0) {
                        allRevealed = true;
                        updateStatusText();
                        if (lazyIndicator) lazyIndicator.classList.add('d-none');
                        return;
                    }

                    isRevealing = true;
                    if (lazyWrap) lazyWrap.classList.remove('d-none');
                    if (lazyIndicator) lazyIndicator.classList.remove('d-none');

                    // Parse via <tbody> sementara (bukan <div>) supaya <tr> tidak
                    // dibuang oleh parser HTML (tr hanya valid di konteks table).
                    var temp = document.createElement('tbody');
                    temp.innerHTML = chunk.join('');
                    var newRows = Array.prototype.slice.call(temp.children);
                    var newCells = [];
                    newRows.forEach(function(row) {
                        var cell = row.querySelector ? row.querySelector('.menunggak-profile-cell') : null;
                        if (cell) newCells.push(cell);
                        if (tableBody) {
                            if (sentinelRow) {
                                tableBody.insertBefore(row, sentinelRow);
                            } else {
                                tableBody.appendChild(row);
                            }
                        }
                    });

                    // Cek status profile HANYA untuk baris yang baru ditampilkan
                    if (typeof loadMenunggakProfileStatus === 'function' && newCells.length) {
                        loadMenunggakProfileStatus(newCells);
                    }

                    revealedCount += chunk.length;
                    allRevealed = revealedCount >= menunggakRowsData.length;
                    updateStatusText();
                    if (allRevealed && lazyIndicator) lazyIndicator.classList.add('d-none');
                    isRevealing = false;
                }

                function revealAllRemaining() {
                    while (!allRevealed) {
                        revealChunk(menunggakRowsData.length);
                    }
                }
                // Dipakai oleh search box & checkbox broadcast supaya tidak diam-diam
                // mengecualikan pelanggan yang belum ter-render dari daftar broadcast.
                window.menunggakRevealAllRemaining = revealAllRemaining;

                // Tabel sekarang scroll di dalam wrapper-nya sendiri (bukan lagi
                // ikut scroll halaman) -- observer & fallback scroll listener
                // WAJIB pakai wrapper itu sbg root/acuan, bukan window, kalau
                // tidak lazy-load tidak akan pernah terpicu sama sekali.
                var tableScrollWrap = document.getElementById('menunggakTableScrollWrap');

                if (sentinelRow && 'IntersectionObserver' in window) {
                    var observer = new IntersectionObserver(function(entries) {
                        entries.forEach(function(entry) {
                            if (entry.isIntersecting) revealChunk(CHUNK_SIZE);
                        });
                    }, { root: tableScrollWrap || null, rootMargin: '0px 0px 300px 0px', threshold: 0 });
                    observer.observe(sentinelRow);
                }

                function checkNearBottomAndReveal() {
                    if (allRevealed || isRevealing) return;
                    var nearBottom;
                    if (tableScrollWrap) {
                        nearBottom = tableScrollWrap.scrollTop + tableScrollWrap.clientHeight >= tableScrollWrap.scrollHeight - 300;
                    } else {
                        nearBottom = window.innerHeight + window.scrollY >= document.body.offsetHeight - 300;
                    }
                    if (nearBottom) revealChunk(CHUNK_SIZE);
                }

                if (tableScrollWrap) {
                    tableScrollWrap.addEventListener('scroll', checkNearBottomAndReveal, { passive: true });
                } else {
                    window.addEventListener('scroll', checkNearBottomAndReveal, { passive: true });
                }

                function initialReveal() {
                    revealChunk(CHUNK_SIZE);
                }
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', initialReveal);
                } else {
                    initialReveal();
                }
            })();
            </script>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-danger text-white">
            <h5 class="mb-0"><i class="fas fa-bullhorn me-2"></i>Broadcast Pelanggan Menunggak</h5>
            <small>Pilih penerima broadcast menggunakan checkbox pada tabel menunggak.</small>
        </div>
        <div class="card-body">
            <form id="menunggakBroadcastForm" onsubmit="submitMenunggakBroadcast(); return false;">
                <input type="hidden" name="idpel_list" id="selectedIdpelList" value="<?php echo htmlspecialchars($menunggakIdCsv, ENT_QUOTES, 'UTF-8'); ?>">

                <div class="mb-3">
                    <div class="alert mb-0" style="background:#0f1b38;border:1px solid #2a3f72;color:#e9f0ff;">
                        Jumlah data terpilih untuk broadcast: <strong id="selectedOnlyCount"><?php echo $menunggakTotalTarget; ?></strong>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Pilih BOT</label>
                        <div class="bot-checkbox-group border rounded p-2" id="grp_menunggak_bot" data-hidden-target="menunggakBotHidden" style="max-height:220px;overflow-y:auto;background:#fff;">
                            <div class="form-check">
                                <input class="form-check-input bot-random-toggle" type="checkbox" id="menunggakBot_RANDOM" value="RANDOM" checked>
                                <label class="form-check-label fw-bold" for="menunggakBot_RANDOM">ACAK dari SEMUA BOT</label>
                            </div>
                            <hr class="my-2">
                            <?php
                            $queryBotMenunggak = "SELECT DISTINCT namebot FROM botwa WHERE pemilik = '" . mysqli_real_escape_string($conn, $ceknama) . "'" . botAccessWhereClause($conn, $AKSES, $assigned_bot_ids ?? [], $asistant_name ?? '');
                            $resultBotMenunggak = mysqli_query($conn, $queryBotMenunggak);
                            while ($resultBotMenunggak && ($rowBotMenunggak = mysqli_fetch_assoc($resultBotMenunggak))) {
                                $botOpt = htmlspecialchars((string)$rowBotMenunggak['namebot'], ENT_QUOTES, 'UTF-8');
                                echo '<div class="form-check">';
                                echo '<input class="form-check-input bot-specific-checkbox" type="checkbox" id="menunggakBot_' . $botOpt . '" value="' . $botOpt . '">';
                                echo '<label class="form-check-label" for="menunggakBot_' . $botOpt . '">' . $botOpt . '</label>';
                                echo '</div>';
                            }
                            ?>
                        </div>
                        <div class="form-text">Centang <b>ACAK dari SEMUA BOT</b> untuk random dari semua bot. Centang satu bot saja untuk selalu memakai bot itu. Centang beberapa bot untuk random hanya dari bot yang dicentang.</div>
                        <!-- Hidden input inilah yang benar-benar dikirim sebagai field "botname" -->
                        <input type="hidden" id="menunggakBotHidden" name="botname" value="RANDOM">
                        <small class="text-muted">Penerima terpilih: <span id="selectedCountInline"><?php echo $menunggakTotalTarget; ?></span> pelanggan.</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label d-block">Isi Pesan</label>
                        <div class="btn-group mb-2" role="group" aria-label="Sumber pesan">
                            <input type="radio" class="btn-check" name="menunggak_pesan_mode" id="menunggakModeManual" checked onchange="toggleMenunggakPesanMode()">
                            <label class="btn btn-outline-primary btn-sm" for="menunggakModeManual">Pesan Manual</label>

                            <input type="radio" class="btn-check" name="menunggak_pesan_mode" id="menunggakModeTemplate" onchange="toggleMenunggakPesanMode()">
                            <label class="btn btn-outline-primary btn-sm" for="menunggakModeTemplate">Template Reminder Pembayaran</label>
                        </div>
                        <input type="hidden" name="use_template" id="menunggakUseTemplate" value="0">

                        <textarea rows="5" class="form-control" id="menunggakPesan" name="pesan" placeholder="Tulis pesan broadcast untuk pelanggan menunggak..." data-template-preview="<?php echo htmlspecialchars($menunggakTemplateRemainderPreview, ENT_QUOTES, 'UTF-8'); ?>"></textarea>
                        <?php if ($menunggakTemplateRemainderPreview === ''): ?>
                        <div id="menunggakTemplateEmptyWarning" class="alert alert-warning py-2 mb-0 small" style="display:none;">
                            <i class="fas fa-triangle-exclamation me-1"></i>
                            Template <strong>"Pesan Remainder Manual"</strong> belum diisi. Silakan atur dulu di menu
                            <a href="notification.php" target="_blank" rel="noopener">Notifikasi</a> sebelum kirim dengan mode ini.
                        </div>
                        <?php endif; ?>
                        <div id="menunggakTemplateInfo" class="alert alert-secondary py-2 mb-0 small" style="display:none;">
                            <i class="fas fa-file-alt me-1"></i>
                            Preview template <strong>"Pesan Remainder Manual"</strong> dari menu
                            <a href="notification.php" target="_blank" rel="noopener">Notifikasi</a>
                            -- placeholder <code>$NAMA</code>/<code>$IDPEL</code>/dll. di atas akan otomatis
                            diganti per penerima saat dikirim. Ubah isi templatenya di sana kalau perlu.
                        </div>
                    </div>
                </div>

                <?php
                $telegram_bot_options_menunggak = [];
                if (function_exists('telegramBotAccessWhereClause')) {
                    $queryTelegramBotMenunggak = "SELECT DISTINCT namebot FROM bottelegram WHERE pemilik = '" . mysqli_real_escape_string($conn, $ceknama) . "'" . telegramBotAccessWhereClause($conn, $AKSES, $assigned_telegram_bot_ids ?? [], $asistant_name ?? '');
                    $resultTelegramBotMenunggak = mysqli_query($conn, $queryTelegramBotMenunggak);
                    while ($resultTelegramBotMenunggak && ($rowTgBotMenunggak = mysqli_fetch_assoc($resultTelegramBotMenunggak))) {
                        $tgBotOptMenunggak = trim((string)($rowTgBotMenunggak['namebot'] ?? ''));
                        if ($tgBotOptMenunggak !== '') {
                            $telegram_bot_options_menunggak[] = $tgBotOptMenunggak;
                        }
                    }
                }
                ?>
                <?php if (!empty($telegram_bot_options_menunggak)): ?>
                <div class="mb-3">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="menunggakTelegramEnable" onchange="document.getElementById('menunggakTelegramGroupWrap').style.display = this.checked ? 'block' : 'none';">
                        <label class="form-check-label fw-bold" for="menunggakTelegramEnable"><i class="fab fa-telegram"></i> Kirim juga via Telegram</label>
                    </div>
                    <div id="menunggakTelegramGroupWrap" style="display:none;" class="mt-2">
                        <label class="form-label">Pilih BOT Telegram</label>
                        <div class="bot-checkbox-group border rounded p-2" id="grp_menunggak_bot_telegram" data-hidden-target="menunggakBotTelegramHidden" style="max-height:220px;overflow-y:auto;background:#fff;">
                            <div class="form-check">
                                <input class="form-check-input bot-random-toggle" type="checkbox" id="menunggakBotTelegram_RANDOM" value="RANDOM" checked>
                                <label class="form-check-label fw-bold" for="menunggakBotTelegram_RANDOM">ACAK dari SEMUA BOT TELEGRAM</label>
                            </div>
                            <hr class="my-2">
                            <?php foreach ($telegram_bot_options_menunggak as $tgBotOptMenunggak):
                                $tgBotOptMenunggakSafe = htmlspecialchars($tgBotOptMenunggak, ENT_QUOTES, 'UTF-8');
                            ?>
                            <div class="form-check">
                                <input class="form-check-input bot-specific-checkbox" type="checkbox" id="menunggakBotTelegram_<?= $tgBotOptMenunggakSafe ?>" value="<?= $tgBotOptMenunggakSafe ?>">
                                <label class="form-check-label" for="menunggakBotTelegram_<?= $tgBotOptMenunggakSafe ?>"><?= $tgBotOptMenunggakSafe ?></label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-text">Hanya pelanggan yang sudah "/start" bot Telegram (chat ID terhubung) yang akan menerima. Nomor WA tetap dikirim seperti biasa jika ada.</div>
                    </div>
                    <!-- Hidden input ini kosong kalau kanal Telegram tidak dicentang -> backend anggap tidak dipilih. -->
                    <input type="hidden" id="menunggakBotTelegramHidden" name="botname_telegram" value="">
                </div>
                <?php endif; ?>

                <div class="mb-3">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="menunggakEmailEnable">
                        <label class="form-check-label fw-bold" for="menunggakEmailEnable"><i class="fas fa-envelope"></i> Kirim juga via Email</label>
                    </div>
                    <div class="form-text">Dikirim ke email pelanggan (kolom EMAIL) kalau ada. Atur SMTP dulu di menu <a href="email_setting.php" target="_blank" rel="noopener">Email SMTP</a>.</div>
                    <!-- Hidden input ini kosong kalau kanal Email tidak dicentang -> backend anggap tidak dipilih. -->
                    <input type="hidden" id="menunggakSendEmail" name="send_email" value="">
                </div>

                <div class="mt-3">
                    <button type="button" id="menunggakSendBtn" onclick="submitMenunggakBroadcast()" class="btn btn-primary">Kirim Broadcast</button>
                    <button type="button" id="menunggakStopBtn" class="btn btn-danger ms-2" style="display:none;" onclick="stopMenunggakProcess()">Stop</button>
                </div>

                <div id="menunggakProcessStatus" class="alert alert-info align-items-center mt-3" style="display:none;">
                    <div id="menunggakProcessSpinner" class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></div>
                    <span id="menunggakProcessText">Sedang memproses pengiriman...</span>
                </div>

                <div id="menunggakProgressWrap" class="mt-2" style="display:none;">
                    <div class="progress" style="height: 20px;">
                        <div id="menunggakProgressBar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%;">0%</div>
                    </div>
                    <div id="menunggakProgressMeta" class="small text-muted mt-1">0/0 | Berhasil: 0 | Gagal: 0</div>
                </div>

                <div id="menunggakDebugContainer" class="mt-3" style="display:none;">
                    <label class="form-label">Debug Kirim Pesan Menunggak</label>
                    <pre id="menunggakDebugOutput" class="p-3" style="background:#111827;color:#e5e7eb;border-radius:8px;max-height:320px;overflow:auto;"></pre>
                </div>
            </form>
        </div>
    </div>

    <?php if ($AKSES === 'ADMIN'): ?>
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-secondary text-white">
            <h5 class="mb-0"><i class="fas fa-ticket-alt me-2"></i>Buat Tiket Dari Data Terpilih</h5>
            <small>Pilih pelanggan dari checkbox pada tabel, lalu buat tiket sekaligus.</small>
        </div>
        <div class="card-body">
            <form id="menunggakTicketForm" onsubmit="submitMenunggakTicketMassal(); return false;">
                <input type="hidden" name="idpel_list" id="selectedIdpelTicketList" value="<?php echo htmlspecialchars($menunggakIdCsv, ENT_QUOTES, 'UTF-8'); ?>">

                <div class="mb-3">
                    <div class="alert mb-0" style="background:#0f1b38;border:1px solid #2a3f72;color:#e9f0ff;">
                        Jumlah data terpilih untuk tiket: <strong id="selectedTicketCount"><?php echo $menunggakTotalTarget; ?></strong>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="menunggakTicketTipe" class="form-label">Tipe Tiket</label>
                        <select class="form-select" id="menunggakTicketTipe" name="tipe" required>
                            <option value="DISMANTLE" selected>DISMANTLE</option>
                        </select>
                    </div>
                    <div class="col-md-8">
                        <label for="menunggakTicketKendala" class="form-label">Kendala</label>
                        <select class="form-select" id="menunggakTicketKendala" name="kendala" required>
                            <option value="">Pilih Kendala</option>
                            <option value="Tidak ada pembayaran lanjutan">Tidak ada pembayaran lanjutan</option>
                            <option value="Pindah rumah">Pindah rumah</option>
                            <option value="Pindah ke provider lain">Pindah ke provider lain</option>
                            <option value="Lainnya">Lainnya</option>
                        </select>
                    </div>
                </div>

                <div class="mt-3">
                    <button type="button" id="menunggakTicketBtn" class="btn btn-secondary" onclick="submitMenunggakTicketMassal()">Buat Tiket Terpilih</button>
                </div>

                <div id="menunggakTicketStatus" class="alert mt-3" style="display:none;"></div>
            </form>
        </div>
    </div>
<?php endif; ?>
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="fas fa-percent me-2"></i>Pengaturan Diskon Data Terpilih</h5>
            <small>Diskon akan diterapkan ke pelanggan yang dicentang pada tabel menunggak.</small>
        </div>
        <div class="card-body">
            <form id="menunggakDiskonForm" onsubmit="submitMenunggakDiskonMassal(); return false;">
                <input type="hidden" name="idpel_list" id="selectedIdpelDiskonList" value="<?php echo htmlspecialchars($menunggakIdCsv, ENT_QUOTES, 'UTF-8'); ?>">

                <div class="mb-3">
                    <div class="alert mb-0" style="background:#0f1b38;border:1px solid #2a3f72;color:#e9f0ff;">
                        Jumlah data terpilih untuk diskon: <strong id="selectedDiskonCount"><?php echo $menunggakTotalTarget; ?></strong>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-3">
                        <label for="menunggakDiskonType" class="form-label">Jenis Diskon</label>
                        <select class="form-select" id="menunggakDiskonType" name="nominal_type" required>
                            <option value="nominal" selected>Nominal (Rp)</option>
                            <option value="persentase">Persentase (%)</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="menunggakDiskonNominal" class="form-label">Nilai Diskon</label>
                        <input type="number" class="form-control" id="menunggakDiskonNominal" name="nominal" min="1" step="0.01" required>
                    </div>
                    <div class="col-md-3">
                        <label for="menunggakDiskonMonth" class="form-label">Bulan Periode</label>
                        <select class="form-select" id="menunggakDiskonMonth" name="periode_month" required>
                            <?php
                            $bulan_penggunaan_diskon = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
                            $bulan_sekarang_diskon = $bulan_penggunaan_diskon[(int)date('n') - 1];
                            foreach ($bulan_penggunaan_diskon as $bln) {
                                $isSelected = ($bln === $bulan_sekarang_diskon) ? 'selected' : '';
                                echo '<option value="' . htmlspecialchars($bln, ENT_QUOTES, 'UTF-8') . '" ' . $isSelected . '>' . htmlspecialchars($bln, ENT_QUOTES, 'UTF-8') . '</option>';
                            }
                            ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="menunggakDiskonYear" class="form-label">Tahun Periode</label>
                        <input type="number" class="form-control" id="menunggakDiskonYear" name="periode_year" min="2000" max="2100" value="<?php echo (int)date('Y'); ?>" required>
                    </div>
                </div>

                <div class="mt-3">
                    <label for="menunggakDiskonKeterangan" class="form-label">Keterangan</label>
                    <textarea class="form-control" id="menunggakDiskonKeterangan" name="keterangan" rows="3" placeholder="Contoh: Keringanan pelanggan menunggak"></textarea>
                </div>

                <div class="mt-3">
                    <button type="button" id="menunggakDiskonBtn" class="btn btn-primary" onclick="submitMenunggakDiskonMassal()">Simpan Diskon Terpilih</button>
                </div>

                <div id="menunggakDiskonStatus" class="alert mt-3" style="display:none;"></div>
            </form>
        </div>
    </div>
</div>

<script src="assets/datatables/js/jquery.dataTables.min.js"></script>
<script src="assets/datatables/js/dataTables.bootstrap5.min.js"></script>
<script>
function applyMenunggakSearch(keyword) {
    // Pastikan semua baris (termasuk yang belum di-scroll) sudah ada di DOM
    // dulu sebelum di-filter, supaya pencarian & seleksi broadcast tidak diam-diam
    // melewatkan pelanggan menunggak yang belum sempat ter-render.
    if (typeof window.menunggakRevealAllRemaining === 'function') {
        window.menunggakRevealAllRemaining();
    }

    const q = String(keyword || '').toLowerCase();

    const rows = document.querySelectorAll('#tabel-menunggak-khusus tbody tr');
    rows.forEach(function (row) {
        const text = (row.textContent || '').toLowerCase();
        const isVisible = text.indexOf(q) !== -1;
        row.style.display = isVisible ? '' : 'none';

        const checkbox = row.querySelector('.recipient-checkbox');
        if (checkbox) {
            if (isVisible) {
                checkbox.disabled = false;
            } else {
                checkbox.checked = false;
                checkbox.disabled = true;
            }
        }
    });

    syncRecipients();
}

function renderMenunggakProfileCell(cell, badgeClass, badgeText, noteText) {
    const badgeEl = cell.querySelector('.menunggak-profile-badge');
    const textEl = cell.querySelector('.menunggak-profile-text');
    if (!badgeEl || !textEl) {
        return;
    }

    badgeEl.className = 'badge menunggak-profile-badge ' + badgeClass;
    badgeEl.textContent = badgeText;
    textEl.textContent = noteText;
}

async function fetchMenunggakProfileStatusForCell(cell) {
    const canCheck = cell.getAttribute('data-can-check') === '1';
    if (!canCheck) {
        renderMenunggakProfileCell(cell, 'bg-secondary', 'Tidak tersedia', 'Server pelanggan tidak ditemukan');
        return;
    }

    const idpel = cell.getAttribute('data-idpel') || '';
    const pemilik = cell.getAttribute('data-pemilik') || '';
    const area = cell.getAttribute('data-area') || '';

    if (!idpel || !pemilik) {
        renderMenunggakProfileCell(cell, 'bg-secondary', 'Tidak tersedia', 'Data pelanggan tidak lengkap');
        return;
    }

    try {
        const payload = new URLSearchParams({
            idpel: idpel
        });

        const response = await fetch('proses/getcustomeronline.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: payload.toString(),
            cache: 'no-store'
        });

        const data = await response.json();
        if (!response.ok || !data || !data.success) {
            const msg = data && data.message ? data.message : 'Gagal mengambil status profile';
            renderMenunggakProfileCell(cell, 'bg-danger', 'Error', msg);
            return;
        }

        const status = String(data.status || 'UNKNOWN').toUpperCase();
        const profileRaw = String(data.profile || '').trim();
        const loginViaRaw = String(data.login_via || '').trim().toLowerCase();
        const profile = (profileRaw && profileRaw.toLowerCase() !== 'unknown' && profileRaw.toLowerCase() !== 'n/a') ? profileRaw : '-';
        const loginVia = (loginViaRaw && loginViaRaw !== 'unknown' && loginViaRaw !== 'n/a') ? loginViaRaw : '-';
        const note = profile + ' | login: ' + loginVia;

        if (status === 'ONLINE') {
            renderMenunggakProfileCell(cell, 'bg-success', 'ONLINE', note);
        } else {
            renderMenunggakProfileCell(cell, 'bg-secondary', 'OFFLINE', note);
        }
    } catch (error) {
        renderMenunggakProfileCell(cell, 'bg-danger', 'Error', error && error.message ? error.message : 'Fetch error');
    }
}

async function loadMenunggakProfileStatus(cellsParam) {
    const cells = cellsParam || Array.from(document.querySelectorAll('.menunggak-profile-cell'));
    if (cells.length === 0) {
        return;
    }

    const concurrency = 4;
    let index = 0;

    async function worker() {
        while (index < cells.length) {
            const currentIndex = index;
            index += 1;
            const cell = cells[currentIndex];
            await fetchMenunggakProfileStatusForCell(cell);
        }
    }

    const workers = [];
    for (let i = 0; i < Math.min(concurrency, cells.length); i++) {
        workers.push(worker());
    }
    await Promise.all(workers);
}

// ==== Sinkronisasi checkbox BOT -> hidden input (aturan sama dengan encodeBotSelection() PHP) ====
function updateMenunggakBotSelection() {
    const group = document.getElementById('grp_menunggak_bot');
    const hidden = document.getElementById('menunggakBotHidden');
    if (!group || !hidden) return;

    const randomCb = group.querySelector('.bot-random-toggle');
    const specificCbs = group.querySelectorAll('.bot-specific-checkbox');
    const selected = Array.from(specificCbs).filter(cb => cb.checked).map(cb => cb.value);

    if (!randomCb.checked && selected.length > 0) {
        hidden.value = selected.length === 1 ? selected[0] : selected.join(',');
    } else {
        hidden.value = 'RANDOM';
    }
}

function initMenunggakBotCheckboxGroup() {
    const group = document.getElementById('grp_menunggak_bot');
    if (!group) return;

    const randomCb = group.querySelector('.bot-random-toggle');
    const specificCb = group.querySelectorAll('.bot-specific-checkbox');

    randomCb.addEventListener('change', function () {
        if (this.checked) specificCb.forEach(cb => cb.checked = false);
        updateMenunggakBotSelection();
    });

    specificCb.forEach(function (cb) {
        cb.addEventListener('change', function () {
            if (this.checked) randomCb.checked = false;
            const anyChecked = Array.prototype.some.call(specificCb, c => c.checked);
            if (!anyChecked) randomCb.checked = true; // fallback ke RANDOM
            updateMenunggakBotSelection();
        });
    });

    updateMenunggakBotSelection(); // set nilai awal hidden input
}

// ==== Sama seperti di atas, tapi utk grup checkbox bot Telegram (kanal opsional/opt-in) ====
function updateMenunggakTelegramBotSelection() {
    const group = document.getElementById('grp_menunggak_bot_telegram');
    const hidden = document.getElementById('menunggakBotTelegramHidden');
    if (!group || !hidden) return;

    const randomCb = group.querySelector('.bot-random-toggle');
    const specificCbs = group.querySelectorAll('.bot-specific-checkbox');
    const selected = Array.from(specificCbs).filter(cb => cb.checked).map(cb => cb.value);

    if (!randomCb.checked && selected.length > 0) {
        hidden.value = selected.length === 1 ? selected[0] : selected.join(',');
    } else {
        hidden.value = 'RANDOM';
    }
}

function initMenunggakTelegramBotCheckboxGroup() {
    const group = document.getElementById('grp_menunggak_bot_telegram');
    if (!group) return;

    const randomCb = group.querySelector('.bot-random-toggle');
    const specificCb = group.querySelectorAll('.bot-specific-checkbox');

    randomCb.addEventListener('change', function () {
        if (this.checked) specificCb.forEach(cb => cb.checked = false);
        updateMenunggakTelegramBotSelection();
    });

    specificCb.forEach(function (cb) {
        cb.addEventListener('change', function () {
            if (this.checked) randomCb.checked = false;
            const anyChecked = Array.prototype.some.call(specificCb, c => c.checked);
            if (!anyChecked) randomCb.checked = true; // fallback ke RANDOM
            updateMenunggakTelegramBotSelection();
        });
    });

    updateMenunggakTelegramBotSelection(); // set nilai awal hidden input
}

document.addEventListener('DOMContentLoaded', function () {
    const filterInput = document.getElementById('filterMenunggakTable');
    if (filterInput) {
        filterInput.addEventListener('input', function () {
            applyMenunggakSearch(filterInput.value);
        });
        filterInput.addEventListener('change', function () {
            applyMenunggakSearch(filterInput.value);
        });
    }

    // Catatan: TIDAK panggil syncRecipients()/loadMenunggakProfileStatus() di sini.
    // Nilai awal selectedIdpelList/selectedRecipientCount/selectedOnlyCount sudah
    // benar dari PHP (mencakup SELURUH data menunggak, bukan cuma yang ter-render).
    // syncRecipients() menghitung ulang dari checkbox yang ADA DI DOM - kalau
    // dipanggil sebelum lazy-load selesai, jumlah pelanggan menunggak yang belum
    // di-scroll bisa diam-diam hilang dari daftar broadcast. loadMenunggakProfileStatus
    // untuk baris pertama sudah dipanggil dari revealChunk() saat initial reveal.
    initMenunggakBotCheckboxGroup();
    initMenunggakTelegramBotCheckboxGroup();
});

function getMenunggakTableColumns() {
    return [
        'IDPEL / Nama',
        'Bayar / Tempo / Tgl Tempo',
        'Paket / Server / Area',
        'Harga',
        'Bulan/Hari Tunggakan',
        'Tiket Existing',
        'Status Profile Saat Ini'
    ];
}

function getMenunggakExportRows(includeHiddenRows) {
    const rows = Array.from(document.querySelectorAll('#tabel-menunggak-khusus tbody tr'));
    const result = [];

    rows.forEach(function (row) {
        if (!includeHiddenRows && row.style.display === 'none') {
            return;
        }

        const cells = Array.from(row.querySelectorAll('td'));
        if (cells.length === 0) {
            return;
        }

        const firstCellText = (cells[0].textContent || '').trim();
        if (firstCellText === 'Tidak ada data pelanggan menunggak.') {
            return;
        }

        const rowData = [];
        for (let i = 1; i < cells.length; i++) {
            let text = (cells[i].innerText || cells[i].textContent || '').trim();
            text = text.replace(/\s*\n\s*/g, '\n').replace(/\n{3,}/g, '\n\n');
            rowData.push(text);
        }
        result.push(rowData);
    });

    return result;
}

function buildMenunggakExportHtml(title, rows) {
    const columns = getMenunggakTableColumns();
    const summary = [
        ['Total Menunggak', <?php echo (int)count($dataMenunggak); ?>],
        ['Nunggak 1 Bulan', <?php echo (int)$totalNunggak1; ?>],
        ['Nunggak 2 Bulan+', <?php echo (int)$totalNunggak2; ?>]
    ];

    const escapeHtml = function (value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    };

    let html = '<!doctype html><html><head><meta charset="utf-8"><title>' + escapeHtml(title) + '</title>';
    html += '<style>body{font-family:Arial,sans-serif;font-size:12px;color:#111827;padding:20px} h1{font-size:18px;margin:0 0 8px} .meta{margin:0 0 14px;color:#4b5563} table{border-collapse:collapse;width:100%} th,td{border:1px solid #cbd5e1;padding:6px 8px;vertical-align:top;text-align:left} th{background:#f3f4f6} .summary{margin:0 0 14px;padding:10px;border:1px solid #cbd5e1;background:#f8fafc} .summary span{display:inline-block;min-width:180px;font-weight:bold} .nowrap{white-space:nowrap}</style>';
    html += '</head><body>';
    html += '<h1>' + escapeHtml(title) + '</h1>';
    html += '<div class="meta">Tanggal cetak: ' + escapeHtml(new Date().toLocaleString()) + '</div>';
    html += '<div class="summary">';
    summary.forEach(function (item) {
        html += '<div><span>' + escapeHtml(item[0]) + ':</span> ' + escapeHtml(item[1]) + '</div>';
    });
    html += '</div>';
    html += '<table><thead><tr>';
    columns.forEach(function (col) {
        html += '<th>' + escapeHtml(col) + '</th>';
    });
    html += '</tr></thead><tbody>';

    if (rows.length === 0) {
        html += '<tr><td colspan="' + columns.length + '">Tidak ada data pelanggan menunggak.</td></tr>';
    } else {
        rows.forEach(function (row) {
            html += '<tr>';
            row.forEach(function (cell) {
                html += '<td>' + escapeHtml(cell).replace(/\n/g, '<br>') + '</td>';
            });
            html += '</tr>';
        });
    }

    html += '</tbody></table></body></html>';
    return html;
}

function exportMenunggakExcel() {
    // Export harus mencakup SEMUA pelanggan menunggak, bukan cuma yang sudah
    // ter-render lewat lazy-load saat tombol export ditekan.
    if (typeof window.menunggakRevealAllRemaining === 'function') {
        window.menunggakRevealAllRemaining();
    }
    const rows = getMenunggakExportRows(false);
    const columns = getMenunggakTableColumns();
    const title = 'Pelanggan Menunggak';

    let html = '<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body>';
    html += '<table border="1"><tr><th colspan="' + columns.length + '">' + title + '</th></tr>';
    html += '<tr><td colspan="' + columns.length + '">Total Menunggak: <?php echo (int)count($dataMenunggak); ?> | Nunggak 1 Bulan: <?php echo (int)$totalNunggak1; ?> | Nunggak 2 Bulan+: <?php echo (int)$totalNunggak2; ?></td></tr>';
    html += '<tr>';
    columns.forEach(function (col) {
        html += '<th>' + col + '</th>';
    });
    html += '</tr>';

    if (rows.length === 0) {
        html += '<tr><td colspan="' + columns.length + '">Tidak ada data pelanggan menunggak.</td></tr>';
    } else {
        rows.forEach(function (row) {
            html += '<tr>';
            row.forEach(function (cell) {
                const safeCell = String(cell || '').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>');
                html += '<td>' + safeCell + '</td>';
            });
            html += '</tr>';
        });
    }

    html += '</table></body></html>';

    const blob = new Blob(['\ufeff' + html], { type: 'application/vnd.ms-excel;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'pelanggan_menunggak.xls';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    setTimeout(function () { URL.revokeObjectURL(url); }, 1500);
}

function exportMenunggakPdf() {
    // Export harus mencakup SEMUA pelanggan menunggak, bukan cuma yang sudah
    // ter-render lewat lazy-load saat tombol export ditekan.
    if (typeof window.menunggakRevealAllRemaining === 'function') {
        window.menunggakRevealAllRemaining();
    }
    const rows = getMenunggakExportRows(false);
    const html = buildMenunggakExportHtml('Pelanggan Menunggak', rows);
    const printWindow = window.open('', '_blank', 'width=1280,height=900');

    if (!printWindow) {
        alert('Popup diblokir browser. Izinkan popup untuk export PDF.');
        return;
    }

    printWindow.document.open();
    printWindow.document.write(html);
    printWindow.document.close();
    printWindow.focus();
    printWindow.onload = function () {
        printWindow.print();
    };
}

let menunggakActiveController = null;
let menunggakIsSubmitting = false;

function toggleMenunggakPesanMode() {
    const useTemplate = document.getElementById('menunggakModeTemplate').checked;
    const pesanInput = document.getElementById('menunggakPesan');
    const templateInfo = document.getElementById('menunggakTemplateInfo');
    const templateEmptyWarning = document.getElementById('menunggakTemplateEmptyWarning');
    const useTemplateHidden = document.getElementById('menunggakUseTemplate');
    const templatePreview = pesanInput.dataset.templatePreview || '';

    useTemplateHidden.value = useTemplate ? '1' : '0';
    if (useTemplate) {
        pesanInput.removeAttribute('required');
        pesanInput.readOnly = true;
        // Tampilkan ISI template apa adanya (placeholder $NAMA/$IDPEL/dll masih
        // literal, belum diganti -- itu baru terjadi per-penerima di backend
        // saat tombol Kirim ditekan), bukan cuma placeholder kosong.
        if (templatePreview !== '') {
            pesanInput.value = templatePreview;
        } else {
            pesanInput.value = '';
            pesanInput.placeholder = 'Template belum diisi -- atur dulu di menu Notifikasi.';
        }
        if (templateInfo) templateInfo.style.display = '';
        if (templateEmptyWarning) templateEmptyWarning.style.display = (templatePreview === '') ? '' : 'none';
    } else {
        pesanInput.setAttribute('required', 'required');
        pesanInput.readOnly = false;
        pesanInput.value = '';
        pesanInput.placeholder = 'Tulis pesan broadcast untuk pelanggan menunggak...';
        if (templateInfo) templateInfo.style.display = 'none';
        if (templateEmptyWarning) templateEmptyWarning.style.display = 'none';
    }
}

function getRecipientCheckboxes() {
    return Array.from(document.querySelectorAll('#tabel-menunggak-khusus tbody tr .recipient-checkbox')).filter(function (box) {
        const row = box.closest('tr');
        return row && row.style.display !== 'none';
    });
}

function syncRecipients() {
    const boxes = getRecipientCheckboxes();
    const selectedBoxes = boxes.filter(function (box) { return box.checked; });
    const selected = selectedBoxes.map(function (box) { return box.value; });

    document.getElementById('selectedIdpelList').value = selected.join(',');
    const ticketInput = document.getElementById('selectedIdpelTicketList');
    if (ticketInput) {
        ticketInput.value = selected.join(',');
    }
    const diskonInput = document.getElementById('selectedIdpelDiskonList');
    if (diskonInput) {
        diskonInput.value = selected.join(',');
    }
    document.getElementById('selectedCountInline').textContent = selected.length;
    document.getElementById('selectedRecipientCount').textContent = selected.length;

    const selectedOnlyCount = document.getElementById('selectedOnlyCount');
    if (selectedOnlyCount) {
        selectedOnlyCount.textContent = selected.length;
    }

    const selectedTicketCount = document.getElementById('selectedTicketCount');
    if (selectedTicketCount) {
        selectedTicketCount.textContent = selected.length;
    }

    const selectedDiskonCount = document.getElementById('selectedDiskonCount');
    if (selectedDiskonCount) {
        selectedDiskonCount.textContent = selected.length;
    }

    const checkAll = document.getElementById('checkAllRecipients');
    if (checkAll) {
        checkAll.checked = boxes.length > 0 && selected.length === boxes.length;
        checkAll.indeterminate = selected.length > 0 && selected.length < boxes.length;
    }
}

document.getElementById('checkAllRecipients').addEventListener('change', function () {
    // Render dulu semua baris yang belum di-scroll supaya "check all" benar-benar
    // memilih SEMUA pelanggan menunggak, bukan cuma yang kebetulan sudah tampil.
    if (typeof window.menunggakRevealAllRemaining === 'function') {
        window.menunggakRevealAllRemaining();
    }
    getRecipientCheckboxes().forEach(function (box) {
        box.checked = !!document.getElementById('checkAllRecipients').checked;
    });
    syncRecipients();
});

document.addEventListener('change', function (event) {
    if (event.target && event.target.classList.contains('recipient-checkbox')) {
        // Baris lain yang belum di-scroll harus tetap ikut terhitung (tetap
        // checked, sesuai state awal) - bukan hilang diam-diam dari daftar
        // broadcast hanya karena belum ter-render saat checkbox ini diubah.
        if (typeof window.menunggakRevealAllRemaining === 'function') {
            window.menunggakRevealAllRemaining();
        }
        syncRecipients();
    }
});

function menunggakSetProcessState(isProcessing, message, statusType) {
    const sendBtn = document.getElementById('menunggakSendBtn');
    const stopBtn = document.getElementById('menunggakStopBtn');
    const processStatus = document.getElementById('menunggakProcessStatus');
    const processText = document.getElementById('menunggakProcessText');
    const processSpinner = document.getElementById('menunggakProcessSpinner');

    processStatus.className = 'alert align-items-center mt-3 alert-' + (statusType || 'info');
    processText.textContent = message || '';

    if (isProcessing) {
        processStatus.style.display = 'flex';
        sendBtn.disabled = true;
        stopBtn.style.display = '';
        processSpinner.style.display = '';
    } else {
        processStatus.style.display = 'flex';
        sendBtn.disabled = false;
        stopBtn.style.display = 'none';
        processSpinner.style.display = 'none';
    }
}

function menunggakResetProgressUI() {
    const progressWrap = document.getElementById('menunggakProgressWrap');
    const progressBar = document.getElementById('menunggakProgressBar');
    const progressMeta = document.getElementById('menunggakProgressMeta');

    progressWrap.style.display = 'none';
    progressBar.style.width = '0%';
    progressBar.textContent = '0%';
    progressBar.classList.add('progress-bar-animated');
    progressMeta.textContent = '0/0 | Berhasil: 0 | Gagal: 0';
}

function menunggakUpdateProgressUI(payload) {
    const progressWrap = document.getElementById('menunggakProgressWrap');
    const progressBar = document.getElementById('menunggakProgressBar');
    const progressMeta = document.getElementById('menunggakProgressMeta');

    const processed = Number(payload.processed || 0);
    const total = Number(payload.total || payload.total_target || 0);
    const successCount = Number(payload.success_count || 0);
    const failedCount = Number(payload.failed_count || 0);
    const percent = total > 0 ? Math.round((processed / total) * 100) : 0;

    progressWrap.style.display = 'block';
    progressBar.style.width = percent + '%';
    progressBar.textContent = percent + '%';
    progressMeta.textContent = processed + '/' + total + ' | Berhasil: ' + successCount + ' | Gagal: ' + failedCount;

    if (percent >= 100) {
        progressBar.classList.remove('progress-bar-animated');
    }
}

function menunggakParseStreamLine(line, onEvent) {
    const safeLine = String(line || '').trim();
    if (!safeLine) return;

    const separatorIndex = safeLine.indexOf(':');
    if (separatorIndex < 0) return;

    const eventName = safeLine.slice(0, separatorIndex).toUpperCase();
    const body = safeLine.slice(separatorIndex + 1);
    let payload = {};

    try {
        payload = body ? JSON.parse(body) : {};
    } catch (e) {
        payload = { raw: body };
    }

    onEvent(eventName, payload, safeLine);
}

async function menunggakConsumeStreamResponse(response, onEvent) {
    if (!response.body || !response.body.getReader) {
        const text = await response.text();
        text.split(/\r?\n/).forEach(function (line) {
            menunggakParseStreamLine(line, onEvent);
        });
        return text;
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    let allText = '';

    while (true) {
        const readResult = await reader.read();
        if (readResult.done) break;

        const chunk = decoder.decode(readResult.value, { stream: true });
        allText += chunk;
        buffer += chunk;

        const lines = buffer.split(/\r?\n/);
        buffer = lines.pop() || '';
        lines.forEach(function (line) {
            menunggakParseStreamLine(line, onEvent);
        });
    }

    const rest = decoder.decode();
    if (rest) {
        allText += rest;
        buffer += rest;
    }

    if (buffer.trim() !== '') {
        menunggakParseStreamLine(buffer, onEvent);
    }

    return allText;
}

function stopMenunggakProcess() {
    if (menunggakActiveController) {
        menunggakActiveController.abort();
    }
}

async function submitMenunggakBroadcast() {
    if (menunggakIsSubmitting) {
        return;
    }

    syncRecipients();
    updateMenunggakBotSelection(); // pastikan botname sudah sinkron sebelum dikirim

    // Kanal Telegram opt-in: hidden "botname_telegram" cuma diisi kalau toggle
    // "Kirim juga via Telegram" benar2 dicentang, kalau tidak dipaksa kosong
    // (initMenunggakTelegramBotCheckboxGroup() sync awal bisa saja sudah
    // mengisi 'RANDOM' walau grup-nya masih disembunyikan).
    const menunggakTelegramEnableCb = document.getElementById('menunggakTelegramEnable');
    if (menunggakTelegramEnableCb && menunggakTelegramEnableCb.checked) {
        updateMenunggakTelegramBotSelection();
    } else {
        const menunggakTgHidden = document.getElementById('menunggakBotTelegramHidden');
        if (menunggakTgHidden) menunggakTgHidden.value = '';
    }

    // Kanal Email opt-in: hidden "send_email" cuma diisi '1' kalau checkbox
    // "Kirim juga via Email" dicentang.
    const menunggakEmailEnableCb = document.getElementById('menunggakEmailEnable');
    const menunggakEmailHidden = document.getElementById('menunggakSendEmail');
    if (menunggakEmailHidden) menunggakEmailHidden.value = (menunggakEmailEnableCb && menunggakEmailEnableCb.checked) ? '1' : '';

    const form = document.getElementById('menunggakBroadcastForm');
    const debugContainer = document.getElementById('menunggakDebugContainer');
    const debugOutput = document.getElementById('menunggakDebugOutput');

    const idpelList = (document.getElementById('selectedIdpelList').value || '').trim();
    if (!idpelList) {
        alert('Pilih minimal satu pelanggan sebagai penerima broadcast.');
        return;
    }

    if (!form.reportValidity()) {
        return;
    }

    let finalSummary = null;
    let streamErrorMessage = '';
    let hasDoneEvent = false;
    let doneMessage = '';

    debugContainer.style.display = 'none';
    debugOutput.textContent = '';
    menunggakResetProgressUI();
    menunggakIsSubmitting = true;
    menunggakSetProcessState(true, 'Sedang mengirim notifikasi ke pelanggan menunggak...', 'info');

    const formData = new FormData(form);
    formData.set('stream', '1');
    formData.set('debug', '1');

    const payload = new URLSearchParams(formData);
    menunggakActiveController = new AbortController();

    try {
        const response = await fetch('proses/notif_menunggak_manual.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: payload.toString(),
            signal: menunggakActiveController.signal,
            cache: 'no-store'
        });

        const responseText = await menunggakConsumeStreamResponse(response, function (eventName, payloadData, rawLine) {
            debugContainer.style.display = 'block';
            debugOutput.textContent += rawLine + '\n';

            if (eventName === 'START') {
                menunggakUpdateProgressUI({
                    processed: 0,
                    total: payloadData.total_target || 0,
                    success_count: 0,
                    failed_count: 0
                });
                menunggakSetProcessState(true, 'Proses pengiriman dimulai...', 'info');
            } else if (eventName === 'PROGRESS') {
                menunggakUpdateProgressUI(payloadData);
                const nama = payloadData.nama ? ' - ' + payloadData.nama : '';
                const statusText = payloadData.status_text ? ' (' + payloadData.status_text + ')' : '';
                menunggakSetProcessState(true, 'Mengirim ke ' + (payloadData.idpel || '-') + nama + statusText, 'info');
            } else if (eventName === 'DONE') {
                hasDoneEvent = true;
                doneMessage = payloadData.message || '';
                finalSummary = payloadData.summary || null;
                if (payloadData.summary) {
                    menunggakUpdateProgressUI({
                        processed: payloadData.summary.total_target || 0,
                        total: payloadData.summary.total_target || 0,
                        success_count: payloadData.summary.success_count || 0,
                        failed_count: payloadData.summary.failed_count || 0
                    });
                }
            } else if (eventName === 'ERROR') {
                streamErrorMessage = payloadData.message || 'Terjadi kesalahan saat proses broadcast menunggak.';
            }
        });

        if (!response.ok) {
            const fallbackText = responseText ? String(responseText).trim().split(/\r?\n/)[0] : '';
            const msg = streamErrorMessage || fallbackText || ('Proses selesai dengan status HTTP ' + response.status + '.');
            if (responseText && debugOutput.textContent.trim() === '') {
                debugContainer.style.display = 'block';
                debugOutput.textContent = responseText;
            }
            menunggakSetProcessState(false, msg, 'warning');
            alert(msg);
        } else if (streamErrorMessage) {
            menunggakSetProcessState(false, streamErrorMessage, 'warning');
            alert(streamErrorMessage);
        } else if (!hasDoneEvent) {
            const msgNoDone = 'Server tidak mengirim status DONE. Cek log debug broadcast.';
            menunggakSetProcessState(false, msgNoDone, 'warning');
            alert(msgNoDone);
        } else {
            const successCount = finalSummary ? Number(finalSummary.success_count || 0) : 0;
            const failedCount = finalSummary ? Number(finalSummary.failed_count || 0) : 0;
            const totalTarget = finalSummary ? Number(finalSummary.total_target || 0) : 0;
            const summaryText = 'Broadcast selesai. Total: ' + totalTarget + ' | Berhasil: ' + successCount + ' | Gagal: ' + failedCount;
            const doneText = doneMessage ? ('\nKeterangan: ' + doneMessage) : '';

            if (totalTarget === 0) {
                menunggakSetProcessState(false, summaryText + (doneMessage ? ' | ' + doneMessage : ''), 'warning');
                alert('Broadcast tidak dijalankan karena target valid 0. ' + summaryText + doneText);
            } else if (successCount > 0 && failedCount === 0) {
                menunggakSetProcessState(false, summaryText, 'success');
                alert(summaryText + doneText);
            } else if (successCount > 0 && failedCount > 0) {
                menunggakSetProcessState(false, summaryText, 'warning');
                alert('Broadcast selesai sebagian. ' + summaryText + doneText);
            } else {
                menunggakSetProcessState(false, summaryText, 'danger');
                alert('Broadcast gagal. ' + summaryText + doneText);
            }
        }
    } catch (error) {
        if (error.name === 'AbortError') {
            menunggakSetProcessState(false, 'Proses dihentikan dari browser.', 'warning');
            alert('Proses dihentikan.');
        } else {
            debugContainer.style.display = 'block';
            debugOutput.textContent = 'Fetch error: ' + error.message;
            menunggakSetProcessState(false, 'Gagal mengirim request ke server.', 'danger');
            alert('Gagal mengirim request. Cek debug.');
        }
    } finally {
        menunggakActiveController = null;
        menunggakIsSubmitting = false;
    }
}

async function submitMenunggakTicketMassal() {
    syncRecipients();

    const form = document.getElementById('menunggakTicketForm');
    const button = document.getElementById('menunggakTicketBtn');
    const statusBox = document.getElementById('menunggakTicketStatus');
    if (!form || !button || !statusBox) {
        return;
    }

    const selected = (document.getElementById('selectedIdpelTicketList').value || '').trim();
    if (!selected) {
        alert('Pilih minimal satu pelanggan untuk dibuatkan tiket.');
        return;
    }

    if (!form.reportValidity()) {
        return;
    }

    button.disabled = true;
    statusBox.style.display = 'block';
    statusBox.className = 'alert alert-info mt-3';
    statusBox.textContent = 'Sedang membuat tiket massal...';

    try {
        const payload = new URLSearchParams(new FormData(form));
        const response = await fetch('proses/buat_tiket_menunggak_massal.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: payload.toString(),
            cache: 'no-store'
        });

        let result;
        const responseText = await response.text();
        try {
            result = JSON.parse(responseText);
        } catch (parseErr) {
            statusBox.className = 'alert alert-danger mt-3';
            statusBox.innerHTML = '<strong>Error:</strong> Server mengembalikan response bukan JSON.<br><small>Kemungkinan ada error PHP di backend. Response: <code>' + responseText.substring(0, 500) + '</code></small>';
            alert('Gagal membuat tiket: response server bukan JSON. Lihat detail di halaman.');
            return;
        }

        if (!response.ok || !result.success) {
            const errorMessage = (result && result.message) ? result.message : 'Gagal membuat tiket massal.';
            statusBox.className = 'alert alert-danger mt-3';
            let errHtml = '<strong>Gagal:</strong> ' + errorMessage;
            if (result && result.file) {
                errHtml += '<br><small>File: ' + result.file + '</small>';
            }
            if (result && result.db_config) {
                errHtml += '<br><small>DB: host=' + (result.db_config.host||'') + ', db=' + (result.db_config.db||'') + '</small>';
            }
            if (result && result.warnings && result.warnings.length > 0) {
                errHtml += '<br><small>Warnings: ' + result.warnings.join('; ') + '</small>';
            }
            statusBox.innerHTML = errHtml;
            alert(errorMessage);
            return;
        }

        const summary = result.summary || {};
        let infoText = 'Selesai. Berhasil: ' + (summary.created || 0) +
            ' | Sudah ada tiket: ' + (summary.skipped_exists || 0) +
            ' | Tidak ditemukan: ' + (summary.skipped_not_found || 0) +
            ' | Gagal: ' + (summary.failed || 0);

        // Tampilkan semua detail per kategori
        const details = result.details || [];
        let detailHtml = '<strong>' + infoText + '</strong>';

        const categories = [
            {key: 'created', label: '? Berhasil dibuat', cls: 'text-success'},
            {key: 'exists', label: '?? Sudah ada tiket BARU/CANCEL/PENDING', cls: 'text-warning'},
            {key: 'not_found', label: '? Tidak ditemukan di DB', cls: 'text-danger'},
            {key: 'failed', label: '? Gagal insert', cls: 'text-danger'}
        ];
        categories.forEach(function(cat) {
            const items = details.filter(function(d) { return d.status === cat.key; });
            if (items.length > 0) {
                detailHtml += '<br><strong class="' + cat.cls + '">' + cat.label + ' (' + items.length + '):</strong><ul class="mb-1 mt-0" style="font-size:0.85em">';
                items.forEach(function(d) {
                    detailHtml += '<li>' + (d.idpel || '-') + ': ' + (d.message || '') + '</li>';
                });
                detailHtml += '</ul>';
            }
        });

        statusBox.className = (summary.failed > 0 || summary.skipped_not_found > 0) ? 'alert alert-warning mt-3' : 'alert alert-success mt-3';
        statusBox.innerHTML = detailHtml;
        alert('Pembuatan tiket massal selesai. Berhasil: ' + (summary.created || 0) + ', Sudah ada: ' + (summary.skipped_exists || 0) + ', Gagal: ' + (summary.failed || 0));
    } catch (error) {
        statusBox.className = 'alert alert-danger mt-3';
        statusBox.innerHTML = '<strong>Terjadi kesalahan:</strong> ' + error.message;
        alert('Terjadi kesalahan saat membuat tiket massal: ' + error.message);
    } finally {
        button.disabled = false;
    }
}

async function submitMenunggakDiskonMassal() {
    syncRecipients();

    const form = document.getElementById('menunggakDiskonForm');
    const button = document.getElementById('menunggakDiskonBtn');
    const statusBox = document.getElementById('menunggakDiskonStatus');
    if (!form || !button || !statusBox) {
        return;
    }

    const selected = (document.getElementById('selectedIdpelDiskonList').value || '').trim();
    if (!selected) {
        alert('Pilih minimal satu pelanggan untuk diberi diskon.');
        return;
    }

    if (!form.reportValidity()) {
        return;
    }

    button.disabled = true;
    statusBox.style.display = 'block';
    statusBox.className = 'alert alert-info mt-3';
    statusBox.textContent = 'Sedang menyimpan diskon massal...';

    try {
        const payload = new URLSearchParams(new FormData(form));
        const response = await fetch('proses/save_diskon_menunggak_massal.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: payload.toString(),
            cache: 'no-store'
        });

        const result = await response.json();
        if (!response.ok || !result.success) {
            const errorMessage = (result && result.message) ? result.message : 'Gagal menyimpan diskon massal.';
            statusBox.className = 'alert alert-danger mt-3';
            statusBox.textContent = errorMessage;
            alert(errorMessage);
            return;
        }

        const summary = result.summary || {};
        const infoText = 'Selesai. Berhasil: ' + (summary.created || 0) +
            ' | Tidak ditemukan: ' + (summary.skipped_not_found || 0) +
            ' | Gagal: ' + (summary.failed || 0);
        statusBox.className = 'alert alert-success mt-3';
        statusBox.textContent = infoText;
        alert('Pengaturan diskon massal selesai diproses.');
    } catch (error) {
        statusBox.className = 'alert alert-danger mt-3';
        statusBox.textContent = 'Terjadi kesalahan: ' + error.message;
        alert('Terjadi kesalahan saat menyimpan diskon massal.');
    } finally {
        button.disabled = false;
    }
}
</script>

<style>
.menunggak-scroll-nav {
    position: fixed;
    left: 24px;
    bottom: 30px;
    z-index: 998;
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.menunggak-scroll-btn {
    width: 46px;
    height: 46px;
    border-radius: 50%;
    border: none;
    background: #fff;
    color: #fd7e14;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.18);
    font-size: 17px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all .2s ease;
}
.menunggak-scroll-btn:hover {
    background: #fd7e14;
    color: #fff;
    transform: scale(1.08);
}
@media (max-width: 576px) {
    .menunggak-scroll-nav { left: 14px; bottom: 14px; gap: 8px; }
    .menunggak-scroll-btn { width: 40px; height: 40px; font-size: 15px; }
}
</style>

<div class="menunggak-scroll-nav">
    <button type="button" class="menunggak-scroll-btn" id="menunggakScrollTopBtn" title="Ke atas halaman" onclick="window.scrollTo({top: 0, behavior: 'smooth'});">
        <i class="fas fa-chevron-up"></i>
    </button>
    <button type="button" class="menunggak-scroll-btn" id="menunggakScrollBottomBtn" title="Ke bawah halaman">
        <i class="fas fa-chevron-down"></i>
    </button>
</div>

<script>
(function () {
    var btnBottom = document.getElementById('menunggakScrollBottomBtn');
    if (!btnBottom) return;
    btnBottom.addEventListener('click', function () {
        // Ungkap dulu semua baris yang masih lazy-load, biar "ke bawah" benar2
        // sampai baris terakhir -- bukan cuma sampai ke indikator loading.
        if (typeof window.menunggakRevealAllRemaining === 'function') {
            window.menunggakRevealAllRemaining();
        }
        window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });
    });
})();
</script>

<?php require 'footer.php'; ?>