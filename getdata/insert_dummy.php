<?php
include '../cek-sesi.php';

// Insert dummy data untuk semua IP di network_devices milik user
$result = mysqli_query($conn, "SELECT ip_address FROM network_devices WHERE user_id = '$current_user_id'");
while ($row = mysqli_fetch_assoc($result)) {
    $ip = $row['ip_address'];
    for ($i = 0; $i < 5; $i++) {
        $rx = rand(10, 100);
        $tx = rand(10, 100);
        $timestamp = date('Y-m-d H:i:s', strtotime("-{$i} minutes"));
        $query = "INSERT INTO traffic_history (ip_address, rx_mbps, tx_mbps, timestamp) VALUES ('$ip', '$rx', '$tx', '$timestamp')";
        mysqli_query($conn, $query);
    }
}

echo "Dummy data inserted for all devices";
?>