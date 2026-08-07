<?php
/**
 * Get actual package from FreeRADIUS users Group attribute
 * Endpoint: crm/billing/getdata/getpackagefromradius.php
 * Method: POST
 * Parameters: idpel (RADIUS username)
 */

require_once __DIR__ . '/../radius_sync_lib.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["error" => "Invalid request method"]);
    exit;
}

$idpel = $_POST['idpel'] ?? '';

if (empty($idpel)) {
    echo json_encode(["error" => "Missing idpel parameter", "package" => "Null"]);
    exit;
}

// Method 0 (utama): FreeRADIUS auth-nya berbasis file "users" (bukan tabel SQL),
// jadi profile aktif harus diambil dari atribut Mikrotik-Group pada blok user yang
// bersangkutan di file tersebut (sumber yang sama dipakai saat user dibuat/diperpanjang,
// lihat crm/billing/api/customer_pppoe.php).
//
// Dibaca lewat radiusReadMergedBlocks() (radius_sync_lib.php) -- bukan
// file_get_contents() langsung ke /etc/freeradius/3.0/users -- karena file itu
// biasanya cuma bisa dibaca root (mis. -rw------- root root), jadi
// is_readable() dari www-data gagal diam-diam dan endpoint ini selalu jatuh ke
// fallback "Null"/N/A walau entrinya jelas ada. radiusReadMergedBlocks() sudah
// otomatis fallback ke "sudo cat" dan sekaligus gabung dengan
// RADIUS_USERS_FILE_MIRROR (mods-config/files/authorize, yang beneran dibaca
// modul `files`), jadi dua-duanya ikut dicek.
foreach (radiusReadMergedBlocks() as $block) {
    if ($block['username'] !== $idpel) {
        continue;
    }
    if (preg_match('/Mikrotik-Group\s*:=\s*"([^"]+)"/', $block['raw'], $groupMatch)) {
        echo json_encode([
            "package" => $groupMatch[1],
            "source" => "freeradius_users_file",
            "idpel" => $idpel,
            "status" => "success"
        ]);
        exit;
    }
}

try {
    // Fallback: Connect to FreeRADIUS database
    $radiusDb = new mysqli('localhost', 'qts', 'Deltaiman@qts92', 'radiusq');
    if ($radiusDb->connect_error) {
        throw new Exception("RADIUS DB connection failed: " . $radiusDb->connect_error);
    }
    
    // Try multiple query methods to find the package
    
    // Method 1: Check if there's a direct Group or package field in users table
    $idpelEsc = $radiusDb->real_escape_string($idpel);
    
    // Query the users table for this username
    $query = "SELECT * FROM users WHERE username = '$idpelEsc' LIMIT 1";
    $result = $radiusDb->query($query);
    
    if ($result && $result->num_rows > 0) {
        $user = $result->fetch_assoc();
        
        // Look for package info in various possible fields
        $package = null;
        
        // Check common field names
        if (isset($user['group']) && !empty($user['group'])) {
            $package = $user['group'];
        } elseif (isset($user['Group']) && !empty($user['Group'])) {
            $package = $user['Group'];
        } elseif (isset($user['package']) && !empty($user['package'])) {
            $package = $user['package'];
        } elseif (isset($user['profile']) && !empty($user['profile'])) {
            $package = $user['profile'];
        } else {
            // If no direct field, check radgroupcheck table for Group attribute
            $query2 = "SELECT groupname FROM radgroupcheck 
                      WHERE username = '$idpelEsc' AND attribute = 'Group' LIMIT 1";
            $result2 = $radiusDb->query($query2);
            
            if ($result2 && $result2->num_rows > 0) {
                $groupRow = $result2->fetch_assoc();
                $package = $groupRow['groupname'] ?? null;
            }
            
            // If still no package, check radusergroup table
            if (empty($package)) {
                $query3 = "SELECT groupname FROM radusergroup 
                          WHERE username = '$idpelEsc' LIMIT 1";
                $result3 = $radiusDb->query($query3);
                
                if ($result3 && $result3->num_rows > 0) {
                    $groupRow = $result3->fetch_assoc();
                    $package = $groupRow['groupname'] ?? null;
                }
            }
        }
        
        $radiusDb->close();
        
        if (!empty($package)) {
            echo json_encode([
                "package" => $package,
                "source" => "radiusq",
                "idpel" => $idpel,
                "status" => "success"
            ]);
        } else {
            echo json_encode([
                "package" => "Null",
                "source" => "radiusq",
                "idpel" => $idpel,
                "status" => "not_found"
            ]);
        }
    } else {
        $radiusDb->close();
        echo json_encode([
            "package" => "Null",
            "source" => "radiusq",
            "idpel" => $idpel,
            "status" => "user_not_found"
        ]);
    }
    
} catch (Exception $e) {
    error_log("RADIUS package fetch error for $idpel: " . $e->getMessage());
    echo json_encode([
        "error" => $e->getMessage(),
        "package" => "Null",
        "status" => "error"
    ]);
}
exit;
?>
