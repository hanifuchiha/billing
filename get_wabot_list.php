<?php
// Enable error reporting for debugging
error_reporting(0);
ini_set('display_errors', 0);

// Start output buffering to prevent header issues
ob_start();

// Include header for database connection
require 'header.php';

// Clean output buffer before JSON response
ob_end_clean();

// Set JSON header
header('Content-Type: application/json');

// Prepare response
$response = [
    'success' => false,
    'bots' => [],
    'message' => 'No bots found'
];

try {
    // Username & akses HARUS dari sesi login, bukan dari POST client -- kalau
    // dipercaya dari POST, siapa saja bisa kirim akses=ADMIN / username milik
    // orang lain untuk lihat bot yang bukan miliknya.
    $username = isset($ceknama) ? $ceknama : '';
    $akses = isset($AKSES) ? $AKSES : '';

    // Query database for bots
    if (!empty($username) && !empty($akses)) {
        if ($akses === 'ADMIN') {
            // Admin gets all bots
            $sql = "SELECT id, namebot, addressbot, webhook FROM botwa ORDER BY id DESC";
            $result = mysqli_query($conn, $sql);
        } else {
            // User gets only their own bots (assistant dibatasi bot yang di-assign / buatan sendiri)
            $sql = "SELECT id, namebot, addressbot, webhook FROM botwa WHERE pemilik = ?" . botAccessWhereClause($conn, $akses, $assigned_bot_ids ?? [], $asistant_name ?? '') . " ORDER BY id DESC";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $result = $stmt->get_result();
        }

        if ($result && mysqli_num_rows($result) > 0) {
            while ($bot = mysqli_fetch_assoc($result)) {
                $response['bots'][] = [
                    'id' => (int)$bot['id'],
                    'namebot' => $bot['namebot'],
                    'webhook' => $bot['webhook']
                ];
            }
            $response['success'] = true;
            $response['message'] = 'Bots loaded successfully';
        }
    }
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

// Output JSON response
echo json_encode($response);
exit;
?>
