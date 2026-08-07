

<?php

require '../cek-sesi.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $server = $_POST['mt_server'] ?? '';
    $merchant_id = $_POST['mt_merchant_id'] ?? '';
    $server_key = $_POST['mt_server_key'] ?? '';
    $client_key = $_POST['mt_client_key'] ?? '';
    $pajak = $_POST['mt_pajak'] ?? '0';
    // BHPS USO dihapus dari sistem (2026-08-02) -- selalu '0', tidak lagi
    // dari input admin. Kolom bhps_uso di DB dibiarkan ada (tidak dihapus).
    $bhps_uso = '0';
    $default_auth_mode = $_POST['mt_auth_mode'] ?? 'API MODE';
   
    $pemilik = $_SESSION['USERNAME'] ?? '';

    $domain = $config['domain'];
    $callback = "https://$domain/crm/billing/callbackmidtrans/callback_midtrans_$ceknama.php";
    $return = "https://$domain/crm/billing/broadband/portallogin.php";

    $folder = '../callbackmidtrans/';
    $allowFiles = [
        'callback_midtrans.php'
    ];

    foreach ($allowFiles as $filename) {
        $file = $folder . $filename;

        if (!file_exists($file)) {
            echo "⚠️ File tidak ditemukan: $file<br>";
            continue;
        }

        $nameOnly = pathinfo($filename, PATHINFO_FILENAME);
        $ext = pathinfo($filename, PATHINFO_EXTENSION);

        // Cek apakah nama sudah mengandung _username di akhir
        if (preg_match('/_' . preg_quote($server, '/') . '$/', $nameOnly)) {
            echo "ℹ️ Lewati, sudah ada username di nama file: $filename<br>";
            continue;
        }

        $baru = $folder . $nameOnly . '_' . $pemilik . '.' . $ext;

        if (!file_exists($baru)) {
            if (copy($file, $baru)) {
                chmod($baru, 0777);
                @chown($baru, 'qts');         // Pastikan user qts ada
                @chgrp($baru, 'qts');         // Gunakan grup yang sesuai
                echo "✅ Disalin dan diatur: $baru<br>";

                ///////////////////catatat di log////////////////////

                // Cek apakah sudah pernah dikirim
                $history_file = "../notifbot/data/history-$ceknama.json";
                $history = [];

                if (file_exists($history_file)) {
                    $history = json_decode(file_get_contents($history_file), true);
                }

                // Pastikan format history adalah array
                if (!is_array($history)) {
                    $history = [];
                }

                $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Berhasil mengatur akun payment gateway Midtrans untuk $pemilik";
                // Simpan ke file history
                file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

                ////////////////////////////////////////////////////

                header("Location: ../paymentset.php ");
            } else {
                echo "❌ Gagal menyalin: $file<br>";
                header("Location: ../paymentset.php ");
            }
        } else {
            echo "ℹ️ File baru sudah ada: $baru<br>";
            header("Location: ../paymentset.php ");
        }
    }
    $sql = "INSERT INTO `midtrans`( `pemilik`,`server`, `merchant_id`, `server_key`, `client_key`, `pajak`, `default_auth_mode`, `callback`, `return`, `bhps_uso`) VALUES ('$pemilik','$server', '$merchant_id', '$server_key', '$client_key', '$pajak', '$default_auth_mode', '$callback', '$return', '$bhps_uso')";
    $query = mysqli_query($conn, $sql);
    if (!$query) {
        echo "<b>MySQL Error:</b> " . mysqli_error($conn) . "<br>";
        echo "<b>Query:</b> $sql<br>";
        exit;
    }
} else {
    header("Location: ../paymentset.php ");
}
