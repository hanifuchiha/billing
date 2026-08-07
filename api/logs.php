<?php
// API Log History - Returns system logs like web version
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    require_once '../koneksibilling.php';
    session_start();

    $username = $_GET['username'] ?? ($_POST['username'] ?? '');
    $password = $_GET['password'] ?? ($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        echo json_encode(['success' => false, 'error' => 'Username dan password harus diisi']);
        exit;
    }

    // Validasi user dan get user_id
    function auth_user($conn, $username, $password) {
        $stmt = $conn->prepare("SELECT id FROM user WHERE USERNAME = ?");
        if (!$stmt) return false;
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $stmt_check = $conn->prepare("SELECT PASWORD FROM user WHERE id = ?");
            $stmt_check->bind_param("i", $row['id']);
            $stmt_check->execute();
            $verify = $stmt_check->get_result();
            if ($verify->num_rows > 0) {
                $verify_row = $verify->fetch_assoc();
                if (password_verify($password, $verify_row['PASWORD'])) {
                    return $row['id'];
                }
            }
        }
        return false;
    }

    $user_id = auth_user($conn, $username, $password);
    if (!$user_id) {
        echo json_encode(['success' => false, 'error' => 'Autentikasi gagal']);
        exit;
    }

    $logs = [];
    
    // Try to read from notifbot/data/history-[username].json (same as web version)
    $history_file = __DIR__ . '/../notifbot/data/history-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $username) . '.json';
    
    if (file_exists($history_file)) {
        $history_content = file_get_contents($history_file);
        $history_data = json_decode($history_content, true);
        
        if (is_array($history_data)) {
            // Parse logs: "[ SISTEM - TIMESTAMP ] MESSAGE"
            // Return in reverse order (latest first) - max 100 entries
            $history_data = array_reverse($history_data);
            $history_data = array_slice($history_data, 0, 100);
            
            foreach ($history_data as $log_entry) {
                if (preg_match('/^\[\s*(.+?)\s*-\s*(.+?)\s*\]\s*(.*)$/', $log_entry, $matches)) {
                    $logs[] = [
                        'sistem' => trim($matches[1]),
                        'timestamp' => trim($matches[2]),
                        'log' => trim($matches[3]),
                        'raw' => $log_entry  // Keep raw format for fallback
                    ];
                } else {
                    // If format doesn't match, still include it
                    $logs[] = [
                        'sistem' => 'System',
                        'timestamp' => date('Y-m-d H:i:s'),
                        'log' => $log_entry,
                        'raw' => $log_entry
                    ];
                }
            }
        }
    }
    
    // If no logs from file, create sample logs from database activities
    if (empty($logs)) {
        $sample_query = "SELECT 
            CONCAT(PEMILIK, ' - ', AREA) as sistem,
            DATE_FORMAT(TANGGALBAYAR, '%Y-%m-%d %H:%i:%s') as timestamp,
            CONCAT(NAMA, ' (', IDPEL, ') - Rp ', HARGA, ' - ', STATUS) as log
            FROM transaksi 
            WHERE PEMILIK IN (SELECT DISTINCT PEMILIK FROM server WHERE user_id = " . intval($user_id) . ")
            ORDER BY TANGGALBAYAR DESC
            LIMIT 50";
        
        $result = mysqli_query($conn, $sample_query);
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $logs[] = [
                    'sistem' => $row['sistem'],
                    'timestamp' => $row['timestamp'],
                    'log' => $row['log'],
                    'raw' => "[ " . $row['sistem'] . " - " . $row['timestamp'] . " ] " . $row['log']
                ];
            }
        }
    }

    echo json_encode(['success' => true, 'data' => $logs]);
    exit;

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}
?>
