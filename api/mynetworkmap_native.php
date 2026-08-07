<?php
header('Content-Type: application/json');
require_once '../koneksibilling.php';
session_start();

try {
    if (!$conn) {
        echo json_encode(['success' => false, 'error' => 'Koneksi DB gagal']);
        exit;
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $input = [];
    if (in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
        $input = json_decode((string)file_get_contents('php://input'), true) ?: [];
    }

    $username = (string)($input['username'] ?? ($_GET['username'] ?? ''));
    $password = (string)($input['password'] ?? ($_GET['password'] ?? ''));

    function auth_user($conn, $username, $password) {
        $stmt = $conn->prepare("SELECT id, USERNAME, PASWORD FROM user WHERE USERNAME = ? LIMIT 1");
        if (!$stmt) return false;
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            $row = $res->fetch_assoc();
            if (password_verify($password, $row['PASWORD']) || (string)$password === (string)$row['PASWORD']) {
                return ['id' => (int)$row['id'], 'username' => (string)$row['USERNAME']];
            }
        }
        return false;
    }

    $user = auth_user($conn, $username, $password);
    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'Autentikasi gagal']);
        exit;
    }

    $userId = (int)$user['id'];

    // Ensure table exists (same concept as web page).
    $createSql = "CREATE TABLE IF NOT EXISTS network_devices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT DEFAULT 0,
        name VARCHAR(100),
        ip_address VARCHAR(45),
        `type` VARCHAR(50),
        location VARCHAR(100),
        parent_id INT DEFAULT NULL,
        `description` TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    $conn->query($createSql);

    if ($method === 'POST') {
        $name = trim((string)($input['nama'] ?? ($input['name'] ?? '')));
        $ip = trim((string)($input['ip'] ?? ($input['ip_address'] ?? '')));
        $location = trim((string)($input['area'] ?? ($input['location'] ?? '')));
        $type = trim((string)($input['type'] ?? 'Router'));
        $description = trim((string)($input['description'] ?? ''));

        if ($name === '' || $ip === '') {
            echo json_encode(['success' => false, 'error' => 'Nama dan IP wajib diisi']);
            exit;
        }

        $stmt = $conn->prepare("INSERT INTO network_devices (user_id, name, ip_address, `type`, location, parent_id, `description`) VALUES (?, ?, ?, ?, ?, 0, ?)");
        $stmt->bind_param('isssss', $userId, $name, $ip, $type, $location, $description);
        $ok = $stmt->execute();
        echo json_encode(['success' => (bool)$ok]);
        exit;
    }

    if ($method === 'PUT') {
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'error' => 'ID tidak valid']);
            exit;
        }

        $name = trim((string)($input['nama'] ?? ($input['name'] ?? '')));
        $ip = trim((string)($input['ip'] ?? ($input['ip_address'] ?? '')));
        $location = trim((string)($input['area'] ?? ($input['location'] ?? '')));
        $type = trim((string)($input['type'] ?? 'Router'));
        $description = trim((string)($input['description'] ?? ''));
        $parentId = isset($input['parent_id']) ? (int)$input['parent_id'] : null;

        if ($name === '' || $ip === '') {
            echo json_encode(['success' => false, 'error' => 'Nama dan IP wajib diisi']);
            exit;
        }

        if ($parentId === null) {
            $stmt = $conn->prepare("UPDATE network_devices SET name=?, ip_address=?, `type`=?, location=?, `description`=? WHERE id=? AND user_id=?");
            $stmt->bind_param('sssssii', $name, $ip, $type, $location, $description, $id, $userId);
        } else {
            $stmt = $conn->prepare("UPDATE network_devices SET name=?, ip_address=?, `type`=?, location=?, parent_id=?, `description`=? WHERE id=? AND user_id=?");
            $stmt->bind_param('ssssisii', $name, $ip, $type, $location, $parentId, $description, $id, $userId);
        }

        $ok = $stmt->execute();
        echo json_encode(['success' => (bool)$ok]);
        exit;
    }

    if ($method === 'DELETE') {
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'error' => 'ID tidak valid']);
            exit;
        }

        $stmt = $conn->prepare("DELETE FROM network_devices WHERE id=? AND user_id=?");
        $stmt->bind_param('ii', $id, $userId);
        $ok = $stmt->execute();
        echo json_encode(['success' => (bool)$ok]);
        exit;
    }

    // Auto sync server table into monitoring devices (mirrors web behavior).
    $serverRes = $conn->query("SELECT IP, BRAND, AREA FROM server WHERE user_id = $userId");
    $existing = [];
    $existingRes = $conn->query("SELECT ip_address FROM network_devices WHERE user_id = $userId");
    while ($existingRes && ($r = $existingRes->fetch_assoc())) {
        $existing[trim((string)$r['ip_address'])] = true;
    }

    if ($serverRes) {
        while ($srv = $serverRes->fetch_assoc()) {
            $ip = trim((string)($srv['IP'] ?? ''));
            if ($ip === '') continue;
            $ipEsc = $conn->real_escape_string($ip);
            if (!isset($existing[$ip])) {
                $name = $conn->real_escape_string((string)($srv['BRAND'] ?? 'Router'));
                $loc = $conn->real_escape_string((string)($srv['AREA'] ?? ''));
                $conn->query("INSERT INTO network_devices (user_id, name, ip_address, type, location, parent_id, description) VALUES ($userId, '$name', '$ipEsc', 'Router', '$loc', 0, 'Auto monitoring')");
                $existing[$ip] = true;
            } else {
                $conn->query("UPDATE network_devices SET parent_id=0 WHERE user_id=$userId AND ip_address='$ipEsc'");
            }
        }
    }

    $historyFile = __DIR__ . '/../history-last-online.txt';
    $lastOnline = [];
    if (is_file($historyFile)) {
        $tmp = json_decode((string)file_get_contents($historyFile), true);
        if (is_array($tmp)) $lastOnline = $tmp;
    }

    $devices = [];
    $res = $conn->query("SELECT id, name, ip_address, `type`, location, parent_id, `description` FROM network_devices WHERE user_id = $userId ORDER BY id DESC LIMIT 300");

    $onlineCount = 0;
    $offlineCount = 0;

    while ($res && ($row = $res->fetch_assoc())) {
        $ipInput = trim((string)($row['ip_address'] ?? ''));
        $latency = null;
        $isOnline = false;
        $pemilik = '';
        $serverPassword = '';

        $serverLookupIp = $ipInput;
        if (strpos($serverLookupIp, ':') !== false) {
            $serverLookupIp = explode(':', $serverLookupIp, 2)[0];
        }

        $stmtSrv = $conn->prepare("SELECT PEMILIK, PASSWORD FROM server WHERE user_id = ? AND IP = ? LIMIT 1");
        if ($stmtSrv) {
            $stmtSrv->bind_param('is', $userId, $serverLookupIp);
            $stmtSrv->execute();
            $resSrv = $stmtSrv->get_result();
            if ($resSrv && $resSrv->num_rows > 0) {
                $srv = $resSrv->fetch_assoc();
                $pemilik = (string)($srv['PEMILIK'] ?? '');
                $serverPassword = (string)($srv['PASSWORD'] ?? '');
            }
        }

        $ip = $ipInput;
        $port = null;
        if (strpos($ipInput, ':') !== false) {
            $parts = explode(':', $ipInput, 2);
            $ip = preg_replace('/[^0-9a-fA-F\.:]/', '', $parts[0]);
            $port = (int)preg_replace('/[^0-9]/', '', $parts[1]);
        } else {
            $ip = preg_replace('/[^0-9a-fA-F\.:]/', '', $ipInput);
        }

        if ($ip !== '') {
            if ($port) {
                $start = microtime(true);
                $fp = @fsockopen($ip, $port, $errno, $errstr, 1.2);
                if ($fp) {
                    $latency = round((microtime(true) - $start) * 1000, 2);
                    fclose($fp);
                    $isOnline = true;
                }
            } else {
                if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
                    $out = shell_exec("ping -n 1 -w 1000 " . escapeshellarg($ip));
                    $isOnline = (stripos((string)$out, 'TTL=') !== false);
                    if (preg_match('/Average = ([0-9]+)ms/i', (string)$out, $m)) {
                        $latency = (float)$m[1];
                    } elseif (preg_match('/time[=<]([0-9]+)ms/i', (string)$out, $m)) {
                        $latency = (float)$m[1];
                    }
                } else {
                    $out = shell_exec("ping -c 1 -W 1 " . escapeshellarg($ip));
                    $isOnline = (stripos((string)$out, 'ttl=') !== false);
                    if (preg_match('/time=([0-9.]+) ms/', (string)$out, $m)) {
                        $latency = (float)$m[1];
                    }
                }
            }
        }

        if ($isOnline) {
            $onlineCount++;
            $lastOnline[$ipInput] = date('Y-m-d H:i:s');
        } else {
            $offlineCount++;
        }

        $devices[] = [
            'id' => (string)$row['id'],
            'name' => (string)($row['name'] ?? ''),
            'ip' => $ipInput,
            'type' => (string)($row['type'] ?? ''),
            'location' => (string)($row['location'] ?? ''),
            'area' => (string)($row['location'] ?? ''),
            'parent_id' => isset($row['parent_id']) ? (string)$row['parent_id'] : '',
            'description' => (string)($row['description'] ?? ''),
            'pemilik' => $pemilik,
            'password' => $serverPassword,
            'status' => $isOnline ? 'ONLINE' : 'OFFLINE',
            'latency_ms' => $latency,
            'last_online' => $isOnline ? '-' : ((string)($lastOnline[$ipInput] ?? 'Never'))
        ];
    }

    @file_put_contents($historyFile, json_encode($lastOnline, JSON_PRETTY_PRINT));

    echo json_encode([
        'success' => true,
        'summary' => [
            'total' => count($devices),
            'online' => $onlineCount,
            'offline' => $offlineCount
        ],
        'devices' => $devices
    ]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
