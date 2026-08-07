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
    $stmt = $conn->prepare('SELECT id, USERNAME, PASWORD FROM user WHERE USERNAME=? LIMIT 1');
    if (!$stmt) return null;
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

function sleepAman($min = 2, $max = 3)
{
    $delay = rand($min * 1000, $max * 1000);
    usleep($delay * 1000);
}

try {
    $username = trim((string)($input['username'] ?? ($_POST['username'] ?? $_GET['username'] ?? '')));
    $password = trim((string)($input['password'] ?? ($_POST['password'] ?? $_GET['password'] ?? '')));
    $pesan = trim((string)($input['pesan_broadcast'] ?? ($_POST['pesan_broadcast'] ?? '')));
    $bot = trim((string)($input['bot_broadcast'] ?? ($_POST['bot_broadcast'] ?? '')));
    $filter_bulan = trim((string)($input['filter_bulan'] ?? ($_POST['filter_bulan'] ?? 'all')));
    $filter_tahun = trim((string)($input['filter_tahun'] ?? ($_POST['filter_tahun'] ?? 'all')));

    $auth = auth_user_mobile($conn, $username, $password);
    if (!$auth) {
        echo json_encode(['success' => false, 'error' => 'Autentikasi gagal']);
        exit;
    }
    if ($pesan === '' || $bot === '') {
        echo json_encode(['success' => false, 'error' => 'Pesan dan bot harus diisi']);
        exit;
    }

    $stmt = $conn->prepare('SELECT addressbot, password, sender FROM botwa WHERE namebot = ? AND pemilik = ? LIMIT 1');
    $stmt->bind_param('ss', $bot, $auth['username']);
    $stmt->execute();
    $result = $stmt->get_result();
    if (!$result || $result->num_rows === 0) {
        echo json_encode(['success' => false, 'error' => 'Bot tidak ditemukan']);
        exit;
    }
    $botRow = $result->fetch_assoc();
    $waapi = (string)($botRow['addressbot'] ?? '');
    $botpass = (string)($botRow['password'] ?? '');
    // device_id gowa multi-device (build gowa terbaru wajib X-Device-Id header /
    // device_id query di /send/message) = isi kolom sender apa adanya.
    $deviceId = trim((string)($botRow['sender'] ?? ''));

    $current_user_id = (int)($auth['id'] ?? 0);
    $queryServerId = mysqli_query($conn, "SELECT PEMILIK FROM server WHERE user_id = $current_user_id");
    $userServerIds = [];
    while ($queryServerId && ($row = mysqli_fetch_assoc($queryServerId))) {
        $userServerIds[] = "'" . mysqli_real_escape_string($conn, $row['PEMILIK']) . "'";
    }
    $userServerList = count($userServerIds) > 0 ? implode(',', array_unique($userServerIds)) : "''";

    $where = [];
    if ($filter_bulan !== '' && strtolower($filter_bulan) !== 'all') {
        $where[] = "MONTH(tanggal_berhenti) = '" . intval($filter_bulan) . "'";
    }
    if ($filter_tahun !== '' && strtolower($filter_tahun) !== 'all') {
        $where[] = "YEAR(tanggal_berhenti) = '" . intval($filter_tahun) . "'";
    }
    $where[] = "pemilik IN ($userServerList)";
    $where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $sql = "SELECT idpel, nama, paket, harga, alamat, nowa, alasan, tanggal_berhenti, keterangan, pemilik FROM pelanggan_berhenti $where_sql AND nowa IS NOT NULL AND nowa != ''";
    $result = mysqli_query($conn, $sql);

    $numbers = [];
    $customer_data = [];
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $nowa = trim((string)($row['nowa'] ?? ''));
        if ($nowa !== '') {
            $numbers[] = $nowa;
            $customer_data[$nowa] = $row;
        }
    }
    $numbers = array_values(array_unique($numbers));

    $success_count = 0;
    $fail_count = 0;
    $success_list = [];
    $fail_list = [];

    foreach ($numbers as $no) {
        $customer = $customer_data[$no];
        $personalized_message = $pesan . "\n\n";
        $personalized_message .= "Data Pelanggan:\n";
        $personalized_message .= "IDPEL: {$customer['idpel']}\n";
        $personalized_message .= "Nama: {$customer['nama']}\n";
        $personalized_message .= "Paket: {$customer['paket']}\n";
        $personalized_message .= "Server: {$customer['pemilik']}\n";
        $personalized_message .= "Alamat: {$customer['alamat']}\n";
        $personalized_message .= "Alasan Berhenti: {$customer['alasan']}\n";
        $personalized_message .= "Tanggal Berhenti: {$customer['tanggal_berhenti']}\n";
        if (!empty($customer['keterangan'])) {
            $personalized_message .= "Keterangan: {$customer['keterangan']}\n";
        }

        $phone = "$no@s.whatsapp.net";
        $data = [
            'phone' => $phone,
            'message' => $personalized_message
        ];

        $url = "$waapi/send/message?session=$bot";
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
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_USERPWD, "$bot:$botpass");

        $response = curl_exec($ch);
        curl_close($ch);

        if ($response !== '') {
            $json = json_decode($response, true);
            if (is_array($json) && (($json['code'] ?? '') === 'SUCCESS')) {
                $success_count++;
                $success_list[] = $customer['nama'] . " ({$no})";
            } else {
                $fail_count++;
                $fail_list[] = $customer['nama'] . " ({$no}) - " . ($json['message'] ?? 'Unknown error');
            }
        } else {
            $fail_count++;
            $fail_list[] = $customer['nama'] . " ({$no}) - No response";
        }

        sleepAman(2, 3);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Broadcast selesai',
        'total_numbers' => count($numbers),
        'success_count' => $success_count,
        'fail_count' => $fail_count,
        'success_list' => $success_list,
        'fail_list' => $fail_list
    ]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
