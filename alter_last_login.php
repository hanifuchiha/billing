<?php
include 'koneksidb.php';

// Cek apakah kolom last_login sudah ada
$result = $conn->query("SHOW COLUMNS FROM `user` LIKE 'last_login'");
if ($result->num_rows == 0) {
    // Tambah kolom last_login
    $alter_sql = "ALTER TABLE `user` ADD COLUMN `last_login` TIMESTAMP NULL DEFAULT NULL";
    if ($conn->query($alter_sql) === TRUE) {
        echo "Kolom last_login berhasil ditambahkan.";
    } else {
        echo "Error menambah kolom: " . $conn->error;
    }
} else {
    echo "Kolom last_login sudah ada.";
}

$conn->close();
?>