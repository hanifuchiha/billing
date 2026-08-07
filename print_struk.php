<?php
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

if (empty($settings['print_enabled'])) {
    die("Print struk langsung sedang dinonaktifkan. Aktifkan di menu Pengaturan Struk.");
}

$paper_size = $settings['paper_size'];
$width_css = struk_paper_width_css($paper_size);
$struk_body = render_struk_body_html($data, $settings);
$css = struk_shared_css($width_css);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Print Struk <?php echo htmlspecialchars($data['IDPEL']); ?></title>
<style>
<?php echo $css; ?>
html, body {
    background: #eef1f5;
    font-family: monospace;
}
.print-toolbar {
    max-width: <?php echo htmlspecialchars($width_css); ?>;
    margin: 16px auto 0;
    text-align: center;
}
.print-toolbar button {
    font-family: inherit;
    font-size: 13px;
    padding: 8px 16px;
    border: none;
    border-radius: 6px;
    background: #2563eb;
    color: #fff;
    cursor: pointer;
}
.print-toolbar button:hover { background: #1d4ed8; }
.struk-wrap {
    display: flex;
    justify-content: center;
    padding: 16px;
}
.struk-paper {
    background: #fff;
    box-shadow: 0 2px 10px rgba(0,0,0,.15);
}
@media print {
    html, body { background: #fff; }
    .print-toolbar { display: none; }
    .struk-wrap { padding: 0; }
    .struk-paper { box-shadow: none; }
}
</style>
</head>
<body>
<div class="print-toolbar">
    <button type="button" onclick="window.print()">🖨️ Cetak Struk</button>
</div>
<div class="struk-wrap">
    <div class="struk-paper"><?php echo $struk_body; ?></div>
</div>
<script>
    window.addEventListener('load', function () {
        setTimeout(function () { window.print(); }, 250);
    });
</script>
</body>
</html>
