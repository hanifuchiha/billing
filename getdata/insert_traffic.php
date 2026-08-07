<?php
include '../cek-sesi.php';
require('routeros_api.class.php');

// Script untuk insert trafik history ke file txt setiap menit
// Jalankan dengan cron: */1 * * * * php insert_traffic.php

// Ambil semua server milik user
$servers = [];
$sql_servers = mysqli_query($conn, "SELECT * FROM server WHERE user_id = '$current_user_id'");
while ($srv = mysqli_fetch_assoc($sql_servers)) {
    $servers[] = $srv;
}

foreach ($servers as $server) {
    $API = new RouterosAPI();
    $mikrotik_ip = $server['IP'];
    $mikrotik_user = $server['USER'];
    $mikrotik_pass = $server['PASSWORD'];

    if ($API->connect($mikrotik_ip, $mikrotik_user, $mikrotik_pass)) {
        // Ambil trafik dari interface utama
        $interface = 'ether1'; // Sesuaikan

        $API->write('/interface/monitor-traffic', false);
        $API->write('=interface=' . $interface, false);
        $API->write('=once=');
        $traffic = $API->read();

        if (!empty($traffic)) {
            $rx_mbps = round($traffic[0]['rx-bits-per-second'] / 1_000_000, 2);
            $tx_mbps = round($traffic[0]['tx-bits-per-second'] / 1_000_000, 2);

            // Simpan ke file txt
            $dir = 'traffic';
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $filename = $dir . "/traffic_" . str_replace(':', '_', $server['IP']) . ".txt";
            $line = date('Y-m-d H:i:s') . ",{$rx_mbps},{$tx_mbps}\n";
            file_put_contents($filename, $line, FILE_APPEND);

            // Untuk simulasi history, tambahkan beberapa baris dengan data sedikit berbeda
            for ($i = 1; $i <= 4; $i++) {
                $rx_sim = max(0, $rx_mbps + rand(-5, 5));
                $tx_sim = max(0, $tx_mbps + rand(-5, 5));
                $time_sim = date('Y-m-d H:i:s', strtotime("-{$i} minutes"));
                $line_sim = "{$time_sim},{$rx_sim},{$tx_sim}\n";
                file_put_contents($filename, $line_sim, FILE_APPEND);
            }
        }

        $API->disconnect();
    }
}

// Hapus file lama, simpan hanya 1 jam terakhir
foreach ($servers as $server) {
    $dir = 'traffic';
    $filename = $dir . "/traffic_" . str_replace(':', '_', $server['IP']) . ".txt";
    if (file_exists($filename)) {
        $lines = file($filename, FILE_IGNORE_NEW_LINES);
        $new_lines = [];
        foreach ($lines as $line) {
            $parts = explode(',', $line);
            if (count($parts) >= 1 && strtotime($parts[0]) > strtotime('-1 hour')) {
                $new_lines[] = $line;
            }
        }
        file_put_contents($filename, implode("\n", $new_lines) . "\n");
    }
}
?>