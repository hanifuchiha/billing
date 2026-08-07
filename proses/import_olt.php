<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require '../cek-sesi.php';

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
    } else {
        throw new Exception('Gagal membaca file XLSX: ' . SimpleXLSX::parseError());
    }

    if (empty($data)) {
        throw new Exception('File Excel kosong atau gagal dibaca');
    }
    
    // Filter out empty rows
    $data = array_filter($data, function($row) {
        return !empty(array_filter($row, function($cell) {
            return !empty(trim($cell));
        }));
    });
    
    if (count($data) < 2) {
        throw new Exception('File harus memiliki minimal 1 baris data (selain header)');
    }
    
    // Ambil header dari baris pertama
    $headerRow = array_shift($data);
    
    // Mapping header ke kolom OLT yang sesuai
    $headerMap = [];
    $requiredHeaders = ['IP + PORT', 'OLT NAME', 'Brand', 'Username', 'Password', 'Product', 'Area'];
    foreach ($requiredHeaders as $req) {
        $found = false;
        foreach ($headerRow as $idx => $header) {
            if (strtolower(trim($header)) === strtolower($req)) {
                $headerMap[$req] = $idx;
                $found = true;
                break;
            }
        }
        if (!$found) {
            throw new Exception("Header wajib '$req' tidak ditemukan");
        }
    }
    
    // Optional headers
    $optionalHeaders = ['Community Read', 'Community Write'];
    foreach ($optionalHeaders as $opt) {
        $headerMap[$opt] = null;
        foreach ($headerRow as $idx => $header) {
            if (strtolower(trim($header)) === strtolower($opt)) {
                $headerMap[$opt] = $idx;
                break;
            }
        }
    }
    
    // Process setiap baris data
    $imported_count = 0;
    $failed_count = 0;
    $details = [];
    
    foreach ($data as $rowNum => $row) {
        $rowNum += 2; // +2 karena 0-index dan header sudah di-shift
        
        try {
            // Ambil nilai dari kolom
            $ipolt = trim($row[$headerMap['IP + PORT']] ?? '');
            $oltname = trim($row[$headerMap['OLT NAME']] ?? '');
            $brandolt = trim($row[$headerMap['Brand']] ?? '');
            $usernameolt = trim($row[$headerMap['Username']] ?? '');
            $passwordolt = trim($row[$headerMap['Password']] ?? '');
            $server = trim($row[$headerMap['Product']] ?? '');
            $area = trim($row[$headerMap['Area']] ?? '');
            $community_read = isset($headerMap['Community Read']) && $headerMap['Community Read'] !== null ? trim($row[$headerMap['Community Read']] ?? '') : '';
            $community_write = isset($headerMap['Community Write']) && $headerMap['Community Write'] !== null ? trim($row[$headerMap['Community Write']] ?? '') : '';
            
            // Validasi required fields
            $errors = [];
            if (empty($ipolt)) {
                $errors[] = 'IP + PORT wajib diisi';
            }
            if (empty($oltname)) {
                $errors[] = 'OLT NAME wajib diisi';
            }
            if (empty($brandolt)) {
                $errors[] = 'Brand wajib diisi';
            }
            if (empty($usernameolt)) {
                $errors[] = 'Username wajib diisi';
            }
            if (empty($passwordolt)) {
                $errors[] = 'Password wajib diisi';
            }
            if (empty($server)) {
                $errors[] = 'Product wajib diisi';
            }
            if (empty($area)) {
                $errors[] = 'Area wajib diisi';
            }
            
            // Validasi brand CDATA GPON
            if ($brandolt === 'CDATA GPON' && empty($community_read)) {
                $errors[] = 'Community Read wajib untuk brand CDATA GPON';
            }
            
            if (!empty($errors)) {
                throw new Exception(implode('; ', $errors));
            }
            
            // Validasi server (pemilik) ada di database
            $sqlServerCheck = "SELECT PEMILIK FROM server WHERE PEMILIK = '" . mysqli_real_escape_string($conn, $server) . "' LIMIT 1";
            if ($AKSES === 'ASSISTANT') {
                $sqlServerCheck = "SELECT PEMILIK FROM server WHERE PEMILIK = '" . mysqli_real_escape_string($conn, $server) . "' AND AREA IN ($area_list) LIMIT 1";
            } else {
                $current_user_id = isset($_SESSION['id']) ? (int)$_SESSION['id'] : 0;
                if ($current_user_id > 0) {
                    $sqlServerCheck = "SELECT PEMILIK FROM server WHERE PEMILIK = '" . mysqli_real_escape_string($conn, $server) . "' AND user_id = $current_user_id LIMIT 1";
                }
            }
            $resServerCheck = mysqli_query($conn, $sqlServerCheck);
            if (!$resServerCheck || mysqli_num_rows($resServerCheck) == 0) {
                throw new Exception('Product (server) "' . $server . '" tidak ditemukan atau tidak ada akses');
            }
            
            // Cek duplikat OLT dengan IP + Area yang sama (per owner)
            $sqlDupCheck = "SELECT id FROM olt WHERE ipolt = '" . mysqli_real_escape_string($conn, $ipolt) . "' AND area = '" . mysqli_real_escape_string($conn, $area) . "' AND pemilik = '" . mysqli_real_escape_string($conn, $server) . "' LIMIT 1";
            $resDupCheck = mysqli_query($conn, $sqlDupCheck);
            $isDuplicate = $resDupCheck && mysqli_num_rows($resDupCheck) > 0;
            
            if ($isDuplicate) {
                throw new Exception('OLT dengan IP + Area ini sudah ada');
            }
            
            // Insert data OLT
            $stmt = $conn->prepare("INSERT INTO olt (ipolt, oltname, pemilik, area, usernameolt, passwordolt, brandolt, community_read, community_write) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if (!$stmt) {
                throw new Exception('Prepare statement gagal: ' . $conn->error);
            }
            
            $stmt->bind_param("sssssssss", $ipolt, $oltname, $server, $area, $usernameolt, $passwordolt, $brandolt, $community_read, $community_write);
            
            if (!$stmt->execute()) {
                throw new Exception('Insert gagal: ' . $stmt->error);
            }
            
            $stmt->close();
            $imported_count++;
            
        } catch (Exception $e) {
            $failed_count++;
            $details[] = "Baris $rowNum: " . $e->getMessage();
        }
    }
    
    // Log history
    if ($imported_count > 0 || $failed_count > 0) {
        $historyFile = '../../notifbot/data/history-' . $ceknama . '.json';
        $history = [];
        if (file_exists($historyFile)) {
            $history = json_decode(file_get_contents($historyFile), true) ?? [];
        }
        
        $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Import data OLT: $imported_count berhasil, $failed_count gagal";
        
        @mkdir(dirname($historyFile), 0755, true);
        file_put_contents($historyFile, json_encode($history, JSON_PRETTY_PRINT));
    }
    
    // Set response
    if ($failed_count === 0 && $imported_count > 0) {
        $response['success'] = true;
        $response['statusnotif'] = 'success';
        $response['message'] = "Berhasil import $imported_count data OLT";
    } else if ($imported_count > 0 && $failed_count > 0) {
        $response['success'] = true;
        $response['statusnotif'] = 'warning';
        $response['message'] = "Import selesai: $imported_count berhasil, $failed_count gagal";
        $response['details'] = $details;
    } else {
        $response['success'] = false;
        $response['statusnotif'] = 'failed';
        $response['message'] = "Semua $failed_count data gagal diimport";
        $response['details'] = $details;
    }

} catch (Exception $e) {
    $response['success'] = false;
    $response['statusnotif'] = 'failed';
    $response['message'] = $e->getMessage();
}

// Cleanup
@unlink($uploadPath ?? '');

// Redirect dengan status
if (isset($response['statusnotif'])) {
    $queryString = http_build_query([
        'statusnotif' => $response['statusnotif'],
        'message' => $response['message']
    ]);
    header('Location: ../olt.php?' . $queryString);
    exit;
} else {
    header('Location: ../olt.php?statusnotif=failed&message=Import gagal');
    exit;
}
?>
