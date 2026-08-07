<?php
// api/server.php
// Sebelumnya cuma dukung session ATAU username+password -- tidak pernah baca
// param `key`/`api_key`, PADAHAL settingsapi.php mendokumentasikan resmi
// "server.php?key=<API_KEY>" sebagai contoh pemakaian. Diganti ke
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
        // Ambil user_id dari tabel user berdasarkan username login
        $stmt = $conn->prepare("SELECT id FROM user WHERE USERNAME = ?");
        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => 'Prepare id user gagal: ' . $conn->error]);
            exit;
        }
        $stmt->bind_param("s", $pemilik);
        if (!$stmt->execute()) {
            echo json_encode(['success' => false, 'error' => 'Eksekusi id user gagal: ' . $stmt->error]);
            exit;
        }
        $result_user = $stmt->get_result();
        $user_id = null;
        if ($result_user && $result_user->num_rows > 0) {
            $row_user = $result_user->fetch_assoc();
            $user_id = $row_user['id'];
        }
        if (!$user_id) {
            echo json_encode(['success' => false, 'error' => 'User ID tidak ditemukan']);
            exit;
        }
        // Ambil semua server berdasarkan user_id (pastikan tipe integer)
        $stmt2 = $conn->prepare("SELECT * FROM server WHERE user_id = ?");
        if (!$stmt2) {
            echo json_encode(['success' => false, 'error' => 'Prepare server gagal: ' . $conn->error]);
            exit;
        }
        $stmt2->bind_param("i", $user_id);
        if (!$stmt2->execute()) {
            echo json_encode(['success' => false, 'error' => 'Eksekusi server gagal: ' . $stmt2->error]);
            exit;
        }
        $result = $stmt2->get_result();
        $servers = [];
        while ($row = $result->fetch_assoc()) {
            // Tambahkan status ping (online/offline) dari IP
            $ip = $row['IP'];
            $online = false;
            if ($ip) {
                // Ambil hanya bagian IP jika formatnya IP:PORT
                $ip_only = explode(':', $ip)[0];
                $cmd = "ping -c 1 -W 1 " . escapeshellarg($ip_only);
                exec($cmd, $output, $status);
                $online = ($status === 0);
            }
            $row['online'] = $online;
            $servers[] = $row;
        }
        echo json_encode(['success' => true, 'data' => $servers]);
        break;
    case 'POST':
        // Tambah server (semua kolom utama)
        $data = $input;
        $ip = $data['ip'] ?? '';
        $password = $data['password'] ?? '';
        $area = $data['area'] ?? '';
        $mik80 = $data['mik80'] ?? '';
        $brand = $data['brand'] ?? '';
        $user_id = $data['user_id'] ?? '';
        // Pemilik diambil dari autentikasi
        if (!$ip || !$password || !$area || !$mik80 || !$brand || !$user_id) {
            echo json_encode(['success' => false, 'error' => 'Data tidak lengkap']);
            exit;
        }
        $stmt = $conn->prepare("INSERT INTO server (IP, PASSWORD, AREA, MIK80, PEMILIK, BRAND, user_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('sssssss', $ip, $password, $area, $mik80, $pemilik, $brand, $user_id);
        $ok = $stmt->execute();
        echo json_encode(['success' => $ok]);
        break;
    case 'PUT':
        // Update server (semua kolom utama)
        $data = $input;
        $id = $data['id'] ?? '';
        $ip = $data['ip'] ?? '';
        $password = $data['password'] ?? '';
        $area = $data['area'] ?? '';
        $mik80 = $data['mik80'] ?? '';
        $brand = $data['brand'] ?? '';
        $user_id = $data['user_id'] ?? '';
        if (!$id || !$ip || !$password || !$area || !$mik80 || !$brand || !$user_id) {
            echo json_encode(['success' => false, 'error' => 'Data tidak lengkap']);
            exit;
        }
        // Update hanya jika milik pemilik
        $stmt = $conn->prepare("UPDATE server SET IP=?, PASSWORD=?, AREA=?, MIK80=?, BRAND=?, user_id=? WHERE ID=? AND PEMILIK=?");
        $stmt->bind_param('ssssssis', $ip, $password, $area, $mik80, $brand, $user_id, $id, $pemilik);
        $ok = $stmt->execute();
        echo json_encode(['success' => $ok]);
        break;
    case 'DELETE':
        // Hapus server
        $data = $input;
        $id = $data['id'] ?? '';
        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'ID tidak ditemukan']);
            exit;
        }
        // Hapus hanya jika milik pemilik
        $stmt = $conn->prepare("DELETE FROM server WHERE ID=? AND PEMILIK=?");
        $stmt->bind_param('is', $id, $pemilik);
        $ok = $stmt->execute();
        echo json_encode(['success' => $ok]);
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Method tidak didukung']);
}
