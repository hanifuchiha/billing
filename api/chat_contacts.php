<?php
header('Content-Type: application/json; charset=utf-8');
require 'header_api.php';

// Get user info from session
$user_id = isset($_SESSION['id']) ? (int)$_SESSION['id'] : 0;
$username = isset($_SESSION['username']) ? $_SESSION['username'] : '';

if (!$username) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Get admin parameter (for filtering)
$admin = isset($_GET['admin']) ? $_GET['admin'] : $username;
$admin = mysqli_real_escape_string($conn, $admin);

// Get all conversations for this user
$sql = "
SELECT DISTINCT 
    CASE 
        WHEN sender_id = '$admin' THEN receiver_id 
        ELSE sender_id 
    END AS contact_id,
    MAX(timestamp) AS last_time,
    SUM(CASE WHEN sender_id != '$admin' AND is_read = 0 THEN 1 ELSE 0 END) AS unread_count
FROM messages 
WHERE sender_id = '$admin' OR receiver_id = '$admin'
GROUP BY contact_id
ORDER BY last_time DESC
";

$result = mysqli_query($conn, $sql);

if (!$result) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    exit;
}

$contacts = [];
while ($row = mysqli_fetch_assoc($result)) {
    $contact_id = $row['contact_id'];
    
    // Parse customer info from contact_id if it contains customer format
    $contact_name = $contact_id;
    $contact_phone = '';
    
    if (preg_match('/(.+?)\s*\(\s*Whatsapp\s*:\s*(.+?)\s*\)/', $contact_id, $matches)) {
        $contact_name = trim($matches[1]);
        $contact_phone = trim($matches[2]);
    }
    
    $contacts[] = [
        'contact_id' => $contact_id,
        'contact_name' => $contact_name,
        'contact_phone' => $contact_phone,
        'last_time' => $row['last_time'],
        'unread_count' => (int)$row['unread_count']
    ];
}

echo json_encode([
    'success' => true,
    'data' => $contacts
]);

mysqli_close($conn);
?>
