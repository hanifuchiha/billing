<?php
require '../cek-sesi.php';

header('Content-Type: application/json; charset=utf-8');

function json_out($ok, $message)
{
    echo json_encode([
        'success' => (bool)$ok,
        'message' => (string)$message,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(false, 'Metode request tidak valid.');
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    json_out(false, 'ID biaya tidak valid.');
}

$ownedPemilik = [];
$queryServerId = mysqli_query($conn, "SELECT PEMILIK FROM server WHERE user_id = " . (int)$current_user_id);
while ($row = mysqli_fetch_assoc($queryServerId)) {
    $ownedPemilik[] = (string)$row['PEMILIK'];
}
if (!in_array($ceknama, $ownedPemilik, true)) {
    $ownedPemilik[] = (string)$ceknama;
}
$ownedPemilik = array_values(array_unique(array_filter($ownedPemilik, static function ($value) {
    return $value !== '';
})));

if (count($ownedPemilik) === 0) {
    json_out(false, 'Akses server tidak ditemukan.');
}

$ownedPemilikEscaped = [];
foreach ($ownedPemilik as $pemilikItem) {
    $ownedPemilikEscaped[] = "'" . mysqli_real_escape_string($conn, $pemilikItem) . "'";
}
$server_list = implode(',', $ownedPemilikEscaped);

$sql = "UPDATE biaya_tambahan_pelanggan SET ACTIVE = 0 WHERE id = ? AND PEMILIK IN ($server_list)";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    json_out(false, 'Gagal menyiapkan query hapus biaya.');
}

$stmt->bind_param('i', $id);
$stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

if ($affected > 0) {
    json_out(true, 'Tambahan biaya berhasil dihapus.');
}

json_out(false, 'Data biaya tidak ditemukan atau tidak punya akses.');
