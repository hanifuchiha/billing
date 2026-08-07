<?php
header('Content-Type: application/json');
require_once '../koneksibilling.php';
require_once '../libs/SimpleXLSX.php';
session_start();

function auth_mobile_user($conn, $username, $password) {
    $stmt = $conn->prepare('SELECT id, USERNAME, PASWORD FROM user WHERE USERNAME=? LIMIT 1');
    if (!$stmt) return null;
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        if (password_verify($password, (string)$row['PASWORD']) || (string)$password === (string)$row['PASWORD']) {
            return [
                'id' => (int)($row['id'] ?? 0),
                'username' => (string)($row['USERNAME'] ?? '')
            ];
        }
    }
    return null;
}

function sanitize_string($value) {
    return trim((string)$value);
}

try {
    if (!$conn) {
        echo json_encode(['success' => false, 'error' => 'Koneksi DB gagal']);
        exit;
    }

    $username = sanitize_string($_POST['username'] ?? $_GET['username'] ?? '');
    $password = sanitize_string($_POST['password'] ?? $_GET['password'] ?? '');
    $skipHeader = isset($_POST['skip_header']) ? filter_var($_POST['skip_header'], FILTER_VALIDATE_BOOLEAN) : true;

    $auth = auth_mobile_user($conn, $username, $password);
    if (!$auth) {
        echo json_encode(['success' => false, 'error' => 'Autentikasi gagal']);
        exit;
    }

    if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => 'File upload gagal atau belum dipilih']);
        exit;
    }

    $file = $_FILES['excel_file'];
    $fileName = (string)$file['name'];
    $fileSize = (int)$file['size'];
    $fileTmp = (string)$file['tmp_name'];
    $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    if ($fileExt !== 'xlsx') {
        echo json_encode(['success' => false, 'error' => 'Format file harus XLSX']);
        exit;
    }
    if ($fileSize > 10 * 1024 * 1024) {
        echo json_encode(['success' => false, 'error' => 'Ukuran file maksimal 10MB']);
        exit;
    }

    $uploadDir = __DIR__ . '/../uploads/';
    if (!file_exists($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }
    $uploadPath = $uploadDir . uniqid('olt_', true) . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $fileName);
    if (!move_uploaded_file($fileTmp, $uploadPath)) {
        echo json_encode(['success' => false, 'error' => 'Gagal menyimpan file upload']);
        exit;
    }

    $rows = SimpleXLSX::parse($uploadPath) ? SimpleXLSX::parse($uploadPath)->rows() : null;
    if (!is_array($rows) || empty($rows)) {
        @unlink($uploadPath);
        echo json_encode(['success' => false, 'error' => 'Gagal membaca isi file XLSX']);
        exit;
    }

    $rows = array_values(array_filter($rows, function ($row) {
        return !empty(array_filter($row, function ($cell) {
            return trim((string)$cell) !== '';
        }));
    }));

    if (count($rows) < 2) {
        @unlink($uploadPath);
        echo json_encode(['success' => false, 'error' => 'File harus memiliki minimal 1 baris data']);
        exit;
    }

    $headerRow = array_map(function ($item) {
        return strtolower(trim((string)$item));
    }, array_shift($rows));

    $getIndex = function (array $headers, array $needles) {
        foreach ($needles as $needle) {
            $idx = array_search(strtolower($needle), $headers, true);
            if ($idx !== false) return $idx;
        }
        return null;
    };

    $indexes = [
        'ipolt' => $getIndex($headerRow, ['ip + port', 'ip port', 'ip+port']),
        'oltname' => $getIndex($headerRow, ['olt name', 'oltname', 'nama olt']),
        'brandolt' => $getIndex($headerRow, ['brand', 'brand olt', 'brandolt']),
        'usernameolt' => $getIndex($headerRow, ['username', 'username olt', 'usernameolt']),
        'passwordolt' => $getIndex($headerRow, ['password', 'password olt', 'passwordolt']),
        'server' => $getIndex($headerRow, ['product', 'server', 'pemilik']),
        'area' => $getIndex($headerRow, ['area'])
    ];

    foreach ($indexes as $key => $index) {
        if ($index === null) {
            @unlink($uploadPath);
            echo json_encode(['success' => false, 'error' => "Header wajib untuk $key tidak ditemukan"]);
            exit;
        }
    }

    $indexes['community_read'] = $getIndex($headerRow, ['community read', 'community_read']);
    $indexes['community_write'] = $getIndex($headerRow, ['community write', 'community_write']);

    $imported = 0;
    $failed = 0;
    $details = [];

    foreach ($rows as $rowIndex => $row) {
        $lineNo = $rowIndex + 2;
        try {
            $ipolt = sanitize_string($row[$indexes['ipolt']] ?? '');
            $oltname = sanitize_string($row[$indexes['oltname']] ?? '');
            $brandolt = sanitize_string($row[$indexes['brandolt']] ?? '');
            $usernameolt = sanitize_string($row[$indexes['usernameolt']] ?? '');
            $passwordolt = sanitize_string($row[$indexes['passwordolt']] ?? '');
            $server = sanitize_string($row[$indexes['server']] ?? '');
            $area = sanitize_string($row[$indexes['area']] ?? '');
            $communityRead = $indexes['community_read'] !== null ? sanitize_string($row[$indexes['community_read']] ?? '') : '';
            $communityWrite = $indexes['community_write'] !== null ? sanitize_string($row[$indexes['community_write']] ?? '') : '';

            if ($ipolt === '' || $oltname === '' || $brandolt === '' || $usernameolt === '' || $passwordolt === '' || $server === '' || $area === '') {
                throw new Exception('Kolom wajib belum lengkap');
            }

            $sqlServerCheck = "SELECT PEMILIK FROM server WHERE PEMILIK = '" . mysqli_real_escape_string($conn, $server) . "' LIMIT 1";
            $resServerCheck = mysqli_query($conn, $sqlServerCheck);
            if (!$resServerCheck || mysqli_num_rows($resServerCheck) === 0) {
                throw new Exception('Server Area tidak ditemukan');
            }

            $sqlDup = "SELECT id FROM olt WHERE ipolt='" . mysqli_real_escape_string($conn, $ipolt) . "' AND area='" . mysqli_real_escape_string($conn, $area) . "' AND pemilik='" . mysqli_real_escape_string($conn, $server) . "' LIMIT 1";
            $resDup = mysqli_query($conn, $sqlDup);
            if ($resDup && mysqli_num_rows($resDup) > 0) {
                throw new Exception('Data OLT sudah ada');
            }

            $stmt = $conn->prepare('INSERT INTO olt (ipolt, oltname, pemilik, area, usernameolt, passwordolt, brandolt, community_read, community_write) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            if (!$stmt) {
                throw new Exception('Prepare gagal');
            }
            $stmt->bind_param('sssssssss', $ipolt, $oltname, $server, $area, $usernameolt, $passwordolt, $brandolt, $communityRead, $communityWrite);
            if (!$stmt->execute()) {
                throw new Exception($stmt->error ?: 'Insert gagal');
            }
            $stmt->close();
            $imported++;
        } catch (Throwable $e) {
            $failed++;
            $details[] = ['row' => $lineNo, 'error' => $e->getMessage()];
        }
    }

    @unlink($uploadPath);

    echo json_encode([
        'success' => $failed === 0 && $imported > 0,
        'message' => $failed === 0
            ? "Berhasil import $imported data OLT"
            : ($imported > 0 ? "Import selesai: $imported berhasil, $failed gagal" : "Semua data gagal diimport"),
        'imported_count' => $imported,
        'failed_count' => $failed,
        'details' => $details
    ]);
} catch (Throwable $e) {
    @unlink($uploadPath ?? '');
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
