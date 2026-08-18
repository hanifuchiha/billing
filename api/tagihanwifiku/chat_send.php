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

// receiver_id HARUS di-scope per tenant (PEMILIK pelanggan), bukan literal 'admin' -
// dulu SEMUA tenant nulis ke bucket 'admin' yang sama, jadi:
// (a) admin tenant lain (bukan superadmin literal 'admin') tidak pernah melihat pesan
//     customer TagihanWifiku-nya sendiri di crm/chat/ (load_contacts.php/load_chat.php
//     nyari receiver_id = username tenant, bukan 'admin'), dan
// (b) di view superadmin (yang sengaja tidak difilter per tenant) semua tenant
//     bercampur jadi satu, kelihatan seperti "pesan nyampur ke chat orang lain".
$receiverIdentity = trim((string)($pelanggan['PEMILIK'] ?? ''));
if ($receiverIdentity === '') {
    $receiverIdentity = 'admin';
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
     VALUES (?, ?, ?, NOW(), 0, ?, ?)"
);

$ok = false;
if ($stmt) {
    $replyIdValue = $replyToId > 0 ? $replyToId : 0;
    mysqli_stmt_bind_param($stmt, 'sssis', $sender, $receiverIdentity, $message, $replyIdValue, $replyToText);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

if (!$ok) {
    $stmtLegacy = mysqli_prepare(
        $conn,
        "INSERT INTO messages (sender_id, receiver_id, message, timestamp, is_read)
         VALUES (?, ?, ?, NOW(), 0)"
    );
    if ($stmtLegacy) {
        mysqli_stmt_bind_param($stmtLegacy, 'sss', $sender, $receiverIdentity, $message);
        $ok = mysqli_stmt_execute($stmtLegacy);
        mysqli_stmt_close($stmtLegacy);
    }
}

$newId = (int)mysqli_insert_id($conn);

if (!$ok) {
    twk_response(500, ['success' => false, 'message' => 'Gagal mengirim pesan chat.']);
}

// Catat ke chat_event_log utk fitur "Catatan Kejadian" (ringkasan per jam
// di modal Live Chat) -- HANYA pesan MASUK dari pelanggan ini, bukan balasan
// admin/AI. Gagal catat TIDAK menggagalkan pengiriman pesan yang sudah pasti
// berhasil di atas.
require_once __DIR__ . '/../../chat_activity_helper.php';
chatActivityLog($conn, $receiverIdentity, $sender, (string)($pelanggan['NAMA'] ?? ''), 'livechat', $message);

// AI bot Live Chat (opt-in per tenant, diatur di System Setting / toggle di crm/chat/).
// Kalau nonaktif atau gagal, biarkan diam saja -- ini bonus di atas pengiriman pesan
// pelanggan yang sudah pasti berhasil di atas, jangan sampai gagal AI menggagalkan respons ini.
//
// Sebelum ke AI chat bebas, dicek dulu keyword aksi (cek data pelanggan/tiket
// gangguan/kode bayar) -- pola sama seperti crm/webhook/botrespon.php, TAPI
// reuse endpoint API mobile yang sudah ada (bukan menduplikasi logic WA bot),
// via token Bearer yang sama yang baru saja divalidasi twk_require_auth() di atas.
require_once __DIR__ . '/../../livechat_ai_helper.php';
$aiSettingsCheck = livechatAiGetSettings($conn, $receiverIdentity);
if (!empty($aiSettingsCheck['ai_enabled'])) {
    $actionReply = null;
    $authHeader = twk_get_auth_header();
    if (preg_match('/Bearer\s+(.+)/i', $authHeader, $mTok)) {
        $rawToken = trim($mTok[1]);
        $actionReply = livechatAiHandleKeywordAction($conn, $receiverIdentity, $sender, $domain, $rawToken, $pelanggan, $idpel, $message);
    }

    if ($actionReply !== null) {
        $stmtAction = mysqli_prepare($conn, "INSERT INTO messages (sender_id, receiver_id, message, timestamp, is_read) VALUES (?, ?, ?, NOW(), 0)");
        if ($stmtAction) {
            mysqli_stmt_bind_param($stmtAction, 'sss', $receiverIdentity, $sender, $actionReply);
            mysqli_stmt_execute($stmtAction);
            mysqli_stmt_close($stmtAction);
        }
    } else {
        livechatAiMaybeReply($conn, $receiverIdentity, $sender, (string)($pelanggan['NAMA'] ?? ''), $message);
    }
}

twk_response(200, [
    'success' => true,
    'message' => 'Pesan berhasil dikirim.',
    'data' => [
        'id' => $newId,
        'sender' => $sender,
        'receiver' => $receiverIdentity,
        'message' => $message,
        'timestamp' => date('Y-m-d H:i:s')
    ]
]);
