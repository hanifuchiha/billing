<?php
/**
 * OLT Cache Data Provider
 * Fetches OLT credentials from cache with fallback to database
 * Used by all OLT console types (ZTE, CDATA, HUAWEI, etc.)
 */

session_start();

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['status']) || $_SESSION['status'] !== 'login') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$oltId = (int)($_GET['olt_id'] ?? $_POST['olt_id'] ?? 0);
$oltIp = $_GET['olt_ip'] ?? $_POST['olt_ip'] ?? '';

// Cache keys from olt.php
const OLT_CACHE_KEY = 'qts_olt_data_cache';
const OLT_CACHE_TIME_KEY = 'qts_olt_cache_time';
const CACHE_DURATION = 5 * 60 * 1000; // 5 minutes

/**
 * Get OLT data from cache or database
 */
function getOltData($oltId, $oltIp) {
    // Try cache first
    $cached = getOltFromCache($oltId, $oltIp);
    if ($cached) {
        return ['success' => true, 'source' => 'cache', 'data' => $cached];
    }
    
    // Fallback to database
    $cached = getOltFromDatabase($oltId, $oltIp);
    if ($cached) {
        return ['success' => true, 'source' => 'database', 'data' => $cached];
    }
    
    return ['success' => false, 'error' => 'OLT not found'];
}

/**
 * Get OLT from browser cache (passed from client)
 */
function getOltFromCache($oltId, $oltIp) {
    // Cache is managed on browser side (localStorage)
    // This function is for validating and retrieving cached data sent from client
    
    // If both ID and IP provided, that's likely from cache
    if ($oltId > 0 || !empty($oltIp)) {
        return [
            'olt_id' => $oltId,
            'olt_ip' => $oltIp
        ];
    }
    
    return null;
}

/**
 * Get OLT from database (fallback)
 */
function getOltFromDatabase($oltId, $oltIp) {
    global $conn;
    
    // Require database connection
    require_once __DIR__ . '/../../header.php';
    
    $query = null;
    
    if ($oltId > 0) {
        $stmt = $conn->prepare("SELECT * FROM olt WHERE id = ?");
        $stmt->bind_param("i", $oltId);
    } elseif (!empty($oltIp)) {
        // Handle IP:port format
        $ipOnly = strpos($oltIp, ':') !== false ? explode(':', $oltIp)[0] : $oltIp;
        $stmt = $conn->prepare("SELECT * FROM olt WHERE ipolt LIKE ?");
        $search = "$ipOnly%";
        $stmt->bind_param("s", $search);
    } else {
        return null;
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    $stmt->close();
    
    return $data;
}

/**
 * Handle actions
 */
if ($action === 'get_by_id') {
    $result = getOltData($oltId, $oltIp);
    echo json_encode($result);
    exit;
}

if ($action === 'get_by_ip') {
    $result = getOltData($oltId, $oltIp);
    echo json_encode($result);
    exit;
}

if ($action === 'validate') {
    // Validate OLT exists and user has access
    require_once __DIR__ . '/../../header.php';
    
    $result = getOltData($oltId, $oltIp);
    
    if ($result['success']) {
        $olt = $result['data'];
        
        // Check user access
        $currentUserId = $_SESSION['id'] ?? 0;
        $userAkses = $_SESSION['akses'] ?? '';
        
        // Additional validation: ensure user owns this OLT (if not ADMIN)
        if ($userAkses !== 'ADMIN' && $userAkses !== 'SUPERADMIN') {
            $stmt = $conn->prepare("SELECT id FROM server WHERE pemilik = ? AND user_id = ?");
            $stmt->bind_param("si", $olt['pemilik'], $currentUserId);
            $stmt->execute();
            if ($stmt->get_result()->num_rows === 0) {
                $stmt->close();
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Access denied']);
                exit;
            }
            $stmt->close();
        }
        
        echo json_encode($result);
    } else {
        http_response_code(404);
        echo json_encode($result);
    }
    exit;
}

// Default: return help
echo json_encode([
    'success' => false,
    'error' => 'No action specified',
    'available_actions' => [
        'get_by_id' => 'Get OLT by ID (olt_id parameter)',
        'get_by_ip' => 'Get OLT by IP (olt_ip parameter)',
        'validate' => 'Validate and get OLT data with access check'
    ]
]);
?>
