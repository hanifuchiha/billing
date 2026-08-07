<?php
header('Content-Type: application/json');
require_once '../koneksibilling.php';
require_once __DIR__ . '/../notifbot/reminder_settings_helper.php';
session_start();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = [];
if (in_array($method, ['POST', 'PUT'], true)) {
    $raw = (string)file_get_contents('php://input');
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
}

function auth_notification_user($conn, $username, $password) {
    $stmt = $conn->prepare('SELECT USERNAME, PASWORD FROM user WHERE USERNAME=? LIMIT 1');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        if (password_verify($password, (string)$row['PASWORD']) || (string)$password === (string)$row['PASWORD']) {
            return (string)$row['USERNAME'];
        }
    }
    return false;
}

function ensure_notif_table_mobile($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS notif_khusus (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pemilik VARCHAR(100) NOT NULL,
        pesan_ketentuan TEXT,
        pesan_disable TEXT,
        pesan_aktif_manual TEXT,
        pesan_remainder_manual TEXT,
        pesan_dismantle_manual TEXT,
        pesan_registrasi TEXT,
        pesan_expired TEXT,
        pesan_reminder TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_pemilik (pemilik)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $required = [
        'pesan_registrasi',
        'pesan_expired',
        'pesan_reminder',
        'pesan_ketentuan',
        'pesan_disable',
        'pesan_aktif_manual',
        'pesan_remainder_manual',
        'pesan_dismantle_manual'
    ];

    $columns = [];
    $res = $conn->query('SHOW COLUMNS FROM notif_khusus');
    while ($res && ($row = $res->fetch_assoc())) {
        $columns[] = (string)$row['Field'];
    }

    foreach ($required as $col) {
        if (!in_array($col, $columns, true)) {
            $conn->query("ALTER TABLE notif_khusus ADD COLUMN `$col` TEXT");
        }
    }
}

function load_json_file_mobile($filePath, $defaultValue = []) {
    if (!is_file($filePath)) {
        return $defaultValue;
    }
    $json = @file_get_contents($filePath);
    if ($json === false || $json === '') {
        return $defaultValue;
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : $defaultValue;
}

function save_json_file_mobile($filePath, $data) {
    $dir = dirname($filePath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return @file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}

try {
    if (!$conn) {
        echo json_encode(['success' => false, 'error' => 'Koneksi DB gagal']);
        exit;
    }

    $username = (string)($input['username'] ?? ($_GET['username'] ?? ''));
    $password = (string)($input['password'] ?? ($_GET['password'] ?? ''));
    $action = strtolower(trim((string)($input['action'] ?? ($_GET['action'] ?? 'load'))));

    $pemilik = auth_notification_user($conn, $username, $password);
    if (!$pemilik) {
        echo json_encode(['success' => false, 'error' => 'Autentikasi gagal']);
        exit;
    }

    ensure_notif_table_mobile($conn);

    if ($method === 'POST' && $action === 'save_messages') {
        $fields = [
            'pesan_registrasi',
            'pesan_expired',
            'pesan_reminder',
            'pesan_ketentuan',
            'pesan_disable',
            'pesan_aktif_manual',
            'pesan_remainder_manual',
            'pesan_dismantle_manual'
        ];

        $values = [];
        foreach ($fields as $f) {
            $values[$f] = (string)($input[$f] ?? '');
        }

        $stmtCheck = $conn->prepare('SELECT id FROM notif_khusus WHERE pemilik=? LIMIT 1');
        $stmtCheck->bind_param('s', $pemilik);
        $stmtCheck->execute();
        $resCheck = $stmtCheck->get_result();

        if ($resCheck && $resCheck->num_rows > 0) {
            $stmt = $conn->prepare('UPDATE notif_khusus SET pesan_registrasi=?, pesan_expired=?, pesan_reminder=?, pesan_ketentuan=?, pesan_disable=?, pesan_aktif_manual=?, pesan_remainder_manual=?, pesan_dismantle_manual=? WHERE pemilik=?');
            $stmt->bind_param(
                'sssssssss',
                $values['pesan_registrasi'],
                $values['pesan_expired'],
                $values['pesan_reminder'],
                $values['pesan_ketentuan'],
                $values['pesan_disable'],
                $values['pesan_aktif_manual'],
                $values['pesan_remainder_manual'],
                $values['pesan_dismantle_manual'],
                $pemilik
            );
            $ok = $stmt->execute();
        } else {
            $stmt = $conn->prepare('INSERT INTO notif_khusus (pesan_registrasi, pesan_expired, pesan_reminder, pesan_ketentuan, pesan_disable, pesan_aktif_manual, pesan_remainder_manual, pesan_dismantle_manual, pemilik) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param(
                'sssssssss',
                $values['pesan_registrasi'],
                $values['pesan_expired'],
                $values['pesan_reminder'],
                $values['pesan_ketentuan'],
                $values['pesan_disable'],
                $values['pesan_aktif_manual'],
                $values['pesan_remainder_manual'],
                $values['pesan_dismantle_manual'],
                $pemilik
            );
            $ok = $stmt->execute();
        }

        echo json_encode(['success' => (bool)$ok]);
        exit;
    }

    if ($method === 'POST' && $action === 'save_advanced') {
        $ownerKey = preg_replace('/[^A-Za-z0-9_.-]/', '_', (string)$pemilik);
        $botReceiverPath = __DIR__ . '/../notifbot/data/bot_receiver_config-' . $ownerKey . '.json';
        $dynamicGreetingPath = __DIR__ . '/../notifbot/data/dynamic_greeting-' . $ownerKey . '.json';

        $botReceiver = [
            'pendaftaran' => (string)($input['receiver_pendaftaran'] ?? ''),
            'server' => (string)($input['receiver_server'] ?? ''),
            'livechat' => (string)($input['receiver_livechat'] ?? ''),
            'system' => (string)($input['receiver_system'] ?? ''),
            'odp_los' => (string)($input['receiver_odp_los'] ?? ''),
            'manual_active' => (string)($input['receiver_manual_active'] ?? ''),
            'provisioning' => (string)($input['receiver_provisioning'] ?? '')
        ];

        $greetingEnabled = !empty($input['dynamic_greeting_enabled']);
        $greetingRaw = (string)($input['dynamic_greeting_list'] ?? '');
        $lines = preg_split('/\r\n|\r|\n/', $greetingRaw);
        $greetings = [];
        if (is_array($lines)) {
            foreach ($lines as $line) {
                $line = trim((string)$line);
                if ($line !== '') {
                    $greetings[] = $line;
                }
            }
        }
        $greetings = array_values(array_unique($greetings));

        $greetingPayload = [
            'enabled' => $greetingEnabled,
            'greetings' => $greetings,
            'owner' => $pemilik,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $okReceiver = save_json_file_mobile($botReceiverPath, $botReceiver);
        $okGreeting = save_json_file_mobile($dynamicGreetingPath, $greetingPayload);
        echo json_encode(['success' => ($okReceiver && $okGreeting)]);
        exit;
    }

    if ($method === 'POST' && $action === 'save_schedule') {
        $jatuhTempo = max(1, min(31, (int)($input['jatuh_tempo'] ?? 28)));
        $tanggalReminder = max(1, min(31, (int)($input['tanggal_reminder'] ?? 25)));
        $waktuReminder = trim((string)($input['waktu_reminder'] ?? '07:00'));
        if (!preg_match('/^\d{2}:\d{2}$/', $waktuReminder)) {
            $waktuReminder = '07:00';
        }
        list($jamReminder, $menitReminder) = explode(':', $waktuReminder, 2);
        $jamReminder = max(0, min(23, (int)$jamReminder));
        $menitReminder = max(0, min(59, (int)$menitReminder));

        $currentYearMonth = date('Y-m');
        $jatuhTempoTs = strtotime($currentYearMonth . '-' . str_pad((string)$jatuhTempo, 2, '0', STR_PAD_LEFT));
        $tanggalReminderTs = strtotime($currentYearMonth . '-' . str_pad((string)$tanggalReminder, 2, '0', STR_PAD_LEFT));
        $hariSebelum = 0;
        if ($jatuhTempoTs !== false && $tanggalReminderTs !== false) {
            $hariSebelum = (int)ceil(($jatuhTempoTs - $tanggalReminderTs) / 86400);
        }

        // Simpan PARTIAL (8 kolom ini saja) via helper -- prorate_untuk_telat/
        // periode_tercatat/kedua filter reminder TIDAK disebut di sini (dikelola
        // paymentset.php / notification.php), jadi tidak ikut tertimpa ke default.
        // Helper ini juga otomatis regenerate mirror notifbot/data/reminder-{pemilik}.json.
        $okSchedule = reminderSettingsSave($conn, (string)$pemilik, [
            'jatuh_tempo' => $jatuhTempo,
            'hari_sebelum' => $hariSebelum,
            'tanggal_reminder' => $tanggalReminder,
            'jam_reminder' => $jamReminder,
            'menit_reminder' => $menitReminder,
            'botname' => (string)($input['botname'] ?? ''),
            'tanggal_awal_tutup_buku' => max(1, min(31, (int)($input['tanggal_awal_tutup_buku'] ?? 1))),
            'tanggal_akhir_tutup_buku' => max(1, min(31, (int)($input['tanggal_akhir_tutup_buku'] ?? 31))),
        ]);

        echo json_encode(['success' => $okSchedule]);
        exit;
    }

    $stmt = $conn->prepare('SELECT pesan_registrasi, pesan_expired, pesan_reminder, pesan_ketentuan, pesan_disable, pesan_aktif_manual, pesan_remainder_manual, pesan_dismantle_manual FROM notif_khusus WHERE pemilik=? LIMIT 1');
    $stmt->bind_param('s', $pemilik);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res && $res->num_rows > 0 ? $res->fetch_assoc() : [];

    $ownerKey = preg_replace('/[^A-Za-z0-9_.-]/', '_', (string)$pemilik);
    $botReceiverPath = __DIR__ . '/../notifbot/data/bot_receiver_config-' . $ownerKey . '.json';
    $dynamicGreetingPath = __DIR__ . '/../notifbot/data/dynamic_greeting-' . $ownerKey . '.json';

    $botReceiver = array_merge([
        'pendaftaran' => '',
        'server' => '',
        'livechat' => '',
        'system' => '',
        'odp_los' => '',
        'manual_active' => '',
        'provisioning' => ''
    ], load_json_file_mobile($botReceiverPath, []));

    $dynamicGreeting = load_json_file_mobile($dynamicGreetingPath, [
        'enabled' => true,
        'greetings' => [
            'Assalamualaikum',
            'Halo',
            'Hai'
        ]
    ]);

    // Fixed Due Date SEKARANG dibaca LANGSUNG dari tabel `reminder_settings` (bukan
    // lagi file JSON) -- reminderSettingsGetRow() return null kalau akun ini belum
    // pernah setting sama sekali (sengaja dipertahankan spy field di response tetap
    // '' /blank utk diisi user, bukan ke-default spt reminderSettingsGet()).
    $reminderRow = reminderSettingsGetRow($conn, (string)$pemilik) ?? [];

    $bots = [];
    $stmtBots = $conn->prepare('SELECT DISTINCT namebot FROM botwa WHERE pemilik=? ORDER BY namebot ASC');
    if ($stmtBots) {
        $stmtBots->bind_param('s', $pemilik);
        $stmtBots->execute();
        $resBots = $stmtBots->get_result();
        while ($resBots && ($bot = $resBots->fetch_assoc())) {
            $namebot = trim((string)($bot['namebot'] ?? ''));
            if ($namebot !== '') {
                $bots[] = $namebot;
            }
        }
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'jatuh_tempo' => (string)($reminderRow['jatuh_tempo'] ?? ''),
            'tanggal_reminder' => (string)($reminderRow['tanggal_reminder'] ?? ''),
            'waktu_reminder' => sprintf('%02d:%02d', (int)($reminderRow['jam_reminder'] ?? 7), (int)($reminderRow['menit_reminder'] ?? 0)),
            'botname' => (string)($reminderRow['botname'] ?? ''),
            'tanggal_awal_tutup_buku' => (string)($reminderRow['tanggal_awal_tutup_buku'] ?? ''),
            'tanggal_akhir_tutup_buku' => (string)($reminderRow['tanggal_akhir_tutup_buku'] ?? ''),
            'bot_options' => $bots,
            'pesan_registrasi' => (string)($row['pesan_registrasi'] ?? ''),
            'pesan_expired' => (string)($row['pesan_expired'] ?? ''),
            'pesan_reminder' => (string)($row['pesan_reminder'] ?? ''),
            'pesan_ketentuan' => (string)($row['pesan_ketentuan'] ?? ''),
            'pesan_disable' => (string)($row['pesan_disable'] ?? ''),
            'pesan_aktif_manual' => (string)($row['pesan_aktif_manual'] ?? ''),
            'pesan_remainder_manual' => (string)($row['pesan_remainder_manual'] ?? ''),
            'pesan_dismantle_manual' => (string)($row['pesan_dismantle_manual'] ?? ''),
            'receiver_pendaftaran' => (string)($botReceiver['pendaftaran'] ?? ''),
            'receiver_server' => (string)($botReceiver['server'] ?? ''),
            'receiver_livechat' => (string)($botReceiver['livechat'] ?? ''),
            'receiver_system' => (string)($botReceiver['system'] ?? ''),
            'receiver_odp_los' => (string)($botReceiver['odp_los'] ?? ''),
            'receiver_manual_active' => (string)($botReceiver['manual_active'] ?? ''),
            'receiver_provisioning' => (string)($botReceiver['provisioning'] ?? ''),
            'dynamic_greeting_enabled' => !empty($dynamicGreeting['enabled']),
            'dynamic_greeting_list' => implode("\n", is_array($dynamicGreeting['greetings'] ?? null) ? $dynamicGreeting['greetings'] : [])
        ]
    ]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
