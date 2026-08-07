<?php
// api/log.php - Log History API (read-only)
// Mirrors crm/billing/log_history.php's actual data source: a per-owner JSON history file at
// notifbot/data/history-{owner_username}.json (NOT a `log_history` SQL table - that table isn't
// what the web page reads from). Previously this endpoint had zero authentication and read the
// wrong table, leaking every tenant's rows to any caller; this version scopes strictly to the
// authenticated owner, same as every other module in this folder.
require_once '../koneksibilling.php';
require_once '_bootstrap.php';
session_start();
api_cors();

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET') {
    api_json(['success' => false, 'error' => 'Method tidak didukung, log billing hanya mendukung GET'], 405);
}

$input = api_read_input();
$auth = api_authenticate($conn, $input);
if ($auth['method'] === 'apikey') {
    api_rate_limit($conn, $auth['api_key']);
}
$ctx = api_resolve_owner($conn, $auth['pemilik']);
if (!$ctx) {
    api_json(['success' => false, 'error' => 'User tidak ditemukan'], 401);
}
api_require_module_enabled($conn, $auth['pemilik'], 'log');

// History files are keyed by the OWNER's own username (same $ceknama convention used by
// cek-sesi.php and log_history.php) - for an ASSISTANT caller this is the owner they belong to,
// not their own username.
$ownerUsername = $auth['pemilik'];
if ($ctx['is_assistant']) {
    $stmt = $conn->prepare('SELECT USERNAME FROM user WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $ctx['owner_user_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row && !empty($row['USERNAME'])) {
        $ownerUsername = (string)$row['USERNAME'];
    }
}

$safeOwner = preg_replace('/[^A-Za-z0-9_.\-]/', '', $ownerUsername);
$historyFile = __DIR__ . '/../notifbot/data/history-' . $safeOwner . '.json';

$entries = [];
if ($safeOwner !== '' && is_file($historyFile)) {
    $raw = json_decode((string)file_get_contents($historyFile), true);
    if (is_array($raw)) {
        $raw = array_reverse($raw); // newest first, same as log_history.php
        foreach ($raw as $line) {
            $line = (string)$line;
            $log_username = $ownerUsername;
            $log_timestamp = '';
            $log_message = $line;
            // Parse "[ system - timestamp ] message", same regex as log_history.php/log_history_data.php.
            if (preg_match('/^\[(.+)\] (.+)$/s', $line, $m)) {
                $header = trim($m[1]);
                $message = $m[2];
                if (strpos($header, ' - ') !== false) {
                    list($log_username, $log_timestamp) = explode(' - ', $header, 2);
                    $log_username = trim($log_username);
                    $log_timestamp = trim($log_timestamp);
                    $log_message = $message;
                } else {
                    $log_message = $line;
                }
            }
            $entries[] = [
                'username'  => $log_username,
                'timestamp' => $log_timestamp,
                'message'   => $log_message,
            ];
        }
    }
}

// Optional filters, mirroring the web page's client-side Sistem/Waktu/Log filter inputs.
$qUsername  = strtolower(trim((string)($_GET['username_filter'] ?? '')));
$qTimestamp = strtolower(trim((string)($_GET['timestamp_filter'] ?? '')));
$qMessage   = strtolower(trim((string)($_GET['q'] ?? '')));
if ($qUsername !== '' || $qTimestamp !== '' || $qMessage !== '') {
    $entries = array_values(array_filter($entries, function ($e) use ($qUsername, $qTimestamp, $qMessage) {
        if ($qUsername !== '' && strpos(strtolower($e['username']), $qUsername) === false) {
            return false;
        }
        if ($qTimestamp !== '' && strpos(strtolower($e['timestamp']), $qTimestamp) === false) {
            return false;
        }
        if ($qMessage !== '' && strpos(strtolower($e['message']), $qMessage) === false) {
            return false;
        }
        return true;
    }));
}

$total  = count($entries);
$limit  = isset($_GET['limit']) ? max(1, min(1000, (int)$_GET['limit'])) : 200;
$offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
$page   = array_slice($entries, $offset, $limit);

api_json(['success' => true, 'data' => $page, 'total' => $total, 'limit' => $limit, 'offset' => $offset]);
