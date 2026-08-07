<?php
require 'vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

// Halaman ini publik (tanpa login) -- dilink dari riwayatTransaction.php yang
// memang diakses pelanggan tanpa login CRM, jadi TIDAK boleh require cek-sesi.php
// (itu akan redirect ke login admin). Koneksi DB cukup lewat koneksidb.php.
require 'koneksidb.php';
require_once 'struk_helper.php';

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    die("ID tidak ditemukan");
}

$query = mysqli_query($conn, "SELECT * FROM transaksi WHERE id=" . $id);
$data = mysqli_fetch_assoc($query);

if (!$data) {
    die("Data tidak ditemukan");
}

// Pengaturan struk disimpan per akun CRM (lihat struk_setting.php), sedangkan
// transaksi.PEMILIK adalah username MikroTik per-server (lihat proses/addserver.php),
// bukan username akun CRM -- resolve dulu akun pemilik server ini lewat join
// server->user (sama seperti riwayatTransaction.php), BUKAN dari session
// $ceknama/$asistant_name, supaya tetap benar walau diakses tanpa login.
$struk_settings_username = (string)($data['PEMILIK'] ?? '');
$rowOwnerQ = mysqli_query($conn, "SELECT u.USERNAME FROM server s INNER JOIN user u ON u.id = s.user_id WHERE s.PEMILIK = '" . mysqli_real_escape_string($conn, (string)($data['PEMILIK'] ?? '')) . "' LIMIT 1");
if ($rowOwnerQ && mysqli_num_rows($rowOwnerQ) > 0) {
    $struk_settings_username = mysqli_fetch_assoc($rowOwnerQ)['USERNAME'];
}
$settings = get_struk_settings($struk_settings_username);

if (empty($settings['pdf_enabled'])) {
    die("Download PDF struk sedang dinonaktifkan. Aktifkan di menu Pengaturan Struk.");
}

$paper_size = $settings['paper_size'];
$width_pt = struk_paper_width_pt($paper_size);

// Hitung tinggi PDF (untuk kertas thermal 58mm/80mm; A4 pakai ukuran tetap)
$numRows = 8; // jumlah baris data di render_struk_body_html
$lineHeight = 15;
$headerHeight = 100; // logo + judul + alamat/no cs
$footerHeight = 70; // footer struk
$customHeight = $headerHeight + ($numRows * $lineHeight) + $footerHeight;

$struk_body = render_struk_body_html($data, $settings);
$css = struk_shared_css($width_pt . 'pt');

$html = '
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<style>' . $css . '
body { width: ' . $width_pt . 'pt; }
</style>
</head>
<body>
<div class="struk-paper">' . $struk_body . '</div>
</body>
</html>
';

$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);

if ($paper_size === 'a4') {
    $dompdf->setPaper('A4', 'portrait');
} else {
    $dompdf->setPaper([0, 0, $width_pt, $customHeight], 'portrait');
}

$dompdf->render();

$filename = 'Struk_' . $data['IDPEL'] . '.pdf';
$dompdf->stream($filename, ["Attachment" => true]);
exit;
