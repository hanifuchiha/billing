<?php
header('Content-Type: application/json; charset=utf-8');

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database connection
require_once __DIR__ . '/koneksibilling.php';

// Authentication: Check session OR credentials
$username = '';

// First, check if user is logged in via session
if (isset($_SESSION['username'])) {
    $username = $_SESSION['username'];
} else {
    // Try to authenticate via GET/POST credentials
    $input_username = $_GET['username'] ?? null;
    $input_password = $_GET['password'] ?? null;
    
    if (!$input_username || !$input_password) {
        // Try JSON body for POST/PUT/DELETE
        if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'DELETE'])) {
            $json = json_decode(file_get_contents('php://input'), true);
            $input_username = $json['username'] ?? null;
            $input_password = $json['password'] ?? null;
        }
    }
    
    // Validate credentials if provided
    if ($input_username && $input_password) {
        $stmt = $conn->prepare("SELECT USERNAME FROM user WHERE USERNAME = ? AND PASWORD = ?");
        if ($stmt) {
            $stmt->bind_param('ss', $input_username, $input_password);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $username = $row['USERNAME'];
            }
            $stmt->close();
        }
    }
}

// If still no username, user is unauthorized
if (!$username) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
?>
