
<?php
require '../cek-sesi.php';


if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $merchant_id = trim($_POST['xendit_merchant_id'] ?? '');
    $server_key = trim($_POST['xendit_server_key'] ?? '');
    $client_key = trim($_POST['xendit_client_key'] ?? '');
    $pajak = trim($_POST['xendit_pajak'] ?? '');
    // BHPS USO dihapus dari sistem (2026-08-02) -- selalu '0', tidak lagi
    // dari input admin. Kolom bhps_uso di DB dibiarkan ada (tidak dihapus).
    $bhps_uso = '0';
    $default_auth_mode = trim($_POST['xendit_default_auth_mode'] ?? 'API MODE');
    $server = trim($_POST['xendit_server'] ?? '');

    // Validasi wajib isi
    if ($merchant_id === '' || $server_key === '' || $client_key === '' || $default_auth_mode === '' || $server === '') {
        echo "❌ Semua field wajib diisi!";
        exit;
    }

    // Pakai $ceknama (owner), bukan $_SESSION['USERNAME'], karena semua query
    // tampilan/list Xendit di paymentset.php & lookup runtime di portal_bayar.php
    // memfilter pemilik=$ceknama. Kalau yang mengisi form adalah akun
    // ASSISTANT/sub-user, $_SESSION['USERNAME'] beda dari $ceknama sehingga data
    // tersimpan tapi tidak pernah muncul/dipakai, dan nama file callback yang
    // disalin tidak cocok dengan $callback (yang sudah benar pakai $ceknama).
    $pemilik = $ceknama ?? '';

    $domain = $config['domain'];
    $callback = "https://$domain/crm/billing/callbackxendit/callback_xendit_$ceknama.php";
    $return = "https://$domain/crm/billing/broadband/portallogin.php";

    $folder = '../callbackxendit/';
    $allowFiles = [
        'callback_xendit.php'
    ];

    foreach ($allowFiles as $filename) {
        $file = $folder . $filename;
        if (!file_exists($file)) {
            echo "⚠️ File tidak ditemukan: $file<br>";
            continue;
        }
        $nameOnly = pathinfo($filename, PATHINFO_FILENAME);
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        if (preg_match('/_' . preg_quote($server, '/') . '$/', $nameOnly)) {
            echo "ℹ️ Lewati, sudah ada username di nama file: $filename<br>";
            continue;
        }
        $baru = $folder . $nameOnly . '_' . $pemilik . '.' . $ext;
        if (!file_exists($baru)) {
            if (copy($file, $baru)) {
                chmod($baru, 0777);
                @chown($baru, 'qts');
                @chgrp($baru, 'qts');
                echo "✅ Disalin dan diatur: $baru<br>";
                $history_file = "../notifbot/data/history-$ceknama.json";
                $history = [];
                if (file_exists($history_file)) {
                    $history = json_decode(file_get_contents($history_file), true);
                }
                if (!is_array($history)) {
                    $history = [];
                }
                $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Berhasil mengatur akun payment gateway Xendit untuk $pemilik";
                file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
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
    $sql = "INSERT INTO `xendit` (`pemilik`, `server`, `merchant_id`, `server_key`, `client_key`, `pajak`, `default_auth_mode`, `callback`, `return`, `bhps_uso`) VALUES ('$pemilik', '$server', '$merchant_id', '$server_key', '$client_key', '$pajak', '$default_auth_mode', '$callback', '$return', '$bhps_uso')";
    $query = mysqli_query($conn, $sql);
    if (!$query) {
        echo "❌ Gagal insert ke database: " . mysqli_error($conn);
    }
} else {
    header("Location: ../paymentset.php ");
}
