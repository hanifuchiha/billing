<?php
// API Transaksi Harian - Returns individual transactions with status like web version
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    require_once '../koneksibilling.php';
    session_start();

    function resolve_bukti_image_url($bukti_raw)
    {
        $bukti = trim((string)$bukti_raw);
        if ($bukti === '') {
            return '';
        }

        if (preg_match('/^https?:\/\//i', $bukti)) {
            return $bukti;
        }

        if (strpos($bukti, 'manual_active/') === 0) {
            return 'uploads/' . $bukti;
        }

        // Bukti pembayaran baru disimpan di folder dokumen/buktibon (di luar folder crm)
        if (strpos($bukti, 'buktibon/') === 0) {
            return '/dokumen/' . $bukti;
        }

        if (preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $bukti)) {
            return 'uploads/' . ltrim($bukti, '/');
        }

        if (strpos($bukti, 'uploads/') === 0) {
            return $bukti;
        }

        return '';
    }

    $username = $_GET['username'] ?? ($_POST['username'] ?? '');
    $password = $_GET['password'] ?? ($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        echo json_encode(['success' => false, 'error' => 'Username dan password harus diisi']);
        exit;
    }

    // Validasi user dan get user_id
    function auth_user($conn, $username, $password) {
        $stmt = $conn->prepare("SELECT id FROM user WHERE USERNAME = ?");
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
                if (password_verify($password, $verify_row['PASWORD'])) {
                    return $row['id'];
                }
            }
        }
        return false;
    }

    $user_id = auth_user($conn, $username, $password);
    if (!$user_id) {
        echo json_encode(['success' => false, 'error' => 'Autentikasi gagal']);
        exit;
    }

    $today = date('Y-m-d');
    $items = [];

    // Get user servers
    $server_query = mysqli_query($conn, "SELECT DISTINCT pemilik, area FROM server WHERE user_id = " . intval($user_id));
    if (!$server_query) {
        echo json_encode(['success' => true, 'data' => []]);
        exit;
    }

    $server_data = [];
    while ($row = mysqli_fetch_assoc($server_query)) {
        if (!empty($row['pemilik'])) {
            $server_data[] = $row;
        }
    }

    if (!empty($server_data)) {
        $pemilik_map = [];
        foreach ($server_data as $srv) {
            if (!isset($pemilik_map[$srv['pemilik']])) {
                $pemilik_map[$srv['pemilik']] = $srv['area'];
            }
        }

        foreach ($pemilik_map as $pemilik => $area) {
            $pemilik_esc = mysqli_real_escape_string($conn, $pemilik);
            $area_esc = mysqli_real_escape_string($conn, $area);

            $tanggal_bayar_expr = "COALESCE(
                DATE(TANGGALBAYAR),
                STR_TO_DATE(TANGGALBAYAR, '%Y-%m-%d'),
                STR_TO_DATE(TRIM(SUBSTRING_INDEX(TANGGALBAYAR, ',', -1)), '%d %M %Y'),
                STR_TO_DATE(TRIM(SUBSTRING_INDEX(TANGGALBAYAR, ',', -1)), '%d %b %Y'),
                STR_TO_DATE(
                    TRIM(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                        SUBSTRING_INDEX(TANGGALBAYAR, ',', -1),
                        'Januari', '01'
                    ), 'Februari', '02'), 'Maret', '03'), 'April', '04'), 'Mei', '05'), 'Juni', '06'), 'Juli', '07'), 'Agustus', '08'), 'September', '09'), 'Oktober', '10'), 'November', '11'), 'Desember', '12')),
                    '%d %m %Y'
                )
            )";

            // Get KONFIRMASI transactions (no date filter) and BERHASIL/PERMINTAAN KODE from today
            $sql = "SELECT * FROM transaksi 
                    WHERE pemilik='$pemilik_esc' 
                    AND (
                        UPPER(TRIM(COALESCE(STATUS, ''))) = 'KONFIRMASI'
                        OR (
                            UPPER(TRIM(COALESCE(STATUS, ''))) IN ('BERHASIL','PERMINTAAN KODE')
                            AND $tanggal_bayar_expr = '$today'
                        )
                    )
                    ORDER BY id DESC";

            $q = mysqli_query($conn, $sql);
            if ($q) {
                while ($d = mysqli_fetch_assoc($q)) {
                    $status = strtolower($d['STATUS'] ?? '');
                    if ($status === 'berhasil') {
                        $status_type = 'berhasil';
                    } elseif ($status === 'konfirmasi') {
                        $status_type = 'konfirmasi';
                    } else {
                        $status_type = 'permintaan';
                    }

                    $bukti = $d['BUKTI'] ?? '';
                    $bukti_image_url = resolve_bukti_image_url($bukti);

                    $items[] = [
                        'status' => $status_type,
                        'nama' => $d['NAMA'] ?? '',
                        'harga' => (int)($d['HARGA'] ?? 0),
                        'idpel' => $d['IDPEL'] ?? '',
                        'tanggal' => $d['TANGGALBAYAR'] ?? '',
                        'area' => $area_esc,
                        'pemilik' => $pemilik_esc,
                        'metode_bayar' => $d['METODE_BAYAR'] ?? '',
                        'bukti' => $bukti,
                        'bukti_image_url' => $bukti_image_url,
                        'id' => $d['id'] ?? null
                    ];
                }
            }
        }
    }

    // Sort: KONFIRMASI > BERHASIL > PENAGIHAN, then by newest ID
    $statusPriority = ['konfirmasi' => 0, 'berhasil' => 1, 'permintaan' => 2];
    usort($items, function ($a, $b) use ($statusPriority) {
        $pa = $statusPriority[$a['status']] ?? 99;
        $pb = $statusPriority[$b['status']] ?? 99;
        if ($pa !== $pb) return $pa <=> $pb;
        $ida = isset($a['id']) ? (int)$a['id'] : 0;
        $idb = isset($b['id']) ? (int)$b['id'] : 0;
        return $idb <=> $ida;
    });

    echo json_encode(['success' => true, 'data' => $items]);
    exit;

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}
?>
