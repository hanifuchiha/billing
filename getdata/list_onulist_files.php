<?php
include '../cek-sesi.php'; // Pastikan koneksi ke database sudah benar


$files = glob(__DIR__.'/debug/onulist_*.txt'); // ambil semua file onulist_*.txt
$files = array_map('basename', $files); // hanya nama file
echo json_encode($files);
