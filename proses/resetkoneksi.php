<?php
/**
 * Reset Koneksi - Hapus Active PPP Connection di MikroTik
 * Mirip dengan proses disable, tapi hanya remove active connection tanpa ubah profile
 * Method: POST
 * Parameters: IDPEL, PEMILIK, AREA, NAMA, NOWA
 */

require '../cek-sesi.php';
require '../routeros_api.class.php';

session_start();
header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["status" => "error", "message" => "Invalid method"]);
    exit;
}

// Ambil parameter dari form/modal
$idpel = $_POST["IDPEL"] ?? $_POST["idpel"] ?? "";
$pemilik = $_POST["PEMILIK"] ?? "";
$area = $_POST["AREA"] ?? "";
$nama = $_POST["NAMA"] ?? "";
$nowa = $_POST["NOWA"] ?? "";

if (empty($idpel) || empty($pemilik) || empty($area)) {
    echo json_encode(["status" => "error", "message" => "Parameter tidak lengkap"]);
    exit;
}

try {
    $reset_log = [];
    $total_servers = 0;
    $success_servers = 0;
    $connection_status = "";
    
    // Query semua server di area & pemilik yang sama (seperti disable)
    $sql = "SELECT * FROM `server` WHERE `AREA` = '$area' AND `PEMILIK` = '$pemilik'";
    $query = mysqli_query($conn, $sql);
    
    if (!$query) {
        throw new Exception("Query server gagal: " . mysqli_error($conn));
    }
    
    while ($data = mysqli_fetch_array($query)) {
        $total_servers++;
        $server_ip = $data['IP'];
        $server_user = $data['PEMILIK'];
        $server_password = $data['PASSWORD'];
        
        $reset_log[] = "Server $server_ip: ";
        
        $API = new RouterosAPI();
        
        // Koneksi ke MikroTik
        if ($API->connect($server_ip, $server_user, $server_password)) {
            $reset_log[] = "  ✓ Koneksi berhasil";
            
            // Cari active connection untuk IDPEL ini
            $activeUsers = $API->comm("/ppp/active/print");
            $found_active = 0;
            $removed_active = 0;
            
            foreach ($activeUsers as $active) {
                if ($active['name'] == $idpel) {
                    $found_active++;
                    $reset_log[] = "  - Ditemukan koneksi aktif untuk $idpel";
                    
                    // Remove active connection
                    $remove_result = $API->comm(
                        "/ppp/active/remove",
                        array(".id" => $active[".id"])
                    );
                    
                    if ($remove_result !== false) {
                        $removed_active++;
                        $reset_log[] = "  ✓ Koneksi berhasil dihapus";
                    } else {
                        $reset_log[] = "  ✗ Gagal menghapus koneksi";
                    }
                }
            }
            
            if ($found_active > 0) {
                $success_servers++;
                $connection_status .= "Server $server_ip: $removed_active/$found_active koneksi dihapus; ";
            } else {
                $connection_status .= "Server $server_ip: Tidak ada koneksi aktif; ";
            }
            
            $API->disconnect();
        } else {
            $reset_log[] = "  ✗ Koneksi ke MikroTik gagal";
            $connection_status .= "Server $server_ip: GAGAL KONEKSI; ";
        }
    }
    
    // Log ke history file (seperti disable)
    $history_file = "../notifbot/data/history-$ceknama.json";
    $history = [];
    
    if (file_exists($history_file)) {
        $history = json_decode(file_get_contents($history_file), true);
    }
    
    if (!is_array($history)) {
        $history = [];
    }
    
    $action_desc = "Reset koneksi pelanggan $idpel ($nama)";
    $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] $action_desc - Hasil: $connection_status";
    
    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    
    // Response ke frontend
    if ($total_servers > 0 && $success_servers > 0) {
        echo json_encode([
            "status" => "success",
            "message" => "Koneksi berhasil direset! User akan reconnect otomatis.",
            "idpel" => $idpel,
            "nama" => $nama,
            "detail" => $connection_status,
            "log" => $reset_log
        ]);
    } elseif ($total_servers > 0) {
        echo json_encode([
            "status" => "info",
            "message" => "Tidak ada koneksi aktif yang ditemukan",
            "idpel" => $idpel,
            "detail" => $connection_status,
            "log" => $reset_log
        ]);
    } else {
        echo json_encode([
            "status" => "warning",
            "message" => "Tidak ditemukan server untuk area ini",
            "idpel" => $idpel
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => "Error: " . $e->getMessage()
    ]);
}

exit;
?>
