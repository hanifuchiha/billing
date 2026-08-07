<?php
// Error handler: tangkap semua error sebagai JSON
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

ob_start();

set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array(
            'success' => false,
            'message' => 'Fatal PHP error: ' . $error['message'],
            'file' => basename($error['file']) . ':' . $error['line']
        ));
    }
});

header('Content-Type: application/json; charset=utf-8');

$phpWarnings = [];

try {
    // Step 1: Load session
    restore_error_handler();
    require_once '../cek-sesi.php';
    set_error_handler(function($severity, $message, $file, $line) use (&$phpWarnings) {
        $phpWarnings[] = basename($file) . ':' . $line . ' - ' . $message;
        return true;
    });

    // Step 2: Connect to absensi DB
    $config_file_abs = dirname(__DIR__) . '/config.json';
    $configAbs = file_exists($config_file_abs) ? json_decode(file_get_contents($config_file_abs), true) : [];
    
    $connAbsensi = @mysqli_connect(
        $configAbs['db_host_absensi'] ?? 'localhost',
        $configAbs['db_user_absensi'] ?? '',
        $configAbs['db_pass_absensi'] ?? '',
        $configAbs['db_name_absensi'] ?? ''
    );
    if (!$connAbsensi) {
        echo json_encode(array(
            'success' => false,
            'message' => 'Gagal koneksi database absensi: ' . mysqli_connect_error(),
            'db_config' => [
                'host' => $configAbs['db_host_absensi'] ?? '(kosong)',
                'db'   => $configAbs['db_name_absensi'] ?? '(kosong)'
            ]
        ));
        exit;
    }

    // Step 3: Connect to billing DB
    $connBilling = @mysqli_connect(
        $configAbs['db_host'] ?? 'localhost',
        $configAbs['db_user'] ?? '',
        $configAbs['db_pass'] ?? '',
        $configAbs['db_name'] ?? ''
    );
    if (!$connBilling) {
        echo json_encode(array(
            'success' => false,
            'message' => 'Gagal koneksi database billing: ' . mysqli_connect_error(),
            'db_config' => [
                'host' => $configAbs['db_host'] ?? '(kosong)',
                'db'   => $configAbs['db_name'] ?? '(kosong)'
            ]
        ));
        exit;
    }

    $ticketSource = isset($ticket_management_source) ? strtolower(trim((string)$ticket_management_source)) : 'tiket_manager';
    if (!in_array($ticketSource, ['tiket_manager', 'joblist'], true)) {
        $ticketSource = 'tiket_manager';
    }

    // Step 4: Cek tabel target tiket sesuai source
    if ($ticketSource === 'joblist') {
        $testQuery = @mysqli_query($connAbsensi, "SELECT 1 FROM joblist LIMIT 1");
        if (!$testQuery) {
            echo json_encode(array(
                'success' => false,
                'message' => 'Tabel joblist tidak ditemukan di DB absensi (' . ($configAbs['db_name_absensi'] ?? '') . '): ' . mysqli_error($connAbsensi)
            ));
            exit;
        }
    } else {
        $testQuery = @mysqli_query($connBilling, "SELECT 1 FROM billing_tiket_manager LIMIT 1");
        if (!$testQuery) {
            echo json_encode(array(
                'success' => false,
                'message' => 'Tabel billing_tiket_manager tidak ditemukan di DB billing (' . ($configAbs['db_name'] ?? '') . '): ' . mysqli_error($connBilling)
            ));
            exit;
        }
    }

    // Step 5: Cek tabel pelanggan
    $testQuery2 = @mysqli_query($connBilling, "SELECT 1 FROM pelanggan LIMIT 1");
    if (!$testQuery2) {
        echo json_encode(array(
            'success' => false,
            'message' => 'Tabel pelanggan tidak ditemukan di DB billing (' . ($configAbs['db_name'] ?? '') . '): ' . mysqli_error($connBilling)
        ));
        exit;
    }

    // Step 6: Cek method POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(array(
            'success' => false,
            'message' => 'Metode request tidak diizinkan.'
        ));
        exit;
    }

    $rawIdpelList = isset($_POST['idpel_list']) ? trim((string)$_POST['idpel_list']) : '';
    $kendala = isset($_POST['kendala']) ? trim((string)$_POST['kendala']) : '';
    $tipe = isset($_POST['tipe']) ? trim((string)$_POST['tipe']) : 'DISMANTLE';

if ($rawIdpelList === '') {
    http_response_code(422);
    echo json_encode(array(
        'success' => false,
        'message' => 'Tidak ada pelanggan terpilih.'
    ));
    exit;
}

if ($kendala === '') {
    http_response_code(422);
    echo json_encode(array(
        'success' => false,
        'message' => 'Kendala wajib dipilih.'
    ));
    exit;
}

$idpelParts = preg_split('/\s*,\s*/', $rawIdpelList);
$idpelUnique = array();
foreach ($idpelParts as $idpelItem) {
    $idpelItem = trim((string)$idpelItem);
    if ($idpelItem === '') {
        continue;
    }

    // Allow alphanumeric and common separators that appear in customer IDs.
    $idpelItem = preg_replace('/[^A-Za-z0-9._\/@-]/', '', $idpelItem);
    if ($idpelItem !== '') {
        $idpelUnique[$idpelItem] = true;
    }
}

$idpelList = array_keys($idpelUnique);
if (count($idpelList) === 0) {
    http_response_code(422);
    echo json_encode(array(
        'success' => false,
        'message' => 'Format ID pelanggan tidak valid.'
    ));
    exit;
}

$escapedIds = array();
foreach ($idpelList as $idpel) {
    $escapedIds[] = "'" . mysqli_real_escape_string($connBilling, $idpel) . "'";
}
$inClause = implode(',', $escapedIds);

$queryPelanggan = "SELECT IDPEL, NAMA, NOWA, ALAMAT, EMAIL, ODP, PEMILIK FROM pelanggan WHERE IDPEL IN ($inClause)";
$resultPelanggan = mysqli_query($connBilling, $queryPelanggan);
if (!$resultPelanggan) {
    http_response_code(500);
    echo json_encode(array(
        'success' => false,
        'message' => 'Gagal mengambil data pelanggan: ' . mysqli_error($connBilling)
    ));
    exit;
}

$pelangganById = array();
while ($row = mysqli_fetch_assoc($resultPelanggan)) {
    $pelangganById[(string)$row['IDPEL']] = $row;
}

$summary = array(
    'total_selected' => count($idpelList),
    'created' => 0,
    'skipped_exists' => 0,
    'skipped_not_found' => 0,
    'failed' => 0
);

$details = array();
$today = date('Y-m-d');
$tipeNormalized = strtoupper(trim((string)$tipe));
if ($tipeNormalized === '') {
    $tipeNormalized = 'DISMANTLE';
}

// Cek existing tiket secara bulk agar anti-duplikat lebih akurat
// Mendukung format data tiket lama/baru (ID PELANGGAN bisa di baris mana saja).
$existingByIdpel = array();
$likeParts = array();
if ($ticketSource === 'joblist') {
    foreach ($idpelList as $idpel) {
        $idpelEscLike = mysqli_real_escape_string($connAbsensi, $idpel);
        $likeParts[] = "data LIKE '%ID PELANGGAN :" . $idpelEscLike . "%'";
    }

    if (count($likeParts) > 0) {
        $tipeEscBulk = mysqli_real_escape_string($connAbsensi, $tipeNormalized);
        $queryExisting = "SELECT id, data, status, tipe, project
                          FROM joblist
                          WHERE tipe = '" . $tipeEscBulk . "'
                            AND status IN ('BARU','PENDING','CANCEL')
                            AND (" . implode(' OR ', $likeParts) . ")";
        $resultExisting = mysqli_query($connAbsensi, $queryExisting);
        if ($resultExisting) {
            while ($rowExisting = mysqli_fetch_assoc($resultExisting)) {
                $dataExisting = (string)($rowExisting['data'] ?? '');
                if (preg_match('/ID PELANGGAN\s*:\s*([^\r\n]+)/i', $dataExisting, $mExisting)) {
                    $idpelExisting = trim((string)$mExisting[1]);
                    if ($idpelExisting !== '') {
                        $existingByIdpel[$idpelExisting] = true;
                    }
                }
            }
        }
    }
} else {
    foreach ($idpelList as $idpel) {
        $idpelEscLike = mysqli_real_escape_string($connBilling, $idpel);
        $likeParts[] = "detail LIKE '%ID PELANGGAN :" . $idpelEscLike . "%'";
    }

    $ownerUserId = isset($USER_ID) ? (int)$USER_ID : 0;
    $serverIdList = [];
    $resSrv = mysqli_query($connBilling, "SELECT id FROM server WHERE user_id = " . (int)$ownerUserId);
    while ($resSrv && ($srvRow = mysqli_fetch_assoc($resSrv))) {
        $serverIdList[] = (int)($srvRow['id'] ?? 0);
    }

    if (count($likeParts) > 0 && !empty($serverIdList)) {
        $tipeEscBulk = mysqli_real_escape_string($connBilling, $tipeNormalized);
        $inServer = implode(',', array_map('intval', $serverIdList));
        $queryExisting = "SELECT id, detail, status, tipe, project_name
                          FROM billing_tiket_manager
                          WHERE tipe = '" . $tipeEscBulk . "'
                            AND status IN ('BARU','PENDING','CANCEL')
                            AND server_id IN (" . $inServer . ")
                            AND (" . implode(' OR ', $likeParts) . ")";
        $resultExisting = mysqli_query($connBilling, $queryExisting);
        if ($resultExisting) {
            while ($rowExisting = mysqli_fetch_assoc($resultExisting)) {
                $dataExisting = (string)($rowExisting['detail'] ?? '');
                if (preg_match('/ID PELANGGAN\s*:\s*([^\r\n]+)/i', $dataExisting, $mExisting)) {
                    $idpelExisting = trim((string)$mExisting[1]);
                    if ($idpelExisting !== '') {
                        $existingByIdpel[$idpelExisting] = true;
                    }
                }
            }
        }
    }
}

foreach ($idpelList as $idpel) {
    if (!isset($pelangganById[$idpel])) {
        $summary['skipped_not_found']++;
        $details[] = array(
            'idpel' => $idpel,
            'status' => 'not_found',
            'message' => 'Pelanggan tidak ditemukan.'
        );
        continue;
    }

    $p = $pelangganById[$idpel];
    $nama = isset($p['NAMA']) ? (string)$p['NAMA'] : '';
    $odp = isset($p['ODP']) ? (string)$p['ODP'] : '';
    $email = isset($p['EMAIL']) ? (string)$p['EMAIL'] : '';
    $alamat = isset($p['ALAMAT']) ? (string)$p['ALAMAT'] : '';
    $nowa = isset($p['NOWA']) ? (string)$p['NOWA'] : '';
    $project = isset($p['PEMILIK']) ? (string)$p['PEMILIK'] : '';

    if (isset($existingByIdpel[$idpel])) {
        $summary['skipped_exists']++;
        $details[] = array(
            'idpel' => $idpel,
            'status' => 'exists',
            'message' => 'Tiket dengan tipe sama sudah ada (BARU/PENDING/CANCEL).'
        );
        continue;
    }

    $dataTiket = "ID PELANGGAN :$idpel\n NAMA :$nama\n ODP :$odp\n EMAIL :$email\n ALAMAT :$alamat\n NO WA :$nowa\n KENDALA :$kendala";

    if ($ticketSource === 'joblist') {
        $tglEsc = mysqli_real_escape_string($connAbsensi, $today);
        $tipeEsc = mysqli_real_escape_string($connAbsensi, $tipeNormalized);
        $dataEsc = mysqli_real_escape_string($connAbsensi, $dataTiket);
        $projectEsc = mysqli_real_escape_string($connAbsensi, $project);

        $queryInsert = "INSERT INTO joblist (tgl, status, nowa, data, project, report, team, tipe) VALUES ('$tglEsc','BARU','','$dataEsc','$projectEsc','','','$tipeEsc')";
        $resultInsert = mysqli_query($connAbsensi, $queryInsert);
        $insertError = mysqli_error($connAbsensi);
    } else {
        $ownerUserId = isset($USER_ID) ? (int)$USER_ID : 0;
        $creatorId = isset($_SESSION['id']) ? (int)$_SESSION['id'] : $ownerUserId;

        $serverRow = null;
        $stmtSrv = mysqli_prepare($connBilling, 'SELECT id, PEMILIK, BRAND, AREA FROM server WHERE user_id = ? AND PEMILIK = ? LIMIT 1');
        if ($stmtSrv) {
            mysqli_stmt_bind_param($stmtSrv, 'is', $ownerUserId, $project);
            mysqli_stmt_execute($stmtSrv);
            $resSrv = mysqli_stmt_get_result($stmtSrv);
            $serverRow = $resSrv ? mysqli_fetch_assoc($resSrv) : null;
            mysqli_stmt_close($stmtSrv);
        }
        if (!$serverRow) {
            $stmtSrvAny = mysqli_prepare($connBilling, 'SELECT id, PEMILIK, BRAND, AREA FROM server WHERE user_id = ? ORDER BY id ASC LIMIT 1');
            if ($stmtSrvAny) {
                mysqli_stmt_bind_param($stmtSrvAny, 'i', $ownerUserId);
                mysqli_stmt_execute($stmtSrvAny);
                $resSrvAny = mysqli_stmt_get_result($stmtSrvAny);
                $serverRow = $resSrvAny ? mysqli_fetch_assoc($resSrvAny) : null;
                mysqli_stmt_close($stmtSrvAny);
            }
        }

        if (!$serverRow) {
            $resultInsert = false;
            $insertError = 'Server owner tidak ditemukan.';
        } else {
            $serverId = (int)($serverRow['id'] ?? 0);
            $brand = (string)($serverRow['BRAND'] ?? '');
            $area = (string)($serverRow['AREA'] ?? '');
            $pemilik = (string)($serverRow['PEMILIK'] ?? $project);
            $projectName = trim($brand . ' - ' . $area);
            if ($projectName === '' || $projectName === '-') {
                $projectName = $pemilik;
            }
            $judul = trim($tipeNormalized . ' - ' . $idpel . ' - ' . $nama);
            if ($judul === '') {
                $judul = $tipeNormalized . ' - Tiket Menunggak';
            }
            $report = '';
            $teknisiId = null;

            $stmtInsertManager = mysqli_prepare($connBilling, "INSERT INTO billing_tiket_manager (judul, detail, server_id, pemilik, brand, area, project_name, tipe, report, status, teknisi_user_id, created_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'BARU', ?, ?)");
            if ($stmtInsertManager) {
                mysqli_stmt_bind_param($stmtInsertManager, 'ssissssssii', $judul, $dataTiket, $serverId, $pemilik, $brand, $area, $projectName, $tipeNormalized, $report, $teknisiId, $creatorId);
                $resultInsert = mysqli_stmt_execute($stmtInsertManager);
                $insertError = mysqli_stmt_error($stmtInsertManager);
                mysqli_stmt_close($stmtInsertManager);
            } else {
                $resultInsert = false;
                $insertError = mysqli_error($connBilling);
            }
        }
    }

    if ($resultInsert) {
        $summary['created']++;
        $existingByIdpel[$idpel] = true;
        $details[] = array(
            'idpel' => $idpel,
            'status' => 'created',
            'message' => 'Tiket berhasil dibuat.'
        );
    } else {
        $summary['failed']++;
        $details[] = array(
            'idpel' => $idpel,
            'status' => 'failed',
            'message' => 'Gagal insert: ' . $insertError
        );
    }
}

    $trailingOutput = ob_get_clean();

    echo json_encode(array(
        'success' => true,
        'message' => 'Proses pembuatan tiket massal selesai.',
        'summary' => $summary,
        'details' => $details,
        'warnings' => !empty($phpWarnings) ? $phpWarnings : null,
        'debug_trailing' => ($trailingOutput !== '' ? $trailingOutput : null)
    ));

} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(array(
        'success' => false,
        'message' => 'Exception: ' . $e->getMessage(),
        'file' => basename($e->getFile()) . ':' . $e->getLine(),
        'warnings' => !empty($phpWarnings) ? $phpWarnings : null
    ));
} catch (Error $e) {
    ob_end_clean();
    echo json_encode(array(
        'success' => false,
        'message' => 'PHP Error: ' . $e->getMessage(),
        'file' => basename($e->getFile()) . ':' . $e->getLine()
    ));
}
