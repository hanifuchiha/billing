<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function json_response(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function load_online_users_from_file(string $filePath): array
{
    if (!is_file($filePath)) {
        return [];
    }

    $raw = @file_get_contents($filePath);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $json = json_decode($raw, true);
    if (!is_array($json) || !isset($json['onlineUsers']) || !is_array($json['onlineUsers'])) {
        return [];
    }

    $lookup = [];
    foreach ($json['onlineUsers'] as $u) {
        $key = strtolower(trim((string)$u));
        if ($key !== '') {
            $lookup[$key] = true;
        }
    }
    return $lookup;
}

if (!isset($_SESSION['status']) || $_SESSION['status'] !== 'login') {
    json_response(401, [
        'success' => false,
        'error' => 'Unauthorized'
    ]);
}

require_once __DIR__ . '/../koneksidb.php';

if (!isset($conn) || !$conn) {
    json_response(500, [
        'success' => false,
        'error' => 'Koneksi database gagal'
    ]);
}

try {
    $odp_code = isset($_GET['odp']) ? trim((string)$_GET['odp']) : '';
    if ($odp_code === '') {
        json_response(400, [
            'success' => false,
            'error' => 'Parameter odp wajib diisi'
        ]);
    }

    $tableCheck = mysqli_query($conn, "SHOW TABLES LIKE 'pelanggan'");
    if (!$tableCheck || mysqli_num_rows($tableCheck) === 0) {
        json_response(500, [
            'success' => false,
            'error' => 'Tabel pelanggan tidak ditemukan'
        ]);
    }

    $columnsRes = mysqli_query($conn, "SHOW COLUMNS FROM pelanggan");
    $columns = [];
    while ($columnsRes && ($col = mysqli_fetch_assoc($columnsRes))) {
        $columns[$col['Field']] = true;
    }

    $paketExpr = "'' AS paket";
    if (isset($columns['PAKET_LAYANAN'])) {
        $paketExpr = "IFNULL(PAKET_LAYANAN, '') AS paket";
    } elseif (isset($columns['PAKET'])) {
        $paketExpr = "IFNULL(PAKET, '') AS paket";
    }

    $usernameExpr = isset($columns['USERNAME']) ? "IFNULL(USERNAME, '')" : "''";
    $idpelExpr = isset($columns['IDPEL']) ? "IFNULL(IDPEL, '')" : "''";
    $namaExpr = isset($columns['NAMA']) ? "IFNULL(NAMA, '')" : "''";
    $alamatExpr = isset($columns['ALAMAT']) ? "IFNULL(ALAMAT, '')" : "''";

    $waExpr = "''";
    if (isset($columns['NOWA'])) {
        $waExpr = "IFNULL(NOWA, '')";
    } elseif (isset($columns['NO_HP'])) {
        $waExpr = "IFNULL(NO_HP, '')";
    }

    // Resolve scope (PEMILIK/AREA yang boleh diakses sesi ini) SEBELUM query
    // pelanggan -- sebelumnya WHERE cuma ODP='...' tanpa scoping sama sekali,
    // jadi kalau ada ODP dgn nama yg sama persis di akun/area LAIN, datanya
    // ikut kebocor ke sesi ini (IDOR lintas tenant). Pola sama persis dgn
    // dashboard.php/tables.php: PEMILIK dari server milik user_id pemilik
    // (utk ASSISTANT, dari user_id GRUP-nya/owner), AREA dibatasi lagi kalau
    // sesi ini ASSISTANT (dari kolom user.server milik assistant itu sendiri).
    $sessionUser = trim((string)($_SESSION['PEMILIK'] ?? ''));
    $roleRow = null;
    $scopePemilikList = [];
    $scopeAreaList = null; // null = tidak dibatasi AREA (akun utama/ADMIN)
    if ($sessionUser !== '') {
        $sessionEsc = mysqli_real_escape_string($conn, $sessionUser);
        $roleRes = mysqli_query($conn, "SELECT id, USERNAME, STATUS, grup, server FROM user WHERE USERNAME = '{$sessionEsc}' LIMIT 1");
        $roleRow = $roleRes ? mysqli_fetch_assoc($roleRes) : null;

        $scopeOwnerUserId = $roleRow ? (int)$roleRow['id'] : 0;
        $isAssistantSession = $roleRow && strtoupper((string)($roleRow['STATUS'] ?? '')) === 'ASSISTANT';
        if ($isAssistantSession && !empty($roleRow['grup'])) {
            $scopeOwnerUserId = (int)$roleRow['grup'];
        }

        if ($scopeOwnerUserId > 0) {
            $ownerServerRes = mysqli_query($conn, "SELECT PEMILIK FROM server WHERE user_id = {$scopeOwnerUserId}");
            while ($ownerServerRes && ($r = mysqli_fetch_assoc($ownerServerRes))) {
                if (!empty($r['PEMILIK'])) {
                    $scopePemilikList[] = (string)$r['PEMILIK'];
                }
            }
        }
        $scopePemilikList = array_values(array_unique(array_filter($scopePemilikList)));

        if ($isAssistantSession) {
            $scopeAreaList = [];
            $assistantServerIds = json_decode((string)($roleRow['server'] ?? ''), true);
            if (is_array($assistantServerIds) && count($assistantServerIds) > 0) {
                $idIn = implode(',', array_map('intval', $assistantServerIds));
                $areaRes = mysqli_query($conn, "SELECT DISTINCT AREA FROM server WHERE id IN ({$idIn})");
                while ($areaRes && ($r = mysqli_fetch_assoc($areaRes))) {
                    if (!empty($r['AREA'])) {
                        $scopeAreaList[] = (string)$r['AREA'];
                    }
                }
            }
        }
    }

    if (empty($scopePemilikList)) {
        // Sesi tidak valid/tidak terhubung ke server manapun -- jangan bocorkan
        // data siapapun, balikin kosong apa adanya (bukan error) spy UI tetap wajar.
        json_response(200, [
            'success' => true,
            'odp_code' => $odp_code,
            'total' => 0,
            'data' => [],
        ]);
    }

    $pemilikInSql = "'" . implode("','", array_map(function ($p) use ($conn) {
        return mysqli_real_escape_string($conn, $p);
    }, $scopePemilikList)) . "'";

    $areaWhereSql = '';
    if (is_array($scopeAreaList)) {
        if (empty($scopeAreaList)) {
            // ASSISTANT tanpa area yg di-assign sama sekali -- jangan tampilkan apapun.
            json_response(200, [
                'success' => true,
                'odp_code' => $odp_code,
                'total' => 0,
                'data' => [],
            ]);
        }
        $areaInSql = "'" . implode("','", array_map(function ($a) use ($conn) {
            return mysqli_real_escape_string($conn, $a);
        }, $scopeAreaList)) . "'";
        $areaWhereSql = " AND AREA IN ({$areaInSql})";
    }

    $odpEscaped = mysqli_real_escape_string($conn, $odp_code);
    $sql = "SELECT {$idpelExpr} AS idpel, {$namaExpr} AS nama, {$usernameExpr} AS username, {$paketExpr}, {$alamatExpr} AS alamat, {$waExpr} AS nowa
            FROM pelanggan
            WHERE ODP = '{$odpEscaped}' AND PEMILIK IN ({$pemilikInSql}){$areaWhereSql}
            ORDER BY nama ASC
            LIMIT 300";

    $result = mysqli_query($conn, $sql);
    if (!$result) {
        throw new Exception('Query pelanggan gagal: ' . mysqli_error($conn));
    }

    $hasActiveConnections = false;
    $activeTableCheck = mysqli_query($conn, "SHOW TABLES LIKE 'active_connections'");
    if ($activeTableCheck && mysqli_num_rows($activeTableCheck) > 0) {
        $hasActiveConnections = true;
    }

    // Sumber status online utama: serverlog/*_online_client.txt (dipakai juga di halaman lain).
    $onlineUsersLookup = [];
    if ($sessionUser !== '') {
        $candidateLogUsers = [$sessionUser];
        if ($roleRow && strtoupper((string)($roleRow['STATUS'] ?? '')) === 'ASSISTANT' && !empty($roleRow['grup'])) {
            $ownerId = (int)$roleRow['grup'];
            if ($ownerId > 0) {
                $ownerRes = mysqli_query($conn, "SELECT USERNAME FROM user WHERE id = {$ownerId} LIMIT 1");
                $ownerRow = $ownerRes ? mysqli_fetch_assoc($ownerRes) : null;
                if ($ownerRow && !empty($ownerRow['USERNAME'])) {
                    $candidateLogUsers[] = (string)$ownerRow['USERNAME'];
                }
            }
        }

        $candidateLogUsers = array_values(array_unique(array_filter($candidateLogUsers)));
        foreach ($candidateLogUsers as $logUser) {
            $logFile = __DIR__ . '/../serverlog/' . $logUser . '_online_client.txt';
            $loaded = load_online_users_from_file($logFile);
            if (!empty($loaded)) {
                $onlineUsersLookup = $onlineUsersLookup + $loaded;
            }
        }
    }

    $data = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $isOnline = false;
        $idpel = (string)($row['idpel'] ?? '');
        $username = (string)($row['username'] ?? '');

        // Cek dari file online_client dulu (mengikuti pola data realtime existing).
        $statusCandidates = [];
        if ($idpel !== '') {
            $statusCandidates[] = strtolower(trim($idpel));
        }
        if ($username !== '') {
            $statusCandidates[] = strtolower(trim($username));
        }
        foreach ($statusCandidates as $candidate) {
            if ($candidate !== '' && isset($onlineUsersLookup[$candidate])) {
                $isOnline = true;
                break;
            }
        }

        // Fallback: active_connections untuk instalasi yang tidak punya file serverlog.
        if (!$isOnline && $hasActiveConnections) {
            $conditions = [];
            if ($username !== '') {
                $usernameEscaped = mysqli_real_escape_string($conn, $username);
                $conditions[] = "username = '{$usernameEscaped}'";
                $conditions[] = "name = '{$usernameEscaped}'";
            }
            if ($idpel !== '') {
                $idpelEscaped = mysqli_real_escape_string($conn, $idpel);
                $conditions[] = "username = '{$idpelEscaped}'";
                $conditions[] = "name = '{$idpelEscaped}'";
                $conditions[] = "idpel = '{$idpelEscaped}'";
            }

            if (!empty($conditions)) {
                $onlineSql = "SELECT 1 FROM active_connections WHERE " . implode(' OR ', $conditions) . " LIMIT 1";
                $onlineRes = @mysqli_query($conn, $onlineSql);
                $isOnline = ($onlineRes && mysqli_num_rows($onlineRes) > 0);
            }
        }

        $data[] = [
            'idpel' => $idpel,
            'nama' => (string)($row['nama'] ?? ''),
            'paket' => (string)($row['paket'] ?? ''),
            'alamat' => (string)($row['alamat'] ?? ''),
            'nowa' => (string)($row['nowa'] ?? ''),
            'username' => $username,
            'status' => $isOnline ? 'Online' : 'Offline',
            'is_online' => $isOnline
        ];
    }

    json_response(200, [
        'success' => true,
        'odp_code' => $odp_code,
        'total' => count($data),
        'data' => $data
    ]);
} catch (Throwable $e) {
    error_log('getPelangganByODP error: ' . $e->getMessage());
    json_response(500, [
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
