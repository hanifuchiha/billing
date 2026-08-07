<?php
require_once __DIR__ . '/vendor/autoload.php';
use Dompdf\Dompdf;

// Ambil HTML dari file dokumentasi utama (tanpa tombol print/pdf)

$html = file_get_contents('olt_documentation.php');
// Hilangkan semua elemen dengan class no-print-pdf (tombol print & download PDF)
$html = preg_replace('/<[^>]+class="[^"]*no-print-pdf[^"]*"[^>]*>[\s\S]*?<\/[^>]+>/i', '', $html);

$dompdf = new Dompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream('OLT_Dokumentasi.pdf', ['Attachment' => true]);
exit;
