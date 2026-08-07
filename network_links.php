<?php
// koneksi antar perangkat (parent-child) untuk network map
// Kolom: id, user_id, child_id, parent_id
require_once '../../cek-sesi.php';
require_once '../../koneksidb.php';
header('Content-Type: application/json');

$uid = (int)$current_user_id;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $child = (int)($_POST['child_id'] ?? 0);
    $parent = (int)($_POST['parent_id'] ?? 0);
    if ($child && $parent && $child != $parent) {
        $cek = mysqli_query($conn, "SELECT * FROM network_links WHERE user_id=$uid AND child_id=$child AND parent_id=$parent");
        if (mysqli_num_rows($cek) == 0) {
            $q = mysqli_query($conn, "INSERT INTO network_links (user_id, child_id, parent_id) VALUES ($uid, $child, $parent)");
            echo json_encode(['status'=>'ok']);
        } else {
            echo json_encode(['status'=>'exists']);
        }
    } else {
        echo json_encode(['status'=>'error','msg'=>'Invalid input']);
    }
    exit;
}

// GET: ambil semua link user
$res = mysqli_query($conn, "SELECT * FROM network_links WHERE user_id=$uid");
$links = [];
while($row = mysqli_fetch_assoc($res)) {
    $links[] = $row;
}
echo json_encode(['status'=>'ok','links'=>$links]);
