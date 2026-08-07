<?php
/**
 * Hapus 1 tiket dari modal "Show Data" (INST/DST/MT/MGS) di dashboard.php.
 * Sumber ('tiket_manager'/'joblist') dikirim dari frontend (hasil list_tiket_by_tipe.php),
 * BUKAN dibaca ulang dari ticket_management_source pengguna -- supaya konsisten dgn baris
 * yang sedang dilihat user meskipun setting berubah di antara load & aksi hapus.
 */
include '../cek-sesi.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$id = (int)($_POST['id'] ?? 0);
$source = strtolower(trim((string)($_POST['source'] ?? '')));

if ($id <= 0 || !in_array($source, ['tiket_manager', 'joblist'], true)) {
    echo json_encode(['success' => false, 'message' => 'Data tidak valid']);
    exit;
}

$actorName = !empty($asistant_name) ? $asistant_name : ($ceknama ?? 'system');

if ($source === 'tiket_manager') {
    $connBilling = isset($conn) ? $conn : null;
    if (!($connBilling instanceof mysqli)) {
        echo json_encode(['success' => false, 'message' => 'Koneksi billing tidak tersedia']);
        exit;
    }

    // Pastikan tiket ini memang milik server yang dimiliki user (proteksi lintas akun).
    $ownerUserId = isset($USER_ID) ? (int)$USER_ID : (isset($current_user_id) ? (int)$current_user_id : 0);
    $stmtCheck = $connBilling->prepare("SELECT t.id, t.judul FROM billing_tiket_manager t INNER JOIN server s ON s.id = t.server_id WHERE t.id = ? AND s.user_id = ? LIMIT 1");
    $stmtCheck->bind_param('ii', $id, $ownerUserId);
    $stmtCheck->execute();
    $rowCheck = $stmtCheck->get_result()->fetch_assoc();
    $stmtCheck->close();

    if (!$rowCheck) {
        echo json_encode(['success' => false, 'message' => 'Tiket tidak ditemukan atau bukan milik akun ini']);
        exit;
    }

    $stmtDel = $connBilling->prepare("DELETE FROM billing_tiket_manager WHERE id = ?");
    $stmtDel->bind_param('i', $id);
    if (!$stmtDel->execute()) {
        echo json_encode(['success' => false, 'message' => 'Gagal menghapus: ' . $connBilling->error]);
        exit;
    }
    $stmtDel->close();

    $history_file = "../notifbot/data/history-$ceknama.json";
    $history = file_exists($history_file) ? (json_decode(file_get_contents($history_file), true) ?: []) : [];
    if (!is_array($history)) $history = [];
    $history[] = "[ $actorName - " . date('Y-m-d H:i:s') . " ] Menghapus tiket #$id (" . ($rowCheck['judul'] ?? '-') . ") dari Tiket Manager";
    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    echo json_encode(['success' => true, 'message' => 'Tiket berhasil dihapus']);
    exit;
}

// source === 'joblist'
$config_file = __DIR__ . '/../../joblist/config.json';
$joblist_config = file_exists($config_file) ? json_decode(file_get_contents($config_file), true) : [];
$joblist_host = $joblist_config['db_host_absensi'] ?? '';
$joblist_user = $joblist_config['db_user_absensi'] ?? '';
$joblist_pass = $joblist_config['db_pass_absensi'] ?? '';
$joblist_db   = $joblist_config['db_name_absensi'] ?? '';

if ($joblist_host === '') {
    echo json_encode(['success' => false, 'message' => 'Konfigurasi joblist tidak ditemukan']);
    exit;
}

$joblist_conn = @mysqli_connect($joblist_host, $joblist_user, $joblist_pass, $joblist_db);
if (!$joblist_conn) {
    echo json_encode(['success' => false, 'message' => 'Koneksi joblist gagal']);
    exit;
}

// Batasi hapus ke project milik server yang dimiliki user (proteksi lintas akun).
$connBilling = isset($conn) ? $conn : null;
$ownedProjects = [];
if ($connBilling instanceof mysqli) {
    $ownerUserId = isset($USER_ID) ? (int)$USER_ID : (isset($current_user_id) ? (int)$current_user_id : 0);
    $qOwned = mysqli_query($connBilling, "SELECT DISTINCT PEMILIK FROM server WHERE user_id = " . (int)$ownerUserId);
    while ($qOwned && ($rSrv = mysqli_fetch_assoc($qOwned))) {
        $ownedProjects[] = (string)($rSrv['PEMILIK'] ?? '');
    }
}

$stmtCheck = $joblist_conn->prepare("SELECT id, project FROM joblist WHERE id = ? LIMIT 1");
$stmtCheck->bind_param('i', $id);
$stmtCheck->execute();
$rowCheck = $stmtCheck->get_result()->fetch_assoc();
$stmtCheck->close();

if (!$rowCheck || !in_array((string)($rowCheck['project'] ?? ''), $ownedProjects, true)) {
    echo json_encode(['success' => false, 'message' => 'Tiket tidak ditemukan atau bukan milik akun ini']);
    exit;
}

$stmtDel = $joblist_conn->prepare("DELETE FROM joblist WHERE id = ?");
$stmtDel->bind_param('i', $id);
if (!$stmtDel->execute()) {
    echo json_encode(['success' => false, 'message' => 'Gagal menghapus: ' . $joblist_conn->error]);
    exit;
}
$stmtDel->close();
mysqli_close($joblist_conn);

$history_file = "../notifbot/data/history-$ceknama.json";
$history = file_exists($history_file) ? (json_decode(file_get_contents($history_file), true) ?: []) : [];
if (!is_array($history)) $history = [];
$history[] = "[ $actorName - " . date('Y-m-d H:i:s') . " ] Menghapus tiket #$id dari Joblist";
file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo json_encode(['success' => true, 'message' => 'Tiket berhasil dihapus']);
