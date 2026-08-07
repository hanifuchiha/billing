<?php
// Helper API to fetch user's bots for floating chat modal
ob_start();
require 'header.php';
ob_end_clean();

// Output JSON
header('Content-Type: application/json');

$response = [
    'success' => false,
    'bots' => []
];

try {
    if ($AKSES == 'USER' || $AKSES == 'ADMIN') {
        if ($AKSES == 'ADMIN') {
            $sql_bots = "SELECT id, namebot, addressbot, webhook FROM botwa ORDER BY id DESC";
            $result_bots = mysqli_query($conn, $sql_bots);
        } else {
            $sql_bots = "SELECT id, namebot, addressbot, webhook FROM botwa WHERE pemilik = ? ORDER BY id DESC";
            $stmt_bots = $conn->prepare($sql_bots);
            if (!$stmt_bots) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $stmt_bots->bind_param('s', $ceknama);
            $stmt_bots->execute();
            $result_bots = $stmt_bots->get_result();
        }
        
        if ($result_bots && mysqli_num_rows($result_bots) > 0) {
            while ($bot = mysqli_fetch_assoc($result_bots)) {
                $response['bots'][] = [
                    'id' => $bot['id'],
                    'namebot' => $bot['namebot'],
                    'addressbot' => $bot['addressbot'],
                    'webhook' => $bot['webhook']
                ];
            }
            $response['success'] = true;
        } else {
            $response['success'] = true;
            $response['bots'] = [];
        }
    }
} catch (Exception $e) {
    $response['success'] = false;
    $response['error'] = $e->getMessage();
}

echo json_encode($response);
exit;
?>
