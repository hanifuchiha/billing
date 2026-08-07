<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../koneksibilling.php';
session_start();

function out_json($success, $message, $extra = [], $code = 200)
{
    http_response_code($code);
    echo json_encode(array_merge([
        'success' => (bool)$success,
        'message' => (string)$message,
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function auth_user_mobile($conn, $username, $password)
{
    $stmt = $conn->prepare('SELECT id, USERNAME, PASWORD FROM user WHERE USERNAME=? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        if (password_verify($password, (string)$row['PASWORD']) || (string)$password === (string)$row['PASWORD']) {
            return ['id' => (int)($row['id'] ?? 0), 'username' => (string)($row['USERNAME'] ?? '')];
        }
    }
    return null;
}

function parse_idpel_list($raw)
{
    $parts = preg_split('/[,;\r\n]+/', (string)$raw);
    $ids = [];
    if (!is_array($parts)) {
        return $ids;
    }
    foreach ($parts as $item) {
        $item = trim((string)$item);
        if ($item === '') {
            continue;
        }
        $item = preg_replace('/[\x00-\x1F\x7F]/u', '', $item);
        if ($item !== '') {
            $ids[$item] = true;
        }
    }
    return array_keys($ids);
}

function get_allowed_owners($conn, $username, $userId)
{
    $owners = [];
    $stmt = $conn->prepare('SELECT DISTINCT PEMILIK FROM server WHERE user_id=? OR PEMILIK=?');
    if ($stmt) {
        $stmt->bind_param('is', $userId, $username);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            if (!empty($row['PEMILIK'])) {
                $owners[] = (string)$row['PEMILIK'];
            }
        }
        $stmt->close();
    }
    if (empty($owners)) {
        $owners[] = $username;
    }
    return array_values(array_unique(array_filter($owners, static fn($v) => trim((string)$v) !== '')));
}

try {
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }

    $username = trim((string)($input['username'] ?? ($_POST['username'] ?? $_GET['username'] ?? '')));
    $password = trim((string)($input['password'] ?? ($_POST['password'] ?? $_GET['password'] ?? '')));
    $idpelRaw = trim((string)($input['idpel_list'] ?? ($_POST['idpel_list'] ?? $_GET['idpel_list'] ?? '')));
    $kendala = trim((string)($input['kendala'] ?? ($_POST['kendala'] ?? $_GET['kendala'] ?? '')));
    $tipe = trim((string)($input['tipe'] ?? ($_POST['tipe'] ?? $_GET['tipe'] ?? 'DISMANTLE')));

    if ($username === '' || $password === '') {
        out_json(false, 'Autentikasi gagal');
    }
    $auth = auth_user_mobile($conn, $username, $password);
    if (!$auth) {
        out_json(false, 'Autentikasi gagal');
    }
    if ($idpelRaw === '') {
        out_json(false, 'Tidak ada pelanggan terpilih');
    }
    if ($kendala === '') {
        out_json(false, 'Kendala wajib diisi');
    }

    $idpelList = parse_idpel_list($idpelRaw);
    if (count($idpelList) === 0) {
        out_json(false, 'Format ID pelanggan tidak valid');
    }

    $allowedOwners = get_allowed_owners($conn, $auth['username'], (int)$auth['id']);
    $ownerEscaped = [];
    foreach ($allowedOwners as $owner) {
        $ownerEscaped[] = "'" . mysqli_real_escape_string($conn, $owner) . "'";
    }
    $ownerWhere = count($ownerEscaped) > 0 ? (' AND PEMILIK IN (' . implode(',', $ownerEscaped) . ')') : '';

    $idEscaped = [];
    foreach ($idpelList as $idpel) {
        $idEscaped[] = "'" . mysqli_real_escape_string($conn, $idpel) . "'";
    }

    $queryPelanggan = "SELECT IDPEL, NAMA, NOWA, ALAMAT, EMAIL, ODP, PEMILIK FROM pelanggan WHERE IDPEL IN (" . implode(',', $idEscaped) . ")" . $ownerWhere;
    $resultPelanggan = mysqli_query($conn, $queryPelanggan);
    if (!$resultPelanggan) {
        out_json(false, 'Gagal mengambil data pelanggan: ' . mysqli_error($conn), [], 500);
    }

    $pelangganById = [];
    while ($row = mysqli_fetch_assoc($resultPelanggan)) {
        $pelangganById[(string)$row['IDPEL']] = $row;
    }

    if (count($pelangganById) === 0) {
        $fallback = mysqli_query($conn, "SELECT IDPEL, NAMA, NOWA, ALAMAT, EMAIL, ODP, PEMILIK FROM pelanggan WHERE IDPEL IN (" . implode(',', $idEscaped) . ")");
        while ($fallback && ($row = mysqli_fetch_assoc($fallback))) {
            $pelangganById[(string)$row['IDPEL']] = $row;
        }
    }

    if (count($pelangganById) === 0) {
        out_json(false, 'Tidak ada pelanggan valid untuk dibuat tiket');
    }

    $today = date('Y-m-d');
    $summary = [
        'total_selected' => count($idpelList),
        'created' => 0,
        'skipped_exists' => 0,
        'skipped_not_found' => 0,
        'failed' => 0,
    ];
    $details = [];

    foreach ($idpelList as $idpel) {
        if (!isset($pelangganById[$idpel])) {
            $summary['skipped_not_found']++;
            $details[] = ['idpel' => $idpel, 'status' => 'not_found'];
            continue;
        }

        $p = $pelangganById[$idpel];
        $nama = (string)($p['NAMA'] ?? '');
        $odp = (string)($p['ODP'] ?? '');
        $email = (string)($p['EMAIL'] ?? '');
        $alamat = (string)($p['ALAMAT'] ?? '');
        $nowa = (string)($p['NOWA'] ?? '');
        $project = (string)($p['PEMILIK'] ?? '');

        $idpelEsc = mysqli_real_escape_string($conn, $idpel);
        $queryCek = "SELECT id FROM joblist WHERE (data LIKE 'ID PELANGGAN :$idpelEsc\n%' AND status IN ('BARU','PENDING')) LIMIT 1";
        $resultCek = mysqli_query($conn, $queryCek);
        if ($resultCek && mysqli_num_rows($resultCek) > 0) {
            $summary['skipped_exists']++;
            $details[] = ['idpel' => $idpel, 'status' => 'exists'];
            continue;
        }

        $dataTiket = "ID PELANGGAN :$idpel\n NAMA :$nama\n ODP :$odp\n EMAIL :$email\n ALAMAT :$alamat\n NO WA :$nowa\n KENDALA :$kendala";
        $tglEsc = mysqli_real_escape_string($conn, $today);
        $tipeEsc = mysqli_real_escape_string($conn, $tipe !== '' ? $tipe : 'DISMANTLE');
        $dataEsc = mysqli_real_escape_string($conn, $dataTiket);
        $projectEsc = mysqli_real_escape_string($conn, $project);

        $queryInsert = "INSERT INTO joblist (tgl, status, nowa, data, project, report, team, tipe) VALUES ('$tglEsc','BARU','','$dataEsc','$projectEsc','','','$tipeEsc')";
        $resultInsert = mysqli_query($conn, $queryInsert);

        if ($resultInsert) {
            $summary['created']++;
            $details[] = ['idpel' => $idpel, 'status' => 'created'];
        } else {
            $summary['failed']++;
            $details[] = ['idpel' => $idpel, 'status' => 'failed', 'message' => mysqli_error($conn)];
        }
    }

    out_json(true, 'Proses pembuatan tiket massal selesai.', [
        'summary' => $summary,
        'details' => $details,
    ]);
} catch (Throwable $e) {
    out_json(false, $e->getMessage(), [], 500);
}
