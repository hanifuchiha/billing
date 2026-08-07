<?php
// API Cek Sesi
header('Content-Type: application/json');
session_start();
$response = ['ok' => false, 'message' => '', 'data' => null];

if (!isset($_SESSION['status']) || $_SESSION['status'] != 'login') {
    $response['message'] = 'Belum login';
    echo json_encode($response); exit;
}

$response['ok'] = true;
$response['message'] = 'Sesi aktif';
$response['data'] = [
    'id' => $_SESSION['id'] ?? '',
    'username' => $_SESSION['USERNAME'] ?? '',
    'pemilik' => $_SESSION['PEMILIK'] ?? '',
    'nowa' => $_SESSION['NOWA'] ?? '',
    'server' => $_SESSION['server'] ?? '',
    'status' => $_SESSION['status'] ?? ''
];
echo json_encode($response);