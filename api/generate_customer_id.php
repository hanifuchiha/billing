<?php
// Sebelumnya file ini require '../cek-sesi.php' (bootstrap panel admin, redirect
// ke halaman login kalau tidak ada session browser) -- API key tidak pernah
// dicek. Diganti ke pola auth API yang benar (session ATAU username+password
// ATAU API key dari tabel `apikey`), sama seperti api/odp.php dkk.
require_once '../koneksibilling.php';
require_once '_bootstrap.php';
session_start();
api_cors();

$input = api_read_input();
$auth = api_authenticate($conn, $input);
$pemilik = $auth['pemilik'];
if ($auth['method'] === 'apikey') {
    api_rate_limit($conn, $auth['api_key']);
}

$response = ['success' => false, 'error' => 'Invalid request'];

try {
    // Get username for generating initials
    $username = $pemilik !== '' ? $pemilik : 'DEFAULT';
    
    // Extract initials from username
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
    
    // Find smallest available number
    $used_numbers = [];
    $prefix_like = $inisial . '-%';
    
    // Check in pelanggan table
    $stmtKode = $conn->prepare("SELECT IDPEL FROM `pelanggan` WHERE `IDPEL` LIKE ?");
    if ($stmtKode) {
        $stmtKode->bind_param("s", $prefix_like);
        $stmtKode->execute();
        $resultKode = $stmtKode->get_result();

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
    }

    // Also check provisioning table for PENDING status
    $check_prov_tbl = mysqli_query($conn, "SHOW TABLES LIKE 'provisioning'");
    if ($check_prov_tbl && mysqli_num_rows($check_prov_tbl) > 0) {
        $stmtProvKode = $conn->prepare("SELECT idpel FROM provisioning WHERE idpel LIKE ? AND status='PENDING'");
        if ($stmtProvKode) {
            $stmtProvKode->bind_param("s", $prefix_like);
            $stmtProvKode->execute();
            $resultProvKode = $stmtProvKode->get_result();
            while ($rowProv = $resultProvKode->fetch_assoc()) {
                if (preg_match('/^' . preg_quote($inisial, '/') . '-(\d{3})(?:@|$)/', $rowProv['idpel'], $matches)) {
                    $nomor = (int)$matches[1];
                    if ($nomor >= 1 && $nomor <= 999) {
                        $used_numbers[$nomor] = true;
                    }
                }
            }
            $stmtProvKode->close();
        }
    }

    // Find smallest available number
    $kode_terkecil = null;
    for ($i = 1; $i <= 999; $i++) {
        if (!isset($used_numbers[$i])) {
            $kode_terkecil = $inisial . "-" . str_pad($i, 3, '0', STR_PAD_LEFT);
            break;
        }
    }

    if ($kode_terkecil) {
        $response['success'] = true;
        $response['generated_id'] = $kode_terkecil;
        $response['message'] = "ID Pelanggan yang disarankan: $kode_terkecil@" . date('dmY');
    } else {
        $response['error'] = 'Semua kode sudah digunakan';
    }
} catch (Exception $e) {
    $response['error'] = 'Server error: ' . $e->getMessage();
}

echo json_encode($response);
exit;
?>
