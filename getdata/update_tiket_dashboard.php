<?php
/**
 * Edit 1 tiket (nama, no WA, kendala, status) dari modal "Show Data" di
 * dashboard.php. Field lain di teks terstruktur tiket (ID PELANGGAN, ODP,
 * EMAIL, ALAMAT, TIKOR) dipertahankan apa adanya -- cuma field yang memang
 * ditampilkan/diedit di modal yang ditimpa, supaya data yang tidak
 * ditampilkan di form edit tidak ikut hilang.
 */
include '../cek-sesi.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$id = (int)($_POST['id'] ?? 0);
$source = strtolower(trim((string)($_POST['source'] ?? '')));
$nama = trim((string)($_POST['nama'] ?? ''));
$nowa = trim((string)($_POST['nowa'] ?? ''));
$kendala = trim((string)($_POST['keterangan'] ?? ''));
$statusAllowed = ['BARU', 'PENDING', 'DONE', 'CANCEL'];
$status = strtoupper(trim((string)($_POST['status'] ?? '')));

if ($id <= 0 || !in_array($source, ['tiket_manager', 'joblist'], true) || !in_array($status, $statusAllowed, true)) {
    echo json_encode(['success' => false, 'message' => 'Data tidak valid']);
    exit;
}

function rebuildTicketText(string $originalText, string $nama, string $nowa, string $kendala): string
{
    $get = static function (string $label) use ($originalText): string {
        if (preg_match('/' . preg_quote($label, '/') . '\s*:([^\n]+)/i', $originalText, $m)) {
            return trim($m[1]);
        }
        return '';
    };

    $header = '';
    if (preg_match('/^={5,}\nTiket [^\n]+\n={5,}/m', $originalText, $mh)) {
        $header = $mh[0];
    }
    $idpel = $get('ID PELANGGAN');
    $odp = $get('ODP');
    $email = $get('EMAIL');
    $alamat = $get('ALAMAT');
    $tikor = $get('TIKOR');

    $lines = [];
    if ($header !== '') $lines[] = $header;
    $lines[] = "ID PELANGGAN :$idpel";
    $lines[] = "NAMA PELANGGAN :$nama";
    $lines[] = "ODP :$odp";
    $lines[] = "EMAIL :$email";
    $lines[] = "ALAMAT :$alamat";
    $lines[] = "NO WHATSAPP : $nowa";
    $lines[] = "KENDALA : $kendala";
    $lines[] = "TIKOR : $tikor";
    return implode("\n", $lines);
}

$actorName = !empty($asistant_name) ? $asistant_name : ($ceknama ?? 'system');

if ($source === 'tiket_manager') {
    $connBilling = isset($conn) ? $conn : null;
    if (!($connBilling instanceof mysqli)) {
        echo json_encode(['success' => false, 'message' => 'Koneksi billing tidak tersedia']);
        exit;
    }

    $ownerUserId = isset($USER_ID) ? (int)$USER_ID : (isset($current_user_id) ? (int)$current_user_id : 0);
    $stmtCheck = $connBilling->prepare("SELECT t.id, t.detail, t.judul FROM billing_tiket_manager t INNER JOIN server s ON s.id = t.server_id WHERE t.id = ? AND s.user_id = ? LIMIT 1");
    $stmtCheck->bind_param('ii', $id, $ownerUserId);
    $stmtCheck->execute();
    $rowCheck = $stmtCheck->get_result()->fetch_assoc();
    $stmtCheck->close();

    if (!$rowCheck) {
        echo json_encode(['success' => false, 'message' => 'Tiket tidak ditemukan atau bukan milik akun ini']);
        exit;
    }

    $newDetail = rebuildTicketText((string)$rowCheck['detail'], $nama, $nowa, $kendala);

    $stmtUpd = $connBilling->prepare("UPDATE billing_tiket_manager SET detail = ?, status = ? WHERE id = ?");
    $stmtUpd->bind_param('ssi', $newDetail, $status, $id);
    if (!$stmtUpd->execute()) {
        echo json_encode(['success' => false, 'message' => 'Gagal update: ' . $connBilling->error]);
        exit;
    }
    $stmtUpd->close();

    $history_file = "../notifbot/data/history-$ceknama.json";
    $history = file_exists($history_file) ? (json_decode(file_get_contents($history_file), true) ?: []) : [];
    if (!is_array($history)) $history = [];
    $history[] = "[ $actorName - " . date('Y-m-d H:i:s') . " ] Mengubah tiket #$id (" . ($rowCheck['judul'] ?? '-') . ") jadi status $status di Tiket Manager";
    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    echo json_encode(['success' => true, 'message' => 'Tiket berhasil diperbarui']);
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

$connBilling = isset($conn) ? $conn : null;
$ownedProjects = [];
if ($connBilling instanceof mysqli) {
    $ownerUserId = isset($USER_ID) ? (int)$USER_ID : (isset($current_user_id) ? (int)$current_user_id : 0);
    $qOwned = mysqli_query($connBilling, "SELECT DISTINCT PEMILIK FROM server WHERE user_id = " . (int)$ownerUserId);
    while ($qOwned && ($rSrv = mysqli_fetch_assoc($qOwned))) {
        $ownedProjects[] = (string)($rSrv['PEMILIK'] ?? '');
    }
}

$stmtCheck = $joblist_conn->prepare("SELECT id, data, project FROM joblist WHERE id = ? LIMIT 1");
$stmtCheck->bind_param('i', $id);
$stmtCheck->execute();
$rowCheck = $stmtCheck->get_result()->fetch_assoc();
$stmtCheck->close();

if (!$rowCheck || !in_array((string)($rowCheck['project'] ?? ''), $ownedProjects, true)) {
    echo json_encode(['success' => false, 'message' => 'Tiket tidak ditemukan atau bukan milik akun ini']);
    exit;
}

$newData = rebuildTicketText((string)$rowCheck['data'], $nama, $nowa, $kendala);

$stmtUpd = $joblist_conn->prepare("UPDATE joblist SET data = ?, status = ?, nowa = ? WHERE id = ?");
$stmtUpd->bind_param('sssi', $newData, $status, $nowa, $id);
if (!$stmtUpd->execute()) {
    echo json_encode(['success' => false, 'message' => 'Gagal update: ' . $joblist_conn->error]);
    exit;
}
$stmtUpd->close();
mysqli_close($joblist_conn);

$history_file = "../notifbot/data/history-$ceknama.json";
$history = file_exists($history_file) ? (json_decode(file_get_contents($history_file), true) ?: []) : [];
if (!is_array($history)) $history = [];
$history[] = "[ $actorName - " . date('Y-m-d H:i:s') . " ] Mengubah tiket #$id jadi status $status di Joblist";
file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo json_encode(['success' => true, 'message' => 'Tiket berhasil diperbarui']);
