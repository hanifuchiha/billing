<?php
// save_smtp_setting.php -- simpan/tes pengaturan SMTP per-tenant.
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}
require_once __DIR__ . '/../cek-sesi.php';
require_once __DIR__ . '/../notifbot/mailer_helper.php';

// Kredensial SMTP sensitif -- owner-only, sama pola halaman email_setting.php.
if ($AKSES === 'ASSISTANT') {
    echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki akses ke pengaturan Email SMTP.']);
    exit;
}

$action = trim((string)($_POST['action'] ?? ''));
$mode = (string)($_POST['mode'] ?? 'internal') === 'external' ? 'external' : 'internal';
$smtpHost = trim((string)($_POST['smtp_host'] ?? ''));
$smtpPort = (int)($_POST['smtp_port'] ?? 587);
if ($smtpPort <= 0) {
    $smtpPort = 587;
}
$smtpUser = trim((string)($_POST['smtp_user'] ?? ''));
$smtpPass = (string)($_POST['smtp_pass'] ?? '');
$smtpSecureRaw = strtolower(trim((string)($_POST['smtp_secure'] ?? 'tls')));
$smtpSecure = in_array($smtpSecureRaw, ['tls', 'ssl'], true) ? $smtpSecureRaw : '';
$smtpFromEmail = trim((string)($_POST['smtp_from_email'] ?? ''));
$smtpFromName = trim((string)($_POST['smtp_from_name'] ?? ''));
$imapHost = trim((string)($_POST['imap_host'] ?? ''));
$imapPort = (int)($_POST['imap_port'] ?? 993);
if ($imapPort <= 0) {
    $imapPort = 993;
}
$imapSecureRaw = strtolower(trim((string)($_POST['imap_secure'] ?? 'ssl')));
$imapSecure = ($imapSecureRaw === 'ssl') ? 'ssl' : '';

$settingInput = [
    'pemilik' => $ceknama,
    'mode' => $mode,
    'smtp_host' => $smtpHost,
    'smtp_port' => $smtpPort,
    'smtp_user' => $smtpUser,
    'smtp_pass' => $smtpPass,
    'smtp_secure' => $smtpSecure,
    'smtp_from_email' => $smtpFromEmail,
    'smtp_from_name' => $smtpFromName,
    'imap_host' => $imapHost,
    'imap_port' => $imapPort,
    'imap_secure' => $imapSecure,
];

if ($action === 'check_inbox') {
    if ($mode === 'external') {
        // Kalau IMAP host tidak diisi eksplisit, coba fallback ke SMTP host --
        // umum di setup 1 domain (cPanel dkk) SMTP & IMAP jalan di host yang sama.
        $useHost = $imapHost !== '' ? $imapHost : $smtpHost;
        $usePort = $imapPort;
        $useSecure = $imapSecure !== '' ? $imapSecure : 'ssl';
        $useUser = $smtpUser;
        $usePass = $smtpPass;
    } else {
        $useHost = $imapHost !== '' ? $imapHost : IMAP_INTERNAL_HOST;
        $usePort = $imapHost !== '' ? $imapPort : IMAP_INTERNAL_PORT;
        $useSecure = $imapHost !== '' ? ($imapSecure !== '' ? $imapSecure : 'ssl') : IMAP_INTERNAL_SECURE;
        $useUser = SMTP_INTERNAL_USER;
        $usePass = SMTP_INTERNAL_PASS;
    }

    $result = imapCheckInbox($useHost, $usePort, $useSecure, $useUser, $usePass, 10);
    echo json_encode([
        'success' => (bool)$result['success'],
        'message' => $result['success']
            ? ('Terhubung. Total ' . (int)$result['total'] . ' pesan, ' . (int)$result['unseen'] . ' belum dibaca.')
            : ('Gagal cek inbox: ' . $result['error']),
        'messages' => $result['messages'],
        'total' => $result['total'],
        'unseen' => $result['unseen'],
    ]);
    exit;
}

if ($action === 'test') {
    $testEmail = trim((string)($_POST['test_email'] ?? ''));
    if ($testEmail === '' || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Email tujuan test tidak valid.']);
        exit;
    }
    $result = sendEmailMessage(
        $conn,
        $ceknama,
        $testEmail,
        'Tester',
        'Test Email SMTP - ' . (string)($ceknama ?? ''),
        'Ini adalah email percobaan dari pengaturan Email SMTP Anda. Kalau email ini sampai, pengaturan SMTP sudah benar.',
        $settingInput
    );
    echo json_encode(['success' => (bool)$result['sent'], 'message' => $result['sent'] ? 'Email test berhasil dikirim, cek inbox/spam ' . $testEmail . '.' : ('Gagal kirim: ' . $result['error'])]);
    exit;
}

if ($action === 'save') {
    smtpSettingEnsureTable($conn);

    $stmtCheck = $conn->prepare("SELECT id FROM smtp_setting WHERE pemilik = ?");
    $stmtCheck->bind_param('s', $ceknama);
    $stmtCheck->execute();
    $stmtCheck->store_result();
    $exists = $stmtCheck->num_rows > 0;
    $stmtCheck->close();

    // Tipe per parameter (mode, host, port, user, pass, secure, from_email, from_name,
    // imap_host, imap_port, imap_secure, pemilik) -- dibangun dari array supaya jumlah
    // karakter gampang diverifikasi (12 param = 12 elemen).
    $paramTypes = implode('', ['s', 's', 'i', 's', 's', 's', 's', 's', 's', 'i', 's', 's']);

    if ($exists) {
        $stmt = $conn->prepare("UPDATE smtp_setting SET mode = ?, smtp_host = ?, smtp_port = ?, smtp_user = ?, smtp_pass = ?, smtp_secure = ?, smtp_from_email = ?, smtp_from_name = ?, imap_host = ?, imap_port = ?, imap_secure = ? WHERE pemilik = ?");
        if ($stmt) {
            $stmt->bind_param($paramTypes, $mode, $smtpHost, $smtpPort, $smtpUser, $smtpPass, $smtpSecure, $smtpFromEmail, $smtpFromName, $imapHost, $imapPort, $imapSecure, $ceknama);
        }
    } else {
        $stmt = $conn->prepare("INSERT INTO smtp_setting (mode, smtp_host, smtp_port, smtp_user, smtp_pass, smtp_secure, smtp_from_email, smtp_from_name, imap_host, imap_port, imap_secure, pemilik) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param($paramTypes, $mode, $smtpHost, $smtpPort, $smtpUser, $smtpPass, $smtpSecure, $smtpFromEmail, $smtpFromName, $imapHost, $imapPort, $imapSecure, $ceknama);
        }
    }

    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Gagal menyiapkan query: ' . mysqli_error($conn)]);
        exit;
    }
    $ok = $stmt->execute();
    $err = $stmt->error;
    $stmt->close();

    echo json_encode(['success' => (bool)$ok, 'message' => $ok ? 'Pengaturan SMTP tersimpan.' : ('Gagal menyimpan: ' . $err)]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Aksi tidak dikenal.']);
