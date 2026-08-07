<?php
// api/profile_account.php
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
        // Ambil profil account user yang login
        $stmt = $conn->prepare("SELECT * FROM user WHERE USERNAME = ?");
        $stmt->bind_param('s', $pemilik);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        echo json_encode(['success' => true, 'data' => $data]);
        break;
    case 'PUT':
        // Update profil account user yang login
        $data = $input;
        $nama = $data['nama'] ?? '';
        $email = $data['email'] ?? '';
        $nowa = $data['nowa'] ?? '';
        if (!$nama || !$email) {
            echo json_encode(['success' => false, 'error' => 'Data tidak lengkap']);
            exit;
        }
        $stmt = $conn->prepare("UPDATE user SET NAMA=?, EMAIL=?, NOWA=? WHERE USERNAME=?");
        $stmt->bind_param('ssss', $nama, $email, $nowa, $pemilik);
        $ok = $stmt->execute();
        echo json_encode(['success' => $ok]);
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Method tidak didukung']);
}
