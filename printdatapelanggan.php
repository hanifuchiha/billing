<?php
require 'cek-sesi.php';

// Periksa koneksi
if ($conn->connect_error) {
    die("Koneksi gagal: " . $conn->connect_error);
}
$pemilik = $_GET['pemilik']; // Get pemilik from the query string



// Menambahkan tabel baru dengan data lengkap transaksi
$html = '<h2>Data Lengkap pelanggan '.$pemilik.'</h2>
          <table border="1" >
          <tr><th>ID PELANGGAN</th><th>NAMA</th><th>PAKET</th><th>TANGGAL PASANG</th><th>EMAIL</th><th>WHATSAPP</th><th>PRODUK</th></tr>';

$query_lengkap = "SELECT * FROM pelanggan WHERE PEMILIK = '$pemilik'"; // Update query to select based on PEMILIK

$result_lengkap = $conn->query($query_lengkap);

while ($row_lengkap = $result_lengkap->fetch_assoc()) {
    $html .= "<tr><td>{$row_lengkap['IDPEL']}</td><td>{$row_lengkap['NAMA']}</td><td>{$row_lengkap['PAKET']}</td><td>{$row_lengkap['TANGGALPASANG']}</td><td>{$row_lengkap['EMAIL']}</td><td>{$row_lengkap['NOWA']}</td><td>{$row_lengkap['PEMILIK']}</td></tr>";
}

 $html .= '</table>';

// Echo for debugging purpose, remove or comment out when final
// echo $html;

// Menyimpan ke PDF

require_once 'dompdf/src/Autoloader.php';
Dompdf\Autoloader::register();
use Dompdf\Dompdf;
$dompdf = new Dompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'landscape');
$dompdf->set_option('isHtml5ParserEnabled', true);
$dompdf->render();
$dompdf->stream('data_pelanggan.pdf');
?>
