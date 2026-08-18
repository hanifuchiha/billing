<?php
require '../cek-sesi.php';

// Set timezone dan error reporting
date_default_timezone_set('Asia/Jakarta');
ini_set('display_errors', 1);
error_reporting(E_ALL);

function ensureOdpImportColumns($conn) {
    $columns = [
        'Hirarki' => "VARCHAR(255) DEFAULT NULL",
        'splitter' => "ENUM('1:2', '1:4', '1:8', '1:16', '1:32') DEFAULT NULL",
        'hirarki_parent' => "VARCHAR(255) DEFAULT NULL"
    ];

    foreach ($columns as $column => $type) {
        $check = mysqli_query($conn, "SHOW COLUMNS FROM odp LIKE '$column'");
        if ($check && mysqli_num_rows($check) == 0) {
            mysqli_query($conn, "ALTER TABLE odp ADD COLUMN $column $type");
        }
    }
}

function normalizeHirarki($value) {
    $allowed = ['ODC', 'ODP', 'ODP-RASIO', 'ODP-JUMPER'];
    $value = strtoupper(trim((string)$value));
    return in_array($value, $allowed, true) ? $value : 'ODP';
}

function normalizeSplitter($value) {
    $allowed = ['1:2', '1:4', '1:8', '1:16', '1:32'];
    $value = trim((string)$value);
    return in_array($value, $allowed, true) ? $value : '';
}

try {
    ensureOdpImportColumns($conn);

    // Validasi file upload
    if (!isset($_FILES['kmz_file']) || $_FILES['kmz_file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('File tidak berhasil diupload. Error: ' . ($_FILES['kmz_file']['error'] ?? 'Unknown'));
    }
    
    $file = $_FILES['kmz_file'];
    $skip_duplicates = isset($_POST['skip_duplicates']);
    $default_server = $_POST['default_server'] ?? '';
    $default_area = $_POST['default_area'] ?? '';
    $kode_prefix = $_POST['kode_prefix'] ?? 'ODP-';
    $default_hirarki = normalizeHirarki($_POST['default_hirarki'] ?? 'ODP');
    $default_splitter = normalizeSplitter($_POST['default_splitter'] ?? '');
    $default_hirarki_parent = trim($_POST['default_hirarki_parent'] ?? '');

    if (in_array($default_hirarki, ['ODC', 'ODP-RASIO', 'ODP-JUMPER'], true)) {
        $default_hirarki_parent = '';
    }
    
    // Validasi required fields
    if (empty($default_server)) {
        throw new Exception('Server default harus dipilih');
    }
    if (empty($default_area)) {
        throw new Exception('Area default harus diisi');
    }
    
    // Validasi ukuran file (10MB max)
    if ($file['size'] > 10 * 1024 * 1024) {
        throw new Exception('Ukuran file terlalu besar. Maksimal 10MB.');
    }
    
    // Validasi ekstensi file
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($file_ext, ['kmz', 'kml'])) {
        throw new Exception('Format file tidak didukung. Gunakan .kmz atau .kml');
    }
    
    // Bangun daftar PEMILIK server yang boleh diakses user ini (sesuai scoping di odp.php)
    if ($AKSES == 'ASSISTANT') {
        $owned_query = mysqli_query($conn, "SELECT DISTINCT PEMILIK FROM server WHERE AREA IN ($area_list)");
    } else {
        $owned_query = mysqli_query($conn, "SELECT DISTINCT PEMILIK FROM server WHERE user_id = $current_user_id");
    }
    $owned_pemilik = [];
    if ($owned_query) {
        while ($owned_row = mysqli_fetch_assoc($owned_query)) {
            $owned_pemilik[] = "'" . mysqli_real_escape_string($conn, $owned_row['PEMILIK']) . "'";
        }
    }
    $server_list = count($owned_pemilik) > 0 ? implode(',', $owned_pemilik) : "''";

    // Validasi server exists dan milik user ini
    $server_check_sql = "SELECT BRAND FROM server WHERE PEMILIK = ? AND `pemilik` IN ($server_list) LIMIT 1";
    $server_stmt = mysqli_prepare($conn, $server_check_sql);
    mysqli_stmt_bind_param($server_stmt, 's', $default_server);
    mysqli_stmt_execute($server_stmt);
    $server_result = mysqli_stmt_get_result($server_stmt);

    if (mysqli_num_rows($server_result) == 0) {
        throw new Exception("Server '$default_server' tidak ditemukan atau tidak diizinkan");
    }
    
    $server_row = mysqli_fetch_assoc($server_result);
    $brand = $server_row['BRAND'];
    
    $kml_content = '';
    
    // Handle KMZ file (ZIP archive containing KML)
    if ($file_ext === 'kmz') {
        $zip = new ZipArchive();
        if ($zip->open($file['tmp_name']) !== TRUE) {
            throw new Exception('Tidak dapat membuka file KMZ');
        }
        
        // Find KML file in ZIP
        $kml_found = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'kml') {
                $kml_content = $zip->getFromIndex($i);
                $kml_found = true;
                break;
            }
        }
        $zip->close();
        
        if (!$kml_found) {
            throw new Exception('File KML tidak ditemukan dalam KMZ');
        }
    } else {
        // Handle KML file directly
        $kml_content = file_get_contents($file['tmp_name']);
    }
    
    if (empty($kml_content)) {
        throw new Exception('File KML kosong atau tidak dapat dibaca');
    }
    
    // Parse KML content
    $dom = new DOMDocument();
    if (!$dom->loadXML($kml_content)) {
        throw new Exception('File KML tidak valid atau corrupt');
    }
    
    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('kml', 'http://www.opengis.net/kml/2.2');
    
    // Find all Placemark elements
    $placemarks = $xpath->query('//kml:Placemark | //Placemark');
    
    if ($placemarks->length === 0) {
        throw new Exception('Tidak ada Placemark ditemukan dalam file KML');
    }
    
    $success_count = 0;
    $error_count = 0;
    $duplicate_count = 0;
    $errors = [];
    
    foreach ($placemarks as $index => $placemark) {
        try {
            // Get name
            $nameNodes = $xpath->query('.//kml:name | .//name', $placemark);
            $name = ($nameNodes->length > 0) ? trim($nameNodes->item(0)->textContent) : "ODP-" . ($index + 1);
            
            if (empty($name)) {
                $name = "ODP-" . ($index + 1);
            }
            
            // Get coordinates
            $coordNodes = $xpath->query('.//kml:coordinates | .//coordinates', $placemark);
            if ($coordNodes->length === 0) {
                throw new Exception("Koordinat tidak ditemukan untuk placemark: $name");
            }
            
            $coordinates = trim($coordNodes->item(0)->textContent);
            $coord_parts = preg_split('/[,\s]+/', $coordinates);
            
            if (count($coord_parts) < 2) {
                throw new Exception("Format koordinat tidak valid untuk placemark: $name");
            }
            
            $longitude = floatval($coord_parts[0]);
            $latitude = floatval($coord_parts[1]);
            
            // Validasi koordinat range
            if ($latitude < -90 || $latitude > 90) {
                throw new Exception("Latitude tidak valid untuk placemark: $name");
            }
            if ($longitude < -180 || $longitude > 180) {
                throw new Exception("Longitude tidak valid untuk placemark: $name");
            }
            
            // Generate kode ODP
            $kode = $kode_prefix . preg_replace('/[^a-zA-Z0-9\-_]/', '', $name);
            $tikor = $latitude . ',' . $longitude;
            
            // Cek duplikat berdasarkan kode atau koordinat
            if ($skip_duplicates) {
                $check_sql = "SELECT id FROM odp WHERE (KODE = ? OR TIKOR = ?) LIMIT 1";
                $check_stmt = mysqli_prepare($conn, $check_sql);
                mysqli_stmt_bind_param($check_stmt, 'ss', $kode, $tikor);
                mysqli_stmt_execute($check_stmt);
                $check_result = mysqli_stmt_get_result($check_stmt);
                
                if (mysqli_num_rows($check_result) > 0) {
                    $duplicate_count++;
                    continue; // Skip duplicate
                }
            }
            
            // Insert ODP data sesuai struktur terbaru
            $insert_sql = "INSERT INTO odp (KODE, NAME, TIKOR, PEMILIK, AREA, BRAND, PORT, Hirarki, splitter, hirarki_parent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $insert_stmt = mysqli_prepare($conn, $insert_sql);
            if (!$insert_stmt) {
                throw new Exception("Gagal menyiapkan query insert: " . mysqli_error($conn));
            }
            $port = 0;
            mysqli_stmt_bind_param($insert_stmt, 'ssssssisss', $kode, $name, $tikor, $default_server, $default_area, $brand, $port, $default_hirarki, $default_splitter, $default_hirarki_parent);

            if (mysqli_stmt_execute($insert_stmt)) {
                $success_count++;
            } else {
                throw new Exception("Gagal menyimpan data: " . mysqli_stmt_error($insert_stmt));
            }
            
        } catch (Exception $e) {
            $error_count++;
            $errors[] = "Placemark " . ($index + 1) . ": " . $e->getMessage();
        }
    }
    
    // Prepare result message
    $message = "Import KMZ/KML selesai!\\n";
    $message .= "Berhasil: $success_count data\\n";
    if ($duplicate_count > 0) {
        $message .= "Duplikat dilewati: $duplicate_count data\\n";
    }
    if ($error_count > 0) {
        $message .= "Error: $error_count data\\n";
    }
    
    // Log import activity
    $log_msg = "ODP KMZ Import: Success=$success_count, Errors=$error_count, Duplicates=$duplicate_count by " . ($ceknama ?: 'System');
    error_log($log_msg);
    
    // log history
    $history_file = "../notifbot/data/history-$ceknama.json";
    $history = [];
    if (file_exists($history_file)) {
        $history = json_decode(file_get_contents($history_file), true);
    }
    if (!is_array($history)) { $history = []; }
    $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Import ODP KMZ";
    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    
    // Redirect with success message
    if ($error_count > 0 && !empty($errors)) {
        $error_detail = "\\n\\nDetail error:\\n" . implode("\\n", array_slice($errors, 0, 5));
        if (count($errors) > 5) {
            $error_detail .= "\\n... dan " . (count($errors) - 5) . " error lainnya";
        }
        $message .= $error_detail;
    }
    
    $encoded_message = urlencode($message);
    header("Location: ../odp.php?status=success&msg=$encoded_message");
    exit;
    
} catch (Exception $e) {
    error_log("ODP KMZ Import Error: " . $e->getMessage());
    
    $error_message = urlencode("Error import KMZ: " . $e->getMessage());
    header("Location: ../odp.php?status=error&msg=$error_message");
    exit;
}
?>