<?php
header('Content-Type: application/json; charset=utf-8');
require 'header_api.php';

$username = isset($_SESSION['username']) ? $_SESSION['username'] : '';

if (!$username) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$contact_id = isset($_GET['contact_id']) ? $_GET['contact_id'] : '';
$contact_id = mysqli_real_escape_string($conn, $contact_id);

if (!$contact_id) {
    echo json_encode(['success' => false, 'message' => 'Contact ID required']);
    exit;
}

// Mark messages from contact as read
$sql_update = "
UPDATE messages 
SET is_read = 1 
WHERE sender_id = '$contact_id' AND receiver_id = '$username'
";
mysqli_query($conn, $sql_update);

// Get all messages in conversation
$sql = "
SELECT id, sender_id, receiver_id, message, timestamp, is_read, reply_to_id, reply_to_text
FROM messages 
WHERE (sender_id = '$username' AND receiver_id = '$contact_id') 
   OR (sender_id = '$contact_id' AND receiver_id = '$username')
ORDER BY timestamp ASC
";

$result = mysqli_query($conn, $sql);

if (!$result) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    exit;
}

$messages = [];
while ($row = mysqli_fetch_assoc($result)) {
    // Check if message contains file
    $message_text = $row['message'];
    $file_url = null;
    $display_text = $message_text;
    
    // Check if message starts with URL (file upload)
    if (preg_match('~^https?://[^\n]+~', $message_text, $matches)) {
        $file_url = $matches[0];
        $display_text = trim(substr($message_text, strlen($file_url)));
    }
    
    $messages[] = [
        'id' => (int)$row['id'],
        'sender_id' => $row['sender_id'],
        'receiver_id' => $row['receiver_id'],
        'message' => $display_text,
        'file_url' => $file_url,
        'timestamp' => $row['timestamp'],
        'is_read' => (int)$row['is_read'],
        'reply_to_id' => $row['reply_to_id'],
        'reply_to_text' => $row['reply_to_text']
    ];
}

echo json_encode([
    'success' => true,
    'data' => $messages
]);

mysqli_close($conn);
?>
