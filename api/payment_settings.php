<?php
// api/payment_settings.php
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
        // Ambil semua payment settings milik pemilik
        $result = mysqli_query($conn, "SELECT * FROM payment_settings WHERE PEMILIK = '" . mysqli_real_escape_string($conn, $pemilik) . "'");
        $data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $data[] = $row;
        }
        echo json_encode(['success' => true, 'data' => $data]);
        break;
    case 'POST':
        // Tambah payment setting
        $data = $input;
        $metode = $data['metode'] ?? '';
        $rekening = $data['rekening'] ?? '';
        $atas_nama = $data['atas_nama'] ?? '';
        if (!$metode || !$rekening || !$atas_nama) {
            echo json_encode(['success' => false, 'error' => 'Data tidak lengkap']);
            exit;
        }
        $stmt = $conn->prepare("INSERT INTO payment_settings (METODE, REKENING, ATAS_NAMA, PEMILIK) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('ssss', $metode, $rekening, $atas_nama, $pemilik);
        $ok = $stmt->execute();
        echo json_encode(['success' => $ok]);
        break;
    case 'PUT':
        // Update payment setting
        $data = $input;
        $id = $data['id'] ?? '';
        $metode = $data['metode'] ?? '';
        $rekening = $data['rekening'] ?? '';
        $atas_nama = $data['atas_nama'] ?? '';
        if (!$id || !$metode || !$rekening || !$atas_nama) {
            echo json_encode(['success' => false, 'error' => 'Data tidak lengkap']);
            exit;
        }
        $stmt = $conn->prepare("UPDATE payment_settings SET METODE=?, REKENING=?, ATAS_NAMA=? WHERE ID=? AND PEMILIK=?");
        $stmt->bind_param('sssis', $metode, $rekening, $atas_nama, $id, $pemilik);
        $ok = $stmt->execute();
        echo json_encode(['success' => $ok]);
        break;
    case 'DELETE':
        // Hapus payment setting
        $data = $input;
        $id = $data['id'] ?? '';
        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'ID tidak ditemukan']);
            exit;
        }
        $stmt = $conn->prepare("DELETE FROM payment_settings WHERE ID=? AND PEMILIK=?");
        $stmt->bind_param('is', $id, $pemilik);
        $ok = $stmt->execute();
        echo json_encode(['success' => $ok]);
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Method tidak didukung']);
}
