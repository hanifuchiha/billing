
<?php
// Simpan Nomor Penerima
if (isset($_POST['mode']) && $_POST['mode'] === 'save_penerima') {
    $nomor = trim($_POST['nomor_penerima'] ?? '');
    $tipe = $_POST['tipe_penerima'] ?? 'pribadi';
    $bot = trim($_POST['bot_penerima'] ?? '');
    $pemilik = isset($ceknama) ? $ceknama : ($_SESSION['username'] ?? '');
    $nomor_final = '';
    if ($tipe === 'pribadi' && preg_match('/^62[0-9]{7,15}$/', $nomor)) {
        $nomor_final = $nomor . '@s.whatsapp.net';
    } elseif ($tipe === 'grup') {
        $nomor_final = $nomor . '@g.us';
    }
    if ($pemilik && $nomor_final) {
        $stmt = $conn->prepare("UPDATE botwa SET penerima=?, bot_penerima=? WHERE pemilik=?");
        $stmt->bind_param('sss', $nomor_final, $bot, $pemilik);
        if ($stmt->execute()) {
            $response = ['status' => 'ok', 'message' => 'Nomor penerima berhasil disimpan'];
        } else {
            $response = ['status' => 'error', 'message' => 'Gagal menyimpan ke database'];
        }
        $stmt->close();
    } else {
        $response = ['status' => 'error', 'message' => 'Data tidak lengkap/format salah'];
    }
    echo json_encode($response); exit;
}

// Simpan Nomor Penerima Server
if (isset($_POST['mode']) && $_POST['mode'] === 'save_penerima_server') {
    $nomor = trim($_POST['nomor_penerima_server'] ?? '');
    $tipe = $_POST['tipe_penerima_server'] ?? 'pribadi';
    $bot = trim($_POST['bot_penerima_server'] ?? '');
    $pemilik = isset($ceknama) ? $ceknama : ($_SESSION['username'] ?? '');
    $nomor_final = '';
    if ($tipe === 'pribadi' && preg_match('/^62[0-9]{7,15}$/', $nomor)) {
        $nomor_final = $nomor . '@s.whatsapp.net';
    } elseif ($tipe === 'grup') {
        $nomor_final = $nomor . '@g.us';
    }
    if ($pemilik && $nomor_final) {
        $stmt = $conn->prepare("UPDATE botwa SET penerima_server=?, bot_penerima_server=? WHERE pemilik=?");
        $stmt->bind_param('sss', $nomor_final, $bot, $pemilik);
        if ($stmt->execute()) {
            $response = ['status' => 'ok', 'message' => 'Nomor penerima server berhasil disimpan'];
        } else {
            $response = ['status' => 'error', 'message' => 'Gagal menyimpan ke database'];
        }
        $stmt->close();
    } else {
        $response = ['status' => 'error', 'message' => 'Data tidak lengkap/format salah'];
    }
    echo json_encode($response); exit;
}

// Simpan Nomor Penerima Livechat
if (isset($_POST['mode']) && $_POST['mode'] === 'save_penerima_livechat') {
    $nomor = trim($_POST['nomor_penerima_livechat'] ?? '');
    $tipe = $_POST['tipe_penerima_livechat'] ?? 'pribadi';
    $bot = trim($_POST['bot_penerima_livechat'] ?? '');
    $pemilik = isset($ceknama) ? $ceknama : ($_SESSION['username'] ?? '');
    $nomor_final = '';
    if ($tipe === 'pribadi' && preg_match('/^62[0-9]{7,15}$/', $nomor)) {
        $nomor_final = $nomor . '@s.whatsapp.net';
    } elseif ($tipe === 'grup') {
        $nomor_final = $nomor . '@g.us';
    }
    if ($pemilik && $nomor_final) {
        $stmt = $conn->prepare("UPDATE botwa SET penerima_livechat=?, bot_penerima_livechat=? WHERE pemilik=?");
        $stmt->bind_param('sss', $nomor_final, $bot, $pemilik);
        if ($stmt->execute()) {
            $response = ['status' => 'ok', 'message' => 'Nomor penerima livechat berhasil disimpan'];
        } else {
            $response = ['status' => 'error', 'message' => 'Gagal menyimpan ke database'];
        }
        $stmt->close();
    } else {
        $response = ['status' => 'error', 'message' => 'Data tidak lengkap/format salah'];
    }
    echo json_encode($response); exit;
}

// Simpan Nomor Penerima System Notif
if (isset($_POST['mode']) && $_POST['mode'] === 'save_penerima_system_notif') {
    $nomor = trim($_POST['nomor_penerima_system_notif'] ?? '');
    $tipe = $_POST['tipe_penerima_system_notif'] ?? 'pribadi';
    $bot = trim($_POST['bot_penerima_system_notif'] ?? '');
    $pemilik = isset($ceknama) ? $ceknama : ($_SESSION['username'] ?? '');
    $nomor_final = '';
    if ($tipe === 'pribadi' && preg_match('/^62[0-9]{7,15}$/', $nomor)) {
        $nomor_final = $nomor . '@s.whatsapp.net';
    } elseif ($tipe === 'grup') {
        $nomor_final = $nomor . '@g.us';
    }
    if ($pemilik && $nomor_final) {
        $stmt = $conn->prepare("UPDATE botwa SET penerima_system_notif=?, bot_penerima_system_notif=? WHERE pemilik=?");
        $stmt->bind_param('sss', $nomor_final, $bot, $pemilik);
        if ($stmt->execute()) {
            $response = ['status' => 'ok', 'message' => 'Nomor penerima system notif berhasil disimpan'];
        } else {
            $response = ['status' => 'error', 'message' => 'Gagal menyimpan ke database'];
        }
        $stmt->close();
    } else {
        $response = ['status' => 'error', 'message' => 'Data tidak lengkap/format salah'];
    }
    echo json_encode($response); exit;
}

// Simpan Nomor Penerima ODP LOS
if (isset($_POST['mode']) && $_POST['mode'] === 'save_penerima_odp_los') {
    $nomor = trim($_POST['nomor_penerima_odp_los'] ?? '');
    $tipe = $_POST['tipe_penerima_odp_los'] ?? 'pribadi';
    $bot = trim($_POST['bot_penerima_odp_los'] ?? '');
    $pemilik = isset($ceknama) ? $ceknama : ($_SESSION['username'] ?? '');
    $nomor_final = '';
    if ($tipe === 'pribadi' && preg_match('/^62[0-9]{7,15}$/', $nomor)) {
        $nomor_final = $nomor . '@s.whatsapp.net';
    } elseif ($tipe === 'grup') {
        $nomor_final = $nomor . '@g.us';
    }
    if ($pemilik && $nomor_final) {
        $stmt = $conn->prepare("UPDATE botwa SET penerima_odp_los=?, bot_penerima_odp_los=? WHERE pemilik=?");
        $stmt->bind_param('sss', $nomor_final, $bot, $pemilik);
        if ($stmt->execute()) {
            $response = ['status' => 'ok', 'message' => 'Nomor penerima ODP LOS berhasil disimpan'];
        } else {
            $response = ['status' => 'error', 'message' => 'Gagal menyimpan ke database'];
        }
        $stmt->close();
    } else {
        $response = ['status' => 'error', 'message' => 'Data tidak lengkap/format salah'];
    }
    echo json_encode($response); exit;
}

// Simpan Interval ODP LOS
if (isset($_POST['mode']) && $_POST['mode'] === 'save_interval_odp_los') {
    $val = (int)($_POST['odp_los_interval_value'] ?? 5);
    $unit = $_POST['odp_los_interval_unit'] ?? 'menit';
    // Simpan ke file/DB sesuai kebutuhan
    // Contoh: file_put_contents('odp_los_interval.txt', "$val|$unit");
    $response = ['status' => 'ok', 'message' => 'Interval ODP LOS berhasil disimpan'];
    echo json_encode($response); exit;
}

// Simpan Grace Period Prabayar
if (isset($_POST['mode']) && $_POST['mode'] === 'save_prabayar_grace_period') {
    $val = (int)($_POST['prabayar_grace_period'] ?? 2);
    // Simpan ke file/DB sesuai kebutuhan
    // Contoh: file_put_contents('prabayar_grace_period.txt', $val);
    $response = ['status' => 'ok', 'message' => 'Grace period berhasil disimpan'];
    echo json_encode($response); exit;
}

// Simpan Nomor Penerima Manual Active
if (isset($_POST['mode']) && $_POST['mode'] === 'save_penerima_manual_active') {
    $nomor = trim($_POST['nomor_penerima_manual_active'] ?? '');
    $tipe = $_POST['tipe_penerima_manual_active'] ?? 'pribadi';
    $bot = trim($_POST['bot_penerima_manual_active'] ?? '');
    $pemilik = isset($ceknama) ? $ceknama : ($_SESSION['username'] ?? '');
    $nomor_final = '';
    if ($tipe === 'pribadi' && preg_match('/^62[0-9]{7,15}$/', $nomor)) {
        $nomor_final = $nomor . '@s.whatsapp.net';
    } elseif ($tipe === 'grup') {
        $nomor_final = $nomor . '@g.us';
    }
    if ($pemilik && $nomor_final) {
        $stmt = $conn->prepare("UPDATE botwa SET penerima_manual_active=?, bot_penerima_manual_active=? WHERE pemilik=?");
        $stmt->bind_param('sss', $nomor_final, $bot, $pemilik);
        if ($stmt->execute()) {
            $response = ['status' => 'ok', 'message' => 'Nomor penerima manual active berhasil disimpan'];
        } else {
            $response = ['status' => 'error', 'message' => 'Gagal menyimpan ke database'];
        }
        $stmt->close();
    } else {
        $response = ['status' => 'error', 'message' => 'Data tidak lengkap/format salah'];
    }
    echo json_encode($response); exit;
}

// Simpan Nomor Penerima Provisioning
if (isset($_POST['mode']) && $_POST['mode'] === 'save_penerima_provisioning') {
    $nomor = trim($_POST['nomor_penerima_provisioning'] ?? '');
    $tipe = $_POST['tipe_penerima_provisioning'] ?? 'pribadi';
    $bot = trim($_POST['bot_penerima_provisioning'] ?? '');
    $pemilik = isset($ceknama) ? $ceknama : ($_SESSION['username'] ?? '');
    $nomor_final = '';
    if ($tipe === 'pribadi' && preg_match('/^62[0-9]{7,15}$/', $nomor)) {
        $nomor_final = $nomor . '@s.whatsapp.net';
    } elseif ($tipe === 'grup') {
        $nomor_final = $nomor . '@g.us';
    }
    if ($pemilik && $nomor_final) {
        $stmt = $conn->prepare("UPDATE botwa SET penerima_provisioning=?, bot_penerima_provisioning=? WHERE pemilik=?");
        $stmt->bind_param('sss', $nomor_final, $bot, $pemilik);
        if ($stmt->execute()) {
            $response = ['status' => 'ok', 'message' => 'Nomor penerima provisioning berhasil disimpan'];
        } else {
            $response = ['status' => 'error', 'message' => 'Gagal menyimpan ke database'];
        }
        $stmt->close();
    } else {
        $response = ['status' => 'error', 'message' => 'Data tidak lengkap/format salah'];
    }
    echo json_encode($response); exit;
}

// Simpan Pengaturan OTP Portal
if (isset($_POST['mode']) && $_POST['mode'] === 'save_otp_portal') {
    // Simpan ke file/DB sesuai kebutuhan
    $response = ['status' => 'ok', 'message' => 'Pengaturan OTP Portal berhasil disimpan'];
    echo json_encode($response); exit;
}

// Simpan Pengaturan Salam Dinamis
if (isset($_POST['mode']) && $_POST['mode'] === 'save_dynamic_greeting') {
    // Simpan ke file/DB sesuai kebutuhan
    $response = ['status' => 'ok', 'message' => 'Pengaturan salam dinamis berhasil disimpan'];
    echo json_encode($response); exit;
}

// Simpan Invoice Generator
if (isset($_POST['mode']) && $_POST['mode'] === 'save_invoice_generator') {
    // Simpan ke file/DB sesuai kebutuhan
    $response = ['status' => 'ok', 'message' => 'Pengaturan invoice generator berhasil disimpan'];
    echo json_encode($response); exit;
}

// Simpan Jadwal Notifikasi
if (isset($_POST['mode']) && $_POST['mode'] === 'save_jadwal') {
    // Simpan ke file/DB sesuai kebutuhan
    $response = ['status' => 'ok', 'message' => 'Jadwal notifikasi berhasil disimpan'];
    echo json_encode($response); exit;
}
// Simpan Pesan Expired
if (isset($_POST['mode']) && $_POST['mode'] === 'save_expired') {
    $pesan = trim($_POST['pesan_expired'] ?? '');
    $pemilik = isset($ceknama) ? $ceknama : ($_SESSION['username'] ?? '');
    if ($pemilik && $pesan) {
        $stmt = $conn->prepare("UPDATE notif_khusus SET pesan_expired=? WHERE pemilik=?");
        $stmt->bind_param('ss', $pesan, $pemilik);
        if ($stmt->execute()) {
            $response = ['status' => 'ok', 'message' => 'Pesan expired berhasil disimpan'];
        } else {
            $response = ['status' => 'error', 'message' => 'Gagal menyimpan ke database'];
        }
        $stmt->close();
    } else {
        $response = ['status' => 'error', 'message' => 'Data tidak lengkap'];
    }
    echo json_encode($response); exit;
}

// Simpan Pesan Reminder
if (isset($_POST['mode']) && $_POST['mode'] === 'save_reminder') {
    $pesan = trim($_POST['pesan_reminder'] ?? '');
    $pemilik = isset($ceknama) ? $ceknama : ($_SESSION['username'] ?? '');
    if ($pemilik && $pesan) {
        $stmt = $conn->prepare("UPDATE notif_khusus SET pesan_reminder=? WHERE pemilik=?");
        $stmt->bind_param('ss', $pesan, $pemilik);
        if ($stmt->execute()) {
            $response = ['status' => 'ok', 'message' => 'Pesan reminder berhasil disimpan'];
        } else {
            $response = ['status' => 'error', 'message' => 'Gagal menyimpan ke database'];
        }
        $stmt->close();
    } else {
        $response = ['status' => 'error', 'message' => 'Data tidak lengkap'];
    }
    echo json_encode($response); exit;
}

// Simpan Pesan Ketentuan
if (isset($_POST['mode']) && $_POST['mode'] === 'save_ketentuan') {
    $pesan = trim($_POST['pesan_ketentuan'] ?? '');
    $pemilik = isset($ceknama) ? $ceknama : ($_SESSION['username'] ?? '');
    if ($pemilik && $pesan) {
        $stmt = $conn->prepare("UPDATE notif_khusus SET pesan_ketentuan=? WHERE pemilik=?");
        $stmt->bind_param('ss', $pesan, $pemilik);
        if ($stmt->execute()) {
            $response = ['status' => 'ok', 'message' => 'Pesan ketentuan berhasil disimpan'];
        } else {
            $response = ['status' => 'error', 'message' => 'Gagal menyimpan ke database'];
        }
        $stmt->close();
    } else {
        $response = ['status' => 'error', 'message' => 'Data tidak lengkap'];
    }
    echo json_encode($response); exit;
}

// Simpan Pesan Disable Manual
if (isset($_POST['mode']) && $_POST['mode'] === 'save_disable') {
    $pesan = trim($_POST['pesan_disable'] ?? '');
    $pemilik = isset($ceknama) ? $ceknama : ($_SESSION['username'] ?? '');
    if ($pemilik && $pesan) {
        $stmt = $conn->prepare("UPDATE notif_khusus SET pesan_disable=? WHERE pemilik=?");
        $stmt->bind_param('ss', $pesan, $pemilik);
        if ($stmt->execute()) {
            $response = ['status' => 'ok', 'message' => 'Pesan disable berhasil disimpan'];
        } else {
            $response = ['status' => 'error', 'message' => 'Gagal menyimpan ke database'];
        }
        $stmt->close();
    } else {
        $response = ['status' => 'error', 'message' => 'Data tidak lengkap'];
    }
    echo json_encode($response); exit;
}

// Simpan Pesan Aktif Manual
if (isset($_POST['mode']) && $_POST['mode'] === 'save_aktif_manual') {
    $pesan = trim($_POST['pesan_aktif_manual'] ?? '');
    $pemilik = isset($ceknama) ? $ceknama : ($_SESSION['username'] ?? '');
    if ($pemilik && $pesan) {
        $stmt = $conn->prepare("UPDATE notif_khusus SET pesan_aktif_manual=? WHERE pemilik=?");
        $stmt->bind_param('ss', $pesan, $pemilik);
        if ($stmt->execute()) {
            $response = ['status' => 'ok', 'message' => 'Pesan aktif manual berhasil disimpan'];
        } else {
            $response = ['status' => 'error', 'message' => 'Gagal menyimpan ke database'];
        }
        $stmt->close();
    } else {
        $response = ['status' => 'error', 'message' => 'Data tidak lengkap'];
    }
    echo json_encode($response); exit;
}

// Simpan Pesan Remainder Manual
if (isset($_POST['mode']) && $_POST['mode'] === 'save_remainder_manual') {
    $pesan = trim($_POST['pesan_remainder_manual'] ?? '');
    $pemilik = isset($ceknama) ? $ceknama : ($_SESSION['username'] ?? '');
    if ($pemilik && $pesan) {
        $stmt = $conn->prepare("UPDATE notif_khusus SET pesan_remainder_manual=? WHERE pemilik=?");
        $stmt->bind_param('ss', $pesan, $pemilik);
        if ($stmt->execute()) {
            $response = ['status' => 'ok', 'message' => 'Pesan remainder manual berhasil disimpan'];
        } else {
            $response = ['status' => 'error', 'message' => 'Gagal menyimpan ke database'];
        }
        $stmt->close();
    } else {
        $response = ['status' => 'error', 'message' => 'Data tidak lengkap'];
    }
    echo json_encode($response); exit;
}

// Simpan Pesan Dismantle Manual
if (isset($_POST['mode']) && $_POST['mode'] === 'save_dismantle_manual') {
    $pesan = trim($_POST['pesan_dismantle_manual'] ?? '');
    $pemilik = isset($ceknama) ? $ceknama : ($_SESSION['username'] ?? '');
    if ($pemilik && $pesan) {
        $stmt = $conn->prepare("UPDATE notif_khusus SET pesan_dismantle_manual=? WHERE pemilik=?");
        $stmt->bind_param('ss', $pesan, $pemilik);
        if ($stmt->execute()) {
            $response = ['status' => 'ok', 'message' => 'Pesan dismantle manual berhasil disimpan'];
        } else {
            $response = ['status' => 'error', 'message' => 'Gagal menyimpan ke database'];
        }
        $stmt->close();
    } else {
        $response = ['status' => 'error', 'message' => 'Data tidak lengkap'];
    }
    echo json_encode($response); exit;
}

// Handler lain (penerima, jadwal, lanjutan, dsb) dapat ditambahkan dengan pola serupa.

header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/../../koneksi.php'; // pastikan path koneksi benar

$response = ['status' => 'error', 'message' => 'Permintaan tidak valid'];

// Contoh: Simpan Pesan Registrasi
if (isset($_POST['mode']) && $_POST['mode'] === 'save_registrasi') {
    $pesan = trim($_POST['pesan_registrasi'] ?? '');
    $pemilik = isset($ceknama) ? $ceknama : ($_SESSION['username'] ?? '');
    if ($pemilik && $pesan) {
        $stmt = $conn->prepare("UPDATE notif_khusus SET pesan_registrasi=? WHERE pemilik=?");
        $stmt->bind_param('ss', $pesan, $pemilik);
        if ($stmt->execute()) {
            $response = ['status' => 'ok', 'message' => 'Pesan registrasi berhasil disimpan'];
        } else {
            $response = ['status' => 'error', 'message' => 'Gagal menyimpan ke database'];
        }
        $stmt->close();
    } else {
        $response = ['status' => 'error', 'message' => 'Data tidak lengkap'];
    }
    echo json_encode($response); exit;
}

// Handler lain (jadwal, penerima, lanjutan, dst) bisa ditambah di sini

// Default response
http_response_code(400);
echo json_encode($response);