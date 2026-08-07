<?php
/**
 * Notification Background Save Handler
 * Memproses semua permintaan simpan notifikasi secara background (AJAX)
 */

header('Content-Type: application/json; charset=utf-8');

require 'header.php';
require_once __DIR__ . '/notifbot/bot_selector_helper.php';

$response = ['success' => false, 'message' => 'Unknown error', 'debug' => ''];

try {
    // Validasi database connection
    if (!isset($conn) || !$conn) {
        throw new Exception('Database connection error');
    }

    // Validasi user login
    if (!isset($ceknama) && !isset($username)) {
        throw new Exception('User tidak login');
    }

    $mode = $_POST['mode'] ?? '';
    $owner_key_for_bot_cfg = isset($ceknama) && $ceknama !== '' ? $ceknama : (isset($username) ? $username : 'default');
    $owner_key_for_dynamic_greeting = preg_replace('/[^A-Za-z0-9_.-]/', '_', (string)$owner_key_for_bot_cfg);
    
    if (empty($mode)) {
        throw new Exception('Mode tidak ditemukan');
    }

    // ===== JADWAL NOTIFIKASI (Remainder Schedule) =====
    if ($mode === 'remainder') {
        $waktu_kirim = $_POST['waktu_kirim'] ?? null;
        $hari = $_POST['hari'] ?? null;

        if (!$waktu_kirim || !$hari) {
            throw new Exception('Waktu kirim atau hari tidak lengkap');
        }

        $sql_check = "SELECT * FROM notifikasi WHERE pemilik = ?";
        $stmt = $conn->prepare($sql_check);
        if (!$stmt) throw new Exception('Prepare error: ' . $conn->error);
        
        $stmt->bind_param('s', $owner_key_for_bot_cfg);
        $stmt->execute();
        if ($stmt->error) throw new Exception('Execute error: ' . $stmt->error);
        
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $sql_update = "UPDATE notifikasi SET waktu_kirim = ?, hari = ? WHERE pemilik = ?";
            $stmt = $conn->prepare($sql_update);
            if (!$stmt) throw new Exception('Prepare error: ' . $conn->error);
            
            $stmt->bind_param('sss', $waktu_kirim, $hari, $owner_key_for_bot_cfg);
            $stmt->execute();
            if ($stmt->error) throw new Exception('Execute error: ' . $stmt->error);
        } else {
            $sql_insert = "INSERT INTO notifikasi (pemilik, waktu_kirim, hari) VALUES (?, ?, ?)";
            $stmt = $conn->prepare($sql_insert);
            if (!$stmt) throw new Exception('Prepare error: ' . $conn->error);
            
            $stmt->bind_param('sss', $owner_key_for_bot_cfg, $waktu_kirim, $hari);
            $stmt->execute();
            if ($stmt->error) throw new Exception('Execute error: ' . $stmt->error);
        }

        $response = ['success' => true, 'message' => 'Jadwal notifikasi berhasil disimpan'];
    }

    // ===== PESAN NOTIFIKASI =====
    elseif ($mode === 'save_registrasi') {
        $pesan = $_POST['pesan_registrasi'] ?? '';
        $sql_check = "SELECT * FROM notif_khusus WHERE pemilik = ?";
        $stmt = $conn->prepare($sql_check);
        $stmt->bind_param('s', $owner_key_for_bot_cfg);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $sql = "UPDATE notif_khusus SET pesan_registrasi = ? WHERE pemilik = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ss', $pesan, $owner_key_for_bot_cfg);
            $stmt->execute();
        } else {
            $sql = "INSERT INTO notif_khusus (pemilik, pesan_registrasi) VALUES (?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ss', $owner_key_for_bot_cfg, $pesan);
            $stmt->execute();
        }
        $response = ['success' => true, 'message' => '✓ Pesan Registrasi berhasil disimpan'];
    }

    elseif ($mode === 'save_expired') {
        $pesan = $_POST['pesan_notif'] ?? '';
        $sql_check = "SELECT * FROM notif_khusus WHERE pemilik = ?";
        $stmt = $conn->prepare($sql_check);
        $stmt->bind_param('s', $owner_key_for_bot_cfg);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $sql = "UPDATE notif_khusus SET pesan_expired = ? WHERE pemilik = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ss', $pesan, $owner_key_for_bot_cfg);
            $stmt->execute();
        } else {
            $sql = "INSERT INTO notif_khusus (pemilik, pesan_expired) VALUES (?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ss', $owner_key_for_bot_cfg, $pesan);
            $stmt->execute();
        }
        $response = ['success' => true, 'message' => '✓ Pesan Expired berhasil disimpan'];
    }

    elseif ($mode === 'save_reminder') {
        $pesan = $_POST['pesan_remainder'] ?? '';
        $sql_check = "SELECT * FROM notif_khusus WHERE pemilik = ?";
        $stmt = $conn->prepare($sql_check);
        $stmt->bind_param('s', $owner_key_for_bot_cfg);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $sql = "UPDATE notif_khusus SET pesan_reminder = ? WHERE pemilik = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ss', $pesan, $owner_key_for_bot_cfg);
            $stmt->execute();
        } else {
            $sql = "INSERT INTO notif_khusus (pemilik, pesan_reminder) VALUES (?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ss', $owner_key_for_bot_cfg, $pesan);
            $stmt->execute();
        }
        $response = ['success' => true, 'message' => '✓ Pesan Reminder berhasil disimpan'];
    }

    // ===== PENGATURAN LANJUTAN - Ketentuan, Disable, dll =====
    elseif ($mode === 'save_ketentuan') {
        $pesan = $_POST['pesan_ketentuan'] ?? '';
        $sql_check = "SELECT * FROM notif_khusus WHERE pemilik = ?";
        $stmt = $conn->prepare($sql_check);
        $stmt->bind_param('s', $owner_key_for_bot_cfg);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $sql = "UPDATE notif_khusus SET pesan_ketentuan = ? WHERE pemilik = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ss', $pesan, $owner_key_for_bot_cfg);
            $stmt->execute();
        } else {
            $sql = "INSERT INTO notif_khusus (pemilik, pesan_ketentuan) VALUES (?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ss', $owner_key_for_bot_cfg, $pesan);
            $stmt->execute();
        }
        $response = ['success' => true, 'message' => '✓ Pesan Ketentuan berhasil disimpan'];
    }

    elseif ($mode === 'save_disable') {
        $pesan = $_POST['pesan_disable'] ?? '';
        $sql_check = "SELECT * FROM notif_khusus WHERE pemilik = ?";
        $stmt = $conn->prepare($sql_check);
        $stmt->bind_param('s', $owner_key_for_bot_cfg);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $sql = "UPDATE notif_khusus SET pesan_disable = ? WHERE pemilik = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ss', $pesan, $owner_key_for_bot_cfg);
            $stmt->execute();
        } else {
            $sql = "INSERT INTO notif_khusus (pemilik, pesan_disable) VALUES (?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ss', $owner_key_for_bot_cfg, $pesan);
            $stmt->execute();
        }
        $response = ['success' => true, 'message' => '✓ Pesan Disable berhasil disimpan'];
    }

    elseif ($mode === 'save_aktif_manual') {
        $pesan = $_POST['pesan_aktif_manual'] ?? '';
        $sql_check = "SELECT * FROM notif_khusus WHERE pemilik = ?";
        $stmt = $conn->prepare($sql_check);
        $stmt->bind_param('s', $owner_key_for_bot_cfg);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $sql = "UPDATE notif_khusus SET pesan_aktif_manual = ? WHERE pemilik = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ss', $pesan, $owner_key_for_bot_cfg);
            $stmt->execute();
        } else {
            $sql = "INSERT INTO notif_khusus (pemilik, pesan_aktif_manual) VALUES (?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ss', $owner_key_for_bot_cfg, $pesan);
            $stmt->execute();
        }
        $response = ['success' => true, 'message' => '✓ Pesan Aktif Manual berhasil disimpan'];
    }

    elseif ($mode === 'save_remainder_manual') {
        $pesan = $_POST['pesan_remainder_manual'] ?? '';
        $sql_check = "SELECT * FROM notif_khusus WHERE pemilik = ?";
        $stmt = $conn->prepare($sql_check);
        $stmt->bind_param('s', $owner_key_for_bot_cfg);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $sql = "UPDATE notif_khusus SET pesan_remainder_manual = ? WHERE pemilik = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ss', $pesan, $owner_key_for_bot_cfg);
            $stmt->execute();
        } else {
            $sql = "INSERT INTO notif_khusus (pemilik, pesan_remainder_manual) VALUES (?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ss', $owner_key_for_bot_cfg, $pesan);
            $stmt->execute();
        }
        $response = ['success' => true, 'message' => '✓ Pesan Remainder Manual berhasil disimpan'];
    }

    elseif ($mode === 'save_dismantle_manual') {
        $pesan = $_POST['pesan_dismantle_manual'] ?? '';
        $sql_check = "SELECT * FROM notif_khusus WHERE pemilik = ?";
        $stmt = $conn->prepare($sql_check);
        $stmt->bind_param('s', $owner_key_for_bot_cfg);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $sql = "UPDATE notif_khusus SET pesan_dismantle_manual = ? WHERE pemilik = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ss', $pesan, $owner_key_for_bot_cfg);
            $stmt->execute();
        } else {
            $sql = "INSERT INTO notif_khusus (pemilik, pesan_dismantle_manual) VALUES (?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ss', $owner_key_for_bot_cfg, $pesan);
            $stmt->execute();
        }
        $response = ['success' => true, 'message' => '✓ Pesan Dismantle Manual berhasil disimpan'];
    }

    // ===== PENERIMA PESAN - Bot Receiver Config =====
    elseif ($mode === 'save_receiver_config') {
        // Handle different penerima forms with their specific button names
        $btn_name = $_POST['_btn_name'] ?? '';
        
        // Get botwa record or create if not exists
        $sql_check = "SELECT * FROM botwa WHERE pemilik = ?";
        $stmt = $conn->prepare($sql_check);
        $stmt->bind_param('s', $owner_key_for_bot_cfg);
        $stmt->execute();
        $result = $stmt->get_result();
        $botwa_exists = $result->num_rows > 0;

        // Determine which field to update based on button name
        if ($btn_name === 'simpan_nomor_penerima') {
            // General penerima (pendaftaran)
            $nomor = $_POST['nomor_penerima'] ?? '';
            $tipe = $_POST['tipe_penerima'] ?? 'pribadi';
            $bot = $_POST['bot_penerima'] ?? '';
            
            if (empty($nomor)) {
                throw new Exception('Nomor penerima tidak boleh kosong');
            }
            
            $data_json = json_encode(['nomor' => $nomor, 'tipe' => $tipe, 'bot' => $bot]);
            if ($botwa_exists) {
                $sql = "UPDATE botwa SET penerima_notif = ? WHERE pemilik = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('ss', $data_json, $owner_key_for_bot_cfg);
            } else {
                $sql = "INSERT INTO botwa (pemilik, penerima_notif) VALUES (?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('ss', $owner_key_for_bot_cfg, $data_json);
            }
            $stmt->execute();
            $response = ['success' => true, 'message' => '✓ Nomor Penerima (Pendaftaran) berhasil disimpan'];
        }
        else if ($btn_name === 'simpan_penerima_server') {
            $nomor = $_POST['nomor_penerima_server'] ?? '';
            $tipe = $_POST['tipe_penerima_server'] ?? 'pribadi';
            $bot = $_POST['bot_penerima_server'] ?? '';
            
            if (empty($nomor)) {
                throw new Exception('Nomor penerima tidak boleh kosong');
            }
            
            $data_json = json_encode(['nomor' => $nomor, 'tipe' => $tipe, 'bot' => $bot]);
            if ($botwa_exists) {
                $sql = "UPDATE botwa SET penerima_server = ? WHERE pemilik = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('ss', $data_json, $owner_key_for_bot_cfg);
            } else {
                $sql = "INSERT INTO botwa (pemilik, penerima_server) VALUES (?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('ss', $owner_key_for_bot_cfg, $data_json);
            }
            $stmt->execute();
            $response = ['success' => true, 'message' => '✓ Nomor Penerima Server berhasil disimpan'];
        }
        else if ($btn_name === 'simpan_penerima_livechat') {
            $nomor = $_POST['nomor_penerima_livechat'] ?? '';
            $tipe = $_POST['tipe_penerima_livechat'] ?? 'pribadi';
            $bot = $_POST['bot_penerima_livechat'] ?? '';
            
            if (empty($nomor)) {
                throw new Exception('Nomor penerima tidak boleh kosong');
            }
            
            $data_json = json_encode(['nomor' => $nomor, 'tipe' => $tipe, 'bot' => $bot]);
            if ($botwa_exists) {
                $sql = "UPDATE botwa SET penerima_livechat = ? WHERE pemilik = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('ss', $data_json, $owner_key_for_bot_cfg);
            } else {
                $sql = "INSERT INTO botwa (pemilik, penerima_livechat) VALUES (?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('ss', $owner_key_for_bot_cfg, $data_json);
            }
            $stmt->execute();
            $response = ['success' => true, 'message' => '✓ Nomor Penerima Live Chat berhasil disimpan'];
        }
        else if ($btn_name === 'simpan_penerima_system_notif') {
            $nomor = $_POST['nomor_penerima_system_notif'] ?? '';
            $tipe = $_POST['tipe_penerima_system_notif'] ?? 'pribadi';
            $bot = $_POST['bot_penerima_system_notif'] ?? '';
            
            if (empty($nomor)) {
                throw new Exception('Nomor penerima tidak boleh kosong');
            }
            
            $data_json = json_encode(['nomor' => $nomor, 'tipe' => $tipe, 'bot' => $bot]);
            if ($botwa_exists) {
                $sql = "UPDATE botwa SET penerima_system_notif = ? WHERE pemilik = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('ss', $data_json, $owner_key_for_bot_cfg);
            } else {
                $sql = "INSERT INTO botwa (pemilik, penerima_system_notif) VALUES (?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('ss', $owner_key_for_bot_cfg, $data_json);
            }
            $stmt->execute();
            $response = ['success' => true, 'message' => '✓ Nomor Penerima System Notif berhasil disimpan'];
        }
        else if ($btn_name === 'simpan_penerima_odp_los') {
            $nomor = $_POST['nomor_penerima_odp_los'] ?? '';
            $tipe = $_POST['tipe_penerima_odp_los'] ?? 'pribadi';
            $bot = $_POST['bot_penerima_odp_los'] ?? '';
            $interval = $_POST['interval_odp_los'] ?? '24';
            
            if (empty($nomor)) {
                throw new Exception('Nomor penerima tidak boleh kosong');
            }
            
            $data_json = json_encode(['nomor' => $nomor, 'tipe' => $tipe, 'bot' => $bot, 'interval' => $interval]);
            if ($botwa_exists) {
                $sql = "UPDATE botwa SET penerima_odp_los = ? WHERE pemilik = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('ss', $data_json, $owner_key_for_bot_cfg);
            } else {
                $sql = "INSERT INTO botwa (pemilik, penerima_odp_los) VALUES (?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('ss', $owner_key_for_bot_cfg, $data_json);
            }
            $stmt->execute();
            $response = ['success' => true, 'message' => '✓ Nomor Penerima ODP LOS berhasil disimpan'];
        }
        else if ($btn_name === 'simpan_interval_odp_los') {
            // This is for interval only
            $interval = $_POST['interval_odp_los'] ?? '24';
            
            if ($botwa_exists) {
                // Get existing odp_los config
                $sql_get = "SELECT penerima_odp_los FROM botwa WHERE pemilik = ?";
                $stmt_get = $conn->prepare($sql_get);
                $stmt_get->bind_param('s', $owner_key_for_bot_cfg);
                $stmt_get->execute();
                $res_get = $stmt_get->get_result();
                $row = $res_get->fetch_assoc();
                
                $data = $row ? json_decode($row['penerima_odp_los'], true) : [];
                if (!is_array($data)) $data = [];
                $data['interval'] = $interval;
                
                $data_json = json_encode($data);
                $sql = "UPDATE botwa SET penerima_odp_los = ? WHERE pemilik = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('ss', $data_json, $owner_key_for_bot_cfg);
                $stmt->execute();
            }
            $response = ['success' => true, 'message' => '✓ Interval berhasil disimpan'];
        }
        else {
            throw new Exception('Tipe penerima tidak dikenali: ' . $btn_name);
        }
    }

    // ===== OTP Portal Template =====
    elseif ($mode === 'save_otp_portal') {
        $otp_portal_mode = $_POST['otp_portal_mode'] ?? 'bypass';
        $otp_portal_waapi = $_POST['otp_portal_waapi'] ?? '';
        $otp_portal_namebot = $_POST['otp_portal_namebot'] ?? '';
        $otp_portal_password = $_POST['otp_portal_password'] ?? '';
        $otp_portal_template = $_POST['otp_portal_template'] ?? '';

        $config_path = __DIR__ . "/notifbot/data/otp_portal_config-$owner_key_for_bot_cfg.json";
        
        $config = [
            'mode' => $otp_portal_mode,
            'waapi' => $otp_portal_waapi,
            'namebot' => $otp_portal_namebot,
            'password' => $otp_portal_password,
            'template' => $otp_portal_template
        ];
        if (!is_dir(dirname($config_path))) {
            mkdir(dirname($config_path), 0755, true);
        }
        file_put_contents($config_path, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        
        $response = ['success' => true, 'message' => '✓ Pengaturan OTP Portal berhasil disimpan'];
    }

    // ===== Dynamic Greeting =====
    elseif ($mode === 'save_dynamic_greeting') {
        $greeting_list = $_POST['dynamic_greeting_list'] ?? '';
        $dynamic_greeting_enabled = isset($_POST['dynamic_greeting_enabled']) ? '1' : '0';
        $config_path = __DIR__ . "/notifbot/data/dynamic_greeting-$owner_key_for_dynamic_greeting.json";
        
        $greetings = array_filter(array_map('trim', explode("\n", $greeting_list)));
        $config = [
            'enabled' => $dynamic_greeting_enabled,
            'greetings' => array_values($greetings)
        ];
        if (!is_dir(dirname($config_path))) {
            mkdir(dirname($config_path), 0755, true);
        }
        file_put_contents($config_path, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        
        $response = ['success' => true, 'message' => '✓ Pengaturan Salam Dinamis berhasil disimpan'];
    }

    // ===== Invoice Generator =====
    elseif ($mode === 'save_invoice_generator') {
        $invoice_config_file = __DIR__ . "/notifbot/data/invoice_generator_config-$owner_key_for_bot_cfg.json";
        
        $config = [
            'enabled' => isset($_POST['invoice_generator_enabled']) ? 1 : 0,
            'start_day' => (int)($_POST['invoice_generate_start_day'] ?? 1),
            'hour' => (int)($_POST['invoice_generate_hour'] ?? 0),
            'minute' => (int)($_POST['invoice_generate_minute'] ?? 0)
        ];
        if (!is_dir(dirname($invoice_config_file))) {
            mkdir(dirname($invoice_config_file), 0755, true);
        }
        file_put_contents($invoice_config_file, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        
        $response = ['success' => true, 'message' => '✓ Pengaturan Invoice Generator berhasil disimpan'];
    }

    // ===== Toggle Promo Update (Cron) =====
    elseif ($mode === 'toggle_promo_update') {
        $promo_config_file = __DIR__ . '/notifbot/notifphp/promo_config.json';
        $promo_config = ["promo_update_enabled" => true];
        
        if (file_exists($promo_config_file)) {
            $promo_config = json_decode(file_get_contents($promo_config_file), true);
            if (!is_array($promo_config)) $promo_config = ["promo_update_enabled" => true];
        }
        
        $promo_config['promo_update_enabled'] = isset($_POST['promo_update_enabled']) && $_POST['promo_update_enabled'] == '1';
        file_put_contents($promo_config_file, json_encode($promo_config, JSON_PRETTY_PRINT));
        
        $response = ['success' => true, 'message' => '✓ Status promo update berhasil diubah'];
    }

    else {
        throw new Exception('Mode tidak dikenali: ' . $mode);
    }

} catch (Exception $e) {
    $response = [
        'success' => false,
        'message' => '✗ Error: ' . $e->getMessage()
    ];
    http_response_code(400);
} catch (mysqli_sql_exception $e) {
    $response = [
        'success' => false,
        'message' => '✗ Database Error: ' . $e->getMessage()
    ];
    http_response_code(400);
}

echo json_encode($response);
exit;
?>
