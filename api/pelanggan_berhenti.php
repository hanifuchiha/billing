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

function get_owner_scope_values_berhenti($conn, $pemilik) {
    $userId = 0;
    $stmtUser = $conn->prepare("SELECT id FROM user WHERE USERNAME=? LIMIT 1");
    if ($stmtUser) {
        $stmtUser->bind_param('s', $pemilik);
        $stmtUser->execute();
        $resUser = $stmtUser->get_result();
        if ($resUser && $resUser->num_rows > 0) {
            $userRow = $resUser->fetch_assoc();
            $userId = (int)($userRow['id'] ?? 0);
        }
    }

    $owners = [];
    if ($userId > 0) {
        $stmtSrv = $conn->prepare("SELECT DISTINCT PEMILIK FROM server WHERE user_id=? OR PEMILIK=?");
        if ($stmtSrv) {
            $stmtSrv->bind_param('is', $userId, $pemilik);
            $stmtSrv->execute();
            $resSrv = $stmtSrv->get_result();
            while ($resSrv && ($row = $resSrv->fetch_assoc())) {
                $owner = trim((string)($row['PEMILIK'] ?? ''));
                if ($owner !== '') {
                    $owners[$owner] = true;
                }
            }
        }
    }

    if (empty($owners)) {
        $owners[$pemilik] = true;
    }

    $quotedOwners = [];
    foreach (array_keys($owners) as $owner) {
        $quotedOwners[] = "'" . mysqli_real_escape_string($conn, $owner) . "'";
    }

    return [
        'user_id' => $userId,
        'owner_in' => implode(',', $quotedOwners),
        'owners' => array_keys($owners)
    ];
}

switch ($method) {
    case 'GET':
        // Ambil pelanggan berhenti milik pemilik.
        // Prioritas: table pelanggan_berhenti (jika ada), fallback ke table pelanggan.
        $search = trim($_GET['search'] ?? '');
        $bulan = trim($_GET['bulan'] ?? 'all');
        $tahun = trim($_GET['tahun'] ?? 'all');
        $scope = get_owner_scope_values_berhenti($conn, $pemilik);
        $ownerIn = $scope['owner_in'];
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

        $servers = [];
        $stmtSrv = $conn->prepare("SELECT DISTINCT PEMILIK, BRAND, AREA FROM server WHERE user_id=? OR PEMILIK=? ORDER BY AREA ASC, BRAND ASC");
        $stmtSrv->bind_param('is', $scope['user_id'], $pemilik);
        $stmtSrv->execute();
        $resSrv = $stmtSrv->get_result();
        while ($resSrv && ($srv = $resSrv->fetch_assoc())) {
            $servers[] = $srv;
        }

        $bots = [];
        $stmtBot = $conn->prepare("SELECT DISTINCT namebot FROM botwa WHERE pemilik=? ORDER BY namebot ASC");
        $stmtBot->bind_param('s', $pemilik);
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
