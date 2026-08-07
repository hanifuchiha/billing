<?php
// api/whatsapp_bot.php
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
        // Ambil status WhatsApp bot milik pemilik
        $result = mysqli_query($conn, "SELECT * FROM whatsapp_bot WHERE PEMILIK = '" . mysqli_real_escape_string($conn, $pemilik) . "'");
        $data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $data[] = $row;
        }
        echo json_encode(['success' => true, 'data' => $data]);
        break;
    case 'POST':
        // Kirim pesan WhatsApp via bot
        $data = $input;
        $nowa = $data['nowa'] ?? '';
        $pesan = $data['pesan'] ?? '';
        if (!$nowa || !$pesan) {
            echo json_encode(['success' => false, 'error' => 'Data tidak lengkap']);
            exit;
        }
        // Simpan ke log atau trigger WA API jika ada endpoint
        $stmt = $conn->prepare("INSERT INTO whatsapp_bot (NOWA, PESAN, TANGGAL, PEMILIK) VALUES (?, ?, NOW(), ?)");
        $stmt->bind_param('sss', $nowa, $pesan, $pemilik);
        $ok = $stmt->execute();
        // TODO: Integrasi dengan WA API jika ada endpoint
        echo json_encode(['success' => $ok]);
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Method tidak didukung']);
}
