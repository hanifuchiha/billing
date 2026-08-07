<?php
// api/vpn.php
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
        // Ambil semua data VPN milik pemilik
        $result = mysqli_query($conn, "SELECT * FROM vpn WHERE PEMILIK = '" . mysqli_real_escape_string($conn, $pemilik) . "'");
        $vpn = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $vpn[] = $row;
        }
        echo json_encode(['success' => true, 'data' => $vpn]);
        break;
    case 'POST':
        // Tambah VPN
        $data = $input;
        $nama = $data['nama'] ?? '';
        $ip_local = $data['ip_local'] ?? '';
        $ip_remote = $data['ip_remote'] ?? '';
        if (!$nama || !$ip_local || !$ip_remote) {
            echo json_encode(['success' => false, 'error' => 'Data tidak lengkap']);
            exit;
        }
        $stmt = $conn->prepare("INSERT INTO vpn (NAMA, IP_LOCAL, IP_REMOTE, PEMILIK) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('ssss', $nama, $ip_local, $ip_remote, $pemilik);
        $ok = $stmt->execute();
        echo json_encode(['success' => $ok]);
        break;
    case 'PUT':
        // Update VPN
        $data = $input;
        $id = $data['id'] ?? '';
        $nama = $data['nama'] ?? '';
        $ip_local = $data['ip_local'] ?? '';
        $ip_remote = $data['ip_remote'] ?? '';
        if (!$id || !$nama || !$ip_local || !$ip_remote) {
            echo json_encode(['success' => false, 'error' => 'Data tidak lengkap']);
            exit;
        }
        $stmt = $conn->prepare("UPDATE vpn SET NAMA=?, IP_LOCAL=?, IP_REMOTE=? WHERE ID=? AND PEMILIK=?");
        $stmt->bind_param('sssis', $nama, $ip_local, $ip_remote, $id, $pemilik);
        $ok = $stmt->execute();
        echo json_encode(['success' => $ok]);
        break;
    case 'DELETE':
        // Hapus VPN
        $data = $input;
        $id = $data['id'] ?? '';
        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'ID tidak ditemukan']);
            exit;
        }
        $stmt = $conn->prepare("DELETE FROM vpn WHERE ID=? AND PEMILIK=?");
        $stmt->bind_param('is', $id, $pemilik);
        $ok = $stmt->execute();
        echo json_encode(['success' => $ok]);
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Method tidak didukung']);
}
