<?php
header('Content-Type: application/json');
require_once '../koneksibilling.php';
session_start();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = [];
if (in_array($method, ['POST', 'PUT'], true)) {
    $raw = (string)file_get_contents('php://input');
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
}

function auth_log_user($conn, $username, $password) {
    $stmt = $conn->prepare('SELECT USERNAME, PASWORD FROM user WHERE USERNAME=? LIMIT 1');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        if (password_verify($password, (string)$row['PASWORD']) || (string)$password === (string)$row['PASWORD']) {
            return (string)$row['USERNAME'];
        }
    }
    return false;
}

function parse_log_entry($entry, $defaultUser) {
    $entry = (string)$entry;
    $system = $defaultUser;
    $timestamp = '';
    $message = $entry;

    if (preg_match('/^\[(.+)\]\s*(.+)$/s', $entry, $matches)) {
        $header = trim((string)$matches[1]);
        $message = (string)$matches[2];
        if (strpos($header, ' - ') !== false) {
            $parts = explode(' - ', $header, 2);
            $system = trim((string)$parts[0]);
            $timestamp = trim((string)$parts[1]);
        }
    }

    return [
        'system' => $system,
        'timestamp' => $timestamp,
        'message' => $message,
        'raw' => $entry
    ];
}

try {
    if (!$conn) {
        echo json_encode(['success' => false, 'error' => 'Koneksi DB gagal']);
        exit;
    }

    $username = (string)($input['username'] ?? ($_GET['username'] ?? ''));
    $password = (string)($input['password'] ?? ($_GET['password'] ?? ''));
    $action = strtolower(trim((string)($input['action'] ?? ($_GET['action'] ?? 'list'))));

    $pemilik = auth_log_user($conn, $username, $password);
    if (!$pemilik) {
        echo json_encode(['success' => false, 'error' => 'Autentikasi gagal']);
        exit;
    }

    $safeUser = preg_replace('/[^a-zA-Z0-9_\-]/', '', $pemilik);
    $historyFile = __DIR__ . '/../notifbot/data/history-' . $safeUser . '.json';

    if ($method === 'POST' && $action === 'clear') {
        $history = [];
        if (file_exists($historyFile)) {
            $existing = json_decode((string)file_get_contents($historyFile), true);
            if (is_array($existing)) {
                $history = $existing;
            }
        } else {
            $dir = dirname($historyFile);
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
        }

        $history[] = '[ ' . $safeUser . ' - ' . date('Y-m-d H:i:s') . ' ] Log cleared from mobile app';
        file_put_contents($historyFile, json_encode($history, JSON_PRETTY_PRINT));

        echo json_encode(['success' => true]);
        exit;
    }

    $rows = [];
    if (file_exists($historyFile)) {
        $history = json_decode((string)file_get_contents($historyFile), true);
        if (is_array($history)) {
            $history = array_reverse($history);
            foreach ($history as $entry) {
                $rows[] = parse_log_entry($entry, $safeUser);
            }
        }
    }

    echo json_encode([
        'success' => true,
        'total' => count($rows),
        'logs' => $rows
    ]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
