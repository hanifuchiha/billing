<?php
require '../cek-sesi.php';

// Ambil id dari GET dan pastikan angka
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if($id <= 0){
    die("<script>alert('ID tidak valid!');window.location='../pool.php';</script>");
}

// Ambil nama pool sebelum hapus untuk log
$sql_get = "SELECT pool_name, iplocal, ipawal, ipakhir FROM pool WHERE id=$id";
$result = mysqli_query($conn, $sql_get);
if($result && mysqli_num_rows($result) > 0){
    $row = mysqli_fetch_assoc($result);
    $pool_name = $row['pool_name'];
    $iplocal = $row['iplocal'];
    $ipawal = $row['ipawal'];
    $ipakhir = $row['ipakhir'];
    $range = $ipawal . '-' . $ipakhir;
} else {
    die("<script>alert('Pool tidak ditemukan!');window.location='../pool.php';</script>");
}

// Cek apakah pool masih digunakan oleh paket
$cek_paket = mysqli_query($conn, "SELECT COUNT(*) as jumlah FROM paket WHERE LOCAL = '$iplocal' OR REMOTE = '$range'");
$row_cek = mysqli_fetch_assoc($cek_paket);
if ($row_cek['jumlah'] > 0) {
    header("Location: ../pool.php?statusnotif=cannot_delete");
    exit;
}

// Hapus data pool dari database
$sql = "DELETE FROM pool WHERE id=$id";
if(mysqli_query($conn, $sql)){
    // log history
    $history_file = "../notifbot/data/history-$ceknama.json";
    $history = [];
    if (file_exists($history_file)) {
        $history = json_decode(file_get_contents($history_file), true);
    }
    if (!is_array($history)) { $history = []; }
    $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Berhasil menghapus pool $pool_name";
    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    header("Location: ../pool.php?statusnotif=success_delete");
    exit;
} else {
    header("Location: ../pool.php?statusnotif=failed_delete");
    exit;
}
