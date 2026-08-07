<?php
require '../cek-sesi.php';

// Ambil data dari form dan escape

$pool_name   = mysqli_real_escape_string($conn, trim($_POST['pool_name'] ?? ''));
$local_ip    = mysqli_real_escape_string($conn, trim($_POST['local_ip'] ?? ''));
$range_start = mysqli_real_escape_string($conn, trim($_POST['range_start'] ?? ''));
$range_end   = mysqli_real_escape_string($conn, trim($_POST['range_end'] ?? ''));
$pemilik     = mysqli_real_escape_string($conn, trim($_POST['pemilik'] ?? ''));

// Tambahan: support subnet input (misal: 192.168.1.0/24) pada kolom range_start
function cidrToRange($cidr) {
    $parts = explode('/', $cidr);
    if (count($parts) != 2) return false;
    $ip = $parts[0];
    $prefix = (int)$parts[1];
    if ($prefix < 0 || $prefix > 32) return false;
    $ip_long = ip2long($ip);
    if ($ip_long === false) return false;
    $mask = -1 << (32 - $prefix);
    $network = $ip_long & $mask;
    $broadcast = $network | (~$mask & 0xFFFFFFFF);
    // Pool biasanya tidak pakai network/broadcast, skip keduanya jika /30 ke bawah
    $first = ($prefix < 31) ? $network + 1 : $network;
    $last = ($prefix < 31) ? $broadcast - 1 : $broadcast;
    return [long2ip($first), long2ip($last)];
}

// Jika user isi range_start dengan subnet (ada /), auto hitung range_start dan range_end
if (strpos($range_start, '/') !== false) {
    $range = cidrToRange($range_start);
    if ($range) {
        $range_start = $range[0];
        $range_end = $range[1];
    } else {
        header("Location: ../pool.php?statusnotif=failed");
        exit;
    }
}

// Cek data lengkap
if(!$pool_name || !$local_ip || !$range_start || !$range_end || !$pemilik){
   header("Location: ../pool.php?statusnotif=failed");
    exit;
}

// ===============================
// CEK DUPLIKAT
// ===============================

// 1️⃣ Cek nama pool sudah ada
$check_name = mysqli_query($conn, "SELECT * FROM pool WHERE pool_name = '$pool_name' AND pemilik = '$pemilik' LIMIT 1");
if(mysqli_num_rows($check_name) > 0){
     header("Location: ../pool.php?statusnotif=duplicate");
    exit;
}

// 2️⃣ Cek apakah ada overlap / IP sudah digunakan (baik local_ip, ipawal, ipakhir)
$check_ip = mysqli_query($conn, "
    SELECT * FROM pool 
    WHERE pemilik = '$pemilik' AND (
        iplocal = '$local_ip' 
        OR ipawal = '$range_start' 
        OR ipakhir = '$range_end'
        OR ('$range_start' BETWEEN ipawal AND ipakhir)
        OR ('$range_end' BETWEEN ipawal AND ipakhir)
    )
    LIMIT 1
");

if(mysqli_num_rows($check_ip) > 0){
    die("<script>alert('❌ IP range atau IP lokal sudah digunakan oleh pool lain!');window.location='../pool.php';</script>");
}

// ===============================
// SIMPAN DATA BARU
// ===============================
$sql = "INSERT INTO pool (pool_name, ipawal, ipakhir, iplocal, pemilik) 
        VALUES ('$pool_name', '$range_start', '$range_end', '$local_ip', '$pemilik')";
// ✅ Eksekusi INSERT jika tidak duplikat
if (mysqli_query($conn, $sql)) {
    // log history
    $history_file = "../notifbot/data/history-$ceknama.json";
    $history = [];
    if (file_exists($history_file)) {
        $history = json_decode(file_get_contents($history_file), true);
    }
    if (!is_array($history)) { $history = []; }
    $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Berhasil menambahkan pool $pool_name";
    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    header("Location: ../pool.php?statusnotif=success");
} else {
    header("Location: ../pool.php?statusnotif=failed");
}
exit;
?>
