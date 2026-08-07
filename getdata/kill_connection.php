<?php
header('Content-Type: application/json');
require '../../../cek-sesi.php';
require '../../../routeros_api.class.php';

$connectionId = isset($_POST['connection_id']) ? trim($_POST['connection_id']) : '';
$serverIp = isset($_POST['server_ip']) ? trim($_POST['server_ip']) : '';
$serverUser = isset($_POST['server_user']) ? trim($_POST['server_user']) : '';
$serverPassword = isset($_POST['server_password']) ? trim($_POST['server_password']) : '';
$username = isset($_POST['username']) ? trim($_POST['username']) : '';

// Validate input
if (empty($connectionId) || empty($serverIp) || empty($serverUser) || empty($serverPassword)) {
    echo json_encode([
        'success' => false,
        'message' => 'Data koneksi tidak lengkap'
    ]);
    exit;
}

try {
    $routeros_api = new RouterosAPI();
    $routeros_api->debug = false;

    if (!$routeros_api->connect($serverIp, $serverUser, $serverPassword)) {
        throw new Exception('Gagal terhubung ke MikroTik');
    }

    // Remove the active connection
    $routeros_api->comm('/ppp/active/remove', array(
        '.id' => $connectionId
    ));

    // Log the action
    $historyFile = '../../../notifbot/data/history-' . str_replace(' ', '_', $username) . '.json';
    if (file_exists(dirname($historyFile))) {
        $history = [];
        if (file_exists($historyFile)) {
            $content = file_get_contents($historyFile);
            $history = json_decode($content, true) ?: [];
        }

        $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : ($ceknama ?? 'Unknown')) . " - " . date('Y-m-d H:i:s') . " ] Memutus koneksi aktif pelanggan $username (ID koneksi $connectionId) di server $serverIp";

        file_put_contents($historyFile, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    $routeros_api->disconnect();

    echo json_encode([
        'success' => true,
        'message' => 'Koneksi ' . htmlspecialchars($username) . ' berhasil diputus',
        'username' => $username,
        'server' => $serverIp
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Gagal matikan koneksi: ' . $e->getMessage()
    ]);
}
?>
