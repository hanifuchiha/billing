<?php
// Set up error logging
$error_log = __DIR__ . '/../logs/generate_id_error.log';

// Create log directory if needed
if (!is_dir(dirname($error_log))) {
    @mkdir(dirname($error_log), 0755, true);
}

// Simple error handler that logs to file
function logError($message) {
    global $error_log;
    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($GLOBALS['error_log'], "[$timestamp] $message\n", FILE_APPEND);
}

// Set error handling
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    logError("PHP Error [$errno] $errstr in $errfile:$errline");
    return false;
});

set_exception_handler(function($exception) {
    logError("Exception: " . $exception->getMessage() . " at " . $exception->getFile() . ":" . $exception->getLine());
});

// Register shutdown handler to catch fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null) {
        logError("Fatal Error: " . $error['message'] . " in " . $error['file'] . ":" . $error['line']);
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Fatal error: ' . $error['message']]);
    }
});

// Send JSON header
header('Content-Type: application/json; charset=utf-8');

logError("Starting generate_id.php request");

// Load database connection
try {
    $koneksi_file = __DIR__ . '/../koneksibilling.php';
    logError("Attempting to load: $koneksi_file");
    
    if (!file_exists($koneksi_file)) {
        logError("Connection file not found: $koneksi_file");
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Connection file not found']);
        exit;
    }
    
    require_once $koneksi_file;
    
    logError("Connection file loaded");
    
    // Check connection
    if (!isset($conn)) {
        logError("Connection object not set after loading koneksibilling.php");
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Connection object not initialized']);
        exit;
    }
    
    if (!$conn) {
        logError("Connection failed: " . mysqli_connect_error());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . mysqli_connect_error()]);
        exit;
    }
    
    logError("Database connection successful");
} catch (Exception $e) {
    logError("Exception during connection: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Connection error: ' . $e->getMessage()]);
    exit;
}

// Get parameters from query string
$username = $_GET['username'] ?? $_POST['username'] ?? '';
$password = $_GET['password'] ?? $_POST['password'] ?? '';

logError("Request received - username: " . (empty($username) ? 'EMPTY' : substr($username, 0, 3) . '***'));

// Initialize response array
$response = ['success' => false, 'error' => 'Unknown error'];

// Simple authentication check
if (empty($username) || empty($password)) {
    logError("Missing credentials");
    echo json_encode(['success' => false, 'error' => 'Username dan password harus diisi']);
    exit;
}

try {
    logError("Starting authentication for user: $username");
    
    // Verify user credentials - use same logic as dropdown_options.php which works
    $stmt = $conn->prepare("SELECT id, USERNAME, PASWORD FROM user WHERE USERNAME = ?");
    if (!$stmt) {
        logError("Prepare failed: " . $conn->error);
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    logError("Statement prepared successfully");
    
    $stmt->bind_param("s", $username);
    if (!$stmt->execute()) {
        logError("Execute failed: " . $stmt->error);
        throw new Exception("Execute failed: " . $stmt->error);
    }
    
    logError("Query executed successfully");
    
    $result = $stmt->get_result();
    
    if ($result->num_rows == 0) {
        logError("Username not found: $username");
        echo json_encode(['success' => false, 'error' => 'Username tidak ditemukan']);
        exit;
    }
    
    logError("User found in database");
    
    $user_row = $result->fetch_assoc();
    $stmt->close();
    
    // Verify password - try password_verify first, then plain text as fallback
    $password_valid = false;
    
    logError("Attempting password verification");
    
    // Try hashed password (bcrypt)
    if (password_verify($password, $user_row['PASWORD'])) {
        $password_valid = true;
        logError("Password verified with bcrypt");
    }
    // Fallback: try plain text password (if stored without hashing)
    elseif ($password === $user_row['PASWORD']) {
        $password_valid = true;
        logError("Password verified as plaintext");
    }
    // Fallback: try MD5 (common legacy hashing)
    elseif (md5($password) === $user_row['PASWORD']) {
        $password_valid = true;
        logError("Password verified with MD5");
    }
    
    if (!$password_valid) {
        logError("Password verification failed for user: $username");
        echo json_encode(['success' => false, 'error' => 'Password salah']);
        exit;
    }
    
    logError("Authentication successful, generating ID");
    
    // Generate ID based on username initials
    $words = explode(" ", strtoupper($username));
    $initials = "";
    foreach ($words as $word) {
        $initials .= substr($word, 0, 1);
        if (strlen($initials) >= 3) break;
    }
    
    // If less than 3 letters, add more from the rest
    if (strlen($initials) < 3) {
        $username_clean = str_replace(" ", "", $username);
        $initials .= strtoupper(substr($username_clean, strlen($initials), 3 - strlen($initials)));
    }
    
    $inisial = substr($initials, 0, 3);
    logError("Generated initials: $inisial");
    
    // Find smallest available number
    $used_numbers = [];
    $prefix_like = $inisial . '-%';
    
    logError("Checking pelanggan table for prefix: $prefix_like");
    
    // Check in pelanggan table
    $stmtKode = $conn->prepare("SELECT IDPEL FROM `pelanggan` WHERE `IDPEL` LIKE ?");
    if (!$stmtKode) {
        logError("Failed to prepare pelanggan query: " . $conn->error);
        throw new Exception("Failed to prepare pelanggan query: " . $conn->error);
    }
    
    $stmtKode->bind_param("s", $prefix_like);
    if (!$stmtKode->execute()) {
        logError("Failed to execute pelanggan query: " . $stmtKode->error);
        throw new Exception("Failed to execute pelanggan query: " . $stmtKode->error);
    }
    
    $resultKode = $stmtKode->get_result();
    $pelanggan_count = $resultKode->num_rows;
    logError("Found $pelanggan_count existing pelanggan with prefix $inisial");

    while ($rowKode = $resultKode->fetch_assoc()) {
        $idpel = $rowKode['IDPEL'];
        if (preg_match('/^' . preg_quote($inisial, '/') . '-(\d{3})(?:@|$)/', $idpel, $matches)) {
            $nomor = (int)$matches[1];
            if ($nomor >= 1 && $nomor <= 999) {
                $used_numbers[$nomor] = true;
            }
        }
    }
    $stmtKode->close();

    // Also check provisioning table for PENDING status
    logError("Checking provisioning table for prefix: $prefix_like");
    
    $check_prov_tbl = mysqli_query($conn, "SHOW TABLES LIKE 'provisioning'");
    if ($check_prov_tbl && mysqli_num_rows($check_prov_tbl) > 0) {
        logError("Provisioning table exists");
        
        $stmtProvKode = $conn->prepare("SELECT idpel FROM provisioning WHERE idpel LIKE ? AND status='PENDING'");
        if ($stmtProvKode) {
            $stmtProvKode->bind_param("s", $prefix_like);
            if ($stmtProvKode->execute()) {
                $resultProvKode = $stmtProvKode->get_result();
                $prov_count = $resultProvKode->num_rows;
                logError("Found $prov_count pending provisioning records with prefix $inisial");
                
                while ($rowProv = $resultProvKode->fetch_assoc()) {
                    if (preg_match('/^' . preg_quote($inisial, '/') . '-(\d{3})(?:@|$)/', $rowProv['idpel'], $matches)) {
                        $nomor = (int)$matches[1];
                        if ($nomor >= 1 && $nomor <= 999) {
                            $used_numbers[$nomor] = true;
                        }
                    }
                }
            }
            $stmtProvKode->close();
        } else {
            logError("Failed to prepare provisioning query");
        }
    } else {
        logError("Provisioning table does not exist");
    }

    // Find smallest available number
    $kode_terkecil = null;
    logError("Looking for smallest available ID number from 1-999");
    
    for ($i = 1; $i <= 999; $i++) {
        if (!isset($used_numbers[$i])) {
            $kode_terkecil = $inisial . "-" . str_pad($i, 3, '0', STR_PAD_LEFT);
            logError("Found available ID: $kode_terkecil");
            break;
        }
    }

    if ($kode_terkecil) {
        $date_suffix = date('dmy');
        $full_message = "ID Pelanggan yang disarankan: $kode_terkecil@$date_suffix";
        logError("Returning success: $full_message");
        
        echo json_encode([
            'success' => true,
            'generated_id' => $kode_terkecil,
            'message' => $full_message
        ]);
    } else {
        logError("No available ID numbers for prefix $inisial");
        echo json_encode([
            'success' => false,
            'error' => 'Semua kode dari ' . $inisial . '-001 hingga ' . $inisial . '-999 sudah ada di database'
        ]);
    }
    
} catch (Exception $e) {
    logError("Caught exception: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage()
    ]);
    exit;
}
