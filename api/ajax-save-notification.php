<?php
/**
 * AJAX API untuk Simpan Notifikasi
 * Menangani semua proses simpan di background
 */

header('Content-Type: application/json; charset=utf-8');

require_once '../koneksidb.php';
require_once '../header.php';
require_once __DIR__ . '/../notifbot/bot_selector_helper.php';

// Response template
$response = ['success' => false, 'message' => '', 'type' => ''];

try {
    $action = trim($_POST['action'] ?? '');
    
    if (empty($action)) {
        throw new Exception('Action tidak ditemukan');
    }

    // ========== PESAN NOTIFIKASI ==========
    if ($action === 'save_pesan_registrasi') {
        $pesan = trim($_POST['pesan_registrasi'] ?? '');
        if (empty($pesan)) {
            throw new Exception('Pesan registrasi tidak boleh kosong');
        }
        
        $pemilik = isset($ceknama) ? $ceknama : '';
        if (empty($pemilik)) {
            throw new Exception('Pemilik tidak ditemukan');
        }

        $stmt = $conn->prepare("INSERT INTO notif_khusus (pemilik, pesan_registrasi, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE pesan_registrasi = VALUES(pesan_registrasi), updated_at = NOW()");
        if (!$stmt) throw new Exception('Prepare error: ' . $conn->error);
        
        $stmt->bind_param('ss', $pemilik, $pesan);
        if (!$stmt->execute()) {
            throw new Exception('Execute error: ' . $stmt->error);
        }
        $stmt->close();

        recordHistory($ceknama, "Menyimpan pesan registrasi");
        $response['success'] = true;
        $response['message'] = 'Pesan registrasi berhasil disimpan';
        $response['type'] = 'registrasi';
    }
    elseif ($action === 'save_pesan_expired') {
        $pesan = trim($_POST['pesan_expired'] ?? '');
        if (empty($pesan)) {
            throw new Exception('Pesan expired tidak boleh kosong');
        }
        
        $pemilik = isset($ceknama) ? $ceknama : '';
        if (empty($pemilik)) {
            throw new Exception('Pemilik tidak ditemukan');
        }

        $stmt = $conn->prepare("INSERT INTO notif_khusus (pemilik, pesan_expired, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE pesan_expired = VALUES(pesan_expired), updated_at = NOW()");
        if (!$stmt) throw new Exception('Prepare error: ' . $conn->error);
        
        $stmt->bind_param('ss', $pemilik, $pesan);
        if (!$stmt->execute()) {
            throw new Exception('Execute error: ' . $stmt->error);
        }
        $stmt->close();

        recordHistory($ceknama, "Menyimpan pesan expired");
        $response['success'] = true;
        $response['message'] = 'Pesan expired berhasil disimpan';
        $response['type'] = 'expired';
    }
    elseif ($action === 'save_pesan_reminder') {
        $pesan = trim($_POST['pesan_reminder'] ?? '');
        if (empty($pesan)) {
            throw new Exception('Pesan reminder tidak boleh kosong');
        }
        
        $pemilik = isset($ceknama) ? $ceknama : '';
        if (empty($pemilik)) {
            throw new Exception('Pemilik tidak ditemukan');
        }

        $stmt = $conn->prepare("INSERT INTO notif_khusus (pemilik, pesan_reminder, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE pesan_reminder = VALUES(pesan_reminder), updated_at = NOW()");
        if (!$stmt) throw new Exception('Prepare error: ' . $conn->error);
        
        $stmt->bind_param('ss', $pemilik, $pesan);
        if (!$stmt->execute()) {
            throw new Exception('Execute error: ' . $stmt->error);
        }
        $stmt->close();

        recordHistory($ceknama, "Menyimpan pesan reminder");
        $response['success'] = true;
        $response['message'] = 'Pesan reminder berhasil disimpan';
        $response['type'] = 'reminder';
    }
    elseif ($action === 'save_pesan_ketentuan') {
        $pesan = trim($_POST['pesan_ketentuan'] ?? '');
        if (empty($pesan)) {
            throw new Exception('Pesan ketentuan tidak boleh kosong');
        }
        
        $pemilik = isset($ceknama) ? $ceknama : '';
        if (empty($pemilik)) {
            throw new Exception('Pemilik tidak ditemukan');
        }

        $stmt = $conn->prepare("INSERT INTO notif_khusus (pemilik, pesan_ketentuan, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE pesan_ketentuan = VALUES(pesan_ketentuan), updated_at = NOW()");
        if (!$stmt) throw new Exception('Prepare error: ' . $conn->error);
        
        $stmt->bind_param('ss', $pemilik, $pesan);
        if (!$stmt->execute()) {
            throw new Exception('Execute error: ' . $stmt->error);
        }
        $stmt->close();

        recordHistory($ceknama, "Menyimpan pesan ketentuan");
        $response['success'] = true;
        $response['message'] = 'Pesan ketentuan berhasil disimpan';
        $response['type'] = 'ketentuan';
    }
    elseif ($action === 'save_pesan_disable') {
        $pesan = trim($_POST['pesan_disable'] ?? '');
        if (empty($pesan)) {
            throw new Exception('Pesan disable tidak boleh kosong');
        }
        
        $pemilik = isset($ceknama) ? $ceknama : '';
        if (empty($pemilik)) {
            throw new Exception('Pemilik tidak ditemukan');
        }

        $stmt = $conn->prepare("INSERT INTO notif_khusus (pemilik, pesan_disable, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE pesan_disable = VALUES(pesan_disable), updated_at = NOW()");
        if (!$stmt) throw new Exception('Prepare error: ' . $conn->error);
        
        $stmt->bind_param('ss', $pemilik, $pesan);
        if (!$stmt->execute()) {
            throw new Exception('Execute error: ' . $stmt->error);
        }
        $stmt->close();

        recordHistory($ceknama, "Menyimpan pesan disable");
        $response['success'] = true;
        $response['message'] = 'Pesan disable berhasil disimpan';
        $response['type'] = 'disable';
    }
    elseif ($action === 'save_pesan_aktif_manual') {
        $pesan = trim($_POST['pesan_aktif_manual'] ?? '');
        if (empty($pesan)) {
            throw new Exception('Pesan aktif manual tidak boleh kosong');
        }
        
        $pemilik = isset($ceknama) ? $ceknama : '';
        if (empty($pemilik)) {
            throw new Exception('Pemilik tidak ditemukan');
        }

        $stmt = $conn->prepare("INSERT INTO notif_khusus (pemilik, pesan_aktif_manual, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE pesan_aktif_manual = VALUES(pesan_aktif_manual), updated_at = NOW()");
        if (!$stmt) throw new Exception('Prepare error: ' . $conn->error);
        
        $stmt->bind_param('ss', $pemilik, $pesan);
        if (!$stmt->execute()) {
            throw new Exception('Execute error: ' . $stmt->error);
        }
        $stmt->close();

        recordHistory($ceknama, "Menyimpan pesan aktif manual");
        $response['success'] = true;
        $response['message'] = 'Pesan aktif manual berhasil disimpan';
        $response['type'] = 'aktif_manual';
    }
    elseif ($action === 'save_pesan_remainder_manual') {
        $pesan = trim($_POST['pesan_remainder_manual'] ?? '');
        if (empty($pesan)) {
            throw new Exception('Pesan remainder manual tidak boleh kosong');
        }
        
        $pemilik = isset($ceknama) ? $ceknama : '';
        if (empty($pemilik)) {
            throw new Exception('Pemilik tidak ditemukan');
        }

        $stmt = $conn->prepare("INSERT INTO notif_khusus (pemilik, pesan_remainder_manual, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE pesan_remainder_manual = VALUES(pesan_remainder_manual), updated_at = NOW()");
        if (!$stmt) throw new Exception('Prepare error: ' . $conn->error);
        
        $stmt->bind_param('ss', $pemilik, $pesan);
        if (!$stmt->execute()) {
            throw new Exception('Execute error: ' . $stmt->error);
        }
        $stmt->close();

        recordHistory($ceknama, "Menyimpan pesan remainder manual");
        $response['success'] = true;
        $response['message'] = 'Pesan remainder manual berhasil disimpan';
        $response['type'] = 'remainder_manual';
    }
    elseif ($action === 'save_pesan_dismantle_manual') {
        $pesan = trim($_POST['pesan_dismantle_manual'] ?? '');
        if (empty($pesan)) {
            throw new Exception('Pesan dismantle manual tidak boleh kosong');
        }
        
        $pemilik = isset($ceknama) ? $ceknama : '';
        if (empty($pemilik)) {
            throw new Exception('Pemilik tidak ditemukan');
        }

        $stmt = $conn->prepare("INSERT INTO notif_khusus (pemilik, pesan_dismantle_manual, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE pesan_dismantle_manual = VALUES(pesan_dismantle_manual), updated_at = NOW()");
        if (!$stmt) throw new Exception('Prepare error: ' . $conn->error);
        
        $stmt->bind_param('ss', $pemilik, $pesan);
        if (!$stmt->execute()) {
            throw new Exception('Execute error: ' . $stmt->error);
        }
        $stmt->close();

        recordHistory($ceknama, "Menyimpan pesan dismantle manual");
        $response['success'] = true;
        $response['message'] = 'Pesan dismantle manual berhasil disimpan';
        $response['type'] = 'dismantle_manual';
    }

    // ========== PENERIMA PESAN ==========
    elseif ($action === 'save_nomor_penerima') {
        $nomor = trim($_POST['nomor_penerima'] ?? '');
        $tipe = $_POST['tipe_penerima'] ?? 'pribadi';
        $bot = trim($_POST['bot_penerima'] ?? '');

        if (empty($nomor)) throw new Exception('Nomor penerima tidak boleh kosong');

        $nomor_final = validateAndFormatNumber($nomor, $tipe);
        
        $pemilik = isset($ceknama) ? $ceknama : '';
        if (empty($pemilik)) throw new Exception('Pemilik tidak ditemukan');

        $stmt = $conn->prepare("UPDATE botwa SET penerima = ? WHERE pemilik = ?");
        if (!$stmt) throw new Exception('Prepare error: ' . $conn->error);
        
        $stmt->bind_param('ss', $nomor_final, $pemilik);
        if (!$stmt->execute()) {
            throw new Exception('Execute error: ' . $stmt->error);
        }
        $stmt->close();

        recordHistory($ceknama, "Menyimpan nomor penerima: $nomor_final");
        $response['success'] = true;
        $response['message'] = 'Nomor penerima berhasil disimpan';
        $response['type'] = 'penerima';
    }
    elseif ($action === 'save_penerima_server') {
        $nomor = trim($_POST['nomor_penerima_server'] ?? '');
        $tipe = $_POST['tipe_penerima_server'] ?? 'pribadi';

        if (empty($nomor)) throw new Exception('Nomor penerima server tidak boleh kosong');

        $nomor_final = validateAndFormatNumber($nomor, $tipe);
        
        $pemilik = isset($ceknama) ? $ceknama : '';
        if (empty($pemilik)) throw new Exception('Pemilik tidak ditemukan');

        $stmt = $conn->prepare("UPDATE botwa SET penerima_server = ? WHERE pemilik = ?");
        if (!$stmt) throw new Exception('Prepare error: ' . $conn->error);
        
        $stmt->bind_param('ss', $nomor_final, $pemilik);
        if (!$stmt->execute()) {
            throw new Exception('Execute error: ' . $stmt->error);
        }
        $stmt->close();

        recordHistory($ceknama, "Menyimpan nomor penerima server: $nomor_final");
        $response['success'] = true;
        $response['message'] = 'Nomor penerima server berhasil disimpan';
        $response['type'] = 'penerima_server';
    }
    elseif ($action === 'save_penerima_livechat') {
        $nomor = trim($_POST['nomor_penerima_livechat'] ?? '');
        $tipe = $_POST['tipe_penerima_livechat'] ?? 'pribadi';

        if (empty($nomor)) throw new Exception('Nomor penerima livechat tidak boleh kosong');

        $nomor_final = validateAndFormatNumber($nomor, $tipe);
        
        $pemilik = isset($ceknama) ? $ceknama : '';
        if (empty($pemilik)) throw new Exception('Pemilik tidak ditemukan');

        $stmt = $conn->prepare("UPDATE botwa SET penerima_livechat = ? WHERE pemilik = ?");
        if (!$stmt) throw new Exception('Prepare error: ' . $conn->error);
        
        $stmt->bind_param('ss', $nomor_final, $pemilik);
        if (!$stmt->execute()) {
            throw new Exception('Execute error: ' . $stmt->error);
        }
        $stmt->close();

        recordHistory($ceknama, "Menyimpan nomor penerima livechat: $nomor_final");
        $response['success'] = true;
        $response['message'] = 'Nomor penerima livechat berhasil disimpan';
        $response['type'] = 'penerima_livechat';
    }
    elseif ($action === 'save_penerima_system_notif') {
        $nomor = trim($_POST['nomor_penerima_system_notif'] ?? '');
        $tipe = $_POST['tipe_penerima_system_notif'] ?? 'pribadi';

        if (empty($nomor)) throw new Exception('Nomor penerima system notif tidak boleh kosong');

        $nomor_final = validateAndFormatNumber($nomor, $tipe);
        
        $pemilik = isset($ceknama) ? $ceknama : '';
        if (empty($pemilik)) throw new Exception('Pemilik tidak ditemukan');

        $stmt = $conn->prepare("UPDATE botwa SET penerima_system_notif = ? WHERE pemilik = ?");
        if (!$stmt) throw new Exception('Prepare error: ' . $conn->error);
        
        $stmt->bind_param('ss', $nomor_final, $pemilik);
        if (!$stmt->execute()) {
            throw new Exception('Execute error: ' . $stmt->error);
        }
        $stmt->close();

        recordHistory($ceknama, "Menyimpan nomor penerima system notif: $nomor_final");
        $response['success'] = true;
        $response['message'] = 'Nomor penerima system notif berhasil disimpan';
        $response['type'] = 'penerima_system_notif';
    }
    elseif ($action === 'save_penerima_odp_los') {
        $nomor = trim($_POST['nomor_penerima_odp_los'] ?? '');
        $tipe = $_POST['tipe_penerima_odp_los'] ?? 'pribadi';

        if (empty($nomor)) throw new Exception('Nomor penerima ODP/LOS tidak boleh kosong');

        $nomor_final = validateAndFormatNumber($nomor, $tipe);
        
        $pemilik = isset($ceknama) ? $ceknama : '';
        if (empty($pemilik)) throw new Exception('Pemilik tidak ditemukan');

        $stmt = $conn->prepare("UPDATE botwa SET penerima_odp_los = ? WHERE pemilik = ?");
        if (!$stmt) throw new Exception('Prepare error: ' . $conn->error);
        
        $stmt->bind_param('ss', $nomor_final, $pemilik);
        if (!$stmt->execute()) {
            throw new Exception('Execute error: ' . $stmt->error);
        }
        $stmt->close();

        recordHistory($ceknama, "Menyimpan nomor penerima ODP/LOS: $nomor_final");
        $response['success'] = true;
        $response['message'] = 'Nomor penerima ODP/LOS berhasil disimpan';
        $response['type'] = 'penerima_odp_los';
    }
    elseif ($action === 'save_penerima_manual_active') {
        $nomor = trim($_POST['nomor_penerima_manual_active'] ?? '');
        $tipe = $_POST['tipe_penerima_manual_active'] ?? 'pribadi';

        if (empty($nomor)) throw new Exception('Nomor penerima manual active tidak boleh kosong');

        $nomor_final = validateAndFormatNumber($nomor, $tipe);
        
        $pemilik = isset($ceknama) ? $ceknama : '';
        if (empty($pemilik)) throw new Exception('Pemilik tidak ditemukan');

        $stmt = $conn->prepare("UPDATE botwa SET penerima_manual_active = ? WHERE pemilik = ?");
        if (!$stmt) throw new Exception('Prepare error: ' . $conn->error);
        
        $stmt->bind_param('ss', $nomor_final, $pemilik);
        if (!$stmt->execute()) {
            throw new Exception('Execute error: ' . $stmt->error);
        }
        $stmt->close();

        recordHistory($ceknama, "Menyimpan nomor penerima manual active: $nomor_final");
        $response['success'] = true;
        $response['message'] = 'Nomor penerima manual active berhasil disimpan';
        $response['type'] = 'penerima_manual_active';
    }
    elseif ($action === 'save_penerima_provisioning') {
        $nomor = trim($_POST['nomor_penerima_provisioning'] ?? '');
        $tipe = $_POST['tipe_penerima_provisioning'] ?? 'pribadi';

        if (empty($nomor)) throw new Exception('Nomor penerima provisioning tidak boleh kosong');

        $nomor_final = validateAndFormatNumber($nomor, $tipe);
        
        $pemilik = isset($ceknama) ? $ceknama : '';
        if (empty($pemilik)) throw new Exception('Pemilik tidak ditemukan');

        $stmt = $conn->prepare("UPDATE botwa SET penerima_provisioning = ? WHERE pemilik = ?");
        if (!$stmt) throw new Exception('Prepare error: ' . $conn->error);
        
        $stmt->bind_param('ss', $nomor_final, $pemilik);
        if (!$stmt->execute()) {
            throw new Exception('Execute error: ' . $stmt->error);
        }
        $stmt->close();

        recordHistory($ceknama, "Menyimpan nomor penerima provisioning: $nomor_final");
        $response['success'] = true;
        $response['message'] = 'Nomor penerima provisioning berhasil disimpan';
        $response['type'] = 'penerima_provisioning';
    }

    // ========== JADWAL NOTIFIKASI ==========
    elseif ($action === 'save_interval_odp_los') {
        $interval = intval($_POST['interval_odp_los'] ?? 0);
        if ($interval < 0) throw new Exception('Interval harus nilai positif');

        $pemilik = isset($ceknama) ? $ceknama : '';
        if (empty($pemilik)) throw new Exception('Pemilik tidak ditemukan');

        $config_file = __DIR__ . "/../notifbot/data/odp_los_config-$pemilik.json";
        $config = ['interval' => $interval, 'updated_at' => date('Y-m-d H:i:s')];
        
        if (file_put_contents($config_file, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
            throw new Exception('Gagal menyimpan konfigurasi ODP/LOS');
        }

        recordHistory($ceknama, "Menyimpan interval ODP/LOS: $interval jam");
        $response['success'] = true;
        $response['message'] = 'Interval ODP/LOS berhasil disimpan';
        $response['type'] = 'interval_odp_los';
    }
    elseif ($action === 'save_prabayar_grace_period') {
        $days = intval($_POST['prabayar_grace_period'] ?? 0);
        if ($days < 0) throw new Exception('Grace period harus nilai positif');

        $pemilik = isset($ceknama) ? $ceknama : '';
        if (empty($pemilik)) throw new Exception('Pemilik tidak ditemukan');

        $config_file = __DIR__ . "/../notifbot/data/grace_period-$pemilik.json";
        $config = ['days' => $days, 'updated_at' => date('Y-m-d H:i:s')];
        
        if (file_put_contents($config_file, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
            throw new Exception('Gagal menyimpan grace period');
        }

        recordHistory($ceknama, "Menyimpan prabayar grace period: $days hari");
        $response['success'] = true;
        $response['message'] = 'Prabayar grace period berhasil disimpan';
        $response['type'] = 'grace_period';
    }
    elseif ($action === 'save_invoice_generator') {
        $enabled = isset($_POST['invoice_generator_enabled']) ? '1' : '0';
        $schedule = trim($_POST['invoice_generator_schedule'] ?? '0 0 * * *');

        $pemilik = isset($ceknama) ? $ceknama : '';
        if (empty($pemilik)) throw new Exception('Pemilik tidak ditemukan');

        $config_file = __DIR__ . "/../notifbot/data/invoice_generator-$pemilik.json";
        $config = ['enabled' => $enabled === '1', 'schedule' => $schedule, 'updated_at' => date('Y-m-d H:i:s')];
        
        if (file_put_contents($config_file, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
            throw new Exception('Gagal menyimpan invoice generator');
        }

        recordHistory($ceknama, "Menyimpan invoice generator: " . ($enabled === '1' ? 'Enabled' : 'Disabled'));
        $response['success'] = true;
        $response['message'] = 'Invoice generator berhasil disimpan';
        $response['type'] = 'invoice_generator';
    }

    // ========== PENGATURAN LANJUTAN ==========
    elseif ($action === 'save_otp_portal_template') {
        $template = trim($_POST['otp_portal_template'] ?? '');
        if (empty($template)) throw new Exception('Template OTP portal tidak boleh kosong');

        $pemilik = isset($ceknama) ? $ceknama : '';
        if (empty($pemilik)) throw new Exception('Pemilik tidak ditemukan');

        $config_file = __DIR__ . "/../notifbot/data/otp_template-$pemilik.json";
        $config = ['template' => $template, 'updated_at' => date('Y-m-d H:i:s')];
        
        if (file_put_contents($config_file, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
            throw new Exception('Gagal menyimpan template OTP portal');
        }

        recordHistory($ceknama, "Menyimpan template OTP portal");
        $response['success'] = true;
        $response['message'] = 'Template OTP portal berhasil disimpan';
        $response['type'] = 'otp_template';
    }
    elseif ($action === 'save_dynamic_greeting') {
        $enabled = isset($_POST['dynamic_greeting_enabled']) ? '1' : '0';
        $greetings_text = trim($_POST['dynamic_greeting_list'] ?? '');

        $pemilik = isset($ceknama) ? $ceknama : '';
        if (empty($pemilik)) throw new Exception('Pemilik tidak ditemukan');

        $greetings = [];
        if (!empty($greetings_text)) {
            $lines = preg_split('/\r\n|\r|\n/', $greetings_text);
            foreach ($lines as $line) {
                $line = trim($line);
                if (!empty($line)) {
                    $greetings[] = $line;
                }
            }
        }

        $greetings = array_values(array_unique($greetings));
        
        $config_file = __DIR__ . "/../notifbot/data/dynamic_greeting-$pemilik.json";
        $config = ['enabled' => $enabled === '1', 'greetings' => $greetings, 'updated_at' => date('Y-m-d H:i:s')];
        
        if (file_put_contents($config_file, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
            throw new Exception('Gagal menyimpan salam dinamis');
        }

        recordHistory($ceknama, "Menyimpan pengaturan salam dinamis");
        $response['success'] = true;
        $response['message'] = 'Pengaturan salam dinamis berhasil disimpan';
        $response['type'] = 'dynamic_greeting';
    }
    else {
        throw new Exception('Action tidak dikenali: ' . htmlspecialchars($action));
    }

} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = $e->getMessage();
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);

// ========== HELPER FUNCTIONS ==========
function validateAndFormatNumber($nomor, $tipe) {
    if ($tipe === 'pribadi') {
        if (!preg_match('/^62[0-9]{7,15}$/', $nomor)) {
            throw new Exception('Nomor pribadi harus diawali 62 dan hanya angka (7-15 digit setelah 62)');
        }
        return $nomor . '@s.whatsapp.net';
    } elseif ($tipe === 'grup') {
        return $nomor . '@g.us';
    }
    throw new Exception('Tipe penerima tidak valid');
}

function recordHistory($pemilik, $action) {
    global $ceknama, $asistant_name;
    $history_file = __DIR__ . "/../notifbot/data/history-$pemilik.json";
    $history = [];
    
    if (file_exists($history_file)) {
        $history = json_decode(file_get_contents($history_file), true);
    }
    
    if (!is_array($history)) $history = [];
    
    $name = !empty($asistant_name) ? $asistant_name : $pemilik;
    $history[] = "[ $name - " . date('Y-m-d H:i:s') . " ] $action";
    
    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
}
?>
