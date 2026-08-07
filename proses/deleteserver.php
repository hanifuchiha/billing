<?php
require '../cek-sesi.php';

$ip_port = $_GET['ip'] ?? '';
$ip = explode(':', $ip_port)[0];

$area = $_GET['area'] ?? '';
$pemilik = $_GET['pemilik'] ?? '';
$id = $_GET['id'] ?? ''; // pastikan id dikirim dari GET

if (!$ip || !$area || !$pemilik || !$id) {
    die("Parameter kurang lengkap.");
}

// 1. Hapus record dari tabel server dengan prepared statement
$stmt = $conn->prepare("DELETE FROM server WHERE id=? AND area=? AND pemilik=?");
$stmt->bind_param("iss", $id, $area, $pemilik);
$stmt->execute();
$stmt->close();

// 2. Cek apakah masih ada server lain dengan pemilik yang sama
$cek = $conn->prepare("SELECT COUNT(*) as total FROM server WHERE pemilik=?");
$cek->bind_param("s", $pemilik);
$cek->execute();
$result = $cek->get_result();
$row = $result->fetch_assoc();
$cek->close();

if ($row['total'] == 0) {
    // Hapus pemilik dari JSON user
    $user_result = $conn->query("SELECT USERNAME, server FROM user");
    while ($row = $user_result->fetch_assoc()) {
        $username = $row['USERNAME'];
        $servers = json_decode($row['server'], true);
        if (is_array($servers) && in_array($pemilik, $servers)) {
            $servers = array_values(array_diff($servers, [$pemilik]));
            $servers_json = json_encode($servers);
            $stmt = $conn->prepare("UPDATE user SET server=? WHERE USERNAME=?");
            $stmt->bind_param("ss", $servers_json, $username);
            $stmt->execute();
            $stmt->close();
        }
    }

    echo "Server berhasil dihapus. JSON user diupdate jika tidak ada server lain milik pemilik yang sama.";

    // 3. Hapus client dari FreeRADIUS
    $file_path = "/etc/freeradius/3.0/clients.conf";
    if (file_exists($file_path)) {
        $file_content = file_get_contents($file_path);
        $pattern = "/\s*client\s+" . preg_quote($ip, "/") . "\s*\{.*?\n\}/s";
        $new_content = preg_replace($pattern, "", $file_content);

        if (file_put_contents($file_path, $new_content) !== false) {
            echo "✅ Client berhasil dihapus!";

            // Restart FreeRADIUS
            exec("sudo systemctl restart freeradius", $output, $return_var);
            if ($return_var === 0) {
                echo "✅ RADIUS Success Restart <br>" . implode("<br>", $output);
            } else {
                echo "❌ RADIUS Error Restart";
            }
        } else {
            echo "❌ Gagal menghapus client!";
        }
    } else {
        echo "❌ File FreeRADIUS clients.conf tidak ditemukan!";
    }
}

// log history
$history_file = "../notifbot/data/history-$ceknama.json";
$history = [];
if (file_exists($history_file)) {
    $history = json_decode(file_get_contents($history_file), true);
}
if (!is_array($history)) { $history = []; }
$history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Berhasil menghapus server $pemilik untuk area $area";
file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

// Redirect setelah semua selesai
header('Location: ../server.php');
exit();
