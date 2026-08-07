<?php
// api/wabot_settings.php - Global (NOT per-tenant) ADMIN-only WhatsApp bot settings: database
// settings, function registry, technical menu DB connection, bot container port range.
//
// Ports crm/billing/wabot.php's four ADMIN-only settings blocks with full parity. Unlike
// api/wabot.php and api/wabot_integrations.php, these settings are single, install-wide JSON
// files (not scoped per PEMILIK) - that's why this is a separate file with a different
// authorization model (is_admin only, no tenant/bot ownership dimension) rather than folded into
// the tenant-scoped files.
//
// action=database  : GET/POST crm/webhook/config.json's db_host/db_user/db_pass/db_name/db_billing
// action=function   : GET/POST crm/webhook/config.json's functions[] registry
// action=technical_menu_db : GET/POST crm/webhook/technical_menu_db.json
// action=port_range : GET/POST crm/billing/config.json's port_start_bot/port_end_bot
header('Content-Type: application/json');
require_once '../koneksibilling.php';
require_once '_bootstrap.php';
session_start();
api_cors();

$method = $_SERVER['REQUEST_METHOD'];
$input  = api_read_input();
$action = strtolower(trim((string)($input['action'] ?? ($_GET['action'] ?? ''))));

$auth = api_authenticate($conn, $input);
$pemilik = $auth['pemilik'];
if ($auth['method'] === 'apikey') {
    api_rate_limit($conn, $auth['api_key']);
}
$ctx = api_resolve_owner($conn, $pemilik);
if (!$ctx) {
    api_json(['success' => false, 'error' => 'User tidak ditemukan'], 401);
}

$roleStmt = $conn->prepare('SELECT STATUS FROM user WHERE id = ? LIMIT 1');
$roleStmt->bind_param('i', $ctx['owner_user_id']);
$roleStmt->execute();
$roleRow = $roleStmt->get_result()->fetch_assoc();
$isAdmin = $roleRow && strtoupper(trim((string)$roleRow['STATUS'])) === 'ADMIN';
if (!$isAdmin) {
    api_json(['success' => false, 'error' => 'Hanya akun ADMIN yang dapat mengakses pengaturan ini'], 403);
}

$ownerUsername = $pemilik;
if ($ctx['is_assistant']) {
    $ownerStmt = $conn->prepare('SELECT USERNAME FROM user WHERE id = ? LIMIT 1');
    $ownerStmt->bind_param('i', $ctx['owner_user_id']);
    $ownerStmt->execute();
    $ownerRow = $ownerStmt->get_result()->fetch_assoc();
    if ($ownerRow && !empty($ownerRow['USERNAME'])) {
        $ownerUsername = (string)$ownerRow['USERNAME'];
    }
}

function ws_log_history($owner, $message) {
    $safe = preg_replace('/[^A-Za-z0-9_.\-]/', '', (string)$owner);
    if ($safe === '') {
        return;
    }
    $file = __DIR__ . '/../notifbot/data/history-' . $safe . '.json';
    $history = [];
    if (is_file($file)) {
        $d = json_decode((string)file_get_contents($file), true);
        if (is_array($d)) {
            $history = $d;
        }
    }
    $history[] = '[ api - ' . date('Y-m-d H:i:s') . ' ] ' . $message;
    @file_put_contents($file, json_encode($history, JSON_PRETTY_PRINT));
}

function ws_read_json($path) {
    if (!file_exists($path)) {
        return [];
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function ws_write_json($path, array $data) {
    return file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}

$webhookConfigPath = __DIR__ . '/../../webhook/config.json';
$technicalMenuDbPath = __DIR__ . '/../../webhook/technical_menu_db.json';
$billingConfigPath = __DIR__ . '/../config.json';

switch ($action) {

    case 'database':
        if ($method === 'GET') {
            $cfg = ws_read_json($webhookConfigPath);
            api_json(['success' => true, 'data' => [
                'db_host' => $cfg['db_host'] ?? '',
                'db_user' => $cfg['db_user'] ?? '',
                'db_name' => $cfg['db_name'] ?? '',
                'db_billing' => $cfg['db_billing'] ?? '',
                // db_pass intentionally omitted from GET response.
            ]]);
        }
        if ($method !== 'POST') {
            api_json(['success' => false, 'error' => 'Method tidak didukung'], 405);
        }
        $dbHost = trim((string)($input['db_host'] ?? ''));
        $dbUser = trim((string)($input['db_user'] ?? ''));
        $dbPass = trim((string)($input['db_pass'] ?? ''));
        $dbName = trim((string)($input['db_name'] ?? ''));
        $dbBilling = trim((string)($input['db_billing'] ?? ''));
        if ($dbHost === '' || $dbUser === '' || $dbName === '') {
            api_json(['success' => false, 'error' => 'db_host, db_user, dan db_name wajib diisi']);
        }
        $test = @new mysqli($dbHost, $dbUser, $dbPass, $dbName);
        if ($test->connect_error) {
            api_json(['success' => false, 'error' => 'Gagal koneksi database: ' . $test->connect_error]);
        }
        $test->close();

        $cfg = ws_read_json($webhookConfigPath);
        $cfg['db_host'] = $dbHost;
        $cfg['db_user'] = $dbUser;
        $cfg['db_pass'] = $dbPass;
        $cfg['db_name'] = $dbName;
        $cfg['db_billing'] = $dbBilling;
        $ok = ws_write_json($webhookConfigPath, $cfg);
        if ($ok) {
            ws_log_history($ownerUsername, "Database settings updated: $dbHost / $dbName");
        }
        api_json(['success' => $ok, 'error' => $ok ? null : 'Gagal menyimpan config file']);
        break;

    case 'function':
        if ($method === 'GET') {
            $cfg = ws_read_json($webhookConfigPath);
            api_json(['success' => true, 'data' => $cfg['functions'] ?? []]);
        }
        if ($method !== 'POST') {
            api_json(['success' => false, 'error' => 'Method tidak didukung'], 405);
        }
        $functionName = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($input['function_name'] ?? ''));
        $functionDesc = trim((string)($input['function_desc'] ?? ''));
        $functionFile = trim((string)($input['function_file'] ?? ''));
        $isEnabled = !empty($input['function_enabled']);
        if ($functionName === '' || $functionFile === '') {
            api_json(['success' => false, 'error' => 'function_name dan function_file wajib diisi']);
        }
        $cfg = ws_read_json($webhookConfigPath);
        if (!isset($cfg['functions']) || !is_array($cfg['functions'])) {
            $cfg['functions'] = [];
        }
        $cfg['functions'][$functionName] = [
            'description' => $functionDesc,
            'file' => $functionFile,
            'enabled' => $isEnabled,
            'created_at' => $cfg['functions'][$functionName]['created_at'] ?? date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        $ok = ws_write_json($webhookConfigPath, $cfg);
        if ($ok) {
            ws_log_history($ownerUsername, "Function settings updated: $functionName");
        }
        api_json(['success' => $ok, 'error' => $ok ? null : 'Gagal menyimpan config file']);
        break;

    case 'technical_menu_db':
        if ($method === 'GET') {
            $cfg = ws_read_json($technicalMenuDbPath);
            api_json(['success' => true, 'data' => [
                'host' => $cfg['host'] ?? 'localhost',
                'user' => $cfg['user'] ?? '',
                'name' => $cfg['name'] ?? 'absensi',
                // pass intentionally omitted from GET response.
            ]]);
        }
        if ($method !== 'POST') {
            api_json(['success' => false, 'error' => 'Method tidak didukung'], 405);
        }
        $techDbHost = trim((string)($input['tech_db_host'] ?? ''));
        $techDbUser = trim((string)($input['tech_db_user'] ?? ''));
        $techDbPass = trim((string)($input['tech_db_pass'] ?? ''));
        $techDbName = trim((string)($input['tech_db_name'] ?? ''));
        if ($techDbHost === '' || $techDbUser === '' || $techDbName === '') {
            api_json(['success' => false, 'error' => 'tech_db_host, tech_db_user, dan tech_db_name wajib diisi']);
        }
        $test = @new mysqli($techDbHost, $techDbUser, $techDbPass, $techDbName);
        if ($test->connect_error) {
            api_json(['success' => false, 'error' => 'Gagal koneksi database: ' . $test->connect_error]);
        }
        $test->close();

        $ok = ws_write_json($technicalMenuDbPath, [
            'host' => $techDbHost, 'user' => $techDbUser, 'pass' => $techDbPass, 'name' => $techDbName,
        ]);
        if ($ok) {
            ws_log_history($ownerUsername, "Technical Menu DB settings updated: $techDbHost / $techDbName");
        }
        api_json(['success' => $ok, 'error' => $ok ? null : 'Gagal menyimpan technical menu db config file']);
        break;

    case 'port_range':
        if ($method === 'GET') {
            $cfg = ws_read_json($billingConfigPath);
            api_json(['success' => true, 'data' => [
                'port_start_bot' => isset($cfg['port_start_bot']) ? (int)$cfg['port_start_bot'] : 3000,
                'port_end_bot' => isset($cfg['port_end_bot']) ? (int)$cfg['port_end_bot'] : 3999,
            ]]);
        }
        if ($method !== 'POST') {
            api_json(['success' => false, 'error' => 'Method tidak didukung'], 405);
        }
        $portStart = (int)($input['port_start_bot'] ?? 3000);
        $portEnd = (int)($input['port_end_bot'] ?? 3999);
        if ($portStart < 3000 || $portStart > 3999 || $portEnd < 3000 || $portEnd > 3999 || $portStart > $portEnd) {
            api_json(['success' => false, 'error' => 'Port range tidak valid. Gunakan rentang 3000-3999 dan pastikan start <= end']);
        }
        $cfg = ws_read_json($billingConfigPath);
        $cfg['port_start_bot'] = $portStart;
        $cfg['port_end_bot'] = $portEnd;
        $ok = ws_write_json($billingConfigPath, $cfg);
        if ($ok) {
            ws_log_history($ownerUsername, "Port range bot updated: {$portStart}-{$portEnd}");
        }
        api_json(['success' => $ok, 'error' => $ok ? null : 'Gagal menyimpan port range bot']);
        break;

    default:
        api_json(['success' => false, 'error' => 'action harus salah satu: database, function, technical_menu_db, port_range'], 400);
}
