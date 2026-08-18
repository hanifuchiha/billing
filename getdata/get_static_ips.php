<?php
include '../cek-sesi.php';
require_once __DIR__ . '/../staticip_helper.php';
staticipEnsureSchema($conn);

$server = trim((string) ($_GET['server'] ?? ''));
$area = trim((string) ($_GET['area'] ?? ''));
// Saat edit, IP yang sedang dipakai pelanggan ini sendiri harus tetap muncul
// di pilihan (bukan dianggap "terpakai" karena bentrok dengan dirinya sendiri).
$currentIp = trim((string) ($_GET['current_ip'] ?? ''));

if ($server === '' || $area === '') {
    echo '<option value="">-- Pilih Server Area dulu --</option>';
    exit;
}

$serverEsc = mysqli_real_escape_string($conn, $server);
$areaEsc = mysqli_real_escape_string($conn, $area);
$q = mysqli_query($conn, "SELECT * FROM pool_staticip WHERE PEMILIK = '$serverEsc' AND AREA = '$areaEsc' ORDER BY id ASC");

if (!$q || mysqli_num_rows($q) === 0) {
    echo '<option value="">-- Belum ada IP Pool Static di Area ini, buat dulu di menu IP Pool Static --</option>';
    exit;
}

echo '<option value="">-- Pilih IP Static --</option>';
if ($currentIp !== '') {
    echo '<option value="' . htmlspecialchars($currentIp) . '" selected>' . htmlspecialchars($currentIp) . ' (IP saat ini)</option>';
}
while ($pool = mysqli_fetch_assoc($q)) {
    $available = staticipListAvailableIps($conn, $pool, 500);
    foreach ($available as $ip) {
        if ($ip === $currentIp) {
            continue;
        }
        echo '<option value="' . htmlspecialchars($ip) . '">' . htmlspecialchars($ip) . '</option>';
    }
}
exit;
