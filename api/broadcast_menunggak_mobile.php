<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../koneksibilling.php';
session_start();

function out_json($success, $message, $extra = [], $code = 200)
{
    http_response_code($code);
    echo json_encode(array_merge([
        'success' => (bool)$success,
        'message' => (string)$message,
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function auth_user_mobile($conn, $username, $password)
{
    $stmt = $conn->prepare('SELECT id, USERNAME, PASWORD FROM user WHERE USERNAME=? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        if (password_verify($password, (string)$row['PASWORD']) || (string)$password === (string)$row['PASWORD']) {
            return ['id' => (int)($row['id'] ?? 0), 'username' => (string)($row['USERNAME'] ?? '')];
        }
    }
    return null;
}

function parse_idpel_list($raw)
{
    $parts = preg_split('/[,;\r\n]+/', (string)$raw);
    $ids = [];
    if (!is_array($parts)) {
        return $ids;
    }
    foreach ($parts as $item) {
        $item = trim((string)$item);
        if ($item === '') {
            continue;
        }
        $item = preg_replace('/[\x00-\x1F\x7F]/u', '', $item);
        if ($item !== '') {
            $ids[$item] = true;
        }
    }
    return array_keys($ids);
}

function get_allowed_owners($conn, $username, $userId)
{
    $owners = [];
    $stmt = $conn->prepare('SELECT DISTINCT PEMILIK FROM server WHERE user_id=? OR PEMILIK=?');
    if ($stmt) {
        $stmt->bind_param('is', $userId, $username);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            if (!empty($row['PEMILIK'])) {
                $owners[] = (string)$row['PEMILIK'];
            }
        }
        $stmt->close();
    }
    if (empty($owners)) {
        $owners[] = $username;
    }
    return array_values(array_unique(array_filter($owners, static fn($v) => trim((string)$v) !== '')));
}

function sleep_aman($min = 2, $max = 3)
{
    $delay = rand($min * 1000, $max * 1000);
    usleep($delay * 1000);
}

try {
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }

    $username = trim((string)($input['username'] ?? ($_POST['username'] ?? $_GET['username'] ?? '')));
    $password = trim((string)($input['password'] ?? ($_POST['password'] ?? $_GET['password'] ?? '')));
    $botname = trim((string)($input['botname'] ?? ($_POST['botname'] ?? $_GET['botname'] ?? '')));
    $pesan = trim((string)($input['pesan_broadcast'] ?? ($input['pesan'] ?? ($_POST['pesan_broadcast'] ?? $_POST['pesan'] ?? ''))));
    $idpelRaw = trim((string)($input['idpel_list'] ?? ($_POST['idpel_list'] ?? $_GET['idpel_list'] ?? '')));

    if ($username === '' || $password === '') {
        out_json(false, 'Autentikasi gagal');
    }
    $auth = auth_user_mobile($conn, $username, $password);
    if (!$auth) {
        out_json(false, 'Autentikasi gagal');
    }
    if ($botname === '' || $pesan === '' || $idpelRaw === '') {
        out_json(false, 'Bot, pesan, dan daftar pelanggan wajib diisi');
    }

    $idpelList = parse_idpel_list($idpelRaw);
    if (count($idpelList) === 0) {
        out_json(false, 'Daftar pelanggan kosong');
    }

    $allowedOwners = get_allowed_owners($conn, $auth['username'], (int)$auth['id']);
    $ownerEscaped = [];
    foreach ($allowedOwners as $owner) {
        $ownerEscaped[] = "'" . mysqli_real_escape_string($conn, $owner) . "'";
    }
    $ownerWhere = count($ownerEscaped) > 0 ? (' AND PEMILIK IN (' . implode(',', $ownerEscaped) . ')') : '';

    $idEscaped = [];
    foreach ($idpelList as $idpel) {
        $idEscaped[] = "'" . mysqli_real_escape_string($conn, $idpel) . "'";
    }

    $sql = "SELECT IDPEL, NAMA, PAKET, AREA, PEMILIK, NOWA, HARGA, ALAMAT, TANGGALPASANG, TIPE_BAYAR, TIPE_TEMPO, last_paid FROM (SELECT p.IDPEL, p.NAMA, p.PAKET, p.AREA, p.PEMILIK, p.NOWA, p.HARGA, p.ALAMAT, p.TANGGALPASANG, p.TIPE_BAYAR, p.TIPE_TEMPO, (SELECT MAX(t.TANGGALBAYAR) FROM transaksi t WHERE t.IDPEL = p.IDPEL AND t.STATUS='BERHASIL') AS last_paid FROM pelanggan p WHERE TRIM(p.IDPEL) IN (" . implode(',', $idEscaped) . ")" . $ownerWhere . ") x WHERE NOWA IS NOT NULL AND NOWA != ''";
    $result = mysqli_query($conn, $sql);
    if (!$result) {
        out_json(false, 'Gagal mengambil data pelanggan: ' . mysqli_error($conn));
    }

    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $nowa = trim((string)($row['NOWA'] ?? ''));
        if ($nowa !== '') {
            $rows[$nowa] = $row;
        }
    }

    if (count($rows) === 0) {
        out_json(false, 'Tidak ada pelanggan valid untuk broadcast');
    }

    $botPool = [];
    if ($botname === '__RANDOM__') {
        $stmtBot = $conn->prepare('SELECT namebot, addressbot, password, sender FROM botwa WHERE pemilik = ?');
        if ($stmtBot) {
            $stmtBot->bind_param('s', $auth['username']);
            $stmtBot->execute();
            $resBot = $stmtBot->get_result();
            while ($resBot && ($bot = $resBot->fetch_assoc())) {
                $botPool[] = [
                    'namebot' => (string)($bot['namebot'] ?? ''),
                    'addressbot' => (string)($bot['addressbot'] ?? ''),
                    'password' => (string)($bot['password'] ?? ''),
                    'sender' => (string)($bot['sender'] ?? ''),
                ];
            }
            $stmtBot->close();
        }
    } else {
        $stmtBot = $conn->prepare('SELECT namebot, addressbot, password, sender FROM botwa WHERE namebot = ? AND pemilik = ? LIMIT 1');
        if ($stmtBot) {
            $stmtBot->bind_param('ss', $botname, $auth['username']);
            $stmtBot->execute();
            $resBot = $stmtBot->get_result();
            if ($resBot && ($bot = $resBot->fetch_assoc())) {
                $botPool[] = [
                    'namebot' => (string)($bot['namebot'] ?? ''),
                    'addressbot' => (string)($bot['addressbot'] ?? ''),
                    'password' => (string)($bot['password'] ?? ''),
                    'sender' => (string)($bot['sender'] ?? ''),
                ];
            }
            $stmtBot->close();
        }
    }

    if (count($botPool) === 0) {
        out_json(false, 'Bot tidak ditemukan');
    }

    $bot = $botPool[array_rand($botPool)];
    $waapi = (string)($bot['addressbot'] ?? '');
    $botpass = (string)($bot['password'] ?? '');
    if ($waapi === '') {
        out_json(false, 'Alamat bot tidak valid');
    }

    // device_id gowa multi-device (build gowa terbaru wajib X-Device-Id header /
    // device_id query di /send/message) = isi kolom sender apa adanya.
    $deviceId = trim((string)($bot['sender'] ?? ''));

    $successCount = 0;
    $failCount = 0;
    $successList = [];
    $failList = [];

    foreach ($rows as $no => $customer) {
        $phone = preg_replace('/\D+/', '', $no);
        if ($phone === '') {
            $failCount++;
            $failList[] = ($customer['NAMA'] ?? 'Pelanggan') . ' (' . $no . ') - nomor tidak valid';
            continue;
        }
        if (strpos($phone, '0') === 0) {
            $phone = '62' . substr($phone, 1);
        } elseif (strpos($phone, '62') !== 0) {
            $phone = '62' . $phone;
        }

        $personalized = $pesan . "\n\nData Pelanggan:\n";
        $personalized .= 'IDPEL: ' . ($customer['IDPEL'] ?? '') . "\n";
        $personalized .= 'Nama: ' . ($customer['NAMA'] ?? '') . "\n";
        $personalized .= 'Paket: ' . ($customer['PAKET'] ?? '') . "\n";
        $personalized .= 'Server: ' . ($customer['PEMILIK'] ?? '') . "\n";
        $personalized .= 'Area: ' . ($customer['AREA'] ?? '') . "\n";
        $personalized .= 'Harga: ' . ($customer['HARGA'] ?? '') . "\n";
        $personalized .= 'Alamat: ' . ($customer['ALAMAT'] ?? '') . "\n";
        $personalized .= 'Tipe Bayar: ' . ($customer['TIPE_BAYAR'] ?? '') . "\n";
        $personalized .= 'Tipe Tempo: ' . ($customer['TIPE_TEMPO'] ?? '') . "\n";
        if (!empty($customer['last_paid'])) {
            $personalized .= 'Terakhir Bayar: ' . $customer['last_paid'] . "\n";
        }

        $payload = ['phone' => $phone . '@s.whatsapp.net', 'message' => $personalized];
        $sendUrl = $waapi . '/send/message?session=' . rawurlencode($bot['namebot']);
        if ($deviceId !== '') {
            $sendUrl .= '&device_id=' . urlencode($deviceId);
        }
        $sendHeaders = ['Content-Type: application/json'];
        if ($deviceId !== '') {
            $sendHeaders[] = "X-Device-Id: $deviceId";
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $sendUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $sendHeaders);
        curl_setopt($ch, CURLOPT_USERPWD, $bot['namebot'] . ':' . $botpass);
        $response = curl_exec($ch);
        curl_close($ch);

        $json = json_decode((string)$response, true);
        if (is_array($json) && (($json['code'] ?? '') === 'SUCCESS')) {
            $successCount++;
            $successList[] = ($customer['NAMA'] ?? 'Pelanggan') . ' (' . $no . ')';
        } else {
            $failCount++;
            $failList[] = ($customer['NAMA'] ?? 'Pelanggan') . ' (' . $no . ') - ' . ($json['message'] ?? 'No response');
        }

        sleep_aman(2, 3);
    }

    out_json(true, 'Broadcast selesai', [
        'total_numbers' => count($rows),
        'success_count' => $successCount,
        'fail_count' => $failCount,
        'success_list' => $successList,
        'fail_list' => $failList,
    ]);
} catch (Throwable $e) {
    out_json(false, $e->getMessage(), [], 500);
}
