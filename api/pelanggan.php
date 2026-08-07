<?php
// api/pelanggan.php
// Sebelumnya cuma dukung session ATAU username+password -- tidak pernah baca
// param `key`/`api_key`, PADAHAL settingsapi.php mendokumentasikan resmi
// "pelanggan.php?key=<API_KEY>" sebagai contoh pemakaian. Diganti ke
// _bootstrap.php::api_authenticate() (session -> username+password -> API key
// dari tabel `apikey`) sama seperti api/odp.php dkk.
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
        // Ambil semua pelanggan milik pemilik
        $result = mysqli_query($conn, "SELECT * FROM pelanggan WHERE SERVER = '" . mysqli_real_escape_string($conn, $pemilik) . "'");
        $pelanggan = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $pelanggan[] = $row;
        }
        echo json_encode(['success' => true, 'data' => $pelanggan]);
        break;
    case 'POST':
        // Tambah pelanggan
        $data = $input;
        $idpel = $data['idpel'] ?? '';
        $nama = $data['nama'] ?? '';
        $alamat = $data['alamat'] ?? '';
        $paket = $data['paket'] ?? '';
        $server = $pemilik; // paksa server = pemilik
        $odp = $data['odp'] ?? '';
        if (!$idpel || !$nama || !$alamat || !$paket || !$server || !$odp) {
            echo json_encode(['success' => false, 'error' => 'Data tidak lengkap']);
            exit;
        }
        $stmt = $conn->prepare("INSERT INTO pelanggan (IDPEL, NAMA, ALAMAT, PAKET, SERVER, ODP) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('ssssss', $idpel, $nama, $alamat, $paket, $server, $odp);
        $ok = $stmt->execute();
        echo json_encode(['success' => $ok]);
        break;
    case 'PUT':
        // Update pelanggan
        $data = $input;
        $idpel = $data['idpel'] ?? '';
        $nama = $data['nama'] ?? '';
        $alamat = $data['alamat'] ?? '';
        $paket = $data['paket'] ?? '';
        $server = $pemilik; // paksa server = pemilik
        $odp = $data['odp'] ?? '';
        if (!$idpel || !$nama || !$alamat || !$paket || !$server || !$odp) {
            echo json_encode(['success' => false, 'error' => 'Data tidak lengkap']);
            exit;
        }
        // Update hanya jika milik pemilik
        $stmt = $conn->prepare("UPDATE pelanggan SET NAMA=?, ALAMAT=?, PAKET=?, SERVER=?, ODP=? WHERE IDPEL=? AND SERVER=?");
        $stmt->bind_param('sssssss', $nama, $alamat, $paket, $server, $odp, $idpel, $pemilik);
        $ok = $stmt->execute();
        echo json_encode(['success' => $ok]);
        break;
    case 'DELETE':
        // Hapus pelanggan
        $data = $input;
        $idpel = $data['idpel'] ?? '';
        if (!$idpel) {
            echo json_encode(['success' => false, 'error' => 'IDPEL tidak ditemukan']);
            exit;
        }
        // Hapus hanya jika milik pemilik
        $stmt = $conn->prepare("DELETE FROM pelanggan WHERE IDPEL=? AND SERVER=?");
        $stmt->bind_param('ss', $idpel, $pemilik);
        $ok = $stmt->execute();
        echo json_encode(['success' => $ok]);
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Method tidak didukung']);
}
