<?php

include '../cek-sesi.php'; // Pastikan koneksi ke database sudah benar


// Cek apakah sudah pernah dikirim
$history_file = '../notifbot/data/history-' . $ceknama . '.json';
$history = [];

if (file_exists($history_file)) {
    $history = json_decode(file_get_contents($history_file), true);
}

// Pastikan format history adalah array
if (!is_array($history)) {
    $history = [];
}

header('Content-Type: application/json');

$server_list = array_map('trim', explode(',', $server_list)); // Ubah ke array & hilangkan spasi
$server_list = "" . implode(",", $server_list) . ""; // Tambahkan kutip di setiap nilai

$sql = "SELECT * FROM `botwa` WHERE 1";
$query = mysqli_query($conn, $sql);
while ($data = mysqli_fetch_array($query)) {

    echo $namebot = $data['namebot'];


    if (empty($namebot)) {
        echo json_encode(['status' => 'unknown']);
        exit;
    }

    $cmd_qts_1 = "sudo -u qts tail -n 40 /home/qts/.pm2/logs/index-out.log | grep '\"sessionId\":\"$namebot\"'";
    $cmd_qts_2 = "sudo -u qts tail -n 40 /home/qts/.pm2/logs/index-out.log | grep 'Session connected: $namebot'";
    $cmd_fiberq = "sudo -u qts tail -n 40 /home/qts/.pm2/logs/index-out.log | grep 'Session disconnected: $namebot'";

    $output_qts_1 = shell_exec($cmd_qts_1);
    $output_qts_2 = shell_exec($cmd_qts_2);
    $output_fiberq = shell_exec($cmd_fiberq);

    // Mengecek apakah bot ditemukan dalam log
    $qts_found = !empty(trim($output_qts_1)) || !empty(trim($output_qts_2));
    $fiberq_found = !empty(trim($output_fiberq));

    $status = 'unknown';
    if ($qts_found) {
        $status = 'online';
    } elseif ($fiberq_found) {
        $status = 'offline';
    }

    echo json_encode(['status' => $status]);



    $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Cek status bot $namebot: $status";
    // Simpan ke file history
    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
}
exit;
