<?php
include '../cek-sesi.php'; // Pastikan koneksi ke database sudah benar

$file = $_GET['file'] ?? '';
$path = __DIR__ . '/debug/' . basename($file); // mencegah traversal
if (file_exists($path)) {
    echo file_get_contents($path);
} else {
    echo '';
}