<?php
header('Content-Type: application/json');
session_start();

// Load config
require_once __DIR__ . '/../../header.php';

// Check if user is logged in
if (!isset($_SESSION['status']) || $_SESSION['status'] !== 'login') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $current_user_id = isset($_SESSION['id']) ? (int)$_SESSION['id'] : 0;
    $AKSES = isset($_SESSION['akses']) ? $_SESSION['akses'] : '';
    
    // Determine which OLTs user can access
    if ($AKSES != 'ASSISTANT') {
        // Regular user: get OLTs from servers they own
        $queryServerId = mysqli_query($conn, "SELECT PEMILIK FROM server WHERE user_id = $current_user_id");
        $userServerIds = [];
        while ($row = mysqli_fetch_assoc($queryServerId)) {
            $userServerIds[] = "'" . mysqli_real_escape_string($conn, $row['PEMILIK']) . "'";
        }
        $userServerList = count($userServerIds) > 0 ? implode(",", $userServerIds) : "''";
        $sql = "SELECT * FROM olt WHERE pemilik IN ($userServerList) ORDER BY area, oltname";
    } else {
        // Assistant: get all OLTs they have access to
        // This would need area filtering based on assistant permissions
        // For now, get all OLTs
        $sql = "SELECT * FROM olt ORDER BY area, oltname";
    }
    
    $query = mysqli_query($conn, $sql);
    if (!$query) {
        throw new Exception(mysqli_error($conn));
    }
    
    $olts = [];
    while ($data = mysqli_fetch_assoc($query)) {
        $olts[] = [
            'id' => (int)$data['id'],
            'ipolt' => $data['ipolt'],
            'oltname' => $data['oltname'],
            'pemilik' => $data['pemilik'],
            'area' => $data['area'],
            'usernameolt' => $data['usernameolt'],
            'passwordolt' => $data['passwordolt'],
            'brandolt' => $data['brandolt'],
            'community_read' => $data['community_read'] ?? '',
            'community_write' => $data['community_write'] ?? '',
            'timestamp' => time()
        ];
    }
    
    echo json_encode([
        'success' => true,
        'count' => count($olts),
        'data' => $olts,
        'timestamp' => time()
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
