<?php
// api/log_history.php
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

$history_file = "../notifbot/data/history-$pemilik.json";

switch ($method) {
    case 'GET':
        // Ambil log history dari file JSON
        if (!file_exists($history_file)) {
            echo json_encode(['success' => true, 'data' => []]);
            exit;
        }
        $history = json_decode(file_get_contents($history_file), true);
        if (!is_array($history)) $history = [];
        echo json_encode(['success' => true, 'data' => $history]);
        break;
    case 'DELETE':
        // Hapus seluruh log history
        if (file_exists($history_file)) {
            unlink($history_file);
        }
        echo json_encode(['success' => true]);
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Method tidak didukung']);
}
