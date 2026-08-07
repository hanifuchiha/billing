<?php
// api/broadcast_info.php
// Sebelumnya cuma dukung session ATAU username+password -- tidak pernah baca
// param `key`/`api_key`, PADAHAL settingsapi.php mendokumentasikan resmi
// akses via API key. Diganti ke _bootstrap.php::api_authenticate() (session ->
// username+password -> API key dari tabel `apikey`) sama seperti api/odp.php dkk.
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

switch ($method) {
    case 'GET':
        // Ambil semua broadcast info milik pemilik
        $search = trim($_GET['search'] ?? '');
        $where = "PEMILIK = '" . mysqli_real_escape_string($conn, $pemilik) . "'";
        if ($search !== '') {
            $searchEsc = mysqli_real_escape_string($conn, $search);
            $where .= " AND (JUDUL LIKE '%$searchEsc%' OR ISI LIKE '%$searchEsc%' OR TANGGAL LIKE '%$searchEsc%')";
        }
        $result = mysqli_query($conn, "SELECT * FROM broadcast_info WHERE $where ORDER BY TANGGAL DESC");
        $data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $data[] = $row;
        }
        echo json_encode(['success' => true, 'data' => $data]);
        break;
    case 'POST':
        // Kirim broadcast info baru
        $data = $input;
        $judul = $data['judul'] ?? '';
        $isi = $data['isi'] ?? '';
        $tanggal = $data['tanggal'] ?? date('Y-m-d H:i:s');
        if (!$judul || !$isi) {
            echo json_encode(['success' => false, 'error' => 'Data tidak lengkap']);
            exit;
        }
        $stmt = $conn->prepare("INSERT INTO broadcast_info (JUDUL, ISI, TANGGAL, PEMILIK) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('ssss', $judul, $isi, $tanggal, $pemilik);
        $ok = $stmt->execute();
        echo json_encode(['success' => $ok]);
        break;
    case 'PUT':
        // Update broadcast info
        $data = $input;
        $id = $data['id'] ?? '';
        $judul = $data['judul'] ?? '';
        $isi = $data['isi'] ?? '';
        if (!$id || !$judul || !$isi) {
            echo json_encode(['success' => false, 'error' => 'Data tidak lengkap']);
            exit;
        }
        $stmt = $conn->prepare("UPDATE broadcast_info SET JUDUL=?, ISI=? WHERE ID=? AND PEMILIK=?");
        $stmt->bind_param('ssis', $judul, $isi, $id, $pemilik);
        $ok = $stmt->execute();
        echo json_encode(['success' => $ok]);
        break;
    case 'DELETE':
        // Hapus broadcast info
        $data = $input;
        $id = $data['id'] ?? '';
        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'ID tidak ditemukan']);
            exit;
        }
        $stmt = $conn->prepare("DELETE FROM broadcast_info WHERE ID=? AND PEMILIK=?");
        $stmt->bind_param('is', $id, $pemilik);
        $ok = $stmt->execute();
        echo json_encode(['success' => $ok]);
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Method tidak didukung']);
}
