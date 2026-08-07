<?php
// api/mitra_account.php
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
        // Ambil semua mitra account milik pemilik
        $pem = mysqli_real_escape_string($conn, $pemilik);
        $q = trim((string)($_GET['q'] ?? ''));
        $where = "PEMILIK = '$pem'";
        if ($q !== '') {
            $qe = mysqli_real_escape_string($conn, $q);
            $where .= " AND (NAMA LIKE '%$qe%' OR NOWA LIKE '%$qe%' OR ALAMAT LIKE '%$qe%' OR EMAIL LIKE '%$qe%' OR jabatan LIKE '%$qe%' OR kabupaten LIKE '%$qe%' OR kecamatan LIKE '%$qe%' OR kelurahan LIKE '%$qe%')";
        }

        $result = mysqli_query($conn, "SELECT * FROM mitra WHERE $where ORDER BY ID DESC LIMIT 300");
        $data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $data[] = $row;
        }
        echo json_encode(['success' => true, 'data' => $data]);
        break;
    case 'POST':
        // Tambah mitra account
        $data = $input;
        $action = strtolower(trim((string)($data['action'] ?? '')));
        if ($action === 'topup') {
            $id = (int)($data['id'] ?? 0);
            $jumlah = (float)($data['jumlah'] ?? 0);
            if ($id <= 0 || $jumlah <= 0) {
                echo json_encode(['success' => false, 'error' => 'ID/Jumlah topup tidak valid']);
                exit;
            }
            $stmt = $conn->prepare("UPDATE mitra SET saldo = COALESCE(saldo,0) + ? WHERE ID=? AND PEMILIK=?");
            $stmt->bind_param('dis', $jumlah, $id, $pemilik);
            $ok = $stmt->execute();
            echo json_encode(['success' => $ok]);
            exit;
        }
        $nama = $data['nama'] ?? '';
        $nowa = $data['nowa'] ?? '';
        $alamat = $data['alamat'] ?? '';
        $email = $data['email'] ?? '';
        $jabatan = $data['jabatan'] ?? '';
        $kabupaten = $data['kabupaten'] ?? '';
        $kecamatan = $data['kecamatan'] ?? '';
        $kelurahan = $data['kelurahan'] ?? '';
        $rw = $data['rw'] ?? '';
        $rt = $data['rt'] ?? '';
        if (!$nama || !$nowa) {
            echo json_encode(['success' => false, 'error' => 'Data tidak lengkap']);
            exit;
        }
        $stmt = $conn->prepare("INSERT INTO mitra (NAMA, NOWA, ALAMAT, EMAIL, jabatan, kabupaten, kecamatan, kelurahan, rw, rt, PEMILIK) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('sssssssssss', $nama, $nowa, $alamat, $email, $jabatan, $kabupaten, $kecamatan, $kelurahan, $rw, $rt, $pemilik);
        $ok = $stmt->execute();
        echo json_encode(['success' => $ok]);
        break;
    case 'PUT':
        // Update mitra account
        $data = $input;
        $id = $data['id'] ?? '';
        $nama = $data['nama'] ?? '';
        $nowa = $data['nowa'] ?? '';
        $alamat = $data['alamat'] ?? '';
        $email = $data['email'] ?? '';
        $jabatan = $data['jabatan'] ?? '';
        $kabupaten = $data['kabupaten'] ?? '';
        $kecamatan = $data['kecamatan'] ?? '';
        $kelurahan = $data['kelurahan'] ?? '';
        $rw = $data['rw'] ?? '';
        $rt = $data['rt'] ?? '';
        if (!$id || !$nama || !$nowa) {
            echo json_encode(['success' => false, 'error' => 'Data tidak lengkap']);
            exit;
        }
        $stmt = $conn->prepare("UPDATE mitra SET NAMA=?, NOWA=?, ALAMAT=?, EMAIL=?, jabatan=?, kabupaten=?, kecamatan=?, kelurahan=?, rw=?, rt=? WHERE ID=? AND PEMILIK=?");
        $stmt->bind_param('ssssssssssis', $nama, $nowa, $alamat, $email, $jabatan, $kabupaten, $kecamatan, $kelurahan, $rw, $rt, $id, $pemilik);
        $ok = $stmt->execute();
        echo json_encode(['success' => $ok]);
        break;
    case 'DELETE':
        // Hapus mitra account
        $data = $input;
        $id = $data['id'] ?? '';
        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'ID tidak ditemukan']);
            exit;
        }
        $stmt = $conn->prepare("DELETE FROM mitra WHERE ID=? AND PEMILIK=?");
        $stmt->bind_param('is', $id, $pemilik);
        $ok = $stmt->execute();
        echo json_encode(['success' => $ok]);
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Method tidak didukung']);
}
