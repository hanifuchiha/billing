<?php
header('Content-Type: application/json');
require_once '../koneksibilling.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    // Get POST data
    $customerID = isset($_POST['customerID']) ? trim($_POST['customerID']) : '';
    $customerID_old = isset($_POST['customerID_old']) ? trim($_POST['customerID_old']) : '';
    $passwordPPPOE = isset($_POST['passwordPPPOE']) ? trim($_POST['passwordPPPOE']) : '';
    $authmode = isset($_POST['authmode']) ? trim($_POST['authmode']) : 'MULTI MODE';
    $customerName = isset($_POST['customerName']) ? trim($_POST['customerName']) : '';
    $address = isset($_POST['address']) ? trim($_POST['address']) : '';
    $provinsi = isset($_POST['provinsi']) ? trim($_POST['provinsi']) : '';
    $kabupaten = isset($_POST['kabupaten']) ? trim($_POST['kabupaten']) : '';
    $kecamatan = isset($_POST['kecamatan']) ? trim($_POST['kecamatan']) : '';
    $kelurahan = isset($_POST['kelurahan']) ? trim($_POST['kelurahan']) : '';
    $rw = isset($_POST['rw']) ? trim($_POST['rw']) : '';
    $rt = isset($_POST['rt']) ? trim($_POST['rt']) : '';
    $whatsapp = isset($_POST['whatsapp']) ? trim($_POST['whatsapp']) : '';
    $email = isset($_POST['Email']) ? trim($_POST['Email']) : '';
    $coordinates = isset($_POST['coordinates']) ? trim($_POST['coordinates']) : '';
    $tanggalpasang = isset($_POST['tanggalpasang']) ? trim($_POST['tanggalpasang']) : date('Y-m-d');
    $sales = isset($_POST['sales']) ? trim($_POST['sales']) : '-';
    $server = isset($_POST['server']) ? trim($_POST['server']) : '';
    $odp = isset($_POST['odp']) ? trim($_POST['odp']) : '';
    $packages = isset($_POST['packages']) ? trim($_POST['packages']) : '';
    $tipe_bayar = isset($_POST['tipe_bayar']) ? trim($_POST['tipe_bayar']) : 'prabayar';
    $tipe_tempo = isset($_POST['tipe_tempo']) ? trim($_POST['tipe_tempo']) : '';

    // Validate required fields
    if (empty($customerID_old)) {
        echo json_encode(['success' => false, 'error' => 'Customer ID lama tidak ditemukan']);
        exit;
    }

    if (empty($customerID) || empty($passwordPPPOE) || empty($customerName) || empty($address)) {
        echo json_encode(['success' => false, 'error' => 'Data pelanggan tidak lengkap']);
        exit;
    }

    if (empty($server) || empty($odp) || empty($packages)) {
        echo json_encode(['success' => false, 'error' => 'Konfigurasi jaringan tidak lengkap']);
        exit;
    }

    // Validate PPPOE credentials - no spaces allowed
    if (preg_match('/\s/', $customerID)) {
        echo json_encode(['success' => false, 'error' => 'PPPOE Username tidak boleh mengandung spasi']);
        exit;
    }

    if (preg_match('/\s/', $passwordPPPOE)) {
        echo json_encode(['success' => false, 'error' => 'PPPOE Password tidak boleh mengandung spasi']);
        exit;
    }

    // Validate auth mode
    $valid_modes = ['RADIUS MODE', 'API MODE', 'MULTI MODE'];
    if (!in_array($authmode, $valid_modes)) {
        $authmode = 'API MODE';
    }

    // Format WhatsApp number
    $whatsappedit = $whatsapp;
    if (!preg_match('/[^+0-9]/', trim($whatsapp))) {
        if (substr(trim($whatsapp), 0, 2) == '62') {
            $whatsappedit = trim($whatsapp);
        } elseif (substr(trim($whatsapp), 0, 3) == '+62') {
            $whatsappedit = '62' . substr(trim($whatsapp), 1);
        } elseif (substr(trim($whatsapp), 0, 1) == '0') {
            $whatsappedit = '62' . substr(trim($whatsapp), 1);
        }
    }

    // Escape all input for database
    $customerID = $conn->real_escape_string($customerID);
    $customerID_old = $conn->real_escape_string($customerID_old);
    $passwordPPPOE = $conn->real_escape_string($passwordPPPOE);
    $customerName = $conn->real_escape_string($customerName);
    $address = $conn->real_escape_string($address);
    $provinsi = $conn->real_escape_string($provinsi);
    $kabupaten = $conn->real_escape_string($kabupaten);
    $kecamatan = $conn->real_escape_string($kecamatan);
    $kelurahan = $conn->real_escape_string($kelurahan);
    $rw = $conn->real_escape_string($rw);
    $rt = $conn->real_escape_string($rt);
    $whatsappedit = $conn->real_escape_string($whatsappedit);
    $email = $conn->real_escape_string($email);
    $coordinates = $conn->real_escape_string($coordinates);
    $tanggalpasang = $conn->real_escape_string($tanggalpasang);
    $sales = $conn->real_escape_string($sales);
    $server = $conn->real_escape_string($server);
    $odp = $conn->real_escape_string($odp);
    $packages = $conn->real_escape_string($packages);
    $tipe_bayar = $conn->real_escape_string($tipe_bayar);
    $tipe_tempo = $conn->real_escape_string($tipe_tempo);

    // Check if customer exists
    $checkQuery = "SELECT IDPEL, TANGGAL_MONTHVERSARY FROM pelanggan WHERE IDPEL = '$customerID_old' LIMIT 1";
    $checkResult = $conn->query($checkQuery);
    if (!$checkResult || $checkResult->num_rows == 0) {
        echo json_encode(['success' => false, 'error' => 'Customer tidak ditemukan']);
        exit;
    }
    $oldRow = $checkResult->fetch_assoc();

    // Mode tempo "monthversary": kunci anchor sekali saja kalau belum pernah
    // diisi. Prabayar diambil dari transaksi BERHASIL terakhir (kalau sudah
    // pernah bayar), selain itu diambil dari tanggal pasang.
    // cek_tagihan_harian.php akan mengunci ulang ke transaksi pertama
    // pelanggan prabayar begitu pembayaran pertamanya masuk.
    $tanggal_monthversary_set_sql = '';
    if ($tipe_tempo === 'monthversary' && empty($oldRow['TANGGAL_MONTHVERSARY'] ?? null)) {
        $anchorBaru = null;
        if ($tipe_bayar === 'prabayar') {
            $lastTrxQuery = $conn->query("SELECT MAX(TANGGALBAYAR) as waktu_terakhir FROM transaksi WHERE IDPEL = '$customerID_old' AND STATUS = 'BERHASIL'");
            if ($lastTrxQuery && ($lastTrxRow = $lastTrxQuery->fetch_assoc()) && !empty($lastTrxRow['waktu_terakhir'])) {
                $anchorBaru = substr((string)$lastTrxRow['waktu_terakhir'], 0, 10);
            }
        }
        if (empty($anchorBaru)) {
            $anchorBaru = $tanggalpasang;
        }
        if (!empty($anchorBaru)) {
            $tanggal_monthversary_set_sql = ",\n        TANGGAL_MONTHVERSARY = '" . $conn->real_escape_string($anchorBaru) . "'";
        }
    }

    // If customerID changed, check if new ID is already used
    if ($customerID !== $customerID_old) {
        $checkNewID = "SELECT IDPEL FROM pelanggan WHERE IDPEL = '$customerID' LIMIT 1";
        $checkNewResult = $conn->query($checkNewID);
        if ($checkNewResult && $checkNewResult->num_rows > 0) {
            echo json_encode(['success' => false, 'error' => 'Customer ID baru sudah digunakan']);
            exit;
        }
    }

    // Update customer data
    $updateQuery = "UPDATE pelanggan SET 
        IDPEL = '$customerID',
        PASSWORD = '$passwordPPPOE',
        MODE = '$authmode',
        NAMA = '$customerName',
        ALAMAT = '$address',
        provinsi = '$provinsi',
        kabupaten = '$kabupaten',
        kecamatan = '$kecamatan',
        kelurahan = '$kelurahan',
        rw = '$rw',
        rt = '$rt',
        NOWA = '$whatsappedit',
        EMAIL = '$email',
        TIKOR = '$coordinates',
        TANGGALPASANG = '$tanggalpasang',
        sales = '$sales',
        PEMILIK = '$server',
        ODP = '$odp',
        PAKET = '$packages',
        TIPE_BAYAR = '$tipe_bayar',
        TIPE_TEMPO = '$tipe_tempo'$tanggal_monthversary_set_sql
        WHERE IDPEL = '$customerID_old'
    ";

    if ($conn->query($updateQuery)) {
        echo json_encode([
            'success' => true,
            'message' => 'Data pelanggan berhasil diperbarui',
            'idpel' => $customerID
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Gagal memperbarui data: ' . $conn->error
        ]);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage()
    ]);
}
