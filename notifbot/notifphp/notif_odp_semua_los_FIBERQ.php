<?php
include '../../koneksidb.php';
require_once('../bot_selector_helper.php');

date_default_timezone_set('Asia/Jakarta');

$filename = basename(__FILE__);
$nameOnly = pathinfo($filename, PATHINFO_FILENAME);
$parts = explode('_', $nameOnly);
$pemilik = end($parts);

if (!$pemilik || $pemilik === 'los') {
    echo "Pemilik tidak valid dari nama file.\n";
    exit;
}

$history_file = "../data/history-$pemilik.json";
$history = [];
if (file_exists($history_file)) {
    $history = json_decode(file_get_contents($history_file), true);
}
if (!is_array($history)) {
    $history = [];
}

$selectedBotSystem = '';
$botCategoryFile = "../data/bot_receiver_config-$pemilik.json";
if (file_exists($botCategoryFile)) {
    $botCategoryData = json_decode(file_get_contents($botCategoryFile), true);
    if (is_array($botCategoryData) && !empty($botCategoryData['odp_los'])) {
        $selectedBotSystem = trim((string)$botCategoryData['odp_los']);
    }
}

// Gunakan helper function untuk pemilihan bot (mendukung RANDOM)
$bot_result = selectBotForNotificationWithField($conn, $pemilik, $selectedBotSystem, 'penerima_odp_los');

if ($bot_result['success']) {
    $waapi = $bot_result['addressbot'];
    $botpass = $bot_result['password'];
    $botname = $bot_result['namebot'];
    $penerima = $bot_result['penerima'];
    $sender = $bot_result['sender'] ?? '';

    if ($bot_result['is_random']) {
        echo "[RANDOM BOT] Bot dipilih secara acak: $botname untuk menghindari spam (ODP LOS)<br>";
    }
} else {
    echo "ERROR: " . $bot_result['message'] . "<br>";
    // Sebelumnya cuma di-echo (kebuang oleh redirect cron `> /dev/null 2>&1`),
    // jadi kegagalan total pemilihan bot tidak pernah tercatat di history.
    $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] GAGAL TOTAL: tidak ada bot WA ditemukan untuk pemilik $pemilik (" . $bot_result['message'] . ") - cron notif ODP LOS dihentikan";
    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    exit;
}

if ($waapi === '' || $botname === '' || $penerima === '') {
    $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Skip notif ODP LOS: konfigurasi bot/penerima belum lengkap";
    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    echo "Konfigurasi bot/penerima ODP LOS belum lengkap.\n";
    exit;
}

$serverlogFile = "../../serverlog/$pemilik.txt";
if (!file_exists($serverlogFile)) {
    $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Skip notif ODP LOS: file serverload tidak ditemukan";
    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    echo "File serverload tidak ditemukan: $serverlogFile\n";
    exit;
}

$serverlogData = json_decode(file_get_contents($serverlogFile), true);
if (!is_array($serverlogData)) {
    $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Skip notif ODP LOS: JSON serverload tidak valid";
    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    echo "JSON serverload tidak valid.\n";
    exit;
}


$odpAllLos = [];
if (isset($serverlogData['odp_all_los']) && is_array($serverlogData['odp_all_los'])) {
    $odpAllLos = $serverlogData['odp_all_los'];
} elseif (isset($serverlogData['odp_summary']) && is_array($serverlogData['odp_summary'])) {
    foreach ($serverlogData['odp_summary'] as $item) {
        $total = (int)($item['TOTAL_PELANGGAN'] ?? 0);
        $online = (int)($item['TOTAL_ONLINE'] ?? 0);
        if ($total > 0 && $online === 0) {
            $odpAllLos[] = $item;
        }
    }
}

$currentKeys = [];
$odpByKey = [];
foreach ($odpAllLos as $item) {
    $odpName = trim((string)($item['ODP'] ?? ''));
    if ($odpName === '') continue;
    $key = strtoupper((string)($item['PEMILIK'] ?? $pemilik) . '||' . (string)($item['AREA'] ?? '') . '||' . $odpName);
    $currentKeys[] = $key;
    $odpByKey[$key] = $item;
}
$currentKeys = array_values(array_unique($currentKeys));

$stateFile = "../data/odp_los_state-$pemilik.json";
$stateData = ['last_keys' => []];
if (file_exists($stateFile)) {
    $tmpState = json_decode(file_get_contents($stateFile), true);
    if (is_array($tmpState)) {
        $stateData = $tmpState;
    }
}
$lastKeys = isset($stateData['last_keys']) && is_array($stateData['last_keys']) ? $stateData['last_keys'] : [];

$newKeys = array_values(array_diff($currentKeys, $lastKeys));
$recoveredKeys = array_values(array_diff($lastKeys, $currentKeys));

function getCustomersInLos($conn, $odp, $area, $pemilik) {
    $sql = "SELECT IDPEL AS id_pelanggan, NAMA AS nama_pelanggan
            FROM pelanggan
            WHERE TRIM(ODP) = TRIM(?)
              AND TRIM(AREA) = TRIM(?)
              AND TRIM(PEMILIK) = TRIM(?)
            ORDER BY IDPEL ASC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('sss', $odp, $area, $pemilik);
    $stmt->execute();
    $result = $stmt->get_result();
    $customers = [];
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $customers[] = $row;
        }
    }

    $stmt->close();
    
    return $customers;
}

function kirimWaNotif($waapi, $botname, $botpass, $phone, $message, $sender = '') {
    $deviceId = trim((string)$sender);
    $url = rtrim($waapi, '/') . '/send/message?session=' . urlencode($botname);
    if ($deviceId !== '') {
        $url .= '&device_id=' . urlencode($deviceId);
    }
    $payload = [
        'phone' => $phone,
        'message' => $message
    ];

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
    curl_setopt($ch, CURLOPT_USERPWD, $botname . ':' . $botpass);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    return [$httpCode, $response, $curlErr];
}

if (count($newKeys) > 0) {
    $jam = date('H:i:s');
    $text = "?? *[ALERT ODP LOS]*\n";
    $text .= "Tanggal: " . date('Y-m-d') . " Pukul $jam\n";
    $text .= "Pemilik: $pemilik\n\n";
    $text .= "ODP berikut semua pelanggannya LOS impact ODP LOS:\n";

    $no = 1;
    foreach ($newKeys as $key) {
        if (!isset($odpByKey[$key])) continue;
        $item = $odpByKey[$key];
        $odpName = (string)($item['ODP'] ?? '-');
        $area = (string)($item['AREA'] ?? '-');
        $total = (int)($item['TOTAL_PELANGGAN'] ?? 0);
        $los = (int)($item['TOTAL_LOS'] ?? $total);
        $pem = (string)($item['PEMILIK'] ?? $pemilik);
        
        $text .= "$no. ODP: $odpName\n";
        $text .= "   Area: $area\n";
        $text .= "   LOS: $los/$total pelanggan\n";
        
        // Get customer details in LOS
        $customers = getCustomersInLos($conn, $odpName, $area, $pem);
        if (!empty($customers)) {
            $text .= "   Pelanggan LOS:\n";
            foreach ($customers as $customer) {
                $idPel = $customer['id_pelanggan'];
                $namaPel = $customer['nama_pelanggan'];
                $text .= "   � $idPel - $namaPel\n";
            }
        }
        
        $text .= "\n---\n";
        $no++;
    }
    
    // Tambahkan salam pembuka dinamis untuk menghindari spam detection
    $text = prependDynamicGreeting($text);

    list($httpCode, $response, $curlErr) = kirimWaNotif($waapi, $botname, $botpass, $penerima, $text, $sender);
    if ($curlErr === '' && $httpCode >= 200 && $httpCode < 300) {
        $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Notif ODP semua LOS dikirim. HTTP: $httpCode";
    } else {
        $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] GAGAL kirim notif ODP semua LOS | HTTP: $httpCode | cURL error: $curlErr | Response: " . substr((string)$response, 0, 200);
    }
}

if (count($recoveredKeys) > 0) {
    $textRecover = "*[INFO ODP KEMBALI NORMAL / AKTIF]*\n";
    $textRecover .= "Tanggal: " . date('Y-m-d H:i:s') . "\n";
    $textRecover .= "ODP berikut sudah AKTIF:\n";
    $no = 1;
    foreach ($recoveredKeys as $key) {
        $parts = explode('||', $key);
        $pem = $parts[0] ?? $pemilik;
        $area = $parts[1] ?? '-';
        $odp = $parts[2] ?? '-';
        $textRecover .= "$no. ODP: $odp (Area: $area, Server: $pem)\n";
        $no++;
    }
    
    // Tambahkan salam pembuka dinamis untuk menghindari spam detection
    $textRecover = prependDynamicGreeting($textRecover);

    list($httpCode2, $response2, $curlErr2) = kirimWaNotif($waapi, $botname, $botpass, $penerima, $textRecover, $sender);
    if ($curlErr2 === '' && $httpCode2 >= 200 && $httpCode2 < 300) {
        $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Notif pemulihan ODP LOS dikirim. HTTP: $httpCode2";
    } else {
        $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] GAGAL kirim notif pemulihan ODP LOS | HTTP: $httpCode2 | cURL error: $curlErr2 | Response: " . substr((string)$response2, 0, 200);
    }
}

if (count($newKeys) === 0 && count($recoveredKeys) === 0) {
    $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] ODP LOS stabil, tidak ada perubahan";
}

$stateData['last_keys'] = $currentKeys;
$stateData['updated_at'] = date('Y-m-d H:i:s');
file_put_contents($stateFile, json_encode($stateData, JSON_PRETTY_PRINT));
file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

echo "Selesai cek notif ODP semua LOS.\n";
