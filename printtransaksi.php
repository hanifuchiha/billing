<?php
require 'cek-sesi.php';

// Periksa koneksi
if ($conn->connect_error) {
    die("Koneksi gagal: " . $conn->connect_error);
}

// Array nama bulan dalam bahasa Indonesia
$bulan_nama = [
    'Januari',
    'Februari',
    'Maret',
    'April',
    'Mei',
    'Juni',
    'Juli',
    'Agustus',
    'September',
    'Oktober',
    'November',
    'Desember'
];

date_default_timezone_set('Asia/Jakarta');
$tahun = $_GET['tahun'];
$pemilik = $_GET['pemilik'];
$data = [];

// Loop untuk setiap bulan
foreach ($bulan_nama as $index => $bulan) {
    $query = "SELECT COUNT(*) AS jumlah_transaksi FROM transaksi WHERE PENGUNAAN LIKE '%$bulan $tahun%' AND STATUS = 'BERHASIL'";
    $result = $conn->query($query);
    if ($result) {
        $row = $result->fetch_assoc();
        $data[] = ['bulan' => "$bulan $tahun", 'jumlah_transaksi' => $row['jumlah_transaksi']];
    } else {
        echo "Error executing query: " . $conn->error;
        exit;
    }
}



$html = '<h2>Laporan Transaksi Berhasil</h2>
         <table border="1" cellpadding="10" cellspacing="0">
         <tr><th>Bulan</th><th>Jumlah Transaksi</th></tr>';

foreach ($data as $row) {
    $html .= "<tr><td>{$row['bulan']}</td><td>{$row['jumlah_transaksi']}</td></tr>";
}

$html .= '</table><br><br>';

// Menambahkan tabel baru dengan data lengkap transaksi
$html .= '<h2>Data Lengkap Transaksi</h2>
          <table border="1" cellpadding="10" cellspacing="0">
          <tr><th>ID PELANGGAN</th><th>NAMA</th><th>TANGGAL BAYAR</th><th>STATUS</th><th>PAKET</th><th>PRODUK</th></tr>';

foreach ($bulan_nama as $index => $bulan) {
    $query_lengkap = "SELECT * FROM transaksi WHERE PENGUNAAN LIKE '%$bulan $tahun%' AND STATUS = 'BERHASIL' AND PEMILIK ='$pemilik'";
    $result_lengkap = $conn->query($query_lengkap);

    while ($row_lengkap = $result_lengkap->fetch_assoc()) {
        $html .= "<tr><td>{$row_lengkap['IDPEL']}</td><td>{$row_lengkap['NAMA']}</td><td>{$row_lengkap['TANGGALBAYAR']}</td><td>{$row_lengkap['STATUS']}</td><td>{$row_lengkap['PAKET']}</td><td>{$row_lengkap['PEMILIK']}</td></tr>";
    }
}

$html .= '</table>';

// Echo for debugging purpose, remove or comment out when final
// echo $html;


require_once 'dompdf/src/Autoloader.php';
Dompdf\Autoloader::register();

use Dompdf\Dompdf;

$dompdf = new Dompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'landscape');
$dompdf->set_option('isHtml5ParserEnabled', true);
$dompdf->render();
$dompdf->stream('Laporan_Transaksi.pdf');
