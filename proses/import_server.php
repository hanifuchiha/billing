<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require '../cek-sesi.php';
require('../routeros_api.class.php');

// Fungsi yang sama seperti addserver.php
function generateMikrotikCredentials($base_username = '') {
    if (empty($base_username)) {
        $base_username = 'user_' . date('YmdHis') . '_' . rand(100, 999);
    }
    
    // Generate username unik
    $username = $base_username . '_' . uniqid();
    
    // Generate password acak yang kuat
    $password = generateRandomPassword(12);
    
    return [
        'username' => $username,
        'password' => $password
    ];
}

function generateRandomPassword($length = 12) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $password;
}

function validateUniqueOwner($conn, $owner) {
    $sqlCheck = "SELECT id FROM server WHERE PEMILIK = '" . mysqli_real_escape_string($conn, $owner) . "' LIMIT 1";
    $res = mysqli_query($conn, $sqlCheck);
    
    if (!$res) {
        throw new Exception("Query error: " . mysqli_error($conn));
    }
    
    return mysqli_num_rows($res) == 0;
}

function createMikrotikSystemUser($API, $username, $password) {
    try {
        // Cek apakah user sudah ada
        $existing_user = $API->comm("/user/print", ["?name" => $username]);
        
        if (!empty($existing_user)) {
            throw new Exception("User sudah ada di MikroTik");
        }
        
        // Buat system user baru di MikroTik
        $result = $API->comm("/user/add", [
            "name" => $username,
            "password" => $password,
            "group" => "full", // atau sesuai kebutuhan
            "disabled" => "no",
            "comment" => "Auto-generated system user - " . date('Y-m-d H:i:s')
        ]);
        
        if (isset($result["!trap"])) {
            throw new Exception("Gagal membuat user: " . print_r($result, true));
        }
        
        return true;
    } catch (Exception $e) {
        throw new Exception("Error creating MikroTik user: " . $e->getMessage());
    }
}

// Konfigurasi upload
$maxFileSize = 10 * 1024 * 1024; // 10MB
$allowedExtensions = ['xlsx'];
$uploadDir = '../uploads/';

// Buat direktori upload jika belum ada
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$response = [
    'success' => false,
    'message' => '',
    'errors' => [],
    'imported_count' => 0,
    'failed_count' => 0,
    'details' => []
];

try {
    // Validasi file upload
    if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('File upload gagal atau tidak ada file yang dipilih');
    }

    $file = $_FILES['excel_file'];
    $fileName = $file['name'];
    $fileSize = $file['size'];
    $fileTmp = $file['tmp_name'];
    $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    // Validasi ekstensi file
    if (!in_array($fileExt, $allowedExtensions)) {
        throw new Exception('Format file tidak didukung. Gunakan .xlsx');
    }

    // Validasi ukuran file
    if ($fileSize > $maxFileSize) {
        throw new Exception('File terlalu besar. Maksimal 10MB');
    }

    // Pindahkan file ke direktori upload
    $uploadPath = $uploadDir . uniqid() . '_' . $fileName;
    if (!move_uploaded_file($fileTmp, $uploadPath)) {
        throw new Exception('Gagal menyimpan file upload');
    }

    // Load library untuk membaca Excel
    if (!class_exists('SimpleXLSX')) {
        require_once '../libs/SimpleXLSX.php';
    }
    
    // Konversi Excel ke array data
    $data = [];

    if ($xlsx = SimpleXLSX::parse($uploadPath)) {
        $data = $xlsx->rows();
        error_log("DEBUG: XLSX parsed successfully. Rows: " . count($data));
    } else {
        throw new Exception('Gagal membaca file XLSX: ' . SimpleXLSX::parseError());
    }

    if (empty($data)) {
        error_log("DEBUG: Data array kosong setelah parsing Excel");
        throw new Exception('File Excel kosong atau gagal dibaca');
    }
    
    // Filter out empty rows
    $data = array_filter($data, function($row) {
        return !empty(array_filter($row, function($cell) {
            return !empty(trim($cell));
        }));
    });
    
    if (empty($data)) {
        error_log("DEBUG: Semua baris kosong setelah filtering");
        throw new Exception('File Excel tidak mengandung data yang valid');
    }
    
    error_log("DEBUG: Data valid ditemukan: " . count($data) . " baris");

    // Opsi dari form
    $skipFirstRow = isset($_POST['skip_first_row']);
    $testConnection = isset($_POST['test_connection']);

    // Debug: Log data awal
    error_log("DEBUG: Total baris dari Excel: " . count($data));
    error_log("DEBUG: Skip first row: " . ($skipFirstRow ? 'YES' : 'NO'));

    // Skip header jika diperlukan
    if ($skipFirstRow && !empty($data)) {
        array_shift($data);
        error_log("DEBUG: Setelah skip header, tersisa: " . count($data) . " baris");
    }

    // Debug: Tampilkan sample data
    if (!empty($data)) {
        error_log("DEBUG: Sample row pertama: " . print_r($data[0], true));
    }

    // Validasi struktur data - format template: Brand, Area, IP Address, API Port, Web Port, Username, Password
    $requiredColumns = 7; // Brand, Area, IP, API Port, Web Port, Username, Password
    $structural_errors = [];
    
    foreach ($data as $rowIndex => $row) {
        if (count($row) < $requiredColumns) {
            $structural_errors[] = "Baris " . ($rowIndex + 1) . ": Data tidak lengkap (harus ada $requiredColumns kolom: Brand, Area, IP Address, API Port, Web Port, Username, Password)";
        }
    }

    if (!empty($structural_errors)) {
        error_log("DEBUG: Structural errors: " . print_r($structural_errors, true));
        throw new Exception('Terdapat error dalam struktur data: ' . implode(', ', $structural_errors));
    }

    error_log("DEBUG: Validasi struktur passed, akan memproses " . count($data) . " baris");

    // Proses import data (sama seperti addserver.php)
    $API = new RouterosAPI();
    $config_file = '../config.json';
    $config = file_exists($config_file) ? json_decode(file_get_contents($config_file), true) : [];

    // Ambil data server list satu kali di awal (sama seperti addserver.php)
    $data_lama = $server_list_JOSN;
    $jsonDatalama = json_decode($data_lama);
    if (!is_array($jsonDatalama)) {
        $jsonDatalama = [];
    }

    foreach ($data as $rowIndex => $row) {
        $rowNum = $rowIndex + 1;
        error_log("DEBUG: Memproses baris $rowNum");
        
        try {
            // Ekstrak data dari row - format template: Brand, Area, IP Address, API Port, Web Port, Username, Password
            $input_brand = trim($row[0] ?? '');
            $input_area = trim($row[1] ?? '');
            $input_ipaddr = trim($row[2] ?? '');
            $input_portapi = (int) trim($row[3] ?? '0');
            $input_portwebfig = (int) trim($row[4] ?? '0');
            $input_mikrotik_admin_user = trim($row[5] ?? ''); // Username admin MikroTik yang ada
            $input_mikrotik_admin_pass = trim($row[6] ?? ''); // Password admin MikroTik yang ada

            // Debug extracted data
            error_log("DEBUG: Baris $rowNum - Brand: '$input_brand', Area: '$input_area', IP: '$input_ipaddr', API Port: '$input_portapi', Web Port: '$input_portwebfig', User: '$input_mikrotik_admin_user'");

            // Validasi data
            if (empty($input_brand) || empty($input_area) || empty($input_ipaddr) || 
                empty($input_portapi) || empty($input_portwebfig) || 
                empty($input_mikrotik_admin_user) || empty($input_mikrotik_admin_pass)) {
                
                $missing_fields = [];
                if (empty($input_brand)) $missing_fields[] = 'Brand';
                if (empty($input_area)) $missing_fields[] = 'Area';
                if (empty($input_ipaddr)) $missing_fields[] = 'IP Address';
                if (empty($input_portapi)) $missing_fields[] = 'API Port';
                if (empty($input_portwebfig)) $missing_fields[] = 'Web Port';
                if (empty($input_mikrotik_admin_user)) $missing_fields[] = 'Username';
                if (empty($input_mikrotik_admin_pass)) $missing_fields[] = 'Password';
                
                throw new Exception("Baris $rowNum: Field kosong - " . implode(', ', $missing_fields));
            }

            // Validasi IP
            if (!filter_var($input_ipaddr, FILTER_VALIDATE_IP) && !filter_var(gethostbyname($input_ipaddr), FILTER_VALIDATE_IP)) {
                throw new Exception("Baris $rowNum: IP Address tidak valid");
            }

            // Validasi port
            if ($input_portapi < 1 || $input_portapi > 65535) {
                throw new Exception("Baris $rowNum: API Port tidak valid");
            }

            $ipPort = $input_ipaddr . ":" . $input_portapi;

            // Step 1: Connect ke MikroTik menggunakan admin credentials yang ada (sama seperti addserver.php)
            $API = new RouterosAPI();
            error_log("DEBUG: Baris $rowNum - Mencoba koneksi ke $ipPort dengan user: $input_mikrotik_admin_user");
            
            if (!$API->connect($ipPort, $input_mikrotik_admin_user, $input_mikrotik_admin_pass)) {
                error_log("DEBUG: Baris $rowNum - Koneksi gagal ke $ipPort");
                throw new Exception("Baris $rowNum: Gagal koneksi ke MikroTik dengan IP: $ipPort. Periksa IP, port, username dan password admin.");
            }
            
            error_log("DEBUG: Baris $rowNum - Koneksi berhasil ke $ipPort");

            // Step 2: Generate credentials baru untuk system user (sama seperti addserver.php)
            $new_credentials = generateMikrotikCredentials($input_brand . '_' . $input_area);
            $new_username = $new_credentials['username'];
            $new_password = $new_credentials['password'];

            // Step 3: Validasi agar username baru tidak ada di database (sama seperti addserver.php)
            $attempt = 0;
            $max_attempts = 10;

            while ($attempt < $max_attempts) {
                if (validateUniqueOwner($conn, $new_username)) {
                    break; // Username unik, bisa digunakan
                }

                // Generate ulang jika tidak unik
                $new_credentials = generateMikrotikCredentials($input_brand . '_' . $input_area);
                $new_username = $new_credentials['username'];
                $new_password = $new_credentials['password'];
                $attempt++;
            }

            if ($attempt >= $max_attempts) {
                throw new Exception("Baris $rowNum: Gagal generate username unik setelah $max_attempts percobaan");
            }

            // Step 4: Buat system user baru di MikroTik (sama seperti addserver.php)
            createMikrotikSystemUser($API, $new_username, $new_password);

            // Step 5: Test koneksi dengan user baru (sama seperti addserver.php)
            $testAPI = new RouterosAPI();
            if (!$testAPI->connect($ipPort, $new_username, $new_password)) {
                throw new Exception("Baris $rowNum: User berhasil dibuat tapi gagal test koneksi dengan user baru");
            }
            $testAPI->disconnect();

            // Step 6: Update user server list (sama seperti addserver.php)
            $jsonDatalama[] = $new_username;
            $data_json_update = json_encode($jsonDatalama);

            // Step 7: Update user data (sama seperti addserver.php)
            $sql_update = "UPDATE `user` SET `server` = '" . mysqli_real_escape_string($conn, $data_json_update) . "' WHERE `USERNAME` = '" . mysqli_real_escape_string($conn, $ceknama) . "'";
            if (!mysqli_query($conn, $sql_update)) {
                throw new Exception("Baris $rowNum: Gagal update user data: " . mysqli_error($conn));
            }

            // Step 8: Insert server data dengan credentials baru (sama seperti addserver.php)
            $sql1 = "INSERT INTO `server` (`IP`, `PASSWORD`, `MAP`, `BOTWA`, `NAS`, `AREA`, `OLT`, `MIK80`, `TEMPO`, `PAJAK`, `TRIPAY`, `PEMILIK`, `BRAND`)
                     VALUES ('" . mysqli_real_escape_string($conn, $ipPort) . "', 
                             '" . mysqli_real_escape_string($conn, $new_password) . "',
                             '', '', '', 
                             '" . mysqli_real_escape_string($conn, $input_area) . "', 
                             '', 
                             '" . mysqli_real_escape_string($conn, $input_portwebfig) . "',
                             '', '', '', 
                             '" . mysqli_real_escape_string($conn, $new_username) . "', 
                             '" . mysqli_real_escape_string($conn, $input_brand) . "')";

            if (!mysqli_query($conn, $sql1)) {
                throw new Exception("Baris $rowNum: Gagal insert server data: " . mysqli_error($conn));
            }

            // Step 9: Setup RADIUS dan konfigurasi lainnya menggunakan user baru (sama seperti addserver.php)
            if ($API->connect($ipPort, $new_username, $new_password)) {
                // Check if RADIUS with same IP exists (sama seperti addserver.php)
                $existing = $API->comm("/radius/print", ["?address" => $config['webiplocal']]);

                if (empty($existing)) {
                    $result = $API->comm("/radius/add", [
                        "service" => "ppp,login,hotspot",
                        "address" => $config['webiplocal'],
                        "secret" => "crmradius",
                        "disabled" => "no"
                    ]);
                }

                // Update all Hotspot Server Profiles (sama seperti addserver.php)
                $profiles = $API->comm("/ip/hotspot/profile/print");
                if (!isset($profiles[0]["!trap"])) {
                    foreach ($profiles as $profile) {
                        $set = $API->comm("/ip/hotspot/profile/set", [
                            ".id" => $profile[".id"],
                            "use-radius" => "yes",
                            "radius-accounting" => "yes",
                        ]);
                    }
                }

                // Get client IP/host (sama seperti addserver.php)
                $parts = explode(":", $ipPort, 2);
                $host = $parts[0];
                $client_name = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);

                // Format FreeRADIUS client entry (sama seperti addserver.php)
                $client_entry = "\nclient $client_name {\n" .
                    "    ipaddr = $client_name\n" .
                    "    secret = crmradius\n" .
                    "    require_message_authenticator = no\n" .
                    "    nastype = other\n" .
                    "}\n";

                // Write to FreeRADIUS config and restart service (sama seperti addserver.php)
                $cmd = "echo '$client_entry' | sudo tee -a /etc/freeradius/3.0/clients.conf > /dev/null";
                exec($cmd, $output, $return_var);

                if ($return_var === 0) {
                    exec("sudo systemctl restart freeradius", $output, $return_var);
                }
            }

            $history_file = "../notifbot/data/history-$ceknama.json";
            $history = [];
            if (file_exists($history_file)) {
                $history = json_decode(file_get_contents($history_file), true);
            }
            if (!is_array($history)) $history = [];
            $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Berhasil mengimport server '$input_brand' ($input_area)";
            file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

            $response['imported_count']++;
            $success_msg = "Baris $rowNum: Berhasil import server $input_brand ($input_area)";
            $success_msg .= " - Username: $new_username (generated)";
            $response['details'][] = $success_msg;
            
            error_log("DEBUG: SUCCESS - Baris $rowNum berhasil diimport. Total sukses: " . $response['imported_count']);

        } catch (Exception $e) {
            $response['failed_count']++;
            $response['errors'][] = $e->getMessage();
            $response['details'][] = "Baris $rowNum: ERROR - " . $e->getMessage();
            
            error_log("DEBUG: ERROR - Baris $rowNum gagal: " . $e->getMessage() . ". Total gagal: " . $response['failed_count']);
        }
    }

    // Hapus file upload
    if (file_exists($uploadPath)) {
        unlink($uploadPath);
    }

    // Debug final result
    error_log("DEBUG: Final result - Imported: " . $response['imported_count'] . ", Failed: " . $response['failed_count']);
    error_log("DEBUG: Errors: " . print_r($response['errors'], true));
    error_log("DEBUG: Details: " . print_r($response['details'], true));

    if ($response['imported_count'] > 0) {
        $response['success'] = true;
        $response['message'] = "Import selesai! {$response['imported_count']} server berhasil diimport";
        if ($response['failed_count'] > 0) {
            $response['message'] .= ", {$response['failed_count']} server gagal";
        }
    } else {
        $error_details = !empty($response['errors']) ? implode('; ', $response['errors']) : 'Tidak ada detail error';
        error_log("DEBUG: No servers imported. Error details: " . $error_details);
        throw new Exception('Tidak ada server yang berhasil diimport. Detail: ' . $error_details);
    }

} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    $response['success'] = false;
}

// Redirect dengan hasil
if ($response['success']) {
    $msg = urlencode($response['message']);
    header("Location: ../import_server.php?status=sukses&msg=$msg");
} else {
    $msg = urlencode($response['message']);
    header("Location: ../import_server.php?status=error&msg=$msg");
}
exit;
?>