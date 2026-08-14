
<?php
require_once(__DIR__ . '/../cek-sesi.php');
require_once(__DIR__ . '/../routeros_api.class.php');
require_once(__DIR__ . '/../libs/SimpleXLSX.php');

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

function parseTikor($rawValue) {
    $rawValue = trim((string)$rawValue);
    $parts = explode(',', $rawValue);
    if (count($parts) !== 2) {
        throw new Exception("Format TIKOR harus 'latitude,longitude'");
    }

    $latitude = (float)trim($parts[0]);
    $longitude = (float)trim($parts[1]);

    if ($latitude < -90 || $latitude > 90) {
        throw new Exception('Latitude harus antara -90 sampai 90');
    }
    if ($longitude < -180 || $longitude > 180) {
        throw new Exception('Longitude harus antara -180 sampai 180');
    }

    return $latitude . ',' . $longitude;
}

function parseTikorFromLatLng($latRaw, $lngRaw) {
    $latitude = (float)trim((string)$latRaw);
    $longitude = (float)trim((string)$lngRaw);

    if ($latitude < -90 || $latitude > 90) {
        throw new Exception('Latitude harus antara -90 sampai 90');
    }
    if ($longitude < -180 || $longitude > 180) {
        throw new Exception('Longitude harus antara -180 sampai 180');
    }

    return $latitude . ',' . $longitude;
}

function normalizeHirarki($value) {
    $allowed = ['ODC', 'ODP', 'ODP-RASIO', 'ODP-JUMPER'];
    $value = strtoupper(trim((string)$value));
    return in_array($value, $allowed, true) ? $value : 'ODP';
}

function normalizeSplitter($value) {
    $allowed = ['1:2', '1:4', '1:8', '1:16', '1:32'];
    $value = trim((string)$value);
    if (in_array($value, $allowed, true)) {
        return $value;
    }

    // Excel kadang mengubah teks "1:16" menjadi angka waktu 0.052777...
    // karena dianggap jam:menit. Konversi balik supaya import tetap ramah.
    if (is_numeric($value)) {
        $totalMinutes = (int)round(((float)$value) * 24 * 60);
        $hours = intdiv($totalMinutes, 60);
        $minutes = $totalMinutes % 60;
        $ratio = $hours . ':' . $minutes;
        if (in_array($ratio, $allowed, true)) {
            return $ratio;
        }
    }

    return null;
}

function buildServerAreaHelp(mysqli $conn, string $pemilik, string $area): string {
    $pemilik = trim($pemilik);
    $area = trim($area);

    if ($area !== '') {
        $sql = "SELECT PEMILIK, BRAND, AREA FROM server WHERE AREA = ? ORDER BY BRAND, AREA LIMIT 3";
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 's', $area);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $suggestions = [];
            while ($row = mysqli_fetch_assoc($result)) {
                $suggestions[] = "PEMILIK='" . ($row['PEMILIK'] ?? '') . "', AREA='" . ($row['AREA'] ?? '') . "'";
            }
            if (!empty($suggestions)) {
                return "Nilai Excel: PEMILIK='$pemilik', AREA='$area'. Untuk AREA ini gunakan " . implode(' atau ', $suggestions) . ". Kolom PEMILIK harus berisi kode PEMILIK, bukan nama BRAND.";
            }
        }
    }

    $examples = [];
    $q = mysqli_query($conn, "SELECT PEMILIK, BRAND, AREA FROM server ORDER BY BRAND, AREA LIMIT 5");
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $examples[] = ($row['PEMILIK'] ?? '') . ' | ' . ($row['AREA'] ?? '');
        }
    }
    $exampleText = !empty($examples) ? " Contoh valid: " . implode('; ', $examples) . "." : "";
    return "Nilai Excel: PEMILIK='$pemilik', AREA='$area'. Pasangan ini tidak ada di tabel server.$exampleText";
}

try {
    ensureOdpImportColumns($conn);

    // Validasi file upload
    if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('File tidak berhasil diupload. Error: ' . ($_FILES['excel_file']['error'] ?? 'Unknown'));
    }
    
    $file = $_FILES['excel_file'];
    $skip_duplicates = isset($_POST['skip_duplicates']);
    
    // Validasi ukuran file (5MB max)
    if ($file['size'] > 5 * 1024 * 1024) {
        throw new Exception('Ukuran file terlalu besar. Maksimal 5MB.');
    }
    
    // Validasi ekstensi file
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed_extensions = ['xlsx', 'xls'];
    
    if (!in_array($file_ext, $allowed_extensions)) {
        throw new Exception('Format file tidak didukung. Gunakan .xlsx atau .xls');
    }
    
    $import_data = [];
    
    // Handle Excel file only
    if (!($xlsx = SimpleXLSX::parse($file['tmp_name']))) {
        throw new Exception('Error parsing Excel file: ' . SimpleXLSX::parseError());
    }
    
    $rows = $xlsx->rows();
    if (empty($rows)) {
        throw new Exception('File Excel kosong atau tidak valid');
    }
    
    // Skip header row
    array_shift($rows);
    
    foreach ($rows as $row) {
        if (!empty(trim($row[0] ?? ''))) {
            $import_data[] = $row;
        }
    }

    if (empty($import_data)) {
        throw new Exception('Tidak ada data valid yang ditemukan dalam file');
    }

    // Process import data
    $success_count = 0;
    $error_count = 0;
    $duplicate_count = 0;
    $errors = [];
    $inserted = [];
    $duplicates = [];

    foreach ($import_data as $index => $row) {
        $row_num = $index + 2; // +2 karena mulai dari row 2 (setelah header)
        try {
            $kode = trim($row[0] ?? '');
            $name = trim($row[1] ?? '');
            $rawCol2 = trim($row[2] ?? '');

            $tikor = '';
            $pemilik = '';
            $area = '';
            $hirarki = '';
            $splitter = '';
            $hirarki_parent = '';

            // Format baru: KODE | NAME | TIKOR | PEMILIK | AREA | HIRARKI | SPLITTER | HIRARKI_PARENT
            if (strpos($rawCol2, ',') !== false) {
                $tikor = parseTikor($rawCol2);
                $pemilik = trim($row[3] ?? '');
                $area = trim($row[4] ?? '');
                $hirarki = normalizeHirarki($row[5] ?? 'ODP');
                $splitter = normalizeSplitter($row[6] ?? '');
                $hirarki_parent = trim($row[7] ?? '');
            } else {
                // Kompatibilitas format lama: KODE | NAME | LATITUDE | LONGITUDE | PEMILIK | AREA | ...
                $tikor = parseTikorFromLatLng($row[2] ?? '', $row[3] ?? '');
                $pemilik = trim($row[4] ?? '');
                $area = trim($row[5] ?? '');
                $hirarki = normalizeHirarki($row[6] ?? 'ODP');
                $splitter = normalizeSplitter($row[7] ?? '');
                $hirarki_parent = trim($row[8] ?? '');
            }

            if (in_array($hirarki, ['ODC', 'ODP-RASIO', 'ODP-JUMPER'], true)) {
                $hirarki_parent = '';
            }

            // Validasi required fields
            if (empty($kode)) throw new Exception("Kode ODP tidak boleh kosong");
            if (empty($name)) throw new Exception("Nama ODP tidak boleh kosong");
            if (empty($pemilik)) throw new Exception("PEMILIK tidak boleh kosong");
            if (empty($area)) throw new Exception("Area tidak boleh kosong");

            // Ambil brand dari data server yang valid
            $brand = '';
            $server_sql = "SELECT BRAND FROM server WHERE PEMILIK = ? AND AREA = ? LIMIT 1";
            $server_stmt = mysqli_prepare($conn, $server_sql);
            mysqli_stmt_bind_param($server_stmt, 'ss', $pemilik, $area);
            mysqli_stmt_execute($server_stmt);
            $server_result = mysqli_stmt_get_result($server_stmt);
            if ($server_result && mysqli_num_rows($server_result) > 0) {
                $server_row = mysqli_fetch_assoc($server_result);
                $brand = trim($server_row['BRAND'] ?? '');
            }
            if (empty($brand)) {
                throw new Exception("Server/Area tidak cocok. " . buildServerAreaHelp($conn, $pemilik, $area));
            }

            if ($skip_duplicates) {
                $check_sql = "SELECT id FROM odp WHERE KODE = ? LIMIT 1";
                $check_stmt = mysqli_prepare($conn, $check_sql);
                mysqli_stmt_bind_param($check_stmt, 's', $kode);
                mysqli_stmt_execute($check_stmt);
                $check_result = mysqli_stmt_get_result($check_stmt);
                if ($check_result && mysqli_num_rows($check_result) > 0) {
                    $duplicate_count++;
                    $duplicates[] = "Row $row_num: KODE '$kode' sudah ada, data dilewati";
                    continue;
                }
            }

            // Insert ODP data sesuai struktur terbaru
            $insert_sql = "INSERT INTO odp (KODE, NAME, TIKOR, PEMILIK, AREA, BRAND, PORT, Hirarki, splitter, hirarki_parent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $insert_stmt = mysqli_prepare($conn, $insert_sql);
            $port = 0;
            mysqli_stmt_bind_param($insert_stmt, 'ssssssisss', $kode, $name, $tikor, $pemilik, $area, $brand, $port, $hirarki, $splitter, $hirarki_parent);
            if (mysqli_stmt_execute($insert_stmt)) {
                $success_count++;
                $inserted[] = "Row $row_num: $kode - $name";
            } else {
                throw new Exception("Gagal menyimpan data ke database: " . mysqli_error($conn));
            }
        } catch (Exception $e) {
            $error_count++;
            $errors[] = "Row $row_num: " . $e->getMessage();
        }
    }
    
    // Prepare result message
    $total_processed = $success_count + $duplicate_count + $error_count;
    $message = "Import ODP Excel selesai.\n";
    $message .= "Total baris diproses: $total_processed data\n";
    $message .= "Berhasil masuk: $success_count data\n";
    if ($duplicate_count > 0) {
        $message .= "Duplikat kode dilewati: $duplicate_count data\n";
    }
    if ($error_count > 0) {
        $message .= "Gagal import: $error_count data\n";
    }
    $message .= "\nCatatan: TIKOR/koordinat yang sama tetap boleh masuk. Duplikat hanya dicek dari KODE.";
    
    // Log import activity
    $log_msg = "ODP Excel Import: Success=$success_count, Errors=$error_count, Duplicates=$duplicate_count by " . ($ceknama ?: 'System');
    error_log($log_msg);
    
    // log history
    $history_file = "../notifbot/data/history-$ceknama.json";
    $history = [];
    if (file_exists($history_file)) {
        $history = json_decode(file_get_contents($history_file), true);
    }
    if (!is_array($history)) { $history = []; }
    $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Import ODP Excel";
    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    
    // Redirect with success message
    if (!empty($inserted)) {
        $message .= "\n\nData berhasil masuk:\n" . implode("\n", array_slice($inserted, 0, 8));
        if (count($inserted) > 8) {
            $message .= "\n... dan " . (count($inserted) - 8) . " data berhasil lainnya";
        }
    }
    if (!empty($duplicates)) {
        $message .= "\n\nData dilewati karena KODE duplikat:\n" . implode("\n", array_slice($duplicates, 0, 8));
        if (count($duplicates) > 8) {
            $message .= "\n... dan " . (count($duplicates) - 8) . " duplikat lainnya";
        }
    }
    if ($error_count > 0 && !empty($errors)) {
        $error_detail = "\n\nData gagal import:\n" . implode("\n", array_slice($errors, 0, 10));
        if (count($errors) > 10) {
            $error_detail .= "\n... dan " . (count($errors) - 10) . " gagal lainnya";
        }
        $message .= $error_detail;
    }
    
    $encoded_message = urlencode($message);
    header("Location: ../odp.php?status=success&msg=$encoded_message");
    exit;
    
} catch (Exception $e) {
    error_log("ODP Excel Import Error: " . $e->getMessage());
    
    $error_message = urlencode("Error import: " . $e->getMessage());
    header("Location: ../odp.php?status=error&msg=$error_message");
    exit;
}
?>
