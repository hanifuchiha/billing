<?php
/**
 * Reset/Disconnect Koneksi PPPoE di Mikrotik
 * Endpoint: crm/billing/proses/reset_koneksi.php
 * Method: POST
 */

header('Content-Type: application/json');
require '../cek-sesi.php';
require '../koneksidb.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$idpel = $_POST['idpel'] ?? '';
$server = $_POST['server'] ?? '';
$ip_mikrotik = $_POST['ip_mikrotik'] ?? '';
$user_mikrotik = $_POST['user_mikrotik'] ?? '';
$pass_mikrotik = $_POST['pass_mikrotik'] ?? '';

if (empty($idpel) || empty($ip_mikrotik)) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit;
}

try {
    // Load RouterOS API
    require '../getdata/routeros_api.class.php';
    
    $API = new RouterosAPI();
    
    // Koneksi ke Mikrotik
    if (!$API->connect($ip_mikrotik, $user_mikrotik, $pass_mikrotik)) {
        throw new Exception('Failed to connect to Mikrotik: ' . $ip_mikrotik);
    }
    
    // Query PPP active untuk find koneksi dengan username ini
    $pppActive = $API->comm('/ppp/active/print', ["?name" => $idpel]);
    
    if (empty($pppActive)) {
        echo json_encode([
            'success' => false,
            'message' => "Koneksi ${idpel} tidak sedang aktif di Mikrotik"
        ]);
        $API->disconnect();
        exit;
    }
    
    // Ambil ID dari active connection
    $activeId = $pppActive[0]['.id'] ?? null;
    
    if (empty($activeId)) {
        throw new Exception('Could not find active connection ID');
    }
    
    // Disconnect / remove active PPP connection
    $result = $API->comm('/ppp/active/remove', ['.id' => $activeId]);
    
    $API->disconnect();
    
    if ($result) {
        // Log ke database untuk audit
        $logMsg = "User ${idpel} koneksi di-disconnect oleh ${ceknama}";
        $logTime = date('Y-m-d H:i:s');
        error_log("[${logTime}] [${server}] ${logMsg}");
        
        echo json_encode([
            'success' => true,
            'message' => "✅ Koneksi ${idpel} berhasil di-disconnect dari Mikrotik ${ip_mikrotik}",
            'idpel' => $idpel,
            'active_id' => $activeId
        ]);
    } else {
        throw new Exception('Failed to disconnect active connection');
    }
    
} catch (Exception $e) {
    error_log("Reset koneksi error for ${idpel}: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage(),
        'idpel' => $idpel
    ]);
}

exit;
?>
