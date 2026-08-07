<?php
require '../cek-sesi.php';

header('Content-Type: application/json');

if ($AKSES == 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('NMS', $akses_menu, true)) {
        echo json_encode(['success' => false, 'message' => 'Tidak memiliki akses.']);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method tidak diizinkan.']);
    exit;
}

$id = (int)($_POST['id'] ?? 0);
$pos_dx = isset($_POST['pos_dx']) && is_numeric($_POST['pos_dx']) ? (float)$_POST['pos_dx'] : null;
$pos_dy = isset($_POST['pos_dy']) && is_numeric($_POST['pos_dy']) ? (float)$_POST['pos_dy'] : null;

if ($id <= 0 || $pos_dx === null || $pos_dy === null) {
    echo json_encode(['success' => false, 'message' => 'Parameter tidak valid.']);
    exit;
}

// Clamp supaya tidak ada offset absurd (mis. gara2 delta drag error) yang
// mendorong node keluar sangat jauh dari kanvas.
$pos_dx = max(-5000, min(5000, $pos_dx));
$pos_dy = max(-5000, min(5000, $pos_dy));

// Scoping kepemilikan: device HARUS milik $current_user_id yang sedang login
// (sama seperti proses tambah/hapus device di mynetworkmap.php) supaya
// assistant/owner tidak bisa menggeser posisi device milik akun lain.
$stmt = $conn->prepare("UPDATE network_devices SET pos_dx = ?, pos_dy = ? WHERE id = ? AND user_id = ?");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Gagal menyiapkan query.']);
    exit;
}
$uid = (int)$current_user_id;
$stmt->bind_param('ddii', $pos_dx, $pos_dy, $id, $uid);
$ok = $stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

if (!$ok) {
    echo json_encode(['success' => false, 'message' => 'Gagal menyimpan posisi.']);
    exit;
}

echo json_encode(['success' => true, 'affected' => $affected]);
