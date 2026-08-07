<?php
// Script SEMENTARA untuk diagnosis "pajak DompetX masih 11% padahal sudah
// diset 0" -- kode cek_sesi.php sudah benar di disk, jadi salah satu
// kemungkinan adalah OPcache PHP masih menyimpan bytecode versi lama.
// Hapus file ini setelah selesai, bukan bagian permanen aplikasi.
require '../cek-sesi.php';

header('Content-Type: text/plain; charset=utf-8');

echo "PHP version: " . phpversion() . "\n";
echo "opcache.enable: " . (ini_get('opcache.enable') ?: '(tidak ada)') . "\n";
echo "opcache.enable_cli: " . (ini_get('opcache.enable_cli') ?: '(tidak ada)') . "\n";

if (function_exists('opcache_get_status')) {
    $status = opcache_get_status(false);
    if ($status === false) {
        echo "opcache_get_status(): OPcache tidak aktif.\n";
    } else {
        echo "OPcache aktif: memory_usage used=" . ($status['memory_usage']['used_memory'] ?? '?') . "\n";
        echo "Jumlah script ter-cache: " . ($status['opcache_statistics']['num_cached_scripts'] ?? '?') . "\n";
    }
} else {
    echo "Fungsi opcache_get_status tidak tersedia (ekstensi OPcache mungkin tidak terpasang).\n";
}

if (function_exists('opcache_reset')) {
    $reset = opcache_reset();
    echo "opcache_reset(): " . ($reset ? 'BERHASIL, semua cache dibersihkan' : 'GAGAL') . "\n";
} else {
    echo "Fungsi opcache_reset tidak tersedia.\n";
}

// Konfirmasi langsung: baca ulang cek_sesi.php dan cari case 'dompetx' di
// dalamnya, supaya kelihatan apakah isi file yang dieksekusi PHP sekarang
// benar-benar sudah versi terbaru.
$cekSesiContent = file_get_contents(__DIR__ . '/../broadband/cek_sesi.php');
echo "\ncek_sesi.php mengandung \"case 'dompetx':\": " . (strpos($cekSesiContent, "case 'dompetx':") !== false ? 'YA' : 'TIDAK') . "\n";
echo "cek_sesi.php last modified: " . date('Y-m-d H:i:s', filemtime(__DIR__ . '/../broadband/cek_sesi.php')) . "\n";
