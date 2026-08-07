<?php

require '../cek-sesi.php';

function respond_and_exit($message, $redirect = '../paymentset.php') {
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $safeRedirect = htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8');
    echo "<script>alert('$safeMessage'); window.location='$safeRedirect';</script>";
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $api_key = $_POST['api_key'] ?? '';
    $merchant_code = $_POST['merchant_code'] ?? '';
    $private_key = $_POST['private_key'] ?? '';
    $server = $_POST['server'] ?? '';
    $ppn = $_POST['ppn'] ?? '';
    // BHPS USO dihapus dari sistem (2026-08-02) -- selalu '0', tidak lagi
    // dari input admin. Kolom bhps_uso di DB dibiarkan ada (tidak dihapus).
    $bhps_uso = '0';
    $api_hotspot = $_POST['api_hotspot'] ?? '';
    // Pakai $ceknama (owner), bukan $_SESSION['USERNAME'], karena semua query
    // tampilan/list Tripay di paymentset.php memfilter pemilik=$ceknama. Kalau
    // yang mengisi form adalah akun ASSISTANT/sub-user, $_SESSION['USERNAME']
    // beda dari $ceknama sehingga data tersimpan tapi tidak pernah muncul.
    $pemilik = $ceknama ?? '';

    if ($pemilik === '') {
        respond_and_exit('Pemilik tidak diketahui, gagal menyimpan pengaturan Tripay');
    }


    $domain=$config['domain'];
     $callback="https://$domain/crm/billing/callbacktripay/callback_tripay_$ceknama.php";
     $return="https://$domain/crm/billing/broadband/portallogin.php";



    $folder = '../callbacktripay/';
    // Daftar file yang boleh disalin
    $allowFiles = [
        'callback_tripay.php'
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
        if (preg_match('/_' . preg_quote($pemilik, '/') . '$/', $nameOnly)) {
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


                $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Berhasil mengatur akun payment gateway Tripay untuk $pemilik";
                // Simpan ke file history
                file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));



                ////////////////////////////////////////////////////
            } else {
                echo "❌ Gagal menyalin: $file<br>";
            }
        } else {
            echo "ℹ️ File baru sudah ada: $baru<br>";
        }
    }

    // Cek dulu apakah baris untuk pemilik ini sudah ada, supaya submit ulang
    // meng-UPDATE data lama (bukan menambah baris duplikat) tanpa bergantung
    // pada asumsi UNIQUE key di skema tabel `tripay`.
    $checkStmt = $conn->prepare("SELECT id FROM `tripay` WHERE `pemilik` = ? LIMIT 1");
    if (!$checkStmt) {
        respond_and_exit('Gagal menyiapkan query: ' . $conn->error);
    }
    $checkStmt->bind_param('s', $pemilik);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    $existingId = null;
    if ($row = $checkResult->fetch_assoc()) {
        $existingId = $row['id'];
    }
    $checkStmt->close();

    if ($existingId !== null) {
        $stmt = $conn->prepare(
            "UPDATE `tripay` SET `server` = ?, `pajak` = ?, `callback` = ?, `apikey` = ?, `privatekey` = ?, `merchant` = ?, `api_hotspot` = ?, `return` = ?, `bhps_uso` = ? WHERE `id` = ?"
        );
        if (!$stmt) {
            respond_and_exit('Gagal menyiapkan query: ' . $conn->error);
        }
        $stmt->bind_param(
            'sssssssssi',
            $server, $ppn, $callback, $api_key, $private_key, $merchant_code, $api_hotspot, $return, $bhps_uso, $existingId
        );
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO `tripay` (`pemilik`, `server`, `pajak`, `callback`, `apikey`, `privatekey`, `merchant`, `api_hotspot`, `return`, `bhps_uso`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            respond_and_exit('Gagal menyiapkan query: ' . $conn->error);
        }
        $stmt->bind_param(
            'ssssssssss',
            $pemilik, $server, $ppn, $callback, $api_key, $private_key, $merchant_code, $api_hotspot, $return, $bhps_uso
        );
    }

    if ($stmt->execute()) {
        $stmt->close();
        respond_and_exit('Pengaturan Tripay berhasil disimpan');
    } else {
        $error = $stmt->error;
        $stmt->close();
        respond_and_exit('Gagal menyimpan pengaturan Tripay: ' . $error);
    }
} else {
    header("Location: ../paymentset.php");
    exit;
}
