<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

require '../cek-sesi.php';
require('../routeros_api.class.php');
require_once __DIR__ . '/../radius_sync_lib.php';
require_once __DIR__ . '/../mikrotik_credentials_helper.php';

// Validasi input POST
if (!isset($_POST['add']) || $_POST['add'] != 'add') {
    header("Location: ../server.php");
    exit;
}

if (!isset($_POST['brand'], $_POST['ipaddr'], $_POST['portapi'], $_POST['portwebfig'], $_POST['product'], $_POST['password'])) {
    header("Location: ../server.php?status=error&msg=" . urlencode("Data form tidak lengkap"));
    exit;
}

// Ambil input dari form
$input_brand = $_POST['brand']; // allow spaces in brand
$input_area = htmlspecialchars($_POST['area']);
$input_area_username = str_replace(' ', '_', $input_area); // Ganti spasi dengan underscore untuk username
$input_ipaddr = htmlspecialchars($_POST['ipaddr']);
$input_portapi = (int) htmlspecialchars($_POST['portapi']);
$input_portwebfig = (int) htmlspecialchars($_POST['portwebfig']);
$input_mikrotik_admin_user = htmlspecialchars($_POST['product']); // Username admin MikroTik yang ada
$input_mikrotik_admin_pass = htmlspecialchars($_POST['password']); // Password admin MikroTik yang ada
$input_connection_mode = ($_POST['connection_mode'] ?? 'API') === 'RADIUS_ONLY' ? 'RADIUS_ONLY' : 'API';
$input_coordinates = trim((string)($_POST['coordinates'] ?? ''));
if ($input_coordinates !== '' && !preg_match('/^-?\d{1,3}(\.\d+)?,-?\d{1,3}(\.\d+)?$/', $input_coordinates)) {
    $input_coordinates = '';
}

$ipPort = $input_ipaddr . ":" . $input_portapi;

// Simpan konfigurasi
$config_file = '../config.json';
$config = file_exists($config_file) ? json_decode(file_get_contents($config_file), true) : [];

// ============================================================
// Mode RADIUS SAJA -- TIDAK ADA koneksi API ke router sama sekali.
// Cukup buat kunci internal (PEMILIK) + secret RADIUS, simpan ke DB, daftarkan
// NAS client-nya di FreeRADIUS, lalu tampilkan skrip MikroTik untuk di-paste
// manual oleh admin. Lihat radiusGenerateMikrotikScript() di radius_sync_lib.php.
// ============================================================
if ($input_connection_mode === 'RADIUS_ONLY') {
    if (!filter_var($input_ipaddr, FILTER_VALIDATE_IP)) {
        header("Location: ../server.php?status=error&msg=" . urlencode("IP tidak valid untuk mode RADIUS SAJA (domain belum didukung untuk mode ini)."));
        exit;
    }

    $new_key = generateMikrotikCredentials($input_brand . '_' . $input_area_username)['username'];
    $attempt = 0;
    while ($attempt < 10 && !validateUniqueOwner($conn, $new_key)) {
        $new_key = generateMikrotikCredentials($input_brand . '_' . $input_area_username)['username'];
        $attempt++;
    }

    $radius_secret = radiusGenerateSecret(24);

    $stmt = mysqli_prepare($conn, "INSERT INTO `server` (`IP`, `PASSWORD`, `AREA`, `MIK80`, `PEMILIK`, `BRAND`, `user_id`, `CONNECTION_MODE`, `TIKOR`) VALUES (?, ?, ?, ?, ?, ?, ?, 'RADIUS_ONLY', ?)");
    mysqli_stmt_bind_param($stmt, "ssssssis", $input_ipaddr, $radius_secret, $input_area, $input_portwebfig, $new_key, $input_brand, $current_user_id, $input_coordinates);
    if (!mysqli_stmt_execute($stmt)) {
        header("Location: ../server.php?status=error&msg=" . urlencode("Gagal menyimpan server: " . mysqli_error($conn)));
        exit;
    }
    $new_server_id = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    // Daftarkan NAS client untuk router ini di FreeRADIUS (clients.conf) +
    // restart supaya langsung aktif. Nama client = IP router (bukan kunci
    // PEMILIK) supaya konsisten dengan pembersihan client lama berbasis IP
    // di proses/editserver.php (lihat langkah "Hapus client lama").
    radiusUpsertNasClient($input_ipaddr, $input_ipaddr, $radius_secret);
    radiusReloadIfChanged(true);

    // log history
    $history_file = "../notifbot/data/history-$ceknama.json";
    $history = [];
    if (file_exists($history_file)) {
        $history = json_decode(file_get_contents($history_file), true);
    }
    if (!is_array($history)) { $history = []; }
    $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Berhasil menambahkan server baru $new_key (mode RADIUS SAJA) untuk area $input_area";
    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

    header("Location: ../server.php?status=sukses&msg=" . urlencode("Server $input_brand ($input_ipaddr) berhasil disimpan dengan mode RADIUS SAJA.") . "&radius_script_for=" . $new_server_id);
    exit;
}

// Proses add server dengan debug echo di setiap langkah
try {
    echo "<pre>";
    echo "[DEBUG] Mulai proses add server\n";
    $data_lama = isset($server_list_JOSN) ? $server_list_JOSN : null;
    $ceknama = isset($ceknama) ? $ceknama : '';
    echo "[DEBUG] Input brand: $input_brand\n";
    echo "[DEBUG] Input area: $input_area\n";
    echo "[DEBUG] Input ipaddr: $input_ipaddr\n";
    echo "[DEBUG] Input portapi: $input_portapi\n";
    echo "[DEBUG] Input portwebfig: $input_portwebfig\n";
    echo "[DEBUG] Input mikrotik admin user: $input_mikrotik_admin_user\n";
    echo "[DEBUG] Input mikrotik admin pass: $input_mikrotik_admin_pass\n";
    echo "[DEBUG] ipPort: $ipPort\n";
    
    $API = new RouterosAPI();
    echo "[DEBUG] RouterosAPI instance created\n";
    
    $host_info = parse_url("//$ipPort");
    $host = $host_info['host'] ?? $input_ipaddr;
    $port = $input_portapi; // Selalu gunakan input port API
    echo "[DEBUG] Host: $host, Port: $port\n";
    
    if (!filter_var($host, FILTER_VALIDATE_IP)) {
        $resolved_ip = gethostbyname($host);
        echo "[DEBUG] Host is domain, resolved to: $resolved_ip\n";
        if ($resolved_ip === $host) {
            echo "[ERROR] Gagal resolve hostname '$host' ke IP address.\n";
            throw new Exception("Gagal resolve hostname '$host' ke IP address. Periksa DNS atau gunakan IP langsung.");
        }
    }
    
    $max_retries = 3;
    $retry_delay = 1;
    $success = false;
    $last_error = '';
    for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
        echo "[DEBUG] Percobaan koneksi $attempt/$max_retries\n";
        $timeout = 5;
        $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if ($socket) {
            echo "[DEBUG] Port $port di $host terbuka\n";
            fclose($socket);
            if ($API->connect($ipPort, $input_mikrotik_admin_user, $input_mikrotik_admin_pass)) {
                echo "[DEBUG] API connect berhasil\n";
                $success = true;
                break;
            } else {
                echo "[ERROR] Gagal koneksi API ke $ipPort (Percobaan $attempt/$max_retries)\n";
                $last_error = "Gagal koneksi API ke $ipPort (Percobaan $attempt/$max_retries)";
            }
        } else {
            echo "[ERROR] Port $port di $host tidak dapat diakses - Error: $errstr (Percobaan $attempt/$max_retries)\n";
            $last_error = "Port $port di $host tidak dapat diakses - Error: $errstr (Percobaan $attempt/$max_retries)";
        }
        sleep($retry_delay);
    }
    if (!$success) {
        $troubleshoot = getMikrotikTroubleshootingInfo($host, $port, $input_mikrotik_admin_user);
        $debug_msg = $last_error . "\n\n";
        $debug_msg .= "TROUBLESHOOTING INFO:\n";
        $debug_msg .= "- Ping test: " . $troubleshoot['ping'] . "\n";
        $debug_msg .= "- Available ports: " . implode(', ', $troubleshoot['available_ports']) . "\n\n";
        $debug_msg .= "KEMUNGKINAN MASALAH:\n";
        if ($troubleshoot['ping'] === 'FAILED') {
            $debug_msg .= "❌ Host tidak dapat dijangkau - cek koneksi internet/network\n";
        }
        if (empty($troubleshoot['available_ports'])) {
            $debug_msg .= "❌ Port API tidak terbuka - cek firewall atau service API\n";
        } else if (!in_array($port, $troubleshoot['available_ports'])) {
            $debug_msg .= "❌ Port $port tidak tersedia, tapi port lain terbuka: " . implode(', ', $troubleshoot['available_ports']) . "\n";
        }
        $debug_msg .= "\nSOLUSI:\n";
        foreach ($troubleshoot['tips'] as $tip) {
            $debug_msg .= "• $tip\n";
        }
        echo "[ERROR] $debug_msg\n";
        echo "</pre>";
        throw new Exception($debug_msg);
    }
    
    echo "[DEBUG] Koneksi ke MikroTik sukses\n";
    $new_credentials = generateMikrotikCredentials($input_brand . '_' . $input_area_username);
    $new_username = $new_credentials['username'];
    $new_password = $new_credentials['password'];
    echo "[DEBUG] Generated new username: $new_username\n";
    echo "[DEBUG] Generated new password: $new_password\n";
    $attempt = 0;
    $max_attempts = 10;
    while ($attempt < $max_attempts) {
        echo "[DEBUG] Cek username unik attempt $attempt\n";
        if (validateUniqueOwner($conn, $new_username)) {
            echo "[DEBUG] Username unik di database\n";
            break;
        }
        echo "[DEBUG] Username sudah ada, generate ulang\n";
        $new_credentials = generateMikrotikCredentials($input_brand . '_' . $input_area_username);
        $new_username = $new_credentials['username'];
        $new_password = $new_credentials['password'];
        $attempt++;
    }
    if ($attempt >= $max_attempts) {
        echo "[ERROR] Gagal generate username unik setelah $max_attempts percobaan\n";
        echo "</pre>";
        throw new Exception("Gagal generate username unik setelah $max_attempts percobaan");
    }
    echo "[DEBUG] Membuat system user baru di MikroTik\n";
    createMikrotikSystemUser($API, $new_username, $new_password);
    echo "[DEBUG] System user baru berhasil dibuat\n";
    $testAPI = new RouterosAPI();
    if (!$testAPI->connect($ipPort, $new_username, $new_password)) {
        echo "[ERROR] User berhasil dibuat tapi gagal test koneksi dengan user baru\n";
        echo "</pre>";
        throw new Exception("User berhasil dibuat tapi gagal test koneksi dengan user baru");
    }
    $testAPI->disconnect();
    echo "[DEBUG] Test koneksi dengan user baru berhasil\n";
    $sql1 = "INSERT INTO `server` (`IP`, `PASSWORD`, `AREA`, `MIK80`, `PEMILIK`, `BRAND`,`user_id`,`TIKOR`)
             VALUES ('" . mysqli_real_escape_string($conn, $ipPort) . "',
                     '" . mysqli_real_escape_string($conn, $new_password) . "',
                     '" . mysqli_real_escape_string($conn, $input_area) . "',
                     '" . mysqli_real_escape_string($conn, $input_portwebfig) . "',
                     '" . mysqli_real_escape_string($conn, $new_username) . "',
                      '" . mysqli_real_escape_string($conn, $input_brand) . "',
                     '$current_user_id',
                     '" . mysqli_real_escape_string($conn, $input_coordinates) . "')";
                     
    echo "[DEBUG] SQL Insert: $sql1\n";
    if (!mysqli_query($conn, $sql1)) {
        echo "[ERROR] Gagal insert server data: " . mysqli_error($conn) . "\n";
        echo "</pre>";
        throw new Exception("Gagal insert server data: " . mysqli_error($conn));
    }
    echo "[DEBUG] Insert server data ke database berhasil\n";
    if ($API->connect($ipPort, $new_username, $new_password)) {
        echo "[DEBUG] Koneksi ulang ke MikroTik dengan user baru berhasil\n";
        $existing = $API->comm("/radius/print", ["?address" => $config['radius_ip']]);
        if (empty($existing)) {
            echo "[DEBUG] Menambahkan RADIUS baru\n";
            $result = $API->comm("/radius/add", [
                "service" => "ppp,login,hotspot",
                "address" => $config['radius_ip'],
                "secret" => !empty($config['radius_password']) ? $config['radius_password'] : 'crmradius',
                "disabled" => "no"
            ]);
        } else {
            echo "[DEBUG] RADIUS sudah ada\n";
        }
        $profiles = $API->comm("/ip/hotspot/profile/print");
        if (!isset($profiles[0]["!trap"])) {
            foreach ($profiles as $profile) {
                echo "[DEBUG] Update hotspot profile: " . $profile[".id"] . "\n";
                $set = $API->comm("/ip/hotspot/profile/set", [
                    ".id" => $profile[".id"],
                    "use-radius" => "yes",
                    "radius-accounting" => "yes",
                ]);
            }
        }
    }
    echo "[DEBUG] Semua proses selesai sukses\n";
    echo "</pre>";
    // log history
    $history_file = "../notifbot/data/history-$ceknama.json";
    $history = [];
    if (file_exists($history_file)) {
        $history = json_decode(file_get_contents($history_file), true);
    }
    if (!is_array($history)) { $history = []; }
    $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Berhasil menambahkan server baru $new_username untuk area $input_area";
    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    // Success - redirect dengan info user baru
    $success_url = "../server.php?status=sukses&new_user=" . urlencode($new_username) . "&generated=1";
    header("Location: $success_url");
    exit;
} catch (Exception $e) {
    // Error handling
    echo "<pre>[ERROR] " . $e->getMessage() . "</pre>";
    error_log("Addserver Error: " . $e->getMessage());
    $error_url = "../server.php?status=error&msg=" . urlencode($e->getMessage());
    header("Location: $error_url");
    exit;
}
?>