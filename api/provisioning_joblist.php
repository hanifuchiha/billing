<?php
// api/provisioning_joblist.php
// Diganti ke _bootstrap.php::api_authenticate() (session -> username+password ->
// API key dari tabel `apikey`) -- sebelumnya tidak pernah baca param `key`/`api_key`.
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
// --- END Autentikasi universal ---

switch ($method) {
    case 'GET':
        // Ambil joblist milik pemilik dengan filter opsional
        $pem = mysqli_real_escape_string($conn, $pemilik);
        $status = strtoupper(trim((string)($_GET['status'] ?? 'ALL')));
        $q = trim((string)($_GET['q'] ?? ''));

        $where = ["PEMILIK = '$pem'"];
        if ($status !== '' && $status !== 'ALL') {
            $s = mysqli_real_escape_string($conn, $status);
            $where[] = "UPPER(status) = '$s'";
        }
        if ($q !== '') {
            $qe = mysqli_real_escape_string($conn, $q);
            $where[] = "(data LIKE '%$qe%' OR team LIKE '%$qe%' OR project LIKE '%$qe%' OR tipe LIKE '%$qe%' OR nowa LIKE '%$qe%')";
        }

        $sql = "SELECT * FROM joblist WHERE " . implode(' AND ', $where) . " ORDER BY tgl DESC, id DESC LIMIT 300";
        $result = mysqli_query($conn, $sql);
        $data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $data[] = $row;
        }
        echo json_encode(['success' => true, 'data' => $data]);
        break;
    case 'POST':
        // Tambah joblist
        $data = $input;
        $data_job = $data['data'] ?? '';
        $status = $data['status'] ?? '';
        $team = $data['team'] ?? '';
        $project = $data['project'] ?? '';
        $tipe = $data['tipe'] ?? '';
        $waktu = $data['waktu'] ?? '';
        $created_at = $data['created_at'] ?? date('Y-m-d H:i:s');
        $tgl = $data['tgl'] ?? '';
        $nowa = $data['nowa'] ?? '';
        $report = $data['report'] ?? '';
        if (!$data_job || !$status || !$team || !$project || !$tipe || !$tgl) {
            echo json_encode(['success' => false, 'error' => 'Data tidak lengkap']);
            exit;
        }
        $stmt = $conn->prepare("INSERT INTO joblist (data, status, team, project, tipe, waktu, created_at, tgl, nowa, report, PEMILIK) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('sssssssssss', $data_job, $status, $team, $project, $tipe, $waktu, $created_at, $tgl, $nowa, $report, $pemilik);
        $ok = $stmt->execute();
        echo json_encode(['success' => $ok]);
        break;
    case 'PUT':
        // Update joblist
        $data = $input;
        $id = $data['id'] ?? '';
        $data_job = $data['data'] ?? '';
        $status = $data['status'] ?? '';
        $team = $data['team'] ?? '';
        $project = $data['project'] ?? '';
        $tipe = $data['tipe'] ?? '';
        $waktu = $data['waktu'] ?? '';
        $tgl = $data['tgl'] ?? '';
        $nowa = $data['nowa'] ?? '';
        $report = $data['report'] ?? '';
        if (!$id || !$data_job || !$status || !$team || !$project || !$tipe || !$tgl) {
            echo json_encode(['success' => false, 'error' => 'Data tidak lengkap']);
            exit;
        }
        $stmt = $conn->prepare("UPDATE joblist SET data=?, status=?, team=?, project=?, tipe=?, waktu=?, tgl=?, nowa=?, report=? WHERE id=? AND PEMILIK=?");
        $stmt->bind_param('sssssssssis', $data_job, $status, $team, $project, $tipe, $waktu, $tgl, $nowa, $report, $id, $pemilik);
        $ok = $stmt->execute();
        echo json_encode(['success' => $ok]);
        break;
    case 'DELETE':
        // Hapus joblist
        $data = $input;
        $id = $data['id'] ?? '';
        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'ID tidak ditemukan']);
            exit;
        }
        $stmt = $conn->prepare("DELETE FROM joblist WHERE id=? AND PEMILIK=?");
        $stmt->bind_param('is', $id, $pemilik);
        $ok = $stmt->execute();
        echo json_encode(['success' => $ok]);
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Method tidak didukung']);
}
