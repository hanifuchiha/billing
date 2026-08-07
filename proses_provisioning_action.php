<?php
/**
 * Proses Provisioning Action - Handles approve/reject/detail from billing side
 */
require 'cek-sesi.php';
require 'routeros_api.class.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Validate that the provisioning record belongs to this owner
function getProvisioningRecord($conn, $id, $USER_ID) {
    $stmt = $conn->prepare("SELECT p.* FROM provisioning p 
        INNER JOIN server s ON p.server_pemilik = s.PEMILIK 
        WHERE p.id = ? AND s.user_id = ? 
        LIMIT 1");
    $stmt->bind_param('ii', $id, $USER_ID);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row;
}

// Get server connection info
function getServerInfo($conn, $pemilik, $area) {
    $stmt = $conn->prepare("SELECT * FROM server WHERE PEMILIK = ? AND AREA = ? LIMIT 1");
    $stmt->bind_param('ss', $pemilik, $area);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row;
}

// Get paket options for provisioning owner/server
function getPaketOptions($conn, $pemilik, $area = '') {
    $options = [];

    if ($area !== '') {
        $stmt = $conn->prepare("SELECT id, PAKET, HARGA FROM paket WHERE PEMILIK = ? AND AREA = ? ORDER BY PAKET ASC");
        $stmt->bind_param('ss', $pemilik, $area);
    } else {
        $stmt = $conn->prepare("SELECT id, PAKET, HARGA FROM paket WHERE PEMILIK = ? ORDER BY PAKET ASC");
        $stmt->bind_param('s', $pemilik);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    $rows = reseller_filter_rows($conn, reseller_collect_rows($res), 'broadband');
    $stmt->close();
    foreach ($rows as $row) {
        $paketName = trim((string)($row['PAKET'] ?? ''));
        if ($paketName !== '') {
            $options[] = $paketName;
        }
    }

    if (empty($options) && $area !== '') {
        $stmt2 = $conn->prepare("SELECT id, PAKET, HARGA FROM paket WHERE PEMILIK = ? ORDER BY PAKET ASC");
        $stmt2->bind_param('s', $pemilik);
        $stmt2->execute();
        $res2 = $stmt2->get_result();
        $rows2 = reseller_filter_rows($conn, reseller_collect_rows($res2), 'broadband');
        $stmt2->close();
        foreach ($rows2 as $row2) {
            $paketName2 = trim((string)($row2['PAKET'] ?? ''));
            if ($paketName2 !== '') {
                $options[] = $paketName2;
            }
        }
    }

    return array_values(array_unique($options));
}

// Get joblist ticket details and evidence photos
function getJoblistTicketData($tiket_id) {
    // Load joblist config
    $joblist_config_file = __DIR__ . '/../joblist/config.json';
    if (!file_exists($joblist_config_file)) {
        return ['ticket_data' => null, 'evidence_photos' => []];
    }
    
    $joblist_config = json_decode(file_get_contents($joblist_config_file), true);
    
    // Connect to joblist database if different from billing
    $joblist_host = $joblist_config['db_host_absensi'] ?? '';
    $joblist_user = $joblist_config['db_user_absensi'] ?? '';
    $joblist_pass = $joblist_config['db_pass_absensi'] ?? '';
    $joblist_db = $joblist_config['db_name_absensi'] ?? '';
    
    if (empty($joblist_host)) {
        return ['ticket_data' => null, 'evidence_photos' => []];
    }
    
    $joblist_conn = mysqli_connect($joblist_host, $joblist_user, $joblist_pass, $joblist_db);
    if (!$joblist_conn) {
        return ['ticket_data' => null, 'evidence_photos' => []];
    }
    
    // Get joblist ticket data
    $stmt = $joblist_conn->prepare("SELECT id, data, report, status, team, project, tipe, waktu FROM joblist WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $tiket_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $ticket_data = $result->fetch_assoc();
    $stmt->close();
    
    $evidence_photos = [];
    if ($ticket_data && $tiket_id > 0) {
        // Get evidence photos from folder
        $direkturi_joblist = $joblist_config['direktori_joblist'] ?? '/var/www/quenbytekniksejahtera/crm/joblist';
        $url_joblist = $joblist_config['url_joblist'] ?? 'https://quenbytekniksejahtera.com/crm/joblist';

        // Foto evidence sekarang disimpan terpusat di dokumen/joblist/ (sejajar crm/, di luar folder crm)
        $direktori_dokumen_joblist = rtrim(preg_replace('#/crm/joblist/?$#', '', $direkturi_joblist), '/') . '/dokumen/joblist';
        $url_dokumen_joblist = rtrim(preg_replace('#/crm/joblist/?$#', '', $url_joblist), '/') . '/dokumen/joblist';

        $evidence_folder = "{$direktori_dokumen_joblist}/{$tiket_id}/";

        if (is_dir($evidence_folder)) {
            $image_files = glob($evidence_folder . "*.{jpg,jpeg,png,gif,JPG,JPEG,PNG}", GLOB_BRACE);
            if (!empty($image_files)) {
                foreach ($image_files as $file_path) {
                    $filename = basename($file_path);
                    $image_url = "{$url_dokumen_joblist}/{$tiket_id}/" . $filename;
                    $evidence_photos[] = [
                        'url' => $image_url,
                        'filename' => $filename
                    ];
                }
            }
        }
    }
    
    mysqli_close($joblist_conn);
    
    return [
        'ticket_data' => $ticket_data,
        'evidence_photos' => $evidence_photos
    ];
}

// ==================== DETAIL ====================
if ($action === 'detail' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $row = getProvisioningRecord($conn, $id, $USER_ID);
    if ($row) {
        $row['paket_options'] = getPaketOptions($conn, (string)$row['server_pemilik'], (string)($row['area'] ?? ''));
        
        // Get joblist ticket data and evidence photos
        $tiket_id = intval($row['tiket_id'] ?? 0);
        if ($tiket_id > 0) {
            $joblist_data = getJoblistTicketData($tiket_id);
            $row['joblist_ticket'] = $joblist_data['ticket_data'];
            $row['evidence_photos'] = $joblist_data['evidence_photos'];
        } else {
            $row['joblist_ticket'] = null;
            $row['evidence_photos'] = [];
        }
        
        echo json_encode(['success' => true, 'data' => $row]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Data tidak ditemukan']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$id = intval($_POST['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID tidak valid']);
    exit;
}

$prov = getProvisioningRecord($conn, $id, $USER_ID);
if (!$prov) {
    echo json_encode(['success' => false, 'message' => 'Data provisioning tidak ditemukan']);
    exit;
}

if ($prov['status'] !== 'PENDING' && !($action === 'reactivate' && $prov['status'] === 'EXPIRED')) {
    echo json_encode(['success' => false, 'message' => 'Hanya data PENDING yang dapat diproses (status: ' . $prov['status'] . ')']);
    exit;
}

// ==================== APPROVE ====================
if ($action === 'approve') {
    $allowedApproveUpdates = [
        'nama', 'nowa', 'email', 'tanggal_pasang', 'tipe_bayar', 'tipe_tempo',
        'tikor', 'alamat', 'paket'
    ];

    $validTipeBayar = ['prabayar', 'pascabayar'];
    $validTipeTempo = ['mengikuti_tanggal_bayar', 'mengikuti_tanggal_tempo', 'monthversary'];

    $updateValues = [];
    foreach ($allowedApproveUpdates as $field) {
        if (array_key_exists($field, $_POST)) {
            $value = trim((string)$_POST[$field]);
            if ($field === 'tanggal_pasang' && $value !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                echo json_encode(['success' => false, 'message' => 'Format tanggal pasang tidak valid']);
                exit;
            }
            if ($field === 'tipe_bayar' && $value !== '' && !in_array($value, $validTipeBayar, true)) {
                echo json_encode(['success' => false, 'message' => 'Tipe bayar tidak valid']);
                exit;
            }
            if ($field === 'tipe_tempo' && $value !== '' && !in_array($value, $validTipeTempo, true)) {
                echo json_encode(['success' => false, 'message' => 'Tipe tempo tidak valid']);
                exit;
            }
            $updateValues[$field] = $value;
        }
    }

    if (isset($updateValues['nama']) && $updateValues['nama'] === '') {
        echo json_encode(['success' => false, 'message' => 'Nama tidak boleh kosong']);
        exit;
    }

    if (isset($updateValues['paket']) && $updateValues['paket'] !== '') {
        $availablePaket = getPaketOptions($conn, (string)$prov['server_pemilik'], (string)($prov['area'] ?? ''));
        if (!in_array($updateValues['paket'], $availablePaket, true)) {
            echo json_encode(['success' => false, 'message' => 'Paket tidak tersedia untuk server ini']);
            exit;
        }
    }

    if (!empty($updateValues)) {
        $setParts = [];
        $types = '';
        $params = [];
        foreach ($updateValues as $field => $value) {
            $setParts[] = "$field = ?";
            $types .= 's';
            $params[] = $value;
        }
        $types .= 'i';
        $params[] = $id;

        $sql_update = "UPDATE provisioning SET " . implode(', ', $setParts) . " WHERE id = ?";
        $stmt_update = $conn->prepare($sql_update);
        $bindParams = [];
        $bindParams[] = &$types;
        foreach ($params as $k => $v) {
            $bindParams[] = &$params[$k];
        }
        call_user_func_array([$stmt_update, 'bind_param'], $bindParams);
        if (!$stmt_update->execute()) {
            echo json_encode(['success' => false, 'message' => 'Gagal update data provisioning: ' . $stmt_update->error]);
            $stmt_update->close();
            exit;
        }
        $stmt_update->close();

        // Reload updated provisioning data for approve process
        $prov = getProvisioningRecord($conn, $id, $USER_ID);
        if (!$prov) {
            echo json_encode(['success' => false, 'message' => 'Data provisioning tidak ditemukan setelah update']);
            exit;
        }
    }

    $serverInfo = getServerInfo($conn, $prov['server_pemilik'], $prov['area']);
    if (!$serverInfo) {
        echo json_encode(['success' => false, 'message' => 'Server tidak ditemukan']);
        exit;
    }

    // Validasi karakter idpel/password_pppoe SEBELUM dipakai memanggil Mikrotik
    // API atau (di blok lain) menulis file /etc/freeradius/3.0/users. Nilai ini
    // berasal dari tabel provisioning -- normalnya sudah aman karena diisi
    // otomatis lewat tiket_manager.php (whitelist regex), tapi divalidasi ulang
    // di sini sebagai lapis pertahanan terakhir supaya data "kotor" (spasi/
    // kutip/backslash) tidak pernah sampai merusak sintaks file users RADIUS.
    $idpelCheck = (string)$prov['idpel'];
    $passwordCheck = (string)$prov['password_pppoe'];
    if ($idpelCheck === '' || strlen($idpelCheck) > 64 || $idpelCheck[0] === '#' || preg_match('/[\x00-\x20"\\\\\x7F]/', $idpelCheck)) {
        echo json_encode(['success' => false, 'message' => 'ID Pelanggan mengandung karakter yang tidak valid/tidak aman untuk RADIUS (tidak boleh kosong, lebih dari 64 karakter, diawali "#", mengandung spasi/kontrol-karakter, kutip-dua, atau backslash)']);
        exit;
    }
    if (preg_match('/[\s"\\\\]/', $passwordCheck)) {
        echo json_encode(['success' => false, 'message' => 'Password PPPoE mengandung karakter yang tidak valid/tidak aman untuk RADIUS (tidak boleh mengandung spasi, kutip-dua, atau backslash)']);
        exit;
    }

    // Update MikroTik comment from PROVISIONING-PENDING to BARU
    if ($prov['auth_mode'] === 'API MODE' || $prov['auth_mode'] === 'MULTI MODE') {
        $API = new RouterosAPI();
        $connected = $API->connect($serverInfo['IP'], $serverInfo['PEMILIK'], $serverInfo['PASSWORD']);
        if ($connected) {
            // Find the PPP secret
            $existing = $API->comm("/ppp/secret/print", ["?name" => $prov['idpel']]);
            if (!empty($existing)) {
                $secretId = $existing[0]['.id'];
                $newComment = "BARU " . $prov['nama'] . "-" . $prov['nowa'] . "-" . $prov['tanggal_pasang'];
                $API->comm("/ppp/secret/set", [
                    ".id" => $secretId,
                    "comment" => $newComment
                ]);
            }
            $API->disconnect();
        }
    }

    // Get price from paket table
    $harga = (int)reseller_effective_harga($conn, $prov['paket'], $prov['server_pemilik']);

    $tempo = $serverInfo['TEMPO'] ?? '';

    // Mode tempo "monthversary": anchor awal dikunci ke tanggal pasang, sama
    // seperti billing/proses/addcustomer.php. Untuk prabayar, anchor ini akan
    // dikunci ulang otomatis oleh cek_tagihan_harian.php begitu pelanggan
    // melakukan pembayaran pertamanya.
    $tanggal_monthversary = ($prov['tipe_tempo'] === 'monthversary') ? $prov['tanggal_pasang'] : null;

    // INSERT into pelanggan table (same as addcustomer.php)
    $stmt_pel = $conn->prepare("INSERT INTO pelanggan
        (`PASSWORD`, `IDPEL`, `NIK`, `NAMA`, `TIPE_BAYAR`, `TIPE_TEMPO`, `PAKET`, `HARGA`, `TANGGALPASANG`, `NOWA`, `EMAIL`, `ALAMAT`, `TEMPO`, `PEMILIK`, `MODE`, `ODP`, `AREA`, `provinsi`, `kabupaten`, `kecamatan`, `kelurahan`, `rw`, `rt`, `TIKOR`, `sales`, `BRAND`, `TANGGAL_MONTHVERSARY`)
        VALUES (?, ?, ?, ?, ?, ?, ?, '-', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt_pel->bind_param('ssssssssssssssssssssssssss',
        $prov['password_pppoe'], $prov['idpel'], $prov['nik'], $prov['nama'],
        $prov['tipe_bayar'], $prov['tipe_tempo'], $prov['paket'],
        $prov['tanggal_pasang'], $prov['nowa'], $prov['email'],
        $prov['alamat'], $tempo, $prov['server_pemilik'],
        $prov['auth_mode'], $prov['odp'], $prov['area'],
        $prov['provinsi'], $prov['kabupaten'], $prov['kecamatan'],
        $prov['kelurahan'], $prov['rw'], $prov['rt'],
        $prov['tikor'], $prov['sales'], $prov['server_brand'],
        $tanggal_monthversary
    );

    if (!$stmt_pel->execute()) {
        echo json_encode(['success' => false, 'message' => 'Gagal insert pelanggan: ' . $stmt_pel->error]);
        $stmt_pel->close();
        exit;
    }
    $stmt_pel->close();

    // Auto create PENAGIHAN transaction (same as addcustomer.php)
    $bulan_indonesia = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
    ];
    $timestamp_pasang = strtotime((string)$prov['tanggal_pasang']);
    if ($timestamp_pasang === false) $timestamp_pasang = time();

    $periode_penggunaan = $bulan_indonesia[(int)date('n', $timestamp_pasang)] . ' ' . date('Y', $timestamp_pasang);
    $tanggal_penagihan = date('Y-m-d');

    // Check if transaction already exists
    $cek_trx = $conn->prepare("SELECT id FROM transaksi WHERE IDPEL = ? AND PENGUNAAN = ? AND TRIM(UPPER(COALESCE(STATUS, ''))) IN ('PENAGIHAN','PERMINTAAN KODE','KONFIRMASI','BERHASIL') LIMIT 1");
    $cek_trx->bind_param('ss', $prov['idpel'], $periode_penggunaan);
    $cek_trx->execute();
    $cek_trx_result = $cek_trx->get_result();

    if ($cek_trx_result->num_rows === 0) {
        $bukti_penagihan = 'INV-REG-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$prov['idpel']) . '-' . date('Ym');
        $cek_penagihan = 'AUTO PENAGIHAN DARI PROVISIONING JOBLIST';
        $status_penagihan = 'PENAGIHAN';

        $stmt_trx = $conn->prepare("INSERT INTO transaksi (TANGGALBAYAR, PENGUNAAN, STATUS, IDPEL, NAMA, PAKET, HARGA, BUKTI, CEK, PEMILIK, METODE_BAYAR) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '')");
        $stmt_trx->bind_param('ssssssisss',
            $tanggal_penagihan, $periode_penggunaan, $status_penagihan,
            $prov['idpel'], $prov['nama'], $prov['paket'],
            $harga, $bukti_penagihan, $cek_penagihan, $prov['server_pemilik']
        );
        $stmt_trx->execute();
        $stmt_trx->close();
    }
    $cek_trx->close();

    // Update provisioning status to APPROVED
    $stmt_upd = $conn->prepare("UPDATE provisioning SET status = 'APPROVED', approved_at = NOW(), approved_by = ? WHERE id = ?");
    $stmt_upd->bind_param('si', $ceknama, $id);
    $stmt_upd->execute();
    $stmt_upd->close();

    echo json_encode(['success' => true, 'message' => 'Provisioning ' . $prov['idpel'] . ' berhasil di-approve dan ditambahkan ke pelanggan aktif']);
    exit;
}

// ==================== REJECT ====================
if ($action === 'reject') {
    $serverInfo = getServerInfo($conn, $prov['server_pemilik'], $prov['area']);

    // Remove from MikroTik
    if ($serverInfo && ($prov['auth_mode'] === 'API MODE' || $prov['auth_mode'] === 'MULTI MODE')) {
        $API = new RouterosAPI();
        $connected = $API->connect($serverInfo['IP'], $serverInfo['PEMILIK'], $serverInfo['PASSWORD']);
        if ($connected) {
            $existing = $API->comm("/ppp/secret/print", ["?name" => $prov['idpel']]);
            if (!empty($existing)) {
                $API->comm("/ppp/secret/remove", [".id" => $existing[0]['.id']]);
            }
            $API->disconnect();
        }
    }

    // Remove from RADIUS
    if ($prov['auth_mode'] === 'RADIUS MODE' || $prov['auth_mode'] === 'MULTI MODE') {
        $users_file = "/etc/freeradius/3.0/users";
        if (file_exists($users_file)) {
            $content = file_get_contents($users_file);
            $pattern = '/^' . preg_quote($prov['idpel'], '/') . ' Cleartext-Password.*\n(\t.*\n)*\n?/m';
            $new_content = preg_replace($pattern, '', $content);
            if ($new_content !== $content) {
                file_put_contents($users_file, $new_content);
                // Restart freeradius
                if (function_exists('exec')) {
                    @exec('sudo systemctl restart freeradius 2>&1');
                }
            }
        }
    }

    // Update status to REJECTED
    $stmt_upd = $conn->prepare("UPDATE provisioning SET status = 'REJECTED', approved_at = NOW(), approved_by = ? WHERE id = ?");
    $stmt_upd->bind_param('si', $ceknama, $id);
    $stmt_upd->execute();
    $stmt_upd->close();

    echo json_encode(['success' => true, 'message' => 'Provisioning ' . $prov['idpel'] . ' ditolak. Secret PPPoE telah dihapus.']);
    exit;
}

// ==================== REACTIVATE (re-create secret + reset 3 days) ====================
if ($action === 'reactivate') {
    $serverInfo = getServerInfo($conn, $prov['server_pemilik'], $prov['area']);
    if (!$serverInfo) {
        // Fallback without area
        $stmt_fallback = $conn->prepare("SELECT * FROM server WHERE PEMILIK = ? LIMIT 1");
        $stmt_fallback->bind_param('s', $prov['server_pemilik']);
        $stmt_fallback->execute();
        $serverInfo = $stmt_fallback->get_result()->fetch_assoc();
        $stmt_fallback->close();
    }
    if (!$serverInfo) {
        echo json_encode(['success' => false, 'message' => 'Server tidak ditemukan']);
        exit;
    }

    // Validasi karakter idpel/password_pppoe SEBELUM dipakai memanggil Mikrotik
    // API (ppp/secret/add) atau menulis file /etc/freeradius/3.0/users di bawah.
    // Lihat penjelasan lengkap di blok approve di atas.
    $idpelCheck = (string)$prov['idpel'];
    $passwordCheck = (string)$prov['password_pppoe'];
    if ($idpelCheck === '' || strlen($idpelCheck) > 64 || $idpelCheck[0] === '#' || preg_match('/[\x00-\x20"\\\\\x7F]/', $idpelCheck)) {
        echo json_encode(['success' => false, 'message' => 'ID Pelanggan mengandung karakter yang tidak valid/tidak aman untuk RADIUS (tidak boleh kosong, lebih dari 64 karakter, diawali "#", mengandung spasi/kontrol-karakter, kutip-dua, atau backslash)']);
        exit;
    }
    if (preg_match('/[\s"\\\\]/', $passwordCheck)) {
        echo json_encode(['success' => false, 'message' => 'Password PPPoE mengandung karakter yang tidak valid/tidak aman untuk RADIUS (tidak boleh mengandung spasi, kutip-dua, atau backslash)']);
        exit;
    }

    $mk_ok = false;
    $rad_ok = false;

    // Re-create MikroTik secret
    if ($prov['auth_mode'] === 'API MODE' || $prov['auth_mode'] === 'MULTI MODE') {
        $API = new RouterosAPI();
        $API->debug = false;
        if ($API->connect($serverInfo['IP'], $serverInfo['PEMILIK'], $serverInfo['PASSWORD'])) {
            // Check if already exists
            $existing = $API->comm("/ppp/secret/print", ["?name" => $prov['idpel']]);
            if (!empty($existing) && !isset($existing['!trap'])) {
                // Already exists, just update comment
                $API->comm("/ppp/secret/set", [
                    ".id" => $existing[0]['.id'],
                    "comment" => "PROVISIONING-PENDING " . $prov['nama'] . "-" . $prov['nowa'] . "-" . $prov['tanggal_pasang']
                ]);
                $mk_ok = true;
            } else {
                // Create new
                $result = $API->comm("/ppp/secret/add", [
                    "name"     => $prov['idpel'],
                    "password" => $prov['password_pppoe'],
                    "profile"  => $prov['paket'],
                    "service"  => "any",
                    "comment"  => "PROVISIONING-PENDING " . $prov['nama'] . "-" . $prov['nowa'] . "-" . $prov['tanggal_pasang']
                ]);
                if (!isset($result['!trap'])) {
                    $mk_ok = true;
                } else {
                    $trap_msg = isset($result['!trap'][0]['message']) ? $result['!trap'][0]['message'] : 'Unknown error';
                    $API->disconnect();
                    echo json_encode(['success' => false, 'message' => 'MikroTik error: ' . $trap_msg]);
                    exit;
                }
            }
            $API->disconnect();
        } else {
            echo json_encode(['success' => false, 'message' => 'Gagal konek ke MikroTik: ' . $serverInfo['IP']]);
            exit;
        }
    }

    // Re-create RADIUS entry
    if ($prov['auth_mode'] === 'RADIUS MODE' || $prov['auth_mode'] === 'MULTI MODE') {
        $users_file = "/etc/freeradius/3.0/users";
        if (file_exists($users_file)) {
            $content = file_get_contents($users_file);
            if (strpos($content, $prov['idpel'] . " Cleartext-Password") === false) {
                $entry = $prov['idpel'] . " Cleartext-Password := \"" . $prov['password_pppoe'] . "\"\n";
                $entry .= "\tMikrotik-Group := \"" . $prov['paket'] . "\"\n\n";
                file_put_contents($users_file, $entry, FILE_APPEND);
                @exec('sudo systemctl restart freeradius 2>&1');
            }
            $rad_ok = true;
        }
    }

    // Reset to PENDING with new 3-day expiry
    $new_expired = date('Y-m-d H:i:s', strtotime('+3 days'));
    $stmt_react = $conn->prepare("UPDATE provisioning SET status = 'PENDING', expired_at = ?, approved_at = NULL, approved_by = NULL WHERE id = ?");
    $stmt_react->bind_param('si', $new_expired, $id);
    $stmt_react->execute();
    $stmt_react->close();

    echo json_encode(['success' => true, 'message' => 'Provisioning ' . $prov['idpel'] . ' berhasil diaktifkan ulang (3 hari). Secret PPPoE telah dibuat kembali.']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Action tidak dikenal']);
