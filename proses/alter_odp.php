<?php
require '../cek-sesi.php'; // Pastikan koneksi database ada

// Query untuk menambahkan kolom Hirarki
$query = "ALTER TABLE odp ADD COLUMN Hirarki VARCHAR(255) DEFAULT NULL";

if (mysqli_query($conn, $query)) {
    echo "Kolom 'Hirarki' berhasil ditambahkan ke tabel 'odp'.";
} else {
    echo "Error: " . mysqli_error($conn);
}
?>