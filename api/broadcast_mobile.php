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

function auth_broadcast_user($conn, $username, $password) {
    $stmt = $conn->prepare('SELECT USERNAME, PASWORD, ID FROM user WHERE USERNAME=? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        if (password_verify($password, (string)$row['PASWORD']) || (string)$password === (string)$row['PASWORD']) {
            return [
                'username' => (string)$row['USERNAME'],
                'id' => (int)($row['ID'] ?? 0)
            ];
        }
    }
    return null;
}

function wa_phone_format($raw) {
    $digits = preg_replace('/\D+/', '', (string)$raw);
    if ($digits === '') {
        return '';
    }
    if (strpos($digits, '0') === 0) {
        $digits = '62' . substr($digits, 1);
    } elseif (strpos($digits, '62') !== 0) {
        $digits = '62' . $digits;
    }
    return $digits . '@s.whatsapp.net';
}

// Jeda aman antar pesan broadcast supaya nomor bot tidak dianggap spam oleh
// WhatsApp -- pola sama persis dgn proses/notif_gangguan.php dkk.
function sleepAman($min = 2, $max = 5) {
    $delayMs = rand($min * 1000, $max * 1000);
    usleep($delayMs * 1000);
}

function applySafeBroadcastDelay($index) {
    sleepAman(2, 5);
    if ($index > 0 && $index % 15 === 0) {
        usleep(rand(3000, 8000) * 1000);
    }
    if ($index > 0 && $index % 50 === 0) {
        usleep(rand(8000, 15000) * 1000);
    }
}

function send_wa_text($address, $botName, $botPass, $phone, $message, $sender = '') {
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'http_code' => 0, 'error' => 'cURL tidak tersedia'];
    }

    $payload = [
        'phone' => $phone,
        'message' => $message
    ];

    // device_id gowa multi-device (build gowa terbaru wajib X-Device-Id header /
    // device_id query di /send/message) = isi kolom sender apa adanya.
    $deviceId = trim((string)$sender);

    $url = rtrim((string)$address, '/') . '/send/message?session=' . urlencode((string)$botName);
    if ($deviceId !== '') {
        $url .= '&device_id=' . urlencode($deviceId);
    }
    $headers = ['Content-Type: application/json'];
    if ($deviceId !== '') {
        $headers[] = "X-Device-Id: $deviceId";
    }
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_USERPWD, (string)$botName . ':' . (string)$botPass);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    $ok = ($err === '' && $http >= 200 && $http < 300);
    return [
        'ok' => $ok,
        'http_code' => $http,
        'error' => $err,
        'response' => (string)$resp
    ];
}

try {
    if (!$conn) {
        echo json_encode(['success' => false, 'error' => 'Koneksi DB gagal']);
        exit;
    }

    $username = (string)($input['username'] ?? ($_GET['username'] ?? ''));
    $password = (string)($input['password'] ?? ($_GET['password'] ?? ''));
    $action = strtolower(trim((string)($input['action'] ?? ($_GET['action'] ?? 'init'))));

    $auth = auth_broadcast_user($conn, $username, $password);
    if (!$auth) {
        echo json_encode(['success' => false, 'error' => 'Autentikasi gagal']);
        exit;
    }

    $pemilik = $auth['username'];
    $userId = (int)$auth['id'];

    if ($method === 'GET' && $action === 'init') {
        $bots = [];
        $stmtBot = $conn->prepare('SELECT DISTINCT namebot FROM botwa WHERE pemilik=? ORDER BY namebot ASC');
        $stmtBot->bind_param('s', $pemilik);
        $stmtBot->execute();
        $resBot = $stmtBot->get_result();
        while ($resBot && ($row = $resBot->fetch_assoc())) {
            $name = trim((string)($row['namebot'] ?? ''));
            if ($name !== '') {
                $bots[] = $name;
            }
        }

        $products = [];
        $stmtSrv = $conn->prepare('SELECT DISTINCT PEMILIK, BRAND, AREA FROM server WHERE user_id=? OR PEMILIK=? ORDER BY AREA ASC, BRAND ASC');
        $stmtSrv->bind_param('is', $userId, $pemilik);
        $stmtSrv->execute();
        $resSrv = $stmtSrv->get_result();
        while ($resSrv && ($row = $resSrv->fetch_assoc())) {
            $products[] = [
                'pemilik' => (string)($row['PEMILIK'] ?? ''),
                'brand' => (string)($row['BRAND'] ?? ''),
                'area' => (string)($row['AREA'] ?? '')
            ];
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'bots' => $bots,
                'products' => $products,
                'modes' => ['info', 'selesai', 'tagihan', 'pesan']
            ]
        ]);
        exit;
    }

    if ($method === 'GET' && $action === 'odp') {
        $server = trim((string)($_GET['server'] ?? ''));
        $area = trim((string)($_GET['area'] ?? ''));

        if ($server === '' || $area === '') {
            echo json_encode(['success' => false, 'error' => 'server dan area wajib diisi']);
            exit;
        }

        $options = [];
        $stmt = $conn->prepare('SELECT KODE, NAME FROM odp WHERE PEMILIK=? AND AREA=? ORDER BY KODE ASC');
        $stmt->bind_param('ss', $server, $area);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $options[] = [
                'kode' => (string)($row['KODE'] ?? ''),
                'name' => (string)($row['NAME'] ?? '')
            ];
        }
        $options[] = ['kode' => 'SEMUA', 'name' => 'ALL ODP'];

        echo json_encode(['success' => true, 'data' => ['odp' => $options]]);
        exit;
    }

    if ($method === 'POST' && $action === 'send') {
        $botname = trim((string)($input['botname'] ?? ''));
        $mode = strtolower(trim((string)($input['mode'] ?? 'info')));
        $server = trim((string)($input['server'] ?? ''));
        $area = trim((string)($input['area'] ?? ''));
        $odp = trim((string)($input['odp'] ?? ''));
        $pesanManual = trim((string)($input['pesan'] ?? ''));

        if ($botname === '' || $server === '' || $area === '' || $odp === '') {
            echo json_encode(['success' => false, 'error' => 'botname, server, area, odp wajib diisi']);
            exit;
        }
        if (!in_array($mode, ['info', 'selesai', 'tagihan', 'pesan'], true)) {
            echo json_encode(['success' => false, 'error' => 'mode tidak valid']);
            exit;
        }
        if ($mode === 'pesan' && $pesanManual === '') {
            echo json_encode(['success' => false, 'error' => 'pesan manual wajib diisi']);
            exit;
        }

        $botPool = [];
        if ($botname === '__RANDOM__') {
            $stmtBot = $conn->prepare('SELECT namebot, addressbot, password, sender FROM botwa WHERE pemilik=?');
            $stmtBot->bind_param('s', $pemilik);
            $stmtBot->execute();
            $resBot = $stmtBot->get_result();
            while ($resBot && ($row = $resBot->fetch_assoc())) {
                $name = trim((string)($row['namebot'] ?? ''));
                $addr = trim((string)($row['addressbot'] ?? ''));
                $pass = trim((string)($row['password'] ?? ''));
                $sender = trim((string)($row['sender'] ?? ''));
                if ($name !== '' && $addr !== '' && $pass !== '') {
                    $botPool[] = ['name' => $name, 'addr' => $addr, 'pass' => $pass, 'sender' => $sender];
                }
            }
            if (count($botPool) > 1) {
                shuffle($botPool);
            }
        } else {
            $stmtBot = $conn->prepare('SELECT namebot, addressbot, password, sender FROM botwa WHERE pemilik=? AND namebot=? LIMIT 1');
            $stmtBot->bind_param('ss', $pemilik, $botname);
            $stmtBot->execute();
            $resBot = $stmtBot->get_result();
            $row = $resBot ? $resBot->fetch_assoc() : null;
            if ($row) {
                $botPool[] = [
                    'name' => trim((string)($row['namebot'] ?? '')),
                    'addr' => trim((string)($row['addressbot'] ?? '')),
                    'pass' => trim((string)($row['password'] ?? '')),
                    'sender' => trim((string)($row['sender'] ?? ''))
                ];
            }
        }

        if (empty($botPool)) {
            echo json_encode(['success' => false, 'error' => 'Bot tidak ditemukan']);
            exit;
        }

        if (strtoupper($odp) === 'SEMUA') {
            $stmtTarget = $conn->prepare('SELECT IDPEL, NAMA, NOWA FROM pelanggan WHERE PEMILIK=? AND AREA=?');
            $stmtTarget->bind_param('ss', $server, $area);
        } else {
            $stmtTarget = $conn->prepare('SELECT IDPEL, NAMA, NOWA FROM pelanggan WHERE PEMILIK=? AND AREA=? AND ODP=?');
            $stmtTarget->bind_param('sss', $server, $area, $odp);
        }
        $stmtTarget->execute();
        $resTarget = $stmtTarget->get_result();
        $targets = [];
        while ($resTarget && ($row = $resTarget->fetch_assoc())) {
            $targets[] = $row;
        }

        if (empty($targets)) {
            echo json_encode([
                'success' => true,
                'summary' => ['total_target' => 0, 'success_count' => 0, 'failed_count' => 0],
                'data' => []
            ]);
            exit;
        }

        if ($botname === '__RANDOM__' && count($targets) > 1) {
            shuffle($targets);
        }

        $buildText = function($modeValue, $nama, $manual) {
            $safeNama = trim((string)$nama);
            if ($safeNama === '') {
                $safeNama = 'Pelanggan';
            }
            if ($modeValue === 'pesan') {
                return "Halo Bapak/Ibu $safeNama,\n\n" . trim((string)$manual);
            }
            if ($modeValue === 'selesai') {
                return "Halo Bapak/Ibu $safeNama,\n\nInformasi: gangguan jaringan telah selesai ditangani. Silakan cek kembali layanan Anda.";
            }
            if ($modeValue === 'tagihan') {
                return "Halo Bapak/Ibu $safeNama,\n\nPengingat tagihan layanan internet. Mohon lakukan pembayaran agar layanan tetap aktif.";
            }
            return "Halo Bapak/Ibu $safeNama,\n\nInformasi: saat ini sedang terjadi gangguan jaringan di area Anda. Tim kami sedang melakukan penanganan.";
        };

        $sentRows = [];
        $successCount = 0;
        $failedCount = 0;

        foreach ($targets as $idx => $target) {
            $botCfg = $botPool[$idx % count($botPool)];
            $idpel = (string)($target['IDPEL'] ?? '');
            $nama = (string)($target['NAMA'] ?? '');
            $nowa = (string)($target['NOWA'] ?? '');
            $phone = wa_phone_format($nowa);
            if ($phone === '') {
                $failedCount++;
                $sentRows[] = [
                    'idpel' => $idpel,
                    'nama' => $nama,
                    'wa_ok' => false,
                    'status_text' => 'Nomor tidak valid'
                ];
                continue;
            }

            $message = $buildText($mode, $nama, $pesanManual);
            $send = send_wa_text($botCfg['addr'], $botCfg['name'], $botCfg['pass'], $phone, $message, $botCfg['sender'] ?? '');
            if ($send['ok']) {
                $successCount++;
            } else {
                $failedCount++;
            }

            $sentRows[] = [
                'idpel' => $idpel,
                'nama' => $nama,
                'wa_ok' => (bool)$send['ok'],
                'status_text' => $send['ok'] ? 'Terkirim' : 'Gagal',
                'http_code' => (int)$send['http_code']
            ];

            applySafeBroadcastDelay($idx + 1);
        }

        echo json_encode([
            'success' => true,
            'summary' => [
                'total_target' => count($targets),
                'success_count' => $successCount,
                'failed_count' => $failedCount
            ],
            'data' => $sentRows
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Action tidak didukung']);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
