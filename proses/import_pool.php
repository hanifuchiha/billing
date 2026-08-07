<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../cek-sesi.php';
require_once __DIR__ . '/../libs/SimpleXLSX.php';

function redirectImport($status, $message) {
    $url = '../pool.php?statusnotif=' . urlencode($status) . '&text=' . urlencode($message);
    header('Location: ' . $url);
    exit;
}

function cidrToRange($cidr) {
    $parts = explode('/', $cidr);
    if (count($parts) !== 2) {
        return false;
    }

    $ip = trim($parts[0]);
    $prefix = (int)trim($parts[1]);
    if ($prefix < 0 || $prefix > 32) {
        return false;
    }

    $ipLong = ip2long($ip);
    if ($ipLong === false) {
        return false;
    }

    $mask = -1 << (32 - $prefix);
    $network = $ipLong & $mask;
    $broadcast = $network | (~$mask & 0xFFFFFFFF);

    $first = ($prefix < 31) ? $network + 1 : $network;
    $last = ($prefix < 31) ? $broadcast - 1 : $broadcast;

    return [long2ip($first), long2ip($last)];
}

function isValidIpv4($ip) {
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
}

if (
    $_SERVER['REQUEST_METHOD'] !== 'POST' ||
    !isset($_FILES['excel_file']) ||
    !isset($_FILES['excel_file']['tmp_name']) ||
    !is_uploaded_file($_FILES['excel_file']['tmp_name']) ||
    $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK
) {
    redirectImport('import_failed', 'Tidak ada file yang diupload atau upload gagal.');
}

$fileTmp = $_FILES['excel_file']['tmp_name'];
$fileName = $_FILES['excel_file']['name'];
$ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

if ($ext !== 'xlsx') {
    redirectImport('import_failed', 'File harus berformat .xlsx');
}

$xlsx = SimpleXLSX::parse($fileTmp);
if (!$xlsx) {
    redirectImport('import_failed', 'Gagal membaca file Excel: ' . SimpleXLSX::parseError());
}

$rows = $xlsx->rows();
if (isset($_POST['skip_first_row'])) {
    array_shift($rows);
}

$successCount = 0;
$failCount = 0;
$failMessages = [];

foreach ($rows as $i => $row) {
    if (empty(array_filter($row, function ($v) {
        return trim((string)$v) !== '';
    }))) {
        continue;
    }

    $line = $i + 2;
    $poolName = trim((string)($row[0] ?? ''));
    $ipAwal = trim((string)($row[1] ?? ''));
    $ipAkhir = trim((string)($row[2] ?? ''));
    $ipLocal = trim((string)($row[3] ?? ''));
    $pemilik = trim((string)($row[4] ?? ''));

    if ($pemilik === '') {
        $pemilik = $ceknama;
    }

    $missing = [];
    if ($poolName === '') {
        $missing[] = 'pool_name';
    }
    if ($ipAwal === '') {
        $missing[] = 'ipawal';
    }
    if ($ipLocal === '') {
        $missing[] = 'iplocal';
    }
    if ($pemilik === '') {
        $missing[] = 'pemilik';
    }

    if (!empty($missing)) {
        $failCount++;
        $failMessages[] = 'Baris ' . $line . ': Field kosong: ' . implode(', ', $missing);
        continue;
    }

    if (strpos($ipAwal, '/') !== false) {
        $range = cidrToRange($ipAwal);
        if ($range === false) {
            $failCount++;
            $failMessages[] = 'Baris ' . $line . ': Format subnet tidak valid.';
            continue;
        }
        $ipAwal = $range[0];
        $ipAkhir = $range[1];
    }

    if ($ipAkhir === '') {
        $failCount++;
        $failMessages[] = 'Baris ' . $line . ': ipakhir wajib diisi jika ipawal bukan subnet.';
        continue;
    }

    if (!isValidIpv4($ipAwal) || !isValidIpv4($ipAkhir) || !isValidIpv4($ipLocal)) {
        $failCount++;
        $failMessages[] = 'Baris ' . $line . ': Format IP tidak valid.';
        continue;
    }

    if (ip2long($ipAwal) > ip2long($ipAkhir)) {
        $failCount++;
        $failMessages[] = 'Baris ' . $line . ': ipawal tidak boleh lebih besar dari ipakhir.';
        continue;
    }

    $poolEsc = mysqli_real_escape_string($conn, $poolName);
    $pemilikEsc = mysqli_real_escape_string($conn, $pemilik);
    $awalEsc = mysqli_real_escape_string($conn, $ipAwal);
    $akhirEsc = mysqli_real_escape_string($conn, $ipAkhir);
    $localEsc = mysqli_real_escape_string($conn, $ipLocal);

    $checkName = mysqli_query($conn, "SELECT id FROM pool WHERE pool_name = '$poolEsc' AND pemilik = '$pemilikEsc' LIMIT 1");
    if ($checkName && mysqli_num_rows($checkName) > 0) {
        $failCount++;
        $failMessages[] = 'Baris ' . $line . ': Nama pool sudah ada untuk pemilik ini.';
        continue;
    }

    $checkIp = mysqli_query($conn, "
        SELECT id FROM pool
        WHERE pemilik = '$pemilikEsc' AND (
            iplocal = '$localEsc'
            OR ipawal = '$awalEsc'
            OR ipakhir = '$akhirEsc'
            OR ('$awalEsc' BETWEEN ipawal AND ipakhir)
            OR ('$akhirEsc' BETWEEN ipawal AND ipakhir)
        )
        LIMIT 1
    ");

    if ($checkIp && mysqli_num_rows($checkIp) > 0) {
        $failCount++;
        $failMessages[] = 'Baris ' . $line . ': IP range atau IP local sudah digunakan.';
        continue;
    }

    $insertSql = "INSERT INTO pool (pool_name, ipawal, ipakhir, iplocal, pemilik) VALUES ('$poolEsc', '$awalEsc', '$akhirEsc', '$localEsc', '$pemilikEsc')";
    if (!mysqli_query($conn, $insertSql)) {
        $failCount++;
        $failMessages[] = 'Baris ' . $line . ': Gagal simpan database: ' . mysqli_error($conn);
        continue;
    }

    $successCount++;
}

$message = 'Sukses: ' . $successCount . ' baris. Gagal: ' . $failCount . ' baris.';
if ($failCount > 0) {
    $message .= "\n" . implode("\n", $failMessages);
}

$history_file = '../notifbot/data/history-' . $ceknama . '.json';
$history = [];
if (file_exists($history_file)) {
    $history = json_decode(file_get_contents($history_file), true);
}
if (!is_array($history)) {
    $history = [];
}
$history[] = '[ ' . (!empty($asistant_name) ? $asistant_name : $ceknama) . ' - ' . date('Y-m-d H:i:s') . ' ] Import IP Pool - ' . $message;
file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

if ($failCount === 0) {
    redirectImport('import_success', $message);
}

redirectImport('import_failed', $message);
