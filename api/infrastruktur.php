<?php
// API Infrastructure - Returns data dari cache file serverlog seperti versi web
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    require_once '../koneksibilling.php';
    session_start();

    $username = $_GET['username'] ?? ($_POST['username'] ?? '');
    $password = $_GET['password'] ?? ($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        echo json_encode(['success' => false, 'error' => 'Username dan password harus diisi']);
        exit;
    }

    // Validasi user
    function auth_user($conn, $username, $password) {
        $stmt = $conn->prepare("SELECT id, USERNAME, STATUS, ticket_management_source FROM user WHERE USERNAME = ?");
        if (!$stmt) return false;
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $stmt_check = $conn->prepare("SELECT PASWORD FROM user WHERE id = ?");
            $stmt_check->bind_param("i", $row['id']);
            $stmt_check->execute();
            $verify = $stmt_check->get_result();
            if ($verify->num_rows > 0) {
                $verify_row = $verify->fetch_assoc();
                if (password_verify($password, $verify_row['PASWORD']) || (string)$password === (string)$verify_row['PASWORD']) {
                    return [
                        'id' => (int)$row['id'],
                        'username' => (string)$row['USERNAME'],
                        'status' => strtoupper(trim((string)($row['STATUS'] ?? ''))),
                        'ticket_management_source' => strtolower(trim((string)($row['ticket_management_source'] ?? 'tiket_manager')))
                    ];
                }
            }
        }
        return false;
    }

    $user_data = auth_user($conn, $username, $password);
    if (!$user_data) {
        echo json_encode(['success' => false, 'error' => 'Autentikasi gagal']);
        exit;
    }
    
    $pemilik = $user_data['username'];

    // 1. Ambil data dari cache file serverlog/[PEMILIK].txt (seperti versi web)
    $serverlog_file = __DIR__ . '/../serverlog/' . $pemilik . '.txt';
    
    $cache_data = [
        'Total_pelanggan' => 0,
        'Total_online_paket' => 0,
        'Total_online_expired' => 0,
        'Total_los_internet' => 0,
        'Total_expired_offline' => 0
    ];
    
    if (file_exists($serverlog_file)) {
        $json_content = file_get_contents($serverlog_file);
        if ($json_content) {
            $cache_data = json_decode($json_content, true);
            if (!is_array($cache_data)) {
                $cache_data = [
                    'Total_pelanggan' => 0,
                    'Total_online_paket' => 0,
                    'Total_online_expired' => 0,
                    'Total_los_internet' => 0,
                    'Total_expired_offline' => 0
                ];
            }
        }
    }

    // 2. Ambil infrastructure data dari database (samakan rumus dengan dashboard web)
    $infrastructure = [
        'servers' => 0,
        'areas' => 0,
        'olts' => 0,
        'odps' => 0,
        'hompas' => 0
    ];

    $pemilik_esc = mysqli_real_escape_string($conn, $pemilik);
    $where_clause = "pemilik = '$pemilik_esc'";

    $infra_query = "SELECT 
        (SELECT COUNT(*) FROM server WHERE $where_clause) as servers,
        (SELECT COUNT(DISTINCT AREA) FROM server WHERE $where_clause) as areas,
        (SELECT COUNT(DISTINCT AREA) FROM olt WHERE $where_clause) as olts,
        (SELECT COUNT(*) FROM odp WHERE $where_clause) as odps,
        (SELECT COALESCE(SUM(
            CASE
              WHEN splitter='1:2' THEN 2
              WHEN splitter='1:4' THEN 4
              WHEN splitter='1:8' THEN 8
              WHEN splitter='1:16' THEN 16
              WHEN splitter='1:32' THEN 32
              ELSE 0
            END
        ), 0) FROM odp WHERE $where_clause AND Hirarki='ODP') as hompas";
    
    $infra_result = mysqli_query($conn, $infra_query);
    if ($infra_result && ($row = mysqli_fetch_assoc($infra_result))) {
        $infrastructure = [
            'servers' => (int)($row['servers'] ?? 0),
            'areas' => (int)($row['areas'] ?? 0),
            'olts' => (int)($row['olts'] ?? 0),
            'odps' => (int)($row['odps'] ?? 0),
            'hompas' => (int)($row['hompas'] ?? 0)
        ];
    }

    $ticketSource = in_array((string)($user_data['ticket_management_source'] ?? ''), ['tiket_manager', 'joblist'], true)
        ? (string)$user_data['ticket_management_source']
        : 'tiket_manager';
    if ((string)($user_data['status'] ?? '') !== 'ADMIN') {
        $ticketSource = 'tiket_manager';
    }

    // 3. Ambil customer status sesuai source tiket aktif
    $customer_status = [
        'instalasi' => 0,
        'dismantel' => 0,
        'maintenance' => 0,
        'migrasi' => 0
    ];

    if ($ticketSource === 'tiket_manager') {
        $ownerId = (int)$user_data['id'];
        $status_query = "SELECT
            (SELECT COUNT(*) FROM billing_tiket_manager WHERE tipe = 'INSTALLASI' AND status IN ('BARU', 'PENDING') AND server_id IN (SELECT id FROM server WHERE user_id = $ownerId)) as instalasi,
            (SELECT COUNT(*) FROM billing_tiket_manager WHERE tipe = 'DISMANTLE' AND status IN ('BARU', 'PENDING') AND server_id IN (SELECT id FROM server WHERE user_id = $ownerId)) as dismantel,
            (SELECT COUNT(*) FROM billing_tiket_manager WHERE tipe = 'MAINTENANCE' AND status IN ('BARU', 'PENDING') AND server_id IN (SELECT id FROM server WHERE user_id = $ownerId)) as maintenance,
            (SELECT COUNT(*) FROM billing_tiket_manager WHERE tipe = 'MIGRASI' AND status IN ('BARU', 'PENDING') AND server_id IN (SELECT id FROM server WHERE user_id = $ownerId)) as migrasi";
    } else {
        $status_query = "SELECT 
            (SELECT COUNT(*) FROM absensi.joblist WHERE tipe = 'INSTALLASI' AND status IN ('BARU', 'PENDING') AND project = '" . mysqli_real_escape_string($conn, $pemilik) . "') as instalasi,
            (SELECT COUNT(*) FROM absensi.joblist WHERE tipe = 'DISMANTLE' AND status IN ('BARU', 'PENDING') AND project = '" . mysqli_real_escape_string($conn, $pemilik) . "') as dismantel,
            (SELECT COUNT(*) FROM absensi.joblist WHERE tipe = 'MAINTENANCE' AND status IN ('BARU', 'PENDING') AND project = '" . mysqli_real_escape_string($conn, $pemilik) . "') as maintenance,
            (SELECT COUNT(*) FROM absensi.joblist WHERE tipe = 'MIGRASI' AND status IN ('BARU', 'PENDING') AND project = '" . mysqli_real_escape_string($conn, $pemilik) . "') as migrasi";
    }
    
    $status_result = mysqli_query($conn, $status_query);
    if ($status_result && ($row = mysqli_fetch_assoc($status_result))) {
        $customer_status = [
            'instalasi' => (int)($row['instalasi'] ?? 0),
            'dismantel' => (int)($row['dismantel'] ?? 0),
            'maintenance' => (int)($row['maintenance'] ?? 0),
            'migrasi' => (int)($row['migrasi'] ?? 0)
        ];
    }

    // Return combined data
    echo json_encode([
        'success' => true,
        'infrastructure' => $infrastructure,
        'customer_status' => $customer_status,
        'customer_counts' => [
            'total_pelanggan' => (int)($cache_data['Total_pelanggan'] ?? 0),
            'total_online' => (int)($cache_data['Total_online_paket'] ?? 0),
            'total_los' => (int)($cache_data['Total_los_internet'] ?? 0),
            'total_expired_online' => (int)($cache_data['Total_online_expired'] ?? 0),
            'total_expired_offline' => (int)($cache_data['Total_expired_offline'] ?? 0)
        ]
    ]);
    exit;

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}
