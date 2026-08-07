<?php
require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    twk_response(405, ['success' => false, 'message' => 'Method tidak diizinkan.']);
}

$conn = twk_db_connect();
$session = twk_require_auth($conn);
$idpel = (string)$session['idpel'];

$pelanggan = twk_find_customer($conn, $idpel);
if (!$pelanggan) {
    twk_response(404, ['success' => false, 'message' => 'Data pelanggan tidak ditemukan.']);
}

$body = twk_get_json_body();
$nama = trim((string)($body['nama'] ?? ($_POST['nama'] ?? ($pelanggan['NAMA'] ?? ''))));
$nowa = trim((string)($body['nowa'] ?? ($_POST['nowa'] ?? ($pelanggan['NOWA'] ?? ''))));
$email = trim((string)($body['email'] ?? ($_POST['email'] ?? ($pelanggan['EMAIL'] ?? ''))));
$alamat = trim((string)($body['alamat'] ?? ($_POST['alamat'] ?? ($pelanggan['ALAMAT'] ?? ''))));

if ($nama === '') {
    twk_response(422, ['success' => false, 'message' => 'Nama wajib diisi.']);
}
if ($nowa === '') {
    twk_response(422, ['success' => false, 'message' => 'Nomor WhatsApp wajib diisi.']);
}
if (strlen($nama) > 191) {
    twk_response(422, ['success' => false, 'message' => 'Nama terlalu panjang.']);
}
if (strlen($alamat) > 5000) {
    twk_response(422, ['success' => false, 'message' => 'Alamat terlalu panjang.']);
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    twk_response(422, ['success' => false, 'message' => 'Format email tidak valid.']);
}

$nowaNorm = twk_normalize_phone($nowa);
if ($nowaNorm === '') {
    twk_response(422, ['success' => false, 'message' => 'Nomor WhatsApp tidak valid.']);
}

$stmt = mysqli_prepare($conn, 'UPDATE pelanggan SET NAMA = ?, NOWA = ?, EMAIL = ?, ALAMAT = ? WHERE IDPEL = ? LIMIT 1');
if (!$stmt) {
    twk_response(500, ['success' => false, 'message' => 'Gagal menyiapkan update profil.']);
}

mysqli_stmt_bind_param($stmt, 'sssss', $nama, $nowaNorm, $email, $alamat, $idpel);
$ok = mysqli_stmt_execute($stmt);
$affected = (int)mysqli_stmt_affected_rows($stmt);
mysqli_stmt_close($stmt);

if (!$ok) {
    twk_response(500, ['success' => false, 'message' => 'Gagal menyimpan perubahan profil.']);
}

$updated = twk_find_customer($conn, $idpel);
if (!$updated) {
    twk_response(500, ['success' => false, 'message' => 'Profil berhasil diubah tapi gagal membaca data terbaru.']);
}

twk_response(200, [
    'success' => true,
    'message' => $affected > 0 ? 'Data profil berhasil diperbarui.' : 'Tidak ada perubahan data profil.',
    'data' => [
        'customer' => twk_customer_payload($updated),
        'updated_at' => date('Y-m-d H:i:s')
    ]
]);
