<?php
include '../cek-sesi.php'; // Pastikan koneksi ke database sudah benar

if (isset($_GET['id'])) {
    $id = $_GET['id'];

    $query = "SELECT * FROM pelanggan WHERE IDPEL = '$id'";
    $result = mysqli_query($conn, $query);
    $data = mysqli_fetch_assoc($result);

    echo json_encode($data);
}
exit;
