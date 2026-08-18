<?php
require '../cek-sesi.php';
require_once __DIR__ . '/../corporate_helper.php';
corporateEnsureSchema($conn);

function redirectEditCorporate($status, $text = '') {
    $url = "../corporate.php?edit=" . urlencode($status);
    if ($text !== '') {
        $url .= "&text=" . urlencode($text);
    }
    header("Location: " . $url);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectEditCorporate('0', 'Metode tidak valid');
}

$id = (int) ($_POST['id'] ?? 0);
$namaPerusahaan = trim((string) ($_POST['nama_perusahaan'] ?? ''));
if ($id <= 0 || $namaPerusahaan === '') {
    redirectEditCorporate('0', 'Nama Perusahaan wajib diisi');
}

// Wajib milik tenant yang login -- ditambah batas AREA kalau sesi ini
// ASSISTANT (lihat corporateAreaFilterSql, pelajaran dari bug scoping area
// sistemik sebelumnya: cek kepemilikan HARUS konsisten di endpoint TULIS,
// bukan cuma listing).
$ceknamaEsc = mysqli_real_escape_string($conn, $ceknama);
$areaFilterCorp = corporateAreaFilterSql('AREA', $AKSES, $area_list ?? '');
$oldRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT LOGO, PORTAL_USERNAME, PORTAL_PASSWORD FROM corporate WHERE id = $id AND PEMILIK = '$ceknamaEsc'" . $areaFilterCorp . " LIMIT 1"));
if (!$oldRow) {
    redirectEditCorporate('0', 'Customer Corporate tidak ditemukan');
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
$status = ($_POST['status'] ?? 'AKTIF') === 'NONAKTIF' ? 'NONAKTIF' : 'AKTIF';
$portalUsername = trim((string) ($_POST['portal_username'] ?? ''));
$portalPasswordRaw = trim((string) ($_POST['portal_password'] ?? ''));

// Username portal unik lintas SEMUA tenant (lihat catatan sama di addcorporate.php).
$portalUsernameSql = 'NULL';
$portalPasswordSql = "'" . mysqli_real_escape_string($conn, (string) $oldRow['PORTAL_PASSWORD']) . "'";
if ($oldRow['PORTAL_PASSWORD'] === null) {
    $portalPasswordSql = 'NULL';
}
if ($portalUsername !== '') {
    $cekUsername = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM corporate WHERE PORTAL_USERNAME = '" . mysqli_real_escape_string($conn, $portalUsername) . "' AND id != $id LIMIT 1"));
    if ($cekUsername) {
        redirectEditCorporate('0', "Username Portal \"$portalUsername\" sudah dipakai perusahaan lain");
    }
    $portalUsernameSql = "'" . mysqli_real_escape_string($conn, $portalUsername) . "'";
    // Password baru diisi -> ganti (hash ulang). Kosong -> pertahankan hash lama.
    if ($portalPasswordRaw !== '') {
        $portalPasswordSql = "'" . mysqli_real_escape_string($conn, password_hash($portalPasswordRaw, PASSWORD_DEFAULT)) . "'";
    } elseif ($oldRow['PORTAL_USERNAME'] === null) {
        // Username baru pertama kali diisi tapi password dikosongkan -- tolak,
        // supaya tidak ada akun portal tanpa password sama sekali.
        redirectEditCorporate('0', 'Password Portal wajib diisi kalau Username Portal baru diisi');
    }
}

$sql = "UPDATE corporate SET
    AREA = '" . mysqli_real_escape_string($conn, $area) . "',
    NAMA_PERUSAHAAN = '" . mysqli_real_escape_string($conn, $namaPerusahaan) . "',
    PJ_NAMA = '" . mysqli_real_escape_string($conn, $pjNama) . "',
    PJ_JABATAN = '" . mysqli_real_escape_string($conn, $pjJabatan) . "',
    NPWP = '" . mysqli_real_escape_string($conn, $npwp) . "',
    NIB = '" . mysqli_real_escape_string($conn, $nib) . "',
    SIUP = '" . mysqli_real_escape_string($conn, $siup) . "',
    ALAMAT_KANTOR = '" . mysqli_real_escape_string($conn, $alamat) . "',
    EMAIL_FINANCE = '" . mysqli_real_escape_string($conn, $emailFinance) . "',
    EMAIL_IT = '" . mysqli_real_escape_string($conn, $emailIt) . "',
    TELEPON = '" . mysqli_real_escape_string($conn, $telepon) . "',
    WHATSAPP = '" . mysqli_real_escape_string($conn, $whatsapp) . "',
    WEBSITE = '" . mysqli_real_escape_string($conn, $website) . "',
    CATATAN = '" . mysqli_real_escape_string($conn, $catatan) . "',
    STATUS = '" . mysqli_real_escape_string($conn, $status) . "',
    PORTAL_USERNAME = $portalUsernameSql,
    PORTAL_PASSWORD = $portalPasswordSql
    WHERE id = $id AND PEMILIK = '$ceknamaEsc'";

if (!mysqli_query($conn, $sql)) {
    redirectEditCorporate('0', 'Gagal update database: ' . mysqli_error($conn));
}

// Ganti logo kalau ada file baru diupload (opsional).
if (isset($_FILES['logo'])) {
    $uploadResult = corporateHandleFileUpload($_FILES['logo'], 'logo', (string) $id);
    if (isset($uploadResult['error'])) {
        redirectEditCorporate('0', 'Data tersimpan, tapi upload logo gagal: ' . $uploadResult['error']);
    }
    if (isset($uploadResult['relative_path'])) {
        mysqli_query($conn, "UPDATE corporate SET LOGO = '" . mysqli_real_escape_string($conn, $uploadResult['relative_path']) . "' WHERE id = $id");
        corporateDeleteDokumenFile((string) ($oldRow['LOGO'] ?? ''));
    }
}

// Replace-all baris PIC (aman krn tidak ada file terkait di child ini).
mysqli_query($conn, "DELETE FROM corporate_pic WHERE corporate_id = $id");

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
            continue;
        }
        mysqli_query($conn, "INSERT INTO corporate_pic (corporate_id, nama, jabatan, email, whatsapp, telepon) VALUES (
            $id,
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
$history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Berhasil edit Customer Corporate '$namaPerusahaan'";
@file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

redirectEditCorporate('1');
