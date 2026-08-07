<?php
header('Content-Type: application/json; charset=utf-8');
require 'header_api.php';

$username = isset($_SESSION['username']) ? $_SESSION['username'] : '';

if (!$username) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$contact_id = isset($input['contact_id']) ? $input['contact_id'] : '';
$message = isset($input['message']) ? $input['message'] : '';

$contact_id = mysqli_real_escape_string($conn, $contact_id);
$message = mysqli_real_escape_string($conn, $message);

if (!$contact_id || !$message) {
    echo json_encode(['success' => false, 'message' => 'Contact ID and message required']);
    exit;
}

$reply_to_id = isset($input['reply_to_id']) ? (int)$input['reply_to_id'] : null;
$reply_to_text = isset($input['reply_to_text']) ? mysqli_real_escape_string($conn, $input['reply_to_text']) : '';

date_default_timezone_set('Asia/Jakarta');
$timestamp = date('Y-m-d H:i:s');

// Insert message
$sql = "
INSERT INTO messages 
(sender_id, receiver_id, message, timestamp, is_read, reply_to_id, reply_to_text)
VALUES 
('$username', '$contact_id', '$message', '$timestamp', 0, " . 
($reply_to_id ? "$reply_to_id" : "NULL") . ", " . 
($reply_to_text ? "'$reply_to_text'" : "NULL") . ")
";

if (mysqli_query($conn, $sql)) {
    $message_id = mysqli_insert_id($conn);
    
    echo json_encode([
        'success' => true,
        'message' => 'Message sent successfully',
        'message_id' => $message_id,
        'timestamp' => $timestamp
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to send message: ' . mysqli_error($conn)]);
}

mysqli_close($conn);
?>
