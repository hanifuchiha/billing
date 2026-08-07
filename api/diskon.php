<?php
// api/diskon.php
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
        // Ambil semua diskon milik pemilik
        $result = mysqli_query($conn, "SELECT * FROM diskon WHERE PEMILIK = '" . mysqli_real_escape_string($conn, $pemilik) . "'");
        $data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $data[] = $row;
        }
        echo json_encode(['success' => true, 'data' => $data]);
        break;
    case 'POST':
        // Tambah diskon
        $data = $input;
        $nama = $data['nama'] ?? '';
        $persen = $data['persen'] ?? '';
        $keterangan = $data['keterangan'] ?? '';
        if (!$nama || $persen === '') {
            echo json_encode(['success' => false, 'error' => 'Data tidak lengkap']);
            exit;
        }
        $stmt = $conn->prepare("INSERT INTO diskon (NAMA, PERSEN, KETERANGAN, PEMILIK) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('sdss', $nama, $persen, $keterangan, $pemilik);
        $ok = $stmt->execute();
        echo json_encode(['success' => $ok]);
        break;
    case 'PUT':
        // Update diskon
        $data = $input;
        $id = $data['id'] ?? '';
        $nama = $data['nama'] ?? '';
        $persen = $data['persen'] ?? '';
        $keterangan = $data['keterangan'] ?? '';
        if (!$id || !$nama || $persen === '') {
            echo json_encode(['success' => false, 'error' => 'Data tidak lengkap']);
            exit;
        }
        $stmt = $conn->prepare("UPDATE diskon SET NAMA=?, PERSEN=?, KETERANGAN=? WHERE ID=? AND PEMILIK=?");
        $stmt->bind_param('sdssi', $nama, $persen, $keterangan, $id, $pemilik);
        $ok = $stmt->execute();
        echo json_encode(['success' => $ok]);
        break;
    case 'DELETE':
        // Hapus diskon
        $data = $input;
        $id = $data['id'] ?? '';
        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'ID tidak ditemukan']);
            exit;
        }
        $stmt = $conn->prepare("DELETE FROM diskon WHERE ID=? AND PEMILIK=?");
        $stmt->bind_param('is', $id, $pemilik);
        $ok = $stmt->execute();
        echo json_encode(['success' => $ok]);
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Method tidak didukung']);
}
