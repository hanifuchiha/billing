<?php
include '../cek-sesi.php'; // Pastikan koneksi ke database sudah benar

header('Content-Type: application/json');

$idpel = $_GET['idpel'] ?? null;

$data = [
    "status" => "Offline",
    "login_via" => "RADIUS",
    "remote" => null
];

if ($idpel) {
    $radwho = shell_exec("radwho | grep -w " . escapeshellarg($idpel));
    if (!empty(trim($radwho))) {
        $data['status'] = "Online";
        $data['remote'] = preg_replace('/\s+/', ' ', trim($radwho));
    }
}

echo json_encode($data);
