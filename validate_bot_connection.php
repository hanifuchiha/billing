<?php
/**
 * Validation script untuk Error 463 debugging
 * Lokasi: crm/billing/validate_bot_connection.php
 */

header('Content-Type: application/json');

if (!isset($_GET['action'])) {
    echo json_encode(['error' => 'action parameter required']);
    exit;
}

$action = $_GET['action'];

// ============ ACTION 1: Validate Phone Format ============
if ($action === 'validate_phone') {
    $phone = $_GET['phone'] ?? '';
    
    $results = [
        'input' => $phone,
        'tests' => []
    ];
    
    // Test 1: Remove non-digits
    $cleaned = preg_replace('/[^0-9]/', '', $phone);
    $results['tests'][] = [
        'name' => 'Remove non-digits',
        'result' => $cleaned
    ];
    
    // Test 2: Check regex (62 + 8-15 digits)
    $regex_match = preg_match('/^62\d{8,15}$/', $cleaned);
    $results['tests'][] = [
        'name' => 'Regex check (^62\\d{8,15}$)',
        'valid' => $regex_match ? true : false,
        'result' => $cleaned,
        'reason' => $regex_match 
            ? 'Format valid' 
            : ($cleaned === '' ? 'Kosong setelah cleanup' : 'Harus dimulai 62 + 8-15 digit')
    ];
    
    // Test 3: Suggest corrected format
    $suggested = $cleaned;
    if (strlen($suggested) === 10 && strpos($suggested, '62') !== 0) {
        // Asumsi user input format Indonesia (08...)
        $suggested = '62' . substr($suggested, 1);
    }
    
    $results['tests'][] = [
        'name' => 'Suggested format',
        'result' => $suggested,
        'note' => 'Gunakan format ini untuk API'
    ];
    
    // Test 4: JID format (untuk API yang membutuhkan)
    $results['tests'][] = [
        'name' => 'JID format (dengan @s.whatsapp.net)',
        'result' => $cleaned . '@s.whatsapp.net'
    ];
    
    $results['final_status'] = $regex_match ? 'PASS ✅' : 'FAIL ❌';
    echo json_encode($results, JSON_PRETTY_PRINT);
    exit;
}

// ============ ACTION 2: Test Bot Connection ============
if ($action === 'test_bot_connection') {
    require 'header.php'; // Load database connection
    
    $botId = (int)($_GET['bot_id'] ?? 0);
    
    if ($botId <= 0) {
        echo json_encode(['error' => 'Invalid bot_id']);
        exit;
    }
    
    // Get bot config
    $stmt = $conn->prepare("SELECT id, namebot, addressbot, password, sender FROM botwa WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $botId);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($res->num_rows === 0) {
        echo json_encode(['error' => 'Bot not found']);
        exit;
    }
    
    $bot = $res->fetch_assoc();
    $results = [
        'bot_id' => $botId,
        'bot_name' => $bot['namebot'],
        'addressbot' => $bot['addressbot'],
        'tests' => []
    ];
    
    // Test 1: Basic connectivity
    $addressBot = rtrim($bot['addressbot'], '/');
    $ch = curl_init($addressBot . '/app/status');
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $results['tests'][] = [
        'name' => 'Basic connectivity to bot server',
        'url' => $addressBot,
        'http_code' => $httpCode,
        'curl_error' => $curlError ?: 'None',
        'status' => $httpCode > 0 ? 'PASS ✅' : 'FAIL ❌'
    ];
    
    // Test 2: Auth with test send
    $ch = curl_init($addressBot . '/send/message?session=' . urlencode($bot['namebot']));
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'phone' => '628123456789',
        'message' => 'test',
        'sender' => $bot['sender']
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_USERPWD, "{$bot['namebot']}:{$bot['password']}");
    
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $decoded = json_decode($response, true);
    
    $results['tests'][] = [
        'name' => 'Authentication test',
        'http_code' => $httpCode,
        'response_code' => $decoded['code'] ?? 'N/A',
        'response_message' => $decoded['message'] ?? 'N/A',
        'status' => ($httpCode >= 200 && $httpCode < 300) ? 'PASS ✅' : ($httpCode === 463 ? 'ERROR 463 ❌' : 'FAIL ❌')
    ];
    
    $results['debug_log'] = $response;
    echo json_encode($results, JSON_PRETTY_PRINT);
    exit;
}

// ============ ACTION 3: Format Comparison ============
if ($action === 'format_comparison') {
    $phone = $_GET['phone'] ?? '628123456789';
    
    // Remove non-digits
    $clean = preg_replace('/[^0-9]/', '', $phone);
    
    $formats = [
        'Clean (digit only)' => $clean,
        'With JID suffix' => $clean . '@s.whatsapp.net',
        'Indonesia format (0)' => '0' . substr($clean, 2),
        'International (+)' => '+' . $clean,
        'With spaces' => substr($clean, 0, 2) . ' ' . substr($clean, 2, 4) . ' ' . substr($clean, 6),
    ];
    
    echo json_encode([
        'input' => $phone,
        'formats' => $formats,
        'recommended' => [
            'For API' => $clean,
            'For JID' => $clean . '@s.whatsapp.net'
        ]
    ], JSON_PRETTY_PRINT);
    exit;
}

echo json_encode(['error' => 'Unknown action']);
?>
