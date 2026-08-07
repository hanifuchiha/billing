<?php
$koneksi = mysqli_connect("localhost", "qts", "Deltaiman@qts92", "Mybillingq");

$sender = $_POST['sender'] ?? null;
$receiver = $_POST['receiver'] ?? null;

if ($sender && $receiver) {
    mysqli_query($koneksi, "UPDATE messages 
                            SET is_read = 1 
                            WHERE sender_id = '$sender' AND receiver_id = '$receiver'");
}
