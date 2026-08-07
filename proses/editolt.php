
<?php
// Enable error reporting for debugging
error_reporting(E_ALL);

// Include database connection
require_once '../cek-sesi.php';

// Check if form is submitted via POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo "<script>alert('Invalid request method!'); window.history.back();</script>";
    exit();
}

// Get and validate form data
$id = isset($_POST['id']) ? intval($_POST['id']) : 0;
$ipolt = isset($_POST['ipolt']) ? trim($_POST['ipolt']) : '';
$oltname = isset($_POST['oltname']) ? trim($_POST['oltname']) : '';
$brandolt = isset($_POST['brandolt']) ? trim($_POST['brandolt']) : '';
$usernameolt = isset($_POST['usernameolt']) ? trim($_POST['usernameolt']) : '';
$passwordolt = isset($_POST['passwordolt']) ? trim($_POST['passwordolt']) : '';
$server = isset($_POST['server']) ? trim($_POST['server']) : '';
$area = isset($_POST['area']) ? trim($_POST['area']) : '';
$community_read = isset($_POST['community_read']) ? trim($_POST['community_read']) : '';
$community_write = isset($_POST['community_write']) ? trim($_POST['community_write']) : '';

// Validation
$errors = [];

if ($id <= 0) {
    $errors[] = "ID OLT tidak valid";
}

if (empty($ipolt)) {
    $errors[] = "IP OLT wajib diisi";
} else {
    // Validate IP part (before colon if port is included)
    $ip_part = explode(':', $ipolt)[0];
    if (!filter_var($ip_part, FILTER_VALIDATE_IP)) {
        $errors[] = "Format alamat IP tidak valid";
    }
    
    // Validate port if included
    if (strpos($ipolt, ':') !== false) {
        $port_part = explode(':', $ipolt)[1];
        if (!is_numeric($port_part) || $port_part < 1 || $port_part > 65535) {
            $errors[] = "Port harus berupa angka antara 1-65535";
        }
    }
}

if (empty($oltname)) {
    $errors[] = "Nama OLT wajib diisi";
}

if (empty($brandolt)) {
    $errors[] = "Brand OLT wajib dipilih";
} else {
    // Validate brand against allowed values (sesuai dengan olt.php)
    $allowed_brands = [
        'HIOSO EPON HA7302CST', 'HIOSO EPON HA7304', 'HIOSO EPON HA7308',
        'ZTE GPON C300', 'ZTE GPON C320', 'ZTE GPON C600',
        'HUAWEI MA5608T', 'HUAWEI MA5680T', 'HUAWEI MA5683T',
        'VSOL GPON V1600G0/G1', 'VSOL EPON V1600D',
        'HSGQ GPON G02/G04/G08/G16', 'HSGQ EPON E04/E08',
        'CDATA GPON FD1601S', 'CDATA EPON FD1104/FD1208',
        // Legacy values untuk kompatibilitas data lama
        'HIOSO EPON', 'ZTE GPON C300/320', 'CDATA GPON', 'CDATA GPON SNMP LEGACY',
        'VSOL GPON', 'VSOL EPON', 'HSGQ GPON', 'HSGQ EPON',
        // Fallback values
        'HUAWEI GPON', 'ZTE GPON', 'FIBERHOME GPON', 'NOKIA GPON', 'CDATA', 'EPON LAIN', 'GPON LAIN'
    ];
    if (!in_array($brandolt, $allowed_brands)) {
        $errors[] = "Pemilihan brand tidak valid: " . htmlspecialchars($brandolt);
    }
}

if (empty($usernameolt)) {
    $errors[] = "Username OLT wajib diisi";
}

if (empty($passwordolt)) {
    $errors[] = "Password OLT wajib diisi";
}

if (empty($server)) {
    $errors[] = "Server wajib dipilih";
}

if (empty($area)) {
    $errors[] = "Area wajib dipilih";
}

if ($brandolt === 'CDATA GPON') {
    if (empty($community_read)) {
        $errors[] = "Community Read wajib diisi untuk brand CDATA GPON";
    }
}

// If there are validation errors, show them and go back
if (!empty($errors)) {
    $error_message = implode("\\n", $errors);
    echo "<script>alert('Error validasi:\\n{$error_message}'); window.history.back();</script>";
    exit();
}

try {
    // Check database connection
    if (!isset($conn) || $conn->connect_error) {
        throw new Exception("Database connection failed: " . ($conn->connect_error ?? 'Connection not established'));
    }
    
    // Check if OLT exists
    $check_stmt = $conn->prepare("SELECT id FROM olt WHERE id = ?");
    if (!$check_stmt) {
        throw new Exception("Prepare statement failed: " . $conn->error);
    }
    
    $check_stmt->bind_param("i", $id);
    if (!$check_stmt->execute()) {
        throw new Exception("Execute failed: " . $check_stmt->error);
    }
    
    $result = $check_stmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception("OLT tidak ditemukan");
    }
    
    $check_stmt->close();
    
    // Check if IP already exists for another OLT
    $ip_check_stmt = $conn->prepare("SELECT id FROM olt WHERE ipolt = ? AND id != ?");
    if (!$ip_check_stmt) {
        throw new Exception("IP check prepare failed: " . $conn->error);
    }
    
    $ip_check_stmt->bind_param("si", $ipolt, $id);
    if (!$ip_check_stmt->execute()) {
        throw new Exception("IP check execute failed: " . $ip_check_stmt->error);
    }
    
    $ip_result = $ip_check_stmt->get_result();
    
    if ($ip_result->num_rows > 0) {
        $ip_check_stmt->close();
        throw new Exception("Alamat IP sudah digunakan oleh OLT lain");
    }
    $ip_check_stmt->close();
    
    // Prepare update statement (without methodolt field)
    $update_stmt = $conn->prepare("UPDATE olt SET ipolt = ?, oltname = ?, brandolt = ?, usernameolt = ?, passwordolt = ?, pemilik = ?, area = ?, community_read = ?, community_write = ? WHERE id = ?");
    if (!$update_stmt) {
        throw new Exception("Update prepare failed: " . $conn->error);
    }
    
    $update_stmt->bind_param("sssssssssi", $ipolt, $oltname, $brandolt, $usernameolt, $passwordolt, $server, $area, $community_read, $community_write, $id);
    
    if ($update_stmt->execute()) {
        $update_stmt->close();
        
        // Log the update activity
        $log_message = "OLT updated: ID={$id}, IP={$ipolt}, Name={$oltname}, Brand={$brandolt}, Server={$server}, Area={$area}, Community Read={$community_read}, Community Write={$community_write}";
        error_log($log_message);
        
        // log history
        $history_file = "../notifbot/data/history-$ceknama.json";
        $history = [];
        if (file_exists($history_file)) {
            $history = json_decode(file_get_contents($history_file), true);
        }
        if (!is_array($history)) { $history = []; }
        $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Berhasil edit OLT $oltname";
        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
        
        // Success message and redirect
        echo "<script>
            alert('Data OLT berhasil diperbarui!');
            window.location.href = '../olt.php';
        </script>";
        
    } else {
        $update_stmt->close();
        throw new Exception("Gagal memperbarui data OLT: " . $conn->error);
    }
    
} catch (Exception $e) {
    // Log the error
    error_log("OLT Edit Error: " . $e->getMessage());
    
    // Show error message and go back
    echo "<script>
        alert('Error: " . addslashes($e->getMessage()) . "');
        window.history.back();
    </script>";
}

// Close database connection
if (isset($conn)) {
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Memproses Update OLT...</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
            background: #f8f9fa;
        }
        .loading {
            text-align: center;
            padding: 2rem;
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #007bff;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 1rem;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .success {
            color: #28a745;
        }
        .error {
            color: #dc3545;
        }
    </style>
</head>
<body>
    <div class="loading">
        <div class="spinner"></div>
        <h3>Memproses Update OLT...</h3>
        <p>Mohon tunggu sementara kami memperbarui data OLT.</p>
    </div>
    
    <script>
        // Fallback redirect if JavaScript alerts don't work
        setTimeout(function() {
            window.location.href = '../olt.php';
        }, 5000);
    </script>
</body>
</html>