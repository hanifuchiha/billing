<?php
require '../cek-sesi.php';
require_once __DIR__ . '/../corporate_helper.php';
corporateEnsureSchema($conn);

function redirectAddCorporate($status, $text = '') {
    $url = "../corporate.php?statusnotif=" . urlencode($status);
    if ($text !== '') {
        $url .= "&text=" . urlencode($text);
    }
    header("Location: " . $url);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectAddCorporate('failed', 'Metode tidak valid');
}

$namaPerusahaan = trim((string) ($_POST['nama_perusahaan'] ?? ''));
if ($namaPerusahaan === '') {
    redirectAddCorporate('failed', 'Nama Perusahaan wajib diisi');
}

$area = trim((string) ($_POST['area'] ?? ''));
$pjNama = trim((string) ($_POST['pj_nama'] ?? ''));
$pjJabatan = trim((string) ($_POST['pj_jabatan'] ?? ''));
$npwp = trim((string) ($_POST['npwp'] ?? ''));
$nib = trim((string) ($_POST['nib'] ?? ''));
$siup = trim((string) ($_POST['siup'] ?? ''));
$alamat = trim((string) ($_POST['alamat_kantor'] ?? ''));
$emailFinance = trim((string) ($_POST['email_finance'] ?? ''));
$emailIt = trim((string) ($_POST['email_it'] ?? ''));
$telepon = trim((string) ($_POST['telepon'] ?? ''));
$whatsapp = trim((string) ($_POST['whatsapp'] ?? ''));
$website = trim((string) ($_POST['website'] ?? ''));
$catatan = trim((string) ($_POST['catatan'] ?? ''));
$portalUsername = trim((string) ($_POST['portal_username'] ?? ''));
$portalPasswordRaw = trim((string) ($_POST['portal_password'] ?? ''));

// Username portal (kalau diisi) wajib unik lintas SEMUA tenant -- portal
// login corporate satu pintu bersama, tidak ada langkah pilih tenant dulu.
$portalUsernameSql = 'NULL';
$portalPasswordSql = 'NULL';
if ($portalUsername !== '') {
    if ($portalPasswordRaw === '') {
        redirectAddCorporate('failed', 'Password Portal wajib diisi kalau Username Portal diisi');
    }
    $cekUsername = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM corporate WHERE PORTAL_USERNAME = '" . mysqli_real_escape_string($conn, $portalUsername) . "' LIMIT 1"));
    if ($cekUsername) {
        redirectAddCorporate('failed', "Username Portal \"$portalUsername\" sudah dipakai perusahaan lain");
    }
    $portalUsernameSql = "'" . mysqli_real_escape_string($conn, $portalUsername) . "'";
    $portalPasswordSql = "'" . mysqli_real_escape_string($conn, password_hash($portalPasswordRaw, PASSWORD_DEFAULT)) . "'";
}

$sql = "INSERT INTO corporate (PEMILIK, AREA, NAMA_PERUSAHAAN, PJ_NAMA, PJ_JABATAN, NPWP, NIB, SIUP, ALAMAT_KANTOR, EMAIL_FINANCE, EMAIL_IT, TELEPON, WHATSAPP, WEBSITE, CATATAN, STATUS, PORTAL_USERNAME, PORTAL_PASSWORD) VALUES (
    '" . mysqli_real_escape_string($conn, $ceknama) . "',
    '" . mysqli_real_escape_string($conn, $area) . "',
    '" . mysqli_real_escape_string($conn, $namaPerusahaan) . "',
    '" . mysqli_real_escape_string($conn, $pjNama) . "',
    '" . mysqli_real_escape_string($conn, $pjJabatan) . "',
    '" . mysqli_real_escape_string($conn, $npwp) . "',
    '" . mysqli_real_escape_string($conn, $nib) . "',
    '" . mysqli_real_escape_string($conn, $siup) . "',
    '" . mysqli_real_escape_string($conn, $alamat) . "',
    '" . mysqli_real_escape_string($conn, $emailFinance) . "',
    '" . mysqli_real_escape_string($conn, $emailIt) . "',
    '" . mysqli_real_escape_string($conn, $telepon) . "',
    '" . mysqli_real_escape_string($conn, $whatsapp) . "',
    '" . mysqli_real_escape_string($conn, $website) . "',
    '" . mysqli_real_escape_string($conn, $catatan) . "',
    'AKTIF',
    $portalUsernameSql,
    $portalPasswordSql
)";

if (!mysqli_query($conn, $sql)) {
    redirectAddCorporate('failed', 'Gagal menyimpan data perusahaan: ' . mysqli_error($conn));
}
$corporateId = mysqli_insert_id($conn);

// Upload logo (opsional).
if (isset($_FILES['logo'])) {
    $uploadResult = corporateHandleFileUpload($_FILES['logo'], 'logo', (string) $corporateId);
    if (isset($uploadResult['error'])) {
        redirectAddCorporate('failed', 'Perusahaan tersimpan, tapi upload logo gagal: ' . $uploadResult['error']);
    }
    if (isset($uploadResult['relative_path'])) {
        mysqli_query($conn, "UPDATE corporate SET LOGO = '" . mysqli_real_escape_string($conn, $uploadResult['relative_path']) . "' WHERE id = $corporateId");
    }
}

// Simpan baris PIC (array paralel dari form dinamis).
$picNama = $_POST['pic_nama'] ?? [];
$picJabatan = $_POST['pic_jabatan'] ?? [];
$picEmail = $_POST['pic_email'] ?? [];
$picWhatsapp = $_POST['pic_whatsapp'] ?? [];
$picTelepon = $_POST['pic_telepon'] ?? [];

if (is_array($picNama)) {
    foreach ($picNama as $i => $nama) {
        $nama = trim((string) $nama);
        $jabatan = trim((string) ($picJabatan[$i] ?? ''));
        $email = trim((string) ($picEmail[$i] ?? ''));
        $wa = trim((string) ($picWhatsapp[$i] ?? ''));
        $tlp = trim((string) ($picTelepon[$i] ?? ''));
        if ($nama === '' && $jabatan === '' && $email === '' && $wa === '' && $tlp === '') {
            continue; // baris kosong (dikirim tapi tidak diisi), lewati.
        }
        mysqli_query($conn, "INSERT INTO corporate_pic (corporate_id, nama, jabatan, email, whatsapp, telepon) VALUES (
            $corporateId,
            '" . mysqli_real_escape_string($conn, $nama) . "',
            '" . mysqli_real_escape_string($conn, $jabatan) . "',
            '" . mysqli_real_escape_string($conn, $email) . "',
            '" . mysqli_real_escape_string($conn, $wa) . "',
            '" . mysqli_real_escape_string($conn, $tlp) . "'
        )");
    }
}

$history_file = "../notifbot/data/history-$ceknama.json";
$history = [];
if (file_exists($history_file)) {
    $history = json_decode(file_get_contents($history_file), true);
}
if (!is_array($history)) { $history = []; }
$history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Berhasil menambahkan Customer Corporate '$namaPerusahaan'";
@file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

redirectAddCorporate('success');
