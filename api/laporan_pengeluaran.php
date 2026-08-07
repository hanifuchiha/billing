<?php
// api/laporan_pengeluaran.php
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
        // Ambil laporan pengeluaran milik pemilik dengan filter opsional
        $pem = mysqli_real_escape_string($conn, $pemilik);
        $bulan = isset($_GET['bulan']) ? (int)$_GET['bulan'] : 0;
        $tahun = isset($_GET['tahun']) ? (int)$_GET['tahun'] : 0;
        $kategori = trim((string)($_GET['kategori'] ?? ''));
        $q = trim((string)($_GET['q'] ?? ''));

        $where = ["PEMILIK = '$pem'"];
        if ($bulan >= 1 && $bulan <= 12) {
            $where[] = "MONTH(TANGGAL) = $bulan";
        }
        if ($tahun >= 2000 && $tahun <= 2100) {
            $where[] = "YEAR(TANGGAL) = $tahun";
        }
        if ($kategori !== '') {
            $kat = mysqli_real_escape_string($conn, $kategori);
            $where[] = "KATEGORI LIKE '%$kat%'";
        }
        if ($q !== '') {
            $qe = mysqli_real_escape_string($conn, $q);
            $where[] = "(NAMA LIKE '%$qe%' OR KETERANGAN LIKE '%$qe%' OR KATEGORI LIKE '%$qe%' OR JUMLAH LIKE '%$qe%' OR TANGGAL LIKE '%$qe%')";
        }
        $sql = "SELECT * FROM pengeluaran WHERE " . implode(' AND ', $where) . " ORDER BY TANGGAL DESC, ID DESC LIMIT 500";
        $result = mysqli_query($conn, $sql);
        $data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $data[] = $row;
        }
        echo json_encode(['success' => true, 'data' => $data]);
        break;
    case 'POST':
        // Tambah pengeluaran
        $data = $input;
        $nama = $data['nama'] ?? '';
        $jumlah = $data['jumlah'] ?? '';
        $tanggal = $data['tanggal'] ?? date('Y-m-d');
        $keterangan = $data['keterangan'] ?? '';
        $kategori = $data['kategori'] ?? '';
        if (!$nama || $jumlah === '') {
            echo json_encode(['success' => false, 'error' => 'Data tidak lengkap']);
            exit;
        }
        $stmt = $conn->prepare("INSERT INTO pengeluaran (NAMA, JUMLAH, TANGGAL, KETERANGAN, KATEGORI, PEMILIK) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('sdssss', $nama, $jumlah, $tanggal, $keterangan, $kategori, $pemilik);
        $ok = $stmt->execute();
        echo json_encode(['success' => $ok]);
        break;
    case 'PUT':
        // Update pengeluaran
        $data = $input;
        $id = $data['id'] ?? '';
        $nama = $data['nama'] ?? '';
        $jumlah = $data['jumlah'] ?? '';
        $tanggal = $data['tanggal'] ?? date('Y-m-d');
        $keterangan = $data['keterangan'] ?? '';
        $kategori = $data['kategori'] ?? '';
        if (!$id || !$nama || $jumlah === '') {
            echo json_encode(['success' => false, 'error' => 'Data tidak lengkap']);
            exit;
        }
        $stmt = $conn->prepare("UPDATE pengeluaran SET NAMA=?, JUMLAH=?, TANGGAL=?, KETERANGAN=?, KATEGORI=? WHERE ID=? AND PEMILIK=?");
        $stmt->bind_param('sdsssis', $nama, $jumlah, $tanggal, $keterangan, $kategori, $id, $pemilik);
        $ok = $stmt->execute();
        echo json_encode(['success' => $ok]);
        break;
    case 'DELETE':
        // Hapus pengeluaran
        $data = $input;
        $id = $data['id'] ?? '';
        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'ID tidak ditemukan']);
            exit;
        }
        $stmt = $conn->prepare("DELETE FROM pengeluaran WHERE ID=? AND PEMILIK=?");
        $stmt->bind_param('is', $id, $pemilik);
        $ok = $stmt->execute();
        echo json_encode(['success' => $ok]);
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Method tidak didukung']);
}
