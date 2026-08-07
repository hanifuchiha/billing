<?php
require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    twk_response(405, ['success' => false, 'message' => 'Method tidak diizinkan.']);
}

$conn = twk_db_connect();
$session = twk_require_auth($conn);
$idpel = (string)$session['idpel'];

$pelanggan = twk_find_customer($conn, $idpel);
if (!$pelanggan) {
    twk_response(404, ['success' => false, 'message' => 'Data pelanggan tidak ditemukan.']);
}

$sender = twk_customer_chat_identity($pelanggan);
if ($sender === '') {
    twk_response(422, ['success' => false, 'message' => 'Identitas chat pelanggan tidak valid.']);
}

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 80;
if ($limit <= 0 || $limit > 200) {
    $limit = 80;
}

$messages = [];
$sql = "SELECT id, sender_id, receiver_id, message, timestamp, is_read, reply_to_id, reply_to_text
        FROM messages
        WHERE (sender_id = ? AND receiver_id = 'admin')
           OR (sender_id = 'admin' AND receiver_id = ?)
        ORDER BY timestamp ASC
        LIMIT ?";
$stmt = mysqli_prepare($conn, $sql);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, 'ssi', $sender, $sender, $limit);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $fromMe = ((string)($row['sender_id'] ?? '') === $sender);
        $messages[] = [
            'id' => (int)($row['id'] ?? 0),
            'from_me' => $fromMe,
            'sender' => (string)($row['sender_id'] ?? ''),
            'receiver' => (string)($row['receiver_id'] ?? ''),
            'message' => (string)($row['message'] ?? ''),
            'timestamp' => (string)($row['timestamp'] ?? ''),
            'is_read' => (int)($row['is_read'] ?? 0),
            'reply_to_id' => isset($row['reply_to_id']) ? (int)$row['reply_to_id'] : null,
            'reply_to_text' => (string)($row['reply_to_text'] ?? '')
        ];
    }
    mysqli_stmt_close($stmt);
}

$markRead = mysqli_prepare($conn, "UPDATE messages SET is_read = 1 WHERE sender_id = 'admin' AND receiver_id = ? AND is_read = 0");
if ($markRead) {
    mysqli_stmt_bind_param($markRead, 's', $sender);
    mysqli_stmt_execute($markRead);
    mysqli_stmt_close($markRead);
}

twk_response(200, [
    'success' => true,
    'message' => 'Data chat berhasil diambil.',
    'data' => [
        'sender' => $sender,
        'items' => $messages,
        'count' => count($messages)
    ]
]);
