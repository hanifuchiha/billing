<?php
header('Content-Type: application/json; charset=utf-8');
require 'header_api.php';

$username = isset($_SESSION['username']) ? $_SESSION['username'] : '';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

// Check if table exists
$result = mysqli_query($conn, "SHOW TABLES LIKE 'page_access_log'");
if (mysqli_num_rows($result) == 0) {
    echo json_encode([
        'success' => true,
        'logs' => [],
        'total' => 0,
        'message' => 'No logs available'
    ]);
    exit;
}

// Get total count
$sql_count = "SELECT COUNT(*) as total FROM page_access_log";
$result_count = mysqli_query($conn, $sql_count);
$total = mysqli_fetch_assoc($result_count)['total'] ?? 0;

// Get logs
$sql = "
SELECT 
    id,
    user_id,
    username,
    page_name,
    page_url,
    http_method,
    ip_address,
    user_agent,
    access_time
FROM page_access_log
ORDER BY access_time DESC
LIMIT $limit OFFSET $offset
";

$result = mysqli_query($conn, $sql);

if (!$result) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

$logs = [];
while ($row = mysqli_fetch_assoc($result)) {
    $logs[] = [
        'id' => (int)$row['id'],
        'user_id' => (int)$row['user_id'],
        'username' => $row['username'],
        'page_name' => $row['page_name'],
        'page_url' => $row['page_url'],
        'http_method' => $row['http_method'],
        'ip_address' => $row['ip_address'],
        'user_agent' => substr($row['user_agent'], 0, 100),
        'access_time' => $row['access_time']
    ];
}

echo json_encode([
    'success' => true,
    'logs' => $logs,
    'total' => $total,
    'limit' => $limit,
    'offset' => $offset
]);

mysqli_close($conn);
?>
