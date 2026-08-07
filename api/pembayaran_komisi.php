<?php
// api/pembayaran_komisi.php
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

switch ($method) {
    case 'GET':
        // Ambil semua pembayaran komisi milik pemilik
        $pem = mysqli_real_escape_string($conn, $pemilik);
        $q = trim((string)($_GET['q'] ?? ''));
        $where = "pk.PEMILIK = '$pem'";
        if ($q !== '') {
            $qe = mysqli_real_escape_string($conn, $q);
            $where .= " AND (m.NAMA LIKE '%$qe%' OR pk.MITRA_ID LIKE '%$qe%' OR pk.JUMLAH LIKE '%$qe%' OR pk.TANGGAL LIKE '%$qe%')";
        }

        $sql = "SELECT pk.*, m.NAMA AS mitra_nama
                FROM pembayaran_komisi pk
                LEFT JOIN mitra m ON m.ID = pk.MITRA_ID AND m.PEMILIK = pk.PEMILIK
                WHERE $where
                ORDER BY pk.TANGGAL DESC, pk.ID DESC
                LIMIT 500";
        $result = mysqli_query($conn, $sql);
        $data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $data[] = $row;
        }
        echo json_encode(['success' => true, 'data' => $data]);
        break;
    case 'POST':
        // Tambah pembayaran komisi
        $data = $input;
        $mitra_id = $data['mitra_id'] ?? '';
        $jumlah = $data['jumlah'] ?? '';
        $tanggal = $data['tanggal'] ?? date('Y-m-d');
        if (!$mitra_id || !$jumlah) {
            echo json_encode(['success' => false, 'error' => 'Data tidak lengkap']);
            exit;
        }
        $stmt = $conn->prepare("INSERT INTO pembayaran_komisi (MITRA_ID, JUMLAH, TANGGAL, PEMILIK) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('sdss', $mitra_id, $jumlah, $tanggal, $pemilik);
        $ok = $stmt->execute();
        echo json_encode(['success' => $ok]);
        break;
    case 'PUT':
        // Update pembayaran komisi
        $data = $input;
        $id = $data['id'] ?? '';
        $mitra_id = $data['mitra_id'] ?? '';
        $jumlah = $data['jumlah'] ?? '';
        $tanggal = $data['tanggal'] ?? '';
        if (!$id || !$mitra_id || $jumlah === '' || !$tanggal) {
            echo json_encode(['success' => false, 'error' => 'Data tidak lengkap']);
            exit;
        }
        $stmt = $conn->prepare("UPDATE pembayaran_komisi SET MITRA_ID=?, JUMLAH=?, TANGGAL=? WHERE ID=? AND PEMILIK=?");
        $stmt->bind_param('sdsis', $mitra_id, $jumlah, $tanggal, $id, $pemilik);
        $ok = $stmt->execute();
        echo json_encode(['success' => $ok]);
        break;
    case 'DELETE':
        // Hapus pembayaran komisi
        $data = $input;
        $id = $data['id'] ?? '';
        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'ID tidak ditemukan']);
            exit;
        }
        $stmt = $conn->prepare("DELETE FROM pembayaran_komisi WHERE ID=? AND PEMILIK=?");
        $stmt->bind_param('is', $id, $pemilik);
        $ok = $stmt->execute();
        echo json_encode(['success' => $ok]);
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Method tidak didukung']);
}
