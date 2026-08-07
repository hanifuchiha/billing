<?php
// API: Ambil daftar bot WhatsApp milik user dari tabel botwa
header('Content-Type: application/json');
require_once '../koneksidb.php';
global $conn;

$username = trim($_GET['username'] ?? $_POST['username'] ?? '');
$password = $_GET['password'] ?? $_POST['password'] ?? '';

if ($username === '' || $password === '') {
    echo json_encode(['success' => false, 'error' => 'username dan password wajib']);
    exit;
}

// Autentikasi user
$stmt = $conn->prepare("SELECT USERNAME, PASWORD, STATUS FROM user WHERE USERNAME = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'User tidak ditemukan']);
    exit;
}
$row = $result->fetch_assoc();
if (!password_verify($password, $row['PASWORD']) && (string)$password !== (string)$row['PASWORD']) {
    echo json_encode(['success' => false, 'error' => 'Password salah']);
    exit;
}

$akses = strtoupper(trim((string)($row['STATUS'] ?? '')));
$ceknama = $row['USERNAME'];

// Ambil bot berdasarkan akses
if ($akses === 'ADMIN') {
    $sqlBots = "SELECT id, namebot, password, addressbot, webhook, sender, pemilik, technical_menu_enabled, allow_read_server, allow_read_customer, allow_create_payment_code FROM botwa ORDER BY id DESC";
    $stmtBots = $conn->prepare($sqlBots);
    $stmtBots->execute();
} else {
    $sqlBots = "SELECT id, namebot, password, addressbot, webhook, sender, pemilik, technical_menu_enabled, allow_read_server, allow_read_customer, allow_create_payment_code FROM botwa WHERE pemilik = ? ORDER BY id DESC";
    $stmtBots = $conn->prepare($sqlBots);
    $stmtBots->bind_param('s', $ceknama);
    $stmtBots->execute();
}

$bots = [];
$resultBots = $stmtBots->get_result();
while ($bot = $resultBots->fetch_assoc()) {
    // Ekstrak port dari addressbot untuk membangun whatsapp_port
    $parsed = parse_url((string)($bot['addressbot'] ?? ''));
    $port = isset($parsed['port']) ? (int)$parsed['port'] : 0;
    $whatsappPort = $port > 0 ? "whatsapp_{$port}" : '';

    $bots[] = [
        'id'           => $bot['id'],
        'namebot'      => $bot['namebot'],
        'password'     => $bot['password'],
        'addressbot'   => $bot['addressbot'],
        'webhook'      => $bot['webhook'],
        'sender'       => $bot['sender'] ?? '',
        'pemilik'      => $bot['pemilik'] ?? '',
        'technical_menu_enabled' => isset($bot['technical_menu_enabled']) ? (int)$bot['technical_menu_enabled'] : 1,
        'allow_read_server' => isset($bot['allow_read_server']) ? (int)$bot['allow_read_server'] : 1,
        'allow_read_customer' => isset($bot['allow_read_customer']) ? (int)$bot['allow_read_customer'] : 1,
        'allow_create_payment_code' => isset($bot['allow_create_payment_code']) ? (int)$bot['allow_create_payment_code'] : 1,
        'whatsapp_port' => $whatsappPort,
    ];
}

echo json_encode(['success' => true, 'data' => $bots]);
