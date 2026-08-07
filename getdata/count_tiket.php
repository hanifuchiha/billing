<?php
include '../cek-sesi.php'; // Pastikan koneksi ke database sudah benar

$conn = isset($conn) ? $conn : null;
$connBilling = $conn;
$ceknama = isset($ceknama) ? $ceknama : 'unknown';
$ticketSource = isset($ticket_management_source) ? strtolower(trim((string)$ticket_management_source)) : 'tiket_manager';
if (!in_array($ticketSource, ['tiket_manager', 'joblist'], true)) {
    $ticketSource = 'tiket_manager';
}

include '../koneksidbabsensi.php';
$conn = isset($conn) ? $conn : null;
if (!($conn instanceof mysqli)) {
    echo json_encode(['error' => 'Koneksi DB absensi tidak tersedia']);
    exit;
}

header('Content-Type: application/json');
$bulanIni = date('Y-m'); 
$dataKey = $_GET['data'] ?? ''; // parameter filter data
$servers = $_GET['servers'] ?? '[]';
$servers = json_decode($servers, true);
if (!is_array($servers)) {
    $servers = [];
}

header('Content-Type: application/json');

if ($ticketSource === 'tiket_manager' && $connBilling instanceof mysqli) {
    $ownerUserId = isset($USER_ID) ? (int)$USER_ID : (isset($current_user_id) ? (int)$current_user_id : 0);
    if ($ownerUserId <= 0) {
        echo json_encode([
            'dismantel' => 0,
            'dismantel_total' => 0,
            'maintenance_total' => 0,
            'maintenance' => 0,
            'instalasi_total' => 0,
            'migrasi' => 0,
            'migrasi_total' => 0,
            'latest_cancel_team' => null
        ]);
        exit;
    }

    $ownerEsc = (int)$ownerUserId;
    $serverFilterSql = "";
    $serverFilterSqlTotal = "";

    $ownedServerIds = [];
    $qOwned = mysqli_query($connBilling, "SELECT id, PEMILIK FROM server WHERE user_id = $ownerEsc");
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
    if (empty($scopeServerIds)) {
        echo json_encode([
            'dismantel' => 0,
            'dismantel_total' => 0,
            'maintenance_total' => 0,
            'maintenance' => 0,
            'instalasi_total' => 0,
            'migrasi' => 0,
            'migrasi_total' => 0,
            'latest_cancel_team' => null
        ]);
        exit;
    }

    $idIn = implode(',', array_map('intval', $scopeServerIds));
    $serverFilterSql = " AND server_id IN ($idIn)";
    $serverFilterSqlTotal = $serverFilterSql;

    $dataFilterSql = '';
    if ($dataKey !== '') {
        $dataLike = mysqli_real_escape_string($connBilling, (string)$dataKey);
        $dataFilterSql = " AND (judul LIKE '%$dataLike%' OR detail LIKE '%$dataLike%' OR report LIKE '%$dataLike%' OR project_name LIKE '%$dataLike%')";
    }

    $countType = static function($connRef, $type, $withDataFilter, $dataFilterSql, $serverFilterSql, $serverFilterSqlTotal) {
        $typeEsc = mysqli_real_escape_string($connRef, (string)$type);
        $extraFilter = $withDataFilter ? $dataFilterSql . $serverFilterSql : $serverFilterSqlTotal;
        $sql = "SELECT COUNT(*) AS total FROM billing_tiket_manager WHERE tipe = '$typeEsc' AND status IN ('BARU','PENDING')" . $extraFilter;
        $res = mysqli_query($connRef, $sql);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        return (int)($row['total'] ?? 0);
    };

    $dismantel = $countType($connBilling, 'DISMANTLE', true, $dataFilterSql, $serverFilterSql, $serverFilterSqlTotal);
    $maintenance = $countType($connBilling, 'MAINTENANCE', true, $dataFilterSql, $serverFilterSql, $serverFilterSqlTotal);
    $migrasi = $countType($connBilling, 'MIGRASI', true, $dataFilterSql, $serverFilterSql, $serverFilterSqlTotal);
    $total_instalasi = $countType($connBilling, 'INSTALLASI', false, $dataFilterSql, $serverFilterSql, $serverFilterSqlTotal);
    $dismantel_total = $countType($connBilling, 'DISMANTLE', false, $dataFilterSql, $serverFilterSql, $serverFilterSqlTotal);
    $maintenance_total = $countType($connBilling, 'MAINTENANCE', false, $dataFilterSql, $serverFilterSql, $serverFilterSqlTotal);
    $migrasi_total = $countType($connBilling, 'MIGRASI', false, $dataFilterSql, $serverFilterSql, $serverFilterSqlTotal);

    $latestCancelTeam = null;
    $sqlLatest = "SELECT t.report, u.USERNAME AS teknisi FROM billing_tiket_manager t LEFT JOIN user u ON u.id = t.teknisi_user_id WHERE t.tipe = 'DISMANTLE' AND t.status IN ('CANCEL','PENDING')" . $serverFilterSql . " ORDER BY COALESCE(t.done_at, t.updated_at, t.created_at) DESC LIMIT 1";
    $resLatest = mysqli_query($connBilling, $sqlLatest);
    if ($resLatest && ($rowLatest = mysqli_fetch_assoc($resLatest))) {
        $latestCancelTeam = trim((string)($rowLatest['teknisi'] ?? ''));
        if ($latestCancelTeam === '') {
            $latestCancelTeam = trim((string)($rowLatest['report'] ?? ''));
        }
        if ($latestCancelTeam === '') {
            $latestCancelTeam = null;
        }
    }

    echo json_encode([
        'dismantel' => $dismantel,
        'dismantel_total' => $dismantel_total,
        'maintenance_total' => $maintenance_total,
        'maintenance' => $maintenance,
        'instalasi_total' => $total_instalasi,
        'migrasi' => $migrasi,
        'migrasi_total' => $migrasi_total,
        'latest_cancel_team' => $latestCancelTeam
    ]);
    exit;
}

// Dismantel OPEN (BARU + PENDING)
$sql1 = "SELECT COUNT(*) as total FROM joblist 
         WHERE  tipe = 'DISMANTLE' 
           AND status IN ('BARU', 'PENDING')
           AND data LIKE '%$dataKey%'";
$res1 = $conn->query($sql1);
$dismantel = $res1->fetch_assoc()['total'] ?? 0;

// Maintenance OPEN (BARU + PENDING)
$sql2 = "SELECT COUNT(*) as total FROM joblist 
         WHERE  tipe = 'MAINTENANCE' 
           AND status IN ('BARU', 'PENDING')
           AND data LIKE '%$dataKey%'";
$res2 = $conn->query($sql2);
$maintenance = $res2->fetch_assoc()['total'] ?? 0;

// Migrasi OPEN (BARU + PENDING)
$sql4 = "SELECT COUNT(*) as total FROM joblist 
         WHERE  tipe = 'MIGRASI' 
           AND status IN ('BARU', 'PENDING')
           AND data LIKE '%$dataKey%'";
$res4 = $conn->query($sql4);
$migrasi = $res4->fetch_assoc()['total'] ?? 0;

// Dismantel terbaru CANCEL bulan ini → ambil kolom team
$sql3 = "SELECT team
         FROM joblist
         WHERE tipe = 'DISMANTLE'
           AND status IN ('CANCEL', 'PENDING')  AND data LIKE '%$dataKey%'
           AND waktu LIKE '%$bulanIni%'
         ORDER BY waktu DESC
         LIMIT 1";
$res3 = $conn->query($sql3);
$team_cancel = $res3->num_rows > 0 ? $res3->fetch_assoc()['team'] : null;





// default
$total_instalasi = 0;

// kalau ada server list valid
if (!empty($servers) && is_array($servers)) {
    // escape tiap nama server
    $safeServers = array_map(function($s) use ($conn) {
        return "'" . $conn->real_escape_string($s) . "'";
    }, $servers);

    // gabungkan jadi string untuk IN()
    $inList = implode(",", $safeServers);

    // query instalasi (BARU + PENDING)
    $sql = "SELECT COUNT(*) as total 
            FROM joblist 
            WHERE tipe = 'INSTALLASI' 
              AND status IN ('BARU', 'PENDING')
              AND project IN ($inList)";
    $res = $conn->query($sql);
    $total_instalasi = $res->fetch_assoc()['total'] ?? 0;
}




// default
$dismantel_total = 0;

// kalau ada server list valid
if (!empty($servers) && is_array($servers)) {
    // escape tiap nama server
    $safeServers = array_map(function($s) use ($conn) {
        return "'" . $conn->real_escape_string($s) . "'";
    }, $servers);

    // gabungkan jadi string untuk IN()
    $inList = implode(",", $safeServers);

    // query instalasi (BARU + PENDING)
    $sql = "SELECT COUNT(*) as total 
            FROM joblist 
            WHERE tipe = 'DISMANTLE' 
              AND status IN ('BARU', 'PENDING')
              AND project IN ($inList)";
    $res = $conn->query($sql);
    $dismantel_total = $res->fetch_assoc()['total'] ?? 0;
}




// default
$maintenance_total = 0;

// kalau ada server list valid
if (!empty($servers) && is_array($servers)) {
    // escape tiap nama server
    $safeServers = array_map(function($s) use ($conn) {
        return "'" . $conn->real_escape_string($s) . "'";
    }, $servers);

    // gabungkan jadi string untuk IN()
    $inList = implode(",", $safeServers);

    // query instalasi (BARU + PENDING)
    $sql = "SELECT COUNT(*) as total 
            FROM joblist 
            WHERE tipe = 'MAINTENANCE' 
              AND status IN ('BARU', 'PENDING')
              AND project IN ($inList)";
    $res = $conn->query($sql);
    $maintenance_total = $res->fetch_assoc()['total'] ?? 0;
}




// default
$migrasi_total = 0;

// kalau ada server list valid
if (!empty($servers) && is_array($servers)) {
    // escape tiap nama server
    $safeServers = array_map(function($s) use ($conn) {
        return "'" . $conn->real_escape_string($s) . "'";
    }, $servers);

    // gabungkan jadi string untuk IN()
    $inList = implode(",", $safeServers);

    // query migrasi (BARU + PENDING)
    $sql = "SELECT COUNT(*) as total 
            FROM joblist 
            WHERE tipe = 'MIGRASI' 
              AND status IN ('BARU', 'PENDING')
              AND project IN ($inList)";
    $res = $conn->query($sql);
    $migrasi_total = $res->fetch_assoc()['total'] ?? 0;
}





// kirim response JSON
echo json_encode([
    "dismantel" => $dismantel,
    "dismantel_total" => $dismantel_total,
    "maintenance_total" => $maintenance_total,
    "maintenance" => $maintenance,
    "instalasi_total" => $total_instalasi,
    "migrasi" => $migrasi,
    "migrasi_total" => $migrasi_total,
    "latest_cancel_team" => $team_cancel
]);

// --- Catat log ke history ---
$history_file = "../notifbot/data/history-$ceknama.json";
if (!is_dir(dirname($history_file))) {
    mkdir(dirname($history_file), 0777, true);
}
$history = [];
if (file_exists($history_file)) {
    $history = json_decode(file_get_contents($history_file), true) ?: [];
}
// $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Retrieved ticket counts for system billing";
// file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));