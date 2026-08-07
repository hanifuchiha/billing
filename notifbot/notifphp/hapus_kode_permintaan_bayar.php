<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include '../../koneksidb.php';




////////////////////////////////////////


$filename = basename(__FILE__); // contoh: hapus_kode_permintaan_bayar_FIBERQ.php
$nameOnly = pathinfo($filename, PATHINFO_FILENAME); // hapus_kode_permintaan_bayar_FIBERQ

$parts = explode('_', $nameOnly);
$pemilik = end($parts); // ambil bagian terakhir

echo "Bagian terakhir dari nama file: $pemilik <br>";


//////DATA USERNAME/////////////////////////////////////////////////////////////
$sql99 = "SELECT * FROM `user` WHERE `USERNAME`='$pemilik' ";
$query99 = mysqli_query($conn, $sql99);
while ($data99 = mysqli_fetch_array($query99)) {
    $iduser = $data99['id'];
    $saldo = $data99['saldo'];
    $username = $data99['USERNAME'];
    $password = $data99['PASWORD'];
    $nowa = $data99['NOWA'];
    $AKSES = $data99['STATUS'];
    $domain2 = $data99['domain'];
    $server_list_JOSN = $data99['server'];
    if ($server_list_JOSN == true) {
        $server_list = json_decode($server_list_JOSN);
        $server_list = "'" . implode("', '", $server_list) . "'";
    }
}
/////////////////////////////////////////////////////////////////////////////////


// Path ke file JSON
$jsonFile = "../data/reminder-$pemilik.json";

// Cek apakah file ada
if (file_exists($jsonFile)) {
    // Baca isi file JSON
    $jsonData = file_get_contents($jsonFile);

    // Decode JSON menjadi array asosiatif
    $data = json_decode($jsonData, true);

    // Periksa apakah decoding berhasil
    if ($data !== null) {
        foreach ($data as $item) {
            $jatuh_tempo = $item['jatuh_tempo'];
            $hari_sebelum = $item['hari_sebelum'];
            $tanggal_reminder = $item['tanggal_reminder'];
            $botname = $item['botname'];
        }
    } else {
        echo "Error: Gagal mendecode JSON.";
    }
} else {
    echo "Error: File JSON tidak ditemukan.";
}




// Cek apakah sudah pernah dikirim
$history_file = "../data/history-$pemilik.json";
$history = [];

if (file_exists($history_file)) {
    $history = json_decode(file_get_contents($history_file), true);
}

// Pastikan format history adalah array
if (!is_array($history)) {
    $history = [];
}

/////////////////////////////////////////////////////////////////////////////////












// Query SQL
$sql2 = "DELETE FROM transaksi WHERE `STATUS` LIKE 'PERMINTAAN KODE'  AND `waktu` < CURDATE() - INTERVAL 2 DAY";

// Eksekusi query
$query2 = mysqli_query($conn, $sql2);

// Periksa keberhasilan
if ($query2) {
    // Ambil jumlah baris yang terpengaruh
    $affectedRows = mysqli_affected_rows($conn);

    if ($affectedRows > 0) {
        echo "Berhasil menghapus $affectedRows data PERMINTAAN KODE expired.";
        // --- Catat log ---
        $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Deleted $affectedRows expired PERMINTAAN KODE transactions";
        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    } else {
        echo "Tidak ada data yang dihapus";
    }
} else {
    // Sebelumnya kegagalan query cuma di-echo (kebuang oleh redirect cron
    // `> /dev/null 2>&1`), jadi tidak pernah tercatat di history.
    echo "Terjadi kesalahan: " . mysqli_error($conn);
    $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] GAGAL hapus PERMINTAAN KODE expired: " . mysqli_error($conn);
    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
}
