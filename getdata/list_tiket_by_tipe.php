<?php
/**
 * List tiket per tipe pekerjaan (INSTALLASI/DISMANTLE/MAINTENANCE/MIGRASI) untuk
 * modal "Show Data" di dashboard.php. Sumber data mengikuti setting
 * ticket_management_source pengguna (sama seperti count_tiket.php &
 * cron_maintenance_ticket.php) -- 'tiket_manager' (tabel billing_tiket_manager)
 * atau 'joblist' (tabel joblist di DB absensi).
 *
 * Kedua sumber menyimpan detail tiket sbg TEKS TERSTRUKTUR yang sama persis
 * (lihat buat_tiket.php): "ID PELANGGAN :x", "NAMA PELANGGAN :x", "NO WHATSAPP :x",
 * dst -- jadi NAMA & NOWA pelanggan diekstrak dgn regex yang sama utk kedua sumber.
 */
include '../cek-sesi.php';

header('Content-Type: application/json');

/**
 * Ekstrak field dari teks terstruktur tiket (dipakai billing_tiket_manager.detail
 * MAUPUN joblist.data -- sama persis formatnya, lihat buat_tiket.php).
 */
function extractTicketField(string $text, array $labels): string
{
    foreach ($labels as $label) {
        if (preg_match('/' . preg_quote($label, '/') . '\s*:([^\n]+)/i', $text, $m)) {
            $val = trim($m[1]);
            if ($val !== '') return $val;
        }
    }
    return '';
}

/**
 * Gabungkan ODP, KODE ODP, JUMLAH PELANGGAN, ALAMAT, dan KENDALA jadi satu
 * kolom "Keterangan" yang lengkap - sebelumnya cuma KENDALA saja yang
 * ditampilkan (mis. "Pelanggan offline/LOS - otomatis sistem"), sehingga
 * teknisi/admin harus buka detail tiket dulu utk tahu ODP/alamat pelanggan.
 */
function buildKeteranganLengkap(string $text): string
{
    $parts = [];

    $odp = extractTicketField($text, ['KODE ODP', 'ODP']);
    if ($odp !== '') $parts[] = 'ODP: ' . $odp;

    $jumlah = extractTicketField($text, ['JUMLAH PELANGGAN']);
    if ($jumlah !== '') $parts[] = 'Jumlah Pelanggan: ' . $jumlah;

    $alamat = extractTicketField($text, ['ALAMAT']);
    if ($alamat !== '') $parts[] = 'Alamat: ' . $alamat;

    $kendala = extractTicketField($text, ['KENDALA']);
    if ($kendala !== '') $parts[] = $kendala;

    if (!empty($parts)) return implode(' | ', $parts);
    return trim($text);
}

$connBilling = isset($conn) ? $conn : null;
$ticketSource = isset($ticket_management_source) ? strtolower(trim((string)$ticket_management_source)) : 'tiket_manager';
if (!in_array($ticketSource, ['tiket_manager', 'joblist'], true)) {
    $ticketSource = 'tiket_manager';
}

$tipeMap = [
    'INSTALASI' => 'INSTALLASI',
    'INSTALLASI' => 'INSTALLASI',
    'DISMANTLE' => 'DISMANTLE',
    'DST' => 'DISMANTLE',
    'MAINTENANCE' => 'MAINTENANCE',
    'MT' => 'MAINTENANCE',
    'MIGRASI' => 'MIGRASI',
    'MGS' => 'MIGRASI',
];
$tipeRaw = strtoupper(trim((string)($_GET['tipe'] ?? '')));
$tipe = $tipeMap[$tipeRaw] ?? null;
if ($tipe === null) {
    echo json_encode(['success' => false, 'message' => 'Tipe tiket tidak dikenali', 'rows' => []]);
    exit;
}

// Status: 'ALL' / kosong -> semua status. Selain itu -> 1 status spesifik.
$statusAllowed = ['BARU', 'PENDING', 'DONE', 'CANCEL'];
$statusRaw = strtoupper(trim((string)($_GET['status'] ?? '')));
$statusFilter = in_array($statusRaw, $statusAllowed, true) ? $statusRaw : null;

$search = trim((string)($_GET['search'] ?? ''));

$servers = json_decode((string)($_GET['servers'] ?? '[]'), true);
if (!is_array($servers)) {
    $servers = [];
}

$rows = [];

if ($ticketSource === 'tiket_manager' && $connBilling instanceof mysqli) {
    $ownerUserId = isset($USER_ID) ? (int)$USER_ID : (isset($current_user_id) ? (int)$current_user_id : 0);
    if ($ownerUserId > 0) {
        $ownedServerIds = [];
        $qOwned = mysqli_query($connBilling, "SELECT id, PEMILIK FROM server WHERE user_id = $ownerUserId");
        while ($qOwned && ($rSrv = mysqli_fetch_assoc($qOwned))) {
            $sid = (int)($rSrv['id'] ?? 0);
            if ($sid > 0) {
                $ownedServerIds[$sid] = (string)($rSrv['PEMILIK'] ?? '');
            }
        }

        $selectedServerIds = [];
        if (!empty($servers)) {
            $serverLookup = array_flip(array_map('strval', $servers));
            foreach ($ownedServerIds as $sid => $pemilikName) {
                if (isset($serverLookup[(string)$pemilikName])) {
                    $selectedServerIds[] = (int)$sid;
                }
            }
        }
        $scopeServerIds = !empty($selectedServerIds) ? $selectedServerIds : array_keys($ownedServerIds);

        if (!empty($scopeServerIds)) {
            $idIn = implode(',', array_map('intval', $scopeServerIds));
            $tipeEsc = mysqli_real_escape_string($connBilling, $tipe);
            $statusSql = $statusFilter !== null
                ? " AND t.status = '" . mysqli_real_escape_string($connBilling, $statusFilter) . "'"
                : " AND t.status IN ('BARU','PENDING')";
            $searchSql = '';
            if ($search !== '') {
                $searchEsc = mysqli_real_escape_string($connBilling, $search);
                $searchSql = " AND (t.judul LIKE '%$searchEsc%' OR t.detail LIKE '%$searchEsc%')";
            }
            $sql = "SELECT t.id, t.judul, t.detail, t.status, t.pemilik, t.area, t.project_name, t.created_at,
                           u.USERNAME AS teknisi
                    FROM billing_tiket_manager t
                    LEFT JOIN user u ON u.id = t.teknisi_user_id
                    WHERE t.tipe = '$tipeEsc' AND t.server_id IN ($idIn)" . $statusSql . $searchSql . "
                    ORDER BY t.created_at DESC
                    LIMIT 300";
            $res = mysqli_query($connBilling, $sql);
            while ($res && ($r = mysqli_fetch_assoc($res))) {
                $detailText = (string)($r['detail'] ?? '');
                $rows[] = [
                    'id' => (int)$r['id'],
                    'source' => 'tiket_manager',
                    'judul' => (string)($r['judul'] ?? '-'),
                    'idpel' => extractTicketField($detailText, ['ID PELANGGAN']),
                    'nama' => extractTicketField($detailText, ['NAMA PELANGGAN', 'NAMA']),
                    'nowa' => extractTicketField($detailText, ['NO WHATSAPP', 'NOWA', 'WHATSAPP']),
                    'keterangan' => buildKeteranganLengkap($detailText),
                    'status' => (string)($r['status'] ?? ''),
                    'petugas' => trim((string)($r['teknisi'] ?? '')) ?: '-',
                    'project' => trim((string)($r['project_name'] ?? '')) ?: (string)($r['pemilik'] ?? '-'),
                    'area' => (string)($r['area'] ?? ''),
                    'tanggal' => (string)($r['created_at'] ?? ''),
                ];
            }
        }
    }
} elseif ($ticketSource === 'joblist') {
    $config_file = __DIR__ . '/../../joblist/config.json';
    $joblist_config = file_exists($config_file) ? json_decode(file_get_contents($config_file), true) : [];
    $joblist_host = $joblist_config['db_host_absensi'] ?? '';
    $joblist_user = $joblist_config['db_user_absensi'] ?? '';
    $joblist_pass = $joblist_config['db_pass_absensi'] ?? '';
    $joblist_db   = $joblist_config['db_name_absensi'] ?? '';

    if ($joblist_host !== '') {
        $joblist_conn = @mysqli_connect($joblist_host, $joblist_user, $joblist_pass, $joblist_db);
        if ($joblist_conn) {
            $tipeEsc = mysqli_real_escape_string($joblist_conn, $tipe);
            $projectFilterSql = '';
            if (!empty($servers)) {
                $safeServers = array_map(function ($s) use ($joblist_conn) {
                    return "'" . mysqli_real_escape_string($joblist_conn, (string)$s) . "'";
                }, $servers);
                $projectFilterSql = ' AND project IN (' . implode(',', $safeServers) . ')';
            }
            $statusSql = $statusFilter !== null
                ? " AND status = '" . mysqli_real_escape_string($joblist_conn, $statusFilter) . "'"
                : " AND status IN ('BARU','PENDING')";
            $searchSql = '';
            if ($search !== '') {
                $searchEsc = mysqli_real_escape_string($joblist_conn, $search);
                $searchSql = " AND (data LIKE '%$searchEsc%' OR nowa LIKE '%$searchEsc%')";
            }
            $sql = "SELECT id, data, report, status, team, project, waktu, nowa
                    FROM joblist
                    WHERE tipe = '$tipeEsc'" . $statusSql . $projectFilterSql . $searchSql . "
                    ORDER BY waktu DESC
                    LIMIT 300";
            $res = mysqli_query($joblist_conn, $sql);
            while ($res && ($r = mysqli_fetch_assoc($res))) {
                $dataText = (string)($r['data'] ?? '');
                $idpelLabel = extractTicketField($dataText, ['ID PELANGGAN']);
                $namaLabel = extractTicketField($dataText, ['NAMA PELANGGAN', 'NAMA']);
                $nowaLabel = extractTicketField($dataText, ['NO WHATSAPP', 'NOWA', 'WHATSAPP']) ?: trim((string)($r['nowa'] ?? ''));
                $keteranganLabel = buildKeteranganLengkap($dataText);
                if ($keteranganLabel === '') $keteranganLabel = trim((string)($r['report'] ?? ''));
                $rows[] = [
                    'id' => (int)$r['id'],
                    'source' => 'joblist',
                    'judul' => $idpelLabel !== '' ? $idpelLabel : ('Tiket #' . $r['id']),
                    'idpel' => $idpelLabel,
                    'nama' => $namaLabel,
                    'nowa' => $nowaLabel,
                    'keterangan' => $keteranganLabel,
                    'status' => (string)($r['status'] ?? ''),
                    'petugas' => trim((string)($r['team'] ?? '')) ?: '-',
                    'project' => (string)($r['project'] ?? '-'),
                    'area' => '',
                    'tanggal' => (string)($r['waktu'] ?? ''),
                ];
            }
            mysqli_close($joblist_conn);
        }
    }
}

echo json_encode(['success' => true, 'tipe' => $tipe, 'source' => $ticketSource, 'rows' => $rows]);
