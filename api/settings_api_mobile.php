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

function auth_user_mobile($conn, $username, $password) {
    $stmt = $conn->prepare('SELECT USERNAME, PASWORD FROM user WHERE USERNAME = ? LIMIT 1');
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

function ensure_apikey_table($conn) {
    $sql = "CREATE TABLE IF NOT EXISTS apikey (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_name VARCHAR(191) NOT NULL UNIQUE,
        api_key VARCHAR(191) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $conn->query($sql);
}

try {
    if (!$conn) {
        echo json_encode(['success' => false, 'error' => 'Koneksi DB gagal']);
        exit;
    }

    $username = (string)($input['username'] ?? ($_POST['username'] ?? ($_GET['username'] ?? '')));
    $password = (string)($input['password'] ?? ($_POST['password'] ?? ($_GET['password'] ?? '')));
    $action = strtolower(trim((string)($input['action'] ?? ($_POST['action'] ?? ($_GET['action'] ?? 'load')))));

    $pemilik = auth_user_mobile($conn, $username, $password);
    if (!$pemilik) {
        echo json_encode(['success' => false, 'error' => 'Autentikasi gagal']);
        exit;
    }

    ensure_apikey_table($conn);

    $stmtKey = $conn->prepare('SELECT api_key FROM apikey WHERE user_name = ? LIMIT 1');
    $stmtKey->bind_param('s', $pemilik);
    $stmtKey->execute();
    $resKey = $stmtKey->get_result();
    $currentKey = '';
    if ($resKey && $resKey->num_rows > 0) {
        $rowKey = $resKey->fetch_assoc();
        $currentKey = (string)($rowKey['api_key'] ?? '');
    }

    if ($method === 'POST' && $action === 'regenerate') {
        $newKey = bin2hex(random_bytes(16));
        if ($currentKey !== '') {
            $stmtUp = $conn->prepare('UPDATE apikey SET api_key = ? WHERE user_name = ?');
            $stmtUp->bind_param('ss', $newKey, $pemilik);
            $ok = $stmtUp->execute();
        } else {
            $stmtIn = $conn->prepare('INSERT INTO apikey (user_name, api_key) VALUES (?, ?)');
            $stmtIn->bind_param('ss', $pemilik, $newKey);
            $ok = $stmtIn->execute();
        }

        if (!$ok) {
            echo json_encode(['success' => false, 'error' => 'Gagal regenerate API key']);
            exit;
        }
        $currentKey = $newKey;
    }

    if ($currentKey === '') {
        $generated = bin2hex(random_bytes(16));
        $stmtIn = $conn->prepare('INSERT INTO apikey (user_name, api_key) VALUES (?, ?)');
        $stmtIn->bind_param('ss', $pemilik, $generated);
        $stmtIn->execute();
        $currentKey = $generated;
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'api_key' => $currentKey,
            'settings' => [
                'cors_origin' => '*',
                'script_timeout' => 30,
                'rate_limit_per_hour' => 100,
                'mikrotik_timeout' => 10
            ],
            'endpoints' => [
                [
                    'label' => 'Daftar fitur API',
                    'url' => 'apiinterface.php?key=' . $currentKey . '&type=api_features'
                ],
                [
                    'label' => 'Data inti billing',
                    'url' => 'apiinterface.php?key=' . $currentKey . '&type=billing_core_data'
                ],
                [
                    'label' => 'Servers',
                    'url' => 'apiinterface.php?key=' . $currentKey . '&type=servers'
                ],
                [
                    'label' => 'Pelanggan aktif',
                    'url' => 'apiinterface.php?key=' . $currentKey . '&type=active_customers'
                ],
                [
                    'label' => 'Transaksi',
                    'url' => 'apiinterface.php?key=' . $currentKey . '&type=transactions'
                ]
            ],
            'documentation_url' => 'https://quenbytekniksejahtera.com/crm/billing/documentationapi.php'
        ]
    ]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
