<?php
header('Content-Type: application/json');
require_once '../koneksibilling.php';
session_start();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = [];
if (in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
    $raw = (string)file_get_contents('php://input');
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
}

function auth_payment_user($conn, $username, $password) {
    $stmt = $conn->prepare('SELECT id, USERNAME, PASWORD FROM user WHERE USERNAME=? LIMIT 1');
    if (!$stmt) return null;
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        if (password_verify($password, (string)$row['PASWORD']) || (string)$password === (string)$row['PASWORD']) {
            return [
                'id' => (int)($row['id'] ?? 0),
                'username' => (string)$row['USERNAME']
            ];
        }
    }
    return null;
}

function table_exists_mobile($conn, $tableName) {
    $tableEsc = mysqli_real_escape_string($conn, $tableName);
    $res = mysqli_query($conn, "SHOW TABLES LIKE '$tableEsc'");
    return $res && mysqli_num_rows($res) > 0;
}

function table_columns_mobile($conn, $tableName) {
    $tableEsc = mysqli_real_escape_string($conn, $tableName);
    $cols = [];
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `$tableEsc`");
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        if (!empty($row['Field'])) {
            $cols[] = (string)$row['Field'];
        }
    }
    return $cols;
}

try {
    if (!$conn) {
        echo json_encode(['success' => false, 'error' => 'Koneksi DB gagal']);
        exit;
    }

    $username = (string)($input['username'] ?? ($_GET['username'] ?? ''));
    $password = (string)($input['password'] ?? ($_GET['password'] ?? ''));
    $action = strtolower(trim((string)($input['action'] ?? ($_GET['action'] ?? 'load'))));

    $auth = auth_payment_user($conn, $username, $password);
    if (!$auth) {
        echo json_encode(['success' => false, 'error' => 'Autentikasi gagal']);
        exit;
    }

    $pemilik = $auth['username'];

    if ($method === 'POST' && $action === 'set_default') {
        $allowed = ['manual_bank', 'tripay', 'duitku', 'midtrans', 'xendit'];
        $value = strtolower(trim((string)($input['default_payment'] ?? 'manual_bank')));
        if (!in_array($value, $allowed, true)) {
            echo json_encode(['success' => false, 'error' => 'default_payment tidak valid']);
            exit;
        }

        $stmt = $conn->prepare('UPDATE user SET payment_default=? WHERE USERNAME=?');
        $stmt->bind_param('ss', $value, $pemilik);
        $ok = $stmt->execute();
        echo json_encode(['success' => (bool)$ok]);
        exit;
    }

    if ($method === 'POST' && $action === 'save_manual_bank') {
        $id = (int)($input['id'] ?? 0);
        $namaBank = trim((string)($input['nama_bank'] ?? ''));
        $namaPemilikBank = trim((string)($input['nama_pemilik_bank'] ?? ''));
        $rekeningBank = trim((string)($input['rekening_bank'] ?? ''));
        $server = trim((string)($input['server'] ?? ''));
        $ppn = is_numeric($input['ppn'] ?? null) ? (float)$input['ppn'] : 11.0;
        $bhpsUso = is_numeric($input['bhps_uso'] ?? null) ? (float)$input['bhps_uso'] : 0.0;

        if ($namaBank === '' || $namaPemilikBank === '' || $rekeningBank === '' || $server === '') {
            echo json_encode(['success' => false, 'error' => 'Semua field bank wajib diisi']);
            exit;
        }

        if ($id > 0) {
            $stmt = $conn->prepare('UPDATE manualbank SET nama_bank=?, nama_pemilik_bank=?, rekening_bank=?, server=?, ppn=?, bhps_uso=? WHERE id=? AND pemilik=?');
            $stmt->bind_param('ssssddis', $namaBank, $namaPemilikBank, $rekeningBank, $server, $ppn, $bhpsUso, $id, $pemilik);
            $ok = $stmt->execute();
        } else {
            $stmt = $conn->prepare('INSERT INTO manualbank (nama_bank, nama_pemilik_bank, rekening_bank, pemilik, server, ppn, bhps_uso) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('sssssdd', $namaBank, $namaPemilikBank, $rekeningBank, $pemilik, $server, $ppn, $bhpsUso);
            $ok = $stmt->execute();
        }

        echo json_encode(['success' => (bool)$ok]);
        exit;
    }

    if ($method === 'POST' && $action === 'delete_manual_bank') {
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'error' => 'id tidak valid']);
            exit;
        }
        $stmt = $conn->prepare('DELETE FROM manualbank WHERE id=? AND pemilik=?');
        $stmt->bind_param('is', $id, $pemilik);
        $ok = $stmt->execute();
        echo json_encode(['success' => (bool)$ok]);
        exit;
    }

    if ($method === 'POST' && $action === 'save_tripay') {
        $id = (int)($input['id'] ?? 0);
        $apiKey = trim((string)($input['api_key'] ?? ''));
        $privateKey = trim((string)($input['private_key'] ?? ''));
        $merchantCode = trim((string)($input['merchant_code'] ?? ''));
        $server = trim((string)($input['server'] ?? $pemilik));
        $ppn = trim((string)($input['ppn'] ?? '11'));
        $bhpsUso = trim((string)($input['bhps_uso'] ?? '0'));
        $authMode = trim((string)($input['default_auth_mode'] ?? 'RADIUS MODE'));

        if ($apiKey === '' || $privateKey === '' || $merchantCode === '') {
            echo json_encode(['success' => false, 'error' => 'API key, private key, dan merchant code wajib diisi']);
            exit;
        }

        if ($id > 0) {
            $stmt = $conn->prepare('UPDATE tripay SET apikey=?, privatekey=?, merchant=?, server=?, pajak=?, bhps_uso=?, default_auth_mode=? WHERE id=? AND pemilik=?');
            $stmt->bind_param('sssssssis', $apiKey, $privateKey, $merchantCode, $server, $ppn, $bhpsUso, $authMode, $id, $pemilik);
        } else {
            $stmt = $conn->prepare('INSERT INTO tripay (apikey, privatekey, merchant, pemilik, server, pajak, bhps_uso, default_auth_mode) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('ssssssss', $apiKey, $privateKey, $merchantCode, $pemilik, $server, $ppn, $bhpsUso, $authMode);
        }
        $ok = $stmt && $stmt->execute();
        echo json_encode(['success' => (bool)$ok]);
        exit;
    }

    if ($method === 'POST' && $action === 'save_duitku') {
        $id = (int)($input['id'] ?? 0);
        $merchantCode = trim((string)($input['merchant_code'] ?? ''));
        $apiKey = trim((string)($input['api_key'] ?? ''));
        $server = trim((string)($input['server'] ?? $pemilik));
        $ppn = trim((string)($input['ppn'] ?? '11'));
        $bhpsUso = trim((string)($input['bhps_uso'] ?? '0'));
        $authMode = trim((string)($input['default_auth_mode'] ?? 'RADIUS MODE'));
        $returnUrl = trim((string)($input['return_url'] ?? ("https://quenbytekniksejahtera.com/crm/billing/callbackduitku/callback_duitku_{$pemilik}.php")));

        if ($merchantCode === '' || $apiKey === '') {
            echo json_encode(['success' => false, 'error' => 'Merchant code dan API key wajib diisi']);
            exit;
        }

        if ($id > 0) {
            $stmt = $conn->prepare('UPDATE duitku SET merchant_code=?, api_key=?, return_url=?, server=?, pajak=?, bhps_uso=?, default_auth_mode=? WHERE id=? AND pemilik=?');
            $stmt->bind_param('sssssssis', $merchantCode, $apiKey, $returnUrl, $server, $ppn, $bhpsUso, $authMode, $id, $pemilik);
        } else {
            $stmt = $conn->prepare('INSERT INTO duitku (merchant_code, api_key, return_url, pemilik, server, pajak, bhps_uso, default_auth_mode) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('ssssssss', $merchantCode, $apiKey, $returnUrl, $pemilik, $server, $ppn, $bhpsUso, $authMode);
        }
        $ok = $stmt && $stmt->execute();
        echo json_encode(['success' => (bool)$ok]);
        exit;
    }

    if ($method === 'POST' && $action === 'save_midtrans') {
        $id = (int)($input['id'] ?? 0);
        $merchantId = trim((string)($input['merchant_id'] ?? ''));
        $serverKey = trim((string)($input['server_key'] ?? ''));
        $clientKey = trim((string)($input['client_key'] ?? ''));
        $server = trim((string)($input['server'] ?? $pemilik));
        $ppn = trim((string)($input['ppn'] ?? '11'));
        $bhpsUso = trim((string)($input['bhps_uso'] ?? '0'));
        $authMode = trim((string)($input['default_auth_mode'] ?? 'RADIUS MODE'));

        if ($merchantId === '' || $serverKey === '' || $clientKey === '') {
            echo json_encode(['success' => false, 'error' => 'Merchant ID, server key, dan client key wajib diisi']);
            exit;
        }

        if ($id > 0) {
            $stmt = $conn->prepare('UPDATE midtrans SET merchant_id=?, server_key=?, client_key=?, server=?, pajak=?, bhps_uso=?, default_auth_mode=? WHERE id=? AND pemilik=?');
            $stmt->bind_param('sssssssis', $merchantId, $serverKey, $clientKey, $server, $ppn, $bhpsUso, $authMode, $id, $pemilik);
        } else {
            $stmt = $conn->prepare('INSERT INTO midtrans (merchant_id, server_key, client_key, pemilik, server, pajak, bhps_uso, default_auth_mode) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('ssssssss', $merchantId, $serverKey, $clientKey, $pemilik, $server, $ppn, $bhpsUso, $authMode);
        }
        $ok = $stmt && $stmt->execute();
        echo json_encode(['success' => (bool)$ok]);
        exit;
    }

    if ($method === 'POST' && $action === 'save_xendit') {
        $id = (int)($input['id'] ?? 0);
        $merchantId = trim((string)($input['merchant_id'] ?? ''));
        $serverKey = trim((string)($input['server_key'] ?? ''));
        $clientKey = trim((string)($input['client_key'] ?? ''));
        $server = trim((string)($input['server'] ?? $pemilik));
        $ppn = trim((string)($input['ppn'] ?? '11'));
        $bhpsUso = trim((string)($input['bhps_uso'] ?? '0'));
        $authMode = trim((string)($input['default_auth_mode'] ?? 'RADIUS MODE'));

        if ($merchantId === '' || $serverKey === '' || $clientKey === '') {
            echo json_encode(['success' => false, 'error' => 'Merchant ID, server key, dan client key wajib diisi']);
            exit;
        }

        if ($id > 0) {
            $stmt = $conn->prepare('UPDATE xendit SET merchant_id=?, server_key=?, client_key=?, server=?, pajak=?, bhps_uso=?, default_auth_mode=? WHERE id=? AND pemilik=?');
            $stmt->bind_param('sssssssis', $merchantId, $serverKey, $clientKey, $server, $ppn, $bhpsUso, $authMode, $id, $pemilik);
        } else {
            $stmt = $conn->prepare('INSERT INTO xendit (merchant_id, server_key, client_key, pemilik, server, pajak, bhps_uso, default_auth_mode) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('ssssssss', $merchantId, $serverKey, $clientKey, $pemilik, $server, $ppn, $bhpsUso, $authMode);
        }
        $ok = $stmt && $stmt->execute();
        echo json_encode(['success' => (bool)$ok]);
        exit;
    }

    if ($method === 'POST' && $action === 'delete_gateway') {
        $id = (int)($input['id'] ?? 0);
        $gateway = strtolower(trim((string)($input['gateway'] ?? '')));
        $tableMap = [
            'tripay' => 'tripay',
            'duitku' => 'duitku',
            'midtrans' => 'midtrans',
            'xendit' => 'xendit'
        ];

        if ($id <= 0 || !isset($tableMap[$gateway])) {
            echo json_encode(['success' => false, 'error' => 'Parameter delete gateway tidak valid']);
            exit;
        }

        $tableName = $tableMap[$gateway];
        $stmt = $conn->prepare("DELETE FROM `$tableName` WHERE id=? AND pemilik=?");
        $stmt->bind_param('is', $id, $pemilik);
        $ok = $stmt && $stmt->execute();
        echo json_encode(['success' => (bool)$ok]);
        exit;
    }

    $manualBanks = [];
    $stmtMb = $conn->prepare('SELECT id, nama_bank, nama_pemilik_bank, rekening_bank, server, ppn, bhps_uso FROM manualbank WHERE pemilik=? ORDER BY id DESC');
    $stmtMb->bind_param('s', $pemilik);
    $stmtMb->execute();
    $resMb = $stmtMb->get_result();
    while ($resMb && ($row = $resMb->fetch_assoc())) {
        $manualBanks[] = $row;
    }

    $currentDefault = 'manual_bank';
    $stmtDef = $conn->prepare('SELECT payment_default FROM user WHERE USERNAME=? LIMIT 1');
    $stmtDef->bind_param('s', $pemilik);
    $stmtDef->execute();
    $resDef = $stmtDef->get_result();
    if ($resDef && $resDef->num_rows > 0) {
        $r = $resDef->fetch_assoc();
        $val = trim((string)($r['payment_default'] ?? ''));
        if ($val !== '') $currentDefault = $val;
    }

    $countTripay = 0;
    $countDuitku = 0;
    $countMidtrans = 0;
    $countXendit = 0;
    $tripayConfigs = [];
    $duitkuConfigs = [];
    $midtransConfigs = [];
    $xenditConfigs = [];

    if (table_exists_mobile($conn, 'tripay')) {
        $q = $conn->prepare('SELECT COUNT(*) AS c FROM tripay WHERE pemilik=?');
        $q->bind_param('s', $pemilik);
        $q->execute();
        $r = $q->get_result();
        if ($r && $r->num_rows > 0) $countTripay = (int)($r->fetch_assoc()['c'] ?? 0);

        $q = $conn->prepare('SELECT * FROM tripay WHERE pemilik=? ORDER BY id DESC');
        $q->bind_param('s', $pemilik);
        $q->execute();
        $r = $q->get_result();
        while ($r && ($row = $r->fetch_assoc())) {
            $tripayConfigs[] = [
                'id' => (int)($row['id'] ?? 0),
                'pemilik' => (string)($row['pemilik'] ?? ''),
                'api_key' => (string)($row['apikey'] ?? ''),
                'private_key' => (string)($row['privatekey'] ?? ''),
                'merchant_code' => (string)($row['merchant'] ?? ''),
                'ppn' => (string)($row['pajak'] ?? ''),
                'bhps_uso' => (string)($row['bhps_uso'] ?? ''),
                'default_auth_mode' => (string)($row['default_auth_mode'] ?? '')
            ];
        }
    }
    if (table_exists_mobile($conn, 'duitku')) {
        $q = $conn->prepare('SELECT COUNT(*) AS c FROM duitku WHERE pemilik=?');
        $q->bind_param('s', $pemilik);
        $q->execute();
        $r = $q->get_result();
        if ($r && $r->num_rows > 0) $countDuitku = (int)($r->fetch_assoc()['c'] ?? 0);

        $q = $conn->prepare('SELECT * FROM duitku WHERE pemilik=? ORDER BY id DESC');
        $q->bind_param('s', $pemilik);
        $q->execute();
        $r = $q->get_result();
        while ($r && ($row = $r->fetch_assoc())) {
            $duitkuConfigs[] = [
                'id' => (int)($row['id'] ?? 0),
                'pemilik' => (string)($row['pemilik'] ?? ''),
                'merchant_code' => (string)($row['merchant_code'] ?? ''),
                'api_key' => (string)($row['api_key'] ?? ''),
                'return_url' => (string)($row['return_url'] ?? ''),
                'ppn' => (string)($row['pajak'] ?? ''),
                'bhps_uso' => (string)($row['bhps_uso'] ?? ''),
                'default_auth_mode' => (string)($row['default_auth_mode'] ?? '')
            ];
        }
    }
    if (table_exists_mobile($conn, 'midtrans')) {
        $q = $conn->prepare('SELECT COUNT(*) AS c FROM midtrans WHERE pemilik=?');
        $q->bind_param('s', $pemilik);
        $q->execute();
        $r = $q->get_result();
        if ($r && $r->num_rows > 0) $countMidtrans = (int)($r->fetch_assoc()['c'] ?? 0);

        $q = $conn->prepare('SELECT * FROM midtrans WHERE pemilik=? ORDER BY id DESC');
        $q->bind_param('s', $pemilik);
        $q->execute();
        $r = $q->get_result();
        while ($r && ($row = $r->fetch_assoc())) {
            $midtransConfigs[] = [
                'id' => (int)($row['id'] ?? 0),
                'pemilik' => (string)($row['pemilik'] ?? ''),
                'merchant_id' => (string)($row['merchant_id'] ?? ''),
                'server_key' => (string)($row['server_key'] ?? ''),
                'client_key' => (string)($row['client_key'] ?? ''),
                'ppn' => (string)($row['pajak'] ?? ''),
                'bhps_uso' => (string)($row['bhps_uso'] ?? ''),
                'default_auth_mode' => (string)($row['default_auth_mode'] ?? '')
            ];
        }
    }
    if (table_exists_mobile($conn, 'xendit')) {
        $q = $conn->prepare('SELECT COUNT(*) AS c FROM xendit WHERE pemilik=?');
        $q->bind_param('s', $pemilik);
        $q->execute();
        $r = $q->get_result();
        if ($r && $r->num_rows > 0) $countXendit = (int)($r->fetch_assoc()['c'] ?? 0);

        $q = $conn->prepare('SELECT * FROM xendit WHERE pemilik=? ORDER BY id DESC');
        $q->bind_param('s', $pemilik);
        $q->execute();
        $r = $q->get_result();
        while ($r && ($row = $r->fetch_assoc())) {
            $xenditConfigs[] = [
                'id' => (int)($row['id'] ?? 0),
                'pemilik' => (string)($row['pemilik'] ?? ''),
                'merchant_id' => (string)($row['merchant_id'] ?? ''),
                'server_key' => (string)($row['server_key'] ?? ''),
                'client_key' => (string)($row['client_key'] ?? ''),
                'ppn' => (string)($row['pajak'] ?? ''),
                'bhps_uso' => (string)($row['bhps_uso'] ?? ''),
                'default_auth_mode' => (string)($row['default_auth_mode'] ?? '')
            ];
        }
    }

    $servers = [];
    $stmtSrv = $conn->prepare('SELECT DISTINCT PEMILIK, BRAND, AREA FROM server WHERE user_id=? OR PEMILIK=? ORDER BY AREA ASC, BRAND ASC');
    $uid = (int)($auth['id'] ?? 0);
    $stmtSrv->bind_param('is', $uid, $pemilik);
    $stmtSrv->execute();
    $resSrv = $stmtSrv->get_result();
    while ($resSrv && ($row = $resSrv->fetch_assoc())) {
        $servers[] = $row;
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'default_payment' => $currentDefault,
            'status' => [
                'manual_bank' => count($manualBanks),
                'tripay' => $countTripay,
                'duitku' => $countDuitku,
                'midtrans' => $countMidtrans,
                'xendit' => $countXendit
            ],
            'servers' => $servers,
            'manual_banks' => $manualBanks,
            'tripay_configs' => $tripayConfigs,
            'duitku_configs' => $duitkuConfigs,
            'midtrans_configs' => $midtransConfigs,
            'xendit_configs' => $xenditConfigs
        ]
    ]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
