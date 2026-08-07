<?php
// api/wabot_integrations.php - WA Resmi (Meta Cloud API/Qiscus/Custom) and WA Unofficial
// (Fonnte/Wablas/UltraMsg/Evolution/Gowa-external/Custom) integration CRUD + activate/deactivate/
// test-send. Ports crm/billing/wabot.php's two integration blocks with full parity - both were
// already correctly ownership-checked in the web version (`WHERE id=? AND pemilik=?` everywhere),
// so this file mainly adapts the same logic + the same crm/webhook/wa_resmi_helper.php /
// wa_unofficial_helper.php send-dispatch functions to JSON request/response instead of
// form POST + redirect-with-alert.
//
// type=resmi   -> integrasi_whatsapp_resmi table, wr_* helper functions
// type=unofficial -> integrasi_whatsapp_unofficial table, wu_* helper functions
header('Content-Type: application/json');
require_once '../koneksibilling.php';
require_once '_bootstrap.php';
session_start();
api_cors();

$method = $_SERVER['REQUEST_METHOD'];
$input  = api_read_input();
$type   = strtolower(trim((string)($input['type'] ?? ($_GET['type'] ?? 'resmi'))));
$action = strtolower(trim((string)($input['action'] ?? ($_GET['action'] ?? 'list'))));

if (!in_array($type, ['resmi', 'unofficial'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => "type harus 'resmi' atau 'unofficial'"]);
    exit;
}

$auth = api_authenticate($conn, $input);
$pemilik = $auth['pemilik'];
if ($auth['method'] === 'apikey') {
    api_rate_limit($conn, $auth['api_key']);
}
$ctx = api_resolve_owner($conn, $pemilik);
if (!$ctx) {
    api_json(['success' => false, 'error' => 'User tidak ditemukan'], 401);
}
api_require_module_enabled($conn, $pemilik, 'wabot');

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

if ($type === 'resmi') {
    require_once __DIR__ . '/../../webhook/wa_resmi_db_check.php';
    require_once __DIR__ . '/../../webhook/wa_resmi_helper.php';
    $table = 'integrasi_whatsapp_resmi';
    $providers = ['meta_cloud_api', 'qiscus', 'custom'];
} else {
    require_once __DIR__ . '/../../webhook/wa_unofficial_db_check.php';
    require_once __DIR__ . '/../../webhook/wa_unofficial_helper.php';
    $table = 'integrasi_whatsapp_unofficial';
    $providers = ['fonnte', 'wablas', 'ultramsg', 'evolution_api', 'gowa_external', 'custom'];
}

function wi_log_history($owner, $message) {
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

switch ($method) {
    case 'GET':
        $stmt = $conn->prepare("SELECT * FROM `$table` WHERE pemilik = ? ORDER BY status DESC, id DESC");
        api_bind($stmt, 's', [$pemilik]);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $botStmt = $conn->prepare('SELECT id, namebot, addressbot, tipe_bot FROM botwa WHERE pemilik = ? ORDER BY namebot ASC');
        api_bind($botStmt, 's', [$pemilik]);
        $botStmt->execute();
        $bots = $botStmt->get_result()->fetch_all(MYSQLI_ASSOC);

        api_json(['success' => true, 'type' => $type, 'data' => $rows, 'available_providers' => $providers, 'bots' => $bots]);
        break;

    case 'POST':
        switch ($action) {
            case 'save':
                $id = (int)($input['id'] ?? 0);
                $provider = (string)($input['provider'] ?? '');
                $namaIntegrasi = trim((string)($input['nama_integrasi'] ?? ''));
                if (!in_array($provider, $providers, true) || $namaIntegrasi === '') {
                    api_json(['success' => false, 'error' => 'nama_integrasi dan provider (valid) wajib diisi']);
                }

                if ($type === 'resmi') {
                    $fields = [
                        'base_url' => trim((string)($input['base_url'] ?? '')),
                        'api_version' => trim((string)($input['api_version'] ?? '')),
                        'phone_number_id' => trim((string)($input['phone_number_id'] ?? '')),
                        'waba_id' => trim((string)($input['waba_id'] ?? '')),
                        'sender_number' => trim((string)($input['sender_number'] ?? '')),
                        'access_token' => trim((string)($input['access_token'] ?? '')),
                        'api_key' => trim((string)($input['api_key'] ?? '')),
                        'app_id' => trim((string)($input['app_id'] ?? '')),
                        'channel_id' => trim((string)($input['channel_id'] ?? '')),
                        'auth_header_type' => trim((string)($input['auth_header_type'] ?? 'bearer')),
                        'auth_header_name' => trim((string)($input['auth_header_name'] ?? 'Authorization')),
                        'use_template_for_notif' => !empty($input['use_template_for_notif']) ? 1 : 0,
                        'template_name' => trim((string)($input['template_name'] ?? '')),
                        'template_language' => trim((string)($input['template_language'] ?? 'id')),
                        'custom_endpoint_path' => trim((string)($input['custom_endpoint_path'] ?? '')),
                        'custom_body_template' => trim((string)($input['custom_body_template'] ?? '')),
                    ];
                    if ($id > 0) {
                        $stmt = $conn->prepare('UPDATE integrasi_whatsapp_resmi SET nama_integrasi=?, provider=?, base_url=?, api_version=?, phone_number_id=?, waba_id=?, sender_number=?, access_token=?, api_key=?, app_id=?, channel_id=?, auth_header_type=?, auth_header_name=?, use_template_for_notif=?, template_name=?, template_language=?, custom_endpoint_path=?, custom_body_template=? WHERE id=? AND pemilik=?');
                        api_bind($stmt, 'sssssssssssssissssis', [
                            $namaIntegrasi, $provider, $fields['base_url'], $fields['api_version'], $fields['phone_number_id'],
                            $fields['waba_id'], $fields['sender_number'], $fields['access_token'], $fields['api_key'], $fields['app_id'],
                            $fields['channel_id'], $fields['auth_header_type'], $fields['auth_header_name'], $fields['use_template_for_notif'],
                            $fields['template_name'], $fields['template_language'], $fields['custom_endpoint_path'], $fields['custom_body_template'],
                            $id, $pemilik,
                        ]);
                    } else {
                        $stmt = $conn->prepare('INSERT INTO integrasi_whatsapp_resmi (pemilik, nama_integrasi, provider, base_url, api_version, phone_number_id, waba_id, sender_number, access_token, api_key, app_id, channel_id, auth_header_type, auth_header_name, use_template_for_notif, template_name, template_language, custom_endpoint_path, custom_body_template) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                        api_bind($stmt, 'ssssssssssssssissss', [
                            $pemilik, $namaIntegrasi, $provider, $fields['base_url'], $fields['api_version'], $fields['phone_number_id'],
                            $fields['waba_id'], $fields['sender_number'], $fields['access_token'], $fields['api_key'], $fields['app_id'],
                            $fields['channel_id'], $fields['auth_header_type'], $fields['auth_header_name'], $fields['use_template_for_notif'],
                            $fields['template_name'], $fields['template_language'], $fields['custom_endpoint_path'], $fields['custom_body_template'],
                        ]);
                    }
                } else {
                    $fields = [
                        'base_url' => trim((string)($input['base_url'] ?? '')),
                        'api_token' => trim((string)($input['api_token'] ?? '')),
                        'secret_key' => trim((string)($input['secret_key'] ?? '')),
                        'instance_id' => trim((string)($input['instance_id'] ?? '')),
                        'sender_number' => trim((string)($input['sender_number'] ?? '')),
                        'auth_header_type' => trim((string)($input['auth_header_type'] ?? 'bearer')),
                        'auth_header_name' => trim((string)($input['auth_header_name'] ?? 'Authorization')),
                        'custom_endpoint_path' => trim((string)($input['custom_endpoint_path'] ?? '')),
                        'custom_body_template' => trim((string)($input['custom_body_template'] ?? '')),
                    ];
                    if ($id > 0) {
                        $stmt = $conn->prepare('UPDATE integrasi_whatsapp_unofficial SET nama_integrasi=?, provider=?, base_url=?, api_token=?, secret_key=?, instance_id=?, sender_number=?, auth_header_type=?, auth_header_name=?, custom_endpoint_path=?, custom_body_template=? WHERE id=? AND pemilik=?');
                        api_bind($stmt, 'sssssssssssis', [
                            $namaIntegrasi, $provider, $fields['base_url'], $fields['api_token'], $fields['secret_key'],
                            $fields['instance_id'], $fields['sender_number'], $fields['auth_header_type'], $fields['auth_header_name'],
                            $fields['custom_endpoint_path'], $fields['custom_body_template'], $id, $pemilik,
                        ]);
                    } else {
                        $stmt = $conn->prepare('INSERT INTO integrasi_whatsapp_unofficial (pemilik, nama_integrasi, provider, base_url, api_token, secret_key, instance_id, sender_number, auth_header_type, auth_header_name, custom_endpoint_path, custom_body_template) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
                        api_bind($stmt, 'ssssssssssss', [
                            $pemilik, $namaIntegrasi, $provider, $fields['base_url'], $fields['api_token'], $fields['secret_key'],
                            $fields['instance_id'], $fields['sender_number'], $fields['auth_header_type'], $fields['auth_header_name'],
                            $fields['custom_endpoint_path'], $fields['custom_body_template'],
                        ]);
                    }
                }

                $ok = $stmt->execute();
                if ($ok) {
                    $newId = $id > 0 ? $id : $conn->insert_id;
                    wi_log_history($ownerUsername, ($id > 0 ? 'Update' : 'Tambah') . " integrasi WA $type: $namaIntegrasi");
                    api_json(['success' => true, 'id' => $newId]);
                }
                api_json(['success' => false, 'error' => $stmt->error]);
                break;

            case 'activate':
                $id = (int)($input['id'] ?? 0);
                $targetBotwaId = (int)($input['target_botwa_id'] ?? 0);
                $newBotName = trim((string)($input['new_bot_name'] ?? ''));

                $chk = $conn->prepare("SELECT id FROM `$table` WHERE id=? AND pemilik=? LIMIT 1");
                api_bind($chk, 'is', [$id, $pemilik]);
                $chk->execute();
                if ($chk->get_result()->num_rows === 0) {
                    api_json(['success' => false, 'error' => 'Integrasi tidak ditemukan'], 404);
                }

                $gatewayBaseUrl = wi_gateway_base_url();
                $result = $type === 'resmi'
                    ? wr_activate_integration($conn, $id, $targetBotwaId, $newBotName, $gatewayBaseUrl)
                    : wu_activate_integration($conn, $id, $targetBotwaId, $newBotName, $gatewayBaseUrl);

                if (!empty($result['success'])) {
                    wi_log_history($ownerUsername, "Aktifkan integrasi WA $type #$id");
                }
                api_json(['success' => !empty($result['success']), 'error' => $result['error'] ?? null]);
                break;

            case 'deactivate':
                $id = (int)($input['id'] ?? 0);
                $chk = $conn->prepare("SELECT id FROM `$table` WHERE id=? AND pemilik=? LIMIT 1");
                api_bind($chk, 'is', [$id, $pemilik]);
                $chk->execute();
                if ($chk->get_result()->num_rows === 0) {
                    api_json(['success' => false, 'error' => 'Integrasi tidak ditemukan'], 404);
                }
                $result = $type === 'resmi' ? wr_deactivate_integration($conn, $id) : wu_deactivate_integration($conn, $id);
                if (!empty($result['success'])) {
                    wi_log_history($ownerUsername, "Nonaktifkan integrasi WA $type #$id");
                }
                api_json(['success' => !empty($result['success']), 'error' => $result['error'] ?? null]);
                break;

            case 'delete':
                $id = (int)($input['id'] ?? 0);
                $chk = $conn->prepare("SELECT status FROM `$table` WHERE id=? AND pemilik=? LIMIT 1");
                api_bind($chk, 'is', [$id, $pemilik]);
                $chk->execute();
                $row = $chk->get_result()->fetch_assoc();
                if (!$row) {
                    api_json(['success' => false, 'error' => 'Integrasi tidak ditemukan'], 404);
                }
                if ((int)$row['status'] === 1) {
                    $type === 'resmi' ? wr_deactivate_integration($conn, $id) : wu_deactivate_integration($conn, $id);
                }
                $del = $conn->prepare("DELETE FROM `$table` WHERE id=? AND pemilik=?");
                api_bind($del, 'is', [$id, $pemilik]);
                $ok = $del->execute();
                if ($ok) {
                    wi_log_history($ownerUsername, "Hapus integrasi WA $type #$id");
                }
                api_json(['success' => $ok, 'error' => $ok ? null : $del->error]);
                break;

            case 'test_send':
                $id = (int)($input['id'] ?? 0);
                $testPhone = trim((string)($input['test_phone'] ?? ''));
                if ($testPhone === '') {
                    api_json(['success' => false, 'error' => 'test_phone wajib diisi']);
                }
                $stmt = $conn->prepare("SELECT * FROM `$table` WHERE id=? AND pemilik=? LIMIT 1");
                api_bind($stmt, 'is', [$id, $pemilik]);
                $stmt->execute();
                $integrasi = $stmt->get_result()->fetch_assoc();
                if (!$integrasi) {
                    api_json(['success' => false, 'error' => 'Integrasi tidak ditemukan'], 404);
                }

                $testMessage = 'Tes koneksi ' . ($type === 'resmi' ? 'WhatsApp API Resmi' : 'Bot WhatsApp Lain') . ' dari billing ' . date('Y-m-d H:i:s');
                $result = $type === 'resmi'
                    ? wr_send_message($integrasi, $testPhone, $testMessage)
                    : wu_send_message($integrasi, $testPhone, $testMessage);

                $statusTxt = !empty($result['success']) ? 'sukses' : 'gagal';
                $msgTxt = !empty($result['success']) ? 'Terkirim' : ($result['error'] ?? 'Gagal');
                $upd = $conn->prepare("UPDATE `$table` SET last_test_at = NOW(), last_test_status = ?, last_test_message = ? WHERE id = ?");
                api_bind($upd, 'ssi', [$statusTxt, $msgTxt, $id]);
                $upd->execute();

                api_json(['success' => !empty($result['success']), 'message' => $msgTxt]);
                break;

            default:
                api_json(['success' => false, 'error' => 'Action tidak dikenali'], 400);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method tidak didukung']);
}

function wi_gateway_base_url() {
    $configPath = __DIR__ . '/../config.json';
    $config = file_exists($configPath) ? (json_decode((string)file_get_contents($configPath), true) ?: []) : [];
    if (!empty($config['webhook_url'])) {
        return rtrim((string)$config['webhook_url'], '/');
    }
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . '/crm/webhook';
}
