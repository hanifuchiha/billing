<?php
include 'koneksidb.php';

// Cek apakah kolom TANGGAL_MONTHVERSARY sudah ada
$result = $conn->query("SHOW COLUMNS FROM `pelanggan` LIKE 'TANGGAL_MONTHVERSARY'");
if ($result->num_rows == 0) {
    // Tambah kolom TANGGAL_MONTHVERSARY (anchor tanggal jatuh tempo untuk mode tempo "monthversary")
    $alter_sql = "ALTER TABLE `pelanggan` ADD COLUMN `TANGGAL_MONTHVERSARY` DATE NULL DEFAULT NULL";
    if ($conn->query($alter_sql) === TRUE) {
        echo "Kolom TANGGAL_MONTHVERSARY berhasil ditambahkan.";
    } else {
        echo "Error menambah kolom: " . $conn->error;
    }
} else {
    echo "Kolom TANGGAL_MONTHVERSARY sudah ada.";
}

$conn->close();
?>
