<?php
// api/pelanggan_berhenti.php
// Auth diganti ke _bootstrap.php::api_authenticate() (session -> username+password -> API key
// dari tabel `apikey`) -- sebelumnya endpoint ini tidak pernah cek API key.
require_once '../koneksibilling.php';
require_once '_bootstrap.php';
session_start();
api_cors();

$method = $_SERVER['REQUEST_METHOD'];
$input = api_read_input();

$auth = api_authenticate($conn, $input);
$pemilik = $auth['pemilik'];
if ($auth['method'] === 'apikey') {
    api_rate_limit($conn, $auth['api_key']);
}

// Scoping SEBELUMNYA (get_owner_scope_values_berhenti(), dihapus) tidak pernah resolve
// ASSISTANT->owner (server.user_id = id akun sendiri tidak akan match apa pun utk assistant,
// karena server dimiliki OWNER, bukan assistant-nya) DAN tidak filter AREA sama sekali (cuma
// PEMILIK) -- assistant/reseller kemungkinan melihat data kosong ATAU (kalau fallback-nya
// ke-trigger) berpotensi bocor pelanggan berhenti lintas-area. Diganti pola resmi
// _bootstrap.php::api_resolve_owner()/api_allowed_pemilik_list() yang sudah benar menangani
// ASSISTANT (via user.grup + user.server) sama seperti api/pelanggan.php, api/odp.php, dll.
$ctx = api_resolve_owner($conn, $pemilik);
if (!$ctx) {
    api_json(['success' => false, 'error' => 'User tidak ditemukan'], 401);
}

switch ($method) {
    case 'GET':
        // Ambil pelanggan berhenti milik pemilik.
        // Prioritas: table pelanggan_berhenti (jika ada), fallback ke table pelanggan.
        $search = trim($_GET['search'] ?? '');
        $bulan = trim($_GET['bulan'] ?? 'all');
        $tahun = trim($_GET['tahun'] ?? 'all');
        $pemilikList = api_allowed_pemilik_list($conn, $ctx);
        $ownerIn = api_pemilik_in_sql($conn, $pemilikList);
        $searchEsc = mysqli_real_escape_string($conn, $search);
        $bulanEsc = mysqli_real_escape_string($conn, $bulan);
        $tahunEsc = mysqli_real_escape_string($conn, $tahun);

        $data = [];
        $tableExists = false;
        $check = mysqli_query($conn, "SHOW TABLES LIKE 'pelanggan_berhenti'");
        if ($check && mysqli_num_rows($check) > 0) {
            $tableExists = true;
        }

        $whereExtra = [];
        if ($bulan !== '' && strtolower($bulan) !== 'all') {
            $whereExtra[] = "MONTH(tanggal_berhenti) = '" . intval($bulanEsc) . "'";
        }
        if ($tahun !== '' && strtolower($tahun) !== 'all') {
            $whereExtra[] = "YEAR(tanggal_berhenti) = '" . intval($tahunEsc) . "'";
        }
        if ($search !== '') {
            $whereExtra[] = "(IDPEL LIKE '%$searchEsc%' OR NAMA LIKE '%$searchEsc%' OR PAKET LIKE '%$searchEsc%' OR NOWA LIKE '%$searchEsc%' OR alamat LIKE '%$searchEsc%' OR alasan LIKE '%$searchEsc%')";
        }
        $whereExtraSql = empty($whereExtra) ? '' : (' AND ' . implode(' AND ', $whereExtra));

        if ($tableExists) {
            $where = "pemilik IN ($ownerIn)";
            $result = mysqli_query($conn, "SELECT * FROM pelanggan_berhenti WHERE $where $whereExtraSql ORDER BY tanggal_berhenti DESC, ID DESC");
        } else {
            $where = "PEMILIK IN ($ownerIn) AND STATUS IN ('BERHENTI','NONAKTIF','DISMANTLE','CANCEL')";
            $result = mysqli_query($conn, "SELECT * FROM pelanggan WHERE $where $whereExtraSql ORDER BY ID DESC");
        }

        $summary = [
            'total' => 0,
            'with_wa' => 0,
            'bulan' => $bulan,
            'tahun' => $tahun
        ];

        // Dropdown filter server/area: dibatasi ke server yang benar-benar di-assign ke akun
        // yang login (allowed_server_ids dari api_resolve_owner()), bukan lagi semua server owner.
        $servers = [];
        if (!empty($ctx['allowed_server_ids'])) {
            $serverIdsIn = implode(',', array_map('intval', $ctx['allowed_server_ids']));
            $resSrv = mysqli_query($conn, "SELECT DISTINCT PEMILIK, BRAND, AREA FROM server WHERE id IN ($serverIdsIn) ORDER BY AREA ASC, BRAND ASC");
            while ($resSrv && ($srv = mysqli_fetch_assoc($resSrv))) {
                $servers[] = $srv;
            }
        }

        // Bot WA milik OWNER akun ini (bukan username akun yang login -- utk ASSISTANT itu beda),
        // dipakai sbg pilihan bot broadcast.
        $bots = [];
        $ownerUsernameStmt = $conn->prepare('SELECT USERNAME FROM user WHERE id = ? LIMIT 1');
        $ownerUsernameStmt->bind_param('i', $ctx['owner_user_id']);
        $ownerUsernameStmt->execute();
        $ownerUsernameRow = $ownerUsernameStmt->get_result()->fetch_assoc();
        $ownerUsername = $ownerUsernameRow ? (string)$ownerUsernameRow['USERNAME'] : $pemilik;
        $stmtBot = $conn->prepare("SELECT DISTINCT namebot FROM botwa WHERE pemilik=? ORDER BY namebot ASC");
        $stmtBot->bind_param('s', $ownerUsername);
        $stmtBot->execute();
        $resBot = $stmtBot->get_result();
        while ($resBot && ($bot = $resBot->fetch_assoc())) {
            $bots[] = (string)($bot['namebot'] ?? '');
        }

        while ($row = mysqli_fetch_assoc($result)) {
            $summary['total']++;
            $nowa = trim((string)($row['NOWA'] ?? ($row['nowa'] ?? '')));
            if ($nowa !== '') {
                $summary['with_wa']++;
            }
            $data[] = [
                'IDPEL' => $row['IDPEL'] ?? ($row['idpel'] ?? ''),
                'NAMA' => $row['NAMA'] ?? ($row['nama'] ?? ''),
                'PAKET' => $row['PAKET'] ?? ($row['paket'] ?? ''),
                'STATUS' => $row['STATUS'] ?? ($row['status'] ?? 'BERHENTI'),
                'AREA' => $row['AREA'] ?? ($row['area'] ?? ''),
                'NOWA' => $row['NOWA'] ?? ($row['nowa'] ?? ''),
                'HARGA' => $row['HARGA'] ?? ($row['harga'] ?? ''),
                'TANGGALPASANG' => $row['TANGGALPASANG'] ?? ($row['tanggalpasang'] ?? ''),
                'alamat' => $row['alamat'] ?? '',
                'alasan' => $row['alasan'] ?? '',
                'tanggal_berhenti' => $row['tanggal_berhenti'] ?? '',
                'keterangan' => $row['keterangan'] ?? '',
                'pemilik' => $row['pemilik'] ?? $pemilik
            ];
        }
        echo json_encode(['success' => true, 'data' => $data, 'summary' => $summary, 'servers' => $servers, 'bots' => $bots]);
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Method tidak didukung']);
}
