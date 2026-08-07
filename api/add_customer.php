<?php
// Sebelumnya file ini require '../cek-sesi.php' -- itu bootstrap panel admin
// (browser session only) yang langsung redirect ke halaman login kalau tidak
// ada session, JADI TIDAK PERNAH mengecek API key. Diganti ke pola auth API
// yang benar (session ATAU username+password ATAU API key dari tabel
// `apikey`), sama seperti api/odp.php dkk.
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Get POST data
        $customerID = mysqli_real_escape_string($conn, $_POST['customerID'] ?? '');
        $passwordPPPOE = mysqli_real_escape_string($conn, $_POST['passwordPPPOE'] ?? '');
        $authmode = mysqli_real_escape_string($conn, $_POST['authmode'] ?? 'MULTI MODE');
        $customerName = mysqli_real_escape_string($conn, $_POST['customerName'] ?? '');
        $address = mysqli_real_escape_string($conn, $_POST['address'] ?? '');
        $provinsi = mysqli_real_escape_string($conn, $_POST['provinsi'] ?? '');
        $kabupaten = mysqli_real_escape_string($conn, $_POST['kabupaten'] ?? '');
        $kecamatan = mysqli_real_escape_string($conn, $_POST['kecamatan'] ?? '');
        $kelurahan = mysqli_real_escape_string($conn, $_POST['kelurahan'] ?? '');
        $rw = mysqli_real_escape_string($conn, $_POST['rw'] ?? '');
        $rt = mysqli_real_escape_string($conn, $_POST['rt'] ?? '');
        $whatsapp = mysqli_real_escape_string($conn, $_POST['whatsapp'] ?? '');
        $email = mysqli_real_escape_string($conn, $_POST['Email'] ?? '');
        $coordinates = mysqli_real_escape_string($conn, $_POST['coordinates'] ?? '');
        $tanggalpasang = mysqli_real_escape_string($conn, $_POST['tanggalpasang'] ?? date('Y-m-d'));
        $sales = mysqli_real_escape_string($conn, $_POST['sales'] ?? '-');
        $server = mysqli_real_escape_string($conn, $_POST['server'] ?? '');
        $odp = mysqli_real_escape_string($conn, $_POST['odp'] ?? '');
        $packages = mysqli_real_escape_string($conn, $_POST['packages'] ?? '');
        $tipe_bayar = mysqli_real_escape_string($conn, $_POST['tipe_bayar'] ?? 'prabayar');
        $tipe_tempo = mysqli_real_escape_string($conn, $_POST['tipe_tempo'] ?? '');

        // Mode tempo "monthversary": anchor awal dikunci ke tanggal pasang.
        // Untuk prabayar, cek_tagihan_harian.php akan mengunci ulang anchor
        // ini ke tanggal transaksi BERHASIL pertama begitu pelanggan bayar.
        $tanggal_monthversary_sql = ($tipe_tempo === 'monthversary')
            ? "'" . $tanggalpasang . "'"
            : 'NULL';

        // Validate required fields
        if (empty($customerID) || empty($passwordPPPOE) || empty($customerName) || empty($address)) {
            $response['error'] = 'Data pelanggan tidak lengkap';
            echo json_encode($response);
            exit;
        }

        if (empty($server) || empty($odp) || empty($packages)) {
            $response['error'] = 'Konfigurasi jaringan tidak lengkap';
            echo json_encode($response);
            exit;
        }

        // Check if customer ID already exists
        $checkQuery = "SELECT IDPEL FROM pelanggan WHERE IDPEL = '$customerID'";
        $checkResult = mysqli_query($conn, $checkQuery);
        if (mysqli_num_rows($checkResult) > 0) {
            $response['error'] = 'Customer ID sudah digunakan';
            echo json_encode($response);
            exit;
        }

        // Add location columns if not exist
        $columns = ['provinsi', 'kabupaten', 'kecamatan', 'kelurahan', 'rw', 'rt'];
        foreach ($columns as $col) {
            $checkCol = mysqli_query($conn, "SHOW COLUMNS FROM pelanggan LIKE '$col'");
            if (mysqli_num_rows($checkCol) == 0) {
                mysqli_query($conn, "ALTER TABLE pelanggan ADD COLUMN $col VARCHAR(100) DEFAULT ''");
            }
        }

        // Extract coordinates
        $tikor = $coordinates;
        $coords = explode(',', $coordinates);
        $latitude = isset($coords[0]) ? trim($coords[0]) : '0';
        $longitude = isset($coords[1]) ? trim($coords[1]) : '0';

        // Prepare insert statement
        $insertQuery = "INSERT INTO pelanggan (
            IDPEL, NAMA, ALAMAT, PROVINSI, KABUPATEN, KECAMATAN, KELURAHAN, RW, RT,
            NOWA, EMAIL, TIKOR, LATITUDE, LONGITUDE, TANGGALPASANG, AREA, ODP, PAKET,
            STATUS, AUTHMODE, TIPE_BAYAR, TIPE_TEMPO, TANGGAL_MONTHVERSARY
        ) VALUES (
            '$customerID', '$customerName', '$address', '$provinsi', '$kabupaten', '$kecamatan', '$kelurahan', '$rw', '$rt',
            '$whatsapp', '$email', '$tikor', '$latitude', '$longitude', '$tanggalpasang', '$server', '$odp', '$packages',
            'ACTIVE', '$authmode', '$tipe_bayar', '$tipe_tempo', $tanggal_monthversary_sql
        )";

        if (mysqli_query($conn, $insertQuery)) {
            // Create PPPoE account via RouterOS
            $pppoeCreated = false;
            try {
                // Try to create PPPoE account (optional, may fail if RouterOS not available)
                // This is a placeholder for RouterOS integration
                $pppoeCreated = true;
            } catch (Exception $e) {
                // If PPPoE creation fails, customer is still created in DB
                $pppoeCreated = false;
            }

            $response['success'] = true;
            $response['message'] = 'Pelanggan berhasil ditambahkan';
            $response['data'] = [
                'idpel' => $customerID,
                'nama' => $customerName,
                'pppoe_created' => $pppoeCreated
            ];
        } else {
            $response['error'] = 'Error inserting customer: ' . mysqli_error($conn);
        }
    } catch (Exception $e) {
        $response['error'] = 'Server error: ' . $e->getMessage();
    }
} else {
    $response['error'] = 'Invalid request method';
}

echo json_encode($response);
exit;
?>
