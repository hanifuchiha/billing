<style>
                    /* Perbesar tinggi minimal textarea agar tidak sempit */
                    textarea.form-control:not(.full-height) {
                        height: auto !important;
                       
                        overflow: hidden !important;
                        resize: none !important;
                        transition: none;
                    }
                </style>



<?php
require 'header.php';
if ($AKSES == 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('Notification_settings', $akses_menu, true)) {
        echo '<div class="container-fluid py-4"><div class="alert alert-danger">Anda tidak memiliki akses ke menu Notification Settings.</div></div>';
        require 'footer.php';
        exit;
    }
}

require_once __DIR__ . '/notifbot/bot_selector_helper.php';
require_once __DIR__ . '/notifbot/reminder_settings_helper.php';

// --- HELPER: konversi checkbox bot menjadi string tersimpan, dan sebaliknya ---
if (!function_exists('encodeBotSelection')) {
    /**
     * Ubah hasil checkbox (array nama bot / 'RANDOM') menjadi string yang disimpan.
     * - kosong / RANDOM ikut dicentang  -> 'RANDOM' (random dari SEMUA bot)
     * - hanya 1 bot spesifik dicentang  -> nama bot itu saja (fix, tidak random)
     * - >1 bot spesifik dicentang       -> "BotA,BotB,..." (random dari yang dicentang saja)
     */
    function encodeBotSelection(array $selected, array $allBots)
    {
        $selected = array_values(array_unique(array_filter(array_map('trim', $selected), function ($v) {
            return $v !== '';
        })));

        if (empty($selected) || in_array('RANDOM', $selected, true)) {
            return 'RANDOM';
        }

        if (count($selected) === 1) {
            return $selected[0];
        }

        return implode(',', $selected);
    }
}

if (!function_exists('pickBotFromSelection')) {
    /**
     * Dipakai di script pengirim notif untuk menentukan bot yang benar-benar dipakai
     * saat mengirim pesan, berdasarkan nilai tersimpan dari encodeBotSelection().
     */
    function pickBotFromSelection($storedValue, array $allBots)
    {
        $allBots = array_values(array_diff($allBots, ['RANDOM']));

        if ($storedValue === '' || $storedValue === null || $storedValue === 'RANDOM') {
            return !empty($allBots) ? $allBots[array_rand($allBots)] : null;
        }

        $list = array_filter(array_map('trim', explode(',', $storedValue)), function ($v) {
            return $v !== '';
        });
        $list = array_values(array_intersect($list, $allBots));

        if (empty($list)) {
            return !empty($allBots) ? $allBots[array_rand($allBots)] : null;
        }
        if (count($list) === 1) {
            return $list[0];
        }
        return $list[array_rand($list)];
    }
}

// --- AUTO CREATE TABLE notif_khusus & kolom jika belum ada ---
$notif_khusus_check = $conn->query("SHOW TABLES LIKE 'notif_khusus'");
if ($notif_khusus_check->num_rows == 0) {
    $conn->query("CREATE TABLE notif_khusus (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pemilik VARCHAR(100) NOT NULL,
        pesan_ketentuan TEXT,
        pesan_disable TEXT,
        pesan_aktif_manual TEXT,
        pesan_remainder_manual TEXT,
        pesan_dismantle_manual TEXT,
        pesan_gangguan TEXT,
        pesan_gangguan_selesai TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} else {
    // Tambah kolom jika belum ada
    $columns = [];
    $res = $conn->query("SHOW COLUMNS FROM notif_khusus");
    while ($row = $res->fetch_assoc()) {
        $columns[] = $row['Field'];
    }
    $add_cols = [];
    if (!in_array('pesan_registrasi', $columns)) {
        $add_cols[] = "ADD COLUMN pesan_registrasi TEXT";
    }
    if (!in_array('pesan_expired', $columns)) {
        $add_cols[] = "ADD COLUMN pesan_expired TEXT";
    }
    if (!in_array('pesan_reminder', $columns)) {
        $add_cols[] = "ADD COLUMN pesan_reminder TEXT";
    }
    if (!in_array('pesan_remainder_manual', $columns)) {
        $add_cols[] = "ADD COLUMN pesan_remainder_manual TEXT";
    }
    if (!in_array('pesan_dismantle_manual', $columns)) {
        $add_cols[] = "ADD COLUMN pesan_dismantle_manual TEXT";
    }
    if (!in_array('pesan_gangguan', $columns)) {
        $add_cols[] = "ADD COLUMN pesan_gangguan TEXT";
    }
    if (!in_array('pesan_gangguan_selesai', $columns)) {
        $add_cols[] = "ADD COLUMN pesan_gangguan_selesai TEXT";
    }
    if (!in_array('pesan_pembayaran_berhasil', $columns)) {
        $add_cols[] = "ADD COLUMN pesan_pembayaran_berhasil TEXT";
    }
    if (count($add_cols) > 0) {
        $conn->query("ALTER TABLE notif_khusus " . implode(', ', $add_cols));
    }
}
// --- AUTO ADD COLUMN penerima_server to botwa if not exists ---
$botwa_check = $conn->query("SHOW COLUMNS FROM botwa LIKE 'penerima_server'");
if ($botwa_check->num_rows == 0) {
    $conn->query("ALTER TABLE botwa ADD COLUMN penerima_server VARCHAR(255) DEFAULT NULL");
}
// --- AUTO ADD COLUMN penerima_livechat to botwa if not exists ---
$botwa_check_livechat = $conn->query("SHOW COLUMNS FROM botwa LIKE 'penerima_livechat'");
if ($botwa_check_livechat->num_rows == 0) {
    $conn->query("ALTER TABLE botwa ADD COLUMN penerima_livechat VARCHAR(255) DEFAULT NULL");
}
// --- AUTO ADD COLUMN penerima_system_notif to botwa if not exists ---
$botwa_check_system_notif = $conn->query("SHOW COLUMNS FROM botwa LIKE 'penerima_system_notif'");
if ($botwa_check_system_notif->num_rows == 0) {
    $conn->query("ALTER TABLE botwa ADD COLUMN penerima_system_notif VARCHAR(255) DEFAULT NULL");
}
// --- AUTO ADD COLUMN penerima_manual_active to botwa if not exists ---
$botwa_check_manual_active = $conn->query("SHOW COLUMNS FROM botwa LIKE 'penerima_manual_active'");
if ($botwa_check_manual_active->num_rows == 0) {
    $conn->query("ALTER TABLE botwa ADD COLUMN penerima_manual_active VARCHAR(255) DEFAULT NULL");
}
// --- AUTO ADD COLUMN penerima_odp_los to botwa if not exists ---
$botwa_check_odp_los = $conn->query("SHOW COLUMNS FROM botwa LIKE 'penerima_odp_los'");
if ($botwa_check_odp_los->num_rows == 0) {
    $conn->query("ALTER TABLE botwa ADD COLUMN penerima_odp_los VARCHAR(255) DEFAULT NULL");
}
// --- AUTO ADD COLUMN penerima_provisioning to botwa if not exists ---
$botwa_check_provisioning = $conn->query("SHOW COLUMNS FROM botwa LIKE 'penerima_provisioning'");
if ($botwa_check_provisioning->num_rows == 0) {
    $conn->query("ALTER TABLE botwa ADD COLUMN penerima_provisioning VARCHAR(255) DEFAULT NULL");
}
// // Tampilkan error PHP di browser
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
if (isset($_GET['msg'])) {
    echo '<div class="container">' . htmlspecialchars($_GET['msg']) . '</div>';
}

$owner_key_for_bot_cfg = isset($ceknama) && $ceknama !== '' ? $ceknama : (isset($username) ? $username : 'default');
$owner_key_for_dynamic_greeting = preg_replace('/[^A-Za-z0-9_.-]/', '_', (string)$owner_key_for_bot_cfg);
$bot_receiver_config_path = __DIR__ . "/notifbot/data/bot_receiver_config-$owner_key_for_bot_cfg.json";
$dynamic_greeting_config_path = __DIR__ . "/notifbot/data/dynamic_greeting-$owner_key_for_dynamic_greeting.json";
$dynamic_greeting_default_list = function_exists('getDefaultGreetingList') ? getDefaultGreetingList() : [
    'Assalamualaikum',
    'Halo',
    'Hai',
    'Salam pagi',
    'Salam sore',
    'Salam malam'
];
$dynamic_greeting_enabled = '1';
$dynamic_greeting_list = $dynamic_greeting_default_list;
$dynamic_greeting_list_text = implode("\n", $dynamic_greeting_list);

if (file_exists($dynamic_greeting_config_path)) {
    $tmp_dynamic_greeting_config = json_decode(file_get_contents($dynamic_greeting_config_path), true);
    if (is_array($tmp_dynamic_greeting_config)) {
        $dynamic_greeting_enabled = !empty($tmp_dynamic_greeting_config['enabled']) ? '1' : '0';
        if (isset($tmp_dynamic_greeting_config['greetings']) && is_array($tmp_dynamic_greeting_config['greetings'])) {
            $loaded_greetings = [];
            foreach ($tmp_dynamic_greeting_config['greetings'] as $item) {
                $line = trim((string)$item);
                if ($line !== '') {
                    $loaded_greetings[] = $line;
                }
            }
            $loaded_greetings = array_values(array_unique($loaded_greetings));
            if (!empty($loaded_greetings)) {
                $dynamic_greeting_list = $loaded_greetings;
                $dynamic_greeting_list_text = implode("\n", $dynamic_greeting_list);
            }
        }
    }
}

$bot_receiver_config = [
    'pendaftaran' => '',
    'server' => '',
    'livechat' => '',
    'system' => '',
    'odp_los' => '',
    'manual_active' => '',
    'provisioning' => ''
];

if (file_exists($bot_receiver_config_path)) {
    $tmp_bot_receiver_config = json_decode(file_get_contents($bot_receiver_config_path), true);
    if (is_array($tmp_bot_receiver_config)) {
        $bot_receiver_config = array_merge($bot_receiver_config, $tmp_bot_receiver_config);
    }
}

if (!function_exists('saveBotReceiverConfig')) {
    function saveBotReceiverConfig($filePath, $currentConfig, $key, $value)
    {
        $allowed = ['pendaftaran', 'server', 'livechat', 'system', 'odp_los', 'manual_active', 'provisioning'];
        if (!in_array($key, $allowed, true)) {
            return $currentConfig;
        }
        $currentConfig[$key] = trim((string)$value);
        @file_put_contents($filePath, json_encode($currentConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $currentConfig;
    }
}

if (isset($_POST['simpan_pengaturan_salam_dinamis'])) {
    $dynamic_greeting_enabled = isset($_POST['dynamic_greeting_enabled']) ? '1' : '0';
    $dynamic_greeting_input = (string)($_POST['dynamic_greeting_list'] ?? '');
    $dynamic_greeting_lines = preg_split('/\r\n|\r|\n/', $dynamic_greeting_input);
    $dynamic_greeting_list = [];

    if (is_array($dynamic_greeting_lines)) {
        foreach ($dynamic_greeting_lines as $line) {
            $line = trim((string)$line);
            if ($line !== '') {
                $dynamic_greeting_list[] = $line;
            }
        }
    }

    $dynamic_greeting_list = array_values(array_unique($dynamic_greeting_list));
    if (empty($dynamic_greeting_list)) {
        $dynamic_greeting_list = $dynamic_greeting_default_list;
    }
    $dynamic_greeting_list_text = implode("\n", $dynamic_greeting_list);

    $dynamic_greeting_payload = [
        'enabled' => $dynamic_greeting_enabled === '1',
        'greetings' => $dynamic_greeting_list,
        'owner' => $owner_key_for_bot_cfg,
        'updated_at' => date('Y-m-d H:i:s')
    ];

    if (file_put_contents($dynamic_greeting_config_path, json_encode($dynamic_greeting_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false) {
        echo "<div class='alert alert-success'>Pengaturan salam dinamis berhasil disimpan.</div>";
    } else {
        echo "<div class='alert alert-danger'>Gagal menyimpan pengaturan salam dinamis.</div>";
    }

    $history_file = "../notifbot/data/history-$ceknama.json";
    $history = [];
    if (file_exists($history_file)) {
        $history = json_decode(file_get_contents($history_file), true);
    }
    if (!is_array($history)) $history = [];
    $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Menyimpan pengaturan salam dinamis notification";
    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
}

    // Proses simpan nomor penerima pesan (card baru)
if (isset($_POST['simpan_nomor_penerima'])) {
    $nomor_penerima = trim($_POST['nomor_penerima'] ?? '');
    $tipe_penerima = $_POST['tipe_penerima'] ?? 'pribadi';
    $nomor_valid = true;
    $nomor_final = '';
    if ($tipe_penerima === 'pribadi') {
        // Validasi harus mulai dengan 62 dan hanya angka
        if (preg_match('/^62[0-9]{7,15}$/', $nomor_penerima)) {
            $nomor_final = $nomor_penerima . '@s.whatsapp.net';
        } else {
            $nomor_valid = false;
        }
    } elseif ($tipe_penerima === 'grup') {
        // Grup: tidak validasi awalan, hanya tambahkan @g.us
        $nomor_final = $nomor_penerima . '@g.us';
    }
    if ($nomor_valid) {
        $bot_penerima_arr = isset($_POST['bot_penerima']) && is_array($_POST['bot_penerima']) ? $_POST['bot_penerima'] : [];
        $selected_bot_penerima = encodeBotSelection($bot_penerima_arr, $bot_options ?? []);
        $bot_receiver_config = saveBotReceiverConfig($bot_receiver_config_path, $bot_receiver_config, 'pendaftaran', $selected_bot_penerima);
        // Simpan ke tabel botwa kolom penerima, update berdasarkan pemilik (gunakan $ceknama jika ada)
        $pemilik = isset($ceknama) ? $ceknama : '';
        if ($pemilik !== '') {
            $stmt = $conn->prepare("UPDATE botwa SET penerima = ? WHERE pemilik = ?");
            $stmt->bind_param('ss', $nomor_final, $pemilik);
            if ($stmt->execute()) {
                echo "<div class='alert alert-success'>Nomor penerima berhasil disimpan di database: <b>".htmlspecialchars($nomor_final)."</b></div>";
            } else {
                echo "<div class='alert alert-danger'>Gagal menyimpan ke database.</div>";
            }
            $stmt->close();

            $history_file = "../notifbot/data/history-$ceknama.json";
            $history = [];
            if (file_exists($history_file)) {
                $history = json_decode(file_get_contents($history_file), true);
            }
            if (!is_array($history)) $history = [];
            $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Menyimpan nomor penerima pesan";
            file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
        } else {
            echo "<div class='alert alert-warning'>Pemilik tidak diketahui, gagal simpan ke database.</div>";
        }
    } else {
        echo "<div class='alert alert-warning'>Nomor pribadi harus diawali 62 dan hanya angka!</div>";
    }
}


// Proses simpan nomor penerima server
if (isset($_POST['simpan_penerima_server'])) {
    $nomor_penerima = trim($_POST['nomor_penerima_server'] ?? '');
    $tipe_penerima = $_POST['tipe_penerima_server'] ?? 'pribadi';
    $nomor_valid = true;
    $nomor_final = '';
    if ($tipe_penerima === 'pribadi') {
        // Validasi harus mulai dengan 62 dan hanya angka
        if (preg_match('/^62[0-9]{7,15}$/', $nomor_penerima)) {
            $nomor_final = $nomor_penerima . '@s.whatsapp.net';
        } else {
            $nomor_valid = false;
        }
    } elseif ($tipe_penerima === 'grup') {
        // Grup: tidak validasi awalan, hanya tambahkan @g.us
        $nomor_final = $nomor_penerima . '@g.us';
    }
    if ($nomor_valid) {
        $bot_penerima_server_arr = isset($_POST['bot_penerima_server']) && is_array($_POST['bot_penerima_server']) ? $_POST['bot_penerima_server'] : [];
        $selected_bot_penerima_server = encodeBotSelection($bot_penerima_server_arr, $bot_options ?? []);
        $bot_receiver_config = saveBotReceiverConfig($bot_receiver_config_path, $bot_receiver_config, 'server', $selected_bot_penerima_server);
        // Simpan ke tabel botwa kolom penerima_server, update berdasarkan pemilik (gunakan $ceknama jika ada)
        $pemilik = isset($ceknama) ? $ceknama : '';
        if ($pemilik !== '') {
            $stmt = $conn->prepare("UPDATE botwa SET penerima_server = ? WHERE pemilik = ?");
            $stmt->bind_param('ss', $nomor_final, $pemilik);
            if ($stmt->execute()) {
                echo "<div class='alert alert-success'>Nomor penerima server berhasil disimpan di database: <b>" . htmlspecialchars($nomor_final) . "</b></div>";
            } else {
                echo "<div class='alert alert-danger'>Gagal menyimpan ke database.</div>";
            }
            $stmt->close();

            $history_file = "../notifbot/data/history-$ceknama.json";
            $history = [];
            if (file_exists($history_file)) {
                $history = json_decode(file_get_contents($history_file), true);
            }
            if (!is_array($history)) $history = [];
            $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Menyimpan nomor penerima server";
            file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
        } else {
            echo "<div class='alert alert-warning'>Pemilik tidak diketahui, gagal simpan ke database.</div>";
        }
    } else {
        echo "<div class='alert alert-warning'>Nomor pribadi harus diawali 62 dan hanya angka!</div>";
    }
}


// Proses simpan nomor penerima livechat
if (isset($_POST['simpan_penerima_livechat'])) {
    $nomor_penerima = trim($_POST['nomor_penerima_livechat'] ?? '');
    $tipe_penerima = $_POST['tipe_penerima_livechat'] ?? 'pribadi';
    $nomor_valid = true;
    $nomor_final = '';
    if ($tipe_penerima === 'pribadi') {
        // Validasi harus mulai dengan 62 dan hanya angka
        if (preg_match('/^62[0-9]{7,15}$/', $nomor_penerima)) {
            $nomor_final = $nomor_penerima . '@s.whatsapp.net';
        } else {
            $nomor_valid = false;
        }
    } elseif ($tipe_penerima === 'grup') {
        // Grup: tidak validasi awalan, hanya tambahkan @g.us
        $nomor_final = $nomor_penerima . '@g.us';
    }
    if ($nomor_valid) {
        $bot_penerima_livechat_arr = isset($_POST['bot_penerima_livechat']) && is_array($_POST['bot_penerima_livechat']) ? $_POST['bot_penerima_livechat'] : [];
        $selected_bot_penerima_livechat = encodeBotSelection($bot_penerima_livechat_arr, $bot_options ?? []);
        $bot_receiver_config = saveBotReceiverConfig($bot_receiver_config_path, $bot_receiver_config, 'livechat', $selected_bot_penerima_livechat);
        // Simpan ke tabel botwa kolom penerima_livechat, update berdasarkan pemilik (gunakan $ceknama jika ada)
        $pemilik = isset($ceknama) ? $ceknama : '';
        if ($pemilik !== '') {
            $stmt = $conn->prepare("UPDATE botwa SET penerima_livechat = ? WHERE pemilik = ?");
            $stmt->bind_param('ss', $nomor_final, $pemilik);
            if ($stmt->execute()) {
                echo "<div class='alert alert-success'>Nomor penerima livechat berhasil disimpan di database: <b>" . htmlspecialchars($nomor_final) . "</b></div>";
            } else {
                echo "<div class='alert alert-danger'>Gagal menyimpan ke database.</div>";
            }
            $stmt->close();

            $history_file = "../notifbot/data/history-$ceknama.json";
            $history = [];
            if (file_exists($history_file)) {
                $history = json_decode(file_get_contents($history_file), true);
            }
            if (!is_array($history)) $history = [];
            $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Menyimpan nomor penerima livechat";
            file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
        } else {
            echo "<div class='alert alert-warning'>Pemilik tidak diketahui, gagal simpan ke database.</div>";
        }
    } else {
        echo "<div class='alert alert-warning'>Nomor pribadi harus diawali 62 dan hanya angka!</div>";
    }
}


// Proses simpan nomor penerima system notif
if (isset($_POST['simpan_penerima_system_notif'])) {
    $nomor_penerima = trim($_POST['nomor_penerima_system_notif'] ?? '');
    $tipe_penerima = $_POST['tipe_penerima_system_notif'] ?? 'pribadi';
    $nomor_valid = true;
    $nomor_final = '';
    if ($tipe_penerima === 'pribadi') {
        // Validasi harus mulai dengan 62 dan hanya angka
        if (preg_match('/^62[0-9]{7,15}$/', $nomor_penerima)) {
            $nomor_final = $nomor_penerima . '@s.whatsapp.net';
        } else {
            $nomor_valid = false;
        }
    } elseif ($tipe_penerima === 'grup') {
        // Grup: tidak validasi awalan, hanya tambahkan @g.us
        $nomor_final = $nomor_penerima . '@g.us';
    }
    if ($nomor_valid) {
        $bot_penerima_system_notif_arr = isset($_POST['bot_penerima_system_notif']) && is_array($_POST['bot_penerima_system_notif']) ? $_POST['bot_penerima_system_notif'] : [];
        $selected_bot_penerima_system_notif = encodeBotSelection($bot_penerima_system_notif_arr, $bot_options ?? []);
        $bot_receiver_config = saveBotReceiverConfig($bot_receiver_config_path, $bot_receiver_config, 'system', $selected_bot_penerima_system_notif);
        // Simpan ke tabel botwa kolom penerima_system_notif, update berdasarkan pemilik (gunakan $ceknama jika ada)
        $pemilik = isset($ceknama) ? $ceknama : '';
        if ($pemilik !== '') {
            $stmt = $conn->prepare("UPDATE botwa SET penerima_system_notif = ? WHERE pemilik = ?");
            $stmt->bind_param('ss', $nomor_final, $pemilik);
            if ($stmt->execute()) {
                echo "<div class='alert alert-success'>Nomor penerima system notif berhasil disimpan di database: <b>" . htmlspecialchars($nomor_final) . "</b></div>";
            } else {
                echo "<div class='alert alert-danger'>Gagal menyimpan ke database.</div>";
            }
            $stmt->close();

            $history_file = "../notifbot/data/history-$ceknama.json";
            $history = [];
            if (file_exists($history_file)) {
                $history = json_decode(file_get_contents($history_file), true);
            }
            if (!is_array($history)) $history = [];
            $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Menyimpan nomor penerima system notif";
            file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
        } else {
            echo "<div class='alert alert-warning'>Pemilik tidak diketahui, gagal simpan ke database.</div>";
        }
    } else {
        echo "<div class='alert alert-warning'>Nomor pribadi harus diawali 62 dan hanya angka!</div>";
    }
}

// Proses simpan nomor penerima notif ODP semua LOS
if (isset($_POST['simpan_penerima_odp_los'])) {
    $nomor_penerima = trim($_POST['nomor_penerima_odp_los'] ?? '');
    $tipe_penerima = $_POST['tipe_penerima_odp_los'] ?? 'pribadi';
    $nomor_valid = true;
    $nomor_final = '';
    if ($tipe_penerima === 'pribadi') {
        if (preg_match('/^62[0-9]{7,15}$/', $nomor_penerima)) {
            $nomor_final = $nomor_penerima . '@s.whatsapp.net';
        } else {
            $nomor_valid = false;
        }
    } elseif ($tipe_penerima === 'grup') {
        $nomor_final = $nomor_penerima . '@g.us';
    }
    if ($nomor_valid) {
        $bot_penerima_odp_los_arr = isset($_POST['bot_penerima_odp_los']) && is_array($_POST['bot_penerima_odp_los']) ? $_POST['bot_penerima_odp_los'] : [];
        $selected_bot_penerima_odp_los = encodeBotSelection($bot_penerima_odp_los_arr, $bot_options ?? []);
        $bot_receiver_config = saveBotReceiverConfig($bot_receiver_config_path, $bot_receiver_config, 'odp_los', $selected_bot_penerima_odp_los);
        $pemilik = isset($ceknama) ? $ceknama : '';
        if ($pemilik !== '') {
            $stmt = $conn->prepare("UPDATE botwa SET penerima_odp_los = ? WHERE pemilik = ?");
            $stmt->bind_param('ss', $nomor_final, $pemilik);
            if ($stmt->execute()) {
                echo "<div class='alert alert-success'>Nomor penerima notif ODP LOS berhasil disimpan di database: <b>" . htmlspecialchars($nomor_final) . "</b></div>";
            } else {
                echo "<div class='alert alert-danger'>Gagal menyimpan ke database.</div>";
            }
            $stmt->close();

            $history_file = "../notifbot/data/history-$ceknama.json";
            $history = [];
            if (file_exists($history_file)) {
                $history = json_decode(file_get_contents($history_file), true);
            }
            if (!is_array($history)) $history = [];
            $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Menyimpan nomor penerima notif ODP LOS";
            file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
        } else {
            echo "<div class='alert alert-warning'>Pemilik tidak diketahui, gagal simpan ke database.</div>";
        }
    } else {
        echo "<div class='alert alert-warning'>Nomor pribadi harus diawali 62 dan hanya angka!</div>";
    }
}

// Proses simpan nomor penerima manual active (owner)
if (isset($_POST['simpan_penerima_manual_active'])) {
    $nomor_penerima = trim($_POST['nomor_penerima_manual_active'] ?? '');
    $tipe_penerima = $_POST['tipe_penerima_manual_active'] ?? 'pribadi';
    $nomor_valid = true;
    $nomor_final = '';
    if ($tipe_penerima === 'pribadi') {
        if (preg_match('/^62[0-9]{7,15}$/', $nomor_penerima)) {
            $nomor_final = $nomor_penerima . '@s.whatsapp.net';
        } else {
            $nomor_valid = false;
        }
    } elseif ($tipe_penerima === 'grup') {
        $nomor_final = $nomor_penerima . '@g.us';
    }
    if ($nomor_valid) {
        $bot_penerima_manual_active_arr = isset($_POST['bot_penerima_manual_active']) && is_array($_POST['bot_penerima_manual_active']) ? $_POST['bot_penerima_manual_active'] : [];
        $selected_bot_penerima_manual_active = encodeBotSelection($bot_penerima_manual_active_arr, $bot_options ?? []);
        $bot_receiver_config = saveBotReceiverConfig($bot_receiver_config_path, $bot_receiver_config, 'manual_active', $selected_bot_penerima_manual_active);
        $pemilik = isset($ceknama) ? $ceknama : '';
        if ($pemilik !== '') {
            $stmt = $conn->prepare("UPDATE botwa SET penerima_manual_active = ? WHERE pemilik = ?");
            $stmt->bind_param('ss', $nomor_final, $pemilik);
            if ($stmt->execute()) {
                echo "<div class='alert alert-success'>Nomor penerima manual active berhasil disimpan di database: <b>" . htmlspecialchars($nomor_final) . "</b></div>";
            } else {
                echo "<div class='alert alert-danger'>Gagal menyimpan ke database.</div>";
            }
            $stmt->close();

            $history_file = "../notifbot/data/history-$ceknama.json";
            $history = [];
            if (file_exists($history_file)) {
                $history = json_decode(file_get_contents($history_file), true);
            }
            if (!is_array($history)) $history = [];
            $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Menyimpan nomor penerima manual active";
            file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
        } else {
            echo "<div class='alert alert-warning'>Pemilik tidak diketahui, gagal simpan ke database.</div>";
        }
    } else {
        echo "<div class='alert alert-warning'>Nomor pribadi harus diawali 62 dan hanya angka!</div>";
    }
}

if (isset($_POST['simpan_penerima_provisioning'])) {
    $nomor_penerima = trim($_POST['nomor_penerima_provisioning'] ?? '');
    $tipe_penerima = $_POST['tipe_penerima_provisioning'] ?? 'pribadi';
    $nomor_valid = true;
    $nomor_final = '';
    if ($tipe_penerima === 'pribadi') {
        if (preg_match('/^62[0-9]{7,15}$/', $nomor_penerima)) {
            $nomor_final = $nomor_penerima . '@s.whatsapp.net';
        } else {
            $nomor_valid = false;
        }
    } elseif ($tipe_penerima === 'grup') {
        $nomor_final = $nomor_penerima . '@g.us';
    }
    if ($nomor_valid) {
        $bot_penerima_provisioning_arr = isset($_POST['bot_penerima_provisioning']) && is_array($_POST['bot_penerima_provisioning']) ? $_POST['bot_penerima_provisioning'] : [];
        $selected_bot_penerima_provisioning = encodeBotSelection($bot_penerima_provisioning_arr, $bot_options ?? []);
        $bot_receiver_config = saveBotReceiverConfig($bot_receiver_config_path, $bot_receiver_config, 'provisioning', $selected_bot_penerima_provisioning);
        $pemilik = isset($ceknama) ? $ceknama : '';
        if ($pemilik !== '') {
            $stmt = $conn->prepare("UPDATE botwa SET penerima_provisioning = ? WHERE pemilik = ?");
            $stmt->bind_param('ss', $nomor_final, $pemilik);
            if ($stmt->execute()) {
                echo "<div class='alert alert-success'>Nomor penerima provisioning berhasil disimpan di database: <b>" . htmlspecialchars($nomor_final) . "</b></div>";
            } else {
                echo "<div class='alert alert-danger'>Gagal menyimpan ke database.</div>";
            }
            $stmt->close();

            $history_file = "../notifbot/data/history-$ceknama.json";
            $history = [];
            if (file_exists($history_file)) {
                $history = json_decode(file_get_contents($history_file), true);
            }
            if (!is_array($history)) $history = [];
            $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Menyimpan nomor penerima provisioning";
            file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
        } else {
            echo "<div class='alert alert-warning'>Pemilik tidak diketahui, gagal simpan ke database.</div>";
        }
    } else {
        echo "<div class='alert alert-warning'>Nomor pribadi harus diawali 62 dan hanya angka!</div>";
    }
}

// Pengaturan OTP portal pelanggan (mode + WA API)
$otp_portal_config_path = __DIR__ . '/broadband/otp_portal_config.json';
$otp_portal_waapi_path = __DIR__ . '/broadband/waapi.txt';
$otp_portal_mode = 'bypass';
$otp_portal_waapi = '';
$otp_portal_namebot = '';
$otp_portal_password = '';
$otp_portal_template = "*[INI ADALAH PESAN OTOMATIS]*\nIni adalah kode otp anda : *{otp}*";

if (isset($_POST['simpan_pengaturan_otp_portal'])) {
    $otp_mode_post = trim($_POST['otp_portal_mode'] ?? 'bypass');
    $otp_mode_post = in_array($otp_mode_post, ['otp', 'bypass'], true) ? $otp_mode_post : 'bypass';

    $otp_waapi_post = trim($_POST['otp_portal_waapi'] ?? '');
    $otp_namebot_post = trim($_POST['otp_portal_namebot'] ?? '');
    $otp_password_post = trim($_POST['otp_portal_password'] ?? '');
    $otp_template_post = trim($_POST['otp_portal_template'] ?? '');
    if ($otp_template_post === '') {
        $otp_template_post = "*[INI ADALAH PESAN OTOMATIS]*\nIni adalah kode otp anda : *{otp}*";
    }

    $otp_payload = [
        'otp_mode' => $otp_mode_post,
        'otp_message_template' => $otp_template_post,
        'updated_at' => date('Y-m-d H:i:s')
    ];

    $json_saved = file_put_contents($otp_portal_config_path, json_encode($otp_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $waapi_content = "waapi={$otp_waapi_post}\nnamebot={$otp_namebot_post}\npassword={$otp_password_post}\n";
    $waapi_saved = file_put_contents($otp_portal_waapi_path, $waapi_content);

    if ($json_saved !== false && $waapi_saved !== false) {
        echo "<div class='alert alert-success'>Pengaturan OTP Portal berhasil disimpan.</div>";
    } else {
        echo "<div class='alert alert-danger'>Gagal menyimpan pengaturan OTP Portal.</div>";
    }

    $history_file = "../notifbot/data/history-$ceknama.json";
    $history = [];
    if (file_exists($history_file)) {
        $history = json_decode(file_get_contents($history_file), true);
    }
    if (!is_array($history)) $history = [];
    $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Menyimpan pengaturan OTP portal pelanggan";
    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
}

if (file_exists($otp_portal_config_path)) {
    $otp_cfg = json_decode(file_get_contents($otp_portal_config_path), true);
    if (is_array($otp_cfg)) {
        $otp_portal_mode = ($otp_cfg['otp_mode'] ?? 'bypass') === 'otp' ? 'otp' : 'bypass';
        $otp_portal_template = trim($otp_cfg['otp_message_template'] ?? $otp_portal_template);
        if ($otp_portal_template === '') {
            $otp_portal_template = "*[INI ADALAH PESAN OTOMATIS]*\nIni adalah kode otp anda : *{otp}*";
        }
    }
}

if (file_exists($otp_portal_waapi_path)) {
    $wa_lines = file($otp_portal_waapi_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($wa_lines as $wa_line) {
        if (strpos($wa_line, 'waapi=') === 0) $otp_portal_waapi = substr($wa_line, 6);
        if (strpos($wa_line, 'namebot=') === 0) $otp_portal_namebot = substr($wa_line, 8);
        if (strpos($wa_line, 'password=') === 0) $otp_portal_password = substr($wa_line, 9);
    }
}

$odp_los_interval_config_file = __DIR__ . "/notifbot/data/odp_los_interval-$username.json";
$odp_los_interval_unit = 'jam';
$odp_los_interval_value = 1;

if (file_exists($odp_los_interval_config_file)) {
    $odp_los_cfg = json_decode(file_get_contents($odp_los_interval_config_file), true);
    if (is_array($odp_los_cfg)) {
        $odp_los_interval_unit = in_array(($odp_los_cfg['unit'] ?? 'jam'), ['menit', 'jam'], true) ? $odp_los_cfg['unit'] : 'jam';
        $odp_los_interval_value = (int)($odp_los_cfg['value'] ?? 1);
    }
}

if (isset($_POST['simpan_interval_odp_los'])) {
    $odp_los_interval_unit_post = $_POST['odp_los_interval_unit'] ?? 'jam';
    $odp_los_interval_value_post = (int)($_POST['odp_los_interval_value'] ?? 1);
    $odp_los_interval_unit_post = in_array($odp_los_interval_unit_post, ['menit', 'jam'], true) ? $odp_los_interval_unit_post : 'jam';

    if ($odp_los_interval_unit_post === 'menit') {
        $odp_los_interval_value_post = max(1, min(59, $odp_los_interval_value_post));
    } else {
        $odp_los_interval_value_post = max(1, min(23, $odp_los_interval_value_post));
    }

    $odp_los_payload = [
        'unit' => $odp_los_interval_unit_post,
        'value' => $odp_los_interval_value_post,
        'updated_at' => date('Y-m-d H:i:s')
    ];

    if (file_put_contents($odp_los_interval_config_file, json_encode($odp_los_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false) {
        echo "<div class='alert alert-success'>Interval cek ODP LOS berhasil disimpan.</div>";
        $odp_los_interval_unit = $odp_los_interval_unit_post;
        $odp_los_interval_value = $odp_los_interval_value_post;
    } else {
        echo "<div class='alert alert-danger'>Gagal menyimpan interval cek ODP LOS.</div>";
    }

    $history_file = "../notifbot/data/history-$ceknama.json";
    $history = [];
    if (file_exists($history_file)) {
        $history = json_decode(file_get_contents($history_file), true);
    }
    if (!is_array($history)) $history = [];
    $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Menyimpan interval cek notif ODP LOS";
    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
}

$penerima_val = '';
$tipe_penerima_val = 'pribadi';
$pemilik = isset($ceknama) ? $ceknama : '';
if ($pemilik !== '') {
    $stmt = $conn->prepare("SELECT penerima FROM botwa WHERE pemilik = ? LIMIT 1");
    $stmt->bind_param('s', $pemilik);
    $stmt->execute();
    $stmt->bind_result($penerima_db);
    if ($stmt->fetch() && $penerima_db) {
        $penerima_val = $penerima_db;
        // Deteksi tipe dari suffix
        if (substr($penerima_val, -14) === '@s.whatsapp.net') {
            $tipe_penerima_val = 'pribadi';
            $penerima_val = substr($penerima_val, 0, -14);
        } elseif (substr($penerima_val, -5) === '@g.us') {
            $tipe_penerima_val = 'grup';
            $penerima_val = substr($penerima_val, 0, -5);
        }
    }
    $stmt->close();
}


// Ambil nilai penerima_server dari database untuk value default form
$penerima_server_val = '';
$tipe_penerima_server_val = 'pribadi';
$pemilik = isset($ceknama) ? $ceknama : '';
if ($pemilik !== '') {
    $stmt = $conn->prepare("SELECT penerima_server FROM botwa WHERE pemilik = ? LIMIT 1");
    $stmt->bind_param('s', $pemilik);
    $stmt->execute();
    $stmt->bind_result($penerima_server_db);
    if ($stmt->fetch() && $penerima_server_db) {
        $penerima_server_val = $penerima_server_db;
        // Deteksi tipe dari suffix
        if (substr($penerima_server_val, -14) === '@s.whatsapp.net') {
            $tipe_penerima_server_val = 'pribadi';
            $penerima_server_val = substr($penerima_server_val, 0, -14);
        } elseif (substr($penerima_server_val, -5) === '@g.us') {
            $tipe_penerima_server_val = 'grup';
            $penerima_server_val = substr($penerima_server_val, 0, -5);
        }
    }
    $stmt->close();
}


// Ambil nilai penerima_livechat dari database untuk value default form
$penerima_livechat_val = '';
$tipe_penerima_livechat_val = 'pribadi';
$pemilik = isset($ceknama) ? $ceknama : '';
if ($pemilik !== '') {
    $stmt = $conn->prepare("SELECT penerima_livechat FROM botwa WHERE pemilik = ? LIMIT 1");
    $stmt->bind_param('s', $pemilik);
    $stmt->execute();
    $stmt->bind_result($penerima_livechat_db);
    if ($stmt->fetch() && $penerima_livechat_db) {
        $penerima_livechat_val = $penerima_livechat_db;
        // Deteksi tipe dari suffix
        if (substr($penerima_livechat_val, -14) === '@s.whatsapp.net') {
            $tipe_penerima_livechat_val = 'pribadi';
            $penerima_livechat_val = substr($penerima_livechat_val, 0, -14);
        } elseif (substr($penerima_livechat_val, -5) === '@g.us') {
            $tipe_penerima_livechat_val = 'grup';
            $penerima_livechat_val = substr($penerima_livechat_val, 0, -5);
        }
    }
    $stmt->close();
}


// Ambil nilai penerima_system_notif dari database untuk value default form
$penerima_system_notif_val = '';
$tipe_penerima_system_notif_val = 'pribadi';
$pemilik = isset($ceknama) ? $ceknama : '';
if ($pemilik !== '') {
    $stmt = $conn->prepare("SELECT penerima_system_notif FROM botwa WHERE pemilik = ? LIMIT 1");
    $stmt->bind_param('s', $pemilik);
    $stmt->execute();
    $stmt->bind_result($penerima_system_notif_db);
    if ($stmt->fetch() && $penerima_system_notif_db) {
        $penerima_system_notif_val = $penerima_system_notif_db;
        // Deteksi tipe dari suffix
        if (substr($penerima_system_notif_val, -14) === '@s.whatsapp.net') {
            $tipe_penerima_system_notif_val = 'pribadi';
            $penerima_system_notif_val = substr($penerima_system_notif_val, 0, -14);
        } elseif (substr($penerima_system_notif_val, -5) === '@g.us') {
            $tipe_penerima_system_notif_val = 'grup';
            $penerima_system_notif_val = substr($penerima_system_notif_val, 0, -5);
        }
    }
    $stmt->close();
}


// Ambil nilai penerima_manual_active dari database untuk value default form
$penerima_manual_active_val = '';
$tipe_penerima_manual_active_val = 'pribadi';
$pemilik = isset($ceknama) ? $ceknama : '';
if ($pemilik !== '') {
    $stmt = $conn->prepare("SELECT penerima_manual_active FROM botwa WHERE pemilik = ? LIMIT 1");
    $stmt->bind_param('s', $pemilik);
    $stmt->execute();
    $stmt->bind_result($penerima_manual_active_db);
    if ($stmt->fetch() && $penerima_manual_active_db) {
        $penerima_manual_active_val = $penerima_manual_active_db;
        if (substr($penerima_manual_active_val, -14) === '@s.whatsapp.net') {
            $tipe_penerima_manual_active_val = 'pribadi';
            $penerima_manual_active_val = substr($penerima_manual_active_val, 0, -14);
        } elseif (substr($penerima_manual_active_val, -5) === '@g.us') {
            $tipe_penerima_manual_active_val = 'grup';
            $penerima_manual_active_val = substr($penerima_manual_active_val, 0, -5);
        }
    }
    $stmt->close();
}

$bot_options = ['RANDOM'];  // RANDOM untuk memilih bot secara acak
if (!empty($ceknama)) {
    // Assistant tanpa assign hanya boleh pilih bot yg di-assign owner / bot buatan sendiri
    $bot_option_query = mysqli_query($conn, "SELECT DISTINCT namebot FROM botwa WHERE pemilik = '" . mysqli_real_escape_string($conn, $ceknama) . "'" . botAccessWhereClause($conn, $AKSES, $assigned_bot_ids ?? [], $asistant_name ?? '') . " ORDER BY namebot ASC");
    if ($bot_option_query) {
        while ($bot_option_row = mysqli_fetch_assoc($bot_option_query)) {
            $bot_name_opt = trim((string)($bot_option_row['namebot'] ?? ''));
            if ($bot_name_opt !== '') {
                $bot_options[] = $bot_name_opt;
            }
        }
    }
}


// Ambil nilai penerima_odp_los dari database untuk value default form
$penerima_odp_los_val = '';
$tipe_penerima_odp_los_val = 'pribadi';
$pemilik = isset($ceknama) ? $ceknama : '';
if ($pemilik !== '') {
    $stmt = $conn->prepare("SELECT penerima_odp_los FROM botwa WHERE pemilik = ? LIMIT 1");
    $stmt->bind_param('s', $pemilik);
    $stmt->execute();
    $stmt->bind_result($penerima_odp_los_db);
    if ($stmt->fetch() && $penerima_odp_los_db) {
        $penerima_odp_los_val = $penerima_odp_los_db;
        if (substr($penerima_odp_los_val, -14) === '@s.whatsapp.net') {
            $tipe_penerima_odp_los_val = 'pribadi';
            $penerima_odp_los_val = substr($penerima_odp_los_val, 0, -14);
        } elseif (substr($penerima_odp_los_val, -5) === '@g.us') {
            $tipe_penerima_odp_los_val = 'grup';
            $penerima_odp_los_val = substr($penerima_odp_los_val, 0, -5);
        }
    }
    $stmt->close();
}

// Ambil nilai penerima_provisioning dari database untuk value default form
$penerima_provisioning_val = '';
$tipe_penerima_provisioning_val = 'pribadi';
$pemilik = isset($ceknama) ? $ceknama : '';
if ($pemilik !== '') {
    $stmt = $conn->prepare("SELECT penerima_provisioning FROM botwa WHERE pemilik = ? LIMIT 1");
    $stmt->bind_param('s', $pemilik);
    $stmt->execute();
    $stmt->bind_result($penerima_provisioning_db);
    if ($stmt->fetch() && $penerima_provisioning_db) {
        $penerima_provisioning_val = $penerima_provisioning_db;
        if (substr($penerima_provisioning_val, -14) === '@s.whatsapp.net') {
            $tipe_penerima_provisioning_val = 'pribadi';
            $penerima_provisioning_val = substr($penerima_provisioning_val, 0, -14);
        } elseif (substr($penerima_provisioning_val, -5) === '@g.us') {
            $tipe_penerima_provisioning_val = 'grup';
            $penerima_provisioning_val = substr($penerima_provisioning_val, 0, -5);
        }
    }
    $stmt->close();
}

$selected_bot_penerima = trim((string)($bot_receiver_config['pendaftaran'] ?? ''));
$selected_bot_penerima_server = trim((string)($bot_receiver_config['server'] ?? ''));
$selected_bot_penerima_livechat = trim((string)($bot_receiver_config['livechat'] ?? ''));
$selected_bot_penerima_system_notif = trim((string)($bot_receiver_config['system'] ?? ''));
$selected_bot_penerima_odp_los = trim((string)($bot_receiver_config['odp_los'] ?? ''));
$selected_bot_penerima_manual_active = trim((string)($bot_receiver_config['manual_active'] ?? ''));
$selected_bot_penerima_provisioning = trim((string)($bot_receiver_config['provisioning'] ?? ''));

// --- Bantu tampilkan checkbox: pecah nilai tersimpan jadi array untuk pengecekan 'checked' ---
$checked_bot_penerima              = ($selected_bot_penerima === '' || $selected_bot_penerima === 'RANDOM') ? ['RANDOM'] : explode(',', $selected_bot_penerima);
$checked_bot_penerima_server       = ($selected_bot_penerima_server === '' || $selected_bot_penerima_server === 'RANDOM') ? ['RANDOM'] : explode(',', $selected_bot_penerima_server);
$checked_bot_penerima_livechat     = ($selected_bot_penerima_livechat === '' || $selected_bot_penerima_livechat === 'RANDOM') ? ['RANDOM'] : explode(',', $selected_bot_penerima_livechat);
$checked_bot_penerima_system_notif = ($selected_bot_penerima_system_notif === '' || $selected_bot_penerima_system_notif === 'RANDOM') ? ['RANDOM'] : explode(',', $selected_bot_penerima_system_notif);
$checked_bot_penerima_odp_los      = ($selected_bot_penerima_odp_los === '' || $selected_bot_penerima_odp_los === 'RANDOM') ? ['RANDOM'] : explode(',', $selected_bot_penerima_odp_los);
$checked_bot_penerima_manual_active = ($selected_bot_penerima_manual_active === '' || $selected_bot_penerima_manual_active === 'RANDOM') ? ['RANDOM'] : explode(',', $selected_bot_penerima_manual_active);
$checked_bot_penerima_provisioning = ($selected_bot_penerima_provisioning === '' || $selected_bot_penerima_provisioning === 'RANDOM') ? ['RANDOM'] : explode(',', $selected_bot_penerima_provisioning);

if (!function_exists('renderBotCheckboxGroup')) {
    /**
     * Cetak grup checkbox pemilihan bot: RANDOM(semua) + tiap bot spesifik.
     */
    function renderBotCheckboxGroup($fieldName, $groupId, array $botOptions, array $checkedList)
    {
        echo '<div class="bot-checkbox-group border rounded p-2" id="' . htmlspecialchars($groupId, ENT_QUOTES, 'UTF-8') . '">';
        echo '<div class="form-check">';
        echo '<input class="form-check-input bot-random-toggle" type="checkbox" name="' . htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8') . '[]" id="' . htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8') . '_RANDOM" value="RANDOM" ' . (in_array('RANDOM', $checkedList, true) ? 'checked' : '') . '>';
        echo '<label class="form-check-label fw-bold" for="' . htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8') . '_RANDOM">ACAK dari SEMUA BOT</label>';
        echo '</div>';
        echo '<hr class="my-2">';
        foreach ($botOptions as $bot_opt) {
            if ($bot_opt === 'RANDOM') continue;
            $bot_opt_safe = htmlspecialchars($bot_opt, ENT_QUOTES, 'UTF-8');
            $cbId = htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8') . '_' . $bot_opt_safe;
            echo '<div class="form-check">';
            echo '<input class="form-check-input bot-specific-checkbox" type="checkbox" name="' . htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8') . '[]" id="' . $cbId . '" value="' . $bot_opt_safe . '" ' . (in_array($bot_opt, $checkedList, true) ? 'checked' : '') . '>';
            echo '<label class="form-check-label" for="' . $cbId . '">' . $bot_opt_safe . '</label>';
            echo '</div>';
        }
        echo '</div>';
        echo '<div class="form-text">Centang <b>ACAK dari SEMUA BOT</b> untuk random dari semua bot. Centang satu bot saja untuk selalu memakai bot itu. Centang beberapa bot untuk random hanya dari bot yang dicentang.</div>';
    }
}

// Proses simpan ketentuan




// Proses simpan pesan registrasi
if (isset($_POST['simpan_pesan_registrasi'])) {
    $pesan_registrasi = trim($_POST['pesan_registrasi'] ?? '');
    $pemilik = isset($ceknama) ? $ceknama : (isset($_POST['pemilik']) ? trim($_POST['pemilik']) : '');
    if ($pesan_registrasi !== '' && $pemilik !== '') {
        $stmt = $conn->prepare("SELECT id FROM notif_khusus WHERE pemilik = ?");
        $stmt->bind_param('s', $pemilik);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $stmt2 = $conn->prepare("UPDATE notif_khusus SET pesan_registrasi = ? WHERE pemilik = ?");
            $stmt2->bind_param('ss', $pesan_registrasi, $pemilik);
            $stmt2->execute();
            $stmt2->close();
            echo "<div class='alert alert-success'><i class='fas fa-check-circle me-2'></i>Pesan registrasi berhasil diupdate!</div>";
        } else {
            $stmt2 = $conn->prepare("INSERT INTO notif_khusus (pemilik, pesan_registrasi) VALUES (?, ?)");
            $stmt2->bind_param('ss', $pemilik, $pesan_registrasi);
            $stmt2->execute();
            $stmt2->close();
            echo "<div class='alert alert-success'><i class='fas fa-check-circle me-2'></i>Pesan registrasi berhasil disimpan!</div>";
        }
        $stmt->close();

        $history_file = "../notifbot/data/history-$ceknama.json";
        $history = [];
        if (file_exists($history_file)) {
            $history = json_decode(file_get_contents($history_file), true);
        }
        if (!is_array($history)) $history = [];
        $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Menyimpan pesan registrasi pelanggan baru";
        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    }
}

// Proses simpan pesan expired
if (isset($_POST['simpan_pesan_expired'])) {
    $pesan_expired = trim($_POST['pesan_expired'] ?? '');
    $pemilik = isset($ceknama) ? $ceknama : (isset($_POST['pemilik']) ? trim($_POST['pemilik']) : '');
    if ($pesan_expired !== '' && $pemilik !== '') {
        $stmt = $conn->prepare("SELECT id FROM notif_khusus WHERE pemilik = ?");
        $stmt->bind_param('s', $pemilik);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $stmt2 = $conn->prepare("UPDATE notif_khusus SET pesan_expired = ? WHERE pemilik = ?");
            $stmt2->bind_param('ss', $pesan_expired, $pemilik);
            $stmt2->execute();
            $stmt2->close();
            echo "<div class='alert alert-success'><i class='fas fa-check-circle me-2'></i>Pesan expired berhasil diupdate!</div>";
        } else {
            $stmt2 = $conn->prepare("INSERT INTO notif_khusus (pemilik, pesan_expired) VALUES (?, ?)");
            $stmt2->bind_param('ss', $pemilik, $pesan_expired);
            $stmt2->execute();
            $stmt2->close();
            echo "<div class='alert alert-success'><i class='fas fa-check-circle me-2'></i>Pesan expired berhasil disimpan!</div>";
        }
        $stmt->close();

        $history_file = "../notifbot/data/history-$ceknama.json";
        $history = [];
        if (file_exists($history_file)) {
            $history = json_decode(file_get_contents($history_file), true);
        }
        if (!is_array($history)) $history = [];
        $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Menyimpan pesan expired";
        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    }
}

// Proses simpan pesan reminder
if (isset($_POST['simpan_pesan_reminder'])) {
    $pesan_reminder = trim($_POST['pesan_reminder'] ?? '');
    $pemilik = isset($ceknama) ? $ceknama : (isset($_POST['pemilik']) ? trim($_POST['pemilik']) : '');
    if ($pesan_reminder !== '' && $pemilik !== '') {
        $stmt = $conn->prepare("SELECT id FROM notif_khusus WHERE pemilik = ?");
        $stmt->bind_param('s', $pemilik);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $stmt2 = $conn->prepare("UPDATE notif_khusus SET pesan_reminder = ? WHERE pemilik = ?");
            $stmt2->bind_param('ss', $pesan_reminder, $pemilik);
            $stmt2->execute();
            $stmt2->close();
            echo "<div class='alert alert-success'><i class='fas fa-check-circle me-2'></i>Pesan reminder berhasil diupdate!</div>";
        } else {
            $stmt2 = $conn->prepare("INSERT INTO notif_khusus (pemilik, pesan_reminder) VALUES (?, ?)");
            $stmt2->bind_param('ss', $pemilik, $pesan_reminder);
            $stmt2->execute();
            $stmt2->close();
            echo "<div class='alert alert-success'><i class='fas fa-check-circle me-2'></i>Pesan reminder berhasil disimpan!</div>";
        }
        $stmt->close();

        $history_file = "../notifbot/data/history-$ceknama.json";
        $history = [];
        if (file_exists($history_file)) {
            $history = json_decode(file_get_contents($history_file), true);
        }
        if (!is_array($history)) $history = [];
        $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Menyimpan pesan reminder pembayaran";
        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    }
}

// Proses simpan filter reminder (skip pelanggan sudah bayar / skip sudah dinotif
// hari ini) -- disimpan ke tabel `reminder_settings` (kolom yang sama dgn
// Payment Setting -> Konfigurasi Fixed Due Date), via reminderSettingsSave()
// yang PARTIAL update (cuma 2 kolom filter ini) supaya key lain (jatuh_tempo,
// hari_sebelum, dst -- dikelola paymentset.php) TIDAK ikut hilang. Helper ini
// juga otomatis regenerate mirror notifbot/data/reminder-{username}.json yang
// masih dibaca notif_remainder_pembayaran*.php. (Form ini submit via AJAX ke
// notification_api.php action save_filter_reminder di kondisi normal -- blok
// ini fallback kalau JS gagal/nonaktif, lihat actionMap di baris ~3050.)
if (isset($_POST['simpan_filter_reminder'])) {
    $pemilikFilter = isset($ceknama) ? $ceknama : '';
    if ($pemilikFilter !== '') {
        reminderSettingsSave($conn, $pemilikFilter, [
            'filter_sudah_bayar_reminder' => isset($_POST['filter_sudah_bayar_reminder']),
            'filter_skip_notif_hari_ini' => isset($_POST['filter_skip_notif_hari_ini']),
        ]);
        echo "<div class='alert alert-success'><i class='fas fa-check-circle me-2'></i>Filter reminder berhasil disimpan!</div>";
    }
}

// Proses simpan ketentuan
if (isset($_POST['simpan_ketentuan'])) {
    $pesan_ketentuan = trim($_POST['pesan_ketentuan'] ?? '');
    $pemilik = isset($ceknama) ? $ceknama : (isset($_POST['pemilik']) ? trim($_POST['pemilik']) : '');
    if ($pesan_ketentuan !== '' && $pemilik !== '') {
        $stmt = $conn->prepare("SELECT id FROM notif_khusus WHERE pemilik = ?");
        $stmt->bind_param('s', $pemilik);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $stmtu = $conn->prepare("UPDATE notif_khusus SET pesan_ketentuan = ? WHERE pemilik = ?");
            $stmtu->bind_param('ss', $pesan_ketentuan, $pemilik);
            $stmtu->execute();
            $stmtu->close();
            $msg = "<div class='alert alert-success'>Ketentuan berhasil diupdate!</div>";
        } else {
            $stmti = $conn->prepare("INSERT INTO notif_khusus (pesan_ketentuan, pemilik) VALUES (?, ?)");
            $stmti->bind_param('ss', $pesan_ketentuan, $pemilik);
            $stmti->execute();
            $stmti->close();
            $msg = "<div class='alert alert-success'>Ketentuan berhasil disimpan!</div>";
        }
        $stmt->close();

        $history_file = "../notifbot/data/history-$ceknama.json";
        $history = [];
        if (file_exists($history_file)) {
            $history = json_decode(file_get_contents($history_file), true);
        }
        if (!is_array($history)) $history = [];
        $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Menyimpan ketentuan notifikasi";
        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    } else {
        echo "<div class='alert alert-warning'>Pesan ketentuan dan pemilik tidak boleh kosong!</div>";
    }
}


// Proses simpan pesan disable manual
if (isset($_POST['simpan_pesan_disable'])) {
    $pesan_disable = trim($_POST['pesan_disable'] ?? '');
    $pemilik = isset($ceknama) ? $ceknama : (isset($_POST['pemilik']) ? trim($_POST['pemilik']) : '');
    if ($pesan_disable !== '' && $pemilik !== '') {
        $stmt = $conn->prepare("SELECT id FROM notif_khusus WHERE pemilik = ?");
        $stmt->bind_param('s', $pemilik);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $stmtu = $conn->prepare("UPDATE notif_khusus SET pesan_disable = ? WHERE pemilik = ?");
            $stmtu->bind_param('ss', $pesan_disable, $pemilik);
            $stmtu->execute();
            $stmtu->close();
            $msg = "<div class='alert alert-success'>Pesan disable berhasil diupdate!</div>";
        } else {
            $stmti = $conn->prepare("INSERT INTO notif_khusus (pesan_disable, pemilik) VALUES (?, ?)");
            $stmti->bind_param('ss', $pesan_disable, $pemilik);
            $stmti->execute();
            $stmti->close();
            $msg = "<div class='alert alert-success'>Pesan disable berhasil disimpan!</div>";
        }
        $stmt->close();

        $history_file = "../notifbot/data/history-$ceknama.json";
        $history = [];
        if (file_exists($history_file)) {
            $history = json_decode(file_get_contents($history_file), true);
        }
        if (!is_array($history)) $history = [];
        $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Menyimpan pesan disable manual";
        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    } else {
        echo "<div class='alert alert-warning'>Pesan disable dan pemilik tidak boleh kosong!</div>";
    }
}

// Proses simpan pesan aktif manual
if (isset($_POST['simpan_pesan_aktif_manual'])) {
    $pesan_aktif_manual = trim($_POST['pesan_aktif_manual'] ?? '');
    $pemilik = isset($ceknama) ? $ceknama : (isset($_POST['pemilik']) ? trim($_POST['pemilik']) : '');
    if ($pesan_aktif_manual !== '' && $pemilik !== '') {
        $stmt = $conn->prepare("SELECT id FROM notif_khusus WHERE pemilik = ?");
        $stmt->bind_param('s', $pemilik);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $stmtu = $conn->prepare("UPDATE notif_khusus SET pesan_aktif_manual = ? WHERE pemilik = ?");
            $stmtu->bind_param('ss', $pesan_aktif_manual, $pemilik);
            $stmtu->execute();
            $stmtu->close();
            $msg = "<div class='alert alert-success'>Pesan aktif manual berhasil diupdate!</div>";
        } else {
            $stmti = $conn->prepare("INSERT INTO notif_khusus (pesan_aktif_manual, pemilik) VALUES (?, ?)");
            $stmti->bind_param('ss', $pesan_aktif_manual, $pemilik);
            $stmti->execute();
            $stmti->close();
            $msg = "<div class='alert alert-success'>Pesan aktif manual berhasil disimpan!</div>";
        }
        $stmt->close();

        $history_file = "../notifbot/data/history-$ceknama.json";
        $history = [];
        if (file_exists($history_file)) {
            $history = json_decode(file_get_contents($history_file), true);
        }
        if (!is_array($history)) $history = [];
        $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Menyimpan pesan aktif manual";
        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    } else {
        echo "<div class='alert alert-warning'>Pesan aktif manual dan pemilik tidak boleh kosong!</div>";
    }
}

// Proses simpan pesan remainder manual
if (isset($_POST['simpan_pesan_remainder_manual'])) {
    $pesan_remainder_manual = trim($_POST['pesan_remainder_manual'] ?? '');
    $pemilik = isset($ceknama) ? $ceknama : (isset($_POST['pemilik']) ? trim($_POST['pemilik']) : '');
    if ($pesan_remainder_manual !== '' && $pemilik !== '') {
        $stmt = $conn->prepare("SELECT id FROM notif_khusus WHERE pemilik = ?");
        $stmt->bind_param('s', $pemilik);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $stmtu = $conn->prepare("UPDATE notif_khusus SET pesan_remainder_manual = ? WHERE pemilik = ?");
            $stmtu->bind_param('ss', $pesan_remainder_manual, $pemilik);
            $stmtu->execute();
            $stmtu->close();
            $msg = "<div class='alert alert-success'>Pesan remainder manual berhasil diupdate!</div>";
        } else {
            $stmti = $conn->prepare("INSERT INTO notif_khusus (pesan_remainder_manual, pemilik) VALUES (?, ?)");
            $stmti->bind_param('ss', $pesan_remainder_manual, $pemilik);
            $stmti->execute();
            $stmti->close();
            $msg = "<div class='alert alert-success'>Pesan remainder manual berhasil disimpan!</div>";
        }
        $stmt->close();

        $history_file = "../notifbot/data/history-$ceknama.json";
        $history = [];
        if (file_exists($history_file)) {
            $history = json_decode(file_get_contents($history_file), true);
        }
        if (!is_array($history)) $history = [];
        $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Menyimpan pesan remainder manual";
        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    } else {
        echo "<div class='alert alert-warning'>Pesan remainder manual dan pemilik tidak boleh kosong!</div>";
    }
}

// Proses simpan pesan dismantle manual
if (isset($_POST['simpan_pesan_dismantle_manual'])) {
    $pesan_dismantle_manual = trim($_POST['pesan_dismantle_manual'] ?? '');
    $pemilik = isset($ceknama) ? $ceknama : (isset($_POST['pemilik']) ? trim($_POST['pemilik']) : '');
    if ($pesan_dismantle_manual !== '' && $pemilik !== '') {
        $stmt = $conn->prepare("SELECT id FROM notif_khusus WHERE pemilik = ?");
        $stmt->bind_param('s', $pemilik);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $stmtu = $conn->prepare("UPDATE notif_khusus SET pesan_dismantle_manual = ? WHERE pemilik = ?");
            $stmtu->bind_param('ss', $pesan_dismantle_manual, $pemilik);
            $stmtu->execute();
            $stmtu->close();
            $msg = "<div class='alert alert-success'>Pesan dismantle manual berhasil diupdate!</div>";
        } else {
            $stmti = $conn->prepare("INSERT INTO notif_khusus (pesan_dismantle_manual, pemilik) VALUES (?, ?)");
            $stmti->bind_param('ss', $pesan_dismantle_manual, $pemilik);
            $stmti->execute();
            $stmti->close();
            $msg = "<div class='alert alert-success'>Pesan dismantle manual berhasil disimpan!</div>";
        }
        $stmt->close();

        $history_file = "../notifbot/data/history-$ceknama.json";
        $history = [];
        if (file_exists($history_file)) {
            $history = json_decode(file_get_contents($history_file), true);
        }
        if (!is_array($history)) $history = [];
        $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Menyimpan pesan dismantle manual";
        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    } else {
        echo "<div class='alert alert-warning'>Pesan dismantle manual dan pemilik tidak boleh kosong!</div>";
    }
}

// Proses simpan pesan pembayaran berhasil (dikirim callback gateway pembayaran)
if (isset($_POST['simpan_pesan_pembayaran_berhasil'])) {
    $pesan_pembayaran_berhasil = trim($_POST['pesan_pembayaran_berhasil'] ?? '');
    $pemilik = isset($ceknama) ? $ceknama : (isset($_POST['pemilik']) ? trim($_POST['pemilik']) : '');
    if ($pesan_pembayaran_berhasil !== '' && $pemilik !== '') {
        $stmt = $conn->prepare("SELECT id FROM notif_khusus WHERE pemilik = ?");
        $stmt->bind_param('s', $pemilik);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $stmtu = $conn->prepare("UPDATE notif_khusus SET pesan_pembayaran_berhasil = ? WHERE pemilik = ?");
            $stmtu->bind_param('ss', $pesan_pembayaran_berhasil, $pemilik);
            $stmtu->execute();
            $stmtu->close();
            $msg = "<div class='alert alert-success'>Pesan pembayaran berhasil diupdate!</div>";
        } else {
            $stmti = $conn->prepare("INSERT INTO notif_khusus (pesan_pembayaran_berhasil, pemilik) VALUES (?, ?)");
            $stmti->bind_param('ss', $pesan_pembayaran_berhasil, $pemilik);
            $stmti->execute();
            $stmti->close();
            $msg = "<div class='alert alert-success'>Pesan pembayaran berhasil disimpan!</div>";
        }
        $stmt->close();

        $history_file = "../notifbot/data/history-$ceknama.json";
        $history = [];
        if (file_exists($history_file)) {
            $history = json_decode(file_get_contents($history_file), true);
        }
        if (!is_array($history)) $history = [];
        $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Menyimpan pesan pembayaran berhasil";
        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    } else {
        echo "<div class='alert alert-warning'>Pesan pembayaran berhasil dan pemilik tidak boleh kosong!</div>";
    }
}




   


// Ambil pesan_ketentuan, pesan_disable, pesan_aktif_manual sekali saja di awal
$pesan_ketentuan_val = '';
$pesan_disable_val = '';
$pesan_aktif_manual_val = '';
// Tambahan: pesan_remainder_manual dan pesan_dismantle_manual
$pesan_remainder_manual_val = '';
$pesan_dismantle_manual_val = '';
// Tambahan: pesan_registrasi, pesan_expired, pesan_reminder
$pesan_registrasi_val = '';
$pesan_expired_val = '';
$pesan_reminder_val = '';
// Tambahan: pesan_gangguan dan pesan_gangguan_selesai (template broadcast.php mode info/selesai)
$pesan_gangguan_val = '';
$pesan_gangguan_selesai_val = '';
// Tambahan: pesan_pembayaran_berhasil (dikirim callback gateway pembayaran)
$pesan_pembayaran_berhasil_val = '';
if (!empty($ceknama)) {
    $stmt = $conn->prepare("SELECT pesan_registrasi, pesan_expired, pesan_reminder, pesan_ketentuan, pesan_disable, pesan_aktif_manual, pesan_remainder_manual, pesan_dismantle_manual, pesan_gangguan, pesan_gangguan_selesai, pesan_pembayaran_berhasil FROM notif_khusus WHERE pemilik = ?");
    $stmt->bind_param('s', $ceknama);
    $stmt->execute();
    $stmt->bind_result($pesan_registrasi_val, $pesan_expired_val, $pesan_reminder_val, $pesan_ketentuan_val, $pesan_disable_val, $pesan_aktif_manual_val, $pesan_remainder_manual_val, $pesan_dismantle_manual_val, $pesan_gangguan_val, $pesan_gangguan_selesai_val, $pesan_pembayaran_berhasil_val);
    $stmt->fetch();
    $stmt->close();
}
if (trim((string)$pesan_pembayaran_berhasil_val) === '') {
    require_once __DIR__ . '/notifbot/notif_template_helper.php';
    $pesan_pembayaran_berhasil_val = notifTemplateDefaultPembayaranBerhasil();
}


// Direktori penyimpanan
$directory = "notifbot/data";

// Buat folder jika belum ada
if (!is_dir($directory)) {
    mkdir($directory, 0777, true);
    // echo "Folder '$directory' berhasil dibuat.<br>";
}

// Daftar file yang ingin dibuat
$files = [
    "$directory/reminder-$username.json",
    "$directory/history-$username.json",
    "$directory/invoice_generator-$username.json"
];

// Cek dan buat masing-masing file jika belum ada
foreach ($files as $file) {
    if (!file_exists($file)) {
        file_put_contents($file, json_encode([]));
        // echo "File '$file' berhasil dibuat.<br>";
    } else {
        // echo "File '$file' sudah ada.<br>";
    }
}

// --- Prabayar Grace Period Setting (Pisah File) ---
$prabayar_grace_period = 2;
$grace_period_config_file = "$directory/prabayar_grace_period-$username.json";
if (file_exists($grace_period_config_file)) {
    $grace_data = json_decode(file_get_contents($grace_period_config_file), true);
    if (is_array($grace_data) && isset($grace_data['prabayar_grace_period'])) {
        $prabayar_grace_period = (int)$grace_data['prabayar_grace_period'];
    }
}
if (isset($_POST['simpan_prabayar_grace_period'])) {
    $prabayar_grace_period_post = isset($_POST['prabayar_grace_period']) ? (int)$_POST['prabayar_grace_period'] : 2;
    $prabayar_grace_period_post = max(0, min(30, $prabayar_grace_period_post));
    $grace_data = [
        'prabayar_grace_period' => $prabayar_grace_period_post,
        'updated_at' => date('Y-m-d H:i:s')
    ];
    file_put_contents($grace_period_config_file, json_encode($grace_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $prabayar_grace_period = $prabayar_grace_period_post;
    echo "<div class='alert alert-success'>Waktu tunggu prabayar berhasil disimpan: <b>{$prabayar_grace_period} hari</b></div>";
}






$folder = 'notifbot/notifphp/';

// Pengaturan invoice generator penagihan
$invoice_config_file = "$directory/invoice_generator-$username.json";
$invoice_generator_enabled = 0;
$invoice_generate_schedule = 'monthly_range';
$invoice_generate_start_day = 25;
$invoice_generate_hour = 7;
$invoice_generate_minute = 0;
// H-N hari sebelum jatuh tempo utk auto-generate invoice PENAGIHAN pelanggan
// Monthversary & Rolling (jatuh tempo per-pelanggan, tidak ikut start_day global).
$invoice_generate_days_before_due = 2;

if (file_exists($invoice_config_file)) {
    $invoice_cfg = json_decode(file_get_contents($invoice_config_file), true);
    if (is_array($invoice_cfg)) {
        $invoice_generator_enabled = !empty($invoice_cfg['enabled']) ? 1 : 0;
        $invoice_generate_schedule = (($invoice_cfg['schedule'] ?? 'monthly_range') === 'daily') ? 'daily' : 'monthly_range';
        $invoice_generate_start_day = isset($invoice_cfg['start_day']) ? (int)$invoice_cfg['start_day'] : 25;
        $invoice_generate_hour = isset($invoice_cfg['hour']) ? (int)$invoice_cfg['hour'] : 7;
        $invoice_generate_minute = isset($invoice_cfg['minute']) ? (int)$invoice_cfg['minute'] : 0;
        $invoice_generate_days_before_due = isset($invoice_cfg['days_before_due']) ? (int)$invoice_cfg['days_before_due'] : 2;
    }
}

$invoice_generate_start_day = max(1, min(31, $invoice_generate_start_day));
$invoice_generate_hour = max(0, min(23, $invoice_generate_hour));
$invoice_generate_minute = max(0, min(59, $invoice_generate_minute));
$invoice_generate_days_before_due = max(0, min(30, $invoice_generate_days_before_due));

if (isset($_POST['simpan_invoice_generator'])) {
    $invoice_generator_enabled = isset($_POST['invoice_generator_enabled']) ? 1 : 0;
    if (isset($_POST['invoice_generate_daily'])) {
        $invoice_generate_schedule = 'daily';
    } else {
        $invoice_generate_schedule = (($_POST['invoice_generate_schedule'] ?? 'monthly_range') === 'daily') ? 'daily' : 'monthly_range';
    }
    $invoice_generate_start_day = isset($_POST['invoice_generate_start_day']) ? (int)$_POST['invoice_generate_start_day'] : 25;
    $invoice_generate_hour = isset($_POST['invoice_generate_hour']) ? (int)$_POST['invoice_generate_hour'] : 7;
    $invoice_generate_minute = isset($_POST['invoice_generate_minute']) ? (int)$_POST['invoice_generate_minute'] : 0;
    $invoice_generate_days_before_due = isset($_POST['invoice_generate_days_before_due']) ? (int)$_POST['invoice_generate_days_before_due'] : 2;

    $invoice_generate_start_day = max(1, min(31, $invoice_generate_start_day));
    $invoice_generate_hour = max(0, min(23, $invoice_generate_hour));
    $invoice_generate_minute = max(0, min(59, $invoice_generate_minute));
    $invoice_generate_days_before_due = max(0, min(30, $invoice_generate_days_before_due));

    $invoice_payload = [
        'enabled' => $invoice_generator_enabled,
        'schedule' => $invoice_generate_schedule,
        'start_day' => $invoice_generate_start_day,
        'hour' => $invoice_generate_hour,
        'minute' => $invoice_generate_minute,
        'days_before_due' => $invoice_generate_days_before_due,
        'updated_at' => date('Y-m-d H:i:s')
    ];

    if (file_put_contents($invoice_config_file, json_encode($invoice_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false) {
        $cfg_runtime_file = 'config.json';
        $cfg_runtime = file_exists($cfg_runtime_file) ? json_decode(file_get_contents($cfg_runtime_file), true) : [];
        $domain_runtime = trim($cfg_runtime['URL'] ?? '');

        if ($domain_runtime !== '' && stripos($domain_runtime, 'http://') !== 0 && stripos($domain_runtime, 'https://') !== 0) {
            $domain_runtime = 'https://' . ltrim($domain_runtime, '/');
        }

        if ($domain_runtime !== '') {
            // Cron Fixed Due Date -- lihat komentar sama di paymentset.php. Jadwal
            // generate ("Mulai Tanggal"/scheduleMode) tetap dilakukan DI DALAM
            // invoice_generator_penagihan_*.php sendiri.
            $invoice_cron_job = "$invoice_generate_minute $invoice_generate_hour * * * curl $domain_runtime/crm/billing/notifbot/notifphp/invoice_generator_penagihan_$username.php > /dev/null 2>&1";

            // Cron Rolling & Monthversary -- SELALU harian, terlepas dari Mode
            // Jadwal/Mulai Tanggal (itu punya Fixed Due Date). Tiap pelanggan
            // digenerate sendiri N hari sebelum jatuh tempo masing-masing.
            $invoice_cron_job_rm = "$invoice_generate_minute $invoice_generate_hour * * * curl $domain_runtime/crm/billing/notifbot/notifphp/invoice_generator_rolling_monthversary_$username.php > /dev/null 2>&1";

            $current_crontab_wwwdata = shell_exec("crontab -u www-data -l 2>&1");
            if (!is_string($current_crontab_wwwdata) || stripos($current_crontab_wwwdata, 'no crontab for') !== false) {
                $current_crontab_wwwdata = "";
            }

            $lines = preg_split('/\r\n|\r|\n/', $current_crontab_wwwdata);
            $filtered = [];
            foreach ($lines as $line) {
                if (strpos((string)$line, "invoice_generator_penagihan_$username.php") !== false) {
                    continue;
                }
                if (strpos((string)$line, "invoice_generator_rolling_monthversary_$username.php") !== false) {
                    continue;
                }
                if (trim((string)$line) === '') {
                    continue;
                }
                $filtered[] = $line;
            }

            $base_crontab_wwwdata = implode("\n", $filtered);
            $base_crontab_wwwdata = $base_crontab_wwwdata !== '' ? $base_crontab_wwwdata . "\n" : '';

            // Selalu hapus dulu cron invoice generator yang lama, lalu buat ulang jika masih aktif.
            file_put_contents('/tmp/crontab_wwwdata_invoice.txt', $base_crontab_wwwdata);
            shell_exec('crontab -u www-data /tmp/crontab_wwwdata_invoice.txt');
            @unlink('/tmp/crontab_wwwdata_invoice.txt');

            if ($invoice_generator_enabled) {
                $new_crontab_wwwdata = $base_crontab_wwwdata . $invoice_cron_job . "\n" . $invoice_cron_job_rm . "\n";
                file_put_contents('/tmp/crontab_wwwdata_invoice.txt', $new_crontab_wwwdata);
                shell_exec('crontab -u www-data /tmp/crontab_wwwdata_invoice.txt');
                @unlink('/tmp/crontab_wwwdata_invoice.txt');
            } else {
                $new_crontab_wwwdata = $base_crontab_wwwdata;
            }

            echo "<div class='alert alert-success'>Pengaturan Invoice Generator berhasil disimpan dan sinkron ke crontab www-data.</div>";
        } else {
            echo "<div class='alert alert-warning'>Pengaturan Invoice Generator tersimpan, tetapi URL domain belum valid sehingga crontab www-data belum diupdate.</div>";
        }
    } else {
        echo "<div class='alert alert-danger'>Gagal menyimpan pengaturan Invoice Generator.</div>";
    }

    $history_file = "../notifbot/data/history-$ceknama.json";
    $history = [];
    if (file_exists($history_file)) {
        $history = json_decode(file_get_contents($history_file), true);
    }
    if (!is_array($history)) $history = [];
    $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Menyimpan pengaturan invoice generator penagihan";
    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
}

// Generate/perbarui file cron per-akun dari template notifbot/notifphp/*.php --
// logic aslinya (sebelum diekstrak) ada di sini, sekarang jadi satu sumber
// bersama notifCronFilesGenerate() (notifbot/notif_cron_files_helper.php) yang
// juga dipanggil otomatis saat login billing (index.php).
require_once __DIR__ . '/notifbot/notif_cron_files_helper.php';
notifCronFilesGenerate($username, !empty($asistant_name) ? $asistant_name : $username);





?>

<style>
.container {
    font-size: 0.8rem !important;
    padding: 5px !important;
    margin: 5px auto !important;
    line-height: 1.1 !important;
}

.container * {
    font-size: 0.8rem !important;
    margin: 2px !important;
    padding: 2px !important;
    line-height: 1.1 !important;
    box-sizing: border-box;
}

.notification-preview {
    background: #2f3438; /* dark gray */
    color: #f1f3f5 !important;
    max-width: 70% !important;
    width: 70% !important;
    margin-left: auto !important;
    margin-right: auto !important;
    word-break: break-word;
}

/* tombol & input proporsional */
.container button,
.container input,
.container select {
    font-size: 0.8rem !important;
    padding: 3px 6px !important;
    border-radius: 4px !important;
    height: auto !important;
}

/* textarea tema hitam hijau */
.container textarea {
    overflow: hidden;
    resize: vertical;
    min-height: 40px;
    background-color: #000 !important;
    color: #00ff00 !important;
    border: 1px solid #333 !important;
    font-family: monospace;
    border-radius: 4px;
    padding: 4px 6px;
}

/* preformat hitam hijau */
.container pre {
    background-color: #000 !important;   /* latar hitam */
    color: #00ff00 !important;           /* teks hijau */
    font-family: monospace;
    font-size: 0.7rem !important;        /* kecilkan */
    border: 1px solid #333;
    border-radius: 4px;
    padding: 5px 8px;
    white-space: pre-wrap;               /* agar teks panjang tetap wrap */
    word-wrap: break-word;
}

/* bot checkbox group */
.bot-checkbox-group {
    max-height: 220px;
    overflow-y: auto;
    background: var(--white, #fff);
    border-color: var(--border-color, #e2e8f0) !important;
    color: var(--text-primary, #1e293b);
}

body.app-theme-dark .bot-checkbox-group {
    background-color: #0f172a !important;
    border-color: rgba(59, 130, 246, 0.2) !important;
    color: #e2e8f0 !important;
}

body.app-theme-dark .bot-checkbox-group .form-check-label {
    color: #e2e8f0 !important;
}
</style>

        <!-- Bootstrap 5 CSS for Tabs Support -->
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">

        <style>
            /* Standardize card appearance: consistent shadow + rounding */
            .card {
                box-shadow: 0 .5rem 1rem rgba(0,0,0,0.12) !important;
                border-radius: .5rem !important;
                transition: box-shadow .15s ease, transform .08s ease;
            }
            .card:hover { box-shadow: 0 .75rem 1.5rem rgba(0,0,0,0.18) !important; }
            /* Make headers match rounding when used with bg classes */
            .card > .card-header { border-top-left-radius: .5rem; border-top-right-radius: .5rem; }
            
            /* Tab Navigation Styling - STRONG OVERRIDE FOR SOFT-UI-DASHBOARD CONFLICT */
            #notificationTabs {
                border-bottom: 2px solid #2563eb !important;
                gap: 0.5rem !important;
                display: flex !important;
                flex-wrap: wrap !important;
                list-style: none !important;
                padding-left: 0 !important;
                margin-bottom: 1rem !important;
            }
            #notificationTabs .nav-item {
                margin-bottom: -1px !important;
                display: block !important;
            }
            #notificationTabs .nav-link {
                display: block !important;
                color: #495057 !important;
                border: 1px solid transparent !important;
                border-bottom: 3px solid transparent !important;
                border-radius: 0 !important;
                padding: 0.75rem 1.5rem !important;
                font-weight: 500 !important;
                transition: all .25s ease !important;
                cursor: pointer !important;
                background-color: transparent !important;
                background-image: none !important;
                margin: 0 !important;
            }
            #notificationTabs .nav-link:hover {
                color: #2563eb !important;
                background-color: rgba(37, 99, 235, 0.05) !important;
                border-bottom-color: #2563eb !important;
            }
            #notificationTabs .nav-link.active {
                color: #2563eb !important;
                background-color: transparent !important;
                border-bottom: 3px solid #2563eb !important;
                font-weight: 600 !important;
            }
            
            /* Tab Content Container - STRONG OVERRIDE */
            #notificationTabsContent {
                border: none !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            
            /* Tab Pane - CRITICAL FIX FOR DISPLAY */
            #notificationTabsContent .tab-pane {
                display: none !important;
                opacity: 0 !important;
                padding: 0 !important;
                margin: 0 !important;
                animation: fadeIn 0.3s ease-in-out !important;
            }
            
            #notificationTabsContent .tab-pane.show,
            #notificationTabsContent .tab-pane.active {
                display: block !important;
                opacity: 1 !important;
            }
            
            @keyframes fadeIn {
                0% { opacity: 0; transform: translateY(5px); }
                100% { opacity: 1; transform: translateY(0); }
            }
            
            /* Mobile responsive tabs */
            @media (max-width: 768px) {
                #notificationTabs {
                    flex-wrap: wrap !important;
                    gap: 0 !important;
                }
                #notificationTabs .nav-link {
                    padding: 0.75rem 1rem !important;
                    font-size: 0.9rem !important;
                    flex: 1 1 auto !important;
                    text-align: center !important;
                }
            }
        </style>

        <div class="container mt-4">
       
            <div class="row">
                <div class="col">
                    <h3 class="mb-4"><i class="fas fa-cog me-2"></i>Manajemen Notifikasi</h3>
                    
                    <!-- Tab Navigation -->
                    <ul class="nav nav-tabs mb-4" id="notificationTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="pesan-tab" data-bs-toggle="tab" data-bs-target="#pesan-content" type="button" role="tab" aria-controls="pesan-content" aria-selected="true">
                                <i class="fas fa-envelope me-2"></i>Pesan Notifikasi
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="penerima-tab" data-bs-toggle="tab" data-bs-target="#penerima-content" type="button" role="tab" aria-controls="penerima-content" aria-selected="false">
                                <i class="fas fa-users me-2"></i>Penerima Notifikasi
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="advanced-tab" data-bs-toggle="tab" data-bs-target="#advanced-content" type="button" role="tab" aria-controls="advanced-content" aria-selected="false">
                                <i class="fas fa-sliders-h me-2"></i>Pengaturan Lanjutan
                            </button>
                        </li>
                    </ul>

                    <!-- Tab Content -->
                    <div class="tab-content" id="notificationTabsContent">

                        <!-- Tab 1: Pesan Notifikasi -->
                        <div class="tab-pane fade show active" id="pesan-content" role="tabpanel" aria-labelledby="pesan-tab">

                <?php
                require_once __DIR__ . '/notifbot/notif_template_helper.php';

$URL=$config['URL'];
                // Lokasi & format template SEKARANG satu acuan tunggal di
                // notif_template_helper.php (dipakai bersama semua cron notif juga) --
                // sumbernya tabel notif_khusus (database), BUKAN lagi file
                // notifdata/*.txt -- lihat file itu utk detail kenapa direfactor begini.

                // Handle per-modal AJAX saves (each modal saves only its section)
                if (isset($_POST['mode']) && in_array($_POST['mode'], ['save_registrasi','save_expired','save_remainder'])) {
                    $mode = $_POST['mode'];

                    if ($mode === 'save_registrasi' && isset($_POST['pesan_registrasi'])) {
                        notifTemplateSaveSections($ceknama, trim($_POST['pesan_registrasi']), null, null);
                    } elseif ($mode === 'save_expired' && isset($_POST['pesan_notif'])) {
                        notifTemplateSaveSections($ceknama, null, trim($_POST['pesan_notif']), null);
                    } elseif ($mode === 'save_remainder' && isset($_POST['pesan_remainder'])) {
                        notifTemplateSaveSections($ceknama, null, null, trim($_POST['pesan_remainder']));
                    }

                    // If AJAX (XHR), return simple OK and exit; otherwise continue to render page
                    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                        echo "OK";
                        exit;
                    }
                }

                if (($_POST['mode'] ?? '') === 'editnotif') {
                    // Ambil input teks
                    $pesan_registrasi = trim($_POST['pesan_registrasi']);
                    $pesan_notif = trim($_POST['pesan_notif']);
                    $pesan_remainder = trim($_POST['pesan_remainder']);

                    // Simpan ke database
                    notifTemplateSaveSections($ceknama, $pesan_registrasi, $pesan_notif, $pesan_remainder);
                }

                // Ambil isi 3 section dari database (auto-dibuat dgn template default
                // kalau belum ada -- lihat notifTemplateGetContent()).
                $isi = notifTemplateGetContent($ceknama);
                $registrasi_raw = notifTemplateExtractSection($isi, 'REGISTRASI');
                $expired_raw = notifTemplateExtractSection($isi, 'EXPIRED');
                $remainder_raw = notifTemplateExtractSection($isi, 'REMAINDER');

                // Format untuk ditampilkan (string literal "\n")
                $registrasi_display = str_replace("\n", "\\n", $registrasi_raw);
                $expired_display    = str_replace("\n", "\\n", $expired_raw);
                $remainder_display  = str_replace("\n", "\\n", $remainder_raw);

                // Format biasa (newline jadi <br>)
                $registrasi_normal = nl2br(htmlspecialchars($registrasi_raw, ENT_QUOTES, 'UTF-8'));
                $expired_normal    = nl2br(htmlspecialchars($expired_raw, ENT_QUOTES, 'UTF-8'));
                $remainder_normal  = nl2br(htmlspecialchars($remainder_raw, ENT_QUOTES, 'UTF-8'));

                // Nilai filter reminder saat ini -- dibaca dari tabel `reminder_settings`
                // yang sama dgn Payment Setting -> Konfigurasi Fixed Due Date. Default TRUE
                // (perilaku sama spt selama ini) kalau belum pernah disentuh.
                $reminderFilterSettings = reminderSettingsGet($conn, $ceknama);
                $filter_sudah_bayar_reminder_val = !empty($reminderFilterSettings['filter_sudah_bayar_reminder']);
                $filter_skip_notif_hari_ini_val = !empty($reminderFilterSettings['filter_skip_notif_hari_ini']);
                ?>


                <!-- Form 1. Notifikasi Registrasi Pelanggan Baru -->
                <div class="card shadow-lg mt-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-user-plus me-2"></i>Notifikasi Registrasi Pelanggan Baru</h5>
                    </div>
                    <div class="card-body">
                        <form method="post" action="">
                            <div class="mb-3">
                                <label for="pesan_registrasi" class="form-label fw-bold">Pesan Registrasi</label>
                                <textarea class="form-control" id="pesan_registrasi" name="pesan_registrasi" rows="6" placeholder="Masukkan pesan yang akan dikirim saat pelanggan baru mendaftar..." required><?php echo htmlspecialchars($registrasi_raw); ?></textarea>
                                <small class="text-muted d-block mt-1"><i class="fas fa-database me-1"></i>Disimpan di database: tabel <code>notif_khusus</code>, kolom <code>pesan_registrasi</code></small>
                                <div class="variable-info bg-light p-3 rounded mt-3">
                                    <strong><i class="fas fa-code me-2"></i>Variabel yang tersedia:</strong>
                                    <div class="row mt-2">
                                        <div class="col-md-6">
                                            <ul class="mb-0" style="font-size:0.9em;">
                                                <li><code>$namapel</code> = Nama pelanggan (penerima)</li>
                                                <li><code>$Nama</code> = Nama pendaftar</li>
                                                <li><code>$Alamat</code> = Alamat pemasangan</li>
                                                <li><code>$nohp</code> = Nomor WhatsApp</li>
                                                <li><code>$Email</code> = Email pendaftar</li>
                                            </ul>
                                        </div>
                                        <div class="col-md-6">
                                            <ul class="mb-0" style="font-size:0.9em;">
                                                <li><code>$tanggalpasang</code> = Tanggal permintaan pasang</li>
                                                <li><code>$Paket</code> = Paket langganan</li>
                                                <li><code>$Sales</code> = Nama sales</li>
                                                <li><code>$project</code> = Nama product</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary" name="simpan_pesan_registrasi">
                                <i class="fas fa-save me-2"></i>Simpan Pesan Registrasi
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Form 2. Notifikasi Expired -->
                <div class="card shadow-lg mt-4">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="mb-0"><i class="fas fa-calendar-times me-2"></i>Notifikasi Expired (Tagihan Belum Dibayar)</h5>
                    </div>
                    <div class="card-body">
                        <form method="post" action="">
                            <div class="mb-3">
                                <label for="pesan_expired" class="form-label fw-bold">Pesan Expired</label>
                                <textarea class="form-control" id="pesan_expired" name="pesan_expired" rows="6" placeholder="Masukkan pesan yang akan dikirim saat tagihan pelanggan belum dibayar pada tanggal jatuh tempo..." required><?php echo htmlspecialchars($expired_raw); ?></textarea>
                                <small class="text-muted d-block mt-1"><i class="fas fa-database me-1"></i>Disimpan di database: tabel <code>notif_khusus</code>, kolom <code>pesan_expired</code></small>
                                <div class="variable-info bg-light p-3 rounded mt-3">
                                    <strong><i class="fas fa-code me-2"></i>Variabel yang tersedia:</strong>
                                    <div class="row mt-2">
                                        <div class="col-md-6">
                                            <ul class="mb-0" style="font-size:0.9em;">
                                                <li><code>$nama</code> = Nama pelanggan</li>
                                                <li><code>$idpel</code> = ID pelanggan</li>
                                                <li><code>$paket</code> = Nama paket layanan</li>
                                                <li><code>$nowa</code> = Nomor WhatsApp pelanggan</li>
                                            </ul>
                                        </div>
                                        <div class="col-md-6">
                                            <ul class="mb-0" style="font-size:0.9em;">
                                                <li><code>$email</code> = Email pelanggan</li>
                                                <li><code>$tanggalbayar</code> = Tanggal jatuh tempo</li>
                                                <li><code>$harga</code> = Jumlah tagihan</li>
                                                <li><code>$periode</code> = Periode langganan</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-warning text-dark" name="simpan_pesan_expired">
                                <i class="fas fa-save me-2"></i>Simpan Pesan Expired
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Form 3. Notifikasi Reminder Pembayaran -->
                <div class="card shadow-lg mt-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="fas fa-bell me-2"></i>Notifikasi Reminder Pembayaran</h5>
                    </div>
                    <div class="card-body">
                        <form method="post" action="">
                            <div class="mb-3">
                                <label for="pesan_reminder" class="form-label fw-bold">Pesan Reminder</label>
                                <textarea class="form-control" id="pesan_reminder" name="pesan_reminder" rows="6" placeholder="Masukkan pesan pengingat yang akan dikirim kepada pelanggan sebelum jatuh tempo..." required><?php echo htmlspecialchars($remainder_raw); ?></textarea>
                                <small class="text-muted d-block mt-1"><i class="fas fa-database me-1"></i>Disimpan di database: tabel <code>notif_khusus</code>, kolom <code>pesan_reminder</code></small>
                                <div class="variable-info bg-light p-3 rounded mt-3">
                                    <strong><i class="fas fa-code me-2"></i>Variabel yang tersedia:</strong>
                                    <div class="row mt-2">
                                        <div class="col-md-6">
                                            <ul class="mb-0" style="font-size:0.9em;">
                                                <li><code>$nama</code> = Nama pelanggan</li>
                                                <li><code>$idpel</code> = ID pelanggan</li>
                                                <li><code>$paket</code> = Nama paket layanan</li>
                                                <li><code>$nowa</code> = Nomor WhatsApp pelanggan</li>
                                            </ul>
                                        </div>
                                        <div class="col-md-6">
                                            <ul class="mb-0" style="font-size:0.9em;">
                                                <li><code>$email</code> = Email pelanggan</li>
                                                <li><code>$tanggalbayar</code> = Tanggal jatuh tempo</li>
                                                <li><code>$harga</code> = Jumlah tagihan</li>
                                                <li><code>$periode</code> = Periode langganan</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-info text-white" name="simpan_pesan_reminder">
                                <i class="fas fa-save me-2"></i>Simpan Pesan Reminder
                            </button>
                        </form>

                        <hr class="my-4">

                        <form method="post" action="">
                            <div class="mb-2">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="filter_sudah_bayar_reminder" name="filter_sudah_bayar_reminder" value="1" <?php echo $filter_sudah_bayar_reminder_val ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="filter_sudah_bayar_reminder">Filter: Skip pelanggan yang sudah bayar</label>
                                </div>
                                <small class="text-muted d-block">ON (default): reminder tidak dikirim ke pelanggan yang statusnya sudah lunas periode ini. OFF: reminder tetap dikirim ke semua pelanggan tanpa peduli status bayar (mis. utk testing).</small>
                            </div>
                            <div class="mb-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="filter_skip_notif_hari_ini" name="filter_skip_notif_hari_ini" value="1" <?php echo $filter_skip_notif_hari_ini_val ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="filter_skip_notif_hari_ini">Filter: Skip pelanggan yang sudah dinotif hari ini</label>
                                </div>
                                <small class="text-muted d-block">ON (default): pelanggan yang sudah menerima reminder hari ini tidak dikirimi lagi (cegah kirim dobel). OFF: reminder bisa terkirim berkali-kali ke pelanggan yang sama dalam sehari -- gunakan dgn hati-hati (mis. utk testing).</small>
                            </div>
                            <small class="text-muted d-block mb-2"><i class="fas fa-database me-1"></i>Disimpan di database: tabel <code>reminder_settings</code> (setting yang sama dipakai di Payment Setting -> Konfigurasi Fixed Due Date)</small>
                            <button type="submit" class="btn btn-info text-white" name="simpan_filter_reminder">
                                <i class="fas fa-save me-2"></i>Simpan Filter Reminder
                            </button>
                        </form>
                    </div>
                </div>
                             <!-- Form Ketentuan Baru -->
            <div class="card shadow-lg mt-4">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0">Simpan Pesan Ketentuan berlangganan pendaftar baru ( pesan ini akan masuk saat pelanggan mendaftar )</h5>
                </div>
                <div class="card-body">
                    <form method="post" action="">
                        <div class="mb-3">
                            <label for="pesan_ketentuan" class="form-label">Pesan Ketentuan</label>
                            <textarea class="form-control" id="pesan_ketentuan" name="pesan_ketentuan" rows="4" required><?= htmlspecialchars($pesan_ketentuan_val) ?></textarea>
                            <small class="text-muted d-block mt-1"><i class="fas fa-database me-1"></i>Disimpan di database: tabel <code>notif_khusus</code>, kolom <code>pesan_ketentuan</code></small>
                            <!-- Info variabel yang tersedia -->
                            <div class="variable-info bg-light p-3 rounded mt-2">
                                <strong>Variabel yang tersedia:</strong>
                                <ul class="mb-0" style="font-size:0.95em;">
                                    <li><code>$namapel</code> = Nama pelanggan (penerima)</li>
                                    <li><code>$Nama</code> = Nama pendaftar</li>
                                    <li><code>$Alamat</code> = Alamat pemasangan</li>
                                    <li><code>$nohp</code> = Nomor WhatsApp</li>
                                    <li><code>$Email</code> = Email pendaftar</li>
                                    <li><code>$tanggalpasang</code> = Tanggal permintaan pasang</li>
                                    <li><code>$Paket</code> = Paket langganan</li>
                                    <li><code>$Sales</code> = Nama sales</li>
                                    <li><code>$project</code> = Nama product</li>
                                     
                                </ul>
                            </div>
                        </div>
                      <button type="submit" class="btn btn-primary" name="simpan_ketentuan">Simpan</button>
                    </form>
                </div>
            </div>


            <!-- Form Pesan Disable Manual -->
            <div class="card shadow-lg mt-4">
                <div class="card-header bg-warning text-dark">
                    <h5 class="mb-0">Simpan Pesan DISABLE Manual (pesan ini akan dikirim saat pelanggan dinonaktifkan manual)</h5>
                </div>
                <div class="card-body">
                    <form method="post" action="">
                        <div class="mb-3">
                                <label for="pesan_disable" class="form-label">Pesan Disable Manual</label>
                                <textarea class="form-control" id="pesan_disable" name="pesan_disable" rows="4" required><?= htmlspecialchars($pesan_disable_val ?? '') ?></textarea>
                                <small class="text-muted d-block mt-1"><i class="fas fa-database me-1"></i>Disimpan di database: tabel <code>notif_khusus</code>, kolom <code>pesan_disable</code></small>
                                <div class="variable-info bg-light p-3 rounded mt-2">
                                    <b>Variabel yang tersedia untuk pesan disable:</b><br>
                                 
                                    <ul class="mb-0" style="font-size:0.9em;">
                                        <li><b>$nama</b> = Nama pelanggan</li>
                                        <li><b>$idpel</b> = ID pelanggan</li>
                                        <li><b>$nowa</b> = Nomor WhatsApp pelanggan</li>
                                        <li><b>$paket</b> = Nama paket layanan</li>
                                        <li><b>$domain</b> = Domain sistem</li>
                                         <li><b>$periode</b> = Periode langganan</li>
                                    </ul>
                                </div>
                        </div>
                        <button type="submit" class="btn btn-warning" name="simpan_pesan_disable">Simpan Pesan Disable</button>
                    </form>
                </div>
            </div>

            <!-- Form Pesan Gangguan (dipakai broadcast.php mode "Notif Gangguan") -->
            <div class="card shadow-lg mt-4">
                <div class="card-header bg-danger text-white">
                    <h5 class="mb-0">Simpan Pesan GANGGUAN (dipakai saat broadcast ODP/ODC di menu Broadcast, jenis info "Notif Gangguan")</h5>
                </div>
                <div class="card-body">
                    <form method="post" action="">
                        <div class="mb-3">
                                <label for="pesan_gangguan" class="form-label">Pesan Gangguan</label>
                                <textarea class="form-control" id="pesan_gangguan" name="pesan_gangguan" rows="4" required><?= htmlspecialchars($pesan_gangguan_val ?? '') ?></textarea>
                                <small class="text-muted d-block mt-1"><i class="fas fa-database me-1"></i>Disimpan di database: tabel <code>notif_khusus</code>, kolom <code>pesan_gangguan</code></small>
                                <div class="variable-info bg-light p-3 rounded mt-2">
                                    <b>Variabel yang tersedia untuk pesan gangguan:</b><br>
                                    <ul class="mb-0" style="font-size:0.9em;">
                                        <li><b>$nama</b> = Nama pelanggan</li>
                                        <li><b>$idpel</b> = ID pelanggan</li>
                                        <li><b>$nowa</b> = Nomor WhatsApp pelanggan</li>
                                        <li><b>$paket</b> = Nama paket layanan</li>
                                        <li><b>$alamat</b> = Alamat pelanggan</li>
                                        <li><b>$area</b> = Area pelanggan</li>
                                        <li><b>$odp</b> = Kode ODP/ODC target broadcast</li>
                                    </ul>
                                </div>
                        </div>
                        <button type="submit" class="btn btn-danger" name="simpan_pesan_gangguan">Simpan Pesan Gangguan</button>
                    </form>
                </div>
            </div>

            <!-- Form Pesan Selesai Gangguan (dipakai broadcast.php mode "Notif Selesai Gangguan") -->
            <div class="card shadow-lg mt-4">
                <div class="card-header bg-info text-dark">
                    <h5 class="mb-0">Simpan Pesan SELESAI GANGGUAN (dipakai saat broadcast ODP/ODC di menu Broadcast, jenis info "Notif Selesai Gangguan")</h5>
                </div>
                <div class="card-body">
                    <form method="post" action="">
                        <div class="mb-3">
                                <label for="pesan_gangguan_selesai" class="form-label">Pesan Selesai Gangguan</label>
                                <textarea class="form-control" id="pesan_gangguan_selesai" name="pesan_gangguan_selesai" rows="4" required><?= htmlspecialchars($pesan_gangguan_selesai_val ?? '') ?></textarea>
                                <small class="text-muted d-block mt-1"><i class="fas fa-database me-1"></i>Disimpan di database: tabel <code>notif_khusus</code>, kolom <code>pesan_gangguan_selesai</code></small>
                                <div class="variable-info bg-light p-3 rounded mt-2">
                                    <b>Variabel yang tersedia untuk pesan selesai gangguan:</b><br>
                                    <ul class="mb-0" style="font-size:0.9em;">
                                        <li><b>$nama</b> = Nama pelanggan</li>
                                        <li><b>$idpel</b> = ID pelanggan</li>
                                        <li><b>$nowa</b> = Nomor WhatsApp pelanggan</li>
                                        <li><b>$paket</b> = Nama paket layanan</li>
                                        <li><b>$alamat</b> = Alamat pelanggan</li>
                                        <li><b>$area</b> = Area pelanggan</li>
                                        <li><b>$odp</b> = Kode ODP/ODC target broadcast</li>
                                    </ul>
                                </div>
                        </div>
                        <button type="submit" class="btn btn-info" name="simpan_pesan_gangguan_selesai">Simpan Pesan Selesai Gangguan</button>
                    </form>
                </div>
            </div>

            <!-- Form Pesan Aktif Manual -->
            <div class="card shadow-lg mt-4">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0">Simpan Pesan AKTIF Manual (pesan ini akan dikirim saat pelanggan diaktifkan manual)</h5>
                </div>
                <div class="card-body">
                    <form method="post" action="">
                        <div class="mb-3">
                                <label for="pesan_aktif_manual" class="form-label">Pesan Aktif Manual</label>
                                <textarea class="form-control" id="pesan_aktif_manual" name="pesan_aktif_manual" rows="4" required><?= htmlspecialchars($pesan_aktif_manual_val ?? '') ?></textarea>
                                <small class="text-muted d-block mt-1"><i class="fas fa-database me-1"></i>Disimpan di database: tabel <code>notif_khusus</code>, kolom <code>pesan_aktif_manual</code></small>
                                <div class="variable-info bg-light p-3 rounded mt-2">
                                    <b>Variabel yang tersedia untuk pesan aktif manual:</b><br>
                                    
                                    <ul class="mb-0" style="font-size:0.9em;">
                                        <li><b>$nama</b> = Nama pelanggan</li>
                                        <li><b>$idpel</b> = ID pelanggan</li>
                                        <li><b>$nowa</b> = Nomor WhatsApp pelanggan</li>
                                        <li><b>$paket</b> = Nama paket layanan</li>
                                        <li><b>$email</b> = Email pelanggan</li>
                                        <li><b>$tanggalbayar</b> = Tanggal pembayaran</li>
                                         <li><b>$periode</b> = Periode langganan</li>
                                    </ul>
                                </div>
                        </div>
                        <button type="submit" class="btn btn-success" name="simpan_pesan_aktif_manual">Simpan Pesan Aktif Manual</button>
                    </form>
                </div>
            </div>

                <!-- Form Pesan Remainder Manual -->
                <div class="card shadow-lg mt-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0">Simpan Pesan REMAINDER Manual (pesan ini akan dikirim saat reminder manual)</h5>
                    </div>
                    <div class="card-body">
                        <form method="post" action="">
                            <div class="mb-3">
                                <label for="pesan_remainder_manual" class="form-label">Pesan Remainder Manual</label>
                                <textarea class="form-control" id="pesan_remainder_manual" name="pesan_remainder_manual" rows="4" required><?= htmlspecialchars($pesan_remainder_manual_val ?? '') ?></textarea>
                                    <small class="text-muted d-block mt-1"><i class="fas fa-database me-1"></i>Disimpan di database: tabel <code>notif_khusus</code>, kolom <code>pesan_remainder_manual</code></small>
                                    <div class="variable-info bg-light p-3 rounded mt-2">
                                        <b>Variabel yang tersedia untuk pesan remainder manual:</b><br>
                             
                                        <ul class="mb-0" style="font-size:0.9em;">
                                            <li><b>$nama</b> = Nama pelanggan</li>
                                            <li><b>$idpel</b> = ID pelanggan</li>
                                            <li><b>$nowa</b> = Nomor WhatsApp pelanggan</li>
                                            <li><b>$paket</b> = Nama paket layanan</li>
                                            <li><b>$email</b> = Email pelanggan</li>
                                        <li><b>$periode</b> = Periode langganan</li>
                                        </ul>
                                    </div>
                            </div>
                            <button type="submit" class="btn btn-info" name="simpan_pesan_remainder_manual">Simpan Pesan Remainder Manual</button>
                        </form>
                    </div>
                </div>

                <!-- Form Pesan Dismantle Manual -->
                <div class="card shadow-lg mt-4">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0">Simpan Pesan DISMANTLE Manual (pesan ini akan dikirim saat pelanggan dibongkar/dismantle manual)</h5>
                    </div>
                    <div class="card-body">
                        <form method="post" action="">
                            <div class="mb-3">
                                <label for="pesan_dismantle_manual" class="form-label">Pesan Dismantle Manual</label>
                                <textarea class="form-control" id="pesan_dismantle_manual" name="pesan_dismantle_manual" rows="4" required><?= htmlspecialchars($pesan_dismantle_manual_val ?? '') ?></textarea>
                                    <small class="text-muted d-block mt-1"><i class="fas fa-database me-1"></i>Disimpan di database: tabel <code>notif_khusus</code>, kolom <code>pesan_dismantle_manual</code></small>
                                    <div class="variable-info bg-light p-3 rounded mt-2">
                                        <b>Variabel yang tersedia untuk pesan dismantle manual:</b><br>
                                       
                                        <ul class="mb-0" style="font-size:0.9em;">
                                            <li><b>$nama</b> = Nama pelanggan</li>
                                            <li><b>$idpel</b> = ID pelanggan</li>
                                            <li><b>$tanggal_dismantle</b> = Tanggal layanan dihentikan</li>
                                        </ul>
                                    </div>
                            </div>
                            <button type="submit" class="btn btn-danger" name="simpan_pesan_dismantle_manual">Simpan Pesan Dismantle Manual</button>
                        </form>
                    </div>
                </div>

                <!-- Form Pesan Pembayaran Berhasil -->
                <div class="card shadow-lg mt-4">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">Simpan Pesan Pembayaran Berhasil (pesan ini dikirim otomatis oleh SEMUA payment gateway saat pembayaran berhasil dikonfirmasi)</h5>
                    </div>
                    <div class="card-body">
                        <form method="post" action="">
                            <div class="mb-3">
                                <label for="pesan_pembayaran_berhasil" class="form-label">Pesan Pembayaran Berhasil</label>
                                <textarea class="form-control" id="pesan_pembayaran_berhasil" name="pesan_pembayaran_berhasil" rows="10" required><?= htmlspecialchars($pesan_pembayaran_berhasil_val ?? '') ?></textarea>
                                    <small class="text-muted d-block mt-1"><i class="fas fa-database me-1"></i>Disimpan di database: tabel <code>notif_khusus</code>, kolom <code>pesan_pembayaran_berhasil</code>. Kosongkan/reset ke teks default di atas kalau belum pernah diubah.</small>
                                    <div class="variable-info bg-light p-3 rounded mt-2">
                                        <b>Variabel yang tersedia untuk pesan pembayaran berhasil:</b><br>
                                        <ul class="mb-0" style="font-size:0.9em;">
                                            <li><b>$NAMAPELANGGAN</b> = Nama pelanggan</li>
                                            <li><b>$USERNAMETRANASAKSI</b> = ID pelanggan</li>
                                            <li><b>$PAKETPELANGGAN</b> = Paket langganan</li>
                                            <li><b>$WHATSAPPELANGGAN</b> = Nomor WhatsApp pelanggan</li>
                                            <li><b>$EMAILPELANGGAN</b> = Email pelanggan</li>
                                            <li><b>$ALAMATPELANGGAN</b> = Alamat pelanggan</li>
                                            <li><b>$periode</b> = Periode penggunaan yang dibayar</li>
                                            <li><b>$tanggalbayar</b> = Tanggal pembayaran</li>
                                            <li><b>$cekstatus</b> = Status pembayaran dari gateway</li>
                                            <li><b>$amount</b> = Nominal pembayaran</li>
                                            <li><b>$invoiceref</b> = Nomor referensi/invoice</li>
                                            <li><b>$payment_method</b> = Metode pembayaran</li>
                                            <li><b>$payment_method_code</b> = Kode metode pembayaran</li>
                                            <li><b>$linkBukti</b> = Baris link download bukti pembayaran</li>
                                            <li><b>$BRANDPELANGGAN</b> = Nama brand/server pelanggan</li>
                                        </ul>
                                    </div>
                            </div>
                            <button type="submit" class="btn btn-success" name="simpan_pesan_pembayaran_berhasil">Simpan Pesan Pembayaran Berhasil</button>
                        </form>
                    </div>
                </div>

                        </div><!-- End Pesan Tab -->

                        <!-- Tab 3: Penerima Notifikasi -->
                        <div class="tab-pane fade" id="penerima-content" role="tabpanel" aria-labelledby="penerima-tab">

            <!-- Card Form Nomor Penerima Pesan -->
            <div class="card shadow-lg mt-4">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0">Input Nomor Penerima Pesan ( saat ada pendaftaran )</h5>
                </div>
                <div class="card-body">
                    <form method="post" action="">
                        <div class="mb-3">
                            <label for="nomor_penerima" class="form-label">Nomor Penerima</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="nomor_penerima" name="nomor_penerima" placeholder="Masukkan nomor (cth: 628xxxxxx atau ID grup)" required value="<?= htmlspecialchars($penerima_val) ?>">
                                <select class="form-select" id="tipe_penerima" name="tipe_penerima">
                                    <option value="pribadi" <?= $tipe_penerima_val=='pribadi'?'selected':'' ?>>Pribadi (Nomor WhatsApp)</option>
                                    <option value="grup" <?= $tipe_penerima_val=='grup'?'selected':'' ?>>Grup (ID Grup WhatsApp)</option>
                                </select>
                            </div>
                            <div class="mt-2">
                                <label class="form-label">Pilih BOT untuk notifikasi ini</label>
                                <?php renderBotCheckboxGroup('bot_penerima', 'grp_bot_penerima', $bot_options, $checked_bot_penerima); ?>
                            </div>
                            <div class="form-text">Pilih <b>Pribadi</b> untuk nomor WhatsApp (awali dengan 62), <b>Grup</b> untuk ID grup WhatsApp.</div>
                        </div>
                        <button type="submit" class="btn btn-primary" name="simpan_nomor_penerima" >Simpan Nomor Penerima</button>
                    </form>
                </div>
            </div>
                
            <!-- Card Form Nomor Penerima Notif Server -->
            <div class="card shadow-lg mt-4">
                <div class="card-header bg-danger text-white">
                    <h5 class="mb-0">Input Nomor Penerima Notif Server ( saat server tidak konek )</h5>
                </div>
                <div class="card-body">
                    <form method="post" action="">
                        <div class="mb-3">
                            <label for="nomor_penerima_server" class="form-label">Nomor Penerima Server</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="nomor_penerima_server" name="nomor_penerima_server" placeholder="Masukkan nomor (cth: 628xxxxxx atau ID grup)" required value="<?= htmlspecialchars($penerima_server_val) ?>">
                                <select class="form-select" id="tipe_penerima_server" name="tipe_penerima_server">
                                    <option value="pribadi" <?= $tipe_penerima_server_val=='pribadi'?'selected':'' ?>>Pribadi (Nomor WhatsApp)</option>
                                    <option value="grup" <?= $tipe_penerima_server_val=='grup'?'selected':'' ?>>Grup (ID Grup WhatsApp)</option>
                                </select>
                            </div>
                            <div class="mt-2">
                                <label class="form-label">Pilih BOT untuk notifikasi ini</label>
                                <?php renderBotCheckboxGroup('bot_penerima_server', 'grp_bot_penerima_server', $bot_options, $checked_bot_penerima_server); ?>
                            </div>
                            <div class="form-text">Pilih <b>Pribadi</b> untuk nomor WhatsApp (awali dengan 62), <b>Grup</b> untuk ID grup WhatsApp.</div>
                        </div>
                        <button type="submit" class="btn btn-danger" name="simpan_penerima_server">Simpan Nomor Penerima Server</button>
                    </form>
                </div>
            </div>
                
            <!-- Card Form Nomor Penerima Notif Live Chat -->
            <div class="card shadow-lg mt-4">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0">Input Nomor Penerima Notif Live Chat</h5>
                </div>
                <div class="card-body">
                    <form method="post" action="">
                        <div class="mb-3">
                            <label for="nomor_penerima_livechat" class="form-label">Nomor Penerima Live Chat</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="nomor_penerima_livechat" name="nomor_penerima_livechat" placeholder="Masukkan nomor (cth: 628xxxxxx atau ID grup)" required value="<?= htmlspecialchars($penerima_livechat_val) ?>">
                                <select class="form-select" id="tipe_penerima_livechat" name="tipe_penerima_livechat">
                                    <option value="pribadi" <?= $tipe_penerima_livechat_val=='pribadi'?'selected':'' ?>>Pribadi (Nomor WhatsApp)</option>
                                    <option value="grup" <?= $tipe_penerima_livechat_val=='grup'?'selected':'' ?>>Grup (ID Grup WhatsApp)</option>
                                </select>
                            </div>
                            <div class="mt-2">
                                <label class="form-label">Pilih BOT untuk notifikasi ini</label>
                                <?php renderBotCheckboxGroup('bot_penerima_livechat', 'grp_bot_penerima_livechat', $bot_options, $checked_bot_penerima_livechat); ?>
                            </div>
                            <div class="form-text">Pilih <b>Pribadi</b> untuk nomor WhatsApp (awali dengan 62), <b>Grup</b> untuk ID grup WhatsApp.</div>
                        </div>
                        <button type="submit" class="btn btn-info" name="simpan_penerima_livechat">Simpan Nomor Penerima Live Chat</button>
                    </form>
                </div>
            </div>
                
            <!-- Card Form Nomor Penerima Notif System -->
            <div class="card shadow-lg mt-4">
                <div class="card-header bg-warning text-white">
                    <h5 class="mb-0">Input Nomor Penerima Notif System</h5>
                </div>
                <div class="card-body">
                    <form method="post" action="">
                        <div class="mb-3">
                            <label for="nomor_penerima_system_notif" class="form-label">Nomor Penerima System Notif</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="nomor_penerima_system_notif" name="nomor_penerima_system_notif" placeholder="Masukkan nomor (cth: 628xxxxxx atau ID grup)" required value="<?= htmlspecialchars($penerima_system_notif_val) ?>">
                                <select class="form-select" id="tipe_penerima_system_notif" name="tipe_penerima_system_notif">
                                    <option value="pribadi" <?= $tipe_penerima_system_notif_val=='pribadi'?'selected':'' ?>>Pribadi (Nomor WhatsApp)</option>
                                    <option value="grup" <?= $tipe_penerima_system_notif_val=='grup'?'selected':'' ?>>Grup (ID Grup WhatsApp)</option>
                                </select>
                            </div>
                            <div class="mt-2">
                                <label class="form-label">Pilih BOT untuk notifikasi ini</label>
                                <?php renderBotCheckboxGroup('bot_penerima_system_notif', 'grp_bot_penerima_system_notif', $bot_options, $checked_bot_penerima_system_notif); ?>
                            </div>
                            <div class="form-text">Pilih <b>Pribadi</b> untuk nomor WhatsApp (awali dengan 62), <b>Grup</b> untuk ID grup WhatsApp.</div>
                        </div>
                        <button type="submit" class="btn btn-warning" name="simpan_penerima_system_notif">Simpan Nomor Penerima System Notif</button>
                    </form>
                </div>
            </div>

            <!-- Card Form Nomor Penerima Notif ODP Semua LOS -->
            <div class="card shadow-lg mt-4">
                <div class="card-header bg-danger text-white">
                    <h5 class="mb-0">Input Nomor Penerima Notif ODP Semua Pelanggan LOS</h5>
                </div>
                <div class="card-body">
                    <form method="post" action="">
                        <div class="mb-3">
                            <label for="nomor_penerima_odp_los" class="form-label">Nomor Penerima ODP LOS</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="nomor_penerima_odp_los" name="nomor_penerima_odp_los" placeholder="Masukkan nomor (cth: 628xxxxxx atau ID grup)" required value="<?= htmlspecialchars($penerima_odp_los_val) ?>">
                                <select class="form-select" id="tipe_penerima_odp_los" name="tipe_penerima_odp_los">
                                    <option value="pribadi" <?= $tipe_penerima_odp_los_val=='pribadi'?'selected':'' ?>>Pribadi (Nomor WhatsApp)</option>
                                    <option value="grup" <?= $tipe_penerima_odp_los_val=='grup'?'selected':'' ?>>Grup (ID Grup WhatsApp)</option>
                                </select>
                            </div>
                            <div class="mt-2">
                                <label class="form-label">Pilih BOT untuk notifikasi ini</label>
                                <?php renderBotCheckboxGroup('bot_penerima_odp_los', 'grp_bot_penerima_odp_los', $bot_options, $checked_bot_penerima_odp_los); ?>
                            </div>
                            <div class="form-text">Notif akan dikirim jika pada satu ODP semua pelanggan sedang LOS (berdasarkan data serverload).</div>
                        </div>
                        <button type="submit" class="btn btn-danger" name="simpan_penerima_odp_los">Simpan Nomor Penerima ODP LOS</button>
                    </form>
                </div>
            </div>

            <!-- Card Interval Cek ODP Semua LOS -->
            <div class="card shadow-lg mt-4">
                <div class="card-header bg-secondary text-white">
                    <h5 class="mb-0">Waktu Cek Notif ODP Semua Pelanggan LOS</h5>
                </div>
                <div class="card-body">
                    <form method="post" action="">
                        <div class="row g-2 align-items-end">
                            <div class="col-md-4">
                                <label for="odp_los_interval_value" class="form-label">Cek setiap</label>
                                <input type="number" min="1" class="form-control" id="odp_los_interval_value" name="odp_los_interval_value" value="<?= (int)$odp_los_interval_value ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label for="odp_los_interval_unit" class="form-label">Satuan</label>
                                <select class="form-select" id="odp_los_interval_unit" name="odp_los_interval_unit">
                                    <option value="menit" <?= $odp_los_interval_unit === 'menit' ? 'selected' : '' ?>>Menit</option>
                                    <option value="jam" <?= $odp_los_interval_unit === 'jam' ? 'selected' : '' ?>>Jam</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <button type="submit" class="btn btn-secondary w-100" name="simpan_interval_odp_los">Simpan Interval</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Card Form Nomor Penerima Notif Manual Active -->
            <div class="card shadow-lg mt-4">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0">Input Nomor Penerima Notif Manual Active (Owner)</h5>
                </div>
                <div class="card-body">
                    <form method="post" action="">
                        <div class="mb-3">
                            <label for="nomor_penerima_manual_active" class="form-label">Nomor Penerima Manual Active</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="nomor_penerima_manual_active" name="nomor_penerima_manual_active" placeholder="Masukkan nomor (cth: 628xxxxxx atau ID grup)" required value="<?= htmlspecialchars($penerima_manual_active_val) ?>">
                                <select class="form-select" id="tipe_penerima_manual_active" name="tipe_penerima_manual_active">
                                    <option value="pribadi" <?= $tipe_penerima_manual_active_val=='pribadi'?'selected':'' ?>>Pribadi (Nomor WhatsApp)</option>
                                    <option value="grup" <?= $tipe_penerima_manual_active_val=='grup'?'selected':'' ?>>Grup (ID Grup WhatsApp)</option>
                                </select>
                            </div>
                            <div class="form-text">Pilih <b>Pribadi</b> untuk nomor WhatsApp (awali dengan 62), <b>Grup</b> untuk ID grup WhatsApp.</div>
                            <div class="mt-2">
                                <label class="form-label">Pilih BOT untuk notifikasi ini</label>
                                <?php renderBotCheckboxGroup('bot_penerima_manual_active', 'grp_bot_penerima_manual_active', $bot_options, $checked_bot_penerima_manual_active); ?>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-success" name="simpan_penerima_manual_active">Simpan Nomor Penerima Manual Active</button>
                    </form>
                </div>
            </div>


            <!-- Card Form Nomor Penerima Notif Provisioning -->
            <div class="card shadow-lg mt-4">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0">Input Nomor Penerima Notif Provisioning (Owner)</h5>
                </div>
                <div class="card-body">
                    <form method="post" action="">
                        <div class="mb-3">
                            <label for="nomor_penerima_provisioning" class="form-label">Nomor Penerima Provisioning</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="nomor_penerima_provisioning" name="nomor_penerima_provisioning" placeholder="Masukkan nomor (cth: 628xxxxxx atau ID grup)" required value="<?= htmlspecialchars($penerima_provisioning_val) ?>">
                                <select class="form-select" id="tipe_penerima_provisioning" name="tipe_penerima_provisioning">
                                    <option value="pribadi" <?= $tipe_penerima_provisioning_val=='pribadi'?'selected':'' ?>>Pribadi (Nomor WhatsApp)</option>
                                    <option value="grup" <?= $tipe_penerima_provisioning_val=='grup'?'selected':'' ?>>Grup (ID Grup WhatsApp)</option>
                                </select>
                            </div>
                            <div class="form-text">Pilih <b>Pribadi</b> untuk nomor WhatsApp (awali dengan 62), <b>Grup</b> untuk ID grup WhatsApp. Notifikasi dikirim saat ada data provisioning baru masuk dari joblist.</div>
                            <div class="mt-2">
                                <label class="form-label">Pilih BOT untuk notifikasi ini</label>
                                <?php renderBotCheckboxGroup('bot_penerima_provisioning', 'grp_bot_penerima_provisioning', $bot_options, $checked_bot_penerima_provisioning); ?>
                            </div>
                            <!-- Tidak perlu badge BOT terpilih, hanya checkbox saja -->
                        </div>
                        <button type="submit" class="btn btn-info" name="simpan_penerima_provisioning">Simpan Nomor Penerima Provisioning</button>
                    </form>
                </div>
            </div>

                        </div><!-- End Penerima Tab -->

                        <!-- Tab 4: Pengaturan Lanjutan -->
                        <div class="tab-pane fade" id="advanced-content" role="tabpanel" aria-labelledby="advanced-tab">

            <!-- Card Pengaturan OTP Portal Pelanggan -->
            <div class="card shadow-lg mt-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">Pengaturan OTP Portal Pelanggan</h5>
                </div>
                <div class="card-body">
                    <form method="post" action="">
                        <div class="mb-3">
                            <label for="otp_portal_mode" class="form-label">Mode OTP Portal</label>
                            <select class="form-select" id="otp_portal_mode" name="otp_portal_mode">
                                <option value="bypass" <?= $otp_portal_mode === 'bypass' ? 'selected' : '' ?>>Bypass OTP (login langsung via nomor WA)</option>
                                <option value="otp" <?= $otp_portal_mode === 'otp' ? 'selected' : '' ?>>Aktifkan OTP (kirim OTP via bot)</option>
                            </select>
                            <div class="form-text">Pilih <b>bypass</b> untuk sementara tanpa kirim OTP, atau <b>otp</b> untuk kembali ke verifikasi OTP.</div>
                        </div>

                        <div class="mb-3">
                            <label for="otp_portal_waapi" class="form-label">URL WA API</label>
                            <input type="text" class="form-control" id="otp_portal_waapi" name="otp_portal_waapi" placeholder="Contoh: http://domain:3000" value="<?= htmlspecialchars($otp_portal_waapi) ?>">
                        </div>

                        <div class="mb-3">
                            <label for="otp_portal_namebot" class="form-label">Nama Bot (username basic auth)</label>
                            <input type="text" class="form-control" id="otp_portal_namebot" name="otp_portal_namebot" value="<?= htmlspecialchars($otp_portal_namebot) ?>">
                        </div>

                        <div class="mb-3">
                            <label for="otp_portal_password" class="form-label">Password Bot</label>
                            <input type="text" class="form-control" id="otp_portal_password" name="otp_portal_password" value="<?= htmlspecialchars($otp_portal_password) ?>">
                        </div>

                        <div class="mb-3">
                            <label for="otp_portal_template" class="form-label">Template Pesan OTP</label>
                            <textarea class="form-control" id="otp_portal_template" name="otp_portal_template" rows="4"><?= htmlspecialchars($otp_portal_template) ?></textarea>
                            <div class="form-text">Gunakan <b>{otp}</b> sebagai placeholder kode OTP.</div>
                        </div>

                        <button type="submit" class="btn btn-primary" name="simpan_pengaturan_otp_portal">Simpan Pengaturan OTP Portal</button>
                    </form>
                </div>
            </div>

            <!-- Card Pengaturan Salam Dinamis -->
            <div class="card shadow-lg mt-4">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0">Pengaturan Salam Dinamis Notifikasi</h5>
                </div>
                <div class="card-body">
                    <form method="post" action="">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="dynamic_greeting_enabled" name="dynamic_greeting_enabled" value="1" <?= $dynamic_greeting_enabled === '1' ? 'checked' : '' ?> style="width: 3em; height: 1.5em; cursor: pointer;">
                            <label class="form-check-label" for="dynamic_greeting_enabled" style="cursor: pointer; user-select: none;">
                                <strong>Aktifkan salam pembuka dinamis</strong>
                            </label>
                        </div>
                        <div class="form-text mb-3">
                            Jika aktif, semua notifikasi yang memakai helper <code>prependDynamicGreeting()</code> akan diawali salam acak untuk membantu mengurangi deteksi spam WhatsApp.
                        </div>
                        <div class="mb-3">
                            <label for="dynamic_greeting_list" class="form-label"><strong>Daftar Salam Acak (Bisa Diedit)</strong></label>
                            <textarea class="form-control" id="dynamic_greeting_list" name="dynamic_greeting_list" rows="8" placeholder="Satu salam per baris"><?= htmlspecialchars($dynamic_greeting_list_text) ?></textarea>
                            <div class="form-text">Isi satu salam per baris. Daftar ini akan dipakai untuk semua fungsi <code>prependDynamicGreeting()</code> sesuai owner saat ini.</div>
                        </div>
                        <button type="submit" class="btn btn-info" name="simpan_pengaturan_salam_dinamis">Simpan Pengaturan Salam Dinamis</button>
                    </form>
                </div>
            </div>

            <!-- Card "Trun on" (Manajemen Cron Job) sudah dipindah ke halaman terpisah:
                 System Setting (system_setting.php), menu di grup sidebar "Akun & Pengaturan". -->
            <div class="card shadow-lg mt-4">
                <div class="card-body">
                    <a href="system_setting.php" class="btn btn-outline-dark">
                        <i class="fas fa-cogs"></i> Buka System Setting (Manajemen Cron Job)
                    </a>
                </div>
            </div>

                        </div><!-- End Advanced Tab -->









                    </div><!-- End Tab Content -->

            </div>
            </div>
                <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
                <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
                <script>
                    // ===== MANUAL TAB HANDLER - OVERRIDE SOFT-UI-DASHBOARD CONFLICT =====
                    document.addEventListener('DOMContentLoaded', function() {
                        // Get all tab buttons and panes
                        const tabButtons = document.querySelectorAll('#notificationTabs .nav-link');
                        const tabPanes = document.querySelectorAll('#notificationTabsContent .tab-pane');
                        
                        // Add click handler to each tab button
                        tabButtons.forEach(function(button) {
                            button.addEventListener('click', function(e) {
                                e.preventDefault();
                                
                                // Get target pane ID from data-bs-target
                                const targetId = this.getAttribute('data-bs-target');
                                
                                // Remove active class from all buttons and panes
                                tabButtons.forEach(btn => {
                                    btn.classList.remove('active');
                                    btn.setAttribute('aria-selected', 'false');
                                });
                                
                                tabPanes.forEach(pane => {
                                    pane.classList.remove('show', 'active');
                                });
                                
                                // Add active class to clicked button
                                this.classList.add('active');
                                this.setAttribute('aria-selected', 'true');
                                
                                // Show target pane
                                const targetPane = document.querySelector(targetId);
                                if (targetPane) {
                                    targetPane.classList.add('show', 'active');
                                }
                            });
                        });
                    });
                    
                    function autoResizeTextarea(textarea) {
                        textarea.style.height = 'auto'; // reset height
                        textarea.style.overflow = 'hidden';
                        // Hitung jumlah baris isi
                        var lines = textarea.value.split('\n').length;
                        textarea.rows = Math.max(lines, 1);
                        textarea.style.height = textarea.scrollHeight + 'px'; // sesuaikan tinggi
                    }

                    // Ambil semua textarea (termasuk di modal)
                    document.querySelectorAll('textarea').forEach(function(textarea) {
                        autoResizeTextarea(textarea); // sesuaikan tinggi saat load

                        // Sesuaikan tinggi saat user mengetik, paste, atau value berubah
                        ['input','change','paste'].forEach(function(evt) {
                            textarea.addEventListener(evt, function() {
                                autoResizeTextarea(textarea);
                            });
                        });
                    });

                    // Trigger resize semua textarea saat window resize
                    window.addEventListener('resize', function() {
                        document.querySelectorAll('textarea').forEach(autoResizeTextarea);
                    });

                    // Trigger resize semua textarea saat halaman benar-benar selesai dimuat
                    window.addEventListener('load', function() {
                        document.querySelectorAll('textarea').forEach(autoResizeTextarea);
                    });

                    // Jika ada modal Bootstrap, auto-resize textarea saat modal dibuka
                    document.querySelectorAll('.modal').forEach(function(modal) {
                        modal.addEventListener('shown.bs.modal', function() {
                            modal.querySelectorAll('textarea').forEach(function(textarea) {
                                autoResizeTextarea(textarea);
                            });
                        });
                    });

                    // ===== BOT CHECKBOX GROUP: RANDOM vs SPESIFIK saling eksklusif =====
                    document.addEventListener('DOMContentLoaded', function () {
                        document.querySelectorAll('.bot-checkbox-group').forEach(function (group) {
                            var randomCb   = group.querySelector('.bot-random-toggle');
                            var specificCb = group.querySelectorAll('.bot-specific-checkbox');
                            if (!randomCb) return;

                            randomCb.addEventListener('change', function () {
                                if (this.checked) {
                                    specificCb.forEach(function (cb) { cb.checked = false; });
                                }
                            });

                            specificCb.forEach(function (cb) {
                                cb.addEventListener('change', function () {
                                    if (this.checked) randomCb.checked = false;
                                    var anyChecked = Array.prototype.some.call(specificCb, function (c) { return c.checked; });
                                    if (!anyChecked) randomCb.checked = true; // fallback otomatis ke RANDOM
                                });
                            });
                        });
                    });
                </script>
                <style>
                    /* FIXED Modal at 85% viewport height with proper layout */
                    .modal-centered-desktop.modal-fullscreen-85 .modal-dialog {
                        max-width: 90vw;
                        width: 90vw;
                        margin: 2vh auto;
                        height: 85vh;
                    }
                    .modal-centered-desktop.modal-fullscreen-85 .modal-content {
                        height: 100%;
                        display: flex;
                        flex-direction: column;
                        overflow: hidden;
                    }
                    
                    /* Modal header - fixed height */
                    .modal-centered-desktop.modal-fullscreen-85 .modal-header {
                        flex-shrink: 0;
                        padding: 1rem;
                        border-bottom: 1px solid #dee2e6;
                    }
                    
                    /* Modal footer - fixed height */
                    .modal-centered-desktop.modal-fullscreen-85 .modal-footer {
                        flex-shrink: 0;
                        padding: 1rem;
                        border-top: 1px solid #dee2e6;
                        background: #fff;
                    }
                    
                    /* Modal body - flexible height */
                    .modal-centered-desktop.modal-fullscreen-85 .modal-body {
                        flex: 1;
                        display: flex;
                        flex-direction: column;
                        padding: 1rem;
                        overflow: hidden;
                        gap: 1rem;
                    }

                    /* Textarea container - 60% of modal body */
                    .modal-centered-desktop.modal-fullscreen-85 .modal-body > .mb-3:first-of-type {
                        flex: 0 0 60%;
                        display: flex;
                        flex-direction: column;
                        overflow: hidden;
                    }
                    
                    /* Label - fixed height */
                    .modal-centered-desktop.modal-fullscreen-85 .modal-body > .mb-3:first-of-type .form-label {
                        flex-shrink: 0;
                        margin-bottom: 0.5rem;
                    }
                    
                    /* Textarea - fills remaining space */
                    .modal-centered-desktop.modal-fullscreen-85 .modal-body > .mb-3:first-of-type textarea.full-height {
                        flex: 1;
                        width: 100%;
                        resize: vertical;
                        overflow-y: auto;
                        border: 1px solid #ced4da;
                        border-radius: 0.375rem;
                        padding: 0.75rem;
                        font-family: monospace;
                        font-size: 0.875rem;
                        line-height: 1.4;
                        background-color: #000 !important;
                        color: #00ff00 !important;
                    }

                    /* Variable info area - 40% of modal body */
                    .modal-centered-desktop.modal-fullscreen-85 .modal-body > .variable-info {
                        flex: 0 0 40%;
                        overflow-y: auto;
                        background: #f8f9fa;
                        border-radius: 0.375rem;
                        padding: 1rem;
                    }
                </style>

                <!-- Modals -->
                <div class="modal fade modal-centered-desktop modal-fullscreen-85" id="modalRegistrasi" tabindex="-1" aria-labelledby="modalRegistrasiLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="post" class="modal-save-form">
                            <div class="modal-header">
                                <h5 class="modal-title" id="modalRegistrasiLabel">Edit Notifikasi Registrasi</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="form-label">Pesan Registrasi</label>
                                    <textarea id="ta_registrasi" name="pesan_registrasi" class="form-control full-height" rows="20"><?= htmlspecialchars(str_replace('\\n', "\n", $registrasi_display)) ?></textarea>
                                </div>
                                <div class="variable-info bg-light p-3 rounded mb-3">
                                    <h6 class="fw-bold">Variabel yang tersedia:</h6>
                                    <div class="row">
                                          <div class="col-md-6">
                                            <code>$passwordPPPOE</code> = Data PPPoE<br>
                                            <code>$customerID</code> = ID Pelanggan<br>
                                            <code>$customerName</code> = Nama Pelanggan<br>
                                            <code>$packages</code> = Paket Layanan<br>
                                            <code>$tanggalpasang</code> = Tanggal Pasang<br>
                                            <code>$whatsappedit</code> = Nomor WhatsApp<br>
                                              <code>$URL</code> = Default link ini
                                        </div>
                                      <div class="col-md-6">
                                            <code>$email</code> = Email<br>
                                            <code>$address</code> = Alamat<br>
                                            <code>$BRAND</code> = Server<br>
                                            <code>$odp</code> = ODP<br>
                                            <code>$area</code> = Area<br>
                                            <code>$coordinates</code> = Koordinat<br>
                                            <code>$sales</code> = Sales
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <input type="hidden" name="mode" value="save_registrasi">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                                <button type="submit" class="btn btn-primary">Simpan</button>
                            </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="modal fade modal-centered-desktop modal-fullscreen-85" id="modalExpired" tabindex="-1" aria-labelledby="modalExpiredLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="post" class="modal-save-form">
                            <div class="modal-header">
                                <h5 class="modal-title" id="modalExpiredLabel">Edit Notifikasi Expired</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="form-label">Pesan Expired</label>
                                    <textarea id="ta_expired" name="pesan_notif" class="form-control full-height" rows="20"><?= htmlspecialchars(str_replace('\\n', "\n", $expired_display)) ?></textarea>
                                </div>
                                <div class="variable-info bg-light p-3 rounded mb-3">
                                    <h6 class="fw-bold">Variabel yang tersedia:</h6>
                                    <div class="row">
                                       <div class="col-md-6">
                                            <code>$IDPEL</code> = ID Pelanggan<br>
                                            <code>$NAMA</code> = Nama Pelanggan<br>
                                            <code>$PAKET</code> = Paket Layanan<br>
                                            <code>$URL</code> = Default link ini
                                        </div>
                                        <div class="col-md-6">
                                            <code>$NOWA</code> = Nomor WhatsApp<br>
                                            <code>$EMAIL</code> = Email<br>
                                            <code>$ALAMAT</code> = Alamat<br>
                                            <code>$periode</code> = Periode Langganan<br>
                                            <code>$BRAND</code> = Server
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <input type="hidden" name="mode" value="save_expired">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                                <button type="submit" class="btn btn-primary">Simpan</button>
                            </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="modal fade modal-centered-desktop modal-fullscreen-85" id="modalReminder" tabindex="-1" aria-labelledby="modalReminderLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="post" class="modal-save-form">
                            <div class="modal-header">
                                <h5 class="modal-title" id="modalReminderLabel">Edit Notifikasi Reminder</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="form-label">Pesan Reminder</label>
                                    <textarea id="ta_remainder" name="pesan_remainder" class="form-control full-height" rows="20"><?= htmlspecialchars(str_replace('\\n', "\n", $remainder_display)) ?></textarea>
                                </div>
                                <div class="variable-info bg-light p-3 rounded mb-3">
                                    <h6 class="fw-bold">Variabel yang tersedia:</h6>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <code>$IDPEL</code> = ID Pelanggan<br>
                                            <code>$NAMA</code> = Nama Pelanggan<br>
                                            <code>$PAKET</code> = Paket Layanan<br>
                                            <code>$NOWA</code> = Nomor WhatsApp<br>
                                              <code>$URL</code> = Default link ini
                                        </div>
                                        <div class="col-md-6">
                                            <code>$EMAIL</code> = Email<br>
                                            <code>$ALAMAT</code> = Alamat<br>
                                            <code>$BRAND</code> = Server<br>
                                             <code>$periode</code> = Periode Langganan<br>
                                            <code>$jatuh_tempo</code> = Tanggal Tempo
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <input type="hidden" name="mode" value="save_remainder">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                                <button type="submit" class="btn btn-primary">Simpan</button>
                            </div>
                            </form>
                        </div>
                    </div>
                </div>

                <script>
                    // Per-modal save via form submit with AJAX; fallback to normal full POST if JS disabled
                    $(document).on('submit', '.modal-save-form', function(e){
                        e.preventDefault();
                        var $form = $(this);
                        var formData = $form.serializeArray();
                        var data = {};
                        formData.forEach(function(pair){ data[pair.name] = pair.value; });

                        // Include textarea values explicitly (they should already be in formData because textarea has name)
                        var mode = data.mode || '';

                        $.ajax({
                            url: window.location.href,
                            method: 'POST',
                            data: data,
                            headers: { 'X-Requested-With': 'XMLHttpRequest' }
                        }).done(function(resp){
                            // Update previews and hidden inputs according to mode
                            if (mode === 'save_registrasi') {
                                $('#preview_registrasi').text(data.pesan_registrasi || $('#ta_registrasi').val());
                                $('#hidden_pesan_registrasi').val(data.pesan_registrasi || $('#ta_registrasi').val());
                            }
                            if (mode === 'save_expired') {
                                $('#preview_expired').text(data.pesan_notif || $('#ta_expired').val());
                                $('#hidden_pesan_notif').val(data.pesan_notif || $('#ta_expired').val());
                            }
                            if (mode === 'save_remainder') {
                                $('#preview_remainder').text(data.pesan_remainder || $('#ta_remainder').val());
                                $('#hidden_pesan_remainder').val(data.pesan_remainder || $('#ta_remainder').val());
                            }
                            showToast('Sukses', 'Tersimpan');
                            // close modal using Bootstrap 5 API
                            try {
                                var modalEl = $form.closest('.modal')[0];
                                if (modalEl) {
                                    var bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
                                    bsModal.hide();
                                    // After hide animation, ensure any leftover backdrop is removed
                                    setTimeout(function(){
                                        try { $('.modal-backdrop').remove(); } catch(e) {}
                                        try { $('body').removeClass('modal-open'); } catch(e) {}
                                    }, 350);
                                }
                            } catch (err) {
                                console.warn('Could not close modal programmatically', err);
                                // also attempt cleanup as fallback
                                setTimeout(function(){
                                    try { $('.modal-backdrop').remove(); } catch(e) {}
                                    try { $('body').removeClass('modal-open'); } catch(e) {}
                                }, 350);
                            }
                        }).fail(function(xhr){
                            showToast('Gagal', 'Penyimpanan gagal');
                            console.error('save error', xhr && xhr.responseText);
                        });
                    });

                    // Simple modal initialization - no dynamic height adjustments
                    $(document).on('shown.bs.modal', '.modal-fullscreen-85', function(){
                        // Focus on textarea when modal opens
                        $(this).find('textarea.full-height').focus();
                    });
                </script>
                      
                    </div>







            <style>
                .log-container {
                    max-height: 300px;
                    overflow-y: auto;
                }
            </style>
            <style>
                /* Mobile-friendly stacked table: convert rows into card-like blocks on small screens */
                @media (max-width: 768px) {
                    .stackable-on-mobile .stackable thead { display: none; }
                    .stackable-on-mobile .stackable, .stackable-on-mobile .stackable tbody, .stackable-on-mobile .stackable tr, .stackable-on-mobile .stackable td {
                        display: block; width: 100%;
                    }
                    .stackable-on-mobile .stackable tr { margin-bottom: .75rem; border: 1px solid #e9ecef; border-radius: .375rem; padding: .5rem; background: #fff; }
                    .stackable-on-mobile .stackable td { padding: .375rem .5rem; border: none; }
                    /* show the label on its own line and stack content vertically */
                    .stackable-on-mobile .stackable td[data-label]:before {
                        content: attr(data-label) ":";
                        font-weight: 700;
                        display: block;
                        margin-bottom: .25rem;
                        color: #343a40;
                    }
                    /* ensure the description (penjelasan) is readable on mobile */
                    .stackable-on-mobile .stackable td .text-muted {
                        color: #495057 !important;
                        opacity: 1 !important;
                        font-size: .95rem;
                    }
                }
            </style>
                <style>
                    /* Cron toggle colors: secondary (off) and orange (on)
                       Use higher specificity and !important to override Bootstrap defaults */
                    input.form-check-input.cron-toggle,
                    .form-check-input.cron-toggle {
                        background-color: #6c757d !important; /* bootstrap secondary */
                        border-color: #6c757d !important;
                        background-image: none !important;
                        transition: background-color .15s ease, border-color .15s ease;
                    }

                    input.form-check-input.cron-toggle:checked,
                    .form-check-input.cron-toggle:checked {
                        background-color: #fd7e14 !important; /* bootstrap warning/orange */
                        border-color: #fd7e14 !important;
                        background-image: none !important;
                    }

                    input.form-check-input.cron-toggle:focus,
                    .form-check-input.cron-toggle:focus {
                        box-shadow: 0 0 0 .25rem rgba(253,126,20,0.18) !important;
                    }

                    /* Ensure the switch knob contrasts nicely */
                    input.form-check-input.cron-toggle::after,
                    .form-check-input.cron-toggle::after {
                        background-color: #fff !important;
                    }

                    /* Responsive spacing for the cron controls */
                    .cron-controls .cron-form { padding: .5rem 0; }
                </style>
            







        </div>









        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
        <!-- Toast container for AJAX feedback -->
        <div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1080;">
            <div id="ajaxToast" class="toast align-items-center text-bg-dark border-0" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="d-flex">
                    <div class="toast-body" id="ajaxToastBody">Pesan</div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
            </div>
        </div>

        <script>
            function showToast(title, message) {
                var $toast = $('#ajaxToast');
                $('#ajaxToastBody').text(message);
                var toast = bootstrap.Toast.getOrCreateInstance($toast[0]);
                toast.show();
            }
        </script>

        <script>
            // AJAX SUBMIT HANDLER untuk semua form - submit di background tanpa reload
            $(function() {
                
                // Track which button was clicked
                var $clickedButton = null;
                
                $(document).on('click', 'form button[type="submit"]', function() {
                    $clickedButton = $(this);
                    console.log('Button clicked:', $clickedButton.attr('name'));
                });
                
                // Event handler: mencegat semua form submit
                $(document).on('submit', 'form', function(e) {
                    var $form = $(this);

                    // Modal form dan cron form sudah punya handler sendiri
                    if ($form.hasClass('modal-save-form') || $form.hasClass('cron-form')) {
                        return true;
                    }
                    
                    // Debug
                    console.log('Form submitted');
                    
                    var formData = new FormData(this);
                    
                    // Tentukan action berdasarkan button yang di-click
                    var action = $form.data('action') || null;
                    
                    if (!action && $clickedButton) {
                        var buttonName = $clickedButton.attr('name');
                        console.log('Clicked button name:', buttonName);
                        
                        if (buttonName) {
                            // Map button name ke action
                            var actionMap = {
                                'simpan_pesan_registrasi': 'save_registrasi',
                                'simpan_pesan_expired': 'save_expired',
                                'simpan_pesan_reminder': 'save_reminder',
                                'simpan_filter_reminder': 'save_filter_reminder',
                                'simpan_ketentuan': 'save_ketentuan',
                                'simpan_pesan_disable': 'save_disable',
                                'simpan_pesan_aktif_manual': 'save_aktif_manual',
                                'simpan_pesan_remainder_manual': 'save_remainder_manual',
                                'simpan_pesan_dismantle_manual': 'save_dismantle_manual',
                                'simpan_pesan_gangguan': 'save_gangguan',
                                'simpan_pesan_gangguan_selesai': 'save_gangguan_selesai',
                                'simpan_nomor_penerima': 'save_nomor_penerima',
                                'simpan_penerima_server': 'save_penerima_server',
                                'simpan_penerima_livechat': 'save_penerima_livechat',
                                'simpan_penerima_system_notif': 'save_penerima_system_notif',
                                'simpan_penerima_odp_los': 'save_penerima_odp_los',
                                'simpan_penerima_manual_active': 'save_penerima_manual_active',
                                'simpan_penerima_provisioning': 'save_penerima_provisioning',
                                'simpan_pengaturan_salam_dinamis': 'save_salam_dinamis',
                                'simpan_interval_odp_los': 'save_interval_odp_los',
                                'simpan_pengaturan_otp_portal': 'save_otp_portal'
                            };
                            action = actionMap[buttonName] || buttonName;
                        }
                    }
                    
                    // Jika tidak ada action, lewatkan ke form submit biasa
                    if (!action) {
                        console.log('No action found, proceeding with normal submit');
                        return true;
                    }

                    // Hanya form dengan action-map yang dikirim via AJAX
                    e.preventDefault();
                    
                    console.log('Action to execute:', action);
                    
                    // Tambahkan action ke form data
                    formData.append('action', action);
                    
                    // Tampilkan loading state
                    var $submitButton = ($clickedButton && $clickedButton.length) ? $clickedButton : $form.find('button[type="submit"]').first();
                    var originalText = $submitButton.length ? $submitButton.text() : '';
                    if ($submitButton.length) {
                        $submitButton.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Menyimpan...');
                    }
                    
                    // Submit via AJAX
                    $.ajax({
                        url: 'notification_api.php',
                        type: 'POST',
                        data: formData,
                        processData: false,
                        contentType: false,
                        dataType: 'json'
                    }).done(function(response) {
                        console.log('AJAX response received:', response);
                        
                        if (response && response.success) {
                            showToast('Sukses', response.message);
                            
                            // Close modal jika ada
                            setTimeout(function() {
                                try {
                                    var modalEl = $form.closest('.modal')[0];
                                    if (modalEl) {
                                        var bsModal = bootstrap.Modal.getInstance(modalEl);
                                        if (bsModal) {
                                            bsModal.hide();
                                            setTimeout(function() {
                                                $('.modal-backdrop').remove();
                                                $('body').removeClass('modal-open');
                                            }, 350);
                                        }
                                    }
                                } catch(err) {
                                    console.error('Modal close error:', err);
                                }
                            }, 500);
                        } else {
                            var errorMsg = (response && response.message) ? response.message : 'Penyimpanan gagal';
                            showToast('Gagal', errorMsg);
                            console.error('API returned error:', response);
                        }
                    }).fail(function(xhr, status, error) {
                        console.error('AJAX failed:', {
                            status: xhr.status,
                            statusText: xhr.statusText,
                            responseText: xhr.responseText,
                            error: error
                        });
                        
                        var errorMsg = 'Penyimpanan gagal';
                        if (xhr.status === 0) {
                            errorMsg = 'Koneksi error atau blocked';
                        } else if (xhr.status === 404) {
                            errorMsg = 'File notification_api.php tidak ditemukan (404)';
                        } else if (xhr.status === 500) {
                            errorMsg = 'Server error - check server logs';
                        } else if (xhr.status === 403) {
                            errorMsg = 'Access forbidden (403)';
                        }
                        showToast('Gagal', errorMsg);
                    }).always(function() {
                        // Restore button state
                        if ($submitButton.length) {
                            $submitButton.prop('disabled', false).html(originalText);
                        }
                        $clickedButton = null;
                    });
                    
                    return false;
                });
                
                // Cron toggle handler
                $('.cron-toggle').on('change', function() {
                    var checkbox = $(this);
                    var form = checkbox.closest('form');
                    var isChecked = checkbox.is(':checked');
                    var action = isChecked ? 'add' : 'delete';
                    form.find('.action-field').val(action);

                    $.post(window.location.href, form.serialize())
                        .done(function(resp) {
                            showToast('Sukses', 'Perubahan disimpan.');
                        })
                        .fail(function() {
                            showToast('Gagal', 'Tidak dapat menyimpan perubahan.');
                            checkbox.prop('checked', !isChecked);
                        });
                });
            });
        </script>




                </div>
            </div>



<script>
// Placeholder for future JavaScript if needed
</script>

<?php require 'footer.php'; ?>