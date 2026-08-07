<?php
/**
 * mailer_helper.php
 *
 * Helper generik utk kirim email per-tenant (PEMILIK), dua mode:
 * - internal: pakai kredensial default platform (konstanta di bawah),
 *   tenant tidak perlu isi apa2.
 * - external: tenant isi SMTP sendiri (host/port/user/pass/secure/from).
 *
 * Sengaja TIDAK pakai/mengubah notifbot/phpmailer + kredensial yang dipakai
 * forgot.php (fitur lupa password) -- itu privat utk fitur itu, sudah jalan
 * di produksi, tidak boleh disentuh. Helper ini include class PHPMailer yang
 * sama (notifbot/phpmailer/classes/*.php) tapi dengan kredensial default
 * internal miliknya sendiri (SMTP_INTERNAL_* di bawah).
 */

require_once __DIR__ . '/phpmailer/classes/class.phpmailer.php';
require_once __DIR__ . '/phpmailer/classes/class.smtp.php';

if (!defined('SMTP_INTERNAL_HOST')) {
    define('SMTP_INTERNAL_HOST', 'mail.quenbytekniksejahtera.com');
    define('SMTP_INTERNAL_PORT', 587);
    define('SMTP_INTERNAL_SECURE', 'tls');
    define('SMTP_INTERNAL_USER', 'helpdesk@quenbytekniksejahtera.com');
    define('SMTP_INTERNAL_PASS', 'helpdeskqts');
    // Server mail internal (cPanel-style) biasanya expose IMAP di host yang
    // sama, cuma beda port/protokol -- dipakai fitur "Cek Inbox" mode internal.
    define('IMAP_INTERNAL_HOST', 'mail.quenbytekniksejahtera.com');
    define('IMAP_INTERNAL_PORT', 993);
    define('IMAP_INTERNAL_SECURE', 'ssl');
}

function smtpSettingEnsureTable($conn)
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $check = @mysqli_query($conn, "SHOW TABLES LIKE 'smtp_setting'");
    if ($check && mysqli_num_rows($check) === 0) {
        @mysqli_query($conn, "CREATE TABLE smtp_setting (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pemilik VARCHAR(100) NOT NULL,
            mode ENUM('internal','external') NOT NULL DEFAULT 'internal',
            smtp_host VARCHAR(255) DEFAULT '',
            smtp_port INT DEFAULT 587,
            smtp_user VARCHAR(255) DEFAULT '',
            smtp_pass VARCHAR(255) DEFAULT '',
            smtp_secure VARCHAR(10) DEFAULT 'tls',
            smtp_from_email VARCHAR(255) DEFAULT '',
            smtp_from_name VARCHAR(150) DEFAULT '',
            imap_host VARCHAR(255) DEFAULT '',
            imap_port INT DEFAULT 993,
            imap_secure VARCHAR(10) DEFAULT 'ssl',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_pemilik (pemilik)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return;
    }

    // Migrasi idempotent utk instalasi lama yang tabelnya sudah ada duluan
    // sebelum kolom IMAP ditambahkan (pola sama notif_khusus self-heal).
    $imapCols = [
        'imap_host' => "ALTER TABLE smtp_setting ADD COLUMN imap_host VARCHAR(255) DEFAULT ''",
        'imap_port' => "ALTER TABLE smtp_setting ADD COLUMN imap_port INT DEFAULT 993",
        'imap_secure' => "ALTER TABLE smtp_setting ADD COLUMN imap_secure VARCHAR(10) DEFAULT 'ssl'",
    ];
    foreach ($imapCols as $col => $alterSql) {
        $colCheck = @mysqli_query($conn, "SHOW COLUMNS FROM smtp_setting LIKE '$col'");
        if ($colCheck && mysqli_num_rows($colCheck) === 0) {
            @mysqli_query($conn, $alterSql);
        }
    }
}

/**
 * Ambil setting SMTP milik $pemilik. Kalau belum pernah disimpan, kembalikan
 * array default mode internal (TIDAK insert row baru -- baru tersimpan kalau
 * user benar2 klik Simpan di halaman pengaturan).
 */
function smtpSettingGet($conn, $pemilik)
{
    smtpSettingEnsureTable($conn);

    $default = [
        'pemilik' => $pemilik,
        'mode' => 'internal',
        'smtp_host' => '',
        'smtp_port' => 587,
        'smtp_user' => '',
        'smtp_pass' => '',
        'smtp_secure' => 'tls',
        'smtp_from_email' => '',
        'smtp_from_name' => '',
        'imap_host' => '',
        'imap_port' => 993,
        'imap_secure' => 'ssl',
    ];

    $stmt = $conn->prepare("SELECT mode, smtp_host, smtp_port, smtp_user, smtp_pass, smtp_secure, smtp_from_email, smtp_from_name, imap_host, imap_port, imap_secure FROM smtp_setting WHERE pemilik = ? LIMIT 1");
    if (!$stmt) {
        return $default;
    }
    $stmt->bind_param('s', $pemilik);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        return $default;
    }

    return array_merge($default, $row, ['pemilik' => $pemilik]);
}

/**
 * Nama pengirim default utk mode internal -- pakai site_name dari
 * config.json kalau ada, fallback "Notifikasi".
 */
function smtpInternalDefaultFromName()
{
    $configFile = __DIR__ . '/../config.json';
    if (file_exists($configFile)) {
        $config = json_decode((string)file_get_contents($configFile), true);
        if (is_array($config) && !empty($config['site_name'])) {
            return (string)$config['site_name'];
        }
    }
    return 'Notifikasi';
}

/**
 * Kirim satu email. $smtpSettingOverride opsional -- kalau diisi, dipakai
 * langsung tanpa query ulang ke DB (dipakai fitur "Test Kirim" di halaman
 * setting, supaya bisa tes nilai yang SEDANG diketik user, belum tentu
 * sudah tersimpan).
 *
 * Return: ['sent' => bool, 'error' => string]
 */
function sendEmailMessage($conn, $pemilik, $toEmail, $toName, $subject, $htmlBody, $smtpSettingOverride = null)
{
    $toEmail = trim((string)$toEmail);
    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return ['sent' => false, 'error' => 'Alamat email tidak valid.'];
    }

    $setting = is_array($smtpSettingOverride) ? $smtpSettingOverride : smtpSettingGet($conn, $pemilik);
    $mode = (string)($setting['mode'] ?? 'internal');
    $displayName = trim((string)$toName) !== '' ? trim((string)$toName) : 'Pelanggan';

    if ($mode === 'external' && trim((string)($setting['smtp_host'] ?? '')) !== '') {
        $host = trim((string)$setting['smtp_host']);
        $port = (int)($setting['smtp_port'] ?? 587);
        $secure = trim((string)($setting['smtp_secure'] ?? 'tls'));
        $user = trim((string)($setting['smtp_user'] ?? ''));
        $pass = (string)($setting['smtp_pass'] ?? '');
        $fromEmail = trim((string)($setting['smtp_from_email'] ?? '')) !== '' ? trim((string)$setting['smtp_from_email']) : $user;
        $fromName = trim((string)($setting['smtp_from_name'] ?? '')) !== '' ? trim((string)$setting['smtp_from_name']) : smtpInternalDefaultFromName();
        $auth = ($user !== '');
    } else {
        $host = SMTP_INTERNAL_HOST;
        $port = SMTP_INTERNAL_PORT;
        $secure = SMTP_INTERNAL_SECURE;
        $user = SMTP_INTERNAL_USER;
        $pass = SMTP_INTERNAL_PASS;
        $fromEmail = SMTP_INTERNAL_USER;
        $fromName = trim((string)($setting['smtp_from_name'] ?? '')) !== '' ? trim((string)$setting['smtp_from_name']) : smtpInternalDefaultFromName();
        $auth = true;
    }

    if ($host === '') {
        return ['sent' => false, 'error' => 'SMTP host belum diisi.'];
    }

    $mail = new PHPMailer;
    $mail->IsSMTP();
    $mail->Host = $host;
    $mail->Port = $port;
    $mail->SMTPAuth = $auth;
    $mail->SMTPSecure = $secure;
    $mail->SMTPDebug = 0;
    $mail->Timeout = 15;

    if ($auth) {
        $mail->Username = $user;
        $mail->Password = $pass;
    }

    $mail->SetFrom($fromEmail !== '' ? $fromEmail : $user, $fromName);
    $mail->Subject = $subject;
    $mail->AddAddress($toEmail, $displayName);
    $mail->MsgHTML($htmlBody);

    ob_start();
    $sendOk = $mail->Send();
    $rawEcho = trim((string)ob_get_clean());

    if ($sendOk) {
        return ['sent' => true, 'error' => ''];
    }

    $error = trim(trim((string)$mail->ErrorInfo) . ' ' . $rawEcho);
    return ['sent' => false, 'error' => $error !== '' ? $error : 'Gagal mengirim email (tidak diketahui).'];
}

/**
 * Baca beberapa email terbaru dari INBOX via IMAP -- dipakai fitur "Cek Inbox".
 *
 * Sengaja TIDAK pakai ekstensi PHP `imap_*` (tidak bisa dipastikan aktif di
 * server ini, umum dimatikan di hosting shared/cPanel) -- dipakai raw socket
 * IMAP4rev1 client minimal (stream_socket_client), pola sama yang dipakai
 * RainLoop webmail (/email/rainloop) yang sudah terbukti jalan ke mail
 * server yang sama.
 *
 * Return: ['success' => bool, 'error' => string, 'messages' => array, 'total' => int, 'unseen' => int]
 */
function imapCheckInbox($host, $port, $secure, $user, $pass, $limit = 10)
{
    $host = trim((string)$host);
    $result = ['success' => false, 'error' => '', 'messages' => [], 'total' => 0, 'unseen' => 0];
    if ($host === '') {
        $result['error'] = 'IMAP host belum diisi.';
        return $result;
    }
    if (trim((string)$user) === '') {
        $result['error'] = 'IMAP username belum diisi.';
        return $result;
    }

    $port = (int)$port > 0 ? (int)$port : 993;
    $secure = strtolower(trim((string)$secure));
    $transportPrefix = ($secure === 'ssl') ? 'ssl://' : '';
    $remote = $transportPrefix . $host . ':' . $port;

    $ctx = stream_context_create(['ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false,
        'allow_self_signed' => true,
    ]]);

    $errno = 0;
    $errstr = '';
    $sock = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$sock) {
        $result['error'] = "Gagal konek ke $host:$port" . ($errstr !== '' ? " ($errstr)" : '');
        return $result;
    }
    stream_set_timeout($sock, 15);

    // Baca baris greeting server sebelum kirim command pertama.
    fgets($sock, 8192);

    $tagCounter = 0;
    $sendTagged = function ($command) use ($sock, &$tagCounter) {
        $tag = 'A' . (++$tagCounter);
        fwrite($sock, $tag . ' ' . $command . "\r\n");
        return $tag;
    };
    // Baca respons sampai baris bertag ditemukan -- literal {N} (mis. isi header)
    // dibaca persis N byte apa adanya supaya tidak salah tebak batas baris.
    $readTagged = function ($tag) use ($sock) {
        $lines = [];
        while (!feof($sock)) {
            $line = fgets($sock, 8192);
            if ($line === false) {
                break;
            }
            $lines[] = $line;
            if (preg_match('/\{(\d+)\}\r?\n$/', $line, $m)) {
                $need = (int)$m[1];
                $literal = '';
                while (strlen($literal) < $need && !feof($sock)) {
                    $chunk = fread($sock, min(8192, $need - strlen($literal)));
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $literal .= $chunk;
                }
                $lines[] = $literal;
                continue;
            }
            if (strpos($line, $tag . ' ') === 0) {
                break;
            }
        }
        return $lines;
    };

    $userEsc = str_replace(['\\', '"'], ['\\\\', '\\"'], (string)$user);
    $passEsc = str_replace(['\\', '"'], ['\\\\', '\\"'], (string)$pass);

    $tag = $sendTagged('LOGIN "' . $userEsc . '" "' . $passEsc . '"');
    $resp = $readTagged($tag);
    $lastLine = (string)(end($resp) ?: '');
    if (stripos($lastLine, $tag . ' OK') !== 0) {
        fclose($sock);
        $result['error'] = 'Login IMAP gagal -- cek username/password/host.';
        return $result;
    }

    $tag = $sendTagged('SELECT INBOX');
    $resp = $readTagged($tag);
    $total = 0;
    $unseen = 0;
    foreach ($resp as $line) {
        if (preg_match('/^\*\s+(\d+)\s+EXISTS/i', $line, $m)) {
            $total = (int)$m[1];
        }
        if (preg_match('/\*\s+OK\s+\[UNSEEN\s+(\d+)\]/i', $line, $m)) {
            $unseen = (int)$m[1];
        }
    }

    $messages = [];
    if ($total > 0) {
        $start = max(1, $total - (int)$limit + 1);
        for ($i = $total; $i >= $start; $i--) {
            $tag = $sendTagged("FETCH $i (FLAGS BODY.PEEK[HEADER.FIELDS (FROM SUBJECT DATE)])");
            $resp = $readTagged($tag);
            $headerText = '';
            foreach ($resp as $line) {
                // Literal hasil BODY.PEEK ditambahkan apa adanya oleh $readTagged
                // sbg elemen array terpisah -- baris yang bukan "* n FETCH ..."/tag
                // dan mengandung "From:"/"Subject:"/"Date:" itulah isi headernya.
                if (stripos($line, 'From:') !== false || stripos($line, 'Subject:') !== false || stripos($line, 'Date:') !== false) {
                    $headerText .= $line;
                }
            }
            $seen = false;
            foreach ($resp as $line) {
                if (stripos($line, '\\Seen') !== false) {
                    $seen = true;
                    break;
                }
            }

            $from = '';
            $subject = '';
            $date = '';
            if (preg_match('/^From:\s*(.+)$/mi', $headerText, $m)) {
                $from = trim($m[1]);
            }
            if (preg_match('/^Subject:\s*(.+)$/mi', $headerText, $m)) {
                $subject = trim($m[1]);
            }
            if (preg_match('/^Date:\s*(.+)$/mi', $headerText, $m)) {
                $date = trim($m[1]);
            }

            if ($from !== '' || $subject !== '') {
                $messages[] = [
                    'from' => $from,
                    'subject' => $subject !== '' ? $subject : '(tanpa subjek)',
                    'date' => $date,
                    'seen' => $seen,
                ];
            }
        }
    }

    $tag = $sendTagged('LOGOUT');
    $readTagged($tag);
    fclose($sock);

    $result['success'] = true;
    $result['messages'] = $messages;
    $result['total'] = $total;
    $result['unseen'] = $unseen;
    return $result;
}
