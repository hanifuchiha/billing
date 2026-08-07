<?php
require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

$body = twk_get_json_body();
$message = trim((string)($body['message'] ?? ($_POST['message'] ?? '')));
$replyToId = isset($body['reply_to_id']) ? (int)$body['reply_to_id'] : (isset($_POST['reply_to_id']) ? (int)$_POST['reply_to_id'] : 0);
$replyToText = trim((string)($body['reply_to_text'] ?? ($_POST['reply_to_text'] ?? '')));

$cfg = twk_load_config();
$domain = trim((string)($cfg['domain'] ?? 'quenbytekniksejahtera.com'));
$uploadDir = dirname(__DIR__, 4) . '/dokumen/chat';
$uploadUrlPrefix = 'https://' . $domain . '/dokumen/chat/';

$fileUrl = null;
if (isset($_FILES['file']) && is_array($_FILES['file']) && (int)($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }

    $originalName = (string)($_FILES['file']['name'] ?? 'upload.bin');
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'jfif', 'heic', 'heif',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt',
        'mp3', 'wav', 'ogg', 'mp4', 'webm', 'mov', 'mkv',
        'zip', 'rar', '7z'
    ];

    if (!in_array($ext, $allowed, true)) {
        twk_response(422, ['success' => false, 'message' => 'Tipe file tidak didukung.']);
    }

    $newFileName = uniqid('file_', true) . '.' . $ext;
    $targetPath = $uploadDir . '/' . $newFileName;
    if (!@move_uploaded_file((string)$_FILES['file']['tmp_name'], $targetPath)) {
        twk_response(500, ['success' => false, 'message' => 'Gagal mengunggah file chat.']);
    }

    $fileUrl = $uploadUrlPrefix . $newFileName;
}

if ($fileUrl !== null) {
    $message = $message !== '' ? ($fileUrl . "\n" . $message) : $fileUrl;
}

if ($message === '') {
    twk_response(422, ['success' => false, 'message' => 'Pesan tidak boleh kosong.']);
}
if (strlen($message) > 4000) {
    twk_response(422, ['success' => false, 'message' => 'Pesan terlalu panjang (maks 4000 karakter).']);
}

$stmt = mysqli_prepare(
    $conn,
    "INSERT INTO messages (sender_id, receiver_id, message, timestamp, is_read, reply_to_id, reply_to_text)
     VALUES (?, 'admin', ?, NOW(), 0, ?, ?)"
);

$ok = false;
if ($stmt) {
    $replyIdValue = $replyToId > 0 ? $replyToId : 0;
    mysqli_stmt_bind_param($stmt, 'ssis', $sender, $message, $replyIdValue, $replyToText);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

if (!$ok) {
    $stmtLegacy = mysqli_prepare(
        $conn,
        "INSERT INTO messages (sender_id, receiver_id, message, timestamp, is_read)
         VALUES (?, 'admin', ?, NOW(), 0)"
    );
    if ($stmtLegacy) {
        mysqli_stmt_bind_param($stmtLegacy, 'ss', $sender, $message);
        $ok = mysqli_stmt_execute($stmtLegacy);
        mysqli_stmt_close($stmtLegacy);
    }
}

$newId = (int)mysqli_insert_id($conn);

if (!$ok) {
    twk_response(500, ['success' => false, 'message' => 'Gagal mengirim pesan chat.']);
}

twk_response(200, [
    'success' => true,
    'message' => 'Pesan berhasil dikirim.',
    'data' => [
        'id' => $newId,
        'sender' => $sender,
        'receiver' => 'admin',
        'message' => $message,
        'timestamp' => date('Y-m-d H:i:s')
    ]
]);
