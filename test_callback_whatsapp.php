<?php
/**
 * TEST FILE untuk WhatsApp Callback TRIPAY
 * File ini untuk debug dan testing WhatsApp notification
 * 
 * JANGAN GUNAKAN DI PRODUCTION!
 * 
 * Usage:
 * 1. Buka di browser: http://yourserver/crm/billing/test_callback_whatsapp.php
 * 2. Lihat response dan check log
 */

// ============ CONFIGURATION ============
$owner = 'FIBERQ'; // Sesuaikan dengan owner yang mau di-test
$testMode = true;  // Set false untuk real send (JANGAN LUPA DISABLE!)

// ============ INCLUDES ============
require './koneksidb.php';
require './routeros_api.class.php';
require "./notifbot/phpmailer/classes/class.phpmailer.php";
require_once './notifbot/bot_selector_helper.php';

// ============ SETUP ============
header('Content-Type: application/json; charset=utf-8');
$results = [];

// ============ STEP 1: CEK REMINDER JSON ============
$results['step1_check_json'] = [];
$jsonFile = "./notifbot/data/reminder-$owner.json";

if (file_exists($jsonFile)) {
    $results['step1_check_json']['status'] = 'PASS';
    $results['step1_check_json']['file'] = $jsonFile;
    $jsonData = file_get_contents($jsonFile);
    $data = json_decode($jsonData, true);
    
    if ($data !== null && is_array($data)) {
        $results['step1_check_json']['valid_json'] = true;
        $item = $data[0] ?? [];
        $botname = $item['botname'] ?? null;
        $results['step1_check_json']['botname'] = $botname;
        $results['step1_check_json']['all_keys'] = array_keys($item);
    } else {
        $results['step1_check_json']['valid_json'] = false;
        $results['step1_check_json']['error'] = 'Invalid JSON format';
    }
} else {
    $results['step1_check_json']['status'] = 'FAIL';
    $results['step1_check_json']['error'] = "File not found: $jsonFile";
}

// ============ STEP 2: CEK DATABASE BOTWA ============
$results['step2_check_db'] = [];

if (!empty($botname)) {
    $sql = "SELECT * FROM `botwa` WHERE `namebot` = '$botname'";
    $query = mysqli_query($conn, $sql);
    
    if ($query && mysqli_num_rows($query) > 0) {
        $results['step2_check_db']['status'] = 'PASS';
        $bot_data = mysqli_fetch_array($query);
        $results['step2_check_db']['bot_info'] = [
            'namebot' => $bot_data['namebot'],
            'addressbot' => $bot_data['addressbot'],
            'password' => '***HIDDEN***',
            'status' => $bot_data['status'] ?? 'unknown'
        ];
        
        $waapi = $bot_data['addressbot'];
        $botpass = $bot_data['password'];
        $results['step2_check_db']['waapi_set'] = !empty($waapi);
        $results['step2_check_db']['botpass_set'] = !empty($botpass);
    } else {
        $results['step2_check_db']['status'] = 'FAIL';
        $results['step2_check_db']['error'] = "Bot '$botname' not found in database";
    }
} else {
    $results['step2_check_db']['status'] = 'FAIL';
    $results['step2_check_db']['error'] = 'Bot name not available from JSON';
}

// ============ STEP 3: TEST cURL CONNECTION ============
$results['step3_curl_test'] = [];

if (!empty($waapi) && !empty($botpass) && !empty($botname)) {
    $testPhone = "6287838767626@s.whatsapp.net";
    $testMessage = "Test message dari WhatsApp Callback Tester - Waktu: " . date('Y-m-d H:i:s');
    
    $data = [
        "phone" => $testPhone,
        "message" => $testMessage
    ];
    
    $url = "$waapi/send/message?session=$botname";
    $results['step3_curl_test']['url'] = $url;
    $results['step3_curl_test']['session'] = $botname;
    
    if ($testMode) {
        $results['step3_curl_test']['mode'] = 'TEST MODE (not sending)';
        $results['step3_curl_test']['payload'] = $data;
    } else {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_USERPWD, "$botname:$botpass");
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        $results['step3_curl_test']['status'] = $curlError ? 'FAIL' : 'PASS';
        $results['step3_curl_test']['http_code'] = $httpCode;
        $results['step3_curl_test']['curl_error'] = $curlError ?: 'none';
        $results['step3_curl_test']['response'] = $response;
    }
} else {
    $results['step3_curl_test']['status'] = 'SKIP';
    $results['step3_curl_test']['reason'] = 'Missing waapi or botpass from previous steps';
}

// ============ STEP 4: CEK HISTORY FILE ============
$results['step4_history_file'] = [];
$historyFile = "./notifbot/data/history-$owner.json";

if (file_exists($historyFile)) {
    $results['step4_history_file']['status'] = 'EXISTS';
    $history = json_decode(file_get_contents($historyFile), true);
    
    if (is_array($history)) {
        $results['step4_history_file']['valid_json'] = true;
        $results['step4_history_file']['total_entries'] = count($history);
        $results['step4_history_file']['latest_5'] = array_slice($history, -5);
    } else {
        $results['step4_history_file']['valid_json'] = false;
    }
} else {
    $results['step4_history_file']['status'] = 'NOT FOUND';
    $results['step4_history_file']['path'] = $historyFile;
}

// ============ STEP 5: TEST SEND MESSAGE ============
$results['step5_test_send'] = [];

// Sample pelanggan data untuk test
$testCustomer = [
    'IDPEL' => 'FQ-QUENBYQIERA',
    'NAMA' => 'Test Customer WhatsApp Callback',
    'PAKET' => 'Unlimited 50Mbps',
    'NOWA' => '687838767626',
    'EMAIL' => 'test@example.com',
    'ALAMAT' => 'Test Address',
    'price' => 250000,
    'invoiceref' => 'INV-' . date('YmdHis'),
    'payment_method' => 'Bank Transfer',
    'payment_method_code' => 'BCA'
];

$period = date('F Y', strtotime('-1 month'));
$tempat_bayar = tanggal_indo2(date('Y-m-d'), true);

$messageText = "[INI ADALAH PESAN OTOMATIS - TEST]\n*PEMBAYARAN BERHASIL*\n\n";
$messageText .= "Hai bpk/ibu " . $testCustomer['NAMA'] . "\n";
$messageText .= "Pembayaran anda Telah kami terima.\n\n";
$messageText .= "Dengan detail :\n";
$messageText .= "- ID Pelanggan : " . $testCustomer['IDPEL'] . "\n";
$messageText .= "- Nama Pelanggan : " . $testCustomer['NAMA'] . "\n";
$messageText .= "- Paket : " . $testCustomer['PAKET'] . "\n";
$messageText .= "- No WA : " . $testCustomer['NOWA'] . "\n";
$messageText .= "- Email : " . $testCustomer['EMAIL'] . "\n";
$messageText .= "- Alamat : " . $testCustomer['ALAMAT'] . "\n\n";
$messageText .= "Data transaksi :\n";
$messageText .= "- Periode : $period\n";
$messageText .= "- Tanggal Bayar : $tempat_bayar\n";
$messageText .= "- Status : AKTIF\n";
$messageText .= "- Nominal : Rp " . number_format($testCustomer['price'], 0, ',', '.') . "\n";
$messageText .= "- Ref : " . $testCustomer['invoiceref'] . "\n";
$messageText .= "- Metode : " . $testCustomer['payment_method'] . "\n";
$messageText .= "- Kode Metode : " . $testCustomer['payment_method_code'] . "\n\n";
$messageText .= "*** INI ADALAH TEST MESSAGE - ABAIKAN ***\n";

$results['step5_test_send']['message'] = $messageText;
$results['step5_test_send']['customer'] = $testCustomer;

if (!$testMode && !empty($waapi) && !empty($botpass) && !empty($botname)) {
    $phone = $testCustomer['NOWA'] . "@s.whatsapp.net";
    $data = [
        "phone" => $phone,
        "message" => $messageText
    ];
    
    $url = "$waapi/send/message?session=$botname";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_USERPWD, "$botname:$botpass");
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    $results['step5_test_send']['status'] = $curlError ? 'FAIL' : ($httpCode === 200 ? 'PASS' : 'FAIL');
    $results['step5_test_send']['http_code'] = $httpCode;
    $results['step5_test_send']['curl_error'] = $curlError ?: 'none';
    $results['step5_test_send']['response'] = substr($response, 0, 500); // Potong response panjang
} else {
    $results['step5_test_send']['status'] = $testMode ? 'TEST MODE' : 'SKIP';
}

// ============ SUMMARY ============
$results['summary'] = [
    'timestamp' => date('Y-m-d H:i:s'),
    'owner' => $owner,
    'test_mode' => $testMode,
    'rekomendasi' => [
        'Jika step1 FAIL: Buat file reminder-' . $owner . '.json di ./notifbot/data/',
        'Jika step2 FAIL: Pastikan bot "' . $botname . '" sudah terdaftar di database botwa',
        'Jika step3 FAIL: Check wadapi URL dan credentials',
        'Jika step5 FAIL: Check network connectivity ke WhatsApp API',
        'Set $testMode = false untuk test Send dengan data real (HATI-HATI!)'
    ]
];

// ============ OUTPUT ============
echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

// ============ HELPERS ============
function tanggal_indo2($tanggal, $cetak_hari = false)
{
    $hari = array(1 => 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu');
    $bulan = array(1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember');

    $split = explode('-', $tanggal);
    $tanggal_formatted = $split[2] . ' ' . $bulan[(int)$split[1]] . ' ' . $split[0];

    if ($cetak_hari) {
        $num_hari = date('N', strtotime($tanggal));
        return $hari[$num_hari] . ', ' . $tanggal_formatted;
    }

    return $tanggal_formatted;
}
?>
