



<?php

// Buffer semua output dari sini supaya endpoint AJAX (mis. ai_test_action di bawah) bisa
// membuang teks/HTML apapun yang tercetak sebelum titik itu (WAN IP, HTML dari header.php, dll)
// dan mengirim JSON murni ke fetch(), tanpa tergantung setting output_buffering di php.ini.
ob_start();

require 'header.php';
if ($AKSES == 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('Whatsapp_BOT', $akses_menu, true)) {
        echo '<div class="container-fluid py-4"><div class="alert alert-danger">Anda tidak memiliki akses ke menu Whatsapp BOT.</div></div>';
        require 'footer.php';
        exit;
    }
}
 ?>

<?php


$ips = file_get_contents("https://api.ipify.org");

echo "WAN IP: " . $ips;


// Auto-create operational hours table jika belum ada
require_once '../webhook/operational_hours_db_check.php';
?>

    <?php


$isAdminAccess = isset($AKSES) && strtoupper(trim((string)$AKSES)) === 'ADMIN';
$currentPortStart = isset($config['port_start_bot']) ? (int)$config['port_start_bot'] : 3000;
$currentPortEnd = isset($config['port_end_bot']) ? (int)$config['port_end_bot'] : 3999;

// Pastikan toggle menu teknisi tersedia per bot di database.
@$conn->query("ALTER TABLE botwa ADD COLUMN technical_menu_enabled TINYINT(1) NOT NULL DEFAULT 1");
@$conn->query("ALTER TABLE botwa ADD COLUMN allow_read_server TINYINT(1) NOT NULL DEFAULT 1");
@$conn->query("ALTER TABLE botwa ADD COLUMN allow_read_customer TINYINT(1) NOT NULL DEFAULT 1");
@$conn->query("ALTER TABLE botwa ADD COLUMN allow_create_payment_code TINYINT(1) NOT NULL DEFAULT 1");
// Pastikan kolom AI Provider (token/apikey, endpoint, daftar model) tersedia per bot.
@$conn->query("ALTER TABLE botwa ADD COLUMN ai_provider VARCHAR(30) NOT NULL DEFAULT 'cerebras'");
@$conn->query("ALTER TABLE botwa ADD COLUMN ai_api_key VARCHAR(255) NOT NULL DEFAULT ''");
@$conn->query("ALTER TABLE botwa ADD COLUMN ai_base_url VARCHAR(255) NOT NULL DEFAULT ''");
@$conn->query("ALTER TABLE botwa ADD COLUMN ai_models TEXT NULL");
// Pastikan kolom Auto Respon (on/off otomatis + kata trigger dari chat sendiri) tersedia per bot.
@$conn->query("ALTER TABLE botwa ADD COLUMN auto_respon_enabled TINYINT(1) NOT NULL DEFAULT 1");
@$conn->query("ALTER TABLE botwa ADD COLUMN auto_respon_trigger_on VARCHAR(20) NOT NULL DEFAULT '###'");
@$conn->query("ALTER TABLE botwa ADD COLUMN auto_respon_trigger_off VARCHAR(20) NOT NULL DEFAULT '***'");
require_once '../webhook/ai_provider_helper.php';
$aiCatalog = aiProviderCatalog();

$technicalMenuDbPath = __DIR__ . '/../webhook/technical_menu_db.json';
$technicalMenuDbConfig = [
    'host' => 'localhost',
    'user' => 'qts',
    'pass' => '',
    'name' => 'absensi'
];

if (file_exists($technicalMenuDbPath)) {
    $technicalMenuDbRaw = json_decode(file_get_contents($technicalMenuDbPath), true);
    if (is_array($technicalMenuDbRaw)) {
        $technicalMenuDbConfig = array_merge($technicalMenuDbConfig, $technicalMenuDbRaw);
    }
} elseif (isset($config['technical_menu_db']) && is_array($config['technical_menu_db'])) {
    // Fallback kompatibilitas lama jika masih tersimpan di config.json
    $technicalMenuDbConfig = array_merge($technicalMenuDbConfig, $config['technical_menu_db']);
}

if ($currentPortStart < 3000 || $currentPortStart > 3999) {
    $currentPortStart = 3000;
}
if ($currentPortEnd < 3000 || $currentPortEnd > 3999) {
    $currentPortEnd = 3999;
}
if ($currentPortStart > $currentPortEnd) {
    $currentPortStart = 3000;
    $currentPortEnd = 3999;
}

function mikrotikPortExpressionMatches($expression, $targetPort)
{
    $expression = trim((string)$expression);
    $targetPort = (int)$targetPort;

    if ($expression === '' || $targetPort <= 0) {
        return false;
    }

    foreach (explode(',', $expression) as $segment) {
        $segment = trim($segment);
        if ($segment === '') {
            continue;
        }

        if (strpos($segment, '-') !== false) {
            $range = explode('-', $segment, 2);
            $start = (int)trim($range[0]);
            $end = (int)trim($range[1]);
            if ($start > 0 && $end >= $start && $targetPort >= $start && $targetPort <= $end) {
                return true;
            }
            continue;
        }

        if ((int)$segment === $targetPort) {
            return true;
        }
    }

    return false;
}

// Handle tester send message
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['tester_bot_id'], $_POST['tester_phone'], $_POST['tester_message'])) {
    $testerBotId = (int)$_POST['tester_bot_id'];
    $testerPhone = preg_replace('/[^0-9]/', '', $_POST['tester_phone']);
    $testerMessage = trim($_POST['tester_message']);

    if ($testerBotId > 0 && preg_match('/^62\d{8,15}$/', $testerPhone) && $testerMessage !== '') {
        // Ambil data bot
        $stmt = $conn->prepare("SELECT addressbot FROM botwa WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $testerBotId);
        $stmt->execute();
        $res = $stmt->get_result();
        $bot = $res && $res->num_rows > 0 ? $res->fetch_assoc() : null;
        $stmt->close();

        if ($bot && !empty($bot['addressbot'])) {
            $addressBot = rtrim($bot['addressbot'], '/');
            // Ambil namebot, password, dan sender dari database
            $stmt2 = $conn->prepare("SELECT namebot, password, sender FROM botwa WHERE id = ? LIMIT 1");
            $stmt2->bind_param('i', $testerBotId);
            $stmt2->execute();
            $res2 = $stmt2->get_result();
            $botAuth = $res2 && $res2->num_rows > 0 ? $res2->fetch_assoc() : null;
            $stmt2->close();

            $namebot = $botAuth && isset($botAuth['namebot']) ? $botAuth['namebot'] : '';
            $password = $botAuth && isset($botAuth['password']) ? $botAuth['password'] : '';
            $sender = $botAuth && isset($botAuth['sender']) ? $botAuth['sender'] : '';

            // Penyesuaian endpoint dan payload sesuai pengaduan.php/whatsapp_helper
            $sendUrl = $addressBot . '/send/message?session=' . urlencode($namebot);
            $payload = [
                'phone' => $testerPhone,
                'message' => $testerMessage,
                'sender' => $sender,
            ];

            // Gowa multi-device: device_id = isi field Sender apa adanya (nama
            // device yang dibuat di server gowa itu, mis. "hanif", "CS", bukan
            // hasil konversi nomor telepon). Build gowa terbaru wajib
            // X-Device-Id header / device_id query di /send/message.
            $deviceId = trim((string)$sender);
            if ($deviceId !== '') {
                $sendUrl .= '&device_id=' . urlencode($deviceId);
            }

            file_put_contents(__DIR__.'/tester_debug.log', "[".date('Y-m-d H:i:s')."]\nURL: $sendUrl\nPayload: ".json_encode($payload)."\nUserPWD: $namebot:$password\n", FILE_APPEND);


            if (function_exists('curl_init')) {
                $ch = curl_init($sendUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 15);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
                $testerHeaders = [
                    'Content-Type: application/json',
                    'Accept: application/json',
                ];
                if ($deviceId !== '') {
                    $testerHeaders[] = 'X-Device-Id: ' . $deviceId;
                }
                curl_setopt($ch, CURLOPT_HTTPHEADER, $testerHeaders);
                curl_setopt($ch, CURLOPT_USERPWD, "$namebot:$password");

                $rawResponse = curl_exec($ch);
                $curlError = curl_error($ch);
                $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                // Debug log dengan detail lengkap
                $debugInfo = [
                    'timestamp' => date('Y-m-d H:i:s'),
                    'url' => $sendUrl,
                    'http_code' => $httpCode,
                    'curl_error' => $curlError,
                    'payload' => $payload,
                    'response' => $rawResponse,
                    'auth' => "$namebot:***"
                ];
                file_put_contents(__DIR__.'/tester_debug.log', json_encode($debugInfo, JSON_PRETTY_PRINT) . "\n" . str_repeat("=", 80) . "\n", FILE_APPEND);

                if ($rawResponse === false) {
                    echo "❌ Gagal menghubungi bot server: $curlError\n";
                    echo "Pastikan bot server sedang berjalan di: $addressBot";
                } else {
                    $decoded = json_decode($rawResponse, true);
                    $isJson = json_last_error() === JSON_ERROR_NONE;
                    
                    if ($httpCode >= 200 && $httpCode < 300) {
                        echo "✅ Pesan berhasil dikirim!";
                        if ($isJson && isset($decoded['message'])) {
                            echo "\nResponse: " . $decoded['message'];
                        }
                    } else {
                        // Handle error codes lebih detail
                        if ($httpCode === 463) {
                            echo "❌ Error 463 - Kemungkinan:\n";
                            echo "1. Session/Authentication invalid\n";
                            echo "2. Format nomor telepon salah (gunakan 62xxx...)\n";
                            echo "3. Bot server sedang overload\n\n";
                        }
                        
                        if ($isJson && isset($decoded['message'])) {
                            echo "[HTTP $httpCode] " . $decoded['message'];
                        } else {
                            echo "[HTTP $httpCode] Gagal mengirim pesan tes\n";
                            echo "Response: " . substr(strip_tags($rawResponse), 0, 300);
                        }
                    }
                }
            } else {
                echo "❌ Ekstensi cURL tidak tersedia di server";
            }
        } else {
            echo "Bot tidak ditemukan.";
        }
    } else {
        echo "Data tidak valid.";
    }
  
}







// === CLEANUP ORPHANED SERVICES (ADMIN ONLY) ===
if ($isAdminAccess) {
    // Get all botrespon services from systemd
    exec("systemctl list-units --all --type=service --plain --no-legend | grep 'botrespon_' | awk '{print $1}'", $allServices);
    
    if (!empty($allServices)) {
        // Build expected service names from bot address port: botrespon_whatsapp_PORT.service
        $dbBotNames = [];
        $sqlBots = "SELECT addressbot FROM botwa WHERE pemilik = ?";
        $stmtBots = $conn->prepare($sqlBots);
        $stmtBots->bind_param("s", $ceknama);
        $stmtBots->execute();
        $resultBots = $stmtBots->get_result();
        
        while ($rowBot = $resultBots->fetch_assoc()) {
            $addressBot = isset($rowBot['addressbot']) ? (string)$rowBot['addressbot'] : '';
            $parsedAddress = parse_url($addressBot);
            $botPort = isset($parsedAddress['port']) ? (int)$parsedAddress['port'] : 0;
            if ($botPort > 0) {
                $dbBotNames[] = "botrespon_whatsapp_{$botPort}.service";
            }
        }
        
        // Compare and remove orphaned services
        $orphanedServices = array_diff($allServices, $dbBotNames);
        
        foreach ($orphanedServices as $orphanService) {
            if (preg_match('/^botrespon_(.+)\.service$/', $orphanService)) {
                // Stop and disable the service
                exec("sudo systemctl stop " . escapeshellarg($orphanService), $output1, $code1);
                exec("sudo systemctl disable " . escapeshellarg($orphanService), $output2, $code2);
                
                // Remove service file
                $serviceFilePath = "/etc/systemd/system/" . escapeshellarg($orphanService);
                exec("sudo rm -f /etc/systemd/system/" . escapeshellarg($orphanService), $output3, $code3);
                
                // Reload systemd daemon
                exec("sudo systemctl daemon-reload", $output4, $code4);
                
                // Log cleanup activity
                $history_file = "notifbot/data/history-$ceknama.json";
                if (file_exists($history_file)) {
                    $history = json_decode(file_get_contents($history_file), true);
                } else {
                    $history = [];
                }
                
                if (!is_array($history)) $history = [];
                
                $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Auto cleanup: Service $orphanService dihapus (tidak ada di database)";
                file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
            }
        }
    }
// Handle Edit Sender
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['edit_sender']) && isset($_POST['bot_id']) && isset($_POST['sender'])) {
    $botId = (int)$_POST['bot_id'];
    $sender = trim($_POST['sender']);
    if ($botId > 0 && $sender !== '') {
        $updateSenderStmt = $conn->prepare("UPDATE botwa SET sender = ? WHERE id = ? LIMIT 1");
        if ($updateSenderStmt) {
            $updateSenderStmt->bind_param('si', $sender, $botId);
            if ($updateSenderStmt->execute()) {
                $_SESSION['message'] = "Sender berhasil diperbarui.";
            } else {
                $_SESSION['message'] = "Gagal update sender: " . $updateSenderStmt->error;
            }
            $updateSenderStmt->close();
        } else {
            $_SESSION['message'] = "Gagal menyiapkan query update sender.";
        }
    } else {
        $_SESSION['message'] = "ID bot atau sender tidak valid.";
    }
    echo "<script>alert('" . addslashes($_SESSION['message']) . "'); window.location.href='wabot.php';</script>";
    exit;
}
    // Cleanup orphaned webhook response files: botrespon_whatsapp_XXXX.php
    $dbBotResponseFiles = [];
    $sqlAllBots = "SELECT addressbot FROM botwa";
    $resultAllBots = $conn->query($sqlAllBots);
    if ($resultAllBots) {
        while ($row = $resultAllBots->fetch_assoc()) {
            $addressBot = isset($row['addressbot']) ? (string)$row['addressbot'] : '';
            $parsedAddress = parse_url($addressBot);
            $botPort = isset($parsedAddress['port']) ? (int)$parsedAddress['port'] : 0;
            if ($botPort > 0) {
                $dbBotResponseFiles["botrespon_whatsapp_{$botPort}.php"] = true;
            }
        }
    }

    $webhookDir = isset($config['webhook_dir']) ? rtrim((string)$config['webhook_dir'], '/\\') : '';
    if ($webhookDir === '' || !is_dir($webhookDir)) {
        $webhookDir = realpath(__DIR__ . '/../webhook');
    }

    if ($webhookDir && is_dir($webhookDir)) {
        $orphanResponseFiles = glob($webhookDir . DIRECTORY_SEPARATOR . 'botrespon_whatsapp_*.php');
        if (is_array($orphanResponseFiles)) {
            foreach ($orphanResponseFiles as $responseFilePath) {
                $responseFileName = basename($responseFilePath);
                if (!preg_match('/^botrespon_whatsapp_\d+\.php$/', $responseFileName)) {
                    continue;
                }

                if (!isset($dbBotResponseFiles[$responseFileName])) {
                    if (@unlink($responseFilePath)) {
                        $history_file = "notifbot/data/history-$ceknama.json";
                        $history = file_exists($history_file) ? json_decode(file_get_contents($history_file), true) : [];
                        if (!is_array($history)) {
                            $history = [];
                        }
                        $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Auto cleanup: File {$responseFileName} dihapus (bot tidak ada di database)";
                        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
                    }
                }
            }
        }
    }
}
// === END CLEANUP ORPHANED SERVICES ===



// ========== DATABASE & FUNCTION SETTINGS (ADMIN ONLY) ==========
if ($isAdminAccess && $_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['save_database_settings'])) {
    $dbHost = trim($_POST['db_host'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = trim($_POST['db_pass'] ?? '');
    $dbName = trim($_POST['db_name'] ?? '');
    $dbBilling = trim($_POST['db_billing'] ?? '');
    
    if (empty($dbHost) || empty($dbUser) || empty($dbName)) {
        $_SESSION['message'] = "❌ Host, User, dan Database Name wajib diisi!";
    } else {
        // Test koneksi
        $testConn = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
        
        if ($testConn->connect_error) {
            $_SESSION['message'] = "❌ Gagal koneksi database: " . $testConn->connect_error;
        } else {
            $testConn->close();
            
            // Update config.json
            $configPath = '../webhook/config.json';
            $config = file_exists($configPath) ? json_decode(file_get_contents($configPath), true) : [];
            if (!is_array($config)) $config = [];
            
            $config['db_host'] = $dbHost;
            $config['db_user'] = $dbUser;
            $config['db_pass'] = $dbPass;
            $config['db_name'] = $dbName;
            $config['db_billing'] = $dbBilling;
            
            if (file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
                $_SESSION['message'] = "✅ Database settings berhasil disimpan!";
                
                $history_file = "notifbot/data/history-$ceknama.json";
                $history = file_exists($history_file) ? json_decode(file_get_contents($history_file), true) : [];
                if (!is_array($history)) $history = [];
                $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Mengubah pengaturan koneksi database bot menjadi $dbHost / $dbName";
                file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
            } else {
                $_SESSION['message'] = "❌ Gagal menyimpan config file!";
            }
        }
    }
    
    echo "<script>alert('" . addslashes($_SESSION['message']) . "'); window.location.href='wabot.php';</script>";
    exit;
}

// Handle Function Settings (ADMIN ONLY)
if ($isAdminAccess && $_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['save_function_settings'])) {
    $functionName = preg_replace("/[^a-zA-Z0-9_]/", "", $_POST['function_name'] ?? '');
    $functionDesc = trim($_POST['function_desc'] ?? '');
    $functionFile = trim($_POST['function_file'] ?? '');
    $isEnabled = isset($_POST['function_enabled']) && $_POST['function_enabled'] == '1' ? true : false;
    
    if (empty($functionName) || empty($functionFile)) {
        $_SESSION['message'] = "❌ Nama function dan file path wajib diisi!";
    } else {
        $configPath = '../webhook/config.json';
        $config = file_exists($configPath) ? json_decode(file_get_contents($configPath), true) : [];
        if (!is_array($config)) $config = [];
        if (!isset($config['functions'])) $config['functions'] = [];
        
        $config['functions'][$functionName] = [
            'description' => $functionDesc,
            'file' => $functionFile,
            'enabled' => $isEnabled,
            'created_at' => $config['functions'][$functionName]['created_at'] ?? date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        if (file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
            $_SESSION['message'] = "✅ Function settings berhasil disimpan!";
            
            $history_file = "notifbot/data/history-$ceknama.json";
            $history = file_exists($history_file) ? json_decode(file_get_contents($history_file), true) : [];
            if (!is_array($history)) $history = [];
            $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Mengubah pengaturan fungsi bot: $functionName";
            file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
        } else {
            $_SESSION['message'] = "❌ Gagal menyimpan config file!";
        }
    }
    
    echo "<script>alert('" . addslashes($_SESSION['message']) . "'); window.location.href='wabot.php';</script>";
    exit;
}

// Handle Technical Menu Database Settings (ADMIN ONLY)
if ($isAdminAccess && $_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['save_technical_menu_db'])) {
    $techDbHost = trim($_POST['tech_db_host'] ?? '');
    $techDbUser = trim($_POST['tech_db_user'] ?? '');
    $techDbPass = trim($_POST['tech_db_pass'] ?? '');
    $techDbName = trim($_POST['tech_db_name'] ?? '');
    
    if (empty($techDbHost) || empty($techDbUser) || empty($techDbName)) {
        $_SESSION['message'] = "❌ Host, User, dan Database Name wajib diisi!";
    } else {
        // Test koneksi
        $testConn = new mysqli($techDbHost, $techDbUser, $techDbPass, $techDbName);
        
        if ($testConn->connect_error) {
            $_SESSION['message'] = "❌ Gagal koneksi database: " . $testConn->connect_error;
        } else {
            $testConn->close();
            
            // Simpan ke file khusus technical menu DB
            $techDbConfigPath = __DIR__ . '/../webhook/technical_menu_db.json';
            $techDbConfig = [
                'host' => $techDbHost,
                'user' => $techDbUser,
                'pass' => $techDbPass,
                'name' => $techDbName
            ];
            
            if (file_put_contents($techDbConfigPath, json_encode($techDbConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
                $_SESSION['message'] = "✅ Technical Menu Database settings berhasil disimpan!";
                
                $history_file = "notifbot/data/history-$ceknama.json";
                $history = file_exists($history_file) ? json_decode(file_get_contents($history_file), true) : [];
                if (!is_array($history)) $history = [];
                $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Mengubah pengaturan koneksi database Menu Teknisi menjadi $techDbHost / $techDbName";
                file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
            } else {
                $_SESSION['message'] = "❌ Gagal menyimpan technical menu db config file!";
            }
        }
    }
    
    echo "<script>alert('" . addslashes($_SESSION['message']) . "'); window.location.href='wabot.php';</script>";
    exit;
}

// Handle prompt editing
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['nama'])) {
    $nama = preg_replace("/[^a-zA-Z0-9_-]/", "", $_POST['nama']);
    $catatan = trim($_POST['catatan']);
    $filePath = "../webhook/$nama.txt";

    file_put_contents($filePath, $catatan);
    $_SESSION['message'] = "Prompt updated successfully";
}

// Handle operational hours settings
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['save_operational_hours'])) {
    require_once '../webhook/operational_hours_helper.php';
    
    $botname = preg_replace("/[^a-zA-Z0-9_-]/", "", $_POST['bot_operational_name']);
    $enabled = isset($_POST['operational_enabled']) && $_POST['operational_enabled'] == '1' ? true : false;
    $startTime = trim($_POST['operational_start_time']);
    $endTime = trim($_POST['operational_end_time']);
    $timezone = $_POST['operational_timezone'] ?? 'Asia/Jakarta';
    $offlineMode = isset($_POST['operational_offline_mode']) && $_POST['operational_offline_mode'] == '1' ? true : false;
    $messageOutside = trim($_POST['operational_message']);
    
    // Ambil hari-hari yang dipilih
    $days = isset($_POST['operational_days']) && is_array($_POST['operational_days']) ? $_POST['operational_days'] : [];
    
    $hoursConfig = [
        'enabled' => $enabled,
        'start_time' => $startTime,
        'end_time' => $endTime,
        'timezone' => $timezone,
        'days' => $days,
        'message_outside_hours' => $messageOutside,
        'offline_mode' => $offlineMode,
    ];
    
    if (saveOperationalHours($botname, $hoursConfig)) {
        $_SESSION['message'] = "Jam operasional berhasil disimpan!";
        
        $history_file = "notifbot/data/history-$ceknama.json";
        $history = file_exists($history_file) ? json_decode(file_get_contents($history_file), true) : [];
        if (!is_array($history)) $history = [];
        $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Mengubah jam operasional bot $botname";
        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    } else {
        $opErr = !empty($GLOBALS['lastOpHoursError']) ? $GLOBALS['lastOpHoursError'] : ($conn ? $conn->error : 'no DB connection');
        $_SESSION['message'] = "Gagal menyimpan jam operasional! Detail: $opErr";
    }
    
    echo "<script>alert('" . addslashes($_SESSION['message']) . "'); window.location.href='wabot.php';</script>";
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['save_technical_menu_settings'])) {
    $botId = isset($_POST['bot_technical_menu_id']) ? (int)$_POST['bot_technical_menu_id'] : 0;
    $enabled = isset($_POST['technical_menu_enabled']) && $_POST['technical_menu_enabled'] === '1';

    if ($botId <= 0) {
        $_SESSION['message'] = "ID bot tidak valid.";
        echo "<script>alert('" . addslashes($_SESSION['message']) . "'); window.location.href='wabot.php';</script>";
        exit;
    }

    $ownerStmt = $conn->prepare("SELECT namebot, pemilik, created_by_assistant FROM botwa WHERE id = ? LIMIT 1");
    if (!$ownerStmt) {
        $_SESSION['message'] = "Gagal memvalidasi bot.";
        echo "<script>alert('" . addslashes($_SESSION['message']) . "'); window.location.href='wabot.php';</script>";
        exit;
    }

    $ownerStmt->bind_param('i', $botId);
    $ownerStmt->execute();
    $ownerRes = $ownerStmt->get_result();
    $botRow = ($ownerRes && $ownerRes->num_rows > 0) ? $ownerRes->fetch_assoc() : null;
    $ownerStmt->close();

    if (!$botRow) {
        $_SESSION['message'] = "Bot tidak ditemukan.";
        echo "<script>alert('" . addslashes($_SESSION['message']) . "'); window.location.href='wabot.php';</script>";
        exit;
    }

    $botOwner = trim((string)($botRow['pemilik'] ?? ''));
    $botname = trim((string)($botRow['namebot'] ?? ''));
    if ($botname === '') {
        $_SESSION['message'] = "Nama bot tidak ditemukan.";
        echo "<script>alert('" . addslashes($_SESSION['message']) . "'); window.location.href='wabot.php';</script>";
        exit;
    }

    // Sama seperti sebelumnya (admin ATAU pemilik akun) DITAMBAH: khusus
    // ASSISTANT, bot itu jg harus di-assign ke dia ATAU dibuat sendiri olehnya
    // (lihat notifbot/bot_access_helper.php) -- sebelumnya cek ini SELALU
    // lolos utk semua assistant krn $ceknama = username OWNER, bukan
    // assistant itu sendiri, jadi tidak pernah benar2 membedakan antar-assistant.
    $botManageAllowed = $isAdminAccess || strtoupper($botOwner) === strtoupper((string)$ceknama);
    if ($botManageAllowed && $AKSES === 'ASSISTANT') {
        $botManageAllowed = botAccessCanManage($AKSES, $assigned_bot_ids ?? [], $asistant_name ?? '', array_merge($botRow, ['id' => $botId]));
    }
    if (!$botManageAllowed) {
        $_SESSION['message'] = "Anda tidak berhak mengubah menu teknisi bot ini.";
        echo "<script>alert('" . addslashes($_SESSION['message']) . "'); window.location.href='wabot.php';</script>";
        exit;
    }

    $updateStmt = $conn->prepare("UPDATE botwa SET technical_menu_enabled = ? WHERE id = ? LIMIT 1");
    if ($updateStmt) {
        $enabledInt = $enabled ? 1 : 0;
        $updateStmt->bind_param('ii', $enabledInt, $botId);
    }

    if ($updateStmt && $updateStmt->execute()) {
        $updateStmt->close();
        $_SESSION['message'] = "Menu teknisi bot " . $botname . " berhasil di" . ($enabled ? 'aktifkan' : 'nonaktifkan') . ".";

        $history_file = "notifbot/data/history-$ceknama.json";
        $history = file_exists($history_file) ? json_decode(file_get_contents($history_file), true) : [];
        if (!is_array($history)) {
            $history = [];
        }
        $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Menu Teknisi bot $botname di" . ($enabled ? 'aktifkan' : 'nonaktifkan');
        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    } else {
        if ($updateStmt) {
            $updateStmt->close();
        }
        $_SESSION['message'] = "Gagal menyimpan pengaturan menu teknisi ke database.";
    }

    echo "<script>alert('" . addslashes($_SESSION['message']) . "'); window.location.href='wabot.php';</script>";
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['save_bot_access_permissions'])) {
    $botId = isset($_POST['bot_access_id']) ? (int)$_POST['bot_access_id'] : 0;
    $allowReadServer = isset($_POST['allow_read_server']) && $_POST['allow_read_server'] === '1' ? 1 : 0;
    $allowReadCustomer = isset($_POST['allow_read_customer']) && $_POST['allow_read_customer'] === '1' ? 1 : 0;
    $allowCreatePayment = isset($_POST['allow_create_payment_code']) && $_POST['allow_create_payment_code'] === '1' ? 1 : 0;

    if ($botId <= 0) {
        $_SESSION['message'] = "ID bot tidak valid.";
        echo "<script>alert('" . addslashes($_SESSION['message']) . "'); window.location.href='wabot.php';</script>";
        exit;
    }

    $ownerStmt = $conn->prepare("SELECT namebot, pemilik, created_by_assistant FROM botwa WHERE id = ? LIMIT 1");
    if (!$ownerStmt) {
        $_SESSION['message'] = "Gagal memvalidasi data bot.";
        echo "<script>alert('" . addslashes($_SESSION['message']) . "'); window.location.href='wabot.php';</script>";
        exit;
    }

    $ownerStmt->bind_param('i', $botId);
    $ownerStmt->execute();
    $ownerRes = $ownerStmt->get_result();
    $botRow = ($ownerRes && $ownerRes->num_rows > 0) ? $ownerRes->fetch_assoc() : null;
    $ownerStmt->close();

    if (!$botRow) {
        $_SESSION['message'] = "Bot tidak ditemukan.";
        echo "<script>alert('" . addslashes($_SESSION['message']) . "'); window.location.href='wabot.php';</script>";
        exit;
    }

    $botOwner = trim((string)($botRow['pemilik'] ?? ''));
    $botname = trim((string)($botRow['namebot'] ?? ''));

    $botManageAllowed = $isAdminAccess || strtoupper($botOwner) === strtoupper((string)$ceknama);
    if ($botManageAllowed && $AKSES === 'ASSISTANT') {
        $botManageAllowed = botAccessCanManage($AKSES, $assigned_bot_ids ?? [], $asistant_name ?? '', array_merge($botRow, ['id' => $botId]));
    }
    if (!$botManageAllowed) {
        $_SESSION['message'] = "Anda tidak berhak mengubah akses bot ini.";
        echo "<script>alert('" . addslashes($_SESSION['message']) . "'); window.location.href='wabot.php';</script>";
        exit;
    }

    $updateStmt = $conn->prepare("UPDATE botwa SET allow_read_server = ?, allow_read_customer = ?, allow_create_payment_code = ? WHERE id = ? LIMIT 1");
    if (!$updateStmt) {
        $_SESSION['message'] = "Gagal menyimpan akses bot.";
        echo "<script>alert('" . addslashes($_SESSION['message']) . "'); window.location.href='wabot.php';</script>";
        exit;
    }

    $updateStmt->bind_param('iiii', $allowReadServer, $allowReadCustomer, $allowCreatePayment, $botId);
    if ($updateStmt->execute()) {
        $_SESSION['message'] = "Akses bot *$botname* berhasil diperbarui.";

        $history_file = "notifbot/data/history-$ceknama.json";
        $history = file_exists($history_file) ? json_decode(file_get_contents($history_file), true) : [];
        if (!is_array($history)) {
            $history = [];
        }
        $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Mengubah hak akses bot $botname: lihat data server " . ($allowReadServer ? 'diizinkan' : 'tidak diizinkan') . ", lihat data pelanggan " . ($allowReadCustomer ? 'diizinkan' : 'tidak diizinkan') . ", buat kode bayar " . ($allowCreatePayment ? 'diizinkan' : 'tidak diizinkan');
        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    } else {
        $_SESSION['message'] = "Gagal update akses bot.";
    }
    $updateStmt->close();

    echo "<script>alert('" . addslashes($_SESSION['message']) . "'); window.location.href='wabot.php';</script>";
    exit;
}

// Handle AI Provider settings (provider, API key/token, endpoint, daftar model) per bot
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['save_ai_settings'])) {
    $botId = isset($_POST['bot_ai_id']) ? (int)$_POST['bot_ai_id'] : 0;
    $aiCatalog = aiProviderCatalog();
    $aiProvider = strtolower(trim((string)($_POST['ai_provider'] ?? '')));
    if (!array_key_exists($aiProvider, $aiCatalog)) {
        $aiProvider = 'cerebras';
    }
    $aiApiKey = trim((string)($_POST['ai_api_key'] ?? ''));
    $aiBaseUrl = trim((string)($_POST['ai_base_url'] ?? ''));
    $aiModelsRaw = (string)($_POST['ai_models'] ?? '');
    $aiModelsArr = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $aiModelsRaw)), function ($m) {
        return $m !== '';
    }));
    $aiModelsJson = json_encode($aiModelsArr, JSON_UNESCAPED_UNICODE);

    if ($botId <= 0) {
        $_SESSION['message'] = "ID bot tidak valid.";
        echo "<script>alert('" . addslashes($_SESSION['message']) . "'); window.location.href='wabot.php';</script>";
        exit;
    }

    $ownerStmt = $conn->prepare("SELECT namebot, pemilik, created_by_assistant FROM botwa WHERE id = ? LIMIT 1");
    if (!$ownerStmt) {
        $_SESSION['message'] = "Gagal memvalidasi data bot.";
        echo "<script>alert('" . addslashes($_SESSION['message']) . "'); window.location.href='wabot.php';</script>";
        exit;
    }

    $ownerStmt->bind_param('i', $botId);
    $ownerStmt->execute();
    $ownerRes = $ownerStmt->get_result();
    $botRow = ($ownerRes && $ownerRes->num_rows > 0) ? $ownerRes->fetch_assoc() : null;
    $ownerStmt->close();

    if (!$botRow) {
        $_SESSION['message'] = "Bot tidak ditemukan.";
        echo "<script>alert('" . addslashes($_SESSION['message']) . "'); window.location.href='wabot.php';</script>";
        exit;
    }

    $botOwner = trim((string)($botRow['pemilik'] ?? ''));
    $botname = trim((string)($botRow['namebot'] ?? ''));

    $botManageAllowed = $isAdminAccess || strtoupper($botOwner) === strtoupper((string)$ceknama);
    if ($botManageAllowed && $AKSES === 'ASSISTANT') {
        $botManageAllowed = botAccessCanManage($AKSES, $assigned_bot_ids ?? [], $asistant_name ?? '', array_merge($botRow, ['id' => $botId]));
    }
    if (!$botManageAllowed) {
        $_SESSION['message'] = "Anda tidak berhak mengubah pengaturan AI bot ini.";
        echo "<script>alert('" . addslashes($_SESSION['message']) . "'); window.location.href='wabot.php';</script>";
        exit;
    }

    $updateStmt = $conn->prepare("UPDATE botwa SET ai_provider = ?, ai_api_key = ?, ai_base_url = ?, ai_models = ? WHERE id = ? LIMIT 1");
    if (!$updateStmt) {
        $_SESSION['message'] = "Gagal menyimpan pengaturan AI Provider.";
        echo "<script>alert('" . addslashes($_SESSION['message']) . "'); window.location.href='wabot.php';</script>";
        exit;
    }

    $updateStmt->bind_param('ssssi', $aiProvider, $aiApiKey, $aiBaseUrl, $aiModelsJson, $botId);
    if ($updateStmt->execute()) {
        $_SESSION['message'] = "Pengaturan AI Provider bot *$botname* berhasil disimpan.";

        $history_file = "notifbot/data/history-$ceknama.json";
        $history = file_exists($history_file) ? json_decode(file_get_contents($history_file), true) : [];
        if (!is_array($history)) {
            $history = [];
        }
        $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Mengubah pengaturan AI Provider bot $botname menjadi " . $aiCatalog[$aiProvider]['label'];
        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    } else {
        $_SESSION['message'] = "Gagal update pengaturan AI Provider.";
    }
    $updateStmt->close();

    echo "<script>alert('" . addslashes($_SESSION['message']) . "'); window.location.href='wabot.php';</script>";
    exit;
}

// Handle Auto Respon settings (ON/OFF otomatis + kata trigger dari chat bot sendiri)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['save_auto_respon_settings'])) {
    $botId = isset($_POST['bot_autorespon_id']) ? (int)$_POST['bot_autorespon_id'] : 0;
    $autoResponEnabledPost = isset($_POST['auto_respon_enabled']) ? 1 : 0;
    $triggerOnPost = trim((string)($_POST['auto_respon_trigger_on'] ?? ''));
    $triggerOffPost = trim((string)($_POST['auto_respon_trigger_off'] ?? ''));

    if ($botId <= 0) {
        $_SESSION['message'] = "ID bot tidak valid.";
        echo "<script>alert('" . addslashes($_SESSION['message']) . "'); window.location.href='wabot.php';</script>";
        exit;
    }

    if ($triggerOnPost === '' || $triggerOffPost === '') {
        $_SESSION['message'] = "Kata trigger ON dan OFF wajib diisi.";
        echo "<script>alert('" . addslashes($_SESSION['message']) . "'); window.location.href='wabot.php';</script>";
        exit;
    }

    if (strcasecmp($triggerOnPost, $triggerOffPost) === 0) {
        $_SESSION['message'] = "Kata trigger ON dan OFF tidak boleh sama.";
        echo "<script>alert('" . addslashes($_SESSION['message']) . "'); window.location.href='wabot.php';</script>";
        exit;
    }

    $triggerOnPost = mb_substr($triggerOnPost, 0, 20);
    $triggerOffPost = mb_substr($triggerOffPost, 0, 20);

    $ownerStmt = $conn->prepare("SELECT namebot, pemilik, created_by_assistant FROM botwa WHERE id = ? LIMIT 1");
    if (!$ownerStmt) {
        $_SESSION['message'] = "Gagal memvalidasi data bot.";
        echo "<script>alert('" . addslashes($_SESSION['message']) . "'); window.location.href='wabot.php';</script>";
        exit;
    }

    $ownerStmt->bind_param('i', $botId);
    $ownerStmt->execute();
    $ownerRes = $ownerStmt->get_result();
    $botRow = ($ownerRes && $ownerRes->num_rows > 0) ? $ownerRes->fetch_assoc() : null;
    $ownerStmt->close();

    if (!$botRow) {
        $_SESSION['message'] = "Bot tidak ditemukan.";
        echo "<script>alert('" . addslashes($_SESSION['message']) . "'); window.location.href='wabot.php';</script>";
        exit;
    }

    $botOwner = trim((string)($botRow['pemilik'] ?? ''));
    $botname = trim((string)($botRow['namebot'] ?? ''));

    $botManageAllowed = $isAdminAccess || strtoupper($botOwner) === strtoupper((string)$ceknama);
    if ($botManageAllowed && $AKSES === 'ASSISTANT') {
        $botManageAllowed = botAccessCanManage($AKSES, $assigned_bot_ids ?? [], $asistant_name ?? '', array_merge($botRow, ['id' => $botId]));
    }
    if (!$botManageAllowed) {
        $_SESSION['message'] = "Anda tidak berhak mengubah pengaturan Auto Respon bot ini.";
        echo "<script>alert('" . addslashes($_SESSION['message']) . "'); window.location.href='wabot.php';</script>";
        exit;
    }

    $updateStmt = $conn->prepare("UPDATE botwa SET auto_respon_enabled = ?, auto_respon_trigger_on = ?, auto_respon_trigger_off = ? WHERE id = ? LIMIT 1");
    if (!$updateStmt) {
        $_SESSION['message'] = "Gagal menyimpan pengaturan Auto Respon.";
        echo "<script>alert('" . addslashes($_SESSION['message']) . "'); window.location.href='wabot.php';</script>";
        exit;
    }

    $updateStmt->bind_param('issi', $autoResponEnabledPost, $triggerOnPost, $triggerOffPost, $botId);
    if ($updateStmt->execute()) {
        $_SESSION['message'] = "Pengaturan Auto Respon bot *$botname* berhasil disimpan.";

        $history_file = "notifbot/data/history-$ceknama.json";
        $history = file_exists($history_file) ? json_decode(file_get_contents($history_file), true) : [];
        if (!is_array($history)) {
            $history = [];
        }
        $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Mengubah pengaturan Auto Respon bot $botname: status " . ($autoResponEnabledPost ? 'AKTIF' : 'NONAKTIF') . ", trigger ON \"$triggerOnPost\", trigger OFF \"$triggerOffPost\"";
        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    } else {
        $_SESSION['message'] = "Gagal update pengaturan Auto Respon.";
    }
    $updateStmt->close();

    echo "<script>alert('" . addslashes($_SESSION['message']) . "'); window.location.href='wabot.php';</script>";
    exit;
}

// Handle Menu 7 (Laporan Gangguan) Template
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['save_menu7_template'])) {
    require_once '../webhook/menu_config_helper.php';
    
    $botname = preg_replace("/[^a-zA-Z0-9_-]/", "", $_POST['botname_menu7']);
    $template = trim($_POST['menu7_template']);
    $messageNotReg = trim($_POST['menu7_not_registered']);
    
    if (saveMenu7Config($botname, $template, $messageNotReg)) {
        $_SESSION['message'] = "✅ Menu 7 (Laporan Gangguan) berhasil disimpan!";
        
        $history_file = "notifbot/data/history-$ceknama.json";
        $history = file_exists($history_file) ? json_decode(file_get_contents($history_file), true) : [];
        if (!is_array($history)) $history = [];
        $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Mengubah template Menu 7 (Laporan Gangguan) untuk bot $botname";
        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    } else {
        $_SESSION['message'] = "❌ Gagal menyimpan menu 7!";
    }
    
    echo "<script>alert('" . $_SESSION['message'] . "'); window.location.href='wabot.php';</script>";
    exit;
}

// Handle Menu 10 (Data Pelanggan) Template
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['save_menu10_template'])) {
    require_once '../webhook/menu_config_helper.php';
    
    $botname = preg_replace("/[^a-zA-Z0-9_-]/", "", $_POST['botname_menu10']);
    $template = trim($_POST['menu10_template']);
    $messageNotReg = trim($_POST['menu10_not_registered']);
    
    if (saveMenu10Config($botname, $template, $messageNotReg)) {
        $_SESSION['message'] = "✅ Menu 10 (Data Pelanggan) berhasil disimpan!";
        
        $history_file = "notifbot/data/history-$ceknama.json";
        $history = file_exists($history_file) ? json_decode(file_get_contents($history_file), true) : [];
        if (!is_array($history)) $history = [];
        $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Mengubah template Menu 10 (Data Pelanggan) untuk bot $botname";
        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    } else {
        $_SESSION['message'] = "❌ Gagal menyimpan menu 10!";
    }
    
    echo "<script>alert('" . $_SESSION['message'] . "'); window.location.href='wabot.php';</script>";
    exit;
}

// Handle menu settings (ADMIN only)
if ($isAdminAccess && $_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['save_menu_settings'])) {
    require_once '../webhook/menu_config_helper.php';
    
    $botname = preg_replace("/[^a-zA-Z0-9_-]/", "", $_POST['botname_menu']);
    $main_menu_text = trim($_POST['main_menu_text']);
    
    // Parse menu list dari POST
    $menuConfig = [
        'main_menu_text' => $main_menu_text,
        'menu_list' => []
    ];
    
    // Loop melalui setiap menu item
    if (isset($_POST['menu_numbers']) && is_array($_POST['menu_numbers'])) {
        foreach ($_POST['menu_numbers'] as $index => $menuNumber) {
            $menuNumber = trim($menuNumber);
            if (!empty($menuNumber)) {
                $actionType = $_POST['menu_action_types'][$index] ?? 'text';
                
                $menuConfig['menu_list'][$menuNumber] = [
                    'label' => $_POST['menu_labels'][$index] ?? '',
                    'enabled' => isset($_POST['menu_enabled'][$index]) && $_POST['menu_enabled'][$index] == '1',
                    'action_type' => $actionType,
                    'action_function' => $actionType === 'function' ? ($_POST['menu_action_functions'][$index] ?? '') : '',
                    'message' => $actionType === 'text' ? ($_POST['menu_messages'][$index] ?? '') : ''
                ];
            }
        }
    }
    
    // Simpan konfigurasi
    if (saveMenuConfig($botname, $menuConfig)) {
        $_SESSION['message'] = "Menu settings berhasil disimpan!";
        
        // Log activity
        $history_file = "notifbot/data/history-$ceknama.json";
        if (file_exists($history_file)) {
            $history = json_decode(file_get_contents($history_file), true);
        } else {
            $history = [];
        }
        if (!is_array($history)) $history = [];
        $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Mengubah pengaturan menu bot $botname";
        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    } else {
        $_SESSION['message'] = "Gagal menyimpan menu settings!";
    }
    
    echo "<script>alert('" . $_SESSION['message'] . "'); window.location.href='wabot.php';</script>";
    exit;
}

// Handle bot port range settings (ADMIN only)
if ($isAdminAccess && $_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['save_port_range_settings'])) {
    $portStart = isset($_POST['port_start_bot']) ? (int)$_POST['port_start_bot'] : 3000;
    $portEnd = isset($_POST['port_end_bot']) ? (int)$_POST['port_end_bot'] : 3999;

    if ($portStart < 3000 || $portStart > 3999 || $portEnd < 3000 || $portEnd > 3999 || $portStart > $portEnd) {
        $_SESSION['message'] = "Port range tidak valid. Gunakan rentang 3000-3999 dan pastikan Start <= End.";
        echo "<script>alert('" . $_SESSION['message'] . "'); window.location.href='wabot.php';</script>";
        exit;
    }

    $configPath = __DIR__ . '/config.json';
    $latestConfig = file_exists($configPath) ? json_decode(file_get_contents($configPath), true) : [];
    if (!is_array($latestConfig)) {
        $latestConfig = [];
    }

    $latestConfig['port_start_bot'] = $portStart;
    $latestConfig['port_end_bot'] = $portEnd;

    $saveOk = file_put_contents($configPath, json_encode($latestConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    if ($saveOk !== false) {
        $_SESSION['message'] = "Port range bot berhasil disimpan: {$portStart}-{$portEnd}";

        $history_file = "notifbot/data/history-$ceknama.json";
        $history = file_exists($history_file) ? json_decode(file_get_contents($history_file), true) : [];
        if (!is_array($history)) {
            $history = [];
        }
        $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Mengubah rentang port bot menjadi {$portStart}-{$portEnd}";
        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    } else {
        $_SESSION['message'] = "Gagal menyimpan port range bot.";
    }

    echo "<script>alert('" . $_SESSION['message'] . "'); window.location.href='wabot.php';</script>";
    exit;
}

// Proxy API action supaya tidak kena CORS / mixed-content saat UI https memanggil bot http.
if ($_SERVER["REQUEST_METHOD"] === "GET" && isset($_GET['bot_api_action'])) {
    header('Content-Type: application/json; charset=utf-8');

    $allowedActions = [
        'login' => '/app/login',
        'login-with-code' => '/app/login-with-code',
        'logout' => '/app/logout',
        'reconnect' => '/app/reconnect',
    ];

    $action = isset($_GET['bot_api_action']) ? strtolower(trim((string)$_GET['bot_api_action'])) : '';
    $addressBot = isset($_GET['addressbot']) ? trim((string)$_GET['addressbot']) : '';
    $deviceId = isset($_GET['device_id']) ? trim((string)$_GET['device_id']) : '';
    $phone = isset($_GET['phone']) ? trim((string)$_GET['phone']) : '';

    if (!isset($allowedActions[$action])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Aksi bot tidak valid']);
        exit;
    }

    if ($addressBot === '' || !filter_var($addressBot, FILTER_VALIDATE_URL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Address bot tidak valid']);
        exit;
    }

    $scheme = strtolower((string)parse_url($addressBot, PHP_URL_SCHEME));
    if ($scheme !== 'http' && $scheme !== 'https') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Scheme URL bot harus http atau https']);
        exit;
    }

    if ($deviceId === '') {
        try {
            $deviceId = 'device_' . bin2hex(random_bytes(6));
        } catch (Exception $e) {
            $deviceId = 'device_' . uniqid();
        }
    }

    $baseUrl = rtrim($addressBot, '/');
    $targetUrl = $baseUrl . $allowedActions[$action];

    if ($action === 'login-with-code') {
        if ($phone === '' || !preg_match('/^\d{10,15}$/', preg_replace('/\D/', '', $phone))) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Nomor telepon tidak valid']);
            exit;
        }
        $targetUrl .= '?phone=' . urlencode(preg_replace('/\D/', '', $phone));
    }

    if (!function_exists('curl_init')) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Ekstensi cURL tidak tersedia di server']);
        exit;
    }

    $ch = curl_init($targetUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Device-Id: ' . $deviceId,
        ],
    ]);

    $rawResponse = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($rawResponse === false) {
        http_response_code(502);
        echo json_encode([
            'success' => false,
            'message' => 'Gagal menghubungi bot server',
            'error' => $curlError,
            'target_url' => $targetUrl,
        ]);
        exit;
    }

    $decoded = json_decode($rawResponse, true);
    $isJson = json_last_error() === JSON_ERROR_NONE;

    echo json_encode([
        'success' => $httpCode >= 200 && $httpCode < 300,
        'http_code' => $httpCode,
        'target_url' => $targetUrl,
        'device_id' => $deviceId,
        'upstream_json' => $isJson ? $decoded : null,
        'upstream_raw' => $isJson ? null : $rawResponse,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Tes AI Provider per bot (dipanggil AJAX dari modal "Tes AI" di aiTesterModal<id>).
// Memanggil provider AI yang benar-benar tersimpan di DB untuk bot ini, pakai system prompt
// yang sama seperti mesin bot produksi (crm/webhook/botrespon.php), tanpa perlu kirim WA asli.
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['ai_test_action'])) {
    // Buang output text/HTML apapun yang sudah tercetak sebelum titik ini (mis. echo WAN IP di
    // atas header.php) supaya body response benar-benar JSON murni untuk fetch() di modal Tes AI.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    $botId = isset($_POST['ai_test_bot_id']) ? (int)$_POST['ai_test_bot_id'] : 0;
    $testMessage = trim((string)($_POST['ai_test_message'] ?? ''));

    if ($botId <= 0 || $testMessage === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Bot dan pesan tes wajib diisi']);
        exit;
    }

    $stmt = $conn->prepare('SELECT namebot, pemilik, created_by_assistant, ai_provider, ai_api_key, ai_base_url, ai_models FROM botwa WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $botId);
    $stmt->execute();
    $botRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$botRow) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Bot tidak ditemukan']);
        exit;
    }

    $botManageAllowed = $isAdminAccess || strtoupper(trim((string)$botRow['pemilik'])) === strtoupper((string)$ceknama);
    if ($botManageAllowed && $AKSES === 'ASSISTANT') {
        $botManageAllowed = botAccessCanManage($AKSES, $assigned_bot_ids ?? [], $asistant_name ?? '', array_merge($botRow, ['id' => $botId]));
    }
    if (!$botManageAllowed) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Anda tidak berhak menguji AI bot ini']);
        exit;
    }

    $promptFile = __DIR__ . '/../webhook/' . $botRow['namebot'] . '.txt';
    if (is_file($promptFile) && filesize($promptFile) > 0) {
        $systemPrompt = file_get_contents($promptFile);
    } else {
        $systemPrompt = "Kamu adalah asisten Customer Service profesional. Jawablah pertanyaan pelanggan WhatsApp dengan singkat, jelas, dan sopan.";
    }

    $botAiConfig = [
        'ai_provider' => $botRow['ai_provider'],
        'ai_api_key' => $botRow['ai_api_key'],
        'ai_base_url' => $botRow['ai_base_url'],
        'ai_models' => $botRow['ai_models'],
    ];

    $startTime = microtime(true);
    $aiResponse = aiChatComplete($botAiConfig, $systemPrompt, $testMessage);
    $elapsedMs = (int)round((microtime(true) - $startTime) * 1000);

    $isError = strpos($aiResponse, '❌') === 0;
    echo json_encode([
        'success' => !$isError,
        'response' => $aiResponse,
        'elapsed_ms' => $elapsedMs,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
// INTEGRASI WHATSAPP API RESMI (Meta Cloud API / Qiscus / Custom)
// ============================================================
$waResmiPemilik = $ceknama;

$waResmiProviderMeta = [
    'meta_cloud_api' => ['label' => 'Meta WhatsApp Cloud API (Resmi Langsung)', 'group' => 'cloud', 'base_url' => 'https://graph.facebook.com'],
    'qiscus'         => ['label' => 'Qiscus (Omnichannel Chat)', 'group' => 'qiscus', 'base_url' => 'https://omnichannel.qiscus.com'],
    'custom'         => ['label' => 'Custom / Lainnya (REST Generic)', 'group' => 'custom', 'base_url' => ''],
];

$waResmiGatewayBaseUrl = !empty($config['webhook_url']) ? rtrim($config['webhook_url'], '/') : (
    (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/crm/webhook'
);

$waResmiFlash = null;
$waResmiIntegrasiList = [];
$waResmiBotList = [];
$waResmiSetupError = null;

// Seluruh blok ini dibungkus try/catch supaya kalau ada error (mis. tabel
// belum sempat terbuat, query gagal, dsb) TIDAK menjatuhkan seluruh halaman
// wabot.php jadi blank/500 — cukup fitur WA Resmi yang menampilkan pesan error.
try {

require_once '../webhook/wa_resmi_db_check.php';
require_once '../webhook/wa_resmi_helper.php';

// ---- Simpan (Tambah/Edit) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_integrasi'])) {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $provider = isset($_POST['provider']) && isset($waResmiProviderMeta[$_POST['provider']]) ? $_POST['provider'] : '';
    $namaIntegrasi = trim((string)($_POST['nama_integrasi'] ?? ''));

    if ($provider === '' || $namaIntegrasi === '') {
        $_SESSION['wa_resmi_message'] = "Nama integrasi dan penyedia wajib diisi.";
    } else {
        $fields = [
            'base_url' => trim((string)($_POST['base_url'] ?? '')),
            'api_version' => trim((string)($_POST['api_version'] ?? '')),
            'phone_number_id' => trim((string)($_POST['phone_number_id'] ?? '')),
            'waba_id' => trim((string)($_POST['waba_id'] ?? '')),
            'sender_number' => trim((string)($_POST['sender_number'] ?? '')),
            'access_token' => trim((string)($_POST['access_token'] ?? '')),
            'api_key' => trim((string)($_POST['api_key'] ?? '')),
            'app_id' => trim((string)($_POST['app_id'] ?? '')),
            'channel_id' => trim((string)($_POST['channel_id'] ?? '')),
            'auth_header_type' => trim((string)($_POST['auth_header_type'] ?? 'bearer')),
            'auth_header_name' => trim((string)($_POST['auth_header_name'] ?? 'Authorization')),
            'use_template_for_notif' => isset($_POST['use_template_for_notif']) ? 1 : 0,
            'template_name' => trim((string)($_POST['template_name'] ?? '')),
            'template_language' => trim((string)($_POST['template_language'] ?? 'id')),
            'custom_endpoint_path' => trim((string)($_POST['custom_endpoint_path'] ?? '')),
            'custom_body_template' => trim((string)($_POST['custom_body_template'] ?? '')),
        ];

        if ($id > 0) {
            $sql = "UPDATE integrasi_whatsapp_resmi SET nama_integrasi=?, provider=?, base_url=?, api_version=?, phone_number_id=?, waba_id=?, sender_number=?, access_token=?, api_key=?, app_id=?, channel_id=?, auth_header_type=?, auth_header_name=?, use_template_for_notif=?, template_name=?, template_language=?, custom_endpoint_path=?, custom_body_template=? WHERE id=? AND pemilik=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param(
                'sssssssssssssissssis',
                $namaIntegrasi,
                $provider,
                $fields['base_url'],
                $fields['api_version'],
                $fields['phone_number_id'],
                $fields['waba_id'],
                $fields['sender_number'],
                $fields['access_token'],
                $fields['api_key'],
                $fields['app_id'],
                $fields['channel_id'],
                $fields['auth_header_type'],
                $fields['auth_header_name'],
                $fields['use_template_for_notif'],
                $fields['template_name'],
                $fields['template_language'],
                $fields['custom_endpoint_path'],
                $fields['custom_body_template'],
                $id,
                $waResmiPemilik
            );
            $stmt->execute();
            $stmt->close();
            $_SESSION['wa_resmi_message'] = "Integrasi '$namaIntegrasi' berhasil diperbarui.";
        } else {
            $sql = "INSERT INTO integrasi_whatsapp_resmi (pemilik, nama_integrasi, provider, base_url, api_version, phone_number_id, waba_id, sender_number, access_token, api_key, app_id, channel_id, auth_header_type, auth_header_name, use_template_for_notif, template_name, template_language, custom_endpoint_path, custom_body_template) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param(
                'ssssssssssssssissss',
                $waResmiPemilik,
                $namaIntegrasi,
                $provider,
                $fields['base_url'],
                $fields['api_version'],
                $fields['phone_number_id'],
                $fields['waba_id'],
                $fields['sender_number'],
                $fields['access_token'],
                $fields['api_key'],
                $fields['app_id'],
                $fields['channel_id'],
                $fields['auth_header_type'],
                $fields['auth_header_name'],
                $fields['use_template_for_notif'],
                $fields['template_name'],
                $fields['template_language'],
                $fields['custom_endpoint_path'],
                $fields['custom_body_template']
            );
            $stmt->execute();
            $stmt->close();
            $_SESSION['wa_resmi_message'] = "Integrasi '$namaIntegrasi' berhasil ditambahkan. Silakan aktifkan agar mulai dipakai mengirim notif.";
        }
    }
    echo "<script>alert('" . addslashes($_SESSION['wa_resmi_message']) . "'); window.location.href='wabot.php';</script>";
    exit;
}

// ---- Aktifkan (alihkan bot) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['activate_integrasi'])) {
    $id = (int)($_POST['id'] ?? 0);
    $targetBotwaId = (int)($_POST['target_botwa_id'] ?? 0);
    $newBotName = trim((string)($_POST['new_bot_name'] ?? ''));

    $chk = $conn->prepare("SELECT id FROM integrasi_whatsapp_resmi WHERE id=? AND pemilik=? LIMIT 1");
    $chk->bind_param('is', $id, $waResmiPemilik);
    $chk->execute();
    $owned = $chk->get_result()->num_rows > 0;
    $chk->close();

    if (!$owned) {
        $_SESSION['wa_resmi_message'] = "Integrasi tidak ditemukan.";
    } else {
        $result = wr_activate_integration($conn, $id, $targetBotwaId, $newBotName, $waResmiGatewayBaseUrl);
        $_SESSION['wa_resmi_message'] = $result['success']
            ? "Integrasi diaktifkan. Semua notifikasi WA untuk akun ini sekarang lewat WA resmi."
            : "Gagal mengaktifkan: " . ($result['error'] ?? 'unknown error');
    }
    echo "<script>alert('" . addslashes($_SESSION['wa_resmi_message']) . "'); window.location.href='wabot.php';</script>";
    exit;
}

// ---- Nonaktifkan ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deactivate_integrasi'])) {
    $id = (int)($_POST['id'] ?? 0);
    $chk = $conn->prepare("SELECT id FROM integrasi_whatsapp_resmi WHERE id=? AND pemilik=? LIMIT 1");
    $chk->bind_param('is', $id, $waResmiPemilik);
    $chk->execute();
    $owned = $chk->get_result()->num_rows > 0;
    $chk->close();

    if ($owned) {
        $result = wr_deactivate_integration($conn, $id);
        $_SESSION['wa_resmi_message'] = $result['success']
            ? "Integrasi dinonaktifkan. Bot dikembalikan ke pengaturan sebelumnya (jika ada)."
            : "Gagal menonaktifkan: " . ($result['error'] ?? 'unknown error');
    } else {
        $_SESSION['wa_resmi_message'] = "Integrasi tidak ditemukan.";
    }
    echo "<script>alert('" . addslashes($_SESSION['wa_resmi_message']) . "'); window.location.href='wabot.php';</script>";
    exit;
}

// ---- Hapus ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_integrasi'])) {
    $id = (int)($_POST['id'] ?? 0);
    $chk = $conn->prepare("SELECT status FROM integrasi_whatsapp_resmi WHERE id=? AND pemilik=? LIMIT 1");
    $chk->bind_param('is', $id, $waResmiPemilik);
    $chk->execute();
    $row = $chk->get_result()->fetch_assoc();
    $chk->close();

    if ($row) {
        if ((int)$row['status'] === 1) {
            wr_deactivate_integration($conn, $id);
        }
        $del = $conn->prepare("DELETE FROM integrasi_whatsapp_resmi WHERE id=? AND pemilik=?");
        $del->bind_param('is', $id, $waResmiPemilik);
        $del->execute();
        $del->close();
        $_SESSION['wa_resmi_message'] = "Integrasi dihapus.";
    } else {
        $_SESSION['wa_resmi_message'] = "Integrasi tidak ditemukan.";
    }
    echo "<script>alert('" . addslashes($_SESSION['wa_resmi_message']) . "'); window.location.href='wabot.php';</script>";
    exit;
}

// ---- Test kirim pesan ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_send_integrasi'])) {
    $id = (int)($_POST['id'] ?? 0);
    $testPhone = trim((string)($_POST['test_phone'] ?? ''));

    $stmt = $conn->prepare("SELECT * FROM integrasi_whatsapp_resmi WHERE id=? AND pemilik=? LIMIT 1");
    $stmt->bind_param('is', $id, $waResmiPemilik);
    $stmt->execute();
    $integrasi = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$integrasi) {
        $_SESSION['wa_resmi_message'] = "Integrasi tidak ditemukan.";
    } elseif ($testPhone === '') {
        $_SESSION['wa_resmi_message'] = "Nomor tujuan test tidak boleh kosong.";
    } else {
        $result = wr_send_message($integrasi, $testPhone, "Tes koneksi WhatsApp API Resmi dari billing " . date('Y-m-d H:i:s'));

        $upd = $conn->prepare("UPDATE integrasi_whatsapp_resmi SET last_test_at = NOW(), last_test_status = ?, last_test_message = ? WHERE id = ?");
        $statusTxt = $result['success'] ? 'sukses' : 'gagal';
        $msgTxt = $result['success'] ? 'Terkirim' : ($result['error'] ?? 'Gagal');
        $upd->bind_param('ssi', $statusTxt, $msgTxt, $id);
        $upd->execute();
        $upd->close();

        $_SESSION['wa_resmi_message'] = $result['success']
            ? "Test pesan berhasil terkirim ke $testPhone."
            : "Test pesan gagal: " . ($result['error'] ?? 'unknown error');
    }
    echo "<script>alert('" . addslashes($_SESSION['wa_resmi_message']) . "'); window.location.href='wabot.php';</script>";
    exit;
}

if (isset($_SESSION['wa_resmi_message'])) {
    $waResmiFlash = $_SESSION['wa_resmi_message'];
    unset($_SESSION['wa_resmi_message']);
}

$waResmiListStmt = $conn->prepare("SELECT * FROM integrasi_whatsapp_resmi WHERE pemilik = ? ORDER BY status DESC, id DESC");
if ($waResmiListStmt) {
    $waResmiListStmt->bind_param('s', $waResmiPemilik);
    $waResmiListStmt->execute();
    $waResmiIntegrasiList = $waResmiListStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $waResmiListStmt->close();
}

// Bot yang tampil ke ASSISTANT dibatasi ke bot yang di-assign owner ATAU bot
// buatan sendiri assistant ini -- lihat notifbot/bot_access_helper.php.
$waResmiBotListStmt = $conn->prepare("SELECT id, namebot, addressbot, tipe_bot FROM botwa WHERE pemilik = ?" . botAccessWhereClause($conn, $AKSES, $assigned_bot_ids ?? [], $asistant_name ?? '') . " ORDER BY namebot ASC");
if ($waResmiBotListStmt) {
    $waResmiBotListStmt->bind_param('s', $waResmiPemilik);
    $waResmiBotListStmt->execute();
    $waResmiBotList = $waResmiBotListStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $waResmiBotListStmt->close();
}

} catch (Throwable $waResmiEx) {
    error_log('[WA_RESMI] Gagal memuat/memproses integrasi WA resmi: ' . $waResmiEx->getMessage());
    $waResmiSetupError = $waResmiEx->getMessage();
}
// ============================================================
// END INTEGRASI WHATSAPP API RESMI
// ============================================================

// ============================================================
// BOT WHATSAPP LAIN (Fonnte / Wablas / UltraMsg / Evolution API / Gowa Eksternal / Custom)
// ============================================================
$waUnofficialPemilik = $ceknama;

$waUnofficialProviderMeta = [
    'fonnte'        => ['label' => 'Fonnte', 'group' => 'fonnte', 'base_url' => 'https://api.fonnte.com'],
    'wablas'        => ['label' => 'Wablas', 'group' => 'wablas', 'base_url' => 'https://wablas.com'],
    'ultramsg'      => ['label' => 'UltraMsg', 'group' => 'ultramsg', 'base_url' => 'https://api.ultramsg.com'],
    'evolution_api' => ['label' => 'Evolution API (Self-Hosted)', 'group' => 'evolution', 'base_url' => ''],
    'gowa_external' => ['label' => 'Gowa Server Eksternal (Sudah Ada)', 'group' => 'gowaext', 'base_url' => ''],
    'custom'        => ['label' => 'Custom / Lainnya (REST Generic)', 'group' => 'customu', 'base_url' => ''],
];

$waUnofficialGatewayBaseUrl = !empty($config['webhook_url']) ? rtrim($config['webhook_url'], '/') : (
    (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/crm/webhook'
);

$waUnofficialFlash = null;
$waUnofficialIntegrasiList = [];
$waUnofficialBotList = [];
$waUnofficialSetupError = null;

try {

require_once '../webhook/wa_unofficial_db_check.php';
require_once '../webhook/wa_unofficial_helper.php';

// ---- Simpan (Tambah/Edit) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_integrasi_unofficial'])) {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $provider = isset($_POST['provider']) && isset($waUnofficialProviderMeta[$_POST['provider']]) ? $_POST['provider'] : '';
    $namaIntegrasi = trim((string)($_POST['nama_integrasi'] ?? ''));

    if ($provider === '' || $namaIntegrasi === '') {
        $_SESSION['wa_unofficial_message'] = "Nama integrasi dan penyedia wajib diisi.";
    } else {
        $fields = [
            'base_url' => trim((string)($_POST['base_url'] ?? '')),
            'api_token' => trim((string)($_POST['api_token'] ?? '')),
            'secret_key' => trim((string)($_POST['secret_key'] ?? '')),
            'instance_id' => trim((string)($_POST['instance_id'] ?? '')),
            'sender_number' => trim((string)($_POST['sender_number'] ?? '')),
            'auth_header_type' => trim((string)($_POST['auth_header_type'] ?? 'bearer')),
            'auth_header_name' => trim((string)($_POST['auth_header_name'] ?? 'Authorization')),
            'custom_endpoint_path' => trim((string)($_POST['custom_endpoint_path'] ?? '')),
            'custom_body_template' => trim((string)($_POST['custom_body_template'] ?? '')),
            'gowa_version' => ($_POST['gowa_version'] ?? '') === 'multi_device' ? 'multi_device' : 'legacy',
        ];

        if ($id > 0) {
            $sql = "UPDATE integrasi_whatsapp_unofficial SET nama_integrasi=?, provider=?, base_url=?, api_token=?, secret_key=?, instance_id=?, sender_number=?, auth_header_type=?, auth_header_name=?, custom_endpoint_path=?, custom_body_template=?, gowa_version=? WHERE id=? AND pemilik=?";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new RuntimeException("Gagal menyiapkan query update: " . $conn->error . ". Pastikan tabel integrasi_whatsapp_unofficial sudah terbentuk dan user database punya izin CREATE/ALTER TABLE.");
            }
            $stmt->bind_param(
                'ssssssssssssis',
                $namaIntegrasi,
                $provider,
                $fields['base_url'],
                $fields['api_token'],
                $fields['secret_key'],
                $fields['instance_id'],
                $fields['sender_number'],
                $fields['auth_header_type'],
                $fields['auth_header_name'],
                $fields['custom_endpoint_path'],
                $fields['custom_body_template'],
                $fields['gowa_version'],
                $id,
                $waUnofficialPemilik
            );
            $stmt->execute();
            $stmt->close();

            // Jika integrasi sedang aktif, perubahan session/alamat/password
            // harus langsung disalin ke botwa. Tanpa ini pengirim lama tetap
            // memakai kredensial sebelumnya dan GOWA membalas HTTP 401.
            $activeStmt = wu_prepare_or_throw($conn, "SELECT status, target_botwa_id FROM integrasi_whatsapp_unofficial WHERE id=? AND pemilik=? LIMIT 1");
            $activeStmt->bind_param('is', $id, $waUnofficialPemilik);
            $activeStmt->execute();
            $activeIntegration = $activeStmt->get_result()->fetch_assoc();
            $activeStmt->close();

            if ($activeIntegration && (int)$activeIntegration['status'] === 1 && (int)$activeIntegration['target_botwa_id'] > 0) {
                $syncResult = wu_activate_integration(
                    $conn,
                    $id,
                    (int)$activeIntegration['target_botwa_id'],
                    '',
                    $waUnofficialGatewayBaseUrl
                );
                if (!$syncResult['success']) {
                    throw new RuntimeException('Integrasi tersimpan tetapi gagal disinkronkan ke bot aktif: ' . ($syncResult['error'] ?? 'unknown error'));
                }
            }

            $_SESSION['wa_unofficial_message'] = "Integrasi '$namaIntegrasi' berhasil diperbarui dan konfigurasi bot aktif sudah disinkronkan.";
        } else {
            $sql = "INSERT INTO integrasi_whatsapp_unofficial (pemilik, nama_integrasi, provider, base_url, api_token, secret_key, instance_id, sender_number, auth_header_type, auth_header_name, custom_endpoint_path, custom_body_template, gowa_version) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new RuntimeException("Gagal menyiapkan query tambah: " . $conn->error . ". Pastikan tabel integrasi_whatsapp_unofficial sudah terbentuk dan user database punya izin CREATE/ALTER TABLE.");
            }
            $stmt->bind_param(
                'sssssssssssss',
                $waUnofficialPemilik,
                $namaIntegrasi,
                $provider,
                $fields['base_url'],
                $fields['api_token'],
                $fields['secret_key'],
                $fields['instance_id'],
                $fields['sender_number'],
                $fields['auth_header_type'],
                $fields['auth_header_name'],
                $fields['custom_endpoint_path'],
                $fields['custom_body_template'],
                $fields['gowa_version']
            );
            $stmt->execute();
            $stmt->close();
            $_SESSION['wa_unofficial_message'] = "Integrasi '$namaIntegrasi' berhasil ditambahkan. Silakan aktifkan agar mulai dipakai mengirim notif.";
        }
    }
    echo "<script>alert('" . addslashes($_SESSION['wa_unofficial_message']) . "'); window.location.href='wabot.php';</script>";
    exit;
}

// ---- Aktifkan (alihkan bot) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['activate_integrasi_unofficial'])) {
    $id = (int)($_POST['id'] ?? 0);
    $targetBotwaId = (int)($_POST['target_botwa_id'] ?? 0);
    $newBotName = trim((string)($_POST['new_bot_name'] ?? ''));

    $chk = wu_prepare_or_throw($conn, "SELECT id FROM integrasi_whatsapp_unofficial WHERE id=? AND pemilik=? LIMIT 1");
    $chk->bind_param('is', $id, $waUnofficialPemilik);
    $chk->execute();
    $owned = $chk->get_result()->num_rows > 0;
    $chk->close();

    if (!$owned) {
        $_SESSION['wa_unofficial_message'] = "Integrasi tidak ditemukan.";
    } else {
        $result = wu_activate_integration($conn, $id, $targetBotwaId, $newBotName, $waUnofficialGatewayBaseUrl);
        $_SESSION['wa_unofficial_message'] = $result['success']
            ? "Integrasi diaktifkan. Semua notifikasi WA untuk bot ini sekarang lewat layanan ini."
            : "Gagal mengaktifkan: " . ($result['error'] ?? 'unknown error');
    }
    echo "<script>alert('" . addslashes($_SESSION['wa_unofficial_message']) . "'); window.location.href='wabot.php';</script>";
    exit;
}

// ---- Nonaktifkan ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deactivate_integrasi_unofficial'])) {
    $id = (int)($_POST['id'] ?? 0);
    $chk = wu_prepare_or_throw($conn, "SELECT id FROM integrasi_whatsapp_unofficial WHERE id=? AND pemilik=? LIMIT 1");
    $chk->bind_param('is', $id, $waUnofficialPemilik);
    $chk->execute();
    $owned = $chk->get_result()->num_rows > 0;
    $chk->close();

    if ($owned) {
        $result = wu_deactivate_integration($conn, $id);
        $_SESSION['wa_unofficial_message'] = $result['success']
            ? "Integrasi dinonaktifkan. Bot dikembalikan ke pengaturan sebelumnya (jika ada)."
            : "Gagal menonaktifkan: " . ($result['error'] ?? 'unknown error');
    } else {
        $_SESSION['wa_unofficial_message'] = "Integrasi tidak ditemukan.";
    }
    echo "<script>alert('" . addslashes($_SESSION['wa_unofficial_message']) . "'); window.location.href='wabot.php';</script>";
    exit;
}

// ---- Hapus ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_integrasi_unofficial'])) {
    $id = (int)($_POST['id'] ?? 0);
    $chk = wu_prepare_or_throw($conn, "SELECT status FROM integrasi_whatsapp_unofficial WHERE id=? AND pemilik=? LIMIT 1");
    $chk->bind_param('is', $id, $waUnofficialPemilik);
    $chk->execute();
    $row = $chk->get_result()->fetch_assoc();
    $chk->close();

    if ($row) {
        if ((int)$row['status'] === 1) {
            wu_deactivate_integration($conn, $id);
        }
        $del = wu_prepare_or_throw($conn, "DELETE FROM integrasi_whatsapp_unofficial WHERE id=? AND pemilik=?");
        $del->bind_param('is', $id, $waUnofficialPemilik);
        $del->execute();
        $del->close();
        $_SESSION['wa_unofficial_message'] = "Integrasi dihapus.";
    } else {
        $_SESSION['wa_unofficial_message'] = "Integrasi tidak ditemukan.";
    }
    echo "<script>alert('" . addslashes($_SESSION['wa_unofficial_message']) . "'); window.location.href='wabot.php';</script>";
    exit;
}

// ---- Test kirim pesan ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_send_integrasi_unofficial'])) {
    $id = (int)($_POST['id'] ?? 0);
    $testPhone = trim((string)($_POST['test_phone'] ?? ''));

    $stmt = wu_prepare_or_throw($conn, "SELECT * FROM integrasi_whatsapp_unofficial WHERE id=? AND pemilik=? LIMIT 1");
    $stmt->bind_param('is', $id, $waUnofficialPemilik);
    $stmt->execute();
    $integrasi = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$integrasi) {
        $_SESSION['wa_unofficial_message'] = "Integrasi tidak ditemukan.";
    } elseif ($testPhone === '') {
        $_SESSION['wa_unofficial_message'] = "Nomor tujuan test tidak boleh kosong.";
    } else {
        $result = wu_send_message($integrasi, $testPhone, "Tes koneksi Bot WhatsApp Lain dari billing " . date('Y-m-d H:i:s'));

        $upd = wu_prepare_or_throw($conn, "UPDATE integrasi_whatsapp_unofficial SET last_test_at = NOW(), last_test_status = ?, last_test_message = ? WHERE id = ?");
        $statusTxt = $result['success'] ? 'sukses' : 'gagal';
        $msgTxt = $result['success'] ? 'Terkirim' : ($result['error'] ?? 'Gagal');
        $upd->bind_param('ssi', $statusTxt, $msgTxt, $id);
        $upd->execute();
        $upd->close();

        $_SESSION['wa_unofficial_message'] = $result['success']
            ? "Test pesan berhasil terkirim ke $testPhone."
            : "Test pesan gagal: " . ($result['error'] ?? 'unknown error');
    }
    echo "<script>alert('" . addslashes($_SESSION['wa_unofficial_message']) . "'); window.location.href='wabot.php';</script>";
    exit;
}

if (isset($_SESSION['wa_unofficial_message'])) {
    $waUnofficialFlash = $_SESSION['wa_unofficial_message'];
    unset($_SESSION['wa_unofficial_message']);
}

$waUnofficialListStmt = $conn->prepare("SELECT * FROM integrasi_whatsapp_unofficial WHERE pemilik = ? ORDER BY status DESC, id DESC");
if ($waUnofficialListStmt) {
    $waUnofficialListStmt->bind_param('s', $waUnofficialPemilik);
    $waUnofficialListStmt->execute();
    $waUnofficialIntegrasiList = $waUnofficialListStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $waUnofficialListStmt->close();
}

// Bot yang tampil ke ASSISTANT dibatasi ke bot yang di-assign owner ATAU bot
// buatan sendiri assistant ini -- lihat notifbot/bot_access_helper.php.
$waUnofficialBotListStmt = $conn->prepare("SELECT id, namebot, addressbot, tipe_bot FROM botwa WHERE pemilik = ?" . botAccessWhereClause($conn, $AKSES, $assigned_bot_ids ?? [], $asistant_name ?? '') . " ORDER BY namebot ASC");
if ($waUnofficialBotListStmt) {
    $waUnofficialBotListStmt->bind_param('s', $waUnofficialPemilik);
    $waUnofficialBotListStmt->execute();
    $waUnofficialBotList = $waUnofficialBotListStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $waUnofficialBotListStmt->close();
}

} catch (Throwable $waUnofficialEx) {
    error_log('[WA_UNOFFICIAL] Gagal memuat/memproses bot alternatif: ' . $waUnofficialEx->getMessage());
    $waUnofficialSetupError = $waUnofficialEx->getMessage();
}
// ============================================================
// END BOT WHATSAPP LAIN
// ============================================================


    $ok = isset($_GET["statusnotif"]) ? $_GET["statusnotif"] : '';
    if ($ok == "Server sibuk atau nama bot sudah ada") {
    ?>
        <div class="alert alert-danger" role="alert">
            <?php echo $_GET["statusnotif"] ?>
        </div>
    <?php
    }
    ?>
    <?php
    $ok = isset($_GET["statusnotif"]) ? $_GET["statusnotif"] : '';
    if ($ok == "success") {
    ?>
        <div class="alert alert-success" role="alert">
            Success adding BOT
        </div>
    <?php
    }
    ?>












                    </div>
                  <div class="row" id="wadirectSection">
        <div class="col-12">
            <div class="card mb-4">



        <div class="row">
            <div class="col-12">
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center flex-wrap gap-2 wabot-toolbar" style="padding: 12px 15px; font-weight: 600;">
                        <h6 class="mb-0" style="font-size: 1em; font-weight: 600;"><i class="fas fa-robot me-2"></i>BOT WhatsApp Internal billing ( UNOFFICIAL WA API ) </h6>
                        <div class="d-flex gap-2 flex-wrap wabot-toolbar-actions">
                            <?php if ($isAdminAccess): ?>
                            <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#databaseSettingsModal" style="font-weight: 500;">
                                <i class="fas fa-database me-1"></i> Database Settings
                            </button>
                            <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#functionSettingsModal" style="font-weight: 500;">
                                <i class="fas fa-cog me-1"></i> Function Settings
                            </button>
                            <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#technicalMenuDbModal" style="font-weight: 500;">
                                <i class="fas fa-tools me-1"></i> Technical Menu DB
                            </button>
                            <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#portRangeModal" style="font-weight: 500;">
                                <i class="fas fa-network-wired me-1"></i> Port Settings
                            </button>
                            <?php endif; ?>
                          
                            <!-- Button trigger modal -->
                            <button type="button" class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#dataModal" style="font-weight: 500;">
                                <i class="fas fa-plus me-1"></i> Add Bot
                            </button>
                        </div>
                    </div>
                        <?php if ($isAdminAccess): ?>
                        <!-- DATABASE SETTINGS MODAL -->
                        <div class="modal fade" id="databaseSettingsModal" tabindex="-1" aria-labelledby="databaseSettingsModalLabel" aria-hidden="true">
                            <div class="modal-dialog modal-lg">
                                <div class="modal-content">
                                    <form method="post">
                                        <div class="modal-header bg-primary text-white">
                                            <h5 class="modal-title" id="databaseSettingsModalLabel">
                                                <i class="fas fa-database me-2"></i>Database Settings
                                            </h5>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body">
                                            <input type="hidden" name="save_database_settings" value="1">
                                            
                                            <div class="alert alert-warning">
                                                <strong>⚠️ Hati-hati!</strong> Pengaturan database akan mempengaruhi semua fungsi bot.
                                                Pastikan credentials benar sebelum menyimpan.
                                            </div>
                                            
                                            <div class="form-group mb-3">
                                                <label class="form-label fw-bold">Database Host</label>
                                                <input type="text" class="form-control" name="db_host" 
                                                       value="<?= $config['db_host'] ?? 'localhost' ?>" 
                                                       placeholder="localhost atau IP" required>
                                                <small class="text-muted">Contoh: localhost, 192.168.1.10, atau domain.com</small>
                                            </div>
                                            
                                            <div class="form-group mb-3">
                                                <label class="form-label fw-bold">Database User</label>
                                                <input type="text" class="form-control" name="db_user" 
                                                       value="<?= $config['db_user'] ?? '' ?>" 
                                                       placeholder="root atau username" required>
                                            </div>
                                            
                                            <div class="form-group mb-3">
                                                <label class="form-label fw-bold">Database Password</label>
                                                <input type="password" class="form-control" name="db_pass" 
                                                       value="<?= $config['db_pass'] ?? '' ?>" 
                                                       placeholder="password database">
                                            </div>
                                            
                                            <div class="form-group mb-3">
                                                <label class="form-label fw-bold">Database Name (Untuk Query Umum)</label>
                                                <input type="text" class="form-control" name="db_name" 
                                                       value="<?= $config['db_name'] ?? 'absensi' ?>" 
                                                       placeholder="absensi" required>
                                                <small class="text-muted">Database utama untuk query umum bot</small>
                                            </div>
                                            
                                            <div class="form-group mb-3">
                                                <label class="form-label fw-bold">Database Name (Untuk Billing/Pelanggan)</label>
                                                <input type="text" class="form-control" name="db_billing" 
                                                       value="<?= $config['db_billing'] ?? 'Mybillingq' ?>" 
                                                       placeholder="Mybillingq">
                                                <small class="text-muted">Database untuk data pelanggan (Menu 7 & 10)</small>
                                            </div>
                                            
                                            <hr>
                                            
                                            <div class="alert alert-info">
                                                <strong>ℹ️ Database yang sedang digunakan:</strong><br>
                                                Host: <code><?= $config['db_host'] ?? 'localhost' ?></code><br>
                                                User: <code><?= $config['db_user'] ?? 'N/A' ?></code><br>
                                                Main DB: <code><?= $config['db_name'] ?? 'absensi' ?></code><br>
                                                Billing DB: <code><?= $config['db_billing'] ?? 'Mybillingq' ?></code>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                            <button type="submit" class="btn btn-primary">💾 Simpan Database response Settings</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        
                        <!-- TECHNICAL MENU DATABASE SETTINGS MODAL -->
                        <div class="modal fade" id="technicalMenuDbModal" tabindex="-1" aria-labelledby="technicalMenuDbModalLabel" aria-hidden="true">
                            <div class="modal-dialog modal-lg">
                                <div class="modal-content">
                                    <form method="post">
                                        <div class="modal-header bg-info text-white">
                                            <h5 class="modal-title" id="technicalMenuDbModalLabel">
                                                <i class="fas fa-tools me-2"></i>Technical Menu Database Settings
                                            </h5>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body">
                                            <input type="hidden" name="save_technical_menu_db" value="1">
                                            
                                            <div class="alert alert-info">
                                                <strong>ℹ️ Database khusus untuk Menu Teknis</strong><br>
                                                Pengaturan ini hanya mempengaruhi menu tiket teknis (#menuteknis, #tiketbaru, #updatetiket, dll).
                                            </div>
                                            
                                            <div class="form-group mb-3">
                                                <label class="form-label fw-bold">Database Host</label>
                                                <input type="text" class="form-control" name="tech_db_host" 
                                                       value="<?= $technicalMenuDbConfig['host'] ?? 'localhost' ?>" 
                                                       placeholder="localhost atau IP" required>
                                                <small class="text-muted">Contoh: localhost, 192.168.1.10</small>
                                            </div>
                                            
                                            <div class="form-group mb-3">
                                                <label class="form-label fw-bold">Database User</label>
                                                <input type="text" class="form-control" name="tech_db_user" 
                                                       value="<?= $technicalMenuDbConfig['user'] ?? 'qts' ?>" 
                                                       placeholder="username database" required>
                                            </div>
                                            
                                            <div class="form-group mb-3">
                                                <label class="form-label fw-bold">Database Password</label>
                                                <input type="password" class="form-control" name="tech_db_pass" 
                                                       value="<?= $technicalMenuDbConfig['pass'] ?? '' ?>" 
                                                       placeholder="password database">
                                            </div>
                                            
                                            <div class="form-group mb-3">
                                                <label class="form-label fw-bold">Database Name</label>
                                                <input type="text" class="form-control" name="tech_db_name" 
                                                       value="<?= $technicalMenuDbConfig['name'] ?? 'absensi' ?>" 
                                                       placeholder="absensi" required>
                                                <small class="text-muted">Database untuk tabel tickets, employees, job_lists, materials</small>
                                            </div>
                                            
                                            <hr>
                                            
                                            <div class="alert alert-success">
                                                <strong>✅ Konfigurasi saat ini:</strong><br>
                                                Host: <code><?= $technicalMenuDbConfig['host'] ?? 'localhost' ?></code><br>
                                                User: <code><?= $technicalMenuDbConfig['user'] ?? 'qts' ?></code><br>
                                                Database: <code><?= $technicalMenuDbConfig['name'] ?? 'absensi' ?></code>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                            <button type="submit" class="btn btn-info">💾 Simpan Technical Menu DB Settings</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        
                        <!-- FUNCTION SETTINGS MODAL -->
                        <div class="modal fade" id="functionSettingsModal" tabindex="-1" aria-labelledby="functionSettingsModalLabel" aria-hidden="true">
                            <div class="modal-dialog modal-lg">
                                <div class="modal-content">
                                    <form method="post">
                                        <div class="modal-header bg-success text-white">
                                            <h5 class="modal-title" id="functionSettingsModalLabel">
                                                <i class="fas fa-cog me-2"></i>Function Settings
                                            </h5>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body">
                                            <input type="hidden" name="save_function_settings" value="1">
                                            
                                            <div class="alert alert-info">
                                                <strong>ℹ️ Informasi</strong><br>
                                                Di sini Anda bisa mengatur function custom yang dijalankan oleh bot.
                                                Setiap function perlu file path yang valid.
                                            </div>
                                            
                                            <div class="form-group mb-3">
                                                <label class="form-label fw-bold">Function Name</label>
                                                <input type="text" class="form-control" name="function_name" 
                                                       placeholder="contoh: handleMenu7, handleMenu10" required>
                                                <small class="text-muted">Gunakan huruf, angka, dan underscore saja</small>
                                            </div>
                                            
                                            <div class="form-group mb-3">
                                                <label class="form-label fw-bold">Function Description</label>
                                                <input type="text" class="form-control" name="function_desc" 
                                                       placeholder="Deskripsi singkat untuk function ini">
                                            </div>
                                            
                                            <div class="form-group mb-3">
                                                <label class="form-label fw-bold">File Path</label>
                                                <input type="text" class="form-control" name="function_file" 
                                                       placeholder="../webhook/menu_config_helper.php" required>
                                                <small class="text-muted">Path relatif dari webhook folder</small>
                                            </div>
                                            
                                            <div class="form-group mb-3">
                                                <label class="form-label">
                                                    <input type="checkbox" name="function_enabled" value="1" checked>
                                                    <strong>Enable Function</strong>
                                                </label>
                                            </div>
                                            
                                            <hr>
                                            
                                            <div class="alert alert-warning">
                                                <strong>📋 Function Saat Ini:</strong><br>
                                                <?php 
                                                if (isset($config['functions']) && is_array($config['functions']) && !empty($config['functions'])) {
                                                    foreach ($config['functions'] as $fname => $fdata) {
                                                        $enabled = $fdata['enabled'] ? '✅' : '❌';
                                                        echo "• <code>$fname</code> $enabled - {$fdata['description']}<br>";
                                                    }
                                                } else {
                                                    echo "Belum ada function yang terdaftar";
                                                }
                                                ?>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                            <button type="submit" class="btn btn-success">💾 Simpan Function</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <!-- PORT RANGE MODAL -->
                        <div class="modal fade" id="portRangeModal" tabindex="-1" aria-labelledby="portRangeModalLabel" aria-hidden="true">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <form method="post">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="portRangeModalLabel">Bot Port Range Settings</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body">
                                            <input type="hidden" name="save_port_range_settings" value="1">
                                            <div class="alert alert-info py-2 small mb-3">
                                                Port bot hanya boleh di rentang <strong>3000-3999</strong>.
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Port Start</label>
                                                <input type="number" class="form-control" name="port_start_bot" min="3000" max="3999" value="<?= (int)$currentPortStart ?>" required>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Port End</label>
                                                <input type="number" class="form-control" name="port_end_bot" min="3000" max="3999" value="<?= (int)$currentPortEnd ?>" required>
                                            </div>
                                            <small class="text-muted">Port baru saat Add Bot akan dipilih otomatis dari rentang ini.</small>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                            <button type="submit" class="btn btn-primary">Simpan</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="modal fade" id="dataModal" tabindex="-1" aria-labelledby="dataModalLabel" aria-hidden="true">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="dataModalLabel"> ADD BOT</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">




                                        <form id="dataForm" action="proses/addbot.php" method="post">

                                            <div class="mb-3">
                                                <label for="kode" class="form-label">BOT name</label>
                                                <input required type="text" class="form-control" id="botname" name="botname" required>
                                            </div>
                                            <div class="mb-3">
                                                <label for="sender" class="form-label">Sender (Nama Pengirim)</label>
                                                <input type="text" class="form-control" id="sender" name="sender" placeholder="Nama pengirim bot (WA API)">
                                            </div>

                                            <div hidden class="mb-3">
                                                <label  for="kode"  class="form-label">pemilik</label>
                                                <input required type="text" class="form-control" id="pemilik" name="pemilik" value="<?php echo $ceknama ?>" required>
                                            </div>

                                            <div class="mb-3">
                                                <label for="kode" class="form-label">BOT PASSWORD</label> Keamanan dari hecker
                                                <input required type="password" class="form-control" id="botname" name="botpass" required>
                                            </div>

                                            <div class="mb-3">
                                                <label for="botversion" class="form-label">Versi WA API</label>
                                                <select class="form-control" id="botversion" name="botversion" required>
                                                    <option value="v7.10.1" selected>v7.10.1 (default)</option>
                                                    <option value="v8.3.5" selected>v8.3.5 (default)</option>
                                                    <option value="v8.4.0" selected>v8.4.0 (default)</option>
                                                    <option value="latest">latest</option>
                                                </select>
                                            </div>

                                            <div class="mb-3">
                                                <label for="deploy_mode" class="form-label">Mode Docker</label>
                                                <select class="form-control" id="deploy_mode" name="deploy_mode" required>
                                                    <option value="inside" selected>DOCKER DI DALAM HOST</option>
                                                    <option value="outside">DOCKER DI LUAR HOST</option>
                                                </select>
                                                <small class="text-muted d-block mt-2">Pilih "DOCKER DI LUAR HOST" untuk menampilkan konfigurasi SSH server remote.</small>
                                            </div>

                                            <div id="ssh-config-fields" style="display:none;">
                                                <div class="mb-3">
                                                    <label for="ssh_ip" class="form-label">IP</label>
                                                    <input type="text" class="form-control" id="ssh_ip" name="ssh_ip" placeholder="103.46.186.76">
                                                </div>
                                                <div class="mb-3">
                                                    <label for="ssh_user" class="form-label">User</label>
                                                    <input type="text" class="form-control" id="ssh_user" name="ssh_user" placeholder="root">
                                                </div>
                                                <div class="mb-3">
                                                    <label for="ssh_pass" class="form-label">Pass</label>
                                                    <input type="password" class="form-control" id="ssh_pass" name="ssh_pass">
                                                </div>
                                            </div>




                                        </form>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                        <button type="submit" class="btn btn-primary" form="dataForm">Simpan</button>
                                    </div>
                                </div>
                            </div>
                        </div>



                        <div class="table-responsive p-0">

                            <style>
                                /* General small helpers */
                                .small-text { font-size: 8px; }

                                .wabot-menu-modal .modal-dialog {
                                    max-width: min(1480px, 96vw);
                                }

                                .wabot-menu-modal .modal-body {
                                    padding: 1.5rem;
                                }

                                .wabot-menu-modal .menu-item-row {
                                    border: 1px solid rgba(15, 23, 42, 0.08);
                                    box-shadow: 0 10px 24px rgba(15, 23, 42, 0.06);
                                }

                                .wabot-menu-modal .menu-item-grid {
                                    display: grid;
                                    grid-template-columns: minmax(70px, 0.75fr) minmax(180px, 1.3fr) minmax(100px, 0.9fr) minmax(160px, 1fr) minmax(260px, 2fr) minmax(56px, 0.45fr);
                                    gap: 0.9rem;
                                    align-items: start;
                                }

                                .wabot-menu-modal .menu-grid-field {
                                    min-width: 0;
                                }

                                .wabot-menu-modal .menu-grid-field.action-function-field,
                                .wabot-menu-modal .menu-grid-field.action-message-field {
                                    grid-column: span 2;
                                }

                                .wabot-menu-modal .menu-grid-field textarea {
                                    min-height: 92px;
                                    resize: vertical;
                                }

                                .wabot-menu-modal .menu-grid-action {
                                    display: flex;
                                    align-items: flex-end;
                                    justify-content: flex-end;
                                    height: 100%;
                                }

                                .wabot-menu-modal .menu-grid-action .btn {
                                    width: 100%;
                                }

                                @media (max-width: 1199.98px) {
                                    .wabot-menu-modal .modal-dialog {
                                        max-width: min(1180px, 98vw);
                                    }

                                    .wabot-menu-modal .menu-item-grid {
                                        grid-template-columns: repeat(2, minmax(0, 1fr));
                                    }

                                    .wabot-menu-modal .menu-grid-field.action-function-field,
                                    .wabot-menu-modal .menu-grid-field.action-message-field,
                                    .wabot-menu-modal .menu-grid-action {
                                        grid-column: 1 / -1;
                                    }

                                    .wabot-menu-modal .menu-grid-action .btn {
                                        width: auto;
                                        min-width: 120px;
                                    }
                                }

                                @media (max-width: 767.98px) {
                                    .wabot-menu-modal .modal-body {
                                        padding: 1rem;
                                    }

                                    .wabot-menu-modal .menu-item-grid {
                                        grid-template-columns: 1fr;
                                    }

                                    .wabot-menu-modal .menu-grid-field.action-function-field,
                                    .wabot-menu-modal .menu-grid-field.action-message-field,
                                    .wabot-menu-modal .menu-grid-action {
                                        grid-column: auto;
                                    }

                                    .wabot-menu-modal .menu-grid-action .btn {
                                        width: 100%;
                                    }
                                }

                                /* ===== ADAPTIVE THEME UNTUK MODAL ===== */
                                /* 
                                   Cara Penggunaan:
                                   1. Tambahkan class "theme-adaptive-modal" pada div.modal
                                   2. Gunakan class berikut di dalam modal:
                                      - .text-adaptive         : untuk text biasa yang ikut tema
                                      - .text-adaptive-label   : untuk label cyan
                                      - .text-adaptive-muted   : untuk text keterangan/muted
                                      - .bg-adaptive-card      : untuk card/box utama
                                      - .bg-adaptive-dark      : untuk background sedikit lebih gelap
                                      - .bg-adaptive-info      : untuk info box (cyan)
                                      - .bg-adaptive-warning   : untuk warning box (kuning)
                                   3. Modal akan otomatis berubah sesuai tema sistem (dark/light)
                                */
                                
                                /* Light Mode (Default) - Fallback untuk browser yang tidak support prefers-color-scheme */
                                .theme-adaptive-modal .modal-content {
                                    background-color: #ffffff;
                                    color: #212529;
                                    border: 1px solid #dee2e6;
                                }
                                
                                .theme-adaptive-modal .modal-body {
                                    background-color: #f8f9fa;
                                }
                                
                                .theme-adaptive-modal .modal-footer {
                                    background-color: #f8f9fa;
                                    border-top: 1px solid #dee2e6;
                                }
                                
                                .theme-adaptive-modal .text-adaptive {
                                    color: #212529 !important;
                                }
                                
                                .theme-adaptive-modal .bg-adaptive-card {
                                    background-color: #ffffff;
                                    border-color: #dee2e6;
                                }
                                
                                .theme-adaptive-modal .bg-adaptive-dark {
                                    background-color: #e9ecef;
                                }
                                
                                .theme-adaptive-modal .bg-adaptive-info {
                                    background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
                                    border-color: #17a2b8;
                                }
                                
                                .theme-adaptive-modal .bg-adaptive-warning {
                                    background: linear-gradient(135deg, #fff8e1 0%, #ffecb3 100%);
                                    border-color: #ffc107;
                                }
                                
                                .theme-adaptive-modal .text-adaptive-muted {
                                    color: #6c757d !important;
                                }
                                
                                .theme-adaptive-modal .text-adaptive-label {
                                    color: #17a2b8 !important;
                                }

                                .theme-adaptive-modal .operational-modal-header {
                                    background-color: #17a2b8;
                                    color: #ffffff;
                                    border-bottom: none;
                                }

                                .theme-adaptive-modal .op-section-title {
                                    font-size: 16px;
                                    font-weight: 700;
                                }

                                .theme-adaptive-modal .op-helper {
                                    display: block;
                                    margin-top: 8px;
                                    font-weight: 500;
                                    color: #495057;
                                }

                                .theme-adaptive-modal .op-field {
                                    border: 2px solid #17a2b8 !important;
                                    background-color: #ffffff !important;
                                    color: #1f2937 !important;
                                    box-shadow: 0 0 0 3px rgba(23, 162, 184, 0.2) !important;
                                }

                                .theme-adaptive-modal .op-field::placeholder {
                                    color: #6c757d !important;
                                }

                                .theme-adaptive-modal .op-field option {
                                    background-color: #ffffff;
                                    color: #1f2937;
                                }

                                .theme-adaptive-modal .op-divider {
                                    border-color: rgba(23, 162, 184, 0.35);
                                }

                                .theme-adaptive-modal .op-warning-note {
                                    color: #856404;
                                    display: block;
                                    margin-top: 8px;
                                    font-weight: 600;
                                }
                                
                                .theme-adaptive-modal .btn-close {
                                    filter: none;
                                }
                                
                                /* Dark Mode - Diterapkan saat sistem menggunakan dark theme */
                                @media (prefers-color-scheme: dark) {
                                    .theme-adaptive-modal .modal-content {
                                        background-color: #2a2a2a;
                                        color: #f8f9fa;
                                        border: 1px solid #4a4a4a;
                                    }
                                    
                                    .theme-adaptive-modal .modal-body {
                                        background-color: #2a2a2a;
                                    }
                                    
                                    .theme-adaptive-modal .modal-footer {
                                        background-color: #3a3a3a;
                                        border-top: 1px solid #4a4a4a;
                                    }
                                    
                                    .theme-adaptive-modal .text-adaptive {
                                        color: #f8f9fa !important;
                                    }
                                    
                                    .theme-adaptive-modal .bg-adaptive-card {
                                        background-color: #1a1a1a;
                                        border-color: #17a2b8;
                                    }
                                    
                                    .theme-adaptive-modal .bg-adaptive-dark {
                                        background-color: #2a2a2a;
                                    }
                                    
                                    .theme-adaptive-modal .bg-adaptive-info {
                                        background: linear-gradient(135deg, #1a3a4a 0%, #2a4a5a 100%);
                                        border-color: #17a2b8;
                                    }
                                    
                                    .theme-adaptive-modal .bg-adaptive-warning {
                                        background: linear-gradient(135deg, #3a2a0a 0%, #4a3a1a 100%);
                                        border-color: #ffc107;
                                    }
                                    
                                    .theme-adaptive-modal .text-adaptive-muted {
                                        color: #b0b0b0 !important;
                                    }
                                    
                                    .theme-adaptive-modal .text-adaptive-label {
                                        color: #63d5e8 !important;
                                    }

                                    .theme-adaptive-modal .op-helper {
                                        color: #c7d0d8;
                                    }

                                    .theme-adaptive-modal .op-field {
                                        background-color: #1f252b !important;
                                        color: #f1f5f9 !important;
                                        border-color: #35b9cf !important;
                                        box-shadow: 0 0 0 3px rgba(53, 185, 207, 0.22) !important;
                                    }

                                    .theme-adaptive-modal .op-field::placeholder {
                                        color: #9aa7b4 !important;
                                    }

                                    .theme-adaptive-modal .op-field option {
                                        background-color: #1f252b;
                                        color: #f1f5f9;
                                    }

                                    .theme-adaptive-modal .op-divider {
                                        border-color: rgba(99, 213, 232, 0.4);
                                    }

                                    .theme-adaptive-modal .op-warning-note {
                                        color: #ffd24d;
                                    }
                                    
                                    .theme-adaptive-modal .btn-close {
                                        filter: invert(1);
                                    }
                                    
                                    .theme-adaptive-modal .form-check-input {
                                        background-color: #1a1a1a !important;
                                        border-color: #17a2b8 !important;
                                    }
                                }

                                /* Fallback: paksa dark mode jika halaman terdeteksi gelap walau prefers-color-scheme tidak aktif */
                                .theme-adaptive-modal.theme-force-dark .modal-content {
                                    background-color: #2a2a2a;
                                    color: #f8f9fa;
                                    border: 1px solid #4a4a4a;
                                }

                                .theme-adaptive-modal.theme-force-dark .modal-body {
                                    background-color: #2a2a2a;
                                }

                                .theme-adaptive-modal.theme-force-dark .modal-footer {
                                    background-color: #3a3a3a;
                                    border-top: 1px solid #4a4a4a;
                                }

                                .theme-adaptive-modal.theme-force-dark .text-adaptive {
                                    color: #f8f9fa !important;
                                }

                                .theme-adaptive-modal.theme-force-dark .text-adaptive-label {
                                    color: #63d5e8 !important;
                                }

                                .theme-adaptive-modal.theme-force-dark .op-helper {
                                    color: #c7d0d8;
                                }

                                .theme-adaptive-modal.theme-force-dark .bg-adaptive-card {
                                    background-color: #1a1a1a;
                                    border-color: #35b9cf;
                                }

                                .theme-adaptive-modal.theme-force-dark .bg-adaptive-dark {
                                    background-color: #2a2a2a;
                                }

                                .theme-adaptive-modal.theme-force-dark .bg-adaptive-info {
                                    background: linear-gradient(135deg, #1a3a4a 0%, #2a4a5a 100%);
                                    border-color: #35b9cf;
                                }

                                .theme-adaptive-modal.theme-force-dark .bg-adaptive-warning {
                                    background: linear-gradient(135deg, #3a2a0a 0%, #4a3a1a 100%);
                                    border-color: #ffc107;
                                }

                                .theme-adaptive-modal.theme-force-dark .op-field {
                                    background-color: #1f252b !important;
                                    color: #f1f5f9 !important;
                                    border-color: #35b9cf !important;
                                    box-shadow: 0 0 0 3px rgba(53, 185, 207, 0.22) !important;
                                }

                                .theme-adaptive-modal.theme-force-dark .op-field::placeholder {
                                    color: #9aa7b4 !important;
                                }

                                .theme-adaptive-modal.theme-force-dark .op-field option {
                                    background-color: #1f252b;
                                    color: #f1f5f9;
                                }

                                .theme-adaptive-modal.theme-force-dark .op-divider {
                                    border-color: rgba(99, 213, 232, 0.4);
                                }

                                .theme-adaptive-modal.theme-force-dark .op-warning-note {
                                    color: #ffd24d;
                                }

                                .theme-adaptive-modal.theme-force-dark .btn-close {
                                    filter: invert(1);
                                }

                                .theme-adaptive-modal.theme-force-dark .form-check-input {
                                    background-color: #1a1a1a !important;
                                    border-color: #17a2b8 !important;
                                }

                                /* Custom checkbox styling - adaptif untuk light/dark mode */
                                .form-check-input {
                                    cursor: pointer;
                                    border: 3px solid #17a2b8 !important;
                                    transition: all 0.3s ease;
                                }
                                
                                /* Light Mode - checkbox default */
                                @media (prefers-color-scheme: light), (prefers-color-scheme: no-preference) {
                                    .form-check-input {
                                        background-color: #f8f9fa !important;
                                    }
                                }
                                
                                /* Dark Mode - checkbox */
                                @media (prefers-color-scheme: dark) {
                                    .form-check-input {
                                        background-color: #1a1a1a !important;
                                    }
                                }
                                
                                .form-check-input:checked {
                                    background-color: #17a2b8 !important;
                                    border-color: #17a2b8 !important;
                                    box-shadow: 0 0 15px rgba(23, 162, 184, 0.8) !important;
                                }
                                
                                .form-check-input:focus {
                                    border-color: #17a2b8 !important;
                                    box-shadow: 0 0 0 0.2rem rgba(23, 162, 184, 0.5) !important;
                                }
                                
                                .form-check-input:hover {
                                    border-color: #1ac6de !important;
                                    box-shadow: 0 0 10px rgba(26, 198, 222, 0.5);
                                    transform: scale(1.05);
                                }
                                
                                /* Toggle switch styling untuk operational hours */
                                .form-switch .form-check-input {
                                    width: 50px;
                                    height: 25px;
                                    cursor: pointer;
                                }
                                
                                .form-switch .form-check-input:checked {
                                    background-color: #17a2b8 !important;
                                    border-color: #17a2b8 !important;
                                    box-shadow: 0 0 8px rgba(23, 162, 184, 0.6) !important;
                                }
                                
                                /* Toggle switch styling khusus untuk offline mode (kuning) */
                                .offline-toggle-switch .form-check-input:checked {
                                    background-color: #ffc107 !important;
                                    border-color: #ffc107 !important;
                                    box-shadow: 0 0 8px rgba(255, 193, 7, 0.6) !important;
                                }

                                /* Styling untuk card bot */
                                .bot-card {
                                    border: 1px solid #e9ecef;
                                    border-radius: 10px;
                                    padding: 15px;
                                    margin-bottom: 15px;
                                    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                                    transition: box-shadow 0.3s ease;
                                }

                                .bot-card:hover { box-shadow: 0 4px 8px rgba(0,0,0,0.15); }

                                .bot-name { font-size: 18px; font-weight: 600; margin-bottom: 10px; }
                                .bot-address { font-size: 12px; color: #6c757d; word-break: break-all; }

                                .bot-buttons { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 10px; }
                                .bot-buttons .btn { font-size: 11px; padding: 5px 10px; }

                                /* Styling untuk modal */
                                .modal-dialog { max-width: 500px; }
                                .form-control { border-radius: 6px; }

                                /* Tombol di dalam modal: tampil vertikal sebagai grup */
                                .modal-footer {
                                    display: flex;
                                    flex-direction: column;
                                    align-items: stretch;
                                    gap: 0.5rem;
                                }

                                .modal-footer .btn {
                                    width: 100%;
                                    margin: 0 !important;
                                }

                                /* Styling untuk table */
                                .table { border-collapse: separate; border-spacing: 0; }
                                .table thead th {
                                    background-color: #f8f9fa;
                                    border-bottom: 2px solid #dee2e6;
                                    font-weight: 600;
                                }

                                .table tbody tr:nth-child(even) { background-color: #f8f9fa; }
                                .table tbody tr:hover { background-color: #e9ecef; }

                                .badge { font-size: 10px; }

                                .wabot-toolbar-actions .btn {
                                    white-space: nowrap;
                                }

                                .wabot-action-groups {
                                    display: flex;
                                    flex-direction: column;
                                    gap: 0.45rem;
                                    align-items: center;
                                }

                                .wabot-action-group {
                                    display: flex;
                                    flex-wrap: wrap;
                                    gap: 0.4rem;
                                    justify-content: center;
                                }

                                .wabot-action-group .btn,
                                .wabot-action-group form .btn {
                                    min-width: 110px;
                                    white-space: nowrap;
                                }

                                .modal-action-groups {
                                    display: flex;
                                    flex-direction: column;
                                    gap: 0.85rem;
                                }

                                .modal-action-group {
                                    border: 1px solid #e5e7eb;
                                    border-radius: 10px;
                                    padding: 0.75rem;
                                    background: #f8fafc;
                                }

                                .modal-action-group-title {
                                    font-size: 0.75rem;
                                    font-weight: 700;
                                    color: #6b7280;
                                    margin-bottom: 0.5rem;
                                    text-transform: uppercase;
                                    letter-spacing: 0.04em;
                                }

                                .modal-action-group .btn,
                                .modal-action-group form .btn,
                                .modal-action-group a.btn {
                                    width: 100%;
                                    margin-bottom: 0.45rem;
                                }

                                .modal-action-group .btn:last-child,
                                .modal-action-group form:last-child .btn,
                                .modal-action-group a.btn:last-child {
                                    margin-bottom: 0;
                                }

                                /* Responsive: stack rows on small screens and show a label before each cell */
                                @media (max-width: 768px) {
                                    .wabot-toolbar {
                                        align-items: stretch !important;
                                    }

                                    .wabot-toolbar h6 {
                                        margin-bottom: 0;
                                    }

                                    .wabot-toolbar-actions {
                                        width: 100%;
                                    }

                                    .wabot-toolbar-actions .btn {
                                        flex: 1 1 calc(50% - 0.5rem);
                                        min-width: 145px;
                                    }

                                    .table thead { display: none; }
                                    .table, .table tbody, .table tr, .table td { display: block; width: 100%; }
                                    .table tr {
                                        margin-bottom: 0.9rem;
                                        padding: 0.65rem 0.75rem;
                                        border: 1px solid #e9ecef;
                                        border-radius: 10px;
                                        background: #fff;
                                        box-shadow: 0 1px 3px rgba(0,0,0,0.06);
                                    }
                                    .table td {
                                        padding: 0.5rem 0;
                                        border: none;
                                    }
                                    .table td::before {
                                        content: attr(data-label);
                                        display: inline-block;
                                        font-weight: 700;
                                        width: 38%;
                                        color: #6c757d;
                                        vertical-align: top;
                                    }
                                    .table td[data-label="BOT Name"] .d-flex {
                                        gap: 0.6rem;
                                        align-items: flex-start;
                                    }
                                    .table td[data-label="Actions"] {
                                        padding-top: 0.7rem;
                                    }
                                    .wabot-action-groups {
                                        align-items: stretch;
                                    }
                                    .wabot-action-group {
                                        display: grid;
                                        grid-template-columns: repeat(2, minmax(0, 1fr));
                                        gap: 0.5rem;
                                        width: 100%;
                                    }
                                    .wabot-action-group .btn,
                                    .wabot-action-group form .btn {
                                        width: 100%;
                                        white-space: normal;
                                        min-width: 0;
                                    }
                                }

                                /* Extra small tweaks */
                                @media (max-width: 420px) {
                                    .wabot-toolbar-actions .btn {
                                        flex: 1 1 100%;
                                        min-width: 100%;
                                    }

                                    .table td::before { display: block; width: 100%; margin-bottom: 0.25rem; }
                                    .wabot-action-group {
                                        grid-template-columns: 1fr;
                                    }
                                }
                            </style>

                            <table class="table align-items-center mb-0" style="font-size: 10px;">
                               

                                <tbody id="dataTable">
                                    <?php



                                    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
                                        $id = intval($_POST['id']);
                                        $namebot = $_POST['namebot'];
                                        $portForwardRemovedCount = 0;
                                        $mikrotikCleanupAttempted = false;
                                        $mikrotikConnected = false;

                                        // Ambil addressbot berdasarkan id
                                        $result = $conn->query("SELECT * FROM botwa WHERE id = $id");
                                        if ($result && $row = $result->fetch_assoc()) {
                                            $addressbot = $row['addressbot'];
                                            $server111 = $row['pemilik'];

                                            // Ambil port dari addressbot (misalnya http://domain.com:3010)
                                            $parsed = parse_url($addressbot);
                                            $port = isset($parsed['port']) ? intval($parsed['port']) : 0;


                                            if ($port >= 3000 && $port <= 3999) {

                                                $containerName = "whatsapp_$port";
                                                $volumeName = "whatsapp_$port";

                                                $addressHost = isset($parsed['host']) ? $parsed['host'] : '';
                                                $localPublicIp = isset($config['ippublic']) ? $config['ippublic'] : '';
                                                $isInsideHost = ($addressHost === '' || $addressHost === $localPublicIp || $addressHost === 'localhost' || $addressHost === '127.0.0.1');

                                                  $out1 = shell_exec("docker stop $containerName 2>&1");
                                                    $out2 = shell_exec("docker rm $containerName 2>&1");
                                                    $out3 = shell_exec("docker volume rm $volumeName 2>&1");
                                                    file_put_contents('/tmp/docker_delete_debug.log', "[".date('Y-m-d H:i:s')."] STOP: $out1\nRM: $out2\nVOL: $out3\n", FILE_APPEND);
                                                    
                                                   $sshIp = $addressHost;
                                                    $sshUser = isset($config['docker_remote_user']) ? $config['docker_remote_user'] : 'air';
                                                    $sshPass = isset($config['docker_remote_pass']) ? $config['docker_remote_pass'] : '1Sampai8@';

                                                    $dockerDeleteCommand = "sshpass -p " . escapeshellarg($sshPass)
                                                        . " ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null "
                                                        . $sshUser . "@" . $sshIp . " "
                                                        . escapeshellarg("docker stop $containerName && docker rm $containerName && docker volume rm $volumeName")
                                                        . " 2>&1";
                                                    $result = shell_exec($dockerDeleteCommand);
                                                    file_put_contents('/tmp/docker_delete_debug.log', "[".date('Y-m-d H:i:s')."] REMOTE: $result\n", FILE_APPEND);
                                                  


                                                require 'routeros_api.class.php';
                                                // Koneksi ke MikroTik main router dan hapus rule port forwarding bot
                                                $API = new RouterosAPI();
                                                $ip = isset($config['router_ip']) ? trim((string)$config['router_ip']) : '';
                                                $routerApiPort = isset($config['ippublicportapi']) ? trim((string)$config['ippublicportapi']) : '';
                                                $username = isset($config['router_user']) ? trim((string)$config['router_user']) : '';
                                                $password = isset($config['router_pass']) ? (string)$config['router_pass'] : '';
                                                $routerEndpoint = $ip;
                                                if ($ip !== '' && $routerApiPort !== '' && ctype_digit($routerApiPort)) {
                                                    $routerEndpoint = $ip . ':' . $routerApiPort;
                                                }

                                                if ($ip !== '' && $username !== '') {
                                                    $mikrotikCleanupAttempted = true;
                                                }

                                                if ($routerEndpoint !== '' && $username !== '' && $API->connect($routerEndpoint, $username, $password)) {
                                                    $mikrotikConnected = true;
                                                    $API->write('/ip/firewall/nat/print');
                                                    $natRules = $API->read();

                                                    foreach ($natRules as $rule) {
                                                        if (!isset($rule['.id'])) {
                                                            continue;
                                                        }

                                                        $dstPortExpr = isset($rule['dst-port']) ? $rule['dst-port'] : '';
                                                        $toPortsExpr = isset($rule['to-ports']) ? $rule['to-ports'] : '';
                                                        $comment = strtolower(trim((string)($rule['comment'] ?? '')));
                                                        $chain = strtolower(trim((string)($rule['chain'] ?? '')));

                                                        // Fokus ke dstnat/port forwarding
                                                        if ($chain !== '' && $chain !== 'dstnat') {
                                                            continue;
                                                        }

                                                        $matchByPort = mikrotikPortExpressionMatches($dstPortExpr, $port)
                                                            || mikrotikPortExpressionMatches($toPortsExpr, $port);

                                                        $matchByComment = $comment !== '' && (
                                                            strpos($comment, (string)$port) !== false
                                                            || strpos($comment, strtolower($namebot)) !== false
                                                            || strpos($comment, strtolower($containerName)) !== false
                                                        );

                                                        if ($matchByPort || $matchByComment) {
                                                            $API->write('/ip/firewall/nat/remove', false);
                                                            $API->write('=.id=' . $rule['.id']);
                                                            $API->read();
                                                            $portForwardRemovedCount++;
                                                        }
                                                    }

                                                    $API->disconnect();
                                                }

                                                // Hapus file log webhook jika ada
                                                $logFile1 = "{$config['webhook_dir']}webhook_log_whatsapp_$volumeName.txt";
                                                if (file_exists($logFile1)) {
                                                    unlink($logFile1);
                                                }

                                                // Hapus file log webhook jika ada
                                                $logFile2 = "{$config['webhook_dir']}webhook_$volumeName.php";
                                                if (file_exists($logFile2)) {
                                                    unlink($logFile2);
                                                }

                                                // Hapus file log webhook jika ada
                                                $logFile3 = "{$config['webhook_dir']}botrespon_$volumeName.php";
                                                if (file_exists($logFile3)) {
                                                    unlink($logFile3);
                                                }
                                            }

                                            // Hapus dari database
                                            $sql11 = "DELETE FROM botwa WHERE id = $id";
                                            if ($conn->query($sql11)) {






                                                //////DATA USERNAME/////////////////////////////////////////////////////////////
                                                $sql99 = "SELECT * FROM `user` WHERE `server` like '%$server111%' ";
                                                $query99 = mysqli_query($conn, $sql99);
                                                while ($data99 = mysqli_fetch_array($query99)) {

                                                    $username22 = $data99['USERNAME'];
                                                }




                                                // Cek apakah sudah pernah dikirim
                                                $history_file = "notifbot/data/history-$username22.json";
                                                $history = [];

                                                if (file_exists($history_file)) {
                                                    $history = json_decode(file_get_contents($history_file), true);
                                                }

                                                // Pastikan format history adalah array
                                                if (!is_array($history)) {
                                                    $history = [];
                                                }




                                                if (!$mikrotikCleanupAttempted) {
                                                    $mikrotikInfo = " | mikrotik cleanup: dilewati (router config kosong)";
                                                } elseif (!$mikrotikConnected) {
                                                    $mikrotikInfo = " | mikrotik cleanup: gagal konek router";
                                                } else {
                                                    $mikrotikInfo = " | mikrotik dstnat terhapus: " . $portForwardRemovedCount;
                                                }
                                                $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Bot $namebot berhasil dihapus" . $mikrotikInfo;
                                                // Simpan ke file history
                                                file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

                                                /////////////////////////////////////////////////////////////////////////////////

                                            } else {
                                                echo "âŒ Gagal menghapus data dari database: " . $conn->error;
                                            }
                                        } else {
                                            echo "âŒ Data bot tidak ditemukan berdasarkan ID.";
                                        }
                                    }


                                
                                    if (isset($_GET['start_service']) && isset($_GET['bot'])) {
                                        $volumeName = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['bot']); // whatsapp_3017
                                        $serviceName = "botrespon_{$volumeName}.service";
                                        $servicePath = "/etc/systemd/system/{$serviceName}";

                                        // Sinkronkan script botrespon per-bot dengan template terbaru.
                                        $webhookDir = isset($config['webhook_dir']) ? rtrim((string)$config['webhook_dir'], '/\\') : '';
                                        if ($webhookDir === '' || !is_dir($webhookDir)) {
                                            $webhookDir = realpath(__DIR__ . '/../webhook');
                                        }

                                        $botresponTemplate = rtrim((string)$webhookDir, '/\\') . DIRECTORY_SEPARATOR . 'botrespon.php';
                                        $botresponTarget = rtrim((string)$webhookDir, '/\\') . DIRECTORY_SEPARATOR . "botrespon_{$volumeName}.php";
                                        $syncWarning = '';

                                        if (!is_file($botresponTemplate)) {
                                            $syncWarning = " Template botrespon.php tidak ditemukan.";
                                        } else {
                                            if (!@copy($botresponTemplate, $botresponTarget)) {
                                                $syncWarning = " Gagal sinkron file botrespon untuk {$volumeName}.";
                                            } else {
                                                @chmod($botresponTarget, 0777);
                                            }
                                        }

                                        $curlPath = "/usr/bin/curl";
                                        $curlURL = "{$config['webhook_url']}botrespon_$volumeName.php";

                                        // Selalu tulis ulang service agar config lama yang rusak tidak dipakai
                                        $serviceContent = "[Unit]
Description=Bot Auto Reply CURL: {$volumeName}
After=network.target

[Service]
User=www-data
ExecStart=/bin/bash -c 'while true; do {$curlPath} -s \"{$curlURL}\"; sleep 3; done'
ExecStop=/bin/kill -s TERM \$MAINPID
KillMode=process
Restart=always
RestartSec=2
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target";

                                        $tmpService = "/tmp/$serviceName";
                                        file_put_contents($tmpService, $serviceContent);

                                        // Hentikan service lama, lalu pasang unit terbaru
                                        exec("sudo systemctl stop $serviceName");
                                        exec("sudo systemctl disable $serviceName");

                                        exec("sudo mv $tmpService $servicePath", $out1, $code1);
                                        exec("sudo systemctl daemon-reload", $out2, $code2);
                                        exec("sudo systemctl enable $serviceName", $out3, $code3);

                                        if ($code1 !== 0 || $code2 !== 0 || $code3 !== 0) {
                                            $message = "Gagal membuat service <b>$serviceName</b> (code: $code1, $code2, $code3).";
                                            echo "<script>alert(" . json_encode(strip_tags($message)) . ");</script>";
                                            echo "<script>location.href='?status=1';</script>";
                                            exit;
                                        }

                                        // Start service (aksi start harus selalu start, bukan toggle)
                                        exec("sudo systemctl daemon-reload");
                                        exec("sudo systemctl reset-failed $serviceName");
                                        $output = [];
                                        exec("sudo systemctl start $serviceName 2>&1", $output, $code);

                                        $message = $code === 0
                                            ? "BOT <b>$volumeName</b> berhasil dijalankan." . $syncWarning
                                            : "Gagal menjalankan BOT <b>$volumeName</b>. Detail: " . implode(" ", $output) . $syncWarning;

                                        echo "<script>alert(" . json_encode(strip_tags($message)) . ");</script>";
                                        echo "<script>location.href='?status=1';</script>";
                                    }


                                    if (isset($_GET['stop_service']) && isset($_GET['bot'])) {
                                        $volumeName = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['bot']); // whatsapp_3017
                                        $serviceName = "botrespon_{$volumeName}.service";

                                        exec("sudo systemctl stop $serviceName", $output, $code);
                                        $message = $code === 0
                                            ? "âœ… BOT <b>$volumeName</b> berhasil dihentikan."
                                            : "âŒ Gagal menghentikan BOT <b>$volumeName</b>.";

                                        echo "<script>alert(" . json_encode(strip_tags($message)) . ");</script>";
                                        echo "<script>location.href='?status=1';</script>";
                                        exit;
                                    }



                                    $server_list = array_map('trim', explode(',', $server_list)); // Ubah ke array & hilangkan spasi
                                    $server_list = "" . implode(",", $server_list) . ""; // Tambahkan kutip di setiap nilai



                                    // Bot yang tampil ke ASSISTANT dibatasi ke bot yang di-assign owner (kolom
                                    // user.assigned_bots) ATAU bot yang dibuat sendiri oleh assistant ini
                                    // (botwa.created_by_assistant) -- lihat notifbot/bot_access_helper.php.
                                    // Owner/admin tetap lihat SEMUA bot pemilik = $ceknama spt sebelumnya.
                                    $sql = "SELECT * FROM `botwa` WHERE `pemilik` = '$ceknama'" . botAccessWhereClause($conn, $AKSES, $assigned_bot_ids ?? [], $asistant_name ?? '');
                                    $query = mysqli_query($conn, $sql);
                                    while ($data = mysqli_fetch_array($query)) {
                                        $ip = $data['id'];
                                        setcookie('id', $ip);
                                        $technicalMenuEnabled = !isset($data['technical_menu_enabled']) || (int)$data['technical_menu_enabled'] === 1;
                                        $allowReadServer = !isset($data['allow_read_server']) || (int)$data['allow_read_server'] === 1;
                                        $allowReadCustomer = !isset($data['allow_read_customer']) || (int)$data['allow_read_customer'] === 1;
                                        $allowCreatePaymentCode = !isset($data['allow_create_payment_code']) || (int)$data['allow_create_payment_code'] === 1;



                                    ?>


                                        <tr>
                                            <td data-label="BOT Name">
                                                <div class="d-flex px-2 py-1 align-items-start">
                                                    <div class="me-3">
                                                        <img src="wa.png" class="avatar avatar-sm" alt="Server">
                                                    </div>
                                                    <div class="flex-grow-1">
                                                        <h6 class="mb-2 text-sm"><?php echo htmlspecialchars($data['namebot']); ?></h6>
                                                        <div class="text-muted small">
                                                            <?php echo htmlspecialchars($data['addressbot']); ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                                            <?php
                                                            // Extract port from addressbot to get volume name
                                                            $volumeName = $data['namebot'];
                                                            $ippublic = $config['ippublic'];
                                                            
                                                            $parsedUrl = parse_url($data['addressbot']);
                                                            $port = isset($parsedUrl['port']) ? $parsedUrl['port'] : '';
                                                            $addressbotHost = isset($parsedUrl['host']) ? $parsedUrl['host'] : '';
                                                            // Bot internal MODE "DALAM HOST": addressbot menunjuk IP LOKAL
                                                            // Docker ($config['webiplocal'], tidak bisa diakses langsung dari
                                                            // browser admin), jadi harus dibangun ulang pakai IP publik
                                                            // server ini + port NAT Mikrotik.
                                                            // Bot internal MODE "LUAR HOST" (docker_remote/SSH): addressbot
                                                            // SUDAH berisi IP server luar host yang benar & bisa diakses
                                                            // langsung (lihat proses/addbot.php) -- JANGAN ditimpa lagi pakai
                                                            // $ippublic server INI, itu penyebab tombol Setting bot luar-host
                                                            // selalu ngarah ke server yang salah.
                                                            // Bot eksternal (unofficial_api/resmi_api): addressbot SUDAH
                                                            // alamat aslinya (server lain), jangan diganti ke IP server ini.
                                                            $isBotModeDalamHost = $addressbotHost !== '' && $addressbotHost === trim((string)($config['webiplocal'] ?? ''));
                                                            $connect = (($data['tipe_bot'] ?? 'gowa') === 'gowa')
                                                                ? ($isBotModeDalamHost ? "http://$ippublic:" . $port : $data['addressbot'])
                                                                : $data['addressbot'];
                                                            $volumeName = $port ? "whatsapp_{$port}" : $volumeName;
                                                            $serviceName = "botrespon_{$volumeName}.service";

                                                            // Ambil status aktif dari systemctl
                                                            $statusOutput = [];
                                                            exec("systemctl is-active $serviceName", $statusOutput);

                                                            // Simpan hasilnya ke boolean
                                                            $isActive = trim($statusOutput[0]) === 'active';

                                                            // Debug (hapus ini di produksi)
                                                            # echo "Service: $serviceName Status: " . ($isActive ? 'active' : 'inactive');
                                                            ?>

                                            <td class="text-center" data-label="Status Auto respone">
                                                <?php if ($isActive): ?>
                                                    <span class="badge bg-success">Aktif</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Nonaktif</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center" data-label="Jam Operasional">
                                                <?php 
                                                require_once '../webhook/operational_hours_helper.php';
                                                $opStatus = getOperationalHoursStatus($data['namebot']);
                                                if ($opStatus['status'] === 'disabled') {
                                                    echo '<span class="badge bg-light text-dark">-</span>';
                                                } elseif ($opStatus['status'] === 'open') {
                                                    echo '<span class="badge bg-success">Buka</span><br><small>' . $opStatus['hours'] . '</small>';
                                                } else {
                                                    echo '<span class="badge bg-warning">Tutup</span><br><small>' . $opStatus['hours'] . '</small>';
                                                }
                                                ?>
                                            </td>
                                            <td data-label="Actions">
                                                <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#actionMenuModal<?= $data['id'] ?>">
                                                    <i class="fas fa-layer-group me-1"></i> Menu Settings
                                                </button>
                                                <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#senderModal<?= $data['id'] ?>">
                                                    <i class="fas fa-paper-plane me-1"></i> Edit Sender
                                                </button>
                                                <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#testerModal<?= $data['id'] ?>">
                                                    <i class="fas fa-vial me-1"></i> Tester
                                                </button>
                                            </td>
                                                                                <!-- Tester Modal -->
                                                                                <div class="modal fade" id="testerModal<?= $data['id'] ?>" tabindex="-1" aria-labelledby="testerModalLabel<?= $data['id'] ?>" aria-hidden="true">
                                                                                    <div class="modal-dialog">
                                                                                        <div class="modal-content">
                                                                                            <form method="post" action="">
                                                                                                <input type="hidden" name="tester_bot_id" value="<?= $data['id'] ?>">
                                                                                                <div class="modal-header bg-success text-white">
                                                                                                    <h5 class="modal-title" id="testerModalLabel<?= $data['id'] ?>">
                                                                                                        <i class="fas fa-vial me-2"></i>Tester Bot: <?= htmlspecialchars($data['namebot']) ?>
                                                                                                    </h5>
                                                                                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                                                                                </div>
                                                                                                <div class="modal-body">
                                                                                                    <div class="mb-3">
                                                                                                        <label for="tester_phone_<?= $data['id'] ?>" class="form-label">Nomor Tujuan (WhatsApp)</label>
                                                                                                        <input type="text" class="form-control" id="tester_phone_<?= $data['id'] ?>" name="tester_phone" placeholder="628xxxxxxxxxx" required>
                                                                                                    </div>
                                                                                                    <div class="mb-3">
                                                                                                        <label for="tester_message_<?= $data['id'] ?>" class="form-label">Pesan Tes</label>
                                                                                                        <textarea class="form-control" id="tester_message_<?= $data['id'] ?>" name="tester_message" rows="2" placeholder="Pesan uji coba..." required>Ini adalah pesan tes dari bot <?= htmlspecialchars($data['namebot']) ?>.</textarea>
                                                                                                    </div>
                                                                                                </div>
                                                                                                <div class="modal-footer">
                                                                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                                                                                    <button type="submit" class="btn btn-success">Kirim Tes</button>
                                                                                                </div>
                                                                                            </form>
                                                                                        </div>
                                                                                    </div>
                                                                                </div>
                                        </tr>
                                          <!-- Sender Modal -->
                                        <div class="modal fade" id="senderModal<?= $data['id'] ?>" tabindex="-1" aria-labelledby="senderModalLabel<?= $data['id'] ?>" aria-hidden="true">
                                        <div class="modal-dialog">
                                        <div class="modal-content">
                                        <form method="post">
                                        <div class="modal-header bg-warning text-dark">
                                        <h5 class="modal-title" id="senderModalLabel<?= $data['id'] ?>">
                                        <i class="fas fa-paper-plane me-2"></i>Edit Sender Bot: <?= htmlspecialchars($data['namebot']) ?>
                                        </h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body">
                                        <input type="hidden" name="edit_sender" value="1">
                                        <input type="hidden" name="bot_id" value="<?= (int)$data['id'] ?>">
                                        <div class="mb-3">
                                        <label for="sender_input_<?= $data['id'] ?>" class="form-label">Sender (Nama Pengirim)</label>
                                        <input type="text" class="form-control" id="sender_input_<?= $data['id'] ?>" name="sender" value="<?= htmlspecialchars($data['sender'] ?? '') ?>" placeholder="Nama pengirim bot (WA API)" required>
                                        </div>
                                        </div>
                                        <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                        <button type="submit" class="btn btn-warning">Simpan Sender</button>
                                        </div>
                                        </form>
                                        </div>
                                        </div>
                                        </div>
                                        <div class="modal fade" id="actionMenuModal<?= $data['id'] ?>" tabindex="-1" aria-labelledby="actionMenuModalLabel<?= $data['id'] ?>" aria-hidden="true">
                                            <div class="modal-dialog modal-dialog-centered">
                                                <div class="modal-content">
                                                    <div class="modal-header bg-primary text-white">
                                                        <h5 class="modal-title" id="actionMenuModalLabel<?= $data['id'] ?>">
                                                            <i class="fas fa-layer-group me-2"></i>Menu Bot: <?= htmlspecialchars($data['namebot']) ?>
                                                        </h5>
                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <div class="modal-action-groups">
                                                            <div class="modal-action-group">
                                                                <div class="modal-action-group-title">Akses</div>
                                                                <button type="button" class="btn btn-success btn-sm" onclick="window.open('<?php echo $connect ; ?>', '_blank')">Settings</button>
                                                                <button type="button" class="btn btn-warning btn-sm" onclick="window.open('<?php echo $data['webhook']; ?>', '_blank')">Webhook</button>
                                                            </div>

                                                            <div class="modal-action-group">
                                                                <div class="modal-action-group-title">Konfigurasi</div>
                                                                <button class="btn btn-sm btn-info" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#editModal<?= $data['id'] ?>">
                                                                    <i class="fas fa-edit me-1"></i> Edit Prompt
                                                                </button>
                                                                <button class="btn btn-sm btn-warning" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#senderModal<?= $data['id'] ?>">
                                                                    <i class="fas fa-paper-plane me-1"></i> Edit Sender
                                                                </button>
                                                                                                      
                                                                <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#operationalModal<?= $data['id'] ?>">
                                                                    <i class="fas fa-clock me-1"></i> Jam Operasional
                                                                </button>
                                                                <button class="btn btn-sm btn-dark" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#aiProviderModal<?= $data['id'] ?>">
                                                                    <i class="fas fa-robot me-1"></i> AI Provider
                                                                </button>
                                                                <button class="btn btn-sm <?= (!isset($data['auto_respon_enabled']) || (int)$data['auto_respon_enabled'] === 1) ? 'btn-success' : 'btn-danger' ?>" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#autoResponModal<?= $data['id'] ?>">
                                                                    <i class="fas fa-toggle-on me-1"></i> Trigger Control Auto Respon
                                                                </button>
                                                                <button class="btn btn-sm btn-outline-dark" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#aiTesterModal<?= $data['id'] ?>">
                                                                    <i class="fas fa-vial me-1"></i> Tes AI
                                                                </button>
                                                                <?php if ($isAdminAccess): ?>
                                                                <button class="btn btn-sm btn-outline-danger" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#menu7Modal<?= $data['id'] ?>" title="Edit Menu 7 - Laporan Gangguan">
                                                                    <i class="fas fa-bug me-1"></i> Menu 7
                                                                </button>
                                                                <button class="btn btn-sm btn-outline-info" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#menu10Modal<?= $data['id'] ?>" title="Edit Menu 10 - Data Pelanggan">
                                                                    <i class="fas fa-user me-1"></i> Menu 10
                                                                </button>
                                                                <button class="btn btn-sm btn-primary" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#menuModal<?= $data['id'] ?>">
                                                                    <i class="fas fa-list-ul me-1"></i> Menu Settings Lanjutan
                                                                </button>
                                                                <?php endif; ?>
                                                                <form method="post">
                                                                    <input type="hidden" name="save_technical_menu_settings" value="1">
                                                                    <input type="hidden" name="bot_technical_menu_id" value="<?= (int)$data['id'] ?>">
                                                                    <input type="hidden" name="technical_menu_enabled" value="<?= $technicalMenuEnabled ? '0' : '1' ?>">
                                                                    <button type="submit" class="btn btn-sm <?= $technicalMenuEnabled ? 'btn-outline-danger' : 'btn-outline-success' ?>">
                                                                        <i class="fas <?= $technicalMenuEnabled ? 'fa-toggle-on' : 'fa-toggle-off' ?> me-1"></i>
                                                                        <?= $technicalMenuEnabled ? 'Matikan' : 'Aktifkan' ?> Menu Teknisi
                                                                    </button>
                                                                </form>
                                                                <span class="badge <?= $technicalMenuEnabled ? 'bg-success' : 'bg-secondary' ?>">
                                                                    Menu Teknisi <?= $technicalMenuEnabled ? 'ON' : 'OFF' ?>
                                                                </span>
                                                                <form method="post" class="mt-2">
                                                                    <input type="hidden" name="save_bot_access_permissions" value="1">
                                                                    <input type="hidden" name="bot_access_id" value="<?= (int)$data['id'] ?>">
                                                                    <input type="hidden" name="allow_read_server" value="<?= $allowReadServer ? '0' : '1' ?>">
                                                                    <input type="hidden" name="allow_read_customer" value="<?= $allowReadCustomer ? '1' : '0' ?>">
                                                                    <input type="hidden" name="allow_create_payment_code" value="<?= $allowCreatePaymentCode ? '1' : '0' ?>">
                                                                    <button type="submit" class="btn btn-sm <?= $allowReadServer ? 'btn-outline-danger' : 'btn-outline-success' ?>">
                                                                        <i class="fas <?= $allowReadServer ? 'fa-server' : 'fa-lock' ?> me-1"></i>
                                                                        <?= $allowReadServer ? 'Matikan' : 'Aktifkan' ?> Baca Server
                                                                    </button>
                                                                </form>
                                                                <form method="post" class="mt-2">
                                                                    <input type="hidden" name="save_bot_access_permissions" value="1">
                                                                    <input type="hidden" name="bot_access_id" value="<?= (int)$data['id'] ?>">
                                                                    <input type="hidden" name="allow_read_server" value="<?= $allowReadServer ? '1' : '0' ?>">
                                                                    <input type="hidden" name="allow_read_customer" value="<?= $allowReadCustomer ? '0' : '1' ?>">
                                                                    <input type="hidden" name="allow_create_payment_code" value="<?= $allowCreatePaymentCode ? '1' : '0' ?>">
                                                                    <button type="submit" class="btn btn-sm <?= $allowReadCustomer ? 'btn-outline-danger' : 'btn-outline-success' ?>">
                                                                        <i class="fas <?= $allowReadCustomer ? 'fa-user-times' : 'fa-user-check' ?> me-1"></i>
                                                                        <?= $allowReadCustomer ? 'Matikan' : 'Aktifkan' ?> Baca Pelanggan
                                                                    </button>
                                                                </form>
                                                                <form method="post" class="mt-2">
                                                                    <input type="hidden" name="save_bot_access_permissions" value="1">
                                                                    <input type="hidden" name="bot_access_id" value="<?= (int)$data['id'] ?>">
                                                                    <input type="hidden" name="allow_read_server" value="<?= $allowReadServer ? '1' : '0' ?>">
                                                                    <input type="hidden" name="allow_read_customer" value="<?= $allowReadCustomer ? '1' : '0' ?>">
                                                                    <input type="hidden" name="allow_create_payment_code" value="<?= $allowCreatePaymentCode ? '0' : '1' ?>">
                                                                    <button type="submit" class="btn btn-sm <?= $allowCreatePaymentCode ? 'btn-outline-danger' : 'btn-outline-success' ?>">
                                                                        <i class="fas <?= $allowCreatePaymentCode ? 'fa-money-bill-wave' : 'fa-unlock-alt' ?> me-1"></i>
                                                                        <?= $allowCreatePaymentCode ? 'Matikan' : 'Aktifkan' ?> Buat Kode Bayar
                                                                    </button>
                                                                </form>
                                                                <span class="badge <?= $allowReadServer ? 'bg-success' : 'bg-secondary' ?>">Read Server <?= $allowReadServer ? 'ON' : 'OFF' ?></span>
                                                                <span class="badge <?= $allowReadCustomer ? 'bg-success' : 'bg-secondary' ?>">Read Pelanggan <?= $allowReadCustomer ? 'ON' : 'OFF' ?></span>
                                                                <span class="badge <?= $allowCreatePaymentCode ? 'bg-success' : 'bg-secondary' ?>">Kode Bayar <?= $allowCreatePaymentCode ? 'ON' : 'OFF' ?></span>
                                                            </div>

                                                            <!-- <div class="modal-action-group">
                                                                <div class="modal-action-group-title">Device Authentication</div>
                                                                <button type="button" class="btn btn-sm btn-success" onclick="handleBotLogin('<?= addslashes($data['addressbot']) ?>', '<?= addslashes($data['id']) ?>')">
                                                                    <i class="fas fa-sign-in-alt me-1"></i> Login
                                                                </button>
                                                                <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#loginCodeModal<?= $data['id'] ?>">
                                                                    <i class="fas fa-qrcode me-1"></i> Login dengan Kode
                                                                </button>
                                                                <button type="button" class="btn btn-sm btn-warning" onclick="handleBotReconnect('<?= addslashes($data['addressbot']) ?>', '<?= addslashes($data['id']) ?>')">
                                                                    <i class="fas fa-sync me-1"></i> Reconnect
                                                                </button>
                                                                <button type="button" class="btn btn-sm btn-danger" onclick="handleBotLogout('<?= addslashes($data['addressbot']) ?>', '<?= addslashes($data['id']) ?>')">
                                                                    <i class="fas fa-sign-out-alt me-1"></i> Logout
                                                                </button>
                                                            </div> -->

                                                            <div class="modal-action-group">
                                                                <div class="modal-action-group-title">Kontrol Bot</div>
                                                                <?php if (($data['tipe_bot'] ?? 'gowa') === 'unofficial_api'): ?>
                                                                    <small class="text-muted d-block">Bot ini dikelola oleh layanan eksternal (Bot WhatsApp Lain) — tidak ada service Docker lokal yang perlu di-start/stop di server ini.</small>
                                                                <?php elseif ($isActive): ?>
                                                                    <a href="?stop_service=1&bot=<?= urlencode($volumeName) ?>&url=<?= urlencode($data['addressbot']) ?>"
                                                                        class="btn btn-sm btn-danger"
                                                                        onclick="return confirm('Yakin ingin mematikan BOT <?= htmlspecialchars($volumeName) ?>?')">
                                                                        Stop
                                                                    </a>
                                                                <?php else: ?>
                                                                    <a href="?start_service=1&bot=<?= urlencode($volumeName) ?>&url=<?= urlencode($data['addressbot']) ?>"
                                                                        class="btn btn-sm btn-success"
                                                                        onclick="return confirm('Yakin ingin menyalakan BOT <?= htmlspecialchars($volumeName) ?>?')">
                                                                        Start auto respone
                                                                    </a>
                                                                <?php endif; ?>
                                                                <form method="POST" onsubmit="return confirm('Yakin ingin menghapus data ini?');">
                                                                    <input type="hidden" name="id" value="<?php echo $data['id']; ?>">
                                                                    <input type="hidden" name="addressbot" value="<?php echo $data['addressbot']; ?>">
                                                                    <input type="hidden" name="namebot" value="<?php echo $data['namebot']; ?>">
                                                                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <?php
                                        // Get prompt content
                                        $filePath = "../webhook/{$data['namebot']}.txt";
                                        $promptContent = file_exists($filePath) ? file_get_contents($filePath) : '';
                                        ?>
                                        <!-- Edit Prompt Modal -->
                                        <div class="modal fade" id="editModal<?= $data['id'] ?>" tabindex="-1" aria-labelledby="editModalLabel<?= $data['id'] ?>" aria-hidden="true">
                                            <div class="modal-dialog modal-lg">
                                                <div class="modal-content">
                                                    <form method="post">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title" id="editModalLabel<?= $data['id'] ?>">Edit Prompt for <?= $data['namebot'] ?></h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <input type="hidden" name="nama" value="<?= $data['namebot'] ?>">
                                                            <div class="mb-3">
                                                                <label class="form-label">Prompt Instructions</label>
                                                                <textarea class="form-control" name="catatan" rows="15" style="font-family: monospace;"><?= htmlspecialchars($promptContent) ?></textarea>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn btn-primary">Save Changes</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Menu 7 Modal (Laporan Gangguan) -->
                                        <div class="modal fade" id="menu7Modal<?= $data['id'] ?>" tabindex="-1" aria-labelledby="menu7ModalLabel<?= $data['id'] ?>" aria-hidden="true">
                                            <div class="modal-dialog modal-lg">
                                                <div class="modal-content">
                                                    <form method="post">
                                                        <div class="modal-header bg-danger text-white">
                                                            <h5 class="modal-title" id="menu7ModalLabel<?= $data['id'] ?>">
                                                                <i class="fas fa-bug me-2"></i>Edit Menu 7 - Laporan Gangguan untuk <?= $data['namebot'] ?>
                                                            </h5>
                                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <input type="hidden" name="save_menu7_template" value="1">
                                                            <input type="hidden" name="botname_menu7" value="<?= $data['namebot'] ?>">
                                                            
                                                            <?php
                                                            require_once '../webhook/menu_config_helper.php';
                                                            $menu7Template = loadMenu7Template($data['namebot']);
                                                            $menu7NotReg = loadMenu7NotRegisteredMessage($data['namebot']);
                                                            ?>
                                                            
                                                            <div class="alert alert-info">
                                                                <strong>📝 Template Menu 7</strong><br>
                                                                Edit template respons untuk laporan gangguan. Gunakan <code>{CUSTOMER_DATA}</code>  sebagai placeholder untuk data pelanggan otomatis dan triger <code>Keluhan:</code>.
                                                            </div>
                                                            
                                                            <div class="mb-3">
                                                                <label class="form-label fw-bold">Template Respons (gunakan {CUSTOMER_DATA} untuk data otomatis)</label>
                                                                <textarea class="form-control" name="menu7_template" rows="12" style="font-family: monospace; font-size: 12px;" required><?= htmlspecialchars($menu7Template) ?></textarea>
                                                                <small class="text-muted">Variabel yang bisa digunakan: {CUSTOMER_DATA}</small>
                                                            </div>
                                                            
                                                            <div class="mb-3">
                                                                <label class="form-label fw-bold">Pesan Jika Customer Tidak Terdaftar</label>
                                                                <textarea class="form-control" name="menu7_not_registered" rows="3" required><?= htmlspecialchars($menu7NotReg) ?></textarea>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                                            <button type="submit" class="btn btn-danger">💾 Simpan Menu 7</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Menu 10 Modal (Data Pelanggan) -->
                                        <div class="modal fade" id="menu10Modal<?= $data['id'] ?>" tabindex="-1" aria-labelledby="menu10ModalLabel<?= $data['id'] ?>" aria-hidden="true">
                                            <div class="modal-dialog modal-lg">
                                                <div class="modal-content">
                                                    <form method="post">
                                                        <div class="modal-header bg-info text-white">
                                                            <h5 class="modal-title" id="menu10ModalLabel<?= $data['id'] ?>">
                                                                <i class="fas fa-user me-2"></i>Edit Menu 10 - Data Pelanggan untuk <?= $data['namebot'] ?>
                                                            </h5>
                                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <input type="hidden" name="save_menu10_template" value="1">
                                                            <input type="hidden" name="botname_menu10" value="<?= $data['namebot'] ?>">
                                                            
                                                            <?php
                                                            require_once '../webhook/menu_config_helper.php';
                                                            $menu10Template = loadMenu10Template($data['namebot']);
                                                            $menu10NotReg = loadMenu10NotRegisteredMessage($data['namebot']);
                                                            ?>
                                                            
                                                            <div class="alert alert-info">
                                                                <strong>📝 Template Menu 10</strong><br>
                                                                Edit template respons untuk data pelanggan. Gunakan placeholder variabel seperti {IDPEL}, {NAMA}, {PAKET}, {HARGA}, {ALAMAT}, {TANGGALPASANG}, {EMAIL}, {ODP}, {AREA}.
                                                            </div>
                                                            
                                                            <div class="mb-3">
                                                                <label class="form-label fw-bold">Template Respons (gunakan placeholder {VARIABLE})</label>
                                                                <textarea class="form-control" name="menu10_template" rows="12" style="font-family: monospace; font-size: 12px;" required><?= htmlspecialchars($menu10Template) ?></textarea>
                                                                <small class="text-muted d-block mt-2">
                                                                    <strong>Variabel yang bisa digunakan:</strong><br>
                                                                    {IDPEL} | {NAMA} | {PAKET} | {HARGA} | {ALAMAT} | {TANGGALPASANG} | {EMAIL} | {ODP} | {AREA}
                                                                </small>
                                                            </div>
                                                            
                                                            <div class="mb-3">
                                                                <label class="form-label fw-bold">Pesan Jika Customer Tidak Terdaftar</label>
                                                                <textarea class="form-control" name="menu10_not_registered" rows="3" required><?= htmlspecialchars($menu10NotReg) ?></textarea>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                                            <button type="submit" class="btn btn-info">💾 Simpan Menu 10</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>

                                        <?php
                                        $aiModelsArr = json_decode((string)($data['ai_models'] ?? ''), true);
                                        if (!is_array($aiModelsArr)) {
                                            $aiModelsArr = [];
                                        }
                                        $aiModelsText = implode("\n", $aiModelsArr);
                                        $aiSelectedProvider = $data['ai_provider'] ?: 'cerebras';
                                        if (!array_key_exists($aiSelectedProvider, $aiCatalog)) {
                                            $aiSelectedProvider = 'cerebras';
                                        }
                                        ?>
                                        <!-- AI Provider Modal -->
                                        <div class="modal fade" id="aiProviderModal<?= $data['id'] ?>" tabindex="-1" aria-labelledby="aiProviderModalLabel<?= $data['id'] ?>" aria-hidden="true">
                                            <div class="modal-dialog modal-lg">
                                                <div class="modal-content">
                                                    <form method="post">
                                                        <div class="modal-header bg-dark text-white">
                                                            <h5 class="modal-title" id="aiProviderModalLabel<?= $data['id'] ?>">
                                                                <i class="fas fa-robot me-2"></i>AI Provider Bot - <?= htmlspecialchars($data['namebot']) ?>
                                                            </h5>
                                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <input type="hidden" name="save_ai_settings" value="1">
                                                            <input type="hidden" name="bot_ai_id" value="<?= (int)$data['id'] ?>">

                                                            <div class="alert alert-info">
                                                                <strong>🤖 AI Provider per Bot</strong><br>
                                                                Tiap bot WA bisa pakai provider AI dan API key/token yang berbeda-beda. Semua provider di bawah kompatibel format OpenAI Chat Completions.
                                                            </div>

                                                            <div class="mb-3">
                                                                <label class="form-label fw-bold">Provider AI</label>
                                                                <div class="input-group">
                                                                    <select class="form-select" name="ai_provider" id="aiProviderSelect<?= $data['id'] ?>" onchange="wbAiProviderLinkUpdate(<?= (int)$data['id'] ?>)" required>
                                                                        <?php foreach ($aiCatalog as $provKey => $provInfo): ?>
                                                                            <option value="<?= htmlspecialchars($provKey) ?>" data-website="<?= htmlspecialchars($provInfo['website']) ?>" <?= $aiSelectedProvider === $provKey ? 'selected' : '' ?>>
                                                                                <?= htmlspecialchars($provInfo['label']) ?>
                                                                            </option>
                                                                        <?php endforeach; ?>
                                                                    </select>
                                                                    <a href="<?= htmlspecialchars($aiCatalog[$aiSelectedProvider]['website']) ?>" target="_blank" rel="noopener" id="aiProviderLink<?= $data['id'] ?>" class="btn btn-outline-secondary">
                                                                        <i class="fas fa-external-link-alt me-1"></i> Buka Provider
                                                                    </a>
                                                                </div>
                                                            </div>

                                                            <div class="mb-3">
                                                                <label class="form-label fw-bold">API Key / Token</label>
                                                                <input type="text" class="form-control" name="ai_api_key" value="<?= htmlspecialchars($data['ai_api_key'] ?? '') ?>" placeholder="API key khusus bot ini" autocomplete="off">
                                                                <small class="text-muted">Boleh beda-beda tiap bot.</small>
                                                            </div>

                                                            <div class="mb-3">
                                                                <label class="form-label fw-bold">Endpoint Custom (opsional)</label>
                                                                <input type="text" class="form-control" name="ai_base_url" value="<?= htmlspecialchars($data['ai_base_url'] ?? '') ?>" placeholder="Wajib diisi jika Provider = Provider Lain (Custom)">
                                                                <small class="text-muted">Kosongkan untuk pakai endpoint default provider di atas. Boleh isi base URL saja (mis. https://api.cerebras.ai/v1), "/chat/completions" akan ditambahkan otomatis kalau belum ada.</small>
                                                            </div>

                                                            <div class="mb-3">
                                                                <label class="form-label fw-bold">Model (1 baris = 1 model)</label>
                                                                <textarea class="form-control" name="ai_models" rows="4" style="font-family: monospace;" placeholder="contoh:&#10;llama3.1-8b&#10;llama3.3-70b"><?= htmlspecialchars($aiModelsText) ?></textarea>
                                                                <small class="text-muted">Bisa isi 1 atau banyak model. Model paling atas = model utama; kalau gagal, otomatis dicoba model baris berikutnya.</small>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                                            <button type="submit" class="btn btn-dark">💾 Simpan AI Provider</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>

                                        <?php
                                        $autoResponEnabledVal = !isset($data['auto_respon_enabled']) || (int)$data['auto_respon_enabled'] === 1;
                                        $autoResponTriggerOnVal = trim((string)($data['auto_respon_trigger_on'] ?? '')) !== '' ? $data['auto_respon_trigger_on'] : '###';
                                        $autoResponTriggerOffVal = trim((string)($data['auto_respon_trigger_off'] ?? '')) !== '' ? $data['auto_respon_trigger_off'] : '***';
                                        ?>
                                        <!-- Trigger Control Auto Respon Modal -->
                                        <div class="modal fade" id="autoResponModal<?= $data['id'] ?>" tabindex="-1" aria-labelledby="autoResponModalLabel<?= $data['id'] ?>" aria-hidden="true">
                                            <div class="modal-dialog modal-lg">
                                                <div class="modal-content">
                                                    <form method="post">
                                                        <div class="modal-header bg-success text-white">
                                                            <h5 class="modal-title" id="autoResponModalLabel<?= $data['id'] ?>">
                                                                <i class="fas fa-toggle-on me-2"></i>Trigger Control Auto Respon - <?= htmlspecialchars($data['namebot']) ?>
                                                            </h5>
                                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <input type="hidden" name="save_auto_respon_settings" value="1">
                                                            <input type="hidden" name="bot_autorespon_id" value="<?= (int)$data['id'] ?>">

                                                            <div class="alert alert-info">
                                                                <strong><i class="fas fa-info-circle me-1"></i> Cara Kerja Auto Respon ON/OFF via Chat</strong><br>
                                                                Auto respon (balasan otomatis ke pelanggan - AI, menu, maupun keyword) bisa dinyalakan/dimatikan langsung dari WhatsApp tanpa buka halaman ini, dengan cara <strong>chat ke nomor bot ini sendiri</strong> (kirim pesan dari nomor bot ke nomor bot itu sendiri / self-chat):
                                                                <ul class="mb-0 mt-2">
                                                                    <li>Ketik <code><?= htmlspecialchars($autoResponTriggerOnVal) ?></code> untuk <strong>MENGHIDUPKAN</strong> auto respon.</li>
                                                                    <li>Ketik <code><?= htmlspecialchars($autoResponTriggerOffVal) ?></code> untuk <strong>MEMATIKAN</strong> auto respon.</li>
                                                                </ul>
                                                                Saat auto respon OFF, bot tidak akan membalas pesan pelanggan sama sekali (AI, menu, maupun keyword) sampai dihidupkan lagi lewat trigger di atas atau toggle di bawah ini. Kata trigger boleh diganti sesuai kebutuhan Anda.
                                                            </div>

                                                            <div class="form-check form-switch mb-3">
                                                                <input class="form-check-input" type="checkbox" role="switch" id="autoResponSwitch<?= $data['id'] ?>" name="auto_respon_enabled" value="1" <?= $autoResponEnabledVal ? 'checked' : '' ?> onchange="document.getElementById('autoResponSwitchLabel<?= $data['id'] ?>').textContent = this.checked ? 'Auto Respon AKTIF' : 'Auto Respon NONAKTIF';">
                                                                <label class="form-check-label fw-bold" id="autoResponSwitchLabel<?= $data['id'] ?>" for="autoResponSwitch<?= $data['id'] ?>">
                                                                    Auto Respon <?= $autoResponEnabledVal ? 'AKTIF' : 'NONAKTIF' ?>
                                                                </label>
                                                            </div>

                                                            <div class="mb-3">
                                                                <label class="form-label fw-bold">Kata Trigger untuk HIDUPKAN Auto Respon</label>
                                                                <input type="text" class="form-control" name="auto_respon_trigger_on" value="<?= htmlspecialchars($autoResponTriggerOnVal) ?>" maxlength="20" required>
                                                                <small class="text-muted">Dikirim dari chat bot ke diri sendiri untuk menghidupkan auto respon. Default: ###</small>
                                                            </div>

                                                            <div class="mb-3">
                                                                <label class="form-label fw-bold">Kata Trigger untuk MATIKAN Auto Respon</label>
                                                                <input type="text" class="form-control" name="auto_respon_trigger_off" value="<?= htmlspecialchars($autoResponTriggerOffVal) ?>" maxlength="20" required>
                                                                <small class="text-muted">Dikirim dari chat bot ke diri sendiri untuk mematikan auto respon. Default: ***</small>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                                            <button type="submit" class="btn btn-success">💾 Simpan Auto Respon</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>

                                        <script>
                                        function wbAiProviderLinkUpdate(botId) {
                                            const select = document.getElementById('aiProviderSelect' + botId);
                                            const link = document.getElementById('aiProviderLink' + botId);
                                            if (!select || !link) return;
                                            const website = select.options[select.selectedIndex].getAttribute('data-website') || '';
                                            if (website) {
                                                link.href = website;
                                                link.classList.remove('disabled');
                                            } else {
                                                link.href = '#';
                                                link.classList.add('disabled');
                                            }
                                        }
                                        </script>

                                        <!-- AI Tester Modal -->
                                        <div class="modal fade" id="aiTesterModal<?= $data['id'] ?>" tabindex="-1" aria-labelledby="aiTesterModalLabel<?= $data['id'] ?>" aria-hidden="true">
                                            <div class="modal-dialog modal-lg">
                                                <div class="modal-content">
                                                    <div class="modal-header bg-dark text-white">
                                                        <h5 class="modal-title" id="aiTesterModalLabel<?= $data['id'] ?>">
                                                            <i class="fas fa-vial me-2"></i>Tes AI - <?= htmlspecialchars($data['namebot']) ?>
                                                        </h5>
                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <div class="alert alert-info">
                                                            <strong>🧪 Simulasi Respons AI</strong><br>
                                                            Ketik pesan seolah-olah dari pelanggan WhatsApp. Sistem akan memanggil provider/API key/model
                                                            AI yang <em>sudah tersimpan</em> untuk bot ini (menu AI Provider) plus prompt bot yang aktif,
                                                            tanpa benar-benar mengirim pesan WhatsApp.
                                                        </div>
                                                        <div class="mb-3">
                                                            <label for="aiTesterInput<?= $data['id'] ?>" class="form-label fw-bold">Pesan Pelanggan (Simulasi)</label>
                                                            <textarea class="form-control" id="aiTesterInput<?= $data['id'] ?>" rows="3" placeholder="Contoh: Berapa harga paket 20Mbps?">Halo, mau tanya info paket wifi.</textarea>
                                                        </div>
                                                        <button type="button" class="btn btn-dark" id="aiTesterBtn<?= $data['id'] ?>" onclick="wbAiTesterRun(<?= (int)$data['id'] ?>)">
                                                            <i class="fas fa-play me-1"></i> Jalankan Tes
                                                        </button>
                                                        <hr>
                                                        <label class="form-label fw-bold">Respons AI</label>
                                                        <div id="aiTesterResult<?= $data['id'] ?>" class="border rounded p-3 bg-light" style="min-height: 90px; white-space: pre-wrap; font-family: monospace; font-size: 13px;">
                                                            <span class="text-muted">Belum ada hasil tes.</span>
                                                        </div>
                                                        <div id="aiTesterMeta<?= $data['id'] ?>" class="text-muted small mt-1"></div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <script>
                                        function wbAiTesterRun(botId) {
                                            const input = document.getElementById('aiTesterInput' + botId);
                                            const resultBox = document.getElementById('aiTesterResult' + botId);
                                            const metaBox = document.getElementById('aiTesterMeta' + botId);
                                            const btn = document.getElementById('aiTesterBtn' + botId);
                                            const message = (input && input.value ? input.value : '').trim();

                                            if (!message) {
                                                alert('Isi dulu pesan simulasinya.');
                                                return;
                                            }

                                            const originalBtnHtml = btn.innerHTML;
                                            btn.disabled = true;
                                            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Menguji...';
                                            resultBox.innerHTML = '<span class="text-muted">Menunggu respons AI...</span>';
                                            metaBox.textContent = '';

                                            const body = new URLSearchParams();
                                            body.set('ai_test_action', '1');
                                            body.set('ai_test_bot_id', String(botId));
                                            body.set('ai_test_message', message);

                                            fetch(window.location.pathname, {
                                                method: 'POST',
                                                headers: {
                                                    'Content-Type': 'application/x-www-form-urlencoded',
                                                    'Accept': 'application/json'
                                                },
                                                body: body.toString()
                                            })
                                            .then(response => response.json())
                                            .then(result => {
                                                btn.disabled = false;
                                                btn.innerHTML = originalBtnHtml;
                                                if (result && result.success) {
                                                    resultBox.textContent = result.response;
                                                    resultBox.classList.remove('border-danger');
                                                    metaBox.textContent = 'Waktu respons: ' + result.elapsed_ms + ' ms';
                                                } else {
                                                    resultBox.textContent = (result && (result.response || result.message)) || 'Gagal menguji AI.';
                                                    resultBox.classList.add('border-danger');
                                                    metaBox.textContent = '';
                                                }
                                            })
                                            .catch(err => {
                                                btn.disabled = false;
                                                btn.innerHTML = originalBtnHtml;
                                                resultBox.textContent = '❌ Gagal menghubungi server: ' + err.message;
                                                resultBox.classList.add('border-danger');
                                            });
                                        }
                                        </script>

                                        <!-- Operational Hours Modal -->
                                        <div class="modal fade theme-adaptive-modal" id="operationalModal<?= $data['id'] ?>" tabindex="-1" aria-labelledby="operationalModalLabel<?= $data['id'] ?>" aria-hidden="true">
                                            <div class="modal-dialog modal-lg">
                                                <div class="modal-content">
                                                    <form method="post">
                                                        <div class="modal-header operational-modal-header">
                                                            <h5 class="modal-title text-white" id="operationalModalLabel<?= $data['id'] ?>">
                                                                <i class="fas fa-clock me-2"></i>Jam Operasional Bot - <?= htmlspecialchars($data['namebot']) ?>
                                                            </h5>
                                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <input type="hidden" name="save_operational_hours" value="1">
                                                            <input type="hidden" name="bot_operational_name" value="<?= htmlspecialchars($data['namebot']) ?>">
                                                            
                                                            <?php
                                                            require_once '../webhook/operational_hours_helper.php';
                                                            $opHours = loadOperationalHours($data['namebot']);
                                                            $availableDays = getAllOperationalDays();
                                                            $timezones = getAllTimezones();
                                                            ?>
                                                            
                                                            <!-- Alert Info -->
                                                            <div class="alert bg-adaptive-info" role="alert" style="border: 2px solid #17a2b8; border-radius: 10px; box-shadow: 0 4px 15px rgba(23, 162, 184, 0.3);">
                                                                <i class="fas fa-info-circle me-2" style="color: #17a2b8; font-size: 20px;"></i>
                                                                <strong class="text-adaptive-label op-section-title">ℹ️ Penjelasan:</strong><br>
                                                                <span class="text-adaptive" style="font-size: 14px; line-height: 1.6; font-weight: 600;">Aktifkan fitur ini untuk membatasi jam merespons bot. Bot hanya akan merespons pesanan saat dalam jam dan hari kerja yang diatur.</span>
                                                            </div>
                                                            
                                                            <!-- Enable Operational Hours -->
                                                            <div class="mb-4 p-3 bg-adaptive-dark" style="border-radius: 10px; border: 2px solid #17a2b8; box-shadow: 0 4px 15px rgba(23, 162, 184, 0.2);">
                                                                <div class="form-check form-switch">
                                                                    <input class="form-check-input" type="checkbox" id="operationalEnabled<?= $data['id'] ?>" 
                                                                           name="operational_enabled" value="1" 
                                                                           <?= $opHours['enabled'] ? 'checked' : '' ?>
                                                                           onchange="toggleOperationalFields<?= $data['id'] ?>()"
                                                                           style="cursor: pointer; width: 50px; height: 25px;">
                                                                    <label class="form-check-label fw-bold text-adaptive" for="operationalEnabled<?= $data['id'] ?>" style="font-size: 17px; cursor: pointer; margin-left: 10px; letter-spacing: 0.5px;">
                                                                        🔧 Aktifkan Jam Operasional
                                                                    </label>
                                                                </div>
                                                                <small class="op-helper">💡 Jika diaktifkan, bot akan terbatas merespons hanya pada jam kerja</small>
                                                            </div>
                                                            
                                                            <div id="operationalFieldsContainer<?= $data['id'] ?>" style="display: <?= $opHours['enabled'] ? 'block' : 'none' ?>;">
                                                                
                                                                <!-- Timezone -->
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-bold text-adaptive-label op-section-title">🌍 Zona Waktu</label>
                                                                    <select class="form-control form-control-lg op-field" name="operational_timezone" required style="font-size: 16px !important; font-weight: 600 !important; padding: 12px !important;">
                                                                        <option value="">-- Pilih Zona Waktu --</option>
                                                                        <?php foreach ($timezones as $tz => $tzLabel): ?>
                                                                            <option value="<?= htmlspecialchars($tz) ?>" <?= $opHours['timezone'] === $tz ? 'selected' : '' ?>>
                                                                                <?= htmlspecialchars($tzLabel) ?>
                                                                            </option>
                                                                        <?php endforeach; ?>
                                                                    </select>
                                                                    <small class="op-helper">💡 Pilih zona waktu sesuai lokasi server/bisnis Anda</small>
                                                                </div>

                                                                <hr class="op-divider">
                                                                
                                                                <!-- Time Range -->
                                                                <div class="row mb-3">
                                                                    <div class="col-md-6">
                                                                          <label class="form-label fw-bold text-adaptive-label op-section-title">⏰ Jam Mulai Operasional</label>
                                                                          <input type="time" class="form-control form-control-lg op-field" name="operational_start_time" 
                                                                               value="<?= htmlspecialchars($opHours['start_time']) ?>" required 
                                                                              style="font-size: 18px !important; font-weight: 700 !important; padding: 14px !important; letter-spacing: 2px !important;">
                                                                          <small class="op-helper">💡 Contoh: 09:00</small>
                                                                    </div>
                                                                    <div class="col-md-6">
                                                                          <label class="form-label fw-bold text-adaptive-label op-section-title">🏁 Jam Akhir Operasional</label>
                                                                          <input type="time" class="form-control form-control-lg op-field" name="operational_end_time" 
                                                                               value="<?= htmlspecialchars($opHours['end_time']) ?>" required 
                                                                              style="font-size: 18px !important; font-weight: 700 !important; padding: 14px !important; letter-spacing: 2px !important;">
                                                                          <small class="op-helper">💡 Contoh: 17:00</small>
                                                                    </div>
                                                                </div>

                                                                     <hr class="op-divider">
                                                                
                                                                <!-- Operating Days -->
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-bold text-adaptive-label op-section-title">📅 Hari Operasional (Pilih Semua Hari Kerja)</label>
                                                                    <div class="card p-4 bg-adaptive-card" style="border: 2px solid #17a2b8; box-shadow: 0 0 15px rgba(23, 162, 184, 0.3);">
                                                                        <div class="row">
                                                                            <?php foreach ($availableDays as $index => $day): ?>
                                                                            <div class="col-md-6 mb-3">
                                                                                <div class="form-check bg-adaptive-dark" style="padding: 10px 15px; border-radius: 6px; border: 1px solid rgba(23, 162, 184, 0.3);">
                                                                                    <input class="form-check-input" type="checkbox" id="day_<?= $data['id'] ?>_<?= $day ?>" 
                                                                                           name="operational_days[]" value="<?= htmlspecialchars($day) ?>"
                                                                                           <?= in_array($day, $opHours['days']) ? 'checked' : '' ?>
                                                                                           style="cursor: pointer; width: 22px; height: 22px; margin-top: 3px;">
                                                                                    <label class="form-check-label text-adaptive" for="day_<?= $data['id'] ?>_<?= $day ?>" style="cursor: pointer; font-size: 16px; font-weight: 700; margin-left: 12px; letter-spacing: 0.5px;">
                                                                                        <?= htmlspecialchars($day) ?>
                                                                                    </label>
                                                                                </div>
                                                                            </div>
                                                                            <?php endforeach; ?>
                                                                        </div>
                                                                    </div>
                                                                    <small class="op-helper" style="margin-top: 12px; font-size: 13px;">💡 Centang hari-hari ketika bot harus aktif merespons</small>
                                                                </div>

                                                                <hr class="op-divider">
                                                                
                                                                <!-- Offline Mode -->
                                                                <div class="mb-3 p-3 bg-adaptive-warning" style="border-radius: 10px; border: 2px solid #ffc107; box-shadow: 0 4px 15px rgba(255, 193, 7, 0.3);">
                                                                    <div class="form-check form-switch offline-toggle-switch">
                                                                        <input class="form-check-input" type="checkbox" id="offlineMode<?= $data['id'] ?>" 
                                                                               name="operational_offline_mode" value="1"
                                                                               <?= $opHours['offline_mode'] ? 'checked' : '' ?>
                                                                               style="cursor: pointer; width: 50px; height: 25px;">
                                                                        <label class="form-check-label fw-bold text-adaptive" for="offlineMode<?= $data['id'] ?>" style="font-size: 16px; cursor: pointer; margin-left: 10px; letter-spacing: 0.5px;">
                                                                            🤖 Kirim Pesan Otomatis saat Bot Tutup
                                                                        </label>
                                                                    </div>
                                                                    <small class="op-warning-note">
                                                                        💡 Jika diaktifkan: Bot kirim balasan otomatis saat di luar jam operasional<br>
                                                                        ⛔ Jika dinonaktifkan: Bot tidak merespons sama sekali saat tutup
                                                                    </small>
                                                                </div>
                                                                
                                                                <!-- Outside Hours Message -->
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-bold text-adaptive-label op-section-title">💬 Pesan Balasan Saat Bot Tutup</label>
                                                                    <textarea class="form-control op-field" name="operational_message" rows="4" 
                                                                              placeholder="Contoh: Maaf, layanan kami sedang tutup. Kami akan merespons pada jam operasional 09:00-17:00 Senin-Jumat. Terima kasih telah menghubungi 🙏" 
                                                                              style="font-size: 15px !important; font-weight: 500 !important; padding: 14px !important; line-height: 1.6 !important; resize: vertical !important;"><?= htmlspecialchars($opHours['message_outside_hours']) ?></textarea>
                                                                    <small class="op-helper">
                                                                        💡 Pesan ini hanya terkirim jika mode "Kirim Pesan Otomatis" diaktifkan
                                                                    </small>
                                                                </div>
                                                                
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border: none;">❌ Batal</button>
                                                            <button type="submit" class="btn btn-primary btn-lg" style="background-color: #17a2b8; color: white !important; border: none; font-weight: 600;">✅ Simpan Pengaturan</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Modal Login with Code -->
                                        <div class="modal fade" id="loginCodeModal<?= $data['id'] ?>" tabindex="-1" aria-labelledby="loginCodeModalLabel<?= $data['id'] ?>" aria-hidden="true">
                                            <div class="modal-dialog modal-dialog-centered">
                                                <div class="modal-content">
                                                    <div class="modal-header bg-info text-white">
                                                        <h5 class="modal-title" id="loginCodeModalLabel<?= $data['id'] ?>">
                                                            <i class="fas fa-qrcode me-2"></i>Login dengan Kode Pairing untuk <?= htmlspecialchars($data['namebot']) ?>
                                                        </h5>
                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <div class="mb-3">
                                                            <label for="phoneInput<?= $data['id'] ?>" class="form-label">Nomor Telepon (Dengan Kode Negara)</label>
                                                            <input type="text" class="form-control" id="phoneInput<?= $data['id'] ?>" placeholder="628912344551" value="">
                                                            <small class="text-muted d-block mt-2">
                                                                <i class="fas fa-info-circle me-1"></i>
                                                                Masukkan nomor telepon lengkap dengan kode negara (contoh: 628912344551)
                                                            </small>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                                        <button type="button" class="btn btn-info" onclick="handleLoginWithCode('<?= addslashes($data['addressbot']) ?>', '<?= addslashes($data['id']) ?>')">
                                                            <i class="fas fa-sign-in-alt me-1"></i> Login Sekarang
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <script>
                                        function toggleOperationalFields<?= $data['id'] ?>() {
                                            const checkbox = document.getElementById('operationalEnabled<?= $data['id'] ?>');
                                            const container = document.getElementById('operationalFieldsContainer<?= $data['id'] ?>');
                                            container.style.display = checkbox.checked ? 'block' : 'none';
                                        }
                                        </script>

                                        <?php
                                        // Load menu config untuk bot ini
                                        if ($isAdminAccess) {
                                            require_once '../webhook/menu_config_helper.php';
                                            $menuConfig = loadMenuConfig($data['namebot']);
                                        }
                                        ?>
                                        
                                        <!-- Menu Settings Modal (ADMIN Only) -->
                                        <?php if ($isAdminAccess): ?>
                                        <div class="modal fade wabot-menu-modal" id="menuModal<?= $data['id'] ?>" tabindex="-1" aria-labelledby="menuModalLabel<?= $data['id'] ?>" aria-hidden="true">
                                            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                                                <div class="modal-content">
                                                    <form method="post" id="menuForm<?= $data['id'] ?>">
                                                        <div class="modal-header bg-primary text-white">
                                                            <h5 class="modal-title" id="menuModalLabel<?= $data['id'] ?>">
                                                                <i class="fas fa-list-ul me-2"></i>Menu Settings for <?= $data['namebot'] ?>
                                                            </h5>
                                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <input type="hidden" name="save_menu_settings" value="1">
                                                            <input type="hidden" name="botname_menu" value="<?= $data['namebot'] ?>">
                                                            
                                                            <!-- Alert Info -->
                                                            <div class="alert alert-info">
                                                                <i class="fas fa-info-circle me-2"></i>
                                                                <strong>Menu Settings</strong><br>
                                                                Kelola menu dan nomor perintah untuk bot ADMIN. Atur nomor menu, label, dan pesan yang dikirim ketika user memilih menu tersebut.
                                                            </div>
                                                            
                                                            <!-- Main Menu Text -->
                                                            <div class="card mb-4">
                                                                <div class="card-header bg-light" style="padding: 12px 15px; border-bottom: 2px solid #e2e8f0;">
                                                                    <h6 class="mb-0" style="font-weight: 600; font-size: 0.95em;"><i class="fas fa-bars me-2"></i>Teks Menu Utama</h6>
                                                                </div>
                                                                <div class="card-body">
                                                                    <label class="form-label">Menu Utama (ditampilkan saat user ketik MENU / 00)</label>
                                                                    <textarea class="form-control" name="main_menu_text" rows="20" style="font-family: monospace; font-size: 11px;"><?= htmlspecialchars($menuConfig['main_menu_text']) ?></textarea>
                                                                    <small class="text-muted">Format: gunakan *teks* untuk bold, _teks_ untuk italic</small>
                                                                </div>
                                                            </div>
                                                            
                                                            <!-- Menu Items -->
                                                            <div class="card">
                                                                <div class="card-header bg-light d-flex justify-content-between align-items-center" style="padding: 12px 15px; border-bottom: 2px solid #e2e8f0;">
                                                                    <h6 class="mb-0" style="font-weight: 600; font-size: 0.95em;"><i class="fas fa-th-list me-2"></i>Daftar Item Menu</h6>
                                                                    <button type="button" class="btn btn-sm btn-success" onclick="addMenuItem<?= $data['id'] ?>()" style="font-weight: 500;">>
                                                                        <i class="fas fa-plus me-1"></i>Tambah Menu
                                                                    </button>
                                                                </div>
                                                                <div class="card-body">
                                                                    <div id="menuItemsContainer<?= $data['id'] ?>">
                                                                        <?php 
                                                                        $menuIndex = 0;
                                                                        foreach ($menuConfig['menu_list'] as $menuNum => $menuData): 
                                                                        ?>
                                                                        <div class="menu-item-row card mb-3" data-index="<?= $menuIndex ?>">
                                                                            <div class="card-body">
                                                                                <div class="menu-item-grid <?= ($menuData['action_type'] ?? 'text') === 'function' ? 'is-function' : 'is-text' ?>">
                                                                                    <div class="menu-grid-field">
                                                                                        <label class="form-label small">Nomor</label>
                                                                                        <input type="text" class="form-control form-control-sm" 
                                                                                               name="menu_numbers[]" 
                                                                                               value="<?= htmlspecialchars($menuNum) ?>" 
                                                                                               placeholder="1" required>
                                                                                    </div>
                                                                                    <div class="menu-grid-field">
                                                                                        <label class="form-label small">Label Menu</label>
                                                                                        <input type="text" class="form-control form-control-sm" 
                                                                                               name="menu_labels[]" 
                                                                                               value="<?= htmlspecialchars($menuData['label']) ?>" 
                                                                                               placeholder="Nama menu" required>
                                                                                    </div>
                                                                                    <div class="menu-grid-field">
                                                                                        <label class="form-label small">Aktif</label>
                                                                                        <div class="form-check form-switch">
                                                                                            <input class="form-check-input" type="checkbox" 
                                                                                                   name="menu_enabled[]" 
                                                                                                   value="1" 
                                                                                                   <?= $menuData['enabled'] ? 'checked' : '' ?>>
                                                                                        </div>
                                                                                    </div>
                                                                                    <div class="menu-grid-field">
                                                                                        <label class="form-label small">Tipe Action</label>
                                                                                        <select class="form-control form-control-sm menu-action-type" 
                                                                                                name="menu_action_types[]" 
                                                                                                onchange="toggleActionFields(this)">
                                                                                            <option value="text" <?= ($menuData['action_type'] ?? 'text') === 'text' ? 'selected' : '' ?>>Text</option>
                                                                                            <option value="function" <?= ($menuData['action_type'] ?? 'text') === 'function' ? 'selected' : '' ?>>Function</option>
                                                                                        </select>
                                                                                    </div>
                                                                                    <div class="menu-grid-field action-function-field" style="display: <?= ($menuData['action_type'] ?? 'text') === 'function' ? 'block' : 'none' ?>">
                                                                                        <label class="form-label small">Function Name</label>
                                                                                        <input type="text" class="form-control form-control-sm" 
                                                                                               name="menu_action_functions[]" 
                                                                                               value="<?= htmlspecialchars($menuData['action_function'] ?? '') ?>" 
                                                                                               placeholder="cekIdPelanggan">
                                                                                    </div>
                                                                                    <div class="menu-grid-field action-message-field" style="display: <?= ($menuData['action_type'] ?? 'text') === 'text' ? 'block' : 'none' ?>">
                                                                                        <label class="form-label small">Pesan Respon</label>
                                                                                        <textarea class="form-control form-control-sm" 
                                                                                                  name="menu_messages[]" 
                                                                                                  rows="3" 
                                                                                                  style="font-size: 11px;"
                                                                                                  placeholder="Pesan yang dikirim"><?= htmlspecialchars($menuData['message'] ?? '') ?></textarea>
                                                                                    </div>
                                                                                    <div class="menu-grid-field menu-grid-action">
                                                                                        <button type="button" class="btn btn-sm btn-danger" onclick="removeMenuItem(this)">
                                                                                            <i class="fas fa-trash"></i>
                                                                                        </button>
                                                                                    </div>
                                                                                </div>
                                                                            </div>
                                                                        </div>
                                                                        <?php 
                                                                        $menuIndex++;
                                                                        endforeach; 
                                                                        ?>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            
                                                            <!-- Tips -->
                                                            <div class="alert alert-warning mt-3">
                                                                <i class="fas fa-lightbulb me-2"></i>
                                                                <strong>Tips:</strong>
                                                                <ul class="mb-0 small">
                                                                    <li>Nomor menu bisa berupa angka (1, 2, 10) atau teks (MENU, INFO)</li>
                                                                    <li><strong>Tipe Action "Text":</strong> Kirim pesan statis. Untuk menu 00/MENU, gunakan "SHOW_FULL_MENU"</li>
                                                                    <li><strong>Tipe Action "Function":</strong> Panggil function PHP (cek database, dll). Contoh: cekIdPelanggan, buatLaporanGangguan, buatKodeBayarWifi</li>
                                                                    <li>Function yang tersedia: <code>cekIdPelanggan</code> (menu 10), <code>buatLaporanGangguan</code> (menu 7), <code>buatKodeBayarWifi</code> (menu 11)</li>
                                                                    <li>Matikan menu yang tidak aktif dengan toggle "Aktif"</li>
                                                                    <li>Menu hanya berlaku untuk bot milik ADMIN</li>
                                                                </ul>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                                                <i class="fas fa-times me-1"></i>Batal
                                                            </button>
                                                            <button type="submit" class="btn btn-primary">
                                                                <i class="fas fa-save me-1"></i>Simpan Menu Settings
                                                            </button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <script>
                                        function toggleActionFields(selectElement) {
                                            const grid = selectElement.closest('.menu-item-grid');
                                            const functionField = grid.querySelector('.action-function-field');
                                            const messageField = grid.querySelector('.action-message-field');
                                            
                                            if (selectElement.value === 'function') {
                                                functionField.style.display = 'block';
                                                messageField.style.display = 'none';
                                                grid.classList.add('is-function');
                                                grid.classList.remove('is-text');
                                            } else {
                                                functionField.style.display = 'none';
                                                messageField.style.display = 'block';
                                                grid.classList.add('is-text');
                                                grid.classList.remove('is-function');
                                            }
                                        }
                                        
                                        function addMenuItem<?= $data['id'] ?>() {
                                            const container = document.getElementById('menuItemsContainer<?= $data['id'] ?>');
                                            const index = container.children.length;
                                            const newItem = `
                                                <div class="menu-item-row card mb-3" data-index="${index}">
                                                    <div class="card-body">
                                                        <div class="menu-item-grid is-text">
                                                            <div class="menu-grid-field">
                                                                <label class="form-label small">Nomor</label>
                                                                <input type="text" class="form-control form-control-sm" 
                                                                       name="menu_numbers[]" 
                                                                       placeholder="1" required>
                                                            </div>
                                                            <div class="menu-grid-field">
                                                                <label class="form-label small">Label Menu</label>
                                                                <input type="text" class="form-control form-control-sm" 
                                                                       name="menu_labels[]" 
                                                                       placeholder="Nama menu" required>
                                                            </div>
                                                            <div class="menu-grid-field">
                                                                <label class="form-label small">Aktif</label>
                                                                <div class="form-check form-switch">
                                                                    <input class="form-check-input" type="checkbox" 
                                                                           name="menu_enabled[]" 
                                                                           value="1" checked>
                                                                </div>
                                                            </div>
                                                            <div class="menu-grid-field">
                                                                <label class="form-label small">Tipe Action</label>
                                                                <select class="form-control form-control-sm menu-action-type" 
                                                                        name="menu_action_types[]" 
                                                                        onchange="toggleActionFields(this)">
                                                                    <option value="text" selected>Text</option>
                                                                    <option value="function">Function</option>
                                                                </select>
                                                            </div>
                                                            <div class="menu-grid-field action-function-field" style="display: none;">
                                                                <label class="form-label small">Function Name</label>
                                                                <input type="text" class="form-control form-control-sm" 
                                                                       name="menu_action_functions[]" 
                                                                       placeholder="cekIdPelanggan">
                                                            </div>
                                                            <div class="menu-grid-field action-message-field" style="display: block;">
                                                                <label class="form-label small">Pesan Respon</label>
                                                                <textarea class="form-control form-control-sm" 
                                                                          name="menu_messages[]" 
                                                                          rows="3" 
                                                                          style="font-size: 11px;"
                                                                          placeholder="Pesan yang dikirim"></textarea>
                                                            </div>
                                                            <div class="menu-grid-field menu-grid-action">
                                                                <button type="button" class="btn btn-sm btn-danger" onclick="removeMenuItem(this)">
                                                                    <i class="fas fa-trash"></i>
                                                                </button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            `;
                                            container.insertAdjacentHTML('beforeend', newItem);
                                        }
                                        
                                        function removeMenuItem(button) {
                                            if (confirm('Hapus menu item ini?')) {
                                                button.closest('.menu-item-row').remove();
                                            }
                                        }
                                        </script>
                                        
                                        <!-- Bot Device Management API Functions -->
                                        <script>
                                        /**
                                         * Fetch with timeout
                                         */
                                        function fetchWithTimeout(url, options = {}, timeout = 10000) {
                                            return Promise.race([
                                                fetch(url, options),
                                                new Promise((_, reject) =>
                                                    setTimeout(() => reject(new Error('Request timeout after ' + timeout + 'ms')), timeout)
                                                )
                                            ]);
                                        }

                                        function setButtonLoading(btn, loadingText) {
                                            if (!btn) return { originalText: '', hasButton: false };
                                            const originalText = btn.innerHTML;
                                            btn.disabled = true;
                                            btn.innerHTML = loadingText;
                                            return { originalText, hasButton: true };
                                        }

                                        function resetButtonLoading(btn, state) {
                                            if (!btn || !state || !state.hasButton) return;
                                            btn.disabled = false;
                                            btn.innerHTML = state.originalText;
                                        }

                                        function buildProxyUrl(action, addressBot, botId, phone = '') {
                                            const proxyUrl = new URL(window.location.pathname, window.location.origin);
                                            proxyUrl.searchParams.set('bot_api_action', action);
                                            proxyUrl.searchParams.set('addressbot', String(addressBot || ''));
                                            proxyUrl.searchParams.set('device_id', String(botId || ('device_' + Math.random().toString(36).slice(2, 10))));
                                            if (phone) {
                                                proxyUrl.searchParams.set('phone', String(phone || '').replace(/\D/g, ''));
                                            }
                                            return proxyUrl.toString();
                                        }

                                        function extractUpstreamPayload(proxyResult) {
                                            if (!proxyResult || typeof proxyResult !== 'object') return null;
                                            if (proxyResult.upstream_json && typeof proxyResult.upstream_json === 'object') {
                                                return proxyResult.upstream_json;
                                            }
                                            return null;
                                        }

                                        function isProxySuccess(proxyResult) {
                                            const upstream = extractUpstreamPayload(proxyResult);
                                            if (proxyResult && proxyResult.success === true) return true;
                                            if (!upstream) return false;
                                            return upstream.code === 200 || upstream.status === 200 || upstream.status === 'OK';
                                        }
                                        
                                        /**
                                         * Handle Bot Login API Call
                                         */
                                        function handleBotLogin(addressBot, botId) {
                                            if (!addressBot) {
                                                alert('❌ Address bot tidak valid');
                                                return;
                                            }
                                            
                                            const loadingBtn = event.target;
                                            const loadingState = setButtonLoading(loadingBtn, '<i class="fas fa-spinner fa-spin me-1"></i> Login...');
                                            const proxyUrl = buildProxyUrl('login', addressBot, botId);
                                            
                                            console.log('🔍 Login Request via proxy:', { proxyUrl, addressBot, botId });
                                            
                                            fetchWithTimeout(proxyUrl, {
                                                method: 'GET',
                                                headers: {
                                                    'Accept': 'application/json'
                                                }
                                            }, 10000)
                                            .then(response => {
                                                if (!response.ok) {
                                                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                                                }
                                                return response.json();
                                            })
                                            .then(proxyResult => {
                                                resetButtonLoading(loadingBtn, loadingState);
                                                const upstream = extractUpstreamPayload(proxyResult);
                                                
                                                console.log('✅ Login Proxy Response:', proxyResult);
                                                
                                                if (isProxySuccess(proxyResult)) {
                                                    alert('✅ Login berhasil!\\n\\nResponse: ' + JSON.stringify(upstream || proxyResult, null, 2));
                                                } else {
                                                    alert('⚠️ Login response:\\n' + JSON.stringify(upstream || proxyResult, null, 2));
                                                }
                                            })
                                            .catch(error => {
                                                resetButtonLoading(loadingBtn, loadingState);
                                                
                                                console.error('❌ Login Error:', error);
                                                alert('❌ Login gagal\\n\\n' + error.message + '\\n\\nCek koneksi bot di server (bukan browser), karena request sudah lewat proxy PHP.');
                                            });
                                        }
                                        
                                        /**
                                         * Handle Bot Login with Code
                                         */
                                        function handleLoginWithCode(addressBot, botId) {
                                            const phoneInput = document.getElementById('phoneInput' + botId);
                                            const phone = phoneInput.value.trim();
                                            
                                            if (!phone) {
                                                alert('⚠️ Silakan masukkan nomor telepon terlebih dahulu');
                                                phoneInput.focus();
                                                return;
                                            }
                                            
                                            // Validasi format nomor (minimal 10 digit)
                                            if (!/^\d{10,15}$/.test(phone.replace(/[^0-9]/g, ''))) {
                                                alert('⚠️ Format nomor telepon tidak valid\\n\\nGunakan format: 628912344551 (minimal 10 digit, maksimal 15 digit)');
                                                phoneInput.focus();
                                                return;
                                            }
                                            
                                            if (!addressBot) {
                                                alert('❌ Address bot tidak valid');
                                                return;
                                            }
                                            
                                            const loadingBtn = event.target;
                                            const loadingState = setButtonLoading(loadingBtn, '<i class="fas fa-spinner fa-spin me-1"></i> Pairing...');
                                            const proxyUrl = buildProxyUrl('login-with-code', addressBot, botId, phone);
                                            
                                            console.log('🔍 Login with Code Request via proxy:', { proxyUrl, phone, botId });
                                            
                                            fetchWithTimeout(proxyUrl, {
                                                method: 'GET',
                                                headers: {
                                                    'Accept': 'application/json'
                                                }
                                            }, 10000)
                                            .then(response => {
                                                if (!response.ok) {
                                                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                                                }
                                                return response.json();
                                            })
                                            .then(proxyResult => {
                                                resetButtonLoading(loadingBtn, loadingState);
                                                const upstream = extractUpstreamPayload(proxyResult);
                                                
                                                console.log('✅ Login with Code Proxy Response:', proxyResult);
                                                
                                                if (isProxySuccess(proxyResult)) {
                                                    alert('✅ Login dengan kode berhasil!\\n\\n' + JSON.stringify(upstream || proxyResult, null, 2));
                                                    phoneInput.value = '';
                                                } else {
                                                    alert('⚠️ Response:\\n' + JSON.stringify(upstream || proxyResult, null, 2));
                                                }
                                            })
                                            .catch(error => {
                                                resetButtonLoading(loadingBtn, loadingState);
                                                
                                                console.error('❌ Login with Code Error:', error);
                                                alert('❌ Login dengan kode gagal\\n\\n' + error.message);
                                            });
                                        }
                                        
                                        /**
                                         * Handle Bot Logout API Call
                                         */
                                        function handleBotLogout(addressBot, botId) {
                                            if (!confirm('⚠️ Apakah Anda yakin ingin logout? Database dan session akan dihapus.')) {
                                                return;
                                            }
                                            
                                            if (!addressBot) {
                                                alert('❌ Address bot tidak valid');
                                                return;
                                            }
                                            
                                            const loadingBtn = event.target;
                                            const loadingState = setButtonLoading(loadingBtn, '<i class="fas fa-spinner fa-spin me-1"></i> Logout...');
                                            const proxyUrl = buildProxyUrl('logout', addressBot, botId);
                                            
                                            console.log('🔍 Logout Request via proxy:', { proxyUrl, botId });
                                            
                                            fetchWithTimeout(proxyUrl, {
                                                method: 'GET',
                                                headers: {
                                                    'Accept': 'application/json'
                                                }
                                            }, 10000)
                                            .then(response => {
                                                if (!response.ok) {
                                                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                                                }
                                                return response.json();
                                            })
                                            .then(proxyResult => {
                                                resetButtonLoading(loadingBtn, loadingState);
                                                const upstream = extractUpstreamPayload(proxyResult);
                                                
                                                console.log('✅ Logout Proxy Response:', proxyResult);
                                                
                                                if (isProxySuccess(proxyResult)) {
                                                    alert('✅ Logout berhasil!\\n\\n' + JSON.stringify(upstream || proxyResult, null, 2));
                                                } else {
                                                    alert('⚠️ Response:\\n' + JSON.stringify(upstream || proxyResult, null, 2));
                                                }
                                            })
                                            .catch(error => {
                                                resetButtonLoading(loadingBtn, loadingState);
                                                
                                                console.error('❌ Logout Error:', error);
                                                alert('❌ Logout gagal\\n\\n' + error.message);
                                            });
                                        }
                                        
                                        /**
                                         * Handle Bot Reconnect API Call
                                         */
                                        function handleBotReconnect(addressBot, botId) {
                                            if (!addressBot) {
                                                alert('❌ Address bot tidak valid');
                                                return;
                                            }
                                            
                                            const loadingBtn = event.target;
                                            const loadingState = setButtonLoading(loadingBtn, '<i class="fas fa-spinner fa-spin me-1"></i> Reconnect...');
                                            const proxyUrl = buildProxyUrl('reconnect', addressBot, botId);
                                            
                                            console.log('🔍 Reconnect Request via proxy:', { proxyUrl, botId });
                                            
                                            fetchWithTimeout(proxyUrl, {
                                                method: 'GET',
                                                headers: {
                                                    'Accept': 'application/json'
                                                }
                                            }, 10000)
                                            .then(response => {
                                                if (!response.ok) {
                                                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                                                }
                                                return response.json();
                                            })
                                            .then(proxyResult => {
                                                resetButtonLoading(loadingBtn, loadingState);
                                                const upstream = extractUpstreamPayload(proxyResult);
                                                
                                                console.log('✅ Reconnect Proxy Response:', proxyResult);
                                                
                                                if (isProxySuccess(proxyResult)) {
                                                    alert('✅ Reconnect berhasil!\\n\\n' + JSON.stringify(upstream || proxyResult, null, 2));
                                                } else {
                                                    alert('⚠️ Response:\\n' + JSON.stringify(upstream || proxyResult, null, 2));
                                                }
                                            })
                                            .catch(error => {
                                                resetButtonLoading(loadingBtn, loadingState);
                                                
                                                console.error('❌ Reconnect Error:', error);
                                                alert('❌ Reconnect gagal\\n\\n' + error.message);
                                            });
                                        }
                                        </script>
                                        <?php endif; ?>

                                    <?php
                                        $ip = $data['id'];
                                        setcookie('id', $ip);
                                    }
                                    ?>
                                </tbody>
                            </table>



 </div>
            </div>
                    
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/soft-ui-dashboard.min.js?v=1.1.0"></script>
<script>
    function parseRgbColor(colorValue) {
        if (!colorValue) return null;
        var matches = colorValue.match(/\d+(?:\.\d+)?/g);
        if (!matches || matches.length < 3) return null;
        return {
            r: parseFloat(matches[0]),
            g: parseFloat(matches[1]),
            b: parseFloat(matches[2])
        };
    }

    function getRelativeLuminance(rgb) {
        if (!rgb) return 255;
        return (0.2126 * rgb.r) + (0.7152 * rgb.g) + (0.0722 * rgb.b);
    }

    function shouldUseDarkModalTheme() {
        var prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
        var bodyBg = parseRgbColor(window.getComputedStyle(document.body).backgroundColor);
        var htmlBg = parseRgbColor(window.getComputedStyle(document.documentElement).backgroundColor);
        var backdropIsDark = getRelativeLuminance(bodyBg) < 145 || getRelativeLuminance(htmlBg) < 145;
        return prefersDark || backdropIsDark;
    }

    function syncAdaptiveModalTheme(modalEl) {
        if (!modalEl || !modalEl.classList.contains('theme-adaptive-modal')) return;
        var darkMode = shouldUseDarkModalTheme();
        modalEl.classList.toggle('theme-force-dark', darkMode);
        modalEl.classList.toggle('theme-force-light', !darkMode);
    }

    function syncAllAdaptiveModals() {
        var modals = document.querySelectorAll('.theme-adaptive-modal');
        modals.forEach(function(modalEl) {
            syncAdaptiveModalTheme(modalEl);
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        syncAllAdaptiveModals();

        var deployModeSelect = document.getElementById('deploy_mode');
        var sshConfigFields = document.getElementById('ssh-config-fields');
        var sshInputs = sshConfigFields ? sshConfigFields.querySelectorAll('input') : [];
        var dataModal = document.getElementById('dataModal');

        function syncDeployModeFields() {
            if (!deployModeSelect || !sshConfigFields) {
                return;
            }

            var showRemoteFields = deployModeSelect.value === 'outside';
            sshConfigFields.style.display = showRemoteFields ? 'block' : 'none';

            sshInputs.forEach(function(input) {
                input.required = showRemoteFields;
            });
        }

        if (deployModeSelect) {
            deployModeSelect.addEventListener('change', syncDeployModeFields);
            syncDeployModeFields();
        }

        if (dataModal) {
            dataModal.addEventListener('show.bs.modal', syncDeployModeFields);
        }

        document.querySelectorAll('.theme-adaptive-modal').forEach(function(modalEl) {
            modalEl.addEventListener('show.bs.modal', function() {
                syncAdaptiveModalTheme(modalEl);
            });
        });

        if (window.matchMedia) {
            var mq = window.matchMedia('(prefers-color-scheme: dark)');
            if (mq.addEventListener) {
                mq.addEventListener('change', syncAllAdaptiveModals);
            } else if (mq.addListener) {
                mq.addListener(syncAllAdaptiveModals);
            }
        }

        // Fallback untuk tombol menu mobile jika handler bawaan tidak terpasang.
        if (typeof window.toggleSidenav !== 'function') {
            var iconNavbarSidenav = document.getElementById('iconNavbarSidenav');
            var toggleBtn = document.getElementById('toggleBtn');
            var sidenav = document.getElementById('sidenav-main');
            var bodyEl = document.body;

            var fallbackToggle = function(event) {
                if (event) {
                    event.preventDefault();
                }

                if (!bodyEl) {
                    return;
                }

                var className = 'g-sidenav-pinned';
                if (bodyEl.classList.contains(className)) {
                    bodyEl.classList.remove(className);
                    bodyEl.classList.add('g-sidenav-hidden');
                    if (sidenav) {
                        sidenav.classList.remove('bg-white');
                    }
                } else {
                    bodyEl.classList.add(className);
                    bodyEl.classList.remove('g-sidenav-hidden');
                    if (sidenav) {
                        sidenav.classList.add('bg-white');
                        sidenav.classList.remove('bg-transparent');
                    }
                }
            };

            if (iconNavbarSidenav) {
                iconNavbarSidenav.addEventListener('click', fallbackToggle);
            }

            if (toggleBtn) {
                toggleBtn.addEventListener('click', fallbackToggle);
            }
        }
    });
</script>

<div class="row" id="waUnofficialSection">
        <div class="col-12">
            <div class="card mb-4">
                <div class="card-header bg-info text-white d-flex justify-content-between align-items-center flex-wrap gap-2" style="padding: 12px 15px; font-weight: 600;">
                    <h6 class="mb-0" style="font-size: 1em; font-weight: 600;"><i class="fas fa-plug me-2"></i>BOT WhatsApp API External ( UNOFFICIAL WA API  )</h6>
                    <?php if (!$waUnofficialSetupError): ?>
                    <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#addUnofficialModal" style="font-weight: 500;">
                        <i class="fas fa-plus me-1"></i> Tambah Bot Lain
                    </button>
                    <?php endif; ?>
                </div>
                <div class="card-body">

                    <?php if ($waUnofficialFlash): ?>
                        <div class="alert alert-info"><?= htmlspecialchars($waUnofficialFlash) ?></div>
                    <?php endif; ?>

                    <?php if ($waUnofficialSetupError): ?>
                        <div class="alert alert-danger">
                            <strong><i class="fas fa-triangle-exclamation me-1"></i> Fitur Bot WhatsApp Lain belum bisa dimuat.</strong><br>
                            <?= htmlspecialchars($waUnofficialSetupError) ?><br>
                            <small>Cek error log server, atau pastikan database user punya izin CREATE/ALTER TABLE. Bagian bot WhatsApp lain di halaman ini tetap berjalan normal.</small>
                        </div>
                    <?php else: ?>

                    

                    <div class="table-responsive">
                        <table class="table table-bordered align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Nama Integrasi</th>
                                    <th>Penyedia</th>
                                    <th>Status</th>
                                    <th>Bot Terhubung</th>
                                    <th>Test Terakhir</th>
                                    <th style="min-width: 260px;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($waUnofficialIntegrasiList)): ?>
                                    <tr><td colspan="6" class="text-center text-muted">Belum ada bot lain. Klik "Tambah Bot Lain" untuk mulai.</td></tr>
                                <?php else: foreach ($waUnofficialIntegrasiList as $rowU): ?>
                                    <?php
                                        $unofficialProviderMeta = $waUnofficialProviderMeta;
                                        $providerLabelU = $waUnofficialProviderMeta[$rowU['provider']]['label'] ?? $rowU['provider'];
                                        $boundBotU = null;
                                        if (!empty($rowU['target_botwa_id'])) {
                                            foreach ($waUnofficialBotList as $bu) {
                                                if ((int)$bu['id'] === (int)$rowU['target_botwa_id']) { $boundBotU = $bu; break; }
                                            }
                                        }
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($rowU['nama_integrasi']) ?></td>
                                        <td><?= htmlspecialchars($providerLabelU) ?></td>
                                        <td>
                                            <?php if ((int)$rowU['status'] === 1): ?>
                                                <span class="badge bg-success">Aktif</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Nonaktif</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $boundBotU ? htmlspecialchars($boundBotU['namebot']) : '<span class="text-muted">-</span>' ?></td>
                                        <td style="font-size: 0.85em;">
                                            <?php if (!empty($rowU['last_test_at'])): ?>
                                                <?= $rowU['last_test_status'] === 'sukses' ? '<span class="text-success">✅ Sukses</span>' : '<span class="text-danger">❌ Gagal</span>' ?><br>
                                                <span class="text-muted"><?= htmlspecialchars($rowU['last_test_at']) ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">Belum pernah</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="d-flex flex-wrap gap-1">
                                                <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#editModalU<?= $rowU['id'] ?>" onclick="wuFixEditModalFields(<?= (int)$rowU['id'] ?>)">
                                                    <i class="fas fa-edit"></i> Edit
                                                </button>
                                                <button type="button" class="btn btn-outline-success btn-sm" data-bs-toggle="modal" data-bs-target="#testModalU<?= $rowU['id'] ?>">
                                                    <i class="fas fa-paper-plane"></i> Test
                                                </button>
                                                <?php if ((int)$rowU['status'] === 1): ?>
                                                    <form method="post" onsubmit="return confirm('Nonaktifkan integrasi ini? Bot akan dikembalikan ke pengaturan sebelumnya.');" style="display:inline;">
                                                        <input type="hidden" name="id" value="<?= $rowU['id'] ?>">
                                                        <button type="submit" name="deactivate_integrasi_unofficial" value="1" class="btn btn-outline-warning btn-sm">
                                                            <i class="fas fa-power-off"></i> Nonaktifkan
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#activateModalU<?= $rowU['id'] ?>">
                                                        <i class="fas fa-power-off"></i> Aktifkan
                                                    </button>
                                                <?php endif; ?>
                                                <form method="post" onsubmit="return confirm('Hapus integrasi ini secara permanen?');" style="display:inline;">
                                                    <input type="hidden" name="id" value="<?= $rowU['id'] ?>">
                                                    <button type="submit" name="delete_integrasi_unofficial" value="1" class="btn btn-outline-danger btn-sm">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>

                                    <!-- MODAL: EDIT -->
                                    <div class="modal fade" id="editModalU<?= $rowU['id'] ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog modal-lg">
                                            <div class="modal-content">
                                                <form method="post">
                                                    <div class="modal-header bg-primary text-white">
                                                        <h5 class="modal-title">Edit Bot Lain: <?= htmlspecialchars($rowU['nama_integrasi']) ?></h5>
                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <?php include 'wa_unofficial_form_fields.php'; ?>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                                        <button type="submit" name="save_integrasi_unofficial" value="1" class="btn btn-primary">💾 Simpan Perubahan</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- MODAL: TEST SEND -->
                                    <div class="modal fade" id="testModalU<?= $rowU['id'] ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <form method="post">
                                                    <input type="hidden" name="id" value="<?= $rowU['id'] ?>">
                                                    <div class="modal-header bg-success text-white">
                                                        <h5 class="modal-title">Test Kirim Pesan</h5>
                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <p class="text-muted">Mengirim langsung lewat <strong><?= htmlspecialchars($providerLabelU) ?></strong> (tidak perlu integrasi ini aktif dulu).</p>
                                                        <label class="form-label fw-bold">Nomor WhatsApp Tujuan</label>
                                                        <input type="text" class="form-control" name="test_phone" placeholder="0812xxxxxxx atau 62812xxxxxxx" required>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                                        <button type="submit" name="test_send_integrasi_unofficial" value="1" class="btn btn-success">Kirim Test</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- MODAL: ACTIVATE -->
                                    <div class="modal fade" id="activateModalU<?= $rowU['id'] ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <form method="post">
                                                    <input type="hidden" name="id" value="<?= $rowU['id'] ?>">
                                                    <div class="modal-header bg-success text-white">
                                                        <h5 class="modal-title">Aktifkan: <?= htmlspecialchars($rowU['nama_integrasi']) ?></h5>
                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <label class="form-label fw-bold">Pasang ke Bot</label>
                                                        <select class="form-control" name="target_botwa_id" onchange="document.getElementById('newBotNameWrapU<?= $rowU['id'] ?>').style.display = this.value==='0' ? 'block':'none';">
                                                            <option value="0">+ Buat bot baru khusus integrasi ini</option>
                                                            <?php foreach ($waUnofficialBotList as $bu): ?>
                                                                <option value="<?= $bu['id'] ?>"><?= htmlspecialchars($bu['namebot']) ?> <?= $bu['tipe_bot'] !== 'gowa' ? '(sedang pakai integrasi lain)' : '(unofficial gowa)' ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <small class="text-muted d-block mt-1">Memilih bot yang sudah ada akan mengalihkan bot itu ke layanan ini (pengaturan lama dibackup otomatis, bisa dikembalikan lewat "Nonaktifkan"). Semua fitur billing yang memakai nama bot itu otomatis ikut pindah.</small>

                                                        <div id="newBotNameWrapU<?= $rowU['id'] ?>" class="mt-3">
                                                            <?php if (($rowU['provider'] ?? '') === 'gowa_external'): ?>
                                                                <small class="text-muted d-block">Nama bot baru otomatis memakai Nama Session: <strong><?= htmlspecialchars($rowU['instance_id'] ?? '') ?></strong> (harus sama persis dengan session di server gowa eksternal itu).</small>
                                                            <?php else: ?>
                                                            <label class="form-label fw-bold">Nama Bot Baru (opsional)</label>
                                                            <input type="text" class="form-control" name="new_bot_name" placeholder="misal: alt_utama">
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                                        <button type="submit" name="activate_integrasi_unofficial" value="1" class="btn btn-success">Aktifkan Sekarang</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php endif /* $waUnofficialSetupError */ ?>

                </div>
            </div>
        </div>
    </div>

<!-- MODAL: TAMBAH BOT LAIN -->
<div class="modal fade" id="addUnofficialModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title">Tambah Bot WhatsApp Lain</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php $rowU = null; $unofficialProviderMeta = $waUnofficialProviderMeta; include 'wa_unofficial_form_fields.php'; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" name="save_integrasi_unofficial" value="1" class="btn btn-info text-white">💾 Simpan Bot Lain</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    var waUnofficialProviderGroup = <?= json_encode(array_map(function ($p) { return $p['group']; }, $waUnofficialProviderMeta)) ?>;
    var waUnofficialProviderBaseUrl = <?= json_encode(array_map(function ($p) { return $p['base_url']; }, $waUnofficialProviderMeta)) ?>;

    function waUnofficialApplyProviderVisibility(form) {
        var select = form.querySelector('[name="provider"]');
        if (!select) return;
        var group = waUnofficialProviderGroup[select.value] || 'customu';

        form.querySelectorAll('[data-groupu]').forEach(function (el) {
            var groups = el.getAttribute('data-groupu').split(',');
            var visible = groups.indexOf(group) !== -1;
            el.style.display = visible ? '' : 'none';
            el.querySelectorAll('input, select, textarea').forEach(function (field) {
                field.disabled = !visible;
            });
        });

        var baseUrlInput = form.querySelector('[name="base_url"]');
        if (baseUrlInput && !baseUrlInput.value && waUnofficialProviderBaseUrl[select.value]) {
            baseUrlInput.value = waUnofficialProviderBaseUrl[select.value];
        }
    }

    document.addEventListener('change', function (e) {
        if (e.target && e.target.name === 'provider' && e.target.closest('#waUnofficialSection, #addUnofficialModal')) {
            waUnofficialApplyProviderVisibility(e.target.closest('form'));
        }
    });

    document.querySelectorAll('#waUnofficialSection form, #addUnofficialModal form').forEach(function (form) {
        if (form.querySelector('[name="provider"]')) {
            waUnofficialApplyProviderVisibility(form);
        }
    });

    // Modal edit/tambah di-generate per baris & baru benar-benar "hidup" saat
    // dibuka -- hitung ulang visibility field setiap modal ini ditampilkan,
    // supaya tidak mengandalkan hitungan awal saat load halaman saja.
    document.addEventListener('show.bs.modal', function (e) {
        if (!e.target || !e.target.closest('#waUnofficialSection, #addUnofficialModal')) {
            return;
        }
        var form = e.target.querySelector('form');
        if (form && form.querySelector('[name="provider"]')) {
            waUnofficialApplyProviderVisibility(form);
        }
    });

    // Dipanggil langsung dari onclick tombol Edit (belt-and-suspenders, tidak
    // bergantung sepenuhnya ke event show.bs.modal Bootstrap yang kadang
    // tidak konsisten ke-trigger untuk modal yang di-generate per baris).
    window.wuFixEditModalFields = function (id) {
        var modalEl = document.getElementById('editModalU' + id);
        if (!modalEl) return;
        var form = modalEl.querySelector('form');
        if (form && form.querySelector('[name="provider"]')) {
            waUnofficialApplyProviderVisibility(form);
        }
    };
})();
</script>

<!-- Dark Mode CSS for WABOT -->
<style>
body.app-theme-dark .wabot-toolbar {
    background: linear-gradient(135deg, #2563eb 0%, #3b82f6 100%) !important;
    color: #ffffff !important;
}

body.app-theme-dark .card-header.bg-light {
    background: linear-gradient(135deg, #1a233a 0%, #0f172a 100%) !important;
    border-bottom: 2px solid rgba(59, 130, 246, 0.3) !important;
    color: #e2e8f0 !important;
}

body.app-theme-dark .card-header.bg-light h6,
body.app-theme-dark .card-header.bg-light .form-label {
    color: #f1f5f9 !important;
    font-weight: 600 !important;
}

body.app-theme-dark .btn-light {
    background-color: #1a233a !important;
    border-color: rgba(59, 130, 246, 0.3) !important;
    color: #e2e8f0 !important;
    font-weight: 500 !important;
}

body.app-theme-dark .btn-light:hover {
    background-color: rgba(59, 130, 246, 0.15) !important;
    border-color: #3b82f6 !important;
    color: #f1f5f9 !important;
}

body.app-theme-dark .btn-light:active {
    background-color: rgba(59, 130, 246, 0.25) !important;
}

body.app-theme-dark .wabot-toolbar-actions .btn {
    font-weight: 500;
}

/* Kotak referensi provider di modal Tambah WA Resmi / Bot Lain */
.wa-ref-box,
.wa-ref-box h1, .wa-ref-box h2, .wa-ref-box h3, .wa-ref-box h4, .wa-ref-box h5, .wa-ref-box h6,
.wa-ref-box p, .wa-ref-box span, .wa-ref-box small, .wa-ref-box strong, .wa-ref-box label,
.wa-ref-box li, .wa-ref-box div, .wa-ref-box td, .wa-ref-box th {
    color: #212529 !important;
}
.wa-ref-box {
    background-color: #f1f3f5 !important;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 14px 16px;
}
.wa-ref-box thead {
    background-color: rgba(0, 0, 0, 0.05);
}
.wa-ref-box td,
.wa-ref-box th {
    border-color: rgba(0, 0, 0, 0.1);
}
body.app-theme-dark .wa-ref-box,
body.app-theme-dark .wa-ref-box h1, body.app-theme-dark .wa-ref-box h2, body.app-theme-dark .wa-ref-box h3,
body.app-theme-dark .wa-ref-box h4, body.app-theme-dark .wa-ref-box h5, body.app-theme-dark .wa-ref-box h6,
body.app-theme-dark .wa-ref-box p, body.app-theme-dark .wa-ref-box span, body.app-theme-dark .wa-ref-box small,
body.app-theme-dark .wa-ref-box strong, body.app-theme-dark .wa-ref-box label, body.app-theme-dark .wa-ref-box li,
body.app-theme-dark .wa-ref-box div, body.app-theme-dark .wa-ref-box td, body.app-theme-dark .wa-ref-box th {
    color: #e2e8f0 !important;
}
body.app-theme-dark .wa-ref-box {
    background-color: #1a233a !important;
    border-color: rgba(59, 130, 246, 0.25) !important;
}
body.app-theme-dark .wa-ref-box thead {
    background-color: rgba(59, 130, 246, 0.12) !important;
}
body.app-theme-dark .wa-ref-box td,
body.app-theme-dark .wa-ref-box th {
    border-color: rgba(59, 130, 246, 0.15) !important;
}
</style>

<div class="row" id="waResmiSection">
        <div class="col-12">
            <div class="card mb-4">
                <div class="card-header bg-success text-white d-flex justify-content-between align-items-center flex-wrap gap-2" style="padding: 12px 15px; font-weight: 600;">
                    <h6 class="mb-0" style="font-size: 1em; font-weight: 600;"><i class="fas fa-shield-alt me-2"></i>BOT WhatsApp API Resmi ( OFFICIAL API WA )</h6>
                    <?php if (!$waResmiSetupError): ?>
                    <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#addIntegrasiModal" style="font-weight: 500;">
                        <i class="fas fa-plus me-1"></i> Tambah Integrasi Resmi
                    </button>
                    <?php endif; ?>
                </div>
                <div class="card-body">

                    <?php if ($waResmiFlash): ?>
                        <div class="alert alert-info"><?= htmlspecialchars($waResmiFlash) ?></div>
                    <?php endif; ?>

                    <?php if ($waResmiSetupError): ?>
                        <div class="alert alert-danger">
                            <strong><i class="fas fa-triangle-exclamation me-1"></i> Fitur Integrasi WA Resmi belum bisa dimuat.</strong><br>
                            <?= htmlspecialchars($waResmiSetupError) ?><br>
                            <small>Cek error log server, atau pastikan database user punya izin CREATE/ALTER TABLE. Bagian bot WhatsApp lain di halaman ini tetap berjalan normal.</small>
                        </div>
                    <?php else: ?>

                    

                    <div class="table-responsive">
                        <table class="table table-bordered align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Nama Integrasi</th>
                                    <th>Penyedia</th>
                                    <th>Sender</th>
                                    <th>Status</th>
                                    <th>Bot Terhubung</th>
                                    <th>Test Terakhir</th>
                                    <th style="min-width: 260px;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($waResmiIntegrasiList)): ?>
                                    <tr><td colspan="7" class="text-center text-muted">Belum ada integrasi WA resmi. Klik "Tambah Integrasi Resmi" untuk mulai.</td></tr>
                                <?php else: foreach ($waResmiIntegrasiList as $row): ?>
                                    <?php
                                        $providerMeta = $waResmiProviderMeta;
                                        $providerLabel = $waResmiProviderMeta[$row['provider']]['label'] ?? $row['provider'];
                                        $boundBot = null;
                                        if (!empty($row['target_botwa_id'])) {
                                            foreach ($waResmiBotList as $b) {
                                                if ((int)$b['id'] === (int)$row['target_botwa_id']) { $boundBot = $b; break; }
                                            }
                                        }
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['nama_integrasi']) ?></td>
                                        <td><?= htmlspecialchars($providerLabel) ?></td>
                                        <td><?= htmlspecialchars($row['sender_number'] ?: '-') ?></td>
                                        <td>
                                            <?php if ((int)$row['status'] === 1): ?>
                                                <span class="badge bg-success">Aktif</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Nonaktif</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $boundBot ? htmlspecialchars($boundBot['namebot']) : '<span class="text-muted">-</span>' ?></td>
                                        <td style="font-size: 0.85em;">
                                            <?php if (!empty($row['last_test_at'])): ?>
                                                <?= $row['last_test_status'] === 'sukses' ? '<span class="text-success">✅ Sukses</span>' : '<span class="text-danger">❌ Gagal</span>' ?><br>
                                                <span class="text-muted"><?= htmlspecialchars($row['last_test_at']) ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">Belum pernah</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="d-flex flex-wrap gap-1">
                                                <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#editModalResmi<?= $row['id'] ?>" onclick="wrFixEditModalFields(<?= (int)$row['id'] ?>)">
                                                    <i class="fas fa-edit"></i> Edit
                                                </button>
                                                <button type="button" class="btn btn-outline-success btn-sm" data-bs-toggle="modal" data-bs-target="#testModalResmi<?= $row['id'] ?>">
                                                    <i class="fas fa-paper-plane"></i> Test
                                                </button>
                                                <?php if ((int)$row['status'] === 1): ?>
                                                    <form method="post" onsubmit="return confirm('Nonaktifkan integrasi ini? Bot akan dikembalikan ke pengaturan sebelumnya.');" style="display:inline;">
                                                        <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                                        <button type="submit" name="deactivate_integrasi" value="1" class="btn btn-outline-warning btn-sm">
                                                            <i class="fas fa-power-off"></i> Nonaktifkan
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#activateModalResmi<?= $row['id'] ?>">
                                                        <i class="fas fa-power-off"></i> Aktifkan
                                                    </button>
                                                <?php endif; ?>
                                                <form method="post" onsubmit="return confirm('Hapus integrasi ini secara permanen?');" style="display:inline;">
                                                    <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                                    <button type="submit" name="delete_integrasi" value="1" class="btn btn-outline-danger btn-sm">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>

                                    <!-- MODAL: EDIT -->
                                    <div class="modal fade" id="editModalResmi<?= $row['id'] ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog modal-lg">
                                            <div class="modal-content">
                                                <form method="post">
                                                    <div class="modal-header bg-primary text-white">
                                                        <h5 class="modal-title">Edit Integrasi: <?= htmlspecialchars($row['nama_integrasi']) ?></h5>
                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <?php include 'wa_resmi_form_fields.php'; ?>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                                        <button type="submit" name="save_integrasi" value="1" class="btn btn-primary">💾 Simpan Perubahan</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- MODAL: TEST SEND -->
                                    <div class="modal fade" id="testModalResmi<?= $row['id'] ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <form method="post">
                                                    <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                                    <div class="modal-header bg-success text-white">
                                                        <h5 class="modal-title">Test Kirim Pesan</h5>
                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <p class="text-muted">Mengirim langsung lewat provider <strong><?= htmlspecialchars($providerLabel) ?></strong> (tidak perlu integrasi ini aktif dulu).</p>
                                                        <label class="form-label fw-bold">Nomor WhatsApp Tujuan</label>
                                                        <input type="text" class="form-control" name="test_phone" placeholder="0812xxxxxxx atau 62812xxxxxxx" required>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                                        <button type="submit" name="test_send_integrasi" value="1" class="btn btn-success">Kirim Test</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- MODAL: ACTIVATE -->
                                    <div class="modal fade" id="activateModalResmi<?= $row['id'] ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <form method="post">
                                                    <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                                    <div class="modal-header bg-success text-white">
                                                        <h5 class="modal-title">Aktifkan: <?= htmlspecialchars($row['nama_integrasi']) ?></h5>
                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <label class="form-label fw-bold">Pasang ke Bot</label>
                                                        <select class="form-control" name="target_botwa_id" onchange="document.getElementById('newBotNameWrapResmi<?= $row['id'] ?>').style.display = this.value==='0' ? 'block':'none';">
                                                            <option value="0">+ Buat bot baru khusus integrasi ini</option>
                                                            <?php foreach ($waResmiBotList as $b): ?>
                                                                <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['namebot']) ?> <?= $b['tipe_bot'] === 'resmi_api' ? '(sedang pakai WA resmi lain)' : '(unofficial gowa)' ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <small class="text-muted d-block mt-1">Memilih bot yang sudah ada akan mengalihkan bot itu ke WA resmi (pengaturan lama dibackup otomatis, bisa dikembalikan lewat "Nonaktifkan"). Semua fitur billing yang memakai nama bot itu otomatis ikut pindah.</small>

                                                        <div id="newBotNameWrapResmi<?= $row['id'] ?>" class="mt-3">
                                                            <label class="form-label fw-bold">Nama Bot Baru (opsional)</label>
                                                            <input type="text" class="form-control" name="new_bot_name" placeholder="misal: resmi_utama">
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                                        <button type="submit" name="activate_integrasi" value="1" class="btn btn-success">Aktifkan Sekarang</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php endif /* $waResmiSetupError */ ?>

                </div>
            </div>
        </div>
    </div>

<!-- MODAL: TAMBAH INTEGRASI RESMI -->
<div class="modal fade" id="addIntegrasiModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">Tambah Integrasi WhatsApp API Resmi</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php $row = null; $providerMeta = $waResmiProviderMeta; include 'wa_resmi_form_fields.php'; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" name="save_integrasi" value="1" class="btn btn-success">💾 Simpan Integrasi</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    var waResmiProviderGroup = <?= json_encode(array_map(function ($p) { return $p['group']; }, $waResmiProviderMeta)) ?>;
    var waResmiProviderBaseUrl = <?= json_encode(array_map(function ($p) { return $p['base_url']; }, $waResmiProviderMeta)) ?>;

    function waResmiApplyProviderVisibility(form) {
        var select = form.querySelector('[name="provider"]');
        if (!select) return;
        var group = waResmiProviderGroup[select.value] || 'cloud';

        form.querySelectorAll('[data-group]').forEach(function (el) {
            var groups = el.getAttribute('data-group').split(',');
            var visible = groups.indexOf(group) !== -1;
            el.style.display = visible ? '' : 'none';
            el.querySelectorAll('input, select, textarea').forEach(function (field) {
                field.disabled = !visible;
            });
        });

        var baseUrlInput = form.querySelector('[name="base_url"]');
        if (baseUrlInput && !baseUrlInput.value && waResmiProviderBaseUrl[select.value]) {
            baseUrlInput.value = waResmiProviderBaseUrl[select.value];
        }
    }

    document.addEventListener('change', function (e) {
        if (e.target && e.target.name === 'provider' && e.target.closest('#waResmiSection, #addIntegrasiModal')) {
            waResmiApplyProviderVisibility(e.target.closest('form'));
        }
    });

    document.querySelectorAll('#waResmiSection form, #addIntegrasiModal form').forEach(function (form) {
        if (form.querySelector('[name="provider"]')) {
            waResmiApplyProviderVisibility(form);
        }
    });

    document.addEventListener('show.bs.modal', function (e) {
        if (!e.target || !e.target.closest('#waResmiSection, #addIntegrasiModal')) {
            return;
        }
        var form = e.target.querySelector('form');
        if (form && form.querySelector('[name="provider"]')) {
            waResmiApplyProviderVisibility(form);
        }
    });

    // Dipanggil langsung dari onclick tombol Edit (belt-and-suspenders, tidak
    // bergantung sepenuhnya ke event show.bs.modal Bootstrap).
    window.wrFixEditModalFields = function (id) {
        var modalEl = document.getElementById('editModalResmi' + id);
        if (!modalEl) return;
        var form = modalEl.querySelector('form');
        if (form && form.querySelector('[name="provider"]')) {
            waResmiApplyProviderVisibility(form);
        }
    };
})();
</script>

<?php
function wabotTailLog($path, $maxBytes = 50000, $maxLines = 200)
{
    if (!is_file($path)) {
        return null;
    }
    $fh = fopen($path, 'r');
    if (!$fh) {
        return null;
    }
    $size = filesize($path);
    if ($size > $maxBytes) {
        fseek($fh, -$maxBytes, SEEK_END);
    }
    $content = stream_get_contents($fh);
    fclose($fh);

    // Redak password supaya tidak tampil polos di layar.
    $content = preg_replace('/UserPWD:\s*([^:\r\n]+):[^\r\n]*/', 'UserPWD: $1:***', $content);

    $lines = explode("\n", $content);
    if (count($lines) > $maxLines) {
        $lines = array_slice($lines, -$maxLines);
    }
    return trim(implode("\n", $lines));
}

$wabotTesterLog = wabotTailLog(__DIR__ . '/tester_debug.log');
$wabotGatewayLog = wabotTailLog(__DIR__ . '/../webhook/wa_unofficial_gateway_log.txt');
?>

<div class="container-fluid mt-4 mb-4">
    <div class="card">
        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center" style="cursor:pointer;" data-bs-toggle="collapse" data-bs-target="#debugLogPanel" aria-expanded="false" aria-controls="debugLogPanel">
            <span><i class="fas fa-bug me-2"></i>Log Debug (Tester Bot &amp; Gateway Bot WhatsApp Lain)</span>
            <span><i class="fas fa-chevron-down"></i></span>
        </div>
        <div class="collapse" id="debugLogPanel">
            <div class="card-body bg-body-tertiary">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="mb-0">tester_debug.log <small class="text-muted">(hasil klik "Tester" di bot internal)</small></h6>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="location.reload()"><i class="fas fa-rotate me-1"></i>Refresh</button>
                </div>
                <pre class="wabot-debug-log-box"><?= $wabotTesterLog !== null && $wabotTesterLog !== '' ? htmlspecialchars($wabotTesterLog) : '(belum ada log, atau file tester_debug.log tidak ditemukan)' ?></pre>

                <h6 class="mt-3 mb-2">wa_unofficial_gateway_log.txt <small class="text-muted">(pesan yang lewat proxy Bot WhatsApp Lain: Fonnte/Wablas/dst)</small></h6>
                <pre class="wabot-debug-log-box"><?= $wabotGatewayLog !== null && $wabotGatewayLog !== '' ? htmlspecialchars($wabotGatewayLog) : '(belum ada log, atau file wa_unofficial_gateway_log.txt tidak ditemukan)' ?></pre>
            </div>
        </div>
    </div>
</div>

<style>
.wabot-debug-log-box {
    max-height: 400px;
    overflow: auto;
    background: #1a1a1a;
    color: #7ee787;
    padding: 12px;
    border-radius: 6px;
    font-size: 12px;
    white-space: pre-wrap;
    word-break: break-all;
}
</style>

<script>
document.getElementById('debugLogPanel')?.addEventListener('shown.bs.collapse', function () {
    this.querySelectorAll('.wabot-debug-log-box').forEach(function (box) {
        box.scrollTop = box.scrollHeight;
    });
});
</script>

</body>
</html>
