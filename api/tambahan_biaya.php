<?php
// api/tambahan_biaya.php
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

switch ($method) {
    case 'GET':
        // Ambil semua tambahan biaya milik pemilik
        $pem = mysqli_real_escape_string($conn, $pemilik);
        $q = trim((string)($_GET['q'] ?? ''));
        $where = "PEMILIK = '$pem'";
        if ($q !== '') {
            $qe = mysqli_real_escape_string($conn, $q);
            $where .= " AND (NAMA LIKE '%$qe%' OR KETERANGAN LIKE '%$qe%' OR JUMLAH LIKE '%$qe%')";
        }
        $result = mysqli_query($conn, "SELECT * FROM tambahan_biaya WHERE $where ORDER BY ID DESC LIMIT 500");
        $data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $data[] = $row;
        }
        echo json_encode(['success' => true, 'data' => $data]);
        break;
    case 'POST':
        // Tambah tambahan biaya
        $data = $input;
        $nama = $data['nama'] ?? '';
        $jumlah = $data['jumlah'] ?? '';
        $keterangan = $data['keterangan'] ?? '';
        if (!$nama || $jumlah === '') {
            echo json_encode(['success' => false, 'error' => 'Data tidak lengkap']);
            exit;
        }
        $stmt = $conn->prepare("INSERT INTO tambahan_biaya (NAMA, JUMLAH, KETERANGAN, PEMILIK) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('sdss', $nama, $jumlah, $keterangan, $pemilik);
        $ok = $stmt->execute();
        echo json_encode(['success' => $ok]);
        break;
    case 'PUT':
        // Update tambahan biaya
        $data = $input;
        $id = $data['id'] ?? '';
        $nama = $data['nama'] ?? '';
        $jumlah = $data['jumlah'] ?? '';
        $keterangan = $data['keterangan'] ?? '';
        if (!$id || !$nama || $jumlah === '') {
            echo json_encode(['success' => false, 'error' => 'Data tidak lengkap']);
            exit;
        }
        $stmt = $conn->prepare("UPDATE tambahan_biaya SET NAMA=?, JUMLAH=?, KETERANGAN=? WHERE ID=? AND PEMILIK=?");
        $stmt->bind_param('sdsis', $nama, $jumlah, $keterangan, $id, $pemilik);
        $ok = $stmt->execute();
        echo json_encode(['success' => $ok]);
        break;
    case 'DELETE':
        // Hapus tambahan biaya
        $data = $input;
        $id = $data['id'] ?? '';
        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'ID tidak ditemukan']);
            exit;
        }
        $stmt = $conn->prepare("DELETE FROM tambahan_biaya WHERE ID=? AND PEMILIK=?");
        $stmt->bind_param('is', $id, $pemilik);
        $ok = $stmt->execute();
        echo json_encode(['success' => $ok]);
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Method tidak didukung']);
}
